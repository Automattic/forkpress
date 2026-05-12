use anyhow::{Context, Result, anyhow, bail};
#[cfg(target_os = "macos")]
use std::ffi::CString;
use std::ffi::{OsStr, OsString};
use std::fs::{self, File, OpenOptions};
#[cfg(target_os = "windows")]
use std::io::{Read, Seek, SeekFrom, Write};
use std::path::{Path, PathBuf};
#[cfg(target_os = "macos")]
use std::process::Command;
use std::time::{SystemTime, UNIX_EPOCH};

#[cfg(target_os = "macos")]
use forkpress_core::absolutize;
use forkpress_core::{
    FileViewStrategy, Layout, SharedPaths, StorageStrategy, path_exists_no_follow,
    read_site_manifest, validate_branch_name, write_site_manifest,
};
use forkpress_runtime::{PortableRuntime, run_php_script};

mod remote;
pub use remote::{
    RemoteBranchOptions, RemoteBranchReport, RemoteCacheStats, RemoteSiteAdd, RemoteSiteManifest,
    RemoteSiteProbe, add_remote_site, branch_remote_site, list_remote_sites, probe_remote_site,
    read_remote_site_manifest, remote_site_cache_stats, sanitize_remote_site_name,
};

pub struct CowSiteInit<'a> {
    pub shared: &'a SharedPaths,
    pub site_title: &'a str,
    pub admin_password: Option<&'a str>,
}

pub fn write_cow_strategy_notes(layout: &Layout) -> Result<()> {
    let notes = "\
# ForkPress materialized COW strategy

This site was initialized with `strategy = \"cow\"`.

This backend uses materialized branch directories beside `.forkpress`. With the
default layout, the main branch is `./main` and a branch named `marketing` is
`./marketing`. Each branch contains an ordinary WordPress tree and its own
ordinary SQLite database file at `wp-content/database/.ht.sqlite`. WordPress
reads and writes those files directly, so this strategy does not use BranchFS
streams, SQL COW views, tombstones, triggers, or per-branch table prefixes.

Branch creation uses the file view recorded in `.forkpress/site.toml`.
ForkPress first tries materialized COW branch directories with host filesystem
clone primitives (Linux `FICLONE`, macOS `clonefile`, Windows ReFS block
clone). On macOS, if the current location cannot clone files, ForkPress creates
a rootless APFS sparsebundle under `.forkpress/macos-cow`, mounts it at
`.forkpress/macos-cow/mount`, and links each public branch directory, such as
`./main`, into that APFS volume. On Windows, put the site on a ReFS Dev Drive
created by `ForkPressSetup.exe`. A regular full copy is only the last-resort
file view on platforms where ForkPress can make that tradeoff explicit.

APFS clone sharing is not visible to tools that add up path sizes. `du`, Finder,
and many disk analyzers can count shared clone extents once for every branch, so
a COW branch can appear to consume another full WordPress tree. To inspect
physical growth on macOS, compare `df -h .forkpress/macos-cow/mount` before and
after branch creation, or inspect the allocated size of
`.forkpress/macos-cow/branches.sparsebundle`.

Manage mount-backed COW storage through ForkPress:

- `forkpress serve`
- `forkpress stop`
- `forkpress storage status --work-dir .forkpress` for diagnostics
- `forkpress storage mount|detach --work-dir .forkpress` for manual cleanup
- `forkpress storage compact --work-dir .forkpress` to detach and compact the
  sparsebundle after deleting branches

Stop asks macOS to detach the sparsebundle after stopping this site's ForkPress
server. Use `--force` only when normal detach reports a busy mount and you have
closed terminals/editors that were using `.forkpress/macos-cow/mount`.

Git smart HTTP is available at `http://wp.localhost:18080/site.git`. The Git
adapter stores protocol objects under `.forkpress/cow/git`, snapshots branch
directories before clone/fetch/push, and applies pushed `wordpress/` file
changes back to the target branch directory. `database.sql` in Git checkouts is
generated from the branch SQLite database and ignored on push.
";
    fs::write(layout.cow_dir.join("README.md"), notes).with_context(|| {
        format!(
            "failed to write {}",
            layout.cow_dir.join("README.md").display()
        )
    })
}

pub fn print_cow_storage_status(
    layout: &Layout,
    file_view: Option<FileViewStrategy>,
) -> Result<()> {
    println!("  project:   {}", layout.project_dir.display());
    let sparsebundle_detached = file_view == Some(FileViewStrategy::MacosApfsSparsebundle)
        && !layout.macos_cow_branches_dir.exists();
    if sparsebundle_detached {
        println!("  branches:  unavailable while sparsebundle is detached");
    } else {
        println!("  branches:  {}", cow_branch_names(layout)?.len());
    }
    println!("  public:    {}", layout.cow_branches_dir.display());
    if file_view == Some(FileViewStrategy::MacosApfsSparsebundle) {
        println!("  storage:   {}", layout.macos_cow_branches_dir.display());
    } else {
        println!("  storage:   {}", layout.cow_branches_dir.display());
    }
    println!(
        "  lock:      {}",
        layout.cow_dir.join("operations.lock").display()
    );
    println!(
        "  lifecycle: {}",
        layout.cow_dir.join("lifecycle.lock").display()
    );

    if sparsebundle_detached {
        println!("  leftovers: unavailable while sparsebundle is detached");
    } else {
        let leftovers = cow_stale_operation_entries(layout)?;
        if leftovers.is_empty() {
            println!("  leftovers: none");
        } else {
            println!("  leftovers: {}", leftovers.len());
            for path in leftovers.iter().take(5) {
                println!("    {}", path.display());
            }
            if leftovers.len() > 5 {
                println!("    ... {} more", leftovers.len() - 5);
            }
        }
    }

    Ok(())
}

#[cfg(target_os = "macos")]
pub fn print_macos_cow_storage_status(layout: &Layout) -> Result<()> {
    println!("  image:     {}", layout.macos_cow_image.display());
    println!("  mount:     {}", layout.macos_cow_mount.display());
    match macos_mount_info(&layout.macos_cow_mount)? {
        Some(info) => {
            println!("  attached:  yes");
            println!("  device:    {}", info.device);
        }
        None => {
            println!("  attached:  no");
            println!(
                "  attach:    forkpress storage mount --work-dir {}",
                shell_quote_path(&layout.work_dir)
            );
        }
    }
    Ok(())
}

#[cfg(not(target_os = "macos"))]
pub fn print_macos_cow_storage_status(layout: &Layout) -> Result<()> {
    println!("  image:     {}", layout.macos_cow_image.display());
    println!("  mount:     {}", layout.macos_cow_mount.display());
    println!("  attached:  not available on this OS");
    Ok(())
}

pub fn prepare_cow_file_view(layout: &Layout) -> Result<FileViewStrategy> {
    fs::create_dir_all(&layout.cow_dir)
        .with_context(|| format!("failed to create {}", layout.cow_dir.display()))?;
    fs::create_dir_all(&layout.cow_branches_dir)
        .with_context(|| format!("failed to create {}", layout.cow_branches_dir.display()))?;

    #[cfg(target_os = "macos")]
    {
        if std::env::var_os("FORKPRESS_FORCE_MACOS_APFS_SPARSEBUNDLE").is_some() {
            prepare_macos_apfs_sparsebundle_file_view(layout)?;
            return Ok(FileViewStrategy::MacosApfsSparsebundle);
        }
    }

    if probe_reflink_dir(&layout.cow_branches_dir)? {
        return Ok(FileViewStrategy::Reflink);
    }

    #[cfg(target_os = "windows")]
    {
        bail!(
            "Windows COW storage requires ReFS block cloning. Run ForkPress Setup to create a Dev Drive, then create the site under %USERPROFILE%\\ForkPressDevDrive."
        );
    }

    #[cfg(target_os = "macos")]
    {
        match prepare_macos_apfs_sparsebundle_file_view(layout) {
            Ok(()) => return Ok(FileViewStrategy::MacosApfsSparsebundle),
            Err(err) => {
                eprintln!("forkpress: macOS APFS sparsebundle COW setup failed: {err:#}");
                eprintln!("forkpress: falling back to full file-copy materialization");
            }
        }
    }

    #[cfg(not(target_os = "windows"))]
    {
        Ok(FileViewStrategy::Copy)
    }
}

pub fn ensure_cow_file_view_ready(layout: &Layout) -> Result<FileViewStrategy> {
    let mut manifest = read_site_manifest(layout)?;
    if let Some(file_view) = manifest.as_ref().and_then(|manifest| manifest.file_view) {
        ensure_cow_file_view_available(layout, file_view)?;
        return Ok(file_view);
    }

    let file_view = prepare_cow_file_view(layout)?;
    if let Some(existing) = manifest.as_mut()
        && existing.strategy == StorageStrategy::Cow
    {
        existing.file_view = Some(file_view);
        write_site_manifest(layout, existing.clone())?;
    }
    Ok(file_view)
}

pub fn ensure_cow_file_view_available(layout: &Layout, file_view: FileViewStrategy) -> Result<()> {
    match file_view {
        FileViewStrategy::Reflink | FileViewStrategy::Copy => {
            fs::create_dir_all(&layout.cow_branches_dir).with_context(|| {
                format!("failed to create {}", layout.cow_branches_dir.display())
            })?;
        }
        FileViewStrategy::MacosApfsSparsebundle => {
            ensure_macos_apfs_sparsebundle_file_view(layout)?;
        }
    }
    Ok(())
}

pub fn ensure_cow_main_branch(
    layout: &Layout,
    runtime: &PortableRuntime,
    init: CowSiteInit<'_>,
    file_view: FileViewStrategy,
) -> Result<()> {
    let storage_root = cow_branch_storage_root(layout, "main", file_view);
    if !storage_root.join("wp-load.php").is_file() {
        if let Some(parent) = storage_root.parent() {
            fs::create_dir_all(parent)
                .with_context(|| format!("failed to create {}", parent.display()))?;
        }
        ensure_empty_or_absent_dir(&storage_root)?;
        copy_tree_cow(&layout.runtime_dir.join("runtime/wp-src"), &storage_root)?;
    }
    let main_root = ensure_cow_public_branch_root(layout, "main", &storage_root, file_view)?;

    run_cow_bootstrap_script(
        layout,
        runtime,
        init.shared,
        &main_root,
        init.site_title,
        init.admin_password.unwrap_or("admin"),
    )?;
    Ok(())
}

pub fn cow_branch_root(layout: &Layout, branch: &str) -> PathBuf {
    layout.cow_branches_dir.join(branch)
}

pub fn cow_branch_names(layout: &Layout) -> Result<Vec<String>> {
    let mut names = Vec::new();
    let Ok(entries) = fs::read_dir(&layout.cow_branches_dir) else {
        return Ok(names);
    };
    for entry in entries {
        let entry = entry?;
        let name = entry.file_name().to_string_lossy().into_owned();
        if validate_branch_name(&name).is_err() {
            continue;
        }
        let path = entry.path();
        if path.is_dir() && path.join("wp-load.php").is_file() {
            names.push(name);
        }
    }
    sort_branch_names(&mut names);
    Ok(names)
}

pub fn plain_branch_names(branches_dir: &Path) -> Result<Vec<String>> {
    let mut names = Vec::new();
    let Ok(entries) = fs::read_dir(branches_dir) else {
        return Ok(names);
    };
    for entry in entries {
        let entry = entry?;
        if !entry.file_type()?.is_dir() {
            continue;
        }
        let name = entry.file_name().to_string_lossy().into_owned();
        if validate_branch_name(&name).is_ok() {
            names.push(name);
        }
    }
    sort_branch_names(&mut names);
    Ok(names)
}

fn sort_branch_names(names: &mut [String]) {
    names.sort_by(|a, b| {
        if a == "main" {
            std::cmp::Ordering::Less
        } else if b == "main" {
            std::cmp::Ordering::Greater
        } else {
            a.cmp(b)
        }
    });
}

pub fn write_cow_branch_list(layout: &Layout) -> Result<()> {
    fs::create_dir_all(&layout.cow_dir)
        .with_context(|| format!("failed to create {}", layout.cow_dir.display()))?;
    let mut out = String::new();
    for name in cow_branch_names(layout)? {
        out.push_str(&name);
        out.push('\n');
    }
    fs::write(&layout.cow_branch_list, out)
        .with_context(|| format!("failed to write {}", layout.cow_branch_list.display()))
}

pub fn create_cow_branch(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    branch: &str,
    from: &str,
    url_hint: Option<(String, String)>,
) -> Result<()> {
    validate_branch_name(from)?;
    let file_view = read_site_manifest(layout)?
        .and_then(|manifest| manifest.file_view)
        .unwrap_or(FileViewStrategy::Copy);
    let source = cow_branch_storage_root(layout, from, file_view);
    if !source.is_dir() {
        bail!("source branch does not exist: {from}");
    }
    create_cow_branch_from_tree(
        layout,
        runtime,
        shared,
        branch,
        &source,
        &format!("COW branch '{from}'"),
        Some(from),
        url_hint,
    )
}

pub fn create_cow_branch_from_tree(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    branch: &str,
    source: &Path,
    source_label: &str,
    seed_branch: Option<&str>,
    url_hint: Option<(String, String)>,
) -> Result<()> {
    validate_branch_name(branch)?;
    let file_view = read_site_manifest(layout)?
        .and_then(|manifest| manifest.file_view)
        .unwrap_or(FileViewStrategy::Copy);
    if !source.join("wp-load.php").is_file() {
        bail!(
            "source tree is not a materialized WordPress root: {}",
            source.display()
        );
    }
    let public_dest = cow_branch_root(layout, branch);
    if path_exists_no_follow(&public_dest) {
        bail!("branch already exists: {branch}");
    }
    let dest = cow_branch_storage_root(layout, branch, file_view);
    if dest.exists() {
        bail!("branch already exists: {branch}");
    }
    if cow_branch_copies_require_cow(layout)? {
        copy_tree_cow_required(source, &dest)?;
    } else {
        copy_tree_cow(source, &dest)?;
    }
    let branch_root = ensure_cow_public_branch_root(layout, branch, &dest, file_view)?;
    run_cow_bootstrap_script(layout, runtime, shared, &branch_root, "ForkPress", "admin")?;
    let source_db = cow_sqlite_db_path(source);
    if source_db.is_file() {
        record_cow_merge_base_snapshot(layout, runtime, shared, branch, &source_db)?;
    }
    let branch_db = cow_sqlite_db_path(&branch_root);
    if branch_db.is_file() {
        allocate_cow_autoincrement_bands(layout, runtime, shared, branch, &branch_db)?;
        capture_cow_row_identities(layout, runtime, shared, branch, &branch_db, seed_branch)?;
    }
    record_cow_file_merge_base_snapshot(layout, runtime, shared, branch, &branch_root)?;
    write_cow_branch_list(layout)?;
    println!("forkpress: COW cloned {source_label} -> '{branch}'");
    if let Some((root_host, port)) = url_hint {
        println!(
            "Visit http://{}.{root_host}:{port}/ to see this branch.",
            branch
        );
    }
    Ok(())
}

pub fn ensure_cow_branch_exists(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    branch: &str,
    from: &str,
    url_hint: Option<(String, String)>,
) -> Result<()> {
    let file_view = read_site_manifest(layout)?
        .and_then(|manifest| manifest.file_view)
        .unwrap_or(FileViewStrategy::Copy);
    let public_root = cow_branch_root(layout, branch);
    let storage_root = cow_branch_storage_root(layout, branch, file_view);
    if public_root.join("wp-load.php").is_file() || storage_root.join("wp-load.php").is_file() {
        println!("forkpress: reusing existing branch {branch}");
        return Ok(());
    }
    create_cow_branch(layout, runtime, shared, branch, from, url_hint)
}

pub fn show_cow_branch(layout: &Layout, branch: &str) -> Result<()> {
    validate_branch_name(branch)?;
    let file_view = read_site_manifest(layout)?
        .and_then(|manifest| manifest.file_view)
        .unwrap_or(FileViewStrategy::Copy);
    let public_root = cow_branch_root(layout, branch);
    let storage_root = cow_branch_storage_root(layout, branch, file_view);
    let root = if public_root.join("wp-load.php").is_file() {
        public_root
    } else {
        storage_root
    };
    if !root.join("wp-load.php").is_file() {
        bail!("branch does not exist: {branch}");
    }
    let db = root.join("wp-content/database/.ht.sqlite");
    println!("forkpress cow branch {branch}");
    println!("  root:      {}", root.display());
    println!("  database:  {}", db.display());
    println!("  files:     {}", count_regular_files(&root)?);
    println!("  file view: {}", file_view.as_str());
    println!(
        "  git ref:   {}",
        layout.cow_git_dir.join("refs/heads").join(branch).display()
    );
    Ok(())
}

pub fn reset_cow_branch(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    branch: &str,
    from: &str,
    force: bool,
) -> Result<()> {
    validate_branch_name(branch)?;
    validate_branch_name(from)?;
    if branch == from {
        bail!("cannot reset a branch from itself");
    }
    if branch == "main" && !force {
        bail!("refusing to reset main without --force");
    }

    let file_view = read_site_manifest(layout)?
        .and_then(|manifest| manifest.file_view)
        .unwrap_or(FileViewStrategy::Copy);
    let source = cow_branch_storage_root(layout, from, file_view);
    let target = cow_branch_storage_root(layout, branch, file_view);
    if !source.join("wp-load.php").is_file() {
        bail!("source branch does not exist: {from}");
    }
    if !target.join("wp-load.php").is_file() {
        bail!("target branch does not exist: {branch}");
    }

    let source_db = cow_sqlite_db_path(&source);
    if !source_db.is_file() {
        bail!(
            "source branch database does not exist: {}",
            source_db.display()
        );
    }

    let parent = target.parent().ok_or_else(|| {
        anyhow!(
            "target branch has no parent directory: {}",
            target.display()
        )
    })?;
    fs::create_dir_all(parent).with_context(|| format!("failed to create {}", parent.display()))?;
    let staging = unique_cow_operation_dir(parent, "reset-stage", branch);
    let backup = unique_cow_operation_dir(parent, "reset-backup", branch);
    if path_exists_no_follow(&staging) || path_exists_no_follow(&backup) {
        bail!("temporary reset path already exists");
    }

    let stage_result = (|| -> Result<()> {
        if cow_branch_copies_require_cow(layout)? {
            copy_tree_cow_required(&source, &staging)?;
        } else {
            copy_tree_cow(&source, &staging)?;
        }

        let staged_db = cow_sqlite_db_path(&staging);
        remove_sqlite_file_and_sidecars(&staged_db)?;
        hot_copy_sqlite_database(layout, runtime, shared, &source_db, &staged_db)?;
        Ok(())
    })();

    if let Err(err) = stage_result {
        let _ = fs::remove_dir_all(&staging);
        return Err(err).context("failed to stage COW branch reset");
    }

    let mut target_moved_to_backup = false;
    let mut staging_published = false;
    let publish = (|| -> Result<()> {
        fs::rename(&target, &backup).with_context(|| {
            format!(
                "failed to move current branch {} to {}",
                target.display(),
                backup.display()
            )
        })?;
        target_moved_to_backup = true;
        fs::rename(&staging, &target).with_context(|| {
            format!(
                "failed to publish reset branch {} to {}",
                staging.display(),
                target.display()
            )
        })?;
        staging_published = true;

        let public_root = ensure_cow_public_branch_root(layout, branch, &target, file_view)?;
        run_cow_bootstrap_script(layout, runtime, shared, &public_root, "ForkPress", "admin")?;
        write_cow_branch_list(layout)?;
        Ok(())
    })();

    if let Err(err) = publish {
        let failed = unique_cow_operation_dir(parent, "reset-failed", branch);
        let rollback = rollback_failed_reset_publish(
            branch,
            &target,
            &backup,
            &staging,
            &failed,
            target_moved_to_backup,
            staging_published,
        );
        return Err(err).context(format!("failed to reset COW branch; {rollback}"));
    }

    if let Err(err) = fs::remove_dir_all(&backup) {
        eprintln!(
            "forkpress: warning: reset succeeded but failed to remove old branch backup {}: {err}",
            backup.display()
        );
    }
    record_cow_merge_base_snapshot(layout, runtime, shared, branch, &source_db)?;
    let target_db = cow_sqlite_db_path(&target);
    if target_db.is_file() {
        allocate_cow_autoincrement_bands(layout, runtime, shared, branch, &target_db)?;
        capture_cow_row_identities(layout, runtime, shared, branch, &target_db, Some(from))?;
    }
    record_cow_file_merge_base_snapshot(layout, runtime, shared, branch, &target)?;
    invalidate_cow_git_ref(layout, branch)?;

    println!("forkpress: reset COW branch '{branch}' from '{from}'");
    Ok(())
}

pub fn cow_merge_metadata_db_path(layout: &Layout) -> PathBuf {
    layout.cow_dir.join("merge/metadata.sqlite")
}

pub fn cow_merge_base_db_path(layout: &Layout, branch: &str) -> Result<PathBuf> {
    validate_branch_name(branch)?;
    Ok(layout
        .cow_dir
        .join("merge/bases")
        .join(format!("{branch}.sqlite")))
}

pub fn cow_merge_file_base_path(layout: &Layout, branch: &str) -> Result<PathBuf> {
    validate_branch_name(branch)?;
    Ok(layout
        .cow_dir
        .join("merge/file-bases")
        .join(format!("{branch}.json")))
}

fn capture_cow_row_identities(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    branch: &str,
    db: &Path,
    seed_branch: Option<&str>,
) -> Result<()> {
    let metadata_db = cow_merge_metadata_db_path(layout);
    let mut args: Vec<OsString> = vec![
        "capture-identities".into(),
        "--db".into(),
        db.as_os_str().to_os_string(),
        "--metadata-db".into(),
        metadata_db.as_os_str().to_os_string(),
        "--branch".into(),
        branch.into(),
        "--quiet".into(),
        "1".into(),
    ];
    if let Some(seed_branch) = seed_branch {
        args.push("--seed-branch".into());
        args.push(seed_branch.into());
    }
    run_php_script(
        layout,
        runtime,
        shared,
        "scripts/cow/merge.php",
        args.iter().map(|arg| arg.as_os_str()),
    )
}

fn allocate_cow_autoincrement_bands(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    branch: &str,
    db: &Path,
) -> Result<()> {
    let metadata_db = cow_merge_metadata_db_path(layout);
    let args: Vec<OsString> = vec![
        "allocate-id-bands".into(),
        "--db".into(),
        db.as_os_str().to_os_string(),
        "--metadata-db".into(),
        metadata_db.as_os_str().to_os_string(),
        "--branch".into(),
        branch.into(),
        "--quiet".into(),
        "1".into(),
    ];
    run_php_script(
        layout,
        runtime,
        shared,
        "scripts/cow/merge.php",
        args.iter().map(|arg| arg.as_os_str()),
    )
}

fn record_cow_merge_base_snapshot(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    branch: &str,
    source_db: &Path,
) -> Result<()> {
    let dest = cow_merge_base_db_path(layout, branch)?;
    let parent = dest.parent().ok_or_else(|| {
        anyhow!(
            "merge base path has no parent directory: {}",
            dest.display()
        )
    })?;
    fs::create_dir_all(parent).with_context(|| format!("failed to create {}", parent.display()))?;

    let nanos = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_default()
        .as_nanos();
    let tmp_db = parent.join(format!(
        ".{branch}.merge-base-{}-{nanos}.sqlite",
        std::process::id()
    ));
    remove_sqlite_file_and_sidecars(&tmp_db)?;
    hot_copy_sqlite_database(layout, runtime, shared, source_db, &tmp_db)
        .with_context(|| format!("failed to capture merge base for branch '{branch}'"))?;
    remove_sqlite_file_and_sidecars(&dest)?;
    fs::rename(&tmp_db, &dest).with_context(|| {
        format!(
            "failed to publish merge base snapshot {} to {}",
            tmp_db.display(),
            dest.display()
        )
    })?;
    Ok(())
}

fn record_cow_file_merge_base_snapshot(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    branch: &str,
    branch_root: &Path,
) -> Result<()> {
    let dest = cow_merge_file_base_path(layout, branch)?;
    let parent = dest.parent().ok_or_else(|| {
        anyhow!(
            "filesystem merge base path has no parent directory: {}",
            dest.display()
        )
    })?;
    fs::create_dir_all(parent).with_context(|| format!("failed to create {}", parent.display()))?;

    let nanos = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_default()
        .as_nanos();
    let tmp = parent.join(format!(
        ".{branch}.file-merge-base-{}-{nanos}.json",
        std::process::id()
    ));
    let args: Vec<OsString> = vec![
        "capture-files".into(),
        "--root".into(),
        branch_root.as_os_str().to_os_string(),
        "--file-base".into(),
        tmp.as_os_str().to_os_string(),
        "--quiet".into(),
        "1".into(),
    ];
    run_php_script(
        layout,
        runtime,
        shared,
        "scripts/cow/merge.php",
        args.iter().map(|arg| arg.as_os_str()),
    )
    .with_context(|| format!("failed to capture filesystem merge base for branch '{branch}'"))?;
    if dest.exists() {
        fs::remove_file(&dest).with_context(|| format!("failed to replace {}", dest.display()))?;
    }
    fs::rename(&tmp, &dest).with_context(|| {
        format!(
            "failed to publish filesystem merge base {} to {}",
            tmp.display(),
            dest.display()
        )
    })?;
    Ok(())
}

pub fn merge_cow_branch(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    source: &str,
    target: &str,
) -> Result<()> {
    validate_branch_name(source)?;
    validate_branch_name(target)?;
    if source == target {
        bail!("cannot merge a branch into itself");
    }

    let file_view = read_site_manifest(layout)?
        .and_then(|manifest| manifest.file_view)
        .unwrap_or(FileViewStrategy::Copy);
    let source_root = cow_branch_storage_root(layout, source, file_view);
    let target_root = cow_branch_storage_root(layout, target, file_view);
    if !source_root.join("wp-load.php").is_file() {
        bail!("source branch does not exist: {source}");
    }
    if !target_root.join("wp-load.php").is_file() {
        bail!("target branch does not exist: {target}");
    }

    let source_db = cow_sqlite_db_path(&source_root);
    let target_db = cow_sqlite_db_path(&target_root);
    let base_db = cow_merge_base_db_path(layout, source)?;
    let base_files = cow_merge_file_base_path(layout, source)?;
    if !source_db.is_file() {
        bail!(
            "source branch database does not exist: {}",
            source_db.display()
        );
    }
    if !target_db.is_file() {
        bail!(
            "target branch database does not exist: {}",
            target_db.display()
        );
    }
    if !base_db.is_file() {
        bail!(
            "no merge base snapshot found for branch '{source}' at {}. Recreate or reset the branch before merging.",
            base_db.display()
        );
    }
    if !base_files.is_file() {
        bail!(
            "no filesystem merge base found for branch '{source}' at {}. Recreate or reset the branch before merging.",
            base_files.display()
        );
    }

    let metadata_db = cow_merge_metadata_db_path(layout);
    let args: Vec<OsString> = vec![
        "--base-db".into(),
        base_db.as_os_str().to_os_string(),
        "--source-db".into(),
        source_db.as_os_str().to_os_string(),
        "--target-db".into(),
        target_db.as_os_str().to_os_string(),
        "--metadata-db".into(),
        metadata_db.as_os_str().to_os_string(),
        "--source".into(),
        source.into(),
        "--target".into(),
        target.into(),
        "--base-files".into(),
        base_files.as_os_str().to_os_string(),
        "--source-root".into(),
        source_root.as_os_str().to_os_string(),
        "--target-root".into(),
        target_root.as_os_str().to_os_string(),
    ];
    run_php_script(
        layout,
        runtime,
        shared,
        "scripts/cow/merge.php",
        args.iter().map(|arg| arg.as_os_str()),
    )?;
    record_cow_merge_base_snapshot(layout, runtime, shared, source, &source_db)?;
    record_cow_file_merge_base_snapshot(layout, runtime, shared, source, &source_root)?;
    invalidate_cow_git_ref(layout, target)?;
    Ok(())
}

pub fn inspect_cow_merge_audit(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    format: &str,
    limit: &str,
    run_id: Option<&str>,
    scope: &str,
    records: &str,
    conflict_type: Option<&str>,
    decision: Option<&str>,
    path: Option<&str>,
    path_prefix: Option<&str>,
    id_band_skips: bool,
    review: bool,
) -> Result<()> {
    let metadata_db = cow_merge_metadata_db_path(layout);
    let mut args: Vec<OsString> = vec![
        "audit".into(),
        "--metadata-db".into(),
        metadata_db.as_os_str().to_os_string(),
        "--format".into(),
        format.into(),
        "--limit".into(),
        limit.into(),
        "--scope".into(),
        scope.into(),
        "--records".into(),
        records.into(),
    ];
    if let Some(run_id) = run_id {
        args.push("--run".into());
        args.push(run_id.into());
    }
    if let Some(conflict_type) = conflict_type {
        args.push("--conflict-type".into());
        args.push(conflict_type.into());
    }
    if let Some(decision) = decision {
        args.push("--decision".into());
        args.push(decision.into());
    }
    if let Some(path) = path {
        args.push("--path".into());
        args.push(path.into());
    }
    if let Some(path_prefix) = path_prefix {
        args.push("--path-prefix".into());
        args.push(path_prefix.into());
    }
    if id_band_skips {
        args.push("--id-band-skips".into());
    }
    if review {
        args.push("--review".into());
    }
    run_php_script(
        layout,
        runtime,
        shared,
        "scripts/cow/merge.php",
        args.iter().map(|arg| arg.as_os_str()),
    )
}

pub fn review_cow_merge_audit_record(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    record_type: &str,
    record_id: &str,
    status: &str,
    note: &str,
    reviewer: Option<&str>,
) -> Result<()> {
    let metadata_db = cow_merge_metadata_db_path(layout);
    let mut args: Vec<OsString> = vec![
        "review-record".into(),
        "--metadata-db".into(),
        metadata_db.as_os_str().to_os_string(),
        "--record".into(),
        record_type.into(),
        "--id".into(),
        record_id.into(),
        "--status".into(),
        status.into(),
        "--note".into(),
        note.into(),
    ];
    if let Some(reviewer) = reviewer {
        args.push("--reviewer".into());
        args.push(reviewer.into());
    }
    run_php_script(
        layout,
        runtime,
        shared,
        "scripts/cow/merge.php",
        args.iter().map(|arg| arg.as_os_str()),
    )
}

pub fn rollback_failed_reset_publish(
    branch: &str,
    target: &Path,
    backup: &Path,
    staging: &Path,
    failed: &Path,
    target_moved_to_backup: bool,
    staging_published: bool,
) -> String {
    let mut notes = Vec::new();
    let mut errors = Vec::new();

    if staging_published && path_exists_no_follow(target) {
        match fs::rename(target, failed) {
            Ok(()) => notes.push(format!("moved failed published tree to {}", failed.display())),
            Err(rename_err) => match fs::remove_dir_all(target) {
                Ok(()) => notes.push(format!(
                    "removed failed published tree from {} after rename failed",
                    target.display()
                )),
                Err(remove_err) => errors.push(format!(
                    "failed to move published tree {} to {} ({rename_err}); also failed to remove it ({remove_err})",
                    target.display(),
                    failed.display()
                )),
            },
        }
    }

    if target_moved_to_backup && path_exists_no_follow(backup) {
        if path_exists_no_follow(target) {
            errors.push(format!(
                "previous branch backup remains at {} because target still exists at {}",
                backup.display(),
                target.display()
            ));
        } else {
            match fs::rename(backup, target) {
                Ok(()) => notes.push(format!("restored previous branch contents for '{branch}'")),
                Err(err) => errors.push(format!(
                    "failed to restore previous branch backup {} to {} ({err})",
                    backup.display(),
                    target.display()
                )),
            }
        }
    } else if target_moved_to_backup {
        errors.push(format!(
            "previous branch backup is missing from {}",
            backup.display()
        ));
    }

    for path in [failed, staging] {
        if path_exists_no_follow(path)
            && let Err(err) = fs::remove_dir_all(path)
        {
            errors.push(format!(
                "failed to remove temporary path {} ({err})",
                path.display()
            ));
        }
    }

    if errors.is_empty() {
        if notes.is_empty() {
            "no published reset changes needed rollback".to_string()
        } else {
            notes.join("; ")
        }
    } else {
        format!(
            "rollback incomplete: {}; {}",
            errors.join("; "),
            notes.join("; ")
        )
    }
}

pub fn invalidate_cow_git_ref(layout: &Layout, branch: &str) -> Result<()> {
    let ref_path = layout.cow_git_dir.join("refs/heads").join(branch);
    if let Some(parent) = ref_path.parent() {
        fs::create_dir_all(parent)
            .with_context(|| format!("failed to create {}", parent.display()))?;
    }
    fs::write(&ref_path, "0000000000000000000000000000000000000000\n")
        .with_context(|| format!("failed to mark COW Git ref stale at {}", ref_path.display()))
}

pub fn delete_cow_branch(layout: &Layout, branch: &str) -> Result<()> {
    validate_branch_name(branch)?;
    if branch == "main" {
        bail!("cannot delete the main branch");
    }
    let file_view = read_site_manifest(layout)?
        .and_then(|manifest| manifest.file_view)
        .unwrap_or(FileViewStrategy::Copy);
    let public_root = cow_branch_root(layout, branch);
    let storage_root = cow_branch_storage_root(layout, branch, file_view);

    let mut removed = false;
    match fs::symlink_metadata(&public_root) {
        Ok(meta) if meta.file_type().is_symlink() => {
            fs::remove_file(&public_root)
                .with_context(|| format!("failed to remove {}", public_root.display()))?;
            removed = true;
        }
        Ok(meta) if meta.is_dir() => {
            fs::remove_dir_all(&public_root)
                .with_context(|| format!("failed to remove {}", public_root.display()))?;
            removed = true;
        }
        Ok(_) => bail!("{} is not a branch directory", public_root.display()),
        Err(err) if err.kind() == std::io::ErrorKind::NotFound => {}
        Err(err) => {
            return Err(err)
                .with_context(|| format!("failed to inspect {}", public_root.display()));
        }
    }

    if storage_root != public_root && storage_root.exists() {
        fs::remove_dir_all(&storage_root)
            .with_context(|| format!("failed to remove {}", storage_root.display()))?;
        removed = true;
    }

    let git_ref = layout.cow_git_dir.join("refs/heads").join(branch);
    if git_ref.exists() {
        fs::remove_file(&git_ref)
            .with_context(|| format!("failed to remove {}", git_ref.display()))?;
    }

    write_cow_branch_list(layout)?;
    if removed {
        println!("forkpress: deleted COW branch '{branch}'");
    } else {
        println!("forkpress: no COW branch named '{branch}'");
    }
    Ok(())
}

pub fn cow_stale_operation_entries(layout: &Layout) -> Result<Vec<PathBuf>> {
    let mut roots = vec![
        layout.project_dir.clone(),
        layout.cow_dir.clone(),
        layout.cow_branches_dir.clone(),
    ];
    if layout.macos_cow_branches_dir.exists() {
        roots.push(layout.macos_cow_branches_dir.clone());
    }

    let mut entries = Vec::new();
    for root in roots {
        let Ok(read_dir) = fs::read_dir(&root) else {
            continue;
        };
        for entry in read_dir {
            let entry = entry?;
            let name = entry.file_name().to_string_lossy().into_owned();
            if name.starts_with(".forkpress-reset-")
                || name.starts_with(".forkpress-delete-")
                || name.starts_with(".forkpress-new-")
                || name.starts_with(".forkpress-update-")
            {
                entries.push(entry.path());
            }
        }
    }
    entries.sort();
    entries.dedup();
    Ok(entries)
}

pub fn copy_tree_cow(source: &Path, dest: &Path) -> Result<()> {
    copy_tree_cow_mode(source, dest, TreeCloneMode::AllowCopyFallback)
}

pub fn copy_tree_cow_required(source: &Path, dest: &Path) -> Result<()> {
    copy_tree_cow_mode(source, dest, TreeCloneMode::RequireCow)
}

pub fn probe_reflink_dir(dir: &Path) -> Result<bool> {
    fs::create_dir_all(dir).with_context(|| format!("failed to create {}", dir.display()))?;
    let nanos = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_default()
        .as_nanos();
    let probe_dir = dir.join(format!(
        ".forkpress-cow-probe-{}-{nanos}",
        std::process::id()
    ));
    fs::create_dir_all(&probe_dir)
        .with_context(|| format!("failed to create {}", probe_dir.display()))?;

    let result = (|| -> Result<bool> {
        let source = probe_dir.join("source.txt");
        let dest = probe_dir.join("dest.txt");
        let source_bytes = reflink_probe_bytes();
        fs::write(&source, &source_bytes)
            .with_context(|| format!("failed to write {}", source.display()))?;

        if try_clone_file(&source, &dest).is_err() {
            return Ok(false);
        }

        fs::write(&dest, b"branch")
            .with_context(|| format!("failed to write {}", dest.display()))?;
        let canonical =
            fs::read(&source).with_context(|| format!("failed to read {}", source.display()))?;
        Ok(canonical == source_bytes)
    })();

    let _ = fs::remove_dir_all(&probe_dir);
    result
}

fn reflink_probe_bytes() -> Vec<u8> {
    #[cfg(target_os = "windows")]
    {
        let mut bytes = vec![0; (WINDOWS_REFS_CLONE_ALIGNMENT * 2) as usize];
        for (index, byte) in bytes.iter_mut().enumerate() {
            *byte = (index % 251) as u8;
        }
        bytes
    }

    #[cfg(not(target_os = "windows"))]
    {
        b"canonical".to_vec()
    }
}

#[cfg(unix)]
pub struct CowOperationLock {
    file: File,
}

#[cfg(unix)]
impl Drop for CowOperationLock {
    fn drop(&mut self) {
        use std::os::fd::AsRawFd;
        unsafe {
            libc::flock(self.file.as_raw_fd(), libc::LOCK_UN);
        }
    }
}

#[cfg(not(unix))]
pub struct CowOperationLock;

#[cfg(unix)]
pub fn lock_cow_operations(layout: &Layout) -> Result<CowOperationLock> {
    use std::os::fd::AsRawFd;

    fs::create_dir_all(&layout.cow_dir)
        .with_context(|| format!("failed to create {}", layout.cow_dir.display()))?;
    let path = layout.cow_dir.join("operations.lock");
    let file = OpenOptions::new()
        .create(true)
        .read(true)
        .write(true)
        .truncate(false)
        .open(&path)
        .with_context(|| format!("failed to open {}", path.display()))?;
    let status = unsafe { libc::flock(file.as_raw_fd(), libc::LOCK_EX) };
    if status != 0 {
        bail!("failed to lock {}", path.display());
    }
    Ok(CowOperationLock { file })
}

#[cfg(not(unix))]
pub fn lock_cow_operations(_layout: &Layout) -> Result<CowOperationLock> {
    Ok(CowOperationLock)
}

#[cfg(unix)]
pub struct CowLifecycleLock {
    file: File,
}

#[cfg(unix)]
impl Drop for CowLifecycleLock {
    fn drop(&mut self) {
        use std::os::fd::AsRawFd;
        unsafe {
            libc::flock(self.file.as_raw_fd(), libc::LOCK_UN);
        }
    }
}

#[cfg(not(unix))]
pub struct CowLifecycleLock;

#[cfg(unix)]
pub fn lock_cow_lifecycle(layout: &Layout) -> Result<CowLifecycleLock> {
    use std::os::fd::AsRawFd;

    fs::create_dir_all(&layout.cow_dir)
        .with_context(|| format!("failed to create {}", layout.cow_dir.display()))?;
    let path = layout.cow_dir.join("lifecycle.lock");
    let file = OpenOptions::new()
        .create(true)
        .read(true)
        .write(true)
        .truncate(false)
        .open(&path)
        .with_context(|| format!("failed to open {}", path.display()))?;
    let status = unsafe { libc::flock(file.as_raw_fd(), libc::LOCK_EX) };
    if status != 0 {
        bail!("failed to lock {}", path.display());
    }
    Ok(CowLifecycleLock { file })
}

#[cfg(not(unix))]
pub fn lock_cow_lifecycle(_layout: &Layout) -> Result<CowLifecycleLock> {
    Ok(CowLifecycleLock)
}

pub fn detach_macos_apfs_sparsebundle_file_view(
    layout: &Layout,
    force: bool,
    print_remove_site_hint: bool,
) -> Result<()> {
    detach_macos_apfs_sparsebundle_file_view_impl(layout, force, print_remove_site_hint)
}

pub fn compact_macos_apfs_sparsebundle_file_view(layout: &Layout) -> Result<()> {
    compact_macos_apfs_sparsebundle_file_view_impl(layout)
}

fn cow_branch_copies_require_cow(layout: &Layout) -> Result<bool> {
    Ok(read_site_manifest(layout)?
        .and_then(|manifest| manifest.file_view)
        .map(FileViewStrategy::requires_cow)
        .unwrap_or(false))
}

fn cow_branch_storage_root(layout: &Layout, branch: &str, file_view: FileViewStrategy) -> PathBuf {
    match file_view {
        FileViewStrategy::MacosApfsSparsebundle => layout.macos_cow_branches_dir.join(branch),
        FileViewStrategy::Reflink | FileViewStrategy::Copy => cow_branch_root(layout, branch),
    }
}

fn ensure_empty_or_absent_dir(path: &Path) -> Result<()> {
    if !path.exists() {
        return Ok(());
    }
    if !path.is_dir() || !is_empty_dir(path)? {
        bail!(
            "{} already exists and is not an empty directory",
            path.display()
        );
    }
    fs::remove_dir(path).with_context(|| format!("failed to remove empty {}", path.display()))
}

fn ensure_cow_public_branch_root(
    layout: &Layout,
    branch: &str,
    storage_root: &Path,
    file_view: FileViewStrategy,
) -> Result<PathBuf> {
    let public_root = cow_branch_root(layout, branch);
    if file_view == FileViewStrategy::MacosApfsSparsebundle {
        ensure_macos_cow_public_branch_link(&public_root, storage_root)?;
    }
    Ok(public_root)
}

#[cfg(target_os = "macos")]
fn ensure_macos_cow_public_branch_link(public_root: &Path, storage_root: &Path) -> Result<()> {
    use std::os::unix::fs::symlink;

    match fs::symlink_metadata(public_root) {
        Ok(meta) if meta.file_type().is_symlink() => {
            let target = fs::read_link(public_root)
                .with_context(|| format!("failed to read symlink {}", public_root.display()))?;
            if target == storage_root {
                return Ok(());
            }
            bail!(
                "{} already points to {}; expected {}",
                public_root.display(),
                target.display(),
                storage_root.display()
            );
        }
        Ok(_) => {
            if public_root == storage_root {
                return Ok(());
            }
            bail!(
                "{} already exists; cannot link it to APFS sparsebundle storage at {}",
                public_root.display(),
                storage_root.display()
            );
        }
        Err(err) if err.kind() == std::io::ErrorKind::NotFound => {}
        Err(err) => {
            return Err(err)
                .with_context(|| format!("failed to inspect {}", public_root.display()));
        }
    }

    if let Some(parent) = public_root.parent() {
        fs::create_dir_all(parent)
            .with_context(|| format!("failed to create {}", parent.display()))?;
    }
    symlink(storage_root, public_root).with_context(|| {
        format!(
            "failed to link {} -> {}",
            public_root.display(),
            storage_root.display()
        )
    })
}

#[cfg(not(target_os = "macos"))]
fn ensure_macos_cow_public_branch_link(_public_root: &Path, _storage_root: &Path) -> Result<()> {
    bail!("macOS APFS sparsebundle file view is only available on macOS")
}

#[cfg(target_os = "macos")]
fn prepare_macos_apfs_sparsebundle_file_view(layout: &Layout) -> Result<()> {
    if layout.cow_branches_dir == layout.cow_dir.join("branches")
        && layout.cow_branches_dir.exists()
        && is_empty_dir(&layout.cow_branches_dir)?
    {
        fs::remove_dir(&layout.cow_branches_dir).with_context(|| {
            format!(
                "failed to remove empty {}",
                layout.cow_branches_dir.display()
            )
        })?;
    }
    ensure_macos_apfs_sparsebundle_file_view(layout)?;
    if !probe_reflink_dir(&layout.macos_cow_branches_dir)? {
        bail!(
            "mounted macOS APFS sparsebundle does not support clonefile at {}",
            layout.macos_cow_branches_dir.display()
        );
    }
    Ok(())
}

#[cfg(target_os = "macos")]
fn ensure_macos_apfs_sparsebundle_file_view(layout: &Layout) -> Result<()> {
    fs::create_dir_all(&layout.macos_cow_dir)
        .with_context(|| format!("failed to create {}", layout.macos_cow_dir.display()))?;

    if !layout.macos_cow_image.exists() {
        run_hdiutil([
            OsString::from("create"),
            OsString::from("-type"),
            OsString::from("SPARSEBUNDLE"),
            OsString::from("-fs"),
            OsString::from("APFS"),
            OsString::from("-volname"),
            OsString::from("ForkPressBranches"),
            OsString::from("-size"),
            OsString::from("32g"),
            layout.macos_cow_image.as_os_str().to_owned(),
        ])
        .with_context(|| {
            format!(
                "failed to create APFS sparsebundle at {}",
                layout.macos_cow_image.display()
            )
        })?;
    }

    if !layout.macos_cow_branches_dir.is_dir() {
        fs::create_dir_all(&layout.macos_cow_mount)
            .with_context(|| format!("failed to create {}", layout.macos_cow_mount.display()))?;
        run_hdiutil([
            OsString::from("attach"),
            OsString::from("-nobrowse"),
            OsString::from("-mountpoint"),
            layout.macos_cow_mount.as_os_str().to_owned(),
            layout.macos_cow_image.as_os_str().to_owned(),
        ])
        .with_context(|| {
            format!(
                "failed to mount APFS sparsebundle {} at {}",
                layout.macos_cow_image.display(),
                layout.macos_cow_mount.display()
            )
        })?;
        fs::create_dir_all(&layout.macos_cow_branches_dir).with_context(|| {
            format!(
                "failed to create {}",
                layout.macos_cow_branches_dir.display()
            )
        })?;
    }

    link_cow_branches_to_macos_cow(layout)
}

#[cfg(not(target_os = "macos"))]
fn ensure_macos_apfs_sparsebundle_file_view(_layout: &Layout) -> Result<()> {
    bail!("macOS APFS sparsebundle file view is only available on macOS")
}

#[derive(Debug, Clone)]
#[cfg(target_os = "macos")]
struct MacosMountInfo {
    device: String,
}

#[cfg(target_os = "macos")]
fn macos_mount_info(mount: &Path) -> Result<Option<MacosMountInfo>> {
    if !mount.exists() {
        return Ok(None);
    }

    use std::mem::MaybeUninit;

    let mount = absolutize(mount.to_path_buf())?;
    let mount_c = CString::new(mount.as_os_str().as_encoded_bytes())
        .with_context(|| format!("{} contains an interior NUL byte", mount.display()))?;
    let mut stat = MaybeUninit::<libc::statfs>::zeroed();
    let rc = unsafe { libc::statfs(mount_c.as_ptr(), stat.as_mut_ptr()) };
    if rc != 0 {
        return Err(std::io::Error::last_os_error())
            .with_context(|| format!("failed to inspect mount status for {}", mount.display()));
    }
    let stat = unsafe { stat.assume_init() };
    let mounted_on = c_char_array_to_string(&stat.f_mntonname);
    let mounted_on_path = PathBuf::from(&mounted_on);
    let mounted_on_canonical = fs::canonicalize(&mounted_on_path).unwrap_or(mounted_on_path);
    let mount_canonical = fs::canonicalize(&mount).unwrap_or_else(|_| mount.clone());
    if mounted_on_canonical != mount_canonical {
        return Ok(None);
    }

    Ok(Some(MacosMountInfo {
        device: c_char_array_to_string(&stat.f_mntfromname),
    }))
}

#[cfg(target_os = "macos")]
fn c_char_array_to_string(buf: &[libc::c_char]) -> String {
    let end = buf.iter().position(|ch| *ch == 0).unwrap_or(buf.len());
    let bytes: Vec<u8> = buf[..end].iter().map(|ch| *ch as u8).collect();
    String::from_utf8_lossy(&bytes).into_owned()
}

#[cfg(target_os = "macos")]
fn detach_macos_apfs_sparsebundle_file_view_impl(
    layout: &Layout,
    force: bool,
    print_remove_site_hint: bool,
) -> Result<()> {
    let Some(info) = macos_mount_info(&layout.macos_cow_mount)? else {
        println!(
            "forkpress: COW storage is already detached for {}",
            layout.work_dir.display()
        );
        println!(
            "Attach:     forkpress storage mount --work-dir {}",
            shell_quote_path(&layout.work_dir)
        );
        return Ok(());
    };

    let mut first_args = vec![OsString::from("detach")];
    if force {
        first_args.push(OsString::from("-force"));
    }
    first_args.push(OsString::from(&info.device));

    let first = hdiutil_output(first_args)?;
    if !first.status.success() {
        let mut fallback_args = vec![OsString::from("detach")];
        if force {
            fallback_args.push(OsString::from("-force"));
        }
        fallback_args.push(layout.macos_cow_mount.as_os_str().to_owned());
        let fallback = hdiutil_output(fallback_args)?;
        if !fallback.status.success() {
            let message = hdiutil_failure_message(&fallback);
            if message.to_ascii_lowercase().contains("busy") {
                bail!(
                    "COW storage is still busy at {}.\nClose terminals/editors using that path, or inspect open files with:\n  lsof +D {}\nThen run:\n  forkpress stop --work-dir {}{}",
                    layout.macos_cow_mount.display(),
                    shell_quote_path(&layout.macos_cow_mount),
                    shell_quote_path(&layout.work_dir),
                    if force { "" } else { " --force" }
                );
            }
            bail!("{message}");
        }
    }

    if macos_mount_info(&layout.macos_cow_mount)?.is_some() {
        bail!(
            "hdiutil reported success, but COW storage is still attached at {}",
            layout.macos_cow_mount.display()
        );
    }

    println!(
        "forkpress: detached COW storage mounted at {}",
        layout.macos_cow_mount.display()
    );
    if print_remove_site_hint {
        println!("Remove site: rm -rf {}", shell_quote_path(&layout.work_dir));
    }
    println!(
        "Attach again: forkpress storage mount --work-dir {}",
        shell_quote_path(&layout.work_dir)
    );
    Ok(())
}

#[cfg(not(target_os = "macos"))]
fn detach_macos_apfs_sparsebundle_file_view_impl(
    _layout: &Layout,
    _force: bool,
    _print_remove_site_hint: bool,
) -> Result<()> {
    bail!("macOS APFS sparsebundle detach is only available on macOS")
}

#[cfg(target_os = "macos")]
fn compact_macos_apfs_sparsebundle_file_view_impl(layout: &Layout) -> Result<()> {
    if !layout.macos_cow_image.exists() {
        println!(
            "forkpress: no APFS sparsebundle found at {}",
            layout.macos_cow_image.display()
        );
        return Ok(());
    }
    if macos_mount_info(&layout.macos_cow_mount)?.is_some() {
        bail!(
            "COW storage is still attached at {}. Run `forkpress stop --work-dir {}` before compacting.",
            layout.macos_cow_mount.display(),
            shell_quote_path(&layout.work_dir)
        );
    }

    let output = hdiutil_output([
        OsString::from("compact"),
        layout.macos_cow_image.as_os_str().to_owned(),
    ])?;
    if !output.status.success() {
        bail!("{}", hdiutil_failure_message(&output));
    }

    let stdout = String::from_utf8_lossy(&output.stdout);
    let stderr = String::from_utf8_lossy(&output.stderr);
    for line in stdout.lines().chain(stderr.lines()) {
        if !line.trim().is_empty() {
            println!("  {line}");
        }
    }
    println!(
        "forkpress: compacted COW sparsebundle {}",
        layout.macos_cow_image.display()
    );
    println!(
        "Attach again: forkpress storage mount --work-dir {}",
        shell_quote_path(&layout.work_dir)
    );
    Ok(())
}

#[cfg(not(target_os = "macos"))]
fn compact_macos_apfs_sparsebundle_file_view_impl(_layout: &Layout) -> Result<()> {
    bail!("macOS APFS sparsebundle compact is only available on macOS")
}

#[cfg(target_os = "macos")]
fn run_hdiutil(args: impl IntoIterator<Item = OsString>) -> Result<()> {
    let output = hdiutil_output(args)?;
    if output.status.success() {
        return Ok(());
    }
    bail!("{}", hdiutil_failure_message(&output))
}

#[cfg(target_os = "macos")]
fn hdiutil_output(args: impl IntoIterator<Item = OsString>) -> Result<std::process::Output> {
    Command::new("hdiutil")
        .args(args)
        .output()
        .context("failed to run hdiutil")
}

#[cfg(target_os = "macos")]
fn hdiutil_failure_message(output: &std::process::Output) -> String {
    let stdout = String::from_utf8_lossy(&output.stdout);
    let stderr = String::from_utf8_lossy(&output.stderr);
    format!(
        "hdiutil exited with status {}{}{}{}{}",
        output.status,
        if stdout.trim().is_empty() {
            ""
        } else {
            "\nstdout:\n"
        },
        stdout.trim(),
        if stderr.trim().is_empty() {
            ""
        } else {
            "\nstderr:\n"
        },
        stderr.trim()
    )
}

#[cfg(target_os = "macos")]
fn link_cow_branches_to_macos_cow(layout: &Layout) -> Result<()> {
    use std::os::unix::fs::symlink;

    if layout.cow_branches_dir != layout.cow_dir.join("branches") {
        return Ok(());
    }

    fs::create_dir_all(&layout.cow_dir)
        .with_context(|| format!("failed to create {}", layout.cow_dir.display()))?;

    match fs::symlink_metadata(&layout.cow_branches_dir) {
        Ok(meta) if meta.file_type().is_symlink() => {
            let target = fs::read_link(&layout.cow_branches_dir).with_context(|| {
                format!(
                    "failed to read symlink {}",
                    layout.cow_branches_dir.display()
                )
            })?;
            if target == layout.macos_cow_branches_dir {
                return Ok(());
            }
            bail!(
                "{} already points to {}; expected {}",
                layout.cow_branches_dir.display(),
                target.display(),
                layout.macos_cow_branches_dir.display()
            );
        }
        Ok(meta) if meta.is_dir() => {
            if !is_empty_dir(&layout.cow_branches_dir)? {
                bail!(
                    "{} already contains branch data and cannot be replaced with APFS sparsebundle storage",
                    layout.cow_branches_dir.display()
                );
            }
            fs::remove_dir(&layout.cow_branches_dir).with_context(|| {
                format!(
                    "failed to remove empty {}",
                    layout.cow_branches_dir.display()
                )
            })?;
        }
        Ok(_) => bail!(
            "{} exists and is not a directory or symlink",
            layout.cow_branches_dir.display()
        ),
        Err(err) if err.kind() == std::io::ErrorKind::NotFound => {}
        Err(err) => {
            return Err(err).with_context(|| {
                format!("failed to inspect {}", layout.cow_branches_dir.display())
            });
        }
    }

    symlink(&layout.macos_cow_branches_dir, &layout.cow_branches_dir).with_context(|| {
        format!(
            "failed to link {} -> {}",
            layout.cow_branches_dir.display(),
            layout.macos_cow_branches_dir.display()
        )
    })
}

#[cfg_attr(not(target_os = "macos"), allow(dead_code))]
fn is_empty_dir(path: &Path) -> Result<bool> {
    let mut entries =
        fs::read_dir(path).with_context(|| format!("failed to read {}", path.display()))?;
    Ok(entries.next().transpose()?.is_none())
}

fn run_cow_bootstrap_script(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    branch_root: &Path,
    site_title: &str,
    admin_password: &str,
) -> Result<()> {
    run_php_script(
        layout,
        runtime,
        shared,
        "runtime/cow/bootstrap_wp.php",
        [
            branch_root.as_os_str(),
            OsStr::new(site_title),
            layout
                .runtime_dir
                .join("vendor/sqlite-database-integration")
                .as_os_str(),
            layout
                .runtime_dir
                .join("wp-plugin/forkpress-wp.php")
                .as_os_str(),
            layout.debug_log.as_os_str(),
            OsStr::new(admin_password),
        ],
    )
}

fn cow_sqlite_db_path(branch_root: &Path) -> PathBuf {
    branch_root.join("wp-content/database/.ht.sqlite")
}

fn sqlite_sidecar_path(db: &Path, suffix: &str) -> PathBuf {
    let mut value = db.as_os_str().to_os_string();
    value.push(suffix);
    PathBuf::from(value)
}

fn remove_sqlite_file_and_sidecars(db: &Path) -> Result<()> {
    for path in [
        db.to_path_buf(),
        sqlite_sidecar_path(db, "-wal"),
        sqlite_sidecar_path(db, "-shm"),
    ] {
        match fs::symlink_metadata(&path) {
            Ok(meta) if meta.is_file() => {
                fs::remove_file(&path)
                    .with_context(|| format!("failed to remove {}", path.display()))?;
            }
            Ok(_) => bail!("{} is not a regular SQLite file", path.display()),
            Err(err) if err.kind() == std::io::ErrorKind::NotFound => {}
            Err(err) => {
                return Err(err).with_context(|| format!("failed to inspect {}", path.display()));
            }
        }
    }
    Ok(())
}

fn hot_copy_sqlite_database(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    source_db: &Path,
    dest_db: &Path,
) -> Result<()> {
    if let Some(parent) = dest_db.parent() {
        fs::create_dir_all(parent)
            .with_context(|| format!("failed to create {}", parent.display()))?;
    }
    run_php_script(
        layout,
        runtime,
        shared,
        "scripts/shared/sqlite_backup.php",
        [source_db.as_os_str(), dest_db.as_os_str()],
    )
}

fn unique_cow_operation_dir(parent: &Path, operation: &str, branch: &str) -> PathBuf {
    let nanos = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_default()
        .as_nanos();
    parent.join(format!(
        ".forkpress-{operation}-{branch}-{}-{nanos}",
        std::process::id()
    ))
}

fn count_regular_files(root: &Path) -> Result<usize> {
    let mut count = 0usize;
    let mut stack = vec![root.to_path_buf()];
    while let Some(dir) = stack.pop() {
        for entry in
            fs::read_dir(&dir).with_context(|| format!("failed to read {}", dir.display()))?
        {
            let entry = entry?;
            let file_type = entry.file_type()?;
            if file_type.is_dir() {
                stack.push(entry.path());
            } else if file_type.is_file() {
                count += 1;
            }
        }
    }
    Ok(count)
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum TreeCloneMode {
    AllowCopyFallback,
    RequireCow,
}

const WINDOWS_REFS_CLONE_ALIGNMENT: u64 = 64 * 1024;
#[cfg(target_os = "windows")]
const WINDOWS_REFS_MAX_CLONE_CHUNK: u64 = 1024 * 1024 * 1024;

fn copy_tree_cow_mode(source: &Path, dest: &Path, mode: TreeCloneMode) -> Result<()> {
    if !source.is_dir() {
        bail!("source directory not found: {}", source.display());
    }
    if dest.exists() {
        bail!("destination already exists: {}", dest.display());
    }
    fs::create_dir_all(dest).with_context(|| format!("failed to create {}", dest.display()))?;

    let mut stack = vec![source.to_path_buf()];
    while let Some(dir) = stack.pop() {
        let rel = dir
            .strip_prefix(source)
            .with_context(|| format!("{} is not under {}", dir.display(), source.display()))?;
        let out_dir = dest.join(rel);
        fs::create_dir_all(&out_dir)
            .with_context(|| format!("failed to create {}", out_dir.display()))?;

        for entry in fs::read_dir(&dir)? {
            let entry = entry?;
            let path = entry.path();
            let file_type = entry.file_type()?;
            let rel_path = path.strip_prefix(source)?;
            let out_path = dest.join(rel_path);
            if file_type.is_dir() {
                stack.push(path);
            } else if file_type.is_file() {
                clone_or_copy_file(&path, &out_path, mode)?;
            }
        }
    }
    Ok(())
}

fn clone_or_copy_file(source: &Path, dest: &Path, mode: TreeCloneMode) -> Result<()> {
    if let Some(parent) = dest.parent() {
        fs::create_dir_all(parent)?;
    }
    if let Err(err) = try_clone_file(source, dest) {
        if mode == TreeCloneMode::RequireCow {
            let _ = fs::remove_file(dest);
            return Err(err).with_context(|| {
                format!(
                    "filesystem COW clone is required for {} -> {}",
                    source.display(),
                    dest.display()
                )
            });
        }
        fs::copy(source, dest).with_context(|| {
            format!("failed to copy {} to {}", source.display(), dest.display())
        })?;
    }
    let permissions = fs::metadata(source)?.permissions();
    let _ = fs::set_permissions(dest, permissions);
    Ok(())
}

#[cfg(target_os = "linux")]
fn try_clone_file(source: &Path, dest: &Path) -> Result<()> {
    use std::os::fd::AsRawFd;
    let src = File::open(source)?;
    let dst = OpenOptions::new().write(true).create_new(true).open(dest)?;
    let rc = unsafe { libc::ioctl(dst.as_raw_fd(), 0x4004_9409 as _, src.as_raw_fd()) };
    if rc == 0 {
        Ok(())
    } else {
        let _ = fs::remove_file(dest);
        Err(std::io::Error::last_os_error()).context("FICLONE failed")
    }
}

#[cfg(target_os = "macos")]
fn try_clone_file(source: &Path, dest: &Path) -> Result<()> {
    use std::os::unix::ffi::OsStrExt;
    unsafe extern "C" {
        fn clonefile(src: *const libc::c_char, dst: *const libc::c_char, flags: u32)
        -> libc::c_int;
    }
    let src = CString::new(source.as_os_str().as_bytes())?;
    let dst = CString::new(dest.as_os_str().as_bytes())?;
    let rc = unsafe { clonefile(src.as_ptr(), dst.as_ptr(), 0) };
    if rc == 0 {
        Ok(())
    } else {
        let _ = fs::remove_file(dest);
        Err(std::io::Error::last_os_error()).context("clonefile failed")
    }
}

#[cfg(target_os = "windows")]
fn try_clone_file(source: &Path, dest: &Path) -> Result<()> {
    let mut src = File::open(source)?;
    let mut dst = OpenOptions::new()
        .read(true)
        .write(true)
        .create_new(true)
        .open(dest)?;
    let source_len = src.metadata()?.len();
    dst.set_len(source_len)?;

    let (clone_len, tail_len) = windows_refs_clone_plan(source_len);
    if clone_len > 0
        && let Err(err) = windows_refs_duplicate_extents(&src, &dst, clone_len)
    {
        let _ = fs::remove_file(dest);
        return Err(err);
    }

    if tail_len > 0
        && let Err(err) = windows_copy_uncloned_tail(&mut src, &mut dst, clone_len, tail_len)
    {
        let _ = fs::remove_file(dest);
        return Err(err);
    }

    Ok(())
}

#[cfg_attr(not(target_os = "windows"), allow(dead_code))]
fn windows_refs_clone_plan(file_len: u64) -> (u64, u64) {
    let clone_len = file_len / WINDOWS_REFS_CLONE_ALIGNMENT * WINDOWS_REFS_CLONE_ALIGNMENT;
    let tail_len = file_len - clone_len;
    (clone_len, tail_len)
}

#[cfg(target_os = "windows")]
fn windows_refs_duplicate_extents(source: &File, dest: &File, clone_len: u64) -> Result<()> {
    use std::ffi::c_void;
    use std::mem::size_of;
    use std::os::windows::io::AsRawHandle;

    const FSCTL_DUPLICATE_EXTENTS_TO_FILE: u32 = 0x0009_8344;

    #[repr(C)]
    struct DuplicateExtentsData {
        file_handle: *mut c_void,
        source_file_offset: i64,
        target_file_offset: i64,
        byte_count: i64,
    }

    #[link(name = "kernel32")]
    unsafe extern "system" {
        fn DeviceIoControl(
            h_device: *mut c_void,
            dw_io_control_code: u32,
            lp_in_buffer: *mut c_void,
            n_in_buffer_size: u32,
            lp_out_buffer: *mut c_void,
            n_out_buffer_size: u32,
            lp_bytes_returned: *mut u32,
            lp_overlapped: *mut c_void,
        ) -> i32;
    }

    let mut offset = 0;
    while offset < clone_len {
        let chunk_len = (clone_len - offset).min(WINDOWS_REFS_MAX_CLONE_CHUNK);
        let mut request = DuplicateExtentsData {
            file_handle: source.as_raw_handle(),
            source_file_offset: i64::try_from(offset)
                .context("source offset exceeds Windows LARGE_INTEGER range")?,
            target_file_offset: i64::try_from(offset)
                .context("target offset exceeds Windows LARGE_INTEGER range")?,
            byte_count: i64::try_from(chunk_len)
                .context("clone length exceeds Windows LARGE_INTEGER range")?,
        };
        let mut bytes_returned = 0u32;
        let ok = unsafe {
            DeviceIoControl(
                dest.as_raw_handle(),
                FSCTL_DUPLICATE_EXTENTS_TO_FILE,
                &mut request as *mut _ as *mut c_void,
                size_of::<DuplicateExtentsData>() as u32,
                std::ptr::null_mut(),
                0,
                &mut bytes_returned,
                std::ptr::null_mut(),
            )
        };
        if ok == 0 {
            return Err(std::io::Error::last_os_error())
                .context("FSCTL_DUPLICATE_EXTENTS_TO_FILE failed");
        }
        offset += chunk_len;
    }
    Ok(())
}

#[cfg(target_os = "windows")]
fn windows_copy_uncloned_tail(
    source: &mut File,
    dest: &mut File,
    offset: u64,
    tail_len: u64,
) -> Result<()> {
    source.seek(SeekFrom::Start(offset))?;
    dest.seek(SeekFrom::Start(offset))?;

    let mut remaining = tail_len;
    let mut buffer = vec![0; WINDOWS_REFS_CLONE_ALIGNMENT as usize];
    while remaining > 0 {
        let read_len = remaining.min(buffer.len() as u64) as usize;
        source.read_exact(&mut buffer[..read_len])?;
        dest.write_all(&buffer[..read_len])?;
        remaining -= read_len as u64;
    }
    Ok(())
}

#[cfg(not(any(target_os = "linux", target_os = "macos", target_os = "windows")))]
fn try_clone_file(_source: &Path, _dest: &Path) -> Result<()> {
    bail!("platform file clone unsupported")
}

#[cfg(target_os = "macos")]
fn shell_quote_path(path: &std::path::Path) -> String {
    shell_quote(&path.to_string_lossy())
}

#[cfg(target_os = "macos")]
fn shell_quote(value: &str) -> String {
    if value
        .chars()
        .all(|ch| ch.is_ascii_alphanumeric() || matches!(ch, '/' | '.' | '_' | '-' | ':'))
    {
        return value.to_string();
    }
    format!("'{}'", value.replace('\'', "'\\''"))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reset_rollback_reports_restore_outcome() {
        let root = std::env::temp_dir().join(format!(
            "forkpress-reset-rollback-{}-{}",
            std::process::id(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        let target = root.join("branch");
        let backup = root.join(".forkpress-reset-backup-branch");
        let staging = root.join(".forkpress-reset-stage-branch");
        let failed = root.join(".forkpress-reset-failed-branch");
        fs::create_dir_all(&backup).unwrap();
        fs::write(backup.join("wp-load.php"), b"<?php\n").unwrap();
        fs::create_dir_all(&staging).unwrap();

        let message = rollback_failed_reset_publish(
            "branch", &target, &backup, &staging, &failed, true, false,
        );
        assert!(message.contains("restored previous branch contents"));
        assert!(target.join("wp-load.php").is_file());
        assert!(!path_exists_no_follow(&backup));
        assert!(!path_exists_no_follow(&staging));

        fs::remove_dir_all(&target).unwrap();
        fs::create_dir_all(&target).unwrap();
        fs::create_dir_all(&backup).unwrap();
        let message = rollback_failed_reset_publish(
            "branch", &target, &backup, &staging, &failed, true, false,
        );
        assert!(message.contains("rollback incomplete"));
        assert!(message.contains("backup remains"));
        assert!(path_exists_no_follow(&backup));

        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn windows_refs_clone_plan_keeps_unaligned_tail_out_of_ioctl() {
        assert_eq!(windows_refs_clone_plan(0), (0, 0));
        assert_eq!(windows_refs_clone_plan(1), (0, 1));
        assert_eq!(
            windows_refs_clone_plan(WINDOWS_REFS_CLONE_ALIGNMENT - 1),
            (0, WINDOWS_REFS_CLONE_ALIGNMENT - 1)
        );
        assert_eq!(
            windows_refs_clone_plan(WINDOWS_REFS_CLONE_ALIGNMENT),
            (WINDOWS_REFS_CLONE_ALIGNMENT, 0)
        );
        assert_eq!(
            windows_refs_clone_plan(WINDOWS_REFS_CLONE_ALIGNMENT + 3),
            (WINDOWS_REFS_CLONE_ALIGNMENT, 3)
        );
    }

    #[test]
    fn windows_refs_probe_bytes_are_large_enough_for_block_clone() {
        let bytes = reflink_probe_bytes();
        #[cfg(target_os = "windows")]
        assert_eq!(bytes.len() as u64, WINDOWS_REFS_CLONE_ALIGNMENT * 2);
        #[cfg(not(target_os = "windows"))]
        assert_eq!(bytes, b"canonical");
    }
}

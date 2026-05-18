use anyhow::{Context, Result, anyhow, bail};
#[cfg(test)]
use std::cell::RefCell;
#[cfg(any(target_os = "linux", target_os = "macos"))]
use std::ffi::CString;
use std::ffi::{OsStr, OsString};
use std::fs::{self, File, OpenOptions};
#[cfg(any(target_os = "linux", target_os = "windows"))]
use std::io::{Read, Seek, SeekFrom, Write};
#[cfg(target_os = "windows")]
use std::os::windows::ffi::OsStrExt;
use std::path::{Path, PathBuf};
#[cfg(target_os = "macos")]
use std::process::Command;
use std::time::{SystemTime, UNIX_EPOCH};

#[cfg(target_os = "linux")]
use flate2::read::GzDecoder;
#[cfg(target_os = "macos")]
use forkpress_core::absolutize;
use forkpress_core::{
    FileViewStrategy, Layout, SharedPaths, StorageStrategy, path_exists_no_follow,
    read_site_manifest, validate_branch_name, write_site_manifest,
};
use forkpress_runtime::{PortableRuntime, run_php_script};
#[cfg(target_os = "windows")]
use windows_sys::Win32::Storage::FileSystem::{
    MOVEFILE_REPLACE_EXISTING, MOVEFILE_WRITE_THROUGH, MoveFileExW,
};

#[cfg(test)]
thread_local! {
    static COW_STORAGE_TEST_FAILPOINT: RefCell<Option<(String, String)>> = RefCell::new(None);
}

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
clone). On Linux, if the current location cannot clone files, ForkPress writes
a sparse XFS image under the user's ForkPress data directory, attaches it to a
loop device, mounts one shared XFS volume for all ForkPress sites, and links
each public branch directory, such as `./main`, into that volume. On macOS, if
the current location cannot clone files, ForkPress creates a rootless APFS
sparsebundle under `.forkpress/macos-cow`, mounts it at
`.forkpress/macos-cow/mount`, and links public branches into that APFS volume.
On Windows, put the site on a ReFS Dev Drive created by `ForkPressSetup.exe`.
A regular full copy is only the last-resort file view on platforms where
ForkPress can make that tradeoff explicit.

APFS/XFS clone sharing is not visible to tools that add up path sizes. `du`,
Finder, and many disk analyzers can count shared clone extents once for every
branch, so a COW branch can appear to consume another full WordPress tree. To
inspect physical growth on macOS, compare `df -h .forkpress/macos-cow/mount`
before and after branch creation, or inspect the allocated size of
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
    let mount_backed_detached = file_view
        .map(|file_view| cow_file_view_detached(layout, file_view))
        .unwrap_or(false);
    let detached_label = if file_view == Some(FileViewStrategy::MacosApfsSparsebundle) {
        "sparsebundle"
    } else {
        "mount-backed storage"
    };
    if mount_backed_detached {
        println!("  branches:  unavailable while {detached_label} is detached");
    } else {
        println!("  branches:  {}", cow_branch_names(layout)?.len());
    }
    println!("  public:    {}", layout.cow_branches_dir.display());
    println!(
        "  storage:   {}",
        cow_file_view_storage_dir(layout, file_view).display()
    );
    println!(
        "  lock:      {}",
        layout.cow_dir.join("operations.lock").display()
    );
    println!(
        "  lifecycle: {}",
        layout.cow_dir.join("lifecycle.lock").display()
    );

    if mount_backed_detached {
        println!("  leftovers: unavailable while {detached_label} is detached");
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

fn cow_file_view_detached(layout: &Layout, file_view: FileViewStrategy) -> bool {
    match file_view {
        FileViewStrategy::MacosApfsSparsebundle => !layout.macos_cow_branches_dir.exists(),
        FileViewStrategy::LinuxXfsLoop => !layout.linux_xfs_branches_dir.exists(),
        FileViewStrategy::Reflink | FileViewStrategy::Copy => false,
    }
}

fn cow_file_view_storage_dir(layout: &Layout, file_view: Option<FileViewStrategy>) -> &Path {
    match file_view {
        Some(FileViewStrategy::MacosApfsSparsebundle) => &layout.macos_cow_branches_dir,
        Some(FileViewStrategy::LinuxXfsLoop) => &layout.linux_xfs_branches_dir,
        Some(FileViewStrategy::Reflink) | Some(FileViewStrategy::Copy) | None => {
            &layout.cow_branches_dir
        }
    }
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

#[cfg(target_os = "linux")]
pub fn print_linux_xfs_loop_storage_status(layout: &Layout) -> Result<()> {
    println!("  image:     {}", layout.linux_xfs_image.display());
    println!("  mount:     {}", layout.linux_xfs_mount.display());
    match linux_xfs_mount_info(&layout.linux_xfs_mount)? {
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

#[cfg(not(target_os = "linux"))]
pub fn print_linux_xfs_loop_storage_status(layout: &Layout) -> Result<()> {
    println!("  image:     {}", layout.linux_xfs_image.display());
    println!("  mount:     {}", layout.linux_xfs_mount.display());
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

    #[cfg(target_os = "linux")]
    {
        if std::env::var_os("FORKPRESS_FORCE_LINUX_XFS_LOOP").is_some() {
            if let Err(err) = prepare_linux_xfs_loop_file_view(layout) {
                return Err(cleanup_failed_linux_xfs_loop_prepare(layout, err)?);
            }
            return Ok(FileViewStrategy::LinuxXfsLoop);
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

    #[cfg(target_os = "linux")]
    {
        match prepare_linux_xfs_loop_file_view(layout) {
            Ok(()) => return Ok(FileViewStrategy::LinuxXfsLoop),
            Err(err) => {
                let err = cleanup_failed_linux_xfs_loop_prepare(layout, err)?;
                eprintln!("forkpress: Linux XFS loop COW setup failed: {err:#}");
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
        reconcile_cow_public_branch_links(layout, file_view)?;
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
        FileViewStrategy::LinuxXfsLoop => {
            ensure_linux_xfs_loop_file_view(layout)?;
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

pub fn reconcile_cow_public_branch_links(
    layout: &Layout,
    file_view: FileViewStrategy,
) -> Result<()> {
    let storage_branches_dir = match file_view {
        FileViewStrategy::MacosApfsSparsebundle => &layout.macos_cow_branches_dir,
        FileViewStrategy::LinuxXfsLoop => &layout.linux_xfs_branches_dir,
        FileViewStrategy::Reflink | FileViewStrategy::Copy => return Ok(()),
    };
    let Ok(entries) = fs::read_dir(storage_branches_dir) else {
        return Ok(());
    };

    for entry in entries {
        let entry = entry.with_context(|| {
            format!(
                "failed to read branch storage entry under {}",
                storage_branches_dir.display()
            )
        })?;
        let branch = entry.file_name().to_string_lossy().into_owned();
        if validate_branch_name(&branch).is_err() {
            continue;
        }
        let storage_root = entry.path();
        if !storage_root.join("wp-load.php").is_file() {
            continue;
        }
        ensure_cow_public_branch_root(layout, &branch, &storage_root, file_view)?;
    }
    Ok(())
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
    let copy_mode = if cow_branch_copies_require_cow(layout)? {
        TreeCloneMode::RequireCow
    } else {
        TreeCloneMode::AllowCopyFallback
    };
    create_cow_branch_from_tree_with_copy_mode(
        layout,
        runtime,
        shared,
        branch,
        source,
        source_label,
        seed_branch,
        url_hint,
        copy_mode,
    )
}

pub fn create_cow_branch_from_external_tree(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    branch: &str,
    source: &Path,
    source_label: &str,
    seed_branch: Option<&str>,
    url_hint: Option<(String, String)>,
) -> Result<()> {
    create_cow_branch_from_tree_with_copy_mode(
        layout,
        runtime,
        shared,
        branch,
        source,
        source_label,
        seed_branch,
        url_hint,
        TreeCloneMode::AllowCopyFallback,
    )
}

fn create_cow_branch_from_tree_with_copy_mode(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    branch: &str,
    source: &Path,
    source_label: &str,
    seed_branch: Option<&str>,
    url_hint: Option<(String, String)>,
    copy_mode: TreeCloneMode,
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
    let dest_parent = dest.parent().ok_or_else(|| {
        anyhow!(
            "branch destination has no parent directory: {}",
            dest.display()
        )
    })?;
    fs::create_dir_all(dest_parent)
        .with_context(|| format!("failed to create {}", dest_parent.display()))?;
    let staging = unique_cow_operation_dir(dest_parent, "branch-create-stage", branch);
    if path_exists_no_follow(&staging) {
        bail!("temporary branch creation path already exists");
    }
    cleanup_unpublished_cow_branch_birth_artifacts(
        layout, runtime, shared, branch, &staging, &dest, file_view,
    )
    .with_context(|| {
        format!("failed to clean stale unpublished COW branch birth artifacts for '{branch}'")
    })?;

    let source_db = cow_sqlite_db_path(source);
    let mut staging_published = false;
    let create_result = (|| -> Result<()> {
        copy_tree_cow_mode(source, &staging, copy_mode)?;

        run_cow_bootstrap_script(layout, runtime, shared, &staging, "ForkPress", "admin")?;
        if !source_db.is_file() {
            bail!(
                "source branch database does not exist: {}",
                source_db.display()
            );
        }
        record_cow_merge_base_snapshot(layout, runtime, shared, branch, &source_db)?;
        let branch_db = cow_sqlite_db_path(&staging);
        if !branch_db.is_file() {
            bail!(
                "created branch database does not exist: {}",
                branch_db.display()
            );
        }
        allocate_cow_autoincrement_bands(layout, runtime, shared, branch, &branch_db)?;
        capture_cow_row_identities(layout, runtime, shared, branch, &branch_db, seed_branch)?;
        record_cow_file_merge_base_snapshot(layout, runtime, shared, branch, &staging)?;
        cow_storage_failpoint("after-branch-create-birth-metadata")?;

        if path_exists_no_follow(&dest) {
            bail!("branch already exists: {branch}");
        }
        fs::rename(&staging, &dest).with_context(|| {
            format!(
                "failed to publish branch {} to {}",
                staging.display(),
                dest.display()
            )
        })?;
        staging_published = true;
        let public_root = ensure_cow_public_branch_root(layout, branch, &dest, file_view)?;
        run_cow_bootstrap_script(layout, runtime, shared, &public_root, "ForkPress", "admin")?;
        write_cow_branch_list(layout)?;
        Ok(())
    })();

    if let Err(err) = create_result {
        let cleanup = cleanup_failed_cow_branch_create(
            layout,
            runtime,
            shared,
            branch,
            &staging,
            &dest,
            file_view,
            staging_published,
        );
        return Err(err).context(format!("failed to create COW branch '{branch}'; {cleanup}"));
    }

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
        ensure_no_pending_cow_reset(layout, branch)?;
        let root = if storage_root.join("wp-load.php").is_file() {
            &storage_root
        } else {
            &public_root
        };
        let db = validate_existing_cow_branch_birth_files(layout, branch, root)?;
        validate_cow_branch_birth_metadata(layout, runtime, shared, branch, &db)
            .with_context(|| {
                format!(
                    "existing COW branch '{branch}' is missing required merge metadata; reset or delete/recreate it before reuse"
                )
            })?;
        println!("forkpress: reusing existing branch {branch}");
        return Ok(());
    }
    create_cow_branch(layout, runtime, shared, branch, from, url_hint)
}

fn validate_existing_cow_branch_birth_files(
    layout: &Layout,
    branch: &str,
    root: &Path,
) -> Result<PathBuf> {
    let db = cow_sqlite_db_path(root);
    if !db.is_file() {
        bail!(
            "existing COW branch '{branch}' is missing its database at {}. Reset or delete/recreate it before reuse.",
            db.display()
        );
    }
    let base_db = cow_merge_base_db_path(layout, branch)?;
    if !base_db.is_file() {
        bail!(
            "existing COW branch '{branch}' is missing its DB merge base at {}. Reset or delete/recreate it before reuse.",
            base_db.display()
        );
    }
    let file_base = cow_merge_file_base_path(layout, branch)?;
    if !file_base.is_file() {
        bail!(
            "existing COW branch '{branch}' is missing its filesystem merge base at {}. Reset or delete/recreate it before reuse.",
            file_base.display()
        );
    }
    Ok(db)
}

fn cow_reset_pending_path(layout: &Layout, branch: &str) -> Result<PathBuf> {
    validate_branch_name(branch)?;
    Ok(layout
        .cow_dir
        .join("reset-pending")
        .join(format!("{branch}.txt")))
}

fn write_cow_reset_pending(layout: &Layout, branch: &str, from: &str) -> Result<()> {
    let path = cow_reset_pending_path(layout, branch)?;
    if let Some(parent) = path.parent() {
        fs::create_dir_all(parent)
            .with_context(|| format!("failed to create {}", parent.display()))?;
    }
    fs::write(
        &path,
        format!(
            "branch={branch}\nfrom={from}\ncreated_at_unix={}\n",
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap_or_default()
                .as_secs()
        ),
    )
    .with_context(|| {
        format!(
            "failed to record pending COW branch reset at {}",
            path.display()
        )
    })
}

fn clear_cow_reset_pending(layout: &Layout, branch: &str) -> Result<()> {
    let path = cow_reset_pending_path(layout, branch)?;
    match fs::remove_file(&path) {
        Ok(()) => Ok(()),
        Err(err) if err.kind() == std::io::ErrorKind::NotFound => Ok(()),
        Err(err) => Err(err).with_context(|| {
            format!(
                "failed to clear pending COW branch reset marker {}",
                path.display()
            )
        }),
    }
}

fn ensure_no_pending_cow_reset(layout: &Layout, branch: &str) -> Result<()> {
    let path = cow_reset_pending_path(layout, branch)?;
    if path.exists() {
        bail!(
            "COW branch '{branch}' has an unfinished reset recorded at {}. Rerun `forkpress branch reset {branch} --from <source>` or delete/recreate the branch before reuse or merge.",
            path.display()
        );
    }
    Ok(())
}

fn cow_storage_failpoint(name: &str) -> Result<()> {
    #[cfg(test)]
    {
        let configured = COW_STORAGE_TEST_FAILPOINT.with(|failpoint| failpoint.borrow().clone());
        if let Some((configured_name, action)) = configured {
            if configured_name == name {
                return cow_storage_failpoint_action(name, &action);
            }
        }
    }

    let configured = match std::env::var("FORKPRESS_COW_STORAGE_TEST_FAILPOINT") {
        Ok(value) if !value.is_empty() => value,
        _ => return Ok(()),
    };
    if !configured
        .split(',')
        .map(str::trim)
        .any(|candidate| candidate == name)
    {
        return Ok(());
    }

    let action = std::env::var("FORKPRESS_COW_STORAGE_TEST_FAILPOINT_ACTION")
        .unwrap_or_else(|_| "throw".to_string());
    cow_storage_failpoint_action(name, &action)
}

fn cow_storage_failpoint_action(name: &str, action: &str) -> Result<()> {
    match action {
        "exit" => std::process::exit(86),
        _ => bail!("forced COW storage failpoint: {name}"),
    }
}

fn clear_cow_reset_pending_if_rollback_complete(
    layout: &Layout,
    branch: &str,
    rollback_notes: &[&str],
) {
    if rollback_notes
        .iter()
        .all(|note| !note.contains("incomplete"))
    {
        let _ = clear_cow_reset_pending(layout, branch);
    }
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
    ensure_no_pending_cow_reset(layout, from)?;

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

    write_cow_reset_pending(layout, branch, from)?;
    let metadata_backup = match snapshot_cow_reset_metadata(layout, runtime, shared, branch, parent)
    {
        Ok(backup) => backup,
        Err(err) => {
            let _ = fs::remove_dir_all(&staging);
            clear_cow_reset_pending_if_rollback_complete(
                layout,
                branch,
                &["removed unpublished reset staging"],
            );
            return Err(err).context("failed to snapshot COW reset metadata before staging reset");
        }
    };

    let finalize_staging = (|| -> Result<()> {
        record_cow_merge_base_snapshot(layout, runtime, shared, branch, &source_db)?;
        let staged_db = cow_sqlite_db_path(&staging);
        if staged_db.is_file() {
            allocate_cow_autoincrement_bands(layout, runtime, shared, branch, &staged_db)?;
            capture_cow_row_identities(layout, runtime, shared, branch, &staged_db, Some(from))?;
        }
        record_cow_file_merge_base_snapshot(layout, runtime, shared, branch, &staging)?;
        cow_storage_failpoint("after-branch-reset-birth-metadata")?;
        Ok(())
    })();

    if let Err(err) = finalize_staging {
        let metadata_rollback = metadata_backup.restore(layout);
        let staging_cleanup = fs::remove_dir_all(&staging)
            .map(|_| "removed unpublished reset staging".to_string())
            .unwrap_or_else(|cleanup_err| {
                format!(
                    "rollback incomplete: failed to remove {}: {cleanup_err}",
                    staging.display()
                )
            });
        clear_cow_reset_pending_if_rollback_complete(
            layout,
            branch,
            &[&metadata_rollback, &staging_cleanup],
        );
        return Err(err).context(format!(
            "failed to finalize COW branch reset metadata before publish; {metadata_rollback}; {staging_cleanup}"
        ));
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
        let metadata_rollback = metadata_backup.restore(layout);
        let rollback = rollback_failed_reset_publish(
            branch,
            &target,
            &backup,
            &staging,
            &failed,
            target_moved_to_backup,
            staging_published,
        );
        clear_cow_reset_pending_if_rollback_complete(
            layout,
            branch,
            &[&metadata_rollback, &rollback],
        );
        return Err(err).context(format!(
            "failed to reset COW branch; {metadata_rollback}; {rollback}"
        ));
    }

    cow_storage_failpoint("after-branch-reset-publish")?;
    if let Err(err) = invalidate_cow_git_ref(layout, branch) {
        let metadata_rollback = metadata_backup.restore(layout);
        let failed = unique_cow_operation_dir(parent, "reset-failed", branch);
        let branch_rollback = rollback_failed_reset_publish(
            branch,
            &target,
            &backup,
            &staging,
            &failed,
            target_moved_to_backup,
            staging_published,
        );
        clear_cow_reset_pending_if_rollback_complete(
            layout,
            branch,
            &[&metadata_rollback, &branch_rollback],
        );
        return Err(err).context(format!(
            "failed to finalize COW branch reset publication; {metadata_rollback}; {branch_rollback}"
        ));
    }
    clear_cow_reset_pending(layout, branch)?;
    metadata_backup.cleanup();

    if let Err(err) = fs::remove_dir_all(&backup) {
        eprintln!(
            "forkpress: warning: reset succeeded but failed to remove old branch backup {}: {err}",
            backup.display()
        );
    }

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

fn cleanup_cow_branch_birth_metadata(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    branch: &str,
) -> Result<()> {
    let metadata_db = cow_merge_metadata_db_path(layout);
    if !metadata_db.is_file() {
        return Ok(());
    }
    let args: Vec<OsString> = vec![
        "cleanup-branch-birth-metadata".into(),
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

fn validate_cow_branch_birth_metadata(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    branch: &str,
    db: &Path,
) -> Result<()> {
    let metadata_db = cow_merge_metadata_db_path(layout);
    let args: Vec<OsString> = vec![
        "validate-branch-birth-metadata".into(),
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
    remove_sqlite_sidecars(&dest)?;
    publish_file_atomically(&tmp_db, &dest, "merge base snapshot")?;
    remove_sqlite_sidecars(&dest)?;
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
    publish_file_atomically(&tmp, &dest, "filesystem merge base")?;
    Ok(())
}

pub fn merge_cow_branch(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    source: &str,
    target: &str,
    plugin_validator: Option<&Path>,
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
    ensure_no_pending_cow_reset(layout, source)?;
    ensure_no_pending_cow_reset(layout, target)?;

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
    validate_cow_branch_birth_metadata(layout, runtime, shared, source, &source_db)
        .with_context(|| format!("branch '{source}' is missing required merge metadata"))?;

    let metadata_db = cow_merge_metadata_db_path(layout);
    let mut args: Vec<OsString> = vec![
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
    if let Some(plugin_validator) = plugin_validator {
        args.push("--plugin-validator".into());
        args.push(plugin_validator.as_os_str().to_os_string());
    }
    run_php_script(
        layout,
        runtime,
        shared,
        "scripts/cow/merge.php",
        args.iter().map(|arg| arg.as_os_str()),
    )?;
    // Advance only the source branch's merge bases after a successful merge so
    // future merges from that source compare against its post-merge state. The
    // target branch keeps its own base snapshots for merges where it is later
    // used as the source.
    record_cow_merge_base_snapshot(layout, runtime, shared, source, &source_db)?;
    record_cow_file_merge_base_snapshot(layout, runtime, shared, source, &source_root)?;
    invalidate_cow_git_ref(layout, target)?;
    Ok(())
}

pub struct CowMergeAuditQuery<'a> {
    pub format: &'a str,
    pub limit: &'a str,
    pub run_id: Option<&'a str>,
    pub scope: &'a str,
    pub records: &'a str,
    pub conflict_type: Option<&'a str>,
    pub conflict_id: Option<&'a str>,
    pub conflict_key: Option<&'a str>,
    pub event_type: Option<&'a str>,
    pub plugin: Option<&'a str>,
    pub plugin_object: Option<&'a str>,
    pub plugin_severity: Option<&'a str>,
    pub plugin_logical_identity: Option<&'a str>,
    pub decision: Option<&'a str>,
    pub path: Option<&'a str>,
    pub path_prefix: Option<&'a str>,
    pub id_band_skips: bool,
    pub target_kept: bool,
    pub review: bool,
    pub review_status: Option<&'a str>,
    pub resolution_status: Option<&'a str>,
    pub lifecycle_state: Option<&'a str>,
    pub next_action: Option<&'a str>,
    pub revalidation_class: Option<&'a str>,
    pub latest_revalidation_status: Option<&'a str>,
    pub stale_status: Option<&'a str>,
    pub resolution_choice: Option<&'a str>,
    pub blocked_resolution_choice: Option<&'a str>,
    pub resolution_strategy: Option<&'a str>,
    pub generic_resolver: Option<&'a str>,
    pub after_revalidate: Option<&'a str>,
    pub group_by: &'a str,
}

pub fn inspect_cow_merge_audit(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    query: CowMergeAuditQuery<'_>,
) -> Result<()> {
    let metadata_db = cow_merge_metadata_db_path(layout);
    let mut args: Vec<OsString> = vec![
        "audit".into(),
        "--metadata-db".into(),
        metadata_db.as_os_str().to_os_string(),
        "--format".into(),
        query.format.into(),
        "--limit".into(),
        query.limit.into(),
        "--scope".into(),
        query.scope.into(),
        "--records".into(),
        query.records.into(),
    ];
    if let Some(run_id) = query.run_id {
        args.push("--run".into());
        args.push(run_id.into());
    }
    if let Some(conflict_type) = query.conflict_type {
        args.push("--conflict-type".into());
        args.push(conflict_type.into());
    }
    if let Some(conflict_id) = query.conflict_id {
        args.push("--conflict-id".into());
        args.push(conflict_id.into());
    }
    if let Some(conflict_key) = query.conflict_key {
        args.push("--conflict-key".into());
        args.push(conflict_key.into());
    }
    if let Some(event_type) = query.event_type {
        args.push("--event-type".into());
        args.push(event_type.into());
    }
    if let Some(plugin) = query.plugin {
        args.push("--plugin".into());
        args.push(plugin.into());
    }
    if let Some(plugin_object) = query.plugin_object {
        args.push("--plugin-object".into());
        args.push(plugin_object.into());
    }
    if let Some(plugin_severity) = query.plugin_severity {
        args.push("--plugin-severity".into());
        args.push(plugin_severity.into());
    }
    if let Some(plugin_logical_identity) = query.plugin_logical_identity {
        args.push("--plugin-logical-identity".into());
        args.push(plugin_logical_identity.into());
    }
    if let Some(decision) = query.decision {
        args.push("--decision".into());
        args.push(decision.into());
    }
    if let Some(path) = query.path {
        args.push("--path".into());
        args.push(path.into());
    }
    if let Some(path_prefix) = query.path_prefix {
        args.push("--path-prefix".into());
        args.push(path_prefix.into());
    }
    if query.id_band_skips {
        args.push("--id-band-skips".into());
    }
    if query.target_kept {
        args.push("--target-kept".into());
    }
    if query.review {
        args.push("--review".into());
    }
    if let Some(review_status) = query.review_status {
        args.push("--review-status".into());
        args.push(review_status.into());
    }
    if let Some(resolution_status) = query.resolution_status {
        args.push("--resolution-status".into());
        args.push(resolution_status.into());
    }
    if let Some(lifecycle_state) = query.lifecycle_state {
        args.push("--lifecycle-state".into());
        args.push(lifecycle_state.into());
    }
    if let Some(next_action) = query.next_action {
        args.push("--next-action".into());
        args.push(next_action.into());
    }
    if let Some(revalidation_class) = query.revalidation_class {
        args.push("--revalidation-class".into());
        args.push(revalidation_class.into());
    }
    if let Some(latest_revalidation_status) = query.latest_revalidation_status {
        args.push("--latest-revalidation-status".into());
        args.push(latest_revalidation_status.into());
    }
    if let Some(stale_status) = query.stale_status {
        args.push("--stale-status".into());
        args.push(stale_status.into());
    }
    if let Some(resolution_choice) = query.resolution_choice {
        args.push("--resolution-choice".into());
        args.push(resolution_choice.into());
    }
    if let Some(blocked_resolution_choice) = query.blocked_resolution_choice {
        args.push("--blocked-resolution-choice".into());
        args.push(blocked_resolution_choice.into());
    }
    if let Some(resolution_strategy) = query.resolution_strategy {
        args.push("--resolution-strategy".into());
        args.push(resolution_strategy.into());
    }
    if let Some(generic_resolver) = query.generic_resolver {
        args.push("--generic-resolver".into());
        args.push(generic_resolver.into());
    }
    if let Some(after_revalidate) = query.after_revalidate {
        args.push("--after-revalidate".into());
        args.push(after_revalidate.into());
    }
    if query.group_by != "none" {
        args.push("--group-by".into());
        args.push(query.group_by.into());
    }
    run_php_script(
        layout,
        runtime,
        shared,
        "scripts/cow/merge.php",
        args.iter().map(|arg| arg.as_os_str()),
    )
}

pub fn recover_cow_merge_crash(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    run_id: Option<&str>,
    restore_target_db: bool,
    restore_files: bool,
    format: &str,
) -> Result<()> {
    let metadata_db = cow_merge_metadata_db_path(layout);
    let mut args: Vec<OsString> = vec![
        "recover-crash".into(),
        "--metadata-db".into(),
        metadata_db.as_os_str().to_os_string(),
        "--format".into(),
        format.into(),
    ];
    if let Some(run_id) = run_id {
        args.push("--run".into());
        args.push(run_id.into());
    }
    if restore_target_db {
        args.push("--restore-target-db".into());
    }
    if restore_files {
        args.push("--restore-files".into());
    }
    run_php_script(
        layout,
        runtime,
        shared,
        "scripts/cow/merge.php",
        args.iter().map(|arg| arg.as_os_str()),
    )
}

pub fn revalidate_cow_merge_reviews(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    run_id: Option<&str>,
    conflict_id: Option<&str>,
    conflict_key: Option<&str>,
    reviewer: Option<&str>,
    format: &str,
    quiet: bool,
) -> Result<()> {
    let metadata_db = cow_merge_metadata_db_path(layout);
    let mut args: Vec<OsString> = vec![
        "revalidate-reviews".into(),
        "--metadata-db".into(),
        metadata_db.as_os_str().to_os_string(),
        "--format".into(),
        format.into(),
    ];
    if let Some(run_id) = run_id {
        args.push("--run".into());
        args.push(run_id.into());
    }
    if let Some(conflict_id) = conflict_id {
        args.push("--conflict-id".into());
        args.push(conflict_id.into());
    }
    if let Some(conflict_key) = conflict_key {
        args.push("--conflict-key".into());
        args.push(conflict_key.into());
    }
    if let Some(reviewer) = reviewer {
        args.push("--reviewer".into());
        args.push(reviewer.into());
    }
    if quiet {
        args.push("--quiet".into());
    }
    run_php_script(
        layout,
        runtime,
        shared,
        "scripts/cow/merge.php",
        args.iter().map(|arg| arg.as_os_str()),
    )
}

pub fn record_cow_plugin_validator_conflicts(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    run_id: &str,
    findings_json: Option<&str>,
    findings_file: Option<&Path>,
    format: &str,
) -> Result<()> {
    let metadata_db = cow_merge_metadata_db_path(layout);
    let mut args: Vec<OsString> = vec![
        "record-plugin-validator-conflicts".into(),
        "--metadata-db".into(),
        metadata_db.as_os_str().to_os_string(),
        "--run".into(),
        run_id.into(),
        "--format".into(),
        format.into(),
    ];
    if let Some(findings_json) = findings_json {
        args.push("--findings-json".into());
        args.push(findings_json.into());
    }
    if let Some(findings_file) = findings_file {
        args.push("--findings-file".into());
        args.push(findings_file.as_os_str().to_os_string());
    }
    run_php_script(
        layout,
        runtime,
        shared,
        "scripts/cow/merge.php",
        args.iter().map(|arg| arg.as_os_str()),
    )
}

pub fn run_cow_plugin_validator(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    run_id: &str,
    validator: &Path,
    format: &str,
) -> Result<()> {
    let metadata_db = cow_merge_metadata_db_path(layout);
    let args: Vec<OsString> = vec![
        "run-plugin-validator".into(),
        "--metadata-db".into(),
        metadata_db.as_os_str().to_os_string(),
        "--run".into(),
        run_id.into(),
        "--validator".into(),
        validator.as_os_str().to_os_string(),
        "--format".into(),
        format.into(),
    ];
    run_php_script(
        layout,
        runtime,
        shared,
        "scripts/cow/merge.php",
        args.iter().map(|arg| arg.as_os_str()),
    )
}

#[allow(clippy::too_many_arguments)]
pub fn record_cow_plugin_driver_resolution(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    conflict_id: Option<&str>,
    conflict_key: Option<&str>,
    run_id: Option<&str>,
    driver: &str,
    result_json: Option<&str>,
    result_file: Option<&Path>,
    previous_json: Option<&str>,
    previous_file: Option<&Path>,
    applied: bool,
    note: Option<&str>,
    reviewer: Option<&str>,
    format: &str,
) -> Result<()> {
    let metadata_db = cow_merge_metadata_db_path(layout);
    let mut args: Vec<OsString> = vec![
        "record-plugin-driver-resolution".into(),
        "--metadata-db".into(),
        metadata_db.as_os_str().to_os_string(),
        "--driver".into(),
        driver.into(),
        "--format".into(),
        format.into(),
    ];
    if let Some(conflict_id) = conflict_id {
        args.push("--id".into());
        args.push(conflict_id.into());
    }
    if let Some(conflict_key) = conflict_key {
        args.push("--conflict-key".into());
        args.push(conflict_key.into());
    }
    if let Some(run_id) = run_id {
        args.push("--run".into());
        args.push(run_id.into());
    }
    if let Some(result_json) = result_json {
        args.push("--result-json".into());
        args.push(result_json.into());
    }
    if let Some(result_file) = result_file {
        args.push("--result-file".into());
        args.push(result_file.as_os_str().to_os_string());
    }
    if let Some(previous_json) = previous_json {
        args.push("--previous-json".into());
        args.push(previous_json.into());
    }
    if let Some(previous_file) = previous_file {
        args.push("--previous-file".into());
        args.push(previous_file.as_os_str().to_os_string());
    }
    if applied {
        args.push("--applied".into());
    }
    if let Some(note) = note {
        args.push("--note".into());
        args.push(note.into());
    }
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

pub fn run_cow_plugin_driver(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    conflict_id: Option<&str>,
    conflict_key: Option<&str>,
    run_id: Option<&str>,
    driver: &Path,
    note: Option<&str>,
    reviewer: Option<&str>,
    format: &str,
) -> Result<()> {
    let metadata_db = cow_merge_metadata_db_path(layout);
    let mut args: Vec<OsString> = vec![
        "run-plugin-driver".into(),
        "--metadata-db".into(),
        metadata_db.as_os_str().to_os_string(),
        "--driver".into(),
        driver.as_os_str().to_os_string(),
        "--format".into(),
        format.into(),
    ];
    if let Some(conflict_id) = conflict_id {
        args.push("--id".into());
        args.push(conflict_id.into());
    }
    if let Some(conflict_key) = conflict_key {
        args.push("--conflict-key".into());
        args.push(conflict_key.into());
    }
    if let Some(run_id) = run_id {
        args.push("--run".into());
        args.push(run_id.into());
    }
    if let Some(note) = note {
        args.push("--note".into());
        args.push(note.into());
    }
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

pub fn review_cow_merge_conflict_key(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    conflict_key: &str,
    run_id: Option<&str>,
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
        "conflict".into(),
        "--conflict-key".into(),
        conflict_key.into(),
        "--status".into(),
        status.into(),
        "--note".into(),
        note.into(),
    ];
    if let Some(run_id) = run_id {
        args.push("--run".into());
        args.push(run_id.into());
    }
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

pub fn resolve_cow_merge_conflict(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    conflict_id: &str,
    choice: Option<&str>,
    apply: bool,
    apply_reviewed: bool,
    after_revalidate: bool,
    note: Option<&str>,
    reviewer: Option<&str>,
) -> Result<()> {
    let metadata_db = cow_merge_metadata_db_path(layout);
    let mut args: Vec<OsString> = vec![
        "resolve-conflict".into(),
        "--metadata-db".into(),
        metadata_db.as_os_str().to_os_string(),
        "--id".into(),
        conflict_id.into(),
    ];
    if let Some(choice) = choice {
        args.push("--choice".into());
        args.push(choice.into());
    }
    if apply {
        args.push("--apply".into());
    }
    if apply_reviewed {
        args.push("--apply-reviewed".into());
    }
    if after_revalidate {
        args.push("--after-revalidate".into());
    }
    if let Some(note) = note {
        args.push("--note".into());
        args.push(note.into());
    }
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

pub fn resolve_cow_merge_conflict_key(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    conflict_key: &str,
    run_id: Option<&str>,
    choice: Option<&str>,
    apply: bool,
    apply_reviewed: bool,
    after_revalidate: bool,
    note: Option<&str>,
    reviewer: Option<&str>,
) -> Result<()> {
    let metadata_db = cow_merge_metadata_db_path(layout);
    let mut args: Vec<OsString> = vec![
        "resolve-conflict".into(),
        "--metadata-db".into(),
        metadata_db.as_os_str().to_os_string(),
        "--conflict-key".into(),
        conflict_key.into(),
    ];
    if let Some(run_id) = run_id {
        args.push("--run".into());
        args.push(run_id.into());
    }
    if let Some(choice) = choice {
        args.push("--choice".into());
        args.push(choice.into());
    }
    if apply {
        args.push("--apply".into());
    }
    if apply_reviewed {
        args.push("--apply-reviewed".into());
    }
    if after_revalidate {
        args.push("--after-revalidate".into());
    }
    if let Some(note) = note {
        args.push("--note".into());
        args.push(note.into());
    }
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

pub fn apply_reviewed_cow_merge_resolutions(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    run_id: Option<&str>,
    limit: Option<&str>,
    note: Option<&str>,
    reviewer: Option<&str>,
    format: Option<&str>,
) -> Result<()> {
    let metadata_db = cow_merge_metadata_db_path(layout);
    let mut args: Vec<OsString> = vec![
        "apply-reviewed-resolutions".into(),
        "--metadata-db".into(),
        metadata_db.as_os_str().to_os_string(),
    ];
    if let Some(run_id) = run_id {
        args.push("--run".into());
        args.push(run_id.into());
    }
    if let Some(limit) = limit {
        args.push("--limit".into());
        args.push(limit.into());
    }
    if let Some(note) = note {
        args.push("--note".into());
        args.push(note.into());
    }
    if let Some(reviewer) = reviewer {
        args.push("--reviewer".into());
        args.push(reviewer.into());
    }
    if let Some(format) = format {
        args.push("--format".into());
        args.push(format.into());
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
    if layout.linux_xfs_branches_dir.exists() {
        roots.push(layout.linux_xfs_branches_dir.clone());
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

pub fn detach_linux_xfs_loop_file_view(
    layout: &Layout,
    force: bool,
    print_remove_site_hint: bool,
) -> Result<()> {
    detach_linux_xfs_loop_file_view_impl(layout, force, print_remove_site_hint)
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
        FileViewStrategy::LinuxXfsLoop => layout.linux_xfs_branches_dir.join(branch),
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
    match file_view {
        FileViewStrategy::MacosApfsSparsebundle => {
            ensure_mount_backed_cow_public_branch_link(
                &public_root,
                storage_root,
                "APFS sparsebundle",
            )?;
        }
        FileViewStrategy::LinuxXfsLoop => {
            ensure_mount_backed_cow_public_branch_link(&public_root, storage_root, "XFS loop")?;
        }
        FileViewStrategy::Reflink | FileViewStrategy::Copy => {}
    }
    Ok(public_root)
}

#[cfg(unix)]
fn ensure_mount_backed_cow_public_branch_link(
    public_root: &Path,
    storage_root: &Path,
    label: &str,
) -> Result<()> {
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
                "{} already exists; cannot link it to {} storage at {}",
                public_root.display(),
                label,
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

#[cfg(not(unix))]
fn ensure_mount_backed_cow_public_branch_link(
    _public_root: &Path,
    _storage_root: &Path,
    label: &str,
) -> Result<()> {
    bail!("{label} file view requires Unix symlinks")
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

#[cfg(target_os = "linux")]
fn prepare_linux_xfs_loop_file_view(
    layout: &Layout,
) -> std::result::Result<(), LinuxXfsLoopPrepareError> {
    let mut mounted_here = false;
    let result = (|| -> Result<()> {
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
        ensure_linux_xfs_loop_file_view_impl(layout, Some(&mut mounted_here))?;
        if !probe_reflink_dir(&layout.linux_xfs_branches_dir)? {
            bail!(
                "mounted Linux XFS loop volume does not support reflinks at {}",
                layout.linux_xfs_branches_dir.display()
            );
        }
        Ok(())
    })();

    result.map_err(|source| LinuxXfsLoopPrepareError {
        source,
        mounted_here,
    })
}

#[cfg(target_os = "linux")]
fn ensure_linux_xfs_loop_file_view(layout: &Layout) -> Result<()> {
    ensure_linux_xfs_loop_file_view_impl(layout, None)
}

#[cfg(target_os = "linux")]
fn ensure_linux_xfs_loop_file_view_impl(
    layout: &Layout,
    mut mounted_here: Option<&mut bool>,
) -> Result<()> {
    fs::create_dir_all(&layout.linux_xfs_dir)
        .with_context(|| format!("failed to create {}", layout.linux_xfs_dir.display()))?;
    let _lock = lock_linux_xfs_global_storage(layout)?;

    ensure_linux_xfs_image(layout)?;
    if linux_xfs_mount_info(&layout.linux_xfs_mount)?.is_none() {
        mount_linux_xfs_image(layout)?;
        if let Some(mounted_here) = &mut mounted_here {
            **mounted_here = true;
        }
    }

    fs::create_dir_all(&layout.linux_xfs_branches_dir).with_context(|| {
        format!(
            "failed to create {}",
            layout.linux_xfs_branches_dir.display()
        )
    })?;
    link_cow_branches_to_mount_backed_storage(layout, &layout.linux_xfs_branches_dir, "XFS loop")
}

#[cfg(not(target_os = "linux"))]
fn ensure_linux_xfs_loop_file_view(_layout: &Layout) -> Result<()> {
    bail!("Linux XFS loop file view is only available on Linux")
}

#[cfg(target_os = "linux")]
fn ensure_linux_xfs_image(layout: &Layout) -> Result<()> {
    if layout.linux_xfs_image.exists() {
        return Ok(());
    }

    write_sparse_gzip_template(&layout.linux_xfs_image, LINUX_XFS_REFLINK_TEMPLATE_GZ).with_context(
        || {
            format!(
                "failed to write XFS image template to {}",
                layout.linux_xfs_image.display()
            )
        },
    )
}

#[cfg(target_os = "linux")]
fn write_sparse_gzip_template(path: &Path, template_gz: &[u8]) -> Result<()> {
    let parent = path
        .parent()
        .ok_or_else(|| anyhow!("image path has no parent: {}", path.display()))?;
    fs::create_dir_all(parent).with_context(|| format!("failed to create {}", parent.display()))?;
    let tmp = parent.join(format!(
        ".forkpress-xfs-image-{}-{}.tmp",
        std::process::id(),
        SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap_or_default()
            .as_nanos()
    ));

    let result = (|| -> Result<()> {
        let mut output = OpenOptions::new()
            .write(true)
            .create_new(true)
            .open(&tmp)
            .with_context(|| format!("failed to create {}", tmp.display()))?;
        let mut input = GzDecoder::new(template_gz);
        let mut buffer = vec![0u8; 64 * 1024];
        let mut written = 0u64;
        loop {
            let len = input
                .read(&mut buffer)
                .context("failed to inflate embedded XFS image template")?;
            if len == 0 {
                break;
            }
            if buffer[..len].iter().all(|byte| *byte == 0) {
                output
                    .seek(SeekFrom::Current(len as i64))
                    .with_context(|| format!("failed to seek in {}", tmp.display()))?;
            } else {
                output
                    .write_all(&buffer[..len])
                    .with_context(|| format!("failed to write {}", tmp.display()))?;
            }
            written += len as u64;
        }
        output
            .set_len(written)
            .with_context(|| format!("failed to size {}", tmp.display()))?;
        output
            .sync_all()
            .with_context(|| format!("failed to sync {}", tmp.display()))?;
        fs::rename(&tmp, path).with_context(|| format!("failed to publish {}", path.display()))?;
        Ok(())
    })();

    if result.is_err() {
        let _ = fs::remove_file(&tmp);
    }
    result
}

#[cfg(target_os = "linux")]
fn mount_linux_xfs_image(layout: &Layout) -> Result<()> {
    fs::create_dir_all(&layout.linux_xfs_mount)
        .with_context(|| format!("failed to create {}", layout.linux_xfs_mount.display()))?;
    let loop_device = attach_linux_loop_device(&layout.linux_xfs_image)?;
    let mount_result = linux_mount_xfs_device(&loop_device.path, &layout.linux_xfs_mount);
    if let Err(err) = mount_result {
        let _ = loop_device.detach();
        return Err(err).with_context(|| {
            format!(
                "failed to mount XFS image {} at {}",
                layout.linux_xfs_image.display(),
                layout.linux_xfs_mount.display()
            )
        });
    }
    Ok(())
}

#[cfg(target_os = "linux")]
fn attach_linux_loop_device(image: &Path) -> Result<LinuxLoopDevice> {
    use std::os::fd::AsRawFd;
    use std::os::unix::fs::OpenOptionsExt;

    let control = OpenOptions::new()
        .read(true)
        .write(true)
        .custom_flags(libc::O_CLOEXEC)
        .open("/dev/loop-control")
        .context(
            "failed to open /dev/loop-control; Linux XFS loop storage requires loop-device access",
        )?;
    let number = unsafe { libc::ioctl(control.as_raw_fd(), LOOP_CTL_GET_FREE) };
    if number < 0 {
        return Err(std::io::Error::last_os_error())
            .context("failed to allocate a free loop device");
    }

    let path = PathBuf::from(format!("/dev/loop{number}"));
    let loop_file = OpenOptions::new()
        .read(true)
        .write(true)
        .custom_flags(libc::O_CLOEXEC)
        .open(&path)
        .with_context(|| format!("failed to open {}", path.display()))?;
    let image_file = OpenOptions::new()
        .read(true)
        .write(true)
        .custom_flags(libc::O_CLOEXEC)
        .open(image)
        .with_context(|| format!("failed to open {}", image.display()))?;

    let rc = unsafe { libc::ioctl(loop_file.as_raw_fd(), LOOP_SET_FD, image_file.as_raw_fd()) };
    if rc != 0 {
        return Err(std::io::Error::last_os_error()).with_context(|| {
            format!("failed to attach {} to {}", image.display(), path.display())
        });
    }

    let device = LinuxLoopDevice {
        path,
        file: loop_file,
    };
    if let Err(err) = device.set_autoclear(image) {
        let _ = device.detach();
        return Err(err);
    }
    Ok(device)
}

#[cfg(target_os = "linux")]
fn linux_mount_xfs_device(device: &Path, mount: &Path) -> Result<()> {
    use std::os::unix::ffi::OsStrExt;

    let source = CString::new(device.as_os_str().as_bytes())
        .with_context(|| format!("{} contains an interior NUL byte", device.display()))?;
    let target = CString::new(mount.as_os_str().as_bytes())
        .with_context(|| format!("{} contains an interior NUL byte", mount.display()))?;
    let fs_type = CString::new("xfs").unwrap();
    let data = CString::new("nouuid").unwrap();
    let flags = libc::MS_NOATIME;
    let rc = unsafe {
        libc::mount(
            source.as_ptr(),
            target.as_ptr(),
            fs_type.as_ptr(),
            flags,
            data.as_ptr().cast(),
        )
    };
    if rc == 0 {
        Ok(())
    } else {
        Err(std::io::Error::last_os_error()).context("mount(2) failed for XFS loop volume")
    }
}

#[cfg(target_os = "linux")]
#[derive(Debug, Clone)]
pub struct LinuxXfsMountInfo {
    pub device: String,
}

#[cfg(target_os = "linux")]
fn linux_xfs_mount_info(mount: &Path) -> Result<Option<LinuxXfsMountInfo>> {
    if !mount.exists() {
        return Ok(None);
    }

    let mount = fs::canonicalize(mount).unwrap_or_else(|_| mount.to_path_buf());
    let mountinfo = fs::read_to_string("/proc/self/mountinfo")
        .context("failed to read /proc/self/mountinfo")?;
    for line in mountinfo.lines() {
        let Some(info) = parse_linux_mountinfo_line(line) else {
            continue;
        };
        let mounted_on = PathBuf::from(info.mount_point);
        let mounted_on = fs::canonicalize(&mounted_on).unwrap_or(mounted_on);
        if mounted_on == mount && info.fs_type == "xfs" {
            return Ok(Some(LinuxXfsMountInfo {
                device: info.source,
            }));
        }
    }
    Ok(None)
}

#[cfg(target_os = "linux")]
fn parse_linux_mountinfo_line(line: &str) -> Option<ParsedLinuxMountInfo> {
    let fields: Vec<&str> = line.split_whitespace().collect();
    let separator = fields.iter().position(|field| *field == "-")?;
    if separator < 5 || fields.len() <= separator + 2 {
        return None;
    }
    Some(ParsedLinuxMountInfo {
        mount_point: unescape_linux_mountinfo_field(fields[4]),
        fs_type: fields[separator + 1].to_string(),
        source: unescape_linux_mountinfo_field(fields[separator + 2]),
    })
}

#[cfg(target_os = "linux")]
#[derive(Debug, Clone, PartialEq, Eq)]
struct ParsedLinuxMountInfo {
    mount_point: String,
    fs_type: String,
    source: String,
}

#[cfg(target_os = "linux")]
fn unescape_linux_mountinfo_field(value: &str) -> String {
    let mut out = Vec::with_capacity(value.len());
    let bytes = value.as_bytes();
    let mut index = 0;
    while index < bytes.len() {
        if bytes[index] == b'\\'
            && index + 3 < bytes.len()
            && bytes[index + 1].is_ascii_digit()
            && bytes[index + 2].is_ascii_digit()
            && bytes[index + 3].is_ascii_digit()
        {
            let decoded = (bytes[index + 1] - b'0') * 64
                + (bytes[index + 2] - b'0') * 8
                + (bytes[index + 3] - b'0');
            out.push(decoded);
            index += 4;
        } else {
            out.push(bytes[index]);
            index += 1;
        }
    }
    String::from_utf8_lossy(&out).into_owned()
}

#[cfg(target_os = "linux")]
const LINUX_XFS_REFLINK_TEMPLATE_GZ: &[u8] =
    include_bytes!("../assets/linux-xfs-reflink-template.img.gz");

#[cfg(target_os = "linux")]
const LOOP_SET_FD: libc::Ioctl = 0x4C00;
#[cfg(target_os = "linux")]
const LOOP_CLR_FD: libc::Ioctl = 0x4C01;
#[cfg(target_os = "linux")]
const LOOP_SET_STATUS64: libc::Ioctl = 0x4C04;
#[cfg(target_os = "linux")]
const LOOP_CTL_GET_FREE: libc::Ioctl = 0x4C82;
#[cfg(target_os = "linux")]
const LO_FLAGS_AUTOCLEAR: u32 = 4;

#[cfg(target_os = "linux")]
struct LinuxXfsLoopPrepareError {
    source: anyhow::Error,
    mounted_here: bool,
}

#[cfg(target_os = "linux")]
#[repr(C)]
#[derive(Clone, Copy)]
struct LoopInfo64 {
    lo_device: u64,
    lo_inode: u64,
    lo_rdevice: u64,
    lo_offset: u64,
    lo_sizelimit: u64,
    lo_number: u32,
    lo_encrypt_type: u32,
    lo_encrypt_key_size: u32,
    lo_flags: u32,
    lo_file_name: [u8; 64],
    lo_crypt_name: [u8; 64],
    lo_encrypt_key: [u8; 32],
    lo_init: [u64; 2],
}

#[cfg(target_os = "linux")]
impl Default for LoopInfo64 {
    fn default() -> Self {
        Self {
            lo_device: 0,
            lo_inode: 0,
            lo_rdevice: 0,
            lo_offset: 0,
            lo_sizelimit: 0,
            lo_number: 0,
            lo_encrypt_type: 0,
            lo_encrypt_key_size: 0,
            lo_flags: 0,
            lo_file_name: [0; 64],
            lo_crypt_name: [0; 64],
            lo_encrypt_key: [0; 32],
            lo_init: [0; 2],
        }
    }
}

#[cfg(target_os = "linux")]
struct LinuxLoopDevice {
    path: PathBuf,
    file: File,
}

#[cfg(target_os = "linux")]
impl LinuxLoopDevice {
    fn set_autoclear(&self, image: &Path) -> Result<()> {
        use std::os::fd::AsRawFd;

        let mut info = LoopInfo64 {
            lo_flags: LO_FLAGS_AUTOCLEAR,
            ..LoopInfo64::default()
        };
        copy_loop_file_name(image, &mut info.lo_file_name);
        let rc = unsafe {
            libc::ioctl(
                self.file.as_raw_fd(),
                LOOP_SET_STATUS64,
                &info as *const LoopInfo64,
            )
        };
        if rc == 0 {
            Ok(())
        } else {
            Err(std::io::Error::last_os_error())
                .with_context(|| format!("failed to set loop flags on {}", self.path.display()))
        }
    }

    fn detach(&self) -> Result<()> {
        use std::os::fd::AsRawFd;

        let rc = unsafe { libc::ioctl(self.file.as_raw_fd(), LOOP_CLR_FD) };
        if rc == 0 {
            Ok(())
        } else {
            Err(std::io::Error::last_os_error())
                .with_context(|| format!("failed to detach {}", self.path.display()))
        }
    }
}

#[cfg(target_os = "linux")]
fn copy_loop_file_name(image: &Path, out: &mut [u8; 64]) {
    use std::os::unix::ffi::OsStrExt;

    out.fill(0);
    let bytes = image.as_os_str().as_bytes();
    let len = bytes.len().min(out.len().saturating_sub(1));
    out[..len].copy_from_slice(&bytes[..len]);
}

#[cfg(target_os = "linux")]
struct LinuxXfsGlobalLock {
    file: File,
}

#[cfg(target_os = "linux")]
impl Drop for LinuxXfsGlobalLock {
    fn drop(&mut self) {
        use std::os::fd::AsRawFd;
        unsafe {
            libc::flock(self.file.as_raw_fd(), libc::LOCK_UN);
        }
    }
}

#[cfg(target_os = "linux")]
fn lock_linux_xfs_global_storage(layout: &Layout) -> Result<LinuxXfsGlobalLock> {
    use std::os::fd::AsRawFd;

    fs::create_dir_all(&layout.linux_xfs_dir)
        .with_context(|| format!("failed to create {}", layout.linux_xfs_dir.display()))?;
    let path = layout.linux_xfs_dir.join("setup.lock");
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
    Ok(LinuxXfsGlobalLock { file })
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

    let mut output = None;
    for attempt in 0..20 {
        let attempt_output = hdiutil_output([
            OsString::from("compact"),
            layout.macos_cow_image.as_os_str().to_owned(),
        ])?;
        if attempt_output.status.success() {
            output = Some(attempt_output);
            break;
        }

        let message = hdiutil_failure_message(&attempt_output);
        if !macos_hdiutil_compact_retryable_message(&message) || attempt == 19 {
            bail!("{message}");
        }
        let delay = 250 * (attempt + 1) as u64;
        std::thread::sleep(std::time::Duration::from_millis(delay.min(2_000)));
    }
    let output = output.expect("compact retry loop must return output or fail");

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

#[cfg_attr(not(target_os = "macos"), allow(dead_code))]
fn macos_hdiutil_compact_retryable_message(message: &str) -> bool {
    let message = message.to_ascii_lowercase();
    message.contains("resource temporarily unavailable") || message.contains("resource busy")
}

#[cfg(not(target_os = "macos"))]
fn compact_macos_apfs_sparsebundle_file_view_impl(_layout: &Layout) -> Result<()> {
    bail!("macOS APFS sparsebundle compact is only available on macOS")
}

#[cfg(target_os = "linux")]
fn detach_linux_xfs_loop_file_view_impl(
    layout: &Layout,
    force: bool,
    print_remove_site_hint: bool,
) -> Result<()> {
    let _lock = lock_linux_xfs_global_storage(layout)?;
    let Some(info) = linux_xfs_mount_info(&layout.linux_xfs_mount)? else {
        println!(
            "forkpress: shared Linux XFS COW storage is already detached at {}",
            layout.linux_xfs_mount.display()
        );
        println!(
            "Attach:     forkpress storage mount --work-dir {}",
            shell_quote_path(&layout.work_dir)
        );
        return Ok(());
    };

    if print_remove_site_hint {
        println!("{}", linux_xfs_remove_site_hint(layout));
    }

    unmount_linux_xfs_loop_file_view(layout, force, &info)?;

    println!(
        "forkpress: detached shared Linux XFS COW storage mounted at {}",
        layout.linux_xfs_mount.display()
    );
    println!(
        "Attach again: forkpress storage mount --work-dir {}",
        shell_quote_path(&layout.work_dir)
    );
    Ok(())
}

#[cfg(any(target_os = "linux", target_os = "macos"))]
#[cfg_attr(not(target_os = "linux"), allow(dead_code))]
fn linux_xfs_remove_site_hint(layout: &Layout) -> String {
    let mut paths = vec![layout.work_dir.clone()];
    if let Ok(branches) = cow_branch_names(layout) {
        for branch in branches {
            let public_root = cow_branch_root(layout, &branch);
            if !paths.iter().any(|path| path == &public_root) {
                paths.push(public_root);
            }
        }
    }
    if !paths.iter().any(|path| path == &layout.linux_xfs_site_dir) {
        paths.push(layout.linux_xfs_site_dir.clone());
    }

    let paths = paths
        .iter()
        .map(|path| shell_quote_path(path))
        .collect::<Vec<_>>()
        .join(" ");
    format!("Remove site before detaching shared storage:\n  rm -rf {paths}")
}

#[cfg(target_os = "linux")]
fn cleanup_failed_linux_xfs_loop_prepare(
    layout: &Layout,
    err: LinuxXfsLoopPrepareError,
) -> Result<anyhow::Error> {
    if err.mounted_here {
        let _lock = lock_linux_xfs_global_storage(layout)?;
        if let Some(info) = linux_xfs_mount_info(&layout.linux_xfs_mount)? {
            unmount_linux_xfs_loop_file_view(layout, false, &info).with_context(|| {
                format!(
                    "failed to detach partial Linux XFS COW storage at {} after setup failure",
                    layout.linux_xfs_mount.display()
                )
            })?;
        }
    }
    Ok(err.source)
}

#[cfg(target_os = "linux")]
fn unmount_linux_xfs_loop_file_view(
    layout: &Layout,
    force: bool,
    info: &LinuxXfsMountInfo,
) -> Result<()> {
    let target = CString::new(path_bytes(&layout.linux_xfs_mount)).with_context(|| {
        format!(
            "{} contains an interior NUL byte",
            layout.linux_xfs_mount.display()
        )
    })?;
    let flags = if force { libc::MNT_FORCE } else { 0 };
    let rc = unsafe { libc::umount2(target.as_ptr(), flags) };
    if rc != 0 {
        let err = std::io::Error::last_os_error();
        if err.raw_os_error() == Some(libc::EBUSY) {
            bail!(
                "shared Linux XFS COW storage is still busy at {}.\nStop other ForkPress sites using linux-xfs-loop storage, close terminals/editors using that path, or inspect open files with:\n  lsof +D {}\nThen run:\n  forkpress storage detach --work-dir {}{}",
                layout.linux_xfs_mount.display(),
                shell_quote_path(&layout.linux_xfs_mount),
                shell_quote_path(&layout.work_dir),
                if force { "" } else { " --force" }
            );
        }
        return Err(err).with_context(|| {
            format!(
                "failed to unmount shared Linux XFS COW storage {} mounted from {}",
                layout.linux_xfs_mount.display(),
                info.device
            )
        });
    }

    if linux_xfs_mount_info(&layout.linux_xfs_mount)?.is_some() {
        bail!(
            "umount2 reported success, but COW storage is still attached at {}",
            layout.linux_xfs_mount.display()
        );
    }
    Ok(())
}

#[cfg(not(target_os = "linux"))]
fn detach_linux_xfs_loop_file_view_impl(
    _layout: &Layout,
    _force: bool,
    _print_remove_site_hint: bool,
) -> Result<()> {
    bail!("Linux XFS loop detach is only available on Linux")
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
    link_cow_branches_to_mount_backed_storage(
        layout,
        &layout.macos_cow_branches_dir,
        "APFS sparsebundle",
    )
}

#[cfg(unix)]
fn link_cow_branches_to_mount_backed_storage(
    layout: &Layout,
    storage_branches_dir: &Path,
    label: &str,
) -> Result<()> {
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
            if target == storage_branches_dir {
                return Ok(());
            }
            bail!(
                "{} already points to {}; expected {}",
                layout.cow_branches_dir.display(),
                target.display(),
                storage_branches_dir.display()
            );
        }
        Ok(meta) if meta.is_dir() => {
            if !is_empty_dir(&layout.cow_branches_dir)? {
                bail!(
                    "{} already contains branch data and cannot be replaced with {} storage",
                    layout.cow_branches_dir.display(),
                    label
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

    symlink(storage_branches_dir, &layout.cow_branches_dir).with_context(|| {
        format!(
            "failed to link {} -> {}",
            layout.cow_branches_dir.display(),
            storage_branches_dir.display()
        )
    })
}

#[cfg(not(unix))]
fn link_cow_branches_to_mount_backed_storage(
    _layout: &Layout,
    _storage_branches_dir: &Path,
    label: &str,
) -> Result<()> {
    bail!("{label} file view requires Unix symlinks")
}

#[cfg_attr(not(any(target_os = "macos", target_os = "linux")), allow(dead_code))]
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

fn publish_file_atomically(tmp: &Path, dest: &Path, label: &str) -> Result<()> {
    atomic_replace_file(tmp, dest).with_context(|| {
        format!(
            "failed to publish {label} {} to {}",
            tmp.display(),
            dest.display()
        )
    })
}

#[cfg(unix)]
fn atomic_replace_file(tmp: &Path, dest: &Path) -> Result<()> {
    fs::rename(tmp, dest)?;
    Ok(())
}

#[cfg(target_os = "windows")]
fn atomic_replace_file(tmp: &Path, dest: &Path) -> Result<()> {
    let tmp_wide: Vec<u16> = tmp.as_os_str().encode_wide().chain(Some(0)).collect();
    let dest_wide: Vec<u16> = dest.as_os_str().encode_wide().chain(Some(0)).collect();
    let replaced = unsafe {
        MoveFileExW(
            tmp_wide.as_ptr(),
            dest_wide.as_ptr(),
            MOVEFILE_REPLACE_EXISTING | MOVEFILE_WRITE_THROUGH,
        )
    };
    if replaced == 0 {
        return Err(std::io::Error::last_os_error()).context("MoveFileExW failed");
    }
    Ok(())
}

#[cfg(not(any(unix, target_os = "windows")))]
fn atomic_replace_file(tmp: &Path, dest: &Path) -> Result<()> {
    if dest.exists() {
        bail!(
            "atomic replacement is not implemented for this platform and destination exists: {}",
            dest.display()
        );
    }
    fs::rename(tmp, dest)?;
    Ok(())
}

fn remove_sqlite_sidecars(db: &Path) -> Result<()> {
    for path in [
        sqlite_sidecar_path(db, "-wal"),
        sqlite_sidecar_path(db, "-shm"),
    ] {
        match fs::symlink_metadata(&path) {
            Ok(meta) if meta.is_file() => {
                fs::remove_file(&path)
                    .with_context(|| format!("failed to remove {}", path.display()))?;
            }
            Ok(_) => bail!("{} is not a regular SQLite sidecar file", path.display()),
            Err(err) if err.kind() == std::io::ErrorKind::NotFound => {}
            Err(err) => {
                return Err(err).with_context(|| format!("failed to inspect {}", path.display()));
            }
        }
    }
    Ok(())
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

fn remove_branch_path_if_ours(path: &Path) -> Result<bool> {
    match fs::symlink_metadata(path) {
        Ok(meta) if meta.file_type().is_symlink() || meta.is_file() => {
            fs::remove_file(path)
                .with_context(|| format!("failed to remove {}", path.display()))?;
            Ok(true)
        }
        Ok(meta) if meta.is_dir() => {
            fs::remove_dir_all(path)
                .with_context(|| format!("failed to remove {}", path.display()))?;
            Ok(true)
        }
        Ok(_) => bail!("{} is not a removable branch path", path.display()),
        Err(err) if err.kind() == std::io::ErrorKind::NotFound => Ok(false),
        Err(err) => Err(err).with_context(|| format!("failed to inspect {}", path.display())),
    }
}

fn cleanup_cow_branch_birth_files(
    layout: &Layout,
    branch: &str,
    staging: &Path,
    dest: &Path,
    file_view: FileViewStrategy,
    staging_published: bool,
) -> Vec<String> {
    let mut errors = Vec::new();
    if let Err(err) = remove_branch_path_if_ours(staging) {
        errors.push(err.to_string());
    }
    if staging_published {
        let public_root = cow_branch_root(layout, branch);
        if public_root != dest {
            match fs::symlink_metadata(&public_root) {
                Ok(meta) if meta.file_type().is_symlink() => match fs::read_link(&public_root) {
                    Ok(target) if target == dest => {
                        if let Err(err) = fs::remove_file(&public_root) {
                            errors
                                .push(format!("failed to remove {}: {err}", public_root.display()));
                        }
                    }
                    Ok(target) => errors.push(format!(
                        "{} points to {}; expected {}",
                        public_root.display(),
                        target.display(),
                        dest.display()
                    )),
                    Err(err) => {
                        errors.push(format!("failed to read {}: {err}", public_root.display()))
                    }
                },
                Ok(_) if file_view == FileViewStrategy::MacosApfsSparsebundle => {
                    errors.push(format!(
                        "{} is not the expected branch symlink",
                        public_root.display()
                    ));
                }
                Ok(_) => {}
                Err(err) if err.kind() == std::io::ErrorKind::NotFound => {}
                Err(err) => errors.push(format!(
                    "failed to inspect {}: {err}",
                    public_root.display()
                )),
            }
        }
        if let Err(err) = remove_branch_path_if_ours(dest) {
            errors.push(err.to_string());
        }
    }
    match cow_merge_base_db_path(layout, branch) {
        Ok(base_db) => {
            if let Err(err) = remove_sqlite_file_and_sidecars(&base_db) {
                errors.push(err.to_string());
            }
        }
        Err(err) => errors.push(err.to_string()),
    }
    match cow_merge_file_base_path(layout, branch) {
        Ok(file_base) => match fs::remove_file(&file_base) {
            Ok(()) => {}
            Err(err) if err.kind() == std::io::ErrorKind::NotFound => {}
            Err(err) => errors.push(format!("failed to remove {}: {err}", file_base.display())),
        },
        Err(err) => errors.push(err.to_string()),
    }
    errors.extend(cleanup_cow_branch_birth_temp_files(layout, branch));
    errors
}

fn cleanup_cow_branch_birth_temp_files(layout: &Layout, branch: &str) -> Vec<String> {
    let mut errors = Vec::new();
    match cow_merge_base_db_path(layout, branch) {
        Ok(base_db) => {
            if let Some(parent) = base_db.parent() {
                errors.extend(cleanup_cow_branch_birth_temp_files_in_dir(
                    parent,
                    &format!(".{branch}.merge-base-"),
                ));
            }
        }
        Err(err) => errors.push(err.to_string()),
    }
    match cow_merge_file_base_path(layout, branch) {
        Ok(file_base) => {
            if let Some(parent) = file_base.parent() {
                errors.extend(cleanup_cow_branch_birth_temp_files_in_dir(
                    parent,
                    &format!(".{branch}.file-merge-base-"),
                ));
            }
        }
        Err(err) => errors.push(err.to_string()),
    }
    errors
}

fn cleanup_cow_branch_birth_temp_files_in_dir(dir: &Path, prefix: &str) -> Vec<String> {
    let mut errors = Vec::new();
    let entries = match fs::read_dir(dir) {
        Ok(entries) => entries,
        Err(err) if err.kind() == std::io::ErrorKind::NotFound => return errors,
        Err(err) => {
            errors.push(format!("failed to read {}: {err}", dir.display()));
            return errors;
        }
    };
    for entry in entries {
        let entry = match entry {
            Ok(entry) => entry,
            Err(err) => {
                errors.push(format!("failed to read entry in {}: {err}", dir.display()));
                continue;
            }
        };
        let name = entry.file_name();
        let Some(name) = name.to_str() else {
            continue;
        };
        if !name.starts_with(prefix) {
            continue;
        }
        let path = entry.path();
        match fs::symlink_metadata(&path) {
            Ok(meta) if meta.file_type().is_symlink() || meta.is_file() => {
                if let Err(err) = fs::remove_file(&path) {
                    errors.push(format!("failed to remove {}: {err}", path.display()));
                }
            }
            Ok(_) => errors.push(format!(
                "{} is not a regular temporary branch birth artifact",
                path.display()
            )),
            Err(err) if err.kind() == std::io::ErrorKind::NotFound => {}
            Err(err) => errors.push(format!("failed to inspect {}: {err}", path.display())),
        }
    }
    errors
}

fn cleanup_failed_cow_branch_create(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    branch: &str,
    staging: &Path,
    dest: &Path,
    file_view: FileViewStrategy,
    staging_published: bool,
) -> String {
    let mut errors =
        cleanup_cow_branch_birth_files(layout, branch, staging, dest, file_view, staging_published);
    if let Err(err) = cleanup_cow_branch_birth_metadata(layout, runtime, shared, branch) {
        errors.push(err.to_string());
    }
    if errors.is_empty() {
        "rolled back branch creation artifacts".to_string()
    } else {
        format!("rollback incomplete: {}", errors.join("; "))
    }
}

fn cleanup_unpublished_cow_branch_birth_artifacts(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    branch: &str,
    staging: &Path,
    dest: &Path,
    file_view: FileViewStrategy,
) -> Result<()> {
    let mut errors =
        cleanup_cow_branch_birth_files(layout, branch, staging, dest, file_view, false);
    if let Err(err) = cleanup_cow_branch_birth_metadata(layout, runtime, shared, branch) {
        errors.push(err.to_string());
    }
    if let Err(err) = clear_cow_reset_pending(layout, branch) {
        errors.push(err.to_string());
    }
    if errors.is_empty() {
        Ok(())
    } else {
        bail!("{}", errors.join("; "))
    }
}

struct CowResetMetadataBackup {
    branch: String,
    root: PathBuf,
    metadata_db: Option<PathBuf>,
    merge_base_db: Option<PathBuf>,
    file_base: Option<PathBuf>,
}

impl CowResetMetadataBackup {
    fn restore_sqlite(backup: &Option<PathBuf>, dest: &Path, label: &str) -> Result<()> {
        remove_sqlite_file_and_sidecars(dest)?;
        if let Some(backup) = backup {
            if let Some(parent) = dest.parent() {
                fs::create_dir_all(parent)
                    .with_context(|| format!("failed to create {}", parent.display()))?;
            }
            fs::copy(backup, dest).with_context(|| {
                format!(
                    "failed to restore {label} {} from {}",
                    dest.display(),
                    backup.display()
                )
            })?;
        }
        Ok(())
    }

    fn restore_file(backup: &Option<PathBuf>, dest: &Path, label: &str) -> Result<()> {
        match fs::remove_file(dest) {
            Ok(()) => {}
            Err(err) if err.kind() == std::io::ErrorKind::NotFound => {}
            Err(err) => {
                return Err(err).with_context(|| format!("failed to remove {}", dest.display()));
            }
        }
        if let Some(backup) = backup {
            if let Some(parent) = dest.parent() {
                fs::create_dir_all(parent)
                    .with_context(|| format!("failed to create {}", parent.display()))?;
            }
            fs::copy(backup, dest).with_context(|| {
                format!(
                    "failed to restore {label} {} from {}",
                    dest.display(),
                    backup.display()
                )
            })?;
        }
        Ok(())
    }

    fn restore(&self, layout: &Layout) -> String {
        let mut errors = Vec::new();
        if let Err(err) = Self::restore_sqlite(
            &self.metadata_db,
            &cow_merge_metadata_db_path(layout),
            "metadata database",
        ) {
            errors.push(err.to_string());
        }
        match cow_merge_base_db_path(layout, &self.branch) {
            Ok(dest) => {
                if let Err(err) = Self::restore_sqlite(&self.merge_base_db, &dest, "DB merge base")
                {
                    errors.push(err.to_string());
                }
            }
            Err(err) => errors.push(err.to_string()),
        }
        match cow_merge_file_base_path(layout, &self.branch) {
            Ok(dest) => {
                if let Err(err) =
                    Self::restore_file(&self.file_base, &dest, "filesystem merge base")
                {
                    errors.push(err.to_string());
                }
            }
            Err(err) => errors.push(err.to_string()),
        };
        self.cleanup();
        if errors.is_empty() {
            "restored previous reset metadata".to_string()
        } else {
            format!("metadata rollback incomplete: {}", errors.join("; "))
        }
    }

    fn cleanup(&self) {
        if let Err(err) = fs::remove_dir_all(&self.root)
            && err.kind() != std::io::ErrorKind::NotFound
        {
            eprintln!(
                "forkpress: warning: failed to remove reset metadata backup {}: {err}",
                self.root.display()
            );
        }
    }
}

fn snapshot_optional_sqlite(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    source: &Path,
    dest: &Path,
    label: &str,
) -> Result<Option<PathBuf>> {
    if !source.is_file() {
        return Ok(None);
    }
    hot_copy_sqlite_database(layout, runtime, shared, source, dest)
        .with_context(|| format!("failed to snapshot {label} {}", source.display()))?;
    Ok(Some(dest.to_path_buf()))
}

fn snapshot_optional_file(source: &Path, dest: &Path, label: &str) -> Result<Option<PathBuf>> {
    if !source.is_file() {
        return Ok(None);
    }
    fs::copy(source, dest)
        .with_context(|| format!("failed to snapshot {label} {}", source.display()))?;
    Ok(Some(dest.to_path_buf()))
}

fn snapshot_cow_reset_metadata(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    branch: &str,
    parent: &Path,
) -> Result<CowResetMetadataBackup> {
    let root = unique_cow_operation_dir(parent, "reset-metadata-backup", branch);
    if path_exists_no_follow(&root) {
        bail!("temporary reset metadata backup path already exists");
    }
    fs::create_dir_all(&root).with_context(|| format!("failed to create {}", root.display()))?;

    let result = (|| -> Result<CowResetMetadataBackup> {
        let metadata_db = snapshot_optional_sqlite(
            layout,
            runtime,
            shared,
            &cow_merge_metadata_db_path(layout),
            &root.join("metadata.sqlite"),
            "merge metadata database",
        )?;
        let merge_base_db = snapshot_optional_sqlite(
            layout,
            runtime,
            shared,
            &cow_merge_base_db_path(layout, branch)?,
            &root.join("merge-base.sqlite"),
            "DB merge base",
        )?;
        let file_base = snapshot_optional_file(
            &cow_merge_file_base_path(layout, branch)?,
            &root.join("file-base.json"),
            "filesystem merge base",
        )?;

        Ok(CowResetMetadataBackup {
            branch: branch.to_string(),
            root: root.clone(),
            metadata_db,
            merge_base_db,
            file_base,
        })
    })();
    if result.is_err()
        && let Err(err) = fs::remove_dir_all(&root)
    {
        eprintln!(
            "forkpress: warning: failed to remove incomplete reset metadata backup {}: {err}",
            root.display()
        );
    }
    result
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

#[cfg(any(target_os = "linux", target_os = "macos"))]
fn shell_quote_path(path: &std::path::Path) -> String {
    shell_quote(&path.to_string_lossy())
}

#[cfg(any(target_os = "linux", target_os = "macos"))]
fn shell_quote(value: &str) -> String {
    if value
        .chars()
        .all(|ch| ch.is_ascii_alphanumeric() || matches!(ch, '/' | '.' | '_' | '-' | ':'))
    {
        return value.to_string();
    }
    format!("'{}'", value.replace('\'', "'\\''"))
}

#[cfg(target_os = "linux")]
fn path_bytes(path: &Path) -> &[u8] {
    use std::os::unix::ffi::OsStrExt;

    path.as_os_str().as_bytes()
}

#[cfg(test)]
mod tests {
    use super::*;

    struct CowStorageTestFailpointGuard {
        previous: Option<(String, String)>,
    }

    impl CowStorageTestFailpointGuard {
        fn set(name: &str, action: &str) -> Self {
            let previous = COW_STORAGE_TEST_FAILPOINT
                .with(|failpoint| failpoint.replace(Some((name.to_string(), action.to_string()))));
            Self { previous }
        }
    }

    impl Drop for CowStorageTestFailpointGuard {
        fn drop(&mut self) {
            COW_STORAGE_TEST_FAILPOINT.with(|failpoint| {
                failpoint.replace(self.previous.take());
            });
        }
    }

    #[test]
    fn atomic_publish_preserves_existing_destination_on_publish_failure() {
        let root = std::env::temp_dir().join(format!(
            "forkpress-atomic-publish-failure-{}-{}",
            std::process::id(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        fs::create_dir_all(&root).unwrap();
        let dest = root.join("base.sqlite");
        let missing_tmp = root.join(".base.tmp");
        fs::write(&dest, b"previous base").unwrap();

        let error = publish_file_atomically(&missing_tmp, &dest, "test snapshot")
            .unwrap_err()
            .to_string();

        assert!(error.contains("failed to publish test snapshot"));
        assert_eq!(fs::read(&dest).unwrap(), b"previous base");
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn atomic_publish_replaces_existing_destination_without_predelete() {
        let root = std::env::temp_dir().join(format!(
            "forkpress-atomic-publish-success-{}-{}",
            std::process::id(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        fs::create_dir_all(&root).unwrap();
        let dest = root.join("file-base.json");
        let tmp = root.join(".file-base.tmp.json");
        fs::write(&dest, br#"{"old":true}"#).unwrap();
        fs::write(&tmp, br#"{"new":true}"#).unwrap();

        publish_file_atomically(&tmp, &dest, "test file base").unwrap();

        assert_eq!(fs::read(&dest).unwrap(), br#"{"new":true}"#);
        assert!(!path_exists_no_follow(&tmp));
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn merge_base_sidecar_cleanup_does_not_remove_existing_main_snapshot() {
        let root = std::env::temp_dir().join(format!(
            "forkpress-atomic-merge-base-sidecars-{}-{}",
            std::process::id(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        fs::create_dir_all(&root).unwrap();
        let dest = root.join("feature.sqlite");
        let missing_tmp = root.join(".feature.merge-base.tmp.sqlite");
        fs::write(&dest, b"previous sqlite base").unwrap();
        fs::write(sqlite_sidecar_path(&dest, "-wal"), b"stale wal").unwrap();
        fs::write(sqlite_sidecar_path(&dest, "-shm"), b"stale shm").unwrap();

        remove_sqlite_sidecars(&dest).unwrap();
        let result = publish_file_atomically(&missing_tmp, &dest, "merge base snapshot");

        assert!(result.is_err());
        assert_eq!(fs::read(&dest).unwrap(), b"previous sqlite base");
        assert!(!path_exists_no_follow(&sqlite_sidecar_path(&dest, "-wal")));
        assert!(!path_exists_no_follow(&sqlite_sidecar_path(&dest, "-shm")));
        fs::remove_dir_all(root).unwrap();
    }

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
    fn branch_birth_cleanup_removes_staged_branch_and_merge_bases() {
        let root = std::env::temp_dir().join(format!(
            "forkpress-branch-birth-cleanup-{}-{}",
            std::process::id(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        let layout = Layout::new(root.join(".forkpress")).unwrap();
        let staging = root.join(".forkpress-branch-create-stage-feature");
        let dest = root.join("feature");
        fs::create_dir_all(&staging).unwrap();
        fs::write(staging.join("wp-load.php"), b"<?php\n").unwrap();
        fs::create_dir_all(&dest).unwrap();
        fs::write(dest.join("wp-load.php"), b"<?php\n").unwrap();
        let base_db = cow_merge_base_db_path(&layout, "feature").unwrap();
        fs::create_dir_all(base_db.parent().unwrap()).unwrap();
        fs::write(&base_db, b"base").unwrap();
        fs::write(sqlite_sidecar_path(&base_db, "-wal"), b"wal").unwrap();
        fs::write(sqlite_sidecar_path(&base_db, "-shm"), b"shm").unwrap();
        let temp_base = base_db
            .parent()
            .unwrap()
            .join(".feature.merge-base-test.sqlite");
        fs::write(&temp_base, b"temp base").unwrap();
        fs::write(sqlite_sidecar_path(&temp_base, "-wal"), b"temp wal").unwrap();
        let unrelated_temp_base = base_db
            .parent()
            .unwrap()
            .join(".other.merge-base-test.sqlite");
        fs::write(&unrelated_temp_base, b"other temp base").unwrap();
        let file_base = cow_merge_file_base_path(&layout, "feature").unwrap();
        fs::create_dir_all(file_base.parent().unwrap()).unwrap();
        fs::write(&file_base, b"{}").unwrap();
        let temp_file_base = file_base
            .parent()
            .unwrap()
            .join(".feature.file-merge-base-test.json");
        fs::write(&temp_file_base, b"{}").unwrap();
        let unrelated_temp_file_base = file_base
            .parent()
            .unwrap()
            .join(".other.file-merge-base-test.json");
        fs::write(&unrelated_temp_file_base, b"{}").unwrap();

        let errors = cleanup_cow_branch_birth_files(
            &layout,
            "feature",
            &staging,
            &dest,
            FileViewStrategy::Copy,
            true,
        );

        assert!(errors.is_empty(), "{errors:?}");
        assert!(!path_exists_no_follow(&staging));
        assert!(!path_exists_no_follow(&dest));
        assert!(!path_exists_no_follow(&base_db));
        assert!(!path_exists_no_follow(&sqlite_sidecar_path(
            &base_db, "-wal"
        )));
        assert!(!path_exists_no_follow(&sqlite_sidecar_path(
            &base_db, "-shm"
        )));
        assert!(!path_exists_no_follow(&file_base));
        assert!(!path_exists_no_follow(&temp_base));
        assert!(!path_exists_no_follow(&sqlite_sidecar_path(
            &temp_base, "-wal"
        )));
        assert!(!path_exists_no_follow(&temp_file_base));
        assert!(path_exists_no_follow(&unrelated_temp_base));
        assert!(path_exists_no_follow(&unrelated_temp_file_base));

        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn unpublished_branch_birth_cleanup_keeps_branch_tree_but_removes_merge_bases() {
        let root = std::env::temp_dir().join(format!(
            "forkpress-unpublished-branch-birth-cleanup-{}-{}",
            std::process::id(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        let layout = Layout::new(root.join(".forkpress")).unwrap();
        let staging = root.join(".forkpress-branch-create-stage-feature");
        let dest = root.join("feature");
        fs::create_dir_all(&dest).unwrap();
        fs::write(dest.join("wp-load.php"), b"<?php\n").unwrap();
        let base_db = cow_merge_base_db_path(&layout, "feature").unwrap();
        fs::create_dir_all(base_db.parent().unwrap()).unwrap();
        fs::write(&base_db, b"base").unwrap();
        fs::write(sqlite_sidecar_path(&base_db, "-wal"), b"wal").unwrap();
        fs::write(sqlite_sidecar_path(&base_db, "-shm"), b"shm").unwrap();
        let file_base = cow_merge_file_base_path(&layout, "feature").unwrap();
        fs::create_dir_all(file_base.parent().unwrap()).unwrap();
        fs::write(&file_base, b"{}").unwrap();

        let errors = cleanup_cow_branch_birth_files(
            &layout,
            "feature",
            &staging,
            &dest,
            FileViewStrategy::Copy,
            false,
        );

        assert!(errors.is_empty(), "{errors:?}");
        assert!(path_exists_no_follow(&dest));
        assert!(dest.join("wp-load.php").is_file());
        assert!(!path_exists_no_follow(&base_db));
        assert!(!path_exists_no_follow(&sqlite_sidecar_path(
            &base_db, "-wal"
        )));
        assert!(!path_exists_no_follow(&sqlite_sidecar_path(
            &base_db, "-shm"
        )));
        assert!(!path_exists_no_follow(&file_base));

        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn existing_branch_reuse_requires_merge_bases() {
        let root = std::env::temp_dir().join(format!(
            "forkpress-branch-reuse-metadata-{}-{}",
            std::process::id(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        let layout = Layout::new(root.join(".forkpress")).unwrap();
        let branch_root = cow_branch_root(&layout, "feature");
        fs::create_dir_all(branch_root.join("wp-content/database")).unwrap();
        fs::write(branch_root.join("wp-load.php"), b"<?php\n").unwrap();
        fs::write(
            branch_root.join("wp-content/database/.ht.sqlite"),
            b"sqlite placeholder",
        )
        .unwrap();

        let missing_base =
            validate_existing_cow_branch_birth_files(&layout, "feature", &branch_root)
                .unwrap_err()
                .to_string();
        assert!(missing_base.contains("missing its DB merge base"));

        let base_db = cow_merge_base_db_path(&layout, "feature").unwrap();
        fs::create_dir_all(base_db.parent().unwrap()).unwrap();
        fs::write(&base_db, b"base").unwrap();
        let missing_file_base =
            validate_existing_cow_branch_birth_files(&layout, "feature", &branch_root)
                .unwrap_err()
                .to_string();
        assert!(missing_file_base.contains("missing its filesystem merge base"));

        let file_base = cow_merge_file_base_path(&layout, "feature").unwrap();
        fs::create_dir_all(file_base.parent().unwrap()).unwrap();
        fs::write(&file_base, b"{}").unwrap();
        let db = validate_existing_cow_branch_birth_files(&layout, "feature", &branch_root)
            .expect("existing branch has the required birth files");
        assert_eq!(db, branch_root.join("wp-content/database/.ht.sqlite"));

        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn pending_reset_marker_blocks_branch_reuse_and_merge_guards() {
        let root = std::env::temp_dir().join(format!(
            "forkpress-pending-reset-{}-{}",
            std::process::id(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        let layout = Layout::new(root.join(".forkpress")).unwrap();

        ensure_no_pending_cow_reset(&layout, "feature").unwrap();
        write_cow_reset_pending(&layout, "feature", "main").unwrap();

        let err = ensure_no_pending_cow_reset(&layout, "feature")
            .unwrap_err()
            .to_string();
        assert!(err.contains("unfinished reset"));
        assert!(err.contains("forkpress branch reset feature --from <source>"));

        clear_cow_reset_pending(&layout, "feature").unwrap();
        write_cow_reset_pending(&layout, "feature", "main").unwrap();
        let staging = root.join(".forkpress-branch-create-stage-feature");
        let dest = root.join("feature");
        cleanup_unpublished_cow_branch_birth_artifacts(
            &layout,
            &PortableRuntime::from_layout(&layout),
            &SharedPaths {
                work_dir: layout.work_dir.clone(),
                php_bin: None,
            },
            "feature",
            &staging,
            &dest,
            FileViewStrategy::Copy,
        )
        .unwrap();
        ensure_no_pending_cow_reset(&layout, "feature").unwrap();

        write_cow_reset_pending(&layout, "feature", "main").unwrap();
        clear_cow_reset_pending_if_rollback_complete(
            &layout,
            "feature",
            &["restored previous branch contents"],
        );
        ensure_no_pending_cow_reset(&layout, "feature").unwrap();

        write_cow_reset_pending(&layout, "feature", "main").unwrap();
        clear_cow_reset_pending_if_rollback_complete(
            &layout,
            "feature",
            &["rollback incomplete: previous branch backup is missing"],
        );
        assert!(cow_reset_pending_path(&layout, "feature").unwrap().exists());
        clear_cow_reset_pending(&layout, "feature").unwrap();
        ensure_no_pending_cow_reset(&layout, "feature").unwrap();
        assert!(!cow_reset_pending_path(&layout, "feature").unwrap().exists());

        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn reset_metadata_backup_restores_previous_artifacts() {
        let root = std::env::temp_dir().join(format!(
            "forkpress-reset-metadata-backup-{}-{}",
            std::process::id(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        let layout = Layout::new(root.join(".forkpress")).unwrap();
        let branch = "feature";
        let backup_root = root.join(".forkpress-reset-metadata-backup-feature-test");
        fs::create_dir_all(&backup_root).unwrap();

        let metadata_db = cow_merge_metadata_db_path(&layout);
        fs::create_dir_all(metadata_db.parent().unwrap()).unwrap();
        fs::write(&metadata_db, b"new metadata").unwrap();
        fs::write(
            sqlite_sidecar_path(&metadata_db, "-wal"),
            b"new metadata wal",
        )
        .unwrap();
        fs::write(backup_root.join("metadata.sqlite"), b"old metadata").unwrap();

        let merge_base = cow_merge_base_db_path(&layout, branch).unwrap();
        fs::create_dir_all(merge_base.parent().unwrap()).unwrap();
        fs::write(&merge_base, b"new base").unwrap();
        fs::write(sqlite_sidecar_path(&merge_base, "-wal"), b"new base wal").unwrap();
        fs::write(backup_root.join("merge-base.sqlite"), b"old base").unwrap();

        let file_base = cow_merge_file_base_path(&layout, branch).unwrap();
        fs::create_dir_all(file_base.parent().unwrap()).unwrap();
        fs::write(&file_base, b"{\"new\":true}").unwrap();
        fs::write(backup_root.join("file-base.json"), b"{\"old\":true}").unwrap();

        let backup = CowResetMetadataBackup {
            branch: branch.to_string(),
            root: backup_root.clone(),
            metadata_db: Some(backup_root.join("metadata.sqlite")),
            merge_base_db: Some(backup_root.join("merge-base.sqlite")),
            file_base: Some(backup_root.join("file-base.json")),
        };

        let message = backup.restore(&layout);
        assert!(message.contains("restored previous reset metadata"));
        assert_eq!(fs::read(&metadata_db).unwrap(), b"old metadata");
        assert_eq!(fs::read(&merge_base).unwrap(), b"old base");
        assert_eq!(fs::read(&file_base).unwrap(), b"{\"old\":true}");
        assert!(!path_exists_no_follow(&sqlite_sidecar_path(
            &metadata_db,
            "-wal"
        )));
        assert!(!path_exists_no_follow(&sqlite_sidecar_path(
            &merge_base,
            "-wal"
        )));
        assert!(!path_exists_no_follow(&backup_root));

        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    #[cfg(unix)]
    fn reset_allocates_id_bands_against_staging_before_publish() {
        use std::os::unix::fs::PermissionsExt;

        let root = std::env::temp_dir().join(format!(
            "forkpress-reset-prepublish-bands-{}-{}",
            std::process::id(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        let layout = Layout::new(root.join(".forkpress")).unwrap();
        let runtime = PortableRuntime::from_layout(&layout);
        let fake_php = root.join("fake-php");
        let fake_php_log = root.join("fake-php.log");
        let escaped_log = fake_php_log.to_string_lossy().replace('\'', "'\\''");
        fs::create_dir_all(layout.runtime_dir.join("runtime/cow")).unwrap();
        fs::create_dir_all(layout.runtime_dir.join("scripts/shared")).unwrap();
        fs::create_dir_all(layout.runtime_dir.join("scripts/cow")).unwrap();
        fs::write(layout.runtime_dir.join("runtime/cow/bootstrap_wp.php"), b"").unwrap();
        fs::write(
            layout.runtime_dir.join("scripts/shared/sqlite_backup.php"),
            b"",
        )
        .unwrap();
        fs::write(layout.runtime_dir.join("scripts/cow/merge.php"), b"").unwrap();
        fs::write(
            &fake_php,
            format!(
                r#"#!/bin/sh
while [ "$1" = "-d" ]; do
    shift 2
done
script="$1"
shift
case "$script" in
    */sqlite_backup.php)
        cp "$1" "$2"
        ;;
    */bootstrap_wp.php)
        ;;
    */merge.php)
        command="$1"
        shift
        case "$command" in
            allocate-id-bands)
                db=""
                while [ "$#" -gt 0 ]; do
                    key="$1"
                    shift
                    if [ "$key" = "--db" ] && [ "$#" -gt 0 ]; then
                        db="$1"
                        shift
                    fi
                done
                printf 'allocate:%s\n' "$db" >> '{escaped_log}'
                case "$db" in
                    *".forkpress-reset-stage-feature-"*) ;;
                    *) exit 43 ;;
                esac
                ;;
            capture-identities)
                printf 'identities\n' >> '{escaped_log}'
                ;;
            capture-files)
                file_base=""
                while [ "$#" -gt 0 ]; do
                    key="$1"
                    shift
                    if [ "$key" = "--file-base" ] && [ "$#" -gt 0 ]; then
                        file_base="$1"
                        shift
                    fi
                done
                mkdir -p "$(dirname "$file_base")"
                printf '{{}}\n' > "$file_base"
                printf 'files:%s\n' "$file_base" >> '{escaped_log}'
                ;;
            *)
                exit 44
                ;;
        esac
        ;;
    *)
        exit 45
        ;;
esac
"#
            ),
        )
        .unwrap();
        let mut permissions = fs::metadata(&fake_php).unwrap().permissions();
        permissions.set_mode(0o755);
        fs::set_permissions(&fake_php, permissions).unwrap();

        for branch in ["main", "feature"] {
            let branch_root = cow_branch_root(&layout, branch);
            fs::create_dir_all(branch_root.join("wp-content/database")).unwrap();
            fs::write(branch_root.join("wp-load.php"), b"<?php\n").unwrap();
            fs::write(
                branch_root.join("wp-content/database/.ht.sqlite"),
                format!("{branch} db\n"),
            )
            .unwrap();
        }

        reset_cow_branch(
            &layout,
            &runtime,
            &SharedPaths {
                work_dir: layout.work_dir.clone(),
                php_bin: Some(fake_php),
            },
            "feature",
            "main",
            false,
        )
        .unwrap();

        let log = fs::read_to_string(&fake_php_log).unwrap();
        let allocate_line = log
            .lines()
            .find(|line| line.starts_with("allocate:"))
            .expect("reset allocated ID bands");
        assert!(
            allocate_line.contains(".forkpress-reset-stage-feature-"),
            "ID bands should be allocated against the private reset staging DB before publish, got {allocate_line}"
        );
        assert!(!cow_reset_pending_path(&layout, "feature").unwrap().exists());
        assert_eq!(
            fs::read_to_string(
                cow_branch_root(&layout, "feature").join("wp-content/database/.ht.sqlite")
            )
            .unwrap(),
            "main db\n"
        );

        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    #[cfg(unix)]
    fn reset_birth_metadata_failpoint_rolls_back_artifacts_before_publish() {
        use std::os::unix::fs::PermissionsExt;

        let root = std::env::temp_dir().join(format!(
            "forkpress-reset-birth-metadata-rollback-{}-{}",
            std::process::id(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        let layout = Layout::new(root.join(".forkpress")).unwrap();
        let runtime = PortableRuntime::from_layout(&layout);
        let fake_php = root.join("fake-php");
        let fake_php_log = root.join("fake-php.log");
        let escaped_log = fake_php_log.to_string_lossy().replace('\'', "'\\''");
        fs::create_dir_all(layout.runtime_dir.join("runtime/cow")).unwrap();
        fs::create_dir_all(layout.runtime_dir.join("scripts/shared")).unwrap();
        fs::create_dir_all(layout.runtime_dir.join("scripts/cow")).unwrap();
        fs::write(layout.runtime_dir.join("runtime/cow/bootstrap_wp.php"), b"").unwrap();
        fs::write(
            layout.runtime_dir.join("scripts/shared/sqlite_backup.php"),
            b"",
        )
        .unwrap();
        fs::write(layout.runtime_dir.join("scripts/cow/merge.php"), b"").unwrap();
        fs::write(
            &fake_php,
            format!(
                r#"#!/bin/sh
while [ "$1" = "-d" ]; do
    shift 2
done
script="$1"
shift
case "$script" in
    */sqlite_backup.php)
        cp "$1" "$2"
        ;;
    */bootstrap_wp.php)
        ;;
    */merge.php)
        command="$1"
        shift
        case "$command" in
            allocate-id-bands)
                db=""
                metadata_db=""
                while [ "$#" -gt 0 ]; do
                    key="$1"
                    shift
                    case "$key" in
                        --db)
                            db="$1"
                            shift
                            ;;
                        --metadata-db)
                            metadata_db="$1"
                            shift
                            ;;
                    esac
                done
                printf 'allocate:%s\n' "$db" >> '{escaped_log}'
                case "$db" in
                    *".forkpress-reset-stage-feature-"*) ;;
                    *) exit 43 ;;
                esac
                mkdir -p "$(dirname "$metadata_db")"
                printf 'new reset metadata\n' > "$metadata_db"
                ;;
            capture-identities)
                metadata_db=""
                while [ "$#" -gt 0 ]; do
                    key="$1"
                    shift
                    if [ "$key" = "--metadata-db" ] && [ "$#" -gt 0 ]; then
                        metadata_db="$1"
                        shift
                    fi
                done
                printf 'identities\n' >> '{escaped_log}'
                printf 'captured identities\n' >> "$metadata_db"
                ;;
            capture-files)
                file_base=""
                while [ "$#" -gt 0 ]; do
                    key="$1"
                    shift
                    if [ "$key" = "--file-base" ] && [ "$#" -gt 0 ]; then
                        file_base="$1"
                        shift
                    fi
                done
                mkdir -p "$(dirname "$file_base")"
                printf '{{"reset":true}}\n' > "$file_base"
                printf 'files:%s\n' "$file_base" >> '{escaped_log}'
                ;;
            *)
                exit 44
                ;;
        esac
        ;;
    *)
        exit 45
        ;;
esac
"#
            ),
        )
        .unwrap();
        let mut permissions = fs::metadata(&fake_php).unwrap().permissions();
        permissions.set_mode(0o755);
        fs::set_permissions(&fake_php, permissions).unwrap();

        let main_root = cow_branch_root(&layout, "main");
        let feature_root = cow_branch_root(&layout, "feature");
        for (branch, root, db_contents, marker) in [
            ("main", &main_root, "main db\n", "main published\n"),
            ("feature", &feature_root, "feature db\n", "feature old\n"),
        ] {
            fs::create_dir_all(root.join("wp-content/database")).unwrap();
            fs::write(root.join("wp-load.php"), b"<?php\n").unwrap();
            fs::write(root.join("wp-content/database/.ht.sqlite"), db_contents).unwrap();
            fs::write(root.join("wp-content/marker.txt"), marker).unwrap();
            assert!(cow_branch_root(&layout, branch).exists());
        }

        let metadata_db = cow_merge_metadata_db_path(&layout);
        fs::create_dir_all(metadata_db.parent().unwrap()).unwrap();
        fs::write(&metadata_db, b"old reset metadata\n").unwrap();
        let merge_base = cow_merge_base_db_path(&layout, "feature").unwrap();
        fs::create_dir_all(merge_base.parent().unwrap()).unwrap();
        fs::write(&merge_base, b"old feature merge base\n").unwrap();
        let file_base = cow_merge_file_base_path(&layout, "feature").unwrap();
        fs::create_dir_all(file_base.parent().unwrap()).unwrap();
        fs::write(&file_base, b"{\"old\":true}\n").unwrap();

        let err = {
            let _guard =
                CowStorageTestFailpointGuard::set("after-branch-reset-birth-metadata", "throw");
            reset_cow_branch(
                &layout,
                &runtime,
                &SharedPaths {
                    work_dir: layout.work_dir.clone(),
                    php_bin: Some(fake_php.clone()),
                },
                "feature",
                "main",
                false,
            )
            .expect_err("reset should fail at the post-birth-metadata failpoint")
        };
        let message = format!("{err:#}");
        assert!(message.contains("after-branch-reset-birth-metadata"));
        assert!(message.contains("failed to finalize COW branch reset metadata before publish"));
        assert_eq!(
            fs::read_to_string(feature_root.join("wp-content/database/.ht.sqlite")).unwrap(),
            "feature db\n"
        );
        assert_eq!(
            fs::read_to_string(feature_root.join("wp-content/marker.txt")).unwrap(),
            "feature old\n"
        );
        assert!(!cow_reset_pending_path(&layout, "feature").unwrap().exists());
        assert_eq!(fs::read(&metadata_db).unwrap(), b"old reset metadata\n");
        assert_eq!(fs::read(&merge_base).unwrap(), b"old feature merge base\n");
        assert_eq!(fs::read(&file_base).unwrap(), b"{\"old\":true}\n");

        let branch_parent = feature_root.parent().unwrap();
        let staging_leftovers = fs::read_dir(branch_parent)
            .unwrap()
            .filter_map(|entry| entry.ok())
            .filter(|entry| {
                entry
                    .file_name()
                    .to_string_lossy()
                    .starts_with(".forkpress-reset-stage-feature-")
            })
            .count();
        assert_eq!(staging_leftovers, 0);

        reset_cow_branch(
            &layout,
            &runtime,
            &SharedPaths {
                work_dir: layout.work_dir.clone(),
                php_bin: Some(fake_php),
            },
            "feature",
            "main",
            false,
        )
        .unwrap();
        assert_eq!(
            fs::read_to_string(feature_root.join("wp-content/database/.ht.sqlite")).unwrap(),
            "main db\n"
        );
        assert_eq!(
            fs::read_to_string(feature_root.join("wp-content/marker.txt")).unwrap(),
            "main published\n"
        );
        assert!(!cow_reset_pending_path(&layout, "feature").unwrap().exists());

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

    #[test]
    fn cow_file_view_storage_dir_selects_physical_mount_roots() {
        let root = std::env::temp_dir().join(format!(
            "forkpress-storage-dir-{}-{}",
            std::process::id(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        let layout = Layout::new(root.join(".forkpress")).unwrap();

        assert_eq!(
            cow_file_view_storage_dir(&layout, Some(FileViewStrategy::Reflink)),
            layout.cow_branches_dir.as_path()
        );
        assert_eq!(
            cow_file_view_storage_dir(&layout, Some(FileViewStrategy::MacosApfsSparsebundle)),
            layout.macos_cow_branches_dir.as_path()
        );
        assert_eq!(
            cow_file_view_storage_dir(&layout, Some(FileViewStrategy::LinuxXfsLoop)),
            layout.linux_xfs_branches_dir.as_path()
        );
    }

    #[test]
    #[cfg(any(target_os = "linux", target_os = "macos"))]
    fn linux_xfs_remove_site_hint_includes_hidden_site_dir() {
        let root = std::env::temp_dir().join(format!(
            "forkpress-xfs-remove-hint-{}-{}",
            std::process::id(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        let layout = Layout::new(root.join(".forkpress")).unwrap();
        let main = cow_branch_root(&layout, "main");
        fs::create_dir_all(&main).unwrap();
        fs::write(main.join("wp-load.php"), b"<?php\n").unwrap();

        let hint = linux_xfs_remove_site_hint(&layout);

        assert!(hint.contains("Remove site before detaching shared storage"));
        assert!(hint.contains(&shell_quote_path(&layout.work_dir)));
        assert!(hint.contains(&shell_quote_path(&main)));
        assert!(hint.contains(&shell_quote_path(&layout.linux_xfs_site_dir)));

        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    #[cfg(unix)]
    fn mount_backed_public_branch_link_points_to_storage_root() {
        let root = std::env::temp_dir().join(format!(
            "forkpress-storage-link-{}-{}",
            std::process::id(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        let public_root = root.join("main");
        let storage_root = root.join("storage/main");
        fs::create_dir_all(&storage_root).unwrap();

        ensure_mount_backed_cow_public_branch_link(&public_root, &storage_root, "test").unwrap();

        assert_eq!(fs::read_link(&public_root).unwrap(), storage_root);
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    #[cfg(unix)]
    fn reconcile_mount_backed_public_branch_links_recovers_orphan_storage_branch() {
        let root = std::env::temp_dir().join(format!(
            "forkpress-storage-link-reconcile-{}-{}",
            std::process::id(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        let layout = Layout::new(root.join(".forkpress")).unwrap();
        let storage_root = layout.macos_cow_branches_dir.join("feature");
        fs::create_dir_all(&storage_root).unwrap();
        fs::write(storage_root.join("wp-load.php"), b"<?php\n").unwrap();
        fs::create_dir_all(layout.macos_cow_branches_dir.join("not-a-branch")).unwrap();
        fs::create_dir_all(layout.macos_cow_branches_dir.join("missing-wp")).unwrap();

        reconcile_cow_public_branch_links(&layout, FileViewStrategy::MacosApfsSparsebundle)
            .unwrap();

        assert_eq!(
            fs::read_link(cow_branch_root(&layout, "feature")).unwrap(),
            storage_root
        );
        assert!(!path_exists_no_follow(&cow_branch_root(
            &layout,
            "not-a-branch"
        )));
        assert!(!path_exists_no_follow(&cow_branch_root(
            &layout,
            "missing-wp"
        )));
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    #[cfg(target_os = "linux")]
    fn linux_mountinfo_parser_decodes_mount_source_and_type() {
        let line = "42 23 7:1 / /tmp/forkpress\\040mount rw,nosuid - xfs /dev/loop7 rw,attr2";
        let parsed = parse_linux_mountinfo_line(line).unwrap();
        assert_eq!(parsed.mount_point, "/tmp/forkpress mount");
        assert_eq!(parsed.fs_type, "xfs");
        assert_eq!(parsed.source, "/dev/loop7");
    }

    #[test]
    #[cfg(target_os = "linux")]
    fn loop_file_name_is_nul_terminated_after_truncation() {
        let long_path = PathBuf::from(format!("/{}", "a".repeat(128)));
        let mut out = [0xff; 64];
        copy_loop_file_name(&long_path, &mut out);
        assert_eq!(out[63], 0);
        assert_eq!(out[62], b'a');
    }

    #[test]
    fn macos_sparsebundle_compact_retries_transient_hdiutil_busy_errors() {
        assert!(macos_hdiutil_compact_retryable_message(
            "hdiutil exited with status exit status: 1\nstderr:\nhdiutil: compact failed - Resource temporarily unavailable"
        ));
        assert!(macos_hdiutil_compact_retryable_message(
            "hdiutil exited with status exit status: 1\nstderr:\nhdiutil: compact failed - resource busy"
        ));
        assert!(!macos_hdiutil_compact_retryable_message(
            "hdiutil exited with status exit status: 1\nstderr:\nhdiutil: compact failed - image not recognized"
        ));
    }
}

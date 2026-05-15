use anyhow::{Context, Result, anyhow, bail};
use clap::{Args, ValueEnum};
use std::fs;
use std::path::{Path, PathBuf};

#[derive(ValueEnum, Debug, Clone, Copy, PartialEq, Eq)]
pub enum StorageStrategy {
    #[value(
        name = "cow",
        alias = "zfs",
        alias = "mac-cow",
        alias = "materialized",
        alias = "materialized-cow"
    )]
    Cow,
    #[cfg(feature = "dev-experiments")]
    #[value(alias = "sqlite", alias = "sqlite-cow")]
    Branchfs,
    #[cfg(feature = "dev-experiments")]
    #[value(alias = "redb", alias = "cas-redb")]
    Cas,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum FileViewStrategy {
    Reflink,
    MacosApfsSparsebundle,
    LinuxXfsLoop,
    Copy,
}

impl FileViewStrategy {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Reflink => "reflink",
            Self::MacosApfsSparsebundle => "macos-apfs-sparsebundle",
            Self::LinuxXfsLoop => "linux-xfs-loop",
            Self::Copy => "file-copy",
        }
    }

    pub fn from_manifest_value(value: &str) -> Result<Self> {
        match value.trim() {
            "reflink" | "clonefile" | "ficlone" => Ok(Self::Reflink),
            "macos-apfs-sparsebundle" | "apfs-sparsebundle" => Ok(Self::MacosApfsSparsebundle),
            "linux-xfs-loop" | "xfs-loop" | "linux-xfs" => Ok(Self::LinuxXfsLoop),
            "file-copy" | "copy" => Ok(Self::Copy),
            other => bail!("unknown file_view strategy in site manifest: {other}"),
        }
    }

    pub fn requires_cow(self) -> bool {
        self != Self::Copy
    }
}

impl StorageStrategy {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Cow => "cow",
            #[cfg(feature = "dev-experiments")]
            Self::Branchfs => "branchfs",
            #[cfg(feature = "dev-experiments")]
            Self::Cas => "cas",
        }
    }

    #[cfg(feature = "dev-experiments")]
    pub fn display_name(self) -> &'static str {
        match self {
            Self::Cow => "cow/materialized",
            Self::Branchfs => "branchfs/sqlite",
            Self::Cas => "cas/redb",
        }
    }

    pub fn from_manifest_value(value: &str) -> Result<Self> {
        match value.trim() {
            "cow" | "zfs" | "mac-cow" | "materialized" | "materialized-cow" => Ok(Self::Cow),
            #[cfg(feature = "dev-experiments")]
            "branchfs" | "sqlite" | "sqlite-cow" => Ok(Self::Branchfs),
            #[cfg(feature = "dev-experiments")]
            "cas" | "redb" | "cas-redb" => Ok(Self::Cas),
            #[cfg(not(feature = "dev-experiments"))]
            "branchfs" | "sqlite" | "sqlite-cow" | "cas" | "redb" | "cas-redb" => bail!(
                "storage strategy \"{}\" is experimental; use forkpress-dev to open this site",
                value.trim()
            ),
            other => bail!("unknown storage strategy in site manifest: {other}"),
        }
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct SiteManifest {
    pub strategy: StorageStrategy,
    pub file_view: Option<FileViewStrategy>,
}

impl SiteManifest {
    pub fn new(strategy: StorageStrategy) -> Self {
        Self {
            strategy,
            file_view: None,
        }
    }

    pub fn with_file_view(mut self, file_view: FileViewStrategy) -> Self {
        self.file_view = Some(file_view);
        self
    }

    pub fn parse(contents: &str) -> Result<Self> {
        let mut strategy = None;
        let mut file_view = None;
        for raw_line in contents.lines() {
            let line = raw_line.split('#').next().unwrap_or("").trim();
            if line.is_empty() {
                continue;
            }
            let Some((key, value)) = line.split_once('=') else {
                continue;
            };
            let key = key.trim();
            let value = value.trim().trim_matches('"');
            if key == "strategy" {
                strategy = Some(StorageStrategy::from_manifest_value(value)?);
            } else if key == "file_view" {
                file_view = Some(FileViewStrategy::from_manifest_value(value)?);
            }
        }

        Ok(Self {
            strategy: strategy.unwrap_or(StorageStrategy::Cow),
            file_view,
        })
    }

    pub fn render(&self) -> String {
        let mut rendered = format!(
            "# ForkPress site manifest\nversion = 1\nstrategy = \"{}\"\n",
            self.strategy.as_str()
        );
        if let Some(file_view) = self.file_view {
            rendered.push_str(&format!("file_view = \"{}\"\n", file_view.as_str()));
        }
        rendered
    }
}

#[derive(Debug, Clone)]
pub struct Layout {
    pub work_dir: PathBuf,
    pub project_dir: PathBuf,
    pub runtime_dir: PathBuf,
    pub logs_dir: PathBuf,
    pub site_manifest: PathBuf,
    pub site_fp: PathBuf,
    pub cow_dir: PathBuf,
    pub cow_branches_dir: PathBuf,
    pub cow_branch_list: PathBuf,
    pub cow_git_dir: PathBuf,
    #[cfg_attr(not(target_os = "macos"), allow(dead_code))]
    pub macos_cow_dir: PathBuf,
    #[cfg_attr(not(target_os = "macos"), allow(dead_code))]
    pub macos_cow_image: PathBuf,
    #[cfg_attr(not(target_os = "macos"), allow(dead_code))]
    pub macos_cow_mount: PathBuf,
    #[cfg_attr(not(target_os = "macos"), allow(dead_code))]
    pub macos_cow_branches_dir: PathBuf,
    #[cfg_attr(not(target_os = "linux"), allow(dead_code))]
    pub linux_xfs_dir: PathBuf,
    #[cfg_attr(not(target_os = "linux"), allow(dead_code))]
    pub linux_xfs_image: PathBuf,
    #[cfg_attr(not(target_os = "linux"), allow(dead_code))]
    pub linux_xfs_mount: PathBuf,
    #[cfg_attr(not(target_os = "linux"), allow(dead_code))]
    pub linux_xfs_site_dir: PathBuf,
    #[cfg_attr(not(target_os = "linux"), allow(dead_code))]
    pub linux_xfs_branches_dir: PathBuf,
    #[cfg(feature = "dev-experiments")]
    pub cas_dir: PathBuf,
    #[cfg(feature = "dev-experiments")]
    pub cas_store: PathBuf,
    #[cfg(feature = "dev-experiments")]
    pub cas_wp_root: PathBuf,
    #[cfg(feature = "dev-experiments")]
    pub cas_branches_dir: PathBuf,
    #[cfg(feature = "dev-experiments")]
    pub cas_branch_list: PathBuf,
    pub wp_root: PathBuf,
    pub debug_log: PathBuf,
    pub php_error_log: PathBuf,
    pub php_server_log: PathBuf,
    pub forkpress_server_log: PathBuf,
    pub server_pid_file: PathBuf,
    pub runtime_ready_marker: PathBuf,
    #[cfg(feature = "dev-experiments")]
    pub bootstrap_marker: PathBuf,
    #[cfg(feature = "dev-experiments")]
    pub managed_files_marker: PathBuf,
}

#[derive(Args, Debug, Clone)]
pub struct SharedPaths {
    #[arg(long, default_value = ".forkpress")]
    pub work_dir: PathBuf,

    #[arg(long)]
    pub php_bin: Option<PathBuf>,
}

impl Layout {
    pub fn new(work_dir: PathBuf) -> Result<Self> {
        let work_dir = absolutize(work_dir)?;
        let project_dir = work_dir
            .parent()
            .map(Path::to_path_buf)
            .unwrap_or_else(|| work_dir.clone());
        let primary_cow_dir = work_dir.join("cow");
        let legacy_cow_dir = work_dir.join("zfs");
        let legacy_primary_has_cow_data = path_exists_no_follow(&primary_cow_dir.join("branches"));
        let legacy_zfs_has_cow_data = path_exists_no_follow(&legacy_cow_dir.join("branches"));
        let (cow_dir, cow_branches_dir) = if legacy_primary_has_cow_data {
            (primary_cow_dir.clone(), primary_cow_dir.join("branches"))
        } else if legacy_zfs_has_cow_data {
            (legacy_cow_dir.clone(), legacy_cow_dir.join("branches"))
        } else {
            (primary_cow_dir.clone(), project_dir.clone())
        };
        let cow_branch_list = cow_dir.join("branches.txt");
        let cow_git_dir = cow_dir.join("git");
        let linux_xfs_dir = forkpress_data_dir(&work_dir).join("linux-xfs");
        let linux_xfs_mount = linux_xfs_dir.join("mount");
        let linux_xfs_site_dir = linux_xfs_mount
            .join("sites")
            .join(linux_xfs_site_slug(&project_dir, &work_dir));

        Ok(Self {
            project_dir,
            runtime_dir: work_dir.join("runtime"),
            logs_dir: work_dir.join("logs"),
            site_manifest: work_dir.join("site.toml"),
            site_fp: work_dir.join("site.fp"),
            cow_dir,
            cow_branches_dir,
            cow_branch_list,
            cow_git_dir,
            macos_cow_dir: work_dir.join("macos-cow"),
            macos_cow_image: work_dir.join("macos-cow/branches.sparsebundle"),
            macos_cow_mount: work_dir.join("macos-cow/mount"),
            macos_cow_branches_dir: work_dir.join("macos-cow/mount/branches"),
            linux_xfs_dir: linux_xfs_dir.clone(),
            linux_xfs_image: linux_xfs_dir.join("forkpress-branches.xfs"),
            linux_xfs_mount,
            linux_xfs_branches_dir: linux_xfs_site_dir.join("branches"),
            linux_xfs_site_dir,
            #[cfg(feature = "dev-experiments")]
            cas_dir: work_dir.join("cas"),
            #[cfg(feature = "dev-experiments")]
            cas_store: work_dir.join("cas/store.redb"),
            #[cfg(feature = "dev-experiments")]
            cas_wp_root: work_dir.join("cas/wproot"),
            #[cfg(feature = "dev-experiments")]
            cas_branches_dir: work_dir.join("cas/branches"),
            #[cfg(feature = "dev-experiments")]
            cas_branch_list: work_dir.join("cas/branches.txt"),
            wp_root: work_dir.join("wproot"),
            debug_log: work_dir.join("logs/wp-debug.log"),
            php_error_log: work_dir.join("logs/php-errors.log"),
            php_server_log: work_dir.join("logs/php-server.log"),
            forkpress_server_log: work_dir.join("logs/forkpress-server.log"),
            server_pid_file: work_dir.join("server.pid"),
            runtime_ready_marker: work_dir.join("runtime/.forkpress-runtime-ready"),
            #[cfg(feature = "dev-experiments")]
            bootstrap_marker: work_dir.join(".forkpress-bootstrap-complete"),
            #[cfg(feature = "dev-experiments")]
            managed_files_marker: work_dir.join(".forkpress-managed-files-version"),
            work_dir,
        })
    }
}

fn forkpress_data_dir(work_dir: &Path) -> PathBuf {
    if let Some(path) = non_empty_env_path("FORKPRESS_DATA_DIR") {
        return path;
    }
    if let Some(path) = non_empty_env_path("XDG_DATA_HOME") {
        return path.join("forkpress");
    }
    if let Some(path) = non_empty_env_path("HOME") {
        return path.join(".local/share/forkpress");
    }
    work_dir.join("data")
}

fn non_empty_env_path(name: &str) -> Option<PathBuf> {
    let value = std::env::var_os(name)?;
    if value.is_empty() {
        None
    } else {
        Some(PathBuf::from(value))
    }
}

fn linux_xfs_site_slug(project_dir: &Path, work_dir: &Path) -> String {
    let name = project_dir
        .file_name()
        .and_then(|name| name.to_str())
        .map(sanitize_linux_xfs_slug_name)
        .filter(|name| !name.is_empty())
        .unwrap_or_else(|| "site".to_string());
    format!(
        "{name}-{}",
        fnv1a_hex(work_dir.as_os_str().as_encoded_bytes())
    )
}

fn sanitize_linux_xfs_slug_name(value: &str) -> String {
    value
        .chars()
        .filter_map(|ch| {
            if ch.is_ascii_alphanumeric() {
                Some(ch.to_ascii_lowercase())
            } else if ch == '-' || ch == '_' {
                Some(ch)
            } else {
                None
            }
        })
        .take(40)
        .collect()
}

fn fnv1a_hex(bytes: &[u8]) -> String {
    let mut hash = 0xcbf29ce484222325u64;
    for byte in bytes {
        hash ^= u64::from(*byte);
        hash = hash.wrapping_mul(0x100000001b3);
    }
    format!("{hash:016x}")
}

pub fn read_site_manifest(layout: &Layout) -> Result<Option<SiteManifest>> {
    if !layout.site_manifest.is_file() {
        return Ok(None);
    }
    let contents = fs::read_to_string(&layout.site_manifest)
        .with_context(|| format!("failed to read {}", layout.site_manifest.display()))?;
    SiteManifest::parse(&contents)
        .with_context(|| format!("failed to parse {}", layout.site_manifest.display()))
        .map(Some)
}

pub fn write_site_manifest(layout: &Layout, manifest: SiteManifest) -> Result<()> {
    fs::create_dir_all(&layout.work_dir)?;
    fs::write(&layout.site_manifest, manifest.render())
        .with_context(|| format!("failed to write {}", layout.site_manifest.display()))
}

pub fn write_site_manifest_if_missing(layout: &Layout, manifest: SiteManifest) -> Result<()> {
    if read_site_manifest(layout)?.is_none() {
        write_site_manifest(layout, manifest)?;
    }
    Ok(())
}

pub fn initialized_storage_strategy(layout: &Layout) -> Result<Option<StorageStrategy>> {
    if let Some(manifest) = read_site_manifest(layout)? {
        return Ok(Some(manifest.strategy));
    }

    if layout.site_fp.exists() {
        #[cfg(feature = "dev-experiments")]
        return Ok(Some(StorageStrategy::Branchfs));
        #[cfg(not(feature = "dev-experiments"))]
        bail!(
            "legacy BranchFS site detected at {}; use forkpress-dev to open experimental or legacy storage",
            layout.site_fp.display()
        );
    }

    Ok(None)
}

pub fn require_initialized_strategy(layout: &Layout, command: &str) -> Result<StorageStrategy> {
    initialized_storage_strategy(layout)?.ok_or_else(|| {
        anyhow!(
            "{command}: no ForkPress site found in {}. Run `forkpress init` first.",
            layout.work_dir.display()
        )
    })
}

pub fn absolutize(path: PathBuf) -> Result<PathBuf> {
    let raw = if path.is_absolute() {
        path
    } else {
        std::env::current_dir()
            .context("failed to read current working directory")?
            .join(path)
    };

    let mut out = PathBuf::new();
    for comp in raw.components() {
        match comp {
            std::path::Component::ParentDir => {
                out.pop();
            }
            std::path::Component::CurDir => {}
            other => out.push(other.as_os_str()),
        }
    }
    Ok(out)
}

pub fn path_exists_no_follow(path: &Path) -> bool {
    match fs::symlink_metadata(path) {
        Ok(_) => true,
        Err(err) if err.kind() == std::io::ErrorKind::NotFound => false,
        Err(_) => true,
    }
}

pub fn validate_branch_name(branch: &str) -> Result<()> {
    let valid = !branch.is_empty()
        && branch.len() <= 63
        && branch
            .chars()
            .all(|ch| ch.is_ascii_alphanumeric() || ch == '_' || ch == '-');
    if !valid || reserved_branch_name(branch) {
        bail!("invalid branch name: {branch}");
    }
    Ok(())
}

fn reserved_branch_name(branch: &str) -> bool {
    matches!(
        branch.to_ascii_lowercase().as_str(),
        "www" | "admin" | "api" | "mail" | "localhost" | "wp"
    )
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::time::{SystemTime, UNIX_EPOCH};

    #[test]
    #[cfg(feature = "dev-experiments")]
    fn manifest_parses_branchfs_and_aliases() {
        let manifest = SiteManifest::parse("version = 1\nstrategy = \"sqlite\"\n").unwrap();
        assert_eq!(manifest.strategy, StorageStrategy::Branchfs);

        let manifest = SiteManifest::parse("strategy = \"sqlite-cow\"\n").unwrap();
        assert_eq!(manifest.strategy, StorageStrategy::Branchfs);
    }

    #[test]
    fn manifest_parses_cow_and_legacy_aliases() {
        let manifest = SiteManifest::parse("strategy = \"zfs\"\n").unwrap();
        assert_eq!(manifest.strategy, StorageStrategy::Cow);

        let manifest = SiteManifest::parse("strategy = \"cow\"\n").unwrap();
        assert_eq!(manifest.strategy, StorageStrategy::Cow);

        let manifest = SiteManifest::parse("strategy = \"mac-cow\"\n").unwrap();
        assert_eq!(manifest.strategy, StorageStrategy::Cow);
    }

    #[test]
    #[cfg(not(feature = "dev-experiments"))]
    fn production_manifest_rejects_experimental_strategies() {
        let err = SiteManifest::parse("strategy = \"cas\"\n").unwrap_err();
        assert!(err.to_string().contains("forkpress-dev"));

        let err = SiteManifest::parse("strategy = \"branchfs\"\n").unwrap_err();
        assert!(err.to_string().contains("forkpress-dev"));
    }

    #[test]
    fn manifest_parses_file_view() {
        let manifest =
            SiteManifest::parse("strategy = \"cow\"\nfile_view = \"macos-apfs-sparsebundle\"\n")
                .unwrap();
        assert_eq!(manifest.strategy, StorageStrategy::Cow);
        assert_eq!(
            manifest.file_view,
            Some(FileViewStrategy::MacosApfsSparsebundle)
        );

        let manifest = SiteManifest::parse("strategy = \"cow\"\nfile_view = \"copy\"\n").unwrap();
        assert_eq!(manifest.file_view, Some(FileViewStrategy::Copy));

        let manifest =
            SiteManifest::parse("strategy = \"cow\"\nfile_view = \"linux-xfs\"\n").unwrap();
        assert_eq!(manifest.file_view, Some(FileViewStrategy::LinuxXfsLoop));
    }

    #[test]
    #[cfg(feature = "dev-experiments")]
    fn manifest_parses_cas() {
        let manifest = SiteManifest::parse("strategy = \"cas\"\n").unwrap();
        assert_eq!(manifest.strategy, StorageStrategy::Cas);
        let alias = SiteManifest::parse("strategy = \"redb\"\n").unwrap();
        assert_eq!(alias.strategy, StorageStrategy::Cas);
    }

    #[test]
    fn manifest_render_round_trips() {
        let rendered = SiteManifest::new(StorageStrategy::Cow)
            .with_file_view(FileViewStrategy::Reflink)
            .render();
        let parsed = SiteManifest::parse(&rendered).unwrap();
        assert!(rendered.contains("strategy = \"cow\""));
        assert_eq!(parsed.strategy, StorageStrategy::Cow);
        assert_eq!(parsed.file_view, Some(FileViewStrategy::Reflink));
    }

    #[test]
    fn linux_xfs_site_slug_is_stable_and_ascii() {
        let slug = linux_xfs_site_slug(
            Path::new("/tmp/Client Site!"),
            Path::new("/tmp/Client Site!/.forkpress"),
        );
        assert!(slug.starts_with("clientsite-"));
        assert!(slug.chars().all(|ch| {
            ch.is_ascii_lowercase() || ch.is_ascii_digit() || ch == '-' || ch == '_'
        }));
        assert_eq!(
            slug,
            linux_xfs_site_slug(
                Path::new("/tmp/Client Site!"),
                Path::new("/tmp/Client Site!/.forkpress")
            )
        );
    }

    #[test]
    fn cow_branch_names_are_dns_label_safe() {
        assert!(validate_branch_name("feature-1").is_ok());
        assert!(validate_branch_name("agent_2").is_ok());
        assert!(validate_branch_name("").is_err());
        assert!(validate_branch_name("has.dot").is_err());
        assert!(validate_branch_name("../main").is_err());
        assert!(validate_branch_name(&"a".repeat(64)).is_err());
        for reserved in ["www", "admin", "api", "mail", "localhost", "wp"] {
            assert!(validate_branch_name(reserved).is_err());
            assert!(validate_branch_name(&reserved.to_ascii_uppercase()).is_err());
        }
    }

    #[test]
    fn layout_uses_cow_dir_for_new_sites_and_legacy_zfs_for_existing_sites() {
        let root = std::env::temp_dir().join(format!(
            "forkpress-layout-test-{}-{}",
            std::process::id(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        let new_project = root.join("new-project");
        let new_site = new_project.join(".forkpress");
        let new_layout = Layout::new(new_site.clone()).unwrap();
        assert_eq!(
            new_layout.cow_dir,
            absolutize(new_site.clone()).unwrap().join("cow")
        );
        assert_eq!(
            new_layout.project_dir,
            absolutize(new_project.clone()).unwrap()
        );
        assert_eq!(
            new_layout.cow_branches_dir,
            absolutize(new_project).unwrap()
        );
        fs::create_dir_all(new_site.join("cow")).unwrap();
        fs::write(new_site.join("cow/branches.txt"), b"main\n").unwrap();
        let new_layout_with_branch_list = Layout::new(new_site.clone()).unwrap();
        assert_eq!(
            new_layout_with_branch_list.cow_branches_dir,
            new_layout.cow_branches_dir
        );

        let legacy_cow_site = root.join("legacy-cow/.forkpress");
        fs::create_dir_all(legacy_cow_site.join("cow/branches")).unwrap();
        let legacy_cow_layout = Layout::new(legacy_cow_site.clone()).unwrap();
        assert_eq!(
            legacy_cow_layout.cow_dir,
            absolutize(legacy_cow_site.clone()).unwrap().join("cow")
        );
        assert_eq!(
            legacy_cow_layout.cow_branches_dir,
            absolutize(legacy_cow_site).unwrap().join("cow/branches")
        );

        let legacy_site = root.join("legacy-zfs/.forkpress");
        fs::create_dir_all(legacy_site.join("zfs/branches")).unwrap();
        let legacy_layout = Layout::new(legacy_site.clone()).unwrap();
        assert_eq!(
            legacy_layout.cow_dir,
            absolutize(legacy_site).unwrap().join("zfs")
        );
        assert_eq!(
            legacy_layout.cow_branches_dir,
            legacy_layout.cow_dir.join("branches")
        );

        let zfs_smoke_site = root.join("zfs-smoke-only/.forkpress");
        fs::create_dir_all(zfs_smoke_site.join("zfs")).unwrap();
        fs::write(zfs_smoke_site.join("zfs/engine-smoke.img"), b"").unwrap();
        let zfs_smoke_layout = Layout::new(zfs_smoke_site.clone()).unwrap();
        assert_eq!(
            zfs_smoke_layout.cow_dir,
            absolutize(zfs_smoke_site.clone()).unwrap().join("cow")
        );
        assert_eq!(
            zfs_smoke_layout.cow_branches_dir,
            absolutize(root.join("zfs-smoke-only")).unwrap()
        );
        let _ = fs::remove_dir_all(root);
    }
}

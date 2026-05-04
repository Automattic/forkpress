use anyhow::{Context, Result, anyhow, bail};
use clap::{ArgAction, Args, Parser, Subcommand, ValueEnum};
use flate2::read::GzDecoder;
#[cfg(target_os = "macos")]
use std::ffi::CString;
use std::ffi::{OsStr, OsString};
use std::fs::{self, File, OpenOptions};
use std::io::{Cursor, Read, Seek, SeekFrom, Write};
use std::net::{TcpStream, ToSocketAddrs};
use std::path::{Path, PathBuf};
use std::process::{Child, Command, ExitStatus, Stdio};
use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering};
use std::thread;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};
use zip::ZipArchive;

use forkpress_cas_store as cas_store;
mod zfs_engine;

const RUNTIME_BUNDLE: &[u8] = include_bytes!(env!("FORKPRESS_RUNTIME_BUNDLE"));
const RUNTIME_BUNDLE_ID: &str = env!("FORKPRESS_RUNTIME_BUNDLE_ID");
const STARTUP_WARNING_FILTER: &str = "Missing arginfo";
const SERVER_REGISTRY_FILE: &str = "servers.tsv";

#[derive(Parser, Debug)]
#[command(
    name = "forkpress",
    disable_version_flag = true,
    version,
    about = "Single-binary WordPress with git-style branching"
)]
struct Cli {
    /// Print version.
    #[arg(short = 'v', long = "version", action = ArgAction::Version)]
    _version: Option<bool>,

    #[command(subcommand)]
    command: Commands,
}

#[derive(Subcommand, Debug)]
enum Commands {
    /// Create a new ForkPress site and seed the default admin user.
    Init(InitArgs),
    /// Clone the ForkPress git remote into a local checkout.
    Clone(CloneArgs),
    /// Pull the current checkout with rebase/autostash.
    Pull(PullArgs),
    /// Start the local preview server in the foreground.
    Start(StartArgs),
    /// Start the local preview server in the background.
    Serve(StartArgs),
    /// Stop this site's server and detach mount-backed storage.
    Stop(ServerStopArgs),
    /// Start, list, and stop running ForkPress site servers.
    Server(ServerArgs),
    /// Create local worktrees for multiple agents.
    Agents(AgentsArgs),
    /// Stage, commit, and push a local checkout so it becomes previewable.
    Push(PushArgs),
    /// Alias for `push`, matching the agent workflow language.
    Commit(PushArgs),
    /// Git-compatible wrapper plus `forkpress git branch create <name>`.
    Git(GitPassthrough),
    /// Manage ForkPress branches.
    Branch(BranchPassthrough),
    /// Alias for `branch`.
    Branchctl(BranchPassthrough),
    /// Manage authentication users (add/list/remove/verify/auth-enabled).
    User(UserPassthrough),
    /// Show WordPress, PHP, server, and maintenance logs.
    Logs(LogsArgs),
    /// Inspect local ForkPress environment and storage capabilities.
    Doctor(DoctorArgs),
    /// Inspect, mount, and detach mount-backed site storage.
    Storage(StorageArgs),
    /// Consistent hot-copy of a running .fp file via SQLite VACUUM INTO.
    Backup(BackupArgs),
    /// Write a .fp file to a portable directory tree (files + SQL + manifest).
    Export(ExportArgs),
    /// Rebuild a .fp from a directory tree produced by `forkpress export`.
    Import(ImportArgs),
    /// Inspect and smoke-test the embedded ZFS engine.
    #[command(hide = true)]
    Zfs(ZfsArgs),
}

#[derive(Args, Debug, Clone)]
struct InitArgs {
    #[command(flatten)]
    shared: SharedPaths,

    /// Storage strategy for this site. Existing sites keep their initialized
    /// strategy; this flag is only used by `forkpress init`.
    #[arg(long, value_enum, default_value_t = default_storage_strategy())]
    strategy: StorageStrategy,

    /// Site title written to site_config. Defaults to "ForkPress".
    #[arg(long, default_value = "ForkPress")]
    site_title: String,

    /// Root host used in generated banners. Defaults to "wp.localhost".
    #[arg(long, default_value = "wp.localhost")]
    root_host: String,

    /// Admin password. If omitted a random password is generated and
    /// printed once to stdout.
    #[arg(long)]
    admin_password: Option<String>,
}

#[derive(Args, Debug, Clone)]
struct UserPassthrough {
    #[command(flatten)]
    shared: SharedPaths,

    #[arg(trailing_var_arg = true, allow_hyphen_values = true, action = ArgAction::Append)]
    args: Vec<String>,
}

#[derive(Args, Debug, Clone)]
struct BackupArgs {
    #[command(flatten)]
    shared: SharedPaths,
    /// Source .fp file (defaults to the site.fp in --work-dir).
    source: Option<PathBuf>,
    /// Destination .fp path (must not exist).
    dest: PathBuf,
}

#[derive(Args, Debug, Clone)]
struct ExportArgs {
    #[command(flatten)]
    shared: SharedPaths,
    /// Source .fp file (defaults to the site.fp in --work-dir).
    source: Option<PathBuf>,
    /// Output directory (created if missing; must be empty).
    output_dir: PathBuf,
}

#[derive(Args, Debug, Clone)]
struct ImportArgs {
    #[command(flatten)]
    shared: SharedPaths,
    /// Directory produced by `forkpress export`.
    input_dir: PathBuf,
    /// Destination .fp path (must not exist).
    dest: PathBuf,
}

#[derive(Args, Debug, Clone)]
struct ZfsArgs {
    #[command(subcommand)]
    command: ZfsCommand,
}

#[derive(Subcommand, Debug, Clone)]
enum ZfsCommand {
    /// Create/import/export a file-backed pool, then snapshot and clone a dataset.
    Smoke(ZfsSmokeArgs),
}

#[derive(Args, Debug, Clone)]
struct ZfsSmokeArgs {
    /// ForkPress site state directory. The smoke pool image is written under zfs/.
    #[arg(long, default_value = ".forkpress")]
    work_dir: PathBuf,

    /// Pool image size in MiB.
    #[arg(long, default_value_t = 128)]
    pool_size_mib: u64,
}

#[derive(Args, Debug, Clone)]
struct SharedPaths {
    #[arg(long, default_value = ".forkpress")]
    work_dir: PathBuf,

    #[arg(long)]
    php_bin: Option<PathBuf>,
}

#[derive(Args, Debug, Clone)]
struct LogsArgs {
    #[arg(long, default_value = ".forkpress")]
    work_dir: PathBuf,

    /// Log file to read.
    #[arg(long, value_enum, default_value_t = LogSelection::Wp)]
    file: LogSelection,

    /// Number of lines to print before exiting or following.
    #[arg(short = 'n', long, default_value_t = 80)]
    lines: usize,

    /// Keep printing new log output until interrupted.
    #[arg(short, long)]
    follow: bool,

    /// Print known log paths instead of log contents.
    #[arg(long)]
    paths: bool,
}

#[derive(Args, Debug, Clone)]
struct DoctorArgs {
    #[command(subcommand)]
    command: DoctorCommand,
}

#[derive(Subcommand, Debug, Clone)]
enum DoctorCommand {
    /// Probe branch file storage options for this machine and --work-dir.
    Storage(DoctorStorageArgs),
}

#[derive(Args, Debug, Clone)]
struct DoctorStorageArgs {
    #[command(flatten)]
    shared: SharedPaths,
}

#[derive(Args, Debug, Clone)]
struct StorageArgs {
    #[command(subcommand)]
    command: StorageCommand,
}

#[derive(Subcommand, Debug, Clone)]
enum StorageCommand {
    /// Show whether this site's mount-backed storage is attached.
    Status(StorageStatusArgs),
    /// Attach this site's mount-backed storage.
    Mount(StorageMountArgs),
    /// Detach this site's mount-backed storage so the work dir can be moved or deleted.
    Detach(StorageDetachArgs),
}

#[derive(Args, Debug, Clone)]
struct StorageStatusArgs {
    /// ForkPress site state directory.
    #[arg(long, default_value = ".forkpress")]
    work_dir: PathBuf,
}

#[derive(Args, Debug, Clone)]
struct StorageMountArgs {
    /// ForkPress site state directory.
    #[arg(long, default_value = ".forkpress")]
    work_dir: PathBuf,
}

#[derive(Args, Debug, Clone)]
struct StorageDetachArgs {
    /// ForkPress site state directory.
    #[arg(long, default_value = ".forkpress")]
    work_dir: PathBuf,

    /// Do not stop this site's ForkPress server before detaching storage.
    #[arg(long)]
    keep_server: bool,

    /// Seconds to wait when stopping the matching site server.
    #[arg(long, default_value_t = 10)]
    timeout: u64,

    /// Force detach. Use only after normal detach reports that the mount is busy.
    #[arg(long)]
    force: bool,
}

#[derive(ValueEnum, Debug, Clone, Copy, PartialEq, Eq)]
enum LogSelection {
    /// WordPress debug log. Critical errors and PHP fatals usually land here.
    Wp,
    /// PHP error_log target for the bundled server.
    #[value(alias = "php-errors")]
    Php,
    /// PHP built-in server access/output log.
    Server,
    /// ForkPress background server wrapper log.
    Forkpress,
    /// Background branch garbage-collection log.
    Gc,
    /// Every known log file.
    All,
}

#[derive(ValueEnum, Debug, Clone, Copy, PartialEq, Eq)]
enum StorageStrategy {
    /// Current single-file SQLite store: BranchFS files plus COW WordPress DB views.
    #[value(alias = "sqlite", alias = "sqlite-cow")]
    Branchfs,
    /// Experimental materialized COW store: normal branch directories with COW file views.
    #[value(
        name = "cow",
        alias = "zfs",
        alias = "mac-cow",
        alias = "materialized",
        alias = "materialized-cow"
    )]
    Cow,
    /// Experimental Redb-backed content-addressed file store with branch manifests.
    #[value(alias = "redb", alias = "cas-redb")]
    Cas,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum FileViewStrategy {
    /// Materialized directories whose file contents are cloned by the host FS.
    Reflink,
    /// macOS rootless APFS sparsebundle mounted under .forkpress.
    MacosApfsSparsebundle,
    /// Last-resort full copies.
    Copy,
}

impl FileViewStrategy {
    fn as_str(self) -> &'static str {
        match self {
            Self::Reflink => "reflink",
            Self::MacosApfsSparsebundle => "macos-apfs-sparsebundle",
            Self::Copy => "file-copy",
        }
    }

    fn from_manifest_value(value: &str) -> Result<Self> {
        match value.trim() {
            "reflink" | "clonefile" | "ficlone" => Ok(Self::Reflink),
            "macos-apfs-sparsebundle" | "apfs-sparsebundle" => Ok(Self::MacosApfsSparsebundle),
            "file-copy" | "copy" => Ok(Self::Copy),
            other => bail!("unknown file_view strategy in site manifest: {other}"),
        }
    }

    fn requires_cow(self) -> bool {
        self != Self::Copy
    }
}

impl StorageStrategy {
    fn as_str(self) -> &'static str {
        match self {
            Self::Branchfs => "branchfs",
            Self::Cow => "cow",
            Self::Cas => "cas",
        }
    }

    fn display_name(self) -> &'static str {
        match self {
            Self::Branchfs => "branchfs/sqlite",
            Self::Cow => "cow/materialized",
            Self::Cas => "cas/redb",
        }
    }

    fn from_manifest_value(value: &str) -> Result<Self> {
        match value.trim() {
            "branchfs" | "sqlite" | "sqlite-cow" => Ok(Self::Branchfs),
            "cow" | "zfs" | "mac-cow" | "materialized" | "materialized-cow" => Ok(Self::Cow),
            "cas" | "redb" | "cas-redb" => Ok(Self::Cas),
            other => bail!("unknown storage strategy in site manifest: {other}"),
        }
    }
}

fn default_storage_strategy() -> StorageStrategy {
    if cfg!(target_os = "macos") {
        StorageStrategy::Cow
    } else {
        StorageStrategy::Branchfs
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
struct SiteManifest {
    strategy: StorageStrategy,
    file_view: Option<FileViewStrategy>,
}

impl SiteManifest {
    fn new(strategy: StorageStrategy) -> Self {
        Self {
            strategy,
            file_view: None,
        }
    }

    fn with_file_view(mut self, file_view: FileViewStrategy) -> Self {
        self.file_view = Some(file_view);
        self
    }

    fn parse(contents: &str) -> Result<Self> {
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
            strategy: strategy.unwrap_or(StorageStrategy::Branchfs),
            file_view,
        })
    }

    fn render(&self) -> String {
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

#[derive(Args, Debug, Clone)]
struct CloneArgs {
    /// Git remote to clone.
    #[arg(default_value_t = default_git_remote())]
    remote: String,

    /// Directory to create for the checkout.
    #[arg(default_value = "site")]
    dir: PathBuf,

    /// Name for the configured git remote.
    #[arg(long, default_value = "origin")]
    remote_name: String,
}

#[derive(Args, Debug, Clone)]
struct PullArgs {
    /// Existing git checkout or worktree.
    #[arg(default_value = ".")]
    repo: PathBuf,
}

#[derive(Args, Debug, Clone)]
struct AgentsArgs {
    #[command(flatten)]
    shared: SharedPaths,

    /// Git remote, e.g. http://wp.localhost:18080/site.git.
    #[arg(long, default_value_t = default_git_remote())]
    remote: String,

    /// Directory that will hold the main checkout and agent worktrees.
    #[arg(default_value = "forkpress-agents")]
    dir: PathBuf,

    /// Number of agent branches/worktrees to create.
    #[arg(long, default_value_t = 10)]
    count: usize,

    /// Branch name prefix. Branches are named `<prefix>-1`, `<prefix>-2`, etc.
    #[arg(long, default_value = "agent")]
    prefix: String,

    /// Parent ForkPress branch to fork from.
    #[arg(long, default_value = "main")]
    from: String,

    /// Name for the configured git remote.
    #[arg(long, default_value = "origin")]
    remote_name: String,

    /// ForkPress user for local branch creation when auth is enabled.
    #[arg(long)]
    user: Option<String>,

    /// ForkPress password for local branch creation when auth is enabled.
    #[arg(long)]
    password: Option<String>,
}

#[derive(Args, Debug, Clone)]
struct PushArgs {
    /// Existing git checkout or worktree.
    #[arg(default_value = ".")]
    repo: PathBuf,

    /// Commit message. Defaults to `forkpress: update <branch>`.
    #[arg(short, long)]
    message: Option<String>,

    /// Remote name to push to.
    #[arg(long, default_value = "origin")]
    remote_name: String,
}

#[derive(Args, Debug, Clone)]
struct GitPassthrough {
    #[command(flatten)]
    shared: SharedPaths,

    #[arg(trailing_var_arg = true, allow_hyphen_values = true, action = ArgAction::Append)]
    args: Vec<String>,
}

#[derive(Args, Debug, Clone)]
struct StartArgs {
    #[command(flatten)]
    shared: SharedPaths,

    #[arg(long, default_value = "127.0.0.1")]
    host: String,

    #[arg(long, default_value_t = 18080)]
    port: u16,

    #[arg(long, default_value = "wp.localhost")]
    root_host: String,

    #[arg(long, default_value = "ForkPress")]
    site_title: String,

    /// Number of concurrent PHP workers (PHP_CLI_SERVER_WORKERS).
    /// Defaults to min(8, num_cpus * 2). Pass --workers 1 to force
    /// single-worker mode (useful for debugging; the env var is then
    /// left unset so PHP keeps its traditional single-request loop).
    /// Linux / macOS only — ignored on Windows.
    #[arg(long)]
    workers: Option<usize>,

    /// Run `branchctl gc` on a recurring interval while the server is up.
    /// Accepts `<N>s`, `<N>m`, or `<N>h` (e.g. `--gc-interval 1h`,
    /// `--gc-interval 300s`). Omit, pass `0`, or pass an invalid value to
    /// disable. Inline GC on branch delete still runs regardless.
    #[arg(long)]
    gc_interval: Option<String>,

    /// Start the site server in the background and return after it is ready.
    #[arg(long)]
    background: bool,

    /// Keep `forkpress serve` in the foreground.
    #[arg(long, conflicts_with = "background")]
    foreground: bool,
}

#[derive(Args, Debug, Clone)]
struct ServerArgs {
    #[command(subcommand)]
    command: ServerCommand,
}

#[derive(Subcommand, Debug, Clone)]
enum ServerCommand {
    /// Start this site's server in the background.
    Start(StartArgs),
    /// List running ForkPress site servers started by this user.
    List,
    /// Stop the server for --work-dir, a specific --pid, or every known server.
    Stop(ServerStopArgs),
}

#[derive(Args, Debug, Clone)]
struct ServerStopArgs {
    /// Site state directory whose server should be stopped.
    #[arg(long, default_value = ".forkpress")]
    work_dir: PathBuf,

    /// Stop every running ForkPress site server in the registry.
    #[arg(long)]
    all: bool,

    /// Stop a specific ForkPress server process.
    #[arg(long)]
    pid: Option<u32>,

    /// Seconds to wait for graceful shutdown before forcing the process down.
    #[arg(long, default_value_t = 10)]
    timeout: u64,

    /// Force detach of mount-backed storage after stopping the server.
    #[arg(long)]
    force: bool,
}

/// Parse a duration string in one of `<N>s`, `<N>m`, `<N>h`. Returns `None`
/// for invalid input, for a missing suffix, or for a value that resolves to
/// zero (the caller treats `None` as "feature disabled", so `--gc-interval 0`
/// is equivalent to not passing the flag). Compound forms like `1h30m` are
/// NOT supported — the user-facing docs promise only a single-unit suffix.
fn parse_duration(s: &str) -> Option<Duration> {
    let s = s.trim();
    if s.is_empty() {
        return None;
    }
    // Plain "0" → disabled (keeps the CLI ergonomic: pass `0` to turn off).
    if s == "0" {
        return None;
    }
    let (num_part, unit_secs) = if let Some(rest) = s.strip_suffix('h') {
        (rest, 3600u64)
    } else if let Some(rest) = s.strip_suffix('m') {
        (rest, 60u64)
    } else if let Some(rest) = s.strip_suffix('s') {
        (rest, 1u64)
    } else {
        return None;
    };
    let n: u64 = num_part.trim().parse().ok()?;
    if n == 0 {
        return None;
    }
    let total = n.checked_mul(unit_secs)?;
    Some(Duration::from_secs(total))
}

#[cfg(test)]
mod duration_tests {
    use super::*;

    #[test]
    fn parses_hours() {
        assert_eq!(parse_duration("1h"), Some(Duration::from_secs(3600)));
        assert_eq!(parse_duration("24h"), Some(Duration::from_secs(86400)));
    }

    #[test]
    fn parses_minutes() {
        assert_eq!(parse_duration("10m"), Some(Duration::from_secs(600)));
        assert_eq!(parse_duration("90m"), Some(Duration::from_secs(5400)));
    }

    #[test]
    fn parses_seconds() {
        assert_eq!(parse_duration("90s"), Some(Duration::from_secs(90)));
        assert_eq!(parse_duration("1s"), Some(Duration::from_secs(1)));
    }

    #[test]
    fn zero_is_disabled() {
        assert_eq!(parse_duration("0"), None);
        assert_eq!(parse_duration("0s"), None);
        assert_eq!(parse_duration("0h"), None);
    }

    #[test]
    fn invalid_returns_none() {
        assert_eq!(parse_duration(""), None);
        assert_eq!(parse_duration("bogus"), None);
        assert_eq!(parse_duration("h"), None);
        assert_eq!(parse_duration("10"), None); // no suffix
        assert_eq!(parse_duration("1d"), None); // unsupported unit
        assert_eq!(parse_duration("1h30m"), None); // compound not supported
        assert_eq!(parse_duration("-5s"), None);
    }
}

/// Default PHP worker count: min(8, num_cpus * 2). Capped so we don't spawn
/// 32+ PHP processes on a big CI box for no benefit — WordPress request
/// handling is bounded by SQLite write contention long before CPU.
fn default_worker_count() -> usize {
    std::cmp::min(8, num_cpus::get().saturating_mul(2))
}

#[derive(Args, Debug, Clone)]
struct BranchPassthrough {
    #[command(flatten)]
    shared: SharedPaths,

    #[arg(trailing_var_arg = true, allow_hyphen_values = true, action = ArgAction::Append)]
    args: Vec<String>,
}

#[derive(Debug, Clone)]
struct Layout {
    work_dir: PathBuf,
    project_dir: PathBuf,
    runtime_dir: PathBuf,
    logs_dir: PathBuf,
    site_manifest: PathBuf,
    site_fp: PathBuf,
    cow_dir: PathBuf,
    cow_branches_dir: PathBuf,
    cow_branch_list: PathBuf,
    cow_git_dir: PathBuf,
    #[cfg_attr(not(target_os = "macos"), allow(dead_code))]
    macos_cow_dir: PathBuf,
    #[cfg_attr(not(target_os = "macos"), allow(dead_code))]
    macos_cow_image: PathBuf,
    #[cfg_attr(not(target_os = "macos"), allow(dead_code))]
    macos_cow_mount: PathBuf,
    #[cfg_attr(not(target_os = "macos"), allow(dead_code))]
    macos_cow_branches_dir: PathBuf,
    cas_dir: PathBuf,
    cas_store: PathBuf,
    cas_wp_root: PathBuf,
    cas_branches_dir: PathBuf,
    cas_branch_list: PathBuf,
    wp_root: PathBuf,
    debug_log: PathBuf,
    php_error_log: PathBuf,
    php_server_log: PathBuf,
    forkpress_server_log: PathBuf,
    server_pid_file: PathBuf,
    runtime_ready_marker: PathBuf,
    bootstrap_marker: PathBuf,
    managed_files_marker: PathBuf,
}

#[derive(Debug, Clone)]
struct PortableRuntime {
    php: PathBuf,
}

struct ChildGuard {
    name: &'static str,
    child: Child,
}

#[derive(Debug, Clone, PartialEq, Eq)]
struct ServerRecord {
    pid: u32,
    child_pid: Option<u32>,
    work_dir: PathBuf,
    host: String,
    port: u16,
    root_host: String,
    log: PathBuf,
}

struct ServerRegistrationGuard {
    pid: u32,
    layout: Layout,
}

impl ChildGuard {
    fn id(&self) -> u32 {
        self.child.id()
    }

    fn try_wait(&mut self) -> Result<Option<ExitStatus>> {
        self.child
            .try_wait()
            .with_context(|| format!("failed to poll {}", self.name))
    }
}

impl Drop for ChildGuard {
    fn drop(&mut self) {
        if self.child.try_wait().ok().flatten().is_none() {
            let _ = self.child.kill();
            let _ = self.child.wait();
        }
    }
}

fn main() {
    let code = match run() {
        Ok(code) => code,
        Err(err) => {
            eprintln!("forkpress: {err:#}");
            1
        }
    };
    std::process::exit(code);
}

fn run() -> Result<i32> {
    let cli = Cli::parse();
    match cli.command {
        Commands::Init(args) => init_command(args),
        Commands::Clone(args) => clone_command(args),
        Commands::Pull(args) => pull_command(args),
        Commands::Start(args) => start_command(args),
        Commands::Serve(args) => serve_command(args),
        Commands::Stop(args) => server_stop_command(args),
        Commands::Server(args) => server_command(args),
        Commands::Agents(args) => agents_command(args),
        Commands::Push(args) | Commands::Commit(args) => push_command(args),
        Commands::Git(args) => git_command(args),
        Commands::Branch(args) | Commands::Branchctl(args) => branch_command(args),
        Commands::User(args) => user_command(args),
        Commands::Logs(args) => logs_command(args),
        Commands::Doctor(args) => doctor_command(args),
        Commands::Storage(args) => storage_command(args),
        Commands::Backup(args) => backup_command(args),
        Commands::Export(args) => export_command(args),
        Commands::Import(args) => import_command(args),
        Commands::Zfs(args) => zfs_command(args),
    }
}

fn clone_command(args: CloneArgs) -> Result<i32> {
    ensure_git_available()?;
    run_git(
        None,
        [
            OsString::from("clone"),
            OsString::from("--origin"),
            OsString::from(&args.remote_name),
            OsString::from(&args.remote),
            args.dir.as_os_str().to_owned(),
        ],
    )?;
    Ok(0)
}

fn pull_command(args: PullArgs) -> Result<i32> {
    ensure_git_available()?;
    let repo = absolutize(args.repo)?;
    ensure_git_repository(&repo)?;
    run_git(
        Some(&repo),
        [
            OsString::from("pull"),
            OsString::from("--rebase"),
            OsString::from("--autostash"),
        ],
    )?;
    Ok(0)
}

fn init_command(args: InitArgs) -> Result<i32> {
    let layout = Layout::new(args.shared.work_dir.clone())?;

    if initialized_storage_strategy(&layout)?.is_some() {
        bail!(
            "init: a ForkPress site already exists in {}. Remove it or choose a different --work-dir.",
            layout.work_dir.display()
        );
    }

    match args.strategy {
        StorageStrategy::Branchfs => {
            prepare_runtime(&layout)?;
            let runtime = PortableRuntime::from_layout(&layout);
            init_branchfs_site(args, layout, runtime)
        }
        StorageStrategy::Cow => init_cow_site(args, layout),
        StorageStrategy::Cas => init_cas_site(args, layout),
    }
}

fn init_branchfs_site(args: InitArgs, layout: Layout, runtime: PortableRuntime) -> Result<i32> {
    let mut script_args: Vec<std::ffi::OsString> = vec![layout.site_fp.as_os_str().to_owned()];
    if let Some(pw) = &args.admin_password {
        script_args.push(std::ffi::OsString::from("--admin-password"));
        script_args.push(std::ffi::OsString::from(pw));
    }

    run_php_script(
        &layout,
        &runtime,
        &args.shared,
        "scripts/init_db.php",
        script_args.iter().map(|s| s.as_os_str()),
    )?;
    write_site_manifest(&layout, SiteManifest::new(StorageStrategy::Branchfs))?;

    println!(
        "forkpress: site initialised at {}",
        layout.site_fp.display()
    );
    println!("  title:     {}", args.site_title);
    println!("  root host: {}", args.root_host);
    Ok(0)
}

fn init_cow_site(args: InitArgs, layout: Layout) -> Result<i32> {
    prepare_runtime(&layout)?;
    let runtime = PortableRuntime::from_layout(&layout);
    let file_view = prepare_cow_file_view(&layout)?;
    ensure_cow_main_branch(&layout, &runtime, &args, file_view)?;
    write_site_manifest(
        &layout,
        SiteManifest::new(StorageStrategy::Cow).with_file_view(file_view),
    )?;
    write_cow_strategy_notes(&layout)?;
    write_cow_branch_list(&layout)?;

    println!(
        "forkpress: COW materialized strategy initialised in {}",
        layout.work_dir.display()
    );
    println!("  title:     {}", args.site_title);
    println!("  root host: {}", args.root_host);
    println!("  file view: {}", file_view.as_str());
    println!(
        "  status:    ready; branches are materialized under {}",
        layout.cow_branches_dir.display()
    );
    Ok(0)
}

fn init_cas_site(args: InitArgs, layout: Layout) -> Result<i32> {
    prepare_runtime(&layout)?;
    let runtime = PortableRuntime::from_layout(&layout);
    fs::create_dir_all(&layout.cas_branches_dir)
        .with_context(|| format!("failed to create {}", layout.cas_branches_dir.display()))?;
    ensure_cas_main_branch(&layout, &runtime, &args)?;
    write_site_manifest(&layout, SiteManifest::new(StorageStrategy::Cas))?;
    write_cas_notes(&layout)?;
    write_cas_branch_list(&layout)?;

    println!(
        "forkpress: cas strategy initialised in {}",
        layout.work_dir.display()
    );
    println!("  title:     {}", args.site_title);
    println!("  root host: {}", args.root_host);
    println!("  store:     {}", layout.cas_store.display());
    println!("  status:    ready; WordPress files are served lazily from Redb");
    Ok(0)
}

fn user_command(args: UserPassthrough) -> Result<i32> {
    if args.args.is_empty() {
        bail!("user requires a subcommand, e.g. `forkpress user add alice s3cret --role write`");
    }
    let layout = Layout::new(args.shared.work_dir.clone())?;
    ensure_branchfs_strategy(&layout, "user")?;
    prepare_runtime(&layout)?;
    let runtime = PortableRuntime::from_layout(&layout);

    let mut command = php_base_command(&layout, &runtime, &args.shared);
    command.arg(layout.runtime_dir.join("scripts/user_admin.php"));
    for arg in &args.args {
        command.arg(arg);
    }
    command.env("BRANCHFS_DB", &layout.site_fp);

    let output = command
        .output()
        .context("failed to run user command via bundled php")?;
    write_filtered_output(&output.stdout, &output.stderr)?;
    Ok(output.status.code().unwrap_or(1))
}

fn doctor_command(args: DoctorArgs) -> Result<i32> {
    match args.command {
        DoctorCommand::Storage(args) => doctor_storage_command(args),
    }
}

fn doctor_storage_command(args: DoctorStorageArgs) -> Result<i32> {
    let layout = Layout::new(args.shared.work_dir)?;
    let target = layout.cow_branches_dir.clone();
    fs::create_dir_all(&target)
        .with_context(|| format!("failed to create {}", target.display()))?;

    println!("ForkPress storage capability report");
    println!("  work dir:     {}", layout.work_dir.display());
    println!("  project dir:  {}", layout.project_dir.display());
    println!("  branch roots: {}", target.display());
    if let Some(manifest) = read_site_manifest(&layout)? {
        println!("  strategy:     {}", manifest.strategy.as_str());
        if let Some(file_view) = manifest.file_view {
            println!("  file view:    {}", file_view.as_str());
            if file_view == FileViewStrategy::MacosApfsSparsebundle {
                println!(
                    "  measurement:  `du` can overcount APFS clone sharing; compare `df -h {}` before/after branch creation",
                    layout.macos_cow_mount.display()
                );
            }
        }
    }

    if probe_reflink_dir(&target)? {
        println!("  reflinks:     yes");
        println!("  recommendation: materialized COW branches in place");
        return Ok(0);
    }

    println!("  reflinks:     no");

    #[cfg(target_os = "macos")]
    {
        println!("  macOS APFS sparsebundle: available through hdiutil");
        println!(
            "  recommendation: forkpress init --strategy cow will create {} and mount it at {}",
            layout.macos_cow_image.display(),
            layout.macos_cow_mount.display()
        );
    }

    #[cfg(not(target_os = "macos"))]
    {
        println!("  recommendation: no platform-specific COW fallback implemented yet");
        println!("  final fallback: file-copy materialization");
    }

    Ok(0)
}

fn storage_command(args: StorageArgs) -> Result<i32> {
    match args.command {
        StorageCommand::Status(args) => storage_status_command(args),
        StorageCommand::Mount(args) => storage_mount_command(args),
        StorageCommand::Detach(args) => storage_detach_command(args),
    }
}

fn storage_status_command(args: StorageStatusArgs) -> Result<i32> {
    let layout = Layout::new(args.work_dir)?;

    println!("ForkPress storage status");
    println!("  work dir:  {}", layout.work_dir.display());

    let manifest = read_site_manifest(&layout)?;
    if let Some(manifest) = &manifest {
        println!("  strategy:  {}", manifest.strategy.as_str());
        if let Some(file_view) = manifest.file_view {
            println!("  file view: {}", file_view.as_str());
        }
    } else {
        println!("  site:      not initialized");
    }

    match manifest.as_ref().and_then(|manifest| manifest.file_view) {
        Some(FileViewStrategy::MacosApfsSparsebundle) => {
            print_macos_cow_storage_status(&layout)?;
        }
        _ => {
            if layout.macos_cow_image.exists() || layout.macos_cow_mount.exists() {
                print_macos_cow_storage_status(&layout)?;
            } else {
                println!("  mount:     none");
            }
        }
    }

    Ok(0)
}

fn storage_mount_command(args: StorageMountArgs) -> Result<i32> {
    let layout = Layout::new(args.work_dir)?;
    let strategy = require_initialized_strategy(&layout, "storage mount")?;
    if strategy != StorageStrategy::Cow {
        println!(
            "forkpress: no mount-backed storage for strategy = \"{}\"",
            strategy.as_str()
        );
        return Ok(0);
    }

    let file_view = ensure_cow_file_view_ready(&layout)?;
    match file_view {
        FileViewStrategy::MacosApfsSparsebundle => {
            ensure_macos_apfs_sparsebundle_file_view(&layout)?;
            println!(
                "forkpress: COW storage mounted at {}",
                layout.macos_cow_mount.display()
            );
            println!("Branch roots: {}", layout.cow_branches_dir.display());
        }
        FileViewStrategy::Reflink | FileViewStrategy::Copy => {
            println!(
                "forkpress: storage file view \"{}\" does not use a detachable mount",
                file_view.as_str()
            );
        }
    }

    Ok(0)
}

fn storage_detach_command(args: StorageDetachArgs) -> Result<i32> {
    let layout = Layout::new(args.work_dir)?;

    if !args.keep_server {
        if let Some(record) = running_record_for_work_dir(&layout.work_dir)? {
            stop_server_record(&record, Duration::from_secs(args.timeout))?;
        }
    }

    if !detach_storage_for_layout_if_present(&layout, args.force)? {
        println!(
            "forkpress: no detachable storage found for {}",
            layout.work_dir.display()
        );
    }

    Ok(0)
}

fn detach_storage_for_layout_if_present(layout: &Layout, force: bool) -> Result<bool> {
    let manifest = read_site_manifest(layout)?;
    let has_macos_cow = manifest.as_ref().and_then(|manifest| manifest.file_view)
        == Some(FileViewStrategy::MacosApfsSparsebundle)
        || layout.macos_cow_image.exists()
        || layout.macos_cow_mount.exists();

    if !has_macos_cow {
        return Ok(false);
    }

    detach_macos_apfs_sparsebundle_file_view(layout, force)?;
    Ok(true)
}

#[cfg(target_os = "macos")]
fn print_macos_cow_storage_status(layout: &Layout) -> Result<()> {
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
fn print_macos_cow_storage_status(layout: &Layout) -> Result<()> {
    println!("  image:     {}", layout.macos_cow_image.display());
    println!("  mount:     {}", layout.macos_cow_mount.display());
    println!("  attached:  not available on this OS");
    Ok(())
}

fn backup_command(args: BackupArgs) -> Result<i32> {
    let layout = Layout::new(args.shared.work_dir.clone())?;
    if args.source.is_none() {
        ensure_branchfs_strategy(&layout, "backup")?;
    }
    prepare_runtime(&layout)?;
    let runtime = PortableRuntime::from_layout(&layout);
    let src = args.source.unwrap_or_else(|| layout.site_fp.clone());
    if !src.is_file() {
        bail!("backup: source .fp not found: {}", src.display());
    }
    run_php_script(
        &layout,
        &runtime,
        &args.shared,
        "scripts/backup.php",
        [src.as_os_str(), args.dest.as_os_str()],
    )?;
    Ok(0)
}

fn export_command(args: ExportArgs) -> Result<i32> {
    let layout = Layout::new(args.shared.work_dir.clone())?;
    if args.source.is_none() {
        ensure_branchfs_strategy(&layout, "export")?;
    }
    prepare_runtime(&layout)?;
    let runtime = PortableRuntime::from_layout(&layout);
    let src = args.source.unwrap_or_else(|| layout.site_fp.clone());
    if !src.is_file() {
        bail!("export: source .fp not found: {}", src.display());
    }
    run_php_script(
        &layout,
        &runtime,
        &args.shared,
        "scripts/export.php",
        [src.as_os_str(), args.output_dir.as_os_str()],
    )?;
    Ok(0)
}

fn import_command(args: ImportArgs) -> Result<i32> {
    let layout = Layout::new(args.shared.work_dir.clone())?;
    prepare_runtime(&layout)?;
    let runtime = PortableRuntime::from_layout(&layout);
    if !args.input_dir.is_dir() {
        bail!(
            "import: source directory not found: {}",
            args.input_dir.display()
        );
    }
    run_php_script(
        &layout,
        &runtime,
        &args.shared,
        "scripts/import.php",
        [args.input_dir.as_os_str(), args.dest.as_os_str()],
    )?;
    Ok(0)
}

fn zfs_command(args: ZfsArgs) -> Result<i32> {
    if std::env::var_os("FORKPRESS_ENABLE_ZFS_CLI").is_none() {
        bail!(
            "the embedded ZFS experiment is disabled; set FORKPRESS_ENABLE_ZFS_CLI=1 to run this developer-only command"
        );
    }
    match args.command {
        ZfsCommand::Smoke(args) => {
            let layout = Layout::new(args.work_dir)?;
            let zfs_dir = layout.work_dir.join("zfs");
            fs::create_dir_all(&zfs_dir)
                .with_context(|| format!("failed to create {}", zfs_dir.display()))?;
            let pool_img = zfs_dir.join("engine-smoke.img");
            let size = args
                .pool_size_mib
                .checked_mul(1024 * 1024)
                .ok_or_else(|| anyhow!("--pool-size-mib is too large"))?;
            let report = zfs_engine::smoke(&pool_img, size)?;
            println!("forkpress: embedded ZFS engine smoke passed");
            println!("  built-in: {}", zfs_engine::available());
            println!("  pool:     {}", pool_img.display());
            println!("  main:     {}", report.read_main);
            println!("  clone:    {}", report.read_clone);
            Ok(0)
        }
    }
}

#[derive(Debug, Clone)]
struct LogFileSpec {
    name: &'static str,
    path: PathBuf,
    description: &'static str,
}

fn logs_command(args: LogsArgs) -> Result<i32> {
    let layout = Layout::new(args.work_dir.clone())?;
    let files = selected_log_files(&layout, args.file);

    if args.paths {
        for file in files {
            println!(
                "{}\t{}\t{}",
                file.name,
                file.path.display(),
                file.description
            );
        }
        return Ok(0);
    }

    if args.follow && files.len() != 1 {
        bail!("--follow requires one log file; pass --file wp, php, server, forkpress, or gc");
    }

    let multiple = files.len() > 1;
    for (idx, file) in files.iter().enumerate() {
        if multiple {
            if idx > 0 {
                println!();
            }
            println!("==> {}: {} <==", file.name, file.path.display());
        }
        print_log_tail(file, args.lines)?;
    }

    if args.follow {
        follow_log_file(&files[0])?;
    }

    Ok(0)
}

fn selected_log_files(layout: &Layout, selection: LogSelection) -> Vec<LogFileSpec> {
    let wp = LogFileSpec {
        name: "wp",
        path: layout.debug_log.clone(),
        description: "WordPress debug log",
    };
    let php = LogFileSpec {
        name: "php",
        path: layout.php_error_log.clone(),
        description: "PHP error_log",
    };
    let server = LogFileSpec {
        name: "server",
        path: layout.php_server_log.clone(),
        description: "PHP built-in server output",
    };
    let forkpress = LogFileSpec {
        name: "forkpress",
        path: layout.forkpress_server_log.clone(),
        description: "ForkPress background server wrapper",
    };
    let gc = LogFileSpec {
        name: "gc",
        path: layout.logs_dir.join("gc.log"),
        description: "background branch garbage collection",
    };

    match selection {
        LogSelection::Wp => vec![wp],
        LogSelection::Php => vec![php],
        LogSelection::Server => vec![server],
        LogSelection::Forkpress => vec![forkpress],
        LogSelection::Gc => vec![gc],
        LogSelection::All => vec![wp, php, server, forkpress, gc],
    }
}

fn print_log_tail(file: &LogFileSpec, lines: usize) -> Result<()> {
    match fs::read_to_string(&file.path) {
        Ok(contents) => {
            for line in tail_lines(&contents, lines) {
                println!("{line}");
            }
        }
        Err(err) if err.kind() == std::io::ErrorKind::NotFound => {
            println!(
                "forkpress: log has not been created yet: {}",
                file.path.display()
            );
        }
        Err(err) => {
            return Err(err).with_context(|| format!("failed to read {}", file.path.display()));
        }
    }
    Ok(())
}

fn tail_lines(contents: &str, line_count: usize) -> Vec<&str> {
    if line_count == 0 {
        return Vec::new();
    }
    let lines: Vec<&str> = contents.lines().collect();
    let start = lines.len().saturating_sub(line_count);
    lines[start..].to_vec()
}

fn follow_log_file(file: &LogFileSpec) -> Result<()> {
    println!(
        "forkpress: following {} at {} (Ctrl-C to stop)",
        file.name,
        file.path.display()
    );

    let mut offset = fs::metadata(&file.path)
        .map(|metadata| metadata.len())
        .unwrap_or(0);

    loop {
        match File::open(&file.path) {
            Ok(mut handle) => {
                let len = handle
                    .metadata()
                    .with_context(|| format!("failed to stat {}", file.path.display()))?
                    .len();
                if len < offset {
                    offset = 0;
                }
                handle
                    .seek(SeekFrom::Start(offset))
                    .with_context(|| format!("failed to seek {}", file.path.display()))?;
                let mut buf = Vec::new();
                handle
                    .read_to_end(&mut buf)
                    .with_context(|| format!("failed to read {}", file.path.display()))?;
                if !buf.is_empty() {
                    print!("{}", String::from_utf8_lossy(&buf));
                    std::io::stdout().flush()?;
                }
                offset = handle.stream_position().with_context(|| {
                    format!("failed to read position for {}", file.path.display())
                })?;
            }
            Err(err) if err.kind() == std::io::ErrorKind::NotFound => {}
            Err(err) => {
                return Err(err).with_context(|| format!("failed to open {}", file.path.display()));
            }
        }
        thread::sleep(Duration::from_secs(1));
    }
}

#[cfg(test)]
mod log_tests {
    use super::*;

    #[test]
    fn tail_lines_returns_requested_suffix() {
        assert_eq!(tail_lines("one\ntwo\nthree\n", 2), vec!["two", "three"]);
    }

    #[test]
    fn tail_lines_handles_short_and_zero_counts() {
        assert_eq!(tail_lines("one\ntwo\n", 10), vec!["one", "two"]);
        assert_eq!(tail_lines("one\ntwo\n", 0), Vec::<&str>::new());
    }
}

#[cfg(test)]
mod storage_strategy_tests {
    use super::*;

    #[test]
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
    fn cli_accepts_cow_strategy_aliases() {
        for strategy in ["cow", "mac-cow", "zfs"] {
            let cli = Cli::try_parse_from([
                "forkpress",
                "init",
                "--strategy",
                strategy,
                "--admin-password",
                "admin",
            ])
            .unwrap();
            let Commands::Init(args) = cli.command else {
                panic!("expected init command");
            };
            assert_eq!(args.strategy, StorageStrategy::Cow);
        }
    }

    #[test]
    fn cli_init_uses_platform_default_strategy() {
        let cli = Cli::try_parse_from(["forkpress", "init"]).unwrap();
        let Commands::Init(args) = cli.command else {
            panic!("expected init command");
        };
        assert_eq!(args.strategy, default_storage_strategy());
    }

    #[test]
    fn cli_accepts_happy_path_serve_and_stop() {
        let serve = Cli::try_parse_from(["forkpress", "serve", "--port", "18780"]).unwrap();
        let Commands::Serve(args) = serve.command else {
            panic!("expected serve command");
        };
        assert_eq!(args.port, 18780);
        assert!(!args.background);
        assert!(!args.foreground);

        let stop =
            Cli::try_parse_from(["forkpress", "stop", "--work-dir", ".forkpress", "--force"])
                .unwrap();
        let Commands::Stop(args) = stop.command else {
            panic!("expected stop command");
        };
        assert!(args.force);
    }

    #[test]
    fn cli_accepts_storage_lifecycle_commands() {
        let status =
            Cli::try_parse_from(["forkpress", "storage", "status", "--work-dir", ".forkpress"])
                .unwrap();
        assert!(matches!(
            status.command,
            Commands::Storage(StorageArgs {
                command: StorageCommand::Status(_)
            })
        ));

        let detach = Cli::try_parse_from([
            "forkpress",
            "storage",
            "detach",
            "--work-dir",
            ".forkpress",
            "--force",
            "--keep-server",
        ])
        .unwrap();
        let Commands::Storage(StorageArgs {
            command: StorageCommand::Detach(args),
        }) = detach.command
        else {
            panic!("expected storage detach command");
        };
        assert!(args.force);
        assert!(args.keep_server);
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
    }

    #[test]
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
    fn cow_branch_names_are_dns_label_safe() {
        assert!(validate_branch_name("feature-1").is_ok());
        assert!(validate_branch_name("agent_2").is_ok());
        assert!(validate_branch_name("").is_err());
        assert!(validate_branch_name("has.dot").is_err());
        assert!(validate_branch_name("../main").is_err());
        assert!(validate_branch_name(&"a".repeat(64)).is_err());
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

fn serve_command(mut args: StartArgs) -> Result<i32> {
    if !args.foreground {
        args.background = true;
    }
    start_command(args)
}

fn start_command(args: StartArgs) -> Result<i32> {
    if args.background {
        return start_background_command(args);
    }

    let layout = Layout::new(args.shared.work_dir.clone())?;
    let strategy = initialized_storage_strategy(&layout)?.unwrap_or(StorageStrategy::Branchfs);
    prepare_runtime(&layout)?;
    ensure_ports_available(&args)?;

    let runtime = PortableRuntime::from_layout(&layout);

    let workers = args.workers.unwrap_or_else(default_worker_count);
    let mut php = match strategy {
        StorageStrategy::Branchfs => {
            ensure_bootstrapped(&layout, &runtime, &args)?;
            start_php_server(&layout, &runtime, &args, workers)?
        }
        StorageStrategy::Cow => {
            ensure_cow_bootstrapped(&layout, &runtime, &args)?;
            start_cow_php_server(&layout, &runtime, &args, workers)?
        }
        StorageStrategy::Cas => {
            ensure_cas_bootstrapped(&layout, &runtime, &args)?;
            start_cas_php_server(&layout, &runtime, &args, workers)?
        }
    };
    let _registration =
        register_running_server(&layout, &args, std::process::id(), Some(php.id()))?;

    if workers > 1 {
        println!("PHP workers: {} (PHP_CLI_SERVER_WORKERS)", workers);
    } else {
        println!("PHP workers: 1 (single-request mode — set --workers >1 for concurrency)");
    }
    println!("Main site:  http://{}:{}/", args.root_host, args.port);
    println!(
        "Branch site: http://<branch>.{}:{}/",
        args.root_host, args.port
    );
    match strategy {
        StorageStrategy::Branchfs => {
            println!(
                "Git remote: http://{}:{}/site.git",
                args.root_host, args.port
            );
            println!("DB access:  database.sql in each git branch checkout (read-only snapshot)");
        }
        StorageStrategy::Cow => {
            println!(
                "Git remote: http://{}:{}/site.git",
                args.root_host, args.port
            );
            println!("DB access:  wp-content/database/.ht.sqlite inside each materialized branch");
        }
        StorageStrategy::Cas => {
            println!("Git remote: not available for cas strategy yet");
            println!("DB access:  .forkpress/cas/branches/<branch>/.ht.sqlite");
        }
    }
    println!(
        "Logs:       forkpress logs --work-dir {} --file wp",
        shell_quote_path(&layout.work_dir)
    );
    println!(
        "Follow:     forkpress logs --work-dir {} --file wp --follow",
        shell_quote_path(&layout.work_dir)
    );
    println!(
        "Stop:       forkpress stop --work-dir {}",
        shell_quote_path(&layout.work_dir)
    );
    println!("List:       forkpress server list");
    println!("Press Ctrl+C to stop.");

    let stop = Arc::new(AtomicBool::new(false));
    let handler_flag = Arc::clone(&stop);
    ctrlc::set_handler(move || {
        handler_flag.store(true, Ordering::SeqCst);
    })
    .context("failed to install Ctrl+C handler")?;

    // Optional background GC. Off by default; enabled with --gc-interval.
    // Inline GC on branch delete runs regardless of this flag.
    let gc_thread = if let Some(interval_raw) = args.gc_interval.as_deref() {
        match parse_duration(interval_raw) {
            Some(interval) => {
                println!("Background GC: every {}", interval_raw);
                let stop_gc = Arc::clone(&stop);
                let layout_gc = layout.clone();
                let runtime_gc = runtime.clone();
                let shared_gc = args.shared.clone();
                Some(thread::spawn(move || {
                    run_background_gc(stop_gc, interval, layout_gc, runtime_gc, shared_gc);
                }))
            }
            None => {
                eprintln!(
                    "forkpress: --gc-interval {:?} is not a valid duration (expected e.g. 300s / 10m / 1h); background GC disabled",
                    interval_raw
                );
                None
            }
        }
    } else {
        None
    };

    loop {
        if stop.load(Ordering::SeqCst) {
            println!("Stopping servers...");
            break;
        }

        if let Some(status) = php.try_wait()? {
            bail!(
                "php server exited unexpectedly with status {status}. Check {}",
                layout.php_server_log.display()
            );
        }

        thread::sleep(Duration::from_millis(250));
    }

    if let Some(h) = gc_thread {
        // The GC thread checks `stop` between ticks, so it exits within one
        // tick of the Ctrl-C; join to surface panics rather than leak.
        let _ = h.join();
    }

    drop(php);
    drop(_registration);
    detach_storage_for_layout_if_present(&layout, false)?;

    Ok(0)
}

fn start_background_command(args: StartArgs) -> Result<i32> {
    let layout = Layout::new(args.shared.work_dir.clone())?;
    fs::create_dir_all(&layout.logs_dir)?;
    ensure_ports_available(&args)?;

    if let Some(record) = running_record_for_work_dir(&layout.work_dir)? {
        bail!(
            "server already running for {} as pid {} at http://{}:{}/",
            layout.work_dir.display(),
            record.pid,
            record.root_host,
            record.port
        );
    }

    let current_exe = std::env::current_exe().context("failed to locate current executable")?;
    let mut command = Command::new(current_exe);
    command.arg("start");
    append_start_args(&mut command, &args, &layout);

    let log = OpenOptions::new()
        .create(true)
        .append(true)
        .open(&layout.forkpress_server_log)
        .with_context(|| format!("failed to open {}", layout.forkpress_server_log.display()))?;
    let log_err = log.try_clone()?;

    command
        .stdin(Stdio::null())
        .stdout(Stdio::from(log))
        .stderr(Stdio::from(log_err));

    #[cfg(unix)]
    unsafe {
        use std::os::unix::process::CommandExt;
        command.pre_exec(|| {
            if libc::setsid() == -1 {
                return Err(std::io::Error::last_os_error());
            }
            Ok(())
        });
    }

    let mut child = command
        .spawn()
        .context("failed to start forkpress server in the background")?;
    let pid = child.id();

    let deadline = Instant::now() + Duration::from_secs(120);
    let mut tcp_ready = false;
    while Instant::now() < deadline {
        if let Some(status) = child
            .try_wait()
            .context("failed to poll background forkpress server")?
        {
            bail!(
                "background server exited early with status {status}. Check {}",
                layout.forkpress_server_log.display()
            );
        }
        if !tcp_ready && tcp_port_open(&args.host, args.port) {
            tcp_ready = true;
        }
        let registered = live_server_records()?.iter().any(|record| {
            record.pid == pid
                && record.work_dir == layout.work_dir
                && record.port == args.port
                && record.root_host == args.root_host
        });
        if tcp_ready && registered {
            println!("forkpress: server started in background as pid {pid}");
            println!("Main site:  http://{}:{}/", args.root_host, args.port);
            println!(
                "Branch site: http://<branch>.{}:{}/",
                args.root_host, args.port
            );
            println!(
                "Logs:       forkpress logs --work-dir {} --file wp",
                shell_quote_path(&layout.work_dir)
            );
            println!(
                "Follow:     forkpress logs --work-dir {} --file wp --follow",
                shell_quote_path(&layout.work_dir)
            );
            println!(
                "Stop:       forkpress stop --work-dir {}",
                shell_quote_path(&layout.work_dir)
            );
            println!("List:       forkpress server list");
            return Ok(0);
        }
        thread::sleep(Duration::from_millis(250));
    }

    bail!(
        "timed out waiting for background server pid {pid}. Check {}",
        layout.forkpress_server_log.display()
    );
}

fn append_start_args(command: &mut Command, args: &StartArgs, layout: &Layout) {
    command.arg("--work-dir").arg(&layout.work_dir);
    if let Some(php_bin) = &args.shared.php_bin {
        command.arg("--php-bin").arg(php_bin);
    }
    command.arg("--host").arg(&args.host);
    command.arg("--port").arg(args.port.to_string());
    command.arg("--root-host").arg(&args.root_host);
    command.arg("--site-title").arg(&args.site_title);
    if let Some(workers) = args.workers {
        command.arg("--workers").arg(workers.to_string());
    }
    if let Some(gc_interval) = &args.gc_interval {
        command.arg("--gc-interval").arg(gc_interval);
    }
}

fn server_command(args: ServerArgs) -> Result<i32> {
    match args.command {
        ServerCommand::Start(mut args) => {
            args.background = true;
            start_command(args)
        }
        ServerCommand::List => server_list_command(),
        ServerCommand::Stop(args) => server_stop_command(args),
    }
}

fn server_list_command() -> Result<i32> {
    let records = live_server_records()?;
    if records.is_empty() {
        println!("forkpress: no running site servers found");
        return Ok(0);
    }

    println!("PID\tPHP PID\tPORT\tROOT HOST\tWORK DIR\tLOG");
    for record in records {
        let child_pid = record
            .child_pid
            .map(|pid| pid.to_string())
            .unwrap_or_else(|| "-".to_string());
        println!(
            "{}\t{}\t{}\t{}\t{}\t{}",
            record.pid,
            child_pid,
            record.port,
            record.root_host,
            record.work_dir.display(),
            record.log.display()
        );
    }
    Ok(0)
}

fn server_stop_command(args: ServerStopArgs) -> Result<i32> {
    if args.all && args.pid.is_some() {
        bail!("pass either --all or --pid, not both");
    }

    let mut targets = Vec::new();
    let mut detach_layouts = Vec::new();
    let records = live_server_records()?;

    if args.all {
        targets = records;
        for record in &targets {
            push_unique_detach_layout(&mut detach_layouts, Layout::new(record.work_dir.clone())?);
        }
    } else if let Some(pid) = args.pid {
        if let Some(record) = records.iter().find(|record| record.pid == pid).cloned() {
            push_unique_detach_layout(&mut detach_layouts, Layout::new(record.work_dir.clone())?);
            targets.push(record);
        }
    } else {
        let layout = Layout::new(args.work_dir.clone())?;
        push_unique_detach_layout(&mut detach_layouts, layout.clone());
        if let Some(pid) = read_pid_file(&layout.server_pid_file)? {
            if let Some(record) = records.iter().find(|record| record.pid == pid).cloned() {
                targets.push(record);
            }
        }
        if targets.is_empty() {
            if let Some(record) = records
                .into_iter()
                .find(|record| record.work_dir == layout.work_dir)
            {
                targets.push(record);
            }
        }
    }

    if targets.is_empty() {
        println!("forkpress: no matching running site servers found");
    } else {
        for record in targets {
            stop_server_record(&record, Duration::from_secs(args.timeout))?;
        }
    }

    for layout in detach_layouts {
        detach_storage_for_layout_if_present(&layout, args.force)?;
    }

    Ok(0)
}

fn push_unique_detach_layout(layouts: &mut Vec<Layout>, layout: Layout) {
    if !layouts
        .iter()
        .any(|existing| existing.work_dir == layout.work_dir)
    {
        layouts.push(layout);
    }
}

impl Drop for ServerRegistrationGuard {
    fn drop(&mut self) {
        let _ = unregister_running_server(&self.layout, self.pid);
    }
}

fn register_running_server(
    layout: &Layout,
    args: &StartArgs,
    pid: u32,
    child_pid: Option<u32>,
) -> Result<ServerRegistrationGuard> {
    fs::create_dir_all(&layout.logs_dir)?;
    fs::write(&layout.server_pid_file, format!("{pid}\n")).with_context(|| {
        format!(
            "failed to write server pid file {}",
            layout.server_pid_file.display()
        )
    })?;

    let mut records = read_server_registry()?;
    records.retain(|record| {
        record.pid != pid && record.work_dir != layout.work_dir && record_process_exists(record)
    });
    records.push(ServerRecord {
        pid,
        child_pid,
        work_dir: layout.work_dir.clone(),
        host: args.host.clone(),
        port: args.port,
        root_host: args.root_host.clone(),
        log: layout.forkpress_server_log.clone(),
    });
    write_server_registry(&records)?;

    Ok(ServerRegistrationGuard {
        pid,
        layout: layout.clone(),
    })
}

fn unregister_running_server(layout: &Layout, pid: u32) -> Result<()> {
    if let Some(current_pid) = read_pid_file(&layout.server_pid_file)? {
        if current_pid == pid {
            let _ = fs::remove_file(&layout.server_pid_file);
        }
    }

    let mut records = read_server_registry()?;
    let original_len = records.len();
    records.retain(|record| record.pid != pid);
    if records.len() != original_len {
        write_server_registry(&records)?;
    }
    Ok(())
}

fn running_record_for_work_dir(work_dir: &std::path::Path) -> Result<Option<ServerRecord>> {
    Ok(live_server_records()?
        .into_iter()
        .find(|record| record.work_dir == work_dir))
}

fn live_server_records() -> Result<Vec<ServerRecord>> {
    let records = read_server_registry()?;
    let live: Vec<ServerRecord> = records.into_iter().filter(record_process_exists).collect();
    write_server_registry(&live)?;
    Ok(live)
}

fn read_server_registry() -> Result<Vec<ServerRecord>> {
    let path = server_registry_path();
    let Ok(raw) = fs::read_to_string(&path) else {
        return Ok(Vec::new());
    };

    let mut records = Vec::new();
    for line in raw.lines() {
        if let Some(record) = parse_server_record_line(line) {
            records.push(record);
        }
    }
    Ok(records)
}

fn write_server_registry(records: &[ServerRecord]) -> Result<()> {
    let path = server_registry_path();
    if let Some(parent) = path.parent() {
        fs::create_dir_all(parent)
            .with_context(|| format!("failed to create {}", parent.display()))?;
    }

    let mut out = String::new();
    for record in records {
        out.push_str(&format_server_record_line(record));
        out.push('\n');
    }

    fs::write(&path, out).with_context(|| format!("failed to write {}", path.display()))?;
    Ok(())
}

fn parse_server_record_line(line: &str) -> Option<ServerRecord> {
    let fields: Vec<&str> = line.split('\t').collect();
    if fields.len() != 6 && fields.len() != 7 {
        return None;
    }
    let pid = fields[0].parse::<u32>().ok()?;
    let port = fields[3].parse::<u16>().ok()?;
    let child_pid = if fields.len() == 7 && !fields[6].is_empty() {
        Some(fields[6].parse::<u32>().ok()?)
    } else {
        None
    };

    Some(ServerRecord {
        pid,
        child_pid,
        work_dir: PathBuf::from(unescape_registry_field(fields[1])),
        host: unescape_registry_field(fields[2]),
        port,
        root_host: unescape_registry_field(fields[4]),
        log: PathBuf::from(unescape_registry_field(fields[5])),
    })
}

fn format_server_record_line(record: &ServerRecord) -> String {
    let mut out = String::new();
    out.push_str(&record.pid.to_string());
    out.push('\t');
    out.push_str(&escape_registry_field(&record.work_dir.to_string_lossy()));
    out.push('\t');
    out.push_str(&escape_registry_field(&record.host));
    out.push('\t');
    out.push_str(&record.port.to_string());
    out.push('\t');
    out.push_str(&escape_registry_field(&record.root_host));
    out.push('\t');
    out.push_str(&escape_registry_field(&record.log.to_string_lossy()));
    out.push('\t');
    if let Some(child_pid) = record.child_pid {
        out.push_str(&child_pid.to_string());
    }
    out
}

fn server_registry_path() -> PathBuf {
    if let Some(dir) = std::env::var_os("FORKPRESS_STATE_DIR") {
        return PathBuf::from(dir).join(SERVER_REGISTRY_FILE);
    }
    if let Some(dir) = std::env::var_os("XDG_STATE_HOME") {
        return PathBuf::from(dir)
            .join("forkpress")
            .join(SERVER_REGISTRY_FILE);
    }
    if let Some(home) = std::env::var_os("HOME") {
        return PathBuf::from(home)
            .join(".local/state/forkpress")
            .join(SERVER_REGISTRY_FILE);
    }
    std::env::temp_dir().join(format!(
        "forkpress-{}-{SERVER_REGISTRY_FILE}",
        std::env::var("USER").unwrap_or_else(|_| "user".to_string())
    ))
}

fn read_pid_file(path: &std::path::Path) -> Result<Option<u32>> {
    let Ok(raw) = fs::read_to_string(path) else {
        return Ok(None);
    };
    let trimmed = raw.trim();
    if trimmed.is_empty() {
        return Ok(None);
    }
    let pid = trimmed
        .parse::<u32>()
        .with_context(|| format!("invalid pid in {}", path.display()))?;
    Ok(Some(pid))
}

fn stop_server_record(record: &ServerRecord, timeout: Duration) -> Result<()> {
    if !record_process_exists(record) {
        println!("forkpress: pid {} is no longer running", record.pid);
        let layout = Layout::new(record.work_dir.clone())?;
        let _ = unregister_running_server(&layout, record.pid);
        return Ok(());
    }

    signal_server_record(record, libc::SIGINT)?;
    if !wait_for_record_exit(record, timeout) {
        signal_server_record(record, libc::SIGTERM)?;
        if !wait_for_record_exit(record, Duration::from_secs(2)) {
            signal_server_record(record, libc::SIGKILL)?;
            let _ = wait_for_record_exit(record, Duration::from_secs(2));
        }
    }

    let layout = Layout::new(record.work_dir.clone())?;
    let _ = unregister_running_server(&layout, record.pid);

    if record_process_exists(record) {
        bail!("failed to stop server pid {}", record.pid);
    }

    if tcp_port_open(&record.host, record.port) {
        eprintln!(
            "forkpress: warning: {}:{} is still accepting connections; another process may own the port",
            record.host, record.port
        );
    }

    println!(
        "forkpress: stopped server pid {} for http://{}:{}/",
        record.pid, record.root_host, record.port
    );
    Ok(())
}

fn wait_for_record_exit(record: &ServerRecord, timeout: Duration) -> bool {
    let deadline = Instant::now() + timeout;
    while Instant::now() < deadline {
        if !record_process_exists(record) {
            return true;
        }
        thread::sleep(Duration::from_millis(100));
    }
    !record_process_exists(record)
}

fn record_process_exists(record: &ServerRecord) -> bool {
    process_exists(record.pid) || record.child_pid.map(process_exists).unwrap_or(false)
}

fn process_exists(pid: u32) -> bool {
    if pid == 0 {
        return false;
    }
    let rc = unsafe { libc::kill(pid as libc::pid_t, 0) };
    if rc == 0 {
        return true;
    }
    matches!(
        std::io::Error::last_os_error().raw_os_error(),
        Some(libc::EPERM)
    )
}

fn signal_server_record(record: &ServerRecord, signal: i32) -> Result<()> {
    let mut signaled_group = false;
    if process_exists(record.pid) {
        if process_group_id(record.pid) == Some(record.pid) {
            signal_process_group(record.pid, signal)?;
            signaled_group = true;
        } else {
            signal_process(record.pid, signal)?;
        }
    }

    if let Some(child_pid) = record.child_pid {
        if process_exists(child_pid) && !signaled_group {
            if process_group_id(child_pid) == Some(record.pid) {
                signal_process_group(record.pid, signal)?;
            } else {
                signal_process(child_pid, signal)?;
            }
        }
    }

    Ok(())
}

fn process_group_id(pid: u32) -> Option<u32> {
    if pid == 0 {
        return None;
    }
    let pgid = unsafe { libc::getpgid(pid as libc::pid_t) };
    if pgid < 0 { None } else { Some(pgid as u32) }
}

fn signal_process_group(pgid: u32, signal: i32) -> Result<()> {
    if pgid == 0 {
        return Ok(());
    }
    let rc = unsafe { libc::kill(-(pgid as libc::pid_t), signal) };
    if rc == 0 {
        return Ok(());
    }
    if matches!(
        std::io::Error::last_os_error().raw_os_error(),
        Some(libc::ESRCH)
    ) {
        return Ok(());
    }
    Err(std::io::Error::last_os_error())
        .with_context(|| format!("failed to signal process group {pgid}"))
}

fn signal_process(pid: u32, signal: i32) -> Result<()> {
    let rc = unsafe { libc::kill(pid as libc::pid_t, signal) };
    if rc == 0 {
        return Ok(());
    }
    if matches!(
        std::io::Error::last_os_error().raw_os_error(),
        Some(libc::ESRCH)
    ) {
        return Ok(());
    }
    Err(std::io::Error::last_os_error()).with_context(|| format!("failed to signal process {pid}"))
}

fn escape_registry_field(field: &str) -> String {
    let mut escaped = String::new();
    for ch in field.chars() {
        match ch {
            '\\' => escaped.push_str("\\\\"),
            '\t' => escaped.push_str("\\t"),
            '\n' => escaped.push_str("\\n"),
            '\r' => escaped.push_str("\\r"),
            other => escaped.push(other),
        }
    }
    escaped
}

fn unescape_registry_field(field: &str) -> String {
    let mut out = String::new();
    let mut chars = field.chars();
    while let Some(ch) = chars.next() {
        if ch != '\\' {
            out.push(ch);
            continue;
        }
        match chars.next() {
            Some('\\') => out.push('\\'),
            Some('t') => out.push('\t'),
            Some('n') => out.push('\n'),
            Some('r') => out.push('\r'),
            Some(other) => {
                out.push('\\');
                out.push(other);
            }
            None => out.push('\\'),
        }
    }
    out
}

fn shell_quote_path(path: &std::path::Path) -> String {
    shell_quote(&path.to_string_lossy())
}

fn shell_quote(value: &str) -> String {
    if value
        .chars()
        .all(|ch| ch.is_ascii_alphanumeric() || matches!(ch, '/' | '.' | '_' | '-' | ':'))
    {
        return value.to_string();
    }
    format!("'{}'", value.replace('\'', "'\\''"))
}

fn git_command(args: GitPassthrough) -> Result<i32> {
    if args.args.is_empty() {
        bail!(
            "git requires arguments, e.g. `forkpress git clone` or `forkpress git branch create agent-1`"
        );
    }

    if args.args.len() >= 3 && args.args[0] == "branch" && args.args[1] == "create" {
        let branch = &args.args[2];
        let create_args = git_branch_create_args(&args.args[3..])?;
        create_args.auth.validate()?;
        let layout = Layout::new(args.shared.work_dir.clone())?;
        let strategy = require_initialized_strategy(&layout, "git branch create")?;
        prepare_runtime(&layout)?;
        let runtime = PortableRuntime::from_layout(&layout);
        match strategy {
            StorageStrategy::Cow => {
                if create_args.auth.user.is_some() {
                    bail!("cow local branch creation does not use --user/--password");
                }
                create_cow_branch(&layout, &runtime, &args.shared, branch, &create_args.from)?;
                println!("forkpress: branch {branch} ready");
                return Ok(0);
            }
            StorageStrategy::Cas => {
                if create_args.auth.user.is_some() {
                    bail!("cas local branch creation does not use --user/--password");
                }
                create_cas_branch(&layout, &runtime, &args.shared, branch, &create_args.from)?;
                println!("forkpress: branch {branch} ready");
                return Ok(0);
            }
            StorageStrategy::Branchfs => {}
        }
        if !layout.site_fp.exists() || !layout.bootstrap_marker.exists() {
            bail!(
                "no bootstrapped site found in {}. Run `forkpress serve` first",
                layout.work_dir.display()
            );
        }
        ensure_branch_exists(
            &layout,
            &runtime,
            &args.shared,
            branch,
            &create_args.from,
            &create_args.auth,
        )?;
        println!("forkpress: branch {branch} ready");
        return Ok(0);
    }

    ensure_git_available()?;
    let git_args: Vec<OsString> = args.args.iter().map(OsString::from).collect();
    run_git(None, git_args)?;
    Ok(0)
}

#[derive(Debug, Clone, Default)]
struct BranchAuth {
    user: Option<String>,
    password: Option<String>,
}

impl BranchAuth {
    fn validate(&self) -> Result<()> {
        if self.user.is_some() != self.password.is_some() {
            bail!("--user and --password must be passed together");
        }
        Ok(())
    }

    fn append_to(&self, command: &mut Command) {
        if let (Some(user), Some(password)) = (&self.user, &self.password) {
            command
                .arg("--user")
                .arg(user)
                .arg("--password")
                .arg(password);
        }
    }
}

#[derive(Debug, Clone)]
struct BranchCreateArgs {
    from: String,
    auth: BranchAuth,
}

fn git_branch_create_args(args: &[String]) -> Result<BranchCreateArgs> {
    let mut parsed = BranchCreateArgs {
        from: "main".to_string(),
        auth: BranchAuth::default(),
    };
    let mut index = 0;
    while index < args.len() {
        match args[index].as_str() {
            "--from" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--from requires a branch name");
                };
                parsed.from = value.clone();
                index += 2;
            }
            "--user" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--user requires a username");
                };
                parsed.auth.user = Some(value.clone());
                index += 2;
            }
            "--password" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--password requires a password");
                };
                parsed.auth.password = Some(value.clone());
                index += 2;
            }
            other => bail!("unsupported argument for `forkpress git branch create`: {other}"),
        }
    }
    Ok(parsed)
}

fn agents_command(args: AgentsArgs) -> Result<i32> {
    ensure_git_available()?;
    if args.count == 0 {
        bail!("--count must be greater than zero");
    }
    let auth = BranchAuth {
        user: args.user.clone(),
        password: args.password.clone(),
    };
    auth.validate()?;

    let layout = Layout::new(args.shared.work_dir.clone())?;
    let strategy = require_initialized_strategy(&layout, "agents")?;
    if strategy == StorageStrategy::Cas {
        bail!("agents is not available for cas strategy yet");
    }
    prepare_runtime(&layout)?;
    let runtime = PortableRuntime::from_layout(&layout);

    match strategy {
        StorageStrategy::Branchfs => {
            if !layout.site_fp.exists() || !layout.bootstrap_marker.exists() {
                bail!(
                    "no bootstrapped site found in {}. Run `forkpress serve` first",
                    layout.work_dir.display()
                );
            }
        }
        StorageStrategy::Cow => {
            ensure_cow_file_view_ready(&layout)?;
            if !cow_branch_root(&layout, "main")
                .join("wp-load.php")
                .is_file()
            {
                bail!(
                    "no COW main branch found in {}. Run `forkpress init` first",
                    layout.work_dir.display()
                );
            }
        }
        StorageStrategy::Cas => unreachable!(),
    }

    let root_dir = absolutize(args.dir)?;
    fs::create_dir_all(&root_dir)
        .with_context(|| format!("failed to create {}", root_dir.display()))?;
    let repo = root_dir.join("site");
    if repo.exists() {
        ensure_git_repository(&repo)?;
    } else {
        run_git(
            None,
            [
                OsString::from("clone"),
                OsString::from("--origin"),
                OsString::from(&args.remote_name),
                OsString::from(&args.remote),
                repo.as_os_str().to_owned(),
            ],
        )?;
    }

    for index in 1..=args.count {
        let branch = format!("{}-{}", args.prefix, index);
        match strategy {
            StorageStrategy::Branchfs => {
                ensure_branch_exists(&layout, &runtime, &args.shared, &branch, &args.from, &auth)?;
            }
            StorageStrategy::Cow => {
                ensure_cow_branch_exists(&layout, &runtime, &args.shared, &branch, &args.from)?;
            }
            StorageStrategy::Cas => unreachable!(),
        }
    }

    run_git(
        Some(&repo),
        [
            OsString::from("fetch"),
            OsString::from("--prune"),
            OsString::from(&args.remote_name),
        ],
    )?;

    for index in 1..=args.count {
        let branch = format!("{}-{}", args.prefix, index);
        let worktree_path = root_dir.join(&branch);
        if worktree_path.exists() {
            println!(
                "forkpress: reusing existing worktree {}",
                worktree_path.display()
            );
            continue;
        }
        add_agent_worktree(&repo, &args.remote_name, &branch, &worktree_path)?;
        println!("forkpress: {} ready at {}", branch, worktree_path.display());
    }

    println!(
        "forkpress: {} agent worktrees ready under {}",
        args.count,
        root_dir.display()
    );
    Ok(0)
}

fn push_command(args: PushArgs) -> Result<i32> {
    ensure_git_available()?;
    let repo = absolutize(args.repo)?;
    ensure_git_repository(&repo)?;

    let branch = git_stdout(&repo, ["rev-parse", "--abbrev-ref", "HEAD"])?;
    if branch == "HEAD" {
        bail!("push requires a named branch; detached HEAD is not supported");
    }

    let status = git_stdout(&repo, ["status", "--porcelain"])?;
    if !status.trim().is_empty() {
        ensure_git_identity(&repo)?;
        run_git(Some(&repo), [OsString::from("add"), OsString::from("-A")])?;
        let message = args
            .message
            .unwrap_or_else(|| default_commit_message(&branch));
        run_git(
            Some(&repo),
            [
                OsString::from("commit"),
                OsString::from("-m"),
                OsString::from(message),
            ],
        )?;
    }

    run_git(
        Some(&repo),
        [
            OsString::from("push"),
            OsString::from(&args.remote_name),
            OsString::from(format!("HEAD:refs/heads/{branch}")),
        ],
    )?;

    println!("forkpress: {branch} is now previewable over HTTP");
    Ok(0)
}

fn ensure_git_identity(repo: &std::path::Path) -> Result<()> {
    if !git_config_is_set(repo, "user.name")? {
        run_git(
            Some(repo),
            [
                OsString::from("config"),
                OsString::from("user.name"),
                OsString::from("ForkPress Agent"),
            ],
        )?;
    }
    if !git_config_is_set(repo, "user.email")? {
        run_git(
            Some(repo),
            [
                OsString::from("config"),
                OsString::from("user.email"),
                OsString::from("forkpress-agent@local"),
            ],
        )?;
    }
    Ok(())
}

fn git_config_is_set(repo: &std::path::Path, key: &str) -> Result<bool> {
    let status = Command::new("git")
        .arg("config")
        .arg("--get")
        .arg(key)
        .current_dir(repo)
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .with_context(|| format!("failed to inspect git config {key}"))?;
    Ok(status.success())
}

/// Background GC loop. Runs until `stop` flips true. Each tick invokes
/// `scripts/branchctl.php gc` via the bundled PHP and appends stdout/stderr
/// to a dedicated log file (separate from php-server.log so one stream's
/// rotation doesn't clobber the other).
fn run_background_gc(
    stop: Arc<AtomicBool>,
    interval: Duration,
    layout: Layout,
    runtime: PortableRuntime,
    shared: SharedPaths,
) {
    let gc_log_path = layout.logs_dir.join("gc.log");
    // Poll cadence used to observe the stop flag between ticks. Keeping it
    // small means Ctrl-C returns near-instantly even with --gc-interval 1h.
    let poll = Duration::from_millis(250);
    let mut next_run = Instant::now() + interval;
    while !stop.load(Ordering::SeqCst) {
        if Instant::now() >= next_run {
            if let Err(err) = run_gc_once(&layout, &runtime, &shared, &gc_log_path) {
                let _ = OpenOptions::new()
                    .create(true)
                    .append(true)
                    .open(&gc_log_path)
                    .and_then(|mut f| writeln!(f, "forkpress gc: failed: {err:#}"));
            }
            next_run = Instant::now() + interval;
        }
        thread::sleep(poll);
    }
}

fn run_gc_once(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    gc_log_path: &std::path::Path,
) -> Result<()> {
    let log = OpenOptions::new()
        .create(true)
        .append(true)
        .open(gc_log_path)
        .with_context(|| format!("failed to open {}", gc_log_path.display()))?;
    let log_err = log.try_clone()?;

    let mut cmd = php_base_command(layout, runtime, shared);
    cmd.arg(layout.runtime_dir.join("scripts/branchctl.php"))
        .arg("gc")
        .env("BRANCHFS_DB", &layout.site_fp)
        .env("BRANCHFS_SQLITE_WP_DB", &layout.site_fp)
        .stdout(Stdio::from(log))
        .stderr(Stdio::from(log_err));
    let status = cmd.status().context("failed to spawn branchctl gc")?;
    if !status.success() {
        bail!("branchctl gc exited with {status}");
    }
    Ok(())
}

fn branch_command(args: BranchPassthrough) -> Result<i32> {
    if args.args.is_empty() {
        bail!("branch requires branchctl arguments, e.g. `forkpress branch create marketing`");
    }

    let layout = Layout::new(args.shared.work_dir.clone())?;
    let strategy = require_initialized_strategy(&layout, "branch")?;
    prepare_runtime(&layout)?;
    let runtime = PortableRuntime::from_layout(&layout);

    match strategy {
        StorageStrategy::Cow => return cow_branch_command(args, layout, runtime),
        StorageStrategy::Cas => return cas_branch_command(args, layout, runtime),
        StorageStrategy::Branchfs => {}
    }

    if !layout.site_fp.exists() || !layout.bootstrap_marker.exists() {
        bail!(
            "no bootstrapped site found in {}. Run `forkpress serve` first",
            layout.work_dir.display()
        );
    }

    let (root_host, port) = branchctl_url_hint(&layout)?;
    let mut command = php_base_command(&layout, &runtime, &args.shared);
    command.arg(layout.runtime_dir.join("scripts/branchctl.php"));
    for arg in &args.args {
        command.arg(arg);
    }
    command.env("BRANCHFS_DB", &layout.site_fp);
    command.env("BRANCHFS_SQLITE_WP_DB", &layout.site_fp);
    command.env("BRANCHFS_ROOT_HOST", &root_host);
    command.env("PORT", &port);
    command.env("FORKPRESS_LOCAL_CTL", "1");

    let output = command
        .output()
        .context("failed to run branch command via bundled php")?;

    write_filtered_output(&output.stdout, &output.stderr)?;

    Ok(output.status.code().unwrap_or(1))
}

fn cow_branch_command(
    args: BranchPassthrough,
    layout: Layout,
    runtime: PortableRuntime,
) -> Result<i32> {
    ensure_cow_file_view_ready(&layout)?;
    match args.args[0].as_str() {
        "list" => {
            for branch in cow_branch_names(&layout)? {
                println!("{branch}");
            }
            Ok(0)
        }
        "show" | "status" => {
            let branch = args.args.get(1).map(String::as_str).unwrap_or("main");
            show_cow_branch(&layout, branch)?;
            Ok(0)
        }
        "create" => {
            let Some(branch) = args.args.get(1) else {
                bail!("branch create requires a branch name");
            };
            let mut from = "main".to_string();
            let mut index = 2;
            while index < args.args.len() {
                match args.args[index].as_str() {
                    "--from" => {
                        let Some(value) = args.args.get(index + 1) else {
                            bail!("--from requires a branch name");
                        };
                        from = value.clone();
                        index += 2;
                    }
                    other => bail!("unsupported argument for `forkpress branch create`: {other}"),
                }
            }
            create_cow_branch(&layout, &runtime, &args.shared, branch, &from)?;
            Ok(0)
        }
        "delete" | "rm" => {
            let Some(branch) = args.args.get(1) else {
                bail!("branch delete requires a branch name");
            };
            delete_cow_branch(&layout, branch)?;
            Ok(0)
        }
        other => bail!("cow branch subcommand is not implemented yet: {other}"),
    }
}

fn cas_branch_command(
    args: BranchPassthrough,
    layout: Layout,
    runtime: PortableRuntime,
) -> Result<i32> {
    match args.args[0].as_str() {
        "list" => {
            for branch in cas_branch_names(&layout)? {
                println!("{branch}");
            }
            Ok(0)
        }
        "create" => {
            let Some(branch) = args.args.get(1) else {
                bail!("branch create requires a branch name");
            };
            let mut from = "main".to_string();
            let mut index = 2;
            while index < args.args.len() {
                match args.args[index].as_str() {
                    "--from" => {
                        let Some(value) = args.args.get(index + 1) else {
                            bail!("--from requires a branch name");
                        };
                        from = value.clone();
                        index += 2;
                    }
                    other => bail!("unsupported argument for `forkpress branch create`: {other}"),
                }
            }
            create_cas_branch(&layout, &runtime, &args.shared, branch, &from)?;
            Ok(0)
        }
        other => bail!("cas branch subcommand is not implemented yet: {other}"),
    }
}

fn branchctl_url_hint(layout: &Layout) -> Result<(String, String)> {
    if let Some(record) = running_record_for_work_dir(&layout.work_dir)? {
        return Ok((record.root_host, record.port.to_string()));
    }
    Ok(("wp.localhost".to_string(), "18080".to_string()))
}

fn default_commit_message(branch: &str) -> String {
    format!("forkpress: update {branch}")
}

fn default_git_remote() -> String {
    "http://wp.localhost:18080/site.git".to_string()
}

fn ensure_branch_exists(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    branch: &str,
    from: &str,
    auth: &BranchAuth,
) -> Result<()> {
    let (root_host, port) = branchctl_url_hint(layout)?;
    let mut command = php_base_command(layout, runtime, shared);
    command
        .arg(layout.runtime_dir.join("scripts/branchctl.php"))
        .arg("create")
        .arg(branch)
        .arg("--from")
        .arg(from)
        .env("BRANCHFS_DB", &layout.site_fp)
        .env("BRANCHFS_SQLITE_WP_DB", &layout.site_fp)
        .env("BRANCHFS_ROOT_HOST", &root_host)
        .env("PORT", &port)
        .env("FORKPRESS_LOCAL_CTL", "1");
    auth.append_to(&mut command);

    let output = command
        .output()
        .with_context(|| format!("failed to create branch {branch} via bundled php"))?;

    if output.status.success() {
        write_filtered_output(&output.stdout, &output.stderr)?;
        return Ok(());
    }

    let stderr_text = String::from_utf8_lossy(&output.stderr);
    if stderr_text.contains("already exists") {
        println!("forkpress: reusing existing branch {branch}");
        return Ok(());
    }

    write_filtered_output(&output.stdout, &output.stderr)?;
    bail!(
        "branchctl create {branch} exited with status {}",
        output.status
    );
}

fn add_agent_worktree(
    repo: &std::path::Path,
    remote_name: &str,
    branch: &str,
    path: &std::path::Path,
) -> Result<()> {
    if git_ref_exists(repo, &format!("refs/heads/{branch}"))? {
        run_git(
            Some(repo),
            [
                OsString::from("worktree"),
                OsString::from("add"),
                path.as_os_str().to_owned(),
                OsString::from(branch),
            ],
        )
    } else {
        run_git(
            Some(repo),
            [
                OsString::from("worktree"),
                OsString::from("add"),
                OsString::from("--track"),
                OsString::from("-b"),
                OsString::from(branch),
                path.as_os_str().to_owned(),
                OsString::from(format!("{remote_name}/{branch}")),
            ],
        )
    }
}

impl Layout {
    fn new(work_dir: PathBuf) -> Result<Self> {
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
            cas_dir: work_dir.join("cas"),
            cas_store: work_dir.join("cas/store.redb"),
            cas_wp_root: work_dir.join("cas/wproot"),
            cas_branches_dir: work_dir.join("cas/branches"),
            cas_branch_list: work_dir.join("cas/branches.txt"),
            wp_root: work_dir.join("wproot"),
            debug_log: work_dir.join("logs/wp-debug.log"),
            php_error_log: work_dir.join("logs/php-errors.log"),
            php_server_log: work_dir.join("logs/php-server.log"),
            forkpress_server_log: work_dir.join("logs/forkpress-server.log"),
            server_pid_file: work_dir.join("server.pid"),
            runtime_ready_marker: work_dir.join("runtime/.forkpress-runtime-ready"),
            bootstrap_marker: work_dir.join(".forkpress-bootstrap-complete"),
            managed_files_marker: work_dir.join(".forkpress-managed-files-version"),
            work_dir,
        })
    }
}

impl PortableRuntime {
    fn from_layout(layout: &Layout) -> Self {
        let root = layout.runtime_dir.join("portable-runtime");
        Self {
            php: root.join("bin/php"),
        }
    }
}

fn read_site_manifest(layout: &Layout) -> Result<Option<SiteManifest>> {
    if !layout.site_manifest.is_file() {
        return Ok(None);
    }
    let contents = fs::read_to_string(&layout.site_manifest)
        .with_context(|| format!("failed to read {}", layout.site_manifest.display()))?;
    SiteManifest::parse(&contents)
        .with_context(|| format!("failed to parse {}", layout.site_manifest.display()))
        .map(Some)
}

fn write_site_manifest(layout: &Layout, manifest: SiteManifest) -> Result<()> {
    fs::create_dir_all(&layout.work_dir)?;
    fs::write(&layout.site_manifest, manifest.render())
        .with_context(|| format!("failed to write {}", layout.site_manifest.display()))
}

fn write_site_manifest_if_missing(layout: &Layout, manifest: SiteManifest) -> Result<()> {
    if read_site_manifest(layout)?.is_none() {
        write_site_manifest(layout, manifest)?;
    }
    Ok(())
}

fn initialized_storage_strategy(layout: &Layout) -> Result<Option<StorageStrategy>> {
    if let Some(manifest) = read_site_manifest(layout)? {
        return Ok(Some(manifest.strategy));
    }

    // Back-compat: sites created before the manifest existed are the original
    // BranchFS/SQLite strategy and can be detected from site.fp.
    if layout.site_fp.exists() {
        return Ok(Some(StorageStrategy::Branchfs));
    }

    Ok(None)
}

fn require_initialized_strategy(layout: &Layout, command: &str) -> Result<StorageStrategy> {
    initialized_storage_strategy(layout)?.ok_or_else(|| {
        anyhow!(
            "{command}: no ForkPress site found in {}. Run `forkpress init` first.",
            layout.work_dir.display()
        )
    })
}

fn ensure_branchfs_strategy(layout: &Layout, command: &str) -> Result<()> {
    let strategy = require_initialized_strategy(layout, command)?;
    if strategy != StorageStrategy::Branchfs {
        bail_strategy_unsupported(command, strategy)?;
    }
    Ok(())
}

fn bail_strategy_unsupported(command: &str, strategy: StorageStrategy) -> Result<()> {
    bail!(
        "{command} is not implemented for the {} storage strategy yet. This site was initialized with strategy = \"{}\".",
        strategy.display_name(),
        strategy.as_str()
    )
}

fn write_cow_strategy_notes(layout: &Layout) -> Result<()> {
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
clone primitives (Linux `FICLONE`, macOS `clonefile`). On macOS, if the current
location cannot clone files, ForkPress creates a rootless APFS sparsebundle
under `.forkpress/macos-cow`, mounts it at `.forkpress/macos-cow/mount`, and
links each public branch directory, such as `./main`, into that APFS volume. A
regular full copy is only the last-resort file view.

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

fn write_cas_notes(layout: &Layout) -> Result<()> {
    fs::create_dir_all(&layout.cas_dir)
        .with_context(|| format!("failed to create {}", layout.cas_dir.display()))?;
    let notes = "\
# ForkPress CAS strategy

This site was initialized with `strategy = \"cas\"`.

WordPress files are stored in `.forkpress/cas/store.redb` and served lazily
through the built-in `branchfs` PHP extension. `.forkpress/cas/wproot` is the
virtual document root path used for PHP path interception; it is not a full
copy of WordPress.

Each branch has an ordinary SQLite database at
`.forkpress/cas/branches/<branch>/.ht.sqlite`.

The durable experimental store is `.forkpress/cas/store.redb`. It stores:

- content-addressed file blobs keyed by SHA-256 hash
- branch manifests listing paths and blob hashes
- branch pointers that make local branch creation share unchanged blobs

Creating a branch copies the source branch manifest in Redb and copies only the
source branch's SQLite database directory. Git smart HTTP is not wired to this
strategy yet.
";
    fs::write(layout.cas_dir.join("README.md"), notes).with_context(|| {
        format!(
            "failed to write {}",
            layout.cas_dir.join("README.md").display()
        )
    })
}

fn absolutize(path: PathBuf) -> Result<PathBuf> {
    // Make the path absolute and normalize `.` / `..` components. We can't use
    // std::fs::canonicalize because the directory may not exist yet (first run).
    // A literal `./` survives a naive join (e.g. `cwd + "./.forkpress"` becomes
    // `cwd/./.forkpress`), and branchfs's prefix matching does not treat that
    // as equal to `cwd/.forkpress`, so this normalization is load-bearing.
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

fn path_exists_no_follow(path: &Path) -> bool {
    match fs::symlink_metadata(path) {
        Ok(_) => true,
        Err(err) if err.kind() == std::io::ErrorKind::NotFound => false,
        Err(_) => true,
    }
}

fn prepare_runtime(layout: &Layout) -> Result<()> {
    fs::create_dir_all(&layout.work_dir)?;
    fs::create_dir_all(&layout.logs_dir)?;
    fs::create_dir_all(&layout.wp_root)?;

    let expected_marker = format!("forkpress-runtime {RUNTIME_BUNDLE_ID}\n");
    let runtime_current = fs::read_to_string(&layout.runtime_ready_marker)
        .map(|contents| contents == expected_marker)
        .unwrap_or(false);

    if !runtime_current {
        if layout.runtime_dir.exists() {
            fs::remove_dir_all(&layout.runtime_dir)
                .with_context(|| format!("failed to clear {}", layout.runtime_dir.display()))?;
        }
        fs::create_dir_all(&layout.runtime_dir)?;

        let decoder = GzDecoder::new(Cursor::new(RUNTIME_BUNDLE));
        let mut archive = tar::Archive::new(decoder);
        archive.unpack(&layout.runtime_dir).with_context(|| {
            format!(
                "failed to unpack runtime into {}",
                layout.runtime_dir.display()
            )
        })?;

        ensure_wp_source_unzipped(layout)?;
        fs::write(&layout.runtime_ready_marker, expected_marker)?;
    }

    Ok(())
}

fn ensure_wp_source_unzipped(layout: &Layout) -> Result<()> {
    let wp_src_dir = layout.runtime_dir.join("runtime/wp-src");
    if wp_src_dir.join("wp-load.php").exists() {
        return Ok(());
    }

    fs::create_dir_all(&wp_src_dir)?;
    let zip_file = File::open(layout.runtime_dir.join("runtime/wp.zip"))
        .context("failed to open embedded WordPress archive")?;
    let mut zip = ZipArchive::new(zip_file).context("failed to read embedded WordPress zip")?;

    for i in 0..zip.len() {
        let mut entry = zip.by_index(i)?;
        let enclosed = entry
            .enclosed_name()
            .ok_or_else(|| anyhow!("zip entry had invalid path"))?;
        let stripped = enclosed
            .strip_prefix("wordpress")
            .unwrap_or(enclosed.as_path());

        if stripped.as_os_str().is_empty() {
            continue;
        }

        let out_path = wp_src_dir.join(stripped);

        if entry.is_dir() {
            fs::create_dir_all(&out_path)?;
            continue;
        }

        if let Some(parent) = out_path.parent() {
            fs::create_dir_all(parent)?;
        }

        let mut out = File::create(&out_path)?;
        std::io::copy(&mut entry, &mut out)?;
    }

    Ok(())
}

fn ensure_ports_available(args: &StartArgs) -> Result<()> {
    if tcp_port_open(&args.host, args.port) {
        bail!(
            "http server port {} is already in use on {}",
            args.port,
            args.host
        );
    }
    Ok(())
}

fn ensure_cow_bootstrapped(
    layout: &Layout,
    runtime: &PortableRuntime,
    args: &StartArgs,
) -> Result<()> {
    let file_view = ensure_cow_file_view_ready(layout)?;
    let init_args = InitArgs {
        shared: args.shared.clone(),
        strategy: StorageStrategy::Cow,
        site_title: args.site_title.clone(),
        root_host: args.root_host.clone(),
        admin_password: Some("admin".to_string()),
    };
    ensure_cow_main_branch(layout, runtime, &init_args, file_view)?;
    write_site_manifest_if_missing(
        layout,
        SiteManifest::new(StorageStrategy::Cow).with_file_view(file_view),
    )?;
    write_cow_branch_list(layout)?;
    Ok(())
}

fn prepare_cow_file_view(layout: &Layout) -> Result<FileViewStrategy> {
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

    Ok(FileViewStrategy::Copy)
}

fn ensure_cow_file_view_ready(layout: &Layout) -> Result<FileViewStrategy> {
    let mut manifest = read_site_manifest(layout)?;
    if let Some(file_view) = manifest.as_ref().and_then(|manifest| manifest.file_view) {
        ensure_cow_file_view_available(layout, file_view)?;
        return Ok(file_view);
    }

    let file_view = prepare_cow_file_view(layout)?;
    if let Some(existing) = manifest.as_mut() {
        if existing.strategy == StorageStrategy::Cow {
            existing.file_view = Some(file_view);
            write_site_manifest(layout, existing.clone())?;
        }
    }
    Ok(file_view)
}

fn ensure_cow_file_view_available(layout: &Layout, file_view: FileViewStrategy) -> Result<()> {
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
    if Path::new(&mounted_on) != mount {
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
fn detach_macos_apfs_sparsebundle_file_view(layout: &Layout, force: bool) -> Result<()> {
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
    println!("Remove site: rm -rf {}", shell_quote_path(&layout.work_dir));
    println!(
        "Attach again: forkpress storage mount --work-dir {}",
        shell_quote_path(&layout.work_dir)
    );
    Ok(())
}

#[cfg(not(target_os = "macos"))]
fn detach_macos_apfs_sparsebundle_file_view(_layout: &Layout, _force: bool) -> Result<()> {
    bail!("macOS APFS sparsebundle detach is only available on macOS")
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

fn ensure_cow_main_branch(
    layout: &Layout,
    runtime: &PortableRuntime,
    args: &InitArgs,
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
        &args.shared,
        &main_root,
        &args.site_title,
        args.admin_password.as_deref().unwrap_or("admin"),
    )?;
    Ok(())
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
        "runtime/bootstrap_cow_wp.php",
        [
            branch_root.as_os_str(),
            OsStr::new(site_title),
            layout
                .runtime_dir
                .join("vendor/sqlite-database-integration")
                .as_os_str(),
            layout
                .runtime_dir
                .join("wp-plugin/branchfs-wp.php")
                .as_os_str(),
            layout.debug_log.as_os_str(),
            OsStr::new(admin_password),
        ],
    )
}

fn cow_branch_root(layout: &Layout, branch: &str) -> PathBuf {
    layout.cow_branches_dir.join(branch)
}

fn validate_branch_name(branch: &str) -> Result<()> {
    let valid = !branch.is_empty()
        && branch.len() <= 63
        && branch
            .chars()
            .all(|ch| ch.is_ascii_alphanumeric() || ch == '_' || ch == '-');
    if !valid {
        bail!("invalid branch name: {branch}");
    }
    Ok(())
}

fn cow_branch_names(layout: &Layout) -> Result<Vec<String>> {
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
    names.sort_by(|a, b| {
        if a == "main" {
            std::cmp::Ordering::Less
        } else if b == "main" {
            std::cmp::Ordering::Greater
        } else {
            a.cmp(b)
        }
    });
    Ok(names)
}

fn plain_branch_names(branches_dir: &Path) -> Result<Vec<String>> {
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
    names.sort_by(|a, b| {
        if a == "main" {
            std::cmp::Ordering::Less
        } else if b == "main" {
            std::cmp::Ordering::Greater
        } else {
            a.cmp(b)
        }
    });
    Ok(names)
}

fn write_cow_branch_list(layout: &Layout) -> Result<()> {
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

fn create_cow_branch(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    branch: &str,
    from: &str,
) -> Result<()> {
    validate_branch_name(branch)?;
    validate_branch_name(from)?;
    let file_view = read_site_manifest(layout)?
        .and_then(|manifest| manifest.file_view)
        .unwrap_or(FileViewStrategy::Copy);
    let source = cow_branch_storage_root(layout, from, file_view);
    if !source.is_dir() {
        bail!("source branch does not exist: {from}");
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
        copy_tree_cow_required(&source, &dest)?;
    } else {
        copy_tree_cow(&source, &dest)?;
    }
    let branch_root = ensure_cow_public_branch_root(layout, branch, &dest, file_view)?;
    run_cow_bootstrap_script(layout, runtime, shared, &branch_root, "ForkPress", "admin")?;
    write_cow_branch_list(layout)?;
    let (root_host, port) = branchctl_url_hint(layout)
        .unwrap_or_else(|_| ("wp.localhost".to_string(), "18080".to_string()));
    println!("forkpress: COW cloned '{from}' -> '{branch}'");
    println!(
        "Visit http://{}.{root_host}:{port}/ to see this branch.",
        branch
    );
    Ok(())
}

fn ensure_cow_branch_exists(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    branch: &str,
    from: &str,
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
    create_cow_branch(layout, runtime, shared, branch, from)
}

fn show_cow_branch(layout: &Layout, branch: &str) -> Result<()> {
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

fn delete_cow_branch(layout: &Layout, branch: &str) -> Result<()> {
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

fn ensure_cas_bootstrapped(
    layout: &Layout,
    runtime: &PortableRuntime,
    args: &StartArgs,
) -> Result<()> {
    let init_args = InitArgs {
        shared: args.shared.clone(),
        strategy: StorageStrategy::Cas,
        site_title: args.site_title.clone(),
        root_host: args.root_host.clone(),
        admin_password: Some("admin".to_string()),
    };
    ensure_cas_main_branch(layout, runtime, &init_args)?;
    write_site_manifest_if_missing(layout, SiteManifest::new(StorageStrategy::Cas))?;
    write_cas_branch_list(layout)?;
    Ok(())
}

fn ensure_cas_main_branch(
    layout: &Layout,
    runtime: &PortableRuntime,
    args: &InitArgs,
) -> Result<()> {
    fs::create_dir_all(&layout.cas_dir)
        .with_context(|| format!("failed to create {}", layout.cas_dir.display()))?;
    fs::create_dir_all(&layout.cas_wp_root)
        .with_context(|| format!("failed to create {}", layout.cas_wp_root.display()))?;
    fs::create_dir_all(&layout.cas_branches_dir)
        .with_context(|| format!("failed to create {}", layout.cas_branches_dir.display()))?;

    if !cas_store::branch_exists(&layout.cas_store, "main").unwrap_or(false) {
        let staging = layout.cas_dir.join("staging-main");
        if staging.exists() {
            fs::remove_dir_all(&staging)
                .with_context(|| format!("failed to reset {}", staging.display()))?;
        }
        copy_tree_cow(&layout.runtime_dir.join("runtime/wp-src"), &staging)?;
        install_cas_managed_wp_files(layout, &staging)?;
        let report = cas_store::snapshot_branch(&layout.cas_store, &staging, "main")?;
        fs::remove_dir_all(&staging)
            .with_context(|| format!("failed to remove {}", staging.display()))?;
        println!(
            "  cas snapshot main ({} files, {} bytes)",
            report.files, report.bytes
        );
    }

    run_cas_bootstrap_script(
        layout,
        runtime,
        &args.shared,
        "main",
        &args.site_title,
        args.admin_password.as_deref().unwrap_or("admin"),
    )?;
    Ok(())
}

fn cas_branch_root(layout: &Layout, branch: &str) -> PathBuf {
    layout.cas_branches_dir.join(branch)
}

fn install_cas_managed_wp_files(layout: &Layout, branch_root: &Path) -> Result<()> {
    let wp_content = branch_root.join("wp-content");
    fs::create_dir_all(wp_content.join("plugins"))
        .with_context(|| format!("failed to create {}", wp_content.join("plugins").display()))?;
    fs::create_dir_all(wp_content.join("mu-plugins")).with_context(|| {
        format!(
            "failed to create {}",
            wp_content.join("mu-plugins").display()
        )
    })?;

    let plugin_dest = wp_content.join("plugins/sqlite-database-integration");
    if plugin_dest.exists() {
        fs::remove_dir_all(&plugin_dest)
            .with_context(|| format!("failed to reset {}", plugin_dest.display()))?;
    }
    copy_tree_cow(
        &layout
            .runtime_dir
            .join("vendor/sqlite-database-integration"),
        &plugin_dest,
    )?;

    fs::copy(
        layout.runtime_dir.join("wp-plugin/branchfs-wp.php"),
        wp_content.join("mu-plugins/branchfs-wp.php"),
    )
    .with_context(|| {
        format!(
            "failed to install {}",
            wp_content.join("mu-plugins/branchfs-wp.php").display()
        )
    })?;

    fs::write(wp_content.join("db.php"), cas_sqlite_dropin())
        .with_context(|| format!("failed to write {}", wp_content.join("db.php").display()))?;
    fs::write(branch_root.join("wp-config.php"), cas_wp_config()).with_context(|| {
        format!(
            "failed to write {}",
            branch_root.join("wp-config.php").display()
        )
    })
}

fn run_cas_bootstrap_script(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    branch: &str,
    site_title: &str,
    admin_password: &str,
) -> Result<()> {
    run_php_script(
        layout,
        runtime,
        shared,
        "runtime/bootstrap_cas_wp.php",
        [
            layout.cas_store.as_os_str(),
            layout.cas_wp_root.as_os_str(),
            layout.cas_branches_dir.as_os_str(),
            OsStr::new(branch),
            OsStr::new(site_title),
            layout.debug_log.as_os_str(),
            OsStr::new(admin_password),
        ],
    )
}

fn cas_sqlite_dropin() -> &'static str {
    r#"<?php
/**
 * ForkPress SQLite database drop-in for lazy CAS branches.
 */

define( 'SQLITE_DB_DROPIN_VERSION', '1.8.0' );

$sqlite_plugin_implementation_folder_path = __DIR__ . '/plugins/sqlite-database-integration';

if ( ! file_exists( $sqlite_plugin_implementation_folder_path . '/wp-includes/sqlite/db.php' ) ) {
	return;
}

if ( ! defined( 'DATABASE_TYPE' ) ) {
	define( 'DATABASE_TYPE', 'sqlite' );
}
if ( ! defined( 'DB_ENGINE' ) ) {
	define( 'DB_ENGINE', 'sqlite' );
}

require_once $sqlite_plugin_implementation_folder_path . '/wp-includes/sqlite/db.php';
"#
}

fn cas_wp_config() -> &'static str {
    r#"<?php
$forkpress_branch = getenv('FORKPRESS_BRANCH') ?: ($_SERVER['FORKPRESS_BRANCH'] ?? 'main');
if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,62}$/', $forkpress_branch)) {
    $forkpress_branch = 'main';
}

$forkpress_db_base = getenv('FORKPRESS_CAS_DB_BASE');
if (!$forkpress_db_base) {
    $forkpress_db_base = dirname(__DIR__) . '/branches';
}
$forkpress_db_dir = rtrim($forkpress_db_base, "/\\") . DIRECTORY_SEPARATOR . $forkpress_branch;
if (!is_dir($forkpress_db_dir)) {
    @mkdir($forkpress_db_dir, 0755, true);
}

if (!defined('FQDB')) {
    define('FQDB',    $forkpress_db_dir . DIRECTORY_SEPARATOR . '.ht.sqlite');
    define('DB_DIR',  $forkpress_db_dir);
    define('DB_FILE', '.ht.sqlite');
}
define('DB_NAME', 'forkpress');
define('DB_USER', 'forkpress');
define('DB_PASSWORD', 'forkpress');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wp_';

define('AUTH_KEY',         'forkpress-cas-k1-xxxxxxxxxxxxxxxx');
define('SECURE_AUTH_KEY',  'forkpress-cas-k2-xxxxxxxxxxxxxxxx');
define('LOGGED_IN_KEY',    'forkpress-cas-k3-xxxxxxxxxxxxxxxx');
define('NONCE_KEY',        'forkpress-cas-k4-xxxxxxxxxxxxxxxx');
define('AUTH_SALT',        'forkpress-cas-s1-xxxxxxxxxxxxxxxx');
define('SECURE_AUTH_SALT', 'forkpress-cas-s2-xxxxxxxxxxxxxxxx');
define('LOGGED_IN_SALT',   'forkpress-cas-s3-xxxxxxxxxxxxxxxx');
define('NONCE_SALT',       'forkpress-cas-s4-xxxxxxxxxxxxxxxx');

define('WP_DEBUG', true);
define('WP_DEBUG_LOG', getenv('FORKPRESS_CAS_DEBUG_LOG') ?: '/tmp/forkpress-cas-wp-debug.log');
define('WP_DEBUG_DISPLAY', false);
define('DISALLOW_FILE_MODS', true);
define('WP_AUTO_UPDATE_CORE', false);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
if (!defined('DISABLE_WP_CRON')) {
    define('DISABLE_WP_CRON', true);
}

if (isset($_SERVER['HTTP_HOST'])) {
    define('WP_HOME',    'http://' . $_SERVER['HTTP_HOST']);
    define('WP_SITEURL', 'http://' . $_SERVER['HTTP_HOST']);
}

if (!defined('ABSPATH')) {
    define('ABSPATH', 'branchfs://' . $forkpress_branch . '/');
}

require_once ABSPATH . 'wp-settings.php';
"#
}

fn cas_branch_names(layout: &Layout) -> Result<Vec<String>> {
    plain_branch_names(&layout.cas_branches_dir)
}

fn write_cas_branch_list(layout: &Layout) -> Result<()> {
    fs::create_dir_all(&layout.cas_dir)
        .with_context(|| format!("failed to create {}", layout.cas_dir.display()))?;
    let mut out = String::new();
    for name in cas_branch_names(layout)? {
        out.push_str(&name);
        out.push('\n');
    }
    fs::write(&layout.cas_branch_list, out)
        .with_context(|| format!("failed to write {}", layout.cas_branch_list.display()))
}

fn create_cas_branch(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    branch: &str,
    from: &str,
) -> Result<()> {
    validate_branch_name(branch)?;
    validate_branch_name(from)?;
    if !cas_store::branch_exists(&layout.cas_store, from).unwrap_or(false) {
        bail!("source branch does not exist: {from}");
    }
    if cas_store::branch_exists(&layout.cas_store, branch).unwrap_or(false) {
        bail!("branch already exists: {branch}");
    }

    fs::create_dir_all(&layout.cas_branches_dir)
        .with_context(|| format!("failed to create {}", layout.cas_branches_dir.display()))?;
    fs::create_dir_all(&layout.cas_wp_root)
        .with_context(|| format!("failed to create {}", layout.cas_wp_root.display()))?;

    let source = cas_branch_root(layout, from);
    let dest = cas_branch_root(layout, branch);
    if dest.exists() {
        bail!("branch already exists: {branch}");
    }

    let branch_report = cas_store::clone_branch_manifest(&layout.cas_store, from, branch)?;
    if source.is_dir() {
        copy_tree_cow(&source, &dest)?;
    } else {
        fs::create_dir_all(&dest)
            .with_context(|| format!("failed to create {}", dest.display()))?;
    }
    run_cas_bootstrap_script(layout, runtime, shared, branch, "ForkPress", "admin")?;
    write_cas_branch_list(layout)?;

    let (root_host, port) = branchctl_url_hint(layout)
        .unwrap_or_else(|_| ("wp.localhost".to_string(), "18080".to_string()));
    println!("forkpress: cas cloned '{from}' -> '{branch}'");
    println!(
        "  shared manifest: {} files, {} bytes",
        branch_report.files, branch_report.bytes
    );
    println!(
        "Visit http://{}.{root_host}:{port}/ to see this branch.",
        branch
    );
    Ok(())
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum TreeCloneMode {
    AllowCopyFallback,
    RequireCow,
}

fn copy_tree_cow(source: &Path, dest: &Path) -> Result<()> {
    copy_tree_cow_mode(source, dest, TreeCloneMode::AllowCopyFallback)
}

fn copy_tree_cow_required(source: &Path, dest: &Path) -> Result<()> {
    copy_tree_cow_mode(source, dest, TreeCloneMode::RequireCow)
}

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

fn probe_reflink_dir(dir: &Path) -> Result<bool> {
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
        fs::write(&source, b"canonical")
            .with_context(|| format!("failed to write {}", source.display()))?;

        if try_clone_file(&source, &dest).is_err() {
            return Ok(false);
        }

        fs::write(&dest, b"branch")
            .with_context(|| format!("failed to write {}", dest.display()))?;
        let canonical =
            fs::read(&source).with_context(|| format!("failed to read {}", source.display()))?;
        Ok(canonical == b"canonical")
    })();

    let _ = fs::remove_dir_all(&probe_dir);
    result
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
    use std::ffi::CString;
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

#[cfg(not(any(target_os = "linux", target_os = "macos")))]
fn try_clone_file(_source: &Path, _dest: &Path) -> Result<()> {
    bail!("platform file clone unsupported")
}

fn ensure_bootstrapped(layout: &Layout, runtime: &PortableRuntime, args: &StartArgs) -> Result<()> {
    write_site_manifest_if_missing(layout, SiteManifest::new(StorageStrategy::Branchfs))?;

    if !layout.site_fp.exists() {
        run_php_script(
            layout,
            runtime,
            &args.shared,
            "scripts/init_db.php",
            [layout.site_fp.as_os_str()],
        )?;
    }

    if !layout.bootstrap_marker.exists() {
        run_php_script(
            layout,
            runtime,
            &args.shared,
            "scripts/import_wp.php",
            [
                layout.runtime_dir.join("runtime/wp-src").as_os_str(),
                layout.site_fp.as_os_str(),
                OsStr::new("main"),
            ],
        )?;

        run_php_script(
            layout,
            runtime,
            &args.shared,
            "runtime/bootstrap_wp.php",
            [
                layout.site_fp.as_os_str(),
                layout.wp_root.as_os_str(),
                OsStr::new(&args.site_title),
                layout
                    .runtime_dir
                    .join("wp-plugin/branchfs-wp.php")
                    .as_os_str(),
                layout.debug_log.as_os_str(),
            ],
        )?;

        File::create(&layout.bootstrap_marker)?;
        write_managed_files_marker(layout)?;
    } else {
        refresh_managed_wp_files_if_needed(layout, runtime, args)?;
    }

    run_branchctl_migrations_quiet(layout, runtime, &args.shared)?;

    Ok(())
}

fn managed_files_marker_contents() -> String {
    format!("forkpress-managed-files {RUNTIME_BUNDLE_ID}\n")
}

fn write_managed_files_marker(layout: &Layout) -> Result<()> {
    fs::write(
        &layout.managed_files_marker,
        managed_files_marker_contents(),
    )
    .with_context(|| format!("failed to write {}", layout.managed_files_marker.display()))
}

fn refresh_managed_wp_files_if_needed(
    layout: &Layout,
    runtime: &PortableRuntime,
    args: &StartArgs,
) -> Result<()> {
    let expected = managed_files_marker_contents();
    let current = fs::read_to_string(&layout.managed_files_marker)
        .map(|contents| contents == expected)
        .unwrap_or(false);
    if current {
        return Ok(());
    }

    run_php_script(
        layout,
        runtime,
        &args.shared,
        "runtime/refresh_wp_files.php",
        [
            layout.site_fp.as_os_str(),
            layout.wp_root.as_os_str(),
            OsStr::new(&args.site_title),
            layout
                .runtime_dir
                .join("wp-plugin/branchfs-wp.php")
                .as_os_str(),
            layout.debug_log.as_os_str(),
        ],
    )?;
    write_managed_files_marker(layout)?;
    Ok(())
}

fn run_branchctl_migrations_quiet(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
) -> Result<()> {
    let mut command = php_base_command(layout, runtime, shared);
    command
        .arg(layout.runtime_dir.join("scripts/branchctl.php"))
        .arg("list")
        .env("BRANCHFS_DB", &layout.site_fp)
        .env("BRANCHFS_SQLITE_WP_DB", &layout.site_fp)
        .env("FORKPRESS_LOCAL_CTL", "1");

    let output = command
        .output()
        .context("failed to run branchctl migration check")?;
    if !output.status.success() {
        write_filtered_output(&output.stdout, &output.stderr)?;
        bail!(
            "branchctl migration check exited with status {}",
            output.status
        );
    }

    Ok(())
}

fn start_php_server(
    layout: &Layout,
    runtime: &PortableRuntime,
    args: &StartArgs,
    workers: usize,
) -> Result<ChildGuard> {
    let log = OpenOptions::new()
        .create(true)
        .append(true)
        .open(&layout.php_server_log)?;
    let log_err = log.try_clone()?;

    let mut command = php_base_command(layout, runtime, &args.shared);
    command
        .arg("-d")
        .arg("log_errors=On")
        .arg("-d")
        .arg(format!("error_log={}", layout.php_error_log.display()))
        .arg("-d")
        .arg("post_max_size=100M")
        .arg("-d")
        .arg("upload_max_filesize=100M")
        .arg("-S")
        .arg(format!("{}:{}", args.host, args.port))
        .arg("-t")
        .arg(&layout.wp_root)
        .arg(layout.runtime_dir.join("runtime/router.php"))
        .env("BRANCHFS_DB", &layout.site_fp)
        .env("BRANCHFS_SQLITE_WP_DB", &layout.site_fp)
        .env("BRANCHFS_WP_ROOT", &layout.wp_root)
        .env("BRANCHFS_ROOT_HOST", &args.root_host)
        .stdout(Stdio::from(log))
        .stderr(Stdio::from(log_err));

    // PHP 7.4+ supports PHP_CLI_SERVER_WORKERS for multi-process handling of
    // concurrent HTTP requests on the built-in server. Without it, a single
    // slow request (plugin init, search, wp-cron) serializes every other
    // request on the same server. Only set the env var when >1 so the
    // single-worker debug path is byte-identical to the pre-workers behaviour.
    // PHP_CLI_SERVER_WORKERS is a Linux/macOS-only feature — on Windows the
    // built-in server simply ignores the variable, which is fine.
    if workers > 1 {
        command.env("PHP_CLI_SERVER_WORKERS", workers.to_string());
    }

    let child = command
        .spawn()
        .context("failed to start bundled php server")?;

    let mut guard = ChildGuard {
        name: "php server",
        child,
    };

    wait_for_tcp(&args.host, args.port, Duration::from_secs(30))
        .with_context(|| format!("php server did not open {}:{}", args.host, args.port))?;

    if let Some(status) = guard.try_wait()? {
        bail!(
            "php server exited early with status {status}. Check {}",
            layout.php_server_log.display()
        );
    }

    Ok(guard)
}

fn start_cow_php_server(
    layout: &Layout,
    runtime: &PortableRuntime,
    args: &StartArgs,
    workers: usize,
) -> Result<ChildGuard> {
    let log = OpenOptions::new()
        .create(true)
        .append(true)
        .open(&layout.php_server_log)?;
    let log_err = log.try_clone()?;

    let mut command = php_base_command(layout, runtime, &args.shared);
    command
        .arg("-d")
        .arg("log_errors=On")
        .arg("-d")
        .arg(format!("error_log={}", layout.php_error_log.display()))
        .arg("-d")
        .arg("post_max_size=100M")
        .arg("-d")
        .arg("upload_max_filesize=100M")
        .arg("-S")
        .arg(format!("{}:{}", args.host, args.port))
        .arg("-t")
        .arg(&layout.cow_branches_dir)
        .arg(layout.runtime_dir.join("runtime/router_cow.php"))
        .env("FORKPRESS_BRANCHES_DIR", &layout.cow_branches_dir)
        .env("FORKPRESS_COW_DIR", &layout.cow_dir)
        .env("FORKPRESS_COW_BRANCHES_DIR", &layout.cow_branches_dir)
        .env("FORKPRESS_COW_GIT_DIR", &layout.cow_git_dir)
        .env("FORKPRESS_BRANCH_LIST", &layout.cow_branch_list)
        .env("FORKPRESS_PLAIN_STRATEGY", "cow")
        .env("FORKPRESS_ROOT_HOST", &args.root_host)
        .stdout(Stdio::from(log))
        .stderr(Stdio::from(log_err));

    if workers > 1 {
        command.env("PHP_CLI_SERVER_WORKERS", workers.to_string());
    }

    let child = command
        .spawn()
        .context("failed to start bundled php server")?;

    let mut guard = ChildGuard {
        name: "php server",
        child,
    };

    wait_for_tcp(&args.host, args.port, Duration::from_secs(30))
        .with_context(|| format!("php server did not open {}:{}", args.host, args.port))?;

    if let Some(status) = guard.try_wait()? {
        bail!(
            "php server exited early with status {status}. Check {}",
            layout.php_server_log.display()
        );
    }

    Ok(guard)
}

fn start_cas_php_server(
    layout: &Layout,
    runtime: &PortableRuntime,
    args: &StartArgs,
    workers: usize,
) -> Result<ChildGuard> {
    let log = OpenOptions::new()
        .create(true)
        .append(true)
        .open(&layout.php_server_log)?;
    let log_err = log.try_clone()?;

    let mut command = php_base_command(layout, runtime, &args.shared);
    command
        .arg("-d")
        .arg("log_errors=On")
        .arg("-d")
        .arg(format!("error_log={}", layout.php_error_log.display()))
        .arg("-d")
        .arg("post_max_size=100M")
        .arg("-d")
        .arg("upload_max_filesize=100M")
        .arg("-S")
        .arg(format!("{}:{}", args.host, args.port))
        .arg("-t")
        .arg(&layout.cas_wp_root)
        .arg(layout.runtime_dir.join("runtime/router_cas.php"))
        .env("FORKPRESS_CAS_STORE", &layout.cas_store)
        .env("FORKPRESS_CAS_WP_ROOT", &layout.cas_wp_root)
        .env("FORKPRESS_CAS_DB_BASE", &layout.cas_branches_dir)
        .env("FORKPRESS_CAS_DEBUG_LOG", &layout.debug_log)
        .env("FORKPRESS_BRANCH_LIST", &layout.cas_branch_list)
        .env("FORKPRESS_ROOT_HOST", &args.root_host)
        .stdout(Stdio::from(log))
        .stderr(Stdio::from(log_err));

    if workers > 1 {
        command.env("PHP_CLI_SERVER_WORKERS", workers.to_string());
    }

    let child = command
        .spawn()
        .context("failed to start bundled php server")?;

    let mut guard = ChildGuard {
        name: "php server",
        child,
    };
    wait_for_tcp(&args.host, args.port, Duration::from_secs(30))
        .with_context(|| format!("php server did not open {}:{}", args.host, args.port))?;
    if let Some(status) = guard.try_wait()? {
        bail!(
            "php server exited during startup with status {status}. Check {}",
            layout.php_server_log.display()
        );
    }
    Ok(guard)
}

fn php_base_command(_layout: &Layout, runtime: &PortableRuntime, shared: &SharedPaths) -> Command {
    // branchfs is compiled into the php binary as a builtin extension
    // (see scripts/build-dist.sh), so no -d extension=... flag is needed.
    let mut command = php_command(runtime, shared);
    command
        .arg("-d")
        .arg("display_errors=Off")
        .arg("-d")
        .arg("display_startup_errors=Off");
    command
}

fn run_php_script<I, S>(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
    script_rel: &str,
    args: I,
) -> Result<()>
where
    I: IntoIterator<Item = S>,
    S: AsRef<OsStr>,
{
    let mut command = php_base_command(layout, runtime, shared);
    command.arg(layout.runtime_dir.join(script_rel));
    for arg in args {
        command.arg(arg);
    }
    command.env("BRANCHFS_SQLITE_WP_DB", &layout.site_fp);

    let output = command
        .output()
        .with_context(|| format!("failed to run bundled php script {}", script_rel))?;

    write_filtered_output(&output.stdout, &output.stderr)?;

    if !output.status.success() {
        bail!("{script_rel} exited with status {}", output.status);
    }

    Ok(())
}

fn php_command(runtime: &PortableRuntime, shared: &SharedPaths) -> Command {
    if let Some(php_bin) = &shared.php_bin {
        return Command::new(php_bin);
    }
    // Static-php-cli produces a self-contained php binary (static on Linux,
    // only linked against libSystem on macOS). No loader shim needed.
    Command::new(&runtime.php)
}

fn write_filtered_output(stdout: &[u8], stderr: &[u8]) -> Result<()> {
    let mut out = std::io::stdout().lock();
    out.write_all(stdout)?;

    let stderr_text = String::from_utf8_lossy(stderr);
    for line in stderr_text.lines() {
        if !line.contains(STARTUP_WARNING_FILTER) {
            writeln!(std::io::stderr().lock(), "{line}")?;
        }
    }
    Ok(())
}

fn ensure_git_available() -> Result<()> {
    let status = Command::new("git")
        .arg("--version")
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .context("failed to execute `git --version`")?;
    if !status.success() {
        bail!("git is required for this command");
    }
    Ok(())
}

fn ensure_git_repository(repo: &std::path::Path) -> Result<()> {
    let status = Command::new("git")
        .arg("rev-parse")
        .arg("--git-dir")
        .current_dir(repo)
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .with_context(|| format!("failed to inspect git checkout at {}", repo.display()))?;
    if status.success() {
        Ok(())
    } else {
        bail!("not a git checkout: {}", repo.display());
    }
}

fn run_git<I, S>(cwd: Option<&std::path::Path>, args: I) -> Result<()>
where
    I: IntoIterator<Item = S>,
    S: AsRef<OsStr>,
{
    let args_vec: Vec<OsString> = args
        .into_iter()
        .map(|arg| arg.as_ref().to_owned())
        .collect();
    let mut command = Command::new("git");
    command
        .args(&args_vec)
        .stdin(Stdio::inherit())
        .stdout(Stdio::inherit())
        .stderr(Stdio::inherit());
    if let Some(dir) = cwd {
        command.current_dir(dir);
    }

    let status = command.status().with_context(|| {
        format!(
            "failed to run git {}",
            args_vec
                .iter()
                .map(|arg| arg.to_string_lossy().into_owned())
                .collect::<Vec<_>>()
                .join(" ")
        )
    })?;
    if !status.success() {
        bail!("git exited with status {status}");
    }
    Ok(())
}

fn git_stdout<I, S>(cwd: &std::path::Path, args: I) -> Result<String>
where
    I: IntoIterator<Item = S>,
    S: AsRef<OsStr>,
{
    let args_vec: Vec<OsString> = args
        .into_iter()
        .map(|arg| arg.as_ref().to_owned())
        .collect();
    let output = Command::new("git")
        .args(&args_vec)
        .current_dir(cwd)
        .output()
        .with_context(|| {
            format!(
                "failed to run git {}",
                args_vec
                    .iter()
                    .map(|arg| arg.to_string_lossy().into_owned())
                    .collect::<Vec<_>>()
                    .join(" ")
            )
        })?;
    if !output.status.success() {
        bail!(
            "git {} exited with status {}",
            args_vec
                .iter()
                .map(|arg| arg.to_string_lossy().into_owned())
                .collect::<Vec<_>>()
                .join(" "),
            output.status
        );
    }
    Ok(String::from_utf8_lossy(&output.stdout).trim().to_string())
}

fn git_ref_exists(repo: &std::path::Path, reference: &str) -> Result<bool> {
    let status = Command::new("git")
        .arg("rev-parse")
        .arg("--verify")
        .arg("--quiet")
        .arg(reference)
        .current_dir(repo)
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .with_context(|| format!("failed to inspect git ref {reference}"))?;
    Ok(status.success())
}

fn wait_for_tcp(host: &str, port: u16, timeout: Duration) -> Result<()> {
    let deadline = Instant::now() + timeout;
    while Instant::now() < deadline {
        if tcp_port_open(host, port) {
            return Ok(());
        }
        thread::sleep(Duration::from_millis(250));
    }
    bail!("timed out waiting for {host}:{port}");
}

fn tcp_port_open(host: &str, port: u16) -> bool {
    let addrs = (host, port).to_socket_addrs();
    let Ok(addrs) = addrs else {
        return false;
    };

    addrs
        .into_iter()
        .any(|addr| TcpStream::connect_timeout(&addr, Duration::from_millis(250)).is_ok())
}

#[cfg(test)]
mod git_helper_tests {
    use super::*;
    use clap::error::ErrorKind;

    #[test]
    fn short_version_flag_prints_version() {
        let err = Cli::try_parse_from(["forkpress", "-v"]).unwrap_err();
        assert_eq!(err.kind(), ErrorKind::DisplayVersion);
    }

    #[test]
    fn long_version_flag_prints_version() {
        let err = Cli::try_parse_from(["forkpress", "--version"]).unwrap_err();
        assert_eq!(err.kind(), ErrorKind::DisplayVersion);
    }

    #[test]
    fn version_flag_does_not_break_subcommand_parsing() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "init",
            "--work-dir",
            ".forkpress",
            "--admin-password",
            "admin",
        ])
        .unwrap();
        assert!(matches!(cli.command, Commands::Init(_)));
    }

    #[test]
    fn commit_message_mentions_branch() {
        assert_eq!(
            default_commit_message("agent-3"),
            "forkpress: update agent-3"
        );
    }

    #[test]
    fn default_remote_points_at_local_server() {
        assert_eq!(default_git_remote(), "http://wp.localhost:18080/site.git");
    }

    #[test]
    fn parses_git_branch_create_args() {
        let args = vec![
            "--from".to_string(),
            "main".to_string(),
            "--user".to_string(),
            "admin".to_string(),
            "--password".to_string(),
            "admin".to_string(),
        ];
        let parsed = git_branch_create_args(&args).unwrap();
        assert_eq!(parsed.from, "main");
        assert_eq!(parsed.auth.user.as_deref(), Some("admin"));
        assert_eq!(parsed.auth.password.as_deref(), Some("admin"));
    }

    #[test]
    fn registry_fields_round_trip_control_chars() {
        let value = "dir\\with\ttabs\nand\rreturns";
        assert_eq!(
            unescape_registry_field(&escape_registry_field(value)),
            value
        );
    }

    #[test]
    fn registry_record_lines_round_trip_child_pid() {
        let record = ServerRecord {
            pid: 123,
            child_pid: Some(456),
            work_dir: PathBuf::from("/tmp/fork press"),
            host: "127.0.0.1".to_string(),
            port: 18080,
            root_host: "wp.localhost".to_string(),
            log: PathBuf::from("/tmp/fork press/logs/server.log"),
        };
        let line = format_server_record_line(&record);
        assert_eq!(parse_server_record_line(&line), Some(record));
    }

    #[test]
    fn registry_record_parser_accepts_legacy_lines_without_child_pid() {
        let line = "123\t/tmp/forkpress\t127.0.0.1\t18080\twp.localhost\t/tmp/forkpress/log";
        let record = parse_server_record_line(line).unwrap();
        assert_eq!(record.pid, 123);
        assert_eq!(record.child_pid, None);
        assert_eq!(record.port, 18080);
    }

    #[test]
    fn shell_quote_handles_spaces_and_single_quotes() {
        assert_eq!(shell_quote("/tmp/forkpress"), "/tmp/forkpress");
        assert_eq!(
            shell_quote("/tmp/fork press/o'clock"),
            "'/tmp/fork press/o'\\''clock'"
        );
    }
}

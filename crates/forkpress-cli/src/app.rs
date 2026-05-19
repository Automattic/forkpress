use anyhow::{Context, Result, anyhow, bail};
use clap::{ArgAction, Args, Parser, Subcommand, ValueEnum};
#[cfg(feature = "dev-experiments")]
use std::ffi::OsStr;
use std::ffi::OsString;
use std::fs::{self, File, OpenOptions};
use std::io::{Read, Seek, SeekFrom, Write};
use std::path::{Path, PathBuf};
use std::process::{Command, Stdio};
use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering};
use std::thread;
use std::time::{Duration, Instant};
#[cfg(test)]
use std::time::{SystemTime, UNIX_EPOCH};

#[cfg(test)]
use forkpress_core::path_exists_no_follow;
#[cfg(feature = "dev-experiments")]
use forkpress_core::validate_branch_name;
use forkpress_core::{
    FileViewStrategy, Layout, SharedPaths, SiteManifest, StorageStrategy, absolutize,
    initialized_storage_strategy, read_site_manifest, require_initialized_strategy,
    write_site_manifest, write_site_manifest_if_missing,
};
use forkpress_git::{
    add_agent_worktree, default_commit_message, default_git_remote, ensure_git_available,
    ensure_git_identity, ensure_git_repository, git_stdout, run_git, sync_pushed_branch,
};
#[cfg(feature = "dev-experiments")]
use forkpress_runtime::run_php_script;
use forkpress_runtime::write_filtered_output;
use forkpress_runtime::{
    PortableRuntime, php_base_command, prepare_runtime as prepare_embedded_runtime,
};
use forkpress_server::{
    ChildGuard, ServerRecord, ServerStartInfo, live_server_records, read_pid_file,
    register_running_server, running_record_for_work_dir, stop_server_record, tcp_port_open,
    wait_for_tcp,
};
#[cfg(test)]
use forkpress_server::{
    escape_registry_field, format_server_record_line, parse_server_record_line,
    unescape_registry_field,
};
use forkpress_storage::{
    CowMergeAuditQuery, CowSiteInit, RemoteBranchOptions, RemoteSiteAdd, add_remote_site,
    apply_reviewed_cow_merge_resolutions, branch_remote_site,
    compact_macos_apfs_sparsebundle_file_view, cow_branch_exists, cow_branch_names,
    cow_branch_root, create_cow_branch, delete_cow_branch, detach_linux_xfs_loop_file_view,
    detach_macos_apfs_sparsebundle_file_view, ensure_cow_branch_exists, ensure_cow_file_view_ready,
    ensure_cow_main_branch, inspect_cow_merge_audit, list_remote_sites, lock_cow_lifecycle,
    lock_cow_operations, merge_cow_branch, prepare_cow_file_view, print_cow_storage_status,
    print_linux_xfs_loop_storage_status, print_macos_cow_storage_status, probe_reflink_dir,
    probe_remote_site, record_cow_plugin_driver_resolution, record_cow_plugin_validator_conflicts,
    recover_cow_merge_crash, reset_cow_branch, resolve_cow_merge_conflict,
    resolve_cow_merge_conflict_key, revalidate_cow_merge_reviews, review_cow_merge_audit_record,
    review_cow_merge_conflict_key, run_cow_plugin_driver, run_cow_plugin_validator,
    sanitize_remote_site_name, show_cow_branch, write_cow_branch_list, write_cow_strategy_notes,
};
#[cfg(feature = "dev-experiments")]
use forkpress_storage::{copy_tree_cow, plain_branch_names};
#[cfg(test)]
use forkpress_storage::{
    cow_stale_operation_entries, invalidate_cow_git_ref, rollback_failed_reset_publish,
};

#[cfg(feature = "dev-experiments")]
use forkpress_cas_store as cas_store;
#[cfg(feature = "dev-experiments")]
#[path = "../../../experiments/zfs-engine/zfs_engine.rs"]
mod zfs_engine;

const RUNTIME_BUNDLE: &[u8] = include_bytes!(env!("FORKPRESS_RUNTIME_BUNDLE"));
const RUNTIME_BUNDLE_ID: &str = env!("FORKPRESS_RUNTIME_BUNDLE_ID");
const FORKPRESS_COW_PARENT_LIFECYCLE_LOCK: &str = "FORKPRESS_COW_PARENT_LIFECYCLE_LOCK";
const REMOTE_CLONE_SSH_CONNECT_TIMEOUT_SECONDS: u16 = 10;

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
    /// Manage remote-site caches and branch from them.
    Remote(RemoteArgs),
    /// Manage authentication users (add/list/remove/verify/auth-enabled).
    #[cfg(feature = "dev-experiments")]
    User(UserPassthrough),
    /// Show WordPress, PHP, server, and maintenance logs.
    Logs(LogsArgs),
    /// Inspect local ForkPress environment and storage capabilities.
    Doctor(DoctorArgs),
    /// Inspect, mount, and detach mount-backed site storage.
    Storage(StorageArgs),
    /// Consistent hot-copy of a running .fp file via SQLite VACUUM INTO.
    #[cfg(feature = "dev-experiments")]
    Backup(BackupArgs),
    /// Write a .fp file to a portable directory tree (files + SQL + manifest).
    #[cfg(feature = "dev-experiments")]
    Export(ExportArgs),
    /// Rebuild a .fp from a directory tree produced by `forkpress export`.
    #[cfg(feature = "dev-experiments")]
    Import(ImportArgs),
    /// Inspect and smoke-test the embedded ZFS engine.
    #[cfg(feature = "dev-experiments")]
    #[command(hide = true)]
    Zfs(ZfsArgs),
}

#[derive(Args, Debug, Clone)]
struct InitArgs {
    #[command(flatten)]
    shared: SharedPaths,

    /// Storage strategy for this site. Existing sites keep their initialized
    /// strategy; this flag is only used by `forkpress init`.
    #[cfg(feature = "dev-experiments")]
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

#[cfg(feature = "dev-experiments")]
#[derive(Args, Debug, Clone)]
struct UserPassthrough {
    #[command(flatten)]
    shared: SharedPaths,

    #[arg(trailing_var_arg = true, allow_hyphen_values = true, action = ArgAction::Append)]
    args: Vec<String>,
}

#[cfg(feature = "dev-experiments")]
#[derive(Args, Debug, Clone)]
struct BackupArgs {
    #[command(flatten)]
    shared: SharedPaths,
    /// Source .fp file (defaults to the site.fp in --work-dir).
    source: Option<PathBuf>,
    /// Destination .fp path (must not exist).
    dest: PathBuf,
}

#[cfg(feature = "dev-experiments")]
#[derive(Args, Debug, Clone)]
struct ExportArgs {
    #[command(flatten)]
    shared: SharedPaths,
    /// Source .fp file (defaults to the site.fp in --work-dir).
    source: Option<PathBuf>,
    /// Output directory (created if missing; must be empty).
    output_dir: PathBuf,
}

#[cfg(feature = "dev-experiments")]
#[derive(Args, Debug, Clone)]
struct ImportArgs {
    #[command(flatten)]
    shared: SharedPaths,
    /// Directory produced by `forkpress export`.
    input_dir: PathBuf,
    /// Destination .fp path (must not exist).
    dest: PathBuf,
}

#[cfg(feature = "dev-experiments")]
#[derive(Args, Debug, Clone)]
struct ZfsArgs {
    #[command(subcommand)]
    command: ZfsCommand,
}

#[cfg(feature = "dev-experiments")]
#[derive(Subcommand, Debug, Clone)]
enum ZfsCommand {
    /// Create/import/export a file-backed pool, then snapshot and clone a dataset.
    Smoke(ZfsSmokeArgs),
}

#[cfg(feature = "dev-experiments")]
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
    /// Compact detached macOS APFS sparsebundle storage.
    Compact(StorageCompactArgs),
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

#[derive(Args, Debug, Clone)]
struct StorageCompactArgs {
    /// ForkPress site state directory.
    #[arg(long, default_value = ".forkpress")]
    work_dir: PathBuf,

    /// Do not stop this site's ForkPress server before compacting storage.
    #[arg(long)]
    keep_server: bool,

    /// Seconds to wait when stopping the matching site server.
    #[arg(long, default_value_t = 10)]
    timeout: u64,

    /// Force detach before compacting. Use only after normal detach reports that the mount is busy.
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

#[cfg(feature = "dev-experiments")]
fn default_storage_strategy() -> StorageStrategy {
    StorageStrategy::Cow
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
    #[cfg(feature = "dev-experiments")]
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
#[cfg(feature = "dev-experiments")]
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

#[cfg(all(test, feature = "dev-experiments"))]
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
#[command(disable_help_flag = true)]
struct BranchPassthrough {
    #[command(flatten)]
    shared: SharedPaths,

    #[arg(trailing_var_arg = true, allow_hyphen_values = true, action = ArgAction::Append)]
    args: Vec<String>,
}

#[derive(Args, Debug, Clone)]
struct RemoteArgs {
    #[command(flatten)]
    shared: SharedPaths,

    #[command(subcommand)]
    command: RemoteCommand,
}

#[derive(Subcommand, Debug, Clone)]
enum RemoteCommand {
    /// Thin-clone a remote WordPress root over SSH and optionally branch from it.
    Clone(RemoteCloneArgs),
    /// Register an existing remote-site cache.
    Add(RemoteAddArgs),
    /// List registered remote-site caches.
    List,
    /// Show cache details for one registered remote site.
    Show(RemoteShowArgs),
    /// Create a normal ForkPress COW branch from a registered remote cache.
    Branch(RemoteBranchArgs),
}

#[derive(Args, Debug, Clone)]
struct RemoteCloneArgs {
    /// Local name for this remote-site cache.
    name: String,

    /// Remote SSH target, e.g. user@example.com.
    #[arg(long)]
    ssh: String,

    /// SSH private key to use for rsync, e.g. ~/.ssh/id_ed25519.
    #[arg(long = "ssh-key")]
    ssh_key: Option<PathBuf>,

    /// SSH port to use for rsync.
    #[arg(long = "ssh-port")]
    ssh_port: Option<u16>,

    /// Remote WordPress root path.
    #[arg(long = "path")]
    remote_path: String,

    /// Branch to create from the synced cache after cloning.
    #[arg(long)]
    branch: Option<String>,

    /// Production site URL to record.
    #[arg(long = "remote-url", alias = "url")]
    remote_url: Option<String>,

    /// Local URL hint to record.
    #[arg(long = "local-url")]
    local_url: Option<String>,

    /// Include wp-content/uploads in the initial boot sync.
    #[arg(long)]
    include_uploads: bool,

    /// Download the full WordPress tree instead of the boot-critical subset.
    #[arg(long)]
    full_sync: bool,

    /// Additional rsync exclude pattern. Can be passed more than once.
    #[arg(long = "exclude")]
    excludes: Vec<String>,

    /// Do not delete stale local cache files that disappeared remotely.
    #[arg(long)]
    no_delete: bool,

    /// Replace an existing remote-site registration and, with --branch, recreate the local branch from the remote cache.
    #[arg(long)]
    force: bool,
}

#[derive(Args, Debug, Clone)]
struct RemoteAddArgs {
    /// Local name for this remote-site cache.
    name: String,

    /// Existing materialized WordPress cache root. This directory must contain wp-load.php before it can be branched.
    #[arg(long = "cache-root")]
    cache_root: Option<PathBuf>,

    /// wp-cow clone name or clone directory. Uses its file-cache/mirror directory without deleting or moving it.
    #[arg(long = "wp-cow-clone")]
    wp_cow_clone: Option<String>,

    /// wp-cow state directory. Defaults to WPCOW_HOME or ~/.wp-cow when --wp-cow-clone is a name.
    #[arg(long = "wp-cow-state-dir")]
    wp_cow_state_dir: Option<PathBuf>,

    /// Remote SSH target to record. This is metadata only; ForkPress does not modify the remote.
    #[arg(long)]
    ssh: Option<String>,

    /// Remote WordPress path to record.
    #[arg(long = "path")]
    remote_path: Option<String>,

    /// Production site URL to record.
    #[arg(long = "remote-url")]
    remote_url: Option<String>,

    /// Local URL hint to record.
    #[arg(long = "local-url")]
    local_url: Option<String>,

    /// Replace an existing remote-site registration without touching the cache.
    #[arg(long)]
    force: bool,
}

#[derive(Args, Debug, Clone)]
struct RemoteShowArgs {
    /// Registered remote-site name.
    name: String,
}

#[derive(Args, Debug, Clone)]
struct RemoteBranchArgs {
    /// Registered remote-site name.
    remote: String,

    /// Branch to create from the remote cache.
    branch: String,
}

pub(crate) fn main() {
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
        Commands::Remote(args) => remote_command(args),
        #[cfg(feature = "dev-experiments")]
        Commands::User(args) => user_command(args),
        Commands::Logs(args) => logs_command(args),
        Commands::Doctor(args) => doctor_command(args),
        Commands::Storage(args) => storage_command(args),
        #[cfg(feature = "dev-experiments")]
        Commands::Backup(args) => backup_command(args),
        #[cfg(feature = "dev-experiments")]
        Commands::Export(args) => export_command(args),
        #[cfg(feature = "dev-experiments")]
        Commands::Import(args) => import_command(args),
        #[cfg(feature = "dev-experiments")]
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

    match init_storage_strategy(&args) {
        #[cfg(feature = "dev-experiments")]
        StorageStrategy::Branchfs => {
            prepare_runtime(&layout)?;
            let runtime = PortableRuntime::from_layout(&layout);
            init_branchfs_site(args, layout, runtime)
        }
        StorageStrategy::Cow => init_cow_site(args, layout),
        #[cfg(feature = "dev-experiments")]
        StorageStrategy::Cas => init_cas_site(args, layout),
    }
}

fn init_storage_strategy(_args: &InitArgs) -> StorageStrategy {
    #[cfg(feature = "dev-experiments")]
    {
        _args.strategy
    }
    #[cfg(not(feature = "dev-experiments"))]
    {
        StorageStrategy::Cow
    }
}

#[cfg(feature = "dev-experiments")]
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
        "experiments/branchfs/scripts/init_db.php",
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
    ensure_cow_main_branch(
        &layout,
        &runtime,
        CowSiteInit {
            shared: &args.shared,
            site_title: &args.site_title,
            admin_password: args.admin_password.as_deref(),
        },
        file_view,
    )?;
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

#[cfg(feature = "dev-experiments")]
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

#[cfg(feature = "dev-experiments")]
fn user_command(args: UserPassthrough) -> Result<i32> {
    if args.args.is_empty() {
        bail!("user requires a subcommand, e.g. `forkpress user add alice s3cret --role write`");
    }
    let layout = Layout::new(args.shared.work_dir.clone())?;
    ensure_branchfs_strategy(&layout, "user")?;
    prepare_runtime(&layout)?;
    let runtime = PortableRuntime::from_layout(&layout);

    let mut command = php_base_command(&layout, &runtime, &args.shared);
    command.arg(
        layout
            .runtime_dir
            .join("experiments/branchfs/scripts/user_admin.php"),
    );
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
            } else if file_view == FileViewStrategy::LinuxXfsLoop {
                println!(
                    "  measurement:  `du` can overcount XFS reflink sharing; compare `df -h {}` before/after branch creation",
                    layout.linux_xfs_mount.display()
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
            "  recommendation: forkpress init will create {} and mount it at {}",
            layout.macos_cow_image.display(),
            layout.macos_cow_mount.display()
        );
    }

    #[cfg(target_os = "windows")]
    {
        println!("  Windows ReFS Dev Drive: recommended before file-copy fallback");
        println!("  setup: run ForkPressSetup.exe to create a ReFS Dev Drive VHDX");
        println!(
            "  recommendation: create/open a ForkPress project on that Dev Drive, then rerun forkpress init"
        );
    }

    #[cfg(target_os = "linux")]
    {
        println!("  Linux XFS loop volume: available through direct loop/mount syscalls");
        println!("  image: {}", layout.linux_xfs_image.display());
        println!("  mount: {}", layout.linux_xfs_mount.display());
        println!(
            "  recommendation: forkpress init will mount one shared XFS volume for all ForkPress sites if this process has loop and mount privileges"
        );
    }

    #[cfg(not(any(target_os = "linux", target_os = "macos", target_os = "windows")))]
    {
        println!("  recommendation: file-copy materialization");
    }

    Ok(0)
}

fn storage_command(args: StorageArgs) -> Result<i32> {
    match args.command {
        StorageCommand::Status(args) => storage_status_command(args),
        StorageCommand::Mount(args) => storage_mount_command(args),
        StorageCommand::Detach(args) => storage_detach_command(args),
        StorageCommand::Compact(args) => storage_compact_command(args),
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
        if manifest.strategy == StorageStrategy::Cow {
            print_cow_storage_status(&layout, manifest.file_view)?;
        }
    } else {
        println!("  site:      not initialized");
    }

    match manifest.as_ref().and_then(|manifest| manifest.file_view) {
        Some(FileViewStrategy::MacosApfsSparsebundle) => {
            print_macos_cow_storage_status(&layout)?;
        }
        Some(FileViewStrategy::LinuxXfsLoop) => {
            print_linux_xfs_loop_storage_status(&layout)?;
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

    let _lock = lock_cow_operations(&layout)?;
    let file_view = ensure_cow_file_view_ready(&layout)?;
    match file_view {
        FileViewStrategy::MacosApfsSparsebundle => {
            println!(
                "forkpress: COW storage mounted at {}",
                layout.macos_cow_mount.display()
            );
            println!("Branch roots: {}", layout.cow_branches_dir.display());
        }
        FileViewStrategy::LinuxXfsLoop => {
            println!(
                "forkpress: shared Linux XFS COW storage mounted at {}",
                layout.linux_xfs_mount.display()
            );
            println!("Branch roots: {}", layout.cow_branches_dir.display());
            println!("Storage roots: {}", layout.linux_xfs_branches_dir.display());
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

    if !detach_storage_for_layout_if_present(
        &layout,
        args.force,
        args.keep_server,
        Duration::from_secs(args.timeout),
        DetachStorageMode::Explicit,
    )? {
        println!(
            "forkpress: no detachable storage found for {}",
            layout.work_dir.display()
        );
    }

    Ok(0)
}

fn storage_compact_command(args: StorageCompactArgs) -> Result<i32> {
    let layout = Layout::new(args.work_dir)?;
    let strategy = require_initialized_strategy(&layout, "storage compact")?;
    if strategy != StorageStrategy::Cow {
        println!(
            "forkpress: no compactable COW storage for strategy = \"{}\"",
            strategy.as_str()
        );
        return Ok(0);
    }

    let manifest = read_site_manifest(&layout)?;
    let has_macos_cow = manifest.as_ref().and_then(|manifest| manifest.file_view)
        == Some(FileViewStrategy::MacosApfsSparsebundle)
        || layout.macos_cow_image.exists();
    if !has_macos_cow {
        println!("forkpress: storage file view does not use a compactable sparsebundle");
        return Ok(0);
    }

    with_stopped_cow_server_for_storage(
        &layout,
        args.keep_server,
        Duration::from_secs(args.timeout),
        || {
            detach_macos_apfs_sparsebundle_file_view(&layout, args.force, false)?;
            compact_macos_apfs_sparsebundle_file_view(&layout)
        },
    )?;
    Ok(0)
}

fn detach_storage_for_layout_if_present(
    layout: &Layout,
    force: bool,
    keep_server: bool,
    timeout: Duration,
    mode: DetachStorageMode,
) -> Result<bool> {
    let manifest = read_site_manifest(layout)?;
    let has_linux_xfs = has_linux_xfs_detachable_storage(layout, manifest.as_ref());
    let has_macos_cow = manifest.as_ref().and_then(|manifest| manifest.file_view)
        == Some(FileViewStrategy::MacosApfsSparsebundle)
        || layout.macos_cow_image.exists()
        || layout.macos_cow_mount.exists();

    if !has_macos_cow && !has_linux_xfs {
        return Ok(false);
    }

    with_stopped_cow_server_for_storage(layout, keep_server, timeout, || {
        if has_linux_xfs {
            if let Some(record) = other_linux_xfs_server(layout)? {
                if should_detach_linux_xfs_storage(mode, Some(&record))? {
                    detach_linux_xfs_loop_file_view(layout, force, true)
                } else {
                    println!(
                        "forkpress: leaving shared Linux XFS COW storage mounted at {} because server pid {} at {} is still using it",
                        layout.linux_xfs_mount.display(),
                        record.pid,
                        record.work_dir.display()
                    );
                    Ok(())
                }
            } else {
                should_detach_linux_xfs_storage(mode, None)?;
                detach_linux_xfs_loop_file_view(layout, force, true)
            }
        } else {
            detach_macos_apfs_sparsebundle_file_view(layout, force, true)
        }
    })?;
    Ok(true)
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum DetachStorageMode {
    Automatic,
    Explicit,
}

fn has_linux_xfs_detachable_storage(layout: &Layout, manifest: Option<&SiteManifest>) -> bool {
    has_linux_xfs_detachable_storage_state(
        manifest,
        layout.linux_xfs_site_dir.exists(),
        layout.linux_xfs_branches_dir.exists(),
    )
}

fn has_linux_xfs_detachable_storage_state(
    manifest: Option<&SiteManifest>,
    linux_xfs_site_dir_exists: bool,
    linux_xfs_branches_dir_exists: bool,
) -> bool {
    manifest.and_then(|manifest| manifest.file_view) == Some(FileViewStrategy::LinuxXfsLoop)
        || linux_xfs_site_dir_exists
        || linux_xfs_branches_dir_exists
}

fn other_linux_xfs_server(layout: &Layout) -> Result<Option<ServerRecord>> {
    for record in live_server_records()? {
        if record.work_dir == layout.work_dir {
            continue;
        }
        let other_layout = Layout::new(record.work_dir.clone())?;
        let manifest = read_site_manifest(&other_layout)?;
        if has_linux_xfs_detachable_storage(&other_layout, manifest.as_ref()) {
            return Ok(Some(record));
        }
    }
    Ok(None)
}

fn should_detach_linux_xfs_storage(
    mode: DetachStorageMode,
    other: Option<&ServerRecord>,
) -> Result<bool> {
    if let Some(record) = other {
        return match mode {
            DetachStorageMode::Automatic => Ok(false),
            DetachStorageMode::Explicit => {
                bail!(
                    "shared Linux XFS COW storage is still used by server pid {} at {}. Stop all ForkPress sites before detaching the shared volume.",
                    record.pid,
                    record.work_dir.display()
                );
            }
        };
    }
    Ok(true)
}

fn with_stopped_cow_server_for_storage<T>(
    layout: &Layout,
    keep_server: bool,
    timeout: Duration,
    action: impl FnOnce() -> Result<T>,
) -> Result<T> {
    with_stopped_cow_server_for_storage_impl(
        layout,
        keep_server,
        timeout,
        running_record_for_work_dir,
        stop_server_record,
        action,
    )
}

fn with_stopped_cow_server_for_storage_impl<T, FindRunning, StopRunning, Action>(
    layout: &Layout,
    keep_server: bool,
    timeout: Duration,
    mut find_running: FindRunning,
    mut stop_running: StopRunning,
    action: Action,
) -> Result<T>
where
    FindRunning: FnMut(&Path) -> Result<Option<ServerRecord>>,
    StopRunning: FnMut(&ServerRecord, Duration) -> Result<()>,
    Action: FnOnce() -> Result<T>,
{
    let mut action = Some(action);
    loop {
        let _lifecycle_lock = lock_cow_lifecycle(layout)?;
        let _lock = lock_cow_operations(layout)?;
        if let Some(record) = find_running(&layout.work_dir)? {
            drop(_lock);
            drop(_lifecycle_lock);
            if keep_server {
                bail!(
                    "server pid {} started for {} while preparing storage. Stop it first or omit --keep-server.",
                    record.pid,
                    layout.work_dir.display()
                );
            }
            stop_running(&record, timeout)?;
            continue;
        }

        return action
            .take()
            .expect("storage lifecycle action already consumed")();
    }
}

#[cfg(feature = "dev-experiments")]
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
        "scripts/shared/sqlite_backup.php",
        [src.as_os_str(), args.dest.as_os_str()],
    )?;
    Ok(0)
}

#[cfg(feature = "dev-experiments")]
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
        "experiments/branchfs/scripts/export.php",
        [src.as_os_str(), args.output_dir.as_os_str()],
    )?;
    Ok(0)
}

#[cfg(feature = "dev-experiments")]
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
        "experiments/branchfs/scripts/import.php",
        [args.input_dir.as_os_str(), args.dest.as_os_str()],
    )?;
    Ok(0)
}

#[cfg(feature = "dev-experiments")]
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
    #[cfg(feature = "dev-experiments")]
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
    #[cfg(feature = "dev-experiments")]
    fn cli_init_uses_platform_default_strategy() {
        let cli = Cli::try_parse_from(["forkpress", "init"]).unwrap();
        let Commands::Init(args) = cli.command else {
            panic!("expected init command");
        };
        assert_eq!(args.strategy, default_storage_strategy());
    }

    #[test]
    #[cfg(not(feature = "dev-experiments"))]
    fn production_cli_does_not_expose_strategy_selector() {
        assert!(Cli::try_parse_from(["forkpress", "init", "--strategy", "cow"]).is_err());
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

        let compact = Cli::try_parse_from([
            "forkpress",
            "storage",
            "compact",
            "--work-dir",
            ".forkpress",
            "--force",
            "--keep-server",
        ])
        .unwrap();
        let Commands::Storage(StorageArgs {
            command: StorageCommand::Compact(args),
        }) = compact.command
        else {
            panic!("expected storage compact command");
        };
        assert!(args.force);
        assert!(args.keep_server);
    }

    #[test]
    fn linux_xfs_detachable_storage_is_detected_without_manifest() {
        assert!(!has_linux_xfs_detachable_storage_state(None, false, false));
        assert!(has_linux_xfs_detachable_storage_state(None, true, false));
        assert!(has_linux_xfs_detachable_storage_state(None, false, true));

        let reflink_manifest =
            SiteManifest::new(StorageStrategy::Cow).with_file_view(FileViewStrategy::Reflink);
        assert!(!has_linux_xfs_detachable_storage_state(
            Some(&reflink_manifest),
            false,
            false
        ));

        let linux_xfs_manifest =
            SiteManifest::new(StorageStrategy::Cow).with_file_view(FileViewStrategy::LinuxXfsLoop);
        assert!(has_linux_xfs_detachable_storage_state(
            Some(&linux_xfs_manifest),
            false,
            false
        ));
    }

    #[test]
    fn automatic_linux_xfs_detach_leaves_shared_mount_for_other_servers() {
        let record = ServerRecord {
            pid: 12345,
            child_pid: None,
            work_dir: PathBuf::from("/tmp/forkpress-other/.forkpress"),
            host: "127.0.0.1".to_string(),
            port: 18080,
            root_host: "wp.localhost".to_string(),
            log: PathBuf::from("/tmp/forkpress-other/.forkpress/logs/forkpress-server.log"),
        };

        assert!(
            !should_detach_linux_xfs_storage(DetachStorageMode::Automatic, Some(&record)).unwrap()
        );
        assert!(should_detach_linux_xfs_storage(DetachStorageMode::Automatic, None).unwrap());

        let err = should_detach_linux_xfs_storage(DetachStorageMode::Explicit, Some(&record))
            .unwrap_err();
        assert!(
            err.to_string()
                .contains("Stop all ForkPress sites before detaching the shared volume")
        );
    }

    #[cfg(unix)]
    #[test]
    fn cow_storage_lifecycle_waits_for_background_start_lock() {
        let root = std::env::temp_dir().join(format!(
            "forkpress-cow-lifecycle-wait-{}-{}",
            std::process::id(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        let layout = Layout::new(root.join(".forkpress")).unwrap();
        fs::create_dir_all(&layout.cow_dir).unwrap();
        let start_lock = lock_cow_lifecycle(&layout).unwrap();
        let (tx, rx) = std::sync::mpsc::channel();
        let thread_layout = layout.clone();

        let handle = std::thread::spawn(move || {
            with_stopped_cow_server_for_storage_impl(
                &thread_layout,
                false,
                Duration::from_millis(10),
                |_| Ok(None),
                |_, _| Ok(()),
                || {
                    tx.send(()).unwrap();
                    Ok(())
                },
            )
            .unwrap();
        });

        assert!(rx.recv_timeout(Duration::from_millis(150)).is_err());
        drop(start_lock);
        rx.recv_timeout(Duration::from_secs(2)).unwrap();
        handle.join().unwrap();
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn cow_storage_lifecycle_stops_registered_server_before_action() {
        let root = std::env::temp_dir().join(format!(
            "forkpress-cow-lifecycle-stop-{}-{}",
            std::process::id(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        let layout = Layout::new(root.join(".forkpress")).unwrap();
        fs::create_dir_all(&layout.cow_dir).unwrap();
        let record = ServerRecord {
            pid: 12345,
            child_pid: None,
            work_dir: layout.work_dir.clone(),
            host: "127.0.0.1".to_string(),
            port: 18080,
            root_host: "wp.localhost".to_string(),
            log: layout.forkpress_server_log.clone(),
        };
        let lookups = std::cell::Cell::new(0);
        let stopped = std::cell::Cell::new(false);

        with_stopped_cow_server_for_storage_impl(
            &layout,
            false,
            Duration::from_secs(3),
            |work_dir| {
                assert_eq!(work_dir, layout.work_dir);
                let count = lookups.get();
                lookups.set(count + 1);
                if count == 0 {
                    Ok(Some(record.clone()))
                } else {
                    Ok(None)
                }
            },
            |stopped_record, timeout| {
                assert_eq!(stopped_record, &record);
                assert_eq!(timeout, Duration::from_secs(3));
                stopped.set(true);
                Ok(())
            },
            || {
                assert!(stopped.get());
                Ok(())
            },
        )
        .unwrap();
        assert_eq!(lookups.get(), 2);
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn cow_stale_operation_entries_reports_top_level_leftovers() {
        let root = std::env::temp_dir().join(format!(
            "forkpress-cow-leftovers-{}-{}",
            std::process::id(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        let work_dir = root.join(".forkpress");
        let layout = Layout::new(work_dir).unwrap();
        fs::create_dir_all(&layout.project_dir).unwrap();
        fs::create_dir_all(&layout.cow_dir).unwrap();
        fs::create_dir_all(&layout.cow_branches_dir).unwrap();
        fs::create_dir_all(layout.project_dir.join(".forkpress-reset-stage-feature")).unwrap();
        fs::create_dir_all(layout.project_dir.join(".forkpress-delete-public-feature")).unwrap();
        fs::create_dir_all(layout.cow_dir.join(".forkpress-new-feature")).unwrap();
        fs::create_dir_all(layout.project_dir.join(".forkpress-update-stage-feature")).unwrap();
        fs::create_dir_all(
            layout
                .cow_branches_dir
                .join(".forkpress-update-backup-feature"),
        )
        .unwrap();
        fs::create_dir_all(layout.project_dir.join("not-a-leftover")).unwrap();

        let entries = cow_stale_operation_entries(&layout).unwrap();
        assert_eq!(entries.len(), 5);
        assert!(
            entries
                .iter()
                .any(|path| path.ends_with(".forkpress-reset-stage-feature"))
        );
        assert!(
            entries
                .iter()
                .any(|path| path.ends_with(".forkpress-delete-public-feature"))
        );
        assert!(
            entries
                .iter()
                .any(|path| path.ends_with(".forkpress-new-feature"))
        );
        assert!(
            entries
                .iter()
                .any(|path| path.ends_with(".forkpress-update-stage-feature"))
        );
        assert!(
            entries
                .iter()
                .any(|path| path.ends_with(".forkpress-update-backup-feature"))
        );

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
    fn reset_marks_cow_git_ref_stale() {
        let root = std::env::temp_dir().join(format!(
            "forkpress-reset-ref-{}-{}",
            std::process::id(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        let layout = Layout::new(root.join(".forkpress")).unwrap();
        let ref_path = layout.cow_git_dir.join("refs/heads/feature");
        fs::create_dir_all(ref_path.parent().unwrap()).unwrap();
        fs::write(&ref_path, "1111111111111111111111111111111111111111\n").unwrap();

        invalidate_cow_git_ref(&layout, "feature").unwrap();
        assert_eq!(
            fs::read_to_string(&ref_path).unwrap(),
            "0000000000000000000000000000000000000000\n"
        );

        fs::remove_dir_all(root).unwrap();
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
    let strategy = initialized_storage_strategy(&layout)?.unwrap_or(StorageStrategy::Cow);
    prepare_runtime(&layout)?;
    ensure_ports_available(&args)?;

    let runtime = PortableRuntime::from_layout(&layout);

    let workers = args.workers.unwrap_or_else(default_worker_count);
    let (mut php, registration) = match strategy {
        #[cfg(feature = "dev-experiments")]
        StorageStrategy::Branchfs => {
            ensure_bootstrapped(&layout, &runtime, &args)?;
            (start_php_server(&layout, &runtime, &args, workers)?, None)
        }
        StorageStrategy::Cow => {
            let parent_holds_lifecycle_lock =
                std::env::var_os(FORKPRESS_COW_PARENT_LIFECYCLE_LOCK).is_some();
            let _lifecycle_lock = if parent_holds_lifecycle_lock {
                None
            } else {
                Some(lock_cow_lifecycle(&layout)?)
            };
            let _lock = lock_cow_operations(&layout)?;
            if let Some(record) = running_record_for_work_dir(&layout.work_dir)? {
                bail!(
                    "server already running for {} as pid {} at http://{}:{}/",
                    layout.work_dir.display(),
                    record.pid,
                    record.root_host,
                    record.port
                );
            }
            ensure_cow_bootstrapped(&layout, &runtime, &args)?;
            let php = start_cow_php_server(&layout, &runtime, &args, workers)?;
            let registration = register_running_server(
                &layout,
                &server_start_info(&args),
                std::process::id(),
                Some(php.id()),
            )?;
            drop(_lock);
            drop(_lifecycle_lock);
            (php, Some(registration))
        }
        #[cfg(feature = "dev-experiments")]
        StorageStrategy::Cas => {
            ensure_cas_bootstrapped(&layout, &runtime, &args)?;
            (
                start_cas_php_server(&layout, &runtime, &args, workers)?,
                None,
            )
        }
    };
    let _registration = match registration {
        Some(registration) => registration,
        None => register_running_server(
            &layout,
            &server_start_info(&args),
            std::process::id(),
            Some(php.id()),
        )?,
    };

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
        #[cfg(feature = "dev-experiments")]
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
        #[cfg(feature = "dev-experiments")]
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

    // Optional legacy BranchFS background GC. COW branch deletion and Git
    // object cleanup run inline and do not use this loop.
    #[cfg(feature = "dev-experiments")]
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
    #[cfg(not(feature = "dev-experiments"))]
    let gc_thread: Option<std::thread::JoinHandle<()>> = None;

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
    detach_storage_for_layout_if_present(
        &layout,
        false,
        false,
        Duration::from_secs(10),
        DetachStorageMode::Automatic,
    )?;

    Ok(0)
}

fn start_background_command(args: StartArgs) -> Result<i32> {
    let layout = Layout::new(args.shared.work_dir.clone())?;
    fs::create_dir_all(&layout.logs_dir)?;
    let strategy = initialized_storage_strategy(&layout)?.unwrap_or(StorageStrategy::Cow);
    let _cow_lifecycle_lock = if strategy == StorageStrategy::Cow {
        Some(lock_cow_lifecycle(&layout)?)
    } else {
        None
    };
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
    forward_env_if_present(&mut command, "FORKPRESS_COW_GIT_TEST_FAILPOINT");
    forward_env_if_present(&mut command, "FORKPRESS_COW_GIT_TEST_FAILPOINT_ACTION");
    if _cow_lifecycle_lock.is_some() {
        command.env(FORKPRESS_COW_PARENT_LIFECYCLE_LOCK, "1");
    }

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
    #[cfg(feature = "dev-experiments")]
    if let Some(gc_interval) = &args.gc_interval {
        command.arg("--gc-interval").arg(gc_interval);
    }
}

fn forward_env_if_present(command: &mut Command, key: &str) {
    if let Ok(value) = std::env::var(key) {
        command.env(key, value);
    }
}

fn server_start_info(args: &StartArgs) -> ServerStartInfo {
    ServerStartInfo {
        host: args.host.clone(),
        port: args.port,
        root_host: args.root_host.clone(),
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
        if let Some(pid) = read_pid_file(&layout.server_pid_file)?
            && let Some(record) = records.iter().find(|record| record.pid == pid).cloned()
        {
            targets.push(record);
        }
        if targets.is_empty()
            && let Some(record) = records
                .into_iter()
                .find(|record| record.work_dir == layout.work_dir)
        {
            targets.push(record);
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
        detach_storage_for_layout_if_present(
            &layout,
            args.force,
            false,
            Duration::from_secs(args.timeout),
            DetachStorageMode::Automatic,
        )?;
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

fn shell_quote_path(path: &std::path::Path) -> String {
    shell_quote(&path.to_string_lossy())
}

fn shell_quote(value: &str) -> String {
    if value
        .chars()
        .all(|ch| ch.is_ascii_alphanumeric() || matches!(ch, '/' | '.' | '_' | '-' | ':' | '@'))
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
                let _lock = lock_cow_operations(&layout)?;
                ensure_cow_file_view_ready(&layout)?;
                let url_hint = branchctl_url_hint(&layout).ok();
                create_cow_branch(
                    &layout,
                    &runtime,
                    &args.shared,
                    branch,
                    &create_args.from,
                    url_hint,
                )?;
                println!("forkpress: branch {branch} ready");
                return Ok(0);
            }
            #[cfg(feature = "dev-experiments")]
            StorageStrategy::Cas => {
                if create_args.auth.user.is_some() {
                    bail!("cas local branch creation does not use --user/--password");
                }
                create_cas_branch(&layout, &runtime, &args.shared, branch, &create_args.from)?;
                println!("forkpress: branch {branch} ready");
                return Ok(0);
            }
            #[cfg(feature = "dev-experiments")]
            StorageStrategy::Branchfs => {
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
        }
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

    #[cfg(feature = "dev-experiments")]
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
    #[cfg(feature = "dev-experiments")]
    if strategy == StorageStrategy::Cas {
        bail!("agents is not available for cas strategy yet");
    }
    prepare_runtime(&layout)?;
    let runtime = PortableRuntime::from_layout(&layout);

    match strategy {
        #[cfg(feature = "dev-experiments")]
        StorageStrategy::Branchfs => {
            if !layout.site_fp.exists() || !layout.bootstrap_marker.exists() {
                bail!(
                    "no bootstrapped site found in {}. Run `forkpress serve` first",
                    layout.work_dir.display()
                );
            }
        }
        StorageStrategy::Cow => {
            let _lock = lock_cow_operations(&layout)?;
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
        #[cfg(feature = "dev-experiments")]
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

    match strategy {
        #[cfg(feature = "dev-experiments")]
        StorageStrategy::Branchfs => {
            for index in 1..=args.count {
                let branch = format!("{}-{}", args.prefix, index);
                ensure_branch_exists(&layout, &runtime, &args.shared, &branch, &args.from, &auth)?;
            }
        }
        StorageStrategy::Cow => {
            let _lock = lock_cow_operations(&layout)?;
            ensure_cow_file_view_ready(&layout)?;
            for index in 1..=args.count {
                let branch = format!("{}-{}", args.prefix, index);
                let url_hint = branchctl_url_hint(&layout).ok();
                ensure_cow_branch_exists(
                    &layout,
                    &runtime,
                    &args.shared,
                    &branch,
                    &args.from,
                    url_hint,
                )?;
            }
        }
        #[cfg(feature = "dev-experiments")]
        StorageStrategy::Cas => unreachable!(),
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
    sync_pushed_branch(&repo, &args.remote_name, &branch)?;

    println!("forkpress: {branch} is now previewable over HTTP");
    Ok(0)
}

/// Background GC loop. Runs until `stop` flips true. Each tick invokes
/// `experiments/branchfs/scripts/branchctl.php gc` via the bundled PHP and
/// appends stdout/stderr to a dedicated log file, separate from php-server.log
/// so one stream's rotation doesn't clobber the other.
#[cfg(feature = "dev-experiments")]
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

#[cfg(feature = "dev-experiments")]
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
    cmd.arg(
        layout
            .runtime_dir
            .join("experiments/branchfs/scripts/branchctl.php"),
    )
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

fn remote_command(args: RemoteArgs) -> Result<i32> {
    let layout = Layout::new(args.shared.work_dir.clone())?;
    match args.command {
        RemoteCommand::Clone(clone) => remote_clone_command(&args.shared, &layout, clone),
        RemoteCommand::Add(add) => remote_add_command(&layout, add),
        RemoteCommand::List => remote_list_command(&layout),
        RemoteCommand::Show(show) => remote_show_command(&layout, show),
        RemoteCommand::Branch(branch) => remote_branch_command(&args.shared, &layout, branch),
    }
}

fn remote_clone_command(
    shared: &SharedPaths,
    layout: &Layout,
    args: RemoteCloneArgs,
) -> Result<i32> {
    let cache_root = remote_clone_cache_root(layout, &args.name)?;
    let manifest_path = cache_root
        .parent()
        .ok_or_else(|| anyhow!("failed to resolve remote cache parent"))?
        .join("manifest.json");
    if manifest_path.exists() && !args.force {
        bail!(
            "remote site already exists: {}; pass --force to update it",
            sanitize_remote_site_name(&args.name)
        );
    }
    if let Some(branch) = &args.branch {
        let strategy = require_initialized_strategy(layout, "remote clone --branch")?;
        if strategy != StorageStrategy::Cow {
            bail!(
                "remote clone --branch requires COW storage, found strategy = \"{}\"",
                strategy.as_str()
            );
        }
        if cow_branch_exists(layout, branch)? && !args.force {
            bail!(
                "branch already exists: {branch}. Open http://{branch}.{}:{}/ or pass --force to replace it from the remote clone.",
                branchctl_url_hint(layout)
                    .map(|(host, _)| host)
                    .unwrap_or_else(|_| "wp.localhost".to_string()),
                branchctl_url_hint(layout)
                    .map(|(_, port)| port)
                    .unwrap_or_else(|_| "18080".to_string())
            );
        }
    }

    fs::create_dir_all(&cache_root)
        .with_context(|| format!("failed to create {}", cache_root.display()))?;
    if let Some(key) = &args.ssh_key {
        if !key.is_file() {
            bail!(
                "SSH key does not exist or is not a file: {}. Use an absolute key path or leave the shell to expand `~` before running ForkPress.",
                key.display()
            );
        }
    }
    let rsync_args = remote_clone_rsync_args(&args, &cache_root);
    let output = Command::new("rsync").args(&rsync_args).output().context(
        "failed to start rsync; install rsync or use `forkpress remote add --cache-root`",
    )?;
    if !output.status.success() {
        bail!(
            "{}",
            remote_clone_rsync_failure_message(
                &args,
                &output.status.to_string(),
                &String::from_utf8_lossy(&output.stderr)
            )
        );
    }
    write_filtered_output(&output.stdout, &output.stderr)?;
    if !cache_root.join("wp-load.php").is_file() {
        bail!(
            "remote clone did not produce a WordPress root at {}; expected wp-load.php",
            cache_root.display()
        );
    }
    if !cache_root.join("wp-content/database/.ht.sqlite").is_file()
        && cache_root.join("wp-config.php").is_file()
    {
        prepare_runtime(layout)?;
        let runtime = PortableRuntime::from_layout(layout);
        remote_clone_import_mysql_database(shared, layout, &runtime, &args, &cache_root)
            .with_context(
                || "remote site did not include ForkPress SQLite data and MySQL import failed",
            )?;
    }

    let manifest = add_remote_site(
        layout,
        RemoteSiteAdd {
            name: args.name.clone(),
            ssh: Some(args.ssh.clone()),
            remote_path: Some(args.remote_path.clone()),
            remote_url: args.remote_url.clone(),
            local_url: args.local_url.clone(),
            cache_root: Some(cache_root),
            wp_cow_clone_root: None,
            force: args.force,
        },
    )?;
    let cache = forkpress_storage::remote_site_cache_stats(&manifest)?;
    println!("forkpress: remote site '{}' cloned", manifest.name);
    println!("  cache:     {}", manifest.cache_root.display());
    println!("  files:     {}", cache.files);
    println!(
        "  sync:      {}",
        if args.full_sync {
            "full tree"
        } else if args.include_uploads {
            "boot cache, uploads included"
        } else {
            "boot cache"
        }
    );
    println!(
        "  wp-load:   {}",
        if cache.has_wp_load { "yes" } else { "no" }
    );

    if let Some(branch) = args.branch {
        prepare_runtime(layout)?;
        let runtime = PortableRuntime::from_layout(layout);
        let _lock = lock_cow_operations(layout)?;
        ensure_cow_file_view_ready(layout)?;
        let report = branch_remote_site(
            layout,
            &runtime,
            shared,
            RemoteBranchOptions {
                remote: manifest.name.clone(),
                branch,
                replace_existing: args.force,
                url_hint: branchctl_url_hint(layout).ok(),
            },
        )?;
        if report.replaced_existing {
            println!(
                "forkpress: replaced existing branch '{}' from remote cache '{}'",
                report.branch, report.remote.name
            );
        }
        println!(
            "forkpress: remote cache '{}' branched to '{}'",
            report.remote.name, report.branch
        );
        println!("  source files: {}", report.cache.files);
    } else {
        println!(
            "  next:      forkpress remote --work-dir {} branch {} <branch>",
            layout.work_dir.display(),
            manifest.name
        );
    }
    Ok(0)
}

fn remote_clone_cache_root(layout: &Layout, name: &str) -> Result<PathBuf> {
    let name = sanitize_remote_site_name(name);
    if name.is_empty() {
        bail!("remote site name must contain at least one ASCII letter or number");
    }
    Ok(layout.cow_dir.join("remote-sites").join(name).join("cache"))
}

fn remote_clone_import_mysql_database(
    shared: &SharedPaths,
    layout: &Layout,
    runtime: &PortableRuntime,
    args: &RemoteCloneArgs,
    cache_root: &Path,
) -> Result<()> {
    let export_path = cache_root
        .parent()
        .ok_or_else(|| anyhow!("failed to resolve remote cache parent"))?
        .join("mysql-export.jsonl");
    let export_file = File::create(&export_path)
        .with_context(|| format!("failed to create {}", export_path.display()))?;
    let exporter = fs::read_to_string(layout.runtime_dir.join("scripts/cow/mysql_export.php"))
        .context("failed to read bundled MySQL export helper")?;

    let mut command = remote_clone_ssh_command(args);
    command
        .arg(&args.ssh)
        .arg(format!("php -- {}", shell_quote(&args.remote_path)))
        .stdin(Stdio::piped())
        .stdout(Stdio::from(export_file))
        .stderr(Stdio::piped());
    let mut child = command
        .spawn()
        .context("failed to start ssh for remote MySQL export")?;
    if let Some(mut stdin) = child.stdin.take() {
        stdin
            .write_all(exporter.as_bytes())
            .context("failed to send MySQL export helper over ssh")?;
    }
    let output = child
        .wait_with_output()
        .context("failed to wait for remote MySQL export")?;
    if !output.status.success() {
        let stderr = String::from_utf8_lossy(&output.stderr);
        bail!(
            "remote MySQL export failed with status {}{}{}",
            output.status,
            if stderr.trim().is_empty() { "" } else { ": " },
            stderr.trim()
        );
    }
    let metadata = fs::metadata(&export_path)
        .with_context(|| format!("failed to stat {}", export_path.display()))?;
    if metadata.len() == 0 {
        bail!("remote MySQL export produced no data");
    }

    let db_path = cache_root.join("wp-content/database/.ht.sqlite");
    let mut import = php_base_command(layout, runtime, shared);
    import
        .arg(
            layout
                .runtime_dir
                .join("scripts/cow/mysql_import_sqlite.php"),
        )
        .arg(&export_path)
        .arg(&db_path);
    let output = import
        .output()
        .context("failed to run bundled MySQL-to-SQLite importer")?;
    write_filtered_output(&output.stdout, &output.stderr)?;
    if !output.status.success() {
        bail!(
            "MySQL-to-SQLite importer exited with status {}",
            output.status
        );
    }
    let _ = fs::remove_file(&export_path);
    Ok(())
}

fn remote_clone_ssh_command(args: &RemoteCloneArgs) -> Command {
    let mut command = Command::new("ssh");
    if let Some(key) = &args.ssh_key {
        command.arg("-i").arg(key);
    }
    if let Some(port) = args.ssh_port {
        command.arg("-p").arg(port.to_string());
    }
    command.arg("-o").arg(format!(
        "ConnectTimeout={REMOTE_CLONE_SSH_CONNECT_TIMEOUT_SECONDS}"
    ));
    command
}

fn remote_clone_rsync_source(ssh: &str, remote_path: &str) -> String {
    let mut path = remote_path.trim_end_matches('/').to_string();
    path.push('/');
    format!("{ssh}:{path}")
}

fn remote_clone_rsync_args(args: &RemoteCloneArgs, cache_root: &Path) -> Vec<OsString> {
    let mut out = vec![OsString::from("-az")];
    if let Some(ssh_command) = remote_clone_rsync_ssh_command(args) {
        out.push(OsString::from("-e"));
        out.push(OsString::from(ssh_command));
    }
    if !args.no_delete {
        out.push(OsString::from("--delete"));
    }
    if !args.full_sync {
        for exclude in remote_clone_default_excludes(args.include_uploads) {
            out.push(OsString::from("--exclude"));
            out.push(OsString::from(exclude));
        }
    }
    for exclude in &args.excludes {
        out.push(OsString::from("--exclude"));
        out.push(OsString::from(exclude));
    }
    out.push(OsString::from(remote_clone_rsync_source(
        &args.ssh,
        &args.remote_path,
    )));
    out.push(cache_root.as_os_str().to_os_string());
    out
}

fn remote_clone_rsync_ssh_command(args: &RemoteCloneArgs) -> Option<String> {
    if args.ssh_key.is_none() && args.ssh_port.is_none() {
        return None;
    }

    let mut command = String::from("ssh");
    if let Some(key) = &args.ssh_key {
        command.push_str(" -i ");
        command.push_str(&shell_quote_path(key));
    }
    if let Some(port) = args.ssh_port {
        command.push_str(" -p ");
        command.push_str(&port.to_string());
    }
    command.push_str(" -o ConnectTimeout=");
    command.push_str(&REMOTE_CLONE_SSH_CONNECT_TIMEOUT_SECONDS.to_string());
    Some(command)
}

fn remote_clone_ssh_check_command(args: &RemoteCloneArgs) -> String {
    let mut command = String::from("ssh");
    if let Some(key) = &args.ssh_key {
        command.push_str(" -i ");
        command.push_str(&shell_quote_path(key));
    }
    if let Some(port) = args.ssh_port {
        command.push_str(" -p ");
        command.push_str(&port.to_string());
    }
    command.push_str(" -o ConnectTimeout=");
    command.push_str(&REMOTE_CLONE_SSH_CONNECT_TIMEOUT_SECONDS.to_string());
    command.push_str(" -o BatchMode=yes ");
    command.push_str(&shell_quote(&args.ssh));
    command.push(' ');
    command.push_str(&shell_quote(&format!(
        "test -f {}",
        shell_quote(&remote_clone_wp_load_path(&args.remote_path))
    )));
    command
}

fn remote_clone_wp_load_path(remote_path: &str) -> String {
    let mut path = remote_path.trim_end_matches('/').to_string();
    path.push_str("/wp-load.php");
    path
}

fn remote_clone_rsync_failure_message(
    args: &RemoteCloneArgs,
    status: &str,
    stderr: &str,
) -> String {
    let trimmed = stderr.trim();
    let lower = trimmed.to_ascii_lowercase();
    let mut message = format!(
        "remote clone could not sync {} with rsync ({status}).",
        remote_clone_rsync_source(&args.ssh, &args.remote_path)
    );

    if lower.contains("operation timed out")
        || lower.contains("connection timed out")
        || lower.contains("no route to host")
    {
        message.push_str(&format!(
            "\n\nSSH did not connect to {} on port {} before timing out. This is a network/hosting reachability problem, not a WordPress import problem. Check the SSH port, hosting firewall or IP allowlist, VPN/network, and whether SSH is enabled for this site.",
            args.ssh,
            args.ssh_port.unwrap_or(22)
        ));
    } else if lower.contains("connection refused") {
        message.push_str(&format!(
            "\n\nThe host refused SSH on port {}. Check the SSH port configured by the host; many managed WordPress hosts do not use 22 or 2222.",
            args.ssh_port.unwrap_or(22)
        ));
    } else if lower.contains("permission denied") {
        message.push_str(
            "\n\nSSH reached the server but authentication failed. Check the SSH user, key, key permissions, and whether the key is loaded in your agent if it has a passphrase.",
        );
    } else if lower.contains("no such file")
        || lower.contains("not a directory")
        || lower.contains("change_dir")
    {
        message.push_str(
            "\n\nSSH reached the server, but rsync could not read the remote WordPress path. Check --path and confirm that wp-load.php exists there.",
        );
    } else {
        message.push_str(
            "\n\nVerify the SSH target, key, port, and remote WordPress path outside ForkPress before retrying.",
        );
    }

    message.push_str("\n\nTry this SSH check:\n  ");
    message.push_str(&remote_clone_ssh_check_command(args));
    if trimmed.is_empty() {
        message.push_str("\n\nrsync did not print stderr.");
    } else {
        message.push_str("\n\nrsync stderr:\n");
        message.push_str(trimmed);
    }
    message
}

fn remote_clone_default_excludes(include_uploads: bool) -> Vec<&'static str> {
    let mut excludes = vec![
        "wp-content/cache/",
        "wp-content/upgrade/",
        "wp-content/backups/",
        "wp-content/backup-db/",
        "wp-content/ai1wm-backups/",
        "wp-content/updraft/",
        "wp-content/wflogs/",
        "wp-content/debug.log",
        ".git/",
    ];
    if !include_uploads {
        excludes.insert(0, "wp-content/uploads/");
    }
    excludes
}

fn remote_add_command(layout: &Layout, args: RemoteAddArgs) -> Result<i32> {
    let wp_cow_clone_root = match args.wp_cow_clone {
        Some(raw) => Some(resolve_wp_cow_clone_root(
            &raw,
            args.wp_cow_state_dir.as_ref(),
        )?),
        None => None,
    };
    let cache_root = match args.cache_root {
        Some(path) => Some(absolutize(path)?),
        None => None,
    };
    let manifest = add_remote_site(
        layout,
        RemoteSiteAdd {
            name: args.name,
            ssh: args.ssh,
            remote_path: args.remote_path,
            remote_url: args.remote_url,
            local_url: args.local_url,
            cache_root,
            wp_cow_clone_root,
            force: args.force,
        },
    )?;
    let cache = forkpress_storage::remote_site_cache_stats(&manifest)?;
    println!("forkpress: remote site '{}' registered", manifest.name);
    println!("  cache:     {}", manifest.cache_root.display());
    println!("  files:     {}", cache.files);
    println!(
        "  wp-load:   {}",
        if cache.has_wp_load { "yes" } else { "no" }
    );
    if !manifest.remote_url.is_empty() {
        println!("  remote:    {}", manifest.remote_url);
    }
    Ok(0)
}

fn remote_list_command(layout: &Layout) -> Result<i32> {
    let sites = list_remote_sites(layout)?;
    for site in sites {
        let cache = forkpress_storage::remote_site_cache_stats(&site)?;
        println!(
            "{}\t{}\t{} files\twp-load:{}",
            site.name,
            site.cache_root.display(),
            cache.files,
            if cache.has_wp_load { "yes" } else { "no" }
        );
    }
    Ok(0)
}

fn remote_show_command(layout: &Layout, args: RemoteShowArgs) -> Result<i32> {
    let probe = probe_remote_site(layout, &args.name)?;
    println!("forkpress remote site {}", probe.manifest.name);
    println!("  cache:      {}", probe.cache.cache_root.display());
    println!("  files:      {}", probe.cache.files);
    println!("  bytes:      {}", probe.cache.bytes);
    println!(
        "  wp-load:    {}",
        if probe.cache.has_wp_load { "yes" } else { "no" }
    );
    if !probe.manifest.remote_path.is_empty() {
        println!("  path:       {}", probe.manifest.remote_path);
    }
    if !probe.manifest.remote_url.is_empty() {
        println!("  remote url: {}", probe.manifest.remote_url);
    }
    if let Some(root) = &probe.manifest.wp_cow_clone_root {
        println!("  wp-cow:     {}", root.display());
    }
    Ok(0)
}

fn remote_branch_command(
    shared: &SharedPaths,
    layout: &Layout,
    args: RemoteBranchArgs,
) -> Result<i32> {
    let strategy = require_initialized_strategy(layout, "remote branch")?;
    if strategy != StorageStrategy::Cow {
        bail!(
            "remote branch requires COW storage, found strategy = \"{}\"",
            strategy.as_str()
        );
    }
    prepare_runtime(layout)?;
    let runtime = PortableRuntime::from_layout(layout);
    let _lock = lock_cow_operations(layout)?;
    ensure_cow_file_view_ready(layout)?;
    let report = branch_remote_site(
        layout,
        &runtime,
        shared,
        RemoteBranchOptions {
            remote: args.remote,
            branch: args.branch,
            replace_existing: false,
            url_hint: branchctl_url_hint(layout).ok(),
        },
    )?;
    println!(
        "forkpress: remote cache '{}' branched to '{}'",
        report.remote.name, report.branch
    );
    println!("  source files: {}", report.cache.files);
    Ok(0)
}

fn resolve_wp_cow_clone_root(raw: &str, state_dir: Option<&PathBuf>) -> Result<PathBuf> {
    let as_path = PathBuf::from(raw);
    if as_path.exists() || raw.contains('/') || raw.contains('\\') {
        return absolutize(as_path);
    }
    let state = match state_dir {
        Some(path) => absolutize(path.clone())?,
        None => default_wp_cow_state_dir()?,
    };
    Ok(state.join("clones").join(raw))
}

fn default_wp_cow_state_dir() -> Result<PathBuf> {
    if let Some(home) = std::env::var_os("WPCOW_HOME") {
        return Ok(PathBuf::from(home));
    }
    let home = std::env::var_os("HOME").ok_or_else(|| {
        anyhow!("HOME is not set; pass --wp-cow-state-dir when using --wp-cow-clone by name")
    })?;
    Ok(PathBuf::from(home).join(".wp-cow"))
}

fn branch_command(args: BranchPassthrough) -> Result<i32> {
    if branch_help_requested(&args.args) {
        print!("{}", branch_help_text(branch_help_command(&args.args)));
        return Ok(0);
    }

    let layout = Layout::new(args.shared.work_dir.clone())?;
    let strategy = require_initialized_strategy(&layout, "branch")?;
    prepare_runtime(&layout)?;
    let runtime = PortableRuntime::from_layout(&layout);

    match strategy {
        StorageStrategy::Cow => cow_branch_command(args, layout, runtime),
        #[cfg(feature = "dev-experiments")]
        StorageStrategy::Cas => cas_branch_command(args, layout, runtime),
        #[cfg(feature = "dev-experiments")]
        StorageStrategy::Branchfs => {
            if !layout.site_fp.exists() || !layout.bootstrap_marker.exists() {
                bail!(
                    "no bootstrapped site found in {}. Run `forkpress serve` first",
                    layout.work_dir.display()
                );
            }

            let (root_host, port) = branchctl_url_hint(&layout)?;
            let mut command = php_base_command(&layout, &runtime, &args.shared);
            command.arg(
                layout
                    .runtime_dir
                    .join("experiments/branchfs/scripts/branchctl.php"),
            );
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
    }
}

fn cow_branch_command(
    args: BranchPassthrough,
    layout: Layout,
    runtime: PortableRuntime,
) -> Result<i32> {
    let _lock = lock_cow_operations(&layout)?;
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
                bail!(
                    "branch create requires a new branch name.\n\n{}",
                    branch_help_text(Some("create"))
                );
            };
            let mut from = "main".to_string();
            let mut index = 2;
            while index < args.args.len() {
                match args.args[index].as_str() {
                    "--from" => {
                        let Some(value) = args.args.get(index + 1) else {
                            bail!(
                                "`--from` requires a source branch name.\n\n{}",
                                branch_help_text(Some("create"))
                            );
                        };
                        from = value.clone();
                        index += 2;
                    }
                    value if value.starts_with("--from=") => {
                        from = value.trim_start_matches("--from=").to_string();
                        if from.is_empty() {
                            bail!(
                                "`--from` requires a source branch name.\n\n{}",
                                branch_help_text(Some("create"))
                            );
                        }
                        index += 1;
                    }
                    other => bail!(
                        "unsupported argument for `forkpress branch create`: {other}\n\n{}",
                        branch_help_text(Some("create"))
                    ),
                }
            }
            let url_hint = branchctl_url_hint(&layout).ok();
            create_cow_branch(&layout, &runtime, &args.shared, branch, &from, url_hint)?;
            Ok(0)
        }
        "reset" | "rollback" => {
            let Some(branch) = args.args.get(1) else {
                bail!(
                    "branch reset requires the branch to reset.\n\n{}",
                    branch_help_text(Some("reset"))
                );
            };
            let mut from: Option<String> = None;
            let mut force = false;
            let mut index = 2;
            while index < args.args.len() {
                match args.args[index].as_str() {
                    "--from" => {
                        let Some(value) = args.args.get(index + 1) else {
                            bail!(
                                "`--from` requires a source branch name.\n\n{}",
                                branch_help_text(Some("reset"))
                            );
                        };
                        from = Some(value.clone());
                        index += 2;
                    }
                    value if value.starts_with("--from=") => {
                        let value = value.trim_start_matches("--from=");
                        if value.is_empty() {
                            bail!(
                                "`--from` requires a source branch name.\n\n{}",
                                branch_help_text(Some("reset"))
                            );
                        }
                        from = Some(value.to_string());
                        index += 1;
                    }
                    "--force" => {
                        force = true;
                        index += 1;
                    }
                    other => bail!(
                        "unsupported argument for `forkpress branch reset`: {other}\n\n{}",
                        branch_help_text(Some("reset"))
                    ),
                }
            }
            let Some(from) = from else {
                bail!(
                    "branch reset requires `--from <source>`.\n\n{}",
                    branch_help_text(Some("reset"))
                );
            };
            reset_cow_branch(&layout, &runtime, &args.shared, branch, &from, force)?;
            Ok(0)
        }
        "merge" => {
            let merge = parse_cow_branch_merge_args(&args.args)?;
            let (root_host, _) = branchctl_url_hint(&layout)
                .unwrap_or_else(|_| ("wp.localhost".to_string(), "18080".to_string()));
            merge_cow_branch(
                &layout,
                &runtime,
                &args.shared,
                &merge.source,
                &merge.target,
                Some(&root_host),
                merge.plugin_validator.as_deref(),
            )?;
            Ok(0)
        }
        "recover-crash" | "merge-recover" => {
            let recovery = parse_cow_branch_recover_crash_args(&args.args)?;
            recover_cow_merge_crash(
                &layout,
                &runtime,
                &args.shared,
                recovery.run_id.as_deref(),
                recovery.restore_target_db,
                recovery.restore_files,
                &recovery.format,
            )?;
            Ok(0)
        }
        "revalidate-reviews" | "merge-revalidate" => {
            let revalidation = parse_cow_branch_revalidate_reviews_args(&args.args)?;
            revalidate_cow_merge_reviews(
                &layout,
                &runtime,
                &args.shared,
                revalidation.run_id.as_deref(),
                revalidation.conflict_id.as_deref(),
                revalidation.conflict_key.as_deref(),
                revalidation.reviewer.as_deref(),
                &revalidation.format,
                revalidation.quiet,
            )?;
            Ok(0)
        }
        "merge-apply-reviewed" => {
            let apply = parse_cow_branch_apply_reviewed_args(&args.args)?;
            apply_reviewed_cow_merge_resolutions(
                &layout,
                &runtime,
                &args.shared,
                apply.run_id.as_deref(),
                apply.limit.as_deref(),
                apply.note.as_deref(),
                apply.reviewer.as_deref(),
                Some(&apply.format),
            )?;
            Ok(0)
        }
        "history" | "merge-history" | "tree" => {
            let history = parse_cow_branch_history_args(&args.args)?;
            inspect_cow_merge_audit(
                &layout,
                &runtime,
                &args.shared,
                CowMergeAuditQuery {
                    format: &history.format,
                    limit: &history.limit,
                    run_id: history.run_id.as_deref(),
                    scope: "all",
                    records: "runs",
                    conflict_type: None,
                    conflict_id: None,
                    conflict_key: None,
                    event_type: None,
                    plugin: None,
                    plugin_object: None,
                    plugin_severity: None,
                    plugin_logical_identity: None,
                    decision: None,
                    path: None,
                    path_prefix: None,
                    id_band_skips: false,
                    target_kept: false,
                    review: false,
                    review_status: None,
                    resolution_status: None,
                    lifecycle_state: None,
                    next_action: None,
                    revalidation_class: None,
                    latest_revalidation_status: None,
                    stale_status: None,
                    resolution_choice: None,
                    blocked_resolution_choice: None,
                    resolution_strategy: None,
                    generic_resolver: None,
                    after_revalidate: None,
                    group_by: "none",
                },
            )?;
            Ok(0)
        }
        "record-plugin-validator-conflicts" => {
            let record = parse_cow_branch_record_plugin_validator_args(&args.args)?;
            record_cow_plugin_validator_conflicts(
                &layout,
                &runtime,
                &args.shared,
                &record.run_id,
                record.findings_json.as_deref(),
                record.findings_file.as_deref(),
                &record.format,
            )?;
            Ok(0)
        }
        "run-plugin-validator" => {
            let validator = parse_cow_branch_run_plugin_validator_args(&args.args)?;
            run_cow_plugin_validator(
                &layout,
                &runtime,
                &args.shared,
                &validator.run_id,
                &validator.validator,
                &validator.format,
            )?;
            Ok(0)
        }
        "record-plugin-driver-resolution" => {
            let resolution = parse_cow_branch_record_plugin_driver_resolution_args(&args.args)?;
            record_cow_plugin_driver_resolution(
                &layout,
                &runtime,
                &args.shared,
                resolution.conflict_id.as_deref(),
                resolution.conflict_key.as_deref(),
                resolution.run_id.as_deref(),
                &resolution.driver,
                resolution.result_json.as_deref(),
                resolution.result_file.as_deref(),
                resolution.previous_json.as_deref(),
                resolution.previous_file.as_deref(),
                resolution.applied,
                resolution.note.as_deref(),
                resolution.reviewer.as_deref(),
                &resolution.format,
            )?;
            Ok(0)
        }
        "run-plugin-driver" => {
            let driver = parse_cow_branch_run_plugin_driver_args(&args.args)?;
            run_cow_plugin_driver(
                &layout,
                &runtime,
                &args.shared,
                driver.conflict_id.as_deref(),
                driver.conflict_key.as_deref(),
                driver.run_id.as_deref(),
                &driver.driver,
                driver.note.as_deref(),
                driver.reviewer.as_deref(),
                &driver.format,
            )?;
            Ok(0)
        }
        "merge-audit" | "audit" | "conflicts" => {
            let audit = if args.args[0] == "conflicts" {
                parse_cow_branch_conflicts_args(&args.args)?
            } else {
                parse_cow_branch_merge_audit_args(&args.args)?
            };
            if audit.revalidate {
                revalidate_cow_merge_reviews(
                    &layout,
                    &runtime,
                    &args.shared,
                    audit.run_id.as_deref(),
                    audit.conflict_id.as_deref(),
                    audit.conflict_key.as_deref(),
                    audit.reviewer.as_deref(),
                    &audit.format,
                    audit.quiet,
                )?;
                return Ok(0);
            }
            inspect_cow_merge_audit(
                &layout,
                &runtime,
                &args.shared,
                CowMergeAuditQuery {
                    format: &audit.format,
                    limit: &audit.limit,
                    run_id: audit.run_id.as_deref(),
                    scope: &audit.scope,
                    records: &audit.records,
                    conflict_type: audit.conflict_type.as_deref(),
                    conflict_id: audit.conflict_id.as_deref(),
                    conflict_key: audit.conflict_key.as_deref(),
                    event_type: audit.event_type.as_deref(),
                    plugin: audit.plugin.as_deref(),
                    plugin_object: audit.plugin_object.as_deref(),
                    plugin_severity: audit.plugin_severity.as_deref(),
                    plugin_logical_identity: audit.plugin_logical_identity.as_deref(),
                    decision: audit.decision.as_deref(),
                    path: audit.path.as_deref(),
                    path_prefix: audit.path_prefix.as_deref(),
                    id_band_skips: audit.id_band_skips,
                    target_kept: audit.target_kept,
                    review: audit.review,
                    review_status: audit.review_status.as_deref(),
                    resolution_status: audit.resolution_status.as_deref(),
                    lifecycle_state: audit.lifecycle_state.as_deref(),
                    next_action: audit.next_action.as_deref(),
                    revalidation_class: audit.revalidation_class.as_deref(),
                    latest_revalidation_status: audit.latest_revalidation_status.as_deref(),
                    stale_status: audit.stale_status.as_deref(),
                    resolution_choice: audit.resolution_choice.as_deref(),
                    blocked_resolution_choice: audit.blocked_resolution_choice.as_deref(),
                    resolution_strategy: audit.resolution_strategy.as_deref(),
                    generic_resolver: audit.generic_resolver.as_deref(),
                    after_revalidate: audit.after_revalidate.as_deref(),
                    group_by: &audit.group_by,
                },
            )?;
            Ok(0)
        }
        "merge-review" => {
            let Some(record_type) = args.args.get(1) else {
                bail!(
                    "branch merge-review requires conflict, conflict-key, decision, or resolution"
                );
            };
            if !matches!(
                record_type.as_str(),
                "conflict" | "conflict-key" | "decision" | "resolution"
            ) {
                bail!(
                    "branch merge-review supports conflict <id>, conflict-key <key>, decision <id>, or resolution <id>"
                );
            }
            let Some(record_id_or_key) = args.args.get(2) else {
                if record_type == "conflict-key" {
                    bail!("branch merge-review requires a conflict key");
                }
                bail!("branch merge-review requires a record id");
            };
            let mut status: Option<String> = None;
            let mut note: Option<String> = None;
            let mut reviewer: Option<String> = None;
            let mut run: Option<String> = None;
            let mut index = 3;
            while index < args.args.len() {
                match args.args[index].as_str() {
                    "--status" => {
                        let Some(value) = args.args.get(index + 1) else {
                            bail!("--status requires pending, needs-action, or reviewed");
                        };
                        status = Some(value.clone());
                        index += 2;
                    }
                    "--note" => {
                        let Some(value) = args.args.get(index + 1) else {
                            bail!("--note requires text");
                        };
                        note = Some(value.clone());
                        index += 2;
                    }
                    "--reviewer" => {
                        let Some(value) = args.args.get(index + 1) else {
                            bail!("--reviewer requires a name");
                        };
                        reviewer = Some(value.clone());
                        index += 2;
                    }
                    "--run" => {
                        let Some(value) = args.args.get(index + 1) else {
                            bail!("--run requires a merge run id");
                        };
                        run = Some(value.clone());
                        index += 2;
                    }
                    other => {
                        bail!("unsupported argument for `forkpress branch merge-review`: {other}")
                    }
                }
            }
            if record_type != "conflict-key" && run.is_some() {
                bail!(
                    "--run can only be used with `forkpress branch merge-review conflict-key <key>`"
                );
            }
            let Some(status) = status else {
                bail!("branch merge-review requires --status pending|needs-action|reviewed");
            };
            let Some(note) = note else {
                bail!("branch merge-review requires --note <text>");
            };
            if record_type == "conflict-key" {
                review_cow_merge_conflict_key(
                    &layout,
                    &runtime,
                    &args.shared,
                    record_id_or_key,
                    run.as_deref(),
                    &status,
                    &note,
                    reviewer.as_deref(),
                )?;
            } else {
                review_cow_merge_audit_record(
                    &layout,
                    &runtime,
                    &args.shared,
                    record_type,
                    record_id_or_key,
                    &status,
                    &note,
                    reviewer.as_deref(),
                )?;
            }
            Ok(0)
        }
        "merge-resolve" => {
            let Some(record_type) = args.args.get(1) else {
                bail!("branch merge-resolve requires conflict or conflict-key");
            };
            if record_type != "conflict" && record_type != "conflict-key" {
                bail!("branch merge-resolve supports conflict <id> or conflict-key <key>");
            }
            let Some(record_id_or_key) = args.args.get(2) else {
                if record_type == "conflict-key" {
                    bail!("branch merge-resolve requires a conflict key");
                }
                bail!("branch merge-resolve requires a conflict id");
            };
            let mut choice: Option<String> = None;
            let mut apply = false;
            let mut apply_reviewed = false;
            let mut after_revalidate = false;
            let mut note: Option<String> = None;
            let mut reviewer: Option<String> = None;
            let mut run: Option<String> = None;
            let mut index = 3;
            while index < args.args.len() {
                match args.args[index].as_str() {
                    "--choice" => {
                        let Some(value) = args.args.get(index + 1) else {
                            bail!("--choice requires source or target");
                        };
                        choice = Some(value.clone());
                        index += 2;
                    }
                    "--apply" => {
                        apply = true;
                        index += 1;
                    }
                    "--apply-reviewed" => {
                        apply_reviewed = true;
                        index += 1;
                    }
                    "--after-revalidate" => {
                        after_revalidate = true;
                        index += 1;
                    }
                    "--note" => {
                        let Some(value) = args.args.get(index + 1) else {
                            bail!("--note requires text");
                        };
                        note = Some(value.clone());
                        index += 2;
                    }
                    "--reviewer" => {
                        let Some(value) = args.args.get(index + 1) else {
                            bail!("--reviewer requires a name");
                        };
                        reviewer = Some(value.clone());
                        index += 2;
                    }
                    "--run" => {
                        let Some(value) = args.args.get(index + 1) else {
                            bail!("--run requires a merge run id");
                        };
                        run = Some(value.clone());
                        index += 2;
                    }
                    other => {
                        bail!("unsupported argument for `forkpress branch merge-resolve`: {other}")
                    }
                }
            }
            if record_type == "conflict" && run.is_some() {
                bail!(
                    "--run can only be used with `forkpress branch merge-resolve conflict-key <key>`"
                );
            }
            if apply_reviewed && choice.is_some() {
                bail!("--apply-reviewed cannot be combined with --choice");
            }
            if apply_reviewed && apply {
                bail!(
                    "--apply-reviewed already applies the latest validated choice; do not combine it with --apply"
                );
            }
            if !apply_reviewed && choice.is_none() {
                bail!("branch merge-resolve requires --choice source|target or --apply-reviewed");
            }
            if record_type == "conflict-key" {
                resolve_cow_merge_conflict_key(
                    &layout,
                    &runtime,
                    &args.shared,
                    record_id_or_key,
                    run.as_deref(),
                    choice.as_deref(),
                    apply,
                    apply_reviewed,
                    after_revalidate,
                    note.as_deref(),
                    reviewer.as_deref(),
                )?;
            } else {
                resolve_cow_merge_conflict(
                    &layout,
                    &runtime,
                    &args.shared,
                    record_id_or_key,
                    choice.as_deref(),
                    apply,
                    apply_reviewed,
                    after_revalidate,
                    note.as_deref(),
                    reviewer.as_deref(),
                )?;
            }
            Ok(0)
        }
        "delete" | "rm" => {
            let Some(branch) = args.args.get(1) else {
                bail!(
                    "branch delete requires a branch name.\n\n{}",
                    branch_help_text(Some("delete"))
                );
            };
            delete_cow_branch(&layout, branch)?;
            Ok(0)
        }
        other => bail!(
            "unknown branch command `{other}`.\n\n{}",
            branch_help_text(None)
        ),
    }
}

fn branch_help_requested(args: &[String]) -> bool {
    args.is_empty()
        || matches!(
            args.first().map(String::as_str),
            Some("help" | "--help" | "-h")
        )
        || matches!(args.get(1).map(String::as_str), Some("--help" | "-h"))
}

fn branch_help_command(args: &[String]) -> Option<&str> {
    match args {
        [] => None,
        [flag] if flag == "--help" || flag == "-h" => None,
        [first, command, ..] if first == "help" => Some(command.as_str()),
        [command, flag, ..] if flag == "--help" || flag == "-h" => Some(command.as_str()),
        _ => None,
    }
}

fn branch_help_text(command: Option<&str>) -> &'static str {
    match command {
        Some("list") => {
            "Usage: forkpress branch list\n\nPrint all materialized branches for this site.\n"
        }
        Some("show") | Some("status") => {
            "Usage: forkpress branch show [branch]\n\nShow storage details for a branch. Defaults to main.\n"
        }
        Some("create") => {
            "Usage: forkpress branch create <new-branch> [--from <source>]\n\nCreate a branch from another materialized branch. Defaults to --from main.\nExample: forkpress branch create feature --from main\n"
        }
        Some("reset") | Some("rollback") => {
            "Usage: forkpress branch reset <branch> --from <source> [--force]\n\nReplace a branch with a fresh copy of another branch. Resetting main requires --force.\nExample: forkpress branch reset feature --from main\n"
        }
        Some("merge") => {
            "Usage: forkpress branch merge <source> --into <target> [--plugin-validator <path>]\n\nMerge source branch changes into the target branch and record audit metadata. Use --plugin-validator to run one plugin validator before reporting completion.\nExample: forkpress branch merge feature --into main\n"
        }
        Some("recover-crash") | Some("merge-recover") => {
            "Usage: forkpress branch recover-crash [--run <id>] [--restore-target-db] [--restore-files] [--format text|json]\n\nInspect or restore pending COW merge crash-recovery artifacts. Run without restore flags to list pending artifacts first.\nExamples:\n  forkpress branch recover-crash\n  forkpress branch recover-crash --restore-target-db --restore-files\n"
        }
        Some("revalidate-reviews") | Some("merge-revalidate") => {
            "Usage: forkpress branch revalidate-reviews [--run <id>] [--conflict-id <id>|--conflict-key <key>] [--reviewer <name>] [--format text|json] [--quiet]\n\nRecheck reviewed merge conflicts against current target state. Stale reviewed conflicts are carried back into the needs-action queue without applying a resolution.\nExample: forkpress branch revalidate-reviews --conflict-key sha256:abc123 --run 12 --reviewer alice\n"
        }
        Some("record-plugin-validator-conflicts") => {
            "Usage: forkpress branch record-plugin-validator-conflicts --run <id> (--findings-file <path>|--findings-json <json>) [--format text|json]\n\nRecord plugin-scoped validator findings against an existing merge run. Prefer --findings-file for real validators.\n"
        }
        Some("run-plugin-validator") => {
            "Usage: forkpress branch run-plugin-validator --run <id> --validator <path> [--format text|json]\n\nRun one plugin validator and record emitted findings as plugin-scoped merge conflicts.\n"
        }
        Some("record-plugin-driver-resolution") => {
            "Usage: forkpress branch record-plugin-driver-resolution conflict <id> --driver <name> (--result-file <path>|--result-json <json>) [--previous-file <path>|--previous-json <json>] [--applied] [--note <text>] [--reviewer <name>] [--format text|json]\n       forkpress branch record-plugin-driver-resolution conflict-key <key> [--run <id>] --driver <name> (--result-file <path>|--result-json <json>) [--previous-file <path>|--previous-json <json>] [--applied] [--note <text>] [--reviewer <name>] [--format text|json]\n\nRecord first-class audit evidence from a plugin-specific merge driver. This does not run a generic source/target resolver; the driver is responsible for any plugin-owned repair before recording an applied result.\n"
        }
        Some("run-plugin-driver") => {
            "Usage: forkpress branch run-plugin-driver conflict <id> --driver <path> [--note <text>] [--reviewer <name>] [--format text|json]\n       forkpress branch run-plugin-driver conflict-key <key> [--run <id>] --driver <path> [--note <text>] [--reviewer <name>] [--format text|json]\n\nRun one plugin merge driver with merge and conflict context, then record its emitted `plugin-driver` resolution. The driver must emit JSON with status `validated` or `applied` and a `result` value.\n"
        }
        Some("merge-audit") | Some("audit") => {
            "Usage: forkpress branch merge-audit [options]\n\nInspect merge runs, decisions, conflicts, conflict events, resolutions, rollback failures, and pending crash recovery artifacts. Use --revalidate to carry stale reviewed conflicts back into needs-action before resolving; revalidation only accepts --run, --conflict-id, --conflict-key, --reviewer, --format, and --quiet.\nCommon options: --format text|json, --run <id>, --scope all|db|files|plugin, --records all|runs|conflicts|conflict-events|decisions|resolutions|rollback-failures|crash-recovery, --conflict-id <id>, --conflict-key <key>, --event-type recorded|review-pending|review-needs-action|review-reviewed|resolution-validated|resolution-applied|resolution-blocked|revalidation-required, --plugin <name>, --plugin-object <object>, --plugin-severity <severity>, --plugin-logical-identity <json>, --review, --review-status <status>, --lifecycle-state <state>, --next-action <action>, --revalidation-class <class>, --latest-revalidation-status <status>, --stale-status <status>, --resolution-choice source|target|plugin-driver, --blocked-resolution-choice source|target, --resolution-strategy <strategy>, --generic-resolver yes|no, --after-revalidate supported|unsupported, --group-by none|table|status|path|type|severity|lifecycle|event-type|next-action|conflict-key|resolution-strategy|generic-resolver|after-revalidate|revalidation-class|latest-revalidation-status|stale-status|plugin|plugin-object|plugin-severity|plugin-logical-identity, --revalidate.\nGroup support: resolutions by table/status/path/plugin/plugin-object/plugin-severity/plugin-logical-identity; conflicts by table/type/path/severity/lifecycle/next-action/conflict-key/resolution-strategy/generic-resolver/after-revalidate/revalidation-class/latest-revalidation-status/stale-status/plugin/plugin-object/plugin-severity/plugin-logical-identity; conflict-events by table/type/lifecycle/event-type/conflict-key/plugin/plugin-object/plugin-severity/plugin-logical-identity; decisions by table/type/path.\n"
        }
        Some("conflicts") => {
            "Usage: forkpress branch conflicts [options]\n\nShow the merge conflict review queue. This is a shortcut for `forkpress branch merge-audit --records conflicts`, and accepts the same filters, including --run <id>, --scope all|db|files|plugin, --lifecycle-state <state>, --next-action <action>, --resolution-choice source|target|plugin-driver, --blocked-resolution-choice source|target, --group-by table|type|lifecycle|next-action|plugin|plugin-object|plugin-severity|plugin-logical-identity, and --format text|json. Use `forkpress branch conflicts --revalidate --run <id>` to carry stale reviewed conflicts back into needs-action before applying reviewed choices.\n"
        }
        Some("merge-review") => {
            "Usage: forkpress branch merge-review <conflict|decision|resolution> <id> --status <pending|needs-action|reviewed> --note <text> [--reviewer <name>]\n       forkpress branch merge-review conflict-key <key> [--run <id>] --status <pending|needs-action|reviewed> --note <text> [--reviewer <name>]\n\nAttach review metadata to an audit record. Reviewing by conflict key is allowed only when the key identifies one unresolved conflict, or when --run disambiguates it.\n"
        }
        Some("merge-resolve") => {
            "Usage: forkpress branch merge-resolve conflict <id> (--choice <source|target> [--apply]|--apply-reviewed) [--after-revalidate] [--note <text>] [--reviewer <name>]\n       forkpress branch merge-resolve conflict-key <key> [--run <id>] (--choice <source|target> [--apply]|--apply-reviewed) [--after-revalidate] [--note <text>] [--reviewer <name>]\n\nValidate or apply a reviewed merge conflict choice. Resolving by conflict key is allowed only when the key identifies one unresolved conflict, or when --run disambiguates it. Use --apply-reviewed to apply the latest validated choice. Use --after-revalidate only after merge-audit --revalidate has carried a stale DB row/cell, file conflict, or compatible source-added schema index/view/trigger conflict back to needs-action.\n"
        }
        Some("merge-apply-reviewed") => {
            "Usage: forkpress branch merge-apply-reviewed [--run <id>] [--limit <n>] [--note <text>] [--reviewer <name>] [--format text|json]\n\nApply every currently validated, unapplied generic conflict resolution in the review queue. Inspect the same queue first with `forkpress branch merge-audit --next-action apply-reviewed-choice`.\n"
        }
        Some("history") | Some("merge-history") | Some("tree") => {
            "Usage: forkpress branch history [--run <id>] [--limit <n>] [--format text|json]\n       forkpress branch tree [--run <id>] [--limit <n>] [--format text|json]\n\nShow merge run history as source -> target branch edges, with decision and conflict counts. Use --format json for automation.\n"
        }
        Some("delete") | Some("rm") => {
            "Usage: forkpress branch delete <branch>\n\nDelete a materialized branch. Use with care.\n"
        }
        _ => {
            "Usage: forkpress branch <command> [options]\n\nCommands:\n  list                         List branches\n  show [branch]                Show branch storage details\n  create <branch> [--from b]   Create a branch; defaults to --from main\n  reset <branch> --from b      Replace a branch from another branch\n  merge <source> --into target Merge one branch into another; accepts --plugin-validator\n  history [options]            Show merge run history\n  tree [options]               Show branch merge edges from history\n  recover-crash [options]      Inspect or restore pending merge crash artifacts\n  revalidate-reviews [options] Recheck reviewed conflicts for stale target drift\n  run-plugin-validator [opts]  Run one plugin validator for a merge run\n  record-plugin-validator-conflicts [opts]\n                               Record plugin-scoped validator findings\n  run-plugin-driver [opts]     Run a plugin driver for one conflict\n  record-plugin-driver-resolution [opts]\n                               Record a plugin-driver repair result\n  merge-audit [options]        Inspect merge audit records\n  conflicts [options]          Show merge conflict review queue\n  merge-review <type> <id>     Mark an audit record as reviewed\n  merge-review conflict-key <key>\n                               Review by logical conflict key when unambiguous\n  merge-resolve conflict <id>  Validate or apply a conflict choice\n  merge-resolve conflict-key <key>\n                               Resolve by logical conflict key when unambiguous\n  merge-apply-reviewed [opts]  Apply validated conflict choices from the queue\n  delete <branch>              Delete a branch\n\nExamples:\n  forkpress branch list\n  forkpress branch create feature --from main\n  forkpress branch merge feature --into main\n  forkpress branch history\n  forkpress branch tree --format json\n  forkpress branch merge feature --into main --plugin-validator ./validator.php\n  forkpress branch recover-crash --restore-target-db --restore-files\n  forkpress branch revalidate-reviews --reviewer alice\n  forkpress branch run-plugin-validator --run 12 --validator ./validator.php\n  forkpress branch run-plugin-driver conflict 34 --driver ./repair.php --format json\n  forkpress branch record-plugin-driver-resolution conflict 34 --driver ./repair.php --result-file repair-result.json --applied\n  forkpress branch merge-audit --review --records conflicts\n  forkpress branch conflicts --run 12 --lifecycle-state needs-action\n  forkpress branch merge-apply-reviewed --run 12 --reviewer alice\n\nRun `forkpress branch <command> --help` for command-specific help.\n"
        }
    }
}

#[derive(Debug, PartialEq, Eq)]
struct CowBranchHistoryArgs {
    format: String,
    limit: String,
    run_id: Option<String>,
}

fn parse_cow_branch_history_args(args: &[String]) -> Result<CowBranchHistoryArgs> {
    let command = args.first().map(String::as_str).unwrap_or("history");
    let mut format = "text".to_string();
    let mut limit = "20".to_string();
    let mut run_id: Option<String> = None;
    let mut index = 1;
    while index < args.len() {
        match args[index].as_str() {
            "--format" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--format requires text or json");
                };
                format = value.clone();
                index += 2;
            }
            value if value.starts_with("--format=") => {
                let value = value.trim_start_matches("--format=");
                if value.is_empty() {
                    bail!("--format requires text or json");
                }
                format = value.to_string();
                index += 1;
            }
            "--limit" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--limit requires a value");
                };
                limit = value.clone();
                index += 2;
            }
            value if value.starts_with("--limit=") => {
                let value = value.trim_start_matches("--limit=");
                if value.is_empty() {
                    bail!("--limit requires a value");
                }
                limit = value.to_string();
                index += 1;
            }
            "--run" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--run requires a merge run id");
                };
                run_id = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--run=") => {
                let value = value.trim_start_matches("--run=");
                if value.is_empty() {
                    bail!("--run requires a merge run id");
                }
                run_id = Some(value.to_string());
                index += 1;
            }
            other => bail!(
                "unsupported argument for `forkpress branch {command}`: {other}\n\n{}",
                branch_help_text(Some(command))
            ),
        }
    }
    Ok(CowBranchHistoryArgs {
        format,
        limit,
        run_id,
    })
}

#[derive(Debug, PartialEq, Eq)]
struct CowBranchMergeAuditArgs {
    format: String,
    limit: String,
    run_id: Option<String>,
    scope: String,
    records: String,
    conflict_type: Option<String>,
    conflict_id: Option<String>,
    conflict_key: Option<String>,
    event_type: Option<String>,
    plugin: Option<String>,
    plugin_object: Option<String>,
    plugin_severity: Option<String>,
    plugin_logical_identity: Option<String>,
    decision: Option<String>,
    path: Option<String>,
    path_prefix: Option<String>,
    id_band_skips: bool,
    target_kept: bool,
    review: bool,
    revalidate: bool,
    review_status: Option<String>,
    reviewer: Option<String>,
    resolution_status: Option<String>,
    lifecycle_state: Option<String>,
    next_action: Option<String>,
    revalidation_class: Option<String>,
    latest_revalidation_status: Option<String>,
    stale_status: Option<String>,
    resolution_choice: Option<String>,
    blocked_resolution_choice: Option<String>,
    resolution_strategy: Option<String>,
    generic_resolver: Option<String>,
    after_revalidate: Option<String>,
    group_by: String,
    quiet: bool,
}

fn parse_cow_branch_merge_audit_args(args: &[String]) -> Result<CowBranchMergeAuditArgs> {
    let command = args.first().map(String::as_str).unwrap_or("merge-audit");
    let mut format = "text".to_string();
    let mut limit = "20".to_string();
    let mut run_id: Option<String> = None;
    let mut scope = "all".to_string();
    let mut records = "all".to_string();
    let mut conflict_type: Option<String> = None;
    let mut conflict_id: Option<String> = None;
    let mut conflict_key: Option<String> = None;
    let mut event_type: Option<String> = None;
    let mut plugin: Option<String> = None;
    let mut plugin_object: Option<String> = None;
    let mut plugin_severity: Option<String> = None;
    let mut plugin_logical_identity: Option<String> = None;
    let mut decision: Option<String> = None;
    let mut path: Option<String> = None;
    let mut path_prefix: Option<String> = None;
    let mut id_band_skips = false;
    let mut target_kept = false;
    let mut review = false;
    let mut revalidate = false;
    let mut review_status: Option<String> = None;
    let mut reviewer: Option<String> = None;
    let mut resolution_status: Option<String> = None;
    let mut lifecycle_state: Option<String> = None;
    let mut next_action: Option<String> = None;
    let mut revalidation_class: Option<String> = None;
    let mut latest_revalidation_status: Option<String> = None;
    let mut stale_status: Option<String> = None;
    let mut resolution_choice: Option<String> = None;
    let mut blocked_resolution_choice: Option<String> = None;
    let mut resolution_strategy: Option<String> = None;
    let mut generic_resolver: Option<String> = None;
    let mut after_revalidate: Option<String> = None;
    let mut group_by = "none".to_string();
    let mut quiet = false;
    let mut index = 1;
    while index < args.len() {
        match args[index].as_str() {
            "--format" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--format requires text or json");
                };
                format = value.clone();
                index += 2;
            }
            value if value.starts_with("--format=") => {
                let value = value.trim_start_matches("--format=");
                if value.is_empty() {
                    bail!("--format requires text or json");
                }
                format = value.to_string();
                index += 1;
            }
            "--limit" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--limit requires a value");
                };
                limit = value.clone();
                index += 2;
            }
            value if value.starts_with("--limit=") => {
                let value = value.trim_start_matches("--limit=");
                if value.is_empty() {
                    bail!("--limit requires a value");
                }
                limit = value.to_string();
                index += 1;
            }
            "--run" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--run requires a merge run id");
                };
                run_id = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--run=") => {
                let value = value.trim_start_matches("--run=");
                if value.is_empty() {
                    bail!("--run requires a merge run id");
                }
                run_id = Some(value.to_string());
                index += 1;
            }
            "--scope" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--scope requires all, db, files, or plugin");
                };
                scope = value.clone();
                index += 2;
            }
            value if value.starts_with("--scope=") => {
                let value = value.trim_start_matches("--scope=");
                if value.is_empty() {
                    bail!("--scope requires all, db, files, or plugin");
                }
                scope = value.to_string();
                index += 1;
            }
            "--records" => {
                let Some(value) = args.get(index + 1) else {
                    bail!(
                        "--records requires all, runs, conflicts, conflict-events, decisions, resolutions, rollback-failures, or crash-recovery"
                    );
                };
                records = value.clone();
                index += 2;
            }
            value if value.starts_with("--records=") => {
                let value = value.trim_start_matches("--records=");
                if value.is_empty() {
                    bail!(
                        "--records requires all, runs, conflicts, conflict-events, decisions, resolutions, rollback-failures, or crash-recovery"
                    );
                }
                records = value.to_string();
                index += 1;
            }
            "--conflict-type" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--conflict-type requires a value");
                };
                conflict_type = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--conflict-type=") => {
                let value = value.trim_start_matches("--conflict-type=");
                if value.is_empty() {
                    bail!("--conflict-type requires a value");
                }
                conflict_type = Some(value.to_string());
                index += 1;
            }
            "--conflict-id" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--conflict-id requires a positive integer");
                };
                conflict_id = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--conflict-id=") => {
                let value = value.trim_start_matches("--conflict-id=");
                if value.is_empty() {
                    bail!("--conflict-id requires a positive integer");
                }
                conflict_id = Some(value.to_string());
                index += 1;
            }
            "--conflict-key" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--conflict-key requires a value");
                };
                conflict_key = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--conflict-key=") => {
                let value = value.trim_start_matches("--conflict-key=");
                if value.is_empty() {
                    bail!("--conflict-key requires a value");
                }
                conflict_key = Some(value.to_string());
                index += 1;
            }
            "--event-type" => {
                let Some(value) = args.get(index + 1) else {
                    bail!(
                        "--event-type requires recorded, review-pending, review-needs-action, review-reviewed, resolution-validated, resolution-applied, resolution-blocked, or revalidation-required"
                    );
                };
                event_type = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--event-type=") => {
                let value = value.trim_start_matches("--event-type=");
                if value.is_empty() {
                    bail!(
                        "--event-type requires recorded, review-pending, review-needs-action, review-reviewed, resolution-validated, resolution-applied, resolution-blocked, or revalidation-required"
                    );
                }
                event_type = Some(value.to_string());
                index += 1;
            }
            "--plugin" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--plugin requires a name");
                };
                plugin = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--plugin=") => {
                let value = value.trim_start_matches("--plugin=");
                if value.is_empty() {
                    bail!("--plugin requires a name");
                }
                plugin = Some(value.to_string());
                index += 1;
            }
            "--plugin-object" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--plugin-object requires an object");
                };
                plugin_object = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--plugin-object=") => {
                let value = value.trim_start_matches("--plugin-object=");
                if value.is_empty() {
                    bail!("--plugin-object requires an object");
                }
                plugin_object = Some(value.to_string());
                index += 1;
            }
            "--plugin-severity" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--plugin-severity requires info, warning, error, or critical");
                };
                plugin_severity = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--plugin-severity=") => {
                let value = value.trim_start_matches("--plugin-severity=");
                if value.is_empty() {
                    bail!("--plugin-severity requires info, warning, error, or critical");
                }
                plugin_severity = Some(value.to_string());
                index += 1;
            }
            "--plugin-logical-identity" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--plugin-logical-identity requires a JSON value");
                };
                plugin_logical_identity = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--plugin-logical-identity=") => {
                let value = value.trim_start_matches("--plugin-logical-identity=");
                if value.is_empty() {
                    bail!("--plugin-logical-identity requires a JSON value");
                }
                plugin_logical_identity = Some(value.to_string());
                index += 1;
            }
            "--decision" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--decision requires a value");
                };
                decision = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--decision=") => {
                let value = value.trim_start_matches("--decision=");
                if value.is_empty() {
                    bail!("--decision requires a value");
                }
                decision = Some(value.to_string());
                index += 1;
            }
            "--path" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--path requires a relative file path");
                };
                path = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--path=") => {
                let value = value.trim_start_matches("--path=");
                if value.is_empty() {
                    bail!("--path requires a relative file path");
                }
                path = Some(value.to_string());
                index += 1;
            }
            "--path-prefix" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--path-prefix requires a relative file path prefix");
                };
                path_prefix = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--path-prefix=") => {
                let value = value.trim_start_matches("--path-prefix=");
                if value.is_empty() {
                    bail!("--path-prefix requires a relative file path prefix");
                }
                path_prefix = Some(value.to_string());
                index += 1;
            }
            "--id-band-skips" => {
                id_band_skips = true;
                index += 1;
            }
            "--target-kept" => {
                target_kept = true;
                index += 1;
            }
            "--review" => {
                review = true;
                index += 1;
            }
            "--revalidate" => {
                revalidate = true;
                index += 1;
            }
            "--quiet" => {
                quiet = true;
                index += 1;
            }
            "--review-status" => {
                let Some(value) = args.get(index + 1) else {
                    bail!(
                        "--review-status requires unreviewed, pending, needs-action, or reviewed"
                    );
                };
                review_status = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--review-status=") => {
                let value = value.trim_start_matches("--review-status=");
                if value.is_empty() {
                    bail!(
                        "--review-status requires unreviewed, pending, needs-action, or reviewed"
                    );
                }
                review_status = Some(value.to_string());
                index += 1;
            }
            "--reviewer" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--reviewer requires a name");
                };
                reviewer = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--reviewer=") => {
                let value = value.trim_start_matches("--reviewer=");
                if value.is_empty() {
                    bail!("--reviewer requires a name");
                }
                reviewer = Some(value.to_string());
                index += 1;
            }
            "--resolution-status" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--resolution-status requires validated or applied");
                };
                resolution_status = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--resolution-status=") => {
                let value = value.trim_start_matches("--resolution-status=");
                if value.is_empty() {
                    bail!("--resolution-status requires validated or applied");
                }
                resolution_status = Some(value.to_string());
                index += 1;
            }
            "--lifecycle-state" => {
                let Some(value) = args.get(index + 1) else {
                    bail!(
                        "--lifecycle-state requires unreviewed, deferred, needs-action, reviewed, validated, or resolved"
                    );
                };
                lifecycle_state = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--lifecycle-state=") => {
                let value = value.trim_start_matches("--lifecycle-state=");
                if value.is_empty() {
                    bail!(
                        "--lifecycle-state requires unreviewed, deferred, needs-action, reviewed, validated, or resolved"
                    );
                }
                lifecycle_state = Some(value.to_string());
                index += 1;
            }
            "--next-action" => {
                let Some(value) = args.get(index + 1) else {
                    bail!(
                        "--next-action requires review, run-plugin-validator, wait, revalidate, resolve, apply-reviewed-choice, manual-review, or none"
                    );
                };
                next_action = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--next-action=") => {
                let value = value.trim_start_matches("--next-action=");
                if value.is_empty() {
                    bail!(
                        "--next-action requires review, run-plugin-validator, wait, revalidate, resolve, apply-reviewed-choice, manual-review, or none"
                    );
                }
                next_action = Some(value.to_string());
                index += 1;
            }
            "--revalidation-class" => {
                let Some(value) = args.get(index + 1) else {
                    bail!(
                        "--revalidation-class requires unchanged, compatible-target-drift, compatible-source-drift, compatible-schema-index-target-drift, compatible-schema-view-target-drift, compatible-schema-trigger-target-drift, missing, incompatible, replacement-evidence, or unclassified"
                    );
                };
                revalidation_class = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--revalidation-class=") => {
                let value = value.trim_start_matches("--revalidation-class=");
                if value.is_empty() {
                    bail!(
                        "--revalidation-class requires unchanged, compatible-target-drift, compatible-source-drift, compatible-schema-index-target-drift, compatible-schema-view-target-drift, compatible-schema-trigger-target-drift, missing, incompatible, replacement-evidence, or unclassified"
                    );
                }
                revalidation_class = Some(value.to_string());
                index += 1;
            }
            "--latest-revalidation-status" => {
                let Some(value) = args.get(index + 1) else {
                    bail!(
                        "--latest-revalidation-status requires none, current, source-drifted, target-drifted, source-and-target-drifted, or unknown"
                    );
                };
                latest_revalidation_status = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--latest-revalidation-status=") => {
                let value = value.trim_start_matches("--latest-revalidation-status=");
                if value.is_empty() {
                    bail!(
                        "--latest-revalidation-status requires none, current, source-drifted, target-drifted, source-and-target-drifted, or unknown"
                    );
                }
                latest_revalidation_status = Some(value.to_string());
                index += 1;
            }
            "--stale-status" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--stale-status requires fresh, stale, error, or unknown");
                };
                stale_status = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--stale-status=") => {
                let value = value.trim_start_matches("--stale-status=");
                if value.is_empty() {
                    bail!("--stale-status requires fresh, stale, error, or unknown");
                }
                stale_status = Some(value.to_string());
                index += 1;
            }
            "--resolution-choice" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--resolution-choice requires source, target, or plugin-driver");
                };
                resolution_choice = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--resolution-choice=") => {
                let value = value.trim_start_matches("--resolution-choice=");
                if value.is_empty() {
                    bail!("--resolution-choice requires source, target, or plugin-driver");
                }
                resolution_choice = Some(value.to_string());
                index += 1;
            }
            "--blocked-resolution-choice" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--blocked-resolution-choice requires source or target");
                };
                blocked_resolution_choice = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--blocked-resolution-choice=") => {
                let value = value.trim_start_matches("--blocked-resolution-choice=");
                if value.is_empty() {
                    bail!("--blocked-resolution-choice requires source or target");
                }
                blocked_resolution_choice = Some(value.to_string());
                index += 1;
            }
            "--resolution-strategy" => {
                let Some(value) = args.get(index + 1) else {
                    bail!(
                        "--resolution-strategy requires manual-review, plugin-validator, schema-choice, file-choice, row-choice, or cell-choice"
                    );
                };
                resolution_strategy = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--resolution-strategy=") => {
                let value = value.trim_start_matches("--resolution-strategy=");
                if value.is_empty() {
                    bail!(
                        "--resolution-strategy requires manual-review, plugin-validator, schema-choice, file-choice, row-choice, or cell-choice"
                    );
                }
                resolution_strategy = Some(value.to_string());
                index += 1;
            }
            "--generic-resolver" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--generic-resolver requires yes or no");
                };
                generic_resolver = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--generic-resolver=") => {
                let value = value.trim_start_matches("--generic-resolver=");
                if value.is_empty() {
                    bail!("--generic-resolver requires yes or no");
                }
                generic_resolver = Some(value.to_string());
                index += 1;
            }
            "--after-revalidate" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--after-revalidate requires supported or unsupported");
                };
                after_revalidate = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--after-revalidate=") => {
                let value = value.trim_start_matches("--after-revalidate=");
                if value.is_empty() {
                    bail!("--after-revalidate requires supported or unsupported");
                }
                after_revalidate = Some(value.to_string());
                index += 1;
            }
            "--group-by" => {
                let Some(value) = args.get(index + 1) else {
                    bail!(
                        "--group-by requires none, table, status, path, type, severity, lifecycle, event-type, next-action, conflict-key, resolution-strategy, generic-resolver, after-revalidate, revalidation-class, latest-revalidation-status, stale-status, plugin, plugin-object, plugin-severity, or plugin-logical-identity"
                    );
                };
                group_by = value.clone();
                index += 2;
            }
            value if value.starts_with("--group-by=") => {
                let value = value.trim_start_matches("--group-by=");
                if value.is_empty() {
                    bail!(
                        "--group-by requires none, table, status, path, type, severity, lifecycle, event-type, next-action, conflict-key, resolution-strategy, generic-resolver, after-revalidate, revalidation-class, latest-revalidation-status, stale-status, plugin, plugin-object, plugin-severity, or plugin-logical-identity"
                    );
                }
                group_by = value.to_string();
                index += 1;
            }
            other => bail!(
                "unsupported argument for `forkpress branch {command}`: {other}\n\n{}",
                branch_help_text(Some(command))
            ),
        }
    }
    if revalidate
        && (limit != "20"
            || scope != "all"
            || records != "all"
            || conflict_type.is_some()
            || event_type.is_some()
            || plugin.is_some()
            || plugin_object.is_some()
            || plugin_severity.is_some()
            || plugin_logical_identity.is_some()
            || decision.is_some()
            || path.is_some()
            || path_prefix.is_some()
            || id_band_skips
            || target_kept
            || review
            || review_status.is_some()
            || resolution_status.is_some()
            || lifecycle_state.is_some()
            || next_action.is_some()
            || revalidation_class.is_some()
            || latest_revalidation_status.is_some()
            || stale_status.is_some()
            || resolution_choice.is_some()
            || blocked_resolution_choice.is_some()
            || resolution_strategy.is_some()
            || generic_resolver.is_some()
            || after_revalidate.is_some()
            || group_by != "none")
    {
        bail!(
            "`forkpress branch {command} --revalidate` only accepts --run, --conflict-id, --conflict-key, --reviewer, --format, and --quiet; run {command} without --revalidate to filter audit output"
        );
    }
    if revalidate && conflict_id.is_some() && conflict_key.is_some() {
        bail!("--conflict-id cannot be combined with --conflict-key");
    }
    if quiet && !revalidate {
        bail!("--quiet is only supported with `forkpress branch {command} --revalidate`");
    }
    Ok(CowBranchMergeAuditArgs {
        format,
        limit,
        run_id,
        scope,
        records,
        conflict_type,
        conflict_id,
        conflict_key,
        event_type,
        plugin,
        plugin_object,
        plugin_severity,
        plugin_logical_identity,
        decision,
        path,
        path_prefix,
        id_band_skips,
        target_kept,
        review,
        revalidate,
        review_status,
        reviewer,
        resolution_status,
        lifecycle_state,
        next_action,
        revalidation_class,
        latest_revalidation_status,
        stale_status,
        resolution_choice,
        blocked_resolution_choice,
        resolution_strategy,
        generic_resolver,
        after_revalidate,
        group_by,
        quiet,
    })
}

fn parse_cow_branch_conflicts_args(args: &[String]) -> Result<CowBranchMergeAuditArgs> {
    let mut audit = parse_cow_branch_merge_audit_args(args)?;
    if !audit.revalidate && audit.records == "all" {
        audit.records = "conflicts".to_string();
    }
    Ok(audit)
}

#[derive(Debug, PartialEq, Eq)]
struct CowBranchRecoverCrashArgs {
    run_id: Option<String>,
    restore_target_db: bool,
    restore_files: bool,
    format: String,
}

fn parse_cow_branch_recover_crash_args(args: &[String]) -> Result<CowBranchRecoverCrashArgs> {
    let mut run_id: Option<String> = None;
    let mut restore_target_db = false;
    let mut restore_files = false;
    let mut format = "text".to_string();
    let mut index = 1;
    while index < args.len() {
        match args[index].as_str() {
            "--run" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--run requires a merge run id");
                };
                run_id = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--run=") => {
                let value = value.trim_start_matches("--run=");
                if value.is_empty() {
                    bail!("--run requires a merge run id");
                }
                run_id = Some(value.to_string());
                index += 1;
            }
            "--restore-target-db" => {
                restore_target_db = true;
                index += 1;
            }
            "--restore-files" => {
                restore_files = true;
                index += 1;
            }
            "--format" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--format requires text or json");
                };
                format = value.clone();
                index += 2;
            }
            value if value.starts_with("--format=") => {
                let value = value.trim_start_matches("--format=");
                if value.is_empty() {
                    bail!("--format requires text or json");
                }
                format = value.to_string();
                index += 1;
            }
            other => bail!(
                "unsupported argument for `forkpress branch recover-crash`: {other}\n\n{}",
                branch_help_text(Some("recover-crash"))
            ),
        }
    }
    if format != "text" && format != "json" {
        bail!("--format requires text or json");
    }
    Ok(CowBranchRecoverCrashArgs {
        run_id,
        restore_target_db,
        restore_files,
        format,
    })
}

#[derive(Debug, PartialEq, Eq)]
struct CowBranchRevalidateReviewsArgs {
    run_id: Option<String>,
    conflict_id: Option<String>,
    conflict_key: Option<String>,
    reviewer: Option<String>,
    format: String,
    quiet: bool,
}

#[derive(Debug, PartialEq, Eq)]
struct CowBranchApplyReviewedArgs {
    run_id: Option<String>,
    limit: Option<String>,
    note: Option<String>,
    reviewer: Option<String>,
    format: String,
}

fn parse_cow_branch_revalidate_reviews_args(
    args: &[String],
) -> Result<CowBranchRevalidateReviewsArgs> {
    let mut run_id: Option<String> = None;
    let mut conflict_id: Option<String> = None;
    let mut conflict_key: Option<String> = None;
    let mut reviewer: Option<String> = None;
    let mut format = "text".to_string();
    let mut quiet = false;
    let mut index = 1;
    while index < args.len() {
        match args[index].as_str() {
            "--run" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--run requires a merge run id");
                };
                run_id = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--run=") => {
                let value = value.trim_start_matches("--run=");
                if value.is_empty() {
                    bail!("--run requires a merge run id");
                }
                run_id = Some(value.to_string());
                index += 1;
            }
            "--conflict-id" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--conflict-id requires a positive integer");
                };
                conflict_id = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--conflict-id=") => {
                let value = value.trim_start_matches("--conflict-id=");
                if value.is_empty() {
                    bail!("--conflict-id requires a positive integer");
                }
                conflict_id = Some(value.to_string());
                index += 1;
            }
            "--conflict-key" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--conflict-key requires a value");
                };
                conflict_key = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--conflict-key=") => {
                let value = value.trim_start_matches("--conflict-key=");
                if value.is_empty() {
                    bail!("--conflict-key requires a value");
                }
                conflict_key = Some(value.to_string());
                index += 1;
            }
            "--reviewer" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--reviewer requires a name");
                };
                reviewer = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--reviewer=") => {
                let value = value.trim_start_matches("--reviewer=");
                if value.is_empty() {
                    bail!("--reviewer requires a name");
                }
                reviewer = Some(value.to_string());
                index += 1;
            }
            "--format" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--format requires text or json");
                };
                format = value.clone();
                index += 2;
            }
            value if value.starts_with("--format=") => {
                let value = value.trim_start_matches("--format=");
                if value.is_empty() {
                    bail!("--format requires text or json");
                }
                format = value.to_string();
                index += 1;
            }
            "--quiet" => {
                quiet = true;
                index += 1;
            }
            other => bail!(
                "unsupported argument for `forkpress branch revalidate-reviews`: {other}\n\n{}",
                branch_help_text(Some("revalidate-reviews"))
            ),
        }
    }
    if format != "text" && format != "json" {
        bail!("--format requires text or json");
    }
    if conflict_id.is_some() && conflict_key.is_some() {
        bail!("--conflict-id cannot be combined with --conflict-key");
    }
    Ok(CowBranchRevalidateReviewsArgs {
        run_id,
        conflict_id,
        conflict_key,
        reviewer,
        format,
        quiet,
    })
}

fn parse_cow_branch_apply_reviewed_args(args: &[String]) -> Result<CowBranchApplyReviewedArgs> {
    let mut run_id: Option<String> = None;
    let mut limit: Option<String> = None;
    let mut note: Option<String> = None;
    let mut reviewer: Option<String> = None;
    let mut format = "text".to_string();
    let mut index = 1;
    while index < args.len() {
        match args[index].as_str() {
            "--run" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--run requires a merge run id");
                };
                run_id = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--run=") => {
                let value = value.trim_start_matches("--run=");
                if value.is_empty() {
                    bail!("--run requires a merge run id");
                }
                run_id = Some(value.to_string());
                index += 1;
            }
            "--limit" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--limit requires a positive integer");
                };
                limit = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--limit=") => {
                let value = value.trim_start_matches("--limit=");
                if value.is_empty() {
                    bail!("--limit requires a positive integer");
                }
                limit = Some(value.to_string());
                index += 1;
            }
            "--note" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--note requires text");
                };
                note = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--note=") => {
                let value = value.trim_start_matches("--note=");
                if value.is_empty() {
                    bail!("--note requires text");
                }
                note = Some(value.to_string());
                index += 1;
            }
            "--reviewer" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--reviewer requires a name");
                };
                reviewer = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--reviewer=") => {
                let value = value.trim_start_matches("--reviewer=");
                if value.is_empty() {
                    bail!("--reviewer requires a name");
                }
                reviewer = Some(value.to_string());
                index += 1;
            }
            "--format" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--format requires text or json");
                };
                format = value.clone();
                index += 2;
            }
            value if value.starts_with("--format=") => {
                let value = value.trim_start_matches("--format=");
                if value.is_empty() {
                    bail!("--format requires text or json");
                }
                format = value.to_string();
                index += 1;
            }
            other => bail!(
                "unsupported argument for `forkpress branch merge-apply-reviewed`: {other}\n\n{}",
                branch_help_text(Some("merge-apply-reviewed"))
            ),
        }
    }
    if format != "text" && format != "json" {
        bail!("--format requires text or json");
    }
    Ok(CowBranchApplyReviewedArgs {
        run_id,
        limit,
        note,
        reviewer,
        format,
    })
}

#[derive(Debug, PartialEq, Eq)]
struct CowBranchRecordPluginValidatorArgs {
    run_id: String,
    findings_json: Option<String>,
    findings_file: Option<PathBuf>,
    format: String,
}

fn parse_cow_branch_record_plugin_validator_args(
    args: &[String],
) -> Result<CowBranchRecordPluginValidatorArgs> {
    let mut run_id: Option<String> = None;
    let mut findings_json: Option<String> = None;
    let mut findings_file: Option<PathBuf> = None;
    let mut format = "text".to_string();
    let mut index = 1;
    while index < args.len() {
        match args[index].as_str() {
            "--run" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--run requires a merge run id");
                };
                run_id = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--run=") => {
                let value = value.trim_start_matches("--run=");
                if value.is_empty() {
                    bail!("--run requires a merge run id");
                }
                run_id = Some(value.to_string());
                index += 1;
            }
            "--findings-json" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--findings-json requires a JSON array");
                };
                findings_json = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--findings-json=") => {
                let value = value.trim_start_matches("--findings-json=");
                if value.is_empty() {
                    bail!("--findings-json requires a JSON array");
                }
                findings_json = Some(value.to_string());
                index += 1;
            }
            "--findings-file" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--findings-file requires a path");
                };
                findings_file = Some(PathBuf::from(value));
                index += 2;
            }
            value if value.starts_with("--findings-file=") => {
                let value = value.trim_start_matches("--findings-file=");
                if value.is_empty() {
                    bail!("--findings-file requires a path");
                }
                findings_file = Some(PathBuf::from(value));
                index += 1;
            }
            "--format" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--format requires text or json");
                };
                format = value.clone();
                index += 2;
            }
            value if value.starts_with("--format=") => {
                let value = value.trim_start_matches("--format=");
                if value.is_empty() {
                    bail!("--format requires text or json");
                }
                format = value.to_string();
                index += 1;
            }
            other => bail!(
                "unsupported argument for `forkpress branch record-plugin-validator-conflicts`: {other}\n\n{}",
                branch_help_text(Some("record-plugin-validator-conflicts"))
            ),
        }
    }
    let Some(run_id) = run_id else {
        bail!(
            "record-plugin-validator-conflicts requires --run <id>.\n\n{}",
            branch_help_text(Some("record-plugin-validator-conflicts"))
        );
    };
    if findings_json.is_some() == findings_file.is_some() {
        bail!(
            "record-plugin-validator-conflicts requires exactly one of --findings-json or --findings-file"
        );
    }
    if format != "text" && format != "json" {
        bail!("--format requires text or json");
    }
    Ok(CowBranchRecordPluginValidatorArgs {
        run_id,
        findings_json,
        findings_file,
        format,
    })
}

#[derive(Debug, PartialEq, Eq)]
struct CowBranchRunPluginValidatorArgs {
    run_id: String,
    validator: PathBuf,
    format: String,
}

fn parse_cow_branch_run_plugin_validator_args(
    args: &[String],
) -> Result<CowBranchRunPluginValidatorArgs> {
    let mut run_id: Option<String> = None;
    let mut validator: Option<PathBuf> = None;
    let mut format = "text".to_string();
    let mut index = 1;
    while index < args.len() {
        match args[index].as_str() {
            "--run" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--run requires a merge run id");
                };
                run_id = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--run=") => {
                let value = value.trim_start_matches("--run=");
                if value.is_empty() {
                    bail!("--run requires a merge run id");
                }
                run_id = Some(value.to_string());
                index += 1;
            }
            "--validator" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--validator requires a path");
                };
                validator = Some(PathBuf::from(value));
                index += 2;
            }
            value if value.starts_with("--validator=") => {
                let value = value.trim_start_matches("--validator=");
                if value.is_empty() {
                    bail!("--validator requires a path");
                }
                validator = Some(PathBuf::from(value));
                index += 1;
            }
            "--format" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--format requires text or json");
                };
                format = value.clone();
                index += 2;
            }
            value if value.starts_with("--format=") => {
                let value = value.trim_start_matches("--format=");
                if value.is_empty() {
                    bail!("--format requires text or json");
                }
                format = value.to_string();
                index += 1;
            }
            other => bail!(
                "unsupported argument for `forkpress branch run-plugin-validator`: {other}\n\n{}",
                branch_help_text(Some("run-plugin-validator"))
            ),
        }
    }
    let Some(run_id) = run_id else {
        bail!(
            "run-plugin-validator requires --run <id>.\n\n{}",
            branch_help_text(Some("run-plugin-validator"))
        );
    };
    let Some(validator) = validator else {
        bail!(
            "run-plugin-validator requires --validator <path>.\n\n{}",
            branch_help_text(Some("run-plugin-validator"))
        );
    };
    if format != "text" && format != "json" {
        bail!("--format requires text or json");
    }
    Ok(CowBranchRunPluginValidatorArgs {
        run_id,
        validator,
        format,
    })
}

#[derive(Debug, PartialEq, Eq)]
struct CowBranchRecordPluginDriverResolutionArgs {
    conflict_id: Option<String>,
    conflict_key: Option<String>,
    run_id: Option<String>,
    driver: String,
    result_json: Option<String>,
    result_file: Option<PathBuf>,
    previous_json: Option<String>,
    previous_file: Option<PathBuf>,
    applied: bool,
    note: Option<String>,
    reviewer: Option<String>,
    format: String,
}

#[derive(Debug, PartialEq, Eq)]
struct CowBranchRunPluginDriverArgs {
    conflict_id: Option<String>,
    conflict_key: Option<String>,
    run_id: Option<String>,
    driver: PathBuf,
    note: Option<String>,
    reviewer: Option<String>,
    format: String,
}

fn parse_cow_branch_record_plugin_driver_resolution_args(
    args: &[String],
) -> Result<CowBranchRecordPluginDriverResolutionArgs> {
    let Some(record_type) = args.get(1) else {
        bail!(
            "record-plugin-driver-resolution requires conflict <id> or conflict-key <key>.\n\n{}",
            branch_help_text(Some("record-plugin-driver-resolution"))
        );
    };
    if record_type != "conflict" && record_type != "conflict-key" {
        bail!(
            "record-plugin-driver-resolution supports conflict <id> or conflict-key <key>.\n\n{}",
            branch_help_text(Some("record-plugin-driver-resolution"))
        );
    }
    let Some(record_id_or_key) = args.get(2) else {
        if record_type == "conflict-key" {
            bail!("record-plugin-driver-resolution requires a conflict key");
        }
        bail!("record-plugin-driver-resolution requires a conflict id");
    };
    let mut run_id: Option<String> = None;
    let mut driver: Option<String> = None;
    let mut result_json: Option<String> = None;
    let mut result_file: Option<PathBuf> = None;
    let mut previous_json: Option<String> = None;
    let mut previous_file: Option<PathBuf> = None;
    let mut applied = false;
    let mut note: Option<String> = None;
    let mut reviewer: Option<String> = None;
    let mut format = "text".to_string();
    let mut index = 3;
    while index < args.len() {
        match args[index].as_str() {
            "--run" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--run requires a merge run id");
                };
                run_id = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--run=") => {
                let value = value.trim_start_matches("--run=");
                if value.is_empty() {
                    bail!("--run requires a merge run id");
                }
                run_id = Some(value.to_string());
                index += 1;
            }
            "--driver" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--driver requires a name");
                };
                driver = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--driver=") => {
                let value = value.trim_start_matches("--driver=");
                if value.is_empty() {
                    bail!("--driver requires a name");
                }
                driver = Some(value.to_string());
                index += 1;
            }
            "--result-json" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--result-json requires a JSON value");
                };
                result_json = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--result-json=") => {
                let value = value.trim_start_matches("--result-json=");
                if value.is_empty() {
                    bail!("--result-json requires a JSON value");
                }
                result_json = Some(value.to_string());
                index += 1;
            }
            "--result-file" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--result-file requires a path");
                };
                result_file = Some(PathBuf::from(value));
                index += 2;
            }
            value if value.starts_with("--result-file=") => {
                let value = value.trim_start_matches("--result-file=");
                if value.is_empty() {
                    bail!("--result-file requires a path");
                }
                result_file = Some(PathBuf::from(value));
                index += 1;
            }
            "--previous-json" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--previous-json requires a JSON value");
                };
                previous_json = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--previous-json=") => {
                let value = value.trim_start_matches("--previous-json=");
                if value.is_empty() {
                    bail!("--previous-json requires a JSON value");
                }
                previous_json = Some(value.to_string());
                index += 1;
            }
            "--previous-file" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--previous-file requires a path");
                };
                previous_file = Some(PathBuf::from(value));
                index += 2;
            }
            value if value.starts_with("--previous-file=") => {
                let value = value.trim_start_matches("--previous-file=");
                if value.is_empty() {
                    bail!("--previous-file requires a path");
                }
                previous_file = Some(PathBuf::from(value));
                index += 1;
            }
            "--applied" => {
                applied = true;
                index += 1;
            }
            "--note" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--note requires text");
                };
                note = Some(value.clone());
                index += 2;
            }
            "--reviewer" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--reviewer requires a name");
                };
                reviewer = Some(value.clone());
                index += 2;
            }
            "--format" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--format requires text or json");
                };
                format = value.clone();
                index += 2;
            }
            value if value.starts_with("--format=") => {
                let value = value.trim_start_matches("--format=");
                if value.is_empty() {
                    bail!("--format requires text or json");
                }
                format = value.to_string();
                index += 1;
            }
            other => bail!(
                "unsupported argument for `forkpress branch record-plugin-driver-resolution`: {other}\n\n{}",
                branch_help_text(Some("record-plugin-driver-resolution"))
            ),
        }
    }
    if record_type == "conflict" && run_id.is_some() {
        bail!(
            "--run can only be used with `forkpress branch record-plugin-driver-resolution conflict-key <key>`"
        );
    }
    let Some(driver) = driver else {
        bail!(
            "record-plugin-driver-resolution requires --driver <name>.\n\n{}",
            branch_help_text(Some("record-plugin-driver-resolution"))
        );
    };
    if result_json.is_some() == result_file.is_some() {
        bail!(
            "record-plugin-driver-resolution requires exactly one of --result-json or --result-file"
        );
    }
    if previous_json.is_some() && previous_file.is_some() {
        bail!(
            "record-plugin-driver-resolution accepts only one of --previous-json or --previous-file"
        );
    }
    if format != "text" && format != "json" {
        bail!("--format requires text or json");
    }
    Ok(CowBranchRecordPluginDriverResolutionArgs {
        conflict_id: (record_type == "conflict").then(|| record_id_or_key.clone()),
        conflict_key: (record_type == "conflict-key").then(|| record_id_or_key.clone()),
        run_id,
        driver,
        result_json,
        result_file,
        previous_json,
        previous_file,
        applied,
        note,
        reviewer,
        format,
    })
}

fn parse_cow_branch_run_plugin_driver_args(
    args: &[String],
) -> Result<CowBranchRunPluginDriverArgs> {
    let Some(record_type) = args.get(1) else {
        bail!(
            "run-plugin-driver requires conflict <id> or conflict-key <key>.\n\n{}",
            branch_help_text(Some("run-plugin-driver"))
        );
    };
    if record_type != "conflict" && record_type != "conflict-key" {
        bail!(
            "run-plugin-driver supports conflict <id> or conflict-key <key>.\n\n{}",
            branch_help_text(Some("run-plugin-driver"))
        );
    }
    let Some(record_id_or_key) = args.get(2) else {
        if record_type == "conflict-key" {
            bail!("run-plugin-driver requires a conflict key");
        }
        bail!("run-plugin-driver requires a conflict id");
    };
    let mut run_id: Option<String> = None;
    let mut driver: Option<PathBuf> = None;
    let mut note: Option<String> = None;
    let mut reviewer: Option<String> = None;
    let mut format = "text".to_string();
    let mut index = 3;
    while index < args.len() {
        match args[index].as_str() {
            "--run" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--run requires a merge run id");
                };
                run_id = Some(value.clone());
                index += 2;
            }
            value if value.starts_with("--run=") => {
                let value = value.trim_start_matches("--run=");
                if value.is_empty() {
                    bail!("--run requires a merge run id");
                }
                run_id = Some(value.to_string());
                index += 1;
            }
            "--driver" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--driver requires a path");
                };
                driver = Some(PathBuf::from(value));
                index += 2;
            }
            value if value.starts_with("--driver=") => {
                let value = value.trim_start_matches("--driver=");
                if value.is_empty() {
                    bail!("--driver requires a path");
                }
                driver = Some(PathBuf::from(value));
                index += 1;
            }
            "--note" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--note requires text");
                };
                note = Some(value.clone());
                index += 2;
            }
            "--reviewer" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--reviewer requires a name");
                };
                reviewer = Some(value.clone());
                index += 2;
            }
            "--format" => {
                let Some(value) = args.get(index + 1) else {
                    bail!("--format requires text or json");
                };
                format = value.clone();
                index += 2;
            }
            value if value.starts_with("--format=") => {
                let value = value.trim_start_matches("--format=");
                if value.is_empty() {
                    bail!("--format requires text or json");
                }
                format = value.to_string();
                index += 1;
            }
            other => bail!(
                "unsupported argument for `forkpress branch run-plugin-driver`: {other}\n\n{}",
                branch_help_text(Some("run-plugin-driver"))
            ),
        }
    }
    if record_type == "conflict" && run_id.is_some() {
        bail!(
            "--run can only be used with `forkpress branch run-plugin-driver conflict-key <key>`"
        );
    }
    let Some(driver) = driver else {
        bail!(
            "run-plugin-driver requires --driver <path>.\n\n{}",
            branch_help_text(Some("run-plugin-driver"))
        );
    };
    if format != "text" && format != "json" {
        bail!("--format requires text or json");
    }
    Ok(CowBranchRunPluginDriverArgs {
        conflict_id: (record_type == "conflict").then(|| record_id_or_key.clone()),
        conflict_key: (record_type == "conflict-key").then(|| record_id_or_key.clone()),
        run_id,
        driver,
        note,
        reviewer,
        format,
    })
}

#[derive(Debug, PartialEq, Eq)]
struct CowBranchMergeArgs {
    source: String,
    target: String,
    plugin_validator: Option<PathBuf>,
}

fn parse_cow_branch_merge_args(args: &[String]) -> Result<CowBranchMergeArgs> {
    let mut source: Option<String> = None;
    let mut target: Option<String> = None;
    let mut plugin_validator: Option<PathBuf> = None;
    let mut index = 1;
    while index < args.len() {
        let arg = args[index].as_str();
        match arg {
            "--into" => {
                let Some(value) = args.get(index + 1) else {
                    bail!(
                        "`--into` requires a target branch name.\n\n{}",
                        branch_help_text(Some("merge"))
                    );
                };
                target = Some(value.clone());
                index += 2;
            }
            "--target" => {
                bail!(
                    "`--target` is not a branch merge option. Use `--into <target>`.\n\n{}",
                    branch_help_text(Some("merge"))
                );
            }
            "--plugin-validator" => {
                if plugin_validator.is_some() {
                    bail!(
                        "`--plugin-validator` may only be provided once.\n\n{}",
                        branch_help_text(Some("merge"))
                    );
                }
                let Some(value) = args.get(index + 1) else {
                    bail!(
                        "`--plugin-validator` requires a path.\n\n{}",
                        branch_help_text(Some("merge"))
                    );
                };
                if value.is_empty() {
                    bail!(
                        "`--plugin-validator` requires a path.\n\n{}",
                        branch_help_text(Some("merge"))
                    );
                }
                plugin_validator = Some(PathBuf::from(value));
                index += 2;
            }
            value if value.starts_with("--into=") => {
                let value = value.trim_start_matches("--into=");
                if value.is_empty() {
                    bail!(
                        "`--into` requires a target branch name.\n\n{}",
                        branch_help_text(Some("merge"))
                    );
                }
                target = Some(value.to_string());
                index += 1;
            }
            value if value.starts_with("--plugin-validator=") => {
                if plugin_validator.is_some() {
                    bail!(
                        "`--plugin-validator` may only be provided once.\n\n{}",
                        branch_help_text(Some("merge"))
                    );
                }
                let value = value.trim_start_matches("--plugin-validator=");
                if value.is_empty() {
                    bail!(
                        "`--plugin-validator` requires a path.\n\n{}",
                        branch_help_text(Some("merge"))
                    );
                }
                plugin_validator = Some(PathBuf::from(value));
                index += 1;
            }
            value if value.starts_with("--") => {
                bail!(
                    "unsupported argument for `forkpress branch merge`: {value}\n\n{}",
                    branch_help_text(Some("merge"))
                );
            }
            value => {
                if source.is_some() {
                    bail!(
                        "unexpected extra branch name `{value}`.\n\n{}",
                        branch_help_text(Some("merge"))
                    );
                }
                source = Some(value.to_string());
                index += 1;
            }
        }
    }
    let Some(source) = source else {
        bail!(
            "branch merge requires a source branch name before or after `--into`.\n\n{}",
            branch_help_text(Some("merge"))
        );
    };
    let Some(target) = target else {
        bail!(
            "branch merge requires `--into <target>`.\n\n{}",
            branch_help_text(Some("merge"))
        );
    };
    Ok(CowBranchMergeArgs {
        source,
        target,
        plugin_validator,
    })
}

#[cfg(feature = "dev-experiments")]
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

#[cfg(feature = "dev-experiments")]
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
        .arg(
            layout
                .runtime_dir
                .join("experiments/branchfs/scripts/branchctl.php"),
        )
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

#[cfg(feature = "dev-experiments")]
fn ensure_branchfs_strategy(layout: &Layout, command: &str) -> Result<()> {
    let strategy = require_initialized_strategy(layout, command)?;
    if strategy != StorageStrategy::Branchfs {
        bail_strategy_unsupported(command, strategy)?;
    }
    Ok(())
}

#[cfg(feature = "dev-experiments")]
fn bail_strategy_unsupported(command: &str, strategy: StorageStrategy) -> Result<()> {
    bail!(
        "{command} is not implemented for the {} storage strategy yet. This site was initialized with strategy = \"{}\".",
        strategy.display_name(),
        strategy.as_str()
    )
}

#[cfg(feature = "dev-experiments")]
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

fn prepare_runtime(layout: &Layout) -> Result<()> {
    prepare_embedded_runtime(layout, RUNTIME_BUNDLE, RUNTIME_BUNDLE_ID)
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
    ensure_cow_main_branch(
        layout,
        runtime,
        CowSiteInit {
            shared: &args.shared,
            site_title: &args.site_title,
            admin_password: Some("admin"),
        },
        file_view,
    )?;
    write_site_manifest_if_missing(
        layout,
        SiteManifest::new(StorageStrategy::Cow).with_file_view(file_view),
    )?;
    write_cow_branch_list(layout)?;
    Ok(())
}

#[cfg(feature = "dev-experiments")]
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

#[cfg(feature = "dev-experiments")]
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

#[cfg(feature = "dev-experiments")]
fn cas_branch_root(layout: &Layout, branch: &str) -> PathBuf {
    layout.cas_branches_dir.join(branch)
}

#[cfg(feature = "dev-experiments")]
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
        layout.runtime_dir.join("wp-plugin/forkpress-wp.php"),
        wp_content.join("mu-plugins/forkpress-wp.php"),
    )
    .with_context(|| {
        format!(
            "failed to install {}",
            wp_content.join("mu-plugins/forkpress-wp.php").display()
        )
    })?;

    fs::copy(
        layout
            .runtime_dir
            .join("experiments/wp-plugin/forkpress-experiments-wp.php"),
        wp_content.join("mu-plugins/forkpress-experiments-wp.php"),
    )
    .with_context(|| {
        format!(
            "failed to install {}",
            wp_content
                .join("mu-plugins/forkpress-experiments-wp.php")
                .display()
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

#[cfg(feature = "dev-experiments")]
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
        "experiments/cas/runtime/bootstrap_wp.php",
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

#[cfg(feature = "dev-experiments")]
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

#[cfg(feature = "dev-experiments")]
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
define('FS_METHOD', 'direct');
define('DISALLOW_FILE_MODS', false);
define('DISALLOW_FILE_EDIT', true);
define('WP_AUTO_UPDATE_CORE', false);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_HTTP_BLOCK_EXTERNAL', false);
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

#[cfg(feature = "dev-experiments")]
fn cas_branch_names(layout: &Layout) -> Result<Vec<String>> {
    plain_branch_names(&layout.cas_branches_dir)
}

#[cfg(feature = "dev-experiments")]
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

#[cfg(feature = "dev-experiments")]
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

#[cfg(feature = "dev-experiments")]
fn ensure_bootstrapped(layout: &Layout, runtime: &PortableRuntime, args: &StartArgs) -> Result<()> {
    write_site_manifest_if_missing(layout, SiteManifest::new(StorageStrategy::Branchfs))?;

    if !layout.site_fp.exists() {
        run_php_script(
            layout,
            runtime,
            &args.shared,
            "experiments/branchfs/scripts/init_db.php",
            [layout.site_fp.as_os_str()],
        )?;
    }

    if !layout.bootstrap_marker.exists() {
        run_php_script(
            layout,
            runtime,
            &args.shared,
            "experiments/branchfs/scripts/import_wp.php",
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
            "experiments/branchfs/runtime/bootstrap_wp.php",
            [
                layout.site_fp.as_os_str(),
                layout.wp_root.as_os_str(),
                OsStr::new(&args.site_title),
                layout
                    .runtime_dir
                    .join("wp-plugin/forkpress-wp.php")
                    .as_os_str(),
                layout.debug_log.as_os_str(),
                layout
                    .runtime_dir
                    .join("experiments/wp-plugin/forkpress-experiments-wp.php")
                    .as_os_str(),
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

#[cfg(feature = "dev-experiments")]
fn managed_files_marker_contents() -> String {
    format!("forkpress-managed-files {RUNTIME_BUNDLE_ID}\n")
}

#[cfg(feature = "dev-experiments")]
fn write_managed_files_marker(layout: &Layout) -> Result<()> {
    fs::write(
        &layout.managed_files_marker,
        managed_files_marker_contents(),
    )
    .with_context(|| format!("failed to write {}", layout.managed_files_marker.display()))
}

#[cfg(feature = "dev-experiments")]
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
        "experiments/branchfs/runtime/refresh_wp_files.php",
        [
            layout.site_fp.as_os_str(),
            layout.wp_root.as_os_str(),
            OsStr::new(&args.site_title),
            layout
                .runtime_dir
                .join("wp-plugin/forkpress-wp.php")
                .as_os_str(),
            layout.debug_log.as_os_str(),
            layout
                .runtime_dir
                .join("experiments/wp-plugin/forkpress-experiments-wp.php")
                .as_os_str(),
        ],
    )?;
    write_managed_files_marker(layout)?;
    Ok(())
}

#[cfg(feature = "dev-experiments")]
fn run_branchctl_migrations_quiet(
    layout: &Layout,
    runtime: &PortableRuntime,
    shared: &SharedPaths,
) -> Result<()> {
    let mut command = php_base_command(layout, runtime, shared);
    command
        .arg(
            layout
                .runtime_dir
                .join("experiments/branchfs/scripts/branchctl.php"),
        )
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

#[cfg(feature = "dev-experiments")]
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
        .arg(
            layout
                .runtime_dir
                .join("experiments/branchfs/runtime/router.php"),
        )
        .env("BRANCHFS_DB", &layout.site_fp)
        .env("BRANCHFS_SQLITE_WP_DB", &layout.site_fp)
        .env("BRANCHFS_WP_ROOT", &layout.wp_root)
        .env("BRANCHFS_ROOT_HOST", &args.root_host)
        .env("FORKPRESS_ROOT_HOST", &args.root_host)
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
    let forkpress_bin =
        std::env::current_exe().context("failed to locate running forkpress binary")?;
    let file_view = read_site_manifest(layout)?
        .and_then(|manifest| manifest.file_view)
        .unwrap_or(FileViewStrategy::Copy);
    let storage_branches_dir = match file_view {
        FileViewStrategy::MacosApfsSparsebundle => layout.macos_cow_branches_dir.clone(),
        FileViewStrategy::LinuxXfsLoop => layout.linux_xfs_branches_dir.clone(),
        FileViewStrategy::Reflink | FileViewStrategy::Copy => layout.cow_branches_dir.clone(),
    };

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
        .arg(layout.runtime_dir.join("runtime/cow/router.php"))
        .env("FORKPRESS_BRANCHES_DIR", &layout.cow_branches_dir)
        .env("FORKPRESS_BIN", &forkpress_bin)
        .env("FORKPRESS_WORK_DIR", &layout.work_dir)
        .env("FORKPRESS_COW_DIR", &layout.cow_dir)
        .env("FORKPRESS_COW_BRANCHES_DIR", &layout.cow_branches_dir)
        .env("FORKPRESS_COW_STORAGE_BRANCHES_DIR", &storage_branches_dir)
        .env("FORKPRESS_COW_GIT_DIR", &layout.cow_git_dir)
        .env("FORKPRESS_COW_FILE_VIEW", file_view.as_str())
        .env(
            "FORKPRESS_COW_MERGE_METADATA_DB",
            forkpress_storage::cow_merge_metadata_db_path(layout),
        )
        .env(
            "FORKPRESS_COW_MERGE_HELPER",
            layout.runtime_dir.join("scripts/cow/merge.php"),
        )
        .env("FORKPRESS_BRANCH_LIST", &layout.cow_branch_list)
        .env("FORKPRESS_DEBUG_LOG", &layout.debug_log)
        .env("FORKPRESS_PLAIN_STRATEGY", "cow")
        .env("FORKPRESS_ROOT_HOST", &args.root_host)
        .env_remove(FORKPRESS_COW_PARENT_LIFECYCLE_LOCK);
    forward_env_if_present(&mut command, "FORKPRESS_COW_GIT_TEST_FAILPOINT");
    forward_env_if_present(&mut command, "FORKPRESS_COW_GIT_TEST_FAILPOINT_ACTION");
    command
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

#[cfg(feature = "dev-experiments")]
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
        .arg(
            layout
                .runtime_dir
                .join("experiments/cas/runtime/router.php"),
        )
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
    fn branch_without_args_reaches_custom_help() {
        let cli = Cli::try_parse_from(["forkpress", "branch"]).unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert!(args.args.is_empty());
        assert!(branch_help_requested(&args.args));
        assert!(branch_help_text(branch_help_command(&args.args)).contains("Commands:"));
    }

    #[test]
    fn branch_help_flag_reaches_custom_help() {
        let cli = Cli::try_parse_from(["forkpress", "branch", "--help"]).unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert_eq!(args.args, vec!["--help".to_string()]);
        assert_eq!(branch_help_command(&args.args), None);
    }

    #[test]
    fn branch_subcommand_help_selects_subcommand_text() {
        let args = vec!["create".to_string(), "--help".to_string()];
        assert!(branch_help_requested(&args));
        assert_eq!(branch_help_command(&args), Some("create"));
        assert!(branch_help_text(branch_help_command(&args)).contains("--from <source>"));
    }

    #[test]
    fn branch_help_lists_crash_recovery_command() {
        assert!(branch_help_text(None).contains("recover-crash"));
        assert!(branch_help_text(Some("recover-crash")).contains("--restore-target-db"));
        assert!(branch_help_text(Some("recover-crash")).contains("--restore-files"));
    }

    #[test]
    fn branch_help_lists_review_revalidation_command() {
        assert!(branch_help_text(None).contains("revalidate-reviews"));
        assert!(branch_help_text(None).contains("history [options]"));
        assert!(branch_help_text(None).contains("tree [options]"));
        assert!(branch_help_text(Some("history")).contains("source -> target"));
        assert!(branch_help_text(Some("tree")).contains("--format text|json"));
        assert!(branch_help_text(Some("revalidate-reviews")).contains("--reviewer"));
        assert!(branch_help_text(Some("revalidate-reviews")).contains("--conflict-id <id>"));
        assert!(branch_help_text(Some("revalidate-reviews")).contains("--conflict-key <key>"));
        assert!(branch_help_text(Some("revalidate-reviews")).contains("--quiet"));
        assert!(branch_help_text(Some("revalidate-reviews")).contains("needs-action"));
        assert!(branch_help_text(Some("merge-audit")).contains("--revalidate"));
        assert!(branch_help_text(Some("merge-audit")).contains("revalidation only accepts"));
        assert!(branch_help_text(Some("merge-audit")).contains("--quiet"));
        assert!(branch_help_text(Some("merge-audit")).contains("--scope all|db|files|plugin"));
        assert!(branch_help_text(Some("merge-audit")).contains("all|runs|conflicts"));
        assert!(branch_help_text(Some("merge-audit")).contains("crash-recovery"));
        assert!(branch_help_text(Some("merge-audit")).contains("--conflict-id <id>"));
        assert!(branch_help_text(Some("merge-audit")).contains("--conflict-key <key>"));
        assert!(branch_help_text(Some("merge-audit")).contains("--event-type recorded|"));
        assert!(branch_help_text(Some("merge-audit")).contains("resolution-blocked"));
        assert!(branch_help_text(Some("merge-audit")).contains("--lifecycle-state <state>"));
        assert!(branch_help_text(Some("merge-audit")).contains("--next-action <action>"));
        assert!(branch_help_text(Some("merge-audit")).contains("--revalidation-class <class>"));
        assert!(
            branch_help_text(Some("merge-audit")).contains("--latest-revalidation-status <status>")
        );
        assert!(branch_help_text(Some("merge-audit")).contains("--stale-status <status>"));
        assert!(
            branch_help_text(Some("merge-audit")).contains("--resolution-choice source|target")
        );
        assert!(branch_help_text(Some("merge-audit")).contains("plugin-driver"));
        assert!(
            branch_help_text(Some("merge-audit"))
                .contains("--blocked-resolution-choice source|target")
        );
        assert!(branch_help_text(Some("merge-audit")).contains("lifecycle"));
        assert!(branch_help_text(Some("merge-audit")).contains("event-type"));
        assert!(branch_help_text(Some("merge-audit")).contains("next-action"));
        assert!(branch_help_text(Some("merge-audit")).contains("resolution-strategy"));
        assert!(branch_help_text(Some("merge-audit")).contains("plugin-logical-identity"));
        assert!(
            branch_help_text(Some("merge-audit"))
                .contains("resolutions by table/status/path/plugin/plugin-object")
        );
        assert!(
            branch_help_text(Some("merge-resolve"))
                .contains("source-added schema index/view/trigger")
        );
        assert!(branch_help_text(None).contains("merge-apply-reviewed"));
        assert!(branch_help_text(Some("merge-apply-reviewed")).contains("apply-reviewed-choice"));
        assert!(branch_help_text(None).contains("conflicts [options]"));
        assert!(branch_help_text(Some("conflicts")).contains("--records conflicts"));
        assert!(branch_help_text(Some("conflicts")).contains("--revalidate --run <id>"));
    }

    #[test]
    fn branch_help_lists_plugin_validator_commands() {
        assert!(branch_help_text(None).contains("run-plugin-validator"));
        assert!(branch_help_text(None).contains("record-plugin-validator-conflicts"));
        assert!(branch_help_text(None).contains("run-plugin-driver"));
        assert!(branch_help_text(None).contains("record-plugin-driver-resolution"));
        assert!(branch_help_text(None).contains("--plugin-validator ./validator.php"));
        assert!(branch_help_text(Some("merge")).contains("--plugin-validator <path>"));
        assert!(branch_help_text(Some("run-plugin-validator")).contains("--validator"));
        assert!(branch_help_text(Some("run-plugin-driver")).contains("status `validated`"));
        assert!(
            branch_help_text(Some("record-plugin-validator-conflicts")).contains("--findings-file")
        );
        assert!(
            branch_help_text(Some("record-plugin-driver-resolution")).contains("--result-file")
        );
        assert!(
            branch_help_text(Some("record-plugin-driver-resolution")).contains("plugin-specific")
        );
    }

    #[test]
    fn parses_branch_record_plugin_driver_resolution_args() {
        let args = vec![
            "record-plugin-driver-resolution".to_string(),
            "conflict-key".to_string(),
            "sha256:abc".to_string(),
            "--run=42".to_string(),
            "--driver".to_string(),
            "plugin-driver@1".to_string(),
            "--result-json".to_string(),
            "{\"status\":\"repaired\"}".to_string(),
            "--previous-file".to_string(),
            "before.json".to_string(),
            "--applied".to_string(),
            "--note".to_string(),
            "driver repaired graph".to_string(),
            "--reviewer".to_string(),
            "driver-test".to_string(),
            "--format=json".to_string(),
        ];
        let parsed = parse_cow_branch_record_plugin_driver_resolution_args(&args).unwrap();
        assert_eq!(parsed.conflict_id, None);
        assert_eq!(parsed.conflict_key.as_deref(), Some("sha256:abc"));
        assert_eq!(parsed.run_id.as_deref(), Some("42"));
        assert_eq!(parsed.driver, "plugin-driver@1");
        assert_eq!(
            parsed.result_json.as_deref(),
            Some("{\"status\":\"repaired\"}")
        );
        assert_eq!(
            parsed.previous_file.as_deref(),
            Some(Path::new("before.json"))
        );
        assert!(parsed.applied);
        assert_eq!(parsed.note.as_deref(), Some("driver repaired graph"));
        assert_eq!(parsed.reviewer.as_deref(), Some("driver-test"));
        assert_eq!(parsed.format, "json");
    }

    #[test]
    fn parses_branch_run_plugin_driver_args() {
        let args = vec![
            "run-plugin-driver".to_string(),
            "conflict-key".to_string(),
            "sha256:def".to_string(),
            "--run=99".to_string(),
            "--driver".to_string(),
            "repair.php".to_string(),
            "--note".to_string(),
            "driver validated graph".to_string(),
            "--reviewer".to_string(),
            "driver-test".to_string(),
            "--format=json".to_string(),
        ];
        let parsed = parse_cow_branch_run_plugin_driver_args(&args).unwrap();
        assert_eq!(parsed.conflict_id, None);
        assert_eq!(parsed.conflict_key.as_deref(), Some("sha256:def"));
        assert_eq!(parsed.run_id.as_deref(), Some("99"));
        assert_eq!(parsed.driver, PathBuf::from("repair.php"));
        assert_eq!(parsed.note.as_deref(), Some("driver validated graph"));
        assert_eq!(parsed.reviewer.as_deref(), Some("driver-test"));
        assert_eq!(parsed.format, "json");
    }

    #[test]
    fn parses_branch_recover_crash_defaults() {
        let args = vec!["recover-crash".to_string()];
        let parsed = parse_cow_branch_recover_crash_args(&args).unwrap();
        assert_eq!(parsed.run_id, None);
        assert!(!parsed.restore_target_db);
        assert!(!parsed.restore_files);
        assert_eq!(parsed.format, "text");
    }

    #[test]
    fn parses_branch_recover_crash_restore_flags() {
        let args = vec![
            "recover-crash".to_string(),
            "--run=7".to_string(),
            "--restore-target-db".to_string(),
            "--restore-files".to_string(),
            "--format=json".to_string(),
        ];
        let parsed = parse_cow_branch_recover_crash_args(&args).unwrap();
        assert_eq!(parsed.run_id.as_deref(), Some("7"));
        assert!(parsed.restore_target_db);
        assert!(parsed.restore_files);
        assert_eq!(parsed.format, "json");
    }

    #[test]
    fn branch_recover_crash_errors_on_unknown_flags() {
        let args = vec!["recover-crash".to_string(), "--target".to_string()];
        let err = parse_cow_branch_recover_crash_args(&args)
            .unwrap_err()
            .to_string();
        assert!(err.contains("unsupported argument"));
        assert!(err.contains("forkpress branch recover-crash"));
    }

    #[test]
    fn parses_branch_revalidate_reviews_defaults() {
        let args = vec!["revalidate-reviews".to_string()];
        let parsed = parse_cow_branch_revalidate_reviews_args(&args).unwrap();
        assert_eq!(parsed.run_id, None);
        assert_eq!(parsed.conflict_id, None);
        assert_eq!(parsed.conflict_key, None);
        assert_eq!(parsed.reviewer, None);
        assert_eq!(parsed.format, "text");
        assert!(!parsed.quiet);
    }

    #[test]
    fn parses_branch_revalidate_reviews_filters() {
        let args = vec![
            "revalidate-reviews".to_string(),
            "--run=9".to_string(),
            "--conflict-key=sha256:abc123".to_string(),
            "--reviewer=alice".to_string(),
            "--format=json".to_string(),
            "--quiet".to_string(),
        ];
        let parsed = parse_cow_branch_revalidate_reviews_args(&args).unwrap();
        assert_eq!(parsed.run_id.as_deref(), Some("9"));
        assert_eq!(parsed.conflict_key.as_deref(), Some("sha256:abc123"));
        assert_eq!(parsed.reviewer.as_deref(), Some("alice"));
        assert_eq!(parsed.format, "json");
        assert!(parsed.quiet);
    }

    #[test]
    fn branch_revalidate_reviews_errors_on_unknown_flags() {
        let args = vec!["revalidate-reviews".to_string(), "--apply".to_string()];
        let err = parse_cow_branch_revalidate_reviews_args(&args)
            .unwrap_err()
            .to_string();
        assert!(err.contains("unsupported argument"));
        assert!(err.contains("forkpress branch revalidate-reviews"));
    }

    #[test]
    fn branch_revalidate_reviews_rejects_conflict_id_and_key() {
        let args = vec![
            "revalidate-reviews".to_string(),
            "--conflict-id=12".to_string(),
            "--conflict-key=sha256:abc123".to_string(),
        ];
        let err = parse_cow_branch_revalidate_reviews_args(&args)
            .unwrap_err()
            .to_string();
        assert!(err.contains("--conflict-id cannot be combined with --conflict-key"));
    }

    #[test]
    fn parses_branch_apply_reviewed_args() {
        let args = vec![
            "merge-apply-reviewed".to_string(),
            "--run=9".to_string(),
            "--limit".to_string(),
            "50".to_string(),
            "--note=apply the reviewed queue".to_string(),
            "--reviewer=alice".to_string(),
            "--format=json".to_string(),
        ];
        let parsed = parse_cow_branch_apply_reviewed_args(&args).unwrap();
        assert_eq!(parsed.run_id.as_deref(), Some("9"));
        assert_eq!(parsed.limit.as_deref(), Some("50"));
        assert_eq!(parsed.note.as_deref(), Some("apply the reviewed queue"));
        assert_eq!(parsed.reviewer.as_deref(), Some("alice"));
        assert_eq!(parsed.format, "json");
    }

    #[test]
    fn branch_apply_reviewed_errors_on_unknown_flags() {
        let args = vec!["merge-apply-reviewed".to_string(), "--apply".to_string()];
        let err = parse_cow_branch_apply_reviewed_args(&args)
            .unwrap_err()
            .to_string();
        assert!(err.contains("unsupported argument"));
        assert!(err.contains("forkpress branch merge-apply-reviewed"));
    }

    #[test]
    fn parses_branch_record_plugin_validator_findings_file() {
        let args = vec![
            "record-plugin-validator-conflicts".to_string(),
            "--run=12".to_string(),
            "--findings-file=validator-findings.json".to_string(),
            "--format=json".to_string(),
        ];
        let parsed = parse_cow_branch_record_plugin_validator_args(&args).unwrap();
        assert_eq!(parsed.run_id, "12");
        assert_eq!(parsed.findings_json, None);
        assert_eq!(
            parsed.findings_file.as_deref(),
            Some(Path::new("validator-findings.json"))
        );
        assert_eq!(parsed.format, "json");
    }

    #[test]
    fn parses_branch_record_plugin_validator_findings_json() {
        let args = vec![
            "record-plugin-validator-conflicts".to_string(),
            "--run".to_string(),
            "12".to_string(),
            "--findings-json".to_string(),
            "[]".to_string(),
        ];
        let parsed = parse_cow_branch_record_plugin_validator_args(&args).unwrap();
        assert_eq!(parsed.run_id, "12");
        assert_eq!(parsed.findings_json.as_deref(), Some("[]"));
        assert_eq!(parsed.findings_file, None);
        assert_eq!(parsed.format, "text");
    }

    #[test]
    fn branch_record_plugin_validator_requires_one_findings_source() {
        let args = vec![
            "record-plugin-validator-conflicts".to_string(),
            "--run=12".to_string(),
        ];
        let err = parse_cow_branch_record_plugin_validator_args(&args)
            .unwrap_err()
            .to_string();
        assert!(err.contains("exactly one"));
        assert!(err.contains("--findings-json"));
        assert!(err.contains("--findings-file"));
    }

    #[test]
    fn branch_record_plugin_validator_rejects_multiple_findings_sources() {
        let args = vec![
            "record-plugin-validator-conflicts".to_string(),
            "--run=12".to_string(),
            "--findings-json=[]".to_string(),
            "--findings-file=validator-findings.json".to_string(),
        ];
        let err = parse_cow_branch_record_plugin_validator_args(&args)
            .unwrap_err()
            .to_string();
        assert!(err.contains("exactly one"));
    }

    #[test]
    fn parses_branch_run_plugin_validator() {
        let args = vec![
            "run-plugin-validator".to_string(),
            "--run=12".to_string(),
            "--validator=./validator.php".to_string(),
            "--format=json".to_string(),
        ];
        let parsed = parse_cow_branch_run_plugin_validator_args(&args).unwrap();
        assert_eq!(parsed.run_id, "12");
        assert_eq!(parsed.validator, PathBuf::from("./validator.php"));
        assert_eq!(parsed.format, "json");
    }

    #[test]
    fn branch_run_plugin_validator_requires_validator() {
        let args = vec!["run-plugin-validator".to_string(), "--run=12".to_string()];
        let err = parse_cow_branch_run_plugin_validator_args(&args)
            .unwrap_err()
            .to_string();
        assert!(err.contains("--validator <path>"));
        assert!(err.contains("forkpress branch run-plugin-validator"));
    }

    #[test]
    fn parses_branch_merge_source_then_target() {
        let args = vec![
            "merge".to_string(),
            "feature".to_string(),
            "--into".to_string(),
            "main".to_string(),
        ];
        let parsed = parse_cow_branch_merge_args(&args).unwrap();
        assert_eq!(parsed.source, "feature");
        assert_eq!(parsed.target, "main");
        assert_eq!(parsed.plugin_validator, None);
    }

    #[test]
    fn parses_branch_merge_target_then_source() {
        let args = vec![
            "merge".to_string(),
            "--into".to_string(),
            "main".to_string(),
            "feature".to_string(),
        ];
        let parsed = parse_cow_branch_merge_args(&args).unwrap();
        assert_eq!(parsed.source, "feature");
        assert_eq!(parsed.target, "main");
        assert_eq!(parsed.plugin_validator, None);
    }

    #[test]
    fn parses_branch_merge_equals_target() {
        let args = vec![
            "merge".to_string(),
            "feature".to_string(),
            "--into=main".to_string(),
        ];
        let parsed = parse_cow_branch_merge_args(&args).unwrap();
        assert_eq!(parsed.source, "feature");
        assert_eq!(parsed.target, "main");
        assert_eq!(parsed.plugin_validator, None);
    }

    #[test]
    fn parses_branch_merge_plugin_validator_equals_form() {
        let args = vec![
            "merge".to_string(),
            "feature".to_string(),
            "--into=main".to_string(),
            "--plugin-validator=./validator.php".to_string(),
        ];
        let parsed = parse_cow_branch_merge_args(&args).unwrap();
        assert_eq!(parsed.source, "feature");
        assert_eq!(parsed.target, "main");
        assert_eq!(
            parsed.plugin_validator.as_deref(),
            Some(Path::new("./validator.php"))
        );
    }

    #[test]
    fn parses_branch_merge_plugin_validator_space_form() {
        let args = vec![
            "merge".to_string(),
            "--plugin-validator".to_string(),
            "./validator.php".to_string(),
            "feature".to_string(),
            "--into".to_string(),
            "main".to_string(),
        ];
        let parsed = parse_cow_branch_merge_args(&args).unwrap();
        assert_eq!(parsed.source, "feature");
        assert_eq!(parsed.target, "main");
        assert_eq!(
            parsed.plugin_validator.as_deref(),
            Some(Path::new("./validator.php"))
        );
    }

    #[test]
    fn branch_merge_errors_on_empty_plugin_validator() {
        let args = vec![
            "merge".to_string(),
            "feature".to_string(),
            "--into=main".to_string(),
            "--plugin-validator=".to_string(),
        ];
        let err = parse_cow_branch_merge_args(&args).unwrap_err().to_string();
        assert!(err.contains("--plugin-validator"));
        assert!(err.contains("requires a path"));
        assert!(err.contains("forkpress branch merge"));
    }

    #[test]
    fn branch_merge_errors_on_duplicate_plugin_validator() {
        let args = vec![
            "merge".to_string(),
            "feature".to_string(),
            "--into=main".to_string(),
            "--plugin-validator=./first.php".to_string(),
            "--plugin-validator=./second.php".to_string(),
        ];
        let err = parse_cow_branch_merge_args(&args).unwrap_err().to_string();
        assert!(err.contains("--plugin-validator"));
        assert!(err.contains("only be provided once"));
        assert!(err.contains("forkpress branch merge"));
    }

    #[test]
    fn branch_merge_errors_explain_missing_source() {
        let args = vec![
            "merge".to_string(),
            "--into".to_string(),
            "main".to_string(),
        ];
        let err = parse_cow_branch_merge_args(&args).unwrap_err().to_string();
        assert!(err.contains("source branch"));
        assert!(err.contains("forkpress branch merge <source> --into <target>"));
    }

    #[test]
    fn branch_merge_errors_suggest_into_for_target() {
        let args = vec![
            "merge".to_string(),
            "feature".to_string(),
            "--target".to_string(),
            "main".to_string(),
        ];
        let err = parse_cow_branch_merge_args(&args).unwrap_err().to_string();
        assert!(err.contains("--target"));
        assert!(err.contains("--into <target>"));
    }

    #[test]
    fn parses_branch_merge_audit_passthrough_args() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "branch",
            "--work-dir",
            ".forkpress",
            "merge-audit",
            "--format",
            "json",
            "--run",
            "7",
            "--scope",
            "files",
            "--records",
            "conflicts",
            "--conflict-type",
            "file-unsafe-symlink",
            "--path-prefix",
            "wp-content/uploads",
        ])
        .unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert_eq!(
            args.args,
            vec![
                "merge-audit".to_string(),
                "--format".to_string(),
                "json".to_string(),
                "--run".to_string(),
                "7".to_string(),
                "--scope".to_string(),
                "files".to_string(),
                "--records".to_string(),
                "conflicts".to_string(),
                "--conflict-type".to_string(),
                "file-unsafe-symlink".to_string(),
                "--path-prefix".to_string(),
                "wp-content/uploads".to_string(),
            ]
        );
    }

    #[test]
    fn parses_branch_merge_audit_revalidate_alias_args() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "branch",
            "--work-dir",
            ".forkpress",
            "merge-audit",
            "--revalidate",
            "--run",
            "7",
            "--reviewer",
            "alice",
            "--format",
            "json",
            "--quiet",
        ])
        .unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert_eq!(
            args.args,
            vec![
                "merge-audit".to_string(),
                "--revalidate".to_string(),
                "--run".to_string(),
                "7".to_string(),
                "--reviewer".to_string(),
                "alice".to_string(),
                "--format".to_string(),
                "json".to_string(),
                "--quiet".to_string(),
            ]
        );
    }

    #[test]
    fn parses_cow_branch_history_args() {
        let args = vec![
            "history".to_string(),
            "--format=json".to_string(),
            "--limit".to_string(),
            "5".to_string(),
            "--run=12".to_string(),
        ];
        let parsed = parse_cow_branch_history_args(&args).unwrap();
        assert_eq!(parsed.format, "json");
        assert_eq!(parsed.limit, "5");
        assert_eq!(parsed.run_id.as_deref(), Some("12"));
    }

    #[test]
    fn branch_history_rejects_audit_only_filters() {
        let args = vec![
            "tree".to_string(),
            "--records".to_string(),
            "conflicts".to_string(),
        ];
        let err = parse_cow_branch_history_args(&args)
            .unwrap_err()
            .to_string();
        assert!(err.contains("forkpress branch tree"));
        assert!(err.contains("Show merge run history"));
    }

    #[test]
    fn parses_cow_branch_merge_audit_lifecycle_filter_args() {
        let args = vec![
            "merge-audit".to_string(),
            "--records".to_string(),
            "conflicts".to_string(),
            "--conflict-key".to_string(),
            "sha256:abc123".to_string(),
            "--lifecycle-state".to_string(),
            "validated".to_string(),
            "--group-by".to_string(),
            "lifecycle".to_string(),
        ];
        let parsed = parse_cow_branch_merge_audit_args(&args).unwrap();
        assert_eq!(parsed.records, "conflicts");
        assert_eq!(parsed.conflict_key.as_deref(), Some("sha256:abc123"));
        assert_eq!(parsed.lifecycle_state.as_deref(), Some("validated"));
        assert_eq!(parsed.group_by, "lifecycle");
    }

    #[test]
    fn parses_cow_branch_merge_audit_equals_form_filters() {
        let args = vec![
            "merge-audit".to_string(),
            "--format=json".to_string(),
            "--limit=12".to_string(),
            "--run=7".to_string(),
            "--scope=plugin".to_string(),
            "--records=conflicts".to_string(),
            "--conflict-type=row-target-deleted".to_string(),
            "--conflict-id=12".to_string(),
            "--conflict-key=sha256:abc123".to_string(),
            "--event-type=resolution-blocked".to_string(),
            "--plugin=forkpress-plugin-graph".to_string(),
            "--plugin-object=child:1000000".to_string(),
            "--plugin-severity=error".to_string(),
            "--plugin-logical-identity={\"slug\":\"child-before-rerun\",\"kind\":\"plugin-child\"}"
                .to_string(),
            "--path=wp-content/uploads/a.jpg".to_string(),
            "--path-prefix=wp-content/uploads".to_string(),
            "--review-status=needs-action".to_string(),
            "--reviewer=alice".to_string(),
            "--lifecycle-state=needs-action".to_string(),
            "--next-action=revalidate".to_string(),
            "--revalidation-class=compatible-target-drift".to_string(),
            "--latest-revalidation-status=target-drifted".to_string(),
            "--stale-status=stale".to_string(),
            "--resolution-choice=target".to_string(),
            "--blocked-resolution-choice=source".to_string(),
            "--resolution-strategy=cell-choice".to_string(),
            "--generic-resolver=yes".to_string(),
            "--after-revalidate=supported".to_string(),
            "--group-by=next-action".to_string(),
        ];
        let parsed = parse_cow_branch_merge_audit_args(&args).unwrap();
        assert_eq!(parsed.format, "json");
        assert_eq!(parsed.limit, "12");
        assert_eq!(parsed.run_id.as_deref(), Some("7"));
        assert_eq!(parsed.scope, "plugin");
        assert_eq!(parsed.records, "conflicts");
        assert_eq!(parsed.conflict_type.as_deref(), Some("row-target-deleted"));
        assert_eq!(parsed.conflict_id.as_deref(), Some("12"));
        assert_eq!(parsed.conflict_key.as_deref(), Some("sha256:abc123"));
        assert_eq!(parsed.event_type.as_deref(), Some("resolution-blocked"));
        assert_eq!(parsed.plugin.as_deref(), Some("forkpress-plugin-graph"));
        assert_eq!(parsed.plugin_object.as_deref(), Some("child:1000000"));
        assert_eq!(parsed.plugin_severity.as_deref(), Some("error"));
        assert_eq!(
            parsed.plugin_logical_identity.as_deref(),
            Some("{\"slug\":\"child-before-rerun\",\"kind\":\"plugin-child\"}")
        );
        assert_eq!(parsed.path.as_deref(), Some("wp-content/uploads/a.jpg"));
        assert_eq!(parsed.path_prefix.as_deref(), Some("wp-content/uploads"));
        assert_eq!(parsed.review_status.as_deref(), Some("needs-action"));
        assert_eq!(parsed.reviewer.as_deref(), Some("alice"));
        assert_eq!(parsed.lifecycle_state.as_deref(), Some("needs-action"));
        assert_eq!(parsed.next_action.as_deref(), Some("revalidate"));
        assert_eq!(
            parsed.revalidation_class.as_deref(),
            Some("compatible-target-drift")
        );
        assert_eq!(
            parsed.latest_revalidation_status.as_deref(),
            Some("target-drifted")
        );
        assert_eq!(parsed.stale_status.as_deref(), Some("stale"));
        assert_eq!(parsed.resolution_choice.as_deref(), Some("target"));
        assert_eq!(parsed.blocked_resolution_choice.as_deref(), Some("source"));
        assert_eq!(parsed.resolution_strategy.as_deref(), Some("cell-choice"));
        assert_eq!(parsed.generic_resolver.as_deref(), Some("yes"));
        assert_eq!(parsed.after_revalidate.as_deref(), Some("supported"));
        assert_eq!(parsed.group_by, "next-action");
    }

    #[test]
    fn parses_cow_branch_merge_audit_equals_form_decision_filters() {
        let args = vec![
            "merge-audit".to_string(),
            "--records=decisions".to_string(),
            "--decision=target-kept".to_string(),
            "--resolution-status=applied".to_string(),
        ];
        let parsed = parse_cow_branch_merge_audit_args(&args).unwrap();
        assert_eq!(parsed.records, "decisions");
        assert_eq!(parsed.decision.as_deref(), Some("target-kept"));
        assert_eq!(parsed.resolution_status.as_deref(), Some("applied"));
    }

    #[test]
    fn parses_cow_branch_merge_audit_resolution_plugin_grouping() {
        let args = vec![
            "merge-audit".to_string(),
            "--records=resolutions".to_string(),
            "--group-by=plugin-logical-identity".to_string(),
        ];
        let parsed = parse_cow_branch_merge_audit_args(&args).unwrap();
        assert_eq!(parsed.records, "resolutions");
        assert_eq!(parsed.group_by, "plugin-logical-identity");
    }

    #[test]
    fn parses_cow_branch_merge_audit_resolution_plugin_filters() {
        let args = vec![
            "merge-audit".to_string(),
            "--records=resolutions".to_string(),
            "--plugin=forkpress-plugin-logical-id".to_string(),
            "--plugin-object=child-slot:1000000".to_string(),
            "--plugin-severity=warning".to_string(),
            "--plugin-logical-identity={\"kind\":\"plugin-child\",\"slug\":\"child-before-rerun\"}"
                .to_string(),
        ];
        let parsed = parse_cow_branch_merge_audit_args(&args).unwrap();
        assert_eq!(parsed.records, "resolutions");
        assert_eq!(
            parsed.plugin.as_deref(),
            Some("forkpress-plugin-logical-id")
        );
        assert_eq!(parsed.plugin_object.as_deref(), Some("child-slot:1000000"));
        assert_eq!(parsed.plugin_severity.as_deref(), Some("warning"));
        assert_eq!(
            parsed.plugin_logical_identity.as_deref(),
            Some("{\"kind\":\"plugin-child\",\"slug\":\"child-before-rerun\"}")
        );
    }

    #[test]
    fn parses_cow_branch_merge_audit_id_band_skip_shortcut() {
        let args = vec!["merge-audit".to_string(), "--id-band-skips".to_string()];
        let parsed = parse_cow_branch_merge_audit_args(&args).unwrap();
        assert!(parsed.id_band_skips);
        assert_eq!(parsed.records, "all");
        assert_eq!(parsed.decision.as_deref(), None);
    }

    #[test]
    fn parses_cow_branch_conflicts_shortcut_defaults_to_conflicts() {
        let args = vec![
            "conflicts".to_string(),
            "--run=42".to_string(),
            "--lifecycle-state=needs-action".to_string(),
            "--group-by=next-action".to_string(),
        ];
        let parsed = parse_cow_branch_conflicts_args(&args).unwrap();
        assert_eq!(parsed.records, "conflicts");
        assert_eq!(parsed.run_id.as_deref(), Some("42"));
        assert_eq!(parsed.lifecycle_state.as_deref(), Some("needs-action"));
        assert_eq!(parsed.group_by, "next-action");
    }

    #[test]
    fn branch_conflicts_revalidate_keeps_revalidation_contract() {
        let args = vec![
            "conflicts".to_string(),
            "--revalidate".to_string(),
            "--run=7".to_string(),
            "--reviewer=alice".to_string(),
            "--format=json".to_string(),
            "--quiet".to_string(),
        ];
        let parsed = parse_cow_branch_conflicts_args(&args).unwrap();
        assert!(parsed.revalidate);
        assert_eq!(parsed.records, "all");
        assert_eq!(parsed.run_id.as_deref(), Some("7"));
        assert_eq!(parsed.reviewer.as_deref(), Some("alice"));
        assert_eq!(parsed.format, "json");
        assert!(parsed.quiet);
    }

    #[test]
    fn branch_merge_audit_revalidate_rejects_ignored_filters() {
        let args = vec![
            "merge-audit".to_string(),
            "--revalidate".to_string(),
            "--run=7".to_string(),
            "--lifecycle-state=needs-action".to_string(),
        ];
        let err = parse_cow_branch_merge_audit_args(&args)
            .unwrap_err()
            .to_string();
        assert!(err.contains("merge-audit --revalidate"));
        assert!(err.contains(
            "only accepts --run, --conflict-id, --conflict-key, --reviewer, --format, and --quiet"
        ));
    }

    #[test]
    fn branch_merge_audit_revalidate_accepts_quiet() {
        let args = vec![
            "merge-audit".to_string(),
            "--revalidate".to_string(),
            "--run=7".to_string(),
            "--conflict-key=sha256:abc123".to_string(),
            "--format=json".to_string(),
            "--quiet".to_string(),
        ];
        let parsed = parse_cow_branch_merge_audit_args(&args).unwrap();
        assert!(parsed.revalidate);
        assert_eq!(parsed.conflict_key.as_deref(), Some("sha256:abc123"));
        assert!(parsed.quiet);
    }

    #[test]
    fn branch_merge_audit_quiet_requires_revalidate() {
        let args = vec!["merge-audit".to_string(), "--quiet".to_string()];
        let err = parse_cow_branch_merge_audit_args(&args)
            .unwrap_err()
            .to_string();
        assert!(err.contains("--quiet"));
        assert!(err.contains("merge-audit --revalidate"));
    }

    #[test]
    fn branch_merge_audit_equals_form_rejects_empty_lifecycle_state() {
        let args = vec!["merge-audit".to_string(), "--lifecycle-state=".to_string()];
        let err = parse_cow_branch_merge_audit_args(&args)
            .unwrap_err()
            .to_string();
        assert!(err.contains("--lifecycle-state"));
        assert!(err.contains("requires"));
    }

    #[test]
    fn branch_merge_audit_conflict_key_requires_value() {
        let args = vec!["merge-audit".to_string(), "--conflict-key=".to_string()];
        let err = parse_cow_branch_merge_audit_args(&args)
            .unwrap_err()
            .to_string();
        assert!(err.contains("--conflict-key"));
        assert!(err.contains("requires"));
    }

    #[test]
    fn branch_merge_audit_event_type_requires_value() {
        let args = vec!["merge-audit".to_string(), "--event-type=".to_string()];
        let err = parse_cow_branch_merge_audit_args(&args)
            .unwrap_err()
            .to_string();
        assert!(err.contains("--event-type"));
        assert!(err.contains("requires"));
    }

    #[test]
    fn branch_merge_audit_conflict_id_requires_value() {
        let args = vec!["merge-audit".to_string(), "--conflict-id=".to_string()];
        let err = parse_cow_branch_merge_audit_args(&args)
            .unwrap_err()
            .to_string();
        assert!(err.contains("--conflict-id"));
        assert!(err.contains("requires"));
    }

    #[test]
    fn branch_merge_audit_plugin_filters_require_values() {
        let args = vec!["merge-audit".to_string(), "--plugin-object=".to_string()];
        let err = parse_cow_branch_merge_audit_args(&args)
            .unwrap_err()
            .to_string();
        assert!(err.contains("--plugin-object"));
        assert!(err.contains("requires an object"));

        let args = vec![
            "merge-audit".to_string(),
            "--plugin-logical-identity=".to_string(),
        ];
        let err = parse_cow_branch_merge_audit_args(&args)
            .unwrap_err()
            .to_string();
        assert!(err.contains("--plugin-logical-identity"));
        assert!(err.contains("requires a JSON value"));
    }

    #[test]
    fn branch_merge_audit_errors_on_unknown_flags() {
        let args = vec!["merge-audit".to_string(), "--target".to_string()];
        let err = parse_cow_branch_merge_audit_args(&args)
            .unwrap_err()
            .to_string();
        assert!(err.contains("unsupported argument"));
        assert!(err.contains("forkpress branch merge-audit"));
    }

    #[test]
    fn branch_conflicts_errors_use_conflicts_help() {
        let args = vec!["conflicts".to_string(), "--target".to_string()];
        let err = parse_cow_branch_conflicts_args(&args)
            .unwrap_err()
            .to_string();
        assert!(err.contains("unsupported argument"));
        assert!(err.contains("forkpress branch conflicts"));
        assert!(err.contains("merge-audit --records conflicts"));
    }

    #[test]
    fn branch_merge_audit_lifecycle_state_requires_value() {
        let args = vec!["merge-audit".to_string(), "--lifecycle-state".to_string()];
        let err = parse_cow_branch_merge_audit_args(&args)
            .unwrap_err()
            .to_string();
        assert!(err.contains("--lifecycle-state"));
        assert!(err.contains("requires"));
    }

    #[test]
    fn branch_merge_audit_next_action_requires_value() {
        let args = vec!["merge-audit".to_string(), "--next-action=".to_string()];
        let err = parse_cow_branch_merge_audit_args(&args)
            .unwrap_err()
            .to_string();
        assert!(err.contains("--next-action"));
        assert!(err.contains("requires"));
    }

    #[test]
    fn branch_merge_audit_stale_status_requires_value() {
        let args = vec!["merge-audit".to_string(), "--stale-status=".to_string()];
        let err = parse_cow_branch_merge_audit_args(&args)
            .unwrap_err()
            .to_string();
        assert!(err.contains("--stale-status"));
        assert!(err.contains("requires"));
    }

    #[test]
    fn branch_merge_audit_resolution_choice_requires_value() {
        let args = vec![
            "merge-audit".to_string(),
            "--blocked-resolution-choice=".to_string(),
        ];
        let err = parse_cow_branch_merge_audit_args(&args)
            .unwrap_err()
            .to_string();
        assert!(err.contains("--blocked-resolution-choice"));
        assert!(err.contains("requires"));
    }

    #[test]
    fn parses_branch_merge_audit_id_band_skip_shortcut() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "branch",
            "--work-dir",
            ".forkpress",
            "merge-audit",
            "--id-band-skips",
        ])
        .unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert_eq!(
            args.args,
            vec!["merge-audit".to_string(), "--id-band-skips".to_string()]
        );
    }

    #[test]
    fn parses_branch_merge_audit_target_kept_shortcut() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "branch",
            "--work-dir",
            ".forkpress",
            "merge-audit",
            "--target-kept",
        ])
        .unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert_eq!(
            args.args,
            vec!["merge-audit".to_string(), "--target-kept".to_string()]
        );
    }

    #[test]
    fn parses_branch_merge_audit_review_shortcut() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "branch",
            "--work-dir",
            ".forkpress",
            "merge-audit",
            "--review",
            "--review-status",
            "unreviewed",
        ])
        .unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert_eq!(
            args.args,
            vec![
                "merge-audit".to_string(),
                "--review".to_string(),
                "--review-status".to_string(),
                "unreviewed".to_string(),
            ]
        );
    }

    #[test]
    fn parses_branch_merge_audit_resolution_status_filter() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "branch",
            "--work-dir",
            ".forkpress",
            "merge-audit",
            "--records",
            "resolutions",
            "--resolution-status",
            "applied",
            "--group-by",
            "status",
        ])
        .unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert_eq!(
            args.args,
            vec![
                "merge-audit".to_string(),
                "--records".to_string(),
                "resolutions".to_string(),
                "--resolution-status".to_string(),
                "applied".to_string(),
                "--group-by".to_string(),
                "status".to_string(),
            ]
        );
    }

    #[test]
    fn parses_branch_merge_audit_conflict_grouping() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "branch",
            "--work-dir",
            ".forkpress",
            "merge-audit",
            "--records",
            "conflicts",
            "--group-by",
            "severity",
        ])
        .unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert_eq!(
            args.args,
            vec![
                "merge-audit".to_string(),
                "--records".to_string(),
                "conflicts".to_string(),
                "--group-by".to_string(),
                "severity".to_string(),
            ]
        );
    }

    #[test]
    fn parses_branch_merge_audit_conflict_key_grouping() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "branch",
            "--work-dir",
            ".forkpress",
            "merge-audit",
            "--records",
            "conflicts",
            "--group-by=conflict-key",
        ])
        .unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert_eq!(
            args.args,
            vec![
                "merge-audit".to_string(),
                "--records".to_string(),
                "conflicts".to_string(),
                "--group-by=conflict-key".to_string(),
            ]
        );
    }

    #[test]
    fn parses_branch_merge_audit_revalidation_class_filter_and_grouping() {
        let args = vec![
            "merge-audit".to_string(),
            "--records=conflicts".to_string(),
            "--revalidation-class=compatible-target-drift".to_string(),
            "--group-by=revalidation-class".to_string(),
        ];
        let parsed = parse_cow_branch_merge_audit_args(&args).unwrap();
        assert_eq!(parsed.records, "conflicts");
        assert_eq!(
            parsed.revalidation_class.as_deref(),
            Some("compatible-target-drift")
        );
        assert_eq!(parsed.group_by, "revalidation-class");
    }

    #[test]
    fn parses_branch_merge_audit_latest_revalidation_status_filter_and_grouping() {
        let args = vec![
            "merge-audit".to_string(),
            "--records=conflicts".to_string(),
            "--latest-revalidation-status=target-drifted".to_string(),
            "--group-by=latest-revalidation-status".to_string(),
        ];
        let parsed = parse_cow_branch_merge_audit_args(&args).unwrap();
        assert_eq!(parsed.records, "conflicts");
        assert_eq!(
            parsed.latest_revalidation_status.as_deref(),
            Some("target-drifted")
        );
        assert_eq!(parsed.group_by, "latest-revalidation-status");
    }

    #[test]
    fn parses_branch_merge_audit_stale_status_filter_and_grouping() {
        let args = vec![
            "merge-audit".to_string(),
            "--records=conflicts".to_string(),
            "--stale-status=stale".to_string(),
            "--group-by=stale-status".to_string(),
        ];
        let parsed = parse_cow_branch_merge_audit_args(&args).unwrap();
        assert_eq!(parsed.records, "conflicts");
        assert_eq!(parsed.stale_status.as_deref(), Some("stale"));
        assert_eq!(parsed.group_by, "stale-status");
    }

    #[test]
    fn parses_branch_merge_audit_plugin_grouping() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "branch",
            "--work-dir",
            ".forkpress",
            "merge-audit",
            "--scope",
            "plugin",
            "--group-by",
            "plugin",
        ])
        .unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert_eq!(
            args.args,
            vec![
                "merge-audit".to_string(),
                "--scope".to_string(),
                "plugin".to_string(),
                "--group-by".to_string(),
                "plugin".to_string(),
            ]
        );
    }

    #[test]
    fn parses_branch_merge_audit_plugin_severity_grouping() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "branch",
            "--work-dir",
            ".forkpress",
            "merge-audit",
            "--scope",
            "plugin",
            "--group-by=plugin-severity",
        ])
        .unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert_eq!(
            args.args,
            vec![
                "merge-audit".to_string(),
                "--scope".to_string(),
                "plugin".to_string(),
                "--group-by=plugin-severity".to_string(),
            ]
        );
    }

    #[test]
    fn parses_branch_merge_audit_plugin_object_grouping() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "branch",
            "--work-dir",
            ".forkpress",
            "merge-audit",
            "--scope",
            "plugin",
            "--group-by=plugin-object",
        ])
        .unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert_eq!(
            args.args,
            vec![
                "merge-audit".to_string(),
                "--scope".to_string(),
                "plugin".to_string(),
                "--group-by=plugin-object".to_string(),
            ]
        );
    }

    #[test]
    fn parses_branch_merge_audit_plugin_logical_identity_grouping() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "branch",
            "--work-dir",
            ".forkpress",
            "merge-audit",
            "--scope",
            "plugin",
            "--group-by=plugin-logical-identity",
        ])
        .unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert_eq!(
            args.args,
            vec![
                "merge-audit".to_string(),
                "--scope".to_string(),
                "plugin".to_string(),
                "--group-by=plugin-logical-identity".to_string(),
            ]
        );
    }

    #[test]
    fn parses_branch_merge_audit_plugin_resolution_grouping() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "branch",
            "--work-dir",
            ".forkpress",
            "merge-audit",
            "--records",
            "resolutions",
            "--group-by=plugin-logical-identity",
        ])
        .unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert_eq!(
            args.args,
            vec![
                "merge-audit".to_string(),
                "--records".to_string(),
                "resolutions".to_string(),
                "--group-by=plugin-logical-identity".to_string(),
            ]
        );
    }

    #[test]
    fn parses_branch_merge_audit_lifecycle_queue_grouping() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "branch",
            "--work-dir",
            ".forkpress",
            "merge-audit",
            "--records",
            "conflicts",
            "--lifecycle-state",
            "needs-action",
            "--group-by",
            "lifecycle",
        ])
        .unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert_eq!(
            args.args,
            vec![
                "merge-audit".to_string(),
                "--records".to_string(),
                "conflicts".to_string(),
                "--lifecycle-state".to_string(),
                "needs-action".to_string(),
                "--group-by".to_string(),
                "lifecycle".to_string(),
            ]
        );
    }

    #[test]
    fn parses_branch_merge_audit_next_action_queue_grouping() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "branch",
            "--work-dir",
            ".forkpress",
            "merge-audit",
            "--records",
            "conflicts",
            "--next-action",
            "resolve",
            "--group-by=next-action",
        ])
        .unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert_eq!(
            args.args,
            vec![
                "merge-audit".to_string(),
                "--records".to_string(),
                "conflicts".to_string(),
                "--next-action".to_string(),
                "resolve".to_string(),
                "--group-by=next-action".to_string(),
            ]
        );
    }

    #[test]
    fn parses_branch_merge_audit_conflict_events() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "branch",
            "--work-dir",
            ".forkpress",
            "merge-audit",
            "--records",
            "conflict-events",
            "--scope",
            "db",
            "--conflict-type",
            "row-target-deleted",
            "--group-by",
            "event-type",
        ])
        .unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert_eq!(
            args.args,
            vec![
                "merge-audit".to_string(),
                "--records".to_string(),
                "conflict-events".to_string(),
                "--scope".to_string(),
                "db".to_string(),
                "--conflict-type".to_string(),
                "row-target-deleted".to_string(),
                "--group-by".to_string(),
                "event-type".to_string(),
            ]
        );
    }

    #[test]
    fn parses_branch_merge_audit_decision_grouping() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "branch",
            "--work-dir",
            ".forkpress",
            "merge-audit",
            "--records",
            "decisions",
            "--group-by",
            "type",
        ])
        .unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert_eq!(
            args.args,
            vec![
                "merge-audit".to_string(),
                "--records".to_string(),
                "decisions".to_string(),
                "--group-by".to_string(),
                "type".to_string(),
            ]
        );
    }

    #[test]
    fn parses_branch_merge_audit_rollback_failures() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "branch",
            "--work-dir",
            ".forkpress",
            "merge-audit",
            "--records",
            "rollback-failures",
            "--run",
            "9",
        ])
        .unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert_eq!(
            args.args,
            vec![
                "merge-audit".to_string(),
                "--records".to_string(),
                "rollback-failures".to_string(),
                "--run".to_string(),
                "9".to_string(),
            ]
        );
    }

    #[test]
    fn parses_branch_merge_review_args() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "branch",
            "--work-dir",
            ".forkpress",
            "merge-review",
            "conflict",
            "12",
            "--status",
            "reviewed",
            "--note",
            "Keep target content",
            "--reviewer",
            "alice",
        ])
        .unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert_eq!(
            args.args,
            vec![
                "merge-review".to_string(),
                "conflict".to_string(),
                "12".to_string(),
                "--status".to_string(),
                "reviewed".to_string(),
                "--note".to_string(),
                "Keep target content".to_string(),
                "--reviewer".to_string(),
                "alice".to_string(),
            ]
        );
    }

    #[test]
    fn parses_branch_merge_review_conflict_key_args() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "branch",
            "--work-dir",
            ".forkpress",
            "merge-review",
            "conflict-key",
            "sha256:abc123",
            "--run",
            "9",
            "--status",
            "reviewed",
            "--note",
            "Keep target content",
            "--reviewer",
            "alice",
        ])
        .unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert_eq!(
            args.args,
            vec![
                "merge-review".to_string(),
                "conflict-key".to_string(),
                "sha256:abc123".to_string(),
                "--run".to_string(),
                "9".to_string(),
                "--status".to_string(),
                "reviewed".to_string(),
                "--note".to_string(),
                "Keep target content".to_string(),
                "--reviewer".to_string(),
                "alice".to_string(),
            ]
        );
    }

    #[test]
    fn parses_branch_merge_resolve_args() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "branch",
            "--work-dir",
            ".forkpress",
            "merge-resolve",
            "conflict",
            "12",
            "--choice",
            "source",
            "--apply",
            "--after-revalidate",
            "--note",
            "Use source title",
            "--reviewer",
            "alice",
        ])
        .unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert_eq!(
            args.args,
            vec![
                "merge-resolve".to_string(),
                "conflict".to_string(),
                "12".to_string(),
                "--choice".to_string(),
                "source".to_string(),
                "--apply".to_string(),
                "--after-revalidate".to_string(),
                "--note".to_string(),
                "Use source title".to_string(),
                "--reviewer".to_string(),
                "alice".to_string(),
            ]
        );
    }

    #[test]
    fn parses_branch_merge_resolve_apply_reviewed_args() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "branch",
            "--work-dir",
            ".forkpress",
            "merge-resolve",
            "conflict",
            "12",
            "--apply-reviewed",
            "--note",
            "Apply validated choice",
            "--reviewer",
            "alice",
        ])
        .unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert_eq!(
            args.args,
            vec![
                "merge-resolve".to_string(),
                "conflict".to_string(),
                "12".to_string(),
                "--apply-reviewed".to_string(),
                "--note".to_string(),
                "Apply validated choice".to_string(),
                "--reviewer".to_string(),
                "alice".to_string(),
            ]
        );
    }

    #[test]
    fn parses_branch_merge_resolve_conflict_key_args() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "branch",
            "--work-dir",
            ".forkpress",
            "merge-resolve",
            "conflict-key",
            "sha256:abc123",
            "--run",
            "9",
            "--choice",
            "target",
            "--note",
            "Keep target value",
            "--reviewer",
            "alice",
        ])
        .unwrap();
        let Commands::Branch(args) = cli.command else {
            panic!("expected branch command");
        };
        assert_eq!(
            args.args,
            vec![
                "merge-resolve".to_string(),
                "conflict-key".to_string(),
                "sha256:abc123".to_string(),
                "--run".to_string(),
                "9".to_string(),
                "--choice".to_string(),
                "target".to_string(),
                "--note".to_string(),
                "Keep target value".to_string(),
                "--reviewer".to_string(),
                "alice".to_string(),
            ]
        );
    }

    #[test]
    fn parses_remote_add_wp_cow_clone() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "remote",
            "--work-dir",
            ".forkpress",
            "add",
            "calm-cottage",
            "--wp-cow-clone",
            "calm-cottage",
            "--force",
        ])
        .unwrap();
        let Commands::Remote(args) = cli.command else {
            panic!("expected remote command");
        };
        let RemoteCommand::Add(add) = args.command else {
            panic!("expected remote add command");
        };
        assert_eq!(args.shared.work_dir, PathBuf::from(".forkpress"));
        assert_eq!(add.name, "calm-cottage");
        assert_eq!(add.wp_cow_clone.as_deref(), Some("calm-cottage"));
        assert!(add.force);
    }

    #[test]
    fn parses_remote_clone_thin_cache_defaults() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "remote",
            "--work-dir",
            ".forkpress",
            "clone",
            "production",
            "--ssh",
            "deploy@example.com",
            "--ssh-key",
            "/Users/alex/.ssh/forkpress id",
            "--ssh-port",
            "2222",
            "--path",
            "/srv/www/example",
            "--branch",
            "prod-main",
            "--url",
            "https://example.com",
            "--force",
        ])
        .unwrap();
        let Commands::Remote(args) = cli.command else {
            panic!("expected remote command");
        };
        let RemoteCommand::Clone(clone) = args.command else {
            panic!("expected remote clone command");
        };
        assert_eq!(args.shared.work_dir, PathBuf::from(".forkpress"));
        assert_eq!(clone.name, "production");
        assert_eq!(clone.ssh, "deploy@example.com");
        assert_eq!(
            clone.ssh_key.as_deref(),
            Some(Path::new("/Users/alex/.ssh/forkpress id"))
        );
        assert_eq!(clone.ssh_port, Some(2222));
        assert_eq!(clone.remote_path, "/srv/www/example");
        assert_eq!(clone.branch.as_deref(), Some("prod-main"));
        assert_eq!(clone.remote_url.as_deref(), Some("https://example.com"));
        assert!(!clone.include_uploads);
        assert!(!clone.full_sync);
        assert!(clone.force);

        let rsync = remote_clone_rsync_args(
            &clone,
            Path::new("/tmp/forkpress/.forkpress/cow/remote-sites/production/cache"),
        );
        let rsync: Vec<String> = rsync
            .into_iter()
            .map(|arg| arg.to_string_lossy().into_owned())
            .collect();
        assert_eq!(rsync[0], "-az");
        assert_eq!(
            rsync.iter().position(|arg| arg == "-e").map(|index| {
                rsync
                    .get(index + 1)
                    .expect("missing rsync ssh command after -e")
                    .as_str()
            }),
            Some("ssh -i '/Users/alex/.ssh/forkpress id' -p 2222 -o ConnectTimeout=10")
        );
        assert!(rsync.contains(&"--delete".to_string()));
        assert!(rsync.contains(&"wp-content/uploads/".to_string()));
        assert!(rsync.contains(&"wp-content/cache/".to_string()));
        assert!(rsync.contains(&"wp-content/upgrade/".to_string()));
        assert!(rsync.contains(&"wp-content/backups/".to_string()));
        assert!(rsync.contains(&"wp-content/debug.log".to_string()));
        assert!(rsync.contains(&".git/".to_string()));
        assert!(rsync.contains(&"deploy@example.com:/srv/www/example/".to_string()));
    }

    #[test]
    fn remote_clone_include_uploads_keeps_other_boot_excludes() {
        let clone = RemoteCloneArgs {
            name: "Production".to_string(),
            ssh: "deploy@example.com".to_string(),
            ssh_key: None,
            ssh_port: None,
            remote_path: "/srv/www/example/".to_string(),
            branch: None,
            remote_url: None,
            local_url: None,
            include_uploads: true,
            full_sync: false,
            excludes: vec!["wp-content/cache/".to_string()],
            no_delete: true,
            force: false,
        };
        let rsync = remote_clone_rsync_args(&clone, Path::new("/tmp/cache"));
        let rsync: Vec<String> = rsync
            .into_iter()
            .map(|arg| arg.to_string_lossy().into_owned())
            .collect();
        assert!(!rsync.contains(&"--delete".to_string()));
        assert!(!rsync.contains(&"-e".to_string()));
        assert!(!rsync.contains(&"wp-content/uploads/".to_string()));
        assert!(rsync.contains(&"wp-content/cache/".to_string()));
        assert!(rsync.contains(&"wp-content/upgrade/".to_string()));
        assert!(rsync.contains(&"deploy@example.com:/srv/www/example/".to_string()));
    }

    #[test]
    fn remote_clone_full_sync_disables_boot_excludes() {
        let cli = Cli::try_parse_from([
            "forkpress",
            "remote",
            "clone",
            "production",
            "--ssh",
            "deploy@example.com",
            "--path",
            "/srv/www/example/",
            "--full-sync",
            "--exclude",
            "private/",
        ])
        .unwrap();
        let Commands::Remote(args) = cli.command else {
            panic!("expected remote command");
        };
        let RemoteCommand::Clone(clone) = args.command else {
            panic!("expected remote clone command");
        };
        assert!(clone.full_sync);
        assert!(!clone.include_uploads);

        let rsync = remote_clone_rsync_args(&clone, Path::new("/tmp/cache"));
        let rsync: Vec<String> = rsync
            .into_iter()
            .map(|arg| arg.to_string_lossy().into_owned())
            .collect();
        assert!(rsync.contains(&"--delete".to_string()));
        assert!(!rsync.contains(&"wp-content/uploads/".to_string()));
        assert!(!rsync.contains(&"wp-content/cache/".to_string()));
        assert!(!rsync.contains(&"wp-content/upgrade/".to_string()));
        assert!(rsync.contains(&"private/".to_string()));
        assert!(rsync.contains(&"deploy@example.com:/srv/www/example/".to_string()));
    }

    #[test]
    fn remote_clone_rsync_ssh_command_is_omitted_without_credentials() {
        let clone = RemoteCloneArgs {
            name: "production".to_string(),
            ssh: "deploy@example.com".to_string(),
            ssh_key: None,
            ssh_port: None,
            remote_path: "/srv/www/example".to_string(),
            branch: None,
            remote_url: None,
            local_url: None,
            include_uploads: false,
            full_sync: false,
            excludes: Vec::new(),
            no_delete: false,
            force: false,
        };
        assert_eq!(remote_clone_rsync_ssh_command(&clone), None);
    }

    #[test]
    fn remote_clone_mysql_export_ssh_reuses_credentials() {
        let clone = RemoteCloneArgs {
            name: "production".to_string(),
            ssh: "deploy@example.com".to_string(),
            ssh_key: Some(PathBuf::from("/Users/alex/.ssh/forkpress id")),
            ssh_port: Some(2222),
            remote_path: "/srv/www/example with spaces".to_string(),
            branch: None,
            remote_url: None,
            local_url: None,
            include_uploads: false,
            full_sync: false,
            excludes: Vec::new(),
            no_delete: false,
            force: false,
        };
        let mut command = remote_clone_ssh_command(&clone);
        command
            .arg(&clone.ssh)
            .arg(format!("php -- {}", shell_quote(&clone.remote_path)));
        let args: Vec<String> = command
            .get_args()
            .map(|arg| arg.to_string_lossy().into_owned())
            .collect();
        assert_eq!(
            args,
            vec![
                "-i",
                "/Users/alex/.ssh/forkpress id",
                "-p",
                "2222",
                "-o",
                "ConnectTimeout=10",
                "deploy@example.com",
                "php -- '/srv/www/example with spaces'",
            ]
        );
    }

    #[test]
    fn remote_clone_rsync_timeout_error_is_actionable() {
        let clone = RemoteCloneArgs {
            name: "production".to_string(),
            ssh: "deploy@example.com".to_string(),
            ssh_key: Some(PathBuf::from("/Users/alex/.ssh/forkpress id")),
            ssh_port: Some(2222),
            remote_path: "/srv/www/example".to_string(),
            branch: Some("production-main".to_string()),
            remote_url: Some("https://example.com".to_string()),
            local_url: None,
            include_uploads: false,
            full_sync: false,
            excludes: Vec::new(),
            no_delete: false,
            force: false,
        };
        let message = remote_clone_rsync_failure_message(
            &clone,
            "exit status: 255",
            "ssh: connect to host example.com port 2222: Operation timed out\nrsync error: unexplained error (code 255)",
        );

        assert!(message.contains("remote clone could not sync deploy@example.com:/srv/www/example/ with rsync (exit status: 255)."));
        assert!(
            message.contains(
                "SSH did not connect to deploy@example.com on port 2222 before timing out."
            )
        );
        assert!(message.contains("network/hosting reachability problem"));
        assert!(message.contains("hosting firewall or IP allowlist"));
        assert!(message.contains("ssh -i '/Users/alex/.ssh/forkpress id' -p 2222 -o ConnectTimeout=10 -o BatchMode=yes deploy@example.com 'test -f /srv/www/example/wp-load.php'"));
        assert!(message.contains("rsync stderr:"));
        assert!(message.contains("Operation timed out"));
    }

    #[test]
    fn remote_clone_ssh_check_command_quotes_remote_paths() {
        let clone = RemoteCloneArgs {
            name: "production".to_string(),
            ssh: "deploy@example.com".to_string(),
            ssh_key: None,
            ssh_port: None,
            remote_path: "/srv/www/example with spaces".to_string(),
            branch: None,
            remote_url: None,
            local_url: None,
            include_uploads: false,
            full_sync: false,
            excludes: Vec::new(),
            no_delete: false,
            force: false,
        };

        assert_eq!(
            remote_clone_ssh_check_command(&clone),
            "ssh -o ConnectTimeout=10 -o BatchMode=yes deploy@example.com 'test -f '\\''/srv/www/example with spaces/wp-load.php'\\'''"
        );
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

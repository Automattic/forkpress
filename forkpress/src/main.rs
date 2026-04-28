use anyhow::{Context, Result, anyhow, bail};
use clap::{ArgAction, Args, Parser, Subcommand};
use flate2::read::GzDecoder;
use std::ffi::{OsStr, OsString};
use std::fs::{self, File, OpenOptions};
use std::io::{Cursor, Write};
use std::net::{TcpStream, ToSocketAddrs};
use std::path::PathBuf;
use std::process::{Child, Command, ExitStatus, Stdio};
use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering};
use std::thread;
use std::time::{Duration, Instant};
use zip::ZipArchive;

const RUNTIME_BUNDLE: &[u8] = include_bytes!(env!("FORKPRESS_RUNTIME_BUNDLE"));
const STARTUP_WARNING_FILTER: &str = "Missing arginfo";

#[derive(Parser, Debug)]
#[command(
    name = "forkpress",
    version,
    about = "Single-binary WordPress with git-style branching"
)]
struct Cli {
    #[command(subcommand)]
    command: Commands,
}

#[derive(Subcommand, Debug)]
enum Commands {
    /// Create a new site.fp and seed the default admin user.
    Init(InitArgs),
    /// Clone the ForkPress git remote into a local checkout.
    Clone(CloneArgs),
    /// Pull the current checkout with rebase/autostash.
    Pull(PullArgs),
    /// Start the local preview server.
    #[command(alias = "serve")]
    Start(StartArgs),
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
    #[command(alias = "branchctl")]
    Branchctl(BranchPassthrough),
    /// Manage authentication users (add/list/remove/verify/auth-enabled).
    User(UserPassthrough),
    /// Consistent hot-copy of a running .fp file via SQLite VACUUM INTO.
    Backup(BackupArgs),
    /// Write a .fp file to a portable directory tree (files + SQL + manifest).
    Export(ExportArgs),
    /// Rebuild a .fp from a directory tree produced by `forkpress export`.
    Import(ImportArgs),
}

#[derive(Args, Debug, Clone)]
struct InitArgs {
    #[command(flatten)]
    shared: SharedPaths,

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
struct SharedPaths {
    #[arg(long, default_value = ".forkpress")]
    work_dir: PathBuf,

    #[arg(long)]
    php_bin: Option<PathBuf>,
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
    runtime_dir: PathBuf,
    logs_dir: PathBuf,
    site_fp: PathBuf,
    wp_root: PathBuf,
    debug_log: PathBuf,
    php_error_log: PathBuf,
    php_server_log: PathBuf,
    runtime_ready_marker: PathBuf,
    bootstrap_marker: PathBuf,
}

#[derive(Debug, Clone)]
struct PortableRuntime {
    php: PathBuf,
}

struct ChildGuard {
    name: &'static str,
    child: Child,
}

impl ChildGuard {
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
        Commands::Agents(args) => agents_command(args),
        Commands::Push(args) | Commands::Commit(args) => push_command(args),
        Commands::Git(args) => git_command(args),
        Commands::Branch(args) | Commands::Branchctl(args) => branch_command(args),
        Commands::User(args) => user_command(args),
        Commands::Backup(args) => backup_command(args),
        Commands::Export(args) => export_command(args),
        Commands::Import(args) => import_command(args),
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
    prepare_runtime(&layout)?;
    let runtime = PortableRuntime::from_layout(&layout);

    if layout.site_fp.exists() {
        bail!(
            "init: a site.fp already exists at {}. Remove it or choose a different --work-dir.",
            layout.site_fp.display()
        );
    }

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

    println!(
        "forkpress: site initialised at {}",
        layout.site_fp.display()
    );
    println!("  title:     {}", args.site_title);
    println!("  root host: {}", args.root_host);
    Ok(0)
}

fn user_command(args: UserPassthrough) -> Result<i32> {
    if args.args.is_empty() {
        bail!("user requires a subcommand, e.g. `forkpress user add alice s3cret --role write`");
    }
    let layout = Layout::new(args.shared.work_dir.clone())?;
    prepare_runtime(&layout)?;
    let runtime = PortableRuntime::from_layout(&layout);

    if !layout.site_fp.exists() {
        bail!(
            "no site.fp found in {}. Run `forkpress init` first.",
            layout.work_dir.display()
        );
    }

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

fn backup_command(args: BackupArgs) -> Result<i32> {
    let layout = Layout::new(args.shared.work_dir.clone())?;
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

fn start_command(args: StartArgs) -> Result<i32> {
    let layout = Layout::new(args.shared.work_dir.clone())?;
    prepare_runtime(&layout)?;
    ensure_ports_available(&args)?;

    let runtime = PortableRuntime::from_layout(&layout);

    ensure_bootstrapped(&layout, &runtime, &args)?;

    let workers = args.workers.unwrap_or_else(default_worker_count);
    let mut php = start_php_server(&layout, &runtime, &args, workers)?;

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
    println!(
        "Git remote: http://{}:{}/site.git",
        args.root_host, args.port
    );
    println!("DB access:  database.sql in each git branch checkout (read-only snapshot)");
    println!("Logs:       {}", layout.logs_dir.display());
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

    Ok(0)
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
        prepare_runtime(&layout)?;
        let runtime = PortableRuntime::from_layout(&layout);
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
    prepare_runtime(&layout)?;
    let runtime = PortableRuntime::from_layout(&layout);

    if !layout.site_fp.exists() || !layout.bootstrap_marker.exists() {
        bail!(
            "no bootstrapped site found in {}. Run `forkpress start` first",
            layout.work_dir.display()
        );
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
        ensure_branch_exists(&layout, &runtime, &args.shared, &branch, &args.from, &auth)?;
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
    prepare_runtime(&layout)?;
    let runtime = PortableRuntime::from_layout(&layout);

    if !layout.site_fp.exists() || !layout.bootstrap_marker.exists() {
        bail!(
            "no bootstrapped site found in {}. Run `forkpress start` first",
            layout.work_dir.display()
        );
    }

    let mut command = php_base_command(&layout, &runtime, &args.shared);
    command.arg(layout.runtime_dir.join("scripts/branchctl.php"));
    for arg in &args.args {
        command.arg(arg);
    }
    command.env("BRANCHFS_DB", &layout.site_fp);
    command.env("BRANCHFS_SQLITE_WP_DB", &layout.site_fp);
    command.env("BRANCHFS_ROOT_HOST", "wp.localhost");
    command.env("PORT", "18080");

    let output = command
        .output()
        .context("failed to run branch command via bundled php")?;

    write_filtered_output(&output.stdout, &output.stderr)?;

    Ok(output.status.code().unwrap_or(1))
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
    let mut command = php_base_command(layout, runtime, shared);
    command
        .arg(layout.runtime_dir.join("scripts/branchctl.php"))
        .arg("create")
        .arg(branch)
        .arg("--from")
        .arg(from)
        .env("BRANCHFS_DB", &layout.site_fp)
        .env("BRANCHFS_SQLITE_WP_DB", &layout.site_fp)
        .env("BRANCHFS_ROOT_HOST", "wp.localhost")
        .env("PORT", "18080");
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
        Ok(Self {
            runtime_dir: work_dir.join("runtime"),
            logs_dir: work_dir.join("logs"),
            site_fp: work_dir.join("site.fp"),
            wp_root: work_dir.join("wproot"),
            debug_log: work_dir.join("logs/wp-debug.log"),
            php_error_log: work_dir.join("logs/php-errors.log"),
            php_server_log: work_dir.join("logs/php-server.log"),
            runtime_ready_marker: work_dir.join("runtime/.forkpress-runtime-ready"),
            bootstrap_marker: work_dir.join(".forkpress-bootstrap-complete"),
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

fn prepare_runtime(layout: &Layout) -> Result<()> {
    fs::create_dir_all(&layout.work_dir)?;
    fs::create_dir_all(&layout.logs_dir)?;
    fs::create_dir_all(&layout.wp_root)?;

    if !layout.runtime_ready_marker.exists() {
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
        File::create(&layout.runtime_ready_marker)?;
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

fn ensure_bootstrapped(layout: &Layout, runtime: &PortableRuntime, args: &StartArgs) -> Result<()> {
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
}

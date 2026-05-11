use assert_cmd::Command;
use std::env;
use std::ffi::{OsStr, OsString};
use std::fs;
use std::io::{Read, Write};
use std::net::{TcpListener, TcpStream};
use std::path::{Path, PathBuf};
use std::process::{Command as StdCommand, Output, Stdio};
use std::thread;
use std::time::{Duration, Instant};
use tempfile::TempDir;

const PRODUCTION_COMMANDS: &[&str] = &[
    "agents",
    "branch",
    "branchctl",
    "clone",
    "commit",
    "doctor",
    "git",
    "init",
    "logs",
    "pull",
    "push",
    "serve",
    "server",
    "start",
    "stop",
    "storage",
];

const DEV_ONLY_COMMANDS: &[&str] = &["backup", "export", "import", "user", "zfs"];

macro_rules! argv {
    ($($arg:expr),* $(,)?) => {{
        vec![$(OsString::from($arg)),*]
    }};
}

#[test]
#[ignore = "requires FORKPRESS_E2E_BIN to point to a built production forkpress binary"]
fn production_cli_exhaustive_e2e() {
    let mut harness = Harness::new();
    harness.command_surface();
    harness.parser_and_early_validation_failures();
    harness.no_site_diagnostics();
    harness.init_site();
    harness.server_entrypoints();
    harness.start_main_server_with_serve();
    harness.branch_commands();
    harness.git_checkout_commands();
    harness.agent_worktrees();
    harness.log_commands();
    harness.storage_commands();
    harness.stop_main_server();
    harness.foreground_start_exits_on_sigint();
    harness.post_stop_storage_commands();
    harness.readme_command_docs();
}

struct Harness {
    bin: PathBuf,
    repo_root: PathBuf,
    temp: TempDir,
    state_dir: PathBuf,
    work_root: PathBuf,
    work_dir: PathBuf,
    port: u16,
}

impl Harness {
    fn new() -> Self {
        let repo_root = repo_root();
        let bin = env::var_os("FORKPRESS_E2E_BIN")
            .map(PathBuf::from)
            .unwrap_or_else(|| panic!("FORKPRESS_E2E_BIN is required"));
        let bin = if bin.is_absolute() {
            bin
        } else {
            repo_root.join(bin)
        };
        assert!(
            bin.is_file(),
            "FORKPRESS_E2E_BIN does not point to a file: {}",
            bin.display()
        );

        let temp = TempDir::new().expect("failed to create e2e temp dir");
        let state_dir = temp.path().join("state");
        let work_root = temp.path().join("site");
        let work_dir = work_root.join(".forkpress");
        fs::create_dir_all(&state_dir).expect("failed to create e2e state dir");

        Self {
            bin,
            repo_root,
            temp,
            state_dir,
            work_root,
            work_dir,
            port: unused_port(),
        }
    }

    fn command_surface(&self) {
        step("top-level production command surface");

        let top_help = self.success(argv!("--help"));
        assert_eq!(
            top_commands(&top_help),
            PRODUCTION_COMMANDS,
            "unexpected production command set\n{top_help}"
        );
        for command in DEV_ONLY_COMMANDS {
            assert_not_contains(&top_help, &format!("  {command}"), "dev command leaked");
            let output = self.failure(argv!(command));
            assert_contains(
                &output,
                "unrecognized subcommand",
                "dev command should not be accepted",
            );
        }

        let version = self.success(argv!("--version"));
        assert_contains(&version, "forkpress ", "version output");

        let init_help = self.success(argv!("init", "--help"));
        assert_contains(&init_help, "--admin-password <ADMIN_PASSWORD>", "init help");
        assert_not_contains(&init_help, "--strategy", "production init help");

        let start_help = self.success(argv!("start", "--help"));
        for expected in [
            "--work-dir <WORK_DIR>",
            "--php-bin <PHP_BIN>",
            "--host <HOST>",
            "--port <PORT>",
            "--root-host <ROOT_HOST>",
            "--site-title <SITE_TITLE>",
            "--workers <WORKERS>",
            "--background",
        ] {
            assert_contains(&start_help, expected, "start help");
        }
        assert_not_contains(&start_help, "--gc-interval", "production start help");

        let serve_help = self.success(argv!("serve", "--help"));
        assert_contains(&serve_help, "--foreground", "serve help");
        assert_not_contains(&serve_help, "--gc-interval", "production serve help");

        let clone_help = self.success(argv!("clone", "--help"));
        assert_contains(&clone_help, "--remote-name <REMOTE_NAME>", "clone help");

        let pull_help = self.success(argv!("pull", "--help"));
        assert_contains(&pull_help, "[REPO]", "pull help");

        let push_help = self.success(argv!("push", "--help"));
        assert_contains(&push_help, "--message <MESSAGE>", "push help");
        assert_contains(&push_help, "--remote-name <REMOTE_NAME>", "push help");

        let commit_help = self.success(argv!("commit", "--help"));
        assert_contains(&commit_help, "--message <MESSAGE>", "commit help");

        let logs_help = self.success(argv!("logs", "--help"));
        for expected in [
            "--file <FILE>",
            "--lines <LINES>",
            "--follow",
            "--paths",
            "wp:",
            "php:",
            "server:",
            "forkpress:",
            "gc:",
            "all:",
        ] {
            assert_contains(&logs_help, expected, "logs help");
        }

        let server_help = self.success(argv!("server", "--help"));
        for expected in ["start", "list", "stop"] {
            assert_contains(&server_help, expected, "server help");
        }

        let server_stop_help = self.success(argv!("server", "stop", "--help"));
        for expected in ["--all", "--pid <PID>", "--timeout <TIMEOUT>", "--force"] {
            assert_contains(&server_stop_help, expected, "server stop help");
        }

        let storage_help = self.success(argv!("storage", "--help"));
        for expected in ["status", "mount", "detach", "compact"] {
            assert_contains(&storage_help, expected, "storage help");
        }

        let doctor_help = self.success(argv!("doctor", "--help"));
        assert_contains(&doctor_help, "storage", "doctor help");

        for passthrough in ["branch", "branchctl", "git"] {
            let help = self.success(argv!(passthrough, "--help"));
            assert_contains(&help, "Usage:", &format!("{passthrough} passthrough help"));
        }
    }

    fn parser_and_early_validation_failures(&self) {
        step("parser and early validation failures");

        let missing_command = self.failure(Vec::<OsString>::new());
        assert_contains(
            &missing_command,
            "Usage: forkpress <COMMAND>",
            "missing command",
        );

        let init_dev_strategy = self.failure(argv!("init", "--strategy", "cow"));
        assert_contains(
            &init_dev_strategy,
            "unexpected argument '--strategy'",
            "production strategy rejection",
        );

        let conflicting_serve_flags = self.failure(argv!("serve", "--background", "--foreground"));
        assert_contains(
            &conflicting_serve_flags,
            "cannot be used with",
            "serve conflict",
        );

        for command in ["doctor", "storage", "server"] {
            let output = self.failure(argv!(command));
            assert_contains(&output, "Usage:", &format!("{command} missing subcommand"));
        }

        let empty_branch = self.failure(argv!("branch"));
        assert_contains(
            &empty_branch,
            "branch requires branchctl arguments",
            "empty branch",
        );

        let empty_branchctl = self.failure(argv!("branchctl"));
        assert_contains(
            &empty_branchctl,
            "branch requires branchctl arguments",
            "empty branchctl",
        );

        let empty_git = self.failure(argv!("git"));
        assert_contains(&empty_git, "git requires arguments", "empty git");

        let partial_branch_auth = self.failure(argv!(
            "git", "branch", "create", "cli-docs", "--user", "jan"
        ));
        assert_contains(
            &partial_branch_auth,
            "--user and --password must be passed together",
            "branch auth validation",
        );

        let zero_agents = self.failure(argv!("agents", "--count", "0"));
        assert_contains(
            &zero_agents,
            "--count must be greater than zero",
            "agents validation",
        );

        let partial_agents_auth = self.failure(argv!("agents", "--user", "jan"));
        assert_contains(
            &partial_agents_auth,
            "--user and --password must be passed together",
            "agents auth validation",
        );

        let conflicting_stop_targets = self.failure(argv!("stop", "--all", "--pid", "123"));
        assert_contains(
            &conflicting_stop_targets,
            "pass either --all or --pid, not both",
            "stop target validation",
        );

        let follow_all_logs = self.failure(argv!("logs", "--file", "all", "--follow"));
        assert_contains(
            &follow_all_logs,
            "--follow requires one log file",
            "logs follow validation",
        );
    }

    fn no_site_diagnostics(&self) {
        step("no-site diagnostics");

        let missing_work_dir = self.temp.path().join("missing-site/.forkpress");

        let storage_status = self.success(argv!(
            "storage",
            "status",
            "--work-dir",
            missing_work_dir.as_os_str()
        ));
        assert_contains(
            &storage_status,
            "site:      not initialized",
            "storage status",
        );
        assert_contains(&storage_status, "mount:     none", "storage status");

        let storage_mount = self.failure(argv!(
            "storage",
            "mount",
            "--work-dir",
            missing_work_dir.as_os_str()
        ));
        assert_contains(
            &storage_mount,
            "storage mount: no ForkPress site found",
            "storage mount",
        );

        let storage_detach = self.success(argv!(
            "storage",
            "detach",
            "--work-dir",
            missing_work_dir.as_os_str()
        ));
        assert_contains(
            &storage_detach,
            "forkpress: no detachable storage found",
            "storage detach",
        );

        let storage_compact = self.failure(argv!(
            "storage",
            "compact",
            "--work-dir",
            missing_work_dir.as_os_str()
        ));
        assert_contains(
            &storage_compact,
            "storage compact: no ForkPress site found",
            "storage compact",
        );

        let branch_list = self.failure(argv!(
            "branch",
            "--work-dir",
            missing_work_dir.as_os_str(),
            "list"
        ));
        assert_contains(
            &branch_list,
            "branch: no ForkPress site found",
            "uninitialized branch list",
        );

        let agents = self.failure(argv!(
            "agents",
            "--work-dir",
            missing_work_dir.as_os_str(),
            "--count",
            "1"
        ));
        assert_contains(&agents, "agents: no ForkPress site found", "agents no site");

        let logs_paths = self.success(argv!(
            "logs",
            "--work-dir",
            missing_work_dir.as_os_str(),
            "--file",
            "all",
            "--paths"
        ));
        for name in ["wp", "php", "server", "forkpress", "gc"] {
            assert_contains(&logs_paths, name, "logs paths");
        }

        let log_tail = self.success(argv!(
            "logs",
            "--work-dir",
            missing_work_dir.as_os_str(),
            "--file",
            "php-errors",
            "-n",
            "3"
        ));
        assert_contains(
            &log_tail,
            "forkpress: log has not been created yet",
            "missing log tail",
        );

        let doctor_storage = self.success(argv!(
            "doctor",
            "storage",
            "--work-dir",
            missing_work_dir.as_os_str()
        ));
        assert_contains(
            &doctor_storage,
            "ForkPress storage capability report",
            "doctor storage",
        );
        assert_contains(&doctor_storage, "recommendation:", "doctor storage");

        let server_list = self.success(argv!("server", "list"));
        assert_contains(
            &server_list,
            "forkpress: no running site servers found",
            "server list",
        );

        let server_stop = self.success(argv!(
            "server",
            "stop",
            "--work-dir",
            missing_work_dir.as_os_str()
        ));
        assert_contains(
            &server_stop,
            "forkpress: no matching running site servers found",
            "server stop no match",
        );

        let top_stop = self.success(argv!("stop", "--work-dir", missing_work_dir.as_os_str()));
        assert_contains(
            &top_stop,
            "forkpress: no matching running site servers found",
            "stop no match",
        );

        let git_version = self.success(argv!("git", "--version"));
        assert_contains(&git_version, "git version", "git passthrough");

        let not_a_checkout = self.temp.path().join("not-a-checkout");
        fs::create_dir_all(&not_a_checkout).expect("failed to create non-checkout dir");
        let pull = self.failure(argv!("pull", not_a_checkout.as_os_str()));
        assert_contains(&pull, "not a git checkout", "pull non-checkout");
    }

    fn init_site(&self) {
        step("init COW site");

        let output = self.success(argv!(
            "init",
            "--work-dir",
            self.work_dir.as_os_str(),
            "--admin-password",
            "admin",
            "--site-title",
            "ForkPress E2E",
            "--root-host",
            "wp.localhost"
        ));
        assert_contains(
            &output,
            "COW materialized strategy initialised",
            "init output",
        );
        assert!(self.work_dir.is_dir(), "work dir missing after init");
        assert!(
            self.branch_root("main").join("wp-load.php").is_file(),
            "main branch was not materialized"
        );

        let manifest = fs::read_to_string(self.work_dir.join("site.toml"))
            .expect("failed to read site manifest");
        assert_contains(&manifest, "strategy = \"cow\"", "site manifest");
        assert!(
            manifest.contains("file_view = \"reflink\"")
                || manifest.contains("file_view = \"file-copy\"")
                || manifest.contains("file_view = \"macos-apfs-sparsebundle\""),
            "site manifest did not record a known file view:\n{manifest}"
        );

        let duplicate = self.failure(argv!(
            "init",
            "--work-dir",
            self.work_dir.as_os_str(),
            "--admin-password",
            "admin"
        ));
        assert_contains(
            &duplicate,
            "a ForkPress site already exists",
            "duplicate init",
        );

        let storage_status = self.success(argv!(
            "storage",
            "status",
            "--work-dir",
            self.work_dir.as_os_str()
        ));
        assert_contains(
            &storage_status,
            "ForkPress storage status",
            "initialized storage status",
        );
        assert_contains(
            &storage_status,
            "strategy:  cow",
            "initialized storage status",
        );

        let doctor = self.success(argv!(
            "doctor",
            "storage",
            "--work-dir",
            self.work_dir.as_os_str()
        ));
        assert_contains(&doctor, "strategy:     cow", "initialized doctor storage");
    }

    fn server_entrypoints(&mut self) {
        step("start/stop/server entrypoints");

        let start_port = unused_port();
        let start = self.success(argv!(
            "start",
            "--work-dir",
            self.work_dir.as_os_str(),
            "--port",
            start_port.to_string(),
            "--root-host",
            "wp.localhost",
            "--workers",
            "1",
            "--background"
        ));
        assert_contains(
            &start,
            "forkpress: server started in background",
            "start --background",
        );
        wait_for_tcp(start_port, Duration::from_secs(30));

        let duplicate_port = unused_port();
        let duplicate = self.failure(argv!(
            "start",
            "--work-dir",
            self.work_dir.as_os_str(),
            "--port",
            duplicate_port.to_string(),
            "--root-host",
            "wp.localhost",
            "--workers",
            "1",
            "--background"
        ));
        assert_contains(&duplicate, "server already running", "duplicate start");

        let list = self.success(argv!("server", "list"));
        assert_contains(&list, &self.work_dir.display().to_string(), "server list");

        let stop = self.success(argv!(
            "stop",
            "--work-dir",
            self.work_dir.as_os_str(),
            "--timeout",
            "20"
        ));
        assert!(
            stop.contains("forkpress: stopped server pid")
                || stop.contains("forkpress: no detachable storage found")
                || stop.contains("forkpress: detached COW storage")
                || stop.contains("forkpress: COW storage is already detached")
                || stop.trim().is_empty(),
            "unexpected stop output:\n{stop}"
        );
        self.assert_server_not_listed();

        let server_port = unused_port();
        let server_start = self.success(argv!(
            "server",
            "start",
            "--work-dir",
            self.work_dir.as_os_str(),
            "--port",
            server_port.to_string(),
            "--root-host",
            "wp.localhost",
            "--workers",
            "1"
        ));
        assert_contains(
            &server_start,
            "forkpress: server started in background",
            "server start",
        );
        wait_for_tcp(server_port, Duration::from_secs(30));

        let list = self.success(argv!("server", "list"));
        let pid = parse_server_pid(&list, &self.work_dir);
        let stop_by_pid = self.success(argv!(
            "server",
            "stop",
            "--pid",
            pid.to_string(),
            "--timeout",
            "20"
        ));
        assert!(
            stop_by_pid.contains("forkpress: stopped server pid")
                || stop_by_pid.contains("forkpress: no detachable storage found")
                || stop_by_pid.contains("forkpress: detached COW storage")
                || stop_by_pid.contains("forkpress: COW storage is already detached")
                || stop_by_pid.trim().is_empty(),
            "unexpected server stop --pid output:\n{stop_by_pid}"
        );
        self.assert_server_not_listed();

        let stop_all = self.success(argv!("server", "stop", "--all", "--timeout", "5"));
        assert_contains(
            &stop_all,
            "forkpress: no matching running site servers found",
            "server stop --all without targets",
        );
    }

    fn start_main_server_with_serve(&mut self) {
        step("serve command keeps site available for command e2e");

        self.port = unused_port();
        let serve = self.success(argv!(
            "serve",
            "--work-dir",
            self.work_dir.as_os_str(),
            "--port",
            self.port.to_string(),
            "--root-host",
            "wp.localhost",
            "--workers",
            "1"
        ));
        assert_contains(
            &serve,
            "forkpress: server started in background",
            "serve default background",
        );
        wait_for_tcp(self.port, Duration::from_secs(30));

        let list = self.success(argv!("server", "list"));
        assert_contains(&list, &self.work_dir.display().to_string(), "server list");

        let main = http_get(self.port, &host_for_branch("main", self.port), "/");
        assert_not_contains(&main.body, "Branch not found", "main HTTP response");
    }

    fn branch_commands(&self) {
        step("branch and branchctl commands");

        let list = self.success(argv!(
            "branch",
            "--work-dir",
            self.work_dir.as_os_str(),
            "list"
        ));
        assert_contains(&list, "main", "branch list");

        let show_main = self.success(argv!(
            "branch",
            "--work-dir",
            self.work_dir.as_os_str(),
            "show",
            "main"
        ));
        assert_contains(&show_main, "forkpress cow branch main", "branch show main");

        let create = self.success(argv!(
            "branch",
            "--work-dir",
            self.work_dir.as_os_str(),
            "create",
            "feature-cli"
        ));
        assert_contains(
            &create,
            "forkpress: COW cloned 'main' -> 'feature-cli'",
            "branch create",
        );
        assert_contains(
            &create,
            &format!("feature-cli.wp.localhost:{}/", self.port),
            "branch create URL hint",
        );
        assert!(
            self.branch_root("feature-cli")
                .join("wp-load.php")
                .is_file(),
            "feature-cli branch root missing"
        );

        let response = http_get(self.port, &host_for_branch("feature-cli", self.port), "/");
        assert_eq!(response.status, 200, "feature-cli HTTP status");
        assert_contains(&response.body, "Branch: feature-cli", "feature-cli HTTP");

        let status = self.success(argv!(
            "branchctl",
            "--work-dir",
            self.work_dir.as_os_str(),
            "status",
            "feature-cli"
        ));
        assert_contains(
            &status,
            "forkpress cow branch feature-cli",
            "branchctl status",
        );

        self.write_branch_file(
            "feature-cli",
            "wp-content/feature-cli.txt",
            "feature only\n",
        );
        assert!(
            !self
                .branch_root("main")
                .join("wp-content/feature-cli.txt")
                .exists(),
            "feature branch write leaked into main"
        );

        let local_branch = self.success(argv!(
            "git",
            "--work-dir",
            self.work_dir.as_os_str(),
            "branch",
            "create",
            "git-local",
            "--from",
            "feature-cli"
        ));
        assert_contains(
            &local_branch,
            "forkpress: branch git-local ready",
            "git branch create local",
        );
        assert!(
            self.branch_root("git-local").join("wp-load.php").is_file(),
            "git-local branch root missing"
        );

        let local_branch_auth = self.failure(argv!(
            "git",
            "--work-dir",
            self.work_dir.as_os_str(),
            "branch",
            "create",
            "git-auth",
            "--user",
            "jan",
            "--password",
            "secret"
        ));
        assert_contains(
            &local_branch_auth,
            "cow local branch creation does not use --user/--password",
            "COW git branch auth rejection",
        );

        let local_branch_unsupported = self.failure(argv!(
            "git",
            "--work-dir",
            self.work_dir.as_os_str(),
            "branch",
            "create",
            "git-unsupported",
            "--bogus"
        ));
        assert_contains(
            &local_branch_unsupported,
            "unsupported argument for `forkpress git branch create`",
            "git branch create unsupported arg",
        );

        let rm = self.success(argv!(
            "branch",
            "--work-dir",
            self.work_dir.as_os_str(),
            "rm",
            "git-local"
        ));
        assert_contains(&rm, "deleted COW branch 'git-local'", "branch rm alias");
        assert!(
            !self.branch_root("git-local").exists(),
            "git-local was not deleted"
        );

        let alias_create = self.success(argv!(
            "branchctl",
            "--work-dir",
            self.work_dir.as_os_str(),
            "create",
            "branchctl-alias",
            "--from",
            "feature-cli"
        ));
        assert_contains(
            &alias_create,
            "forkpress: COW cloned 'feature-cli' -> 'branchctl-alias'",
            "branchctl create",
        );
        let alias_delete = self.success(argv!(
            "branchctl",
            "--work-dir",
            self.work_dir.as_os_str(),
            "delete",
            "branchctl-alias"
        ));
        assert_contains(
            &alias_delete,
            "deleted COW branch 'branchctl-alias'",
            "branchctl delete",
        );

        self.success(argv!(
            "branch",
            "--work-dir",
            self.work_dir.as_os_str(),
            "create",
            "reset-source"
        ));
        self.success(argv!(
            "branch",
            "--work-dir",
            self.work_dir.as_os_str(),
            "create",
            "reset-target"
        ));
        self.write_branch_file("reset-source", "wp-content/reset-source.txt", "source\n");
        self.write_branch_file("reset-target", "wp-content/reset-target.txt", "target\n");

        let reset = self.success(argv!(
            "branch",
            "--work-dir",
            self.work_dir.as_os_str(),
            "reset",
            "reset-target",
            "--from",
            "reset-source"
        ));
        assert_contains(
            &reset,
            "reset COW branch 'reset-target' from 'reset-source'",
            "branch reset",
        );
        assert!(
            self.branch_root("reset-target")
                .join("wp-content/reset-source.txt")
                .is_file(),
            "reset target did not receive source file"
        );
        assert!(
            !self
                .branch_root("reset-target")
                .join("wp-content/reset-target.txt")
                .exists(),
            "reset target kept stale target file"
        );

        let rollback = self.success(argv!(
            "branch",
            "--work-dir",
            self.work_dir.as_os_str(),
            "rollback",
            "reset-target",
            "--from",
            "main"
        ));
        assert_contains(
            &rollback,
            "reset COW branch 'reset-target' from 'main'",
            "branch rollback alias",
        );
        assert!(
            !self
                .branch_root("reset-target")
                .join("wp-content/reset-source.txt")
                .exists(),
            "rollback target kept reset source file"
        );

        let reset_main = self.failure(argv!(
            "branch",
            "--work-dir",
            self.work_dir.as_os_str(),
            "reset",
            "main",
            "--from",
            "feature-cli"
        ));
        assert_contains(
            &reset_main,
            "refusing to reset main without --force",
            "reset main rejection",
        );

        let reset_self = self.failure(argv!(
            "branch",
            "--work-dir",
            self.work_dir.as_os_str(),
            "reset",
            "feature-cli",
            "--from",
            "feature-cli"
        ));
        assert_contains(
            &reset_self,
            "cannot reset a branch from itself",
            "reset from itself",
        );

        for branch in ["reset-source", "reset-target"] {
            self.success(argv!(
                "branch",
                "--work-dir",
                self.work_dir.as_os_str(),
                "delete",
                branch
            ));
        }

        let delete_main = self.failure(argv!(
            "branchctl",
            "--work-dir",
            self.work_dir.as_os_str(),
            "delete",
            "main"
        ));
        assert_contains(
            &delete_main,
            "cannot delete the main branch",
            "delete main rejection",
        );

        let missing_create_name = self.failure(argv!(
            "branch",
            "--work-dir",
            self.work_dir.as_os_str(),
            "create"
        ));
        assert_contains(
            &missing_create_name,
            "branch create requires a branch name",
            "branch create missing name",
        );

        let invalid_branch = self.failure(argv!(
            "branch",
            "--work-dir",
            self.work_dir.as_os_str(),
            "create",
            "bad/slash"
        ));
        assert_contains(
            &invalid_branch,
            "invalid branch name",
            "invalid branch name",
        );

        let missing_reset_from = self.failure(argv!(
            "branch",
            "--work-dir",
            self.work_dir.as_os_str(),
            "reset",
            "feature-cli"
        ));
        assert_contains(
            &missing_reset_from,
            "branch reset requires --from <branch>",
            "branch reset missing --from",
        );

        let unknown_branch_command = self.failure(argv!(
            "branch",
            "--work-dir",
            self.work_dir.as_os_str(),
            "publish",
            "feature-cli"
        ));
        assert_contains(
            &unknown_branch_command,
            "cow branch subcommand is not implemented yet",
            "unknown branch command",
        );
    }

    fn git_checkout_commands(&self) {
        step("clone, pull, push, commit, and git passthrough commands");

        let remote = format!("http://127.0.0.1:{}/site.git", self.port);
        let checkout = self.temp.path().join("checkout");
        let clone = self.success(argv!("clone", &remote, checkout.as_os_str()));
        assert!(
            clone.contains("Cloning into") || clone.trim().is_empty(),
            "unexpected clone output:\n{clone}"
        );
        assert!(
            checkout.join("wordpress/wp-load.php").is_file(),
            "clone did not materialize wordpress/wp-load.php"
        );
        assert!(
            checkout.join("database.sql").is_file(),
            "clone did not include database.sql"
        );
        assert!(
            !checkout
                .join("wordpress/wp-content/database/.ht.sqlite")
                .exists(),
            "clone leaked private SQLite database"
        );

        let remote_clone = self.temp.path().join("remote-name-checkout");
        self.success(argv!(
            "clone",
            "--remote-name",
            "forkpress",
            &remote,
            remote_clone.as_os_str()
        ));
        let remotes = git_output(&remote_clone, ["remote"]);
        assert_contains(&remotes, "forkpress", "clone --remote-name");

        self.success(argv!("pull", checkout.as_os_str()));

        git(
            &checkout,
            ["fetch", "origin", "+refs/heads/*:refs/remotes/origin/*"],
        );
        git(
            &checkout,
            ["checkout", "-B", "feature-cli", "origin/feature-cli"],
        );
        fs::write(
            checkout.join("wordpress/wp-content/pushed-from-cli.txt"),
            "pushed through forkpress push\n",
        )
        .expect("failed to write push fixture");

        let push = self.success(argv!(
            "push",
            checkout.as_os_str(),
            "--message",
            "test push command"
        ));
        assert_contains(
            &push,
            "feature-cli is now previewable over HTTP",
            "push output",
        );
        assert_file_contains(
            &self
                .branch_root("feature-cli")
                .join("wp-content/pushed-from-cli.txt"),
            "pushed through forkpress push",
        );
        assert!(
            !self
                .branch_root("main")
                .join("wp-content/pushed-from-cli.txt")
                .exists(),
            "push leaked feature file into main"
        );
        assert_clean_git(&checkout);

        git(
            &checkout,
            ["checkout", "-B", "commit-created", "origin/main"],
        );
        fs::write(
            checkout.join("wordpress/wp-content/commit-created.txt"),
            "created through forkpress commit\n",
        )
        .expect("failed to write commit fixture");
        let commit = self.success(argv!(
            "commit",
            checkout.as_os_str(),
            "-m",
            "create branch through commit alias"
        ));
        assert_contains(
            &commit,
            "commit-created is now previewable over HTTP",
            "commit alias output",
        );
        assert_file_contains(
            &self
                .branch_root("commit-created")
                .join("wp-content/commit-created.txt"),
            "created through forkpress commit",
        );
        assert_clean_git(&checkout);

        git(
            &checkout,
            ["checkout", "-B", "default-message", "origin/main"],
        );
        fs::write(
            checkout.join("wordpress/wp-content/default-message.txt"),
            "created with default message\n",
        )
        .expect("failed to write default-message fixture");
        let default_push = self.success(argv!("push", checkout.as_os_str()));
        assert_contains(
            &default_push,
            "default-message is now previewable over HTTP",
            "push default message output",
        );
        assert_file_contains(
            &self
                .branch_root("default-message")
                .join("wp-content/default-message.txt"),
            "created with default message",
        );
        assert_clean_git(&checkout);

        let missing_remote = self.failure(argv!(
            "push",
            checkout.as_os_str(),
            "--remote-name",
            "missing"
        ));
        assert_contains(
            &missing_remote,
            "git exited with status",
            "push missing remote",
        );

        let git_status = self.success_in(&checkout, argv!("git", "status", "--short"));
        assert_eq!(
            git_status.trim(),
            "",
            "git passthrough status was not clean"
        );
    }

    fn agent_worktrees(&self) {
        step("agents command");

        let remote = format!("http://127.0.0.1:{}/site.git", self.port);
        let agents_dir = self.temp.path().join("agents");
        let output = self.success(argv!(
            "agents",
            "--work-dir",
            self.work_dir.as_os_str(),
            "--remote",
            &remote,
            "--count",
            "2",
            "--prefix",
            "cowagent",
            agents_dir.as_os_str()
        ));
        assert_contains(&output, "2 agent worktrees ready", "agents command output");
        for branch in ["cowagent-1", "cowagent-2"] {
            assert!(
                self.branch_root(branch).join("wp-load.php").is_file(),
                "{branch} branch was not created"
            );
            assert!(
                agents_dir
                    .join(branch)
                    .join("wordpress/wp-load.php")
                    .is_file(),
                "{branch} worktree was not created"
            );
        }

        let reuse = self.success(argv!(
            "agents",
            "--work-dir",
            self.work_dir.as_os_str(),
            "--remote",
            &remote,
            "--count",
            "2",
            "--prefix",
            "cowagent",
            agents_dir.as_os_str()
        ));
        assert_contains(
            &reuse,
            "reusing existing branch cowagent-1",
            "agents branch reuse",
        );
        assert_contains(&reuse, "reusing existing worktree", "agents worktree reuse");
    }

    fn log_commands(&self) {
        step("logs command");

        let paths = self.success(argv!(
            "logs",
            "--work-dir",
            self.work_dir.as_os_str(),
            "--file",
            "all",
            "--paths"
        ));
        for name in ["wp", "php", "server", "forkpress", "gc"] {
            assert_contains(&paths, name, "logs --paths");
        }

        for selection in ["wp", "php", "php-errors", "server", "forkpress", "gc"] {
            let output = self.success(argv!(
                "logs",
                "--work-dir",
                self.work_dir.as_os_str(),
                "--file",
                selection,
                "-n",
                "5"
            ));
            assert!(
                output.contains("forkpress: log has not been created yet")
                    || !output.trim().is_empty()
                    || selection == "server",
                "unexpected empty log output for {selection}"
            );
        }

        let all = self.success(argv!(
            "logs",
            "--work-dir",
            self.work_dir.as_os_str(),
            "--file",
            "all",
            "-n",
            "5"
        ));
        for header in [
            "==> wp:",
            "==> php:",
            "==> server:",
            "==> forkpress:",
            "==> gc:",
        ] {
            assert_contains(&all, header, "logs --file all");
        }
    }

    fn storage_commands(&self) {
        step("storage status and mount commands");

        let status = self.success(argv!(
            "storage",
            "status",
            "--work-dir",
            self.work_dir.as_os_str()
        ));
        for expected in [
            "ForkPress storage status",
            "public:",
            "storage:",
            "lock:",
            "leftovers:",
        ] {
            assert_contains(&status, expected, "storage status");
        }

        let mount = self.success(argv!(
            "storage",
            "mount",
            "--work-dir",
            self.work_dir.as_os_str()
        ));
        assert!(
            mount.contains("COW storage mounted")
                || mount.contains("does not use a detachable mount"),
            "unexpected storage mount output:\n{mount}"
        );
    }

    fn stop_main_server(&self) {
        step("stop command");

        self.success(argv!(
            "stop",
            "--work-dir",
            self.work_dir.as_os_str(),
            "--timeout",
            "20"
        ));
        self.assert_server_not_listed();
    }

    fn foreground_start_exits_on_sigint(&mut self) {
        step("foreground start handles SIGINT");

        let port = unused_port();
        let mut command = StdCommand::new(&self.bin);
        command
            .arg("start")
            .arg("--work-dir")
            .arg(&self.work_dir)
            .arg("--port")
            .arg(port.to_string())
            .arg("--root-host")
            .arg("wp.localhost")
            .arg("--workers")
            .arg("1")
            .env("FORKPRESS_STATE_DIR", &self.state_dir)
            .env("GIT_TERMINAL_PROMPT", "0")
            .env("NO_COLOR", "1")
            .current_dir(self.temp.path())
            .stdin(Stdio::null())
            .stdout(Stdio::piped())
            .stderr(Stdio::piped());

        let mut child = command.spawn().expect("failed to spawn foreground start");
        let child_id = child.id();
        wait_for_tcp_or_exit(port, Duration::from_secs(45), &mut child);
        wait_for_registered_server_or_exit(
            &self.bin,
            &self.state_dir,
            self.temp.path(),
            &self.work_dir,
            Duration::from_secs(30),
            &mut child,
        );
        thread::sleep(Duration::from_secs(1));

        #[cfg(unix)]
        unsafe {
            libc::kill(child_id as i32, libc::SIGINT);
        }

        #[cfg(not(unix))]
        child.kill().expect("failed to stop foreground start");

        let status = wait_for_child(&mut child, Duration::from_secs(30));
        if status.is_none() {
            child.kill().expect("failed to kill stuck foreground start");
            panic!("foreground start did not exit after SIGINT");
        }

        let output = child
            .wait_with_output()
            .expect("failed to collect start output");
        let combined = combined(&output);
        assert!(
            output.status.success(),
            "foreground start exited unsuccessfully after SIGINT:\n{combined}"
        );
        assert_contains(&combined, "Main site:", "foreground start output");
        assert_contains(&combined, "Stopping servers...", "foreground start SIGINT");
        self.assert_server_not_listed();
    }

    fn post_stop_storage_commands(&self) {
        step("post-stop storage detach and compact commands");

        let detach = self.success(argv!(
            "storage",
            "detach",
            "--work-dir",
            self.work_dir.as_os_str(),
            "--timeout",
            "20"
        ));
        assert!(
            detach.contains("no detachable storage found")
                || detach.contains("detached COW storage")
                || detach.contains("COW storage is already detached"),
            "unexpected storage detach output:\n{detach}"
        );

        let compact = self.success(argv!(
            "storage",
            "compact",
            "--work-dir",
            self.work_dir.as_os_str(),
            "--timeout",
            "20"
        ));
        assert!(
            compact.contains("storage file view does not use a compactable sparsebundle")
                || compact.contains("compacted COW sparsebundle")
                || compact.contains("no APFS sparsebundle found"),
            "unexpected storage compact output:\n{compact}"
        );

        let status = self.success(argv!(
            "storage",
            "status",
            "--work-dir",
            self.work_dir.as_os_str()
        ));
        assert_contains(&status, "ForkPress storage status", "final storage status");
    }

    fn readme_command_docs(&self) {
        step("README production command docs");

        let readme =
            fs::read_to_string(self.repo_root.join("README.md")).expect("failed to read README.md");
        let commands = markdown_section(&readme, "Commands")
            .unwrap_or_else(|| panic!("README.md is missing a Commands section"));

        for command in PRODUCTION_COMMANDS {
            assert_contains(
                commands,
                &format!("`forkpress {command}"),
                "README Commands section",
            );
        }

        for command in DEV_ONLY_COMMANDS {
            assert_not_contains(
                commands,
                &format!("`forkpress {command}"),
                "README production Commands section",
            );
        }
    }

    fn success<I, S>(&self, args: I) -> String
    where
        I: IntoIterator<Item = S>,
        S: AsRef<OsStr>,
    {
        self.success_in(self.temp.path(), args)
    }

    fn success_in<I, S>(&self, cwd: &Path, args: I) -> String
    where
        I: IntoIterator<Item = S>,
        S: AsRef<OsStr>,
    {
        let mut command = self.command(cwd);
        let args = args
            .into_iter()
            .map(|arg| arg.as_ref().to_os_string())
            .collect::<Vec<_>>();
        command.args(&args);
        let assert = command.assert().success();
        combined(assert.get_output())
    }

    fn failure<I, S>(&self, args: I) -> String
    where
        I: IntoIterator<Item = S>,
        S: AsRef<OsStr>,
    {
        let mut command = self.command(self.temp.path());
        let args = args
            .into_iter()
            .map(|arg| arg.as_ref().to_os_string())
            .collect::<Vec<_>>();
        command.args(&args);
        let assert = command.assert().failure();
        combined(assert.get_output())
    }

    fn command(&self, cwd: &Path) -> Command {
        let mut command = Command::new(&self.bin);
        command
            .env("FORKPRESS_STATE_DIR", &self.state_dir)
            .env("GIT_TERMINAL_PROMPT", "0")
            .env("NO_COLOR", "1")
            .current_dir(cwd);
        command
    }

    fn branch_root(&self, branch: &str) -> PathBuf {
        self.work_root.join(branch)
    }

    fn write_branch_file(&self, branch: &str, rel: &str, contents: &str) {
        let path = self.branch_root(branch).join(rel);
        fs::create_dir_all(path.parent().expect("branch file has no parent"))
            .expect("failed to create branch fixture parent");
        fs::write(&path, contents).unwrap_or_else(|err| {
            panic!("failed to write {}: {err}", path.display());
        });
    }

    fn assert_server_not_listed(&self) {
        let list = self.success(argv!("server", "list"));
        assert!(
            !list.contains(&self.work_dir.display().to_string()),
            "server still listed for {}:\n{list}",
            self.work_dir.display()
        );
    }
}

impl Drop for Harness {
    fn drop(&mut self) {
        let _ = StdCommand::new(&self.bin)
            .arg("stop")
            .arg("--all")
            .arg("--timeout")
            .arg("5")
            .arg("--force")
            .env("FORKPRESS_STATE_DIR", &self.state_dir)
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .status();
    }
}

struct HttpResponse {
    status: u16,
    body: String,
}

fn step(name: &str) {
    println!("==> {name}");
}

fn repo_root() -> PathBuf {
    let mut dir = PathBuf::from(env!("CARGO_MANIFEST_DIR"));
    loop {
        if dir.join("Cargo.toml").is_file()
            && dir.join("README.md").is_file()
            && dir.join("crates/forkpress-cli").is_dir()
        {
            return dir;
        }
        assert!(
            dir.pop(),
            "failed to locate repository root from {}",
            env!("CARGO_MANIFEST_DIR")
        );
    }
}

fn unused_port() -> u16 {
    TcpListener::bind(("127.0.0.1", 0))
        .expect("failed to bind ephemeral port")
        .local_addr()
        .expect("failed to read ephemeral port")
        .port()
}

fn wait_for_tcp(port: u16, timeout: Duration) {
    let deadline = Instant::now() + timeout;
    while Instant::now() < deadline {
        if TcpStream::connect(("127.0.0.1", port)).is_ok() {
            return;
        }
        thread::sleep(Duration::from_millis(100));
    }
    panic!("timed out waiting for TCP port {port}");
}

fn wait_for_tcp_or_exit(port: u16, timeout: Duration, child: &mut std::process::Child) {
    let deadline = Instant::now() + timeout;
    while Instant::now() < deadline {
        if TcpStream::connect(("127.0.0.1", port)).is_ok() {
            return;
        }
        if let Some(status) = child.try_wait().expect("failed to poll foreground start") {
            panic!("foreground start exited before TCP readiness: {status}");
        }
        thread::sleep(Duration::from_millis(100));
    }
    panic!("timed out waiting for foreground TCP port {port}");
}

fn wait_for_registered_server_or_exit(
    bin: &Path,
    state_dir: &Path,
    cwd: &Path,
    work_dir: &Path,
    timeout: Duration,
    child: &mut std::process::Child,
) {
    let deadline = Instant::now() + timeout;
    while Instant::now() < deadline {
        let output = StdCommand::new(bin)
            .arg("server")
            .arg("list")
            .env("FORKPRESS_STATE_DIR", state_dir)
            .env("GIT_TERMINAL_PROMPT", "0")
            .env("NO_COLOR", "1")
            .current_dir(cwd)
            .output()
            .expect("failed to run server list while waiting for foreground start");
        assert!(
            output.status.success(),
            "server list failed while waiting for foreground start:\n{}",
            combined(&output)
        );
        if combined(&output).contains(&work_dir.display().to_string()) {
            return;
        }
        if let Some(status) = child.try_wait().expect("failed to poll foreground start") {
            panic!("foreground start exited before server registration: {status}");
        }
        thread::sleep(Duration::from_millis(100));
    }
    panic!(
        "timed out waiting for foreground server registration for {}",
        work_dir.display()
    );
}

fn wait_for_child(
    child: &mut std::process::Child,
    timeout: Duration,
) -> Option<std::process::ExitStatus> {
    let deadline = Instant::now() + timeout;
    while Instant::now() < deadline {
        if let Some(status) = child.try_wait().expect("failed to poll child") {
            return Some(status);
        }
        thread::sleep(Duration::from_millis(100));
    }
    None
}

fn http_get(port: u16, host: &str, path: &str) -> HttpResponse {
    let mut stream = TcpStream::connect(("127.0.0.1", port))
        .unwrap_or_else(|err| panic!("failed to connect to HTTP server on {port}: {err}"));
    let request = format!(
        "GET {path} HTTP/1.1\r\nHost: {host}\r\nConnection: close\r\nUser-Agent: forkpress-e2e\r\n\r\n"
    );
    stream
        .write_all(request.as_bytes())
        .expect("failed to write HTTP request");
    let mut bytes = Vec::new();
    stream
        .read_to_end(&mut bytes)
        .expect("failed to read HTTP response");
    parse_http_response(&bytes)
}

fn parse_http_response(bytes: &[u8]) -> HttpResponse {
    let raw = String::from_utf8_lossy(bytes);
    let (headers, body) = raw
        .split_once("\r\n\r\n")
        .unwrap_or_else(|| panic!("invalid HTTP response:\n{raw}"));
    let status = headers
        .lines()
        .next()
        .and_then(|line| line.split_whitespace().nth(1))
        .and_then(|code| code.parse::<u16>().ok())
        .unwrap_or_else(|| panic!("invalid HTTP status line:\n{headers}"));
    HttpResponse {
        status,
        body: body.to_string(),
    }
}

fn host_for_branch(branch: &str, port: u16) -> String {
    if branch == "main" {
        format!("wp.localhost:{port}")
    } else {
        format!("{branch}.wp.localhost:{port}")
    }
}

fn git<I, S>(cwd: &Path, args: I)
where
    I: IntoIterator<Item = S>,
    S: AsRef<OsStr>,
{
    let output = git_command(cwd, args);
    assert!(
        output.status.success(),
        "git command failed in {}\n{}",
        cwd.display(),
        combined(&output)
    );
}

fn git_output<I, S>(cwd: &Path, args: I) -> String
where
    I: IntoIterator<Item = S>,
    S: AsRef<OsStr>,
{
    let output = git_command(cwd, args);
    assert!(
        output.status.success(),
        "git command failed in {}\n{}",
        cwd.display(),
        combined(&output)
    );
    combined(&output)
}

fn git_command<I, S>(cwd: &Path, args: I) -> Output
where
    I: IntoIterator<Item = S>,
    S: AsRef<OsStr>,
{
    let args = args
        .into_iter()
        .map(|arg| arg.as_ref().to_os_string())
        .collect::<Vec<_>>();
    StdCommand::new("git")
        .args(&args)
        .current_dir(cwd)
        .env("GIT_TERMINAL_PROMPT", "0")
        .output()
        .unwrap_or_else(|err| panic!("failed to run git in {}: {err}", cwd.display()))
}

fn assert_clean_git(repo: &Path) {
    let status = git_output(repo, ["status", "--porcelain"]);
    assert_eq!(
        status.trim(),
        "",
        "checkout is dirty after ForkPress command:\n{status}"
    );
}

fn parse_server_pid(list: &str, work_dir: &Path) -> u32 {
    let work_dir = work_dir.display().to_string();
    for line in list.lines().skip(1) {
        if line.contains(&work_dir) {
            return line
                .split('\t')
                .next()
                .and_then(|pid| pid.parse::<u32>().ok())
                .unwrap_or_else(|| panic!("failed to parse server PID from line: {line}"));
        }
    }
    panic!("server list did not include {work_dir}:\n{list}");
}

fn top_commands(help: &str) -> Vec<&str> {
    let mut commands = Vec::new();
    let mut in_commands = false;
    for line in help.lines() {
        if line == "Commands:" {
            in_commands = true;
            continue;
        }
        if line == "Options:" {
            break;
        }
        if !in_commands {
            continue;
        }
        let Some(command) = line.split_whitespace().next() else {
            continue;
        };
        if command != "help" {
            commands.push(command);
        }
    }
    commands.sort_unstable();
    commands
}

fn markdown_section<'a>(contents: &'a str, heading: &str) -> Option<&'a str> {
    let marker = format!("## {heading}");
    let start = contents.find(&marker)?;
    let after_start = start + marker.len();
    let rest = contents.get(after_start..)?;
    let end = rest.find("\n## ").unwrap_or(rest.len());
    rest.get(..end)
}

fn assert_file_contains(path: &Path, needle: &str) {
    let contents = fs::read_to_string(path)
        .unwrap_or_else(|err| panic!("failed to read {}: {err}", path.display()));
    assert_contains(&contents, needle, &format!("file {}", path.display()));
}

fn assert_contains(haystack: &str, needle: &str, context: &str) {
    assert!(
        haystack.contains(needle),
        "{context} did not contain {needle:?}\n{haystack}"
    );
}

fn assert_not_contains(haystack: &str, needle: &str, context: &str) {
    assert!(
        !haystack.contains(needle),
        "{context} unexpectedly contained {needle:?}\n{haystack}"
    );
}

fn combined(output: &Output) -> String {
    let mut combined = String::new();
    combined.push_str(&String::from_utf8_lossy(&output.stdout));
    combined.push_str(&String::from_utf8_lossy(&output.stderr));
    combined
}

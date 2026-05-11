use std::ffi::OsString;
use std::fs;

use crate::harness::Harness;
use crate::support::{assert_contains, assert_not_contains, markdown_section, step, top_commands};
use crate::{DEV_ONLY_COMMANDS, PRODUCTION_COMMANDS};

impl Harness {
    pub(crate) fn command_surface(&self) {
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

    pub(crate) fn parser_and_early_validation_failures(&self) {
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

    pub(crate) fn readme_command_docs(&self) {
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
}

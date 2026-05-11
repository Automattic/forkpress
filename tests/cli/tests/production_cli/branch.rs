use crate::harness::Harness;
use crate::support::{assert_contains, host_for_branch, http_get, step};

impl Harness {
    pub(crate) fn branch_commands(&self) {
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
}

use std::fs;

use crate::harness::Harness;
use crate::support::{
    assert_clean_git, assert_contains, assert_file_contains, git, git_output, step,
};

impl Harness {
    pub(crate) fn git_checkout_commands(&self) {
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

    pub(crate) fn agent_worktrees(&self) {
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
}

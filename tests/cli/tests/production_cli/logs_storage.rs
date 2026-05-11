use crate::harness::Harness;
use crate::support::{assert_contains, step};

impl Harness {
    pub(crate) fn log_commands(&self) {
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

    pub(crate) fn storage_commands(&self) {
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
}

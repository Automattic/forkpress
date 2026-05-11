use std::fs;
use std::process::{Command as StdCommand, Stdio};
use std::thread;
use std::time::Duration;

use crate::harness::Harness;
use crate::support::{
    assert_contains, assert_not_contains, combined, host_for_branch, http_get, parse_server_pid,
    step, unused_port, wait_for_child, wait_for_registered_server_or_exit, wait_for_tcp,
    wait_for_tcp_or_exit,
};

impl Harness {
    pub(crate) fn no_site_diagnostics(&self) {
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

    pub(crate) fn init_site(&self) {
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

    pub(crate) fn server_entrypoints(&mut self) {
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

    pub(crate) fn start_main_server_with_serve(&mut self) {
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

    pub(crate) fn stop_main_server(&self) {
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

    pub(crate) fn foreground_start_exits_on_sigint(&mut self) {
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

    pub(crate) fn post_stop_storage_commands(&self) {
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
}

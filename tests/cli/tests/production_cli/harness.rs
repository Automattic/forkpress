use assert_cmd::Command;
use std::env;
use std::ffi::OsStr;
use std::fs;
use std::path::{Path, PathBuf};
use std::process::{Command as StdCommand, Stdio};
use tempfile::TempDir;

use crate::support::{combined, repo_root, unused_port};

pub(crate) struct Harness {
    pub(crate) bin: PathBuf,
    pub(crate) repo_root: PathBuf,
    pub(crate) temp: TempDir,
    pub(crate) state_dir: PathBuf,
    pub(crate) work_root: PathBuf,
    pub(crate) work_dir: PathBuf,
    pub(crate) port: u16,
}

impl Harness {
    pub(crate) fn new() -> Self {
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

    pub(crate) fn success<I, S>(&self, args: I) -> String
    where
        I: IntoIterator<Item = S>,
        S: AsRef<OsStr>,
    {
        self.success_in(self.temp.path(), args)
    }

    pub(crate) fn success_in<I, S>(&self, cwd: &Path, args: I) -> String
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

    pub(crate) fn failure<I, S>(&self, args: I) -> String
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

    pub(crate) fn branch_root(&self, branch: &str) -> PathBuf {
        self.work_root.join(branch)
    }

    pub(crate) fn write_branch_file(&self, branch: &str, rel: &str, contents: &str) {
        let path = self.branch_root(branch).join(rel);
        fs::create_dir_all(path.parent().expect("branch file has no parent"))
            .expect("failed to create branch fixture parent");
        fs::write(&path, contents).unwrap_or_else(|err| {
            panic!("failed to write {}: {err}", path.display());
        });
    }

    pub(crate) fn assert_server_not_listed(&self) {
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

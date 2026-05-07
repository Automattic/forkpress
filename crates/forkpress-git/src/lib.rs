use anyhow::{Context, Result, bail};
use std::ffi::{OsStr, OsString};
use std::path::Path;
use std::process::{Command, Stdio};

pub fn default_commit_message(branch: &str) -> String {
    format!("forkpress: update {branch}")
}

pub fn default_git_remote() -> String {
    "http://wp.localhost:18080/site.git".to_string()
}

pub fn ensure_git_available() -> Result<()> {
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

pub fn ensure_git_repository(repo: &Path) -> Result<()> {
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

pub fn run_git<I, S>(cwd: Option<&Path>, args: I) -> Result<()>
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

pub fn git_stdout<I, S>(cwd: &Path, args: I) -> Result<String>
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

pub fn git_ref_exists(repo: &Path, reference: &str) -> Result<bool> {
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

pub fn git_is_ancestor(repo: &Path, ancestor: &str, descendant: &str) -> Result<bool> {
    let status = Command::new("git")
        .arg("merge-base")
        .arg("--is-ancestor")
        .arg(ancestor)
        .arg(descendant)
        .current_dir(repo)
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .with_context(|| format!("failed to compare git refs {ancestor} and {descendant}"))?;
    match status.code() {
        Some(0) => Ok(true),
        Some(1) => Ok(false),
        _ => bail!("git merge-base exited with status {status}"),
    }
}

pub fn add_agent_worktree(repo: &Path, remote_name: &str, branch: &str, path: &Path) -> Result<()> {
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

pub fn sync_pushed_branch(repo: &Path, remote_name: &str, branch: &str) -> Result<()> {
    let remote_ref = format!("refs/remotes/{remote_name}/{branch}");
    let upstream = format!("{remote_name}/{branch}");
    run_git(
        Some(repo),
        [
            OsString::from("fetch"),
            OsString::from("--prune"),
            OsString::from(remote_name),
            OsString::from(format!("+refs/heads/{branch}:{remote_ref}")),
        ],
    )?;
    set_pushed_branch_upstream(repo, branch, &upstream)?;

    let head = git_stdout(repo, ["rev-parse", "HEAD"])?;
    let remote_head = git_stdout(repo, ["rev-parse", remote_ref.as_str()])?;
    if head == remote_head {
        return Ok(());
    }

    if git_is_ancestor(repo, "HEAD", &remote_ref)? {
        ensure_clean_before_server_normalized_reset(repo)?;
        run_git(
            Some(repo),
            [
                OsString::from("reset"),
                OsString::from("--hard"),
                OsString::from(&remote_ref),
            ],
        )?;
        println!("forkpress: fast-forwarded {branch} to the server-normalized Git ref");
    } else {
        eprintln!(
            "forkpress: pushed {branch}, but the server-normalized Git ref is not a fast-forward; run forkpress pull in {}",
            repo.display()
        );
    }

    Ok(())
}

pub fn ensure_git_identity(repo: &Path) -> Result<()> {
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

fn set_pushed_branch_upstream(repo: &Path, branch: &str, upstream: &str) -> Result<()> {
    run_git(
        Some(repo),
        [
            OsString::from("branch"),
            OsString::from(format!("--set-upstream-to={upstream}")),
            OsString::from(branch),
        ],
    )
    .with_context(|| format!("failed to set upstream for {branch} to {upstream}"))
}

fn ensure_clean_before_server_normalized_reset(repo: &Path) -> Result<()> {
    let status = git_stdout(repo, ["status", "--porcelain"])?;
    if status.trim().is_empty() {
        return Ok(());
    }
    bail!(
        "server-normalized Git ref is a fast-forward, but the checkout changed before reset; refusing to overwrite local changes"
    );
}

fn git_config_is_set(repo: &Path, key: &str) -> Result<bool> {
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

#[cfg(test)]
mod tests {
    use super::*;
    use std::fs;
    use std::path::PathBuf;
    use std::time::{SystemTime, UNIX_EPOCH};

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
    fn git_ref_and_ancestor_helpers_read_local_history() -> Result<()> {
        let repo = TestDir::new("refs")?;
        init_repo(&repo.path)?;
        commit_file(&repo.path, "one.txt", "one", "one")?;
        let first = git_stdout(&repo.path, ["rev-parse", "HEAD"])?;
        commit_file(&repo.path, "two.txt", "two", "two")?;

        assert!(git_ref_exists(&repo.path, "refs/heads/main")?);
        assert!(!git_ref_exists(&repo.path, "refs/heads/missing")?);
        assert!(git_is_ancestor(&repo.path, &first, "HEAD")?);
        assert!(!git_is_ancestor(&repo.path, "HEAD", &first)?);
        Ok(())
    }

    #[test]
    fn sync_pushed_branch_fast_forwards_to_server_normalized_ref() -> Result<()> {
        let root = TestDir::new("sync")?;
        let origin = root.path.join("origin.git");
        let publisher = root.path.join("publisher");
        let checkout = root.path.join("checkout");

        run_git(
            Some(&root.path),
            [
                OsString::from("init"),
                OsString::from("--bare"),
                origin.as_os_str().to_owned(),
            ],
        )?;
        init_repo(&publisher)?;
        commit_file(&publisher, "site.txt", "initial", "initial")?;
        add_remote(&publisher, "origin", &origin)?;
        run_git(
            Some(&publisher),
            [
                OsString::from("push"),
                OsString::from("-u"),
                OsString::from("origin"),
                OsString::from("HEAD:refs/heads/main"),
            ],
        )?;

        run_git(
            Some(&root.path),
            [
                OsString::from("clone"),
                OsString::from("--branch"),
                OsString::from("main"),
                origin.as_os_str().to_owned(),
                checkout.as_os_str().to_owned(),
            ],
        )?;
        ensure_git_identity(&checkout)?;

        commit_file(&publisher, "site.txt", "normalized", "normalized")?;
        run_git(
            Some(&publisher),
            [
                OsString::from("push"),
                OsString::from("origin"),
                OsString::from("HEAD:refs/heads/main"),
            ],
        )?;
        let normalized = git_stdout(&publisher, ["rev-parse", "HEAD"])?;

        sync_pushed_branch(&checkout, "origin", "main")?;

        assert_eq!(git_stdout(&checkout, ["rev-parse", "HEAD"])?, normalized);
        assert_eq!(
            git_stdout(
                &checkout,
                ["rev-parse", "--abbrev-ref", "--symbolic-full-name", "@{u}"]
            )?,
            "origin/main"
        );
        Ok(())
    }

    struct TestDir {
        path: PathBuf,
    }

    impl TestDir {
        fn new(name: &str) -> Result<Self> {
            let nonce = SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .context("system clock is before UNIX epoch")?
                .as_nanos();
            let path = std::env::temp_dir().join(format!(
                "forkpress-git-test-{}-{nonce}-{name}",
                std::process::id()
            ));
            fs::create_dir_all(&path)
                .with_context(|| format!("failed to create {}", path.display()))?;
            Ok(Self { path })
        }
    }

    impl Drop for TestDir {
        fn drop(&mut self) {
            let _ = fs::remove_dir_all(&self.path);
        }
    }

    fn init_repo(repo: &Path) -> Result<()> {
        fs::create_dir_all(repo).with_context(|| format!("failed to create {}", repo.display()))?;
        run_git(Some(repo), ["init"])?;
        run_git(Some(repo), ["symbolic-ref", "HEAD", "refs/heads/main"])?;
        ensure_git_identity(repo)
    }

    fn commit_file(repo: &Path, path: &str, contents: &str, message: &str) -> Result<()> {
        fs::write(repo.join(path), contents)
            .with_context(|| format!("failed to write {}", repo.join(path).display()))?;
        run_git(Some(repo), ["add", path])?;
        run_git(Some(repo), ["commit", "-m", message])
    }

    fn add_remote(repo: &Path, remote_name: &str, remote: &Path) -> Result<()> {
        run_git(
            Some(repo),
            [
                OsString::from("remote"),
                OsString::from("add"),
                OsString::from(remote_name),
                remote.as_os_str().to_owned(),
            ],
        )
    }
}

use std::ffi::OsStr;
use std::fs;
use std::io::{Read, Write};
use std::net::{TcpListener, TcpStream};
use std::path::{Path, PathBuf};
use std::process::{Command as StdCommand, Output};
use std::thread;
use std::time::{Duration, Instant};

pub(crate) struct HttpResponse {
    pub(crate) status: u16,
    pub(crate) body: String,
}

pub(crate) fn step(name: &str) {
    println!("==> {name}");
}

pub(crate) fn repo_root() -> PathBuf {
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

pub(crate) fn unused_port() -> u16 {
    TcpListener::bind(("127.0.0.1", 0))
        .expect("failed to bind ephemeral port")
        .local_addr()
        .expect("failed to read ephemeral port")
        .port()
}

pub(crate) fn wait_for_tcp(port: u16, timeout: Duration) {
    let deadline = Instant::now() + timeout;
    while Instant::now() < deadline {
        if TcpStream::connect(("127.0.0.1", port)).is_ok() {
            return;
        }
        thread::sleep(Duration::from_millis(100));
    }
    panic!("timed out waiting for TCP port {port}");
}

pub(crate) fn wait_for_tcp_or_exit(port: u16, timeout: Duration, child: &mut std::process::Child) {
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

pub(crate) fn wait_for_registered_server_or_exit(
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

pub(crate) fn wait_for_child(
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

pub(crate) fn http_get(port: u16, host: &str, path: &str) -> HttpResponse {
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

pub(crate) fn host_for_branch(branch: &str, port: u16) -> String {
    if branch == "main" {
        format!("wp.localhost:{port}")
    } else {
        format!("{branch}.wp.localhost:{port}")
    }
}

pub(crate) fn git<I, S>(cwd: &Path, args: I)
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

pub(crate) fn git_output<I, S>(cwd: &Path, args: I) -> String
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

pub(crate) fn assert_clean_git(repo: &Path) {
    let status = git_output(repo, ["status", "--porcelain"]);
    assert_eq!(
        status.trim(),
        "",
        "checkout is dirty after ForkPress command:\n{status}"
    );
}

pub(crate) fn parse_server_pid(list: &str, work_dir: &Path) -> u32 {
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

pub(crate) fn top_commands(help: &str) -> Vec<&str> {
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

pub(crate) fn markdown_section<'a>(contents: &'a str, heading: &str) -> Option<&'a str> {
    let marker = format!("## {heading}");
    let start = contents.find(&marker)?;
    let after_start = start + marker.len();
    let rest = contents.get(after_start..)?;
    let end = rest.find("\n## ").unwrap_or(rest.len());
    rest.get(..end)
}

pub(crate) fn assert_file_contains(path: &Path, needle: &str) {
    let contents = fs::read_to_string(path)
        .unwrap_or_else(|err| panic!("failed to read {}: {err}", path.display()));
    assert_contains(&contents, needle, &format!("file {}", path.display()));
}

pub(crate) fn assert_contains(haystack: &str, needle: &str, context: &str) {
    assert!(
        haystack.contains(needle),
        "{context} did not contain {needle:?}\n{haystack}"
    );
}

pub(crate) fn assert_not_contains(haystack: &str, needle: &str, context: &str) {
    assert!(
        !haystack.contains(needle),
        "{context} unexpectedly contained {needle:?}\n{haystack}"
    );
}

pub(crate) fn combined(output: &Output) -> String {
    let mut combined = String::new();
    combined.push_str(&String::from_utf8_lossy(&output.stdout));
    combined.push_str(&String::from_utf8_lossy(&output.stderr));
    combined
}

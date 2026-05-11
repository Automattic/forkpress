use anyhow::{Context, Result, bail};
use forkpress_core::Layout;
use std::fs;
#[cfg(unix)]
use std::fs::{File, OpenOptions};
use std::net::{TcpStream, ToSocketAddrs};
use std::path::{Path, PathBuf};
#[cfg(windows)]
use std::process::Command;
use std::process::{Child, ExitStatus};
use std::thread;
use std::time::{Duration, Instant};

const SERVER_REGISTRY_FILE: &str = "servers.tsv";

pub struct ChildGuard {
    pub name: &'static str,
    pub child: Child,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ServerRecord {
    pub pid: u32,
    pub child_pid: Option<u32>,
    pub work_dir: PathBuf,
    pub host: String,
    pub port: u16,
    pub root_host: String,
    pub log: PathBuf,
}

pub struct ServerStartInfo {
    pub host: String,
    pub port: u16,
    pub root_host: String,
}

pub struct ServerRegistrationGuard {
    pid: u32,
    layout: Layout,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum ServerSignal {
    Interrupt,
    Terminate,
    Kill,
}

#[cfg(unix)]
struct ServerRegistryLock {
    file: File,
}

#[cfg(unix)]
impl Drop for ServerRegistryLock {
    fn drop(&mut self) {
        use std::os::fd::AsRawFd;
        unsafe {
            libc::flock(self.file.as_raw_fd(), libc::LOCK_UN);
        }
    }
}

#[cfg(not(unix))]
struct ServerRegistryLock;

impl ChildGuard {
    pub fn id(&self) -> u32 {
        self.child.id()
    }

    pub fn try_wait(&mut self) -> Result<Option<ExitStatus>> {
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

impl Drop for ServerRegistrationGuard {
    fn drop(&mut self) {
        let _ = unregister_running_server(&self.layout, self.pid);
    }
}

pub fn register_running_server(
    layout: &Layout,
    info: &ServerStartInfo,
    pid: u32,
    child_pid: Option<u32>,
) -> Result<ServerRegistrationGuard> {
    fs::create_dir_all(&layout.logs_dir)?;
    fs::write(&layout.server_pid_file, format!("{pid}\n")).with_context(|| {
        format!(
            "failed to write server pid file {}",
            layout.server_pid_file.display()
        )
    })?;

    let _registry_lock = lock_server_registry()?;
    let mut records = read_server_registry()?;
    records.retain(|record| {
        record.pid != pid && record.work_dir != layout.work_dir && record_process_exists(record)
    });
    records.push(ServerRecord {
        pid,
        child_pid,
        work_dir: layout.work_dir.clone(),
        host: info.host.clone(),
        port: info.port,
        root_host: info.root_host.clone(),
        log: layout.forkpress_server_log.clone(),
    });
    write_server_registry(&records)?;

    Ok(ServerRegistrationGuard {
        pid,
        layout: layout.clone(),
    })
}

pub fn unregister_running_server(layout: &Layout, pid: u32) -> Result<()> {
    if let Some(current_pid) = read_pid_file(&layout.server_pid_file)?
        && current_pid == pid
    {
        let _ = fs::remove_file(&layout.server_pid_file);
    }

    let _registry_lock = lock_server_registry()?;
    let mut records = read_server_registry()?;
    let original_len = records.len();
    records.retain(|record| record.pid != pid);
    if records.len() != original_len {
        write_server_registry(&records)?;
    }
    Ok(())
}

pub fn running_record_for_work_dir(work_dir: &Path) -> Result<Option<ServerRecord>> {
    Ok(live_server_records()?
        .into_iter()
        .find(|record| record.work_dir == work_dir))
}

pub fn live_server_records() -> Result<Vec<ServerRecord>> {
    let _registry_lock = lock_server_registry()?;
    let records = read_server_registry()?;
    let live: Vec<ServerRecord> = records.into_iter().filter(record_process_exists).collect();
    write_server_registry(&live)?;
    Ok(live)
}

pub fn read_pid_file(path: &Path) -> Result<Option<u32>> {
    let Ok(raw) = fs::read_to_string(path) else {
        return Ok(None);
    };
    let trimmed = raw.trim();
    if trimmed.is_empty() {
        return Ok(None);
    }
    let pid = trimmed
        .parse::<u32>()
        .with_context(|| format!("invalid pid in {}", path.display()))?;
    Ok(Some(pid))
}

pub fn stop_server_record(record: &ServerRecord, timeout: Duration) -> Result<()> {
    if !record_process_exists(record) {
        println!("forkpress: pid {} is no longer running", record.pid);
        let layout = Layout::new(record.work_dir.clone())?;
        let _ = unregister_running_server(&layout, record.pid);
        return Ok(());
    }

    signal_server_record(record, ServerSignal::Interrupt)?;
    if !wait_for_record_exit(record, timeout) {
        signal_server_record(record, ServerSignal::Terminate)?;
        if !wait_for_record_exit(record, Duration::from_secs(2)) {
            signal_server_record(record, ServerSignal::Kill)?;
            let _ = wait_for_record_exit(record, Duration::from_secs(2));
        }
    }

    let layout = Layout::new(record.work_dir.clone())?;
    let _ = unregister_running_server(&layout, record.pid);

    if record_process_exists(record) {
        bail!("failed to stop server pid {}", record.pid);
    }

    if tcp_port_open(&record.host, record.port) {
        eprintln!(
            "forkpress: warning: {}:{} is still accepting connections; another process may own the port",
            record.host, record.port
        );
    }

    println!(
        "forkpress: stopped server pid {} for http://{}:{}/",
        record.pid, record.root_host, record.port
    );
    Ok(())
}

pub fn parse_server_record_line(line: &str) -> Option<ServerRecord> {
    let fields: Vec<&str> = line.split('\t').collect();
    if fields.len() != 6 && fields.len() != 7 {
        return None;
    }
    let pid = fields[0].parse::<u32>().ok()?;
    let port = fields[3].parse::<u16>().ok()?;
    let child_pid = if fields.len() == 7 && !fields[6].is_empty() {
        Some(fields[6].parse::<u32>().ok()?)
    } else {
        None
    };

    Some(ServerRecord {
        pid,
        child_pid,
        work_dir: PathBuf::from(unescape_registry_field(fields[1])),
        host: unescape_registry_field(fields[2]),
        port,
        root_host: unescape_registry_field(fields[4]),
        log: PathBuf::from(unescape_registry_field(fields[5])),
    })
}

pub fn format_server_record_line(record: &ServerRecord) -> String {
    let mut out = String::new();
    out.push_str(&record.pid.to_string());
    out.push('\t');
    out.push_str(&escape_registry_field(&record.work_dir.to_string_lossy()));
    out.push('\t');
    out.push_str(&escape_registry_field(&record.host));
    out.push('\t');
    out.push_str(&record.port.to_string());
    out.push('\t');
    out.push_str(&escape_registry_field(&record.root_host));
    out.push('\t');
    out.push_str(&escape_registry_field(&record.log.to_string_lossy()));
    out.push('\t');
    if let Some(child_pid) = record.child_pid {
        out.push_str(&child_pid.to_string());
    }
    out
}

pub fn escape_registry_field(field: &str) -> String {
    let mut escaped = String::new();
    for ch in field.chars() {
        match ch {
            '\\' => escaped.push_str("\\\\"),
            '\t' => escaped.push_str("\\t"),
            '\n' => escaped.push_str("\\n"),
            '\r' => escaped.push_str("\\r"),
            other => escaped.push(other),
        }
    }
    escaped
}

pub fn unescape_registry_field(field: &str) -> String {
    let mut out = String::new();
    let mut chars = field.chars();
    while let Some(ch) = chars.next() {
        if ch != '\\' {
            out.push(ch);
            continue;
        }
        match chars.next() {
            Some('\\') => out.push('\\'),
            Some('t') => out.push('\t'),
            Some('n') => out.push('\n'),
            Some('r') => out.push('\r'),
            Some(other) => {
                out.push('\\');
                out.push(other);
            }
            None => out.push('\\'),
        }
    }
    out
}

pub fn wait_for_tcp(host: &str, port: u16, timeout: Duration) -> Result<()> {
    let deadline = Instant::now() + timeout;
    while Instant::now() < deadline {
        if tcp_port_open(host, port) {
            return Ok(());
        }
        thread::sleep(Duration::from_millis(250));
    }
    bail!("timed out waiting for {host}:{port}");
}

pub fn tcp_port_open(host: &str, port: u16) -> bool {
    let addrs = (host, port).to_socket_addrs();
    let Ok(addrs) = addrs else {
        return false;
    };

    addrs
        .into_iter()
        .any(|addr| TcpStream::connect_timeout(&addr, Duration::from_millis(250)).is_ok())
}

#[cfg(unix)]
fn lock_server_registry() -> Result<ServerRegistryLock> {
    use std::os::fd::AsRawFd;

    let path = server_registry_lock_path();
    if let Some(parent) = path.parent() {
        fs::create_dir_all(parent)
            .with_context(|| format!("failed to create {}", parent.display()))?;
    }
    let file = OpenOptions::new()
        .create(true)
        .read(true)
        .write(true)
        .truncate(false)
        .open(&path)
        .with_context(|| format!("failed to open {}", path.display()))?;
    let status = unsafe { libc::flock(file.as_raw_fd(), libc::LOCK_EX) };
    if status != 0 {
        bail!("failed to lock {}", path.display());
    }
    Ok(ServerRegistryLock { file })
}

#[cfg(not(unix))]
fn lock_server_registry() -> Result<ServerRegistryLock> {
    Ok(ServerRegistryLock)
}

fn read_server_registry() -> Result<Vec<ServerRecord>> {
    let path = server_registry_path();
    let Ok(raw) = fs::read_to_string(&path) else {
        return Ok(Vec::new());
    };

    let mut records = Vec::new();
    for line in raw.lines() {
        if let Some(record) = parse_server_record_line(line) {
            records.push(record);
        }
    }
    Ok(records)
}

fn write_server_registry(records: &[ServerRecord]) -> Result<()> {
    let path = server_registry_path();
    if let Some(parent) = path.parent() {
        fs::create_dir_all(parent)
            .with_context(|| format!("failed to create {}", parent.display()))?;
    }

    let mut out = String::new();
    for record in records {
        out.push_str(&format_server_record_line(record));
        out.push('\n');
    }

    fs::write(&path, out).with_context(|| format!("failed to write {}", path.display()))?;
    Ok(())
}

fn server_registry_path() -> PathBuf {
    if let Some(dir) = std::env::var_os("FORKPRESS_STATE_DIR") {
        return PathBuf::from(dir).join(SERVER_REGISTRY_FILE);
    }
    #[cfg(windows)]
    if let Some(dir) = std::env::var_os("LOCALAPPDATA") {
        return PathBuf::from(dir)
            .join("ForkPress")
            .join(SERVER_REGISTRY_FILE);
    }
    if let Some(dir) = std::env::var_os("XDG_STATE_HOME") {
        return PathBuf::from(dir)
            .join("forkpress")
            .join(SERVER_REGISTRY_FILE);
    }
    if let Some(home) = std::env::var_os("HOME") {
        return PathBuf::from(home)
            .join(".local/state/forkpress")
            .join(SERVER_REGISTRY_FILE);
    }
    std::env::temp_dir().join(format!(
        "forkpress-{}-{SERVER_REGISTRY_FILE}",
        std::env::var("USER")
            .or_else(|_| std::env::var("USERNAME"))
            .unwrap_or_else(|_| "user".to_string())
    ))
}

#[cfg(unix)]
fn server_registry_lock_path() -> PathBuf {
    server_registry_path().with_extension("tsv.lock")
}

fn wait_for_record_exit(record: &ServerRecord, timeout: Duration) -> bool {
    let deadline = Instant::now() + timeout;
    while Instant::now() < deadline {
        if !record_process_exists(record) {
            return true;
        }
        thread::sleep(Duration::from_millis(100));
    }
    !record_process_exists(record)
}

fn record_process_exists(record: &ServerRecord) -> bool {
    process_exists(record.pid) || record.child_pid.map(process_exists).unwrap_or(false)
}

#[cfg(unix)]
fn process_exists(pid: u32) -> bool {
    if pid == 0 {
        return false;
    }
    let rc = unsafe { libc::kill(pid as libc::pid_t, 0) };
    if rc == 0 {
        return true;
    }
    matches!(
        std::io::Error::last_os_error().raw_os_error(),
        Some(libc::EPERM)
    )
}

#[cfg(windows)]
fn process_exists(pid: u32) -> bool {
    if pid == 0 {
        return false;
    }

    use std::ffi::c_void;

    const ERROR_ACCESS_DENIED: u32 = 5;
    const PROCESS_QUERY_LIMITED_INFORMATION: u32 = 0x1000;

    #[link(name = "kernel32")]
    unsafe extern "system" {
        fn OpenProcess(
            dw_desired_access: u32,
            b_inherit_handle: i32,
            dw_process_id: u32,
        ) -> *mut c_void;
        fn CloseHandle(h_object: *mut c_void) -> i32;
        fn GetLastError() -> u32;
    }

    let handle = unsafe { OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, 0, pid) };
    if handle.is_null() {
        return unsafe { GetLastError() } == ERROR_ACCESS_DENIED;
    }
    let _ = unsafe { CloseHandle(handle) };
    true
}

#[cfg(unix)]
fn signal_server_record(record: &ServerRecord, signal: ServerSignal) -> Result<()> {
    let mut signaled_group = false;
    if process_exists(record.pid) {
        if process_group_id(record.pid) == Some(record.pid) {
            signal_process_group(record.pid, signal)?;
            signaled_group = true;
        } else {
            signal_process(record.pid, signal)?;
        }
    }

    if let Some(child_pid) = record.child_pid
        && process_exists(child_pid)
        && !signaled_group
    {
        if process_group_id(child_pid) == Some(record.pid) {
            signal_process_group(record.pid, signal)?;
        } else {
            signal_process(child_pid, signal)?;
        }
    }

    Ok(())
}

#[cfg(windows)]
fn signal_server_record(record: &ServerRecord, signal: ServerSignal) -> Result<()> {
    if let Some(child_pid) = record.child_pid
        && process_exists(child_pid)
    {
        signal_process(child_pid, signal)?;
    }
    if process_exists(record.pid) {
        signal_process(record.pid, signal)?;
    }
    Ok(())
}

#[cfg(unix)]
fn process_group_id(pid: u32) -> Option<u32> {
    if pid == 0 {
        return None;
    }
    let pgid = unsafe { libc::getpgid(pid as libc::pid_t) };
    if pgid < 0 { None } else { Some(pgid as u32) }
}

#[cfg(unix)]
fn signal_process_group(pgid: u32, signal: ServerSignal) -> Result<()> {
    if pgid == 0 {
        return Ok(());
    }
    let rc = unsafe { libc::kill(-(pgid as libc::pid_t), unix_signal(signal)) };
    if rc == 0 {
        return Ok(());
    }
    if matches!(
        std::io::Error::last_os_error().raw_os_error(),
        Some(libc::ESRCH)
    ) {
        return Ok(());
    }
    Err(std::io::Error::last_os_error())
        .with_context(|| format!("failed to signal process group {pgid}"))
}

#[cfg(unix)]
fn signal_process(pid: u32, signal: ServerSignal) -> Result<()> {
    let rc = unsafe { libc::kill(pid as libc::pid_t, unix_signal(signal)) };
    if rc == 0 {
        return Ok(());
    }
    if matches!(
        std::io::Error::last_os_error().raw_os_error(),
        Some(libc::ESRCH)
    ) {
        return Ok(());
    }
    Err(std::io::Error::last_os_error()).with_context(|| format!("failed to signal process {pid}"))
}

#[cfg(unix)]
fn unix_signal(signal: ServerSignal) -> i32 {
    match signal {
        ServerSignal::Interrupt => libc::SIGINT,
        ServerSignal::Terminate => libc::SIGTERM,
        ServerSignal::Kill => libc::SIGKILL,
    }
}

#[cfg(windows)]
fn signal_process(pid: u32, signal: ServerSignal) -> Result<()> {
    let mut command = Command::new("taskkill");
    command.arg("/PID").arg(pid.to_string()).arg("/T");
    if signal == ServerSignal::Kill {
        command.arg("/F");
    }
    let output = command.output().context("failed to run taskkill")?;
    if output.status.success() {
        return Ok(());
    }

    let stdout = String::from_utf8_lossy(&output.stdout);
    let stderr = String::from_utf8_lossy(&output.stderr);
    let combined = format!("{stdout}\n{stderr}").to_ascii_lowercase();
    if combined.contains("not found")
        || combined.contains("not running")
        || combined.contains("no running instance")
    {
        return Ok(());
    }
    bail!(
        "taskkill failed for pid {} with status {}{}{}{}{}",
        pid,
        output.status,
        if stdout.trim().is_empty() {
            ""
        } else {
            "\nstdout:\n"
        },
        stdout.trim(),
        if stderr.trim().is_empty() {
            ""
        } else {
            "\nstderr:\n"
        },
        stderr.trim()
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn registry_fields_round_trip_control_chars() {
        let value = "dir\\with\ttabs\nand\rreturns";
        assert_eq!(
            unescape_registry_field(&escape_registry_field(value)),
            value
        );
    }

    #[test]
    fn registry_record_lines_round_trip_child_pid() {
        let record = ServerRecord {
            pid: 123,
            child_pid: Some(456),
            work_dir: PathBuf::from("/tmp/fork press"),
            host: "127.0.0.1".to_string(),
            port: 18080,
            root_host: "wp.localhost".to_string(),
            log: PathBuf::from("/tmp/fork press/logs/server.log"),
        };
        let line = format_server_record_line(&record);
        assert_eq!(parse_server_record_line(&line), Some(record));
    }

    #[test]
    fn registry_record_parser_accepts_legacy_lines_without_child_pid() {
        let line = "123\t/tmp/forkpress\t127.0.0.1\t18080\twp.localhost\t/tmp/forkpress/log";
        let record = parse_server_record_line(line).unwrap();
        assert_eq!(record.pid, 123);
        assert_eq!(record.child_pid, None);
        assert_eq!(record.port, 18080);
    }
}

use anyhow::{anyhow, Context, Result};
use serde::{Deserialize, Serialize};
use std::ffi::OsStr;
use std::fs;
use std::io::{self, Write};
use std::path::{Path, PathBuf};
use std::process::{Child, Command, Stdio};
use std::thread;
use std::time::Duration;

use crate::config::{Manifest, Probe};
use crate::overlay::OverlayStore;

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct RemoteEntry {
    pub name: String,
    pub kind: String,
    pub size: u64,
    pub mode: u32,
    pub mtime: u64,
}

#[derive(Debug, Clone)]
pub struct RemoteClient {
    manifest: Manifest,
    control_path: Option<PathBuf>,
}

impl RemoteClient {
    pub fn new(manifest: Manifest, control_path: Option<PathBuf>) -> Self {
        Self {
            manifest,
            control_path,
        }
    }

    pub fn manifest(&self) -> &Manifest {
        &self.manifest
    }

    pub fn ensure_master(&self) -> Result<()> {
        let Some(control_path) = &self.control_path else {
            return Ok(());
        };
        if control_path.exists() {
            return Ok(());
        }
        if let Some(parent) = control_path.parent() {
            std::fs::create_dir_all(parent)?;
        }
        let mut command = Command::new("timeout");
        command
            .arg("--kill-after=2s")
            .arg(format!("{}s", ssh_connect_timeout_secs() + 5))
            .arg("ssh")
            .arg("-MNf")
            .arg("-S")
            .arg(control_path)
            .arg("-o")
            .arg("ControlMaster=yes")
            .arg("-o")
            .arg("ControlPersist=600");
        self.add_ssh_safety_options(&mut command);
        let status = command
            .arg(&self.manifest.ssh)
            .status()
            .context("start SSH control master")?;
        if !status.success() {
            return Err(anyhow!(
                "failed to start SSH control master for {}",
                self.manifest.ssh
            ));
        }
        Ok(())
    }

    pub fn command(&self, remote_command: &str) -> Command {
        self.ssh_command(remote_command, 0)
    }

    fn ssh_command(&self, remote_command: &str, timeout_secs: u64) -> Command {
        let mut command = if timeout_secs > 0 {
            let mut command = Command::new("timeout");
            command
                .arg("--kill-after=2s")
                .arg(format!("{}s", timeout_secs))
                .arg("ssh");
            command
        } else {
            Command::new("ssh")
        };

        if let Some(control_path) = &self.control_path {
            command.arg("-S").arg(control_path);
            command.arg("-o").arg("ControlMaster=auto");
            command.arg("-o").arg("ControlPersist=600");
        }
        self.add_ssh_safety_options(&mut command);
        command.arg(&self.manifest.ssh);
        command.arg(remote_command);
        command
    }

    pub fn exec_capture(&self, remote_command: &str, stdin: Option<&[u8]>) -> io::Result<Vec<u8>> {
        let timeout_secs = remote_command_timeout_secs();
        let mut command = self.ssh_command(remote_command, timeout_secs);

        let mut child = command
            .stdin(if stdin.is_some() {
                Stdio::piped()
            } else {
                Stdio::null()
            })
            .stdout(Stdio::piped())
            .stderr(Stdio::piped())
            .spawn()?;

        if let Some(input) = stdin {
            if let Some(mut child_stdin) = child.stdin.take() {
                child_stdin.write_all(input)?;
            }
        }

        let output = child.wait_with_output()?;
        if output.status.success() {
            return Ok(output.stdout);
        }
        let stderr = String::from_utf8_lossy(&output.stderr);
        if matches!(output.status.code(), Some(124) | Some(137)) {
            return Err(io::Error::new(
                io::ErrorKind::TimedOut,
                format!(
                    "remote command timed out after {} seconds: {}",
                    timeout_secs, stderr
                ),
            ));
        }
        if output.status.code() == Some(2) || stderr.contains("WPCOW_ENOENT") {
            return Err(io::Error::new(io::ErrorKind::NotFound, stderr.to_string()));
        }
        Err(io::Error::new(io::ErrorKind::Other, stderr.to_string()))
    }

    pub fn sync_runtime_files(&self, upper: &Path) -> Result<()> {
        fs::create_dir_all(upper).with_context(|| format!("create {}", upper.display()))?;

        let remote_command = format!(
            r#"cd {} && (find . -mindepth 1 -maxdepth 1 \( -type f -o -type l \) -print; find wp-content -mindepth 1 -maxdepth 1 \( -type f -o -type l \) -print 2>/dev/null; for p in wp-admin wp-includes wp-content/plugins wp-content/themes wp-content/mu-plugins wp-content/languages; do if [ -e "$p" ]; then printf '%s\n' "$p"; fi; done) | tar -cf - -T -"#,
            shell_quote(&self.manifest.remote_path)
        );

        let mut ssh = self
            .ssh_command(&remote_command, runtime_sync_timeout_secs())
            .stdout(Stdio::piped())
            .stderr(Stdio::inherit())
            .spawn()
            .context("start remote runtime tar over ssh")?;

        let mut tar = Command::new("tar")
            .arg("--no-same-owner")
            .arg("-C")
            .arg(upper)
            .arg("-xf")
            .arg("-")
            .stdin(Stdio::piped())
            .stderr(Stdio::inherit())
            .spawn()
            .context("start local runtime tar extraction")?;

        let copy_result = {
            let mut ssh_stdout = ssh.stdout.take().expect("ssh stdout piped");
            let mut tar_stdin = tar.stdin.take().expect("tar stdin piped");
            io::copy(&mut ssh_stdout, &mut tar_stdin).context("copy runtime tar stream")
        };

        let ssh_status = ssh.wait().context("wait for remote runtime tar")?;
        let tar_status = tar.wait().context("wait for local runtime tar")?;

        copy_result?;

        if !ssh_status.success() {
            return Err(anyhow!(
                "remote runtime tar failed with status {}",
                ssh_status
            ));
        }
        if !tar_status.success() {
            return Err(anyhow!(
                "local runtime tar extraction failed with status {}",
                tar_status
            ));
        }

        Ok(())
    }

    pub fn start_db_tunnel(&self) -> Result<Option<Child>> {
        if env_bool("WPCOW_REMOTE_DB_TUNNEL", true) == Some(false) {
            return Ok(None);
        }
        if self.manifest.probe.db_host.is_empty()
            || self.manifest.probe.db_name.is_empty()
            || self.manifest.probe.db_user.is_empty()
        {
            return Ok(None);
        }

        let Some((remote_host, remote_port)) = remote_db_tcp_target(&self.manifest.probe.db_host)
        else {
            return Ok(None);
        };

        let bind = format!(
            "{}:{}:{}:{}",
            self.manifest.remote_db_tunnel.host,
            self.manifest.remote_db_tunnel.port,
            remote_host,
            remote_port
        );
        let mut command = Command::new("ssh");
        if let Some(control_path) = &self.control_path {
            command.arg("-S").arg(control_path);
            command.arg("-o").arg("ControlMaster=auto");
            command.arg("-o").arg("ControlPersist=600");
        }
        self.add_ssh_safety_options(&mut command);
        command
            .arg("-o")
            .arg("ExitOnForwardFailure=yes")
            .arg("-N")
            .arg("-L")
            .arg(bind)
            .arg(&self.manifest.ssh)
            .stdin(Stdio::null())
            .stdout(Stdio::null())
            .stderr(Stdio::piped());

        let mut child = command.spawn().context("start remote DB SSH tunnel")?;
        for _ in 0..20 {
            if let Some(status) = child.try_wait()? {
                let mut stderr = String::new();
                if let Some(mut err) = child.stderr.take() {
                    use std::io::Read;
                    let _ = err.read_to_string(&mut stderr);
                }
                return Err(anyhow!(
                    "remote DB SSH tunnel exited with status {}: {}",
                    status,
                    stderr
                ));
            }
            thread::sleep(Duration::from_millis(50));
        }

        Ok(Some(child))
    }

    pub fn stat(&self, rel: &Path) -> io::Result<RemoteEntry> {
        let full = self.remote_full_path(rel)?;
        let code = r#"
$p=$argv[1];
clearstatcache(true,$p);
$s=@lstat($p);
if($s===false){fwrite(STDERR,"WPCOW_ENOENT\n");exit(2);}
$kind=is_link($p)?"symlink":(is_dir($p)?"dir":(is_file($p)?"file":"other"));
echo json_encode(array(
 "name"=>basename($p),
 "kind"=>$kind,
 "size"=>(int)$s["size"],
 "mode"=>(int)$s["mode"],
 "mtime"=>(int)$s["mtime"]
));
"#;
        let bytes = self.php_eval(code, &[full])?;
        serde_json::from_slice(&bytes)
            .map_err(|err| io::Error::new(io::ErrorKind::InvalidData, err))
    }

    pub fn readdir(&self, rel: &Path) -> io::Result<Vec<RemoteEntry>> {
        let full = self.remote_full_path(rel)?;
        let code = r#"
$p=$argv[1];
if(!is_dir($p)){fwrite(STDERR,"WPCOW_ENOENT\n");exit(2);}
$out=array();
foreach(scandir($p) as $name){
 if($name==="."||$name===".."){continue;}
 $child=$p.DIRECTORY_SEPARATOR.$name;
 $s=@lstat($child);
 if($s===false){continue;}
 $kind=is_link($child)?"symlink":(is_dir($child)?"dir":(is_file($child)?"file":"other"));
 $out[]=array("name"=>$name,"kind"=>$kind,"size"=>(int)$s["size"],"mode"=>(int)$s["mode"],"mtime"=>(int)$s["mtime"]);
}
echo json_encode($out);
"#;
        let bytes = self.php_eval(code, &[full])?;
        serde_json::from_slice(&bytes)
            .map_err(|err| io::Error::new(io::ErrorKind::InvalidData, err))
    }

    pub fn read_range(&self, rel: &Path, offset: u64, length: usize) -> io::Result<Vec<u8>> {
        let full = self.remote_full_path(rel)?;
        let code = r#"
$p=$argv[1];$offset=(int)$argv[2];$length=(int)$argv[3];
$f=@fopen($p,"rb");
if(!$f){fwrite(STDERR,"WPCOW_ENOENT\n");exit(2);}
if($offset>0){fseek($f,$offset);}
echo fread($f,$length);
"#;
        self.php_eval(code, &[full, offset.to_string(), length.to_string()])
    }

    pub fn readlink(&self, rel: &Path) -> io::Result<String> {
        let full = self.remote_full_path(rel)?;
        let code = r#"
$p=$argv[1];
$target=@readlink($p);
if($target===false){fwrite(STDERR,"WPCOW_ENOENT\n");exit(2);}
echo $target;
"#;
        let bytes = self.php_eval(code, &[full])?;
        Ok(String::from_utf8_lossy(&bytes).to_string())
    }

    pub fn remote_query_readonly(&self, sql: &str) -> Result<RemoteQueryResult> {
        let probe = &self.manifest.probe;
        let code = r#"
$host=$argv[1];$user=$argv[2];$pass=$argv[3];$db=$argv[4];$sql=$argv[5];$timeout=(int)$argv[6];
if($timeout<1){$timeout=10;}
@set_time_limit($timeout);
if(!preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i',$sql)){
 fwrite(STDERR,"WPCOW_REFUSED_WRITE\n");exit(3);
}
$port=null;$socket=null;
if(preg_match('/^(.+):([0-9]+)$/',$host,$m)){
 $host=$m[1];$port=(int)$m[2];
} elseif(preg_match('/^([^:]+):(\/.*)$/',$host,$m)){
 $host=$m[1];$socket=$m[2];
}
$mysqli=mysqli_init();
@$mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, min(5,$timeout));
if(!@$mysqli->real_connect($host,$user,$pass,$db,$port,$socket)){
 fwrite(STDERR,mysqli_connect_error()."\n");exit(1);
}
@$mysqli->set_charset("utf8mb4");
@$mysqli->query("SET SESSION max_execution_time=".max(1,$timeout * 1000));
@$mysqli->query("SET SESSION max_statement_time=".max(1,$timeout));
$res=$mysqli->query($sql, MYSQLI_STORE_RESULT);
if($res===false){
 echo json_encode(array("ok"=>false,"error"=>$mysqli->error,"rows"=>array(),"fields"=>array(),"affected"=>0));
 exit(0);
}
if($res===true){
 echo json_encode(array("ok"=>true,"error"=>"","rows"=>array(),"fields"=>array(),"affected"=>$mysqli->affected_rows));
 exit(0);
}
$fields=array();
foreach($res->fetch_fields() as $field){$fields[]=$field->name;}
$rows=array();
while($row=$res->fetch_assoc()){$rows[]=$row;}
echo json_encode(array("ok"=>true,"error"=>"","rows"=>$rows,"fields"=>$fields,"affected"=>count($rows)));
"#;
        let bytes = self
            .php_eval(
                code,
                &[
                    probe.db_host.clone(),
                    probe.db_user.clone(),
                    probe.db_password.clone(),
                    probe.db_name.clone(),
                    sql.to_string(),
                    remote_db_query_timeout_secs().to_string(),
                ],
            )
            .context("remote readonly query")?;
        let result: RemoteQueryResult = serde_json::from_slice(&bytes)?;
        Ok(result)
    }

    fn php_eval(&self, code: &str, args: &[String]) -> io::Result<Vec<u8>> {
        let mut command = format!("php -r {} --", shell_quote(code));
        for arg in args {
            command.push(' ');
            command.push_str(&shell_quote(arg));
        }
        self.exec_capture(&command, None)
    }

    fn remote_full_path(&self, rel: &Path) -> io::Result<String> {
        let rel = OverlayStore::clean_rel(rel)
            .map_err(|err| io::Error::new(io::ErrorKind::InvalidInput, err.to_string()))?;
        let rel = OverlayStore::rel_string(&rel);
        if rel.is_empty() {
            Ok(self.manifest.remote_path.clone())
        } else {
            Ok(format!(
                "{}/{}",
                self.manifest.remote_path.trim_end_matches('/'),
                rel
            ))
        }
    }

    fn add_ssh_safety_options(&self, command: &mut Command) {
        let connect_timeout = ssh_connect_timeout_secs();
        command
            .arg("-o")
            .arg(format!("ConnectTimeout={connect_timeout}"));
        command.arg("-o").arg("ServerAliveInterval=5");
        command.arg("-o").arg("ServerAliveCountMax=1");
        command.arg("-o").arg("BatchMode=yes");
    }
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct RemoteQueryResult {
    pub ok: bool,
    pub error: String,
    pub rows: Vec<serde_json::Map<String, serde_json::Value>>,
    pub fields: Vec<String>,
    pub affected: i64,
}

pub fn probe_wordpress(ssh: &str, remote_path: &str) -> Result<Probe> {
    let script = r#"
<?php
error_reporting(E_ERROR | E_PARSE);
if (!file_exists('wp-load.php')) {
    fwrite(STDERR, "wp-load.php not found\n");
    exit(2);
}
define('WP_USE_THEMES', false);
require_once 'wp-load.php';
global $wpdb;
$uploads = function_exists('wp_upload_dir') ? wp_upload_dir(null, false, false) : array('basedir' => WP_CONTENT_DIR . '/uploads');
$out = array(
    'abspath' => defined('ABSPATH') ? ABSPATH : getcwd(),
    'wp_content_dir' => defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : getcwd() . '/wp-content',
    'uploads_dir' => isset($uploads['basedir']) ? $uploads['basedir'] : '',
    'table_prefix' => isset($wpdb) ? $wpdb->prefix : (isset($table_prefix) ? $table_prefix : 'wp_'),
    'db_name' => defined('DB_NAME') ? DB_NAME : '',
    'db_host' => defined('DB_HOST') ? DB_HOST : '',
    'db_user' => defined('DB_USER') ? DB_USER : '',
    'db_password' => defined('DB_PASSWORD') ? DB_PASSWORD : '',
    'siteurl' => function_exists('get_option') ? get_option('siteurl') : '',
    'home' => function_exists('get_option') ? get_option('home') : ''
);
echo json_encode($out);
"#;

    let remote_command = format!("cd {} && php", shell_quote(remote_path));
    let output = Command::new("timeout")
        .arg("--kill-after=2s")
        .arg(format!("{}s", remote_command_timeout_secs()))
        .arg("ssh")
        .arg("-o")
        .arg(format!("ConnectTimeout={}", ssh_connect_timeout_secs()))
        .arg("-o")
        .arg("ServerAliveInterval=5")
        .arg("-o")
        .arg("ServerAliveCountMax=1")
        .arg("-o")
        .arg("BatchMode=yes")
        .arg(ssh)
        .arg(remote_command)
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .and_then(|mut child| {
            child
                .stdin
                .as_mut()
                .expect("stdin is piped")
                .write_all(script.as_bytes())?;
            child.wait_with_output()
        })
        .context("run remote WordPress probe")?;

    if !output.status.success() {
        return Err(anyhow!(
            "remote probe failed: {}",
            String::from_utf8_lossy(&output.stderr)
        ));
    }

    let probe: Probe = serde_json::from_slice(&output.stdout)
        .with_context(|| String::from_utf8_lossy(&output.stdout).to_string())?;
    Ok(probe)
}

pub fn shell_quote(value: impl AsRef<OsStr>) -> String {
    let value = value.as_ref().to_string_lossy();
    if value.is_empty() {
        return "''".to_string();
    }
    let escaped = value.replace('\'', "'\"'\"'");
    format!("'{}'", escaped)
}

fn remote_command_timeout_secs() -> u64 {
    env_u64("WPCOW_REMOTE_COMMAND_TIMEOUT_SECS", 20)
}

fn runtime_sync_timeout_secs() -> u64 {
    env_u64("WPCOW_RUNTIME_SYNC_TIMEOUT_SECS", 180)
}

fn remote_db_query_timeout_secs() -> u64 {
    env_u64("WPCOW_REMOTE_DB_QUERY_TIMEOUT_SECS", 10)
}

fn ssh_connect_timeout_secs() -> u64 {
    env_u64("WPCOW_SSH_CONNECT_TIMEOUT_SECS", 8)
}

fn env_u64(name: &str, default: u64) -> u64 {
    std::env::var(name)
        .ok()
        .and_then(|raw| raw.parse::<u64>().ok())
        .unwrap_or(default)
}

fn env_bool(name: &str, default: bool) -> Option<bool> {
    let raw = std::env::var(name).ok()?;
    match raw.to_ascii_lowercase().as_str() {
        "1" | "true" | "yes" | "on" => Some(true),
        "0" | "false" | "no" | "off" => Some(false),
        _ => Some(default),
    }
}

fn remote_db_tcp_target(db_host: &str) -> Option<(String, u16)> {
    if db_host.contains(":/") {
        return None;
    }

    let (host, port) = if let Some((host, port)) = db_host.rsplit_once(':') {
        if let Ok(port) = port.parse::<u16>() {
            (host, port)
        } else {
            (db_host, 3306)
        }
    } else {
        (db_host, 3306)
    };

    let host = match host {
        "" | "localhost" => "127.0.0.1",
        other => other,
    };

    Some((host.to_string(), port))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn quotes_shell_strings() {
        assert_eq!(shell_quote("abc"), "'abc'");
        assert_eq!(shell_quote("a'b"), "'a'\"'\"'b'");
        assert_eq!(shell_quote(""), "''");
    }

    #[test]
    fn parses_remote_db_tcp_targets() {
        assert_eq!(
            remote_db_tcp_target("localhost"),
            Some(("127.0.0.1".to_string(), 3306))
        );
        assert_eq!(
            remote_db_tcp_target("db.example.com:3307"),
            Some(("db.example.com".to_string(), 3307))
        );
        assert_eq!(remote_db_tcp_target("localhost:/tmp/mysql.sock"), None);
    }
}

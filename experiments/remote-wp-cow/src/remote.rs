use anyhow::{anyhow, Context, Result};
use base64::{engine::general_purpose::STANDARD as BASE64, Engine as _};
use serde::{Deserialize, Serialize};
use std::ffi::OsStr;
use std::io::{self, BufRead, BufReader, Read, Write};
use std::os::unix::io::AsRawFd;
use std::path::{Path, PathBuf};
use std::process::{Child, ChildStdin, ChildStdout, Command, Stdio};
use std::sync::{Arc, Mutex};
use std::thread;
use std::time::{Duration, Instant};

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
pub struct RemoteStat {
    pub entry: RemoteEntry,
    pub data: Option<Vec<u8>>,
}

#[derive(Debug, Clone)]
pub struct RuntimeCodePackLimits {
    pub max_file_bytes: u64,
    pub max_total_bytes: u64,
    pub max_files: u64,
}

#[derive(Debug, Clone)]
pub struct RuntimeCodePackFile {
    pub rel: PathBuf,
    pub entry: RemoteEntry,
    pub bytes: Vec<u8>,
}

#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct RuntimeCodePackSummary {
    pub files: u64,
    pub bytes: u64,
    pub skipped: u64,
    pub capped: bool,
}

#[derive(Debug, Clone)]
pub struct RemoteClient {
    manifest: Manifest,
    control_path: Option<PathBuf>,
    file_helper: Arc<Mutex<Option<RemoteFileHelper>>>,
    db_helper: Arc<Mutex<Option<RemoteDbHelper>>>,
}

impl RemoteClient {
    pub fn new(manifest: Manifest, control_path: Option<PathBuf>) -> Self {
        Self {
            manifest,
            control_path,
            file_helper: Arc::new(Mutex::new(None)),
            db_helper: Arc::new(Mutex::new(None)),
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

    pub fn stop_master(&self) -> Result<()> {
        let Some(control_path) = &self.control_path else {
            return Ok(());
        };
        if !control_path.exists() {
            return Ok(());
        }

        let mut command = Command::new("ssh");
        command.arg("-S").arg(control_path);
        command.arg("-O").arg("exit");
        self.add_ssh_safety_options(&mut command);
        let status = command
            .arg(&self.manifest.ssh)
            .status()
            .context("stop SSH control master")?;
        if !status.success() {
            return Err(anyhow!(
                "failed to stop SSH control master for {}",
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

    pub fn start_db_tunnel(&self) -> Result<Option<Child>> {
        if env_bool("WPCOW_REMOTE_DB_TUNNEL", false) != Some(true) {
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
        let started = Instant::now();
        let result = self.stat_inner(rel);
        trace_remote_result("stat", &OverlayStore::rel_string(rel), started, &result);
        result
    }

    fn stat_inner(&self, rel: &Path) -> io::Result<RemoteEntry> {
        self.stat_prefetch_inner(rel, 0).map(|stat| stat.entry)
    }

    pub fn stat_prefetch(&self, rel: &Path, max_file_bytes: u64) -> io::Result<RemoteStat> {
        let started = Instant::now();
        let result = self.stat_prefetch_inner(rel, max_file_bytes);
        trace_remote_result(
            "stat_prefetch",
            &format!("{}<= {}", OverlayStore::rel_string(rel), max_file_bytes),
            started,
            &result,
        );
        result
    }

    fn stat_prefetch_inner(&self, rel: &Path, max_file_bytes: u64) -> io::Result<RemoteStat> {
        let full = self.remote_full_path(rel)?;
        if remote_file_helper_enabled() {
            let request = serde_json::json!({
                "op": "stat",
                "path": full,
                "max_file_bytes": max_file_bytes,
            });
            if let Ok(response) = self.file_helper_request(request) {
                if let Some(entry) = response.get("entry") {
                    let entry: RemoteEntry = serde_json::from_value(entry.clone())
                        .map_err(|err| io::Error::new(io::ErrorKind::InvalidData, err))?;
                    let data = if response.get("data").is_some() {
                        match decode_helper_data(response) {
                            Ok(bytes)
                                if entry.kind == "file" && bytes.len() as u64 == entry.size =>
                            {
                                Some(bytes)
                            }
                            _ => None,
                        }
                    } else {
                        None
                    };
                    return Ok(RemoteStat { entry, data });
                }
            }
        }

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
        let entry = serde_json::from_slice(&bytes)
            .map_err(|err| io::Error::new(io::ErrorKind::InvalidData, err))?;
        Ok(RemoteStat { entry, data: None })
    }

    pub fn readdir(&self, rel: &Path) -> io::Result<Vec<RemoteEntry>> {
        let started = Instant::now();
        let result = self.readdir_inner(rel);
        trace_remote_result("readdir", &OverlayStore::rel_string(rel), started, &result);
        result
    }

    fn readdir_inner(&self, rel: &Path) -> io::Result<Vec<RemoteEntry>> {
        let full = self.remote_full_path(rel)?;
        if remote_file_helper_enabled() {
            let request = serde_json::json!({
                "op": "readdir",
                "path": full,
            });
            if let Ok(response) = self.file_helper_request(request) {
                if let Some(entries) = response.get("entries") {
                    return serde_json::from_value(entries.clone())
                        .map_err(|err| io::Error::new(io::ErrorKind::InvalidData, err));
                }
            }
        }

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

    pub fn prefetch_dir(
        &self,
        rel: &Path,
        max_file_bytes: u64,
        max_total_bytes: u64,
    ) -> io::Result<Vec<RemoteStat>> {
        let started = Instant::now();
        let result = self.prefetch_dir_inner(rel, max_file_bytes, max_total_bytes);
        trace_remote_result(
            "prefetch_dir",
            &format!(
                "{}<= file:{} total:{}",
                OverlayStore::rel_string(rel),
                max_file_bytes,
                max_total_bytes
            ),
            started,
            &result,
        );
        result
    }

    fn prefetch_dir_inner(
        &self,
        rel: &Path,
        max_file_bytes: u64,
        max_total_bytes: u64,
    ) -> io::Result<Vec<RemoteStat>> {
        if max_file_bytes == 0 || max_total_bytes == 0 || !remote_file_helper_enabled() {
            return Ok(Vec::new());
        }
        let full = self.remote_full_path(rel)?;
        let request = serde_json::json!({
            "op": "prefetch_dir",
            "path": full,
            "max_file_bytes": max_file_bytes,
            "max_total_bytes": max_total_bytes,
        });
        let response = self.file_helper_request(request)?;
        let mut out = Vec::new();
        let Some(files) = response.get("files").and_then(|value| value.as_array()) else {
            return Ok(out);
        };
        for file in files {
            let Some(entry_value) = file.get("entry") else {
                continue;
            };
            let entry: RemoteEntry = serde_json::from_value(entry_value.clone())
                .map_err(|err| io::Error::new(io::ErrorKind::InvalidData, err))?;
            let data = if file.get("data").is_some() {
                match decode_helper_data(file.clone()) {
                    Ok(bytes) if entry.kind == "file" && bytes.len() as u64 == entry.size => {
                        Some(bytes)
                    }
                    _ => None,
                }
            } else {
                None
            };
            out.push(RemoteStat { entry, data });
        }
        Ok(out)
    }

    pub fn read_range(&self, rel: &Path, offset: u64, length: usize) -> io::Result<Vec<u8>> {
        let started = Instant::now();
        let result = self.read_range_inner(rel, offset, length);
        trace_remote_result(
            "read_range",
            &format!("{}@{}+{}", OverlayStore::rel_string(rel), offset, length),
            started,
            &result,
        );
        result
    }

    fn read_range_inner(&self, rel: &Path, offset: u64, length: usize) -> io::Result<Vec<u8>> {
        let full = self.remote_full_path(rel)?;
        if remote_file_helper_enabled() {
            let request = serde_json::json!({
                "op": "read_range",
                "path": full,
                "offset": offset,
                "length": length,
            });
            if let Ok(response) = self.file_helper_request(request) {
                return decode_helper_data(response);
            }
        }

        let code = r#"
$p=$argv[1];$offset=(int)$argv[2];$length=(int)$argv[3];
$f=@fopen($p,"rb");
if(!$f){fwrite(STDERR,"WPCOW_ENOENT\n");exit(2);}
if($offset>0){fseek($f,$offset);}
echo fread($f,$length);
"#;
        self.php_eval(code, &[full, offset.to_string(), length.to_string()])
    }

    pub fn read_file(&self, rel: &Path) -> io::Result<Vec<u8>> {
        let started = Instant::now();
        let result = self.read_file_inner(rel);
        trace_remote_result(
            "read_file",
            &OverlayStore::rel_string(rel),
            started,
            &result,
        );
        result
    }

    fn read_file_inner(&self, rel: &Path) -> io::Result<Vec<u8>> {
        let full = self.remote_full_path(rel)?;
        if remote_file_helper_enabled() {
            let request = serde_json::json!({
                "op": "read_file",
                "path": full,
            });
            if let Ok(response) = self.file_helper_request(request) {
                return decode_helper_data(response);
            }
        }

        let code = r#"
$p=$argv[1];
$f=@fopen($p,"rb");
if(!$f){fwrite(STDERR,"WPCOW_ENOENT\n");exit(2);}
while(!feof($f)){
 echo fread($f,1048576);
}
"#;
        self.php_eval(code, &[full])
    }

    pub fn readlink(&self, rel: &Path) -> io::Result<String> {
        let started = Instant::now();
        let result = self.readlink_inner(rel);
        trace_remote_result("readlink", &OverlayStore::rel_string(rel), started, &result);
        result
    }

    fn readlink_inner(&self, rel: &Path) -> io::Result<String> {
        let full = self.remote_full_path(rel)?;
        if remote_file_helper_enabled() {
            let request = serde_json::json!({
                "op": "readlink",
                "path": full,
            });
            if let Ok(response) = self.file_helper_request(request) {
                if let Some(target) = response.get("target").and_then(|value| value.as_str()) {
                    return Ok(target.to_string());
                }
            }
        }

        let code = r#"
$p=$argv[1];
$target=@readlink($p);
if($target===false){fwrite(STDERR,"WPCOW_ENOENT\n");exit(2);}
echo $target;
"#;
        let bytes = self.php_eval(code, &[full])?;
        Ok(String::from_utf8_lossy(&bytes).to_string())
    }

    pub fn runtime_code_pack<F>(
        &self,
        roots: &[PathBuf],
        limits: RuntimeCodePackLimits,
        mut on_file: F,
    ) -> Result<RuntimeCodePackSummary>
    where
        F: FnMut(RuntimeCodePackFile) -> Result<()>,
    {
        let started = Instant::now();
        let result = self.runtime_code_pack_inner(roots, limits, &mut on_file);
        trace_remote_result(
            "runtime_code_pack",
            &format!("{} roots", roots.len()),
            started,
            &result,
        );
        result
    }

    fn runtime_code_pack_inner<F>(
        &self,
        roots: &[PathBuf],
        limits: RuntimeCodePackLimits,
        on_file: &mut F,
    ) -> Result<RuntimeCodePackSummary>
    where
        F: FnMut(RuntimeCodePackFile) -> Result<()>,
    {
        if limits.max_file_bytes == 0 || limits.max_total_bytes == 0 || limits.max_files == 0 {
            return Ok(RuntimeCodePackSummary::default());
        }

        let roots = roots
            .iter()
            .map(|root| {
                OverlayStore::clean_rel(root)
                    .map(|clean| OverlayStore::rel_string(&clean))
                    .map_err(|err| io::Error::new(io::ErrorKind::InvalidInput, err.to_string()))
            })
            .collect::<io::Result<Vec<_>>>()?;
        if roots.is_empty() {
            return Ok(RuntimeCodePackSummary::default());
        }

        let mut remote_command = format!("php -r {} --", shell_quote(runtime_code_pack_php()));
        for arg in [
            self.manifest.remote_path.clone(),
            serde_json::to_string(&roots)?,
            limits.max_file_bytes.to_string(),
            limits.max_total_bytes.to_string(),
            limits.max_files.to_string(),
        ] {
            remote_command.push(' ');
            remote_command.push_str(&shell_quote(arg));
        }

        let mut command = self.ssh_command(&remote_command, runtime_code_pack_timeout_secs());
        let mut child = command
            .stdin(Stdio::null())
            .stdout(Stdio::piped())
            .stderr(Stdio::piped())
            .spawn()
            .context("start remote runtime code pack")?;
        let stdout = child
            .stdout
            .take()
            .ok_or_else(|| anyhow!("runtime code pack stdout"))?;

        let mut summary = RuntimeCodePackSummary::default();
        for line in BufReader::new(stdout).lines() {
            let line = line.context("read remote runtime code pack")?;
            if line.trim().is_empty() {
                continue;
            }
            let value: serde_json::Value = serde_json::from_str(&line)
                .with_context(|| format!("decode remote runtime code pack line: {line}"))?;
            match value.get("type").and_then(|value| value.as_str()) {
                Some("file") => {
                    let rel = value
                        .get("path")
                        .and_then(|value| value.as_str())
                        .ok_or_else(|| anyhow!("runtime code pack file missing path"))?;
                    let entry: RemoteEntry = serde_json::from_value(
                        value
                            .get("entry")
                            .cloned()
                            .ok_or_else(|| anyhow!("runtime code pack file missing entry"))?,
                    )?;
                    let bytes = decode_helper_data(value.clone())?;
                    if entry.kind == "file" && bytes.len() as u64 == entry.size {
                        on_file(RuntimeCodePackFile {
                            rel: PathBuf::from(rel),
                            entry,
                            bytes,
                        })?;
                    }
                }
                Some("summary") => {
                    summary = serde_json::from_value(value.clone())?;
                }
                Some("error") => {
                    let error = value
                        .get("error")
                        .and_then(|value| value.as_str())
                        .unwrap_or("remote runtime code pack failed");
                    return Err(anyhow!(error.to_string()));
                }
                _ => {}
            }
        }

        let output = child.wait_with_output()?;
        if !output.status.success() {
            return Err(anyhow!(
                "remote runtime code pack exited with status {}: {}",
                output.status,
                String::from_utf8_lossy(&output.stderr)
            ));
        }

        Ok(summary)
    }

    pub fn remote_query_readonly(&self, sql: &str) -> Result<RemoteQueryResult> {
        let started = Instant::now();
        let result = self.remote_query_readonly_inner(sql);
        trace_remote_result("query", sql, started, &result);
        result
    }

    fn remote_query_readonly_inner(&self, sql: &str) -> Result<RemoteQueryResult> {
        if remote_db_helper_enabled() {
            if let Ok(result) = self.db_helper_query(sql) {
                if result.ok || !is_remote_db_connection_lost(&result.error) {
                    return Ok(result);
                }
                if let Ok(retry) = self.reset_db_helper_and_retry(sql) {
                    return Ok(retry);
                }
            }
        }

        self.remote_query_readonly_oneshot(sql)
    }

    fn remote_query_readonly_oneshot(&self, sql: &str) -> Result<RemoteQueryResult> {
        let probe = &self.manifest.probe;
        let code = r#"
$host=$argv[1];$user=$argv[2];$pass=$argv[3];$db=$argv[4];$sql=$argv[5];$timeout=(int)$argv[6];
if($timeout<1){$timeout=10;}
@set_time_limit($timeout);
if(function_exists("mysqli_report")){mysqli_report(MYSQLI_REPORT_OFF);}
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

    fn db_helper_query(&self, sql: &str) -> Result<RemoteQueryResult> {
        let mut last_error = None;
        for _ in 0..2 {
            match self.db_helper_query_once(sql) {
                Ok(response) => return Ok(response),
                Err(err) => {
                    last_error = Some(err);
                    let mut helper = self
                        .db_helper
                        .lock()
                        .map_err(|_| anyhow!("remote DB helper lock"))?;
                    reset_db_helper(&mut helper);
                }
            }
        }
        Err(last_error.unwrap_or_else(|| anyhow!("remote DB helper failed")))
    }

    fn reset_db_helper_and_retry(&self, sql: &str) -> Result<RemoteQueryResult> {
        {
            let mut helper = self
                .db_helper
                .lock()
                .map_err(|_| anyhow!("remote DB helper lock"))?;
            reset_db_helper(&mut helper);
        }
        self.db_helper_query(sql)
    }

    fn db_helper_query_once(&self, sql: &str) -> Result<RemoteQueryResult> {
        let mut helper = self
            .db_helper
            .lock()
            .map_err(|_| anyhow!("remote DB helper lock"))?;
        if helper.is_none() {
            *helper = Some(self.start_db_helper()?);
        }
        let helper = helper
            .as_mut()
            .ok_or_else(|| anyhow!("remote DB helper missing"))?;
        let request = serde_json::to_vec(&serde_json::json!({ "sql": sql }))?;
        helper.stdin.write_all(&request)?;
        helper.stdin.write_all(b"\n")?;
        helper.stdin.flush()?;

        let timeout = Duration::from_secs(remote_db_query_timeout_secs().saturating_add(2));
        let line = read_helper_line(&mut helper.stdout, timeout, "remote DB helper")?;
        let response: serde_json::Value = serde_json::from_str(&line)?;
        if response
            .get("ok")
            .and_then(|value| value.as_bool())
            .is_none()
        {
            let error = response
                .get("error")
                .and_then(|value| value.as_str())
                .unwrap_or("remote DB helper response missing ok");
            return Err(anyhow!(error.to_string()));
        }
        Ok(serde_json::from_value(response)?)
    }

    fn file_helper_request(&self, request: serde_json::Value) -> io::Result<serde_json::Value> {
        let mut last_error = None;
        for _ in 0..2 {
            match self.file_helper_request_once(&request) {
                Ok(response) => return Ok(response),
                Err(err) => {
                    last_error = Some(err);
                    let mut helper = self
                        .file_helper
                        .lock()
                        .map_err(|_| io::Error::new(io::ErrorKind::Other, "file helper lock"))?;
                    reset_file_helper(&mut helper);
                }
            }
        }
        Err(last_error
            .unwrap_or_else(|| io::Error::new(io::ErrorKind::Other, "remote file helper failed")))
    }

    fn file_helper_request_once(
        &self,
        request: &serde_json::Value,
    ) -> io::Result<serde_json::Value> {
        let mut helper = self
            .file_helper
            .lock()
            .map_err(|_| io::Error::new(io::ErrorKind::Other, "file helper lock"))?;
        if helper.is_none() {
            *helper = Some(self.start_file_helper()?);
        }
        let helper = helper
            .as_mut()
            .ok_or_else(|| io::Error::new(io::ErrorKind::BrokenPipe, "file helper missing"))?;
        let request = serde_json::to_vec(request)
            .map_err(|err| io::Error::new(io::ErrorKind::InvalidInput, err))?;
        helper.stdin.write_all(&request)?;
        helper.stdin.write_all(b"\n")?;
        helper.stdin.flush()?;

        let line = read_helper_line(
            &mut helper.stdout,
            Duration::from_secs(remote_file_helper_timeout_secs()),
            "remote file helper",
        )?;
        let response: serde_json::Value = serde_json::from_str(&line)
            .map_err(|err| io::Error::new(io::ErrorKind::InvalidData, err))?;
        if response.get("ok").and_then(|value| value.as_bool()) == Some(true) {
            return Ok(response);
        }
        let error = response
            .get("error")
            .and_then(|value| value.as_str())
            .unwrap_or("remote file helper error")
            .to_string();
        let kind = if response.get("kind").and_then(|value| value.as_str()) == Some("not_found") {
            io::ErrorKind::NotFound
        } else {
            io::ErrorKind::Other
        };
        Err(io::Error::new(kind, error))
    }

    fn start_file_helper(&self) -> io::Result<RemoteFileHelper> {
        let remote_command = format!("php -r {}", shell_quote(remote_file_helper_php()));
        let mut command = self.ssh_command(&remote_command, 0);
        let mut child = command
            .stdin(Stdio::piped())
            .stdout(Stdio::piped())
            .stderr(Stdio::null())
            .spawn()?;
        let stdin = child
            .stdin
            .take()
            .ok_or_else(|| io::Error::new(io::ErrorKind::BrokenPipe, "file helper stdin"))?;
        let stdout = child
            .stdout
            .take()
            .ok_or_else(|| io::Error::new(io::ErrorKind::BrokenPipe, "file helper stdout"))?;
        Ok(RemoteFileHelper {
            child,
            stdin,
            stdout,
        })
    }

    fn start_db_helper(&self) -> io::Result<RemoteDbHelper> {
        let probe = &self.manifest.probe;
        let mut remote_command = format!("php -r {} --", shell_quote(remote_db_helper_php()));
        for arg in [
            probe.db_host.clone(),
            probe.db_user.clone(),
            probe.db_password.clone(),
            probe.db_name.clone(),
            remote_db_query_timeout_secs().to_string(),
        ] {
            remote_command.push(' ');
            remote_command.push_str(&shell_quote(arg));
        }
        let mut command = self.ssh_command(&remote_command, 0);
        let mut child = command
            .stdin(Stdio::piped())
            .stdout(Stdio::piped())
            .stderr(Stdio::null())
            .spawn()?;
        let stdin = child
            .stdin
            .take()
            .ok_or_else(|| io::Error::new(io::ErrorKind::BrokenPipe, "DB helper stdin"))?;
        let stdout = child
            .stdout
            .take()
            .ok_or_else(|| io::Error::new(io::ErrorKind::BrokenPipe, "DB helper stdout"))?;
        Ok(RemoteDbHelper {
            child,
            stdin,
            stdout,
        })
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

#[derive(Debug)]
struct RemoteFileHelper {
    child: Child,
    stdin: ChildStdin,
    stdout: ChildStdout,
}

#[derive(Debug)]
struct RemoteDbHelper {
    child: Child,
    stdin: ChildStdin,
    stdout: ChildStdout,
}

impl Drop for RemoteFileHelper {
    fn drop(&mut self) {
        let _ = self.child.kill();
        let _ = self.child.wait();
    }
}

impl Drop for RemoteDbHelper {
    fn drop(&mut self) {
        let _ = self.child.kill();
        let _ = self.child.wait();
    }
}

fn reset_file_helper(helper: &mut Option<RemoteFileHelper>) {
    if let Some(mut helper) = helper.take() {
        let _ = helper.child.kill();
        let _ = helper.child.wait();
    }
}

fn reset_db_helper(helper: &mut Option<RemoteDbHelper>) {
    if let Some(mut helper) = helper.take() {
        let _ = helper.child.kill();
        let _ = helper.child.wait();
    }
}

fn decode_helper_data(response: serde_json::Value) -> io::Result<Vec<u8>> {
    let data = response
        .get("data")
        .and_then(|value| value.as_str())
        .ok_or_else(|| {
            io::Error::new(io::ErrorKind::InvalidData, "helper response missing data")
        })?;
    BASE64
        .decode(data)
        .map_err(|err| io::Error::new(io::ErrorKind::InvalidData, err))
}

fn read_helper_line(
    stdout: &mut ChildStdout,
    timeout: Duration,
    label: &str,
) -> io::Result<String> {
    let deadline = Instant::now() + timeout;
    let fd = stdout.as_raw_fd();
    let mut out = Vec::new();

    loop {
        let now = Instant::now();
        if now >= deadline {
            return Err(io::Error::new(
                io::ErrorKind::TimedOut,
                format!(
                    "{} did not respond within {} seconds",
                    label,
                    timeout.as_secs()
                ),
            ));
        }

        let remaining = deadline.saturating_duration_since(now);
        let timeout_ms = remaining.as_millis().min(i32::MAX as u128) as i32;
        let mut fd_set = libc::pollfd {
            fd,
            events: libc::POLLIN,
            revents: 0,
        };
        let ready = unsafe { libc::poll(&mut fd_set, 1, timeout_ms) };
        if ready < 0 {
            return Err(io::Error::last_os_error());
        }
        if ready == 0 {
            continue;
        }
        if fd_set.revents & libc::POLLIN == 0 {
            return Err(io::Error::new(
                io::ErrorKind::BrokenPipe,
                format!("{label} pipe closed"),
            ));
        }

        let mut chunk = [0_u8; 8192];
        let read = stdout.read(&mut chunk)?;
        if read == 0 {
            return Err(io::Error::new(
                io::ErrorKind::UnexpectedEof,
                format!("{label} closed"),
            ));
        }
        out.extend_from_slice(&chunk[..read]);
        if out.last() == Some(&b'\n') || out.contains(&b'\n') {
            return String::from_utf8(out)
                .map_err(|err| io::Error::new(io::ErrorKind::InvalidData, err));
        }
    }
}

fn remote_file_helper_enabled() -> bool {
    env_bool("WPCOW_REMOTE_FILE_HELPER", true).unwrap_or(true)
}

fn remote_db_helper_enabled() -> bool {
    env_bool("WPCOW_REMOTE_DB_HELPER", true).unwrap_or(true)
}

fn is_remote_db_connection_lost(error: &str) -> bool {
    let error = error.to_ascii_lowercase();
    error.contains("server has gone away")
        || error.contains("lost connection")
        || error.contains("error while sending")
        || error.contains("connection was killed")
}

fn trace_remote_result<T, E: std::fmt::Display>(
    op: &str,
    target: &str,
    started: Instant,
    result: &std::result::Result<T, E>,
) {
    if std::env::var("WPCOW_TRACE_REMOTE").ok().as_deref() != Some("1") {
        return;
    }
    let elapsed_ms = started.elapsed().as_millis();
    match result {
        Ok(_) => eprintln!("wp-cow remote {op} ok {elapsed_ms}ms {target}"),
        Err(err) => eprintln!("wp-cow remote {op} err {elapsed_ms}ms {target}: {err}"),
    }
}

fn remote_file_helper_php() -> &'static str {
    r#"
error_reporting(0);
function wpcow_send($payload) {
 echo json_encode($payload), "\n";
 flush();
}
function wpcow_not_found() {
 wpcow_send(array("ok"=>false,"kind"=>"not_found","error"=>"WPCOW_ENOENT"));
}
while (($line = fgets(STDIN)) !== false) {
 $request = json_decode($line, true);
 if (!is_array($request)) {
  wpcow_send(array("ok"=>false,"error"=>"invalid request"));
  continue;
 }
 $op = isset($request["op"]) ? $request["op"] : "";
 $path = isset($request["path"]) ? $request["path"] : "";
 if ($op === "stat") {
  $max_file_bytes = isset($request["max_file_bytes"]) ? max(0, (int)$request["max_file_bytes"]) : 0;
  clearstatcache(true, $path);
  $s = @lstat($path);
  if ($s === false) { wpcow_not_found(); continue; }
  $kind = is_link($path) ? "symlink" : (is_dir($path) ? "dir" : (is_file($path) ? "file" : "other"));
  $entry = array(
   "name"=>basename($path),
   "kind"=>$kind,
   "size"=>(int)$s["size"],
   "mode"=>(int)$s["mode"],
   "mtime"=>(int)$s["mtime"]
  );
  $payload = array("ok"=>true,"entry"=>$entry);
  if ($max_file_bytes > 0 && $kind === "file" && (int)$s["size"] <= $max_file_bytes) {
   $data = @file_get_contents($path);
   if ($data !== false && strlen($data) === (int)$s["size"]) {
    $payload["data"] = base64_encode($data);
    $payload["size"] = strlen($data);
   }
  }
  wpcow_send($payload);
  continue;
 }
 if ($op === "readdir") {
  if (!is_dir($path)) { wpcow_not_found(); continue; }
  $out = array();
  foreach (scandir($path) as $name) {
   if ($name === "." || $name === "..") { continue; }
   $child = $path . DIRECTORY_SEPARATOR . $name;
   $s = @lstat($child);
   if ($s === false) { continue; }
   $kind = is_link($child) ? "symlink" : (is_dir($child) ? "dir" : (is_file($child) ? "file" : "other"));
   $out[] = array("name"=>$name,"kind"=>$kind,"size"=>(int)$s["size"],"mode"=>(int)$s["mode"],"mtime"=>(int)$s["mtime"]);
  }
  wpcow_send(array("ok"=>true,"entries"=>$out));
  continue;
 }
 if ($op === "prefetch_dir") {
  if (!is_dir($path)) { wpcow_not_found(); continue; }
  $max_file_bytes = isset($request["max_file_bytes"]) ? max(0, (int)$request["max_file_bytes"]) : 0;
  $max_total_bytes = isset($request["max_total_bytes"]) ? max(0, (int)$request["max_total_bytes"]) : 0;
  $total = 0;
  $out = array();
  foreach (scandir($path) as $name) {
   if ($name === "." || $name === "..") { continue; }
   $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
   if ($ext !== "php" && $ext !== "json" && $ext !== "mo") { continue; }
   $child = $path . DIRECTORY_SEPARATOR . $name;
   $s = @lstat($child);
   if ($s === false || !is_file($child)) { continue; }
   $size = (int)$s["size"];
   if ($size > $max_file_bytes || $size + $total > $max_total_bytes) { continue; }
   $data = @file_get_contents($child);
   if ($data === false || strlen($data) !== $size) { continue; }
   $total += $size;
   $out[] = array("entry"=>array(
    "name"=>$name,
    "kind"=>"file",
    "size"=>$size,
    "mode"=>(int)$s["mode"],
    "mtime"=>(int)$s["mtime"]
   ),"data"=>base64_encode($data));
  }
  wpcow_send(array("ok"=>true,"files"=>$out,"bytes"=>$total));
  continue;
 }
 if ($op === "read_file") {
  if (!is_file($path)) { wpcow_not_found(); continue; }
  $data = @file_get_contents($path);
  if ($data === false) { wpcow_not_found(); continue; }
  wpcow_send(array("ok"=>true,"data"=>base64_encode($data),"size"=>strlen($data)));
  continue;
 }
 if ($op === "read_range") {
  $offset = isset($request["offset"]) ? max(0, (int)$request["offset"]) : 0;
  $length = isset($request["length"]) ? max(0, (int)$request["length"]) : 0;
  $f = @fopen($path, "rb");
  if (!$f) { wpcow_not_found(); continue; }
  if ($offset > 0) { @fseek($f, $offset); }
  $data = $length > 0 ? fread($f, $length) : "";
  if ($data === false) { $data = ""; }
  wpcow_send(array("ok"=>true,"data"=>base64_encode($data),"size"=>strlen($data)));
  continue;
 }
 if ($op === "readlink") {
  $target = @readlink($path);
  if ($target === false) { wpcow_not_found(); continue; }
  wpcow_send(array("ok"=>true,"target"=>$target));
  continue;
 }
 wpcow_send(array("ok"=>false,"error"=>"unknown op"));
}
"#
}

fn remote_db_helper_php() -> &'static str {
    r#"
error_reporting(0);
$host=$argv[1];$user=$argv[2];$pass=$argv[3];$db=$argv[4];$timeout=(int)$argv[5];
if($timeout<1){$timeout=10;}
@set_time_limit(0);
if(function_exists("mysqli_report")){mysqli_report(MYSQLI_REPORT_OFF);}
$port=null;$socket=null;
if(preg_match('/^(.+):([0-9]+)$/',$host,$m)){
 $host=$m[1];$port=(int)$m[2];
} elseif(preg_match('/^([^:]+):(\/.*)$/',$host,$m)){
 $host=$m[1];$socket=$m[2];
}
$mysqli=mysqli_init();
@$mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, min(5,$timeout));
if(!@$mysqli->real_connect($host,$user,$pass,$db,$port,$socket)){
 echo json_encode(array("ok"=>false,"error"=>mysqli_connect_error(),"rows"=>array(),"fields"=>array(),"affected"=>0)), "\n";
 flush();
 exit(0);
}
@$mysqli->set_charset("utf8mb4");
@$mysqli->query("SET SESSION max_execution_time=".max(1,$timeout * 1000));
@$mysqli->query("SET SESSION max_statement_time=".max(1,$timeout));
while (($line = fgets(STDIN)) !== false) {
 $request = json_decode($line, true);
 if (!is_array($request) || !isset($request["sql"])) {
  echo json_encode(array("ok"=>false,"error"=>"invalid request","rows"=>array(),"fields"=>array(),"affected"=>0)), "\n";
  flush();
  continue;
 }
 $sql = $request["sql"];
 if(!preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i',$sql)){
  echo json_encode(array("ok"=>false,"error"=>"WPCOW_REFUSED_WRITE","rows"=>array(),"fields"=>array(),"affected"=>0)), "\n";
  flush();
  continue;
 }
 $res=$mysqli->query($sql, MYSQLI_STORE_RESULT);
 if($res===false){
  echo json_encode(array("ok"=>false,"error"=>$mysqli->error,"rows"=>array(),"fields"=>array(),"affected"=>0)), "\n";
  flush();
  continue;
 }
 if($res===true){
  echo json_encode(array("ok"=>true,"error"=>"","rows"=>array(),"fields"=>array(),"affected"=>$mysqli->affected_rows)), "\n";
  flush();
  continue;
 }
 $fields=array();
 foreach($res->fetch_fields() as $field){$fields[]=$field->name;}
 $rows=array();
 while($row=$res->fetch_assoc()){$rows[]=$row;}
 echo json_encode(array("ok"=>true,"error"=>"","rows"=>$rows,"fields"=>$fields,"affected"=>count($rows))), "\n";
flush();
}
"#
}

fn runtime_code_pack_php() -> &'static str {
    r#"
error_reporting(0);
$base = rtrim($argv[1], "/");
$roots = json_decode($argv[2], true);
$max_file_bytes = max(0, (int)$argv[3]);
$max_total_bytes = max(0, (int)$argv[4]);
$max_files = max(0, (int)$argv[5]);
$total = 0;
$files = 0;
$skipped = 0;
$capped = false;
if (!is_array($roots)) { $roots = array(); }
function wpcow_pack_send($payload) {
 echo json_encode($payload), "\n";
 flush();
}
function wpcow_pack_clean($rel) {
 $rel = str_replace("\\", "/", (string)$rel);
 $rel = trim($rel, "/");
 if ($rel === "") { return false; }
 $parts = array();
 foreach (explode("/", $rel) as $part) {
  if ($part === "" || $part === ".") { continue; }
  if ($part === "..") { return false; }
  $parts[] = $part;
 }
 return implode("/", $parts);
}
function wpcow_pack_allowed_ext($rel) {
 $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
 return in_array($ext, array("php", "inc", "phtml", "json", "mo"), true);
}
function wpcow_pack_excluded($rel) {
 return $rel === "wp-config.php" || strpos($rel . "/", "wp-content/uploads/") === 0;
}
function wpcow_pack_entry($path, $name, $size, $mtime) {
 $mode = 0100644;
 $stat = @lstat($path);
 if (is_array($stat)) { $mode = (int)$stat["mode"]; }
 return array("name"=>$name,"kind"=>"file","size"=>$size,"mode"=>$mode,"mtime"=>$mtime);
}
function wpcow_pack_file($rel, $path) {
 global $max_file_bytes, $max_total_bytes, $max_files, $total, $files, $skipped, $capped;
 if ($capped) { return; }
 if (wpcow_pack_excluded($rel) || !wpcow_pack_allowed_ext($rel)) { $skipped++; return; }
 clearstatcache(true, $path);
 if (!is_file($path)) { $skipped++; return; }
 $size = filesize($path);
 if ($size === false) { $skipped++; return; }
 $size = (int)$size;
 if ($size > $max_file_bytes) { $skipped++; return; }
 if ($files >= $max_files || $total + $size > $max_total_bytes) { $capped = true; return; }
 $data = @file_get_contents($path);
 if ($data === false || strlen($data) !== $size) { $skipped++; return; }
 $mtime = @filemtime($path);
 if ($mtime === false) { $mtime = 0; }
 $files++;
 $total += $size;
 wpcow_pack_send(array(
  "type"=>"file",
  "path"=>$rel,
  "entry"=>wpcow_pack_entry($path, basename($path), $size, (int)$mtime),
  "data"=>base64_encode($data)
 ));
}
function wpcow_pack_dir($rel, $path) {
 global $capped;
 $stack = array(array($rel, $path));
 while (!$capped && !empty($stack)) {
  $item = array_pop($stack);
  $dir_rel = $item[0];
  $dir_path = $item[1];
  if (wpcow_pack_excluded($dir_rel) || !is_dir($dir_path)) { continue; }
  $names = @scandir($dir_path);
  if (!is_array($names)) { continue; }
  rsort($names, SORT_STRING);
  foreach ($names as $name) {
   if ($name === "." || $name === "..") { continue; }
   $child_rel = $dir_rel === "" ? $name : $dir_rel . "/" . $name;
   $child_path = $dir_path . DIRECTORY_SEPARATOR . $name;
   if (wpcow_pack_excluded($child_rel)) { continue; }
   if (is_dir($child_path) && !is_link($child_path)) {
    $stack[] = array($child_rel, $child_path);
   } elseif (is_file($child_path)) {
    wpcow_pack_file($child_rel, $child_path);
    if ($capped) { break; }
   }
  }
 }
}
foreach ($roots as $root) {
 if ($capped) { break; }
 $rel = wpcow_pack_clean($root);
 if ($rel === false) { $skipped++; continue; }
 $path = $base . "/" . $rel;
 if (is_file($path)) {
  wpcow_pack_file($rel, $path);
 } elseif (is_dir($path)) {
  wpcow_pack_dir($rel, $path);
 } else {
  $skipped++;
 }
}
wpcow_pack_send(array("type"=>"summary","files"=>$files,"bytes"=>$total,"skipped"=>$skipped,"capped"=>$capped));
"#
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
    'home' => function_exists('get_option') ? get_option('home') : '',
    'template' => function_exists('get_template') ? get_template() : (function_exists('get_option') ? get_option('template') : ''),
    'stylesheet' => function_exists('get_stylesheet') ? get_stylesheet() : (function_exists('get_option') ? get_option('stylesheet') : ''),
    'active_plugins' => function_exists('get_option') && is_array(get_option('active_plugins')) ? array_values(get_option('active_plugins')) : array(),
    'active_sitewide_plugins' => function_exists('get_site_option') && is_array(get_site_option('active_sitewide_plugins')) ? array_keys(get_site_option('active_sitewide_plugins')) : array()
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

fn remote_file_helper_timeout_secs() -> u64 {
    env_u64(
        "WPCOW_REMOTE_FILE_HELPER_TIMEOUT_SECS",
        remote_command_timeout_secs(),
    )
}

fn remote_db_query_timeout_secs() -> u64 {
    env_u64("WPCOW_REMOTE_DB_QUERY_TIMEOUT_SECS", 10)
}

fn runtime_code_pack_timeout_secs() -> u64 {
    env_u64("WPCOW_RUNTIME_CODE_PACK_TIMEOUT_SECS", 180)
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
    use std::fs;
    use std::os::unix::fs::PermissionsExt;
    use std::path::{Path, PathBuf};
    use std::sync::{Mutex, OnceLock};

    static ENV_LOCK: OnceLock<Mutex<()>> = OnceLock::new();

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

    #[test]
    fn classifies_remote_db_connection_loss_errors() {
        assert!(is_remote_db_connection_lost("MySQL server has gone away"));
        assert!(is_remote_db_connection_lost(
            "Lost connection to MySQL server during query"
        ));
        assert!(!is_remote_db_connection_lost("Unknown column 'x'"));
    }

    #[test]
    #[ignore = "strict harness only: mutates process SSH helper env"]
    fn stat_prefetch_returns_small_file_bytes_from_helper() {
        let _guard = ENV_LOCK.get_or_init(|| Mutex::new(())).lock().unwrap();

        let old_path = std::env::var_os("PATH");
        let old_helper = std::env::var_os("WPCOW_REMOTE_FILE_HELPER");
        let old_timeout = std::env::var_os("WPCOW_REMOTE_FILE_HELPER_TIMEOUT_SECS");

        let temp = tempfile::tempdir().unwrap();
        let remote_root = temp.path().join("remote");
        let bin = temp.path().join("bin");
        fs::create_dir_all(&remote_root).unwrap();
        fs::create_dir_all(&bin).unwrap();
        fs::write(remote_root.join("index.php"), b"<?php echo 'remote';").unwrap();

        let fake_ssh = bin.join("ssh");
        fs::write(
            &fake_ssh,
            r#"#!/usr/bin/env bash
set -euo pipefail
cmd="${@: -1}"
exec bash -lc "$cmd"
"#,
        )
        .unwrap();
        let mut perms = fs::metadata(&fake_ssh).unwrap().permissions();
        perms.set_mode(0o755);
        fs::set_permissions(&fake_ssh, perms).unwrap();

        let path = match old_path.as_ref() {
            Some(old) => format!("{}:{}", bin.display(), old.to_string_lossy()),
            None => bin.display().to_string(),
        };
        std::env::set_var("PATH", path);
        std::env::set_var("WPCOW_REMOTE_FILE_HELPER", "1");
        std::env::set_var("WPCOW_REMOTE_FILE_HELPER_TIMEOUT_SECS", "5");

        let manifest = Manifest::new(
            "example".to_string(),
            "fake-host".to_string(),
            remote_root.to_string_lossy().to_string(),
            "https://example.com".to_string(),
            "http://example.test".to_string(),
            Probe {
                table_prefix: "wp_".to_string(),
                ..Probe::default()
            },
        );
        let remote = RemoteClient::new(manifest, None);

        let prefetched = remote
            .stat_prefetch(Path::new("index.php"), 1024)
            .expect("stat prefetch");
        assert_eq!(prefetched.entry.size, 20);
        assert_eq!(
            prefetched.data.as_deref(),
            Some(&b"<?php echo 'remote';"[..])
        );

        let metadata_only = remote
            .stat_prefetch(Path::new("index.php"), 4)
            .expect("stat without prefetch");
        assert_eq!(metadata_only.entry.size, 20);
        assert!(
            metadata_only.data.is_none(),
            "files above the stat-prefetch limit should remain read-through"
        );

        match old_path {
            Some(value) => std::env::set_var("PATH", value),
            None => std::env::remove_var("PATH"),
        }
        match old_helper {
            Some(value) => std::env::set_var("WPCOW_REMOTE_FILE_HELPER", value),
            None => std::env::remove_var("WPCOW_REMOTE_FILE_HELPER"),
        }
        match old_timeout {
            Some(value) => std::env::set_var("WPCOW_REMOTE_FILE_HELPER_TIMEOUT_SECS", value),
            None => std::env::remove_var("WPCOW_REMOTE_FILE_HELPER_TIMEOUT_SECS"),
        }
    }

    #[test]
    #[ignore = "strict harness only: mutates process SSH helper env"]
    fn prefetch_dir_batches_only_runtime_file_types() {
        let _guard = ENV_LOCK.get_or_init(|| Mutex::new(())).lock().unwrap();

        let old_path = std::env::var_os("PATH");
        let old_helper = std::env::var_os("WPCOW_REMOTE_FILE_HELPER");
        let old_timeout = std::env::var_os("WPCOW_REMOTE_FILE_HELPER_TIMEOUT_SECS");

        let temp = tempfile::tempdir().unwrap();
        let remote_root = temp.path().join("remote");
        let runtime_dir = remote_root.join("wp-content/plugins/example/includes");
        let bin = temp.path().join("bin");
        fs::create_dir_all(&runtime_dir).unwrap();
        fs::create_dir_all(&bin).unwrap();
        fs::write(runtime_dir.join("a.php"), b"<?php // a").unwrap();
        fs::write(runtime_dir.join("b.json"), b"{\"ok\":true}").unwrap();
        fs::write(runtime_dir.join("style.css"), b"body{}").unwrap();

        let fake_ssh = bin.join("ssh");
        fs::write(
            &fake_ssh,
            r#"#!/usr/bin/env bash
set -euo pipefail
cmd="${@: -1}"
exec bash -lc "$cmd"
"#,
        )
        .unwrap();
        let mut perms = fs::metadata(&fake_ssh).unwrap().permissions();
        perms.set_mode(0o755);
        fs::set_permissions(&fake_ssh, perms).unwrap();

        let path = match old_path.as_ref() {
            Some(old) => format!("{}:{}", bin.display(), old.to_string_lossy()),
            None => bin.display().to_string(),
        };
        std::env::set_var("PATH", path);
        std::env::set_var("WPCOW_REMOTE_FILE_HELPER", "1");
        std::env::set_var("WPCOW_REMOTE_FILE_HELPER_TIMEOUT_SECS", "5");

        let manifest = Manifest::new(
            "example".to_string(),
            "fake-host".to_string(),
            remote_root.to_string_lossy().to_string(),
            "https://example.com".to_string(),
            "http://example.test".to_string(),
            Probe {
                table_prefix: "wp_".to_string(),
                ..Probe::default()
            },
        );
        let remote = RemoteClient::new(manifest, None);
        let files = remote
            .prefetch_dir(Path::new("wp-content/plugins/example/includes"), 1024, 4096)
            .expect("prefetch dir");
        let names = files
            .iter()
            .map(|stat| stat.entry.name.as_str())
            .collect::<Vec<_>>();
        assert!(names.contains(&"a.php"));
        assert!(names.contains(&"b.json"));
        assert!(!names.contains(&"style.css"));
        assert_eq!(files.iter().filter(|stat| stat.data.is_some()).count(), 2);

        match old_path {
            Some(value) => std::env::set_var("PATH", value),
            None => std::env::remove_var("PATH"),
        }
        match old_helper {
            Some(value) => std::env::set_var("WPCOW_REMOTE_FILE_HELPER", value),
            None => std::env::remove_var("WPCOW_REMOTE_FILE_HELPER"),
        }
        match old_timeout {
            Some(value) => std::env::set_var("WPCOW_REMOTE_FILE_HELPER_TIMEOUT_SECS", value),
            None => std::env::remove_var("WPCOW_REMOTE_FILE_HELPER_TIMEOUT_SECS"),
        }
    }

    #[test]
    #[ignore = "strict harness only: mutates process SSH helper env"]
    fn runtime_code_pack_streams_bounded_runtime_files() {
        let _guard = ENV_LOCK.get_or_init(|| Mutex::new(())).lock().unwrap();

        let old_path = std::env::var_os("PATH");
        let old_timeout = std::env::var_os("WPCOW_RUNTIME_CODE_PACK_TIMEOUT_SECS");

        let temp = tempfile::tempdir().unwrap();
        let remote_root = temp.path().join("remote");
        let bin = temp.path().join("bin");
        fs::create_dir_all(remote_root.join("wp-includes")).unwrap();
        fs::create_dir_all(remote_root.join("wp-content/uploads/2026/05")).unwrap();
        fs::create_dir_all(remote_root.join("wp-content/plugins/example/assets")).unwrap();
        fs::create_dir_all(&bin).unwrap();
        fs::write(remote_root.join("index.php"), b"<?php echo 'index';").unwrap();
        fs::write(remote_root.join("wp-config.php"), b"<?php // prod creds").unwrap();
        fs::write(remote_root.join("wp-includes/load.php"), b"<?php // load").unwrap();
        fs::write(remote_root.join("wp-includes/blocks.json"), b"{}").unwrap();
        fs::write(
            remote_root.join("wp-content/uploads/2026/05/huge.php"),
            b"<?php // not runtime",
        )
        .unwrap();
        fs::write(
            remote_root.join("wp-content/plugins/example/example.php"),
            b"<?php // plugin",
        )
        .unwrap();
        fs::write(
            remote_root.join("wp-content/plugins/example/assets/style.css"),
            b"body{}",
        )
        .unwrap();

        let fake_ssh = bin.join("ssh");
        fs::write(
            &fake_ssh,
            r#"#!/usr/bin/env bash
set -euo pipefail
cmd="${@: -1}"
exec bash -lc "$cmd"
"#,
        )
        .unwrap();
        let mut perms = fs::metadata(&fake_ssh).unwrap().permissions();
        perms.set_mode(0o755);
        fs::set_permissions(&fake_ssh, perms).unwrap();

        let path = match old_path.as_ref() {
            Some(old) => format!("{}:{}", bin.display(), old.to_string_lossy()),
            None => bin.display().to_string(),
        };
        std::env::set_var("PATH", path);
        std::env::set_var("WPCOW_RUNTIME_CODE_PACK_TIMEOUT_SECS", "10");

        let manifest = Manifest::new(
            "example".to_string(),
            "fake-host".to_string(),
            remote_root.to_string_lossy().to_string(),
            "https://example.com".to_string(),
            "http://example.test".to_string(),
            Probe {
                table_prefix: "wp_".to_string(),
                ..Probe::default()
            },
        );
        let remote = RemoteClient::new(manifest, None);
        let mut files = Vec::new();
        let summary = remote
            .runtime_code_pack(
                &[
                    PathBuf::from("index.php"),
                    PathBuf::from("wp-config.php"),
                    PathBuf::from("wp-includes"),
                    PathBuf::from("wp-content/plugins/example"),
                    PathBuf::from("wp-content/uploads"),
                ],
                RuntimeCodePackLimits {
                    max_file_bytes: 1024,
                    max_total_bytes: 8192,
                    max_files: 100,
                },
                |file| {
                    files.push((file.rel, file.entry.name, file.bytes));
                    Ok(())
                },
            )
            .expect("runtime code pack");

        let paths = files
            .iter()
            .map(|(rel, _, _)| rel.to_string_lossy().to_string())
            .collect::<Vec<_>>();
        assert!(paths.contains(&"index.php".to_string()));
        assert!(paths.contains(&"wp-includes/load.php".to_string()));
        assert!(paths.contains(&"wp-includes/blocks.json".to_string()));
        assert!(paths.contains(&"wp-content/plugins/example/example.php".to_string()));
        assert!(!paths.contains(&"wp-config.php".to_string()));
        assert!(!paths
            .iter()
            .any(|path| path.starts_with("wp-content/uploads/")));
        assert!(!paths.iter().any(|path| path.ends_with(".css")));
        assert_eq!(summary.files as usize, files.len());

        match old_path {
            Some(value) => std::env::set_var("PATH", value),
            None => std::env::remove_var("PATH"),
        }
        match old_timeout {
            Some(value) => std::env::set_var("WPCOW_RUNTIME_CODE_PACK_TIMEOUT_SECS", value),
            None => std::env::remove_var("WPCOW_RUNTIME_CODE_PACK_TIMEOUT_SECS"),
        }
    }
}

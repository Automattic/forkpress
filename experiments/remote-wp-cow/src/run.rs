use anyhow::{anyhow, Context, Result};
use std::collections::BTreeSet;
use std::fs;
use std::io::{Read, Write};
use std::path::{Path, PathBuf};
use std::process::{Child, Command, Stdio};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::thread::{self, JoinHandle};
use std::time::{Duration, SystemTime, UNIX_EPOCH};

use crate::config::{self, ClonePaths, Manifest};
use crate::control;
use crate::db;
use crate::fusefs;
use crate::generate::ROUTER_BASENAME;
use crate::mysql_proxy;
use crate::remote::{shell_quote, RemoteClient};

pub struct RunOptions {
    pub mountpoint: PathBuf,
    pub http_addr: String,
    pub skip_php: bool,
}

pub fn run_site(manifest: Manifest, paths: ClonePaths, options: RunOptions) -> Result<()> {
    let shutdown = Arc::new(AtomicBool::new(false));
    install_signal_handler(shutdown.clone())?;

    let control_addr = control_addr_from_url(&manifest.control_url)?;
    let remote = RemoteClient::new(manifest.clone(), Some(paths.run.join("ssh-control.sock")));
    let offline = config::is_offline(&paths);
    let mut db_tunnel = if offline {
        eprintln!(
            "wp-cow clone '{}' is severed; remote filesystem and DB reads are disabled",
            manifest.name
        );
        None
    } else {
        remote.ensure_master()?;
        match remote.start_db_tunnel() {
            Ok(Some(child)) => {
                eprintln!(
                    "wp-cow remote DB tunnel listening at {}:{}",
                    manifest.remote_db_tunnel.host, manifest.remote_db_tunnel.port
                );
                Some(child)
            }
            Ok(None) => {
                eprintln!(
                    "wp-cow remote DB tunnel disabled or unavailable; falling back to control reads"
                );
                None
            }
            Err(err) => {
                eprintln!("wp-cow remote DB tunnel failed: {err:#}");
                eprintln!("wp-cow falling back to control reads");
                None
            }
        }
    };

    let control_shutdown = shutdown.clone();
    let control_manifest = manifest.clone();
    let control_paths = paths.clone();
    let control_remote = remote.clone();
    let control_thread = thread::spawn(move || {
        control::serve_control(
            &control_addr,
            control_manifest,
            control_paths,
            control_remote,
            control_shutdown,
        )
    });

    let proxy_addr = format!("{}:{}", manifest.db_proxy.host, manifest.db_proxy.port);
    let proxy_shutdown = shutdown.clone();
    let proxy_manifest = manifest.clone();
    let proxy_paths = paths.clone();
    let proxy_remote = remote.clone();
    let proxy_thread = thread::spawn(move || {
        mysql_proxy::serve_proxy(
            &proxy_addr,
            proxy_manifest,
            proxy_paths,
            proxy_remote,
            proxy_shutdown,
        )
    });

    let mount_manifest = manifest.clone();
    let mount_paths = paths.clone();
    let mountpoint = options.mountpoint.clone();
    let mount_thread =
        thread::spawn(move || fusefs::mount_foreground(mount_manifest, mount_paths, &mountpoint));

    if let Err(wait_err) = wait_for_mount(&options.mountpoint, &mount_thread) {
        shutdown.store(true, Ordering::SeqCst);
        if mount_thread.is_finished() {
            match mount_thread.join() {
                Ok(Err(mount_err)) => return Err(mount_err).with_context(|| wait_err.to_string()),
                Ok(Ok(())) => return Err(wait_err),
                Err(_) => return Err(anyhow!("mount thread panicked")).context(wait_err),
            }
        }
        let _ = unmount(&options.mountpoint);
        return Err(wait_err);
    }

    let mut web = if options.skip_php {
        None
    } else {
        Some(start_web_server(
            &paths,
            &options.mountpoint,
            &options.http_addr,
        )?)
    };

    if !offline && env_bool("WPCOW_PREFETCH_RUNTIME", false) {
        let warm_manifest = manifest.clone();
        let warm_paths = paths.clone();
        let warm_remote = remote.clone();
        thread::spawn(move || {
            if let Err(err) = prefetch_runtime_files(&warm_manifest, &warm_paths, &warm_remote) {
                eprintln!("wp-cow runtime prefetch skipped: {err:#}");
            }
        });
    }

    eprintln!(
        "wp-cow running clone '{}' at {} from {}",
        manifest.name,
        options.http_addr,
        options.mountpoint.display()
    );

    while !shutdown.load(Ordering::SeqCst) {
        if let Some(child) = web.as_mut() {
            if let Some(status) = child.try_wait()? {
                shutdown.store(true, Ordering::SeqCst);
                return Err(anyhow!("web server exited with status {}", status));
            }
        }
        thread::sleep(Duration::from_millis(250));
    }

    if let Some(mut child) = web {
        let _ = child.kill();
        let _ = child.wait();
    }
    if let Some(child) = db_tunnel.as_mut() {
        let _ = child.kill();
        let _ = child.wait();
    }
    let _ = unmount(&options.mountpoint);

    match control_thread.join() {
        Ok(result) => result?,
        Err(_) => return Err(anyhow!("control thread panicked")),
    }

    match proxy_thread.join() {
        Ok(result) => result?,
        Err(_) => return Err(anyhow!("MySQL proxy thread panicked")),
    }

    match mount_thread.join() {
        Ok(result) => {
            if let Err(err) = result {
                eprintln!("wp-cow mount stopped: {err:#}");
            }
        }
        Err(_) => return Err(anyhow!("mount thread panicked")),
    }

    Ok(())
}

pub fn mount_only(manifest: Manifest, paths: ClonePaths, mountpoint: &Path) -> Result<()> {
    fusefs::mount_foreground(manifest, paths, mountpoint)
}

pub(crate) fn prefetch_runtime_files(
    manifest: &Manifest,
    paths: &ClonePaths,
    remote: &RemoteClient,
) -> Result<()> {
    let mirror = paths.file_cache.join("mirror");
    fs::create_dir_all(&mirror)?;
    let stamp = mirror.join(".wp-cow-runtime-prefetch-v3");
    if stamp.is_file() {
        return Ok(());
    }

    let mut rels = [
        "index.php",
        "wp-activate.php",
        "wp-blog-header.php",
        "wp-comments-post.php",
        "wp-cron.php",
        "wp-load.php",
        "wp-login.php",
        "wp-mail.php",
        "wp-settings.php",
        "wp-signup.php",
        "wp-trackback.php",
        "xmlrpc.php",
        "wp-admin",
        "wp-includes",
    ]
    .into_iter()
    .map(|rel| rel.to_string())
    .collect::<Vec<_>>();
    let mut themes = BTreeSet::new();
    for option in ["template", "stylesheet"] {
        if let Some(theme) = db::local_option_value(manifest, option)? {
            if let Some(theme) = clean_theme_name(&theme) {
                themes.insert(theme);
            }
        }
    }
    if themes.is_empty() {
        for theme in remote_active_theme_names(manifest, remote)? {
            themes.insert(theme);
        }
    }
    for theme in themes {
        rels.push(format!("wp-content/themes/{theme}"));
    }

    eprintln!(
        "wp-cow warming runtime file cache in background: {}",
        rels.join(", ")
    );
    let _ = write_prefetch_progress(paths, "prefetching-runtime", &rels.join(", "), 0, 0, 0);
    let remote_paths = rels.iter().map(shell_quote).collect::<Vec<_>>().join(" ");
    let remote_command = format!(
        "cd {} && tar -cf - --ignore-failed-read {}",
        shell_quote(&manifest.remote_path),
        remote_paths
    );
    let mut ssh = remote
        .command(&remote_command)
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .context("start remote theme tar")?;
    let mut tar = Command::new("tar")
        .arg("-C")
        .arg(&mirror)
        .arg("-xf")
        .arg("-")
        .stdin(Stdio::piped())
        .spawn()
        .context("start local theme tar")?;

    {
        let mut ssh_stdout = ssh.stdout.take().expect("ssh stdout piped");
        let mut tar_stdin = tar.stdin.take().expect("tar stdin piped");
        let mut buf = [0_u8; 64 * 1024];
        let mut bytes = 0_u64;
        loop {
            let read = ssh_stdout.read(&mut buf)?;
            if read == 0 {
                break;
            }
            tar_stdin.write_all(&buf[..read])?;
            bytes = bytes.saturating_add(read as u64);
            if bytes == read as u64 || bytes % (1024 * 1024) < read as u64 {
                let _ = write_prefetch_progress(
                    paths,
                    "prefetching-runtime",
                    &rels.join(", "),
                    bytes,
                    0,
                    bytes,
                );
            }
        }
    }

    let ssh_output = ssh.wait_with_output()?;
    let tar_status = tar.wait()?;
    if !ssh_output.status.success() {
        return Err(anyhow!(
            "remote theme tar failed: {}",
            String::from_utf8_lossy(&ssh_output.stderr)
        ));
    }
    if !tar_status.success() {
        return Err(anyhow!("local theme tar failed with status {}", tar_status));
    }
    fs::write(&stamp, b"ok\n")?;
    let _ = write_prefetch_progress(paths, "cached", "", 0, 0, 0);
    Ok(())
}

fn remote_active_theme_names(
    manifest: &Manifest,
    remote: &RemoteClient,
) -> Result<BTreeSet<String>> {
    let mut out = BTreeSet::new();
    let Some(table) = safe_mysql_identifier(&format!("{}options", manifest.probe.table_prefix))
    else {
        return Ok(out);
    };
    let sql = format!(
        "SELECT option_name, option_value FROM `{table}` WHERE option_name IN ('template','stylesheet')"
    );
    let result = db::remote_readonly_query(remote, &sql)?;
    if !result.ok {
        return Ok(out);
    }
    for row in result.rows {
        let Some(value) = row.get("option_value").and_then(|value| value.as_str()) else {
            continue;
        };
        if let Some(theme) = clean_theme_name(value) {
            out.insert(theme);
        }
    }
    Ok(out)
}

fn safe_mysql_identifier(value: &str) -> Option<String> {
    if value.is_empty()
        || !value
            .chars()
            .all(|ch| ch.is_ascii_alphanumeric() || ch == '_' || ch == '$')
    {
        return None;
    }
    Some(value.to_string())
}

fn write_prefetch_progress(
    paths: &ClonePaths,
    phase: &str,
    active_path: &str,
    active_bytes: u64,
    active_total: u64,
    bytes_cached: u64,
) -> Result<()> {
    fs::create_dir_all(&paths.file_cache)?;
    let progress = serde_json::json!({
        "phase": phase,
        "active_path": active_path,
        "active_bytes": active_bytes,
        "active_total": active_total,
        "files_cached": 0,
        "bytes_cached": bytes_cached,
        "last_cached_path": active_path,
        "updated_at_unix_ms": now_unix_ms(),
    });
    let progress_path = paths.file_cache.join("progress.json");
    let tmp = paths
        .file_cache
        .join(format!("progress.json.prefetch.{}.tmp", std::process::id()));
    fs::write(&tmp, serde_json::to_vec_pretty(&progress)?)?;
    fs::rename(tmp, progress_path)?;
    Ok(())
}

fn clean_theme_name(value: &str) -> Option<String> {
    let value = value.trim();
    if value.is_empty()
        || !value
            .chars()
            .all(|ch| ch.is_ascii_alphanumeric() || ch == '_' || ch == '-' || ch == '.')
    {
        return None;
    }
    Some(value.to_string())
}

fn start_web_server(paths: &ClonePaths, mountpoint: &Path, http_addr: &str) -> Result<Child> {
    match std::env::var("WPCOW_WEB_SERVER")
        .unwrap_or_else(|_| "frankenphp".to_string())
        .to_ascii_lowercase()
        .as_str()
    {
        "php" | "php-dev" | "php-dev-server" => start_php_dev_server(paths, mountpoint, http_addr),
        "frankenphp" => start_frankenphp_server(paths, mountpoint, http_addr),
        other => Err(anyhow!(
            "unsupported WPCOW_WEB_SERVER={other}; expected frankenphp or php"
        )),
    }
}

fn start_frankenphp_server(
    paths: &ClonePaths,
    mountpoint: &Path,
    http_addr: &str,
) -> Result<Child> {
    let caddyfile = paths.run.join("Caddyfile");
    fs::create_dir_all(&paths.run)?;
    fs::write(
        &caddyfile,
        frankenphp_caddyfile(paths, mountpoint, http_addr),
    )?;
    Command::new(std::env::var("WPCOW_FRANKENPHP_BIN").unwrap_or_else(|_| "frankenphp".to_string()))
        .arg("run")
        .arg("--config")
        .arg(&caddyfile)
        .arg("--adapter")
        .arg("caddyfile")
        .stdin(Stdio::null())
        .spawn()
        .context("start FrankenPHP server")
}

fn start_php_dev_server(paths: &ClonePaths, mountpoint: &Path, http_addr: &str) -> Result<Child> {
    Command::new("php")
        .env(
            "PHP_CLI_SERVER_WORKERS",
            env_u64("WPCOW_PHP_WORKERS", 4).to_string(),
        )
        .arg("-d")
        .arg(format!(
            "max_execution_time={}",
            env_u64("WPCOW_PHP_MAX_EXECUTION_SECS", 90)
        ))
        .arg("-d")
        .arg(format!(
            "default_socket_timeout={}",
            env_u64("WPCOW_PHP_SOCKET_TIMEOUT_SECS", 15)
        ))
        .arg("-d")
        .arg(format!(
            "mysqlnd.net_read_timeout={}",
            env_u64("WPCOW_PHP_SOCKET_TIMEOUT_SECS", 15)
        ))
        .arg("-S")
        .arg(http_addr)
        .arg("-t")
        .arg(mountpoint)
        .arg(paths.generated.join("router.php"))
        .stdin(Stdio::null())
        .spawn()
        .context("start php built-in server")
}

fn frankenphp_caddyfile(_paths: &ClonePaths, mountpoint: &Path, http_addr: &str) -> String {
    let threads = env_u64("WPCOW_PHP_WORKERS", 4);
    let max_execution = env_u64("WPCOW_PHP_MAX_EXECUTION_SECS", 90);
    let socket_timeout = env_u64("WPCOW_PHP_SOCKET_TIMEOUT_SECS", 15);
    let listen = caddy_listen(http_addr);
    let root = caddy_quote(&mountpoint.to_string_lossy());
    let router = format!("/{ROUTER_BASENAME}");
    let bind = listen
        .bind
        .as_ref()
        .map(|host| format!("\n\tbind {}", caddy_quote(host)))
        .unwrap_or_default();
    format!(
        r#"{{
	admin off
	auto_https off
	frankenphp {{
		num_threads {threads}
		max_threads {threads}
		php_ini max_execution_time {max_execution}
		php_ini default_socket_timeout {socket_timeout}
		php_ini mysqlnd.net_read_timeout {socket_timeout}
		php_ini opcache.enable 1
		php_ini opcache.memory_consumption 192
		php_ini opcache.max_accelerated_files 20000
		php_ini opcache.validate_timestamps 1
		php_ini opcache.revalidate_freq 2
	}}
}}

{site_addr} {{{bind}
	root * {root}

	@wpCowRouter path {router}
	handle @wpCowRouter {{
		respond 404
	}}

	@wpAdminIndex path /wp-admin /wp-admin/
	handle @wpAdminIndex {{
		rewrite * /wp-admin/index.php
		php
	}}

	@static {{
		file
		not path *.php
	}}
	handle @static {{
		file_server
	}}

	@phpFiles path *.php
	handle @phpFiles {{
		php
	}}

	handle {{
		rewrite * {router}
		php
	}}
}}
"#,
        site_addr = listen.site_addr,
    )
}

struct CaddyListen {
    site_addr: String,
    bind: Option<String>,
}

fn caddy_listen(http_addr: &str) -> CaddyListen {
    let without_scheme = http_addr
        .strip_prefix("http://")
        .or_else(|| http_addr.strip_prefix("https://"))
        .unwrap_or(http_addr);
    let authority = without_scheme
        .split('/')
        .next()
        .unwrap_or(without_scheme)
        .trim();
    let (host, port) = split_host_port(authority);
    let port = port.unwrap_or("80");
    let bind = match host {
        Some(host) if !matches!(host, "" | "0.0.0.0" | "*" | "::" | "[::]") => {
            Some(host.trim_matches(['[', ']']).to_string())
        }
        _ => None,
    };
    CaddyListen {
        site_addr: format!("http://:{port}"),
        bind,
    }
}

fn split_host_port(authority: &str) -> (Option<&str>, Option<&str>) {
    if let Some(rest) = authority.strip_prefix('[') {
        if let Some((host, tail)) = rest.split_once(']') {
            return (
                Some(host),
                tail.strip_prefix(':').filter(|value| !value.is_empty()),
            );
        }
    }
    if let Some((host, port)) = authority.rsplit_once(':') {
        return (Some(host), (!port.is_empty()).then_some(port));
    }
    (Some(authority), None)
}

fn caddy_quote(value: &str) -> String {
    format!("\"{}\"", value.replace('\\', "\\\\").replace('"', "\\\""))
}

fn env_u64(name: &str, default: u64) -> u64 {
    std::env::var(name)
        .ok()
        .and_then(|raw| raw.parse::<u64>().ok())
        .unwrap_or(default)
}

fn now_unix_ms() -> u128 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|duration| duration.as_millis())
        .unwrap_or_default()
}

fn env_bool(name: &str, default: bool) -> bool {
    std::env::var(name)
        .ok()
        .map(|raw| {
            matches!(
                raw.to_ascii_lowercase().as_str(),
                "1" | "true" | "yes" | "on"
            )
        })
        .unwrap_or(default)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn caddy_listen_accepts_localhost_host_headers() {
        let listen = caddy_listen("127.0.0.1:9481");
        assert_eq!(listen.site_addr, "http://:9481");
        assert_eq!(listen.bind.as_deref(), Some("127.0.0.1"));

        let listen = caddy_listen("0.0.0.0:8080");
        assert_eq!(listen.site_addr, "http://:8080");
        assert_eq!(listen.bind, None);
    }

    #[test]
    fn frankenphp_routes_wp_admin_directory_to_index() {
        let temp = tempfile::tempdir().unwrap();
        let paths = crate::config::clone_paths(temp.path(), "example");
        let caddyfile = frankenphp_caddyfile(&paths, Path::new("/tmp/mount"), "127.0.0.1:9481");
        assert!(caddyfile.contains("@wpAdminIndex path /wp-admin /wp-admin/"));
        assert!(caddyfile.contains("rewrite * /wp-admin/index.php"));
    }
}

fn wait_for_mount(mountpoint: &Path, mount_thread: &JoinHandle<Result<()>>) -> Result<()> {
    for _ in 0..100 {
        if mountpoint.join("wp-config.php").exists() {
            return Ok(());
        }
        if mount_thread.is_finished() {
            return Err(anyhow!(
                "FUSE mount exited before generated WordPress files became visible at {}",
                mountpoint.display()
            ));
        }
        thread::sleep(Duration::from_millis(200));
    }
    Err(anyhow!(
        "timed out waiting for FUSE mount at {}",
        mountpoint.display()
    ))
}

fn control_addr_from_url(url: &str) -> Result<String> {
    let parsed = url::Url::parse(url)?;
    let host = parsed
        .host_str()
        .ok_or_else(|| anyhow!("control URL missing host"))?;
    let port = parsed
        .port()
        .ok_or_else(|| anyhow!("control URL missing port"))?;
    Ok(format!("{}:{}", host, port))
}

fn install_signal_handler(shutdown: Arc<AtomicBool>) -> Result<()> {
    ctrlc::set_handler(move || {
        shutdown.store(true, Ordering::SeqCst);
    })
    .context("install Ctrl-C handler")
}

fn unmount(mountpoint: &Path) -> Result<()> {
    let status = Command::new("fusermount3")
        .arg("-u")
        .arg(mountpoint)
        .status()
        .or_else(|_| {
            Command::new("fusermount")
                .arg("-u")
                .arg(mountpoint)
                .status()
        })
        .context("run fusermount")?;
    if !status.success() {
        return Err(anyhow!("fusermount failed with status {}", status));
    }
    Ok(())
}

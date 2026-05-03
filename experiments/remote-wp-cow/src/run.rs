use anyhow::{anyhow, Context, Result};
use std::fs;
use std::os::unix::fs::PermissionsExt;
use std::path::{Path, PathBuf};
use std::process::{Child, Command, Stdio};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::thread::{self, JoinHandle};
use std::time::Duration;

use crate::config::{self, ClonePaths, Manifest};
use crate::control;
use crate::fusefs;
use crate::generate::ROUTER_BASENAME;
use crate::mysql_proxy;
use crate::remote::RemoteClient;

pub struct RunOptions {
    pub mountpoint: PathBuf,
    pub http_addr: String,
    pub skip_php: bool,
}

pub fn run_site(manifest: Manifest, paths: ClonePaths, options: RunOptions) -> Result<()> {
    let shutdown = Arc::new(AtomicBool::new(false));
    install_signal_handler(shutdown.clone())?;
    run_site_until_shutdown(manifest, paths, options, shutdown)
}

#[cfg(test)]
pub(crate) fn run_site_with_shutdown(
    manifest: Manifest,
    paths: ClonePaths,
    options: RunOptions,
    shutdown: Arc<AtomicBool>,
) -> Result<()> {
    run_site_until_shutdown(manifest, paths, options, shutdown)
}

fn run_site_until_shutdown(
    manifest: Manifest,
    paths: ClonePaths,
    options: RunOptions,
    shutdown: Arc<AtomicBool>,
) -> Result<()> {
    let control_addr = control_addr_from_url(&manifest.control_url)?;
    let remote = RemoteClient::new(manifest.clone(), Some(config::ssh_control_path(&paths)));
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

fn start_web_server(paths: &ClonePaths, mountpoint: &Path, http_addr: &str) -> Result<Child> {
    match std::env::var("WPCOW_WEB_SERVER")
        .unwrap_or_else(|_| "auto".to_string())
        .to_ascii_lowercase()
        .as_str()
    {
        "php" | "php-dev" | "php-dev-server" => start_php_dev_server(paths, mountpoint, http_addr),
        "auto" | "frankenphp" => {
            let bin = frankenphp_bin();
            if command_exists(&bin) {
                start_frankenphp_server(paths, mountpoint, http_addr)
            } else {
                eprintln!("wp-cow FrankenPHP binary '{bin}' was not found; falling back to PHP's development server");
                start_php_dev_server(paths, mountpoint, http_addr)
            }
        }
        other => Err(anyhow!(
            "unsupported WPCOW_WEB_SERVER={other}; expected auto, frankenphp, or php"
        )),
    }
}

fn frankenphp_bin() -> String {
    std::env::var("WPCOW_FRANKENPHP_BIN").unwrap_or_else(|_| "frankenphp".to_string())
}

fn command_exists(bin: &str) -> bool {
    let path = Path::new(bin);
    if path.components().count() > 1 {
        return is_executable_file(path);
    }

    std::env::var_os("PATH")
        .map(|paths| std::env::split_paths(&paths).any(|dir| is_executable_file(&dir.join(bin))))
        .unwrap_or(false)
}

fn is_executable_file(path: &Path) -> bool {
    fs::metadata(path)
        .map(|metadata| metadata.is_file() && metadata.permissions().mode() & 0o111 != 0)
        .unwrap_or(false)
}

fn apply_web_server_env(command: &mut Command, paths: &ClonePaths) {
    if config::is_offline(paths) {
        command
            .env("WPCOW_OFFLINE", "1")
            .env("WPCOW_REMOTE_DB_TUNNEL", "0");
    }
}

fn php_side_effect_guards_enabled() -> bool {
    !matches!(
        std::env::var("WPCOW_ALLOW_UNSAFE_PLUGIN_SIDE_EFFECTS")
            .unwrap_or_default()
            .to_ascii_lowercase()
            .as_str(),
        "1" | "true" | "yes" | "on"
    )
}

fn php_disabled_functions() -> &'static str {
    "exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,mail,fsockopen,pfsockopen,stream_socket_client"
}

fn php_safety_ini_entries() -> Vec<(&'static str, String)> {
    if !php_side_effect_guards_enabled() {
        return Vec::new();
    }

    vec![
        ("disable_functions", php_disabled_functions().to_string()),
        ("allow_url_include", "0".to_string()),
    ]
}

fn opcache_validate_timestamps() -> u64 {
    env_u64("WPCOW_OPCACHE_VALIDATE_TIMESTAMPS", 0).min(1)
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
    let mut command = Command::new(frankenphp_bin());
    apply_web_server_env(&mut command, paths);
    command
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
    let mut command = Command::new("php");
    apply_web_server_env(&mut command, paths);
    let workers = env_u64("WPCOW_PHP_WORKERS", 4);
    if workers > 1 {
        command.env("PHP_CLI_SERVER_WORKERS", workers.to_string());
    }
    command
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
        .arg("-d")
        .arg("opcache.enable_cli=1")
        .arg("-d")
        .arg("opcache.memory_consumption=192")
        .arg("-d")
        .arg("opcache.max_accelerated_files=20000")
        .arg("-d")
        .arg(format!(
            "opcache.validate_timestamps={}",
            opcache_validate_timestamps()
        ))
        .stdin(Stdio::null());
    for (name, value) in php_safety_ini_entries() {
        command.arg("-d").arg(format!("{name}={value}"));
    }
    command
        .arg("-S")
        .arg(http_addr)
        .arg("-t")
        .arg(mountpoint)
        .arg(paths.generated.join("router.php"))
        .spawn()
        .context("start php built-in server")
}

fn frankenphp_caddyfile(_paths: &ClonePaths, mountpoint: &Path, http_addr: &str) -> String {
    let threads = env_u64("WPCOW_PHP_WORKERS", 4);
    let max_execution = env_u64("WPCOW_PHP_MAX_EXECUTION_SECS", 90);
    let socket_timeout = env_u64("WPCOW_PHP_SOCKET_TIMEOUT_SECS", 15);
    let opcache_validate = opcache_validate_timestamps();
    let safety_ini = php_safety_ini_entries()
        .into_iter()
        .map(|(name, value)| format!("\t\tphp_ini {name} {value}\n"))
        .collect::<String>();
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
		php_ini opcache.validate_timestamps {opcache_validate}
		php_ini opcache.revalidate_freq 2
{safety_ini}
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

	@wpCowInstaller path /wp-admin/install.php /wp-admin/setup-config.php
	handle @wpCowInstaller {{
		rewrite * {router}?__wp_cow_installer_guard=1
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

    #[test]
    fn frankenphp_routes_installer_paths_through_runtime_guard() {
        let temp = tempfile::tempdir().unwrap();
        let paths = crate::config::clone_paths(temp.path(), "example");
        let caddyfile = frankenphp_caddyfile(&paths, Path::new("/tmp/mount"), "127.0.0.1:9481");
        assert!(caddyfile
            .contains("@wpCowInstaller path /wp-admin/install.php /wp-admin/setup-config.php"));
        assert!(caddyfile.contains("rewrite * /.wp-cow-router.php?__wp_cow_installer_guard=1"));
        assert!(
            caddyfile.find("@wpCowInstaller").unwrap() < caddyfile.find("@phpFiles").unwrap(),
            "installer guard must run before the generic PHP file handler"
        );
    }

    #[test]
    fn web_runtime_disables_common_plugin_side_effect_primitives() {
        assert!(php_disabled_functions().contains("stream_socket_client"));
        assert!(php_disabled_functions().contains("proc_open"));
        assert!(php_disabled_functions().contains("mail"));

        let temp = tempfile::tempdir().unwrap();
        let paths = crate::config::clone_paths(temp.path(), "example");
        let caddyfile = frankenphp_caddyfile(&paths, Path::new("/tmp/mount"), "127.0.0.1:9481");
        assert!(caddyfile.contains("php_ini disable_functions"));
        assert!(caddyfile.contains("stream_socket_client"));
        assert!(caddyfile.contains("php_ini allow_url_include 0"));
    }

    #[test]
    fn web_runtime_defaults_to_no_opcache_timestamp_revalidation() {
        let temp = tempfile::tempdir().unwrap();
        let paths = crate::config::clone_paths(temp.path(), "example");
        let caddyfile = frankenphp_caddyfile(&paths, Path::new("/tmp/mount"), "127.0.0.1:9481");
        assert!(caddyfile.contains("php_ini opcache.validate_timestamps 0"));
    }

    #[test]
    fn command_exists_requires_an_executable_file() {
        let temp = tempfile::tempdir().unwrap();
        let fake = temp.path().join("frankenphp");
        fs::write(&fake, b"#!/bin/sh\nexit 0\n").unwrap();

        let mut permissions = fs::metadata(&fake).unwrap().permissions();
        permissions.set_mode(0o644);
        fs::set_permissions(&fake, permissions).unwrap();
        assert!(
            !command_exists(fake.to_str().unwrap()),
            "a non-executable FrankenPHP file must not suppress the PHP fallback"
        );

        let mut permissions = fs::metadata(&fake).unwrap().permissions();
        permissions.set_mode(0o755);
        fs::set_permissions(&fake, permissions).unwrap();
        assert!(command_exists(fake.to_str().unwrap()));
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

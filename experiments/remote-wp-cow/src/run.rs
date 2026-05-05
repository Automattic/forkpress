use anyhow::{anyhow, Context, Result};
use std::fs;
use std::os::unix::fs::PermissionsExt;
use std::path::{Path, PathBuf};
use std::process::{Child, Command, Output, Stdio};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::thread::{self, JoinHandle};
use std::time::{Duration, Instant};

use crate::config::{self, ClonePaths, Manifest};
use crate::control;
use crate::fusefs;
use crate::generate::ROUTER_BASENAME;
use crate::mysql_proxy;
use crate::plugin_policy;
use crate::remote::RemoteClient;
use crate::runtime_cache;

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
    if !offline && runtime_cache::runtime_code_pack_enabled() {
        runtime_cache::mark_runtime_code_cache_starting(&paths);
        let warm_manifest = manifest.clone();
        let warm_paths = paths.clone();
        let warm_remote = remote.clone();
        thread::spawn(move || {
            match runtime_cache::warm_runtime_code_cache(&warm_remote, &warm_manifest, &warm_paths)
            {
                Ok(summary) => {
                    eprintln!(
                        "wp-cow cached {} bounded runtime code files ({:.1} MB); uploads/media remain lazy",
                        summary.files,
                        summary.bytes as f64 / (1024.0 * 1024.0)
                    );
                    if summary.capped {
                        eprintln!(
                            "wp-cow runtime code cache hit its configured cap; remaining runtime files stay lazy"
                        );
                    }
                }
                Err(err) => {
                    runtime_cache::mark_runtime_code_cache_failed(&warm_paths);
                    eprintln!("wp-cow runtime code cache failed: {err:#}");
                    eprintln!("wp-cow continuing with lazy per-file remote reads");
                }
            }
        });
    }

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
    let plugin_admission_thread = spawn_plugin_admission_if_enabled(
        manifest.clone(),
        paths.clone(),
        options.mountpoint.clone(),
        shutdown.clone(),
    );

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
    if let Some(handle) = plugin_admission_thread {
        match handle.join() {
            Ok(Ok(())) => {}
            Ok(Err(err)) => eprintln!("wp-cow plugin admission stopped: {err:#}"),
            Err(_) => eprintln!("wp-cow plugin admission thread panicked"),
        }
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

fn default_php_disabled_functions() -> &'static str {
    "exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,mail,fsockopen,pfsockopen,stream_socket_client,curl_exec,curl_multi_exec"
}

fn php_disabled_functions() -> String {
    match std::env::var("WPCOW_PHP_DISABLE_FUNCTIONS") {
        Ok(raw) => {
            let raw = raw.trim();
            if raw.is_empty()
                || matches!(
                    raw.to_ascii_lowercase().as_str(),
                    "0" | "false" | "no" | "off" | "none"
                )
            {
                String::new()
            } else {
                raw.to_string()
            }
        }
        Err(_) => default_php_disabled_functions().to_string(),
    }
}

fn php_safety_ini_entries() -> Vec<(&'static str, String)> {
    if !php_side_effect_guards_enabled() {
        return Vec::new();
    }

    let disabled_functions = php_disabled_functions();
    let mut entries = vec![("allow_url_include", "0".to_string())];
    if !disabled_functions.is_empty() {
        entries.insert(0, ("disable_functions", disabled_functions));
    }
    entries
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
    apply_php_safety_ini_args(&mut command);
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

fn spawn_plugin_admission_if_enabled(
    manifest: Manifest,
    paths: ClonePaths,
    mountpoint: PathBuf,
    shutdown: Arc<AtomicBool>,
) -> Option<JoinHandle<Result<()>>> {
    if !plugin_admission_enabled(&manifest) {
        return None;
    }

    Some(thread::spawn(move || {
        run_plugin_admission(manifest, paths, mountpoint, shutdown)
    }))
}

fn plugin_admission_enabled(manifest: &Manifest) -> bool {
    if plugin_policy::active_plugins_for_policy(manifest).is_empty() {
        return false;
    }
    if env_is_false("WPCOW_PLUGIN_ADMISSION") {
        return false;
    }

    let mode = plugin_mode_from_env();
    !matches!(
        mode.as_str(),
        "full"
            | "on"
            | "enabled"
            | "1"
            | "true"
            | "yes"
            | "off"
            | "none"
            | "disabled"
            | "disable"
            | "0"
            | "false"
            | "no"
    )
}

fn plugin_mode_from_env() -> String {
    let mode = std::env::var("WPCOW_PLUGIN_MODE")
        .unwrap_or_default()
        .trim()
        .to_ascii_lowercase();
    if !mode.is_empty() {
        return mode;
    }

    let legacy = std::env::var("WPCOW_ENABLE_PLUGINS")
        .unwrap_or_default()
        .trim()
        .to_ascii_lowercase();
    if matches!(
        legacy.as_str(),
        "1" | "true" | "yes" | "on" | "full" | "enabled"
    ) {
        "full".to_string()
    } else if matches!(
        legacy.as_str(),
        "0" | "false" | "no" | "off" | "none" | "disabled" | "disable"
    ) {
        "off".to_string()
    } else {
        "auto".to_string()
    }
}

fn env_is_false(name: &str) -> bool {
    std::env::var(name)
        .ok()
        .map(|raw| {
            matches!(
                raw.trim().to_ascii_lowercase().as_str(),
                "0" | "false" | "no" | "off" | "disabled"
            )
        })
        .unwrap_or(false)
}

fn run_plugin_admission(
    manifest: Manifest,
    paths: ClonePaths,
    mountpoint: PathBuf,
    shutdown: Arc<AtomicBool>,
) -> Result<()> {
    if config::is_offline(&paths) {
        return Ok(());
    }
    if !wait_for_first_request_ready(&paths, &shutdown) {
        return Ok(());
    }
    sleep_shutdown_aware(
        Duration::from_secs(env_u64("WPCOW_PLUGIN_ADMISSION_DELAY_SECS", 20)),
        &shutdown,
    );
    if shutdown.load(Ordering::SeqCst) || config::is_offline(&paths) {
        return Ok(());
    }

    let policy_path = plugin_policy::policy_path(&paths);
    let timeout = Duration::from_secs(env_u64("WPCOW_PLUGIN_ADMISSION_TIMEOUT_SECS", 15).max(1));
    let active_plugins = plugin_policy::active_plugins_for_policy(&manifest);
    let mut policy = plugin_policy::load_policy_or_new(&policy_path, &active_plugins)?;

    for plugin in &policy.active.clone() {
        if shutdown.load(Ordering::SeqCst) || config::is_offline(&paths) {
            break;
        }
        policy = plugin_policy::load_policy_or_new(&policy_path, &active_plugins)?;
        if policy.allows(plugin) || policy.quarantine.contains_key(plugin) {
            continue;
        }

        eprintln!("wp-cow admitting plugin candidate '{plugin}'");
        let candidate = plugin_policy::policy_with_candidate(&policy, plugin);
        let candidate_path = plugin_policy::candidate_policy_path(&paths, plugin);
        plugin_policy::write_policy_atomic(&candidate_path, &candidate)?;

        let admission = run_plugin_smoke(&mountpoint, &candidate_path, timeout);
        policy = plugin_policy::load_policy_or_new(&policy_path, &active_plugins)?;
        match admission {
            Ok(()) => {
                policy.allow_plugin(plugin);
                eprintln!("wp-cow admitted plugin '{plugin}'");
            }
            Err(err) => {
                let reason = trim_reason(&format!("{err:#}"));
                policy.quarantine_plugin(plugin, reason.clone());
                eprintln!("wp-cow quarantined plugin '{plugin}': {reason}");
            }
        }
        plugin_policy::write_policy_atomic(&policy_path, &policy)?;
        let _ = fs::remove_file(candidate_path);
    }

    Ok(())
}

fn wait_for_first_request_ready(paths: &ClonePaths, shutdown: &AtomicBool) -> bool {
    let ready_file = paths.run.join("first-request-ready.json");
    let deadline = Instant::now()
        + Duration::from_secs(env_u64("WPCOW_PLUGIN_ADMISSION_READY_TIMEOUT_SECS", 600));
    while Instant::now() < deadline {
        if shutdown.load(Ordering::SeqCst) {
            return false;
        }
        if ready_file.is_file() {
            return true;
        }
        thread::sleep(Duration::from_millis(250));
    }
    false
}

fn sleep_shutdown_aware(duration: Duration, shutdown: &AtomicBool) {
    let deadline = Instant::now() + duration;
    while Instant::now() < deadline {
        if shutdown.load(Ordering::SeqCst) {
            return;
        }
        thread::sleep(Duration::from_millis(250));
    }
}

fn run_plugin_smoke(
    mountpoint: &Path,
    candidate_policy_path: &Path,
    timeout: Duration,
) -> Result<()> {
    let mut command = Command::new("php");
    command
        .current_dir(mountpoint)
        .env("WPCOW_PLUGIN_MODE", "auto")
        .env("WPCOW_PLUGIN_POLICY_FILE", candidate_policy_path)
        .env("WPCOW_SPLASH", "0")
        .env("WPCOW_PROXY_FRONTEND", "0")
        .env("WPCOW_ACTIVE_WARM_WAIT", "0")
        .env("WPCOW_PLUGIN_ADMISSION_SMOKE", "1")
        .arg("-d")
        .arg(format!(
            "max_execution_time={}",
            timeout.as_secs().saturating_add(2)
        ))
        .arg("-d")
        .arg(format!(
            "default_socket_timeout={}",
            env_u64("WPCOW_PHP_SOCKET_TIMEOUT_SECS", 15).min(timeout.as_secs().max(1))
        ))
        .arg("-d")
        .arg(format!(
            "mysqlnd.net_read_timeout={}",
            env_u64("WPCOW_PHP_SOCKET_TIMEOUT_SECS", 15).min(timeout.as_secs().max(1))
        ));
    apply_php_safety_ini_args(&mut command);
    command
        .arg("-r")
        .arg(plugin_smoke_php())
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped());

    let output = run_command_with_timeout(command, timeout)
        .with_context(|| format!("plugin smoke timed out after {}s", timeout.as_secs()))?;
    if !output.status.success() {
        return Err(anyhow!(
            "plugin smoke exited with status {}{}",
            output.status,
            output_tail(&output)
        ));
    }
    if !String::from_utf8_lossy(&output.stdout).contains("WPCOW_PLUGIN_SMOKE_OK") {
        return Err(anyhow!(
            "plugin smoke did not finish cleanly{}",
            output_tail(&output)
        ));
    }
    Ok(())
}

fn plugin_smoke_php() -> &'static str {
    r#"
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/?__wp_cow_bypass_splash=1&__wp_cow_plugin_smoke=1';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['DOCUMENT_ROOT'] = getcwd();
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = getcwd() . '/index.php';
$_GET['__wp_cow_bypass_splash'] = '1';
ob_start();
require getcwd() . '/index.php';
$html = ob_get_clean();
if (
	false !== stripos( $html, 'wp-cow DB/runtime error' ) ||
	false !== stripos( $html, 'wp-cow did not load the remote site' ) ||
	false !== stripos( $html, 'wp-admin/install.php' ) ||
	false !== stripos( $html, 'WordPress &rsaquo; Installation' )
) {
	fwrite( STDERR, $html );
	exit( 3 );
}
echo "\nWPCOW_PLUGIN_SMOKE_OK\n";
"#
}

fn run_command_with_timeout(mut command: Command, timeout: Duration) -> Result<Output> {
    let mut child = command.spawn().context("spawn plugin smoke PHP")?;
    let started = Instant::now();
    loop {
        if child.try_wait()?.is_some() {
            return child
                .wait_with_output()
                .context("collect plugin smoke PHP output");
        }
        if started.elapsed() >= timeout {
            let _ = child.kill();
            let output = child
                .wait_with_output()
                .context("collect timed-out plugin smoke PHP output")?;
            return Err(anyhow!("timed out{}", output_tail(&output)));
        }
        thread::sleep(Duration::from_millis(100));
    }
}

fn output_tail(output: &Output) -> String {
    let mut text = String::new();
    text.push_str(&String::from_utf8_lossy(&output.stdout));
    text.push_str(&String::from_utf8_lossy(&output.stderr));
    let text = text.trim();
    if text.is_empty() {
        return String::new();
    }
    let tail = text
        .lines()
        .rev()
        .take(12)
        .collect::<Vec<_>>()
        .into_iter()
        .rev()
        .collect::<Vec<_>>()
        .join("\n");
    format!(": {tail}")
}

fn trim_reason(reason: &str) -> String {
    let reason = reason.replace('\n', " ");
    if reason.len() <= 500 {
        reason
    } else {
        format!("{}...", reason.chars().take(500).collect::<String>())
    }
}

fn apply_php_safety_ini_args(command: &mut Command) {
    for (name, value) in php_safety_ini_entries() {
        command.arg("-d").arg(format!("{name}={value}"));
    }
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
        assert!(php_disabled_functions().contains("curl_exec"));
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

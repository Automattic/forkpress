use anyhow::{anyhow, Context, Result};
use std::path::{Path, PathBuf};
use std::process::{Child, Command, Stdio};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::thread;
use std::time::Duration;

use crate::config::{ClonePaths, Manifest};
use crate::control;
use crate::fusefs;
use crate::remote::RemoteClient;

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
    remote.ensure_master()?;

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

    let mount_manifest = manifest.clone();
    let mount_paths = paths.clone();
    let mountpoint = options.mountpoint.clone();
    let mount_thread =
        thread::spawn(move || fusefs::mount_foreground(mount_manifest, mount_paths, &mountpoint));

    wait_for_mount(&options.mountpoint);

    let mut php = if options.skip_php {
        None
    } else {
        Some(start_php_server(
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
        if let Some(child) = php.as_mut() {
            if let Some(status) = child.try_wait()? {
                shutdown.store(true, Ordering::SeqCst);
                return Err(anyhow!("php server exited with status {}", status));
            }
        }
        thread::sleep(Duration::from_millis(250));
    }

    if let Some(mut child) = php {
        let _ = child.kill();
        let _ = child.wait();
    }
    let _ = unmount(&options.mountpoint);

    match control_thread.join() {
        Ok(result) => result?,
        Err(_) => return Err(anyhow!("control thread panicked")),
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

fn start_php_server(paths: &ClonePaths, mountpoint: &Path, http_addr: &str) -> Result<Child> {
    Command::new("php")
        .arg("-S")
        .arg(http_addr)
        .arg("-t")
        .arg(mountpoint)
        .arg(paths.generated.join("router.php"))
        .stdin(Stdio::null())
        .spawn()
        .context("start php built-in server")
}

fn wait_for_mount(mountpoint: &Path) {
    for _ in 0..40 {
        if mountpoint.join("wp-config.php").exists() {
            return;
        }
        thread::sleep(Duration::from_millis(100));
    }
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

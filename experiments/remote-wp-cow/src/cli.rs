use anyhow::{anyhow, Context, Result};
use clap::{Args, Parser, Subcommand};
use std::fs;
use std::path::PathBuf;

use crate::config::{
    clone_paths, default_state_dir, derive_name, ensure_clone_dirs, load_manifest, write_manifest,
    Manifest, Probe,
};
use crate::db;
use crate::generate;
use crate::remote::{probe_wordpress, RemoteClient};
use crate::run::{self, RunOptions};

#[derive(Debug, Parser)]
#[command(name = "wp-cow")]
#[command(about = "Lazy local WordPress clone runtime over SSH")]
pub struct Cli {
    #[command(subcommand)]
    command: Command,
}

#[derive(Debug, Subcommand)]
enum Command {
    #[command(name = "clone")]
    Clone(CloneArgs),
    #[command(name = "serve")]
    Serve(ServeArgs),
    #[command(name = "init-db")]
    InitDb(NameArgs),
    #[command(name = "export-schema")]
    ExportSchema(NameArgs),
    #[command(name = "materialize")]
    Materialize(MaterializeArgs),
    #[command(name = "mount")]
    Mount(MountArgs),
    #[command(name = "run")]
    Run(RunArgs),
    #[command(name = "probe")]
    Probe(ProbeArgs),
}

#[derive(Debug, Args)]
struct CloneArgs {
    #[arg(long = "ssh")]
    ssh: String,
    #[arg(long = "path")]
    path: String,
    #[arg(long = "remote-url")]
    remote_url: String,
    #[arg(long = "local-url")]
    local_url: String,
    #[arg(long)]
    name: Option<String>,
    #[arg(long)]
    state_dir: Option<PathBuf>,
    #[arg(long)]
    force: bool,
    #[arg(long)]
    no_probe: bool,
    #[arg(long)]
    skip_schema: bool,
}

#[derive(Debug, Args)]
struct ServeArgs {
    #[arg(long = "ssh")]
    ssh: String,
    #[arg(long = "path")]
    path: String,
    #[arg(long = "remote-url")]
    remote_url: String,
    #[arg(long = "local-url")]
    local_url: String,
    #[arg(long)]
    name: Option<String>,
    #[arg(long)]
    state_dir: Option<PathBuf>,
    #[arg(long)]
    force: bool,
    #[arg(long)]
    no_probe: bool,
    #[arg(long)]
    mountpoint: Option<PathBuf>,
    #[arg(long, default_value = "127.0.0.1:8080")]
    http: String,
    #[arg(long)]
    no_php: bool,
    #[arg(long)]
    no_runtime_sync: bool,
}

#[derive(Debug, Args)]
struct NameArgs {
    name: String,
    #[arg(long)]
    state_dir: Option<PathBuf>,
}

#[derive(Debug, Args)]
struct MaterializeArgs {
    name: String,
    #[arg(long = "table", required = true)]
    tables: Vec<String>,
    #[arg(long)]
    state_dir: Option<PathBuf>,
}

#[derive(Debug, Args)]
struct MountArgs {
    name: String,
    #[arg(long)]
    mountpoint: Option<PathBuf>,
    #[arg(long)]
    state_dir: Option<PathBuf>,
}

#[derive(Debug, Args)]
struct RunArgs {
    name: String,
    #[arg(long)]
    mountpoint: Option<PathBuf>,
    #[arg(long, default_value = "127.0.0.1:8080")]
    http: String,
    #[arg(long)]
    no_php: bool,
    #[arg(long)]
    state_dir: Option<PathBuf>,
}

#[derive(Debug, Args)]
struct ProbeArgs {
    #[arg(long = "ssh")]
    ssh: String,
    #[arg(long = "path")]
    path: String,
}

pub fn run() -> Result<()> {
    let cli = Cli::parse();
    match cli.command {
        Command::Clone(args) => clone_site(args),
        Command::Serve(args) => serve_site(args),
        Command::InitDb(args) => init_db(args),
        Command::ExportSchema(args) => export_schema(args),
        Command::Materialize(args) => materialize(args),
        Command::Mount(args) => mount(args),
        Command::Run(args) => run_clone(args),
        Command::Probe(args) => {
            let probe = probe_wordpress(&args.ssh, &args.path)?;
            println!("{}", serde_json::to_string_pretty(&probe)?);
            Ok(())
        }
    }
}

fn clone_site(args: CloneArgs) -> Result<()> {
    let state_dir = args.state_dir.unwrap_or(default_state_dir()?);
    let name = args
        .name
        .unwrap_or_else(|| derive_name(&args.remote_url, &args.local_url));
    let paths = clone_paths(&state_dir, &name);

    if paths.root.exists() {
        if !args.force {
            return Err(anyhow!(
                "{} already exists; pass --force to replace generated clone metadata",
                paths.root.display()
            ));
        }
        fs::remove_dir_all(&paths.root)?;
    }

    ensure_clone_dirs(&paths)?;

    let probe = if args.no_probe {
        Probe {
            abspath: args.path.clone(),
            wp_content_dir: format!("{}/wp-content", args.path.trim_end_matches('/')),
            uploads_dir: format!("{}/wp-content/uploads", args.path.trim_end_matches('/')),
            table_prefix: "wp_".to_string(),
            siteurl: args.remote_url.clone(),
            home: args.remote_url.clone(),
            ..Probe::default()
        }
    } else {
        probe_wordpress(&args.ssh, &args.path)?
    };

    let manifest = Manifest::new(
        name,
        args.ssh,
        args.path,
        args.remote_url,
        args.local_url,
        probe,
    );

    write_manifest(&paths.manifest, &manifest)?;
    generate::write_wordpress_overrides(&paths, &manifest)?;
    db::write_state(&paths, &db::DbState::default())?;

    if !args.skip_schema && !args.no_probe {
        let remote = RemoteClient::new(manifest.clone(), Some(paths.run.join("ssh-control.sock")));
        remote.ensure_master()?;
        db::export_schema(&remote, &paths).context("export schema")?;
    }

    println!(
        "created clone '{}': {}",
        manifest.name,
        paths.root.display()
    );
    Ok(())
}

fn serve_site(args: ServeArgs) -> Result<()> {
    let state_dir = args.state_dir.clone().unwrap_or(default_state_dir()?);
    let name = args
        .name
        .clone()
        .unwrap_or_else(|| derive_name(&args.remote_url, &args.local_url));
    let paths = clone_paths(&state_dir, &name);

    let manifest = if !paths.root.exists() || args.force {
        if paths.root.exists() {
            fs::remove_dir_all(&paths.root)?;
        }
        ensure_clone_dirs(&paths)?;

        let probe = if args.no_probe {
            Probe {
                abspath: args.path.clone(),
                wp_content_dir: format!("{}/wp-content", args.path.trim_end_matches('/')),
                uploads_dir: format!("{}/wp-content/uploads", args.path.trim_end_matches('/')),
                table_prefix: "wp_".to_string(),
                siteurl: args.remote_url.clone(),
                home: args.remote_url.clone(),
                ..Probe::default()
            }
        } else {
            probe_wordpress(&args.ssh, &args.path)?
        };

        let manifest = Manifest::new(
            name,
            args.ssh.clone(),
            args.path.clone(),
            args.remote_url.clone(),
            args.local_url.clone(),
            probe,
        );

        write_manifest(&paths.manifest, &manifest)?;
        generate::write_wordpress_overrides(&paths, &manifest)?;
        db::write_state(&paths, &db::DbState::default())?;
        println!("created lazy clone '{}'", manifest.name);
        manifest
    } else {
        let mut manifest = load_manifest(&paths.manifest)?;
        let mut changed = false;
        let mut should_probe = false;

        if manifest.ssh != args.ssh {
            manifest.ssh = args.ssh.clone();
            changed = true;
            should_probe = true;
        }
        if manifest.remote_path != args.path {
            manifest.remote_path = args.path.clone();
            changed = true;
            should_probe = true;
        }
        if manifest.remote_url != args.remote_url {
            manifest.remote_url = args.remote_url.clone();
            changed = true;
        }
        if manifest.local_url != args.local_url {
            manifest.local_url = args.local_url.clone();
            changed = true;
        }

        if !args.no_probe
            && (should_probe
                || manifest.probe.db_name.is_empty()
                || manifest.probe.db_host.is_empty()
                || manifest.probe.db_user.is_empty())
        {
            manifest.probe = probe_wordpress(&manifest.ssh, &manifest.remote_path)?;
            changed = true;
        }

        if changed {
            write_manifest(&paths.manifest, &manifest)?;
            generate::write_wordpress_overrides(&paths, &manifest)?;
            println!("updated lazy clone '{}'", manifest.name);
        } else {
            println!("using existing lazy clone '{}'", manifest.name);
        }

        manifest
    };

    if should_sync_runtime(&paths, args.no_runtime_sync) {
        let remote = RemoteClient::new(manifest.clone(), Some(paths.run.join("ssh-control.sock")));
        remote.ensure_master()?;
        println!(
            "syncing WordPress runtime files for '{}' (uploads stay lazy)",
            manifest.name
        );
        remote
            .sync_runtime_files(&paths.upper)
            .context("sync WordPress runtime files")?;
        fs::write(paths.generated.join("runtime-files.synced"), b"ok\n")?;
        println!("synced WordPress runtime files for '{}'", manifest.name);
    } else {
        println!(
            "using local WordPress runtime files for '{}'",
            manifest.name
        );
    }

    generate::write_wordpress_overrides(&paths, &manifest)?;

    if !paths.db.join("schema.sql").exists() {
        if args.no_probe {
            return Err(anyhow!(
                "schema is missing and --no-probe prevents discovering remote DB settings"
            ));
        }
        let remote = RemoteClient::new(manifest.clone(), Some(paths.run.join("ssh-control.sock")));
        remote.ensure_master()?;
        db::export_schema(&remote, &paths).context("export schema")?;
        println!("exported schema only for '{}'", manifest.name);
    }

    if db::init_local_db_if_empty(&manifest, &paths)? {
        println!(
            "initialized empty local database '{}'",
            manifest.local_db.name
        );
    } else {
        println!("using existing local database '{}'", manifest.local_db.name);
    }

    println!(
        "starting lazy COW server; files and database rows are fetched on demand, not copied up front"
    );

    let mountpoint = args
        .mountpoint
        .unwrap_or_else(|| PathBuf::from("/mnt/wp-cow").join(&manifest.name));
    let options = RunOptions {
        mountpoint,
        http_addr: args.http,
        skip_php: args.no_php,
    };
    run::run_site(manifest, paths, options)
}

fn should_sync_runtime(paths: &crate::config::ClonePaths, no_runtime_sync: bool) -> bool {
    if no_runtime_sync || env_bool("WPCOW_RUNTIME_SYNC", true) == Some(false) {
        return false;
    }
    if env_bool("WPCOW_RUNTIME_SYNC_FORCE", false) == Some(true) {
        return true;
    }
    !paths.generated.join("runtime-files.synced").is_file()
}

fn env_bool(name: &str, default: bool) -> Option<bool> {
    let raw = std::env::var(name).ok()?;
    match raw.to_ascii_lowercase().as_str() {
        "1" | "true" | "yes" | "on" => Some(true),
        "0" | "false" | "no" | "off" => Some(false),
        _ => Some(default),
    }
}

fn init_db(args: NameArgs) -> Result<()> {
    let state_dir = args.state_dir.unwrap_or(default_state_dir()?);
    let paths = clone_paths(&state_dir, &args.name);
    let manifest = load_manifest(&paths.manifest)?;
    db::init_local_db(&manifest, &paths)?;
    println!("initialized local database '{}'", manifest.local_db.name);
    Ok(())
}

fn export_schema(args: NameArgs) -> Result<()> {
    let state_dir = args.state_dir.unwrap_or(default_state_dir()?);
    let paths = clone_paths(&state_dir, &args.name);
    let manifest = load_manifest(&paths.manifest)?;
    let remote = RemoteClient::new(manifest.clone(), Some(paths.run.join("ssh-control.sock")));
    remote.ensure_master()?;
    db::export_schema(&remote, &paths)?;
    println!("exported remote schema for '{}'", manifest.name);
    Ok(())
}

fn materialize(args: MaterializeArgs) -> Result<()> {
    let state_dir = args.state_dir.unwrap_or(default_state_dir()?);
    let paths = clone_paths(&state_dir, &args.name);
    let manifest = load_manifest(&paths.manifest)?;
    let remote = RemoteClient::new(manifest.clone(), Some(paths.run.join("ssh-control.sock")));
    remote.ensure_master()?;
    let materialized = db::materialize_tables(&remote, &manifest, &paths, &args.tables)?;
    println!("{}", serde_json::to_string_pretty(&materialized)?);
    Ok(())
}

fn mount(args: MountArgs) -> Result<()> {
    let state_dir = args.state_dir.unwrap_or(default_state_dir()?);
    let paths = clone_paths(&state_dir, &args.name);
    let manifest = load_manifest(&paths.manifest)?;
    let mountpoint = args
        .mountpoint
        .unwrap_or_else(|| PathBuf::from("/mnt/wp-cow").join(&manifest.name));
    run::mount_only(manifest, paths, &mountpoint)
}

fn run_clone(args: RunArgs) -> Result<()> {
    let state_dir = args.state_dir.unwrap_or(default_state_dir()?);
    let paths = clone_paths(&state_dir, &args.name);
    let manifest = load_manifest(&paths.manifest)?;
    let mountpoint = args
        .mountpoint
        .unwrap_or_else(|| PathBuf::from("/mnt/wp-cow").join(&manifest.name));
    let options = RunOptions {
        mountpoint,
        http_addr: args.http,
        skip_php: args.no_php,
    };
    run::run_site(manifest, paths, options)
}

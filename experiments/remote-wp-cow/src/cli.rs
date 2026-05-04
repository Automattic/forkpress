use anyhow::{anyhow, Context, Result};
use clap::{Args, Parser, Subcommand};
use std::collections::{BTreeSet, VecDeque};
use std::fs;
use std::path::{Path, PathBuf};
use std::time::{Instant, SystemTime, UNIX_EPOCH};

use crate::config::{
    clone_paths, default_state_dir, derive_name, ensure_clone_dirs, load_manifest, write_manifest,
    write_offline_marker, Manifest, OfflineMarker, Probe,
};
use crate::db;
use crate::generate;
use crate::overlay::OverlayStore;
use crate::remote::{probe_wordpress, RemoteClient};
use crate::run::{self, RunOptions};
use crate::runtime_cache;

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
    #[command(name = "sever")]
    Sever(SeverArgs),
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
    #[arg(long, hide = true)]
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
struct SeverArgs {
    name: String,
    #[arg(long = "admin-password")]
    admin_password: Option<String>,
    #[arg(long = "admin-login")]
    admin_login: Option<String>,
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
        Command::Sever(args) => sever(args),
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
        let remote = RemoteClient::new(
            manifest.clone(),
            Some(crate::config::ssh_control_path(&paths)),
        );
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
    let serve_started = Instant::now();
    let state_dir = args.state_dir.clone().unwrap_or(default_state_dir()?);
    let name = args
        .name
        .clone()
        .unwrap_or_else(|| derive_name(&args.remote_url, &args.local_url));
    let paths = clone_paths(&state_dir, &name);

    let metadata_started = Instant::now();
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
                || manifest.probe.db_user.is_empty()
                || (manifest.probe.template.is_empty() && manifest.probe.stylesheet.is_empty()))
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
    println!(
        "prepared clone metadata in {:.2}s",
        metadata_started.elapsed().as_secs_f64()
    );

    if args.no_runtime_sync {
        println!(
            "--no-runtime-sync is now the fixed serve behavior for '{}'",
            manifest.name
        );
    }
    if std::env::var_os("WPCOW_RUNTIME_SYNC").is_some()
        || std::env::var_os("WPCOW_RUNTIME_SYNC_FORCE").is_some()
    {
        println!(
            "ignoring runtime sync environment for '{}'; requested files will be fetched on demand",
            manifest.name
        );
    }
    println!(
        "runtime code is cached in a bounded pack for '{}'; uploads/media remain lazy and are cached on demand",
        manifest.name
    );

    generate::write_wordpress_overrides(&paths, &manifest)?;

    if !paths.db.join("schema.sql").exists() {
        let phase_started = Instant::now();
        if args.no_probe {
            return Err(anyhow!(
                "schema is missing and --no-probe prevents discovering remote DB settings"
            ));
        }
        let remote = RemoteClient::new(
            manifest.clone(),
            Some(crate::config::ssh_control_path(&paths)),
        );
        remote.ensure_master()?;
        db::export_schema(&remote, &paths).context("export schema")?;
        println!(
            "exported schema only for '{}' in {:.2}s",
            manifest.name,
            phase_started.elapsed().as_secs_f64()
        );
    }

    let phase_started = Instant::now();
    if db::init_local_db_if_empty(&manifest, &paths)? {
        println!(
            "initialized empty local database '{}' in {:.2}s",
            manifest.local_db.name,
            phase_started.elapsed().as_secs_f64()
        );
    } else {
        println!(
            "using existing local database '{}' ({:.2}s)",
            manifest.local_db.name,
            phase_started.elapsed().as_secs_f64()
        );
    }

    if materialize_options_table_enabled() {
        let phase_started = Instant::now();
        let remote = RemoteClient::new(
            manifest.clone(),
            Some(crate::config::ssh_control_path(&paths)),
        );
        remote.ensure_master()?;
        let options_table = format!("{}options", manifest.probe.table_prefix);
        let materialized = db::materialize_tables(
            &remote,
            &manifest,
            &paths,
            std::slice::from_ref(&options_table),
        )
        .context("materialize WordPress options table")?;
        println!(
            "materialized {} WordPress options table(s) for local plugin/runtime reads in {:.2}s",
            materialized.len(),
            phase_started.elapsed().as_secs_f64()
        );
    }

    println!(
        "starting lazy COW server after {:.2}s; files and database rows are fetched on demand, not copied up front",
        serve_started.elapsed().as_secs_f64()
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
    let remote = RemoteClient::new(
        manifest.clone(),
        Some(crate::config::ssh_control_path(&paths)),
    );
    remote.ensure_master()?;
    db::export_schema(&remote, &paths)?;
    println!("exported remote schema for '{}'", manifest.name);
    Ok(())
}

fn materialize(args: MaterializeArgs) -> Result<()> {
    let state_dir = args.state_dir.unwrap_or(default_state_dir()?);
    let paths = clone_paths(&state_dir, &args.name);
    let manifest = load_manifest(&paths.manifest)?;
    let remote = RemoteClient::new(
        manifest.clone(),
        Some(crate::config::ssh_control_path(&paths)),
    );
    remote.ensure_master()?;
    let materialized = db::materialize_tables(&remote, &manifest, &paths, &args.tables)?;
    println!("{}", serde_json::to_string_pretty(&materialized)?);
    Ok(())
}

fn sever(args: SeverArgs) -> Result<()> {
    let started = Instant::now();
    let state_dir = args.state_dir.unwrap_or(default_state_dir()?);
    let paths = clone_paths(&state_dir, &args.name);
    let manifest = load_manifest(&paths.manifest)?;
    let remote = RemoteClient::new(
        manifest.clone(),
        Some(crate::config::ssh_control_path(&paths)),
    );

    remote.ensure_master()?;
    if !paths.db.join("schema.sql").exists() {
        db::export_schema(&remote, &paths).context("export schema")?;
    }
    if db::init_local_db_if_empty(&manifest, &paths)? {
        println!(
            "initialized empty local database '{}'",
            manifest.local_db.name
        );
    }

    let refreshed_options = db::refresh_option_bootstrap_for_offline(&remote, &manifest, &paths)
        .context("refresh remote option bootstrap rows for offline use")?;
    println!(
        "refreshed {} WordPress option bootstrap rows for local/offline use",
        refreshed_options.len()
    );

    let mut requested_tables = db::load_state(&paths)?
        .materialized_tables
        .into_iter()
        .collect::<BTreeSet<_>>();
    if args.admin_password.is_some() {
        requested_tables.insert(format!("{}users", manifest.probe.table_prefix));
        requested_tables.insert(format!("{}usermeta", manifest.probe.table_prefix));
    }
    let requested_tables = requested_tables.into_iter().collect::<Vec<_>>();
    let tables = db::existing_local_tables(&manifest, &requested_tables)?;
    let skipped = requested_tables.len().saturating_sub(tables.len());
    if skipped > 0 {
        println!(
            "skipping {} previously materialized WordPress tables that are not present in the local schema",
            skipped
        );
    }
    let materialized = db::materialize_tables(&remote, &manifest, &paths, &tables)
        .context("materialize local offline database lower layer")?;
    println!(
        "materialized {} WordPress tables for local/offline use",
        materialized.len()
    );

    let admin = if let Some(password) = args.admin_password.as_deref() {
        let admin = db::set_local_admin_password(&manifest, args.admin_login.as_deref(), password)
            .context("set local administrator password")?;
        println!(
            "set local administrator password for '{}' without writing to the remote DB",
            admin.user_login
        );
        Some(admin.user_login)
    } else {
        None
    };

    if admin.is_some() {
        let warmed = runtime_cache::warm_runtime_code_cache_with_admin(&remote, &manifest, &paths)
            .context("cache WordPress runtime code for offline login")?;
        if warmed.files > 0 {
            println!(
                "cached {} bounded runtime code files for offline login",
                warmed.files
            );
        } else {
            let cached = cache_offline_core_runtime(&remote, &manifest, &paths)
                .context("cache WordPress core/admin runtime for offline login")?;
            println!("cached {cached} WordPress core/admin runtime files for offline login");
        }
    }

    let marker = OfflineMarker {
        severed_at_unix: SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap_or_default()
            .as_secs(),
        materialized_tables: tables,
        admin_user: admin,
    };
    write_offline_marker(&paths, &marker)?;
    generate::write_wordpress_overrides(&paths, &manifest)?;
    if let Err(err) = remote.stop_master() {
        eprintln!("warning: could not close SSH control master after severing: {err:#}");
    }

    println!(
        "severed clone '{}' from remote lower layers in {:.2}s",
        manifest.name,
        started.elapsed().as_secs_f64()
    );
    Ok(())
}

fn cache_offline_core_runtime(
    remote: &RemoteClient,
    manifest: &Manifest,
    paths: &crate::config::ClonePaths,
) -> Result<usize> {
    let overlay = OverlayStore::new(paths);
    let mut queue = VecDeque::from([PathBuf::new()]);
    let mut cached = 0_usize;

    while let Some(dir) = queue.pop_front() {
        let entries = match remote.readdir(&dir) {
            Ok(entries) => entries,
            Err(err) if err.kind() == std::io::ErrorKind::NotFound => continue,
            Err(err) => {
                return Err(err).with_context(|| {
                    format!(
                        "read remote runtime directory {}",
                        OverlayStore::rel_string(&dir)
                    )
                })
            }
        };

        for entry in entries {
            let rel = dir.join(&entry.name);
            let _ = overlay.put_cached_entry(&rel, &entry);

            if entry.kind == "dir" && should_descend_offline_core_runtime_dir(&rel) {
                queue.push_back(rel);
                continue;
            }

            if !should_cache_offline_core_runtime_file(&rel, &entry.kind) {
                continue;
            }
            if entry.size > manifest.cache_max_file_bytes {
                continue;
            }

            overlay
                .read_cached_or_remote_with_entry(
                    remote,
                    &rel,
                    0,
                    1,
                    manifest.cache_max_file_bytes,
                    Some(entry),
                )
                .with_context(|| {
                    format!(
                        "cache remote runtime file {}",
                        OverlayStore::rel_string(&rel)
                    )
                })?;
            cached += 1;
        }
    }

    Ok(cached)
}

fn should_descend_offline_core_runtime_dir(rel: &Path) -> bool {
    rel == Path::new("wp-admin")
        || rel.starts_with(Path::new("wp-admin/"))
        || rel == Path::new("wp-includes")
        || rel.starts_with(Path::new("wp-includes/"))
}

fn should_cache_offline_core_runtime_file(rel: &Path, kind: &str) -> bool {
    if kind != "file" {
        return false;
    }
    if rel.starts_with(Path::new("wp-content")) {
        return false;
    }
    if rel.starts_with(Path::new("wp-admin")) || rel.starts_with(Path::new("wp-includes")) {
        return true;
    }
    let Some(name) = rel.file_name().and_then(|name| name.to_str()) else {
        return false;
    };
    rel.parent()
        .is_none_or(|parent| parent.as_os_str().is_empty())
        && (name == "index.php" || (name.starts_with("wp-") && name.ends_with(".php")))
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

fn materialize_options_table_enabled() -> bool {
    std::env::var("WPCOW_MATERIALIZE_OPTIONS_TABLE")
        .ok()
        .map(|raw| {
            matches!(
                raw.to_ascii_lowercase().as_str(),
                "1" | "true" | "yes" | "on"
            )
        })
        .unwrap_or(true)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn offline_core_runtime_cache_is_bounded_to_wordpress_core() {
        assert!(should_cache_offline_core_runtime_file(
            Path::new("wp-login.php"),
            "file"
        ));
        assert!(should_cache_offline_core_runtime_file(
            Path::new("wp-admin/admin.php"),
            "file"
        ));
        assert!(should_cache_offline_core_runtime_file(
            Path::new("wp-includes/version.php"),
            "file"
        ));
        assert!(should_descend_offline_core_runtime_dir(Path::new(
            "wp-admin/includes"
        )));
        assert!(should_descend_offline_core_runtime_dir(Path::new(
            "wp-includes/blocks"
        )));

        assert!(!should_cache_offline_core_runtime_file(
            Path::new("wp-content/uploads/2026/05/large.mov"),
            "file"
        ));
        assert!(!should_cache_offline_core_runtime_file(
            Path::new("wp-content/plugins/woocommerce/woocommerce.php"),
            "file"
        ));
        assert!(!should_cache_offline_core_runtime_file(
            Path::new("wp-content/themes/neve/functions.php"),
            "file"
        ));
        assert!(!should_descend_offline_core_runtime_dir(Path::new(
            "wp-content/uploads"
        )));
    }
}

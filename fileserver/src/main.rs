mod mysql_proxy;
mod smb;
mod smb_proto;
mod sftp;
mod store;

use anyhow::Result;
use clap::Parser;
use std::path::PathBuf;
use std::sync::Arc;

#[derive(Parser, Debug)]
#[command(name = "fileserver", about = "SFTP + SMB2 + MySQL fileserver backed by branched-wp SQLite store")]
struct Cli {
    /// Path to the branched-wp SQLite database
    #[arg(long)]
    db: PathBuf,

    /// SFTP listen address
    #[arg(long, default_value = "0.0.0.0:2222")]
    sftp_addr: String,

    /// SMB2 listen address
    #[arg(long, default_value = "0.0.0.0:445")]
    smb_addr: String,

    /// MySQL proxy listen address
    #[arg(long, default_value = "0.0.0.0:3306")]
    mysql_addr: String,
}

#[tokio::main]
async fn main() -> Result<()> {
    env_logger::init();
    let cli = Cli::parse();

    let store = Arc::new(store::Store::open(&cli.db)?);

    // Keep WAL bounded over long-running sessions: auto-checkpoint (set in
    // Store::open) fires on its own once the WAL crosses ~500 pages, and
    // this periodic thread plus the shutdown checkpoint below truncate it
    // back to zero on a regular cadence. See TODO #5 / PRD F10.
    store.spawn_periodic_checkpoint(std::time::Duration::from_secs(30));

    let sftp_store = Arc::clone(&store);
    let sftp_addr = cli.sftp_addr.clone();
    let sftp_handle = tokio::spawn(async move {
        if let Err(e) = sftp::run_sftp_server(&sftp_addr, sftp_store).await {
            log::error!("SFTP server error: {}", e);
        }
    });

    let smb_store = Arc::clone(&store);
    let smb_addr = cli.smb_addr.clone();
    let smb_handle = tokio::spawn(async move {
        if let Err(e) = smb::run_smb_server(&smb_addr, smb_store).await {
            log::error!("SMB2 server error: {}", e);
        }
    });

    let mysql_store = Arc::clone(&store);
    let mysql_addr = cli.mysql_addr.clone();
    let mysql_handle = tokio::spawn(async move {
        if let Err(e) = mysql_proxy::run_mysql_proxy(&mysql_addr, mysql_store).await {
            log::error!("MySQL proxy error: {}", e);
        }
    });

    // Wait for Ctrl-C or any server to exit, whichever comes first, then
    // flush the WAL before returning so the .fp file on disk is a
    // complete, checkpointed snapshot — important for `cp site.fp` in
    // operations and for a fast restart (nothing to replay from a -wal).
    tokio::select! {
        _ = tokio::signal::ctrl_c() => {
            log::info!("fileserver: received Ctrl-C, shutting down");
        }
        _ = sftp_handle  => {},
        _ = smb_handle   => {},
        _ = mysql_handle => {},
    }

    if let Err(e) = store.checkpoint_truncate() {
        log::warn!("fileserver: shutdown wal_checkpoint failed: {}", e);
    }

    Ok(())
}

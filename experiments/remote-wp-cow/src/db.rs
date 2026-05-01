use anyhow::{anyhow, Context, Result};
use serde::{Deserialize, Serialize};
use std::collections::BTreeSet;
use std::fs::{self, File};
use std::io::{self, Read};
use std::path::PathBuf;
use std::process::{Command, Stdio};

use crate::config::{parse_host_port, ClonePaths, Manifest};
use crate::remote::{shell_quote, RemoteClient, RemoteQueryResult};
use crate::sql;

#[derive(Debug, Default, Serialize, Deserialize)]
pub struct DbState {
    pub materialized_tables: BTreeSet<String>,
}

pub fn state_path(paths: &ClonePaths) -> PathBuf {
    paths.db.join("state.json")
}

pub fn load_state(paths: &ClonePaths) -> Result<DbState> {
    let path = state_path(paths);
    if !path.exists() {
        return Ok(DbState::default());
    }
    let mut json = String::new();
    File::open(path)?.read_to_string(&mut json)?;
    Ok(serde_json::from_str(&json)?)
}

pub fn write_state(paths: &ClonePaths, state: &DbState) -> Result<()> {
    fs::create_dir_all(&paths.db)?;
    let json = serde_json::to_vec_pretty(state)?;
    fs::write(state_path(paths), [json, b"\n".to_vec()].concat())?;
    Ok(())
}

pub fn export_schema(remote: &RemoteClient, paths: &ClonePaths) -> Result<()> {
    let probe = &remote.manifest().probe;
    ensure_probe_has_db(probe)?;
    fs::create_dir_all(&paths.db)?;
    let command = format!(
        "MYSQL_PWD={} mysqldump {} --user={} --no-data --skip-lock-tables {}",
        shell_quote(&probe.db_password),
        remote_mysql_cli_options(&probe.db_host),
        shell_quote(&probe.db_user),
        shell_quote(&probe.db_name)
    );
    let schema = remote
        .exec_capture(&command, None)
        .context("export remote schema with mysqldump")?;
    fs::write(paths.db.join("schema.sql"), schema)?;
    Ok(())
}

pub fn init_local_db(manifest: &Manifest, paths: &ClonePaths) -> Result<()> {
    let schema = paths.db.join("schema.sql");
    if !schema.exists() {
        return Err(anyhow!(
            "{} does not exist; run clone without --skip-schema or materialize a table first",
            schema.display()
        ));
    }

    let create_sql = format!(
        "CREATE DATABASE IF NOT EXISTS `{}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
        manifest.local_db.name.replace('`', "``")
    );
    run_mysql_exec(manifest, &create_sql)?;

    let mut mysql = local_mysql_command(manifest);
    mysql.arg(&manifest.local_db.name).stdin(Stdio::piped());
    let mut child = mysql
        .spawn()
        .context("start local mysql for schema import")?;
    let mut stdin = child.stdin.take().expect("mysql stdin piped");
    let mut schema_file = File::open(&schema)?;
    io::copy(&mut schema_file, &mut stdin)?;
    drop(stdin);
    let status = child.wait()?;
    if !status.success() {
        return Err(anyhow!(
            "local mysql schema import failed with status {}",
            status
        ));
    }
    Ok(())
}

pub fn materialize_tables(
    remote: &RemoteClient,
    manifest: &Manifest,
    paths: &ClonePaths,
    tables: &[String],
) -> Result<Vec<String>> {
    let expanded = sql::expand_wordpress_groups(&manifest.probe.table_prefix, tables);
    let mut state = load_state(paths)?;
    let mut changed = Vec::new();

    for table in expanded {
        validate_table_name(&table)?;
        if state.materialized_tables.contains(&table) {
            continue;
        }
        materialize_one_table(remote, manifest, &table)
            .with_context(|| format!("materialize table {}", table))?;
        state.materialized_tables.insert(table.clone());
        changed.push(table);
    }

    write_state(paths, &state)?;
    Ok(changed)
}

pub fn route_for_tables(
    remote: &RemoteClient,
    manifest: &Manifest,
    paths: &ClonePaths,
    tables: &[String],
) -> Result<RouteDecision> {
    let state = load_state(paths)?;
    let expanded = sql::expand_wordpress_groups(&manifest.probe.table_prefix, tables);
    let touches_local = expanded
        .iter()
        .any(|table| state.materialized_tables.contains(table));

    if touches_local {
        let materialized = materialize_tables(remote, manifest, paths, &expanded)?;
        Ok(RouteDecision {
            backend: "local".to_string(),
            materialized,
        })
    } else {
        Ok(RouteDecision {
            backend: "remote".to_string(),
            materialized: Vec::new(),
        })
    }
}

pub fn remote_readonly_query(remote: &RemoteClient, sql_text: &str) -> Result<RemoteQueryResult> {
    if !sql::is_safe_read_sql(sql_text) || sql::is_write_sql(sql_text) {
        return Err(anyhow!("refusing to send non-read SQL to remote"));
    }
    remote.remote_query_readonly(sql_text)
}

#[derive(Debug, Serialize)]
pub struct RouteDecision {
    pub backend: String,
    pub materialized: Vec<String>,
}

fn materialize_one_table(remote: &RemoteClient, manifest: &Manifest, table: &str) -> Result<()> {
    let probe = &manifest.probe;
    ensure_probe_has_db(probe)?;
    let delete_sql = format!("DELETE FROM `{}`;", table.replace('`', "``"));
    run_mysql_exec(manifest, &delete_sql)?;

    let dump_command = format!(
        "MYSQL_PWD={} mysqldump {} --user={} --single-transaction --quick --skip-lock-tables --no-create-info --replace {} {}",
        shell_quote(&probe.db_password),
        remote_mysql_cli_options(&probe.db_host),
        shell_quote(&probe.db_user),
        shell_quote(&probe.db_name),
        shell_quote(table)
    );

    let mut ssh = remote
        .command(&dump_command)
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .context("start remote mysqldump over ssh")?;

    let mut mysql = local_mysql_command(manifest);
    mysql.arg(&manifest.local_db.name).stdin(Stdio::piped());
    let mut mysql_child = mysql.spawn().context("start local mysql import")?;

    {
        let mut ssh_stdout = ssh.stdout.take().expect("ssh stdout piped");
        let mut mysql_stdin = mysql_child.stdin.take().expect("mysql stdin piped");
        io::copy(&mut ssh_stdout, &mut mysql_stdin)?;
    }

    let ssh_output = ssh.wait_with_output()?;
    let mysql_status = mysql_child.wait()?;

    if !ssh_output.status.success() {
        return Err(anyhow!(
            "remote mysqldump failed: {}",
            String::from_utf8_lossy(&ssh_output.stderr)
        ));
    }
    if !mysql_status.success() {
        return Err(anyhow!(
            "local mysql import failed with status {}",
            mysql_status
        ));
    }
    Ok(())
}

fn local_mysql_command(manifest: &Manifest) -> Command {
    let mut command = Command::new("mysql");
    command.arg("--host").arg(&manifest.local_db.host);
    command
        .arg("--port")
        .arg(manifest.local_db.port.to_string());
    command.arg("--user").arg(&manifest.local_db.user);
    if !manifest.local_db.password.is_empty() {
        command.env("MYSQL_PWD", &manifest.local_db.password);
    }
    command
}

fn run_mysql_exec(manifest: &Manifest, sql_text: &str) -> Result<()> {
    let mut command = local_mysql_command(manifest);
    command.arg("--execute").arg(sql_text);
    let status = command.status().context("run local mysql")?;
    if !status.success() {
        return Err(anyhow!("local mysql failed with status {}", status));
    }
    Ok(())
}

fn validate_table_name(table: &str) -> Result<()> {
    if table.is_empty()
        || !table
            .chars()
            .all(|ch| ch.is_ascii_alphanumeric() || ch == '_' || ch == '$')
    {
        return Err(anyhow!("unsafe table name {}", table));
    }
    Ok(())
}

fn ensure_probe_has_db(probe: &crate::config::Probe) -> Result<()> {
    if probe.db_name.is_empty() || probe.db_user.is_empty() || probe.db_host.is_empty() {
        return Err(anyhow!("remote probe did not return database credentials"));
    }
    Ok(())
}

fn remote_mysql_cli_options(db_host: &str) -> String {
    if let Some(idx) = db_host.find(":/") {
        let host = &db_host[..idx];
        let socket = &db_host[idx + 1..];
        return format!(
            "--host={} --socket={}",
            shell_quote(host),
            shell_quote(socket)
        );
    }

    if let Some((host, port)) = db_host.rsplit_once(':') {
        if port.parse::<u16>().is_ok() {
            return format!("--host={} --port={}", shell_quote(host), shell_quote(port));
        }
    }

    format!("--host={}", shell_quote(db_host))
}

#[allow(dead_code)]
pub fn local_db_host_port(manifest: &Manifest) -> (String, u16) {
    parse_host_port(
        &format!("{}:{}", manifest.local_db.host, manifest.local_db.port),
        manifest.local_db.port,
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn rejects_unsafe_table_names() {
        assert!(validate_table_name("wp_posts").is_ok());
        assert!(validate_table_name("wp-posts").is_err());
        assert!(validate_table_name("wp_posts;DROP").is_err());
    }

    #[test]
    fn formats_remote_mysql_host_variants() {
        assert_eq!(remote_mysql_cli_options("localhost"), "--host='localhost'");
        assert_eq!(
            remote_mysql_cli_options("db.example.com:3307"),
            "--host='db.example.com' --port='3307'"
        );
        assert_eq!(
            remote_mysql_cli_options("localhost:/tmp/mysql.sock"),
            "--host='localhost' --socket='/tmp/mysql.sock'"
        );
    }
}

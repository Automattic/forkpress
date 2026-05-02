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
    #[serde(default)]
    pub materialized_tables: BTreeSet<String>,
    #[serde(default)]
    pub option_bootstrap_tables: BTreeSet<String>,
    #[serde(default)]
    pub option_rows: BTreeSet<String>,
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

pub fn init_local_db_if_empty(manifest: &Manifest, paths: &ClonePaths) -> Result<bool> {
    if local_schema_table_count(manifest)? > 0 {
        return Ok(false);
    }

    init_local_db(manifest, paths)?;
    Ok(true)
}

pub fn local_schema_table_count(manifest: &Manifest) -> Result<u64> {
    let sql_text = format!(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{}';",
        mysql_string_literal(&manifest.local_db.name)
    );
    let output = local_mysql_command(manifest)
        .arg("--batch")
        .arg("--skip-column-names")
        .arg("--execute")
        .arg(sql_text)
        .output()
        .context("query local mysql schema state")?;
    if !output.status.success() {
        return Err(anyhow!(
            "local mysql schema state query failed: {}",
            String::from_utf8_lossy(&output.stderr)
        ));
    }

    let stdout = String::from_utf8_lossy(&output.stdout);
    stdout
        .trim()
        .parse::<u64>()
        .with_context(|| format!("parse local table count from {}", stdout.trim()))
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

pub fn route_for_query(
    remote: &RemoteClient,
    manifest: &Manifest,
    paths: &ClonePaths,
    sql_text: &str,
    tables: &[String],
) -> Result<RouteDecision> {
    let expanded = sql::expand_wordpress_groups(&manifest.probe.table_prefix, tables);
    let mut state = load_state(paths)?;

    if let Some(options_table) =
        option_bootstrap_table_for_sql(&manifest.probe.table_prefix, sql_text, &expanded)
    {
        if !state.option_bootstrap_tables.contains(&options_table) {
            materialize_option_bootstrap(remote, manifest, &options_table).with_context(|| {
                format!("materialize option bootstrap rows for {}", options_table)
            })?;
            state.option_bootstrap_tables.insert(options_table);
            write_state(paths, &state)?;
        }
        return Ok(RouteDecision {
            backend: "local".to_string(),
            materialized: Vec::new(),
        });
    }

    let options_table = format!("{}options", manifest.probe.table_prefix);
    let option_names = option_names_for_sql(sql_text, &options_table, &expanded);
    if !option_names.is_empty() {
        materialize_option_rows(remote, manifest, &mut state, &options_table, &option_names)
            .with_context(|| format!("materialize option rows for {}", options_table))?;
        write_state(paths, &state)?;
        return Ok(RouteDecision {
            backend: "local".to_string(),
            materialized: Vec::new(),
        });
    }

    route_for_tables(remote, manifest, paths, tables)
}

pub fn remote_readonly_query(remote: &RemoteClient, sql_text: &str) -> Result<RemoteQueryResult> {
    if !sql::is_safe_read_sql(sql_text) || sql::is_write_sql(sql_text) {
        return Err(anyhow!("refusing to send non-read SQL to remote"));
    }
    remote.remote_query_readonly(sql_text)
}

pub fn local_option_value(manifest: &Manifest, name: &str) -> Result<Option<String>> {
    let table = format!("{}options", manifest.probe.table_prefix);
    validate_table_name(&table)?;
    let sql_text = format!(
        "SELECT option_value FROM {} WHERE option_name='{}' LIMIT 1;",
        qualified_table(manifest, &table),
        mysql_string_literal(name)
    );
    let output = local_mysql_command(manifest)
        .arg("--batch")
        .arg("--raw")
        .arg("--skip-column-names")
        .arg("--execute")
        .arg(sql_text)
        .output()
        .context("query local option value")?;
    if !output.status.success() {
        return Ok(None);
    }
    let value = String::from_utf8_lossy(&output.stdout)
        .lines()
        .next()
        .map(|line| line.to_string());
    Ok(value)
}

#[derive(Debug, Serialize)]
pub struct RouteDecision {
    pub backend: String,
    pub materialized: Vec<String>,
}

fn materialize_one_table(remote: &RemoteClient, manifest: &Manifest, table: &str) -> Result<()> {
    let probe = &manifest.probe;
    ensure_probe_has_db(probe)?;
    let delete_sql = format!("DELETE FROM {};", qualified_table(manifest, table));
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

fn materialize_option_bootstrap(
    remote: &RemoteClient,
    manifest: &Manifest,
    table: &str,
) -> Result<()> {
    let probe = &manifest.probe;
    ensure_probe_has_db(probe)?;
    validate_table_name(table)?;

    let where_sql = option_bootstrap_where_sql();
    let delete_sql = format!(
        "DELETE FROM {} WHERE {};",
        qualified_table(manifest, table),
        where_sql
    );
    run_mysql_exec(manifest, &delete_sql)?;

    let dump_command = format!(
        "MYSQL_PWD={} mysqldump {} --user={} --single-transaction --quick --skip-lock-tables --no-create-info --replace --where={} {} {}",
        shell_quote(&probe.db_password),
        remote_mysql_cli_options(&probe.db_host),
        shell_quote(&probe.db_user),
        shell_quote(&where_sql),
        shell_quote(&probe.db_name),
        shell_quote(table)
    );

    let mut ssh = remote
        .command(&dump_command)
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .context("start remote option bootstrap mysqldump over ssh")?;

    let mut mysql = local_mysql_command(manifest);
    mysql.arg(&manifest.local_db.name).stdin(Stdio::piped());
    let mut mysql_child = mysql.spawn().context("start local mysql option import")?;

    {
        let mut ssh_stdout = ssh.stdout.take().expect("ssh stdout piped");
        let mut mysql_stdin = mysql_child.stdin.take().expect("mysql stdin piped");
        io::copy(&mut ssh_stdout, &mut mysql_stdin)?;
    }

    let ssh_output = ssh.wait_with_output()?;
    let mysql_status = mysql_child.wait()?;

    if !ssh_output.status.success() {
        return Err(anyhow!(
            "remote option bootstrap mysqldump failed: {}",
            String::from_utf8_lossy(&ssh_output.stderr)
        ));
    }
    if !mysql_status.success() {
        return Err(anyhow!(
            "local mysql option bootstrap import failed with status {}",
            mysql_status
        ));
    }
    Ok(())
}

fn materialize_option_rows(
    remote: &RemoteClient,
    manifest: &Manifest,
    state: &mut DbState,
    table: &str,
    names: &[String],
) -> Result<()> {
    let probe = &manifest.probe;
    ensure_probe_has_db(probe)?;
    validate_table_name(table)?;

    let missing = names
        .iter()
        .filter(|name| !state.option_rows.contains(&option_row_key(table, name)))
        .cloned()
        .collect::<Vec<_>>();
    if missing.is_empty() {
        return Ok(());
    }

    let where_sql = option_names_where_sql(&missing);
    let delete_sql = format!(
        "DELETE FROM {} WHERE {};",
        qualified_table(manifest, table),
        where_sql
    );
    run_mysql_exec(manifest, &delete_sql)?;

    let dump_command = format!(
        "MYSQL_PWD={} mysqldump {} --user={} --single-transaction --quick --skip-lock-tables --no-create-info --replace --where={} {} {}",
        shell_quote(&probe.db_password),
        remote_mysql_cli_options(&probe.db_host),
        shell_quote(&probe.db_user),
        shell_quote(&where_sql),
        shell_quote(&probe.db_name),
        shell_quote(table)
    );

    let mut ssh = remote
        .command(&dump_command)
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .context("start remote option row mysqldump over ssh")?;

    let mut mysql = local_mysql_command(manifest);
    mysql.arg(&manifest.local_db.name).stdin(Stdio::piped());
    let mut mysql_child = mysql
        .spawn()
        .context("start local mysql option row import")?;

    {
        let mut ssh_stdout = ssh.stdout.take().expect("ssh stdout piped");
        let mut mysql_stdin = mysql_child.stdin.take().expect("mysql stdin piped");
        io::copy(&mut ssh_stdout, &mut mysql_stdin)?;
    }

    let ssh_output = ssh.wait_with_output()?;
    let mysql_status = mysql_child.wait()?;

    if !ssh_output.status.success() {
        return Err(anyhow!(
            "remote option row mysqldump failed: {}",
            String::from_utf8_lossy(&ssh_output.stderr)
        ));
    }
    if !mysql_status.success() {
        return Err(anyhow!(
            "local mysql option row import failed with status {}",
            mysql_status
        ));
    }

    for name in missing {
        state.option_rows.insert(option_row_key(table, &name));
    }
    Ok(())
}

fn option_bootstrap_table_for_sql(
    table_prefix: &str,
    sql_text: &str,
    tables: &[String],
) -> Option<String> {
    if !sql::is_safe_read_sql(sql_text) || sql::is_write_sql(sql_text) {
        return None;
    }

    let options_table = format!("{}options", table_prefix);
    if !tables.iter().any(|table| table == &options_table) {
        return None;
    }

    let lower = sql_text.to_ascii_lowercase();
    if lower.contains("autoload") {
        return Some(options_table);
    }

    if lower.contains("option_name")
        && option_bootstrap_names()
            .iter()
            .any(|name| lower.contains(&format!("'{}'", name)))
    {
        return Some(options_table);
    }

    None
}

fn option_names_for_sql(sql_text: &str, options_table: &str, tables: &[String]) -> Vec<String> {
    if !sql::is_safe_read_sql(sql_text) || sql::is_write_sql(sql_text) {
        return Vec::new();
    }
    if !tables.iter().any(|table| table == options_table) {
        return Vec::new();
    }

    let lower = sql_text.to_ascii_lowercase();
    let Some(option_name_pos) = lower.find("option_name") else {
        return Vec::new();
    };
    let tail = &sql_text[option_name_pos + "option_name".len()..];
    let lower_tail = &lower[option_name_pos + "option_name".len()..];

    if let Some(eq_pos) = lower_tail.find('=') {
        if lower_tail[..eq_pos]
            .chars()
            .all(|ch| ch.is_ascii_whitespace() || ch == '`')
        {
            return first_sql_string_literal(&tail[eq_pos + 1..])
                .into_iter()
                .collect();
        }
    }

    if let Some(in_pos) = lower_tail.find(" in ") {
        return sql_string_literals_until_closing_paren(&tail[in_pos + 4..]);
    }

    Vec::new()
}

fn option_bootstrap_where_sql() -> String {
    let names = option_bootstrap_names()
        .iter()
        .map(|name| format!("'{}'", mysql_string_literal(name)))
        .collect::<Vec<_>>()
        .join(", ");
    format!("autoload IN ('yes', 'on', 'auto-on', 'auto') OR option_name IN ({names})")
}

fn option_names_where_sql(names: &[String]) -> String {
    let names = names
        .iter()
        .map(|name| format!("'{}'", mysql_string_literal(name)))
        .collect::<Vec<_>>()
        .join(", ");
    format!("option_name IN ({names})")
}

fn option_row_key(table: &str, name: &str) -> String {
    format!("{table}:{name}")
}

fn option_bootstrap_names() -> &'static [&'static str] {
    &[
        "siteurl",
        "home",
        "blogname",
        "blogdescription",
        "admin_email",
        "active_plugins",
        "template",
        "stylesheet",
        "current_theme",
        "permalink_structure",
        "rewrite_rules",
        "sidebars_widgets",
        "stylesheet_root",
        "template_root",
        "upload_path",
        "upload_url_path",
    ]
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

fn qualified_table(manifest: &Manifest, table: &str) -> String {
    format!(
        "`{}`.`{}`",
        manifest.local_db.name.replace('`', "``"),
        table.replace('`', "``")
    )
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

fn mysql_string_literal(value: &str) -> String {
    value.replace('\\', "\\\\").replace('\'', "\\'")
}

fn first_sql_string_literal(input: &str) -> Option<String> {
    sql_string_literals_until_closing_paren(input)
        .into_iter()
        .next()
}

fn sql_string_literals_until_closing_paren(input: &str) -> Vec<String> {
    let mut out = Vec::new();
    let mut chars = input.chars().peekable();
    while let Some(ch) = chars.next() {
        if ch == ')' {
            break;
        }
        if ch != '\'' {
            continue;
        }
        let mut value = String::new();
        while let Some(ch) = chars.next() {
            if ch == '\\' {
                if let Some(next) = chars.next() {
                    value.push(next);
                }
                continue;
            }
            if ch == '\'' {
                if chars.peek() == Some(&'\'') {
                    let _ = chars.next();
                    value.push('\'');
                    continue;
                }
                break;
            }
            value.push(ch);
        }
        if !value.is_empty() {
            out.push(value);
        }
    }
    out
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

    #[test]
    fn detects_option_bootstrap_reads() {
        let tables = vec!["ady_options".to_string()];
        assert_eq!(
            option_bootstrap_table_for_sql(
                "ady_",
                "SELECT option_name, option_value FROM ady_options WHERE autoload IN ( 'yes', 'on', 'auto-on', 'auto' )",
                &tables
            ),
            Some("ady_options".to_string())
        );
        assert_eq!(
            option_bootstrap_table_for_sql(
                "ady_",
                "SELECT option_value FROM ady_options WHERE option_name = 'siteurl' LIMIT 1",
                &tables
            ),
            Some("ady_options".to_string())
        );
        assert_eq!(
            option_bootstrap_table_for_sql(
                "ady_",
                "SELECT option_value FROM ady_options WHERE option_name = 'some_plugin_option' LIMIT 1",
                &tables
            ),
            None
        );
    }

    #[test]
    fn extracts_targeted_option_reads() {
        let tables = vec!["ady_options".to_string()];
        assert_eq!(
            option_names_for_sql(
                "SELECT option_value FROM ady_options WHERE option_name = 'aioseo_options_internal_localized' LIMIT 1",
                "ady_options",
                &tables
            ),
            vec!["aioseo_options_internal_localized".to_string()]
        );
        assert_eq!(
            option_names_for_sql(
                "SELECT * FROM ady_options WHERE option_name IN ('a', 'b')",
                "ady_options",
                &tables
            ),
            vec!["a".to_string(), "b".to_string()]
        );
    }

    #[test]
    fn qualifies_local_tables_for_exec_without_selected_database() {
        let manifest = Manifest {
            version: crate::config::MANIFEST_VERSION,
            name: "calm".to_string(),
            ssh: "example".to_string(),
            remote_path: "/srv/www".to_string(),
            remote_url: "https://example.com".to_string(),
            local_url: "http://localhost:9481".to_string(),
            created_at_unix: 1,
            probe: crate::config::Probe::default(),
            local_db: crate::config::LocalDb {
                name: "cow_calm".to_string(),
                user: "cow_calm".to_string(),
                password: String::new(),
                host: "127.0.0.1".to_string(),
                port: 33071,
            },
            remote_db_tunnel: crate::config::RemoteDbTunnel {
                host: "127.0.0.1".to_string(),
                port: 33072,
            },
            control_url: "http://127.0.0.1:39070".to_string(),
            cache_max_file_bytes: 1024,
            remote_metadata_cache_ttl_secs: 30,
        };
        assert_eq!(
            qualified_table(&manifest, "ady_options"),
            "`cow_calm`.`ady_options`"
        );
    }
}

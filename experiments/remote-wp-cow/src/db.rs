use anyhow::{anyhow, Context, Result};
use serde::{Deserialize, Serialize};
use std::collections::BTreeSet;
use std::fs::{self, File};
use std::io::{self, Read};
use std::path::PathBuf;
use std::process::{Command, Stdio};

use crate::config::{parse_host_port, ClonePaths, Manifest};
use crate::remote::{shell_quote, RemoteClient, RemoteQueryResult};
use crate::row_cow::{
    self, CowQueryResult, PkValue, Row, RowCowBackend, RowCowExecution, RowCowPlan,
};
use crate::sql;

#[derive(Debug, Default, Serialize, Deserialize)]
pub struct DbState {
    #[serde(default)]
    pub materialized_tables: BTreeSet<String>,
    #[serde(default)]
    pub option_bootstrap_tables: BTreeSet<String>,
    #[serde(default)]
    pub option_rows: BTreeSet<String>,
    #[serde(default)]
    pub dirty_option_rows: BTreeSet<String>,
}

#[derive(Debug, Clone, Serialize)]
pub struct LocalAdmin {
    pub id: u64,
    pub user_login: String,
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
        materialize_one_table(remote, manifest, paths, &table)
            .with_context(|| format!("materialize table {}", table))?;
        state.materialized_tables.insert(table.clone());
        changed.push(table);
    }

    write_state(paths, &state)?;
    Ok(changed)
}

pub fn existing_local_tables(manifest: &Manifest, tables: &[String]) -> Result<Vec<String>> {
    for table in tables {
        validate_table_name(table)?;
    }
    if tables.is_empty() {
        return Ok(Vec::new());
    }

    let in_list = tables
        .iter()
        .map(|table| format!("'{}'", mysql_string_literal(table)))
        .collect::<Vec<_>>()
        .join(", ");
    let sql_text = format!(
        "SELECT table_name FROM information_schema.tables \
         WHERE table_schema='{}' AND table_name IN ({});",
        mysql_string_literal(&manifest.local_db.name),
        in_list
    );
    let output = local_mysql_command(manifest)
        .arg("--batch")
        .arg("--raw")
        .arg("--skip-column-names")
        .arg("--execute")
        .arg(sql_text)
        .output()
        .context("query local WordPress table list")?;
    if !output.status.success() {
        return Err(anyhow!(
            "local table list query failed: {}",
            String::from_utf8_lossy(&output.stderr)
        ));
    }

    let present = String::from_utf8_lossy(&output.stdout)
        .lines()
        .map(|line| line.to_string())
        .collect::<BTreeSet<_>>();
    Ok(tables
        .iter()
        .filter(|table| present.contains(table.as_str()))
        .cloned()
        .collect())
}

pub fn set_local_admin_password(
    manifest: &Manifest,
    login: Option<&str>,
    password: &str,
) -> Result<LocalAdmin> {
    let users_table = format!("{}users", manifest.probe.table_prefix);
    let usermeta_table = format!("{}usermeta", manifest.probe.table_prefix);
    validate_table_name(&users_table)?;
    validate_table_name(&usermeta_table)?;

    let admin = if let Some(login) = login {
        local_user_by_login(manifest, &users_table, login)?
    } else {
        local_first_admin_user(manifest, &users_table, &usermeta_table)?
    };

    let update_sql = format!(
        "UPDATE {} SET user_pass=MD5('{}'), user_activation_key='' WHERE ID={};\
         DELETE FROM {} WHERE user_id={} AND meta_key='session_tokens';",
        qualified_table(manifest, &users_table),
        mysql_string_literal(password),
        admin.id,
        qualified_table(manifest, &usermeta_table),
        admin.id
    );
    run_mysql_exec(manifest, &update_sql)?;
    Ok(admin)
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
            let excluded = dirty_option_names_for_table(&state, &options_table);
            materialize_option_bootstrap(remote, manifest, &options_table, &excluded)
                .with_context(|| {
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

pub fn refresh_option_bootstrap_for_offline(
    remote: &RemoteClient,
    manifest: &Manifest,
    paths: &ClonePaths,
) -> Result<Vec<String>> {
    let table = format!("{}options", manifest.probe.table_prefix);
    validate_table_name(&table)?;

    let mut state = load_state(paths)?;
    let excluded = dirty_option_names_for_table(&state, &table);
    materialize_option_bootstrap(remote, manifest, &table, &excluded)
        .with_context(|| format!("refresh option bootstrap rows for {}", table))?;

    state.option_bootstrap_tables.insert(table.clone());
    for name in option_bootstrap_names() {
        if !excluded.iter().any(|excluded_name| excluded_name == name) {
            state.option_rows.insert(option_row_key(&table, name));
        }
    }
    write_state(paths, &state)?;
    Ok(option_bootstrap_names()
        .iter()
        .filter(|name| !excluded.iter().any(|excluded_name| excluded_name == *name))
        .map(|name| (*name).to_string())
        .collect())
}

#[derive(Debug, Serialize)]
pub struct RouteDecision {
    pub backend: String,
    pub materialized: Vec<String>,
}

#[derive(Debug, Serialize)]
pub struct RowCowResponse {
    pub handled: bool,
    pub backend: String,
    pub materialized: Vec<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub fallback: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub result: Option<CowQueryResult>,
}

pub fn row_cow_query(
    remote: &RemoteClient,
    manifest: &Manifest,
    paths: &ClonePaths,
    sql_text: &str,
    tables: &[String],
) -> Result<RowCowResponse> {
    let mut backend = MysqlRowCowBackend { remote, manifest };
    match row_cow::execute_row_cow(&mut backend, sql_text)? {
        RowCowExecution::Select(result) => Ok(RowCowResponse {
            handled: true,
            backend: "cow".to_string(),
            materialized: Vec::new(),
            fallback: None,
            result: Some(result),
        }),
        RowCowExecution::PreparedLocalWrite {
            table,
            pk_column,
            pk_values,
            ..
        } => {
            mark_dirty_option_rows_for_write(
                manifest,
                paths,
                &table,
                pk_column.as_deref(),
                &pk_values,
            )?;
            Ok(RowCowResponse {
                handled: true,
                backend: "local".to_string(),
                materialized: Vec::new(),
                fallback: None,
                result: None,
            })
        }
        RowCowExecution::LocalOnlyInsert { table } => {
            mark_dirty_option_rows_from_sql(manifest, paths, sql_text, &table)?;
            Ok(RowCowResponse {
                handled: true,
                backend: "local".to_string(),
                materialized: Vec::new(),
                fallback: None,
                result: None,
            })
        }
        RowCowExecution::Fallback(plan) => {
            let (fallback, plan_tables) = fallback_name_and_tables(plan);
            if should_materialize_row_cow_fallback(sql_text, &fallback, &plan_tables) {
                let materialized = materialize_tables(remote, manifest, paths, &plan_tables)?;
                return Ok(RowCowResponse {
                    handled: false,
                    backend: "local".to_string(),
                    materialized,
                    fallback: Some(fallback),
                    result: None,
                });
            }

            if !tables.is_empty() && sql::is_write_sql(sql_text) {
                let materialized = materialize_tables(remote, manifest, paths, tables)?;
                return Ok(RowCowResponse {
                    handled: false,
                    backend: "local".to_string(),
                    materialized,
                    fallback: Some(fallback),
                    result: None,
                });
            }

            Ok(RowCowResponse {
                handled: false,
                backend: "fallback".to_string(),
                materialized: Vec::new(),
                fallback: Some(fallback),
                result: None,
            })
        }
    }
}

fn mark_dirty_option_rows_for_write(
    manifest: &Manifest,
    paths: &ClonePaths,
    table: &str,
    pk_column: Option<&str>,
    pk_values: &[PkValue],
) -> Result<()> {
    let options_table = format!("{}options", manifest.probe.table_prefix);
    if table != options_table
        || !pk_column.is_some_and(|column| column.eq_ignore_ascii_case("option_name"))
    {
        return Ok(());
    }

    let mut state = load_state(paths)?;
    for value in pk_values {
        state
            .dirty_option_rows
            .insert(option_row_key(table, &value.0));
    }
    write_state(paths, &state)
}

fn mark_dirty_option_rows_from_sql(
    manifest: &Manifest,
    paths: &ClonePaths,
    sql_text: &str,
    table: &str,
) -> Result<()> {
    let options_table = format!("{}options", manifest.probe.table_prefix);
    if table != options_table {
        return Ok(());
    }
    let names = option_write_names_for_sql(sql_text, &options_table, &[options_table.clone()]);
    if names.is_empty() {
        return Ok(());
    }

    let mut state = load_state(paths)?;
    for name in names {
        state.dirty_option_rows.insert(option_row_key(table, &name));
    }
    write_state(paths, &state)
}

fn should_materialize_row_cow_fallback(
    sql_text: &str,
    fallback: &str,
    plan_tables: &[String],
) -> bool {
    fallback == "PromoteTable" && !plan_tables.is_empty() && sql::is_write_sql(sql_text)
}

fn fallback_name_and_tables(plan: RowCowPlan) -> (String, Vec<String>) {
    match plan {
        RowCowPlan::PromoteTable { tables, .. } => ("PromoteTable".to_string(), tables),
        RowCowPlan::Unsupported { .. } => ("Unsupported".to_string(), Vec::new()),
        RowCowPlan::RowLevel(_) => ("RowLevel".to_string(), Vec::new()),
    }
}

struct MysqlRowCowBackend<'a> {
    remote: &'a RemoteClient,
    manifest: &'a Manifest,
}

impl RowCowBackend for MysqlRowCowBackend<'_> {
    fn remote_select_by_pk(
        &mut self,
        table: &str,
        pk_column: &str,
        pk_values: &[PkValue],
    ) -> Result<CowQueryResult> {
        validate_table_name(table)?;
        let sql_text = row_cow::select_all_by_pk_sql(table, pk_column, pk_values)?;
        let result = remote_readonly_query(self.remote, &sql_text)?;
        if !result.ok {
            return Err(anyhow!("remote row-COW select failed: {}", result.error));
        }
        Ok(remote_query_to_cow_result(result))
    }

    fn local_select_by_pk(
        &mut self,
        table: &str,
        pk_column: &str,
        pk_values: &[PkValue],
    ) -> Result<CowQueryResult> {
        validate_table_name(table)?;
        let sql_text = format!(
            "SELECT * FROM {} WHERE {};",
            qualified_table(self.manifest, table),
            row_cow::pk_values_where_sql(pk_column, pk_values)?
        );
        local_query_result(self.manifest, &sql_text)
    }

    fn local_upsert_rows(&mut self, table: &str, rows: &[Row]) -> Result<usize> {
        validate_table_name(table)?;
        if rows.is_empty() {
            return Ok(0);
        }

        let mut columns = Vec::new();
        for row in rows {
            for column in row.keys() {
                if !columns.iter().any(|existing| existing == column) {
                    columns.push(column.clone());
                }
            }
        }

        let column_sql = columns
            .iter()
            .map(|column| row_cow::quote_identifier(column))
            .collect::<Result<Vec<_>>>()?
            .join(", ");
        let values_sql = rows
            .iter()
            .map(|row| {
                let values = columns
                    .iter()
                    .map(|column| mysql_json_value(row.get(column)))
                    .collect::<Vec<_>>()
                    .join(", ");
                format!("({values})")
            })
            .collect::<Vec<_>>()
            .join(", ");
        let sql_text = format!(
            "REPLACE INTO {} ({column_sql}) VALUES {values_sql};",
            qualified_table(self.manifest, table),
        );
        run_mysql_exec(self.manifest, &sql_text)?;
        Ok(rows.len())
    }

    fn local_delete_by_pk(
        &mut self,
        table: &str,
        pk_column: &str,
        pk_values: &[PkValue],
    ) -> Result<usize> {
        validate_table_name(table)?;
        let sql_text = format!(
            "DELETE FROM {} WHERE {};",
            qualified_table(self.manifest, table),
            row_cow::pk_values_where_sql(pk_column, pk_values)?
        );
        run_mysql_exec(self.manifest, &sql_text)?;
        Ok(pk_values.len())
    }

    fn local_tombstone_by_pk(
        &mut self,
        table: &str,
        pk_column: &str,
        pk_values: &[PkValue],
    ) -> Result<usize> {
        validate_table_name(table)?;
        ensure_row_cow_meta_table(self.manifest)?;
        if pk_values.is_empty() {
            return Ok(0);
        }

        let values_sql = pk_values
            .iter()
            .map(|value| {
                format!(
                    "('{}', '{}', '{}')",
                    mysql_string_literal(table),
                    mysql_string_literal(pk_column),
                    mysql_string_literal(&value.0)
                )
            })
            .collect::<Vec<_>>()
            .join(", ");
        let sql_text = format!(
            "REPLACE INTO {} (table_name, pk_column, pk_value) VALUES {values_sql};",
            qualified_table(self.manifest, ROW_COW_TOMBSTONE_TABLE)
        );
        run_mysql_exec(self.manifest, &sql_text)?;
        Ok(pk_values.len())
    }

    fn local_clear_tombstone_by_pk(
        &mut self,
        table: &str,
        pk_column: &str,
        pk_values: &[PkValue],
    ) -> Result<usize> {
        validate_table_name(table)?;
        ensure_row_cow_meta_table(self.manifest)?;
        if pk_values.is_empty() {
            return Ok(0);
        }

        let sql_text = format!(
            "DELETE FROM {} WHERE table_name='{}' AND pk_column='{}' AND {};",
            qualified_table(self.manifest, ROW_COW_TOMBSTONE_TABLE),
            mysql_string_literal(table),
            mysql_string_literal(pk_column),
            row_cow::pk_values_where_sql("pk_value", pk_values)?
        );
        run_mysql_exec(self.manifest, &sql_text)?;
        Ok(pk_values.len())
    }

    fn local_reserve_insert_pk(&mut self, table: &str, pk_column: Option<&str>) -> Result<()> {
        let Some(pk_column) = pk_column else {
            return Ok(());
        };
        if !row_cow::is_auto_increment_pk_for_table(table, pk_column) {
            return Ok(());
        }
        reserve_local_auto_increment(self.remote, self.manifest, table, pk_column)
    }

    fn local_tombstones_by_pk(
        &mut self,
        table: &str,
        pk_column: &str,
        pk_values: &[PkValue],
    ) -> Result<BTreeSet<PkValue>> {
        validate_table_name(table)?;
        ensure_row_cow_meta_table(self.manifest)?;
        if pk_values.is_empty() {
            return Ok(BTreeSet::new());
        }

        let sql_text = format!(
            "SELECT pk_value FROM {} WHERE table_name='{}' AND pk_column='{}' AND {};",
            qualified_table(self.manifest, ROW_COW_TOMBSTONE_TABLE),
            mysql_string_literal(table),
            mysql_string_literal(pk_column),
            row_cow::pk_values_where_sql("pk_value", pk_values)?
        );
        let result = local_query_result(self.manifest, &sql_text)?;
        Ok(result
            .rows
            .into_iter()
            .filter_map(|row| {
                row.get("pk_value")
                    .and_then(|value| value.as_str())
                    .map(str::to_string)
            })
            .map(PkValue)
            .collect())
    }
}

const ROW_COW_TOMBSTONE_TABLE: &str = "_wp_cow_row_tombstones";

#[derive(Debug, Clone, PartialEq, Eq)]
struct TombstoneGroup {
    pk_column: String,
    pk_values: Vec<PkValue>,
}

fn ensure_row_cow_meta_table(manifest: &Manifest) -> Result<()> {
    let sql_text = format!(
        "CREATE TABLE IF NOT EXISTS {} (\
         table_name varchar(191) NOT NULL,\
         pk_column varchar(64) NOT NULL,\
         pk_value varchar(191) NOT NULL,\
         deleted_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,\
         PRIMARY KEY (table_name, pk_column, pk_value)\
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        qualified_table(manifest, ROW_COW_TOMBSTONE_TABLE)
    );
    run_mysql_exec(manifest, &sql_text)
}

fn remote_query_to_cow_result(result: RemoteQueryResult) -> CowQueryResult {
    CowQueryResult {
        ok: result.ok,
        error: result.error,
        rows: result.rows,
        fields: result.fields,
        affected: result.affected,
    }
}

fn reserve_local_auto_increment(
    remote: &RemoteClient,
    manifest: &Manifest,
    table: &str,
    pk_column: &str,
) -> Result<()> {
    validate_table_name(table)?;
    validate_table_name(pk_column)?;
    let remote_max = remote_max_pk(remote, table, pk_column)
        .with_context(|| format!("read remote max primary key for {}", table))?;
    let local_max = local_max_pk(manifest, table, pk_column)
        .with_context(|| format!("read local max primary key for {}", table))?;
    let Some(next_id) = remote_max.max(local_max).checked_add(1) else {
        return Ok(());
    };
    if next_id <= 1 {
        return Ok(());
    }
    let sql_text = format!(
        "ALTER TABLE {} AUTO_INCREMENT = {};",
        qualified_table(manifest, table),
        next_id
    );
    run_mysql_exec(manifest, &sql_text)
}

fn remote_max_pk(remote: &RemoteClient, table: &str, pk_column: &str) -> Result<u64> {
    let sql_text = format!(
        "SELECT MAX({}) AS max_pk FROM {};",
        row_cow::quote_identifier(pk_column)?,
        row_cow::quote_identifier(table)?
    );
    let result = remote_readonly_query(remote, &sql_text)?;
    if !result.ok {
        return Err(anyhow!(
            "remote max primary key query failed: {}",
            result.error
        ));
    }
    max_pk_from_rows(&result.rows, "max_pk")
}

fn local_max_pk(manifest: &Manifest, table: &str, pk_column: &str) -> Result<u64> {
    let sql_text = format!(
        "SELECT MAX({}) AS max_pk FROM {};",
        row_cow::quote_identifier(pk_column)?,
        qualified_table(manifest, table)
    );
    let result = local_query_result(manifest, &sql_text)?;
    max_pk_from_rows(&result.rows, "max_pk")
}

fn max_pk_from_rows(rows: &[Row], field: &str) -> Result<u64> {
    let Some(value) = rows.first().and_then(|row| row.get(field)) else {
        return Ok(0);
    };
    match value {
        serde_json::Value::Null => Ok(0),
        serde_json::Value::Number(number) => Ok(number.as_u64().unwrap_or(0)),
        serde_json::Value::String(raw) => {
            let raw = raw.trim();
            if raw.is_empty() || raw.eq_ignore_ascii_case("null") {
                Ok(0)
            } else {
                raw.parse::<u64>()
                    .with_context(|| format!("parse max primary key value from {}", raw))
            }
        }
        _ => Ok(0),
    }
}

fn mysql_json_value(value: Option<&serde_json::Value>) -> String {
    match value {
        None | Some(serde_json::Value::Null) => "NULL".to_string(),
        Some(serde_json::Value::Bool(value)) => {
            if *value {
                "1".to_string()
            } else {
                "0".to_string()
            }
        }
        Some(serde_json::Value::Number(value)) => value.to_string(),
        Some(serde_json::Value::String(value)) => {
            format!("'{}'", mysql_string_literal(value))
        }
        Some(value) => format!("'{}'", mysql_string_literal(&value.to_string())),
    }
}

fn materialize_one_table(
    remote: &RemoteClient,
    manifest: &Manifest,
    paths: &ClonePaths,
    table: &str,
) -> Result<()> {
    validate_table_name(table)?;
    fs::create_dir_all(&paths.db)?;
    let overlay_dump = paths.db.join(format!(
        ".wp-cow-local-overlay-{}-{}.sql",
        std::process::id(),
        table
    ));
    dump_local_table_overlay(manifest, table, &overlay_dump)
        .with_context(|| format!("dump local overlay rows for {}", table))?;
    let tombstones = local_row_cow_tombstones_for_table(manifest, table)
        .with_context(|| format!("load local row tombstones for {}", table))?;

    let materialized = materialize_remote_table(remote, manifest, table)
        .with_context(|| format!("import remote lower table {}", table));
    if let Err(err) = materialized {
        let _ = import_sql_file(manifest, &overlay_dump);
        let _ = fs::remove_file(&overlay_dump);
        return Err(err);
    }

    import_sql_file(manifest, &overlay_dump)
        .with_context(|| format!("restore local overlay rows for {}", table))?;
    apply_row_cow_tombstones(manifest, table, &tombstones)
        .with_context(|| format!("apply local row tombstones for {}", table))?;
    fs::remove_file(&overlay_dump).with_context(|| format!("remove {}", overlay_dump.display()))?;
    Ok(())
}

fn materialize_remote_table(remote: &RemoteClient, manifest: &Manifest, table: &str) -> Result<()> {
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

fn dump_local_table_overlay(manifest: &Manifest, table: &str, path: &PathBuf) -> Result<()> {
    let dump_file = File::create(path).with_context(|| format!("create {}", path.display()))?;
    let mut dump = local_mysqldump_command(manifest);
    dump.arg("--single-transaction")
        .arg("--quick")
        .arg("--skip-lock-tables")
        .arg("--no-create-info")
        .arg("--replace")
        .arg(&manifest.local_db.name)
        .arg(table)
        .stdout(Stdio::from(dump_file))
        .stderr(Stdio::piped());

    let output = dump
        .spawn()
        .context("start local mysqldump overlay export")?
        .wait_with_output()
        .context("wait for local mysqldump overlay export")?;
    if !output.status.success() {
        return Err(anyhow!(
            "local mysqldump overlay export failed: {}",
            String::from_utf8_lossy(&output.stderr)
        ));
    }
    Ok(())
}

fn import_sql_file(manifest: &Manifest, path: &PathBuf) -> Result<()> {
    let input = File::open(path).with_context(|| format!("open {}", path.display()))?;
    let mut mysql = local_mysql_command(manifest);
    mysql
        .arg(&manifest.local_db.name)
        .stdin(Stdio::from(input))
        .stderr(Stdio::piped());
    let output = mysql
        .spawn()
        .context("start local mysql import")?
        .wait_with_output()
        .context("wait for local mysql import")?;
    if !output.status.success() {
        return Err(anyhow!(
            "local mysql import failed: {}",
            String::from_utf8_lossy(&output.stderr)
        ));
    }
    Ok(())
}

fn local_row_cow_tombstones_for_table(
    manifest: &Manifest,
    table: &str,
) -> Result<Vec<TombstoneGroup>> {
    validate_table_name(table)?;
    ensure_row_cow_meta_table(manifest)?;
    let sql_text = format!(
        "SELECT pk_column, pk_value FROM {} WHERE table_name='{}' ORDER BY pk_column, pk_value;",
        qualified_table(manifest, ROW_COW_TOMBSTONE_TABLE),
        mysql_string_literal(table)
    );
    let result = local_query_result(manifest, &sql_text)?;
    let mut grouped = std::collections::BTreeMap::<String, Vec<PkValue>>::new();
    for row in result.rows {
        let Some(pk_column) = row.get("pk_column").and_then(|value| value.as_str()) else {
            continue;
        };
        let Some(pk_value) = row.get("pk_value").and_then(|value| value.as_str()) else {
            continue;
        };
        grouped
            .entry(pk_column.to_string())
            .or_default()
            .push(PkValue(pk_value.to_string()));
    }

    Ok(grouped
        .into_iter()
        .map(|(pk_column, pk_values)| TombstoneGroup {
            pk_column,
            pk_values,
        })
        .collect())
}

fn apply_row_cow_tombstones(
    manifest: &Manifest,
    table: &str,
    tombstones: &[TombstoneGroup],
) -> Result<()> {
    for sql_text in row_cow_tombstone_delete_sqls(manifest, table, tombstones)? {
        run_mysql_exec(manifest, &sql_text)?;
    }
    Ok(())
}

fn row_cow_tombstone_delete_sqls(
    manifest: &Manifest,
    table: &str,
    tombstones: &[TombstoneGroup],
) -> Result<Vec<String>> {
    validate_table_name(table)?;
    tombstones
        .iter()
        .filter(|group| !group.pk_values.is_empty())
        .map(|group| {
            Ok(format!(
                "DELETE FROM {} WHERE {};",
                qualified_table(manifest, table),
                row_cow::pk_values_where_sql(&group.pk_column, &group.pk_values)?
            ))
        })
        .collect()
}

fn local_first_admin_user(
    manifest: &Manifest,
    users_table: &str,
    usermeta_table: &str,
) -> Result<LocalAdmin> {
    let capabilities_key = format!("{}capabilities", manifest.probe.table_prefix);
    let sql_text = format!(
        "SELECT u.ID, u.user_login \
         FROM {} u \
         JOIN {} m ON m.user_id = u.ID \
         WHERE m.meta_key = '{}' AND m.meta_value LIKE '%administrator%' \
         ORDER BY u.ID LIMIT 1;",
        qualified_table(manifest, users_table),
        qualified_table(manifest, usermeta_table),
        mysql_string_literal(&capabilities_key)
    );
    local_admin_from_query(manifest, &sql_text, "find local administrator user")
}

fn local_user_by_login(manifest: &Manifest, users_table: &str, login: &str) -> Result<LocalAdmin> {
    let sql_text = format!(
        "SELECT ID, user_login FROM {} WHERE user_login='{}' LIMIT 1;",
        qualified_table(manifest, users_table),
        mysql_string_literal(login)
    );
    local_admin_from_query(manifest, &sql_text, "find requested local user")
}

fn local_admin_from_query(
    manifest: &Manifest,
    sql_text: &str,
    context: &'static str,
) -> Result<LocalAdmin> {
    let output = local_mysql_command(manifest)
        .arg("--batch")
        .arg("--raw")
        .arg("--skip-column-names")
        .arg("--execute")
        .arg(sql_text)
        .output()
        .context(context)?;
    if !output.status.success() {
        return Err(anyhow!(
            "{} failed: {}",
            context,
            String::from_utf8_lossy(&output.stderr)
        ));
    }

    let stdout = String::from_utf8_lossy(&output.stdout);
    let line = stdout
        .lines()
        .next()
        .ok_or_else(|| anyhow!("no local administrator user found"))?;
    let (id, user_login) = line
        .split_once('\t')
        .ok_or_else(|| anyhow!("unexpected local user query output: {}", line))?;
    let id = id
        .parse::<u64>()
        .with_context(|| format!("parse local user id from {}", id))?;
    Ok(LocalAdmin {
        id,
        user_login: user_login.to_string(),
    })
}

fn materialize_option_bootstrap(
    remote: &RemoteClient,
    manifest: &Manifest,
    table: &str,
    excluded_names: &[String],
) -> Result<()> {
    let probe = &manifest.probe;
    ensure_probe_has_db(probe)?;
    validate_table_name(table)?;

    let where_sql = option_bootstrap_where_sql_excluding(excluded_names);
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
        .filter(|name| {
            !state
                .dirty_option_rows
                .contains(&option_row_key(table, name))
        })
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
    option_names_for_option_predicate(sql_text, options_table, tables)
}

fn option_write_names_for_sql(
    sql_text: &str,
    options_table: &str,
    tables: &[String],
) -> Vec<String> {
    if !sql::is_write_sql(sql_text) {
        return Vec::new();
    }
    option_names_for_option_predicate(sql_text, options_table, tables)
}

fn option_names_for_option_predicate(
    sql_text: &str,
    options_table: &str,
    tables: &[String],
) -> Vec<String> {
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

fn option_bootstrap_where_sql_excluding(excluded_names: &[String]) -> String {
    let base = option_bootstrap_where_sql();
    if excluded_names.is_empty() {
        return base;
    }

    let excluded = excluded_names
        .iter()
        .map(|name| format!("'{}'", mysql_string_literal(name)))
        .collect::<Vec<_>>()
        .join(", ");
    format!("({base}) AND option_name NOT IN ({excluded})")
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

fn dirty_option_names_for_table(state: &DbState, table: &str) -> Vec<String> {
    let prefix = format!("{table}:");
    state
        .dirty_option_rows
        .iter()
        .filter_map(|key| key.strip_prefix(&prefix).map(str::to_string))
        .collect()
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

fn local_mysqldump_command(manifest: &Manifest) -> Command {
    let mut command = Command::new("mysqldump");
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

pub(crate) fn run_mysql_exec(manifest: &Manifest, sql_text: &str) -> Result<()> {
    let mut command = local_mysql_command(manifest);
    command.arg("--execute").arg(sql_text);
    let status = command.status().context("run local mysql")?;
    if !status.success() {
        return Err(anyhow!("local mysql failed with status {}", status));
    }
    Ok(())
}

pub(crate) fn local_query_result(manifest: &Manifest, sql_text: &str) -> Result<CowQueryResult> {
    let output = local_mysql_command(manifest)
        .arg("--batch")
        .arg("--raw")
        .arg("--execute")
        .arg(sql_text)
        .output()
        .context("run local mysql query")?;
    if !output.status.success() {
        return Err(anyhow!(
            "local mysql query failed: {}",
            String::from_utf8_lossy(&output.stderr)
        ));
    }

    let stdout = String::from_utf8_lossy(&output.stdout);
    let mut lines = stdout.lines();
    let Some(header) = lines.next() else {
        return Ok(CowQueryResult::ok(Vec::new(), Vec::new()));
    };
    let fields = header
        .split('\t')
        .map(|field| field.to_string())
        .collect::<Vec<_>>();
    let mut rows = Vec::new();
    for line in lines {
        let values = line.split('\t').collect::<Vec<_>>();
        let mut row = Row::new();
        for (idx, field) in fields.iter().enumerate() {
            let value = values.get(idx).copied().unwrap_or_default();
            if value == "NULL" {
                row.insert(field.clone(), serde_json::Value::Null);
            } else {
                row.insert(field.clone(), serde_json::Value::String(value.to_string()));
            }
        }
        rows.push(row);
    }

    Ok(CowQueryResult::ok(rows, fields))
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

    fn test_manifest() -> Manifest {
        Manifest {
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
            db_proxy: crate::config::DbProxy {
                host: "127.0.0.1".to_string(),
                port: 33070,
            },
            remote_db_tunnel: crate::config::RemoteDbTunnel {
                host: "127.0.0.1".to_string(),
                port: 33072,
            },
            control_url: "http://127.0.0.1:39070".to_string(),
            cache_max_file_bytes: 1024,
            remote_metadata_cache_ttl_secs: 30,
        }
    }

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
    fn extracts_dirty_option_write_names() {
        let tables = vec!["ady_options".to_string()];
        assert_eq!(
            option_write_names_for_sql(
                "UPDATE ady_options SET option_value = 'neve' WHERE option_name = 'template'",
                "ady_options",
                &tables,
            ),
            vec!["template".to_string()]
        );
        assert_eq!(
            option_write_names_for_sql(
                "DELETE FROM ady_options WHERE option_name IN ('template', 'stylesheet')",
                "ady_options",
                &tables,
            ),
            vec!["template".to_string(), "stylesheet".to_string()]
        );
    }

    #[test]
    fn option_bootstrap_refresh_can_preserve_dirty_rows() {
        let where_sql = option_bootstrap_where_sql_excluding(&[
            "template".to_string(),
            "stylesheet".to_string(),
        ]);
        assert!(where_sql.contains("autoload IN"));
        assert!(where_sql.contains("option_name NOT IN ('template', 'stylesheet')"));
    }

    #[test]
    fn qualifies_local_tables_for_exec_without_selected_database() {
        let manifest = test_manifest();
        assert_eq!(
            qualified_table(&manifest, "ady_options"),
            "`cow_calm`.`ady_options`"
        );
    }

    #[test]
    fn tombstone_delete_sql_preserves_overlay_on_table_promotion() {
        let manifest = test_manifest();
        let sql = row_cow_tombstone_delete_sqls(
            &manifest,
            "wp_posts",
            &[TombstoneGroup {
                pk_column: "ID".to_string(),
                pk_values: vec![PkValue("7".to_string()), PkValue("9".to_string())],
            }],
        )
        .unwrap();

        assert_eq!(
            sql,
            vec!["DELETE FROM `cow_calm`.`wp_posts` WHERE `ID` IN ('7', '9');"]
        );
    }

    #[test]
    fn parses_max_primary_key_rows_for_auto_increment_reservation() {
        let mut row = Row::new();
        row.insert(
            "max_pk".to_string(),
            serde_json::Value::String("184".to_string()),
        );
        assert_eq!(max_pk_from_rows(&[row], "max_pk").unwrap(), 184);

        let mut null_row = Row::new();
        null_row.insert("max_pk".to_string(), serde_json::Value::Null);
        assert_eq!(max_pk_from_rows(&[null_row], "max_pk").unwrap(), 0);

        assert_eq!(max_pk_from_rows(&[], "max_pk").unwrap(), 0);
    }

    #[test]
    fn row_cow_safe_read_fallbacks_do_not_promote_tables() {
        let tables = vec!["ady_options".to_string()];
        assert!(
            !should_materialize_row_cow_fallback(
                "SELECT option_name, option_value FROM ady_options WHERE autoload IN ('yes', 'on')",
                "PromoteTable",
                &tables,
            ),
            "safe live-lower reads should route to the remote lower layer instead of dumping full tables"
        );
        assert!(
            should_materialize_row_cow_fallback(
                "UPDATE ady_options SET option_value='x' WHERE autoload='yes'",
                "PromoteTable",
                &tables,
            ),
            "write fallbacks still need local table promotion before the write executes"
        );
    }
}

use anyhow::{anyhow, Result};
use msql_srv::{
    Column, ColumnFlags, ColumnType, ErrorKind, InitWriter, MysqlIntermediary, MysqlShim,
    ParamParser, QueryResultWriter, StatementMetaWriter, ValueInner,
};
use mysql::prelude::Queryable;
use serde_json::Value as JsonValue;
use std::collections::BTreeMap;
use std::io;
use std::net::TcpListener;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::thread;
use std::time::Duration;

use crate::config::{self, ClonePaths, Manifest};
use crate::db;
use crate::remote::RemoteClient;
use crate::row_cow::CowQueryResult;
use crate::sql;

pub fn serve_proxy(
    addr: &str,
    manifest: Manifest,
    paths: ClonePaths,
    remote: RemoteClient,
    shutdown: Arc<AtomicBool>,
) -> Result<()> {
    let listener = TcpListener::bind(addr).with_context(|| format!("bind MySQL proxy {addr}"))?;
    listener
        .set_nonblocking(true)
        .context("set MySQL proxy nonblocking")?;

    while !shutdown.load(Ordering::SeqCst) {
        match listener.accept() {
            Ok((stream, _peer)) => {
                let backend = ProxyBackend::new(manifest.clone(), paths.clone(), remote.clone());
                thread::spawn(move || {
                    if let Err(err) = MysqlIntermediary::run_on_tcp(backend, stream) {
                        eprintln!("wp-cow MySQL proxy connection ended: {err:?}");
                    }
                });
            }
            Err(err) if err.kind() == io::ErrorKind::WouldBlock => {
                thread::sleep(Duration::from_millis(50));
            }
            Err(err) => return Err(err).context("accept MySQL proxy connection"),
        }
    }

    Ok(())
}

struct ProxyBackend {
    manifest: Manifest,
    paths: ClonePaths,
    remote: RemoteClient,
    local: Option<mysql::Conn>,
    prepared: BTreeMap<u32, String>,
    next_statement_id: u32,
}

impl ProxyBackend {
    fn new(manifest: Manifest, paths: ClonePaths, remote: RemoteClient) -> Self {
        Self {
            manifest,
            paths,
            remote,
            local: None,
            prepared: BTreeMap::new(),
            next_statement_id: 1,
        }
    }

    fn dispatch(&mut self, query: &str) -> Result<ProxyReply> {
        if is_local_session_sql(query) {
            return self.local_query(query);
        }

        if sql::is_write_sql(query) {
            if !config::is_offline(&self.paths) {
                let tables = sql::extract_tables(query);
                let response =
                    db::row_cow_query(&self.remote, &self.manifest, &self.paths, query, &tables)?;
                if response.backend != "local" && !response.handled {
                    return Err(anyhow!("write SQL did not resolve to local backend"));
                }
            }
            return self.local_query(query);
        }

        if sql::is_safe_read_sql(query) {
            if config::is_offline(&self.paths) {
                return self.local_query(query);
            }

            let tables = sql::extract_tables(query);
            let row_cow =
                db::row_cow_query(&self.remote, &self.manifest, &self.paths, query, &tables)?;
            if let Some(result) = row_cow.result {
                return Ok(ProxyReply::Result(result));
            }
            if row_cow.backend == "local" {
                return self.local_query(query);
            }

            let route =
                db::route_for_query(&self.remote, &self.manifest, &self.paths, query, &tables)?;
            if route.backend == "local" {
                self.local_query(query)
            } else {
                let result = db::cached_remote_readonly_query(&self.remote, &self.paths, query)?;
                Ok(ProxyReply::Result(CowQueryResult {
                    ok: result.ok,
                    error: result.error,
                    rows: result.rows,
                    fields: result.fields,
                    affected: result.affected,
                }))
            }
        } else {
            self.local_query(query)
        }
    }

    fn local_conn(&mut self) -> Result<&mut mysql::Conn> {
        if self.local.is_none() {
            let mut builder = mysql::OptsBuilder::new()
                .ip_or_hostname(Some(self.manifest.local_db.host.clone()))
                .tcp_port(self.manifest.local_db.port)
                .user(Some(self.manifest.local_db.user.clone()))
                .db_name(Some(self.manifest.local_db.name.clone()));
            if !self.manifest.local_db.password.is_empty() {
                builder = builder.pass(Some(self.manifest.local_db.password.clone()));
            }
            self.local = Some(mysql::Conn::new(builder)?);
        }
        self.local
            .as_mut()
            .ok_or_else(|| anyhow!("local MySQL connection was not initialized"))
    }

    fn local_query(&mut self, query: &str) -> Result<ProxyReply> {
        let result = self.local_conn()?.query_iter(query)?;
        let fields = result
            .columns()
            .as_ref()
            .iter()
            .map(|column| column.name_str().to_string())
            .collect::<Vec<_>>();
        let affected = result.affected_rows();
        let last_insert_id = result.last_insert_id().unwrap_or(0);

        if fields.is_empty() {
            drop(result);
            return Ok(ProxyReply::Completed {
                affected_rows: affected,
                last_insert_id,
            });
        }

        let mut rows = Vec::new();
        for row in result {
            let row = row?;
            let values = row.unwrap();
            let mut out = serde_json::Map::new();
            for (idx, field) in fields.iter().enumerate() {
                let value = values.get(idx).cloned().unwrap_or(mysql::Value::NULL);
                out.insert(field.clone(), mysql_value_to_json(value));
            }
            rows.push(out);
        }

        Ok(ProxyReply::Result(CowQueryResult {
            ok: true,
            error: String::new(),
            affected: rows.len() as i64,
            rows,
            fields,
        }))
    }
}

enum ProxyReply {
    Result(CowQueryResult),
    Completed {
        affected_rows: u64,
        last_insert_id: u64,
    },
}

impl<W: io::Read + io::Write> MysqlShim<W> for ProxyBackend {
    type Error = io::Error;

    fn on_prepare(
        &mut self,
        query: &str,
        info: StatementMetaWriter<'_, W>,
    ) -> Result<(), Self::Error> {
        let id = self.next_statement_id;
        self.next_statement_id = self.next_statement_id.saturating_add(1);
        self.prepared.insert(id, query.to_string());
        let params = (0..count_placeholders(query))
            .map(|idx| Column {
                table: String::new(),
                column: format!("param{}", idx + 1),
                coltype: ColumnType::MYSQL_TYPE_STRING,
                colflags: ColumnFlags::empty(),
            })
            .collect::<Vec<_>>();
        info.reply(id, &params, &[])
    }

    fn on_execute(
        &mut self,
        id: u32,
        params: ParamParser<'_>,
        results: QueryResultWriter<'_, W>,
    ) -> Result<(), Self::Error> {
        let Some(query) = self.prepared.get(&id).cloned() else {
            return Ok(results.error(ErrorKind::ER_UNKNOWN_STMT_HANDLER, b"unknown statement")?);
        };
        let params = params
            .into_iter()
            .map(|param| mysql_param_literal(param.value.into_inner()))
            .collect::<Vec<_>>();
        let Ok(query) = substitute_placeholders(&query, &params) else {
            return Ok(results.error(
                ErrorKind::ER_PARSE_ERROR,
                b"prepared statement parameter count does not match placeholders",
            )?);
        };
        write_proxy_reply(self.dispatch(&query), results)
    }

    fn on_close(&mut self, stmt: u32) {
        self.prepared.remove(&stmt);
    }

    fn on_query(
        &mut self,
        query: &str,
        results: QueryResultWriter<'_, W>,
    ) -> Result<(), Self::Error> {
        write_proxy_reply(self.dispatch(query), results)
    }

    fn on_init(&mut self, _schema: &str, writer: InitWriter<'_, W>) -> Result<(), Self::Error> {
        writer.ok()
    }
}

fn write_proxy_reply<W: io::Read + io::Write>(
    reply: Result<ProxyReply>,
    results: QueryResultWriter<'_, W>,
) -> Result<(), io::Error> {
    match reply {
        Ok(ProxyReply::Result(result)) if result.ok => write_result(result, results),
        Ok(ProxyReply::Result(result)) => {
            Ok(results.error(ErrorKind::ER_UNKNOWN_ERROR, result.error.as_bytes())?)
        }
        Ok(ProxyReply::Completed {
            affected_rows,
            last_insert_id,
        }) => results.completed(affected_rows, last_insert_id),
        Err(err) => Ok(results.error(ErrorKind::ER_UNKNOWN_ERROR, err.to_string().as_bytes())?),
    }
}

fn write_result<W: io::Read + io::Write>(
    result: CowQueryResult,
    results: QueryResultWriter<'_, W>,
) -> Result<(), io::Error> {
    let columns = result
        .fields
        .iter()
        .map(|field| Column {
            table: String::new(),
            column: field.clone(),
            coltype: ColumnType::MYSQL_TYPE_STRING,
            colflags: ColumnFlags::empty(),
        })
        .collect::<Vec<_>>();
    let mut writer = results.start(&columns)?;
    for row in result.rows {
        for field in &result.fields {
            match row.get(field) {
                None | Some(JsonValue::Null) => writer.write_col(None::<&str>)?,
                Some(JsonValue::String(value)) => writer.write_col(value.as_str())?,
                Some(value) => writer.write_col(value.to_string())?,
            }
        }
        writer.end_row()?;
    }
    writer.finish()
}

fn is_local_session_sql(query: &str) -> bool {
    let normalized = query.trim_start().to_ascii_uppercase();
    normalized.starts_with("SET ")
        || normalized.starts_with("START TRANSACTION")
        || normalized.starts_with("BEGIN")
        || normalized.starts_with("COMMIT")
        || normalized.starts_with("ROLLBACK")
}

fn count_placeholders(query: &str) -> usize {
    scan_placeholders(query, None).0
}

fn substitute_placeholders(query: &str, params: &[String]) -> Result<String> {
    let (used, out) = scan_placeholders(query, Some(params));
    if used != params.len() {
        return Err(anyhow!("too many prepared statement parameters"));
    }
    out.ok_or_else(|| anyhow!("missing prepared statement parameter"))
}

fn scan_placeholders(query: &str, params: Option<&[String]>) -> (usize, Option<String>) {
    let chars = query.chars().collect::<Vec<_>>();
    let mut out = params.map(|_| String::with_capacity(query.len()));
    let mut idx = 0;
    let mut used = 0;

    while idx < chars.len() {
        let ch = chars[idx];

        if ch == '\'' || ch == '"' || ch == '`' {
            push_char(&mut out, ch);
            idx += 1;
            while idx < chars.len() {
                let inner = chars[idx];
                push_char(&mut out, inner);
                idx += 1;
                if inner == '\\' && idx < chars.len() {
                    push_char(&mut out, chars[idx]);
                    idx += 1;
                    continue;
                }
                if inner == ch {
                    if idx < chars.len() && chars[idx] == ch {
                        push_char(&mut out, chars[idx]);
                        idx += 1;
                        continue;
                    }
                    break;
                }
            }
            continue;
        }

        if ch == '-' && idx + 1 < chars.len() && chars[idx + 1] == '-' {
            push_char(&mut out, ch);
            push_char(&mut out, chars[idx + 1]);
            idx += 2;
            while idx < chars.len() {
                let comment = chars[idx];
                push_char(&mut out, comment);
                idx += 1;
                if comment == '\n' {
                    break;
                }
            }
            continue;
        }

        if ch == '#' {
            push_char(&mut out, ch);
            idx += 1;
            while idx < chars.len() {
                let comment = chars[idx];
                push_char(&mut out, comment);
                idx += 1;
                if comment == '\n' {
                    break;
                }
            }
            continue;
        }

        if ch == '/' && idx + 1 < chars.len() && chars[idx + 1] == '*' {
            push_char(&mut out, ch);
            push_char(&mut out, chars[idx + 1]);
            idx += 2;
            while idx < chars.len() {
                let comment = chars[idx];
                push_char(&mut out, comment);
                idx += 1;
                if comment == '*' && idx < chars.len() && chars[idx] == '/' {
                    push_char(&mut out, chars[idx]);
                    idx += 1;
                    break;
                }
            }
            continue;
        }

        if ch == '?' {
            if let Some(params) = params {
                let Some(param) = params.get(used) else {
                    return (used, None);
                };
                push_str(&mut out, param);
            }
            used += 1;
            idx += 1;
            continue;
        }

        push_char(&mut out, ch);
        idx += 1;
    }

    (used, out)
}

fn push_char(out: &mut Option<String>, ch: char) {
    if let Some(out) = out {
        out.push(ch);
    }
}

fn push_str(out: &mut Option<String>, value: &str) {
    if let Some(out) = out {
        out.push_str(value);
    }
}

fn mysql_param_literal(value: ValueInner<'_>) -> String {
    match value {
        ValueInner::NULL => "NULL".to_string(),
        ValueInner::Bytes(bytes) => format!("'{}'", mysql_string_literal_bytes(bytes)),
        ValueInner::Int(value) => value.to_string(),
        ValueInner::UInt(value) => value.to_string(),
        ValueInner::Double(value) => value.to_string(),
        ValueInner::Date(bytes) | ValueInner::Time(bytes) | ValueInner::Datetime(bytes) => {
            format!("X'{}'", hex::encode(bytes))
        }
    }
}

fn mysql_value_to_json(value: mysql::Value) -> JsonValue {
    match value {
        mysql::Value::NULL => JsonValue::Null,
        mysql::Value::Bytes(bytes) => JsonValue::String(String::from_utf8_lossy(&bytes).into()),
        mysql::Value::Int(value) => JsonValue::String(value.to_string()),
        mysql::Value::UInt(value) => JsonValue::String(value.to_string()),
        mysql::Value::Float(value) => JsonValue::String(value.to_string()),
        mysql::Value::Double(value) => JsonValue::String(value.to_string()),
        mysql::Value::Date(year, month, day, hour, minute, second, micros) => {
            JsonValue::String(format!(
                "{year:04}-{month:02}-{day:02} {hour:02}:{minute:02}:{second:02}.{:06}",
                micros
            ))
        }
        mysql::Value::Time(negative, days, hours, minutes, seconds, micros) => {
            let sign = if negative { "-" } else { "" };
            JsonValue::String(format!(
                "{sign}{days} {hours:02}:{minutes:02}:{seconds:02}.{:06}",
                micros
            ))
        }
    }
}

fn mysql_string_literal_bytes(bytes: &[u8]) -> String {
    String::from_utf8_lossy(bytes)
        .replace('\\', "\\\\")
        .replace('\'', "\\'")
}

trait Context<T> {
    fn context(self, msg: &'static str) -> Result<T>;
    fn with_context<F: FnOnce() -> String>(self, f: F) -> Result<T>;
}

impl<T> Context<T> for io::Result<T> {
    fn context(self, msg: &'static str) -> Result<T> {
        self.map_err(|err| anyhow!("{msg}: {err}"))
    }

    fn with_context<F: FnOnce() -> String>(self, f: F) -> Result<T> {
        self.map_err(|err| anyhow!("{}: {err}", f()))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn recognizes_session_sql_as_local_only() {
        assert!(is_local_session_sql("SET NAMES utf8mb4"));
        assert!(is_local_session_sql("BEGIN"));
        assert!(is_local_session_sql("COMMIT"));
        assert!(!is_local_session_sql("SELECT * FROM wp_posts"));
    }

    #[test]
    fn substitutes_prepared_placeholders_outside_literals_and_comments() {
        let sql =
            "SELECT '?' AS literal, col FROM wp_posts WHERE ID = ? AND post_title = ? /* ? */";
        let substituted =
            substitute_placeholders(sql, &["123".to_string(), "'local \\' title'".to_string()])
                .unwrap();
        assert_eq!(
            substituted,
            "SELECT '?' AS literal, col FROM wp_posts WHERE ID = 123 AND post_title = 'local \\' title' /* ? */"
        );
        assert_eq!(count_placeholders(sql), 2);
    }

    #[test]
    fn quotes_prepared_parameter_literals() {
        assert_eq!(
            mysql_param_literal(ValueInner::Bytes(b"a'b\\c")),
            "'a\\'b\\\\c'"
        );
        assert_eq!(mysql_param_literal(ValueInner::NULL), "NULL");
        assert_eq!(mysql_param_literal(ValueInner::UInt(42)), "42");
    }
}

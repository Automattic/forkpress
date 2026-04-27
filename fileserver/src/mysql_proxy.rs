use anyhow::Result;
use async_trait::async_trait;
use opensrv_mysql::*;
use sha1::{Digest, Sha1};
use std::io;
use std::io::Write;
use std::sync::{Arc, Mutex};
use tokio::io::AsyncWrite;

use crate::store::Store;

pub struct MysqlHandler {
    store: Arc<Store>,
    branch_id: i64,
    /// Role assigned by the handshake authenticate() call. None = auth
    /// disabled for this site (legacy open mode).
    role: Arc<Mutex<Option<String>>>,
    /// Username supplied at handshake time. Used as the Principal when
    /// shelling out to `branchctl _ddl` (hostile-review finding #18) so
    /// the audit trail attributes the DDL to the authenticated client.
    username: Arc<Mutex<Option<String>>>,
}

impl MysqlHandler {
    pub fn new(store: Arc<Store>) -> Self {
        Self {
            store,
            branch_id: 1,
            role: Arc::new(Mutex::new(None)),
            username: Arc::new(Mutex::new(None)),
        }
    }

    fn is_read_only(&self) -> bool {
        matches!(self.role.lock().unwrap().as_deref(), Some("read"))
    }

    fn current_username(&self) -> Option<String> {
        self.username.lock().unwrap().clone()
    }
}

/// Verify a mysql_native_password reply.
///
/// Native auth:  reply = SHA1(password) XOR SHA1(salt || SHA1(SHA1(password)))
///
/// We store `mysql_sha1 = SHA1(SHA1(password))` in the users table. From that
/// plus the salt we cannot directly recompute `SHA1(password)`, but we can
/// still verify the reply because of XOR:
///
///   sha1_pw  = reply XOR SHA1(salt || mysql_sha1)
///   expect   = SHA1(sha1_pw)
///   ok       = (expect == mysql_sha1)
fn verify_mysql_native(reply: &[u8], salt: &[u8], mysql_sha1_hex: &str) -> bool {
    if reply.len() != 20 { return false; }
    let Ok(mysql_sha1) = hex::decode(mysql_sha1_hex) else { return false; };
    if mysql_sha1.len() != 20 { return false; }

    // SHA1(salt || mysql_sha1)
    let mut h = Sha1::new();
    h.update(salt);
    h.update(&mysql_sha1);
    let s2 = h.finalize();

    // sha1_pw = reply XOR s2
    let sha1_pw: [u8; 20] = std::array::from_fn(|i| reply[i] ^ s2[i]);

    // expect = SHA1(sha1_pw)
    let mut h2 = Sha1::new();
    h2.update(&sha1_pw);
    let expect = h2.finalize();

    expect.as_slice() == mysql_sha1.as_slice()
}

/// Rewrite bare `wp_` identifier prefixes to `b{id}_wp_`, leaving string
/// literals and comments untouched.
///
/// Rules:
///   - Inside `'…'` or `"…"` strings the text is left alone. SQL's `''` and
///     MySQL's `\\'` both escape an embedded quote; backticked identifiers
///     (`\`wp_x\``) rewrite as normal.
///   - Line comments `-- …\n` and block comments `/* … */` are skipped.
///   - An occurrence of `wp_` is rewritten only when the character immediately
///     before it is not part of an identifier (so `my_wp_xyz` or `swp_` are
///     left alone).
pub fn rewrite_wp_prefix(sql: &str, prefix: &str) -> String {
    let bytes = sql.as_bytes();
    let mut out = String::with_capacity(sql.len() + prefix.len());
    let mut i = 0;
    let mut prev_byte: Option<u8> = None;

    while i < bytes.len() {
        let c = bytes[i];

        // -- line comment
        if c == b'-' && i + 1 < bytes.len() && bytes[i + 1] == b'-' {
            while i < bytes.len() && bytes[i] != b'\n' {
                out.push(bytes[i] as char);
                i += 1;
            }
            prev_byte = Some(b'\n');
            continue;
        }

        // /* block comment */
        if c == b'/' && i + 1 < bytes.len() && bytes[i + 1] == b'*' {
            out.push_str("/*");
            i += 2;
            while i < bytes.len() {
                if bytes[i] == b'*' && i + 1 < bytes.len() && bytes[i + 1] == b'/' {
                    out.push_str("*/");
                    i += 2;
                    break;
                }
                out.push(bytes[i] as char);
                i += 1;
            }
            prev_byte = Some(b' ');
            continue;
        }

        // String literal: ' or "
        if c == b'\'' || c == b'"' {
            let quote = c;
            out.push(quote as char);
            i += 1;
            while i < bytes.len() {
                let b = bytes[i];
                // MySQL-style backslash escape: copy next byte verbatim
                if b == b'\\' && i + 1 < bytes.len() {
                    out.push(b as char);
                    out.push(bytes[i + 1] as char);
                    i += 2;
                    continue;
                }
                // SQL-standard doubled-quote escape: 'it''s'
                if b == quote && i + 1 < bytes.len() && bytes[i + 1] == quote {
                    out.push(quote as char);
                    out.push(quote as char);
                    i += 2;
                    continue;
                }
                out.push(b as char);
                i += 1;
                if b == quote {
                    break;
                }
            }
            prev_byte = Some(quote);
            continue;
        }

        // Match bare `wp_` in identifier position.
        if (c == b'w' || c == b'W')
            && i + 2 < bytes.len()
            && (bytes[i + 1] == b'p' || bytes[i + 1] == b'P')
            && bytes[i + 2] == b'_'
            && !is_ident_cont(prev_byte)
        {
            out.push_str(prefix);
            i += 3;
            prev_byte = Some(b'_');
            continue;
        }

        out.push(c as char);
        prev_byte = Some(c);
        i += 1;
    }

    out
}

/// Identifier-continuation check: letters, digits, underscore, or `$`
/// (MySQL permits `$` in identifiers). A `None` preceding byte (start of
/// input) counts as non-identifier so leading `wp_` is rewritten.
fn is_ident_cont(b: Option<u8>) -> bool {
    match b {
        Some(b) => b.is_ascii_alphanumeric() || b == b'_' || b == b'$',
        None => false,
    }
}

/// DDL-on-view detection for the MySQL proxy.
///
/// The BranchedPDO class (scripts/branched_pdo.php) already re-routes
/// ALTER TABLE / CREATE INDEX / DROP INDEX whose target is a COW branch
/// VIEW to the underlying overlay. WordPress code that uses BranchedPDO
/// gets this for free. Out-of-band SQL clients (`mysql`, `wp-cli db query`,
/// phpMyAdmin) hit this proxy directly, and SQLite will refuse DDL on a
/// view with a raw error.
///
/// So the proxy performs the same detection — it classifies the DDL, pulls
/// the target identifier, and if `sqlite_master.type = 'view'` for that
/// name the SQL is handed off to the PHP helper (which reuses the single
/// source-of-truth routing logic in BranchedPDO).
///
/// Returns the target table name iff this is DDL that the proxy should
/// delegate to the PHP helper. Returns None for non-DDL or DDL whose
/// target isn't a view (let SQLite handle it directly).
pub fn detect_view_ddl_target(sql: &str) -> Option<String> {
    let trimmed = sql.trim_start();
    let u4_upper: String = trimmed.chars().take(4).collect::<String>().to_ascii_uppercase();
    match u4_upper.as_str() {
        "ALTE" | "CREA" | "DROP" => {}
        _ => return None,
    }
    let bytes = trimmed.as_bytes();
    let upper = trimmed.to_ascii_uppercase();

    // Helper: starting from byte index `pos` (inside `bytes`), skip spaces
    // then parse one identifier (quoted with "..", backticked with `..`,
    // bracketed with [..], or bare). Returns (name, next_pos).
    fn skip_ws(b: &[u8], mut i: usize) -> usize {
        while i < b.len() && b[i].is_ascii_whitespace() {
            i += 1;
        }
        i
    }
    fn parse_ident(b: &[u8], mut i: usize) -> Option<(String, usize)> {
        i = skip_ws(b, i);
        if i >= b.len() {
            return None;
        }
        match b[i] {
            b'"' => {
                i += 1;
                let start = i;
                while i < b.len() && b[i] != b'"' {
                    i += 1;
                }
                if i >= b.len() {
                    return None;
                }
                let name = std::str::from_utf8(&b[start..i]).ok()?.to_string();
                Some((name, i + 1))
            }
            b'`' => {
                i += 1;
                let start = i;
                while i < b.len() && b[i] != b'`' {
                    i += 1;
                }
                if i >= b.len() {
                    return None;
                }
                let name = std::str::from_utf8(&b[start..i]).ok()?.to_string();
                Some((name, i + 1))
            }
            b'[' => {
                i += 1;
                let start = i;
                while i < b.len() && b[i] != b']' {
                    i += 1;
                }
                if i >= b.len() {
                    return None;
                }
                let name = std::str::from_utf8(&b[start..i]).ok()?.to_string();
                Some((name, i + 1))
            }
            c if c == b'_' || c.is_ascii_alphabetic() => {
                let start = i;
                while i < b.len()
                    && (b[i] == b'_' || b[i].is_ascii_alphanumeric())
                {
                    i += 1;
                }
                let name = std::str::from_utf8(&b[start..i]).ok()?.to_string();
                Some((name, i))
            }
            _ => None,
        }
    }

    // ALTER TABLE <name> ...
    if upper.starts_with("ALTER") {
        let rest_after_alter = skip_ws(bytes, 5);
        if !upper[rest_after_alter..].starts_with("TABLE") {
            return None;
        }
        let after_table = skip_ws(bytes, rest_after_alter + 5);
        let (name, _) = parse_ident(bytes, after_table)?;
        return Some(name);
    }
    // CREATE [UNIQUE] INDEX [IF NOT EXISTS] <idx> ON <table>(...)
    if upper.starts_with("CREATE") {
        let mut i = skip_ws(bytes, 6);
        if upper[i..].starts_with("UNIQUE") {
            i = skip_ws(bytes, i + 6);
        }
        if !upper[i..].starts_with("INDEX") {
            return None;
        }
        i = skip_ws(bytes, i + 5);
        if upper[i..].starts_with("IF") {
            i = skip_ws(bytes, i + 2);
            if upper[i..].starts_with("NOT") {
                i = skip_ws(bytes, i + 3);
                if upper[i..].starts_with("EXISTS") {
                    i = skip_ws(bytes, i + 6);
                }
            }
        }
        let (_idx, after_idx) = parse_ident(bytes, i)?;
        let after_on = skip_ws(bytes, after_idx);
        if !upper[after_on..].starts_with("ON") {
            return None;
        }
        let after_on_kw = skip_ws(bytes, after_on + 2);
        let (tbl, _) = parse_ident(bytes, after_on_kw)?;
        return Some(tbl);
    }
    // DROP INDEX [IF EXISTS] <idx> — the "target table" doesn't appear in
    // the SQL. The proxy still has to route the statement to BranchedPDO
    // whenever the referenced index belongs to a branch view; we return a
    // sentinel "DROP_INDEX" marker and let the caller look up sqlite_master.
    if upper.starts_with("DROP") {
        let mut i = skip_ws(bytes, 4);
        if !upper[i..].starts_with("INDEX") {
            return None;
        }
        i = skip_ws(bytes, i + 5);
        if upper[i..].starts_with("IF") {
            i = skip_ws(bytes, i + 2);
            if upper[i..].starts_with("EXISTS") {
                i = skip_ws(bytes, i + 6);
            }
        }
        let (idx_name, _) = parse_ident(bytes, i)?;
        // Caller sees a name that always fails the "is_view" check, so it
        // needs a distinct code path: we signal via the "__drop_index__:"
        // prefix which the handler unpacks.
        return Some(format!("__drop_index__:{}", idx_name));
    }
    None
}

/// Path to the PHP branchctl helper. Configured via env var
/// `FORKPRESS_BRANCHCTL_BIN`; falls back to `branchctl` on $PATH which
/// matches the bin/branchctl wrapper script shipped in the repo.
fn branchctl_bin() -> String {
    std::env::var("FORKPRESS_BRANCHCTL_BIN").unwrap_or_else(|_| "branchctl".to_string())
}

/// Run `branchctl _ddl --db <path> --branch <name>` with the SQL on stdin.
/// Returns () on success; on failure returns the stderr message so the
/// proxy can forward it to the client.
///
/// `token` is a FORKPRESS_TOKEN HMAC-signed blob identifying the authenticated
/// MySQL user; it's passed via env so branchctl can resolve a Principal and
/// attribute the audit row correctly (hostile-review finding #18).
pub fn exec_ddl_via_branchctl(
    db_path: &std::path::Path,
    branch: &str,
    sql: &str,
    token: Option<&str>,
) -> std::result::Result<(), String> {
    let bin = branchctl_bin();
    let mut cmd = std::process::Command::new(&bin);
    cmd.arg("_ddl")
        .arg("--db")
        .arg(db_path.as_os_str())
        .arg("--branch")
        .arg(branch)
        .stdin(std::process::Stdio::piped())
        .stdout(std::process::Stdio::piped())
        .stderr(std::process::Stdio::piped());
    if let Some(t) = token {
        cmd.env("FORKPRESS_TOKEN", t);
    }
    let mut child = cmd
        .spawn()
        .map_err(|e| format!("failed to spawn '{}': {}", bin, e))?;
    if let Some(mut stdin) = child.stdin.take() {
        stdin
            .write_all(sql.as_bytes())
            .map_err(|e| format!("write sql to branchctl: {}", e))?;
        // Drop stdin so child sees EOF.
    }
    let output = child
        .wait_with_output()
        .map_err(|e| format!("wait branchctl: {}", e))?;
    if output.status.success() {
        Ok(())
    } else {
        let err = String::from_utf8_lossy(&output.stderr).trim().to_string();
        if err.is_empty() {
            Err(format!("branchctl _ddl exited with status {:?}", output.status.code()))
        } else {
            Err(err)
        }
    }
}

#[async_trait]
impl<W: AsyncWrite + Unpin + Send> AsyncMysqlShim<W> for MysqlHandler {
    type Error = io::Error;

    async fn authenticate(
        &self,
        _auth_plugin: &str,
        username: &[u8],
        salt: &[u8],
        auth_data: &[u8],
    ) -> bool {
        if !self.store.auth_enabled() {
            *self.role.lock().unwrap() = None;
            // In legacy mode we still record the supplied username (if any)
            // so the audit log can attribute MySQL-proxied DDL. This is a
            // hint only — auth_enabled=0 confers no security claim on it.
            *self.username.lock().unwrap() =
                std::str::from_utf8(username).ok().map(|s| s.to_string());
            return true;
        }
        let user = match std::str::from_utf8(username) {
            Ok(s) => s,
            Err(_) => return false,
        };
        // Empty reply means the client sent no password — reject when auth is on.
        if auth_data.is_empty() {
            return false;
        }
        match self.store.user_mysql_creds(user) {
            Some((mysql_sha1, role)) => {
                if verify_mysql_native(auth_data, salt, &mysql_sha1) {
                    *self.role.lock().unwrap() = Some(role);
                    *self.username.lock().unwrap() = Some(user.to_string());
                    true
                } else {
                    false
                }
            }
            None => false,
        }
    }

    async fn on_prepare<'a>(
        &'a mut self,
        _: &'a str,
        info: StatementMetaWriter<'a, W>,
    ) -> io::Result<()> {
        info.reply(0, &[] as &[Column], &[] as &[Column]).await
    }

    async fn on_execute<'a>(
        &'a mut self,
        _: u32,
        _: ParamParser<'a>,
        results: QueryResultWriter<'a, W>,
    ) -> io::Result<()> {
        results.completed(OkResponse::default()).await
    }

    async fn on_close<'a>(&'a mut self, _: u32)
    where
        W: 'async_trait,
    {
    }

    async fn on_init<'a>(
        &'a mut self,
        schema: &'a str,
        w: InitWriter<'a, W>,
    ) -> io::Result<()> {
        match self.store.branch_id(schema) {
            Ok(id) => {
                self.branch_id = id;
                w.ok().await
            }
            Err(_) => w.error(ErrorKind::ER_BAD_DB_ERROR, schema.as_bytes()).await,
        }
    }

    async fn on_query<'a>(
        &'a mut self,
        sql: &'a str,
        results: QueryResultWriter<'a, W>,
    ) -> io::Result<()> {
        // Role 'read' may only run SELECT/SHOW/EXPLAIN/PRAGMA.
        if self.is_read_only() {
            let trimmed = sql.trim_start().to_uppercase();
            let is_read = trimmed.starts_with("SELECT")
                || trimmed.starts_with("SHOW")
                || trimmed.starts_with("EXPLAIN")
                || trimmed.starts_with("PRAGMA")
                || trimmed.starts_with("SET")
                || trimmed.is_empty();
            if !is_read {
                return results
                    .error(
                        ErrorKind::ER_ACCESS_DENIED_ERROR,
                        b"role 'read' cannot execute writes",
                    )
                    .await;
            }
        }
        let prefix = format!("b{}_wp_", self.branch_id);
        let rewritten = rewrite_wp_prefix(sql, &prefix);

        // Intercept DDL whose target is a branch VIEW. SQLite refuses
        // ALTER / CREATE INDEX / DROP INDEX on a view; for
        // BranchedPDO-wrapped PHP callers this is handled transparently,
        // and we do the same here for out-of-band clients (wp-cli db
        // query, mysql, phpMyAdmin). See TODO3 #1.
        if let Some(target) = detect_view_ddl_target(&rewritten) {
            let should_intercept = if let Some(idx) = target.strip_prefix("__drop_index__:") {
                // For DROP INDEX we look up the index's owning table and
                // route through BranchedPDO when that table is a branch
                // view or overlay (BranchedPDO also refuses cross-branch
                // DROPs).
                let owner: Option<String> = {
                    let r = self
                        .store
                        .query_rows(&format!(
                            "SELECT tbl_name FROM sqlite_master \
                             WHERE type='index' AND name='{}'",
                            idx.replace('\'', "''")
                        ))
                        .ok();
                    r.and_then(|(_, rows)| {
                        rows.into_iter().next().and_then(|mut row| {
                            row.pop().and_then(|v| match v {
                                rusqlite::types::Value::Text(s) => Some(s),
                                _ => None,
                            })
                        })
                    })
                };
                match owner {
                    Some(t) => t.ends_with("__overlay") || self.store.is_view(&t),
                    None => false,
                }
            } else {
                self.store.is_view(&target)
            };

            if should_intercept {
                let branch = self
                    .store
                    .branch_name(self.branch_id)
                    .unwrap_or_else(|| format!("b{}", self.branch_id));
                let db_path = self.store.db_path().to_path_buf();
                let sql_owned = rewritten.clone();
                // Mint a short-lived FORKPRESS_TOKEN so branchctl _ddl can
                // attribute the audit row to the connected MySQL user
                // (hostile-review finding #18). When auth is disabled we
                // pass no token and branchctl runs as the `system`
                // principal in legacy mode.
                let token: Option<String> = if self.store.auth_enabled() {
                    self.current_username().and_then(|u| {
                        self.store.mint_principal_token(&u, 60).ok()
                    })
                } else {
                    None
                };
                // Run the blocking subprocess on a tokio blocking thread so
                // we don't stall the async runtime.
                let join = tokio::task::spawn_blocking(move || {
                    exec_ddl_via_branchctl(&db_path, &branch, &sql_owned,
                                           token.as_deref())
                })
                .await;
                match join {
                    Ok(Ok(())) => {
                        return results.completed(OkResponse::default()).await;
                    }
                    Ok(Err(msg)) => {
                        return results
                            .error(ErrorKind::ER_UNKNOWN_ERROR, msg.as_bytes())
                            .await;
                    }
                    Err(e) => {
                        let msg = format!("spawn_blocking: {}", e);
                        return results
                            .error(ErrorKind::ER_UNKNOWN_ERROR, msg.as_bytes())
                            .await;
                    }
                }
            }
        }

        match self.store.query_rows(&rewritten) {
            Err(e) => {
                let msg = e.to_string();
                results.error(ErrorKind::ER_UNKNOWN_ERROR, msg.as_bytes()).await
            }
            Ok((col_names, rows)) => {
                if col_names.is_empty() {
                    return results.completed(OkResponse::default()).await;
                }
                let cols: Vec<Column> = col_names
                    .iter()
                    .map(|name| Column {
                        table: String::new(),
                        column: name.clone(),
                        coltype: ColumnType::MYSQL_TYPE_STRING,
                        colflags: ColumnFlags::empty(),
                    })
                    .collect();
                let mut rw = results.start(&cols).await?;
                for row in &rows {
                    for val in row {
                        match val {
                            rusqlite::types::Value::Null => rw.write_col(None::<String>)?,
                            rusqlite::types::Value::Integer(i) => rw.write_col(*i)?,
                            rusqlite::types::Value::Real(f) => rw.write_col(*f)?,
                            rusqlite::types::Value::Text(s) => rw.write_col(s.as_str())?,
                            rusqlite::types::Value::Blob(b) => rw.write_col(b.as_slice())?,
                        }
                    }
                    rw.end_row().await?;
                }
                rw.finish().await
            }
        }
    }
}

pub async fn run_mysql_proxy(addr: &str, store: Arc<Store>) -> Result<()> {
    let listener = tokio::net::TcpListener::bind(addr).await?;
    log::info!("MySQL proxy listening on {}", addr);
    loop {
        let (stream, peer) = listener.accept().await?;
        log::info!("mysql: connection from {}", peer);
        let store = Arc::clone(&store);
        tokio::spawn(async move {
            let handler = MysqlHandler::new(store);
            let (reader, writer) = tokio::io::split(stream);
            if let Err(e) = AsyncMysqlIntermediary::run_on(handler, reader, writer).await {
                log::error!("mysql: session error: {}", e);
            }
        });
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn rewrites_table_identifier() {
        assert_eq!(
            rewrite_wp_prefix("SELECT * FROM wp_options", "b2_wp_"),
            "SELECT * FROM b2_wp_options"
        );
    }

    #[test]
    fn preserves_single_quoted_string() {
        assert_eq!(
            rewrite_wp_prefix(
                "UPDATE wp_options SET option_value='wp_capabilities' \
                 WHERE option_name='x'",
                "b1_wp_"
            ),
            "UPDATE b1_wp_options SET option_value='wp_capabilities' \
             WHERE option_name='x'"
        );
    }

    #[test]
    fn preserves_double_quoted_string() {
        assert_eq!(
            rewrite_wp_prefix(
                "INSERT INTO wp_usermeta(meta_key) VALUES(\"wp_capabilities\")",
                "b1_wp_"
            ),
            "INSERT INTO b1_wp_usermeta(meta_key) VALUES(\"wp_capabilities\")"
        );
    }

    #[test]
    fn preserves_string_with_sql_doubled_quote() {
        // 'it''s wp_capabilities' — the embedded `wp_` must survive.
        assert_eq!(
            rewrite_wp_prefix(
                "INSERT INTO wp_x(v) VALUES('it''s wp_capabilities')",
                "b3_wp_"
            ),
            "INSERT INTO b3_wp_x(v) VALUES('it''s wp_capabilities')"
        );
    }

    #[test]
    fn preserves_string_with_backslash_escape() {
        assert_eq!(
            rewrite_wp_prefix(
                "INSERT INTO wp_x(v) VALUES('a\\'b wp_capabilities')",
                "b3_wp_"
            ),
            "INSERT INTO b3_wp_x(v) VALUES('a\\'b wp_capabilities')"
        );
    }

    #[test]
    fn skips_line_comment() {
        assert_eq!(
            rewrite_wp_prefix(
                "SELECT * FROM wp_options -- reference wp_capabilities\nWHERE x=1",
                "b1_wp_"
            ),
            "SELECT * FROM b1_wp_options -- reference wp_capabilities\nWHERE x=1"
        );
    }

    #[test]
    fn skips_block_comment() {
        assert_eq!(
            rewrite_wp_prefix(
                "SELECT * FROM wp_options /* about wp_capabilities */ WHERE x=1",
                "b1_wp_"
            ),
            "SELECT * FROM b1_wp_options /* about wp_capabilities */ WHERE x=1"
        );
    }

    #[test]
    fn does_not_rewrite_inside_identifier() {
        // `my_wp_x` must not become `my_b1_wp_x`.
        assert_eq!(
            rewrite_wp_prefix("SELECT my_wp_x FROM t", "b1_wp_"),
            "SELECT my_wp_x FROM t"
        );
    }

    #[test]
    fn rewrites_multiple_occurrences() {
        assert_eq!(
            rewrite_wp_prefix(
                "SELECT wp_options.id FROM wp_options JOIN wp_posts",
                "b1_wp_"
            ),
            "SELECT b1_wp_options.id FROM b1_wp_options JOIN b1_wp_posts"
        );
    }

    #[test]
    fn where_clause_string_not_rewritten() {
        assert_eq!(
            rewrite_wp_prefix(
                "SELECT * FROM wp_usermeta WHERE meta_key='wp_capabilities'",
                "b1_wp_"
            ),
            "SELECT * FROM b1_wp_usermeta WHERE meta_key='wp_capabilities'"
        );
    }

    #[test]
    fn mixed_case_prefix_is_rewritten() {
        assert_eq!(
            rewrite_wp_prefix("SELECT * FROM WP_OPTIONS", "b1_wp_"),
            "SELECT * FROM b1_wp_OPTIONS"
        );
    }

    #[test]
    fn unterminated_string_is_passed_through() {
        // Don't panic on a malformed query; pass bytes through unchanged
        // from the opening quote to end of input.
        let out = rewrite_wp_prefix("SELECT 'oops wp_capabilities", "b1_wp_");
        assert!(out.contains("'oops wp_capabilities"));
    }

    // ---------- DDL detection tests (TODO3 #1) ----------------------

    #[test]
    fn ddl_alter_add_column_extracts_target() {
        let t = detect_view_ddl_target(
            "ALTER TABLE b3_wp_posts ADD COLUMN seo_title TEXT",
        );
        assert_eq!(t.as_deref(), Some("b3_wp_posts"));
    }

    #[test]
    fn ddl_alter_drop_column_extracts_target() {
        let t = detect_view_ddl_target(
            "ALTER TABLE b7_wp_options DROP COLUMN legacy",
        );
        assert_eq!(t.as_deref(), Some("b7_wp_options"));
    }

    #[test]
    fn ddl_alter_rename_column_extracts_target() {
        let t = detect_view_ddl_target(
            "ALTER TABLE b2_wp_users RENAME COLUMN display_name TO display",
        );
        assert_eq!(t.as_deref(), Some("b2_wp_users"));
    }

    #[test]
    fn ddl_create_index_extracts_target_table() {
        let t = detect_view_ddl_target(
            "CREATE INDEX idx_x ON b1_wp_posts(post_type)",
        );
        assert_eq!(t.as_deref(), Some("b1_wp_posts"));
    }

    #[test]
    fn ddl_create_unique_index_if_not_exists_extracts_target() {
        let t = detect_view_ddl_target(
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_y ON b1_wp_options(option_name)",
        );
        assert_eq!(t.as_deref(), Some("b1_wp_options"));
    }

    #[test]
    fn ddl_quoted_identifier_unquoted() {
        // The detector must strip surrounding quotes so the caller can do
        // sqlite_master lookups with the bare name.
        let t = detect_view_ddl_target(
            r#"ALTER TABLE "b3_wp_posts" ADD COLUMN x TEXT"#,
        );
        assert_eq!(t.as_deref(), Some("b3_wp_posts"));
    }

    #[test]
    fn ddl_backticked_identifier_unquoted() {
        let t = detect_view_ddl_target(
            "ALTER TABLE `b3_wp_posts` ADD COLUMN x TEXT",
        );
        assert_eq!(t.as_deref(), Some("b3_wp_posts"));
    }

    #[test]
    fn ddl_bracketed_identifier_unquoted() {
        let t = detect_view_ddl_target(
            "ALTER TABLE [b3_wp_posts] ADD COLUMN x TEXT",
        );
        assert_eq!(t.as_deref(), Some("b3_wp_posts"));
    }

    #[test]
    fn ddl_drop_index_signals_with_prefix() {
        let t = detect_view_ddl_target("DROP INDEX idx_foo");
        assert_eq!(t.as_deref(), Some("__drop_index__:idx_foo"));
    }

    #[test]
    fn ddl_drop_index_if_exists() {
        let t = detect_view_ddl_target("DROP INDEX IF EXISTS idx_foo");
        assert_eq!(t.as_deref(), Some("__drop_index__:idx_foo"));
    }

    #[test]
    fn non_ddl_returns_none() {
        assert!(detect_view_ddl_target("SELECT * FROM b1_wp_posts").is_none());
        assert!(detect_view_ddl_target("UPDATE b1_wp_posts SET title='x'").is_none());
        assert!(detect_view_ddl_target("INSERT INTO b1_wp_posts VALUES (1)").is_none());
        assert!(detect_view_ddl_target("DELETE FROM b1_wp_posts").is_none());
    }

    #[test]
    fn drop_table_is_not_intercepted() {
        // DROP TABLE on a view fails the way SQLite would fail directly —
        // the proxy doesn't need to intercept it.
        assert!(detect_view_ddl_target("DROP TABLE b1_wp_posts").is_none());
    }

    #[test]
    fn create_table_is_not_intercepted() {
        // CREATE TABLE creates a new table; no existing view involved.
        assert!(detect_view_ddl_target("CREATE TABLE foo(x INT)").is_none());
    }

    #[test]
    fn detection_is_case_insensitive() {
        let t = detect_view_ddl_target(
            "alter table b1_wp_posts add column foo text",
        );
        assert_eq!(t.as_deref(), Some("b1_wp_posts"));
    }

    #[test]
    fn detection_tolerates_leading_whitespace() {
        let t = detect_view_ddl_target(
            "   \n ALTER TABLE b1_wp_posts ADD COLUMN x INT",
        );
        assert_eq!(t.as_deref(), Some("b1_wp_posts"));
    }

    #[test]
    fn malformed_ddl_returns_none() {
        assert!(detect_view_ddl_target("ALTER").is_none());
        assert!(detect_view_ddl_target("ALTER TABLE").is_none());
        assert!(detect_view_ddl_target("CREATE INDEX").is_none());
        assert!(detect_view_ddl_target("DROP INDEX").is_none());
    }
}

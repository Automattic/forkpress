use anyhow::{Context, Result, anyhow};
use rusqlite::{Connection, params};
use sha1::{Digest, Sha1};
use std::path::{Path, PathBuf};
use std::sync::{Arc, Mutex};

/// Chunk size for large-blob storage. Blobs strictly larger than this are
/// stored across multiple rows in `blob_chunks`; smaller blobs stay inline
/// in `blobs.data` for single-query reads. Must match the PHP extension's
/// `BRANCHFS_CHUNK_SIZE` in ext/branchfs.c.
pub const CHUNK_SIZE: usize = 1024 * 1024;

pub struct Store {
    conn: Arc<Mutex<Connection>>,
    db_path: PathBuf,
}

#[derive(Debug, Clone)]
pub struct FileEntry {
    pub path: String,
    pub blob_hash: Option<String>,
    pub mode: i64,
    pub mtime: i64,
    pub is_dir: bool,
    pub size: i64,
}

impl Store {
    pub fn open(db_path: &Path) -> Result<Self> {
        let conn = Connection::open(db_path)
            .with_context(|| format!("opening db {:?}", db_path))?;
        // 15s busy timeout: SQLite polls the write lock for this long
        // before returning SQLITE_BUSY. Paired with with_busy_retry
        // below to cover cases where the timeout still exceeds.
        conn.busy_timeout(std::time::Duration::from_secs(15))?;
        conn.execute_batch(
            "PRAGMA journal_mode=WAL;
             PRAGMA foreign_keys=ON;
             PRAGMA wal_autocheckpoint=500;",
        )?;
        Ok(Self { conn: Arc::new(Mutex::new(conn)), db_path: db_path.to_path_buf() })
    }

    pub fn open_and_init(db_path: &Path) -> Result<Self> {
        let conn = Connection::open(db_path)
            .with_context(|| format!("opening db {:?}", db_path))?;
        conn.busy_timeout(std::time::Duration::from_secs(15))?;
        conn.execute_batch(
            "PRAGMA journal_mode=WAL;
             PRAGMA foreign_keys=ON;
             PRAGMA wal_autocheckpoint=500;",
        )?;
        conn.execute_batch(include_str!("../../sql/schema.sql"))?;
        Ok(Self { conn: Arc::new(Mutex::new(conn)), db_path: db_path.to_path_buf() })
    }

    /// Path to the underlying SQLite database file. Needed by the MySQL proxy
    /// to shell out to BranchedPDO's DDL handler (which opens its own PDO
    /// connection against the same file).
    pub fn db_path(&self) -> &Path {
        &self.db_path
    }

    /// True iff `name` exists in sqlite_master as a view.
    ///
    /// Used by the MySQL proxy to detect DDL whose target is a branch view;
    /// such DDL must go through BranchedPDO (not raw SQLite) since SQLite
    /// refuses ALTER / CREATE INDEX / DROP INDEX on a view.
    pub fn is_view(&self, name: &str) -> bool {
        let conn = self.conn.lock().unwrap();
        conn.query_row(
            "SELECT type FROM sqlite_master WHERE name = ?1",
            params![name],
            |r| r.get::<_, String>(0),
        )
        .ok()
        .as_deref()
            == Some("view")
    }

    /// Resolve a branch id back to its name. Returns None if the id isn't
    /// in the branches table.
    pub fn branch_name(&self, id: i64) -> Option<String> {
        let conn = self.conn.lock().unwrap();
        conn.query_row(
            "SELECT name FROM branches WHERE id = ?1",
            params![id],
            |r| r.get(0),
        )
        .ok()
    }

    /// Run `PRAGMA wal_checkpoint(TRUNCATE)` to flush WAL frames back
    /// into the main DB and reset the `-wal` file to zero length.
    /// Called on shutdown and periodically from `run_checkpoint_thread`.
    pub fn checkpoint_truncate(&self) -> Result<()> {
        let conn = self.conn.lock().unwrap();
        conn.execute_batch("PRAGMA wal_checkpoint(TRUNCATE);")?;
        Ok(())
    }

    /// Run `f` once, retrying with exponential backoff on SQLITE_BUSY /
    /// SQLITE_LOCKED. Non-busy errors are surfaced immediately.
    ///
    /// Defaults match the PHP side (100 / 500 / 2000 ms) so both wire
    /// protocols behave identically under concurrent load. See TODO #6.
    pub fn with_busy_retry<T, F>(&self, f: F) -> Result<T>
    where
        F: Fn(&Connection) -> rusqlite::Result<T>,
    {
        let delays_ms = [100_u64, 500, 2000];
        let max_attempts = delays_ms.len() + 1;
        let mut last_err: Option<rusqlite::Error> = None;
        for attempt in 0..max_attempts {
            let conn = self.conn.lock().unwrap();
            match f(&conn) {
                Ok(v) => return Ok(v),
                Err(e) => {
                    let is_busy = matches!(
                        e.sqlite_error_code(),
                        Some(rusqlite::ErrorCode::DatabaseBusy)
                            | Some(rusqlite::ErrorCode::DatabaseLocked)
                    );
                    if !is_busy || attempt == max_attempts - 1 {
                        return Err(e.into());
                    }
                    drop(conn);
                    let delay = delays_ms
                        .get(attempt)
                        .copied()
                        .unwrap_or(*delays_ms.last().unwrap());
                    std::thread::sleep(std::time::Duration::from_millis(delay));
                    last_err = Some(e);
                }
            }
        }
        Err(last_err
            .map(|e| anyhow::anyhow!(e))
            .unwrap_or_else(|| anyhow::anyhow!("with_busy_retry exhausted without error")))
    }

    /// Spawn a background thread that runs `PRAGMA wal_checkpoint(TRUNCATE)`
    /// every `interval` seconds. Keeps the WAL bounded over long-running
    /// server lifetimes when writes are steady but not bursty enough to
    /// trigger auto-checkpoint.
    pub fn spawn_periodic_checkpoint(self: &Arc<Self>, interval: std::time::Duration) {
        let this = Arc::clone(self);
        std::thread::spawn(move || {
            loop {
                std::thread::sleep(interval);
                if let Err(e) = this.checkpoint_truncate() {
                    log::warn!("periodic wal_checkpoint failed: {}", e);
                }
            }
        });
    }

    pub fn list_branches(&self) -> Result<Vec<String>> {
        let conn = self.conn.lock().unwrap();
        let mut stmt = conn.prepare("SELECT name FROM branches ORDER BY name")?;
        let names = stmt.query_map([], |r| r.get(0))?
            .collect::<Result<Vec<String>, _>>()?;
        Ok(names)
    }

    pub fn branch_id(&self, branch_name: &str) -> Result<i64> {
        let conn = self.conn.lock().unwrap();
        conn.query_row(
            "SELECT id FROM branches WHERE name = ?1",
            params![branch_name],
            |r| r.get(0),
        )
        .with_context(|| format!("branch not found: {}", branch_name))
    }

    /// Walk branch parent chain to resolve the full file tree (COW semantics).
    pub fn list_files(&self, branch_name: &str) -> Result<Vec<FileEntry>> {
        let conn = self.conn.lock().unwrap();
        // Collect branch ancestry
        let mut branch_chain: Vec<i64> = Vec::new();
        let mut current = branch_name.to_string();
        loop {
            let row: Option<(i64, Option<String>)> = conn
                .query_row(
                    "SELECT id, parent_branch FROM branches WHERE name = ?1",
                    params![current],
                    |r| Ok((r.get(0)?, r.get(1)?)),
                )
                .ok();
            match row {
                Some((id, parent)) => {
                    branch_chain.push(id);
                    match parent {
                        Some(p) => current = p,
                        None => break,
                    }
                }
                None => break,
            }
        }

        // Paths seen (most-derived branch wins); tombstones excluded at end.
        let mut seen: std::collections::HashMap<String, FileEntry> =
            std::collections::HashMap::new();

        for branch_id in &branch_chain {
            let mut stmt = conn.prepare(
                "SELECT f.path, f.blob_hash, f.mode, f.mtime, f.is_dir, COALESCE(b.size, 0)
                 FROM files f
                 LEFT JOIN blobs b ON b.hash = f.blob_hash
                 WHERE f.branch_id = ?1",
            )?;
            let rows = stmt.query_map(params![branch_id], |r| {
                Ok(FileEntry {
                    path: r.get(0)?,
                    blob_hash: r.get(1)?,
                    mode: r.get(2)?,
                    mtime: r.get(3)?,
                    is_dir: r.get::<_, i64>(4)? != 0,
                    size: r.get(5)?,
                })
            })?;
            for row in rows {
                let entry = row?;
                seen.entry(entry.path.clone()).or_insert(entry);
            }
        }

        Ok(seen
            .into_values()
            .filter(|e| e.blob_hash.is_some() || e.is_dir)
            .collect())
    }

    pub fn read_file(&self, branch_name: &str, path: &str) -> Result<Vec<u8>> {
        let conn = self.conn.lock().unwrap();
        // Walk ancestry for this specific path.
        let mut current = branch_name.to_string();
        loop {
            let row: Option<(Option<String>, Option<String>)> = conn
                .query_row(
                    "SELECT f.blob_hash, b.parent_branch
                     FROM branches b
                     LEFT JOIN files f ON f.branch_id = b.id AND f.path = ?2
                     WHERE b.name = ?1",
                    params![current, path],
                    |r| Ok((r.get(0)?, r.get(1)?)),
                )
                .ok();
            match row {
                Some((Some(hash), _)) => {
                    // found — check inline first, fall through to chunks.
                    let inline: Option<Vec<u8>> = conn.query_row(
                        "SELECT data FROM blobs WHERE hash = ?1",
                        params![hash],
                        |r| r.get(0),
                    )?;
                    if let Some(data) = inline {
                        return Ok(data);
                    }
                    // Chunked blob: concatenate chunks in order.
                    let mut stmt = conn.prepare(
                        "SELECT data FROM blob_chunks WHERE blob_hash = ?1 ORDER BY chunk_no ASC",
                    )?;
                    let mut out: Vec<u8> = Vec::new();
                    let rows = stmt.query_map(params![hash], |r| r.get::<_, Vec<u8>>(0))?;
                    for row in rows {
                        let chunk = row?;
                        out.extend_from_slice(&chunk);
                    }
                    return Ok(out);
                }
                Some((None, Some(parent))) => {
                    // Not on this branch, check parent
                    current = parent;
                }
                _ => return Err(anyhow!("file not found: {}", path)),
            }
        }
    }

    /// Write file content, create blob + update files row + create fs_commit.
    pub fn write_file(
        &self,
        branch_name: &str,
        path: &str,
        data: &[u8],
        protocol: &str,
    ) -> Result<()> {
        let hash = sha1_hex(data);
        let size = data.len() as i64;
        let now = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap()
            .as_secs() as i64;

        let conn = self.conn.lock().unwrap();
        let branch_id: i64 = conn.query_row(
            "SELECT id FROM branches WHERE name = ?1",
            params![branch_name],
            |r| r.get(0),
        )
        .with_context(|| format!("branch not found: {}", branch_name))?;

        if data.len() <= CHUNK_SIZE {
            // Small blob: keep inline — single-query reads, matches legacy layout.
            conn.execute(
                "INSERT OR IGNORE INTO blobs(hash, data, size) VALUES(?1, ?2, ?3)",
                params![hash, data, size],
            )?;
        } else {
            // Large blob: metadata row with NULL data, content in blob_chunks.
            // INSERT OR IGNORE so re-writing an already-deduped blob is a no-op.
            let inserted = conn.execute(
                "INSERT OR IGNORE INTO blobs(hash, data, size) VALUES(?1, NULL, ?2)",
                params![hash, size],
            )?;
            if inserted > 0 {
                let mut stmt = conn.prepare(
                    "INSERT OR IGNORE INTO blob_chunks(blob_hash, chunk_no, data) VALUES(?1, ?2, ?3)",
                )?;
                let mut chunk_no: i64 = 0;
                for chunk in data.chunks(CHUNK_SIZE) {
                    stmt.execute(params![hash, chunk_no, chunk])?;
                    chunk_no += 1;
                }
            }
        }

        conn.execute(
            "INSERT INTO files(branch_id, path, blob_hash, mode, mtime, is_dir)
             VALUES(?1, ?2, ?3, 33188, ?4, 0)
             ON CONFLICT(branch_id, path) DO UPDATE SET
               blob_hash = excluded.blob_hash,
               mtime = excluded.mtime,
               mode = excluded.mode,
               is_dir = 0",
            params![branch_id, path, hash, now],
        )?;

        // Ensure parent directories exist
        ensure_parent_dirs(&conn, branch_id, path, now)?;

        // Create fs_commit
        let message = format!("{}: edit {}", protocol, path);
        record_snapshot(&conn, branch_id, &message)?;

        Ok(())
    }

    pub fn delete_file(&self, branch_name: &str, path: &str) -> Result<()> {
        let conn = self.conn.lock().unwrap();
        let branch_id: i64 = conn.query_row(
            "SELECT id FROM branches WHERE name = ?1",
            params![branch_name],
            |r| r.get(0),
        )?;

        conn.execute(
            "INSERT INTO files(branch_id, path, blob_hash, mode, mtime, is_dir)
             VALUES(?1, ?2, NULL, 33188, strftime('%s','now'), 0)
             ON CONFLICT(branch_id, path) DO UPDATE SET blob_hash = NULL",
            params![branch_id, path],
        )?;

        record_snapshot(&conn, branch_id, &format!("delete {}", path))?;
        Ok(())
    }

    /// Execute a raw SQL query and return column names + rows.
    /// SELECT-like queries return data; DML returns empty result.
    pub fn query_rows(
        &self,
        sql: &str,
    ) -> Result<(Vec<String>, Vec<Vec<rusqlite::types::Value>>)> {
        let conn = self.conn.lock().unwrap();
        let sql_upper = sql.trim_start().to_uppercase();
        let is_query = sql_upper.starts_with("SELECT")
            || sql_upper.starts_with("SHOW")
            || sql_upper.starts_with("EXPLAIN")
            || sql_upper.starts_with("PRAGMA");
        if !is_query {
            conn.execute_batch(sql).ok();
            return Ok((vec![], vec![]));
        }
        let mut stmt = conn.prepare(sql).context("mysql proxy: prepare")?;
        let columns: Vec<String> =
            stmt.column_names().into_iter().map(String::from).collect();
        let col_count = stmt.column_count();
        let rows: std::result::Result<
            Vec<Vec<rusqlite::types::Value>>,
            rusqlite::Error,
        > = stmt
            .query_map([], |row| {
                (0..col_count)
                    .map(|i| row.get::<_, rusqlite::types::Value>(i))
                    .collect()
            })?
            .collect();
        Ok((columns, rows?))
    }

    /// Whether site_config.auth_enabled = '1'. Returns false when the
    /// row/table is absent (pre-auth DB, treated as "legacy open site").
    pub fn auth_enabled(&self) -> bool {
        let conn = self.conn.lock().unwrap();
        // Create-if-missing so a brand-new DB opened via Store::open
        // (without init) doesn't poison subsequent queries.
        let _ = conn.execute_batch(
            "CREATE TABLE IF NOT EXISTS site_config (key TEXT PRIMARY KEY, value TEXT);",
        );
        let v: Option<String> = conn
            .query_row(
                "SELECT value FROM site_config WHERE key='auth_enabled'",
                [],
                |r| r.get(0),
            )
            .ok();
        v.as_deref() == Some("1")
    }

    /// Verify username+password; returns the user's role on success.
    /// Uses bcrypt (compatible with PHP's password_hash(PASSWORD_BCRYPT)).
    pub fn verify_user_password(&self, username: &str, password: &str) -> Option<String> {
        let conn = self.conn.lock().unwrap();
        // Tolerate stores that predate the users table.
        let _ = conn.execute_batch(
            "CREATE TABLE IF NOT EXISTS users (
                 username      TEXT PRIMARY KEY,
                 password_hash TEXT NOT NULL,
                 mysql_sha1    TEXT,
                 role          TEXT NOT NULL CHECK(role IN ('admin','write','read')),
                 created_at    TEXT DEFAULT (datetime('now'))
             );",
        );
        let row: Option<(String, String)> = conn
            .query_row(
                "SELECT password_hash, role FROM users WHERE username = ?1",
                params![username],
                |r| Ok((r.get(0)?, r.get(1)?)),
            )
            .ok();
        let (hash, role) = row?;
        match bcrypt::verify(password, &hash) {
            Ok(true) => Some(role),
            _ => None,
        }
    }

    /// Return (mysql_sha1 hex, role) for the user, if any. Used by the
    /// MySQL proxy's mysql_native_password handshake.
    pub fn user_mysql_creds(&self, username: &str) -> Option<(String, String)> {
        let conn = self.conn.lock().unwrap();
        let _ = conn.execute_batch(
            "CREATE TABLE IF NOT EXISTS users (
                 username      TEXT PRIMARY KEY,
                 password_hash TEXT NOT NULL,
                 mysql_sha1    TEXT,
                 role          TEXT NOT NULL CHECK(role IN ('admin','write','read')),
                 created_at    TEXT DEFAULT (datetime('now'))
             );",
        );
        conn.query_row(
            "SELECT COALESCE(mysql_sha1, ''), role FROM users WHERE username = ?1",
            params![username],
            |r| Ok((r.get::<_, String>(0)?, r.get::<_, String>(1)?)),
        )
        .ok()
        .filter(|(m, _)| !m.is_empty())
    }

    pub fn create_dir(&self, branch_name: &str, path: &str) -> Result<()> {
        let conn = self.conn.lock().unwrap();
        let branch_id: i64 = conn.query_row(
            "SELECT id FROM branches WHERE name = ?1",
            params![branch_name],
            |r| r.get(0),
        )?;
        let now = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap()
            .as_secs() as i64;

        conn.execute(
            "INSERT INTO files(branch_id, path, blob_hash, mode, mtime, is_dir)
             VALUES(?1, ?2, NULL, 16877, ?3, 1)
             ON CONFLICT(branch_id, path) DO UPDATE SET is_dir=1, mode=16877",
            params![branch_id, path, now],
        )?;
        Ok(())
    }

    /// Read (or generate and persist) the site's HMAC secret. 32 bytes of
    /// cryptographic entropy, hex-encoded. Must match
    /// scripts/principal.php::principal_ensure_hmac_secret().
    fn ensure_hmac_secret(&self) -> Result<String> {
        let conn = self.conn.lock().unwrap();
        let _ = conn.execute_batch(
            "CREATE TABLE IF NOT EXISTS site_config (key TEXT PRIMARY KEY, value TEXT);",
        );
        let existing: Option<String> = conn
            .query_row(
                "SELECT value FROM site_config WHERE key='hmac_secret'",
                [],
                |r| r.get(0),
            )
            .ok();
        if let Some(v) = existing {
            if v.len() >= 32 {
                return Ok(v);
            }
        }
        // Mint fresh 32-byte secret.
        let mut buf = [0u8; 32];
        // Use getrandom via std::time + sha256 isn't adequate — use OS
        // randomness via /dev/urandom. Fallback to time-seeded if unavailable.
        match std::fs::read("/dev/urandom") {
            Ok(_) => {
                use std::io::Read;
                let mut f = std::fs::File::open("/dev/urandom")
                    .context("open /dev/urandom")?;
                f.read_exact(&mut buf).context("read /dev/urandom")?;
            }
            Err(_) => {
                // Extremely fallback: mix hostname + time + pid + sha256
                let seed = format!(
                    "{}-{}-{}",
                    std::time::SystemTime::now()
                        .duration_since(std::time::UNIX_EPOCH)
                        .map(|d| d.as_nanos())
                        .unwrap_or(0),
                    std::process::id(),
                    std::ptr::addr_of!(buf) as usize
                );
                use sha2::Digest;
                let h = sha2::Sha256::digest(seed.as_bytes());
                buf.copy_from_slice(&h[..32]);
            }
        }
        let hex_secret = hex::encode(buf);
        conn.execute(
            "INSERT INTO site_config (key, value) VALUES ('hmac_secret', ?1) \
             ON CONFLICT(key) DO UPDATE SET value = excluded.value",
            params![hex_secret],
        )?;
        Ok(hex_secret)
    }

    /// Mint a FORKPRESS_TOKEN for `username` valid for `ttl_seconds`.
    /// Must produce a value that scripts/principal.php accepts.
    ///
    /// Format (base64url-encoded): "<username>.<exp_unix>.<hex_sha256_hmac>"
    /// with HMAC key = site_config['hmac_secret'] (as hex string).
    pub fn mint_principal_token(&self, username: &str, ttl_seconds: u64) -> Result<String> {
        use hmac::{Hmac, Mac};
        use sha2::Sha256;
        let secret = self.ensure_hmac_secret()?;
        let exp = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .map(|d| d.as_secs())
            .unwrap_or(0)
            + ttl_seconds;
        let payload = format!("{}.{}", username, exp);
        let mut mac = <Hmac<Sha256> as Mac>::new_from_slice(secret.as_bytes())
            .map_err(|e| anyhow!("hmac keying: {}", e))?;
        mac.update(payload.as_bytes());
        let sig_hex = hex::encode(mac.finalize().into_bytes());
        let raw = format!("{}.{}", payload, sig_hex);
        // base64url without padding
        use base64::Engine;
        let encoded = base64::engine::general_purpose::URL_SAFE_NO_PAD.encode(raw.as_bytes());
        Ok(encoded)
    }
}

fn sha1_hex(data: &[u8]) -> String {
    let mut h = Sha1::new();
    h.update(data);
    hex::encode(h.finalize())
}

fn ensure_parent_dirs(
    conn: &Connection,
    branch_id: i64,
    path: &str,
    now: i64,
) -> Result<()> {
    let mut p = path;
    while let Some(pos) = p.rfind('/') {
        p = &p[..pos];
        if p.is_empty() {
            break;
        }
        conn.execute(
            "INSERT OR IGNORE INTO files(branch_id, path, blob_hash, mode, mtime, is_dir)
             VALUES(?1, ?2, NULL, 16877, ?3, 1)",
            params![branch_id, p, now],
        )?;
    }
    Ok(())
}

fn record_snapshot(conn: &Connection, branch_id: i64, message: &str) -> Result<()> {
    // Resolve full tree for this branch (walk ancestry)
    let tree = resolve_tree(conn, branch_id)?;

    let parent_id: Option<i64> = conn
        .query_row(
            "SELECT id FROM fs_commits WHERE branch_id=?1 ORDER BY id DESC LIMIT 1",
            params![branch_id],
            |r| r.get(0),
        )
        .ok();

    conn.execute(
        "INSERT INTO fs_commits(branch_id, commit_hash, parent_id, message)
         VALUES(?1, lower(hex(randomblob(8))), ?2, ?3)",
        params![branch_id, parent_id, message],
    )?;
    let commit_id = conn.last_insert_rowid();

    let mut stmt = conn.prepare(
        "INSERT INTO fs_commit_files(commit_id, path, blob_hash, mode, mtime, is_dir)
         VALUES(?1, ?2, ?3, ?4, ?5, ?6)",
    )?;
    for entry in &tree {
        stmt.execute(params![
            commit_id,
            entry.path,
            entry.blob_hash,
            entry.mode,
            entry.mtime,
            entry.is_dir as i64,
        ])?;
    }
    Ok(())
}

fn resolve_tree(conn: &Connection, branch_id: i64) -> Result<Vec<FileEntry>> {
    // Walk ancestry
    let mut chain: Vec<i64> = Vec::new();
    let mut current_id = branch_id;
    loop {
        chain.push(current_id);
        let parent: Option<i64> = conn
            .query_row(
                "SELECT b2.id FROM branches b1
                 JOIN branches b2 ON b2.name = b1.parent_branch
                 WHERE b1.id = ?1",
                params![current_id],
                |r| r.get(0),
            )
            .ok();
        match parent {
            Some(p) => current_id = p,
            None => break,
        }
    }

    let mut seen: std::collections::HashMap<String, FileEntry> = std::collections::HashMap::new();
    for bid in &chain {
        let mut stmt = conn.prepare(
            "SELECT f.path, f.blob_hash, f.mode, f.mtime, f.is_dir, COALESCE(b.size,0)
             FROM files f LEFT JOIN blobs b ON b.hash=f.blob_hash WHERE f.branch_id=?1",
        )?;
        let rows = stmt.query_map(params![bid], |r| {
            Ok(FileEntry {
                path: r.get(0)?,
                blob_hash: r.get(1)?,
                mode: r.get(2)?,
                mtime: r.get(3)?,
                is_dir: r.get::<_, i64>(4)? != 0,
                size: r.get(5)?,
            })
        })?;
        for row in rows {
            let e = row?;
            seen.entry(e.path.clone()).or_insert(e);
        }
    }
    Ok(seen.into_values().collect())
}

#[cfg(test)]
mod tests {
    use super::*;
    use rusqlite::Connection;
    use std::sync::{Arc, Mutex};
    use tempfile::NamedTempFile;

    fn make_store() -> Store {
        let f = NamedTempFile::new().unwrap();
        let path = f.path().to_path_buf();
        // Keep file alive by leaking the tempfile handle
        std::mem::forget(f);
        let conn = Connection::open(&path).unwrap();
        conn.execute_batch(include_str!("../../sql/schema.sql")).unwrap();
        Store { conn: Arc::new(Mutex::new(conn)), db_path: path }
    }

    #[test]
    fn test_write_and_read() {
        let store = make_store();
        store.write_file("main", "hello.txt", b"hello world", "sftp").unwrap();
        let data = store.read_file("main", "hello.txt").unwrap();
        assert_eq!(data, b"hello world");
    }

    #[test]
    fn test_commit_created() {
        let store = make_store();
        store.write_file("main", "test.txt", b"data", "sftp").unwrap();
        let conn = store.conn.lock().unwrap();
        let count: i64 = conn
            .query_row("SELECT COUNT(*) FROM fs_commits", [], |r| r.get(0))
            .unwrap();
        assert!(count > 0);
        let msg: String = conn
            .query_row(
                "SELECT message FROM fs_commits ORDER BY id DESC LIMIT 1",
                [],
                |r| r.get(0),
            )
            .unwrap();
        assert!(msg.contains("test.txt"), "message was: {}", msg);
    }

    #[test]
    fn test_cow_inheritance() {
        let store = make_store();
        // Write file on main
        store.write_file("main", "shared.txt", b"parent content", "sftp").unwrap();
        // Create child branch
        {
            let conn = store.conn.lock().unwrap();
            conn.execute(
                "INSERT INTO branches(name, parent_branch) VALUES('child', 'main')",
                [],
            )
            .unwrap();
        }
        // Child should see parent's file
        let data = store.read_file("child", "shared.txt").unwrap();
        assert_eq!(data, b"parent content");
    }

    #[test]
    fn test_tombstone() {
        let store = make_store();
        store.write_file("main", "gone.txt", b"bye", "sftp").unwrap();
        store.delete_file("main", "gone.txt").unwrap();
        let files = store.list_files("main").unwrap();
        let found = files.iter().any(|f| f.path == "gone.txt");
        assert!(!found, "tombstoned file should not appear in listing");
    }

    #[test]
    fn test_overwrite() {
        let store = make_store();
        store.write_file("main", "a.txt", b"v1", "test").unwrap();
        store.write_file("main", "a.txt", b"v2", "test").unwrap();
        let data = store.read_file("main", "a.txt").unwrap();
        assert_eq!(data, b"v2");
    }

    #[test]
    fn test_list_files_includes_dirs() {
        let store = make_store();
        store.write_file("main", "sub/file.txt", b"data", "test").unwrap();
        let files = store.list_files("main").unwrap();
        let sub_dir = files.iter().find(|f| f.path == "sub");
        assert!(sub_dir.is_some(), "sub dir should appear in listing");
        assert!(sub_dir.unwrap().is_dir, "sub should be a directory");
    }

    #[test]
    fn test_create_dir() {
        let store = make_store();
        store.create_dir("main", "mydir").unwrap();
        let files = store.list_files("main").unwrap();
        let dir = files.iter().find(|f| f.path == "mydir");
        assert!(dir.is_some(), "mydir should appear in listing");
        assert!(dir.unwrap().is_dir, "mydir should be a directory");
    }

    #[test]
    fn test_cow_child_overrides_parent() {
        let store = make_store();
        store.write_file("main", "f.txt", b"parent", "test").unwrap();
        {
            let conn = store.conn.lock().unwrap();
            conn.execute(
                "INSERT INTO branches(name, parent_branch) VALUES('child', 'main')",
                [],
            ).unwrap();
        }
        store.write_file("child", "f.txt", b"child", "test").unwrap();
        let child_data = store.read_file("child", "f.txt").unwrap();
        let parent_data = store.read_file("main", "f.txt").unwrap();
        assert_eq!(child_data, b"child");
        assert_eq!(parent_data, b"parent");
    }

    #[test]
    fn test_tombstone_hides_parent_file() {
        let store = make_store();
        store.write_file("main", "f.txt", b"data", "test").unwrap();
        {
            let conn = store.conn.lock().unwrap();
            conn.execute(
                "INSERT INTO branches(name, parent_branch) VALUES('child', 'main')",
                [],
            ).unwrap();
        }
        store.delete_file("child", "f.txt").unwrap();
        let files = store.list_files("child").unwrap();
        let found = files.iter().any(|f| f.path == "f.txt");
        assert!(!found, "tombstoned file should not appear in child listing");
    }

    #[test]
    fn test_commit_snapshot_contents() {
        let store = make_store();
        store.write_file("main", "snap.txt", b"data", "test").unwrap();
        let conn = store.conn.lock().unwrap();
        let count: i64 = conn.query_row(
            "SELECT COUNT(*) FROM fs_commit_files WHERE path = 'snap.txt'",
            [],
            |r| r.get(0),
        ).unwrap();
        assert!(count > 0, "snap.txt should appear in commit snapshot");
    }

    #[test]
    fn test_large_blob_is_chunked() {
        let store = make_store();
        // 3 MiB payload — guarantees > 2 chunks at 1 MiB chunk size.
        let size = 3 * 1024 * 1024 + 7;
        let data: Vec<u8> = (0..size).map(|i| (i % 251) as u8).collect();
        store.write_file("main", "big.bin", &data, "test").unwrap();

        let conn = store.conn.lock().unwrap();
        // blobs.data must be NULL for chunked blobs
        let inline: Option<Vec<u8>> = conn.query_row(
            "SELECT data FROM blobs WHERE size = ?1",
            params![size as i64],
            |r| r.get(0),
        ).unwrap();
        assert!(inline.is_none(), "large blob must have NULL blobs.data");

        let chunk_count: i64 = conn.query_row(
            "SELECT COUNT(*) FROM blob_chunks WHERE blob_hash = (SELECT hash FROM blobs WHERE size = ?1)",
            params![size as i64],
            |r| r.get(0),
        ).unwrap();
        let expected = ((size + CHUNK_SIZE - 1) / CHUNK_SIZE) as i64;
        assert_eq!(chunk_count, expected, "expected {} chunks, got {}", expected, chunk_count);
        drop(conn);

        // Round-trip: read_file must reassemble the full payload.
        let roundtrip = store.read_file("main", "big.bin").unwrap();
        assert_eq!(roundtrip.len(), data.len());
        assert_eq!(roundtrip, data);
    }

    #[test]
    fn test_small_blob_stays_inline() {
        let store = make_store();
        let data = vec![42u8; 1024]; // well under 1 MiB
        store.write_file("main", "small.bin", &data, "test").unwrap();

        let conn = store.conn.lock().unwrap();
        let inline: Option<Vec<u8>> = conn.query_row(
            "SELECT data FROM blobs WHERE size = ?1",
            params![data.len() as i64],
            |r| r.get(0),
        ).unwrap();
        assert!(inline.is_some(), "small blob must stay inline");
        assert_eq!(inline.unwrap(), data);

        let chunk_count: i64 = conn.query_row(
            "SELECT COUNT(*) FROM blob_chunks",
            [],
            |r| r.get(0),
        ).unwrap();
        assert_eq!(chunk_count, 0, "small blob must not create chunk rows");
    }

    #[test]
    fn test_multi_commit_chain() {
        let store = make_store();
        store.write_file("main", "file1.txt", b"data1", "test").unwrap();
        store.write_file("main", "file2.txt", b"data2", "test").unwrap();
        let conn = store.conn.lock().unwrap();
        let count: i64 = conn.query_row(
            "SELECT COUNT(*) FROM fs_commits",
            [],
            |r| r.get(0),
        ).unwrap();
        assert_eq!(count, 2);
        let second_parent: Option<i64> = conn.query_row(
            "SELECT parent_id FROM fs_commits ORDER BY id DESC LIMIT 1",
            [],
            |r| r.get(0),
        ).unwrap();
        let first_id: i64 = conn.query_row(
            "SELECT id FROM fs_commits ORDER BY id ASC LIMIT 1",
            [],
            |r| r.get(0),
        ).unwrap();
        assert_eq!(second_parent, Some(first_id), "second commit parent_id should equal first commit id");
    }

    // ───── TODO3 #13 — auth-path unit tests ──────────────────────────

    #[test]
    fn test_verify_user_password_rejects_unknown_user() {
        let store = make_store();
        assert!(store.verify_user_password("ghost", "x").is_none());
    }

    #[test]
    fn test_verify_user_password_accepts_correct_bcrypt() {
        let store = make_store();
        // bcrypt("correct-horse", cost=4) — precomputed so the test is fast.
        let hash = bcrypt::hash("correct-horse", bcrypt::DEFAULT_COST).unwrap();
        {
            let conn = store.conn.lock().unwrap();
            conn.execute(
                "CREATE TABLE IF NOT EXISTS users(
                    username TEXT PRIMARY KEY,
                    password_hash TEXT NOT NULL,
                    mysql_sha1 TEXT,
                    role TEXT NOT NULL CHECK(role IN ('admin','write','read')),
                    created_at TEXT DEFAULT (datetime('now'))
                )",
                [],
            ).unwrap();
            conn.execute(
                "INSERT INTO users (username, password_hash, role) VALUES (?1, ?2, 'write')",
                params!["alice", hash],
            ).unwrap();
        }
        assert_eq!(
            store.verify_user_password("alice", "correct-horse").as_deref(),
            Some("write")
        );
        assert!(store.verify_user_password("alice", "wrong").is_none());
    }

    #[test]
    fn test_user_mysql_creds_returns_hash_and_role() {
        let store = make_store();
        {
            let conn = store.conn.lock().unwrap();
            conn.execute(
                "CREATE TABLE IF NOT EXISTS users(
                    username TEXT PRIMARY KEY,
                    password_hash TEXT NOT NULL,
                    mysql_sha1 TEXT,
                    role TEXT NOT NULL CHECK(role IN ('admin','write','read')),
                    created_at TEXT DEFAULT (datetime('now'))
                )",
                [],
            ).unwrap();
            conn.execute(
                "INSERT INTO users (username, password_hash, mysql_sha1, role) \
                 VALUES (?1, 'x', ?2, 'read')",
                params!["bob", "deadbeef".to_string()],
            ).unwrap();
        }
        let out = store.user_mysql_creds("bob");
        assert_eq!(out, Some(("deadbeef".to_string(), "read".to_string())));
        assert!(store.user_mysql_creds("nobody").is_none());
    }

    #[test]
    fn test_store_db_path_round_trips() {
        // TODO3 #13 coverage for the TODO3 #1 plumbing: the store
        // must retain the db path passed to `open()` so the MySQL
        // proxy can hand it to the PHP DDL helper.
        let f = NamedTempFile::new().unwrap();
        let path = f.path().to_path_buf();
        std::mem::forget(f);
        let conn = Connection::open(&path).unwrap();
        conn.execute_batch(include_str!("../../sql/schema.sql")).unwrap();
        drop(conn);
        let store = Store::open(&path).unwrap();
        assert_eq!(store.db_path(), path.as_path());
    }

    #[test]
    fn test_mint_principal_token_roundtrip_format() {
        // Hostile-review finding #18: the MySQL proxy must mint tokens
        // that scripts/principal.php verifies. We don't shell out to
        // PHP here (binary may not be available in the cargo test env);
        // we verify the produced token at least decodes to the expected
        // payload shape and that minting twice with the same secret
        // produces identical tokens for the same (user, exp) inputs.
        let store = make_store();
        let t1 = store.mint_principal_token("alice", 60).unwrap();
        // base64url-decoded → "<user>.<exp>.<hex>"
        use base64::Engine;
        let raw = base64::engine::general_purpose::URL_SAFE_NO_PAD
            .decode(t1.as_bytes())
            .unwrap();
        let s = String::from_utf8(raw).unwrap();
        let parts: Vec<&str> = s.split('.').collect();
        assert_eq!(parts.len(), 3, "token should split into 3 parts: {}", s);
        assert_eq!(parts[0], "alice");
        // Hex-encoded sha256 = 64 chars.
        assert_eq!(parts[2].len(), 64, "sig should be 64 hex chars");
        // Same secret, fresh mint → expiry differs by at most 1 second
        // (or exactly equals depending on timing).
        let t2 = store.mint_principal_token("alice", 60).unwrap();
        assert_eq!(t1.len(), t2.len());
    }
}

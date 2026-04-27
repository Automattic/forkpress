-- BranchFS SQLite Schema
-- Content-addressed blob store with per-branch copy-on-write file overlays

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS blobs (
    hash        TEXT PRIMARY KEY,
    -- data is NULL for blobs whose payload lives in blob_chunks (size > 1 MB).
    -- Small blobs (<= 1 MB) keep the payload inline for single-query reads
    -- and backward compatibility with legacy .fp files.
    data        BLOB,
    size        INTEGER NOT NULL
);

-- Chunked storage for large blobs. Each chunk is a 1 MB slice of the blob
-- content, keyed by (blob_hash, chunk_no). chunk_no starts at 0 and is
-- contiguous. Only present for blobs whose blobs.data IS NULL.
-- See PRD SF1 (chunked blob storage) and ext/branchfs.c::store_write_file.
CREATE TABLE IF NOT EXISTS blob_chunks (
    blob_hash TEXT NOT NULL,
    chunk_no  INTEGER NOT NULL,
    data      BLOB NOT NULL,
    PRIMARY KEY (blob_hash, chunk_no),
    FOREIGN KEY (blob_hash) REFERENCES blobs(hash) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_blob_chunks_hash ON blob_chunks(blob_hash);

CREATE TABLE IF NOT EXISTS branches (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT UNIQUE NOT NULL,
    parent_branch   TEXT,
    created_at      TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (parent_branch) REFERENCES branches(name)
);

CREATE TABLE IF NOT EXISTS files (
    branch_id   INTEGER NOT NULL,
    path        TEXT NOT NULL,
    blob_hash   TEXT,           -- NULL = tombstone (deleted on this branch)
    mode        INTEGER DEFAULT 33188,  -- 0100644 in octal
    mtime       INTEGER DEFAULT (strftime('%s','now')),
    is_dir      INTEGER DEFAULT 0,
    PRIMARY KEY (branch_id, path),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (blob_hash) REFERENCES blobs(hash)
);

CREATE INDEX IF NOT EXISTS idx_files_path ON files(path);
CREATE INDEX IF NOT EXISTS idx_files_branch_dir ON files(branch_id, path);

-- File-side commit graph. Each fs_commits row captures a full file-tree
-- snapshot at the moment a branchctl commit happened.
CREATE TABLE IF NOT EXISTS fs_commits (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id   INTEGER NOT NULL,
    commit_hash TEXT NOT NULL DEFAULT (lower(hex(randomblob(16)))),
    parent_id   INTEGER,                        -- previous fs_commit on this branch
    message     TEXT,
    created_at  TEXT DEFAULT (datetime('now')),
    UNIQUE (branch_id, commit_hash),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (parent_id) REFERENCES fs_commits(id)
);
CREATE INDEX IF NOT EXISTS idx_fs_commits_branch ON fs_commits(branch_id);
CREATE INDEX IF NOT EXISTS idx_fs_commits_hash ON fs_commits(branch_id, commit_hash);

-- Full snapshot per commit. Blobs are content-addressed so only the
-- metadata rows duplicate between commits; real file content is shared.
CREATE TABLE IF NOT EXISTS fs_commit_files (
    commit_id   INTEGER NOT NULL,
    path        TEXT NOT NULL,
    blob_hash   TEXT,                           -- NULL = tombstone
    mode        INTEGER,
    mtime       INTEGER,
    is_dir      INTEGER DEFAULT 0,
    PRIMARY KEY (commit_id, path),
    FOREIGN KEY (commit_id) REFERENCES fs_commits(id),
    FOREIGN KEY (blob_hash) REFERENCES blobs(hash)
);

-- Seed the 'main' branch
INSERT OR IGNORE INTO branches (name, parent_branch) VALUES ('main', NULL);

-- Authentication: per-site user accounts and site-wide config flags.
-- Every write surface (SFTP, SMB, MySQL proxy, git push) consults these
-- tables; see fileserver/src/store.rs verify_user_password / auth_enabled.
CREATE TABLE IF NOT EXISTS users (
    username      TEXT PRIMARY KEY,
    password_hash TEXT NOT NULL,
    mysql_sha1    TEXT,            -- SHA1(SHA1(password)) hex; required for MySQL native auth
    role          TEXT NOT NULL CHECK(role IN ('admin','write','read')),
    created_at    TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS site_config (
    key   TEXT PRIMARY KEY,
    value TEXT
);

-- Brand-new site: auth is enabled by default. Existing sites created
-- before this change get auth_enabled='0' in branchctl.php::fs_migrate().
INSERT OR IGNORE INTO site_config (key, value) VALUES ('auth_enabled', '1');

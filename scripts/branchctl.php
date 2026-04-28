<?php
/* : <<'__END__' */
/**
 * branchctl — manage branchfs branches from the command line.
 *
 * Usage:
 *   branchctl list
 *   branchctl create {name} [--from {parent}]
 *   branchctl commit {name} [-m "message"]
 *   branchctl delete {name}
 *   branchctl show {name}
 *
 * Environment:
 *   BRANCHFS_DB      path to the branchfs SQLite file    [/tmp/branchfs-dev/branchfs.db]
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

if (!extension_loaded('branchfs')) {
    fwrite(STDERR, "branchctl: branchfs extension not loaded. Use the bundled `forkpress branch ...` command.\n");
    exit(2);
}

function env_or(string $name, string $default): string {
    $v = getenv($name);
    return ($v === false || $v === '') ? $default : $v;
}

$DB_PATH = env_or('BRANCHFS_DB', '/tmp/branchfs-dev/branchfs.db');

function die_usage(?string $msg = null, int $code = 1): void {
    if ($msg !== null) fwrite(STDERR, "branchctl: $msg\n\n");
    fwrite(STDERR, <<<USAGE
Usage:
  branchctl list
  branchctl show    <name>
  branchctl create  <name>  [--from <parent>]
  branchctl delete  <name>

  branchctl commit  <name>  [-m "message"]
  branchctl log     <name>  [-n <count>]
  branchctl diff    <a> <b>  [--rows]   (--rows = row-level DB diff)
  branchctl status  <name>
  branchctl merge   <from>  --into <target>
  branchctl reset   <name>  <commit-hash>  [--force]
  branchctl rollback <name>  [--force]
  branchctl migrate <name>   — migrate a legacy (pre-COW) branch in place
  branchctl migrate --all    — migrate every legacy branch
  branchctl gc              [--dry-run]
  branchctl audit           [--since <ts>] [--actor <name>]

Flags:
  --db <path>   override BRANCHFS_DB (default: /tmp/branchfs-dev/branchfs.db)

Workflow:
  create   forks the branchfs overlay and records an initial snapshot.
  commit   snapshots the branch's current file tree into fs_commits.
  log      shows commit history for <name>.
  diff     per-file add/del/mod counts between two branch overlays.
  merge    runs scripts/merge.php: 3-way file merge only (DB merge not implemented).
  reset    hard-resets <name>'s file overlay to a specific commit hash.
           Refuses if uncommitted file-side changes exist unless --force.
  rollback shortcut: resets to the previous commit on <name>.
  delete   drops the overlay and removes the branch from SQLite.
  gc       deletes blobs not referenced by any live file or fs_commit.
           --dry-run prints what would be freed without deleting.

USAGE);
    exit($code);
}

function parse_args(array $argv): array {
    $pos = [];
    $flags = [];
    for ($i = 1; $i < count($argv); $i++) {
        $a = $argv[$i];
        if (str_starts_with($a, '--')) {
            $name = substr($a, 2);
            $next = $argv[$i + 1] ?? null;
            if ($next !== null && !str_starts_with($next, '-')) {
                $flags[$name] = $next;
                $i++;
            } else {
                $flags[$name] = true;
            }
        } elseif ($a === '-m') {
            $flags['message'] = $argv[++$i] ?? '';
        } elseif ($a === '-n') {
            $flags['n'] = $argv[++$i] ?? '';
        } else {
            $pos[] = $a;
        }
    }
    return [$pos, $flags];
}

[$pos, $flags] = parse_args($argv);
$cmd = $pos[0] ?? null;
if ($cmd === null || $cmd === 'help' || $cmd === '-h' || $cmd === '--help') die_usage(null, 0);

if (isset($flags['db'])) $DB_PATH = (string)$flags['db'];

if (!file_exists($DB_PATH)) {
    fwrite(STDERR, "branchctl: branchfs DB not found: $DB_PATH\n");
    fwrite(STDERR, "           Run `forkpress serve` first, or set BRANCHFS_DB.\n");
    exit(2);
}

function sqlite_open(string $path): SQLite3 {
    $db = new SQLite3($path, SQLITE3_OPEN_READWRITE);
    // 15s busy timeout on top of the retry helper: gives a loser writer
    // a long polling window before the helper escalates to backoff+retry.
    $db->busyTimeout(15000);
    // Keep WAL bounded under branchctl write bursts (create/merge/reset
    // all perform many writes in sequence).
    $db->exec('PRAGMA wal_autocheckpoint = 500');
    fs_migrate($db);
    return $db;
}

function branchfs_list(SQLite3 $db): array {
    $rows = [];
    $r = $db->query(
        "SELECT id, name, parent_branch, created_at "
      . "FROM branches ORDER BY name"
    );
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}

function branchfs_file_count(SQLite3 $db, int $branch_id): int {
    $s = $db->prepare("SELECT COUNT(*) FROM files WHERE branch_id = :bid");
    $s->bindValue(':bid', $branch_id, SQLITE3_INTEGER);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_NUM);
    return (int)($row[0] ?? 0);
}

function valid_branch_name(string $name): bool {
    return (bool)preg_match('/^[a-zA-Z0-9_\-]{1,63}$/', $name);
}

/**
 * Names reserved for HTTP routing / well-known subdomains. Creating a
 * branch with any of these would collide with the router's host parsing
 * (www.wp.localhost, admin.wp.localhost, etc.). Finding #11.
 */
function reserved_branch_names(): array {
    return ['www', 'admin', 'api', 'mail', 'localhost', 'wp'];
}

function is_reserved_branch_name(string $name): bool {
    return in_array(strtolower($name), reserved_branch_names(), true);
}

/* ================================================================
 * File-side commit graph (fs_commits + fs_commit_files)
 *
 * Each branchctl commit records a full snapshot of the branch's resolved
 * file tree. reset/rollback look up the matching fs_commit and materialize
 * it back into the branch overlay. Blobs are content-addressed, so the
 * only duplication per commit is the row-per-path in fs_commit_files.
 * ================================================================ */

function fs_migrate(SQLite3 $db): void {
    /* Idempotent; runs on every open so existing stores get the new tables
     * without a separate migration step. */
    $db->exec(<<<SQL
CREATE TABLE IF NOT EXISTS fs_commits (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id   INTEGER NOT NULL,
    commit_hash TEXT NOT NULL DEFAULT (lower(hex(randomblob(16)))),
    parent_id   INTEGER,
    message     TEXT,
    created_at  TEXT DEFAULT (datetime('now')),
    kind        TEXT NOT NULL DEFAULT 'FULL',    -- TODO3 #5: FULL | DELTA
    UNIQUE (branch_id, commit_hash),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (parent_id) REFERENCES fs_commits(id)
);
CREATE INDEX IF NOT EXISTS idx_fs_commits_branch ON fs_commits(branch_id);
CREATE INDEX IF NOT EXISTS idx_fs_commits_hash ON fs_commits(branch_id, commit_hash);
/* TODO3 #5: op encodes delta semantics for file-side commits.
 *   'UPSERT' — path exists at this commit with the given metadata
 *   'DELETE' — path existed at a prior commit but is gone in this one
 * Default 'UPSERT' keeps pre-TODO3 rows interpretable as a full snapshot. */
CREATE TABLE IF NOT EXISTS fs_commit_files (
    commit_id   INTEGER NOT NULL,
    path        TEXT NOT NULL,
    blob_hash   TEXT,
    mode        INTEGER,
    mtime       INTEGER,
    is_dir      INTEGER DEFAULT 0,
    op          TEXT NOT NULL DEFAULT 'UPSERT',
    PRIMARY KEY (commit_id, path),
    FOREIGN KEY (commit_id) REFERENCES fs_commits(id),
    FOREIGN KEY (blob_hash) REFERENCES blobs(hash)
);
CREATE TABLE IF NOT EXISTS db_snapshots (
    branch_id  INTEGER NOT NULL,
    table_name TEXT NOT NULL,
    row_pk     TEXT NOT NULL,
    row_json   TEXT NOT NULL,
    PRIMARY KEY (branch_id, table_name, row_pk)
);
/* ----------------------------------------------------------------
 * Per-commit DB snapshot (rows + tombstones + per-table schema).
 *
 * Storage cost is O(divergent rows the branch overlays) per commit,
 * not O(total branch rows): we snapshot only what's in the branch's
 * own overlays/tombstones, since inherited rows are reconstructable
 * from the parent's state at the same commit hash.
 *
 * db_commits is paired 1:1 with fs_commits via fs_commit_id +
 * commit_hash. branchctl commit/rollback/reset operate on both
 * tables atomically inside one transaction.
 * ---------------------------------------------------------------- */
CREATE TABLE IF NOT EXISTS db_commits (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id    INTEGER NOT NULL,
    fs_commit_id INTEGER,                              -- 1:1 link to fs_commits.id
    commit_hash  TEXT NOT NULL,                        -- mirrors fs_commits.commit_hash
    created_at   TEXT DEFAULT (datetime('now')),
    kind         TEXT NOT NULL DEFAULT 'FULL',         -- 'FULL' | 'DELTA' (TODO3 #2)
    UNIQUE (branch_id, commit_hash),
    FOREIGN KEY (branch_id)    REFERENCES branches(id),
    FOREIGN KEY (fs_commit_id) REFERENCES fs_commits(id)
);
CREATE INDEX IF NOT EXISTS idx_db_commits_branch ON db_commits(branch_id);
CREATE INDEX IF NOT EXISTS idx_db_commits_hash   ON db_commits(branch_id, commit_hash);
CREATE INDEX IF NOT EXISTS idx_db_commits_fs     ON db_commits(fs_commit_id);
/* TODO3 #2: op column encodes delta semantics.
 *   'UPSERT' — row exists in this commit with the given row_json
 *   'DELETE' — row was present in a prior commit but removed in this one
 * FULL commits contain only UPSERT rows (full snapshot of branch state).
 * DELTA commits contain UPSERT (added/changed) + DELETE (removed) since
 * the previous commit on this branch. Default 'UPSERT' keeps pre-TODO3
 * rows interpretable as full-snapshot. */
CREATE TABLE IF NOT EXISTS db_commit_overlays (
    commit_id    INTEGER NOT NULL,
    table_suffix TEXT NOT NULL,                  -- e.g. 'wp_posts'
    row_pk       TEXT NOT NULL,                  -- JSON-encoded PK map
    row_json     TEXT NOT NULL,                  -- JSON-encoded full row
    op           TEXT NOT NULL DEFAULT 'UPSERT', -- UPSERT | DELETE
    PRIMARY KEY (commit_id, table_suffix, row_pk),
    FOREIGN KEY (commit_id) REFERENCES db_commits(id)
);
CREATE TABLE IF NOT EXISTS db_commit_tombstones (
    commit_id    INTEGER NOT NULL,
    table_suffix TEXT NOT NULL,
    row_pk       TEXT NOT NULL,
    op           TEXT NOT NULL DEFAULT 'UPSERT', -- UPSERT | DELETE
    PRIMARY KEY (commit_id, table_suffix, row_pk),
    FOREIGN KEY (commit_id) REFERENCES db_commits(id)
);
CREATE TABLE IF NOT EXISTS db_commit_schema (
    commit_id    INTEGER NOT NULL,
    table_suffix TEXT NOT NULL,
    overlay_ddl  TEXT NOT NULL,                   -- CREATE TABLE for the overlay
    indexes_json TEXT NOT NULL DEFAULT '[]',      -- JSON array of CREATE INDEX statements
    PRIMARY KEY (commit_id, table_suffix),
    FOREIGN KEY (commit_id) REFERENCES db_commits(id)
);
/* COW (copy-on-write) branch fork markers. One row per (branch, table)
 * recorded at branch-create time. The marker captures the parent's
 * physical table name + a fork token (we use parent rowid max as a
 * cheap watermark). The presence of a row here also flags the branch
 * as "COW format" — branches without a row predate this feature and
 * are migrated lazily on first merge. */
CREATE TABLE IF NOT EXISTS db_cow_branches (
    branch_id          INTEGER NOT NULL,
    table_suffix       TEXT NOT NULL,             -- e.g. 'posts', 'options'
    parent_branch_id   INTEGER NOT NULL,
    parent_table_name  TEXT NOT NULL,             -- e.g. 'b1_wp_posts'
    fork_token         TEXT NOT NULL DEFAULT '',  -- opaque marker, opaque to merge
    created_at         TEXT DEFAULT (datetime('now')),
    PRIMARY KEY (branch_id, table_suffix)
);
/* Lazy fork-time ancestor capture for COW branches.
 *
 * On parent-side UPDATE/DELETE, an AFTER-row trigger pushes the OLD row
 * into this table for every descendant branch that has a COW marker for
 * the parent's table. The push is INSERT OR IGNORE so only the FIRST
 * pre-divergence value is preserved — that's the true fork-time ancestor
 * the branch saw via its inheriting view.
 *
 * Storage cost is O(parent UPDATE/DELETE events × descendant branches),
 * not O(total rows). For workflows where parents are mostly read-only
 * and branches are short-lived (the typical preview/PR pattern), this
 * stays small.
 *
 * row_pk_json is JSON-encoded ordered PK column map.
 * row_json is the full pre-change row (for UPDATE: OLD values; for
 * DELETE: the deleted row).
 */
CREATE TABLE IF NOT EXISTS db_ancestor_overlay (
    branch_id  INTEGER NOT NULL,
    table_name TEXT NOT NULL,             -- the BRANCH's logical name, e.g. b2_wp_posts
    row_pk     TEXT NOT NULL,             -- JSON-encoded PK
    row_json   TEXT NOT NULL,             -- full row JSON
    created_at TEXT DEFAULT (datetime('now')),
    PRIMARY KEY (branch_id, table_name, row_pk)
);
/* Companion to db_ancestor_overlay: PKs the parent INSERTED after the
 * fork. Lets the merge correctly recognize "both inserted same PK
 * independently" as a conflict (ancestor absent), instead of "source
 * adopted target's row" (false ancestor lookup). */
CREATE TABLE IF NOT EXISTS db_post_fork_inserts (
    branch_id  INTEGER NOT NULL,
    table_name TEXT NOT NULL,             -- the BRANCH's logical name
    row_pk     TEXT NOT NULL,             -- JSON-encoded PK
    PRIMARY KEY (branch_id, table_name, row_pk)
);
/* TODO3 #3: shared ancestor snapshot, keyed by parent table (NOT branch_id).
 *
 * Parent-side BEFORE UPDATE/DELETE triggers insert ONCE into this table per
 * parent write, regardless of how many descendant branches exist. Merge.php
 * fans the row out across descendants at merge time (only for branches
 * that actually diverge).
 *
 * Cost of a parent UPDATE is now O(1), independent of descendant count.
 * Pre-TODO3 the per-descendant fanout trigger cost O(num descendants) per
 * parent write, degrading parent throughput linearly with branch count.
 */
CREATE TABLE IF NOT EXISTS db_parent_ancestor (
    parent_table_name TEXT NOT NULL,
    row_pk            TEXT NOT NULL,
    row_json          TEXT NOT NULL,
    captured_at       TEXT DEFAULT (datetime('now')),
    PRIMARY KEY (parent_table_name, row_pk)
);
/* Also shared across descendants: PKs the parent INSERTED after the fork.
 * Equivalent to db_post_fork_inserts but keyed by parent_table_name so
 * ONE trigger insertion covers every descendant. */
CREATE TABLE IF NOT EXISTS db_parent_post_fork_inserts (
    parent_table_name TEXT NOT NULL,
    row_pk            TEXT NOT NULL,
    captured_at       TEXT DEFAULT (datetime('now')),
    PRIMARY KEY (parent_table_name, row_pk)
);
/* TODO3 #12: audit trail. Every branchctl action that mutates state
 * writes a row here: create, commit, merge, rollback, reset, delete,
 * migrate. Readers: `branchctl audit [--since <ts>] [--actor <name>]`.
 *
 * `actor` comes from the FORKPRESS_ACTOR env var (typically set by the
 * authenticated caller's shell) falling back to the system user via
 * get_current_user(), or 'anonymous' when neither is available. */
CREATE TABLE IF NOT EXISTS audit_log (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    ts      TEXT    NOT NULL DEFAULT (datetime('now')),
    actor   TEXT    NOT NULL,
    action  TEXT    NOT NULL,
    target  TEXT,
    details TEXT
);
CREATE INDEX IF NOT EXISTS idx_audit_log_ts    ON audit_log(ts);
CREATE INDEX IF NOT EXISTS idx_audit_log_actor ON audit_log(actor);
/* Hostile-review finding #2 — tamper-resistant audit_log.
 *
 * A user with write access to the .fp file (via sqlite3 shell or a rogue
 * PHP caller) could previously run
 *   DELETE FROM audit_log
 *   UPDATE audit_log SET actor='spoofed'
 * to cover their tracks. These BEFORE triggers fire RAISE(ABORT) so
 * neither statement commits. There is no safe path through bundled PHP
 * operations to rewrite history.
 *
 * Limitation: a caller who can DROP TABLE audit_log has already escaped
 * every protection SQLite offers.
 * Mitigation at the OS level (write-protect the .fp when handing it to
 * an untrusted consumer) is the operator's responsibility. */
CREATE TRIGGER IF NOT EXISTS audit_log_no_update
BEFORE UPDATE ON audit_log
BEGIN
    SELECT RAISE(ABORT, 'audit_log is append-only (tamper-resistant)');
END;
CREATE TRIGGER IF NOT EXISTS audit_log_no_delete
BEFORE DELETE ON audit_log
BEGIN
    SELECT RAISE(ABORT, 'audit_log is append-only (tamper-resistant)');
END;
CREATE TABLE IF NOT EXISTS db_snapshots_schema (
    branch_id    INTEGER NOT NULL,
    table_name   TEXT NOT NULL,
    ddl_sql      TEXT NOT NULL,                  -- the CREATE TABLE statement at fork time
    indexes_json TEXT NOT NULL DEFAULT '[]',     -- JSON array of CREATE INDEX statements
    PRIMARY KEY (branch_id, table_name)
);
CREATE TABLE IF NOT EXISTS users (
    username      TEXT PRIMARY KEY,
    password_hash TEXT NOT NULL,
    mysql_sha1    TEXT,
    role          TEXT NOT NULL CHECK(role IN ('admin','write','read')),
    created_at    TEXT DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS site_config (
    key   TEXT PRIMARY KEY,
    value TEXT
);
CREATE TABLE IF NOT EXISTS blob_chunks (
    blob_hash TEXT NOT NULL,
    chunk_no  INTEGER NOT NULL,
    data      BLOB NOT NULL,
    PRIMARY KEY (blob_hash, chunk_no),
    FOREIGN KEY (blob_hash) REFERENCES blobs(hash) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_blob_chunks_hash ON blob_chunks(blob_hash);
SQL);

    /* Legacy .fp files created before chunked storage landed may still have
     * `blobs.data BLOB NOT NULL`. Relax that to allow NULL so future large
     * writes can store payload in blob_chunks. SQLite cannot drop NOT NULL
     * in-place, so we rebuild the table only if the constraint is present. */
    $blobs_sql = (string)$db->querySingle(
        "SELECT sql FROM sqlite_master WHERE type='table' AND name='blobs'"
    );
    if ($blobs_sql && stripos($blobs_sql, 'data        BLOB NOT NULL') !== false) {
        $db->exec('BEGIN IMMEDIATE');
        try {
            $db->exec("CREATE TABLE blobs_new (hash TEXT PRIMARY KEY, data BLOB, size INTEGER NOT NULL)");
            $db->exec("INSERT INTO blobs_new (hash, data, size) SELECT hash, data, size FROM blobs");
            $db->exec("DROP TABLE blobs");
            $db->exec("ALTER TABLE blobs_new RENAME TO blobs");
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            /* Non-fatal: legacy-NOT-NULL schema still works for small blobs. */
        }
    }

    /* Back-compat: sites created BEFORE this change never had site_config,
     * so the very first migration on them must leave auth_enabled=0.
     * Brand-new sites go through init_db.php which seeds auth_enabled=1
     * before fs_migrate ever runs. */
    $has_any = (int)$db->querySingle("SELECT COUNT(*) FROM site_config");
    if ($has_any === 0) {
        $db->exec("INSERT OR IGNORE INTO site_config (key, value) VALUES ('auth_enabled', '0')");
    }

    /* TODO3 #5 migration: add kind / op columns for delta-encoded
     * file commits (same pattern as TODO3 #2 did for db_commits). */
    $cols_fs_commits = [];
    $ri = $db->query('PRAGMA table_info("fs_commits")');
    while ($row = $ri->fetchArray(SQLITE3_ASSOC)) $cols_fs_commits[] = (string)$row['name'];
    if (!in_array('kind', $cols_fs_commits, true)) {
        @$db->exec(
            "ALTER TABLE fs_commits ADD COLUMN kind TEXT NOT NULL DEFAULT 'FULL'"
        );
    }
    $cols_fs_files = [];
    $ri = $db->query('PRAGMA table_info("fs_commit_files")');
    while ($row = $ri->fetchArray(SQLITE3_ASSOC)) $cols_fs_files[] = (string)$row['name'];
    if (!in_array('op', $cols_fs_files, true)) {
        @$db->exec(
            "ALTER TABLE fs_commit_files ADD COLUMN op TEXT NOT NULL DEFAULT 'UPSERT'"
        );
    }

    /* TODO3 #2 migration: add kind / op columns for delta-encoded commits.
     * ALTER TABLE ADD COLUMN is O(1) in SQLite — no table rewrite. New
     * columns land with their DEFAULT so existing rows are interpreted as
     * 'UPSERT' (legacy full-snapshot rows) under the new reader path. */
    $cols_overlays = [];
    $ri = $db->query('PRAGMA table_info("db_commit_overlays")');
    while ($row = $ri->fetchArray(SQLITE3_ASSOC)) $cols_overlays[] = (string)$row['name'];
    if (!in_array('op', $cols_overlays, true)) {
        @$db->exec(
            "ALTER TABLE db_commit_overlays ADD COLUMN op TEXT NOT NULL DEFAULT 'UPSERT'"
        );
    }
    $cols_tombs = [];
    $ri = $db->query('PRAGMA table_info("db_commit_tombstones")');
    while ($row = $ri->fetchArray(SQLITE3_ASSOC)) $cols_tombs[] = (string)$row['name'];
    if (!in_array('op', $cols_tombs, true)) {
        @$db->exec(
            "ALTER TABLE db_commit_tombstones ADD COLUMN op TEXT NOT NULL DEFAULT 'UPSERT'"
        );
    }
    $cols_commits = [];
    $ri = $db->query('PRAGMA table_info("db_commits")');
    while ($row = $ri->fetchArray(SQLITE3_ASSOC)) $cols_commits[] = (string)$row['name'];
    if (!in_array('kind', $cols_commits, true)) {
        @$db->exec(
            "ALTER TABLE db_commits ADD COLUMN kind TEXT NOT NULL DEFAULT 'FULL'"
        );
    }

    /* TODO3 #3 migration: reinstall parent-side ancestor-capture triggers
     * under the O(1) shared-snapshot scheme. Pre-TODO3 triggers fanned
     * out via a JOIN against db_cow_branches, inserting one row per
     * descendant per parent write; the rewritten triggers insert exactly
     * once into db_parent_ancestor.
     *
     * The actual reinstall is idempotent and cheap — we just walk the
     * distinct parent_table_name values in db_cow_branches and hand each
     * one to cow_install_parent_triggers(). Safe no-op on fresh stores
     * with no COW branches.
     */
    if (function_exists('cow_install_parent_triggers')) {
        $parents = [];
        $pr = $db->query("SELECT DISTINCT parent_table_name FROM db_cow_branches");
        if ($pr) {
            while ($row = $pr->fetchArray(SQLITE3_NUM)) {
                $p = (string)$row[0];
                if ($p !== '') $parents[] = $p;
            }
        }
        foreach ($parents as $p) {
            cow_install_parent_triggers($db, $p);
        }
    }
}

// TODO3 #12 `audit_log_write` lives in audit_helpers.php — first slice of
// the TODO3 #16 branchctl.php split. More command-specific helpers will
// migrate into scripts/branchctl/ as the refactor continues.
require_once __DIR__ . '/audit_helpers.php';

function fs_branch_id(SQLite3 $db, string $name): int {
    $s = $db->prepare("SELECT id FROM branches WHERE name = :n");
    $s->bindValue(':n', $name, SQLITE3_TEXT);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_NUM);
    return (int)($row[0] ?? 0);
}

/* Walk the branch chain and return the resolved effective tree.
 * Returns [path => ['blob_hash','mode','mtime','is_dir']]. Tombstones
 * (blob_hash=NULL AND is_dir=0) are excluded from the result. */
function fs_resolve_tree(SQLite3 $db, int $branch_id): array {
    $tree = [];
    $tombstoned = [];
    $bid = $branch_id;
    while ($bid > 0) {
        $r = $db->query("SELECT path, blob_hash, mode, mtime, is_dir FROM files WHERE branch_id = $bid");
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $p = $row['path'];
            if (isset($tree[$p]) || isset($tombstoned[$p])) continue;
            if (!$row['blob_hash'] && !$row['is_dir']) {
                $tombstoned[$p] = true;
                continue;
            }
            $tree[$p] = $row;
        }
        $pq = $db->prepare("SELECT b2.id FROM branches b1 JOIN branches b2 ON b1.parent_branch = b2.name WHERE b1.id = :b");
        $pq->bindValue(':b', $bid, SQLITE3_INTEGER);
        $pr = $pq->execute();
        $prow = $pr->fetchArray(SQLITE3_NUM);
        $bid = $prow ? (int)$prow[0] : 0;
    }
    return $tree;
}

// Shared COW (copy-on-write) DB branch helpers. merge.php require_once's
// the same file so it can drop/recreate parent triggers around table
// rebuilds without duplicating the trigger-DDL generation logic.
require_once __DIR__ . '/cow_helpers.php';
require_once __DIR__ . '/fs_commit_helpers.php';

/* Deterministic hash of a resolved tree, used to detect uncommitted
 * changes against the last fs_commit. Fast enough for O(3000 files). */
function fs_tree_digest(array $tree): string {
    ksort($tree);
    $h = hash_init('sha256');
    foreach ($tree as $p => $e) {
        hash_update($h, $p . "\0" . ($e['blob_hash'] ?? '') . "\0" . (int)$e['is_dir'] . "\n");
    }
    return hash_final($h);
}

function fs_last_commit(SQLite3 $db, int $branch_id): ?array {
    $s = $db->prepare("SELECT id, commit_hash, message, created_at FROM fs_commits WHERE branch_id = :b ORDER BY id DESC LIMIT 1");
    $s->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

/* The "current" commit on a branch is its most recent fs_commits row. */
function fs_current_commit(SQLite3 $db, int $branch_id): ?array {
    return fs_last_commit($db, $branch_id);
}

function fs_find_commit_by_hash(SQLite3 $db, int $branch_id, string $hash): ?array {
    $s = $db->prepare("SELECT id, commit_hash, message, created_at FROM fs_commits WHERE branch_id = :b AND commit_hash = :h LIMIT 1");
    $s->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $s->bindValue(':h', $hash, SQLITE3_TEXT);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

/**
 * Delete orphaned blobs from the `blobs` store.
 *
 * Two modes:
 *   - $candidate_hashes empty  → full GC: scan the whole blobs table, delete
 *                                 any hash not referenced by files or
 *                                 fs_commit_files.
 *   - $candidate_hashes given  → narrow GC: only those hashes are checked.
 *                                 Used by the inline-GC path after a branch
 *                                 delete, so cost scales with the deleted
 *                                 branch's blob set, not the whole store.
 *
 * Chunked blobs (TODO2 #2) store their payload in `blob_chunks`; this helper
 * removes chunk rows in the same transaction as the blobs row so a half-GC'd
 * state is never observable.
 *
 * Returns [deleted_count, bytes_reclaimed].
 */
function fs_gc(SQLite3 $db, array $candidate_hashes = []): array {
    if (empty($candidate_hashes)) {
        /* Full sweep: collect every orphan by anti-joining the reference tables. */
        $to_delete = [];
        $bytes_free = 0;
        $r = $db->query(
            "SELECT hash, size FROM blobs "
          . "WHERE hash NOT IN (SELECT blob_hash FROM files WHERE blob_hash IS NOT NULL) "
          . "  AND hash NOT IN (SELECT blob_hash FROM fs_commit_files WHERE blob_hash IS NOT NULL)"
        );
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $to_delete[] = $row['hash'];
            $bytes_free += (int)$row['size'];
        }
    } else {
        /* Narrow sweep: per-hash existence check against both reference tables.
         * Prepared statements reused across the candidate list. */
        $candidate_hashes = array_values(array_unique(array_filter(
            $candidate_hashes,
            fn($h) => is_string($h) && $h !== ''
        )));
        $chk_files = $db->prepare(
            "SELECT 1 FROM files WHERE blob_hash = :h LIMIT 1"
        );
        $chk_commits = $db->prepare(
            "SELECT 1 FROM fs_commit_files WHERE blob_hash = :h LIMIT 1"
        );
        $size_q = $db->prepare("SELECT size FROM blobs WHERE hash = :h LIMIT 1");

        $to_delete = [];
        $bytes_free = 0;
        foreach ($candidate_hashes as $h) {
            $chk_files->bindValue(':h', $h, SQLITE3_TEXT);
            $r1 = $chk_files->execute();
            $has_file = (bool)$r1->fetchArray(SQLITE3_NUM);
            $r1->finalize();
            $chk_files->reset();
            if ($has_file) continue;

            $chk_commits->bindValue(':h', $h, SQLITE3_TEXT);
            $r2 = $chk_commits->execute();
            $has_commit = (bool)$r2->fetchArray(SQLITE3_NUM);
            $r2->finalize();
            $chk_commits->reset();
            if ($has_commit) continue;

            $size_q->bindValue(':h', $h, SQLITE3_TEXT);
            $sr = $size_q->execute();
            $srow = $sr->fetchArray(SQLITE3_NUM);
            $sr->finalize();
            $size_q->reset();
            if (!$srow) continue; // blob row already gone; nothing to reclaim

            $to_delete[] = $h;
            $bytes_free += (int)$srow[0];
        }
    }

    if (empty($to_delete)) {
        return [0, 0];
    }

    /* One transaction covers both tables so a mid-GC crash either leaves the
     * blob reachable (no change) or removes both chunk rows and metadata. */
    $in_outer_tx = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
    } catch (\Throwable $e) {
        // If we're already inside a caller's transaction (inline-GC path),
        // reuse it rather than nesting — SQLite does not support nested tx.
        $in_outer_tx = true;
    }
    try {
        $del_chunks = $db->prepare("DELETE FROM blob_chunks WHERE blob_hash = :h");
        $del_blob   = $db->prepare("DELETE FROM blobs WHERE hash = :h");
        foreach ($to_delete as $h) {
            $del_chunks->bindValue(':h', $h, SQLITE3_TEXT);
            $del_chunks->execute();
            $del_chunks->reset();
            $del_blob->bindValue(':h', $h, SQLITE3_TEXT);
            $del_blob->execute();
            $del_blob->reset();
        }
        if (!$in_outer_tx) $db->exec('COMMIT');
    } catch (\Throwable $e) {
        if (!$in_outer_tx) $db->exec('ROLLBACK');
        throw $e;
    }

    return [count($to_delete), $bytes_free];
}

// TODO3 #5: `fs_materialize_commit_tree` lives in fs_commit_helpers.php
// (shared between branchctl, merge, and the git server).

function fs_digest_of_commit(SQLite3 $db, int $commit_id): string {
    return fs_tree_digest(fs_materialize_commit_tree($db, $commit_id));
}

function fs_record_snapshot(SQLite3 $db, int $branch_id, string $message): int {
    $commit_hash = bin2hex(random_bytes(16));
    $tree = fs_resolve_tree($db, $branch_id);
    $parent = fs_last_commit($db, $branch_id);
    $parent_id = $parent['id'] ?? null;

    // TODO3 #5: delta-encode if there's a prior commit on this branch.
    $kind = $parent_id === null ? 'FULL' : 'DELTA';
    $prev_tree = [];
    if ($kind === 'DELTA') {
        $prev_tree = fs_materialize_commit_tree($db, (int)$parent_id);
    }

    $db->exec('BEGIN IMMEDIATE');
    try {
        $s = $db->prepare("INSERT INTO fs_commits (branch_id, commit_hash, parent_id, message, kind) VALUES (:b, :h, :p, :m, :k)");
        $s->bindValue(':b', $branch_id, SQLITE3_INTEGER);
        $s->bindValue(':h', $commit_hash, SQLITE3_TEXT);
        $parent_id === null ? $s->bindValue(':p', null, SQLITE3_NULL) : $s->bindValue(':p', $parent_id, SQLITE3_INTEGER);
        $s->bindValue(':m', $message, SQLITE3_TEXT);
        $s->bindValue(':k', $kind, SQLITE3_TEXT);
        $s->execute();
        $cid = (int)$db->lastInsertRowID();

        $ins = $db->prepare(
            "INSERT INTO fs_commit_files (commit_id, path, blob_hash, mode, mtime, is_dir, op) "
          . "VALUES (:c, :p, :bh, :md, :mt, :d, :op)"
        );
        $cur_paths = [];
        foreach ($tree as $path => $e) {
            $cur_paths[$path] = true;
            if ($kind === 'DELTA') {
                $prev = $prev_tree[$path] ?? null;
                $same = $prev
                    && (string)($prev['blob_hash'] ?? '') === (string)($e['blob_hash'] ?? '')
                    && (int)($prev['mode']  ?? 0) === (int)($e['mode']  ?? 0)
                    && (int)($prev['mtime'] ?? 0) === (int)($e['mtime'] ?? 0)
                    && (int)($prev['is_dir'] ?? 0) === (int)($e['is_dir'] ?? 0);
                if ($same) continue;
            }
            $ins->bindValue(':c',  $cid, SQLITE3_INTEGER);
            $ins->bindValue(':p',  $path, SQLITE3_TEXT);
            $ins->bindValue(':bh', $e['blob_hash'] ?? null,
                $e['blob_hash'] ? SQLITE3_TEXT : SQLITE3_NULL);
            $ins->bindValue(':md', (int)($e['mode'] ?? 0),  SQLITE3_INTEGER);
            $ins->bindValue(':mt', (int)($e['mtime'] ?? 0), SQLITE3_INTEGER);
            $ins->bindValue(':d',  (int)($e['is_dir'] ?? 0), SQLITE3_INTEGER);
            $ins->bindValue(':op', 'UPSERT', SQLITE3_TEXT);
            $ins->execute();
            $ins->reset();
        }
        if ($kind === 'DELTA') {
            foreach ($prev_tree as $p => $_) {
                if (isset($cur_paths[$p])) continue;
                $ins->bindValue(':c',  $cid, SQLITE3_INTEGER);
                $ins->bindValue(':p',  $p, SQLITE3_TEXT);
                $ins->bindValue(':bh', null, SQLITE3_NULL);
                $ins->bindValue(':md', 0, SQLITE3_INTEGER);
                $ins->bindValue(':mt', 0, SQLITE3_INTEGER);
                $ins->bindValue(':d',  0, SQLITE3_INTEGER);
                $ins->bindValue(':op', 'DELETE', SQLITE3_TEXT);
                $ins->execute();
                $ins->reset();
            }
        }
        $db->exec('COMMIT');
        return $cid;
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

/* Materialize a commit's file tree back into the branch's overlay.
 * We replace the branch's explicit `files` rows entirely and write each
 * snapshot entry as an explicit row — so the result doesn't depend on
 * parent-branch inheritance. */
function fs_restore_snapshot(SQLite3 $db, int $branch_id, int $commit_id): int {
    require_once __DIR__ . '/opcache.php';

    // Collect current .php paths on this branch so we can invalidate OPcache
    // entries for any that are about to change. Easier to be broad than to
    // diff precisely: invalidating a path whose bytecode we still have is
    // cheap, whereas missing one serves stale code.
    $branch_name = (string)$db->querySingle(
        "SELECT name FROM branches WHERE id = " . (int)$branch_id
    );

    $pre_paths = [];
    $r = $db->query("SELECT path FROM files WHERE branch_id = $branch_id");
    while ($row = $r->fetchArray(SQLITE3_NUM)) {
        $pre_paths[$row[0]] = true;
    }
    $r->finalize();

    // TODO3 #5: materialize target tree from the commit chain, not just
    // the target commit's own rows (which under delta encoding hold
    // only changes since the previous commit).
    $materialized_tree = fs_materialize_commit_tree($db, $commit_id);

    $db->exec('BEGIN IMMEDIATE');
    try {
        $db->exec("DELETE FROM files WHERE branch_id = $branch_id");
        $ins = $db->prepare(
            "INSERT INTO files (branch_id, path, blob_hash, mode, mtime, is_dir) "
          . "VALUES (:b, :p, :bh, :md, :mt, :d)"
        );
        $count = 0;
        $post_paths = [];
        foreach ($materialized_tree as $p => $row) {
            $ins->bindValue(':b',  $branch_id, SQLITE3_INTEGER);
            $ins->bindValue(':p',  $row['path'], SQLITE3_TEXT);
            $ins->bindValue(':bh', $row['blob_hash'] ?? null,
                $row['blob_hash'] ? SQLITE3_TEXT : SQLITE3_NULL);
            $ins->bindValue(':md', (int)($row['mode'] ?? 0),  SQLITE3_INTEGER);
            $ins->bindValue(':mt', (int)($row['mtime'] ?? 0), SQLITE3_INTEGER);
            $ins->bindValue(':d',  (int)($row['is_dir'] ?? 0), SQLITE3_INTEGER);
            $ins->execute();
            $ins->reset();
            if (empty($row['is_dir'])) $post_paths[$row['path']] = true;
            $count++;
        }

        // Invalidate OPcache for every .php path in the symmetric difference
        // between pre-reset and post-reset overlays — i.e. any path that
        // was added, removed, or whose content may have changed.
        foreach ($pre_paths  as $p => $_) opcache_queue_invalidate($db, $branch_name, $p);
        foreach ($post_paths as $p => $_) opcache_queue_invalidate($db, $branch_name, $p);

        $db->exec('COMMIT');
        return $count;
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

/* ================================================================
 * DB-side commit graph (db_commits + db_commit_overlays +
 *                        db_commit_tombstones + db_commit_schema)
 *
 * branchctl commit/rollback/reset extend the file-side fs_commits
 * machinery with a parallel DB snapshot:
 *
 *   - For each b{bid}_wp_X__overlay  → snapshot its rows
 *   - For each b{bid}_wp_X__tombstones → snapshot its PKs
 *   - For each overlay table → snapshot the CREATE TABLE DDL +
 *     all CREATE INDEX statements that reference it
 *
 * Storage is O(divergent rows) per commit, NOT O(total branch rows)
 * — inherited rows are reconstructable from the parent's state at
 * the same fs_commit. The COW model makes this naturally efficient.
 *
 * For MAIN (id=1) the same machinery snapshots the real b1_wp_*
 * tables (no overlay/tombstone split — main IS the canonical
 * state). main snapshots are O(rows on main); for sites that don't
 * commit on main this never fires.
 * ================================================================ */

/** All COW-overlay table suffixes for the given branch_id. The suffix
 *  is the part after the "b{bid}_wp_" prefix.
 *
 *  For non-main branches we read db_cow_branches (single source of
 *  truth for the COW set). For main we enumerate b1_wp_* real tables
 *  directly so we can snapshot main too if the user commits on main. */
function db_branch_table_suffixes(SQLite3 $db, int $branch_id): array {
    $suffixes = [];
    if ($branch_id === 1) {
        // main: enumerate real tables.
        $r = $db->query(
            "SELECT name FROM sqlite_master "
          . "WHERE type='table' "
          . "  AND name LIKE 'b1_wp_%' "
          . "  AND name NOT LIKE '%\\_\\_overlay' ESCAPE '\\' "
          . "  AND name NOT LIKE '%\\_\\_tombstones' ESCAPE '\\'"
        );
        while ($row = $r->fetchArray(SQLITE3_NUM)) {
            $suffixes[] = substr((string)$row[0], strlen('b1_wp_'));
        }
        return $suffixes;
    }
    $st = $db->prepare(
        "SELECT table_suffix FROM db_cow_branches WHERE branch_id = :b "
      . "ORDER BY table_suffix"
    );
    $st->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $r = $st->execute();
    while ($row = $r->fetchArray(SQLITE3_NUM)) $suffixes[] = (string)$row[0];
    return $suffixes;
}

/** Resolve the physical table name to read for a (branch_id, suffix)
 *  pair: overlay for non-main, real table for main. */
function db_overlay_or_real(int $branch_id, string $suffix): string {
    if ($branch_id === 1) return "b1_wp_{$suffix}";
    return "b{$branch_id}_wp_{$suffix}__overlay";
}

/** Resolve the tombstone table name (or empty for main, which has none). */
function db_tombstone_name(int $branch_id, string $suffix): string {
    if ($branch_id === 1) return '';
    return "b{$branch_id}_wp_{$suffix}__tombstones";
}

/** PK columns for a logical (suffix) on a branch. Reads PRAGMA on the
 *  underlying overlay (or real table for main). */
function db_pk_cols_for(SQLite3 $db, int $branch_id, string $suffix): array {
    return cow_extract_pk_cols($db, db_overlay_or_real($branch_id, $suffix));
}

/** Capture row-by-row state of the branch's overlays + tombstones.
 *  Returns ['overlays' => [[suffix, pk_json, row_json], ...],
 *          'tombstones' => [[suffix, pk_json], ...],
 *          'schema'     => [[suffix, ddl, indexes_json], ...]].
 *
 *  Designed so a digest of this structure cheaply detects "uncommitted
 *  DB changes" without writing to db_commits. */
function db_collect_state(SQLite3 $db, int $branch_id): array {
    $overlays   = [];
    $tombstones = [];
    $schema     = [];
    foreach (db_branch_table_suffixes($db, $branch_id) as $suffix) {
        $physical = db_overlay_or_real($branch_id, $suffix);
        $pk_cols  = cow_extract_pk_cols($db, $physical);
        $columns  = cow_table_columns($db, $physical);
        if (empty($columns)) continue;
        // Collect rows.
        $r = $db->query("SELECT * FROM \"" . SQLite3::escapeString($physical) . "\"");
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $pk_map = [];
            if (empty($pk_cols)) {
                // No PK: use whole row as the key.
                $pk_map = $row;
            } else {
                foreach ($pk_cols as $c) $pk_map[$c] = $row[$c] ?? null;
            }
            $overlays[] = [
                $suffix,
                json_encode($pk_map, JSON_UNESCAPED_UNICODE),
                json_encode($row,    JSON_UNESCAPED_UNICODE),
            ];
        }
        $r->finalize();
        // Tombstones (only non-main).
        if ($branch_id !== 1) {
            $tomb = db_tombstone_name($branch_id, $suffix);
            if (cow_is_table($db, $tomb)) {
                $r2 = $db->query("SELECT * FROM \"" . SQLite3::escapeString($tomb) . "\"");
                while ($row = $r2->fetchArray(SQLITE3_ASSOC)) {
                    if (empty($pk_cols)) {
                        $pk_map = $row;
                    } else {
                        $pk_map = [];
                        foreach ($pk_cols as $c) $pk_map[$c] = $row[$c] ?? null;
                    }
                    $tombstones[] = [
                        $suffix,
                        json_encode($pk_map, JSON_UNESCAPED_UNICODE),
                    ];
                }
                $r2->finalize();
            }
        }
        // Schema: overlay's CREATE TABLE + dependent indexes.
        $ddl = (string)$db->querySingle(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='"
            . SQLite3::escapeString($physical) . "'"
        );
        $idxs = [];
        $r3 = $db->query(
            "SELECT sql FROM sqlite_master WHERE type='index' "
          . "  AND tbl_name='" . SQLite3::escapeString($physical) . "' "
          . "  AND sql IS NOT NULL ORDER BY name"
        );
        while ($row = $r3->fetchArray(SQLITE3_NUM)) $idxs[] = (string)$row[0];
        $r3->finalize();
        $schema[] = [
            $suffix,
            (string)$ddl,
            json_encode($idxs, JSON_UNESCAPED_UNICODE),
        ];
    }
    return ['overlays' => $overlays, 'tombstones' => $tombstones, 'schema' => $schema];
}

/** Deterministic digest of the branch's DB state — used to detect
 *  "uncommitted DB changes" against the last db_commit. */
function db_state_digest(array $state): string {
    $h = hash_init('sha256');
    foreach ($state['schema'] as $row) {
        hash_update($h, "S\0" . $row[0] . "\0" . $row[1] . "\0" . $row[2] . "\n");
    }
    // Sort overlays/tombstones by (suffix, pk) for determinism.
    $ov = $state['overlays'];
    usort($ov, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    foreach ($ov as $row) {
        hash_update($h, "O\0" . $row[0] . "\0" . $row[1] . "\0" . $row[2] . "\n");
    }
    $tb = $state['tombstones'];
    usort($tb, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    foreach ($tb as $row) {
        hash_update($h, "T\0" . $row[0] . "\0" . $row[1] . "\n");
    }
    return hash_final($h);
}

/** TODO3 #2: materialize the full state of a db_commit by walking the
 *  commit chain for its branch.
 *
 *  Starts from the latest 'FULL' commit whose id ≤ $commit_id (or from
 *  the first commit if none is marked FULL — defensive; every branch's
 *  first commit is always FULL) and applies each subsequent DELTA
 *  commit's UPSERT / DELETE ops in order up to and including $commit_id.
 *
 *  Returns the same structure as `db_collect_state`:
 *    ['overlays'   => [[suffix, pk_json, row_json], ...],
 *     'tombstones' => [[suffix, pk_json],           ...],
 *     'schema'     => [[suffix, overlay_ddl, indexes_json], ...]]
 */
function db_materialize_commit_state(SQLite3 $db, int $commit_id): array {
    // Branch for this commit; then every commit id on that branch ≤ $commit_id.
    $branch_id = (int)$db->querySingle(
        "SELECT branch_id FROM db_commits WHERE id = $commit_id"
    );
    if ($branch_id <= 0) {
        return ['overlays' => [], 'tombstones' => [], 'schema' => []];
    }
    $commits = [];
    $r = $db->query(
        "SELECT id, COALESCE(kind,'FULL') AS kind FROM db_commits "
      . "WHERE branch_id = $branch_id AND id <= $commit_id ORDER BY id"
    );
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $commits[] = $row;
    $r->finalize();

    // Find the last FULL commit — that's the replay base.
    $base_idx = 0;
    for ($i = count($commits) - 1; $i >= 0; $i--) {
        if ($commits[$i]['kind'] === 'FULL') { $base_idx = $i; break; }
    }

    $ov_map     = [];   // "<suffix>\0<pk_json>" => [suffix, pk_json, row_json]
    $tb_map     = [];   // "<suffix>\0<pk_json>" => [suffix, pk_json]
    $schema_map = [];   // suffix => [overlay_ddl, indexes_json]

    for ($i = $base_idx; $i < count($commits); $i++) {
        $cid  = (int)$commits[$i]['id'];
        $kind = $commits[$i]['kind'];

        // Schema: a FULL commit resets the schema map; a DELTA commit
        // merges its (small) set of schema rows on top.
        if ($kind === 'FULL') $schema_map = [];
        $rs = $db->query(
            "SELECT table_suffix, overlay_ddl, indexes_json "
          . "FROM db_commit_schema WHERE commit_id = $cid"
        );
        while ($row = $rs->fetchArray(SQLITE3_ASSOC)) {
            $schema_map[$row['table_suffix']] = [
                $row['overlay_ddl'], $row['indexes_json'],
            ];
        }
        $rs->finalize();

        // Overlays: FULL resets; DELTA merges UPSERT/DELETE.
        if ($kind === 'FULL') { $ov_map = []; $tb_map = []; }
        $ro = $db->query(
            "SELECT table_suffix, row_pk, row_json, op "
          . "FROM db_commit_overlays WHERE commit_id = $cid"
        );
        while ($row = $ro->fetchArray(SQLITE3_ASSOC)) {
            $key = $row['table_suffix'] . "\0" . $row['row_pk'];
            if (($row['op'] ?? 'UPSERT') === 'DELETE') {
                unset($ov_map[$key]);
            } else {
                $ov_map[$key] = [
                    $row['table_suffix'], $row['row_pk'], $row['row_json'],
                ];
            }
        }
        $ro->finalize();
        $rt = $db->query(
            "SELECT table_suffix, row_pk, op "
          . "FROM db_commit_tombstones WHERE commit_id = $cid"
        );
        while ($row = $rt->fetchArray(SQLITE3_ASSOC)) {
            $key = $row['table_suffix'] . "\0" . $row['row_pk'];
            if (($row['op'] ?? 'UPSERT') === 'DELETE') {
                unset($tb_map[$key]);
            } else {
                $tb_map[$key] = [$row['table_suffix'], $row['row_pk']];
            }
        }
        $rt->finalize();
    }

    $schema = [];
    foreach ($schema_map as $suffix => $sv) {
        $schema[] = [$suffix, $sv[0], $sv[1]];
    }
    return [
        'overlays'   => array_values($ov_map),
        'tombstones' => array_values($tb_map),
        'schema'     => $schema,
    ];
}

/** Materialize a previously-recorded db_commit's state into a digest.
 *  Used by branchctl rollback/reset --no-force checks. */
function db_digest_of_commit(SQLite3 $db, int $commit_id): string {
    return db_state_digest(db_materialize_commit_state($db, $commit_id));
}

/** Latest db_commit row for the branch (NULL if none). */
function db_last_commit(SQLite3 $db, int $branch_id): ?array {
    $s = $db->prepare(
        "SELECT id, fs_commit_id, commit_hash, created_at FROM db_commits "
      . "WHERE branch_id = :b ORDER BY id DESC LIMIT 1"
    );
    $s->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

/** Look up a db_commit by its hash. */
function db_find_commit_by_hash(SQLite3 $db, int $branch_id, string $hash): ?array {
    $s = $db->prepare(
        "SELECT id, fs_commit_id, commit_hash, created_at FROM db_commits "
      . "WHERE branch_id = :b AND commit_hash = :h LIMIT 1"
    );
    $s->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $s->bindValue(':h', $hash, SQLITE3_TEXT);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

/** Persist the branch's current DB state under a new db_commit. Returns
 *  the new db_commit id. Caller must wrap in a transaction.
 *
 *  TODO3 #2 delta encoding:
 *    - FIRST commit on a branch → kind='FULL', stores the entire current
 *      state as UPSERT rows / UPSERT tombstones.
 *    - SUBSEQUENT commits → kind='DELTA'. Only rows that differ from the
 *      previous commit are stored:
 *        * UPSERT when a row is new OR its row_json changed
 *        * DELETE when a row was present in the previous commit and is
 *          no longer in the branch's overlay/tombstone
 *      Storage per commit scales with what actually changed, not with
 *      total divergent-row count. A 50-row overlay committed 100 times
 *      with 5 rows changed per commit used to cost 100×50 stored rows
 *      and now costs ~50 (first FULL) + 100×5 (deltas).
 */
function db_record_snapshot(SQLite3 $db, int $branch_id, int $fs_commit_id,
                            string $commit_hash): int {
    $state = db_collect_state($db, $branch_id);

    // Determine kind based on whether this branch already has any commit.
    $prev = db_last_commit($db, $branch_id);
    $kind = $prev ? 'DELTA' : 'FULL';

    // Materialize the prev state to diff against, but only for DELTA.
    $prev_ov_map = [];
    $prev_tb_map = [];
    if ($kind === 'DELTA') {
        $prev_state = db_materialize_commit_state($db, (int)$prev['id']);
        foreach ($prev_state['overlays'] as $row) {
            $prev_ov_map[$row[0] . "\0" . $row[1]] = $row[2];
        }
        foreach ($prev_state['tombstones'] as $row) {
            $prev_tb_map[$row[0] . "\0" . $row[1]] = true;
        }
    }

    $s = $db->prepare(
        "INSERT INTO db_commits (branch_id, fs_commit_id, commit_hash, kind) "
      . "VALUES (:b, :f, :h, :k)"
    );
    $s->bindValue(':b', $branch_id,    SQLITE3_INTEGER);
    $s->bindValue(':f', $fs_commit_id, SQLITE3_INTEGER);
    $s->bindValue(':h', $commit_hash,  SQLITE3_TEXT);
    $s->bindValue(':k', $kind,         SQLITE3_TEXT);
    $s->execute();
    $cid = (int)$db->lastInsertRowID();

    // Write overlay deltas (or the full snapshot for FULL commits).
    $cur_ov_keys = [];
    $ins_ov = $db->prepare(
        "INSERT INTO db_commit_overlays "
      . "(commit_id, table_suffix, row_pk, row_json, op) "
      . "VALUES (:c, :s, :pk, :rj, :op)"
    );
    foreach ($state['overlays'] as $row) {
        $key = $row[0] . "\0" . $row[1];
        $cur_ov_keys[$key] = true;
        $changed = ($kind === 'FULL')
            || !isset($prev_ov_map[$key])
            || ($prev_ov_map[$key] !== $row[2]);
        if (!$changed) continue;
        $ins_ov->bindValue(':c',  $cid,    SQLITE3_INTEGER);
        $ins_ov->bindValue(':s',  $row[0], SQLITE3_TEXT);
        $ins_ov->bindValue(':pk', $row[1], SQLITE3_TEXT);
        $ins_ov->bindValue(':rj', $row[2], SQLITE3_TEXT);
        $ins_ov->bindValue(':op', 'UPSERT', SQLITE3_TEXT);
        $ins_ov->execute();
        $ins_ov->reset();
    }
    // DELETE rows: present in prev commit but absent in current state.
    if ($kind === 'DELTA') {
        foreach ($prev_ov_map as $key => $_) {
            if (isset($cur_ov_keys[$key])) continue;
            [$suffix, $pk_json] = explode("\0", $key, 2);
            $ins_ov->bindValue(':c',  $cid,     SQLITE3_INTEGER);
            $ins_ov->bindValue(':s',  $suffix,  SQLITE3_TEXT);
            $ins_ov->bindValue(':pk', $pk_json, SQLITE3_TEXT);
            $ins_ov->bindValue(':rj', '',       SQLITE3_TEXT);
            $ins_ov->bindValue(':op', 'DELETE', SQLITE3_TEXT);
            $ins_ov->execute();
            $ins_ov->reset();
        }
    }

    // Tombstones — same delta logic.
    $cur_tb_keys = [];
    $ins_tb = $db->prepare(
        "INSERT INTO db_commit_tombstones "
      . "(commit_id, table_suffix, row_pk, op) "
      . "VALUES (:c, :s, :pk, :op)"
    );
    foreach ($state['tombstones'] as $row) {
        $key = $row[0] . "\0" . $row[1];
        $cur_tb_keys[$key] = true;
        $changed = ($kind === 'FULL') || !isset($prev_tb_map[$key]);
        if (!$changed) continue;
        $ins_tb->bindValue(':c',  $cid,    SQLITE3_INTEGER);
        $ins_tb->bindValue(':s',  $row[0], SQLITE3_TEXT);
        $ins_tb->bindValue(':pk', $row[1], SQLITE3_TEXT);
        $ins_tb->bindValue(':op', 'UPSERT', SQLITE3_TEXT);
        $ins_tb->execute();
        $ins_tb->reset();
    }
    if ($kind === 'DELTA') {
        foreach ($prev_tb_map as $key => $_) {
            if (isset($cur_tb_keys[$key])) continue;
            [$suffix, $pk_json] = explode("\0", $key, 2);
            $ins_tb->bindValue(':c',  $cid,     SQLITE3_INTEGER);
            $ins_tb->bindValue(':s',  $suffix,  SQLITE3_TEXT);
            $ins_tb->bindValue(':pk', $pk_json, SQLITE3_TEXT);
            $ins_tb->bindValue(':op', 'DELETE', SQLITE3_TEXT);
            $ins_tb->execute();
            $ins_tb->reset();
        }
    }

    // Schema rows are small and per-table — always write them fully.
    // The materializer merges them across commits anyway.
    if (!empty($state['schema'])) {
        $ins = $db->prepare(
            "INSERT INTO db_commit_schema (commit_id, table_suffix, overlay_ddl, indexes_json) "
          . "VALUES (:c, :s, :d, :i)"
        );
        foreach ($state['schema'] as $row) {
            $ins->bindValue(':c', $cid,    SQLITE3_INTEGER);
            $ins->bindValue(':s', $row[0], SQLITE3_TEXT);
            $ins->bindValue(':d', $row[1], SQLITE3_TEXT);
            $ins->bindValue(':i', $row[2], SQLITE3_TEXT);
            $ins->execute();
            $ins->reset();
        }
    }
    return $cid;
}

/** Decode JSON-encoded value to its appropriate SQLite3 bind type. */
function db_bind_json_value($stmt, int $i, $v): void {
    if ($v === null) {
        $stmt->bindValue($i, null, SQLITE3_NULL);
    } elseif (is_int($v)) {
        $stmt->bindValue($i, $v, SQLITE3_INTEGER);
    } elseif (is_float($v)) {
        $stmt->bindValue($i, $v, SQLITE3_FLOAT);
    } elseif (is_bool($v)) {
        $stmt->bindValue($i, (int)$v, SQLITE3_INTEGER);
    } else {
        // String / array / object — coerce to string.
        $stmt->bindValue($i, is_array($v) || is_object($v) ? json_encode($v) : (string)$v, SQLITE3_TEXT);
    }
}

/** Rebuild branch's overlay+tombstone tables from a previously-recorded
 *  db_commit. Drops existing overlay rows / tombstone rows / overlay
 *  indexes and replays from the snapshot. Caller must wrap in a
 *  transaction. */
function db_restore_snapshot(SQLite3 $db, int $branch_id, int $commit_id): int {
    $touched = 0;

    // TODO3 #2: materialize the target state by walking the commit chain
    // (base FULL snapshot + subsequent DELTA commits up to $commit_id).
    $materialized = db_materialize_commit_state($db, $commit_id);

    // Step 1: collect schema rows from the materialized view.
    $schema_by_suffix = [];
    foreach ($materialized['schema'] as $row) {
        $schema_by_suffix[$row[0]] = [
            'table_suffix' => $row[0],
            'overlay_ddl'  => $row[1],
            'indexes_json' => $row[2],
        ];
    }

    // Step 2: for each overlay tracked at commit time, drop the current
    // (view+overlay+tombstone) trio and recreate from the snapshotted DDL.
    foreach ($schema_by_suffix as $suffix => $row) {
        $logical = ($branch_id === 1)
            ? "b1_wp_{$suffix}"
            : "b{$branch_id}_wp_{$suffix}";
        $overlay = ($branch_id === 1) ? $logical : ($logical . '__overlay');
        $tomb    = ($branch_id === 1) ? null     : ($logical . '__tombstones');

        // Drop INSTEAD OF triggers + view + overlay (rebuild fresh from DDL).
        if ($branch_id !== 1) {
            $db->exec("DROP TRIGGER IF EXISTS \"{$logical}__cow_ins\"");
            $db->exec("DROP TRIGGER IF EXISTS \"{$logical}__cow_upd\"");
            $db->exec("DROP TRIGGER IF EXISTS \"{$logical}__cow_del\"");
            $db->exec("DROP VIEW IF EXISTS \"$logical\"");
        }
        // Drop indexes that point at this overlay first (so DROP TABLE
        // doesn't fail if the index DDL references columns we're about
        // to recreate).
        //
        // We collect the index names UPFRONT (and finalize the cursor)
        // before issuing any DROP — DDL modifies sqlite_master, and an
        // open SELECT cursor on sqlite_master keeps a read lock that
        // makes any subsequent DROP fail with "database table is locked".
        $idx_to_drop = [];
        $rix = $db->query(
            "SELECT name FROM sqlite_master WHERE type='index' "
          . "  AND tbl_name='" . SQLite3::escapeString($overlay) . "' "
          . "  AND sql IS NOT NULL"
        );
        while ($irow = $rix->fetchArray(SQLITE3_NUM)) {
            $idx_to_drop[] = (string)$irow[0];
        }
        $rix->finalize();
        foreach ($idx_to_drop as $iname) {
            $db->exec("DROP INDEX IF EXISTS \"" . SQLite3::escapeString($iname) . "\"");
        }

        // Drop and recreate the overlay (or main's real table).
        $db->exec("DROP TABLE IF EXISTS \"$overlay\"");
        $ddl = (string)$row['overlay_ddl'];
        if ($ddl !== '') $db->exec($ddl);

        // Recreate the indexes.
        $idxs = json_decode((string)$row['indexes_json'], true) ?: [];
        foreach ($idxs as $isql) {
            if (is_string($isql) && $isql !== '') @$db->exec($isql);
        }

        // Recreate the tombstone table (PK-only schema, derived from PK
        // columns of the overlay we just made).
        if ($tomb !== null) {
            $db->exec("DROP TABLE IF EXISTS \"$tomb\"");
            $pk_cols  = cow_extract_pk_cols($db, $overlay);
            $columns  = cow_table_columns($db, $overlay);
            if (!empty($pk_cols)) {
                $pk_types = [];
                $pi = $db->query('PRAGMA table_info("' . SQLite3::escapeString($overlay) . '")');
                while ($prow = $pi->fetchArray(SQLITE3_ASSOC)) {
                    if (in_array($prow['name'], $pk_cols, true)) {
                        $pk_types[$prow['name']] = $prow['type'] ?: 'TEXT';
                    }
                }
                $tomb_cols_ddl = [];
                foreach ($pk_cols as $c) {
                    $t = $pk_types[$c] ?? 'TEXT';
                    $tomb_cols_ddl[] = '"' . $c . '" ' . $t;
                }
                $tomb_pk = implode(', ', array_map(fn($c) => '"' . $c . '"', $pk_cols));
                $db->exec(
                    "CREATE TABLE \"$tomb\" ("
                  . implode(', ', $tomb_cols_ddl)
                  . ", PRIMARY KEY ($tomb_pk))"
                );
            } else {
                $cols_ddl = [];
                foreach ($columns as $c) $cols_ddl[] = '"' . $c . '" BLOB';
                $db->exec("CREATE TABLE \"$tomb\" (" . implode(', ', $cols_ddl) . ")");
            }
        }
    }

    // Step 3: replay overlay rows from the materialized state.
    $rows_by_suffix = [];
    foreach ($materialized['overlays'] as $row) {
        $rows_by_suffix[$row[0]][] = [
            'table_suffix' => $row[0],
            'row_pk'       => $row[1],
            'row_json'     => $row[2],
        ];
    }
    foreach ($rows_by_suffix as $suffix => $rows) {
        $physical = db_overlay_or_real($branch_id, $suffix);
        if (!cow_is_table($db, $physical)) continue;
        $cols = cow_table_columns($db, $physical);
        if (empty($cols)) continue;
        $col_list = implode(', ', array_map(fn($c) => '"' . $c . '"', $cols));
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $ins = $db->prepare(
            "INSERT INTO \"$physical\" ($col_list) VALUES ($placeholders)"
        );
        foreach ($rows as $r2) {
            $row_obj = json_decode((string)$r2['row_json'], true);
            if (!is_array($row_obj)) continue;
            $i = 1;
            foreach ($cols as $c) {
                db_bind_json_value($ins, $i++, $row_obj[$c] ?? null);
            }
            $ins->execute();
            $ins->reset();
            $touched++;
        }
    }

    // Step 4: replay tombstones (non-main only) from materialized state.
    if ($branch_id !== 1) {
        $tomb_by_suffix = [];
        foreach ($materialized['tombstones'] as $row) {
            $tomb_by_suffix[$row[0]][] = (string)$row[1];
        }
        foreach ($tomb_by_suffix as $suffix => $pks) {
            $tomb = db_tombstone_name($branch_id, $suffix);
            if (!cow_is_table($db, $tomb)) continue;
            $pk_cols = cow_extract_pk_cols($db, $tomb);
            if (empty($pk_cols)) continue;
            $col_list = implode(', ', array_map(fn($c) => '"' . $c . '"', $pk_cols));
            $placeholders = implode(', ', array_fill(0, count($pk_cols), '?'));
            $ins = $db->prepare(
                "INSERT INTO \"$tomb\" ($col_list) VALUES ($placeholders)"
            );
            foreach ($pks as $pk_json) {
                $pk_obj = json_decode($pk_json, true);
                if (!is_array($pk_obj)) continue;
                $i = 1;
                foreach ($pk_cols as $c) db_bind_json_value($ins, $i++, $pk_obj[$c] ?? null);
                $ins->execute();
                $ins->reset();
                $touched++;
            }
        }
    }

    // Step 5: rebuild views + INSTEAD OF triggers from the (now-restored)
    // overlay schemas. For each suffix that had a snapshot AND we're a
    // non-main branch, we need a view.
    if ($branch_id !== 1) {
        foreach ($schema_by_suffix as $suffix => $_) {
            db_rebuild_view_for(
                $db, $branch_id, $suffix
            );
        }
    }

    // Step 6: refresh the COW marker for each restored suffix so future
    // db_cow_branches lookups see the post-restore parent_table_name.
    if ($branch_id !== 1) {
        $parent_id = cow_parent_branch_id($db, $branch_id);
        $upd = $db->prepare(
            "UPDATE db_cow_branches SET parent_table_name = :pt "
          . "WHERE branch_id = :b AND table_suffix = :s"
        );
        foreach ($schema_by_suffix as $suffix => $_) {
            $pt = "b{$parent_id}_wp_{$suffix}";
            $upd->bindValue(':pt', $pt,        SQLITE3_TEXT);
            $upd->bindValue(':b',  $branch_id, SQLITE3_INTEGER);
            $upd->bindValue(':s',  $suffix,    SQLITE3_TEXT);
            $upd->execute();
            $upd->reset();
        }
    }
    return $touched;
}

/** Rebuild a single view + its INSTEAD OF triggers using the overlay's
 *  current column set. Mirrors BranchedPDO::rebuild_view_and_triggers
 *  but uses the SQLite3 connection used by branchctl. */
function db_rebuild_view_for(SQLite3 $db, int $branch_id, string $suffix): void {
    $logical = "b{$branch_id}_wp_{$suffix}";
    $overlay = $logical . '__overlay';
    $tomb    = $logical . '__tombstones';

    // Look up parent's logical table name from the COW marker; fall back
    // to deriving from the parent branch id if the marker isn't there.
    $parent_table = (string)$db->querySingle(
        "SELECT parent_table_name FROM db_cow_branches "
      . "WHERE branch_id = " . (int)$branch_id . " AND table_suffix = '"
      . SQLite3::escapeString($suffix) . "'"
    );
    if ($parent_table === '') {
        $pid = cow_parent_branch_id($db, $branch_id);
        $parent_table = "b{$pid}_wp_{$suffix}";
    }
    if (!cow_is_table($db, $overlay)) return;
    if (!cow_is_table($db, $parent_table) && !cow_is_view($db, $parent_table)) {
        // Parent's table is gone — leave the view non-existent.
        return;
    }

    $columns = cow_table_columns($db, $overlay);
    $pk_cols = cow_extract_pk_cols($db, $overlay);
    if (empty($columns)) return;

    $parent_cols = cow_table_columns($db, $parent_table);

    $db->exec("DROP TRIGGER IF EXISTS \"{$logical}__cow_ins\"");
    $db->exec("DROP TRIGGER IF EXISTS \"{$logical}__cow_upd\"");
    $db->exec("DROP TRIGGER IF EXISTS \"{$logical}__cow_del\"");
    $db->exec("DROP VIEW IF EXISTS \"$logical\"");

    // Build view body — project parent rows to overlay's column shape so
    // schema-divergent overlay (e.g. with a branch-only column) still
    // works in the UNION ALL. Same logic as BranchedPDO uses.
    $col_list = implode(', ', array_map(fn($c) => '"' . $c . '"', $columns));
    $proj = [];
    $pset = array_flip($parent_cols);
    foreach ($columns as $c) {
        if (isset($pset[$c])) $proj[] = 'p."' . $c . '"';
        else                   $proj[] = 'NULL AS "' . $c . '"';
    }
    $proj_list = implode(', ', $proj);
    if (empty($pk_cols)) {
        $body = "SELECT $col_list FROM \"$overlay\" UNION ALL SELECT $proj_list FROM \"$parent_table\" p";
    } elseif (count($pk_cols) === 1) {
        $pk = '"' . $pk_cols[0] . '"';
        $body = "SELECT $col_list FROM \"$overlay\" "
              . "UNION ALL SELECT $proj_list FROM \"$parent_table\" p "
              . "WHERE p.$pk NOT IN (SELECT $pk FROM \"$overlay\") "
              . "  AND p.$pk NOT IN (SELECT $pk FROM \"$tomb\")";
    } else {
        $tup_p   = '(' . implode(', ', array_map(fn($c) => 'p."' . $c . '"', $pk_cols)) . ')';
        $pk_sel  = implode(', ', array_map(fn($c) => '"' . $c . '"', $pk_cols));
        $body = "SELECT $col_list FROM \"$overlay\" "
              . "UNION ALL SELECT $proj_list FROM \"$parent_table\" p "
              . "WHERE $tup_p NOT IN (SELECT $pk_sel FROM \"$overlay\") "
              . "  AND $tup_p NOT IN (SELECT $pk_sel FROM \"$tomb\")";
    }
    $db->exec("CREATE VIEW \"$logical\" AS $body");

    $defaults = [];
    $pi = $db->query('PRAGMA table_info("' . SQLite3::escapeString($overlay) . '")');
    while ($prow = $pi->fetchArray(SQLITE3_ASSOC)) {
        if ($prow['dflt_value'] !== null) {
            $defaults[$prow['name']] = (string)$prow['dflt_value'];
        }
    }
    $unique_cols = cow_single_col_unique_columns($db, $overlay);
    foreach (cow_trigger_sql($logical, $overlay, $tomb,
                             $pk_cols, $columns, $defaults,
                             $branch_id, $parent_table, $parent_cols,
                             $unique_cols) as $trg) {
        $db->exec($trg);
    }
}

/** Return TRUE if the branch's current DB state diverges from the last
 *  recorded db_commit. Used to gate rollback/reset --no-force against
 *  uncommitted DB changes. */
function db_has_uncommitted_changes(SQLite3 $db, int $branch_id): bool {
    $last = db_last_commit($db, $branch_id);
    if (!$last) return false;  // no anchor → "clean"
    $now = db_state_digest(db_collect_state($db, $branch_id));
    $ref = db_digest_of_commit($db, (int)$last['id']);
    return $now !== $ref;
}

/* ================================================================
 * Hostile-review finding #2 / #18 — resolve a Principal before any
 * subcommand runs and register it on audit_helpers. Every subsequent
 * `audit_log_write` call binds to this principal; CLI flags / env
 * vars cannot override it.
 *
 * Read-only subcommands (list, show, audit, log, status, diff, help)
 * don't need a principal — they don't mutate state. Everything else
 * does. This keeps `branchctl list` usable by operators without creds.
 * ================================================================ */
require_once __DIR__ . '/principal.php';

function branchctl_is_readonly_cmd(string $cmd): bool {
    return in_array($cmd, [
        'list', 'show', 'log', 'status', 'diff', 'audit', 'gc',
        'help', '-h', '--help',
    ], true);
}

/**
 * Hostile-review finding #18 — DDL allowlist for the `_ddl` subcommand.
 *
 * Returns true only when $sql is one of:
 *   ALTER TABLE …
 *   CREATE [UNIQUE] INDEX …
 *   DROP INDEX …
 *   ALTER TABLE … RENAME …   (covered by ALTER TABLE prefix)
 * Anything else — including DROP TABLE, CREATE TABLE, DELETE, UPDATE,
 * INSERT, SELECT, ATTACH, DETACH, PRAGMA, any multi-statement input —
 * is rejected. The proxy's intended DDL routing surface is exactly
 * these three shapes; broader forms (DROP TABLE, CREATE TABLE) would
 * need distinct handling anyway.
 *
 * We reject multi-statement input defensively: a single forbidden
 * statement hidden in a payload like "ALTER TABLE x ADD y; DELETE FROM
 * audit_log" would otherwise slip through.
 */
function branchctl_is_allowed_ddl(string $sql): bool {
    // Normalize: strip leading whitespace + any SQL comments at the head.
    $s = ltrim($sql);
    // Strip leading SQL line & block comments until we hit real code.
    while (true) {
        if (strncmp($s, '--', 2) === 0) {
            $eol = strpos($s, "\n");
            $s = ($eol === false) ? '' : ltrim(substr($s, $eol + 1));
            continue;
        }
        if (strncmp($s, '/*', 2) === 0) {
            $end = strpos($s, '*/', 2);
            if ($end === false) return false;
            $s = ltrim(substr($s, $end + 2));
            continue;
        }
        break;
    }
    if ($s === '') return false;

    // Strip a trailing semicolon + whitespace so "ALTER TABLE x;" is OK.
    // Reject multi-statement: any semicolon NOT at the trailing tail means
    // a second statement follows.
    $trimmed = rtrim($s, " \t\r\n;");
    if (strpos($trimmed, ';') !== false) {
        // A semicolon inside a string literal is fine; we do a quick
        // scan that tracks single quotes only (DDL has no need for
        // complex quoting — column names are backticked/double-quoted
        // identifiers, not strings).
        $in = false;
        $len = strlen($trimmed);
        for ($i = 0; $i < $len; $i++) {
            $c = $trimmed[$i];
            if ($c === "'") {
                // Handle '' escape.
                if ($in && $i + 1 < $len && $trimmed[$i + 1] === "'") {
                    $i++;
                    continue;
                }
                $in = !$in;
            } elseif (!$in && $c === ';') {
                return false;  // multi-statement
            }
        }
    }

    $up = strtoupper($trimmed);
    // ALTER TABLE (add/drop/rename column, rename to) — BranchedPDO handles
    // the view-rebuild. Note we accept RENAME TO even though BranchedPDO
    // rejects it; BranchedPDO will surface its own RuntimeException.
    if (preg_match('/^ALTER\s+TABLE\b/i', $trimmed)) return true;
    // CREATE [UNIQUE] INDEX [IF NOT EXISTS] … ON …
    if (preg_match('/^CREATE\s+(UNIQUE\s+)?INDEX\b/i', $trimmed)) return true;
    // DROP INDEX [IF EXISTS] …
    if (preg_match('/^DROP\s+INDEX\b/i', $trimmed)) return true;

    return false;
}

// Ensure the .fp has an audit_log table + triggers before the principal
// is resolved (so bootstrap migration runs exactly once, early).
{
    $__mig = sqlite_open($DB_PATH);
    unset($__mig); /* sqlite_open calls fs_migrate(); close happens implicitly */
}

$__principal_reason = null;
$__principal = principal_resolve($DB_PATH, $flags, $__principal_reason);
if ($__principal === null) {
    if (!branchctl_is_readonly_cmd((string)$cmd)) {
        // Writers (including _ddl) under auth_enabled=1 require a
        // verified principal.
        fwrite(STDERR, "branchctl: " . ($__principal_reason ?: 'auth required') . "\n");
        exit(2);
    }
    // Read-only: run under a nameless system principal. Audit rows
    // aren't written for read-only operations so this is benign.
    $__principal = new Principal('system', 'read', true);
}
audit_log_set_principal($__principal);

switch ($cmd) {

case 'list': {
    branchfs_set_db($DB_PATH);
    $db = sqlite_open($DB_PATH);
    $branches = branchfs_list($db);

    printf("%-20s  %-20s  %-8s  %s\n", "BRANCH", "PARENT", "FILES", "CREATED");
    printf("%s\n", str_repeat('-', 70));
    foreach ($branches as $b) {
        $files = branchfs_file_count($db, (int)$b['id']);
        printf("%-20s  %-20s  %-8d  %s\n",
            $b['name'],
            $b['parent_branch'] ?? '(root)',
            $files,
            $b['created_at'] ?? ''
        );
    }
    break;
}

case 'create': {
    $name = $pos[1] ?? die_usage("`create` needs a branch name");
    $from = (string)($flags['from'] ?? 'main');
    if (!valid_branch_name($name)) die_usage("invalid branch name: $name");
    if ($name === 'main') die_usage("'main' is reserved");
    if (is_reserved_branch_name($name)) {
        die_usage("'$name' is reserved (collides with HTTP routing); reserved names are: "
            . implode(', ', reserved_branch_names()));
    }

    // Validate the parent exists BEFORE asking the extension to create the
    // branch. `branchfs_create_branch()` silently no-ops for a missing parent
    // (the extension has no way to report error), which used to leave the
    // CLI claiming success while the `branches` row never appeared.
    {
        $precheck = new SQLite3($DB_PATH, SQLITE3_OPEN_READONLY);
        $st = $precheck->prepare("SELECT 1 FROM branches WHERE name = :n LIMIT 1");
        $st->bindValue(':n', $from, SQLITE3_TEXT);
        $res = $st->execute();
        $has_parent = (bool)$res->fetchArray(SQLITE3_NUM);
        $res->finalize();
        $st->close();
        // Also reject creating a branch whose name already exists.
        $st2 = $precheck->prepare("SELECT 1 FROM branches WHERE name = :n LIMIT 1");
        $st2->bindValue(':n', $name, SQLITE3_TEXT);
        $res2 = $st2->execute();
        $exists = (bool)$res2->fetchArray(SQLITE3_NUM);
        $res2->finalize();
        $st2->close();
        $precheck->close();
        if (!$has_parent) {
            fwrite(STDERR, "branchctl: no parent branch named '$from'\n");
            exit(4);
        }
        if ($exists) {
            fwrite(STDERR, "branchctl: branch '$name' already exists\n");
            exit(4);
        }
    }

    branchfs_set_db($DB_PATH);
    branchfs_create_branch($name, $from);
    echo "branchfs: forked '$from' -> '$name'\n";

    /* Record an initial snapshot so reset has a landing point even before
     * the user makes any commits. The fs side is recorded NOW; the db side
     * is recorded BELOW, after the COW table machinery has built the
     * overlays — so the initial db_commit captures an empty-but-correct
     * state baseline. */
    $db = sqlite_open($DB_PATH);
    $bid = fs_branch_id($db, $name);
    if ($bid > 0) {
        fs_record_snapshot($db, $bid, "branchctl create from '$from'");
        echo "branchfs: initial snapshot recorded\n";
    }

    // ── COW (copy-on-write) DB branch creation ──────────────────────────
    //
    // Old behavior copied every parent row into b{new}_wp_* tables and
    // snapshotted them into db_snapshots — O(N rows) in time and storage.
    // New behavior creates a view + overlay + tombstones trio per table,
    // O(num_tables) and a few KB regardless of row count.
    //
    // The branch's view UNION-ALLs its overlay with the parent's view minus
    // tombstones. Reads transparently inherit; writes get caught by INSTEAD OF
    // triggers that route to overlay/tombstones.
    $parent_id = fs_branch_id($db, $from);
    $new_id    = fs_branch_id($db, $name);
    if ($parent_id > 0 && $new_id > 0) {
        $prefix_parent = "b{$parent_id}_wp_";

        // Collect parent table/view suffixes — anything matching b{parent}_wp_*
        // that ISN'T an internal __overlay / __tombstones artifact. Both real
        // tables (parent is main) and views (parent is itself a branch) qualify.
        $tables_stmt = $db->prepare(
            "SELECT name, type FROM sqlite_master "
          . "WHERE name LIKE :p "
          . "  AND type IN ('table', 'view') "
          . "  AND name NOT LIKE '%\\_\\_overlay' ESCAPE '\\' "
          . "  AND name NOT LIKE '%\\_\\_tombstones' ESCAPE '\\'"
        );
        $tables_stmt->bindValue(':p', $prefix_parent . '%', SQLITE3_TEXT);
        $tables_result = $tables_stmt->execute();
        $suffixes_to_cow = [];
        while ($row = $tables_result->fetchArray(SQLITE3_ASSOC)) {
            $suffixes_to_cow[] = substr($row['name'], strlen($prefix_parent));
        }
        $tables_result->finalize();
        $tables_stmt->close();

        $created = 0;
        $db->exec('BEGIN IMMEDIATE');
        try {
            foreach ($suffixes_to_cow as $suffix) {
                cow_create_branch_table($db, $new_id, $parent_id, $suffix);
                $created++;
            }
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            echo "warning: COW branch table creation failed: " . $e->getMessage() . "\n";
        }
        if ($created > 0) {
            echo "branchfs: COW-forked $created tables from '$from' (no row copy)\n";
        }
    }

    // Pair the just-created fs_commit with an initial db_commit so the
    // unified rollback graph has a landing point on day 0. We thread the
    // same commit_hash so log/log-show can pair them visually.
    $first_fs = fs_last_commit($db, $bid);
    if ($first_fs && $bid > 0) {
        $db->exec('BEGIN IMMEDIATE');
        try {
            db_record_snapshot($db, $bid, (int)$first_fs['id'], (string)$first_fs['commit_hash']);
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            // Non-fatal: future commits will still create db_commits rows.
            fwrite(STDERR, "warning: initial db snapshot failed: " . $e->getMessage() . "\n");
        }
    }

    echo "\n";
    $root_host = getenv('BRANCHFS_ROOT_HOST') ?: 'localhost';
    $port = getenv('PORT') ?: '80';
    echo "Visit http://$name.$root_host:$port/ to see this branch.\n";
    audit_log_write($db, 'create', $name, ['from' => $from]);
    break;
}

case 'commit': {
    $name = $pos[1] ?? die_usage("`commit` needs a branch name");
    if (!valid_branch_name($name)) die_usage("invalid branch name: $name");
    $msg = (string)($flags['message'] ?? ("branchctl commit on " . date('c')));

    $db = sqlite_open($DB_PATH);
    $bid = fs_branch_id($db, $name);
    if ($bid <= 0) {
        fwrite(STDERR, "branchctl: no branch named '$name'\n");
        exit(4);
    }

    // TODO3 #4: auto-migrate legacy (full-copy) branches to COW format
    // when they first commit. Commit already touches every overlay on
    // the branch, so folding the one-shot migration in costs nothing
    // extra on the steady-state path — and it guarantees branches used
    // but never merged still get the efficient format eventually.
    $has_legacy = (int)$db->querySingle(
        "SELECT COUNT(*) FROM sqlite_master "
      . "WHERE type='table' "
      . "  AND name LIKE 'b{$bid}_wp_%' "
      . "  AND name NOT LIKE '%\\_\\_overlay' ESCAPE '\\' "
      . "  AND name NOT LIKE '%\\_\\_tombstones' ESCAPE '\\'"
    );
    if ($has_legacy > 0) {
        $db->exec('BEGIN IMMEDIATE');
        try {
            $n = cow_migrate_legacy_branch($db, $bid);
            $db->exec('COMMIT');
            if ($n > 0) {
                echo "branchctl: auto-migrated $n legacy table(s) on '$name' to COW format\n";
            }
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            fwrite(STDERR, "branchctl: commit-time COW migration on '$name' failed: "
                         . $e->getMessage() . "\n");
            exit(5);
        }
    }

    // Detect "no-op commit" against EITHER side. If neither files nor DB
    // diverged from the last anchored snapshot, skip — re-snapshotting an
    // unchanged tree fills the commit graph with empty markers.
    $current_fs_snap = fs_last_commit($db, $bid);
    $current_fs_dig  = fs_tree_digest(fs_resolve_tree($db, $bid));
    $anchor_fs_dig   = $current_fs_snap ? fs_digest_of_commit($db, (int)$current_fs_snap['id']) : '';

    $current_db_snap = db_last_commit($db, $bid);
    $current_db_dig  = db_state_digest(db_collect_state($db, $bid));
    $anchor_db_dig   = $current_db_snap ? db_digest_of_commit($db, (int)$current_db_snap['id']) : '';

    $fs_clean = $current_fs_snap && $current_fs_dig === $anchor_fs_dig;
    $db_clean = $current_db_snap && $current_db_dig === $anchor_db_dig;

    if ($fs_clean && $db_clean) {
        echo "branchfs: no changes on '$name' (files OR db); skipped snapshot.\n";
        break;
    }

    // Atomic commit: fs_record_snapshot has its own BEGIN/COMMIT; we
    // rendez-vous with it by adding the db_commit row on the same hash
    // INSIDE its transaction. To keep the change small, we open a wrapping
    // BEGIN here and have fs_record_snapshot detect we're already in a tx
    // (done via SQLite3::exec returning false rather than throwing) ...
    // simpler: just take the BEGIN ourselves, replicate fs_record_snapshot
    // logic inline.
    $commit_hash = bin2hex(random_bytes(16));
    $tree = fs_resolve_tree($db, $bid);
    $parent = $current_fs_snap;
    $parent_id = $parent['id'] ?? null;

    // TODO3 #5: delta-encode file commits inline (same logic as
    // fs_record_snapshot, but we open our own BEGIN here so fs side +
    // db side share a single atomic transaction).
    $fs_kind = $parent_id === null ? 'FULL' : 'DELTA';
    $fs_prev_tree = ($fs_kind === 'DELTA')
        ? fs_materialize_commit_tree($db, (int)$parent_id)
        : [];

    $db->exec('BEGIN IMMEDIATE');
    try {
        // ---- fs side ----
        $s = $db->prepare("INSERT INTO fs_commits (branch_id, commit_hash, parent_id, message, kind) VALUES (:b, :h, :p, :m, :k)");
        $s->bindValue(':b', $bid,         SQLITE3_INTEGER);
        $s->bindValue(':h', $commit_hash, SQLITE3_TEXT);
        $parent_id === null
            ? $s->bindValue(':p', null,       SQLITE3_NULL)
            : $s->bindValue(':p', $parent_id, SQLITE3_INTEGER);
        $s->bindValue(':m', $msg, SQLITE3_TEXT);
        $s->bindValue(':k', $fs_kind, SQLITE3_TEXT);
        $s->execute();
        $fs_cid = (int)$db->lastInsertRowID();

        $ins = $db->prepare(
            "INSERT INTO fs_commit_files (commit_id, path, blob_hash, mode, mtime, is_dir, op) "
          . "VALUES (:c, :p, :bh, :md, :mt, :d, :op)"
        );
        $cur_paths = [];
        foreach ($tree as $path => $e) {
            $cur_paths[$path] = true;
            if ($fs_kind === 'DELTA') {
                $prev = $fs_prev_tree[$path] ?? null;
                $same = $prev
                    && (string)($prev['blob_hash'] ?? '') === (string)($e['blob_hash'] ?? '')
                    && (int)($prev['mode']   ?? 0) === (int)($e['mode']   ?? 0)
                    && (int)($prev['mtime']  ?? 0) === (int)($e['mtime']  ?? 0)
                    && (int)($prev['is_dir'] ?? 0) === (int)($e['is_dir'] ?? 0);
                if ($same) continue;
            }
            $ins->bindValue(':c',  $fs_cid, SQLITE3_INTEGER);
            $ins->bindValue(':p',  $path,   SQLITE3_TEXT);
            $ins->bindValue(':bh', $e['blob_hash'] ?? null,
                $e['blob_hash'] ? SQLITE3_TEXT : SQLITE3_NULL);
            $ins->bindValue(':md', (int)($e['mode']   ?? 0), SQLITE3_INTEGER);
            $ins->bindValue(':mt', (int)($e['mtime']  ?? 0), SQLITE3_INTEGER);
            $ins->bindValue(':d',  (int)($e['is_dir'] ?? 0), SQLITE3_INTEGER);
            $ins->bindValue(':op', 'UPSERT', SQLITE3_TEXT);
            $ins->execute();
            $ins->reset();
        }
        if ($fs_kind === 'DELTA') {
            foreach ($fs_prev_tree as $p => $_) {
                if (isset($cur_paths[$p])) continue;
                $ins->bindValue(':c',  $fs_cid, SQLITE3_INTEGER);
                $ins->bindValue(':p',  $p,      SQLITE3_TEXT);
                $ins->bindValue(':bh', null,    SQLITE3_NULL);
                $ins->bindValue(':md', 0,       SQLITE3_INTEGER);
                $ins->bindValue(':mt', 0,       SQLITE3_INTEGER);
                $ins->bindValue(':d',  0,       SQLITE3_INTEGER);
                $ins->bindValue(':op', 'DELETE', SQLITE3_TEXT);
                $ins->execute();
                $ins->reset();
            }
        }

        // ---- db side ----
        $db_cid = db_record_snapshot($db, $bid, $fs_cid, $commit_hash);
        $db->exec('COMMIT');
        echo "branchfs: snapshot #$fs_cid committed on '$name': $msg\n";
        echo "branchfs: commit " . substr($commit_hash, 0, 12) . "\n";
        echo "branchfs: db_commit #$db_cid recorded for branch '$name'\n";
    } catch (\Throwable $e) {
        $db->exec('ROLLBACK');
        fwrite(STDERR, "branchctl: commit failed: " . $e->getMessage() . "\n");
        exit(5);
    }
    audit_log_write($db, 'commit', $name,
        ['commit_hash' => $commit_hash, 'message' => $msg]);
    break;
}

case 'delete': {
    $name = $pos[1] ?? die_usage("`delete` needs a branch name");
    if (!valid_branch_name($name)) die_usage("invalid branch name: $name");
    if ($name === 'main') die_usage("'main' cannot be deleted");

    branchfs_set_db($DB_PATH);
    $db = sqlite_open($DB_PATH);
    $s = $db->prepare("SELECT id FROM branches WHERE name = :n");
    $s->bindValue(':n', $name, SQLITE3_TEXT);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_NUM);
    // Finalize the result and statement before DDL — an open read cursor keeps
    // a read transaction alive, which blocks DROP TABLE ("database table is locked").
    $r->finalize();
    $s->close();
    if (!$row) {
        echo "branchfs: no branch named '$name'\n";
        break;
    }

    $bid = (int)$row[0];

    /* Collect candidate blob hashes BEFORE we drop the rows that reference
     * them. Only hashes referenced by the deleted branch's rows are
     * candidates — this bounds inline-GC work to O(deleted-branch blobs),
     * not O(whole blob store). */
    $candidates = [];
    $cr = $db->query(
        "SELECT DISTINCT blob_hash FROM files "
      . "WHERE branch_id = $bid AND blob_hash IS NOT NULL"
    );
    while ($crow = $cr->fetchArray(SQLITE3_NUM)) {
        $candidates[$crow[0]] = true;
    }
    $cr->finalize();
    $cr2 = $db->query(
        "SELECT DISTINCT fcf.blob_hash FROM fs_commit_files fcf "
      . "JOIN fs_commits fc ON fc.id = fcf.commit_id "
      . "WHERE fc.branch_id = $bid AND fcf.blob_hash IS NOT NULL"
    );
    while ($crow = $cr2->fetchArray(SQLITE3_NUM)) {
        $candidates[$crow[0]] = true;
    }
    $cr2->finalize();
    $candidate_hashes = array_keys($candidates);

    // Collect DROP targets BEFORE the transaction: DROP cannot run while an
    // iterator over sqlite_master is open, and SQLite's DDL rules forbid
    // schema changes mid-transaction on some builds. Both real tables (legacy
    // copy-format branches) AND views/overlays/tombstones (COW branches)
    // need to be dropped.
    $prefix = "b{$bid}_wp_";
    $ts = $db->prepare(
        "SELECT name, type FROM sqlite_master "
      . "WHERE name LIKE :p AND type IN ('table', 'view')"
    );
    $ts->bindValue(':p', $prefix . '%', SQLITE3_TEXT);
    $tr = $ts->execute();
    $views_to_drop  = [];
    $tables_to_drop = [];
    while ($trow = $tr->fetchArray(SQLITE3_ASSOC)) {
        if ($trow['type'] === 'view') {
            $views_to_drop[] = $trow['name'];
        } else {
            $tables_to_drop[] = $trow['name'];
        }
    }
    $tr->finalize();
    $ts->close();

    $reclaimed_n = 0;
    $reclaimed_bytes = 0;
    $db->exec('BEGIN IMMEDIATE');
    try {
        // Views first so triggers go with them; then tables (overlays + tombstones).
        // Triggers ON the views are dropped automatically by DROP VIEW.
        foreach ($views_to_drop as $vname) {
            $db->exec("DROP VIEW IF EXISTS \"$vname\"");
        }
        foreach ($tables_to_drop as $tname) {
            $db->exec("DROP TABLE IF EXISTS \"$tname\"");
        }
        // sqlite_sequence cleanup for the dropped overlays.
        @$db->exec("DELETE FROM sqlite_sequence WHERE name LIKE '"
                 . SQLite3::escapeString($prefix) . "%'");
        // Drop COW marker rows so subsequent helpers don't touch a phantom branch.
        $db->exec("DELETE FROM db_cow_branches WHERE branch_id = $bid");
        $db->exec("DELETE FROM db_snapshots_schema WHERE branch_id = $bid");
        $db->exec("DELETE FROM db_ancestor_overlay WHERE branch_id = $bid");
        $db->exec("DELETE FROM db_post_fork_inserts WHERE branch_id = $bid");
        // Hostile-review #8: lazy-ancestor snapshots are keyed by
        // (parent_table_name, row_pk). When a branch whose tables served
        // as "parent" for those snapshots is deleted, its tables are
        // dropped — leaving orphaned rows that nothing can reference.
        // Purge rows whose parent_table_name points at this branch's
        // (just-dropped) table-space so db_parent_ancestor stays bounded
        // on high-churn sites (per-PR preview-branch lifecycle).
        $pa_prefix_esc = SQLite3::escapeString($prefix);
        $db->exec(
            "DELETE FROM db_parent_ancestor "
          . "WHERE parent_table_name LIKE '{$pa_prefix_esc}%'"
        );
        /* fs_commit_files rows are keyed by commit_id, so they must go
         * before (or together with) the fs_commits rows to avoid orphan
         * rows after delete. Previously, the delete path forgot fs_commits
         * entirely — leaving stale snapshot rows that kept blobs reachable. */
        $db->exec(
            "DELETE FROM fs_commit_files "
          . "WHERE commit_id IN (SELECT id FROM fs_commits WHERE branch_id = $bid)"
        );
        $db->exec("DELETE FROM fs_commits  WHERE branch_id = $bid");
        // db_commit_* are keyed by db_commits.id; clean them too.
        $db->exec(
            "DELETE FROM db_commit_overlays WHERE commit_id IN "
          . "(SELECT id FROM db_commits WHERE branch_id = $bid)"
        );
        $db->exec(
            "DELETE FROM db_commit_tombstones WHERE commit_id IN "
          . "(SELECT id FROM db_commits WHERE branch_id = $bid)"
        );
        $db->exec(
            "DELETE FROM db_commit_schema WHERE commit_id IN "
          . "(SELECT id FROM db_commits WHERE branch_id = $bid)"
        );
        $db->exec("DELETE FROM db_commits   WHERE branch_id = $bid");
        $db->exec("DELETE FROM files        WHERE branch_id = $bid");
        $db->exec("DELETE FROM db_snapshots WHERE branch_id = $bid");
        // TODO3 #15: OPcache invalidation queue entries reference the
        // branch by URL prefix `branchfs://<branch>/…`. Purge them so
        // high-churn sites (per-PR preview branches) don't accumulate
        // stale rows.
        $inv = $db->prepare(
            "DELETE FROM opcache_invalidations WHERE url LIKE :p"
        );
        if ($inv) {
            $inv->bindValue(':p', 'branchfs://' . $name . '/%', SQLITE3_TEXT);
            @$inv->execute();
        }
        $db->exec("DELETE FROM branches     WHERE id        = $bid");

        /* Inline GC on the candidate hashes only. fs_gc is transaction-aware:
         * it reuses our BEGIN IMMEDIATE rather than nesting. */
        if (!empty($candidate_hashes)) {
            [$reclaimed_n, $reclaimed_bytes] = fs_gc($db, $candidate_hashes);
        }
        $db->exec('COMMIT');
    } catch (\Throwable $e) {
        $db->exec('ROLLBACK');
        fwrite(STDERR, "branchctl: delete failed: " . $e->getMessage() . "\n");
        exit(5);
    }

    echo "branchfs: deleted branch '$name'\n";
    if ($reclaimed_n > 0) {
        printf("branchfs: reclaimed %d orphaned blob(s), %d bytes\n",
            $reclaimed_n, $reclaimed_bytes);
    }
    audit_log_write($db, 'delete', $name,
        ['blobs_reclaimed' => $reclaimed_n, 'bytes' => $reclaimed_bytes]);
    break;
}

case 'show': {
    $name = $pos[1] ?? die_usage("`show` needs a branch name");
    if (!valid_branch_name($name) && $name !== 'main') die_usage("invalid branch name: $name");

    $db = sqlite_open($DB_PATH);
    $s = $db->prepare("SELECT id, name, parent_branch, created_at FROM branches WHERE name = :n");
    $s->bindValue(':n', $name, SQLITE3_TEXT);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        echo "branchfs: no overlay named '$name'\n";
    } else {
        $n = branchfs_file_count($db, (int)$row['id']);
        echo "branchfs: $name\n";
        echo "  parent : " . ($row['parent_branch'] ?? '(root)') . "\n";
        echo "  files  : $n\n";
        echo "  created: " . ($row['created_at'] ?? '') . "\n";
        $last = fs_last_commit($db, (int)$row['id']);
        if ($last) {
            echo "  last commit: " . substr($last['commit_hash'] ?? '', 0, 12)
               . " — " . ($last['message'] ?? '') . "\n";
        }
    }
    break;
}

case 'log': {
    $name = $pos[1] ?? die_usage("`log` needs a branch name");
    if (!valid_branch_name($name) && $name !== 'main') die_usage("invalid branch name: $name");
    $limit = (int)($flags['n'] ?? $flags['limit'] ?? 20);
    if ($limit < 1) $limit = 20;
    if ($limit > 500) $limit = 500;

    $db = sqlite_open($DB_PATH);
    $bid = fs_branch_id($db, $name);
    if ($bid <= 0) {
        echo "branchfs: no branch named '$name'\n";
        break;
    }

    // Unified log: every fs_commit on this branch joined with the matching
    // db_commit (LEFT JOIN so legacy fs_commits without a paired db_commit
    // still show up). The "DB" column flags whether DB state was snapshotted
    // alongside the file state.
    $s = $db->prepare(
        "SELECT fc.id, fc.commit_hash, fc.message, fc.created_at, "
      . "       (CASE WHEN dc.id IS NULL THEN '-' ELSE 'db' END) AS db_flag "
      . "FROM fs_commits fc "
      . "LEFT JOIN db_commits dc "
      . "  ON dc.fs_commit_id = fc.id AND dc.branch_id = fc.branch_id "
      . "WHERE fc.branch_id = :b ORDER BY fc.id DESC LIMIT :lim"
    );
    $s->bindValue(':b', $bid, SQLITE3_INTEGER);
    $s->bindValue(':lim', $limit, SQLITE3_INTEGER);
    $r = $s->execute();
    $rows = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    printf("%-34s  %-3s  %-19s  %s\n", "COMMIT", "DB", "WHEN", "MESSAGE");
    printf("%s\n", str_repeat('-', 84));
    foreach ($rows as $row) {
        printf("%-34s  %-3s  %-19s  %s\n",
            $row['commit_hash'] ?? '',
            $row['db_flag']     ?? '-',
            substr($row['created_at'] ?? '', 0, 19),
            trim($row['message'] ?? '')
        );
    }
    if (empty($rows)) {
        echo "  (no commits on '$name' yet)\n";
    }
    break;
}

case 'status': {
    // TODO3 #11 — per-table summary of branch divergence.
    //
    // Prints overlay / tombstone row counts per b{id}_wp_X table plus
    // a note when the branch has uncommitted DB changes since the
    // last db_commit. Intended as a fast "what's different on this
    // branch right now?" orientation for operators.
    $name = $pos[1] ?? die_usage("`status` needs a branch name");
    if (!valid_branch_name($name) && $name !== 'main') die_usage("invalid branch: $name");
    $db = sqlite_open($DB_PATH);
    $bid = fs_branch_id($db, $name);
    if ($bid <= 0) {
        fwrite(STDERR, "branchctl: no branch named '$name'\n");
        exit(4);
    }
    echo "branchfs: status of '$name' (id=$bid)\n";
    printf("\n  %-24s %10s %10s\n", "TABLE_SUFFIX", "OVERLAY", "TOMBS");
    printf("  %s\n", str_repeat('-', 48));
    $suffixes = db_branch_table_suffixes($db, $bid);
    $total_o = 0; $total_t = 0;
    foreach ($suffixes as $suffix) {
        $overlay = ($bid === 1)
            ? "b1_wp_$suffix"
            : "b{$bid}_wp_{$suffix}__overlay";
        $tomb    = ($bid === 1) ? null : "b{$bid}_wp_{$suffix}__tombstones";
        $ov_n = cow_is_table($db, $overlay)
            ? (int)$db->querySingle('SELECT COUNT(*) FROM "' . SQLite3::escapeString($overlay) . '"')
            : 0;
        $tb_n = ($tomb !== null && cow_is_table($db, $tomb))
            ? (int)$db->querySingle('SELECT COUNT(*) FROM "' . SQLite3::escapeString($tomb) . '"')
            : 0;
        $total_o += $ov_n; $total_t += $tb_n;
        printf("  %-24s %10d %10d\n", $suffix, $ov_n, $tb_n);
    }
    printf("  %s\n", str_repeat('-', 48));
    printf("  %-24s %10d %10d\n", "total", $total_o, $total_t);
    echo "\n";
    if ($bid > 1) {
        echo db_has_uncommitted_changes($db, $bid)
            ? "  DB state: divergent from last db_commit (uncommitted changes)\n"
            : "  DB state: clean (matches last db_commit)\n";
    }
    break;
}

case 'diff': {
    $a = $pos[1] ?? die_usage("`diff` needs two branch names");
    $b = $pos[2] ?? die_usage("`diff` needs two branch names");
    if (!valid_branch_name($a) && $a !== 'main') die_usage("invalid branch: $a");
    if (!valid_branch_name($b) && $b !== 'main') die_usage("invalid branch: $b");
    // Use --rows for the DB-row-level diff mode. `--db <path>` is the
    // global DB-path override, so those two flag names must stay distinct.
    $mode_db = !empty($flags['rows']);

    $db = sqlite_open($DB_PATH);
    $aid = (int)$db->querySingle("SELECT id FROM branches WHERE name = '" . $db->escapeString($a) . "'");
    $bid = (int)$db->querySingle("SELECT id FROM branches WHERE name = '" . $db->escapeString($b) . "'");

    if ($mode_db) {
        // TODO3 #11 — row-level DB diff between two branches. Walks the
        // union of suffixes on both sides and prints the PK-level
        // differences in each table. Runs against the view layer so
        // cross-branch inherited rows are taken into account.
        echo "branchfs: db diff '$a' vs '$b'\n";
        $a_sufs = $aid > 0 ? db_branch_table_suffixes($db, $aid) : [];
        $b_sufs = $bid > 0 ? db_branch_table_suffixes($db, $bid) : [];
        $all = array_values(array_unique(array_merge($a_sufs, $b_sufs)));
        sort($all);
        $total = 0;
        foreach ($all as $suffix) {
            $ta = $aid > 0 ? db_overlay_or_real($aid, $suffix) : '';
            $tb = $bid > 0 ? db_overlay_or_real($bid, $suffix) : '';
            // Diff via the VIEW layer so inherited rows aren't confused
            // with divergence; read each branch's logical name.
            $la = ($aid === 1) ? "b1_wp_$suffix" : "b{$aid}_wp_$suffix";
            $lb = ($bid === 1) ? "b1_wp_$suffix" : "b{$bid}_wp_$suffix";
            if (!cow_is_table($db, $la) && !cow_is_view($db, $la)) { continue; }
            if (!cow_is_table($db, $lb) && !cow_is_view($db, $lb)) { continue; }
            $pk_cols = cow_extract_pk_cols($db, $la);
            $cols    = cow_table_columns($db, $la);
            if (empty($cols)) continue;

            $row_a = [];
            $r = $db->query('SELECT * FROM "' . SQLite3::escapeString($la) . '"');
            while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
                $pkm = [];
                foreach ($pk_cols as $c) $pkm[$c] = $row[$c] ?? null;
                $row_a[json_encode($pkm)] = json_encode($row);
            }
            $r->finalize();
            $row_b = [];
            $r = $db->query('SELECT * FROM "' . SQLite3::escapeString($lb) . '"');
            while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
                $pkm = [];
                foreach ($pk_cols as $c) $pkm[$c] = $row[$c] ?? null;
                $row_b[json_encode($pkm)] = json_encode($row);
            }
            $r->finalize();
            $added = array_diff_key($row_b, $row_a);
            $removed = array_diff_key($row_a, $row_b);
            $modified = [];
            foreach ($row_a as $pk => $ja) {
                if (isset($row_b[$pk]) && $row_b[$pk] !== $ja) {
                    $modified[$pk] = true;
                }
            }
            if (!$added && !$removed && !$modified) continue;
            printf("  table %s: +%d / -%d / ~%d\n",
                $suffix, count($added), count($removed), count($modified));
            $total += count($added) + count($removed) + count($modified);
        }
        if ($total === 0) {
            echo "  (no row differences between '$a' and '$b')\n";
        }
        break;
    }

    echo "branchfs: overlay diff '$a' vs '$b'\n";

    $tree_a = $aid > 0 ? fs_resolve_tree($db, $aid) : [];
    $tree_b = $bid > 0 ? fs_resolve_tree($db, $bid) : [];

    $all_paths = array_unique(array_merge(array_keys($tree_a), array_keys($tree_b)));
    sort($all_paths);

    $diffs = [];
    foreach ($all_paths as $path) {
        $ha = $tree_a[$path]['blob_hash'] ?? null;
        $hb = $tree_b[$path]['blob_hash'] ?? null;
        if ($ha !== $hb) {
            $diffs[] = ['path' => $path, 'a' => $ha, 'b' => $hb];
        }
    }

    if (empty($diffs)) {
        printf("  (no file differences between '$a' and '$b')\n");
    } else {
        printf("\n%-4s  %s\n", "OP", "PATH");
        printf("%s\n", str_repeat('-', 60));
        foreach ($diffs as $d) {
            if ($d['a'] === null) $op = '+';
            elseif ($d['b'] === null) $op = '-';
            else $op = 'M';
            printf("%-4s  %s\n", $op, $d['path']);
        }
        printf("\n  %d file(s) differ\n", count($diffs));
    }
    break;
}

case 'merge': {
    $from = $pos[1] ?? die_usage("`merge` needs a source branch");
    $into = $flags['into'] ?? null;
    if (!$into) die_usage("`merge` needs --into <target>");
    if (!valid_branch_name($from)) die_usage("invalid source: $from");
    if (!valid_branch_name($into) && $into !== 'main') die_usage("invalid target: $into");
    if ($from === $into) {
        // Self-merge is always a no-op and almost certainly a user mistake.
        // Reject explicitly so the CLI surfaces the error instead of silently
        // succeeding.
        die_usage("`merge $from --into $into`: cannot merge a branch into itself");
    }

    // Lazy COW migration: if either branch is in the legacy (full-copy)
    // format, migrate it to view+overlay+tombstone IN PLACE before merge
    // runs. After this, every non-main branch's b{id}_wp_* objects are
    // views, and merge.php's row diff sees the same data through the view
    // layer it would have seen against the real table.
    {
        $db_pre = sqlite_open($DB_PATH);
        $migrated_total = 0;
        foreach ([$from, $into] as $bn) {
            if ($bn === 'main') continue;
            $bid = fs_branch_id($db_pre, $bn);
            if ($bid <= 0) continue;
            // Quick check: any real (non-overlay/tombstone) table under this
            // prefix means legacy format.
            $has_legacy = (int)$db_pre->querySingle(
                "SELECT COUNT(*) FROM sqlite_master "
              . "WHERE type='table' "
              . "  AND name LIKE 'b{$bid}_wp_%' "
              . "  AND name NOT LIKE '%\\_\\_overlay' ESCAPE '\\' "
              . "  AND name NOT LIKE '%\\_\\_tombstones' ESCAPE '\\'"
            );
            if ($has_legacy === 0) continue;
            $db_pre->exec('BEGIN IMMEDIATE');
            try {
                $n = cow_migrate_legacy_branch($db_pre, $bid);
                $db_pre->exec('COMMIT');
                $migrated_total += $n;
                if ($n > 0) {
                    echo "branchctl: lazy-migrated $n legacy table(s) on '$bn' to COW format\n";
                }
            } catch (\Throwable $e) {
                $db_pre->exec('ROLLBACK');
                fwrite(STDERR, "branchctl: lazy COW migration on '$bn' failed: "
                             . $e->getMessage() . "\n");
                exit(5);
            }
        }
        $db_pre->close();
    }

    $merge_script = __DIR__ . '/merge.php';
    if (!file_exists($merge_script)) {
        fwrite(STDERR, "branchctl: scripts/merge.php not found next to branchctl.php\n");
        exit(5);
    }
    echo "branchctl: invoking scripts/merge.php (3-way file + DB merge)...\n";

    $argv_forward = [
        escapeshellarg($from),
        escapeshellarg($into),
        escapeshellarg($DB_PATH),
    ];
    if (isset($flags['strategy'])) {
        $strat = (string)$flags['strategy'];
        if (!in_array($strat, ['abort', 'ours', 'theirs'], true)) {
            die_usage("invalid --strategy '$strat' (must be abort|ours|theirs)");
        }
        $argv_forward[] = '--strategy=' . $strat;
    }
    if (isset($flags['on-id-collision'])) {
        $oic = (string)$flags['on-id-collision'];
        if (!in_array($oic, ['conflict', 'renumber'], true)) {
            die_usage("invalid --on-id-collision '$oic' (must be conflict|renumber)");
        }
        $argv_forward[] = '--on-id-collision=' . $oic;
    }
    $so = realpath(__DIR__ . '/../ext/branchfs.so');
    $php_bin = PHP_BINARY;
    $ext_flag = $so ? '-d extension=' . escapeshellarg($so) : '';
    $cmdline = sprintf(
        '%s %s -d display_errors=Off -d display_startup_errors=Off %s %s',
        escapeshellarg($php_bin),
        $ext_flag,
        escapeshellarg($merge_script),
        implode(' ', $argv_forward)
    );
    passthru($cmdline, $rc);
    if ($rc !== 0) {
        $audit_db = sqlite_open($DB_PATH);
        audit_log_write($audit_db, 'merge_failed', "$from -> $into",
            ['rc' => $rc]);
        fwrite(STDERR, "branchctl: merge exited with status $rc\n");
        exit($rc);
    }
    {
        $audit_db = sqlite_open($DB_PATH);
        audit_log_write($audit_db, 'merge', "$from -> $into",
            ['strategy' => $flags['strategy'] ?? 'abort']);
    }
    break;
}

case 'reset': {
    $name   = $pos[1] ?? die_usage("`reset` needs a branch name");
    $commit = $pos[2] ?? die_usage("`reset` needs a commit hash");
    if (!valid_branch_name($name) && $name !== 'main') die_usage("invalid branch: $name");
    if (!preg_match('/^[A-Za-z0-9]{1,64}$/', $commit)) {
        die_usage("invalid commit hash: $commit (use `branchctl log $name` to list hashes)");
    }
    $force = !empty($flags['force']);

    $db = sqlite_open($DB_PATH);
    $bid = fs_branch_id($db, $name);
    if ($bid <= 0) {
        fwrite(STDERR, "branchctl: no branch named '$name'\n");
        exit(4);
    }

    if (!$force) {
        // Check BOTH file-side and DB-side for uncommitted changes.
        $current_snap = fs_current_commit($db, $bid);
        if ($current_snap) {
            $tree_now    = fs_tree_digest(fs_resolve_tree($db, $bid));
            $snap_digest = fs_digest_of_commit($db, (int)$current_snap['id']);
            if ($tree_now !== $snap_digest) {
                fwrite(STDERR,
                    "branchctl: '$name' has file-side changes since the last snapshot.\n"
                  . "           Reset would discard them. Run `branchctl commit $name` first,\n"
                  . "           or pass --force to discard.\n");
                exit(6);
            }
        }
        if (db_has_uncommitted_changes($db, $bid)) {
            fwrite(STDERR,
                "branchctl: '$name' has DB changes since the last db_commit.\n"
              . "           Reset would discard them. Run `branchctl commit $name` first,\n"
              . "           or pass --force to discard.\n");
            exit(6);
        }
    }

    $fs = fs_find_commit_by_hash($db, $bid, $commit);
    if (!$fs) {
        fwrite(STDERR, "branchctl: no snapshot found for commit '$commit' on branch '$name'.\n");
        fwrite(STDERR, "           Use `branchctl log $name` to see available commits.\n");
        exit(7);
    }
    $db_commit = db_find_commit_by_hash($db, $bid, $commit);

    $n = fs_restore_snapshot($db, $bid, (int)$fs['id']);

    // DB-side restore: paired with the same commit_hash. If we have one,
    // wrap the restore in a transaction so partial-restore can't leak.
    $db_rows = 0;
    if ($db_commit) {
        $db->exec('BEGIN IMMEDIATE');
        try {
            $db_rows = db_restore_snapshot($db, $bid, (int)$db_commit['id']);
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            fwrite(STDERR, "branchctl: db restore failed: " . $e->getMessage() . "\n");
            exit(5);
        }
    }

    echo "branchfs: '$name' reset to commit " . substr($commit, 0, 12) . "\n";
    echo "branchfs: restored $n files from snapshot #{$fs['id']} ({$fs['message']})\n";
    if ($db_commit) {
        echo "branchfs: restored $db_rows db row(s)/tombstone(s) from db_commit #{$db_commit['id']}\n";
    }
    audit_log_write($db, 'reset', $name,
        ['commit' => $commit, 'files' => $n, 'db_rows' => $db_rows]);
    break;
}

case 'rollback': {
    $name = $pos[1] ?? die_usage("`rollback` needs a branch name");
    if (!valid_branch_name($name) && $name !== 'main') die_usage("invalid branch: $name");
    $force = !empty($flags['force']);

    $db = sqlite_open($DB_PATH);
    $bid = fs_branch_id($db, $name);
    if ($bid <= 0) {
        fwrite(STDERR, "branchctl: no branch named '$name'\n");
        exit(4);
    }

    if (!$force) {
        // Check BOTH file-side and DB-side for uncommitted changes.
        $current_snap = fs_current_commit($db, $bid);
        if ($current_snap) {
            $tree_now    = fs_tree_digest(fs_resolve_tree($db, $bid));
            $snap_digest = fs_digest_of_commit($db, (int)$current_snap['id']);
            if ($tree_now !== $snap_digest) {
                fwrite(STDERR,
                    "branchctl: '$name' has file-side changes since the last snapshot.\n"
                  . "           Rollback would discard them. Run `branchctl commit $name`\n"
                  . "           first, or pass --force to discard.\n");
                exit(6);
            }
        }
        if (db_has_uncommitted_changes($db, $bid)) {
            fwrite(STDERR,
                "branchctl: '$name' has DB changes since the last db_commit.\n"
              . "           Rollback would discard them. Run `branchctl commit $name`\n"
              . "           first, or pass --force to discard.\n");
            exit(6);
        }
    }

    $s = $db->prepare(
        "SELECT id, commit_hash, message, created_at FROM fs_commits "
      . "WHERE branch_id = :b ORDER BY id DESC LIMIT 2"
    );
    $s->bindValue(':b', $bid, SQLITE3_INTEGER);
    $r = $s->execute();
    $r->fetchArray(SQLITE3_ASSOC); // skip current (HEAD)
    $prev = $r->fetchArray(SQLITE3_ASSOC);
    // Finalize before any subsequent DDL — open SELECT cursors on
    // fs_commits keep a read lock that blocks DROP TABLE later.
    $r->finalize();
    $s->close();
    if (!$prev) {
        fwrite(STDERR, "branchctl: no previous commit to roll back to on '$name'.\n");
        exit(7);
    }
    $n = fs_restore_snapshot($db, $bid, (int)$prev['id']);

    // DB-side restore: paired by commit_hash with the fs commit we just
    // rolled back to. May be NULL on legacy commits made before db_commits
    // was introduced (in which case we leave DB state untouched, matching
    // the pre-versioning behaviour).
    $db_rows = 0;
    $db_prev = db_find_commit_by_hash($db, $bid, (string)$prev['commit_hash']);
    if ($db_prev) {
        $db->exec('BEGIN IMMEDIATE');
        try {
            $db_rows = db_restore_snapshot($db, $bid, (int)$db_prev['id']);
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            fwrite(STDERR, "branchctl: db rollback failed: " . $e->getMessage() . "\n");
            exit(5);
        }
    }

    echo "branchfs: '$name' rolled back to " . substr($prev['commit_hash'] ?? '', 0, 12) . "\n";
    echo "branchfs: restored $n files from snapshot #{$prev['id']} ({$prev['message']})\n";
    if ($db_prev) {
        echo "branchfs: restored $db_rows db row(s)/tombstone(s) from db_commit #{$db_prev['id']}\n";
    }
    audit_log_write($db, 'rollback', $name,
        ['commit' => $prev['commit_hash'] ?? '', 'files' => $n, 'db_rows' => $db_rows]);
    break;
}

case 'gc': {
    /* Full-store orphan sweep. Same semantics as before the fs_gc refactor:
     * a hash is orphaned when no row in `files` or `fs_commit_files`
     * references it. --dry-run reports without deleting. */
    $dry = !empty($flags['dry-run']);

    $db = sqlite_open($DB_PATH);

    $total_before = (int)$db->querySingle("SELECT COUNT(*) FROM blobs");
    $bytes_before = (int)$db->querySingle("SELECT COALESCE(SUM(size), 0) FROM blobs");

    if ($dry) {
        /* Dry-run shares fs_gc's orphan-detection query but skips the DELETE. */
        $to_delete = [];
        $bytes_free = 0;
        $r = $db->query(
            "SELECT hash, size FROM blobs "
          . "WHERE hash NOT IN (SELECT blob_hash FROM files WHERE blob_hash IS NOT NULL) "
          . "  AND hash NOT IN (SELECT blob_hash FROM fs_commit_files WHERE blob_hash IS NOT NULL)"
        );
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $to_delete[] = $row['hash'];
            $bytes_free += (int)$row['size'];
        }
        printf("branchctl gc (--dry-run): would delete %d blobs, freeing %d bytes\n",
            count($to_delete), $bytes_free);
        printf("  total blobs: %d (%d bytes) -> %d bytes after gc\n",
            $total_before, $bytes_before, $bytes_before - $bytes_free);
        break;
    }

    try {
        [$n, $bytes_free] = fs_gc($db);
    } catch (\Throwable $e) {
        fwrite(STDERR, "branchctl: gc failed: " . $e->getMessage() . "\n");
        exit(4);
    }

    if ($n === 0) {
        printf("branchctl gc: nothing to reclaim (%d blobs, %d bytes)\n",
            $total_before, $bytes_before);
        break;
    }

    printf("branchctl gc: deleted %d blobs, reclaimed %d bytes (before: %d blobs / %d bytes)\n",
        $n, $bytes_free, $total_before, $bytes_before);
    break;
}

case 'migrate': {
    // TODO3 #4 — migrate legacy (full-copy) branches to COW format on demand.
    //
    // Pre-TODO3, legacy branches migrated lazily only on first merge.
    // A branch that was used but never merged stayed in the inefficient
    // full-copy format indefinitely. This subcommand exposes the same
    // migration directly.
    //
    // Usage:
    //   branchctl migrate <branch>   — migrate one branch
    //   branchctl migrate --all      — migrate every legacy branch
    //
    // Cost is O(rows diffed vs parent) per table — same as the merge-
    // time migration. Idempotent: already-COW branches are a no-op.
    $all = !empty($flags['all']);
    $target = $pos[1] ?? null;
    if (!$all && ($target === null || $target === '')) {
        die_usage("`migrate` needs a branch name (or --all)");
    }
    if ($target !== null && $target !== 'main' && !valid_branch_name($target)) {
        die_usage("invalid branch: $target");
    }

    $db = sqlite_open($DB_PATH);

    // Collect branch ids to consider. 'main' is never legacy.
    $branches = [];
    if ($all) {
        $r = $db->query("SELECT id, name FROM branches WHERE name != 'main'");
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $branches[] = [(int)$row['id'], (string)$row['name']];
        }
    } else {
        if ($target === 'main') {
            fwrite(STDERR, "branchctl: main does not need COW migration\n");
            exit(0);
        }
        $bid = fs_branch_id($db, $target);
        if ($bid <= 0) {
            fwrite(STDERR, "branchctl: no branch named '$target'\n");
            exit(4);
        }
        $branches[] = [$bid, $target];
    }

    $total_migrated = 0;
    $branches_migrated = 0;
    foreach ($branches as [$bid, $bname]) {
        $has_legacy = (int)$db->querySingle(
            "SELECT COUNT(*) FROM sqlite_master "
          . "WHERE type='table' "
          . "  AND name LIKE 'b{$bid}_wp_%' "
          . "  AND name NOT LIKE '%\\_\\_overlay' ESCAPE '\\' "
          . "  AND name NOT LIKE '%\\_\\_tombstones' ESCAPE '\\'"
        );
        if ($has_legacy === 0) {
            if (!$all) echo "branchctl: '$bname' is already in COW format (no-op)\n";
            continue;
        }
        $db->exec('BEGIN IMMEDIATE');
        try {
            $n = cow_migrate_legacy_branch($db, $bid);
            $db->exec('COMMIT');
            $total_migrated += $n;
            $branches_migrated++;
            echo "branchctl: migrated $n legacy table(s) on '$bname' to COW format\n";
            audit_log_write($db, 'migrate', $bname, ['tables' => $n]);
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            fwrite(STDERR, "branchctl: migrate '$bname' failed: "
                         . $e->getMessage() . "\n");
            exit(5);
        }
    }
    if ($all) {
        echo "branchctl: migrate --all done — "
           . "$branches_migrated branch(es), $total_migrated table(s) migrated\n";
    }
    break;
}

case '_ddl': {
    // Hidden subcommand: run DDL on a branch through BranchedPDO.
    //
    // This keeps branch-specific ALTER TABLE / CREATE INDEX / DROP INDEX
    // routing on the same view-rebuild / overlay re-targeting path that
    // in-process PHP callers already get.
    //
    // Usage: branchctl _ddl --branch <name> [--db <path>]   (SQL on stdin)
    //
    // Hostile-review finding #18: this subcommand previously accepted ANY
    // SQL on stdin, including DELETE FROM audit_log. Now:
    //   - principal resolution (above) already requires --user/--password
    //     or FORKPRESS_TOKEN under auth_enabled=1;
    //   - we validate the SQL is an allowlisted DDL form before running.
    //
    // Exits 0 on success; prints the SQLite error (if any) to STDERR and
    // exits 5 on failure.
    $branch = (string)($flags['branch'] ?? '');
    if ($branch === '') die_usage("`_ddl` needs --branch <name>", 2);
    if ($branch !== 'main' && !valid_branch_name($branch)) {
        die_usage("invalid branch: $branch", 2);
    }
    $sql = stream_get_contents(STDIN);
    if ($sql === false || trim((string)$sql) === '') {
        fwrite(STDERR, "branchctl: _ddl: no SQL on stdin\n");
        exit(2);
    }
    // Reject non-DDL. Allowlist: ALTER TABLE, CREATE [UNIQUE] INDEX,
    // DROP INDEX. Everything else — DROP TABLE, CREATE TABLE, DELETE,
    // UPDATE, INSERT, SELECT, PRAGMA, ATTACH — is rejected.
    if (!branchctl_is_allowed_ddl((string)$sql)) {
        fwrite(STDERR,
            "branchctl: _ddl: SQL is not an allowed DDL form — "
          . "only ALTER TABLE, CREATE [UNIQUE] INDEX, DROP INDEX, "
          . "and RENAME are accepted\n");
        exit(2);
    }
    require_once __DIR__ . '/branched_pdo.php';
    try {
        // BranchedPDO::connect + BootstrapBranchedPDO::ensure is the
        // sanctioned entry point. ensure() runs BranchedPDO::assert_branched
        // so any raw-PDO regression in this path is caught immediately
        // (finding #5 — assert_branched had zero production callers).
        $pdo = BranchedPDO::connect($DB_PATH, $branch);
        BootstrapBranchedPDO::ensure($pdo, $DB_PATH, $branch);
        $pdo->exec((string)$sql);
    } catch (\Throwable $e) {
        fwrite(STDERR, "branchctl: _ddl: " . $e->getMessage() . "\n");
        exit(5);
    }
    break;
}

case 'alter-add-column': {
    // alter-add-column <branch> <table_suffix> <col_name> <col_type>
    //
    // Wraps ALTER TABLE … ADD COLUMN on the branch's underlying physical
    // table (or main's real table) AND triggers cow_recreate_views_for_table
    // so descendant branches' views expose the new column. Used by tests
    // and by tooling that ALTERs WP schemas (plugin upgrades).
    $bname    = $pos[1] ?? die_usage("`alter-add-column` needs <branch> <table_suffix> <col_name> <col_type>");
    $suffix   = $pos[2] ?? die_usage("`alter-add-column` needs <branch> <table_suffix> <col_name> <col_type>");
    $col_name = $pos[3] ?? die_usage("`alter-add-column` needs <branch> <table_suffix> <col_name> <col_type>");
    $col_type = $pos[4] ?? die_usage("`alter-add-column` needs <branch> <table_suffix> <col_name> <col_type>");
    if ($bname !== 'main' && !valid_branch_name($bname)) die_usage("invalid branch: $bname");
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col_name)) die_usage("invalid col name");
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_ \(\),]*$/', $col_type)) die_usage("invalid col type");
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $suffix)) die_usage("invalid suffix");

    $db = sqlite_open($DB_PATH);
    $bid = fs_branch_id($db, $bname);
    if ($bid <= 0) {
        fwrite(STDERR, "branchctl: no branch named '$bname'\n");
        exit(4);
    }
    $logical = "b{$bid}_wp_{$suffix}";
    // Determine the real table to ALTER. For main this is the logical name
    // itself; for a COW branch we ALTER the overlay (not the view).
    $physical = $logical;
    if (cow_is_view($db, $logical)) {
        $physical = $logical . '__overlay';
    }
    if (!cow_is_table($db, $physical)) {
        fwrite(STDERR, "branchctl: no underlying table '$physical' to alter\n");
        exit(5);
    }
    $db->exec('BEGIN IMMEDIATE');
    // SQLite3::exec() returns false on error and sets lastErrorMsg() but
    // doesn't throw unless PDO-style exception-mode is enabled. Check the
    // return value to surface failures (like "duplicate column name").
    $ok = $db->exec('ALTER TABLE "' . SQLite3::escapeString($physical) . '" '
            . 'ADD COLUMN "' . $col_name . '" ' . $col_type);
    if ($ok === false) {
        $err = $db->lastErrorMsg();
        $db->exec('ROLLBACK');
        fwrite(STDERR, "branchctl: alter-add-column failed: $err\n");
        exit(5);
    }
    try {
        // Invalidate descendant branches' views — SELECT * was resolved at
        // the original CREATE VIEW time, so the new column would otherwise
        // be invisible.
        cow_recreate_views_for_table($db, $suffix);
        $db->exec('COMMIT');
    } catch (\Throwable $e) {
        $db->exec('ROLLBACK');
        fwrite(STDERR, "branchctl: alter-add-column failed: " . $e->getMessage() . "\n");
        exit(5);
    }
    echo "branchctl: added column '$col_name' $col_type to b{$bid}_wp_{$suffix} "
       . "(updated dependent branch views)\n";
    break;
}

case 'audit': {
    // TODO3 #12 — `branchctl audit [--since <ts>] [--actor <name>]`.
    //
    // Prints the audit_log table's rows in id-DESC order. Both filter
    // flags are optional. --since accepts any SQLite-parseable datetime
    // literal (e.g. "2026-04-20", "2026-04-20 12:00:00", "-7 days").
    $since = isset($flags['since']) ? (string)$flags['since'] : null;
    $actor = isset($flags['actor']) ? (string)$flags['actor'] : null;
    $db = sqlite_open($DB_PATH);
    $where = [];
    $bind  = [];
    if ($since !== null && $since !== '' && $since !== '1') {
        $where[] = "ts >= datetime(:since)";
        $bind[':since'] = $since;
    }
    if ($actor !== null && $actor !== '' && $actor !== '1') {
        $where[] = "actor = :actor";
        $bind[':actor'] = $actor;
    }
    $clause = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);
    $sql = "SELECT id, ts, actor, action, target, details "
         . "FROM audit_log$clause ORDER BY id DESC LIMIT 500";
    $s = $db->prepare($sql);
    foreach ($bind as $k => $v) $s->bindValue($k, $v, SQLITE3_TEXT);
    $r = $s->execute();
    printf("%-4s  %-19s  %-12s  %-12s  %s\n", "ID", "TS", "ACTOR", "ACTION", "TARGET");
    printf("%s\n", str_repeat('-', 80));
    $count = 0;
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        printf("%-4s  %-19s  %-12s  %-12s  %s\n",
            $row['id'], $row['ts'], $row['actor'], $row['action'],
            $row['target'] ?? '');
        $count++;
    }
    if ($count === 0) echo "(no audit rows)\n";
    break;
}

default:
    die_usage("unknown command: $cmd");
}
exit(0);
?>
__END__

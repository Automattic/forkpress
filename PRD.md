# ForkPress — Product Requirements Document

## Overview

ForkPress is a branch-scoped WordPress preview system. A site is a single
portable `.fp` file. The `forkpress` binary boots a full WordPress stack from
that file; the `branchfs` PHP extension makes the same file work on production
PHP servers without the binary.

---

## Deliverables

### D1 — `forkpress` binary
Single static binary per target, no runtime dependencies.

| Target | Linking |
|--------|---------|
| `x86_64-unknown-linux-musl` | fully static |
| `aarch64-unknown-linux-musl` | fully static |
| `x86_64-apple-darwin` | libSystem only |
| `aarch64-apple-darwin` | libSystem only |

Built via `make dist` (static-php-cli + Rust workspace).

### D2 — `branchfs` PHP extension
C extension (`ext/branchfs.c`) loadable on any standard PHP 8.x installation.
Provides the filesystem virtualisation layer independently of the full binary,
so production sites can install only the extension.

Public API:
```c
branchfs_set_db(string $path): void      // open a .fp file
branchfs_set_branch(string $name): void  // activate a branch
```

Overrides the PHP `file` stream wrapper so all file I/O under the WP root is
served from the SQLite store transparently.

---

## Site file format

### SF1 — Single `.fp` file
The entire site lives in one SQLite file (WAL mode).

| Table group | Contents |
|-------------|----------|
| `blobs`, `blob_chunks`, `branches`, `files`, `fs_commits`, `fs_commit_files` | WordPress filesystem (COW) |
| `b{id}_wp_*` (main): real tables. `b{id}_wp_*` (non-main branches): VIEWS backed by `b{id}_wp_*__overlay` (changed/added rows) and `b{id}_wp_*__tombstones` (deleted PKs). | WordPress database, COW per branch |
| `db_cow_branches`, `db_ancestor_overlay`, `db_post_fork_inserts`, `db_snapshots_schema` | COW branch metadata + lazy fork-time ancestor for 3-way DB merge |
| `db_commits`, `db_commit_overlays`, `db_commit_tombstones`, `db_commit_schema` | Per-commit DB snapshot paired 1:1 with `fs_commits` (rows + tombstones + schema). branchctl commit/rollback/reset operate atomically on both sides. |
| `db_snapshots` | Legacy per-row ancestor (still consulted for branches in pre-COW format; lazy-migrated to COW on first merge) |
| `site_config`, `users` | Site-wide config and authentication |

WAL mode guarantees every committed write is durable even on crash — no
pack/unpack cycle, no temp state.

**Chunked blob storage.** Blobs larger than 1 MB are split across rows in
`blob_chunks(blob_hash, chunk_no, data)`. The `blobs` row keeps
`(hash, NULL, size)` as a metadata pointer, and the payload is streamed
into 1 MB slices. Small blobs (≤ 1 MB) stay inline in `blobs.data` so
reads remain a single query and pre-chunking `.fp` files keep working
unchanged. The chunk size is a compile-time constant
(`BRANCHFS_CHUNK_SIZE` in `ext/branchfs.h`, `CHUNK_SIZE` in
`fileserver/src/store.rs`) and must match across both writer
implementations. `branchctl gc` cleans orphaned rows in both `blobs`
*and* `blob_chunks` in one transaction.

**Inline GC on branch delete.** `branchctl delete <branch>` collects the
set of blob hashes referenced only by the deleted branch's `files` and
`fs_commits` rows and reclaims the orphaned ones in the same transaction
that drops the branch. Cost is bounded by the deleted branch's blob set,
not the whole store, so delete stays O(deleted branch) instead of
O(full blob table). Both `blobs` and `blob_chunks` rows are dropped;
shared blobs (still referenced by a sibling or parent branch) are left
in place. A stdout line `branchfs: reclaimed N orphaned blob(s), M
bytes` appears when N > 0.

### SF2 — Branch isolation
Both the filesystem and the database are branch-isolated.

- **Filesystem**: copy-on-write blobs per branch (existing `files` table overlay).
  Large-file writes use the chunked layout from SF1 so that uploading a
  multi-MB media asset never holds the entire payload in a single SQLite
  row — peak memory stays bounded by the chunk size instead of the file
  size.
- **Database (COW)**: each branch owns its own set of WordPress tables with
  prefix `b{branch_id}_wp_` (e.g. `b2_wp_posts`). For NON-MAIN branches,
  `b{id}_wp_X` is a **VIEW** that UNION-ALLs an overlay (changed/added rows)
  with the parent's view minus tombstones (deleted PKs). INSTEAD OF triggers
  on the view route INSERT/UPDATE/DELETE to overlay/tombstones — WordPress
  and the MySQL proxy both write to the view transparently, no SQL rewrite.
  For MAIN (id=1), `b1_wp_X` remains a real table (no view layer); branches
  inherit from main via the chain.

  Branch creation is therefore **O(num_tables)**, not O(num_rows): a 10k-row
  site forks in milliseconds and grows the `.fp` file by ~12 KB per table
  instead of copying every row. Storage is proportional to the divergent
  rows the branch actually overlays — empty branches are nearly free.

### SF2a — Concurrent-writer resilience
Every PHP writer (branchctl, merge.php, checkpoint.php) opens SQLite with
`busyTimeout(15000)` (15 s), and the long-running critical transactions
in `merge.php` are wrapped in `sqlite_retry_busy()` — an
exponential-backoff retry helper (100 / 500 / 2000 ms, 3 retries) from
`scripts/sqlite_retry.php`. The Rust store does the same via
`Connection::busy_timeout(15s)` plus a `Store::with_busy_retry()`
wrapper, so the SFTP / SMB / MySQL write paths behave identically. Under
concurrent writer load (HTTP + SFTP + MySQL + parallel branchctl) a
transient `SQLITE_BUSY` no longer surfaces as a 500 / failed command.

### SF3 — WAL management
- SQLite is opened with `PRAGMA wal_autocheckpoint = 500` by every writer
  (`init_db.php`, `scripts/branchctl.php` sqlite_open, and
  `fileserver/src/store.rs` Store::open), tighter than the 1000-page
  default, so steady-state WAL growth stays below ~2 MB.
- `fileserver` runs an in-process periodic `PRAGMA wal_checkpoint(TRUNCATE)`
  on a 30-second cadence and one final checkpoint on Ctrl-C / SIGTERM, so
  the on-disk `.fp` is a complete snapshot when the process exits cleanly.
- `scripts/checkpoint.php <site.fp>` runs an explicit TRUNCATE checkpoint
  from the CLI for ops use (shrink after a batch import, verify before
  copying the `.fp` file).
- The `.fp` site file is the primary artifact. While the server is
  running, `.fp-wal` (write-ahead log) and `.fp-shm` (shared memory index)
  accompany it. A `cp site.fp backup.fp` while the server is up requires
  copying all three, or running `scripts/checkpoint.php` first to
  collapse the WAL into the main file.

---

## CLI — `forkpress`

### CLI1 — `forkpress init <site.fp>`
Create a new `.fp` file.

```
forkpress init my-blog.fp [--title "My Blog"] [--root-host localhost]
                          [--admin-password <pw>]
```

Creates schema, seeds `main` branch, inserts `site_config`. Also creates
the default `admin` user with role `admin`. Admin password source:
1. `--admin-password <pw>` CLI flag
2. `FORKPRESS_ADMIN_PASSWORD` env var
3. random 24-char password printed **once** to stdout. There is no way
   to recover this password after the init run — rotate it with
   `forkpress user remove admin && forkpress user add admin <newpw> --role admin`.

### CLI2 — `forkpress start <site.fp>`
Boot the full stack from a `.fp` file.

```
forkpress start my-blog.fp
  [--host 127.0.0.1]  [--port 18080]
  [--sftp-port 2222]  [--smb-port 445]
  [--mysql-port 3306]
  [--workers N]            # PHP HTTP worker count (default: min(8, num_cpus*2))
  [--gc-interval <dur>]    # background branchctl gc cadence; off by default
  [--no-fileserver]
  [--logs ./my-blog.logs]
```

Starts: PHP HTTP server, SFTP server, SMB2 server, MySQL proxy.

Startup banner must print connection strings for all active services and
the active worker count (`PHP workers: N (PHP_CLI_SERVER_WORKERS)`).

`--workers N` controls how many PHP processes the built-in server forks
to handle concurrent HTTP requests. Default is
`min(8, num_cpus::get() * 2)` — capped at 8 because WordPress request
serving is bounded by SQLite write contention long before CPU. Pass
`--workers 1` to force the legacy single-request loop (useful when
attaching a debugger to the PHP process); `PHP_CLI_SERVER_WORKERS` is
left unset in that mode so behaviour is byte-identical to the
pre-multi-worker baseline. The flag is honoured on Linux and macOS;
Windows is not a target (see Non-requirements).

`--gc-interval <dur>` enables a background thread that runs
`branchctl gc` on a recurring cadence while the server is up. The
value is a single-unit duration — one of `<N>s`, `<N>m`, or `<N>h`
(e.g. `--gc-interval 300s`, `--gc-interval 10m`, `--gc-interval 1h`).
Compound forms like `1h30m` are not supported. Omitting the flag (or
passing `0` / an invalid value) leaves background GC disabled; inline
GC on branch delete runs either way. Each tick logs stdout/stderr to
`<work-dir>/logs/gc.log` so the primary PHP and fileserver logs stay
unpolluted.

### CLI3 — `forkpress branch <site.fp> <subcommand>`

| Subcommand | Description |
|------------|-------------|
| `list` | List all branches |
| `create <name> [--from <parent>]` | Create branch (COW filesystem + copy DB tables) |
| `commit <name> [-m "msg"]` | Snapshot current state |
| `log <name> [-n <count>]` | Show commit history |
| `show <name>` | Show branch info |
| `diff <a> <b>` | File diff between two branches |
| `merge <from> --into <target>` | 3-way file + DB merge |
| `reset <name> <hash>` | Reset branch to commit |
| `rollback <name>` | Reset to previous commit |
| `delete <name>` | Delete branch + its DB tables + ancestor snapshot rows. Runs an inline GC step that reclaims blobs uniquely owned by the deleted branch — no waiting for a manual `gc` pass. |
| `gc` | Remove unreferenced blobs across the whole store (shares `fs_gc()` with inline delete-GC). |

### CLI4 — `forkpress backup <source.fp> <dest.fp>`
Consistent hot-copy of a running site via SQLite's online backup API
(`SQLite3::backup`, PHP 8.1+) — TODO3 #14. The online backup copies
pages in small batches and yields the write lock between each batch,
so concurrent writers stall for milliseconds per batch instead of
being blocked for the full copy duration. Falls back to `VACUUM INTO`
on pre-8.1 PHP where the backup method isn't available.

### CLI5 — `forkpress export <source.fp> <output-dir>`
Writes the site to a portable directory tree:
```
<output-dir>/
  manifest.json                     (format_version, branch topology, site_config)
  branches/<name>/files/…           (resolved file tree per branch)
  branches/<name>/db.sql            (SQL dump of b{id}_wp_* tables + indexes)
```
Suitable for long-term archival and format-version migrations.

### CLI6 — `forkpress import <input-dir> <new.fp>`
Reverse of export: runs `init`, recreates branches in topological
order, replays their files through the branchfs stream wrapper, then
applies each branch's `db.sql`. `b{old_id}_wp_` prefixes are rewritten
to the re-assigned `b{new_id}_wp_` on the fly.

### CLI7 — `forkpress user <subcommand>`
Manage authentication users. Forwards to `scripts/user_admin.php`.

| Subcommand | Description |
|------------|-------------|
| `add <user> <pw> [--role admin\|write\|read]` | Insert or update a user. Default role is `write`. |
| `list` | Print username / role / created_at for every user. |
| `remove <user>` | Delete a user row. Deleting the last admin locks out the site until the flag `auth_enabled='0'` is set out-of-band. |
| `verify <user> <pw>` | Exit 0 if the password matches the stored bcrypt hash, 1 otherwise. Used by tests and recovery scripts. |
| `auth-enabled [0\|1]` | Read or write the `site_config.auth_enabled` flag. With no arg, prints the current value. |

Password hashing: bcrypt via PHP's `password_hash(PASSWORD_BCRYPT)`;
also stores `mysql_sha1 = SHA1(SHA1(password))` hex so the MySQL proxy
can run a real `mysql_native_password` handshake without ever holding
the plaintext or a reversible hash.

---

## Feature requirements

### F1 — Multi-branch HTTP serving
- Main site: `http://localhost:<port>/`
- Branch site: `http://<branch>.localhost:<port>/`
- Branch selected by: subdomain > `X-Branch` header > `wp_branch` signed cookie > `?_branch=` query param > `main`
- **Worker concurrency**: PHP's built-in server is launched with
  `PHP_CLI_SERVER_WORKERS=N` so concurrent HTTP requests are handled
  by N independent PHP processes instead of serialised through a
  single accept loop. N is the resolved value of `forkpress start
  --workers` (default `min(8, num_cpus*2)`). Each worker is a separate
  process and opens its own SQLite handle on the `.fp` file; WAL mode
  plus the SF2a busy-retry helpers keep the multi-writer path safe.
  The PHP `branchfs` builtin extension is process-local, so workers
  do not share OPcache bytecode or wrapper state — every worker
  re-hydrates branchfs on the first request it serves.

### F2 — Git access
- `git clone http://host:port/site.git` — exports current branch's file tree
- `git push` — imports files into branch, creates `fs_commits` row
- One git branch = one forkpress branch
- Implemented by `scripts/git_server/server.php` + WordPress php-toolkit
- **Authentication** (see FAUTH): push (`git-receive-pack`) requires
  HTTP Basic credentials verified against the `users` table when
  `site_config.auth_enabled='1'`. Users with role `read` get HTTP 403;
  anyone else gets HTTP 401 with `WWW-Authenticate: Basic realm="BranchFS Git"`.
- **Principal & audit binding**: the verified username is the `Principal`
  (see F12) attached to any `fs_commits` / `audit_log` row produced by
  the push. No caller can override the actor via env var or header —
  `audit_log.actor` is written from the cryptographically verified
  identity, not from `FORKPRESS_ACTOR`.

### F3 — SFTP access
- SFTP server on `:2222` (configurable)
- Path scheme: `/branch-name/path/to/file`
- **Authentication** (see FAUTH): `auth_enabled='1'` sites require a
  username+password that verifies against `users.password_hash` via
  bcrypt. `auth_none` is rejected. Users with role `read` can browse
  and read files but any write / mkdir / remove returns
  `SSH_FX_PERMISSION_DENIED`.
- File close after write → `fs_commits` row with message `sftp: edit <path>`
- **Principal & audit binding**: the authenticated SFTP username is the
  `Principal` (see F12) used when the close-handler writes the
  `fs_commits` / `audit_log` row.

### F4 — SMB2 access
- SMB2 server on `:445` (configurable)
- Share per branch: `\\host\branch-name\`
- **Authentication** (see FAUTH): the current SMB2 implementation does
  not speak NTLMSSP, so per-user credential verification on the wire
  is not implemented. When `auth_enabled='1'`, the server accepts SMB2
  NEGOTIATE (so clients can detect the service) but rejects every
  subsequent command (SESSION_SETUP, TREE_CONNECT, CREATE, …) with
  `STATUS_ACCESS_DENIED`. Sites that need SMB must either keep
  `auth_enabled='0'` or use SFTP / the MySQL proxy instead.
- File close after write → `fs_commits` row
- **Principal & audit binding**: because SMB NTLMSSP isn't implemented,
  SMB paths only run under `auth_enabled='0'` and all audit rows they
  produce are attributed to the synthetic `system` principal (see F12).

### F5 — MySQL access
- MySQL-compatible protocol server on `:3306` (configurable)
- Connect: `mysql -u root -h localhost -P 3306 <branch-name>`
- **Authentication** (see FAUTH): implements the MySQL
  `mysql_native_password` handshake against `users.mysql_sha1` (stored
  as `SHA1(SHA1(password))` hex) when `auth_enabled='1'`. Empty
  passwords are rejected. Users with role `read` can run
  SELECT/SHOW/EXPLAIN/PRAGMA/SET but any INSERT/UPDATE/DELETE/DDL
  returns `ER_ACCESS_DENIED_ERROR`.
- **Principal & audit binding**: the proxy records the authenticated
  username at handshake and, when it shells out to `branchctl _ddl` for
  view-DDL routing, passes the username through as a short-lived HMAC
  token (`FORKPRESS_TOKEN`) so branchctl resolves the same Principal
  (see F12). `_ddl` now also rejects any SQL that isn't a DDL form in
  the allowlist (ALTER TABLE, CREATE [UNIQUE] INDEX, DROP INDEX) —
  closing hostile-review finding #18, where the hidden subcommand
  previously accepted arbitrary SQL on stdin.
- The branch-name is the MySQL "database" in the connection string
- All `b{branch_id}_wp_*` tables for that branch are visible as `wp_*`
  (table name translation: strip the `b{id}_` prefix in responses)
- SELECT, INSERT, UPDATE, DELETE supported
- Writes go directly to SQLite (WAL-safe, no buffering)
- **Identifier rewriting is tokenizer-aware** (`rewrite_wp_prefix` in
  `fileserver/src/mysql_proxy.rs`): `wp_<table>` is rewritten to
  `b{id}_wp_<table>` only when the occurrence is an identifier. String
  literals (`'…'`, `"…"`, including `''` and `\\'` escapes) and SQL
  comments (`-- …`, `/* … */`) are left byte-for-byte unchanged, so role
  slugs, capability keys, and meta-key values (`'wp_capabilities'`,
  `'wp_user_roles'`, `'wp_user_level'`) round-trip intact.

### F6 — Branching with DB isolation (COW)
- `branchctl create my-branch [--from main]`:
  - **Filesystem**: new entry in `branches` table (COW, no file copy needed)
  - **Database**: for each parent table `b{parent_id}_wp_X`, creates a
    view + overlay + tombstone trio under `b{new_id}_wp_X`:
    - `b{new_id}_wp_X__overlay` — empty real table mirroring parent's DDL
      (PK, UNIQUE, NOT NULL, defaults all preserved). Replicates non-PK
      indexes from the parent, with branch-prefixed names.
    - `b{new_id}_wp_X__tombstones` — PK-only table marking inherited rows
      the branch has deleted.
    - `b{new_id}_wp_X` (VIEW) — `SELECT * FROM overlay UNION ALL SELECT *
      FROM <parent's view> WHERE pk NOT IN (overlay) AND pk NOT IN
      (tombstones)`. Composite PKs use row-value tuples
      `(pk1, pk2) NOT IN (SELECT pk1, pk2 FROM overlay)`.
    - INSTEAD OF triggers on the view route writes to overlay/tombstones.
    - `sqlite_sequence` row for the overlay is set to
      `parent_max + (branch_id - 1) * 1_000_000_000` (TODO3 #7). This
      gives each non-main branch its own ~10⁹-wide AUTOINCREMENT band,
      so two siblings forked from the same parent never collide on
      inserted IDs. Main (branch_id=1) keeps its natural sequence so
      on-disk IDs stay small. `--on-id-collision=renumber` handles
      remapping sibling-band IDs back into main's gap at merge time.
  - **Parent-side ancestor capture** (TODO3 #3, O(1) shared scheme):
    ONE BEFORE-UPDATE/DELETE and AFTER-INSERT trigger is installed on
    each parent real table. The trigger body writes exactly once per
    parent write into the shared `db_parent_ancestor` /
    `db_parent_post_fork_inserts` tables, keyed by
    (parent_table_name, row_pk) — independent of how many descendant
    branches exist. Merge fans the ancestor out across descendants at
    merge time (only for branches that actually diverge). Pre-TODO3 the
    trigger body fanned out via a JOIN against `db_cow_branches`,
    producing one INSERT per descendant per parent write; parent
    throughput degraded linearly with sibling-branch count — the exact
    workload pattern cheap branching was meant to encourage.
    Concurrency: the trigger uses `INSERT OR IGNORE`, so the FIRST
    pre-update snapshot wins — concurrent parent writes cannot
    overwrite each other's snapshot of the row-as-of-fork-time.
    **Cluster-A #11 — merge-time filter**: when `merge.php` reads
    `db_parent_ancestor` it JOINs the `branches` table and filters
    by `captured_at >= branches.created_at`. A snapshot whose
    `captured_at` predates the branch's `created_at` was populated
    by a parent write for another (earlier) descendant and must not
    be adopted as this branch's fork-time ancestor — doing so would
    resurrect a row the branch never inherited. The same filter is
    already applied to `db_parent_post_fork_inserts` lookups.
  - **Marker**: `db_cow_branches(branch_id, table_suffix, parent_branch_id,
    parent_table_name, fork_token)` records that the branch is in COW
    format and tracks the parent's table for merge-time ancestor lookup.
  - **Schema snapshot**: `db_snapshots_schema` stores the fork-time DDL
    (rewritten to reference the branch's logical name) so column-level
    schema-merge has a true ancestor.
- **Cost**: O(num_tables × small constant). For a typical WP site with
  ~22 tables, branch create is < 200 ms regardless of row count. A 100k-row
  site grows the `.fp` file by ~95 KB on branch create (vs ~50 MB+ under
  the old row-copy behavior).
- All branches see their own isolated database state — INSTEAD OF triggers
  enforce isolation; UPDATEs on the branch view only touch the branch's
  overlay, never the parent's table.
- **Nested branch chains** (Cluster-A #12 — verified): a grandchild
  branch `b5` whose parent is non-main `b3` gets a view whose
  `UNION ALL` references `b3`'s logical view, not `b1`'s real table.
  `cow_create_branch_table`'s `$parent_table = "b{$parent_id}_wp_…"`
  uses the direct parent, and the INSTEAD OF trigger's cross-layer
  UNIQUE guard walks up through SQLite's view-resolution — so a
  grandchild's guard correctly rejects an overlay INSERT whose
  UNIQUE value matches a row inherited three levels up. Rigorous
  tests in `test_cluster_a.py::TestClusterAF12_NestedBranchChains`
  cover 3- and 4-level precedence and the guard across levels.
- **INSTEAD OF UPDATE semantics** (Cluster-A #1): the UPDATE trigger
  uses an explicit "tombstone-old-then-upsert-new" sequence rather
  than `INSERT OR REPLACE`. Pre-Cluster-A the trigger did
  `INSERT OR REPLACE INTO overlay (…) VALUES (NEW.*)` which silently
  deleted any overlay row whose UNIQUE column collided with NEW —
  SQL users expect `SQLITE_CONSTRAINT_UNIQUE`, not REPLACE, unless
  they wrote `OR REPLACE` themselves. And when `NEW.PK ≠ OLD.PK` the
  old overlay row's tombstone coverage vanished so the parent's
  row at `OLD.PK` re-emerged through the view. Post-Cluster-A the
  trigger body is:
  1. Cross-layer UNIQUE guard (Cluster-A #9 — composite-PK-safe:
     `pk_tuple_p NOT IN (SELECT pk_tuple FROM tombstones/overlay)`
     rather than the single-column `$pk_cols[0]` form. Pre-Cluster-A
     a tombstone for composite PK `(1, 20)` made the guard skip
     `(1, 10)` too because it filtered only on col 0, admitting a
     UNIQUE collision for any row whose u-value matched the
     inherited `(1, 10)`).
  2. If `NEW.PK ≠ OLD.PK`: `INSERT OR IGNORE` a tombstone for OLD.PK
     and DELETE the old overlay row at OLD.PK.
  3. DELETE any tombstone at NEW.PK.
  4. `INSERT INTO overlay (…) VALUES (NEW.*) ON CONFLICT(pk)
     DO UPDATE SET …` — a PK conflict at NEW (same-PK update) runs
     the UPDATE branch; UNIQUE violations on non-PK columns fire
     natively as `SQLITE_CONSTRAINT_UNIQUE`.
- `router.php` sets `$GLOBALS['_branchfs_table_prefix'] = "b{id}_wp_"` before
  WordPress boots so HTTP requests use the correct branch's tables (the
  view layer is transparent to WordPress and the MySQL proxy).
- **Schema-change propagation** (TODO3 #6 wrap-in-transaction): when a
  `branchctl alter-add-column` (or any tooling that goes through the
  COW helpers) changes a parent table's shape, every descendant
  branch's view is dropped and recreated so `SELECT *` resolves the
  new column set. The multi-branch loop runs inside
  `BEGIN IMMEDIATE … COMMIT`; any per-branch failure rolls back the
  whole batch and surfaces the failing branch id.
- **Transparent DDL routing through COW views**
  (`scripts/branched_pdo.php`): user PHP code that runs raw SQL via
  `BranchedPDO::connect($site_fp, $branch)` instead of `new PDO(...)`
  gets transparent DDL re-targeting. SQLite forbids `ALTER TABLE` /
  `CREATE INDEX` / `DROP INDEX` on a view, so a DDL whose target is
  `b{id}_wp_X` (a view on a non-main branch) is intercepted, rewritten
  to operate on the underlying `b{id}_wp_X__overlay`, and the view +
  INSTEAD OF triggers are rebuilt so the new column set is reflected.
  Supported DDL forms:
  - `ALTER TABLE … ADD COLUMN <name> <type> [DEFAULT …]`
  - `ALTER TABLE … DROP COLUMN <name>` (SQLite ≥3.35; native)
  - `ALTER TABLE … RENAME COLUMN <old> TO <new>`
  - `ALTER TABLE … RENAME TO …` is rejected with a clear error
    (renaming a logical WP table would orphan the COW marker)
  - `CREATE [UNIQUE] INDEX <name> ON <branch_view>(…)` →
    re-targeted at the overlay
  - `DROP INDEX <name>` — overlay-owned indexes drop cleanly; the
    wrapper rejects an attempt to drop an index owned by another
    branch with a descriptive error
  Non-DDL (SELECT/INSERT/UPDATE/DELETE/transactions/prepared
  statements) flows through unchanged. `BranchedPDO extends PDO`, so
  every typed dependency that expects a `\PDO` (including
  `WP_SQLite_Connection`'s `pdo` constructor option) accepts a
  BranchedPDO without modification. The single one-line shim —
  replacing `new PDO('sqlite:'.$path)` with
  `BranchedPDO::connect($path, $branch)` — is the only change required
  in user code. To use BranchedPDO with the WordPress
  sqlite-database-integration plugin, instantiate via:
  ```php
  $bpdo = BranchedPDO::connect($site_fp, $branch);
  $conn = new WP_SQLite_Connection(['pdo' => $bpdo]);
  ```
  The plugin's query translator emits `ALTER TABLE wp_*` against
  branch-prefixed names (after WordPress's wp_ prefix gets rewritten
  to b{id}_wp_), and BranchedPDO transparently routes those to the
  overlay.

  **Raw-PDO guard** (TODO3 #8): a caller that forgets to use
  `BranchedPDO::connect()` and instantiates `new PDO('sqlite:…fp')`
  directly gets silent misses on DDL interception. Bootstraps should
  add a one-line check:
  ```php
  BranchedPDO::assert_branched($pdo, $site_fp);
  ```
  With `FORKPRESS_STRICT_PDO=1` in the environment, the helper throws
  `RuntimeException` on a raw PDO; otherwise it `error_log()`s a
  diagnostic so operators can find the offending code and fix it.

- **Transparent DDL routing over the MySQL wire protocol**
  (`fileserver/src/mysql_proxy.rs`): out-of-band SQL clients (wp-cli
  `db query`, the `mysql` command-line client, phpMyAdmin, any JDBC/
  MySQL Connector driver pointed at the proxy) get the same DDL
  re-targeting BranchedPDO gives in-process PHP callers. The proxy
  classifies every incoming statement with `detect_view_ddl_target`;
  when the target is a COW branch view, the SQL is passed on stdin to
  `branchctl _ddl --branch <name>` (a thin wrapper that uses
  `BranchedPDO::connect`). That keeps ONE source-of-truth for DDL
  handling: the Rust side only answers "is this DDL and what's the
  target?", while the overlay re-targeting + view/trigger rebuild
  lives exclusively in `branched_pdo.php` / `cow_helpers.php`.

### F6a — Lazy migration of legacy (pre-COW) branches
Branches in older `.fp` files use the original row-copy format
(real tables under `b{id}_wp_X`, with row data captured into
`db_snapshots`). On the first `branchctl merge` involving such a branch,
`branchctl` detects the legacy format (a real table where a view would
now be) and migrates it in-place:
- For each `b{id}_wp_X` real table, compute the diff vs the parent's
  current state.
- Drop the real table; create overlay + tombstone + view + triggers.
- Bulk-insert into overlay every row whose value differs from the
  parent's same-PK row (the branch's modifications + additions).
- Bulk-insert into tombstones every parent PK absent from the branch
  (the branch's deletions).
- Continue with the normal merge flow.
After migration, the branch is indistinguishable from a brand-new COW
branch and benefits from the same storage / fork-time guarantees on
future operations.

**On-demand migration** (TODO3 #4):
- `branchctl migrate <name>` — migrate one legacy branch in place.
- `branchctl migrate --all`  — scan every branch and migrate any that
  are still in legacy format.
- `branchctl commit <name>` — auto-migrates before writing the
  snapshot so branches that commit but never merge still upgrade
  eventually, with no extra step from the operator.

All three paths share `cow_migrate_legacy_branch`, so correctness is
inherited from the original merge-time code.

### F7 — Committing (files + DB, atomic)
- `branchctl commit <branch>` records a snapshot of BOTH:
  - **Files** (TODO3 #5, delta-encoded, mirrors the DB side below):
    `fs_commits` rows carry `kind='FULL'` (initial snapshot — every
    path as `op='UPSERT'`) or `kind='DELTA'` (only paths that changed
    since the previous commit; `op='UPSERT'` for added/changed,
    `op='DELETE'` for removed). A 3k-path WP tree committed 100 times
    with 5 changed files each costs ~3030 rows pre-TODO3 and ~530 now
    (30 seed + 100×5). Readers materialize a commit's full tree via
    `fs_materialize_commit_tree()` in `scripts/fs_commit_helpers.php`
    (shared between branchctl, merge, and the git server).
  - **DB** (TODO3 #2, delta-encoded): `db_commits` + `db_commit_overlays`
    + `db_commit_tombstones` + `db_commit_schema`. Each `db_commits` row
    is marked `kind='FULL'` (complete snapshot of the branch's overlay
    rows, tombstones, and DDL) or `kind='DELTA'` (only rows that changed
    since the previous commit on this branch; `op='UPSERT'` encodes
    added/changed rows, `op='DELETE'` encodes rows removed). The first
    commit on a branch is always FULL; every subsequent commit is DELTA.
    Cost per commit is O(rows changed since prev commit), not
    O(total divergent rows) — 100 commits modifying the same 50 rows
    costs ~50 (FULL base) + 100×per-commit-delta stored rows instead
    of 100×50.
  - Restore / rollback / reset walk the commit chain: find the FULL
    base ≤ target, apply each subsequent DELTA up to the target commit,
    then replay the materialized state into the branch's overlay /
    tombstones / schema.
- Both sides are written in a single `BEGIN IMMEDIATE … COMMIT` — a
  failure on either rolls back the other. The two commits share the
  same `commit_hash` and are linked via `db_commits.fs_commit_id`.
- `commit_hash` is a 32-hex-char random ID generated in PHP.
- A "no-op commit" is detected on BOTH sides: if neither file tree nor
  DB state diverges from the last paired snapshot, the command exits
  silently rather than padding the commit graph.

### F8 — Rollback / Reset (files + DB, atomic)
- `branchctl rollback <branch>` and `branchctl reset <branch> <hash>`
  restore BOTH:
  - **Files**: replay `fs_commit_files` into the branch's `files` table
    (existing behaviour).
  - **DB**: drop and recreate every overlay listed in
    `db_commit_schema` from its snapshotted DDL, recreate dependent
    indexes from `indexes_json`, drop and recreate the matching
    `__tombstones` table from the overlay's PK, replay overlay rows
    from `db_commit_overlays`, replay tombstone PKs from
    `db_commit_tombstones`, then rebuild the view + INSTEAD OF
    triggers via `db_rebuild_view_for()` so SELECT through the view
    matches the snapshotted shape.
- Each side runs in its own atomic transaction (the file restore
  reuses the existing `fs_restore_snapshot` BEGIN/COMMIT, then the DB
  restore takes its own BEGIN/COMMIT).
- `--force` controls BOTH guards: without it, the command refuses if
  either the file tree OR the DB state has uncommitted changes since
  the last paired commit. The DB-uncommitted check hashes the current
  overlay+tombstone+schema state (`db_state_digest`) and compares to
  the digest of the last `db_commit` for the branch.
- DB-side restore on a `fs_commit` that pre-dates `db_commits` (legacy
  sites upgraded in place) is a no-op — only the file side rolls back,
  matching the pre-versioning behaviour.

### F9 — Merge
`branchctl merge <from> --into <target> [--strategy=abort|ours|theirs] [--on-id-collision=conflict|renumber]`

**Phase 1 — File 3-way merge** (existing)
Uses the fork-time `fs_commits` snapshot as the common ancestor. Per-path:
- Source changed, target unchanged → apply source
- Target changed, source unchanged → keep target
- Both changed identically → no-op
- Both changed differently → conflict (honour `--strategy`)

**Phase 2 — DB 3-way merge** (implemented; COW-aware)
Ancestor model is **diff-based** under COW: instead of pre-snapshotting every
row at fork time (legacy `db_snapshots` semantics), the merge reconstructs
a fork-time row value lazily, only for rows the branch actually overlaid
or tombstoned.

Lookup order for ancestor row at PK X on a COW branch:
1. **`db_ancestor_overlay`** — populated by parent-side BEFORE-UPDATE/DELETE
   triggers. If parent independently mutated the row before the branch
   touched it, the OLD value was captured here at parent-mutation time.
   This is the *exact* fork-time value.
2. **`db_post_fork_inserts`** — populated by parent-side AFTER-INSERT
   triggers. If the PK is in this set, ancestor is "absent" (parent
   inserted the row after fork). Treating it as absent makes the merge
   correctly classify "both sides inserted same PK independently" as a
   conflict instead of falsely "source modified target's row".
3. **Parent's CURRENT row at PK X** — fallback. Exact when the parent
   hasn't independently mutated this row since fork (the common case).
   When stale, the merge degrades gracefully: the worst case is a
   spurious clean apply where a conflict should fire, mitigated in
   practice by (1) and (2) above.

For legacy non-COW branches, `db_snapshots` is consulted as before.

Per row (keyed by primary key JSON):

| Ancestor | Source now | Target now | Result |
|----------|------------|------------|--------|
| absent | present | absent | INSERT into target |
| absent | present | present, same | no-op |
| absent | present | present, different | CONFLICT |
| present | absent | unchanged | DELETE from target |
| present | absent | changed | CONFLICT |
| present | changed | unchanged | UPDATE target |
| present | unchanged | changed | keep target |
| present | changed same | changed same | no-op |
| present | changed A | changed B | CONFLICT |

New tables on source (e.g. new plugin) are created on target with DDL-preserving
`CREATE TABLE` + `INSERT`. All changes are applied atomically in one transaction.
`--strategy=abort` leaves the target completely unchanged on any conflict.

**Phase 2b — Column-level schema merge** (implemented)
For tables present on BOTH source and target, the merge runs a column-level
3-way diff alongside the row-level diff. The fork-time DDL + index list is
recorded per branch in `db_snapshots_schema(branch_id, table_name, ddl_sql,
indexes_json)` at `branchctl create` time. Branches that pre-date this table
fall back to "source's CURRENT schema is the ancestor" (i.e. assume no schema
change occurred on source); on the first merge against such a branch,
`db_snapshots_schema` is opportunistically backfilled from the live schema.

**Per-column 3-way table** (matched by column name):

| Ancestor | Source now | Target now | Result |
|----------|------------|------------|--------|
| absent | present | absent | `ALTER TABLE target ADD COLUMN …` (preserves type, NOT NULL, DEFAULT) |
| absent | present | present, identical | no-op |
| absent | present | present, different shape | CONFLICT |
| present | absent | unchanged | `ALTER TABLE target DROP COLUMN …` (3.35+ native, fallback to rebuild) |
| present | absent | modified | CONFLICT |
| present | present, type T1 | present, type T2 | rebuild target with source's column definition |
| present | present | present | no-op |

**Per-index 3-way** (matched by normalized DDL — branch-prefix-independent):
- Source added the index, target doesn't have it → `CREATE INDEX` on target,
  with the index name rewritten to use target's `b{tgt_id}_wp_` prefix.
- Source dropped it, target still has it → `DROP INDEX` from target.
- Both have / both lack → no-op. Conflicting structures honor `--strategy`.

Schema ops are sorted to run BEFORE row-level ops in the same transaction
(`drop_index → drop_column → modify_column → add_column → add_index → row
upserts/deletes`), so a source-side INSERT that uses a brand-new column finds
that column already on target. The whole merge — schema + rows + ancestor
snapshot refresh — is one atomic `BEGIN IMMEDIATE … COMMIT`. After a
successful merge, `db_snapshots_schema` for the source branch is replaced in
the same transaction so iterative merges don't re-propose already-applied
schema changes.

**Auto-increment ID collision renumbering (`--on-id-collision`)**

Default `conflict` keeps the historical behaviour: if both source and target
independently inserted a new row with the same auto-increment PK after the
fork, the merge reports a CONFLICT. Resolving it via `--strategy=ours` or
`--strategy=theirs` would lose one side's data.

`--on-id-collision=renumber` resolves these conflicts non-destructively for
the standard WordPress tables: source's colliding row is upserted into target
with a fresh PK that exceeds `max(MAX(source.pk), MAX(target.pk))`, and any
hard-coded foreign-key column on a source-origin row in the same merge that
referenced the old PK is rewritten to the new PK before being applied. The
rewrite map (PK-owning table → list of (FK table, FK column)):

| PK-owning table | Foreign-key references |
|------------------|------------------------|
| `wp_posts.ID`            | `wp_postmeta.post_id`, `wp_comments.comment_post_ID`, `wp_term_relationships.object_id`, `wp_posts.post_parent` |
| `wp_users.ID`            | `wp_usermeta.user_id`, `wp_posts.post_author`, `wp_comments.user_id` |
| `wp_comments.comment_ID` | `wp_commentmeta.comment_id` |
| `wp_terms.term_id`       | `wp_term_taxonomy.term_id` |
| `wp_term_taxonomy.term_taxonomy_id` | `wp_term_relationships.term_taxonomy_id` |

Eligibility is strict — a collision is only auto-renumbered when ALL of the
following hold:

1. The colliding column is the table's single-column INTEGER PRIMARY KEY
   AUTOINCREMENT (detected via `sqlite_master.sql LIKE '%AUTOINCREMENT%'`,
   with a single-INTEGER-PK fallback).
2. The PK-owning table's bare suffix (e.g. `posts`, `users`) is in the
   rewrite map above.
3. The collision is genuinely "both inserted independently after fork" —
   the ancestor snapshot has no row at this PK, source and target both have
   distinct rows at this PK.

Unrelated value conflicts (e.g. two different `option_value` for the same
`option_name`) and PK collisions on tables outside the rewrite map (e.g.
`wp_options.option_id`, plugin-defined custom tables) still produce CONFLICT
even when `--on-id-collision=renumber` is passed.

The renumber + FK rewrite + ancestor snapshot refresh all run in the same
`sqlite_retry_busy` transaction as the existing apply loop, so a renumbered
post and its rewritten meta become visible atomically. Each renumber is
listed in the merge summary output:

```
ID collision renumbers (1):
  b1_wp_posts.ID                       42 -> 157
```

**Ancestor snapshot refresh (iterative merges)**

For LEGACY (non-COW) branches: after a successful merge the source branch's
`db_snapshots` rows are replaced in the same transaction with a fresh
snapshot of the source branch's current tables.

For COW branches: after a successful merge, for each PK that was upserted
from source to target, `db_ancestor_overlay` is updated to source's
CURRENT row at that PK. This makes the next merge's ancestor lookup
return the just-merged value, so re-merging without further changes is a
clean noop instead of a spurious "both sides changed" conflict.
Additionally, conflicts resolved via `--strategy=ours` (target kept its
value) get the same ancestor refresh: source's CURRENT value is recorded
so the next merge sees `s == a → noop`, preserving the user's "ours"
decision across iterative merges. Merges that exit via `--strategy=abort`
on a real conflict do not touch any ancestor table.

### F9a — OPcache invalidation for out-of-process writers
Router serves PHP via `branchfs://<branch>/path.php` URLs so OPcache keys
bytecode per-branch. Any writer that mutates a `.php` file on disk from a
*different* PHP process (branchctl merge/reset/rollback) must communicate
the change to the running PHP server, otherwise stale bytecode keeps
serving the old code until OPcache TTL expires.

Implementation (`scripts/opcache.php`):
- Table `opcache_invalidations(id, url, created_at)` is a cross-process
  queue inside the `.fp` file.
- Writers (merge.php, branchctl reset/rollback) call
  `opcache_queue_invalidate($db, $branch, $path)` inside the same
  transaction as the file change. Non-`.php`/`.phtml` paths are skipped
  because they never have OPcache entries.
- Router (`e2e/router.php`) calls `opcache_process_pending($db)` at the
  start of every request, pops every queued URL, and calls
  `opcache_invalidate()` for each. Safe when OPcache is not loaded — the
  queue is still drained, just without the actual invalidation call.

### FAUTH — Authentication for write surfaces
Every non-HTTP write surface (SFTP, SMB, MySQL proxy, git push) honours
a per-site auth gate backed by two new SQLite tables:

- `users(username TEXT PRIMARY KEY, password_hash TEXT, mysql_sha1 TEXT,
  role TEXT CHECK(role IN ('admin','write','read')), created_at TEXT)`
- `site_config(key TEXT PRIMARY KEY, value TEXT)` — auth is on when
  `auth_enabled='1'`.

Role semantics:

| Role | HTTP preview | SFTP read | SFTP write | MySQL SELECT | MySQL DML | SMB | git clone | git push |
|------|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| `admin`/`write` | open | yes | yes | yes | yes | if `auth_enabled='0'` | yes | yes |
| `read` | open | yes | **403** | yes | **403** | if `auth_enabled='0'` | yes | **403** |
| unauthenticated (when `auth_enabled='1'`) | open | **reject** | — | **reject** | — | **reject** | open | **401** |

Back-compat:
- **new sites** created via `forkpress init` / `scripts/init_db.php`
  default to `auth_enabled='1'` and ship with an `admin` user whose
  one-time password is printed during init.
- **pre-change sites** (the `.fp` was created before this feature) have
  no `site_config` row at all; the first `fs_migrate()` on them seeds
  `auth_enabled='0'` so existing workflows don't break. Operators opt
  into auth by running `forkpress user add admin <pw> --role admin &&
  forkpress user auth-enabled 1`.

Password recovery is intentionally manual — there is no email loop, no
reset token. Operators rotate credentials with
`forkpress user remove <name> && forkpress user add <name> <newpw>`.

### F12 — Audit log (principal-bound, tamper-resistant)

Every state-mutating branchctl command (create, commit, delete, merge,
rollback, reset, migrate, `_ddl`) writes one row into `audit_log`:

```
audit_log(id, ts, actor, action, target, details)
```

**Principal binding.** `audit_log.actor` is sourced from a resolved
`Principal` (see `scripts/principal.php`), **not** from
`FORKPRESS_ACTOR`. The resolver runs once at CLI startup and
registers itself on `audit_helpers.php` so every subsequent
`audit_log_write` call uses the same identity:

- `auth_enabled='1'`: the CLI accepts `--user <u> --password <p>` OR
  `FORKPRESS_TOKEN=<signed>`. Credentials are verified against
  `users.password_hash` (bcrypt) or the HMAC-signed token. The env var
  `FORKPRESS_ACTOR` is **ignored** — setting it does nothing.
- `auth_enabled='0'` (legacy): no credentials required. The principal
  is a synthetic `system` role; `FORKPRESS_ACTOR` is honoured as a
  display-only hint for audit attribution but confers no security.

**Token format.** A FORKPRESS_TOKEN is
`base64url("<username>.<exp_unix>.<hex_sha256_hmac>")`. The HMAC key
is stored in `site_config['hmac_secret']` (32 bytes, hex-encoded) and
auto-generated on first use. The secret is never logged. The MySQL
proxy mints 60-second tokens via `Store::mint_principal_token()` when
it shells out to `branchctl _ddl`.

**Tamper resistance.** `audit_log` carries two SQLite triggers:

```sql
CREATE TRIGGER audit_log_no_update
BEFORE UPDATE ON audit_log
BEGIN SELECT RAISE(ABORT, 'audit_log is append-only (tamper-resistant)'); END;
CREATE TRIGGER audit_log_no_delete
BEFORE DELETE ON audit_log
BEGIN SELECT RAISE(ABORT, 'audit_log is append-only (tamper-resistant)'); END;
```

So a caller who runs `DELETE FROM audit_log` or `UPDATE audit_log SET
actor='victim'` — whether via sqlite3 shell, the MySQL proxy, or a
PHP caller — is stopped with a `SQLITE_CONSTRAINT`-style abort. There
is one SQLite-scope limitation: a caller who can `DROP TABLE
audit_log` or physically replace the `.fp` file has escaped every
constraint SQLite can enforce. Operators who need stronger guarantees
should mount the `.fp` read-only for untrusted consumers. See
LIMITATIONS.md.

**Failure semantics.** `audit_log_write` failures are **not silent**.
If the INSERT fails for any reason (e.g. a broken schema from
administrative corruption), the helper writes a diagnostic to STDERR
and the CLI exits non-zero — closing hostile-review finding #2 where
failures were swallowed.

**Chokepoints.**
- `scripts/principal.php` — `Principal` class, token mint/verify,
  `principal_resolve()`.
- `scripts/audit_helpers.php` — `audit_log_set_principal()` and
  `audit_log_write()` with loud failure.
- `scripts/branched_pdo.php` — `BootstrapBranchedPDO::ensure()` is the
  sanctioned chokepoint wired into `branchctl _ddl` (the sole
  PDO-backed DDL path today, finding #5). Other DDL surfaces use
  SQLite3 directly rather than PDO and don't flow through ensure().
  Principal + audit binding happens at the scripts/audit_helpers.php
  layer, not through a PDO-wrapping facade.
- `scripts/branchctl.php` — resolves a principal once up front,
  rejects writers when auth is enabled and no credentials are
  supplied, and gates `_ddl` SQL through a DDL allowlist.

---

## Non-requirements (explicit out of scope for v1)

- (Removed — username+password+role auth implemented for SFTP/SMB/MySQL/git; see FAUTH)
- OAuth / SSO / TOTP / passwordless authentication
- TLS / HTTPS
- Multi-user concurrent editing with conflict resolution
- Windows binary target — implies `PHP_CLI_SERVER_WORKERS` is unavailable;
  multi-worker HTTP serving is therefore Linux/macOS only
- (Removed — single-process PHP serving replaced by multi-worker mode in F1 / CLI2)
- (Removed — now implemented via ancestor snapshot refresh in F9)
- (Removed — DB merge for schema changes is now implemented via Phase 2b in F9)
- **Cross-layer UNIQUE constraints.** The branch overlay's UNIQUE constraint
  catches duplicates among OTHER overlay rows, but does not know about
  rows inherited from the parent. Inserting a value that already exists in
  the parent (via inheritance) through the branch view will succeed, then
  the branch view will return both rows (the parent's and the overlay's).
  Enforcing this would require an INSTEAD OF INSERT pre-check querying the
  parent view per-insert. WordPress core relies on option_name UNIQUE; in
  practice an UPDATE-then-check pattern is used (update_option() which does
  a SELECT first). See
  `e2e/test_rigorous_ddl.py::TestUniqueViolation::test_cross_layer_unique_violation_rejected`
  (xfail).
- **Tables without an explicit PRIMARY KEY have limited DELETE semantics.**
  For a no-PK `b1_wp_X` table, the branch view is built without a tombstone
  filter (the full-row-identity filter across UNION ALL produces ambiguity
  with duplicates and is nontrivial to implement correctly). DELETE of a
  branch-only overlay row works; DELETE of an inherited parent row writes
  a tombstone but the branch view still shows the parent row. WordPress
  core has PKs on every table so this does not affect real usage. See
  `e2e/test_rigorous_pk.py::TestNoExplicitPK::test_no_pk_delete_hidden_via_tombstone`
  (xfail with this explanation).
- **Autoincrement PK collision when an ancestor mutates after a descendant is created.**
  `sqlite_sequence` is seeded per-overlay at fork time. If parent `a` inserts a
  new autoincrement row AFTER child branch `b` has been created, and child `b`
  independently inserts a row, both can pick the same next-id and `b`'s view
  then hides the parent's row (its PK is filtered by `WHERE pk NOT IN (SELECT pk
  FROM overlay)`). Realistic chain-of-previews usage (create, modify, fork child,
  only then mutate child) avoids this. Sibling branches with independent writes
  to autoincrement tables, and merges between them, use the existing
  `--on-id-collision=renumber` path. See
  `e2e/test_rigorous_scale.py::TestDeepInheritance::test_sibling_ancestor_autoincrement_collision_is_documented`.

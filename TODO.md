# ForkPress — Outstanding Issues

Prioritized list of gaps identified during system review. Work through in
priority order. Each item must be resolved with:

1. **Code fix** — address the root cause, not symptoms
2. **E2E test** — simulates the realistic user workflow that would hit the bug,
   not a narrow unit test. Adds to `e2e/test_invariants.py`, `e2e/test_db_merge.py`,
   or a new `e2e/test_<area>.py`. Python/pytest preferred.
3. **PRD update** — reflect the new behavior in `PRD.md` (F-requirements or
   non-requirements section)
4. **Commit** — one coherent commit per TODO with a clear message
5. **Mark done** — flip `[ ]` to `[x]` in this file

Running tests:
```
PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/ -v -m "not live" 2>&1
```

All existing tests must continue to pass.

---

## [x] 1. MySQL proxy: string literals are corrupted by `wp_` rewriting [CRITICAL]

**Problem**
`fileserver/src/mysql_proxy.rs` (or the equivalent Rust file doing the
rewrite) transforms every query with `sql.replace("wp_", prefix)`. This hits
inside single- and double-quoted string literals too. So

```
UPDATE wp_options SET option_value='wp_capabilities' WHERE option_name='a:1:{...}'
```

becomes

```
UPDATE b1_wp_options SET option_value='b1_wp_capabilities' WHERE option_name='a:1:{...}'
```

— the string literal `'wp_capabilities'` is silently mangled.

**Why it matters**
WordPress stores role slugs (`wp_user_roles`), capability keys (`wp_capabilities`),
and user-meta keys (`wp_user_level`) as bare string values in the DB. Every
admin action that updates these corrupts the data. After one page-load,
`SELECT ... WHERE meta_key='wp_capabilities'` returns zero rows because the
stored value is `'b1_wp_capabilities'`, stripping every user of their
permissions. Silent, nearly impossible to debug from the WP side.

**Acceptance**
- String literals (`'...'` AND `"..."`) are never rewritten
- Identifier references `wp_<table>` used in a FROM/JOIN/UPDATE/INSERT INTO
  position ARE rewritten to `b{id}_wp_<table>`
- Escaped quotes inside strings (`'it''s'`, `'a\'b'`) do not confuse the tokenizer
- SQL comments (`-- foo`, `/* foo */`) are not rewritten
- The three `@pytest.mark.live` tests in `test_invariants.py` pass:
  - `test_string_literal_with_wp_prefix_not_rewritten`
  - `test_usermeta_meta_key_values_not_rewritten_on_read`
  - `test_where_clause_string_not_rewritten`
- Add a round-trip E2E: `mysql_query("main", "INSERT INTO wp_options(option_name, option_value) VALUES ('x', 'wp_capabilities')")`
  then `SELECT option_value FROM wp_options WHERE option_name='x'` must return
  exactly `wp_capabilities` byte-for-byte

**Approach (non-prescriptive)**
A proper SQL tokenizer (sqlparser-rs or a small hand-written one for this
narrow dialect). Don't try to extend the regex hack — it will never be correct.

---

## [x] 2. Merge: ancestor drift on re-merge [CRITICAL]

**Problem**
`db_snapshots` is populated once at `branchctl create` time and never updated.
After `merge feature --into main`, the snapshot for `feature` still reflects
the *original* fork point. If the user:

1. Merges `feature` → `main` (clean)
2. Modifies `feature` further
3. Merges `feature` → `main` again

— the second merge's 3-way diff uses the stale snapshot as ancestor. Every
row that was touched by merge 1 now looks like "source changed vs ancestor"
AND "target changed vs ancestor" (because main received those changes in the
first merge). Result: false conflicts for every row previously merged.

Same issue if the user does `branchctl merge main --into feature` to pull
from main: the snapshot doesn't track that feature now contains main's state.

**Why it matters**
Iterative feature work ("merge, review, tweak, merge again") is the basic
workflow. Without snapshot refresh, users can only merge a branch exactly
once before they start fighting false conflicts.

**Acceptance**
- After a successful merge, the *source* branch's `db_snapshots` is
  refreshed to reflect its current state (the state main now has)
- Re-merging the same branch twice with no new changes is a clean no-op,
  not a conflict
- Re-merging after modifying feature only touches the newly-changed rows
- Merging main → feature (reverse direction) also refreshes the correct
  snapshot
- E2E test: full iterative loop (fork → merge → modify → merge → verify)

**Approach**
After successful merge, re-snapshot the source branch: delete existing
`db_snapshots WHERE branch_id = source_id`, then re-insert for every row in
every `b{source_id}_wp_*` table. Do this in the same transaction as the
merge so either both happen or neither.

Edge case: if user uses `--strategy=ours`, source's snapshot should NOT be
refreshed because source's data wasn't adopted. Think through what "ancestor"
should mean in each strategy:
- `abort` (default): no merge happened, no refresh
- `theirs`: source wins on conflicts, source state was adopted → refresh
- `ours`: target won on conflicts, but source's *clean* changes were applied →
  source's new ancestor should be the post-merge state of target

---

## [x] 3. OPcache: stale bytecode after file merge [HIGH]

**Problem**
`router.php` serves PHP via `branchfs://<branch>/path.php` URLs so OPcache
keys bytecode per-branch (per line 18-22 of `e2e/router.php`). When
`branchctl merge feature --into main` writes new content for `theme.php` on
main, the OPcache entry for `branchfs://main/theme.php` still holds the old
compiled bytecode.

**Why it matters**
After a merge, requests to main serve the *pre-merge* PHP code until:
- OPcache's TTL expires (default `opcache.revalidate_freq=2s`, but
  `opcache.validate_timestamps` must also check mtime — and branchfs mtime
  may not change via merge)
- The server is restarted

Users see "I merged the change but it's not live" with no visible diagnostic.

**Acceptance**
- After a successful merge, every `.php` path written to target has
  `opcache_invalidate()` called for its `branchfs://<target>/<path>` URL
- E2E test: on the server, modify a PHP file on a branch, merge, hit the
  merged URL, verify new content is served without any server restart
- Bonus: also invalidate on SFTP upload, git push, and `branchctl reset`,
  since all three can modify PHP files

**Approach**
Add a helper in `scripts/merge.php` (or a new `scripts/opcache.php`) that
collects changed paths and invokes `opcache_invalidate` via an in-process
call from the webserver. Since merge.php is typically called out-of-process
from branchctl, this needs a way to signal the running PHP server. Options:
1. Touch a sentinel file that router.php checks on next request and
   invalidates the relevant paths
2. Extend branchfs.so to hook into blob writes and invalidate automatically
3. Add an HTTP endpoint that the merge command calls to trigger invalidation

---

## [x] 4. db_snapshots never cleaned on branch delete [HIGH]

**Problem**
`branchctl delete <branch>`:
- Drops `b{id}_wp_*` tables ✅
- Deletes rows from `files` ✅
- Deletes row from `branches` ✅
- Does NOT delete rows from `db_snapshots` ❌

Rows accumulate forever.

**Why it matters**
On sites with high branch churn (CI previews, per-PR branches), the
`db_snapshots` table grows without bound. A site with 1000 branch-deletes
and ~50 rows per table × ~5 tables would carry ~250k dead ancestor rows.
Not catastrophic, but definitely leaky.

**Acceptance**
- `branchctl delete` also `DELETE FROM db_snapshots WHERE branch_id = :bid`
- E2E test: create branch, verify snapshot rows exist for its id, delete,
  verify snapshot rows gone

**Approach**
One-line fix in `scripts/branchctl.php` delete case, after dropping tables
and before deleting the `branches` row.

---

## [x] 5. WAL file can grow without bound [MEDIUM]

**Problem**
SQLite WAL mode is enabled. By default, SQLite auto-checkpoints every 1000
pages, but under high write traffic the WAL can still grow to many MB before
a checkpoint fires. On crash recovery, the entire WAL is replayed.

**Why it matters**
- Long-running forkpress instances can accumulate large `.fp-wal` files
- Recovery-time after a crash scales with WAL size
- Users moving/copying the `.fp` file while the server runs must also copy
  `.fp-wal` and `.fp-shm` or lose the un-checkpointed data

**Acceptance**
- `forkpress start` runs an explicit `PRAGMA wal_checkpoint(TRUNCATE)` on
  shutdown (clean exit signal)
- `forkpress` has a periodic checkpoint (every N writes or N seconds)
- E2E test: simulate heavy writes, verify WAL does not grow beyond a
  configured cap (e.g., 16MB)

**Approach**
- On forkpress shutdown: run `PRAGMA wal_checkpoint(TRUNCATE)` against the
  site DB
- Either periodic checkpointing in forkpress (every ~30s) or relying on
  SQLite's auto-checkpoint (tune `PRAGMA wal_autocheckpoint`)
- Document the `.fp` + `.fp-wal` + `.fp-shm` triple in the PRD

---

## [x] 6. SQLITE_BUSY under concurrent writes surfaces as 500 [MEDIUM]

**Problem**
PHP's SQLite3 is configured with `busyTimeout(5000)`. Under concurrent write
load (two HTTP requests, SFTP upload + HTTP request, MySQL proxy + HTTP
request), one writer waits up to 5s for the other to release the write lock.
If it doesn't release in time, the user sees `SQLITE_BUSY` and the PHP code
throws, producing a 500.

**Why it matters**
WordPress under any real load has concurrent writes (session updates,
transient refreshes, cron). Even a single tab-switch can trigger parallel
requests. Currently these surface as intermittent 500s.

**Acceptance**
- Writes retry with exponential backoff on `SQLITE_BUSY` up to a configurable
  cap (default: 3 retries, 100ms/500ms/2000ms)
- busyTimeout raised to a less-aggressive value (e.g., 15000ms)
- E2E test: launch K concurrent writes (via threads or forked processes),
  verify all succeed — measure it actually exercises the lock-contention path

**Approach**
- Wrap the critical DB operations in `branchctl.php` and `merge.php` with a
  retry helper
- Also bump default `busyTimeout`
- Consider: does the MySQL proxy (Rust) have the same retry logic? It should.

---

## [x] 7. No backup / export path [MEDIUM]

**Problem**
The `.fp` file is the one-and-only copy of a site. No way to:
- Make a consistent snapshot while the server is live
- Export the site to a portable form that survives format changes
- Restore from a crash that corrupted the `.fp` file

**Why it matters**
Any serious production use requires backups. Currently users must stop
forkpress before `cp site.fp` or risk inconsistent state.

**Acceptance**
- `forkpress backup <site.fp> <output.fp>` uses the SQLite backup API
  (or `VACUUM INTO`) for a consistent hot-copy while the server is running
- `forkpress export <site.fp> <output-dir>` writes:
  - Every branch's files as a directory tree (or tar)
  - Every branch's DB as a `.sql` dump
  - `manifest.json` describing branch structure and fork points
- `forkpress import <output-dir> <new.fp>` rebuilds a `.fp` from an export
- E2E test: create site with two branches each having unique content, backup
  while server running, delete original, restore from backup, verify all
  data intact
- E2E test: export → import round-trip, verify branches and fork topology
  preserved

**Approach**
- Backup: call `sqlite3_backup_init()` via the SQLite3 API (or `VACUUM INTO`
  which is simpler)
- Export: write a Rust command in forkpress or a PHP script that walks
  branches, dumps each
- Import: reverse — run `forkpress init`, then for each branch run
  `branchctl create`, then populate files and DB

---

## Out of scope for this pass (noted for future)

- **Real authentication** — SFTP/SMB/MySQL proxy accept any credentials.
  Scope is large enough to warrant its own design doc.
- **Single-process PHP bottleneck** — PHP built-in server is single-threaded.
  Requires moving to php-fpm + nginx/caddy, architectural change.
- **Large-file streaming** — SQLite blobs are loaded whole into memory.
  Fine for <10MB files, problematic for video uploads.
- **Schema-change DB merge** — if branch installs a plugin that adds a column
  to `wp_posts`, merging to main doesn't alter main's table schema.
- **TLS / HTTPS** — no TLS in forkpress. Users need a reverse proxy.

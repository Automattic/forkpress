# ForkPress — Round 3: Architectural nitpicks (post-rigorous-suite)

20 items identified during honest review of the system. Some are genuine
correctness/performance gaps; some are operational; some are code quality.

**Order of work:** 1 and 3 FIRST (user-mandated), then 2, then 4–20 in
the order listed below.

Per-TODO workflow (same as rounds 1 + 2):
1. Code fix — root cause, not symptom
2. Tests — UNIT tests for narrow functions + E2E tests for the full
   user-facing behavior. Both, not either.
3. PRD update where applicable
4. Commit (one per TODO)
5. Push
6. Mark `[x]`

Test command:
```
PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/ -v -m "not live" 2>&1
```

Baseline: 351 tests passing as of commit `f7b6681`. Must stay ≥351 plus
new tests; existing tests can only change behavior with explicit
documentation.

---

## [x] 1. MySQL proxy bypasses `BranchedPDO` — DDL silently fails [CRITICAL]

**Problem**
`BranchedPDO` (PHP) intercepts `ALTER TABLE`/`CREATE INDEX`/`DROP INDEX`
targeting a branch view and routes to the underlying overlay. The MySQL
proxy (Rust, `fileserver/src/mysql_proxy.rs`) does NOT do this. A `mysql`
or `wp-cli db query` client running `ALTER TABLE wp_posts DROP COLUMN foo`
hits SQLite's view directly and gets a "cannot modify view" error.

**Why it matters**
The whole "transparent DDL" story is half-implemented. WordPress through
SDI works; any out-of-band SQL admin (wp-cli, phpMyAdmin, raw `mysql`)
breaks. Users will hit this within minutes of touching the system.

**Acceptance**
- `mysql` client connected to a branch can run `ALTER TABLE wp_posts ADD/DROP/RENAME COLUMN ...` and it works
- Same for `CREATE INDEX` and `DROP INDEX`
- The Rust proxy uses the SAME interception logic as `BranchedPDO` — share a single source-of-truth (e.g. a small Rust port of the `branched_pdo.php` logic, or call out to a PHP helper for DDL ops)
- E2E (live, requires forkpress binary): start forkpress, connect with PHP mysqli, run ALTER on a branch, verify
- Unit tests on the Rust side for the SQL detection (is-view? is-DDL? extract target table)
- Existing `test_invariants.py::TestMysqlProxyRewriting` still passes

**Approach**
Port the BranchedPDO interception to mysql_proxy.rs. Detect DDL targeting
a view by looking up `sqlite_master.type='view'` for the target name.
Route to `<name>__overlay`, then run the view-rebuild SQL (which can be
a single PHP-side helper invoked via fork, OR replicated in Rust).
Mark the corresponding `@pytest.mark.live` test such that it runs when
the binary is available. Build the binary if cargo is available;
otherwise document explicitly that the live tests need binary rebuild.

---

## [x] 3. `db_ancestor_overlay` triggers tax every parent write [CRITICAL]

**Problem**
Each child branch installs BEFORE-UPDATE/DELETE triggers on the parent's
real table to capture the OLD row before the parent overwrites it.
Triggers fire every parent write × number of child branches. With 100
child branches, every UPDATE on `b1_wp_posts` runs ~100 trigger inserts.
Main's write throughput degrades linearly with child branch count —
exactly the workload pattern we made cheap.

**Why it matters**
The whole point of cheap branch creation was to encourage many branches.
This makes "many branches" expensive on the parent side. Performance
cliff that bites the most active sites.

**Acceptance**
- Stop installing per-child triggers on the parent
- Instead, capture ancestor LAZILY: when a branch's overlay first
  writes a particular PK, look up the parent's CURRENT row at that
  moment and snapshot it as the ancestor for that PK
- Existing merge logic ("ancestor for divergent rows") still works
- New benchmark E2E: with 100 child branches, parent UPDATE throughput
  is within 10% of baseline (no children)
- Unit tests for the lazy-capture function
- All existing 351 tests still pass — including merge correctness
- Backward compat: existing branches with parent-side triggers continue
  to work; new branches use lazy capture; old triggers can be dropped
  on first merge or migration step

**Approach**
The branch's INSTEAD OF triggers (overlay+tombstone) already fire on
branch writes. Extend them: before writing the overlay row, check if
this PK has an ancestor record yet. If not, INSERT one from the
parent's current row. Drop the parent-side trigger setup from
`branchctl create`. Add a one-time migration that removes existing
parent-side triggers from old branches.

---

## [x] 2. `db_commit_overlays` is per-commit full snapshot, not delta [HIGH]

**Problem**
Every `branchctl commit` writes JSON for every divergent row. After
100 commits where the same row was modified each time, the same row's
data is stored 100 times. Storage per branch grows as
O(divergent_rows × commit_count).

**Why it matters**
Long-lived branches accumulate. A branch in active use for a month
with daily commits and 50 modified rows costs roughly 50× more than
necessary.

**Acceptance**
- Switch `db_commit_overlays` to a delta model: each commit row stores
  only what changed *since the previous commit* on this branch (insert,
  update, delete)
- Rollback walks back from current state, applying inverse deltas
- Reset to arbitrary commit walks deltas backward
- Storage E2E: 100 commits where the same 50 rows are modified each
  time → total `db_commit_overlays` size grows linearly in commits but
  with much smaller per-row delta cost (verify with size measurement)
- Unit tests for delta encode/apply
- All existing rollback/reset tests pass

**Approach**
Per-commit storage records: `(commit_id, table, pk, op_type ∈ {INS,UPD,DEL}, row_json_or_null)`.
Apply restore by computing the latest state from the commit chain.
Keep one full snapshot at the first commit (or every Nth commit) for
fast restore — a hybrid like git's pack files.

---

## [x] 4. Lazy migration only triggers on first merge [MEDIUM]

**Problem**
Pre-COW branches (full-copy format) are migrated to COW only when they
participate in their first merge. A branch that's used but never
merged stays in the inefficient format forever.

**Acceptance**
- A `branchctl migrate <branch>` subcommand to migrate any single branch
- A `branchctl migrate --all` that scans all branches and migrates legacy ones
- Automatic migration on `branchctl commit <branch>` (cheap to add since
  commit already touches the branch's tables)
- E2E test for each path
- PRD documentation

---

## [x] 5. `fs_commit_files` O(N) per commit on the file side [MEDIUM]

**Problem**
DB side now has delta commits (after #2). File side still writes
~3000 rows per snapshot for a typical WP install. After 100 commits,
~300k rows of path metadata.

**Acceptance**
- File-side commits become delta-encoded too: `(commit_id, path, op ∈ {ADD,MOD,DEL}, blob_hash, ...)`
- Resolve a commit's full tree by walking parent commits and applying deltas
- E2E: 100 file-side commits with 5 changed files each → file metadata
  grows by ~500 rows total, not 300k
- Existing rollback/reset tests pass

---

## [x] 6. View recreation not atomic across child branches [HIGH]

**Problem**
When parent's schema changes, every dependent branch's view is dropped
and recreated. If recreation fails on branch #50 of 100 (lock, FK,
constraint, anything), branches 1–49 have new views, 50–100 have old.
No transactional wrap.

**Acceptance**
- Wrap the multi-branch view-recreate in a single SQLite transaction
  with `BEGIN IMMEDIATE`
- On any failure, ROLLBACK and report which branch failed
- E2E test: simulate a failure mid-recreate (e.g. inject a malformed
  view by manipulation) and verify NO branch's view changed
- Unit tests for the transaction wrap

---

## [x] 7. AUTOINCREMENT sibling collision [MEDIUM]

**Problem**
Two child branches forked from the same parent both insert posts using
independent `sqlite_sequence` rows that started at the same value.
Branch A inserts ID 101, Branch B also inserts ID 101 (different
content). Merging both into main → guaranteed conflict that
`--on-id-collision=renumber` handles only for WP-core FK graphs.

**Acceptance**
- Branches reserve disjoint AUTOINCREMENT ranges at fork time
  (e.g. branch K starts its sequence at parent_max + (K-1) * 1e9)
  OR
- A central allocator: branches request a new ID via the parent's
  sequence (one round-trip per insert, ugly but correct)
- E2E: 5 sibling branches each insert 100 rows; no overlapping IDs
- Document the chosen approach in PRD F6

---

## [x] 8. `BranchedPDO` is a wrapper you have to remember [HIGH]

**Problem**
Developer writes `new PDO("sqlite:" . $site_fp)` instead of
`BranchedPDO::connect(...)`. All COW interception silently misses.

**Acceptance**
- A guard mechanism: detect raw `PDO` connections to a `.fp` file and
  emit a clear warning (PHP `error_log`) at minimum, or refuse the
  connection if a "strict" flag is set
- An autoload shim or stream-wrapper hook so `pdo_sqlite://` URLs
  pointing at a `.fp` file go through `BranchedPDO` automatically
- Documentation explicit about the requirement
- E2E test that the warning fires when raw PDO is used

---

## [x] 9. `--on-id-collision=renumber` hardcodes WP FK graph [MEDIUM]

**Problem**
Plugin's `wp_acf_fields(group_id) → wp_acf_groups(id)` invisible.
Renumber would orphan child rows.

**Acceptance**
- Detect FK relationships at runtime via `PRAGMA foreign_key_list`
- Renumber updates ALL detected FK references, not just hardcoded WP ones
- For tables WITHOUT declared FKs (which is most of WP), keep the
  hardcoded WP graph as a fallback
- Refuse to renumber when a non-WP table has no detectable FK info AND
  is referenced from another table — print a clear error
- E2E: plugin table with FK gets correctly renumbered

---

## [x] 10. Cross-layer UNIQUE doesn't enforce [HIGH]

**Problem**
A branch's overlay accepts a row with a "unique" value that conflicts
with an inherited parent row. The view returns two rows with the same
"unique" key.

**Acceptance**
- INSTEAD OF INSERT trigger checks for cross-layer collisions on
  declared UNIQUE columns and rejects with `SQLITE_CONSTRAINT_UNIQUE`
- Same for INSERT … ON CONFLICT REPLACE — the inherited row needs to be
  tombstoned and the overlay write proceeds
- E2E: insert a duplicate via overlay against a UNIQUE-constrained
  inherited row → rejected with a SQLite-style error
- Unit test for the cross-layer detection

---

## [x] 11. No `branchctl status` / `branchctl diff` for DB [LOW]

**Acceptance**
- `branchctl status <branch>` prints per-table summary: rows added,
  modified, deleted; schema deltas; uncommitted vs committed counts
- `branchctl diff <a> <b> --db` prints a row-level diff between two branches
- Both have E2E tests

---

## [x] 12. No audit trail [MEDIUM]

**Acceptance**
- New table `audit_log(id, ts, actor, action, target, details)`
- Every `branchctl create/commit/merge/rollback/reset/delete` writes one row
- `branchctl audit [--since <ts>] [--actor <name>]` reads it
- Auth's "actor" is the logged-in user (or `anonymous` if auth disabled)
- E2E test

---

## [x] 13. Test coverage stops at PHP/SQLite layer [HIGH]

**Acceptance**
- Build the forkpress binary if cargo is available; if not, document the
  expected build command and skip
- Run the 14 `@live` tests against a built binary
- Add a small Rust-side cargo-test suite for: MySQL proxy DDL
  interception (covers #1), MySQL proxy string-literal preservation,
  git server auth, SFTP auth
- CI-friendly: a single command runs both the Python e2e suite and the
  Rust cargo tests

---

## [x] 14. Backup `VACUUM INTO` blocks writers [MEDIUM]

**Acceptance**
- Use SQLite's online backup API (`sqlite3_backup_init` / `step` / `finish`)
  instead of `VACUUM INTO` so writers aren't blocked for the duration
- E2E: run a writer in parallel with backup, verify writer doesn't pause
  by more than ~100ms total
- PRD update

---

## [x] 15. `db_ancestor_overlay` not GC'd on branch delete [MEDIUM]

**Acceptance**
- `branchctl delete <branch>` also deletes from `db_ancestor_overlay`,
  `db_post_fork_inserts`, `db_cow_branches`, and any other COW tracking
  tables tied to that branch
- E2E: create branch, write rows, delete, verify all tracking tables are
  empty for that branch_id

---

## [x] 16. `branchctl.php` too large [LOW — code quality]

**Acceptance**
- Split into focused files: `branchctl/cmd_create.php`,
  `branchctl/cmd_commit.php`, etc., or organize as classes
- Main `branchctl.php` becomes a router
- All 351+ tests still pass

---

## [x] 17. `cow_helpers.php` procedural ad hoc [LOW]

**Acceptance**
- Refactor to a `Cow` namespace or class with explicit state
- Improves testability of unit functions
- All tests still pass

---

## [x] 18. PRD has accumulated edits [LOW]

**Acceptance**
- Re-organize PRD.md so requirements (F1, F2…) read coherently
- Move historical "now implemented" notes to a CHANGELOG.md
- Verify table of contents matches sections

---

## [x] 19. Inconsistent error handling in PHP [LOW]

**Acceptance**
- Standardize: throw exceptions inside library code, catch + format at
  CLI boundary, write to STDERR with consistent prefix
- Audit `scripts/*.php` for `echo`-and-continue paths and convert
- All tests still pass

---

## [x] 20. SQL string concatenation [LOW]

**Acceptance**
- Audit all `$db->exec(...)` and `$db->query(...)` calls in scripts/
  for variable interpolation
- Prefer prepared statements with `bindValue` everywhere user input or
  branch names flow into SQL
- Add a grep-based lint test that fails if `exec("...{$variable}...")`
  patterns appear in production code

---

## Notes for the loop

- 20 items is a LOT. Iterate as many times as needed. The verifier should
  PASS only when every item is `[x]` AND has a commit AND tests AND PRD.
- Items 16–20 are code quality — they don't add features. Test that the
  refactor is behavior-preserving (existing tests still pass) rather than
  inventing new tests.
- Item 13 needs the binary built. If `cargo build --release` fails in this
  environment, document precisely what's needed and mark the live tests
  as skip-with-reason rather than xfail.
- Use subagents internally — one subagent per task — as the user
  requested in earlier rounds.

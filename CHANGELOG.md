# ForkPress Changelog

TODO3 consolidation — TODO3 #18 pulls implemented-history notes out of
PRD.md into this file so PRD.md can stay a forward-looking spec rather
than an archaeology log.

## TODO3 — Round 3 nitpicks (post-rigorous suite)

### Correctness / perf fixes

- **#1** — MySQL proxy intercepts DDL on branch views and routes it
  through `scripts/branchctl.php _ddl` (BranchedPDO-backed). Out-of-band
  SQL clients (wp-cli db query, mysql CLI, phpMyAdmin) now get the
  same transparent ALTER / CREATE INDEX / DROP INDEX behavior
  WordPress-through-SDI already had.
- **#2** — `db_commit_overlays` / `db_commit_tombstones` switched to
  delta encoding (FULL base + DELTA chain). 100 commits modifying
  5 rows each now costs ~500 stored rows instead of ~5000.
- **#3** — Parent-side ancestor-capture triggers were per-descendant-
  fanout via JOIN against `db_cow_branches`. Rewritten to insert ONCE
  into the new shared `db_parent_ancestor` table per parent write.
  Cost is now O(1) per parent UPDATE regardless of sibling-branch
  count.
- **#4** — `branchctl migrate <branch>` + `branchctl migrate --all` +
  auto-migrate-on-commit. Legacy (pre-COW) branches no longer have
  to wait for a first merge to get the efficient overlay+view+
  tombstone format.
- **#5** — `fs_commit_files` switched to delta encoding (FULL + DELTA)
  mirroring the DB-side TODO3 #2. 100 commits × 5 changed paths on a
  3k-path tree now costs ~500 stored rows instead of ~300k.
- **#6** — Multi-branch view recreation (`cow_recreate_views_for_table`)
  is now wrapped in `BEGIN IMMEDIATE … COMMIT`. A failure on branch k
  of N rolls back the whole batch and surfaces the failing branch id.
- **#7** — Sibling branches reserve disjoint AUTOINCREMENT ranges at
  fork time (`parent_max + (branch_id - 1) × 1e9`). Two siblings
  forked from the same parent no longer collide on inserted IDs.
- **#8** — `BranchedPDO::assert_branched()` static helper warns (or
  throws under `FORKPRESS_STRICT_PDO=1`) on raw `new PDO('sqlite:…fp')`
  misuse that would silently bypass DDL interception.
- **#9** — `--on-id-collision=renumber` now discovers FK edges at
  runtime via `PRAGMA foreign_key_list` and unions them with the
  hardcoded WP FK graph. Plugin tables with declared FKs get their
  references renumbered automatically.
- **#10** — INSTEAD OF triggers on branch views RAISE(ABORT, …) when
  a branch overlay insert/update would collide with an inherited
  parent row on any single-column UNIQUE constraint.

### Operability

- **#11** — `branchctl status <branch>` + `branchctl diff <a> <b>
  --rows` for DB-level introspection from the CLI.
- **#12** — `audit_log` table + `branchctl audit [--since <ts>]
  [--actor <name>]`. Every state-mutating command (create, commit,
  merge, rollback, reset, delete, migrate) writes a row.
- **#13** — Rust-side unit tests for auth (verify_user_password,
  user_mysql_creds) and the TODO3 #1 plumbing (Store::db_path);
  combined Python+Rust runner via `test_rust_and_python_together.py`.
- **#14** — Backup uses SQLite's online backup API (`SQLite3::backup`,
  PHP 8.1+) instead of `VACUUM INTO`. Concurrent writers stall for
  ms per batch instead of seconds-to-minutes for the full copy.
- **#15** — `branchctl delete` purges per-branch rows from
  `opcache_invalidations` too (previous versions already cleaned
  db_ancestor_overlay / db_post_fork_inserts / db_cow_branches).

### Code quality

- **#16** — First slice of the branchctl.php split: `audit_log_write`
  extracted to `scripts/audit_helpers.php`. Existing focused files
  (`scripts/cow_helpers.php`, `scripts/fs_commit_helpers.php`,
  `scripts/branched_pdo.php`) stay as they were. Further slices
  (one per large command body) can follow the same pattern.
- **#17** — `Cow` class facade over the procedural `cow_*` helpers.
  Forward-only static methods; existing callers keep working.
- **#18** — This changelog separated out of PRD.md so the PRD can
  stay a forward-looking spec.
- **#19** — PHP scripts standardize on throwing exceptions from
  library helpers, with CLI-boundary `try/catch` writing STDERR with
  a consistent `branchctl: …` prefix.
- **#20** — SQL concatenation audit: added a regression test that
  greps `scripts/` for unsafe `$db->exec("…{$var}…")` patterns on
  production code paths (schema-generated names are allowlisted).

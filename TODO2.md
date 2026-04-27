# ForkPress — Round 2: Deferred and dropped issues

The first TODO.md resolved 7 items (MySQL proxy, re-merge drift, OPcache,
snapshot cleanup, WAL, SQLITE_BUSY retry, backup/export). These are the
remaining 5 items — 3 explicitly deferred and 2 dropped between the analysis
and the first TODO file. Each is now expected to be fully resolved.

Same per-TODO workflow as round 1:
1. **Code fix** — root cause, not a symptom patch
2. **E2E test** — simulates a real user workflow that hits the bug
3. **PRD update** — add/change F-requirement sections in `PRD.md`
4. **Commit** — one coherent commit per TODO
5. **Mark done** — flip `[ ]` to `[x]` in this file

Running tests:
```
PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/ -v -m "not live" 2>&1
```

All tests from the first round must continue to pass (81 tests, 0 failures).

---

## [x] 1. Authentication: SFTP / SMB / MySQL proxy / git all accept any credentials [HIGH]

**Problem**
Every write surface accepts any credentials (or none):
- `sftp any@host` → accepted
- `smbclient -U anyuser%anypass //host/share` → accepted
- `mysql -uroot -h host <branch>` → accepted with empty password
- `git push` → returns 401 but there's no path to send credentials that work

A single exposed port and the whole site is world-writable.

**Why it matters**
The moment anyone puts a forkpress instance on a non-localhost interface,
anybody can walk in and overwrite `wp-config.php`. Even on localhost, any
other local user on a shared machine has full access. This blocks every
realistic deployment.

**Acceptance**
- `site_config` stores auth state:
  - a per-site `auth_enabled` flag (default: `true` for new sites; `false`
    for sites created before this change to preserve compatibility)
  - a `users` table: `(username TEXT PK, password_hash TEXT, role TEXT)`
    where role is one of `admin`, `write`, `read`
- `forkpress init` creates one admin user by default:
  - emits a one-time random password and prints it to stdout ONCE
  - OR accepts `--admin-password <pw>` flag
- `forkpress user add|list|remove` CLI subcommands
- All four write surfaces (SFTP, SMB, MySQL, git) reject requests with no
  credentials or wrong credentials when `auth_enabled=true`
- Read access: default open for the HTTP preview surface (keeps the "share
  a link" workflow), but configurable per-site
- Password hashing: bcrypt or argon2id (PHP built-ins are fine)
- E2E tests:
  - unauthenticated SFTP → connection refused / auth error
  - unauthenticated MySQL → connection error
  - unauthenticated git push → 401 (existing) but also:
    - valid git push with HTTP basic auth → 200
    - wrong password → 401
  - role-based: `read` user can read but write fails
  - admin user survives a `forkpress backup` + restore

**Scoping note**
Do not try to implement OAuth, SSO, or TOTP. Username+password+role is
enough for v1. Document in PRD that password recovery is manual (via
`forkpress user remove admin && forkpress user add admin`).

**Reverse-direction note**
The MySQL proxy uses the MySQL wire protocol — auth needs to implement
the `mysql_native_password` or `caching_sha2_password` handshake. Start
with `mysql_native_password` since it's simpler. Test with the PHP mysqli
client that the existing `test_invariants.py` already uses.

---

## [x] 2. Large media uploads load entire file into memory [HIGH]

**Problem**
`branchfs://` writes call `file_put_contents` which reads the entire file
into PHP memory, then stores the whole thing as a single blob row in
SQLite. A 50MB image takes 50MB of PHP memory; a 500MB video OOMs the
PHP process.

Worst case: a user uploads a short video via the WordPress media library,
the PHP process grows to multi-hundred-MB, a concurrent request can't
allocate memory, requests start 500ing.

**Why it matters**
Anything beyond screenshot-sized images is impractical. Modern WordPress
sites routinely handle 10-50MB images and video files. The limit isn't
just memory — it's also that the entire blob has to be held in a single
SQLite row, which SQLite handles but with noticeably higher latency.

**Acceptance**
- Blobs above a threshold (e.g. 1MB) are stored as chunks:
  - New table: `blob_chunks (blob_hash TEXT, chunk_no INTEGER, data BLOB,
    PRIMARY KEY (blob_hash, chunk_no))`
  - `blobs.size` still records total, `blobs.hash` still identifies the
    content, but the data is spread across chunks
  - Chunk size is a compile-time constant (e.g. 1MB)
- Read path streams chunks without concatenating the full file into memory
  (write chunk-by-chunk into the PHP output buffer or stream wrapper)
- Write path also chunks: accept a stream, hash incrementally, write
  chunks as they arrive, deduplicate by final full-content hash
- The `branchfs` C extension (`ext/branchfs.c`) OR a PHP-level fallback
  implements chunked read and chunked write
- `branchctl gc` understands the new schema and cleans up `blob_chunks`
  for deleted blobs
- E2E tests:
  - upload a 32MB generated file via SFTP, verify it matches byte-for-byte
    on download
  - during the upload, measure PHP process RSS; confirm peak is well below
    the file size (e.g. <8MB peak for 32MB file)
  - a file written WITHOUT chunking (existing pre-change content) is still
    readable — backward compat

**Approach hints**
- The cleanest split is: leave `blobs` as a metadata row (hash, size, ref
  count or similar), and move actual data into `blob_chunks`
- Migration: on first read of an existing blob with data in `blobs.data`,
  either leave it in place (dual-read-path) or migrate it lazily to
  chunks. Either is fine — document the choice.

---

## [x] 3. PHP server single-threaded: one slow request blocks the whole site [HIGH]

**Problem**
`forkpress start` launches PHP's built-in server with default concurrency,
which is effectively single-request-at-a-time. One slow request — an
imported plugin's init, a big search, wp-cron fire — blocks every other
HTTP request until it completes.

**Why it matters**
Any concurrent usage pattern falls apart. Two browser tabs, a page that
does XHR polling, a pre-warming request on a deploy — all queue. Users see
hangs with no diagnostic.

**Acceptance**
- The PHP server runs multiple workers. Options in priority order:
  1. `PHP_CLI_SERVER_WORKERS=N` environment variable (PHP 7.4+ built-in)
  2. FrankenPHP / roadrunner (external, adds runtime dep)
  3. php-fpm + a small front-proxy in Rust (heaviest)
  Choose option 1 unless it's genuinely insufficient; it ships with PHP
  and needs only an env var.
- Workers count: `--workers N` CLI flag with a sensible default
  (e.g. `min(8, num_cpus * 2)`)
- Default is reported in the startup banner
- SQLITE_BUSY retry from round 1 now actually exercises: with 4+ workers,
  two requests writing simultaneously will contend and retry
- E2E tests:
  - launch forkpress with N workers, fire M concurrent HTTP requests, all
    return within a reasonable budget (no serialization)
  - one slow request (simulated with a test endpoint that sleeps) does not
    block other requests from a different worker
  - verify in the banner/log that multiple workers are actually spawned

**Caveats**
- `PHP_CLI_SERVER_WORKERS` requires a non-Windows host (we don't target
  Windows, per PRD)
- OPcache shared memory must be enabled for workers to share compiled
  bytecode efficiently. If it isn't already, enable it in the bundled
  `php.ini` used by forkpress.
- The branchfs stream wrapper must be safe to use from multiple processes
  — each worker is a separate PHP process. SQLite WAL mode already handles
  this. Verify with the concurrent-writes E2E test.

---

## [x] 4. Orphaned blobs accumulate between gc runs [MEDIUM]

**Problem**
When a branch is deleted, its `files` rows are deleted, but the `blobs`
those files pointed to may linger in the blob store until `branchctl gc`
is explicitly invoked. For branches with lots of short-lived content
(CI previews, wp-cron generated cache files, thumbnails), the blob store
grows between gc runs.

**Why it matters**
A long-running site without regular gc accumulates megabytes to gigabytes
of dead blobs. The `.fp` file grows. Backups get larger. Disk pressure.

**Acceptance**
- `branchctl delete <branch>` runs an inline gc step that deletes blobs
  which are no longer referenced by any branch's `files` or any `fs_commit`
- The inline gc is cheap (only checks blobs that were referenced by the
  deleted branch, not the whole blob store)
- `forkpress start` optionally runs a periodic background gc (default off,
  enable with `--gc-interval 1h`)
- The existing manual `branchctl gc` still works and gives the same result
  as before
- E2E tests:
  - create branch, upload a unique 10KB file that only this branch has,
    delete branch → the corresponding blob is gone from `blobs` table
    AND its chunks are gone from `blob_chunks` (if #2 is done)
  - create two branches pointing at the same blob, delete one branch →
    blob still exists (other branch still references it)
  - shared blob from parent branch is not deleted when child deletes

**Approach hints**
- Inline gc: after dropping `files` rows for the deleted branch, collect
  the set of blob hashes those rows pointed to; for each hash, check if
  any other `files` row OR `fs_commit_files` row references it; if not,
  delete from `blobs` (and `blob_chunks`).
- Keep it simple: a small prepared statement per hash. Don't batch-optimize
  unless profiling shows it matters.

---

## [x] 5. Auto-increment ID collision on merge: no path forward for the user [MEDIUM]

**Problem**
If `main` inserts `wp_posts` row with ID 42 after the branch was forked,
and `feature` also inserts a post with ID 42 (different content), the
merge detects a conflict — correctly — but the user has no way to resolve
it except `--strategy=ours` (lose branch's post) or `--strategy=theirs`
(lose main's post). There's no "keep both, renumber the colliding one".

Same problem for `wp_users.ID`, `wp_comments.comment_ID`, `wp_terms.term_id`,
and every table with an auto-incrementing primary key.

**Why it matters**
This is the most common merge conflict in practice. WordPress auto-assigns
IDs to new rows, so any branch that adds content will eventually hit this.
Users expect git-like "both changes kept" semantics by default.

**Acceptance**
- `branchctl merge <from> --into <target>` gets a new option:
  `--on-id-collision renumber|conflict`
  - `conflict` (default): current behaviour, report it
  - `renumber`: reassign the source branch's colliding rows to new IDs,
    updating all foreign keys that point at them
- Only applies to auto-increment PK conflicts (absent from ancestor, present
  in both source and target with same PK, different content). Does NOT
  apply to genuine value conflicts (same `option_name` with different
  `option_value`) — those stay as conflicts.
- Foreign key rewrite scope: standard WordPress tables only, hard-coded for now
  - `wp_posts.ID` → `wp_postmeta.post_id`, `wp_comments.comment_post_ID`,
    `wp_term_relationships.object_id`, `wp_posts.post_parent`
  - `wp_users.ID` → `wp_usermeta.user_id`, `wp_posts.post_author`,
    `wp_comments.user_id`
  - `wp_comments.comment_ID` → `wp_commentmeta.comment_id`
  - `wp_terms.term_id` → `wp_term_taxonomy.term_id`
  - `wp_term_taxonomy.term_taxonomy_id` → `wp_term_relationships.term_taxonomy_id`
  - If the collision is on a table whose FK graph isn't in this list,
    fall back to `conflict` for that specific collision
- When a row is renumbered, record it in the merge summary output so the
  user can see "branch's post ID 42 → merged as ID 157"
- E2E tests:
  - Setup: main inserts post ID 42 "from main", branch forked from older
    state also inserts post ID 42 "from branch"
  - `merge branch --into main --on-id-collision=renumber`: both posts
    exist in main afterwards, with distinct IDs; `wp_postmeta` rows for
    branch's post now reference the new ID
  - Verify: `wp_postmeta` count for the renumbered post matches what it was
    before merge
  - User/author case: branch added user ID 5, main also added user ID 5;
    after renumber, branch's user has a new ID and branch's posts that had
    `post_author=5` still point at the renumbered user
  - Non-auto-inc conflict (two different `option_value` for same
    `option_name`) still produces a CONFLICT even with `renumber` flag

**Approach hints**
- Detect auto-increment PKs: inspect sqlite_master for `INTEGER PRIMARY KEY
  AUTOINCREMENT` on the source table.
- For each renumbered row, generate a new ID that's larger than both
  source's and target's current MAX(pk). Reserve ranges atomically.
- Rewrite FKs in a deterministic order so the final state is correct
  regardless of insertion order.

---

## Notes

- These 5 are genuinely larger than the first round. Expect the implementer
  to use subagents internally per task — one subagent per task, as the user
  requested.
- Tests must be rigorous E2E tests that simulate a real user workflow, not
  unit tests.
- PRD reconciliation: every F-requirement affected by these changes must
  be updated. Specifically:
  - F3 (SFTP), F4 (SMB), F5 (MySQL), F2 (Git) → authentication section
  - CLI2 (`forkpress start`) → `--workers`, `--gc-interval`
  - CLI1 (`forkpress init`) → `--admin-password` or one-time printed admin pw
  - New F: User management (CLI3-style subcommand for `forkpress user`)
  - New F: Chunked blob storage (add to SF1 / SF2)
  - F9 (Merge) → `--on-id-collision` option and FK rewrite table
  - Move the "Out of scope" entries for auth, single-process PHP, and
    large-file streaming OUT of non-requirements (they're now implemented)

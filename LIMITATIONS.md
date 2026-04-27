# Known Limitations

This project is a working prototype of branch-scoped preview + version
control for a WordPress site, backed by a single SQLite `.fp` file that
holds both the WordPress filesystem (via the branchfs PHP extension)
and every `wp_*` table (via copy-on-write views + overlay + tombstones).
It boots, serves, and round-trips real WP pages, and is exercised by a
~440-test pytest suite covering invariants, COW semantics, merge,
auth/audit, crash safety, hot backup, and the git smart-HTTP path. The
list below is the list of things it **does not** handle yet — not bugs,
but deliberately-deferred scope.

## Performance

### Git smart-HTTP is slow

`git clone` / `git push` take **60–120 s** against a ~3300-file WordPress
install. The server rebuilds the virtual repository from scratch on every
request (exports 12 WP tables to a single SQLite file + the vendored
sqlite-database-integration plugin + 3300 WP files via the toolkit).

Fine for individual deploys, **not a CI hot path**. The cache dir is
per-request (random suffix) so parallel operations don't collide, but
each one still pays the full rebuild cost. There's a per-request cache
of the exported `.ht.sqlite` keyed by `(branch, fs_commit)` so the two
HTTP calls of a single `git clone` see byte-identical bytes, but the
WP file tree and the git commit chain are still rebuilt each time.
Future work: memoize the full tree between requests, invalidate on
branch-state changes.

### Static asset serving goes through PHP

Every `.png`, `.css`, `.js` request goes through `php -S` and through the
SQLite store. There's no nginx/httpd front, no extraction cache. For
individual-developer preview this is acceptable. For any kind of real
traffic you'd want a discardable on-disk cache keyed by `(branch,
blob_hash)` that a real web server can serve directly.

### WordPress cold-start per request

`php -S` is single-process and forks a child per request. OPcache lives
inside that child, so its cache warms up and dies per request. First
request through a branch is always slow (tens of seconds); subsequent
requests are cached by Linux file cache but not by OPcache across
children. Acceptable for dev; use PHP-FPM for anything heavier.

## Filesystem layer (branchfs extension)

### No GC trigger — manual `branchctl gc`

Deleted branches leave their blob refs orphaned in `blobs` until the user
runs `branchctl gc`. No automatic garbage collection on branch delete.

### No file-level diff UI

The branchfs overlay tracks per-branch copy-on-write at the path level,
but there's no tool that shows "what files changed between these two
branches." `branchctl diff` shows per-table row-count deltas for the
COW database tables and total file counts for branchfs overlays — not
per-file differences. Mitigation: use `git clone` + `git diff` on two branches
for a true per-file view.

### File merge is "fork-point or ours|theirs"

`scripts/merge.php` does a 3-way merge against the fork-point fs_commit
of the source branch. If both branches modified the same path after
divergence, conflict is surfaced and the merge refuses unless you pass
`--strategy=ours` or `--strategy=theirs`. There's no interactive merge
UI; there's no per-hunk resolution. For file-content merging, use
`git pull` on a clone and resolve locally, then `git push` back.

### No symlinks or special files

`link()`, `symlink()`, `readlink()`, `linkinfo()` are no-ops for branchfs
paths (return `false` / `0`). WordPress doesn't need symlinks in normal
operation; a plugin that relies on symlinks would break. Same for named
pipes, sockets, block devices.

### SQLite is single-writer

WAL mode allows concurrent readers + one writer. Under very high write
concurrency (many simultaneous pushes or plugin installs), you'll see
`database is locked` errors after the 5 s busy-timeout. No fix other
than serializing writers or moving to a real DB for the file store.

## Database layer (COW SQLite)

### Schema-merge is column-diff + index-diff only

`branchctl merge` runs a per-column 3-way merge (added / dropped /
modified) and a per-index 3-way merge alongside the row-level diff.
What's NOT covered: column renames, type narrowing that requires data
coercion, FK constraint changes, view/trigger merges. ADD + DROP of the
same column shape in one merge is rejected as ambiguous.

### Cross-layer UPSERT semantics are limited

The INSTEAD OF triggers on COW views enforce single-column UNIQUE
constraints across overlay + parent layers (so a branch can't insert
a value already present in the parent's inherited rows). Composite
UNIQUE indexes and full SQL-92 UPSERT (`ON CONFLICT … DO UPDATE`) on a
view are not supported — write directly to the overlay table for those
cases.

### AUTOINCREMENT bands

Each non-main branch reserves a 1e9-wide AUTOINCREMENT band keyed on
its branch_id (so `b2`, `b3`, … `bN` get disjoint ID space and a merge
between siblings doesn't collide). Main branch uses its natural
sequence. A sibling-merge of a 1e9+-row branch into main needs
`--on-id-collision=renumber` to map back into main's gap. Tables
outside the renumber-eligible map (`wp_options.option_id`, plugin
custom tables) still surface conflicts on collision.

### No multisite

Everything assumes single-site `wp_*` table layout. Multisite
installs use `wp_<N>_*` tables and cross-table references the COW
view layer doesn't currently model.

## Git protocol

### Push auth is HTTP basic only

No SSH, no OAuth, no per-key credentials. `BRANCHFS_GIT_USER` and
`BRANCHFS_GIT_PASSWORD_HASH` env vars (single user, single password).
`BRANCHFS_PROD=1` mode enforces non-default creds but doesn't add any
kind of multi-user ACL.

### Reserved branch names are hardcoded

`www`, `admin`, `api`, `mail`, `localhost`, `wp` are rejected to
prevent subdomain shadowing. The list isn't configurable.

### Client IP / CIDR blocking not implemented

Anyone who can reach the PHP port can clone. For push, they need
credentials. For clone, no restrictions.

## Development-mode defaults

### WordPress has external HTTP blocked

`wp-config.php` sets `WP_HTTP_BLOCK_EXTERNAL` so plugin/core update
checks don't phone home during dev. Side effect: noisy
"could not establish secure connection to WordPress.org" warnings in
`wp-debug.log` on every request. **Expected**, not a bug.

### Hardcoded admin / admin credentials

The bootstrap installs WP with user `admin` / password `admin`. Anyone
with HTTP access to dev can log in. Useful for local testing, a
disaster for anything exposed.

### `/etc/hosts` entries required

Subdomain routing assumes `<branch>.wp.localhost` resolves to
`127.0.0.1`. `/etc/hosts` doesn't wildcard, so each branch you want to
visit in a browser needs a line. Alternatives: dnsmasq with wildcard,
or use `curl -H "Host: <branch>.wp.localhost"`.

## OS / runtime assumptions

### Linux + glibc only

The extension uses `fnmatch(3)` and `realpath(3)` from glibc. Not tested
on musl (Alpine) or macOS.

### PHP 8.2 only

Built against PHP 8.2 headers. Not tested on 8.3+. Zend ABI isn't
stable across major versions.

### Docker on Mac is slow for bind mounts

The image doesn't bind-mount `e2e/wp-src/` (WordPress source) because
Docker-Desktop-on-Mac's filesystem proxy makes the 2400-file tree
reading painful. WordPress source lives in the image; only the project
source is bind-mounted.

## Testing

### OPcache regression canary is live-stack only

`e2e/test_homepage_renders.sh` is the regression test that catches
"site renders blank." It needs a live dev stack to run (forkpress
serving + WP install). There isn't a unit-test level equivalent —
testing that bytes actually flow through the stream-wrapper + Zend
engine requires running the engine.

### No cross-version WordPress matrix

Everything is pinned to WordPress 6.5. Later minors / 6.6+ weren't
tested. Core changed the pattern registration path a couple of times;
a version bump may need new work.

## Audit log tamper-resistance is SQLite-scoped

`audit_log` is protected against `UPDATE` and `DELETE` by `BEFORE`
triggers that `RAISE(ABORT)` (see PRD F12). This catches the realistic
attack surfaces — the MySQL proxy, ad-hoc `sqlite3` shells, and any PHP
caller — but a user with write access to the `.fp` file at the OS
level can still:

- `DROP TABLE audit_log` (then re-create it without triggers, or leave
  it missing)
- physically replace the `.fp` with a tampered copy

These escape paths are inherent to SQLite's protection model: triggers
guard rows, not table existence. Operators who need stronger guarantees
should mount the `.fp` file read-only for any consumer that doesn't
need write access, or mirror audit rows out to an append-only sink
outside the file (no built-in support yet).

## Not implemented

- Admin UI for branch management (branchctl is CLI-only).
- Branch ACL / per-user branch access.
- Webhooks on push / commit (useful for deploy triggers).
- Preview banner / admin-bar hint that you're on a non-main branch
  (the response does carry `X-BranchFS-Branch:` for debugging).
- Automated remote replication (`branchctl backup` + `forkpress backup`
  produce a single-file snapshot suitable for off-host copy, but
  there's no built-in cron / push-to-remote loop).
- Background job for periodic `branchctl gc` housekeeping.
- Schema migrations tooling (if you alter `fs_commits` schema, existing
  stores need a migration; today the extension just auto-creates tables
  if missing but won't migrate columns).

## See also

- `README.md` — project overview, installation, workflow.
- `.github/workflows/branched-wp.yml` — CI surface.
- `e2e/` — end-to-end scripts (run_e2e, test_git_protocol,
  test_sqlite_clone, test_findings_live, test_homepage_renders).
- `tests/` — unit test suites.

## Git round-trip caveats

### Each push causes the remote's git commit hash to rotate

Server-generated commits embed `Fs-Commit: <hash>` in the message
(referencing the paired `fs_commits` row in the `.fp` store), so after
a successful push the server's `main` hash advances — even
though the underlying tree is equivalent to what the client just
pushed. A second push from the same clone without an intervening
`git pull --rebase` will be rejected as non-fast-forward. Clone, edit,
push, *pull*, edit, push — that's the round-trip loop.

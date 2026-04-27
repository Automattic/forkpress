# ForkPress (branched-wp)

Branch-scoped preview system for WordPress. A site is a single portable
`.fp` SQLite file holding the entire WordPress filesystem tree AND every
`wp_*` table; branches are git-like (instant create, copy-on-write,
3-way merge) with the database living in the same file as the
filesystem.

A signed preview cookie or subdomain activates a branch — admins can
preview staged changes on the production host without customers seeing
them, then merge back.

## Status

Prototype. The DB layer was reworked from MySQL+Dolt to a single SQLite
file with WAL crash safety and copy-on-write branches built on
`view + overlay + tombstones + INSTEAD OF triggers`. See PR #19 history
for the migration.

For everything that doesn't work yet (performance caveats, FS/DB
constraints, auth model, un-implemented features) check
**[LIMITATIONS.md](LIMITATIONS.md)** first.

## Source of truth

- **[PRD.md](PRD.md)** — feature requirements (deliverables D1/D2,
  site-file format SF1..SF3, CLI surface CLI1..CLI7, feature matrix
  F1..F12), explicit non-requirements.
- **[CHANGELOG.md](CHANGELOG.md)** — release notes per remediation
  round.
- **[LIMITATIONS.md](LIMITATIONS.md)** — known rough edges.

## What's here

| Path | Purpose |
| --- | --- |
| `forkpress/` | Rust CLI that bundles static PHP + branchfs + WP runtime into a single static binary per target. `forkpress start`, `forkpress branch`, `forkpress backup`, `forkpress export`, `forkpress import`, `forkpress user`. |
| `fileserver/` | Rust file server: SFTP / SMB2 / MySQL proxy frontends in front of the `.fp` store. |
| `ext/branchfs.c`, `ext/branchfs.h` | PHP extension: stream wrapper + `file://` override so plugins using `__DIR__` / absolute paths route through the SQLite store. |
| `scripts/branchctl.php` | CLI: create / commit / merge / rollback / reset / delete / migrate / status / diff / gc / `_ddl`. Principal-bound auth + tamper-resistant audit log. |
| `scripts/branched_pdo.php` | `BranchedPDO` extends `PDO` — DDL interception that routes DDL to the correct COW branch view/overlay. `BootstrapBranchedPDO::ensure()` is the chokepoint guarding raw-PDO regressions. |
| `scripts/cow_helpers.php` | COW branch primitives: view+overlay+tombstone construction, INSTEAD OF triggers (incl. cross-layer UNIQUE guard), AUTOINCREMENT band reservation. |
| `scripts/merge.php` | 3-way file + DB merge (rows + columns + indexes). `--strategy=abort\|ours\|theirs`, `--on-id-collision=conflict\|renumber`. |
| `scripts/launcher.php` | Single real on-disk file — verifies a signed cookie/header, activates the branch, boots WordPress from the SQLite store. |
| `scripts/init_db.php` | Creates an empty `.fp` store with all required schema. |
| `scripts/principal.php` | Principal / token mint+verify. |
| `scripts/audit_helpers.php` | Audit log writes with loud failure surface. |
| `scripts/git_server/` | Git smart-HTTP server: clone/push the WP filesystem against branchfs branches. |
| `wp-plugin/branchfs-wp.php` | WordPress mu-plugin: `pre_move_uploaded_file` hook, `filesystem_method` override, admin-bar branch indicator. |
| `e2e/` | Pytest end-to-end suite (~440 tests) covering invariants, COW semantics, merge, auth, audit, crash safety, hot backup, etc. |
| `Makefile` | Build + test targets. |

## How the plugin-compat trick works

A plain `branchfs://` stream wrapper would only intercept calls that
explicitly use the `branchfs://...` URL. WordPress plugins don't —
they use `__DIR__`, `plugin_dir_path()`, `ABSPATH . 'wp-...'`, bare
relative paths, and sometimes absolute paths like
`/var/www/html/wp-content/...`. The extension therefore does three
things:

1. Registers `branchfs://` as an explicit wrapper (for the launcher).
2. **Overrides the plain `file` wrapper** so paths under the configured
   WP root (relative or absolute, no scheme) route through the SQLite
   store: `stream_opener`, `url_stat`, directory ops, `unlink`,
   `rename`, `mkdir`, `rmdir`, `stream_metadata`.
3. Replaces `file_exists()` in the function table because `access(2)`
   bypasses PHP stream wrappers, so `file_exists()` would otherwise
   miss the virtual tree.

The PHP stat cache is cleared on every mutation so stale `stat()`
results don't leak between branches.

## Subdomain-based branch routing

No cookies — **the subdomain IS the branch**:

| URL | Branch |
| --- | --- |
| `http://wp.localhost:18080/` | `main` |
| `http://marketing.wp.localhost:18080/` | `marketing` |
| `http://feature.wp.localhost:18080/` | `feature` |

The router reads `Host:` and picks the first dot-label as the branch
name. The root host is controlled by `BRANCHFS_ROOT_HOST` (default
`wp.localhost`).

## Building & running

### Single binary (recommended)

`forkpress` packages a static PHP, the branchfs extension (compiled
into PHP as a builtin), the vendored git server, the WP bootstrap
helpers, and the Rust launcher into one self-contained binary per
target. No system PHP, no dynamic libs.

```bash
make dist           # one-time, ~3-5 min on Apple Silicon (static-php-cli)
make forkpress
./target/release/forkpress serve
```

That boots:
- main site at `http://localhost:18080/`
- branch previews at `http://<branch>.localhost:18080/`
- git remote at `http://localhost:18080/site.git`
- SFTP at `:2222`, SMB at `:8888`, MySQL at `:3306`

Branch management:

```bash
./target/release/forkpress branch create marketing
./target/release/forkpress branch list
```

Agent/worktree workflow:

```bash
./target/release/forkpress agents http://localhost:18080/site.git ./agents --count 10
cd ./agents/agent-1
# edit files with an agent
../../target/release/forkpress push -m "Agent 1 changes"
```

`forkpress serve` is an alias for `forkpress start`. `forkpress agents`
creates `agent-1` … `agent-10` ForkPress branches and matching local
git worktrees. `forkpress push` does `git add -A`, `git commit`, and
`git push` so a worktree becomes previewable over HTTP in one step.
Use normal `git pull --rebase --autostash` inside any worktree to pull
newer remote changes into that agent's branch.

Release artifacts for Linux (x86_64 + aarch64) and macOS (aarch64 +
x86_64) are produced by `.github/workflows/release.yml` on every tag.

### Native dev build

For iterating on the PHP extension without rebuilding the bundle:

```bash
sudo apt-get install php8.2-dev libsqlite3-dev build-essential pkg-config
make                # builds ext/branchfs.so
make test-all       # PHP-side tests
PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/ -m "not live"   # full e2e suite
```

The `Makefile` auto-detects includes via `php-config` and `pkg-config`.

## `branchctl`

Same operations the bundled `forkpress branch` exposes, but as a
PHP CLI for ops use:

```
list                                       # all branches with file/row counts
status <name>                              # diverged tables + overlay size
create <name> [--from P]                   # O(1) COW fork
delete <name> [--force]                    # drop overlay + branch
commit <name> [-m "msg"]                   # snapshot files + DB
log    <name> [-n N]                       # commit history
diff   <a> <b> [--rows]                    # per-table row + file deltas
merge  <from> --into <to>                  # 3-way file + DB merge
       [--strategy=abort|ours|theirs]
       [--on-id-collision=conflict|renumber]
reset    <name> <commit>                   # rewind to a specific commit
rollback <name>                            # = reset HEAD~1
migrate  <name>                            # legacy → COW (one-time)
gc       [--dry-run]                       # reclaim unreachable blobs
```

`reset` and `rollback` rewind both files AND DB atomically. They refuse
to run with uncommitted changes; pass `--force` to discard.

`merge` schema-aware diff: per-column 3-way (added/dropped/modified)
runs alongside per-row 3-way; ALTER TABLEs are sorted to land before
row upserts so a fresh column is present when source rows that
reference it apply.

## Git smart-HTTP

Developers can `git clone http://wp.localhost:18080/site.git` to get
both the file tree and a self-contained bootable SQLite copy of the
DB, edit locally, then `git push` changes back. Each git branch maps
1:1 to a branchfs branch; each git commit is paired with an
`fs_commits` snapshot.

```bash
git clone http://wp.localhost:18080/site.git wp-clone
cd wp-clone/wordpress
php -S 127.0.0.1:9080 -t .                          # boot the clone locally
git push http://admin:admin@wp.localhost:18080/site.git main
git push http://admin:admin@wp.localhost:18080/site.git main:marketing
```

Auth: read access (`upload-pack`) anonymous; push access
(`receive-pack`) HTTP basic. Set `BRANCHFS_PROD=1` to switch to
production-mode auth (no `admin/admin` default). Reserved branch
names that collide with router host parsing are refused.

## How previews work end-to-end

1. Default traffic: launcher sees no preview cookie → activates branch
   `main` → WordPress boots, reads files from the `main` overlay AND
   the `b1_wp_*` tables in the same `.fp` file.
2. Admin creates a preview via `branchctl create staging --from main`.
   COW: tables become views over an overlay + tombstone + the parent's
   data, no row copy. Files: shared blobs by content hash.
3. Admin gets a signed cookie `staging.<expires>.<hmac>` (or hits
   `staging.wp.localhost`).
4. Next request with that cookie → launcher verifies HMAC → activates
   branch `staging` → WordPress sees the staging overlay + the staging
   branch's `wp_*` views.
5. All writes land in the staging branch's overlay. Customers on `main`
   see nothing.
6. `branchctl merge staging --into main` does a 3-way file + DB merge
   (rows + columns + indexes), atomically.

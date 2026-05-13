# ForkPress

ForkPress is a single-binary local WordPress branch runner for agent work.

The product path is now the **COW materialized backend**. `forkpress init`
creates ordinary branch directories beside `.forkpress`, such as `./main` and
`./marketing`. Each branch is a normal WordPress tree with its own SQLite
database file, and branch creation uses filesystem copy-on-write when the
machine can provide it.

No Docker, no system PHP, no MySQL daemon, no FUSE service, and no helper
daemon. The release artifact is one `forkpress` binary on macOS/Linux and a
click-through installer on Windows.

## Quick Start

Start in an empty project directory:

```bash
./forkpress init
./forkpress serve
```

Open:

```text
http://wp.localhost:18080/
http://wp.localhost:18080/wp-admin/
```

The admin opens logged in by default. To use the normal WordPress login form,
start the server with:

```bash
FORKPRESS_AUTO_LOGIN=0 ./forkpress serve
```

Stop the site server and detach any mount-backed COW storage:

```bash
./forkpress stop
```

## Install

Download the archive for your machine from a release, unpack it, and run the
binary.

Windows:

1. Download `ForkPressSetup.exe` from a release.
2. Open it and follow the prompts.
3. Accept the Windows permission prompt.
4. Reboot only if Windows asks.
5. Open **Start ForkPress Site** from the desktop or Start Menu.

The Windows installer installs protected program files under
`%ProgramFiles%\ForkPress`, creates a ReFS Dev Drive VHDX at
`%ProgramData%\ForkPress\Storage\forkpress-dev-drive.vhdx`, mounts it at
`%USERPROFILE%\ForkPressDevDrive`, adds `forkpress.exe` to the user PATH, creates
`%USERPROFILE%\ForkPressDevDrive\Sites\My ForkPress Site`, runs `forkpress init`
there, and creates shortcuts. It does not require WSL, Docker, FUSE, WinFsp, or
manual Windows feature setup.

macOS:

```bash
case "$(uname -m)" in
  arm64) TARGET=aarch64-apple-darwin ;;
  x86_64) TARGET=x86_64-apple-darwin ;;
  *) echo "Unsupported Mac architecture: $(uname -m)" >&2; exit 1 ;;
esac

curl -L -o forkpress.tar.gz \
  "https://github.com/Automattic/forkpress/releases/download/<tag>/forkpress-$TARGET.tar.gz"

tar -xzf forkpress.tar.gz
chmod +x forkpress
./forkpress --version
```

Release targets:

- `x86_64-pc-windows-msvc`
- `aarch64-apple-darwin`
- `x86_64-apple-darwin`
- `aarch64-unknown-linux-musl`
- `x86_64-unknown-linux-musl`

## Work With Branches

Create a branch:

```bash
./forkpress branch create marketing
```

That creates:

```text
.forkpress/        # ForkPress metadata, runtime, logs, COW bookkeeping
main/              # main WordPress tree
marketing/         # marketing WordPress tree
```

Preview the branch:

```text
http://marketing.wp.localhost:18080/
http://marketing.wp.localhost:18080/wp-admin/
```

The WordPress admin bar shows `Branch: <name>`. Hover it to filter and switch
between local branches.

Reset a branch back to another branch:

```bash
./forkpress branch reset marketing --from main
```

That replaces `./marketing` with a fresh COW clone of `./main`, including the
branch-local SQLite database. ForkPress refuses to reset `main` unless you pass
`--force`.

## Git Workflow

ForkPress exposes a Git smart-HTTP view at:

```text
http://wp.localhost:18080/site.git
```

Clone it:

```bash
./forkpress clone http://wp.localhost:18080/site.git site
cd site
```

The checkout has this shape:

```text
site/
  database.sql        # read-only snapshot of the current branch DB
  wordpress/          # editable WordPress files
```

Switch to a ForkPress branch:

```bash
git fetch origin
git switch marketing
```

Create a Git branch from a fetched ForkPress branch and push it. ForkPress will
materialize the matching COW branch when it receives the new Git ref:

```bash
git switch -c marketing origin/main
../forkpress commit -m "create marketing branch"
```

Edit files under `wordpress/`, then push them back into the materialized COW
branch:

```bash
printf "hello from marketing\n" > wordpress/wp-content/marketing.txt
../forkpress commit -m "marketing file change"
```

Preview the pushed file:

```text
http://marketing.wp.localhost:18080/wp-content/marketing.txt
```

Delete the remote Git branch when you want to remove the matching preview
branch:

```bash
git push origin --delete marketing
```

ForkPress accepts one branch update per Git push. Push branch creates, updates,
and deletes one branch at a time.

`database.sql` is generated for model context. Edits to `database.sql` are
ignored on push; database changes should happen through WordPress, WP-CLI, or
another tool operating on the branch's own SQLite database.
The snapshot includes user tables, table rows, explicit indexes, triggers, and
views, while omitting SQLite and ForkPress driver internals. Credential-shaped
columns and key/value rows, such as WordPress password hashes, session tokens,
application passwords, and plugin API tokens, are redacted before the snapshot
is written into the Git view.
`wordpress/wp-content/database/` is private runtime state and is not part of the
Git view; ForkPress ignores pushed files under that path.
After a push, ForkPress immediately re-snapshots the branch so the remote Git
ref reflects the generated `database.sql`, not a user-edited copy. Successful
push cleanup also prunes unreachable loose objects from `.forkpress/cow/git`,
including Git snapshots left behind by deleted or force-updated preview refs.
`forkpress commit` fetches that normalized ref and fast-forwards your checkout
when possible, so generated files and ignored private runtime paths do not leave
the worktree one commit behind the preview server.

## Run Agents

With the site server running:

```bash
./forkpress agents
```

This creates ten ForkPress branches and ten Git worktrees:

```text
forkpress-agents/site
forkpress-agents/agent-1
forkpress-agents/agent-2
...
forkpress-agents/agent-10
```

Each `agent-N` worktree is checked out on its matching ForkPress branch.

Create fewer or differently named worktrees:

```bash
./forkpress agents --count 3 --prefix experiment
```

After an agent edits files:

```bash
cd forkpress-agents/experiment-1
../../forkpress commit -m "experiment 1 changes"
```

Preview it at:

```text
http://experiment-1.wp.localhost:18080/
```

## Logs And Debugging

Show WordPress critical errors and PHP fatals:

```bash
./forkpress logs --file wp
```

Follow new WordPress log output while reproducing a browser problem:

```bash
./forkpress logs --file wp --follow
```

Print every known log path:

```bash
./forkpress logs --file all --paths
```

Useful log files:

- `wp`: `.forkpress/logs/wp-debug.log`
- `php`: `.forkpress/logs/php-errors.log`
- `server`: `.forkpress/logs/php-server.log`
- `forkpress`: `.forkpress/logs/forkpress-server.log`
- `gc`: `.forkpress/logs/gc.log`

## How COW Storage Works

ForkPress records the selected storage strategy in `.forkpress/site.toml`:

```toml
version = 1
strategy = "cow"
file_view = "reflink"
```

The COW backend has three layers:

```mermaid
flowchart TB
    cli[forkpress CLI]
    server[Local PHP server<br/>wp.localhost:18080]
    git[Git smart HTTP<br/>/site.git]

    subgraph Project["Project directory"]
        main[./main<br/>WordPress files<br/>wp-content/database/.ht.sqlite]
        branch[./marketing<br/>WordPress files<br/>wp-content/database/.ht.sqlite]
        meta[.forkpress<br/>runtime, logs, site.toml]
    end

    cowgit[.forkpress/cow/git<br/>Git adapter object store]
    macos[.forkpress/macos-cow<br/>optional APFS sparsebundle]

    cli --> meta
    cli -- branch create --> branch
    server --> main
    server --> branch
    git <--> cowgit
    cowgit <--> main
    cowgit <--> branch
    macos -. physical storage when needed .-> main
    macos -. physical storage when needed .-> branch
```

The durable WordPress state for a branch is the branch directory itself. A post
save on `marketing.wp.localhost` writes to:

```text
./marketing/wp-content/database/.ht.sqlite
```

It does not write to `./main`, and it does not use SQL views, overlay tables,
or branch table prefixes.

ForkPress-served WordPress requests take a shared advisory lock at
`.forkpress/cow/operations.lock`. COW mutations such as branch create, reset,
delete, and Git apply take the same lock exclusively, so ForkPress does not
publish or remove a branch tree while one of its own HTTP requests is active.
Direct shell/editor writes to `./main` or `./marketing` are normal filesystem
writes and do not participate in that lock.

### File View Cascade

ForkPress tries the cheapest ordinary-file view first:

1. **Native filesystem cloning in the project directory.** On macOS this uses
   APFS `clonefile`; on Linux this uses `FICLONE` reflinks; on Windows this
   uses ReFS block cloning when the project lives on a ReFS/Dev Drive volume.
   New branches share unchanged file blocks with the source branch. Writes to a
   branch path do not mutate the source path.
2. **Rootless APFS sparsebundle on macOS.** If the project volume cannot clone files,
   ForkPress creates `.forkpress/macos-cow/branches.sparsebundle`, mounts it at
   `.forkpress/macos-cow/mount`, stores the physical branch trees there, and
   exposes public branch directories like `./main` and `./marketing`.
3. **Guided ReFS Dev Drive setup on Windows.** If a Windows project is not on
   clone-capable storage, the Windows installer runs the Dev Drive setup flow
   and creates ForkPress shortcuts into `%USERPROFILE%\ForkPressDevDrive`.
4. **Full file copy.** This is the final fallback when COW storage is not
   available.

Inspect the selected file view:

```bash
./forkpress storage status
./forkpress doctor storage
```

`storage status` also reports branch count, the public branch root, the physical
storage root, the COW lifecycle locks, and any leftover staging directories from
interrupted branch operations.

If a sparsebundle is attached, stop through ForkPress before deleting or moving
the project:

```bash
./forkpress stop
rm -rf .forkpress main marketing
```

On sparsebundle-backed sites, reclaim free space inside the image after branch
churn:

```bash
./forkpress storage compact
```

Compaction stops this site's server, detaches the sparsebundle, runs
`hdiutil compact`, and leaves storage detached. Run `./forkpress serve` or
`./forkpress storage mount` to attach it again.

If macOS reports the storage is busy, close terminals or editors inside
`.forkpress/macos-cow/mount` and run `./forkpress stop` again. Use
`./forkpress stop --force` only for cleanup after normal detach reports a busy
mount.

### Git Is An Interface

Git is not the source of truth. It is an editing and transport view over the
COW branch directories.

```mermaid
sequenceDiagram
    participant Agent as Agent worktree
    participant Git as Git smart HTTP
    participant Store as .forkpress/cow/git
    participant Branch as ./marketing

    Agent->>Git: clone/fetch
    Git->>Branch: snapshot wordpress/ files + database.sql
    Git->>Store: update Git objects/refs
    Store-->>Agent: Git branch

    Agent->>Agent: edit wordpress/ files
    Agent->>Git: forkpress commit
    Git->>Store: receive pushed commit
    Git->>Branch: apply wordpress/ changes
```

Before every Git request, ForkPress snapshots each branch directory into the
Git adapter store. After a push, ForkPress applies only `wordpress/` changes
back to the target branch directory, excluding private runtime paths such as
`wp-content/database/`. The branch's SQLite database remains branch-local and is
never overwritten by `database.sql`.

## Production And Dev Builds

The production binary is `forkpress`. It only exposes the materialized COW
strategy and its file-view cascade:

- macOS APFS `clonefile`;
- macOS APFS sparsebundle fallback;
- Linux `FICLONE` reflinks;
- full file-copy fallback.

Experimental storage work is compiled into `forkpress-dev`, not `forkpress`.
The dev binary enables:

- the older BranchFS/SQLite strategy;
- the Redb CAS manifest strategy;
- hidden embedded-ZFS smoke tooling. The native ZFS engine still requires an
  explicit `FORKPRESS_ENABLE_EMBEDDED_ZFS=1` build because it fetches and links
  the external OpenZFS experiment.

## Repository Layout

Production Rust packages live under `crates/`:

- `forkpress-cli`: binaries and high-level command routing;
- `forkpress-core`: shared layout, manifest, path, and strategy types;
- `forkpress-storage`: production COW branch storage, including APFS
  `clonefile`, APFS sparsebundle, Linux `FICLONE`, Windows ReFS block cloning,
  and file-copy fallback;
- `forkpress-runtime`: embedded PHP/WordPress runtime preparation and PHP
  script execution;
- `forkpress-server`: server registry, stop/list, and TCP readiness helpers;
- `forkpress-git`: Git command, ref, worktree, and push-sync helpers.

Production PHP runtime files live in `runtime/`, production/shared helper
scripts live in `scripts/`, and production COW PHP tests live in `tests/`.
Experiment-specific code lives under `experiments/`, including BranchFS, CAS
Rust crates, the experiment WordPress plugin, and the embedded-ZFS smoke
tooling.

## Commands

- `forkpress init` initializes a site. On macOS the default strategy is `cow`.
- `forkpress init --admin-password admin` creates a COW site with a known
  local admin password.
- `forkpress serve` starts the server in the background.
- `forkpress start` starts the server in the foreground.
- `forkpress stop` stops this site's server and detaches mount-backed storage.
- `forkpress stop --all` stops every running ForkPress site server for your
  user.
- `forkpress server list` lists running site servers.
- `forkpress branch list` lists local branches.
- `forkpress branch create <name> [--from main]` creates a COW branch and
  reserves moderate AUTOINCREMENT ID bands for WordPress core and arbitrary
  plugin tables that use SQLite `AUTOINCREMENT`.
- `forkpress branch reset <name> --from <source>` replaces one COW branch with
  the files and SQLite database from another branch.
- `forkpress branch merge <source> --into <target>` merges one materialized COW
  branch into another branch. WordPress and plugin tables are merged
  generically from SQLite state; branch-time AUTOINCREMENT bands keep
  independently created rows, such as posts saved through wp-admin or REST,
  from colliding across branches. If a clean source insert or source row update
  collides with a target-side unique key, including expression indexes,
  generated-column unique keys, and normal-column partial unique indexes, the
  target row is kept and the choice is recorded as an auditable
  `row-unique-collision`. If a source insert or source row update violates a
  target-side SQLite constraint, including foreign-key references after
  parent-before-child table ordering, same-table foreign-key row ordering, and
  reviewed source restores of target-dropped same-table foreign-key tables.
  Restored foreign-key child tables also validate after source-only parent
  tables materialize or after reviewers restore the parent table first; trying
  to restore the child first reports the missing parent table or parent row
  dependency before mutating target state. Source-added table rows with missing
  target-side foreign-key parents are held for the same audited row review
  instead of aborting the merge. Source-added indexes are materialized as each
  new table lands, before dependent source-added tables are processed, so
  foreign keys backed by source-added unique indexes validate without a false
  row constraint conflict. Source-added views are ordered by source-side
  view dependencies, and source-added views that need a restored target table
  are held as reviewable `schema-source-added-view` conflicts until that
  dependency validates. Cyclic source-added view graphs are also held as
  reviewable `schema-source-added-view` conflicts rather than installed in an
  arbitrary order, and reviewed source resolution keeps the audited cycle reason
  validation-gated. Missing or non-persistent view references are preflighted
  before target mutation. Target-dropped table restores defer
  source-added indexes and triggers that already have standalone schema
  conflicts, so the table can restore before those objects are resolved in
  dependency order. Source-added triggers attached to, reading, or writing
  missing target-side schema objects are held as reviewable
  `schema-source-added-trigger` conflicts instead of being installed as latent
  invalid triggers. Acyclic source-added trigger programs are ordered by their
  clear subject/write dependencies before installation, while trigger program
  cycles are held as reviewable `schema-source-added-trigger` conflicts instead
  of installing an unsupported trigger graph. Source-added or reviewed source
  triggers that would cycle with target-side trigger programs stay
  validation-gated as well, and trigger programs are compiled after
  installation so invalid target-side column references stay validation-gated too;
  statement-local CTE aliases in trigger bodies are ignored while real schema
  objects referenced inside those CTEs remain dependencies.
  Trigger references to temporary or attached SQLite schemas are kept
  validation-gated instead of being matched to same-named persistent tables.
  Quoted schema-qualified references are tracked, while schema-looking text
  inside SQL literals or comments is ignored by dependency preflight.
  Validation-gated source view rewrites also recompile preserved target view
  trigger programs before reporting dry-run or apply success, so a source view
  change cannot leave a latent invalid trigger behind.
  Validation-gated source table drops refuse to leave dependent target
  foreign-key child tables pointing at a missing parent table, including during
  dry-run previews, and also refuse to leave target trigger programs that
  still reference the dropped table. Source view drops likewise refuse to
  leave target trigger programs that still reference the dropped view, so
  table-drop chains through dependent views stay explicitly reviewable.
  When a source row still violates target constraints, target
  is kept by default and the choice is recorded as
  an auditable `row-target-constraint`. Source deletes that would orphan
  target-side foreign-key children are held the same way until a reviewed
  source delete validates, while unchanged target-side child rows that source
  deleted or reparented away from the deleted parent are applied first so
  parent-and-dependent changes land together. If source also rewrites a
  referenced child key and updates unchanged grandchildren to follow it,
  ForkPress applies the proven rewrite graph under the same validation savepoint
  before deleting the original parent. If that rewrite points at a source-only
  parent key, ForkPress materializes the audited source parent row inside the
  same savepoint before updating dependents, preserving sparse source `rowid`
  values for no-primary-key parent tables when the target rowid is free.
  Identical source/target inserts
  with the same explicit primary key, and identical source/target updates or
  deletes to existing explicit-primary-key rows, are recorded as non-conflicting
  `source-applied` decisions. Identical source/target cell changes inside an
  otherwise divergent row are also recorded as non-conflicting `source-applied`
  decisions when row identity is known. No-primary-key inserts that are already
  present in target with the same payload through a declared unique index are
  recorded as an auditable non-conflicting `source-applied` decision instead.
  Without declared unique evidence, identical-looking no-primary-key inserts
  remain separate rows so duplicate-capable plugin tables do not lose data.
  For no-primary-key plugin tables, runtime row identity tracking handles
  delete/reinsert `rowid` reuse. Source-added no-primary-key tables and
  validation-gated source table restores preserve sparse source `rowid` values,
  while validation-gated compatible table rebuilds preserve sparse target
  `rowid` values and refresh sidecar row hashes. If a direct offline edit
  changes cells on a keyless source row that target did not change while target
  also changed the prior row and no runtime identity event exists, mergeback
  keeps target by default and records an auditable
  `row-identity-ambiguous` conflict instead of mixing cells from different
  possible logical rows.
- `forkpress branch merge-audit [--format text|json] [--run ID]`
  `[--scope all|db|files] [--records all|conflicts|decisions|resolutions]`
  `[--conflict-type TYPE] [--decision DECISION] [--path PATH]`
  `[--path-prefix PREFIX] [--id-band-skips] [--target-kept] [--review]`
  `[--review-status unreviewed|pending|needs-action|reviewed]`
  `[--resolution-status validated|applied] [--group-by table|status|path|type|severity]` prints the COW merge audit log
  without opening the raw metadata database. `--records resolutions` focuses
  the report on deterministic conflict resolution records; `--group-by` adds
  compact resolution summaries for UI or assistant review. With
  `--records conflicts`, `--group-by` can summarize conflicts by table, type,
  path, or severity class. With `--records decisions`, `--group-by` can
  summarize automatic decisions by table, type, or path. `--target-kept`
  focuses the report on preserved target/trunk-side decisions.
  `--review-status unreviewed` is audit-only and returns records that have no
  review note yet; `pending`, `needs-action`, and `reviewed` match the latest
  recorded review annotation. For an active database conflict queue, combine
  `--review --review-status unreviewed --records conflicts --scope db`.
  Use the same `--records` and `--scope` shape with `--review-status pending`
  or `--review-status needs-action` to revisit annotated follow-up queues;
  later review notes supersede earlier notes for filtering.
  `needs-action` queues are intended for records that require owner follow-up
  before they can be marked reviewed. Use `--review-status reviewed` with the
  same filters as a closure report for records whose latest annotation is
  complete.
  For unreviewed deterministic resolution follow-up, use
  `--review --review-status unreviewed --records resolutions --scope db`.
  Use `--scope files` with `--records conflicts` or `--records resolutions`
  for filesystem conflict and deterministic resolution review queues.
  For unreviewed automatic decision review, use
  `--review --review-status unreviewed --records decisions --scope db` or
  `--scope files`.
- `forkpress branch merge-review conflict|decision|resolution <id> --status pending|needs-action|reviewed --note <text>`
  `[--reviewer NAME]` appends a review note to an auditable merge conflict,
  decision, or deterministic resolution record.
- `forkpress branch merge-resolve conflict <id> --choice source|target [--apply]`
  `[--note TEXT] [--reviewer NAME]` validates an audited DB cell, row
  insert-collision, row-unique-collision, row-target-constraint,
  row-identity-ambiguous, row-target-deleted, row-source-deleted, or filesystem
  path conflict, plus validation-gated schema conflicts. DB conflicts work for
  explicit primary keys and no-primary-key tables with sidecar row identity.
  `row-unique-collision` source choices replace the still-matching target row
  that owns the colliding unique key, or for audited source-update collisions
  remove that target row and update the original source-identity row; target
  remains the default choice unless a reviewer applies a source resolution.
  Schema source choices can apply safe source-added columns, indexes, views,
  and triggers; source index/view/trigger rewrites or drops; source table drops
  that do not leave dependent target views invalid, implicitly remove target
  indexes/triggers, leave target trigger programs referencing the dropped
  table, or leave dependent target foreign-key child tables pointing
  at a missing parent table; source table restores when target dropped a table
  that source kept; and compatible table rebuilds that preserve target rows and
  target indexes/triggers while changing audited non-primary-key column
  definitions. Source index choices validate against current target rows and
  target foreign-key integrity during dry-run and apply, so uniqueness,
  expression-index, or latent foreign-key mismatch failures remain
  validation-gated until reviewers address the blocking target data/schema.
  Compatible table rebuilds run the same target foreign-key integrity check
  before dry-run or apply reports success.
  Source table restores recreate the audited source table and copy
  source rows after validating that the target table is still absent, preserving
  sparse source `rowid` values for no-primary-key tables, then restore source
  indexes/triggers that were removed as a side effect of the target table drop.
  Restore previews and applies also validate target foreign-key integrity and
  compile restored trigger programs before recording a successful resolution.
  No-primary-key sidecar identities for target rows are tombstoned when table
  drop/restore resolutions remove or recreate the target table, so later rowid
  reuse receives fresh logical identity metadata. Safe source-added column
  resolutions refresh no-primary-key sidecar row hashes immediately after the
  target row shape changes.
  Source-added table creation is recorded as a schema-level source-applied
  decision even when the table has no rows.
  Identical source/target table, index, view, and trigger schema changes are
  also recorded as non-conflicting `source-applied` decisions instead of being
  left as implicit no-ops. Matching source/target column additions inside an
  otherwise divergent table schema are recorded the same way.
  Target-only row inserts, row deletes, and cell changes are preserved and
  recorded as `target-kept` decisions so clean trunk/main-side data changes are
  auditable alongside schema changes. Matching source/target row updates and
  deletes are recorded as `source-applied` no-ops when explicit primary keys or
  sidecar no-primary-key identity prove they refer to the same logical row.
  Target-only schema additions and rewrites are preserved and recorded as
  `target-kept` decisions so clean trunk/main-side DDL remains auditable.
  Target-only filesystem additions, deletions, and path changes are also
  preserved and recorded as `target-kept` decisions.
  Compatible rebuilds also preserve
  dependent target views when those views validate before and after the rebuild.
  Source view rewrites preserve transitive dependent target views and their
  triggers when they validate before and after the rewrite; source view drops
  are blocked while dependent target views or triggers still reference the
  dropped view, including triggers on other tables whose bodies read from it.
  With `--apply`,
  ForkPress records the deterministic resolution in merge metadata and appends
  a reviewed annotation to the conflict audit record. Reruns after a reviewed
  target choice keep the original conflict and resolution audit records, but
  treat that unchanged divergence as accepted and record a `target-accepted`
  decision instead of reporting it as a fresh active conflict. Audit run and
  decision-group summaries count those accepted target decisions separately from
  active `target-wins` defaults.
- `forkpress branch show <name>` prints the branch directory, database, file
  count, and Git ref path.
- `forkpress branch delete <name>` removes a COW branch. `main` cannot be
  deleted.
- `forkpress clone [remote] [dir]` wraps `git clone`.
- `forkpress agents [dir] --count 10 --prefix agent` creates agent branches
  and worktrees.
- `forkpress commit -m "message"` stages, commits, and pushes the current Git
  branch back into ForkPress.
- `forkpress pull` wraps `git pull --rebase --autostash`.
- `forkpress logs --file wp|php|server|forkpress|gc|all` prints logs.
- `forkpress storage status|mount|detach|compact` diagnoses or manually manages
  detachable COW storage.
- `forkpress doctor storage` probes local filesystem clone support.

## Build From Source

```bash
make dist
make forkpress
```

`make dist` builds the production static PHP runtime. `make forkpress` embeds
that runtime and the PHP/WordPress assets into the production Rust binary.

Developer experiment build:

```bash
make dist-dev
make forkpress-dev
```

`make dist-dev` adds the experimental BranchFS/CAS PHP runtime support, and
`make forkpress-dev` builds the Rust binary with the `dev-experiments` Cargo
feature. The production wrapper rejects `dev-experiments`; use
`--bin forkpress-dev` whenever that feature is enabled.

For fast Rust-only checks without rebuilding PHP:

```bash
cargo test --workspace --exclude forkpress-cli
cargo test -p forkpress-core --features dev-experiments
FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli
FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli --features dev-experiments --bin forkpress-dev
```

PHP unit tests:

```bash
make test-cow
make test-branchfs
make test-all
```

`tests/` contains production COW tests. Experiment-specific runtime files,
Rust crates, and tests live with their experiment code under `experiments/`.
There are no generic PHP tests shared by both storage families yet; common
behavior is covered through the COW and experiment-specific suites.

## Publish

Push a version tag:

```bash
git tag v0.1.13
git push origin v0.1.13
```

The release workflow builds and uploads the target archives listed above.

# ForkPress

ForkPress is a single-binary local WordPress branch runner for agent work.
The default `branchfs` storage strategy stores a whole site in one `.fp`
SQLite file, serves each branch on its own local subdomain, and exposes the
WordPress file tree through Git so multiple agents can work in separate
directories.

## What You Get

- `forkpress init` creates the local site store using the default `branchfs`
  strategy.
- `forkpress init --strategy zfs` records an experimental ZFS strategy choice
  for a new work directory. The ZFS HTTP/Git backend is not wired yet.
- `forkpress server start` starts the preview server in the background.
- `forkpress server list` shows running site servers.
- `forkpress server stop` stops the current site's server.
- `forkpress logs --file wp` shows WordPress debug output and fatal errors.
- `http://wp.localhost:18080/` serves `main`.
- `http://agent-1.wp.localhost:18080/` serves branch `agent-1`.
- `forkpress clone` clones the site files from `http://wp.localhost:18080/site.git`.
- `forkpress agents` creates 10 branches and 10 Git worktrees by default.
- `forkpress commit` stages, commits, and pushes a worktree so it is previewable.
- `database.sql` appears in every branch checkout as a read-only snapshot of that
  branch's WordPress tables for model context.

No FUSE, no Docker, no external daemon sidecars, no system PHP. The release
artifact is one `forkpress` binary per target. Git is still used as the local
worktree tool.

## Install

Download the archive for your platform from a tagged GitHub release, unpack it,
and put the `forkpress` binary on your `PATH`.

Release targets:

- `x86_64-unknown-linux-musl`
- `aarch64-unknown-linux-musl`
- `x86_64-apple-darwin`
- `aarch64-apple-darwin`

Example:

```bash
tar -xzf forkpress-aarch64-apple-darwin.tar.gz
chmod +x forkpress
./forkpress --help
```

## Run A Site

Start in an empty project directory. The `--admin-password admin` value makes
the Git push examples below work without a credential prompt; use a stronger
password for anything beyond a local throwaway site.

```bash
forkpress init --admin-password admin
forkpress server start
```

The first server start imports WordPress into `.forkpress/site.fp`, writes the
`.forkpress/site.toml` strategy manifest if it does not exist yet, installs the
SQLite database drop-in, creates the WordPress admin user, and starts the local
server.

List running site servers:

```bash
forkpress server list
```

Stop this site's server from the same project directory:

```bash
forkpress server stop
```

Stop every ForkPress site server started by your user:

```bash
forkpress server stop --all
```

View WordPress critical errors and PHP fatals:

```bash
forkpress logs --file wp
```

Follow new WordPress log output while you reproduce a problem in the browser:

```bash
forkpress logs --file wp --follow
```

Print every known log path:

```bash
forkpress logs --file all --paths
```

Useful log files:

- `wp`: `.forkpress/logs/wp-debug.log`, WordPress debug output and fatal errors.
- `php`: `.forkpress/logs/php-errors.log`, PHP `error_log` output.
- `server`: `.forkpress/logs/php-server.log`, PHP built-in server access/output.
- `forkpress`: `.forkpress/logs/forkpress-server.log`, background server wrapper.
- `gc`: `.forkpress/logs/gc.log`, background branch garbage collection.

Open:

```text
http://wp.localhost:18080/
```

WordPress admin opens logged in by default:

```text
http://wp.localhost:18080/wp-admin/
```

Set `FORKPRESS_AUTO_LOGIN=0` before starting the server if you want the normal
WordPress login form. The example above creates `admin` with password `admin`.

Branch previews use subdomains:

```text
http://<branch>.wp.localhost:18080/
```

The WordPress admin bar shows the current branch. Hover `Branch: <name>` to
filter and switch to another local branch; the menu shows up to 20 matches.

If your resolver does not handle `*.localhost`, add host entries or run
`forkpress server start --root-host wp.local` and route `wp.local` plus the
branch subdomains to `127.0.0.1`.

## Architecture Overview

Every ForkPress work directory has `.forkpress/site.toml`, a small manifest
that records which storage strategy the site uses. Existing sites created
before the manifest existed are treated as `branchfs` sites when
`.forkpress/site.fp` is present.

The default strategy is `branchfs`. In that strategy, the durable site artifact
is `.forkpress/site.fp`: a SQLite database containing the WordPress file tree,
WordPress database tables, branch metadata, Git-facing file snapshots, users,
and site config. The downloaded `forkpress` executable carries the PHP runtime,
WordPress source, ForkPress PHP scripts, the WordPress SQLite integration
plugin, and the native `branchfs` PHP extension.

This overview uses the same order as most useful architecture docs: first the
system context, then the building blocks, then the important runtime and storage
flows. The goal is to make clear what owns state and what is only an interface.

There is no Dolt server in the current `branchfs` architecture. There is also
no MySQL daemon, FUSE mount, Samba share, Docker service, or long-lived helper
process besides the optional background ForkPress server you start. WordPress
still issues MySQL-shaped queries, but the bundled SQLite integration
translates them to SQLite and stores the data in `.forkpress/site.fp`.

### System Context

```mermaid
flowchart LR
    user[Developer or agent] --> cli[forkpress CLI]
    browser[Browser] --> http[Local HTTP server<br/>wp.localhost:18080]
    git[Git client] --> smart[Git smart HTTP<br/>/site.git]

    cli --> manifest[.forkpress/site.toml<br/>strategy manifest]
    manifest --> fp[(.forkpress/site.fp<br/>branchfs site store)]
    http --> fp
    smart --> fp

    cli --> worktree[Git worktrees<br/>wordpress/ files]
    git --> worktree
```

ForkPress gives different tools different views of the same local site:

- The browser gets a normal WordPress site per branch:
  `wp.localhost` is `main`, and `<branch>.wp.localhost` is that branch.
- Git gets a temporary repository synthesized from `.forkpress/site.fp` for
  `branchfs` sites.
- Agents get editable worktrees containing `wordpress/` files plus a
  read-only `database.sql` snapshot for context.
- WordPress gets a PHP document root at `.forkpress/wproot`, but in the
  `branchfs` strategy file reads and writes are intercepted and resolved from
  the current branch in `site.fp`.

### Storage Strategies

ForkPress now treats storage as a site-level strategy:

```text
.forkpress/
  site.toml
```

```toml
version = 1
strategy = "branchfs"
```

The selected strategy is written during `forkpress init` and reused by later
commands. This keeps future backends from accidentally running BranchFS-specific
code against a different storage model.

Supported strategy values:

- `branchfs` (default, aliases: `sqlite`, `sqlite-cow`): current production
  strategy. Files live in BranchFS tables, and WordPress database branches use
  SQLite COW views, overlays, tombstones, and triggers.
- `zfs`: experimental strategy marker. `forkpress init --strategy zfs` records
  the strategy and writes `.forkpress/zfs/README.md`, but HTTP serving, branch
  operations, and Git protocol integration intentionally refuse for now instead
  of pretending to be ZFS.

### ZFS Shipping Plan

ForkPress can ship ZFS without a system ZFS install by embedding a
ForkPress-specific OpenZFS engine compiled to WebAssembly/WASI:

- The release artifact remains one `forkpress` binary. Cargo embeds
  `zfsengine.wasm` the same way it embeds the PHP runtime payload.
- The Rust binary embeds a Wasm runtime and calls the ZFS engine in process.
- The durable storage is a sparse `.forkpress/zfs/pool.img` file.
- The ZFS engine opens that pool image through WASI file APIs, so there is no
  kernel module, FUSE mount, Samba share, Docker service, Node runtime, or
  system ZFS dependency.
- OpenZFS code remains in the Wasm module rather than being native-linked into
  the Rust binary. The project still needs a license review because OpenZFS is
  CDDL and ForkPress is GPL-2.0.

The browser demo at
[OpenZFS WebAssembly experiment](https://adamziel.github.io/experiments/real-zfs/)
proves the primitives are viable: real pools, datasets, snapshots, and clones
on a file-backed pool. That artifact is not the one ForkPress should embed
directly because it is Emscripten JS plus a threaded Wasm module that expects a
browser or Node worker runtime. ForkPress should instead build a headless
WASI module with a small exported C ABI.

The intended ZFS strategy is different from BranchFS:

- one ZFS dataset per ForkPress branch
- branch creation = snapshot parent + clone snapshot
- WordPress files and the SQLite database file are versioned together inside
  the branch dataset
- no SQL-level overlays, COW views, tombstones, or branch table prefixes
- Git clone/fetch materializes `wordpress/` and `database.sql` from the ZFS
  branch dataset
- Git push writes file changes into the target ZFS dataset and snapshots the
  result

Because the ZFS pool is inside a normal file and there is no FUSE/kernel mount,
PHP cannot directly access dataset files. The HTTP backend should therefore use
materialized branch working directories as caches:

```mermaid
flowchart LR
    pool[(.forkpress/zfs/pool.img<br/>OpenZFS pool)]
    engine[zfsengine.wasm<br/>WASI module]
    cache[.forkpress/zfs/worktrees/feature<br/>materialized cache]
    php[Bundled PHP + WordPress]
    git[Git endpoint]

    pool <--> engine
    engine --> cache
    cache --> php
    php --> cache
    cache --> engine
    git <--> engine
```

For a request, ForkPress exports the requested branch dataset into its cache,
runs WordPress against ordinary files and an ordinary SQLite database file, then
imports changed files and the database file back into the branch dataset under
a branch lock. Git uses the same engine path, not BranchFS tables.

### Building Blocks

The working backend today is `branchfs`, so the detailed building-block and
request-flow diagrams below describe that strategy.

```mermaid
flowchart TB
    subgraph Binary["forkpress static binary"]
        rust[Rust CLI<br/>forkpress/src/main.rs]
        bundle[Runtime payload<br/>embedded into the binary]
    end

    subgraph Runtime[".forkpress/runtime"]
        php[Static PHP binary<br/>branchfs built in]
        router[runtime/router.php]
        ctl[scripts/branchctl.php<br/>merge/reset/gc/users]
        gitserver[scripts/git_server/server.php]
        sqlitewp[WordPress SQLite integration]
        wp[WordPress source]
    end

    subgraph Store[".forkpress/site.fp"]
        meta[branches, users, site_config]
        files[files, blobs, blob_chunks]
        fscommits[fs_commits, fs_commit_files]
        tables[bN_wp_* tables/views]
    end

    rust --> bundle
    bundle --> Runtime
    rust --> php
    php --> router
    php --> ctl
    router --> sqlitewp
    router --> gitserver
    router --> wp
    router --> Store
    ctl --> Store
    gitserver --> Store
```

The important pieces are:

- `forkpress`: Rust wrapper that unpacks the embedded runtime, starts/stops the
  local PHP server, runs local control scripts, and wraps common Git workflows.
- Runtime payload: build-time archive embedded into the `forkpress` executable.
  Cargo builds it from the bundled PHP binary, PHP scripts, WordPress archive,
  SQLite integration plugin, SQL files, and mu-plugin. On first use, ForkPress
  unpacks it into `.forkpress/runtime`.
- `branchfs`: native PHP extension compiled into the bundled PHP binary. It
  provides the `branchfs://<branch>/path` stream wrapper, file operation
  interception for WordPress-style absolute paths, and SQLite-backed file store
  access. It is a PHP extension using SQLite internally, not a separate SQLite
  loadable extension that users install.
- `runtime/router.php`: resolves the branch from the host name, activates
  `branchfs`, sets the branch-specific WordPress table prefix, handles Git
  smart-HTTP routes, and boots WordPress through branch-scoped paths so OPcache
  keys compiled PHP per branch.
- `scripts/branchctl.php` and related scripts: create branches, merge/reset
  branch state, garbage collect unreachable file blobs, manage users, and repair
  COW database objects.
- `scripts/git_server/server.php`: builds a temporary Git repository from
  BranchFS snapshots for clone/fetch/push. It is a Git protocol adapter, not the
  source of truth.
- WordPress SQLite integration: the managed `wp-content/db.php` drop-in loads
  the bundled plugin. It translates WordPress's MySQL dialect to SQLite/PDO.
  ForkPress patches that path so writes through branch COW views work.

### SQLite And Extensions

ForkPress does not require users to install a SQLite extension. SQLite is the
on-disk store and query engine inside `.forkpress/site.fp`, accessed from the
bundled PHP runtime through PHP's SQLite APIs and PDO SQLite.

The custom native code is `branchfs`, a PHP extension compiled into the bundled
PHP binary for release builds. In local developer builds, tests may load
`ext/branchfs.so`, but release archives still ship a single `forkpress`
executable.

The WordPress SQLite integration plugin is PHP code, not a SQLite extension. It
parses and rewrites MySQL-flavored WordPress queries into SQLite-compatible SQL.
ForkPress then relies on ordinary SQLite tables, views, indexes, triggers, and
transactions to implement branch isolation.

### What Git Means Here

Git is the editing and transport interface for files. It is not where the live
site is stored.

```mermaid
sequenceDiagram
    participant Agent as Agent worktree
    participant Git as git clone/push
    participant Server as ForkPress Git endpoint
    participant Store as site.fp

    Agent->>Git: forkpress clone / git switch branch
    Git->>Server: upload-pack / info refs
    Server->>Store: materialize fs_commits + current branch files
    Store-->>Server: wordpress/ files + database.sql snapshot
    Server-->>Agent: temporary Git repository

    Agent->>Agent: edit wordpress/ files
    Agent->>Git: forkpress commit
    Git->>Server: receive-pack
    Server->>Store: apply wordpress/ file changes to branch overlay
    Server->>Store: record fs_commit snapshot
```

On clone/fetch, ForkPress materializes Git commits from `fs_commits` and the
current file tree in `site.fp`. Each Git branch corresponds to a ForkPress
branch. Every commit contains:

- `wordpress/`: editable WordPress files for that branch.
- `database.sql`: a generated, read-only SQL dump of the branch's WordPress
  tables for model context.

On push, only files under `wordpress/` are applied back to `site.fp`.
`database.sql` is intentionally ignored. Database changes should be made by
loading the branch in WordPress, not by editing SQL dumps.

### What Dolt Means Here

Dolt is not part of the shipped runtime. Earlier design notes may mention a
Dolt-backed MySQL-compatible branch database, but the current working
`branchfs` implementation uses SQLite only:

- no `dolt sql-server`
- no MySQL port
- no `database/branch` connection syntax
- no Dolt commits or Dolt merges

BranchFS branch isolation is implemented inside SQLite with per-branch tables,
views, overlays, tombstones, and control scripts.

### Branch Storage

`site.fp` has two branch-aware storage layers.

```mermaid
flowchart TB
    subgraph FileLayer["File layer"]
        blobs[blobs/blob_chunks<br/>content-addressed file bytes]
        mainFiles[files rows for main]
        branchFiles[files rows for feature<br/>overrides and tombstones]
        resolved[resolved branch tree]
        commits[fs_commits<br/>snapshots for Git]
        blobs --> mainFiles
        blobs --> branchFiles
        mainFiles --> resolved
        branchFiles --> resolved
        resolved --> commits
    end

    subgraph DbLayer["WordPress database layer"]
        mainTable[b1_wp_posts<br/>main real table]
        view[b8_wp_posts<br/>branch COW view]
        overlay[b8_wp_posts__overlay<br/>branch-local changed rows]
        tomb[b8_wp_posts__tombstones<br/>branch-local deletes]
        trig[INSTEAD OF triggers]
        mainTable --> view
        overlay --> view
        tomb --> view
        trig --> overlay
        trig --> tomb
    end
```

Files are copy-on-write at the path level:

- `blobs` stores file content by hash. Large blobs are split into
  `blob_chunks`.
- `files` stores per-branch path metadata. A row with a `blob_hash` overrides
  the parent. A row with `blob_hash = NULL` is a tombstone delete.
- `fs_commits` and `fs_commit_files` store full file-tree snapshots used to
  build Git history and roll back file pushes.

WordPress database tables are copy-on-write at the row level:

- `main` uses real tables named like `b1_wp_posts`.
- A branch gets logical table names like `b8_wp_posts`.
- Those branch logical tables are SQLite views over the parent table plus
  branch-local overlay/tombstone tables.
- `INSTEAD OF INSERT/UPDATE/DELETE` triggers redirect writes on the view into
  the branch overlay and tombstones.
- The SQLite integration's MySQL compatibility tables are updated so WordPress
  still sees normal WordPress tables and indexes.

This means a branch can edit files, options, posts, users, plugin state, and
schema without changing `main` or sibling branches. Merging is explicit.

### Request Flow

```mermaid
sequenceDiagram
    participant Browser
    participant Router as router.php
    participant BranchFS as branchfs extension
    participant WP as WordPress
    participant SQLite as SQLite integration/PDO
    participant Store as site.fp

    Browser->>Router: GET http://feature.wp.localhost:18080/wp-admin/
    Router->>Store: look up branch id for feature
    Router->>BranchFS: set db, root, branch; activate interception
    Router->>WP: require branchfs://feature/index.php
    WP->>BranchFS: read PHP/theme/plugin files
    BranchFS->>Store: resolve inherited files + feature overrides
    WP->>SQLite: run MySQL-shaped wpdb queries with b8_wp_ prefix
    SQLite->>Store: read/write SQLite COW views and overlays
    Store-->>Browser: rendered WordPress response
```

When a branch writes a post or option, WordPress writes to `b8_wp_*` tables for
that branch. Those names are views, so the write is captured by triggers and
stored in `b8_wp_*__overlay` or `b8_wp_*__tombstones`. Reads combine inherited
parent rows with those branch-local rows.

### Local Directory Layout

```text
.forkpress/
  site.toml                       # storage strategy manifest
  site.fp                         # branchfs strategy durable site store
  runtime/                        # unpacked embedded PHP, scripts, WP source
  wproot/                         # PHP server document root
  zfs/                            # experimental ZFS strategy notes/state
  logs/
    wp-debug.log                  # WordPress fatal/errors
    php-errors.log                # PHP error_log target
    php-server.log                # PHP built-in server output
    forkpress-server.log          # background wrapper output
    gc.log                        # background branch GC
  server.pid                      # server process marker
```

Managed WordPress files such as `wp-config.php`, `wp-content/db.php`, the
SQLite integration plugin, and the `branchfs-wp.php` mu-plugin live inside the
BranchFS store. On runtime upgrades, ForkPress refreshes those managed files so
existing `.fp` sites use the SQLite adapter bundled with the current binary.

## Work On One Branch

From the same project directory:

```bash
forkpress branch create agent-1
forkpress clone http://admin:admin@wp.localhost:18080/site.git site
cd site
git switch agent-1
```

The checkout has this shape:

```text
site/
  database.sql        # read-only branch database snapshot
  wordpress/          # editable WordPress files
```

Make a file change under `wordpress/`, then publish it back to ForkPress:

```bash
printf "hello from agent-1\n" > wordpress/wp-content/agent-1.txt
forkpress commit -m "agent-1 file change"
```

Preview the branch:

```text
http://agent-1.wp.localhost:18080/wp-content/agent-1.txt
```

Open the branch admin without logging in:

```text
http://agent-1.wp.localhost:18080/wp-admin/
```

Pull newer branch state into the checkout:

```bash
forkpress pull
```

## Run 10 Agents

With the site server running, create 10 branches and 10 Git worktrees:

```bash
forkpress agents \
  --remote http://admin:admin@wp.localhost:18080/site.git
```

This creates:

```text
forkpress-agents/site
forkpress-agents/agent-1
forkpress-agents/agent-2
...
forkpress-agents/agent-10
```

Each `agent-N` directory is a Git worktree on its own ForkPress branch.

After an agent edits files:

```bash
cd forkpress-agents/agent-1
forkpress commit -m "agent 1 changes"
```

Preview it at:

```text
http://agent-1.wp.localhost:18080/
```

Create fewer or differently named worktrees:

```bash
forkpress agents \
  --remote http://admin:admin@wp.localhost:18080/site.git \
  --count 3 \
  --prefix experiment
```

## Commands

- `forkpress init --admin-password admin` creates `.forkpress/site.toml`,
  `.forkpress/site.fp`, and a Git push user named `admin` using the default
  `branchfs` strategy.
- `forkpress init --strategy zfs` records an experimental ZFS strategy for a
  new work directory. Commands that need HTTP, Git, or branch operations will
  refuse until the ZFS backend is implemented.
- `forkpress server start` imports and boots WordPress if needed, then serves
  HTTP and Git from `.forkpress/site.fp` in the background for `branchfs` sites.
- `forkpress start --background` is the equivalent lower-level command.
- `forkpress server list` shows running ForkPress site servers.
- `forkpress server stop [--work-dir .forkpress]` stops one site server;
  `forkpress server stop --all` stops every running ForkPress site server in
  the registry.
- `forkpress logs [--file wp|php|server|forkpress|gc|all] [-n 80] [--follow]`
  prints local site logs. Use `--paths` to list log locations.
- `forkpress branch create <name> [--from main]` creates a ForkPress branch
  from the local site control command.
- `forkpress git branch create <name> [--from main]` is a compatibility alias
  for local branch creation.
- `forkpress clone <remote> [dir]` wraps `git clone`.
- `forkpress commit -m "message"` stages, commits, and pushes the current Git
  branch back into ForkPress.
- `forkpress pull` wraps `git pull --rebase --autostash`.
- `forkpress user add/list/remove/verify` manages Git push users.

`database.sql` in Git checkouts is for model context only. Edits to
`database.sql` are ignored on push; database writes should happen through the
running WordPress preview.

## Build From Source

```bash
make dist
make forkpress
```

`make dist` builds a static PHP runtime with the `branchfs` extension compiled
in. `make forkpress` embeds that runtime and the PHP/WordPress assets into the
Rust binary.

For fast Rust-only checks without rebuilding PHP:

```bash
FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress
```

PHP unit tests still use the local development extension:

```bash
make test-all
```

## Publish

Push a version tag:

```bash
git tag v0.1.9
git push origin v0.1.9
```

The release workflow builds and uploads the four target archives listed above.

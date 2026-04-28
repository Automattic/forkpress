# ForkPress

ForkPress is a single-binary local WordPress branch runner for agent work.
It stores a whole site in one `.fp` SQLite file, serves each branch on its own
local subdomain, and exposes the WordPress file tree through Git so multiple
agents can work in separate directories.

## What You Get

- `forkpress init` creates the local site store.
- `forkpress server start` starts the preview server in the background.
- `forkpress server list` shows running site servers.
- `forkpress server stop` stops the current site's server.
- `http://wp.localhost:18080/` serves `main`.
- `http://agent-1.wp.localhost:18080/` serves branch `agent-1`.
- `forkpress clone` clones the site files from `http://wp.localhost:18080/site.git`.
- `forkpress agents` creates 10 branches and 10 Git worktrees by default.
- `forkpress commit` stages, commits, and pushes a worktree so it is previewable.
- `database.sql` appears in every branch checkout as a read-only snapshot of that
  branch's WordPress tables for model context.

No FUSE, no Docker, no daemon sidecars, no system PHP. The release artifact is
one `forkpress` binary per target. Git is still used as the local worktree tool.

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

The first server start imports WordPress into `.forkpress/site.fp`, installs the
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

## How ForkPress Stores A Site

ForkPress keeps the runtime files outside your worktrees and stores site state
in `.forkpress/site.fp`.

```mermaid
flowchart LR
    bin[forkpress binary] --> runtime[.forkpress/runtime<br/>embedded PHP, WordPress, scripts]
    bin --> root[.forkpress/wproot<br/>server document root]
    bin --> store[.forkpress/site.fp<br/>SQLite site store]
    server[PHP server] --> router[runtime/router.php]
    router --> main[wp.localhost<br/>main]
    router --> branch["<branch>.wp.localhost<br/>branch preview"]
    router --> store
    clone[Git checkout] --> files[wordpress/<br/>editable files]
    clone --> snapshot[database.sql<br/>read-only context snapshot]
    files --> push[forkpress commit]
    push --> store
```

Inside `site.fp`, files and database tables are branch-scoped.

```mermaid
flowchart TB
    subgraph Files
        mainFiles[main file rows] --> branchRead[branch reads inherited files]
        branchOverlay[branch file overlay/tombstones] --> branchTree[resolved branch tree]
        branchRead --> branchTree
    end

    subgraph Database
        mainTable[b1_wp_posts table] --> cowView[b3_wp_posts COW view]
        overlay[b3_wp_posts__overlay] --> cowView
        tombstones[b3_wp_posts__tombstones] --> cowView
    end

    mainSite[wp.localhost] --> mainFiles
    mainSite --> mainTable
    branchSite[feature.wp.localhost] --> branchTree
    branchSite --> cowView
```

Changing a branch writes only that branch's file overlay and COW database
overlay. `main` and sibling branches keep reading their own rows until you
explicitly merge or reset with ForkPress commands.

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

- `forkpress init --admin-password admin` creates `.forkpress/site.fp` and a
  Git push user named `admin`.
- `forkpress server start` imports and boots WordPress if needed, then serves
  HTTP and Git from `.forkpress/site.fp` in the background.
- `forkpress start --background` is the equivalent lower-level command.
- `forkpress server list` shows running ForkPress site servers.
- `forkpress server stop [--work-dir .forkpress]` stops one site server;
  `forkpress server stop --all` stops every running ForkPress site server in
  the registry.
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
git tag v0.1.7
git push origin v0.1.7
```

The release workflow builds and uploads the four target archives listed above.

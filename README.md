# ForkPress

ForkPress is a single-binary local WordPress branch runner for agent work.
It stores a whole site in one `.fp` SQLite file, serves each branch on its own
local subdomain, and exposes the WordPress file tree through Git so multiple
agents can work in separate directories.

## What You Get

- `forkpress init` creates the local site store.
- `forkpress serve` starts the preview server.
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

Download the archive for your platform from a tagged GitHub release and put the
`forkpress` binary on your `PATH`.

Release targets:

- `x86_64-unknown-linux-musl`
- `aarch64-unknown-linux-musl`
- `x86_64-apple-darwin`
- `aarch64-apple-darwin`

## Run A Site

```bash
forkpress init
forkpress serve
```

Open:

```text
http://wp.localhost:18080/
```

Branch previews use subdomains:

```text
http://<branch>.wp.localhost:18080/
```

If your resolver does not handle `*.localhost`, add host entries or run
`forkpress serve --root-host wp.local` and route `wp.local` plus the branch
subdomains to `127.0.0.1`.

## Run 10 Agents

With `forkpress serve` running:

```bash
forkpress agents
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

Pull newer branch state into any worktree:

```bash
forkpress pull
```

Create one branch manually:

```bash
forkpress git branch create feature-a
forkpress clone
cd site
git checkout feature-a
```

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
git tag v0.1.0
git push origin v0.1.0
```

The release workflow builds and uploads the four target archives listed above.

# ForkPress

> Branch any WordPress site like Git. One static binary.

ForkPress is a copy-on-write WordPress runtime with Git-style branches,
per-branch databases, a Git smart-HTTP remote, and agent worktrees. It ships
as a single binary — no Docker, no MySQL, no daemons, no FUSE.

[**Documentation**](https://automattic.github.io/forkpress/) ·
[**Download**](https://github.com/Automattic/forkpress/releases/latest) ·
[Quick start](#quick-start) ·
[Commands](#commands)

---

## Why ForkPress

- **Real branches, instantly.** Each branch is a materialized WordPress
  directory with its own SQLite database. Created in milliseconds through
  filesystem copy-on-write — APFS `clonefile`, Linux reflinks, ReFS block
  cloning.
- **A preview URL per branch.** Open `marketing.wp.localhost:18080` and you
  are on the `marketing` branch. The WordPress admin bar shows the current
  branch and lets you switch.
- **Merges with an audit trail.** Files and databases reconcile together.
  Per-branch AUTOINCREMENT bands keep IDs stable across merges — even when
  they live in serialized options, blocks, JSON, or plugin data.
- **Git, transparently.** Every site is a Git smart-HTTP remote. Clone,
  edit, `forkpress commit`, see the branch live.
- **Built for agents.** `forkpress agents` spins up ten preview branches
  and matching Git worktrees. Drop one agent per worktree.
- **Zero runtime dependencies.** One binary embeds PHP and WordPress. No
  Docker, no system PHP, no MySQL, no service sidecars.

## Quick start

```bash
mkdir my-site && cd my-site
forkpress init      # create the site
forkpress serve     # start the preview server in the background
```

Open the preview at <http://wp.localhost:18080/>. The admin at
<http://wp.localhost:18080/wp-admin/> lands logged in by default — pass
`FORKPRESS_AUTO_LOGIN=0` to require the normal WordPress login form.

Branch the site:

```bash
forkpress branch create marketing
```

Preview the branch at <http://marketing.wp.localhost:18080/>. Edit posts,
toggle plugins, install themes — only `marketing` is affected.

Merge it back when you're done:

```bash
forkpress branch merge marketing --into main
```

Stop the server:

```bash
forkpress stop
```

## How it works

A ForkPress site is just a directory. Each branch lives beside the others
with its own WordPress tree and SQLite database:

```text
my-site/
├── .forkpress/        # runtime, logs, storage bookkeeping
├── main/              # main branch: WordPress files + .ht.sqlite
└── marketing/         # marketing branch: WordPress files + .ht.sqlite
```

A request to `marketing.wp.localhost:18080` writes to
`./marketing/wp-content/database/.ht.sqlite`. It cannot touch `main`.

New branches are created with native copy-on-write: new files start out
sharing physical blocks with the source and diverge only on write. There is
no overlay, no SQL view, no namespace remapping — just ordinary directories
your tools already understand.

See [docs/architecture.md](docs/architecture.md) and
[docs/storage/overview.md](docs/storage/overview.md) for the details.

## Install

Download the release for your platform from
[GitHub Releases](https://github.com/Automattic/forkpress/releases).

| Platform | Artifact |
| --- | --- |
| macOS · Apple silicon | `forkpress-aarch64-apple-darwin.tar.gz` |
| macOS · Intel | `forkpress-x86_64-apple-darwin.tar.gz` |
| Linux · ARM64 | `forkpress-aarch64-unknown-linux-musl.tar.gz` |
| Linux · x86_64 | `forkpress-x86_64-unknown-linux-musl.tar.gz` |
| Windows · x86_64 | `ForkPressSetup.exe` (installer) |

### macOS and Linux

```bash
curl -L -o forkpress.tar.gz \
  "https://github.com/Automattic/forkpress/releases/latest/download/forkpress-<target>.tar.gz"
tar -xzf forkpress.tar.gz
chmod +x forkpress
./forkpress --version
```

Replace `<target>` with a macOS or Linux target name from the table above.

### Windows

Run `ForkPressSetup.exe`. The installer adds `forkpress.exe` to your `PATH`,
prepares a clone-capable ReFS Dev Drive, creates a starter site, and installs
Start Menu and desktop shortcuts. No WSL, Docker, WinFsp, or manual feature
setup required.

## Branching

```bash
forkpress branch create marketing                  # from main
forkpress branch create review --from staging      # from another branch
forkpress branch list
forkpress branch show marketing
forkpress branch reset marketing --from main       # replace branch contents
forkpress branch delete marketing                  # main cannot be deleted
```

When ForkPress creates a branch, it reserves a per-branch AUTOINCREMENT band
(1,000,000 IDs) for every WordPress and plugin table that uses `AUTOINCREMENT`.
That keeps independently created post, term, meta, and plugin IDs stable
across merges — including IDs embedded in serialized options, blocks, JSON,
or plugin data.

## Merging

```bash
forkpress branch merge marketing --into main
```

Clean source changes are applied. Target-only changes are preserved. Anything
that needs a human decision is written to the merge audit log.

```bash
forkpress branch merge-audit                                    # everything
forkpress branch merge-audit --review --records conflicts       # active queue
forkpress branch merge-audit --format json --records decisions  # machine-readable

forkpress branch merge-resolve conflict <id> --choice source --apply
forkpress branch merge-review conflict <id> --status reviewed --note "Verified"
```

Conflict choices are validated before they touch the target, and ForkPress
rolls the target back rather than leaving partial state. See
[docs/merging.md](docs/merging.md) for the full audit schema.

## Git workflow

Each ForkPress site is a Git smart-HTTP remote:

```text
http://wp.localhost:18080/site.git
```

```bash
forkpress clone http://wp.localhost:18080/site.git site
cd site
git switch marketing
# edit files under wordpress/ in your IDE
forkpress commit -m "Update marketing page"
```

Checkouts contain editable WordPress files under `wordpress/` and a read-only
`database.sql` snapshot regenerated from the branch database. Credential-shaped
values (password hashes, session tokens, app passwords, API tokens) are
redacted. Database changes should happen through WordPress, WP-CLI, or another
tool that operates on the branch database — pushed `database.sql` edits are
ignored.

See [docs/git-workflow.md](docs/git-workflow.md).

## Agents

```bash
forkpress agents                              # 10 branches + worktrees
forkpress agents --count 3 --prefix review
```

You get parallel preview branches and matching Git worktrees:

```text
forkpress-agents/
├── site/        # main checkout
├── agent-1/     # checked out on the agent-1 branch
├── agent-2/
└── ...
```

Drop one agent per worktree. Each previews at
`http://agent-N.wp.localhost:18080/`. Commit with
`forkpress commit -m "..."`.

See [docs/agents.md](docs/agents.md).

## Copy-on-write storage

| Platform | Default | Fallback |
| --- | --- | --- |
| macOS | APFS `clonefile` in the project directory | Rootless APFS sparsebundle under `.forkpress/macos-cow` |
| Linux | `FICLONE` reflinks in the project directory | Shared XFS loop volume in the user data dir |
| Windows | ReFS block cloning on a Dev Drive | Dev Drive setup through the Windows installer |

Branch directories remain visible beside `.forkpress` even when physical
storage lives in a mount-backed fallback.

```bash
forkpress storage status      # selected file view + mount state
forkpress doctor storage      # probe clone support
forkpress storage mount       # attach mount-backed storage
forkpress storage detach      # detach mount-backed storage
forkpress storage compact     # compact macOS sparsebundle storage
```

Tools like `du`, Finder, and disk analyzers commonly over-count shared
copy-on-write extents because they add up path sizes rather than unique
physical blocks. Trust `storage status` and `doctor storage`.

See [docs/storage/overview.md](docs/storage/overview.md).

## Logs

```bash
forkpress logs --file wp              # WordPress critical errors + PHP fatals
forkpress logs --file wp --follow     # tail
forkpress logs --file all --paths     # show every known log path
```

| Selector | File |
| --- | --- |
| `wp` | `.forkpress/logs/wp-debug.log` |
| `php` | `.forkpress/logs/php-errors.log` |
| `server` | `.forkpress/logs/php-server.log` |
| `forkpress` | `.forkpress/logs/forkpress-server.log` |
| `gc` | `.forkpress/logs/gc.log` |

## Commands

Run `forkpress <command> --help` or `forkpress branch <command> --help` for
the full CLI help.

**Site**

| Command | Purpose |
| --- | --- |
| `forkpress init` | Create a site and seed the admin user. |
| `forkpress serve` | Start the preview server in the background. |
| `forkpress start` | Start the preview server in the foreground. |
| `forkpress stop` | Stop this site's server and detach mount-backed storage. |
| `forkpress server list` | List running ForkPress servers. |

**Branches**

| Command | Purpose |
| --- | --- |
| `forkpress branch list` | List local branches. |
| `forkpress branch show <name>` | Show branch storage details. |
| `forkpress branch create <name> [--from <source>]` | Create a branch. |
| `forkpress branch reset <name> --from <source>` | Replace a branch from another branch. |
| `forkpress branch merge <source> --into <target>` | Merge one branch into another. |
| `forkpress branch merge-audit` | Inspect merge runs, decisions, conflicts, resolutions. |
| `forkpress branch merge-resolve conflict <id>` | Validate or apply a conflict choice. |
| `forkpress branch merge-review <type> <id>` | Attach review status to an audit record. |
| `forkpress branch delete <name>` | Delete a branch other than `main`. |

**Git and agents**

| Command | Purpose |
| --- | --- |
| `forkpress clone [remote] [dir]` | Clone the ForkPress Git remote. |
| `forkpress agents [dir]` | Create agent branches and Git worktrees. |
| `forkpress commit -m "<message>"` | Commit and push the current Git branch. |
| `forkpress pull` | Pull with rebase and autostash. |

**Storage and diagnostics**

| Command | Purpose |
| --- | --- |
| `forkpress logs --file <name>` | Read WordPress, PHP, server, and maintenance logs. |
| `forkpress storage status` | Show selected storage and mount state. |
| `forkpress storage mount` | Attach mount-backed storage. |
| `forkpress storage detach` | Detach mount-backed storage. |
| `forkpress storage compact` | Compact macOS sparsebundle storage. |
| `forkpress doctor storage` | Probe local filesystem clone support. |

## Documentation

The full documentation lives at
[automattic.github.io/forkpress](https://automattic.github.io/forkpress/).

| Workflow | Storage | Reference |
| --- | --- | --- |
| [Branching](docs/branching.md) | [Overview](docs/storage/overview.md) | [Commands](docs/commands.md) |
| [Merging](docs/merging.md) | [macOS](docs/storage/macos.md) | [Logs](docs/logs.md) |
| [Git workflow](docs/git-workflow.md) | [Linux](docs/storage/linux.md) | [Architecture](docs/architecture.md) |
| [Agents](docs/agents.md) | [Windows](docs/storage/windows.md) | [Development](docs/development.md) |

## Development

Local development needs a Rust toolchain, Make, PHP, PHP development headers,
and SQLite development libraries.

```bash
make dist               # build the production PHP/WordPress runtime bundle
make forkpress          # build the production binary

cargo test --workspace --exclude forkpress-cli
FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli
make test-all           # PHP tests
```

The documentation site is an Astro Starlight project at the repository root:
`npm install && npm run dev`. See
[docs/development.md](docs/development.md).

## License

[GPL-2.0-or-later](LICENSE).

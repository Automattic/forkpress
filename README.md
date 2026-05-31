# [ForkPress](https://automattic.github.io/forkpress/)

ForkPress is an **experimental** single-binary WordPress branching environment for agentic work.

At a glance:

- **Run** a WordPress site locally through ForkPress.
- **Branch** the site at any time and get a dedicated preview URL.
- **Work** in ordinary branch directories created instantly with copy-on-write
  storage.
- **Merge** WordPress file and database changes across branches.
- **Use Git** transparently against ForkPress sites.

ForkPress also creates agent worktrees, adds branch switching to the WordPress
admin bar, exposes redacted database snapshots for model context, records
auditable merge decisions, and includes CLI diagnostics for logs and storage.

It ships as a single executable with no external runtime dependencies.

<video controls playsinline preload="metadata" width="100%" src="https://videos.files.wordpress.com/QomF3klq/forkpress.mp4">
  <a href="https://videos.files.wordpress.com/QomF3klq/forkpress.mp4">Watch the ForkPress demo video.</a>
</video>

## Quick start

### 1. Install ForkPress

Choose the command for your operating system, then open a new terminal if your
shell does not immediately pick up the updated `PATH`.

#### macOS

```bash
brew install automattic/tap/forkpress
```

Without Homebrew:

```bash
curl -fsSL https://raw.githubusercontent.com/Automattic/forkpress/trunk/scripts/install.sh | sh
```

#### Linux

```bash
curl -fsSL https://raw.githubusercontent.com/Automattic/forkpress/trunk/scripts/install.sh | sh
```

This installs `forkpress` into `$HOME/.local/bin`. Add that directory to your
`PATH` if your shell does not already include it.

If you already use Homebrew on Linux, this works too:

```bash
brew install automattic/tap/forkpress
```

#### Windows

Download `ForkPressSetup.exe` from
<https://github.com/Automattic/forkpress/releases> and run it.

The installer adds `forkpress.exe` to the user `PATH`, prepares the ReFS Dev
Drive setup ForkPress uses on Windows, creates a starter site, and adds Start
Menu and desktop shortcuts. It does not require WSL, Docker, WinFsp, or manual
Windows feature setup.

### 2. Create and start a site

Commands below assume `forkpress` is on your `PATH`. If you unpacked a release
archive in the current directory, use `./forkpress` instead.
For detailed options and command behavior, see the
[CLI command reference](docs/cli/index.md).

```bash
mkdir my-site
cd my-site
forkpress init
forkpress serve
```

### 3. Open the local preview

```text
http://wp.localhost:18080/
http://wp.localhost:18080/wp-admin/
```

The admin opens logged in by default. To use the normal WordPress login form,
start the server with:

```bash
FORKPRESS_AUTO_LOGIN=0 forkpress serve
```

### 4. Stop the site

```bash
forkpress stop
```

`stop` also detaches mount-backed branch storage when the site uses it.

## Installation

### macOS

```bash
brew install automattic/tap/forkpress
```

### Linux

```bash
curl -fsSL https://raw.githubusercontent.com/Automattic/forkpress/trunk/scripts/install.sh | sh
```

This installs `forkpress` into `$HOME/.local/bin`.

### Windows

Download `ForkPressSetup.exe` from
<https://github.com/Automattic/forkpress/releases> and run it.

The installer adds `forkpress.exe` to the user `PATH` and prepares the ReFS Dev
Drive setup ForkPress uses on Windows.

### Local launcher

To keep ForkPress local to one macOS or Linux project:

```bash
curl -fsSL https://raw.githubusercontent.com/Automattic/forkpress/trunk/scripts/forkpress -o forkpress
chmod +x forkpress
./forkpress init
```

The launcher caches the real binary in `.forkpress-bin/` next to the launcher.

### Manual download

Release artifacts are available from
<https://github.com/Automattic/forkpress/releases>.

| Platform | Artifact | Notes |
| --- | --- | --- |
| macOS&nbsp;Apple&nbsp;silicon | `forkpress-aarch64-apple-darwin.tar.gz` | Static `forkpress` binary. |
| macOS&nbsp;Intel | `forkpress-x86_64-apple-darwin.tar.gz` | Static `forkpress` binary. |
| Linux&nbsp;ARM64 | `forkpress-aarch64-unknown-linux-musl.tar.gz` | Static musl-linked `forkpress` binary. |
| Linux&nbsp;x86_64 | `forkpress-x86_64-unknown-linux-musl.tar.gz` | Static musl-linked `forkpress` binary. |
| Windows&nbsp;x86_64 | `ForkPressSetup.exe` | Installer with Dev Drive setup and shortcuts. |

For macOS and Linux:

```bash
curl -L -o forkpress.tar.gz \
  "https://github.com/Automattic/forkpress/releases/download/<tag>/forkpress-<target>.tar.gz"
tar -xzf forkpress.tar.gz
chmod +x forkpress
./forkpress --version
```

Use a release tag like `vX.Y.Z` and a target from the table.

See [Installation](docs/installation.md) for install options, pinning, and
checksum behavior.

## Branching

ForkPress branches are ordinary WordPress directories beside `.forkpress`. Each
branch has its own SQLite database at `wp-content/database/.ht.sqlite`, so
branch writes stay isolated.

```text
.forkpress/        # ForkPress metadata, runtime, logs, and storage bookkeeping
main/              # main WordPress tree
marketing/         # marketing WordPress tree
```

1. **Create a branch.**

   ```bash
   forkpress branch create marketing
   ```

   To branch from something other than `main`:

   ```bash
   forkpress branch create marketing --from staging
   ```

2. **Preview it.**

   ```text
   http://marketing.wp.localhost:18080/
   http://marketing.wp.localhost:18080/wp-admin/
   ```

   The WordPress admin bar shows the current branch and lets you switch between
   local branches.

3. **Inspect branches.**

   ```bash
   forkpress branch list
   forkpress branch show marketing
   ```

4. **Reset or delete a branch.**

   ```bash
   forkpress branch reset marketing --from main
   forkpress branch delete marketing
   ```

`main` cannot be deleted. Resetting `main` requires `--force`.

## Remote sites

Bring an existing ForkPress-compatible WordPress root into a local branch over
SSH:

```bash
forkpress remote clone production \
  --ssh deploy@example.com \
  --ssh-key ~/.ssh/id_ed25519 \
  --ssh-port 2222 \
  --path /srv/www/example \
  --url https://example.com \
  --branch production-main
```

The first sync is thin by default: uploads, caches, backups, logs, and upgrade
temp files are skipped so the branch can boot quickly. ForkPress can branch from
an existing ForkPress SQLite sidecar or import a normal MySQL-backed WordPress
database over the same SSH connection. Use `--include-uploads` or `--full-sync`
when you need more of the remote tree locally.

Install plugins from the branch's WordPress admin at
`/wp-admin/plugin-install.php`. ForkPress tracks the WordPress.org top 100
popular plugins as an explicit compatibility target and includes a package
smoke test for those plugin downloads.

See [Remote Sites](docs/remote-sites.md) and
[Top Plugin Support](docs/top-plugin-support.md).

## Merging

ForkPress merges WordPress files and branch-local SQLite database changes. Clean
source changes are applied, target-only changes are preserved, and anything that
needs a human decision is recorded in the merge audit log.

ForkPress reserves per-branch AUTOINCREMENT ID ranges for WordPress and plugin
tables. That keeps independently created rows stable across branches, including
IDs embedded in blocks, JSON, serialized options, or plugin data.

1. **Merge into `main`.**

   ```bash
   forkpress branch merge marketing --into main
   ```

2. **Inspect merge activity.**

   ```bash
   forkpress branch merge-audit
   forkpress branch conflicts --review
   forkpress branch merge-audit --format json --review --records decisions
   ```

3. **Apply a reviewed conflict choice.**

   ```bash
   forkpress branch merge-resolve conflict <id> --choice source --apply
   forkpress branch merge-resolve conflict-key <key> --run <id> --choice source --apply
   ```

4. **Mark an audit record as reviewed.**

   ```bash
   forkpress branch merge-review conflict <id> \
     --status reviewed \
     --note "Verified in wp-admin"
   forkpress branch merge-review conflict-key <key> --run <id> \
     --status reviewed \
     --note "Verified in wp-admin"
   ```

## Git workflow

ForkPress serves every site as a Git smart-HTTP remote:

```text
http://wp.localhost:18080/site.git
```

1. **Clone the site.**

   ```bash
   forkpress clone http://wp.localhost:18080/site.git site
   cd site
   ```

2. **Switch branches.**

   ```bash
   git fetch origin
   git switch marketing
   ```

3. **Edit WordPress files** under `wordpress/`.

4. **Commit back to ForkPress.**

   ```bash
   forkpress commit -m "Update marketing page"
   ```

5. **Preview the pushed branch.**

   ```text
   http://marketing.wp.localhost:18080/
   ```

The checkout contains editable WordPress files under `wordpress/` and a
read-only `database.sql` snapshot for model context. The snapshot is regenerated
from the branch database, redacts credential-shaped values, and is ignored on
push.

Database changes should happen through WordPress, WP-CLI, or another tool that
operates on the branch database. Private runtime state under
`wordpress/wp-content/database/` is not part of the Git view.

## Agents

1. **Create agent worktrees.**

   ```bash
   forkpress agents
   ```

   By default, ForkPress creates ten branches and matching worktrees:

   ```text
   forkpress-agents/site
   forkpress-agents/agent-1
   forkpress-agents/agent-2
   ...
   forkpress-agents/agent-10
   ```

2. **Tune the pool when needed.**

   ```bash
   forkpress agents --count 3 --prefix review
   ```

3. **Commit from an agent worktree.**

   ```bash
   cd forkpress-agents/review-1
   forkpress commit -m "Update review 1"
   ```

4. **Preview the branch.**

   ```text
   http://review-1.wp.localhost:18080/
   ```

## Copy-on-write storage

Branches are materialized directories. ForkPress shares unchanged file blocks
with the source branch and stores new blocks only when a branch writes to a
file.

### Storage cascade

| Platform | Default | Fallback |
| --- | --- | --- |
| macOS | APFS `clonefile` in the project directory. | Rootless APFS sparsebundle under `.forkpress/macos-cow`. |
| Linux | `FICLONE` reflinks in the project directory. | Shared XFS loop volume under the user's ForkPress data directory. |
| Windows | ReFS block cloning on a Dev Drive. | Dev Drive setup through the Windows installer. |

Public branch directories remain visible beside `.forkpress` even when physical
storage lives in a mount-backed fallback.

Linux XFS loop storage requires permission to allocate loop devices and mount
filesystems, usually root or `CAP_SYS_ADMIN`.

Full file-copy materialization is available only when explicitly requested. It
is not part of the automatic copy-on-write cascade.

### Storage diagnostics

**Inspect storage and clone support:**

```bash
forkpress storage status
forkpress doctor storage
```

**Attach or detach mount-backed storage:**

```bash
forkpress storage mount
forkpress storage detach
```

**Compact macOS sparsebundle storage after branch churn:**

```bash
forkpress storage compact
```

Tools such as `du`, Finder, and some disk analyzers can over-count shared
copy-on-write extents because they add up path sizes rather than unique physical
blocks. `storage status` and `doctor storage` show the branch root, physical
storage root, and mount-backed storage details when applicable.

Before moving or deleting a site with mount-backed storage, stop it through
ForkPress:

```bash
forkpress stop
```

On Linux XFS-loop sites, `forkpress storage detach` unmounts the shared volume
only after other running ForkPress servers using that storage view are stopped.
Before deleting a Linux XFS-loop site, remove the hidden per-site directory
inside the shared mount too. `forkpress storage detach` prints the exact
`rm -rf` command while the volume is still attached.

## Logs

**Show WordPress critical errors and PHP fatals:**

```bash
forkpress logs --file wp
```

**Follow new WordPress log output:**

```bash
forkpress logs --file wp --follow
```

**Print known log paths:**

```bash
forkpress logs --file all --paths
```

Useful log files:

- `wp`: `.forkpress/logs/wp-debug.log`
- `php`: `.forkpress/logs/php-errors.log`
- `server`: `.forkpress/logs/php-server.log`
- `forkpress`: `.forkpress/logs/forkpress-server.log`
- `gc`: `.forkpress/logs/gc.log`

## Commands

This table is a quick reference for common commands. The docs site has the full
per-command [CLI reference](docs/cli/index.md), including options, examples,
aliases, and grouped subcommands.

| Command | Purpose |
| --- | --- |
| `forkpress init` | Create a site and seed the local admin user. |
| `forkpress serve` | Start the preview server in the background. |
| `forkpress start` | Start the preview server in the foreground. |
| `forkpress stop` | Stop this site's server and detach mount-backed storage. |
| `forkpress server list` | List running ForkPress servers. |
| `forkpress branch list` | List local branches. |
| `forkpress branch show <name>` | Show branch storage details. |
| `forkpress branch create <name> [--from main]` | Create a branch. |
| `forkpress branch reset <name> --from <source>` | Replace a branch from another branch. |
| `forkpress branch merge <source> --into <target>` | Merge one branch into another. |
| `forkpress branch merge-audit` | Inspect merge runs, decisions, conflicts, and resolutions. |
| `forkpress branch conflicts [--run <id>]` | Inspect the conflict review queue; shortcut for `merge-audit --records conflicts`. |
| `forkpress branch merge-resolve conflict <id>` | Validate or apply a conflict choice. |
| `forkpress branch merge-resolve conflict-key <key> [--run <id>]` | Validate or apply a conflict choice by logical conflict key when unambiguous. |
| `forkpress branch merge-review <type> <id>` | Attach review status to an audit record. |
| `forkpress branch merge-review conflict-key <key> [--run <id>]` | Attach review status by logical conflict key when unambiguous. |
| `forkpress branch delete <name>` | Delete a branch other than `main`. |
| `forkpress clone [remote] [dir]` | Clone the ForkPress Git remote. |
| `forkpress agents [dir]` | Create agent branches and Git worktrees. |
| `forkpress commit -m "message"` | Commit and push the current Git branch back to ForkPress. |
| `forkpress pull` | Pull with rebase and autostash. |
| `forkpress remote clone <name> --ssh <host> --path <wp-root> --branch <branch>` | Thin-clone a boot-ready remote WordPress root over SSH, import MySQL into SQLite when needed, then create a local COW branch. |
| `forkpress logs --file <name>` | Read WordPress, PHP, server, and maintenance logs. |
| `forkpress storage status` | Show selected storage and mount state. |
| `forkpress storage mount` | Attach mount-backed storage. |
| `forkpress storage detach` | Detach mount-backed storage. |
| `forkpress storage compact` | Compact macOS sparsebundle storage. |
| `forkpress doctor storage` | Probe local filesystem clone support. |

Run `forkpress <command> --help` or `forkpress branch <command> --help` for the
raw CLI help.

## Development

Local development needs a Rust toolchain, Make, PHP, PHP development headers,
and SQLite development libraries.

**Build the runtime bundle and production binary:**

```bash
make dist
make forkpress
```

**Run the main test suites:**

```bash
cargo test --workspace --exclude forkpress-cli
FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli
make test-all
```

Release automation is documented in [Releases](docs/releases.md).

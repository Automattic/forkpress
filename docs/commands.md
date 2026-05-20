# Commands

This page summarizes the main production commands. For detailed command pages,
options, examples, and behavior notes, see [CLI commands](./cli/index.md).
Run `forkpress <command> --help` or `forkpress branch <command> --help` for the
raw CLI help. For the conflict review workflow around history, branch tree,
audit queues, stale reviews, and source/target/apply-reviewed choices, see
[`conflict-review.md`](conflict-review.md).

## Site lifecycle

| Command | Purpose |
| --- | --- |
| `forkpress init` | Create a site and seed the local admin user. |
| `forkpress init --admin-password admin` | Create a site with a known local admin password. |
| `forkpress serve` | Start the preview server in the background. |
| `forkpress start` | Start the preview server in the foreground. |
| `forkpress stop` | Stop this site's server and detach mount-backed storage. |
| `forkpress stop --all` | Stop every running ForkPress site server for the current user. |
| `forkpress server list` | List running ForkPress servers. |

## Branching and merging

| Command | Purpose |
| --- | --- |
| `forkpress branch list` | List local branches. |
| `forkpress branch show <name>` | Show branch storage details. |
| `forkpress branch create <name> [--from main]` | Create a branch. |
| `forkpress branch reset <name> --from <source>` | Replace a branch from another branch. |
| `forkpress branch merge <source> --into <target>` | Merge one branch into another. |
| `forkpress branch history` | Show recent merge runs as source-to-target branch edges. |
| `forkpress branch tree` | Alias for merge history when you want the branch topology view. |
| `forkpress branch merge-audit` | Inspect merge runs, decisions, conflicts, conflict events, resolutions, and rollback failures. |
| `forkpress branch conflicts [--run <id>]` | Inspect the conflict review queue; shortcut for `merge-audit --records conflicts`. |
| `forkpress branch revalidate-reviews [--run <id>]` | Recheck reviewed conflicts and carry stale reviews back to `needs-action`. |
| `forkpress branch merge-review <type> <id>` | Attach review status to an audit record. |
| `forkpress branch merge-review conflict-key <key> [--run <id>]` | Attach review status by logical conflict key when unambiguous. |
| `forkpress branch merge-resolve conflict <id>` | Validate or apply a conflict choice. |
| `forkpress branch merge-resolve conflict-key <key> [--run <id>]` | Validate or apply a conflict choice by logical conflict key when unambiguous. |
| `forkpress branch delete <name>` | Delete a branch other than `main`. |

## Git and agents

| Command | Purpose |
| --- | --- |
| `forkpress clone [remote] [dir]` | Clone the ForkPress Git remote. |
| `forkpress commit -m "message"` | Commit and push the current Git branch back to ForkPress. |
| `forkpress pull` | Pull with rebase and autostash. |
| `forkpress agents [dir]` | Create agent branches and Git worktrees. |
| `forkpress remote clone <name> --ssh <host> --ssh-key <key> --ssh-port <port> --path <wp-root> --url <url> --branch <branch> [--force]` | Thin-clone a boot-ready remote WordPress root over SSH, import MySQL into the local SQLite sidecar when needed, then create a local COW branch. `--force` updates the cache and recreates an existing target branch. |
| `forkpress remote add <name> --cache-root <dir>` | Register an existing local remote-site cache. |
| `forkpress remote branch <name> <branch>` | Create a local COW branch from a registered remote cache. |

For the remote-site onboarding flow, including thin syncs, plugin
installation, and the top-100 plugin compatibility target, see
[`remote-sites.md`](remote-sites.md).

## Diagnostics and storage

| Command | Purpose |
| --- | --- |
| `forkpress logs --file <name>` | Read WordPress, PHP, server, and maintenance logs. |
| `forkpress storage status` | Show selected storage and mount state. |
| `forkpress storage mount` | Attach mount-backed storage. |
| `forkpress storage detach` | Detach mount-backed storage. |
| `forkpress storage compact` | Compact macOS sparsebundle storage. |
| `forkpress doctor storage` | Probe local filesystem clone support. |

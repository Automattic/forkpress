# Commands

This page summarizes the main production commands. Run
`forkpress <command> --help` or `forkpress branch <command> --help` for the
full CLI help.

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
| `forkpress branch merge-audit` | Inspect merge runs, decisions, conflicts, conflict events, and resolutions. |
| `forkpress branch merge-review <type> <id>` | Attach review status to an audit record. |
| `forkpress branch merge-resolve conflict <id>` | Validate or apply a conflict choice. |
| `forkpress branch delete <name>` | Delete a branch other than `main`. |

## Git and agents

| Command | Purpose |
| --- | --- |
| `forkpress clone [remote] [dir]` | Clone the ForkPress Git remote. |
| `forkpress commit -m "message"` | Commit and push the current Git branch back to ForkPress. |
| `forkpress pull` | Pull with rebase and autostash. |
| `forkpress agents [dir]` | Create agent branches and Git worktrees. |

## Diagnostics and storage

| Command | Purpose |
| --- | --- |
| `forkpress logs --file <name>` | Read WordPress, PHP, server, and maintenance logs. |
| `forkpress storage status` | Show selected storage and mount state. |
| `forkpress storage mount` | Attach mount-backed storage. |
| `forkpress storage detach` | Detach mount-backed storage. |
| `forkpress storage compact` | Compact macOS sparsebundle storage. |
| `forkpress doctor storage` | Probe local filesystem clone support. |

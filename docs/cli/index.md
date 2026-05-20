# CLI

The ForkPress CLI creates local WordPress branch previews, serves them over HTTP, exposes them as Git branches, and coordinates storage, merge, and diagnostic workflows.

Start with `init`, run the preview server with `serve`, create branches, then use the Git or merge commands that match your workflow.

## Primary flow

| Step | Command | Purpose |
| --- | --- | --- |
| 1 | [`forkpress init`](./init.md) | Create the local site state, runtime files, and `main` branch. |
| 2 | [`forkpress serve`](./serve.md) | Start the preview server in the background. |
| 3 | [`forkpress branch`](./branch.md) | Create, inspect, reset, merge, and delete local branches. |
| 4 | [`forkpress clone`](./clone.md) | Clone the ForkPress Git remote into an editing checkout. |
| 5 | [`forkpress commit`](./commit.md) | Stage, commit, push, and normalize the current checkout branch. |

## Commands

| Command | Page | Notes |
| --- | --- | --- |
| `forkpress init` | [Init](./init.md) | Site creation and first admin user. |
| `forkpress serve` | [Serve](./serve.md) | Background server shortcut for normal preview work. |
| `forkpress start` | [Start](./start.md) | Foreground server command used directly and by `serve`. |
| `forkpress stop` | [Stop](./stop.md) | Stop matching servers and detach mount-backed storage. |
| `forkpress server` | [Server](./server.md) | Background server management: start, list, stop. |
| `forkpress branch` | [Branch](./branch.md) | Branch lifecycle, merging, audit review, and plugin merge helpers. |
| `forkpress branchctl` | [Branchctl](./branchctl.md) | Alias for `branch`. |
| `forkpress remote` | [Remote](./remote.md) | Remote-site cache registration and branching. |
| `forkpress clone` | [Clone](./clone.md) | Git clone wrapper with ForkPress defaults. |
| `forkpress pull` | [Pull](./pull.md) | Rebase/autostash pull for an existing checkout. |
| `forkpress commit` | [Commit](./commit.md) | Agent-friendly alias for `push`. |
| `forkpress push` | [Push](./push.md) | Stage, commit if needed, push, then sync the normalized ref. |
| `forkpress agents` | [Agents](./agents.md) | Create multiple branches and matching Git worktrees. |
| `forkpress git` | [Git](./git.md) | Git passthrough plus local `git branch create`. |
| `forkpress logs` | [Logs](./logs.md) | Read or follow WordPress, PHP, server, and maintenance logs. |
| `forkpress doctor` | [Doctor](./doctor.md) | Inspect local environment and storage capabilities. |
| `forkpress storage` | [Storage](./storage.md) | Inspect, mount, detach, and compact mount-backed storage. |

## Global options

| Option | Applies to | Description |
| --- | --- | --- |
| `-v`, `--version` | `forkpress` | Print the ForkPress version and exit. |
| `--work-dir <path>` | Site-aware commands | ForkPress site state directory. Defaults to `.forkpress`. |
| `--php-bin <path>` | Runtime commands | Use a specific PHP binary instead of the embedded runtime command. Mostly useful for development and debugging. |

`--work-dir` points to the ForkPress metadata directory, not the branch directory. In a normal site layout it stays at `.forkpress`, next to branch directories such as `main/` and `marketing/`.

## Production scope

These pages document the production `forkpress` binary. Experimental commands that only exist in `forkpress-dev` are intentionally left out of this CLI reference.

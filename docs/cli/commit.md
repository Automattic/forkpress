# `forkpress commit`

`forkpress commit` stages, commits, pushes, and syncs the current ForkPress Git checkout branch.

It is an alias for [`forkpress push`](./push.md), named for the agent workflow: edit files, then commit the branch back to ForkPress so it becomes previewable.

## Usage

```bash
forkpress commit [repo] [options]
```

## Arguments And Options

| Argument or option | Default | Description |
| --- | --- | --- |
| `repo` | `.` | Existing Git checkout or worktree. |
| `-m <message>`, `--message <message>` | `forkpress: update <branch>` | Commit message to use when the checkout has local changes. |
| `--remote-name <name>` | `origin` | Remote name to push to and fetch the normalized ref from. |

## What It Does

`commit` uses the same implementation as `push`:

1. Verifies that `repo` is a Git checkout.
2. Reads the current branch name and rejects detached `HEAD`.
3. If the checkout is dirty, configures a local Git identity when missing, stages all changes, and creates a commit.
4. Pushes `HEAD` to `refs/heads/<branch>` on the configured remote.
5. Fetches the server-normalized ref and fast-forwards the checkout when possible.

If there are no local changes, `commit` still pushes the current `HEAD` and syncs the normalized ref.

## Examples

Commit and push from the current checkout:

```bash
forkpress commit -m "Update homepage copy"
```

Commit another worktree:

```bash
forkpress commit ../forkpress-agents/agent-1 -m "Adjust agent 1 patch"
```

Use a non-default remote:

```bash
forkpress commit --remote-name forkpress -m "Update plugin template"
```

## Related Commands

Use [`forkpress push`](./push.md) when you prefer the transport-oriented name. Use [`forkpress pull`](./pull.md) if ForkPress reports that the server-normalized ref is not a fast-forward.

# `forkpress push`

`forkpress push` stages, commits, pushes, and syncs the current ForkPress Git checkout branch.

Use it when you want the transport-oriented name for the same workflow exposed by [`forkpress commit`](./commit.md).

## Usage

```bash
forkpress push [repo] [options]
```

## Arguments And Options

| Argument or option | Default | Description |
| --- | --- | --- |
| `repo` | `.` | Existing Git checkout or worktree. |
| `-m <message>`, `--message <message>` | `forkpress: update <branch>` | Commit message to use when the checkout has local changes. |
| `--remote-name <name>` | `origin` | Remote name to push to and fetch the normalized ref from. |

## What It Does

`push` wraps the complete ForkPress Git-editing cycle:

- rejects detached `HEAD`, because ForkPress pushes named branches;
- stages all local changes when the checkout is dirty;
- creates a commit only when there are staged changes to commit;
- pushes the current branch to the ForkPress Git remote;
- fetches the server-normalized ref after the push;
- fast-forwards the checkout when the normalized ref is a descendant of local `HEAD`.

ForkPress normalizes pushed branches server-side. That removes ignored runtime paths, ignores edits to generated `database.sql`, and reflects the branch directory as the source of truth.

## Examples

Push the current checkout with an explicit message:

```bash
forkpress push -m "Update template styles"
```

Push without creating a new commit when the checkout is already clean:

```bash
forkpress push
```

Push another worktree:

```bash
forkpress push ../forkpress-agents/agent-2 -m "Update block markup"
```

## Failure Cases

`push` fails if Git is not available, `repo` is not a Git checkout, or the checkout is in detached `HEAD`.

If the server-normalized ref is not a fast-forward, the push has still reached ForkPress, but the local checkout cannot be reset automatically. Run:

```bash
forkpress pull
```

## Related Commands

Use [`forkpress commit`](./commit.md) for the same behavior with agent workflow wording.

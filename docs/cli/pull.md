# `forkpress pull`

`forkpress pull` updates an existing Git checkout with `git pull --rebase --autostash`.

Use it in a ForkPress editing checkout after another process has pushed or normalized the same branch.

## Usage

```bash
forkpress pull [repo]
```

## Arguments

| Argument | Default | Description |
| --- | --- | --- |
| `repo` | `.` | Existing Git checkout or worktree to update. |

## What It Does

`pull` verifies that `repo` is a Git checkout, then runs:

```bash
git pull --rebase --autostash
```

The command inherits Git's normal rebase behavior. If local changes conflict with the remote, resolve them in the checkout and continue or abort the rebase with standard Git commands.

## Examples

Pull the current checkout:

```bash
forkpress pull
```

Pull another checkout:

```bash
forkpress pull ../site
```

## Related Commands

[`forkpress commit`](./commit.md) fetches the server-normalized ref after a successful push and fast-forwards when possible. Use `pull` when that normalization is not a fast-forward or when the checkout needs to catch up later.

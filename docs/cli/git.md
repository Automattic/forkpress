# `forkpress git`

`forkpress git` passes arguments to Git, with one ForkPress-aware extension for local branch creation.

Use it when you want a Git-compatible wrapper, or when a workflow expects `forkpress git branch create <name>`.

## Usage

```bash
forkpress git [--work-dir <path>] [--php-bin <path>] <git-args>...
```

## Shared Options

| Option | Default | Description |
| --- | --- | --- |
| `--work-dir <path>` | `.forkpress` | Site state directory used only by the ForkPress-aware `git branch create` extension. |
| `--php-bin <path>` | Embedded PHP | PHP binary used by branch creation helpers. |

## Passthrough Mode

For most invocations, `forkpress git` runs:

```bash
git <git-args>...
```

Examples:

```bash
forkpress git status
forkpress git log --oneline
forkpress git fetch origin
```

The command runs Git in the current working directory and inherits standard input, output, and error.

## ForkPress Branch Creation

The special form `forkpress git branch create <name>` creates a ForkPress branch directly through the active site storage strategy instead of invoking Git.

```bash
forkpress git branch create <branch> [--from <source>] [--user <name> --password <password>]
```

| Option | Default | Description |
| --- | --- | --- |
| `--from <source>` | `main` | Source ForkPress branch to copy. |
| `--user <name>` | None | ForkPress user for auth-enabled experimental branch creation. Must be paired with `--password`. Production COW does not use it. |
| `--password <password>` | None | ForkPress password for auth-enabled experimental branch creation. Must be paired with `--user`. Production COW does not use it. |

Example:

```bash
forkpress git branch create marketing --from main
```

For production COW sites, this is equivalent to:

```bash
forkpress branch create marketing --from main
```

## Failure Cases

`forkpress git` requires at least one argument. It also requires the `git` binary for normal passthrough mode.

For the special `git branch create` form, ForkPress validates the site at `--work-dir`, prepares the runtime if needed, and requires the source branch to exist.

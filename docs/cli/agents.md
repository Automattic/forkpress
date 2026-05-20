# `forkpress agents`

`forkpress agents` creates multiple ForkPress branches and matching Git worktrees for parallel editing.

Use it after the site is initialized and the preview server is available at the configured Git remote.

## Usage

```bash
forkpress agents [dir] [options]
```

## Arguments And Options

| Argument or option | Default | Description |
| --- | --- | --- |
| `dir` | `forkpress-agents` | Directory that will hold the main checkout and agent worktrees. |
| `--work-dir <path>` | `.forkpress` | Site state directory used for local branch creation. |
| `--php-bin <path>` | Embedded PHP | PHP binary used by runtime helpers. |
| `--remote <url>` | `http://wp.localhost:18080/site.git` | Git remote to clone or fetch. |
| `--count <n>` | `10` | Number of agent branches and worktrees to create. Must be greater than zero. |
| `--prefix <prefix>` | `agent` | Branch name prefix. Branches are named `<prefix>-1`, `<prefix>-2`, and so on. |
| `--from <branch>` | `main` | Parent ForkPress branch to fork from. |
| `--remote-name <name>` | `origin` | Name for the configured Git remote. |
| `--user <name>` | None | ForkPress user for local branch creation when auth is enabled. Must be paired with `--password`. |
| `--password <password>` | None | ForkPress password for local branch creation when auth is enabled. Must be paired with `--user`. |

Production COW branch creation does not use `--user` and `--password`, but the options remain available for auth-enabled experimental workflows.

## What It Does

`agents` creates or reuses a root directory, ensures a `site/` checkout exists inside it, creates the requested ForkPress branches, fetches the remote, and then adds one Git worktree per branch.

With defaults, the result is:

```text
forkpress-agents/
  site/       # main checkout
  agent-1/    # worktree for branch agent-1
  agent-2/    # worktree for branch agent-2
  ...
  agent-10/   # worktree for branch agent-10
```

Existing worktree directories are reused.

## Examples

Create the default ten worktrees:

```bash
forkpress agents
```

Create three review branches:

```bash
forkpress agents --count 3 --prefix review
```

Create branches from a staging branch:

```bash
forkpress agents --from staging --count 5
```

Use another remote URL and destination:

```bash
forkpress agents ./client-agents --remote http://wp.localhost:18081/site.git
```

## Working In A Worktree

Each generated worktree is a normal Git checkout. Edit files under `wordpress/`, then push the branch back to ForkPress:

```bash
cd forkpress-agents/agent-1
forkpress commit -m "Agent 1 update"
```

Preview the result at:

```text
http://agent-1.wp.localhost:18080/
```

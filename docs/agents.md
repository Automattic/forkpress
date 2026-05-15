# Agents

`forkpress agents` creates a pool of branches plus matching Git worktrees so
several agents can work on a site in parallel.

## Create the pool

With the site server running:

```bash
forkpress agents
```

ForkPress creates ten branches and ten worktrees:

```text
forkpress-agents/
├── site/          # main checkout
├── agent-1/       # checked out on the agent-1 branch
├── agent-2/
└── ...
```

Each `agent-N` worktree is checked out on its matching ForkPress branch.

## Customize the pool

```bash
forkpress agents --count 3 --prefix review
```

That creates branches and worktrees `review-1`, `review-2`, `review-3`.

## Work in a worktree

After an agent edits files in `forkpress-agents/review-1`:

```bash
cd forkpress-agents/review-1
forkpress commit -m "Update review 1"
```

Preview the branch at:

```text
http://review-1.wp.localhost:18080/
```

The agent workflow uses the same Git adapter as a manual checkout. File edits
under `wordpress/` are applied to the materialized ForkPress branch.
Database changes should happen through WordPress, WP-CLI, or another tool
that writes the branch database.

See [Git workflow](git-workflow.md) for the underlying mechanics.

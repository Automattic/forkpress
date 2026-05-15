# Agents

`forkpress agents` creates a small pool of branches and matching Git worktrees
for parallel agent work.

## Create agent worktrees

With the site server running:

```bash
forkpress agents
```

ForkPress creates ten branches and ten worktrees:

```text
forkpress-agents/site
forkpress-agents/agent-1
forkpress-agents/agent-2
...
forkpress-agents/agent-10
```

Each `agent-N` worktree is checked out on its matching ForkPress branch.

## Customize the pool

Create fewer branches or use another prefix:

```bash
forkpress agents --count 3 --prefix review
```

That creates branches and worktrees such as `review-1`, `review-2`, and
`review-3`.

## Commit from a worktree

After an agent edits files:

```bash
cd forkpress-agents/review-1
forkpress commit -m "Update review 1"
```

Preview the result:

```text
http://review-1.wp.localhost:18080/
```

The agent workflow uses the same Git adapter as a manual checkout. File edits
under `wordpress/` are applied to the materialized ForkPress branch; database
changes should happen through WordPress or another tool that writes the branch
database.

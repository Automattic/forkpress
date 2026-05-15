# Branching

ForkPress branches are materialized WordPress trees. A branch is not an SQL
view, an overlay table, or a hidden namespace. It is an ordinary directory with
its own WordPress files and its own SQLite database.

```text
.forkpress/        # ForkPress metadata, runtime, logs, locks, and storage state
main/              # main branch WordPress tree
marketing/         # marketing branch WordPress tree
```

Each branch stores its database at `wp-content/database/.ht.sqlite`. A post save
on `marketing.wp.localhost` writes to `./marketing/wp-content/database/.ht.sqlite`
and cannot mutate `./main/wp-content/database/.ht.sqlite`.

## Create a branch

Create a branch from `main`:

```bash
forkpress branch create marketing
```

Create a branch from another branch:

```bash
forkpress branch create marketing --from staging
```

ForkPress reserves moderate AUTOINCREMENT ID bands for WordPress and plugin
tables when it creates a branch. Those bands keep independently created IDs
stable across branches, including IDs copied into blocks, JSON, serialized
options, or plugin data.

## Preview a branch

Branches are served as local subdomains:

```text
http://marketing.wp.localhost:18080/
http://marketing.wp.localhost:18080/wp-admin/
```

The WordPress admin bar shows the current branch and lets you switch between
local branches.

## Inspect branches

```bash
forkpress branch list
forkpress branch show marketing
```

`branch show` prints the branch directory, database path, file count, and Git
ref path.

## Reset or delete a branch

Reset a branch back to another branch:

```bash
forkpress branch reset marketing --from main
```

That replaces `./marketing` with a fresh copy-on-write clone of `./main`,
including the branch-local SQLite database.

Delete a branch:

```bash
forkpress branch delete marketing
```

`main` cannot be deleted. Resetting `main` requires `--force`.

## Runtime coordination

ForkPress-served WordPress requests take a shared advisory lock at
`.forkpress/cow/operations.lock`. Branch mutations such as create, reset,
delete, and Git apply take the same lock exclusively. That prevents ForkPress
from publishing or removing a branch tree while one of its own HTTP requests is
active.

Direct shell, editor, and external-tool writes to branch directories are normal
filesystem writes. They do not automatically participate in that advisory lock.

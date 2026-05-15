# Branching

A ForkPress branch is a materialized WordPress tree — not an SQL view, an
overlay table, or a hidden namespace. It is an ordinary directory beside
`.forkpress` with its own WordPress files and its own SQLite database.

```text
my-site/
├── .forkpress/        # metadata, runtime, logs, locks, storage state
├── main/              # main branch WordPress tree + database
└── marketing/         # marketing branch WordPress tree + database
```

Each branch stores its database at `wp-content/database/.ht.sqlite`. A post
save on `marketing.wp.localhost` writes to
`./marketing/wp-content/database/.ht.sqlite` and cannot mutate `main`.

## Create

From `main`:

```bash
forkpress branch create marketing
```

From another branch:

```bash
forkpress branch create review --from staging
```

ForkPress reserves a per-branch `AUTOINCREMENT` band (1,000,000 IDs by
default) for every WordPress and plugin table that uses `AUTOINCREMENT`. That
keeps independently created post, term, meta, and plugin IDs stable across
branches — including IDs embedded in serialized options, JSON, blocks, and
plugin data — so most merges don't need to rewrite IDs.

## Preview

Branches are served as local subdomains:

```text
http://marketing.wp.localhost:18080/
http://marketing.wp.localhost:18080/wp-admin/
```

The WordPress admin bar shows the current branch and offers a switcher to
every local branch.

## Inspect

```bash
forkpress branch list
forkpress branch show marketing
```

`branch show` prints the branch directory, database path, file count, and Git
ref path.

## Reset

Replace a branch with a fresh copy of another:

```bash
forkpress branch reset marketing --from main
```

The reset is also a copy-on-write clone, so it is cheap. Resetting `main`
requires `--force`.

## Delete

```bash
forkpress branch delete marketing
```

`main` cannot be deleted.

## Runtime coordination

WordPress requests served by ForkPress take a shared advisory lock at
`.forkpress/cow/operations.lock`. Branch mutations (create, reset, delete,
Git apply) take the same lock exclusively. That prevents ForkPress from
publishing or removing a branch tree while one of its own HTTP requests is
active.

Direct shell, editor, and external-tool writes to branch directories are
ordinary filesystem writes and do not automatically participate in the
advisory lock.

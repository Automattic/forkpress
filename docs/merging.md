# Merging

ForkPress merges one materialized branch into another:

```bash
forkpress branch merge marketing --into main
```

The merge covers both WordPress files and branch-local SQLite database
changes. Clean source changes are applied to the target, target-only changes
are preserved, and anything that needs a human decision is written to the
merge audit log.

## Why branch IDs stay stable

When ForkPress creates a branch, it reserves per-branch `AUTOINCREMENT` ID
ranges for WordPress core tables and any plugin table that uses
`AUTOINCREMENT`. The default band size is 1,000,000 IDs per table per
branch.

That strategy avoids rewriting IDs during normal merges. Posts, terms,
metadata, and plugin rows created independently on different branches keep
their IDs — including IDs embedded in serialized options, JSON, blocks, and
plugin data.

## Inspect merge activity

Recent runs, decisions, conflicts, and resolutions:

```bash
forkpress branch merge-audit
```

Focus on active review queues:

```bash
forkpress branch merge-audit --review --records conflicts
forkpress branch merge-audit --review --records decisions --scope db
forkpress branch merge-audit --review --records decisions --scope files
```

Machine-readable audit data:

```bash
forkpress branch merge-audit --format json --review --records conflicts
```

Useful filters:

| Flag | Purpose |
| --- | --- |
| `--run <id>` | Restrict output to one merge run. |
| `--scope all\|db\|files` | Limit to database or filesystem records. |
| `--records all\|conflicts\|decisions\|resolutions\|rollback-failures` | Choose the record kind. |
| `--review` | Only records still in the active review queue. |
| `--review-status unreviewed\|pending\|needs-action\|reviewed` | Filter by review status. |
| `--target-kept` | Only target-kept decisions. |
| `--path <path>` / `--path-prefix <prefix>` | Filter by file path. |

## Review and resolve conflicts

Record review status:

```bash
forkpress branch merge-review conflict <id> \
  --status reviewed \
  --note "Verified in wp-admin"
```

Validate or apply a conflict choice:

```bash
forkpress branch merge-resolve conflict <id> --choice source
forkpress branch merge-resolve conflict <id> --choice source --apply
```

Source and target choices are validated before they mutate the target. If a
resolution cannot be recorded after a file or database mutation, ForkPress
rolls the target back rather than leaving partial state.

## What gets audited

- automatic source-applied decisions;
- target-kept decisions for clean target-side changes;
- database row and schema conflicts;
- filesystem conflicts;
- reviewed resolutions;
- rollback failures, with the JSONL artifact path when rollback itself needs
  follow-up.

Database merge detail is exposed through the audit commands rather than by
inspecting `.forkpress/cow/merge/metadata.sqlite` directly.

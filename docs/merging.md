# Merging

ForkPress can merge one materialized branch into another:

```bash
forkpress branch merge marketing --into main
```

The merge covers both WordPress files and branch-local SQLite database changes.
Clean source changes are applied to the target, target-only changes are
preserved, and anything that needs a human decision is recorded in the merge
audit log.

## Why branch IDs stay stable

When ForkPress creates a branch, it reserves per-branch AUTOINCREMENT ID ranges
for WordPress core tables and arbitrary plugin tables that use SQLite
`AUTOINCREMENT`. The default band size is 1,000,000 IDs per table per branch.

That strategy avoids rewriting IDs during normal merges. Posts, terms, metadata,
and plugin rows created independently on different branches can keep their IDs,
including IDs embedded in serialized options, JSON, blocks, and plugin data.

## Inspect merge activity

Show recent runs, decisions, conflicts, and resolutions:

```bash
forkpress branch merge-audit
```

Focus on active review queues:

```bash
forkpress branch merge-audit --review --records conflicts
forkpress branch merge-audit --review --records decisions --scope db
forkpress branch merge-audit --review --records decisions --scope files
```

Export machine-readable audit data:

```bash
forkpress branch merge-audit --format json --review --records conflicts
```

Useful filters include `--run`, `--scope all|db|files`,
`--records all|conflicts|decisions|resolutions|rollback-failures`,
`--review-status unreviewed|pending|needs-action|reviewed`, `--target-kept`,
`--path`, and `--path-prefix`.

## Review and resolve conflicts

Record review status on an audit record:

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

Source and target choices are validated before they mutate target state. If a
resolution cannot be recorded after a file or database mutation, ForkPress rolls
the target back rather than keeping a partial result.

`merge-audit --format json --records conflicts` treats conflicts as
first-class records. Each conflict includes a `conflict_class`, a
`resolution_strategy`, executable `resolution_choices`, whether the generic
`merge-resolve conflict` path supports it, and whether `--after-revalidate` is
available. UI clients should consume those fields instead of inferring behavior
from raw `conflict_type` strings.

## What gets audited

ForkPress records:

- automatic source-applied decisions;
- target-kept decisions for clean target-side changes;
- database row and schema conflicts;
- filesystem conflicts;
- reviewed resolutions;
- rollback failures, including the JSONL artifact path when rollback itself
  needs follow-up.

Database merge detail is intentionally exposed through the audit commands
instead of by asking users to inspect `.forkpress/cow/merge/metadata.sqlite`
directly.

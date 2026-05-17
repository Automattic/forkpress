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

Revalidate stale reviewed conflicts and print the conflict ids that are now in
the `needs-action` queue:

```bash
forkpress branch revalidate-reviews --run 12
forkpress branch merge-audit --revalidate --run 12 --format json
```

Useful filters include `--run`, `--scope all|db|files|plugin`,
`--records all|conflicts|conflict-events|decisions|resolutions|rollback-failures`,
`--conflict-key`, `--review-status unreviewed|pending|needs-action|reviewed`,
`--lifecycle-state unreviewed|deferred|needs-action|reviewed|validated|resolved`,
`--next-action review|run-plugin-validator|wait|revalidate|resolve|apply-reviewed-choice|manual-review|none`,
`--revalidation-class unchanged|compatible-target-drift|compatible-source-drift|compatible-schema-index-target-drift|compatible-schema-view-target-drift|compatible-schema-trigger-target-drift|missing|incompatible|replacement-evidence|unclassified`,
`--latest-revalidation-status none|current|source-drifted|target-drifted|source-and-target-drifted|unknown`,
`--stale-status fresh|stale|error|unknown`,
`--resolution-choice source|target`, `--blocked-resolution-choice source|target`,
`--resolution-strategy manual-review|plugin-validator|schema-choice|file-choice|row-choice|cell-choice`,
`--generic-resolver yes|no`, `--after-revalidate supported|unsupported`,
`--group-by`, `--target-kept`, `--path`, and `--path-prefix`.

`--revalidation-class` filters by what the latest revalidation found when it
ran. `--latest-revalidation-status` checks whether that latest recorded
source/target guard still matches live state now. Use
`--group-by latest-revalidation-status` to see which reviewed conflicts can
still be resolved with `--after-revalidate` and which need another revalidation
first. `--stale-status` filters by the conflict's current audited target
staleness before revalidation, and `--group-by stale-status` summarizes those
live fresh/stale/error/unknown queues.
`--resolution-strategy`, `--generic-resolver`, and `--after-revalidate` filter
conflict and conflict-event records by the resolver contract advertised on each
conflict. Use those filters, or the matching `--group-by` values, to build
queues such as generic resolver-ready conflicts, plugin-validator conflicts,
and conflicts that can only be applied after a current revalidation guard.

## Review and resolve conflicts

Record review status on an audit record:

```bash
forkpress branch merge-review conflict <id> \
  --status reviewed \
  --note "Verified in wp-admin"
forkpress branch merge-review conflict-key <key> --run <id> \
  --status reviewed \
  --note "Verified in wp-admin"
```

Validate or apply a conflict choice:

```bash
forkpress branch merge-resolve conflict <id> --choice source
forkpress branch merge-resolve conflict <id> --choice source --apply
forkpress branch merge-resolve conflict <id> --apply-reviewed
forkpress branch merge-resolve conflict-key <key> --run <id> --choice source --apply
```

Source and target choices are validated before they mutate target state. If a
resolution cannot be recorded after a file or database mutation, ForkPress rolls
the target back rather than keeping a partial result.
Validation-only resolutions are persisted as `validated` resolution records and
append `resolution-validated` conflict events. Applying a reviewed choice appends
`resolution-applied`; `--apply-reviewed` applies the latest unapplied validated
choice without asking the user to restate `source` or `target`.
Conflict keys can be used in place of numeric conflict ids only when the key
identifies one unresolved conflict, or when `--run <id>` disambiguates it.
Otherwise, use `merge-audit --conflict-key <key>` to pick the exact row.

`merge-audit --format json --records conflicts` treats conflicts as
first-class records. Each conflict includes a stable `conflict_key` for the
logical table/row/column/type conflict, a same-source/target-branch
`previous_conflict_id` when the conflict recurs in a later run or when a
validator records replacement evidence for the same logical conflict, a
`conflict_class`, a
`resolution_strategy`, executable `resolution_choices`, whether the generic
`merge-resolve conflict` path supports it, and whether `--after-revalidate` is
available. When a normally supported choice is blocked for this specific
conflict payload, `blocked_resolution_choices` maps that choice to the audit
reason. For filesystem conflicts this is how unsafe source payloads are exposed:
unsafe symlinks, unsupported source entries, unsafe directory replacement
subtrees, and source directory deletions that would remove target-side
descendants may still be reviewable conflicts, but `source` is omitted from
the executable choices and listed in `blocked_resolution_choices` with the
reason the resolver will reject it. Schema conflicts use the same model for
source drops that would invalidate target-side dependent views, triggers,
schema objects, or foreign-key child tables; once those dependency conflicts
are resolved, audit output can advertise `source` again. Row conflicts and
primary-key-addressable cell conflicts use the same model when
the current target foreign-key state would reject the audited source row,
source row deletion, source cell value, or source unique-collision replacement
that must first remove a target row with dependent children; after the missing
parent or blocking child dependency is resolved, audit output can advertise
`source` again.
Target-side `CHECK` constraints and trigger rewrites are also exposed as
blocked source choices with the recorded target-constraint reason, so clients do
not need to discover those blockers by attempting a resolver mutation. Audit
output also includes the
conflict
`lifecycle_state`, `next_action`, and latest resolution metadata so clients can
distinguish unreviewed, deferred, needs-action, reviewed, validated, and
resolved conflicts without parsing review notes. Conflict lifecycle changes are
also recorded in an append-only
`merge_conflict_events` stream, and audit JSON exposes the latest event summary.
UI clients should consume those fields instead of inferring behavior from raw
`conflict_type` strings or free-form notes.

Conflict rows are scoped to their merge run. Re-running the same source and
target branch pair with the same unresolved conflict payload records new
conflict rows and `recorded` lifecycle events for the new run, so run-scoped
audit output always owns the conflicts it reports. Those rows share the same
`conflict_key` and link to the previous row for the same source/target branch
pair through `previous_conflict_id`, while the same logical conflict on another
source branch gets its own lineage. A prior reviewed target resolution can
still auto-accept the same payload as a `target-accepted` decision instead of
reopening the conflict.

Plugin validator replacement findings use the same lineage model. A validator
rerun that reports changed evidence for the same plugin object records a newer
plugin conflict with the same `conflict_key` and a `previous_conflict_id` back to
the prior evidence row, so `--conflict-key` can show the original and replacement
evidence together.

Use `--records conflict-events` to inspect the full append-only lifecycle
history for conflict records. Use `--conflict-key <key>` with `--records
conflicts`, `conflict-events`, or `resolutions` to focus audit output on one
logical conflict group across repeated runs. Use `--lifecycle-state <state>`
with conflict records to build queues such as `unreviewed`, `needs-action`,
`validated`, or `resolved`, and with conflict-event records to inspect matching
history entries. Failed resolver attempts append `resolution-blocked` events
and move the unresolved conflict back to `needs-action` with a manual-review
next action, so UI queues do not keep offering a stale validated apply. Use
`--event-type resolution-blocked` to audit those blocked attempts. Use
`--next-action <action>` to build action-specific queues from the same
`next_action` field the JSON output exposes. Use lifecycle grouping
(`--records conflicts --group-by lifecycle`) or `--group-by next-action` to
summarize current conflict queues by lifecycle state or required action.
Use `--group-by resolution-strategy`, `--group-by generic-resolver`, or
`--group-by after-revalidate` to summarize the resolver contract for active
conflicts.

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

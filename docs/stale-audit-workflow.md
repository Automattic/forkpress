# Stale Merge Audits

Status: partial implementation

ForkPress already protects reviewed resolutions from applying to a target that
has changed since the conflict was audited. Resolution code checks the current
target payload against the audited payload and stops with `rerun merge-audit`
when they differ.

That is correct for safety. The implemented revalidation path now gives
reviewers a way back to the queue: `forkpress branch revalidate-reviews` scans
reviewed conflicts, detects stale or errored target payloads, and carries the
latest reviewed note into a new `needs-action` review note without applying any
resolution. It is idempotent, so rerunning it does not duplicate carried notes.

## Current Safety Contract

Today a resolution may apply only when:

- The conflict or decision still exists.
- The target row, cell, schema object, or filesystem path still matches the
  audited target payload.
- The source payload still matches the audited source payload where source
  application depends on it.
- Any target-side constraints still accept the requested source operation.

If any precondition changed, resolution fails. This prevents stale review notes
from silently overwriting newer target work.

## Implemented Revalidation Flow

```bash
forkpress branch revalidate-reviews
forkpress branch revalidate-reviews --run 12 --reviewer alice
forkpress branch revalidate-reviews --format json
forkpress branch revalidate-reviews --run 12 --quiet
forkpress branch merge-audit --revalidate --run 12 --reviewer alice
forkpress branch merge-audit --review --review-status needs-action
forkpress branch merge-audit --records conflicts --revalidation-class compatible-target-drift
forkpress branch merge-audit --records conflicts --latest-revalidation-status target-drifted
forkpress branch merge-audit --records conflicts --group-by latest-revalidation-status
forkpress branch merge-audit --records conflicts --stale-status stale
forkpress branch merge-audit --records conflicts --group-by stale-status
```

The command does not mutate the target branch. It only writes review metadata in
the merge metadata database. Fresh reviewed conflicts stay reviewed. Stale or
errored reviewed conflicts are reopened as `needs-action` with a note that
preserves the prior reviewer, status, and note text.
The text and JSON summaries include `needs_action_conflicts`, plus separate
`carried_conflicts` and `already_needs_action_conflicts` lists. Each entry
names the conflict id, run id, object, classifier, drift reason, revalidation
record, and replacement conflict id when one exists. That makes the next review
queue explicit without requiring a second query just to discover which conflict
ids were reopened. Use `--quiet` for automation that only wants the metadata
mutation and exit status.
The same transition is also recorded in `merge_conflict_events` as a
`revalidation-required` event linked to the `merge_revalidations` row, so
`merge-audit --records conflict-events` can reconstruct the reviewed ->
needs-action lifecycle without inferring it from summary fields.

Each recorded revalidation now includes a conservative `revalidation_class`.
Database row/cell and filesystem conflicts are classified as `unchanged`,
`compatible-target-drift`, `compatible-source-drift`, or `missing`.
No-primary-key database conflicts can also be classified as `incompatible` when
the reviewed logical row disappeared and its old physical rowid now belongs to
a different active sidecar identity. Supported WordPress primary-key row
conflicts are also classified as `incompatible` when either side keeps the same
key but changes semantic object identity after review. Current fingerprints
cover `wp_posts` `post_type`, `wp_options` `option_name`, `wp_postmeta`
`post_id`/`meta_key`, term slugs, term taxonomy `term_id`/`taxonomy`, termmeta
`term_id`/`meta_key`, user logins, usermeta `user_id`/`meta_key`, comment
`comment_post_ID`/`comment_type`, and commentmeta `comment_id`/`meta_key`.
Custom and plugin tables also get a conservative logical-identity check when a
row has a non-primary-key `UNIQUE` index with non-NULL values. If source or
target changes that unique key after review, revalidation classifies the review
as `incompatible`. New database cell conflicts persist audited source and
target row context, so the same logical-key drift is detected even when the
reviewed scalar cell value is unchanged. Older metadata without row-context
payloads falls back to the scalar payload checks. This does not infer
plugin-specific repair semantics; it only prevents guarded
`--after-revalidate` resolution from applying a reviewed row or cell over a
different logical object that happens to reuse the same numeric primary key.
Plugin validator conflicts are classified as `unchanged` when the rerun reports
the same evidence and `replacement-evidence` when the validator reports changed
evidence for the same plugin object. The replacement can come from changed
candidate evidence, changed target evidence, explicit changed `source`
payloads, or changed first-class `logical_identity` evidence emitted by the
validator. Replacement evidence also links the stale review to the newer
validator conflict row, so audit output can point reviewers at the exact
validator record that superseded their prior review. These classes and links
do not make the stale original review apply automatically. Generic
`source`/`target` merge resolution remains blocked for plugin conflicts, and
the stale original validator conflict cannot be resolved by a plugin driver
once replacement evidence exists.

A plugin-specific driver can resolve the current replacement validator conflict
only when the previous reviewed conflict has a latest `replacement-evidence`
revalidation whose replacement id points at that current conflict and whose
guarded payloads are still current. Driver resolution is rejected when no
replacement revalidation exists, when the latest revalidation is incompatible,
or when newer validator evidence has drifted past the replacement conflict.

Audit output also exposes the latest recorded revalidation as first-class
metadata: `latest_revalidation_id`, `latest_revalidation_class`,
`latest_revalidation_status`, `latest_revalidation_reason`,
`latest_revalidation_at`, and, when applicable,
`latest_revalidation_replacement_conflict_id`. The status is recomputed against
the current source and target payloads every time audit output is generated:

- `none`: no revalidation record exists for this conflict.
- `current`: the current source and target payloads still match the latest
  revalidation guard.
- `source-drifted`: the source payload changed after the latest revalidation.
- `target-drifted`: the target payload changed after the latest revalidation.
- `source-and-target-drifted`: both sides changed after the latest
  revalidation.
- `unknown`: the current payloads or recorded hashes are not sufficient to
  prove a current/drifted answer.

Use `merge-audit --latest-revalidation-status <status>` to find conflicts whose
last `--after-revalidate` guard is still current or has gone stale again. Use
`--group-by latest-revalidation-status` for queue summaries. This is different
from `--revalidation-class`: the class says what the last revalidation found at
the time it ran, while the latest status says whether that recorded guard still
matches the live source/target state now.

Use `merge-audit --stale-status fresh|stale|error|unknown` to query the live
pre-revalidation staleness that audit rows already expose, and
`--group-by stale-status` to summarize the current conflict queue without
client-side filtering. This is useful before deciding whether to revalidate
reviewed conflicts or resolve still-fresh ones.

Schema index, view, trigger, dropped-table restore, and table rebuild conflicts
now record current source/target SQL when revalidation finds drift and carry
reviewed conflicts back to `needs-action`. Source-added index, view, and
trigger conflicts can be classified as `compatible-schema-*-target-drift` when
the source object still matches review, the original target had no same-name
object, target drifted to a same-name object, and a dry-run source replacement
validates over the current target. Other schema drift remains `unclassified`:
treating changed DDL as compatible source drift requires a schema-specific
planner that can prove the same dependency graph and target preconditions still
hold.
Table rebuild conflicts also record rebuild-plan evidence for direct
indexes/triggers, dependent views, and dependent view triggers. That closes the
specific stale-audit blind spot where table SQL stayed unchanged but a source
index, trigger, or dependent view changed after review. Unclassified schema
conflicts should still be rerun manually while kept review-only until the
schema planner can prove a guarded resolution remains compatible.
The reviewed -> needs-action transition is still recorded as a
`revalidation-required` conflict event linked to the schema revalidation row, so
schema review UIs can show the lifecycle without treating free-form review
notes as state.

## Future Re-Audit Model

A richer re-audit command should build on the current conservative classifier by
comparing the old audited record with a fresh merge audit and classifying
reviewer intent:

- `unchanged`: the reviewed target/source payload still matches; keep the
  existing resolution state.
- `compatible-target-drift`: target changed, but the reviewed choice still
  refers to the same logical object and no source data would be lost; carry the
  review note forward and mark it as needing confirmation.
- `compatible-source-drift`: source changed in a way that still satisfies the
  same logical choice; carry the review note forward and mark it as needing
  confirmation.
- `incompatible`: payloads or identities changed enough that the previous
  intent is no longer meaningful; reopen as unreviewed.
- `missing`: the original conflict disappeared because the merge is now clean;
  close the old review note as superseded.

The key rule is that re-audit can preserve intent, but it must not apply a
resolution automatically after drift. A human or plugin validator still needs to
confirm any compatible drift.

## Metadata Needed

To support this cleanly, audit metadata should retain:

- Original conflict or decision id.
- Latest replacement conflict or decision id. Plugin validator revalidations
  now store `merge_revalidations.replacement_conflict_id` and expose the latest
  replacement conflict id in audit output.
- Previous review status and note.
- Re-audit classifier. The current `merge_revalidations.revalidation_class`
  stores `unchanged`, `compatible-target-drift`, `compatible-source-drift`,
  `missing`, `incompatible`, `replacement-evidence`, or `unclassified`;
  plugin validators can supply changed `source`, `target`, candidate, and
  `logical_identity` evidence through replacement findings. The same
  `incompatible` class is used when a custom/plugin table's non-primary-key
  `UNIQUE` logical key changes after review. Database cell conflicts can use
  recorded source/target row context for this classifier when the conflict was
  audited after that metadata became available.
  Schema index/view/trigger/table-restore/table-rebuild conflicts can record
  current source/target SQL but stay `unclassified`. Future work should add
  richer schema-specific evidence for dependency rebuild plans, plus explicit
  plugin-supplied logical identities where schema `UNIQUE` keys are not enough
  to prove object identity.
- Logical identity fingerprint separate from the raw payload.
- Re-audit timestamp and merge run id.

Logical identity matters because payload hashes alone cannot distinguish
unrelated edits from “same object, newer title”.

## Guarded Resolution After Revalidation

```bash
forkpress branch merge-resolve conflict <id> --choice source --after-revalidate --apply
```

`--after-revalidate` requires the latest review status to be `needs-action` and
the current source/target payload hashes to match the latest payloads recorded
by `merge-audit --revalidate` or `revalidate-reviews`. If the source or target
drifts again after revalidation, or if the latest revalidation was classified
as `incompatible`, guarded resolution fails and asks for another revalidation
instead of applying the stale original conflict.
The same condition is visible before attempting resolution with:

```bash
forkpress branch merge-audit --records conflicts --latest-revalidation-status current
forkpress branch merge-audit --records conflicts --latest-revalidation-status target-drifted
```

The current implementation supports database cell, database row, filesystem
conflicts, and compatible source-added schema index/view/trigger target drift.
Plugin validator conflicts now have a conservative validator-evidence
classifier: if a validator rerun records changed evidence for the same plugin
object, including changed source evidence, the reviewed plugin conflict returns
to `needs-action` with the replacement validator payload and replacement
conflict id visible in audit. Generic merge resolution still cannot apply
plugin conflicts. A plugin-specific driver may apply only the current
replacement conflict after a current `replacement-evidence` revalidation; stale
originals, unrevalidated replacements, incompatible revalidations, and drifted
replacement evidence remain blocked. Schema index, view, trigger, dropped-table
restore, and table rebuild conflicts can return to the review queue with
current SQL evidence, and table rebuild conflicts include dependency-plan
evidence. Guarded schema
resolution is intentionally limited to source-added index/view/trigger target
drift and compatible table-rebuild target drift where the planner recorded a
compatible schema class after a dry-run source replacement validated against
the current target.

## Test Shape

The implemented tests in `tests/cow/merge.php` cover stale cell/file detection,
carrying reviewed conflicts into `needs-action`, preserving prior reviewer
intent in the carried note, idempotent reruns, replacement revalidation payloads
after further target drift, guarded source resolution for database cells,
database rows, and filesystem paths after revalidation, revalidation classifiers
for stale database row/cell drift, source-drifted database row/cell and
filesystem conflicts, deleted database target rows, deleted filesystem target
paths, incompatible no-primary-key rowid replacement, incompatible replacement
for every currently supported source- and target-side WordPress row semantic
fingerprint, and plugin validator reruns that deduplicate unchanged evidence or
carry reviewed plugin conflicts back to `needs-action` with
`replacement-evidence`, replacement validator payloads, and replacement
conflict links when the validator reports changed evidence for the same plugin
object, including explicit changed plugin source evidence and first-class
plugin `logical_identity` evidence. Custom/plugin
non-primary-key `UNIQUE` logical-key replacements are also classified as
`incompatible` for both source and target drift, including row-context-backed
database cell conflicts where the reviewed cell value itself did not change.
Schema
index/view/trigger/table-restore/table-rebuild conflicts record changed
source/target SQL and carry reviewed conflicts back to `needs-action`. Source
added index/view/trigger target drift can be guarded-applied after revalidation
when it receives a compatible schema class; other schema drift remains
`unclassified` and review-only. Table rebuild fixtures also prove
dependency-only source drift is caught through direct index/trigger,
dependent-view, and dependent view-trigger evidence even when the reviewed table
SQL itself is unchanged.
Focused stale-audit tests also cover latest revalidation status output,
filtering, grouping, CLI JSON output, and text audit output, including the case
where target state drifts again after a previously current revalidation guard.

Future classifier tests should cover explicit plugin-supplied logical
fingerprints for primary-key row conflicts where schema `UNIQUE` keys are not
enough to prove object identity, plus guarded schema-specific resolution once a
planner can prove dependency compatibility.

The existing stale-resolution tests in `tests/cow/merge.php` should remain.
They prove stale resolutions are blocked. New tests should prove reviewers get
a structured way back to a fresh review queue.

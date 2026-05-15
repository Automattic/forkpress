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
forkpress branch merge-audit --revalidate --run 12 --reviewer alice
forkpress branch merge-audit --review --review-status needs-action
```

The command does not mutate the target branch. It only writes review metadata in
the merge metadata database. Fresh reviewed conflicts stay reviewed. Stale or
errored reviewed conflicts are reopened as `needs-action` with a note that
preserves the prior reviewer, status, and note text.

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
Plugin validator conflicts are classified as `unchanged` when the rerun reports
the same evidence and `replacement-evidence` when the validator reports changed
evidence for the same plugin object. Replacement evidence also links the stale
review to the newer validator conflict row, so audit output can point reviewers
at the exact validator record that superseded their prior review. These classes
and links are audit metadata only. They do not make stale reviews apply
automatically.

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
  `missing`, `incompatible`, `replacement-evidence`, or `unclassified`; future
  work should broaden source-drift coverage into plugin/schema-specific
  evidence and add broader incompatible logical-identity cases beyond the
  currently supported WordPress row fingerprints and no-primary-key rowid reuse.
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

The first implementation supports database cell, database row, and filesystem
conflicts. Plugin validator conflicts now have a conservative validator-evidence
classifier: if a validator rerun records changed evidence for the same plugin
object, the reviewed plugin conflict returns to `needs-action` with the
replacement validator payload and replacement conflict id visible in audit.
Generic merge resolution still cannot apply plugin conflicts; the plugin
validator or a plugin-specific repair flow remains the authority. Schema
conflicts still use the conservative stale-target guard until they have
schema-specific revalidation payloads.

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
fingerprint, and plugin validator reruns that carry reviewed plugin conflicts
back to `needs-action` with `replacement-evidence`, replacement validator
payloads, and replacement conflict links when the validator reports changed
evidence for the same plugin object.

Future classifier tests should cover plugin/custom primary-key row conflicts
where the row keeps the same key but a higher-level logical fingerprint proves
it now represents a different object.

The existing stale-resolution tests in `tests/cow/merge.php` should remain.
They prove stale resolutions are blocked. New tests should prove reviewers get
a structured way back to a fresh review queue.

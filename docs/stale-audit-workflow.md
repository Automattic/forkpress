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

## Future Re-Audit Model

A richer re-audit command should compare the old audited record with a fresh
merge audit and classify reviewer intent:

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
- Latest replacement conflict or decision id.
- Previous review status and note.
- Re-audit classifier: `unchanged`, `compatible-target-drift`,
  `compatible-source-drift`, `incompatible`, or `missing`.
- Logical identity fingerprint separate from the raw payload.
- Re-audit timestamp and merge run id.

Logical identity matters because payload hashes alone cannot distinguish
unrelated edits from “same object, newer title”.

## Future CLI Shape

```bash
forkpress branch resolve-conflict <id> --choice source --after-revalidate
```

`--after-revalidate` should require that the target/source payloads match the
latest revalidated audit record, not the stale original record.

## Test Shape

The implemented tests in `tests/cow/merge.php` cover stale cell/file detection,
carrying reviewed conflicts into `needs-action`, preserving prior reviewer
intent in the carried note, and idempotent reruns.

Future classifier tests should cover three records:

- A cell conflict where target drift is unrelated and can carry a note forward
  as `compatible-target-drift`.
- A row conflict where the target row identity changed and must become
  `incompatible`.
- A filesystem conflict where the target file changed and must remain blocked
  until the reviewer confirms the new payload.

The existing stale-resolution tests in `tests/cow/merge.php` should remain.
They prove stale resolutions are blocked. New tests should prove reviewers get
a structured way back to a fresh review queue.

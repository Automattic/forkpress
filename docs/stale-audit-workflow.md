# Stale Merge Audits

Status: design target

ForkPress already protects reviewed resolutions from applying to a target that
has changed since the conflict was audited. Resolution code checks the current
target payload against the audited payload and stops with `rerun merge-audit`
when they differ.

That is correct for safety, but it is rough for reviewers: the reviewer may
have made a valid choice, then unrelated target drift forces them to start over
without a structured way to carry that intent forward.

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

## Desired Re-Audit Flow

A future re-audit command should compare the old audited record with a fresh
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

## Suggested CLI

```bash
forkpress branch merge-audit --revalidate --format json
forkpress branch merge-audit --revalidate --review-status needs-action
forkpress branch resolve-conflict <id> --choice source --after-revalidate
```

`--revalidate` should not mutate the target branch. It should only write
review metadata and replacement links into the merge metadata database.

`--after-revalidate` should require that the target/source payloads match the
latest revalidated audit record, not the stale original record.

## Test Shape

The first test should cover three records:

- A cell conflict where target drift is unrelated and can carry a note forward
  as `compatible-target-drift`.
- A row conflict where the target row identity changed and must become
  `incompatible`.
- A filesystem conflict where the target file changed and must remain blocked
  until the reviewer confirms the new payload.

The existing stale-resolution tests in `tests/cow/merge.php` should remain.
They prove stale resolutions are blocked. New tests should prove reviewers get
a structured way back to a fresh review queue.

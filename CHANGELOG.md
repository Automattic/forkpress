# Changelog

## Unreleased

- Added COW branch mergeback for production materialized branches, including generic SQLite 3-way merge, conservative schema additions, filesystem mergeback, rollback handling, and audit metadata under `.forkpress/cow/merge`.
- Added branch-time AUTOINCREMENT ID bands, audited skips for plain `INTEGER PRIMARY KEY` tables, no-primary-key row identity sidecars, and runtime TEMP-trigger identity tracking.
- Added `forkpress branch merge-audit` inspection/export with JSON/text formats, file/path filters, review shortcuts, rollback failure artifacts, and ID-band skip reporting.
- Added `forkpress branch merge-review` metadata annotations for conflict and decision audit records.
- Added `forkpress branch merge-audit --review-status pending|needs-action|reviewed` to filter audit records by their latest review annotation.
- Added `forkpress branch merge-resolve conflict <id> --choice source|target` as the first validation-gated deterministic resolver for DB cell conflicts, row insert collisions, row delete/update conflicts, no-primary-key table conflicts backed by sidecar row identity, and safe filesystem path conflicts, with applied resolutions recorded in merge metadata.

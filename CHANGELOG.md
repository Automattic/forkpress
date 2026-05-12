# Changelog

## Unreleased

- Added COW branch mergeback for production materialized branches, including generic SQLite 3-way merge, conservative schema additions, filesystem mergeback, rollback handling, and audit metadata under `.forkpress/cow/merge`.
- Added branch-time AUTOINCREMENT ID bands, audited skips for plain `INTEGER PRIMARY KEY` tables, no-primary-key row identity sidecars, and runtime TEMP-trigger identity tracking.
- Added `forkpress branch merge-audit` inspection/export with JSON/text formats, file/path filters, review shortcuts, rollback failure artifacts, and ID-band skip reporting.
- Added `forkpress branch merge-review` metadata annotations for conflict and decision audit records.
- Added `forkpress branch merge-audit --review-status pending|needs-action|reviewed` to filter audit records by their latest review annotation.
- Added `forkpress branch merge-resolve conflict <id> --choice source|target` as the first validation-gated deterministic resolver for DB cell conflicts, row insert collisions, row delete/update conflicts, no-primary-key table conflicts backed by sidecar row identity, safe filesystem path conflicts, and safe source-added column/index schema conflicts, with applied resolutions recorded in merge metadata.
- Applied COW merge conflict resolutions now append a `reviewed` annotation to the resolved conflict audit record.
- Added `forkpress branch merge-audit --records resolutions --resolution-status validated|applied` to inspect deterministic conflict resolution records directly.
- Added `forkpress branch merge-audit --records resolutions --group-by table|status|path` to summarize deterministic resolution records for UI and assistant review.
- Added `forkpress branch merge-audit --records conflicts --group-by table|type|path|severity` to summarize reviewable conflict records for UI and assistant triage.
- Added `forkpress branch merge-audit --records decisions --group-by table|type|path` to summarize automatic merge decisions for UI and assistant triage.
- Broadened validation-gated schema conflict resolution to source index rewrites/drops and compatible table rebuilds that preserve target rows while applying audited source non-primary-key column definitions.

## known-good/cow-mergeback-mvp-2026-05-12

Verified as a major known-good checkpoint for the production materialized COW mergeback MVP:

- Generic state-based SQLite branch mergeback for WordPress core and arbitrary plugin tables without plugin declarations.
- Durable audit metadata for merge runs, decisions, conflicts, reviews, resolutions, ID-band allocations, sidecar row identities, and rollback failures.
- Branch-time AUTOINCREMENT band allocation and audited plain `INTEGER PRIMARY KEY` skip decisions.
- No-primary-key table sidecar identity capture, including runtime TEMP-trigger tracking for rowid delete/reuse cases.
- Filesystem mergeback for regular files, directories, safe relative symlinks, and target-wins audited conflicts for unsafe changes.
- Validation-gated deterministic conflict resolution for explicit-PK DB conflicts, no-PK DB conflicts via sidecar identity, safe filesystem conflicts, and safe source-added schema columns/indexes.
- CLI audit/review/resolution commands for inspecting, annotating, and revisiting automatic merge decisions.

Verification for the tag included:

- Fresh temporary runtime-bundle debug build of `target/debug/forkpress`.
- `tests/cow/e2e.sh target/debug/forkpress`.
- `php -l scripts/cow/merge.php`.
- `php -l tests/cow/merge.php`.
- `php tests/cow/merge.php`.
- `cargo fmt --check`.
- `cargo test -p forkpress-storage`.
- `FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli`.
- `FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli --features dev-experiments --bin forkpress-dev`.

# Changelog

## Unreleased

- Added COW branch mergeback for production materialized branches, including generic SQLite 3-way merge, conservative schema additions, filesystem mergeback, rollback handling, and audit metadata under `.forkpress/cow/merge`.
- Added branch-time AUTOINCREMENT ID bands, audited skips for plain `INTEGER PRIMARY KEY` tables, no-primary-key row identity sidecars, and runtime TEMP-trigger identity tracking.
- Added `forkpress branch merge-audit` inspection/export with JSON/text formats, file/path filters, review shortcuts, rollback failure artifacts, and ID-band skip reporting.
- Added `forkpress branch merge-review` metadata annotations for conflict and decision audit records.
- Extended `forkpress branch merge-review` annotations to deterministic resolution records.
- Added `forkpress branch merge-audit --review-status pending|needs-action|reviewed` to filter audit records by their latest review annotation.
- Added `forkpress branch merge-resolve conflict <id> --choice source|target` as the first validation-gated deterministic resolver for DB cell conflicts, row insert collisions, row delete/update conflicts, no-primary-key table conflicts backed by sidecar row identity, safe filesystem path conflicts, and safe source-added column/index schema conflicts, with applied resolutions recorded in merge metadata.
- Applied COW merge conflict resolutions now append a `reviewed` annotation to the resolved conflict audit record.
- Added `forkpress branch merge-audit --records resolutions --resolution-status validated|applied` to inspect deterministic conflict resolution records directly.
- Added `forkpress branch merge-audit --records resolutions --group-by table|status|path` to summarize deterministic resolution records for UI and assistant review.
- Added `forkpress branch merge-audit --records conflicts --group-by table|type|path|severity` to summarize reviewable conflict records for UI and assistant triage.
- Added `forkpress branch merge-audit --records decisions --group-by table|type|path` to summarize automatic merge decisions for UI and assistant triage.
- Broadened validation-gated schema conflict resolution to source index rewrites/drops and compatible table rebuilds that preserve target rows while applying audited source non-primary-key column definitions.
- Broadened compatible schema table rebuild resolution to preserve target explicit indexes, triggers, and dependent views when those views validate before and after the rebuild.
- Added generic COW schema merge/audit handling for SQLite views and triggers, including clean source-added object application and validation-gated resolution for audited source rewrites or drops.
- Tightened source view resolution so view rewrites preserve dependent target views/triggers when they validate, while source view drops are blocked until dependent target views/triggers are resolved.
- Tightened source view and compatible table schema resolution to preserve transitive dependent target views and view triggers when schema objects are dropped and recreated during validation-gated resolution.
- Added auditable COW handling for source-dropped SQLite tables, with validation-gated source resolution when no dependent target views would be left invalid.
- Tightened source table drop resolution so dependent target indexes/triggers must be resolved explicitly before the table drop can apply, preserving auditable resolution history for those schema objects.
- Added validation-gated source resolution for target-dropped SQLite tables, restoring the audited source table and rows only when the target table is still absent.
- Extended target-dropped table source resolution to restore source-side indexes and triggers removed by the target table drop, with the applied dependency SQL captured in resolution metadata.
- Added schema-level audit decisions for source-added table creation, including empty plugin tables that have no row-level merge decisions.
- Added `target-kept` audit decisions for target-only row inserts, row deletes, and cell changes that are preserved during mergeback.
- Added `target-kept` audit decisions for target-only COW schema tables, table schema changes, indexes, views, and triggers that are preserved during mergeback.
- Added `target-kept` audit decisions for target-only filesystem additions, deletions, and path changes that are preserved during COW mergeback.
- Added `forkpress branch merge-audit --target-kept` to focus audit output on preserved target/trunk-side merge decisions.
- Fixed the `--target-kept` audit shortcut to normalize default CLI records before combining with scope/path filters or decision grouping.
- Added runtime-backed COW e2e coverage for `forkpress branch merge-audit --target-kept` reporting preserved target-side file and data decisions through the real CLI.
- Added runtime-backed COW e2e coverage for `forkpress branch merge-audit --resolution-status validated` normalizing default CLI records to deterministic resolution audit output.
- Added runtime-backed COW e2e coverage for deterministic resolution records being reviewed through `forkpress branch merge-review resolution`.
- Added `forkpress branch merge-audit --review-status unreviewed` to isolate conflicts, decisions, and resolutions that have no review note yet.
- Added runtime-backed COW e2e coverage for `forkpress branch merge-audit --review-status unreviewed` returning unannotated audit records through the real CLI.
- Added COW audit coverage and docs for active reviewer queues combining `--review`, `--review-status unreviewed`, `--records conflicts`, and DB scope.
- Added COW audit coverage and docs for unreviewed deterministic resolution queues combining `--review`, `--review-status unreviewed`, `--records resolutions`, and DB scope.
- Updated the automatic mergeback loop runner to pass a configurable Codex reasoning-effort setting and to include the changelog/known-good tag policy in every worker prompt.

## known-good/cow-mergeback-audit-completeness-2026-05-12

Verified as a major known-good checkpoint for COW mergeback audit completeness after the view-dependency checkpoint:

- Source-added SQLite table creation is recorded as a schema-level `source-applied` decision, including empty plugin tables that have no row-level decisions.
- Target-only SQLite schema tables, table schema changes, indexes, views, and triggers are preserved and recorded as `target-kept` decisions.
- Target-only SQLite row inserts, row deletes, and cell changes are preserved and recorded as `target-kept` decisions.
- Target-only filesystem additions, deletions, and path changes are preserved and recorded as `target-kept` decisions under the existing `__files__` audit convention.
- Merge behavior remains plugin-declaration-free; the checkpoint only expands auditable records for applied or preserved state under ForkPress-owned `.forkpress/cow/merge` metadata.

Verification for the tag included:

- Fresh temporary runtime-bundle debug build of `target/debug/forkpress`.
- `tests/cow/e2e.sh target/debug/forkpress`.
- `php -l scripts/cow/merge.php`.
- `php -l tests/cow/merge.php`.
- `php tests/cow/merge.php`.
- `bash -n tests/cow/e2e.sh`.
- `cargo fmt --check`.
- `cargo test -p forkpress-storage`.
- `FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli`.
- `FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli --features dev-experiments --bin forkpress-dev`.

## known-good/cow-mergeback-view-dependencies-2026-05-12

Verified as a major known-good checkpoint for validation-gated COW schema dependency preservation after the schema-object layer:

- Source view rewrites preserve dependent target views and `INSTEAD OF` triggers when the dependency chain validates before and after mutation.
- Compatible table rebuilds and source view rewrites preserve transitive dependent target views and view triggers by dropping and recreating them in dependency order.
- Source view drops remain blocked until dependent target views/triggers are resolved explicitly, preserving an audit trail for every affected schema object.
- The checkpoint builds on `known-good/cow-mergeback-schema-objects-2026-05-12` without adding plugin declarations, custom schema handlers, merge keys, or conflict policies.

Verification for the tag included:

- Fresh temporary runtime-bundle debug build of `target/debug/forkpress`.
- `tests/cow/e2e.sh target/debug/forkpress`.
- `php -l scripts/cow/merge.php`.
- `php -l tests/cow/merge.php`.
- `php tests/cow/merge.php`.
- `bash -n tests/cow/e2e.sh`.
- `cargo fmt --check`.
- `cargo test -p forkpress-storage`.
- `FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli`.
- `FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli --features dev-experiments --bin forkpress-dev`.

## known-good/cow-mergeback-schema-objects-2026-05-12

Verified as a major known-good checkpoint for the post-schema-resolver COW mergeback schema-object layer:

- Generic schema merge/audit handling for SQLite views and triggers, including clean source-added object application.
- Validation-gated resolution for audited source view/trigger rewrites and drops.
- Auditable source-dropped SQLite table handling, with source resolution blocked until dependent target views, indexes, and triggers are handled explicitly.
- Resolution review annotations and runtime-backed e2e coverage for reviewing deterministic resolution records through the real `forkpress` binary.
- Loop-runner coordination updates for the changelog and known-good tag policy.

Verification for the tag included:

- Fresh temporary runtime-bundle debug build of `target/debug/forkpress`.
- `tests/cow/e2e.sh target/debug/forkpress`.
- `php -l scripts/cow/merge.php`.
- `php -l tests/cow/merge.php`.
- `php tests/cow/merge.php`.
- `bash -n tests/cow/e2e.sh`.
- `cargo fmt --check`.
- `cargo test -p forkpress-storage`.
- `FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli`.
- `FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli --features dev-experiments --bin forkpress-dev`.

## known-good/cow-mergeback-schema-resolver-2026-05-12

Verified as a major known-good checkpoint for the post-MVP COW mergeback schema resolver and audit triage layer:

- `merge-audit` grouping for deterministic resolutions, reviewable conflicts, and automatic decisions.
- Validation-gated schema resolution for source index rewrites, source index drops, and compatible table rebuilds.
- Compatible table rebuilds preserve target rows while applying audited source schema, and preserve target explicit indexes, triggers, and dependent views when they validate before and after the rebuild.
- Schema resolution remains plugin-declaration-free and keeps audit/resolution records in ForkPress-owned `.forkpress/cow/merge` metadata.

Verification for the tag included:

- Fresh temporary runtime-bundle debug build of `target/debug/forkpress`.
- `tests/cow/e2e.sh target/debug/forkpress`.
- `php -l scripts/cow/merge.php`.
- `php -l tests/cow/merge.php`.
- `php tests/cow/merge.php`.
- `bash -n tests/cow/e2e.sh`.
- `cargo fmt --check`.
- `cargo test -p forkpress-storage`.
- `FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli`.
- `FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli --features dev-experiments --bin forkpress-dev`.

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

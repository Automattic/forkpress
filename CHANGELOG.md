# Changelog

## Unreleased

- Added COW branch mergeback for production materialized branches, including generic SQLite 3-way merge, conservative schema additions, filesystem mergeback, rollback handling, and audit metadata under `.forkpress/cow/merge`.
- Added branch-time AUTOINCREMENT ID bands, audited skips for plain `INTEGER PRIMARY KEY` tables, no-primary-key row identity sidecars, and runtime TEMP-trigger identity tracking.
- Tightened no-primary-key identity capture/tracking metadata transactions so staged sidecar identities roll back if run-status recording fails.
- Tightened no-primary-key identity capture/tracking metadata commit failures so staged sidecars, history, and identity decisions roll back while failed runs remain auditable.
- Tightened branch-time AUTOINCREMENT ID-band allocation so target `sqlite_sequence` changes and ForkPress-owned metadata roll back together on commit failure.
- Added COW coverage for AUTOINCREMENT ID-band metadata commit failures, proving already-committed `sqlite_sequence` changes are restored and partial band metadata is discarded.
- Added COW coverage for AUTOINCREMENT rollback-failure artifacts when target snapshot restoration fails after ID-band metadata commit failure.
- Extended generic row unique-collision detection and validation-gated source resolution to normal-column partial unique indexes.
- Extended generic row unique-collision detection and validation-gated source resolution to expression unique indexes.
- Extended generic row unique-collision detection and validation-gated source resolution to collated composite partial expression unique indexes.
- Extended generic row unique-collision detection and validation-gated source resolution to source row updates that collide with target-side unique keys.
- Added auditable `row-target-constraint` conflicts when source row inserts or updates violate target-side SQLite constraints during generic COW mergeback.
- Added generic foreign-key preflight for COW row inserts/updates, including parent-before-child table merge ordering and validation-gated source resolution once missing parent rows are present.
- Ordered source rows inside same-table foreign-key plugin tables so source-only parent rows materialize before dependent child rows, including source-added tables.
- Ordered rows during validation-gated source restores of target-dropped same-table foreign-key plugin tables, preserving no-primary-key sidecar identities while restoring parent rows before children.
- Added COW coverage for validation-gated source restores of target-dropped foreign-key child tables after source-only or already restored parent tables.
- Added validation preflight for target-dropped foreign-key child table restores, reporting missing cross-table parent dependencies before dry-run or apply mutates target state.
- Added COW coverage for target-dropped foreign-key child table restore validation when the parent table exists but the required parent row is still absent.
- Recorded source-added table rows that are blocked by missing target-side foreign-key parents as auditable `row-target-constraint` conflicts instead of aborting the merge.
- Ordered source-added SQLite views by source-side view dependencies, and kept source-added views with missing restored-table dependencies as validation-gated `schema-source-added-view` conflicts.
- Held source-added SQLite view dependency cycles as explicit validation-gated `schema-source-added-view` conflicts instead of trying to guess an installation order.
- Kept reviewed source resolution for cyclic source-added SQLite views validation-gated with the explicit audited cycle reason.
- Held source-added SQLite views that would cycle with target-side views as validation-gated `schema-source-added-view` conflicts before target mutation.
- Added COW coverage for source-changed view rewrites that would cycle with preserved target-side views, keeping source resolution validation-gated until the target view dependency is handled.
- Added COW coverage for source-added view chains that cross source-only tables and restored target-dropped tables, keeping each view validation-gated until its dependencies exist.
- Added COW coverage for source-added trigger chains that cross restored tables, source-added views, and source-only tables, keeping each trigger validation-gated until its dependencies exist.
- Preflighted source-added trigger subject table/view dependencies so triggers attached to missing restored or source-added objects stay validation-gated with explicit audit metadata.
- Ordered acyclic source-added trigger programs by trigger subject/write dependencies so dependent trigger chains install without false schema conflicts.
- Held source-added trigger program cycles as explicit validation-gated `schema-source-added-trigger` conflicts instead of installing unsupported trigger graphs.
- Held source-added or source-resolved triggers that would cycle with target-side trigger programs as validation-gated schema conflicts instead of installing unsupported mixed trigger graphs.
- Added COW coverage for source-changed trigger rewrites that stay validation-gated until a source-dropped cyclic target trigger dependency is resolved.
- Held source table restores whose restored triggers would cycle with target-side trigger programs as validation-gated schema conflicts until the target trigger dependency is resolved.
- Extended source-added trigger dependency preflight to clear trigger body read references so triggers that read missing target-side schema objects stay validation-gated.
- Validated source-added trigger programs after installation so triggers with invalid target-side column references stay validation-gated instead of becoming latent runtime failures.
- Added COW coverage for source-added trigger program validation catching invalid `OLD`/`NEW` references and invalid `UPDATE OF` columns before target installation.
- Deferred source-added indexes and triggers that already have standalone schema conflicts during target-dropped table restores, so table rows can restore before those objects are resolved in dependency order.
- Tightened validation-gated source index resolutions so dry-run and apply both prove target rows satisfy the audited source index before reporting success.
- Tightened validation-gated source index rewrites and drops so dry-run and apply reject latent target foreign-key mismatches before reporting success.
- Tightened validation-gated source table restores and compatible table rebuilds so dry-run and apply reject latent foreign-key mismatches and invalid restored trigger programs before reporting success.
- Tightened automatic safe schema merge and source-added view/trigger apply savepoints so begin failures surface cleanly and roll back staged target/metadata state.
- Tightened validation-gated source table rebuild and source view resolution savepoints so begin failures surface cleanly without staging resolution metadata.
- Tightened COW schema savepoint release/rollback cleanup so cleanup failures surface without leaving partial schema, decision, conflict, or resolution metadata.
- Tightened schema object validation savepoint cleanup so release failures during source trigger/view dry-runs roll back target mutations and resolution metadata.
- Tightened foreign-key dependent rewrite savepoint cleanup so release failures abort and roll back the DB merge instead of becoming misleading row constraint conflicts.
- Tightened legacy review-note metadata migration savepoints so begin/release failures surface without leaving half-renamed metadata tables.
- Tightened COW metadata schema initialization savepoints so begin/release failures surface without leaving half-created audit tables.
- Tightened COW metadata schema DDL execution so table, index, and legacy migration statement failures roll back metadata setup cleanly.
- Tightened COW metadata schema inspection queries so table-info and review-note schema lookup failures roll back metadata setup cleanly.
- Tightened COW metadata schema inspection finalization so metadata setup aborts cleanly if SQLite result cleanup fails before migration DDL.
- Tightened COW metadata journal setup so failures surface before schema initialization can leave partial audit tables.
- Tightened COW SQLite open initialization so metadata database open failures surface before schema setup can create partial audit state.
- Tightened COW metadata statement preparation so run, review, conflict, resolution, and rollback-failure audit paths surface prepare failures consistently while preserving JSONL rollback artifacts.
- Tightened remaining COW sidecar identity and AUTOINCREMENT metadata prepare paths so prepare failures surface cleanly without partial sidecar or band state.
- Tightened AUTOINCREMENT target rowid and `sqlite_sequence` reads/writes so target-side allocation failures surface cleanly and roll back staged band metadata.
- Tightened core schema and row helper reads so table/index/object maps, table info, primary-key detection, and row loaders surface SQLite failures and finalize result sets explicitly.
- Tightened unique-index and foreign-key introspection reads so SQLite failures surface cleanly instead of being treated as absent constraints or unsupported merge evidence.
- Tightened DB conflict resolution row/cell target reads and single-cell target updates so SQLite failures surface cleanly without partial resolution metadata.
- Tightened COW merge-audit metadata read paths so table checks, schema inspection, and row-query prepare failures surface consistently through checked SQLite helpers.
- Tightened COW merge-audit metadata read finalization so table checks, schema inspection, and row queries surface SQLite cleanup failures consistently.
- Tightened no-primary-key sidecar and AUTOINCREMENT metadata read finalization so row identity and band lookup cleanup failures surface consistently without partial metadata.
- Tightened remaining COW metadata read finalization for run-context, review, resolver, conflict, restore-payload, and active sidecar lookups.
- Tightened central COW metadata statement execution paths so decision/conflict execute failures roll back target changes and staged audit rows cleanly.
- Tightened remaining COW sidecar, AUTOINCREMENT, review lookup, resolver lookup, and audit read statement execution paths so execute failures surface consistently without partial metadata.
- Tightened validation-gated source table restores so preserved target views and trigger programs that reference the restored table must still validate before dry-run or apply reports success.
- Added COW coverage for target-dropped table restore rollback when preserved target trigger bodies or target view triggers become invalid after the audited source table schema is restored.
- Added COW coverage for target-dropped table restore rollback when a preserved transitive target view trigger becomes invalid after the audited source table schema is restored.
- Tightened target-dropped table restore validation so preserved target trigger bodies that reference transitive dependent views are checked before dry-run or apply reports success.
- Added COW coverage for target-dropped table restore rollback when a preserved target trigger body references a deeper dependent view chain while other target triggers remain valid.
- Added COW coverage for target-dropped table restore rollback after restored source indexes/triggers apply but a later preserved target trigger validation fails, preserving index-backed FK child rows.
- Added COW coverage for target-dropped no-primary-key table restore apply rollback after restored source rows/indexes/triggers stage but a later preserved target trigger validation fails, including sidecar metadata rollback.
- Added COW coverage for target-dropped no-primary-key table restore dry-run rollback after restored source rows/indexes/triggers stage, including sidecar metadata rollback.
- Added COW coverage for target-dropped no-primary-key table restore rollback after source row sidecars stage but a later preserved foreign-key child validation fails.
- Added COW coverage for compatible table rebuild rollback when a preserved target trigger would become invalid while an index-backed foreign-key child table still depends on the rebuilt table.
- Added COW coverage for compatible table rebuild rollback when a preserved target view would become invalid after applying the audited source schema.
- Made generic DB merge metadata transactional with target DB mutation, so unexpected whole-merge rollbacks discard staged decisions, conflicts, and no-primary-key sidecars while keeping a failed run marker.
- Tightened direct DB merge transaction boundaries so target and metadata begin/commit failures use checked rollback paths and remain deterministically covered.
- Restored direct DB merge target snapshots when merge metadata commit fails after the target commit, keeping target rows/schema and ForkPress-owned metadata atomic.
- Added COW coverage for direct DB merge target snapshot restore failures, proving rollback-failure audit rows and JSONL backup artifacts remain available for recovery.
- Added COW coverage proving direct DB merge rollback-failure artifacts remain available after source-added index DDL has already committed and target snapshot restore fails.
- Added COW coverage proving rejected source-added index conflict metadata rolls back when a later direct DB merge rollback-failure artifact takes over audit reporting.
- Added COW coverage proving direct DB merge metadata commit failures roll back source-added index schema changes along with staged row/table work.
- Made mixed DB+filesystem merge rollback restore target filesystem changes after late metadata failures, keeping target DB, files, and `.forkpress/cow/merge` audit metadata aligned.
- Preserved rollback snapshot artifacts when mixed DB+filesystem rollback itself fails, and recorded DB/filesystem backup locations in the rollback-failure JSONL artifact.
- Added COW coverage for mixed DB+filesystem rollback failures where filesystem root restoration aborts after metadata restore, proving recovery snapshots stay queryable and preserved.
- Preserved per-file transaction artifacts when filesystem-phase rollback itself fails, keeping failed rollback backup paths inspectable through merge audit metadata.
- Made filesystem merge audit metadata atomic across planning and file operations, so failed file merges do not leave partial conflict or decision rows.
- Tightened filesystem merge metadata transaction boundaries so a failed metadata begin cannot leak file planning records outside the rollback scope.
- Re-recorded filesystem rollback failures after mixed DB+filesystem outer rollback restores metadata, keeping rollback failures queryable through merge audit.
- Added focused `forkpress branch merge-audit --records rollback-failures` inspection for failed rollback artifact records.
- Added runtime-backed COW e2e coverage for the real `forkpress branch merge-audit --records rollback-failures` CLI route.
- Recorded known-good checkpoint `known-good/cow-mergeback-schema-rollback-stability-2026-05-14` for the accumulated generic schema dependency validation and rollback/audit atomicity work after the identity-stability checkpoint.
- Recorded known-good checkpoint `known-good/cow-mergeback-rollback-atomicity-2026-05-14` for the accumulated DB/filesystem resolver, sidecar identity, AUTOINCREMENT, and direct merge rollback/audit atomicity work after the schema rollback checkpoint.
- Tightened filesystem conflict resolution rollback so metadata transaction failures restore target paths, preserve rollback artifacts if restore fails, and do not record partial resolution metadata.
- Tightened filesystem conflict resolution metadata transaction boundaries so commit failures restore target paths and roll back staged resolution/review metadata.
- Added COW coverage for target-choice row and filesystem resolution metadata commit failures, proving reviewed notes and resolution rows roll back while target state stays unchanged.
- Tightened COW run-status and failed-run metadata transactions so commit failures roll back staged run state instead of leaving half-written run markers.
- Added COW coverage for whole-branch rollback reporting when failed-run metadata commits fail after target DB/filesystem state has already been restored.
- Added COW coverage proving rollback-failure JSONL artifacts are still written when the SQLite rollback metadata sink is unavailable.
- Added COW coverage proving rollback-failure JSONL artifacts are still written when the SQLite rollback metadata sink rejects the best-effort audit row.
- Tightened validation-gated DB conflict resolution transaction checks so row/schema resolver target and metadata transactions fail loudly and roll back staged row mutations on metadata failures.
- Restored validation-gated DB conflict resolution target snapshots when metadata commit fails after the target commit, keeping row/schema apply paths atomic across target and merge metadata databases.
- Added COW coverage for schema resolution commit-order recovery, proving already-committed target schema mutations roll back when merge metadata commit fails.
- Added COW coverage for target-choice resolution metadata rollback, proving staged resolution rows and reviewed conflict notes roll back together when note recording fails.
- Tightened direct COW merge review annotations so metadata commit failures roll back staged review notes instead of leaving partial reviewer state.
- Added COW coverage for direct decision and resolution review-note metadata commit failures.
- Tightened validation-gated source view rewrites so preserved target view triggers must still compile before dry-run or apply reports success.
- Tightened validation-gated source table drops so dry-run and apply refuse to leave dependent target foreign-key child tables pointing at a missing parent table.
- Added COW coverage for metadata-only review/status/failed-run begin failures, proving no partial notes or run markers are written when audit transactions cannot start.
- Tightened target-dropped table restore dry-run savepoint handling so target and metadata savepoint begin failures surface without leaving partial schema or resolution metadata.
- Tightened compatible table rebuild and schema object dry-run validation savepoint handling so savepoint begin failures surface without target schema changes or partial resolution metadata.
- Tightened source table drop and source index schema resolution savepoint handling so begin failures surface without target schema changes or partial resolution metadata.
- Tightened validation-gated source table drops so dry-run and apply refuse to leave target trigger programs referencing the dropped table.
- Tightened validation-gated source view drops so dry-run and apply refuse to leave target trigger programs referencing the dropped view, including table-drop dependency chains.
- Added COW coverage for validation-gated source view drop chains where a parent view, dependent child view, and child view trigger must be resolved in dependency order.
- Added COW coverage for mixed validation-gated source drop chains where an FK child table, dependent view, and external trigger body reference must be resolved before the parent table drop.
- Materialized source-added table indexes before dependent source-added table rows, so foreign keys backed by source-added unique indexes do not produce false row constraint conflicts.
- Ignored statement-local CTE aliases during source-added trigger dependency preflight while still tracking real schema objects referenced inside the CTE.
- Treated source-added trigger references to temporary or attached SQLite schemas as validation-gated dependencies instead of matching same-named persistent tables.
- Tightened source-added view/trigger dependency parsing so quoted schema-qualified references are tracked while schema-looking text inside SQL literals or comments is ignored.
- Added explicit source-added view dependency preflight so missing, temporary, or attached-schema view references are held as auditable schema conflicts before target mutation.
- Recorded source-added triggers that reference missing target-side schema objects as auditable `schema-source-added-trigger` conflicts instead of installing latent invalid triggers.
- Added generic foreign-key preflight for COW source row deletes so target-side child rows keep their parent by default with auditable `row-target-constraint` metadata until a reviewed source delete validates.
- Applied clean source deletes across unchanged target-side foreign-key child graphs before deleting the parent, avoiding false `row-target-constraint` conflicts when source deletes the dependent rows too.
- Applied clean source updates to unchanged target-side foreign-key child rows before deleting the parent, avoiding false `row-target-constraint` conflicts when source reparents dependent rows away from the deleted parent.
- Applied clean source rewrites to unchanged foreign-key dependent rows when source changes a referenced child key and updates grandchildren to follow that key before deleting the original parent.
- Materialized source-only foreign-key parent rows inside the validated dependent-rewrite savepoint when source rewrites a primary key before deleting the original parent.
- Preserved sparse source `rowid` values and sidecar identities when materializing source-only no-primary-key foreign-key parent rows inside validated dependent rewrites.
- Added COW coverage for occupied-rowid no-primary-key foreign-key parent materialization falling back to a fresh target rowid while preserving the source sidecar identity.
- Added PHP and runtime-backed COW coverage for no-primary-key foreign-key child updates before parent deletes, including immediate target sidecar row-hash refresh.
- Added COW coverage for mixed no-primary-key foreign-key dependent delete/update rollback when a later dependent update fails target validation.
- Added COW coverage for multi-table no-primary-key foreign-key rewrite rollback where staged parent materializations and sidecar adoptions are discarded after a later child update fails validation.
- Refreshed no-primary-key sidecar row hashes immediately after validation-gated safe source-added column resolutions change target row shape.
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
- Added COW audit support, coverage, and docs for explicit file decision review queues combining `--review`, `--review-status unreviewed`, `--records decisions`, and file scope.
- Added COW audit coverage and docs for explicit database decision review queues combining `--review`, `--review-status unreviewed`, `--records decisions`, and DB scope.
- Added COW audit coverage and docs for filesystem conflict and deterministic resolution review queues combining `--review`, `--review-status unreviewed`, file scope, and explicit record types.
- Added COW audit coverage and docs for pending reviewer queues, including status transitions where the latest review note controls filtering.
- Added COW audit coverage and docs for needs-action reviewer queues across conflict and deterministic resolution follow-up workflows.
- Added COW audit coverage and docs for reviewed closure reports across conflict, decision, and deterministic resolution review workflows.
- Added runtime-backed COW e2e coverage for WordPress admin/REST post saves and arbitrary plugin AUTOINCREMENT inserts using branch-time ID bands.
- Added runtime-backed COW e2e coverage for merging independently banded WordPress-created posts between branches without ID collisions, including reviewable source-applied and target-kept audit decisions.
- Fixed generic COW SQLite mergeback so source-inserted rows that collide with target-side unique indexes are audited as target-wins `row-unique-collision` conflicts instead of aborting the merge.
- Added validation-gated source resolution for audited `row-unique-collision` conflicts, replacing the still-matching target row only after reviewer apply.
- Added runtime-backed COW e2e coverage for resolving arbitrary plugin-table `row-unique-collision` conflicts through the real CLI.
- Bounded offline no-primary-key `rowid` reuse ambiguity by recording auditable `row-identity-ambiguous` target-wins conflicts when a keyless source row looks like a full replacement while the target also changed, with validation-gated source resolution available after review.
- Added runtime-backed COW e2e coverage for the offline no-primary-key `rowid` ambiguity boundary and reviewed source resolution path.
- Tightened no-primary-key row identity ambiguity detection so untracked keyless source-only cell changes are not mixed into a target-changed row without runtime identity events.
- Added runtime-backed COW e2e coverage for partial offline no-primary-key `rowid` ambiguity where the source reuses a rowid while changing only target-unchanged cells.
- Collapsed identical no-primary-key source/target inserts that meet by a declared unique index into a non-conflicting auditable `source-applied` decision instead of a default target-wins collision.
- Added runtime-backed COW e2e coverage for identical keyless unique inserts merging through the real CLI without duplicating the row.
- Stabilized identical no-primary-key unique-insert collapses by adopting the source sidecar identity onto the existing target row, preventing repeated collapse decisions on rerun.
- Added COW merge coverage for identical no-primary-key inserts without declared uniqueness remaining separate audited rows instead of being collapsed.
- Recorded identical explicit-primary-key source/target inserts as auditable non-conflicting `source-applied` COW decisions instead of leaving the no-op implicit.
- Recorded identical explicit-primary-key source/target updates and deletes as auditable non-conflicting `source-applied` COW decisions instead of leaving the no-op implicit.
- Recorded identical no-primary-key source/target updates and deletes as auditable non-conflicting `source-applied` COW decisions when sidecar identity proves the row is the same logical row.
- Recorded identical source/target cell changes inside otherwise divergent rows as auditable non-conflicting `source-applied` COW decisions, including no-primary-key rows backed by sidecar identity.
- Recorded identical source/target SQLite table, index, view, and trigger schema changes as auditable non-conflicting `source-applied` COW decisions instead of leaving the no-op implicit.
- Recorded identical source/target table-column additions inside otherwise divergent SQLite table schemas as non-conflicting `source-applied` COW decisions.
- Recorded identical source/target filesystem additions, changes, and deletions as auditable non-conflicting `source-applied` COW decisions instead of leaving the no-op implicit.
- Added COW coverage for rerunning no-primary-key source conflict resolutions without rediscovering the resolved keyless cell, unique collision, delete, or row-identity ambiguity conflicts.
- Added COW coverage for rerunning validation-gated source table drop and target-dropped table restore resolutions without rediscovering the resolved schema conflicts.
- Added COW coverage for rerunning validation-gated filesystem source resolutions and schema object rewrite/drop resolutions without rediscovering the resolved conflicts.
- Added COW coverage for rerunning validation-gated compatible table rebuild and safe source-added column/index resolutions without rediscovering the resolved schema conflicts.
- Added COW coverage for rerunning dependency-preserving compatible table rebuild resolutions while retaining target indexes, triggers, dependent views, and view triggers.
- Added COW coverage for rerunning validation-gated target-choice DB cell, schema, and filesystem resolutions while preserving audited target state without duplicating unchanged conflict records.
- Treated rerun conflicts with a reviewed target-choice resolution as accepted target state, recording `target-accepted` decisions instead of reporting the unchanged divergence as a new active conflict.
- Added `target-accepted` counts to COW merge audit run and decision-group summaries so accepted target states are visible separately from active `target-wins` defaults.
- Fixed COW conflict recording to de-duplicate unchanged schema conflicts with null row identities using an explicit null-safe lookup before inserting metadata.
- Stabilized automatic no-primary-key source-applied row updates and deletes by refreshing or tombstoning target branch sidecar identities during the merge mutation.
- Tombstoned no-primary-key target sidecar identities during validation-gated schema table drops/restores so recreated tables cannot inherit stale rowid identity metadata.
- Preserved sparse no-primary-key rowids and refreshed target sidecar row hashes during validation-gated compatible table rebuild resolutions.
- Preserved sparse source rowids and sidecar identities when applying source-added no-primary-key tables or validation-gated source restores for target-dropped no-primary-key tables.
- Added COW coverage for source-added no-primary-key plugin tables carrying source indexes/triggers while preserving sparse rowids and sidecar identities.
- Added COW coverage for target-dropped no-primary-key plugin table restores carrying source indexes/triggers while preserving sparse rowids and sidecar identities.
- Updated the automatic mergeback loop runner to pass a configurable Codex reasoning-effort setting and to include the changelog/known-good tag policy in every worker prompt.

## known-good/cow-mergeback-rollback-atomicity-2026-05-14

Verified as a major known-good checkpoint for generic COW mergeback rollback and
audit atomicity after the schema-rollback-stability checkpoint:

- Filesystem conflict resolution restores target paths and rolls back staged
  resolution metadata if metadata recording fails, preserving rollback artifacts
  when restore fails.
- Row and schema conflict resolution apply paths check target and metadata
  transaction boundaries, restore target DB snapshots after metadata commit
  failures, and keep rollback-failure artifacts queryable when restoration fails.
- Review-note, target-choice resolution, no-primary-key identity capture/tracking,
  AUTOINCREMENT band allocation, direct DB merge, and mixed DB/filesystem merge
  paths keep ForkPress-owned metadata aligned with target DB/filesystem state.
- Direct DB merge metadata commit failures restore already-committed target
  rows/schema and discard staged decisions, conflicts, and no-primary-key sidecar
  metadata; failed target snapshot restoration keeps recovery artifacts.
- Rollback-failure audit records remain available through
  `forkpress branch merge-audit --records rollback-failures`, including the
  runtime-backed CLI route.

Verification for the tag included:

- `php -l scripts/cow/merge.php`.
- `php -l tests/cow/merge.php`.
- `bash -n tests/cow/e2e.sh`.
- `php tests/cow/merge.php`.
- `cargo fmt --check`.
- `cargo test -p forkpress-storage`.
- `FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli`.
- `FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli --features dev-experiments --bin forkpress-dev`.
- `cargo build --target x86_64-unknown-linux-musl -p forkpress-cli --bin forkpress`.
- `tests/cow/e2e.sh target/x86_64-unknown-linux-musl/debug/forkpress`.

## known-good/cow-mergeback-identity-stability-2026-05-13

Verified as a major known-good checkpoint for generic COW mergeback identity
stability and resolver reruns after the audit-completeness checkpoint:

- Branch-time AUTOINCREMENT allocation bands are covered through runtime-backed
  WordPress admin/REST post creation and arbitrary plugin table inserts.
- Generic unique-key row collisions are audited and can be resolved through the
  validation-gated source resolver for arbitrary plugin tables.
- No-primary-key plugin tables use sidecar identity metadata for runtime-tracked
  rowid reuse, bounded offline ambiguity, stable unique-insert collapses, and
  conservative duplicate-preserving behavior when no durable unique evidence
  exists.
- Identical source/target row, cell, schema, and filesystem changes are recorded
  as non-conflicting `source-applied` decisions when generic identity evidence is
  strong enough, instead of being left as implicit no-ops.
- Validation-gated DB, filesystem, and schema source resolutions have rerun
  coverage, including dependency-preserving compatible table rebuilds.
- Reviewed target-choice reruns are represented as auditable `target-accepted`
  decisions and counted separately from active `target-wins` defaults in audit
  summaries.

Verification for the tag included:

- `php -l scripts/cow/merge.php`.
- `php -l tests/cow/merge.php`.
- `bash -n tests/cow/e2e.sh`.
- `php tests/cow/merge.php`.
- `cargo fmt --check`.
- `cargo test -p forkpress-storage`.
- `FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli`.
- `FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli --features dev-experiments --bin forkpress-dev`.
- `cargo build --target x86_64-unknown-linux-musl -p forkpress-cli --bin forkpress`.
- `tests/cow/e2e.sh target/x86_64-unknown-linux-musl/debug/forkpress`.

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

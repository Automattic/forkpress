# Merge Reliability Matrix

Status: 2026-05-15

ForkPress COW merge is intentionally conservative: it should either apply a
source change exactly, preserve target state, or leave an auditable conflict.
It should not silently rewrite WordPress data to make conflicts disappear.

This page tracks what is already covered and where merge reliability still
depends on review, future validators, or broader end-to-end tests.

## Current Guarantees

- Branches have separate SQLite files and separate file trees.
- AUTOINCREMENT branch ID bands prevent routine ID collisions before JSON or
  serialized references are written.
- The DB merge records source-applied, target-kept, target-wins, conflict,
  resolution, ID-band, row-identity, and rollback-failure metadata outside the
  WordPress database.
- Source row inserts and updates are verified after SQLite accepts the write.
  If target-side triggers rewrote or removed the row, the write is rolled back
  and the merge remains reviewable.
- Reviewed source conflict resolutions use the same row postcondition guard.
- File changes are merged separately from DB rows and unsafe paths remain
  conflicts.
- Conflict resolution validates that the target still matches the audited
  value. If the target drifted, resolution stops and asks for a fresh audit.

## Reliability Gaps

| Area | Current state | Missing reliability work |
| --- | --- | --- |
| WordPress semantic objects | Tests cover real post creation, postmeta references, branch-local page edits/deletes, attachment uploads plus files, taxonomy terms, nav menus, reusable blocks, options with embedded object IDs, JSON option payloads with embedded object IDs, custom post types, plugin AUTOINCREMENT tables, keyless plugin tables, unique collisions, file additions, and branch merge visibility. | Add deeper concurrent edit/delete matrices, synced-pattern/block reference rewrites, menu-location assignments, attachment metadata regeneration, term hierarchy changes, and nested plugin-owned JSON/serialized graphs spanning custom tables and files. |
| Plugin-specific semantics | Generic SQLite merge is table/row/cell based and does not rewrite embedded IDs. `docs/plugin-merge-validators.md` defines the validator boundary and first test shape. | Implement the validator API, plugin-scoped audit records, and plugin-owned JSON/serialized/custom-table/file graph fixtures. |
| Review-only schema cases | Cyclic views/triggers, invalid preserved trigger/view dependencies, and some rebuild dependency chains are held as auditable conflicts. | Improve dependency planning so more safe schema reorderings can apply automatically. Keep non-deterministic or semantically ambiguous cases review-only. |
| Filesystem semantics | File additions/deletions/conflicts are audited; binary file changes/conflicts are hash-verified, symlink policy is tested, and unsupported or unsafe entries remain conflicts. | Add directory/file replacement UX, uploads-specific checks that connect attachment rows to files, and generated asset handling. |
| Crash consistency | DB, metadata, file, rollback-failure, ID-band, and Git publication paths have targeted rollback tests. `docs/merge-crash-consistency.md` maps covered boundaries and missing process-death failpoints. | Add external failpoint tests around target DB commit, metadata commit, file publish, sparsebundle detach/compact, Git publication, pruning, and cleanup. |
| Branch birth | CLI and Git-created branches allocate ID bands and capture merge base metadata. | Keep branch create, Git ref create, reset, and future UI creation on one invariant: DB base, file base, ID bands, and metadata must exist before user writes. |
| ID bands | AUTOINCREMENT bands protect common WordPress and plugin tables. Reset below old bands gets fresh bands. | Enforce bands before every write path, handle explicit-ID imports, cover non-AUTOINCREMENT `INTEGER PRIMARY KEY` plugin tables, and reject/review unsafe reuse. |
| Stale audits | Resolution fails if target no longer matches the audited payload. `docs/stale-audit-workflow.md` defines the revalidation model for carrying reviewer intent forward safely. | Implement the re-audit metadata, CLI, and compatibility classifiers. |
| Release gates | Linux, Windows, and x86_64 macOS release artifacts built in the last checked run. | Keep aarch64 macOS release and APFS sparsebundle E2E green; those gates are required before trusting an M1-ready artifact. |

## Test Direction

Every new reliability claim should have one of these test shapes:

- A PHP unit test in `tests/cow/merge.php` for deterministic DB merge behavior.
- A COW E2E test in `tests/cow/e2e.sh` for real WordPress/runtime behavior.
- A Rust unit test for CLI, storage, Git publication, release packaging, or
  platform-specific lifecycle behavior.
- A CI workflow gate when the behavior only exists on a target platform, such
  as APFS sparsebundles or Windows ReFS.

Prefer adding validators before adding automatic conflict resolution for plugin
or schema cases. A reliable reviewable conflict is better than an automatic
merge that invents WordPress semantics.

See `docs/plugin-merge-validators.md` for the proposed plugin validator
contract.

See `docs/stale-audit-workflow.md` for the proposed stale-audit revalidation
workflow.

See `docs/merge-crash-consistency.md` for the merge crash-consistency boundary
map.

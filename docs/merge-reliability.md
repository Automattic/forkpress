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
| WordPress semantic objects | Tests cover real post creation, postmeta references, branch-local page edits/deletes, same-object page/postmeta edit-vs-delete conflicts with auditable target-wins defaults, attachment uploads plus original and generated-size files, attachment metadata, hierarchical taxonomy terms, page-linked nav menus with menu-location assignments, reusable blocks and synced patterns, options with embedded object IDs, JSON option payloads with embedded object IDs, custom post types, plugin AUTOINCREMENT tables, keyless plugin tables, unique collisions, file additions, nested plugin-owned custom-table/JSON/serialized/file graphs, branch merge visibility, a discovered media validator that reports missing original/generated upload files plus `_wp_attached_file` versus `_wp_attachment_metadata` file drift, a discovered block-reference validator that reports pages/posts left pointing at deleted reusable blocks or synced patterns, a discovered menu-reference validator that reports nav menu items left pointing at deleted post objects, a discovered option-reference validator that reports serialized theme mods left pointing at deleted post objects, a discovered featured-image validator that reports `_thumbnail_id` postmeta left pointing at deleted attachment objects/files, a discovered image-block validator that reports `core/image` block JSON left pointing at deleted attachment objects/files, a discovered term-relationship validator that reports `wp_term_relationships` left pointing at deleted taxonomy term rows, and `docs/merge-repair-policy.md` defines when semantic repairs must remain review-only. | Add broader concurrent object matrices, implement only the repair policies that have deterministic owners, and broaden plugin-owned graph conflict/drift cases. |
| Plugin-specific semantics | Generic SQLite merge is table/row/cell based and does not rewrite embedded IDs. `docs/plugin-merge-validators.md` defines the validator boundary and first test shape. PHP unit and E2E coverage now cover the clean happy path for a plugin-owned custom-table graph with JSON, serialized option/postmeta references, referenced CPT data, and a referenced file. The PHP unit suite also covers the metadata/audit foundation for plugin-scoped validator conflicts, including review queues and grouping. Normal branch merges discover validators from active plugin and mu-plugin locations in the staged candidate target; discovered custom-table graph validators can abort and roll back a candidate with a broken JSON reference, or complete the merge with plugin-scoped review conflicts for target-conflicting graph state. `forkpress branch run-plugin-validator`, `forkpress branch record-plugin-validator-conflicts`, and `forkpress branch merge --plugin-validator <path>` expose explicit validator execution and findings recording. Validator failures after DB/files have staged roll back the merge. | Add broader plugin-owned validators for more real plugins and plugin merge drivers only where a plugin can prove an automatic repair is safe. |
| Review-only schema cases | Cyclic views/triggers, invalid preserved trigger/view dependencies, and some rebuild dependency chains are held as auditable conflicts. | Improve dependency planning so more safe schema reorderings can apply automatically. Keep non-deterministic or semantically ambiguous cases review-only. |
| Filesystem semantics | File additions/deletions/conflicts are audited; binary file changes/conflicts are hash-verified, safe relative symlinks can merge, unsafe symlinks to absolute paths, root-escaping paths, self-references, and ForkPress-managed paths remain conflicts, directory/file replacements get type-specific review conflicts, unchanged target descendants under reviewed replacements are preserved, reviewed source replacements can apply supported file/dir/symlink changes, WordPress E2E links attachment rows to original and generated-size upload files, and PHP coverage uses a discovered validator to cross-check attachment metadata against merged upload files and attached-file metadata drift. | Add stricter uploads-specific validators for more conflict/drift shapes, including attachment metadata regeneration decisions. |
| Crash consistency | DB, metadata, file, rollback-failure, ID-band, and Git publication paths have targeted rollback tests. `docs/merge-crash-consistency.md` maps covered boundaries and missing process-death failpoints. | Add external failpoint tests around target DB commit, metadata commit, file publish, sparsebundle detach/compact, Git publication, pruning, and cleanup. |
| Branch birth | CLI and Git-created branches allocate ID bands and capture merge base metadata. Existing branches reused by automation must still have a database, DB merge base, filesystem merge base, and required birth metadata before reuse. | Keep branch create, Git ref create, reset, and future UI creation on one invariant: DB base, file base, ID bands, and metadata must exist before user writes. |
| ID bands | AUTOINCREMENT bands protect common WordPress and plugin tables. Reset below old bands gets fresh bands. Explicit source IDs outside the reserved branch band are review-held instead of applied automatically for core WordPress and plugin AUTOINCREMENT tables. Non-AUTOINCREMENT `INTEGER PRIMARY KEY` plugin graph collisions are review-held and auditable as non-bandable tables. | Enforce bands before every write path, expand explicit-ID/import handling beyond covered AUTOINCREMENT row-insert cases, and reject/review unsafe reuse. |
| Stale audits | Resolution fails if target no longer matches the audited payload. `forkpress branch revalidate-reviews` and `forkpress branch merge-audit --revalidate` carry stale reviewed conflicts back into `needs-action` while preserving prior reviewer intent, avoiding duplicate carried notes, and recording revalidated payloads. `forkpress branch merge-resolve conflict <id> --after-revalidate` can apply reviewed stale database cell and filesystem conflicts only when the current source/target payloads still match the latest revalidation record. `docs/stale-audit-workflow.md` maps the current flow and the richer classifier model. | Add logical identity fingerprints, replacement-record links, compatibility classifiers, and guarded revalidation resolution for row, plugin, and schema conflicts. |
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

See `docs/merge-repair-policy.md` for the repair-versus-review policy for
WordPress and plugin semantic graphs.

See `docs/stale-audit-workflow.md` for the proposed stale-audit revalidation
workflow.

See `docs/merge-crash-consistency.md` for the merge crash-consistency boundary
map.

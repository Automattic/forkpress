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
| WordPress semantic objects | Tests cover real post creation, postmeta references, users, usermeta, post/comment authors, threaded comments and commentmeta references, branch-local page edits/deletes, same-object page/postmeta edit-vs-delete conflicts with auditable target-wins defaults, attachment uploads plus original and generated-size files, attachment metadata, hierarchical taxonomy terms, page-linked nav menus with menu-location assignments, reusable blocks and synced patterns, options with embedded object IDs, JSON option payloads with embedded object IDs, custom post types, plugin AUTOINCREMENT tables, keyless plugin tables, unique collisions, file additions, nested plugin-owned custom-table/JSON/serialized/file graphs, branch merge visibility, a discovered media validator that reports missing original/generated upload files, duplicate attachment claims on the same upload file, unreadable or NUL-corrupted attachment metadata, empty or unsafe primary/generated upload metadata paths, and `_wp_attached_file` versus `_wp_attachment_metadata` file drift, a discovered block-reference validator that reports pages/posts left pointing at deleted reusable blocks or synced patterns, a discovered menu-reference validator that reports nav menu items left pointing at deleted post objects, a discovered option-reference validator that reports serialized theme mods left pointing at deleted post objects, deleted nav-menu terms, or deleted custom-logo attachments plus serialized nav menu widgets, serialized media-image widgets, serialized sidebar-widget placements, scalar `site_icon`/`page_on_front`/`page_for_posts` options, and serialized `sticky_posts` options left pointing at deleted objects, a discovered featured-image validator that reports `_thumbnail_id` postmeta left pointing at deleted attachment objects/files, a discovered image-block validator that reports `core/image` block JSON left pointing at deleted attachment objects/files, a discovered term-relationship validator that reports `wp_term_relationships` left pointing at deleted taxonomy term rows, and `docs/merge-repair-policy.md` defines when semantic repairs must remain review-only. | Add broader concurrent object matrices, implement only the repair policies that have deterministic owners, and broaden plugin-owned graph conflict/drift cases. |
| Plugin-specific semantics | Generic SQLite merge is table/row/cell based and does not rewrite embedded IDs. `docs/plugin-merge-validators.md` defines the validator boundary and first test shape. PHP unit and E2E coverage now cover the clean happy path for a plugin-owned custom-table graph with JSON, serialized option/postmeta references, referenced CPT data, and a referenced file. The PHP unit suite also covers the metadata/audit foundation for plugin-scoped validator conflicts, including review queues and grouping. Normal branch merges discover validators from active plugin and mu-plugin locations in the staged candidate target; discovered custom-table graph validators can abort and roll back a candidate with a broken JSON reference, or complete the merge with plugin-scoped review conflicts for broken serialized graph row/file references and target-conflicting graph state. `forkpress branch run-plugin-validator`, `forkpress branch record-plugin-validator-conflicts`, and `forkpress branch merge --plugin-validator <path>` expose explicit validator execution and findings recording. Validator failures after DB/files have staged roll back the merge. | Add broader plugin-owned validators for more real plugins and plugin merge drivers only where a plugin can prove an automatic repair is safe. |
| Review-only schema cases | Cyclic views/triggers, invalid preserved trigger/view dependencies, and some rebuild dependency chains are held as auditable conflicts. | Improve dependency planning so more safe schema reorderings can apply automatically. Keep non-deterministic or semantically ambiguous cases review-only. |
| Filesystem semantics | File additions/deletions/conflicts are audited; binary file changes/conflicts are hash-verified, safe relative symlinks can merge, unsafe symlinks to absolute paths, root-escaping paths, self-references, and ForkPress-managed paths remain conflicts, directory/file replacements get type-specific review conflicts, unchanged target descendants under reviewed replacements are preserved, reviewed source replacements can apply supported file/dir/symlink changes, WordPress E2E links attachment rows to original and generated-size upload files, and PHP coverage uses a discovered validator to cross-check attachment metadata against merged upload files and attached-file metadata drift. | Add stricter uploads-specific validators for more conflict/drift shapes, including attachment metadata regeneration decisions. |
| Crash consistency | DB, metadata, file, rollback-failure, ID-band, and Git publication paths have targeted rollback tests. DB merge process-death coverage includes crashes before/after target DB commit and before metadata commit, with pending crash artifacts that block later merges until explicit recovery. Whole-branch DB+file merges now keep recoverable target DB, metadata DB, and filesystem-root snapshots across the file phase, so a hard exit after DB commit, before file mutation, or after an individual file operation blocks later merges until `recover-crash --restore-target-db --restore-files` restores a coherent pre-merge state. Git server process-death coverage includes created-branch metadata/storage/public-link/list publication, existing-branch update publication, delete staging, and object pruning. `docs/merge-crash-consistency.md` maps covered boundaries and missing product-level failpoint work. | Add an external product-level kill harness for public `forkpress` commands, plus platform-specific coverage around sparsebundle detach/compact and rollback-artifact cleanup. |
| Branch birth | CLI and Git-created branches allocate ID bands and capture merge base metadata. Git-created branches finalize birth metadata before publishing the branch tree, so a pre-metadata crash cannot expose a branch without ID bands or row identities. Existing branches reused by automation must still have a database, DB merge base, filesystem merge base, and required birth metadata before reuse. The WordPress admin branch create/merge UI is covered as a thin wrapper over the same CLI paths, including validation, CLI failure surfacing, and real runtime E2E create/merge requests. | Keep branch create, Git ref create, reset, and UI creation on one invariant: DB base, file base, ID bands, and metadata must exist before user writes. |
| ID bands | AUTOINCREMENT bands protect common WordPress and plugin tables. Reset below old bands gets fresh bands. Explicit source IDs outside the reserved branch band are review-held instead of applied automatically for core WordPress and plugin AUTOINCREMENT tables, paired source deletes in the same AUTOINCREMENT table are also held when an out-of-band explicit insert is held, and source child `wp_posts`, owner/reference `wp_postmeta` including type-aware nav menu object refs, threaded `wp_comments`, `wp_commentmeta`, hierarchical `wp_term_taxonomy`, `wp_term_relationships`, `wp_usermeta`, post authors, or comment user refs pointing at held explicit post/term/user IDs are review-held instead of leaving orphan WordPress child rows. Non-AUTOINCREMENT `INTEGER PRIMARY KEY` plugin graph collisions are review-held and auditable as non-bandable tables. | Enforce bands before every write path, expand explicit-ID/import handling beyond covered AUTOINCREMENT row-insert cases, and reject/review unsafe reuse. |
| Stale audits | Resolution fails if target no longer matches the audited payload. `forkpress branch revalidate-reviews` and `forkpress branch merge-audit --revalidate` carry stale reviewed conflicts back into `needs-action` while preserving prior reviewer intent, avoiding duplicate carried notes, and recording revalidated payloads. `forkpress branch merge-resolve conflict <id> --after-revalidate` can apply reviewed stale database row/cell and filesystem conflicts only when the current source/target payloads still match the latest revalidation record. Plugin validator conflicts now return to `needs-action` when a validator rerun records changed evidence for the same plugin object, but remain outside generic guarded resolution; schema conflicts still need schema-specific evidence/planning. `docs/stale-audit-workflow.md` maps the current flow and the richer classifier model. | Add logical identity fingerprints, replacement-record links, compatibility classifiers, and guarded revalidation resolution for plugin and schema conflicts. |
| Release gates | Linux, Windows, and x86_64 macOS release artifacts built in the last checked run. macOS and Linux release workflows install static PHP build prerequisites up front, `scripts/build-dist.sh` now fails before cloning/building PHP if those tools are missing instead of letting `static-php-cli` mutate package-manager state during the release bundle step, and the default `static-php-cli` checkout is pinned to a known upstream commit with an explicit override for deliberate upgrades. | Keep aarch64 macOS release and APFS sparsebundle E2E green; those gates are required before trusting an M1-ready artifact. |

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

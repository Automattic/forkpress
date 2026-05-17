# Merge Reliability Matrix

Status: 2026-05-16

ForkPress COW merge is intentionally conservative: it should either apply a
source change exactly, preserve target state, or leave an auditable conflict.
It should not silently rewrite WordPress data to make conflicts disappear.

This page tracks what is already covered and where merge reliability still
depends on review, future validators, or broader end-to-end tests.

## Objective Audit

This audit maps the current reliability objective to concrete artifacts. It is
intentionally stricter than "tests pass": an item is only treated as covered
when there is a test or document that exercises the specific merge invariant.

Recent additions released through `v0.1.33`:

- Plugin validators now receive first-class pre-merge target context:
  `FORKPRESS_MERGE_TARGET_BEFORE_DB` and, when file context exists,
  `FORKPRESS_MERGE_TARGET_BEFORE_ROOT`. The focused
  `tests/cow/plugin_validator.php` contract probe verifies that a validator can
  compare the candidate target with the target-before snapshot.
- `tests/cow/wp_semantic_validator.php` now includes a plugin custom-post-type
  graph validator case where a deleted CPT row leaves plugin custom-table JSON,
  serialized PHP, and option references stale. The merge stays reviewable and
  plugin-scoped audit output identifies both stale graph owners.
- Reviewed table rebuild conflicts now retain rebuild-plan evidence for direct
  source indexes/triggers, dependent views, and dependent view triggers.
  `tests/cow/schema_review.php` proves dependency-only source drift returns the
  reviewed conflict to `needs-action` even when the table DDL itself did not
  change.

| Objective item | Evidence on trunk | Remaining gap |
| --- | --- | --- |
| 1. Real WordPress semantic merge coverage | `tests/cow/e2e.sh` creates source and target branches through runtime WordPress requests, validates each branch-local graph before merge, then merges pages, branch-local page edits/deletes with edited content and authors, postmeta, users/usermeta, authors, comments/commentmeta, hierarchical taxonomy terms, nav menus and menu locations, reusable `wp_block` rows, page-to-reusable-block refs, `core/image` block refs and featured-image refs to media attachments, options and JSON options with branch user/object IDs, media uploads with attachment parents plus generated-size metadata/files, a CPT-like `forkpress_note`, and plugin-shaped custom tables/files. The semantic E2E merge now requires `status: completed` and a zero-conflict merge run, so runtime-only state cannot hide behind a surviving object graph. The branch UI E2E also submits branch create without `Accept: application/json` or `X-ForkPress-Async`, and fails if WordPress HTML reaches the caller instead of ForkPress JSON. `tests/cow/merge_smoke.php` now fast-gates page create/create, edit/create, delete/create, page-plus-postmeta create/create, page-plus-comment create/create, page-plus-custom-post-type create/create, page-plus-taxonomy create/create, page-plus-menu create/create, page-plus-reusable-block create/create, page-plus-attachment create/create, page-plus-image-block create/create, page-plus-gallery create/create, page-plus-file-block create/create, page-plus-media-text create/create, and page-plus-options JSON/serialized create/create invariants without starting WordPress: independent main inserts must survive while branch page inserts, edits, deletes, postmeta graph rows, comment users, usermeta, comments, commentmeta, plugin-like custom post type rows, CPT postmeta, CPT taxonomy relationships, CPT option refs, terms, term-taxonomy rows, page-term relationships, nav menu terms, menu item posts/postmeta/relationships, merged theme-mod menu locations, reusable `wp_block` rows referenced from block comments, attachment rows, featured-image and attachment metadata, `core/image`, `core/gallery`, `core/file`, and `core/media-text` block JSON refs, upload files, and JSON/serialized option references apply cleanly with zero conflicts while preserving branch-specific IDs without rewrite. It also fast-gates same-object page/postmeta edit-vs-delete conflicts, user/usermeta edit-vs-delete conflicts, comment/commentmeta edit-vs-delete conflicts, custom-post-type edit-vs-delete conflicts across CPT rows, CPT postmeta, CPT option indexes, and CPT taxonomy relationships, taxonomy-term edit-vs-delete conflicts across terms, term-taxonomy rows, termmeta, and page-term relationships, reusable-block edit-vs-delete conflicts across the `wp_block` row and target page cleanup, navigation-menu edit-vs-delete conflicts across menu terms, term-taxonomy rows, menu item posts, menu item metadata, relationships, and theme-mod location cleanup, attachment edit-vs-delete conflicts across attachment rows, metadata rows, original files, and generated files, plus JSON and serialized option edit-vs-delete conflicts. `tests/cow/wp_semantic_validator.php` is a focused fast gate for discovered WordPress semantic validators that catch pages left pointing at deleted reusable blocks, synced patterns, navigation blocks, or template parts, child pages or attachments left pointing at deleted `post_parent` rows, posts or attachments left pointing at deleted `post_author` users, postmeta left pointing at deleted posts, usermeta left pointing at deleted users, nav menu items left pointing at deleted parent menu items, pages, or taxonomy terms, featured-image postmeta left pointing at deleted attachment rows/files, `core/audio`, `core/cover`, `core/file`, `core/image`, `core/video`, `core/media-text`, and `core/gallery` block JSON left pointing at deleted attachment rows/files, `core/avatar` and `core/latest-posts` block JSON left pointing at deleted users, `core/navigation-link` and `core/navigation-submenu` block JSON left pointing at deleted pages or taxonomy terms, `core/query` block JSON including `taxQuery` and `core/latest-posts` category filters left pointing at deleted author users or taxonomy terms, term relationships left pointing at deleted taxonomy terms, term taxonomy rows left pointing at deleted terms, child taxonomy terms left pointing at deleted parent terms, termmeta left pointing at deleted terms, comments left pointing at deleted posts/users/parent comments, commentmeta left pointing at deleted comments, and options/widgets/theme mods, including block, text, and custom HTML widget content, media widgets, pages widgets, and nav menu auto-add options, left pointing at deleted WordPress objects. `tests/cow/merge.php` adds deterministic WordPress row fingerprint and validator coverage. | Add broader concurrent edit/delete matrices for complete WP objects and deterministic repair policies only where the owner object is unambiguous. |
| 2. Plugin-specific merge semantics | `docs/plugin-merge-validators.md` defines the validator contract, including rejecting contradictory status/finding output and optional first-class `severity` and `logical_identity` evidence for plugin-defined object identity. `scripts/cow/merge.php` discovers active plugin and mu-plugin validators, runs explicit validators, records plugin-scoped conflicts, validates first-class plugin validator identity, severity, review-guidance, and logical-identity fields, filters plugin audit queues by plugin, plugin object, plugin severity, and plugin logical identity, groups plugin conflict, event, and resolution queues by the same first-class fields, rolls back inline validator failures, and records explicit `plugin-driver` resolution evidence without allowing generic source/target resolution of plugin conflicts. `tests/cow/plugin_validator.php` is a focused fast gate for discovered validator review of plugin-owned DB/JSON/file graphs and serialized/JSON option/postmeta/file graphs, plugin-scoped audit output for incoherent JSON, missing or unsafe file references including URL-like and Windows drive-letter plugin file references, stale serialized/JSON asset references, identical validator rerun dedupe, contradictory validator output rejection, malformed validator identity rejection, replacement-evidence revalidation when validator findings change after review, explicit plugin source-evidence drift recorded by validator reruns, plugin `severity` audit fields, plugin object/severity/logical-identity filters and groupings, generic merge-resolve rejection for plugin conflicts, explicit plugin-driver repair resolution audit, and `logical_identity` drift returning reviewed findings to `needs-action`. `tests/cow/merge.php` covers clean custom-table graph merges, validator findings, plugin and plugin-severity conflict grouping, audit/review grouping, validator rerun evidence, file-root context, active-plugin discovery, explicit-ID plugin graph validation, contradictory validator output rejection, and failed-validator rollback. `tests/cow/e2e.sh` covers a runtime plugin-shaped graph across custom table parent/child rows, child JSON payload refs, JSON, serialized data, options, postmeta, CPT data, and branch-owned file contents. | Add validators for real plugins and add merge drivers only for plugin-owned repairs that can prove correctness. |
| 3. Remaining review-only schema cases | `scripts/cow/merge.php` validates source-added views/triggers/indexes, preserves invalid dependency cases as conflicts, and supports safe schema object resolution for deterministic subsets. `tests/cow/schema_review.php` is a focused fast gate proving acyclic source-added dependent views, views depending on source-added tables, trigger programs, and triggers depending on source-added tables including trigger `WHEN` clauses apply in dependency order, cyclic source-added views/triggers stay reviewable, source-added triggers with missing target dependencies stay gated until the dependency is restored, source-added table/view drops with unresolved dependent target views/triggers/schema objects stay reviewable with blocked source choices until the dependencies are resolved, source-added expression unique indexes blocked by target rows stay reviewable until the blocking rows are removed, and reviewed source-added indexes/views/triggers, dropped-table restores, and table rebuild conflicts return to `needs-action` with current source SQL evidence when the reviewed source SQL changes after review. Table rebuild conflicts also retain rebuild-plan evidence for direct indexes/triggers, dependent views, and dependent view triggers, so dependency-only source drift returns reviewed conflicts to `needs-action`. It also covers target-side SQL drift for reviewed source-added view, trigger, index, dropped-table restore, and table rebuild conflicts, including current target SQL evidence; source-added index, view, and trigger target drift can be classified as `compatible-schema-*-target-drift` and applied with `--after-revalidate` when dry-run source replacement validates against the current target. `tests/cow/merge.php` covers broader cyclic/invalid view and trigger dependency handling, source-added dependent view/trigger/index ordering, and rebuild validation cases. | Improve dependency planning for more safe reorderings. Add guarded schema revalidation resolution flows beyond source-added object target drift where the schema planner can prove compatibility. Cyclic or semantically ambiguous cases should stay review-only. |
| 4. Filesystem merge hardening | `tests/cow/filesystem.php` is a focused fast gate for safe source text/binary file application, conflicting binary file edits staying target-kept with hash payload metadata instead of text decoding, safe relative symlink changes/additions, unsafe absolute/root-escaping/self-referential/managed-path symlinks staying as auditable file conflicts, unsupported special source filesystem entries staying review-held and not force-applicable as reviewed source resolutions on platforms with FIFO support, directory/file type replacements staying review-held until an explicit audited source resolution applies them, unsafe directory replacement subtrees blocking source resolution before mutation, and source directory deletions staying review-held with source resolution blocked when target descendants exist. `tests/cow/media_validator.php` fast-gates discovered upload validators for missing required attachment metadata rows, invalid serialized attachment metadata, malformed `image_meta`, invalid original/generated/backup dimensions, original/generated/backup filesize drift, attachment `post_mime_type` and generated-size/backup `mime-type` drift against known upload file extensions including AVIF and PDF, malformed or incomplete generated-size and backup-size metadata, generated-size, `original_image`, and backup-size filename drift, missing original/generated/`original_image`/backup upload files from `_wp_attached_file` and `_wp_attachment_metadata`, duplicate original/generated/backup upload ownership, unsafe primary/metadata/generated/`original_image`/backup upload paths including URL-like and Windows drive-letter primary upload metadata, `_wp_attached_file` versus `_wp_attachment_metadata['file']` drift, and review-only regeneration guidance for missing generated derivatives. `tests/cow/merge.php` covers file adds/deletes/conflicts, binary hash comparisons, symlink safety, directory/file and file/directory replacement review, rollback artifacts, upload-file validators, generated and backup attachment file checks, original/generated dimension drift, malformed `image_meta`, generated-size filename drift, featured-image/image-block/media metadata drift, and unsafe metadata paths. `tests/cow/e2e.sh` verifies real merged upload originals and generated thumbnails. | Add stricter uploads-specific validators for more drift shapes and implement a deterministic media repair driver only if WordPress can prove exact regeneration. |
| 5. Crash consistency across DB/files/metadata/Git | `docs/merge-crash-consistency.md` lists the covered boundaries. `tests/cow/merge.php` covers target DB, metadata, file, rollback-failure, ID-band, and whole-branch rollback paths. `tests/cow/e2e.sh` drives public merge/create/reset/recover crash/retry flows for DB, metadata, before-file, after-file, recovery-cleanup, branch-birth, branch-reset publication failpoints, and actual smart-HTTP Git-created branch pushes interrupted before branch-birth metadata, before branch-list publication, and after branch-list publication, each verified after a fresh server restart. `tests/cow/git_server.php` covers Git-created branch birth, Git update/delete, stale cleanup, and object-prune interruption. | Broaden external kill harness coverage across the remaining Git-push failpoints and platform-specific APFS/cleanup checkpoints, then verify post-crash state from a fresh process. |
| 6. Branch birth always captures merge bases | `crates/forkpress-storage/src/lib.rs` requires branch birth metadata for branch reuse/merge and blocks pending reset states. `tests/cow/branch_birth.php` fast-gates required ID bands, keyless row identities, filesystem merge-base capture as a frozen pre-write snapshot with managed DB/config/Git exclusions, cleanup of rollback metadata, and cleanup isolation for unrelated branch metadata. `tests/cow/git_server.php` covers Git-created branch DB/file base, ID-band, row identity, decision/run metadata, branch-birth decision cleanup, DB merge-base sidecar cleanup, file-base cleanup, and cleanup/rollback paths. `tests/cow/e2e.sh` covers public create retry after interrupted birth metadata, public reset retry after interrupted reset publication, and remote-cache branch creation followed by AUTOINCREMENT-band insertion and mergeback to `main`. | Keep every new creation/reuse/reset path under the same invariant and add regressions whenever a new branch publication path is introduced. |
| 7. ID-band enforcement beyond happy paths | `tests/cow/id_bands.php` is a focused fast gate for separate branch AUTOINCREMENT bands, JSON/serialized references that keep branch IDs distinct without rewrite, normal in-band branch reuse that refreshes existing bands instead of allocating fresh ones, reset protection that allocates fresh bands when a branch DB drops below its old reservation, non-colliding non-AUTOINCREMENT `INTEGER PRIMARY KEY` plugin rows, review-held non-AUTOINCREMENT `INTEGER PRIMARY KEY` plugin collisions including ordinary implicit rowid allocation where both branches independently receive `id=1`, and audit/text output that names non-bandable plugin tables and explains the missing durable `sqlite_sequence` reservation point. `tests/cow/explicit_ids.php` fast-gates in-band explicit AUTOINCREMENT imports that should merge without ID rewrite, out-of-band AUTOINCREMENT inserts and primary-key rewrites for WordPress and plugin tables, paired source deletes held behind explicit inserts, inserted or updated `wp_posts` rows with `post_author`, `core/avatar`, and `core/query` author references behind out-of-band explicit `wp_users` imports, inserted or updated `wp_usermeta` and `wp_comments` rows held behind out-of-band explicit `wp_users` imports, inserted or updated `wp_commentmeta` plus threaded comments behind out-of-band explicit `wp_comments` imports, inserted or updated featured-image postmeta, image-block content, `site_icon`, theme-mod `custom_logo`, and media/block/text/custom HTML widget options held behind out-of-band explicit attachment imports, pages-widget options held behind out-of-band explicit page imports, and inserted or updated `wp_termmeta`, `wp_term_taxonomy`, `wp_term_relationships`, serialized theme-mod menu locations, nav-menu widgets, and `nav_menu_options` options held behind out-of-band explicit `wp_terms` imports. `tests/cow/merge.php` covers AUTOINCREMENT allocation, rollback, reset below old bands, independent branch IDs, explicit out-of-band source IDs, child rows behind held explicit post/term/user IDs, inserted and updated scalar/serialized/theme/widget `wp_options`, `wp_posts`, `wp_postmeta`, `wp_comments`, `wp_commentmeta`, `wp_usermeta`, `wp_termmeta`, `wp_term_taxonomy`, `wp_term_relationships`, post-author, taxonomy menu-item, reusable/media/avatar/navigation/query block `post_content`, and comment-user references behind held explicit post/term/user IDs, JSON/serialized references that keep branch IDs distinct, plugin validator review for no-FK child rows behind held explicit plugin AUTOINCREMENT parents, and non-AUTOINCREMENT `INTEGER PRIMARY KEY` plugin graph collisions as review-held. `tests/cow/e2e.sh` verifies runtime branch post IDs fall inside branch bands and requires an independently banded source/target WordPress post merge to finish with `status: completed` and zero recorded conflicts while preserving embedded JSON/serialized post IDs. | Expand explicit-ID/import handling beyond currently covered AUTOINCREMENT row-insert/rewrite cases and enforce review for more plugin/custom logical identities that are not safely bandable. |
| 8. Better stale-audit workflow | `docs/stale-audit-workflow.md` describes the revalidation model. `scripts/cow/merge.php` implements `revalidate-reviews`, `merge-audit --revalidate`, `merge-resolve --after-revalidate`, revalidation classes, latest revalidation status checks, source/target drift checks, schema index/view/trigger/table-restore/table-rebuild source/target drift checks, plugin validator replacement evidence, plugin replacement conflict links, WordPress semantic fingerprints, conservative non-PK `UNIQUE` logical-key drift detection for custom/plugin tables, source/target row-context payloads for new database cell conflicts, current `--resolution-choice` and `--blocked-resolution-choice` conflict queue filters, `--event-type`, conflict-event grouping, blocked resolution attempt events, resolver-contract filtering/grouping by resolution strategy, generic resolver support, and after-revalidate support, `--latest-revalidation-status`, `--stale-status`, and matching group-by queue filters, and text/JSON revalidation summaries that name the carried and already-open `needs-action` conflict ids, classifiers, drift reasons, revalidation records, and replacement conflict ids. `tests/cow/stale_audit.php` is a focused fast gate for reviewed cell conflicts, target drift detection, source cell/row drift detection, deleted target rows classified as `missing`, no-primary-key rowid replacement classified as `incompatible`, WordPress post, postmeta, option, term, term taxonomy, termmeta, user, usermeta, comment, and commentmeta semantic replacements classified as `incompatible`, custom/plugin `UNIQUE` logical-key replacement on source or target classified as `incompatible`, row-context-backed source/target logical-key replacement for cell conflicts where the reviewed cell value itself is unchanged, `needs-action` carry-forward, idempotent revalidation, actionable revalidation summaries, audit-visible revalidation classes, latest revalidation current/drifted status output, live stale-status filtering/grouping, and guarded `--after-revalidate` source resolution. `tests/cow/plugin_validator.php` fast-gates plugin validator replacement evidence when rerun findings change, including explicit changed plugin `source` payload evidence and changed first-class plugin `logical_identity` evidence. `tests/cow/schema_review.php` fast-gates source-added schema index/view/trigger, dropped-table restore, and table rebuild source drift returning reviewed schema conflicts to `needs-action` with current source SQL evidence; source-added index, view, and trigger target drift get compatible classes and guarded `--after-revalidate` source resolution only when dry-run source replacement validates. Table rebuild payloads include direct indexes/triggers, dependent views, and dependent view triggers, so dependency-only source drift is visible even when table SQL is unchanged. It also covers target-side source-added view, trigger, index, dropped-table restore, and table rebuild drift with current target SQL evidence. `tests/cow/merge.php` covers stale row/cell/file drift, source drift, deleted targets, no-PK rowid replacement, supported WordPress semantic fingerprints, guarded resolution, live source-applicable and source-blocked audit filters, conflict event-type filtering and grouping, blocked resolution attempt events, resolver-contract filtering/grouping, idempotent carried notes, plugin validator rerun evidence through direct and `merge-audit --revalidate` paths, duplicate identical validator rerun handling, and replacement validator conflict ids. | Add guarded plugin/schema-specific resolution flows beyond source-added object target drift where a plugin or schema planner can prove compatibility. |
| 9. Release gate issue | `scripts/build-dist.sh` and release preflight tests fail earlier when static-PHP prerequisites are missing, avoid macOS bash empty-array expansion under `set -u` while wrapping Apple Silicon `spc` commands in `arch -arm64`, and `docs/merge-reliability.md` tracks aarch64 macOS as a release gate. Release `v0.1.33` was published from workflow run `25974694022` at trunk commit `5f7d9622c5b27f5e06b2ddc917143e783bf6ecbd` after all five release targets built, packaged, and smoke-tested: `aarch64-apple-darwin`, `x86_64-apple-darwin`, `aarch64-unknown-linux-musl`, `x86_64-unknown-linux-musl`, and `x86_64-pc-windows-msvc`. The release includes merge-reliability slices landed through PR #238 and `v0.1.33`; the Apple Silicon archive is available at `https://github.com/Automattic/forkpress/releases/download/v0.1.33/forkpress-aarch64-apple-darwin.tar.gz`, uploaded with the other release assets and `SHA256SUMS`. The mac APFS e2e gate still tolerates only the known transient `hdiutil compact` "Resource temporarily unavailable" failure after storage has already detached. | Keep aarch64 macOS release and APFS sparsebundle E2E green on trunk for future releases; do not treat a transient compact skip as proof that compaction itself succeeded. |

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
- Row target-constraint conflicts expose source choices as blocked while
  current target foreign-key state, target-side `CHECK` constraints, or target
  trigger rewrites make the audited source row invalid.
- Reviewed source conflict resolutions use the same row postcondition guard.
- File changes are merged separately from DB rows and unsafe paths remain
  conflicts.
- Conflict resolution validates that the target still matches the audited
  value. If the target drifted, resolution stops and asks for a fresh audit.

## Reliability Gaps

| Area | Current state | Missing reliability work |
| --- | --- | --- |
| WordPress semantic objects | Tests cover real post creation, postmeta references, users, usermeta, post/comment authors, threaded comments and commentmeta references, branch-local page edits/deletes with edited content/author assertions, same-object page/postmeta edit-vs-delete conflicts with auditable target-wins defaults, same-object user/usermeta edit-vs-delete conflicts that preserve target deletion before review, same-object comment/commentmeta edit-vs-delete conflicts that preserve target deletion before review, same-object custom-post-type edit-vs-delete conflicts across CPT rows, CPT postmeta, CPT option indexes, and CPT taxonomy relationships, same-object taxonomy-term edit-vs-delete conflicts across terms, term-taxonomy rows, termmeta, and page-term relationships, attachment uploads plus original and generated-size files, attachment metadata, attachment-to-page parent links, same-object reusable-block edit-vs-delete conflicts across the `wp_block` row and target page cleanup, same-object navigation-menu edit-vs-delete conflicts across menu terms, term-taxonomy rows, menu item posts, menu item metadata, relationships, and theme-mod location cleanup, same-object attachment edit-vs-delete conflicts across attachment rows, metadata rows, original files, and generated files, `core/audio`, `core/cover`, `core/file`, `core/image`, `core/video`, `core/media-text`, and `core/gallery` block references and featured-image postmeta references to media attachments, hierarchical taxonomy terms, page-linked nav menus with menu-location assignments, reusable blocks and synced patterns, options with embedded object IDs including branch user refs, JSON option payloads with embedded object IDs including branch user refs, custom post types, plugin AUTOINCREMENT tables, keyless plugin tables, unique collisions, file additions, nested plugin-owned custom-table/JSON/serialized/file graphs, branch merge visibility, a clean zero-conflict semantic E2E merge requirement, a discovered media validator that reports missing original/generated upload files including metadata-side original, `original_image`, and backup image files, duplicate attachment claims on the same upload file including same-attachment generated-file duplicates, unreadable or NUL-corrupted attachment metadata, malformed `image_meta`, empty or unsafe primary/metadata/generated/`original_image`/backup upload metadata paths, original/generated/backup dimension drift, malformed or incomplete generated-size and backup-size metadata, generated-size, `original_image`, and backup filename drift, and `_wp_attached_file` versus `_wp_attachment_metadata` file drift, fast discovered block-reference, post-parent-reference, post-author-reference, postmeta-reference, usermeta-reference, menu-parent-reference, menu-reference, featured-image, image-block, media-block, avatar-navigation-link-block, gallery-block, query-block, term-relationship, term-taxonomy-reference, term-parent-reference, termmeta-reference, and option-reference validators that report pages/posts left pointing at deleted reusable blocks, synced patterns, navigation blocks, or template parts, child pages or attachments left pointing at deleted `post_parent` rows, posts or attachments left pointing at deleted `post_author` users, postmeta rows left pointing at deleted posts, usermeta rows left pointing at deleted users, nav menu items left pointing at deleted parent menu items or deleted post/taxonomy objects, `_thumbnail_id` postmeta left pointing at deleted attachment objects/files, `core/audio`, `core/cover`, `core/file`, `core/image`, `core/video`, `core/media-text`, or `core/gallery` block JSON left pointing at deleted attachment objects/files, `core/avatar` and `core/latest-posts` block JSON left pointing at deleted users, `core/navigation-link` and `core/navigation-submenu` block JSON left pointing at deleted pages or taxonomy terms, `core/query` block JSON including `taxQuery` and `core/latest-posts` category filters left pointing at deleted author users or taxonomy terms, `wp_term_relationships` left pointing at deleted taxonomy term rows, term taxonomy rows left pointing at deleted terms, child taxonomy terms left pointing at deleted parent terms, termmeta rows left pointing at deleted terms, serialized theme mods left pointing at deleted post objects, deleted nav-menu terms, or deleted custom-logo attachments plus serialized nav menu widgets, serialized nav menu auto-add options, serialized block/text/custom HTML widget content, serialized media-image/audio/video/gallery and pages widgets, serialized sidebar-widget placements, scalar `site_icon`/`page_on_front`/`page_for_posts` options, and serialized `sticky_posts` options left pointing at deleted objects, and `docs/merge-repair-policy.md` defines when semantic repairs must remain review-only. | Add broader concurrent object matrices, implement only the repair policies that have deterministic owners, and broaden plugin-owned graph conflict/drift cases. |
| Plugin-specific semantics | Generic SQLite merge is table/row/cell based and does not rewrite embedded IDs. `docs/plugin-merge-validators.md` defines the validator boundary and first test shape. PHP unit and E2E coverage now cover the clean happy path for a plugin-owned custom-table graph with parent/child rows, child JSON payload references, serialized option/postmeta references, referenced CPT data, and a referenced file. The PHP unit suite also covers the metadata/audit foundation for plugin-scoped validator conflicts, including review queues, first-class plugin filters, and queue grouping. Normal branch merges discover validators from active plugin, network-active plugin, and mu-plugin locations in the staged candidate target; discovered custom-table graph validators can abort and roll back a candidate with a broken JSON reference, or complete the merge with plugin-scoped review conflicts for broken serialized graph row/file references, unsafe URL-like or drive-letter plugin file references, and target-conflicting graph state. The focused plugin validator gate now includes serialized and JSON plugin option/postmeta references left pointing at a deleted plugin asset row/file or an unsafe plugin-owned file path, first-class plugin `severity`, validator identity, and `logical_identity` contract validation, plugin/object/severity/logical-identity filters and groupings, and `logical_identity` drift evidence for semantic identities that are not expressed as SQLite keys. `forkpress branch run-plugin-validator`, `forkpress branch record-plugin-validator-conflicts`, and `forkpress branch merge --plugin-validator <path>` expose explicit validator execution and findings recording, while rejecting contradictory valid-with-findings output or malformed validator identities before they become conflict metadata. Validator failures after DB/files have staged roll back the merge. | Add broader plugin-owned validators for more real plugins and plugin merge drivers only where a plugin can prove an automatic repair is safe. |
| Review-only schema cases | Acyclic source-added dependent views, views that depend on source-added tables, trigger programs, and triggers that depend on source-added tables including trigger `WHEN` clauses can apply automatically in dependency order. Source-added expression unique indexes that target rows would violate stay reviewable until those rows are removed. Source-dropped table/view conflicts expose `source` as blocked while current target views, triggers, schema objects, or foreign-key children still depend on the object, then advertise `source` after those dependency conflicts are resolved. Reviewed source-added index/view/trigger conflicts, dropped-table restores, and table rebuild conflicts can be revalidated for changed source/target SQL evidence and return to `needs-action`; source-added index, view, trigger, and compatible table-rebuild target drift can be guarded with `compatible-schema-*-target-drift` and source-applied after revalidation when dry-run replacement validates. Table rebuild revalidation includes dependency-plan evidence for direct indexes/triggers, dependent views, and dependent view triggers, so dependency-only source drift is not hidden behind unchanged table SQL. Cyclic views/triggers, source-added triggers with unresolved dependencies, invalid preserved trigger/view dependencies, and some rebuild dependency chains are held as auditable conflicts. | Improve dependency planning so more safe schema reorderings can apply automatically. Add guarded schema revalidation resolution flows beyond currently validated target-drift cases where the schema planner can prove compatibility. Keep non-deterministic or semantically ambiguous cases review-only. |
| Filesystem semantics | File additions/deletions/conflicts are audited; binary file changes/conflicts are hash-verified, safe relative symlinks can merge, unsafe symlinks to absolute paths, root-escaping paths, self-references, and ForkPress-managed paths remain conflicts, reviewed source resolution cannot force-apply unsafe symlinks, reviewed source directory-subtree resolution cannot force-apply unsafe symlinks nested inside a replacement directory, and unsupported special source filesystem entries remain review-held and cannot be source-applied on platforms with FIFO support. Directory/file and file/directory replacements get type-specific review conflicts, unchanged target descendants and source descendants under reviewed replacements are held until review, source directory deletions with target-side descendants are held with source resolution blocked before any descendant deletion, reviewed source replacements can apply supported file/dir/symlink changes including safe directory subtrees, WordPress E2E links attachment rows to original and generated-size upload files, plugin-shaped E2E checks branch-owned file contents, and PHP coverage uses a discovered validator to cross-check attachment metadata against merged upload files, missing required attachment metadata rows, invalid serialized attachment metadata, malformed `image_meta`, invalid original/generated/backup dimensions, original/generated/backup filesize drift, attachment `post_mime_type` and generated-size/backup `mime-type` drift against known upload file extensions including AVIF and PDF, generated-size, `original_image`, and backup filename drift, missing original/generated upload files including metadata-side original, `original_image`, and backup image files, attached-file metadata drift, duplicate original/generated/backup upload ownership, and unsafe primary/metadata/generated/`original_image`/backup upload paths, including root-escaping, URL-like, and Windows drive-letter primary upload metadata. | Add stricter uploads-specific validators for more conflict/drift shapes, including attachment metadata regeneration decisions. |
| Crash consistency | DB, metadata, file, rollback-failure, ID-band, and Git publication paths have targeted rollback tests. DB merge process-death coverage includes crashes before/after target DB commit and before metadata commit, with pending crash artifacts that block later merges until explicit recovery. Whole-branch DB+file merges now keep recoverable target DB, metadata DB, and filesystem-root snapshots across the file phase, so a hard exit after DB commit, before file mutation, or after an individual file operation blocks later merges until `recover-crash --restore-target-db --restore-files` restores a coherent pre-merge state. Public E2E now drives merge/create/reset/recover crash paths through `forkpress` commands and covers actual smart-HTTP Git-created branch pushes interrupted before branch-birth metadata, before branch-list publication, and after branch-list publication, followed by fresh server restart verification and merge retry/mergeback. Git server process-death coverage includes created-branch metadata/storage/public-link/list publication, existing-branch update publication, delete staging, and object pruning. `docs/merge-crash-consistency.md` maps covered boundaries and missing product-level failpoint work. | Broaden the external product-level kill harness across the remaining actual Git-push failpoints, plus platform-specific coverage around sparsebundle detach/compact and rollback-artifact cleanup. |
| Branch birth | CLI, Git-created, and remote-cache branches allocate ID bands and capture merge base metadata. Branch-birth validation rejects missing ID bands and keyless row identities, filesystem base capture records user content/uploads while excluding managed DB/config/Git files, and rollback cleanup removes only the failed branch metadata and preserves unrelated branch birth metadata. Git-created branches finalize birth metadata before publishing the branch tree, so a pre-metadata crash cannot expose a branch without ID bands or row identities. Existing branches reused by automation must still have a database, DB merge base, filesystem merge base, and required birth metadata before reuse. The WordPress admin branch create/merge UI is covered as a thin wrapper over the same CLI paths, including validation, CLI failure surfacing, and real runtime E2E create/merge requests. Remote-cache branch E2E coverage registers a materialized `main` cache, creates `remote-cache-branch`, verifies branch storage, inserts into the runtime AUTOINCREMENT probe inside the branch band, then merges branch DB and filesystem changes back to `main`. | Keep branch create, Git ref create, remote-cache branch, reset, and UI creation on one invariant: DB base, file base, ID bands, and metadata must exist before user writes. |
| ID bands | AUTOINCREMENT bands protect common WordPress and plugin tables. Normal branch reuse inside the reserved band refreshes existing metadata instead of allocating fresh IDs; reset below old bands gets fresh bands. Explicit source IDs outside the reserved branch band are review-held instead of applied automatically for core WordPress and plugin AUTOINCREMENT tables, paired source deletes in the same AUTOINCREMENT table are also held when an out-of-band explicit insert or primary-key rewrite is held, and source child `wp_posts`, owner/reference `wp_postmeta` including inserted and updated type-aware nav menu object refs, inserted or updated scalar/serialized/theme/widget `wp_options` references, inserted or updated `wp_comments`, `wp_commentmeta`, inserted or updated `wp_termmeta`, hierarchical and updated `wp_term_taxonomy`, inserted or primary-key-rewritten `wp_term_relationships`, inserted or updated `wp_usermeta`, inserted or updated post authors, inserted or updated reusable/media/avatar/latest-posts/navigation/query block `post_content` refs including `taxQuery`, inserted or updated taxonomy menu-item object refs, or inserted or updated comment user refs pointing at held explicit post/term/user IDs are review-held instead of leaving orphan WordPress child rows. Focused explicit-ID coverage now includes inserted or updated `wp_posts` rows with `post_author`, `core/avatar`, and `core/query` author references plus `wp_usermeta` and `wp_comments` rows behind held out-of-band explicit `wp_users` imports, inserted or updated `wp_commentmeta` and threaded comments behind held explicit `wp_comments` imports, inserted or updated featured-image postmeta, image-block content, `site_icon`, theme-mod `custom_logo`, and media/block/text/custom HTML widget options behind held explicit attachment imports, pages-widget options behind held explicit page imports, and inserted or updated `wp_termmeta`, `wp_term_taxonomy`, `wp_term_relationships`, serialized theme-mod menu locations, nav-menu widgets, and `nav_menu_options` options behind held explicit `wp_terms` imports. Non-AUTOINCREMENT `INTEGER PRIMARY KEY` plugin tables are marked non-bandable; non-colliding rows merge, while collisions are review-held and auditable. | Enforce bands before every write path, expand explicit-ID/import handling beyond covered AUTOINCREMENT row-insert cases, and reject/review unsafe reuse. |
| Stale audits | Resolution fails if target no longer matches the audited payload. `forkpress branch revalidate-reviews` and `forkpress branch merge-audit --revalidate` carry stale reviewed conflicts back into `needs-action` while preserving prior reviewer intent, avoiding duplicate carried notes, recording revalidated payloads, linking plugin replacement validator conflicts, emitting actionable text/JSON summaries of carried and already-open conflict ids, and storing a conservative revalidation classifier such as `compatible-target-drift`, `compatible-source-drift`, `missing`, no-primary-key rowid-reuse `incompatible`, supported WordPress post/postmeta/option/term/term-taxonomy/termmeta/user/usermeta/comment/commentmeta primary-key semantic `incompatible`, custom/plugin non-PK `UNIQUE` logical-key `incompatible`, plugin `replacement-evidence`, schema `compatible-schema-index-target-drift`, `compatible-schema-view-target-drift`, `compatible-schema-trigger-target-drift`, `compatible-schema-table-target-drift`, or schema index/view/trigger/table-restore/table-rebuild `unclassified` drift. New database cell conflicts also retain source/target row-context payloads, so custom/plugin logical-key replacement can be detected even when the reviewed scalar cell value is unchanged. `forkpress branch merge-audit --resolution-choice source` and `--blocked-resolution-choice source` expose the current executable/blocking contract for conflict queues. `forkpress branch merge-audit --event-type recorded|review-pending|review-needs-action|review-reviewed|resolution-validated|resolution-applied|resolution-blocked|revalidation-required` exposes the first-class conflict event history without scraping review notes, including failed resolver attempts that leave conflicts open, and `forkpress branch merge-audit --records conflict-events --group-by event-type|lifecycle|conflict-key` summarizes that history for UI queues. `forkpress branch merge-audit --latest-revalidation-status current|source-drifted|target-drifted|source-and-target-drifted|unknown|none` and `--group-by latest-revalidation-status` expose whether the latest recorded after-revalidate guard still matches live source/target state before the reviewer attempts a guarded resolution. `forkpress branch merge-resolve conflict <id> --after-revalidate` can apply reviewed stale database row/cell and filesystem conflicts only when the current source/target payloads still match the latest revalidation record, the latest classifier is not `incompatible`, and the original logical row still exists; source-added schema index/view/trigger and compatible table-rebuild target drift can also source-apply after revalidation only when the planner recorded its matching compatible schema class. The fast stale-audit gate now covers deleted target rows, no-primary-key rowid reuse, supported WordPress semantic replacement classifiers, custom/plugin logical-key replacements inferred from non-PK `UNIQUE` indexes, row-context-backed cell conflict identity drift, live source-applicable/source-blocked audit filters, event-type output/filtering/grouping, latest revalidation status output/filtering/grouping, and the direct revalidation output shape. Plugin validator conflicts now return to `needs-action` when a validator rerun records changed evidence for the same plugin object, including explicit changed plugin `source` payload evidence or changed first-class `logical_identity` evidence, but remain outside generic guarded resolution; reviewed schema index/view/trigger/table-restore/table-rebuild conflicts now return to `needs-action` with current SQL and, for table rebuilds, dependency-plan evidence when schema SQL or dependent object SQL drifts. Other schema conflicts still need planner-backed guarded resolution before they can be applied after revalidation. `docs/stale-audit-workflow.md` maps the current flow and the remaining richer classifier model. | Add guarded revalidation resolution for plugin and more schema conflicts where a plugin or schema planner can prove compatibility. |
| Release gates | Linux, Windows, x86_64 macOS, and aarch64 macOS production bundle artifacts built in the last checked release. macOS and Linux release workflows install static PHP build prerequisites up front, `scripts/build-dist.sh` now fails before cloning/building PHP if those tools are missing instead of letting `static-php-cli` mutate package-manager state during the release bundle step, avoids macOS bash empty-array expansion under `set -u` while wrapping Apple Silicon `spc` commands in `arch -arm64`, and the default `static-php-cli` checkout is pinned to a known upstream commit with an explicit override for deliberate upgrades. Release `v0.1.33` workflow run `25974694022` passed release packaging, artifact smoke checks, tag creation, GitHub release publication, and Homebrew formula update for all release targets, including `aarch64-apple-darwin`. Trunk and release CI also run APFS sparsebundle E2E on both macOS targets with a bounded product retry and e2e-only tolerance for the known transient `hdiutil compact` unavailable condition. | Keep aarch64 macOS release and APFS sparsebundle E2E green on trunk for future releases. Add a separate product check if compact-success itself becomes release-critical. |

## Test Direction

Every new reliability claim should have one of these test shapes:

- A PHP unit test in `tests/cow/merge.php` for deterministic DB merge behavior.
- A COW E2E test in `tests/cow/e2e.sh` for real WordPress/runtime behavior.
- A Rust unit test for CLI, storage, Git publication, release packaging, or
  platform-specific lifecycle behavior.
- A CI workflow gate when the behavior only exists on a target platform, such
  as APFS sparsebundles or Windows ReFS.

For fast local iteration on merge logic, start with:

```bash
make test-cow-merge-smoke
```

This smoke gate includes regressions for pages created, edited, and deleted on
a feature branch while `main` also creates content. Page-plus-postmeta,
page-plus-comment, page-plus-threaded-comment, page-plus-author, page-plus-custom-post-type, page-plus-taxonomy,
page-plus-menu, page-plus-child-page, page-plus-revision, page-plus-reusable-block, page-plus-navigation-block,
page-plus-attachment,
page-plus-image-block, page-plus-gallery, page-plus-file-block,
page-plus-media-text, page-plus-audio-cover-video, and page-plus-options cases
must complete with zero conflicts while preserving
branch-specific IDs inside author/user, comment/user, and threaded-comment graphs, plugin-like CPT graphs, menu
relationships, parent/child and revision `post_parent` page graphs, theme-mod locations, reusable-block comments, `core/navigation`
comments that reference `wp_navigation` rows, attachment
metadata, `core/image`/`core/gallery`/`core/file`/`core/media-text`/
`core/audio`/`core/cover`/`core/video` block JSON, upload files, JSON payloads,
and serialized payloads without rewrite.
It also includes page/postmeta, child page, revision, user, comment, threaded comment, taxonomy term, reusable block, navigation
block, menu, attachment, and option edit/delete conflict cases that must stay reviewable and
keep the target deletion until review. The page/postmeta case covers the
`wp_posts` row and its `wp_postmeta` graph, the child page case covers a deleted
child `wp_posts` row while preserving the unchanged parent page, the revision
case covers a deleted `revision` row while preserving its unchanged parent page, the user case covers `wp_users` and
`wp_usermeta`, the comment cases cover deleted parentless and reply comments
while preserving unchanged authors and parent comments, the taxonomy case covers term rows, term-taxonomy rows,
termmeta, and page-term relationships, the reusable block case covers the
deleted `wp_block` row and target page cleanup, the navigation block case covers
the deleted `wp_navigation` row and target page cleanup, the menu case covers menu
terms, taxonomy rows, menu item posts, menu item metadata, relationships, and
theme-mod location cleanup, the attachment case covers the DB and file graph,
and the option cases cover JSON and serialized `wp_options` rows plus global
front/posts page singleton, serialized sticky-post, and scalar site-icon option
disagreements, plus serialized theme-mod custom-logo and media-image widget
and nav-menu widget disagreements, that must stay reviewable.

For filesystem merge behavior, including binary changes and conflicts, safe
relative symlinks, unsafe absolute/root-escaping/self-referential/managed-path
symlink conflicts, directory/file type replacement review, and source directory
deletes with target descendants, run:

```bash
make test-cow-filesystem
```

For WordPress branch UI and router handling, including async create/merge
requests and browser-like no-header submissions that should be answered before
WordPress `admin-post.php` or admin-page bootstrap, run:

```bash
make test-cow-branch-ui
```

For explicit import IDs or primary-key rewrites at or outside a branch's
reserved AUTOINCREMENT ID band, run:

```bash
make test-cow-explicit-ids
```

For all cheap COW helper/UI/router checks, run:

```bash
make test-cow-fast
```

The CI e2e jobs run this fast target before building the production runtime
bundle, so helper-level merge, Git publication, branch UI, and router
regressions fail before the static PHP build.

For Git-created branch, branch-birth metadata, push rollback, and Git
publication crash/failpoint changes, run:

```bash
make test-cow-git-server
```

For branch-birth metadata validation, frozen pre-write filesystem base
snapshots, and cleanup isolation without the Git publication harness, run:

```bash
make test-cow-branch-birth
```

For ID-band allocation, in-band branch reuse, branch-reset reuse protection,
JSON/serialized branch ID preservation, and non-bandable `INTEGER PRIMARY KEY`
plugin collision checks, run:

```bash
make test-cow-id-bands
```

For a semantic-only fast gate that skips Git publication, branch UI, router,
schema, and generic filesystem checks while still covering page smoke merges,
ID bands, explicit IDs, media/upload validators, plugin validators, stale
audits, and WordPress semantic reference validators, run:

```bash
make test-cow-semantic-fast
```

For the focused runtime WordPress semantic E2E slice, which starts a real
ForkPress site and merges branch-created page/edit/delete, media upload,
menus, reusable blocks, options, comments, users, terms, CPT data, and
plugin-shaped DB/JSON/serialized/file graphs before exiting the larger E2E
script, run:

```bash
make test-cow-e2e-semantic FORKPRESS_E2E_BIN=/path/to/forkpress
```

For schema dependency planning and review-only cases such as dependent
source-added views/triggers, source-added views or triggers that depend on
source-added tables, cyclic source-added views/triggers, source-added expression unique
indexes blocked by target rows, or source-added triggers with missing
dependencies, run:

```bash
make test-cow-schema-review
```

For WordPress upload/media validator changes, including missing required
attachment metadata rows, missing original, metadata-side original, `original_image`, backup, or generated upload files, invalid serialized attachment
metadata, duplicate original/generated upload ownership, invalid
`image_meta`, invalid original/generated/backup dimensions, original/generated/backup filesize drift, attachment
`post_mime_type` and generated-size/backup `mime-type` drift against known upload file extensions, attached-file metadata drift, generated-size,
`original_image`, or backup filename drift, unsafe primary/metadata/generated/`original_image`/backup paths including URL-like and Windows drive-letter primary upload metadata, and malformed or
incomplete generated-size or backup-size metadata, run:

```bash
make test-cow-media-validator
```

For WordPress semantic reference validators, including pages left pointing at
deleted reusable blocks, synced patterns, or template parts, child pages or attachments left
pointing at deleted `post_parent` rows, posts or attachments left pointing at
deleted `post_author` users, postmeta rows left pointing at deleted posts,
usermeta rows left pointing at deleted users, and nav menu items left pointing
at deleted parent menu items or deleted pages, plus featured-image postmeta left pointing at deleted
attachments/files and `core/audio`, `core/cover`, `core/file`, `core/image`,
  `core/video`, `core/media-text`, or `core/gallery` block JSON left pointing at
deleted attachments/files from content-bearing custom post types as well as
posts/pages, `core/query` block JSON including `taxQuery` and `core/latest-posts` filters left pointing at deleted author
users or taxonomy terms, `core/avatar` block JSON left pointing at deleted
users, `core/navigation-link` and `core/navigation-submenu` block JSON left pointing at deleted pages or
taxonomy terms, term relationships left pointing at deleted taxonomy terms,
term taxonomy rows left pointing at deleted terms, child taxonomy terms left
pointing at deleted parent terms, termmeta rows left
pointing at deleted terms, comments left pointing at deleted
posts/users/parent comments, commentmeta
left pointing at deleted comments, and options/widgets/theme mods left pointing
at deleted WordPress objects, run:

```bash
make test-cow-wp-semantic-validator
```

For plugin-owned DB/JSON/file graph validator changes, including serialized
or JSON option/postmeta references, unsafe plugin-owned file references
including URL-like and Windows drive-letter file paths,
validator runner contract checks, rerun replacement evidence, explicit
plugin source-evidence drift, and first-class plugin logical-identity drift,
run:

```bash
make test-cow-plugin-validator
```

For stale-audit revalidation, source/target drift, deleted target rows,
no-primary-key rowid reuse, WordPress semantic identity replacement across
posts, postmeta, options, terms, taxonomy, termmeta, users, usermeta,
comments, and commentmeta, custom/plugin non-PK `UNIQUE` logical-key
replacement, row-context-backed cell conflict identity drift, and guarded
`--after-revalidate` resolution changes, run:

```bash
make test-cow-stale-audit
```

For the broader PHP merge gate without building ForkPress or starting the full
WordPress E2E harness, run:

```bash
make test-cow-merge
```

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

## Follow-Up Work

PR #46 should be treated as a merge-reliability hardening milestone, not the
final proof that all merges are automatic or fully reliable. The next work
should stay focused on these areas:

- Add validators for real plugins with known cross-table, serialized, JSON, and
  file graphs. Add plugin-owned merge drivers only when the plugin can prove a
  deterministic repair.
- Build broader external kill harnesses for public Git push/serve entry points,
  then verify recovery from a fresh process after each interruption.
- Expand explicit-ID/import handling beyond the currently covered
  AUTOINCREMENT row insert/rewrite and known WordPress reference cases.
- Add guarded plugin/schema stale-audit resolution flows where the plugin or
  schema planner can prove the reviewed choice is still valid.
- Keep conflict resolution modeled as a first-class contract in audit output:
  every conflict class should advertise its legal executable choices,
  resolution strategy, generic resolver support, and revalidation support before
  the CLI or UI offers an action. Audit output should also expose lifecycle and
  next-action fields so deferred, needs-action, reviewed, validated, and
  resolved conflicts are not inferred from free-form review notes. Conflict
  state changes now append `merge_conflict_events`, giving UI and API clients a
  durable event stream for recorded, reviewed, revalidated, and resolved
  conflicts; validation-only `merge-resolve` calls are persisted as validated
  resolutions with `resolution-validated` events, `--apply-reviewed` applies the
  latest unapplied validated choice, payload-specific blockers such as cyclic
  schema source resolution, unresolved target dependencies for source-dropped
  tables/views, foreign-key row, unique-collision row, and
  primary-key-addressable cell source choices blocked by current target state,
  target-side row `CHECK` constraints, target trigger rewrites, unsafe
  filesystem source payloads, and target-descendant directory deletions are
  exposed as
  `blocked_resolution_choices`,
  conflict rows expose a stable
  `conflict_key` for logical UI grouping plus `previous_conflict_id` lineage for
  recurring conflicts and plugin validator replacement evidence on the same
  source/target branch pair, conflict rows are
  scoped to the merge run so repeated unresolved conflicts and identical payload
  conflicts on different branch pairs receive their own rows/events, and
  blocked resolver attempts append `resolution-blocked` events that move the
  still-open conflict back to `needs-action`/manual-review queues instead of
  leaving UI clients to offer a stale validated apply, and
  `merge-audit --conflict-key <key>` can focus conflict, conflict-event, or
  resolution audit output on one logical conflict group, `merge-review
  conflict-key <key> [--run <id>]` can attach review status by logical key,
  `merge-resolve conflict-key <key> [--run <id>]` can validate or apply a
  conflict choice by logical key when that key is unambiguous, and
  `merge-audit --lifecycle-state <state>` can focus conflict queues and
  conflict-event history on `unreviewed`, `deferred`, `needs-action`,
  `reviewed`, `validated`, or `resolved` records without clients
  reimplementing lifecycle inference, `merge-audit --resolution-choice
  source|target` and `--blocked-resolution-choice source|target` can focus
  queues using the same live contract that `merge-resolve` enforces,
  `merge-audit --next-action <action>` can focus queues by the action a UI
  should offer next, `merge-audit --latest-revalidation-status <status>` can
  focus conflicts whose latest revalidation guard is still current or has
  drifted again, and `merge-audit --stale-status <status>` can query live
  conflict staleness before revalidation. `merge-audit --records conflicts
  --group-by lifecycle`, `--group-by next-action`, `--group-by conflict-key`,
  `--group-by latest-revalidation-status`, and `--group-by stale-status` can
  summarize queue counts by lifecycle, required action, logical conflict,
  current guard status, or live stale status without client-side aggregation.
  `merge-audit --records conflicts --group-by resolution-strategy`,
  `--group-by generic-resolver`, and `--group-by after-revalidate` summarize
  which queues are generic-resolver ready and which require guarded
  revalidation before apply; the same names are also filters for focusing
  conflict and conflict-event records.
  `merge-audit --records conflict-events --group-by event-type`, `--group-by
  lifecycle`, `--group-by conflict-key`, and the plugin groupings can summarize
  recorded, review, revalidation, blocked-resolution, and resolution event
  history without client-side aggregation.
- Improve deterministic schema dependency planning for safe view/trigger
  reorderings while keeping cyclic or semantic ambiguity review-only.
- Keep aarch64 macOS release artifacts and APFS sparsebundle E2E runs green for
  each release. `v0.1.33` is the current published release after all five
  production-bundle targets stayed green and the current reliability slices
  through trunk commit `5f7d9622c5b27f5e06b2ddc917143e783bf6ecbd` were
  released.

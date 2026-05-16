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

| Objective item | Evidence in this PR | Remaining gap |
| --- | --- | --- |
| 1. Real WordPress semantic merge coverage | `tests/cow/e2e.sh` creates source and target branches through runtime WordPress requests, validates each branch-local graph before merge, then merges pages, branch-local page edits/deletes with edited content and authors, postmeta, users/usermeta, authors, comments/commentmeta, hierarchical taxonomy terms, nav menus and menu locations, reusable `wp_block` rows, page-to-reusable-block refs, `core/image` block refs and featured-image refs to media attachments, options and JSON options with branch user/object IDs, media uploads with attachment parents plus generated-size metadata/files, a CPT-like `forkpress_note`, and plugin-shaped custom tables/files. The semantic E2E merge now requires `status: completed` and a zero-conflict merge run, so runtime-only state cannot hide behind a surviving object graph. `tests/cow/merge.php` adds deterministic WordPress row fingerprint and validator coverage. | Add broader concurrent edit/delete matrices for complete WP objects and deterministic repair policies only where the owner object is unambiguous. |
| 2. Plugin-specific merge semantics | `docs/plugin-merge-validators.md` defines the validator contract, including rejecting contradictory status/finding output. `scripts/cow/merge.php` discovers active plugin and mu-plugin validators, runs explicit validators, records plugin-scoped conflicts, and rolls back inline validator failures. `tests/cow/merge.php` covers clean custom-table graph merges, validator findings, audit/review grouping, validator rerun evidence, file-root context, active-plugin discovery, explicit-ID plugin graph validation, contradictory validator output rejection, and failed-validator rollback. `tests/cow/e2e.sh` covers a runtime plugin-shaped graph across custom table parent/child rows, child JSON payload refs, JSON, serialized data, options, postmeta, CPT data, and branch-owned file contents. | Add validators for real plugins and add merge drivers only for plugin-owned repairs that can prove correctness. |
| 3. Remaining review-only schema cases | `scripts/cow/merge.php` validates source-added views/triggers, preserves invalid dependency cases as conflicts, and supports safe schema object resolution for deterministic subsets. `tests/cow/merge.php` covers cyclic/invalid view and trigger dependency handling, source-added dependent view ordering, and rebuild validation cases. | Improve dependency planning for more safe reorderings. Cyclic or semantically ambiguous cases should stay review-only. |
| 4. Filesystem merge hardening | `tests/cow/merge.php` covers file adds/deletes/conflicts, binary hash comparisons, symlink safety, directory/file and file/directory replacement review, rollback artifacts, upload-file validators, generated attachment file checks, original/generated dimension drift, generated-size filename drift, featured-image/image-block/media metadata drift, and unsafe metadata paths. `tests/cow/e2e.sh` verifies real merged upload originals and generated thumbnails. | Add stricter uploads-specific validators for more drift shapes and explicit attachment-regeneration decisions. |
| 5. Crash consistency across DB/files/metadata/Git | `docs/merge-crash-consistency.md` lists the covered boundaries. `tests/cow/merge.php` covers target DB, metadata, file, rollback-failure, ID-band, and whole-branch rollback paths. `tests/cow/e2e.sh` drives public merge/create/reset/recover crash/retry flows for DB, metadata, before-file, after-file, recovery-cleanup, branch-birth, branch-reset publication failpoints, and actual smart-HTTP Git-created branch pushes interrupted before branch-birth metadata, before branch-list publication, and after branch-list publication, each verified after a fresh server restart. `tests/cow/git_server.php` covers Git-created branch birth, Git update/delete, stale cleanup, and object-prune interruption. | Broaden external kill harness coverage across the remaining Git-push failpoints and platform-specific APFS/cleanup checkpoints, then verify post-crash state from a fresh process. |
| 6. Branch birth always captures merge bases | `crates/forkpress-storage/src/lib.rs` requires branch birth metadata for branch reuse/merge and blocks pending reset states. `tests/cow/git_server.php` covers Git-created branch DB/file base, ID-band, row identity, and cleanup/rollback paths. `tests/cow/e2e.sh` covers public create retry after interrupted birth metadata, public reset retry after interrupted reset publication, and remote-cache branch creation followed by AUTOINCREMENT-band insertion and mergeback to `main`. | Keep every new creation/reuse/reset path under the same invariant and add regressions whenever a new branch publication path is introduced. |
| 7. ID-band enforcement beyond happy paths | `tests/cow/id_bands.php` is a focused fast gate for separate branch AUTOINCREMENT bands, JSON/serialized references that keep branch IDs distinct without rewrite, and review-held non-AUTOINCREMENT `INTEGER PRIMARY KEY` plugin collisions. `tests/cow/merge.php` covers AUTOINCREMENT allocation, rollback, reset below old bands, independent branch IDs, explicit out-of-band source IDs, child rows behind held explicit post/term/user IDs, inserted and updated scalar/serialized/theme/widget `wp_options`, `wp_posts`, `wp_postmeta`, `wp_comments`, `wp_commentmeta`, `wp_usermeta`, `wp_termmeta`, `wp_term_taxonomy`, `wp_term_relationships`, post-author, taxonomy menu-item, reusable/media/avatar/navigation/query block `post_content`, and comment-user references behind held explicit post/term/user IDs, JSON/serialized references that keep branch IDs distinct, plugin validator review for no-FK child rows behind held explicit plugin AUTOINCREMENT parents, and non-AUTOINCREMENT `INTEGER PRIMARY KEY` plugin graph collisions as review-held. `tests/cow/e2e.sh` verifies runtime branch post IDs fall inside branch bands and requires an independently banded source/target WordPress post merge to finish with `status: completed` and zero recorded conflicts while preserving embedded JSON/serialized post IDs. | Expand explicit-ID/import handling beyond currently covered AUTOINCREMENT row-insert/rewrite cases and enforce review for more plugin/custom logical identities that are not safely bandable. |
| 8. Better stale-audit workflow | `docs/stale-audit-workflow.md` describes the revalidation model. `scripts/cow/merge.php` implements `revalidate-reviews`, `merge-audit --revalidate`, `merge-resolve --after-revalidate`, revalidation classes, source/target drift checks, plugin validator replacement evidence, and plugin replacement conflict links. `tests/cow/stale_audit.php` is a focused fast gate for reviewed cell conflicts, target drift detection, `needs-action` carry-forward, idempotent revalidation, and guarded `--after-revalidate` source resolution. `tests/cow/merge.php` covers stale row/cell/file drift, source drift, deleted targets, no-PK rowid replacement, supported WordPress semantic fingerprints, guarded resolution, idempotent carried notes, plugin validator rerun evidence through direct and `merge-audit --revalidate` paths, duplicate identical validator rerun handling, and replacement validator conflict ids. | Add broader plugin/schema source-drift evidence, more custom logical-identity classifiers, and guarded plugin/schema-specific resolution flows where appropriate. |
| 9. Release gate issue | `scripts/build-dist.sh` and release preflight tests fail earlier when static-PHP prerequisites are missing, avoid macOS bash empty-array expansion under `set -u` while wrapping Apple Silicon `spc` commands in `arch -arm64`, and `docs/merge-reliability.md` tracks aarch64 macOS as a release gate. Trunk CI run `25952935740` passed `Build production dist bundle` and release-built COW APFS sparsebundle E2E on `aarch64-apple-darwin` and `x86_64-apple-darwin`, plus Linux COW E2E and Windows checks. The mac APFS e2e now tolerates only the known transient `hdiutil compact` "Resource temporarily unavailable" failure after storage has already detached. | Keep aarch64 macOS release and APFS sparsebundle E2E green on trunk before treating Mac artifacts as trustworthy; do not treat a transient compact skip as proof that compaction itself succeeded. |

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
| WordPress semantic objects | Tests cover real post creation, postmeta references, users, usermeta, post/comment authors, threaded comments and commentmeta references, branch-local page edits/deletes with edited content/author assertions, same-object page/postmeta edit-vs-delete conflicts with auditable target-wins defaults, attachment uploads plus original and generated-size files, attachment metadata, attachment-to-page parent links, `core/image` block references and featured-image postmeta references to media attachments, hierarchical taxonomy terms, page-linked nav menus with menu-location assignments, reusable blocks and synced patterns, options with embedded object IDs including branch user refs, JSON option payloads with embedded object IDs including branch user refs, custom post types, plugin AUTOINCREMENT tables, keyless plugin tables, unique collisions, file additions, nested plugin-owned custom-table/JSON/serialized/file graphs, branch merge visibility, a clean zero-conflict semantic E2E merge requirement, a discovered media validator that reports missing original/generated upload files, duplicate attachment claims on the same upload file including same-attachment generated-file duplicates, unreadable or NUL-corrupted attachment metadata, empty or unsafe primary/generated upload metadata paths, original/generated dimension drift, generated-size filename drift, incomplete generated-size metadata, and `_wp_attached_file` versus `_wp_attachment_metadata` file drift, a discovered block-reference validator that reports pages/posts left pointing at deleted reusable blocks or synced patterns, a discovered menu-reference validator that reports nav menu items left pointing at deleted post objects, a discovered option-reference validator that reports serialized theme mods left pointing at deleted post objects, deleted nav-menu terms, or deleted custom-logo attachments plus serialized nav menu widgets, serialized media-image widgets, serialized sidebar-widget placements, scalar `site_icon`/`page_on_front`/`page_for_posts` options, and serialized `sticky_posts` options left pointing at deleted objects, a discovered featured-image validator that reports `_thumbnail_id` postmeta left pointing at deleted attachment objects/files, a discovered image-block validator that reports `core/image` block JSON left pointing at deleted attachment objects/files, a discovered term-relationship validator that reports `wp_term_relationships` left pointing at deleted taxonomy term rows, and `docs/merge-repair-policy.md` defines when semantic repairs must remain review-only. | Add broader concurrent object matrices, implement only the repair policies that have deterministic owners, and broaden plugin-owned graph conflict/drift cases. |
| Plugin-specific semantics | Generic SQLite merge is table/row/cell based and does not rewrite embedded IDs. `docs/plugin-merge-validators.md` defines the validator boundary and first test shape. PHP unit and E2E coverage now cover the clean happy path for a plugin-owned custom-table graph with parent/child rows, child JSON payload references, serialized option/postmeta references, referenced CPT data, and a referenced file. The PHP unit suite also covers the metadata/audit foundation for plugin-scoped validator conflicts, including review queues and grouping. Normal branch merges discover validators from active plugin and mu-plugin locations in the staged candidate target; discovered custom-table graph validators can abort and roll back a candidate with a broken JSON reference, or complete the merge with plugin-scoped review conflicts for broken serialized graph row/file references and target-conflicting graph state. `forkpress branch run-plugin-validator`, `forkpress branch record-plugin-validator-conflicts`, and `forkpress branch merge --plugin-validator <path>` expose explicit validator execution and findings recording. Validator failures after DB/files have staged roll back the merge. | Add broader plugin-owned validators for more real plugins and plugin merge drivers only where a plugin can prove an automatic repair is safe. |
| Review-only schema cases | Cyclic views/triggers, invalid preserved trigger/view dependencies, and some rebuild dependency chains are held as auditable conflicts. | Improve dependency planning so more safe schema reorderings can apply automatically. Keep non-deterministic or semantically ambiguous cases review-only. |
| Filesystem semantics | File additions/deletions/conflicts are audited; binary file changes/conflicts are hash-verified, safe relative symlinks can merge, unsafe symlinks to absolute paths, root-escaping paths, self-references, and ForkPress-managed paths remain conflicts, directory/file and file/directory replacements get type-specific review conflicts, unchanged target descendants and source descendants under reviewed replacements are held until review, reviewed source replacements can apply supported file/dir/symlink changes including directory subtrees, WordPress E2E links attachment rows to original and generated-size upload files, plugin-shaped E2E checks branch-owned file contents, and PHP coverage uses a discovered validator to cross-check attachment metadata against merged upload files and attached-file metadata drift. | Add stricter uploads-specific validators for more conflict/drift shapes, including attachment metadata regeneration decisions. |
| Crash consistency | DB, metadata, file, rollback-failure, ID-band, and Git publication paths have targeted rollback tests. DB merge process-death coverage includes crashes before/after target DB commit and before metadata commit, with pending crash artifacts that block later merges until explicit recovery. Whole-branch DB+file merges now keep recoverable target DB, metadata DB, and filesystem-root snapshots across the file phase, so a hard exit after DB commit, before file mutation, or after an individual file operation blocks later merges until `recover-crash --restore-target-db --restore-files` restores a coherent pre-merge state. Public E2E now drives merge/create/reset/recover crash paths through `forkpress` commands and covers actual smart-HTTP Git-created branch pushes interrupted before branch-birth metadata, before branch-list publication, and after branch-list publication, followed by fresh server restart verification and merge retry/mergeback. Git server process-death coverage includes created-branch metadata/storage/public-link/list publication, existing-branch update publication, delete staging, and object pruning. `docs/merge-crash-consistency.md` maps covered boundaries and missing product-level failpoint work. | Broaden the external product-level kill harness across the remaining actual Git-push failpoints, plus platform-specific coverage around sparsebundle detach/compact and rollback-artifact cleanup. |
| Branch birth | CLI, Git-created, and remote-cache branches allocate ID bands and capture merge base metadata. Git-created branches finalize birth metadata before publishing the branch tree, so a pre-metadata crash cannot expose a branch without ID bands or row identities. Existing branches reused by automation must still have a database, DB merge base, filesystem merge base, and required birth metadata before reuse. The WordPress admin branch create/merge UI is covered as a thin wrapper over the same CLI paths, including validation, CLI failure surfacing, and real runtime E2E create/merge requests. Remote-cache branch E2E coverage registers a materialized `main` cache, creates `remote-cache-branch`, verifies branch storage, inserts into the runtime AUTOINCREMENT probe inside the branch band, then merges branch DB and filesystem changes back to `main`. | Keep branch create, Git ref create, remote-cache branch, reset, and UI creation on one invariant: DB base, file base, ID bands, and metadata must exist before user writes. |
| ID bands | AUTOINCREMENT bands protect common WordPress and plugin tables. Reset below old bands gets fresh bands. Explicit source IDs outside the reserved branch band are review-held instead of applied automatically for core WordPress and plugin AUTOINCREMENT tables, paired source deletes in the same AUTOINCREMENT table are also held when an out-of-band explicit insert is held, and source child `wp_posts`, owner/reference `wp_postmeta` including inserted and updated type-aware nav menu object refs, inserted or updated scalar/serialized/theme/widget `wp_options` references, inserted or updated `wp_comments`, `wp_commentmeta`, inserted or updated `wp_termmeta`, hierarchical and updated `wp_term_taxonomy`, inserted or primary-key-rewritten `wp_term_relationships`, inserted or updated `wp_usermeta`, inserted or updated post authors, inserted or updated reusable/media/avatar/navigation/query block `post_content` refs, inserted or updated taxonomy menu-item object refs, or inserted or updated comment user refs pointing at held explicit post/term/user IDs are review-held instead of leaving orphan WordPress child rows. Non-AUTOINCREMENT `INTEGER PRIMARY KEY` plugin graph collisions are review-held and auditable as non-bandable tables. | Enforce bands before every write path, expand explicit-ID/import handling beyond covered AUTOINCREMENT row-insert cases, and reject/review unsafe reuse. |
| Stale audits | Resolution fails if target no longer matches the audited payload. `forkpress branch revalidate-reviews` and `forkpress branch merge-audit --revalidate` carry stale reviewed conflicts back into `needs-action` while preserving prior reviewer intent, avoiding duplicate carried notes, recording revalidated payloads, linking plugin replacement validator conflicts, and storing a conservative revalidation classifier such as `compatible-target-drift`, `compatible-source-drift`, `missing`, no-primary-key rowid-reuse `incompatible`, supported WordPress primary-key semantic `incompatible`, or plugin `replacement-evidence`. `forkpress branch merge-resolve conflict <id> --after-revalidate` can apply reviewed stale database row/cell and filesystem conflicts only when the current source/target payloads still match the latest revalidation record, the latest classifier is not `incompatible`, and the original logical row still exists. Plugin validator conflicts now return to `needs-action` when a validator rerun records changed evidence for the same plugin object, but remain outside generic guarded resolution; schema conflicts still need schema-specific evidence/planning. `docs/stale-audit-workflow.md` maps the current flow and the remaining richer classifier model. | Add broader source-drift coverage for plugin/schema-specific evidence, broader incompatible classifiers for more primary-key/higher-level semantic identities, and guarded revalidation resolution for plugin and schema conflicts. |
| Release gates | Linux, Windows, x86_64 macOS, and aarch64 macOS production bundle artifacts built in the last checked runs. macOS and Linux release workflows install static PHP build prerequisites up front, `scripts/build-dist.sh` now fails before cloning/building PHP if those tools are missing instead of letting `static-php-cli` mutate package-manager state during the release bundle step, avoids macOS bash empty-array expansion under `set -u` while wrapping Apple Silicon `spc` commands in `arch -arm64`, and the default `static-php-cli` checkout is pinned to a known upstream commit with an explicit override for deliberate upgrades. Trunk CI run `25952935740` passed release-built APFS sparsebundle E2E on both macOS targets after adding a bounded product retry and e2e-only tolerance for the known transient `hdiutil compact` unavailable condition. | Keep aarch64 macOS release and APFS sparsebundle E2E green on trunk; those gates are required before trusting an M1-ready artifact. Add a separate product check if compact-success itself becomes release-critical. |

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

For ID-band allocation, JSON/serialized branch ID preservation, and
non-bandable `INTEGER PRIMARY KEY` plugin collision checks, run:

```bash
make test-cow-id-bands
```

For WordPress upload/media validator changes, run:

```bash
make test-cow-media-validator
```

For stale-audit revalidation and guarded `--after-revalidate` resolution
changes, run:

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
- Add richer plugin/schema stale-audit evidence and guarded resolution flows
  where the plugin or schema planner can prove the reviewed choice is still
  valid.
- Improve deterministic schema dependency planning for safe view/trigger
  reorderings while keeping cyclic or semantic ambiguity review-only.
- Keep aarch64 macOS release artifacts and APFS sparsebundle E2E runs green
  before treating Apple Silicon merge/release behavior as trustworthy.

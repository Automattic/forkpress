# Merge Repair Policy

Status: proposed policy

ForkPress merge should not rewrite WordPress or plugin data just because a
rewritten state would be convenient. Automatic repair is allowed only when the
repair is deterministic, preserves the user's intended object graph, and can be
validated after application. Everything else should remain an auditable conflict
or a plugin-scoped validator finding.

## Default Rule

The merge engine may automatically apply source rows and files when their raw
DB/file preconditions are satisfied. It must not infer WordPress semantics that
are not represented by those preconditions.

When a semantic object spans multiple places, such as posts, postmeta, terms,
block JSON, options, upload files, or plugin tables, the safe default is:

- preserve target state when source and target disagree;
- stage source state only when generic merge rules prove it safe;
- run validators to detect incoherent merged graphs;
- record conflicts with enough payload to review the graph manually.

## Repair Classes

| Class | Automatic repair policy | Reason |
| --- | --- | --- |
| Branch ID collisions | Prevent before write with AUTOINCREMENT bands. Do not rewrite embedded IDs after the fact. | IDs are commonly serialized into JSON, PHP serialization, block attributes, and plugin payloads. Rewriting every reference is not generally knowable. |
| Missing upload files from attachment metadata | Validator conflict by default. | Regenerating sizes changes bytes, dimensions, metadata, and possibly plugin expectations. A future media-specific repair may be valid only when WordPress can regenerate the exact declared size from an existing original. |
| `_wp_attached_file` versus `_wp_attachment_metadata['file']` drift | Validator conflict by default. | Either path can be intentional after a plugin move/import. The merge engine should not choose one without a media owner. |
| Featured image references to deleted attachments | Validator conflict by default. | Removing `_thumbnail_id` or restoring the attachment are both semantic editorial choices. |
| `core/image` block IDs pointing at deleted attachments | Validator conflict by default. | Updating block JSON requires knowing whether the image should be removed, replaced, or restored. |
| Reusable block or synced pattern refs pointing at deleted `wp_block` posts | Validator conflict by default. | The safe action depends on editorial intent: unlink, restore, replace, or accept deletion. |
| Nav menu items pointing at deleted objects | Validator conflict by default. | Menu repair is content semantics, not row semantics. |
| Comments or commentmeta pointing at deleted posts, users, parent comments, or comments | Validator conflict by default. | Deleting the comment, restoring the referenced object, reassigning authorship, or flattening a thread are editorial/moderation choices. |
| Option/theme-mod object references pointing at deleted posts | Validator conflict by default. | Options often contain theme or plugin contracts that ForkPress cannot infer. |
| Term relationships pointing at deleted taxonomy rows | Validator conflict by default. | Recreating terms may collide with slugs, hierarchy, counts, and plugin taxonomy semantics. |
| Plugin-owned graph references | Plugin validator conflict or plugin merge driver only. | Only the plugin can reliably define graph identity, invariants, and safe repairs. |

## When Automatic Repair Becomes Acceptable

Automatic repair can move from review-only to applied only when all conditions
are true:

- The repair has a local owner: WordPress core semantics, a bundled ForkPress
  validator with tests, or an explicit plugin merge driver.
- The repair is deterministic from base/source/target state.
- The repair validates the final candidate graph, not just individual rows.
- The audit records original source, original target, chosen repair, and reason.
- Re-running the merge after the repair is idempotent.
- Stale-review guards still block applying old decisions after target drift.

## Test Shape

Every new automatic repair needs both:

- a negative validator/conflict test proving the unrepaired incoherent graph is
  held for review; and
- a positive repair test proving the repaired graph is coherent, auditable, and
  idempotent after a rerun.

For WordPress media repairs, include real upload files and generated sizes. For
plugin repairs, include custom tables, JSON, serialized PHP, options/postmeta,
and any referenced files.

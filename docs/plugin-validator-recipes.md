# Plugin Validator Recipes

Plugin validators are the place to encode plugin-owned merge semantics. Use
these recipes when generic SQLite row merging can preserve rows but cannot know
whether the merged plugin graph is coherent.

This page is intentionally practical: each recipe names the data graph, the
invariant to check, the conflict identity to report, and when a merge driver is
allowed to repair the graph.

## Validator Shape

A validator should inspect the candidate target through the paths ForkPress
passes in environment variables:

```php
<?php
$db = new SQLite3((string) getenv('FORKPRESS_MERGE_TARGET_DB'));
$target_root = rtrim((string) getenv('FORKPRESS_MERGE_TARGET_ROOT'), '/');

$findings = [];

// Add plugin-owned graph checks here.

echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
```

Each finding needs a stable plugin object identity. Prefer semantic identity
over row ids when the plugin has one:

```php
$findings[] = [
    'plugin' => 'my-plugin',
    'validator' => 'my-plugin-order-graph@1',
    'type' => 'plugin-my-plugin-missing-order-product',
    'object' => 'order:' . $order_id,
    'reason' => 'order item references a missing product lookup row',
    'tables' => ['wp_wc_orders', 'wp_wc_order_items', 'wp_wc_order_itemmeta'],
    'logical_identity' => [
        'plugin' => 'my-plugin',
        'kind' => 'order_item_product_ref',
        'order_id' => (int) $order_id,
        'item_id' => (int) $item_id,
        'product_id' => (int) $product_id,
    ],
    'severity' => 'error',
    'resolution_policy' => 'review-only',
    'suggested_action' => 'Restore the referenced product or remove the order item in the plugin UI.',
    'manual_review_reason' => 'ForkPress cannot know whether the order item should be deleted or the product restored.',
];
```

## WooCommerce HPOS-Style Orders

Check orders as one graph:

- `wp_wc_orders`
- `wp_wc_order_addresses`
- `wp_wc_orders_meta`
- `wp_woocommerce_order_items`
- `wp_woocommerce_order_itemmeta`
- `wp_wc_product_meta_lookup`
- order cache/options if the plugin stores them

Required invariants:

- Every order item belongs to an existing order.
- Every item `_product_id` points at an existing product lookup row or a live
  product post, depending on the plugin version.
- Address and order meta rows do not survive without their owner order.
- Cached order ids in options do not point at deleted orders.

Use `logical_identity` values such as:

```json
{
  "plugin": "woocommerce",
  "kind": "order_item_product_ref",
  "order_id": 1000123,
  "item_id": 1000456,
  "product_id": 1000789
}
```

A driver may repair only plugin-owned caches that WooCommerce can regenerate.
It should not delete order items, rewrite product ids, or restore products
without explicit plugin ownership of that decision.

## Gravity Forms-Style Entry Fields

Check entries against the form field map:

- form metadata, especially serialized or JSON `display_meta`
- entries
- entry meta rows whose keys are field ids
- uploaded files referenced from entry meta

Required invariants:

- Entry meta field keys exist in the current form field map.
- File-upload field values point at existing files inside uploads.
- Removed fields do not leave active entry metadata unless the plugin treats it
  as historical immutable data.

Use a logical identity that includes the form id, entry id, and field id:

```json
{
  "plugin": "gravityforms",
  "kind": "entry_field_ref",
  "form_id": 7,
  "entry_id": 42,
  "field_id": "3"
}
```

Most field-map conflicts should remain review-only. A driver can safely rebuild
derived indexes only if Gravity Forms treats those indexes as caches.

## ACF-Style Field Definitions

Check field metadata against field-definition posts:

- `acf-field` and `acf-field-group` posts
- hidden postmeta that stores field keys
- serialized relationship, post object, taxonomy, image, file, and repeater
  values

Required invariants:

- Hidden field-key metadata points at a live field definition.
- Relationship-like values point at live WordPress objects.
- Repeater/flexible-content nested values use known subfield keys.

Use logical identities based on field keys, not only post ids:

```json
{
  "plugin": "advanced-custom-fields",
  "kind": "field_key_ref",
  "post_id": 123,
  "meta_key": "hero_image",
  "field_key": "field_abc123"
}
```

ACF field data is editorial content. A merge driver should not rewrite field
keys or relationship ids unless ACF itself exposes a deterministic migration
for that field type.

## Elementor-Style Widget Trees

Check page-builder JSON as a graph:

- `_elementor_data` postmeta JSON
- attachments referenced by image, gallery, video, and background controls
- global widgets/templates referenced from widget nodes
- generated CSS/files if the plugin stores them in uploads

Required invariants:

- Attachment ids in widget settings point at live attachment rows.
- Attachment URLs or paths point at existing upload files.
- Template/global-widget references point at live template posts.

Use a logical identity that includes the document id and widget id:

```json
{
  "plugin": "elementor",
  "kind": "widget_attachment_ref",
  "document_id": 55,
  "widget_id": "8f3d0aa",
  "setting": "image.id",
  "attachment_id": 88
}
```

Generated CSS can be repairable if Elementor can rebuild it exactly from the
candidate database. Widget JSON edits are content decisions and should stay
review-only.

## Yoast SEO-Style Indexables

Check index rows as derived plugin state:

- `wp_yoast_indexable`
- `wp_yoast_indexable_hierarchy`
- object rows the indexables point at
- canonical permalink fields

Required invariants:

- An indexable object id points at a live post, term, user, or archive owner.
- `(object_type, object_sub_type, permalink)` does not create a duplicate
  canonical route introduced by the merge.
- Hierarchy edges point at live indexable rows.

Use route-oriented logical identities:

```json
{
  "plugin": "wordpress-seo",
  "kind": "canonical_permalink",
  "object_type": "post",
  "permalink": "https://example.test/about/"
}
```

Yoast index rows are often rebuildable caches. A driver can delete/rebuild
indexables only if it reruns Yoast's own indexer and the validator confirms the
final graph is coherent.

## Events Calendar-Style Event Graphs

Check event custom post types and denormalized options:

- event, venue, and organizer posts
- event postmeta for venue/organizer ids and date ranges
- taxonomy relationships
- cached event id options such as recent-event lists

Required invariants:

- Event venue and organizer metadata points at live CPT rows.
- Cached event id arrays do not reference deleted events.
- Date-range cache rows match the live event rows if the plugin stores them.

Use option-aware logical identities for stale caches:

```json
{
  "plugin": "the-events-calendar",
  "kind": "event_option_ref",
  "option_name": "tribe_events_recent_event_ids",
  "event_id": 1004,
  "index": 2
}
```

Cache-only options may be repairable if The Events Calendar can regenerate them
from live event rows. Event, venue, organizer, and taxonomy edits remain
review-only unless the plugin owns an explicit migration.

## Driver Rules

Use a merge driver only after a validator has reported a plugin conflict and
the driver can prove the repaired graph is coherent. A driver must:

- receive one conflict context;
- mutate only plugin-owned rows/files;
- emit `validated` if it only proves the conflict is already clear, or
  `applied` if it changed the target;
- leave the validator file unchanged;
- rerun the validator cleanly before ForkPress records the driver result.

Do not use a driver to make generic editorial choices such as deleting content,
changing object ids, or rewriting JSON references to a guessed replacement.

## Acceptance Tests

For every production validator or driver, add a focused test that proves:

- the incoherent graph becomes a plugin-scoped conflict;
- the conflict has a stable `logical_identity`;
- generic source/target resolution is blocked for that plugin conflict;
- stale revalidation returns changed plugin evidence to `needs-action`;
- a driver, if present, is rejected when it does not clear the validator
  finding.

The existing `tests/cow/plugin_validator.php` file contains fixtures for each
recipe shape and should be used as the baseline for new production validators.

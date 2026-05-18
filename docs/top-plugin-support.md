# Top Plugin Support

ForkPress tracks the current WordPress.org popular-plugin list as a concrete
compatibility target. The checked-in manifest is
`runtime/cow/plugin-compat/popular-wordpress-plugins.json`.

## What The Manifest Means

Every plugin in the manifest has:

- `install_support: wp-admin-plugin-install`, meaning ForkPress must keep the
  WordPress admin plugin install and update routes available inside branch
  previews.
- `merge_support: generic-audit`, meaning the plugin can be installed and
  merged with the normal SQLite/file merge engine. If the active plugin does
  not ship a ForkPress validator, ForkPress records a durable
  `plugin-validator-unchecked` audit decision instead of pretending it checked
  plugin-owned semantics.
- `merge_support: semantic-recipe-plus-generic-audit`, meaning ForkPress also
  has a tested semantic recipe for a plugin-shaped graph used by that plugin
  family.

The manifest is not a claim that ForkPress can automatically resolve every
domain-specific conflict for all 100 plugins. It is the release target that
keeps installation support and merge coverage accounting explicit.

## Refreshing The List

The list comes from the WordPress.org Plugin Directory popular browse API.

```bash
node scripts/popular-plugin-compat.mjs refresh
node scripts/popular-plugin-compat.mjs validate
```

The validator requires exactly 100 unique ranked plugins and verifies each
record declares the install support and merge coverage policy.

## Current Semantic Recipes

ForkPress has focused plugin-shaped validator coverage for:

- ACF field definitions
- Elementor widget trees
- The Events Calendar event graphs
- WooCommerce HPOS orders
- Yoast indexables

These recipes live in [Plugin Validator Recipes](./plugin-validator-recipes.md)
and are backed by `tests/cow/plugin_validator.php`.

## What Is Still Missing

To move a top-100 plugin from `generic-audit` to
`semantic-recipe-plus-generic-audit`, add a validator recipe that models the
plugin's own object graph, add focused merge tests, and only add a merge driver
when the repair is deterministic and plugin-owned.

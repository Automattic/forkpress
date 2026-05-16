<?php

$pass = 0;
$fail = 0;

function assert_same($actual, $expected, string $msg): void {
    global $pass, $fail;
    if ($actual === $expected) {
        echo "  PASS: $msg\n";
        $pass++;
        return;
    }
    echo "  FAIL: $msg (got " . var_export($actual, true) . ', expected ' . var_export($expected, true) . ")\n";
    $fail++;
}

define('FORKPRESS_COW_MERGE_TESTS', true);
require_once __DIR__ . '/../../scripts/cow/merge.php';

echo "=== COW merge smoke tests ===\n";

$base_theme_mods = serialize([
    'color' => 'blue',
    'nav_menu_locations' => [],
]);
$source_theme_mods = serialize([
    'color' => 'blue',
    'nav_menu_locations' => [
        'forkpress_semantic_source' => 101,
    ],
]);
$target_theme_mods = serialize([
    'color' => 'red',
    'nav_menu_locations' => [
        'forkpress_semantic_target' => 202,
    ],
]);

$merged_theme_mods = unserialize(
    (string)cow_merge_wordpress_theme_mods_nav_locations_merge(
        $base_theme_mods,
        $source_theme_mods,
        $target_theme_mods
    ),
    ['allowed_classes' => false]
);

assert_same($merged_theme_mods['color'] ?? null, 'red', 'theme_mods merge preserves target-local unrelated changes');
assert_same($merged_theme_mods['nav_menu_locations']['forkpress_semantic_source'] ?? null, 101, 'theme_mods merge preserves source menu location');
assert_same($merged_theme_mods['nav_menu_locations']['forkpress_semantic_target'] ?? null, 202, 'theme_mods merge preserves target menu location');

$conflicting_source = serialize([
    'color' => 'blue',
    'nav_menu_locations' => [
        'primary' => 101,
    ],
]);
$conflicting_target = serialize([
    'color' => 'blue',
    'nav_menu_locations' => [
        'primary' => 202,
    ],
]);
assert_same(
    cow_merge_wordpress_theme_mods_nav_locations_merge($base_theme_mods, $conflicting_source, $conflicting_target),
    null,
    'theme_mods merge leaves same-location disagreement reviewable'
);

$source_with_unrelated_change = serialize([
    'color' => 'green',
    'nav_menu_locations' => [
        'forkpress_semantic_source' => 101,
    ],
]);
assert_same(
    cow_merge_wordpress_theme_mods_nav_locations_merge($base_theme_mods, $source_with_unrelated_change, $target_theme_mods),
    null,
    'theme_mods merge leaves source unrelated changes reviewable'
);

assert_same(
    cow_merge_wordpress_target_local_row_reason(
        'wp_options',
        ['option_name' => 'cron', 'option_value' => 'base'],
        ['option_name' => 'cron', 'option_value' => 'source'],
        ['option_name' => 'cron', 'option_value' => 'target']
    ),
    'target kept branch-local WordPress runtime option cache; source cache state is not merged',
    'runtime option cache conflicts keep target state'
);

assert_same(
    cow_merge_wordpress_target_local_row_reason(
        'wp_options',
        null,
        ['option_name' => '_transient_doing_cron', 'option_value' => 'source'],
        null
    ),
    'target kept branch-local WordPress runtime option cache; source cache state is not merged',
    'source-only runtime transients are not merged'
);

assert_same(
    cow_merge_wordpress_target_local_row_reason(
        'wp_options',
        null,
        [
            'option_name' => 'forkpress_topic_children',
            'option_value' => serialize([18000000 => [18000001]]),
        ],
        [
            'option_name' => 'forkpress_topic_children',
            'option_value' => serialize([19000000 => [19000001]]),
        ]
    ),
    'target kept branch-local WordPress runtime option cache; source cache state is not merged',
    'taxonomy children cache conflicts keep target state'
);

assert_same(
    cow_merge_wordpress_target_local_row_reason(
        'wp_options',
        null,
        [
            'option_name' => 'plugin_children',
            'option_value' => 'source content',
        ],
        [
            'option_name' => 'plugin_children',
            'option_value' => 'target content',
        ]
    ),
    null,
    'non-cache children options remain reviewable'
);

assert_same(
    cow_merge_wordpress_target_local_row_reason(
        'wp_options',
        ['option_name' => 'blogname', 'option_value' => 'base'],
        ['option_name' => 'blogname', 'option_value' => 'source'],
        ['option_name' => 'blogname', 'option_value' => 'target']
    ),
    null,
    'content options remain reviewable'
);

if ($fail > 0) {
    echo "COW merge smoke tests failed ($fail failures, $pass passes).\n";
    exit(1);
}

echo "COW merge smoke tests passed ($pass assertions).\n";

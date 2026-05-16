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

function smoke_remove_tree(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

function smoke_open_db(string $path): SQLite3 {
    $db = new SQLite3($path);
    $db->busyTimeout(5000);
    return $db;
}

function smoke_scalar(string $path, string $sql): mixed {
    $db = smoke_open_db($path);
    $value = $db->querySingle($sql);
    $db->close();
    return $value;
}

function smoke_create_posts_db(string $path): void {
    $db = smoke_open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("CREATE TABLE wp_postmeta (
        meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER NOT NULL,
        meta_key TEXT NOT NULL,
        meta_value TEXT NOT NULL
    )");
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (1, 'Base Page', 'Base content', 'publish', 'page', 'base-page')");
    $db->exec("INSERT INTO wp_postmeta (meta_id, post_id, meta_key, meta_value) VALUES
        (2, 1, '_forkpress_smoke_note', 'Base note')");
    $db->close();
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

$tmp = sys_get_temp_dir() . '/forkpress-cow-merge-smoke-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);
try {
    $base = $tmp . '/base.sqlite';
    $source = $tmp . '/source.sqlite';
    $target = $tmp . '/target.sqlite';
    $metadata = $tmp . '/.forkpress/cow/merge/metadata.sqlite';

    smoke_create_posts_db($base);
    copy($base, $source);
    copy($base, $target);

    $db = smoke_open_db($source);
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (18000000, 'Branch Page', 'Created on feature branch', 'publish', 'page', 'branch-page')");
    $db->close();

    $db = smoke_open_db($target);
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (19000000, 'Main Page', 'Created on main branch', 'publish', 'page', 'main-page')");
    $db->close();

    $result = cow_merge_databases($base, $source, $target, $metadata, 'feature-smoke-pages', 'main');
    assert_same($result['status'], 'completed', 'independent branch and main page inserts complete cleanly');
    assert_same((int)($result['conflicts'] ?? -1), 0, 'independent branch and main page inserts do not create merge conflicts');
    assert_same(smoke_scalar($target, "SELECT post_content FROM wp_posts WHERE post_title = 'Branch Page'"), 'Created on feature branch', 'merged target includes the branch-created page');
    assert_same(smoke_scalar($target, "SELECT post_content FROM wp_posts WHERE post_title = 'Main Page'"), 'Created on main branch', 'merged target preserves the main-created page');
    assert_same((int)smoke_scalar($target, "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'page'"), 3, 'merged target has base, branch, and main pages');
    assert_same(
        (int)smoke_scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_posts'"),
        0,
        'page smoke merge records no wp_posts conflicts'
    );
    assert_same(
        (int)smoke_scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'source-applied'"),
        1,
        'page smoke merge audits the source page insert'
    );
    assert_same(
        (int)smoke_scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'target-kept' AND reason = 'target inserted row and source did not have it'"),
        1,
        'page smoke merge audits the target page insert'
    );

    $edit_base = $tmp . '/edit-base.sqlite';
    $edit_source = $tmp . '/edit-source.sqlite';
    $edit_target = $tmp . '/edit-target.sqlite';
    $edit_metadata = $tmp . '/.forkpress/cow/merge/edit-metadata.sqlite';

    smoke_create_posts_db($edit_base);
    copy($edit_base, $edit_source);
    copy($edit_base, $edit_target);

    $db = smoke_open_db($edit_source);
    $db->exec("UPDATE wp_posts SET post_title = 'Branch Edited Page', post_content = 'Edited on feature branch' WHERE ID = 1");
    $db->close();

    $db = smoke_open_db($edit_target);
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (19000001, 'Main Page During Edit', 'Created on main while branch edits', 'publish', 'page', 'main-page-during-edit')");
    $db->close();

    $edit_result = cow_merge_databases($edit_base, $edit_source, $edit_target, $edit_metadata, 'feature-smoke-page-edit', 'main');
    assert_same($edit_result['status'], 'completed', 'branch page edit and independent main page insert complete cleanly');
    assert_same((int)($edit_result['conflicts'] ?? -1), 0, 'branch page edit and independent main page insert do not create merge conflicts');
    assert_same(smoke_scalar($edit_target, 'SELECT post_content FROM wp_posts WHERE ID = 1'), 'Edited on feature branch', 'merged target includes the branch page edit');
    assert_same(smoke_scalar($edit_target, "SELECT post_content FROM wp_posts WHERE post_title = 'Main Page During Edit'"), 'Created on main while branch edits', 'merged target preserves the main page inserted during branch edit');
    assert_same(
        (int)smoke_scalar($edit_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'source-applied'"),
        1,
        'page edit smoke merge audits the source page update'
    );
    assert_same(
        (int)smoke_scalar($edit_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'target-kept' AND reason = 'target inserted row and source did not have it'"),
        1,
        'page edit smoke merge audits the independent target page insert'
    );

    $delete_base = $tmp . '/delete-base.sqlite';
    $delete_source = $tmp . '/delete-source.sqlite';
    $delete_target = $tmp . '/delete-target.sqlite';
    $delete_metadata = $tmp . '/.forkpress/cow/merge/delete-metadata.sqlite';

    smoke_create_posts_db($delete_base);
    copy($delete_base, $delete_source);
    copy($delete_base, $delete_target);

    $db = smoke_open_db($delete_source);
    $db->exec('DELETE FROM wp_posts WHERE ID = 1');
    $db->close();

    $db = smoke_open_db($delete_target);
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (19000002, 'Main Page During Delete', 'Created on main while branch deletes', 'publish', 'page', 'main-page-during-delete')");
    $db->close();

    $delete_result = cow_merge_databases($delete_base, $delete_source, $delete_target, $delete_metadata, 'feature-smoke-page-delete', 'main');
    assert_same($delete_result['status'], 'completed', 'branch page delete and independent main page insert complete cleanly');
    assert_same((int)($delete_result['conflicts'] ?? -1), 0, 'branch page delete and independent main page insert do not create merge conflicts');
    assert_same((int)smoke_scalar($delete_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 1'), 0, 'merged target applies the branch page delete');
    assert_same(smoke_scalar($delete_target, "SELECT post_content FROM wp_posts WHERE post_title = 'Main Page During Delete'"), 'Created on main while branch deletes', 'merged target preserves the main page inserted during branch delete');
    assert_same(
        (int)smoke_scalar($delete_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'source-applied'"),
        1,
        'page delete smoke merge audits the source page delete'
    );
    assert_same(
        (int)smoke_scalar($delete_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'target-kept' AND reason = 'target inserted row and source did not have it'"),
        1,
        'page delete smoke merge audits the independent target page insert'
    );

    $postmeta_base = $tmp . '/postmeta-base.sqlite';
    $postmeta_source = $tmp . '/postmeta-source.sqlite';
    $postmeta_target = $tmp . '/postmeta-target.sqlite';
    $postmeta_metadata = $tmp . '/.forkpress/cow/merge/postmeta-metadata.sqlite';

    smoke_create_posts_db($postmeta_base);
    copy($postmeta_base, $postmeta_source);
    copy($postmeta_base, $postmeta_target);

    $db = smoke_open_db($postmeta_source);
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (18000010, 'Branch Page With Metadata', 'Branch page metadata content', 'publish', 'page', 'branch-page-with-metadata')");
    $db->exec("INSERT INTO wp_postmeta (meta_id, post_id, meta_key, meta_value) VALUES
        (18000011, 18000010, '_forkpress_smoke_graph', '{\"branch\":\"source\",\"post_id\":18000010}')");
    $db->close();

    $db = smoke_open_db($postmeta_target);
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (19000010, 'Main Page With Metadata', 'Main page metadata content', 'publish', 'page', 'main-page-with-metadata')");
    $db->exec("INSERT INTO wp_postmeta (meta_id, post_id, meta_key, meta_value) VALUES
        (19000011, 19000010, '_forkpress_smoke_graph', '{\"branch\":\"target\",\"post_id\":19000010}')");
    $db->close();

    $postmeta_result = cow_merge_databases($postmeta_base, $postmeta_source, $postmeta_target, $postmeta_metadata, 'feature-smoke-page-postmeta', 'main');
    assert_same($postmeta_result['status'], 'completed', 'branch and main page-plus-postmeta inserts complete cleanly');
    assert_same((int)($postmeta_result['conflicts'] ?? -1), 0, 'branch and main page-plus-postmeta inserts do not create merge conflicts');
    assert_same(smoke_scalar($postmeta_target, 'SELECT post_title FROM wp_posts WHERE ID = 18000010'), 'Branch Page With Metadata', 'merged target includes the branch page row');
    assert_same(smoke_scalar($postmeta_target, 'SELECT post_title FROM wp_posts WHERE ID = 19000010'), 'Main Page With Metadata', 'merged target preserves the main page row');
    assert_same(smoke_scalar($postmeta_target, 'SELECT meta_value FROM wp_postmeta WHERE meta_id = 18000011'), '{"branch":"source","post_id":18000010}', 'merged target includes branch postmeta with its source post ID reference');
    assert_same(smoke_scalar($postmeta_target, 'SELECT meta_value FROM wp_postmeta WHERE meta_id = 19000011'), '{"branch":"target","post_id":19000010}', 'merged target preserves target postmeta with its target post ID reference');
    assert_same(
        (int)smoke_scalar($postmeta_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name IN ('wp_posts', 'wp_postmeta')"),
        0,
        'page-plus-postmeta smoke merge records no WordPress row conflicts'
    );
    assert_same(
        (int)smoke_scalar($postmeta_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'source-applied'"),
        1,
        'page-plus-postmeta smoke merge audits the source page insert'
    );
    assert_same(
        (int)smoke_scalar($postmeta_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_postmeta' AND decision = 'source-applied'"),
        1,
        'page-plus-postmeta smoke merge audits the source metadata insert'
    );
    assert_same(
        (int)smoke_scalar($postmeta_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'target-kept' AND reason = 'target inserted row and source did not have it'"),
        1,
        'page-plus-postmeta smoke merge audits the target page insert'
    );
    assert_same(
        (int)smoke_scalar($postmeta_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_postmeta' AND decision = 'target-kept' AND reason = 'target inserted row and source did not have it'"),
        1,
        'page-plus-postmeta smoke merge audits the target metadata insert'
    );
} finally {
    smoke_remove_tree($tmp);
}

if ($fail > 0) {
    echo "COW merge smoke tests failed ($fail failures, $pass passes).\n";
    exit(1);
}

echo "COW merge smoke tests passed ($pass assertions).\n";

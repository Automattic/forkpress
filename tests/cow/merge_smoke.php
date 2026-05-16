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

function smoke_write_file(string $path, string $contents): void {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($path, $contents);
}

function smoke_insert_option(SQLite3 $db, int $id, string $name, string $value, string $autoload = 'yes'): void {
    $stmt = $db->prepare('INSERT INTO wp_options (option_id, option_name, option_value, autoload) VALUES (:id, :name, :value, :autoload)');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':value', $value, SQLITE3_TEXT);
    $stmt->bindValue(':autoload', $autoload, SQLITE3_TEXT);
    $stmt->execute();
}

function smoke_update_option(SQLite3 $db, string $name, string $value): void {
    $stmt = $db->prepare('UPDATE wp_options SET option_value = :value WHERE option_name = :name');
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':value', $value, SQLITE3_TEXT);
    $stmt->execute();
}

function smoke_insert_postmeta(SQLite3 $db, int $id, int $post_id, string $key, string $value): void {
    $stmt = $db->prepare('INSERT INTO wp_postmeta (meta_id, post_id, meta_key, meta_value) VALUES (:id, :post_id, :key, :value)');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':post_id', $post_id, SQLITE3_INTEGER);
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':value', $value, SQLITE3_TEXT);
    $stmt->execute();
}

function smoke_insert_post(
    SQLite3 $db,
    int $id,
    string $title,
    string $content,
    string $type,
    string $name,
    string $status = 'publish',
    int $parent = 0,
    string $mime_type = '',
    string $guid = ''
): void {
    $stmt = $db->prepare('INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name, post_parent, post_mime_type, guid) VALUES (:id, :title, :content, :status, :type, :name, :parent, :mime_type, :guid)');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
    $stmt->bindValue(':content', $content, SQLITE3_TEXT);
    $stmt->bindValue(':status', $status, SQLITE3_TEXT);
    $stmt->bindValue(':type', $type, SQLITE3_TEXT);
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':parent', $parent, SQLITE3_INTEGER);
    $stmt->bindValue(':mime_type', $mime_type, SQLITE3_TEXT);
    $stmt->bindValue(':guid', $guid, SQLITE3_TEXT);
    $stmt->execute();
}

function smoke_create_posts_db(string $path): void {
    $db = smoke_open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT '',
        post_parent INTEGER NOT NULL DEFAULT 0,
        post_mime_type TEXT NOT NULL DEFAULT '',
        guid TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("CREATE TABLE wp_postmeta (
        meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER NOT NULL,
        meta_key TEXT NOT NULL,
        meta_value TEXT NOT NULL
    )");
    $db->exec("CREATE TABLE wp_terms (
        term_id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        slug TEXT NOT NULL
    )");
    $db->exec("CREATE TABLE wp_term_taxonomy (
        term_taxonomy_id INTEGER PRIMARY KEY AUTOINCREMENT,
        term_id INTEGER NOT NULL,
        taxonomy TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT '',
        parent INTEGER NOT NULL DEFAULT 0,
        count INTEGER NOT NULL DEFAULT 0
    )");
    $db->exec("CREATE TABLE wp_term_relationships (
        object_id INTEGER NOT NULL,
        term_taxonomy_id INTEGER NOT NULL,
        term_order INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (object_id, term_taxonomy_id)
    )");
    $db->exec("CREATE TABLE wp_options (
        option_id INTEGER PRIMARY KEY AUTOINCREMENT,
        option_name TEXT NOT NULL UNIQUE,
        option_value TEXT NOT NULL,
        autoload TEXT NOT NULL DEFAULT 'yes'
    )");
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (1, 'Base Page', 'Base content', 'publish', 'page', 'base-page')");
    $db->exec("INSERT INTO wp_postmeta (meta_id, post_id, meta_key, meta_value) VALUES
        (2, 1, '_forkpress_smoke_note', 'Base note')");
    smoke_insert_option($db, 3, 'forkpress_smoke_base', '{"base":true}');
    smoke_insert_option($db, 4, 'theme_mods_forkpress_smoke', serialize([
        'color' => 'blue',
        'nav_menu_locations' => [],
    ]));
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

    $taxonomy_base = $tmp . '/taxonomy-base.sqlite';
    $taxonomy_source = $tmp . '/taxonomy-source.sqlite';
    $taxonomy_target = $tmp . '/taxonomy-target.sqlite';
    $taxonomy_metadata = $tmp . '/.forkpress/cow/merge/taxonomy-metadata.sqlite';

    smoke_create_posts_db($taxonomy_base);
    copy($taxonomy_base, $taxonomy_source);
    copy($taxonomy_base, $taxonomy_target);

    $db = smoke_open_db($taxonomy_source);
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (18000020, 'Branch Page With Term', 'Branch taxonomy content', 'publish', 'page', 'branch-page-with-term')");
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES
        (18000021, 'Branch Topic', 'branch-topic')");
    $db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES
        (18000022, 18000021, 'category', 'Branch topic description', 0, 1)");
    $db->exec("INSERT INTO wp_term_relationships (object_id, term_taxonomy_id, term_order) VALUES
        (18000020, 18000022, 0)");
    $db->close();

    $db = smoke_open_db($taxonomy_target);
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (19000020, 'Main Page With Term', 'Main taxonomy content', 'publish', 'page', 'main-page-with-term')");
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES
        (19000021, 'Main Topic', 'main-topic')");
    $db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES
        (19000022, 19000021, 'category', 'Main topic description', 0, 1)");
    $db->exec("INSERT INTO wp_term_relationships (object_id, term_taxonomy_id, term_order) VALUES
        (19000020, 19000022, 0)");
    $db->close();

    $taxonomy_result = cow_merge_databases($taxonomy_base, $taxonomy_source, $taxonomy_target, $taxonomy_metadata, 'feature-smoke-page-taxonomy', 'main');
    assert_same($taxonomy_result['status'], 'completed', 'branch and main page-plus-taxonomy inserts complete cleanly');
    assert_same((int)($taxonomy_result['conflicts'] ?? -1), 0, 'branch and main page-plus-taxonomy inserts do not create merge conflicts');
    assert_same(smoke_scalar($taxonomy_target, 'SELECT post_title FROM wp_posts WHERE ID = 18000020'), 'Branch Page With Term', 'merged target includes the branch taxonomy page');
    assert_same(smoke_scalar($taxonomy_target, 'SELECT name FROM wp_terms WHERE term_id = 18000021'), 'Branch Topic', 'merged target includes the branch term row');
    assert_same(smoke_scalar($taxonomy_target, 'SELECT description FROM wp_term_taxonomy WHERE term_taxonomy_id = 18000022'), 'Branch topic description', 'merged target includes the branch term taxonomy row');
    assert_same((int)smoke_scalar($taxonomy_target, 'SELECT COUNT(*) FROM wp_term_relationships WHERE object_id = 18000020 AND term_taxonomy_id = 18000022'), 1, 'merged target includes the branch page-term relationship');
    assert_same(smoke_scalar($taxonomy_target, 'SELECT post_title FROM wp_posts WHERE ID = 19000020'), 'Main Page With Term', 'merged target preserves the main taxonomy page');
    assert_same(smoke_scalar($taxonomy_target, 'SELECT name FROM wp_terms WHERE term_id = 19000021'), 'Main Topic', 'merged target preserves the main term row');
    assert_same(smoke_scalar($taxonomy_target, 'SELECT description FROM wp_term_taxonomy WHERE term_taxonomy_id = 19000022'), 'Main topic description', 'merged target preserves the main term taxonomy row');
    assert_same((int)smoke_scalar($taxonomy_target, 'SELECT COUNT(*) FROM wp_term_relationships WHERE object_id = 19000020 AND term_taxonomy_id = 19000022'), 1, 'merged target preserves the main page-term relationship');
    assert_same(
        (int)smoke_scalar($taxonomy_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name IN ('wp_posts', 'wp_terms', 'wp_term_taxonomy', 'wp_term_relationships')"),
        0,
        'page-plus-taxonomy smoke merge records no WordPress graph conflicts'
    );
    assert_same(
        (int)smoke_scalar($taxonomy_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_terms', 'wp_term_taxonomy', 'wp_term_relationships') AND decision = 'source-applied'"),
        4,
        'page-plus-taxonomy smoke merge audits all source graph inserts'
    );
    assert_same(
        (int)smoke_scalar($taxonomy_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_terms', 'wp_term_taxonomy', 'wp_term_relationships') AND decision = 'target-kept' AND reason = 'target inserted row and source did not have it'"),
        4,
        'page-plus-taxonomy smoke merge audits all target graph inserts'
    );

    $menu_base = $tmp . '/menu-base.sqlite';
    $menu_source = $tmp . '/menu-source.sqlite';
    $menu_target = $tmp . '/menu-target.sqlite';
    $menu_metadata = $tmp . '/.forkpress/cow/merge/menu-metadata.sqlite';

    smoke_create_posts_db($menu_base);
    copy($menu_base, $menu_source);
    copy($menu_base, $menu_target);

    $db = smoke_open_db($menu_source);
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (18000040, 'Branch Menu Page', 'Branch menu page content', 'publish', 'page', 'branch-menu-page'),
        (18000043, 'Branch Menu Item', '', 'publish', 'nav_menu_item', 'branch-menu-item')");
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES
        (18000041, 'Branch Menu', 'branch-menu')");
    $db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES
        (18000042, 18000041, 'nav_menu', 'Branch menu taxonomy', 0, 1)");
    $db->exec("INSERT INTO wp_term_relationships (object_id, term_taxonomy_id, term_order) VALUES
        (18000043, 18000042, 0)");
    smoke_insert_postmeta($db, 18000044, 18000043, '_menu_item_type', 'post_type');
    smoke_insert_postmeta($db, 18000045, 18000043, '_menu_item_object', 'page');
    smoke_insert_postmeta($db, 18000046, 18000043, '_menu_item_object_id', '18000040');
    smoke_insert_postmeta($db, 18000047, 18000043, '_menu_item_menu_item_parent', '0');
    smoke_update_option($db, 'theme_mods_forkpress_smoke', serialize([
        'color' => 'blue',
        'nav_menu_locations' => [
            'branch_primary' => 18000041,
        ],
    ]));
    $db->close();

    $db = smoke_open_db($menu_target);
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (19000040, 'Main Menu Page', 'Main menu page content', 'publish', 'page', 'main-menu-page'),
        (19000043, 'Main Menu Item', '', 'publish', 'nav_menu_item', 'main-menu-item')");
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES
        (19000041, 'Main Menu', 'main-menu')");
    $db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES
        (19000042, 19000041, 'nav_menu', 'Main menu taxonomy', 0, 1)");
    $db->exec("INSERT INTO wp_term_relationships (object_id, term_taxonomy_id, term_order) VALUES
        (19000043, 19000042, 0)");
    smoke_insert_postmeta($db, 19000044, 19000043, '_menu_item_type', 'post_type');
    smoke_insert_postmeta($db, 19000045, 19000043, '_menu_item_object', 'page');
    smoke_insert_postmeta($db, 19000046, 19000043, '_menu_item_object_id', '19000040');
    smoke_insert_postmeta($db, 19000047, 19000043, '_menu_item_menu_item_parent', '0');
    smoke_update_option($db, 'theme_mods_forkpress_smoke', serialize([
        'color' => 'red',
        'nav_menu_locations' => [
            'main_primary' => 19000041,
        ],
    ]));
    $db->close();

    $menu_result = cow_merge_databases($menu_base, $menu_source, $menu_target, $menu_metadata, 'feature-smoke-page-menu', 'main');
    assert_same($menu_result['status'], 'completed', 'branch and main page-plus-menu inserts complete cleanly');
    assert_same((int)($menu_result['conflicts'] ?? -1), 0, 'branch and main page-plus-menu inserts do not create merge conflicts');
    assert_same(smoke_scalar($menu_target, 'SELECT post_title FROM wp_posts WHERE ID = 18000040'), 'Branch Menu Page', 'merged target includes the branch menu page');
    assert_same(smoke_scalar($menu_target, 'SELECT post_title FROM wp_posts WHERE ID = 18000043'), 'Branch Menu Item', 'merged target includes the branch nav menu item post');
    assert_same(smoke_scalar($menu_target, 'SELECT name FROM wp_terms WHERE term_id = 18000041'), 'Branch Menu', 'merged target includes the branch nav menu term');
    assert_same(smoke_scalar($menu_target, 'SELECT taxonomy FROM wp_term_taxonomy WHERE term_taxonomy_id = 18000042'), 'nav_menu', 'merged target includes the branch nav menu taxonomy');
    assert_same((int)smoke_scalar($menu_target, 'SELECT COUNT(*) FROM wp_term_relationships WHERE object_id = 18000043 AND term_taxonomy_id = 18000042'), 1, 'merged target includes the branch menu item relationship');
    assert_same(smoke_scalar($menu_target, "SELECT meta_value FROM wp_postmeta WHERE post_id = 18000043 AND meta_key = '_menu_item_object_id'"), '18000040', 'merged target includes branch menu item page reference');
    assert_same(smoke_scalar($menu_target, 'SELECT post_title FROM wp_posts WHERE ID = 19000040'), 'Main Menu Page', 'merged target preserves the main menu page');
    assert_same(smoke_scalar($menu_target, 'SELECT post_title FROM wp_posts WHERE ID = 19000043'), 'Main Menu Item', 'merged target preserves the main nav menu item post');
    assert_same(smoke_scalar($menu_target, 'SELECT name FROM wp_terms WHERE term_id = 19000041'), 'Main Menu', 'merged target preserves the main nav menu term');
    assert_same(smoke_scalar($menu_target, 'SELECT taxonomy FROM wp_term_taxonomy WHERE term_taxonomy_id = 19000042'), 'nav_menu', 'merged target preserves the main nav menu taxonomy');
    assert_same((int)smoke_scalar($menu_target, 'SELECT COUNT(*) FROM wp_term_relationships WHERE object_id = 19000043 AND term_taxonomy_id = 19000042'), 1, 'merged target preserves the main menu item relationship');
    assert_same(smoke_scalar($menu_target, "SELECT meta_value FROM wp_postmeta WHERE post_id = 19000043 AND meta_key = '_menu_item_object_id'"), '19000040', 'merged target preserves target menu item page reference');
    $merged_menu_theme_mods = unserialize(
        (string)smoke_scalar($menu_target, "SELECT option_value FROM wp_options WHERE option_name = 'theme_mods_forkpress_smoke'"),
        ['allowed_classes' => false]
    );
    assert_same($merged_menu_theme_mods['color'] ?? null, 'red', 'page-plus-menu merge preserves target-local theme mod changes');
    assert_same($merged_menu_theme_mods['nav_menu_locations']['branch_primary'] ?? null, 18000041, 'page-plus-menu merge includes the branch menu location');
    assert_same($merged_menu_theme_mods['nav_menu_locations']['main_primary'] ?? null, 19000041, 'page-plus-menu merge preserves the main menu location');
    assert_same(
        (int)smoke_scalar($menu_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name IN ('wp_posts', 'wp_postmeta', 'wp_terms', 'wp_term_taxonomy', 'wp_term_relationships', 'wp_options')"),
        0,
        'page-plus-menu smoke merge records no WordPress graph conflicts'
    );
    assert_same(
        (int)smoke_scalar($menu_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_postmeta', 'wp_terms', 'wp_term_taxonomy', 'wp_term_relationships', 'wp_options') AND decision = 'source-applied'"),
        10,
        'page-plus-menu smoke merge audits all source graph inserts and the merged theme_mods option'
    );
    assert_same(
        (int)smoke_scalar($menu_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_postmeta', 'wp_terms', 'wp_term_taxonomy', 'wp_term_relationships') AND decision = 'target-kept' AND reason = 'target inserted row and source did not have it'"),
        9,
        'page-plus-menu smoke merge audits all target graph inserts'
    );

    $block_base = $tmp . '/block-base.sqlite';
    $block_source = $tmp . '/block-source.sqlite';
    $block_target = $tmp . '/block-target.sqlite';
    $block_metadata = $tmp . '/.forkpress/cow/merge/block-metadata.sqlite';

    smoke_create_posts_db($block_base);
    copy($block_base, $block_source);
    copy($block_base, $block_target);

    $source_block_content = '<!-- wp:paragraph --><p>Branch reusable block body</p><!-- /wp:paragraph -->';
    $source_page_content = '<!-- wp:paragraph --><p>Branch page before block</p><!-- /wp:paragraph -->' . "\n" .
        '<!-- wp:block {"ref":18000051} /-->';
    $target_block_content = '<!-- wp:paragraph --><p>Main reusable block body</p><!-- /wp:paragraph -->';
    $target_page_content = '<!-- wp:paragraph --><p>Main page before block</p><!-- /wp:paragraph -->' . "\n" .
        '<!-- wp:block {"ref":19000051} /-->';

    $db = smoke_open_db($block_source);
    smoke_insert_post($db, 18000050, 'Branch Page With Reusable Block', $source_page_content, 'page', 'branch-page-with-reusable-block');
    smoke_insert_post($db, 18000051, 'Branch Reusable Block', $source_block_content, 'wp_block', 'branch-reusable-block');
    $db->close();

    $db = smoke_open_db($block_target);
    smoke_insert_post($db, 19000050, 'Main Page With Reusable Block', $target_page_content, 'page', 'main-page-with-reusable-block');
    smoke_insert_post($db, 19000051, 'Main Reusable Block', $target_block_content, 'wp_block', 'main-reusable-block');
    $db->close();

    $block_result = cow_merge_databases($block_base, $block_source, $block_target, $block_metadata, 'feature-smoke-page-block', 'main');
    assert_same($block_result['status'], 'completed', 'branch and main page-plus-reusable-block inserts complete cleanly');
    assert_same((int)($block_result['conflicts'] ?? -1), 0, 'branch and main page-plus-reusable-block inserts do not create merge conflicts');
    assert_same(smoke_scalar($block_target, 'SELECT post_type FROM wp_posts WHERE ID = 18000051'), 'wp_block', 'merged target includes the branch reusable block row');
    assert_same(smoke_scalar($block_target, 'SELECT post_title FROM wp_posts WHERE ID = 18000050'), 'Branch Page With Reusable Block', 'merged target includes the branch page using a reusable block');
    assert_same(smoke_scalar($block_target, 'SELECT post_content FROM wp_posts WHERE ID = 18000050'), $source_page_content, 'merged target preserves the branch page reusable-block reference');
    assert_same(smoke_scalar($block_target, 'SELECT post_type FROM wp_posts WHERE ID = 19000051'), 'wp_block', 'merged target preserves the main reusable block row');
    assert_same(smoke_scalar($block_target, 'SELECT post_title FROM wp_posts WHERE ID = 19000050'), 'Main Page With Reusable Block', 'merged target preserves the main page using a reusable block');
    assert_same(smoke_scalar($block_target, 'SELECT post_content FROM wp_posts WHERE ID = 19000050'), $target_page_content, 'merged target preserves the main page reusable-block reference');
    assert_same(
        (int)smoke_scalar($block_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_posts'"),
        0,
        'page-plus-reusable-block smoke merge records no WordPress row conflicts'
    );
    assert_same(
        (int)smoke_scalar($block_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'source-applied'"),
        2,
        'page-plus-reusable-block smoke merge audits the source page and block inserts'
    );
    assert_same(
        (int)smoke_scalar($block_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'target-kept' AND reason = 'target inserted row and source did not have it'"),
        2,
        'page-plus-reusable-block smoke merge audits the target page and block inserts'
    );

    $attachment_base_root = $tmp . '/attachment-base-root';
    $attachment_source_root = $tmp . '/attachment-source-root';
    $attachment_target_root = $tmp . '/attachment-target-root';
    $attachment_base = $attachment_base_root . '/wp-content/database/.ht.sqlite';
    $attachment_source = $attachment_source_root . '/wp-content/database/.ht.sqlite';
    $attachment_target = $attachment_target_root . '/wp-content/database/.ht.sqlite';
    $attachment_file_base = $tmp . '/.forkpress/cow/merge/file-bases/feature-smoke-page-attachment.json';
    $attachment_metadata = $tmp . '/.forkpress/cow/merge/attachment-metadata.sqlite';

    mkdir(dirname($attachment_base), 0777, true);
    mkdir(dirname($attachment_source), 0777, true);
    mkdir(dirname($attachment_target), 0777, true);
    smoke_create_posts_db($attachment_base);
    copy($attachment_base, $attachment_source);
    copy($attachment_base, $attachment_target);
    cow_merge_capture_file_base($attachment_base_root, $attachment_file_base);

    $source_attachment_meta = serialize([
        'file' => '2026/05/source-featured.jpg',
        'width' => 640,
        'height' => 480,
        'sizes' => [
            'thumbnail' => [
                'file' => 'source-featured-150x150.jpg',
                'width' => 150,
                'height' => 150,
                'mime-type' => 'image/jpeg',
            ],
        ],
    ]);
    $target_attachment_meta = serialize([
        'file' => '2026/05/main-featured.jpg',
        'width' => 800,
        'height' => 600,
        'sizes' => [
            'thumbnail' => [
                'file' => 'main-featured-150x150.jpg',
                'width' => 150,
                'height' => 150,
                'mime-type' => 'image/jpeg',
            ],
        ],
    ]);

    $db = smoke_open_db($attachment_source);
    smoke_insert_post($db, 18000060, 'Branch Page With Featured Image', 'Branch featured image content', 'page', 'branch-page-with-featured-image');
    smoke_insert_post($db, 18000061, 'source-featured.jpg', '', 'attachment', 'source-featured-jpg', 'inherit', 18000060, 'image/jpeg', 'http://example.test/wp-content/uploads/2026/05/source-featured.jpg');
    smoke_insert_postmeta($db, 18000062, 18000060, '_thumbnail_id', '18000061');
    smoke_insert_postmeta($db, 18000063, 18000061, '_wp_attached_file', '2026/05/source-featured.jpg');
    smoke_insert_postmeta($db, 18000064, 18000061, '_wp_attachment_metadata', $source_attachment_meta);
    $db->close();
    smoke_write_file($attachment_source_root . '/wp-content/uploads/2026/05/source-featured.jpg', 'source original image bytes');
    smoke_write_file($attachment_source_root . '/wp-content/uploads/2026/05/source-featured-150x150.jpg', 'source thumbnail image bytes');

    $db = smoke_open_db($attachment_target);
    smoke_insert_post($db, 19000060, 'Main Page With Featured Image', 'Main featured image content', 'page', 'main-page-with-featured-image');
    smoke_insert_post($db, 19000061, 'main-featured.jpg', '', 'attachment', 'main-featured-jpg', 'inherit', 19000060, 'image/jpeg', 'http://example.test/wp-content/uploads/2026/05/main-featured.jpg');
    smoke_insert_postmeta($db, 19000062, 19000060, '_thumbnail_id', '19000061');
    smoke_insert_postmeta($db, 19000063, 19000061, '_wp_attached_file', '2026/05/main-featured.jpg');
    smoke_insert_postmeta($db, 19000064, 19000061, '_wp_attachment_metadata', $target_attachment_meta);
    $db->close();
    smoke_write_file($attachment_target_root . '/wp-content/uploads/2026/05/main-featured.jpg', 'main original image bytes');
    smoke_write_file($attachment_target_root . '/wp-content/uploads/2026/05/main-featured-150x150.jpg', 'main thumbnail image bytes');

    $attachment_result = cow_merge_branch_state(
        $attachment_base,
        $attachment_source,
        $attachment_target,
        $attachment_metadata,
        'feature-smoke-page-attachment',
        'main',
        $attachment_file_base,
        $attachment_source_root,
        $attachment_target_root
    );
    assert_same($attachment_result['status'], 'completed', 'branch and main page-plus-attachment inserts complete cleanly');
    assert_same((int)($attachment_result['conflicts'] ?? -1), 0, 'branch and main page-plus-attachment inserts do not create merge conflicts');
    assert_same(smoke_scalar($attachment_target, 'SELECT post_title FROM wp_posts WHERE ID = 18000060'), 'Branch Page With Featured Image', 'merged target includes the branch featured-image page');
    assert_same(smoke_scalar($attachment_target, 'SELECT post_type FROM wp_posts WHERE ID = 18000061'), 'attachment', 'merged target includes the branch attachment row');
    assert_same((int)smoke_scalar($attachment_target, 'SELECT post_parent FROM wp_posts WHERE ID = 18000061'), 18000060, 'merged target keeps the branch attachment parent page');
    assert_same(smoke_scalar($attachment_target, "SELECT meta_value FROM wp_postmeta WHERE post_id = 18000060 AND meta_key = '_thumbnail_id'"), '18000061', 'merged target includes the branch featured-image reference');
    assert_same(smoke_scalar($attachment_target, "SELECT meta_value FROM wp_postmeta WHERE post_id = 18000061 AND meta_key = '_wp_attached_file'"), '2026/05/source-featured.jpg', 'merged target includes the branch attached-file metadata');
    assert_same(smoke_scalar($attachment_target, "SELECT meta_value FROM wp_postmeta WHERE post_id = 18000061 AND meta_key = '_wp_attachment_metadata'"), $source_attachment_meta, 'merged target includes the branch attachment generated-size metadata');
    assert_same(file_get_contents($attachment_target_root . '/wp-content/uploads/2026/05/source-featured.jpg'), 'source original image bytes', 'merged target includes the branch original upload file');
    assert_same(file_get_contents($attachment_target_root . '/wp-content/uploads/2026/05/source-featured-150x150.jpg'), 'source thumbnail image bytes', 'merged target includes the branch generated upload file');
    assert_same(smoke_scalar($attachment_target, 'SELECT post_title FROM wp_posts WHERE ID = 19000060'), 'Main Page With Featured Image', 'merged target preserves the main featured-image page');
    assert_same(smoke_scalar($attachment_target, 'SELECT post_type FROM wp_posts WHERE ID = 19000061'), 'attachment', 'merged target preserves the main attachment row');
    assert_same(smoke_scalar($attachment_target, "SELECT meta_value FROM wp_postmeta WHERE post_id = 19000060 AND meta_key = '_thumbnail_id'"), '19000061', 'merged target preserves the main featured-image reference');
    assert_same(file_get_contents($attachment_target_root . '/wp-content/uploads/2026/05/main-featured.jpg'), 'main original image bytes', 'merged target preserves the main original upload file');
    assert_same(file_get_contents($attachment_target_root . '/wp-content/uploads/2026/05/main-featured-150x150.jpg'), 'main thumbnail image bytes', 'merged target preserves the main generated upload file');
    assert_same(
        (int)smoke_scalar($attachment_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name IN ('wp_posts', 'wp_postmeta', '__files__')"),
        0,
        'page-plus-attachment smoke merge records no WordPress DB or file conflicts'
    );
    assert_same(
        (int)smoke_scalar($attachment_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'source-applied'"),
        2,
        'page-plus-attachment smoke merge audits the source page and attachment inserts'
    );
    assert_same(
        (int)smoke_scalar($attachment_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_postmeta' AND decision = 'source-applied'"),
        3,
        'page-plus-attachment smoke merge audits the source attachment metadata inserts'
    );
    assert_same(
        (int)smoke_scalar(
            $attachment_metadata,
            "SELECT COUNT(*) FROM merge_decisions WHERE table_name = '__files__' AND decision = 'source-applied' AND row_identity IN ('" .
            SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/2026/05/source-featured.jpg')) . "', '" .
            SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/2026/05/source-featured-150x150.jpg')) . "')"
        ),
        2,
        'page-plus-attachment smoke merge audits the source upload files'
    );
    assert_same(
        (int)smoke_scalar($attachment_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_postmeta', '__files__') AND decision = 'target-kept' AND reason = 'target inserted row and source did not have it'"),
        5,
        'page-plus-attachment smoke merge audits target DB graph inserts'
    );
    assert_same(
        (int)smoke_scalar($attachment_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = '__files__' AND decision = 'target-kept'"),
        2,
        'page-plus-attachment smoke merge audits target upload files'
    );

    $options_base = $tmp . '/options-base.sqlite';
    $options_source = $tmp . '/options-source.sqlite';
    $options_target = $tmp . '/options-target.sqlite';
    $options_metadata = $tmp . '/.forkpress/cow/merge/options-metadata.sqlite';

    smoke_create_posts_db($options_base);
    copy($options_base, $options_source);
    copy($options_base, $options_target);

    $source_option_json = json_encode(['branch' => 'source', 'post_id' => 18000030], JSON_UNESCAPED_SLASHES);
    $source_option_serialized = serialize(['branch' => 'source', 'post_id' => 18000030]);
    $target_option_json = json_encode(['branch' => 'target', 'post_id' => 19000030], JSON_UNESCAPED_SLASHES);
    $target_option_serialized = serialize(['branch' => 'target', 'post_id' => 19000030]);

    $db = smoke_open_db($options_source);
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (18000030, 'Branch Page With Options', 'Branch option content', 'publish', 'page', 'branch-page-with-options')");
    smoke_insert_option($db, 18000031, 'forkpress_source_page_json', $source_option_json);
    smoke_insert_option($db, 18000032, 'forkpress_source_page_serialized', $source_option_serialized);
    $db->close();

    $db = smoke_open_db($options_target);
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (19000030, 'Main Page With Options', 'Main option content', 'publish', 'page', 'main-page-with-options')");
    smoke_insert_option($db, 19000031, 'forkpress_target_page_json', $target_option_json);
    smoke_insert_option($db, 19000032, 'forkpress_target_page_serialized', $target_option_serialized);
    $db->close();

    $options_result = cow_merge_databases($options_base, $options_source, $options_target, $options_metadata, 'feature-smoke-page-options', 'main');
    assert_same($options_result['status'], 'completed', 'branch and main page-plus-options inserts complete cleanly');
    assert_same((int)($options_result['conflicts'] ?? -1), 0, 'branch and main page-plus-options inserts do not create merge conflicts');
    assert_same(smoke_scalar($options_target, 'SELECT post_title FROM wp_posts WHERE ID = 18000030'), 'Branch Page With Options', 'merged target includes the branch options page');
    assert_same(smoke_scalar($options_target, 'SELECT post_title FROM wp_posts WHERE ID = 19000030'), 'Main Page With Options', 'merged target preserves the main options page');
    assert_same(smoke_scalar($options_target, "SELECT option_value FROM wp_options WHERE option_name = 'forkpress_source_page_json'"), $source_option_json, 'merged target includes branch JSON option with its source post ID reference');
    assert_same(smoke_scalar($options_target, "SELECT option_value FROM wp_options WHERE option_name = 'forkpress_source_page_serialized'"), $source_option_serialized, 'merged target includes branch serialized option with its source post ID reference');
    assert_same(smoke_scalar($options_target, "SELECT option_value FROM wp_options WHERE option_name = 'forkpress_target_page_json'"), $target_option_json, 'merged target preserves target JSON option with its target post ID reference');
    assert_same(smoke_scalar($options_target, "SELECT option_value FROM wp_options WHERE option_name = 'forkpress_target_page_serialized'"), $target_option_serialized, 'merged target preserves target serialized option with its target post ID reference');
    assert_same(
        (int)smoke_scalar($options_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name IN ('wp_posts', 'wp_options')"),
        0,
        'page-plus-options smoke merge records no WordPress row conflicts'
    );
    assert_same(
        (int)smoke_scalar($options_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_options') AND decision = 'source-applied'"),
        3,
        'page-plus-options smoke merge audits all source graph inserts'
    );
    assert_same(
        (int)smoke_scalar($options_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_options') AND decision = 'target-kept' AND reason = 'target inserted row and source did not have it'"),
        3,
        'page-plus-options smoke merge audits all target graph inserts'
    );
} finally {
    smoke_remove_tree($tmp);
}

if ($fail > 0) {
    echo "COW merge smoke tests failed ($fail failures, $pass passes).\n";
    exit(1);
}

echo "COW merge smoke tests passed ($pass assertions).\n";

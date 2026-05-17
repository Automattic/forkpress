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

function smoke_run_merge_cli(array $args): array {
    $script = dirname(__DIR__, 2) . '/scripts/cow/merge.php';
    $command = array_map('escapeshellarg', array_merge([PHP_BINARY, $script], $args));
    exec(implode(' ', $command) . ' 2>&1', $output, $status);
    return [
        'status' => $status,
        'output' => implode("\n", $output) . ($output === [] ? '' : "\n"),
    ];
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

function smoke_insert_termmeta(SQLite3 $db, int $id, int $term_id, string $key, string $value): void {
    $stmt = $db->prepare('INSERT INTO wp_termmeta (meta_id, term_id, meta_key, meta_value) VALUES (:id, :term_id, :key, :value)');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':term_id', $term_id, SQLITE3_INTEGER);
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':value', $value, SQLITE3_TEXT);
    $stmt->execute();
}

function smoke_insert_user(SQLite3 $db, int $id, string $login, string $email, string $display_name): void {
    $stmt = $db->prepare('INSERT INTO wp_users (ID, user_login, user_email, display_name) VALUES (:id, :login, :email, :display_name)');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':login', $login, SQLITE3_TEXT);
    $stmt->bindValue(':email', $email, SQLITE3_TEXT);
    $stmt->bindValue(':display_name', $display_name, SQLITE3_TEXT);
    $stmt->execute();
}

function smoke_insert_usermeta(SQLite3 $db, int $id, int $user_id, string $key, string $value): void {
    $stmt = $db->prepare('INSERT INTO wp_usermeta (umeta_id, user_id, meta_key, meta_value) VALUES (:id, :user_id, :key, :value)');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':value', $value, SQLITE3_TEXT);
    $stmt->execute();
}

function smoke_insert_comment(SQLite3 $db, int $id, int $post_id, int $user_id, string $author, string $content, int $parent = 0): void {
    $stmt = $db->prepare('INSERT INTO wp_comments (comment_ID, comment_post_ID, user_id, comment_author, comment_content, comment_parent) VALUES (:id, :post_id, :user_id, :author, :content, :parent)');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':post_id', $post_id, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(':author', $author, SQLITE3_TEXT);
    $stmt->bindValue(':content', $content, SQLITE3_TEXT);
    $stmt->bindValue(':parent', $parent, SQLITE3_INTEGER);
    $stmt->execute();
}

function smoke_insert_commentmeta(SQLite3 $db, int $id, int $comment_id, string $key, string $value): void {
    $stmt = $db->prepare('INSERT INTO wp_commentmeta (meta_id, comment_id, meta_key, meta_value) VALUES (:id, :comment_id, :key, :value)');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':comment_id', $comment_id, SQLITE3_INTEGER);
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
    string $guid = '',
    int $author = 0
): void {
    $stmt = $db->prepare('INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name, post_parent, post_mime_type, guid, post_author) VALUES (:id, :title, :content, :status, :type, :name, :parent, :mime_type, :guid, :author)');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
    $stmt->bindValue(':content', $content, SQLITE3_TEXT);
    $stmt->bindValue(':status', $status, SQLITE3_TEXT);
    $stmt->bindValue(':type', $type, SQLITE3_TEXT);
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':parent', $parent, SQLITE3_INTEGER);
    $stmt->bindValue(':mime_type', $mime_type, SQLITE3_TEXT);
    $stmt->bindValue(':guid', $guid, SQLITE3_TEXT);
    $stmt->bindValue(':author', $author, SQLITE3_INTEGER);
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
        post_author INTEGER NOT NULL DEFAULT 0,
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
    $db->exec("CREATE TABLE wp_termmeta (
        meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
        term_id INTEGER NOT NULL,
        meta_key TEXT NOT NULL,
        meta_value TEXT NOT NULL
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
    $db->exec("CREATE TABLE wp_users (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        user_login TEXT NOT NULL DEFAULT '',
        user_email TEXT NOT NULL DEFAULT '',
        display_name TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("CREATE TABLE wp_usermeta (
        umeta_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        meta_key TEXT NOT NULL,
        meta_value TEXT NOT NULL
    )");
    $db->exec("CREATE TABLE wp_comments (
        comment_ID INTEGER PRIMARY KEY AUTOINCREMENT,
        comment_post_ID INTEGER NOT NULL,
        user_id INTEGER NOT NULL DEFAULT 0,
        comment_author TEXT NOT NULL DEFAULT '',
        comment_content TEXT NOT NULL DEFAULT '',
        comment_parent INTEGER NOT NULL DEFAULT 0
    )");
    $db->exec("CREATE TABLE wp_commentmeta (
        meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
        comment_id INTEGER NOT NULL,
        meta_key TEXT NOT NULL,
        meta_value TEXT NOT NULL
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

    $child_page_base = $tmp . '/child-page-base.sqlite';
    $child_page_source = $tmp . '/child-page-source.sqlite';
    $child_page_target = $tmp . '/child-page-target.sqlite';
    $child_page_metadata = $tmp . '/.forkpress/cow/merge/child-page-metadata.sqlite';

    smoke_create_posts_db($child_page_base);
    copy($child_page_base, $child_page_source);
    copy($child_page_base, $child_page_target);

    $db = smoke_open_db($child_page_source);
    smoke_insert_post($db, 18000040, 'Branch Parent Page', 'Branch parent content', 'page', 'branch-parent-page');
    smoke_insert_post($db, 18000041, 'Branch Child Page', 'Branch child content', 'page', 'branch-child-page', 'publish', 18000040);
    $db->close();

    $db = smoke_open_db($child_page_target);
    smoke_insert_post($db, 19000040, 'Main Parent Page', 'Main parent content', 'page', 'main-parent-page');
    smoke_insert_post($db, 19000041, 'Main Child Page', 'Main child content', 'page', 'main-child-page', 'publish', 19000040);
    $db->close();

    $child_page_result = cow_merge_databases($child_page_base, $child_page_source, $child_page_target, $child_page_metadata, 'feature-smoke-page-child', 'main');
    assert_same($child_page_result['status'], 'completed', 'branch and main parent/child page inserts complete cleanly');
    assert_same((int)($child_page_result['conflicts'] ?? -1), 0, 'branch and main parent/child page inserts do not create merge conflicts');
    assert_same(smoke_scalar($child_page_target, 'SELECT post_title FROM wp_posts WHERE ID = 18000040'), 'Branch Parent Page', 'merged target includes the branch parent page');
    assert_same(smoke_scalar($child_page_target, 'SELECT post_title FROM wp_posts WHERE ID = 18000041'), 'Branch Child Page', 'merged target includes the branch child page');
    assert_same((int)smoke_scalar($child_page_target, 'SELECT post_parent FROM wp_posts WHERE ID = 18000041'), 18000040, 'merged target keeps the branch child page parent reference');
    assert_same(smoke_scalar($child_page_target, 'SELECT post_title FROM wp_posts WHERE ID = 19000040'), 'Main Parent Page', 'merged target preserves the main parent page');
    assert_same(smoke_scalar($child_page_target, 'SELECT post_title FROM wp_posts WHERE ID = 19000041'), 'Main Child Page', 'merged target preserves the main child page');
    assert_same((int)smoke_scalar($child_page_target, 'SELECT post_parent FROM wp_posts WHERE ID = 19000041'), 19000040, 'merged target keeps the main child page parent reference');
    assert_same(
        (int)smoke_scalar($child_page_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_posts'"),
        0,
        'page-plus-child-page smoke merge records no wp_posts conflicts'
    );
    assert_same(
        (int)smoke_scalar($child_page_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'source-applied'"),
        2,
        'page-plus-child-page smoke merge audits the source parent and child page inserts'
    );
    assert_same(
        (int)smoke_scalar($child_page_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'target-kept' AND reason = 'target inserted row and source did not have it'"),
        2,
        'page-plus-child-page smoke merge audits the target parent and child page inserts'
    );

    $child_page_edit_delete_base = $tmp . '/child-page-edit-delete-base.sqlite';
    $child_page_edit_delete_source = $tmp . '/child-page-edit-delete-source.sqlite';
    $child_page_edit_delete_target = $tmp . '/child-page-edit-delete-target.sqlite';
    $child_page_edit_delete_metadata = $tmp . '/.forkpress/cow/merge/child-page-edit-delete-metadata.sqlite';

    smoke_create_posts_db($child_page_edit_delete_base);
    $db = smoke_open_db($child_page_edit_delete_base);
    smoke_insert_post($db, 17000150, 'Base Parent Page', 'Base parent content', 'page', 'base-parent-page');
    smoke_insert_post($db, 17000151, 'Base Child Page', 'Base child content', 'page', 'base-child-page', 'publish', 17000150);
    $db->close();
    copy($child_page_edit_delete_base, $child_page_edit_delete_source);
    copy($child_page_edit_delete_base, $child_page_edit_delete_target);

    $db = smoke_open_db($child_page_edit_delete_source);
    $db->exec("UPDATE wp_posts SET post_title = 'Source Edited Child Page', post_content = 'Source edited child content' WHERE ID = 17000151");
    $db->close();

    $db = smoke_open_db($child_page_edit_delete_target);
    $db->exec('DELETE FROM wp_posts WHERE ID = 17000151');
    $db->close();

    $child_page_edit_delete_result = cow_merge_databases($child_page_edit_delete_base, $child_page_edit_delete_source, $child_page_edit_delete_target, $child_page_edit_delete_metadata, 'feature-smoke-page-child-edit-delete', 'main');
    assert_same($child_page_edit_delete_result['status'], 'completed_with_conflicts', 'child page edit/delete graph stays reviewable');
    assert_same((int)smoke_scalar($child_page_edit_delete_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 17000151'), 0, 'child page edit/delete preserves target child deletion before review');
    assert_same(smoke_scalar($child_page_edit_delete_target, 'SELECT post_title FROM wp_posts WHERE ID = 17000150'), 'Base Parent Page', 'child page edit/delete preserves the unchanged parent page');
    assert_same(
        (int)smoke_scalar($child_page_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_posts' AND conflict_type = 'row-target-deleted'"),
        1,
        'child page edit/delete records the edited child delete conflict'
    );
    assert_same(
        (int)smoke_scalar($child_page_edit_delete_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'target-wins'"),
        1,
        'child page edit/delete defaults the changed source child to target-wins before review'
    );

    $revision_base = $tmp . '/revision-base.sqlite';
    $revision_source = $tmp . '/revision-source.sqlite';
    $revision_target = $tmp . '/revision-target.sqlite';
    $revision_metadata = $tmp . '/.forkpress/cow/merge/revision-metadata.sqlite';

    smoke_create_posts_db($revision_base);
    copy($revision_base, $revision_source);
    copy($revision_base, $revision_target);

    $db = smoke_open_db($revision_source);
    smoke_insert_post($db, 18000042, 'Branch Revised Page', 'Branch revised page content', 'page', 'branch-revised-page');
    smoke_insert_post($db, 18000043, 'Branch Revised Page Revision', 'Branch revision content', 'revision', '18000042-revision-v1', 'inherit', 18000042);
    $db->close();

    $db = smoke_open_db($revision_target);
    smoke_insert_post($db, 19000042, 'Main Revised Page', 'Main revised page content', 'page', 'main-revised-page');
    smoke_insert_post($db, 19000043, 'Main Revised Page Revision', 'Main revision content', 'revision', '19000042-revision-v1', 'inherit', 19000042);
    $db->close();

    $revision_result = cow_merge_databases($revision_base, $revision_source, $revision_target, $revision_metadata, 'feature-smoke-page-revision', 'main');
    assert_same($revision_result['status'], 'completed', 'branch and main page revision inserts complete cleanly');
    assert_same((int)($revision_result['conflicts'] ?? -1), 0, 'branch and main page revision inserts do not create merge conflicts');
    assert_same(smoke_scalar($revision_target, 'SELECT post_title FROM wp_posts WHERE ID = 18000042'), 'Branch Revised Page', 'merged target includes the branch revised page');
    assert_same(smoke_scalar($revision_target, 'SELECT post_type FROM wp_posts WHERE ID = 18000043'), 'revision', 'merged target includes the branch revision row');
    assert_same((int)smoke_scalar($revision_target, 'SELECT post_parent FROM wp_posts WHERE ID = 18000043'), 18000042, 'merged target keeps the branch revision parent page reference');
    assert_same(smoke_scalar($revision_target, 'SELECT post_title FROM wp_posts WHERE ID = 19000042'), 'Main Revised Page', 'merged target preserves the main revised page');
    assert_same(smoke_scalar($revision_target, 'SELECT post_type FROM wp_posts WHERE ID = 19000043'), 'revision', 'merged target preserves the main revision row');
    assert_same((int)smoke_scalar($revision_target, 'SELECT post_parent FROM wp_posts WHERE ID = 19000043'), 19000042, 'merged target keeps the main revision parent page reference');
    assert_same(
        (int)smoke_scalar($revision_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_posts'"),
        0,
        'page-plus-revision smoke merge records no wp_posts conflicts'
    );
    assert_same(
        (int)smoke_scalar($revision_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'source-applied'"),
        2,
        'page-plus-revision smoke merge audits the source page and revision inserts'
    );
    assert_same(
        (int)smoke_scalar($revision_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'target-kept' AND reason = 'target inserted row and source did not have it'"),
        2,
        'page-plus-revision smoke merge audits the target page and revision inserts'
    );

    $revision_edit_delete_base = $tmp . '/revision-edit-delete-base.sqlite';
    $revision_edit_delete_source = $tmp . '/revision-edit-delete-source.sqlite';
    $revision_edit_delete_target = $tmp . '/revision-edit-delete-target.sqlite';
    $revision_edit_delete_metadata = $tmp . '/.forkpress/cow/merge/revision-edit-delete-metadata.sqlite';

    smoke_create_posts_db($revision_edit_delete_base);
    $db = smoke_open_db($revision_edit_delete_base);
    smoke_insert_post($db, 17000152, 'Base Revised Page', 'Base revised page content', 'page', 'base-revised-page');
    smoke_insert_post($db, 17000153, 'Base Revised Page Revision', 'Base revision content', 'revision', '17000152-revision-v1', 'inherit', 17000152);
    $db->close();
    copy($revision_edit_delete_base, $revision_edit_delete_source);
    copy($revision_edit_delete_base, $revision_edit_delete_target);

    $db = smoke_open_db($revision_edit_delete_source);
    $db->exec("UPDATE wp_posts SET post_title = 'Source Edited Page Revision', post_content = 'Source edited revision content' WHERE ID = 17000153");
    $db->close();

    $db = smoke_open_db($revision_edit_delete_target);
    $db->exec('DELETE FROM wp_posts WHERE ID = 17000153');
    $db->close();

    $revision_edit_delete_result = cow_merge_databases($revision_edit_delete_base, $revision_edit_delete_source, $revision_edit_delete_target, $revision_edit_delete_metadata, 'feature-smoke-page-revision-edit-delete', 'main');
    assert_same($revision_edit_delete_result['status'], 'completed_with_conflicts', 'revision edit/delete graph stays reviewable');
    assert_same((int)smoke_scalar($revision_edit_delete_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 17000153'), 0, 'revision edit/delete preserves target revision deletion before review');
    assert_same(smoke_scalar($revision_edit_delete_target, 'SELECT post_title FROM wp_posts WHERE ID = 17000152'), 'Base Revised Page', 'revision edit/delete preserves the unchanged parent page');
    assert_same(
        (int)smoke_scalar($revision_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_posts' AND conflict_type = 'row-target-deleted'"),
        1,
        'revision edit/delete records the edited revision delete conflict'
    );
    assert_same(
        (int)smoke_scalar($revision_edit_delete_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'target-wins'"),
        1,
        'revision edit/delete defaults the changed source revision to target-wins before review'
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

    $postmeta_edit_delete_base = $tmp . '/postmeta-edit-delete-base.sqlite';
    $postmeta_edit_delete_source = $tmp . '/postmeta-edit-delete-source.sqlite';
    $postmeta_edit_delete_target = $tmp . '/postmeta-edit-delete-target.sqlite';
    $postmeta_edit_delete_metadata = $tmp . '/.forkpress/cow/merge/postmeta-edit-delete-metadata.sqlite';

    smoke_create_posts_db($postmeta_edit_delete_base);
    copy($postmeta_edit_delete_base, $postmeta_edit_delete_source);
    copy($postmeta_edit_delete_base, $postmeta_edit_delete_target);

    $db = smoke_open_db($postmeta_edit_delete_source);
    $db->exec("UPDATE wp_posts SET post_title = 'Source Edited Base Page', post_content = 'Source edited base content' WHERE ID = 1");
    $db->exec("UPDATE wp_postmeta SET meta_value = '{\"branch\":\"source\",\"post_id\":1,\"edited\":true}' WHERE meta_id = 2");
    $db->close();

    $db = smoke_open_db($postmeta_edit_delete_target);
    $db->exec('DELETE FROM wp_postmeta WHERE post_id = 1');
    $db->exec('DELETE FROM wp_posts WHERE ID = 1');
    $db->close();

    $postmeta_edit_delete_result = cow_merge_databases($postmeta_edit_delete_base, $postmeta_edit_delete_source, $postmeta_edit_delete_target, $postmeta_edit_delete_metadata, 'feature-smoke-page-postmeta-edit-delete', 'main');
    assert_same($postmeta_edit_delete_result['status'], 'completed_with_conflicts', 'page/postmeta edit/delete graph stays reviewable');
    assert_same((int)smoke_scalar($postmeta_edit_delete_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 1'), 0, 'page/postmeta edit/delete preserves target page deletion before review');
    assert_same((int)smoke_scalar($postmeta_edit_delete_target, 'SELECT COUNT(*) FROM wp_postmeta WHERE post_id = 1'), 0, 'page/postmeta edit/delete preserves target metadata deletion before review');
    assert_same(
        (int)smoke_scalar($postmeta_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_posts' AND conflict_type = 'row-target-deleted'"),
        1,
        'page/postmeta edit/delete records the edited page delete conflict'
    );
    assert_same(
        (int)smoke_scalar($postmeta_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_postmeta' AND conflict_type = 'row-target-deleted'"),
        1,
        'page/postmeta edit/delete records the edited metadata delete conflict'
    );
    assert_same(
        (int)smoke_scalar($postmeta_edit_delete_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_postmeta') AND decision = 'target-wins'"),
        2,
        'page/postmeta edit/delete defaults the changed source graph to target-wins before review'
    );

    $comment_base = $tmp . '/comment-base.sqlite';
    $comment_source = $tmp . '/comment-source.sqlite';
    $comment_target = $tmp . '/comment-target.sqlite';
    $comment_metadata = $tmp . '/.forkpress/cow/merge/comment-metadata.sqlite';

    smoke_create_posts_db($comment_base);
    copy($comment_base, $comment_source);
    copy($comment_base, $comment_target);

    $db = smoke_open_db($comment_source);
    smoke_insert_post($db, 18000070, 'Branch Page With Comment', 'Branch comment content', 'page', 'branch-page-with-comment');
    smoke_insert_user($db, 18000071, 'branch-commenter', 'branch-commenter@example.test', 'Branch Commenter');
    smoke_insert_usermeta($db, 18000072, 18000071, 'forkpress_smoke_profile', '{"branch":"source","user_id":18000071}');
    smoke_insert_comment($db, 18000073, 18000070, 18000071, 'Branch Commenter', 'Branch comment body');
    smoke_insert_commentmeta($db, 18000074, 18000073, 'forkpress_smoke_comment_ref', '{"branch":"source","comment_id":18000073,"user_id":18000071,"post_id":18000070}');
    $db->close();

    $db = smoke_open_db($comment_target);
    smoke_insert_post($db, 19000070, 'Main Page With Comment', 'Main comment content', 'page', 'main-page-with-comment');
    smoke_insert_user($db, 19000071, 'main-commenter', 'main-commenter@example.test', 'Main Commenter');
    smoke_insert_usermeta($db, 19000072, 19000071, 'forkpress_smoke_profile', '{"branch":"target","user_id":19000071}');
    smoke_insert_comment($db, 19000073, 19000070, 19000071, 'Main Commenter', 'Main comment body');
    smoke_insert_commentmeta($db, 19000074, 19000073, 'forkpress_smoke_comment_ref', '{"branch":"target","comment_id":19000073,"user_id":19000071,"post_id":19000070}');
    $db->close();

    $comment_result = cow_merge_databases($comment_base, $comment_source, $comment_target, $comment_metadata, 'feature-smoke-page-comment', 'main');
    assert_same($comment_result['status'], 'completed', 'branch and main page-plus-comment inserts complete cleanly');
    assert_same((int)($comment_result['conflicts'] ?? -1), 0, 'branch and main page-plus-comment inserts do not create merge conflicts');
    assert_same(smoke_scalar($comment_target, 'SELECT post_title FROM wp_posts WHERE ID = 18000070'), 'Branch Page With Comment', 'merged target includes the branch comment page');
    assert_same(smoke_scalar($comment_target, 'SELECT user_login FROM wp_users WHERE ID = 18000071'), 'branch-commenter', 'merged target includes the branch comment author');
    assert_same(smoke_scalar($comment_target, 'SELECT meta_value FROM wp_usermeta WHERE umeta_id = 18000072'), '{"branch":"source","user_id":18000071}', 'merged target includes branch user metadata with its source user ID reference');
    assert_same(smoke_scalar($comment_target, 'SELECT comment_content FROM wp_comments WHERE comment_ID = 18000073'), 'Branch comment body', 'merged target includes the branch comment');
    assert_same((int)smoke_scalar($comment_target, 'SELECT comment_post_ID FROM wp_comments WHERE comment_ID = 18000073'), 18000070, 'merged target keeps the branch comment page reference');
    assert_same((int)smoke_scalar($comment_target, 'SELECT user_id FROM wp_comments WHERE comment_ID = 18000073'), 18000071, 'merged target keeps the branch comment author reference');
    assert_same(smoke_scalar($comment_target, 'SELECT meta_value FROM wp_commentmeta WHERE meta_id = 18000074'), '{"branch":"source","comment_id":18000073,"user_id":18000071,"post_id":18000070}', 'merged target includes branch comment metadata with source graph references');
    assert_same(smoke_scalar($comment_target, 'SELECT post_title FROM wp_posts WHERE ID = 19000070'), 'Main Page With Comment', 'merged target preserves the main comment page');
    assert_same(smoke_scalar($comment_target, 'SELECT user_login FROM wp_users WHERE ID = 19000071'), 'main-commenter', 'merged target preserves the main comment author');
    assert_same(smoke_scalar($comment_target, 'SELECT comment_content FROM wp_comments WHERE comment_ID = 19000073'), 'Main comment body', 'merged target preserves the main comment');
    assert_same(smoke_scalar($comment_target, 'SELECT meta_value FROM wp_commentmeta WHERE meta_id = 19000074'), '{"branch":"target","comment_id":19000073,"user_id":19000071,"post_id":19000070}', 'merged target preserves target comment metadata with target graph references');
    assert_same(
        (int)smoke_scalar($comment_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name IN ('wp_posts', 'wp_users', 'wp_usermeta', 'wp_comments', 'wp_commentmeta')"),
        0,
        'page-plus-comment smoke merge records no WordPress graph conflicts'
    );
    assert_same(
        (int)smoke_scalar($comment_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_users', 'wp_usermeta', 'wp_comments', 'wp_commentmeta') AND decision = 'source-applied'"),
        5,
        'page-plus-comment smoke merge audits all source graph inserts'
    );
    assert_same(
        (int)smoke_scalar($comment_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_users', 'wp_usermeta', 'wp_comments', 'wp_commentmeta') AND decision = 'target-kept' AND reason = 'target inserted row and source did not have it'"),
        5,
        'page-plus-comment smoke merge audits all target graph inserts'
    );

    $threaded_comment_base = $tmp . '/threaded-comment-base.sqlite';
    $threaded_comment_source = $tmp . '/threaded-comment-source.sqlite';
    $threaded_comment_target = $tmp . '/threaded-comment-target.sqlite';
    $threaded_comment_metadata = $tmp . '/.forkpress/cow/merge/threaded-comment-metadata.sqlite';

    smoke_create_posts_db($threaded_comment_base);
    copy($threaded_comment_base, $threaded_comment_source);
    copy($threaded_comment_base, $threaded_comment_target);

    $db = smoke_open_db($threaded_comment_source);
    smoke_insert_post($db, 18000075, 'Branch Page With Threaded Comments', 'Branch threaded comment content', 'page', 'branch-page-with-threaded-comments');
    smoke_insert_user($db, 18000076, 'branch-thread-commenter', 'branch-thread-commenter@example.test', 'Branch Thread Commenter');
    smoke_insert_comment($db, 18000077, 18000075, 18000076, 'Branch Thread Commenter', 'Branch parent comment body');
    smoke_insert_comment($db, 18000078, 18000075, 18000076, 'Branch Thread Commenter', 'Branch reply comment body', 18000077);
    smoke_insert_commentmeta($db, 18000079, 18000078, 'forkpress_smoke_reply_ref', '{"branch":"source","comment_id":18000078,"parent_comment_id":18000077,"post_id":18000075}');
    $db->close();

    $db = smoke_open_db($threaded_comment_target);
    smoke_insert_post($db, 19000075, 'Main Page With Threaded Comments', 'Main threaded comment content', 'page', 'main-page-with-threaded-comments');
    smoke_insert_user($db, 19000076, 'main-thread-commenter', 'main-thread-commenter@example.test', 'Main Thread Commenter');
    smoke_insert_comment($db, 19000077, 19000075, 19000076, 'Main Thread Commenter', 'Main parent comment body');
    smoke_insert_comment($db, 19000078, 19000075, 19000076, 'Main Thread Commenter', 'Main reply comment body', 19000077);
    smoke_insert_commentmeta($db, 19000079, 19000078, 'forkpress_smoke_reply_ref', '{"branch":"target","comment_id":19000078,"parent_comment_id":19000077,"post_id":19000075}');
    $db->close();

    $threaded_comment_result = cow_merge_databases($threaded_comment_base, $threaded_comment_source, $threaded_comment_target, $threaded_comment_metadata, 'feature-smoke-page-threaded-comment', 'main');
    assert_same($threaded_comment_result['status'], 'completed', 'branch and main page-plus-threaded-comment inserts complete cleanly');
    assert_same((int)($threaded_comment_result['conflicts'] ?? -1), 0, 'branch and main page-plus-threaded-comment inserts do not create merge conflicts');
    assert_same(smoke_scalar($threaded_comment_target, 'SELECT post_title FROM wp_posts WHERE ID = 18000075'), 'Branch Page With Threaded Comments', 'merged target includes the branch threaded-comment page');
    assert_same(smoke_scalar($threaded_comment_target, 'SELECT comment_content FROM wp_comments WHERE comment_ID = 18000077'), 'Branch parent comment body', 'merged target includes the branch parent comment');
    assert_same(smoke_scalar($threaded_comment_target, 'SELECT comment_content FROM wp_comments WHERE comment_ID = 18000078'), 'Branch reply comment body', 'merged target includes the branch reply comment');
    assert_same((int)smoke_scalar($threaded_comment_target, 'SELECT comment_parent FROM wp_comments WHERE comment_ID = 18000078'), 18000077, 'merged target keeps the branch reply parent comment reference');
    assert_same(smoke_scalar($threaded_comment_target, 'SELECT meta_value FROM wp_commentmeta WHERE meta_id = 18000079'), '{"branch":"source","comment_id":18000078,"parent_comment_id":18000077,"post_id":18000075}', 'merged target includes branch reply metadata with source graph references');
    assert_same(smoke_scalar($threaded_comment_target, 'SELECT post_title FROM wp_posts WHERE ID = 19000075'), 'Main Page With Threaded Comments', 'merged target preserves the main threaded-comment page');
    assert_same(smoke_scalar($threaded_comment_target, 'SELECT comment_content FROM wp_comments WHERE comment_ID = 19000077'), 'Main parent comment body', 'merged target preserves the main parent comment');
    assert_same(smoke_scalar($threaded_comment_target, 'SELECT comment_content FROM wp_comments WHERE comment_ID = 19000078'), 'Main reply comment body', 'merged target preserves the main reply comment');
    assert_same((int)smoke_scalar($threaded_comment_target, 'SELECT comment_parent FROM wp_comments WHERE comment_ID = 19000078'), 19000077, 'merged target keeps the main reply parent comment reference');
    assert_same(smoke_scalar($threaded_comment_target, 'SELECT meta_value FROM wp_commentmeta WHERE meta_id = 19000079'), '{"branch":"target","comment_id":19000078,"parent_comment_id":19000077,"post_id":19000075}', 'merged target preserves target reply metadata with target graph references');
    assert_same(
        (int)smoke_scalar($threaded_comment_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name IN ('wp_posts', 'wp_users', 'wp_comments', 'wp_commentmeta')"),
        0,
        'page-plus-threaded-comment smoke merge records no WordPress graph conflicts'
    );
    assert_same(
        (int)smoke_scalar($threaded_comment_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_users', 'wp_comments', 'wp_commentmeta') AND decision = 'source-applied'"),
        5,
        'page-plus-threaded-comment smoke merge audits all source graph inserts'
    );
    assert_same(
        (int)smoke_scalar($threaded_comment_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_users', 'wp_comments', 'wp_commentmeta') AND decision = 'target-kept' AND reason = 'target inserted row and source did not have it'"),
        5,
        'page-plus-threaded-comment smoke merge audits all target graph inserts'
    );

    $threaded_comment_edit_delete_base = $tmp . '/threaded-comment-edit-delete-base.sqlite';
    $threaded_comment_edit_delete_source = $tmp . '/threaded-comment-edit-delete-source.sqlite';
    $threaded_comment_edit_delete_target = $tmp . '/threaded-comment-edit-delete-target.sqlite';
    $threaded_comment_edit_delete_metadata = $tmp . '/.forkpress/cow/merge/threaded-comment-edit-delete-metadata.sqlite';

    smoke_create_posts_db($threaded_comment_edit_delete_base);
    $db = smoke_open_db($threaded_comment_edit_delete_base);
    smoke_insert_user($db, 17000084, 'shared-thread-commenter', 'shared-thread-commenter@example.test', 'Shared Thread Commenter');
    smoke_insert_comment($db, 17000085, 1, 17000084, 'Shared Thread Commenter', 'Base parent thread comment body');
    smoke_insert_comment($db, 17000086, 1, 17000084, 'Shared Thread Commenter', 'Base reply thread comment body', 17000085);
    smoke_insert_commentmeta($db, 17000087, 17000086, 'forkpress_smoke_reply_ref', '{"branch":"base","comment_id":17000086,"parent_comment_id":17000085,"post_id":1}');
    $db->close();
    copy($threaded_comment_edit_delete_base, $threaded_comment_edit_delete_source);
    copy($threaded_comment_edit_delete_base, $threaded_comment_edit_delete_target);

    $db = smoke_open_db($threaded_comment_edit_delete_source);
    $db->exec("UPDATE wp_comments SET comment_content = 'Source edited reply thread comment body' WHERE comment_ID = 17000086");
    $db->exec("UPDATE wp_commentmeta SET meta_value = '{\"branch\":\"source\",\"comment_id\":17000086,\"parent_comment_id\":17000085,\"post_id\":1,\"edited\":true}' WHERE meta_id = 17000087");
    $db->close();

    $db = smoke_open_db($threaded_comment_edit_delete_target);
    $db->exec('DELETE FROM wp_commentmeta WHERE comment_id = 17000086');
    $db->exec('DELETE FROM wp_comments WHERE comment_ID = 17000086');
    $db->close();

    $threaded_comment_edit_delete_result = cow_merge_databases($threaded_comment_edit_delete_base, $threaded_comment_edit_delete_source, $threaded_comment_edit_delete_target, $threaded_comment_edit_delete_metadata, 'feature-smoke-threaded-comment-edit-delete', 'main');
    assert_same($threaded_comment_edit_delete_result['status'], 'completed_with_conflicts', 'threaded comment reply edit/delete graph stays reviewable');
    assert_same((int)smoke_scalar($threaded_comment_edit_delete_target, 'SELECT COUNT(*) FROM wp_comments WHERE comment_ID = 17000086'), 0, 'threaded comment edit/delete preserves target reply deletion before review');
    assert_same((int)smoke_scalar($threaded_comment_edit_delete_target, 'SELECT COUNT(*) FROM wp_commentmeta WHERE comment_id = 17000086'), 0, 'threaded comment edit/delete preserves target reply metadata deletion before review');
    assert_same(smoke_scalar($threaded_comment_edit_delete_target, 'SELECT comment_content FROM wp_comments WHERE comment_ID = 17000085'), 'Base parent thread comment body', 'threaded comment edit/delete preserves the unchanged parent comment');
    assert_same(
        (int)smoke_scalar($threaded_comment_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_comments' AND conflict_type = 'row-target-deleted'"),
        1,
        'threaded comment edit/delete records the edited reply delete conflict'
    );
    assert_same(
        (int)smoke_scalar($threaded_comment_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_commentmeta' AND conflict_type = 'row-target-deleted'"),
        1,
        'threaded comment edit/delete records the edited reply metadata delete conflict'
    );
    assert_same(
        (int)smoke_scalar($threaded_comment_edit_delete_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_comments', 'wp_commentmeta') AND decision = 'target-wins'"),
        2,
        'threaded comment edit/delete defaults the changed source reply graph to target-wins before review'
    );

    $authored_page_base = $tmp . '/authored-page-base.sqlite';
    $authored_page_source = $tmp . '/authored-page-source.sqlite';
    $authored_page_target = $tmp . '/authored-page-target.sqlite';
    $authored_page_metadata = $tmp . '/.forkpress/cow/merge/authored-page-metadata.sqlite';

    smoke_create_posts_db($authored_page_base);
    copy($authored_page_base, $authored_page_source);
    copy($authored_page_base, $authored_page_target);

    $db = smoke_open_db($authored_page_source);
    smoke_insert_user($db, 18000088, 'branch-author', 'branch-author@example.test', 'Branch Author');
    smoke_insert_usermeta($db, 18000089, 18000088, 'forkpress_smoke_author_profile', '{"branch":"source","user_id":18000088}');
    smoke_insert_post($db, 18000090, 'Branch Authored Page', 'Branch authored page content', 'page', 'branch-authored-page', 'publish', 0, '', '', 18000088);
    smoke_insert_postmeta($db, 18000091, 18000090, '_forkpress_smoke_author_ref', '{"branch":"source","post_id":18000090,"author_id":18000088}');
    $db->close();

    $db = smoke_open_db($authored_page_target);
    smoke_insert_user($db, 19000088, 'main-author', 'main-author@example.test', 'Main Author');
    smoke_insert_usermeta($db, 19000089, 19000088, 'forkpress_smoke_author_profile', '{"branch":"target","user_id":19000088}');
    smoke_insert_post($db, 19000090, 'Main Authored Page', 'Main authored page content', 'page', 'main-authored-page', 'publish', 0, '', '', 19000088);
    smoke_insert_postmeta($db, 19000091, 19000090, '_forkpress_smoke_author_ref', '{"branch":"target","post_id":19000090,"author_id":19000088}');
    $db->close();

    $authored_page_result = cow_merge_databases($authored_page_base, $authored_page_source, $authored_page_target, $authored_page_metadata, 'feature-smoke-page-author', 'main');
    assert_same($authored_page_result['status'], 'completed', 'branch and main page-plus-author inserts complete cleanly');
    assert_same((int)($authored_page_result['conflicts'] ?? -1), 0, 'branch and main page-plus-author inserts do not create merge conflicts');
    assert_same(smoke_scalar($authored_page_target, 'SELECT user_login FROM wp_users WHERE ID = 18000088'), 'branch-author', 'merged target includes the branch page author');
    assert_same(smoke_scalar($authored_page_target, 'SELECT post_title FROM wp_posts WHERE ID = 18000090'), 'Branch Authored Page', 'merged target includes the branch authored page');
    assert_same((int)smoke_scalar($authored_page_target, 'SELECT post_author FROM wp_posts WHERE ID = 18000090'), 18000088, 'merged target keeps the branch authored page author reference');
    assert_same(smoke_scalar($authored_page_target, 'SELECT meta_value FROM wp_postmeta WHERE meta_id = 18000091'), '{"branch":"source","post_id":18000090,"author_id":18000088}', 'merged target includes branch authored-page metadata with source graph references');
    assert_same(smoke_scalar($authored_page_target, 'SELECT user_login FROM wp_users WHERE ID = 19000088'), 'main-author', 'merged target preserves the main page author');
    assert_same(smoke_scalar($authored_page_target, 'SELECT post_title FROM wp_posts WHERE ID = 19000090'), 'Main Authored Page', 'merged target preserves the main authored page');
    assert_same((int)smoke_scalar($authored_page_target, 'SELECT post_author FROM wp_posts WHERE ID = 19000090'), 19000088, 'merged target keeps the main authored page author reference');
    assert_same(smoke_scalar($authored_page_target, 'SELECT meta_value FROM wp_postmeta WHERE meta_id = 19000091'), '{"branch":"target","post_id":19000090,"author_id":19000088}', 'merged target preserves target authored-page metadata with target graph references');
    assert_same(
        (int)smoke_scalar($authored_page_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name IN ('wp_posts', 'wp_postmeta', 'wp_users', 'wp_usermeta')"),
        0,
        'page-plus-author smoke merge records no WordPress graph conflicts'
    );
    assert_same(
        (int)smoke_scalar($authored_page_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_postmeta', 'wp_users', 'wp_usermeta') AND decision = 'source-applied'"),
        4,
        'page-plus-author smoke merge audits all source graph inserts'
    );
    assert_same(
        (int)smoke_scalar($authored_page_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_postmeta', 'wp_users', 'wp_usermeta') AND decision = 'target-kept' AND reason = 'target inserted row and source did not have it'"),
        4,
        'page-plus-author smoke merge audits all target graph inserts'
    );

    $user_edit_delete_base = $tmp . '/user-edit-delete-base.sqlite';
    $user_edit_delete_source = $tmp . '/user-edit-delete-source.sqlite';
    $user_edit_delete_target = $tmp . '/user-edit-delete-target.sqlite';
    $user_edit_delete_metadata = $tmp . '/.forkpress/cow/merge/user-edit-delete-metadata.sqlite';

    smoke_create_posts_db($user_edit_delete_base);
    $db = smoke_open_db($user_edit_delete_base);
    smoke_insert_user($db, 17000076, 'shared-user', 'shared-user@example.test', 'Shared User');
    smoke_insert_usermeta($db, 17000077, 17000076, 'forkpress_smoke_user_graph', '{"branch":"base","user_id":17000076}');
    $db->close();
    copy($user_edit_delete_base, $user_edit_delete_source);
    copy($user_edit_delete_base, $user_edit_delete_target);

    $db = smoke_open_db($user_edit_delete_source);
    $db->exec("UPDATE wp_users SET user_email = 'source-edited-user@example.test', display_name = 'Source Edited User' WHERE ID = 17000076");
    $db->exec("UPDATE wp_usermeta SET meta_value = '{\"branch\":\"source\",\"user_id\":17000076,\"edited\":true}' WHERE umeta_id = 17000077");
    $db->close();

    $db = smoke_open_db($user_edit_delete_target);
    $db->exec('DELETE FROM wp_usermeta WHERE user_id = 17000076');
    $db->exec('DELETE FROM wp_users WHERE ID = 17000076');
    $db->close();

    $user_edit_delete_result = cow_merge_databases($user_edit_delete_base, $user_edit_delete_source, $user_edit_delete_target, $user_edit_delete_metadata, 'feature-smoke-user-edit-delete', 'main');
    assert_same($user_edit_delete_result['status'], 'completed_with_conflicts', 'user edit/delete graph stays reviewable');
    assert_same((int)smoke_scalar($user_edit_delete_target, 'SELECT COUNT(*) FROM wp_users WHERE ID = 17000076'), 0, 'user edit/delete preserves target user deletion before review');
    assert_same((int)smoke_scalar($user_edit_delete_target, 'SELECT COUNT(*) FROM wp_usermeta WHERE user_id = 17000076'), 0, 'user edit/delete preserves target user metadata deletion before review');
    assert_same(
        (int)smoke_scalar($user_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_users' AND conflict_type = 'row-target-deleted'"),
        1,
        'user edit/delete records the edited user delete conflict'
    );
    assert_same(
        (int)smoke_scalar($user_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_usermeta' AND conflict_type = 'row-target-deleted'"),
        1,
        'user edit/delete records the edited user metadata delete conflict'
    );
    assert_same(
        (int)smoke_scalar($user_edit_delete_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_users', 'wp_usermeta') AND decision = 'target-wins'"),
        2,
        'user edit/delete defaults the changed source user graph to target-wins before review'
    );

    $comment_edit_delete_base = $tmp . '/comment-edit-delete-base.sqlite';
    $comment_edit_delete_source = $tmp . '/comment-edit-delete-source.sqlite';
    $comment_edit_delete_target = $tmp . '/comment-edit-delete-target.sqlite';
    $comment_edit_delete_metadata = $tmp . '/.forkpress/cow/merge/comment-edit-delete-metadata.sqlite';

    smoke_create_posts_db($comment_edit_delete_base);
    $db = smoke_open_db($comment_edit_delete_base);
    smoke_insert_user($db, 17000081, 'shared-commenter', 'shared-commenter@example.test', 'Shared Commenter');
    smoke_insert_comment($db, 17000082, 1, 17000081, 'Shared Commenter', 'Base shared comment body');
    smoke_insert_commentmeta($db, 17000083, 17000082, 'forkpress_smoke_comment_ref', '{"branch":"base","comment_id":17000082,"user_id":17000081,"post_id":1}');
    $db->close();
    copy($comment_edit_delete_base, $comment_edit_delete_source);
    copy($comment_edit_delete_base, $comment_edit_delete_target);

    $db = smoke_open_db($comment_edit_delete_source);
    $db->exec("UPDATE wp_comments SET comment_content = 'Source edited shared comment body' WHERE comment_ID = 17000082");
    $db->exec("UPDATE wp_commentmeta SET meta_value = '{\"branch\":\"source\",\"comment_id\":17000082,\"user_id\":17000081,\"post_id\":1,\"edited\":true}' WHERE meta_id = 17000083");
    $db->close();

    $db = smoke_open_db($comment_edit_delete_target);
    $db->exec('DELETE FROM wp_commentmeta WHERE comment_id = 17000082');
    $db->exec('DELETE FROM wp_comments WHERE comment_ID = 17000082');
    $db->close();

    $comment_edit_delete_result = cow_merge_databases($comment_edit_delete_base, $comment_edit_delete_source, $comment_edit_delete_target, $comment_edit_delete_metadata, 'feature-smoke-comment-edit-delete', 'main');
    assert_same($comment_edit_delete_result['status'], 'completed_with_conflicts', 'comment edit/delete graph stays reviewable');
    assert_same((int)smoke_scalar($comment_edit_delete_target, 'SELECT COUNT(*) FROM wp_comments WHERE comment_ID = 17000082'), 0, 'comment edit/delete preserves target comment deletion before review');
    assert_same((int)smoke_scalar($comment_edit_delete_target, 'SELECT COUNT(*) FROM wp_commentmeta WHERE comment_id = 17000082'), 0, 'comment edit/delete preserves target comment metadata deletion before review');
    assert_same((int)smoke_scalar($comment_edit_delete_target, 'SELECT COUNT(*) FROM wp_users WHERE ID = 17000081'), 1, 'comment edit/delete preserves the unchanged comment author');
    assert_same(
        (int)smoke_scalar($comment_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_comments' AND conflict_type = 'row-target-deleted'"),
        1,
        'comment edit/delete records the edited comment delete conflict'
    );
    assert_same(
        (int)smoke_scalar($comment_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_commentmeta' AND conflict_type = 'row-target-deleted'"),
        1,
        'comment edit/delete records the edited comment metadata delete conflict'
    );
    assert_same(
        (int)smoke_scalar($comment_edit_delete_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_comments', 'wp_commentmeta') AND decision = 'target-wins'"),
        2,
        'comment edit/delete defaults the changed source comment graph to target-wins before review'
    );

    $cpt_base = $tmp . '/cpt-base.sqlite';
    $cpt_source = $tmp . '/cpt-source.sqlite';
    $cpt_target = $tmp . '/cpt-target.sqlite';
    $cpt_metadata = $tmp . '/.forkpress/cow/merge/cpt-metadata.sqlite';

    smoke_create_posts_db($cpt_base);
    copy($cpt_base, $cpt_source);
    copy($cpt_base, $cpt_target);

    $source_cpt_option = json_encode([
        'branch' => 'source',
        'page_id' => 18000080,
        'note_id' => 18000081,
        'term_taxonomy_id' => 18000085,
    ], JSON_UNESCAPED_SLASHES);
    $target_cpt_option = json_encode([
        'branch' => 'target',
        'page_id' => 19000080,
        'note_id' => 19000081,
        'term_taxonomy_id' => 19000085,
    ], JSON_UNESCAPED_SLASHES);

    $db = smoke_open_db($cpt_source);
    smoke_insert_post($db, 18000080, 'Branch Page With Note', 'Branch page linked to a plugin note', 'page', 'branch-page-with-note');
    smoke_insert_post($db, 18000081, 'Branch Plugin Note', 'Branch plugin CPT body', 'forkpress_note', 'branch-plugin-note', 'publish', 18000080);
    smoke_insert_postmeta($db, 18000082, 18000081, '_forkpress_note_payload', '{"branch":"source","note_id":18000081,"page_id":18000080}');
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES
        (18000084, 'Branch Note Topic', 'branch-note-topic')");
    $db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES
        (18000085, 18000084, 'forkpress_topic', 'Branch CPT topic', 0, 1)");
    $db->exec("INSERT INTO wp_term_relationships (object_id, term_taxonomy_id, term_order) VALUES
        (18000081, 18000085, 0)");
    smoke_insert_option($db, 18000083, 'forkpress_source_note_index', $source_cpt_option);
    $db->close();

    $db = smoke_open_db($cpt_target);
    smoke_insert_post($db, 19000080, 'Main Page With Note', 'Main page linked to a plugin note', 'page', 'main-page-with-note');
    smoke_insert_post($db, 19000081, 'Main Plugin Note', 'Main plugin CPT body', 'forkpress_note', 'main-plugin-note', 'publish', 19000080);
    smoke_insert_postmeta($db, 19000082, 19000081, '_forkpress_note_payload', '{"branch":"target","note_id":19000081,"page_id":19000080}');
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES
        (19000084, 'Main Note Topic', 'main-note-topic')");
    $db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES
        (19000085, 19000084, 'forkpress_topic', 'Main CPT topic', 0, 1)");
    $db->exec("INSERT INTO wp_term_relationships (object_id, term_taxonomy_id, term_order) VALUES
        (19000081, 19000085, 0)");
    smoke_insert_option($db, 19000083, 'forkpress_target_note_index', $target_cpt_option);
    $db->close();

    $cpt_result = cow_merge_databases($cpt_base, $cpt_source, $cpt_target, $cpt_metadata, 'feature-smoke-page-cpt', 'main');
    assert_same($cpt_result['status'], 'completed', 'branch and main page-plus-custom-post-type inserts complete cleanly');
    assert_same((int)($cpt_result['conflicts'] ?? -1), 0, 'branch and main page-plus-custom-post-type inserts do not create merge conflicts');
    assert_same(smoke_scalar($cpt_target, 'SELECT post_title FROM wp_posts WHERE ID = 18000080'), 'Branch Page With Note', 'merged target includes the branch CPT owner page');
    assert_same(smoke_scalar($cpt_target, 'SELECT post_type FROM wp_posts WHERE ID = 18000081'), 'forkpress_note', 'merged target includes the branch custom post type row');
    assert_same((int)smoke_scalar($cpt_target, 'SELECT post_parent FROM wp_posts WHERE ID = 18000081'), 18000080, 'merged target keeps the branch CPT parent page reference');
    assert_same(smoke_scalar($cpt_target, 'SELECT meta_value FROM wp_postmeta WHERE meta_id = 18000082'), '{"branch":"source","note_id":18000081,"page_id":18000080}', 'merged target includes branch CPT metadata with source graph references');
    assert_same(smoke_scalar($cpt_target, "SELECT option_value FROM wp_options WHERE option_name = 'forkpress_source_note_index'"), $source_cpt_option, 'merged target includes branch CPT option with source graph references');
    assert_same(smoke_scalar($cpt_target, 'SELECT taxonomy FROM wp_term_taxonomy WHERE term_taxonomy_id = 18000085'), 'forkpress_topic', 'merged target includes the branch CPT taxonomy row');
    assert_same((int)smoke_scalar($cpt_target, 'SELECT COUNT(*) FROM wp_term_relationships WHERE object_id = 18000081 AND term_taxonomy_id = 18000085'), 1, 'merged target includes the branch CPT topic relationship');
    assert_same(smoke_scalar($cpt_target, 'SELECT post_title FROM wp_posts WHERE ID = 19000080'), 'Main Page With Note', 'merged target preserves the main CPT owner page');
    assert_same(smoke_scalar($cpt_target, 'SELECT post_type FROM wp_posts WHERE ID = 19000081'), 'forkpress_note', 'merged target preserves the main custom post type row');
    assert_same(smoke_scalar($cpt_target, 'SELECT meta_value FROM wp_postmeta WHERE meta_id = 19000082'), '{"branch":"target","note_id":19000081,"page_id":19000080}', 'merged target preserves target CPT metadata with target graph references');
    assert_same(smoke_scalar($cpt_target, "SELECT option_value FROM wp_options WHERE option_name = 'forkpress_target_note_index'"), $target_cpt_option, 'merged target preserves target CPT option with target graph references');
    assert_same(
        (int)smoke_scalar($cpt_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name IN ('wp_posts', 'wp_postmeta', 'wp_options', 'wp_terms', 'wp_term_taxonomy', 'wp_term_relationships')"),
        0,
        'page-plus-custom-post-type smoke merge records no WordPress graph conflicts'
    );
    assert_same(
        (int)smoke_scalar($cpt_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_postmeta', 'wp_options', 'wp_terms', 'wp_term_taxonomy', 'wp_term_relationships') AND decision = 'source-applied'"),
        7,
        'page-plus-custom-post-type smoke merge audits all source graph inserts'
    );
    assert_same(
        (int)smoke_scalar($cpt_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_postmeta', 'wp_options', 'wp_terms', 'wp_term_taxonomy', 'wp_term_relationships') AND decision = 'target-kept' AND reason = 'target inserted row and source did not have it'"),
        7,
        'page-plus-custom-post-type smoke merge audits all target graph inserts'
    );

    $cpt_edit_delete_base = $tmp . '/cpt-edit-delete-base.sqlite';
    $cpt_edit_delete_source = $tmp . '/cpt-edit-delete-source.sqlite';
    $cpt_edit_delete_target = $tmp . '/cpt-edit-delete-target.sqlite';
    $cpt_edit_delete_metadata = $tmp . '/.forkpress/cow/merge/cpt-edit-delete-metadata.sqlite';

    smoke_create_posts_db($cpt_edit_delete_base);
    $db = smoke_open_db($cpt_edit_delete_base);
    smoke_insert_post($db, 17000090, 'Shared CPT Owner Page', 'Base owner page body', 'page', 'shared-cpt-owner-page');
    smoke_insert_post($db, 17000091, 'Shared Plugin Note', 'Base plugin CPT body', 'forkpress_note', 'shared-plugin-note', 'publish', 17000090);
    smoke_insert_postmeta($db, 17000092, 17000091, '_forkpress_note_payload', '{"branch":"base","note_id":17000091,"page_id":17000090}');
    smoke_insert_option($db, 17000093, 'forkpress_shared_note_index', '{"branch":"base","note_id":17000091,"page_id":17000090}');
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES (17000094, 'Shared Note Topic', 'shared-note-topic')");
    $db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES (17000095, 17000094, 'forkpress_topic', 'Shared note topic', 0, 1)");
    $db->exec("INSERT INTO wp_term_relationships (object_id, term_taxonomy_id, term_order) VALUES (17000091, 17000095, 0)");
    $db->close();
    copy($cpt_edit_delete_base, $cpt_edit_delete_source);
    copy($cpt_edit_delete_base, $cpt_edit_delete_target);

    $db = smoke_open_db($cpt_edit_delete_source);
    $db->exec("UPDATE wp_posts SET post_title = 'Source Edited Plugin Note', post_content = 'Source edited plugin CPT body' WHERE ID = 17000091");
    $db->exec("UPDATE wp_postmeta SET meta_value = '{\"branch\":\"source\",\"note_id\":17000091,\"page_id\":17000090,\"edited\":true}' WHERE meta_id = 17000092");
    smoke_update_option($db, 'forkpress_shared_note_index', '{"branch":"source","note_id":17000091,"page_id":17000090,"edited":true}');
    $db->exec('UPDATE wp_term_relationships SET term_order = 1 WHERE object_id = 17000091 AND term_taxonomy_id = 17000095');
    $db->close();

    $db = smoke_open_db($cpt_edit_delete_target);
    $db->exec('DELETE FROM wp_term_relationships WHERE object_id = 17000091 AND term_taxonomy_id = 17000095');
    $db->exec("DELETE FROM wp_options WHERE option_name = 'forkpress_shared_note_index'");
    $db->exec('DELETE FROM wp_postmeta WHERE post_id = 17000091');
    $db->exec('DELETE FROM wp_posts WHERE ID = 17000091');
    $db->close();

    $cpt_edit_delete_result = cow_merge_databases($cpt_edit_delete_base, $cpt_edit_delete_source, $cpt_edit_delete_target, $cpt_edit_delete_metadata, 'feature-smoke-cpt-edit-delete', 'main');
    assert_same($cpt_edit_delete_result['status'], 'completed_with_conflicts', 'custom post type edit/delete graph stays reviewable');
    assert_same((int)smoke_scalar($cpt_edit_delete_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 17000091'), 0, 'custom post type edit/delete preserves target CPT deletion before review');
    assert_same((int)smoke_scalar($cpt_edit_delete_target, 'SELECT COUNT(*) FROM wp_postmeta WHERE post_id = 17000091'), 0, 'custom post type edit/delete preserves target CPT metadata deletion before review');
    assert_same((int)smoke_scalar($cpt_edit_delete_target, "SELECT COUNT(*) FROM wp_options WHERE option_name = 'forkpress_shared_note_index'"), 0, 'custom post type edit/delete preserves target CPT option cleanup before review');
    assert_same((int)smoke_scalar($cpt_edit_delete_target, 'SELECT COUNT(*) FROM wp_term_relationships WHERE object_id = 17000091 AND term_taxonomy_id = 17000095'), 0, 'custom post type edit/delete preserves target CPT taxonomy relationship deletion before review');
    assert_same(smoke_scalar($cpt_edit_delete_target, 'SELECT post_title FROM wp_posts WHERE ID = 17000090'), 'Shared CPT Owner Page', 'custom post type edit/delete preserves unchanged owner page');
    assert_same(smoke_scalar($cpt_edit_delete_target, 'SELECT name FROM wp_terms WHERE term_id = 17000094'), 'Shared Note Topic', 'custom post type edit/delete preserves unchanged taxonomy term');
    assert_same(
        (int)smoke_scalar($cpt_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_posts' AND conflict_type = 'row-target-deleted'"),
        1,
        'custom post type edit/delete records the edited CPT delete conflict'
    );
    assert_same(
        (int)smoke_scalar($cpt_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_postmeta' AND conflict_type = 'row-target-deleted'"),
        1,
        'custom post type edit/delete records the edited CPT metadata delete conflict'
    );
    assert_same(
        (int)smoke_scalar($cpt_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_options' AND conflict_type = 'row-target-deleted'"),
        1,
        'custom post type edit/delete records the edited CPT option delete conflict'
    );
    assert_same(
        (int)smoke_scalar($cpt_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_term_relationships' AND conflict_type = 'row-target-deleted'"),
        1,
        'custom post type edit/delete records the edited taxonomy relationship delete conflict'
    );
    assert_same(
        (int)smoke_scalar($cpt_edit_delete_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_postmeta', 'wp_options', 'wp_term_relationships') AND decision = 'target-wins'"),
        4,
        'custom post type edit/delete defaults the changed source CPT graph to target-wins before review'
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

    $taxonomy_edit_delete_base = $tmp . '/taxonomy-edit-delete-base.sqlite';
    $taxonomy_edit_delete_source = $tmp . '/taxonomy-edit-delete-source.sqlite';
    $taxonomy_edit_delete_target = $tmp . '/taxonomy-edit-delete-target.sqlite';
    $taxonomy_edit_delete_metadata = $tmp . '/.forkpress/cow/merge/taxonomy-edit-delete-metadata.sqlite';

    smoke_create_posts_db($taxonomy_edit_delete_base);
    $db = smoke_open_db($taxonomy_edit_delete_base);
    smoke_insert_post($db, 17000140, 'Shared Taxonomy Page', 'Shared taxonomy page content', 'page', 'shared-taxonomy-page');
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES
        (17000141, 'Shared Parent Topic', 'shared-parent-topic'),
        (17000142, 'Shared Child Topic', 'shared-child-topic')");
    $db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES
        (17000143, 17000141, 'category', 'Shared parent topic', 0, 1),
        (17000144, 17000142, 'category', 'Shared child topic', 17000143, 1)");
    smoke_insert_termmeta($db, 17000145, 17000142, 'forkpress_topic_payload', '{"branch":"base","term_id":17000142}');
    $db->exec("INSERT INTO wp_term_relationships (object_id, term_taxonomy_id, term_order) VALUES
        (17000140, 17000144, 0)");
    $db->close();
    copy($taxonomy_edit_delete_base, $taxonomy_edit_delete_source);
    copy($taxonomy_edit_delete_base, $taxonomy_edit_delete_target);

    $db = smoke_open_db($taxonomy_edit_delete_source);
    $db->exec("UPDATE wp_terms SET name = 'Source Edited Child Topic', slug = 'source-edited-child-topic' WHERE term_id = 17000142");
    $db->exec("UPDATE wp_term_taxonomy SET description = 'Source edited child topic', count = 2 WHERE term_taxonomy_id = 17000144");
    $db->exec("UPDATE wp_termmeta SET meta_value = '{\"branch\":\"source\",\"term_id\":17000142,\"edited\":true}' WHERE meta_id = 17000145");
    $db->exec('UPDATE wp_term_relationships SET term_order = 1 WHERE object_id = 17000140 AND term_taxonomy_id = 17000144');
    $db->close();

    $db = smoke_open_db($taxonomy_edit_delete_target);
    $db->exec('DELETE FROM wp_term_relationships WHERE object_id = 17000140 AND term_taxonomy_id = 17000144');
    $db->exec('DELETE FROM wp_termmeta WHERE term_id = 17000142');
    $db->exec('DELETE FROM wp_term_taxonomy WHERE term_taxonomy_id = 17000144');
    $db->exec('DELETE FROM wp_terms WHERE term_id = 17000142');
    $db->close();

    $taxonomy_edit_delete_result = cow_merge_databases($taxonomy_edit_delete_base, $taxonomy_edit_delete_source, $taxonomy_edit_delete_target, $taxonomy_edit_delete_metadata, 'feature-smoke-taxonomy-edit-delete', 'main');
    assert_same($taxonomy_edit_delete_result['status'], 'completed_with_conflicts', 'taxonomy term edit/delete graph stays reviewable');
    assert_same((int)smoke_scalar($taxonomy_edit_delete_target, 'SELECT COUNT(*) FROM wp_terms WHERE term_id = 17000142'), 0, 'taxonomy edit/delete preserves target term deletion before review');
    assert_same((int)smoke_scalar($taxonomy_edit_delete_target, 'SELECT COUNT(*) FROM wp_term_taxonomy WHERE term_taxonomy_id = 17000144'), 0, 'taxonomy edit/delete preserves target term taxonomy deletion before review');
    assert_same((int)smoke_scalar($taxonomy_edit_delete_target, 'SELECT COUNT(*) FROM wp_termmeta WHERE term_id = 17000142'), 0, 'taxonomy edit/delete preserves target term metadata deletion before review');
    assert_same((int)smoke_scalar($taxonomy_edit_delete_target, 'SELECT COUNT(*) FROM wp_term_relationships WHERE object_id = 17000140 AND term_taxonomy_id = 17000144'), 0, 'taxonomy edit/delete preserves target page-term relationship deletion before review');
    assert_same(smoke_scalar($taxonomy_edit_delete_target, 'SELECT name FROM wp_terms WHERE term_id = 17000141'), 'Shared Parent Topic', 'taxonomy edit/delete preserves unchanged parent term');
    assert_same(smoke_scalar($taxonomy_edit_delete_target, 'SELECT description FROM wp_term_taxonomy WHERE term_taxonomy_id = 17000143'), 'Shared parent topic', 'taxonomy edit/delete preserves unchanged parent term taxonomy');
    assert_same(smoke_scalar($taxonomy_edit_delete_target, 'SELECT post_title FROM wp_posts WHERE ID = 17000140'), 'Shared Taxonomy Page', 'taxonomy edit/delete preserves unchanged related page');
    assert_same(
        (int)smoke_scalar($taxonomy_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_terms' AND conflict_type = 'row-target-deleted'"),
        1,
        'taxonomy edit/delete records the edited term delete conflict'
    );
    assert_same(
        (int)smoke_scalar($taxonomy_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_term_taxonomy' AND conflict_type = 'row-target-deleted'"),
        1,
        'taxonomy edit/delete records the edited term taxonomy delete conflict'
    );
    assert_same(
        (int)smoke_scalar($taxonomy_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_termmeta' AND conflict_type = 'row-target-deleted'"),
        1,
        'taxonomy edit/delete records the edited term metadata delete conflict'
    );
    assert_same(
        (int)smoke_scalar($taxonomy_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_term_relationships' AND conflict_type = 'row-target-deleted'"),
        1,
        'taxonomy edit/delete records the edited page-term relationship delete conflict'
    );
    assert_same(
        (int)smoke_scalar($taxonomy_edit_delete_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_terms', 'wp_term_taxonomy', 'wp_termmeta', 'wp_term_relationships') AND decision = 'target-wins'"),
        4,
        'taxonomy edit/delete defaults the changed source graph to target-wins before review'
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

    $nav_widget_base = $tmp . '/nav-widget-base.sqlite';
    $nav_widget_source = $tmp . '/nav-widget-source.sqlite';
    $nav_widget_target = $tmp . '/nav-widget-target.sqlite';
    $nav_widget_metadata = $tmp . '/.forkpress/cow/merge/nav-widget-metadata.sqlite';
    $nav_widget_base_value = serialize(['_multiwidget' => 1]);
    $nav_widget_source_value = serialize([
        2 => [
            'title' => 'Source nav widget',
            'nav_menu' => 18000270,
        ],
        '_multiwidget' => 1,
    ]);
    $nav_widget_target_value = serialize([
        3 => [
            'title' => 'Main nav widget',
            'nav_menu' => 19000270,
        ],
        '_multiwidget' => 1,
    ]);

    smoke_create_posts_db($nav_widget_base);
    $db = smoke_open_db($nav_widget_base);
    smoke_insert_option($db, 17000270, 'widget_nav_menu', $nav_widget_base_value);
    $db->close();
    copy($nav_widget_base, $nav_widget_source);
    copy($nav_widget_base, $nav_widget_target);

    $db = smoke_open_db($nav_widget_source);
    smoke_insert_post($db, 18000271, 'Branch Nav Widget Page', 'Branch nav widget page content', 'page', 'branch-nav-widget-page');
    smoke_insert_post($db, 18000273, 'Branch Nav Widget Item', '', 'nav_menu_item', 'branch-nav-widget-item');
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES
        (18000270, 'Branch Nav Widget Menu', 'branch-nav-widget-menu')");
    $db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES
        (18000272, 18000270, 'nav_menu', 'Branch nav widget menu taxonomy', 0, 1)");
    $db->exec("INSERT INTO wp_term_relationships (object_id, term_taxonomy_id, term_order) VALUES
        (18000273, 18000272, 0)");
    smoke_insert_postmeta($db, 18000274, 18000273, '_menu_item_type', 'post_type');
    smoke_insert_postmeta($db, 18000275, 18000273, '_menu_item_object', 'page');
    smoke_insert_postmeta($db, 18000276, 18000273, '_menu_item_object_id', '18000271');
    smoke_insert_postmeta($db, 18000277, 18000273, '_menu_item_menu_item_parent', '0');
    smoke_update_option($db, 'widget_nav_menu', $nav_widget_source_value);
    $db->close();

    $db = smoke_open_db($nav_widget_target);
    smoke_insert_post($db, 19000271, 'Main Nav Widget Page', 'Main nav widget page content', 'page', 'main-nav-widget-page');
    smoke_insert_post($db, 19000273, 'Main Nav Widget Item', '', 'nav_menu_item', 'main-nav-widget-item');
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES
        (19000270, 'Main Nav Widget Menu', 'main-nav-widget-menu')");
    $db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES
        (19000272, 19000270, 'nav_menu', 'Main nav widget menu taxonomy', 0, 1)");
    $db->exec("INSERT INTO wp_term_relationships (object_id, term_taxonomy_id, term_order) VALUES
        (19000273, 19000272, 0)");
    smoke_insert_postmeta($db, 19000274, 19000273, '_menu_item_type', 'post_type');
    smoke_insert_postmeta($db, 19000275, 19000273, '_menu_item_object', 'page');
    smoke_insert_postmeta($db, 19000276, 19000273, '_menu_item_object_id', '19000271');
    smoke_insert_postmeta($db, 19000277, 19000273, '_menu_item_menu_item_parent', '0');
    smoke_update_option($db, 'widget_nav_menu', $nav_widget_target_value);
    $db->close();

    $nav_widget_result = cow_merge_databases($nav_widget_base, $nav_widget_source, $nav_widget_target, $nav_widget_metadata, 'feature-smoke-nav-widget', 'main');
    $nav_widget_target_value_after = unserialize(
        (string)smoke_scalar($nav_widget_target, "SELECT option_value FROM wp_options WHERE option_name = 'widget_nav_menu'"),
        ['allowed_classes' => false]
    );
    assert_same($nav_widget_result['status'], 'completed_with_conflicts', 'branch and main widget_nav_menu disagreement stays reviewable');
    assert_same(smoke_scalar($nav_widget_target, 'SELECT name FROM wp_terms WHERE term_id = 18000270'), 'Branch Nav Widget Menu', 'nav widget conflict still merges the branch menu term');
    assert_same(smoke_scalar($nav_widget_target, 'SELECT taxonomy FROM wp_term_taxonomy WHERE term_taxonomy_id = 18000272'), 'nav_menu', 'nav widget conflict still merges the branch menu taxonomy');
    assert_same(smoke_scalar($nav_widget_target, 'SELECT post_title FROM wp_posts WHERE ID = 18000273'), 'Branch Nav Widget Item', 'nav widget conflict still merges the branch menu item post');
    assert_same(smoke_scalar($nav_widget_target, "SELECT meta_value FROM wp_postmeta WHERE post_id = 18000273 AND meta_key = '_menu_item_object_id'"), '18000271', 'nav widget conflict still merges branch menu item page reference');
    assert_same(smoke_scalar($nav_widget_target, 'SELECT name FROM wp_terms WHERE term_id = 19000270'), 'Main Nav Widget Menu', 'nav widget conflict preserves the main menu term');
    assert_same($nav_widget_target_value_after[3]['nav_menu'] ?? null, 19000270, 'nav widget conflict keeps target widget menu before review');
    assert_same(
        (int)smoke_scalar($nav_widget_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_options' AND column_name = 'option_value' AND conflict_type = 'cell-conflict'"),
        1,
        'nav widget conflict records one serialized widget option conflict'
    );
    assert_same(
        (int)smoke_scalar($nav_widget_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_options' AND decision = 'target-wins'"),
        1,
        'nav widget conflict defaults widget option to target-wins before review'
    );
    assert_same(
        (int)smoke_scalar($nav_widget_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_postmeta', 'wp_terms', 'wp_term_taxonomy', 'wp_term_relationships') AND decision = 'source-applied'"),
        9,
        'nav widget conflict audits all source menu graph inserts'
    );

    $menu_edit_delete_base = $tmp . '/menu-edit-delete-base.sqlite';
    $menu_edit_delete_source = $tmp . '/menu-edit-delete-source.sqlite';
    $menu_edit_delete_target = $tmp . '/menu-edit-delete-target.sqlite';
    $menu_edit_delete_metadata = $tmp . '/.forkpress/cow/merge/menu-edit-delete-metadata.sqlite';

    smoke_create_posts_db($menu_edit_delete_base);
    $db = smoke_open_db($menu_edit_delete_base);
    smoke_insert_post($db, 17000100, 'Shared Menu Page', 'Shared menu page content', 'page', 'shared-menu-page');
    smoke_insert_post($db, 17000103, 'Shared Menu Item', '', 'nav_menu_item', 'shared-menu-item');
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES
        (17000101, 'Shared Menu', 'shared-menu')");
    $db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES
        (17000102, 17000101, 'nav_menu', 'Shared menu taxonomy', 0, 1)");
    $db->exec("INSERT INTO wp_term_relationships (object_id, term_taxonomy_id, term_order) VALUES
        (17000103, 17000102, 0)");
    smoke_insert_postmeta($db, 17000104, 17000103, '_menu_item_type', 'post_type');
    smoke_insert_postmeta($db, 17000105, 17000103, '_menu_item_object', 'page');
    smoke_insert_postmeta($db, 17000106, 17000103, '_menu_item_object_id', '17000100');
    smoke_insert_postmeta($db, 17000107, 17000103, '_menu_item_menu_item_parent', '0');
    smoke_insert_postmeta($db, 17000108, 17000103, '_menu_item_classes', serialize([]));
    smoke_update_option($db, 'theme_mods_forkpress_smoke', serialize([
        'color' => 'blue',
        'nav_menu_locations' => [
            'shared_primary' => 17000101,
        ],
    ]));
    $db->close();
    copy($menu_edit_delete_base, $menu_edit_delete_source);
    copy($menu_edit_delete_base, $menu_edit_delete_target);

    $db = smoke_open_db($menu_edit_delete_source);
    $db->exec("UPDATE wp_posts SET post_title = 'Source Edited Shared Menu Item', post_name = 'source-edited-shared-menu-item' WHERE ID = 17000103");
    $db->exec("UPDATE wp_terms SET name = 'Source Edited Shared Menu', slug = 'source-edited-shared-menu' WHERE term_id = 17000101");
    $db->exec("UPDATE wp_term_taxonomy SET description = 'Source edited menu taxonomy' WHERE term_taxonomy_id = 17000102");
    $stmt = $db->prepare('UPDATE wp_postmeta SET meta_value = :value WHERE meta_id = 17000108');
    $stmt->bindValue(':value', serialize(['source-edited-menu']), SQLITE3_TEXT);
    $stmt->execute();
    $db->close();

    $db = smoke_open_db($menu_edit_delete_target);
    $db->exec('DELETE FROM wp_postmeta WHERE post_id = 17000103');
    $db->exec('DELETE FROM wp_term_relationships WHERE object_id = 17000103 OR term_taxonomy_id = 17000102');
    $db->exec('DELETE FROM wp_posts WHERE ID = 17000103');
    $db->exec('DELETE FROM wp_term_taxonomy WHERE term_taxonomy_id = 17000102');
    $db->exec('DELETE FROM wp_terms WHERE term_id = 17000101');
    smoke_update_option($db, 'theme_mods_forkpress_smoke', serialize([
        'color' => 'red',
        'nav_menu_locations' => [],
    ]));
    $db->close();

    $menu_edit_delete_result = cow_merge_databases($menu_edit_delete_base, $menu_edit_delete_source, $menu_edit_delete_target, $menu_edit_delete_metadata, 'feature-smoke-menu-edit-delete', 'main');
    assert_same($menu_edit_delete_result['status'], 'completed_with_conflicts', 'menu edit/delete graph stays reviewable');
    assert_same((int)smoke_scalar($menu_edit_delete_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 17000103'), 0, 'menu edit/delete preserves target nav item deletion before review');
    assert_same((int)smoke_scalar($menu_edit_delete_target, 'SELECT COUNT(*) FROM wp_terms WHERE term_id = 17000101'), 0, 'menu edit/delete preserves target menu term deletion before review');
    assert_same((int)smoke_scalar($menu_edit_delete_target, 'SELECT COUNT(*) FROM wp_term_taxonomy WHERE term_taxonomy_id = 17000102'), 0, 'menu edit/delete preserves target menu taxonomy deletion before review');
    assert_same((int)smoke_scalar($menu_edit_delete_target, 'SELECT COUNT(*) FROM wp_term_relationships WHERE object_id = 17000103 AND term_taxonomy_id = 17000102'), 0, 'menu edit/delete preserves target menu relationship deletion before review');
    assert_same((int)smoke_scalar($menu_edit_delete_target, 'SELECT COUNT(*) FROM wp_postmeta WHERE post_id = 17000103'), 0, 'menu edit/delete preserves target nav item metadata deletion before review');
    $menu_edit_delete_theme_mods = unserialize(
        (string)smoke_scalar($menu_edit_delete_target, "SELECT option_value FROM wp_options WHERE option_name = 'theme_mods_forkpress_smoke'"),
        ['allowed_classes' => false]
    );
    assert_same($menu_edit_delete_theme_mods['color'] ?? null, 'red', 'menu edit/delete preserves target-local theme mod changes before review');
    assert_same(array_key_exists('shared_primary', $menu_edit_delete_theme_mods['nav_menu_locations'] ?? []), false, 'menu edit/delete preserves target menu location cleanup before review');
    assert_same(
        (int)smoke_scalar($menu_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_posts' AND conflict_type = 'row-target-deleted'"),
        1,
        'menu edit/delete records the edited nav item delete conflict'
    );
    assert_same(
        (int)smoke_scalar($menu_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_terms' AND conflict_type = 'row-target-deleted'"),
        1,
        'menu edit/delete records the edited menu term delete conflict'
    );
    assert_same(
        (int)smoke_scalar($menu_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_term_taxonomy' AND conflict_type = 'row-target-deleted'"),
        1,
        'menu edit/delete records the edited menu taxonomy delete conflict'
    );
    assert_same(
        (int)smoke_scalar($menu_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_postmeta' AND conflict_type = 'row-target-deleted'"),
        1,
        'menu edit/delete records the edited nav item metadata delete conflict'
    );
    assert_same(
        (int)smoke_scalar($menu_edit_delete_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_terms', 'wp_term_taxonomy', 'wp_postmeta') AND decision = 'target-wins'"),
        4,
        'menu edit/delete defaults the changed source graph to target-wins before review'
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

    $block_edit_delete_base = $tmp . '/block-edit-delete-base.sqlite';
    $block_edit_delete_source = $tmp . '/block-edit-delete-source.sqlite';
    $block_edit_delete_target = $tmp . '/block-edit-delete-target.sqlite';
    $block_edit_delete_metadata = $tmp . '/.forkpress/cow/merge/block-edit-delete-metadata.sqlite';

    smoke_create_posts_db($block_edit_delete_base);
    $shared_block_content = '<!-- wp:paragraph --><p>Shared reusable block body</p><!-- /wp:paragraph -->';
    $shared_page_content = '<!-- wp:paragraph --><p>Page before shared block</p><!-- /wp:paragraph -->' . "\n" .
        '<!-- wp:block {"ref":17000121} /-->';
    $target_without_block_content = '<!-- wp:paragraph --><p>Target removed the shared block</p><!-- /wp:paragraph -->';
    $source_edited_block_content = '<!-- wp:paragraph --><p>Source edited reusable block body</p><!-- /wp:paragraph -->';

    $db = smoke_open_db($block_edit_delete_base);
    smoke_insert_post($db, 17000120, 'Shared Page With Reusable Block', $shared_page_content, 'page', 'shared-page-with-reusable-block');
    smoke_insert_post($db, 17000121, 'Shared Reusable Block', $shared_block_content, 'wp_block', 'shared-reusable-block');
    $db->close();
    copy($block_edit_delete_base, $block_edit_delete_source);
    copy($block_edit_delete_base, $block_edit_delete_target);

    $db = smoke_open_db($block_edit_delete_source);
    $stmt = $db->prepare("UPDATE wp_posts SET post_title = 'Source Edited Shared Reusable Block', post_content = :content WHERE ID = 17000121");
    $stmt->bindValue(':content', $source_edited_block_content, SQLITE3_TEXT);
    $stmt->execute();
    $db->close();

    $db = smoke_open_db($block_edit_delete_target);
    $stmt = $db->prepare('UPDATE wp_posts SET post_content = :content WHERE ID = 17000120');
    $stmt->bindValue(':content', $target_without_block_content, SQLITE3_TEXT);
    $stmt->execute();
    $db->exec('DELETE FROM wp_posts WHERE ID = 17000121');
    $db->close();

    $block_edit_delete_result = cow_merge_databases($block_edit_delete_base, $block_edit_delete_source, $block_edit_delete_target, $block_edit_delete_metadata, 'feature-smoke-reusable-block-edit-delete', 'main');
    assert_same($block_edit_delete_result['status'], 'completed_with_conflicts', 'reusable block edit/delete graph stays reviewable');
    assert_same((int)smoke_scalar($block_edit_delete_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 17000121'), 0, 'reusable block edit/delete preserves target block deletion before review');
    assert_same(smoke_scalar($block_edit_delete_target, 'SELECT post_content FROM wp_posts WHERE ID = 17000120'), $target_without_block_content, 'reusable block edit/delete preserves target page cleanup before review');
    assert_same(
        (int)smoke_scalar($block_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_posts' AND conflict_type = 'row-target-deleted'"),
        1,
        'reusable block edit/delete records the edited block delete conflict'
    );
    assert_same(
        (int)smoke_scalar($block_edit_delete_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'target-wins'"),
        1,
        'reusable block edit/delete defaults the edited source block to target-wins before review'
    );

    $navigation_block_base = $tmp . '/navigation-block-base.sqlite';
    $navigation_block_source = $tmp . '/navigation-block-source.sqlite';
    $navigation_block_target = $tmp . '/navigation-block-target.sqlite';
    $navigation_block_metadata = $tmp . '/.forkpress/cow/merge/navigation-block-metadata.sqlite';

    smoke_create_posts_db($navigation_block_base);
    copy($navigation_block_base, $navigation_block_source);
    copy($navigation_block_base, $navigation_block_target);

    $source_navigation_content = '<!-- wp:navigation-link {"label":"Branch Link","url":"https://example.com/branch"} /-->';
    $source_navigation_page_content = '<!-- wp:paragraph --><p>Branch page before navigation</p><!-- /wp:paragraph -->' . "\n" .
        '<!-- wp:navigation {"ref":18000071} /-->';
    $target_navigation_content = '<!-- wp:navigation-link {"label":"Main Link","url":"https://example.com/main"} /-->';
    $target_navigation_page_content = '<!-- wp:paragraph --><p>Main page before navigation</p><!-- /wp:paragraph -->' . "\n" .
        '<!-- wp:navigation {"ref":19000071} /-->';

    $db = smoke_open_db($navigation_block_source);
    smoke_insert_post($db, 18000070, 'Branch Page With Navigation Block', $source_navigation_page_content, 'page', 'branch-page-with-navigation-block');
    smoke_insert_post($db, 18000071, 'Branch Navigation Block', $source_navigation_content, 'wp_navigation', 'branch-navigation-block');
    $db->close();

    $db = smoke_open_db($navigation_block_target);
    smoke_insert_post($db, 19000070, 'Main Page With Navigation Block', $target_navigation_page_content, 'page', 'main-page-with-navigation-block');
    smoke_insert_post($db, 19000071, 'Main Navigation Block', $target_navigation_content, 'wp_navigation', 'main-navigation-block');
    $db->close();

    $navigation_block_result = cow_merge_databases($navigation_block_base, $navigation_block_source, $navigation_block_target, $navigation_block_metadata, 'feature-smoke-page-navigation-block', 'main');
    assert_same($navigation_block_result['status'], 'completed', 'branch and main page-plus-navigation-block inserts complete cleanly');
    assert_same((int)($navigation_block_result['conflicts'] ?? -1), 0, 'branch and main page-plus-navigation-block inserts do not create merge conflicts');
    assert_same(smoke_scalar($navigation_block_target, 'SELECT post_type FROM wp_posts WHERE ID = 18000071'), 'wp_navigation', 'merged target includes the branch navigation block row');
    assert_same(smoke_scalar($navigation_block_target, 'SELECT post_title FROM wp_posts WHERE ID = 18000070'), 'Branch Page With Navigation Block', 'merged target includes the branch page using a navigation block');
    assert_same(smoke_scalar($navigation_block_target, 'SELECT post_content FROM wp_posts WHERE ID = 18000070'), $source_navigation_page_content, 'merged target preserves the branch page navigation block reference');
    assert_same(smoke_scalar($navigation_block_target, 'SELECT post_type FROM wp_posts WHERE ID = 19000071'), 'wp_navigation', 'merged target preserves the main navigation block row');
    assert_same(smoke_scalar($navigation_block_target, 'SELECT post_title FROM wp_posts WHERE ID = 19000070'), 'Main Page With Navigation Block', 'merged target preserves the main page using a navigation block');
    assert_same(smoke_scalar($navigation_block_target, 'SELECT post_content FROM wp_posts WHERE ID = 19000070'), $target_navigation_page_content, 'merged target preserves the main page navigation block reference');
    assert_same(
        (int)smoke_scalar($navigation_block_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_posts'"),
        0,
        'page-plus-navigation-block smoke merge records no WordPress row conflicts'
    );
    assert_same(
        (int)smoke_scalar($navigation_block_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'source-applied'"),
        2,
        'page-plus-navigation-block smoke merge audits the source page and navigation block inserts'
    );
    assert_same(
        (int)smoke_scalar($navigation_block_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'target-kept' AND reason = 'target inserted row and source did not have it'"),
        2,
        'page-plus-navigation-block smoke merge audits the target page and navigation block inserts'
    );

    $navigation_edit_delete_base = $tmp . '/navigation-edit-delete-base.sqlite';
    $navigation_edit_delete_source = $tmp . '/navigation-edit-delete-source.sqlite';
    $navigation_edit_delete_target = $tmp . '/navigation-edit-delete-target.sqlite';
    $navigation_edit_delete_metadata = $tmp . '/.forkpress/cow/merge/navigation-edit-delete-metadata.sqlite';

    smoke_create_posts_db($navigation_edit_delete_base);
    $shared_navigation_content = '<!-- wp:navigation-link {"label":"Shared Link","url":"https://example.com/shared"} /-->';
    $shared_navigation_page_content = '<!-- wp:paragraph --><p>Page before shared navigation</p><!-- /wp:paragraph -->' . "\n" .
        '<!-- wp:navigation {"ref":17000141} /-->';
    $target_without_navigation_content = '<!-- wp:paragraph --><p>Target removed the shared navigation</p><!-- /wp:paragraph -->';
    $source_edited_navigation_content = '<!-- wp:navigation-link {"label":"Source Edited Link","url":"https://example.com/source"} /-->';

    $db = smoke_open_db($navigation_edit_delete_base);
    smoke_insert_post($db, 17000140, 'Shared Page With Navigation Block', $shared_navigation_page_content, 'page', 'shared-page-with-navigation-block');
    smoke_insert_post($db, 17000141, 'Shared Navigation Block', $shared_navigation_content, 'wp_navigation', 'shared-navigation-block');
    $db->close();
    copy($navigation_edit_delete_base, $navigation_edit_delete_source);
    copy($navigation_edit_delete_base, $navigation_edit_delete_target);

    $db = smoke_open_db($navigation_edit_delete_source);
    $stmt = $db->prepare("UPDATE wp_posts SET post_title = 'Source Edited Shared Navigation Block', post_content = :content WHERE ID = 17000141");
    $stmt->bindValue(':content', $source_edited_navigation_content, SQLITE3_TEXT);
    $stmt->execute();
    $db->close();

    $db = smoke_open_db($navigation_edit_delete_target);
    $stmt = $db->prepare('UPDATE wp_posts SET post_content = :content WHERE ID = 17000140');
    $stmt->bindValue(':content', $target_without_navigation_content, SQLITE3_TEXT);
    $stmt->execute();
    $db->exec('DELETE FROM wp_posts WHERE ID = 17000141');
    $db->close();

    $navigation_edit_delete_result = cow_merge_databases($navigation_edit_delete_base, $navigation_edit_delete_source, $navigation_edit_delete_target, $navigation_edit_delete_metadata, 'feature-smoke-navigation-block-edit-delete', 'main');
    assert_same($navigation_edit_delete_result['status'], 'completed_with_conflicts', 'navigation block edit/delete graph stays reviewable');
    assert_same((int)smoke_scalar($navigation_edit_delete_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 17000141'), 0, 'navigation block edit/delete preserves target block deletion before review');
    assert_same(smoke_scalar($navigation_edit_delete_target, 'SELECT post_content FROM wp_posts WHERE ID = 17000140'), $target_without_navigation_content, 'navigation block edit/delete preserves target page cleanup before review');
    assert_same(
        (int)smoke_scalar($navigation_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_posts' AND conflict_type = 'row-target-deleted'"),
        1,
        'navigation block edit/delete records the edited block delete conflict'
    );
    assert_same(
        (int)smoke_scalar($navigation_edit_delete_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'target-wins'"),
        1,
        'navigation block edit/delete defaults the edited source block to target-wins before review'
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

    $site_icon_base_root = $tmp . '/site-icon-base-root';
    $site_icon_source_root = $tmp . '/site-icon-source-root';
    $site_icon_target_root = $tmp . '/site-icon-target-root';
    $site_icon_base = $site_icon_base_root . '/wp-content/database/.ht.sqlite';
    $site_icon_source = $site_icon_source_root . '/wp-content/database/.ht.sqlite';
    $site_icon_target = $site_icon_target_root . '/wp-content/database/.ht.sqlite';
    $site_icon_file_base = $tmp . '/.forkpress/cow/merge/file-bases/feature-smoke-site-icon.json';
    $site_icon_metadata = $tmp . '/.forkpress/cow/merge/site-icon-metadata.sqlite';

    mkdir(dirname($site_icon_base), 0777, true);
    mkdir(dirname($site_icon_source), 0777, true);
    mkdir(dirname($site_icon_target), 0777, true);
    smoke_create_posts_db($site_icon_base);
    $db = smoke_open_db($site_icon_base);
    smoke_insert_option($db, 17000240, 'site_icon', '0');
    $db->close();
    copy($site_icon_base, $site_icon_source);
    copy($site_icon_base, $site_icon_target);
    cow_merge_capture_file_base($site_icon_base_root, $site_icon_file_base);

    $source_site_icon_meta = serialize([
        'file' => '2026/05/source-site-icon.png',
        'width' => 512,
        'height' => 512,
        'sizes' => [
            'thumbnail' => [
                'file' => 'source-site-icon-150x150.png',
                'width' => 150,
                'height' => 150,
                'mime-type' => 'image/png',
            ],
        ],
    ]);
    $target_site_icon_meta = serialize([
        'file' => '2026/05/main-site-icon.png',
        'width' => 512,
        'height' => 512,
        'sizes' => [
            'thumbnail' => [
                'file' => 'main-site-icon-150x150.png',
                'width' => 150,
                'height' => 150,
                'mime-type' => 'image/png',
            ],
        ],
    ]);

    $db = smoke_open_db($site_icon_source);
    smoke_insert_post($db, 18000240, 'source-site-icon.png', '', 'attachment', 'source-site-icon-png', 'inherit', 0, 'image/png', 'http://example.test/wp-content/uploads/2026/05/source-site-icon.png');
    smoke_insert_postmeta($db, 18000241, 18000240, '_wp_attached_file', '2026/05/source-site-icon.png');
    smoke_insert_postmeta($db, 18000242, 18000240, '_wp_attachment_metadata', $source_site_icon_meta);
    smoke_update_option($db, 'site_icon', '18000240');
    $db->close();
    smoke_write_file($site_icon_source_root . '/wp-content/uploads/2026/05/source-site-icon.png', 'source site icon bytes');
    smoke_write_file($site_icon_source_root . '/wp-content/uploads/2026/05/source-site-icon-150x150.png', 'source site icon thumbnail bytes');

    $db = smoke_open_db($site_icon_target);
    smoke_insert_post($db, 19000240, 'main-site-icon.png', '', 'attachment', 'main-site-icon-png', 'inherit', 0, 'image/png', 'http://example.test/wp-content/uploads/2026/05/main-site-icon.png');
    smoke_insert_postmeta($db, 19000241, 19000240, '_wp_attached_file', '2026/05/main-site-icon.png');
    smoke_insert_postmeta($db, 19000242, 19000240, '_wp_attachment_metadata', $target_site_icon_meta);
    smoke_update_option($db, 'site_icon', '19000240');
    $db->close();
    smoke_write_file($site_icon_target_root . '/wp-content/uploads/2026/05/main-site-icon.png', 'main site icon bytes');
    smoke_write_file($site_icon_target_root . '/wp-content/uploads/2026/05/main-site-icon-150x150.png', 'main site icon thumbnail bytes');

    $site_icon_result = cow_merge_branch_state(
        $site_icon_base,
        $site_icon_source,
        $site_icon_target,
        $site_icon_metadata,
        'feature-smoke-site-icon',
        'main',
        $site_icon_file_base,
        $site_icon_source_root,
        $site_icon_target_root
    );
    assert_same($site_icon_result['status'], 'completed_with_conflicts', 'branch and main site_icon disagreement stays reviewable');
    assert_same(smoke_scalar($site_icon_target, 'SELECT post_type FROM wp_posts WHERE ID = 18000240'), 'attachment', 'site_icon conflict still merges the branch icon attachment row');
    assert_same(smoke_scalar($site_icon_target, "SELECT meta_value FROM wp_postmeta WHERE post_id = 18000240 AND meta_key = '_wp_attached_file'"), '2026/05/source-site-icon.png', 'site_icon conflict still merges branch attached-file metadata');
    assert_same(file_get_contents($site_icon_target_root . '/wp-content/uploads/2026/05/source-site-icon.png'), 'source site icon bytes', 'site_icon conflict still merges branch original icon file');
    assert_same(file_get_contents($site_icon_target_root . '/wp-content/uploads/2026/05/source-site-icon-150x150.png'), 'source site icon thumbnail bytes', 'site_icon conflict still merges branch generated icon file');
    assert_same(smoke_scalar($site_icon_target, 'SELECT post_type FROM wp_posts WHERE ID = 19000240'), 'attachment', 'site_icon conflict preserves the main icon attachment row');
    assert_same(smoke_scalar($site_icon_target, "SELECT option_value FROM wp_options WHERE option_name = 'site_icon'"), '19000240', 'site_icon conflict keeps target scalar attachment option before review');
    assert_same(file_get_contents($site_icon_target_root . '/wp-content/uploads/2026/05/main-site-icon.png'), 'main site icon bytes', 'site_icon conflict preserves main original icon file');
    assert_same(
        (int)smoke_scalar($site_icon_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_options' AND column_name = 'option_value' AND conflict_type = 'cell-conflict'"),
        1,
        'site_icon conflict records one scalar option-value conflict'
    );
    assert_same(
        (int)smoke_scalar($site_icon_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_options' AND decision = 'target-wins'"),
        1,
        'site_icon conflict defaults scalar option to target-wins before review'
    );
    assert_same(
        (int)smoke_scalar($site_icon_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'source-applied'"),
        1,
        'site_icon conflict audits the source attachment row'
    );
    assert_same(
        (int)smoke_scalar($site_icon_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_postmeta' AND decision = 'source-applied'"),
        2,
        'site_icon conflict audits the source attachment metadata rows'
    );
    assert_same(
        (int)smoke_scalar(
            $site_icon_metadata,
            "SELECT COUNT(*) FROM merge_decisions WHERE table_name = '__files__' AND decision = 'source-applied' AND row_identity IN ('" .
            SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/2026/05/source-site-icon.png')) . "', '" .
            SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/2026/05/source-site-icon-150x150.png')) . "')"
        ),
        2,
        'site_icon conflict audits the source upload files'
    );

    $custom_logo_base_root = $tmp . '/custom-logo-base-root';
    $custom_logo_source_root = $tmp . '/custom-logo-source-root';
    $custom_logo_target_root = $tmp . '/custom-logo-target-root';
    $custom_logo_base = $custom_logo_base_root . '/wp-content/database/.ht.sqlite';
    $custom_logo_source = $custom_logo_source_root . '/wp-content/database/.ht.sqlite';
    $custom_logo_target = $custom_logo_target_root . '/wp-content/database/.ht.sqlite';
    $custom_logo_file_base = $tmp . '/.forkpress/cow/merge/file-bases/feature-smoke-custom-logo.json';
    $custom_logo_metadata = $tmp . '/.forkpress/cow/merge/custom-logo-metadata.sqlite';
    $custom_logo_base_mods = serialize([
        'color' => 'blue',
        'custom_logo' => 0,
        'nav_menu_locations' => [],
    ]);
    $custom_logo_source_mods = serialize([
        'color' => 'blue',
        'custom_logo' => 18000250,
        'nav_menu_locations' => [],
    ]);
    $custom_logo_target_mods = serialize([
        'color' => 'blue',
        'custom_logo' => 19000250,
        'nav_menu_locations' => [],
    ]);

    mkdir(dirname($custom_logo_base), 0777, true);
    mkdir(dirname($custom_logo_source), 0777, true);
    mkdir(dirname($custom_logo_target), 0777, true);
    smoke_create_posts_db($custom_logo_base);
    $db = smoke_open_db($custom_logo_base);
    smoke_insert_option($db, 17000250, 'theme_mods_forkpress_logo', $custom_logo_base_mods);
    $db->close();
    copy($custom_logo_base, $custom_logo_source);
    copy($custom_logo_base, $custom_logo_target);
    cow_merge_capture_file_base($custom_logo_base_root, $custom_logo_file_base);

    $source_custom_logo_meta = serialize([
        'file' => '2026/05/source-custom-logo.png',
        'width' => 640,
        'height' => 240,
        'sizes' => [
            'thumbnail' => [
                'file' => 'source-custom-logo-150x150.png',
                'width' => 150,
                'height' => 150,
                'mime-type' => 'image/png',
            ],
        ],
    ]);
    $target_custom_logo_meta = serialize([
        'file' => '2026/05/main-custom-logo.png',
        'width' => 640,
        'height' => 240,
        'sizes' => [
            'thumbnail' => [
                'file' => 'main-custom-logo-150x150.png',
                'width' => 150,
                'height' => 150,
                'mime-type' => 'image/png',
            ],
        ],
    ]);

    $db = smoke_open_db($custom_logo_source);
    smoke_insert_post($db, 18000250, 'source-custom-logo.png', '', 'attachment', 'source-custom-logo-png', 'inherit', 0, 'image/png', 'http://example.test/wp-content/uploads/2026/05/source-custom-logo.png');
    smoke_insert_postmeta($db, 18000251, 18000250, '_wp_attached_file', '2026/05/source-custom-logo.png');
    smoke_insert_postmeta($db, 18000252, 18000250, '_wp_attachment_metadata', $source_custom_logo_meta);
    smoke_update_option($db, 'theme_mods_forkpress_logo', $custom_logo_source_mods);
    $db->close();
    smoke_write_file($custom_logo_source_root . '/wp-content/uploads/2026/05/source-custom-logo.png', 'source custom logo bytes');
    smoke_write_file($custom_logo_source_root . '/wp-content/uploads/2026/05/source-custom-logo-150x150.png', 'source custom logo thumbnail bytes');

    $db = smoke_open_db($custom_logo_target);
    smoke_insert_post($db, 19000250, 'main-custom-logo.png', '', 'attachment', 'main-custom-logo-png', 'inherit', 0, 'image/png', 'http://example.test/wp-content/uploads/2026/05/main-custom-logo.png');
    smoke_insert_postmeta($db, 19000251, 19000250, '_wp_attached_file', '2026/05/main-custom-logo.png');
    smoke_insert_postmeta($db, 19000252, 19000250, '_wp_attachment_metadata', $target_custom_logo_meta);
    smoke_update_option($db, 'theme_mods_forkpress_logo', $custom_logo_target_mods);
    $db->close();
    smoke_write_file($custom_logo_target_root . '/wp-content/uploads/2026/05/main-custom-logo.png', 'main custom logo bytes');
    smoke_write_file($custom_logo_target_root . '/wp-content/uploads/2026/05/main-custom-logo-150x150.png', 'main custom logo thumbnail bytes');

    $custom_logo_result = cow_merge_branch_state(
        $custom_logo_base,
        $custom_logo_source,
        $custom_logo_target,
        $custom_logo_metadata,
        'feature-smoke-custom-logo',
        'main',
        $custom_logo_file_base,
        $custom_logo_source_root,
        $custom_logo_target_root
    );
    $custom_logo_target_mods_after = unserialize(
        (string)smoke_scalar($custom_logo_target, "SELECT option_value FROM wp_options WHERE option_name = 'theme_mods_forkpress_logo'"),
        ['allowed_classes' => false]
    );
    assert_same($custom_logo_result['status'], 'completed_with_conflicts', 'branch and main custom_logo disagreement stays reviewable');
    assert_same(smoke_scalar($custom_logo_target, 'SELECT post_type FROM wp_posts WHERE ID = 18000250'), 'attachment', 'custom_logo conflict still merges the branch logo attachment row');
    assert_same(smoke_scalar($custom_logo_target, "SELECT meta_value FROM wp_postmeta WHERE post_id = 18000250 AND meta_key = '_wp_attached_file'"), '2026/05/source-custom-logo.png', 'custom_logo conflict still merges branch attached-file metadata');
    assert_same(file_get_contents($custom_logo_target_root . '/wp-content/uploads/2026/05/source-custom-logo.png'), 'source custom logo bytes', 'custom_logo conflict still merges branch original logo file');
    assert_same(file_get_contents($custom_logo_target_root . '/wp-content/uploads/2026/05/source-custom-logo-150x150.png'), 'source custom logo thumbnail bytes', 'custom_logo conflict still merges branch generated logo file');
    assert_same(smoke_scalar($custom_logo_target, 'SELECT post_type FROM wp_posts WHERE ID = 19000250'), 'attachment', 'custom_logo conflict preserves the main logo attachment row');
    assert_same($custom_logo_target_mods_after['custom_logo'] ?? null, 19000250, 'custom_logo conflict keeps target theme mod before review');
    assert_same(file_get_contents($custom_logo_target_root . '/wp-content/uploads/2026/05/main-custom-logo.png'), 'main custom logo bytes', 'custom_logo conflict preserves main original logo file');
    assert_same(
        (int)smoke_scalar($custom_logo_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_options' AND column_name = 'option_value' AND conflict_type = 'cell-conflict'"),
        1,
        'custom_logo conflict records one serialized theme-mod option conflict'
    );
    assert_same(
        (int)smoke_scalar($custom_logo_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_options' AND decision = 'target-wins'"),
        1,
        'custom_logo conflict defaults theme-mod option to target-wins before review'
    );
    assert_same(
        (int)smoke_scalar($custom_logo_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'source-applied'"),
        1,
        'custom_logo conflict audits the source attachment row'
    );
    assert_same(
        (int)smoke_scalar($custom_logo_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_postmeta' AND decision = 'source-applied'"),
        2,
        'custom_logo conflict audits the source attachment metadata rows'
    );
    assert_same(
        (int)smoke_scalar(
            $custom_logo_metadata,
            "SELECT COUNT(*) FROM merge_decisions WHERE table_name = '__files__' AND decision = 'source-applied' AND row_identity IN ('" .
            SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/2026/05/source-custom-logo.png')) . "', '" .
            SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/2026/05/source-custom-logo-150x150.png')) . "')"
        ),
        2,
        'custom_logo conflict audits the source upload files'
    );

    $media_widget_base_root = $tmp . '/media-widget-base-root';
    $media_widget_source_root = $tmp . '/media-widget-source-root';
    $media_widget_target_root = $tmp . '/media-widget-target-root';
    $media_widget_base = $media_widget_base_root . '/wp-content/database/.ht.sqlite';
    $media_widget_source = $media_widget_source_root . '/wp-content/database/.ht.sqlite';
    $media_widget_target = $media_widget_target_root . '/wp-content/database/.ht.sqlite';
    $media_widget_file_base = $tmp . '/.forkpress/cow/merge/file-bases/feature-smoke-media-widget.json';
    $media_widget_metadata = $tmp . '/.forkpress/cow/merge/media-widget-metadata.sqlite';
    $media_widget_base_value = serialize(['_multiwidget' => 1]);
    $media_widget_source_value = serialize([
        2 => [
            'attachment_id' => 18000260,
            'url' => 'http://example.test/wp-content/uploads/2026/05/source-media-widget.jpg',
            'caption' => 'Source media widget',
        ],
        '_multiwidget' => 1,
    ]);
    $media_widget_target_value = serialize([
        3 => [
            'attachment_id' => 19000260,
            'url' => 'http://example.test/wp-content/uploads/2026/05/main-media-widget.jpg',
            'caption' => 'Main media widget',
        ],
        '_multiwidget' => 1,
    ]);

    mkdir(dirname($media_widget_base), 0777, true);
    mkdir(dirname($media_widget_source), 0777, true);
    mkdir(dirname($media_widget_target), 0777, true);
    smoke_create_posts_db($media_widget_base);
    $db = smoke_open_db($media_widget_base);
    smoke_insert_option($db, 17000260, 'widget_media_image', $media_widget_base_value);
    $db->close();
    copy($media_widget_base, $media_widget_source);
    copy($media_widget_base, $media_widget_target);
    cow_merge_capture_file_base($media_widget_base_root, $media_widget_file_base);

    $source_media_widget_meta = serialize([
        'file' => '2026/05/source-media-widget.jpg',
        'width' => 1024,
        'height' => 768,
        'sizes' => [
            'thumbnail' => [
                'file' => 'source-media-widget-150x150.jpg',
                'width' => 150,
                'height' => 150,
                'mime-type' => 'image/jpeg',
            ],
        ],
    ]);
    $target_media_widget_meta = serialize([
        'file' => '2026/05/main-media-widget.jpg',
        'width' => 1024,
        'height' => 768,
        'sizes' => [
            'thumbnail' => [
                'file' => 'main-media-widget-150x150.jpg',
                'width' => 150,
                'height' => 150,
                'mime-type' => 'image/jpeg',
            ],
        ],
    ]);

    $db = smoke_open_db($media_widget_source);
    smoke_insert_post($db, 18000260, 'source-media-widget.jpg', '', 'attachment', 'source-media-widget-jpg', 'inherit', 0, 'image/jpeg', 'http://example.test/wp-content/uploads/2026/05/source-media-widget.jpg');
    smoke_insert_postmeta($db, 18000261, 18000260, '_wp_attached_file', '2026/05/source-media-widget.jpg');
    smoke_insert_postmeta($db, 18000262, 18000260, '_wp_attachment_metadata', $source_media_widget_meta);
    smoke_update_option($db, 'widget_media_image', $media_widget_source_value);
    $db->close();
    smoke_write_file($media_widget_source_root . '/wp-content/uploads/2026/05/source-media-widget.jpg', 'source media widget image bytes');
    smoke_write_file($media_widget_source_root . '/wp-content/uploads/2026/05/source-media-widget-150x150.jpg', 'source media widget thumbnail bytes');

    $db = smoke_open_db($media_widget_target);
    smoke_insert_post($db, 19000260, 'main-media-widget.jpg', '', 'attachment', 'main-media-widget-jpg', 'inherit', 0, 'image/jpeg', 'http://example.test/wp-content/uploads/2026/05/main-media-widget.jpg');
    smoke_insert_postmeta($db, 19000261, 19000260, '_wp_attached_file', '2026/05/main-media-widget.jpg');
    smoke_insert_postmeta($db, 19000262, 19000260, '_wp_attachment_metadata', $target_media_widget_meta);
    smoke_update_option($db, 'widget_media_image', $media_widget_target_value);
    $db->close();
    smoke_write_file($media_widget_target_root . '/wp-content/uploads/2026/05/main-media-widget.jpg', 'main media widget image bytes');
    smoke_write_file($media_widget_target_root . '/wp-content/uploads/2026/05/main-media-widget-150x150.jpg', 'main media widget thumbnail bytes');

    $media_widget_result = cow_merge_branch_state(
        $media_widget_base,
        $media_widget_source,
        $media_widget_target,
        $media_widget_metadata,
        'feature-smoke-media-widget',
        'main',
        $media_widget_file_base,
        $media_widget_source_root,
        $media_widget_target_root
    );
    $media_widget_target_value_after = unserialize(
        (string)smoke_scalar($media_widget_target, "SELECT option_value FROM wp_options WHERE option_name = 'widget_media_image'"),
        ['allowed_classes' => false]
    );
    assert_same($media_widget_result['status'], 'completed_with_conflicts', 'branch and main widget_media_image disagreement stays reviewable');
    assert_same(smoke_scalar($media_widget_target, 'SELECT post_type FROM wp_posts WHERE ID = 18000260'), 'attachment', 'media widget conflict still merges the branch widget attachment row');
    assert_same(smoke_scalar($media_widget_target, "SELECT meta_value FROM wp_postmeta WHERE post_id = 18000260 AND meta_key = '_wp_attached_file'"), '2026/05/source-media-widget.jpg', 'media widget conflict still merges branch attached-file metadata');
    assert_same(file_get_contents($media_widget_target_root . '/wp-content/uploads/2026/05/source-media-widget.jpg'), 'source media widget image bytes', 'media widget conflict still merges branch original image file');
    assert_same(file_get_contents($media_widget_target_root . '/wp-content/uploads/2026/05/source-media-widget-150x150.jpg'), 'source media widget thumbnail bytes', 'media widget conflict still merges branch generated image file');
    assert_same(smoke_scalar($media_widget_target, 'SELECT post_type FROM wp_posts WHERE ID = 19000260'), 'attachment', 'media widget conflict preserves the main widget attachment row');
    assert_same($media_widget_target_value_after[3]['attachment_id'] ?? null, 19000260, 'media widget conflict keeps target widget attachment before review');
    assert_same(file_get_contents($media_widget_target_root . '/wp-content/uploads/2026/05/main-media-widget.jpg'), 'main media widget image bytes', 'media widget conflict preserves main original image file');
    assert_same(
        (int)smoke_scalar($media_widget_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_options' AND column_name = 'option_value' AND conflict_type = 'cell-conflict'"),
        1,
        'media widget conflict records one serialized widget option conflict'
    );
    assert_same(
        (int)smoke_scalar($media_widget_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_options' AND decision = 'target-wins'"),
        1,
        'media widget conflict defaults widget option to target-wins before review'
    );
    assert_same(
        (int)smoke_scalar($media_widget_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'source-applied'"),
        1,
        'media widget conflict audits the source attachment row'
    );
    assert_same(
        (int)smoke_scalar($media_widget_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_postmeta' AND decision = 'source-applied'"),
        2,
        'media widget conflict audits the source attachment metadata rows'
    );
    assert_same(
        (int)smoke_scalar(
            $media_widget_metadata,
            "SELECT COUNT(*) FROM merge_decisions WHERE table_name = '__files__' AND decision = 'source-applied' AND row_identity IN ('" .
            SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/2026/05/source-media-widget.jpg')) . "', '" .
            SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/2026/05/source-media-widget-150x150.jpg')) . "')"
        ),
        2,
        'media widget conflict audits the source upload files'
    );

    $attachment_edit_delete_base_root = $tmp . '/attachment-edit-delete-base-root';
    $attachment_edit_delete_source_root = $tmp . '/attachment-edit-delete-source-root';
    $attachment_edit_delete_target_root = $tmp . '/attachment-edit-delete-target-root';
    $attachment_edit_delete_base = $attachment_edit_delete_base_root . '/wp-content/database/.ht.sqlite';
    $attachment_edit_delete_source = $attachment_edit_delete_source_root . '/wp-content/database/.ht.sqlite';
    $attachment_edit_delete_target = $attachment_edit_delete_target_root . '/wp-content/database/.ht.sqlite';
    $attachment_edit_delete_file_base = $tmp . '/.forkpress/cow/merge/file-bases/feature-smoke-attachment-edit-delete.json';
    $attachment_edit_delete_metadata = $tmp . '/.forkpress/cow/merge/attachment-edit-delete-metadata.sqlite';

    mkdir(dirname($attachment_edit_delete_base), 0777, true);
    mkdir(dirname($attachment_edit_delete_source), 0777, true);
    mkdir(dirname($attachment_edit_delete_target), 0777, true);
    smoke_create_posts_db($attachment_edit_delete_base);
    $base_attachment_meta = serialize([
        'file' => '2026/05/shared-edit-delete.jpg',
        'width' => 640,
        'height' => 480,
        'sizes' => [
            'thumbnail' => [
                'file' => 'shared-edit-delete-150x150.jpg',
                'width' => 150,
                'height' => 150,
                'mime-type' => 'image/jpeg',
            ],
        ],
    ]);
    $db = smoke_open_db($attachment_edit_delete_base);
    smoke_insert_post($db, 17000070, 'Shared Page With Existing Image', 'Base attachment page content', 'page', 'shared-page-with-existing-image');
    smoke_insert_post($db, 17000071, 'shared-edit-delete.jpg', '', 'attachment', 'shared-edit-delete-jpg', 'inherit', 17000070, 'image/jpeg', 'http://example.test/wp-content/uploads/2026/05/shared-edit-delete.jpg');
    smoke_insert_postmeta($db, 17000072, 17000070, '_thumbnail_id', '17000071');
    smoke_insert_postmeta($db, 17000073, 17000071, '_wp_attached_file', '2026/05/shared-edit-delete.jpg');
    smoke_insert_postmeta($db, 17000074, 17000071, '_wp_attachment_metadata', $base_attachment_meta);
    $db->close();
    smoke_write_file($attachment_edit_delete_base_root . '/wp-content/uploads/2026/05/shared-edit-delete.jpg', 'base shared image bytes');
    smoke_write_file($attachment_edit_delete_base_root . '/wp-content/uploads/2026/05/shared-edit-delete-150x150.jpg', 'base shared thumbnail bytes');
    copy($attachment_edit_delete_base, $attachment_edit_delete_source);
    copy($attachment_edit_delete_base, $attachment_edit_delete_target);
    smoke_write_file($attachment_edit_delete_source_root . '/wp-content/uploads/2026/05/shared-edit-delete.jpg', 'base shared image bytes');
    smoke_write_file($attachment_edit_delete_source_root . '/wp-content/uploads/2026/05/shared-edit-delete-150x150.jpg', 'base shared thumbnail bytes');
    smoke_write_file($attachment_edit_delete_target_root . '/wp-content/uploads/2026/05/shared-edit-delete.jpg', 'base shared image bytes');
    smoke_write_file($attachment_edit_delete_target_root . '/wp-content/uploads/2026/05/shared-edit-delete-150x150.jpg', 'base shared thumbnail bytes');
    cow_merge_capture_file_base($attachment_edit_delete_base_root, $attachment_edit_delete_file_base);

    $source_edited_attachment_meta = serialize([
        'file' => '2026/05/shared-edit-delete.jpg',
        'width' => 1024,
        'height' => 768,
        'sizes' => [
            'thumbnail' => [
                'file' => 'shared-edit-delete-150x150.jpg',
                'width' => 150,
                'height' => 150,
                'mime-type' => 'image/jpeg',
            ],
        ],
    ]);
    $db = smoke_open_db($attachment_edit_delete_source);
    $db->exec("UPDATE wp_posts SET post_title = 'Source Edited Shared Image', post_mime_type = 'image/jpeg' WHERE ID = 17000071");
    $stmt = $db->prepare("UPDATE wp_postmeta SET meta_value = :metadata WHERE post_id = 17000071 AND meta_key = '_wp_attachment_metadata'");
    $stmt->bindValue(':metadata', $source_edited_attachment_meta, SQLITE3_TEXT);
    $stmt->execute();
    $db->close();
    smoke_write_file($attachment_edit_delete_source_root . '/wp-content/uploads/2026/05/shared-edit-delete.jpg', 'source edited shared image bytes');
    smoke_write_file($attachment_edit_delete_source_root . '/wp-content/uploads/2026/05/shared-edit-delete-150x150.jpg', 'source edited shared thumbnail bytes');

    $db = smoke_open_db($attachment_edit_delete_target);
    $db->exec("DELETE FROM wp_postmeta WHERE post_id = 17000071 OR (post_id = 17000070 AND meta_key = '_thumbnail_id')");
    $db->exec('DELETE FROM wp_posts WHERE ID = 17000071');
    $db->close();
    unlink($attachment_edit_delete_target_root . '/wp-content/uploads/2026/05/shared-edit-delete.jpg');
    unlink($attachment_edit_delete_target_root . '/wp-content/uploads/2026/05/shared-edit-delete-150x150.jpg');

    $attachment_edit_delete_result = cow_merge_branch_state(
        $attachment_edit_delete_base,
        $attachment_edit_delete_source,
        $attachment_edit_delete_target,
        $attachment_edit_delete_metadata,
        'feature-smoke-attachment-edit-delete',
        'main',
        $attachment_edit_delete_file_base,
        $attachment_edit_delete_source_root,
        $attachment_edit_delete_target_root
    );
    assert_same($attachment_edit_delete_result['status'], 'completed_with_conflicts', 'attachment edit/delete graph stays reviewable');
    assert_same((int)smoke_scalar($attachment_edit_delete_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 17000071'), 0, 'attachment edit/delete preserves the target attachment deletion before review');
    assert_same((int)smoke_scalar($attachment_edit_delete_target, 'SELECT COUNT(*) FROM wp_postmeta WHERE post_id = 17000071'), 0, 'attachment edit/delete preserves target metadata deletion before review');
    assert_same((int)smoke_scalar($attachment_edit_delete_target, "SELECT COUNT(*) FROM wp_postmeta WHERE post_id = 17000070 AND meta_key = '_thumbnail_id'"), 0, 'attachment edit/delete preserves target featured-image cleanup before review');
    assert_same(file_exists($attachment_edit_delete_target_root . '/wp-content/uploads/2026/05/shared-edit-delete.jpg'), false, 'attachment edit/delete preserves target original-file deletion before review');
    assert_same(file_exists($attachment_edit_delete_target_root . '/wp-content/uploads/2026/05/shared-edit-delete-150x150.jpg'), false, 'attachment edit/delete preserves target generated-file deletion before review');
    assert_same(
        (int)smoke_scalar($attachment_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_posts' AND conflict_type = 'row-target-deleted'"),
        1,
        'attachment edit/delete records the attachment post edit/delete conflict'
    );
    assert_same(
        (int)smoke_scalar($attachment_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_postmeta' AND conflict_type = 'row-target-deleted'"),
        1,
        'attachment edit/delete records the edited attachment metadata delete conflict'
    );
    assert_same(
        (int)smoke_scalar($attachment_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = '__files__' AND conflict_type = 'file-target-deleted'"),
        2,
        'attachment edit/delete records original and generated file edit/delete conflicts'
    );
    assert_same(
        (int)smoke_scalar($attachment_edit_delete_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_postmeta', '__files__') AND decision = 'target-wins'"),
        4,
        'attachment edit/delete defaults the changed source graph to target-wins before review'
    );

    $image_block_base_root = $tmp . '/image-block-base-root';
    $image_block_source_root = $tmp . '/image-block-source-root';
    $image_block_target_root = $tmp . '/image-block-target-root';
    $image_block_base = $image_block_base_root . '/wp-content/database/.ht.sqlite';
    $image_block_source = $image_block_source_root . '/wp-content/database/.ht.sqlite';
    $image_block_target = $image_block_target_root . '/wp-content/database/.ht.sqlite';
    $image_block_file_base = $tmp . '/.forkpress/cow/merge/file-bases/feature-smoke-page-image-block.json';
    $image_block_metadata = $tmp . '/.forkpress/cow/merge/image-block-metadata.sqlite';

    mkdir(dirname($image_block_base), 0777, true);
    mkdir(dirname($image_block_source), 0777, true);
    mkdir(dirname($image_block_target), 0777, true);
    smoke_create_posts_db($image_block_base);
    copy($image_block_base, $image_block_source);
    copy($image_block_base, $image_block_target);
    cow_merge_capture_file_base($image_block_base_root, $image_block_file_base);

    $source_image_block_content = '<!-- wp:image {"id":18000091,"sizeSlug":"large","linkDestination":"none"} -->' .
        '<figure class="wp-block-image size-large"><img src="http://example.test/wp-content/uploads/2026/05/source-block-image.jpg" alt="" class="wp-image-18000091"/></figure>' .
        '<!-- /wp:image -->';
    $target_image_block_content = '<!-- wp:image {"id":19000091,"sizeSlug":"large","linkDestination":"none"} -->' .
        '<figure class="wp-block-image size-large"><img src="http://example.test/wp-content/uploads/2026/05/main-block-image.jpg" alt="" class="wp-image-19000091"/></figure>' .
        '<!-- /wp:image -->';
    $source_image_block_meta = serialize([
        'file' => '2026/05/source-block-image.jpg',
        'width' => 1024,
        'height' => 768,
        'sizes' => [
            'thumbnail' => [
                'file' => 'source-block-image-150x150.jpg',
                'width' => 150,
                'height' => 150,
                'mime-type' => 'image/jpeg',
            ],
        ],
    ]);
    $target_image_block_meta = serialize([
        'file' => '2026/05/main-block-image.jpg',
        'width' => 1200,
        'height' => 900,
        'sizes' => [
            'thumbnail' => [
                'file' => 'main-block-image-150x150.jpg',
                'width' => 150,
                'height' => 150,
                'mime-type' => 'image/jpeg',
            ],
        ],
    ]);

    $db = smoke_open_db($image_block_source);
    smoke_insert_post($db, 18000090, 'Branch Page With Image Block', $source_image_block_content, 'page', 'branch-page-with-image-block');
    smoke_insert_post($db, 18000091, 'source-block-image.jpg', '', 'attachment', 'source-block-image-jpg', 'inherit', 18000090, 'image/jpeg', 'http://example.test/wp-content/uploads/2026/05/source-block-image.jpg');
    smoke_insert_postmeta($db, 18000092, 18000091, '_wp_attached_file', '2026/05/source-block-image.jpg');
    smoke_insert_postmeta($db, 18000093, 18000091, '_wp_attachment_metadata', $source_image_block_meta);
    $db->close();
    smoke_write_file($image_block_source_root . '/wp-content/uploads/2026/05/source-block-image.jpg', 'source image block original bytes');
    smoke_write_file($image_block_source_root . '/wp-content/uploads/2026/05/source-block-image-150x150.jpg', 'source image block thumbnail bytes');

    $db = smoke_open_db($image_block_target);
    smoke_insert_post($db, 19000090, 'Main Page With Image Block', $target_image_block_content, 'page', 'main-page-with-image-block');
    smoke_insert_post($db, 19000091, 'main-block-image.jpg', '', 'attachment', 'main-block-image-jpg', 'inherit', 19000090, 'image/jpeg', 'http://example.test/wp-content/uploads/2026/05/main-block-image.jpg');
    smoke_insert_postmeta($db, 19000092, 19000091, '_wp_attached_file', '2026/05/main-block-image.jpg');
    smoke_insert_postmeta($db, 19000093, 19000091, '_wp_attachment_metadata', $target_image_block_meta);
    $db->close();
    smoke_write_file($image_block_target_root . '/wp-content/uploads/2026/05/main-block-image.jpg', 'main image block original bytes');
    smoke_write_file($image_block_target_root . '/wp-content/uploads/2026/05/main-block-image-150x150.jpg', 'main image block thumbnail bytes');

    $image_block_result = cow_merge_branch_state(
        $image_block_base,
        $image_block_source,
        $image_block_target,
        $image_block_metadata,
        'feature-smoke-page-image-block',
        'main',
        $image_block_file_base,
        $image_block_source_root,
        $image_block_target_root
    );
    assert_same($image_block_result['status'], 'completed', 'branch and main page-plus-image-block inserts complete cleanly');
    assert_same((int)($image_block_result['conflicts'] ?? -1), 0, 'branch and main page-plus-image-block inserts do not create merge conflicts');
    assert_same(smoke_scalar($image_block_target, 'SELECT post_content FROM wp_posts WHERE ID = 18000090'), $source_image_block_content, 'merged target preserves branch core/image block attachment reference');
    assert_same(smoke_scalar($image_block_target, 'SELECT post_type FROM wp_posts WHERE ID = 18000091'), 'attachment', 'merged target includes the branch image-block attachment row');
    assert_same((int)smoke_scalar($image_block_target, 'SELECT post_parent FROM wp_posts WHERE ID = 18000091'), 18000090, 'merged target keeps the branch image-block attachment parent page');
    assert_same(smoke_scalar($image_block_target, "SELECT meta_value FROM wp_postmeta WHERE post_id = 18000091 AND meta_key = '_wp_attached_file'"), '2026/05/source-block-image.jpg', 'merged target includes branch image-block attached-file metadata');
    assert_same(smoke_scalar($image_block_target, "SELECT meta_value FROM wp_postmeta WHERE post_id = 18000091 AND meta_key = '_wp_attachment_metadata'"), $source_image_block_meta, 'merged target includes branch image-block generated-size metadata');
    assert_same(file_get_contents($image_block_target_root . '/wp-content/uploads/2026/05/source-block-image.jpg'), 'source image block original bytes', 'merged target includes the branch image-block original upload file');
    assert_same(file_get_contents($image_block_target_root . '/wp-content/uploads/2026/05/source-block-image-150x150.jpg'), 'source image block thumbnail bytes', 'merged target includes the branch image-block generated upload file');
    assert_same(smoke_scalar($image_block_target, 'SELECT post_content FROM wp_posts WHERE ID = 19000090'), $target_image_block_content, 'merged target preserves target core/image block attachment reference');
    assert_same(smoke_scalar($image_block_target, 'SELECT post_type FROM wp_posts WHERE ID = 19000091'), 'attachment', 'merged target preserves the main image-block attachment row');
    assert_same(file_get_contents($image_block_target_root . '/wp-content/uploads/2026/05/main-block-image.jpg'), 'main image block original bytes', 'merged target preserves the main image-block original upload file');
    assert_same(file_get_contents($image_block_target_root . '/wp-content/uploads/2026/05/main-block-image-150x150.jpg'), 'main image block thumbnail bytes', 'merged target preserves the main image-block generated upload file');
    assert_same(
        (int)smoke_scalar($image_block_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name IN ('wp_posts', 'wp_postmeta', '__files__')"),
        0,
        'page-plus-image-block smoke merge records no WordPress DB or file conflicts'
    );
    assert_same(
        (int)smoke_scalar($image_block_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'source-applied'"),
        2,
        'page-plus-image-block smoke merge audits the source page and attachment inserts'
    );
    assert_same(
        (int)smoke_scalar($image_block_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_postmeta' AND decision = 'source-applied'"),
        2,
        'page-plus-image-block smoke merge audits the source attachment metadata inserts'
    );
    assert_same(
        (int)smoke_scalar(
            $image_block_metadata,
            "SELECT COUNT(*) FROM merge_decisions WHERE table_name = '__files__' AND decision = 'source-applied' AND row_identity IN ('" .
            SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/2026/05/source-block-image.jpg')) . "', '" .
            SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/2026/05/source-block-image-150x150.jpg')) . "')"
        ),
        2,
        'page-plus-image-block smoke merge audits the source upload files'
    );
    assert_same(
        (int)smoke_scalar($image_block_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_postmeta') AND decision = 'target-kept' AND reason = 'target inserted row and source did not have it'"),
        4,
        'page-plus-image-block smoke merge audits target DB graph inserts'
    );
    assert_same(
        (int)smoke_scalar($image_block_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = '__files__' AND decision = 'target-kept'"),
        2,
        'page-plus-image-block smoke merge audits target upload files'
    );

    $gallery_base_root = $tmp . '/gallery-base-root';
    $gallery_source_root = $tmp . '/gallery-source-root';
    $gallery_target_root = $tmp . '/gallery-target-root';
    $gallery_base = $gallery_base_root . '/wp-content/database/.ht.sqlite';
    $gallery_source = $gallery_source_root . '/wp-content/database/.ht.sqlite';
    $gallery_target = $gallery_target_root . '/wp-content/database/.ht.sqlite';
    $gallery_file_base = $tmp . '/.forkpress/cow/merge/file-bases/feature-smoke-page-gallery.json';
    $gallery_metadata = $tmp . '/.forkpress/cow/merge/gallery-metadata.sqlite';

    mkdir(dirname($gallery_base), 0777, true);
    mkdir(dirname($gallery_source), 0777, true);
    mkdir(dirname($gallery_target), 0777, true);
    smoke_create_posts_db($gallery_base);
    copy($gallery_base, $gallery_source);
    copy($gallery_base, $gallery_target);
    cow_merge_capture_file_base($gallery_base_root, $gallery_file_base);

    $source_gallery_content = '<!-- wp:gallery {"ids":[18000101,18000102],"linkTo":"none"} -->' .
        '<figure class="wp-block-gallery has-nested-images columns-default is-cropped">' .
        '<!-- wp:image {"id":18000101,"sizeSlug":"large","linkDestination":"none"} -->' .
        '<figure class="wp-block-image size-large"><img src="http://example.test/wp-content/uploads/2026/05/source-gallery-a.jpg" alt="" class="wp-image-18000101"/></figure>' .
        '<!-- /wp:image -->' .
        '<!-- wp:image {"id":18000102,"sizeSlug":"large","linkDestination":"none"} -->' .
        '<figure class="wp-block-image size-large"><img src="http://example.test/wp-content/uploads/2026/05/source-gallery-b.jpg" alt="" class="wp-image-18000102"/></figure>' .
        '<!-- /wp:image -->' .
        '</figure><!-- /wp:gallery -->';
    $target_gallery_content = '<!-- wp:gallery {"ids":[19000101,19000102],"linkTo":"none"} -->' .
        '<figure class="wp-block-gallery has-nested-images columns-default is-cropped">' .
        '<!-- wp:image {"id":19000101,"sizeSlug":"large","linkDestination":"none"} -->' .
        '<figure class="wp-block-image size-large"><img src="http://example.test/wp-content/uploads/2026/05/main-gallery-a.jpg" alt="" class="wp-image-19000101"/></figure>' .
        '<!-- /wp:image -->' .
        '<!-- wp:image {"id":19000102,"sizeSlug":"large","linkDestination":"none"} -->' .
        '<figure class="wp-block-image size-large"><img src="http://example.test/wp-content/uploads/2026/05/main-gallery-b.jpg" alt="" class="wp-image-19000102"/></figure>' .
        '<!-- /wp:image -->' .
        '</figure><!-- /wp:gallery -->';
    $source_gallery_a_meta = serialize([
        'file' => '2026/05/source-gallery-a.jpg',
        'width' => 900,
        'height' => 600,
        'sizes' => [
            'thumbnail' => [
                'file' => 'source-gallery-a-150x150.jpg',
                'width' => 150,
                'height' => 150,
                'mime-type' => 'image/jpeg',
            ],
        ],
    ]);
    $source_gallery_b_meta = serialize([
        'file' => '2026/05/source-gallery-b.jpg',
        'width' => 901,
        'height' => 601,
        'sizes' => [
            'thumbnail' => [
                'file' => 'source-gallery-b-150x150.jpg',
                'width' => 150,
                'height' => 150,
                'mime-type' => 'image/jpeg',
            ],
        ],
    ]);
    $target_gallery_a_meta = serialize([
        'file' => '2026/05/main-gallery-a.jpg',
        'width' => 902,
        'height' => 602,
        'sizes' => [
            'thumbnail' => [
                'file' => 'main-gallery-a-150x150.jpg',
                'width' => 150,
                'height' => 150,
                'mime-type' => 'image/jpeg',
            ],
        ],
    ]);
    $target_gallery_b_meta = serialize([
        'file' => '2026/05/main-gallery-b.jpg',
        'width' => 903,
        'height' => 603,
        'sizes' => [
            'thumbnail' => [
                'file' => 'main-gallery-b-150x150.jpg',
                'width' => 150,
                'height' => 150,
                'mime-type' => 'image/jpeg',
            ],
        ],
    ]);

    $db = smoke_open_db($gallery_source);
    smoke_insert_post($db, 18000100, 'Branch Page With Gallery', $source_gallery_content, 'page', 'branch-page-with-gallery');
    smoke_insert_post($db, 18000101, 'source-gallery-a.jpg', '', 'attachment', 'source-gallery-a-jpg', 'inherit', 18000100, 'image/jpeg', 'http://example.test/wp-content/uploads/2026/05/source-gallery-a.jpg');
    smoke_insert_post($db, 18000102, 'source-gallery-b.jpg', '', 'attachment', 'source-gallery-b-jpg', 'inherit', 18000100, 'image/jpeg', 'http://example.test/wp-content/uploads/2026/05/source-gallery-b.jpg');
    smoke_insert_postmeta($db, 18000103, 18000101, '_wp_attached_file', '2026/05/source-gallery-a.jpg');
    smoke_insert_postmeta($db, 18000104, 18000101, '_wp_attachment_metadata', $source_gallery_a_meta);
    smoke_insert_postmeta($db, 18000105, 18000102, '_wp_attached_file', '2026/05/source-gallery-b.jpg');
    smoke_insert_postmeta($db, 18000106, 18000102, '_wp_attachment_metadata', $source_gallery_b_meta);
    $db->close();
    smoke_write_file($gallery_source_root . '/wp-content/uploads/2026/05/source-gallery-a.jpg', 'source gallery a original bytes');
    smoke_write_file($gallery_source_root . '/wp-content/uploads/2026/05/source-gallery-a-150x150.jpg', 'source gallery a thumbnail bytes');
    smoke_write_file($gallery_source_root . '/wp-content/uploads/2026/05/source-gallery-b.jpg', 'source gallery b original bytes');
    smoke_write_file($gallery_source_root . '/wp-content/uploads/2026/05/source-gallery-b-150x150.jpg', 'source gallery b thumbnail bytes');

    $db = smoke_open_db($gallery_target);
    smoke_insert_post($db, 19000100, 'Main Page With Gallery', $target_gallery_content, 'page', 'main-page-with-gallery');
    smoke_insert_post($db, 19000101, 'main-gallery-a.jpg', '', 'attachment', 'main-gallery-a-jpg', 'inherit', 19000100, 'image/jpeg', 'http://example.test/wp-content/uploads/2026/05/main-gallery-a.jpg');
    smoke_insert_post($db, 19000102, 'main-gallery-b.jpg', '', 'attachment', 'main-gallery-b-jpg', 'inherit', 19000100, 'image/jpeg', 'http://example.test/wp-content/uploads/2026/05/main-gallery-b.jpg');
    smoke_insert_postmeta($db, 19000103, 19000101, '_wp_attached_file', '2026/05/main-gallery-a.jpg');
    smoke_insert_postmeta($db, 19000104, 19000101, '_wp_attachment_metadata', $target_gallery_a_meta);
    smoke_insert_postmeta($db, 19000105, 19000102, '_wp_attached_file', '2026/05/main-gallery-b.jpg');
    smoke_insert_postmeta($db, 19000106, 19000102, '_wp_attachment_metadata', $target_gallery_b_meta);
    $db->close();
    smoke_write_file($gallery_target_root . '/wp-content/uploads/2026/05/main-gallery-a.jpg', 'main gallery a original bytes');
    smoke_write_file($gallery_target_root . '/wp-content/uploads/2026/05/main-gallery-a-150x150.jpg', 'main gallery a thumbnail bytes');
    smoke_write_file($gallery_target_root . '/wp-content/uploads/2026/05/main-gallery-b.jpg', 'main gallery b original bytes');
    smoke_write_file($gallery_target_root . '/wp-content/uploads/2026/05/main-gallery-b-150x150.jpg', 'main gallery b thumbnail bytes');

    $gallery_result = cow_merge_branch_state(
        $gallery_base,
        $gallery_source,
        $gallery_target,
        $gallery_metadata,
        'feature-smoke-page-gallery',
        'main',
        $gallery_file_base,
        $gallery_source_root,
        $gallery_target_root
    );
    assert_same($gallery_result['status'], 'completed', 'branch and main page-plus-gallery inserts complete cleanly');
    assert_same((int)($gallery_result['conflicts'] ?? -1), 0, 'branch and main page-plus-gallery inserts do not create merge conflicts');
    assert_same(smoke_scalar($gallery_target, 'SELECT post_content FROM wp_posts WHERE ID = 18000100'), $source_gallery_content, 'merged target preserves branch core/gallery attachment references');
    assert_same(smoke_scalar($gallery_target, 'SELECT post_type FROM wp_posts WHERE ID = 18000101'), 'attachment', 'merged target includes the first branch gallery attachment row');
    assert_same(smoke_scalar($gallery_target, 'SELECT post_type FROM wp_posts WHERE ID = 18000102'), 'attachment', 'merged target includes the second branch gallery attachment row');
    assert_same((int)smoke_scalar($gallery_target, 'SELECT post_parent FROM wp_posts WHERE ID = 18000101'), 18000100, 'merged target keeps first branch gallery attachment parent page');
    assert_same((int)smoke_scalar($gallery_target, 'SELECT post_parent FROM wp_posts WHERE ID = 18000102'), 18000100, 'merged target keeps second branch gallery attachment parent page');
    assert_same(smoke_scalar($gallery_target, "SELECT meta_value FROM wp_postmeta WHERE post_id = 18000101 AND meta_key = '_wp_attached_file'"), '2026/05/source-gallery-a.jpg', 'merged target includes first branch gallery attached-file metadata');
    assert_same(smoke_scalar($gallery_target, "SELECT meta_value FROM wp_postmeta WHERE post_id = 18000102 AND meta_key = '_wp_attached_file'"), '2026/05/source-gallery-b.jpg', 'merged target includes second branch gallery attached-file metadata');
    assert_same(file_get_contents($gallery_target_root . '/wp-content/uploads/2026/05/source-gallery-a.jpg'), 'source gallery a original bytes', 'merged target includes first branch gallery original upload file');
    assert_same(file_get_contents($gallery_target_root . '/wp-content/uploads/2026/05/source-gallery-a-150x150.jpg'), 'source gallery a thumbnail bytes', 'merged target includes first branch gallery generated upload file');
    assert_same(file_get_contents($gallery_target_root . '/wp-content/uploads/2026/05/source-gallery-b.jpg'), 'source gallery b original bytes', 'merged target includes second branch gallery original upload file');
    assert_same(file_get_contents($gallery_target_root . '/wp-content/uploads/2026/05/source-gallery-b-150x150.jpg'), 'source gallery b thumbnail bytes', 'merged target includes second branch gallery generated upload file');
    assert_same(smoke_scalar($gallery_target, 'SELECT post_content FROM wp_posts WHERE ID = 19000100'), $target_gallery_content, 'merged target preserves target core/gallery attachment references');
    assert_same(smoke_scalar($gallery_target, 'SELECT post_type FROM wp_posts WHERE ID = 19000101'), 'attachment', 'merged target preserves first main gallery attachment row');
    assert_same(smoke_scalar($gallery_target, 'SELECT post_type FROM wp_posts WHERE ID = 19000102'), 'attachment', 'merged target preserves second main gallery attachment row');
    assert_same(file_get_contents($gallery_target_root . '/wp-content/uploads/2026/05/main-gallery-a.jpg'), 'main gallery a original bytes', 'merged target preserves first main gallery original upload file');
    assert_same(file_get_contents($gallery_target_root . '/wp-content/uploads/2026/05/main-gallery-a-150x150.jpg'), 'main gallery a thumbnail bytes', 'merged target preserves first main gallery generated upload file');
    assert_same(file_get_contents($gallery_target_root . '/wp-content/uploads/2026/05/main-gallery-b.jpg'), 'main gallery b original bytes', 'merged target preserves second main gallery original upload file');
    assert_same(file_get_contents($gallery_target_root . '/wp-content/uploads/2026/05/main-gallery-b-150x150.jpg'), 'main gallery b thumbnail bytes', 'merged target preserves second main gallery generated upload file');
    assert_same(
        (int)smoke_scalar($gallery_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name IN ('wp_posts', 'wp_postmeta', '__files__')"),
        0,
        'page-plus-gallery smoke merge records no WordPress DB or file conflicts'
    );
    assert_same(
        (int)smoke_scalar($gallery_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'source-applied'"),
        3,
        'page-plus-gallery smoke merge audits the source page and attachment inserts'
    );
    assert_same(
        (int)smoke_scalar($gallery_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_postmeta' AND decision = 'source-applied'"),
        4,
        'page-plus-gallery smoke merge audits the source attachment metadata inserts'
    );
    assert_same(
        (int)smoke_scalar(
            $gallery_metadata,
            "SELECT COUNT(*) FROM merge_decisions WHERE table_name = '__files__' AND decision = 'source-applied' AND row_identity IN ('" .
            SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/2026/05/source-gallery-a.jpg')) . "', '" .
            SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/2026/05/source-gallery-a-150x150.jpg')) . "', '" .
            SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/2026/05/source-gallery-b.jpg')) . "', '" .
            SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/2026/05/source-gallery-b-150x150.jpg')) . "')"
        ),
        4,
        'page-plus-gallery smoke merge audits the source upload files'
    );
    assert_same(
        (int)smoke_scalar($gallery_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_postmeta') AND decision = 'target-kept' AND reason = 'target inserted row and source did not have it'"),
        7,
        'page-plus-gallery smoke merge audits target DB graph inserts'
    );
    assert_same(
        (int)smoke_scalar($gallery_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = '__files__' AND decision = 'target-kept'"),
        4,
        'page-plus-gallery smoke merge audits target upload files'
    );

    $file_block_base_root = $tmp . '/file-block-base-root';
    $file_block_source_root = $tmp . '/file-block-source-root';
    $file_block_target_root = $tmp . '/file-block-target-root';
    $file_block_base = $file_block_base_root . '/wp-content/database/.ht.sqlite';
    $file_block_source = $file_block_source_root . '/wp-content/database/.ht.sqlite';
    $file_block_target = $file_block_target_root . '/wp-content/database/.ht.sqlite';
    $file_block_file_base = $tmp . '/.forkpress/cow/merge/file-bases/feature-smoke-page-file-block.json';
    $file_block_metadata = $tmp . '/.forkpress/cow/merge/file-block-metadata.sqlite';

    mkdir(dirname($file_block_base), 0777, true);
    mkdir(dirname($file_block_source), 0777, true);
    mkdir(dirname($file_block_target), 0777, true);
    smoke_create_posts_db($file_block_base);
    copy($file_block_base, $file_block_source);
    copy($file_block_base, $file_block_target);
    cow_merge_capture_file_base($file_block_base_root, $file_block_file_base);

    $source_file_block_content = '<!-- wp:file {"id":18000121,"href":"http://example.test/wp-content/uploads/2026/05/source-brief.pdf"} -->' .
        '<div class="wp-block-file"><a id="wp-block-file--media-source" href="http://example.test/wp-content/uploads/2026/05/source-brief.pdf">source-brief.pdf</a>' .
        '<a href="http://example.test/wp-content/uploads/2026/05/source-brief.pdf" class="wp-block-file__button wp-element-button" download>Download</a></div>' .
        '<!-- /wp:file -->';
    $target_file_block_content = '<!-- wp:file {"id":19000121,"href":"http://example.test/wp-content/uploads/2026/05/main-brief.pdf"} -->' .
        '<div class="wp-block-file"><a id="wp-block-file--media-main" href="http://example.test/wp-content/uploads/2026/05/main-brief.pdf">main-brief.pdf</a>' .
        '<a href="http://example.test/wp-content/uploads/2026/05/main-brief.pdf" class="wp-block-file__button wp-element-button" download>Main Download</a></div>' .
        '<!-- /wp:file -->';

    $db = smoke_open_db($file_block_source);
    smoke_insert_post($db, 18000120, 'Branch Page With File Block', $source_file_block_content, 'page', 'branch-page-with-file-block');
    smoke_insert_post($db, 18000121, 'source-brief.pdf', '', 'attachment', 'source-brief-pdf', 'inherit', 18000120, 'application/pdf', 'http://example.test/wp-content/uploads/2026/05/source-brief.pdf');
    smoke_insert_postmeta($db, 18000122, 18000121, '_wp_attached_file', '2026/05/source-brief.pdf');
    $db->close();
    smoke_write_file($file_block_source_root . '/wp-content/uploads/2026/05/source-brief.pdf', 'source pdf bytes');

    $db = smoke_open_db($file_block_target);
    smoke_insert_post($db, 19000120, 'Main Page With File Block', $target_file_block_content, 'page', 'main-page-with-file-block');
    smoke_insert_post($db, 19000121, 'main-brief.pdf', '', 'attachment', 'main-brief-pdf', 'inherit', 19000120, 'application/pdf', 'http://example.test/wp-content/uploads/2026/05/main-brief.pdf');
    smoke_insert_postmeta($db, 19000122, 19000121, '_wp_attached_file', '2026/05/main-brief.pdf');
    $db->close();
    smoke_write_file($file_block_target_root . '/wp-content/uploads/2026/05/main-brief.pdf', 'main pdf bytes');

    $file_block_result = cow_merge_branch_state(
        $file_block_base,
        $file_block_source,
        $file_block_target,
        $file_block_metadata,
        'feature-smoke-page-file-block',
        'main',
        $file_block_file_base,
        $file_block_source_root,
        $file_block_target_root
    );
    assert_same($file_block_result['status'], 'completed', 'branch and main page-plus-file-block inserts complete cleanly');
    assert_same((int)($file_block_result['conflicts'] ?? -1), 0, 'branch and main page-plus-file-block inserts do not create merge conflicts');
    assert_same(smoke_scalar($file_block_target, 'SELECT post_content FROM wp_posts WHERE ID = 18000120'), $source_file_block_content, 'merged target preserves branch core/file block attachment reference');
    assert_same(smoke_scalar($file_block_target, 'SELECT post_type FROM wp_posts WHERE ID = 18000121'), 'attachment', 'merged target includes the branch file-block attachment row');
    assert_same((int)smoke_scalar($file_block_target, 'SELECT post_parent FROM wp_posts WHERE ID = 18000121'), 18000120, 'merged target keeps the branch file-block attachment parent page');
    assert_same(smoke_scalar($file_block_target, "SELECT meta_value FROM wp_postmeta WHERE post_id = 18000121 AND meta_key = '_wp_attached_file'"), '2026/05/source-brief.pdf', 'merged target includes branch file-block attached-file metadata');
    assert_same(file_get_contents($file_block_target_root . '/wp-content/uploads/2026/05/source-brief.pdf'), 'source pdf bytes', 'merged target includes the branch file-block upload file');
    assert_same(smoke_scalar($file_block_target, 'SELECT post_content FROM wp_posts WHERE ID = 19000120'), $target_file_block_content, 'merged target preserves target core/file block attachment reference');
    assert_same(smoke_scalar($file_block_target, 'SELECT post_type FROM wp_posts WHERE ID = 19000121'), 'attachment', 'merged target preserves the main file-block attachment row');
    assert_same(file_get_contents($file_block_target_root . '/wp-content/uploads/2026/05/main-brief.pdf'), 'main pdf bytes', 'merged target preserves the main file-block upload file');
    assert_same(
        (int)smoke_scalar($file_block_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name IN ('wp_posts', 'wp_postmeta', '__files__')"),
        0,
        'page-plus-file-block smoke merge records no WordPress DB or file conflicts'
    );
    assert_same(
        (int)smoke_scalar($file_block_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'source-applied'"),
        2,
        'page-plus-file-block smoke merge audits the source page and attachment inserts'
    );
    assert_same(
        (int)smoke_scalar($file_block_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_postmeta' AND decision = 'source-applied'"),
        1,
        'page-plus-file-block smoke merge audits the source attachment metadata insert'
    );
    assert_same(
        (int)smoke_scalar(
            $file_block_metadata,
            "SELECT COUNT(*) FROM merge_decisions WHERE table_name = '__files__' AND decision = 'source-applied' AND row_identity = '" .
            SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/2026/05/source-brief.pdf')) . "'"
        ),
        1,
        'page-plus-file-block smoke merge audits the source upload file'
    );
    assert_same(
        (int)smoke_scalar($file_block_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_postmeta') AND decision = 'target-kept' AND reason = 'target inserted row and source did not have it'"),
        3,
        'page-plus-file-block smoke merge audits target DB graph inserts'
    );
    assert_same(
        (int)smoke_scalar($file_block_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = '__files__' AND decision = 'target-kept'"),
        1,
        'page-plus-file-block smoke merge audits target upload file'
    );

    $media_text_base_root = $tmp . '/media-text-base-root';
    $media_text_source_root = $tmp . '/media-text-source-root';
    $media_text_target_root = $tmp . '/media-text-target-root';
    $media_text_base = $media_text_base_root . '/wp-content/database/.ht.sqlite';
    $media_text_source = $media_text_source_root . '/wp-content/database/.ht.sqlite';
    $media_text_target = $media_text_target_root . '/wp-content/database/.ht.sqlite';
    $media_text_file_base = $tmp . '/.forkpress/cow/merge/file-bases/feature-smoke-page-media-text.json';
    $media_text_metadata = $tmp . '/.forkpress/cow/merge/media-text-metadata.sqlite';

    mkdir(dirname($media_text_base), 0777, true);
    mkdir(dirname($media_text_source), 0777, true);
    mkdir(dirname($media_text_target), 0777, true);
    smoke_create_posts_db($media_text_base);
    copy($media_text_base, $media_text_source);
    copy($media_text_base, $media_text_target);
    cow_merge_capture_file_base($media_text_base_root, $media_text_file_base);

    $source_media_text_content = '<!-- wp:media-text {"mediaId":18000131,"mediaLink":"http://example.test/wp-content/uploads/2026/05/source-media-text.jpg","mediaType":"image"} -->' .
        '<div class="wp-block-media-text is-stacked-on-mobile"><figure class="wp-block-media-text__media"><img src="http://example.test/wp-content/uploads/2026/05/source-media-text.jpg" alt="" class="wp-image-18000131 size-full"/></figure>' .
        '<div class="wp-block-media-text__content"><!-- wp:paragraph --><p>Branch media-text copy</p><!-- /wp:paragraph --></div></div>' .
        '<!-- /wp:media-text -->';
    $target_media_text_content = '<!-- wp:media-text {"mediaId":19000131,"mediaLink":"http://example.test/wp-content/uploads/2026/05/main-media-text.jpg","mediaType":"image"} -->' .
        '<div class="wp-block-media-text is-stacked-on-mobile"><figure class="wp-block-media-text__media"><img src="http://example.test/wp-content/uploads/2026/05/main-media-text.jpg" alt="" class="wp-image-19000131 size-full"/></figure>' .
        '<div class="wp-block-media-text__content"><!-- wp:paragraph --><p>Main media-text copy</p><!-- /wp:paragraph --></div></div>' .
        '<!-- /wp:media-text -->';
    $source_media_text_meta = serialize([
        'file' => '2026/05/source-media-text.jpg',
        'width' => 1280,
        'height' => 720,
        'sizes' => [],
    ]);
    $target_media_text_meta = serialize([
        'file' => '2026/05/main-media-text.jpg',
        'width' => 1280,
        'height' => 720,
        'sizes' => [],
    ]);

    $db = smoke_open_db($media_text_source);
    smoke_insert_post($db, 18000130, 'Branch Page With Media Text', $source_media_text_content, 'page', 'branch-page-with-media-text');
    smoke_insert_post($db, 18000131, 'source-media-text.jpg', '', 'attachment', 'source-media-text-jpg', 'inherit', 18000130, 'image/jpeg', 'http://example.test/wp-content/uploads/2026/05/source-media-text.jpg');
    smoke_insert_postmeta($db, 18000132, 18000131, '_wp_attached_file', '2026/05/source-media-text.jpg');
    smoke_insert_postmeta($db, 18000133, 18000131, '_wp_attachment_metadata', $source_media_text_meta);
    $db->close();
    smoke_write_file($media_text_source_root . '/wp-content/uploads/2026/05/source-media-text.jpg', 'source media text image bytes');

    $db = smoke_open_db($media_text_target);
    smoke_insert_post($db, 19000130, 'Main Page With Media Text', $target_media_text_content, 'page', 'main-page-with-media-text');
    smoke_insert_post($db, 19000131, 'main-media-text.jpg', '', 'attachment', 'main-media-text-jpg', 'inherit', 19000130, 'image/jpeg', 'http://example.test/wp-content/uploads/2026/05/main-media-text.jpg');
    smoke_insert_postmeta($db, 19000132, 19000131, '_wp_attached_file', '2026/05/main-media-text.jpg');
    smoke_insert_postmeta($db, 19000133, 19000131, '_wp_attachment_metadata', $target_media_text_meta);
    $db->close();
    smoke_write_file($media_text_target_root . '/wp-content/uploads/2026/05/main-media-text.jpg', 'main media text image bytes');

    $media_text_result = cow_merge_branch_state(
        $media_text_base,
        $media_text_source,
        $media_text_target,
        $media_text_metadata,
        'feature-smoke-page-media-text',
        'main',
        $media_text_file_base,
        $media_text_source_root,
        $media_text_target_root
    );
    assert_same($media_text_result['status'], 'completed', 'branch and main page-plus-media-text inserts complete cleanly');
    assert_same((int)($media_text_result['conflicts'] ?? -1), 0, 'branch and main page-plus-media-text inserts do not create merge conflicts');
    assert_same(smoke_scalar($media_text_target, 'SELECT post_content FROM wp_posts WHERE ID = 18000130'), $source_media_text_content, 'merged target preserves branch core/media-text block attachment reference');
    assert_same(smoke_scalar($media_text_target, 'SELECT post_type FROM wp_posts WHERE ID = 18000131'), 'attachment', 'merged target includes the branch media-text attachment row');
    assert_same((int)smoke_scalar($media_text_target, 'SELECT post_parent FROM wp_posts WHERE ID = 18000131'), 18000130, 'merged target keeps the branch media-text attachment parent page');
    assert_same(smoke_scalar($media_text_target, "SELECT meta_value FROM wp_postmeta WHERE post_id = 18000131 AND meta_key = '_wp_attached_file'"), '2026/05/source-media-text.jpg', 'merged target includes branch media-text attached-file metadata');
    assert_same(smoke_scalar($media_text_target, "SELECT meta_value FROM wp_postmeta WHERE post_id = 18000131 AND meta_key = '_wp_attachment_metadata'"), $source_media_text_meta, 'merged target includes branch media-text attachment metadata');
    assert_same(file_get_contents($media_text_target_root . '/wp-content/uploads/2026/05/source-media-text.jpg'), 'source media text image bytes', 'merged target includes the branch media-text upload file');
    assert_same(smoke_scalar($media_text_target, 'SELECT post_content FROM wp_posts WHERE ID = 19000130'), $target_media_text_content, 'merged target preserves target core/media-text block attachment reference');
    assert_same(smoke_scalar($media_text_target, 'SELECT post_type FROM wp_posts WHERE ID = 19000131'), 'attachment', 'merged target preserves the main media-text attachment row');
    assert_same(file_get_contents($media_text_target_root . '/wp-content/uploads/2026/05/main-media-text.jpg'), 'main media text image bytes', 'merged target preserves the main media-text upload file');
    assert_same(
        (int)smoke_scalar($media_text_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name IN ('wp_posts', 'wp_postmeta', '__files__')"),
        0,
        'page-plus-media-text smoke merge records no WordPress DB or file conflicts'
    );
    assert_same(
        (int)smoke_scalar($media_text_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'source-applied'"),
        2,
        'page-plus-media-text smoke merge audits the source page and attachment inserts'
    );
    assert_same(
        (int)smoke_scalar($media_text_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_postmeta' AND decision = 'source-applied'"),
        2,
        'page-plus-media-text smoke merge audits the source attachment metadata inserts'
    );
    assert_same(
        (int)smoke_scalar(
            $media_text_metadata,
            "SELECT COUNT(*) FROM merge_decisions WHERE table_name = '__files__' AND decision = 'source-applied' AND row_identity = '" .
            SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/2026/05/source-media-text.jpg')) . "'"
        ),
        1,
        'page-plus-media-text smoke merge audits the source upload file'
    );
    assert_same(
        (int)smoke_scalar($media_text_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_postmeta') AND decision = 'target-kept' AND reason = 'target inserted row and source did not have it'"),
        4,
        'page-plus-media-text smoke merge audits target DB graph inserts'
    );
    assert_same(
        (int)smoke_scalar($media_text_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = '__files__' AND decision = 'target-kept'"),
        1,
        'page-plus-media-text smoke merge audits target upload file'
    );

    $media_refs_base_root = $tmp . '/media-refs-base-root';
    $media_refs_source_root = $tmp . '/media-refs-source-root';
    $media_refs_target_root = $tmp . '/media-refs-target-root';
    $media_refs_base = $media_refs_base_root . '/wp-content/database/.ht.sqlite';
    $media_refs_source = $media_refs_source_root . '/wp-content/database/.ht.sqlite';
    $media_refs_target = $media_refs_target_root . '/wp-content/database/.ht.sqlite';
    $media_refs_file_base = $tmp . '/.forkpress/cow/merge/file-bases/feature-smoke-page-media-refs.json';
    $media_refs_metadata = $tmp . '/.forkpress/cow/merge/media-refs-metadata.sqlite';

    mkdir(dirname($media_refs_base), 0777, true);
    mkdir(dirname($media_refs_source), 0777, true);
    mkdir(dirname($media_refs_target), 0777, true);
    smoke_create_posts_db($media_refs_base);
    copy($media_refs_base, $media_refs_source);
    copy($media_refs_base, $media_refs_target);
    cow_merge_capture_file_base($media_refs_base_root, $media_refs_file_base);

    $media_ref_rows = [
        [
            'branch' => 'source',
            'root' => $media_refs_source_root,
            'db' => $media_refs_source,
            'page_id' => 18000140,
            'attachment_id' => 18000141,
            'meta_id' => 18000142,
            'block' => 'audio',
            'title' => 'Branch Page With Audio Block',
            'slug' => 'branch-page-with-audio-block',
            'file' => '2026/05/source-audio.mp3',
            'mime' => 'audio/mpeg',
            'bytes' => 'source audio bytes',
            'content' => '<!-- wp:audio {"id":18000141} --><figure class="wp-block-audio"><audio controls src="http://example.test/wp-content/uploads/2026/05/source-audio.mp3"></audio></figure><!-- /wp:audio -->',
        ],
        [
            'branch' => 'source',
            'root' => $media_refs_source_root,
            'db' => $media_refs_source,
            'page_id' => 18000150,
            'attachment_id' => 18000151,
            'meta_id' => 18000152,
            'block' => 'cover',
            'title' => 'Branch Page With Cover Block',
            'slug' => 'branch-page-with-cover-block',
            'file' => '2026/05/source-cover.jpg',
            'mime' => 'image/jpeg',
            'bytes' => 'source cover image bytes',
            'content' => '<!-- wp:cover {"url":"http://example.test/wp-content/uploads/2026/05/source-cover.jpg","id":18000151,"dimRatio":50} --><div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span><img class="wp-block-cover__image-background wp-image-18000151" alt="" src="http://example.test/wp-content/uploads/2026/05/source-cover.jpg"/><div class="wp-block-cover__inner-container"><!-- wp:paragraph --><p>Branch cover copy</p><!-- /wp:paragraph --></div></div><!-- /wp:cover -->',
        ],
        [
            'branch' => 'source',
            'root' => $media_refs_source_root,
            'db' => $media_refs_source,
            'page_id' => 18000160,
            'attachment_id' => 18000161,
            'meta_id' => 18000162,
            'block' => 'video',
            'title' => 'Branch Page With Video Block',
            'slug' => 'branch-page-with-video-block',
            'file' => '2026/05/source-video.mp4',
            'mime' => 'video/mp4',
            'bytes' => 'source video bytes',
            'content' => '<!-- wp:video {"id":18000161} --><figure class="wp-block-video"><video controls src="http://example.test/wp-content/uploads/2026/05/source-video.mp4"></video></figure><!-- /wp:video -->',
        ],
        [
            'branch' => 'target',
            'root' => $media_refs_target_root,
            'db' => $media_refs_target,
            'page_id' => 19000140,
            'attachment_id' => 19000141,
            'meta_id' => 19000142,
            'block' => 'audio',
            'title' => 'Main Page With Audio Block',
            'slug' => 'main-page-with-audio-block',
            'file' => '2026/05/main-audio.mp3',
            'mime' => 'audio/mpeg',
            'bytes' => 'main audio bytes',
            'content' => '<!-- wp:audio {"id":19000141} --><figure class="wp-block-audio"><audio controls src="http://example.test/wp-content/uploads/2026/05/main-audio.mp3"></audio></figure><!-- /wp:audio -->',
        ],
        [
            'branch' => 'target',
            'root' => $media_refs_target_root,
            'db' => $media_refs_target,
            'page_id' => 19000150,
            'attachment_id' => 19000151,
            'meta_id' => 19000152,
            'block' => 'cover',
            'title' => 'Main Page With Cover Block',
            'slug' => 'main-page-with-cover-block',
            'file' => '2026/05/main-cover.jpg',
            'mime' => 'image/jpeg',
            'bytes' => 'main cover image bytes',
            'content' => '<!-- wp:cover {"url":"http://example.test/wp-content/uploads/2026/05/main-cover.jpg","id":19000151,"dimRatio":50} --><div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span><img class="wp-block-cover__image-background wp-image-19000151" alt="" src="http://example.test/wp-content/uploads/2026/05/main-cover.jpg"/><div class="wp-block-cover__inner-container"><!-- wp:paragraph --><p>Main cover copy</p><!-- /wp:paragraph --></div></div><!-- /wp:cover -->',
        ],
        [
            'branch' => 'target',
            'root' => $media_refs_target_root,
            'db' => $media_refs_target,
            'page_id' => 19000160,
            'attachment_id' => 19000161,
            'meta_id' => 19000162,
            'block' => 'video',
            'title' => 'Main Page With Video Block',
            'slug' => 'main-page-with-video-block',
            'file' => '2026/05/main-video.mp4',
            'mime' => 'video/mp4',
            'bytes' => 'main video bytes',
            'content' => '<!-- wp:video {"id":19000161} --><figure class="wp-block-video"><video controls src="http://example.test/wp-content/uploads/2026/05/main-video.mp4"></video></figure><!-- /wp:video -->',
        ],
    ];
    foreach ([$media_refs_source, $media_refs_target] as $db_path) {
        $db = smoke_open_db($db_path);
        foreach ($media_ref_rows as $row) {
            if ((string)$row['db'] !== $db_path) {
                continue;
            }
            $guid = 'http://example.test/wp-content/uploads/' . $row['file'];
            smoke_insert_post($db, (int)$row['page_id'], (string)$row['title'], (string)$row['content'], 'page', (string)$row['slug']);
            smoke_insert_post($db, (int)$row['attachment_id'], basename((string)$row['file']), '', 'attachment', str_replace(['.', '/'], '-', basename((string)$row['file'])), 'inherit', (int)$row['page_id'], (string)$row['mime'], $guid);
            smoke_insert_postmeta($db, (int)$row['meta_id'], (int)$row['attachment_id'], '_wp_attached_file', (string)$row['file']);
            smoke_write_file((string)$row['root'] . '/wp-content/uploads/' . $row['file'], (string)$row['bytes']);
        }
        $db->close();
    }

    $media_refs_result = cow_merge_branch_state(
        $media_refs_base,
        $media_refs_source,
        $media_refs_target,
        $media_refs_metadata,
        'feature-smoke-page-media-refs',
        'main',
        $media_refs_file_base,
        $media_refs_source_root,
        $media_refs_target_root
    );
    assert_same($media_refs_result['status'], 'completed', 'branch and main page-plus-audio-cover-video inserts complete cleanly');
    assert_same((int)($media_refs_result['conflicts'] ?? -1), 0, 'branch and main page-plus-audio-cover-video inserts do not create merge conflicts');
    foreach ($media_ref_rows as $row) {
        $owner = (string)$row['branch'] === 'source' ? 'branch' : 'main';
        assert_same(smoke_scalar($media_refs_target, 'SELECT post_content FROM wp_posts WHERE ID = ' . (int)$row['page_id']), (string)$row['content'], "merged target preserves $owner core/{$row['block']} block attachment reference");
        assert_same(smoke_scalar($media_refs_target, 'SELECT post_type FROM wp_posts WHERE ID = ' . (int)$row['attachment_id']), 'attachment', "merged target includes $owner core/{$row['block']} attachment row");
        assert_same((int)smoke_scalar($media_refs_target, 'SELECT post_parent FROM wp_posts WHERE ID = ' . (int)$row['attachment_id']), (int)$row['page_id'], "merged target keeps $owner core/{$row['block']} attachment parent page");
        assert_same(smoke_scalar($media_refs_target, "SELECT meta_value FROM wp_postmeta WHERE post_id = " . (int)$row['attachment_id'] . " AND meta_key = '_wp_attached_file'"), (string)$row['file'], "merged target includes $owner core/{$row['block']} attached-file metadata");
        assert_same(file_get_contents($media_refs_target_root . '/wp-content/uploads/' . $row['file']), (string)$row['bytes'], "merged target includes $owner core/{$row['block']} upload file");
    }
    $source_media_ref_files = array_values(array_map(
        fn(array $row): string => SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/' . $row['file'])),
        array_filter($media_ref_rows, fn(array $row): bool => (string)$row['branch'] === 'source')
    ));
    assert_same(
        (int)smoke_scalar($media_refs_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name IN ('wp_posts', 'wp_postmeta', '__files__')"),
        0,
        'page-plus-audio-cover-video smoke merge records no WordPress DB or file conflicts'
    );
    assert_same(
        (int)smoke_scalar($media_refs_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'source-applied'"),
        6,
        'page-plus-audio-cover-video smoke merge audits the source page and attachment inserts'
    );
    assert_same(
        (int)smoke_scalar($media_refs_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_postmeta' AND decision = 'source-applied'"),
        3,
        'page-plus-audio-cover-video smoke merge audits source attachment metadata inserts'
    );
    assert_same(
        (int)smoke_scalar($media_refs_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = '__files__' AND decision = 'source-applied' AND row_identity IN ('" . implode("', '", $source_media_ref_files) . "')"),
        3,
        'page-plus-audio-cover-video smoke merge audits source upload files'
    );
    assert_same(
        (int)smoke_scalar($media_refs_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name IN ('wp_posts', 'wp_postmeta') AND decision = 'target-kept' AND reason = 'target inserted row and source did not have it'"),
        9,
        'page-plus-audio-cover-video smoke merge audits target DB graph inserts'
    );
    assert_same(
        (int)smoke_scalar($media_refs_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = '__files__' AND decision = 'target-kept'"),
        3,
        'page-plus-audio-cover-video smoke merge audits target upload files'
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

    $front_page_options_base = $tmp . '/front-page-options-base.sqlite';
    $front_page_options_source = $tmp . '/front-page-options-source.sqlite';
    $front_page_options_target = $tmp . '/front-page-options-target.sqlite';
    $front_page_options_metadata = $tmp . '/.forkpress/cow/merge/front-page-options-metadata.sqlite';

    smoke_create_posts_db($front_page_options_base);
    $db = smoke_open_db($front_page_options_base);
    smoke_insert_option($db, 17000220, 'show_on_front', 'page');
    smoke_insert_option($db, 17000221, 'page_on_front', '1');
    smoke_insert_option($db, 17000222, 'page_for_posts', '0');
    $db->close();
    copy($front_page_options_base, $front_page_options_source);
    copy($front_page_options_base, $front_page_options_target);

    $db = smoke_open_db($front_page_options_source);
    smoke_insert_post($db, 18000220, 'Branch Front Page', 'Branch front page content', 'page', 'branch-front-page');
    smoke_insert_post($db, 18000221, 'Branch Posts Page', 'Branch posts page content', 'page', 'branch-posts-page');
    smoke_update_option($db, 'page_on_front', '18000220');
    smoke_update_option($db, 'page_for_posts', '18000221');
    $db->close();

    $db = smoke_open_db($front_page_options_target);
    smoke_insert_post($db, 19000220, 'Main Front Page', 'Main front page content', 'page', 'main-front-page');
    smoke_insert_post($db, 19000221, 'Main Posts Page', 'Main posts page content', 'page', 'main-posts-page');
    smoke_update_option($db, 'page_on_front', '19000220');
    smoke_update_option($db, 'page_for_posts', '19000221');
    $db->close();

    $front_page_options_result = cow_merge_databases($front_page_options_base, $front_page_options_source, $front_page_options_target, $front_page_options_metadata, 'feature-smoke-front-page-options', 'main');
    assert_same($front_page_options_result['status'], 'completed_with_conflicts', 'branch and main front/posts page option disagreement stays reviewable');
    assert_same(smoke_scalar($front_page_options_target, 'SELECT post_title FROM wp_posts WHERE ID = 18000220'), 'Branch Front Page', 'front/posts option conflict still merges the branch front page row');
    assert_same(smoke_scalar($front_page_options_target, 'SELECT post_title FROM wp_posts WHERE ID = 18000221'), 'Branch Posts Page', 'front/posts option conflict still merges the branch posts page row');
    assert_same(smoke_scalar($front_page_options_target, 'SELECT post_title FROM wp_posts WHERE ID = 19000220'), 'Main Front Page', 'front/posts option conflict preserves the main front page row');
    assert_same(smoke_scalar($front_page_options_target, 'SELECT post_title FROM wp_posts WHERE ID = 19000221'), 'Main Posts Page', 'front/posts option conflict preserves the main posts page row');
    assert_same(smoke_scalar($front_page_options_target, "SELECT option_value FROM wp_options WHERE option_name = 'page_on_front'"), '19000220', 'front/posts option conflict keeps target front page option before review');
    assert_same(smoke_scalar($front_page_options_target, "SELECT option_value FROM wp_options WHERE option_name = 'page_for_posts'"), '19000221', 'front/posts option conflict keeps target posts page option before review');
    assert_same(
        (int)smoke_scalar($front_page_options_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_options' AND column_name = 'option_value' AND conflict_type = 'cell-conflict'"),
        2,
        'front/posts option conflict records one option-value conflict per singleton option'
    );
    assert_same(
        (int)smoke_scalar($front_page_options_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_options' AND decision = 'target-wins'"),
        2,
        'front/posts option conflict defaults singleton options to target-wins before review'
    );
    assert_same(
        (int)smoke_scalar($front_page_options_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'source-applied'"),
        2,
        'front/posts option conflict audits the source page inserts'
    );

    $sticky_posts_base = $tmp . '/sticky-posts-base.sqlite';
    $sticky_posts_source = $tmp . '/sticky-posts-source.sqlite';
    $sticky_posts_target = $tmp . '/sticky-posts-target.sqlite';
    $sticky_posts_metadata = $tmp . '/.forkpress/cow/merge/sticky-posts-metadata.sqlite';
    $sticky_posts_base_value = serialize([1]);
    $sticky_posts_source_value = serialize([18000230]);
    $sticky_posts_target_value = serialize([19000230]);

    smoke_create_posts_db($sticky_posts_base);
    $db = smoke_open_db($sticky_posts_base);
    smoke_insert_option($db, 17000230, 'sticky_posts', $sticky_posts_base_value);
    $db->close();
    copy($sticky_posts_base, $sticky_posts_source);
    copy($sticky_posts_base, $sticky_posts_target);

    $db = smoke_open_db($sticky_posts_source);
    smoke_insert_post($db, 18000230, 'Branch Sticky Post', 'Branch sticky post content', 'post', 'branch-sticky-post');
    smoke_update_option($db, 'sticky_posts', $sticky_posts_source_value);
    $db->close();

    $db = smoke_open_db($sticky_posts_target);
    smoke_insert_post($db, 19000230, 'Main Sticky Post', 'Main sticky post content', 'post', 'main-sticky-post');
    smoke_update_option($db, 'sticky_posts', $sticky_posts_target_value);
    $db->close();

    $sticky_posts_result = cow_merge_databases($sticky_posts_base, $sticky_posts_source, $sticky_posts_target, $sticky_posts_metadata, 'feature-smoke-sticky-posts', 'main');
    assert_same($sticky_posts_result['status'], 'completed_with_conflicts', 'branch and main sticky_posts disagreement stays reviewable');
    assert_same(smoke_scalar($sticky_posts_target, 'SELECT post_title FROM wp_posts WHERE ID = 18000230'), 'Branch Sticky Post', 'sticky_posts option conflict still merges the branch sticky post row');
    assert_same(smoke_scalar($sticky_posts_target, 'SELECT post_title FROM wp_posts WHERE ID = 19000230'), 'Main Sticky Post', 'sticky_posts option conflict preserves the main sticky post row');
    assert_same(smoke_scalar($sticky_posts_target, "SELECT option_value FROM wp_options WHERE option_name = 'sticky_posts'"), $sticky_posts_target_value, 'sticky_posts option conflict keeps target serialized post list before review');
    assert_same(
        (int)smoke_scalar($sticky_posts_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_options' AND column_name = 'option_value' AND conflict_type = 'cell-conflict'"),
        1,
        'sticky_posts option conflict records one serialized option-value conflict'
    );
    assert_same(
        (int)smoke_scalar($sticky_posts_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_options' AND decision = 'target-wins'"),
        1,
        'sticky_posts option conflict defaults serialized option to target-wins before review'
    );
    assert_same(
        (int)smoke_scalar($sticky_posts_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND decision = 'source-applied'"),
        1,
        'sticky_posts option conflict audits the source sticky post insert'
    );

    $options_edit_delete_base = $tmp . '/options-edit-delete-base.sqlite';
    $options_edit_delete_source = $tmp . '/options-edit-delete-source.sqlite';
    $options_edit_delete_target = $tmp . '/options-edit-delete-target.sqlite';
    $options_edit_delete_metadata = $tmp . '/.forkpress/cow/merge/options-edit-delete-metadata.sqlite';

    smoke_create_posts_db($options_edit_delete_base);
    $db = smoke_open_db($options_edit_delete_base);
    smoke_insert_option($db, 17000200, 'forkpress_shared_page_json', '{"branch":"base","post_id":1}');
    smoke_insert_option($db, 17000201, 'forkpress_shared_page_serialized', serialize(['branch' => 'base', 'post_id' => 1]));
    $db->close();
    copy($options_edit_delete_base, $options_edit_delete_source);
    copy($options_edit_delete_base, $options_edit_delete_target);

    $db = smoke_open_db($options_edit_delete_source);
    smoke_update_option($db, 'forkpress_shared_page_json', '{"branch":"source","post_id":1,"edited":true}');
    smoke_update_option($db, 'forkpress_shared_page_serialized', serialize(['branch' => 'source', 'post_id' => 1, 'edited' => true]));
    $db->close();

    $db = smoke_open_db($options_edit_delete_target);
    $db->exec("DELETE FROM wp_options WHERE option_name IN ('forkpress_shared_page_json', 'forkpress_shared_page_serialized')");
    $db->close();

    $options_edit_delete_result = cow_merge_databases($options_edit_delete_base, $options_edit_delete_source, $options_edit_delete_target, $options_edit_delete_metadata, 'feature-smoke-options-edit-delete', 'main');
    assert_same($options_edit_delete_result['status'], 'completed_with_conflicts', 'options edit/delete graph stays reviewable');
    assert_same((int)smoke_scalar($options_edit_delete_target, "SELECT COUNT(*) FROM wp_options WHERE option_name IN ('forkpress_shared_page_json', 'forkpress_shared_page_serialized')"), 0, 'options edit/delete preserves target option deletion before review');
    assert_same(
        (int)smoke_scalar($options_edit_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_options' AND conflict_type = 'row-target-deleted'"),
        2,
        'options edit/delete records the edited option delete conflicts'
    );
    assert_same(
        (int)smoke_scalar($options_edit_delete_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_options' AND decision = 'target-wins'"),
        2,
        'options edit/delete defaults the changed source options to target-wins before review'
    );
    $options_contract_audit = cow_merge_audit_report($options_edit_delete_metadata, null, 5, ['records' => 'conflicts']);
    assert_same(count($options_contract_audit['conflicts']), 2, 'conflict audit returns both option edit/delete conflicts');
    $options_contract_conflict_id = (int)$options_contract_audit['conflicts'][0]['id'];
    $options_contract_conflict_key = (string)$options_contract_audit['conflicts'][0]['conflict_key'];
    $unreviewed_filter_audit = cow_merge_audit_report($options_edit_delete_metadata, null, 5, ['lifecycle_state' => 'unreviewed']);
    assert_same($unreviewed_filter_audit['filters']['records'], 'conflicts', 'lifecycle-state filter defaults to conflict records');
    assert_same(count($unreviewed_filter_audit['conflicts']), 2, 'lifecycle-state filter returns unreviewed conflicts');
    $invalid_lifecycle_filter_message = null;
    try {
        cow_merge_audit_report($options_edit_delete_metadata, null, 5, ['records' => 'decisions', 'lifecycle_state' => 'unreviewed']);
    } catch (Throwable $e) {
        $invalid_lifecycle_filter_message = $e->getMessage();
    }
    assert_same(str_contains((string)$invalid_lifecycle_filter_message, '--lifecycle-state can only be combined with --records conflicts or conflict-events'), true, 'lifecycle-state filter rejects non-conflict records');
    foreach ($options_contract_audit['conflicts'] as $contract_conflict) {
        assert_same($contract_conflict['conflict_class'], 'row', 'row delete conflict advertises row class');
        assert_same($contract_conflict['resolution_strategy'], 'row-choice', 'row delete conflict advertises row choice strategy');
        assert_same($contract_conflict['resolution_choices'], ['source', 'target'], 'row delete conflict advertises executable source and target choices');
        assert_same($contract_conflict['generic_resolver'], true, 'row delete conflict advertises generic resolver support');
        assert_same($contract_conflict['after_revalidate_supported'], true, 'row delete conflict advertises after-revalidate support');
        assert_same($contract_conflict['lifecycle_state'], 'unreviewed', 'unreviewed row delete conflict advertises lifecycle state');
        assert_same($contract_conflict['next_action'], 'review', 'unreviewed row delete conflict advertises review as next action');
        assert_same((int)$contract_conflict['resolution_count'], 0, 'unreviewed row delete conflict advertises no resolutions');
        assert_same((int)$contract_conflict['event_count'], 1, 'unreviewed row delete conflict records one lifecycle event');
        assert_same($contract_conflict['latest_event_type'], 'recorded', 'unreviewed row delete conflict advertises recorded event');
        assert_same($contract_conflict['latest_event_lifecycle_state'], 'unreviewed', 'unreviewed row delete conflict advertises event lifecycle state');
    }

    cow_merge_review_record($options_edit_delete_metadata, 'conflict', $options_contract_conflict_id, 'pending', 'Defer option conflict review.', 'cow-smoke');
    $pending_audit = cow_merge_audit_report($options_edit_delete_metadata, null, 5, ['records' => 'conflicts']);
    assert_same($pending_audit['conflicts'][0]['lifecycle_state'], 'deferred', 'pending review note advertises deferred lifecycle state');
    assert_same($pending_audit['conflicts'][0]['next_action'], 'wait', 'pending review note advertises wait next action');
    assert_same((int)$pending_audit['conflicts'][0]['event_count'], 2, 'pending review appends a conflict lifecycle event');
    assert_same($pending_audit['conflicts'][0]['latest_event_type'], 'review-pending', 'pending review advertises latest conflict event');
    assert_same($pending_audit['conflicts'][0]['latest_event_lifecycle_state'], 'deferred', 'pending review advertises latest event lifecycle state');
    $deferred_filter_audit = cow_merge_audit_report($options_edit_delete_metadata, null, 5, ['records' => 'conflicts', 'lifecycle_state' => 'deferred']);
    assert_same(count($deferred_filter_audit['conflicts']), 1, 'lifecycle-state filter returns deferred conflicts');
    assert_same((int)$deferred_filter_audit['conflicts'][0]['id'], $options_contract_conflict_id, 'deferred lifecycle filter returns the reviewed conflict');
    $still_unreviewed_filter_audit = cow_merge_audit_report($options_edit_delete_metadata, null, 5, ['records' => 'conflicts', 'lifecycle_state' => 'unreviewed']);
    assert_same(count($still_unreviewed_filter_audit['conflicts']), 1, 'lifecycle-state filter keeps other conflicts unreviewed');
    assert_same((int)$still_unreviewed_filter_audit['conflicts'][0]['id'] !== $options_contract_conflict_id, true, 'unreviewed lifecycle filter excludes the deferred conflict');

    cow_merge_review_record($options_edit_delete_metadata, 'conflict', $options_contract_conflict_id, 'needs-action', 'Revalidate option conflict before resolving.', 'cow-smoke');
    $needs_action_audit = cow_merge_audit_report($options_edit_delete_metadata, null, 5, ['records' => 'conflicts']);
    assert_same($needs_action_audit['conflicts'][0]['lifecycle_state'], 'needs-action', 'needs-action review note advertises needs-action lifecycle state');
    assert_same($needs_action_audit['conflicts'][0]['next_action'], 'revalidate', 'needs-action row conflict advertises revalidate next action');
    assert_same((int)$needs_action_audit['conflicts'][0]['event_count'], 3, 'needs-action review appends a conflict lifecycle event');
    assert_same($needs_action_audit['conflicts'][0]['latest_event_type'], 'review-needs-action', 'needs-action review advertises latest conflict event');
    assert_same($needs_action_audit['conflicts'][0]['latest_event_lifecycle_state'], 'needs-action', 'needs-action review advertises latest event lifecycle state');
    $needs_action_filter_audit = cow_merge_audit_report($options_edit_delete_metadata, null, 5, ['records' => 'conflicts', 'lifecycle_state' => 'needs-action']);
    assert_same((int)$needs_action_filter_audit['conflicts'][0]['id'], $options_contract_conflict_id, 'lifecycle-state filter returns needs-action conflicts');

    $reviewed_cli = smoke_run_merge_cli([
        'review-record',
        '--metadata-db', $options_edit_delete_metadata,
        '--record', 'conflict',
        '--conflict-key', $options_contract_conflict_key,
        '--run', (string)$options_edit_delete_result['run_id'],
        '--status', 'reviewed',
        '--note', 'Reviewed option conflict.',
        '--reviewer', 'cow-smoke',
    ]);
    assert_same($reviewed_cli['status'], 0, 'review-record CLI records review status by conflict key: ' . $reviewed_cli['output']);
    $reviewed_audit = cow_merge_audit_report($options_edit_delete_metadata, null, 5, ['records' => 'conflicts']);
    assert_same($reviewed_audit['conflicts'][0]['lifecycle_state'], 'reviewed', 'reviewed conflict advertises reviewed lifecycle state');
    assert_same($reviewed_audit['conflicts'][0]['next_action'], 'resolve', 'reviewed generic conflict advertises resolve next action');
    assert_same((int)$reviewed_audit['conflicts'][0]['event_count'], 4, 'reviewed note appends a conflict lifecycle event');
    assert_same($reviewed_audit['conflicts'][0]['latest_event_type'], 'review-reviewed', 'reviewed conflict advertises latest conflict event');
    assert_same($reviewed_audit['conflicts'][0]['latest_event_lifecycle_state'], 'reviewed', 'reviewed conflict advertises latest event lifecycle state');
    $reviewed_filter_audit = cow_merge_audit_report($options_edit_delete_metadata, null, 5, ['records' => 'conflicts', 'lifecycle_state' => 'reviewed']);
    assert_same((int)$reviewed_filter_audit['conflicts'][0]['id'], $options_contract_conflict_id, 'lifecycle-state filter returns reviewed conflicts');

    $validated_resolution = cow_merge_resolve_conflict($options_edit_delete_metadata, $options_contract_conflict_id, 'target', false, 'Validate target option deletion.', 'cow-smoke');
    assert_same((int)($validated_resolution['resolution_id'] ?? 0) > 0, true, 'validation-only resolution records a durable resolution id');
    assert_same($validated_resolution['status'], 'validated', 'validation-only resolution reports validated status');
    assert_same($validated_resolution['applied'], false, 'validation-only resolution does not apply target state');
    $validated_audit = cow_merge_audit_report($options_edit_delete_metadata, null, 5, ['records' => 'conflicts']);
    assert_same($validated_audit['conflicts'][0]['lifecycle_state'], 'validated', 'validation-only resolution advertises validated lifecycle state');
    assert_same($validated_audit['conflicts'][0]['next_action'], 'apply-reviewed-choice', 'validation-only resolution advertises apply as next action');
    assert_same((int)$validated_audit['conflicts'][0]['resolution_count'], 1, 'validation-only resolution increments conflict resolution count');
    assert_same((int)$validated_audit['conflicts'][0]['latest_resolution_applied'], 0, 'validation-only resolution records unapplied resolution');
    assert_same($validated_audit['conflicts'][0]['latest_event_type'], 'resolution-validated', 'validation-only resolution advertises latest validation event');
    assert_same($validated_audit['conflicts'][0]['latest_event_lifecycle_state'], 'validated', 'validation-only resolution advertises latest event lifecycle state');
    $validated_filter_audit = cow_merge_audit_report($options_edit_delete_metadata, null, 5, ['records' => 'conflicts', 'lifecycle_state' => 'validated']);
    assert_same((int)$validated_filter_audit['conflicts'][0]['id'], $options_contract_conflict_id, 'lifecycle-state filter returns validated conflicts');

    $apply_reviewed_cli = smoke_run_merge_cli([
        'resolve-conflict',
        '--metadata-db', $options_edit_delete_metadata,
        '--conflict-key', $options_contract_conflict_key,
        '--run', (string)$options_edit_delete_result['run_id'],
        '--apply-reviewed',
        '--note', 'Keep target option deletion.',
        '--reviewer', 'cow-smoke',
    ]);
    assert_same($apply_reviewed_cli['status'], 0, 'apply-reviewed CLI applies the latest validated choice by conflict key: ' . $apply_reviewed_cli['output']);
    assert_same(str_contains($apply_reviewed_cli['output'], 'choice:    target'), true, 'apply-reviewed CLI reports the validated target choice');
    $resolved_audit = cow_merge_audit_report($options_edit_delete_metadata, null, 5, ['records' => 'conflicts']);
    assert_same($resolved_audit['conflicts'][0]['lifecycle_state'], 'resolved', 'applied resolution advertises resolved lifecycle state');
    assert_same($resolved_audit['conflicts'][0]['next_action'], 'none', 'applied resolution advertises no next action');
    assert_same((int)$resolved_audit['conflicts'][0]['resolution_count'], 2, 'applied resolution increments conflict resolution count');
    assert_same($resolved_audit['conflicts'][0]['latest_resolution_choice'], 'target', 'applied resolution advertises latest resolution choice');
    assert_same((int)$resolved_audit['conflicts'][0]['latest_resolution_applied'], 1, 'applied resolution advertises latest resolution applied flag');
    assert_same($resolved_audit['conflicts'][0]['latest_resolution_status'], 'applied', 'applied target resolution advertises applied status');
    assert_same((int)$resolved_audit['conflicts'][0]['event_count'], 6, 'applied resolution appends a conflict lifecycle event');
    assert_same($resolved_audit['conflicts'][0]['latest_event_type'], 'resolution-applied', 'resolved conflict advertises latest resolution event');
    assert_same($resolved_audit['conflicts'][0]['latest_event_lifecycle_state'], 'resolved', 'resolved conflict advertises latest event lifecycle state');
    assert_same($resolved_audit['conflicts'][0]['latest_event_actor'], 'cow-smoke', 'resolved conflict advertises latest event actor');
    $resolved_filter_audit = cow_merge_audit_report($options_edit_delete_metadata, null, 5, ['records' => 'conflicts', 'lifecycle_state' => 'resolved']);
    assert_same((int)$resolved_filter_audit['conflicts'][0]['id'], $options_contract_conflict_id, 'lifecycle-state filter returns resolved conflicts');
    $lifecycle_group_audit = cow_merge_audit_report($options_edit_delete_metadata, null, 5, ['records' => 'conflicts', 'group_by' => 'lifecycle']);
    $lifecycle_counts = [];
    foreach ($lifecycle_group_audit['conflict_groups'] as $group) {
        $lifecycle_counts[$group['group_key']] = (int)$group['conflict_count'];
    }
    assert_same($lifecycle_counts['resolved'] ?? 0, 1, 'lifecycle grouping counts resolved conflicts');
    assert_same($lifecycle_counts['unreviewed'] ?? 0, 1, 'lifecycle grouping counts still-unreviewed conflicts');
    $resolved_regression_message = null;
    try {
        cow_merge_resolve_conflict(
            $options_edit_delete_metadata,
            $options_contract_conflict_id,
            'target',
            false,
            'Do not reopen a resolved conflict.',
            'cow-smoke'
        );
    } catch (Throwable $e) {
        $resolved_regression_message = $e->getMessage();
    }
    assert_same(str_contains((string)$resolved_regression_message, 'already resolved'), true, 'resolved conflicts reject later validation-only resolutions');
    $after_resolved_regression_audit = cow_merge_audit_report($options_edit_delete_metadata, null, 5, ['records' => 'conflicts']);
    assert_same($after_resolved_regression_audit['conflicts'][0]['lifecycle_state'], 'resolved', 'rejected resolved-conflict validation keeps resolved lifecycle state');
    assert_same((int)$after_resolved_regression_audit['conflicts'][0]['resolution_count'], 2, 'rejected resolved-conflict validation does not append resolution records');
    $event_audit = cow_merge_audit_report($options_edit_delete_metadata, null, 6, ['records' => 'conflict-events']);
    assert_same(count($event_audit['conflict_events']), 6, 'conflict event audit returns the selected conflict lifecycle history');
    assert_same(
        array_column($event_audit['conflict_events'], 'event_type'),
        ['resolution-applied', 'resolution-validated', 'review-reviewed', 'review-needs-action', 'review-pending', 'recorded'],
        'conflict event audit returns lifecycle events newest first'
    );
    assert_same(
        count(array_unique(array_map('intval', array_column($event_audit['conflict_events'], 'conflict_id')))),
        1,
        'conflict event audit can be limited to one conflict history'
    );
    $resolved_event_audit = cow_merge_audit_report($options_edit_delete_metadata, null, 6, ['records' => 'conflict-events', 'lifecycle_state' => 'resolved']);
    assert_same(count($resolved_event_audit['conflict_events']), 1, 'lifecycle-state filter returns matching conflict lifecycle events');
    assert_same($resolved_event_audit['conflict_events'][0]['event_type'], 'resolution-applied', 'resolved lifecycle event filter returns the applied-resolution event');
    assert_same((int)$resolved_event_audit['conflict_events'][0]['conflict_id'], $options_contract_conflict_id, 'resolved lifecycle event filter keeps the selected conflict id');
    assert_same(
        str_contains(cow_merge_audit_filter_label($resolved_event_audit['filters']), 'lifecycle-state=resolved'),
        true,
        'lifecycle-state filter is visible in text audit filters'
    );
    assert_same((int)$event_audit['conflict_events'][0]['conflict_id'], $options_contract_conflict_id, 'conflict event audit exposes the conflict id');
    assert_same($event_audit['conflict_events'][0]['table_name'], 'wp_options', 'conflict event audit exposes the conflict table');
    assert_same($event_audit['conflict_events'][0]['conflict_type'], 'row-target-deleted', 'conflict event audit exposes the conflict type');

    $fk_blocked_base = $tmp . '/fk-blocked-source-base.sqlite';
    $fk_blocked_source = $tmp . '/fk-blocked-source-source.sqlite';
    $fk_blocked_target = $tmp . '/fk-blocked-source-target.sqlite';
    $fk_blocked_metadata = $tmp . '/.forkpress/cow/merge/fk-blocked-source-metadata.sqlite';

    smoke_create_posts_db($fk_blocked_base);
    $db = smoke_open_db($fk_blocked_base);
    $db->exec('CREATE TABLE plugin_smoke_fk_parents (id INTEGER PRIMARY KEY, label TEXT)');
    $db->exec('CREATE TABLE plugin_smoke_fk_children (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL REFERENCES plugin_smoke_fk_parents(id), label TEXT)');
    $db->exec("INSERT INTO plugin_smoke_fk_parents (id, label) VALUES (1, 'base parent')");
    $db->close();
    copy($fk_blocked_base, $fk_blocked_source);
    copy($fk_blocked_base, $fk_blocked_target);

    $db = smoke_open_db($fk_blocked_source);
    $db->exec('DELETE FROM plugin_smoke_fk_parents WHERE id = 1');
    $db->close();

    $db = smoke_open_db($fk_blocked_target);
    $db->exec("UPDATE plugin_smoke_fk_parents SET label = 'target parent edit' WHERE id = 1");
    $db->exec("INSERT INTO plugin_smoke_fk_children (id, parent_id, label) VALUES (20, 1, 'target child blocks source delete')");
    $db->close();

    $fk_blocked_result = cow_merge_databases($fk_blocked_base, $fk_blocked_source, $fk_blocked_target, $fk_blocked_metadata, 'feature-smoke-fk-blocked-source', 'main');
    assert_same($fk_blocked_result['status'], 'completed_with_conflicts', 'foreign-key smoke source delete is held for review while target has children');
    assert_same((int)smoke_scalar($fk_blocked_target, 'SELECT COUNT(*) FROM plugin_smoke_fk_parents WHERE id = 1'), 1, 'foreign-key smoke keeps target parent before source delete review');
    $fk_blocked_conflict_id = (int)smoke_scalar($fk_blocked_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_smoke_fk_parents' AND conflict_type = 'row-source-deleted' ORDER BY id DESC LIMIT 1");
    $fk_blocked_audit = cow_merge_audit_report($fk_blocked_metadata, (int)$fk_blocked_result['run_id'], 10, ['records' => 'conflicts']);
    $fk_blocked_audit_rows = [];
    foreach ($fk_blocked_audit['conflicts'] as $row) {
        $fk_blocked_audit_rows[(int)$row['id']] = $row;
    }
    assert_same(
        $fk_blocked_audit_rows[$fk_blocked_conflict_id]['resolution_choices'],
        ['target'],
        'foreign-key smoke audit hides source while target children still reference the row'
    );
    assert_same(
        str_contains((string)($fk_blocked_audit_rows[$fk_blocked_conflict_id]['blocked_resolution_choices']['source'] ?? ''), 'referenced by plugin_smoke_fk_children(parent_id)'),
        true,
        'foreign-key smoke audit explains the target child blocker'
    );
    $fk_blocked_source_error = null;
    try {
        cow_merge_resolve_conflict($fk_blocked_metadata, $fk_blocked_conflict_id, 'source', true, 'Try blocked source delete.', 'cow-smoke');
    } catch (Throwable $e) {
        $fk_blocked_source_error = $e->getMessage();
    }
    assert_same(
        str_contains((string)$fk_blocked_source_error, 'resolution choice source is blocked'),
        true,
        'foreign-key smoke resolver rejects blocked source before mutation'
    );
    assert_same((int)smoke_scalar($fk_blocked_target, 'SELECT COUNT(*) FROM plugin_smoke_fk_parents WHERE id = 1'), 1, 'foreign-key smoke blocked source leaves target parent untouched');

    $db = smoke_open_db($fk_blocked_target);
    $db->exec('DELETE FROM plugin_smoke_fk_children WHERE parent_id = 1');
    $db->close();
    $fk_unblocked_audit = cow_merge_audit_report($fk_blocked_metadata, (int)$fk_blocked_result['run_id'], 10, ['records' => 'conflicts']);
    $fk_unblocked_audit_rows = [];
    foreach ($fk_unblocked_audit['conflicts'] as $row) {
        $fk_unblocked_audit_rows[(int)$row['id']] = $row;
    }
    assert_same(
        $fk_unblocked_audit_rows[$fk_blocked_conflict_id]['resolution_choices'],
        ['source', 'target'],
        'foreign-key smoke audit advertises source after target children are removed'
    );
    $fk_source_resolution = cow_merge_resolve_conflict($fk_blocked_metadata, $fk_blocked_conflict_id, 'source', true, 'Apply source delete after child review.', 'cow-smoke');
    assert_same($fk_source_resolution['status'], 'applied', 'foreign-key smoke applies source after blockers are cleared');
    assert_same((int)smoke_scalar($fk_blocked_target, 'SELECT COUNT(*) FROM plugin_smoke_fk_parents WHERE id = 1'), 0, 'foreign-key smoke source delete removes the parent after review');

    $plugin_contract = cow_merge_conflict_resolution_contract('__plugins__', 'plugin-demo-finding');
    assert_same($plugin_contract['class'], 'plugin', 'plugin conflicts advertise plugin class');
    assert_same($plugin_contract['strategy'], 'plugin-validator', 'plugin conflicts require validator strategy');
    assert_same($plugin_contract['choices'], [], 'plugin conflicts do not advertise generic source/target choices');
    assert_same($plugin_contract['generic_resolver'], false, 'plugin conflicts do not advertise generic resolver support');

    $schema_contract = cow_merge_conflict_resolution_contract('plugin_items', 'schema-source-added-view');
    assert_same($schema_contract['class'], 'schema', 'schema conflicts advertise schema class');
    assert_same($schema_contract['choices'], ['source', 'target'], 'schema conflicts advertise source and target choices');
    assert_same($schema_contract['blocked_choices'], [], 'schema conflicts advertise no blocked choices by default');
    assert_same($schema_contract['after_revalidate'], false, 'schema conflicts do not advertise after-revalidate support yet');
} finally {
    smoke_remove_tree($tmp);
}

if ($fail > 0) {
    echo "COW merge smoke tests failed ($fail failures, $pass passes).\n";
    exit(1);
}

echo "COW merge smoke tests passed ($pass assertions).\n";

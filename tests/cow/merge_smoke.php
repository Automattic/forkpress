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

function smoke_insert_comment(SQLite3 $db, int $id, int $post_id, int $user_id, string $author, string $content): void {
    $stmt = $db->prepare('INSERT INTO wp_comments (comment_ID, comment_post_ID, user_id, comment_author, comment_content) VALUES (:id, :post_id, :user_id, :author, :content)');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':post_id', $post_id, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(':author', $author, SQLITE3_TEXT);
    $stmt->bindValue(':content', $content, SQLITE3_TEXT);
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

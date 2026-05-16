<?php

$pass = 0;
$fail = 0;

function assert_true($cond, $msg) {
    global $pass, $fail;
    if ($cond) {
        echo "  PASS: $msg\n";
        $pass++;
    } else {
        echo "  FAIL: $msg\n";
        $fail++;
    }
}

function assert_same($actual, $expected, $msg) {
    assert_true(
        $actual === $expected,
        "$msg (got " . var_export($actual, true) . ", expected " . var_export($expected, true) . ")"
    );
}

function remove_tree(string $path): void {
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

function copy_tree_for_test(string $source, string $dest): void {
    mkdir($dest, 0777, true);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $entry) {
        $target = $dest . '/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($source) + 1));
        if ($entry->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0777, true);
            }
            continue;
        }
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        copy($entry->getPathname(), $target);
    }
}

function write_test_file(string $path, string $contents): void {
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);
}

function open_db(string $path): SQLite3 {
    $db = new SQLite3($path);
    $db->busyTimeout(5000);
    return $db;
}

function scalar(string $db_path, string $sql): mixed {
    $db = open_db($db_path);
    $value = $db->querySingle($sql);
    $db->close();
    return $value;
}

function create_wp_semantic_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT ''
    )");
    $db->exec('CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)');
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (30, 'Shared reusable block', '<!-- wp:paragraph --><p>Shared block</p><!-- /wp:paragraph -->', 'publish', 'wp_block', 'shared-reusable-block'),
        (31, 'Page with reusable block', '<!-- wp:block {\"ref\":30} /--><!-- wp:paragraph --><p>Base page content</p><!-- /wp:paragraph -->', 'publish', 'page', 'page-with-reusable-block'),
        (32, 'Shared synced pattern', '<!-- wp:paragraph --><p>Shared synced pattern</p><!-- /wp:paragraph -->', 'publish', 'wp_block', 'shared-synced-pattern'),
        (33, 'Page with synced pattern', '<!-- wp:block {\"ref\":32} /--><!-- wp:paragraph --><p>Base synced pattern content</p><!-- /wp:paragraph -->', 'publish', 'page', 'page-with-synced-pattern')");
    $db->exec("INSERT INTO wp_postmeta (meta_id, post_id, meta_key, meta_value) VALUES (34, 32, 'wp_pattern_sync_status', 'synced')");
    $db->close();
}

function create_wp_menu_ref_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT ''
    )");
    $db->exec('CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)');
    $db->exec('CREATE TABLE wp_terms (term_id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL)');
    $db->exec('CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY AUTOINCREMENT, term_id INTEGER NOT NULL, taxonomy TEXT NOT NULL, description TEXT NOT NULL DEFAULT "", parent INTEGER NOT NULL DEFAULT 0, count INTEGER NOT NULL DEFAULT 0)');
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (40, 'Shared menu page', '<!-- wp:paragraph --><p>Menu page</p><!-- /wp:paragraph -->', 'publish', 'page', 'shared-menu-page'),
        (41, 'Menu item for shared page', '', 'publish', 'nav_menu_item', 'menu-item-shared-page'),
        (42, 'Menu item for shared category', '', 'publish', 'nav_menu_item', 'menu-item-shared-category')");
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES (43, 'Shared menu category', 'shared-menu-category')");
    $db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES (43, 43, 'category', '', 0, 0)");
    $db->exec("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES
        (41, '_menu_item_type', 'post_type'),
        (41, '_menu_item_object', 'page'),
        (41, '_menu_item_object_id', '40'),
        (41, '_menu_item_menu_item_parent', '0'),
        (42, '_menu_item_type', 'taxonomy'),
        (42, '_menu_item_object', 'category'),
        (42, '_menu_item_object_id', '43'),
        (42, '_menu_item_menu_item_parent', '0')");
    $db->close();
}

function create_wp_featured_media_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT '',
        guid TEXT NOT NULL DEFAULT ''
    )");
    $db->exec('CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)');
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name, guid) VALUES
        (60, 'Featured media page', '<!-- wp:paragraph --><p>Featured media page</p><!-- /wp:paragraph -->', 'publish', 'page', 'featured-media-page', ''),
        (61, 'Featured attachment', '', 'inherit', 'attachment', 'featured-attachment', 'wp-content/uploads/2026/05/featured-image.jpg')");
    $db->exec("INSERT INTO wp_postmeta (meta_id, post_id, meta_key, meta_value) VALUES (6000, 60, '_thumbnail_id', '61')");
    $db->close();
}

function create_wp_image_block_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT '',
        guid TEXT NOT NULL DEFAULT ''
    )");
    $image_block_content = '<!-- wp:image {"id":71,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="wp-content/uploads/2026/05/block-image.jpg" class="wp-image-71"/></figure><!-- /wp:image -->';
    $stmt = $db->prepare("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name, guid) VALUES
        (70, 'Image block page', :content, 'publish', 'page', 'image-block-page', ''),
        (71, 'Image block attachment', '', 'inherit', 'attachment', 'block-image', 'wp-content/uploads/2026/05/block-image.jpg')");
    $stmt->bindValue(':content', $image_block_content, SQLITE3_TEXT);
    $stmt->execute();
    $db->close();
}

function create_wp_term_relationship_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT ''
    )");
    $db->exec('CREATE TABLE wp_terms (term_id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL, term_group INTEGER NOT NULL DEFAULT 0)');
    $db->exec('CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY AUTOINCREMENT, term_id INTEGER NOT NULL, taxonomy TEXT NOT NULL, description TEXT NOT NULL DEFAULT "", parent INTEGER NOT NULL DEFAULT 0, count INTEGER NOT NULL DEFAULT 0)');
    $db->exec('CREATE TABLE wp_term_relationships (object_id INTEGER NOT NULL, term_taxonomy_id INTEGER NOT NULL, term_order INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (object_id, term_taxonomy_id))');
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (80, 'Term relationship page', '<!-- wp:paragraph --><p>Term relationship page</p><!-- /wp:paragraph -->', 'publish', 'page', 'term-relationship-page')");
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES (81, 'Retired category', 'retired-category')");
    $db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, count) VALUES (82, 81, 'category', '', 0)");
    $db->close();
}

function create_wp_comment_reference_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("CREATE TABLE wp_users (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        user_login TEXT NOT NULL,
        user_email TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("CREATE TABLE wp_comments (
        comment_ID INTEGER PRIMARY KEY AUTOINCREMENT,
        comment_post_ID INTEGER NOT NULL DEFAULT 0,
        comment_content TEXT NOT NULL DEFAULT '',
        comment_parent INTEGER NOT NULL DEFAULT 0,
        user_id INTEGER NOT NULL DEFAULT 0
    )");
    $db->exec('CREATE TABLE wp_commentmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, comment_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)');
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (120, 'Deleted comment host page', '<!-- wp:paragraph --><p>Comment host</p><!-- /wp:paragraph -->', 'publish', 'page', 'deleted-comment-host-page'),
        (127, 'Surviving comment host page', '<!-- wp:paragraph --><p>Surviving host</p><!-- /wp:paragraph -->', 'publish', 'page', 'surviving-comment-host-page')");
    $db->exec("INSERT INTO wp_users (ID, user_login, user_email) VALUES (121, 'deleted_comment_author', 'deleted-comment-author@example.test')");
    $db->exec("INSERT INTO wp_comments (comment_ID, comment_post_ID, comment_content, comment_parent, user_id) VALUES
        (122, 120, 'Base comment pointing at deleted post and user', 0, 121),
        (123, 127, 'Base comment with metadata', 0, 0),
        (125, 127, 'Base parent comment', 0, 0),
        (126, 127, 'Base child comment', 125, 0)");
    $db->exec("INSERT INTO wp_commentmeta (meta_id, comment_id, meta_key, meta_value) VALUES (124, 123, '_forkpress_comment_note', 'base comment metadata')");
    $db->close();
}

function create_wp_option_reference_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT '',
        guid TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("CREATE TABLE wp_options (
        option_id INTEGER PRIMARY KEY AUTOINCREMENT,
        option_name TEXT NOT NULL,
        option_value TEXT NOT NULL,
        autoload TEXT NOT NULL DEFAULT 'yes'
    )");
    $db->exec('CREATE TABLE wp_terms (term_id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL)');
    $db->exec('CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY AUTOINCREMENT, term_id INTEGER NOT NULL, taxonomy TEXT NOT NULL, description TEXT NOT NULL DEFAULT "", parent INTEGER NOT NULL DEFAULT 0, count INTEGER NOT NULL DEFAULT 0)');
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name, guid) VALUES
        (90, 'Featured option page', '<!-- wp:paragraph --><p>Featured option page</p><!-- /wp:paragraph -->', 'publish', 'page', 'featured-option-page', ''),
        (91, 'Primary posts page', '<!-- wp:paragraph --><p>Primary posts page</p><!-- /wp:paragraph -->', 'publish', 'page', 'primary-posts-page', ''),
        (92, 'Sticky option post', '<!-- wp:paragraph --><p>Sticky option post</p><!-- /wp:paragraph -->', 'publish', 'post', 'sticky-option-post', ''),
        (93, 'Option logo', '', 'inherit', 'attachment', 'option-logo', 'http://example.test/wp-content/uploads/2026/05/option-logo.jpg')");
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES (94, 'Primary menu', 'primary-menu')");
    $db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES (94, 94, 'nav_menu', '', 0, 1)");

    $theme_mods = serialize([
        'forkpress_featured_page' => 90,
        'nav_menu_locations' => [
            'primary' => 94,
        ],
        'custom_logo' => 93,
        'forkpress_accent' => 'base',
    ]);
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('theme_mods_forkpress_active', :value, 'yes')");
    $stmt->bindValue(':value', $theme_mods, SQLITE3_TEXT);
    $stmt->execute();
    $db->exec("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('site_icon', '93', 'yes')");
    $db->exec("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('page_on_front', '90', 'yes')");
    $db->exec("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('page_for_posts', '91', 'yes')");

    $sticky_posts = serialize([92]);
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('sticky_posts', :value, 'yes')");
    $stmt->bindValue(':value', $sticky_posts, SQLITE3_TEXT);
    $stmt->execute();

    $widget_nav_menu = serialize([
        2 => [
            'title' => 'Footer menu',
            'nav_menu' => 94,
        ],
        '_multiwidget' => 1,
    ]);
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('widget_nav_menu', :value, 'yes')");
    $stmt->bindValue(':value', $widget_nav_menu, SQLITE3_TEXT);
    $stmt->execute();

    $widget_media_image = serialize([
        3 => [
            'attachment_id' => 93,
            'url' => 'http://example.test/wp-content/uploads/2026/05/option-logo.jpg',
            'caption' => 'Base media image widget',
        ],
        '_multiwidget' => 1,
    ]);
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('widget_media_image', :value, 'yes')");
    $stmt->bindValue(':value', $widget_media_image, SQLITE3_TEXT);
    $stmt->execute();

    $widget_text = serialize([
        4 => [
            'title' => 'Base text widget',
            'text' => 'Base sidebar text',
        ],
        '_multiwidget' => 1,
    ]);
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('widget_text', :value, 'yes')");
    $stmt->bindValue(':value', $widget_text, SQLITE3_TEXT);
    $stmt->execute();

    $sidebars_widgets = serialize([
        'sidebar-1' => ['nav_menu-2', 'media_image-3', 'text-4'],
        'array_version' => 3,
    ]);
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('sidebars_widgets', :value, 'yes')");
    $stmt->bindValue(':value', $sidebars_widgets, SQLITE3_TEXT);
    $stmt->execute();
    $db->close();
}

define('FORKPRESS_COW_MERGE_TESTS', true);
require_once __DIR__ . '/../../scripts/cow/merge.php';

echo "=== COW WordPress semantic validator focused tests ===\n";

$tmp = sys_get_temp_dir() . '/forkpress-cow-wp-semantic-validator-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);

try {
    $base_root = $tmp . '/base';
    $source_root = $tmp . '/source';
    $target_root = $tmp . '/target';
    $base = $base_root . '/wp-content/database/.ht.sqlite';
    $source = $source_root . '/wp-content/database/.ht.sqlite';
    $target = $target_root . '/wp-content/database/.ht.sqlite';
    $metadata = $tmp . '/.forkpress/cow/merge/wp-semantic-validator-metadata.sqlite';
    $file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-semantic-validator.json';

    mkdir($base_root . '/wp-content/database', 0777, true);
    create_wp_semantic_db($base);
    write_test_file($base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT ID, post_content FROM wp_posts WHERE post_type IN ('page', 'post', 'wp_template_part', 'wp_template')");
$findings = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $content = (string)$row['post_content'];
    if (!preg_match_all('/<!--\s+wp:block\s+\{[^}]*"ref"\s*:\s*(\d+)/', $content, $matches)) {
        continue;
    }
    foreach ($matches[1] as $ref) {
        $ref_id = (int)$ref;
        $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $ref_id AND post_type = 'wp_block'");
        if ($exists === 0) {
            $findings[] = [
                'plugin' => 'forkpress-wp-block-refs',
                'object' => 'post:' . $row['ID'],
                'reason' => 'post content references a missing reusable block',
                'type' => 'plugin-wp-block-missing-reference',
                'tables' => ['wp_posts'],
                'validator' => 'forkpress-wp-block-refs@1',
                'candidate' => [
                    'post_id' => (int)$row['ID'],
                    'missing_ref' => $ref_id,
                ],
            ];
        }
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);

    copy_tree_for_test($base_root, $source_root);
    copy_tree_for_test($base_root, $target_root);
    cow_merge_capture_file_base($base_root, $file_base);
    cow_merge_allocate_autoincrement_bands($source, $metadata, 'feature-wp-semantic-source');
    cow_merge_allocate_autoincrement_bands($target, $metadata, 'main');

    $db = open_db($source);
    $db->exec('DELETE FROM wp_posts WHERE ID = 30');
    $db->exec('DELETE FROM wp_posts WHERE ID = 32');
    $db->exec('DELETE FROM wp_postmeta WHERE post_id = 32');
    $db->close();

    $db = open_db($target);
    $db->exec("UPDATE wp_posts SET post_title = 'Target page still using reusable block' WHERE ID = 31");
    $db->exec("UPDATE wp_posts SET post_title = 'Target page still using synced pattern' WHERE ID = 33");
    $db->close();

    $result = cow_merge_branch_state(
        $base,
        $source,
        $target,
        $metadata,
        'feature-wp-semantic-source',
        'main',
        $file_base,
        $source_root,
        $target_root
    );

    assert_same($result['status'], 'completed_with_conflicts', 'WordPress block-reference validator holds missing reusable blocks and synced patterns for review');
    assert_same((int)($result['plugin_validators'] ?? 0), 1, 'WordPress block-reference validator is discovered from mu-plugins during merge');
    assert_same((int)($result['plugin_validator_conflicts'] ?? 0), 2, 'WordPress block-reference validator records missing reusable block and synced pattern references');
    assert_same((int)scalar($target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 30'), 0, 'WordPress block-reference validator leaves the source block deletion staged for review');
    assert_same((int)scalar($target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 32'), 0, 'WordPress block-reference validator leaves the source synced pattern deletion staged for review');
    assert_same((int)scalar($target, 'SELECT COUNT(*) FROM wp_postmeta WHERE post_id = 32'), 0, 'WordPress block-reference validator leaves the synced pattern metadata deletion staged for review');
    assert_same(scalar($target, 'SELECT post_title FROM wp_posts WHERE ID = 31'), 'Target page still using reusable block', 'WordPress block-reference validator preserves the target page edit');
    assert_same(scalar($target, 'SELECT post_title FROM wp_posts WHERE ID = 33'), 'Target page still using synced pattern', 'WordPress block-reference validator preserves the target synced pattern page edit');

    $audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-block-missing-reference',
    ]);
    assert_same(count($audit['conflicts']), 2, 'WordPress block-reference validator exposes missing refs as plugin-scoped audit conflicts');
    $preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $audit['conflicts']));
    assert_true(str_contains($preview, '"missing_ref":30'), 'WordPress block-reference audit includes the missing reusable block ID');
    assert_true(str_contains($preview, '"missing_ref":32'), 'WordPress block-reference audit includes the missing synced pattern ID');
    assert_true(str_contains($preview, '"post_id":33'), 'WordPress block-reference audit includes the synced pattern consumer page ID');

    $menu_base_root = $tmp . '/menu-base';
    $menu_source_root = $tmp . '/menu-source';
    $menu_target_root = $tmp . '/menu-target';
    $menu_base = $menu_base_root . '/wp-content/database/.ht.sqlite';
    $menu_source = $menu_source_root . '/wp-content/database/.ht.sqlite';
    $menu_target = $menu_target_root . '/wp-content/database/.ht.sqlite';
    $menu_metadata = $tmp . '/.forkpress/cow/merge/wp-menu-ref-validator-metadata.sqlite';
    $menu_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-menu-ref-validator.json';

    mkdir($menu_base_root . '/wp-content/database', 0777, true);
    create_wp_menu_ref_db($menu_base);
    write_test_file($menu_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT item.ID AS menu_item_id, item_type.meta_value AS menu_item_type, object.meta_value AS object_type, object_id.meta_value AS object_id
    FROM wp_posts item
    JOIN wp_postmeta item_type ON item_type.post_id = item.ID AND item_type.meta_key = '_menu_item_type'
    JOIN wp_postmeta object ON object.post_id = item.ID AND object.meta_key = '_menu_item_object'
    JOIN wp_postmeta object_id ON object_id.post_id = item.ID AND object_id.meta_key = '_menu_item_object_id'
    WHERE item.post_type = 'nav_menu_item' AND item_type.meta_value IN ('post_type', 'taxonomy')");
$findings = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $menu_item_type = (string)$row['menu_item_type'];
    $object_type = (string)$row['object_type'];
    $object_id = (int)$row['object_id'];
    $escaped_type = SQLite3::escapeString($object_type);
    if ($menu_item_type === 'taxonomy') {
        $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id AND tt.taxonomy = '$escaped_type' WHERE t.term_id = $object_id");
        $reason = 'nav menu item references a missing taxonomy term';
        $tables = ['wp_posts', 'wp_postmeta', 'wp_terms', 'wp_term_taxonomy'];
    } else {
        $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $object_id AND post_type = '$escaped_type'");
        $reason = 'nav menu item references a missing post object';
        $tables = ['wp_posts', 'wp_postmeta'];
    }
    if ($exists === 0) {
        $findings[] = [
            'plugin' => 'forkpress-wp-menu-refs',
            'object' => 'nav_menu_item:' . $row['menu_item_id'],
            'reason' => $reason,
            'type' => 'plugin-wp-menu-missing-object',
            'tables' => $tables,
            'validator' => 'forkpress-wp-menu-refs@1',
            'candidate' => [
                'menu_item_id' => (int)$row['menu_item_id'],
                'menu_item_type' => $menu_item_type,
                'object_type' => $object_type,
                'missing_object_id' => $object_id,
            ],
        ];
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($menu_base_root, $menu_source_root);
    copy_tree_for_test($menu_base_root, $menu_target_root);
    cow_merge_capture_file_base($menu_base_root, $menu_file_base);
    cow_merge_allocate_autoincrement_bands($menu_source, $menu_metadata, 'feature-wp-menu-ref-source');
    cow_merge_allocate_autoincrement_bands($menu_target, $menu_metadata, 'main');

    $db = open_db($menu_source);
    $db->exec('DELETE FROM wp_posts WHERE ID = 40');
    $db->exec('DELETE FROM wp_term_taxonomy WHERE term_id = 43');
    $db->exec('DELETE FROM wp_terms WHERE term_id = 43');
    $db->close();

    $db = open_db($menu_target);
    $db->exec("UPDATE wp_posts SET post_title = 'Target menu item still pointing at deleted page' WHERE ID = 41");
    $db->exec("UPDATE wp_posts SET post_title = 'Target menu item still pointing at deleted category' WHERE ID = 42");
    $db->close();

    $menu_result = cow_merge_branch_state(
        $menu_base,
        $menu_source,
        $menu_target,
        $menu_metadata,
        'feature-wp-menu-ref-source',
        'main',
        $menu_file_base,
        $menu_source_root,
        $menu_target_root
    );

    assert_same($menu_result['status'], 'completed_with_conflicts', 'WordPress menu-reference validator holds missing menu objects for review');
    assert_same((int)($menu_result['plugin_validators'] ?? 0), 1, 'WordPress menu-reference validator is discovered from mu-plugins during merge');
    assert_same((int)($menu_result['plugin_validator_conflicts'] ?? 0), 2, 'WordPress menu-reference validator records missing page and taxonomy objects');
    assert_same((int)scalar($menu_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 40'), 0, 'WordPress menu-reference validator leaves the source page deletion staged for review');
    assert_same((int)scalar($menu_target, 'SELECT COUNT(*) FROM wp_terms WHERE term_id = 43'), 0, 'WordPress menu-reference validator leaves the source term deletion staged for review');
    assert_same((int)scalar($menu_target, 'SELECT COUNT(*) FROM wp_term_taxonomy WHERE term_id = 43'), 0, 'WordPress menu-reference validator leaves the source taxonomy deletion staged for review');
    assert_same(scalar($menu_target, 'SELECT post_title FROM wp_posts WHERE ID = 41'), 'Target menu item still pointing at deleted page', 'WordPress menu-reference validator preserves the target menu item edit');
    assert_same(scalar($menu_target, 'SELECT post_title FROM wp_posts WHERE ID = 42'), 'Target menu item still pointing at deleted category', 'WordPress menu-reference validator preserves the taxonomy menu item edit');

    $menu_audit = cow_merge_audit_report($menu_metadata, (int)$menu_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-menu-missing-object',
    ]);
    assert_same(count($menu_audit['conflicts']), 2, 'WordPress menu-reference validator exposes missing menu objects as plugin-scoped audit conflicts');
    $menu_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $menu_audit['conflicts']));
    assert_true(str_contains($menu_preview, '"missing_object_id":40'), 'WordPress menu-reference audit includes the missing page ID');
    assert_true(str_contains($menu_preview, '"object_type":"page"'), 'WordPress menu-reference audit includes the page object type');
    assert_true(str_contains($menu_preview, '"missing_object_id":43'), 'WordPress menu-reference audit includes the missing term ID');
    assert_true(str_contains($menu_preview, '"menu_item_type":"taxonomy"'), 'WordPress menu-reference audit includes the taxonomy menu item type');
    assert_true(str_contains($menu_preview, '"object_type":"category"'), 'WordPress menu-reference audit includes the taxonomy object type');

    $featured_base_root = $tmp . '/featured-media-base';
    $featured_source_root = $tmp . '/featured-media-source';
    $featured_target_root = $tmp . '/featured-media-target';
    $featured_base = $featured_base_root . '/wp-content/database/.ht.sqlite';
    $featured_source = $featured_source_root . '/wp-content/database/.ht.sqlite';
    $featured_target = $featured_target_root . '/wp-content/database/.ht.sqlite';
    $featured_metadata = $tmp . '/.forkpress/cow/merge/wp-featured-media-validator-metadata.sqlite';
    $featured_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-featured-media-validator.json';

    mkdir($featured_base_root . '/wp-content/database', 0777, true);
    create_wp_featured_media_db($featured_base);
    write_test_file($featured_base_root . '/wp-content/uploads/2026/05/featured-image.jpg', 'featured image bytes');
    write_test_file($featured_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT meta_id, post_id, meta_value FROM wp_postmeta WHERE meta_key = '_thumbnail_id'");
$findings = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $attachment_id = (int)$row['meta_value'];
    if ($attachment_id <= 0) {
        continue;
    }
    $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $attachment_id AND post_type = 'attachment'");
    if ($exists === 0) {
        $findings[] = [
            'plugin' => 'forkpress-wp-featured-media',
            'object' => 'postmeta:' . $row['meta_id'],
            'reason' => 'featured image references a missing attachment',
            'type' => 'plugin-wp-featured-image-missing-attachment',
            'tables' => ['wp_postmeta', 'wp_posts'],
            'validator' => 'forkpress-wp-featured-media@1',
            'candidate' => [
                'post_id' => (int)$row['post_id'],
                'meta_id' => (int)$row['meta_id'],
                'field' => '_thumbnail_id',
                'missing_object_id' => $attachment_id,
                'object_type' => 'attachment',
            ],
        ];
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($featured_base_root, $featured_source_root);
    copy_tree_for_test($featured_base_root, $featured_target_root);
    cow_merge_capture_file_base($featured_base_root, $featured_file_base);
    cow_merge_allocate_autoincrement_bands($featured_source, $featured_metadata, 'feature-wp-featured-media-source');
    cow_merge_allocate_autoincrement_bands($featured_target, $featured_metadata, 'main');

    $db = open_db($featured_source);
    $db->exec('DELETE FROM wp_posts WHERE ID = 61');
    $db->close();
    unlink($featured_source_root . '/wp-content/uploads/2026/05/featured-image.jpg');

    $db = open_db($featured_target);
    $db->exec("UPDATE wp_posts SET post_title = 'Target page still using deleted featured image' WHERE ID = 60");
    $db->close();

    $featured_result = cow_merge_branch_state(
        $featured_base,
        $featured_source,
        $featured_target,
        $featured_metadata,
        'feature-wp-featured-media-source',
        'main',
        $featured_file_base,
        $featured_source_root,
        $featured_target_root
    );

    assert_same($featured_result['status'], 'completed_with_conflicts', 'WordPress featured image validator holds missing attachments for review');
    assert_same((int)($featured_result['plugin_validators'] ?? 0), 1, 'WordPress featured image validator is discovered from mu-plugins during merge');
    assert_same((int)($featured_result['plugin_validator_conflicts'] ?? 0), 1, 'WordPress featured image validator records the missing attachment');
    assert_same((int)scalar($featured_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 61'), 0, 'WordPress featured image validator leaves the source attachment deletion staged for review');
    assert_true(!file_exists($featured_target_root . '/wp-content/uploads/2026/05/featured-image.jpg'), 'WordPress featured image validator leaves the source upload deletion staged for review');
    assert_same(scalar($featured_target, 'SELECT post_title FROM wp_posts WHERE ID = 60'), 'Target page still using deleted featured image', 'WordPress featured image validator preserves the target page edit');
    assert_same(scalar($featured_target, "SELECT meta_value FROM wp_postmeta WHERE post_id = 60 AND meta_key = '_thumbnail_id'"), '61', 'WordPress featured image validator keeps the stale thumbnail reference visible for review');

    $featured_audit = cow_merge_audit_report($featured_metadata, (int)$featured_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-featured-image-missing-attachment',
    ]);
    assert_same(count($featured_audit['conflicts']), 1, 'WordPress featured image validator exposes the missing attachment as a plugin-scoped audit conflict');
    $featured_preview = (string)($featured_audit['conflicts'][0]['chosen_preview'] ?? '');
    assert_true(str_contains($featured_preview, '"missing_object_id":61'), 'WordPress featured image audit includes the missing attachment ID');
    assert_true(str_contains($featured_preview, '"field":"_thumbnail_id"'), 'WordPress featured image audit includes the thumbnail field');

    $image_block_base_root = $tmp . '/image-block-base';
    $image_block_source_root = $tmp . '/image-block-source';
    $image_block_target_root = $tmp . '/image-block-target';
    $image_block_base = $image_block_base_root . '/wp-content/database/.ht.sqlite';
    $image_block_source = $image_block_source_root . '/wp-content/database/.ht.sqlite';
    $image_block_target = $image_block_target_root . '/wp-content/database/.ht.sqlite';
    $image_block_metadata = $tmp . '/.forkpress/cow/merge/wp-image-block-validator-metadata.sqlite';
    $image_block_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-image-block-validator.json';

    mkdir($image_block_base_root . '/wp-content/database', 0777, true);
    create_wp_image_block_db($image_block_base);
    write_test_file($image_block_base_root . '/wp-content/uploads/2026/05/block-image.jpg', 'image block bytes');
    write_test_file($image_block_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT ID, post_content FROM wp_posts WHERE post_type IN ('post', 'page')");
$findings = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    if (!preg_match_all('/<!--\s*wp:image\s+(\{.*?\})\s*-->/', (string)$row['post_content'], $matches)) {
        continue;
    }
    foreach ($matches[1] as $raw_attrs) {
        $attrs = json_decode($raw_attrs, true);
        if (!is_array($attrs) || empty($attrs['id'])) {
            continue;
        }
        $attachment_id = (int)$attrs['id'];
        $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $attachment_id AND post_type = 'attachment'");
        if ($exists === 0) {
            $findings[] = [
                'plugin' => 'forkpress-wp-image-block-refs',
                'object' => 'post:' . $row['ID'],
                'reason' => 'image block references a missing attachment',
                'type' => 'plugin-wp-image-block-missing-attachment',
                'tables' => ['wp_posts'],
                'validator' => 'forkpress-wp-image-block-refs@1',
                'candidate' => [
                    'post_id' => (int)$row['ID'],
                    'block_name' => 'core/image',
                    'field' => 'attrs.id',
                    'missing_object_id' => $attachment_id,
                    'object_type' => 'attachment',
                ],
            ];
        }
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($image_block_base_root, $image_block_source_root);
    copy_tree_for_test($image_block_base_root, $image_block_target_root);
    cow_merge_capture_file_base($image_block_base_root, $image_block_file_base);
    cow_merge_allocate_autoincrement_bands($image_block_source, $image_block_metadata, 'feature-wp-image-block-source');
    cow_merge_allocate_autoincrement_bands($image_block_target, $image_block_metadata, 'main');

    $db = open_db($image_block_source);
    $db->exec('DELETE FROM wp_posts WHERE ID = 71');
    $db->close();
    unlink($image_block_source_root . '/wp-content/uploads/2026/05/block-image.jpg');

    $db = open_db($image_block_target);
    $db->exec("UPDATE wp_posts SET post_title = 'Target page still using deleted image block attachment' WHERE ID = 70");
    $db->close();

    $image_block_result = cow_merge_branch_state(
        $image_block_base,
        $image_block_source,
        $image_block_target,
        $image_block_metadata,
        'feature-wp-image-block-source',
        'main',
        $image_block_file_base,
        $image_block_source_root,
        $image_block_target_root
    );

    assert_same($image_block_result['status'], 'completed_with_conflicts', 'WordPress image block validator holds missing attachments for review');
    assert_same((int)($image_block_result['plugin_validators'] ?? 0), 1, 'WordPress image block validator is discovered from mu-plugins during merge');
    assert_same((int)($image_block_result['plugin_validator_conflicts'] ?? 0), 1, 'WordPress image block validator records the missing attachment');
    assert_same((int)scalar($image_block_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 71'), 0, 'WordPress image block validator leaves the source attachment deletion staged for review');
    assert_true(!file_exists($image_block_target_root . '/wp-content/uploads/2026/05/block-image.jpg'), 'WordPress image block validator leaves the source upload deletion staged for review');
    assert_same(scalar($image_block_target, 'SELECT post_title FROM wp_posts WHERE ID = 70'), 'Target page still using deleted image block attachment', 'WordPress image block validator preserves the target page edit');
    assert_true(str_contains((string)scalar($image_block_target, 'SELECT post_content FROM wp_posts WHERE ID = 70'), '"id":71'), 'WordPress image block validator keeps the stale block attachment reference visible for review');

    $image_block_audit = cow_merge_audit_report($image_block_metadata, (int)$image_block_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-image-block-missing-attachment',
    ]);
    assert_same(count($image_block_audit['conflicts']), 1, 'WordPress image block validator exposes the missing attachment as a plugin-scoped audit conflict');
    $image_block_preview = (string)($image_block_audit['conflicts'][0]['chosen_preview'] ?? '');
    assert_true(str_contains($image_block_preview, '"missing_object_id":71'), 'WordPress image block audit includes the missing attachment ID');
    assert_true(
        str_contains($image_block_preview, '"block_name":"core/image"') || str_contains($image_block_preview, '"block_name":"core\/image"'),
        'WordPress image block audit includes the block name'
    );

    $term_base_root = $tmp . '/term-ref-base';
    $term_source_root = $tmp . '/term-ref-source';
    $term_target_root = $tmp . '/term-ref-target';
    $term_base = $term_base_root . '/wp-content/database/.ht.sqlite';
    $term_source = $term_source_root . '/wp-content/database/.ht.sqlite';
    $term_target = $term_target_root . '/wp-content/database/.ht.sqlite';
    $term_metadata = $tmp . '/.forkpress/cow/merge/wp-term-ref-validator-metadata.sqlite';
    $term_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-term-ref-validator.json';

    mkdir($term_base_root . '/wp-content/database', 0777, true);
    create_wp_term_relationship_db($term_base);
    write_test_file($term_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT object_id, term_taxonomy_id FROM wp_term_relationships");
$findings = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $object_id = (int)$row['object_id'];
    $term_taxonomy_id = (int)$row['term_taxonomy_id'];
    $taxonomy = $db->querySingle("SELECT taxonomy FROM wp_term_taxonomy WHERE term_taxonomy_id = $term_taxonomy_id");
    $term_id = $db->querySingle("SELECT term_id FROM wp_term_taxonomy WHERE term_taxonomy_id = $term_taxonomy_id");
    $term_exists = $term_id === null ? 0 : (int)$db->querySingle("SELECT COUNT(*) FROM wp_terms WHERE term_id = " . (int)$term_id);
    if ($taxonomy === null || $term_exists === 0) {
        $findings[] = [
            'plugin' => 'forkpress-wp-term-refs',
            'object' => 'term_relationship:' . $object_id . ':' . $term_taxonomy_id,
            'reason' => 'term relationship references a missing taxonomy term',
            'type' => 'plugin-wp-term-relationship-missing-term',
            'tables' => ['wp_term_relationships', 'wp_term_taxonomy', 'wp_terms'],
            'validator' => 'forkpress-wp-term-refs@1',
            'candidate' => [
                'object_id' => $object_id,
                'term_taxonomy_id' => $term_taxonomy_id,
                'taxonomy' => $taxonomy,
                'missing_object_id' => $term_taxonomy_id,
                'object_type' => 'term_taxonomy',
            ],
        ];
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($term_base_root, $term_source_root);
    copy_tree_for_test($term_base_root, $term_target_root);
    cow_merge_capture_file_base($term_base_root, $term_file_base);
    cow_merge_allocate_autoincrement_bands($term_source, $term_metadata, 'feature-wp-term-ref-source');
    cow_merge_allocate_autoincrement_bands($term_target, $term_metadata, 'main');

    $db = open_db($term_source);
    $db->exec('DELETE FROM wp_term_taxonomy WHERE term_taxonomy_id = 82');
    $db->exec('DELETE FROM wp_terms WHERE term_id = 81');
    $db->close();

    $db = open_db($term_target);
    $db->exec("UPDATE wp_posts SET post_title = 'Target page assigned to deleted term' WHERE ID = 80");
    $db->exec('INSERT INTO wp_term_relationships (object_id, term_taxonomy_id) VALUES (80, 82)');
    $db->close();

    $term_result = cow_merge_branch_state(
        $term_base,
        $term_source,
        $term_target,
        $term_metadata,
        'feature-wp-term-ref-source',
        'main',
        $term_file_base,
        $term_source_root,
        $term_target_root
    );

    assert_same($term_result['status'], 'completed_with_conflicts', 'WordPress term relationship validator holds missing taxonomy terms for review');
    assert_same((int)($term_result['plugin_validators'] ?? 0), 1, 'WordPress term relationship validator is discovered from mu-plugins during merge');
    assert_same((int)($term_result['plugin_validator_conflicts'] ?? 0), 1, 'WordPress term relationship validator records the missing taxonomy term');
    assert_same((int)scalar($term_target, 'SELECT COUNT(*) FROM wp_term_taxonomy WHERE term_taxonomy_id = 82'), 0, 'WordPress term relationship validator leaves the source taxonomy deletion staged for review');
    assert_same((int)scalar($term_target, 'SELECT COUNT(*) FROM wp_terms WHERE term_id = 81'), 0, 'WordPress term relationship validator leaves the source term deletion staged for review');
    assert_same((int)scalar($term_target, 'SELECT COUNT(*) FROM wp_term_relationships WHERE object_id = 80 AND term_taxonomy_id = 82'), 1, 'WordPress term relationship validator preserves the target relationship assignment for review');
    assert_same(scalar($term_target, 'SELECT post_title FROM wp_posts WHERE ID = 80'), 'Target page assigned to deleted term', 'WordPress term relationship validator preserves the target page edit');

    $term_audit = cow_merge_audit_report($term_metadata, (int)$term_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-term-relationship-missing-term',
    ]);
    assert_same(count($term_audit['conflicts']), 1, 'WordPress term relationship validator exposes the missing taxonomy term as a plugin-scoped audit conflict');
    $term_preview = (string)($term_audit['conflicts'][0]['chosen_preview'] ?? '');
    assert_true(str_contains($term_preview, '"term_taxonomy_id":82'), 'WordPress term relationship audit includes the missing term taxonomy ID');

    $comment_base_root = $tmp . '/comment-ref-base';
    $comment_source_root = $tmp . '/comment-ref-source';
    $comment_target_root = $tmp . '/comment-ref-target';
    $comment_base = $comment_base_root . '/wp-content/database/.ht.sqlite';
    $comment_source = $comment_source_root . '/wp-content/database/.ht.sqlite';
    $comment_target = $comment_target_root . '/wp-content/database/.ht.sqlite';
    $comment_metadata = $tmp . '/.forkpress/cow/merge/wp-comment-ref-validator-metadata.sqlite';
    $comment_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-comment-ref-validator.json';

    mkdir($comment_base_root . '/wp-content/database', 0777, true);
    create_wp_comment_reference_db($comment_base);
    write_test_file($comment_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$findings = [];
$comments = $db->query('SELECT comment_ID, comment_post_ID, comment_parent, user_id FROM wp_comments ORDER BY comment_ID');
while ($row = $comments->fetchArray(SQLITE3_ASSOC)) {
    $comment_id = (int)$row['comment_ID'];
    $post_id = (int)$row['comment_post_ID'];
    $post_exists = $post_id <= 0 ? 1 : (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $post_id");
    if ($post_exists === 0) {
        $findings[] = [
            'plugin' => 'forkpress-wp-comment-refs',
            'object' => 'comment:' . $comment_id,
            'reason' => 'comment references a missing post',
            'type' => 'plugin-wp-comment-missing-post',
            'tables' => ['wp_comments', 'wp_posts'],
            'validator' => 'forkpress-wp-comment-refs@1',
            'candidate' => [
                'comment_id' => $comment_id,
                'field' => 'comment_post_ID',
                'missing_object_id' => $post_id,
                'object_type' => 'post',
            ],
        ];
    }
    $user_id = (int)$row['user_id'];
    $user_exists = $user_id <= 0 ? 1 : (int)$db->querySingle("SELECT COUNT(*) FROM wp_users WHERE ID = $user_id");
    if ($user_exists === 0) {
        $findings[] = [
            'plugin' => 'forkpress-wp-comment-refs',
            'object' => 'comment:' . $comment_id,
            'reason' => 'comment references a missing user',
            'type' => 'plugin-wp-comment-missing-user',
            'tables' => ['wp_comments', 'wp_users'],
            'validator' => 'forkpress-wp-comment-refs@1',
            'candidate' => [
                'comment_id' => $comment_id,
                'field' => 'user_id',
                'missing_object_id' => $user_id,
                'object_type' => 'user',
            ],
        ];
    }
    $parent_id = (int)$row['comment_parent'];
    $parent_exists = $parent_id <= 0 ? 1 : (int)$db->querySingle("SELECT COUNT(*) FROM wp_comments WHERE comment_ID = $parent_id");
    if ($parent_exists === 0) {
        $findings[] = [
            'plugin' => 'forkpress-wp-comment-refs',
            'object' => 'comment:' . $comment_id,
            'reason' => 'comment references a missing parent comment',
            'type' => 'plugin-wp-comment-missing-parent',
            'tables' => ['wp_comments'],
            'validator' => 'forkpress-wp-comment-refs@1',
            'candidate' => [
                'comment_id' => $comment_id,
                'field' => 'comment_parent',
                'missing_object_id' => $parent_id,
                'object_type' => 'comment',
            ],
        ];
    }
}
$meta = $db->query('SELECT meta_id, comment_id FROM wp_commentmeta ORDER BY meta_id');
while ($row = $meta->fetchArray(SQLITE3_ASSOC)) {
    $comment_id = (int)$row['comment_id'];
    $exists = $comment_id <= 0 ? 1 : (int)$db->querySingle("SELECT COUNT(*) FROM wp_comments WHERE comment_ID = $comment_id");
    if ($exists !== 0) {
        continue;
    }
    $findings[] = [
        'plugin' => 'forkpress-wp-comment-refs',
        'object' => 'commentmeta:' . $row['meta_id'],
        'reason' => 'comment metadata references a missing comment',
        'type' => 'plugin-wp-commentmeta-missing-comment',
        'tables' => ['wp_commentmeta', 'wp_comments'],
        'validator' => 'forkpress-wp-comment-refs@1',
        'candidate' => [
            'meta_id' => (int)$row['meta_id'],
            'field' => 'comment_id',
            'missing_object_id' => $comment_id,
            'object_type' => 'comment',
        ],
    ];
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($comment_base_root, $comment_source_root);
    copy_tree_for_test($comment_base_root, $comment_target_root);
    cow_merge_capture_file_base($comment_base_root, $comment_file_base);
    cow_merge_allocate_autoincrement_bands($comment_source, $comment_metadata, 'feature-wp-comment-ref-source');
    cow_merge_allocate_autoincrement_bands($comment_target, $comment_metadata, 'main');

    $db = open_db($comment_source);
    $db->exec('DELETE FROM wp_posts WHERE ID = 120');
    $db->exec('DELETE FROM wp_users WHERE ID = 121');
    $db->exec('DELETE FROM wp_comments WHERE comment_ID IN (123, 125)');
    $db->close();

    $db = open_db($comment_target);
    $db->exec("UPDATE wp_comments SET comment_content = 'Target comment still pointing at deleted post and user' WHERE comment_ID = 122");
    $db->exec("UPDATE wp_comments SET comment_content = 'Target child comment still pointing at deleted parent' WHERE comment_ID = 126");
    $db->exec("UPDATE wp_commentmeta SET meta_value = 'target metadata still pointing at deleted comment' WHERE meta_id = 124");
    $db->close();

    $comment_result = cow_merge_branch_state(
        $comment_base,
        $comment_source,
        $comment_target,
        $comment_metadata,
        'feature-wp-comment-ref-source',
        'main',
        $comment_file_base,
        $comment_source_root,
        $comment_target_root
    );

    assert_same($comment_result['status'], 'completed_with_conflicts', 'WordPress comment-reference validator holds missing post/user/comment refs for review');
    assert_same((int)($comment_result['plugin_validators'] ?? 0), 1, 'WordPress comment-reference validator is discovered from mu-plugins during merge');
    assert_same((int)($comment_result['plugin_validator_conflicts'] ?? 0), 4, 'WordPress comment-reference validator records missing post, user, parent comment, and commentmeta refs');
    assert_same((int)scalar($comment_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 120'), 0, 'WordPress comment-reference validator leaves the source post deletion staged for review');
    assert_same((int)scalar($comment_target, 'SELECT COUNT(*) FROM wp_users WHERE ID = 121'), 0, 'WordPress comment-reference validator leaves the source user deletion staged for review');
    assert_same((int)scalar($comment_target, 'SELECT COUNT(*) FROM wp_comments WHERE comment_ID = 123'), 0, 'WordPress comment-reference validator leaves the source comment deletion staged for review');
    assert_same((int)scalar($comment_target, 'SELECT COUNT(*) FROM wp_comments WHERE comment_ID = 125'), 0, 'WordPress comment-reference validator leaves the source parent comment deletion staged for review');
    assert_same(scalar($comment_target, 'SELECT comment_content FROM wp_comments WHERE comment_ID = 122'), 'Target comment still pointing at deleted post and user', 'WordPress comment-reference validator preserves target comment edits');
    assert_same(scalar($comment_target, 'SELECT comment_content FROM wp_comments WHERE comment_ID = 126'), 'Target child comment still pointing at deleted parent', 'WordPress comment-reference validator preserves target child comment edits');
    assert_same(scalar($comment_target, 'SELECT meta_value FROM wp_commentmeta WHERE meta_id = 124'), 'target metadata still pointing at deleted comment', 'WordPress comment-reference validator preserves target commentmeta edits');

    $comment_audit = cow_merge_audit_report($comment_metadata, (int)$comment_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
    ]);
    assert_same(count($comment_audit['conflicts']), 4, 'WordPress comment-reference validator exposes missing refs as plugin-scoped audit conflicts');
    $comment_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $comment_audit['conflicts']));
    foreach (['"object_type":"post"', '"object_type":"user"', '"object_type":"comment"', '"missing_object_id":120', '"missing_object_id":121', '"missing_object_id":123', '"missing_object_id":125'] as $needle) {
        assert_true(str_contains($comment_preview, $needle), 'WordPress comment-reference audit includes ' . $needle);
    }
    foreach (['comment_post_ID', 'user_id', 'comment_parent', 'comment_id'] as $needle) {
        assert_true(str_contains($comment_preview, $needle), 'WordPress comment-reference audit includes ' . $needle);
    }

    $option_base_root = $tmp . '/option-ref-base';
    $option_source_root = $tmp . '/option-ref-source';
    $option_target_root = $tmp . '/option-ref-target';
    $option_base = $option_base_root . '/wp-content/database/.ht.sqlite';
    $option_source = $option_source_root . '/wp-content/database/.ht.sqlite';
    $option_target = $option_target_root . '/wp-content/database/.ht.sqlite';
    $option_metadata = $tmp . '/.forkpress/cow/merge/wp-option-ref-validator-metadata.sqlite';
    $option_file_base = $tmp . '/.forkpress/cow/merge/file-bases/wp-option-ref-validator.json';

    mkdir($option_base_root . '/wp-content/database', 0777, true);
    create_wp_option_reference_db($option_base);
    write_test_file($option_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT option_name, option_value FROM wp_options WHERE option_name LIKE 'theme_mods_%' OR option_name IN ('widget_nav_menu', 'widget_media_image', 'sidebars_widgets', 'site_icon', 'page_on_front', 'page_for_posts', 'sticky_posts')");
$findings = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $option_name = (string)$row['option_name'];
    if ($option_name === 'page_on_front' || $option_name === 'page_for_posts') {
        $page_id = (int)$row['option_value'];
        $exists = $page_id > 0 ? (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $page_id AND post_type = 'page'") : 1;
        if ($exists === 0) {
            $findings[] = [
                'plugin' => 'forkpress-wp-option-refs',
                'object' => 'option:' . $option_name,
                'reason' => 'front page option references a missing page',
                'type' => 'plugin-wp-option-missing-object',
                'tables' => ['wp_options', 'wp_posts'],
                'validator' => 'forkpress-wp-option-refs@1',
                'candidate' => [
                    'option_name' => $option_name,
                    'field' => $option_name,
                    'missing_object_id' => $page_id,
                    'object_type' => 'page',
                ],
            ];
        }
        continue;
    }
    if ($option_name === 'site_icon') {
        $attachment_id = (int)$row['option_value'];
        $exists = $attachment_id > 0 ? (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $attachment_id AND post_type = 'attachment'") : 1;
        if ($exists === 0) {
            $findings[] = [
                'plugin' => 'forkpress-wp-option-refs',
                'object' => 'option:' . $option_name,
                'reason' => 'site icon option references a missing attachment',
                'type' => 'plugin-wp-option-missing-object',
                'tables' => ['wp_options', 'wp_posts'],
                'validator' => 'forkpress-wp-option-refs@1',
                'candidate' => [
                    'option_name' => $option_name,
                    'field' => 'site_icon',
                    'missing_object_id' => $attachment_id,
                    'object_type' => 'attachment',
                ],
            ];
        }
        continue;
    }
    if ($option_name === 'sticky_posts') {
        $sticky_posts = @unserialize((string)$row['option_value']);
        if (is_array($sticky_posts)) {
            foreach ($sticky_posts as $index => $post_id) {
                $post_id = (int)$post_id;
                $exists = $post_id > 0 ? (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $post_id AND post_type = 'post'") : 1;
                if ($exists !== 0) {
                    continue;
                }
                $findings[] = [
                    'plugin' => 'forkpress-wp-option-refs',
                    'object' => 'option:' . $option_name,
                    'reason' => 'sticky posts option references a missing post',
                    'type' => 'plugin-wp-option-missing-object',
                    'tables' => ['wp_options', 'wp_posts'],
                    'validator' => 'forkpress-wp-option-refs@1',
                    'candidate' => [
                        'option_name' => $option_name,
                        'field' => 'sticky_posts.' . (string)$index,
                        'missing_object_id' => $post_id,
                        'object_type' => 'post',
                    ],
                ];
            }
        }
        continue;
    }

    $decoded = @unserialize((string)$row['option_value']);
    if (!is_array($decoded)) {
        continue;
    }
    if ($option_name === 'sidebars_widgets') {
        foreach ($decoded as $sidebar_id => $widget_ids) {
            if (!is_array($widget_ids)) {
                continue;
            }
            foreach ($widget_ids as $index => $widget_id) {
                if (!is_string($widget_id) || !preg_match('/^(.+)-([0-9]+)$/', $widget_id, $matches)) {
                    continue;
                }
                $widget_option_name = 'widget_' . $matches[1];
                $widget_number = (int)$matches[2];
                $escaped_option = SQLite3::escapeString($widget_option_name);
                $widget_value = $db->querySingle("SELECT option_value FROM wp_options WHERE option_name = '$escaped_option'");
                $instances = is_string($widget_value) ? @unserialize($widget_value) : null;
                if (is_array($instances) && isset($instances[$widget_number])) {
                    continue;
                }
                $findings[] = [
                    'plugin' => 'forkpress-wp-option-refs',
                    'object' => 'option:' . $option_name,
                    'reason' => 'sidebar references a missing widget instance',
                    'type' => 'plugin-wp-option-missing-object',
                    'tables' => ['wp_options'],
                    'validator' => 'forkpress-wp-option-refs@1',
                    'candidate' => [
                        'option_name' => $option_name,
                        'field' => (string)$sidebar_id . '.' . (string)$index,
                        'missing_object_id' => $widget_id,
                        'object_type' => 'widget',
                        'widget_option_name' => $widget_option_name,
                    ],
                ];
            }
        }
        continue;
    }
    if (isset($decoded['forkpress_featured_page'])) {
        $page_id = (int)$decoded['forkpress_featured_page'];
        $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $page_id AND post_type = 'page'");
        if ($exists === 0) {
            $findings[] = [
                'plugin' => 'forkpress-wp-option-refs',
                'object' => 'option:' . $option_name,
                'reason' => 'theme option references a missing page',
                'type' => 'plugin-wp-option-missing-object',
                'tables' => ['wp_options', 'wp_posts'],
                'validator' => 'forkpress-wp-option-refs@1',
                'candidate' => [
                    'option_name' => $option_name,
                    'field' => 'forkpress_featured_page',
                    'missing_object_id' => $page_id,
                    'object_type' => 'page',
                ],
            ];
        }
    }
    if (isset($decoded['custom_logo'])) {
        $attachment_id = (int)$decoded['custom_logo'];
        $exists = $attachment_id > 0 ? (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $attachment_id AND post_type = 'attachment'") : 1;
        if ($exists === 0) {
            $findings[] = [
                'plugin' => 'forkpress-wp-option-refs',
                'object' => 'option:' . $option_name,
                'reason' => 'theme option references a missing custom logo attachment',
                'type' => 'plugin-wp-option-missing-object',
                'tables' => ['wp_options', 'wp_posts'],
                'validator' => 'forkpress-wp-option-refs@1',
                'candidate' => [
                    'option_name' => $option_name,
                    'field' => 'custom_logo',
                    'missing_object_id' => $attachment_id,
                    'object_type' => 'attachment',
                ],
            ];
        }
    }
    if ($option_name === 'widget_nav_menu') {
        foreach ($decoded as $widget_id => $widget) {
            if (!is_array($widget) || !isset($widget['nav_menu'])) {
                continue;
            }
            $term_id = (int)$widget['nav_menu'];
            $exists = $term_id > 0 ? (int)$db->querySingle("SELECT COUNT(*) FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id AND tt.taxonomy = 'nav_menu' WHERE t.term_id = $term_id") : 1;
            if ($exists !== 0) {
                continue;
            }
            $findings[] = [
                'plugin' => 'forkpress-wp-option-refs',
                'object' => 'option:' . $option_name,
                'reason' => 'nav menu widget references a missing nav menu',
                'type' => 'plugin-wp-option-missing-object',
                'tables' => ['wp_options', 'wp_terms', 'wp_term_taxonomy'],
                'validator' => 'forkpress-wp-option-refs@1',
                'candidate' => [
                    'option_name' => $option_name,
                    'field' => 'widget.' . (string)$widget_id . '.nav_menu',
                    'missing_object_id' => $term_id,
                    'object_type' => 'nav_menu',
                ],
            ];
        }
    }
    if ($option_name === 'widget_media_image') {
        foreach ($decoded as $widget_id => $widget) {
            if (!is_array($widget) || !isset($widget['attachment_id'])) {
                continue;
            }
            $attachment_id = (int)$widget['attachment_id'];
            $exists = $attachment_id > 0 ? (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $attachment_id AND post_type = 'attachment'") : 1;
            if ($exists !== 0) {
                continue;
            }
            $findings[] = [
                'plugin' => 'forkpress-wp-option-refs',
                'object' => 'option:' . $option_name,
                'reason' => 'media image widget references a missing attachment',
                'type' => 'plugin-wp-option-missing-object',
                'tables' => ['wp_options', 'wp_posts'],
                'validator' => 'forkpress-wp-option-refs@1',
                'candidate' => [
                    'option_name' => $option_name,
                    'field' => 'widget.' . (string)$widget_id . '.attachment_id',
                    'missing_object_id' => $attachment_id,
                    'object_type' => 'attachment',
                ],
            ];
        }
    }
    foreach (($decoded['nav_menu_locations'] ?? []) as $location => $term_id) {
        $term_id = (int)$term_id;
        $exists = $term_id > 0 ? (int)$db->querySingle("SELECT COUNT(*) FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id AND tt.taxonomy = 'nav_menu' WHERE t.term_id = $term_id") : 1;
        if ($exists !== 0) {
            continue;
        }
        $findings[] = [
            'plugin' => 'forkpress-wp-option-refs',
            'object' => 'option:' . $option_name,
            'reason' => 'theme option references a missing nav menu',
            'type' => 'plugin-wp-option-missing-object',
            'tables' => ['wp_options', 'wp_terms', 'wp_term_taxonomy'],
            'validator' => 'forkpress-wp-option-refs@1',
            'candidate' => [
                'option_name' => $option_name,
                'field' => 'nav_menu_locations.' . (string)$location,
                'missing_object_id' => $term_id,
                'object_type' => 'nav_menu',
            ],
        ];
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);
    copy_tree_for_test($option_base_root, $option_source_root);
    copy_tree_for_test($option_base_root, $option_target_root);
    cow_merge_capture_file_base($option_base_root, $option_file_base);
    cow_merge_allocate_autoincrement_bands($option_source, $option_metadata, 'feature-wp-option-ref-source');
    cow_merge_allocate_autoincrement_bands($option_target, $option_metadata, 'main');

    $db = open_db($option_source);
    $db->exec('DELETE FROM wp_posts WHERE ID IN (90, 91, 92, 93)');
    $db->exec('DELETE FROM wp_term_taxonomy WHERE term_id = 94');
    $db->exec('DELETE FROM wp_terms WHERE term_id = 94');
    $db->exec("DELETE FROM wp_options WHERE option_name = 'widget_text'");
    $db->close();

    $db = open_db($option_target);
    $theme_mods_target = serialize([
        'forkpress_featured_page' => 90,
        'nav_menu_locations' => [
            'primary' => 94,
        ],
        'custom_logo' => 93,
        'forkpress_accent' => 'target',
    ]);
    $stmt = $db->prepare("UPDATE wp_options SET option_value = :value WHERE option_name = 'theme_mods_forkpress_active'");
    $stmt->bindValue(':value', $theme_mods_target, SQLITE3_TEXT);
    $stmt->execute();
    $widget_nav_target = serialize([
        2 => [
            'title' => 'Target footer menu',
            'nav_menu' => 94,
        ],
        '_multiwidget' => 1,
    ]);
    $stmt = $db->prepare("UPDATE wp_options SET option_value = :value WHERE option_name = 'widget_nav_menu'");
    $stmt->bindValue(':value', $widget_nav_target, SQLITE3_TEXT);
    $stmt->execute();
    $widget_media_target = serialize([
        3 => [
            'attachment_id' => 93,
            'url' => 'http://example.test/wp-content/uploads/2026/05/option-logo.jpg',
            'caption' => 'Target media image widget',
        ],
        '_multiwidget' => 1,
    ]);
    $stmt = $db->prepare("UPDATE wp_options SET option_value = :value WHERE option_name = 'widget_media_image'");
    $stmt->bindValue(':value', $widget_media_target, SQLITE3_TEXT);
    $stmt->execute();
    $sidebars_target = serialize([
        'sidebar-1' => ['nav_menu-2', 'media_image-3', 'text-4'],
        'wp_inactive_widgets' => [],
        'array_version' => 3,
    ]);
    $stmt = $db->prepare("UPDATE wp_options SET option_value = :value WHERE option_name = 'sidebars_widgets'");
    $stmt->bindValue(':value', $sidebars_target, SQLITE3_TEXT);
    $stmt->execute();
    $db->close();

    $option_result = cow_merge_branch_state(
        $option_base,
        $option_source,
        $option_target,
        $option_metadata,
        'feature-wp-option-ref-source',
        'main',
        $option_file_base,
        $option_source_root,
        $option_target_root
    );

    assert_same($option_result['status'], 'completed_with_conflicts', 'WordPress option reference validator holds missing option objects for review');
    assert_same((int)($option_result['plugin_validators'] ?? 0), 1, 'WordPress option reference validator is discovered from mu-plugins during merge');
    assert_same((int)($option_result['plugin_validator_conflicts'] ?? 0), 10, 'WordPress option reference validator records missing pages, posts, attachments, nav menus, widgets, and option refs');
    assert_same((int)scalar($option_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID IN (90, 91, 92, 93)'), 0, 'WordPress option reference validator leaves source object deletions staged for review');
    assert_same((int)scalar($option_target, 'SELECT COUNT(*) FROM wp_terms WHERE term_id = 94'), 0, 'WordPress option reference validator leaves source nav menu deletion staged for review');

    $option_theme_mods_value = scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'theme_mods_forkpress_active'");
    $option_theme_mods = is_string($option_theme_mods_value) ? unserialize($option_theme_mods_value) : null;
    assert_same($option_theme_mods['forkpress_accent'] ?? null, 'target', 'WordPress option reference validator preserves the target theme mods edit');
    assert_same($option_theme_mods['forkpress_featured_page'] ?? null, 90, 'WordPress option reference validator keeps the stale featured page reference visible for review');
    assert_same($option_theme_mods['nav_menu_locations']['primary'] ?? null, 94, 'WordPress option reference validator keeps the stale nav menu location visible for review');
    assert_same($option_theme_mods['custom_logo'] ?? null, 93, 'WordPress option reference validator keeps the stale custom logo reference visible for review');
    assert_same(scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'site_icon'"), '93', 'WordPress option reference validator keeps the stale site icon option visible for review');
    assert_same(scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'page_on_front'"), '90', 'WordPress option reference validator keeps the stale front page option visible for review');
    assert_same(scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'page_for_posts'"), '91', 'WordPress option reference validator keeps the stale posts page option visible for review');
    $option_sticky_posts = unserialize((string)scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'sticky_posts'"));
    assert_same($option_sticky_posts[0] ?? null, 92, 'WordPress option reference validator keeps the stale sticky post reference visible for review');
    $option_widget_nav = unserialize((string)scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'widget_nav_menu'"));
    assert_same($option_widget_nav[2]['title'] ?? null, 'Target footer menu', 'WordPress option reference validator preserves the target nav widget edit');
    assert_same($option_widget_nav[2]['nav_menu'] ?? null, 94, 'WordPress option reference validator keeps the stale nav widget menu visible for review');
    $option_widget_media = unserialize((string)scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'widget_media_image'"));
    assert_same($option_widget_media[3]['caption'] ?? null, 'Target media image widget', 'WordPress option reference validator preserves the target media widget edit');
    assert_same($option_widget_media[3]['attachment_id'] ?? null, 93, 'WordPress option reference validator keeps the stale media widget attachment visible for review');
    assert_same(scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'widget_text'"), null, 'WordPress option reference validator leaves the source widget option deletion staged for review');
    $option_sidebars = unserialize((string)scalar($option_target, "SELECT option_value FROM wp_options WHERE option_name = 'sidebars_widgets'"));
    assert_same($option_sidebars['sidebar-1'][2] ?? null, 'text-4', 'WordPress option reference validator keeps the stale sidebar widget instance visible for review');

    $option_audit = cow_merge_audit_report($option_metadata, (int)$option_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-option-missing-object',
    ]);
    assert_same(count($option_audit['conflicts']), 10, 'WordPress option reference validator exposes missing option objects as plugin-scoped audit conflicts');
    $option_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $option_audit['conflicts']));
    foreach (['"missing_object_id":90', '"missing_object_id":91', '"missing_object_id":92', '"missing_object_id":93', '"missing_object_id":94'] as $needle) {
        assert_true(str_contains($option_preview, $needle), 'WordPress option reference audit includes ' . $needle);
    }
    foreach (['"object_type":"page"', '"object_type":"post"', '"object_type":"attachment"', '"object_type":"nav_menu"', '"object_type":"widget"'] as $needle) {
        assert_true(str_contains($option_preview, $needle), 'WordPress option reference audit includes ' . $needle);
    }
    foreach (['theme_mods_forkpress_active', 'widget_nav_menu', 'widget_media_image', 'sidebars_widgets', 'widget_text', 'site_icon', 'page_on_front', 'page_for_posts', 'sticky_posts'] as $needle) {
        assert_true(str_contains($option_preview, $needle), 'WordPress option reference audit includes ' . $needle);
    }
} finally {
    remove_tree($tmp);
}

if ($fail) {
    echo "FAILURES: $fail\n";
    exit(1);
}
echo "COW WordPress semantic validator focused tests passed ($pass assertions).\n";

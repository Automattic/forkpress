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
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (40, 'Shared menu page', '<!-- wp:paragraph --><p>Menu page</p><!-- /wp:paragraph -->', 'publish', 'page', 'shared-menu-page'),
        (41, 'Menu item for shared page', '', 'publish', 'nav_menu_item', 'menu-item-shared-page')");
    $db->exec("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES
        (41, '_menu_item_type', 'post_type'),
        (41, '_menu_item_object', 'page'),
        (41, '_menu_item_object_id', '40'),
        (41, '_menu_item_menu_item_parent', '0')");
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
$res = $db->query("SELECT item.ID AS menu_item_id, object.meta_value AS object_type, object_id.meta_value AS object_id
    FROM wp_posts item
    JOIN wp_postmeta item_type ON item_type.post_id = item.ID AND item_type.meta_key = '_menu_item_type'
    JOIN wp_postmeta object ON object.post_id = item.ID AND object.meta_key = '_menu_item_object'
    JOIN wp_postmeta object_id ON object_id.post_id = item.ID AND object_id.meta_key = '_menu_item_object_id'
    WHERE item.post_type = 'nav_menu_item' AND item_type.meta_value = 'post_type'");
$findings = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $object_type = (string)$row['object_type'];
    $object_id = (int)$row['object_id'];
    $escaped_type = SQLite3::escapeString($object_type);
    $exists = (int)$db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE ID = $object_id AND post_type = '$escaped_type'");
    if ($exists === 0) {
        $findings[] = [
            'plugin' => 'forkpress-wp-menu-refs',
            'object' => 'nav_menu_item:' . $row['menu_item_id'],
            'reason' => 'nav menu item references a missing post object',
            'type' => 'plugin-wp-menu-missing-object',
            'tables' => ['wp_posts', 'wp_postmeta'],
            'validator' => 'forkpress-wp-menu-refs@1',
            'candidate' => [
                'menu_item_id' => (int)$row['menu_item_id'],
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
    $db->close();

    $db = open_db($menu_target);
    $db->exec("UPDATE wp_posts SET post_title = 'Target menu item still pointing at deleted page' WHERE ID = 41");
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
    assert_same((int)($menu_result['plugin_validator_conflicts'] ?? 0), 1, 'WordPress menu-reference validator records the missing page object');
    assert_same((int)scalar($menu_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 40'), 0, 'WordPress menu-reference validator leaves the source page deletion staged for review');
    assert_same(scalar($menu_target, 'SELECT post_title FROM wp_posts WHERE ID = 41'), 'Target menu item still pointing at deleted page', 'WordPress menu-reference validator preserves the target menu item edit');

    $menu_audit = cow_merge_audit_report($menu_metadata, (int)$menu_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-menu-missing-object',
    ]);
    assert_same(count($menu_audit['conflicts']), 1, 'WordPress menu-reference validator exposes the missing menu object as a plugin-scoped audit conflict');
    $menu_preview = (string)($menu_audit['conflicts'][0]['chosen_preview'] ?? '');
    assert_true(str_contains($menu_preview, '"missing_object_id":40'), 'WordPress menu-reference audit includes the missing page ID');
    assert_true(str_contains($menu_preview, '"object_type":"page"'), 'WordPress menu-reference audit includes the menu object type');
} finally {
    remove_tree($tmp);
}

if ($fail) {
    echo "FAILURES: $fail\n";
    exit(1);
}
echo "COW WordPress semantic validator focused tests passed ($pass assertions).\n";

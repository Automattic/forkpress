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

function create_media_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY AUTOINCREMENT, post_title TEXT, post_content TEXT, post_status TEXT, post_type TEXT NOT NULL DEFAULT 'post', guid TEXT NOT NULL DEFAULT '')");
    $db->exec('CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)');
    $db->close();
}

define('FORKPRESS_COW_MERGE_TESTS', true);
require_once __DIR__ . '/../../scripts/cow/merge.php';

echo "=== COW media validator focused tests ===\n";

$tmp = sys_get_temp_dir() . '/forkpress-cow-media-validator-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);

try {
    $base_root = $tmp . '/base';
    $source_root = $tmp . '/source';
    $target_root = $tmp . '/target';
    $base = $base_root . '/wp-content/database/.ht.sqlite';
    $source = $source_root . '/wp-content/database/.ht.sqlite';
    $target = $target_root . '/wp-content/database/.ht.sqlite';
    $metadata = $tmp . '/.forkpress/cow/merge/media-validator-metadata.sqlite';
    $file_base = $tmp . '/.forkpress/cow/merge/file-bases/media-validator.json';

    mkdir($base_root . '/wp-content/database', 0777, true);
    create_media_db($base);
    write_test_file($base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$res = $db->query("SELECT p.ID, f.meta_value AS attached_file, m.meta_value AS metadata
    FROM wp_posts p
    JOIN wp_postmeta f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'
    JOIN wp_postmeta m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_metadata'
    WHERE p.post_type = 'attachment'
    ORDER BY p.ID");
$findings = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $metadata = @unserialize((string)$row['metadata']);
    if (!is_array($metadata)) {
        continue;
    }
    foreach (($metadata['sizes'] ?? []) as $size_name => $size) {
        if (is_array($size) && isset($size['file'])) {
            continue;
        }
        $findings[] = [
            'plugin' => 'forkpress-wp-media',
            'object' => 'attachment:' . $row['ID'],
            'reason' => 'attachment generated size metadata is incomplete',
            'type' => 'plugin-wp-media-generated-file-drift',
            'tables' => ['wp_posts', 'wp_postmeta'],
            'validator' => 'forkpress-wp-media@1',
            'candidate' => [
                'attached_file' => (string)$row['attached_file'],
                'size' => (string)$size_name,
                'generated_file' => null,
            ],
        ];
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
    cow_merge_allocate_autoincrement_bands($source, $metadata, 'feature-media-source');
    cow_merge_allocate_autoincrement_bands($target, $metadata, 'main');

    write_test_file($source_root . '/wp-content/uploads/2026/05/source-generated-missing-file-key.jpg', "source generated missing file key original bytes\n");
    $db = open_db($source);
    $db->exec("INSERT INTO wp_posts (post_title, post_content, post_status, post_type, guid) VALUES ('Source media generated missing file key', '', 'inherit', 'attachment', 'wp-content/uploads/2026/05/source-generated-missing-file-key.jpg')");
    $attachment_id = (int)$db->lastInsertRowID();
    $metadata_payload = serialize([
        'file' => '2026/05/source-generated-missing-file-key.jpg',
        'width' => 640,
        'height' => 480,
        'sizes' => [
            'thumbnail' => [
                'width' => 150,
                'height' => 150,
            ],
        ],
    ]);
    $stmt = $db->prepare("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (:post_id, '_wp_attached_file', :file), (:post_id, '_wp_attachment_metadata', :metadata)");
    $stmt->bindValue(':post_id', $attachment_id, SQLITE3_INTEGER);
    $stmt->bindValue(':file', '2026/05/source-generated-missing-file-key.jpg', SQLITE3_TEXT);
    $stmt->bindValue(':metadata', $metadata_payload, SQLITE3_TEXT);
    $stmt->execute();
    $db->close();

    $result = cow_merge_branch_state(
        $base,
        $source,
        $target,
        $metadata,
        'feature-media-source',
        'main',
        $file_base,
        $source_root,
        $target_root
    );

    assert_same($result['status'], 'completed_with_conflicts', 'media validator holds incomplete generated-size metadata for review');
    assert_same((int)($result['plugin_validators'] ?? 0), 1, 'media validator is discovered from mu-plugins during merge');
    assert_same((int)($result['plugin_validator_conflicts'] ?? 0), 1, 'media validator records one generated-size metadata conflict');
    assert_same(
        scalar($target, "SELECT meta_value FROM wp_postmeta WHERE post_id = $attachment_id AND meta_key = '_wp_attached_file'"),
        '2026/05/source-generated-missing-file-key.jpg',
        'media validator leaves the staged attachment metadata available for review'
    );
    assert_true(is_file($target_root . '/wp-content/uploads/2026/05/source-generated-missing-file-key.jpg'), 'media validator keeps the original upload file for review');

    $audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-media-generated-file-drift',
    ]);
    assert_same(count($audit['conflicts']), 1, 'media validator exposes incomplete generated-size metadata as a plugin-scoped audit conflict');
    $preview = (string)($audit['conflicts'][0]['chosen_preview'] ?? '');
    assert_true(str_contains($preview, 'source-generated-missing-file-key.jpg'), 'media validator audit includes the affected attachment');
    assert_true(str_contains($preview, '"generated_file":null'), 'media validator audit records the missing generated file field');
} finally {
    remove_tree($tmp);
}

if ($fail) {
    echo "FAILURES: $fail\n";
    exit(1);
}
echo "COW media validator focused tests passed ($pass assertions).\n";

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

function insert_attachment(SQLite3 $db, string $title, string $attached_file, array $metadata): int {
    $stmt = $db->prepare("INSERT INTO wp_posts (post_title, post_content, post_status, post_type, guid) VALUES (:title, '', 'inherit', 'attachment', :guid)");
    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
    $stmt->bindValue(':guid', 'wp-content/uploads/' . $attached_file, SQLITE3_TEXT);
    $stmt->execute();
    $attachment_id = (int)$db->lastInsertRowID();

    $meta_stmt = $db->prepare("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (:post_id, '_wp_attached_file', :file), (:post_id, '_wp_attachment_metadata', :metadata)");
    $meta_stmt->bindValue(':post_id', $attachment_id, SQLITE3_INTEGER);
    $meta_stmt->bindValue(':file', $attached_file, SQLITE3_TEXT);
    $meta_stmt->bindValue(':metadata', serialize($metadata), SQLITE3_TEXT);
    $meta_stmt->execute();

    return $attachment_id;
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
$target_root = rtrim((string)getenv('FORKPRESS_MERGE_TARGET_ROOT'), '/');
$uploads_root = $target_root === '' ? '' : $target_root . '/wp-content/uploads';
$res = $db->query("SELECT p.ID, f.meta_value AS attached_file, m.meta_value AS metadata
    FROM wp_posts p
    JOIN wp_postmeta f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'
    JOIN wp_postmeta m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_metadata'
    WHERE p.post_type = 'attachment'
    ORDER BY p.ID");
$findings = [];
$claimed_uploads = [];
$unsafe_upload_path = static function (string $relative_file): bool {
    return $relative_file === '' ||
        str_starts_with($relative_file, '/') ||
        str_contains($relative_file, "\0") ||
        in_array('..', explode('/', str_replace('\\', '/', $relative_file)), true);
};
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $metadata = @unserialize((string)$row['metadata']);
    if (!is_array($metadata)) {
        continue;
    }
    $attached_file = (string)$row['attached_file'];
    $metadata_file = (string)($metadata['file'] ?? '');
    $relative_files = [$attached_file];
    if ($metadata_file !== '' && $metadata_file !== $attached_file) {
        $relative_files[] = $metadata_file;
    }
    if ($metadata_file !== '' && $metadata_file !== $attached_file) {
        $findings[] = [
            'plugin' => 'forkpress-wp-media',
            'object' => 'attachment:' . $row['ID'],
            'reason' => '_wp_attached_file does not match _wp_attachment_metadata file',
            'type' => 'plugin-wp-media-file-drift',
            'tables' => ['wp_posts', 'wp_postmeta'],
            'validator' => 'forkpress-wp-media@1',
            'candidate' => [
                'attached_file' => $attached_file,
                'metadata_file' => $metadata_file,
            ],
        ];
    }
    if ($unsafe_upload_path($attached_file)) {
        $findings[] = [
            'plugin' => 'forkpress-wp-media',
            'object' => 'attachment:' . $row['ID'],
            'reason' => 'attachment original file metadata points outside uploads',
            'type' => 'plugin-wp-media-unsafe-path',
            'tables' => ['wp_posts', 'wp_postmeta'],
            'validator' => 'forkpress-wp-media@1',
            'candidate' => [
                'attached_file' => $attached_file,
                'file' => $attached_file,
            ],
        ];
    } elseif ($uploads_root !== '' && !is_file($uploads_root . '/' . $attached_file)) {
        $findings[] = [
            'plugin' => 'forkpress-wp-media',
            'object' => 'attachment:' . $row['ID'],
            'reason' => 'attachment original file is missing from uploads',
            'type' => 'plugin-wp-media-missing-file',
            'tables' => ['wp_posts', 'wp_postmeta'],
            'validator' => 'forkpress-wp-media@1',
            'candidate' => [
                'attached_file' => $attached_file,
            ],
        ];
    }
    $directory = trim(dirname($metadata_file !== '' ? $metadata_file : $attached_file), '.');
    foreach (($metadata['sizes'] ?? []) as $size_name => $size) {
        if (is_array($size) && isset($size['file'])) {
            $generated_file = trim($directory . '/' . str_replace('\\', '/', (string)$size['file']), '/');
            if ($unsafe_upload_path($generated_file)) {
                $findings[] = [
                    'plugin' => 'forkpress-wp-media',
                    'object' => 'attachment:' . $row['ID'],
                    'reason' => 'attachment generated size metadata points outside uploads',
                    'type' => 'plugin-wp-media-unsafe-path',
                    'tables' => ['wp_posts', 'wp_postmeta'],
                    'validator' => 'forkpress-wp-media@1',
                    'candidate' => [
                        'attached_file' => $attached_file,
                        'size' => (string)$size_name,
                        'generated_file' => $generated_file,
                    ],
                ];
                continue;
            }
            $relative_files[] = $generated_file;
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
                'attached_file' => $attached_file,
                'size' => (string)$size_name,
                'generated_file' => null,
            ],
        ];
    }
    foreach ($relative_files as $relative_file) {
        $claimed_uploads[$relative_file] ??= [];
        $claimed_uploads[$relative_file][] = (int)$row['ID'];
    }
}
foreach ($claimed_uploads as $relative_file => $attachment_ids) {
    $attachment_counts = array_count_values($attachment_ids);
    $duplicate_attachment_ids = array_values(array_map('intval', array_keys(array_filter(
        $attachment_counts,
        static fn(int $count): bool => $count > 1
    ))));
    $unique_attachment_ids = array_values(array_unique($attachment_ids));
    if (count($unique_attachment_ids) < 2 && $duplicate_attachment_ids === []) {
        continue;
    }
    $findings[] = [
        'plugin' => 'forkpress-wp-media',
        'object' => 'upload:' . $relative_file,
        'reason' => $duplicate_attachment_ids !== []
            ? 'attachment metadata claims the same upload file multiple times'
            : 'multiple attachment metadata records claim the same upload file',
        'type' => 'plugin-wp-media-duplicate-file',
        'tables' => ['wp_posts', 'wp_postmeta'],
        'validator' => 'forkpress-wp-media@1',
        'candidate' => [
            'file' => $relative_file,
            'attachment_ids' => $unique_attachment_ids,
            'duplicate_attachment_ids' => $duplicate_attachment_ids,
        ],
    ];
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
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-metadata-file-mismatch-attached.jpg', "source metadata mismatch attached file bytes\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-self-duplicate.jpg', "source self duplicate original bytes\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-duplicate-a.jpg', "source duplicate original a\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-duplicate-b.jpg', "source duplicate original b\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-duplicate-shared-150x150.jpg', "source duplicate shared generated size\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-unsafe-generated.jpg', "source unsafe generated original bytes\n");
    $db = open_db($source);
    $attachment_id = insert_attachment($db, 'Source media generated missing file key', '2026/05/source-generated-missing-file-key.jpg', [
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
    $missing_original_id = insert_attachment($db, 'Source media missing original file', '2026/05/source-missing-original.jpg', [
        'file' => '2026/05/source-missing-original.jpg',
        'width' => 640,
        'height' => 480,
        'sizes' => [],
    ]);
    $metadata_mismatch_id = insert_attachment($db, 'Source media metadata mismatch', '2026/05/source-metadata-file-mismatch-attached.jpg', [
        'file' => '2026/05/source-metadata-file-mismatch-metadata.jpg',
        'width' => 640,
        'height' => 480,
        'sizes' => [],
    ]);
    $self_duplicate_id = insert_attachment($db, 'Source media self duplicate generated file', '2026/05/source-self-duplicate.jpg', [
        'file' => '2026/05/source-self-duplicate.jpg',
        'width' => 640,
        'height' => 480,
        'sizes' => [
            'thumbnail' => [
                'file' => 'source-self-duplicate.jpg',
                'width' => 150,
                'height' => 150,
            ],
        ],
    ]);
    $duplicate_a_id = insert_attachment($db, 'Source media duplicate generated file A', '2026/05/source-duplicate-a.jpg', [
        'file' => '2026/05/source-duplicate-a.jpg',
        'width' => 640,
        'height' => 480,
        'sizes' => [
            'thumbnail' => [
                'file' => 'source-duplicate-shared-150x150.jpg',
                'width' => 150,
                'height' => 150,
            ],
        ],
    ]);
    $duplicate_b_id = insert_attachment($db, 'Source media duplicate generated file B', '2026/05/source-duplicate-b.jpg', [
        'file' => '2026/05/source-duplicate-b.jpg',
        'width' => 640,
        'height' => 480,
        'sizes' => [
            'thumbnail' => [
                'file' => 'source-duplicate-shared-150x150.jpg',
                'width' => 150,
                'height' => 150,
            ],
        ],
    ]);
    $unsafe_generated_id = insert_attachment($db, 'Source media unsafe generated path', '2026/05/source-unsafe-generated.jpg', [
        'file' => '2026/05/source-unsafe-generated.jpg',
        'width' => 640,
        'height' => 480,
        'sizes' => [
            'thumbnail' => [
                'file' => '../source-unsafe-generated-150x150.jpg',
                'width' => 150,
                'height' => 150,
            ],
        ],
    ]);
    $unsafe_attached_id = insert_attachment($db, 'Source media unsafe attached path', '../source-unsafe-attached.jpg', [
        'file' => '../source-unsafe-attached.jpg',
        'width' => 640,
        'height' => 480,
        'sizes' => [],
    ]);
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
    assert_same((int)($result['plugin_validator_conflicts'] ?? 0), 7, 'media validator records generated-size, missing-file, metadata-file drift, unsafe path, and duplicate upload conflicts');
    assert_same(
        scalar($target, "SELECT meta_value FROM wp_postmeta WHERE post_id = $attachment_id AND meta_key = '_wp_attached_file'"),
        '2026/05/source-generated-missing-file-key.jpg',
        'media validator leaves the staged attachment metadata available for review'
    );
    assert_true(is_file($target_root . '/wp-content/uploads/2026/05/source-generated-missing-file-key.jpg'), 'media validator keeps the original upload file for review');
    assert_same(
        scalar($target, "SELECT meta_value FROM wp_postmeta WHERE post_id = $missing_original_id AND meta_key = '_wp_attached_file'"),
        '2026/05/source-missing-original.jpg',
        'media validator leaves missing-original attachment metadata available for review'
    );
    assert_true(!is_file($target_root . '/wp-content/uploads/2026/05/source-missing-original.jpg'), 'media validator does not invent missing original upload files');
    assert_same(
        scalar($target, "SELECT meta_value FROM wp_postmeta WHERE post_id = $metadata_mismatch_id AND meta_key = '_wp_attached_file'"),
        '2026/05/source-metadata-file-mismatch-attached.jpg',
        'media validator leaves mismatched attached-file metadata available for review'
    );
    assert_true(is_file($target_root . '/wp-content/uploads/2026/05/source-metadata-file-mismatch-attached.jpg'), 'media validator keeps mismatched attached file for review');

    $audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-media-generated-file-drift',
    ]);
    assert_same(count($audit['conflicts']), 1, 'media validator exposes incomplete generated-size metadata as a plugin-scoped audit conflict');
    $preview = (string)($audit['conflicts'][0]['chosen_preview'] ?? '');
    assert_true(str_contains($preview, 'source-generated-missing-file-key.jpg'), 'media validator audit includes the affected attachment');
    assert_true(str_contains($preview, '"generated_file":null'), 'media validator audit records the missing generated file field');

    $missing_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-media-missing-file',
    ]);
    assert_same(count($missing_audit['conflicts']), 1, 'media validator exposes missing original files as plugin-scoped audit conflicts');
    assert_true(str_contains((string)($missing_audit['conflicts'][0]['chosen_preview'] ?? ''), 'source-missing-original.jpg'), 'media validator missing-file audit includes the affected attachment');

    $mismatch_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-media-file-drift',
    ]);
    assert_same(count($mismatch_audit['conflicts']), 1, 'media validator exposes attached-file metadata drift as a plugin-scoped audit conflict');
    $mismatch_preview = (string)($mismatch_audit['conflicts'][0]['chosen_preview'] ?? '');
    assert_true(str_contains($mismatch_preview, 'source-metadata-file-mismatch-attached.jpg'), 'media validator mismatch audit includes the attached file');
    assert_true(str_contains($mismatch_preview, 'source-metadata-file-mismatch-metadata.jpg'), 'media validator mismatch audit includes the metadata file');

    $unsafe_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-media-unsafe-path',
    ]);
    assert_same(count($unsafe_audit['conflicts']), 2, 'media validator exposes unsafe primary and generated upload paths as plugin-scoped audit conflicts');
    $unsafe_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $unsafe_audit['conflicts']));
    assert_true(str_contains($unsafe_preview, 'source-unsafe-generated.jpg'), 'media validator unsafe-path audit includes the affected attachment');
    assert_true(str_contains($unsafe_preview, '../source-unsafe-generated-150x150.jpg'), 'media validator unsafe-path audit includes the rejected generated path');
    assert_true(str_contains($unsafe_preview, '../source-unsafe-attached.jpg'), 'media validator unsafe-path audit includes the rejected attached file path');
    assert_true(is_file($target_root . '/wp-content/uploads/2026/05/source-unsafe-generated.jpg'), 'media validator keeps the unsafe-path attachment original file for review');
    assert_same(
        scalar($target, "SELECT meta_value FROM wp_postmeta WHERE post_id = $unsafe_generated_id AND meta_key = '_wp_attached_file'"),
        '2026/05/source-unsafe-generated.jpg',
        'media validator leaves unsafe-path attachment metadata available for review'
    );
    assert_same(
        scalar($target, "SELECT meta_value FROM wp_postmeta WHERE post_id = $unsafe_attached_id AND meta_key = '_wp_attached_file'"),
        '../source-unsafe-attached.jpg',
        'media validator leaves unsafe attached-path metadata available for review'
    );

    $duplicate_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-media-duplicate-file',
    ]);
    assert_same(count($duplicate_audit['conflicts']), 2, 'media validator exposes duplicate upload ownership as plugin-scoped audit conflicts');
    $duplicate_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $duplicate_audit['conflicts']));
    assert_true(str_contains($duplicate_preview, 'source-self-duplicate.jpg'), 'media validator duplicate audit includes the same-attachment duplicate filename');
    assert_true(str_contains($duplicate_preview, (string)$self_duplicate_id), 'media validator duplicate audit includes the same-attachment duplicate ID');
    assert_true(str_contains($duplicate_preview, 'source-duplicate-shared-150x150.jpg'), 'media validator duplicate audit includes the shared generated filename');
    assert_true(str_contains($duplicate_preview, (string)$duplicate_a_id), 'media validator duplicate audit includes the first shared generated attachment ID');
    assert_true(str_contains($duplicate_preview, (string)$duplicate_b_id), 'media validator duplicate audit includes the second shared generated attachment ID');
} finally {
    remove_tree($tmp);
}

if ($fail) {
    echo "FAILURES: $fail\n";
    exit(1);
}
echo "COW media validator focused tests passed ($pass assertions).\n";

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
    $db->exec("CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY AUTOINCREMENT, post_title TEXT, post_content TEXT, post_status TEXT, post_type TEXT NOT NULL DEFAULT 'post', post_mime_type TEXT NOT NULL DEFAULT '', guid TEXT NOT NULL DEFAULT '')");
    $db->exec('CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)');
    $db->close();
}

function insert_attachment(SQLite3 $db, string $title, string $attached_file, array $metadata, string $mime_type = 'image/jpeg'): int {
    $stmt = $db->prepare("INSERT INTO wp_posts (post_title, post_content, post_status, post_type, post_mime_type, guid) VALUES (:title, '', 'inherit', 'attachment', :mime_type, :guid)");
    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
    $stmt->bindValue(':mime_type', $mime_type, SQLITE3_TEXT);
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

function insert_attachment_raw_metadata(SQLite3 $db, string $title, string $attached_file, string $metadata): int {
    $stmt = $db->prepare("INSERT INTO wp_posts (post_title, post_content, post_status, post_type, post_mime_type, guid) VALUES (:title, '', 'inherit', 'attachment', 'image/jpeg', :guid)");
    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
    $stmt->bindValue(':guid', 'wp-content/uploads/' . $attached_file, SQLITE3_TEXT);
    $stmt->execute();
    $attachment_id = (int)$db->lastInsertRowID();

    $meta_stmt = $db->prepare("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (:post_id, '_wp_attached_file', :file), (:post_id, '_wp_attachment_metadata', :metadata)");
    $meta_stmt->bindValue(':post_id', $attachment_id, SQLITE3_INTEGER);
    $meta_stmt->bindValue(':file', $attached_file, SQLITE3_TEXT);
    $meta_stmt->bindValue(':metadata', $metadata, SQLITE3_TEXT);
    $meta_stmt->execute();

    return $attachment_id;
}

function insert_attachment_with_single_meta(SQLite3 $db, string $title, string $meta_key, string $meta_value): int {
    $stmt = $db->prepare("INSERT INTO wp_posts (post_title, post_content, post_status, post_type, post_mime_type, guid) VALUES (:title, '', 'inherit', 'attachment', 'image/jpeg', '')");
    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
    $stmt->execute();
    $attachment_id = (int)$db->lastInsertRowID();

    $meta_stmt = $db->prepare('INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (:post_id, :meta_key, :meta_value)');
    $meta_stmt->bindValue(':post_id', $attachment_id, SQLITE3_INTEGER);
    $meta_stmt->bindValue(':meta_key', $meta_key, SQLITE3_TEXT);
    $meta_stmt->bindValue(':meta_value', $meta_value, SQLITE3_TEXT);
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
$res = $db->query("SELECT p.ID, p.post_mime_type, f.meta_value AS attached_file, m.meta_value AS metadata
    FROM wp_posts p
    LEFT JOIN wp_postmeta f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'
    LEFT JOIN wp_postmeta m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_metadata'
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
    if ($row['attached_file'] === null || $row['metadata'] === null) {
        foreach (['_wp_attached_file' => $row['attached_file'], '_wp_attachment_metadata' => $row['metadata']] as $meta_key => $meta_value) {
            if ($meta_value !== null) {
                continue;
            }
            $findings[] = [
                'plugin' => 'forkpress-wp-media',
                'object' => 'attachment:' . $row['ID'],
                'reason' => 'attachment is missing required media metadata',
                'type' => 'plugin-wp-media-missing-metadata',
                'tables' => ['wp_posts', 'wp_postmeta'],
                'validator' => 'forkpress-wp-media@1',
                'candidate' => [
                    'attachment_id' => (int)$row['ID'],
                    'missing_meta_key' => $meta_key,
                ],
            ];
        }
        continue;
    }
    $metadata = @unserialize((string)$row['metadata']);
    if (!is_array($metadata)) {
        $findings[] = [
            'plugin' => 'forkpress-wp-media',
            'object' => 'attachment:' . $row['ID'],
            'reason' => 'attachment metadata is not readable serialized media metadata',
            'type' => 'plugin-wp-media-invalid-metadata',
            'tables' => ['wp_posts', 'wp_postmeta'],
            'validator' => 'forkpress-wp-media@1',
            'candidate' => [
                'attached_file' => (string)$row['attached_file'],
            ],
        ];
        continue;
    }
    $attached_file = (string)$row['attached_file'];
    $metadata_file = (string)($metadata['file'] ?? '');
    $expected_mime_by_extension = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];
    $extension = strtolower((string)pathinfo(str_replace('\\', '/', $attached_file), PATHINFO_EXTENSION));
    $expected_mime_type = $expected_mime_by_extension[$extension] ?? null;
    $post_mime_type = strtolower((string)($row['post_mime_type'] ?? ''));
    if ($expected_mime_type !== null && $post_mime_type !== $expected_mime_type) {
        $findings[] = [
            'plugin' => 'forkpress-wp-media',
            'object' => 'attachment:' . $row['ID'],
            'reason' => 'attachment post MIME type does not match the uploaded file extension',
            'type' => 'plugin-wp-media-mime-drift',
            'tables' => ['wp_posts', 'wp_postmeta'],
            'validator' => 'forkpress-wp-media@1',
            'candidate' => [
                'attachment_id' => (int)$row['ID'],
                'attached_file' => $attached_file,
                'post_mime_type' => (string)($row['post_mime_type'] ?? ''),
                'expected_mime_type' => $expected_mime_type,
            ],
        ];
    }
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
    $metadata_width = $metadata['width'] ?? null;
    $metadata_height = $metadata['height'] ?? null;
    if (!is_numeric($metadata_width) || !is_numeric($metadata_height) || (int)$metadata_width <= 0 || (int)$metadata_height <= 0) {
        $findings[] = [
            'plugin' => 'forkpress-wp-media',
            'object' => 'attachment:' . $row['ID'],
            'reason' => 'attachment original dimensions are invalid',
            'type' => 'plugin-wp-media-original-dimensions-drift',
            'tables' => ['wp_posts', 'wp_postmeta'],
            'validator' => 'forkpress-wp-media@1',
            'candidate' => [
                'attached_file' => $attached_file,
                'width' => $metadata_width,
                'height' => $metadata_height,
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
                'attachment_id' => (int)$row['ID'],
                'attached_file' => $attached_file,
            ],
        ];
    } elseif ($uploads_root !== '') {
        $declared_filesize = $metadata['filesize'] ?? null;
        $actual_filesize = is_file($uploads_root . '/' . $attached_file) ? filesize($uploads_root . '/' . $attached_file) : false;
        if ($declared_filesize !== null && $actual_filesize !== false && (!is_numeric($declared_filesize) || (int)$declared_filesize !== (int)$actual_filesize)) {
            $findings[] = [
                'plugin' => 'forkpress-wp-media',
                'object' => 'attachment:' . $row['ID'],
                'reason' => 'attachment original filesize metadata does not match the upload file',
                'type' => 'plugin-wp-media-filesize-drift',
                'tables' => ['wp_posts', 'wp_postmeta'],
                'validator' => 'forkpress-wp-media@1',
                'candidate' => [
                    'attachment_id' => (int)$row['ID'],
                    'attached_file' => $attached_file,
                    'declared_filesize' => $declared_filesize,
                    'actual_filesize' => (int)$actual_filesize,
                ],
            ];
        }
    }
    if ($metadata_file !== '' && $metadata_file !== $attached_file) {
        if ($unsafe_upload_path($metadata_file)) {
            $findings[] = [
                'plugin' => 'forkpress-wp-media',
                'object' => 'attachment:' . $row['ID'],
                'reason' => 'attachment metadata original file points outside uploads',
                'type' => 'plugin-wp-media-unsafe-path',
                'tables' => ['wp_posts', 'wp_postmeta'],
                'validator' => 'forkpress-wp-media@1',
                'candidate' => [
                    'attachment_id' => (int)$row['ID'],
                    'attached_file' => $attached_file,
                    'metadata_file' => $metadata_file,
                ],
            ];
        } elseif ($uploads_root !== '' && !is_file($uploads_root . '/' . $metadata_file)) {
            $findings[] = [
                'plugin' => 'forkpress-wp-media',
                'object' => 'attachment:' . $row['ID'],
                'reason' => 'attachment metadata original file is missing from uploads',
                'type' => 'plugin-wp-media-missing-file',
                'tables' => ['wp_posts', 'wp_postmeta'],
                'validator' => 'forkpress-wp-media@1',
                'candidate' => [
                    'attachment_id' => (int)$row['ID'],
                    'attached_file' => $attached_file,
                    'metadata_file' => $metadata_file,
                ],
            ];
        }
    }
    $directory = trim(dirname($metadata_file !== '' ? $metadata_file : $attached_file), '.');
    if (array_key_exists('original_image', $metadata)) {
        $original_image = str_replace('\\', '/', (string)$metadata['original_image']);
        if ($original_image === '' || (str_contains($original_image, '/') && !$unsafe_upload_path($original_image))) {
            $findings[] = [
                'plugin' => 'forkpress-wp-media',
                'object' => 'attachment:' . $row['ID'],
                'reason' => 'attachment original image file is not a non-empty basename',
                'type' => 'plugin-wp-media-generated-file-drift',
                'tables' => ['wp_posts', 'wp_postmeta'],
                'validator' => 'forkpress-wp-media@1',
                'candidate' => [
                    'attachment_id' => (int)$row['ID'],
                    'attached_file' => $attached_file,
                    'original_image' => (string)$metadata['original_image'],
                ],
            ];
            if ($original_image === '') {
                $original_image = '';
            }
        }
        if ($original_image !== '') {
            $original_image_file = trim($directory . '/' . $original_image, '/');
            if ($unsafe_upload_path($original_image_file)) {
                $findings[] = [
                    'plugin' => 'forkpress-wp-media',
                    'object' => 'attachment:' . $row['ID'],
                    'reason' => 'attachment original image metadata points outside uploads',
                    'type' => 'plugin-wp-media-unsafe-path',
                    'tables' => ['wp_posts', 'wp_postmeta'],
                    'validator' => 'forkpress-wp-media@1',
                    'candidate' => [
                        'attachment_id' => (int)$row['ID'],
                        'attached_file' => $attached_file,
                        'original_image' => (string)$metadata['original_image'],
                        'original_image_file' => $original_image_file,
                    ],
                ];
            } else {
                $relative_files[] = $original_image_file;
                if ($uploads_root !== '' && !is_file($uploads_root . '/' . $original_image_file)) {
                    $findings[] = [
                        'plugin' => 'forkpress-wp-media',
                        'object' => 'attachment:' . $row['ID'],
                        'reason' => 'attachment original image file is missing from uploads',
                        'type' => 'plugin-wp-media-missing-file',
                        'tables' => ['wp_posts', 'wp_postmeta'],
                        'validator' => 'forkpress-wp-media@1',
                        'candidate' => [
                            'attachment_id' => (int)$row['ID'],
                            'attached_file' => $attached_file,
                            'original_image' => (string)$metadata['original_image'],
                            'original_image_file' => $original_image_file,
                        ],
                    ];
                }
            }
        }
    }
    $sizes = $metadata['sizes'] ?? [];
    if (!is_array($sizes)) {
        $findings[] = [
            'plugin' => 'forkpress-wp-media',
            'object' => 'attachment:' . $row['ID'],
            'reason' => 'attachment generated sizes metadata is not an array',
            'type' => 'plugin-wp-media-generated-file-drift',
            'tables' => ['wp_posts', 'wp_postmeta'],
            'validator' => 'forkpress-wp-media@1',
            'candidate' => [
                'attachment_id' => (int)$row['ID'],
                'attached_file' => $attached_file,
                'sizes_type' => gettype($sizes),
            ],
        ];
        $sizes = [];
    }
    foreach ($sizes as $size_name => $size) {
        if (is_array($size) && isset($size['file'])) {
            $size_file = str_replace('\\', '/', (string)$size['file']);
            if ($size_file === '' || (str_contains($size_file, '/') && !$unsafe_upload_path($size_file))) {
                $findings[] = [
                    'plugin' => 'forkpress-wp-media',
                    'object' => 'attachment:' . $row['ID'],
                    'reason' => 'attachment generated size file is not a non-empty basename',
                    'type' => 'plugin-wp-media-generated-file-drift',
                    'tables' => ['wp_posts', 'wp_postmeta'],
                    'validator' => 'forkpress-wp-media@1',
                    'candidate' => [
                        'attachment_id' => (int)$row['ID'],
                        'attached_file' => $attached_file,
                        'size' => (string)$size_name,
                        'generated_file' => (string)$size['file'],
                    ],
                ];
                if ($size_file === '') {
                    continue;
                }
            }
            $generated_file = trim($directory . '/' . $size_file, '/');
            if ($unsafe_upload_path($generated_file)) {
                $findings[] = [
                    'plugin' => 'forkpress-wp-media',
                    'object' => 'attachment:' . $row['ID'],
                    'reason' => 'attachment generated size metadata points outside uploads',
                    'type' => 'plugin-wp-media-unsafe-path',
                    'tables' => ['wp_posts', 'wp_postmeta'],
                    'validator' => 'forkpress-wp-media@1',
                    'candidate' => [
                        'attachment_id' => (int)$row['ID'],
                        'attached_file' => $attached_file,
                        'size' => (string)$size_name,
                        'generated_file' => $generated_file,
                    ],
                ];
                continue;
            }
            if (array_key_exists('mime-type', $size)) {
                $generated_extension = strtolower((string)pathinfo($generated_file, PATHINFO_EXTENSION));
                $expected_generated_mime_type = $expected_mime_by_extension[$generated_extension] ?? null;
                $generated_mime_type = strtolower((string)$size['mime-type']);
                if ($expected_generated_mime_type !== null && $generated_mime_type !== $expected_generated_mime_type) {
                    $findings[] = [
                        'plugin' => 'forkpress-wp-media',
                        'object' => 'attachment:' . $row['ID'],
                        'reason' => 'attachment generated size MIME type does not match the generated file extension',
                        'type' => 'plugin-wp-media-mime-drift',
                        'tables' => ['wp_posts', 'wp_postmeta'],
                        'validator' => 'forkpress-wp-media@1',
                        'candidate' => [
                            'attachment_id' => (int)$row['ID'],
                            'attached_file' => $attached_file,
                            'size' => (string)$size_name,
                            'generated_file' => $generated_file,
                            'generated_mime_type' => (string)$size['mime-type'],
                            'expected_mime_type' => $expected_generated_mime_type,
                        ],
                    ];
                }
            }
            $relative_files[] = $generated_file;
            if ($uploads_root !== '' && !is_file($uploads_root . '/' . $generated_file)) {
                $findings[] = [
                    'plugin' => 'forkpress-wp-media',
                    'object' => 'attachment:' . $row['ID'],
                    'reason' => 'attachment generated size file is missing from uploads',
                    'type' => 'plugin-wp-media-missing-file',
                    'tables' => ['wp_posts', 'wp_postmeta'],
                    'validator' => 'forkpress-wp-media@1',
                    'candidate' => [
                        'attached_file' => $attached_file,
                        'size' => (string)$size_name,
                        'generated_file' => $generated_file,
                    ],
                ];
            } elseif ($uploads_root !== '') {
                $declared_generated_filesize = $size['filesize'] ?? null;
                $actual_generated_filesize = filesize($uploads_root . '/' . $generated_file);
                if ($declared_generated_filesize !== null && (!is_numeric($declared_generated_filesize) || (int)$declared_generated_filesize !== (int)$actual_generated_filesize)) {
                    $findings[] = [
                        'plugin' => 'forkpress-wp-media',
                        'object' => 'attachment:' . $row['ID'],
                        'reason' => 'attachment generated size filesize metadata does not match the upload file',
                        'type' => 'plugin-wp-media-filesize-drift',
                        'tables' => ['wp_posts', 'wp_postmeta'],
                        'validator' => 'forkpress-wp-media@1',
                        'candidate' => [
                            'attachment_id' => (int)$row['ID'],
                            'attached_file' => $attached_file,
                            'size' => (string)$size_name,
                            'generated_file' => $generated_file,
                            'declared_filesize' => $declared_generated_filesize,
                            'actual_filesize' => (int)$actual_generated_filesize,
                        ],
                    ];
                }
            }
            $size_width = $size['width'] ?? null;
            $size_height = $size['height'] ?? null;
            if (!is_numeric($size_width) || !is_numeric($size_height) || (int)$size_width <= 0 || (int)$size_height <= 0) {
                $findings[] = [
                    'plugin' => 'forkpress-wp-media',
                    'object' => 'attachment:' . $row['ID'],
                    'reason' => 'attachment generated size dimensions are invalid',
                    'type' => 'plugin-wp-media-generated-dimensions-drift',
                    'tables' => ['wp_posts', 'wp_postmeta'],
                    'validator' => 'forkpress-wp-media@1',
                    'candidate' => [
                        'attached_file' => $attached_file,
                        'size' => (string)$size_name,
                        'width' => $size_width,
                        'height' => $size_height,
                    ],
                ];
            }
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
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-generated-sizes-not-array.jpg', "source generated sizes not array original bytes\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-missing-generated.jpg', "source missing generated original bytes\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-metadata-file-mismatch-attached.jpg', "source metadata mismatch attached file bytes\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-unsafe-metadata-file-attached.jpg', "source unsafe metadata-file attached bytes\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-self-duplicate.jpg', "source self duplicate original bytes\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-duplicate-a.jpg', "source duplicate original a\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-duplicate-b.jpg', "source duplicate original b\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-duplicate-shared-150x150.jpg', "source duplicate shared generated size\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-duplicate-original.jpg', "source duplicate shared original\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-unsafe-generated.jpg', "source unsafe generated original bytes\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-invalid-metadata.jpg', "source invalid metadata original bytes\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-original-dimensions.jpg', "source invalid original dimensions bytes\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-filesize-drift.jpg', "source filesize drift bytes\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-generated-filesize.jpg', "source generated filesize original bytes\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-generated-filesize-150x150.jpg', "source generated filesize thumb bytes\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-mime-drift.jpg', "source MIME drift image bytes\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-generated-mime.jpg', "source generated MIME original bytes\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-generated-mime-thumb.png', "source generated MIME thumb bytes\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-generated-dimensions.jpg', "source invalid generated dimensions original bytes\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-generated-dimensions-150x150.jpg', "source invalid generated dimensions thumb bytes\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-generated-subdir.jpg', "source generated subdir original bytes\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/nested/source-generated-subdir-150x150.jpg', "source generated subdir thumb bytes\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-original-image-missing-scaled.jpg', "source missing original_image scaled bytes\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-original-image-unsafe-scaled.jpg', "source unsafe original_image scaled bytes\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/source-original-image-subdir-scaled.jpg', "source subdir original_image scaled bytes\n");
    write_test_file($source_root . '/wp-content/uploads/2026/05/nested/source-original-image-subdir-original.jpg', "source subdir original_image original bytes\n");
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
    $sizes_not_array_id = insert_attachment($db, 'Source media generated sizes not array', '2026/05/source-generated-sizes-not-array.jpg', [
        'file' => '2026/05/source-generated-sizes-not-array.jpg',
        'width' => 640,
        'height' => 480,
        'sizes' => 'thumbnail',
    ]);
    $missing_original_id = insert_attachment($db, 'Source media missing original file', '2026/05/source-missing-original.jpg', [
        'file' => '2026/05/source-missing-original.jpg',
        'width' => 640,
        'height' => 480,
        'sizes' => [],
    ]);
    $missing_generated_id = insert_attachment($db, 'Source media missing generated file', '2026/05/source-missing-generated.jpg', [
        'file' => '2026/05/source-missing-generated.jpg',
        'width' => 640,
        'height' => 480,
        'sizes' => [
            'thumbnail' => [
                'file' => 'source-missing-generated-150x150.jpg',
                'width' => 150,
                'height' => 150,
            ],
        ],
    ]);
    $metadata_mismatch_id = insert_attachment($db, 'Source media metadata mismatch', '2026/05/source-metadata-file-mismatch-attached.jpg', [
        'file' => '2026/05/source-metadata-file-mismatch-metadata.jpg',
        'width' => 640,
        'height' => 480,
        'sizes' => [],
    ]);
    $unsafe_metadata_file_id = insert_attachment($db, 'Source media unsafe metadata file', '2026/05/source-unsafe-metadata-file-attached.jpg', [
        'file' => '../source-unsafe-metadata-file.jpg',
        'width' => 640,
        'height' => 480,
        'sizes' => [],
    ]);
    $original_dimensions_id = insert_attachment($db, 'Source media invalid original dimensions', '2026/05/source-original-dimensions.jpg', [
        'file' => '2026/05/source-original-dimensions.jpg',
        'width' => 0,
        'height' => 480,
        'sizes' => [],
    ]);
    $filesize_drift_id = insert_attachment($db, 'Source media filesize drift', '2026/05/source-filesize-drift.jpg', [
        'file' => '2026/05/source-filesize-drift.jpg',
        'width' => 640,
        'height' => 480,
        'filesize' => 1,
        'sizes' => [],
    ]);
    $generated_filesize_drift_id = insert_attachment($db, 'Source media generated filesize drift', '2026/05/source-generated-filesize.jpg', [
        'file' => '2026/05/source-generated-filesize.jpg',
        'width' => 640,
        'height' => 480,
        'sizes' => [
            'thumbnail' => [
                'file' => 'source-generated-filesize-150x150.jpg',
                'width' => 150,
                'height' => 150,
                'filesize' => 1,
            ],
        ],
    ]);
    $mime_drift_id = insert_attachment($db, 'Source media MIME type drift', '2026/05/source-mime-drift.jpg', [
        'file' => '2026/05/source-mime-drift.jpg',
        'width' => 640,
        'height' => 480,
        'sizes' => [],
    ], 'application/pdf');
    $generated_mime_drift_id = insert_attachment($db, 'Source media generated MIME type drift', '2026/05/source-generated-mime.jpg', [
        'file' => '2026/05/source-generated-mime.jpg',
        'width' => 640,
        'height' => 480,
        'sizes' => [
            'thumbnail' => [
                'file' => 'source-generated-mime-thumb.png',
                'width' => 150,
                'height' => 150,
                'mime-type' => 'image/jpeg',
            ],
        ],
    ]);
    $generated_dimensions_id = insert_attachment($db, 'Source media invalid generated dimensions', '2026/05/source-generated-dimensions.jpg', [
        'file' => '2026/05/source-generated-dimensions.jpg',
        'width' => 640,
        'height' => 480,
        'sizes' => [
            'thumbnail' => [
                'file' => 'source-generated-dimensions-150x150.jpg',
                'width' => 0,
                'height' => 150,
            ],
        ],
    ]);
    $generated_subdir_id = insert_attachment($db, 'Source media generated size subdir filename', '2026/05/source-generated-subdir.jpg', [
        'file' => '2026/05/source-generated-subdir.jpg',
        'width' => 640,
        'height' => 480,
        'sizes' => [
            'thumbnail' => [
                'file' => 'nested/source-generated-subdir-150x150.jpg',
                'width' => 150,
                'height' => 150,
            ],
        ],
    ]);
    $original_image_missing_id = insert_attachment($db, 'Source media missing original image file', '2026/05/source-original-image-missing-scaled.jpg', [
        'file' => '2026/05/source-original-image-missing-scaled.jpg',
        'width' => 640,
        'height' => 480,
        'original_image' => 'source-original-image-missing-original.jpg',
        'sizes' => [],
    ]);
    $original_image_unsafe_id = insert_attachment($db, 'Source media unsafe original image path', '2026/05/source-original-image-unsafe-scaled.jpg', [
        'file' => '2026/05/source-original-image-unsafe-scaled.jpg',
        'width' => 640,
        'height' => 480,
        'original_image' => '../source-original-image-unsafe-original.jpg',
        'sizes' => [],
    ]);
    $original_image_subdir_id = insert_attachment($db, 'Source media original image subdir filename', '2026/05/source-original-image-subdir-scaled.jpg', [
        'file' => '2026/05/source-original-image-subdir-scaled.jpg',
        'width' => 640,
        'height' => 480,
        'original_image' => 'nested/source-original-image-subdir-original.jpg',
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
    $duplicate_original_a_id = insert_attachment($db, 'Source media duplicate original file A', '2026/05/source-duplicate-original.jpg', [
        'file' => '2026/05/source-duplicate-original.jpg',
        'width' => 640,
        'height' => 480,
        'sizes' => [],
    ]);
    $duplicate_original_b_id = insert_attachment($db, 'Source media duplicate original file B', '2026/05/source-duplicate-original.jpg', [
        'file' => '2026/05/source-duplicate-original.jpg',
        'width' => 640,
        'height' => 480,
        'sizes' => [],
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
    $invalid_metadata_id = insert_attachment_raw_metadata(
        $db,
        'Source media invalid serialized metadata',
        '2026/05/source-invalid-metadata.jpg',
        'not serialized attachment metadata'
    );
    $missing_attachment_metadata_id = insert_attachment_with_single_meta(
        $db,
        'Source media missing attachment metadata',
        '_wp_attached_file',
        '2026/05/source-missing-attachment-metadata.jpg'
    );
    $missing_attached_file_id = insert_attachment_with_single_meta(
        $db,
        'Source media missing attached file metadata',
        '_wp_attachment_metadata',
        serialize([
            'file' => '2026/05/source-missing-attached-file.jpg',
            'width' => 640,
            'height' => 480,
            'sizes' => [],
        ])
    );
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
    assert_same((int)($result['plugin_validator_conflicts'] ?? 0), 26, 'media validator records missing required metadata, invalid metadata, dimensions, filesize and MIME drift, generated-size, original-image, missing-file, metadata-file drift, unsafe path, and duplicate upload conflicts');
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
    assert_true(is_file($target_root . '/wp-content/uploads/2026/05/source-missing-generated.jpg'), 'media validator keeps the missing-generated attachment original file for review');
    assert_true(!is_file($target_root . '/wp-content/uploads/2026/05/source-missing-generated-150x150.jpg'), 'media validator does not invent missing generated upload files');
    assert_true(is_file($target_root . '/wp-content/uploads/2026/05/source-original-image-missing-scaled.jpg'), 'media validator keeps the scaled upload file for missing original_image review');
    assert_true(!is_file($target_root . '/wp-content/uploads/2026/05/source-original-image-missing-original.jpg'), 'media validator does not invent missing original_image upload files');
    assert_same(
        scalar($target, "SELECT meta_value FROM wp_postmeta WHERE post_id = $metadata_mismatch_id AND meta_key = '_wp_attached_file'"),
        '2026/05/source-metadata-file-mismatch-attached.jpg',
        'media validator leaves mismatched attached-file metadata available for review'
    );
    assert_true(is_file($target_root . '/wp-content/uploads/2026/05/source-metadata-file-mismatch-attached.jpg'), 'media validator keeps mismatched attached file for review');
    assert_same(
        scalar($target, "SELECT meta_value FROM wp_postmeta WHERE post_id = $unsafe_metadata_file_id AND meta_key = '_wp_attached_file'"),
        '2026/05/source-unsafe-metadata-file-attached.jpg',
        'media validator leaves unsafe metadata-file attachment metadata available for review'
    );
    assert_true(is_file($target_root . '/wp-content/uploads/2026/05/source-unsafe-metadata-file-attached.jpg'), 'media validator keeps unsafe metadata-file attached file for review');

    $invalid_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-media-invalid-metadata',
    ]);
    assert_same(count($invalid_audit['conflicts']), 1, 'media validator exposes invalid serialized attachment metadata as a plugin-scoped audit conflict');
    $invalid_preview = (string)($invalid_audit['conflicts'][0]['chosen_preview'] ?? '');
    assert_true(str_contains($invalid_preview, 'source-invalid-metadata.jpg'), 'media validator invalid-metadata audit includes the affected attachment');
    assert_true(str_contains($invalid_preview, (string)$invalid_metadata_id), 'media validator invalid-metadata audit includes the affected attachment ID');

    $missing_metadata_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-media-missing-metadata',
    ]);
    assert_same(count($missing_metadata_audit['conflicts']), 2, 'media validator exposes missing required attachment metadata as plugin-scoped audit conflicts');
    $missing_metadata_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $missing_metadata_audit['conflicts']));
    assert_true(str_contains($missing_metadata_preview, '_wp_attachment_metadata'), 'media validator missing-metadata audit includes the missing attachment metadata key');
    assert_true(str_contains($missing_metadata_preview, '_wp_attached_file'), 'media validator missing-metadata audit includes the missing attached-file key');
    assert_true(str_contains($missing_metadata_preview, (string)$missing_attachment_metadata_id), 'media validator missing-metadata audit includes the missing attachment metadata ID');
    assert_true(str_contains($missing_metadata_preview, (string)$missing_attached_file_id), 'media validator missing-metadata audit includes the missing attached-file ID');

    $audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-media-generated-file-drift',
    ]);
    assert_same(count($audit['conflicts']), 4, 'media validator exposes malformed, incomplete, non-basename generated-size, and non-basename original_image metadata as plugin-scoped audit conflicts');
    $preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $audit['conflicts']));
    assert_true(str_contains($preview, 'source-generated-missing-file-key.jpg'), 'media validator audit includes the affected attachment');
    assert_true(str_contains($preview, '"generated_file":null'), 'media validator audit records the missing generated file field');
    assert_true(str_contains($preview, 'source-generated-sizes-not-array.jpg'), 'media validator audit includes the malformed generated sizes attachment');
    assert_true(str_contains($preview, '"sizes_type":"string"'), 'media validator audit records the malformed generated sizes type');
    assert_true(str_contains($preview, (string)$sizes_not_array_id), 'media validator audit includes the malformed generated sizes attachment ID');
    assert_true(str_contains($preview, 'nested/source-generated-subdir-150x150.jpg'), 'media validator audit includes the non-basename generated filename');
    assert_true(str_contains($preview, (string)$generated_subdir_id), 'media validator audit includes the non-basename generated attachment ID');
    $original_image_subdir_recorded = false;
    $meta_db = open_db($metadata);
    $payloads = $meta_db->query("SELECT chosen_payload FROM merge_conflicts WHERE conflict_type = 'plugin-wp-media-generated-file-drift'");
    while ($payload = $payloads->fetchArray(SQLITE3_ASSOC)) {
        $decoded = cow_merge_decode_payload_json((string)$payload['chosen_payload'], 'media validator generated-file payload');
        if (($decoded['candidate']['original_image'] ?? null) === 'nested/source-original-image-subdir-original.jpg') {
            $original_image_subdir_recorded = true;
        }
    }
    $payloads->finalize();
    $meta_db->close();
    assert_true($original_image_subdir_recorded, 'media validator audit payload identifies the non-basename original_image filename');
    assert_true(str_contains($preview, (string)$original_image_subdir_id), 'media validator audit includes the non-basename original_image attachment ID');

    $original_dimension_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-media-original-dimensions-drift',
    ]);
    assert_same(count($original_dimension_audit['conflicts']), 1, 'media validator exposes invalid original dimensions as a plugin-scoped audit conflict');
    $original_dimension_preview = (string)($original_dimension_audit['conflicts'][0]['chosen_preview'] ?? '');
    assert_true(str_contains($original_dimension_preview, 'source-original-dimensions.jpg'), 'media validator original-dimensions audit includes the affected attachment');
    assert_true(str_contains($original_dimension_preview, '"width":0'), 'media validator original-dimensions audit includes the invalid width');
    assert_true(str_contains($original_dimension_preview, (string)$original_dimensions_id), 'media validator original-dimensions audit includes the affected attachment ID');

    $filesize_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-media-filesize-drift',
    ]);
    assert_same(count($filesize_audit['conflicts']), 2, 'media validator exposes original and generated filesize drift as plugin-scoped audit conflicts');
    $filesize_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $filesize_audit['conflicts']));
    assert_true(str_contains($filesize_preview, 'source-filesize-drift.jpg'), 'media validator filesize audit includes the affected attachment');
    assert_true(str_contains($filesize_preview, '"declared_filesize":1'), 'media validator filesize audit includes the declared filesize');
    assert_true(str_contains($filesize_preview, (string)$filesize_drift_id), 'media validator filesize audit includes the affected attachment ID');
    $generated_filesize_recorded = false;
    $meta_db = open_db($metadata);
    $payloads = $meta_db->query("SELECT chosen_payload FROM merge_conflicts WHERE conflict_type = 'plugin-wp-media-filesize-drift'");
    while ($payload = $payloads->fetchArray(SQLITE3_ASSOC)) {
        $decoded = cow_merge_decode_payload_json((string)$payload['chosen_payload'], 'media validator filesize payload');
        if (($decoded['candidate']['generated_file'] ?? null) === '2026/05/source-generated-filesize-150x150.jpg') {
            $generated_filesize_recorded = true;
        }
    }
    $payloads->finalize();
    $meta_db->close();
    assert_true($generated_filesize_recorded, 'media validator filesize audit payload identifies the affected generated file');
    assert_true(str_contains($filesize_preview, (string)$generated_filesize_drift_id), 'media validator filesize audit includes the generated filesize attachment ID');

    $mime_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-media-mime-drift',
    ]);
    assert_same(count($mime_audit['conflicts']), 2, 'media validator exposes attachment and generated-size MIME drift as plugin-scoped audit conflicts');
    $mime_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $mime_audit['conflicts']));
    assert_true(str_contains($mime_preview, 'source-mime-drift.jpg'), 'media validator MIME drift audit includes the affected attachment');
    assert_true(str_contains($mime_preview, 'application/pdf'), 'media validator MIME drift audit includes the declared MIME type');
    assert_true(str_contains($mime_preview, 'image/jpeg'), 'media validator MIME drift audit includes the expected MIME type');
    assert_true(str_contains($mime_preview, (string)$mime_drift_id), 'media validator MIME drift audit includes the affected attachment ID');
    assert_true(str_contains($mime_preview, 'image/png'), 'media validator MIME drift audit includes the expected generated MIME type');
    assert_true(str_contains($mime_preview, (string)$generated_mime_drift_id), 'media validator MIME drift audit includes the generated-size attachment ID');
    $generated_mime_recorded = false;
    $meta_db = open_db($metadata);
    $payloads = $meta_db->query("SELECT chosen_payload FROM merge_conflicts WHERE conflict_type = 'plugin-wp-media-mime-drift'");
    while ($payload = $payloads->fetchArray(SQLITE3_ASSOC)) {
        $decoded = cow_merge_decode_payload_json((string)$payload['chosen_payload'], 'media validator MIME payload');
        if (($decoded['candidate']['generated_file'] ?? null) === '2026/05/source-generated-mime-thumb.png') {
            $generated_mime_recorded = true;
        }
    }
    $payloads->finalize();
    $meta_db->close();
    assert_true($generated_mime_recorded, 'media validator MIME drift audit payload identifies the affected generated size');

    $generated_dimension_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-media-generated-dimensions-drift',
    ]);
    assert_same(count($generated_dimension_audit['conflicts']), 1, 'media validator exposes invalid generated-size dimensions as a plugin-scoped audit conflict');
    $generated_dimension_preview = (string)($generated_dimension_audit['conflicts'][0]['chosen_preview'] ?? '');
    assert_true(str_contains($generated_dimension_preview, 'source-generated-dimensions.jpg'), 'media validator generated-dimensions audit includes the affected attachment');
    assert_true(str_contains($generated_dimension_preview, '"width":0'), 'media validator generated-dimensions audit includes the invalid generated width');
    assert_true(str_contains($generated_dimension_preview, (string)$generated_dimensions_id), 'media validator generated-dimensions audit includes the affected attachment ID');

    $missing_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-media-missing-file',
    ]);
    assert_same(count($missing_audit['conflicts']), 4, 'media validator exposes missing attached, metadata, generated, and original_image files as plugin-scoped audit conflicts');
    $missing_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $missing_audit['conflicts']));
    assert_true(str_contains($missing_preview, 'source-missing-original.jpg'), 'media validator missing-file audit includes the affected original attachment');
    $missing_metadata_original_recorded = false;
    $missing_original_image_recorded = false;
    $meta_db = open_db($metadata);
    $payloads = $meta_db->query("SELECT chosen_payload FROM merge_conflicts WHERE conflict_type = 'plugin-wp-media-missing-file'");
    while ($payload = $payloads->fetchArray(SQLITE3_ASSOC)) {
        $decoded = cow_merge_decode_payload_json((string)$payload['chosen_payload'], 'media validator missing-file payload');
        if (($decoded['candidate']['metadata_file'] ?? null) === '2026/05/source-metadata-file-mismatch-metadata.jpg') {
            $missing_metadata_original_recorded = true;
        }
        if (($decoded['candidate']['original_image_file'] ?? null) === '2026/05/source-original-image-missing-original.jpg') {
            $missing_original_image_recorded = true;
        }
    }
    $payloads->finalize();
    $meta_db->close();
    assert_true($missing_metadata_original_recorded, 'media validator missing-file audit payload identifies the missing metadata original file');
    assert_true(str_contains($missing_preview, 'source-missing-generated-150x150.jpg'), 'media validator missing-file audit includes the affected generated file');
    assert_true($missing_original_image_recorded, 'media validator missing-file audit payload identifies the missing original_image file');
    assert_true(str_contains($missing_preview, (string)$original_image_missing_id), 'media validator missing-file audit includes the missing original_image attachment ID');

    $mismatch_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-media-file-drift',
    ]);
    assert_same(count($mismatch_audit['conflicts']), 2, 'media validator exposes attached-file metadata drift as a plugin-scoped audit conflict');
    $mismatch_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $mismatch_audit['conflicts']));
    assert_true(str_contains($mismatch_preview, 'source-metadata-file-mismatch-attached.jpg'), 'media validator mismatch audit includes the attached file');
    assert_true(str_contains($mismatch_preview, 'source-metadata-file-mismatch-metadata.jpg'), 'media validator mismatch audit includes the metadata file');
    assert_true(str_contains($mismatch_preview, 'source-unsafe-metadata-file-attached.jpg'), 'media validator mismatch audit includes the unsafe metadata-file attachment');
    assert_true(str_contains($mismatch_preview, '../source-unsafe-metadata-file.jpg'), 'media validator mismatch audit includes the unsafe metadata file');

    $unsafe_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-wp-media-unsafe-path',
    ]);
    assert_same(count($unsafe_audit['conflicts']), 4, 'media validator exposes unsafe primary, metadata, generated, and original_image upload paths as plugin-scoped audit conflicts');
    $unsafe_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $unsafe_audit['conflicts']));
    assert_true(str_contains($unsafe_preview, 'source-unsafe-generated.jpg'), 'media validator unsafe-path audit includes the affected attachment');
    assert_true(str_contains($unsafe_preview, '../source-unsafe-generated-150x150.jpg'), 'media validator unsafe-path audit includes the rejected generated path');
    assert_true(str_contains($unsafe_preview, '../source-unsafe-attached.jpg'), 'media validator unsafe-path audit includes the rejected attached file path');
    assert_true(str_contains($unsafe_preview, '../source-unsafe-metadata-file.jpg'), 'media validator unsafe-path audit includes the rejected metadata file path');
    $unsafe_original_image_recorded = false;
    $meta_db = open_db($metadata);
    $payloads = $meta_db->query("SELECT chosen_payload FROM merge_conflicts WHERE conflict_type = 'plugin-wp-media-unsafe-path'");
    while ($payload = $payloads->fetchArray(SQLITE3_ASSOC)) {
        $decoded = cow_merge_decode_payload_json((string)$payload['chosen_payload'], 'media validator unsafe-path payload');
        if (($decoded['candidate']['original_image'] ?? null) === '../source-original-image-unsafe-original.jpg') {
            $unsafe_original_image_recorded = true;
        }
    }
    $payloads->finalize();
    $meta_db->close();
    assert_true($unsafe_original_image_recorded, 'media validator unsafe-path audit payload identifies the rejected original_image path');
    assert_true(str_contains($unsafe_preview, (string)$original_image_unsafe_id), 'media validator unsafe-path audit includes the unsafe original_image attachment ID');
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
    assert_same(count($duplicate_audit['conflicts']), 3, 'media validator exposes duplicate original and generated upload ownership as plugin-scoped audit conflicts');
    $duplicate_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $duplicate_audit['conflicts']));
    assert_true(str_contains($duplicate_preview, 'source-self-duplicate.jpg'), 'media validator duplicate audit includes the same-attachment duplicate filename');
    assert_true(str_contains($duplicate_preview, (string)$self_duplicate_id), 'media validator duplicate audit includes the same-attachment duplicate ID');
    assert_true(str_contains($duplicate_preview, 'source-duplicate-shared-150x150.jpg'), 'media validator duplicate audit includes the shared generated filename');
    assert_true(str_contains($duplicate_preview, (string)$duplicate_a_id), 'media validator duplicate audit includes the first shared generated attachment ID');
    assert_true(str_contains($duplicate_preview, (string)$duplicate_b_id), 'media validator duplicate audit includes the second shared generated attachment ID');
    assert_true(str_contains($duplicate_preview, 'source-duplicate-original.jpg'), 'media validator duplicate audit includes the shared original filename');
    assert_true(str_contains($duplicate_preview, (string)$duplicate_original_a_id), 'media validator duplicate audit includes the first shared original attachment ID');
    assert_true(str_contains($duplicate_preview, (string)$duplicate_original_b_id), 'media validator duplicate audit includes the second shared original attachment ID');
} finally {
    remove_tree($tmp);
}

if ($fail) {
    echo "FAILURES: $fail\n";
    exit(1);
}
echo "COW media validator focused tests passed ($pass assertions).\n";

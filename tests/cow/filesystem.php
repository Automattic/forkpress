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

function create_test_symlink(string $target, string $link): void {
    if (!is_dir(dirname($link))) {
        mkdir(dirname($link), 0777, true);
    }
    if ((file_exists($link) || is_link($link)) && !unlink($link)) {
        throw new RuntimeException("failed to replace test symlink: $link");
    }
    if (!symlink($target, $link)) {
        throw new RuntimeException("failed to create test symlink: $link");
    }
}

function open_db(string $path): SQLite3 {
    $db = new SQLite3($path);
    $db->busyTimeout(5000);
    return $db;
}

function create_filesystem_db(string $path): void {
    $db = open_db($path);
    $db->exec('CREATE TABLE plugin_items (item_id TEXT PRIMARY KEY, value TEXT NOT NULL)');
    $db->exec("INSERT INTO plugin_items (item_id, value) VALUES ('base', 'base')");
    $db->close();
}

function scalar(string $db_path, string $sql): mixed {
    $db = open_db($db_path);
    $value = $db->querySingle($sql);
    $db->close();
    return $value;
}

define('FORKPRESS_COW_MERGE_TESTS', true);
require_once __DIR__ . '/../../scripts/cow/merge.php';

echo "=== COW filesystem focused tests ===\n";

$tmp = sys_get_temp_dir() . '/forkpress-cow-filesystem-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);

try {
    $base_root = $tmp . '/base';
    $source_root = $tmp . '/source';
    $target_root = $tmp . '/target';
    $base = $base_root . '/wp-content/database/.ht.sqlite';
    $source = $source_root . '/wp-content/database/.ht.sqlite';
    $target = $target_root . '/wp-content/database/.ht.sqlite';
    $metadata = $tmp . '/.forkpress/cow/merge/filesystem-metadata.sqlite';
    $file_base = $tmp . '/.forkpress/cow/merge/file-bases/filesystem.json';

    mkdir($base_root . '/wp-content/database', 0777, true);
    create_filesystem_db($base);
    write_test_file($base_root . '/wp-content/uploads/shared.txt', 'base shared');
    write_test_file($base_root . '/wp-content/uploads/binary.bin', "base\0binary");
    copy_tree_for_test($base_root, $source_root);
    copy_tree_for_test($base_root, $target_root);

    cow_merge_capture_file_base($base_root, $file_base);

    write_test_file($source_root . '/wp-content/uploads/shared.txt', 'source shared');
    write_test_file($source_root . '/wp-content/uploads/binary.bin', "source\0binary\xff");
    create_test_symlink('/etc/passwd', $source_root . '/wp-content/uploads/absolute-link.txt');

    $result = cow_merge_branch_state(
        $base,
        $source,
        $target,
        $metadata,
        'feature-filesystem',
        'main',
        $file_base,
        $source_root,
        $target_root
    );

    assert_same($result['status'], 'completed_with_conflicts', 'filesystem merge completes with review conflicts for unsafe source paths');
    assert_same($result['file_applied'], 2, 'filesystem merge applies safe source-only text and binary file changes');
    assert_same($result['file_conflicts'], 1, 'filesystem merge records one unsafe symlink conflict');
    assert_same(file_get_contents($target_root . '/wp-content/uploads/shared.txt'), 'source shared', 'safe source text file change is applied');
    assert_same(file_get_contents($target_root . '/wp-content/uploads/binary.bin'), "source\0binary\xff", 'safe source binary file change is applied exactly');
    assert_true(!file_exists($target_root . '/wp-content/uploads/absolute-link.txt') && !is_link($target_root . '/wp-content/uploads/absolute-link.txt'), 'unsafe absolute symlink is not installed on target');

    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = '__files__' AND conflict_type = 'file-unsafe-symlink'"),
        1,
        'unsafe filesystem symlink conflict is auditable'
    );
    $audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'files',
        'records' => 'conflicts',
        'conflict_type' => 'file-unsafe-symlink',
    ]);
    assert_same(count($audit['conflicts']), 1, 'file audit can focus on unsafe symlink conflicts');
    assert_same($audit['conflicts'][0]['row_identity'], cow_merge_file_identity_json('wp-content/uploads/absolute-link.txt'), 'unsafe symlink audit points at the blocked path');
    assert_true(str_contains((string)$audit['conflicts'][0]['source_preview'], '/etc/passwd'), 'unsafe symlink audit exposes the rejected source target');
} finally {
    remove_tree($tmp);
}

if ($fail) {
    echo "FAILURES: $fail\n";
    exit(1);
}
echo "COW filesystem focused tests passed ($pass assertions).\n";

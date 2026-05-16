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

function create_explicit_id_db(string $path): void {
    $db = open_db($path);
    $db->exec('CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY AUTOINCREMENT, post_title TEXT NOT NULL, post_content TEXT NOT NULL, post_status TEXT NOT NULL)');
    $db->exec('CREATE TABLE plugin_autoinc (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT NOT NULL)');
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status) VALUES (1, 'Base page', 'Base content', 'draft')");
    $db->exec("INSERT INTO plugin_autoinc (id, label) VALUES (1, 'base plugin row')");
    $db->close();
}

define('FORKPRESS_COW_MERGE_TESTS', true);
require_once __DIR__ . '/../../scripts/cow/merge.php';

echo "=== COW explicit ID focused tests ===\n";

$tmp = sys_get_temp_dir() . '/forkpress-cow-explicit-ids-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);

try {
    $base = $tmp . '/base.sqlite';
    $source = $tmp . '/source.sqlite';
    $target = $tmp . '/target.sqlite';
    $metadata = $tmp . '/.forkpress/cow/merge/explicit-id-metadata.sqlite';

    create_explicit_id_db($base);
    copy($base, $source);
    copy($base, $target);

    $band_result = cow_merge_allocate_autoincrement_bands($source, $metadata, 'feature-explicit-import');
    assert_same($band_result['allocated'], 2, 'source branch allocates AUTOINCREMENT bands before imports');

    $source_db = open_db($source);
    $source_db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status) VALUES (2, 'Imported explicit post', 'explicit id import', 'publish')");
    $source_db->exec("INSERT INTO plugin_autoinc (id, label) VALUES (2, 'imported explicit plugin row')");
    $source_db->close();

    $result = cow_merge_databases($base, $source, $target, $metadata, 'feature-explicit-import', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'out-of-band explicit AUTOINCREMENT imports stay review-held');

    assert_same(
        (int)scalar($target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 2'),
        0,
        'out-of-band explicit WordPress post ID is not applied automatically'
    );
    assert_same(
        (int)scalar($target, 'SELECT COUNT(*) FROM plugin_autoinc WHERE id = 2'),
        0,
        'out-of-band explicit plugin AUTOINCREMENT ID is not applied automatically'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE r.source_branch = 'feature-explicit-import' AND c.table_name = 'wp_posts' AND c.conflict_type = 'row-target-constraint'"),
        1,
        'out-of-band explicit WordPress post records a review conflict'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE r.source_branch = 'feature-explicit-import' AND c.table_name = 'plugin_autoinc' AND c.conflict_type = 'row-target-constraint'"),
        1,
        'out-of-band explicit plugin row records a review conflict'
    );

    $wp_reason = (string)scalar($metadata, "SELECT reason FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE r.source_branch = 'feature-explicit-import' AND d.table_name = 'wp_posts' AND d.decision = 'target-wins' ORDER BY d.id DESC LIMIT 1");
    $plugin_reason = (string)scalar($metadata, "SELECT reason FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE r.source_branch = 'feature-explicit-import' AND d.table_name = 'plugin_autoinc' AND d.decision = 'target-wins' ORDER BY d.id DESC LIMIT 1");
    assert_true(str_contains($wp_reason, 'outside reserved branch band'), 'WordPress explicit-ID conflict explains the branch-band violation');
    assert_true(str_contains($plugin_reason, 'outside reserved branch band'), 'plugin explicit-ID conflict explains the branch-band violation');

    $audit = cow_merge_audit_report($metadata, null, 20, ['records' => 'conflicts']);
    $tables = array_map(fn($record) => $record['table_name'] ?? '', $audit['conflicts'] ?? []);
    assert_true(in_array('wp_posts', $tables, true), 'audit report exposes the WordPress explicit-ID conflict');
    assert_true(in_array('plugin_autoinc', $tables, true), 'audit report exposes the plugin explicit-ID conflict');
} finally {
    remove_tree($tmp);
}

if ($fail) {
    echo "FAILURES: $fail\n";
    exit(1);
}
echo "COW explicit ID focused tests passed ($pass assertions).\n";

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

function assert_throws(callable $fn, string $contains, string $msg): void {
    try {
        $fn();
        assert_true(false, $msg . ' (no exception thrown)');
    } catch (Throwable $e) {
        assert_true(str_contains($e->getMessage(), $contains), $msg . ' (' . $e->getMessage() . ')');
    }
}

function run_merge_cli(array $args): array {
    $script = dirname(__DIR__, 2) . '/scripts/cow/merge.php';
    $command = array_map('escapeshellarg', array_merge([PHP_BINARY, $script], $args));
    exec(implode(' ', $command) . ' 2>&1', $output, $status);
    return [
        'status' => $status,
        'output' => implode("\n", $output) . ($output === [] ? '' : "\n"),
    ];
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

function create_stale_audit_db(string $path): void {
    $db = open_db($path);
    $db->exec('CREATE TABLE plugin_items (item_id TEXT PRIMARY KEY, label TEXT NOT NULL, value TEXT NOT NULL)');
    $db->exec("INSERT INTO plugin_items (item_id, label, value) VALUES ('alpha', 'Alpha', 'base')");
    $db->close();
}

define('FORKPRESS_COW_MERGE_TESTS', true);
require_once __DIR__ . '/../../scripts/cow/merge.php';

echo "=== COW stale audit focused tests ===\n";

$tmp = sys_get_temp_dir() . '/forkpress-cow-stale-audit-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);

try {
    $base = $tmp . '/base.sqlite';
    $source = $tmp . '/source.sqlite';
    $target = $tmp . '/target.sqlite';
    $metadata = $tmp . '/.forkpress/cow/merge/stale-audit-metadata.sqlite';

    create_stale_audit_db($base);
    copy($base, $source);
    copy($base, $target);

    $source_db = open_db($source);
    $source_db->exec("UPDATE plugin_items SET value = 'source reviewed value' WHERE item_id = 'alpha'");
    $source_db->close();
    $target_db = open_db($target);
    $target_db->exec("UPDATE plugin_items SET value = 'target reviewed value' WHERE item_id = 'alpha'");
    $target_db->close();

    $merge = cow_merge_databases($base, $source, $target, $metadata, 'feature-stale-audit', 'main');
    $run_id = (int)$merge['run_id'];
    assert_same($merge['status'], 'completed_with_conflicts', 'stale audit fixture starts with a reviewable cell conflict');
    $conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_items' AND column_name = 'value'");
    assert_true($conflict_id > 0, 'stale audit fixture records the cell conflict');

    cow_merge_review_record(
        $metadata,
        'conflict',
        $conflict_id,
        'reviewed',
        'Keep target plugin value for launch.',
        'cow-test'
    );

    $target_db = open_db($target);
    $target_db->exec("UPDATE plugin_items SET value = 'target drift after review' WHERE item_id = 'alpha'");
    $target_db->close();

    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $conflict_id, 'source', true, 'Try stale source apply.', 'cow-test'),
        'target cell no longer matches the audited conflict target value',
        'stale reviewed conflict cannot apply before revalidation'
    );

    $revalidated = cow_merge_revalidate_reviewed_conflicts($metadata, $run_id, 'cow-revalidate');
    assert_same($revalidated['checked'], 1, 'revalidation checks the reviewed conflict');
    assert_same($revalidated['stale'], 1, 'revalidation detects target drift');
    assert_same($revalidated['carried'], 1, 'revalidation carries stale reviewer intent to needs-action');
    assert_same(
        scalar($metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $conflict_id ORDER BY id DESC LIMIT 1"),
        'compatible-target-drift',
        'revalidation classifies same-object target drift as compatible'
    );

    $audit = cow_merge_audit_report($metadata, $run_id, 10, [
        'records' => 'conflicts',
        'review_status' => 'needs-action',
    ]);
    assert_same(count($audit['conflicts']), 1, 'revalidated conflict enters the needs-action queue');
    assert_true(str_contains((string)$audit['conflicts'][0]['review_note'], 'Keep target plugin value for launch.'), 'revalidated note preserves prior reviewer intent');
    assert_same($audit['conflicts'][0]['revalidation_class'] ?? null, 'compatible-target-drift', 'audit exposes the revalidation classifier');

    $again = run_merge_cli([
        'revalidate-reviews',
        '--metadata-db', $metadata,
        '--run', (string)$run_id,
        '--format', 'json',
    ]);
    assert_same($again['status'], 0, 'revalidation CLI accepts already-carried stale reviews');
    $again_json = json_decode($again['output'], true);
    assert_same($again_json['carried'] ?? null, 0, 'revalidation CLI does not duplicate carried notes');
    assert_same($again_json['already_needs_action'] ?? null, 1, 'revalidation CLI reports already-carried stale reviews');

    $resolution = cow_merge_resolve_conflict(
        $metadata,
        $conflict_id,
        'source',
        true,
        'Apply source after revalidating target drift.',
        'cow-test',
        true
    );
    assert_same($resolution['status'], 'applied', 'after-revalidate source resolution applies the revalidated stale cell conflict');
    assert_same(
        scalar($target, "SELECT value FROM plugin_items WHERE item_id = 'alpha'"),
        'source reviewed value',
        'after-revalidate source resolution writes the audited source value'
    );
    assert_same(
        scalar($metadata, "SELECT previous_payload FROM merge_resolutions WHERE conflict_id = $conflict_id ORDER BY id DESC LIMIT 1"),
        cow_merge_payload_json('target drift after review'),
        'after-revalidate resolution audits the latest revalidated target payload'
    );
} finally {
    remove_tree($tmp);
}

if ($fail) {
    echo "FAILURES: $fail\n";
    exit(1);
}
echo "COW stale audit focused tests passed ($pass assertions).\n";

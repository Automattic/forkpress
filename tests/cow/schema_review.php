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

function create_schema_review_db(string $path): void {
    $db = open_db($path);
    $db->exec('CREATE TABLE plugin_trigger_cycle_self (label TEXT)');
    $db->close();
}

function create_schema_view_order_db(string $path): void {
    $db = open_db($path);
    $db->exec('CREATE TABLE plugin_view_order_items (item_id TEXT PRIMARY KEY, label TEXT NOT NULL)');
    $db->exec("INSERT INTO plugin_view_order_items (item_id, label) VALUES ('alpha', 'Alpha')");
    $db->close();
}

define('FORKPRESS_COW_MERGE_TESTS', true);
require_once __DIR__ . '/../../scripts/cow/merge.php';

echo "=== COW schema review focused tests ===\n";

$tmp = sys_get_temp_dir() . '/forkpress-cow-schema-review-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);

try {
    $base = $tmp . '/base.sqlite';
    $source = $tmp . '/source.sqlite';
    $target = $tmp . '/target.sqlite';
    $metadata = $tmp . '/.forkpress/cow/merge/schema-review-metadata.sqlite';

    create_schema_review_db($base);
    copy($base, $source);
    copy($base, $target);

    $source_db = open_db($source);
    $source_db->exec('CREATE VIEW plugin_cycle_self_view AS SELECT label FROM plugin_cycle_self_view');
    $source_db->exec('CREATE TRIGGER plugin_trigger_cycle_self_insert AFTER INSERT ON plugin_trigger_cycle_self BEGIN UPDATE plugin_trigger_cycle_self SET label = NEW.label WHERE rowid = NEW.rowid; END');
    $source_db->close();

    $result = cow_merge_databases($base, $source, $target, $metadata, 'feature-schema-review', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'cyclic source-added schema objects are held as reviewable conflicts');
    assert_same(
        (int)scalar($target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_cycle_self_view'"),
        0,
        'cyclic source-added view is not installed on target'
    );
    assert_same(
        (int)scalar($target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_trigger_cycle_self_insert'"),
        0,
        'cyclic source-added trigger is not installed on target'
    );

    $view_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_cycle_self_view' AND conflict_type = 'schema-source-added-view' ORDER BY id DESC LIMIT 1");
    $trigger_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_trigger_cycle_self_insert' AND conflict_type = 'schema-source-added-trigger' ORDER BY id DESC LIMIT 1");
    assert_true($view_conflict_id > 0, 'cyclic source-added view records a schema conflict');
    assert_true($trigger_conflict_id > 0, 'cyclic source-added trigger records a schema conflict');

    $view_payload = cow_merge_decode_payload_json(
        (string)scalar($metadata, "SELECT source_payload FROM merge_conflicts WHERE id = $view_conflict_id"),
        'cyclic view payload'
    );
    $trigger_payload = cow_merge_decode_payload_json(
        (string)scalar($metadata, "SELECT source_payload FROM merge_conflicts WHERE id = $trigger_conflict_id"),
        'cyclic trigger payload'
    );
    assert_true(
        str_contains((string)($view_payload['error'] ?? ''), 'unsupported cyclic source view dependencies') &&
            str_contains((string)($view_payload['error'] ?? ''), 'plugin_cycle_self_view -> plugin_cycle_self_view'),
        'cyclic view conflict payload records the unsupported dependency cycle'
    );
    assert_true(
        str_contains((string)($trigger_payload['error'] ?? ''), 'unsupported cyclic trigger dependencies') &&
            str_contains((string)($trigger_payload['error'] ?? ''), 'plugin_trigger_cycle_self_insert -> plugin_trigger_cycle_self_insert'),
        'cyclic trigger conflict payload records the unsupported dependency cycle'
    );

    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $view_conflict_id, 'source', true, 'Try cyclic source-added view.', 'cow-test'),
        'unsupported cyclic source view dependencies',
        'cyclic source-added view resolution remains validation-gated'
    );
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $trigger_conflict_id, 'source', true, 'Try cyclic source-added trigger.', 'cow-test'),
        'unsupported cyclic trigger dependencies',
        'cyclic source-added trigger resolution remains validation-gated'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id IN ($view_conflict_id, $trigger_conflict_id)"),
        0,
        'failed cyclic schema resolution attempts do not record resolutions'
    );

    $view_order_base = $tmp . '/view-order-base.sqlite';
    $view_order_source = $tmp . '/view-order-source.sqlite';
    $view_order_target = $tmp . '/view-order-target.sqlite';
    $view_order_metadata = $tmp . '/.forkpress/cow/merge/schema-view-order-metadata.sqlite';

    create_schema_view_order_db($view_order_base);
    copy($view_order_base, $view_order_source);
    copy($view_order_base, $view_order_target);

    $source_db = open_db($view_order_source);
    $source_db->exec('CREATE VIEW plugin_a_source_grandchild_view AS SELECT label FROM plugin_z_source_parent_view');
    $source_db->exec('CREATE VIEW plugin_z_source_parent_view AS SELECT label FROM plugin_view_order_items');
    $source_db->close();

    $view_order_result = cow_merge_databases($view_order_base, $view_order_source, $view_order_target, $view_order_metadata, 'feature-schema-view-order', 'main');
    $view_order_run_id = (int)$view_order_result['run_id'];
    assert_same($view_order_result['status'], 'completed', 'acyclic source-added dependent views merge automatically');
    assert_same(
        scalar($view_order_target, "SELECT label FROM plugin_a_source_grandchild_view WHERE label = 'Alpha'"),
        'Alpha',
        'source-added dependent view chain remains queryable after merge'
    );
    assert_same(
        (int)scalar($view_order_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE run_id = $view_order_run_id AND conflict_type = 'schema-source-added-view'"),
        0,
        'acyclic source-added dependent view ordering creates no review-only schema conflicts'
    );
    assert_true(
        (int)scalar($view_order_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE run_id = $view_order_run_id AND column_name IN ('plugin_z_source_parent_view', 'plugin_a_source_grandchild_view') AND decision = 'source-applied'") >= 2,
        'source-added dependent view creation order is auditable'
    );
} finally {
    remove_tree($tmp);
}

if ($fail) {
    echo "FAILURES: $fail\n";
    exit(1);
}
echo "COW schema review focused tests passed ($pass assertions).\n";

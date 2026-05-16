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

function create_schema_trigger_order_db(string $path): void {
    $db = open_db($path);
    $db->exec('CREATE TABLE plugin_trigger_order_items (item_id TEXT DEFAULT "default-id", label TEXT DEFAULT "default-label")');
    $db->exec('CREATE TABLE plugin_trigger_order_audit (item_id TEXT, label TEXT)');
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
    $schema_review_run_id = (int)$result['run_id'];
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
    cow_merge_review_record(
        $metadata,
        'conflict',
        $view_conflict_id,
        'reviewed',
        'Review cyclic source-added view after schema dependencies are updated.',
        'cow-test'
    );
    cow_merge_review_record(
        $metadata,
        'conflict',
        $trigger_conflict_id,
        'reviewed',
        'Review cyclic source-added trigger after schema dependencies are updated.',
        'cow-test'
    );
    $source_db = open_db($source);
    $source_db->exec('DROP VIEW plugin_cycle_self_view');
    $source_db->exec('CREATE VIEW plugin_cycle_self_view AS SELECT label FROM plugin_trigger_cycle_self');
    $source_db->exec('DROP TRIGGER plugin_trigger_cycle_self_insert');
    $source_db->exec('CREATE TRIGGER plugin_trigger_cycle_self_insert AFTER INSERT ON plugin_trigger_cycle_self BEGIN SELECT NEW.label; END');
    $source_db->close();
    $schema_object_revalidated = cow_merge_revalidate_reviewed_conflicts($metadata, $schema_review_run_id, 'cow-revalidate');
    assert_same($schema_object_revalidated['checked'], 2, 'schema object revalidation checks reviewed view and trigger conflicts');
    assert_same($schema_object_revalidated['reviewed'], 2, 'schema object revalidation sees reviewed view and trigger conflicts');
    assert_same($schema_object_revalidated['stale'], 2, 'schema object revalidation detects changed source view and trigger SQL');
    assert_same($schema_object_revalidated['carried'], 2, 'schema object revalidation carries changed source object evidence to needs-action');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_revalidations WHERE conflict_id IN ($view_conflict_id, $trigger_conflict_id) AND revalidation_class = 'unclassified'"),
        2,
        'schema object source drift remains unclassified until a schema planner proves compatibility'
    );
    assert_true(
        str_contains((string)scalar($metadata, "SELECT stale_reason FROM merge_revalidations WHERE conflict_id = $view_conflict_id ORDER BY id DESC LIMIT 1"), 'view source changed'),
        'schema view source drift explains that source schema changed after review'
    );
    assert_true(
        str_contains((string)scalar($metadata, "SELECT stale_reason FROM merge_revalidations WHERE conflict_id = $trigger_conflict_id ORDER BY id DESC LIMIT 1"), 'trigger source changed'),
        'schema trigger source drift explains that source schema changed after review'
    );
    $view_revalidate_source_payload = cow_merge_decode_payload_json(
        (string)scalar($metadata, "SELECT source_payload FROM merge_revalidations WHERE conflict_id = $view_conflict_id ORDER BY id DESC LIMIT 1"),
        'schema view revalidation source'
    );
    $trigger_revalidate_source_payload = cow_merge_decode_payload_json(
        (string)scalar($metadata, "SELECT source_payload FROM merge_revalidations WHERE conflict_id = $trigger_conflict_id ORDER BY id DESC LIMIT 1"),
        'schema trigger revalidation source'
    );
    assert_true(
        str_contains((string)($view_revalidate_source_payload['sql'] ?? ''), 'SELECT label FROM plugin_trigger_cycle_self'),
        'schema view revalidation records the updated source SQL'
    );
    assert_true(
        str_contains((string)($trigger_revalidate_source_payload['sql'] ?? ''), 'SELECT NEW.label'),
        'schema trigger revalidation records the updated source SQL'
    );
    $schema_object_audit = cow_merge_audit_report($metadata, $schema_review_run_id, 10, [
        'records' => 'conflicts',
        'review_status' => 'needs-action',
    ]);
    $schema_object_needs_action = array_values(array_filter(
        $schema_object_audit['conflicts'],
        fn($conflict) => in_array((int)($conflict['id'] ?? 0), [$view_conflict_id, $trigger_conflict_id], true)
    ));
    assert_same(count($schema_object_needs_action), 2, 'schema object source drift returns reviewed conflicts to the needs-action audit queue');
    foreach ($schema_object_needs_action as $conflict) {
        assert_same($conflict['revalidation_class'] ?? null, 'unclassified', 'schema object audit exposes conservative unclassified revalidation');
    }

    $schema_target_drift_base = $tmp . '/schema-target-drift-base.sqlite';
    $schema_target_drift_source = $tmp . '/schema-target-drift-source.sqlite';
    $schema_target_drift_target = $tmp . '/schema-target-drift-target.sqlite';
    $schema_target_drift_metadata = $tmp . '/.forkpress/cow/merge/schema-target-drift-metadata.sqlite';

    $db = open_db($schema_target_drift_base);
    $db->exec('CREATE TABLE plugin_schema_target_drift_items (label TEXT NOT NULL)');
    $db->exec("INSERT INTO plugin_schema_target_drift_items (label) VALUES ('target drift anchor')");
    $db->close();
    copy($schema_target_drift_base, $schema_target_drift_source);
    copy($schema_target_drift_base, $schema_target_drift_target);

    $source_db = open_db($schema_target_drift_source);
    $source_db->exec('CREATE VIEW plugin_schema_target_drift_view AS SELECT label FROM plugin_schema_target_drift_view');
    $source_db->exec('CREATE TRIGGER plugin_schema_target_drift_trigger AFTER INSERT ON plugin_schema_target_drift_items BEGIN UPDATE plugin_schema_target_drift_items SET label = NEW.label WHERE rowid = NEW.rowid; END');
    $source_db->close();

    $schema_target_drift_result = cow_merge_databases(
        $schema_target_drift_base,
        $schema_target_drift_source,
        $schema_target_drift_target,
        $schema_target_drift_metadata,
        'feature-schema-target-drift',
        'main'
    );
    $schema_target_drift_run_id = (int)$schema_target_drift_result['run_id'];
    assert_same($schema_target_drift_result['status'], 'completed_with_conflicts', 'schema target-drift fixture starts with reviewed source-added view and trigger conflicts');
    $schema_target_drift_conflict_id = (int)scalar($schema_target_drift_metadata, "SELECT id FROM merge_conflicts WHERE conflict_type = 'schema-source-added-view' AND column_name = 'plugin_schema_target_drift_view' ORDER BY id DESC LIMIT 1");
    assert_true($schema_target_drift_conflict_id > 0, 'schema target-drift fixture records the source-added view conflict');
    $schema_trigger_target_drift_conflict_id = (int)scalar($schema_target_drift_metadata, "SELECT id FROM merge_conflicts WHERE conflict_type = 'schema-source-added-trigger' AND column_name = 'plugin_schema_target_drift_trigger' ORDER BY id DESC LIMIT 1");
    assert_true($schema_trigger_target_drift_conflict_id > 0, 'schema target-drift fixture records the source-added trigger conflict');
    cow_merge_review_record(
        $schema_target_drift_metadata,
        'conflict',
        $schema_target_drift_conflict_id,
        'reviewed',
        'Review source-added view before target creates the same object.',
        'cow-test'
    );
    cow_merge_review_record(
        $schema_target_drift_metadata,
        'conflict',
        $schema_trigger_target_drift_conflict_id,
        'reviewed',
        'Review source-added trigger before target creates the same object.',
        'cow-test'
    );

    $target_db = open_db($schema_target_drift_target);
    $target_db->exec('CREATE VIEW plugin_schema_target_drift_view AS SELECT label FROM plugin_schema_target_drift_items');
    $target_db->exec('CREATE TRIGGER plugin_schema_target_drift_trigger AFTER INSERT ON plugin_schema_target_drift_items BEGIN SELECT NEW.label; END');
    $target_db->close();

    $schema_target_drift_revalidated = cow_merge_revalidate_reviewed_conflicts($schema_target_drift_metadata, $schema_target_drift_run_id, 'cow-revalidate');
    assert_same($schema_target_drift_revalidated['checked'], 2, 'schema target SQL drift revalidation checks reviewed view and trigger conflicts');
    assert_same($schema_target_drift_revalidated['reviewed'], 2, 'schema target SQL drift revalidation sees reviewed view and trigger conflicts');
    assert_same($schema_target_drift_revalidated['stale'], 2, 'schema target SQL drift is treated as stale for views and triggers');
    assert_same($schema_target_drift_revalidated['carried'], 2, 'schema target SQL drift returns view and trigger conflicts to needs-action');
    assert_true(
        str_contains((string)scalar($schema_target_drift_metadata, "SELECT stale_reason FROM merge_revalidations WHERE conflict_id = $schema_target_drift_conflict_id ORDER BY id DESC LIMIT 1"), 'view target changed'),
        'schema target SQL drift explains that the target schema changed after review'
    );
    assert_true(
        str_contains((string)scalar($schema_target_drift_metadata, "SELECT stale_reason FROM merge_revalidations WHERE conflict_id = $schema_trigger_target_drift_conflict_id ORDER BY id DESC LIMIT 1"), 'trigger target changed'),
        'schema trigger target SQL drift explains that the target schema changed after review'
    );
    $schema_target_drift_payload = cow_merge_decode_payload_json(
        (string)scalar($schema_target_drift_metadata, "SELECT target_payload FROM merge_revalidations WHERE conflict_id = $schema_target_drift_conflict_id ORDER BY id DESC LIMIT 1"),
        'schema target-drift target payload'
    );
    assert_true(
        str_contains((string)$schema_target_drift_payload, 'SELECT label FROM plugin_schema_target_drift_items'),
        'schema target SQL drift records the current target SQL evidence'
    );
    $schema_trigger_target_drift_payload = cow_merge_decode_payload_json(
        (string)scalar($schema_target_drift_metadata, "SELECT target_payload FROM merge_revalidations WHERE conflict_id = $schema_trigger_target_drift_conflict_id ORDER BY id DESC LIMIT 1"),
        'schema trigger target-drift target payload'
    );
    assert_true(
        str_contains((string)$schema_trigger_target_drift_payload, 'SELECT NEW.label'),
        'schema trigger target SQL drift records the current target SQL evidence'
    );
    $schema_target_drift_audit = cow_merge_audit_report($schema_target_drift_metadata, $schema_target_drift_run_id, 10, [
        'records' => 'conflicts',
        'review_status' => 'needs-action',
    ]);
    assert_same(count($schema_target_drift_audit['conflicts']), 2, 'schema target SQL drift is visible in the needs-action audit queue');
    foreach ($schema_target_drift_audit['conflicts'] as $conflict) {
        assert_same($conflict['revalidation_class'] ?? null, 'unclassified', 'schema target SQL drift remains unclassified until a planner proves compatibility');
    }

    $schema_index_target_drift_base = $tmp . '/schema-index-target-drift-base.sqlite';
    $schema_index_target_drift_source = $tmp . '/schema-index-target-drift-source.sqlite';
    $schema_index_target_drift_target = $tmp . '/schema-index-target-drift-target.sqlite';
    $schema_index_target_drift_metadata = $tmp . '/.forkpress/cow/merge/schema-index-target-drift-metadata.sqlite';

    $db = open_db($schema_index_target_drift_base);
    $db->exec('CREATE TABLE plugin_schema_index_target_drift_items (label TEXT NOT NULL)');
    $db->exec("INSERT INTO plugin_schema_index_target_drift_items (label) VALUES ('base label')");
    $db->close();
    copy($schema_index_target_drift_base, $schema_index_target_drift_source);
    copy($schema_index_target_drift_base, $schema_index_target_drift_target);

    $source_db = open_db($schema_index_target_drift_source);
    $source_db->exec('CREATE UNIQUE INDEX plugin_schema_index_target_drift_idx ON plugin_schema_index_target_drift_items(lower(label))');
    $source_db->close();

    $target_db = open_db($schema_index_target_drift_target);
    $target_db->exec("INSERT INTO plugin_schema_index_target_drift_items (label) VALUES ('BASE LABEL')");
    $target_db->close();

    $schema_index_target_drift_result = cow_merge_databases(
        $schema_index_target_drift_base,
        $schema_index_target_drift_source,
        $schema_index_target_drift_target,
        $schema_index_target_drift_metadata,
        'feature-schema-index-target-drift',
        'main'
    );
    $schema_index_target_drift_run_id = (int)$schema_index_target_drift_result['run_id'];
    assert_same($schema_index_target_drift_result['status'], 'completed_with_conflicts', 'schema index target-drift fixture starts with a blocked source-added index conflict');
    $schema_index_target_drift_conflict_id = (int)scalar($schema_index_target_drift_metadata, "SELECT id FROM merge_conflicts WHERE conflict_type = 'schema-source-added-index' AND column_name = 'plugin_schema_index_target_drift_idx' ORDER BY id DESC LIMIT 1");
    assert_true($schema_index_target_drift_conflict_id > 0, 'schema index target-drift fixture records the source-added index conflict');
    cow_merge_review_record(
        $schema_index_target_drift_metadata,
        'conflict',
        $schema_index_target_drift_conflict_id,
        'reviewed',
        'Review source-added index before target creates the same object.',
        'cow-test'
    );

    $target_db = open_db($schema_index_target_drift_target);
    $target_db->exec("DELETE FROM plugin_schema_index_target_drift_items WHERE label = 'BASE LABEL'");
    $target_db->exec('CREATE INDEX plugin_schema_index_target_drift_idx ON plugin_schema_index_target_drift_items(label)');
    $target_db->close();

    $schema_index_target_drift_revalidated = cow_merge_revalidate_reviewed_conflicts($schema_index_target_drift_metadata, $schema_index_target_drift_run_id, 'cow-revalidate');
    assert_same($schema_index_target_drift_revalidated['checked'], 1, 'schema index target SQL drift revalidation checks the reviewed conflict');
    assert_same($schema_index_target_drift_revalidated['reviewed'], 1, 'schema index target SQL drift revalidation sees the reviewed conflict');
    assert_same($schema_index_target_drift_revalidated['stale'], 1, 'schema index target SQL drift is treated as stale');
    assert_same($schema_index_target_drift_revalidated['carried'], 1, 'schema index target SQL drift returns the conflict to needs-action');
    assert_true(
        str_contains((string)scalar($schema_index_target_drift_metadata, "SELECT stale_reason FROM merge_revalidations WHERE conflict_id = $schema_index_target_drift_conflict_id ORDER BY id DESC LIMIT 1"), 'index target changed'),
        'schema index target SQL drift explains that the target index changed after review'
    );
    $schema_index_target_drift_payload = cow_merge_decode_payload_json(
        (string)scalar($schema_index_target_drift_metadata, "SELECT target_payload FROM merge_revalidations WHERE conflict_id = $schema_index_target_drift_conflict_id ORDER BY id DESC LIMIT 1"),
        'schema index target-drift target payload'
    );
    assert_true(
        str_contains((string)$schema_index_target_drift_payload, 'CREATE INDEX plugin_schema_index_target_drift_idx'),
        'schema index target SQL drift records the current target SQL evidence'
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

    $view_source_table_base = $tmp . '/view-source-table-base.sqlite';
    $view_source_table_source = $tmp . '/view-source-table-source.sqlite';
    $view_source_table_target = $tmp . '/view-source-table-target.sqlite';
    $view_source_table_metadata = $tmp . '/.forkpress/cow/merge/schema-view-source-table-metadata.sqlite';

    $db = open_db($view_source_table_base);
    $db->exec('CREATE TABLE plugin_view_source_table_anchor (anchor_id TEXT PRIMARY KEY)');
    $db->exec("INSERT INTO plugin_view_source_table_anchor (anchor_id) VALUES ('base-anchor')");
    $db->close();
    copy($view_source_table_base, $view_source_table_source);
    copy($view_source_table_base, $view_source_table_target);

    $source_db = open_db($view_source_table_source);
    $source_db->exec('CREATE TABLE plugin_view_source_table_items (item_id TEXT PRIMARY KEY, label TEXT NOT NULL)');
    $source_db->exec("INSERT INTO plugin_view_source_table_items (item_id, label) VALUES ('source-view-table', 'Source View Table')");
    $source_db->exec('CREATE VIEW plugin_view_source_table_labels AS SELECT label FROM plugin_view_source_table_items');
    $source_db->close();

    $view_source_table_result = cow_merge_databases(
        $view_source_table_base,
        $view_source_table_source,
        $view_source_table_target,
        $view_source_table_metadata,
        'feature-schema-view-source-table',
        'main'
    );
    $view_source_table_run_id = (int)$view_source_table_result['run_id'];
    assert_same($view_source_table_result['status'], 'completed', 'source-added views depending on source-added tables merge automatically');
    assert_same(
        scalar($view_source_table_target, "SELECT label FROM plugin_view_source_table_labels WHERE label = 'Source View Table'"),
        'Source View Table',
        'source-added view can query its source-added dependency table after merge'
    );
    assert_same(
        (int)scalar($view_source_table_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE run_id = $view_source_table_run_id AND conflict_type = 'schema-source-added-view'"),
        0,
        'source-added view dependencies on source-added tables create no review-only schema conflicts'
    );
    assert_same(
        (int)scalar($view_source_table_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE run_id = $view_source_table_run_id AND column_name = 'plugin_view_source_table_labels' AND decision = 'source-applied'"),
        1,
        'source-added view creation after its dependency table is auditable'
    );

    $trigger_order_base = $tmp . '/trigger-order-base.sqlite';
    $trigger_order_source = $tmp . '/trigger-order-source.sqlite';
    $trigger_order_target = $tmp . '/trigger-order-target.sqlite';
    $trigger_order_metadata = $tmp . '/.forkpress/cow/merge/schema-trigger-order-metadata.sqlite';

    create_schema_trigger_order_db($trigger_order_base);
    copy($trigger_order_base, $trigger_order_source);
    copy($trigger_order_base, $trigger_order_target);

    $source_db = open_db($trigger_order_source);
    $source_db->exec('CREATE VIEW plugin_trigger_order_view AS SELECT item_id, label FROM plugin_trigger_order_items');
    $source_db->exec('CREATE TRIGGER z_plugin_trigger_order_view_insert INSTEAD OF INSERT ON plugin_trigger_order_view BEGIN INSERT INTO plugin_trigger_order_audit (item_id, label) VALUES (NEW.item_id, NEW.label); END');
    $source_db->exec('CREATE TRIGGER a_plugin_trigger_order_items_after AFTER INSERT ON plugin_trigger_order_items BEGIN INSERT INTO plugin_trigger_order_view (item_id, label) VALUES (NEW.item_id, NEW.label); END');
    $source_db->close();

    $trigger_order_result = cow_merge_databases(
        $trigger_order_base,
        $trigger_order_source,
        $trigger_order_target,
        $trigger_order_metadata,
        'feature-schema-trigger-order',
        'main'
    );
    $trigger_order_run_id = (int)$trigger_order_result['run_id'];
    assert_same($trigger_order_result['status'], 'completed', 'source-added trigger dependencies merge automatically in dependency order');
    assert_same(
        (int)scalar($trigger_order_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name IN ('a_plugin_trigger_order_items_after', 'z_plugin_trigger_order_view_insert')"),
        2,
        'dependent source-added triggers both install'
    );
    assert_same(
        (int)scalar($trigger_order_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE run_id = $trigger_order_run_id AND conflict_type = 'schema-source-added-trigger'"),
        0,
        'ordered source-added triggers create no review-only schema conflicts'
    );
    assert_same(
        (int)scalar($trigger_order_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE run_id = $trigger_order_run_id AND column_name IN ('a_plugin_trigger_order_items_after', 'z_plugin_trigger_order_view_insert') AND decision = 'source-applied'"),
        2,
        'source-added dependent trigger creation order is auditable'
    );
    $target_db = open_db($trigger_order_target);
    $target_db->exec("INSERT INTO plugin_trigger_order_items (item_id, label) VALUES ('trigger-order', 'Trigger Order')");
    $target_db->close();
    assert_same(
        scalar($trigger_order_target, "SELECT label FROM plugin_trigger_order_audit WHERE item_id = 'trigger-order'"),
        'Trigger Order',
        'dependent source-added trigger chain fires after ordered materialization'
    );

    $trigger_source_table_base = $tmp . '/trigger-source-table-base.sqlite';
    $trigger_source_table_source = $tmp . '/trigger-source-table-source.sqlite';
    $trigger_source_table_target = $tmp . '/trigger-source-table-target.sqlite';
    $trigger_source_table_metadata = $tmp . '/.forkpress/cow/merge/schema-trigger-source-table-metadata.sqlite';

    $db = open_db($trigger_source_table_base);
    $db->exec('CREATE TABLE plugin_trigger_source_table_items (item_id TEXT PRIMARY KEY, label TEXT NOT NULL)');
    $db->close();
    copy($trigger_source_table_base, $trigger_source_table_source);
    copy($trigger_source_table_base, $trigger_source_table_target);

    $source_db = open_db($trigger_source_table_source);
    $source_db->exec('CREATE TABLE plugin_trigger_source_table_audit (item_id TEXT, label TEXT)');
    $source_db->exec('CREATE TABLE plugin_trigger_source_table_gate (enabled INTEGER NOT NULL)');
    $source_db->exec('INSERT INTO plugin_trigger_source_table_gate (enabled) VALUES (1)');
    $source_db->exec('CREATE TRIGGER plugin_trigger_source_table_items_after AFTER INSERT ON plugin_trigger_source_table_items WHEN EXISTS (SELECT 1 FROM plugin_trigger_source_table_gate WHERE enabled = 1) BEGIN INSERT INTO plugin_trigger_source_table_audit (item_id, label) VALUES (NEW.item_id, NEW.label); END');
    $source_db->close();

    $trigger_source_table_result = cow_merge_databases(
        $trigger_source_table_base,
        $trigger_source_table_source,
        $trigger_source_table_target,
        $trigger_source_table_metadata,
        'feature-schema-trigger-source-table',
        'main'
    );
    $trigger_source_table_run_id = (int)$trigger_source_table_result['run_id'];
    assert_same($trigger_source_table_result['status'], 'completed', 'source-added triggers depending on source-added tables merge automatically');
    assert_same(
        (int)scalar($trigger_source_table_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_trigger_source_table_audit'"),
        1,
        'source-added trigger body dependency table installs before trigger validation'
    );
    assert_same(
        (int)scalar($trigger_source_table_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_trigger_source_table_gate'"),
        1,
        'source-added trigger WHEN dependency table installs before trigger validation'
    );
    assert_same(
        (int)scalar($trigger_source_table_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_trigger_source_table_items_after'"),
        1,
        'source-added trigger depending on a source-added table installs'
    );
    assert_same(
        (int)scalar($trigger_source_table_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE run_id = $trigger_source_table_run_id AND conflict_type = 'schema-source-added-trigger'"),
        0,
        'source-added trigger dependencies on source-added tables create no review-only schema conflicts'
    );
    assert_same(
        (int)scalar($trigger_source_table_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE run_id = $trigger_source_table_run_id AND column_name = 'plugin_trigger_source_table_items_after' AND decision = 'source-applied'"),
        1,
        'source-added trigger creation order is auditable'
    );
    $target_db = open_db($trigger_source_table_target);
    $target_db->exec("INSERT INTO plugin_trigger_source_table_items (item_id, label) VALUES ('source-table-trigger', 'Source Table Trigger')");
    $target_db->close();
    assert_same(
        scalar($trigger_source_table_target, "SELECT label FROM plugin_trigger_source_table_audit WHERE item_id = 'source-table-trigger'"),
        'Source Table Trigger',
        'source-added trigger can use source-added body and WHEN dependency tables after merge'
    );

    $trigger_dependency_base = $tmp . '/trigger-dependency-base.sqlite';
    $trigger_dependency_source = $tmp . '/trigger-dependency-source.sqlite';
    $trigger_dependency_target = $tmp . '/trigger-dependency-target.sqlite';
    $trigger_dependency_metadata = $tmp . '/.forkpress/cow/merge/schema-trigger-dependency-metadata.sqlite';

    $db = open_db($trigger_dependency_base);
    $db->exec('CREATE TABLE plugin_trigger_audit (item_label TEXT)');
    $db->close();
    copy($trigger_dependency_base, $trigger_dependency_source);
    copy($trigger_dependency_base, $trigger_dependency_target);

    $source_db = open_db($trigger_dependency_source);
    $source_db->exec('CREATE TABLE plugin_trigger_items (label TEXT)');
    $source_db->exec("INSERT INTO plugin_trigger_items (rowid, label) VALUES (5, 'source item')");
    $source_db->exec('CREATE TRIGGER plugin_trigger_items_audit AFTER INSERT ON plugin_trigger_items BEGIN INSERT INTO plugin_trigger_audit (item_label) VALUES (NEW.label); END');
    $source_db->close();

    $target_db = open_db($trigger_dependency_target);
    $target_db->exec('DROP TABLE plugin_trigger_audit');
    $target_db->close();

    $trigger_dependency_result = cow_merge_databases(
        $trigger_dependency_base,
        $trigger_dependency_source,
        $trigger_dependency_target,
        $trigger_dependency_metadata,
        'feature-schema-trigger-dependency',
        'main'
    );
    assert_same($trigger_dependency_result['status'], 'completed_with_conflicts', 'source-added trigger with missing dependency is held for review');
    assert_same(
        (int)scalar($trigger_dependency_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_trigger_items'"),
        1,
        'source-added trigger table still materializes before trigger review'
    );
    assert_same(
        scalar($trigger_dependency_target, 'SELECT label FROM plugin_trigger_items WHERE rowid = 5'),
        'source item',
        'source-added trigger table rows materialize before trigger review'
    );
    assert_same(
        (int)scalar($trigger_dependency_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_trigger_items_audit'"),
        0,
        'source-added trigger is not installed while its dependency is missing'
    );

    $trigger_dependency_table_conflict_id = (int)scalar($trigger_dependency_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_trigger_audit' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    $trigger_dependency_trigger_conflict_id = (int)scalar($trigger_dependency_metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_trigger_items_audit' AND conflict_type = 'schema-source-added-trigger' ORDER BY id DESC LIMIT 1");
    assert_true($trigger_dependency_table_conflict_id > 0, 'missing trigger dependency records a table restore conflict');
    assert_true($trigger_dependency_trigger_conflict_id > 0, 'missing trigger dependency records a source-added trigger conflict');
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $trigger_dependency_metadata,
            $trigger_dependency_trigger_conflict_id,
            'source',
            true,
            'Try trigger before audit table restore.',
            'cow-test'
        ),
        'references missing target schema objects',
        'source-added trigger resolution remains gated until its dependency is restored'
    );

    $trigger_dependency_table_resolution = cow_merge_resolve_conflict(
        $trigger_dependency_metadata,
        $trigger_dependency_table_conflict_id,
        'source',
        true,
        'Restore trigger audit table before trigger.',
        'cow-test'
    );
    assert_same($trigger_dependency_table_resolution['status'], 'applied', 'trigger dependency table restore applies before trigger resolution');
    $trigger_dependency_trigger_resolution = cow_merge_resolve_conflict(
        $trigger_dependency_metadata,
        $trigger_dependency_trigger_conflict_id,
        'source',
        true,
        'Apply trigger after dependency restore.',
        'cow-test'
    );
    assert_same($trigger_dependency_trigger_resolution['status'], 'applied', 'source-added trigger applies after its dependency exists');

    $target_db = open_db($trigger_dependency_target);
    $target_db->exec("INSERT INTO plugin_trigger_items (label) VALUES ('post-review item')");
    $target_db->close();
    assert_same(
        scalar($trigger_dependency_target, "SELECT item_label FROM plugin_trigger_audit WHERE item_label = 'post-review item'"),
        'post-review item',
        'reviewed source trigger is functional after dependency restore'
    );

    $table_restore_revalidate_base = $tmp . '/table-restore-revalidate-base.sqlite';
    $table_restore_revalidate_source = $tmp . '/table-restore-revalidate-source.sqlite';
    $table_restore_revalidate_target = $tmp . '/table-restore-revalidate-target.sqlite';
    $table_restore_revalidate_metadata = $tmp . '/.forkpress/cow/merge/schema-table-restore-revalidate-metadata.sqlite';

    $db = open_db($table_restore_revalidate_base);
    $db->exec('CREATE TABLE plugin_restore_revalidate (id INTEGER PRIMARY KEY, label TEXT)');
    $db->exec("INSERT INTO plugin_restore_revalidate (id, label) VALUES (1, 'Alpha')");
    $db->close();
    copy($table_restore_revalidate_base, $table_restore_revalidate_source);
    copy($table_restore_revalidate_base, $table_restore_revalidate_target);

    $source_db = open_db($table_restore_revalidate_source);
    $source_db->exec('CREATE INDEX plugin_restore_revalidate_label_idx ON plugin_restore_revalidate(lower(label))');
    $source_db->close();

    $target_db = open_db($table_restore_revalidate_target);
    $target_db->exec('DROP TABLE plugin_restore_revalidate');
    $target_db->close();

    $table_restore_revalidate_result = cow_merge_databases(
        $table_restore_revalidate_base,
        $table_restore_revalidate_source,
        $table_restore_revalidate_target,
        $table_restore_revalidate_metadata,
        'feature-schema-table-restore-revalidate',
        'main'
    );
    assert_same($table_restore_revalidate_result['status'], 'completed_with_conflicts', 'reviewed table restore fixture starts reviewable');
    $table_restore_revalidate_run_id = (int)$table_restore_revalidate_result['run_id'];
    $table_restore_revalidate_conflict_id = (int)scalar($table_restore_revalidate_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_restore_revalidate' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    assert_true($table_restore_revalidate_conflict_id > 0, 'reviewed table restore fixture records a table conflict');
    cow_merge_review_record(
        $table_restore_revalidate_metadata,
        'conflict',
        $table_restore_revalidate_conflict_id,
        'reviewed',
        'Review dropped table restore after source schema is stable.',
        'cow-test'
    );

    $source_db = open_db($table_restore_revalidate_source);
    $source_db->exec('DROP INDEX plugin_restore_revalidate_label_idx');
    $source_db->exec('CREATE INDEX plugin_restore_revalidate_label_idx ON plugin_restore_revalidate(upper(label))');
    $source_db->close();

    $table_restore_revalidated = cow_merge_revalidate_reviewed_conflicts($table_restore_revalidate_metadata, $table_restore_revalidate_run_id, 'cow-revalidate');
    assert_same($table_restore_revalidated['checked'], 2, 'schema table restore revalidation checks the run conflict set');
    assert_same($table_restore_revalidated['reviewed'], 1, 'schema table restore revalidation sees the reviewed conflict');
    assert_same($table_restore_revalidated['stale'], 1, 'schema table restore revalidation detects changed source restore payload');
    assert_same($table_restore_revalidated['carried'], 1, 'schema table restore revalidation carries changed source evidence to needs-action');
    assert_same(
        scalar($table_restore_revalidate_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $table_restore_revalidate_conflict_id ORDER BY id DESC LIMIT 1"),
        'unclassified',
        'schema table restore source drift remains unclassified until a schema planner proves compatibility'
    );
    assert_true(
        str_contains((string)scalar($table_restore_revalidate_metadata, "SELECT stale_reason FROM merge_revalidations WHERE conflict_id = $table_restore_revalidate_conflict_id ORDER BY id DESC LIMIT 1"), 'source changed'),
        'schema table restore source drift explains that source schema changed after review'
    );
    $table_restore_revalidate_source_payload = cow_merge_decode_payload_json(
        (string)scalar($table_restore_revalidate_metadata, "SELECT source_payload FROM merge_revalidations WHERE conflict_id = $table_restore_revalidate_conflict_id ORDER BY id DESC LIMIT 1"),
        'schema table restore revalidation source'
    );
    assert_true(
        str_contains((string)($table_restore_revalidate_source_payload['indexes'][0]['sql'] ?? ''), 'upper(label)'),
        'schema table restore revalidation records the updated source index SQL'
    );
    $table_restore_revalidate_audit = cow_merge_audit_report($table_restore_revalidate_metadata, $table_restore_revalidate_run_id, 10, [
        'records' => 'conflicts',
        'review_status' => 'needs-action',
    ]);
    $table_restore_revalidate_conflicts = array_values(array_filter($table_restore_revalidate_audit['conflicts'], fn($conflict) => (int)($conflict['id'] ?? 0) === $table_restore_revalidate_conflict_id));
    assert_same(count($table_restore_revalidate_conflicts), 1, 'schema table restore source drift returns the reviewed conflict to the needs-action audit queue');
    assert_same($table_restore_revalidate_conflicts[0]['revalidation_class'] ?? null, 'unclassified', 'schema table restore audit exposes conservative unclassified revalidation');

    $table_restore_target_drift_base = $tmp . '/table-restore-target-drift-base.sqlite';
    $table_restore_target_drift_source = $tmp . '/table-restore-target-drift-source.sqlite';
    $table_restore_target_drift_target = $tmp . '/table-restore-target-drift-target.sqlite';
    $table_restore_target_drift_metadata = $tmp . '/.forkpress/cow/merge/schema-table-restore-target-drift-metadata.sqlite';

    $db = open_db($table_restore_target_drift_base);
    $db->exec('CREATE TABLE plugin_restore_target_drift (id INTEGER PRIMARY KEY, label TEXT)');
    $db->exec("INSERT INTO plugin_restore_target_drift (id, label) VALUES (1, 'Alpha')");
    $db->close();
    copy($table_restore_target_drift_base, $table_restore_target_drift_source);
    copy($table_restore_target_drift_base, $table_restore_target_drift_target);

    $target_db = open_db($table_restore_target_drift_target);
    $target_db->exec('DROP TABLE plugin_restore_target_drift');
    $target_db->close();

    $table_restore_target_drift_result = cow_merge_databases(
        $table_restore_target_drift_base,
        $table_restore_target_drift_source,
        $table_restore_target_drift_target,
        $table_restore_target_drift_metadata,
        'feature-schema-table-restore-target-drift',
        'main'
    );
    assert_same($table_restore_target_drift_result['status'], 'completed_with_conflicts', 'reviewed table restore target-drift fixture starts reviewable');
    $table_restore_target_drift_run_id = (int)$table_restore_target_drift_result['run_id'];
    $table_restore_target_drift_conflict_id = (int)scalar($table_restore_target_drift_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_restore_target_drift' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    assert_true($table_restore_target_drift_conflict_id > 0, 'reviewed table restore target-drift fixture records a table conflict');
    cow_merge_review_record(
        $table_restore_target_drift_metadata,
        'conflict',
        $table_restore_target_drift_conflict_id,
        'reviewed',
        'Review dropped table restore before target recreates the table.',
        'cow-test'
    );

    $target_db = open_db($table_restore_target_drift_target);
    $target_db->exec('CREATE TABLE plugin_restore_target_drift (id INTEGER PRIMARY KEY, label BLOB)');
    $target_db->close();

    $table_restore_target_drift_revalidated = cow_merge_revalidate_reviewed_conflicts($table_restore_target_drift_metadata, $table_restore_target_drift_run_id, 'cow-revalidate');
    assert_same($table_restore_target_drift_revalidated['checked'], 1, 'schema table restore target drift revalidation checks the reviewed conflict');
    assert_same($table_restore_target_drift_revalidated['reviewed'], 1, 'schema table restore target drift revalidation sees the reviewed conflict');
    assert_same($table_restore_target_drift_revalidated['stale'], 1, 'schema table restore target drift is treated as stale');
    assert_same($table_restore_target_drift_revalidated['carried'], 1, 'schema table restore target drift returns the conflict to needs-action');
    assert_true(
        str_contains((string)scalar($table_restore_target_drift_metadata, "SELECT stale_reason FROM merge_revalidations WHERE conflict_id = $table_restore_target_drift_conflict_id ORDER BY id DESC LIMIT 1"), 'target changed'),
        'schema table restore target drift explains that target schema changed after review'
    );
    $table_restore_target_drift_payload = cow_merge_decode_payload_json(
        (string)scalar($table_restore_target_drift_metadata, "SELECT target_payload FROM merge_revalidations WHERE conflict_id = $table_restore_target_drift_conflict_id ORDER BY id DESC LIMIT 1"),
        'schema table restore target drift payload'
    );
    assert_true(
        str_contains((string)$table_restore_target_drift_payload, 'label BLOB'),
        'schema table restore target drift records the current target table SQL'
    );

    $table_rebuild_revalidate_base = $tmp . '/table-rebuild-revalidate-base.sqlite';
    $table_rebuild_revalidate_source = $tmp . '/table-rebuild-revalidate-source.sqlite';
    $table_rebuild_revalidate_target = $tmp . '/table-rebuild-revalidate-target.sqlite';
    $table_rebuild_revalidate_metadata = $tmp . '/.forkpress/cow/merge/schema-table-rebuild-revalidate-metadata.sqlite';

    $db = open_db($table_rebuild_revalidate_base);
    $db->exec('CREATE TABLE plugin_rebuild_revalidate (id INTEGER PRIMARY KEY, value TEXT)');
    $db->exec("INSERT INTO plugin_rebuild_revalidate (id, value) VALUES (1, 'Alpha')");
    $db->close();
    copy($table_rebuild_revalidate_base, $table_rebuild_revalidate_source);
    copy($table_rebuild_revalidate_base, $table_rebuild_revalidate_target);

    $source_db = open_db($table_rebuild_revalidate_source);
    $source_db->exec('CREATE TABLE plugin_rebuild_revalidate_new (id INTEGER PRIMARY KEY, value INTEGER)');
    $source_db->exec('INSERT INTO plugin_rebuild_revalidate_new (id, value) SELECT id, value FROM plugin_rebuild_revalidate');
    $source_db->exec('DROP TABLE plugin_rebuild_revalidate');
    $source_db->exec('ALTER TABLE plugin_rebuild_revalidate_new RENAME TO plugin_rebuild_revalidate');
    $source_db->close();

    $target_db = open_db($table_rebuild_revalidate_target);
    $target_db->exec('CREATE TABLE plugin_rebuild_revalidate_new (id INTEGER PRIMARY KEY, value REAL)');
    $target_db->exec('INSERT INTO plugin_rebuild_revalidate_new (id, value) SELECT id, value FROM plugin_rebuild_revalidate');
    $target_db->exec('DROP TABLE plugin_rebuild_revalidate');
    $target_db->exec('ALTER TABLE plugin_rebuild_revalidate_new RENAME TO plugin_rebuild_revalidate');
    $target_db->close();

    $table_rebuild_revalidate_result = cow_merge_databases(
        $table_rebuild_revalidate_base,
        $table_rebuild_revalidate_source,
        $table_rebuild_revalidate_target,
        $table_rebuild_revalidate_metadata,
        'feature-schema-table-rebuild-revalidate',
        'main'
    );
    assert_same($table_rebuild_revalidate_result['status'], 'completed_with_conflicts', 'reviewed table rebuild fixture starts reviewable');
    $table_rebuild_revalidate_run_id = (int)$table_rebuild_revalidate_result['run_id'];
    $table_rebuild_revalidate_conflict_id = (int)scalar($table_rebuild_revalidate_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_rebuild_revalidate' AND conflict_type = 'schema-conflict' ORDER BY id DESC LIMIT 1");
    assert_true($table_rebuild_revalidate_conflict_id > 0, 'reviewed table rebuild fixture records a table conflict');
    cow_merge_review_record(
        $table_rebuild_revalidate_metadata,
        'conflict',
        $table_rebuild_revalidate_conflict_id,
        'reviewed',
        'Review table rebuild after source schema is stable.',
        'cow-test'
    );

    $source_db = open_db($table_rebuild_revalidate_source);
    $source_db->exec('CREATE TABLE plugin_rebuild_revalidate_new (id INTEGER PRIMARY KEY, value NUMERIC)');
    $source_db->exec('INSERT INTO plugin_rebuild_revalidate_new (id, value) SELECT id, value FROM plugin_rebuild_revalidate');
    $source_db->exec('DROP TABLE plugin_rebuild_revalidate');
    $source_db->exec('ALTER TABLE plugin_rebuild_revalidate_new RENAME TO plugin_rebuild_revalidate');
    $source_db->close();

    $table_rebuild_revalidated = cow_merge_revalidate_reviewed_conflicts($table_rebuild_revalidate_metadata, $table_rebuild_revalidate_run_id, 'cow-revalidate');
    assert_same($table_rebuild_revalidated['checked'], 1, 'schema table rebuild revalidation checks the reviewed conflict');
    assert_same($table_rebuild_revalidated['reviewed'], 1, 'schema table rebuild revalidation sees the reviewed conflict');
    assert_same($table_rebuild_revalidated['stale'], 1, 'schema table rebuild revalidation detects changed source table SQL');
    assert_same($table_rebuild_revalidated['carried'], 1, 'schema table rebuild revalidation carries changed source evidence to needs-action');
    assert_same(
        scalar($table_rebuild_revalidate_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $table_rebuild_revalidate_conflict_id ORDER BY id DESC LIMIT 1"),
        'unclassified',
        'schema table rebuild source drift remains unclassified until a schema planner proves compatibility'
    );
    assert_true(
        str_contains((string)scalar($table_rebuild_revalidate_metadata, "SELECT stale_reason FROM merge_revalidations WHERE conflict_id = $table_rebuild_revalidate_conflict_id ORDER BY id DESC LIMIT 1"), 'source changed'),
        'schema table rebuild source drift explains that source schema changed after review'
    );
    $table_rebuild_revalidate_source_sql = cow_merge_decode_payload_json(
        (string)scalar($table_rebuild_revalidate_metadata, "SELECT source_payload FROM merge_revalidations WHERE conflict_id = $table_rebuild_revalidate_conflict_id ORDER BY id DESC LIMIT 1"),
        'schema table rebuild revalidation source'
    );
    assert_true(
        str_contains((string)$table_rebuild_revalidate_source_sql, 'value NUMERIC'),
        'schema table rebuild revalidation records the updated source table SQL'
    );
    $table_rebuild_revalidate_audit = cow_merge_audit_report($table_rebuild_revalidate_metadata, $table_rebuild_revalidate_run_id, 10, [
        'records' => 'conflicts',
        'review_status' => 'needs-action',
    ]);
    $table_rebuild_revalidate_conflicts = array_values(array_filter($table_rebuild_revalidate_audit['conflicts'], fn($conflict) => (int)($conflict['id'] ?? 0) === $table_rebuild_revalidate_conflict_id));
    assert_same(count($table_rebuild_revalidate_conflicts), 1, 'schema table rebuild source drift returns the reviewed conflict to the needs-action audit queue');
    assert_same($table_rebuild_revalidate_conflicts[0]['revalidation_class'] ?? null, 'unclassified', 'schema table rebuild audit exposes conservative unclassified revalidation');

    $table_rebuild_target_drift_base = $tmp . '/table-rebuild-target-drift-base.sqlite';
    $table_rebuild_target_drift_source = $tmp . '/table-rebuild-target-drift-source.sqlite';
    $table_rebuild_target_drift_target = $tmp . '/table-rebuild-target-drift-target.sqlite';
    $table_rebuild_target_drift_metadata = $tmp . '/.forkpress/cow/merge/schema-table-rebuild-target-drift-metadata.sqlite';

    $db = open_db($table_rebuild_target_drift_base);
    $db->exec('CREATE TABLE plugin_rebuild_target_drift (id INTEGER PRIMARY KEY, value TEXT)');
    $db->exec("INSERT INTO plugin_rebuild_target_drift (id, value) VALUES (1, 'Alpha')");
    $db->close();
    copy($table_rebuild_target_drift_base, $table_rebuild_target_drift_source);
    copy($table_rebuild_target_drift_base, $table_rebuild_target_drift_target);

    $source_db = open_db($table_rebuild_target_drift_source);
    $source_db->exec('CREATE TABLE plugin_rebuild_target_drift_new (id INTEGER PRIMARY KEY, value INTEGER)');
    $source_db->exec('INSERT INTO plugin_rebuild_target_drift_new (id, value) SELECT id, value FROM plugin_rebuild_target_drift');
    $source_db->exec('DROP TABLE plugin_rebuild_target_drift');
    $source_db->exec('ALTER TABLE plugin_rebuild_target_drift_new RENAME TO plugin_rebuild_target_drift');
    $source_db->close();

    $target_db = open_db($table_rebuild_target_drift_target);
    $target_db->exec('CREATE TABLE plugin_rebuild_target_drift_new (id INTEGER PRIMARY KEY, value REAL)');
    $target_db->exec('INSERT INTO plugin_rebuild_target_drift_new (id, value) SELECT id, value FROM plugin_rebuild_target_drift');
    $target_db->exec('DROP TABLE plugin_rebuild_target_drift');
    $target_db->exec('ALTER TABLE plugin_rebuild_target_drift_new RENAME TO plugin_rebuild_target_drift');
    $target_db->close();

    $table_rebuild_target_drift_result = cow_merge_databases(
        $table_rebuild_target_drift_base,
        $table_rebuild_target_drift_source,
        $table_rebuild_target_drift_target,
        $table_rebuild_target_drift_metadata,
        'feature-schema-table-rebuild-target-drift',
        'main'
    );
    assert_same($table_rebuild_target_drift_result['status'], 'completed_with_conflicts', 'reviewed table rebuild target-drift fixture starts reviewable');
    $table_rebuild_target_drift_run_id = (int)$table_rebuild_target_drift_result['run_id'];
    $table_rebuild_target_drift_conflict_id = (int)scalar($table_rebuild_target_drift_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_rebuild_target_drift' AND conflict_type = 'schema-conflict' ORDER BY id DESC LIMIT 1");
    assert_true($table_rebuild_target_drift_conflict_id > 0, 'reviewed table rebuild target-drift fixture records a table conflict');
    cow_merge_review_record(
        $table_rebuild_target_drift_metadata,
        'conflict',
        $table_rebuild_target_drift_conflict_id,
        'reviewed',
        'Review table rebuild before target schema changes again.',
        'cow-test'
    );

    $target_db = open_db($table_rebuild_target_drift_target);
    $target_db->exec('CREATE TABLE plugin_rebuild_target_drift_new (id INTEGER PRIMARY KEY, value BLOB)');
    $target_db->exec('INSERT INTO plugin_rebuild_target_drift_new (id, value) SELECT id, value FROM plugin_rebuild_target_drift');
    $target_db->exec('DROP TABLE plugin_rebuild_target_drift');
    $target_db->exec('ALTER TABLE plugin_rebuild_target_drift_new RENAME TO plugin_rebuild_target_drift');
    $target_db->close();

    $table_rebuild_target_drift_revalidated = cow_merge_revalidate_reviewed_conflicts($table_rebuild_target_drift_metadata, $table_rebuild_target_drift_run_id, 'cow-revalidate');
    assert_same($table_rebuild_target_drift_revalidated['checked'], 1, 'schema table rebuild target drift revalidation checks the reviewed conflict');
    assert_same($table_rebuild_target_drift_revalidated['reviewed'], 1, 'schema table rebuild target drift revalidation sees the reviewed conflict');
    assert_same($table_rebuild_target_drift_revalidated['stale'], 1, 'schema table rebuild target drift is treated as stale');
    assert_same($table_rebuild_target_drift_revalidated['carried'], 1, 'schema table rebuild target drift returns the conflict to needs-action');
    assert_true(
        str_contains((string)scalar($table_rebuild_target_drift_metadata, "SELECT stale_reason FROM merge_revalidations WHERE conflict_id = $table_rebuild_target_drift_conflict_id ORDER BY id DESC LIMIT 1"), 'target changed'),
        'schema table rebuild target drift explains that target schema changed after review'
    );
    $table_rebuild_target_drift_payload = cow_merge_decode_payload_json(
        (string)scalar($table_rebuild_target_drift_metadata, "SELECT target_payload FROM merge_revalidations WHERE conflict_id = $table_rebuild_target_drift_conflict_id ORDER BY id DESC LIMIT 1"),
        'schema table rebuild target drift payload'
    );
    assert_true(
        str_contains((string)$table_rebuild_target_drift_payload, 'value BLOB'),
        'schema table rebuild target drift records the current target table SQL'
    );

    $index_validate_base = $tmp . '/index-validate-base.sqlite';
    $index_validate_source = $tmp . '/index-validate-source.sqlite';
    $index_validate_target = $tmp . '/index-validate-target.sqlite';
    $index_validate_metadata = $tmp . '/.forkpress/cow/merge/schema-index-validate-metadata.sqlite';

    $db = open_db($index_validate_base);
    $db->exec('CREATE TABLE plugin_index_validate (id INTEGER PRIMARY KEY, label TEXT)');
    $db->exec("INSERT INTO plugin_index_validate (id, label) VALUES (1, 'Alpha')");
    $db->close();
    copy($index_validate_base, $index_validate_source);
    copy($index_validate_base, $index_validate_target);

    $db = open_db($index_validate_source);
    $db->exec('CREATE UNIQUE INDEX plugin_index_validate_lower_idx ON plugin_index_validate(lower(label))');
    $db->close();

    $db = open_db($index_validate_target);
    $db->exec("INSERT INTO plugin_index_validate (id, label) VALUES (2, 'alpha')");
    $db->close();

    $index_validate_result = cow_merge_databases(
        $index_validate_base,
        $index_validate_source,
        $index_validate_target,
        $index_validate_metadata,
        'feature-schema-index-validate',
        'main'
    );
    assert_same($index_validate_result['status'], 'completed_with_conflicts', 'source-added expression unique index blocked by target rows is audited');
    assert_same(
        (int)scalar($index_validate_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_index_validate_lower_idx'"),
        0,
        'rejected source-added index is not installed on target'
    );
    assert_same(
        (int)scalar($index_validate_target, "SELECT COUNT(*) FROM plugin_index_validate WHERE id = 2 AND label = 'alpha'"),
        1,
        'target row that blocks source-added index is preserved by default'
    );
    $index_validate_conflict_id = (int)scalar($index_validate_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_index_validate' AND column_name = 'plugin_index_validate_lower_idx' AND conflict_type = 'schema-source-added-index' ORDER BY id DESC LIMIT 1");
    assert_true($index_validate_conflict_id > 0, 'blocked source-added expression index records a schema conflict');
    assert_throws(
        fn() => cow_merge_resolve_conflict($index_validate_metadata, $index_validate_conflict_id, 'source', false, 'Preview blocked source index.', 'cow-test'),
        'UNIQUE constraint failed',
        'dry-run source index resolution validates target rows before reporting success'
    );
    assert_same(
        (int)scalar($index_validate_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $index_validate_conflict_id"),
        0,
        'failed source index dry-run does not record resolution metadata'
    );

    $db = open_db($index_validate_target);
    $db->exec('DELETE FROM plugin_index_validate WHERE id = 2');
    $db->close();
    $index_validate_resolution = cow_merge_resolve_conflict(
        $index_validate_metadata,
        $index_validate_conflict_id,
        'source',
        true,
        'Apply source index after row review.',
        'cow-test'
    );
    assert_same($index_validate_resolution['status'], 'applied', 'source index resolution applies after validation passes');
    assert_same(
        (int)scalar($index_validate_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_index_validate_lower_idx'"),
        1,
        'source index resolution installs the audited expression index'
    );
    assert_same(
        (int)scalar($index_validate_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $index_validate_conflict_id AND choice = 'source' AND applied = 1"),
        1,
        'successful source index resolution is auditable'
    );

    $index_revalidate_base = $tmp . '/index-revalidate-base.sqlite';
    $index_revalidate_source = $tmp . '/index-revalidate-source.sqlite';
    $index_revalidate_target = $tmp . '/index-revalidate-target.sqlite';
    $index_revalidate_metadata = $tmp . '/.forkpress/cow/merge/schema-index-revalidate-metadata.sqlite';

    $db = open_db($index_revalidate_base);
    $db->exec('CREATE TABLE plugin_index_revalidate (id INTEGER PRIMARY KEY, label TEXT)');
    $db->exec("INSERT INTO plugin_index_revalidate (id, label) VALUES (1, 'Alpha')");
    $db->close();
    copy($index_revalidate_base, $index_revalidate_source);
    copy($index_revalidate_base, $index_revalidate_target);

    $db = open_db($index_revalidate_source);
    $db->exec('CREATE UNIQUE INDEX plugin_index_revalidate_label_idx ON plugin_index_revalidate(lower(label))');
    $db->close();

    $db = open_db($index_revalidate_target);
    $db->exec("INSERT INTO plugin_index_revalidate (id, label) VALUES (2, 'alpha')");
    $db->close();

    $index_revalidate_result = cow_merge_databases(
        $index_revalidate_base,
        $index_revalidate_source,
        $index_revalidate_target,
        $index_revalidate_metadata,
        'feature-schema-index-revalidate',
        'main'
    );
    assert_same($index_revalidate_result['status'], 'completed_with_conflicts', 'reviewed source-added schema index fixture starts reviewable');
    $index_revalidate_run_id = (int)$index_revalidate_result['run_id'];
    $index_revalidate_conflict_id = (int)scalar($index_revalidate_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_index_revalidate' AND column_name = 'plugin_index_revalidate_label_idx' AND conflict_type = 'schema-source-added-index' ORDER BY id DESC LIMIT 1");
    assert_true($index_revalidate_conflict_id > 0, 'reviewed source-added schema index fixture records an index conflict');
    cow_merge_review_record(
        $index_revalidate_metadata,
        'conflict',
        $index_revalidate_conflict_id,
        'reviewed',
        'Review source-added expression index after target rows are cleaned up.',
        'cow-test'
    );

    $db = open_db($index_revalidate_source);
    $db->exec('DROP INDEX plugin_index_revalidate_label_idx');
    $db->exec('CREATE UNIQUE INDEX plugin_index_revalidate_label_idx ON plugin_index_revalidate(upper(label))');
    $db->close();

    $index_revalidated = cow_merge_revalidate_reviewed_conflicts($index_revalidate_metadata, $index_revalidate_run_id, 'cow-revalidate');
    assert_same($index_revalidated['checked'], 1, 'schema index revalidation checks the reviewed index conflict');
    assert_same($index_revalidated['reviewed'], 1, 'schema index revalidation sees the reviewed index conflict');
    assert_same($index_revalidated['stale'], 1, 'schema index revalidation detects changed source index SQL');
    assert_same($index_revalidated['carried'], 1, 'schema index revalidation carries changed source index evidence to needs-action');
    assert_same(
        scalar($index_revalidate_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $index_revalidate_conflict_id ORDER BY id DESC LIMIT 1"),
        'unclassified',
        'schema index source drift remains unclassified until a schema planner proves compatibility'
    );
    assert_true(
        str_contains((string)scalar($index_revalidate_metadata, "SELECT stale_reason FROM merge_revalidations WHERE conflict_id = $index_revalidate_conflict_id ORDER BY id DESC LIMIT 1"), 'source changed'),
        'schema index source drift explains that source schema changed after review'
    );
    $index_revalidate_source_payload = cow_merge_decode_payload_json(
        (string)scalar($index_revalidate_metadata, "SELECT source_payload FROM merge_revalidations WHERE conflict_id = $index_revalidate_conflict_id ORDER BY id DESC LIMIT 1"),
        'schema index revalidation source'
    );
    assert_true(
        str_contains((string)($index_revalidate_source_payload['sql'] ?? ''), 'upper(label)'),
        'schema index revalidation records the updated source index SQL'
    );
    $index_revalidate_audit = cow_merge_audit_report($index_revalidate_metadata, $index_revalidate_run_id, 10, [
        'records' => 'conflicts',
        'review_status' => 'needs-action',
    ]);
    $index_revalidate_conflicts = array_values(array_filter($index_revalidate_audit['conflicts'], fn($conflict) => (int)($conflict['id'] ?? 0) === $index_revalidate_conflict_id));
    assert_same(count($index_revalidate_conflicts), 1, 'schema index source drift returns the reviewed conflict to the needs-action audit queue');
    assert_same($index_revalidate_conflicts[0]['revalidation_class'] ?? null, 'unclassified', 'schema index audit exposes the conservative unclassified revalidation');
} finally {
    remove_tree($tmp);
}

if ($fail) {
    echo "FAILURES: $fail\n";
    exit(1);
}
echo "COW schema review focused tests passed ($pass assertions).\n";

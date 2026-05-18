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

function run_merge_cli(array $args): array {
    $script = dirname(__DIR__, 2) . '/scripts/cow/merge.php';
    $command = array_map('escapeshellarg', array_merge([PHP_BINARY, $script], $args));
    exec(implode(' ', $command) . ' 2>&1', $output, $status);
    return [
        'status' => $status,
        'output' => implode("\n", $output) . ($output === [] ? '' : "\n"),
    ];
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
    $schema_contract_audit = cow_merge_audit_report($metadata, $schema_review_run_id, 10, ['records' => 'conflicts']);
    $schema_contract_rows = [];
    foreach ($schema_contract_audit['conflicts'] as $row) {
        $schema_contract_rows[(int)$row['id']] = $row;
    }
    assert_same(
        $schema_contract_rows[$view_conflict_id]['resolution_choices'],
        ['target'],
        'cyclic source-added view audit does not advertise blocked source resolution'
    );
    assert_same(
        $schema_contract_rows[$view_conflict_id]['blocked_resolution_choices']['source'] ?? null,
        (string)$view_payload['error'],
        'cyclic source-added view audit explains why source resolution is blocked'
    );
    assert_same(
        $schema_contract_rows[$trigger_conflict_id]['resolution_choices'],
        ['target'],
        'cyclic source-added trigger audit does not advertise blocked source resolution'
    );
    assert_same(
        $schema_contract_rows[$trigger_conflict_id]['blocked_resolution_choices']['source'] ?? null,
        (string)$trigger_payload['error'],
        'cyclic source-added trigger audit explains why source resolution is blocked'
    );
    ob_start();
    cow_merge_print_audit_text($schema_contract_audit);
    $schema_contract_text = (string)ob_get_clean();
    assert_true(
        str_contains($schema_contract_text, 'blocked-choice=source reason=source view plugin_cycle_self_view has unsupported cyclic source view dependencies'),
        'text audit prints blocked source choice for cyclic views'
    );
    assert_true(
        str_contains($schema_contract_text, 'blocked-choice=source reason=source trigger plugin_trigger_cycle_self_insert has unsupported cyclic trigger dependencies'),
        'text audit prints blocked source choice for cyclic triggers'
    );

    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $view_conflict_id, 'source', true, 'Try cyclic source-added view.', 'cow-test'),
        "resolution choice source is blocked for conflict #$view_conflict_id: source view plugin_cycle_self_view has unsupported cyclic source view dependencies",
        'cyclic source-added view resolution is blocked by the conflict contract'
    );
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $trigger_conflict_id, 'source', true, 'Try cyclic source-added trigger.', 'cow-test'),
        "resolution choice source is blocked for conflict #$trigger_conflict_id: source trigger plugin_trigger_cycle_self_insert has unsupported cyclic trigger dependencies",
        'cyclic source-added trigger resolution is blocked by the conflict contract'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id IN ($view_conflict_id, $trigger_conflict_id)"),
        0,
        'failed cyclic schema resolution attempts do not record resolutions'
    );

    $drop_table_base = $tmp . '/drop-table-contract-base.sqlite';
    $drop_table_source = $tmp . '/drop-table-contract-source.sqlite';
    $drop_table_target = $tmp . '/drop-table-contract-target.sqlite';
    $db = open_db($drop_table_base);
    $db->exec('CREATE TABLE plugin_contract_drop_table (item_id TEXT PRIMARY KEY, label TEXT)');
    $db->exec('CREATE VIEW plugin_contract_drop_table_live AS SELECT item_id, label FROM plugin_contract_drop_table');
    $db->close();
    copy($drop_table_base, $drop_table_source);
    copy($drop_table_base, $drop_table_target);
    $db = open_db($drop_table_source);
    $db->exec('DROP VIEW plugin_contract_drop_table_live');
    $db->exec('DROP TABLE plugin_contract_drop_table');
    $db->close();
    $db = open_db($drop_table_target);
    $db->exec('DROP VIEW plugin_contract_drop_table_live');
    $db->exec('CREATE VIEW plugin_contract_drop_table_live AS SELECT item_id, label, label AS target_label FROM plugin_contract_drop_table');
    $db->close();
    $drop_table_result = cow_merge_databases($drop_table_base, $drop_table_source, $drop_table_target, $metadata, 'feature-schema-drop-table-contract', 'main');
    $drop_table_run_id = (int)$drop_table_result['run_id'];
    assert_same($drop_table_result['status'], 'completed_with_conflicts', 'source table drop with a dependent target view stays reviewable');
    $drop_table_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_contract_drop_table' AND conflict_type = 'schema-source-dropped-table' ORDER BY id DESC LIMIT 1");
    $drop_table_view_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_contract_drop_table_live' AND conflict_type = 'schema-source-dropped-view' ORDER BY id DESC LIMIT 1");
    $drop_table_audit = cow_merge_audit_report($metadata, $drop_table_run_id, 10, ['records' => 'conflicts']);
    $drop_table_rows = [];
    foreach ($drop_table_audit['conflicts'] as $row) {
        $drop_table_rows[(int)$row['id']] = $row;
    }
    assert_same(
        $drop_table_rows[$drop_table_conflict_id]['resolution_choices'],
        ['target'],
        'source table drop audit does not advertise source while a target view depends on it'
    );
    assert_true(
        str_contains((string)($drop_table_rows[$drop_table_conflict_id]['blocked_resolution_choices']['source'] ?? ''), 'dependent target views'),
        'source table drop audit explains dependent target view blocker'
    );
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $drop_table_conflict_id, 'source', true, 'Try source table drop before dependent view.', 'cow-test'),
        'resolution choice source is blocked',
        'source table drop resolution is blocked by the conflict contract before dependency mutation'
    );
    cow_merge_resolve_conflict($metadata, $drop_table_view_conflict_id, 'source', true, 'Apply source view drop first.', 'cow-test');
    $drop_table_unblocked_audit = cow_merge_audit_report($metadata, $drop_table_run_id, 10, ['records' => 'conflicts']);
    $drop_table_unblocked_rows = [];
    foreach ($drop_table_unblocked_audit['conflicts'] as $row) {
        $drop_table_unblocked_rows[(int)$row['id']] = $row;
    }
    assert_same(
        $drop_table_unblocked_rows[$drop_table_conflict_id]['resolution_choices'],
        ['source', 'target'],
        'source table drop audit advertises source after dependent target view is resolved'
    );
    $drop_table_resolution = cow_merge_resolve_conflict($metadata, $drop_table_conflict_id, 'source', true, 'Apply source table drop after dependent view.', 'cow-test');
    assert_same($drop_table_resolution['status'], 'applied', 'source table drop applies after dependent target view is resolved');

    $drop_table_when_base = $tmp . '/drop-table-trigger-when-base.sqlite';
    $drop_table_when_source = $tmp . '/drop-table-trigger-when-source.sqlite';
    $drop_table_when_target = $tmp . '/drop-table-trigger-when-target.sqlite';
    $db = open_db($drop_table_when_base);
    $db->exec('CREATE TABLE plugin_contract_drop_when_anchor (item_id TEXT PRIMARY KEY, enabled INTEGER NOT NULL)');
    $db->exec('CREATE TABLE plugin_contract_drop_when_observer (item_id TEXT PRIMARY KEY)');
    $db->exec('CREATE TABLE plugin_contract_drop_when_audit (item_id TEXT)');
    $db->exec(<<<'SQL'
CREATE TRIGGER plugin_contract_drop_when_observer_insert
AFTER INSERT ON plugin_contract_drop_when_observer
WHEN EXISTS (SELECT 1 FROM plugin_contract_drop_when_anchor WHERE enabled = 1)
BEGIN
    INSERT INTO plugin_contract_drop_when_audit (item_id) VALUES (NEW.item_id);
END
SQL);
    $db->close();
    copy($drop_table_when_base, $drop_table_when_source);
    copy($drop_table_when_base, $drop_table_when_target);
    $db = open_db($drop_table_when_source);
    $db->exec('DROP TRIGGER plugin_contract_drop_when_observer_insert');
    $db->exec('DROP TABLE plugin_contract_drop_when_anchor');
    $db->close();
    $drop_table_when_result = cow_merge_databases($drop_table_when_base, $drop_table_when_source, $drop_table_when_target, $metadata, 'feature-schema-drop-table-trigger-when-contract', 'main');
    $drop_table_when_run_id = (int)$drop_table_when_result['run_id'];
    assert_same($drop_table_when_result['status'], 'completed_with_conflicts', 'source table drop with dependent target trigger WHEN clause stays reviewable');
    $drop_table_when_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_contract_drop_when_anchor' AND conflict_type = 'schema-source-dropped-table' ORDER BY id DESC LIMIT 1");
    $drop_table_when_trigger_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_contract_drop_when_observer_insert' AND conflict_type = 'schema-source-dropped-trigger' ORDER BY id DESC LIMIT 1");
    $drop_table_when_audit = cow_merge_audit_report($metadata, $drop_table_when_run_id, 10, ['records' => 'conflicts']);
    $drop_table_when_rows = [];
    foreach ($drop_table_when_audit['conflicts'] as $row) {
        $drop_table_when_rows[(int)$row['id']] = $row;
    }
    assert_same(
        $drop_table_when_rows[$drop_table_when_conflict_id]['resolution_choices'],
        ['target'],
        'source table drop audit does not advertise source while a target trigger WHEN clause depends on it'
    );
    assert_true(
        str_contains((string)($drop_table_when_rows[$drop_table_when_conflict_id]['blocked_resolution_choices']['source'] ?? ''), 'dependent target trigger programs'),
        'source table drop audit explains dependent target trigger WHEN blocker'
    );
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $drop_table_when_conflict_id, 'source', false, 'Preview table drop before trigger WHEN dependency.', 'cow-test'),
        'dependent target trigger programs',
        'source table drop preview refuses to leave target trigger WHEN clause invalid'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $drop_table_when_conflict_id"),
        0,
        'failed trigger-WHEN table drop preview records no resolution metadata'
    );
    assert_same((int)scalar($drop_table_when_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_contract_drop_when_anchor'"), 1, 'blocked trigger-WHEN table drop preserves target table');
    assert_same((int)scalar($drop_table_when_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_contract_drop_when_observer_insert'"), 1, 'blocked trigger-WHEN table drop preserves target trigger');
    cow_merge_resolve_conflict($metadata, $drop_table_when_trigger_conflict_id, 'source', true, 'Apply source trigger WHEN dependency drop.', 'cow-test');
    $drop_table_when_resolution = cow_merge_resolve_conflict($metadata, $drop_table_when_conflict_id, 'source', true, 'Apply table drop after trigger WHEN dependency.', 'cow-test');
    assert_same($drop_table_when_resolution['status'], 'applied', 'source table drop applies after dependent trigger WHEN clause is resolved');
    assert_same(
        (int)scalar($drop_table_when_target, "SELECT COUNT(*) FROM sqlite_master WHERE name IN ('plugin_contract_drop_when_anchor', 'plugin_contract_drop_when_observer_insert')"),
        0,
        'source table drop removes table after dependent trigger WHEN clause is resolved'
    );

    $drop_view_base = $tmp . '/drop-view-contract-base.sqlite';
    $drop_view_source = $tmp . '/drop-view-contract-source.sqlite';
    $drop_view_target = $tmp . '/drop-view-contract-target.sqlite';
    $db = open_db($drop_view_base);
    $db->exec('CREATE TABLE plugin_contract_drop_view_items (item_id TEXT PRIMARY KEY, label TEXT)');
    $db->exec('CREATE VIEW plugin_contract_drop_view_live AS SELECT item_id, label FROM plugin_contract_drop_view_items');
    $db->exec('CREATE TABLE plugin_contract_drop_view_observer (item_id TEXT PRIMARY KEY)');
    $db->exec('CREATE TABLE plugin_contract_drop_view_audit (item_id TEXT, label TEXT)');
    $db->exec(<<<'SQL'
CREATE TRIGGER plugin_contract_drop_view_observer_insert
AFTER INSERT ON plugin_contract_drop_view_observer
BEGIN
    INSERT INTO plugin_contract_drop_view_audit (item_id, label)
    SELECT item_id, label FROM plugin_contract_drop_view_live WHERE item_id = NEW.item_id;
END
SQL);
    $db->close();
    copy($drop_view_base, $drop_view_source);
    copy($drop_view_base, $drop_view_target);
    $db = open_db($drop_view_source);
    $db->exec('DROP TRIGGER plugin_contract_drop_view_observer_insert');
    $db->exec('DROP VIEW plugin_contract_drop_view_live');
    $db->close();
    $db = open_db($drop_view_target);
    $db->exec('DROP TRIGGER plugin_contract_drop_view_observer_insert');
    $db->exec(<<<'SQL'
CREATE TRIGGER plugin_contract_drop_view_observer_insert
AFTER INSERT ON plugin_contract_drop_view_observer
BEGIN
    INSERT INTO plugin_contract_drop_view_audit (item_id, label)
    SELECT item_id, label || ' target' FROM plugin_contract_drop_view_live WHERE item_id = NEW.item_id;
END
SQL);
    $db->close();
    $drop_view_result = cow_merge_databases($drop_view_base, $drop_view_source, $drop_view_target, $metadata, 'feature-schema-drop-view-contract', 'main');
    $drop_view_run_id = (int)$drop_view_result['run_id'];
    assert_same($drop_view_result['status'], 'completed_with_conflicts', 'source view drop with a dependent target trigger stays reviewable');
    $drop_view_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_contract_drop_view_live' AND conflict_type = 'schema-source-dropped-view' ORDER BY id DESC LIMIT 1");
    $drop_view_trigger_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_contract_drop_view_observer_insert' AND conflict_type = 'schema-source-dropped-trigger' ORDER BY id DESC LIMIT 1");
    $drop_view_audit = cow_merge_audit_report($metadata, $drop_view_run_id, 10, ['records' => 'conflicts']);
    $drop_view_rows = [];
    foreach ($drop_view_audit['conflicts'] as $row) {
        $drop_view_rows[(int)$row['id']] = $row;
    }
    assert_same(
        $drop_view_rows[$drop_view_conflict_id]['resolution_choices'],
        ['target'],
        'source view drop audit does not advertise source while a target trigger depends on it'
    );
    assert_true(
        str_contains((string)($drop_view_rows[$drop_view_conflict_id]['blocked_resolution_choices']['source'] ?? ''), 'dependent target trigger programs'),
        'source view drop audit explains dependent target trigger blocker'
    );
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $drop_view_conflict_id, 'source', false, 'Preview source view drop before dependent trigger.', 'cow-test'),
        'resolution choice source is blocked',
        'source view drop preview is blocked by the conflict contract before dependency mutation'
    );
    cow_merge_resolve_conflict($metadata, $drop_view_trigger_conflict_id, 'source', true, 'Apply source trigger drop first.', 'cow-test');
    $drop_view_unblocked_audit = cow_merge_audit_report($metadata, $drop_view_run_id, 10, ['records' => 'conflicts']);
    $drop_view_unblocked_rows = [];
    foreach ($drop_view_unblocked_audit['conflicts'] as $row) {
        $drop_view_unblocked_rows[(int)$row['id']] = $row;
    }
    assert_same(
        $drop_view_unblocked_rows[$drop_view_conflict_id]['resolution_choices'],
        ['source', 'target'],
        'source view drop audit advertises source after dependent target trigger is resolved'
    );
    $drop_view_resolution = cow_merge_resolve_conflict($metadata, $drop_view_conflict_id, 'source', true, 'Apply source view drop after dependent trigger.', 'cow-test');
    assert_same($drop_view_resolution['status'], 'applied', 'source view drop applies after dependent target trigger is resolved');

    $drop_index_trigger_base = $tmp . '/drop-index-trigger-base.sqlite';
    $drop_index_trigger_source = $tmp . '/drop-index-trigger-source.sqlite';
    $drop_index_trigger_target = $tmp . '/drop-index-trigger-target.sqlite';
    $drop_index_trigger_metadata = $tmp . '/.forkpress/cow/merge/drop-index-trigger-metadata.sqlite';
    $db = open_db($drop_index_trigger_base);
    $db->exec('CREATE TABLE plugin_contract_drop_schema_items (item_id TEXT PRIMARY KEY, label TEXT)');
    $db->exec('CREATE TABLE plugin_contract_drop_schema_audit (item_id TEXT, label TEXT)');
    $db->exec('CREATE INDEX plugin_contract_drop_schema_label_idx ON plugin_contract_drop_schema_items(label)');
    $db->exec(<<<'SQL'
CREATE TRIGGER plugin_contract_drop_schema_items_insert
AFTER INSERT ON plugin_contract_drop_schema_items
BEGIN
    INSERT INTO plugin_contract_drop_schema_audit (item_id, label) VALUES (NEW.item_id, NEW.label);
END
SQL);
    $db->close();
    copy($drop_index_trigger_base, $drop_index_trigger_source);
    copy($drop_index_trigger_base, $drop_index_trigger_target);
    $db = open_db($drop_index_trigger_source);
    $db->exec('DROP TRIGGER plugin_contract_drop_schema_items_insert');
    $db->exec('DROP INDEX plugin_contract_drop_schema_label_idx');
    $db->close();

    $drop_index_trigger_result = cow_merge_databases(
        $drop_index_trigger_base,
        $drop_index_trigger_source,
        $drop_index_trigger_target,
        $drop_index_trigger_metadata,
        'feature-schema-drop-index-trigger',
        'main'
    );
    $drop_index_trigger_run_id = (int)$drop_index_trigger_result['run_id'];
    assert_same($drop_index_trigger_result['status'], 'completed', 'source-dropped index and trigger apply automatically when target kept base definitions');
    assert_same(
        (int)scalar($drop_index_trigger_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_contract_drop_schema_label_idx'"),
        0,
        'automatic schema drop removes the unchanged target index'
    );
    assert_same(
        (int)scalar($drop_index_trigger_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_contract_drop_schema_items_insert'"),
        0,
        'automatic schema drop removes the unchanged target trigger'
    );
    assert_same(
        (int)scalar($drop_index_trigger_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE run_id = $drop_index_trigger_run_id AND conflict_type IN ('schema-source-dropped-index', 'schema-source-dropped-trigger')"),
        0,
        'automatic unchanged-target index/trigger drops record no schema conflicts'
    );
    assert_same(
        (int)scalar($drop_index_trigger_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE run_id = $drop_index_trigger_run_id AND decision = 'source-applied' AND column_name IN ('plugin_contract_drop_schema_label_idx', 'plugin_contract_drop_schema_items_insert')"),
        2,
        'automatic unchanged-target index/trigger drops are audited as source-applied decisions'
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
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_revalidations WHERE conflict_id IN ($view_conflict_id, $trigger_conflict_id) AND revalidation_class = 'compatible-source-drift'"),
        2,
        'schema object source drift is classified compatible when revalidation proves formerly blocked source SQL is now valid'
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
        assert_same($conflict['revalidation_class'] ?? null, 'compatible-source-drift', 'schema object audit exposes compatible source-drift revalidation');
    }
    $schema_object_after_revalidate_audit = cow_merge_audit_report($metadata, $schema_review_run_id, 10, [
        'records' => 'conflicts',
        'after_revalidate' => 'supported',
        'latest_revalidation_status' => 'current',
    ]);
    $schema_object_after_revalidate_ids = array_map(
        fn($conflict) => (int)($conflict['id'] ?? 0),
        $schema_object_after_revalidate_audit['conflicts']
    );
    assert_true(
        in_array($view_conflict_id, $schema_object_after_revalidate_ids, true) &&
            in_array($trigger_conflict_id, $schema_object_after_revalidate_ids, true),
        'compatible schema source drift is advertised as after-revalidate supported'
    );
    $view_after_revalidate_resolution = cow_merge_resolve_conflict(
        $metadata,
        $view_conflict_id,
        'source',
        true,
        'Apply revalidated non-cyclic source-added view.',
        'cow-test',
        true
    );
    assert_same($view_after_revalidate_resolution['status'], 'applied', 'after-revalidate source resolution applies updated source-added view evidence');
    assert_true(
        str_contains((string)scalar($target, "SELECT sql FROM sqlite_master WHERE type = 'view' AND name = 'plugin_cycle_self_view'"), 'SELECT label FROM plugin_trigger_cycle_self'),
        'after-revalidate source resolution installs the updated non-cyclic source view SQL'
    );
    $schema_object_revalidation_ids = [
        $view_conflict_id => (int)scalar($metadata, "SELECT id FROM merge_revalidations WHERE conflict_id = $view_conflict_id ORDER BY id DESC LIMIT 1"),
        $trigger_conflict_id => (int)scalar($metadata, "SELECT id FROM merge_revalidations WHERE conflict_id = $trigger_conflict_id ORDER BY id DESC LIMIT 1"),
    ];
    $schema_object_event_audit = cow_merge_audit_report($metadata, $schema_review_run_id, 6, [
        'records' => 'conflict-events',
    ]);
    $schema_object_revalidation_events = array_values(array_filter(
        $schema_object_event_audit['conflict_events'],
        fn($event) => ($event['event_type'] ?? null) === 'revalidation-required' &&
            in_array((int)($event['conflict_id'] ?? 0), [$view_conflict_id, $trigger_conflict_id], true)
    ));
    assert_same(count($schema_object_revalidation_events), 2, 'schema object revalidation is visible in the conflict event stream');
    foreach ($schema_object_revalidation_events as $event) {
        $event_conflict_id = (int)$event['conflict_id'];
        assert_same($event['related_record_type'], 'revalidation', 'schema revalidation event links to the revalidation record');
        assert_same((int)$event['related_record_id'], $schema_object_revalidation_ids[$event_conflict_id], 'schema revalidation event exposes the revalidation id');
        assert_same($event['lifecycle_state'], 'needs-action', 'schema revalidation event records the needs-action lifecycle state');
        assert_same($event['actor'], 'cow-revalidate', 'schema revalidation event preserves the revalidation actor');
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
    assert_same(
        scalar($schema_target_drift_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $schema_target_drift_conflict_id ORDER BY id DESC LIMIT 1"),
        'unclassified',
        'cyclic schema view target SQL drift remains unclassified until a planner proves compatibility'
    );
    assert_same(
        scalar($schema_target_drift_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $schema_trigger_target_drift_conflict_id ORDER BY id DESC LIMIT 1"),
        'unclassified',
        'cyclic schema trigger target SQL drift remains unclassified until a planner proves compatibility'
    );
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $schema_target_drift_metadata,
            $schema_target_drift_conflict_id,
            'source',
            true,
            'Try source view after unclassified revalidation.',
            'cow-test',
            true
        ),
        'resolution choice source is blocked',
        'unclassified schema view target drift cannot resolve after revalidation'
    );
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $schema_target_drift_metadata,
            $schema_trigger_target_drift_conflict_id,
            'source',
            true,
            'Try source trigger after unclassified revalidation.',
            'cow-test',
            true
        ),
        'resolution choice source is blocked',
        'unclassified schema trigger target drift cannot resolve after revalidation'
    );

    $schema_view_compatible_base = $tmp . '/schema-view-compatible-base.sqlite';
    $schema_view_compatible_source = $tmp . '/schema-view-compatible-source.sqlite';
    $schema_view_compatible_target = $tmp . '/schema-view-compatible-target.sqlite';
    $schema_view_compatible_metadata = $tmp . '/.forkpress/cow/merge/schema-view-compatible-metadata.sqlite';

    $db = open_db($schema_view_compatible_base);
    $db->exec('CREATE TABLE plugin_schema_view_compatible_items (label TEXT NOT NULL)');
    $db->exec("INSERT INTO plugin_schema_view_compatible_items (label) VALUES ('compatible view anchor')");
    $db->close();
    copy($schema_view_compatible_base, $schema_view_compatible_source);
    copy($schema_view_compatible_base, $schema_view_compatible_target);

    $source_db = open_db($schema_view_compatible_source);
    $source_db->exec('CREATE VIEW plugin_schema_view_compatible_view AS SELECT label FROM plugin_schema_view_compatible_items');
    $source_view_sql = (string)$source_db->querySingle("SELECT sql FROM sqlite_master WHERE type = 'view' AND name = 'plugin_schema_view_compatible_view'");
    $source_db->close();

    @mkdir(dirname($schema_view_compatible_metadata), 0777, true);
    $schema_view_compatible_meta = open_db($schema_view_compatible_metadata);
    cow_merge_ensure_metadata($schema_view_compatible_meta);
    $schema_view_compatible_run_id = cow_merge_start_run(
        $schema_view_compatible_meta,
        'feature-schema-view-compatible',
        'main',
        $schema_view_compatible_base,
        $schema_view_compatible_source,
        $schema_view_compatible_target
    );
    cow_merge_record_schema_conflict(
        $schema_view_compatible_meta,
        $schema_view_compatible_run_id,
        'plugin_schema_view_compatible_items',
        'plugin_schema_view_compatible_view',
        'schema-source-added-view',
        null,
        ['sql' => $source_view_sql],
        null,
        null,
        'manual source-added view conflict for compatible target-drift revalidation'
    );
    cow_merge_finish_run($schema_view_compatible_meta, $schema_view_compatible_run_id, 'completed_with_conflicts');
    $schema_view_compatible_meta->close();
    $schema_view_compatible_conflict_id = (int)scalar($schema_view_compatible_metadata, "SELECT id FROM merge_conflicts WHERE conflict_type = 'schema-source-added-view' ORDER BY id DESC LIMIT 1");
    assert_true($schema_view_compatible_conflict_id > 0, 'compatible schema view target-drift fixture records a source-added view conflict');
    cow_merge_review_record(
        $schema_view_compatible_metadata,
        'conflict',
        $schema_view_compatible_conflict_id,
        'reviewed',
        'Review source-added view before compatible target drift.',
        'cow-test'
    );
    $target_db = open_db($schema_view_compatible_target);
    $target_db->exec("CREATE VIEW plugin_schema_view_compatible_view AS SELECT label || ' target' AS label FROM plugin_schema_view_compatible_items");
    $target_db->close();

    $schema_view_compatible_revalidated = cow_merge_revalidate_reviewed_conflicts($schema_view_compatible_metadata, $schema_view_compatible_run_id, 'cow-revalidate');
    assert_same($schema_view_compatible_revalidated['checked'], 1, 'compatible schema view target drift revalidation checks the reviewed conflict');
    assert_same($schema_view_compatible_revalidated['stale'], 1, 'compatible schema view target drift is treated as stale');
    assert_same($schema_view_compatible_revalidated['carried'], 1, 'compatible schema view target drift returns the conflict to needs-action');
    assert_same(
        scalar($schema_view_compatible_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $schema_view_compatible_conflict_id ORDER BY id DESC LIMIT 1"),
        'compatible-schema-view-target-drift',
        'schema view target drift is classified compatible when source replacement validates'
    );
    $schema_view_compatible_resolution = cow_merge_resolve_conflict(
        $schema_view_compatible_metadata,
        $schema_view_compatible_conflict_id,
        'source',
        true,
        'Apply source view after compatible target drift revalidation.',
        'cow-test',
        true
    );
    assert_same($schema_view_compatible_resolution['status'], 'applied', 'compatible schema view target drift resolves after revalidation');
    assert_true(
        str_contains((string)scalar($schema_view_compatible_target, "SELECT sql FROM sqlite_master WHERE type = 'view' AND name = 'plugin_schema_view_compatible_view'"), 'SELECT label FROM plugin_schema_view_compatible_items'),
        'compatible schema view target drift applies the audited source view'
    );

    $schema_view_changed_compatible_base = $tmp . '/schema-view-changed-compatible-base.sqlite';
    $schema_view_changed_compatible_source = $tmp . '/schema-view-changed-compatible-source.sqlite';
    $schema_view_changed_compatible_target = $tmp . '/schema-view-changed-compatible-target.sqlite';
    $schema_view_changed_compatible_metadata = $tmp . '/.forkpress/cow/merge/schema-view-changed-compatible-metadata.sqlite';

    $db = open_db($schema_view_changed_compatible_base);
    $db->exec('CREATE TABLE plugin_schema_view_changed_compatible_items (label TEXT NOT NULL)');
    $db->exec("INSERT INTO plugin_schema_view_changed_compatible_items (label) VALUES ('compatible changed view anchor')");
    $db->exec('CREATE VIEW plugin_schema_view_changed_compatible_view AS SELECT label FROM plugin_schema_view_changed_compatible_items');
    $db->close();
    copy($schema_view_changed_compatible_base, $schema_view_changed_compatible_source);
    copy($schema_view_changed_compatible_base, $schema_view_changed_compatible_target);

    $source_db = open_db($schema_view_changed_compatible_source);
    $source_db->exec('DROP VIEW plugin_schema_view_changed_compatible_view');
    $source_db->exec("CREATE VIEW plugin_schema_view_changed_compatible_view AS SELECT label || ' source' AS label FROM plugin_schema_view_changed_compatible_items");
    $source_changed_view_sql = (string)$source_db->querySingle("SELECT sql FROM sqlite_master WHERE type = 'view' AND name = 'plugin_schema_view_changed_compatible_view'");
    $source_db->close();

    $base_db = open_db($schema_view_changed_compatible_base);
    $base_changed_view_sql = (string)$base_db->querySingle("SELECT sql FROM sqlite_master WHERE type = 'view' AND name = 'plugin_schema_view_changed_compatible_view'");
    $base_db->close();

    @mkdir(dirname($schema_view_changed_compatible_metadata), 0777, true);
    $schema_view_changed_compatible_meta = open_db($schema_view_changed_compatible_metadata);
    cow_merge_ensure_metadata($schema_view_changed_compatible_meta);
    $schema_view_changed_compatible_run_id = cow_merge_start_run(
        $schema_view_changed_compatible_meta,
        'feature-schema-view-changed-compatible',
        'main',
        $schema_view_changed_compatible_base,
        $schema_view_changed_compatible_source,
        $schema_view_changed_compatible_target
    );
    cow_merge_record_schema_conflict(
        $schema_view_changed_compatible_meta,
        $schema_view_changed_compatible_run_id,
        'plugin_schema_view_changed_compatible_items',
        'plugin_schema_view_changed_compatible_view',
        'schema-source-changed-view',
        $base_changed_view_sql,
        ['sql' => $source_changed_view_sql],
        $base_changed_view_sql,
        $base_changed_view_sql,
        'manual source-changed view conflict for compatible target-drift revalidation'
    );
    cow_merge_finish_run($schema_view_changed_compatible_meta, $schema_view_changed_compatible_run_id, 'completed_with_conflicts');
    $schema_view_changed_compatible_meta->close();

    $schema_view_changed_compatible_conflict_id = (int)scalar($schema_view_changed_compatible_metadata, "SELECT id FROM merge_conflicts WHERE conflict_type = 'schema-source-changed-view' ORDER BY id DESC LIMIT 1");
    assert_true($schema_view_changed_compatible_conflict_id > 0, 'compatible schema changed-view target-drift fixture records a legacy source-changed view conflict');
    cow_merge_review_record(
        $schema_view_changed_compatible_metadata,
        'conflict',
        $schema_view_changed_compatible_conflict_id,
        'reviewed',
        'Review source-changed view before compatible target drift.',
        'cow-test'
    );
    $target_db = open_db($schema_view_changed_compatible_target);
    $target_db->exec('DROP VIEW plugin_schema_view_changed_compatible_view');
    $target_db->exec("CREATE VIEW plugin_schema_view_changed_compatible_view AS SELECT label || ' target' AS label FROM plugin_schema_view_changed_compatible_items");
    $target_db->close();

    $schema_view_changed_compatible_revalidated = cow_merge_revalidate_reviewed_conflicts(
        $schema_view_changed_compatible_metadata,
        $schema_view_changed_compatible_run_id,
        'cow-revalidate'
    );
    assert_same($schema_view_changed_compatible_revalidated['checked'], 1, 'compatible schema changed-view target drift revalidation checks the reviewed conflict');
    assert_same($schema_view_changed_compatible_revalidated['stale'], 1, 'compatible schema changed-view target drift is treated as stale');
    assert_same($schema_view_changed_compatible_revalidated['carried'], 1, 'compatible schema changed-view target drift returns the conflict to needs-action');
    assert_same(
        scalar($schema_view_changed_compatible_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $schema_view_changed_compatible_conflict_id ORDER BY id DESC LIMIT 1"),
        'compatible-schema-view-target-drift',
        'schema changed-view target drift is classified compatible when source replacement validates'
    );
    $schema_view_changed_compatible_resolution = cow_merge_resolve_conflict(
        $schema_view_changed_compatible_metadata,
        $schema_view_changed_compatible_conflict_id,
        'source',
        true,
        'Apply source-changed view after compatible target drift revalidation.',
        'cow-test',
        true
    );
    assert_same($schema_view_changed_compatible_resolution['status'], 'applied', 'compatible schema changed-view target drift resolves after revalidation');
    assert_same(
        scalar($schema_view_changed_compatible_target, "SELECT label FROM plugin_schema_view_changed_compatible_view"),
        'compatible changed view anchor source',
        'compatible schema changed-view target drift applies the audited source view'
    );

    $schema_trigger_compatible_base = $tmp . '/schema-trigger-compatible-base.sqlite';
    $schema_trigger_compatible_source = $tmp . '/schema-trigger-compatible-source.sqlite';
    $schema_trigger_compatible_target = $tmp . '/schema-trigger-compatible-target.sqlite';
    $schema_trigger_compatible_metadata = $tmp . '/.forkpress/cow/merge/schema-trigger-compatible-metadata.sqlite';

    $db = open_db($schema_trigger_compatible_base);
    $db->exec('CREATE TABLE plugin_schema_trigger_compatible_items (label TEXT NOT NULL)');
    $db->exec("INSERT INTO plugin_schema_trigger_compatible_items (label) VALUES ('compatible trigger anchor')");
    $db->close();
    copy($schema_trigger_compatible_base, $schema_trigger_compatible_source);
    copy($schema_trigger_compatible_base, $schema_trigger_compatible_target);

    $source_db = open_db($schema_trigger_compatible_source);
    $source_db->exec('CREATE TRIGGER plugin_schema_trigger_compatible_trigger AFTER INSERT ON plugin_schema_trigger_compatible_items BEGIN SELECT NEW.label; END');
    $source_trigger_sql = (string)$source_db->querySingle("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_schema_trigger_compatible_trigger'");
    $source_db->close();

    @mkdir(dirname($schema_trigger_compatible_metadata), 0777, true);
    $schema_trigger_compatible_meta = open_db($schema_trigger_compatible_metadata);
    cow_merge_ensure_metadata($schema_trigger_compatible_meta);
    $schema_trigger_compatible_run_id = cow_merge_start_run(
        $schema_trigger_compatible_meta,
        'feature-schema-trigger-compatible',
        'main',
        $schema_trigger_compatible_base,
        $schema_trigger_compatible_source,
        $schema_trigger_compatible_target
    );
    cow_merge_record_schema_conflict(
        $schema_trigger_compatible_meta,
        $schema_trigger_compatible_run_id,
        'plugin_schema_trigger_compatible_items',
        'plugin_schema_trigger_compatible_trigger',
        'schema-source-added-trigger',
        null,
        ['sql' => $source_trigger_sql],
        null,
        null,
        'manual source-added trigger conflict for compatible target-drift revalidation'
    );
    cow_merge_finish_run($schema_trigger_compatible_meta, $schema_trigger_compatible_run_id, 'completed_with_conflicts');
    $schema_trigger_compatible_meta->close();
    $schema_trigger_compatible_conflict_id = (int)scalar($schema_trigger_compatible_metadata, "SELECT id FROM merge_conflicts WHERE conflict_type = 'schema-source-added-trigger' ORDER BY id DESC LIMIT 1");
    assert_true($schema_trigger_compatible_conflict_id > 0, 'compatible schema trigger target-drift fixture records a source-added trigger conflict');
    cow_merge_review_record(
        $schema_trigger_compatible_metadata,
        'conflict',
        $schema_trigger_compatible_conflict_id,
        'reviewed',
        'Review source-added trigger before compatible target drift.',
        'cow-test'
    );
    $target_db = open_db($schema_trigger_compatible_target);
    $target_db->exec("CREATE TRIGGER plugin_schema_trigger_compatible_trigger AFTER INSERT ON plugin_schema_trigger_compatible_items BEGIN SELECT 'target'; END");
    $target_db->close();

    $schema_trigger_compatible_revalidated = cow_merge_revalidate_reviewed_conflicts($schema_trigger_compatible_metadata, $schema_trigger_compatible_run_id, 'cow-revalidate');
    assert_same($schema_trigger_compatible_revalidated['checked'], 1, 'compatible schema trigger target drift revalidation checks the reviewed conflict');
    assert_same($schema_trigger_compatible_revalidated['stale'], 1, 'compatible schema trigger target drift is treated as stale');
    assert_same($schema_trigger_compatible_revalidated['carried'], 1, 'compatible schema trigger target drift returns the conflict to needs-action');
    assert_same(
        scalar($schema_trigger_compatible_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $schema_trigger_compatible_conflict_id ORDER BY id DESC LIMIT 1"),
        'compatible-schema-trigger-target-drift',
        'schema trigger target drift is classified compatible when source replacement validates'
    );
    $schema_trigger_compatible_resolution = cow_merge_resolve_conflict(
        $schema_trigger_compatible_metadata,
        $schema_trigger_compatible_conflict_id,
        'source',
        true,
        'Apply source trigger after compatible target drift revalidation.',
        'cow-test',
        true
    );
    assert_same($schema_trigger_compatible_resolution['status'], 'applied', 'compatible schema trigger target drift resolves after revalidation');
    assert_true(
        str_contains((string)scalar($schema_trigger_compatible_target, "SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_schema_trigger_compatible_trigger'"), 'SELECT NEW.label'),
        'compatible schema trigger target drift applies the audited source trigger'
    );

    $schema_trigger_changed_compatible_base = $tmp . '/schema-trigger-changed-compatible-base.sqlite';
    $schema_trigger_changed_compatible_source = $tmp . '/schema-trigger-changed-compatible-source.sqlite';
    $schema_trigger_changed_compatible_target = $tmp . '/schema-trigger-changed-compatible-target.sqlite';
    $schema_trigger_changed_compatible_metadata = $tmp . '/.forkpress/cow/merge/schema-trigger-changed-compatible-metadata.sqlite';

    $db = open_db($schema_trigger_changed_compatible_base);
    $db->exec('CREATE TABLE plugin_schema_trigger_changed_compatible_items (label TEXT NOT NULL)');
    $db->exec('CREATE TABLE plugin_schema_trigger_changed_compatible_audit (label TEXT NOT NULL)');
    $db->exec("INSERT INTO plugin_schema_trigger_changed_compatible_items (label) VALUES ('compatible changed trigger anchor')");
    $db->exec("CREATE TRIGGER plugin_schema_trigger_changed_compatible_trigger AFTER INSERT ON plugin_schema_trigger_changed_compatible_items BEGIN INSERT INTO plugin_schema_trigger_changed_compatible_audit (label) VALUES ('base:' || NEW.label); END");
    $db->close();
    copy($schema_trigger_changed_compatible_base, $schema_trigger_changed_compatible_source);
    copy($schema_trigger_changed_compatible_base, $schema_trigger_changed_compatible_target);

    $base_db = open_db($schema_trigger_changed_compatible_base);
    $base_changed_trigger_sql = (string)$base_db->querySingle("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_schema_trigger_changed_compatible_trigger'");
    $base_db->close();

    $source_db = open_db($schema_trigger_changed_compatible_source);
    $source_db->exec('DROP TRIGGER plugin_schema_trigger_changed_compatible_trigger');
    $source_db->exec("CREATE TRIGGER plugin_schema_trigger_changed_compatible_trigger AFTER INSERT ON plugin_schema_trigger_changed_compatible_items BEGIN INSERT INTO plugin_schema_trigger_changed_compatible_audit (label) VALUES ('source:' || NEW.label); END");
    $source_changed_trigger_sql = (string)$source_db->querySingle("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_schema_trigger_changed_compatible_trigger'");
    $source_db->close();

    @mkdir(dirname($schema_trigger_changed_compatible_metadata), 0777, true);
    $schema_trigger_changed_compatible_meta = open_db($schema_trigger_changed_compatible_metadata);
    cow_merge_ensure_metadata($schema_trigger_changed_compatible_meta);
    $schema_trigger_changed_compatible_run_id = cow_merge_start_run(
        $schema_trigger_changed_compatible_meta,
        'feature-schema-trigger-changed-compatible',
        'main',
        $schema_trigger_changed_compatible_base,
        $schema_trigger_changed_compatible_source,
        $schema_trigger_changed_compatible_target
    );
    cow_merge_record_schema_conflict(
        $schema_trigger_changed_compatible_meta,
        $schema_trigger_changed_compatible_run_id,
        'plugin_schema_trigger_changed_compatible_items',
        'plugin_schema_trigger_changed_compatible_trigger',
        'schema-source-changed-trigger',
        $base_changed_trigger_sql,
        ['sql' => $source_changed_trigger_sql],
        $base_changed_trigger_sql,
        $base_changed_trigger_sql,
        'manual source-changed trigger conflict for compatible target-drift revalidation'
    );
    cow_merge_finish_run($schema_trigger_changed_compatible_meta, $schema_trigger_changed_compatible_run_id, 'completed_with_conflicts');
    $schema_trigger_changed_compatible_meta->close();

    $schema_trigger_changed_compatible_conflict_id = (int)scalar($schema_trigger_changed_compatible_metadata, "SELECT id FROM merge_conflicts WHERE conflict_type = 'schema-source-changed-trigger' ORDER BY id DESC LIMIT 1");
    assert_true($schema_trigger_changed_compatible_conflict_id > 0, 'compatible schema changed-trigger target-drift fixture records a legacy source-changed trigger conflict');
    cow_merge_review_record(
        $schema_trigger_changed_compatible_metadata,
        'conflict',
        $schema_trigger_changed_compatible_conflict_id,
        'reviewed',
        'Review source-changed trigger before compatible target drift.',
        'cow-test'
    );
    $target_db = open_db($schema_trigger_changed_compatible_target);
    $target_db->exec('DROP TRIGGER plugin_schema_trigger_changed_compatible_trigger');
    $target_db->exec("CREATE TRIGGER plugin_schema_trigger_changed_compatible_trigger AFTER INSERT ON plugin_schema_trigger_changed_compatible_items BEGIN INSERT INTO plugin_schema_trigger_changed_compatible_audit (label) VALUES ('target:' || NEW.label); END");
    $target_db->close();

    $schema_trigger_changed_compatible_revalidated = cow_merge_revalidate_reviewed_conflicts(
        $schema_trigger_changed_compatible_metadata,
        $schema_trigger_changed_compatible_run_id,
        'cow-revalidate'
    );
    assert_same($schema_trigger_changed_compatible_revalidated['checked'], 1, 'compatible schema changed-trigger target drift revalidation checks the reviewed conflict');
    assert_same($schema_trigger_changed_compatible_revalidated['stale'], 1, 'compatible schema changed-trigger target drift is treated as stale');
    assert_same($schema_trigger_changed_compatible_revalidated['carried'], 1, 'compatible schema changed-trigger target drift returns the conflict to needs-action');
    assert_same(
        scalar($schema_trigger_changed_compatible_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $schema_trigger_changed_compatible_conflict_id ORDER BY id DESC LIMIT 1"),
        'compatible-schema-trigger-target-drift',
        'schema changed-trigger target drift is classified compatible when source replacement validates'
    );
    $schema_trigger_changed_compatible_resolution = cow_merge_resolve_conflict(
        $schema_trigger_changed_compatible_metadata,
        $schema_trigger_changed_compatible_conflict_id,
        'source',
        true,
        'Apply source-changed trigger after compatible target drift revalidation.',
        'cow-test',
        true
    );
    assert_same($schema_trigger_changed_compatible_resolution['status'], 'applied', 'compatible schema changed-trigger target drift resolves after revalidation');
    $target_db = open_db($schema_trigger_changed_compatible_target);
    $target_db->exec("INSERT INTO plugin_schema_trigger_changed_compatible_items (label) VALUES ('proof')");
    $target_db->close();
    assert_same(
        scalar($schema_trigger_changed_compatible_target, "SELECT label FROM plugin_schema_trigger_changed_compatible_audit ORDER BY rowid DESC LIMIT 1"),
        'source:proof',
        'compatible schema changed-trigger target drift applies the audited source trigger'
    );

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
    assert_same(
        scalar($schema_index_target_drift_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $schema_index_target_drift_conflict_id ORDER BY id DESC LIMIT 1"),
        'compatible-schema-index-target-drift',
        'schema index target SQL drift is classified compatible when the source index validates over current target state'
    );
    $schema_index_target_drift_resolution = cow_merge_resolve_conflict(
        $schema_index_target_drift_metadata,
        $schema_index_target_drift_conflict_id,
        'source',
        true,
        'Apply source index after compatible target drift revalidation.',
        'cow-test',
        true
    );
    assert_same($schema_index_target_drift_resolution['status'], 'applied', 'compatible schema index target drift resolves after revalidation');
    assert_true(
        str_contains((string)scalar($schema_index_target_drift_target, "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'plugin_schema_index_target_drift_idx'"), 'UNIQUE INDEX plugin_schema_index_target_drift_idx ON plugin_schema_index_target_drift_items(lower(label))'),
        'compatible schema index target drift applies the audited source index'
    );

    $schema_index_changed_compatible_base = $tmp . '/schema-index-changed-compatible-base.sqlite';
    $schema_index_changed_compatible_source = $tmp . '/schema-index-changed-compatible-source.sqlite';
    $schema_index_changed_compatible_target = $tmp . '/schema-index-changed-compatible-target.sqlite';
    $schema_index_changed_compatible_metadata = $tmp . '/.forkpress/cow/merge/schema-index-changed-compatible-metadata.sqlite';

    $db = open_db($schema_index_changed_compatible_base);
    $db->exec('CREATE TABLE plugin_schema_index_changed_compatible_items (label TEXT NOT NULL, slug TEXT NOT NULL)');
    $db->exec("INSERT INTO plugin_schema_index_changed_compatible_items (label, slug) VALUES ('Compatible Changed Index Anchor', 'compatible-changed-index-anchor')");
    $db->exec('CREATE INDEX plugin_schema_index_changed_compatible_idx ON plugin_schema_index_changed_compatible_items(label)');
    $db->close();
    copy($schema_index_changed_compatible_base, $schema_index_changed_compatible_source);
    copy($schema_index_changed_compatible_base, $schema_index_changed_compatible_target);

    $base_db = open_db($schema_index_changed_compatible_base);
    $base_changed_index_sql = (string)$base_db->querySingle("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'plugin_schema_index_changed_compatible_idx'");
    $base_db->close();

    $source_db = open_db($schema_index_changed_compatible_source);
    $source_db->exec('DROP INDEX plugin_schema_index_changed_compatible_idx');
    $source_db->exec('CREATE UNIQUE INDEX plugin_schema_index_changed_compatible_idx ON plugin_schema_index_changed_compatible_items(lower(slug))');
    $source_changed_index_sql = (string)$source_db->querySingle("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'plugin_schema_index_changed_compatible_idx'");
    $source_db->close();

    @mkdir(dirname($schema_index_changed_compatible_metadata), 0777, true);
    $schema_index_changed_compatible_meta = open_db($schema_index_changed_compatible_metadata);
    cow_merge_ensure_metadata($schema_index_changed_compatible_meta);
    $schema_index_changed_compatible_run_id = cow_merge_start_run(
        $schema_index_changed_compatible_meta,
        'feature-schema-index-changed-compatible',
        'main',
        $schema_index_changed_compatible_base,
        $schema_index_changed_compatible_source,
        $schema_index_changed_compatible_target
    );
    cow_merge_record_schema_conflict(
        $schema_index_changed_compatible_meta,
        $schema_index_changed_compatible_run_id,
        'plugin_schema_index_changed_compatible_items',
        'plugin_schema_index_changed_compatible_idx',
        'schema-source-changed-index',
        $base_changed_index_sql,
        ['sql' => $source_changed_index_sql],
        $base_changed_index_sql,
        $base_changed_index_sql,
        'manual source-changed index conflict for compatible target-drift revalidation'
    );
    cow_merge_finish_run($schema_index_changed_compatible_meta, $schema_index_changed_compatible_run_id, 'completed_with_conflicts');
    $schema_index_changed_compatible_meta->close();

    $schema_index_changed_compatible_conflict_id = (int)scalar($schema_index_changed_compatible_metadata, "SELECT id FROM merge_conflicts WHERE conflict_type = 'schema-source-changed-index' ORDER BY id DESC LIMIT 1");
    assert_true($schema_index_changed_compatible_conflict_id > 0, 'compatible schema changed-index target-drift fixture records a legacy source-changed index conflict');
    cow_merge_review_record(
        $schema_index_changed_compatible_metadata,
        'conflict',
        $schema_index_changed_compatible_conflict_id,
        'reviewed',
        'Review source-changed index before compatible target drift.',
        'cow-test'
    );
    $target_db = open_db($schema_index_changed_compatible_target);
    $target_db->exec('DROP INDEX plugin_schema_index_changed_compatible_idx');
    $target_db->exec('CREATE INDEX plugin_schema_index_changed_compatible_idx ON plugin_schema_index_changed_compatible_items(slug)');
    $target_db->close();

    $schema_index_changed_compatible_revalidated = cow_merge_revalidate_reviewed_conflicts(
        $schema_index_changed_compatible_metadata,
        $schema_index_changed_compatible_run_id,
        'cow-revalidate'
    );
    assert_same($schema_index_changed_compatible_revalidated['checked'], 1, 'compatible schema changed-index target drift revalidation checks the reviewed conflict');
    assert_same($schema_index_changed_compatible_revalidated['stale'], 1, 'compatible schema changed-index target drift is treated as stale');
    assert_same($schema_index_changed_compatible_revalidated['carried'], 1, 'compatible schema changed-index target drift returns the conflict to needs-action');
    assert_same(
        scalar($schema_index_changed_compatible_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $schema_index_changed_compatible_conflict_id ORDER BY id DESC LIMIT 1"),
        'compatible-schema-index-target-drift',
        'schema changed-index target drift is classified compatible when source replacement validates'
    );
    $schema_index_changed_compatible_resolution = cow_merge_resolve_conflict(
        $schema_index_changed_compatible_metadata,
        $schema_index_changed_compatible_conflict_id,
        'source',
        true,
        'Apply source-changed index after compatible target drift revalidation.',
        'cow-test',
        true
    );
    assert_same($schema_index_changed_compatible_resolution['status'], 'applied', 'compatible schema changed-index target drift resolves after revalidation');
    assert_true(
        str_contains((string)scalar($schema_index_changed_compatible_target, "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'plugin_schema_index_changed_compatible_idx'"), 'UNIQUE INDEX plugin_schema_index_changed_compatible_idx ON plugin_schema_index_changed_compatible_items(lower(slug))'),
        'compatible schema changed-index target drift applies the audited source index'
    );

    $schema_index_drop_target_drift_base = $tmp . '/schema-index-drop-target-drift-base.sqlite';
    $schema_index_drop_target_drift_source = $tmp . '/schema-index-drop-target-drift-source.sqlite';
    $schema_index_drop_target_drift_target = $tmp . '/schema-index-drop-target-drift-target.sqlite';
    $schema_index_drop_target_drift_metadata = $tmp . '/.forkpress/cow/merge/schema-index-drop-target-drift-metadata.sqlite';

    $db = open_db($schema_index_drop_target_drift_base);
    $db->exec('CREATE TABLE plugin_schema_index_drop_target_drift_items (label TEXT NOT NULL, slug TEXT NOT NULL)');
    $db->exec("INSERT INTO plugin_schema_index_drop_target_drift_items (label, slug) VALUES ('Drop Index Target Drift', 'drop-index-target-drift')");
    $db->exec('CREATE INDEX plugin_schema_index_drop_target_drift_idx ON plugin_schema_index_drop_target_drift_items(label)');
    $db->close();
    copy($schema_index_drop_target_drift_base, $schema_index_drop_target_drift_source);
    copy($schema_index_drop_target_drift_base, $schema_index_drop_target_drift_target);

    $source_db = open_db($schema_index_drop_target_drift_source);
    $source_db->exec('DROP INDEX plugin_schema_index_drop_target_drift_idx');
    $source_db->close();

    $target_db = open_db($schema_index_drop_target_drift_target);
    $target_db->exec('DROP INDEX plugin_schema_index_drop_target_drift_idx');
    $target_db->exec('CREATE INDEX plugin_schema_index_drop_target_drift_idx ON plugin_schema_index_drop_target_drift_items(slug)');
    $target_db->close();

    $schema_index_drop_target_drift_result = cow_merge_databases(
        $schema_index_drop_target_drift_base,
        $schema_index_drop_target_drift_source,
        $schema_index_drop_target_drift_target,
        $schema_index_drop_target_drift_metadata,
        'feature-schema-index-drop-target-drift',
        'main'
    );
    $schema_index_drop_target_drift_run_id = (int)$schema_index_drop_target_drift_result['run_id'];
    assert_same($schema_index_drop_target_drift_result['status'], 'completed_with_conflicts', 'source-dropped index with target drift starts reviewable');
    $schema_index_drop_target_drift_conflict_id = (int)scalar($schema_index_drop_target_drift_metadata, "SELECT id FROM merge_conflicts WHERE conflict_type = 'schema-source-dropped-index' AND column_name = 'plugin_schema_index_drop_target_drift_idx' ORDER BY id DESC LIMIT 1");
    assert_true($schema_index_drop_target_drift_conflict_id > 0, 'source-dropped index target-drift fixture records an index conflict');
    cow_merge_review_record(
        $schema_index_drop_target_drift_metadata,
        'conflict',
        $schema_index_drop_target_drift_conflict_id,
        'reviewed',
        'Review source-dropped index before compatible target drift.',
        'cow-test'
    );

    $target_db = open_db($schema_index_drop_target_drift_target);
    $target_db->exec('DROP INDEX plugin_schema_index_drop_target_drift_idx');
    $target_db->exec('CREATE INDEX plugin_schema_index_drop_target_drift_idx ON plugin_schema_index_drop_target_drift_items(label, slug)');
    $target_db->close();

    $schema_index_drop_target_drift_revalidated = cow_merge_revalidate_reviewed_conflicts(
        $schema_index_drop_target_drift_metadata,
        $schema_index_drop_target_drift_run_id,
        'cow-revalidate'
    );
    assert_same($schema_index_drop_target_drift_revalidated['checked'], 1, 'source-dropped index target drift revalidation checks the reviewed conflict');
    assert_same($schema_index_drop_target_drift_revalidated['stale'], 1, 'source-dropped index target drift is treated as stale');
    assert_same($schema_index_drop_target_drift_revalidated['carried'], 1, 'source-dropped index target drift returns the conflict to needs-action');
    assert_same(
        scalar($schema_index_drop_target_drift_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $schema_index_drop_target_drift_conflict_id ORDER BY id DESC LIMIT 1"),
        'compatible-schema-index-target-drift',
        'source-dropped index target drift is classified compatible when the source drop validates'
    );
    $schema_index_drop_target_drift_audit = cow_merge_audit_report($schema_index_drop_target_drift_metadata, $schema_index_drop_target_drift_run_id, 10, [
        'records' => 'conflicts',
        'revalidation_class' => 'compatible-schema-index-target-drift',
    ]);
    assert_same(count($schema_index_drop_target_drift_audit['conflicts']), 1, 'source-dropped index target drift can be filtered by revalidation class');
    assert_true($schema_index_drop_target_drift_audit['conflicts'][0]['after_revalidate_supported'] ?? false, 'source-dropped index target drift advertises guarded after-revalidate support');
    $schema_index_drop_target_drift_resolution = cow_merge_resolve_conflict(
        $schema_index_drop_target_drift_metadata,
        $schema_index_drop_target_drift_conflict_id,
        'source',
        true,
        'Apply source-dropped index after compatible target drift revalidation.',
        'cow-test',
        true
    );
    assert_same($schema_index_drop_target_drift_resolution['status'], 'applied', 'compatible source-dropped index target drift resolves after revalidation');
    assert_same(
        scalar($schema_index_drop_target_drift_target, "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'plugin_schema_index_drop_target_drift_idx'"),
        null,
        'compatible source-dropped index target drift removes the current target index'
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

    $view_rewrite_base = $tmp . '/view-rewrite-base.sqlite';
    $view_rewrite_source = $tmp . '/view-rewrite-source.sqlite';
    $view_rewrite_target = $tmp . '/view-rewrite-target.sqlite';
    $view_rewrite_metadata = $tmp . '/.forkpress/cow/merge/schema-view-rewrite-metadata.sqlite';

    $db = open_db($view_rewrite_base);
    $db->exec('CREATE TABLE plugin_view_rewrite_items (item_id TEXT PRIMARY KEY, label TEXT NOT NULL)');
    $db->exec("INSERT INTO plugin_view_rewrite_items (item_id, label) VALUES ('view-rewrite', 'View Rewrite')");
    $db->exec('CREATE VIEW plugin_view_rewrite_visible AS SELECT item_id, label FROM plugin_view_rewrite_items');
    $db->close();
    copy($view_rewrite_base, $view_rewrite_source);
    copy($view_rewrite_base, $view_rewrite_target);

    $source_db = open_db($view_rewrite_source);
    $source_db->exec('DROP VIEW plugin_view_rewrite_visible');
    $source_db->exec("CREATE VIEW plugin_view_rewrite_visible AS SELECT item_id, label || ':source' AS label FROM plugin_view_rewrite_items");
    $source_db->close();

    $view_rewrite_result = cow_merge_databases(
        $view_rewrite_base,
        $view_rewrite_source,
        $view_rewrite_target,
        $view_rewrite_metadata,
        'feature-schema-view-rewrite',
        'main'
    );
    $view_rewrite_run_id = (int)$view_rewrite_result['run_id'];
    assert_same($view_rewrite_result['status'], 'completed', 'source-changed view merges automatically when target kept the base view');
    assert_same(
        (int)scalar($view_rewrite_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE run_id = $view_rewrite_run_id AND conflict_type = 'schema-source-changed-view'"),
        0,
        'safe source-changed view rewrite creates no review-only schema conflict'
    );
    assert_same(
        (int)scalar($view_rewrite_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE run_id = $view_rewrite_run_id AND column_name = 'plugin_view_rewrite_visible' AND decision = 'source-applied'"),
        1,
        'automatic source-changed view rewrite is auditable'
    );
    assert_true(
        str_contains((string)scalar($view_rewrite_target, "SELECT sql FROM sqlite_master WHERE type = 'view' AND name = 'plugin_view_rewrite_visible'"), ':source'),
        'automatic source-changed view rewrite installs the source view body'
    );
    assert_same(
        scalar($view_rewrite_target, "SELECT label FROM plugin_view_rewrite_visible WHERE item_id = 'view-rewrite'"),
        'View Rewrite:source',
        'automatically rewritten source view is queryable after merge'
    );

    $view_rewrite_trigger_body_base = $tmp . '/view-rewrite-trigger-body-base.sqlite';
    $view_rewrite_trigger_body_source = $tmp . '/view-rewrite-trigger-body-source.sqlite';
    $view_rewrite_trigger_body_target = $tmp . '/view-rewrite-trigger-body-target.sqlite';
    $view_rewrite_trigger_body_metadata = $tmp . '/.forkpress/cow/merge/schema-view-rewrite-trigger-body-metadata.sqlite';

    $db = open_db($view_rewrite_trigger_body_base);
    $db->exec('CREATE TABLE plugin_view_rewrite_trigger_body_items (item_id TEXT PRIMARY KEY, legacy_label TEXT NOT NULL, modern_label TEXT NOT NULL)');
    $db->exec("INSERT INTO plugin_view_rewrite_trigger_body_items (item_id, legacy_label, modern_label) VALUES ('view-trigger-body', 'Legacy label', 'Modern label')");
    $db->exec('CREATE VIEW plugin_view_rewrite_trigger_body_visible AS SELECT item_id, legacy_label AS label FROM plugin_view_rewrite_trigger_body_items');
    $db->exec('CREATE TABLE plugin_view_rewrite_trigger_body_events (item_id TEXT PRIMARY KEY)');
    $db->exec('CREATE TABLE plugin_view_rewrite_trigger_body_audit (item_id TEXT, label TEXT)');
    $db->exec(<<<'SQL'
CREATE TRIGGER plugin_view_rewrite_trigger_body_events_insert
AFTER INSERT ON plugin_view_rewrite_trigger_body_events
BEGIN
    INSERT INTO plugin_view_rewrite_trigger_body_audit (item_id, label)
    SELECT NEW.item_id, label FROM plugin_view_rewrite_trigger_body_visible WHERE item_id = NEW.item_id;
END
SQL);
    $db->close();
    copy($view_rewrite_trigger_body_base, $view_rewrite_trigger_body_source);
    copy($view_rewrite_trigger_body_base, $view_rewrite_trigger_body_target);

    $source_db = open_db($view_rewrite_trigger_body_source);
    $source_db->exec('DROP VIEW plugin_view_rewrite_trigger_body_visible');
    $source_db->exec('CREATE VIEW plugin_view_rewrite_trigger_body_visible AS SELECT item_id, modern_label FROM plugin_view_rewrite_trigger_body_items');
    $source_db->close();

    $view_rewrite_trigger_body_result = cow_merge_databases(
        $view_rewrite_trigger_body_base,
        $view_rewrite_trigger_body_source,
        $view_rewrite_trigger_body_target,
        $view_rewrite_trigger_body_metadata,
        'feature-schema-view-rewrite-trigger-body',
        'main'
    );
    $view_rewrite_trigger_body_run_id = (int)$view_rewrite_trigger_body_result['run_id'];
    assert_same($view_rewrite_trigger_body_result['status'], 'completed_with_conflicts', 'source-changed view stays reviewable when target trigger body would become invalid');
    assert_same(
        (int)scalar($view_rewrite_trigger_body_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE run_id = $view_rewrite_trigger_body_run_id AND column_name = 'plugin_view_rewrite_trigger_body_visible' AND conflict_type = 'schema-source-changed-view'"),
        1,
        'invalid trigger-body view rewrite records a source-changed view conflict'
    );
    $view_rewrite_trigger_body_payload = cow_merge_decode_payload_json(
        (string)scalar($view_rewrite_trigger_body_metadata, "SELECT source_payload FROM merge_conflicts WHERE run_id = $view_rewrite_trigger_body_run_id AND column_name = 'plugin_view_rewrite_trigger_body_visible' ORDER BY id DESC LIMIT 1"),
        'schema view trigger-body source conflict payload'
    );
    assert_true(
        str_contains((string)($view_rewrite_trigger_body_payload['validation_error'] ?? ''), 'plugin_view_rewrite_trigger_body_events_insert'),
        'invalid trigger-body view rewrite payload names the dependent target trigger'
    );
    assert_true(
        str_contains((string)scalar($view_rewrite_trigger_body_target, "SELECT sql FROM sqlite_master WHERE type = 'view' AND name = 'plugin_view_rewrite_trigger_body_visible'"), 'legacy_label AS label'),
        'invalid trigger-body view rewrite leaves the target view unchanged before review'
    );
    $target_db = open_db($view_rewrite_trigger_body_target);
    $target_db->exec("INSERT INTO plugin_view_rewrite_trigger_body_events (item_id) VALUES ('view-trigger-body')");
    $target_db->close();
    assert_same(
        scalar($view_rewrite_trigger_body_target, "SELECT label FROM plugin_view_rewrite_trigger_body_audit WHERE item_id = 'view-trigger-body'"),
        'Legacy label',
        'invalid trigger-body view rewrite leaves the target trigger runnable before review'
    );

    $view_rewrite_dropped_trigger_base = $tmp . '/view-rewrite-dropped-trigger-base.sqlite';
    $view_rewrite_dropped_trigger_source = $tmp . '/view-rewrite-dropped-trigger-source.sqlite';
    $view_rewrite_dropped_trigger_target = $tmp . '/view-rewrite-dropped-trigger-target.sqlite';
    $view_rewrite_dropped_trigger_metadata = $tmp . '/.forkpress/cow/merge/schema-view-rewrite-dropped-trigger-metadata.sqlite';

    $db = open_db($view_rewrite_dropped_trigger_base);
    $db->exec('CREATE TABLE plugin_view_rewrite_dropped_trigger_items (item_id TEXT PRIMARY KEY, legacy_label TEXT NOT NULL, modern_label TEXT NOT NULL)');
    $db->exec("INSERT INTO plugin_view_rewrite_dropped_trigger_items (item_id, legacy_label, modern_label) VALUES ('view-dropped-trigger', 'Legacy dropped trigger', 'Modern dropped trigger')");
    $db->exec('CREATE VIEW plugin_view_rewrite_dropped_trigger_visible AS SELECT item_id, legacy_label AS label FROM plugin_view_rewrite_dropped_trigger_items');
    $db->exec('CREATE TABLE plugin_view_rewrite_dropped_trigger_events (item_id TEXT PRIMARY KEY)');
    $db->exec('CREATE TABLE plugin_view_rewrite_dropped_trigger_audit (item_id TEXT, label TEXT)');
    $db->exec(<<<'SQL'
CREATE TRIGGER plugin_view_rewrite_dropped_trigger_events_insert
AFTER INSERT ON plugin_view_rewrite_dropped_trigger_events
BEGIN
    INSERT INTO plugin_view_rewrite_dropped_trigger_audit (item_id, label)
    SELECT NEW.item_id, label FROM plugin_view_rewrite_dropped_trigger_visible WHERE item_id = NEW.item_id;
END
SQL);
    $db->close();
    copy($view_rewrite_dropped_trigger_base, $view_rewrite_dropped_trigger_source);
    copy($view_rewrite_dropped_trigger_base, $view_rewrite_dropped_trigger_target);

    $source_db = open_db($view_rewrite_dropped_trigger_source);
    $source_db->exec('DROP TRIGGER plugin_view_rewrite_dropped_trigger_events_insert');
    $source_db->exec('DROP VIEW plugin_view_rewrite_dropped_trigger_visible');
    $source_db->exec('CREATE VIEW plugin_view_rewrite_dropped_trigger_visible AS SELECT item_id, modern_label FROM plugin_view_rewrite_dropped_trigger_items');
    $source_db->close();

    $view_rewrite_dropped_trigger_result = cow_merge_databases(
        $view_rewrite_dropped_trigger_base,
        $view_rewrite_dropped_trigger_source,
        $view_rewrite_dropped_trigger_target,
        $view_rewrite_dropped_trigger_metadata,
        'feature-schema-view-rewrite-dropped-trigger',
        'main'
    );
    $view_rewrite_dropped_trigger_run_id = (int)$view_rewrite_dropped_trigger_result['run_id'];
    assert_same($view_rewrite_dropped_trigger_result['status'], 'completed', 'source-changed view applies automatically when source also drops the invalidating target trigger');
    assert_same(
        (int)scalar($view_rewrite_dropped_trigger_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE run_id = $view_rewrite_dropped_trigger_run_id AND conflict_type IN ('schema-source-changed-view', 'schema-source-dropped-trigger')"),
        0,
        'dropped trigger dependency creates no review-only schema conflict for the source-changed view'
    );
    assert_same(
        (int)scalar($view_rewrite_dropped_trigger_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_view_rewrite_dropped_trigger_events_insert'"),
        0,
        'source-dropped trigger dependency is removed before validating the source-changed view'
    );
    assert_same(
        scalar($view_rewrite_dropped_trigger_target, "SELECT modern_label FROM plugin_view_rewrite_dropped_trigger_visible WHERE item_id = 'view-dropped-trigger'"),
        'Modern dropped trigger',
        'source-changed view remains queryable after its dropped trigger dependency is planned first'
    );
    assert_same(
        (int)scalar($view_rewrite_dropped_trigger_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE run_id = $view_rewrite_dropped_trigger_run_id AND column_name IN ('plugin_view_rewrite_dropped_trigger_visible', 'plugin_view_rewrite_dropped_trigger_events_insert') AND decision = 'source-applied'"),
        2,
        'source-changed view and source-dropped trigger dependency are both audited as source-applied decisions'
    );

    $view_rewrite_source_table_base = $tmp . '/view-rewrite-source-table-base.sqlite';
    $view_rewrite_source_table_source = $tmp . '/view-rewrite-source-table-source.sqlite';
    $view_rewrite_source_table_target = $tmp . '/view-rewrite-source-table-target.sqlite';
    $view_rewrite_source_table_metadata = $tmp . '/.forkpress/cow/merge/schema-view-rewrite-source-table-metadata.sqlite';

    $db = open_db($view_rewrite_source_table_base);
    $db->exec('CREATE TABLE plugin_view_rewrite_source_table_items (item_id TEXT PRIMARY KEY, label TEXT NOT NULL)');
    $db->exec("INSERT INTO plugin_view_rewrite_source_table_items (item_id, label) VALUES ('view-rewrite-source-table', 'View Rewrite Source Table')");
    $db->exec('CREATE VIEW plugin_view_rewrite_source_table_visible AS SELECT item_id, label FROM plugin_view_rewrite_source_table_items');
    $db->close();
    copy($view_rewrite_source_table_base, $view_rewrite_source_table_source);
    copy($view_rewrite_source_table_base, $view_rewrite_source_table_target);

    $source_db = open_db($view_rewrite_source_table_source);
    $source_db->exec('CREATE TABLE plugin_view_rewrite_source_table_suffixes (item_id TEXT PRIMARY KEY, suffix TEXT NOT NULL)');
    $source_db->exec("INSERT INTO plugin_view_rewrite_source_table_suffixes (item_id, suffix) VALUES ('view-rewrite-source-table', ':source-table')");
    $source_db->exec('DROP VIEW plugin_view_rewrite_source_table_visible');
    $source_db->exec("CREATE VIEW plugin_view_rewrite_source_table_visible AS SELECT i.item_id, i.label || COALESCE(s.suffix, '') AS label FROM plugin_view_rewrite_source_table_items i LEFT JOIN plugin_view_rewrite_source_table_suffixes s ON s.item_id = i.item_id");
    $source_db->close();

    $view_rewrite_source_table_result = cow_merge_databases(
        $view_rewrite_source_table_base,
        $view_rewrite_source_table_source,
        $view_rewrite_source_table_target,
        $view_rewrite_source_table_metadata,
        'feature-schema-view-rewrite-source-table',
        'main'
    );
    $view_rewrite_source_table_run_id = (int)$view_rewrite_source_table_result['run_id'];
    assert_same($view_rewrite_source_table_result['status'], 'completed', 'source-changed view depending on a source-added table merges automatically');
    assert_same(
        (int)scalar($view_rewrite_source_table_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE run_id = $view_rewrite_source_table_run_id AND conflict_type = 'schema-source-changed-view'"),
        0,
        'source-changed view rewrite waits for source-added table dependencies before validation'
    );
    assert_same(
        scalar($view_rewrite_source_table_target, "SELECT label FROM plugin_view_rewrite_source_table_visible WHERE item_id = 'view-rewrite-source-table'"),
        'View Rewrite Source Table:source-table',
        'source-changed view can query its source-added table dependency after merge'
    );

    $view_rewrite_dependent_view_base = $tmp . '/view-rewrite-dependent-view-base.sqlite';
    $view_rewrite_dependent_view_source = $tmp . '/view-rewrite-dependent-view-source.sqlite';
    $view_rewrite_dependent_view_target = $tmp . '/view-rewrite-dependent-view-target.sqlite';
    $view_rewrite_dependent_view_metadata = $tmp . '/.forkpress/cow/merge/schema-view-rewrite-dependent-view-metadata.sqlite';

    $db = open_db($view_rewrite_dependent_view_base);
    $db->exec('CREATE TABLE plugin_view_rewrite_dependent_view_items (item_id TEXT PRIMARY KEY, label TEXT NOT NULL)');
    $db->exec("INSERT INTO plugin_view_rewrite_dependent_view_items (item_id, label) VALUES ('view-rewrite-dependent-view', 'View Rewrite Dependent View')");
    $db->exec('CREATE VIEW plugin_view_rewrite_dependent_view_visible AS SELECT item_id, label FROM plugin_view_rewrite_dependent_view_items');
    $db->close();
    copy($view_rewrite_dependent_view_base, $view_rewrite_dependent_view_source);
    copy($view_rewrite_dependent_view_base, $view_rewrite_dependent_view_target);

    $source_db = open_db($view_rewrite_dependent_view_source);
    $source_db->exec('DROP VIEW plugin_view_rewrite_dependent_view_visible');
    $source_db->exec("CREATE VIEW plugin_view_rewrite_dependent_view_visible AS SELECT item_id, label || ':source' AS label FROM plugin_view_rewrite_dependent_view_items");
    $source_db->exec("CREATE VIEW plugin_view_rewrite_dependent_view_child AS SELECT item_id, label || ':child' AS label FROM plugin_view_rewrite_dependent_view_visible");
    $source_db->close();

    $view_rewrite_dependent_view_result = cow_merge_databases(
        $view_rewrite_dependent_view_base,
        $view_rewrite_dependent_view_source,
        $view_rewrite_dependent_view_target,
        $view_rewrite_dependent_view_metadata,
        'feature-schema-view-rewrite-dependent-view',
        'main'
    );
    $view_rewrite_dependent_view_run_id = (int)$view_rewrite_dependent_view_result['run_id'];
    assert_same($view_rewrite_dependent_view_result['status'], 'completed', 'source-added views depending on source-changed views merge automatically');
    assert_same(
        (int)scalar($view_rewrite_dependent_view_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE run_id = $view_rewrite_dependent_view_run_id AND conflict_type IN ('schema-source-changed-view', 'schema-source-added-view')"),
        0,
        'source-added dependent view waits for source-changed view rewrite before validation'
    );
    assert_same(
        scalar($view_rewrite_dependent_view_target, "SELECT label FROM plugin_view_rewrite_dependent_view_child WHERE item_id = 'view-rewrite-dependent-view'"),
        'View Rewrite Dependent View:source:child',
        'source-added dependent view can query the rewritten source view after merge'
    );

    $trigger_rewrite_base = $tmp . '/trigger-rewrite-base.sqlite';
    $trigger_rewrite_source = $tmp . '/trigger-rewrite-source.sqlite';
    $trigger_rewrite_target = $tmp . '/trigger-rewrite-target.sqlite';
    $trigger_rewrite_metadata = $tmp . '/.forkpress/cow/merge/schema-trigger-rewrite-metadata.sqlite';

    $db = open_db($trigger_rewrite_base);
    $db->exec('CREATE TABLE plugin_trigger_rewrite_items (item_id TEXT PRIMARY KEY, label TEXT NOT NULL)');
    $db->exec('CREATE TABLE plugin_trigger_rewrite_audit (item_id TEXT, label TEXT)');
    $db->exec('CREATE TRIGGER plugin_trigger_rewrite_items_after AFTER INSERT ON plugin_trigger_rewrite_items BEGIN INSERT INTO plugin_trigger_rewrite_audit (item_id, label) VALUES (NEW.item_id, NEW.label); END');
    $db->close();
    copy($trigger_rewrite_base, $trigger_rewrite_source);
    copy($trigger_rewrite_base, $trigger_rewrite_target);

    $source_db = open_db($trigger_rewrite_source);
    $source_db->exec('DROP TRIGGER plugin_trigger_rewrite_items_after');
    $source_db->exec("CREATE TRIGGER plugin_trigger_rewrite_items_after AFTER INSERT ON plugin_trigger_rewrite_items BEGIN INSERT INTO plugin_trigger_rewrite_audit (item_id, label) VALUES (NEW.item_id, NEW.label || ':source'); END");
    $source_db->close();

    $trigger_rewrite_result = cow_merge_databases(
        $trigger_rewrite_base,
        $trigger_rewrite_source,
        $trigger_rewrite_target,
        $trigger_rewrite_metadata,
        'feature-schema-trigger-rewrite',
        'main'
    );
    $trigger_rewrite_run_id = (int)$trigger_rewrite_result['run_id'];
    assert_same($trigger_rewrite_result['status'], 'completed', 'source-changed trigger merges automatically when target kept the base trigger');
    assert_same(
        (int)scalar($trigger_rewrite_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE run_id = $trigger_rewrite_run_id AND conflict_type = 'schema-source-changed-trigger'"),
        0,
        'safe source-changed trigger rewrite creates no review-only schema conflict'
    );
    assert_same(
        (int)scalar($trigger_rewrite_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE run_id = $trigger_rewrite_run_id AND column_name = 'plugin_trigger_rewrite_items_after' AND decision = 'source-applied'"),
        1,
        'automatic source-changed trigger rewrite is auditable'
    );
    assert_true(
        str_contains((string)scalar($trigger_rewrite_target, "SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_trigger_rewrite_items_after'"), ':source'),
        'automatic source-changed trigger rewrite installs the source trigger body'
    );
    $target_db = open_db($trigger_rewrite_target);
    $target_db->exec("INSERT INTO plugin_trigger_rewrite_items (item_id, label) VALUES ('trigger-rewrite', 'Trigger Rewrite')");
    $target_db->close();
    assert_same(
        scalar($trigger_rewrite_target, "SELECT label FROM plugin_trigger_rewrite_audit WHERE item_id = 'trigger-rewrite'"),
        'Trigger Rewrite:source',
        'automatically rewritten source trigger fires after merge'
    );

    $trigger_rewrite_source_table_base = $tmp . '/trigger-rewrite-source-table-base.sqlite';
    $trigger_rewrite_source_table_source = $tmp . '/trigger-rewrite-source-table-source.sqlite';
    $trigger_rewrite_source_table_target = $tmp . '/trigger-rewrite-source-table-target.sqlite';
    $trigger_rewrite_source_table_metadata = $tmp . '/.forkpress/cow/merge/schema-trigger-rewrite-source-table-metadata.sqlite';

    $db = open_db($trigger_rewrite_source_table_base);
    $db->exec('CREATE TABLE plugin_trigger_rewrite_source_table_items (item_id TEXT PRIMARY KEY, label TEXT NOT NULL)');
    $db->exec('CREATE TABLE plugin_trigger_rewrite_source_table_audit (item_id TEXT, label TEXT)');
    $db->exec('CREATE TRIGGER plugin_trigger_rewrite_source_table_items_after AFTER INSERT ON plugin_trigger_rewrite_source_table_items BEGIN INSERT INTO plugin_trigger_rewrite_source_table_audit (item_id, label) VALUES (NEW.item_id, NEW.label); END');
    $db->close();
    copy($trigger_rewrite_source_table_base, $trigger_rewrite_source_table_source);
    copy($trigger_rewrite_source_table_base, $trigger_rewrite_source_table_target);

    $source_db = open_db($trigger_rewrite_source_table_source);
    $source_db->exec('CREATE TABLE plugin_trigger_rewrite_source_table_suffixes (suffix_key TEXT PRIMARY KEY, suffix TEXT NOT NULL)');
    $source_db->exec("INSERT INTO plugin_trigger_rewrite_source_table_suffixes (suffix_key, suffix) VALUES ('default', ':source-table')");
    $source_db->exec('DROP TRIGGER plugin_trigger_rewrite_source_table_items_after');
    $source_db->exec("CREATE TRIGGER plugin_trigger_rewrite_source_table_items_after AFTER INSERT ON plugin_trigger_rewrite_source_table_items BEGIN INSERT INTO plugin_trigger_rewrite_source_table_audit (item_id, label) SELECT NEW.item_id, NEW.label || suffix FROM plugin_trigger_rewrite_source_table_suffixes WHERE suffix_key = 'default'; END");
    $source_db->close();

    $trigger_rewrite_source_table_result = cow_merge_databases(
        $trigger_rewrite_source_table_base,
        $trigger_rewrite_source_table_source,
        $trigger_rewrite_source_table_target,
        $trigger_rewrite_source_table_metadata,
        'feature-schema-trigger-rewrite-source-table',
        'main'
    );
    $trigger_rewrite_source_table_run_id = (int)$trigger_rewrite_source_table_result['run_id'];
    assert_same($trigger_rewrite_source_table_result['status'], 'completed', 'source-changed trigger depending on a source-added table merges automatically');
    assert_same(
        (int)scalar($trigger_rewrite_source_table_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE run_id = $trigger_rewrite_source_table_run_id AND conflict_type = 'schema-source-changed-trigger'"),
        0,
        'source-changed trigger rewrite waits for source-added table dependencies before validation'
    );
    $target_db = open_db($trigger_rewrite_source_table_target);
    $target_db->exec("INSERT INTO plugin_trigger_rewrite_source_table_items (item_id, label) VALUES ('trigger-rewrite-source-table', 'Trigger Rewrite Source Table')");
    $target_db->close();
    assert_same(
        scalar($trigger_rewrite_source_table_target, "SELECT label FROM plugin_trigger_rewrite_source_table_audit WHERE item_id = 'trigger-rewrite-source-table'"),
        'Trigger Rewrite Source Table:source-table',
        'source-changed trigger can query its source-added table dependency after merge'
    );

    $trigger_rewrite_source_view_base = $tmp . '/trigger-rewrite-source-view-base.sqlite';
    $trigger_rewrite_source_view_source = $tmp . '/trigger-rewrite-source-view-source.sqlite';
    $trigger_rewrite_source_view_target = $tmp . '/trigger-rewrite-source-view-target.sqlite';
    $trigger_rewrite_source_view_metadata = $tmp . '/.forkpress/cow/merge/schema-trigger-rewrite-source-view-metadata.sqlite';

    $db = open_db($trigger_rewrite_source_view_base);
    $db->exec('CREATE TABLE plugin_trigger_rewrite_source_view_items (item_id TEXT PRIMARY KEY, label TEXT NOT NULL)');
    $db->exec('CREATE TABLE plugin_trigger_rewrite_source_view_suffixes (suffix_key TEXT PRIMARY KEY, suffix TEXT NOT NULL)');
    $db->exec("INSERT INTO plugin_trigger_rewrite_source_view_suffixes (suffix_key, suffix) VALUES ('default', ':base-view')");
    $db->exec('CREATE TABLE plugin_trigger_rewrite_source_view_audit (item_id TEXT, label TEXT)');
    $db->exec('CREATE TRIGGER plugin_trigger_rewrite_source_view_items_after AFTER INSERT ON plugin_trigger_rewrite_source_view_items BEGIN INSERT INTO plugin_trigger_rewrite_source_view_audit (item_id, label) VALUES (NEW.item_id, NEW.label); END');
    $db->close();
    copy($trigger_rewrite_source_view_base, $trigger_rewrite_source_view_source);
    copy($trigger_rewrite_source_view_base, $trigger_rewrite_source_view_target);

    $source_db = open_db($trigger_rewrite_source_view_source);
    $source_db->exec("CREATE VIEW plugin_trigger_rewrite_source_view_suffix_view AS SELECT suffix FROM plugin_trigger_rewrite_source_view_suffixes WHERE suffix_key = 'default'");
    $source_db->exec('DROP TRIGGER plugin_trigger_rewrite_source_view_items_after');
    $source_db->exec("CREATE TRIGGER plugin_trigger_rewrite_source_view_items_after AFTER INSERT ON plugin_trigger_rewrite_source_view_items WHEN EXISTS (SELECT 1 FROM plugin_trigger_rewrite_source_view_suffix_view) BEGIN INSERT INTO plugin_trigger_rewrite_source_view_audit (item_id, label) SELECT NEW.item_id, NEW.label || suffix FROM plugin_trigger_rewrite_source_view_suffix_view; END");
    $source_db->close();

    $trigger_rewrite_source_view_result = cow_merge_databases(
        $trigger_rewrite_source_view_base,
        $trigger_rewrite_source_view_source,
        $trigger_rewrite_source_view_target,
        $trigger_rewrite_source_view_metadata,
        'feature-schema-trigger-rewrite-source-view',
        'main'
    );
    $trigger_rewrite_source_view_run_id = (int)$trigger_rewrite_source_view_result['run_id'];
    assert_same($trigger_rewrite_source_view_result['status'], 'completed', 'source-changed trigger depending on a source-added view merges automatically');
    assert_same(
        (int)scalar($trigger_rewrite_source_view_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_trigger_rewrite_source_view_suffix_view'"),
        1,
        'source-added view installs before source-changed trigger validation'
    );
    assert_same(
        (int)scalar($trigger_rewrite_source_view_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE run_id = $trigger_rewrite_source_view_run_id AND conflict_type IN ('schema-source-added-view', 'schema-source-changed-trigger')"),
        0,
        'source-changed trigger and source-added view dependency create no review-only schema conflicts'
    );
    assert_same(
        (int)scalar($trigger_rewrite_source_view_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE run_id = $trigger_rewrite_source_view_run_id AND column_name IN ('plugin_trigger_rewrite_source_view_suffix_view', 'plugin_trigger_rewrite_source_view_items_after') AND decision = 'source-applied'"),
        2,
        'source-added view and dependent changed trigger are auditable'
    );
    $target_db = open_db($trigger_rewrite_source_view_target);
    $target_db->exec("INSERT INTO plugin_trigger_rewrite_source_view_items (item_id, label) VALUES ('trigger-rewrite-source-view', 'Trigger Rewrite Source View')");
    $target_db->close();
    assert_same(
        scalar($trigger_rewrite_source_view_target, "SELECT label FROM plugin_trigger_rewrite_source_view_audit WHERE item_id = 'trigger-rewrite-source-view'"),
        'Trigger Rewrite Source View:base-view',
        'source-changed trigger can query its source-added view dependency after merge'
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

    $trigger_source_view_base = $tmp . '/trigger-source-view-base.sqlite';
    $trigger_source_view_source = $tmp . '/trigger-source-view-source.sqlite';
    $trigger_source_view_target = $tmp . '/trigger-source-view-target.sqlite';
    $trigger_source_view_metadata = $tmp . '/.forkpress/cow/merge/schema-trigger-source-view-metadata.sqlite';

    $db = open_db($trigger_source_view_base);
    $db->exec('CREATE TABLE plugin_trigger_source_view_items (item_id TEXT PRIMARY KEY, label TEXT NOT NULL)');
    $db->close();
    copy($trigger_source_view_base, $trigger_source_view_source);
    copy($trigger_source_view_base, $trigger_source_view_target);

    $source_db = open_db($trigger_source_view_source);
    $source_db->exec('CREATE TABLE plugin_trigger_source_view_audit (item_id TEXT, label TEXT)');
    $source_db->exec('CREATE TABLE plugin_trigger_source_view_suffixes (suffix_key TEXT PRIMARY KEY, suffix TEXT NOT NULL)');
    $source_db->exec("INSERT INTO plugin_trigger_source_view_suffixes (suffix_key, suffix) VALUES ('default', ':source-view')");
    $source_db->exec('CREATE TABLE plugin_trigger_source_view_gate (enabled INTEGER NOT NULL)');
    $source_db->exec('INSERT INTO plugin_trigger_source_view_gate (enabled) VALUES (1)');
    $source_db->exec("CREATE VIEW plugin_trigger_source_view_suffix_view AS SELECT suffix FROM plugin_trigger_source_view_suffixes WHERE suffix_key = 'default'");
    $source_db->exec('CREATE VIEW plugin_trigger_source_view_gate_view AS SELECT enabled FROM plugin_trigger_source_view_gate WHERE enabled = 1');
    $source_db->exec("CREATE TRIGGER plugin_trigger_source_view_items_after AFTER INSERT ON plugin_trigger_source_view_items WHEN EXISTS (SELECT 1 FROM plugin_trigger_source_view_gate_view) BEGIN INSERT INTO plugin_trigger_source_view_audit (item_id, label) SELECT NEW.item_id, NEW.label || suffix FROM plugin_trigger_source_view_suffix_view; END");
    $source_db->close();

    $trigger_source_view_result = cow_merge_databases(
        $trigger_source_view_base,
        $trigger_source_view_source,
        $trigger_source_view_target,
        $trigger_source_view_metadata,
        'feature-schema-trigger-source-view',
        'main'
    );
    $trigger_source_view_run_id = (int)$trigger_source_view_result['run_id'];
    assert_same($trigger_source_view_result['status'], 'completed', 'source-added triggers depending on source-added views merge automatically');
    assert_same(
        (int)scalar($trigger_source_view_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name IN ('plugin_trigger_source_view_suffix_view', 'plugin_trigger_source_view_gate_view')"),
        2,
        'source-added trigger body and WHEN dependency views install before trigger validation'
    );
    assert_same(
        (int)scalar($trigger_source_view_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_trigger_source_view_items_after'"),
        1,
        'source-added trigger depending on source-added views installs'
    );
    assert_same(
        (int)scalar($trigger_source_view_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE run_id = $trigger_source_view_run_id AND conflict_type IN ('schema-source-added-view', 'schema-source-added-trigger')"),
        0,
        'source-added trigger dependencies on source-added views create no review-only schema conflicts'
    );
    assert_same(
        (int)scalar($trigger_source_view_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE run_id = $trigger_source_view_run_id AND column_name IN ('plugin_trigger_source_view_suffix_view', 'plugin_trigger_source_view_gate_view', 'plugin_trigger_source_view_items_after') AND decision = 'source-applied'"),
        3,
        'source-added views and dependent trigger creation are auditable'
    );
    $target_db = open_db($trigger_source_view_target);
    $target_db->exec("INSERT INTO plugin_trigger_source_view_items (item_id, label) VALUES ('source-view-trigger', 'Source View Trigger')");
    $target_db->close();
    assert_same(
        scalar($trigger_source_view_target, "SELECT label FROM plugin_trigger_source_view_audit WHERE item_id = 'source-view-trigger'"),
        'Source View Trigger:source-view',
        'source-added trigger can use source-added body and WHEN dependency views after merge'
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
        'compatible-source-drift',
        'schema table restore source drift is classified compatible when the current restore validates'
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
    assert_same($table_restore_revalidate_conflicts[0]['revalidation_class'] ?? null, 'compatible-source-drift', 'schema table restore audit exposes compatible source-drift revalidation');
    assert_true($table_restore_revalidate_conflicts[0]['after_revalidate_supported'] ?? false, 'schema table restore advertises guarded after-revalidate support');
    $table_restore_revalidate_resolution = cow_merge_resolve_conflict(
        $table_restore_revalidate_metadata,
        $table_restore_revalidate_conflict_id,
        'source',
        true,
        'Apply current source table restore after compatible source drift.',
        'cow-test',
        true
    );
    assert_same($table_restore_revalidate_resolution['status'], 'applied', 'compatible schema table restore source drift resolves after revalidation');
    assert_same(
        scalar($table_restore_revalidate_target, "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'plugin_restore_revalidate_label_idx'"),
        null,
        'compatible schema table restore defers current source indexes that still have their own schema conflicts'
    );
    assert_same(
        scalar($table_restore_revalidate_target, 'SELECT label FROM plugin_restore_revalidate WHERE id = 1'),
        'Alpha',
        'compatible schema table restore restores source rows after revalidation'
    );

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
    $source_db->exec('CREATE INDEX plugin_rebuild_revalidate_value_idx ON plugin_rebuild_revalidate(value)');
    $source_db->exec('CREATE TRIGGER plugin_rebuild_revalidate_noop AFTER INSERT ON plugin_rebuild_revalidate BEGIN SELECT NEW.value; END');
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
    $source_db->exec('DROP INDEX plugin_rebuild_revalidate_value_idx');
    $source_db->exec('CREATE INDEX plugin_rebuild_revalidate_value_idx ON plugin_rebuild_revalidate(value + 0)');
    $source_db->exec('DROP TRIGGER plugin_rebuild_revalidate_noop');
    $source_db->exec('CREATE TRIGGER plugin_rebuild_revalidate_noop AFTER INSERT ON plugin_rebuild_revalidate BEGIN SELECT NEW.id; END');
    $source_db->close();

    $table_rebuild_revalidated = cow_merge_revalidate_reviewed_conflicts($table_rebuild_revalidate_metadata, $table_rebuild_revalidate_run_id, 'cow-revalidate');
    assert_same($table_rebuild_revalidated['checked'], 1, 'schema table rebuild revalidation checks the reviewed conflict');
    assert_same($table_rebuild_revalidated['reviewed'], 1, 'schema table rebuild revalidation sees the reviewed conflict');
    assert_same($table_rebuild_revalidated['stale'], 1, 'schema table rebuild revalidation detects changed source dependency SQL');
    assert_same($table_rebuild_revalidated['carried'], 1, 'schema table rebuild revalidation carries changed source evidence to needs-action');
    assert_same(
        scalar($table_rebuild_revalidate_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $table_rebuild_revalidate_conflict_id ORDER BY id DESC LIMIT 1"),
        'compatible-source-drift',
        'schema table rebuild source drift is classified compatible when the current source rebuild validates against target'
    );
    assert_true(
        str_contains((string)scalar($table_rebuild_revalidate_metadata, "SELECT stale_reason FROM merge_revalidations WHERE conflict_id = $table_rebuild_revalidate_conflict_id ORDER BY id DESC LIMIT 1"), 'source and target changed'),
        'schema table rebuild source drift explains that source rebuild evidence changed after review'
    );
    $table_rebuild_revalidate_source_sql = cow_merge_decode_payload_json(
        (string)scalar($table_rebuild_revalidate_metadata, "SELECT source_payload FROM merge_revalidations WHERE conflict_id = $table_rebuild_revalidate_conflict_id ORDER BY id DESC LIMIT 1"),
        'schema table rebuild revalidation source'
    );
    assert_true(
        str_contains((string)($table_rebuild_revalidate_source_sql['table_sql'] ?? ''), 'value INTEGER'),
        'schema table rebuild revalidation records the unchanged source table SQL'
    );
    assert_true(
        str_contains((string)json_encode($table_rebuild_revalidate_source_sql), 'value + 0') &&
            str_contains((string)json_encode($table_rebuild_revalidate_source_sql), 'SELECT NEW.id'),
        'schema table rebuild revalidation records changed source dependency SQL'
    );
    $table_rebuild_revalidate_audit = cow_merge_audit_report($table_rebuild_revalidate_metadata, $table_rebuild_revalidate_run_id, 10, [
        'records' => 'conflicts',
        'review_status' => 'needs-action',
    ]);
    $table_rebuild_revalidate_conflicts = array_values(array_filter($table_rebuild_revalidate_audit['conflicts'], fn($conflict) => (int)($conflict['id'] ?? 0) === $table_rebuild_revalidate_conflict_id));
    assert_same(count($table_rebuild_revalidate_conflicts), 1, 'schema table rebuild source drift returns the reviewed conflict to the needs-action audit queue');
    assert_same($table_rebuild_revalidate_conflicts[0]['revalidation_class'] ?? null, 'compatible-source-drift', 'schema table rebuild audit exposes compatible source-drift revalidation');
    $table_rebuild_revalidate_filtered = cow_merge_audit_report($table_rebuild_revalidate_metadata, $table_rebuild_revalidate_run_id, 10, [
        'records' => 'conflicts',
        'revalidation_class' => 'compatible-source-drift',
    ]);
    assert_same(count($table_rebuild_revalidate_filtered['conflicts']), 1, 'schema table rebuild audit filters compatible source drift conflicts by revalidation class');
    assert_same($table_rebuild_revalidate_filtered['conflicts'][0]['id'] ?? null, $table_rebuild_revalidate_conflict_id, 'schema table rebuild compatible source-drift filter returns the revalidated conflict');
    $table_rebuild_revalidate_resolution = cow_merge_resolve_conflict(
        $table_rebuild_revalidate_metadata,
        $table_rebuild_revalidate_conflict_id,
        'source',
        true,
        'Apply source table rebuild after compatible source drift revalidation.',
        'cow-test',
        true
    );
    assert_same($table_rebuild_revalidate_resolution['status'], 'applied', 'compatible schema table rebuild source drift resolves after revalidation');
    assert_true(
        str_contains((string)scalar($table_rebuild_revalidate_target, "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'plugin_rebuild_revalidate'"), 'value INTEGER'),
        'compatible schema table rebuild source drift applies the current source table SQL'
    );
    assert_true(
        str_contains((string)scalar($table_rebuild_revalidate_target, "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'plugin_rebuild_revalidate_value_idx'"), 'value + 0'),
        'compatible schema table rebuild source drift applies the current source index SQL'
    );
    assert_true(
        str_contains((string)scalar($table_rebuild_revalidate_target, "SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_rebuild_revalidate_noop'"), 'NEW.id'),
        'compatible schema table rebuild source drift applies the current source trigger SQL'
    );

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
        str_contains((string)($table_rebuild_target_drift_payload['table_sql'] ?? ''), 'value BLOB'),
        'schema table rebuild target drift records the current target table SQL'
    );
    assert_same(
        scalar($table_rebuild_target_drift_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $table_rebuild_target_drift_conflict_id ORDER BY id DESC LIMIT 1"),
        'compatible-schema-table-target-drift',
        'schema table rebuild target drift is classified compatible when source rebuild validates against current target'
    );
    $table_rebuild_target_drift_filtered = cow_merge_audit_report($table_rebuild_target_drift_metadata, $table_rebuild_target_drift_run_id, 10, [
        'records' => 'conflicts',
        'revalidation_class' => 'compatible-schema-table-target-drift',
    ]);
    assert_same(count($table_rebuild_target_drift_filtered['conflicts']), 1, 'schema table rebuild audit filters compatible target drift conflicts by revalidation class');
    assert_same($table_rebuild_target_drift_filtered['conflicts'][0]['id'] ?? null, $table_rebuild_target_drift_conflict_id, 'schema table rebuild revalidation-class filter returns the revalidated conflict');
    $table_rebuild_target_drift_cli_filter = run_merge_cli([
        'audit',
        '--metadata-db', $table_rebuild_target_drift_metadata,
        '--run', (string)$table_rebuild_target_drift_run_id,
        '--records', 'conflicts',
        '--revalidation-class', 'compatible-schema-table-target-drift',
        '--format', 'json',
    ]);
    assert_same($table_rebuild_target_drift_cli_filter['status'], 0, 'schema table rebuild audit CLI accepts compatible table target-drift revalidation-class');
    $table_rebuild_target_drift_cli_json = json_decode($table_rebuild_target_drift_cli_filter['output'], true);
    assert_same(count($table_rebuild_target_drift_cli_json['conflicts'] ?? []), 1, 'schema table rebuild audit CLI filters compatible table target-drift conflicts');
    assert_same($table_rebuild_target_drift_cli_json['filters']['revalidation_class'] ?? null, 'compatible-schema-table-target-drift', 'schema table rebuild audit CLI reports compatible table target-drift filter');
    $table_rebuild_target_drift_resolution = cow_merge_resolve_conflict(
        $table_rebuild_target_drift_metadata,
        $table_rebuild_target_drift_conflict_id,
        'source',
        true,
        'Apply source table rebuild after compatible target drift revalidation.',
        'cow-test',
        true
    );
    assert_same($table_rebuild_target_drift_resolution['status'], 'applied', 'compatible schema table rebuild target drift resolves after revalidation');
    assert_true(
        str_contains((string)scalar($table_rebuild_target_drift_target, "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'plugin_rebuild_target_drift'"), 'value INTEGER'),
        'compatible schema table rebuild target drift applies the audited source table SQL'
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

    $db = open_db($index_revalidate_target);
    $db->exec('DELETE FROM plugin_index_revalidate WHERE id = 2');
    $db->close();

    $index_revalidated = cow_merge_revalidate_reviewed_conflicts($index_revalidate_metadata, $index_revalidate_run_id, 'cow-revalidate');
    assert_same($index_revalidated['checked'], 1, 'schema index revalidation checks the reviewed index conflict');
    assert_same($index_revalidated['reviewed'], 1, 'schema index revalidation sees the reviewed index conflict');
    assert_same($index_revalidated['stale'], 1, 'schema index revalidation detects changed source index SQL');
    assert_same($index_revalidated['carried'], 1, 'schema index revalidation carries changed source index evidence to needs-action');
    assert_same(
        scalar($index_revalidate_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $index_revalidate_conflict_id ORDER BY id DESC LIMIT 1"),
        'compatible-source-drift',
        'schema index source drift is classified compatible when the current source index validates'
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
    assert_same($index_revalidate_conflicts[0]['revalidation_class'] ?? null, 'compatible-source-drift', 'schema index audit exposes compatible source-drift revalidation');
    assert_true($index_revalidate_conflicts[0]['after_revalidate_supported'] ?? false, 'schema index source drift advertises guarded after-revalidate support');
    $index_revalidate_resolution = cow_merge_resolve_conflict(
        $index_revalidate_metadata,
        $index_revalidate_conflict_id,
        'source',
        true,
        'Apply current source index after compatible source drift.',
        'cow-test',
        true
    );
    assert_same($index_revalidate_resolution['status'], 'applied', 'compatible schema index source drift resolves after revalidation');
    assert_true(
        str_contains((string)scalar($index_revalidate_target, "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'plugin_index_revalidate_label_idx'"), 'upper(label)'),
        'compatible schema index source drift applies the current source index SQL'
    );
} finally {
    remove_tree($tmp);
}

if ($fail) {
    echo "FAILURES: $fail\n";
    exit(1);
}
echo "COW schema review focused tests passed ($pass assertions).\n";

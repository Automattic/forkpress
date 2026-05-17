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

function create_keyless_stale_audit_db(string $path): void {
    $db = open_db($path);
    $db->exec('CREATE TABLE plugin_keyless (label TEXT, value TEXT)');
    $db->exec("INSERT INTO plugin_keyless (rowid, label, value) VALUES (1, 'Base keyless', 'base')");
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

    $filtered_revalidate = run_merge_cli([
        'audit',
        '--metadata-db', $metadata,
        '--revalidate',
        '--scope', 'db',
    ]);
    assert_true($filtered_revalidate['status'] !== 0, 'audit revalidate rejects ignored filters in direct PHP CLI');
    assert_true(
        str_contains($filtered_revalidate['output'], 'merge-audit --revalidate only accepts --run, --reviewer, --format, and --quiet'),
        'audit revalidate explains supported action flags'
    );
    assert_true(
        str_contains($filtered_revalidate['output'], 'Ignored filters: --scope'),
        'audit revalidate names the ignored filter'
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
    assert_same((int)$audit['conflicts'][0]['event_count'], 3, 'revalidated conflict appends a lifecycle event');
    assert_same($audit['conflicts'][0]['latest_event_type'], 'revalidation-required', 'revalidated conflict advertises latest lifecycle event');
    assert_same($audit['conflicts'][0]['latest_event_lifecycle_state'], 'needs-action', 'revalidated conflict advertises latest event state');
    assert_same($audit['conflicts'][0]['latest_event_actor'], 'cow-revalidate', 'revalidated conflict advertises latest event actor');
    $revalidation_id = (int)scalar($metadata, "SELECT id FROM merge_revalidations WHERE conflict_id = $conflict_id ORDER BY id DESC LIMIT 1");
    $event_audit = cow_merge_audit_report($metadata, $run_id, 3, [
        'records' => 'conflict-events',
    ]);
    assert_same(array_column($event_audit['conflict_events'], 'event_type'), ['revalidation-required', 'review-reviewed', 'recorded'], 'stale revalidation is visible in the conflict event stream');
    assert_same($event_audit['conflict_events'][0]['related_record_type'], 'revalidation', 'revalidation event links to the revalidation record');
    assert_same((int)$event_audit['conflict_events'][0]['related_record_id'], $revalidation_id, 'revalidation event exposes the revalidation id');
    assert_same($event_audit['conflict_events'][0]['lifecycle_state'], 'needs-action', 'revalidation event records the needs-action lifecycle state');
    assert_same($event_audit['conflict_events'][0]['actor'], 'cow-revalidate', 'revalidation event preserves the revalidation actor');

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
    $resolved_audit = cow_merge_audit_report($metadata, $run_id, 10, ['records' => 'conflicts']);
    assert_same((int)$resolved_audit['conflicts'][0]['event_count'], 4, 'after-revalidate resolution appends a lifecycle event');
    assert_same($resolved_audit['conflicts'][0]['latest_event_type'], 'resolution-applied', 'after-revalidate resolution advertises latest lifecycle event');
    assert_same($resolved_audit['conflicts'][0]['latest_event_lifecycle_state'], 'resolved', 'after-revalidate resolution advertises latest event state');

    $source_drift_base = $tmp . '/source-drift-base.sqlite';
    $source_drift_source = $tmp . '/source-drift-source.sqlite';
    $source_drift_target = $tmp . '/source-drift-target.sqlite';
    $source_drift_metadata = $tmp . '/.forkpress/cow/merge/source-drift-metadata.sqlite';

    create_stale_audit_db($source_drift_base);
    copy($source_drift_base, $source_drift_source);
    copy($source_drift_base, $source_drift_target);

    $source_db = open_db($source_drift_source);
    $source_db->exec("UPDATE plugin_items SET value = 'source drift original conflict' WHERE item_id = 'alpha'");
    $source_db->close();
    $target_db = open_db($source_drift_target);
    $target_db->exec("UPDATE plugin_items SET value = 'target source-drift conflict' WHERE item_id = 'alpha'");
    $target_db->close();

    $source_drift_merge = cow_merge_databases($source_drift_base, $source_drift_source, $source_drift_target, $source_drift_metadata, 'feature-source-drift-review', 'main');
    $source_drift_run_id = (int)$source_drift_merge['run_id'];
    $source_drift_conflict_id = (int)scalar($source_drift_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_items' AND column_name = 'value'");
    assert_true($source_drift_conflict_id > 0, 'source-drift fixture records a reviewed cell conflict');
    cow_merge_review_record(
        $source_drift_metadata,
        'conflict',
        $source_drift_conflict_id,
        'reviewed',
        'Apply source after confirming it still matches review.',
        'cow-test'
    );

    $source_db = open_db($source_drift_source);
    $source_db->exec("UPDATE plugin_items SET value = 'source drift after review' WHERE item_id = 'alpha'");
    $source_db->close();

    $source_drift_revalidated = cow_merge_revalidate_reviewed_conflicts($source_drift_metadata, $source_drift_run_id, 'cow-revalidate');
    assert_same($source_drift_revalidated['checked'], 1, 'source-drift revalidation checks the reviewed cell conflict');
    assert_same($source_drift_revalidated['stale'], 1, 'source-drift revalidation detects changed source payload');
    assert_same($source_drift_revalidated['carried'], 1, 'source-drift revalidation carries reviewed cell conflict to needs-action');
    assert_same(
        scalar($source_drift_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $source_drift_conflict_id ORDER BY id DESC LIMIT 1"),
        'compatible-source-drift',
        'cell revalidation classifies changed source payloads'
    );
    assert_same(
        scalar($source_drift_metadata, "SELECT source_payload FROM merge_revalidations WHERE conflict_id = $source_drift_conflict_id ORDER BY id DESC LIMIT 1"),
        cow_merge_payload_json('source drift after review'),
        'cell source-drift revalidation records the current source payload'
    );
    $source_drift_audit = cow_merge_audit_report($source_drift_metadata, $source_drift_run_id, 10, ['records' => 'conflicts']);
    $source_drift_conflicts = array_values(array_filter($source_drift_audit['conflicts'], fn($row) => (int)($row['id'] ?? 0) === $source_drift_conflict_id));
    assert_same($source_drift_conflicts[0]['revalidation_class'] ?? null, 'compatible-source-drift', 'cell audit exposes source-drift revalidation class');
    assert_throws(
        fn() => cow_merge_resolve_conflict($source_drift_metadata, $source_drift_conflict_id, 'source', true, 'Try source after source-drift revalidation.', 'cow-test', true),
        'source payload changed after latest merge revalidation',
        'after-revalidate cell resolution fails if the source payload changed after review'
    );

    $row_drift_base = $tmp . '/row-source-drift-base.sqlite';
    $row_drift_source = $tmp . '/row-source-drift-source.sqlite';
    $row_drift_target = $tmp . '/row-source-drift-target.sqlite';
    $row_drift_metadata = $tmp . '/.forkpress/cow/merge/row-source-drift-metadata.sqlite';

    create_stale_audit_db($row_drift_base);
    copy($row_drift_base, $row_drift_source);
    copy($row_drift_base, $row_drift_target);

    $source_db = open_db($row_drift_source);
    $source_db->exec("INSERT INTO plugin_items (item_id, label, value) VALUES ('source-drift-row', 'source row original label', 'source row original value')");
    $source_db->close();
    $target_db = open_db($row_drift_target);
    $target_db->exec("INSERT INTO plugin_items (item_id, label, value) VALUES ('source-drift-row', 'target row conflict label', 'target row conflict value')");
    $target_db->close();

    $row_drift_merge = cow_merge_databases($row_drift_base, $row_drift_source, $row_drift_target, $row_drift_metadata, 'feature-row-source-drift-review', 'main');
    $row_drift_run_id = (int)$row_drift_merge['run_id'];
    $row_identity = SQLite3::escapeString(cow_merge_identity_json(['item_id' => 'source-drift-row']));
    $row_drift_conflict_id = (int)scalar($row_drift_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_items' AND row_identity = '$row_identity'");
    assert_true($row_drift_conflict_id > 0, 'row source-drift fixture records a reviewed row conflict');
    cow_merge_review_record(
        $row_drift_metadata,
        'conflict',
        $row_drift_conflict_id,
        'reviewed',
        'Apply source row after confirming it still matches review.',
        'cow-test'
    );

    $source_db = open_db($row_drift_source);
    $source_db->exec("UPDATE plugin_items SET label = 'source row drifted label', value = 'source row drifted value' WHERE item_id = 'source-drift-row'");
    $source_db->close();

    $row_drift_revalidated = cow_merge_revalidate_reviewed_conflicts($row_drift_metadata, $row_drift_run_id, 'cow-revalidate');
    assert_same($row_drift_revalidated['checked'], 1, 'row source-drift revalidation checks the reviewed row conflict');
    assert_same($row_drift_revalidated['stale'], 1, 'row source-drift revalidation detects changed source row');
    assert_same($row_drift_revalidated['carried'], 1, 'row source-drift revalidation carries reviewed row conflict to needs-action');
    assert_same(
        scalar($row_drift_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $row_drift_conflict_id ORDER BY id DESC LIMIT 1"),
        'compatible-source-drift',
        'row revalidation classifies changed source payloads'
    );
    assert_same(
        scalar($row_drift_metadata, "SELECT source_payload FROM merge_revalidations WHERE conflict_id = $row_drift_conflict_id ORDER BY id DESC LIMIT 1"),
        cow_merge_payload_json(['item_id' => 'source-drift-row', 'label' => 'source row drifted label', 'value' => 'source row drifted value']),
        'row source-drift revalidation records the current source row payload'
    );
    $row_drift_audit = cow_merge_audit_report($row_drift_metadata, $row_drift_run_id, 10, ['records' => 'conflicts']);
    $row_drift_conflicts = array_values(array_filter($row_drift_audit['conflicts'], fn($row) => (int)($row['id'] ?? 0) === $row_drift_conflict_id));
    assert_same($row_drift_conflicts[0]['revalidation_class'] ?? null, 'compatible-source-drift', 'row audit exposes source-drift revalidation class');
    assert_throws(
        fn() => cow_merge_resolve_conflict($row_drift_metadata, $row_drift_conflict_id, 'source', true, 'Try source row after source-drift revalidation.', 'cow-test', true),
        'source payload changed after latest merge revalidation',
        'after-revalidate row resolution fails if the source row changed after review'
    );

    $row_missing_base = $tmp . '/row-missing-base.sqlite';
    $row_missing_source = $tmp . '/row-missing-source.sqlite';
    $row_missing_target = $tmp . '/row-missing-target.sqlite';
    $row_missing_metadata = $tmp . '/.forkpress/cow/merge/row-missing-metadata.sqlite';

    create_stale_audit_db($row_missing_base);
    copy($row_missing_base, $row_missing_source);
    copy($row_missing_base, $row_missing_target);

    $source_db = open_db($row_missing_source);
    $source_db->exec("INSERT INTO plugin_items (item_id, label, value) VALUES ('row-missing', 'Source row missing', 'source row missing')");
    $source_db->close();
    $target_db = open_db($row_missing_target);
    $target_db->exec("INSERT INTO plugin_items (item_id, label, value) VALUES ('row-missing', 'Target row missing', 'target row missing')");
    $target_db->close();

    $row_missing_merge = cow_merge_databases($row_missing_base, $row_missing_source, $row_missing_target, $row_missing_metadata, 'feature-row-missing-review', 'main');
    $row_missing_run_id = (int)$row_missing_merge['run_id'];
    $row_missing_conflict_id = (int)scalar($row_missing_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_items' AND conflict_type = 'row-insert-collision'");
    assert_true($row_missing_conflict_id > 0, 'row missing fixture records a reviewed row conflict');
    cow_merge_review_record(
        $row_missing_metadata,
        'conflict',
        $row_missing_conflict_id,
        'reviewed',
        'Revalidate before applying a row conflict whose target disappeared.',
        'cow-test'
    );

    $target_db = open_db($row_missing_target);
    $target_db->exec("DELETE FROM plugin_items WHERE item_id = 'row-missing'");
    $target_db->close();

    $row_missing_revalidated = cow_merge_revalidate_reviewed_conflicts($row_missing_metadata, $row_missing_run_id, 'cow-revalidate');
    assert_same($row_missing_revalidated['checked'], 1, 'row missing revalidation checks the reviewed row conflict');
    assert_same($row_missing_revalidated['stale'], 1, 'row missing revalidation detects the deleted target row');
    assert_same($row_missing_revalidated['carried'], 1, 'row missing revalidation carries missing target row conflicts to needs-action');
    assert_same(
        scalar($row_missing_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $row_missing_conflict_id ORDER BY id DESC LIMIT 1"),
        'missing',
        'row revalidation classifies deleted target rows as missing'
    );
    $row_missing_audit = cow_merge_audit_report($row_missing_metadata, $row_missing_run_id, 10, ['records' => 'conflicts']);
    $row_missing_conflicts = array_values(array_filter($row_missing_audit['conflicts'], fn($row) => (int)($row['id'] ?? 0) === $row_missing_conflict_id));
    assert_same($row_missing_conflicts[0]['revalidation_class'] ?? null, 'missing', 'row audit exposes missing target row revalidation class');
    $row_missing_resolution = cow_merge_resolve_conflict(
        $row_missing_metadata,
        $row_missing_conflict_id,
        'source',
        true,
        'Apply source after missing-row conflict revalidation.',
        'cow-test',
        true
    );
    assert_same($row_missing_resolution['status'], 'applied', 'after-revalidate source resolution can restore a reviewed missing row conflict');
    assert_same(
        scalar($row_missing_target, "SELECT label FROM plugin_items WHERE item_id = 'row-missing'"),
        'Source row missing',
        'after-revalidate row resolution restores the audited source row'
    );
    assert_same(
        scalar($row_missing_metadata, "SELECT previous_payload FROM merge_resolutions WHERE conflict_id = $row_missing_conflict_id ORDER BY id DESC LIMIT 1"),
        cow_merge_payload_json(null),
        'after-revalidate row resolution audits the missing revalidated target row'
    );

    $keyless_revalidate_base = $tmp . '/keyless-revalidate-base.sqlite';
    $keyless_revalidate_source = $tmp . '/keyless-revalidate-source.sqlite';
    $keyless_revalidate_target = $tmp . '/keyless-revalidate-target.sqlite';
    $keyless_revalidate_metadata = $tmp . '/.forkpress/cow/merge/keyless-revalidate-metadata.sqlite';

    create_keyless_stale_audit_db($keyless_revalidate_base);
    copy($keyless_revalidate_base, $keyless_revalidate_source);
    copy($keyless_revalidate_base, $keyless_revalidate_target);

    $db = open_db($keyless_revalidate_source);
    $db->exec("UPDATE plugin_keyless SET value = 'source keyless revalidate' WHERE rowid = 1");
    $db->close();
    $db = open_db($keyless_revalidate_target);
    $db->exec("UPDATE plugin_keyless SET value = 'target keyless revalidate' WHERE rowid = 1");
    $db->close();

    $keyless_revalidate_merge = cow_merge_databases(
        $keyless_revalidate_base,
        $keyless_revalidate_source,
        $keyless_revalidate_target,
        $keyless_revalidate_metadata,
        'feature-keyless-revalidate',
        'main'
    );
    $keyless_revalidate_run_id = (int)$keyless_revalidate_merge['run_id'];
    assert_same($keyless_revalidate_merge['status'], 'completed_with_conflicts', 'keyless stale-revalidation fixture starts with a cell conflict');
    $keyless_revalidate_conflict_id = (int)scalar($keyless_revalidate_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_keyless' AND column_name = 'value' AND conflict_type = 'cell-conflict' ORDER BY id DESC LIMIT 1");
    assert_true($keyless_revalidate_conflict_id > 0, 'keyless stale-revalidation fixture records a reviewed cell conflict');
    cow_merge_review_record(
        $keyless_revalidate_metadata,
        'conflict',
        $keyless_revalidate_conflict_id,
        'reviewed',
        'Review original keyless row before replacement.',
        'cow-test'
    );

    $db = open_db($keyless_revalidate_target);
    $db->exec('DELETE FROM plugin_keyless WHERE rowid = 1');
    $db->exec("INSERT INTO plugin_keyless (label, value) VALUES ('Replacement keyless', 'runtime replacement')");
    $db->close();
    assert_same(
        (int)scalar($keyless_revalidate_target, 'SELECT rowid FROM plugin_keyless'),
        1,
        'keyless stale-revalidation fixture reuses the reviewed rowid'
    );
    cow_merge_track_row_identity_events(
        $keyless_revalidate_target,
        $keyless_revalidate_metadata,
        'main',
        [
            ['id' => 1, 'table_name' => 'plugin_keyless', 'op' => 'delete', 'rowid' => 1, 'row' => ['label' => 'Base keyless', 'value' => 'target keyless revalidate']],
            ['id' => 2, 'table_name' => 'plugin_keyless', 'op' => 'insert', 'rowid' => 1, 'row' => ['label' => 'Replacement keyless', 'value' => 'runtime replacement']],
        ]
    );

    $keyless_revalidated = cow_merge_revalidate_reviewed_conflicts($keyless_revalidate_metadata, $keyless_revalidate_run_id, 'cow-revalidate');
    assert_same($keyless_revalidated['checked'], 1, 'keyless rowid replacement revalidation checks the reviewed conflict');
    assert_same($keyless_revalidated['stale'], 1, 'keyless rowid replacement revalidation detects stale target identity');
    assert_same($keyless_revalidated['carried'], 1, 'keyless rowid replacement carries stale review intent');
    assert_same(
        scalar($keyless_revalidate_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $keyless_revalidate_conflict_id ORDER BY id DESC LIMIT 1"),
        'incompatible',
        'keyless rowid replacement is classified as incompatible'
    );
    $keyless_revalidated_audit = cow_merge_audit_report($keyless_revalidate_metadata, $keyless_revalidate_run_id, 10, ['records' => 'conflicts']);
    $keyless_revalidated_conflicts = array_values(array_filter($keyless_revalidated_audit['conflicts'], fn($row) => (int)($row['id'] ?? 0) === $keyless_revalidate_conflict_id));
    assert_same($keyless_revalidated_conflicts[0]['revalidation_class'] ?? null, 'incompatible', 'keyless audit exposes incompatible rowid replacement');
    assert_throws(
        fn() => cow_merge_resolve_conflict($keyless_revalidate_metadata, $keyless_revalidate_conflict_id, 'source', true, 'Do not apply source over replacement keyless row.', 'cow-test', true),
        'target row no longer exists',
        'after-revalidate does not apply reviewed source over an incompatible keyless replacement'
    );

    $post_identity_base = $tmp . '/post-identity-base.sqlite';
    $post_identity_source = $tmp . '/post-identity-source.sqlite';
    $post_identity_target = $tmp . '/post-identity-target.sqlite';
    $post_identity_metadata = $tmp . '/.forkpress/cow/merge/post-identity-metadata.sqlite';
    foreach ([$post_identity_base, $post_identity_source, $post_identity_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_type TEXT, post_title TEXT, post_content TEXT)');
        $db->close();
    }
    $db = open_db($post_identity_source);
    $db->exec("INSERT INTO wp_posts (ID, post_type, post_title, post_content) VALUES (10, 'page', 'Source page', 'source page content')");
    $db->close();
    $db = open_db($post_identity_target);
    $db->exec("INSERT INTO wp_posts (ID, post_type, post_title, post_content) VALUES (10, 'page', 'Target page', 'target page content')");
    $db->close();

    $post_identity_merge = cow_merge_databases($post_identity_base, $post_identity_source, $post_identity_target, $post_identity_metadata, 'feature-post-identity-review', 'main');
    $post_identity_run_id = (int)$post_identity_merge['run_id'];
    assert_same($post_identity_merge['status'], 'completed_with_conflicts', 'post semantic identity fixture starts with a same-ID row conflict');
    $post_identity_conflict_id = (int)scalar($post_identity_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'wp_posts' AND conflict_type = 'row-insert-collision'");
    assert_true($post_identity_conflict_id > 0, 'post semantic identity fixture records the row conflict');
    cow_merge_review_record(
        $post_identity_metadata,
        'conflict',
        $post_identity_conflict_id,
        'reviewed',
        'Review source page before applying over target page.',
        'cow-test'
    );

    $db = open_db($post_identity_target);
    $db->exec("UPDATE wp_posts SET post_type = 'attachment', post_title = 'Target attachment', post_content = 'target attachment content' WHERE ID = 10");
    $db->close();

    $post_identity_revalidated = cow_merge_revalidate_reviewed_conflicts($post_identity_metadata, $post_identity_run_id, 'cow-revalidate');
    assert_same($post_identity_revalidated['checked'], 1, 'post semantic revalidation checks the reviewed row conflict');
    assert_same($post_identity_revalidated['stale'], 1, 'post semantic revalidation detects target semantic replacement');
    assert_same($post_identity_revalidated['carried'], 1, 'post semantic revalidation carries semantically replaced rows to needs-action');
    assert_same(
        scalar($post_identity_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $post_identity_conflict_id ORDER BY id DESC LIMIT 1"),
        'incompatible',
        'post semantic revalidation classifies changed post_type as incompatible'
    );
    $post_identity_audit = cow_merge_audit_report($post_identity_metadata, $post_identity_run_id, 10, ['records' => 'conflicts']);
    $post_identity_conflicts = array_values(array_filter($post_identity_audit['conflicts'], fn($row) => (int)($row['id'] ?? 0) === $post_identity_conflict_id));
    assert_same($post_identity_conflicts[0]['revalidation_class'] ?? null, 'incompatible', 'post semantic audit exposes incompatible replacement');
    assert_true(str_contains((string)($post_identity_conflicts[0]['stale_reason'] ?? ''), 'semantic identity'), 'post semantic stale reason explains semantic identity drift');
    assert_throws(
        fn() => cow_merge_resolve_conflict($post_identity_metadata, $post_identity_conflict_id, 'source', true, 'Do not apply source page over replacement attachment.', 'cow-test', true),
        'latest merge revalidation is incompatible',
        'after-revalidate blocks source resolution over an incompatible post semantic replacement'
    );

    $postmeta_identity_base = $tmp . '/postmeta-identity-base.sqlite';
    $postmeta_identity_source = $tmp . '/postmeta-identity-source.sqlite';
    $postmeta_identity_target = $tmp . '/postmeta-identity-target.sqlite';
    $postmeta_identity_metadata = $tmp . '/.forkpress/cow/merge/postmeta-identity-metadata.sqlite';
    foreach ([$postmeta_identity_base, $postmeta_identity_source, $postmeta_identity_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY, post_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)');
        $db->close();
    }
    $db = open_db($postmeta_identity_source);
    $db->exec("INSERT INTO wp_postmeta (meta_id, post_id, meta_key, meta_value) VALUES (20, 10, '_thumbnail_id', '101')");
    $db->close();
    $db = open_db($postmeta_identity_target);
    $db->exec("INSERT INTO wp_postmeta (meta_id, post_id, meta_key, meta_value) VALUES (20, 10, '_thumbnail_id', '202')");
    $db->close();

    $postmeta_identity_merge = cow_merge_databases($postmeta_identity_base, $postmeta_identity_source, $postmeta_identity_target, $postmeta_identity_metadata, 'feature-postmeta-identity-review', 'main');
    $postmeta_identity_run_id = (int)$postmeta_identity_merge['run_id'];
    assert_same($postmeta_identity_merge['status'], 'completed_with_conflicts', 'postmeta semantic identity fixture starts with a same-ID row conflict');
    $postmeta_identity_conflict_id = (int)scalar($postmeta_identity_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'wp_postmeta' AND conflict_type = 'row-insert-collision'");
    assert_true($postmeta_identity_conflict_id > 0, 'postmeta semantic identity fixture records the row conflict');
    cow_merge_review_record(
        $postmeta_identity_metadata,
        'conflict',
        $postmeta_identity_conflict_id,
        'reviewed',
        'Review source thumbnail metadata before applying over target metadata.',
        'cow-test'
    );

    $db = open_db($postmeta_identity_target);
    $db->exec("UPDATE wp_postmeta SET meta_key = '_wp_page_template', meta_value = 'templates/special.html' WHERE meta_id = 20");
    $db->close();

    $postmeta_identity_revalidated = cow_merge_revalidate_reviewed_conflicts($postmeta_identity_metadata, $postmeta_identity_run_id, 'cow-revalidate');
    assert_same($postmeta_identity_revalidated['checked'], 1, 'postmeta semantic revalidation checks the reviewed row conflict');
    assert_same($postmeta_identity_revalidated['stale'], 1, 'postmeta semantic revalidation detects target semantic replacement');
    assert_same($postmeta_identity_revalidated['carried'], 1, 'postmeta semantic revalidation carries semantically replaced metadata to needs-action');
    assert_same(
        scalar($postmeta_identity_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $postmeta_identity_conflict_id ORDER BY id DESC LIMIT 1"),
        'incompatible',
        'postmeta semantic revalidation classifies changed post_id/meta_key identity as incompatible'
    );
    $postmeta_identity_audit = cow_merge_audit_report($postmeta_identity_metadata, $postmeta_identity_run_id, 10, ['records' => 'conflicts']);
    $postmeta_identity_conflicts = array_values(array_filter($postmeta_identity_audit['conflicts'], fn($row) => (int)($row['id'] ?? 0) === $postmeta_identity_conflict_id));
    assert_same($postmeta_identity_conflicts[0]['revalidation_class'] ?? null, 'incompatible', 'postmeta semantic audit exposes incompatible replacement');
    assert_true(str_contains((string)($postmeta_identity_conflicts[0]['stale_reason'] ?? ''), 'semantic identity'), 'postmeta semantic stale reason explains semantic identity drift');
    assert_throws(
        fn() => cow_merge_resolve_conflict($postmeta_identity_metadata, $postmeta_identity_conflict_id, 'source', true, 'Do not apply source thumbnail metadata over replacement template metadata.', 'cow-test', true),
        'latest merge revalidation is incompatible',
        'after-revalidate blocks source resolution over an incompatible postmeta semantic replacement'
    );

    $option_identity_base = $tmp . '/option-identity-base.sqlite';
    $option_identity_source = $tmp . '/option-identity-source.sqlite';
    $option_identity_target = $tmp . '/option-identity-target.sqlite';
    $option_identity_metadata = $tmp . '/.forkpress/cow/merge/option-identity-metadata.sqlite';
    foreach ([$option_identity_base, $option_identity_source, $option_identity_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY, option_name TEXT NOT NULL, option_value TEXT NOT NULL)');
        $db->close();
    }
    $db = open_db($option_identity_source);
    $db->exec("INSERT INTO wp_options (option_id, option_name, option_value) VALUES (30, 'source_feature_flag', 'source enabled')");
    $db->close();
    $db = open_db($option_identity_target);
    $db->exec("INSERT INTO wp_options (option_id, option_name, option_value) VALUES (30, 'target_feature_flag', 'target enabled')");
    $db->close();

    $option_identity_merge = cow_merge_databases($option_identity_base, $option_identity_source, $option_identity_target, $option_identity_metadata, 'feature-option-identity-review', 'main');
    $option_identity_run_id = (int)$option_identity_merge['run_id'];
    assert_same($option_identity_merge['status'], 'completed_with_conflicts', 'option semantic identity fixture starts with a same-ID row conflict');
    $option_identity_conflict_id = (int)scalar($option_identity_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'wp_options' AND conflict_type = 'row-insert-collision'");
    assert_true($option_identity_conflict_id > 0, 'option semantic identity fixture records the row conflict');
    cow_merge_review_record(
        $option_identity_metadata,
        'conflict',
        $option_identity_conflict_id,
        'reviewed',
        'Review source option before applying over target option.',
        'cow-test'
    );

    $db = open_db($option_identity_target);
    $db->exec("UPDATE wp_options SET option_name = 'replacement_feature_flag', option_value = 'replacement enabled' WHERE option_id = 30");
    $db->close();

    $option_identity_revalidated = cow_merge_revalidate_reviewed_conflicts($option_identity_metadata, $option_identity_run_id, 'cow-revalidate');
    assert_same($option_identity_revalidated['checked'], 1, 'option semantic revalidation checks the reviewed row conflict');
    assert_same($option_identity_revalidated['stale'], 1, 'option semantic revalidation detects target semantic replacement');
    assert_same($option_identity_revalidated['carried'], 1, 'option semantic revalidation carries semantically replaced options to needs-action');
    assert_same(
        scalar($option_identity_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $option_identity_conflict_id ORDER BY id DESC LIMIT 1"),
        'incompatible',
        'option semantic revalidation classifies changed option_name identity as incompatible'
    );
    $option_identity_audit = cow_merge_audit_report($option_identity_metadata, $option_identity_run_id, 10, ['records' => 'conflicts']);
    $option_identity_conflicts = array_values(array_filter($option_identity_audit['conflicts'], fn($row) => (int)($row['id'] ?? 0) === $option_identity_conflict_id));
    assert_same($option_identity_conflicts[0]['revalidation_class'] ?? null, 'incompatible', 'option semantic audit exposes incompatible replacement');
    assert_true(str_contains((string)($option_identity_conflicts[0]['stale_reason'] ?? ''), 'semantic identity'), 'option semantic stale reason explains semantic identity drift');
    assert_throws(
        fn() => cow_merge_resolve_conflict($option_identity_metadata, $option_identity_conflict_id, 'source', true, 'Do not apply source option over replacement option.', 'cow-test', true),
        'latest merge revalidation is incompatible',
        'after-revalidate blocks source resolution over an incompatible option semantic replacement'
    );

    $term_identity_base = $tmp . '/term-identity-base.sqlite';
    $term_identity_source = $tmp . '/term-identity-source.sqlite';
    $term_identity_target = $tmp . '/term-identity-target.sqlite';
    $term_identity_metadata = $tmp . '/.forkpress/cow/merge/term-identity-metadata.sqlite';
    foreach ([$term_identity_base, $term_identity_source, $term_identity_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE wp_terms (term_id INTEGER PRIMARY KEY, name TEXT NOT NULL, slug TEXT NOT NULL)');
        $db->close();
    }
    $db = open_db($term_identity_source);
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES (40, 'Source topic', 'source-topic')");
    $db->close();
    $db = open_db($term_identity_target);
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES (40, 'Target topic', 'target-topic')");
    $db->close();

    $term_identity_merge = cow_merge_databases($term_identity_base, $term_identity_source, $term_identity_target, $term_identity_metadata, 'feature-term-identity-review', 'main');
    $term_identity_run_id = (int)$term_identity_merge['run_id'];
    assert_same($term_identity_merge['status'], 'completed_with_conflicts', 'term semantic identity fixture starts with a same-ID row conflict');
    $term_identity_conflict_id = (int)scalar($term_identity_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'wp_terms' AND conflict_type = 'row-insert-collision'");
    assert_true($term_identity_conflict_id > 0, 'term semantic identity fixture records the row conflict');
    cow_merge_review_record(
        $term_identity_metadata,
        'conflict',
        $term_identity_conflict_id,
        'reviewed',
        'Review source term before applying over target term.',
        'cow-test'
    );

    $db = open_db($term_identity_target);
    $db->exec("UPDATE wp_terms SET name = 'Replacement topic', slug = 'replacement-topic' WHERE term_id = 40");
    $db->close();

    $term_identity_revalidated = cow_merge_revalidate_reviewed_conflicts($term_identity_metadata, $term_identity_run_id, 'cow-revalidate');
    assert_same($term_identity_revalidated['checked'], 1, 'term semantic revalidation checks the reviewed row conflict');
    assert_same($term_identity_revalidated['stale'], 1, 'term semantic revalidation detects target semantic replacement');
    assert_same($term_identity_revalidated['carried'], 1, 'term semantic revalidation carries semantically replaced terms to needs-action');
    assert_same(
        scalar($term_identity_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $term_identity_conflict_id ORDER BY id DESC LIMIT 1"),
        'incompatible',
        'term semantic revalidation classifies changed slug identity as incompatible'
    );
    $term_identity_audit = cow_merge_audit_report($term_identity_metadata, $term_identity_run_id, 10, ['records' => 'conflicts']);
    $term_identity_conflicts = array_values(array_filter($term_identity_audit['conflicts'], fn($row) => (int)($row['id'] ?? 0) === $term_identity_conflict_id));
    assert_same($term_identity_conflicts[0]['revalidation_class'] ?? null, 'incompatible', 'term semantic audit exposes incompatible replacement');
    assert_true(str_contains((string)($term_identity_conflicts[0]['stale_reason'] ?? ''), 'semantic identity'), 'term semantic stale reason explains semantic identity drift');
    assert_throws(
        fn() => cow_merge_resolve_conflict($term_identity_metadata, $term_identity_conflict_id, 'source', true, 'Do not apply source term over replacement term.', 'cow-test', true),
        'latest merge revalidation is incompatible',
        'after-revalidate blocks source resolution over an incompatible term semantic replacement'
    );

    $user_identity_base = $tmp . '/user-identity-base.sqlite';
    $user_identity_source = $tmp . '/user-identity-source.sqlite';
    $user_identity_target = $tmp . '/user-identity-target.sqlite';
    $user_identity_metadata = $tmp . '/.forkpress/cow/merge/user-identity-metadata.sqlite';
    foreach ([$user_identity_base, $user_identity_source, $user_identity_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE wp_users (ID INTEGER PRIMARY KEY, user_login TEXT NOT NULL, display_name TEXT NOT NULL)');
        $db->close();
    }
    $db = open_db($user_identity_source);
    $db->exec("INSERT INTO wp_users (ID, user_login, display_name) VALUES (50, 'source_user', 'Source User')");
    $db->close();
    $db = open_db($user_identity_target);
    $db->exec("INSERT INTO wp_users (ID, user_login, display_name) VALUES (50, 'target_user', 'Target User')");
    $db->close();

    $user_identity_merge = cow_merge_databases($user_identity_base, $user_identity_source, $user_identity_target, $user_identity_metadata, 'feature-user-identity-review', 'main');
    $user_identity_run_id = (int)$user_identity_merge['run_id'];
    assert_same($user_identity_merge['status'], 'completed_with_conflicts', 'user semantic identity fixture starts with a same-ID row conflict');
    $user_identity_conflict_id = (int)scalar($user_identity_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'wp_users' AND conflict_type = 'row-insert-collision'");
    assert_true($user_identity_conflict_id > 0, 'user semantic identity fixture records the row conflict');
    cow_merge_review_record(
        $user_identity_metadata,
        'conflict',
        $user_identity_conflict_id,
        'reviewed',
        'Review source user before applying over target user.',
        'cow-test'
    );

    $db = open_db($user_identity_target);
    $db->exec("UPDATE wp_users SET user_login = 'replacement_user', display_name = 'Replacement User' WHERE ID = 50");
    $db->close();

    $user_identity_revalidated = cow_merge_revalidate_reviewed_conflicts($user_identity_metadata, $user_identity_run_id, 'cow-revalidate');
    assert_same($user_identity_revalidated['checked'], 1, 'user semantic revalidation checks the reviewed row conflict');
    assert_same($user_identity_revalidated['stale'], 1, 'user semantic revalidation detects target semantic replacement');
    assert_same($user_identity_revalidated['carried'], 1, 'user semantic revalidation carries semantically replaced users to needs-action');
    assert_same(
        scalar($user_identity_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $user_identity_conflict_id ORDER BY id DESC LIMIT 1"),
        'incompatible',
        'user semantic revalidation classifies changed user_login identity as incompatible'
    );
    $user_identity_audit = cow_merge_audit_report($user_identity_metadata, $user_identity_run_id, 10, ['records' => 'conflicts']);
    $user_identity_conflicts = array_values(array_filter($user_identity_audit['conflicts'], fn($row) => (int)($row['id'] ?? 0) === $user_identity_conflict_id));
    assert_same($user_identity_conflicts[0]['revalidation_class'] ?? null, 'incompatible', 'user semantic audit exposes incompatible replacement');
    assert_true(str_contains((string)($user_identity_conflicts[0]['stale_reason'] ?? ''), 'semantic identity'), 'user semantic stale reason explains semantic identity drift');
    assert_throws(
        fn() => cow_merge_resolve_conflict($user_identity_metadata, $user_identity_conflict_id, 'source', true, 'Do not apply source user over replacement user.', 'cow-test', true),
        'latest merge revalidation is incompatible',
        'after-revalidate blocks source resolution over an incompatible user semantic replacement'
    );

    $extra_semantic_identity_cases = [
        'term-taxonomy' => [
            'table' => 'wp_term_taxonomy',
            'create' => 'CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER, taxonomy TEXT, description TEXT)',
            'source_insert' => "INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description) VALUES (60, 40, 'category', 'source taxonomy')",
            'target_insert' => "INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description) VALUES (60, 40, 'post_tag', 'target taxonomy')",
            'target_update' => "UPDATE wp_term_taxonomy SET term_id = 41, taxonomy = 'nav_menu', description = 'replacement taxonomy' WHERE term_taxonomy_id = 60",
            'label' => 'term taxonomy semantic identity',
        ],
        'termmeta' => [
            'table' => 'wp_termmeta',
            'create' => 'CREATE TABLE wp_termmeta (meta_id INTEGER PRIMARY KEY, term_id INTEGER, meta_key TEXT, meta_value TEXT)',
            'source_insert' => "INSERT INTO wp_termmeta (meta_id, term_id, meta_key, meta_value) VALUES (70, 40, 'source_key', 'source value')",
            'target_insert' => "INSERT INTO wp_termmeta (meta_id, term_id, meta_key, meta_value) VALUES (70, 40, 'target_key', 'target value')",
            'target_update' => "UPDATE wp_termmeta SET term_id = 41, meta_key = 'replacement_key', meta_value = 'replacement value' WHERE meta_id = 70",
            'label' => 'termmeta semantic identity',
        ],
        'usermeta' => [
            'table' => 'wp_usermeta',
            'create' => 'CREATE TABLE wp_usermeta (umeta_id INTEGER PRIMARY KEY, user_id INTEGER, meta_key TEXT, meta_value TEXT)',
            'source_insert' => "INSERT INTO wp_usermeta (umeta_id, user_id, meta_key, meta_value) VALUES (80, 50, 'source_key', 'source value')",
            'target_insert' => "INSERT INTO wp_usermeta (umeta_id, user_id, meta_key, meta_value) VALUES (80, 50, 'target_key', 'target value')",
            'target_update' => "UPDATE wp_usermeta SET user_id = 51, meta_key = 'replacement_key', meta_value = 'replacement value' WHERE umeta_id = 80",
            'label' => 'usermeta semantic identity',
        ],
        'comment' => [
            'table' => 'wp_comments',
            'create' => 'CREATE TABLE wp_comments (comment_ID INTEGER PRIMARY KEY, comment_post_ID INTEGER, comment_type TEXT, comment_content TEXT)',
            'source_insert' => "INSERT INTO wp_comments (comment_ID, comment_post_ID, comment_type, comment_content) VALUES (90, 10, 'comment', 'source comment')",
            'target_insert' => "INSERT INTO wp_comments (comment_ID, comment_post_ID, comment_type, comment_content) VALUES (90, 10, 'review', 'target comment')",
            'target_update' => "UPDATE wp_comments SET comment_post_ID = 11, comment_type = 'pingback', comment_content = 'replacement comment' WHERE comment_ID = 90",
            'label' => 'comment semantic identity',
        ],
        'commentmeta' => [
            'table' => 'wp_commentmeta',
            'create' => 'CREATE TABLE wp_commentmeta (meta_id INTEGER PRIMARY KEY, comment_id INTEGER, meta_key TEXT, meta_value TEXT)',
            'source_insert' => "INSERT INTO wp_commentmeta (meta_id, comment_id, meta_key, meta_value) VALUES (100, 90, 'source_key', 'source value')",
            'target_insert' => "INSERT INTO wp_commentmeta (meta_id, comment_id, meta_key, meta_value) VALUES (100, 90, 'target_key', 'target value')",
            'target_update' => "UPDATE wp_commentmeta SET comment_id = 91, meta_key = 'replacement_key', meta_value = 'replacement value' WHERE meta_id = 100",
            'label' => 'commentmeta semantic identity',
        ],
    ];
    foreach ($extra_semantic_identity_cases as $case_name => $case) {
        $case_base = $tmp . "/extra-$case_name-identity-base.sqlite";
        $case_source = $tmp . "/extra-$case_name-identity-source.sqlite";
        $case_target = $tmp . "/extra-$case_name-identity-target.sqlite";
        $case_metadata = $tmp . "/.forkpress/cow/merge/extra-$case_name-identity-metadata.sqlite";
        foreach ([$case_base, $case_source, $case_target] as $path) {
            $db = open_db($path);
            $db->exec($case['create']);
            $db->close();
        }
        $db = open_db($case_source);
        $db->exec($case['source_insert']);
        $db->close();
        $db = open_db($case_target);
        $db->exec($case['target_insert']);
        $db->close();

        $case_merge = cow_merge_databases($case_base, $case_source, $case_target, $case_metadata, "feature-$case_name-identity-review", 'main');
        $case_run_id = (int)$case_merge['run_id'];
        assert_same($case_merge['status'], 'completed_with_conflicts', $case['label'] . ' fixture starts with a same-ID row conflict');
        $case_conflict_id = (int)scalar($case_metadata, "SELECT id FROM merge_conflicts WHERE table_name = '{$case['table']}' AND conflict_type = 'row-insert-collision'");
        assert_true($case_conflict_id > 0, $case['label'] . ' fixture records the row conflict');
        cow_merge_review_record(
            $case_metadata,
            'conflict',
            $case_conflict_id,
            'reviewed',
            'Review source row before applying over target semantic identity.',
            'cow-test'
        );

        $db = open_db($case_target);
        $db->exec($case['target_update']);
        $db->close();

        $case_revalidated = cow_merge_revalidate_reviewed_conflicts($case_metadata, $case_run_id, 'cow-revalidate');
        assert_same($case_revalidated['checked'], 1, $case['label'] . ' revalidation checks the reviewed row conflict');
        assert_same($case_revalidated['stale'], 1, $case['label'] . ' revalidation detects target semantic replacement');
        assert_same($case_revalidated['carried'], 1, $case['label'] . ' revalidation carries semantic replacement to needs-action');
        assert_same(
            scalar($case_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $case_conflict_id ORDER BY id DESC LIMIT 1"),
            'incompatible',
            $case['label'] . ' revalidation classifies changed semantic identity as incompatible'
        );
        $case_audit = cow_merge_audit_report($case_metadata, $case_run_id, 10, ['records' => 'conflicts']);
        $case_conflicts = array_values(array_filter($case_audit['conflicts'], fn($row) => (int)($row['id'] ?? 0) === $case_conflict_id));
        assert_same($case_conflicts[0]['revalidation_class'] ?? null, 'incompatible', $case['label'] . ' audit exposes incompatible replacement');
        assert_true(str_contains((string)($case_conflicts[0]['stale_reason'] ?? ''), 'semantic identity'), $case['label'] . ' stale reason explains semantic identity drift');
        assert_throws(
            fn() => cow_merge_resolve_conflict($case_metadata, $case_conflict_id, 'source', true, 'Do not apply source row over replacement semantic identity.', 'cow-test', true),
            'latest merge revalidation is incompatible',
            $case['label'] . ' after-revalidate blocks source resolution over incompatible replacement'
        );
    }

    $custom_unique_target_base = $tmp . '/custom-unique-target-base.sqlite';
    $custom_unique_target_source = $tmp . '/custom-unique-target-source.sqlite';
    $custom_unique_target_target = $tmp . '/custom-unique-target-target.sqlite';
    $custom_unique_target_metadata = $tmp . '/.forkpress/cow/merge/custom-unique-target-metadata.sqlite';
    foreach ([$custom_unique_target_base, $custom_unique_target_source, $custom_unique_target_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_unique_objects (object_id INTEGER PRIMARY KEY, object_key TEXT NOT NULL UNIQUE, label TEXT NOT NULL)');
        $db->close();
    }
    $db = open_db($custom_unique_target_source);
    $db->exec("INSERT INTO plugin_unique_objects (object_id, object_key, label) VALUES (200, 'source-object', 'Source object')");
    $db->close();
    $db = open_db($custom_unique_target_target);
    $db->exec("INSERT INTO plugin_unique_objects (object_id, object_key, label) VALUES (200, 'target-object', 'Target object')");
    $db->close();

    $custom_unique_target_merge = cow_merge_databases($custom_unique_target_base, $custom_unique_target_source, $custom_unique_target_target, $custom_unique_target_metadata, 'feature-custom-unique-target-review', 'main');
    $custom_unique_target_run_id = (int)$custom_unique_target_merge['run_id'];
    assert_same($custom_unique_target_merge['status'], 'completed_with_conflicts', 'custom unique-key target identity fixture starts with a same-ID row conflict');
    $custom_unique_target_conflict_id = (int)scalar($custom_unique_target_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_unique_objects' AND conflict_type = 'row-insert-collision'");
    assert_true($custom_unique_target_conflict_id > 0, 'custom unique-key target identity fixture records the row conflict');
    cow_merge_review_record(
        $custom_unique_target_metadata,
        'conflict',
        $custom_unique_target_conflict_id,
        'reviewed',
        'Review source plugin object before applying over target plugin object.',
        'cow-test'
    );

    $db = open_db($custom_unique_target_target);
    $db->exec("UPDATE plugin_unique_objects SET object_key = 'replacement-target-object', label = 'Replacement target object' WHERE object_id = 200");
    $db->close();

    $custom_unique_target_revalidated = cow_merge_revalidate_reviewed_conflicts($custom_unique_target_metadata, $custom_unique_target_run_id, 'cow-revalidate');
    assert_same($custom_unique_target_revalidated['checked'], 1, 'custom unique-key target revalidation checks the reviewed row conflict');
    assert_same($custom_unique_target_revalidated['stale'], 1, 'custom unique-key target revalidation detects target logical replacement');
    assert_same($custom_unique_target_revalidated['carried'], 1, 'custom unique-key target revalidation carries logical replacement to needs-action');
    assert_same(
        scalar($custom_unique_target_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $custom_unique_target_conflict_id ORDER BY id DESC LIMIT 1"),
        'incompatible',
        'custom unique-key target revalidation classifies changed logical identity as incompatible'
    );
    $custom_unique_target_audit = cow_merge_audit_report($custom_unique_target_metadata, $custom_unique_target_run_id, 10, ['records' => 'conflicts']);
    $custom_unique_target_conflicts = array_values(array_filter($custom_unique_target_audit['conflicts'], fn($row) => (int)($row['id'] ?? 0) === $custom_unique_target_conflict_id));
    assert_same($custom_unique_target_conflicts[0]['revalidation_class'] ?? null, 'incompatible', 'custom unique-key target audit exposes incompatible replacement');
    assert_true(str_contains((string)($custom_unique_target_conflicts[0]['stale_reason'] ?? ''), 'semantic identity'), 'custom unique-key target stale reason explains logical identity drift');
    assert_throws(
        fn() => cow_merge_resolve_conflict($custom_unique_target_metadata, $custom_unique_target_conflict_id, 'source', true, 'Do not apply source over replacement plugin object.', 'cow-test', true),
        'latest merge revalidation is incompatible',
        'custom unique-key target after-revalidate blocks source resolution over incompatible replacement'
    );

    $custom_unique_source_base = $tmp . '/custom-unique-source-base.sqlite';
    $custom_unique_source_source = $tmp . '/custom-unique-source-source.sqlite';
    $custom_unique_source_target = $tmp . '/custom-unique-source-target.sqlite';
    $custom_unique_source_metadata = $tmp . '/.forkpress/cow/merge/custom-unique-source-metadata.sqlite';
    foreach ([$custom_unique_source_base, $custom_unique_source_source, $custom_unique_source_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_unique_objects (object_id INTEGER PRIMARY KEY, object_key TEXT NOT NULL UNIQUE, label TEXT NOT NULL)');
        $db->close();
    }
    $db = open_db($custom_unique_source_source);
    $db->exec("INSERT INTO plugin_unique_objects (object_id, object_key, label) VALUES (201, 'source-object-before-review', 'Source object before review')");
    $db->close();
    $db = open_db($custom_unique_source_target);
    $db->exec("INSERT INTO plugin_unique_objects (object_id, object_key, label) VALUES (201, 'target-object-before-review', 'Target object before review')");
    $db->close();

    $custom_unique_source_merge = cow_merge_databases($custom_unique_source_base, $custom_unique_source_source, $custom_unique_source_target, $custom_unique_source_metadata, 'feature-custom-unique-source-review', 'main');
    $custom_unique_source_run_id = (int)$custom_unique_source_merge['run_id'];
    assert_same($custom_unique_source_merge['status'], 'completed_with_conflicts', 'custom unique-key source identity fixture starts with a same-ID row conflict');
    $custom_unique_source_conflict_id = (int)scalar($custom_unique_source_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_unique_objects' AND conflict_type = 'row-insert-collision'");
    assert_true($custom_unique_source_conflict_id > 0, 'custom unique-key source identity fixture records the row conflict');
    cow_merge_review_record(
        $custom_unique_source_metadata,
        'conflict',
        $custom_unique_source_conflict_id,
        'reviewed',
        'Apply source plugin object if it is still the reviewed object.',
        'cow-test'
    );

    $db = open_db($custom_unique_source_source);
    $db->exec("UPDATE plugin_unique_objects SET object_key = 'replacement-source-object', label = 'Replacement source object' WHERE object_id = 201");
    $db->close();

    $custom_unique_source_revalidated = cow_merge_revalidate_reviewed_conflicts($custom_unique_source_metadata, $custom_unique_source_run_id, 'cow-revalidate');
    assert_same($custom_unique_source_revalidated['checked'], 1, 'custom unique-key source revalidation checks the reviewed row conflict');
    assert_same($custom_unique_source_revalidated['stale'], 1, 'custom unique-key source revalidation detects source logical replacement');
    assert_same($custom_unique_source_revalidated['carried'], 1, 'custom unique-key source revalidation carries logical replacement to needs-action');
    assert_same(
        scalar($custom_unique_source_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $custom_unique_source_conflict_id ORDER BY id DESC LIMIT 1"),
        'incompatible',
        'custom unique-key source revalidation classifies changed logical identity as incompatible'
    );
    $custom_unique_source_audit = cow_merge_audit_report($custom_unique_source_metadata, $custom_unique_source_run_id, 10, ['records' => 'conflicts']);
    $custom_unique_source_conflicts = array_values(array_filter($custom_unique_source_audit['conflicts'], fn($row) => (int)($row['id'] ?? 0) === $custom_unique_source_conflict_id));
    assert_same($custom_unique_source_conflicts[0]['revalidation_class'] ?? null, 'incompatible', 'custom unique-key source audit exposes incompatible replacement');
    assert_true(str_contains((string)($custom_unique_source_conflicts[0]['stale_reason'] ?? ''), 'semantic identity'), 'custom unique-key source stale reason explains logical identity drift');
    assert_throws(
        fn() => cow_merge_resolve_conflict($custom_unique_source_metadata, $custom_unique_source_conflict_id, 'source', true, 'Do not apply replacement source plugin object.', 'cow-test', true),
        'latest merge revalidation is incompatible',
        'custom unique-key source after-revalidate blocks source resolution over incompatible replacement'
    );

    $custom_unique_cell_target_base = $tmp . '/custom-unique-cell-target-base.sqlite';
    $custom_unique_cell_target_source = $tmp . '/custom-unique-cell-target-source.sqlite';
    $custom_unique_cell_target_target = $tmp . '/custom-unique-cell-target-target.sqlite';
    $custom_unique_cell_target_metadata = $tmp . '/.forkpress/cow/merge/custom-unique-cell-target-metadata.sqlite';
    foreach ([$custom_unique_cell_target_base, $custom_unique_cell_target_source, $custom_unique_cell_target_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_unique_cells (object_id INTEGER PRIMARY KEY, object_key TEXT NOT NULL UNIQUE, value TEXT NOT NULL)');
        $db->exec("INSERT INTO plugin_unique_cells (object_id, object_key, value) VALUES (300, 'target-cell-object-before-review', 'base value')");
        $db->close();
    }
    $db = open_db($custom_unique_cell_target_source);
    $db->exec("UPDATE plugin_unique_cells SET value = 'source reviewed value' WHERE object_id = 300");
    $db->close();
    $db = open_db($custom_unique_cell_target_target);
    $db->exec("UPDATE plugin_unique_cells SET value = 'target reviewed value' WHERE object_id = 300");
    $db->close();

    $custom_unique_cell_target_merge = cow_merge_databases($custom_unique_cell_target_base, $custom_unique_cell_target_source, $custom_unique_cell_target_target, $custom_unique_cell_target_metadata, 'feature-custom-unique-cell-target-review', 'main');
    $custom_unique_cell_target_run_id = (int)$custom_unique_cell_target_merge['run_id'];
    assert_same($custom_unique_cell_target_merge['status'], 'completed_with_conflicts', 'custom unique-key target cell fixture starts with a same-cell conflict');
    $custom_unique_cell_target_conflict_id = (int)scalar($custom_unique_cell_target_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_unique_cells' AND column_name = 'value' AND conflict_type = 'cell-conflict'");
    assert_true($custom_unique_cell_target_conflict_id > 0, 'custom unique-key target cell fixture records the cell conflict');
    assert_true(
        (string)scalar($custom_unique_cell_target_metadata, "SELECT target_row_payload FROM merge_conflicts WHERE id = $custom_unique_cell_target_conflict_id") !== '',
        'custom unique-key target cell fixture records audited target row context'
    );
    cow_merge_review_record(
        $custom_unique_cell_target_metadata,
        'conflict',
        $custom_unique_cell_target_conflict_id,
        'reviewed',
        'Apply source cell only if the target row is still the reviewed plugin object.',
        'cow-test'
    );

    $db = open_db($custom_unique_cell_target_target);
    $db->exec("UPDATE plugin_unique_cells SET object_key = 'replacement-target-cell-object' WHERE object_id = 300");
    $db->close();

    $custom_unique_cell_target_revalidated = cow_merge_revalidate_reviewed_conflicts($custom_unique_cell_target_metadata, $custom_unique_cell_target_run_id, 'cow-revalidate');
    assert_same($custom_unique_cell_target_revalidated['checked'], 1, 'custom unique-key target cell revalidation checks the reviewed cell conflict');
    assert_same($custom_unique_cell_target_revalidated['stale'], 1, 'custom unique-key target cell revalidation detects logical replacement even when the cell value is unchanged');
    assert_same($custom_unique_cell_target_revalidated['carried'], 1, 'custom unique-key target cell revalidation carries logical replacement to needs-action');
    assert_same(
        scalar($custom_unique_cell_target_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $custom_unique_cell_target_conflict_id ORDER BY id DESC LIMIT 1"),
        'incompatible',
        'custom unique-key target cell revalidation classifies logical replacement as incompatible'
    );
    $custom_unique_cell_target_payload = cow_merge_decode_payload_json(
        (string)scalar($custom_unique_cell_target_metadata, "SELECT target_payload FROM merge_revalidations WHERE conflict_id = $custom_unique_cell_target_conflict_id ORDER BY id DESC LIMIT 1"),
        'custom unique-key target cell revalidation payload'
    );
    assert_same($custom_unique_cell_target_payload['object_key'] ?? null, 'replacement-target-cell-object', 'custom unique-key target cell revalidation records the current target row context');
    assert_throws(
        fn() => cow_merge_resolve_conflict($custom_unique_cell_target_metadata, $custom_unique_cell_target_conflict_id, 'source', true, 'Do not apply source cell over replacement plugin object.', 'cow-test', true),
        'latest merge revalidation is incompatible',
        'custom unique-key target cell after-revalidate blocks source resolution over incompatible replacement'
    );

    $custom_unique_cell_source_base = $tmp . '/custom-unique-cell-source-base.sqlite';
    $custom_unique_cell_source_source = $tmp . '/custom-unique-cell-source-source.sqlite';
    $custom_unique_cell_source_target = $tmp . '/custom-unique-cell-source-target.sqlite';
    $custom_unique_cell_source_metadata = $tmp . '/.forkpress/cow/merge/custom-unique-cell-source-metadata.sqlite';
    foreach ([$custom_unique_cell_source_base, $custom_unique_cell_source_source, $custom_unique_cell_source_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_unique_cells (object_id INTEGER PRIMARY KEY, object_key TEXT NOT NULL UNIQUE, value TEXT NOT NULL)');
        $db->exec("INSERT INTO plugin_unique_cells (object_id, object_key, value) VALUES (301, 'source-cell-object-before-review', 'base value')");
        $db->close();
    }
    $db = open_db($custom_unique_cell_source_source);
    $db->exec("UPDATE plugin_unique_cells SET value = 'source reviewed value' WHERE object_id = 301");
    $db->close();
    $db = open_db($custom_unique_cell_source_target);
    $db->exec("UPDATE plugin_unique_cells SET value = 'target reviewed value' WHERE object_id = 301");
    $db->close();

    $custom_unique_cell_source_merge = cow_merge_databases($custom_unique_cell_source_base, $custom_unique_cell_source_source, $custom_unique_cell_source_target, $custom_unique_cell_source_metadata, 'feature-custom-unique-cell-source-review', 'main');
    $custom_unique_cell_source_run_id = (int)$custom_unique_cell_source_merge['run_id'];
    assert_same($custom_unique_cell_source_merge['status'], 'completed_with_conflicts', 'custom unique-key source cell fixture starts with a same-cell conflict');
    $custom_unique_cell_source_conflict_id = (int)scalar($custom_unique_cell_source_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_unique_cells' AND column_name = 'value' AND conflict_type = 'cell-conflict'");
    assert_true($custom_unique_cell_source_conflict_id > 0, 'custom unique-key source cell fixture records the cell conflict');
    assert_true(
        (string)scalar($custom_unique_cell_source_metadata, "SELECT source_row_payload FROM merge_conflicts WHERE id = $custom_unique_cell_source_conflict_id") !== '',
        'custom unique-key source cell fixture records audited source row context'
    );
    cow_merge_review_record(
        $custom_unique_cell_source_metadata,
        'conflict',
        $custom_unique_cell_source_conflict_id,
        'reviewed',
        'Apply source cell only if the source row is still the reviewed plugin object.',
        'cow-test'
    );

    $db = open_db($custom_unique_cell_source_source);
    $db->exec("UPDATE plugin_unique_cells SET object_key = 'replacement-source-cell-object' WHERE object_id = 301");
    $db->close();

    $custom_unique_cell_source_revalidated = cow_merge_revalidate_reviewed_conflicts($custom_unique_cell_source_metadata, $custom_unique_cell_source_run_id, 'cow-revalidate');
    assert_same($custom_unique_cell_source_revalidated['checked'], 1, 'custom unique-key source cell revalidation checks the reviewed cell conflict');
    assert_same($custom_unique_cell_source_revalidated['stale'], 1, 'custom unique-key source cell revalidation detects logical replacement even when the source cell value is unchanged');
    assert_same($custom_unique_cell_source_revalidated['carried'], 1, 'custom unique-key source cell revalidation carries logical replacement to needs-action');
    assert_same(
        scalar($custom_unique_cell_source_metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $custom_unique_cell_source_conflict_id ORDER BY id DESC LIMIT 1"),
        'incompatible',
        'custom unique-key source cell revalidation classifies logical replacement as incompatible'
    );
    $custom_unique_cell_source_payload = cow_merge_decode_payload_json(
        (string)scalar($custom_unique_cell_source_metadata, "SELECT source_payload FROM merge_revalidations WHERE conflict_id = $custom_unique_cell_source_conflict_id ORDER BY id DESC LIMIT 1"),
        'custom unique-key source cell revalidation payload'
    );
    assert_same($custom_unique_cell_source_payload['object_key'] ?? null, 'replacement-source-cell-object', 'custom unique-key source cell revalidation records the current source row context');
    assert_throws(
        fn() => cow_merge_resolve_conflict($custom_unique_cell_source_metadata, $custom_unique_cell_source_conflict_id, 'source', true, 'Do not apply replacement source cell.', 'cow-test', true),
        'latest merge revalidation is incompatible',
        'custom unique-key source cell after-revalidate blocks source resolution over incompatible replacement'
    );
} finally {
    remove_tree($tmp);
}

if ($fail) {
    echo "FAILURES: $fail\n";
    exit(1);
}
echo "COW stale audit focused tests passed ($pass assertions).\n";

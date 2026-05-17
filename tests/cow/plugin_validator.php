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

function run_merge_cli(array $args): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../scripts/cow/merge.php');
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg((string)$arg);
    }
    $output = [];
    $status = 0;
    exec($cmd . ' 2>&1', $output, $status);
    return [
        'status' => $status,
        'output' => implode("\n", $output),
    ];
}

function create_plugin_validator_db(string $path): void {
    $db = open_db($path);
    $db->exec('CREATE TABLE plugin_graph_parent (parent_id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT NOT NULL)');
    $db->exec('CREATE TABLE plugin_graph_child (child_id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER NOT NULL, graph_json TEXT NOT NULL, file_path TEXT NOT NULL)');
    $db->close();
}

function create_plugin_serialized_validator_db(string $path): void {
    $db = open_db($path);
    $db->exec('CREATE TABLE plugin_asset (asset_id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT NOT NULL, file_path TEXT NOT NULL)');
    $db->exec("CREATE TABLE wp_posts (
        ID INTEGER PRIMARY KEY AUTOINCREMENT,
        post_title TEXT NOT NULL DEFAULT '',
        post_content TEXT NOT NULL DEFAULT '',
        post_status TEXT NOT NULL DEFAULT 'publish',
        post_type TEXT NOT NULL DEFAULT 'post',
        post_name TEXT NOT NULL DEFAULT ''
    )");
    $db->exec('CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)');
    $db->exec("CREATE TABLE wp_options (
        option_id INTEGER PRIMARY KEY AUTOINCREMENT,
        option_name TEXT NOT NULL,
        option_value TEXT NOT NULL,
        autoload TEXT NOT NULL DEFAULT 'yes'
    )");
    $db->exec("INSERT INTO plugin_asset (asset_id, label, file_path) VALUES (20, 'Shared plugin asset', 'wp-content/uploads/plugin-assets/shared.dat')");
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status, post_type, post_name) VALUES
        (21, 'Plugin asset consumer', '<!-- wp:paragraph --><p>Plugin asset consumer</p><!-- /wp:paragraph -->', 'publish', 'plugin_consumer', 'plugin-asset-consumer')");
    $option_value = serialize([
        'asset_id' => 20,
        'file_path' => 'wp-content/uploads/plugin-assets/shared.dat',
        'label' => 'base option',
    ]);
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('plugin_asset_settings', :value, 'yes')");
    $stmt->bindValue(':value', $option_value, SQLITE3_TEXT);
    $stmt->execute();
    $json_option_value = json_encode([
        'asset_id' => 20,
        'file_path' => 'wp-content/uploads/plugin-assets/shared.dat',
        'label' => 'base JSON option',
    ], JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('plugin_asset_json_settings', :value, 'yes')");
    $stmt->bindValue(':value', $json_option_value, SQLITE3_TEXT);
    $stmt->execute();
    $json_unsafe_option_value = json_encode([
        'asset_id' => 20,
        'file_path' => 'wp-content/uploads/plugin-assets/shared.dat',
        'label' => 'base unsafe JSON option',
    ], JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('plugin_asset_unsafe_json_settings', :value, 'yes')");
    $stmt->bindValue(':value', $json_unsafe_option_value, SQLITE3_TEXT);
    $stmt->execute();
    $meta_value = serialize([
        'asset_id' => 20,
        'file_path' => 'wp-content/uploads/plugin-assets/shared.dat',
        'caption' => 'base meta',
    ]);
    $stmt = $db->prepare("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (21, '_plugin_asset_ref', :value)");
    $stmt->bindValue(':value', $meta_value, SQLITE3_TEXT);
    $stmt->execute();
    $json_meta_value = json_encode([
        'asset_id' => 20,
        'file_path' => 'wp-content/uploads/plugin-assets/shared.dat',
        'caption' => 'base JSON meta',
    ], JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (21, '_plugin_asset_json_ref', :value)");
    $stmt->bindValue(':value', $json_meta_value, SQLITE3_TEXT);
    $stmt->execute();
    $db->close();
}

define('FORKPRESS_COW_MERGE_TESTS', true);
require_once __DIR__ . '/../../scripts/cow/merge.php';

echo "=== COW plugin validator focused tests ===\n";

$tmp = sys_get_temp_dir() . '/forkpress-cow-plugin-validator-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);

try {
    $base_root = $tmp . '/base';
    $source_root = $tmp . '/source';
    $target_root = $tmp . '/target';
    $base = $base_root . '/wp-content/database/.ht.sqlite';
    $source = $source_root . '/wp-content/database/.ht.sqlite';
    $target = $target_root . '/wp-content/database/.ht.sqlite';
    $metadata = $tmp . '/.forkpress/cow/merge/plugin-validator-metadata.sqlite';
    $file_base = $tmp . '/.forkpress/cow/merge/file-bases/plugin-validator.json';

    mkdir($base_root . '/wp-content/database', 0777, true);
    create_plugin_validator_db($base);
    write_test_file($base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$target_root = rtrim((string)getenv('FORKPRESS_MERGE_TARGET_ROOT'), '/');
$findings = [];
$res = $db->query('SELECT child_id, parent_id, graph_json, file_path FROM plugin_graph_child ORDER BY child_id');
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $child_id = (int)$row['child_id'];
    $parent_id = (int)$row['parent_id'];
    $graph = json_decode((string)$row['graph_json'], true);
    if (!is_array($graph) || (int)($graph['child_id'] ?? 0) !== $child_id || (int)($graph['parent_id'] ?? 0) !== $parent_id) {
        $findings[] = [
            'plugin' => 'forkpress-plugin-graph',
            'object' => 'child:' . $child_id,
            'reason' => 'plugin child JSON graph does not match the merged row graph',
            'type' => 'plugin-graph-json-drift',
            'tables' => ['plugin_graph_child'],
            'validator' => 'forkpress-plugin-graph@1',
            'candidate' => [
                'child_id' => $child_id,
                'parent_id' => $parent_id,
                'graph' => $graph,
            ],
        ];
    }
    $parent_exists = (int)$db->querySingle('SELECT COUNT(*) FROM plugin_graph_parent WHERE parent_id = ' . $parent_id);
    if ($parent_exists !== 1) {
        $findings[] = [
            'plugin' => 'forkpress-plugin-graph',
            'object' => 'child:' . $child_id,
            'reason' => 'plugin child references a missing parent row',
            'type' => 'plugin-graph-missing-parent',
            'tables' => ['plugin_graph_parent', 'plugin_graph_child'],
            'validator' => 'forkpress-plugin-graph@1',
            'candidate' => [
                'child_id' => $child_id,
                'parent_id' => $parent_id,
            ],
        ];
    }
    $file_path = str_replace('\\', '/', (string)$row['file_path']);
    if ($file_path === '' || str_starts_with($file_path, '/') || str_contains($file_path, '..') || !is_file($target_root . '/' . $file_path)) {
        $findings[] = [
            'plugin' => 'forkpress-plugin-graph',
            'object' => 'child:' . $child_id,
            'reason' => 'plugin child references a missing or unsafe file',
            'type' => 'plugin-graph-file-drift',
            'tables' => ['plugin_graph_child'],
            'paths' => [$file_path],
            'validator' => 'forkpress-plugin-graph@1',
            'severity' => 'error',
            'resolution_policy' => 'review-only',
            'suggested_action' => 'Restore or repair the plugin-owned file reference after review',
            'manual_review_reason' => 'ForkPress cannot synthesize plugin-owned files from a validator finding',
            'candidate' => [
                'child_id' => $child_id,
                'file_path' => $file_path,
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
    cow_merge_allocate_autoincrement_bands($source, $metadata, 'feature-plugin-validator-source');
    cow_merge_allocate_autoincrement_bands($target, $metadata, 'main');

    $source_db = open_db($source);
    $source_db->exec("INSERT INTO plugin_graph_parent (label) VALUES ('source plugin parent')");
    $parent_id = (int)$source_db->lastInsertRowID();
    $source_db->exec("INSERT INTO plugin_graph_child (parent_id, graph_json, file_path) VALUES ($parent_id, '{\"child_id\":9999,\"parent_id\":$parent_id}', 'wp-content/uploads/plugin-validator-missing.dat')");
    $child_id = (int)$source_db->lastInsertRowID();
    $source_db->close();

    $result = cow_merge_branch_state(
        $base,
        $source,
        $target,
        $metadata,
        'feature-plugin-validator-source',
        'main',
        $file_base,
        $source_root,
        $target_root
    );

    assert_same($result['status'], 'completed_with_conflicts', 'plugin validator holds incoherent plugin graph candidates for review');
    assert_same((int)($result['plugin_validators'] ?? 0), 1, 'plugin validator is discovered from mu-plugins during merge');
    assert_same((int)($result['plugin_validator_conflicts'] ?? 0), 2, 'plugin validator records JSON and file graph conflicts');
    assert_same(
        scalar($target, "SELECT parent_id FROM plugin_graph_child WHERE child_id = $child_id"),
        $parent_id,
        'plugin validator leaves the staged child row available for review'
    );
    assert_true(!is_file($target_root . '/wp-content/uploads/plugin-validator-missing.dat'), 'plugin validator does not invent missing plugin files');

    $audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
    ]);
    assert_same(count($audit['conflicts']), 2, 'plugin validator conflicts are visible in plugin audit scope');
    $preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $audit['conflicts']));
    assert_true(str_contains($preview, 'plugin-validator-missing.dat'), 'plugin audit exposes missing plugin file context');
    assert_true(str_contains($preview, '"child_id":9999'), 'plugin audit exposes mismatched JSON graph context');
    $file_audit_conflicts = array_values(array_filter($audit['conflicts'], fn($conflict) => ($conflict['conflict_type'] ?? '') === 'plugin-graph-file-drift'));
    assert_same(count($file_audit_conflicts), 1, 'plugin audit exposes the file validator conflict as a focused record');
    assert_same($file_audit_conflicts[0]['plugin'] ?? null, 'forkpress-plugin-graph', 'plugin audit exposes the validator plugin as a first-class field');
    assert_same($file_audit_conflicts[0]['plugin_object'] ?? null, 'child:' . $child_id, 'plugin audit exposes the validator object as a first-class field');
    assert_same($file_audit_conflicts[0]['plugin_validator'] ?? null, 'forkpress-plugin-graph@1', 'plugin audit exposes the validator version as a first-class field');
    assert_same($file_audit_conflicts[0]['plugin_severity'] ?? null, 'error', 'plugin audit exposes validator severity as a first-class field');
    assert_same($file_audit_conflicts[0]['plugin_tables'] ?? null, ['plugin_graph_child'], 'plugin audit exposes plugin-owned tables as structured fields');
    assert_same($file_audit_conflicts[0]['plugin_files'] ?? null, ['wp-content/uploads/plugin-validator-missing.dat'], 'plugin audit normalizes validator paths into structured plugin files');
    assert_same($file_audit_conflicts[0]['plugin_resolution_policy'] ?? null, 'review-only', 'plugin audit exposes validator review policy as a first-class field');
    assert_true(
        str_contains((string)($file_audit_conflicts[0]['plugin_manual_review_reason'] ?? ''), 'cannot synthesize plugin-owned files'),
        'plugin audit exposes validator manual-review guidance as a first-class field'
    );
    ob_start();
    cow_merge_print_audit_text($audit);
    $plugin_audit_text = ob_get_clean();
    assert_true(str_contains($plugin_audit_text, 'plugin plugin=forkpress-plugin-graph object=child:' . $child_id), 'plugin text audit exposes validator plugin and object fields');
    assert_true(str_contains($plugin_audit_text, 'validator=forkpress-plugin-graph@1'), 'plugin text audit exposes validator version');
    assert_true(str_contains($plugin_audit_text, 'severity=error'), 'plugin text audit exposes validator severity');
    assert_true(str_contains($plugin_audit_text, 'tables=plugin_graph_child'), 'plugin text audit exposes plugin-owned tables');
    assert_true(str_contains($plugin_audit_text, 'files=wp-content/uploads/plugin-validator-missing.dat'), 'plugin text audit exposes plugin-owned files');
    assert_true(str_contains($plugin_audit_text, 'plugin-guidance policy=review-only'), 'plugin text audit exposes validator review policy');
    assert_true(str_contains($plugin_audit_text, 'manual-review=ForkPress cannot synthesize plugin-owned files'), 'plugin text audit exposes validator manual-review reason');

    $json_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = '__plugins__' AND conflict_type = 'plugin-graph-json-drift' ORDER BY id ASC LIMIT 1");
    assert_true($json_conflict_id > 0, 'plugin validator fixture records a JSON graph conflict for revalidation');
    cow_merge_review_record(
        $metadata,
        'conflict',
        $json_conflict_id,
        'reviewed',
        'plugin graph validator needs an app-specific repair',
        'cow-test'
    );

    $initial_revalidation = cow_merge_revalidate_reviewed_conflicts($metadata, (int)$result['run_id'], 'cow-revalidate');
    assert_same($initial_revalidation['reviewed'], 1, 'plugin revalidation sees the reviewed validator conflict');
    assert_same($initial_revalidation['stale'], 0, 'plugin revalidation does not infer stale state without replacement validator evidence');
    assert_same($initial_revalidation['carried'], 0, 'plugin revalidation does not carry plugin conflicts without replacement evidence');

    $identical = cow_merge_record_plugin_validator_conflicts($metadata, (int)$result['run_id'], [
        [
            'plugin' => 'forkpress-plugin-graph',
            'object' => 'child:' . $child_id,
            'reason' => 'plugin child JSON graph does not match the merged row graph',
            'type' => 'plugin-graph-json-drift',
            'tables' => ['plugin_graph_child'],
            'validator' => 'forkpress-plugin-graph@1',
            'candidate' => [
                'child_id' => $child_id,
                'parent_id' => $parent_id,
                'graph' => [
                    'child_id' => 9999,
                    'parent_id' => $parent_id,
                ],
            ],
        ],
    ]);
    assert_same($identical['conflicts'], 1, 'identical plugin validator rerun keeps the same active conflict');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = '__plugins__' AND conflict_type = 'plugin-graph-json-drift'"),
        1,
        'identical plugin validator rerun records no duplicate replacement conflict'
    );

    $replacement = cow_merge_record_plugin_validator_conflicts($metadata, (int)$result['run_id'], [
        [
            'plugin' => 'forkpress-plugin-graph',
            'object' => 'child:' . $child_id,
            'reason' => 'plugin child JSON graph still does not match after validator rerun',
            'type' => 'plugin-graph-json-drift',
            'tables' => ['plugin_graph_child'],
            'validator' => 'forkpress-plugin-graph@1',
            'candidate' => [
                'child_id' => $child_id,
                'parent_id' => $parent_id,
                'graph' => [
                    'child_id' => 123456,
                    'parent_id' => $parent_id,
                ],
            ],
        ],
    ]);
    assert_same($replacement['conflicts'], 1, 'plugin validator rerun records replacement evidence for changed graph findings');
    $replacement_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = '__plugins__' AND conflict_type = 'plugin-graph-json-drift' AND id > $json_conflict_id ORDER BY id DESC LIMIT 1");
    assert_true($replacement_conflict_id > $json_conflict_id, 'plugin validator replacement evidence is stored as a newer conflict');
    $json_conflict_key = (string)scalar($metadata, "SELECT conflict_key FROM merge_conflicts WHERE id = $json_conflict_id");
    assert_same(
        (string)scalar($metadata, "SELECT conflict_key FROM merge_conflicts WHERE id = $replacement_conflict_id"),
        $json_conflict_key,
        'plugin validator replacement evidence keeps the same conflict key'
    );
    assert_same(
        (int)scalar($metadata, "SELECT previous_conflict_id FROM merge_conflicts WHERE id = $replacement_conflict_id"),
        $json_conflict_id,
        'plugin validator replacement evidence links to the prior plugin conflict'
    );
    $plugin_key_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_key' => $json_conflict_key,
    ]);
    assert_same(count($plugin_key_audit['conflicts']), 2, 'plugin conflict-key audit returns original and replacement evidence');
    assert_same(
        count(array_unique(array_column($plugin_key_audit['conflicts'], 'conflict_key'))),
        1,
        'plugin conflict-key audit stays focused on one logical plugin conflict'
    );

    $revalidated = cow_merge_revalidate_reviewed_conflicts($metadata, (int)$result['run_id'], 'cow-revalidate');
    assert_same($revalidated['reviewed'], 1, 'plugin revalidation still only carries reviewed validator conflicts');
    assert_same($revalidated['stale'], 1, 'plugin revalidation treats changed validator evidence as stale');
    assert_same($revalidated['carried'], 1, 'plugin revalidation carries changed validator evidence to needs-action');
    assert_same(
        scalar($metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $json_conflict_id ORDER BY id DESC LIMIT 1"),
        'replacement-evidence',
        'plugin revalidation classifies changed validator evidence'
    );
    assert_same(
        (int)scalar($metadata, "SELECT replacement_conflict_id FROM merge_revalidations WHERE conflict_id = $json_conflict_id ORDER BY id DESC LIMIT 1"),
        $replacement_conflict_id,
        'plugin revalidation links to the replacement validator conflict'
    );
    $plugin_revalidation_id = (int)scalar($metadata, "SELECT id FROM merge_revalidations WHERE conflict_id = $json_conflict_id ORDER BY id DESC LIMIT 1");

    $revalidated_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'review_status' => 'needs-action',
    ]);
    $reviewed_json_conflicts = array_values(array_filter($revalidated_audit['conflicts'], fn($conflict) => (int)($conflict['id'] ?? 0) === $json_conflict_id));
    assert_same(count($reviewed_json_conflicts), 1, 'plugin replacement evidence returns the original reviewed conflict to needs-action');
    assert_same($reviewed_json_conflicts[0]['stale_status'] ?? null, 'stale', 'plugin audit marks changed validator evidence as stale');
    assert_same((int)($reviewed_json_conflicts[0]['replacement_conflict_id'] ?? 0), $replacement_conflict_id, 'plugin audit exposes the live replacement conflict id');
    assert_true(str_contains((string)($reviewed_json_conflicts[0]['current_target_preview'] ?? ''), '123456'), 'plugin audit exposes replacement validator evidence');
    $plugin_event_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 4, [
        'scope' => 'plugin',
        'records' => 'conflict-events',
    ]);
    assert_same($plugin_event_audit['conflict_events'][0]['event_type'], 'revalidation-required', 'plugin replacement revalidation is visible in the conflict event stream');
    assert_same((int)$plugin_event_audit['conflict_events'][0]['conflict_id'], $json_conflict_id, 'plugin revalidation event belongs to the reviewed plugin conflict');
    assert_same($plugin_event_audit['conflict_events'][0]['related_record_type'], 'revalidation', 'plugin revalidation event links to the revalidation record');
    assert_same((int)$plugin_event_audit['conflict_events'][0]['related_record_id'], $plugin_revalidation_id, 'plugin revalidation event exposes the revalidation id');
    assert_same($plugin_event_audit['conflict_events'][0]['lifecycle_state'], 'needs-action', 'plugin revalidation event records the needs-action lifecycle state');

    $revalidated_again = cow_merge_revalidate_reviewed_conflicts($metadata, (int)$result['run_id'], 'cow-revalidate');
    assert_same($revalidated_again['carried'], 0, 'plugin revalidation does not duplicate carried replacement-evidence notes');
    assert_same($revalidated_again['already_needs_action'], 1, 'plugin revalidation reports already-carried replacement evidence');

    $source_payload_result = cow_merge_record_plugin_validator_conflicts($metadata, (int)$result['run_id'], [
        [
            'plugin' => 'forkpress-plugin-source-drift',
            'object' => 'child:' . $child_id,
            'reason' => 'plugin validator source evidence needs review',
            'type' => 'plugin-graph-source-evidence',
            'tables' => ['plugin_graph_child'],
            'validator' => 'forkpress-plugin-graph@1',
            'source' => [
                'child_id' => $child_id,
                'source_revision' => 'source-before-rerun',
            ],
            'target' => [
                'child_id' => $child_id,
                'target_revision' => 'target-at-review',
            ],
            'candidate' => [
                'child_id' => $child_id,
                'graph' => 'candidate-at-review',
            ],
        ],
    ]);
    assert_same($source_payload_result['conflicts'], 1, 'plugin validator can record explicit source evidence for later revalidation');
    $source_payload_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = '__plugins__' AND conflict_type = 'plugin-graph-source-evidence' ORDER BY id DESC LIMIT 1");
    assert_true($source_payload_conflict_id > 0, 'plugin source-evidence conflict is recorded');
    cow_merge_review_record(
        $metadata,
        'conflict',
        $source_payload_conflict_id,
        'reviewed',
        'plugin source evidence looked safe before rerun',
        'cow-test'
    );

    $updated_source_payload = cow_merge_record_plugin_validator_conflicts($metadata, (int)$result['run_id'], [
        [
            'plugin' => 'forkpress-plugin-source-drift',
            'object' => 'child:' . $child_id,
            'reason' => 'plugin validator source evidence changed after rerun',
            'type' => 'plugin-graph-source-evidence',
            'tables' => ['plugin_graph_child'],
            'validator' => 'forkpress-plugin-graph@1',
            'source' => [
                'child_id' => $child_id,
                'source_revision' => 'source-after-rerun',
            ],
            'target' => [
                'child_id' => $child_id,
                'target_revision' => 'target-at-review',
            ],
            'candidate' => [
                'child_id' => $child_id,
                'graph' => 'candidate-at-review',
            ],
        ],
    ]);
    assert_same($updated_source_payload['conflicts'], 1, 'plugin validator rerun records replacement evidence when source evidence changes');
    $source_payload_replacement_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = '__plugins__' AND conflict_type = 'plugin-graph-source-evidence' AND id > $source_payload_conflict_id ORDER BY id DESC LIMIT 1");
    assert_true($source_payload_replacement_id > $source_payload_conflict_id, 'plugin source-evidence rerun stores a newer conflict');

    $source_payload_revalidated = cow_merge_revalidate_reviewed_conflicts($metadata, (int)$result['run_id'], 'cow-revalidate');
    assert_true($source_payload_revalidated['stale'] >= 1, 'plugin revalidation treats changed source evidence as stale');
    assert_true($source_payload_revalidated['carried'] >= 1, 'plugin revalidation carries changed source evidence to needs-action');
    assert_same(
        scalar($metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $source_payload_conflict_id ORDER BY id DESC LIMIT 1"),
        'replacement-evidence',
        'plugin source-evidence revalidation uses the replacement-evidence classifier'
    );
    assert_same(
        (int)scalar($metadata, "SELECT replacement_conflict_id FROM merge_revalidations WHERE conflict_id = $source_payload_conflict_id ORDER BY id DESC LIMIT 1"),
        $source_payload_replacement_id,
        'plugin source-evidence revalidation links to the newer validator finding'
    );
    $source_payload_revalidated_payload = cow_merge_decode_payload_json(
        (string)scalar($metadata, "SELECT source_payload FROM merge_revalidations WHERE conflict_id = $source_payload_conflict_id ORDER BY id DESC LIMIT 1"),
        'plugin source-evidence revalidation source'
    );
    assert_same(
        $source_payload_revalidated_payload['source_revision'] ?? null,
        'source-after-rerun',
        'plugin source-evidence revalidation records the updated source payload'
    );

    $logical_identity_result = cow_merge_record_plugin_validator_conflicts($metadata, (int)$result['run_id'], [
        [
            'plugin' => 'forkpress-plugin-logical-id',
            'object' => 'child-slot:' . $child_id,
            'logical_identity' => [
                'kind' => 'plugin-child',
                'slug' => 'child-before-rerun',
            ],
            'reason' => 'plugin validator logical identity needs review',
            'type' => 'plugin-graph-logical-identity',
            'tables' => ['plugin_graph_child'],
            'validator' => 'forkpress-plugin-graph@1',
            'candidate' => [
                'child_id' => $child_id,
                'graph' => 'candidate-at-review',
            ],
        ],
    ]);
    assert_same($logical_identity_result['conflicts'], 1, 'plugin validator records explicit logical identity evidence');
    $logical_identity_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = '__plugins__' AND conflict_type = 'plugin-graph-logical-identity' ORDER BY id DESC LIMIT 1");
    assert_true($logical_identity_conflict_id > 0, 'plugin logical-identity conflict is recorded');
    $logical_identity_payload = cow_merge_decode_payload_json(
        (string)scalar($metadata, "SELECT chosen_payload FROM merge_conflicts WHERE id = $logical_identity_conflict_id"),
        'plugin logical identity payload'
    );
    assert_same(
        $logical_identity_payload['logical_identity']['slug'] ?? null,
        'child-before-rerun',
        'plugin logical identity is stored as first-class validator evidence'
    );
    $logical_identity_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-graph-logical-identity',
    ]);
    assert_same(count($logical_identity_audit['conflicts']), 1, 'plugin logical identity is visible as a plugin-scoped audit conflict');
    assert_same(
        $logical_identity_audit['conflicts'][0]['plugin_logical_identity']['slug'] ?? null,
        'child-before-rerun',
        'plugin audit exposes logical identity as a structured field'
    );
    ob_start();
    cow_merge_print_audit_text($logical_identity_audit);
    $logical_identity_text = ob_get_clean();
    assert_true(str_contains($logical_identity_text, 'plugin-logical-identity={"kind":"plugin-child","slug":"child-before-rerun"}'), 'plugin text audit exposes logical identity evidence');
    cow_merge_review_record(
        $metadata,
        'conflict',
        $logical_identity_conflict_id,
        'reviewed',
        'plugin logical identity looked safe before rerun',
        'cow-test'
    );

    $updated_logical_identity_result = cow_merge_record_plugin_validator_conflicts($metadata, (int)$result['run_id'], [
        [
            'plugin' => 'forkpress-plugin-logical-id',
            'object' => 'child-slot:' . $child_id,
            'logical_identity' => [
                'kind' => 'plugin-child',
                'slug' => 'child-after-rerun',
            ],
            'reason' => 'plugin validator logical identity changed after rerun',
            'type' => 'plugin-graph-logical-identity',
            'tables' => ['plugin_graph_child'],
            'validator' => 'forkpress-plugin-graph@1',
            'candidate' => [
                'child_id' => $child_id,
                'graph' => 'candidate-at-review',
            ],
        ],
    ]);
    assert_same($updated_logical_identity_result['conflicts'], 1, 'plugin validator rerun records replacement evidence when logical identity changes');
    $logical_identity_replacement_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = '__plugins__' AND conflict_type = 'plugin-graph-logical-identity' AND id > $logical_identity_conflict_id ORDER BY id DESC LIMIT 1");
    assert_true($logical_identity_replacement_id > $logical_identity_conflict_id, 'plugin logical-identity rerun stores a newer conflict');

    $logical_identity_revalidated = cow_merge_revalidate_reviewed_conflicts($metadata, (int)$result['run_id'], 'cow-revalidate');
    assert_true($logical_identity_revalidated['stale'] >= 1, 'plugin revalidation treats changed logical identity as stale');
    assert_true($logical_identity_revalidated['carried'] >= 1, 'plugin revalidation carries changed logical identity to needs-action');
    assert_same(
        scalar($metadata, "SELECT revalidation_class FROM merge_revalidations WHERE conflict_id = $logical_identity_conflict_id ORDER BY id DESC LIMIT 1"),
        'replacement-evidence',
        'plugin logical-identity revalidation uses the replacement-evidence classifier'
    );
    assert_same(
        (int)scalar($metadata, "SELECT replacement_conflict_id FROM merge_revalidations WHERE conflict_id = $logical_identity_conflict_id ORDER BY id DESC LIMIT 1"),
        $logical_identity_replacement_id,
        'plugin logical-identity revalidation links to the newer validator finding'
    );
    $logical_identity_revalidated_payload = cow_merge_decode_payload_json(
        (string)scalar($metadata, "SELECT target_payload FROM merge_revalidations WHERE conflict_id = $logical_identity_conflict_id ORDER BY id DESC LIMIT 1"),
        'plugin logical identity revalidation target'
    );
    assert_same(
        $logical_identity_revalidated_payload['logical_identity']['slug'] ?? null,
        'child-after-rerun',
        'plugin logical-identity revalidation records the updated validator identity'
    );

    $serialized_base_root = $tmp . '/serialized-base';
    $serialized_source_root = $tmp . '/serialized-source';
    $serialized_target_root = $tmp . '/serialized-target';
    $serialized_base = $serialized_base_root . '/wp-content/database/.ht.sqlite';
    $serialized_source = $serialized_source_root . '/wp-content/database/.ht.sqlite';
    $serialized_target = $serialized_target_root . '/wp-content/database/.ht.sqlite';
    $serialized_metadata = $tmp . '/.forkpress/cow/merge/plugin-serialized-validator-metadata.sqlite';
    $serialized_file_base = $tmp . '/.forkpress/cow/merge/file-bases/plugin-serialized-validator.json';

    mkdir($serialized_base_root . '/wp-content/database', 0777, true);
    create_plugin_serialized_validator_db($serialized_base);
    write_test_file($serialized_base_root . '/wp-content/uploads/plugin-assets/shared.dat', 'shared plugin asset');
    write_test_file($serialized_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$target_root = rtrim((string)getenv('FORKPRESS_MERGE_TARGET_ROOT'), '/');
$findings = [];
$check_asset = function (string $object, string $field, array $payload, array $tables) use ($db, $target_root, &$findings): void {
    $asset_id = (int)($payload['asset_id'] ?? 0);
    $file_path = str_replace('\\', '/', (string)($payload['file_path'] ?? ''));
    if ($asset_id <= 0) {
        return;
    }
    $asset_exists = (int)$db->querySingle('SELECT COUNT(*) FROM plugin_asset WHERE asset_id = ' . $asset_id);
    $file_safe = $file_path !== '' && !str_starts_with($file_path, '/') && !str_contains($file_path, '..');
    $file_exists = $file_safe && is_file($target_root . '/' . $file_path);
    if ($asset_exists === 1 && $file_exists) {
        return;
    }
    $findings[] = [
        'plugin' => 'forkpress-plugin-serialized-graph',
        'object' => $object,
        'reason' => 'serialized plugin reference points at a missing asset row or file',
        'type' => 'plugin-serialized-missing-asset',
        'tables' => $tables,
        'paths' => [$file_path],
        'validator' => 'forkpress-plugin-serialized-graph@1',
        'candidate' => [
            'field' => $field,
            'asset_id' => $asset_id,
            'file_path' => $file_path,
            'asset_exists' => $asset_exists,
            'file_safe' => $file_safe,
            'file_exists' => $file_exists,
        ],
    ];
};

$option_value = $db->querySingle("SELECT option_value FROM wp_options WHERE option_name = 'plugin_asset_settings'");
$option_payload = is_string($option_value) ? @unserialize($option_value) : null;
if (is_array($option_payload)) {
    $check_asset('option:plugin_asset_settings', 'option.plugin_asset_settings.asset_id', $option_payload, ['wp_options', 'plugin_asset']);
}

$json_option_value = $db->querySingle("SELECT option_value FROM wp_options WHERE option_name = 'plugin_asset_json_settings'");
$json_option_payload = is_string($json_option_value) ? json_decode($json_option_value, true) : null;
if (is_array($json_option_payload)) {
    $check_asset('option:plugin_asset_json_settings', 'option.plugin_asset_json_settings.asset_id', $json_option_payload, ['wp_options', 'plugin_asset']);
}

$json_unsafe_option_value = $db->querySingle("SELECT option_value FROM wp_options WHERE option_name = 'plugin_asset_unsafe_json_settings'");
$json_unsafe_option_payload = is_string($json_unsafe_option_value) ? json_decode($json_unsafe_option_value, true) : null;
if (is_array($json_unsafe_option_payload)) {
    $check_asset('option:plugin_asset_unsafe_json_settings', 'option.plugin_asset_unsafe_json_settings.file_path', $json_unsafe_option_payload, ['wp_options', 'plugin_asset']);
}

$res = $db->query("SELECT meta_id, meta_value FROM wp_postmeta WHERE meta_key = '_plugin_asset_ref'");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $meta_payload = @unserialize((string)$row['meta_value']);
    if (is_array($meta_payload)) {
        $check_asset('postmeta:' . (string)$row['meta_id'], 'postmeta._plugin_asset_ref.asset_id', $meta_payload, ['wp_postmeta', 'plugin_asset']);
    }
}

$res = $db->query("SELECT meta_id, meta_value FROM wp_postmeta WHERE meta_key = '_plugin_asset_json_ref'");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $meta_payload = json_decode((string)$row['meta_value'], true);
    if (is_array($meta_payload)) {
        $check_asset('postmeta:' . (string)$row['meta_id'], 'postmeta._plugin_asset_json_ref.asset_id', $meta_payload, ['wp_postmeta', 'plugin_asset']);
    }
}

echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);

    copy_tree_for_test($serialized_base_root, $serialized_source_root);
    copy_tree_for_test($serialized_base_root, $serialized_target_root);
    cow_merge_capture_file_base($serialized_base_root, $serialized_file_base);
    cow_merge_allocate_autoincrement_bands($serialized_source, $serialized_metadata, 'feature-plugin-serialized-source');
    cow_merge_allocate_autoincrement_bands($serialized_target, $serialized_metadata, 'main');

    $db = open_db($serialized_source);
    $db->exec('DELETE FROM plugin_asset WHERE asset_id = 20');
    $db->close();
    unlink($serialized_source_root . '/wp-content/uploads/plugin-assets/shared.dat');

    $db = open_db($serialized_target);
    $target_option = serialize([
        'asset_id' => 20,
        'file_path' => 'wp-content/uploads/plugin-assets/shared.dat',
        'label' => 'target option edit',
    ]);
    $stmt = $db->prepare("UPDATE wp_options SET option_value = :value WHERE option_name = 'plugin_asset_settings'");
    $stmt->bindValue(':value', $target_option, SQLITE3_TEXT);
    $stmt->execute();
    $target_json_option = json_encode([
        'asset_id' => 20,
        'file_path' => 'wp-content/uploads/plugin-assets/shared.dat',
        'label' => 'target JSON option edit',
    ], JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare("UPDATE wp_options SET option_value = :value WHERE option_name = 'plugin_asset_json_settings'");
    $stmt->bindValue(':value', $target_json_option, SQLITE3_TEXT);
    $stmt->execute();
    $target_unsafe_json_option = json_encode([
        'asset_id' => 20,
        'file_path' => '../private/plugin-asset.dat',
        'label' => 'target unsafe JSON option edit',
    ], JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare("UPDATE wp_options SET option_value = :value WHERE option_name = 'plugin_asset_unsafe_json_settings'");
    $stmt->bindValue(':value', $target_unsafe_json_option, SQLITE3_TEXT);
    $stmt->execute();
    $target_meta = serialize([
        'asset_id' => 20,
        'file_path' => 'wp-content/uploads/plugin-assets/shared.dat',
        'caption' => 'target meta edit',
    ]);
    $stmt = $db->prepare("UPDATE wp_postmeta SET meta_value = :value WHERE meta_key = '_plugin_asset_ref'");
    $stmt->bindValue(':value', $target_meta, SQLITE3_TEXT);
    $stmt->execute();
    $target_json_meta = json_encode([
        'asset_id' => 20,
        'file_path' => 'wp-content/uploads/plugin-assets/shared.dat',
        'caption' => 'target JSON meta edit',
    ], JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare("UPDATE wp_postmeta SET meta_value = :value WHERE meta_key = '_plugin_asset_json_ref'");
    $stmt->bindValue(':value', $target_json_meta, SQLITE3_TEXT);
    $stmt->execute();
    $db->close();

    $serialized_result = cow_merge_branch_state(
        $serialized_base,
        $serialized_source,
        $serialized_target,
        $serialized_metadata,
        'feature-plugin-serialized-source',
        'main',
        $serialized_file_base,
        $serialized_source_root,
        $serialized_target_root
    );

    assert_same($serialized_result['status'], 'completed_with_conflicts', 'plugin validator holds serialized and JSON option/postmeta asset refs for review');
    assert_same((int)($serialized_result['plugin_validators'] ?? 0), 1, 'serialized plugin validator is discovered from mu-plugins during merge');
    assert_same((int)($serialized_result['plugin_validator_conflicts'] ?? 0), 5, 'serialized plugin validator records serialized and JSON option/postmeta asset conflicts');
    assert_same((int)scalar($serialized_target, 'SELECT COUNT(*) FROM plugin_asset WHERE asset_id = 20'), 0, 'serialized plugin validator leaves the source asset row deletion staged for review');
    assert_true(!is_file($serialized_target_root . '/wp-content/uploads/plugin-assets/shared.dat'), 'serialized plugin validator leaves the source asset file deletion staged for review');
    $merged_option = unserialize((string)scalar($serialized_target, "SELECT option_value FROM wp_options WHERE option_name = 'plugin_asset_settings'"));
    assert_same($merged_option['label'] ?? null, 'target option edit', 'serialized plugin validator preserves target option edits');
    assert_same($merged_option['asset_id'] ?? null, 20, 'serialized plugin validator keeps the stale option asset reference visible');
    $merged_json_option = json_decode((string)scalar($serialized_target, "SELECT option_value FROM wp_options WHERE option_name = 'plugin_asset_json_settings'"), true);
    assert_same($merged_json_option['label'] ?? null, 'target JSON option edit', 'serialized plugin validator preserves target JSON option edits');
    assert_same($merged_json_option['asset_id'] ?? null, 20, 'serialized plugin validator keeps the stale JSON option asset reference visible');
    $merged_unsafe_json_option = json_decode((string)scalar($serialized_target, "SELECT option_value FROM wp_options WHERE option_name = 'plugin_asset_unsafe_json_settings'"), true);
    assert_same($merged_unsafe_json_option['label'] ?? null, 'target unsafe JSON option edit', 'serialized plugin validator preserves target unsafe JSON option edits');
    assert_same($merged_unsafe_json_option['file_path'] ?? null, '../private/plugin-asset.dat', 'serialized plugin validator keeps the unsafe JSON option file path visible');
    $merged_meta = unserialize((string)scalar($serialized_target, "SELECT meta_value FROM wp_postmeta WHERE meta_key = '_plugin_asset_ref'"));
    assert_same($merged_meta['caption'] ?? null, 'target meta edit', 'serialized plugin validator preserves target postmeta edits');
    assert_same($merged_meta['asset_id'] ?? null, 20, 'serialized plugin validator keeps the stale postmeta asset reference visible');
    $merged_json_meta = json_decode((string)scalar($serialized_target, "SELECT meta_value FROM wp_postmeta WHERE meta_key = '_plugin_asset_json_ref'"), true);
    assert_same($merged_json_meta['caption'] ?? null, 'target JSON meta edit', 'serialized plugin validator preserves target JSON postmeta edits');
    assert_same($merged_json_meta['asset_id'] ?? null, 20, 'serialized plugin validator keeps the stale JSON postmeta asset reference visible');

    $serialized_audit = cow_merge_audit_report($serialized_metadata, (int)$serialized_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-serialized-missing-asset',
    ]);
    assert_same(count($serialized_audit['conflicts']), 5, 'serialized plugin validator exposes serialized and JSON option/postmeta refs as plugin audit conflicts');
    $serialized_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $serialized_audit['conflicts']));
    assert_true(str_contains($serialized_preview, 'option.plugin_asset_settings.asset_id'), 'serialized plugin audit includes the option reference field');
    assert_true(str_contains($serialized_preview, 'option.plugin_asset_json_settings.asset_id'), 'serialized plugin audit includes the JSON option reference field');
    assert_true(str_contains($serialized_preview, 'option.plugin_asset_unsafe_json_settings.file_path'), 'serialized plugin audit includes the unsafe JSON option file field');
    assert_true(str_contains($serialized_preview, 'postmeta._plugin_asset_ref.asset_id'), 'serialized plugin audit includes the postmeta reference field');
    assert_true(str_contains($serialized_preview, 'postmeta._plugin_asset_json_ref.asset_id'), 'serialized plugin audit includes the JSON postmeta reference field');
    assert_true(str_contains($serialized_preview, '"asset_id":20'), 'serialized plugin audit includes the missing asset ID');
    assert_true(str_contains($serialized_preview, '"file_exists":false'), 'serialized plugin audit records the missing asset file evidence');
    $unsafe_asset_conflicts = array_values(array_filter($serialized_audit['conflicts'], function (array $conflict): bool {
        $payload = cow_merge_decode_payload_json((string)($conflict['chosen_payload'] ?? ''), 'plugin serialized asset conflict');
        return is_array($payload)
            && (($payload['candidate']['field'] ?? null) === 'option.plugin_asset_unsafe_json_settings.file_path')
            && (($payload['candidate']['file_path'] ?? null) === '../private/plugin-asset.dat')
            && (($payload['candidate']['file_safe'] ?? null) === false);
    }));
    assert_same(count($unsafe_asset_conflicts), 1, 'serialized plugin audit records unsafe file path evidence');

    $env_validator = $tmp . '/plugin-validator-env.php';
    write_test_file($env_validator, <<<'PHP'
<?php
$required_files = [
    'FORKPRESS_MERGE_METADATA_DB',
    'FORKPRESS_MERGE_BASE_DB',
    'FORKPRESS_MERGE_SOURCE_DB',
    'FORKPRESS_MERGE_TARGET_DB',
    'FORKPRESS_MERGE_TARGET_BEFORE_DB',
];
$required_dirs = [
    'FORKPRESS_MERGE_BASE_ROOT',
    'FORKPRESS_MERGE_SOURCE_ROOT',
    'FORKPRESS_MERGE_TARGET_ROOT',
    'FORKPRESS_MERGE_TARGET_BEFORE_ROOT',
];
$required_values = [
    'FORKPRESS_MERGE_RUN',
    'FORKPRESS_MERGE_SOURCE_BRANCH',
    'FORKPRESS_MERGE_TARGET_BRANCH',
];
$missing = [];
foreach ($required_files as $name) {
    $value = (string)getenv($name);
    if ($value === '' || !is_file($value)) {
        $missing[] = $name;
    }
}
foreach ($required_dirs as $name) {
    $value = (string)getenv($name);
    if ($value === '' || !is_dir($value)) {
        $missing[] = $name;
    }
}
foreach ($required_values as $name) {
    $value = (string)getenv($name);
    if ($value === '') {
        $missing[] = $name;
    }
}
$candidate = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$before = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_BEFORE_DB'));
$candidate_children = (int)$candidate->querySingle('SELECT COUNT(*) FROM plugin_graph_child');
$before_children = (int)$before->querySingle('SELECT COUNT(*) FROM plugin_graph_child');
$candidate->close();
$before->close();
if ($candidate_children < 1 || $before_children !== 0) {
    $missing[] = 'target-before snapshot state';
}
$before_root = rtrim((string)getenv('FORKPRESS_MERGE_TARGET_BEFORE_ROOT'), '/');
if (!is_file($before_root . '/wp-content/mu-plugins/forkpress-merge-validator.php')) {
    $missing[] = 'target-before root contents';
}
echo json_encode([
    'status' => $missing === [] ? 'valid' : 'failed',
    'reason' => $missing === [] ? '' : 'missing merge validator context: ' . implode(', ', $missing),
    'findings' => [],
], JSON_UNESCAPED_SLASHES);
PHP);
    $env_result = run_merge_cli([
        'run-plugin-validator',
        '--metadata-db', $metadata,
        '--run', (string)$result['run_id'],
        '--validator', $env_validator,
        '--format', 'json',
    ]);
    assert_same($env_result['status'], 0, 'plugin validator runner provides documented merge context environment');
    $env_decoded = json_decode($env_result['output'], true);
    assert_same($env_decoded['validator_status'] ?? null, 'valid', 'plugin validator context probe reports valid');
    assert_same((int)($env_decoded['conflicts'] ?? -1), 0, 'plugin validator context probe records no conflicts');
    $context_db = (string)scalar($metadata, "SELECT target_before_db FROM merge_runs WHERE id = " . (int)$result['run_id']);
    assert_true(
        str_starts_with($context_db, cow_merge_validator_context_dir($metadata, (int)$result['run_id']) . '/'),
        'plugin validator target-before context is persisted beside merge metadata'
    );

    $contradictory_validator = $tmp . '/plugin-validator-contradictory-valid.php';
    write_test_file($contradictory_validator, <<<'PHP'
<?php
echo json_encode([
    'status' => 'valid',
    'findings' => [
        [
            'plugin' => 'forkpress-plugin-graph',
            'object' => 'child:contradictory-valid',
            'reason' => 'valid status must not carry findings',
            'type' => 'plugin-graph-contradictory-valid',
        ],
    ],
], JSON_UNESCAPED_SLASHES);
PHP);
    $contradictory = run_merge_cli([
        'run-plugin-validator',
        '--metadata-db', $metadata,
        '--run', (string)$result['run_id'],
        '--validator', $contradictory_validator,
        '--format', 'json',
    ]);
    assert_true($contradictory['status'] !== 0, 'plugin validator runner rejects valid status with findings');
    assert_true(str_contains($contradictory['output'], 'status valid with findings'), 'plugin validator runner explains contradictory valid findings');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE conflict_type = 'plugin-graph-contradictory-valid'"),
        0,
        'plugin validator runner does not record contradictory valid findings'
    );

    $empty_conflicts_validator = $tmp . '/plugin-validator-empty-conflicts.php';
    write_test_file($empty_conflicts_validator, <<<'PHP'
<?php
echo json_encode([
    'status' => 'conflicts',
    'findings' => [],
], JSON_UNESCAPED_SLASHES);
PHP);
    $empty_conflicts = run_merge_cli([
        'run-plugin-validator',
        '--metadata-db', $metadata,
        '--run', (string)$result['run_id'],
        '--validator', $empty_conflicts_validator,
        '--format', 'json',
    ]);
    assert_true($empty_conflicts['status'] !== 0, 'plugin validator runner rejects conflicts status without findings');
    assert_true(str_contains($empty_conflicts['output'], 'status conflicts without findings'), 'plugin validator runner explains empty conflict findings');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE conflict_type = 'plugin-validator-conflict' AND row_identity LIKE '%plugin-validator%'"),
        0,
        'plugin validator runner does not record empty conflict findings'
    );

    $raw_malformed_validator = $tmp . '/plugin-validator-raw-malformed.php';
    write_test_file($raw_malformed_validator, <<<'PHP'
<?php
echo json_encode([
    'raw malformed finding entry',
], JSON_UNESCAPED_SLASHES);
PHP);
    $conflicts_before_raw_malformed = (int)scalar($metadata, 'SELECT COUNT(*) FROM merge_conflicts');
    $raw_malformed = run_merge_cli([
        'run-plugin-validator',
        '--metadata-db', $metadata,
        '--run', (string)$result['run_id'],
        '--validator', $raw_malformed_validator,
        '--format', 'json',
    ]);
    assert_true($raw_malformed['status'] !== 0, 'plugin validator runner rejects malformed raw findings arrays');
    assert_true(str_contains($raw_malformed['output'], 'findings must be arrays'), 'plugin validator runner explains malformed raw findings arrays');
    assert_same(
        (int)scalar($metadata, 'SELECT COUNT(*) FROM merge_conflicts'),
        $conflicts_before_raw_malformed,
        'plugin validator runner does not record partial raw malformed findings'
    );

    $malformed_validator = $tmp . '/plugin-validator-malformed-finding.php';
    write_test_file($malformed_validator, <<<'PHP'
<?php
echo json_encode([
    'status' => 'conflicts',
    'findings' => [
        [
            'plugin' => 'forkpress-plugin-graph',
            'object' => 'child:malformed',
            'reason' => 'malformed finding uses a non-plugin conflict type',
            'type' => 'row-target-deleted',
        ],
    ],
], JSON_UNESCAPED_SLASHES);
PHP);
    $malformed = run_merge_cli([
        'run-plugin-validator',
        '--metadata-db', $metadata,
        '--run', (string)$result['run_id'],
        '--validator', $malformed_validator,
        '--format', 'json',
    ]);
    assert_true($malformed['status'] !== 0, 'plugin validator runner rejects malformed finding conflict types');
    assert_true(str_contains($malformed['output'], 'conflict type must start with plugin-'), 'plugin validator runner explains malformed conflict types');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE row_identity LIKE '%child:malformed%'"),
        0,
        'plugin validator runner does not record malformed findings'
    );
} finally {
    remove_tree($tmp);
}

if ($fail) {
    echo "FAILURES: $fail\n";
    exit(1);
}
echo "COW plugin validator focused tests passed ($pass assertions).\n";

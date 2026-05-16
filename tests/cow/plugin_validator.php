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

    $revalidated_again = cow_merge_revalidate_reviewed_conflicts($metadata, (int)$result['run_id'], 'cow-revalidate');
    assert_same($revalidated_again['carried'], 0, 'plugin revalidation does not duplicate carried replacement-evidence notes');
    assert_same($revalidated_again['already_needs_action'], 1, 'plugin revalidation reports already-carried replacement evidence');

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
} finally {
    remove_tree($tmp);
}

if ($fail) {
    echo "FAILURES: $fail\n";
    exit(1);
}
echo "COW plugin validator focused tests passed ($pass assertions).\n";

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
    $meta_value = serialize([
        'asset_id' => 20,
        'file_path' => 'wp-content/uploads/plugin-assets/shared.dat',
        'caption' => 'base meta',
    ]);
    $stmt = $db->prepare("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (21, '_plugin_asset_ref', :value)");
    $stmt->bindValue(':value', $meta_value, SQLITE3_TEXT);
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
    $file_exists = $file_path !== '' && !str_starts_with($file_path, '/') && !str_contains($file_path, '..') && is_file($target_root . '/' . $file_path);
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
            'file_exists' => $file_exists,
        ],
    ];
};

$option_value = $db->querySingle("SELECT option_value FROM wp_options WHERE option_name = 'plugin_asset_settings'");
$option_payload = is_string($option_value) ? @unserialize($option_value) : null;
if (is_array($option_payload)) {
    $check_asset('option:plugin_asset_settings', 'option.plugin_asset_settings.asset_id', $option_payload, ['wp_options', 'plugin_asset']);
}

$res = $db->query("SELECT meta_id, meta_value FROM wp_postmeta WHERE meta_key = '_plugin_asset_ref'");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $meta_payload = @unserialize((string)$row['meta_value']);
    if (is_array($meta_payload)) {
        $check_asset('postmeta:' . (string)$row['meta_id'], 'postmeta._plugin_asset_ref.asset_id', $meta_payload, ['wp_postmeta', 'plugin_asset']);
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
    $target_meta = serialize([
        'asset_id' => 20,
        'file_path' => 'wp-content/uploads/plugin-assets/shared.dat',
        'caption' => 'target meta edit',
    ]);
    $stmt = $db->prepare("UPDATE wp_postmeta SET meta_value = :value WHERE meta_key = '_plugin_asset_ref'");
    $stmt->bindValue(':value', $target_meta, SQLITE3_TEXT);
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

    assert_same($serialized_result['status'], 'completed_with_conflicts', 'plugin validator holds serialized option and postmeta asset refs for review');
    assert_same((int)($serialized_result['plugin_validators'] ?? 0), 1, 'serialized plugin validator is discovered from mu-plugins during merge');
    assert_same((int)($serialized_result['plugin_validator_conflicts'] ?? 0), 2, 'serialized plugin validator records option and postmeta asset conflicts');
    assert_same((int)scalar($serialized_target, 'SELECT COUNT(*) FROM plugin_asset WHERE asset_id = 20'), 0, 'serialized plugin validator leaves the source asset row deletion staged for review');
    assert_true(!is_file($serialized_target_root . '/wp-content/uploads/plugin-assets/shared.dat'), 'serialized plugin validator leaves the source asset file deletion staged for review');
    $merged_option = unserialize((string)scalar($serialized_target, "SELECT option_value FROM wp_options WHERE option_name = 'plugin_asset_settings'"));
    assert_same($merged_option['label'] ?? null, 'target option edit', 'serialized plugin validator preserves target option edits');
    assert_same($merged_option['asset_id'] ?? null, 20, 'serialized plugin validator keeps the stale option asset reference visible');
    $merged_meta = unserialize((string)scalar($serialized_target, "SELECT meta_value FROM wp_postmeta WHERE meta_key = '_plugin_asset_ref'"));
    assert_same($merged_meta['caption'] ?? null, 'target meta edit', 'serialized plugin validator preserves target postmeta edits');
    assert_same($merged_meta['asset_id'] ?? null, 20, 'serialized plugin validator keeps the stale postmeta asset reference visible');

    $serialized_audit = cow_merge_audit_report($serialized_metadata, (int)$serialized_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_type' => 'plugin-serialized-missing-asset',
    ]);
    assert_same(count($serialized_audit['conflicts']), 2, 'serialized plugin validator exposes option and postmeta refs as plugin audit conflicts');
    $serialized_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $serialized_audit['conflicts']));
    assert_true(str_contains($serialized_preview, 'option.plugin_asset_settings.asset_id'), 'serialized plugin audit includes the option reference field');
    assert_true(str_contains($serialized_preview, 'postmeta._plugin_asset_ref.asset_id'), 'serialized plugin audit includes the postmeta reference field');
    assert_true(str_contains($serialized_preview, '"asset_id":20'), 'serialized plugin audit includes the missing asset ID');
    assert_true(str_contains($serialized_preview, '"file_exists":false'), 'serialized plugin audit records the missing asset file evidence');

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

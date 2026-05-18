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
    global $pass, $fail;
    try {
        $fn();
        echo "  FAIL: $msg (no exception)\n";
        $fail++;
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), $contains)) {
            echo "  PASS: $msg ({$e->getMessage()})\n";
            $pass++;
        } else {
            echo "  FAIL: $msg (unexpected exception: {$e->getMessage()})\n";
            $fail++;
        }
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
    return run_merge_cli_env($args, []);
}

function run_merge_cli_env(array $args, array $env): array {
    $base_env = getenv();
    if (!is_array($base_env)) {
        $base_env = [];
    }
    $pipes = [];
    $process = proc_open(
        array_merge([PHP_BINARY, __DIR__ . '/../../scripts/cow/merge.php'], $args),
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        null,
        array_merge($base_env, $env)
    );
    if (!is_resource($process)) {
        throw new RuntimeException('failed to start merge CLI subprocess');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [
        'status' => proc_close($process),
        'output' => (is_string($stdout) ? $stdout : '') . (is_string($stderr) ? $stderr : ''),
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

function create_woocommerce_hpos_validator_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_wc_orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        status TEXT NOT NULL,
        type TEXT NOT NULL,
        total_amount TEXT NOT NULL
    )");
    $db->exec("CREATE TABLE wp_wc_order_addresses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        address_type TEXT NOT NULL,
        first_name TEXT NOT NULL
    )");
    $db->exec("CREATE TABLE wp_wc_orders_meta (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        meta_key TEXT NOT NULL,
        meta_value TEXT NOT NULL
    )");
    $db->exec("CREATE TABLE wp_woocommerce_order_items (
        order_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        order_item_name TEXT NOT NULL,
        order_item_type TEXT NOT NULL
    )");
    $db->exec("CREATE TABLE wp_woocommerce_order_itemmeta (
        meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_item_id INTEGER NOT NULL,
        meta_key TEXT NOT NULL,
        meta_value TEXT NOT NULL
    )");
    $db->exec("CREATE TABLE wp_wc_product_meta_lookup (
        product_id INTEGER PRIMARY KEY,
        sku TEXT NOT NULL
    )");
    $db->exec("CREATE TABLE wp_options (
        option_id INTEGER PRIMARY KEY AUTOINCREMENT,
        option_name TEXT NOT NULL,
        option_value TEXT NOT NULL,
        autoload TEXT NOT NULL DEFAULT 'yes'
    )");
    $db->exec("INSERT INTO wp_wc_orders (id, status, type, total_amount) VALUES (20, 'wc-processing', 'shop_order', '42.00')");
    $db->exec("INSERT INTO wp_wc_order_addresses (id, order_id, address_type, first_name) VALUES (21, 20, 'billing', 'Base')");
    $db->exec("INSERT INTO wp_woocommerce_order_items (order_item_id, order_id, order_item_name, order_item_type) VALUES (22, 20, 'Base product', 'line_item')");
    $db->exec("INSERT INTO wp_woocommerce_order_itemmeta (meta_id, order_item_id, meta_key, meta_value) VALUES (23, 22, '_product_id', '100')");
    $db->exec("INSERT INTO wp_wc_orders_meta (id, order_id, meta_key, meta_value) VALUES (24, 20, '_forkpress_note', 'Base note')");
    $db->exec("INSERT INTO wp_wc_product_meta_lookup (product_id, sku) VALUES (100, 'base-product')");
    $recent_orders = json_encode(['recent_order_ids' => [20]], JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('woocommerce_recent_order_ids', :value, 'yes')");
    $stmt->bindValue(':value', $recent_orders, SQLITE3_TEXT);
    $stmt->execute();
    $db->close();
}

function create_gravity_forms_validator_db(string $path): void {
    $db = open_db($path);
    $db->exec("CREATE TABLE wp_gf_form (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        is_active INTEGER NOT NULL DEFAULT 1
    )");
    $db->exec("CREATE TABLE wp_gf_form_meta (
        form_id INTEGER PRIMARY KEY,
        display_meta TEXT NOT NULL
    )");
    $db->exec("CREATE TABLE wp_gf_entry (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        form_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'active'
    )");
    $db->exec("CREATE TABLE wp_gf_entry_meta (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        form_id INTEGER NOT NULL,
        entry_id INTEGER NOT NULL,
        meta_key TEXT NOT NULL,
        meta_value TEXT NOT NULL
    )");
    $db->exec("INSERT INTO wp_gf_form (id, title, is_active) VALUES (30, 'Contact', 1)");
    $display_meta = json_encode([
        'fields' => [
            ['id' => 5, 'label' => 'Name', 'type' => 'text'],
        ],
    ], JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare('INSERT INTO wp_gf_form_meta (form_id, display_meta) VALUES (30, :display_meta)');
    $stmt->bindValue(':display_meta', $display_meta, SQLITE3_TEXT);
    $stmt->execute();
    $db->exec("INSERT INTO wp_gf_entry (id, form_id, status) VALUES (40, 30, 'active')");
    $db->exec("INSERT INTO wp_gf_entry_meta (id, form_id, entry_id, meta_key, meta_value) VALUES (41, 30, 40, '5', 'Base field value')");
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
    $file_path_raw = (string)$row['file_path'];
    $file_path = str_replace('\\', '/', $file_path_raw);
    $file_safe = $file_path !== '' &&
        !str_starts_with($file_path, '/') &&
        preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:\/\//', $file_path) !== 1 &&
        preg_match('/^[A-Za-z]:\//', $file_path) !== 1 &&
        !str_contains($file_path_raw, '\\') &&
        !in_array('..', explode('/', $file_path), true);
    if (!$file_safe || !is_file($target_root . '/' . $file_path)) {
        $findings[] = [
            'plugin' => 'forkpress-plugin-graph',
            'object' => 'child:' . $child_id,
            'reason' => 'plugin child references a missing or unsafe file',
            'type' => 'plugin-graph-file-drift',
            'tables' => ['plugin_graph_child'],
            'paths' => [$file_path],
            'validator' => 'forkpress-plugin-graph@1',
            'severity' => 'error',
            'logical_identity' => [
                'kind' => 'plugin-child',
                'child_id' => $child_id,
            ],
            'resolution_policy' => 'review-only',
            'suggested_action' => 'Restore or repair the plugin-owned file reference after review',
            'manual_review_reason' => 'ForkPress cannot synthesize plugin-owned files from a validator finding',
            'candidate' => [
                'child_id' => $child_id,
                'file_path' => $file_path,
                'file_safe' => $file_safe,
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
    $source_db->exec("INSERT INTO plugin_graph_child (parent_id, graph_json, file_path) VALUES ($parent_id, '{}', 'https://example.test/plugin-validator-url.dat')");
    $url_child_id = (int)$source_db->lastInsertRowID();
    $source_db->exec("UPDATE plugin_graph_child SET graph_json = '{\"child_id\":$url_child_id,\"parent_id\":$parent_id}' WHERE child_id = $url_child_id");
    $source_db->exec("INSERT INTO plugin_graph_child (parent_id, graph_json, file_path) VALUES ($parent_id, '{}', 'C:/plugin-assets/plugin-validator-drive.dat')");
    $drive_child_id = (int)$source_db->lastInsertRowID();
    $source_db->exec("UPDATE plugin_graph_child SET graph_json = '{\"child_id\":$drive_child_id,\"parent_id\":$parent_id}' WHERE child_id = $drive_child_id");
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
    assert_same((int)($result['plugin_validator_conflicts'] ?? 0), 4, 'plugin validator records JSON, missing-file, and unsafe-file graph conflicts');
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
    assert_same(count($audit['conflicts']), 4, 'plugin validator conflicts are visible in plugin audit scope');
    $preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $audit['conflicts']));
    assert_true(str_contains($preview, 'plugin-validator-missing.dat'), 'plugin audit exposes missing plugin file context');
    assert_true(str_contains($preview, 'https://example.test/plugin-validator-url.dat'), 'plugin audit exposes unsafe URL plugin file context');
    assert_true(str_contains($preview, 'C:/plugin-assets/plugin-validator-drive.dat'), 'plugin audit exposes unsafe drive-letter plugin file context');
    assert_true(str_contains($preview, '"child_id":9999'), 'plugin audit exposes mismatched JSON graph context');
    $file_audit_conflicts = array_values(array_filter($audit['conflicts'], fn($conflict) => ($conflict['conflict_type'] ?? '') === 'plugin-graph-file-drift'));
    assert_same(count($file_audit_conflicts), 3, 'plugin audit exposes the file validator conflicts as focused records');
    $missing_file_audit_conflicts = array_values(array_filter(
        $file_audit_conflicts,
        fn(array $conflict): bool => ($conflict['plugin_files'] ?? null) === ['wp-content/uploads/plugin-validator-missing.dat']
    ));
    assert_same(count($missing_file_audit_conflicts), 1, 'plugin audit exposes the missing file conflict as a focused record');
    $missing_file_audit_conflict = $missing_file_audit_conflicts[0];
    assert_same($missing_file_audit_conflict['plugin'] ?? null, 'forkpress-plugin-graph', 'plugin audit exposes the validator plugin as a first-class field');
    assert_same($missing_file_audit_conflict['plugin_object'] ?? null, 'child:' . $child_id, 'plugin audit exposes the validator object as a first-class field');
    assert_same($missing_file_audit_conflict['plugin_validator'] ?? null, 'forkpress-plugin-graph@1', 'plugin audit exposes the validator version as a first-class field');
    assert_same($missing_file_audit_conflict['plugin_severity'] ?? null, 'error', 'plugin audit exposes validator severity as a first-class field');
    assert_same($missing_file_audit_conflict['plugin_tables'] ?? null, ['plugin_graph_child'], 'plugin audit exposes plugin-owned tables as structured fields');
    assert_same($missing_file_audit_conflict['plugin_files'] ?? null, ['wp-content/uploads/plugin-validator-missing.dat'], 'plugin audit normalizes validator paths into structured plugin files');
    $missing_file_filter_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'plugin_file' => 'wp-content/uploads/plugin-validator-missing.dat',
    ]);
    assert_same(count($missing_file_filter_audit['conflicts']), 1, 'plugin audit can filter conflicts by validator-reported file path');
    assert_same(
        $missing_file_filter_audit['conflicts'][0]['plugin_files'] ?? null,
        ['wp-content/uploads/plugin-validator-missing.dat'],
        'plugin file filter keeps the matching plugin file context'
    );
    $missing_file_cli_filter = run_merge_cli([
        'audit',
        '--metadata-db', $metadata,
        '--run', (string)$result['run_id'],
        '--scope', 'plugin',
        '--records', 'conflicts',
        '--plugin-file', 'wp-content/uploads/plugin-validator-missing.dat',
        '--format', 'json',
    ]);
    assert_same($missing_file_cli_filter['status'], 0, 'plugin audit CLI accepts --plugin-file');
    $missing_file_cli_decoded = json_decode($missing_file_cli_filter['output'], true);
    assert_same(count($missing_file_cli_decoded['conflicts'] ?? []), 1, 'plugin audit CLI filters conflicts by validator-reported file path');
    $unsafe_file_paths = [];
    foreach ($file_audit_conflicts as $conflict) {
        $payload = cow_merge_decode_payload_json((string)($conflict['chosen_payload'] ?? ''), 'plugin graph file conflict');
        if (($payload['candidate']['file_safe'] ?? null) === false) {
            $unsafe_file_paths[] = (string)($payload['candidate']['file_path'] ?? '');
        }
    }
    sort($unsafe_file_paths);
    assert_same(
        $unsafe_file_paths,
        ['C:/plugin-assets/plugin-validator-drive.dat', 'https://example.test/plugin-validator-url.dat'],
        'plugin audit records URL and drive-letter plugin file references as unsafe'
    );
    assert_same($missing_file_audit_conflict['plugin_resolution_policy'] ?? null, 'review-only', 'plugin audit exposes validator review policy as a first-class field');
    assert_true(
        str_contains((string)($missing_file_audit_conflict['plugin_manual_review_reason'] ?? ''), 'cannot synthesize plugin-owned files'),
        'plugin audit exposes validator manual-review guidance as a first-class field'
    );
    $missing_file_conflict_id = (int)$missing_file_audit_conflict['id'];
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $missing_file_conflict_id, 'source', true, 'generic source repair should stay blocked', 'cow-test'),
        'plugin validator conflicts cannot be resolved by generic merge-resolve',
        'plugin validator conflicts remain blocked from generic source/target resolution'
    );
    write_test_file($target_root . '/wp-content/uploads/plugin-validator-missing.dat', 'external plugin driver restored missing file');
    $driver_resolution = cow_merge_record_plugin_driver_resolution(
        $metadata,
        $missing_file_conflict_id,
        'forkpress-plugin-graph-driver@1',
        [
            'status' => 'repaired',
            'restored_file' => 'wp-content/uploads/plugin-validator-missing.dat',
        ],
        null,
        true,
        'plugin driver restored the missing file reference and validated the object graph',
        'cow-test-driver'
    );
    assert_same($driver_resolution['status'], 'applied', 'plugin driver resolution records an applied plugin repair');
    assert_same($driver_resolution['choice'], 'plugin-driver', 'plugin driver resolution uses an explicit plugin-driver choice');
    $driver_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'conflict_id' => (string)$missing_file_conflict_id,
    ]);
    assert_same($driver_audit['conflicts'][0]['lifecycle_state'] ?? null, 'resolved', 'plugin driver resolution closes the plugin conflict lifecycle');
    assert_same((int)($driver_audit['conflicts'][0]['latest_resolution_applied'] ?? 0), 1, 'plugin audit exposes the applied plugin driver resolution');
    assert_same($driver_audit['conflicts'][0]['latest_resolution_choice'] ?? null, 'plugin-driver', 'plugin audit preserves the plugin-driver resolution choice');
    $driver_resolution_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'resolutions',
        'plugin_object' => 'child:' . $child_id,
    ]);
    $driver_resolution_rows = array_values(array_filter(
        $driver_resolution_audit['resolutions'],
        fn(array $resolution): bool => (int)($resolution['id'] ?? 0) === (int)$driver_resolution['resolution_id']
    ));
    assert_same(count($driver_resolution_rows), 1, 'plugin driver resolution is visible in plugin-scoped resolution audit');
    assert_same($driver_resolution_rows[0]['choice'] ?? null, 'plugin-driver', 'plugin resolution audit exposes the plugin-driver choice');
    $driver_result_payload = cow_merge_decode_payload_json((string)($driver_resolution_rows[0]['chosen_payload'] ?? ''), 'plugin driver audit result');
    assert_same($driver_result_payload['driver'] ?? null, 'forkpress-plugin-graph-driver@1', 'plugin resolution audit records the driver identity');
    assert_same($driver_result_payload['result']['status'] ?? null, 'repaired', 'plugin resolution audit records driver result evidence');
    $url_file_audit_conflicts = array_values(array_filter(
        $file_audit_conflicts,
        fn(array $conflict): bool => ($conflict['plugin_files'] ?? null) === ['https://example.test/plugin-validator-url.dat']
    ));
    assert_same(count($url_file_audit_conflicts), 1, 'plugin audit exposes the unsafe URL file conflict as a focused record');
    $url_file_filter_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'plugin_file' => 'https://example.test/plugin-validator-url.dat',
    ]);
    assert_same(count($url_file_filter_audit['conflicts']), 1, 'plugin audit can filter unsafe URL file conflicts by plugin file path');
    $url_file_event_filter_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflict-events',
        'plugin_file' => 'https://example.test/plugin-validator-url.dat',
    ]);
    assert_true(count($url_file_event_filter_audit['conflict_events']) >= 1, 'plugin file filter applies to plugin conflict event queues');
    $url_file_conflict_id = (int)$url_file_audit_conflicts[0]['id'];
    $plugin_driver_path = $tmp . '/forkpress-plugin-graph-driver.php';
    write_test_file($plugin_driver_path, <<<'PHP'
<?php
$context_path = (string)getenv('FORKPRESS_MERGE_CONFLICT_JSON');
$context = is_file($context_path) ? json_decode((string)file_get_contents($context_path), true) : null;
$target_db = (string)getenv('FORKPRESS_MERGE_TARGET_DB');
$target_root = rtrim((string)getenv('FORKPRESS_MERGE_TARGET_ROOT'), '/');
$child_id = (int)str_replace('child:', '', (string)getenv('FORKPRESS_MERGE_PLUGIN_OBJECT'));
$repaired_path = 'wp-content/uploads/plugin-driver-url-repaired.dat';
$db = new SQLite3($target_db);
$stmt = $db->prepare('UPDATE plugin_graph_child SET file_path = :file_path WHERE child_id = :child_id');
$stmt->bindValue(':file_path', $repaired_path, SQLITE3_TEXT);
$stmt->bindValue(':child_id', $child_id, SQLITE3_INTEGER);
$stmt->execute();
@mkdir(dirname($target_root . '/' . $repaired_path), 0777, true);
file_put_contents($target_root . '/' . $repaired_path, 'plugin driver repaired URL file reference');
$ok = is_array($context)
    && (string)getenv('FORKPRESS_MERGE_PLUGIN') === 'forkpress-plugin-graph'
    && (string)getenv('FORKPRESS_MERGE_PLUGIN_OBJECT') === (string)($context['plugin']['object'] ?? '')
    && (int)getenv('FORKPRESS_MERGE_CONFLICT_ID') === (int)($context['conflict_id'] ?? 0)
    && (string)getenv('FORKPRESS_MERGE_TARGET_DB') === (string)($context['merge']['target_db'] ?? '');
echo json_encode([
    'status' => 'applied',
    'result' => [
        'context_ok' => $ok,
        'plugin' => getenv('FORKPRESS_MERGE_PLUGIN'),
        'object' => getenv('FORKPRESS_MERGE_PLUGIN_OBJECT'),
        'conflict_type' => getenv('FORKPRESS_MERGE_CONFLICT_TYPE'),
        'repaired_file' => $repaired_path,
    ],
    'previous' => [
        'conflict_type' => $context['conflict_type'] ?? null,
        'plugin_file' => $context['payloads']['chosen']['files'][0] ?? null,
    ],
    'note' => 'driver accepted the unsafe URL file finding after plugin-specific repair',
], JSON_UNESCAPED_SLASHES);
PHP);
    $driver_cli = run_merge_cli([
        'run-plugin-driver',
        '--metadata-db', $metadata,
        '--id', (string)$url_file_conflict_id,
        '--driver', $plugin_driver_path,
        '--format', 'json',
    ]);
    assert_same($driver_cli['status'], 0, 'plugin driver runner CLI records an applied driver result');
    $driver_cli_result = json_decode($driver_cli['output'], true);
    assert_same($driver_cli_result['driver_status'] ?? null, 'applied', 'plugin driver runner exposes the emitted driver status');
    assert_true(is_file((string)($driver_cli_result['context_file'] ?? '')), 'plugin driver runner materializes conflict context for the driver');
    $runner_resolution_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'resolutions',
        'resolution_choice' => 'plugin-driver',
        'plugin_object' => 'child:' . $url_child_id,
    ]);
    $runner_resolution_rows = array_values(array_filter(
        $runner_resolution_audit['resolutions'],
        fn(array $resolution): bool => (int)($resolution['conflict_id'] ?? 0) === $url_file_conflict_id
    ));
    assert_same(count($runner_resolution_rows), 1, 'plugin driver runner resolution is filterable by plugin-driver choice');
    $runner_plugin_file_resolution_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'resolutions',
        'plugin_file' => 'https://example.test/plugin-validator-url.dat',
    ]);
    assert_same(count($runner_plugin_file_resolution_audit['resolutions']), 1, 'plugin file filter applies to plugin-driver resolution queues');
    assert_same(
        $runner_plugin_file_resolution_audit['resolutions'][0]['plugin_files'] ?? null,
        ['https://example.test/plugin-validator-url.dat'],
        'plugin file resolution filter keeps the matching plugin file context'
    );
    $runner_plugin_file_resolution_group_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'resolutions',
        'group_by' => 'plugin-file',
    ]);
    $plugin_file_resolution_group_counts = [];
    foreach ($runner_plugin_file_resolution_group_audit['resolution_groups'] as $group) {
        $plugin_file_resolution_group_counts[(string)$group['group_key']] = (int)$group['resolution_count'];
    }
    assert_same($plugin_file_resolution_group_counts['https://example.test/plugin-validator-url.dat'] ?? 0, 1, 'plugin file grouping applies to plugin-driver resolution queues');
    $runner_result_payload = cow_merge_decode_payload_json((string)($runner_resolution_rows[0]['chosen_payload'] ?? ''), 'plugin driver runner audit result');
    assert_same($runner_result_payload['result']['context_ok'] ?? null, true, 'plugin driver runner passes conflict and merge context to the driver');
    $drive_file_audit_conflicts = array_values(array_filter(
        $file_audit_conflicts,
        fn(array $conflict): bool => ($conflict['plugin_files'] ?? null) === ['C:/plugin-assets/plugin-validator-drive.dat']
    ));
    assert_same(count($drive_file_audit_conflicts), 1, 'plugin audit exposes the unsafe drive-letter file conflict as a focused record');
    $drive_file_conflict_id = (int)$drive_file_audit_conflicts[0]['id'];
    $plugin_driver_resolution_count = (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE choice = 'plugin-driver'");
    $non_clearing_driver_record = run_merge_cli([
        'record-plugin-driver-resolution',
        '--metadata-db', $metadata,
        '--id', (string)$drive_file_conflict_id,
        '--driver', 'forkpress-plugin-graph-driver@non-clearing-record',
        '--result-json', '{"claimed":"external repair without validator proof"}',
        '--applied',
        '--format', 'json',
    ]);
    assert_true($non_clearing_driver_record['status'] !== 0, 'plugin driver recorder rejects applied repairs that do not clear the validator finding');
    assert_true(
        str_contains($non_clearing_driver_record['output'], 'did not clear validator conflict #' . $drive_file_conflict_id),
        'plugin driver recorder explains uncleared validator findings'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE choice = 'plugin-driver'"),
        $plugin_driver_resolution_count,
        'non-clearing plugin driver recorder records no plugin-driver resolution'
    );
    $non_clearing_plugin_driver_path = $tmp . '/forkpress-plugin-graph-driver-non-clearing.php';
    write_test_file($non_clearing_plugin_driver_path, <<<'PHP'
<?php
echo json_encode([
    'status' => 'applied',
    'result' => ['claimed' => 'repaired without changing the validator-owned graph'],
], JSON_UNESCAPED_SLASHES);
PHP);
    $non_clearing_driver_cli = run_merge_cli([
        'run-plugin-driver',
        '--metadata-db', $metadata,
        '--id', (string)$drive_file_conflict_id,
        '--driver', $non_clearing_plugin_driver_path,
        '--format', 'json',
    ]);
    assert_true($non_clearing_driver_cli['status'] !== 0, 'plugin driver runner rejects applied repairs that do not clear the validator finding');
    assert_true(
        str_contains($non_clearing_driver_cli['output'], 'did not clear validator conflict #' . $drive_file_conflict_id),
        'plugin driver runner explains uncleared validator findings'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE choice = 'plugin-driver'"),
        $plugin_driver_resolution_count,
        'non-clearing plugin driver records no plugin-driver resolution'
    );
    $drive_child_graph_before_validated_driver = (string)scalar($target, "SELECT graph_json FROM plugin_graph_child WHERE child_id = $drive_child_id");
    $validated_mutating_driver_path = $tmp . '/forkpress-plugin-graph-driver-validated-mutating.php';
    write_test_file($validated_mutating_driver_path, <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$db->exec("UPDATE plugin_graph_child SET graph_json = '{\"driver\":\"validated mutation\"}' WHERE child_id = " . (int)str_replace('child:', '', (string)getenv('FORKPRESS_MERGE_PLUGIN_OBJECT')));
$target_root = rtrim((string)getenv('FORKPRESS_MERGE_TARGET_ROOT'), '/');
@mkdir($target_root . '/wp-content/uploads', 0777, true);
file_put_contents($target_root . '/wp-content/uploads/plugin-driver-validated-mutation.dat', 'validated mutation');
echo json_encode([
    'status' => 'validated',
    'result' => ['validated' => true],
], JSON_UNESCAPED_SLASHES);
PHP);
    $validated_mutating_cli = run_merge_cli([
        'run-plugin-driver',
        '--metadata-db', $metadata,
        '--id', (string)$drive_file_conflict_id,
        '--driver', $validated_mutating_driver_path,
        '--format', 'json',
    ]);
    assert_true($validated_mutating_cli['status'] !== 0, 'plugin driver runner rejects validated status with target mutations');
    assert_true(str_contains($validated_mutating_cli['output'], 'validated status but mutated target DB or files'), 'plugin driver runner explains validated mutation policy');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE choice = 'plugin-driver'"),
        $plugin_driver_resolution_count,
        'validated mutating plugin driver records no resolution'
    );
    assert_same(
        (string)scalar($target, "SELECT graph_json FROM plugin_graph_child WHERE child_id = $drive_child_id"),
        $drive_child_graph_before_validated_driver,
        'validated mutating plugin driver rolls back target database mutations'
    );
    assert_true(
        !is_file($target_root . '/wp-content/uploads/plugin-driver-validated-mutation.dat'),
        'validated mutating plugin driver rolls back target filesystem mutations'
    );
    $drive_child_graph_before_failed_driver = (string)scalar($target, "SELECT graph_json FROM plugin_graph_child WHERE child_id = $drive_child_id");
    $failing_plugin_driver_path = $tmp . '/forkpress-plugin-graph-driver-failed.php';
    write_test_file($failing_plugin_driver_path, <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$db->exec("UPDATE plugin_graph_child SET graph_json = '{\"driver\":\"failed mutation\"}' WHERE child_id = " . (int)str_replace('child:', '', (string)getenv('FORKPRESS_MERGE_PLUGIN_OBJECT')));
$target_root = rtrim((string)getenv('FORKPRESS_MERGE_TARGET_ROOT'), '/');
@mkdir($target_root . '/wp-content/uploads', 0777, true);
file_put_contents($target_root . '/wp-content/uploads/plugin-driver-failed-mutation.dat', 'failed mutation');
echo json_encode([
    'status' => 'failed',
    'reason' => 'driver could not prove the plugin graph repair',
], JSON_UNESCAPED_SLASHES);
PHP);
    $failing_driver_cli = run_merge_cli([
        'run-plugin-driver',
        '--metadata-db', $metadata,
        '--id', (string)$drive_file_conflict_id,
        '--driver', $failing_plugin_driver_path,
        '--format', 'json',
    ]);
    assert_true($failing_driver_cli['status'] !== 0, 'plugin driver runner rejects failed driver status');
    assert_true(str_contains($failing_driver_cli['output'], 'could not prove the plugin graph repair'), 'plugin driver runner explains failed driver status');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE choice = 'plugin-driver'"),
        $plugin_driver_resolution_count,
        'failed plugin driver status records no plugin-driver resolution'
    );
    assert_same(
        (string)scalar($target, "SELECT graph_json FROM plugin_graph_child WHERE child_id = $drive_child_id"),
        $drive_child_graph_before_failed_driver,
        'failed plugin driver rolls back target database mutations'
    );
    assert_true(
        !is_file($target_root . '/wp-content/uploads/plugin-driver-failed-mutation.dat'),
        'failed plugin driver rolls back target filesystem mutations'
    );
    $target_validator_path = $target_root . '/wp-content/mu-plugins/forkpress-merge-validator.php';
    $target_validator_before_postflight_failure = (string)file_get_contents($target_validator_path);
    $target_validator_with_postflight_failure = preg_replace('/^<\?php\s*/', <<<'PHP'
<?php
$target_root_for_postflight_failure = rtrim((string)getenv('FORKPRESS_MERGE_TARGET_ROOT'), '/');
if (is_file($target_root_for_postflight_failure . '/wp-content/uploads/plugin-driver-postflight-validator-failure.dat')) {
    echo json_encode([
        'status' => 'failed',
        'reason' => 'postflight validator could not prove the driver repair',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

PHP, $target_validator_before_postflight_failure, 1);
    if (!is_string($target_validator_with_postflight_failure)) {
        throw new RuntimeException('failed to prepare postflight failure validator fixture');
    }
    write_test_file($target_validator_path, $target_validator_with_postflight_failure);
    $target_validator_before_postflight_failure = (string)file_get_contents($target_validator_path);
    $drive_child_graph_before_postflight_failure = (string)scalar($target, "SELECT graph_json FROM plugin_graph_child WHERE child_id = $drive_child_id");
    $postflight_failing_plugin_driver_path = $tmp . '/forkpress-plugin-graph-driver-postflight-validator-failure.php';
    write_test_file($postflight_failing_plugin_driver_path, <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$child_id = (int)str_replace('child:', '', (string)getenv('FORKPRESS_MERGE_PLUGIN_OBJECT'));
$db->exec("UPDATE plugin_graph_child SET graph_json = '{\"driver\":\"postflight validator failure\"}' WHERE child_id = " . $child_id);
$target_root = rtrim((string)getenv('FORKPRESS_MERGE_TARGET_ROOT'), '/');
@mkdir($target_root . '/wp-content/uploads', 0777, true);
file_put_contents($target_root . '/wp-content/uploads/plugin-driver-postflight-validator-failure.dat', 'postflight validator failure mutation');
echo json_encode([
    'status' => 'applied',
    'result' => ['postflight_validator_failure' => true],
], JSON_UNESCAPED_SLASHES);
PHP);
    $postflight_failing_driver_cli = run_merge_cli([
        'run-plugin-driver',
        '--metadata-db', $metadata,
        '--id', (string)$drive_file_conflict_id,
        '--driver', $postflight_failing_plugin_driver_path,
        '--format', 'json',
    ]);
    assert_true($postflight_failing_driver_cli['status'] !== 0, 'plugin driver runner rejects applied repairs when postflight validator fails');
    assert_true(
        str_contains($postflight_failing_driver_cli['output'], 'postflight validator could not prove the driver repair'),
        'plugin driver runner explains postflight validator failures'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE choice = 'plugin-driver'"),
        $plugin_driver_resolution_count,
        'postflight validator failure records no plugin-driver resolution'
    );
    assert_same(
        (string)scalar($target, "SELECT graph_json FROM plugin_graph_child WHERE child_id = $drive_child_id"),
        $drive_child_graph_before_postflight_failure,
        'postflight validator failure rolls back target database mutations'
    );
    assert_true(
        !is_file($target_root . '/wp-content/uploads/plugin-driver-postflight-validator-failure.dat'),
        'postflight validator failure rolls back target filesystem mutations'
    );
    assert_same(
        (string)file_get_contents($target_validator_path),
        $target_validator_before_postflight_failure,
        'postflight validator failure restores the discovered validator file'
    );
    $drive_child_graph_before_deleted_validator = (string)scalar($target, "SELECT graph_json FROM plugin_graph_child WHERE child_id = $drive_child_id");
    $deleted_validator_driver_path = $tmp . '/forkpress-plugin-graph-driver-deletes-validator.php';
    write_test_file($deleted_validator_driver_path, <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$child_id = (int)str_replace('child:', '', (string)getenv('FORKPRESS_MERGE_PLUGIN_OBJECT'));
$repaired_path = 'wp-content/uploads/plugin-driver-deleted-validator-repair.dat';
$stmt = $db->prepare('UPDATE plugin_graph_child SET graph_json = :graph_json, file_path = :file_path WHERE child_id = :child_id');
$stmt->bindValue(':graph_json', json_encode([
    'child_id' => $child_id,
    'parent_id' => (int)$db->querySingle('SELECT parent_id FROM plugin_graph_child WHERE child_id = ' . $child_id),
], JSON_UNESCAPED_SLASHES), SQLITE3_TEXT);
$stmt->bindValue(':file_path', $repaired_path, SQLITE3_TEXT);
$stmt->bindValue(':child_id', $child_id, SQLITE3_INTEGER);
$stmt->execute();
$target_root = rtrim((string)getenv('FORKPRESS_MERGE_TARGET_ROOT'), '/');
@mkdir($target_root . '/wp-content/uploads', 0777, true);
file_put_contents($target_root . '/' . $repaired_path, 'driver repaired but removed validator');
unlink($target_root . '/wp-content/mu-plugins/forkpress-merge-validator.php');
echo json_encode([
    'status' => 'applied',
    'result' => ['deleted_validator' => true],
], JSON_UNESCAPED_SLASHES);
PHP);
    $deleted_validator_driver_cli = run_merge_cli([
        'run-plugin-driver',
        '--metadata-db', $metadata,
        '--id', (string)$drive_file_conflict_id,
        '--driver', $deleted_validator_driver_path,
        '--format', 'json',
    ]);
    assert_true($deleted_validator_driver_cli['status'] !== 0, 'plugin driver runner rejects applied repairs that remove the discovered validator');
    assert_true(
        str_contains($deleted_validator_driver_cli['output'], 'validator file is missing'),
        'plugin driver runner explains missing discovered validator postflight'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE choice = 'plugin-driver'"),
        $plugin_driver_resolution_count,
        'deleted-validator plugin driver records no plugin-driver resolution'
    );
    assert_same(
        (string)scalar($target, "SELECT graph_json FROM plugin_graph_child WHERE child_id = $drive_child_id"),
        $drive_child_graph_before_deleted_validator,
        'deleted-validator plugin driver rolls back target database mutations'
    );
    assert_true(
        !is_file($target_root . '/wp-content/uploads/plugin-driver-deleted-validator-repair.dat'),
        'deleted-validator plugin driver rolls back target filesystem mutations'
    );
    assert_same(
        (string)file_get_contents($target_validator_path),
        $target_validator_before_postflight_failure,
        'deleted-validator plugin driver restores the discovered validator file'
    );
    $drive_child_graph_before_tampered_validator = (string)scalar($target, "SELECT graph_json FROM plugin_graph_child WHERE child_id = $drive_child_id");
    $tampered_validator_driver_path = $tmp . '/forkpress-plugin-graph-driver-tampers-validator.php';
    write_test_file($tampered_validator_driver_path, <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$child_id = (int)str_replace('child:', '', (string)getenv('FORKPRESS_MERGE_PLUGIN_OBJECT'));
$repaired_path = 'wp-content/uploads/plugin-driver-tampered-validator-repair.dat';
$stmt = $db->prepare('UPDATE plugin_graph_child SET graph_json = :graph_json, file_path = :file_path WHERE child_id = :child_id');
$stmt->bindValue(':graph_json', json_encode([
    'child_id' => $child_id,
    'parent_id' => (int)$db->querySingle('SELECT parent_id FROM plugin_graph_child WHERE child_id = ' . $child_id),
], JSON_UNESCAPED_SLASHES), SQLITE3_TEXT);
$stmt->bindValue(':file_path', $repaired_path, SQLITE3_TEXT);
$stmt->bindValue(':child_id', $child_id, SQLITE3_INTEGER);
$stmt->execute();
$target_root = rtrim((string)getenv('FORKPRESS_MERGE_TARGET_ROOT'), '/');
@mkdir($target_root . '/wp-content/uploads', 0777, true);
file_put_contents($target_root . '/' . $repaired_path, 'driver repaired but rewrote validator');
file_put_contents($target_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'VALIDATOR'
<?php
echo json_encode([
    'status' => 'valid',
    'findings' => [],
], JSON_UNESCAPED_SLASHES);
VALIDATOR);
echo json_encode([
    'status' => 'applied',
    'result' => ['tampered_validator' => true],
], JSON_UNESCAPED_SLASHES);
PHP);
    $tampered_validator_driver_cli = run_merge_cli([
        'run-plugin-driver',
        '--metadata-db', $metadata,
        '--id', (string)$drive_file_conflict_id,
        '--driver', $tampered_validator_driver_path,
        '--format', 'json',
    ]);
    assert_true($tampered_validator_driver_cli['status'] !== 0, 'plugin driver runner rejects applied repairs that rewrite the discovered validator');
    assert_true(
        str_contains($tampered_validator_driver_cli['output'], 'refusing to trust postflight validation'),
        'plugin driver runner explains tampered discovered validator postflight'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE choice = 'plugin-driver'"),
        $plugin_driver_resolution_count,
        'tampered-validator plugin driver records no plugin-driver resolution'
    );
    assert_same(
        (string)scalar($target, "SELECT graph_json FROM plugin_graph_child WHERE child_id = $drive_child_id"),
        $drive_child_graph_before_tampered_validator,
        'tampered-validator plugin driver rolls back target database mutations'
    );
    assert_true(
        !is_file($target_root . '/wp-content/uploads/plugin-driver-tampered-validator-repair.dat'),
        'tampered-validator plugin driver rolls back target filesystem mutations'
    );
    assert_same(
        (string)file_get_contents($target_validator_path),
        $target_validator_before_postflight_failure,
        'tampered-validator plugin driver restores the discovered validator file'
    );
    $pre_resolution_failpoint_driver_path = $tmp . '/forkpress-plugin-graph-driver-pre-resolution-failpoint.php';
    write_test_file($pre_resolution_failpoint_driver_path, <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$child_id = (int)str_replace('child:', '', (string)getenv('FORKPRESS_MERGE_PLUGIN_OBJECT'));
$db->exec("UPDATE plugin_graph_child SET graph_json = '{\"driver\":\"pre resolution mutation\"}', file_path = 'wp-content/uploads/plugin-driver-pre-resolution-repair.dat' WHERE child_id = " . $child_id);
$target_root = rtrim((string)getenv('FORKPRESS_MERGE_TARGET_ROOT'), '/');
@mkdir($target_root . '/wp-content/uploads', 0777, true);
file_put_contents($target_root . '/wp-content/uploads/plugin-driver-pre-resolution-repair.dat', 'pre resolution repair');
file_put_contents($target_root . '/wp-content/uploads/plugin-driver-pre-resolution-mutation.dat', 'pre resolution mutation');
echo json_encode([
    'status' => 'applied',
    'result' => ['pre_resolution' => true],
], JSON_UNESCAPED_SLASHES);
PHP);
    $drive_child_graph_before_pre_resolution_failpoint = (string)scalar($target, "SELECT graph_json FROM plugin_graph_child WHERE child_id = $drive_child_id");
    putenv('FORKPRESS_COW_MERGE_TEST_FAILPOINT=before-plugin-driver-resolution');
    putenv('FORKPRESS_COW_MERGE_TEST_FAILPOINT_ACTION=throw');
    $pre_resolution_failpoint_message = null;
    try {
        cow_merge_run_plugin_driver(
            $metadata,
            $drive_file_conflict_id,
            $pre_resolution_failpoint_driver_path,
            null,
            'cow-test'
        );
    } catch (Throwable $e) {
        $pre_resolution_failpoint_message = $e->getMessage();
    } finally {
        putenv('FORKPRESS_COW_MERGE_TEST_FAILPOINT');
        putenv('FORKPRESS_COW_MERGE_TEST_FAILPOINT_ACTION');
    }
    assert_true(
        $pre_resolution_failpoint_message !== null && str_contains($pre_resolution_failpoint_message, 'before-plugin-driver-resolution'),
        'plugin driver pre-resolution failpoint is surfaced to the caller'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE choice = 'plugin-driver'"),
        $plugin_driver_resolution_count,
        'plugin driver pre-resolution failpoint records no plugin-driver resolution'
    );
    assert_same(
        (string)scalar($target, "SELECT graph_json FROM plugin_graph_child WHERE child_id = $drive_child_id"),
        $drive_child_graph_before_pre_resolution_failpoint,
        'plugin driver pre-resolution failpoint rolls back target database mutations'
    );
    assert_true(
        !is_file($target_root . '/wp-content/uploads/plugin-driver-pre-resolution-mutation.dat'),
        'plugin driver pre-resolution failpoint rolls back target filesystem mutations'
    );
    $pre_resolution_kill_driver_path = $tmp . '/forkpress-plugin-graph-driver-pre-resolution-kill.php';
    write_test_file($pre_resolution_kill_driver_path, <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$child_id = (int)str_replace('child:', '', (string)getenv('FORKPRESS_MERGE_PLUGIN_OBJECT'));
$db->exec("UPDATE plugin_graph_child SET graph_json = '{\"driver\":\"pre resolution mutation\"}', file_path = 'wp-content/uploads/plugin-driver-pre-resolution-kill-repair.dat' WHERE child_id = " . $child_id);
$target_root = rtrim((string)getenv('FORKPRESS_MERGE_TARGET_ROOT'), '/');
@mkdir($target_root . '/wp-content/uploads', 0777, true);
file_put_contents($target_root . '/wp-content/uploads/plugin-driver-pre-resolution-kill-repair.dat', 'pre resolution kill repair');
file_put_contents($target_root . '/wp-content/uploads/plugin-driver-pre-resolution-kill-mutation.dat', 'pre resolution kill mutation');
echo json_encode([
    'status' => 'applied',
    'result' => ['pre_resolution_kill' => true],
], JSON_UNESCAPED_SLASHES);
PHP);
    $drive_child_graph_before_pre_resolution_kill = (string)scalar($target, "SELECT graph_json FROM plugin_graph_child WHERE child_id = $drive_child_id");
    $pre_resolution_kill = run_merge_cli_env(
        [
            'run-plugin-driver',
            '--metadata-db', $metadata,
            '--id', (string)$drive_file_conflict_id,
            '--driver', $pre_resolution_kill_driver_path,
            '--format', 'json',
        ],
        [
            'FORKPRESS_COW_MERGE_TEST_FAILPOINT' => 'before-plugin-driver-resolution',
            'FORKPRESS_COW_MERGE_TEST_FAILPOINT_ACTION' => 'kill',
        ]
    );
    assert_true($pre_resolution_kill['status'] !== 0, 'plugin driver pre-resolution process death exits unsuccessfully');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE choice = 'plugin-driver'"),
        $plugin_driver_resolution_count,
        'plugin driver pre-resolution process death records no plugin-driver resolution'
    );
    assert_same(
        (string)scalar($target, "SELECT graph_json FROM plugin_graph_child WHERE child_id = $drive_child_id"),
        '{"driver":"pre resolution mutation"}',
        'plugin driver pre-resolution process death can leave target database mutations before recovery'
    );
    clearstatcache(true, $target_root . '/wp-content/uploads/plugin-driver-pre-resolution-kill-mutation.dat');
    assert_true(
        is_file($target_root . '/wp-content/uploads/plugin-driver-pre-resolution-kill-mutation.dat'),
        'plugin driver pre-resolution process death can leave target filesystem mutations before recovery'
    );
    $crash_report = run_merge_cli([
        'recover-crash',
        '--metadata-db', $metadata,
        '--format', 'json',
    ]);
    assert_same($crash_report['status'], 0, 'plugin driver pre-resolution process death leaves inspectable crash recovery state');
    $crash_report_json = json_decode($crash_report['output'], true);
    assert_same($crash_report_json['pending'] ?? null, 1, 'plugin driver process-death recovery reports one pending artifact');
    assert_same(
        $crash_report_json['artifacts'][0]['checkpoint'] ?? null,
        'plugin-driver-resolution',
        'plugin driver process-death recovery records the driver-resolution checkpoint'
    );
    assert_same(
        $crash_report_json['artifacts'][0]['target_root'] ?? null,
        $target_root,
        'plugin driver process-death recovery records the target filesystem root'
    );
    assert_true(
        is_array($crash_report_json['artifacts'][0]['filesystem_snapshot'] ?? null),
        'plugin driver process-death recovery records a target filesystem snapshot'
    );
    assert_true(
        !array_key_exists(
            'wp-content/uploads/plugin-driver-pre-resolution-kill-mutation.dat',
            $crash_report_json['artifacts'][0]['filesystem_snapshot']['entries'] ?? []
        ),
        'plugin driver process-death recovery snapshot predates the driver-created file'
    );
    $crash_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 5, ['records' => 'crash-recovery']);
    assert_same($crash_audit['filters']['records'], 'crash-recovery', 'merge audit can focus on pending crash recovery artifacts');
    assert_same(count($crash_audit['crash_recovery']), 1, 'merge audit exposes pending plugin-driver crash recovery artifacts');
    assert_same(
        $crash_audit['crash_recovery'][0]['checkpoint'] ?? null,
        'plugin-driver-resolution',
        'merge audit exposes the plugin-driver crash recovery checkpoint'
    );
    ob_start();
    cow_merge_print_audit_text($crash_audit);
    $crash_audit_text = ob_get_clean();
    assert_true(
        str_contains($crash_audit_text, 'filters:   records=crash-recovery') &&
        str_contains($crash_audit_text, 'checkpoint=plugin-driver-resolution') &&
        str_contains($crash_audit_text, '--restore-target-db --restore-files'),
        'merge audit text prints pending crash recovery artifacts and restore flags'
    );
    assert_throws(
        fn() => cow_merge_audit_report($metadata, null, 5, ['records' => 'crash-recovery', 'scope' => 'files']),
        '--records crash-recovery cannot be combined with --scope',
        'crash recovery audit rejects unrelated filters'
    );
    $blocked_driver_retry = run_merge_cli([
        'run-plugin-driver',
        '--metadata-db', $metadata,
        '--id', (string)$drive_file_conflict_id,
        '--driver', $pre_resolution_kill_driver_path,
        '--format', 'json',
    ]);
    assert_true($blocked_driver_retry['status'] !== 0, 'plugin driver runner blocks retries while crash recovery is pending');
    assert_true(
        str_contains($blocked_driver_retry['output'], 'pending COW merge crash recovery artifact'),
        'plugin driver pending crash recovery error points to recovery before retry'
    );
    $restore_crash = run_merge_cli([
        'recover-crash',
        '--metadata-db', $metadata,
        '--restore-target-db',
        '--restore-files',
        '--format', 'json',
    ]);
    assert_same($restore_crash['status'], 0, 'plugin driver process-death recovery restores target DB and files');
    $restore_crash_json = json_decode($restore_crash['output'], true);
    assert_same($restore_crash_json['restored'] ?? null, 1, 'plugin driver process-death recovery restores one artifact');
    assert_same($restore_crash_json['pending'] ?? null, 0, 'plugin driver process-death recovery clears the pending artifact');
    assert_same(
        (string)scalar($target, "SELECT graph_json FROM plugin_graph_child WHERE child_id = $drive_child_id"),
        $drive_child_graph_before_pre_resolution_kill,
        'plugin driver process-death recovery restores target database mutations'
    );
    clearstatcache(true, $target_root . '/wp-content/uploads/plugin-driver-pre-resolution-kill-mutation.dat');
    assert_true(
        !is_file($target_root . '/wp-content/uploads/plugin-driver-pre-resolution-kill-mutation.dat'),
        'plugin driver process-death recovery restores target filesystem mutations'
    );
    $rollback_failure_before = (int)scalar($metadata, 'SELECT COUNT(*) FROM merge_rollback_failures');
    $GLOBALS['cow_merge_test_hooks']['before_file_root_snapshot_restore'] = [
        static function (array $snapshot, string $restore_root) use ($target_root): void {
            if ($restore_root === $target_root) {
                throw new RuntimeException('forced plugin driver filesystem rollback failure');
            }
        },
    ];
    $rollback_failure_message = null;
    try {
        cow_merge_run_plugin_driver(
            $metadata,
            $drive_file_conflict_id,
            $failing_plugin_driver_path,
            null,
            'cow-test'
        );
    } catch (Throwable $e) {
        $rollback_failure_message = $e->getMessage();
    } finally {
        unset($GLOBALS['cow_merge_test_hooks']['before_file_root_snapshot_restore']);
    }
    assert_true(
        $rollback_failure_message !== null && str_contains($rollback_failure_message, 'plugin driver rollback failed'),
        'plugin driver rollback failure is surfaced to the caller'
    );
    assert_true(
        str_contains((string)$rollback_failure_message, 'forced plugin driver filesystem rollback failure'),
        'plugin driver rollback failure includes the rollback error'
    );
    assert_same(
        (int)scalar($metadata, 'SELECT COUNT(*) FROM merge_rollback_failures'),
        $rollback_failure_before + 1,
        'plugin driver rollback failure records rollback-failure metadata'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE choice = 'plugin-driver'"),
        $plugin_driver_resolution_count,
        'plugin driver rollback failure records no plugin-driver resolution'
    );
    $plugin_driver_rollback_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 5, ['records' => 'rollback-failures']);
    assert_same(count($plugin_driver_rollback_audit['rollback_failures']), 1, 'plugin driver rollback failure appears in rollback-failure audit exports');
    assert_true(
        str_contains($plugin_driver_rollback_audit['rollback_failures'][0]['original_failure'] ?? '', 'could not prove the plugin graph repair'),
        'plugin driver rollback-failure audit preserves the driver failure'
    );
    assert_true(
        str_contains($plugin_driver_rollback_audit['rollback_failures'][0]['rollback_failure'] ?? '', 'forced plugin driver filesystem rollback failure'),
        'plugin driver rollback-failure audit preserves the rollback failure'
    );
    $plugin_driver_rollback_artifact_path = (string)($plugin_driver_rollback_audit['rollback_failures'][0]['artifact_path'] ?? '');
    assert_true($plugin_driver_rollback_artifact_path !== '' && is_file($plugin_driver_rollback_artifact_path), 'plugin driver rollback failure preserves a JSONL artifact');
    $plugin_driver_rollback_artifact_lines = file($plugin_driver_rollback_artifact_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    assert_true(is_array($plugin_driver_rollback_artifact_lines) && count($plugin_driver_rollback_artifact_lines) === 1, 'plugin driver rollback failure writes one artifact record');
    $plugin_driver_rollback_artifact = json_decode((string)$plugin_driver_rollback_artifact_lines[0], true);
    assert_true(is_file($plugin_driver_rollback_artifact['artifacts']['target_db_snapshot']['backup'] ?? ''), 'plugin driver rollback failure artifact preserves target DB backup');
    assert_true(is_dir($plugin_driver_rollback_artifact['artifacts']['filesystem_snapshot']['stage_root'] ?? ''), 'plugin driver rollback failure artifact preserves filesystem snapshot backup root');
    $rollback_recovery = run_merge_cli([
        'recover-crash',
        '--metadata-db', $metadata,
        '--restore-target-db',
        '--restore-files',
        '--format', 'json',
    ]);
    assert_same($rollback_recovery['status'], 0, 'plugin driver rollback-failure crash artifact can be restored before later work');
    $rollback_recovery_json = json_decode($rollback_recovery['output'], true);
    assert_same($rollback_recovery_json['pending'] ?? null, 0, 'plugin driver rollback-failure crash artifact is cleared after restore');
    @unlink($target_root . '/wp-content/uploads/plugin-driver-failed-mutation.dat');
    $malformed_plugin_driver_path = $tmp . '/forkpress-plugin-graph-driver-malformed.php';
    write_test_file($malformed_plugin_driver_path, <<<'PHP'
<?php
echo json_encode([
    'status' => 'applied',
], JSON_UNESCAPED_SLASHES);
PHP);
    $malformed_driver_cli = run_merge_cli([
        'run-plugin-driver',
        '--metadata-db', $metadata,
        '--id', (string)$drive_file_conflict_id,
        '--driver', $malformed_plugin_driver_path,
        '--format', 'json',
    ]);
    assert_true($malformed_driver_cli['status'] !== 0, 'plugin driver runner rejects applied status without result evidence');
    assert_true(str_contains($malformed_driver_cli['output'], 'must emit a result value'), 'plugin driver runner explains missing result evidence');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE choice = 'plugin-driver'"),
        $plugin_driver_resolution_count,
        'malformed plugin driver output records no plugin-driver resolution'
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
    $plugin_filter_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'plugin' => 'forkpress-plugin-graph',
    ]);
    assert_same($plugin_filter_audit['filters']['scope'], 'plugin', 'plugin audit filter defaults to plugin scope');
    assert_same($plugin_filter_audit['filters']['records'], 'conflicts', 'plugin audit filter defaults to conflict records');
    assert_same(count($plugin_filter_audit['conflicts']), 4, 'plugin audit can filter conflicts by validator plugin');
    $plugin_object_filter_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'plugin_object' => 'child:' . $child_id,
    ]);
    assert_same(count($plugin_object_filter_audit['conflicts']), 2, 'plugin audit can filter conflicts by validator object');
    $plugin_severity_filter_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'plugin_severity' => 'error',
    ]);
    assert_same(count($plugin_severity_filter_audit['conflicts']), 3, 'plugin audit can filter conflicts by validator severity');
    assert_same($plugin_severity_filter_audit['conflicts'][0]['conflict_type'] ?? null, 'plugin-graph-file-drift', 'plugin severity filter returns the matching validator conflict');
    $plugin_group_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'group_by' => 'plugin',
    ]);
    $plugin_group_counts = [];
    foreach ($plugin_group_audit['conflict_groups'] as $group) {
        $plugin_group_counts[(string)$group['group_key']] = (int)$group['conflict_count'];
    }
    assert_same($plugin_group_counts['forkpress-plugin-graph'] ?? 0, 4, 'plugin audit can group conflicts by validator plugin');
    $plugin_object_group_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'group_by' => 'plugin-object',
    ]);
    $plugin_object_group_counts = [];
    foreach ($plugin_object_group_audit['conflict_groups'] as $group) {
        $plugin_object_group_counts[(string)$group['group_key']] = (int)$group['conflict_count'];
    }
    assert_same($plugin_object_group_counts['child:' . $child_id] ?? 0, 2, 'plugin audit can group conflicts by validator object');
    $plugin_severity_group_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'group_by' => 'plugin-severity',
    ]);
    $plugin_severity_group_counts = [];
    foreach ($plugin_severity_group_audit['conflict_groups'] as $group) {
        $plugin_severity_group_counts[(string)$group['group_key']] = (int)$group['conflict_count'];
    }
    assert_same($plugin_severity_group_counts['error'] ?? 0, 3, 'plugin audit can group conflicts by validator severity');
    assert_same($plugin_severity_group_counts['(unknown)'] ?? 0, 1, 'plugin audit groups findings without validator severity as unknown');
    $plugin_file_group_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'group_by' => 'plugin-file',
    ]);
    $plugin_file_group_counts = [];
    foreach ($plugin_file_group_audit['conflict_groups'] as $group) {
        $plugin_file_group_counts[(string)$group['group_key']] = (int)$group['conflict_count'];
    }
    assert_same($plugin_file_group_counts['wp-content/uploads/plugin-validator-missing.dat'] ?? 0, 1, 'plugin audit can group conflicts by validator-reported file');
    $plugin_group_default_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, ['scope' => 'plugin', 'group_by' => 'plugin']);
    assert_same($plugin_group_default_audit['filters']['records'], 'conflicts', 'plugin grouping defaults audit records to conflicts');
    $plugin_object_group_default_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, ['scope' => 'plugin', 'group_by' => 'plugin-object']);
    assert_same($plugin_object_group_default_audit['filters']['records'], 'conflicts', 'plugin object grouping defaults audit records to conflicts');
    $plugin_severity_group_default_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, ['scope' => 'plugin', 'group_by' => 'plugin-severity']);
    assert_same($plugin_severity_group_default_audit['filters']['records'], 'conflicts', 'plugin severity grouping defaults audit records to conflicts');
    $plugin_file_group_default_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, ['scope' => 'plugin', 'group_by' => 'plugin-file']);
    assert_same($plugin_file_group_default_audit['filters']['records'], 'conflicts', 'plugin file grouping defaults audit records to conflicts');
    ob_start();
    cow_merge_print_audit_text($plugin_object_group_audit);
    $plugin_object_group_text = ob_get_clean();
    assert_true(str_contains($plugin_object_group_text, 'plugin-object=child:' . $child_id . ' conflicts=2'), 'plugin text audit exposes conflict grouping by validator object');
    ob_start();
    cow_merge_print_audit_text($plugin_severity_group_audit);
    $plugin_group_text = ob_get_clean();
    assert_true(str_contains($plugin_group_text, 'plugin-severity=error conflicts=3'), 'plugin text audit exposes conflict grouping by validator severity');
    ob_start();
    cow_merge_print_audit_text($plugin_file_group_audit);
    $plugin_file_group_text = ob_get_clean();
    assert_true(str_contains($plugin_file_group_text, 'plugin-file=wp-content/uploads/plugin-validator-missing.dat conflicts=1'), 'plugin text audit exposes conflict grouping by validator file');

    $logical_alias_result = cow_merge_record_plugin_validator_conflicts($metadata, (int)$result['run_id'], [
        [
            'plugin' => 'forkpress-plugin-graph',
            'object' => 'logical-alias:' . $drive_child_id,
            'reason' => 'plugin driver should prove the semantic child graph was cleared, not just the volatile object label',
            'type' => 'plugin-graph-file-drift',
            'tables' => ['plugin_graph_child'],
            'paths' => ['C:/plugin-assets/plugin-validator-drive.dat'],
            'validator' => 'forkpress-plugin-graph@1',
            'severity' => 'error',
            'logical_identity' => [
                'kind' => 'plugin-child',
                'child_id' => $drive_child_id,
            ],
            'candidate' => [
                'child_id' => $drive_child_id,
                'file_path' => 'C:/plugin-assets/plugin-validator-drive.dat',
            ],
        ],
    ]);
    assert_same($logical_alias_result['conflicts'], 1, 'plugin validator can record a conflict with stable logical identity and a changed object label');
    $logical_alias_identity = SQLite3::escapeString(
        cow_merge_plugin_identity_json('forkpress-plugin-graph', 'logical-alias:' . $drive_child_id)
    );
    $logical_alias_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = '__plugins__' AND conflict_type = 'plugin-graph-file-drift' AND row_identity = '$logical_alias_identity' ORDER BY id DESC LIMIT 1");
    assert_true($logical_alias_conflict_id > 0, 'plugin logical-identity alias conflict is recorded');
    $logical_identity_driver_record = run_merge_cli([
        'record-plugin-driver-resolution',
        '--metadata-db', $metadata,
        '--id', (string)$logical_alias_conflict_id,
        '--driver', 'forkpress-plugin-graph-driver@logical-identity-alias',
        '--result-json', '{"claimed":"object label changed but semantic identity still fails validation"}',
        '--applied',
        '--format', 'json',
    ]);
    assert_true($logical_identity_driver_record['status'] !== 0, 'plugin driver recorder rejects applied repairs that leave the same logical identity open');
    assert_true(
        str_contains($logical_identity_driver_record['output'], 'same logical identity'),
        'plugin driver recorder explains logical-identity postflight failures'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE choice = 'plugin-driver'"),
        $plugin_driver_resolution_count,
        'logical-identity plugin driver postflight records no plugin-driver resolution'
    );

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
    $replacement_no_revalidation_count = (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE choice = 'plugin-driver'");
    $replacement_no_revalidation_record = run_merge_cli([
        'record-plugin-driver-resolution',
        '--metadata-db', $metadata,
        '--id', (string)$replacement_conflict_id,
        '--driver', 'forkpress-plugin-graph-driver@needs-revalidation',
        '--result-json', json_encode(['replacement' => 'without revalidation'], JSON_UNESCAPED_SLASHES),
        '--format', 'json',
    ]);
    assert_true($replacement_no_revalidation_record['status'] !== 0, 'plugin driver recorder rejects replacement evidence before review revalidation');
    assert_true(str_contains($replacement_no_revalidation_record['output'], 'requires merge-audit --revalidate'), 'plugin driver recorder explains missing replacement revalidation');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE choice = 'plugin-driver'"),
        $replacement_no_revalidation_count,
        'unrevalidated plugin replacement records no driver resolution'
    );
    $replacement_no_revalidation_marker = $target_root . '/wp-content/uploads/unrevalidated-plugin-driver-ran.dat';
    $replacement_no_revalidation_driver_path = $tmp . '/forkpress-plugin-graph-driver-unrevalidated.php';
    write_test_file($replacement_no_revalidation_driver_path, <<<'PHP'
<?php
$target_root = rtrim((string)getenv('FORKPRESS_MERGE_TARGET_ROOT'), '/');
@mkdir($target_root . '/wp-content/uploads', 0777, true);
file_put_contents($target_root . '/wp-content/uploads/unrevalidated-plugin-driver-ran.dat', 'unrevalidated driver ran');
echo json_encode([
    'status' => 'applied',
    'result' => ['unexpected' => 'unrevalidated driver ran'],
], JSON_UNESCAPED_SLASHES);
PHP);
    $replacement_no_revalidation_run = run_merge_cli([
        'run-plugin-driver',
        '--metadata-db', $metadata,
        '--id', (string)$replacement_conflict_id,
        '--driver', $replacement_no_revalidation_driver_path,
        '--format', 'json',
    ]);
    assert_true($replacement_no_revalidation_run['status'] !== 0, 'plugin driver runner rejects replacement evidence before review revalidation');
    assert_true(str_contains($replacement_no_revalidation_run['output'], 'requires merge-audit --revalidate'), 'plugin driver runner explains missing replacement revalidation');
    assert_true(!is_file($replacement_no_revalidation_marker), 'plugin driver runner does not execute unrevalidated replacement repairs');
    $plugin_driver_resolution_count_before_stale = (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE choice = 'plugin-driver'");
    $stale_driver_record = run_merge_cli([
        'record-plugin-driver-resolution',
        '--metadata-db', $metadata,
        '--id', (string)$json_conflict_id,
        '--driver', 'forkpress-plugin-graph-driver@stale',
        '--result-json', json_encode(['stale' => true], JSON_UNESCAPED_SLASHES),
        '--format', 'json',
    ]);
    assert_true($stale_driver_record['status'] !== 0, 'plugin driver recorder rejects superseded validator conflicts');
    assert_true(str_contains($stale_driver_record['output'], 'replacement conflict #' . $replacement_conflict_id), 'plugin driver recorder points to replacement validator evidence');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE choice = 'plugin-driver'"),
        $plugin_driver_resolution_count_before_stale,
        'stale plugin driver recorder records no resolution'
    );
    $stale_driver_marker = $target_root . '/wp-content/uploads/stale-plugin-driver-ran.dat';
    $stale_plugin_driver_path = $tmp . '/forkpress-plugin-graph-driver-stale.php';
    write_test_file($stale_plugin_driver_path, <<<'PHP'
<?php
$target_root = rtrim((string)getenv('FORKPRESS_MERGE_TARGET_ROOT'), '/');
@mkdir($target_root . '/wp-content/uploads', 0777, true);
file_put_contents($target_root . '/wp-content/uploads/stale-plugin-driver-ran.dat', 'stale driver ran');
echo json_encode([
    'status' => 'applied',
    'result' => ['unexpected' => 'stale driver ran'],
], JSON_UNESCAPED_SLASHES);
PHP);
    $stale_driver_run = run_merge_cli([
        'run-plugin-driver',
        '--metadata-db', $metadata,
        '--id', (string)$json_conflict_id,
        '--driver', $stale_plugin_driver_path,
        '--format', 'json',
    ]);
    assert_true($stale_driver_run['status'] !== 0, 'plugin driver runner rejects superseded validator conflicts before execution');
    assert_true(str_contains($stale_driver_run['output'], 'replacement conflict #' . $replacement_conflict_id), 'plugin driver runner points to replacement validator evidence');
    assert_true(!is_file($stale_driver_marker), 'plugin driver runner does not execute stale validator repairs');
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
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $replacement_conflict_id, 'target', false, 'generic target repair should stay blocked', 'cow-test', true),
        'plugin validator conflicts cannot be resolved by generic merge-resolve',
        'generic source/target resolution remains blocked for revalidated plugin replacement conflicts'
    );
    $plugin_event_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 4, [
        'scope' => 'plugin',
        'records' => 'conflict-events',
    ]);
    $plugin_revalidation_events = array_values(array_filter(
        $plugin_event_audit['conflict_events'],
        fn($event) => ($event['event_type'] ?? null) === 'revalidation-required' && (int)($event['conflict_id'] ?? 0) === $json_conflict_id
    ));
    assert_same(count($plugin_revalidation_events), 1, 'plugin replacement revalidation is visible in the conflict event stream');
    assert_same((int)$plugin_revalidation_events[0]['conflict_id'], $json_conflict_id, 'plugin revalidation event belongs to the reviewed plugin conflict');
    assert_same($plugin_revalidation_events[0]['related_record_type'], 'revalidation', 'plugin revalidation event links to the revalidation record');
    assert_same((int)$plugin_revalidation_events[0]['related_record_id'], $plugin_revalidation_id, 'plugin revalidation event exposes the revalidation id');
    assert_same($plugin_revalidation_events[0]['lifecycle_state'], 'needs-action', 'plugin revalidation event records the needs-action lifecycle state');
    assert_same($plugin_revalidation_events[0]['plugin'] ?? null, 'forkpress-plugin-graph', 'plugin conflict events expose validator plugin metadata');
    assert_same($plugin_revalidation_events[0]['plugin_object'] ?? null, 'child:' . $child_id, 'plugin conflict events expose validator object metadata');
    $plugin_filtered_event_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 4, [
        'records' => 'conflict-events',
        'plugin_object' => 'child:' . $child_id,
        'plugin' => 'forkpress-plugin-graph',
    ]);
    assert_same($plugin_filtered_event_audit['filters']['scope'], 'plugin', 'plugin event filters default to plugin scope');
    assert_true(count($plugin_filtered_event_audit['conflict_events']) >= 1, 'plugin audit can filter conflict events by validator object');
    $plugin_filtered_revalidation_events = array_values(array_filter(
        $plugin_filtered_event_audit['conflict_events'],
        fn($event) => ($event['event_type'] ?? null) === 'revalidation-required' && (int)($event['conflict_id'] ?? 0) === $json_conflict_id
    ));
    assert_same(count($plugin_filtered_revalidation_events), 1, 'plugin filtered event stream includes the matching validator revalidation event');
    $plugin_event_group_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'records' => 'conflict-events',
        'plugin' => 'forkpress-plugin-graph',
        'group_by' => 'plugin-object',
    ]);
    $plugin_file_event_group_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'records' => 'conflict-events',
        'plugin_file' => 'wp-content/uploads/plugin-validator-missing.dat',
        'group_by' => 'plugin-file',
    ]);
    $plugin_file_event_group_counts = [];
    foreach ($plugin_file_event_group_audit['conflict_event_groups'] as $group) {
        $plugin_file_event_group_counts[(string)$group['group_key']] = (int)$group['event_count'];
    }
    assert_true(($plugin_file_event_group_counts['wp-content/uploads/plugin-validator-missing.dat'] ?? 0) >= 1, 'plugin file grouping applies to plugin conflict-event queues');
    $plugin_event_group_counts = [];
    foreach ($plugin_event_group_audit['conflict_event_groups'] as $group) {
        $plugin_event_group_counts[(string)$group['group_key']] = (int)$group['event_count'];
    }
    assert_true(($plugin_event_group_counts['child:' . $child_id] ?? 0) >= 1, 'plugin audit can group conflict events by validator object');

    $replacement_driver_path = $tmp . '/forkpress-plugin-graph-driver-replacement.php';
    write_test_file($replacement_driver_path, <<<'PHP'
<?php
$target_db = (string)getenv('FORKPRESS_MERGE_TARGET_DB');
$child_id = (int)str_replace('child:', '', (string)getenv('FORKPRESS_MERGE_PLUGIN_OBJECT'));
$db = new SQLite3($target_db);
$row = $db->querySingle('SELECT parent_id FROM plugin_graph_child WHERE child_id = ' . $child_id, true);
$parent_id = is_array($row) ? (int)$row['parent_id'] : 0;
$graph_json = json_encode([
    'child_id' => $child_id,
    'parent_id' => $parent_id,
], JSON_UNESCAPED_SLASHES);
$stmt = $db->prepare('UPDATE plugin_graph_child SET graph_json = :graph_json WHERE child_id = :child_id');
$stmt->bindValue(':graph_json', $graph_json, SQLITE3_TEXT);
$stmt->bindValue(':child_id', $child_id, SQLITE3_INTEGER);
$stmt->execute();
echo json_encode([
    'status' => 'applied',
    'result' => [
        'repair' => 'replacement graph JSON',
        'child_id' => $child_id,
        'parent_id' => $parent_id,
    ],
], JSON_UNESCAPED_SLASHES);
PHP);
    $replacement_driver_run = run_merge_cli([
        'run-plugin-driver',
        '--metadata-db', $metadata,
        '--id', (string)$replacement_conflict_id,
        '--driver', $replacement_driver_path,
        '--format', 'json',
    ]);
    assert_same($replacement_driver_run['status'], 0, 'plugin driver runner applies current revalidated replacement conflict');
    $replacement_driver_result = json_decode($replacement_driver_run['output'], true);
    assert_same((int)($replacement_driver_result['conflict_id'] ?? 0), $replacement_conflict_id, 'plugin driver result belongs to the replacement conflict');
    assert_same($replacement_driver_result['driver_status'] ?? null, 'applied', 'replacement plugin driver records applied status');
    $replacement_driver_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'resolutions',
        'resolution_choice' => 'plugin-driver',
        'plugin_object' => 'child:' . $child_id,
    ]);
    $replacement_driver_rows = array_values(array_filter(
        $replacement_driver_audit['resolutions'],
        fn(array $resolution): bool => (int)($resolution['conflict_id'] ?? 0) === $replacement_conflict_id
    ));
    assert_same(count($replacement_driver_rows), 1, 'current replacement plugin-driver resolution is auditable');

    $ordinary_lineage_meta = open_db($metadata);
    $ordinary_branch = 'feature-plugin-ordinary-lineage';
    $escaped_source = SQLite3::escapeString($source);
    $escaped_target = SQLite3::escapeString($target);
    $escaped_base = SQLite3::escapeString($base);
    $escaped_target_root = SQLite3::escapeString($target_root);
    $ordinary_lineage_meta->exec(
        "INSERT INTO merge_runs (source_branch, target_branch, status, policy, source_db, target_db, base_db, target_root) " .
        "VALUES ('$ordinary_branch', 'main', 'completed_with_conflicts', 'target-wins', '$escaped_source', '$escaped_target', '$escaped_base', '$escaped_target_root')"
    );
    $ordinary_previous_run_id = (int)$ordinary_lineage_meta->lastInsertRowID();
    cow_merge_record_conflict(
        $ordinary_lineage_meta,
        $ordinary_previous_run_id,
        '__plugins__',
        cow_merge_plugin_identity_json('forkpress-plugin-ordinary-lineage', 'object:1'),
        null,
        'plugin-ordinary-lineage',
        null,
        null,
        null,
        [
            'plugin' => 'forkpress-plugin-ordinary-lineage',
            'object' => 'object:1',
            'reason' => 'ordinary prior plugin conflict evidence',
            'validator' => 'forkpress-plugin-ordinary-lineage@1',
            'semantic_scope' => 'plugin',
            'candidate' => ['revision' => 'previous-run'],
        ]
    );
    $ordinary_previous_conflict_id = (int)$ordinary_lineage_meta->querySingle(
        "SELECT id FROM merge_conflicts WHERE run_id = $ordinary_previous_run_id AND conflict_type = 'plugin-ordinary-lineage' ORDER BY id DESC LIMIT 1"
    );
    $ordinary_lineage_meta->exec(
        "INSERT INTO merge_runs (source_branch, target_branch, status, policy, source_db, target_db, base_db, target_root) " .
        "VALUES ('$ordinary_branch', 'main', 'completed_with_conflicts', 'target-wins', '$escaped_source', '$escaped_target', '$escaped_base', '$escaped_target_root')"
    );
    $ordinary_current_run_id = (int)$ordinary_lineage_meta->lastInsertRowID();
    cow_merge_record_conflict(
        $ordinary_lineage_meta,
        $ordinary_current_run_id,
        '__plugins__',
        cow_merge_plugin_identity_json('forkpress-plugin-ordinary-lineage', 'object:1'),
        null,
        'plugin-ordinary-lineage',
        null,
        null,
        null,
        [
            'plugin' => 'forkpress-plugin-ordinary-lineage',
            'object' => 'object:1',
            'reason' => 'ordinary current plugin conflict evidence',
            'validator' => 'forkpress-plugin-ordinary-lineage@1',
            'semantic_scope' => 'plugin',
            'candidate' => ['revision' => 'current-run'],
        ]
    );
    $ordinary_current_conflict_id = (int)$ordinary_lineage_meta->querySingle(
        "SELECT id FROM merge_conflicts WHERE run_id = $ordinary_current_run_id AND conflict_type = 'plugin-ordinary-lineage' ORDER BY id DESC LIMIT 1"
    );
    $ordinary_lineage_meta->close();
    assert_same(
        (int)scalar($metadata, "SELECT previous_conflict_id FROM merge_conflicts WHERE id = $ordinary_current_conflict_id"),
        $ordinary_previous_conflict_id,
        'ordinary plugin conflict lineage links to the previous merge run conflict'
    );
    $ordinary_lineage_record = run_merge_cli([
        'record-plugin-driver-resolution',
        '--metadata-db', $metadata,
        '--id', (string)$ordinary_current_conflict_id,
        '--driver', 'forkpress-plugin-ordinary-lineage-driver@1',
        '--result-json', json_encode(['ordinary-lineage' => 'resolved'], JSON_UNESCAPED_SLASHES),
        '--format', 'json',
    ]);
    assert_same($ordinary_lineage_record['status'], 0, 'plugin driver recorder allows ordinary cross-run plugin conflict lineage');

    $revalidated_again = cow_merge_revalidate_reviewed_conflicts($metadata, (int)$result['run_id'], 'cow-revalidate');
    assert_same($revalidated_again['carried'], 0, 'plugin revalidation does not duplicate carried replacement-evidence notes');
    assert_same($revalidated_again['already_needs_action'], 1, 'plugin revalidation reports already-carried replacement evidence');

    cow_merge_record_plugin_validator_conflicts($metadata, (int)$result['run_id'], [
        [
            'plugin' => 'forkpress-plugin-guard',
            'object' => 'guard:incompatible',
            'reason' => 'plugin guard original evidence',
            'type' => 'plugin-guard-incompatible',
            'validator' => 'forkpress-plugin-guard@1',
            'candidate' => ['revision' => 'original'],
        ],
    ]);
    $incompatible_original_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = '__plugins__' AND conflict_type = 'plugin-guard-incompatible' ORDER BY id DESC LIMIT 1");
    cow_merge_record_plugin_validator_conflicts($metadata, (int)$result['run_id'], [
        [
            'plugin' => 'forkpress-plugin-guard',
            'object' => 'guard:incompatible',
            'reason' => 'plugin guard incompatible replacement evidence',
            'type' => 'plugin-guard-incompatible',
            'validator' => 'forkpress-plugin-guard@1',
            'candidate' => ['revision' => 'replacement'],
        ],
    ]);
    $incompatible_replacement_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = '__plugins__' AND conflict_type = 'plugin-guard-incompatible' AND id > $incompatible_original_id ORDER BY id DESC LIMIT 1");
    $incompatible_meta = open_db($metadata);
    $incompatible_review_id = cow_merge_insert_review_note(
        $incompatible_meta,
        'conflict',
        $incompatible_original_id,
        'needs-action',
        'manual incompatible plugin revalidation fixture',
        'cow-test'
    );
    cow_merge_record_revalidation(
        $incompatible_meta,
        $incompatible_original_id,
        $incompatible_review_id,
        (int)$result['run_id'],
        'incompatible',
        (string)scalar($metadata, "SELECT source_payload FROM merge_conflicts WHERE id = $incompatible_replacement_id"),
        (string)scalar($metadata, "SELECT chosen_payload FROM merge_conflicts WHERE id = $incompatible_replacement_id"),
        'plugin replacement evidence is incompatible',
        $incompatible_replacement_id
    );
    $incompatible_meta->close();
    $incompatible_driver_record = run_merge_cli([
        'record-plugin-driver-resolution',
        '--metadata-db', $metadata,
        '--id', (string)$incompatible_replacement_id,
        '--driver', 'forkpress-plugin-guard@incompatible',
        '--result-json', json_encode(['guard' => 'incompatible'], JSON_UNESCAPED_SLASHES),
        '--format', 'json',
    ]);
    assert_true($incompatible_driver_record['status'] !== 0, 'plugin driver recorder rejects incompatible plugin replacement revalidation');
    assert_true(str_contains($incompatible_driver_record['output'], 'incompatible revalidation'), 'plugin driver recorder explains incompatible plugin replacement revalidation');

    cow_merge_record_plugin_validator_conflicts($metadata, (int)$result['run_id'], [
        [
            'plugin' => 'forkpress-plugin-guard',
            'object' => 'guard:drifted',
            'reason' => 'plugin guard original drift evidence',
            'type' => 'plugin-guard-drifted',
            'validator' => 'forkpress-plugin-guard@1',
            'candidate' => ['revision' => 'original'],
        ],
    ]);
    $drift_original_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = '__plugins__' AND conflict_type = 'plugin-guard-drifted' ORDER BY id DESC LIMIT 1");
    cow_merge_record_plugin_validator_conflicts($metadata, (int)$result['run_id'], [
        [
            'plugin' => 'forkpress-plugin-guard',
            'object' => 'guard:drifted',
            'reason' => 'plugin guard replacement drift evidence',
            'type' => 'plugin-guard-drifted',
            'validator' => 'forkpress-plugin-guard@1',
            'candidate' => ['revision' => 'replacement'],
        ],
    ]);
    $drift_replacement_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = '__plugins__' AND conflict_type = 'plugin-guard-drifted' AND id > $drift_original_id ORDER BY id DESC LIMIT 1");
    $drift_meta = open_db($metadata);
    $drift_review_id = cow_merge_insert_review_note(
        $drift_meta,
        'conflict',
        $drift_original_id,
        'needs-action',
        'manual current plugin revalidation fixture',
        'cow-test'
    );
    cow_merge_record_revalidation(
        $drift_meta,
        $drift_original_id,
        $drift_review_id,
        (int)$result['run_id'],
        'replacement-evidence',
        (string)scalar($metadata, "SELECT source_payload FROM merge_conflicts WHERE id = $drift_replacement_id"),
        (string)scalar($metadata, "SELECT chosen_payload FROM merge_conflicts WHERE id = $drift_replacement_id"),
        'plugin replacement evidence is current',
        $drift_replacement_id
    );
    $drift_meta->close();
    cow_merge_record_plugin_validator_conflicts($metadata, (int)$result['run_id'], [
        [
            'plugin' => 'forkpress-plugin-guard',
            'object' => 'guard:drifted',
            'reason' => 'plugin guard replacement drifted again',
            'type' => 'plugin-guard-drifted',
            'validator' => 'forkpress-plugin-guard@1',
            'candidate' => ['revision' => 'drifted-again'],
        ],
    ]);
    $drift_latest_replacement_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = '__plugins__' AND conflict_type = 'plugin-guard-drifted' AND id > $drift_replacement_id ORDER BY id DESC LIMIT 1");
    $drifted_driver_record = run_merge_cli([
        'record-plugin-driver-resolution',
        '--metadata-db', $metadata,
        '--id', (string)$drift_replacement_id,
        '--driver', 'forkpress-plugin-guard@drifted',
        '--result-json', json_encode(['guard' => 'drifted'], JSON_UNESCAPED_SLASHES),
        '--format', 'json',
    ]);
    assert_true($drifted_driver_record['status'] !== 0, 'plugin driver recorder rejects drifted plugin replacement revalidation');
    assert_true(str_contains($drifted_driver_record['output'], 'replacement conflict #' . $drift_latest_replacement_id), 'plugin driver recorder points at drifted replacement evidence');

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
            'severity' => 'warning',
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
    $logical_identity_group_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 20, [
        'scope' => 'plugin',
        'group_by' => 'plugin-logical-identity',
    ]);
    assert_same($logical_identity_group_audit['filters']['records'], 'conflicts', 'plugin logical-identity grouping defaults audit records to conflicts');
    $logical_identity_group_counts = [];
    foreach ($logical_identity_group_audit['conflict_groups'] as $group) {
        $logical_identity_group_counts[(string)$group['group_key']] = (int)$group['conflict_count'];
    }
    assert_same(
        $logical_identity_group_counts['{"kind":"plugin-child","slug":"child-before-rerun"}'] ?? 0,
        1,
        'plugin audit can group conflicts by structured logical identity'
    );
    $logical_identity_filter_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'plugin_logical_identity' => '{"slug":"child-before-rerun","kind":"plugin-child"}',
    ]);
    assert_same(count($logical_identity_filter_audit['conflicts']), 1, 'plugin audit can filter conflicts by structured logical identity');
    assert_same(
        $logical_identity_filter_audit['conflicts'][0]['plugin_logical_identity']['slug'] ?? null,
        'child-before-rerun',
        'plugin logical-identity filter accepts canonical JSON regardless of object key order'
    );
    ob_start();
    cow_merge_print_audit_text($logical_identity_audit);
    $logical_identity_text = ob_get_clean();
    assert_true(str_contains($logical_identity_text, 'plugin-logical-identity={"kind":"plugin-child","slug":"child-before-rerun"}'), 'plugin text audit exposes logical identity evidence');
    ob_start();
    cow_merge_print_audit_text($logical_identity_group_audit);
    $logical_identity_group_text = ob_get_clean();
    assert_true(str_contains($logical_identity_group_text, 'plugin-logical-identity={"kind":"plugin-child","slug":"child-before-rerun"} conflicts=1'), 'plugin text audit exposes conflict grouping by logical identity');
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
            'severity' => 'warning',
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
    $logical_identity_event_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'records' => 'conflict-events',
        'plugin_logical_identity' => '{"slug":"child-before-rerun","kind":"plugin-child"}',
    ]);
    $logical_identity_revalidation_events = array_values(array_filter(
        $logical_identity_event_audit['conflict_events'],
        fn($event) => ($event['event_type'] ?? null) === 'revalidation-required' && (int)($event['conflict_id'] ?? 0) === $logical_identity_conflict_id
    ));
    assert_same(count($logical_identity_revalidation_events), 1, 'plugin logical-identity filter applies to conflict event queues');
    assert_same(
        $logical_identity_revalidation_events[0]['plugin_logical_identity']['slug'] ?? null,
        'child-before-rerun',
        'plugin logical-identity conflict events expose structured logical identity metadata'
    );
    $logical_identity_event_group_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'records' => 'conflict-events',
        'group_by' => 'plugin-logical-identity',
    ]);
    $logical_identity_event_group_counts = [];
    foreach ($logical_identity_event_group_audit['conflict_event_groups'] as $group) {
        $logical_identity_event_group_counts[(string)$group['group_key']] = (int)$group['event_count'];
    }
    assert_true(($logical_identity_event_group_counts['{"kind":"plugin-child","slug":"child-before-rerun"}'] ?? 0) >= 1, 'plugin audit can group conflict events by logical identity');
    $resolution_meta = open_db($metadata);
    cow_merge_record_resolution(
        $resolution_meta,
        $logical_identity_conflict_id,
        'target',
        false,
        'reviewed plugin logical identity without applying',
        'cow-test',
        $target,
        '__plugins__',
        (string)scalar($metadata, "SELECT row_identity FROM merge_conflicts WHERE id = $logical_identity_conflict_id"),
        '',
        ['state' => 'target-before-review'],
        ['state' => 'target-after-review']
    );
    $resolution_meta->close();
    $logical_identity_resolution_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'records' => 'resolutions',
        'plugin_logical_identity' => '{"slug":"child-before-rerun","kind":"plugin-child"}',
    ]);
    assert_true(count($logical_identity_resolution_audit['resolutions']) >= 1, 'plugin logical-identity filter applies to resolution queues');
    assert_same(
        $logical_identity_resolution_audit['resolutions'][0]['plugin_logical_identity']['slug'] ?? null,
        'child-before-rerun',
        'plugin resolution rows expose structured logical identity metadata from the conflict'
    );
    assert_same(
        $logical_identity_resolution_audit['resolutions'][0]['plugin'] ?? null,
        'forkpress-plugin-logical-id',
        'plugin resolution rows expose validator plugin metadata from the conflict'
    );
    assert_same(
        $logical_identity_resolution_audit['resolutions'][0]['plugin_object'] ?? null,
        'child-slot:' . $child_id,
        'plugin resolution rows expose validator object metadata from the conflict'
    );
    assert_same(
        $logical_identity_resolution_audit['resolutions'][0]['plugin_severity'] ?? null,
        'warning',
        'plugin resolution rows expose validator severity metadata from the conflict'
    );
    ob_start();
    cow_merge_print_audit_text($logical_identity_resolution_audit);
    $logical_identity_resolution_text = ob_get_clean();
    assert_true(
        str_contains($logical_identity_resolution_text, 'plugin plugin=forkpress-plugin-logical-id object=child-slot:' . $child_id) &&
            str_contains($logical_identity_resolution_text, 'severity=warning') &&
            str_contains($logical_identity_resolution_text, 'plugin-logical-identity={"kind":"plugin-child","slug":"child-before-rerun"}'),
        'plugin text audit exposes resolution row plugin metadata'
    );
    foreach ([
        ['plugin' => 'forkpress-plugin-logical-id'],
        ['plugin_object' => 'child-slot:' . $child_id],
        ['plugin_severity' => 'warning'],
    ] as $resolution_filter) {
        $plugin_resolution_filter_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, array_merge([
            'records' => 'resolutions',
        ], $resolution_filter));
        assert_true(
            count($plugin_resolution_filter_audit['resolutions']) >= 1,
            'plugin audit can filter resolution records by ' . array_key_first($resolution_filter)
        );
    }
    $plugin_resolution_status_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
        'plugin' => 'forkpress-plugin-logical-id',
        'resolution_status' => 'validated',
    ]);
    assert_same(
        $plugin_resolution_status_audit['filters']['records'],
        'resolutions',
        'plugin audit with resolution status defaults to resolution records'
    );
    assert_true(
        count($plugin_resolution_status_audit['resolutions']) >= 1,
        'plugin audit with resolution status returns plugin resolution records'
    );
    foreach ([
        'plugin' => 'forkpress-plugin-logical-id',
        'plugin-object' => 'child-slot:' . $child_id,
        'plugin-severity' => 'warning',
        'plugin-logical-identity' => '{"kind":"plugin-child","slug":"child-before-rerun"}',
    ] as $group_by => $expected_group_key) {
        $plugin_resolution_group_audit = cow_merge_audit_report($metadata, (int)$result['run_id'], 10, [
            'records' => 'resolutions',
            'group_by' => $group_by,
        ]);
        $plugin_resolution_group_counts = [];
        foreach ($plugin_resolution_group_audit['resolution_groups'] as $group) {
            $plugin_resolution_group_counts[(string)$group['group_key']] = (int)$group['resolution_count'];
        }
        assert_true(
            ($plugin_resolution_group_counts[$expected_group_key] ?? 0) >= 1,
            "plugin audit can group resolution records by $group_by"
        );
        ob_start();
        cow_merge_print_audit_text($plugin_resolution_group_audit);
        $plugin_resolution_group_text = ob_get_clean();
        assert_true(
            str_contains($plugin_resolution_group_text, "$group_by=$expected_group_key resolutions="),
            "plugin text audit exposes resolution grouping by $group_by"
        );
    }

    $root_context_base_root = $tmp . '/root-context-base';
    $root_context_source_root = $tmp . '/root-context-source';
    $root_context_target_root = $tmp . '/root-context-target';
    $root_context_db_dir = $tmp . '/root-context-dbs';
    $root_context_base = $root_context_db_dir . '/base.sqlite';
    $root_context_source = $root_context_db_dir . '/source.sqlite';
    $root_context_target = $root_context_db_dir . '/target.sqlite';
    $root_context_metadata = $tmp . '/.forkpress/cow/merge/plugin-root-context-metadata.sqlite';
    $root_context_file_base = $tmp . '/.forkpress/cow/merge/file-bases/plugin-root-context.json';

    mkdir($root_context_base_root . '/wp-content/database', 0777, true);
    mkdir($root_context_db_dir, 0777, true);
    create_plugin_validator_db($root_context_base);
    write_test_file($root_context_base_root . '/wp-content/uploads/root-context-marker.dat', 'root context marker');
    write_test_file($root_context_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$target_root = rtrim((string)getenv('FORKPRESS_MERGE_TARGET_ROOT'), '/');
$findings = [];
if (!is_file($target_root . '/wp-content/uploads/root-context-marker.dat')) {
    $findings[] = [
        'plugin' => 'forkpress-plugin-root-context',
        'object' => 'merge-root',
        'reason' => 'plugin validator did not receive the explicit target file root',
        'type' => 'plugin-root-context-env-drift',
        'validator' => 'forkpress-plugin-root-context@1',
        'candidate' => [
            'target_root' => $target_root,
        ],
    ];
}
$res = $db->query('SELECT child_id, file_path FROM plugin_graph_child ORDER BY child_id');
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $file_path = str_replace('\\', '/', (string)$row['file_path']);
    if (!is_file($target_root . '/' . $file_path)) {
        $findings[] = [
            'plugin' => 'forkpress-plugin-root-context',
            'object' => 'child:' . (int)$row['child_id'],
            'reason' => 'plugin child references a missing file under the explicit target root',
            'type' => 'plugin-root-context-file-drift',
            'paths' => [$file_path],
            'validator' => 'forkpress-plugin-root-context@1',
            'candidate' => [
                'child_id' => (int)$row['child_id'],
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
    copy_tree_for_test($root_context_base_root, $root_context_source_root);
    copy_tree_for_test($root_context_base_root, $root_context_target_root);
    copy($root_context_base, $root_context_source);
    copy($root_context_base, $root_context_target);
    cow_merge_capture_file_base($root_context_base_root, $root_context_file_base);
    cow_merge_allocate_autoincrement_bands($root_context_source, $root_context_metadata, 'feature-plugin-root-context-source');
    cow_merge_allocate_autoincrement_bands($root_context_target, $root_context_metadata, 'main');

    $root_context_source_db = open_db($root_context_source);
    $root_context_source_db->exec("INSERT INTO plugin_graph_parent (label) VALUES ('source root-context parent')");
    $root_context_parent_id = (int)$root_context_source_db->lastInsertRowID();
    $root_context_source_db->exec("INSERT INTO plugin_graph_child (parent_id, graph_json, file_path) VALUES ($root_context_parent_id, '{}', 'wp-content/uploads/root-context-missing.dat')");
    $root_context_child_id = (int)$root_context_source_db->lastInsertRowID();
    $root_context_source_db->exec("UPDATE plugin_graph_child SET graph_json = '{\"child_id\":$root_context_child_id,\"parent_id\":$root_context_parent_id}' WHERE child_id = $root_context_child_id");
    $root_context_source_db->close();

    $root_context_result = cow_merge_branch_state(
        $root_context_base,
        $root_context_source,
        $root_context_target,
        $root_context_metadata,
        'feature-plugin-root-context-source',
        'main',
        $root_context_file_base,
        $root_context_source_root,
        $root_context_target_root
    );
    assert_same($root_context_result['status'], 'completed_with_conflicts', 'root-context plugin validator records the real missing file finding');
    assert_same((int)($root_context_result['plugin_validator_conflicts'] ?? 0), 1, 'plugin validator receives the explicit target file root even when the DB path is outside the root');
    $root_context_audit = cow_merge_audit_report($root_context_metadata, (int)$root_context_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'plugin' => 'forkpress-plugin-root-context',
    ]);
    assert_same(count($root_context_audit['conflicts']), 1, 'root-context audit contains only the file finding from the explicit root');
    $root_context_conflict_id = (int)($root_context_audit['conflicts'][0]['id'] ?? 0);
    assert_true($root_context_conflict_id > 0, 'root-context file conflict is recorded for driver postflight');
    $root_context_resolution_count = (int)scalar($root_context_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE choice = 'plugin-driver'");
    $root_context_uncleared_driver = run_merge_cli([
        'record-plugin-driver-resolution',
        '--metadata-db', $root_context_metadata,
        '--id', (string)$root_context_conflict_id,
        '--driver', 'forkpress-plugin-root-context-driver@non-clearing',
        '--result-json', '{"claimed":"repair without touching explicit root files"}',
        '--applied',
        '--format', 'json',
    ]);
    assert_true($root_context_uncleared_driver['status'] !== 0, 'plugin driver recorder uses the explicit target root for postflight validation');
    assert_true(
        str_contains($root_context_uncleared_driver['output'], 'did not clear validator conflict #' . $root_context_conflict_id),
        'root-context plugin driver recorder explains uncleared postflight findings'
    );
    assert_same(
        (int)scalar($root_context_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE choice = 'plugin-driver'"),
        $root_context_resolution_count,
        'root-context non-clearing plugin driver records no plugin-driver resolution'
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

    $woocommerce_base_root = $tmp . '/woocommerce-base';
    $woocommerce_source_root = $tmp . '/woocommerce-source';
    $woocommerce_target_root = $tmp . '/woocommerce-target';
    $woocommerce_base = $woocommerce_base_root . '/wp-content/database/.ht.sqlite';
    $woocommerce_source = $woocommerce_source_root . '/wp-content/database/.ht.sqlite';
    $woocommerce_target = $woocommerce_target_root . '/wp-content/database/.ht.sqlite';
    $woocommerce_metadata = $tmp . '/.forkpress/cow/merge/plugin-woocommerce-validator-metadata.sqlite';
    $woocommerce_file_base = $tmp . '/.forkpress/cow/merge/file-bases/plugin-woocommerce-validator.json';

    mkdir($woocommerce_base_root . '/wp-content/database', 0777, true);
    create_woocommerce_hpos_validator_db($woocommerce_base);
    write_test_file($woocommerce_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$findings = [];
$check_order = function (int $order_id, string $object, array $tables, array $candidate) use ($db, &$findings): void {
    if ($order_id <= 0) {
        return;
    }
    $order_exists = (int)$db->querySingle('SELECT COUNT(*) FROM wp_wc_orders WHERE id = ' . $order_id);
    if ($order_exists === 1) {
        return;
    }
    $findings[] = [
        'plugin' => 'woocommerce',
        'object' => $object,
        'reason' => 'WooCommerce HPOS graph references a missing order row',
        'type' => 'plugin-woocommerce-hpos-missing-order',
        'tables' => $tables,
        'validator' => 'woocommerce-hpos@forkpress-test',
        'severity' => 'error',
        'logical_identity' => [
            'plugin' => 'woocommerce',
            'kind' => 'shop_order',
            'order_id' => $order_id,
        ],
        'candidate' => ['order_id' => $order_id, 'order_exists' => $order_exists] + $candidate,
    ];
};
$addresses = $db->query('SELECT id, order_id, address_type, first_name FROM wp_wc_order_addresses ORDER BY id');
while ($row = $addresses->fetchArray(SQLITE3_ASSOC)) {
    $check_order((int)$row['order_id'], 'order-address:' . (int)$row['id'], ['wp_wc_orders', 'wp_wc_order_addresses'], [
        'address_id' => (int)$row['id'],
        'address_type' => (string)$row['address_type'],
        'first_name' => (string)$row['first_name'],
    ]);
}
$order_metas = $db->query('SELECT id, order_id, meta_key, meta_value FROM wp_wc_orders_meta ORDER BY id');
while ($row = $order_metas->fetchArray(SQLITE3_ASSOC)) {
    $check_order((int)$row['order_id'], 'order-meta:' . (int)$row['id'], ['wp_wc_orders', 'wp_wc_orders_meta'], [
        'order_meta_id' => (int)$row['id'],
        'meta_key' => (string)$row['meta_key'],
        'meta_value' => (string)$row['meta_value'],
    ]);
}
$items = $db->query('SELECT order_item_id, order_id, order_item_name FROM wp_woocommerce_order_items ORDER BY order_item_id');
while ($row = $items->fetchArray(SQLITE3_ASSOC)) {
    $check_order((int)$row['order_id'], 'order-item:' . (int)$row['order_item_id'], ['wp_wc_orders', 'wp_woocommerce_order_items'], [
        'order_item_id' => (int)$row['order_item_id'],
        'order_item_name' => (string)$row['order_item_name'],
    ]);
}
$metas = $db->query('SELECT m.meta_id, m.order_item_id, m.meta_key, m.meta_value, i.order_id
    FROM wp_woocommerce_order_itemmeta m
    LEFT JOIN wp_woocommerce_order_items i ON i.order_item_id = m.order_item_id
    ORDER BY m.meta_id');
while ($row = $metas->fetchArray(SQLITE3_ASSOC)) {
    $item_exists = $row['order_id'] !== null;
    $order_id = $item_exists ? (int)$row['order_id'] : 0;
    if (!$item_exists) {
        $findings[] = [
            'plugin' => 'woocommerce',
            'object' => 'order-itemmeta:' . (int)$row['meta_id'],
            'reason' => 'WooCommerce order item metadata references a missing order item row',
            'type' => 'plugin-woocommerce-hpos-missing-order-item',
            'tables' => ['wp_woocommerce_order_items', 'wp_woocommerce_order_itemmeta'],
            'validator' => 'woocommerce-hpos@forkpress-test',
            'severity' => 'error',
            'logical_identity' => [
                'plugin' => 'woocommerce',
                'kind' => 'order_item',
                'order_item_id' => (int)$row['order_item_id'],
            ],
            'candidate' => [
                'meta_id' => (int)$row['meta_id'],
                'order_item_id' => (int)$row['order_item_id'],
                'item_exists' => false,
            ],
        ];
        continue;
    }
    $check_order($order_id, 'order-itemmeta:' . (int)$row['meta_id'], ['wp_wc_orders', 'wp_woocommerce_order_items', 'wp_woocommerce_order_itemmeta'], [
        'meta_id' => (int)$row['meta_id'],
        'order_item_id' => (int)$row['order_item_id'],
        'meta_key' => (string)$row['meta_key'],
    ]);
    if ((string)$row['meta_key'] === '_product_id') {
        $product_id = (int)$row['meta_value'];
        if ($product_id > 0) {
            $product_exists = (int)$db->querySingle('SELECT COUNT(*) FROM wp_wc_product_meta_lookup WHERE product_id = ' . $product_id);
            if ($product_exists !== 1) {
                $findings[] = [
                    'plugin' => 'woocommerce',
                    'object' => 'order-item-product:' . (int)$row['meta_id'],
                    'reason' => 'WooCommerce order item metadata references a missing product lookup row',
                    'type' => 'plugin-woocommerce-hpos-missing-product',
                    'tables' => ['wp_wc_product_meta_lookup', 'wp_woocommerce_order_itemmeta'],
                    'validator' => 'woocommerce-hpos@forkpress-test',
                    'severity' => 'error',
                    'logical_identity' => [
                        'plugin' => 'woocommerce',
                        'kind' => 'product',
                        'product_id' => $product_id,
                    ],
                    'candidate' => [
                        'meta_id' => (int)$row['meta_id'],
                        'order_item_id' => (int)$row['order_item_id'],
                        'order_id' => $order_id,
                        'product_id' => $product_id,
                        'product_exists' => $product_exists,
                    ],
                ];
            }
        }
    }
}
$option_value = $db->querySingle("SELECT option_value FROM wp_options WHERE option_name = 'woocommerce_recent_order_ids'");
$option_payload = is_string($option_value) ? json_decode($option_value, true) : null;
if (is_array($option_payload)) {
    foreach (($option_payload['recent_order_ids'] ?? []) as $index => $order_id) {
        $check_order((int)$order_id, 'option:woocommerce_recent_order_ids:' . (string)$index, ['wp_options', 'wp_wc_orders'], [
            'option_name' => 'woocommerce_recent_order_ids',
            'json_path' => 'recent_order_ids.' . (string)$index,
        ]);
    }
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);

    copy_tree_for_test($woocommerce_base_root, $woocommerce_source_root);
    copy_tree_for_test($woocommerce_base_root, $woocommerce_target_root);
    cow_merge_capture_file_base($woocommerce_base_root, $woocommerce_file_base);
    cow_merge_allocate_autoincrement_bands($woocommerce_source, $woocommerce_metadata, 'feature-plugin-woocommerce-source');
    cow_merge_allocate_autoincrement_bands($woocommerce_target, $woocommerce_metadata, 'main');

    $db = open_db($woocommerce_source);
    $db->exec('DELETE FROM wp_wc_orders WHERE id = 20');
    $db->close();

    $db = open_db($woocommerce_target);
    $db->exec("UPDATE wp_wc_order_addresses SET first_name = 'Target' WHERE id = 21");
    $db->exec("UPDATE wp_wc_orders_meta SET meta_value = 'Target note' WHERE id = 24");
    $db->exec("UPDATE wp_woocommerce_order_items SET order_item_name = 'Target product' WHERE order_item_id = 22");
    $db->exec("INSERT INTO wp_wc_product_meta_lookup (product_id, sku) VALUES (200, 'target-product')");
    $db->exec("UPDATE wp_woocommerce_order_itemmeta SET meta_value = '200' WHERE meta_id = 23");
    $target_recent_orders = json_encode(['recent_order_ids' => [20], 'target_note' => 'edited on main'], JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare("UPDATE wp_options SET option_value = :value WHERE option_name = 'woocommerce_recent_order_ids'");
    $stmt->bindValue(':value', $target_recent_orders, SQLITE3_TEXT);
    $stmt->execute();
    $db->close();

    $woocommerce_result = cow_merge_branch_state(
        $woocommerce_base,
        $woocommerce_source,
        $woocommerce_target,
        $woocommerce_metadata,
        'feature-plugin-woocommerce-source',
        'main',
        $woocommerce_file_base,
        $woocommerce_source_root,
        $woocommerce_target_root
    );

    assert_same($woocommerce_result['status'], 'completed_with_conflicts', 'WooCommerce HPOS validator holds orphaned order graphs for review');
    assert_same((int)($woocommerce_result['plugin_validators'] ?? 0), 1, 'WooCommerce HPOS validator is discovered from mu-plugins during merge');
    assert_same((int)($woocommerce_result['plugin_validator_conflicts'] ?? 0), 5, 'WooCommerce HPOS validator records address, meta, item, itemmeta, and option order graph conflicts');
    assert_same((int)scalar($woocommerce_target, 'SELECT COUNT(*) FROM wp_wc_orders WHERE id = 20'), 0, 'WooCommerce HPOS validator leaves the source order delete staged for review');
    assert_same(scalar($woocommerce_target, 'SELECT first_name FROM wp_wc_order_addresses WHERE id = 21'), 'Target', 'WooCommerce HPOS validator preserves target address edits for review');
    assert_same(scalar($woocommerce_target, 'SELECT meta_value FROM wp_wc_orders_meta WHERE id = 24'), 'Target note', 'WooCommerce HPOS validator preserves target order metadata edits for review');
    assert_same(scalar($woocommerce_target, 'SELECT order_item_name FROM wp_woocommerce_order_items WHERE order_item_id = 22'), 'Target product', 'WooCommerce HPOS validator preserves target order item edits for review');
    assert_same(scalar($woocommerce_target, 'SELECT meta_value FROM wp_woocommerce_order_itemmeta WHERE meta_id = 23'), '200', 'WooCommerce HPOS validator preserves target itemmeta edits for review');
    $woocommerce_option = json_decode((string)scalar($woocommerce_target, "SELECT option_value FROM wp_options WHERE option_name = 'woocommerce_recent_order_ids'"), true);
    assert_same($woocommerce_option['target_note'] ?? null, 'edited on main', 'WooCommerce HPOS validator preserves target option edits for review');
    assert_same($woocommerce_option['recent_order_ids'] ?? null, [20], 'WooCommerce HPOS validator keeps stale cached order IDs visible');

    $woocommerce_audit = cow_merge_audit_report($woocommerce_metadata, (int)$woocommerce_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'plugin' => 'woocommerce',
        'conflict_type' => 'plugin-woocommerce-hpos-missing-order',
    ]);
    assert_same(count($woocommerce_audit['conflicts']), 5, 'WooCommerce HPOS validator exposes every stale order reference as plugin audit conflicts');
    $woocommerce_preview = implode("\n", array_map(fn($conflict) => (string)($conflict['chosen_preview'] ?? ''), $woocommerce_audit['conflicts']));
    assert_true(str_contains($woocommerce_preview, '"order_id":20'), 'WooCommerce HPOS audit includes the missing order ID');
    $woocommerce_objects = [];
    foreach ($woocommerce_audit['conflicts'] as $conflict) {
        $payload = cow_merge_decode_payload_json((string)($conflict['chosen_payload'] ?? ''), 'WooCommerce HPOS validator payload');
        $woocommerce_objects[] = (string)($payload['object'] ?? '');
    }
    sort($woocommerce_objects);
    assert_same(
        $woocommerce_objects,
        ['option:woocommerce_recent_order_ids:0', 'order-address:21', 'order-item:22', 'order-itemmeta:23', 'order-meta:24'],
        'WooCommerce HPOS audit exposes the stale address, meta, item, itemmeta, and cached option objects'
    );
    $woocommerce_logical_identity_audit = cow_merge_audit_report($woocommerce_metadata, (int)$woocommerce_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'plugin' => 'woocommerce',
        'plugin_logical_identity' => json_encode(['plugin' => 'woocommerce', 'kind' => 'shop_order', 'order_id' => 20], JSON_UNESCAPED_SLASHES),
    ]);
    assert_same(count($woocommerce_logical_identity_audit['conflicts']), 5, 'WooCommerce HPOS audit filters conflicts by plugin logical order identity');
    $woocommerce_group_audit = cow_merge_audit_report($woocommerce_metadata, (int)$woocommerce_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'plugin' => 'woocommerce',
        'group_by' => 'plugin-logical-identity',
    ]);
    $woocommerce_group_counts = [];
    foreach ($woocommerce_group_audit['conflict_groups'] as $group) {
        $woocommerce_group_counts[(string)$group['group_key']] = (int)$group['conflict_count'];
    }
    $woocommerce_order_group_count = 0;
    foreach ($woocommerce_group_counts as $group_key => $conflict_count) {
        if (str_contains($group_key, '"woocommerce"') && str_contains($group_key, '"shop_order"') && str_contains($group_key, '"order_id":20')) {
            $woocommerce_order_group_count += $conflict_count;
        }
    }
    assert_same($woocommerce_order_group_count, 5, 'WooCommerce HPOS audit groups stale graph findings by logical order identity');

    $woocommerce_product_base_root = $tmp . '/woocommerce-product-base';
    $woocommerce_product_source_root = $tmp . '/woocommerce-product-source';
    $woocommerce_product_target_root = $tmp . '/woocommerce-product-target';
    $woocommerce_product_base = $woocommerce_product_base_root . '/wp-content/database/.ht.sqlite';
    $woocommerce_product_source = $woocommerce_product_source_root . '/wp-content/database/.ht.sqlite';
    $woocommerce_product_target = $woocommerce_product_target_root . '/wp-content/database/.ht.sqlite';
    $woocommerce_product_metadata = $tmp . '/.forkpress/cow/merge/plugin-woocommerce-product-validator-metadata.sqlite';
    $woocommerce_product_file_base = $tmp . '/.forkpress/cow/merge/file-bases/plugin-woocommerce-product-validator.json';

    copy_tree_for_test($woocommerce_base_root, $woocommerce_product_base_root);
    copy_tree_for_test($woocommerce_product_base_root, $woocommerce_product_source_root);
    copy_tree_for_test($woocommerce_product_base_root, $woocommerce_product_target_root);
    cow_merge_capture_file_base($woocommerce_product_base_root, $woocommerce_product_file_base);
    cow_merge_allocate_autoincrement_bands($woocommerce_product_source, $woocommerce_product_metadata, 'feature-plugin-woocommerce-product-source');
    cow_merge_allocate_autoincrement_bands($woocommerce_product_target, $woocommerce_product_metadata, 'main');

    $db = open_db($woocommerce_product_source);
    $db->exec('DELETE FROM wp_wc_product_meta_lookup WHERE product_id = 100');
    $db->close();

    $db = open_db($woocommerce_product_target);
    $db->exec("UPDATE wp_woocommerce_order_items SET order_item_name = 'Target product still references lookup' WHERE order_item_id = 22");
    $db->close();

    $woocommerce_product_result = cow_merge_branch_state(
        $woocommerce_product_base,
        $woocommerce_product_source,
        $woocommerce_product_target,
        $woocommerce_product_metadata,
        'feature-plugin-woocommerce-product-source',
        'main',
        $woocommerce_product_file_base,
        $woocommerce_product_source_root,
        $woocommerce_product_target_root
    );

    assert_same($woocommerce_product_result['status'], 'completed_with_conflicts', 'WooCommerce HPOS validator holds order items pointing at deleted product lookup rows for review');
    assert_same((int)($woocommerce_product_result['plugin_validators'] ?? 0), 1, 'WooCommerce product lookup validator is discovered from mu-plugins during merge');
    assert_same((int)($woocommerce_product_result['plugin_validator_conflicts'] ?? 0), 1, 'WooCommerce product lookup validator records the stale product reference');
    assert_same((int)scalar($woocommerce_product_target, 'SELECT COUNT(*) FROM wp_wc_product_meta_lookup WHERE product_id = 100'), 0, 'WooCommerce product lookup validator leaves the source product lookup delete staged for review');
    assert_same(scalar($woocommerce_product_target, 'SELECT order_item_name FROM wp_woocommerce_order_items WHERE order_item_id = 22'), 'Target product still references lookup', 'WooCommerce product lookup validator preserves target order item edits for review');
    assert_same(scalar($woocommerce_product_target, "SELECT meta_value FROM wp_woocommerce_order_itemmeta WHERE meta_id = 23 AND meta_key = '_product_id'"), '100', 'WooCommerce product lookup validator keeps the stale product itemmeta visible');

    $woocommerce_product_audit = cow_merge_audit_report($woocommerce_product_metadata, (int)$woocommerce_product_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'plugin' => 'woocommerce',
        'conflict_type' => 'plugin-woocommerce-hpos-missing-product',
    ]);
    assert_same(count($woocommerce_product_audit['conflicts']), 1, 'WooCommerce HPOS audit exposes the stale product lookup reference as a plugin conflict');
    $woocommerce_product_payload = cow_merge_decode_payload_json((string)($woocommerce_product_audit['conflicts'][0]['chosen_payload'] ?? ''), 'WooCommerce product lookup validator payload');
    assert_same($woocommerce_product_payload['object'] ?? null, 'order-item-product:23', 'WooCommerce product audit identifies the order itemmeta product owner');
    assert_same($woocommerce_product_payload['candidate']['product_id'] ?? null, 100, 'WooCommerce product audit includes the missing product ID');
    assert_same($woocommerce_product_payload['candidate']['order_item_id'] ?? null, 22, 'WooCommerce product audit includes the order item using the missing product');

    $woocommerce_product_logical_identity_audit = cow_merge_audit_report($woocommerce_product_metadata, (int)$woocommerce_product_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'plugin' => 'woocommerce',
        'plugin_logical_identity' => json_encode(['plugin' => 'woocommerce', 'kind' => 'product', 'product_id' => 100], JSON_UNESCAPED_SLASHES),
    ]);
    assert_same(count($woocommerce_product_logical_identity_audit['conflicts']), 1, 'WooCommerce HPOS audit filters product lookup findings by plugin logical product identity');

    $woocommerce_itemmeta_base_root = $tmp . '/woocommerce-itemmeta-base';
    $woocommerce_itemmeta_source_root = $tmp . '/woocommerce-itemmeta-source';
    $woocommerce_itemmeta_target_root = $tmp . '/woocommerce-itemmeta-target';
    $woocommerce_itemmeta_base = $woocommerce_itemmeta_base_root . '/wp-content/database/.ht.sqlite';
    $woocommerce_itemmeta_source = $woocommerce_itemmeta_source_root . '/wp-content/database/.ht.sqlite';
    $woocommerce_itemmeta_target = $woocommerce_itemmeta_target_root . '/wp-content/database/.ht.sqlite';
    $woocommerce_itemmeta_metadata = $tmp . '/.forkpress/cow/merge/plugin-woocommerce-itemmeta-validator-metadata.sqlite';
    $woocommerce_itemmeta_file_base = $tmp . '/.forkpress/cow/merge/file-bases/plugin-woocommerce-itemmeta-validator.json';

    copy_tree_for_test($woocommerce_base_root, $woocommerce_itemmeta_base_root);
    copy_tree_for_test($woocommerce_itemmeta_base_root, $woocommerce_itemmeta_source_root);
    copy_tree_for_test($woocommerce_itemmeta_base_root, $woocommerce_itemmeta_target_root);
    cow_merge_capture_file_base($woocommerce_itemmeta_base_root, $woocommerce_itemmeta_file_base);
    cow_merge_allocate_autoincrement_bands($woocommerce_itemmeta_source, $woocommerce_itemmeta_metadata, 'feature-plugin-woocommerce-itemmeta-source');
    cow_merge_allocate_autoincrement_bands($woocommerce_itemmeta_target, $woocommerce_itemmeta_metadata, 'main');

    $db = open_db($woocommerce_itemmeta_source);
    $db->exec('DELETE FROM wp_woocommerce_order_items WHERE order_item_id = 22');
    $db->close();

    $db = open_db($woocommerce_itemmeta_target);
    $db->exec("UPDATE wp_woocommerce_order_itemmeta SET meta_value = 'target meta keeps product 100' WHERE meta_id = 23");
    $db->close();

    $woocommerce_itemmeta_result = cow_merge_branch_state(
        $woocommerce_itemmeta_base,
        $woocommerce_itemmeta_source,
        $woocommerce_itemmeta_target,
        $woocommerce_itemmeta_metadata,
        'feature-plugin-woocommerce-itemmeta-source',
        'main',
        $woocommerce_itemmeta_file_base,
        $woocommerce_itemmeta_source_root,
        $woocommerce_itemmeta_target_root
    );

    assert_same($woocommerce_itemmeta_result['status'], 'completed_with_conflicts', 'WooCommerce HPOS validator holds itemmeta pointing at deleted order items for review');
    assert_same((int)($woocommerce_itemmeta_result['plugin_validators'] ?? 0), 1, 'WooCommerce order-item metadata validator is discovered from mu-plugins during merge');
    assert_same((int)($woocommerce_itemmeta_result['plugin_validator_conflicts'] ?? 0), 1, 'WooCommerce order-item metadata validator records the stale itemmeta reference');
    assert_same((int)scalar($woocommerce_itemmeta_target, 'SELECT COUNT(*) FROM wp_woocommerce_order_items WHERE order_item_id = 22'), 0, 'WooCommerce order-item metadata validator leaves the source order-item delete staged for review');
    assert_same(scalar($woocommerce_itemmeta_target, 'SELECT meta_value FROM wp_woocommerce_order_itemmeta WHERE meta_id = 23'), 'target meta keeps product 100', 'WooCommerce order-item metadata validator preserves target itemmeta edits for review');

    $woocommerce_itemmeta_audit = cow_merge_audit_report($woocommerce_itemmeta_metadata, (int)$woocommerce_itemmeta_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'plugin' => 'woocommerce',
        'conflict_type' => 'plugin-woocommerce-hpos-missing-order-item',
    ]);
    assert_same(count($woocommerce_itemmeta_audit['conflicts']), 1, 'WooCommerce HPOS audit exposes stale order item metadata as a plugin conflict');
    $woocommerce_itemmeta_payload = cow_merge_decode_payload_json((string)($woocommerce_itemmeta_audit['conflicts'][0]['chosen_payload'] ?? ''), 'WooCommerce order itemmeta validator payload');
    assert_same($woocommerce_itemmeta_payload['object'] ?? null, 'order-itemmeta:23', 'WooCommerce itemmeta audit identifies the stale itemmeta row');
    assert_same($woocommerce_itemmeta_payload['candidate']['order_item_id'] ?? null, 22, 'WooCommerce itemmeta audit includes the missing order item ID');

    $woocommerce_itemmeta_logical_identity_audit = cow_merge_audit_report($woocommerce_itemmeta_metadata, (int)$woocommerce_itemmeta_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'plugin' => 'woocommerce',
        'plugin_logical_identity' => json_encode(['plugin' => 'woocommerce', 'kind' => 'order_item', 'order_item_id' => 22], JSON_UNESCAPED_SLASHES),
    ]);
    assert_same(count($woocommerce_itemmeta_logical_identity_audit['conflicts']), 1, 'WooCommerce HPOS audit filters stale itemmeta findings by plugin logical order-item identity');

    $gravity_base_root = $tmp . '/gravity-base';
    $gravity_source_root = $tmp . '/gravity-source';
    $gravity_target_root = $tmp . '/gravity-target';
    $gravity_base = $gravity_base_root . '/wp-content/database/.ht.sqlite';
    $gravity_source = $gravity_source_root . '/wp-content/database/.ht.sqlite';
    $gravity_target = $gravity_target_root . '/wp-content/database/.ht.sqlite';
    $gravity_metadata = $tmp . '/.forkpress/cow/merge/plugin-gravity-validator-metadata.sqlite';
    $gravity_file_base = $tmp . '/.forkpress/cow/merge/file-bases/plugin-gravity-validator.json';

    mkdir($gravity_base_root . '/wp-content/database', 0777, true);
    create_gravity_forms_validator_db($gravity_base);
    write_test_file($gravity_base_root . '/wp-content/mu-plugins/forkpress-merge-validator.php', <<<'PHP'
<?php
$db = new SQLite3((string)getenv('FORKPRESS_MERGE_TARGET_DB'));
$findings = [];
$forms = [];
$form_meta = $db->query('SELECT form_id, display_meta FROM wp_gf_form_meta ORDER BY form_id');
while ($row = $form_meta->fetchArray(SQLITE3_ASSOC)) {
    $payload = json_decode((string)$row['display_meta'], true);
    $field_ids = [];
    if (is_array($payload)) {
        foreach (($payload['fields'] ?? []) as $field) {
            if (is_array($field) && array_key_exists('id', $field)) {
                $field_ids[(string)(int)$field['id']] = true;
            }
        }
    }
    $forms[(int)$row['form_id']] = $field_ids;
}
$entry_meta = $db->query('SELECT id, form_id, entry_id, meta_key, meta_value FROM wp_gf_entry_meta ORDER BY id');
while ($row = $entry_meta->fetchArray(SQLITE3_ASSOC)) {
    $form_id = (int)$row['form_id'];
    $field_id = (int)$row['meta_key'];
    if ((string)$field_id !== (string)$row['meta_key']) {
        continue;
    }
    if (isset($forms[$form_id][(string)$field_id])) {
        continue;
    }
    $findings[] = [
        'plugin' => 'gravityforms',
        'object' => 'entry-meta:' . (int)$row['id'] . ':field:' . $field_id,
        'reason' => 'Gravity Forms entry metadata references a field removed from the form definition',
        'type' => 'plugin-gravityforms-missing-field-definition',
        'tables' => ['wp_gf_form_meta', 'wp_gf_entry_meta'],
        'validator' => 'gravityforms-field-map@forkpress-test',
        'severity' => 'error',
        'logical_identity' => [
            'plugin' => 'gravityforms',
            'kind' => 'form_field',
            'form_id' => $form_id,
            'field_id' => $field_id,
        ],
        'candidate' => [
            'entry_meta_id' => (int)$row['id'],
            'entry_id' => (int)$row['entry_id'],
            'form_id' => $form_id,
            'field_id' => $field_id,
            'meta_value' => (string)$row['meta_value'],
        ],
    ];
}
echo json_encode([
    'status' => $findings ? 'conflicts' : 'valid',
    'findings' => $findings,
], JSON_UNESCAPED_SLASHES);
PHP);

    copy_tree_for_test($gravity_base_root, $gravity_source_root);
    copy_tree_for_test($gravity_base_root, $gravity_target_root);
    cow_merge_capture_file_base($gravity_base_root, $gravity_file_base);
    cow_merge_allocate_autoincrement_bands($gravity_source, $gravity_metadata, 'feature-plugin-gravity-source');
    cow_merge_allocate_autoincrement_bands($gravity_target, $gravity_metadata, 'main');

    $db = open_db($gravity_source);
    $display_meta_without_field = json_encode(['fields' => []], JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare('UPDATE wp_gf_form_meta SET display_meta = :display_meta WHERE form_id = 30');
    $stmt->bindValue(':display_meta', $display_meta_without_field, SQLITE3_TEXT);
    $stmt->execute();
    $db->close();

    $db = open_db($gravity_target);
    $db->exec("UPDATE wp_gf_entry_meta SET meta_value = 'Target edited field value' WHERE id = 41");
    $db->close();

    $gravity_result = cow_merge_branch_state(
        $gravity_base,
        $gravity_source,
        $gravity_target,
        $gravity_metadata,
        'feature-plugin-gravity-source',
        'main',
        $gravity_file_base,
        $gravity_source_root,
        $gravity_target_root
    );

    assert_same($gravity_result['status'], 'completed_with_conflicts', 'Gravity Forms validator holds entry metadata for removed form fields for review');
    assert_same((int)($gravity_result['plugin_validators'] ?? 0), 1, 'Gravity Forms field-map validator is discovered from mu-plugins during merge');
    assert_same((int)($gravity_result['plugin_validator_conflicts'] ?? 0), 1, 'Gravity Forms validator records the stale entry metadata field reference');
    $gravity_display_meta = json_decode((string)scalar($gravity_target, 'SELECT display_meta FROM wp_gf_form_meta WHERE form_id = 30'), true);
    assert_same($gravity_display_meta['fields'] ?? null, [], 'Gravity Forms validator leaves the source form field removal staged for review');
    assert_same(scalar($gravity_target, 'SELECT meta_value FROM wp_gf_entry_meta WHERE id = 41'), 'Target edited field value', 'Gravity Forms validator preserves target entry metadata edits for review');

    $gravity_audit = cow_merge_audit_report($gravity_metadata, (int)$gravity_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'plugin' => 'gravityforms',
        'conflict_type' => 'plugin-gravityforms-missing-field-definition',
    ]);
    assert_same(count($gravity_audit['conflicts']), 1, 'Gravity Forms audit exposes stale field-definition entry metadata as a plugin conflict');
    $gravity_payload = cow_merge_decode_payload_json((string)($gravity_audit['conflicts'][0]['chosen_payload'] ?? ''), 'Gravity Forms field-map validator payload');
    assert_same($gravity_payload['object'] ?? null, 'entry-meta:41:field:5', 'Gravity Forms audit identifies the stale entry metadata field');
    assert_same($gravity_payload['candidate']['form_id'] ?? null, 30, 'Gravity Forms audit includes the form ID');
    assert_same($gravity_payload['candidate']['field_id'] ?? null, 5, 'Gravity Forms audit includes the removed field ID');

    $gravity_logical_identity_audit = cow_merge_audit_report($gravity_metadata, (int)$gravity_result['run_id'], 10, [
        'scope' => 'plugin',
        'records' => 'conflicts',
        'plugin' => 'gravityforms',
        'plugin_logical_identity' => json_encode(['plugin' => 'gravityforms', 'kind' => 'form_field', 'form_id' => 30, 'field_id' => 5], JSON_UNESCAPED_SLASHES),
    ]);
    assert_same(count($gravity_logical_identity_audit['conflicts']), 1, 'Gravity Forms audit filters stale field findings by plugin logical form-field identity');

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

    $bad_severity_validator = $tmp . '/plugin-validator-bad-severity.php';
    write_test_file($bad_severity_validator, <<<'PHP'
<?php
echo json_encode([
    'status' => 'conflicts',
    'findings' => [
        [
            'plugin' => 'forkpress-plugin-graph',
            'object' => 'child:bad-severity',
            'reason' => 'malformed finding uses an unsupported severity',
            'type' => 'plugin-graph-bad-severity',
            'severity' => 'urgent',
        ],
    ],
], JSON_UNESCAPED_SLASHES);
PHP);
    $bad_severity = run_merge_cli([
        'run-plugin-validator',
        '--metadata-db', $metadata,
        '--run', (string)$result['run_id'],
        '--validator', $bad_severity_validator,
        '--format', 'json',
    ]);
    assert_true($bad_severity['status'] !== 0, 'plugin validator runner rejects malformed finding severity');
    assert_true(str_contains($bad_severity['output'], 'severity must be info, warning, error, or critical'), 'plugin validator runner explains malformed severity values');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE row_identity LIKE '%child:bad-severity%'"),
        0,
        'plugin validator runner does not record findings with malformed severity'
    );

    $bad_guidance_validator = $tmp . '/plugin-validator-bad-guidance.php';
    write_test_file($bad_guidance_validator, <<<'PHP'
<?php
echo json_encode([
    'status' => 'conflicts',
    'findings' => [
        [
            'plugin' => 'forkpress-plugin-graph',
            'object' => 'child:bad-guidance',
            'reason' => 'malformed finding uses non-string review guidance',
            'type' => 'plugin-graph-bad-guidance',
            'resolution_policy' => ['review-only'],
        ],
    ],
], JSON_UNESCAPED_SLASHES);
PHP);
    $bad_guidance = run_merge_cli([
        'run-plugin-validator',
        '--metadata-db', $metadata,
        '--run', (string)$result['run_id'],
        '--validator', $bad_guidance_validator,
        '--format', 'json',
    ]);
    assert_true($bad_guidance['status'] !== 0, 'plugin validator runner rejects malformed review guidance');
    assert_true(str_contains($bad_guidance['output'], 'resolution policy must be a string'), 'plugin validator runner explains malformed review guidance');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE row_identity LIKE '%child:bad-guidance%'"),
        0,
        'plugin validator runner does not record findings with malformed review guidance'
    );

    $bad_paths_validator = $tmp . '/plugin-validator-bad-paths.php';
    write_test_file($bad_paths_validator, <<<'PHP'
<?php
echo json_encode([
    'status' => 'conflicts',
    'findings' => [
        [
            'plugin' => 'forkpress-plugin-graph',
            'object' => 'child:bad-paths',
            'reason' => 'malformed finding uses scalar paths',
            'type' => 'plugin-graph-bad-paths',
            'paths' => 'wp-content/uploads/plugin.dat',
        ],
    ],
], JSON_UNESCAPED_SLASHES);
PHP);
    $bad_paths = run_merge_cli([
        'run-plugin-validator',
        '--metadata-db', $metadata,
        '--run', (string)$result['run_id'],
        '--validator', $bad_paths_validator,
        '--format', 'json',
    ]);
    assert_true($bad_paths['status'] !== 0, 'plugin validator runner rejects malformed paths fields');
    assert_true(str_contains($bad_paths['output'], 'paths must be a list of strings'), 'plugin validator runner explains malformed paths fields');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE row_identity LIKE '%child:bad-paths%'"),
        0,
        'plugin validator runner does not record findings with malformed paths'
    );

    $bad_tables_validator = $tmp . '/plugin-validator-bad-tables.php';
    write_test_file($bad_tables_validator, <<<'PHP'
<?php
echo json_encode([
    'status' => 'conflicts',
    'findings' => [
        [
            'plugin' => 'forkpress-plugin-graph',
            'object' => 'child:bad-tables',
            'reason' => 'malformed finding uses non-string table entries',
            'type' => 'plugin-graph-bad-tables',
            'tables' => [['plugin_graph_child']],
        ],
    ],
], JSON_UNESCAPED_SLASHES);
PHP);
    $bad_tables = run_merge_cli([
        'run-plugin-validator',
        '--metadata-db', $metadata,
        '--run', (string)$result['run_id'],
        '--validator', $bad_tables_validator,
        '--format', 'json',
    ]);
    assert_true($bad_tables['status'] !== 0, 'plugin validator runner rejects malformed table entries');
    assert_true(str_contains($bad_tables['output'], 'tables entries must be strings'), 'plugin validator runner explains malformed table entries');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE row_identity LIKE '%child:bad-tables%'"),
        0,
        'plugin validator runner does not record findings with malformed tables'
    );

    $bad_validator_identity = $tmp . '/plugin-validator-bad-identity.php';
    write_test_file($bad_validator_identity, <<<'PHP'
<?php
echo json_encode([
    'status' => 'conflicts',
    'findings' => [
        [
            'plugin' => 'forkpress-plugin-graph',
            'object' => 'child:bad-validator-identity',
            'reason' => 'malformed finding uses a non-string validator identity',
            'type' => 'plugin-graph-bad-validator-identity',
            'validator' => ['forkpress-plugin-graph@1'],
        ],
    ],
], JSON_UNESCAPED_SLASHES);
PHP);
    $bad_validator_identity_result = run_merge_cli([
        'run-plugin-validator',
        '--metadata-db', $metadata,
        '--run', (string)$result['run_id'],
        '--validator', $bad_validator_identity,
        '--format', 'json',
    ]);
    assert_true($bad_validator_identity_result['status'] !== 0, 'plugin validator runner rejects malformed validator identity');
    assert_true(str_contains($bad_validator_identity_result['output'], 'validator identity must be a string'), 'plugin validator runner explains malformed validator identity');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE row_identity LIKE '%child:bad-validator-identity%'"),
        0,
        'plugin validator runner does not record findings with malformed validator identity'
    );

    $empty_validator_identity = $tmp . '/plugin-validator-empty-identity.php';
    write_test_file($empty_validator_identity, <<<'PHP'
<?php
echo json_encode([
    'status' => 'conflicts',
    'findings' => [
        [
            'plugin' => 'forkpress-plugin-graph',
            'object' => 'child:empty-validator-identity',
            'reason' => 'malformed finding uses empty validator identity',
            'type' => 'plugin-graph-empty-validator-identity',
            'validator' => '   ',
        ],
    ],
], JSON_UNESCAPED_SLASHES);
PHP);
    $empty_validator_identity_result = run_merge_cli([
        'run-plugin-validator',
        '--metadata-db', $metadata,
        '--run', (string)$result['run_id'],
        '--validator', $empty_validator_identity,
        '--format', 'json',
    ]);
    assert_true($empty_validator_identity_result['status'] !== 0, 'plugin validator runner rejects empty validator identity');
    assert_true(str_contains($empty_validator_identity_result['output'], 'validator identity must not be empty'), 'plugin validator runner explains empty validator identity');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE row_identity LIKE '%child:empty-validator-identity%'"),
        0,
        'plugin validator runner does not record findings with empty validator identity'
    );

    $empty_guidance_validator = $tmp . '/plugin-validator-empty-guidance.php';
    write_test_file($empty_guidance_validator, <<<'PHP'
<?php
echo json_encode([
    'status' => 'conflicts',
    'findings' => [
        [
            'plugin' => 'forkpress-plugin-graph',
            'object' => 'child:empty-guidance',
            'reason' => 'malformed finding uses empty review guidance',
            'type' => 'plugin-graph-empty-guidance',
            'manual_review_reason' => '   ',
        ],
    ],
], JSON_UNESCAPED_SLASHES);
PHP);
    $empty_guidance = run_merge_cli([
        'run-plugin-validator',
        '--metadata-db', $metadata,
        '--run', (string)$result['run_id'],
        '--validator', $empty_guidance_validator,
        '--format', 'json',
    ]);
    assert_true($empty_guidance['status'] !== 0, 'plugin validator runner rejects empty review guidance');
    assert_true(str_contains($empty_guidance['output'], 'manual review reason must not be empty'), 'plugin validator runner explains empty review guidance');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE row_identity LIKE '%child:empty-guidance%'"),
        0,
        'plugin validator runner does not record findings with empty review guidance'
    );

    $empty_logical_identity_validator = $tmp . '/plugin-validator-empty-logical-identity.php';
    write_test_file($empty_logical_identity_validator, <<<'PHP'
<?php
echo json_encode([
    'status' => 'conflicts',
    'findings' => [
        [
            'plugin' => 'forkpress-plugin-graph',
            'object' => 'child:empty-logical-identity',
            'reason' => 'malformed finding uses empty identity evidence',
            'type' => 'plugin-graph-empty-logical-identity',
            'logical_identity' => [],
        ],
    ],
], JSON_UNESCAPED_SLASHES);
PHP);
    $empty_logical_identity = run_merge_cli([
        'run-plugin-validator',
        '--metadata-db', $metadata,
        '--run', (string)$result['run_id'],
        '--validator', $empty_logical_identity_validator,
        '--format', 'json',
    ]);
    assert_true($empty_logical_identity['status'] !== 0, 'plugin validator runner rejects empty logical identity');
    assert_true(str_contains($empty_logical_identity['output'], 'logical identity must not be empty'), 'plugin validator runner explains empty logical identity');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE row_identity LIKE '%child:empty-logical-identity%'"),
        0,
        'plugin validator runner does not record findings with empty logical identity'
    );

    $null_logical_identity_validator = $tmp . '/plugin-validator-null-logical-identity.php';
    write_test_file($null_logical_identity_validator, <<<'PHP'
<?php
echo json_encode([
    'status' => 'conflicts',
    'findings' => [
        [
            'plugin' => 'forkpress-plugin-graph',
            'object' => 'child:null-logical-identity',
            'reason' => 'malformed finding uses null identity evidence',
            'type' => 'plugin-graph-null-logical-identity',
            'logical_identity' => null,
        ],
    ],
], JSON_UNESCAPED_SLASHES);
PHP);
    $null_logical_identity = run_merge_cli([
        'run-plugin-validator',
        '--metadata-db', $metadata,
        '--run', (string)$result['run_id'],
        '--validator', $null_logical_identity_validator,
        '--format', 'json',
    ]);
    assert_true($null_logical_identity['status'] !== 0, 'plugin validator runner rejects null logical identity');
    assert_true(str_contains($null_logical_identity['output'], 'logical identity must not be null'), 'plugin validator runner explains null logical identity');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE row_identity LIKE '%child:null-logical-identity%'"),
        0,
        'plugin validator runner does not record findings with null logical identity'
    );
} finally {
    remove_tree($tmp);
}

if ($fail) {
    echo "FAILURES: $fail\n";
    exit(1);
}
echo "COW plugin validator focused tests passed ($pass assertions).\n";

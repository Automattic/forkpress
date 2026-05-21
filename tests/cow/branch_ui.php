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
    assert_true($actual === $expected, "$msg (got " . var_export($actual, true) . ", expected " . var_export($expected, true) . ")");
}

function rm_tree(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
    @rmdir($path);
}

echo "=== ForkPress WordPress branch UI actions ===\n";

umask(0022);

$tmp = sys_get_temp_dir() . '/forkpress-cow-branch-ui-' . getmypid() . '-' . bin2hex(random_bytes(4));
$plugin = realpath(__DIR__ . '/../../wp-plugin/forkpress-wp.php');
assert_true($plugin !== false, 'ForkPress WordPress plugin fixture exists');
$cow_bootstrap = realpath(__DIR__ . '/../../runtime/cow/bootstrap_wp.php');
assert_true($cow_bootstrap !== false, 'ForkPress COW bootstrap fixture exists');
if ($cow_bootstrap !== false) {
    $bootstrap_config = file_get_contents($cow_bootstrap);
    assert_true(str_contains($bootstrap_config, "define('DISALLOW_FILE_MODS', false);"), 'ForkPress COW bootstrap allows wp-admin plugin installation screens');
    assert_true(str_contains($bootstrap_config, "define('FS_METHOD', 'direct');"), 'ForkPress COW bootstrap enables direct plugin writes');
    assert_true(str_contains($bootstrap_config, "define('WP_HTTP_BLOCK_EXTERNAL', false);"), 'ForkPress COW bootstrap allows plugin download HTTP requests');
}
mkdir($tmp, 0777, true);
register_shutdown_function(static function () use ($tmp): void {
    rm_tree($tmp);
});

$fake_bin = $tmp . '/forkpress';
$cli_log = $tmp . '/cli-argv.jsonl';
$plugin_driver = $tmp . '/forkpress-plugin-driver.php';
$discovered_plugins_dir = $tmp . '/wp-content/plugins';
$discovered_plugin_driver = $discovered_plugins_dir . '/real-plugin/forkpress-merge-driver.php';
$single_file_plugin_driver = $discovered_plugins_dir . '/solo.forkpress-merge-driver.php';
$discovered_mu_plugins_dir = $tmp . '/wp-content/mu-plugins';
$mu_plugin_driver = $discovered_mu_plugins_dir . '/forkpress-merge-driver.php';
$work_dir = $tmp . '/site';
$branch_list = $tmp . '/branches.txt';
$runner = $tmp . '/run-action.php';
mkdir($work_dir, 0777, true);
mkdir(dirname($discovered_plugin_driver), 0777, true);
mkdir($discovered_mu_plugins_dir, 0777, true);
file_put_contents($plugin_driver, "<?php echo json_encode(['status' => 'validated', 'result' => ['ok' => true]]);\n");
chmod($plugin_driver, 0755);
file_put_contents($discovered_plugin_driver, "<?php echo json_encode(['status' => 'validated', 'result' => ['discovered' => true]]);\n");
chmod($discovered_plugin_driver, 0755);
file_put_contents($single_file_plugin_driver, "<?php echo json_encode(['status' => 'validated', 'result' => ['single_file' => true]]);\n");
chmod($single_file_plugin_driver, 0755);
file_put_contents($mu_plugin_driver, "<?php echo json_encode(['status' => 'validated', 'result' => ['mu_plugin' => true]]);\n");
chmod($mu_plugin_driver, 0755);

file_put_contents($fake_bin, <<<'PHP'
#!/usr/bin/env php
<?php
$log = getenv('FORKPRESS_TEST_CLI_LOG');
if (is_string($log) && $log !== '') {
    file_put_contents($log, json_encode($argv, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
}
if (getenv('FORKPRESS_TEST_CLI_FAIL') === '1') {
    fwrite(STDERR, "synthetic branch command failure\n");
    exit(19);
}
$outputs = json_decode((string)getenv('FORKPRESS_TEST_CLI_OUTPUTS'), true);
if (is_array($outputs)) {
    $index = 0;
    if (is_string($log) && $log !== '' && is_readable($log)) {
        $lines = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $index = is_array($lines) ? max(0, count($lines) - 1) : 0;
    }
    $output = $outputs[$index] ?? '';
    if (is_scalar($output)) {
        fwrite(STDOUT, str_replace('\n', "\n", (string)$output));
    }
    exit(0);
}
$output = getenv('FORKPRESS_TEST_CLI_OUTPUT');
if (is_string($output) && $output !== '') {
    fwrite(STDOUT, str_replace('\n', "\n", $output));
}
exit(0);
PHP);
chmod($fake_bin, 0755);

file_put_contents($runner, <<<'PHP'
<?php
define('ABSPATH', sys_get_temp_dir() . '/forkpress-test-wp/');
$plugin_dir = getenv('FORKPRESS_TEST_WP_PLUGIN_DIR');
if (is_string($plugin_dir) && $plugin_dir !== '' && !defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', $plugin_dir);
}
$mu_plugin_dir = getenv('FORKPRESS_TEST_WPMU_PLUGIN_DIR');
if (is_string($mu_plugin_dir) && $mu_plugin_dir !== '' && !defined('WPMU_PLUGIN_DIR')) {
    define('WPMU_PLUGIN_DIR', $mu_plugin_dir);
}

function add_action($tag, $callback, $priority = 10, $accepted_args = 1) { return true; }
function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) { return true; }
function add_menu_page($page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null) {
    $GLOBALS['forkpress_test_menu_pages'][] = compact('page_title', 'menu_title', 'capability', 'menu_slug', 'callback', 'icon_url', 'position');
    return $menu_slug;
}
function admin_url($path = '') { return '/wp-admin/' . ltrim((string) $path, '/'); }
function current_user_can($capability) { return getenv('FORKPRESS_TEST_CAN_MANAGE') !== '0'; }
function check_admin_referer($action) { return true; }
function is_admin_bar_showing() { return true; }
function wp_create_nonce($action) { return 'nonce-' . $action; }
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="nonce-' . htmlspecialchars((string) $action, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'; }
function is_ssl() { return false; }
function wp_json_encode($payload) { return json_encode($payload, JSON_UNESCAPED_SLASHES); }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return trim((string) $value); }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_js($value) { return addslashes((string) $value); }
function get_option($name, $default = false) {
    if ($name === 'active_plugins') {
        $plugins = json_decode((string)getenv('FORKPRESS_TEST_ACTIVE_PLUGINS'), true);
        return is_array($plugins) ? $plugins : $default;
    }
    return $default;
}
function get_site_option($name, $default = false) {
    if ($name === 'active_sitewide_plugins') {
        $plugins = json_decode((string)getenv('FORKPRESS_TEST_ACTIVE_SITEWIDE_PLUGINS'), true);
        return is_array($plugins) ? $plugins : $default;
    }
    return $default;
}

$fqdb = getenv('FORKPRESS_TEST_FQDB');
if (is_string($fqdb) && $fqdb !== '' && !defined('FQDB')) {
    define('FQDB', $fqdb);
}
if (getenv('FORKPRESS_TEST_PREDEFINE_SQLITE_IDENTIFIER') === '1' && !function_exists('forkpress_cow_sqlite_identifier')) {
    function forkpress_cow_sqlite_identifier(string $name): string {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}

$async = getenv('FORKPRESS_TEST_ASYNC') !== '0';
$_SERVER = [
    'HTTP_HOST' => 'feature.wp.localhost:18080',
    'HTTP_ACCEPT' => $async ? 'application/json' : 'text/html',
    'REQUEST_URI' => '/wp-admin/',
];
$forwarded_proto = getenv('FORKPRESS_TEST_FORWARDED_PROTO');
if (is_string($forwarded_proto) && $forwarded_proto !== '') {
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = $forwarded_proto;
}
if ($async) {
    $_SERVER['HTTP_X_FORKPRESS_ASYNC'] = '1';
}
$_POST = json_decode(base64_decode($argv[2]), true);
if (!is_array($_POST)) {
    fwrite(STDERR, "invalid test post payload\n");
    exit(2);
}
$_REQUEST = $_POST;
$_GET = $_POST;

require $argv[1];

$action = $_POST['action'] ?? '';
if ($action === 'forkpress_branch_create') {
    forkpress_handle_branch_create();
}
if ($action === 'forkpress_branch_merge') {
    forkpress_handle_branch_merge();
}
if ($action === 'forkpress_branch_history') {
    forkpress_handle_branch_history();
}
if ($action === 'forkpress_branch_tree') {
    forkpress_handle_branch_tree();
}
if ($action === 'forkpress_branch_conflicts') {
    forkpress_handle_branch_conflicts();
}
if ($action === 'forkpress_branch_restore_crash') {
    forkpress_handle_branch_restore_crash();
}
if ($action === 'forkpress_branch_revalidate_conflicts') {
    forkpress_handle_branch_revalidate_conflicts();
}
if ($action === 'forkpress_branch_review_conflict') {
    forkpress_handle_branch_review_conflict();
}
if ($action === 'forkpress_branch_resolve_conflict') {
    forkpress_handle_branch_resolve_conflict();
}
if ($action === 'forkpress_branch_apply_reviewed_conflicts') {
    forkpress_handle_branch_apply_reviewed_conflicts();
}
if ($action === 'forkpress_branch_run_plugin_driver') {
    forkpress_handle_branch_run_plugin_driver();
}
if ($action === 'forkpress_branch_birth_notice') {
    ob_start();
    forkpress_branch_birth_admin_notice();
    $html = ob_get_clean();
    echo json_encode(['html' => $html], JSON_UNESCAPED_SLASHES);
    exit;
}
if ($action === 'forkpress_branch_notice') {
    ob_start();
    forkpress_branch_admin_notice();
    $html = ob_get_clean();
    echo json_encode(['html' => $html], JSON_UNESCAPED_SLASHES);
    exit;
}
if ($action === 'forkpress_branch_switcher_render') {
    ob_start();
    forkpress_branch_switcher_assets();
    forkpress_render_branch_switcher();
    $html = ob_get_clean();
    echo json_encode(['html' => $html], JSON_UNESCAPED_SLASHES);
    exit;
}
if ($action === 'forkpress_branch_admin_page') {
    ob_start();
    forkpress_register_branch_admin_page();
    forkpress_render_branch_admin_page();
    $html = ob_get_clean();
    echo json_encode(['html' => $html, 'menus' => $GLOBALS['forkpress_test_menu_pages'] ?? []], JSON_UNESCAPED_SLASHES);
    exit;
}

fwrite(STDERR, "unknown action\n");
exit(3);
PHP);

function run_branch_ui_action(array $post, array $branches, bool $cli_fail = false, bool $can_manage = true, bool $async = true, array $extra_env = []): array {
    global $tmp, $plugin, $runner, $fake_bin, $cli_log, $work_dir, $branch_list;

    @unlink($cli_log);
    file_put_contents($branch_list, implode("\n", $branches) . "\n");
    $env = [
        'PATH' => getenv('PATH') ?: '',
        'FORKPRESS_BIN' => $fake_bin,
        'FORKPRESS_WORK_DIR' => $work_dir,
        'FORKPRESS_BRANCH' => 'feature',
        'FORKPRESS_BRANCH_LIST' => $branch_list,
        'FORKPRESS_ROOT_HOST' => 'wp.localhost',
        'FORKPRESS_TEST_CLI_LOG' => $cli_log,
        'FORKPRESS_TEST_CLI_FAIL' => $cli_fail ? '1' : '0',
        'FORKPRESS_TEST_CAN_MANAGE' => $can_manage ? '1' : '0',
        'FORKPRESS_TEST_ASYNC' => $async ? '1' : '0',
    ];
    $env = array_merge($env, $extra_env);
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $payload = base64_encode(json_encode($post, JSON_UNESCAPED_SLASHES));
    $process = proc_open([PHP_BINARY, $runner, $plugin, $payload], $descriptor, $pipes, $tmp, $env);
    if (!is_resource($process)) {
        return ['status' => -1, 'stdout' => '', 'stderr' => 'failed to start child', 'argv' => []];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $argv = [];
    if (is_readable($cli_log)) {
        foreach (file($cli_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $argv[] = $decoded;
            }
        }
    }
    return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr, 'argv' => $argv];
}

function decode_branch_ui_payload(array $result): array {
    $payload = json_decode($result['stdout'], true);
    return is_array($payload) ? $payload : [];
}

$predeclared_helper_admin_page = run_branch_ui_action(
    ['action' => 'forkpress_branch_admin_page'],
    ['main', 'feature'],
    false,
    true,
    true,
    ['FORKPRESS_TEST_PREDEFINE_SQLITE_IDENTIFIER' => '1']
);
$predeclared_helper_payload = decode_branch_ui_payload($predeclared_helper_admin_page);
assert_same($predeclared_helper_admin_page['status'], 0, 'branch manager plugin loads when router already declared shared SQLite helpers');
assert_true(str_contains($predeclared_helper_payload['html'] ?? '', '/_forkpress/branches'), 'branch manager plugin still renders admin page after shared helper predeclaration');

$create = run_branch_ui_action(
    ['action' => 'forkpress_branch_create', 'branch' => 'new_feature', 'from' => 'feature'],
    ['main', 'feature']
);
$create_payload = decode_branch_ui_payload($create);
assert_same($create['status'], 0, 'branch create admin action exits cleanly');
assert_same($create_payload['success'] ?? null, true, 'branch create admin action returns JSON success');
assert_same($create_payload['message'] ?? null, 'Created branch new_feature.', 'branch create admin action reports the created branch');
assert_same($create_payload['url'] ?? null, 'http://new_feature.wp.localhost:18080/_forkpress/branches', 'branch create admin action redirects to the out-of-band branch manager');
assert_same($create_payload['managerUrl'] ?? null, 'http://new_feature.wp.localhost:18080/_forkpress/branches', 'branch create response exposes the new branch manager URL');
assert_same($create_payload['branches'][0]['name'] ?? null, 'new_feature', 'branch create response marks the new branch as current in refreshed switcher data');
assert_same($create_payload['branches'][0]['url'] ?? null, 'http://new_feature.wp.localhost:18080/wp-admin/', 'branch create response gives the new branch a usable admin URL');
assert_same($create_payload['branches'][1]['url'] ?? null, 'http://wp.localhost:18080/wp-admin/', 'branch create response gives main its own admin URL');
assert_same($create_payload['branches'][2]['url'] ?? null, 'http://feature.wp.localhost:18080/wp-admin/', 'branch create response keeps existing branch URLs distinct');
assert_same(count($create['argv']), 1, 'branch create admin action invokes ForkPress CLI once');
assert_same(
    array_slice($create['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'create', 'new_feature', '--from', 'feature'],
    'branch create admin action uses safe branch birth CLI path'
);

$forwarded_create = run_branch_ui_action(
    ['action' => 'forkpress_branch_create', 'branch' => 'forwarded_feature', 'from' => 'feature'],
    ['main', 'feature'],
    false,
    true,
    true,
    ['FORKPRESS_TEST_FORWARDED_PROTO' => 'https']
);
$forwarded_create_payload = decode_branch_ui_payload($forwarded_create);
assert_same($forwarded_create['status'], 0, 'branch create respects forwarded HTTPS proxy headers');
assert_same($forwarded_create_payload['url'] ?? null, 'https://forwarded_feature.wp.localhost:18080/_forkpress/branches', 'branch create returns HTTPS branch manager URL behind a proxy');
assert_same($forwarded_create_payload['branches'][1]['url'] ?? null, 'https://wp.localhost:18080/wp-admin/', 'branch create returns HTTPS main URL behind a proxy');

$non_async_create = run_branch_ui_action(
    ['action' => 'forkpress_branch_create', 'branch' => 'no_async_feature', 'from' => 'feature'],
    ['main', 'feature'],
    false,
    true,
    false
);
$non_async_create_payload = decode_branch_ui_payload($non_async_create);
assert_same($non_async_create['status'], 0, 'non-async branch create admin action exits cleanly');
assert_same($non_async_create_payload['success'] ?? null, true, 'non-async branch create admin action returns JSON success');
assert_same($non_async_create_payload['message'] ?? null, 'Created branch no_async_feature.', 'non-async branch create admin action reports the created branch');
assert_same(count($non_async_create['argv']), 1, 'non-async branch create admin action invokes ForkPress CLI once');
assert_same(
    array_slice($non_async_create['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'create', 'no_async_feature', '--from', 'feature'],
    'non-async branch create admin action still uses safe branch birth CLI path'
);

$merge = run_branch_ui_action(
    ['action' => 'forkpress_branch_merge', 'source' => 'feature', 'target' => 'main'],
    ['main', 'feature']
);
$merge_payload = decode_branch_ui_payload($merge);
assert_same($merge['status'], 0, 'branch merge admin action exits cleanly');
assert_same($merge_payload['success'] ?? null, true, 'branch merge admin action returns JSON success');
assert_same($merge_payload['type'] ?? null, 'notice', 'branch merge admin action returns notice type for clean merge');
assert_same($merge_payload['message'] ?? null, 'Merged feature into main.', 'branch merge admin action reports the merge');
assert_same($merge_payload['url'] ?? null, 'http://wp.localhost:18080/_forkpress/branches', 'branch merge admin action redirects to the out-of-band branch manager');
assert_same($merge_payload['managerUrl'] ?? null, 'http://wp.localhost:18080/_forkpress/branches', 'branch merge response exposes the target branch manager URL');
assert_same(count($merge['argv']), 1, 'branch merge admin action invokes ForkPress CLI once');
assert_same(
    array_slice($merge['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'merge', 'feature', '--into', 'main'],
    'branch merge admin action uses audited branch merge CLI path'
);

$history_json = json_encode([
    'runs' => [
        [
            'id' => 42,
            'source_branch' => 'feature',
            'target_branch' => 'main',
            'status' => 'completed_with_conflicts',
            'decision_count' => 9,
            'conflict_count' => 3,
            'finished_at' => '2026-05-18 12:00:00',
        ],
    ],
], JSON_UNESCAPED_SLASHES);
$history = run_branch_ui_action(
    ['action' => 'forkpress_branch_history', 'limit' => '5'],
    ['main', 'feature'],
    false,
    true,
    true,
    ['FORKPRESS_TEST_CLI_OUTPUT' => $history_json]
);
$history_payload = decode_branch_ui_payload($history);
assert_same($history['status'], 0, 'branch history admin action exits cleanly');
assert_same($history_payload['success'] ?? null, true, 'branch history admin action returns JSON success');
assert_same($history_payload['message'] ?? null, 'Loaded 1 merge history run.', 'branch history admin action reports loaded run count');
assert_same($history_payload['recordCount'] ?? null, 1, 'branch history admin action reports record count');
assert_same($history_payload['records'][0]['source_branch'] ?? null, 'feature', 'branch history admin action exposes source branch');
assert_same($history_payload['records'][0]['target_branch'] ?? null, 'main', 'branch history admin action exposes target branch');
assert_same($history_payload['historyCommand'] ?? null, 'forkpress branch history --limit 5 --format json', 'branch history admin action exposes the matching CLI command');
assert_same(count($history['argv']), 1, 'branch history admin action invokes ForkPress CLI once');
assert_same(
    array_slice($history['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'history', '--limit', '5', '--format', 'json'],
    'branch history admin action uses audited branch history CLI path'
);

$tree = run_branch_ui_action(
    ['action' => 'forkpress_branch_tree', 'limit' => '5'],
    ['main', 'feature'],
    false,
    true,
    true,
    ['FORKPRESS_TEST_CLI_OUTPUT' => $history_json]
);
$tree_payload = decode_branch_ui_payload($tree);
assert_same($tree['status'], 0, 'branch tree admin action exits cleanly');
assert_same($tree_payload['success'] ?? null, true, 'branch tree admin action returns JSON success');
assert_same($tree_payload['message'] ?? null, 'Loaded 1 branch tree edge.', 'branch tree admin action reports loaded edge count');
assert_same($tree_payload['recordCount'] ?? null, 1, 'branch tree admin action reports record count');
assert_same($tree_payload['records'][0]['source_branch'] ?? null, 'feature', 'branch tree admin action exposes source branch');
assert_same($tree_payload['records'][0]['target_branch'] ?? null, 'main', 'branch tree admin action exposes target branch');
assert_same($tree_payload['records'][0]['conflictSummary']['total'] ?? null, 3, 'branch tree admin action includes conflict totals for fast graph badges');
assert_same($tree_payload['records'][0]['conflictSummary']['unresolved'] ?? null, 3, 'branch tree admin action seeds unresolved conflict count without extra audits');
assert_same($tree_payload['records'][0]['conflictSummary']['estimated'] ?? null, true, 'branch tree admin action marks seeded conflict summaries as estimated');
assert_same($tree_payload['treeCommand'] ?? null, 'forkpress branch tree --limit 5 --format json', 'branch tree admin action exposes the matching CLI command');
assert_same(count($tree['argv']), 1, 'branch tree admin action invokes ForkPress CLI once');
assert_same(
    array_slice($tree['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'tree', '--limit', '5', '--format', 'json'],
    'branch tree admin action uses audited branch tree CLI path'
);

$metadata_db = $tmp . '/metadata.sqlite';
$metadata = new SQLite3($metadata_db);
$metadata->exec('CREATE TABLE merge_conflicts (id INTEGER PRIMARY KEY, run_id INTEGER NOT NULL)');
$metadata->exec('CREATE TABLE merge_conflict_events (id INTEGER PRIMARY KEY, conflict_id INTEGER NOT NULL, lifecycle_state TEXT NOT NULL)');
$metadata->exec('CREATE TABLE merge_resolutions (id INTEGER PRIMARY KEY, conflict_id INTEGER NOT NULL, applied INTEGER NOT NULL)');
$metadata->exec('INSERT INTO merge_conflicts (id, run_id) VALUES (1, 42), (2, 42), (3, 42)');
$metadata->exec("INSERT INTO merge_conflict_events (id, conflict_id, lifecycle_state) VALUES (1, 1, 'resolved'), (2, 2, 'unreviewed')");
$metadata->exec('INSERT INTO merge_resolutions (id, conflict_id, applied) VALUES (1, 3, 1)');
$metadata->close();
$tree_with_metadata_json = json_encode([
    'metadata_db' => $metadata_db,
    'runs' => [
        [
            'id' => 42,
            'source_branch' => 'feature',
            'target_branch' => 'main',
            'status' => 'completed_with_conflicts',
            'decision_count' => 9,
            'conflict_count' => 3,
            'finished_at' => '2026-05-18 12:00:00',
        ],
    ],
], JSON_UNESCAPED_SLASHES);
$tree_with_metadata = run_branch_ui_action(
    ['action' => 'forkpress_branch_tree', 'limit' => '5'],
    ['main', 'feature'],
    false,
    true,
    true,
    ['FORKPRESS_TEST_CLI_OUTPUT' => $tree_with_metadata_json]
);
$tree_with_metadata_payload = decode_branch_ui_payload($tree_with_metadata);
assert_same($tree_with_metadata_payload['records'][0]['conflictSummary']['total'] ?? null, 3, 'branch tree admin action reads conflict summary totals from metadata');
assert_same($tree_with_metadata_payload['records'][0]['conflictSummary']['resolved'] ?? null, 2, 'branch tree admin action reads resolved conflict counts from metadata');
assert_same($tree_with_metadata_payload['records'][0]['conflictSummary']['unresolved'] ?? null, 1, 'branch tree admin action reads unresolved conflict counts from metadata');
assert_same(isset($tree_with_metadata_payload['records'][0]['conflictSummary']['estimated']), false, 'branch tree admin action does not mark metadata-backed conflict summaries as estimated');

$conflicted_merge_output = "forkpress: merged feature into main\\n  run:       42\\n  status:    completed_with_conflicts\\n  applied:   yes\\n  conflicts: 3\\n";
$conflicted_merge = run_branch_ui_action(
    ['action' => 'forkpress_branch_merge', 'source' => 'feature', 'target' => 'main'],
    ['main', 'feature'],
    false,
    true,
    true,
    ['FORKPRESS_TEST_CLI_OUTPUT' => $conflicted_merge_output]
);
$conflicted_payload = decode_branch_ui_payload($conflicted_merge);
assert_same($conflicted_merge['status'], 0, 'conflicted branch merge admin action exits cleanly');
assert_same($conflicted_payload['success'] ?? null, true, 'conflicted branch merge admin action is still a successful request');
assert_same($conflicted_payload['type'] ?? null, 'warning', 'conflicted branch merge admin action returns warning type');
assert_same($conflicted_payload['mergeStatus'] ?? null, 'completed_with_conflicts', 'conflicted branch merge exposes merge status');
assert_same($conflicted_payload['conflicts'] ?? null, 3, 'conflicted branch merge exposes conflict count');
assert_same($conflicted_payload['run'] ?? null, 42, 'conflicted branch merge exposes merge run id');
assert_same(
    $conflicted_payload['auditCommand'] ?? null,
    'forkpress branch merge-audit --records conflicts --run 42',
    'conflicted branch merge exposes the exact audit command'
);
assert_same(
    $conflicted_payload['message'] ?? null,
    'Merged feature into main with 3 conflicts. Review them with `forkpress branch merge-audit --records conflicts --run 42`.',
    'conflicted branch merge tells users to review conflicts'
);

$audit_output = json_encode([
    'runs' => [
        [
            'id' => 42,
            'source_branch' => 'feature',
            'target_branch' => 'main',
            'status' => 'completed_with_conflicts',
            'conflict_count' => 3,
        ],
    ],
    'conflicts' => [
        [
            'id' => 7,
            'run_id' => 42,
            'conflict_key' => 'wp_posts:page:about',
            'table_name' => 'wp_posts',
            'conflict_type' => 'cell',
            'lifecycle_state' => 'needs-action',
            'next_action' => 'review',
            'resolution_choices' => ['source', 'target'],
            'blocked_resolution_choices' => [],
        ],
        [
            'id' => 8,
            'run_id' => 42,
            'conflict_key' => 'files:uploads/example.jpg',
            'table_name' => '__files__',
            'conflict_type' => 'file',
            'lifecycle_state' => 'needs-action',
            'next_action' => 'review',
        ],
    ],
    'conflict_summary' => [
        'total' => 3,
        'resolved' => 1,
        'unresolved' => 2,
        'by_lifecycle' => [
            'needs-action' => 2,
            'resolved' => 1,
        ],
        'by_next_action' => [
            'review' => 2,
            'none' => 1,
        ],
        'by_scope' => [
            'db' => 1,
            'files' => 1,
            'plugin' => 1,
        ],
    ],
], JSON_UNESCAPED_SLASHES);
$empty_crash_recovery_output = json_encode([
    'crash_recovery' => [],
], JSON_UNESCAPED_SLASHES);
$conflict_audit = run_branch_ui_action(
    ['action' => 'forkpress_branch_conflicts', 'run' => '42'],
    ['main', 'feature'],
    false,
    true,
    true,
    ['FORKPRESS_TEST_CLI_OUTPUTS' => json_encode([$empty_crash_recovery_output, $audit_output], JSON_UNESCAPED_SLASHES)]
);
$conflict_audit_payload = decode_branch_ui_payload($conflict_audit);
assert_same($conflict_audit['status'], 0, 'branch conflict audit admin action exits cleanly');
assert_same($conflict_audit_payload['success'] ?? null, true, 'branch conflict audit admin action returns JSON success');
assert_same($conflict_audit_payload['run'] ?? null, 42, 'branch conflict audit returns merge run id');
assert_same($conflict_audit_payload['recordCount'] ?? null, 2, 'branch conflict audit returns loaded conflict record count');
assert_same($conflict_audit_payload['totalConflicts'] ?? null, 3, 'branch conflict audit returns total run conflict count');
assert_same($conflict_audit_payload['records'][0]['conflict_key'] ?? null, 'wp_posts:page:about', 'branch conflict audit returns first-class conflict records');
assert_same($conflict_audit_payload['conflictSummary']['total'] ?? null, 3, 'branch conflict audit exposes summary total');
assert_same($conflict_audit_payload['conflictSummary']['unresolved'] ?? null, 2, 'branch conflict audit exposes unresolved conflict count');
assert_same($conflict_audit_payload['conflictSummary']['resolved'] ?? null, 1, 'branch conflict audit exposes resolved conflict count');
assert_same($conflict_audit_payload['conflictSummary']['by_scope']['plugin'] ?? null, 1, 'branch conflict audit exposes scope summary buckets');
assert_same($conflict_audit_payload['conflictSummary']['by_next_action']['review'] ?? null, 2, 'branch conflict audit exposes next-action summary buckets');
assert_same(
    $conflict_audit_payload['auditCommand'] ?? null,
    'forkpress branch merge-audit --records conflicts --run 42 --format json',
    'branch conflict audit exposes the exact JSON audit command'
);
assert_same(
    $conflict_audit_payload['message'] ?? null,
    'Loaded 2 of 3 conflict records for merge run 42.',
    'branch conflict audit reports loaded records'
);
assert_same(count($conflict_audit['argv']), 2, 'branch conflict audit admin action checks crash recovery before conflicts');
assert_same(
    array_slice($conflict_audit['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'merge-audit', '--records', 'crash-recovery', '--run', '42', '--format', 'json'],
    'branch conflict audit admin action checks crash recovery first'
);
assert_same(
    array_slice($conflict_audit['argv'][1] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'merge-audit', '--records', 'conflicts', '--run', '42', '--format', 'json'],
    'branch conflict audit admin action uses structured merge-audit JSON path'
);

$conflict_review = run_branch_ui_action(
    ['action' => 'forkpress_branch_review_conflict', 'conflict' => '7', 'run' => '42', 'status' => 'reviewed'],
    ['main', 'feature']
);
$conflict_review_payload = decode_branch_ui_payload($conflict_review);
assert_same($conflict_review['status'], 0, 'branch conflict review admin action exits cleanly');
assert_same($conflict_review_payload['success'] ?? null, true, 'branch conflict review admin action returns JSON success');
assert_same($conflict_review_payload['run'] ?? null, 42, 'branch conflict review returns merge run id');
assert_same($conflict_review_payload['conflict'] ?? null, 7, 'branch conflict review returns conflict id');
assert_same($conflict_review_payload['reviewStatus'] ?? null, 'reviewed', 'branch conflict review returns recorded status');
assert_same(
    array_slice($conflict_review['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'merge-review', 'conflict', '7', '--status', 'reviewed', '--note', 'Marked reviewed from the WordPress branch switcher.', '--reviewer', 'wordpress-ui'],
    'branch conflict review admin action records a first-class merge review note'
);

$custom_conflict_review = run_branch_ui_action(
    ['action' => 'forkpress_branch_review_conflict', 'conflict' => '7', 'run' => '42', 'status' => 'needs-action', 'note' => 'Check the source value with the editor before applying.'],
    ['main', 'feature']
);
assert_same($custom_conflict_review['status'], 0, 'branch conflict review accepts editable notes');
assert_same(
    array_slice($custom_conflict_review['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'merge-review', 'conflict', '7', '--status', 'needs-action', '--note', 'Check the source value with the editor before applying.', '--reviewer', 'wordpress-ui'],
    'branch conflict review passes editable branch-manager notes to the CLI'
);

$invalid_conflict_review = run_branch_ui_action(
    ['action' => 'forkpress_branch_review_conflict', 'conflict' => '7', 'status' => 'done'],
    ['main', 'feature']
);
$invalid_conflict_review_payload = decode_branch_ui_payload($invalid_conflict_review);
assert_same($invalid_conflict_review_payload['success'] ?? null, false, 'branch conflict review rejects invalid statuses');
assert_same(count($invalid_conflict_review['argv']), 0, 'branch conflict review rejects invalid statuses before invoking CLI');

$conflict_resolution = run_branch_ui_action(
    ['action' => 'forkpress_branch_resolve_conflict', 'conflict' => '7', 'run' => '42', 'choice' => 'source'],
    ['main', 'feature']
);
$conflict_resolution_payload = decode_branch_ui_payload($conflict_resolution);
assert_same($conflict_resolution['status'], 0, 'branch conflict resolution admin action exits cleanly');
assert_same($conflict_resolution_payload['success'] ?? null, true, 'branch conflict resolution admin action returns JSON success');
assert_same($conflict_resolution_payload['run'] ?? null, 42, 'branch conflict resolution returns merge run id');
assert_same($conflict_resolution_payload['conflict'] ?? null, 7, 'branch conflict resolution returns conflict id');
assert_same($conflict_resolution_payload['resolutionChoice'] ?? null, 'source', 'branch conflict resolution returns applied choice');
assert_same(
    array_slice($conflict_resolution['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'merge-resolve', 'conflict', '7', '--choice', 'source', '--apply', '--note', 'Applied source choice from the WordPress branch switcher.', '--reviewer', 'wordpress-ui'],
    'branch conflict resolution admin action applies a first-class merge resolution'
);

$custom_conflict_resolution = run_branch_ui_action(
    ['action' => 'forkpress_branch_resolve_conflict', 'conflict' => '7', 'run' => '42', 'choice' => 'target', 'note' => 'Keep the production copy because the source branch is stale.'],
    ['main', 'feature']
);
assert_same($custom_conflict_resolution['status'], 0, 'branch conflict resolution accepts editable notes');
assert_same(
    array_slice($custom_conflict_resolution['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'merge-resolve', 'conflict', '7', '--choice', 'target', '--apply', '--note', 'Keep the production copy because the source branch is stale.', '--reviewer', 'wordpress-ui'],
    'branch conflict resolution passes editable branch-manager notes to the CLI'
);

$custom_value_conflict_resolution = run_branch_ui_action(
    ['action' => 'forkpress_branch_resolve_conflict', 'conflict' => '7', 'run' => '42', 'choice' => 'custom', 'customValue' => '<p>Manually reconciled content</p>', 'note' => 'Use a manually reconciled value.'],
    ['main', 'feature']
);
$custom_value_conflict_resolution_payload = decode_branch_ui_payload($custom_value_conflict_resolution);
assert_same($custom_value_conflict_resolution_payload['success'] ?? null, true, 'branch conflict resolution accepts custom values');
assert_same($custom_value_conflict_resolution_payload['resolutionChoice'] ?? null, 'custom', 'branch conflict resolution reports custom choice');
assert_same(
    array_slice($custom_value_conflict_resolution['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'merge-resolve', 'conflict', '7', '--choice', 'custom', '--custom-value', '<p>Manually reconciled content</p>', '--apply', '--note', 'Use a manually reconciled value.', '--reviewer', 'wordpress-ui'],
    'branch conflict resolution passes raw custom values to the CLI'
);

$change_applied_resolution = run_branch_ui_action(
    ['action' => 'forkpress_branch_resolve_conflict', 'conflict' => '7', 'run' => '42', 'choice' => 'target', 'replaceApplied' => '1', 'note' => 'Switch the already applied resolution back to target.'],
    ['main', 'feature']
);
$change_applied_resolution_payload = decode_branch_ui_payload($change_applied_resolution);
assert_same($change_applied_resolution_payload['success'] ?? null, true, 'branch conflict resolution can request an applied-resolution change');
assert_same($change_applied_resolution_payload['replaceApplied'] ?? null, true, 'branch conflict resolution reports applied-resolution replacement mode');
assert_same(
    $change_applied_resolution_payload['message'] ?? null,
    'Changed conflict #7 to target.',
    'branch conflict resolution explains applied-resolution replacement'
);
assert_same(
    array_slice($change_applied_resolution['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'merge-resolve', 'conflict', '7', '--choice', 'target', '--apply', '--replace-applied', '--note', 'Switch the already applied resolution back to target.', '--reviewer', 'wordpress-ui'],
    'branch conflict resolution passes replace-applied mode to the CLI'
);

$invalid_conflict_resolution = run_branch_ui_action(
    ['action' => 'forkpress_branch_resolve_conflict', 'conflict' => '7', 'choice' => 'both'],
    ['main', 'feature']
);
$invalid_conflict_resolution_payload = decode_branch_ui_payload($invalid_conflict_resolution);
assert_same($invalid_conflict_resolution_payload['success'] ?? null, false, 'branch conflict resolution rejects invalid choices');
assert_same(count($invalid_conflict_resolution['argv']), 0, 'branch conflict resolution rejects invalid choices before invoking CLI');

$apply_reviewed_resolution = run_branch_ui_action(
    ['action' => 'forkpress_branch_resolve_conflict', 'conflict' => '7', 'run' => '42', 'applyReviewed' => '1'],
    ['main', 'feature'],
    false,
    true,
    true,
    ['FORKPRESS_TEST_CLI_OUTPUTS' => json_encode([
        json_encode(['run_id' => 42, 'checked' => 1, 'stale' => 0, 'carried' => 0, 'needs_action_conflicts' => []], JSON_UNESCAPED_SLASHES),
        '',
    ], JSON_UNESCAPED_SLASHES)]
);
$apply_reviewed_resolution_payload = decode_branch_ui_payload($apply_reviewed_resolution);
assert_same($apply_reviewed_resolution['status'], 0, 'branch conflict apply-reviewed action exits cleanly');
assert_same($apply_reviewed_resolution_payload['success'] ?? null, true, 'branch conflict apply-reviewed action returns JSON success');
assert_same($apply_reviewed_resolution_payload['resolutionChoice'] ?? null, 'reviewed', 'branch conflict apply-reviewed returns reviewed choice marker');
assert_same(
    array_slice($apply_reviewed_resolution['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'merge-audit', '--revalidate', '--run', '42', '--reviewer', 'wordpress-ui', '--format', 'json'],
    'branch conflict apply-reviewed action revalidates the run before applying'
);
assert_same(
    array_slice($apply_reviewed_resolution['argv'][1] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'merge-resolve', 'conflict', '7', '--apply-reviewed', '--note', 'Applied reviewed choice from the WordPress branch switcher.', '--reviewer', 'wordpress-ui'],
    'branch conflict apply-reviewed action applies the latest validated choice'
);

$stale_apply_reviewed = run_branch_ui_action(
    ['action' => 'forkpress_branch_resolve_conflict', 'conflict' => '7', 'run' => '42', 'applyReviewed' => '1'],
    ['main', 'feature'],
    false,
    true,
    true,
    ['FORKPRESS_TEST_CLI_OUTPUTS' => json_encode([
        json_encode([
            'run_id' => 42,
            'checked' => 2,
            'stale' => 1,
            'carried' => 1,
            'needs_action_conflicts' => [
                ['conflict_id' => 7, 'revalidation_class' => 'compatible-target-drift'],
            ],
        ], JSON_UNESCAPED_SLASHES),
    ], JSON_UNESCAPED_SLASHES)]
);
$stale_apply_reviewed_payload = decode_branch_ui_payload($stale_apply_reviewed);
assert_same($stale_apply_reviewed_payload['success'] ?? null, false, 'branch conflict apply-reviewed rejects stale reviewed choices');
assert_same($stale_apply_reviewed_payload['checked'] ?? null, 2, 'branch conflict stale apply-reviewed exposes revalidation checked count');
assert_same($stale_apply_reviewed_payload['stale'] ?? null, 1, 'branch conflict stale apply-reviewed exposes stale count');
assert_same($stale_apply_reviewed_payload['carried'] ?? null, 1, 'branch conflict stale apply-reviewed exposes carried count');
assert_same(count($stale_apply_reviewed['argv']), 1, 'branch conflict stale apply-reviewed stops before merge-resolve');
assert_same(
    array_slice($stale_apply_reviewed['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'merge-audit', '--revalidate', '--run', '42', '--reviewer', 'wordpress-ui', '--format', 'json'],
    'branch conflict stale apply-reviewed uses structured revalidation before blocking'
);

$mixed_conflict_resolution = run_branch_ui_action(
    ['action' => 'forkpress_branch_resolve_conflict', 'conflict' => '7', 'choice' => 'source', 'applyReviewed' => '1'],
    ['main', 'feature']
);
$mixed_conflict_resolution_payload = decode_branch_ui_payload($mixed_conflict_resolution);
assert_same($mixed_conflict_resolution_payload['success'] ?? null, false, 'branch conflict resolution rejects mixed choice and apply-reviewed');
assert_same(count($mixed_conflict_resolution['argv']), 0, 'branch conflict resolution rejects mixed apply modes before invoking CLI');

$mixed_replace_applied_resolution = run_branch_ui_action(
    ['action' => 'forkpress_branch_resolve_conflict', 'conflict' => '7', 'applyReviewed' => '1', 'replaceApplied' => '1'],
    ['main', 'feature']
);
$mixed_replace_applied_resolution_payload = decode_branch_ui_payload($mixed_replace_applied_resolution);
assert_same($mixed_replace_applied_resolution_payload['success'] ?? null, false, 'branch conflict resolution rejects mixed apply-reviewed and replace-applied');
assert_same(count($mixed_replace_applied_resolution['argv']), 0, 'branch conflict resolution rejects replace-applied apply modes before invoking CLI');

$after_revalidate_resolution = run_branch_ui_action(
    ['action' => 'forkpress_branch_resolve_conflict', 'conflict' => '7', 'run' => '42', 'choice' => 'source', 'afterRevalidate' => '1'],
    ['main', 'feature']
);
$after_revalidate_resolution_payload = decode_branch_ui_payload($after_revalidate_resolution);
assert_same($after_revalidate_resolution_payload['success'] ?? null, true, 'branch conflict after-revalidate resolution returns JSON success');
assert_same($after_revalidate_resolution_payload['afterRevalidate'] ?? null, true, 'branch conflict after-revalidate resolution reports guarded mode');
assert_same(
    array_slice($after_revalidate_resolution['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'merge-resolve', 'conflict', '7', '--choice', 'source', '--apply', '--after-revalidate', '--note', 'Applied source choice from the WordPress branch switcher.', '--reviewer', 'wordpress-ui'],
    'branch conflict after-revalidate resolution preserves the guarded CLI flag'
);

$mixed_after_revalidate_resolution = run_branch_ui_action(
    ['action' => 'forkpress_branch_resolve_conflict', 'conflict' => '7', 'applyReviewed' => '1', 'afterRevalidate' => '1'],
    ['main', 'feature']
);
$mixed_after_revalidate_resolution_payload = decode_branch_ui_payload($mixed_after_revalidate_resolution);
assert_same($mixed_after_revalidate_resolution_payload['success'] ?? null, false, 'branch conflict resolution rejects apply-reviewed after-revalidate');
assert_same(count($mixed_after_revalidate_resolution['argv']), 0, 'branch conflict resolution rejects apply-reviewed after-revalidate before invoking CLI');

$pending_crash_output = json_encode([
    'crash_recovery' => [
        [
            'run_id' => 42,
            'checkpoint' => 'plugin-driver-resolution',
            'source_branch' => 'feature',
            'target_branch' => 'main',
            'artifact_path' => '/tmp/forkpress/recovery.json',
            'target_db' => '/tmp/forkpress/main/wp-content/database/.ht.sqlite',
            'target_root' => '/tmp/forkpress/main',
            'target_db_snapshot' => ['backup' => '/tmp/forkpress/snap.sqlite'],
            'filesystem_snapshot' => ['entries' => []],
        ],
    ],
], JSON_UNESCAPED_SLASHES);
$pending_crash_audit = run_branch_ui_action(
    ['action' => 'forkpress_branch_conflicts', 'run' => '42'],
    ['main', 'feature'],
    false,
    true,
    true,
    ['FORKPRESS_TEST_CLI_OUTPUTS' => json_encode([$pending_crash_output, $audit_output], JSON_UNESCAPED_SLASHES)]
);
$pending_crash_payload = decode_branch_ui_payload($pending_crash_audit);
assert_same($pending_crash_payload['success'] ?? null, true, 'branch conflict audit returns pending crash recovery as successful warning payload');
assert_same($pending_crash_payload['type'] ?? null, 'warning', 'branch conflict audit treats pending crash recovery as a warning');
assert_same($pending_crash_payload['crashRecoveryCount'] ?? null, 1, 'branch conflict audit exposes pending crash recovery count');
assert_same($pending_crash_payload['crashRecovery'][0]['checkpoint'] ?? null, 'plugin-driver-resolution', 'branch conflict audit exposes crash recovery checkpoint');
assert_same(
    $pending_crash_payload['recoveryCommand'] ?? null,
    'forkpress branch recover-crash --run 42 --restore-target-db --restore-files',
    'branch conflict audit exposes recovery command with restore flags'
);
assert_same(count($pending_crash_audit['argv']), 1, 'branch conflict audit skips conflict actions while crash recovery is pending');
assert_same(
    array_slice($pending_crash_audit['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'merge-audit', '--records', 'crash-recovery', '--run', '42', '--format', 'json'],
    'branch conflict audit only checks crash recovery when recovery is pending'
);

$restore_crash_output = json_encode([
    'run_id' => 42,
    'restored' => 1,
    'pending' => 0,
], JSON_UNESCAPED_SLASHES);
$restore_crash = run_branch_ui_action(
    ['action' => 'forkpress_branch_restore_crash', 'run' => '42'],
    ['main', 'feature'],
    false,
    true,
    true,
    ['FORKPRESS_TEST_CLI_OUTPUT' => $restore_crash_output]
);
$restore_crash_payload = decode_branch_ui_payload($restore_crash);
assert_same($restore_crash_payload['success'] ?? null, true, 'branch crash restore returns JSON success');
assert_same($restore_crash_payload['type'] ?? null, 'notice', 'branch crash restore returns notice type after complete recovery');
assert_same($restore_crash_payload['restored'] ?? null, 1, 'branch crash restore exposes restored count');
assert_same($restore_crash_payload['pending'] ?? null, 0, 'branch crash restore exposes pending count');
assert_same(
    $restore_crash_payload['recoveryCommand'] ?? null,
    'forkpress branch recover-crash --run 42 --restore-target-db --restore-files',
    'branch crash restore exposes exact restore command'
);
assert_same(
    array_slice($restore_crash['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'recover-crash', '--run', '42', '--restore-target-db', '--restore-files', '--format', 'json'],
    'branch crash restore uses structured recover-crash command'
);

$invalid_restore_crash = run_branch_ui_action(
    ['action' => 'forkpress_branch_restore_crash', 'run' => 'abc'],
    ['main', 'feature']
);
$invalid_restore_crash_payload = decode_branch_ui_payload($invalid_restore_crash);
assert_same($invalid_restore_crash_payload['success'] ?? null, false, 'branch crash restore rejects invalid run ids');
assert_same($invalid_restore_crash_payload['message'] ?? null, 'Choose a merge run to restore.', 'branch crash restore explains invalid run ids');
assert_same(count($invalid_restore_crash['argv']), 0, 'branch crash restore does not invoke CLI for invalid run ids');

$invalid_restore_json = run_branch_ui_action(
    ['action' => 'forkpress_branch_restore_crash', 'run' => '42'],
    ['main', 'feature'],
    false,
    true,
    true,
    ['FORKPRESS_TEST_CLI_OUTPUT' => 'not-json']
);
$invalid_restore_json_payload = decode_branch_ui_payload($invalid_restore_json);
assert_same($invalid_restore_json_payload['success'] ?? null, false, 'branch crash restore rejects invalid CLI JSON');
assert_same($invalid_restore_json_payload['message'] ?? null, 'ForkPress returned invalid crash recovery restore JSON.', 'branch crash restore explains invalid CLI JSON');
assert_same(count($invalid_restore_json['argv']), 1, 'branch crash restore invokes CLI once before invalid JSON failure');

$invalid_audit = run_branch_ui_action(
    ['action' => 'forkpress_branch_conflicts', 'run' => 'abc'],
    ['main', 'feature']
);
$invalid_audit_payload = decode_branch_ui_payload($invalid_audit);
assert_same($invalid_audit_payload['success'] ?? null, false, 'branch conflict audit rejects invalid run ids');
assert_same($invalid_audit_payload['message'] ?? null, 'Choose a merge run to inspect.', 'branch conflict audit explains invalid run ids');
assert_same(count($invalid_audit['argv']), 0, 'branch conflict audit does not invoke CLI for invalid run ids');

$filtered_audit = run_branch_ui_action(
    [
        'action' => 'forkpress_branch_conflicts',
        'run' => '42',
        'scope' => 'plugin',
        'lifecycleState' => 'needs-action',
        'nextAction' => 'revalidate',
    ],
    ['main', 'feature'],
    false,
    true,
    true,
    ['FORKPRESS_TEST_CLI_OUTPUTS' => json_encode([$empty_crash_recovery_output, $audit_output], JSON_UNESCAPED_SLASHES)]
);
$filtered_audit_payload = decode_branch_ui_payload($filtered_audit);
assert_same($filtered_audit_payload['success'] ?? null, true, 'branch conflict audit accepts supported filters');
assert_same($filtered_audit_payload['filters']['scope'] ?? null, 'plugin', 'branch conflict audit returns scope filter');
assert_same($filtered_audit_payload['filters']['lifecycleState'] ?? null, 'needs-action', 'branch conflict audit returns lifecycle filter');
assert_same($filtered_audit_payload['filters']['nextAction'] ?? null, 'revalidate', 'branch conflict audit returns next-action filter');
assert_same(
    $filtered_audit_payload['auditCommand'] ?? null,
    'forkpress branch merge-audit --records conflicts --run 42 --scope plugin --lifecycle-state needs-action --next-action revalidate --format json',
    'branch conflict audit exposes filtered audit command'
);
assert_same(
    array_slice($filtered_audit['argv'][1] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'merge-audit', '--records', 'conflicts', '--run', '42', '--format', 'json', '--scope', 'plugin', '--lifecycle-state', 'needs-action', '--next-action', 'revalidate'],
    'branch conflict audit passes supported filters to merge-audit'
);

$invalid_filter_audit = run_branch_ui_action(
    ['action' => 'forkpress_branch_conflicts', 'run' => '42', 'scope' => 'everything'],
    ['main', 'feature']
);
$invalid_filter_payload = decode_branch_ui_payload($invalid_filter_audit);
assert_same($invalid_filter_payload['success'] ?? null, false, 'branch conflict audit rejects invalid filters');
assert_same($invalid_filter_payload['message'] ?? null, 'Choose a valid merge conflict scope.', 'branch conflict audit explains invalid filters');
assert_same(count($invalid_filter_audit['argv']), 0, 'branch conflict audit does not invoke CLI for invalid filters');

$revalidation_output = json_encode([
    'run_id' => 42,
    'checked' => 4,
    'stale' => 2,
    'carried' => 1,
    'needs_action_conflicts' => [
        ['conflict_id' => 7, 'revalidation_class' => 'compatible-target-drift'],
    ],
], JSON_UNESCAPED_SLASHES);
$revalidation = run_branch_ui_action(
    ['action' => 'forkpress_branch_revalidate_conflicts', 'run' => '42'],
    ['main', 'feature'],
    false,
    true,
    true,
    ['FORKPRESS_TEST_CLI_OUTPUT' => $revalidation_output]
);
$revalidation_payload = decode_branch_ui_payload($revalidation);
assert_same($revalidation_payload['success'] ?? null, true, 'branch conflict revalidation returns JSON success');
assert_same($revalidation_payload['type'] ?? null, 'warning', 'branch conflict revalidation returns warning type');
assert_same($revalidation_payload['message'] ?? null, 'Checked merge run 42 for changes: 4 checked, 2 changed, 1 unchanged.', 'branch conflict change check uses clear user-facing wording');
assert_same($revalidation_payload['checked'] ?? null, 4, 'branch conflict revalidation exposes checked count');
assert_same($revalidation_payload['stale'] ?? null, 2, 'branch conflict revalidation exposes stale count');
assert_same($revalidation_payload['carried'] ?? null, 1, 'branch conflict revalidation exposes carried count');
assert_same($revalidation_payload['changeCheck']['checked'] ?? null, 4, 'branch conflict change check exposes structured change-check details');
assert_same(
    $revalidation_payload['auditCommand'] ?? null,
    'forkpress branch merge-audit --revalidate --run 42 --reviewer wordpress-ui --format json',
    'branch conflict revalidation exposes exact command'
);
assert_same(
    array_slice($revalidation['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'merge-audit', '--revalidate', '--run', '42', '--reviewer', 'wordpress-ui', '--format', 'json'],
    'branch conflict revalidation uses structured revalidate command'
);

$apply_reviewed_output = json_encode([
    'run_id' => 42,
    'eligible' => 2,
    'applied' => 2,
    'status' => 'completed',
], JSON_UNESCAPED_SLASHES);
$apply_reviewed = run_branch_ui_action(
    ['action' => 'forkpress_branch_apply_reviewed_conflicts', 'run' => '42'],
    ['main', 'feature'],
    false,
    true,
    true,
    ['FORKPRESS_TEST_CLI_OUTPUT' => $apply_reviewed_output]
);
$apply_reviewed_payload = decode_branch_ui_payload($apply_reviewed);
assert_same($apply_reviewed_payload['success'] ?? null, true, 'branch reviewed-resolution apply action returns JSON success');
assert_same($apply_reviewed_payload['applied'] ?? null, 2, 'branch reviewed-resolution apply action exposes applied count');
assert_same($apply_reviewed_payload['eligible'] ?? null, 2, 'branch reviewed-resolution apply action exposes eligible count');
assert_same(
    $apply_reviewed_payload['message'] ?? null,
    'Applied 2 reviewed merge resolutions for run 42.',
    'branch reviewed-resolution apply action reports applied resolutions'
);
assert_same(
    $apply_reviewed_payload['applyReviewedCommand'] ?? null,
    'forkpress branch merge-apply-reviewed --run 42 --reviewer wordpress-ui --format json',
    'branch reviewed-resolution apply action exposes exact command'
);
assert_same(
    array_slice($apply_reviewed['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'merge-apply-reviewed', '--run', '42', '--reviewer', 'wordpress-ui', '--format', 'json'],
    'branch reviewed-resolution apply action uses structured apply command'
);

$driver_key = hash('sha256', 'forkpress-plugin-graph' . "\0" . realpath($plugin_driver));
$driver_output = json_encode([
    'conflict_id' => 7,
    'resolution_id' => 88,
    'driver_status' => 'validated',
    'driver' => realpath($plugin_driver),
    'choice' => 'plugin-driver',
    'applied' => false,
], JSON_UNESCAPED_SLASHES);
$plugin_driver_run = run_branch_ui_action(
    [
        'action' => 'forkpress_branch_run_plugin_driver',
        'run' => '42',
        'conflict' => '7',
        'driverKey' => $driver_key,
    ],
    ['main', 'feature'],
    false,
    true,
    true,
    [
        'FORKPRESS_PLUGIN_MERGE_DRIVERS' => json_encode(['forkpress-plugin-graph' => realpath($plugin_driver)], JSON_UNESCAPED_SLASHES),
        'FORKPRESS_TEST_CLI_OUTPUT' => $driver_output,
    ]
);
$plugin_driver_payload = decode_branch_ui_payload($plugin_driver_run);
assert_same($plugin_driver_payload['success'] ?? null, true, 'branch plugin driver action returns JSON success');
assert_same($plugin_driver_payload['driverStatus'] ?? null, 'validated', 'branch plugin driver action exposes driver status');
assert_same($plugin_driver_payload['run'] ?? null, 42, 'branch plugin driver action preserves merge run for refresh');
assert_same(
    $plugin_driver_payload['message'] ?? null,
    'Ran plugin driver for conflict #7: validated.',
    'branch plugin driver action reports validated driver result'
);
assert_same(count($plugin_driver_run['argv']), 1, 'branch plugin driver action invokes ForkPress CLI once');
assert_same(
    array_slice($plugin_driver_run['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'run-plugin-driver', 'conflict', '7', '--driver', realpath($plugin_driver), '--reviewer', 'wordpress-ui', '--format', 'json'],
    'branch plugin driver action uses approved run-plugin-driver CLI path'
);

$unapproved_plugin_driver = run_branch_ui_action(
    [
        'action' => 'forkpress_branch_run_plugin_driver',
        'run' => '42',
        'conflict' => '7',
        'driverKey' => 'not-approved',
    ],
    ['main', 'feature'],
    false,
    true,
    true,
    ['FORKPRESS_PLUGIN_MERGE_DRIVERS' => json_encode(['forkpress-plugin-graph' => realpath($plugin_driver)], JSON_UNESCAPED_SLASHES)]
);
$unapproved_plugin_driver_payload = decode_branch_ui_payload($unapproved_plugin_driver);
assert_same($unapproved_plugin_driver_payload['success'] ?? null, false, 'branch plugin driver action rejects unapproved drivers');
assert_same($unapproved_plugin_driver_payload['message'] ?? null, 'Choose an approved plugin merge driver.', 'branch plugin driver action explains unapproved drivers');
assert_same(count($unapproved_plugin_driver['argv']), 0, 'branch plugin driver action does not invoke CLI for unapproved drivers');

$discovered_driver_key = hash('sha256', 'real-plugin' . "\0" . realpath($discovered_plugin_driver));
$discovered_plugin_driver_run = run_branch_ui_action(
    [
        'action' => 'forkpress_branch_run_plugin_driver',
        'run' => '42',
        'conflict' => '7',
        'driverKey' => $discovered_driver_key,
    ],
    ['main', 'feature'],
    false,
    true,
    true,
    [
        'FORKPRESS_TEST_WP_PLUGIN_DIR' => $discovered_plugins_dir,
        'FORKPRESS_TEST_ACTIVE_PLUGINS' => json_encode(['real-plugin/real-plugin.php'], JSON_UNESCAPED_SLASHES),
        'FORKPRESS_TEST_CLI_OUTPUT' => $driver_output,
    ]
);
$discovered_plugin_driver_payload = decode_branch_ui_payload($discovered_plugin_driver_run);
assert_same($discovered_plugin_driver_payload['success'] ?? null, true, 'branch plugin driver action accepts discovered active plugin drivers');
assert_same(
    array_slice($discovered_plugin_driver_run['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'run-plugin-driver', 'conflict', '7', '--driver', realpath($discovered_plugin_driver), '--reviewer', 'wordpress-ui', '--format', 'json'],
    'branch plugin driver action uses discovered active plugin driver path'
);

$single_file_driver_key = hash('sha256', 'solo' . "\0" . realpath($single_file_plugin_driver));
$single_file_plugin_driver_run = run_branch_ui_action(
    [
        'action' => 'forkpress_branch_run_plugin_driver',
        'run' => '42',
        'conflict' => '7',
        'driverKey' => $single_file_driver_key,
    ],
    ['main', 'feature'],
    false,
    true,
    true,
    [
        'FORKPRESS_TEST_WP_PLUGIN_DIR' => $discovered_plugins_dir,
        'FORKPRESS_TEST_ACTIVE_PLUGINS' => json_encode(['solo.php'], JSON_UNESCAPED_SLASHES),
        'FORKPRESS_TEST_CLI_OUTPUT' => $driver_output,
    ]
);
$single_file_plugin_driver_payload = decode_branch_ui_payload($single_file_plugin_driver_run);
assert_same($single_file_plugin_driver_payload['success'] ?? null, true, 'branch plugin driver action accepts single-file active plugin drivers');
assert_same(
    array_slice($single_file_plugin_driver_run['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'run-plugin-driver', 'conflict', '7', '--driver', realpath($single_file_plugin_driver), '--reviewer', 'wordpress-ui', '--format', 'json'],
    'branch plugin driver action uses discovered single-file plugin driver path'
);

$mu_driver_key = hash('sha256', 'mu-plugins' . "\0" . realpath($mu_plugin_driver));
$mu_plugin_driver_run = run_branch_ui_action(
    [
        'action' => 'forkpress_branch_run_plugin_driver',
        'run' => '42',
        'conflict' => '7',
        'driverKey' => $mu_driver_key,
    ],
    ['main', 'feature'],
    false,
    true,
    true,
    [
        'FORKPRESS_TEST_WPMU_PLUGIN_DIR' => $discovered_mu_plugins_dir,
        'FORKPRESS_TEST_CLI_OUTPUT' => $driver_output,
    ]
);
$mu_plugin_driver_payload = decode_branch_ui_payload($mu_plugin_driver_run);
assert_same($mu_plugin_driver_payload['success'] ?? null, true, 'branch plugin driver action accepts discovered mu-plugin drivers');
assert_same(
    array_slice($mu_plugin_driver_run['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'run-plugin-driver', 'conflict', '7', '--driver', realpath($mu_plugin_driver), '--reviewer', 'wordpress-ui', '--format', 'json'],
    'branch plugin driver action uses discovered mu-plugin driver path'
);

$invalid_revalidation = run_branch_ui_action(
    ['action' => 'forkpress_branch_revalidate_conflicts', 'run' => 'abc'],
    ['main', 'feature']
);
$invalid_revalidation_payload = decode_branch_ui_payload($invalid_revalidation);
assert_same($invalid_revalidation_payload['success'] ?? null, false, 'branch conflict revalidation rejects invalid run ids');
assert_same($invalid_revalidation_payload['message'] ?? null, 'Choose a merge run to check for changes.', 'branch conflict revalidation explains invalid run ids');
assert_same(count($invalid_revalidation['argv']), 0, 'branch conflict revalidation does not invoke CLI for invalid run ids');

$invalid_apply_reviewed = run_branch_ui_action(
    ['action' => 'forkpress_branch_apply_reviewed_conflicts', 'run' => 'abc'],
    ['main', 'feature']
);
$invalid_apply_reviewed_payload = decode_branch_ui_payload($invalid_apply_reviewed);
assert_same($invalid_apply_reviewed_payload['success'] ?? null, false, 'branch reviewed-resolution apply rejects invalid run ids');
assert_same($invalid_apply_reviewed_payload['message'] ?? null, 'Choose a merge run to apply reviewed resolutions.', 'branch reviewed-resolution apply explains invalid run ids');
assert_same(count($invalid_apply_reviewed['argv']), 0, 'branch reviewed-resolution apply does not invoke CLI for invalid run ids');

$invalid_revalidation_json = run_branch_ui_action(
    ['action' => 'forkpress_branch_revalidate_conflicts', 'run' => '42'],
    ['main', 'feature'],
    false,
    true,
    true,
    ['FORKPRESS_TEST_CLI_OUTPUT' => 'not-json']
);
$invalid_revalidation_json_payload = decode_branch_ui_payload($invalid_revalidation_json);
assert_same($invalid_revalidation_json_payload['success'] ?? null, false, 'branch conflict revalidation rejects invalid CLI JSON');
assert_same($invalid_revalidation_json_payload['message'] ?? null, 'ForkPress returned invalid conflict change-check JSON.', 'branch conflict revalidation explains invalid CLI JSON');

$invalid_json_audit = run_branch_ui_action(
    ['action' => 'forkpress_branch_conflicts', 'run' => '42'],
    ['main', 'feature'],
    false,
    true,
    true,
    ['FORKPRESS_TEST_CLI_OUTPUTS' => json_encode([$empty_crash_recovery_output, 'not-json'], JSON_UNESCAPED_SLASHES)]
);
$invalid_json_payload = decode_branch_ui_payload($invalid_json_audit);
assert_same($invalid_json_payload['success'] ?? null, false, 'branch conflict audit rejects invalid CLI JSON');
assert_same($invalid_json_payload['message'] ?? null, 'ForkPress returned invalid merge audit JSON.', 'branch conflict audit explains invalid CLI JSON');

$invalid_crash_json_audit = run_branch_ui_action(
    ['action' => 'forkpress_branch_conflicts', 'run' => '42'],
    ['main', 'feature'],
    false,
    true,
    true,
    ['FORKPRESS_TEST_CLI_OUTPUT' => 'not-json']
);
$invalid_crash_json_payload = decode_branch_ui_payload($invalid_crash_json_audit);
assert_same($invalid_crash_json_payload['success'] ?? null, false, 'branch conflict audit rejects invalid crash recovery JSON');
assert_same($invalid_crash_json_payload['message'] ?? null, 'ForkPress returned invalid crash recovery JSON.', 'branch conflict audit explains invalid crash recovery JSON');

$invalid_history_json = run_branch_ui_action(
    ['action' => 'forkpress_branch_history', 'limit' => '10'],
    ['main', 'feature'],
    false,
    true,
    true,
    ['FORKPRESS_TEST_CLI_OUTPUT' => 'not-json']
);
$invalid_history_payload = decode_branch_ui_payload($invalid_history_json);
assert_same($invalid_history_payload['success'] ?? null, false, 'branch history rejects invalid CLI JSON');
assert_same($invalid_history_payload['message'] ?? null, 'ForkPress returned invalid merge history JSON.', 'branch history explains invalid CLI JSON');

$invalid_tree_json = run_branch_ui_action(
    ['action' => 'forkpress_branch_tree', 'limit' => '10'],
    ['main', 'feature'],
    false,
    true,
    true,
    ['FORKPRESS_TEST_CLI_OUTPUT' => 'not-json']
);
$invalid_tree_payload = decode_branch_ui_payload($invalid_tree_json);
assert_same($invalid_tree_payload['success'] ?? null, false, 'branch tree rejects invalid CLI JSON');
assert_same($invalid_tree_payload['message'] ?? null, 'ForkPress returned invalid branch tree JSON.', 'branch tree explains invalid CLI JSON');

$invalid_create = run_branch_ui_action(
    ['action' => 'forkpress_branch_create', 'branch' => 'feature branch', 'from' => 'feature'],
    ['main', 'feature']
);
$invalid_create_payload = decode_branch_ui_payload($invalid_create);
assert_same($invalid_create['status'], 0, 'invalid branch create admin action exits after JSON response');
assert_same($invalid_create_payload['success'] ?? null, false, 'invalid branch create admin action returns JSON failure');
assert_same(count($invalid_create['argv']), 0, 'invalid branch create admin action does not invoke CLI');

$missing_target = run_branch_ui_action(
    ['action' => 'forkpress_branch_merge', 'source' => 'feature', 'target' => 'missing'],
    ['main', 'feature']
);
$missing_target_payload = decode_branch_ui_payload($missing_target);
assert_same($missing_target_payload['success'] ?? null, false, 'merge admin action rejects missing branches');
assert_same(count($missing_target['argv']), 0, 'merge admin action does not invoke CLI for missing branches');

$same_branch = run_branch_ui_action(
    ['action' => 'forkpress_branch_merge', 'source' => 'feature', 'target' => 'feature'],
    ['main', 'feature']
);
$same_branch_payload = decode_branch_ui_payload($same_branch);
assert_same($same_branch_payload['success'] ?? null, false, 'merge admin action rejects same-branch merges');
assert_same(count($same_branch['argv']), 0, 'merge admin action does not invoke CLI for same-branch merges');

$failed_cli = run_branch_ui_action(
    ['action' => 'forkpress_branch_merge', 'source' => 'feature', 'target' => 'main'],
    ['main', 'feature'],
    true
);
$failed_cli_payload = decode_branch_ui_payload($failed_cli);
assert_same($failed_cli_payload['success'] ?? null, false, 'merge admin action returns JSON failure when CLI fails');
assert_same($failed_cli_payload['message'] ?? null, 'synthetic branch command failure', 'merge admin action surfaces CLI failure output');
assert_same(count($failed_cli['argv']), 1, 'merge admin action records attempted CLI command on failure');

$warning_notice = run_branch_ui_action(
    ['action' => 'forkpress_branch_notice', 'forkpress_branch_warning' => 'Merge completed with conflicts.'],
    ['main', 'feature']
);
$warning_notice_payload = decode_branch_ui_payload($warning_notice);
assert_true(
    str_contains((string)($warning_notice_payload['html'] ?? ''), 'notice notice-warning'),
    'branch admin notice renders warning class'
);
assert_true(
    str_contains((string)($warning_notice_payload['html'] ?? ''), 'Merge completed with conflicts.'),
    'branch admin notice renders warning text'
);

$switcher_render = run_branch_ui_action(
    ['action' => 'forkpress_branch_switcher_render'],
    ['main', 'feature']
);
$switcher_render_payload = decode_branch_ui_payload($switcher_render);
$switcher_html = (string)($switcher_render_payload['html'] ?? '');
assert_true(str_contains($switcher_html, 'Open branch manager'), 'branch switcher links to the full branch manager page');
assert_true(str_contains($switcher_html, '/_forkpress/branches'), 'branch switcher uses the out-of-band branch manager URL');
assert_true(str_contains($switcher_html, 'function branchManagerHref'), 'branch switcher normalizes all branch navigation through manager URLs');
assert_true(str_contains($switcher_html, "sourceUrl.pathname = '/_forkpress/branches'"), 'branch switcher rewrites legacy branch URL fallbacks to the out-of-band manager path');
assert_true(str_contains($switcher_html, 'link.href = branchManagerHref(branch)'), 'branch switcher branch list never links directly to branch WordPress hosts');
assert_true(str_contains($switcher_html, "window.location.assign(branchManagerHref({ name: String(body.get('branch') || ''), managerUrl: payload.managerUrl, url: payload.url }))"), 'branch switcher navigates to the branch manager after create even with legacy action payloads');
assert_true(!str_contains($switcher_html, "link.href = branch.managerUrl || branch.url"), 'branch switcher removed direct WordPress URL fallback links');
assert_true(!str_contains($switcher_html, "window.location.assign(payload.managerUrl || payload.url)"), 'branch switcher removed direct WordPress URL fallback redirects');
assert_true(str_contains(file_get_contents($plugin), '\'href\'  => forkpress_branch_manager_url($branch)'), 'branch switcher admin-bar indicator links to the manager instead of the branch front end');
assert_true(str_contains($switcher_html, 'forkpress_branch_history'), 'branch switcher renders branch history action');
assert_true(str_contains($switcher_html, 'nonce-forkpress_branch_history'), 'branch switcher renders branch history nonce');
assert_true(str_contains($switcher_html, 'Show merge history'), 'branch switcher renders branch history button text');
assert_true(str_contains($switcher_html, 'function fetchBranchHistory'), 'branch switcher renders branch history fetch handler');
assert_true(str_contains($switcher_html, 'function renderBranchHistory'), 'branch switcher renders branch history display handler');
assert_true(str_contains($switcher_html, 'Review conflicts'), 'branch switcher can jump from history runs into conflict review');
assert_true(str_contains($switcher_html, 'Create branch'), 'branch switcher renders branch create controls');
assert_true(str_contains($switcher_html, 'forkpress-conflict-list'), 'branch switcher renders conflict audit list container');
assert_true(str_contains($switcher_html, 'forkpress-conflict-summary'), 'branch switcher renders conflict summary container');
assert_true(str_contains($switcher_html, 'forkpress-conflict-summary-button'), 'branch switcher renders conflict summary queue buttons');
assert_true(str_contains($switcher_html, 'function renderConflictAudit'), 'branch switcher renders conflict audit client handler');
assert_true(str_contains($switcher_html, 'function renderConflictSummary'), 'branch switcher renders conflict summary client handler');
assert_true(str_contains($switcher_html, 'function mergeConflictFilters'), 'branch switcher renders conflict summary filter merger');
assert_true(str_contains($switcher_html, 'function conflictSummaryFilter'), 'branch switcher renders conflict summary filter mapping');
assert_true(str_contains($switcher_html, 'function appendConflictSummaryQueue'), 'branch switcher renders conflict summary queue controls');
assert_true(str_contains($switcher_html, 'payload.conflictSummary'), 'branch switcher reads normalized conflict summary payload');
assert_true(str_contains($switcher_html, 'payload.audit && payload.audit.conflict_summary'), 'branch switcher can read merge-audit conflict summary payload');
assert_true(str_contains($switcher_html, "fetchConflictAudit(payload.run, '', mergeConflictFilters(activeFilters, conflictSummaryFilter(filterKey, key)))"), 'branch switcher lets summary buckets fetch filtered conflict queues');
assert_true(str_contains($switcher_html, 'pending crash recovery'), 'branch switcher renders pending crash recovery state');
assert_true(str_contains($switcher_html, 'payload.recoveryCommand'), 'branch switcher renders crash recovery command from audit payload');
assert_true(str_contains($switcher_html, 'forkpress_branch_restore_crash'), 'branch switcher renders crash recovery restore action');
assert_true(str_contains($switcher_html, 'nonce-forkpress_branch_restore_crash'), 'branch switcher renders crash recovery restore nonce');
assert_true(str_contains($switcher_html, 'function fetchCrashRecoveryRestore'), 'branch switcher renders crash recovery restore client handler');
assert_true(str_contains($switcher_html, 'Restore crash recovery'), 'branch switcher renders crash recovery restore button text');
assert_true(str_contains($switcher_html, 'forkpress_branch_conflicts'), 'branch switcher renders conflict audit action');
assert_true(str_contains($switcher_html, 'nonce-forkpress_branch_conflicts'), 'branch switcher renders conflict audit nonce');
assert_true(str_contains($switcher_html, "fetchConflictAudit(payload.run, payload.message || '')"), 'branch switcher requests all conflicts after warning merges');
assert_true(str_contains($switcher_html, "fetchConflictAudit(run, payload.message || '', { lifecycleState: 'needs-action' })"), 'branch switcher requests needs-action conflicts after revalidation');
assert_true(str_contains($switcher_html, 'forkpress_branch_revalidate_conflicts'), 'branch switcher renders conflict revalidation action');
assert_true(str_contains($switcher_html, 'nonce-forkpress_branch_revalidate_conflicts'), 'branch switcher renders conflict revalidation nonce');
assert_true(str_contains($switcher_html, 'function fetchConflictRevalidation'), 'branch switcher renders conflict revalidation client handler');
assert_true(str_contains($switcher_html, 'Check for changes'), 'branch switcher labels conflict rechecks without internal validation wording');
assert_true(str_contains($switcher_html, 'Checked conflicts for changes.'), 'branch switcher reports conflict rechecks without internal validation wording');
assert_true(str_contains($switcher_html, 'forkpress_branch_review_conflict'), 'branch switcher renders conflict review action');
assert_true(str_contains($switcher_html, 'nonce-forkpress_branch_review_conflict'), 'branch switcher renders conflict review nonce');
assert_true(str_contains($switcher_html, 'function fetchConflictReview'), 'branch switcher renders conflict review client handler');
assert_true(str_contains($switcher_html, 'Mark reviewed'), 'branch switcher renders conflict reviewed action');
assert_true(str_contains($switcher_html, 'Needs action'), 'branch switcher renders conflict needs-action action');
assert_true(str_contains($switcher_html, 'forkpress_branch_resolve_conflict'), 'branch switcher renders conflict resolution action');
assert_true(str_contains($switcher_html, 'nonce-forkpress_branch_resolve_conflict'), 'branch switcher renders conflict resolution nonce');
assert_true(str_contains($switcher_html, 'function fetchConflictResolution'), 'branch switcher renders conflict resolution client handler');
assert_true(str_contains($switcher_html, 'function conflictResolutionChoiceAvailable'), 'branch switcher checks conflict resolution availability');
assert_true(str_contains($switcher_html, 'function conflictApplyReviewedAvailable'), 'branch switcher checks apply-reviewed availability');
assert_true(str_contains($switcher_html, 'function conflictResolutionAfterRevalidate'), 'branch switcher detects after-revalidate resolution guards');
assert_true(str_contains($switcher_html, 'function conflictResolutionChangeAvailable'), 'branch switcher detects replace-applied resolution guards');
assert_true(str_contains($switcher_html, 'Use source'), 'branch switcher renders source resolution action');
assert_true(str_contains($switcher_html, 'Keep target'), 'branch switcher renders target resolution action');
assert_true(str_contains($switcher_html, 'Change applied resolution'), 'branch switcher renders applied-resolution change action');
assert_true(str_contains($switcher_html, 'Apply reviewed'), 'branch switcher renders apply-reviewed action');
assert_true(str_contains($switcher_html, "body.append('applyReviewed', '1')"), 'branch switcher sends apply-reviewed resolution payloads');
assert_true(str_contains($switcher_html, "body.append('afterRevalidate', '1')"), 'branch switcher sends after-revalidate resolution payloads');
assert_true(str_contains($switcher_html, "body.append('replaceApplied', '1')"), 'branch switcher sends replace-applied resolution payloads');
assert_true(str_contains($switcher_html, 'forkpress_branch_apply_reviewed_conflicts'), 'branch switcher renders reviewed-resolution apply action');
assert_true(str_contains($switcher_html, 'nonce-forkpress_branch_apply_reviewed_conflicts'), 'branch switcher renders reviewed-resolution apply nonce');
assert_true(str_contains($switcher_html, 'function fetchApplyReviewedConflicts'), 'branch switcher renders reviewed-resolution apply client handler');
assert_true(str_contains($switcher_html, 'Apply reviewed resolutions'), 'branch switcher renders reviewed-resolution apply button text');
assert_true(str_contains($switcher_html, 'function conflictPluginMeta'), 'branch switcher renders structured plugin conflict metadata');
assert_true(str_contains($switcher_html, 'record.plugin_object'), 'branch switcher renders plugin conflict object metadata');
assert_true(str_contains($switcher_html, 'record.plugin_severity'), 'branch switcher renders plugin conflict severity metadata');
assert_true(str_contains($switcher_html, 'record.plugin_validator'), 'branch switcher renders plugin conflict validator metadata');
assert_true(str_contains($switcher_html, 'plugin check: '), 'branch switcher labels plugin validator metadata as plugin checks');
assert_true(str_contains($switcher_html, 'function conflictPluginGuidance'), 'branch switcher renders plugin conflict guidance metadata');
assert_true(str_contains($switcher_html, 'record.plugin_resolution_policy'), 'branch switcher renders plugin conflict resolution policy');
assert_true(str_contains($switcher_html, 'record.plugin_suggested_action'), 'branch switcher renders plugin conflict suggested action');
assert_true(str_contains($switcher_html, 'record.plugin_manual_review_reason'), 'branch switcher renders plugin conflict manual review reason');
assert_true(str_contains($switcher_html, 'forkpress_branch_run_plugin_driver'), 'branch switcher renders plugin driver action');
assert_true(str_contains($switcher_html, 'nonce-forkpress_branch_run_plugin_driver'), 'branch switcher renders plugin driver nonce');
assert_true(str_contains($switcher_html, 'pluginDrivers'), 'branch switcher exposes approved plugin driver metadata');
assert_true(str_contains($switcher_html, 'function driverForConflict'), 'branch switcher renders plugin driver matching helper');
assert_true(str_contains($switcher_html, 'function fetchPluginDriver'), 'branch switcher renders plugin driver client handler');
assert_true(str_contains($switcher_html, 'Run plugin driver'), 'branch switcher renders plugin driver button text');

$admin_page = run_branch_ui_action(
    ['action' => 'forkpress_branch_admin_page'],
    ['main', 'feature']
);
$admin_page_payload = decode_branch_ui_payload($admin_page);
$admin_page_html = (string)($admin_page_payload['html'] ?? '');
$admin_page_menus = $admin_page_payload['menus'] ?? [];
assert_same($admin_page['status'], 0, 'branch manager admin page renders cleanly');
assert_true(str_contains($admin_page_html, '<h1>ForkPress Branches</h1>'), 'branch manager admin page has a wp-admin page title');
assert_true(str_contains($admin_page_html, 'Open ForkPress branch manager'), 'wp-admin branch page links to the out-of-band manager');
assert_true(str_contains($admin_page_html, '/_forkpress/branches'), 'wp-admin branch page points at the out-of-band manager URL');
assert_true(!str_contains($admin_page_html, 'id="forkpress-branch-create-name"'), 'wp-admin branch page no longer owns branch creation UI');
assert_same($admin_page_menus[0]['menu_slug'] ?? null, 'forkpress-branches', 'branch manager registers a wp-admin menu page');

$forbidden = run_branch_ui_action(
    ['action' => 'forkpress_branch_create', 'branch' => 'new_feature', 'from' => 'main'],
    ['main', 'feature'],
    false,
    false
);
$forbidden_payload = decode_branch_ui_payload($forbidden);
assert_same($forbidden_payload['success'] ?? null, false, 'branch create admin action rejects users without manage_options');
assert_same(count($forbidden['argv']), 0, 'forbidden branch create admin action does not invoke CLI');

$notice_cow = $tmp . '/cow';
$notice_db = $tmp . '/branches/feature/wp-content/database/.ht.sqlite';
mkdir($notice_cow, 0777, true);
$blocked_notice = run_branch_ui_action(
    ['action' => 'forkpress_branch_birth_notice'],
    ['main', 'feature'],
    false,
    true,
    true,
    [
        'FORKPRESS_COW_DIR' => $notice_cow,
        'FORKPRESS_COW_MERGE_METADATA_DB' => $notice_cow . '/merge/metadata.sqlite',
        'FORKPRESS_TEST_FQDB' => $notice_db,
    ]
);
$blocked_notice_payload = decode_branch_ui_payload($blocked_notice);
$blocked_notice_html = (string)($blocked_notice_payload['html'] ?? '');
assert_true(str_contains($blocked_notice_html, "missing required merge metadata"), 'branch birth admin notice explains missing merge metadata');
assert_true(str_contains($blocked_notice_html, 'Missing: branch database, database merge base, filesystem merge base, merge metadata.'), 'branch birth admin notice lists missing artifacts');
assert_true(str_contains($blocked_notice_html, 'forkpress branch reset feature --from main'), 'branch birth admin notice gives a reset recovery command');

$main_notice = run_branch_ui_action(
    ['action' => 'forkpress_branch_birth_notice'],
    ['main', 'feature'],
    false,
    true,
    true,
    [
        'FORKPRESS_BRANCH' => 'main',
        'FORKPRESS_COW_DIR' => $notice_cow,
        'FORKPRESS_TEST_FQDB' => $notice_db,
    ]
);
$main_notice_payload = decode_branch_ui_payload($main_notice);
assert_same($main_notice_payload['html'] ?? null, '', 'branch birth admin notice does not warn on main');

rm_tree($tmp);

echo "\n=== ForkPress WordPress branch UI tests: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);

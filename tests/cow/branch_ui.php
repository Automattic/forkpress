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
mkdir($tmp, 0777, true);
register_shutdown_function(static function () use ($tmp): void {
    rm_tree($tmp);
});

$fake_bin = $tmp . '/forkpress';
$cli_log = $tmp . '/cli-argv.jsonl';
$work_dir = $tmp . '/site';
$branch_list = $tmp . '/branches.txt';
$runner = $tmp . '/run-action.php';
mkdir($work_dir, 0777, true);

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
exit(0);
PHP);
chmod($fake_bin, 0755);

file_put_contents($runner, <<<'PHP'
<?php
define('ABSPATH', sys_get_temp_dir() . '/forkpress-test-wp/');

function add_action($tag, $callback, $priority = 10, $accepted_args = 1) { return true; }
function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) { return true; }
function current_user_can($capability) { return getenv('FORKPRESS_TEST_CAN_MANAGE') !== '0'; }
function check_admin_referer($action) { return true; }
function is_ssl() { return false; }
function wp_json_encode($payload) { return json_encode($payload, JSON_UNESCAPED_SLASHES); }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return trim((string) $value); }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$fqdb = getenv('FORKPRESS_TEST_FQDB');
if (is_string($fqdb) && $fqdb !== '' && !defined('FQDB')) {
    define('FQDB', $fqdb);
}

$async = getenv('FORKPRESS_TEST_ASYNC') !== '0';
$_SERVER = [
    'HTTP_HOST' => 'feature.wp.localhost:18080',
    'HTTP_ACCEPT' => $async ? 'application/json' : 'text/html',
    'REQUEST_URI' => '/wp-admin/',
];
if ($async) {
    $_SERVER['HTTP_X_FORKPRESS_ASYNC'] = '1';
}
$_POST = json_decode(base64_decode($argv[2]), true);
if (!is_array($_POST)) {
    fwrite(STDERR, "invalid test post payload\n");
    exit(2);
}
$_REQUEST = $_POST;

require $argv[1];

$action = $_POST['action'] ?? '';
if ($action === 'forkpress_branch_create') {
    forkpress_handle_branch_create();
}
if ($action === 'forkpress_branch_merge') {
    forkpress_handle_branch_merge();
}
if ($action === 'forkpress_branch_birth_notice') {
    ob_start();
    forkpress_branch_birth_admin_notice();
    $html = ob_get_clean();
    echo json_encode(['html' => $html], JSON_UNESCAPED_SLASHES);
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

$create = run_branch_ui_action(
    ['action' => 'forkpress_branch_create', 'branch' => 'new_feature', 'from' => 'feature'],
    ['main', 'feature']
);
$create_payload = decode_branch_ui_payload($create);
assert_same($create['status'], 0, 'branch create admin action exits cleanly');
assert_same($create_payload['success'] ?? null, true, 'branch create admin action returns JSON success');
assert_same($create_payload['message'] ?? null, 'Created branch new_feature.', 'branch create admin action reports the created branch');
assert_same($create_payload['url'] ?? null, 'http://new_feature.wp.localhost:18080/wp-admin/', 'branch create admin action redirects to the new branch admin');
assert_same(count($create['argv']), 1, 'branch create admin action invokes ForkPress CLI once');
assert_same(
    array_slice($create['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'create', 'new_feature', '--from', 'feature'],
    'branch create admin action uses safe branch birth CLI path'
);

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
assert_same($merge_payload['message'] ?? null, 'Merged feature into main.', 'branch merge admin action reports the merge');
assert_same($merge_payload['url'] ?? null, 'http://wp.localhost:18080/wp-admin/', 'branch merge admin action redirects to target branch admin');
assert_same(count($merge['argv']), 1, 'branch merge admin action invokes ForkPress CLI once');
assert_same(
    array_slice($merge['argv'][0] ?? [], 1),
    ['branch', '--work-dir', $work_dir, 'merge', 'feature', '--into', 'main'],
    'branch merge admin action uses audited branch merge CLI path'
);

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

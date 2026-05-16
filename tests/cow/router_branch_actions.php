<?php

$pass = 0; $fail = 0;
function assert_true($cond, $msg) {
    global $pass, $fail;
    if ($cond) { echo "  PASS: $msg\n"; $pass++; }
    else       { echo "  FAIL: $msg\n"; $fail++; }
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

function router_branch_action_request(
    string $child,
    string $branches,
    string $cow,
    string $router,
    string $branch_list,
    string $fake_bin,
    string $work_dir,
    string $uri,
    array $post,
    bool $async = true
): array {
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $payload = base64_encode(json_encode($post, JSON_UNESCAPED_SLASHES));
    $process = proc_open(
        [PHP_BINARY, $child, $branches, $cow, $router, $branch_list, $fake_bin, $work_dir, $uri, $payload, $async ? '1' : '0'],
        $descriptor,
        $pipes
    );
    if (!is_resource($process)) {
        return ['status' => 0, 'body' => '', 'json' => null, 'stderr' => 'proc_open failed', 'exit' => -1];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $decoded = json_decode($stdout, true);
    return [
        'status' => is_array($decoded) ? ($decoded['status'] ?? 0) : 0,
        'body' => is_array($decoded) ? ($decoded['body'] ?? '') : $stdout,
        'json' => is_array($decoded) && isset($decoded['body']) ? json_decode((string)$decoded['body'], true) : null,
        'stderr' => $stderr,
        'exit' => $exit,
    ];
}

echo "=== COW router async branch actions ===\n";

$tmp = sys_get_temp_dir() . '/forkpress-cow-router-branch-actions-' . getmypid() . '-' . bin2hex(random_bytes(4));
$branches = $tmp . '/branches';
$cow = $tmp . '/cow';
$main = $branches . '/main';
$feature = $branches . '/feature';
$branch_list = $cow . '/branches.txt';
$fake_bin = $tmp . '/forkpress';
$cli_log = $tmp . '/cli-argv.jsonl';
$work_dir = $tmp . '/site';
$child = $tmp . '/request.php';
$router = realpath(__DIR__ . '/../../runtime/cow/router.php');
assert_true($router !== false, 'router fixture exists');
register_shutdown_function(static function() use ($tmp): void {
    rm_tree($tmp);
});

mkdir($main . '/wp-admin', 0777, true);
mkdir($feature, 0777, true);
mkdir($cow, 0777, true);
mkdir($work_dir, 0777, true);
file_put_contents($branch_list, "main\nfeature\n");
file_put_contents($main . '/index.php', "<?php echo \"WORDPRESS INDEX\";\n");
file_put_contents($main . '/wp-admin/admin-post.php', "<?php echo \"WORDPRESS ADMIN POST\";\n");
file_put_contents($fake_bin, <<<'PHP'
#!/usr/bin/env php
<?php
$log = getenv('FORKPRESS_TEST_CLI_LOG');
if (is_string($log) && $log !== '') {
    file_put_contents($log, json_encode($argv, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
}
$branch_list = getenv('FORKPRESS_BRANCH_LIST');
if (($argv[4] ?? '') === 'create' && is_string($branch_list) && $branch_list !== '') {
    file_put_contents($branch_list, ($argv[5] ?? '') . "\n", FILE_APPEND);
}
if (getenv('FORKPRESS_TEST_CLI_FAIL') === '1') {
    fwrite(STDERR, "synthetic router branch action failure\n");
    exit(23);
}
exit(0);
PHP);
chmod($fake_bin, 0755);

file_put_contents($child, <<<'PHP'
<?php
$branches = $argv[1];
$cow = $argv[2];
$router = $argv[3];
$branch_list = $argv[4];
$fake_bin = $argv[5];
$work_dir = $argv[6];
$uri = $argv[7];
$post = json_decode(base64_decode($argv[8]), true);
$async = $argv[9] === '1';
putenv('FORKPRESS_BRANCHES_DIR=' . $branches);
putenv('FORKPRESS_COW_DIR=' . $cow);
putenv('FORKPRESS_ROOT_HOST=wp.localhost');
putenv('FORKPRESS_BRANCH_LIST=' . $branch_list);
putenv('FORKPRESS_BIN=' . $fake_bin);
putenv('FORKPRESS_WORK_DIR=' . $work_dir);
putenv('FORKPRESS_TEST_CLI_LOG=' . dirname($fake_bin) . '/cli-argv.jsonl');
$_POST = is_array($post) ? $post : [];
$_REQUEST = $_POST;
$_SERVER = [
    'HTTP_HOST' => 'wp.localhost:18080',
    'HTTP_ACCEPT' => $async ? 'application/json' : 'text/html',
    'REQUEST_URI' => $uri,
    'REQUEST_METHOD' => 'POST',
    'SERVER_NAME' => 'wp.localhost',
    'SERVER_PORT' => '18080',
    'SERVER_PROTOCOL' => 'HTTP/1.1',
];
if ($async) {
    $_SERVER['HTTP_X_FORKPRESS_ASYNC'] = '1';
}
ob_start();
require $router;
$body = ob_get_clean();
echo json_encode(['status' => http_response_code() ?: 200, 'body' => $body], JSON_UNESCAPED_SLASHES) . "\n";
PHP);

$create = router_branch_action_request(
    $child,
    $branches,
    $cow,
    $router,
    $branch_list,
    $fake_bin,
    $work_dir,
    '/wp-admin/admin-post.php',
    ['action' => 'forkpress_branch_create', 'branch' => 'router_created', 'from' => 'main']
);
assert_same($create['exit'], 0, 'async router branch create exits cleanly');
assert_same($create['status'], 200, 'async router branch create returns 200');
assert_same($create['json']['success'] ?? null, true, 'async router branch create returns JSON success');
assert_same($create['json']['message'] ?? null, 'Created branch router_created.', 'async router branch create reports created branch');
assert_true(!str_contains($create['body'], 'WORDPRESS'), 'async router branch create does not reach WordPress admin-post');
$created_branch_names = array_map(static fn($row) => $row['name'] ?? '', $create['json']['branches'] ?? []);
assert_true(in_array('router_created', $created_branch_names, true), 'async router branch create refreshes branch list after CLI command');

$merge = router_branch_action_request(
    $child,
    $branches,
    $cow,
    $router,
    $branch_list,
    $fake_bin,
    $work_dir,
    '/wp-admin/admin-post.php',
    ['action' => 'forkpress_branch_merge', 'source' => 'router_created', 'target' => 'main']
);
assert_same($merge['exit'], 0, 'async router branch merge exits cleanly');
assert_same($merge['status'], 200, 'async router branch merge returns 200');
assert_same($merge['json']['success'] ?? null, true, 'async router branch merge returns JSON success');
assert_same($merge['json']['message'] ?? null, 'Merged router_created into main.', 'async router branch merge reports merged branch');
assert_true(!str_contains($merge['body'], 'WORDPRESS'), 'async router branch merge does not reach WordPress admin-post');

$invalid = router_branch_action_request(
    $child,
    $branches,
    $cow,
    $router,
    $branch_list,
    $fake_bin,
    $work_dir,
    '/wp-admin/admin-post.php',
    ['action' => 'forkpress_branch_create', 'branch' => 'bad branch', 'from' => 'main']
);
assert_same($invalid['status'], 400, 'async router branch create rejects invalid names before CLI');
assert_same($invalid['json']['success'] ?? null, false, 'async router branch create invalid name returns JSON failure');

$fallback = router_branch_action_request(
    $child,
    $branches,
    $cow,
    $router,
    $branch_list,
    $fake_bin,
    $work_dir,
    '/wp-admin/admin-post.php',
    ['action' => 'forkpress_branch_create', 'branch' => 'html_fallback', 'from' => 'main'],
    false
);
assert_same($fallback['exit'], 0, 'non-async admin branch action exits cleanly');
assert_same($fallback['body'], 'WORDPRESS ADMIN POST', 'non-async admin branch action still falls through to WordPress');

$argv_log = [];
foreach (file($cli_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $decoded = json_decode($line, true);
    if (is_array($decoded)) {
        $argv_log[] = array_slice($decoded, 1);
    }
}
assert_same($argv_log[0] ?? null, ['branch', '--work-dir', $work_dir, 'create', 'router_created', '--from', 'main'], 'router branch create invokes safe CLI command');
assert_same($argv_log[1] ?? null, ['branch', '--work-dir', $work_dir, 'merge', 'router_created', '--into', 'main'], 'router branch merge invokes audited CLI command');
assert_same(count($argv_log), 2, 'invalid and non-async branch action requests do not invoke router CLI path');

rm_tree($tmp);

echo "\n=== COW router branch action tests: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);

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
function router_request(string $child, string $branches, string $cow, string $router, string $uri, string $debug_log = ''): array {
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([PHP_BINARY, $child, $branches, $cow, $router, $uri, $debug_log], $descriptor, $pipes);
    if (!is_resource($process)) {
        return ['status' => 0, 'body' => '', 'stderr' => 'proc_open failed'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $decoded = json_decode($stdout, true);
    if (!is_array($decoded)) {
        return ['status' => 0, 'body' => $stdout, 'stderr' => $stderr, 'exit' => $exit];
    }
    $decoded['stderr'] = $stderr;
    $decoded['exit'] = $exit;
    return $decoded;
}

echo "=== COW router path containment ===\n";

$tmp = sys_get_temp_dir() . '/forkpress-cow-router-paths-' . getmypid() . '-' . bin2hex(random_bytes(4));
$site = $tmp . '/site';
$branches = $site;
$cow = $site . '/.forkpress/cow';
$main = $site . '/main';
$secret = $site . '/.forkpress/site.toml';
$debug_log = $site . '/.forkpress/logs/wp-debug.log';
$child = $tmp . '/request.php';
$router = realpath(__DIR__ . '/../../runtime/cow/router.php');
assert_true($router !== false, 'router fixture exists');
register_shutdown_function(static function() use ($tmp): void {
    rm_tree($tmp);
});

mkdir($main, 0777, true);
mkdir(dirname($secret), 0777, true);
mkdir(dirname($debug_log), 0777, true);
mkdir($cow, 0777, true);
file_put_contents($main . '/index.php', "<?php echo \"INDEX\";\n");
file_put_contents($main . '/fatal.php', "<?php forkpress_missing_function_for_router_test();\n");
file_put_contents($main . '/safe.txt', "safe\n");
mkdir($main . '/wp-admin', 0777, true);
file_put_contents($main . '/wp-admin/plugin-install.php', "<?php echo \"PLUGIN INSTALL ADMIN\";\n");
file_put_contents($main . '/wp-admin/update.php', "<?php echo \"PLUGIN UPDATE ADMIN\";\n");
file_put_contents($secret, "strategy = \"cow\"\nsecret = \"do not serve\"\n");
file_put_contents($child, <<<'PHP'
<?php
$branches = $argv[1];
$cow = $argv[2];
$router = $argv[3];
$uri = $argv[4];
$debug_log = $argv[5] ?? '';
putenv('FORKPRESS_BRANCHES_DIR=' . $branches);
putenv('FORKPRESS_COW_DIR=' . $cow);
putenv('FORKPRESS_ROOT_HOST=wp.localhost');
if ($debug_log !== '') {
    putenv('FORKPRESS_DEBUG_LOG=' . $debug_log);
}
$_SERVER = [
    'HTTP_HOST'       => 'wp.localhost',
    'REQUEST_URI'     => $uri,
    'REQUEST_METHOD'  => 'GET',
    'SERVER_NAME'     => 'wp.localhost',
    'SERVER_PORT'     => '80',
    'SERVER_PROTOCOL' => 'HTTP/1.1',
];
ob_start();
require $router;
$body = ob_get_clean();
$status = http_response_code();
echo json_encode(['status' => $status ?: 200, 'body' => $body]) . "\n";
PHP);

$response = router_request($child, $branches, $cow, $router, '/safe.txt');
assert_same($response['exit'], 0, 'safe static request exits cleanly');
assert_same($response['status'], 200, 'safe static request returns 200');
assert_same($response['body'], "safe\n", 'safe static request serves branch file');
assert_same($response['stderr'], '', 'safe static request produces no stderr');

$response = router_request($child, $branches, $cow, $router, '/fatal.php', $debug_log);
assert_true(($response['exit'] ?? 0) !== 0, 'fatal PHP request exits with failure');
$logged = file_exists($debug_log) ? (string)file_get_contents($debug_log) : '';
assert_true(str_contains($logged, "ForkPress branch 'main' PHP fatal while serving /fatal.php"), 'fatal PHP request is logged with branch and URI');
assert_true(str_contains($logged, 'forkpress_missing_function_for_router_test'), 'fatal PHP request log includes the PHP fatal message');

$response = router_request($child, $branches, $cow, $router, '/wp-admin/plugin-install.php');
assert_same($response['exit'], 0, 'wp-admin plugin install request exits cleanly');
assert_same($response['status'], 200, 'wp-admin plugin install request returns 200');
assert_same($response['body'], 'PLUGIN INSTALL ADMIN', 'wp-admin plugin install request reaches the plugin install admin screen');

$response = router_request($child, $branches, $cow, $router, '/plugin-install.php?tab=upload');
assert_same($response['exit'], 0, 'legacy plugin install request exits cleanly');
assert_same($response['status'], 302, 'legacy plugin install request redirects to wp-admin');
assert_true(!str_contains($response['body'], 'INDEX'), 'legacy plugin install request does not fall through to the front controller');

$response = router_request($child, $branches, $cow, $router, '/update.php?action=install-plugin&plugin=hello-dolly');
assert_same($response['exit'], 0, 'legacy plugin update request exits cleanly');
assert_same($response['status'], 302, 'legacy plugin update request redirects to wp-admin');
assert_true(!str_contains($response['body'], 'INDEX'), 'legacy plugin update request does not fall through to the front controller');

foreach ([
    '/../.forkpress/site.toml',
    '/%2e%2e/.forkpress/site.toml',
    '/.%2e/.forkpress/site.toml',
    '/%252e%252e/.forkpress/site.toml',
    '/%2e%2e%5c.forkpress/site.toml',
] as $uri) {
    $response = router_request($child, $branches, $cow, $router, $uri);
    assert_same($response['exit'], 0, "traversal request exits cleanly for $uri");
    assert_same($response['status'], 404, "traversal request returns 404 for $uri");
    assert_true(!str_contains($response['body'], 'strategy = "cow"'), "traversal request does not disclose site manifest for $uri");
    assert_same($response['stderr'], '', "traversal request produces no stderr for $uri");
}

if (@symlink($secret, $main . '/link-secret')) {
    $response = router_request($child, $branches, $cow, $router, '/link-secret');
    assert_same($response['exit'], 0, 'external symlink request exits cleanly');
    assert_same($response['status'], 404, 'external symlink request returns 404');
    assert_true(!str_contains($response['body'], 'strategy = "cow"'), 'external symlink request does not disclose site manifest');

    @symlink($main . '/safe.txt', $main . '/link-safe');
    $response = router_request($child, $branches, $cow, $router, '/link-safe');
    assert_same($response['status'], 200, 'internal symlink request returns 200');
    assert_same($response['body'], "safe\n", 'internal symlink request serves branch-local target');

    $external_dir = $tmp . '/external-dir';
    mkdir($external_dir, 0777, true);
    file_put_contents($external_dir . '/hidden.txt', "hidden\n");
    @symlink($external_dir, $main . '/link-dir');
    foreach (['/link-dir', '/link-dir/'] as $uri) {
        $response = router_request($child, $branches, $cow, $router, $uri);
        assert_same($response['status'], 404, "external symlinked directory request returns 404 for $uri");
        assert_true(!str_contains($response['body'], 'INDEX'), "external symlinked directory request does not fall through to front controller for $uri");
        assert_true(!str_contains($response['body'], 'hidden'), "external symlinked directory request does not disclose target files for $uri");
    }
} else {
    echo "  SKIP: symlink containment tests\n";
}

rm_tree($tmp);

echo "\n=== COW router path tests: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);

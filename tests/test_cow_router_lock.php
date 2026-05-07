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

echo "=== COW router operation lock ===\n";

$tmp = sys_get_temp_dir() . '/forkpress-cow-router-lock-' . getmypid() . '-' . bin2hex(random_bytes(4));
$branches = $tmp . '/branches';
$cow = $tmp . '/cow';
$main = $branches . '/main';
$entered = $tmp . '/router-entered.txt';
$started = $tmp . '/request-started.txt';
$child = $tmp . '/request.php';
$router = realpath(__DIR__ . '/../runtime/cow/router.php');
assert_true($router !== false, 'router fixture exists');
register_shutdown_function(static function() use ($tmp): void {
    rm_tree($tmp);
});

mkdir($main, 0777, true);
mkdir($cow, 0777, true);

file_put_contents($main . '/index.php', "<?php\nfile_put_contents(" . var_export($started, true) . ", sprintf(\"%.6f\\n\", microtime(true)));\necho \"OK\";\n");
file_put_contents($main . '/safe.txt', "SAFE\n");
file_put_contents($child, <<<'PHP'
<?php
$branches = $argv[1];
$cow = $argv[2];
$router = $argv[3];
$uri = $argv[4];
$entered = $argv[5];
putenv('FORKPRESS_BRANCHES_DIR=' . $branches);
putenv('FORKPRESS_COW_DIR=' . $cow);
putenv('FORKPRESS_ROOT_HOST=wp.localhost');
$_SERVER = [
    'HTTP_HOST'       => 'wp.localhost',
    'REQUEST_URI'     => $uri,
    'REQUEST_METHOD'  => 'GET',
    'SERVER_NAME'     => 'wp.localhost',
    'SERVER_PORT'     => '80',
    'SERVER_PROTOCOL' => 'HTTP/1.1',
];
file_put_contents($entered, sprintf("%.6f\n", microtime(true)));
require $router;
PHP);

$lock_path = $cow . '/operations.lock';
$lock = fopen($lock_path, 'c');
assert_true(is_resource($lock), 'test opened operation lock');
if (is_resource($lock)) {
    assert_true(flock($lock, LOCK_EX), 'test holds exclusive operation lock');

    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([PHP_BINARY, $child, $branches, $cow, $router, '/', $entered], $descriptor, $pipes);
    assert_true(is_resource($process), 'spawned router request process');
    if (is_resource($process)) {
        fclose($pipes[0]);
        $deadline = microtime(true) + 2.0;
        while (!file_exists($entered) && microtime(true) < $deadline) {
            usleep(10000);
        }
        assert_true(file_exists($entered), 'router request reached pre-lock gate');
        usleep(150000);
        assert_true(!file_exists($started), 'router request waits behind exclusive COW operation lock');

        $released_at = microtime(true);
        flock($lock, LOCK_UN);
        fclose($lock);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        assert_same($status, 0, 'router request exits cleanly after lock release');
        assert_same($stdout, 'OK', 'router served branch after lock release');
        assert_same($stderr, '', 'router request produced no stderr');
        assert_true(file_exists($started), 'branch PHP executed after lock release');
        $started_at = (float)trim((string)file_get_contents($started));
        assert_true($started_at >= $released_at - 0.05, 'branch PHP did not run before exclusive lock release');
    }
}

@unlink($entered);
@unlink($started);
$lock = fopen($lock_path, 'c');
assert_true(is_resource($lock), 'test reopened operation lock');
if (is_resource($lock)) {
    assert_true(flock($lock, LOCK_EX), 'test holds exclusive operation lock for static request');

    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([PHP_BINARY, $child, $branches, $cow, $router, '/safe.txt', $entered], $descriptor, $pipes);
    assert_true(is_resource($process), 'spawned static router request process');
    if (is_resource($process)) {
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        $deadline = microtime(true) + 2.0;
        while (!file_exists($entered) && microtime(true) < $deadline) {
            usleep(10000);
        }
        assert_true(file_exists($entered), 'static request reached pre-lock gate');
        usleep(150000);
        assert_same(stream_get_contents($pipes[1]), '', 'static response waits behind exclusive COW operation lock');

        flock($lock, LOCK_UN);
        fclose($lock);
        stream_set_blocking($pipes[1], true);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        assert_same($status, 0, 'static router request exits cleanly after lock release');
        assert_same($stdout, "SAFE\n", 'router served static branch file after lock release');
        assert_same($stderr, '', 'static router request produced no stderr');
        assert_true(!file_exists($started), 'static request did not execute branch PHP');
    }
}

rm_tree($tmp);

echo "\n=== COW router lock tests: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);

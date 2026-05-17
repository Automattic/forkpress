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

function write_file(string $path, string $contents): void {
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);
}

function open_db(string $path): SQLite3 {
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    $db = new SQLite3($path);
    $db->busyTimeout(5000);
    return $db;
}

function create_guard_db(string $path): void {
    $db = open_db($path);
    $db->exec('CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY AUTOINCREMENT, post_title TEXT NOT NULL)');
    $db->exec('CREATE TABLE plugin_keyless (label TEXT NOT NULL)');
    $db->exec("INSERT INTO wp_posts (ID, post_title) VALUES (1, 'base post')");
    $db->exec("INSERT INTO plugin_keyless (label) VALUES ('base keyless row')");
    $db->close();
}

function router_guard_request(
    string $child,
    string $branches,
    string $cow,
    string $router,
    string $host,
    string $method,
    string $uri
): array {
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([PHP_BINARY, $child, $branches, $cow, $router, $host, $method, $uri], $descriptor, $pipes);
    if (!is_resource($process)) {
        return ['status' => 0, 'body' => '', 'stderr' => 'proc_open failed', 'exit' => -1];
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

define('FORKPRESS_COW_MERGE_TESTS', true);
require_once __DIR__ . '/../../scripts/cow/merge.php';

echo "=== COW router branch birth write guard ===\n";

$tmp = sys_get_temp_dir() . '/forkpress-cow-router-branch-birth-guard-' . getmypid() . '-' . bin2hex(random_bytes(4));
$branches = $tmp . '/branches';
$cow = $tmp . '/cow';
$main = $branches . '/main';
$feature = $branches . '/feature';
$child = $tmp . '/request.php';
$router = realpath(__DIR__ . '/../../runtime/cow/router.php');
$feature_db = $feature . '/wp-content/database/.ht.sqlite';
$metadata = $cow . '/merge/metadata.sqlite';
$db_base = $cow . '/merge/bases/feature.sqlite';
$file_base = $cow . '/merge/file-bases/feature.json';

assert_true($router !== false, 'router fixture exists');
register_shutdown_function(static function() use ($tmp): void {
    rm_tree($tmp);
});

mkdir($main, 0777, true);
mkdir($feature, 0777, true);
mkdir($cow, 0777, true);
write_file($main . '/index.php', "<?php echo \"MAIN INDEX\";\n");
write_file($feature . '/index.php', "<?php echo \"FEATURE INDEX\";\n");
create_guard_db($feature_db);

file_put_contents($child, <<<'PHP'
<?php
$branches = $argv[1];
$cow = $argv[2];
$router = $argv[3];
$host = $argv[4];
$method = $argv[5];
$uri = $argv[6];
putenv('FORKPRESS_BRANCHES_DIR=' . $branches);
putenv('FORKPRESS_COW_DIR=' . $cow);
putenv('FORKPRESS_ROOT_HOST=wp.localhost');
$_POST = [];
$_REQUEST = [];
$_SERVER = [
    'HTTP_HOST'       => $host,
    'REQUEST_URI'     => $uri,
    'REQUEST_METHOD'  => $method,
    'SERVER_NAME'     => preg_replace('/:\d+$/', '', $host),
    'SERVER_PORT'     => '18080',
    'SERVER_PROTOCOL' => 'HTTP/1.1',
];
ob_start();
require $router;
$body = ob_get_clean();
$status = http_response_code();
echo json_encode(['status' => $status ?: 200, 'body' => $body], JSON_UNESCAPED_SLASHES) . "\n";
PHP);

$read = router_guard_request($child, $branches, $cow, $router, 'feature.wp.localhost:18080', 'GET', '/');
assert_same($read['exit'], 0, 'unvalidated feature GET exits cleanly');
assert_same($read['status'], 200, 'unvalidated feature GET remains readable');
assert_same($read['body'], 'FEATURE INDEX', 'unvalidated feature GET reaches WordPress');

$blocked = router_guard_request($child, $branches, $cow, $router, 'feature.wp.localhost:18080', 'POST', '/');
assert_same($blocked['exit'], 0, 'unvalidated feature POST exits cleanly');
assert_same($blocked['status'], 409, 'unvalidated feature POST is blocked before WordPress');
assert_true(str_contains($blocked['body'], 'missing required merge metadata'), 'unvalidated feature POST explains missing merge metadata');
assert_true(str_contains($blocked['body'], 'forkpress branch reset feature --from main'), 'unvalidated feature POST gives a reset recovery command');
assert_true(str_contains($blocked['body'], 'database merge base'), 'unvalidated feature POST names missing database merge base');
assert_true(str_contains($blocked['body'], 'filesystem merge base'), 'unvalidated feature POST names missing filesystem merge base');
assert_true(!str_contains($blocked['body'], 'FEATURE INDEX'), 'unvalidated feature POST does not reach WordPress');

if (!is_dir(dirname($db_base))) {
    mkdir(dirname($db_base), 0777, true);
}
copy($feature_db, $db_base);
cow_merge_capture_file_base($feature, $file_base);
cow_merge_allocate_autoincrement_bands($feature_db, $metadata, 'feature');
cow_merge_capture_row_identities($feature_db, $metadata, 'feature');

$allowed = router_guard_request($child, $branches, $cow, $router, 'feature.wp.localhost:18080', 'POST', '/');
assert_same($allowed['exit'], 0, 'validated feature POST exits cleanly');
assert_same($allowed['status'], 200, 'validated feature POST reaches WordPress');
assert_same($allowed['body'], 'FEATURE INDEX', 'validated feature POST serves feature branch');

$meta_db = open_db($metadata);
$meta_db->exec("DELETE FROM merge_autoincrement_bands WHERE branch_name = 'feature'");
$meta_db->close();

$metadata_blocked = router_guard_request($child, $branches, $cow, $router, 'feature.wp.localhost:18080', 'PATCH', '/');
assert_same($metadata_blocked['exit'], 0, 'feature PATCH with incomplete metadata exits cleanly');
assert_same($metadata_blocked['status'], 409, 'feature PATCH with incomplete metadata is blocked');
assert_true(str_contains($metadata_blocked['body'], 'forkpress branch reset feature --from main'), 'feature PATCH with incomplete metadata gives a reset recovery command');
assert_true(str_contains($metadata_blocked['body'], 'AUTOINCREMENT ID band'), 'feature PATCH reports incomplete branch birth metadata');
assert_true(!str_contains($metadata_blocked['body'], 'FEATURE INDEX'), 'feature PATCH with incomplete metadata does not reach WordPress');

$main_write = router_guard_request($child, $branches, $cow, $router, 'wp.localhost:18080', 'POST', '/');
assert_same($main_write['exit'], 0, 'main POST exits cleanly');
assert_same($main_write['status'], 200, 'main POST is not guarded by branch birth metadata');
assert_same($main_write['body'], 'MAIN INDEX', 'main POST reaches WordPress');

rm_tree($tmp);

echo "\n=== COW router branch birth write guard tests: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);

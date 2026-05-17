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
    bool $async = true,
    array $extra_env = []
): array {
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $payload = base64_encode(json_encode($post, JSON_UNESCAPED_SLASHES));
    $previous_env = [];
    foreach ($extra_env as $key => $value) {
        $previous_env[$key] = getenv($key);
        putenv($key . '=' . $value);
    }
    $process = proc_open(
        [PHP_BINARY, $child, $branches, $cow, $router, $branch_list, $fake_bin, $work_dir, $uri, $payload, $async ? '1' : '0'],
        $descriptor,
        $pipes
    );
    foreach ($extra_env as $key => $_) {
        if ($previous_env[$key] === false) {
            putenv($key);
        } else {
            putenv($key . '=' . $previous_env[$key]);
        }
    }
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
mkdir($main . '/wp-content/plugins/sample-plugin', 0777, true);
mkdir($feature, 0777, true);
mkdir($cow, 0777, true);
mkdir($work_dir, 0777, true);
file_put_contents($branch_list, "main\nfeature\n");
file_put_contents($main . '/index.php', "<?php echo \"WORDPRESS INDEX\";\n");
file_put_contents($main . '/wp-admin/admin.php', "<?php echo \"WORDPRESS ADMIN PAGE\";\n");
file_put_contents($main . '/wp-admin/admin-post.php', "<?php echo \"WORDPRESS ADMIN POST\";\n");
$driver_path = $main . '/wp-content/plugins/sample-plugin/forkpress-merge-driver.php';
file_put_contents($driver_path, "<?php echo \"sample driver\";\n");
$driver_key = hash('sha256', 'sample-plugin' . "\0" . realpath($driver_path));
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
$outputs = json_decode((string)getenv('FORKPRESS_TEST_CLI_OUTPUTS'), true);
if (is_array($outputs)) {
    $record_type = null;
    $records_index = array_search('--records', $argv, true);
    if (is_int($records_index) && isset($argv[$records_index + 1])) {
        $record_type = (string)$argv[$records_index + 1];
    }
    $output = $record_type !== null && array_key_exists($record_type, $outputs) ? $outputs[$record_type] : null;
    if ($output === null) {
        $is_list = $outputs === [] || array_keys($outputs) === range(0, count($outputs) - 1);
        if ($is_list) {
            $index = 0;
            if (is_string($log) && $log !== '' && is_readable($log)) {
                $lines = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $index = is_array($lines) ? max(0, count($lines) - 1) : 0;
            }
            $output = $outputs[$index] ?? '';
        }
    }
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

$conflicted_merge_output = "forkpress: merged router_created into main\\n  run:       42\\n  status:    completed_with_conflicts\\n  conflicts: 3\\n";
$conflicted_merge = router_branch_action_request(
    $child,
    $branches,
    $cow,
    $router,
    $branch_list,
    $fake_bin,
    $work_dir,
    '/wp-admin/admin-post.php',
    ['action' => 'forkpress_branch_merge', 'source' => 'router_created', 'target' => 'main'],
    true,
    ['FORKPRESS_TEST_CLI_OUTPUT' => $conflicted_merge_output]
);
assert_same($conflicted_merge['exit'], 0, 'conflicted async router branch merge exits cleanly');
assert_same($conflicted_merge['status'], 200, 'conflicted async router branch merge returns 200');
assert_same($conflicted_merge['json']['success'] ?? null, true, 'conflicted async router branch merge returns JSON success');
assert_same($conflicted_merge['json']['type'] ?? null, 'warning', 'conflicted async router branch merge returns warning type');
assert_same($conflicted_merge['json']['mergeStatus'] ?? null, 'completed_with_conflicts', 'conflicted async router branch merge exposes merge status');
assert_same($conflicted_merge['json']['conflicts'] ?? null, 3, 'conflicted async router branch merge exposes conflict count');
assert_same($conflicted_merge['json']['run'] ?? null, 42, 'conflicted async router branch merge exposes merge run id');
assert_same(
    $conflicted_merge['json']['auditCommand'] ?? null,
    'forkpress branch merge-audit --records conflicts --run 42',
    'conflicted async router branch merge exposes audit command'
);
assert_true(!str_contains($conflicted_merge['body'], 'WORDPRESS'), 'conflicted async router branch merge does not reach WordPress admin-post');

$audit_output = json_encode([
    'runs' => [
        ['id' => 42, 'source_branch' => 'router_created', 'target_branch' => 'main', 'conflict_count' => 3],
    ],
    'conflicts' => [
        ['id' => 11, 'run_id' => 42, 'conflict_key' => 'wp_posts:page:router', 'table_name' => 'wp_posts'],
        ['id' => 12, 'run_id' => 42, 'conflict_key' => 'files:router.txt', 'table_name' => '__files__'],
    ],
], JSON_UNESCAPED_SLASHES);
$conflict_audit = router_branch_action_request(
    $child,
    $branches,
    $cow,
    $router,
    $branch_list,
    $fake_bin,
    $work_dir,
    '/wp-admin/admin-post.php',
    ['action' => 'forkpress_branch_conflicts', 'run' => '42'],
    true,
    ['FORKPRESS_TEST_CLI_OUTPUTS' => json_encode(['crash-recovery' => '{"crash_recovery":[]}', 'conflicts' => $audit_output], JSON_UNESCAPED_SLASHES)]
);
assert_same($conflict_audit['exit'], 0, 'async router branch conflict audit exits cleanly');
assert_same($conflict_audit['status'], 200, 'async router branch conflict audit returns 200');
assert_same($conflict_audit['json']['success'] ?? null, true, 'async router branch conflict audit returns JSON success');
assert_same($conflict_audit['json']['recordCount'] ?? null, 2, 'async router branch conflict audit returns loaded records');
assert_same($conflict_audit['json']['totalConflicts'] ?? null, 3, 'async router branch conflict audit returns total conflicts');
assert_same($conflict_audit['json']['records'][0]['conflict_key'] ?? null, 'wp_posts:page:router', 'async router branch conflict audit returns first-class conflict records');
assert_true(!str_contains($conflict_audit['body'], 'WORDPRESS'), 'async router branch conflict audit does not reach WordPress admin-post');

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
$pending_crash_audit = router_branch_action_request(
    $child,
    $branches,
    $cow,
    $router,
    $branch_list,
    $fake_bin,
    $work_dir,
    '/wp-admin/admin-post.php',
    ['action' => 'forkpress_branch_conflicts', 'run' => '42'],
    true,
    ['FORKPRESS_TEST_CLI_OUTPUTS' => json_encode(['crash-recovery' => $pending_crash_output, 'conflicts' => $audit_output], JSON_UNESCAPED_SLASHES)]
);
assert_same($pending_crash_audit['exit'], 0, 'pending crash recovery router branch conflict audit exits cleanly');
assert_same($pending_crash_audit['status'], 200, 'pending crash recovery router branch conflict audit returns 200');
assert_same($pending_crash_audit['json']['success'] ?? null, true, 'pending crash recovery router branch conflict audit returns JSON success');
assert_same($pending_crash_audit['json']['type'] ?? null, 'warning', 'pending crash recovery router branch conflict audit returns warning type');
assert_same($pending_crash_audit['json']['crashRecoveryCount'] ?? null, 1, 'pending crash recovery router branch conflict audit exposes recovery count');
assert_same($pending_crash_audit['json']['crashRecovery'][0]['checkpoint'] ?? null, 'plugin-driver-resolution', 'pending crash recovery router branch conflict audit exposes checkpoint');
assert_same(
    $pending_crash_audit['json']['recoveryCommand'] ?? null,
    'forkpress branch recover-crash --run 42 --restore-target-db --restore-files',
    'pending crash recovery router branch conflict audit exposes restore command'
);
assert_true(!str_contains($pending_crash_audit['body'], 'WORDPRESS'), 'pending crash recovery router branch conflict audit does not reach WordPress admin-post');

$restore_crash_output = json_encode(['restored' => 1, 'pending' => 0], JSON_UNESCAPED_SLASHES);
$restore_crash = router_branch_action_request(
    $child,
    $branches,
    $cow,
    $router,
    $branch_list,
    $fake_bin,
    $work_dir,
    '/wp-admin/admin-post.php',
    ['action' => 'forkpress_branch_restore_crash', 'run' => '42'],
    true,
    ['FORKPRESS_TEST_CLI_OUTPUT' => $restore_crash_output]
);
assert_same($restore_crash['exit'], 0, 'router branch crash restore exits cleanly');
assert_same($restore_crash['status'], 200, 'router branch crash restore returns 200');
assert_same($restore_crash['json']['success'] ?? null, true, 'router branch crash restore returns JSON success');
assert_same($restore_crash['json']['restored'] ?? null, 1, 'router branch crash restore exposes restored count');
assert_same($restore_crash['json']['pending'] ?? null, 0, 'router branch crash restore exposes pending count');
assert_same(
    $restore_crash['json']['recoveryCommand'] ?? null,
    'forkpress branch recover-crash --run 42 --restore-target-db --restore-files',
    'router branch crash restore exposes exact restore command'
);
assert_true(!str_contains($restore_crash['body'], 'WORDPRESS'), 'router branch crash restore does not reach WordPress admin-post');

$invalid_restore_crash = router_branch_action_request(
    $child,
    $branches,
    $cow,
    $router,
    $branch_list,
    $fake_bin,
    $work_dir,
    '/wp-admin/admin-post.php',
    ['action' => 'forkpress_branch_restore_crash', 'run' => 'abc']
);
assert_same($invalid_restore_crash['status'], 400, 'router branch crash restore rejects invalid run ids before CLI');
assert_same($invalid_restore_crash['json']['message'] ?? null, 'Choose a merge run to restore.', 'router branch crash restore explains invalid run ids');
assert_true(!str_contains($invalid_restore_crash['body'], 'WORDPRESS'), 'invalid router branch crash restore does not reach WordPress admin-post');

$invalid_restore_json = router_branch_action_request(
    $child,
    $branches,
    $cow,
    $router,
    $branch_list,
    $fake_bin,
    $work_dir,
    '/wp-admin/admin-post.php',
    ['action' => 'forkpress_branch_restore_crash', 'run' => '42'],
    true,
    ['FORKPRESS_TEST_CLI_OUTPUT' => 'not-json']
);
assert_same($invalid_restore_json['status'], 400, 'router branch crash restore rejects invalid CLI JSON');
assert_same($invalid_restore_json['json']['message'] ?? null, 'ForkPress returned invalid crash recovery restore JSON.', 'router branch crash restore explains invalid CLI JSON');
assert_true(!str_contains($invalid_restore_json['body'], 'WORDPRESS'), 'invalid JSON router branch crash restore does not reach WordPress admin-post');

$invalid_audit = router_branch_action_request(
    $child,
    $branches,
    $cow,
    $router,
    $branch_list,
    $fake_bin,
    $work_dir,
    '/wp-admin/admin-post.php',
    ['action' => 'forkpress_branch_conflicts', 'run' => 'abc']
);
assert_same($invalid_audit['status'], 400, 'async router branch conflict audit rejects invalid run ids before CLI');
assert_same($invalid_audit['json']['message'] ?? null, 'Choose a merge run to inspect.', 'async router branch conflict audit explains invalid run ids');
assert_true(!str_contains($invalid_audit['body'], 'WORDPRESS'), 'invalid async router branch conflict audit does not reach WordPress admin-post');

$filtered_audit = router_branch_action_request(
    $child,
    $branches,
    $cow,
    $router,
    $branch_list,
    $fake_bin,
    $work_dir,
    '/wp-admin/admin-post.php',
    [
        'action' => 'forkpress_branch_conflicts',
        'run' => '42',
        'scope' => 'plugin',
        'lifecycleState' => 'needs-action',
        'nextAction' => 'revalidate',
    ],
    true,
    ['FORKPRESS_TEST_CLI_OUTPUTS' => json_encode(['crash-recovery' => '{"crash_recovery":[]}', 'conflicts' => $audit_output], JSON_UNESCAPED_SLASHES)]
);
assert_same($filtered_audit['status'], 200, 'async router branch conflict audit accepts supported filters');
assert_same($filtered_audit['json']['filters']['scope'] ?? null, 'plugin', 'async router branch conflict audit returns scope filter');
assert_same($filtered_audit['json']['filters']['lifecycleState'] ?? null, 'needs-action', 'async router branch conflict audit returns lifecycle filter');
assert_same($filtered_audit['json']['filters']['nextAction'] ?? null, 'revalidate', 'async router branch conflict audit returns next-action filter');
assert_same(
    $filtered_audit['json']['auditCommand'] ?? null,
    'forkpress branch merge-audit --records conflicts --run 42 --scope plugin --lifecycle-state needs-action --next-action revalidate --format json',
    'async router branch conflict audit exposes filtered audit command'
);
assert_true(!str_contains($filtered_audit['body'], 'WORDPRESS'), 'filtered async router branch conflict audit does not reach WordPress admin-post');

$invalid_filter_audit = router_branch_action_request(
    $child,
    $branches,
    $cow,
    $router,
    $branch_list,
    $fake_bin,
    $work_dir,
    '/wp-admin/admin-post.php',
    ['action' => 'forkpress_branch_conflicts', 'run' => '42', 'scope' => 'everything']
);
assert_same($invalid_filter_audit['status'], 400, 'async router branch conflict audit rejects invalid filters before CLI');
assert_same($invalid_filter_audit['json']['message'] ?? null, 'Choose a valid merge conflict scope.', 'async router branch conflict audit explains invalid filters');
assert_true(!str_contains($invalid_filter_audit['body'], 'WORDPRESS'), 'invalid filtered async router branch conflict audit does not reach WordPress admin-post');

$revalidation_output = json_encode([
    'run_id' => 42,
    'checked' => 4,
    'stale' => 2,
    'carried' => 1,
    'needs_action_conflicts' => [
        ['conflict_id' => 11, 'revalidation_class' => 'compatible-target-drift'],
    ],
], JSON_UNESCAPED_SLASHES);
$revalidation = router_branch_action_request(
    $child,
    $branches,
    $cow,
    $router,
    $branch_list,
    $fake_bin,
    $work_dir,
    '/wp-admin/admin-post.php',
    ['action' => 'forkpress_branch_revalidate_conflicts', 'run' => '42'],
    true,
    ['FORKPRESS_TEST_CLI_OUTPUT' => $revalidation_output]
);
assert_same($revalidation['status'], 200, 'async router branch conflict revalidation returns 200');
assert_same($revalidation['json']['success'] ?? null, true, 'async router branch conflict revalidation returns JSON success');
assert_same($revalidation['json']['type'] ?? null, 'warning', 'async router branch conflict revalidation returns warning type');
assert_same($revalidation['json']['checked'] ?? null, 4, 'async router branch conflict revalidation exposes checked count');
assert_same($revalidation['json']['stale'] ?? null, 2, 'async router branch conflict revalidation exposes stale count');
assert_same($revalidation['json']['carried'] ?? null, 1, 'async router branch conflict revalidation exposes carried count');
assert_same(
    $revalidation['json']['auditCommand'] ?? null,
    'forkpress branch merge-audit --revalidate --run 42 --reviewer wordpress-ui --format json',
    'async router branch conflict revalidation exposes exact command'
);
assert_true(!str_contains($revalidation['body'], 'WORDPRESS'), 'async router branch conflict revalidation does not reach WordPress admin-post');

$apply_reviewed_output = json_encode([
    'run_id' => 42,
    'eligible' => 2,
    'applied' => 2,
    'status' => 'completed',
], JSON_UNESCAPED_SLASHES);
$apply_reviewed = router_branch_action_request(
    $child,
    $branches,
    $cow,
    $router,
    $branch_list,
    $fake_bin,
    $work_dir,
    '/wp-admin/admin-post.php',
    ['action' => 'forkpress_branch_apply_reviewed_conflicts', 'run' => '42'],
    true,
    ['FORKPRESS_TEST_CLI_OUTPUT' => $apply_reviewed_output]
);
assert_same($apply_reviewed['status'], 200, 'async router branch reviewed-resolution apply returns 200');
assert_same($apply_reviewed['json']['success'] ?? null, true, 'async router branch reviewed-resolution apply returns JSON success');
assert_same($apply_reviewed['json']['applied'] ?? null, 2, 'async router branch reviewed-resolution apply exposes applied count');
assert_same($apply_reviewed['json']['eligible'] ?? null, 2, 'async router branch reviewed-resolution apply exposes eligible count');
assert_same(
    $apply_reviewed['json']['applyReviewedCommand'] ?? null,
    'forkpress branch merge-apply-reviewed --run 42 --reviewer wordpress-ui --format json',
    'async router branch reviewed-resolution apply exposes exact command'
);
assert_true(!str_contains($apply_reviewed['body'], 'WORDPRESS'), 'async router branch reviewed-resolution apply does not reach WordPress admin-post');

$invalid_revalidation = router_branch_action_request(
    $child,
    $branches,
    $cow,
    $router,
    $branch_list,
    $fake_bin,
    $work_dir,
    '/wp-admin/admin-post.php',
    ['action' => 'forkpress_branch_revalidate_conflicts', 'run' => 'abc']
);
assert_same($invalid_revalidation['status'], 400, 'async router branch conflict revalidation rejects invalid run ids before CLI');
assert_same($invalid_revalidation['json']['message'] ?? null, 'Choose a merge run to revalidate.', 'async router branch conflict revalidation explains invalid run ids');
assert_true(!str_contains($invalid_revalidation['body'], 'WORDPRESS'), 'invalid async router branch conflict revalidation does not reach WordPress admin-post');

$invalid_apply_reviewed = router_branch_action_request(
    $child,
    $branches,
    $cow,
    $router,
    $branch_list,
    $fake_bin,
    $work_dir,
    '/wp-admin/admin-post.php',
    ['action' => 'forkpress_branch_apply_reviewed_conflicts', 'run' => 'abc']
);
assert_same($invalid_apply_reviewed['status'], 400, 'async router branch reviewed-resolution apply rejects invalid run ids before CLI');
assert_same($invalid_apply_reviewed['json']['message'] ?? null, 'Choose a merge run to apply reviewed resolutions.', 'async router branch reviewed-resolution apply explains invalid run ids');
assert_true(!str_contains($invalid_apply_reviewed['body'], 'WORDPRESS'), 'invalid async router branch reviewed-resolution apply does not reach WordPress admin-post');

$plugin_driver_output = json_encode([
    'status' => 'completed',
    'driver_status' => 'applied',
], JSON_UNESCAPED_SLASHES);
$plugin_driver = router_branch_action_request(
    $child,
    $branches,
    $cow,
    $router,
    $branch_list,
    $fake_bin,
    $work_dir,
    '/wp-admin/admin-post.php',
    [
        'action' => 'forkpress_branch_run_plugin_driver',
        'conflict' => '77',
        'driverKey' => $driver_key,
        'run' => '42',
    ],
    true,
    ['FORKPRESS_TEST_CLI_OUTPUT' => $plugin_driver_output]
);
assert_same($plugin_driver['status'], 200, 'async router plugin driver action returns 200');
assert_same($plugin_driver['json']['success'] ?? null, true, 'async router plugin driver action returns JSON success');
assert_same($plugin_driver['json']['driverStatus'] ?? null, 'applied', 'async router plugin driver action exposes driver status');
assert_same($plugin_driver['json']['driverPlugin'] ?? null, 'sample-plugin', 'async router plugin driver action exposes approved plugin');
assert_same($plugin_driver['json']['conflict'] ?? null, 77, 'async router plugin driver action exposes conflict id');
assert_true(!str_contains($plugin_driver['body'], 'WORDPRESS'), 'async router plugin driver action does not reach WordPress admin-post');

$invalid_plugin_driver_conflict = router_branch_action_request(
    $child,
    $branches,
    $cow,
    $router,
    $branch_list,
    $fake_bin,
    $work_dir,
    '/wp-admin/admin-post.php',
    [
        'action' => 'forkpress_branch_run_plugin_driver',
        'conflict' => 'abc',
        'driverKey' => $driver_key,
    ]
);
assert_same($invalid_plugin_driver_conflict['status'], 400, 'async router plugin driver action rejects invalid conflict ids before CLI');
assert_same($invalid_plugin_driver_conflict['json']['message'] ?? null, 'Choose a plugin conflict to repair.', 'async router plugin driver action explains invalid conflict ids');
assert_true(!str_contains($invalid_plugin_driver_conflict['body'], 'WORDPRESS'), 'invalid async router plugin driver action does not reach WordPress admin-post');

$invalid_plugin_driver_key = router_branch_action_request(
    $child,
    $branches,
    $cow,
    $router,
    $branch_list,
    $fake_bin,
    $work_dir,
    '/wp-admin/admin-post.php',
    [
        'action' => 'forkpress_branch_run_plugin_driver',
        'conflict' => '77',
        'driverKey' => 'not-approved',
    ]
);
assert_same($invalid_plugin_driver_key['status'], 400, 'async router plugin driver action rejects unknown driver keys before CLI');
assert_same($invalid_plugin_driver_key['json']['message'] ?? null, 'Choose an approved plugin merge driver.', 'async router plugin driver action explains unknown driver keys');
assert_true(!str_contains($invalid_plugin_driver_key['body'], 'WORDPRESS'), 'unknown-driver async router plugin driver action does not reach WordPress admin-post');

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

$non_async = router_branch_action_request(
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
assert_same($non_async['exit'], 0, 'non-async router branch create exits cleanly');
assert_same($non_async['status'], 200, 'non-async router branch create returns 200');
assert_same($non_async['json']['success'] ?? null, true, 'non-async router branch create still returns JSON success');
assert_same($non_async['json']['message'] ?? null, 'Created branch html_fallback.', 'non-async router branch create reports created branch');
assert_true(!str_contains($non_async['body'], 'WORDPRESS'), 'non-async router branch create does not reach WordPress admin-post');

$admin_page_create = router_branch_action_request(
    $child,
    $branches,
    $cow,
    $router,
    $branch_list,
    $fake_bin,
    $work_dir,
    '/wp-admin/admin.php?page=forkpress-branches',
    ['action' => 'forkpress_branch_create', 'branch' => 'admin_page_created', 'from' => 'main'],
    false
);
assert_same($admin_page_create['exit'], 0, 'admin-page router branch create exits cleanly');
assert_same($admin_page_create['status'], 200, 'admin-page router branch create returns 200');
assert_same($admin_page_create['json']['success'] ?? null, true, 'admin-page router branch create returns JSON success');
assert_same($admin_page_create['json']['message'] ?? null, 'Created branch admin_page_created.', 'admin-page router branch create reports created branch');
assert_true(!str_contains($admin_page_create['body'], 'WORDPRESS'), 'admin-page router branch create does not reach WordPress admin page');

$argv_log = [];
foreach (file($cli_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $decoded = json_decode($line, true);
    if (is_array($decoded)) {
        $argv_log[] = array_slice($decoded, 1);
    }
}
assert_same($argv_log[0] ?? null, ['branch', '--work-dir', $work_dir, 'create', 'router_created', '--from', 'main'], 'router branch create invokes safe CLI command');
assert_same($argv_log[1] ?? null, ['branch', '--work-dir', $work_dir, 'merge', 'router_created', '--into', 'main'], 'router branch merge invokes audited CLI command');
assert_same($argv_log[2] ?? null, ['branch', '--work-dir', $work_dir, 'merge', 'router_created', '--into', 'main'], 'conflicted router branch merge invokes audited CLI command');
assert_same($argv_log[3] ?? null, ['branch', '--work-dir', $work_dir, 'merge-audit', '--records', 'crash-recovery', '--run', '42', '--format', 'json'], 'router branch conflict audit checks crash recovery first');
assert_same($argv_log[4] ?? null, ['branch', '--work-dir', $work_dir, 'merge-audit', '--records', 'conflicts', '--run', '42', '--format', 'json'], 'router branch conflict audit invokes structured audit CLI command');
assert_same($argv_log[5] ?? null, ['branch', '--work-dir', $work_dir, 'merge-audit', '--records', 'crash-recovery', '--run', '42', '--format', 'json'], 'pending crash recovery router branch conflict audit checks crash recovery');
assert_same($argv_log[6] ?? null, ['branch', '--work-dir', $work_dir, 'recover-crash', '--run', '42', '--restore-target-db', '--restore-files', '--format', 'json'], 'router branch crash restore invokes structured recover-crash CLI command');
assert_same($argv_log[7] ?? null, ['branch', '--work-dir', $work_dir, 'recover-crash', '--run', '42', '--restore-target-db', '--restore-files', '--format', 'json'], 'router branch crash restore invalid JSON path invokes structured recover-crash CLI command');
assert_same($argv_log[8] ?? null, ['branch', '--work-dir', $work_dir, 'merge-audit', '--records', 'crash-recovery', '--run', '42', '--format', 'json'], 'filtered router branch conflict audit checks crash recovery first');
assert_same($argv_log[9] ?? null, ['branch', '--work-dir', $work_dir, 'merge-audit', '--records', 'conflicts', '--run', '42', '--format', 'json', '--scope', 'plugin', '--lifecycle-state', 'needs-action', '--next-action', 'revalidate'], 'router branch conflict audit invokes filtered structured audit CLI command');
assert_same($argv_log[10] ?? null, ['branch', '--work-dir', $work_dir, 'merge-audit', '--revalidate', '--run', '42', '--reviewer', 'wordpress-ui', '--format', 'json'], 'router branch conflict revalidation invokes structured revalidate CLI command');
assert_same($argv_log[11] ?? null, ['branch', '--work-dir', $work_dir, 'merge-apply-reviewed', '--run', '42', '--reviewer', 'wordpress-ui', '--format', 'json'], 'router reviewed-resolution apply invokes structured apply CLI command');
assert_same($argv_log[12] ?? null, ['branch', '--work-dir', $work_dir, 'run-plugin-driver', 'conflict', '77', '--driver', realpath($driver_path), '--reviewer', 'wordpress-ui', '--format', 'json'], 'router plugin driver action invokes approved driver CLI command');
assert_same($argv_log[13] ?? null, ['branch', '--work-dir', $work_dir, 'create', 'html_fallback', '--from', 'main'], 'non-async router branch create invokes safe CLI command');
assert_same($argv_log[14] ?? null, ['branch', '--work-dir', $work_dir, 'create', 'admin_page_created', '--from', 'main'], 'admin-page router branch create invokes safe CLI command');
assert_same(count($argv_log), 15, 'invalid branch action requests do not invoke router CLI path');

rm_tree($tmp);

echo "\n=== COW router branch action tests: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);

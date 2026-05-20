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
$fake_bin = $tmp . '/forkpress';
$router = realpath(__DIR__ . '/../../runtime/cow/router.php');
assert_true($router !== false, 'router fixture exists');
register_shutdown_function(static function() use ($tmp): void {
    rm_tree($tmp);
});

mkdir($main, 0777, true);
mkdir($cow, 0777, true);

file_put_contents($main . '/index.php', "<?php\nfile_put_contents(" . var_export($started, true) . ", sprintf(\"%.6f\\n\", microtime(true)));\necho \"OK\";\n");
file_put_contents($main . '/safe.txt', "SAFE\n");
file_put_contents($fake_bin, "#!/usr/bin/env php\n<?php\nexit(0);\n");
chmod($fake_bin, 0755);
putenv('FORKPRESS_BIN=' . $fake_bin);
putenv('FORKPRESS_WORK_DIR=' . $cow);
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
parse_str(parse_url($uri, PHP_URL_QUERY) ?: '', $_GET);
$_REQUEST = $_GET;
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

foreach (['forkpress_branch_create', 'forkpress_branch_merge', 'forkpress_branch_history', 'forkpress_branch_tree', 'forkpress_branch_conflicts', 'forkpress_branch_restore_crash', 'forkpress_branch_revalidate_conflicts', 'forkpress_branch_review_conflict', 'forkpress_branch_resolve_conflict', 'forkpress_branch_apply_reviewed_conflicts', 'forkpress_branch_run_plugin_driver'] as $action) {
    @unlink($entered);
    @unlink($started);
    $lock = fopen($lock_path, 'c');
    assert_true(is_resource($lock), "test reopened operation lock for admin branch action $action");
    if (is_resource($lock)) {
        assert_true(flock($lock, LOCK_EX), "test holds exclusive operation lock for admin branch action $action");

        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open([PHP_BINARY, $child, $branches, $cow, $router, "/wp-admin/admin-post.php?action=$action", $entered], $descriptor, $pipes);
        assert_true(is_resource($process), "spawned admin branch action request process for $action");
        if (is_resource($process)) {
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            $deadline = microtime(true) + 2.0;
            while (!file_exists($entered) && microtime(true) < $deadline) {
                usleep(10000);
            }
            assert_true(file_exists($entered), "admin branch action reached pre-lock gate for $action");
            $early_body = '';
            $deadline = microtime(true) + 2.0;
            while ($early_body === '' && microtime(true) < $deadline) {
                usleep(10000);
                $early_body .= stream_get_contents($pipes[1]);
            }
            $early_payload = json_decode($early_body, true);
            assert_true(is_array($early_payload), "admin branch action returns ForkPress JSON before lock release for $action");
            assert_same($early_payload['success'] ?? null, false, "admin branch action bypasses shared request lock for $action");

            flock($lock, LOCK_UN);
            fclose($lock);
            stream_set_blocking($pipes[1], true);

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);

            assert_same($status, 0, "admin branch action exits cleanly for $action");
            assert_same($stdout, '', "admin branch action output was already consumed for $action");
            assert_same($stderr, '', "admin branch action produced no stderr for $action");
            assert_true(!file_exists($started), "admin branch action did not fall through to WordPress for $action");
        }
    }
}

@unlink($entered);
@unlink($started);
$lock = fopen($lock_path, 'c');
assert_true(is_resource($lock), 'test reopened operation lock for out-of-band branch manager');
if (is_resource($lock)) {
    assert_true(flock($lock, LOCK_EX), 'test holds exclusive operation lock for out-of-band branch manager');

    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([PHP_BINARY, $child, $branches, $cow, $router, '/_forkpress/branches', $entered], $descriptor, $pipes);
    assert_true(is_resource($process), 'spawned out-of-band branch manager request process');
    if (is_resource($process)) {
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        $deadline = microtime(true) + 2.0;
        while (!file_exists($entered) && microtime(true) < $deadline) {
            usleep(10000);
        }
        assert_true(file_exists($entered), 'out-of-band branch manager reached pre-lock gate');
        usleep(150000);
        $early_body = stream_get_contents($pipes[1]);
        assert_true(str_contains($early_body, 'ForkPress Branches'), 'out-of-band branch manager renders before lock release');
        assert_true(str_contains($early_body, 'fp-graph'), 'out-of-band branch manager renders the branch graph surface');
        assert_true(str_contains($early_body, '"actionUrl":"/_forkpress/action"'), 'out-of-band branch manager uses same-origin action endpoint');
        assert_true(!str_contains($early_body, 'http://wp.localhost/_forkpress/action'), 'out-of-band branch manager does not hard-code an HTTP action endpoint');
        assert_true(str_contains($early_body, 'fp-timeline-lane'), 'out-of-band branch manager renders compact git-style graph lanes');
        assert_true(str_contains($early_body, 'fp-timeline-fork'), 'out-of-band branch manager renders branch fork curves');
        assert_true(str_contains($early_body, 'laneActivity'), 'out-of-band branch manager computes finite branch lifetimes from revision rows');
        assert_true(str_contains($early_body, 'branchForkParents'), 'out-of-band branch manager infers branch fork parents for timeline rendering');
        assert_true(str_contains($early_body, 'isBranchForkRun'), 'out-of-band branch manager treats branch setup rows as fork points');
        assert_true(str_contains($early_body, 'fp-lane-end'), 'out-of-band branch manager renders branch line end caps');
        assert_true(str_contains($early_body, 'fp-timeline-merge'), 'out-of-band branch manager renders merge curves between lanes');
        assert_true(str_contains($early_body, 'fp-row-title'), 'out-of-band branch manager renders revisions as timeline rows');
        assert_true(str_contains($early_body, 'sortedRunEntries'), 'out-of-band branch manager sorts real revision records, not one row per branch');
        assert_true(str_contains($early_body, 'runTimelineTimestamp'), 'out-of-band branch manager sorts revisions by timestamp before run id');
        assert_true(str_contains($early_body, 'interleaved timeline revisions / newest first'), 'out-of-band branch manager labels interleaved revision timeline direction');
        assert_true(str_contains($early_body, 'seedConflictSummaries'), 'out-of-band branch manager seeds conflict summaries without extra conflict audits');
        assert_true(str_contains($early_body, 'selectedRunId'), 'out-of-band branch manager keeps the selected revision synced after conflict audits load');
        assert_true(str_contains($early_body, 'refreshSelectedRunConflictState'), 'out-of-band branch manager refreshes selected revision conflict totals after audit details load');
        assert_true(!str_contains($early_body, 'Promise.all(conflictRuns.map'), 'out-of-band branch manager avoids N+1 conflict audit loading');
        assert_true(str_contains($early_body, 'renderConflictLoading'), 'out-of-band branch manager renders an immediate conflict loader');
        assert_true(str_contains($early_body, 'forkpress_branch_tree'), 'out-of-band branch manager can load branch tree data');
        assert_true(str_contains($early_body, 'forkpress_branch_conflicts'), 'out-of-band branch manager can revisit conflicts');
        assert_true(str_contains($early_body, 'fp-conflict-grid'), 'out-of-band branch manager renders conflict values as review fields');
        assert_true(str_contains($early_body, 'decodeAuditPayload'), 'out-of-band branch manager decodes audit payloads for review');
        assert_true(str_contains($early_body, 'Review note'), 'out-of-band branch manager exposes editable review notes');
        assert_true(str_contains($early_body, 'Apply selected'), 'out-of-band branch manager exposes a selected conflict resolution action');
        assert_true(str_contains($early_body, 'Change applied resolution'), 'out-of-band branch manager exposes applied-resolution changes');
        assert_true(str_contains($early_body, 'prioritizeResolutionChange'), 'out-of-band branch manager surfaces applied-resolution changes before large value previews');
        assert_true(str_contains($early_body, 'replaceApplied'), 'out-of-band branch manager sends replace-applied resolution payloads');
        assert_true(str_contains($early_body, 'function conflictPluginMeta'), 'out-of-band branch manager renders plugin and theme conflict metadata');
        assert_true(str_contains($early_body, 'function conflictPluginGuidance'), 'out-of-band branch manager renders plugin and theme conflict guidance');
        assert_true(str_contains($early_body, 'record.plugin_suggested_action'), 'out-of-band branch manager exposes validator suggested actions');
        assert_true(str_contains($early_body, 'record.semantic_scope'), 'out-of-band branch manager exposes validator semantic scope');
        assert_true(str_contains($early_body, 'Branch actions'), 'out-of-band branch manager keeps create and merge actions available');
        assert_true(!file_exists($started), 'out-of-band branch manager did not execute branch PHP');

        flock($lock, LOCK_UN);
        fclose($lock);
        stream_set_blocking($pipes[1], true);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        assert_same($status, 0, 'out-of-band branch manager exits cleanly');
        assert_same($stdout, '', 'out-of-band branch manager output was already consumed');
        assert_same($stderr, '', 'out-of-band branch manager produced no stderr');
    }
}

@unlink($entered);
@unlink($started);
$descriptor = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = proc_open([PHP_BINARY, $child, $branches, $cow, $router, '/wp-admin/admin-post.php?action=forkpress_branch_create&branch=feature&from=main', $entered], $descriptor, $pipes);
assert_true(is_resource($process), 'spawned router branch create action request process');
if (is_resource($process)) {
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $payload = json_decode($stdout, true);
    assert_same($status, 0, 'router branch create action exits cleanly');
    assert_same($stderr, '', 'router branch create action produced no stderr');
    assert_true(is_array($payload), 'router branch create action returns JSON');
    assert_same($payload['success'] ?? null, true, 'router branch create action reports success');
    assert_same($payload['url'] ?? null, 'http://feature.wp.localhost/wp-admin/', 'router branch create action returns the new branch admin URL');
    assert_same($payload['branches'][0]['name'] ?? null, 'feature', 'router branch create action marks new branch current');
    assert_same($payload['branches'][0]['url'] ?? null, 'http://feature.wp.localhost/wp-admin/', 'router branch create action returns a branch-specific feature URL');
    assert_same($payload['branches'][1]['url'] ?? null, 'http://wp.localhost/wp-admin/', 'router branch create action returns a distinct main URL');
    assert_true(!file_exists($started), 'router branch create action did not fall through to WordPress');
}

rm_tree($tmp);

echo "\n=== COW router lock tests: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);

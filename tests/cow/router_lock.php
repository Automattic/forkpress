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
function read_stream_until($stream, string $needle, float $timeout_seconds): string {
    $body = '';
    $deadline = microtime(true) + $timeout_seconds;
    while (microtime(true) < $deadline) {
        $chunk = stream_get_contents($stream);
        if ($chunk !== false && $chunk !== '') {
            $body .= $chunk;
        }
        if ($needle !== '' && str_contains($body, $needle)) {
            break;
        }
        usleep(10000);
    }
    $chunk = stream_get_contents($stream);
    if ($chunk !== false && $chunk !== '') {
        $body .= $chunk;
    }
    return $body;
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
        $early_body = read_stream_until($pipes[1], '</html>', 2.0);
        assert_true(str_contains($early_body, 'ForkPress Branches'), 'out-of-band branch manager renders before lock release');
        assert_true(str_contains($early_body, 'fp-graph'), 'out-of-band branch manager renders the branch graph surface');
        assert_true(str_contains($early_body, 'fp-bottom'), 'out-of-band branch manager renders one persistent bottom workbench');
        assert_true(str_contains($early_body, 'fp-bottom-actions'), 'out-of-band branch manager keeps branch actions in the bottom workbench');
        assert_true(str_contains($early_body, 'fp-workbench-mode'), 'out-of-band branch manager labels the current workbench mode');
        assert_true(str_contains($early_body, 'role="status" aria-live="polite"'), 'out-of-band branch manager announces async status changes');
        assert_true(str_contains($early_body, 'function statusKindForPayload'), 'out-of-band branch manager normalizes warning/notice action status');
        assert_true(!str_contains($early_body, 'fp-side'), 'out-of-band branch manager no longer alternates through a right sidebar');
        assert_true(str_contains($early_body, '"actionUrl":"/_forkpress/action"'), 'out-of-band branch manager uses same-origin action endpoint');
        assert_true(!str_contains($early_body, 'http://wp.localhost/_forkpress/action'), 'out-of-band branch manager does not hard-code an HTTP action endpoint');
        assert_true(str_contains($early_body, '"pluginDrivers"'), 'out-of-band branch manager receives approved plugin merge drivers');
        assert_true(str_contains($early_body, 'fp-timeline-lane'), 'out-of-band branch manager renders compact git-style graph lanes');
        assert_true(str_contains($early_body, 'fp-timeline-fork'), 'out-of-band branch manager renders branch fork curves');
        assert_true(str_contains($early_body, 'laneActivity'), 'out-of-band branch manager computes finite branch lifetimes from revision rows');
        assert_true(str_contains($early_body, 'branchForkParents'), 'out-of-band branch manager infers branch fork parents for timeline rendering');
        assert_true(str_contains($early_body, 'isBranchForkRun'), 'out-of-band branch manager treats branch setup rows as fork points');
        assert_true(str_contains($early_body, 'fp-lane-boundary'), 'out-of-band branch manager renders branch line boundaries as ticks, not extra revision dots');
        assert_true(str_contains($early_body, 'fp-timeline-merge'), 'out-of-band branch manager renders merge curves between lanes');
        assert_true(str_contains($early_body, 'marker-end'), 'out-of-band branch manager renders merge direction arrows into the target branch');
        assert_true(str_contains($early_body, 'arrowEndX'), 'out-of-band branch manager points merge arrows at the target revision node edge');
        assert_true(str_contains($early_body, 'fp-merge-flow'), 'out-of-band branch manager labels merge source-to-target flow inline on the graph');
        assert_true(str_contains($early_body, 'if (isSelected) {'), 'out-of-band branch manager limits inline merge-flow labels to the selected event');
        assert_true(str_contains($early_body, 'badgeWidth'), 'out-of-band branch manager renders conflict counts as badges rather than extra revision nodes');
        assert_true(str_contains($early_body, 'merge into'), 'out-of-band branch manager labels merge revisions by target branch');
        assert_true(str_contains($early_body, 'fp-row-title'), 'out-of-band branch manager renders revisions as timeline rows');
        assert_true(str_contains($early_body, 'sortedRunEntries'), 'out-of-band branch manager sorts real revision records, not one row per branch');
        assert_true(str_contains($early_body, 'runTimelineTimestamp'), 'out-of-band branch manager sorts revisions by timestamp before run id');
        assert_true(str_contains($early_body, 'events / newest first'), 'out-of-band branch manager labels timeline direction');
        assert_true(str_contains($early_body, 'branchLabelText'), 'out-of-band branch manager compacts and staggers branch lane labels');
        assert_true(str_contains($early_body, 'fp-node-merge'), 'out-of-band branch manager renders merge events with a distinct target marker');
        assert_true(str_contains($early_body, 'Merge event #'), 'out-of-band branch manager distinguishes merge events from ordinary revisions');
        assert_true(str_contains($early_body, 'fp-legend'), 'out-of-band branch manager explains graph symbols with an inline legend');
        assert_true(str_contains($early_body, '<span><i class="conflicts"></i>conflicts</span>'), 'out-of-band branch manager labels graph conflicts plainly');
        assert_true(str_contains($early_body, 'merge result exists'), 'out-of-band branch manager makes clear conflicted merges already have target branch results');
        assert_true(str_contains($early_body, 'merged with conflicts'), 'out-of-band branch manager avoids implying conflicted merges are value-less');
        assert_true(str_contains($early_body, 'fp-row-selected'), 'out-of-band branch manager highlights the selected timeline event');
        assert_true(str_contains($early_body, 'function scrollSelectedRunIntoView'), 'out-of-band branch manager scrolls the graph to deep-linked selected events');
        assert_true(str_contains($early_body, 'Focus selected'), 'out-of-band branch manager offers a clearly labeled graph control to re-center the selected event');
        assert_true(str_contains($early_body, "graph.addEventListener('keydown'"), 'out-of-band branch manager supports keyboard selection in the graph');
        assert_true(str_contains($early_body, 'seedConflictSummaries'), 'out-of-band branch manager seeds conflict summaries without extra conflict audits');
        assert_true(str_contains($early_body, 'selectedRunId'), 'out-of-band branch manager keeps the selected revision synced after conflict audits load');
        assert_true(str_contains($early_body, 'didRestoreInitialSelection'), 'out-of-band branch manager restores URL selection once without overriding later selections');
        assert_true(str_contains($early_body, 'preserveSelection'), 'out-of-band branch manager preserves selected timeline events during refreshes');
        assert_true(str_contains($early_body, 'skipConflictReload'), 'out-of-band branch manager avoids duplicate conflict reloads during graph refreshes');
        assert_true(str_contains($early_body, 'initialParams'), 'out-of-band branch manager can restore selected review state from the URL');
        assert_true(str_contains($early_body, 'function syncReviewUrl'), 'out-of-band branch manager writes selected run and conflict state to the URL');
        assert_true(str_contains($early_body, "params.set('run'"), 'out-of-band branch manager deep-links selected merge/revision runs');
        assert_true(str_contains($early_body, "params.set('conflict'"), 'out-of-band branch manager deep-links selected conflict rows');
        assert_true(str_contains($early_body, "params.set('filter'"), 'out-of-band branch manager deep-links the active conflict filter');
        assert_true(str_contains($early_body, 'requestedRun'), 'out-of-band branch manager ignores stale conflict loads after selecting another revision');
        assert_true(str_contains($early_body, 'refreshSelectedRunConflictState'), 'out-of-band branch manager refreshes selected revision conflict totals after audit details load');
        assert_true(!str_contains($early_body, 'Promise.all(conflictRuns.map'), 'out-of-band branch manager avoids N+1 conflict audit loading');
        assert_true(str_contains($early_body, 'renderConflictLoading'), 'out-of-band branch manager renders an immediate conflict loader');
        assert_true(str_contains($early_body, 'function forgetConflictCache'), 'out-of-band branch manager invalidates cached conflict records after resolution actions');
        assert_true(str_contains($early_body, 'function refreshAfterConflictAction'), 'out-of-band branch manager refreshes graph and conflict data after resolution actions');
        assert_true(str_contains($early_body, 'forkpress_branch_tree'), 'out-of-band branch manager can load branch tree data');
        assert_true(str_contains($early_body, 'forkpress_branch_conflicts'), 'out-of-band branch manager can revisit conflicts');
        assert_true(str_contains($early_body, 'forkpress_branch_revalidate_conflicts'), 'out-of-band branch manager can revalidate conflicts from the full-page review');
        assert_true(str_contains($early_body, 'forkpress_branch_restore_crash'), 'out-of-band branch manager can restore pending crash recovery from the full-page review');
        assert_true(str_contains($early_body, 'fp-conflict-workbench'), 'out-of-band branch manager renders conflicts in the bottom workbench');
        assert_true(str_contains($early_body, 'fp-conflict-review-grid'), 'out-of-band branch manager keeps conflict table and inspector on one screen');
        assert_true(str_contains($early_body, 'body.fp-reviewing .fp-bottom-body'), 'out-of-band branch manager expands conflict review to the full workbench width');
        assert_true(str_contains($early_body, 'grid-template-rows: minmax(180px, 26vh) minmax(0, 1fr)'), 'out-of-band branch manager gives review mode more vertical room for conflicts');
        assert_true(str_contains($early_body, 'repeat(auto-fit, minmax(128px, 1fr))'), 'out-of-band branch manager compresses merge metadata into a responsive strip');
        assert_true(str_contains($early_body, 'body.fp-reviewing .fp-bottom-primary'), 'out-of-band branch manager can hide duplicated run metadata while reviewing conflicts');
        assert_true(str_contains($early_body, 'grid-template-rows: minmax(0, 1fr)'), 'out-of-band branch manager gives the conflict workbench the full lower pane in review mode');
        assert_true(str_contains($early_body, 'fp-conflict-inspector'), 'out-of-band branch manager renders one selected conflict inspector instead of every detail card');
        assert_true(str_contains($early_body, 'setSelectedConflict'), 'out-of-band branch manager changes selected conflicts without rerendering the whole workbench');
        assert_true(str_contains($early_body, 'fp-conflict-inspector-slot'), 'out-of-band branch manager has a stable selected-conflict inspector slot');
        assert_true(!str_contains($early_body, 'Review conflicts'), 'out-of-band branch manager auto-loads conflicts instead of requiring a separate review button');
        assert_true(str_contains($early_body, 'function renderConflictTable'), 'out-of-band branch manager renders conflict summaries as an entity table');
        assert_true(str_contains($early_body, 'data-label'), 'out-of-band branch manager can render mobile conflict rows as labeled cards');
        assert_true(str_contains($early_body, 'cell.title = tooltip.join(\'\\n\')'), 'out-of-band branch manager exposes full conflict table cell text on hover without breaking generated JavaScript');
        assert_true(str_contains($early_body, "row.setAttribute('role', 'button')"), 'out-of-band branch manager makes conflict rows keyboard-operable review targets');
        assert_true(str_contains($early_body, "row.addEventListener('keydown'"), 'out-of-band branch manager supports keyboard selection in the conflict table');
        assert_true(str_contains($early_body, 'fp-summary-chips'), 'out-of-band branch manager renders compact conflict summary chips');
        assert_true(str_contains($early_body, 'merge result exists'), 'out-of-band branch manager separates material merge outcome from resolution choices');
        assert_true(str_contains($early_body, 'choices applied'), 'out-of-band branch manager describes applied conflict choices directly');
        assert_true(str_contains($early_body, 'without explicit choice'), 'out-of-band branch manager describes conflicts without an explicit choice directly');
        assert_true(str_contains($early_body, 'function conflictResolutionSummaryText'), 'out-of-band branch manager centralizes resolution-state terminology');
        assert_true(str_contains($early_body, 'function conflictHasAppliedResolution'), 'out-of-band branch manager names applied resolution state without review workflow terms');
        assert_true(str_contains($early_body, 'function normalizeConflictFilter'), 'out-of-band branch manager preserves old filter links while using clearer terms');
        assert_true(str_contains($early_body, 'function conflictPendingChoiceCount'), 'out-of-band branch manager names pending choice counts directly');
        assert_true(str_contains($early_body, 'fp-filterbar'), 'out-of-band branch manager renders conflict filter controls');
        assert_true(str_contains($early_body, 'function conflictScope'), 'out-of-band branch manager classifies conflict scope for filtering');
        assert_true(str_contains($early_body, 'function conflictFilterCounts'), 'out-of-band branch manager counts conflicts by status and scope');
        assert_true(str_contains($early_body, 'function conflictFilterLabel'), 'out-of-band branch manager names empty conflict filters clearly');
        assert_true(str_contains($early_body, 'aria-pressed'), 'out-of-band branch manager exposes active conflict filters accessibly');
        assert_true(str_contains($early_body, 'Applied choices'), 'out-of-band branch manager filters by applied resolution choices, not review state');
        assert_true(!str_contains($early_body, "['unreviewed', 'Unreviewed']"), 'out-of-band branch manager does not expose an unreviewed workflow filter');
        assert_true(!str_contains($early_body, "['accepted', 'Accepted']"), 'out-of-band branch manager does not expose an accepted workflow filter');
        assert_true(str_contains($early_body, "['Conflict', 'DB table', 'ID', 'Resolution', 'Summary']"), 'out-of-band branch manager keeps DB table, entity IDs, and resolution visible in the conflict table');
        assert_true(str_contains($early_body, "button('Prev'"), 'out-of-band branch manager can move backward through the filtered review queue');
        assert_true(str_contains($early_body, "button('Next'"), 'out-of-band branch manager can move forward through the filtered review queue');
        assert_true(str_contains($early_body, 'fp-review-progress'), 'out-of-band branch manager labels the selected conflict check position compactly');
        assert_true(str_contains($early_body, 'Refresh conflicts'), 'out-of-band branch manager exposes a clear conflict refresh action near the queue');
        assert_true(str_contains($early_body, 'fp-toolbar-more'), 'out-of-band branch manager moves source/target/review-link actions into a compact secondary menu');
        assert_true(str_contains($early_body, 'humanConflictType'), 'out-of-band branch manager translates internal conflict type labels before rendering them');
        assert_true(str_contains($early_body, '.fp-buttons .fp-conflict-meta'), 'out-of-band branch manager keeps inline action labels from stretching mobile controls');
        assert_true(str_contains($early_body, 'overflow-x: auto'), 'out-of-band branch manager keeps mobile conflict actions compact');
        assert_true(str_contains($early_body, 'b.title = label'), 'out-of-band branch manager gives compact buttons full action titles');
        assert_true(str_contains($early_body, 'function setButtonBusy'), 'out-of-band branch manager centralizes busy button state');
        assert_true(str_contains($early_body, 'function runButtonAction'), 'out-of-band branch manager prevents double-clicked async actions');
        assert_true(str_contains($early_body, "buttonNode.setAttribute('aria-busy'"), 'out-of-band branch manager exposes busy state accessibly');
        assert_true(str_contains($early_body, 'button.is-busy::after'), 'out-of-band branch manager shows busy action feedback without layout changes');
        assert_true(str_contains($early_body, 'function appendBranchPreviewLinks'), 'out-of-band branch manager keeps source and target branch actions available in filtered states');
        assert_true(str_contains($early_body, 'function branchManagerHref'), 'out-of-band branch manager gives source and target actions same-origin manager URLs');
        assert_true(str_contains($early_body, 'function branchManagerLink'), 'out-of-band branch manager focuses source and target branches without leaving the manager');
        assert_true(str_contains($early_body, 'initialBranchName'), 'out-of-band branch manager can restore a directly linked branch detail view');
        assert_true(str_contains($early_body, "params.set('branch'"), 'out-of-band branch manager deep-links selected branch detail views');
        assert_true(str_contains($early_body, 'Show source:'), 'out-of-band branch manager labels source branch focus actions clearly');
        assert_true(str_contains($early_body, 'Show target:'), 'out-of-band branch manager labels target branch focus actions clearly');
        assert_true(!str_contains($early_body, "link('Open source:"), 'out-of-band branch manager does not link source actions to branch WordPress hosts');
        assert_true(!str_contains($early_body, "link('Open target:"), 'out-of-band branch manager does not link target actions to branch WordPress hosts');
        assert_true(str_contains($early_body, 'Copy conflict link'), 'out-of-band branch manager exposes a copyable deep link for conflict state');
        assert_true(str_contains($early_body, 'navigator.clipboard.writeText'), 'out-of-band branch manager copies the current review URL when supported');
        assert_true(str_contains($early_body, 'function conflictEntityContext'), 'out-of-band branch manager normalizes conflict entity context for review');
        assert_true(str_contains($early_body, 'entityContext'), 'out-of-band branch manager receives enriched conflict entity context from audits');
        assert_true(str_contains(file_get_contents($router), 'forkpress_cow_conflict_row_payload'), 'out-of-band branch manager can enrich stale conflict context from captured row payloads');
        assert_true(str_contains($early_body, 'wp_options'), 'out-of-band branch manager explains option conflicts by option row context');
        assert_true(str_contains($early_body, 'option_name'), 'out-of-band branch manager exposes option names in conflict context');
        assert_true(str_contains($early_body, 'wp_posts'), 'out-of-band branch manager explains post conflicts by post ID context');
        assert_true(str_contains($early_body, 'post_id'), 'out-of-band branch manager exposes post IDs in conflict context');
        assert_true(str_contains($early_body, 'meta_key'), 'out-of-band branch manager exposes post meta keys in conflict context');
        assert_true(str_contains($early_body, 'fp-conflict-grid'), 'out-of-band branch manager renders conflict values as review fields');
        assert_true(str_contains($early_body, 'decodeAuditPayload'), 'out-of-band branch manager decodes audit payloads for review');
        assert_true(str_contains($early_body, 'Resolution note'), 'out-of-band branch manager exposes editable resolution notes');
        assert_true(str_contains($early_body, 'fp-disclosure'), 'out-of-band branch manager progressively discloses secondary conflict details');
        assert_true(str_contains($early_body, 'Compare values'), 'out-of-band branch manager groups source/base/target values behind one value section');
        assert_true(str_contains($early_body, 'Plugin details'), 'out-of-band branch manager hides dense plugin metadata behind a disclosure');
        assert_true(str_contains($early_body, 'Apply selected choice'), 'out-of-band branch manager exposes a selected conflict resolution action');
        assert_true(str_contains($early_body, 'fp-conflict-action-row'), 'out-of-band branch manager keeps conflict resolution actions visible in the inspector');
        assert_true(str_contains($early_body, 'fp-conflict-card-head'), 'out-of-band branch manager uses a compact conflict card header');
        assert_true(str_contains($early_body, 'fp-conflict-card-controls'), 'out-of-band branch manager keeps conflict actions outside long payload scrolling');
        assert_true(str_contains($early_body, 'fp-conflict-scroll'), 'out-of-band branch manager gives each conflict card a dedicated scroll body');
        assert_true(strpos($early_body, 'controls.appendChild(row);') < strpos($early_body, 'node.appendChild(scrollBody);'), 'out-of-band branch manager places review actions before scrollable details');
        assert_true(str_contains($early_body, "disclosure('Plugin details', pluginPanel, false)"), 'out-of-band branch manager keeps plugin/theme metadata in collapsed secondary details');
        assert_true(str_contains($early_body, 'Change applied resolution'), 'out-of-band branch manager exposes applied-resolution changes');
        assert_true(str_contains($early_body, 'prioritizeResolutionChange'), 'out-of-band branch manager surfaces applied-resolution changes before large value previews');
        assert_true(str_contains($early_body, 'replaceApplied'), 'out-of-band branch manager sends replace-applied resolution payloads');
        assert_true(str_contains($early_body, 'Change the already-applied resolution'), 'out-of-band branch manager confirms before replacing an applied resolution');
        assert_true(!str_contains($early_body, 'Apply reviewed choices'), 'out-of-band branch manager does not expose a reviewed/unreviewed workflow action');
        assert_true(!str_contains($early_body, 'function applyReviewedConflicts'), 'out-of-band branch manager does not ship unused reviewed-resolution workflow JavaScript');
        assert_true(!str_contains($early_body, 'Apply all reviewed conflict choices'), 'out-of-band branch manager does not expose bulk reviewed-choice workflow copy');
        assert_true(str_contains($early_body, 'function renderCrashRecovery'), 'out-of-band branch manager renders pending crash recovery as a blocking state');
        assert_true(str_contains($early_body, 'fp-crash-recovery'), 'out-of-band branch manager styles crash recovery separately from ordinary conflicts');
        assert_true(str_contains($early_body, 'function restoreCrashRecovery'), 'out-of-band branch manager can invoke crash recovery restoration');
        assert_true(str_contains($early_body, 'Restore crash recovery artifacts'), 'out-of-band branch manager confirms before restoring crash recovery artifacts');
        assert_true(str_contains($early_body, 'function conflictPluginMeta'), 'out-of-band branch manager renders plugin and theme conflict metadata');
        assert_true(str_contains($early_body, 'function conflictPluginGuidance'), 'out-of-band branch manager renders plugin and theme conflict guidance');
        assert_true(str_contains($early_body, 'record.plugin_suggested_action'), 'out-of-band branch manager exposes validator suggested actions');
        assert_true(str_contains($early_body, 'record.semantic_scope'), 'out-of-band branch manager exposes validator semantic scope');
        assert_true(str_contains($early_body, 'function pluginDriverFor'), 'out-of-band branch manager matches plugin conflicts to approved drivers');
        assert_true(str_contains($early_body, 'Run plugin driver'), 'out-of-band branch manager exposes approved plugin driver actions');
        assert_true(str_contains($early_body, 'No approved driver'), 'out-of-band branch manager explains plugin conflicts without configured drivers');
        assert_true(str_contains($early_body, 'function runPluginDriver'), 'out-of-band branch manager can run approved plugin drivers from conflict review');
        assert_true(str_contains($early_body, 'Run the approved plugin driver'), 'out-of-band branch manager confirms before running plugin driver automation');
        assert_true(str_contains($early_body, 'Fork from here'), 'out-of-band branch manager exposes branch creation as a direct contextual action');
        assert_true(str_contains($early_body, 'Merge this revision'), 'out-of-band branch manager exposes branch merging as a direct contextual action');
        assert_true(str_contains($early_body, 'Fork a branch from the selected context'), 'out-of-band branch manager labels the fork action accessibly');
        assert_true(str_contains($early_body, 'Merge the selected revision branch'), 'out-of-band branch manager labels the merge action accessibly');
        assert_true(str_contains($early_body, 'function configureForkAction'), 'out-of-band branch manager points fork actions at the selected branch context');
        assert_true(str_contains($early_body, 'function configureMergeAction'), 'out-of-band branch manager points merge actions at the selected revision context');
        assert_true(str_contains($early_body, 'function closeActionDetails'), 'out-of-band branch manager keeps one branch action popover open at a time');
        assert_true(str_contains($early_body, 'summary::-webkit-details-marker'), 'out-of-band branch manager suppresses default disclosure triangles on branch action buttons');
        assert_true(!str_contains($early_body, 'Flag follow-up'), 'out-of-band branch manager does not expose follow-up workflow actions');
        assert_true(!str_contains($early_body, 'Mark reviewed'), 'out-of-band branch manager does not expose mark-reviewed workflow actions');
        assert_true(str_contains($early_body, 'renderConflicts(payload);'), 'out-of-band branch manager rerenders conflict navigation after selecting a different check');
        assert_true(str_contains($early_body, 'body.fp-reviewing .fp-conflict-workbench-body'), 'out-of-band branch manager bounds conflict review panes inside the viewport');
        assert_true(str_contains($early_body, '.fp-conflict-scroll'), 'out-of-band branch manager locks long card content to an internal scroll region');
        assert_true(str_contains($early_body, 'overscroll-behavior: contain'), 'out-of-band branch manager keeps conflict-pane scrolling contained');
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

if (class_exists('SQLite3')) {
    @mkdir($cow . '/merge', 0777, true);
    $metadata = new SQLite3($cow . '/merge/metadata.sqlite');
    $metadata->exec('CREATE TABLE merge_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, source_branch TEXT NOT NULL, target_branch TEXT NOT NULL, base_ref TEXT, started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, finished_at TEXT, status TEXT NOT NULL, policy TEXT NOT NULL, source_db TEXT NOT NULL, target_db TEXT NOT NULL, base_db TEXT NOT NULL, source_root TEXT NOT NULL DEFAULT "", target_root TEXT NOT NULL DEFAULT "", target_before_db TEXT NOT NULL DEFAULT "", target_before_root TEXT NOT NULL DEFAULT "", failure_reason TEXT)');
    $metadata->exec('CREATE TABLE merge_conflicts (id INTEGER PRIMARY KEY AUTOINCREMENT, run_id INTEGER NOT NULL, table_name TEXT NOT NULL, conflict_type TEXT NOT NULL)');
    $metadata->exec('CREATE TABLE merge_decisions (id INTEGER PRIMARY KEY AUTOINCREMENT, run_id INTEGER NOT NULL)');
    $metadata->exec("INSERT INTO merge_runs (source_branch, target_branch, base_ref, started_at, finished_at, status, policy, source_db, target_db, base_db) VALUES ('feature-a', 'main', 'test', '2026-05-20 12:00:00', '2026-05-20 12:00:01', 'completed', 'target-wins', '', '', '')");
    $metadata->close();

    $process = proc_open(
        [PHP_BINARY, $child, $branches, $cow, $router, '/wp-admin/admin-post.php?action=forkpress_branch_tree&limit=50', $entered],
        $descriptor,
        $pipes,
        null,
        ['FORKPRESS_BIN' => '', 'FORKPRESS_WORK_DIR' => '']
    );
    assert_true(is_resource($process), 'spawned metadata fallback branch tree request process');
    if (is_resource($process)) {
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $payload = json_decode($stdout, true);
        assert_same($status, 0, 'metadata fallback branch tree exits cleanly');
        assert_same($stderr, '', 'metadata fallback branch tree produced no stderr');
        assert_true(is_array($payload), 'metadata fallback branch tree returns JSON');
        assert_same($payload['success'] ?? null, true, 'metadata fallback branch tree reports success');
        assert_same($payload['records'][0]['source_branch'] ?? null, 'feature-a', 'metadata fallback branch tree returns merge runs');
        $branch_names = array_map(static fn($branch) => $branch['name'] ?? '', $payload['branches'] ?? []);
        assert_true(in_array('feature-a', $branch_names, true), 'metadata fallback branch tree returns branches discovered from merge metadata');
    }
}

rm_tree($tmp);

echo "\n=== COW router lock tests: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);

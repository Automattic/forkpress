<?php
/**
 * Plain-file router for ForkPress materialized-branch strategies.
 *
 * Branch resolution matches the BranchFS router:
 *   - <root-domain>       -> main
 *   - <branch>.<root>     -> branch
 *
 * Each branch is a materialized WordPress tree under
 * .forkpress/<strategy>/branches/<branch>. WordPress reads and writes
 * ordinary files and an ordinary SQLite database in that tree; there are no
 * SQL overlays.
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);

$branches_dir = getenv('FORKPRESS_BRANCHES_DIR') ?: getenv('FORKPRESS_COW_BRANCHES_DIR') ?: getenv('FORKPRESS_ZFS_BRANCHES_DIR');
$strategy_label = getenv('FORKPRESS_PLAIN_STRATEGY') ?: 'plain-file';
$root_host = getenv('FORKPRESS_ROOT_HOST') ?: 'wp.localhost';

if (!$branches_dir || !is_dir($branches_dir)) {
    http_response_code(500);
    echo "FORKPRESS_BRANCHES_DIR env var required\n";
    return true;
}

$host = $_SERVER['HTTP_HOST'] ?? '';
$host_noport = preg_replace('/:\d+$/', '', $host);
$host_noport = strtolower($host_noport);
$root_host_lc = strtolower($root_host);

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$query = parse_url($uri, PHP_URL_QUERY) ?: '';

function forkpress_cow_normalize_request_path(string $path): ?string {
    $decoded = $path;
    for ($i = 0; $i < 4; $i++) {
        $next = rawurldecode($decoded);
        if ($next === $decoded) {
            break;
        }
        $decoded = $next;
    }

    if ($decoded === '' || $decoded[0] !== '/') {
        $decoded = '/' . $decoded;
    }
    if (strpos($decoded, "\0") !== false) {
        return null;
    }

    $decoded = str_replace('\\', '/', $decoded);
    $trailing_slash = str_ends_with($decoded, '/');
    $segments = [];
    foreach (explode('/', $decoded) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            return null;
        }
        $segments[] = $segment;
    }

    $normalized = '/' . implode('/', $segments);
    if ($trailing_slash && $normalized !== '/') {
        $normalized .= '/';
    }
    return $normalized;
}

$path = forkpress_cow_normalize_request_path($path);
if ($path === null) {
    http_response_code(404);
    echo "Not found\n";
    return true;
}

$branch = 'main';
if ($host_noport === $root_host_lc || $host_noport === '' || $host_noport === '127.0.0.1' || $host_noport === 'localhost') {
    $branch = 'main';
} elseif (substr($host_noport, -strlen('.' . $root_host_lc)) === '.' . $root_host_lc) {
    $sub = substr($host_noport, 0, -strlen('.' . $root_host_lc));
    if (strpos($sub, '.') === false && preg_match('/^[a-zA-Z0-9_\-]{1,63}$/', $sub)) {
        $branch = $sub;
    }
}

if (!preg_match('/^[a-zA-Z0-9_\-]{1,63}$/', $branch)) {
    http_response_code(404);
    echo "Unknown branch\n";
    return true;
}

if (preg_match('|^/([a-zA-Z0-9_\-]+)\.git(/.*)?$|', $path, $git_match)) {
    $git_path = $git_match[2] ?? '/';
    $cow_dir = getenv('FORKPRESS_COW_DIR') ?: dirname(rtrim($branches_dir, '/'));
    $git_repo_dir = getenv('FORKPRESS_COW_GIT_DIR') ?: rtrim($cow_dir, '/') . '/git';
    $storage_branches_dir = getenv('FORKPRESS_COW_STORAGE_BRANCHES_DIR') ?: $branches_dir;
    $branch_list = getenv('FORKPRESS_BRANCH_LIST') ?: rtrim($cow_dir, '/') . '/branches.txt';
    $file_view = getenv('FORKPRESS_COW_FILE_VIEW') ?: 'file-copy';
    $debug_log = getenv('FORKPRESS_DEBUG_LOG') ?: '';
    require_once dirname(__DIR__, 2) . '/scripts/cow/git_server.php';
    cow_git_server_handle($branches_dir, $git_repo_dir, $git_path, $query, $storage_branches_dir, $branch_list, $file_view, $debug_log);
    return true;
}

function forkpress_cow_acquire_request_lock(): bool {
    $cow_dir = getenv('FORKPRESS_COW_DIR') ?: '';
    if ($cow_dir === '') {
        return true;
    }

    $lock_path = rtrim($cow_dir, "/\\") . '/operations.lock';
    $lock_dir = dirname($lock_path);
    if (!is_dir($lock_dir) && !@mkdir($lock_dir, 0755, true) && !is_dir($lock_dir)) {
        http_response_code(503);
        echo "ForkPress COW operation lock unavailable\n";
        return false;
    }

    $handle = @fopen($lock_path, 'c');
    if (!$handle) {
        http_response_code(503);
        echo "ForkPress COW operation lock unavailable\n";
        return false;
    }

    if (!flock($handle, LOCK_SH)) {
        fclose($handle);
        http_response_code(503);
        echo "ForkPress COW operation lock unavailable\n";
        return false;
    }

    register_shutdown_function(static function() use ($handle): void {
        flock($handle, LOCK_UN);
        fclose($handle);
    });

    return true;
}

function forkpress_cow_is_admin_branch_action(string $path): bool {
    $action = $_REQUEST['action'] ?? '';
    if (!is_string($action) || !in_array($action, ['forkpress_branch_create', 'forkpress_branch_merge', 'forkpress_branch_history', 'forkpress_branch_tree', 'forkpress_branch_conflicts', 'forkpress_branch_restore_crash', 'forkpress_branch_revalidate_conflicts', 'forkpress_branch_review_conflict', 'forkpress_branch_resolve_conflict', 'forkpress_branch_apply_reviewed_conflicts', 'forkpress_branch_run_plugin_driver'], true)) {
        return false;
    }
    if ($path === '/wp-admin/admin-post.php') {
        return true;
    }
    return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
}

function forkpress_cow_branch_name_is_valid(string $branch): bool {
    if (!preg_match('/^[a-zA-Z0-9_\-]{1,63}$/', $branch)) {
        return false;
    }

    return !in_array(strtolower($branch), ['www', 'admin', 'api', 'mail', 'localhost', 'wp'], true);
}

function forkpress_cow_branch_post_value(string $key): string {
    $value = $_POST[$key] ?? $_REQUEST[$key] ?? '';
    if (is_array($value)) {
        return '';
    }
    return trim((string)$value);
}

function forkpress_cow_branch_post_int(string $key): ?int {
    $value = $_POST[$key] ?? $_REQUEST[$key] ?? '';
    if (is_array($value)) {
        return null;
    }
    $value = trim((string)$value);
    if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
        return null;
    }
    $int = (int)$value;
    return $int > 0 ? $int : null;
}

function forkpress_cow_branch_conflict_audit_filters(): array {
    $allowed = [
        'scope' => ['all', 'db', 'files', 'plugin'],
        'lifecycleState' => ['unreviewed', 'deferred', 'needs-action', 'reviewed', 'validated', 'resolved'],
        'nextAction' => ['review', 'run-plugin-validator', 'wait', 'revalidate', 'resolve', 'apply-reviewed-choice', 'manual-review', 'none'],
    ];
    $flags = [
        'scope' => '--scope',
        'lifecycleState' => '--lifecycle-state',
        'nextAction' => '--next-action',
    ];
    $labels = [
        'scope' => 'scope',
        'lifecycleState' => 'lifecycle state',
        'nextAction' => 'next action',
    ];
    $filters = [];
    foreach ($allowed as $key => $values) {
        $value = forkpress_cow_branch_post_value($key);
        if ($value === '') {
            continue;
        }
        if (!in_array($value, $values, true)) {
            return [
                'error' => 'Choose a valid merge conflict ' . $labels[$key] . '.',
                'args' => [],
                'filters' => [],
            ];
        }
        $filters[$key] = $value;
    }

    $args = [];
    foreach ($filters as $key => $value) {
        $args[] = $flags[$key];
        $args[] = $value;
    }
    return ['error' => null, 'args' => $args, 'filters' => $filters];
}

function forkpress_cow_branch_url(string $branch, string $uri = '/wp-admin/'): string {
    $root_host = getenv('FORKPRESS_ROOT_HOST') ?: 'wp.localhost';
    $current_host = $_SERVER['HTTP_HOST'] ?? '';
    $port = preg_match('/:(\d+)$/', $current_host, $m) ? ':' . $m[1] : '';
    $host = $branch === 'main' ? $root_host : $branch . '.' . $root_host;
    return 'http://' . $host . $port . $uri;
}

function forkpress_cow_branch_manager_url(string $branch): string {
    return forkpress_cow_branch_url($branch, '/_forkpress/branches');
}

function forkpress_cow_request_can_write(): bool {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    return !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
}

function forkpress_cow_branch_names(string $current_branch, array $extra_branches = []): array {
    $branch_list = getenv('FORKPRESS_BRANCH_LIST') ?: '';
    $branches = [];
    if (is_string($branch_list) && $branch_list !== '' && is_readable($branch_list)) {
        foreach (file($branch_list, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $name = trim((string)$line);
            if ($name !== '' && preg_match('/^[a-zA-Z0-9_\-]{1,63}$/', $name)) {
                $branches[] = $name;
            }
        }
    }
    $branches = array_merge($branches, $extra_branches);
    if (!$branches) {
        $branches[] = $current_branch;
    }

    $branches = array_values(array_unique($branches));
    usort($branches, static function (string $a, string $b) use ($current_branch): int {
        if ($a === $current_branch) return -1;
        if ($b === $current_branch) return 1;
        if ($a === 'main') return -1;
        if ($b === 'main') return 1;
        return strnatcasecmp($a, $b);
    });
    return $branches;
}

function forkpress_cow_branch_switcher_data(string $current_branch, string $uri = '/wp-admin/', array $extra_branches = []): array {
    return array_map(static function (string $branch) use ($current_branch, $uri): array {
        return [
            'name' => $branch,
            'url' => forkpress_cow_branch_url($branch, $uri),
            'siteUrl' => forkpress_cow_branch_url($branch, '/'),
            'adminUrl' => forkpress_cow_branch_url($branch, '/wp-admin/'),
            'managerUrl' => forkpress_cow_branch_manager_url($branch),
            'current' => $branch === $current_branch,
        ];
    }, forkpress_cow_branch_names($current_branch, $extra_branches));
}

function forkpress_cow_branch_finish_json(int $status, string $url, bool $success, string $message, array $data = []): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode(array_merge([
        'success' => $success,
        'type' => $success ? 'notice' : 'error',
        'message' => $message,
        'url' => $url,
    ], $data), JSON_UNESCAPED_SLASHES);
}

function forkpress_cow_branch_run_cli(array $args): array {
    if (!function_exists('proc_open')) {
        return [1, 'ForkPress branch actions require proc_open().'];
    }

    $bin = getenv('FORKPRESS_BIN');
    $work_dir = getenv('FORKPRESS_WORK_DIR');
    if (!is_string($bin) || $bin === '' || !is_executable($bin) || !is_string($work_dir) || $work_dir === '') {
        return [1, 'ForkPress branch actions are not available for this server.'];
    }

    $command = array_merge([$bin, 'branch', '--work-dir', $work_dir], $args);
    $shell_command = implode(' ', array_map('escapeshellarg', $command));
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($shell_command, $descriptors, $pipes);
    if (!is_resource($process)) {
        return [1, 'Failed to start the ForkPress branch command.'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return [(int)$code, trim((string)$stdout . "\n" . (string)$stderr)];
}

function forkpress_cow_branch_plugin_driver_entry(string $plugin, string $driver): ?array {
    $plugin = trim($plugin);
    $driver = trim($driver);
    if ($plugin === '' || $driver === '') {
        return null;
    }
    $real = realpath($driver);
    if (!is_string($real) || !is_file($real)) {
        return null;
    }
    if (strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'php' && !is_executable($real)) {
        return null;
    }
    $key = hash('sha256', $plugin . "\0" . $real);
    return [
        'key' => $key,
        'plugin' => $plugin,
        'driver' => $real,
        'label' => basename($real),
    ];
}

function forkpress_cow_branch_plugin_driver_add(array &$drivers, string $plugin, string $driver): void {
    $entry = forkpress_cow_branch_plugin_driver_entry($plugin, $driver);
    if ($entry === null) {
        return;
    }
    $drivers[$entry['key']] = $entry;
}

function forkpress_cow_branch_configured_plugin_driver_map(): array {
    $raw = getenv('FORKPRESS_PLUGIN_MERGE_DRIVERS');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $drivers = [];
    if (array_is_list($decoded)) {
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            forkpress_cow_branch_plugin_driver_add($drivers, (string)($entry['plugin'] ?? ''), (string)($entry['driver'] ?? $entry['path'] ?? ''));
        }
    } else {
        foreach ($decoded as $plugin => $driver) {
            if (is_array($driver)) {
                forkpress_cow_branch_plugin_driver_add($drivers, (string)$plugin, (string)($driver['driver'] ?? $driver['path'] ?? ''));
            } else {
                forkpress_cow_branch_plugin_driver_add($drivers, (string)$plugin, (string)$driver);
            }
        }
    }
    return $drivers;
}

function forkpress_cow_branch_discovered_plugin_driver_map(string $branch_root): array {
    $drivers = [];
    $plugins_dir = rtrim($branch_root, "/\\") . '/wp-content/plugins';
    if (is_dir($plugins_dir)) {
        foreach ([$plugins_dir . '/*.forkpress-merge-driver.php', $plugins_dir . '/*/forkpress-merge-driver.php'] as $pattern) {
            $matches = glob($pattern);
            if (!is_array($matches)) {
                continue;
            }
            sort($matches, SORT_STRING);
            foreach ($matches as $match) {
                if (!is_file($match)) {
                    continue;
                }
                $plugin = basename(dirname($match));
                if ($plugin === 'plugins') {
                    $plugin = basename($match, '.forkpress-merge-driver.php');
                }
                forkpress_cow_branch_plugin_driver_add($drivers, $plugin, $match);
            }
        }
    }

    $mu_dir = rtrim($branch_root, "/\\") . '/wp-content/mu-plugins';
    if (is_dir($mu_dir)) {
        forkpress_cow_branch_plugin_driver_add($drivers, 'mu-plugins', $mu_dir . '/forkpress-merge-driver.php');
        foreach ([$mu_dir . '/*.forkpress-merge-driver.php', $mu_dir . '/*/forkpress-merge-driver.php'] as $pattern) {
            $matches = glob($pattern);
            if (!is_array($matches)) {
                continue;
            }
            sort($matches, SORT_STRING);
            foreach ($matches as $match) {
                if (!is_file($match)) {
                    continue;
                }
                $plugin = basename(dirname($match));
                if ($plugin === 'mu-plugins') {
                    $plugin = basename($match, '.forkpress-merge-driver.php');
                }
                forkpress_cow_branch_plugin_driver_add($drivers, $plugin, $match);
            }
        }
    }

    return $drivers;
}

function forkpress_cow_branch_plugin_driver_map(string $branches_dir, string $current_branch): array {
    $branch_root = rtrim($branches_dir, "/\\") . '/' . $current_branch;
    return forkpress_cow_branch_configured_plugin_driver_map() + forkpress_cow_branch_discovered_plugin_driver_map($branch_root);
}

function forkpress_cow_branch_merge_summary(string $output): array {
    $summary = [
        'run' => null,
        'status' => null,
        'conflicts' => null,
    ];

    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        if (preg_match('/^\s*(run|status|conflicts):\s*(.+?)\s*$/', (string)$line, $matches) !== 1) {
            continue;
        }
        if ($matches[1] === 'conflicts') {
            $summary['conflicts'] = max(0, (int)$matches[2]);
        } elseif ($matches[1] === 'run') {
            $summary['run'] = max(0, (int)$matches[2]);
        } else {
            $summary['status'] = (string)$matches[2];
        }
    }

    return $summary;
}

function forkpress_cow_branch_merge_audit_command(?int $run, array $filters = []): string {
    $command = 'forkpress branch merge-audit --records conflicts';
    if ($run !== null && $run > 0) {
        $command .= ' --run ' . $run;
    }
    $filter_flags = [
        'scope' => '--scope',
        'lifecycleState' => '--lifecycle-state',
        'nextAction' => '--next-action',
    ];
    foreach ($filter_flags as $key => $flag) {
        if (isset($filters[$key]) && is_string($filters[$key]) && $filters[$key] !== '') {
            $command .= ' ' . $flag . ' ' . $filters[$key];
        }
    }
    return $command;
}

function forkpress_cow_branch_crash_recovery_audit_command(?int $run): string {
    $command = 'forkpress branch merge-audit --records crash-recovery';
    if ($run !== null && $run > 0) {
        $command .= ' --run ' . $run;
    }
    return $command;
}

function forkpress_cow_branch_crash_recovery_command(?int $run, array $artifacts = []): string {
    $command = 'forkpress branch recover-crash';
    if ($run !== null && $run > 0) {
        $command .= ' --run ' . $run;
    }
    $restore_db = false;
    $restore_files = false;
    foreach ($artifacts as $artifact) {
        if (!is_array($artifact)) {
            continue;
        }
        if (is_array($artifact['target_db_snapshot'] ?? null)) {
            $restore_db = true;
        }
        if (is_array($artifact['filesystem_transaction'] ?? null) || is_array($artifact['filesystem_snapshot'] ?? null)) {
            $restore_files = true;
        }
    }
    if ($restore_db) {
        $command .= ' --restore-target-db';
    }
    if ($restore_files) {
        $command .= ' --restore-files';
    }
    return $command;
}

function forkpress_cow_branch_crash_recovery_summary(array $report, int $run): array {
    $records = is_array($report['crash_recovery'] ?? null) ? array_values($report['crash_recovery']) : [];
    return [
        'run' => $run,
        'crashRecovery' => $records,
        'crashRecoveryCount' => count($records),
        'audit' => $report,
        'auditCommand' => forkpress_cow_branch_crash_recovery_audit_command($run) . ' --format json',
        'recoveryCommand' => forkpress_cow_branch_crash_recovery_command($run, $records),
    ];
}

function forkpress_cow_branch_history_summary(array $report, int $limit): array {
    $records = is_array($report['runs'] ?? null) ? array_values($report['runs']) : [];
    return [
        'records' => $records,
        'recordCount' => count($records),
        'limit' => $limit,
        'historyCommand' => 'forkpress branch history --limit ' . $limit . ' --format json',
        'audit' => $report,
    ];
}

function forkpress_cow_branch_tree_summary(array $report, int $limit): array {
    $records = is_array($report['runs'] ?? null) ? array_values($report['runs']) : [];
    return [
        'records' => $records,
        'recordCount' => count($records),
        'limit' => $limit,
        'treeCommand' => 'forkpress branch tree --limit ' . $limit . ' --format json',
        'audit' => $report,
    ];
}

function forkpress_cow_branch_conflict_audit_summary(array $report, int $run, array $filters = []): array {
    $records = is_array($report['conflicts'] ?? null) ? array_values($report['conflicts']) : [];
    $total = count($records);
    $runs = is_array($report['runs'] ?? null) ? $report['runs'] : [];
    foreach ($runs as $run_record) {
        if (!is_array($run_record) || (int)($run_record['id'] ?? 0) !== $run) {
            continue;
        }
        $total = max($total, (int)($run_record['conflict_count'] ?? 0));
        break;
    }

    $conflict_summary = is_array($report['conflict_summary'] ?? null) ? $report['conflict_summary'] : null;
    if ($conflict_summary === null) {
        $conflict_summary = [
            'total' => count($records),
            'resolved' => 0,
            'unresolved' => 0,
            'by_lifecycle' => [],
            'by_next_action' => [],
            'by_scope' => [],
        ];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $lifecycle = trim((string)($record['lifecycle_state'] ?? $record['latest_event_lifecycle_state'] ?? 'unreviewed'));
            if ($lifecycle === '') {
                $lifecycle = 'unreviewed';
            }
            $next_action = trim((string)($record['next_action'] ?? 'review'));
            if ($next_action === '') {
                $next_action = 'review';
            }
            $scope = 'db';
            $table = (string)($record['table_name'] ?? '');
            if ($table === '__files__' || isset($record['path'])) {
                $scope = 'files';
            } elseif (isset($record['plugin']) || isset($record['plugin_object'])) {
                $scope = 'plugin';
            }
            $conflict_summary['by_lifecycle'][$lifecycle] = (int)($conflict_summary['by_lifecycle'][$lifecycle] ?? 0) + 1;
            $conflict_summary['by_next_action'][$next_action] = (int)($conflict_summary['by_next_action'][$next_action] ?? 0) + 1;
            $conflict_summary['by_scope'][$scope] = (int)($conflict_summary['by_scope'][$scope] ?? 0) + 1;
            if ($lifecycle === 'resolved') {
                $conflict_summary['resolved']++;
            } else {
                $conflict_summary['unresolved']++;
            }
        }
    }

    return [
        'run' => $run,
        'records' => $records,
        'recordCount' => count($records),
        'totalConflicts' => $total,
        'conflictSummary' => $conflict_summary,
        'filters' => $filters,
        'audit' => $report,
        'auditCommand' => forkpress_cow_branch_merge_audit_command($run, $filters) . ' --format json',
    ];
}

function forkpress_cow_branch_revalidate_merge_run(int $run): array {
    [$code, $output] = forkpress_cow_branch_run_cli(['merge-audit', '--revalidate', '--run', (string)$run, '--reviewer', 'wordpress-ui', '--format', 'json']);
    if ($code !== 0) {
        return [$code, $output, null];
    }

    $result = json_decode($output, true);
    if (!is_array($result)) {
        return [1, 'ForkPress returned invalid revalidation JSON.', null];
    }

    return [0, $output, $result];
}

function forkpress_cow_branch_revalidation_needs_action_for_conflict(array $result, int $conflict): bool {
    foreach (($result['needs_action_conflicts'] ?? []) as $record) {
        if (is_array($record) && (int)($record['conflict_id'] ?? 0) === $conflict) {
            return true;
        }
    }
    return false;
}

function forkpress_cow_handle_admin_branch_action(string $path, string $current_branch, string $branches_dir): bool {
    if (!forkpress_cow_is_admin_branch_action($path)) {
        return false;
    }

    $action = $_REQUEST['action'] ?? '';
    $current_url = forkpress_cow_branch_url($current_branch, '/wp-admin/');
    $branches = forkpress_cow_branch_names($current_branch);
    if ($action === 'forkpress_branch_create') {
        $branch = forkpress_cow_branch_post_value('branch');
        $from = forkpress_cow_branch_post_value('from') ?: 'main';
        if (!forkpress_cow_branch_name_is_valid($branch)) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Branch names can use letters, numbers, hyphens, and underscores.');
            return true;
        }
        if (!in_array($from, $branches, true)) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Choose an existing source branch.');
            return true;
        }

        [$code, $output] = forkpress_cow_branch_run_cli(['create', $branch, '--from', $from]);
        if ($code !== 0) {
            forkpress_cow_branch_finish_json(400, $current_url, false, $output ?: 'ForkPress could not create the branch.');
            return true;
        }
        forkpress_cow_branch_finish_json(
            200,
            forkpress_cow_branch_url($branch, '/wp-admin/'),
            true,
            'Created branch ' . $branch . '.',
            ['branches' => forkpress_cow_branch_switcher_data($branch, '/wp-admin/', [$branch, 'main'])]
        );
        return true;
    }

    if ($action === 'forkpress_branch_merge') {
        $source = forkpress_cow_branch_post_value('source');
        $target = forkpress_cow_branch_post_value('target') ?: 'main';
        if (!in_array($source, $branches, true) || !in_array($target, $branches, true)) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Choose existing source and target branches.');
            return true;
        }
        if ($source === $target) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Choose two different branches to merge.');
            return true;
        }

        [$code, $output] = forkpress_cow_branch_run_cli(['merge', $source, '--into', $target]);
        if ($code !== 0) {
            forkpress_cow_branch_finish_json(400, $current_url, false, $output ?: 'ForkPress could not merge the branch.');
            return true;
        }
        $summary = forkpress_cow_branch_merge_summary($output);
        $run = is_int($summary['run']) && $summary['run'] > 0 ? $summary['run'] : null;
        $conflicts = is_int($summary['conflicts']) ? $summary['conflicts'] : 0;
        if (($summary['status'] ?? null) === 'completed_with_conflicts' || $conflicts > 0) {
            $audit_command = forkpress_cow_branch_merge_audit_command($run);
            $message = 'Merged ' . $source . ' into ' . $target . ' with ' . $conflicts . ' conflict' . ($conflicts === 1 ? '' : 's') . '. Review them with `' . $audit_command . '`.';
            forkpress_cow_branch_finish_json(
                200,
                forkpress_cow_branch_url($target, '/wp-admin/'),
                true,
                $message,
                [
                    'type' => 'warning',
                    'branches' => forkpress_cow_branch_switcher_data($target, '/wp-admin/'),
                    'mergeStatus' => $summary['status'],
                    'conflicts' => $conflicts,
                    'run' => $run,
                    'auditCommand' => $audit_command,
                ]
            );
            return true;
        }
        forkpress_cow_branch_finish_json(
            200,
            forkpress_cow_branch_url($target, '/wp-admin/'),
            true,
            'Merged ' . $source . ' into ' . $target . '.',
            ['branches' => forkpress_cow_branch_switcher_data($target, '/wp-admin/')]
        );
        return true;
    }

    if ($action === 'forkpress_branch_history') {
        $limit = forkpress_cow_branch_post_int('limit') ?? 10;
        if ($limit < 1 || $limit > 50) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Choose a merge history limit from 1 to 50.');
            return true;
        }

        [$code, $output] = forkpress_cow_branch_run_cli(['history', '--limit', (string)$limit, '--format', 'json']);
        if ($code !== 0) {
            forkpress_cow_branch_finish_json(400, $current_url, false, $output ?: 'ForkPress could not inspect merge history.');
            return true;
        }
        $report = json_decode($output, true);
        if (!is_array($report)) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'ForkPress returned invalid merge history JSON.');
            return true;
        }

        $summary = forkpress_cow_branch_history_summary($report, $limit);
        $count = (int)($summary['recordCount'] ?? 0);
        forkpress_cow_branch_finish_json(
            200,
            $current_url,
            true,
            $count > 0 ? 'Loaded ' . $count . ' merge history ' . ($count === 1 ? 'run.' : 'runs.') : 'No merge history found.',
            $summary
        );
        return true;
    }

    if ($action === 'forkpress_branch_tree') {
        $limit = forkpress_cow_branch_post_int('limit') ?? 20;
        if ($limit < 1 || $limit > 50) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Choose a branch tree limit from 1 to 50.');
            return true;
        }

        [$code, $output] = forkpress_cow_branch_run_cli(['tree', '--limit', (string)$limit, '--format', 'json']);
        if ($code !== 0) {
            forkpress_cow_branch_finish_json(400, $current_url, false, $output ?: 'ForkPress could not inspect the branch tree.');
            return true;
        }
        $report = json_decode($output, true);
        if (!is_array($report)) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'ForkPress returned invalid branch tree JSON.');
            return true;
        }

        $summary = forkpress_cow_branch_tree_summary($report, $limit);
        $count = (int)($summary['recordCount'] ?? 0);
        forkpress_cow_branch_finish_json(
            200,
            $current_url,
            true,
            $count > 0 ? 'Loaded ' . $count . ' branch tree ' . ($count === 1 ? 'edge.' : 'edges.') : 'No branch tree edges found.',
            $summary
        );
        return true;
    }

    if ($action === 'forkpress_branch_conflicts') {
        $run = forkpress_cow_branch_post_int('run');
        if ($run === null) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Choose a merge run to inspect.');
            return true;
        }

        $filters = forkpress_cow_branch_conflict_audit_filters();
        if (($filters['error'] ?? null) !== null) {
            forkpress_cow_branch_finish_json(400, $current_url, false, (string)$filters['error']);
            return true;
        }

        [$crash_code, $crash_output] = forkpress_cow_branch_run_cli(['merge-audit', '--records', 'crash-recovery', '--run', (string)$run, '--format', 'json']);
        if ($crash_code !== 0) {
            forkpress_cow_branch_finish_json(400, $current_url, false, $crash_output ?: 'ForkPress could not inspect pending crash recovery.');
            return true;
        }
        $crash_report = json_decode($crash_output, true);
        if (!is_array($crash_report)) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'ForkPress returned invalid crash recovery JSON.');
            return true;
        }
        $crash_summary = forkpress_cow_branch_crash_recovery_summary($crash_report, $run);
        if (($crash_summary['crashRecoveryCount'] ?? 0) > 0) {
            $message = 'Merge run ' . $run . ' has pending crash recovery. Restore it before reviewing conflicts.';
            forkpress_cow_branch_finish_json(200, $current_url, true, $message, array_merge(['type' => 'warning'], $crash_summary));
            return true;
        }

        $audit_args = array_merge(['merge-audit', '--records', 'conflicts', '--run', (string)$run, '--format', 'json'], $filters['args']);
        [$code, $output] = forkpress_cow_branch_run_cli($audit_args);
        if ($code !== 0) {
            forkpress_cow_branch_finish_json(400, $current_url, false, $output ?: 'ForkPress could not inspect merge conflicts.');
            return true;
        }
        $report = json_decode($output, true);
        if (!is_array($report)) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'ForkPress returned invalid merge audit JSON.');
            return true;
        }

        $summary = forkpress_cow_branch_conflict_audit_summary($report, $run, $filters['filters']);
        $message = 'Loaded ' . $summary['recordCount'] . ' of ' . $summary['totalConflicts'] . ' conflict record' . ($summary['totalConflicts'] === 1 ? '' : 's') . ' for merge run ' . $run . '.';
        forkpress_cow_branch_finish_json(200, $current_url, true, $message, $summary);
        return true;
    }

    if ($action === 'forkpress_branch_restore_crash') {
        $run = forkpress_cow_branch_post_int('run');
        if ($run === null) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Choose a merge run to restore.');
            return true;
        }

        [$code, $output] = forkpress_cow_branch_run_cli(['recover-crash', '--run', (string)$run, '--restore-target-db', '--restore-files', '--format', 'json']);
        if ($code !== 0) {
            forkpress_cow_branch_finish_json(400, $current_url, false, $output ?: 'ForkPress could not restore pending crash recovery.');
            return true;
        }
        $result = json_decode($output, true);
        if (!is_array($result)) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'ForkPress returned invalid crash recovery restore JSON.');
            return true;
        }

        $restored = max(0, (int)($result['restored'] ?? 0));
        $pending = max(0, (int)($result['pending'] ?? 0));
        $message = $restored > 0
            ? 'Restored pending crash recovery for merge run ' . $run . '.'
            : 'No pending crash recovery artifacts were restored for merge run ' . $run . '.';
        forkpress_cow_branch_finish_json(
            200,
            $current_url,
            true,
            $message,
            [
                'type' => $pending > 0 ? 'warning' : 'notice',
                'run' => $run,
                'restored' => $restored,
                'pending' => $pending,
                'recovery' => $result,
                'recoveryCommand' => 'forkpress branch recover-crash --run ' . $run . ' --restore-target-db --restore-files',
            ]
        );
        return true;
    }

    if ($action === 'forkpress_branch_revalidate_conflicts') {
        $run = forkpress_cow_branch_post_int('run');
        if ($run === null) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Choose a merge run to revalidate.');
            return true;
        }

        [$code, $output, $result] = forkpress_cow_branch_revalidate_merge_run($run);
        if ($code !== 0) {
            forkpress_cow_branch_finish_json(400, $current_url, false, $output ?: 'ForkPress could not revalidate merge conflicts.');
            return true;
        }

        $checked = max(0, (int)($result['checked'] ?? 0));
        $stale = max(0, (int)($result['stale'] ?? 0));
        $carried = max(0, (int)($result['carried'] ?? 0));
        $message = 'Revalidated merge run ' . $run . ': checked ' . $checked . ', stale ' . $stale . ', carried ' . $carried . '.';
        forkpress_cow_branch_finish_json(
            200,
            $current_url,
            true,
            $message,
            [
                'type' => 'warning',
                'run' => $run,
                'checked' => $checked,
                'stale' => $stale,
                'carried' => $carried,
                'revalidation' => $result,
                'auditCommand' => 'forkpress branch merge-audit --revalidate --run ' . $run . ' --reviewer wordpress-ui --format json',
            ]
        );
        return true;
    }

    if ($action === 'forkpress_branch_review_conflict') {
        $conflict = forkpress_cow_branch_post_int('conflict');
        if ($conflict === null) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Choose a merge conflict to review.');
            return true;
        }

        $status = forkpress_cow_branch_post_value('status');
        if (!in_array($status, ['pending', 'needs-action', 'reviewed'], true)) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Choose pending, needs-action, or reviewed for the conflict review status.');
            return true;
        }

        $notes = [
            'pending' => 'Marked pending from the WordPress branch switcher.',
            'needs-action' => 'Marked needs-action from the WordPress branch switcher.',
            'reviewed' => 'Marked reviewed from the WordPress branch switcher.',
        ];
        [$code, $output] = forkpress_cow_branch_run_cli([
            'merge-review',
            'conflict',
            (string)$conflict,
            '--status',
            $status,
            '--note',
            $notes[$status],
            '--reviewer',
            'wordpress-ui',
        ]);
        if ($code !== 0) {
            forkpress_cow_branch_finish_json(400, $current_url, false, $output ?: 'ForkPress could not record the conflict review.');
            return true;
        }

        $run = forkpress_cow_branch_post_int('run');
        forkpress_cow_branch_finish_json(
            200,
            $current_url,
            true,
            'Marked conflict #' . $conflict . ' ' . $status . '.',
            [
                'run' => $run,
                'conflict' => $conflict,
                'reviewStatus' => $status,
            ]
        );
        return true;
    }

    if ($action === 'forkpress_branch_resolve_conflict') {
        $conflict = forkpress_cow_branch_post_int('conflict');
        if ($conflict === null) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Choose a merge conflict to resolve.');
            return true;
        }

        $apply_reviewed = forkpress_cow_branch_post_value('applyReviewed') === '1';
        $after_revalidate = forkpress_cow_branch_post_value('afterRevalidate') === '1';
        $choice = forkpress_cow_branch_post_value('choice');
        if ($apply_reviewed && $choice !== '') {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Apply reviewed cannot be combined with a new source or target choice.');
            return true;
        }
        if ($apply_reviewed && $after_revalidate) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'After revalidate requires a source or target choice.');
            return true;
        }
        if (!$apply_reviewed && !in_array($choice, ['source', 'target'], true)) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Choose source or target for the conflict resolution.');
            return true;
        }

        $run = forkpress_cow_branch_post_int('run');
        if ($apply_reviewed && $run !== null) {
            [$code, $output, $revalidation] = forkpress_cow_branch_revalidate_merge_run($run);
            if ($code !== 0) {
                forkpress_cow_branch_finish_json(400, $current_url, false, $output ?: 'ForkPress could not revalidate merge conflicts before applying the reviewed choice.');
                return true;
            }
            if ($revalidation !== null && forkpress_cow_branch_revalidation_needs_action_for_conflict($revalidation, $conflict)) {
                $checked = max(0, (int)($revalidation['checked'] ?? 0));
                $stale = max(0, (int)($revalidation['stale'] ?? 0));
                $carried = max(0, (int)($revalidation['carried'] ?? 0));
                forkpress_cow_branch_finish_json(
                    400,
                    $current_url,
                    false,
                    'Conflict #' . $conflict . ' changed since review. Revalidate and review it before applying the reviewed choice.',
                    [
                        'run' => $run,
                        'conflict' => $conflict,
                        'checked' => $checked,
                        'stale' => $stale,
                        'carried' => $carried,
                        'revalidation' => $revalidation,
                        'auditCommand' => 'forkpress branch merge-audit --revalidate --run ' . $run . ' --reviewer wordpress-ui --format json',
                    ]
                );
                return true;
            }
        }

        $notes = [
            'source' => 'Applied source choice from the WordPress branch switcher.',
            'target' => 'Applied target choice from the WordPress branch switcher.',
            'reviewed' => 'Applied reviewed choice from the WordPress branch switcher.',
        ];
        $resolve_args = [
            'merge-resolve',
            'conflict',
            (string)$conflict,
        ];
        if ($apply_reviewed) {
            $resolve_args[] = '--apply-reviewed';
            $note = $notes['reviewed'];
        } else {
            $resolve_args[] = '--choice';
            $resolve_args[] = $choice;
            $resolve_args[] = '--apply';
            if ($after_revalidate) {
                $resolve_args[] = '--after-revalidate';
            }
            $note = $notes[$choice];
        }
        $resolve_args[] = '--note';
        $resolve_args[] = $note;
        $resolve_args[] = '--reviewer';
        $resolve_args[] = 'wordpress-ui';
        [$code, $output] = forkpress_cow_branch_run_cli($resolve_args);
        if ($code !== 0) {
            forkpress_cow_branch_finish_json(400, $current_url, false, $output ?: 'ForkPress could not apply the conflict resolution.');
            return true;
        }

        forkpress_cow_branch_finish_json(
            200,
            $current_url,
            true,
            $apply_reviewed ? 'Applied reviewed choice for conflict #' . $conflict . '.' : 'Applied ' . $choice . ' for conflict #' . $conflict . '.',
            [
                'run' => $run,
                'conflict' => $conflict,
                'resolutionChoice' => $apply_reviewed ? 'reviewed' : $choice,
                'afterRevalidate' => $after_revalidate,
            ]
        );
        return true;
    }

    if ($action === 'forkpress_branch_apply_reviewed_conflicts') {
        $run = forkpress_cow_branch_post_int('run');
        if ($run === null) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Choose a merge run to apply reviewed resolutions.');
            return true;
        }

        [$code, $output] = forkpress_cow_branch_run_cli([
            'merge-apply-reviewed',
            '--run',
            (string)$run,
            '--reviewer',
            'wordpress-ui',
            '--format',
            'json',
        ]);
        if ($code !== 0) {
            forkpress_cow_branch_finish_json(400, $current_url, false, $output ?: 'ForkPress could not apply reviewed merge resolutions.');
            return true;
        }
        $result = json_decode($output, true);
        if (!is_array($result)) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'ForkPress returned invalid reviewed-resolution JSON.');
            return true;
        }

        $applied = max(0, (int)($result['applied'] ?? 0));
        $eligible = max(0, (int)($result['eligible'] ?? 0));
        $message = $applied > 0
            ? 'Applied ' . $applied . ' reviewed merge resolution' . ($applied === 1 ? '' : 's') . ' for run ' . $run . '.'
            : 'No reviewed merge resolutions were ready to apply for run ' . $run . '.';
        forkpress_cow_branch_finish_json(
            200,
            $current_url,
            true,
            $message,
            [
                'run' => $run,
                'applied' => $applied,
                'eligible' => $eligible,
                'applyReviewed' => $result,
                'applyReviewedCommand' => 'forkpress branch merge-apply-reviewed --run ' . $run . ' --reviewer wordpress-ui --format json',
            ]
        );
        return true;
    }

    if ($action === 'forkpress_branch_run_plugin_driver') {
        $conflict = forkpress_cow_branch_post_int('conflict');
        if ($conflict === null) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Choose a plugin conflict to repair.');
            return true;
        }

        $drivers = forkpress_cow_branch_plugin_driver_map($branches_dir, $current_branch);
        if (!$drivers) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'No approved plugin merge drivers are configured.');
            return true;
        }

        $driver_key = forkpress_cow_branch_post_value('driverKey');
        if ($driver_key === '' || !isset($drivers[$driver_key])) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Choose an approved plugin merge driver.');
            return true;
        }

        $driver = $drivers[$driver_key];
        [$code, $output] = forkpress_cow_branch_run_cli([
            'run-plugin-driver',
            'conflict',
            (string)$conflict,
            '--driver',
            (string)$driver['driver'],
            '--reviewer',
            'wordpress-ui',
            '--format',
            'json',
        ]);
        if ($code !== 0) {
            forkpress_cow_branch_finish_json(400, $current_url, false, $output ?: 'ForkPress could not run the plugin merge driver.');
            return true;
        }
        $result = json_decode($output, true);
        if (!is_array($result)) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'ForkPress returned invalid plugin driver JSON.');
            return true;
        }

        $driver_status = trim((string)($result['driver_status'] ?? $result['status'] ?? 'completed'));
        if ($driver_status === '') {
            $driver_status = 'completed';
        }
        $run = forkpress_cow_branch_post_int('run');
        forkpress_cow_branch_finish_json(
            200,
            $current_url,
            true,
            'Ran plugin driver for conflict #' . $conflict . ': ' . $driver_status . '.',
            [
                'run' => $run,
                'conflict' => $conflict,
                'driverStatus' => $driver_status,
                'driverPlugin' => (string)$driver['plugin'],
                'result' => $result,
            ]
        );
        return true;
    }

    return false;
}

function forkpress_cow_json_encode($value): string {
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : 'null';
}

function forkpress_cow_branch_manager_html(string $current_branch): string {
    return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ForkPress Branches</title>
<style>
    :root {
        color-scheme: light;
        --bg: #f6f7f7;
        --panel: #ffffff;
        --ink: #1d2327;
        --muted: #646970;
        --line: #dcdcde;
        --accent: #2271b1;
        --accent-strong: #135e96;
        --conflict: #b35c00;
        --danger: #b32d2e;
        --ok: #008a20;
    }
    * { box-sizing: border-box; }
    body {
        background: var(--bg);
        color: var(--ink);
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        margin: 0;
    }
    .fp-shell {
        display: grid;
        grid-template-rows: auto 1fr;
        min-height: 100vh;
    }
    .fp-topbar {
        align-items: center;
        background: #1d2327;
        color: #fff;
        display: flex;
        gap: 18px;
        min-height: 54px;
        padding: 0 20px;
    }
    .fp-brand { font-size: 15px; font-weight: 700; }
    .fp-current {
        background: #2c3338;
        border: 1px solid #50575e;
        border-radius: 999px;
        color: #f0f0f1;
        font-size: 12px;
        padding: 5px 10px;
    }
    .fp-open-admin {
        color: #9ecbff;
        font-size: 13px;
        margin-left: auto;
        text-decoration: none;
    }
    .fp-layout {
        display: grid;
        gap: 18px;
        grid-template-columns: minmax(0, 1fr) 360px;
        padding: 18px;
    }
    .fp-panel {
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 8px;
        min-width: 0;
    }
    .fp-graph-head, .fp-detail-head {
        align-items: center;
        border-bottom: 1px solid var(--line);
        display: flex;
        gap: 10px;
        justify-content: space-between;
        padding: 14px 16px;
    }
    h1, h2 { font-size: 16px; line-height: 1.25; margin: 0; }
    .fp-muted { color: var(--muted); font-size: 12px; }
    .fp-graph-wrap {
        min-height: 520px;
        overflow: auto;
        padding: 12px;
    }
    .fp-graph {
        display: block;
        min-height: 440px;
        min-width: 760px;
    }
    .fp-lane-label { fill: #50575e; font-size: 12px; font-weight: 650; }
    .fp-lane-line { stroke: #dcdcde; stroke-width: 2; }
    .fp-lane-stem { stroke: #c3c4c7; stroke-width: 3; }
    .fp-lane-head { cursor: pointer; fill: #fff; stroke: #8c8f94; stroke-width: 2; }
    .fp-row-line { stroke: #f0f0f1; stroke-width: 1; }
    .fp-row-label { fill: #1d2327; font-size: 11px; font-weight: 700; }
    .fp-row-status { fill: #646970; font-size: 10px; }
    .fp-cross-point { fill: #fff; stroke: #c3c4c7; stroke-width: 1.5; }
    .fp-edge { fill: none; stroke-width: 3; }
    .fp-edge.is-conflict { stroke: var(--conflict); stroke-dasharray: 7 5; }
    .fp-edge.is-resolved { stroke: var(--ok); }
    .fp-node { cursor: pointer; stroke: #fff; stroke-width: 3; }
    .fp-node.is-current { stroke: #1d2327; stroke-width: 4; }
    .fp-node.is-conflict { fill: var(--conflict); }
    .fp-node.is-resolved { fill: var(--ok); }
    .fp-node.is-setup { fill: #f6f7f7; stroke: #8c8f94; }
    .fp-node.is-failed { fill: var(--danger); }
    .fp-merge-dot { fill: #fff; stroke-width: 3; }
    .fp-node-label { fill: #1d2327; font-size: 11px; font-weight: 700; pointer-events: none; }
    .fp-conflict-badge { fill: var(--danger); }
    .fp-conflict-badge.is-resolved { fill: var(--ok); }
    .fp-badge-text { fill: #fff; font-size: 10px; font-weight: 700; pointer-events: none; }
    .fp-side {
        display: grid;
        gap: 18px;
        min-width: 0;
    }
    .fp-detail-body, .fp-actions-body {
        display: grid;
        gap: 12px;
        padding: 14px 16px;
    }
    .fp-kv {
        display: grid;
        gap: 8px;
    }
    .fp-kv div {
        border-bottom: 1px solid #f0f0f1;
        display: grid;
        gap: 4px;
        padding-bottom: 8px;
    }
    .fp-kv span:first-child {
        color: var(--muted);
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
    }
    .fp-kv span:last-child {
        font-size: 13px;
        overflow-wrap: anywhere;
    }
    .fp-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    button, .fp-button {
        align-items: center;
        background: #fff;
        border: 1px solid #8c8f94;
        border-radius: 6px;
        color: var(--ink);
        cursor: pointer;
        display: inline-flex;
        font-size: 13px;
        font-weight: 600;
        min-height: 32px;
        padding: 6px 10px;
        text-decoration: none;
    }
    button:hover, .fp-button:hover { border-color: var(--accent); color: var(--accent-strong); }
    button.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
    button.primary:hover { background: var(--accent-strong); color: #fff; }
    button.danger { border-color: var(--danger); color: var(--danger); }
    button:disabled { cursor: progress; opacity: .68; }
    .fp-status {
        border-radius: 6px;
        display: none;
        font-size: 13px;
        padding: 10px 12px;
    }
    .fp-status.is-visible { display: block; }
    .fp-status.ok { background: #edfaef; color: #005c12; }
    .fp-status.warn { background: #fff4e5; color: #713f00; }
    .fp-status.error { background: #fcf0f1; color: #8a2424; }
    details.fp-actions summary {
        cursor: pointer;
        font-size: 13px;
        font-weight: 700;
        padding: 14px 16px;
    }
    .fp-form {
        display: grid;
        gap: 10px;
    }
    .fp-field {
        display: grid;
        gap: 4px;
    }
    .fp-field label {
        color: var(--muted);
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
    }
    input, select {
        border: 1px solid #8c8f94;
        border-radius: 6px;
        font-size: 13px;
        height: 34px;
        padding: 5px 8px;
        width: 100%;
    }
    .fp-conflicts {
        display: grid;
        gap: 10px;
    }
    .fp-conflict {
        border: 1px solid var(--line);
        border-left: 4px solid var(--conflict);
        border-radius: 6px;
        display: grid;
        gap: 8px;
        padding: 10px;
    }
    .fp-conflict-title { font-size: 13px; font-weight: 700; overflow-wrap: anywhere; }
    .fp-conflict-meta { color: var(--muted); font-size: 12px; overflow-wrap: anywhere; }
    pre {
        background: #f6f7f7;
        border: 1px solid var(--line);
        border-radius: 6px;
        font-size: 12px;
        max-height: 220px;
        overflow: auto;
        padding: 10px;
        white-space: pre-wrap;
    }
    @media (max-width: 980px) {
        .fp-layout { grid-template-columns: 1fr; padding: 12px; }
        .fp-topbar { flex-wrap: wrap; padding: 10px 14px; }
        .fp-open-admin { margin-left: 0; }
    }
</style>
</head>
<body>
<div class="fp-shell">
    <header class="fp-topbar">
        <div class="fp-brand">ForkPress Branches</div>
        <div class="fp-current" id="fp-current"></div>
        <a class="fp-open-admin" id="fp-admin-link" href="#">Open WordPress admin</a>
    </header>
    <main class="fp-layout">
        <section class="fp-panel">
            <div class="fp-graph-head">
                <div>
                    <h1>Branch Graph</h1>
                    <div class="fp-muted" id="fp-graph-summary"></div>
                </div>
                <div class="fp-buttons">
                    <button type="button" id="fp-refresh">Refresh</button>
                    <button type="button" id="fp-load-history">History</button>
                </div>
            </div>
            <div class="fp-graph-wrap">
                <svg class="fp-graph" id="fp-graph" role="img" aria-label="ForkPress branch graph"></svg>
            </div>
        </section>
        <aside class="fp-side">
            <section class="fp-panel">
                <div class="fp-detail-head">
                    <h2 id="fp-detail-title">Selection</h2>
                </div>
                <div class="fp-detail-body">
                    <div class="fp-status" id="fp-status"></div>
                    <div class="fp-kv" id="fp-detail"></div>
                    <div class="fp-buttons" id="fp-detail-actions"></div>
                    <div class="fp-conflicts" id="fp-conflicts"></div>
                    <pre id="fp-raw"></pre>
                </div>
            </section>
            <section class="fp-panel">
                <details class="fp-actions">
                    <summary>Branch actions</summary>
                    <div class="fp-actions-body">
                        <form class="fp-form" id="fp-create">
                            <div class="fp-field"><label for="fp-create-name">New branch</label><input id="fp-create-name" name="branch" pattern="[A-Za-z0-9_-]{1,63}" autocomplete="off" required></div>
                            <div class="fp-field"><label for="fp-create-from">From</label><select id="fp-create-from" name="from"></select></div>
                            <button class="primary" type="submit">Create branch</button>
                        </form>
                        <form class="fp-form" id="fp-merge">
                            <div class="fp-field"><label for="fp-merge-source">Source</label><select id="fp-merge-source" name="source"></select></div>
                            <div class="fp-field"><label for="fp-merge-target">Target</label><select id="fp-merge-target" name="target"></select></div>
                            <button type="submit">Merge branch</button>
                        </form>
                    </div>
                </details>
            </section>
        </aside>
    </main>
</div>
<script>
(function () {
    var state = __STATE__;
    var colors = ['#2271b1', '#008a20', '#8a2be2', '#b35c00', '#d63638', '#007cba', '#7a5cff', '#3858e9'];
    var graph = document.getElementById('fp-graph');
    var summary = document.getElementById('fp-graph-summary');
    var detail = document.getElementById('fp-detail');
    var detailTitle = document.getElementById('fp-detail-title');
    var detailActions = document.getElementById('fp-detail-actions');
    var conflicts = document.getElementById('fp-conflicts');
    var raw = document.getElementById('fp-raw');
    var status = document.getElementById('fp-status');
    var current = document.getElementById('fp-current');
    var adminLink = document.getElementById('fp-admin-link');
    var createForm = document.getElementById('fp-create');
    var mergeForm = document.getElementById('fp-merge');
    var createName = document.getElementById('fp-create-name');
    var createFrom = document.getElementById('fp-create-from');
    var mergeSource = document.getElementById('fp-merge-source');
    var mergeTarget = document.getElementById('fp-merge-target');
    var records = [];

    function branch(name) {
        return state.branches.find(function (item) { return item.name === name; }) || { name: name, url: '#', siteUrl: '#', adminUrl: '#', managerUrl: '#' };
    }
    function setStatus(kind, message) {
        status.className = 'fp-status is-visible ' + kind;
        status.textContent = message;
    }
    function clearStatus() {
        status.className = 'fp-status';
        status.textContent = '';
    }
    function post(action, fields) {
        var body = new FormData();
        body.append('action', action);
        Object.keys(fields || {}).forEach(function (key) { body.append(key, fields[key]); });
        return fetch(state.actionUrl, { method: 'POST', body: body, headers: { Accept: 'application/json', 'X-ForkPress-Async': '1' } })
            .then(function (response) {
                return response.text().then(function (text) {
                    var payload = null;
                    try { payload = text ? JSON.parse(text) : null; } catch (error) { payload = null; }
                    if (!response.ok || !payload || payload.success === false) {
                        throw new Error(payload && payload.message ? payload.message : (text || 'ForkPress request failed.'));
                    }
                    return payload;
                });
            });
    }
    function optionList(select, selected) {
        select.innerHTML = '';
        state.branches.forEach(function (item) {
            var option = document.createElement('option');
            option.value = item.name;
            option.textContent = item.name;
            option.selected = item.name === selected;
            select.appendChild(option);
        });
    }
    function refreshForms() {
        optionList(createFrom, state.currentBranch);
        var firstNonMain = state.branches.find(function (item) { return item.name !== 'main'; });
        optionList(mergeSource, state.currentBranch === 'main' && firstNonMain ? firstNonMain.name : state.currentBranch);
        optionList(mergeTarget, 'main');
    }
    function svg(tag, attrs) {
        var el = document.createElementNS('http://www.w3.org/2000/svg', tag);
        Object.keys(attrs || {}).forEach(function (key) { el.setAttribute(key, attrs[key]); });
        return el;
    }
    function runNumber(run) {
        var id = Number(run && run.id ? run.id : 0);
        return Number.isFinite(id) ? id : 0;
    }
    function sortedRunEntries() {
        return records.map(function (run, index) {
            return { run: run, index: index };
        }).sort(function (a, b) {
            var byId = runNumber(b.run) - runNumber(a.run);
            if (byId !== 0) return byId;
            return String(b.run.finished_at || b.run.started_at || '').localeCompare(String(a.run.finished_at || a.run.started_at || ''));
        });
    }
    function conflictSummary(run) {
        var summary = run && (run.conflictSummary || run.conflict_summary || run._conflictSummary);
        var total = Number(run && run.conflict_count || 0);
        var resolved = Number(summary && summary.resolved || 0);
        var unresolved = Number(summary && summary.unresolved || 0);
        if (!summary && total > 0) unresolved = total;
        return { total: total, resolved: resolved, unresolved: unresolved };
    }
    function runVisualClass(run) {
        var summary = conflictSummary(run);
        var status = String(run.status || '');
        if (summary.total > 0 && summary.unresolved === 0 && (run._conflictSummary || run.conflictSummary || run.conflict_summary)) return ' is-resolved';
        if (summary.total > 0) return ' is-conflict';
        if (status === 'id_bands_allocated' || status === 'identity_captured') return ' is-setup';
        if (status.indexOf('failed') !== -1 || status.indexOf('rolled_back') !== -1) return ' is-failed';
        return ' is-clean';
    }
    function laneNames(entries) {
        var map = {};
        var ordered = [];
        function add(name) {
            name = String(name || '');
            if (!name || map[name]) return;
            map[name] = true;
            ordered.push(name);
        }
        add('main');
        if (state.currentBranch !== 'main') add(state.currentBranch);
        (entries || sortedRunEntries()).forEach(function (entry) {
            var run = entry.run;
            add(run.target_branch);
            add(run.source_branch);
        });
        state.branches.forEach(function (item) { add(item.name); });
        return ordered.sort(function (a, b) {
            if (a === 'main') return -1;
            if (b === 'main') return 1;
            if (a === state.currentBranch) return -1;
            if (b === state.currentBranch) return 1;
            return 0;
        });
    }
    function annotateConflictRuns() {
        var conflictRuns = records.filter(function (run) {
            return Number(run.conflict_count || 0) > 0 && run.id && !run._conflictSummary;
        });
        if (!conflictRuns.length) return Promise.resolve();
        return Promise.all(conflictRuns.map(function (run) {
            return post('forkpress_branch_conflicts', { run: String(run.id) }).then(function (payload) {
                run._conflictSummary = payload.conflictSummary || payload.conflict_summary || null;
                run._conflictRecords = Array.isArray(payload.records) ? payload.records : [];
            }).catch(function () {
                run._conflictSummary = { total: Number(run.conflict_count || 0), resolved: 0, unresolved: Number(run.conflict_count || 0) };
            });
        })).then(function () {});
    }
    function renderGraph() {
        var entries = sortedRunEntries();
        var lanes = laneNames(entries);
        var left = 150;
        var top = 82;
        var laneGap = 132;
        var rowGap = 58;
        var width = Math.max(900, left + Math.max(lanes.length, 1) * laneGap + 72);
        var height = Math.max(520, top + Math.max(entries.length, 1) * rowGap + 56);
        graph.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
        graph.setAttribute('width', width);
        graph.setAttribute('height', height);
        graph.innerHTML = '';
        var x = {};
        lanes.forEach(function (name, index) {
            x[name] = left + index * laneGap;
            var color = colors[index % colors.length];
            graph.appendChild(svg('line', { x1: x[name], x2: x[name], y1: 46, y2: height - 34, class: 'fp-lane-stem' }));
            var label = svg('text', { x: x[name], y: 26, class: 'fp-lane-label', 'text-anchor': 'middle' });
            label.textContent = name;
            graph.appendChild(label);
            var head = svg('circle', { cx: x[name], cy: 46, r: 8, class: 'fp-lane-head', stroke: color, 'data-kind': 'branch', 'data-name': name });
            head.appendChild(svg('title', {})).textContent = 'Branch ' + name;
            graph.appendChild(head);
        });
        entries.forEach(function (entry, rowIndex) {
            var run = entry.run;
            var source = String(run.source_branch || '?');
            var target = String(run.target_branch || source || '?');
            var sx = x[source] !== undefined ? x[source] : (x[target] || left);
            var tx = x[target] !== undefined ? x[target] : sx;
            var y = top + rowIndex * rowGap;
            var laneIndex = Math.max(0, lanes.indexOf(source));
            var color = colors[laneIndex % colors.length];
            var conflict = conflictSummary(run);
            var visual = runVisualClass(run);
            var isMerge = source !== target;
            graph.appendChild(svg('line', { x1: 8, x2: width - 28, y1: y, y2: y, class: 'fp-row-line' }));
            lanes.forEach(function (lane) {
                var point = svg('circle', { cx: x[lane], cy: y, r: 3.5, class: 'fp-cross-point' });
                point.appendChild(svg('title', {})).textContent = lane + ' at revision #' + String(run.id || rowIndex + 1);
                graph.appendChild(point);
            });
            if (isMerge) {
                var edge = svg('path', {
                    d: 'M ' + sx + ' ' + y + ' C ' + (sx + ((tx - sx) * 0.45)) + ' ' + y + ', ' + (sx + ((tx - sx) * 0.55)) + ' ' + y + ', ' + tx + ' ' + y,
                    class: 'fp-edge' + (visual.indexOf('is-conflict') !== -1 ? ' is-conflict' : '') + (visual.indexOf('is-resolved') !== -1 ? ' is-resolved' : ''),
                    stroke: visual.indexOf('is-conflict') !== -1 ? '#b35c00' : (visual.indexOf('is-resolved') !== -1 ? '#008a20' : color)
                });
                graph.appendChild(edge);
                graph.appendChild(svg('circle', { cx: sx, cy: y, r: 5, class: 'fp-merge-dot', stroke: color }));
            }
            var node = svg('circle', {
                cx: tx,
                cy: y,
                r: visual.indexOf('is-setup') !== -1 ? 8 : 11,
                fill: visual.indexOf('is-conflict') !== -1 ? '#b35c00' : (visual.indexOf('is-resolved') !== -1 ? '#008a20' : color),
                class: 'fp-node' + visual,
                'data-kind': 'run',
                'data-index': entry.index
            });
            node.appendChild(svg('title', {})).textContent = '#' + String(run.id || '') + ' ' + source + ' -> ' + target + ' / ' + String(run.status || '');
            graph.appendChild(node);
            var rowLabel = svg('text', { x: 14, y: y - 5, class: 'fp-row-label' });
            rowLabel.textContent = '#' + String(run.id || rowIndex + 1) + ' ' + source + ' -> ' + target;
            graph.appendChild(rowLabel);
            var rowStatus = svg('text', { x: 14, y: y + 12, class: 'fp-row-status' });
            rowStatus.textContent = String(run.status || '') + (conflict.total > 0 ? ' / conflicts ' + conflict.unresolved + ' unresolved of ' + conflict.total : '');
            graph.appendChild(rowStatus);
            if (conflict.total > 0) {
                graph.appendChild(svg('circle', { cx: tx + 10, cy: y - 14, r: 9, class: 'fp-conflict-badge' + (conflict.unresolved === 0 && run._conflictSummary ? ' is-resolved' : '') }));
                var badge = svg('text', { x: tx + 10, y: y - 10, class: 'fp-badge-text', 'text-anchor': 'middle' });
                badge.textContent = String(conflict.unresolved === 0 && run._conflictSummary ? conflict.total : conflict.unresolved);
                graph.appendChild(badge);
            }
        });
        summary.textContent = lanes.length + ' branches / ' + entries.length + ' revision records / newest first';
    }
    function setDetail(title, rows, actions, object) {
        detailTitle.textContent = title;
        detail.innerHTML = '';
        detailActions.innerHTML = '';
        conflicts.innerHTML = '';
        Object.keys(rows).forEach(function (key) {
            var row = document.createElement('div');
            var label = document.createElement('span');
            var value = document.createElement('span');
            label.textContent = key;
            value.textContent = rows[key] === undefined || rows[key] === null ? '' : String(rows[key]);
            row.appendChild(label);
            row.appendChild(value);
            detail.appendChild(row);
        });
        (actions || []).forEach(function (action) { detailActions.appendChild(action); });
        raw.textContent = object ? JSON.stringify(object, null, 2) : '';
    }
    function link(label, href) {
        var a = document.createElement('a');
        a.className = 'fp-button';
        a.href = href;
        a.textContent = label;
        return a;
    }
    function button(label, fn, extraClass) {
        var b = document.createElement('button');
        b.type = 'button';
        b.textContent = label;
        if (extraClass) b.className = extraClass;
        b.addEventListener('click', fn);
        return b;
    }
    function selectBranch(name) {
        var item = branch(name);
        clearStatus();
        setDetail('Branch ' + name, { branch: name, current: name === state.currentBranch ? 'yes' : 'no' }, [
            link('Open site', item.siteUrl || item.url),
            link('Open admin', item.adminUrl || item.url),
            link('Focus manager', item.managerUrl || state.rootUrl)
        ], item);
    }
    function selectRun(run) {
        clearStatus();
        var actions = [
            link('Open source', branch(String(run.source_branch || '')).siteUrl),
            link('Open target', branch(String(run.target_branch || '')).siteUrl)
        ];
        if (Number(run.conflict_count || 0) > 0 && run.id) {
            actions.push(button('Review conflicts', function () { loadConflicts(run.id); }, 'danger'));
        }
        setDetail('Revision #' + String(run.id || ''), {
            source: run.source_branch || '',
            target: run.target_branch || '',
            status: run.status || '',
            conflicts: run.conflict_count || 0,
            'conflict state': (function () {
                var summary = conflictSummary(run);
                if (!summary.total) return 'none';
                return summary.unresolved + ' unresolved / ' + summary.resolved + ' resolved / ' + summary.total + ' total';
            }()),
            decisions: run.decision_count || 0,
            finished: run.finished_at || run.started_at || ''
        }, actions, run);
    }
    function renderConflicts(payload) {
        var list = Array.isArray(payload.records) ? payload.records : [];
        setStatus(list.length ? 'warn' : 'ok', payload.message || 'Loaded conflict details.');
        conflicts.innerHTML = '';
        list.forEach(function (record) {
            var node = document.createElement('div');
            node.className = 'fp-conflict';
            var title = document.createElement('div');
            title.className = 'fp-conflict-title';
            title.textContent = record.conflict_key || record.path || record.table_name || ('Conflict #' + record.id);
            var meta = document.createElement('div');
            meta.className = 'fp-conflict-meta';
            meta.textContent = [record.conflict_type, record.lifecycle_state, record.next_action, record.plugin].filter(Boolean).join(' / ');
            var row = document.createElement('div');
            row.className = 'fp-buttons';
            if (record.id && record.lifecycle_state !== 'resolved') {
                row.appendChild(button('Mark reviewed', function () { reviewConflict(record.id, 'reviewed', payload.run); }));
                if (Array.isArray(record.resolution_choices) && record.resolution_choices.indexOf('source') !== -1) {
                    row.appendChild(button('Use source', function () { resolveConflict(record.id, 'source', payload.run); }));
                }
                if (Array.isArray(record.resolution_choices) && record.resolution_choices.indexOf('target') !== -1) {
                    row.appendChild(button('Keep target', function () { resolveConflict(record.id, 'target', payload.run); }));
                }
            }
            node.appendChild(title);
            node.appendChild(meta);
            node.appendChild(row);
            conflicts.appendChild(node);
        });
        raw.textContent = JSON.stringify(payload, null, 2);
    }
    function loadTree() {
        setStatus('warn', 'Loading branch graph...');
        return post('forkpress_branch_tree', { limit: '50' }).then(function (payload) {
            records = Array.isArray(payload.records) ? payload.records : [];
            renderGraph();
            clearStatus();
            if (records.length) selectRun(records[0]); else selectBranch(state.currentBranch);
            annotateConflictRuns().then(function () {
                renderGraph();
                if (records.length) selectRun(records[0]);
            });
        }).catch(function (error) {
            records = [];
            renderGraph();
            setStatus('error', error.message || 'Could not load branch graph.');
            selectBranch(state.currentBranch);
        });
    }
    function loadHistory() {
        setStatus('warn', 'Loading history...');
        post('forkpress_branch_history', { limit: '50' }).then(function (payload) {
            records = Array.isArray(payload.records) ? payload.records : [];
            renderGraph();
            setStatus('ok', payload.message || 'Loaded history.');
            annotateConflictRuns().then(renderGraph);
        }).catch(function (error) { setStatus('error', error.message || 'Could not load history.'); });
    }
    function loadConflicts(run) {
        setStatus('warn', 'Loading conflicts...');
        post('forkpress_branch_conflicts', { run: String(run) }).then(renderConflicts).catch(function (error) {
            setStatus('error', error.message || 'Could not load conflicts.');
        });
    }
    function reviewConflict(id, value, run) {
        post('forkpress_branch_review_conflict', { conflict: String(id), status: value, run: String(run || '') }).then(function () { loadConflicts(run); }).catch(function (error) { setStatus('error', error.message); });
    }
    function resolveConflict(id, choice, run) {
        post('forkpress_branch_resolve_conflict', { conflict: String(id), choice: choice, run: String(run || '') }).then(function () { loadConflicts(run); }).catch(function (error) { setStatus('error', error.message); });
    }
    graph.addEventListener('click', function (event) {
        var target = event.target.closest ? event.target.closest('[data-kind]') : null;
        if (!target) return;
        if (target.getAttribute('data-kind') === 'branch') selectBranch(target.getAttribute('data-name'));
        if (target.getAttribute('data-kind') === 'run') selectRun(records[Number(target.getAttribute('data-index'))]);
    });
    createForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var submit = createForm.querySelector('button[type=submit]');
        submit.disabled = true;
        post('forkpress_branch_create', { branch: createName.value, from: createFrom.value }).then(function (payload) {
            if (Array.isArray(payload.branches)) state.branches = payload.branches;
            state.currentBranch = createName.value;
            current.textContent = 'Current: ' + state.currentBranch;
            refreshForms();
            renderGraph();
            setStatus('ok', payload.message || 'Branch created.');
            createForm.reset();
        }).catch(function (error) { setStatus('error', error.message || 'Branch create failed.'); }).then(function () { submit.disabled = false; });
    });
    mergeForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var submit = mergeForm.querySelector('button[type=submit]');
        submit.disabled = true;
        post('forkpress_branch_merge', { source: mergeSource.value, target: mergeTarget.value }).then(function (payload) {
            setStatus(payload.type === 'warning' ? 'warn' : 'ok', payload.message || 'Merge completed.');
            if (payload.run && Number(payload.conflicts || 0) > 0) loadConflicts(payload.run);
            loadTree();
        }).catch(function (error) { setStatus('error', error.message || 'Merge failed.'); }).then(function () { submit.disabled = false; });
    });
    document.getElementById('fp-refresh').addEventListener('click', loadTree);
    document.getElementById('fp-load-history').addEventListener('click', loadHistory);
    current.textContent = 'Current: ' + state.currentBranch;
    adminLink.href = branch(state.currentBranch).adminUrl || '#';
    refreshForms();
    renderGraph();
    selectBranch(state.currentBranch);
    loadTree();
}());
</script>
</body>
</html>
HTML;
}

function forkpress_cow_handle_branch_manager(string $path, string $current_branch): bool {
    if ($path !== '/_forkpress/branches') {
        return false;
    }
    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo str_replace('__STATE__', forkpress_cow_json_encode([
        'currentBranch' => $current_branch,
        'branches' => forkpress_cow_branch_switcher_data($current_branch, '/wp-admin/'),
        'actionUrl' => forkpress_cow_branch_url($current_branch, '/_forkpress/action'),
        'rootUrl' => forkpress_cow_branch_url('main', '/_forkpress/branches'),
    ]), forkpress_cow_branch_manager_html($current_branch));
    return true;
}

if (forkpress_cow_handle_branch_manager($path, $branch)) {
    return true;
}

if (forkpress_cow_handle_admin_branch_action($path, $branch, $branches_dir)) {
    return true;
}

if (!forkpress_cow_is_admin_branch_action($path) && !forkpress_cow_acquire_request_lock()) {
    return true;
}

$branch_root = rtrim($branches_dir, '/') . '/' . $branch;
if (!is_dir($branch_root)) {
    http_response_code(404);
    echo "Branch not found: " . htmlspecialchars($branch, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
    return true;
}

function forkpress_cow_reject_unready_branch_write(string $branch_root, string $branches_dir, string $branch): bool {
    if ($branch === 'main' || !forkpress_cow_request_can_write()) {
        return false;
    }

    $cow_dir = getenv('FORKPRESS_COW_DIR') ?: dirname(rtrim($branches_dir, "/\\"));
    $merge_dir = rtrim($cow_dir, "/\\") . '/merge';
    $db_path = rtrim($branch_root, "/\\") . '/wp-content/database/.ht.sqlite';
    $db_base = $merge_dir . '/bases/' . $branch . '.sqlite';
    $file_base = $merge_dir . '/file-bases/' . $branch . '.json';
    $metadata_db = $merge_dir . '/metadata.sqlite';

    $missing = [];
    if (!is_file($db_path)) {
        $missing[] = 'branch database';
    }
    if (!is_file($db_base)) {
        $missing[] = 'database merge base';
    }
    if (!is_file($file_base)) {
        $missing[] = 'filesystem merge base';
    }
    if (!is_file($metadata_db)) {
        $missing[] = 'merge metadata';
    }

    $error = null;
    if (!$missing) {
        require_once dirname(__DIR__, 2) . '/scripts/cow/merge.php';
        try {
            cow_merge_validate_branch_birth_metadata($db_path, $metadata_db, $branch);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    if (!$missing && $error === null) {
        return false;
    }

    http_response_code(409);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "ForkPress branch '$branch' is missing required merge metadata. Reset or recreate it before editing.\n";
    echo "Recovery: run `forkpress branch reset $branch --from main`, or delete and recreate the branch.\n";
    if ($missing) {
        echo 'Missing: ' . implode(', ', $missing) . "\n";
    } elseif ($error !== null) {
        echo $error . "\n";
    }
    return true;
}

if (forkpress_cow_reject_unready_branch_write($branch_root, $branches_dir, $branch)) {
    return true;
}

function forkpress_cow_path_is_inside_branch(string $branch_root, string $path): bool {
    $root_real = realpath($branch_root);
    $path_real = realpath($path);
    if ($root_real === false || $path_real === false) {
        return false;
    }

    $root_real = rtrim(str_replace('\\', '/', $root_real), '/');
    $path_real = str_replace('\\', '/', $path_real);
    return $path_real === $root_real || str_starts_with($path_real, $root_real . '/');
}

$_SERVER['FORKPRESS_BRANCH'] = $branch;
putenv('FORKPRESS_BRANCH=' . $branch);
header('X-ForkPress-Branch: ' . $branch);

if (in_array($path, ['/plugins.php', '/plugin-install.php', '/update.php'], true)
    && !file_exists($branch_root . $path)
    && file_exists($branch_root . '/wp-admin' . $path)) {
    header('Location: /wp-admin' . $path . ($query !== '' ? '?' . $query : ''), true, 302);
    return true;
}

$full_path = $branch_root . $path;
$requested_path = $full_path;
if (substr($path, -1) === '/') {
    $full_path .= 'index.php';
}

function forkpress_cow_send_file(string $path): void {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mimes = [
        'css'   => 'text/css',
        'js'    => 'application/javascript',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'json'  => 'application/json',
        'xml'   => 'application/xml',
        'txt'   => 'text/plain',
        'html'  => 'text/html',
        'map'   => 'application/json',
    ];

    header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
    $size = filesize($path);
    if ($size !== false) {
        header('Content-Length: ' . $size);
    }
    readfile($path);
}

function forkpress_cow_register_php_fatal_logger(string $branch, string $script_path): void {
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;
    $debug_log = getenv('FORKPRESS_DEBUG_LOG') ?: '';
    $request_uri = $_SERVER['REQUEST_URI'] ?? $script_path;
    register_shutdown_function(static function() use ($branch, $script_path, $debug_log, $request_uri): void {
        $error = error_get_last();
        if (!is_array($error)) {
            return;
        }
        $type = (int)($error['type'] ?? 0);
        if (!in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            return;
        }
        $message = (string)($error['message'] ?? 'unknown fatal error');
        $file = (string)($error['file'] ?? $script_path);
        $line = (string)($error['line'] ?? '0');
        $entry = sprintf(
            "[%s] ForkPress branch '%s' PHP fatal while serving %s: %s in %s:%s\n",
            date('c'),
            $branch,
            $request_uri,
            $message,
            $file,
            $line
        );
        error_log(rtrim($entry));
        if ($debug_log !== '') {
            @file_put_contents($debug_log, $entry, FILE_APPEND | LOCK_EX);
        }
    });
}

function forkpress_cow_prepare_php_request(string $path, string $branch_root, string $branch): void {
    chdir($branch_root);
    $_SERVER['DOCUMENT_ROOT'] = $branch_root;
    $_SERVER['SCRIPT_FILENAME'] = $path;
    $_SERVER['SCRIPT_NAME'] = substr($path, strlen($branch_root));
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
    forkpress_cow_register_php_fatal_logger($branch, $path);
}

if (file_exists($full_path) && !is_dir($full_path)) {
    if (!forkpress_cow_path_is_inside_branch($branch_root, $full_path)) {
        http_response_code(404);
        echo "Not found\n";
        return true;
    }
    if (strtolower(pathinfo($full_path, PATHINFO_EXTENSION)) === 'php') {
        forkpress_cow_prepare_php_request($full_path, $branch_root, $branch);
        require $full_path;
        return true;
    }
    forkpress_cow_send_file($full_path);
    return true;
}

if (is_dir($full_path)) {
    $index = rtrim($full_path, '/') . '/index.php';
    if (file_exists($index)) {
        if (!forkpress_cow_path_is_inside_branch($branch_root, $index)) {
            http_response_code(404);
            echo "Not found\n";
            return true;
        }
        forkpress_cow_prepare_php_request($index, $branch_root, $branch);
        require $index;
        return true;
    }
}

if ((file_exists($requested_path) || is_link($requested_path))
    && !forkpress_cow_path_is_inside_branch($branch_root, $requested_path)) {
    http_response_code(404);
    echo "Not found\n";
    return true;
}

if (!forkpress_cow_path_is_inside_branch($branch_root, $branch_root . '/index.php')) {
    http_response_code(404);
    echo "Not found\n";
    return true;
}
forkpress_cow_prepare_php_request($branch_root . '/index.php', $branch_root, $branch);
require $branch_root . '/index.php';
return true;

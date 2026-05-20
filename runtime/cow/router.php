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

function forkpress_cow_request_scheme(): string {
    $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    if (is_string($forwarded) && $forwarded !== '') {
        $scheme = strtolower(trim(explode(',', $forwarded)[0]));
        if (in_array($scheme, ['http', 'https'], true)) {
            return $scheme;
        }
    }
    $forwarded_ssl = strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''));
    if ($forwarded_ssl === 'on' || $forwarded_ssl === '1') {
        return 'https';
    }
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') {
        return 'https';
    }
    $request_scheme = strtolower((string)($_SERVER['REQUEST_SCHEME'] ?? ''));
    if (in_array($request_scheme, ['http', 'https'], true)) {
        return $request_scheme;
    }
    return 'http';
}

function forkpress_cow_branch_url(string $branch, string $uri = '/wp-admin/'): string {
    $root_host = getenv('FORKPRESS_ROOT_HOST') ?: 'wp.localhost';
    $current_host = $_SERVER['HTTP_HOST'] ?? '';
    $port = preg_match('/:(\d+)$/', $current_host, $m) ? ':' . $m[1] : '';
    $host = $branch === 'main' ? $root_host : $branch . '.' . $root_host;
    return forkpress_cow_request_scheme() . '://' . $host . $port . $uri;
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

function forkpress_cow_branch_run_conflict_summaries(array $report, array $records): array {
    $metadata_db = (string)($report['metadata_db'] ?? '');
    if ($metadata_db === '' || !is_file($metadata_db) || !class_exists('SQLite3')) {
        return [];
    }
    $run_ids = [];
    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }
        $id = (int)($record['id'] ?? 0);
        if ($id > 0 && (int)($record['conflict_count'] ?? 0) > 0) {
            $run_ids[$id] = true;
        }
    }
    if ($run_ids === []) {
        return [];
    }

    try {
        $db = new SQLite3($metadata_db, SQLITE3_OPEN_READONLY);
        $ids = implode(',', array_keys($run_ids));
        $rows = $db->query(
            "SELECT c.run_id, COUNT(*) AS total, " .
            "SUM(CASE WHEN COALESCE((SELECT ce.lifecycle_state FROM merge_conflict_events ce WHERE ce.conflict_id = c.id ORDER BY ce.id DESC LIMIT 1), '') = 'resolved' " .
            "OR COALESCE((SELECT mr.applied FROM merge_resolutions mr WHERE mr.conflict_id = c.id ORDER BY mr.id DESC LIMIT 1), 0) = 1 " .
            "THEN 1 ELSE 0 END) AS resolved " .
            "FROM merge_conflicts c WHERE c.run_id IN ($ids) GROUP BY c.run_id"
        );
        if (!$rows instanceof SQLite3Result) {
            return [];
        }
        $summaries = [];
        while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
            $run = (int)($row['run_id'] ?? 0);
            $total = max(0, (int)($row['total'] ?? 0));
            $resolved = max(0, min($total, (int)($row['resolved'] ?? 0)));
            $summaries[$run] = [
                'total' => $total,
                'resolved' => $resolved,
                'unresolved' => max(0, $total - $resolved),
            ];
        }
        $rows->finalize();
        $db->close();
        return $summaries;
    } catch (Throwable $e) {
        return [];
    }
}

function forkpress_cow_branch_tree_summary(array $report, int $limit): array {
    $records = is_array($report['runs'] ?? null) ? array_values($report['runs']) : [];
    $summaries = forkpress_cow_branch_run_conflict_summaries($report, $records);
    foreach ($records as &$record) {
        if (!is_array($record)) {
            continue;
        }
        $conflicts = (int)($record['conflict_count'] ?? 0);
        if ($conflicts <= 0 || isset($record['conflictSummary']) || isset($record['conflict_summary'])) {
            continue;
        }
        $id = (int)($record['id'] ?? 0);
        if (isset($summaries[$id])) {
            $record['conflictSummary'] = $summaries[$id];
        } else {
            $record['conflictSummary'] = [
                'total' => $conflicts,
                'resolved' => 0,
                'unresolved' => $conflicts,
                'estimated' => true,
            ];
        }
    }
    unset($record);
    return [
        'records' => $records,
        'recordCount' => count($records),
        'limit' => $limit,
        'treeCommand' => 'forkpress branch tree --limit ' . $limit . ' --format json',
        'audit' => $report,
    ];
}

function forkpress_cow_decode_typed_payload($value) {
    if (!is_string($value) || $value === '') {
        return null;
    }
    $decoded = json_decode($value, true);
    if (!is_array($decoded)) {
        return null;
    }
    $plain = static function ($item) use (&$plain) {
        if (!is_array($item)) {
            return $item;
        }
        if (array_key_exists('type', $item)) {
            $type = (string)($item['type'] ?? '');
            if ($type === 'bytes' && is_string($item['base64'] ?? null)) {
                $bytes = base64_decode((string)$item['base64'], true);
                return $bytes === false ? '' : $bytes;
            }
            if (array_key_exists('value', $item)) {
                return $item['value'];
            }
            if ($type === 'null') {
                return null;
            }
        }
        $out = [];
        foreach ($item as $key => $child) {
            $out[$key] = $plain($child);
        }
        return $out;
    };
    return $plain($decoded);
}

function forkpress_cow_sqlite_identifier(string $name): string {
    return '"' . str_replace('"', '""', $name) . '"';
}

function forkpress_cow_sqlite_columns(SQLite3 $db, string $table): array {
    $columns = [];
    $res = @$db->query('PRAGMA table_info(' . forkpress_cow_sqlite_identifier($table) . ')');
    if (!$res instanceof SQLite3Result) {
        return [];
    }
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        if (is_string($row['name'] ?? null)) {
            $columns[(string)$row['name']] = true;
        }
    }
    $res->finalize();
    return $columns;
}

function forkpress_cow_first_conflict_context_row(array $record, string $table, string $where_column, $where_value, array $select_columns): ?array {
    if (!class_exists('SQLite3')) {
        return null;
    }
    foreach (['target_db', 'source_db'] as $db_key) {
        $path = (string)($record[$db_key] ?? '');
        if ($path === '' || !is_file($path)) {
            continue;
        }
        try {
            $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
            $columns = forkpress_cow_sqlite_columns($db, $table);
            if (!isset($columns[$where_column])) {
                $db->close();
                continue;
            }
            $usable = [];
            foreach ($select_columns as $column) {
                if (isset($columns[$column])) {
                    $usable[] = $column;
                }
            }
            if ($usable === []) {
                $usable[] = $where_column;
            }
            $select = implode(', ', array_map('forkpress_cow_sqlite_identifier', array_unique($usable)));
            $sql = 'SELECT ' . $select . ' FROM ' . forkpress_cow_sqlite_identifier($table) . ' WHERE ' . forkpress_cow_sqlite_identifier($where_column) . ' = :value LIMIT 1';
            $stmt = $db->prepare($sql);
            if (!$stmt instanceof SQLite3Stmt) {
                $db->close();
                continue;
            }
            $stmt->bindValue(':value', $where_value, is_int($where_value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
            $res = $stmt->execute();
            if (!$res instanceof SQLite3Result) {
                $db->close();
                continue;
            }
            $row = $res->fetchArray(SQLITE3_ASSOC);
            $res->finalize();
            $db->close();
            if (is_array($row)) {
                $row['_db_source'] = $db_key === 'target_db' ? 'target' : 'source';
                return $row;
            }
        } catch (Throwable) {
            continue;
        }
    }
    return null;
}

function forkpress_cow_conflict_row_payload(array $record): array {
    foreach (['target_row_payload', 'source_row_payload'] as $key) {
        $row = forkpress_cow_decode_typed_payload((string)($record[$key] ?? ''));
        if (!is_array($row) || $row === []) {
            continue;
        }
        $row['_db_source'] = $key === 'target_row_payload' ? 'target row payload' : 'source row payload';
        return $row;
    }
    return [];
}

function forkpress_cow_conflict_metadata_payloads(string $metadata_db, int $conflict_id): array {
    if ($metadata_db === '' || $conflict_id <= 0 || !class_exists('SQLite3') || !is_file($metadata_db)) {
        return [];
    }
    try {
        $db = new SQLite3($metadata_db, SQLITE3_OPEN_READONLY);
        $stmt = $db->prepare('SELECT source_row_payload, target_row_payload FROM merge_conflicts WHERE id = :id LIMIT 1');
        if (!$stmt instanceof SQLite3Stmt) {
            $db->close();
            return [];
        }
        $stmt->bindValue(':id', $conflict_id, SQLITE3_INTEGER);
        $res = $stmt->execute();
        if (!$res instanceof SQLite3Result) {
            $db->close();
            return [];
        }
        $row = $res->fetchArray(SQLITE3_ASSOC);
        $res->finalize();
        $db->close();
        return is_array($row) ? $row : [];
    } catch (Throwable) {
        return [];
    }
}

function forkpress_cow_post_context_from_id(array $record, int $post_id): ?array {
    $row = forkpress_cow_first_conflict_context_row($record, 'wp_posts', 'ID', $post_id, ['ID', 'post_type', 'post_title', 'post_name', 'post_status']);
    if (!is_array($row)) {
        $payload_row = forkpress_cow_conflict_row_payload($record);
        $payload_post_id = isset($payload_row['ID']) ? (int)$payload_row['ID'] : 0;
        if ($payload_post_id === $post_id) {
            $row = $payload_row;
        }
    }
    if (!is_array($row)) {
        return null;
    }
    $title = trim((string)($row['post_title'] ?? ''));
    $type = trim((string)($row['post_type'] ?? 'post'));
    $slug = trim((string)($row['post_name'] ?? ''));
    return [
        'entityType' => 'post',
        'entityLabel' => ucfirst($type) . ' #' . $post_id,
        'identifier' => 'ID ' . $post_id,
        'context' => trim(($title !== '' ? $title : '(untitled)') . ($slug !== '' ? ' / ' . $slug : '')),
        'details' => array_filter([
            'post_id' => $post_id,
            'post_type' => $type,
            'post_title' => $title,
            'post_name' => $slug,
            'post_status' => $row['post_status'] ?? null,
            'database' => $row['_db_source'] ?? null,
        ], static fn($value) => $value !== null && $value !== ''),
    ];
}

function forkpress_cow_conflict_entity_context(array $record): array {
    $table = (string)($record['table_name'] ?? '');
    $column = (string)($record['column_name'] ?? '');
    $identity = forkpress_cow_decode_typed_payload((string)($record['row_identity'] ?? ''));
    $identity = is_array($identity) ? $identity : [];
    $field = $column !== '' ? $column : (string)($record['conflict_type'] ?? '');

    if ($table === '__plugins__' || isset($record['plugin'])) {
        $object = (string)($record['plugin_object'] ?? '');
        $plugin = (string)($record['plugin'] ?? '');
        $type = str_contains((string)($record['conflict_type'] ?? ''), 'theme') || str_starts_with($object, 'theme:')
            ? 'theme'
            : 'plugin';
        return [
            'entityType' => $type,
            'entityLabel' => $type === 'theme' ? 'Theme conflict' : 'Plugin conflict',
            'identifier' => $object !== '' ? $object : ($plugin !== '' ? $plugin : (string)($record['conflict_key'] ?? '')),
            'field' => (string)($record['conflict_type'] ?? 'plugin-validator-conflict'),
            'context' => (string)($record['plugin_reason'] ?? $record['plugin_suggested_action'] ?? ''),
            'details' => array_filter([
                'plugin' => $plugin,
                'object' => $object,
                'severity' => $record['plugin_severity'] ?? null,
                'validator' => $record['plugin_validator'] ?? null,
                'semantic_scope' => $record['semantic_scope'] ?? null,
                'files' => $record['plugin_files'] ?? null,
                'tables' => $record['plugin_tables'] ?? null,
            ], static fn($value) => $value !== null && $value !== '' && $value !== []),
        ];
    }

    if ($table === '__files__') {
        $path = (string)($identity['path'] ?? $record['path'] ?? $record['file_path'] ?? '');
        return [
            'entityType' => 'file',
            'entityLabel' => 'File',
            'identifier' => $path,
            'field' => 'path',
            'context' => (string)($record['conflict_type'] ?? ''),
            'details' => ['path' => $path],
        ];
    }

    if ($table === 'wp_options') {
        $option_id = isset($identity['option_id']) ? (int)$identity['option_id'] : null;
        $row = $option_id !== null ? forkpress_cow_first_conflict_context_row($record, 'wp_options', 'option_id', $option_id, ['option_id', 'option_name', 'autoload']) : null;
        $payload_row = is_array($row) ? [] : forkpress_cow_conflict_row_payload($record);
        $context_row = is_array($row) ? $row : $payload_row;
        $option_name = (string)($context_row['option_name'] ?? $identity['option_name'] ?? '');
        return [
            'entityType' => 'option',
            'entityLabel' => 'Option',
            'identifier' => $option_name !== '' ? $option_name : ($option_id !== null ? 'option_id ' . $option_id : ''),
            'field' => $field,
            'context' => $option_id !== null ? 'option_id ' . $option_id : '',
            'details' => array_filter([
                'option_id' => $option_id,
                'option_name' => $option_name,
                'autoload' => $context_row['autoload'] ?? null,
                'database' => $context_row['_db_source'] ?? null,
                'row_lookup' => $context_row === [] ? 'not found in source or target database' : null,
            ], static fn($value) => $value !== null && $value !== ''),
        ];
    }

    if ($table === 'wp_posts' && isset($identity['ID'])) {
        $context = forkpress_cow_post_context_from_id($record, (int)$identity['ID']);
        if ($context !== null) {
            $context['field'] = $field;
            return $context;
        }
    }

    if ($table === 'wp_postmeta') {
        $meta_id = isset($identity['meta_id']) ? (int)$identity['meta_id'] : null;
        $row = $meta_id !== null ? forkpress_cow_first_conflict_context_row($record, 'wp_postmeta', 'meta_id', $meta_id, ['meta_id', 'post_id', 'meta_key']) : null;
        $payload_row = is_array($row) ? [] : forkpress_cow_conflict_row_payload($record);
        $context_row = is_array($row) ? $row : $payload_row;
        $post_id = (int)($context_row['post_id'] ?? $identity['post_id'] ?? 0);
        $post_context = $post_id > 0 ? forkpress_cow_post_context_from_id($record, $post_id) : null;
        $meta_key = (string)($context_row['meta_key'] ?? $identity['meta_key'] ?? '');
        return [
            'entityType' => 'postmeta',
            'entityLabel' => 'Post meta',
            'identifier' => $meta_key !== '' ? $meta_key : ($meta_id !== null ? 'meta_id ' . $meta_id : ''),
            'field' => $field,
            'context' => trim(($post_id > 0 ? 'post_id ' . $post_id : '') . ($post_context ? ' / ' . $post_context['context'] : '')),
            'details' => array_filter([
                'meta_id' => $meta_id,
                'post_id' => $post_id > 0 ? $post_id : null,
                'meta_key' => $meta_key,
                'post' => $post_context['context'] ?? null,
                'database' => $context_row['_db_source'] ?? null,
                'row_lookup' => $context_row === [] ? 'not found in source or target database' : null,
            ], static fn($value) => $value !== null && $value !== ''),
        ];
    }

    if ($table === 'wp_terms') {
        $term_id = isset($identity['term_id']) ? (int)$identity['term_id'] : null;
        $row = $term_id !== null ? forkpress_cow_first_conflict_context_row($record, 'wp_terms', 'term_id', $term_id, ['term_id', 'name', 'slug']) : null;
        $payload_row = is_array($row) ? [] : forkpress_cow_conflict_row_payload($record);
        $context_row = is_array($row) ? $row : $payload_row;
        return [
            'entityType' => 'term',
            'entityLabel' => 'Term',
            'identifier' => (string)($context_row['name'] ?? '') !== '' ? (string)$context_row['name'] : ($term_id !== null ? 'term_id ' . $term_id : ''),
            'field' => $field,
            'context' => (string)($context_row['slug'] ?? ''),
            'details' => array_filter([
                'term_id' => $term_id,
                'name' => $context_row['name'] ?? null,
                'slug' => $context_row['slug'] ?? null,
                'database' => $context_row['_db_source'] ?? null,
                'row_lookup' => $context_row === [] ? 'not found in source or target database' : null,
            ], static fn($value) => $value !== null && $value !== ''),
        ];
    }

    $identifier_parts = [];
    foreach ($identity as $key => $value) {
        if (is_scalar($value)) {
            $identifier_parts[] = $key . '=' . (string)$value;
        }
    }
    return [
        'entityType' => $table !== '' ? $table : 'record',
        'entityLabel' => $table !== '' ? $table : 'Record',
        'identifier' => implode(', ', $identifier_parts),
        'field' => $field,
        'context' => (string)($record['conflict_key'] ?? ''),
        'details' => $identity,
    ];
}

function forkpress_cow_enrich_conflict_records(array $records, string $metadata_db = ''): array {
    foreach ($records as &$record) {
        if (is_array($record) && (!isset($record['source_row_payload']) || !isset($record['target_row_payload']))) {
            $payloads = forkpress_cow_conflict_metadata_payloads($metadata_db, (int)($record['id'] ?? 0));
            foreach (['source_row_payload', 'target_row_payload'] as $key) {
                if (!isset($record[$key]) && isset($payloads[$key])) {
                    $record[$key] = $payloads[$key];
                }
            }
        }
        if (is_array($record) && !isset($record['entityContext']) && !isset($record['entity_context'])) {
            $record['entityContext'] = forkpress_cow_conflict_entity_context($record);
        }
    }
    unset($record);
    return $records;
}

function forkpress_cow_branch_conflict_audit_summary(array $report, int $run, array $filters = []): array {
    $records = is_array($report['conflicts'] ?? null) ? array_values($report['conflicts']) : [];
    $records = forkpress_cow_enrich_conflict_records($records, (string)($report['metadata_db'] ?? ''));
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

        $review_note = forkpress_cow_branch_post_value('note');
        if (strlen($review_note) > 2000) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Keep conflict review notes under 2000 characters.');
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
            $review_note !== '' ? $review_note : $notes[$status],
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
        $replace_applied = forkpress_cow_branch_post_value('replaceApplied') === '1';
        $after_revalidate = forkpress_cow_branch_post_value('afterRevalidate') === '1';
        $choice = forkpress_cow_branch_post_value('choice');
        if ($apply_reviewed && $choice !== '') {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Apply reviewed cannot be combined with a new source or target choice.');
            return true;
        }
        if ($apply_reviewed && $replace_applied) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Changing an applied resolution requires a new source or target choice.');
            return true;
        }
        if ($apply_reviewed && $after_revalidate) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'After revalidate requires a source or target choice.');
            return true;
        }
        if ($replace_applied && $after_revalidate) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Changing an applied resolution cannot be combined with after revalidate.');
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

        $review_note = forkpress_cow_branch_post_value('note');
        if (strlen($review_note) > 2000) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Keep conflict resolution notes under 2000 characters.');
            return true;
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
            if ($replace_applied) {
                $resolve_args[] = '--replace-applied';
            }
            $note = $notes[$choice];
        }
        $resolve_args[] = '--note';
        $resolve_args[] = $review_note !== '' ? $review_note : $note;
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
            $apply_reviewed ? 'Applied reviewed choice for conflict #' . $conflict . '.' : ($replace_applied ? 'Changed conflict #' . $conflict . ' to ' . $choice . '.' : 'Applied ' . $choice . ' for conflict #' . $conflict . '.'),
            [
                'run' => $run,
                'conflict' => $conflict,
                'resolutionChoice' => $apply_reviewed ? 'reviewed' : $choice,
                'afterRevalidate' => $after_revalidate,
                'replaceApplied' => $replace_applied,
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
    .fp-row-hit { cursor: pointer; fill: transparent; pointer-events: all; stroke: transparent; stroke-width: 1; }
    .fp-row-hit:hover { fill: transparent; stroke: #cfe7ff; }
    .fp-timeline-lane { fill: none; stroke-linecap: round; stroke-width: 4; }
    .fp-timeline-fork { fill: none; stroke-linecap: round; stroke-width: 4; }
    .fp-timeline-merge { fill: none; stroke-linecap: round; stroke-width: 4; }
    .fp-timeline-merge.is-conflict { stroke: var(--conflict); stroke-dasharray: 7 5; }
    .fp-timeline-merge.is-resolved { stroke: var(--ok); }
    .fp-row-divider { stroke: #f0f0f1; stroke-width: 1; }
    .fp-row-title { fill: #1d2327; font-size: 12px; font-weight: 700; pointer-events: none; }
    .fp-row-meta { fill: #646970; font-size: 11px; pointer-events: none; }
    .fp-lane-cap { fill: #fff; stroke-width: 2.5; }
    .fp-lane-end { fill: #f6f7f7; stroke-width: 2; }
    .fp-lane-label { fill: #50575e; font-size: 10px; font-weight: 700; pointer-events: none; }
    .fp-branch-pill { fill: #f6f7f7; stroke: #dcdcde; stroke-width: 1; }
    .fp-branch-pill-text { fill: #50575e; font-size: 10px; font-weight: 650; pointer-events: none; }
    .fp-node { cursor: pointer; stroke: #fff; stroke-width: 3; }
    .fp-node.is-current { stroke: #1d2327; stroke-width: 4; }
    .fp-node.is-conflict { fill: var(--conflict); }
    .fp-node.is-resolved { fill: var(--ok); }
    .fp-node.is-setup { fill: #f6f7f7; stroke: #8c8f94; }
    .fp-node.is-failed { fill: var(--danger); }
    .fp-merge-dot { fill: #fff; stroke-width: 3; cursor: pointer; }
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
    .fp-conflict-workbench {
        display: none;
        grid-column: 1 / -1;
    }
    .fp-conflict-workbench.is-visible { display: block; }
    .fp-conflict-workbench-body {
        display: grid;
        gap: 14px;
        padding: 14px 16px 16px;
    }
    .fp-conflict-table-wrap {
        border: 1px solid var(--line);
        border-radius: 6px;
        overflow: auto;
    }
    .fp-conflict-table {
        border-collapse: collapse;
        min-width: 1160px;
        width: 100%;
    }
    .fp-conflict-table th {
        background: #f6f7f7;
        border-bottom: 1px solid var(--line);
        color: var(--muted);
        font-size: 10px;
        font-weight: 700;
        padding: 8px;
        position: sticky;
        text-align: left;
        text-transform: uppercase;
        top: 0;
        vertical-align: top;
        z-index: 1;
    }
    .fp-conflict-table td {
        border-top: 1px solid #f0f0f1;
        font-size: 12px;
        line-height: 1.35;
        max-width: 260px;
        overflow-wrap: anywhere;
        padding: 9px 8px;
        vertical-align: top;
    }
    .fp-conflict-table tbody tr:hover { background: #f6fbff; }
    .fp-table-primary { font-weight: 700; }
    .fp-table-muted {
        color: var(--muted);
        font-size: 11px;
        margin-top: 3px;
    }
    .fp-conflict-details {
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
    .fp-conflict-loading {
        background: #f6f7f7;
        border: 1px solid var(--line);
        border-radius: 6px;
        color: var(--muted);
        font-size: 13px;
        padding: 12px;
    }
    .fp-conflict-title { font-size: 13px; font-weight: 700; overflow-wrap: anywhere; }
    .fp-conflict-meta { color: var(--muted); font-size: 12px; overflow-wrap: anywhere; }
    .fp-conflict-plugin {
        background: #f6f7f7;
        border: 1px solid var(--line);
        border-radius: 6px;
        display: grid;
        gap: 6px;
        padding: 9px 10px;
    }
    .fp-conflict-plugin div {
        display: grid;
        gap: 2px;
    }
    .fp-conflict-plugin span:first-child {
        color: var(--muted);
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
    }
    .fp-conflict-plugin span:last-child {
        font-size: 12px;
        overflow-wrap: anywhere;
    }
    .fp-conflict-grid {
        display: grid;
        gap: 8px;
        grid-template-columns: 1fr 1fr;
    }
    .fp-conflict-value {
        display: grid;
        gap: 4px;
        min-width: 0;
    }
    .fp-conflict-value label,
    .fp-conflict-note label,
    .fp-conflict-choice label {
        color: var(--muted);
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
    }
    textarea {
        border: 1px solid #c3c4c7;
        border-radius: 6px;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: 12px;
        min-height: 72px;
        padding: 7px 8px;
        resize: vertical;
        width: 100%;
    }
    textarea[readonly] { background: #f6f7f7; color: #1d2327; }
    .fp-conflict-note, .fp-conflict-choice {
        display: grid;
        gap: 4px;
    }
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
        .fp-conflict-grid { grid-template-columns: 1fr; }
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
        <section class="fp-panel fp-conflict-workbench" id="fp-conflict-workbench">
            <div class="fp-detail-head">
                <div>
                    <h2 id="fp-conflict-title">Conflict Review</h2>
                    <div class="fp-muted" id="fp-conflict-summary">Select a conflicting revision to inspect entity-level details.</div>
                </div>
            </div>
            <div class="fp-conflict-workbench-body">
                <div class="fp-conflicts" id="fp-conflicts"></div>
            </div>
        </section>
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
    var conflictWorkbench = document.getElementById('fp-conflict-workbench');
    var conflictTitle = document.getElementById('fp-conflict-title');
    var conflictSummaryText = document.getElementById('fp-conflict-summary');
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
    var selectedRunId = null;

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
    function showConflictWorkbench(title, message) {
        conflictWorkbench.className = 'fp-panel fp-conflict-workbench is-visible';
        conflictTitle.textContent = title || 'Conflict Review';
        conflictSummaryText.textContent = message || '';
    }
    function hideConflictWorkbench() {
        conflictWorkbench.className = 'fp-panel fp-conflict-workbench';
        conflicts.innerHTML = '';
        conflictSummaryText.textContent = 'Select a conflicting revision to inspect entity-level details.';
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
    function runTimelineTimestamp(run) {
        var value = String(run && (run.finished_at || run.started_at || run.created_at) || '');
        if (!value) return 0;
        var normalized = value.indexOf('T') === -1 ? value.replace(' ', 'T') + 'Z' : value;
        var parsed = Date.parse(normalized);
        return Number.isFinite(parsed) ? parsed : 0;
    }
    function sortedRunEntries() {
        return records.map(function (run, index) {
            return { run: run, index: index };
        }).sort(function (a, b) {
            var byTime = runTimelineTimestamp(b.run) - runTimelineTimestamp(a.run);
            if (byTime !== 0) return byTime;
            return runNumber(b.run) - runNumber(a.run);
        });
    }
    function conflictSummary(run) {
        var summary = run && (run.conflictSummary || run.conflict_summary || run._conflictSummary);
        var total = Number(summary && summary.total || run && run.conflict_count || 0);
        var resolved = Number(summary && summary.resolved || 0);
        var unresolved = Number(summary && summary.unresolved || 0);
        if (!summary && total > 0) unresolved = total;
        return { total: total, resolved: resolved, unresolved: unresolved };
    }
    function hasConflictSummary(run) {
        return !!(run && (run._conflictSummary || run.conflictSummary || run.conflict_summary));
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
    function isSetupRun(run) {
        var status = String(run && run.status || '');
        var policy = String(run && run.policy || '');
        var base = String(run && run.base_ref || '');
        return status === 'id_bands_allocated'
            || status === 'identity_captured'
            || policy === 'autoincrement-id-band-allocation'
            || policy === 'sidecar-row-identity-capture'
            || base === 'autoincrement-id-band'
            || base === 'identity-capture';
    }
    function isBranchForkRun(run) {
        var source = String(run && run.source_branch || '');
        var target = String(run && run.target_branch || '');
        var policy = String(run && run.policy || '');
        var base = String(run && run.base_ref || '');
        return source !== ''
            && source === target
            && source !== 'main'
            && (policy === 'autoincrement-id-band-allocation' || base === 'autoincrement-id-band');
    }
    function branchForkParents(entries) {
        var parents = {};
        (entries || []).slice().reverse().forEach(function (entry) {
            var run = entry.run || {};
            var source = String(run.source_branch || '');
            var target = String(run.target_branch || '');
            if (!source || !target || source === target) return;
            if (!parents[source]) parents[source] = target;
            if (!parents[target] && target !== 'main') parents[target] = source;
        });
        (entries || []).forEach(function (entry) {
            var run = entry.run || {};
            var branch = String(run.source_branch || '');
            if (isBranchForkRun(run) && !parents[branch]) parents[branch] = 'main';
        });
        return parents;
    }
    function branchColor(name, lanes) {
        var index = Math.max(0, lanes.indexOf(name));
        return colors[index % colors.length];
    }
    function runLabel(run) {
        var source = String(run.source_branch || '?');
        var target = String(run.target_branch || source || '?');
        var prefix = '#' + String(run.id || '');
        if (source === target) {
            return prefix + ' ' + source;
        }
        return prefix + ' ' + source + ' -> ' + target;
    }
    function runMeta(run) {
        var status = String(run.status || '');
        var parts = [status];
        var conflict = conflictSummary(run);
        if (conflict.total > 0) {
            parts.push(conflict.unresolved + ' unresolved / ' + conflict.total + ' conflicts');
        } else if (Number(run.decision_count || 0) > 0) {
            parts.push(String(run.decision_count) + ' decisions');
        }
        var when = run.finished_at || run.started_at || '';
        if (when) parts.push(when);
        return parts.filter(Boolean).join('  ·  ');
    }
    function drawPill(text, x, y, color, layer) {
        var parent = layer || graph;
        var width = Math.max(46, Math.min(150, 14 + String(text).length * 6.3));
        var rect = svg('rect', { x: x, y: y - 12, width: width, height: 18, rx: 9, class: 'fp-branch-pill', stroke: color });
        var label = svg('text', { x: x + 8, y: y + 1, class: 'fp-branch-pill-text' });
        label.textContent = text.length > 20 ? text.slice(0, 18) + '...' : text;
        parent.appendChild(rect);
        parent.appendChild(label);
        return width;
    }
    function laneActivity(entries, lanes) {
        var activity = {};
        var forkParents = branchForkParents(entries);
        function touch(name, rowIndex) {
            name = String(name || '');
            if (!name || !activity[name]) return;
            if (activity[name].first === null || rowIndex < activity[name].first) activity[name].first = rowIndex;
            if (activity[name].last === null || rowIndex > activity[name].last) activity[name].last = rowIndex;
        }
        lanes.forEach(function (lane) { activity[lane] = { first: null, last: null }; });
        entries.forEach(function (entry, rowIndex) {
            var run = entry.run || {};
            [run.source_branch, run.target_branch].forEach(function (name) {
                touch(name, rowIndex);
            });
            if (isBranchForkRun(run)) touch(forkParents[String(run.source_branch || '')] || 'main', rowIndex);
        });
        return activity;
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
    function seedConflictSummaries() {
        records.forEach(function (run) {
            var total = Number(run && run.conflict_count || 0);
            if (total > 0 && !hasConflictSummary(run)) {
                run._conflictSummary = { total: total, resolved: 0, unresolved: total, estimated: true };
            }
        });
    }
    function firstConflictRun() {
        return records.find(function (run) { return Number(run && run.conflict_count || 0) > 0 && run.id; }) || null;
    }
    function runById(run) {
        var id = String(run || '');
        return records.find(function (item) { return String(item && item.id || '') === id; }) || null;
    }
    function renderGraph() {
        var entries = sortedRunEntries();
        var lanes = laneNames(entries);
        var forkParents = branchForkParents(entries);
        var graphLeft = 84;
        var laneGap = 64;
        var graphWidth = Math.max(1, lanes.length) * laneGap;
        var textX = graphLeft + graphWidth + 56;
        var top = 64;
        var rowGap = 70;
        var rowHeight = 60;
        var width = Math.max(1180, textX + 740);
        var height = Math.max(520, top + Math.max(entries.length, 1) * rowGap + 34);
        graph.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
        graph.setAttribute('width', width);
        graph.setAttribute('height', height);
        graph.innerHTML = '';
        var rowLayer = svg('g', { class: 'fp-row-layer' });
        var laneLayer = svg('g', { class: 'fp-lane-layer' });
        var edgeLayer = svg('g', { class: 'fp-edge-layer' });
        var nodeLayer = svg('g', { class: 'fp-node-layer' });
        var textLayer = svg('g', { class: 'fp-text-layer' });
        var hitLayer = svg('g', { class: 'fp-hit-layer' });
        [rowLayer, laneLayer, edgeLayer, nodeLayer, textLayer, hitLayer].forEach(function (layer) { graph.appendChild(layer); });
        var x = {};
        var activity = laneActivity(entries, lanes);
        lanes.forEach(function (name, index) {
            x[name] = graphLeft + index * laneGap;
        });
        entries.forEach(function (entry, rowIndex) {
            var y = top + rowIndex * rowGap;
            rowLayer.appendChild(svg('line', { x1: 0, x2: width - 20, y1: y + rowHeight / 2, y2: y + rowHeight / 2, class: 'fp-row-divider' }));
        });
        lanes.forEach(function (name) {
            var span = activity[name];
            if (!span || span.first === null || span.last === null) return;
            var color = branchColor(name, lanes);
            var startY = top + span.first * rowGap - 22;
            var endY = top + span.last * rowGap + 22;
            laneLayer.appendChild(svg('path', {
                d: 'M ' + x[name] + ' ' + startY + ' L ' + x[name] + ' ' + endY,
                class: 'fp-timeline-lane',
                stroke: color,
                opacity: name === 'main' ? '.72' : '.58'
            }));
            var cap = svg('circle', { cx: x[name], cy: startY, r: 4, class: 'fp-lane-cap', stroke: color });
            cap.appendChild(svg('title', {})).textContent = name + ' latest visible revision';
            nodeLayer.appendChild(cap);
            var end = svg('circle', { cx: x[name], cy: endY, r: 4, class: 'fp-lane-end', stroke: color });
            end.appendChild(svg('title', {})).textContent = name + ' oldest visible revision';
            nodeLayer.appendChild(end);
            if (span.first < 6) {
                var label = svg('text', { x: x[name] + 7, y: startY - 4, class: 'fp-lane-label' });
                label.textContent = name.length > 15 ? name.slice(0, 13) + '...' : name;
                textLayer.appendChild(label);
            }
        });
        entries.forEach(function (entry, rowIndex) {
            var run = entry.run;
            var source = String(run.source_branch || '?');
            var target = String(run.target_branch || source || '?');
            var sx = x[source] !== undefined ? x[source] : (x[target] || graphLeft);
            var tx = x[target] !== undefined ? x[target] : sx;
            var y = top + rowIndex * rowGap;
            var sourceColor = branchColor(source, lanes);
            var targetColor = branchColor(target, lanes);
            var conflict = conflictSummary(run);
            var visual = runVisualClass(run);
            var isMerge = source !== target;
            var isFork = isBranchForkRun(run);
            var forkParent = forkParents[source] || 'main';
            var px = x[forkParent] !== undefined ? x[forkParent] : graphLeft;
            if (isFork && px !== sx) {
                var forkPath = svg('path', {
                    d: 'M ' + px + ' ' + y + ' C ' + px + ' ' + (y - 24) + ', ' + sx + ' ' + (y - 24) + ', ' + sx + ' ' + y,
                    class: 'fp-timeline-fork',
                    stroke: sourceColor,
                    opacity: '.92',
                    'data-kind': 'run',
                    'data-index': entry.index
                });
                forkPath.appendChild(svg('title', {})).textContent = source + ' branched from ' + forkParent;
                edgeLayer.appendChild(forkPath);
                nodeLayer.appendChild(svg('circle', { cx: px, cy: y, r: 5, class: 'fp-merge-dot', stroke: branchColor(forkParent, lanes), 'data-kind': 'run', 'data-index': entry.index }));
            }
            if (isMerge) {
                var bend = Math.max(32, Math.abs(tx - sx) / 2);
                var mergePath = svg('path', {
                    d: 'M ' + sx + ' ' + y + ' C ' + sx + ' ' + (y - 24) + ', ' + (tx + (sx < tx ? -bend : bend)) + ' ' + (y - 24) + ', ' + tx + ' ' + y,
                    class: 'fp-timeline-merge' + (visual.indexOf('is-conflict') !== -1 ? ' is-conflict' : '') + (visual.indexOf('is-resolved') !== -1 ? ' is-resolved' : ''),
                    stroke: visual.indexOf('is-conflict') !== -1 ? '#b35c00' : (visual.indexOf('is-resolved') !== -1 ? '#008a20' : sourceColor),
                    'data-kind': 'run',
                    'data-index': entry.index
                });
                edgeLayer.appendChild(mergePath);
                nodeLayer.appendChild(svg('circle', { cx: sx, cy: y, r: 5, class: 'fp-merge-dot', stroke: sourceColor, 'data-kind': 'run', 'data-index': entry.index }));
            }
            var node = svg('circle', {
                cx: tx,
                cy: y,
                r: visual.indexOf('is-setup') !== -1 ? 8 : 11,
                fill: visual.indexOf('is-conflict') !== -1 ? '#b35c00' : (visual.indexOf('is-resolved') !== -1 ? '#008a20' : targetColor),
                class: 'fp-node' + visual,
                'data-kind': 'run',
                'data-index': entry.index
            });
            node.appendChild(svg('title', {})).textContent = isFork ? ('#' + String(run.id || '') + ' ' + source + ' branched from ' + forkParent) : ('#' + String(run.id || '') + ' ' + source + ' -> ' + target + ' / ' + String(run.status || ''));
            nodeLayer.appendChild(node);
            var title = svg('text', { x: textX, y: y - 6, class: 'fp-row-title' });
            title.textContent = isFork ? ('#' + String(run.id || '') + ' ' + source + ' branched from ' + forkParent) : runLabel(run);
            textLayer.appendChild(title);
            var meta = svg('text', { x: textX, y: y + 12, class: 'fp-row-meta' });
            meta.textContent = runMeta(run);
            textLayer.appendChild(meta);
            var pillX = textX + 305;
            if (isFork) {
                pillX += drawPill(forkParent, pillX, y - 7, branchColor(forkParent, lanes), textLayer) + 8;
                var forkText = svg('text', { x: pillX, y: y - 6, class: 'fp-row-meta' });
                forkText.textContent = 'forks';
                textLayer.appendChild(forkText);
                pillX += 34;
                drawPill(source, pillX, y - 7, sourceColor, textLayer);
            } else {
                pillX += drawPill(source, pillX, y - 7, sourceColor, textLayer) + 8;
            }
            if (isMerge) {
                var arrow = svg('text', { x: pillX, y: y - 6, class: 'fp-row-meta' });
                arrow.textContent = 'into';
                textLayer.appendChild(arrow);
                pillX += 30;
                drawPill(target, pillX, y - 7, targetColor, textLayer);
            }
            if (conflict.total > 0) {
                nodeLayer.appendChild(svg('circle', { cx: tx + 16, cy: y - 17, r: 9, class: 'fp-conflict-badge' + (conflict.unresolved === 0 && hasConflictSummary(run) ? ' is-resolved' : '') }));
                var badge = svg('text', { x: tx + 16, y: y - 13, class: 'fp-badge-text', 'text-anchor': 'middle' });
                badge.textContent = String(conflict.unresolved === 0 && hasConflictSummary(run) ? conflict.total : conflict.unresolved);
                nodeLayer.appendChild(badge);
            }
            hitLayer.appendChild(svg('rect', {
                x: 0,
                y: y - rowHeight / 2,
                width: width,
                height: rowHeight,
                class: 'fp-row-hit',
                'data-kind': 'run',
                'data-index': entry.index
            }));
        });
        summary.textContent = lanes.length + ' graph lanes / ' + entries.length + ' interleaved timeline revisions / newest first';
    }
    function setDetail(title, rows, actions, object) {
        detailTitle.textContent = title;
        detail.innerHTML = '';
        detailActions.innerHTML = '';
        hideConflictWorkbench();
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
        raw.style.display = object ? 'block' : 'none';
    }
    function refreshSelectedRunConflictState(run, summary) {
        var item = runById(run);
        if (item && summary) item._conflictSummary = summary;
        if (!summary || String(selectedRunId) !== String(run)) return false;
        Array.prototype.slice.call(detail.querySelectorAll('div')).some(function (row) {
            var cells = row.querySelectorAll('span');
            if (cells.length < 2 || cells[0].textContent !== 'conflict state') return false;
            cells[1].textContent = String(summary.unresolved || 0) + ' unresolved / ' + String(summary.resolved || 0) + ' resolved / ' + String(summary.total || 0) + ' total';
            return true;
        });
        return true;
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
    function textNode(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        node.textContent = text === undefined || text === null ? '' : String(text);
        return node;
    }
    function cleanPreview(value) {
        if (value === undefined || value === null) return '';
        var text = String(value);
        try {
            var parsed = JSON.parse(text);
            if (typeof parsed === 'string') return parsed;
        } catch (error) {}
        return text;
    }
    function parsePreviewObject(value) {
        if (value === undefined || value === null || value === '') return {};
        if (typeof value === 'object') return value || {};
        try {
            var parsed = JSON.parse(String(value));
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (error) {
            return {};
        }
    }
    function typedPayloadToPlain(value) {
        if (!value || typeof value !== 'object') return value;
        if (Object.prototype.hasOwnProperty.call(value, 'type')) {
            if (Object.prototype.hasOwnProperty.call(value, 'value')) return value.value;
            if (value.type === 'null') return null;
            if (value.type === 'bytes' && typeof value.base64 === 'string') return '[binary]';
        }
        var out = {};
        Object.keys(value).forEach(function (key) { out[key] = typedPayloadToPlain(value[key]); });
        return out;
    }
    function conflictIdentity(record) {
        var preview = parsePreviewObject(record.row_identity_preview);
        if (Object.keys(preview).length) return preview;
        var raw = parsePreviewObject(record.row_identity);
        return typedPayloadToPlain(raw) || {};
    }
    function detailsText(details) {
        if (!details || typeof details !== 'object') return '';
        return Object.keys(details).map(function (key) {
            var value = details[key];
            if (value === undefined || value === null || value === '') return '';
            if (Array.isArray(value)) value = value.join(', ');
            if (typeof value === 'object') value = JSON.stringify(value);
            return key + ': ' + String(value);
        }).filter(Boolean).join(' / ');
    }
    function shortPreview(value) {
        var text = cleanPreview(value);
        text = String(text || '').replace(/\s+/g, ' ').trim();
        if (text.length > 140) return text.slice(0, 137) + '...';
        return text;
    }
    function decodeAuditPayload(value) {
        if (value === undefined || value === null || value === '') return '';
        if (typeof value !== 'string') return JSON.stringify(value, null, 2);
        try {
            var payload = JSON.parse(value);
            if (payload && payload.type === 'bytes' && typeof payload.base64 === 'string') {
                var binary = atob(payload.base64);
                if (window.TextDecoder) {
                    var bytes = new Uint8Array(binary.length);
                    for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
                    return new TextDecoder('utf-8', { fatal: false }).decode(bytes);
                }
                return binary;
            }
            return JSON.stringify(payload, null, 2);
        } catch (error) {
            return value;
        }
    }
    function conflictValue(record, key, previewKey) {
        if (record[previewKey] !== undefined && record[previewKey] !== null && record[previewKey] !== '') {
            return cleanPreview(record[previewKey]);
        }
        return decodeAuditPayload(record[key]);
    }
    function conflictObjectLabel(record) {
        if (record.table_name === '__plugins__' && record.plugin_object) return record.plugin_object;
        if (record.table_name && record.column_name) return record.table_name + '.' + record.column_name;
        if (record.path) return record.path;
        if (record.file_path) return record.file_path;
        if (record.plugin_object) return record.plugin_object;
        return record.conflict_key || ('Conflict #' + record.id);
    }
    function conflictEntityContext(record) {
        var context = record.entityContext || record.entity_context || {};
        var details = context.details && typeof context.details === 'object' ? context.details : {};
        var identity = conflictIdentity(record);
        var table = String(record.table_name || '');
        var column = String(record.column_name || '');
        var type = String(context.entityType || context.entity_type || '');
        var label = String(context.entityLabel || context.entity_label || '');
        var identifier = String(context.identifier || '');
        var field = String(context.field || column || record.conflict_type || '');
        var contextText = String(context.context || '');

        if (!type && (table === '__plugins__' || record.plugin)) type = String(record.conflict_type || '').indexOf('theme') !== -1 ? 'theme' : 'plugin';
        if (!label && type) label = type.charAt(0).toUpperCase() + type.slice(1);
        if (!type && table) type = table;
        if (!label && table) label = table;

        if (table === 'wp_options') {
            type = 'option';
            label = 'Option';
            identifier = identifier || String(details.option_name || identity.option_name || (identity.option_id ? 'option_id ' + identity.option_id : ''));
            contextText = contextText || (identity.option_id ? 'option_id ' + identity.option_id : '');
        } else if (table === 'wp_posts') {
            type = 'post';
            label = label || 'Post';
            identifier = identifier || (identity.ID ? 'ID ' + identity.ID : '');
        } else if (table === 'wp_postmeta') {
            type = 'postmeta';
            label = 'Post meta';
            identifier = identifier || String(details.meta_key || identity.meta_key || (identity.meta_id ? 'meta_id ' + identity.meta_id : ''));
            contextText = contextText || [
                details.post_id || identity.post_id ? 'post_id ' + String(details.post_id || identity.post_id) : '',
                details.post || ''
            ].filter(Boolean).join(' / ');
        } else if (table === '__files__') {
            type = 'file';
            label = 'File';
            identifier = identifier || String(identity.path || record.path || record.file_path || '');
        } else if (table === '__plugins__' || record.plugin) {
            label = type === 'theme' ? 'Theme conflict' : 'Plugin conflict';
            identifier = identifier || String(record.plugin_object || record.plugin || record.conflict_key || '');
            contextText = contextText || String(record.plugin_reason || record.plugin_suggested_action || '');
        }

        return {
            entityType: type || 'record',
            entityLabel: label || 'Record',
            identifier: identifier,
            field: field,
            context: contextText,
            details: details,
            table: table,
            column: column
        };
    }
    function conflictPluginMeta(record) {
        if (!record || (record.table_name !== '__plugins__' && !record.plugin)) return '';
        return [
            record.semantic_scope ? 'scope: ' + String(record.semantic_scope) : '',
            record.plugin ? 'plugin: ' + String(record.plugin) : '',
            record.plugin_object ? 'object: ' + String(record.plugin_object) : '',
            record.plugin_severity ? 'severity: ' + String(record.plugin_severity) : '',
            record.plugin_validator ? 'validator: ' + String(record.plugin_validator) : ''
        ].filter(Boolean).join(' / ');
    }
    function conflictPluginGuidance(record) {
        if (!record || (record.table_name !== '__plugins__' && !record.plugin)) return '';
        return [
            record.plugin_resolution_policy ? 'policy: ' + String(record.plugin_resolution_policy) : '',
            record.plugin_suggested_action ? 'action: ' + String(record.plugin_suggested_action) : '',
            record.plugin_manual_review_reason ? 'manual review: ' + String(record.plugin_manual_review_reason) : ''
        ].filter(Boolean).join(' / ');
    }
    function conflictIsPluginRecord(record) {
        return !!(record && (record.table_name === '__plugins__' || record.plugin));
    }
    function conflictValueField(label, value) {
        var wrap = document.createElement('div');
        wrap.className = 'fp-conflict-value';
        var labelNode = document.createElement('label');
        labelNode.textContent = label;
        var textarea = document.createElement('textarea');
        textarea.readOnly = true;
        textarea.value = value || '';
        wrap.appendChild(labelNode);
        wrap.appendChild(textarea);
        return wrap;
    }
    function appendConflictInfo(parent, label, value) {
        if (value === undefined || value === null || value === '') return;
        var row = document.createElement('div');
        row.appendChild(textNode('span', '', label));
        row.appendChild(textNode('span', '', Array.isArray(value) ? value.join(', ') : String(value)));
        parent.appendChild(row);
    }
    function conflictPluginPanel(record) {
        if (!conflictIsPluginRecord(record)) return null;
        var panel = document.createElement('div');
        panel.className = 'fp-conflict-plugin';
        appendConflictInfo(panel, 'scope', record.semantic_scope || 'plugin');
        appendConflictInfo(panel, 'plugin', record.plugin);
        appendConflictInfo(panel, 'object', record.plugin_object);
        appendConflictInfo(panel, 'severity', record.plugin_severity);
        appendConflictInfo(panel, 'validator', record.plugin_validator);
        appendConflictInfo(panel, 'reason', record.plugin_reason);
        appendConflictInfo(panel, 'policy', record.plugin_resolution_policy);
        appendConflictInfo(panel, 'manual review', record.plugin_manual_review_reason);
        appendConflictInfo(panel, 'suggested action', record.plugin_suggested_action);
        appendConflictInfo(panel, 'tables', record.plugin_tables);
        appendConflictInfo(panel, 'files', record.plugin_files);
        return panel.childNodes.length ? panel : null;
    }
    function conflictResolutionChangeAvailable(record) {
        if (!record || !record.id || Number(record.latest_resolution_applied || 0) !== 1) return false;
        if (record.conflict_type !== 'cell-conflict') return false;
        var choices = Array.isArray(record.resolution_choices) ? record.resolution_choices : ['source', 'target'];
        return choices.indexOf('source') !== -1 && choices.indexOf('target') !== -1;
    }
    function conflictResolutionAppliedLabel(record) {
        if (!record || !record.latest_resolution_id) return 'Current applied resolution can be changed by selecting source or target.';
        return 'Current applied resolution #' + String(record.latest_resolution_id) + ' used ' + String(record.latest_resolution_choice || 'a reviewed') + ' choice.';
    }
    function appendTableCell(row, parts) {
        var cell = document.createElement('td');
        (parts || []).forEach(function (part) {
            if (!part || part.text === undefined || part.text === null || part.text === '') return;
            cell.appendChild(textNode('div', part.className || '', part.text));
        });
        row.appendChild(cell);
        return cell;
    }
    function conflictStateText(record) {
        return [
            record.lifecycle_state || 'unreviewed',
            record.next_action ? 'next: ' + record.next_action : '',
            record.review_status ? 'review: ' + record.review_status : '',
            record.latest_resolution_status ? 'resolution: ' + record.latest_resolution_status : '',
            record.stale_status ? 'stale: ' + record.stale_status : ''
        ].filter(Boolean).join(' / ');
    }
    function conflictValueSummary(record) {
        if (conflictIsPluginRecord(record)) {
            return [
                record.plugin_reason || '',
                record.plugin_suggested_action ? 'suggested: ' + record.plugin_suggested_action : ''
            ].filter(Boolean).join(' / ');
        }
        return [
            conflictValue(record, 'source_payload', 'source_preview') ? 'source: ' + shortPreview(conflictValue(record, 'source_payload', 'source_preview')) : '',
            conflictValue(record, 'target_payload', 'target_preview') ? 'target: ' + shortPreview(conflictValue(record, 'target_payload', 'target_preview')) : ''
        ].filter(Boolean).join(' / ');
    }
    function renderConflictTable(payload, list) {
        var wrap = document.createElement('div');
        wrap.className = 'fp-conflict-table-wrap';
        var table = document.createElement('table');
        table.className = 'fp-conflict-table';
        var thead = document.createElement('thead');
        var header = document.createElement('tr');
        ['Conflict', 'Entity', 'Identifier', 'Field', 'Context', 'Values', 'Action'].forEach(function (label) {
            header.appendChild(textNode('th', '', label));
        });
        thead.appendChild(header);
        table.appendChild(thead);
        var tbody = document.createElement('tbody');
        list.forEach(function (record) {
            var context = conflictEntityContext(record);
            var row = document.createElement('tr');
            var details = detailsText(context.details);
            appendTableCell(row, [
                { className: 'fp-table-primary', text: '#' + String(record.id || '') },
                { className: 'fp-table-muted', text: conflictStateText(record) }
            ]);
            appendTableCell(row, [
                { className: 'fp-table-primary', text: context.entityLabel },
                { className: 'fp-table-muted', text: context.table && context.column ? context.table + '.' + context.column : context.table }
            ]);
            appendTableCell(row, [
                { className: 'fp-table-primary', text: context.identifier || '(unknown)' },
                { className: 'fp-table-muted', text: context.entityType }
            ]);
            appendTableCell(row, [
                { className: 'fp-table-primary', text: context.field || record.conflict_type || '' },
                { className: 'fp-table-muted', text: record.conflict_type || '' }
            ]);
            appendTableCell(row, [
                { className: 'fp-table-primary', text: context.context || details || '(no context)' },
                { className: 'fp-table-muted', text: context.context && details ? details : '' }
            ]);
            appendTableCell(row, [
                { className: 'fp-table-primary', text: conflictValueSummary(record) || '(review details below)' },
                { className: 'fp-table-muted', text: conflictPluginMeta(record) }
            ]);
            var actionCell = document.createElement('td');
            var jump = button('Details', function () {
                var card = document.getElementById('fp-conflict-card-' + String(record.id || ''));
                if (card && card.scrollIntoView) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
            actionCell.appendChild(jump);
            tbody.appendChild(row);
            row.appendChild(actionCell);
        });
        table.appendChild(tbody);
        wrap.appendChild(table);
        return wrap;
    }
    function selectBranch(name) {
        var item = branch(name);
        selectedRunId = null;
        clearStatus();
        setDetail('Branch ' + name, { branch: name, current: name === state.currentBranch ? 'yes' : 'no' }, [
            link('Open site', item.siteUrl || item.url),
            link('Open admin', item.adminUrl || item.url),
            link('Focus manager', item.managerUrl || state.rootUrl)
        ], item);
    }
    function selectRun(run) {
        selectedRunId = String(run && run.id || '');
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
        if (refreshSelectedRunConflictState(payload.run, payload.conflictSummary || payload.conflict_summary || null)) {
            renderGraph();
        }
        setStatus(list.length ? 'warn' : 'ok', payload.message || 'Loaded conflict details.');
        var summary = payload.conflictSummary || payload.conflict_summary || {};
        var summaryParts = [];
        if (summary.total !== undefined) {
            summaryParts.push(String(summary.unresolved || 0) + ' unresolved');
            summaryParts.push(String(summary.resolved || 0) + ' resolved');
            summaryParts.push(String(summary.total || list.length) + ' total');
        } else {
            summaryParts.push(String(list.length) + ' listed');
        }
        showConflictWorkbench('Conflict Review: revision #' + String(payload.run || ''), summaryParts.join(' / '));
        conflicts.innerHTML = '';
        raw.textContent = '';
        raw.style.display = 'none';
        if (!list.length) {
            conflicts.appendChild(textNode('div', 'fp-conflict-loading', 'No conflicts found for this revision.'));
            return;
        }
        conflicts.appendChild(renderConflictTable(payload, list));
        var detailList = document.createElement('div');
        detailList.className = 'fp-conflict-details';
        list.forEach(function (record) {
            var node = document.createElement('div');
            node.className = 'fp-conflict';
            node.id = 'fp-conflict-card-' + String(record.id || '');
            var title = textNode('div', 'fp-conflict-title', '#' + String(record.id || '') + ' ' + conflictObjectLabel(record));
            var metaParts = [
                record.conflict_type,
                record.lifecycle_state,
                record.next_action,
                record.stale_status ? 'stale: ' + record.stale_status : '',
                record.review_status ? 'review: ' + record.review_status : '',
                record.latest_resolution_status ? 'resolution: ' + record.latest_resolution_status : '',
                conflictPluginMeta(record)
            ].filter(Boolean);
            var meta = textNode('div', 'fp-conflict-meta', metaParts.join(' / '));
            var pluginPanel = conflictPluginPanel(record);
            var values = document.createElement('div');
            values.className = 'fp-conflict-grid';
            values.appendChild(conflictValueField('Base', conflictValue(record, 'base_payload', 'base_preview')));
            values.appendChild(conflictValueField('Source', conflictValue(record, 'source_payload', 'source_preview')));
            values.appendChild(conflictValueField('Target', conflictValue(record, 'target_payload', 'target_preview')));
            values.appendChild(conflictValueField('Chosen / current target', conflictValue(record, 'chosen_payload', 'chosen_preview') || conflictValue(record, 'current_target_payload', 'current_target_preview')));
            var noteWrap = document.createElement('div');
            noteWrap.className = 'fp-conflict-note';
            var noteLabel = document.createElement('label');
            noteLabel.textContent = 'Review note';
            var note = document.createElement('textarea');
            note.value = record.review_note || '';
            note.placeholder = 'Record why this choice is correct, what still needs action, or what changed after revalidation.';
            noteWrap.appendChild(noteLabel);
            noteWrap.appendChild(note);
            var choiceWrap = document.createElement('div');
            choiceWrap.className = 'fp-conflict-choice';
            var choiceLabel = document.createElement('label');
            choiceLabel.textContent = 'Resolution choice';
            var choice = document.createElement('select');
            (Array.isArray(record.resolution_choices) ? record.resolution_choices : ['source', 'target']).forEach(function (value) {
                var option = document.createElement('option');
                option.value = value;
                option.textContent = value === 'source' ? 'Use source value' : (value === 'target' ? 'Keep target value' : value);
                option.selected = record.latest_resolution_choice === value;
                choice.appendChild(option);
            });
            choiceWrap.appendChild(choiceLabel);
            choiceWrap.appendChild(choice);
            var row = document.createElement('div');
            row.className = 'fp-buttons';
            var prioritizeResolutionChange = conflictResolutionChangeAvailable(record);
            var isPluginConflict = conflictIsPluginRecord(record);
            if (isPluginConflict && record.id && record.lifecycle_state !== 'resolved') {
                row.appendChild(button('Needs action', function () { reviewConflict(record.id, 'needs-action', payload.run, note.value); }));
                row.appendChild(button('Mark reviewed', function () { reviewConflict(record.id, 'reviewed', payload.run, note.value); }));
                row.appendChild(textNode('span', 'fp-conflict-meta', conflictPluginGuidance(record) || 'Plugin and theme validator conflicts are review-only here; use the suggested action or a plugin merge driver.'));
            } else if (record.id && record.lifecycle_state !== 'resolved') {
                row.appendChild(button('Needs action', function () { reviewConflict(record.id, 'needs-action', payload.run, note.value); }));
                row.appendChild(button('Mark reviewed', function () { reviewConflict(record.id, 'reviewed', payload.run, note.value); }));
                row.appendChild(button('Apply selected', function () { resolveConflict(record.id, choice.value, payload.run, note.value); }, 'primary'));
            } else if (prioritizeResolutionChange) {
                row.appendChild(button('Change applied resolution', function () { resolveConflict(record.id, choice.value, payload.run, note.value, false, true); }, 'primary'));
                row.appendChild(textNode('span', 'fp-conflict-meta', conflictResolutionAppliedLabel(record)));
            } else if (record.id && record.latest_resolution_status && record.latest_resolution_applied !== 1) {
                row.appendChild(button('Apply reviewed choice', function () { resolveConflict(record.id, '', payload.run, note.value, true); }, 'primary'));
            } else if (record.id && Number(record.latest_resolution_applied || 0) === 1) {
                row.appendChild(textNode('span', 'fp-conflict-meta', 'Resolved; changing this conflict type requires a fresh merge audit.'));
            } else {
                row.appendChild(textNode('span', 'fp-conflict-meta', 'Resolved; no action needed.'));
            }
            node.appendChild(title);
            node.appendChild(meta);
            if (pluginPanel) node.appendChild(pluginPanel);
            if (isPluginConflict) {
                node.appendChild(noteWrap);
                node.appendChild(row);
                var pluginPayload = conflictValue(record, 'chosen_payload', 'chosen_preview');
                if (pluginPayload) {
                    var pluginValues = document.createElement('div');
                    pluginValues.className = 'fp-conflict-grid';
                    pluginValues.appendChild(conflictValueField('Validator payload', pluginPayload));
                    node.appendChild(pluginValues);
                }
            } else if (prioritizeResolutionChange) {
                node.appendChild(choiceWrap);
                node.appendChild(noteWrap);
                node.appendChild(row);
                node.appendChild(values);
            } else {
                node.appendChild(values);
                node.appendChild(noteWrap);
                node.appendChild(choiceWrap);
                node.appendChild(row);
            }
            detailList.appendChild(node);
        });
        conflicts.appendChild(detailList);
    }
    function loadTree() {
        setStatus('warn', 'Loading branch graph...');
        return post('forkpress_branch_tree', { limit: '50' }).then(function (payload) {
            records = Array.isArray(payload.records) ? payload.records : [];
            seedConflictSummaries();
            renderGraph();
            clearStatus();
            var initialRun = firstConflictRun() || records[0] || null;
            if (initialRun) {
                selectRun(initialRun);
                if (Number(initialRun.conflict_count || 0) > 0) loadConflicts(initialRun.id, { defaultLoad: true });
            } else {
                selectBranch(state.currentBranch);
            }
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
            seedConflictSummaries();
            renderGraph();
            setStatus('ok', payload.message || 'Loaded history.');
        }).catch(function (error) { setStatus('error', error.message || 'Could not load history.'); });
    }
    function renderConflictLoading(run) {
        showConflictWorkbench('Conflict Review: revision #' + String(run || ''), 'Loading entity-level conflict context...');
        conflicts.innerHTML = '';
        raw.textContent = '';
        raw.style.display = 'none';
        conflicts.appendChild(textNode('div', 'fp-conflict-loading', 'Loading conflict list for run #' + String(run) + '...'));
    }
    function cacheConflictPayload(run, payload) {
        var item = runById(run);
        if (!item) return;
        item._conflictSummary = payload.conflictSummary || payload.conflict_summary || item._conflictSummary || null;
        item._conflictRecords = Array.isArray(payload.records) ? payload.records : [];
        if (String(selectedRunId) === String(run)) {
            selectRun(item);
        }
    }
    function loadConflicts(run, options) {
        var item = runById(run);
        if (item && Array.isArray(item._conflictRecords)) {
            renderConflicts({
                run: Number(run),
                records: item._conflictRecords,
                conflictSummary: item._conflictSummary || null,
                message: 'Loaded cached conflict details.'
            });
            if (!options || !options.refresh) return Promise.resolve();
        } else {
            renderConflictLoading(run);
        }
        setStatus('warn', 'Loading conflicts...');
        return post('forkpress_branch_conflicts', { run: String(run) }).then(function (payload) {
            cacheConflictPayload(run, payload);
            renderConflicts(payload);
            renderGraph();
        }).catch(function (error) {
            setStatus('error', error.message || 'Could not load conflicts.');
        });
    }
    function reviewConflict(id, value, run, note) {
        post('forkpress_branch_review_conflict', { conflict: String(id), status: value, run: String(run || ''), note: note || '' }).then(function () { loadConflicts(run); }).catch(function (error) { setStatus('error', error.message); });
    }
    function resolveConflict(id, choice, run, note, applyReviewed, replaceApplied) {
        post('forkpress_branch_resolve_conflict', { conflict: String(id), choice: choice || '', run: String(run || ''), note: note || '', applyReviewed: applyReviewed ? '1' : '', replaceApplied: replaceApplied ? '1' : '' }).then(function () { loadConflicts(run); loadTree(); }).catch(function (error) { setStatus('error', error.message); });
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
        'actionUrl' => '/_forkpress/action',
        'rootUrl' => '/_forkpress/branches',
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

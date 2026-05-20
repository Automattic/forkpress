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

function forkpress_cow_branch_post_has_value(string $key): bool {
    $value = $_POST[$key] ?? $_REQUEST[$key] ?? null;
    return $value !== null && !is_array($value);
}

function forkpress_cow_branch_post_raw_value(string $key): string {
    $value = $_POST[$key] ?? $_REQUEST[$key] ?? '';
    if (is_array($value)) {
        return '';
    }
    return (string)$value;
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

function forkpress_cow_metadata_db_path(string $branches_dir): string {
    $cow_dir = getenv('FORKPRESS_COW_DIR') ?: dirname(rtrim($branches_dir, "/\\"));
    return rtrim($cow_dir, "/\\") . '/merge/metadata.sqlite';
}

function forkpress_cow_metadata_branch_names(string $branches_dir): array {
    $metadata_db = forkpress_cow_metadata_db_path($branches_dir);
    if (!class_exists('SQLite3') || !is_file($metadata_db)) {
        return [];
    }

    try {
        $db = new SQLite3($metadata_db, SQLITE3_OPEN_READONLY);
        $names = [];
        foreach ([
            'SELECT DISTINCT source_branch AS branch FROM merge_runs UNION SELECT DISTINCT target_branch AS branch FROM merge_runs',
            'SELECT DISTINCT branch_name AS branch FROM merge_autoincrement_bands',
            'SELECT DISTINCT branch_name AS branch FROM merge_row_identities',
        ] as $sql) {
            $rows = @$db->query($sql);
            if (!$rows instanceof SQLite3Result) {
                continue;
            }
            while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
                $name = trim((string)($row['branch'] ?? ''));
                if ($name !== '' && preg_match('/^[a-zA-Z0-9_\-]{1,63}$/', $name)) {
                    $names[$name] = true;
                }
            }
            $rows->finalize();
        }
        $db->close();
        return array_keys($names);
    } catch (Throwable) {
        return [];
    }
}

function forkpress_cow_branch_tree_report_from_metadata(string $branches_dir, int $limit): ?array {
    $metadata_db = forkpress_cow_metadata_db_path($branches_dir);
    if (!class_exists('SQLite3') || !is_file($metadata_db)) {
        return null;
    }

    try {
        $db = new SQLite3($metadata_db, SQLITE3_OPEN_READONLY);
        $stmt = $db->prepare(
            'SELECT r.id, r.source_branch, r.target_branch, r.base_ref, r.started_at, r.finished_at, r.status, r.policy, ' .
            'r.source_db, r.target_db, r.base_db, r.source_root, r.target_root, r.target_before_db, r.target_before_root, r.failure_reason, ' .
            '(SELECT COUNT(*) FROM merge_conflicts c WHERE c.run_id = r.id) AS conflict_count, ' .
            '(SELECT COUNT(*) FROM merge_decisions d WHERE d.run_id = r.id) AS decision_count ' .
            'FROM merge_runs r ORDER BY r.id DESC LIMIT :limit'
        );
        if (!$stmt instanceof SQLite3Stmt) {
            $db->close();
            return null;
        }
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $rows = $stmt->execute();
        if (!$rows instanceof SQLite3Result) {
            $db->close();
            return null;
        }
        $runs = [];
        while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
            $row['id'] = (int)($row['id'] ?? 0);
            $row['conflict_count'] = (int)($row['conflict_count'] ?? 0);
            $row['decision_count'] = (int)($row['decision_count'] ?? 0);
            $runs[] = $row;
        }
        $rows->finalize();
        $db->close();
        return [
            'runs' => $runs,
            'metadata_db' => $metadata_db,
            'branches' => forkpress_cow_metadata_branch_names($branches_dir),
            'source' => 'metadata',
        ];
    } catch (Throwable) {
        return null;
    }
}

function forkpress_cow_branch_conflict_report_from_metadata(string $branches_dir, int $run): ?array {
    $metadata_db = forkpress_cow_metadata_db_path($branches_dir);
    if (!class_exists('SQLite3') || !is_file($metadata_db)) {
        return null;
    }

    try {
        $db = new SQLite3($metadata_db, SQLITE3_OPEN_READONLY);
        $stmt = $db->prepare(
            'SELECT c.*, r.source_branch, r.target_branch, r.source_db, r.target_db, r.base_db, ' .
            '(SELECT ce.lifecycle_state FROM merge_conflict_events ce WHERE ce.conflict_id = c.id ORDER BY ce.id DESC LIMIT 1) AS lifecycle_state, ' .
            '(SELECT ce.event_type FROM merge_conflict_events ce WHERE ce.conflict_id = c.id ORDER BY ce.id DESC LIMIT 1) AS latest_event_type, ' .
            '(SELECT rn.status FROM merge_review_notes rn WHERE rn.record_type = "conflict" AND rn.record_id = c.id ORDER BY rn.id DESC LIMIT 1) AS review_status, ' .
            '(SELECT rn.note FROM merge_review_notes rn WHERE rn.record_type = "conflict" AND rn.record_id = c.id ORDER BY rn.id DESC LIMIT 1) AS review_note, ' .
            '(SELECT mr.id FROM merge_resolutions mr WHERE mr.conflict_id = c.id ORDER BY mr.id DESC LIMIT 1) AS latest_resolution_id, ' .
            '(SELECT mr.choice FROM merge_resolutions mr WHERE mr.conflict_id = c.id ORDER BY mr.id DESC LIMIT 1) AS latest_resolution_choice, ' .
            '(SELECT mr.applied FROM merge_resolutions mr WHERE mr.conflict_id = c.id ORDER BY mr.id DESC LIMIT 1) AS latest_resolution_applied, ' .
            '(SELECT mr.status FROM merge_resolutions mr WHERE mr.conflict_id = c.id ORDER BY mr.id DESC LIMIT 1) AS latest_resolution_status, ' .
            '(SELECT rv.revalidation_class FROM merge_revalidations rv WHERE rv.conflict_id = c.id ORDER BY rv.id DESC LIMIT 1) AS stale_status ' .
            'FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.run_id = :run ORDER BY c.id ASC'
        );
        if (!$stmt instanceof SQLite3Stmt) {
            $db->close();
            return null;
        }
        $stmt->bindValue(':run', $run, SQLITE3_INTEGER);
        $rows = $stmt->execute();
        if (!$rows instanceof SQLite3Result) {
            $db->close();
            return null;
        }
        $conflicts = [];
        while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
            $row['id'] = (int)($row['id'] ?? 0);
            $row['run_id'] = (int)($row['run_id'] ?? 0);
            $row['latest_resolution_id'] = isset($row['latest_resolution_id']) ? (int)$row['latest_resolution_id'] : null;
            $row['latest_resolution_applied'] = isset($row['latest_resolution_applied']) ? (int)$row['latest_resolution_applied'] : 0;
            $row['resolution_choices'] = ((string)($row['conflict_type'] ?? '') === 'cell-conflict' && (string)($row['table_name'] ?? '') !== '__plugins__')
                ? ['source', 'target', 'custom']
                : ['source', 'target'];
            $conflicts[] = $row;
        }
        $rows->finalize();
        $db->close();
        return [
            'conflicts' => $conflicts,
            'metadata_db' => $metadata_db,
            'source' => 'metadata',
        ];
    } catch (Throwable) {
        return null;
    }
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

if (!function_exists('forkpress_cow_sqlite_identifier')) {
    function forkpress_cow_sqlite_identifier(string $name): string {
        return '"' . str_replace('"', '""', $name) . '"';
    }
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
                'plugin_check' => $record['plugin_validator'] ?? null,
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
        return [1, 'ForkPress returned invalid conflict change-check JSON.', null];
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
    $current_url = forkpress_cow_branch_manager_url($current_branch);
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
            forkpress_cow_branch_manager_url($branch),
            true,
            'Created branch ' . $branch . '.',
            [
                'branches' => forkpress_cow_branch_switcher_data($branch, '/wp-admin/', [$branch, 'main']),
                'managerUrl' => forkpress_cow_branch_manager_url($branch),
            ]
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
                forkpress_cow_branch_manager_url($target),
                true,
                $message,
                [
                    'type' => 'warning',
                    'branches' => forkpress_cow_branch_switcher_data($target, '/wp-admin/'),
                    'managerUrl' => forkpress_cow_branch_manager_url($target),
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
            forkpress_cow_branch_manager_url($target),
            true,
            'Merged ' . $source . ' into ' . $target . '.',
            [
                'branches' => forkpress_cow_branch_switcher_data($target, '/wp-admin/'),
                'managerUrl' => forkpress_cow_branch_manager_url($target),
            ]
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
            $report = forkpress_cow_branch_tree_report_from_metadata($branches_dir, $limit);
            if ($report === null) {
                forkpress_cow_branch_finish_json(400, $current_url, false, $output ?: 'ForkPress could not inspect the branch tree.');
                return true;
            }
        } else {
            $report = json_decode($output, true);
            if (!is_array($report)) {
                forkpress_cow_branch_finish_json(400, $current_url, false, 'ForkPress returned invalid branch tree JSON.');
                return true;
            }
        }

        $summary = forkpress_cow_branch_tree_summary($report, $limit);
        if (is_array($report['branches'] ?? null)) {
            $summary['branches'] = forkpress_cow_branch_switcher_data($current_branch, '/wp-admin/', $report['branches']);
        }
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
            $crash_summary = ['crashRecoveryCount' => 0];
        } else {
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
        }

        $audit_args = array_merge(['merge-audit', '--records', 'conflicts', '--run', (string)$run, '--format', 'json'], $filters['args']);
        [$code, $output] = forkpress_cow_branch_run_cli($audit_args);
        if ($code !== 0) {
            $report = forkpress_cow_branch_conflict_report_from_metadata($branches_dir, $run);
            if ($report === null) {
                forkpress_cow_branch_finish_json(400, $current_url, false, $output ?: 'ForkPress could not inspect merge conflicts.');
                return true;
            }
        } else {
            $report = json_decode($output, true);
            if (!is_array($report)) {
                forkpress_cow_branch_finish_json(400, $current_url, false, 'ForkPress returned invalid merge audit JSON.');
                return true;
            }
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
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Choose a merge run to check for changes.');
            return true;
        }

        [$code, $output, $result] = forkpress_cow_branch_revalidate_merge_run($run);
        if ($code !== 0) {
            forkpress_cow_branch_finish_json(400, $current_url, false, $output ?: 'ForkPress could not check merge conflicts for changes.');
            return true;
        }

        $checked = max(0, (int)($result['checked'] ?? 0));
        $stale = max(0, (int)($result['stale'] ?? 0));
        $carried = max(0, (int)($result['carried'] ?? 0));
        $message = 'Checked merge run ' . $run . ' for changes: ' . $checked . ' checked, ' . $stale . ' changed, ' . $carried . ' unchanged.';
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
                'changeCheck' => $result,
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
        $custom_value = forkpress_cow_branch_post_raw_value('customValue');
        $has_custom_value = forkpress_cow_branch_post_has_value('customValue');
        if ($apply_reviewed && $choice !== '') {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Apply reviewed cannot be combined with a new source, target, or custom choice.');
            return true;
        }
        if ($apply_reviewed && $custom_value !== '') {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Apply reviewed cannot be combined with a custom value.');
            return true;
        }
        if ($apply_reviewed && $replace_applied) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Changing an applied resolution requires a new source, target, or custom choice.');
            return true;
        }
        if ($apply_reviewed && $after_revalidate) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Checking again requires a source, target, or custom choice.');
            return true;
        }
        if ($replace_applied && $after_revalidate) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Changing an applied resolution cannot be combined with checking again.');
            return true;
        }
        if (!$apply_reviewed && !in_array($choice, ['source', 'target', 'custom'], true)) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Choose source, target, or custom for the conflict resolution.');
            return true;
        }
        if (!$apply_reviewed && $choice === 'custom' && !$has_custom_value) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Enter a custom value for the conflict resolution.');
            return true;
        }
        if (!$apply_reviewed && $choice === 'custom' && str_contains($custom_value, "\0")) {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Custom conflict values cannot contain NUL bytes.');
            return true;
        }
        if (!$apply_reviewed && $choice !== 'custom' && $has_custom_value && $custom_value !== '') {
            forkpress_cow_branch_finish_json(400, $current_url, false, 'Custom values can only be used with the custom conflict resolution choice.');
            return true;
        }

        $run = forkpress_cow_branch_post_int('run');
        if ($apply_reviewed && $run !== null) {
            [$code, $output, $revalidation] = forkpress_cow_branch_revalidate_merge_run($run);
            if ($code !== 0) {
                forkpress_cow_branch_finish_json(400, $current_url, false, $output ?: 'ForkPress could not check merge conflicts for changes before applying the reviewed choice.');
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
                    'Conflict #' . $conflict . ' changed since review. Check it again and review it before applying the reviewed choice.',
                    [
                        'run' => $run,
                        'conflict' => $conflict,
                        'checked' => $checked,
                        'stale' => $stale,
                        'carried' => $carried,
                        'changeCheck' => $revalidation,
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
            'custom' => 'Applied custom value from the WordPress branch switcher.',
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
            if ($choice === 'custom') {
                $resolve_args[] = '--custom-value';
                $resolve_args[] = $custom_value;
            }
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
                'customValue' => $choice === 'custom' ? $custom_value : null,
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
        overflow: hidden;
    }
    .fp-shell {
        display: flex;
        flex-direction: column;
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
        flex: 1;
        gap: 10px;
        grid-template-columns: minmax(0, 1fr);
        grid-template-rows: minmax(360px, 1fr) auto;
        height: calc(100vh - 54px);
        min-height: 0;
        overflow: hidden;
        padding: 10px;
    }
    .fp-panel {
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 8px;
        min-width: 0;
    }
    .fp-graph-panel {
        display: flex;
        flex-direction: column;
        min-height: 0;
        overflow: hidden;
    }
    .fp-graph-head, .fp-detail-head {
        align-items: center;
        border-bottom: 1px solid var(--line);
        display: flex;
        gap: 10px;
        justify-content: space-between;
        padding: 14px 16px;
    }
    .fp-bottom > .fp-detail-head { padding: 10px 16px; }
    h1, h2 { font-size: 16px; line-height: 1.25; margin: 0; }
    .fp-muted { color: var(--muted); font-size: 12px; overflow-wrap: anywhere; }
    .fp-summary-chips {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
    }
    .fp-chip {
        background: #f6f7f7;
        border: 1px solid var(--line);
        border-radius: 999px;
        color: var(--muted);
        display: inline-flex;
        font-size: 11px;
        line-height: 1;
        padding: 4px 7px;
    }
    .fp-chip.strong {
        background: #edf6ff;
        border-color: #c5d9ed;
        color: #1d4f73;
        font-weight: 700;
    }
    .fp-chip.warn {
        background: #fff4e5;
        border-color: #f0c36d;
        color: #713f00;
        font-weight: 700;
    }
    .fp-graph-head > div, .fp-detail-head > div { min-width: 0; }
    .fp-workbench-title {
        display: grid;
        gap: 2px;
    }
    .fp-graph-intro {
        display: grid;
        gap: 4px;
    }
    .fp-legend {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 6px 10px;
        margin-top: 2px;
    }
    .fp-legend span {
        align-items: center;
        color: var(--muted);
        display: inline-flex;
        font-size: 11px;
        gap: 4px;
        white-space: nowrap;
    }
    .fp-legend i {
        border: 2px solid #fff;
        display: inline-block;
        height: 10px;
        width: 10px;
    }
    .fp-legend .revision { background: var(--accent); border-radius: 50%; }
    .fp-legend .merge { background: var(--conflict); transform: rotate(45deg); }
    .fp-legend .conflicts { background: var(--danger); border-radius: 4px; width: 16px; }
    .fp-legend .arrow {
        border: 0;
        border-top: 2px solid #50575e;
        height: 0;
        position: relative;
        width: 16px;
    }
    .fp-legend .arrow::after {
        border-bottom: 4px solid transparent;
        border-left: 5px solid #50575e;
        border-top: 4px solid transparent;
        content: "";
        position: absolute;
        right: -1px;
        top: -5px;
    }
    .fp-graph-wrap {
        flex: 1 1 0;
        min-height: 0;
        overflow: auto;
        padding: 8px 10px 10px;
    }
    .fp-graph {
        display: block;
        min-height: 280px;
        min-width: 680px;
    }
    .fp-row-hit { cursor: pointer; fill: transparent; pointer-events: all; stroke: transparent; stroke-width: 1; }
    .fp-row-hit:hover, .fp-row-hit:focus { fill: transparent; outline: none; stroke: #72aee6; }
    .fp-row-selected { fill: #f0f6fc; stroke: #cfe7ff; stroke-width: 1; }
    .fp-timeline-lane { fill: none; stroke-linecap: round; stroke-width: 4; }
    .fp-timeline-fork { fill: none; stroke-linecap: round; stroke-width: 4; }
    .fp-timeline-merge { fill: none; stroke-linecap: round; stroke-width: 3; }
    .fp-timeline-merge.is-conflict { stroke: var(--conflict); stroke-dasharray: 7 5; }
    .fp-timeline-merge.is-resolved { stroke: var(--ok); }
    .fp-row-divider { stroke: #f0f0f1; stroke-width: 1; }
    .fp-row-title { fill: #1d2327; font-size: 12px; font-weight: 700; pointer-events: none; }
    .fp-row-meta { fill: #646970; font-size: 11px; pointer-events: none; }
    .fp-lane-boundary { stroke-linecap: round; stroke-width: 2; opacity: .76; }
    .fp-lane-label-bg { fill: #fff; opacity: .94; stroke: #f0f0f1; stroke-width: 1; }
    .fp-lane-label { fill: #50575e; font-size: 10px; font-weight: 700; pointer-events: none; }
    .fp-merge-flow-bg { fill: #fff; opacity: .95; stroke: #dcdcde; stroke-width: 1; }
    .fp-merge-flow { fill: #50575e; font-size: 9.5px; font-weight: 700; pointer-events: none; }
    .fp-node { cursor: pointer; stroke: #fff; stroke-width: 3; }
    .fp-node-merge { stroke-width: 3; }
    .fp-node.is-selected { stroke: #1d2327; stroke-width: 4; }
    .fp-node.is-current { stroke: #1d2327; stroke-width: 4; }
    .fp-node.is-conflict { fill: var(--conflict); }
    .fp-node.is-resolved { fill: var(--ok); }
    .fp-node.is-setup { fill: #f6f7f7; stroke: #8c8f94; }
    .fp-node.is-failed { fill: var(--danger); }
    .fp-conflict-badge { fill: var(--danger); }
    .fp-conflict-badge.is-resolved { fill: var(--ok); }
    .fp-badge-text { fill: #fff; font-size: 10px; font-weight: 700; pointer-events: none; }
    .fp-bottom {
        display: grid;
        grid-template-rows: auto minmax(0, 1fr);
        max-height: 46vh;
        min-height: 330px;
        min-width: 0;
    }
    body.fp-reviewing .fp-layout {
        grid-template-rows: minmax(180px, 26vh) minmax(0, 1fr);
    }
    body.fp-reviewing .fp-bottom {
        max-height: none;
        min-height: 0;
    }
    .fp-bottom-body {
        display: grid;
        gap: 12px;
        grid-template-columns: minmax(220px, 248px) minmax(0, 1fr);
        min-height: 0;
        overflow: hidden;
        padding: 10px 12px 12px;
    }
    .fp-bottom-primary {
        display: grid;
        grid-column: 1 / -1;
        grid-template-rows: auto minmax(0, 1fr);
        min-height: 0;
        min-width: 0;
    }
    body.fp-reviewing .fp-bottom-body {
        gap: 8px;
        grid-template-columns: minmax(0, 1fr);
        grid-template-rows: minmax(0, 1fr);
        padding: 8px 12px 12px;
    }
    body.fp-reviewing .fp-bottom-primary {
        display: none;
    }
    .fp-detail-body, .fp-actions-body {
        display: grid;
        gap: 12px;
        min-height: 0;
        overflow: auto;
        padding: 10px 12px;
    }
    .fp-detail-body { grid-template-rows: auto auto auto minmax(0, 1fr); }
    body.fp-reviewing .fp-bottom-primary .fp-detail-head { display: none; }
    body.fp-reviewing .fp-detail-body {
        gap: 8px;
        grid-template-rows: auto;
        padding: 0;
    }
    body.fp-reviewing .fp-bottom-primary #fp-detail-actions { display: none; }
    body.fp-reviewing #fp-raw { display: none !important; }
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
    body.fp-reviewing .fp-kv {
        display: grid;
        gap: 6px;
        grid-template-columns: repeat(auto-fit, minmax(128px, 1fr));
    }
    body.fp-reviewing .fp-kv div {
        align-items: start;
        background: #fafafa;
        border: 1px solid #f0f0f1;
        border-radius: 6px;
        display: grid;
        gap: 3px;
        grid-template-columns: minmax(0, 1fr);
        padding: 6px 8px;
    }
    body.fp-reviewing .fp-kv span:first-child { font-size: 10px; }
    body.fp-reviewing .fp-kv span:last-child { font-size: 12px; }
    .fp-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .fp-buttons .fp-conflict-meta {
        align-items: center;
        display: inline-flex;
        min-height: 32px;
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
    button.is-busy::after {
        content: "";
        border: 2px solid currentColor;
        border-right-color: transparent;
        border-radius: 50%;
        height: 12px;
        margin-left: 6px;
        width: 12px;
    }
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
        border-bottom: 1px solid var(--line);
        cursor: pointer;
        font-size: 13px;
        font-weight: 700;
        padding: 12px 14px;
    }
    .fp-bottom-actions {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-left: auto;
        position: relative;
    }
    .fp-bottom-actions .fp-actions {
        position: relative;
    }
    .fp-bottom-actions details.fp-actions summary {
        border: 1px solid #8c8f94;
        border-radius: 6px;
        list-style: none;
        min-height: 32px;
        padding: 6px 10px;
        white-space: nowrap;
    }
    .fp-bottom-actions details.fp-actions summary::-webkit-details-marker {
        display: none;
    }
    .fp-bottom-actions details.fp-actions[open] summary {
        border-color: var(--accent);
        color: var(--accent-strong);
    }
    .fp-bottom-actions .fp-actions-body {
        background: #fff;
        border: 1px solid var(--line);
        border-radius: 8px;
        box-shadow: 0 12px 28px rgba(0, 0, 0, .14);
        position: absolute;
        right: 0;
        top: calc(100% + 8px);
        width: 320px;
        z-index: 4;
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
        grid-template-rows: auto minmax(0, 1fr);
        min-height: 0;
        min-width: 0;
        overflow: hidden;
    }
    .fp-conflict-workbench {
        display: none;
        min-height: 0;
        min-width: 0;
        overflow: hidden;
    }
    .fp-conflict-workbench.is-visible {
        display: grid;
        grid-template-rows: auto minmax(0, 1fr);
    }
    .fp-conflict-workbench-body {
        display: grid;
        gap: 10px;
        min-height: 0;
        overflow: hidden;
        padding: 10px 12px;
    }
    body.fp-reviewing .fp-conflict-workbench-body {
        overflow: hidden;
        overscroll-behavior: contain;
    }
    .fp-filterbar {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }
    .fp-filterbar button {
        background: #f6f7f7;
        border-color: var(--line);
        color: var(--muted);
        font-size: 12px;
        min-height: 28px;
        padding: 4px 8px;
    }
    .fp-filterbar button.is-active {
        background: #edf6ff;
        border-color: #72aee6;
        color: #135e96;
    }
    .fp-filter-count {
        color: inherit;
        font-size: 11px;
        font-weight: 700;
        margin-left: 4px;
    }
    .fp-conflict-review-grid {
        align-items: stretch;
        display: grid;
        gap: 12px;
        grid-template-columns: minmax(560px, 1.35fr) minmax(300px, .75fr);
        min-height: 0;
        min-width: 0;
        overflow: hidden;
    }
    .fp-conflict-review-grid > * { min-height: 0; min-width: 0; }
    .fp-conflict-table-wrap {
        border: 1px solid var(--line);
        border-radius: 6px;
        max-height: none;
        min-width: 0;
        overflow: auto;
    }
    .fp-conflict-table {
        border-collapse: collapse;
        table-layout: fixed;
        width: 100%;
    }
    .fp-conflict-table th:nth-child(1), .fp-conflict-table td:nth-child(1) { width: 74px; }
    .fp-conflict-table th:nth-child(2), .fp-conflict-table td:nth-child(2) { width: 20%; }
    .fp-conflict-table th:nth-child(3), .fp-conflict-table td:nth-child(3) { width: 25%; }
    .fp-conflict-table th:nth-child(4), .fp-conflict-table td:nth-child(4) { width: auto; }
    .fp-conflict-table th {
        background: #f6f7f7;
        border-bottom: 1px solid var(--line);
        color: var(--muted);
        font-size: 10px;
        font-weight: 700;
        padding: 6px 7px;
        position: sticky;
        text-align: left;
        text-transform: uppercase;
        top: 0;
        vertical-align: top;
        z-index: 1;
    }
    .fp-conflict-table td {
        border-top: 1px solid #f0f0f1;
        font-size: 11.5px;
        line-height: 1.28;
        max-width: 220px;
        overflow-wrap: anywhere;
        padding: 7px;
        vertical-align: top;
    }
    .fp-conflict-table tbody tr:hover { background: #f6fbff; }
    .fp-conflict-table tbody tr { cursor: pointer; }
    .fp-conflict-table tbody tr.is-selected { background: #eaf4ff; box-shadow: inset 3px 0 0 var(--accent); }
    .fp-table-primary { font-weight: 700; }
    .fp-table-muted {
        color: var(--muted);
        font-size: 11px;
        margin-top: 3px;
    }
    .fp-conflict-table .fp-table-primary,
    .fp-conflict-table .fp-table-muted {
        display: -webkit-box;
        overflow: hidden;
        -webkit-box-orient: vertical;
    }
    .fp-conflict-table .fp-table-primary { -webkit-line-clamp: 3; }
    .fp-conflict-table .fp-table-muted { -webkit-line-clamp: 2; }
    .fp-review-progress {
        align-items: center;
        color: var(--muted);
        display: inline-flex;
        font-size: 12px;
        font-weight: 700;
        min-height: 32px;
        padding: 0 2px;
        white-space: nowrap;
    }
    .fp-toolbar-more {
        position: relative;
    }
    .fp-toolbar-more summary {
        align-items: center;
        background: #fff;
        border: 1px solid #8c8f94;
        border-radius: 6px;
        color: var(--ink);
        cursor: pointer;
        display: inline-flex;
        font-size: 13px;
        font-weight: 600;
        list-style: none;
        min-height: 32px;
        padding: 6px 10px;
    }
    .fp-toolbar-more summary::-webkit-details-marker { display: none; }
    .fp-toolbar-more[open] summary {
        border-color: var(--accent);
        color: var(--accent-strong);
    }
    .fp-toolbar-more-body {
        background: #fff;
        border: 1px solid var(--line);
        border-radius: 8px;
        box-shadow: 0 12px 28px rgba(0, 0, 0, .14);
        display: grid;
        gap: 6px;
        padding: 8px;
        position: absolute;
        right: 0;
        top: calc(100% + 6px);
        width: 220px;
        z-index: 5;
    }
    .fp-toolbar-more-body .fp-button,
    .fp-toolbar-more-body button {
        justify-content: flex-start;
        width: 100%;
    }
    .fp-conflict-details {
        display: grid;
        gap: 10px;
    }
    .fp-conflict {
        border: 1px solid var(--line);
        border-left: 4px solid var(--conflict);
        border-radius: 6px;
    }
    .fp-conflict-inspector {
        display: grid;
        grid-template-rows: auto auto minmax(0, 1fr);
        max-height: 100%;
        min-height: 0;
        overflow: hidden;
        padding: 0;
    }
    #fp-conflict-inspector-slot {
        min-height: 0;
        overflow: hidden;
    }
    .fp-conflict-inspector-empty {
        background: #f6f7f7;
        border: 1px solid var(--line);
        border-radius: 6px;
        color: var(--muted);
        font-size: 12px;
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
    .fp-crash-recovery {
        background: #fff8e5;
        border: 1px solid #dba617;
        border-left: 4px solid var(--conflict);
        border-radius: 6px;
        display: grid;
        gap: 8px;
        padding: 12px;
    }
    .fp-crash-recovery strong { color: #713f00; }
    .fp-crash-recovery code {
        background: rgba(255, 255, 255, .72);
        border: 1px solid rgba(113, 63, 0, .22);
        border-radius: 5px;
        display: block;
        font-size: 12px;
        overflow-wrap: anywhere;
        padding: 7px 8px;
    }
    .fp-conflict-title { font-size: 13px; font-weight: 700; overflow-wrap: anywhere; }
    .fp-conflict-card-head {
        background: #fff;
        border-bottom: 1px solid #f0f0f1;
        display: grid;
        gap: 6px;
        min-width: 0;
        padding: 10px 12px 8px;
    }
    .fp-conflict-title-row {
        align-items: center;
        display: flex;
        gap: 8px;
        justify-content: space-between;
        min-width: 0;
    }
    .fp-conflict-title-row .fp-conflict-title {
        min-width: 0;
    }
    .fp-conflict-contextline {
        color: var(--muted);
        font-size: 12px;
        line-height: 1.35;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .fp-conflict-card-controls {
        background: #fff;
        border-bottom: 1px solid #f0f0f1;
        display: grid;
        gap: 8px;
        padding: 8px 12px;
    }
    .fp-conflict-scroll {
        display: grid;
        gap: 10px;
        min-height: 0;
        overflow: auto;
        overscroll-behavior: contain;
        padding: 10px 12px 12px;
    }
    .fp-conflict-inspector .fp-summary-chips {
        gap: 4px;
    }
    .fp-conflict-inspector .fp-chip {
        font-size: 10.5px;
        padding: 3px 6px;
    }
    .fp-chip.ok {
        background: #edfaef;
        border-color: #b8e6c1;
        color: #005c12;
        font-weight: 700;
    }
    .fp-chip.danger {
        background: #fcf0f1;
        border-color: #f0c0c4;
        color: #8a2424;
        font-weight: 700;
    }
    .fp-conflict-meta { color: var(--muted); font-size: 12px; overflow-wrap: anywhere; }
    .fp-conflict-guidance {
        background: #f6f7f7;
        border: 1px solid var(--line);
        border-radius: 6px;
        color: #2c3338;
        display: grid;
        gap: 4px;
        font-size: 12px;
        line-height: 1.35;
        padding: 8px 10px;
    }
    .fp-conflict-guidance strong {
        color: var(--muted);
        font-size: 10px;
        text-transform: uppercase;
    }
    .fp-disclosure {
        border: 1px solid var(--line);
        border-radius: 6px;
        overflow: hidden;
    }
    .fp-disclosure summary {
        background: #f6f7f7;
        cursor: pointer;
        font-size: 12px;
        font-weight: 700;
        padding: 8px 10px;
    }
    .fp-disclosure[open] summary {
        border-bottom: 1px solid var(--line);
    }
    .fp-disclosure-body {
        display: grid;
        gap: 8px;
        padding: 8px 10px 10px;
    }
    .fp-conflict-plugin {
        background: #f6f7f7;
        border: 1px solid var(--line);
        border-radius: 6px;
        display: grid;
        gap: 6px;
        grid-template-columns: repeat(2, minmax(0, 1fr));
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
    .fp-conflict-inspector .fp-conflict-grid {
        grid-template-columns: 1fr;
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
        font-size: 11.5px;
        min-height: 52px;
        padding: 6px 7px;
        resize: vertical;
        width: 100%;
    }
    textarea[readonly] { background: #f6f7f7; color: #1d2327; }
    .fp-conflict-value textarea { min-height: 82px; }
    .fp-conflict-note textarea { min-height: 42px; }
    .fp-conflict-note, .fp-conflict-choice {
        display: grid;
        gap: 4px;
    }
    .fp-conflict-action-row {
        align-items: center;
        background: #fff;
    }
    pre {
        background: #f6f7f7;
        border: 1px solid var(--line);
        border-radius: 6px;
        font-size: 12px;
        max-height: 150px;
        overflow: auto;
        padding: 10px;
        white-space: pre-wrap;
    }
    @media (max-width: 980px) {
        .fp-layout { grid-template-rows: minmax(340px, 1fr) auto; height: auto; overflow: visible; padding: 8px; }
        body { overflow: auto; }
        .fp-topbar { flex-wrap: wrap; gap: 8px 12px; padding: 10px 14px; }
        .fp-open-admin { flex-basis: 100%; margin-left: 0; }
        .fp-graph-head {
            align-items: stretch;
            flex-direction: column;
        }
        .fp-graph-head .fp-buttons { width: 100%; }
        .fp-bottom { max-height: none; }
        .fp-bottom > .fp-detail-head {
            align-items: stretch;
            flex-direction: column;
        }
        .fp-bottom-body { grid-template-columns: 1fr; overflow: visible; }
        .fp-bottom-primary { grid-column: auto; }
        .fp-bottom-actions { margin-left: 0; width: 100%; }
        .fp-bottom-actions .fp-actions { flex: 1 1 100%; }
        .fp-bottom-actions details.fp-actions summary { width: 100%; }
        .fp-bottom-actions .fp-actions-body { position: static; width: 100%; box-shadow: none; }
        .fp-conflict-review-grid { grid-template-columns: 1fr; }
        .fp-conflict-table,
        .fp-conflict-table tbody,
        .fp-conflict-table tr,
        .fp-conflict-table td {
            display: block;
            width: 100%;
        }
        .fp-conflict-table thead { display: none; }
        .fp-conflict-table tr {
            border-top: 1px solid var(--line);
            padding: 8px 0;
        }
        .fp-conflict-table tr:first-child { border-top: 0; }
        .fp-conflict-table td {
            border-top: 0;
            display: grid;
            gap: 6px;
            grid-template-columns: 78px minmax(0, 1fr);
            max-width: none;
            padding: 4px 8px;
            width: 100% !important;
        }
        .fp-conflict-table td::before {
            color: var(--muted);
            content: attr(data-label);
            font-size: 10px;
            font-weight: 700;
            grid-row: 1 / span 2;
            text-transform: uppercase;
        }
        .fp-conflict-table td .fp-table-primary,
        .fp-conflict-table td .fp-table-muted {
            display: block;
            grid-column: 2;
            overflow: visible;
            -webkit-line-clamp: unset;
        }
        .fp-conflict-table tbody tr.is-selected {
            background: #eaf4ff;
            box-shadow: inset 3px 0 0 var(--accent);
        }
        .fp-conflict-grid { grid-template-columns: 1fr; }
        .fp-conflict-plugin { grid-template-columns: 1fr; }
        body.fp-reviewing .fp-conflict-workbench { order: -1; }
        .fp-conflict-workbench > .fp-detail-head {
            align-items: stretch;
            flex-direction: column;
        }
        .fp-conflict-workbench .fp-buttons { width: 100%; }
        .fp-conflict-workbench .fp-buttons .fp-button {
            flex: 1 1 100%;
            justify-content: center;
            white-space: normal;
        }
        .fp-conflict-workbench .fp-buttons {
            flex-wrap: nowrap;
            overflow-x: auto;
            padding-bottom: 2px;
        }
        .fp-conflict-workbench .fp-buttons button,
        .fp-conflict-workbench .fp-buttons .fp-button,
        .fp-conflict-workbench .fp-buttons .fp-conflict-meta {
            flex: 0 0 auto;
            justify-content: center;
            white-space: nowrap;
        }
        .fp-conflict-contextline {
            overflow: visible;
            white-space: normal;
        }
        body.fp-reviewing .fp-layout { grid-template-rows: minmax(210px, 30vh) auto; }
        body.fp-reviewing .fp-graph-wrap {
            max-height: 250px;
            min-height: 190px !important;
        }
        body.fp-reviewing .fp-legend { display: none; }
    }
    body.fp-reviewing .fp-graph-wrap { min-height: 180px; }
    body.fp-reviewing .fp-conflict-table-wrap,
    body.fp-reviewing .fp-conflict-inspector { max-height: none; }
</style>
</head>
<body>
<div class="fp-shell">
    <header class="fp-topbar">
        <div class="fp-brand">ForkPress Branches</div>
        <div class="fp-current" id="fp-current"></div>
        <a class="fp-open-admin" id="fp-admin-link" href="#">Current branch details</a>
    </header>
    <main class="fp-layout">
        <section class="fp-panel fp-graph-panel">
            <div class="fp-graph-head">
                <div class="fp-graph-intro">
                    <h1>Branch Graph</h1>
                    <div class="fp-muted" id="fp-graph-summary"></div>
                    <div class="fp-legend" aria-label="Graph legend">
                        <span><i class="revision"></i>revision</span>
                        <span><i class="merge"></i>merge event</span>
                        <span><i class="conflicts"></i>conflicts</span>
                        <span><i class="arrow"></i>into target</span>
                    </div>
                </div>
                <div class="fp-buttons">
                    <button type="button" id="fp-focus-selected">Focus selected</button>
                    <button type="button" id="fp-refresh">Refresh</button>
                    <button type="button" id="fp-load-history">History</button>
                </div>
            </div>
            <div class="fp-graph-wrap">
                <svg class="fp-graph" id="fp-graph" role="img" aria-label="ForkPress branch graph"></svg>
            </div>
        </section>
        <section class="fp-panel fp-bottom" id="fp-bottom-pane">
            <div class="fp-detail-head">
                <div class="fp-workbench-title">
                    <h2>Branch Workbench</h2>
                    <div class="fp-muted" id="fp-workbench-mode" aria-live="polite">Select a branch, revision, or merge event.</div>
                </div>
                <section class="fp-bottom-actions">
                <details class="fp-actions" id="fp-fork-actions" aria-label="Fork a branch from the selected context">
                    <summary>Fork from here</summary>
                    <div class="fp-actions-body">
                        <form class="fp-form" id="fp-create">
                            <div class="fp-field"><label for="fp-create-name">New branch</label><input id="fp-create-name" name="branch" pattern="[A-Za-z0-9_-]{1,63}" autocomplete="off" required></div>
                            <div class="fp-field"><label for="fp-create-from">From</label><select id="fp-create-from" name="from"></select></div>
                            <button class="primary" type="submit">Create branch</button>
                        </form>
                    </div>
                </details>
                <details class="fp-actions" id="fp-merge-actions" aria-label="Merge the selected revision branch">
                    <summary>Merge this revision</summary>
                    <div class="fp-actions-body">
                        <form class="fp-form" id="fp-merge">
                            <div class="fp-field"><label for="fp-merge-source">Source</label><select id="fp-merge-source" name="source"></select></div>
                            <div class="fp-field"><label for="fp-merge-target">Target</label><select id="fp-merge-target" name="target"></select></div>
                            <button type="submit">Merge branch</button>
                        </form>
                    </div>
                </details>
                </section>
            </div>
            <div class="fp-bottom-body">
                <section class="fp-bottom-primary">
                <div class="fp-detail-head">
                    <h2 id="fp-detail-title">Selection</h2>
                </div>
                <div class="fp-detail-body">
                    <div class="fp-status" id="fp-status" role="status" aria-live="polite"></div>
                    <div class="fp-kv" id="fp-detail"></div>
                    <div class="fp-buttons" id="fp-detail-actions"></div>
                    <pre id="fp-raw"></pre>
                </div>
                </section>
                <section class="fp-conflict-workbench" id="fp-conflict-workbench">
                    <div class="fp-detail-head">
                        <div>
                            <h2 id="fp-conflict-title">Conflicts</h2>
                            <div class="fp-muted" id="fp-conflict-summary">Select a conflicting revision to inspect entity-level details.</div>
                        </div>
                        <div class="fp-buttons" id="fp-conflict-actions"></div>
                    </div>
                    <div class="fp-conflict-workbench-body">
                        <div class="fp-conflicts" id="fp-conflicts"></div>
                    </div>
                </section>
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
    var conflictActions = document.getElementById('fp-conflict-actions');
    var conflicts = document.getElementById('fp-conflicts');
    var workbenchMode = document.getElementById('fp-workbench-mode');
    var raw = document.getElementById('fp-raw');
    var status = document.getElementById('fp-status');
    var current = document.getElementById('fp-current');
    var adminLink = document.getElementById('fp-admin-link');
    var forkActions = document.getElementById('fp-fork-actions');
    var mergeActions = document.getElementById('fp-merge-actions');
    var createForm = document.getElementById('fp-create');
    var mergeForm = document.getElementById('fp-merge');
    var createName = document.getElementById('fp-create-name');
    var createFrom = document.getElementById('fp-create-from');
    var mergeSource = document.getElementById('fp-merge-source');
    var mergeTarget = document.getElementById('fp-merge-target');
    var initialParams = new URLSearchParams(window.location.search);
    var initialRunId = initialParams.get('run') || '';
    var initialBranchName = initialParams.get('branch') || '';
    var records = [];
    var selectedRunId = null;
    var selectedBranchName = '';
    var selectedConflictId = initialParams.get('conflict') || null;
    var selectedConflictFilter = normalizeConflictFilter(initialParams.get('filter') || 'all');
    var didRestoreInitialSelection = false;

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
    function setWorkbenchMode(message) {
        workbenchMode.textContent = message || 'Select a branch, revision, or merge event.';
    }
    function statusKindForPayload(payload, fallback) {
        var type = String(payload && payload.type || '');
        if (type === 'warning') return 'warn';
        if (type === 'error') return 'error';
        if (type === 'notice' || type === 'success') return 'ok';
        return fallback || 'ok';
    }
    function syncReviewUrl() {
        if (!window.history || !window.history.replaceState) return;
        var params = new URLSearchParams(window.location.search);
        if (selectedBranchName && !selectedRunId) params.set('branch', selectedBranchName);
        else params.delete('branch');
        if (selectedRunId) params.set('run', selectedRunId);
        else params.delete('run');
        if (selectedConflictId) params.set('conflict', selectedConflictId);
        else params.delete('conflict');
        if (selectedConflictFilter && selectedConflictFilter !== 'all') params.set('filter', selectedConflictFilter);
        else params.delete('filter');
        var query = params.toString();
        window.history.replaceState(null, '', window.location.pathname + (query ? '?' + query : ''));
    }
    function showConflictWorkbench(title, message) {
        document.body.classList.add('fp-reviewing');
        conflictWorkbench.className = 'fp-conflict-workbench is-visible';
        conflictTitle.textContent = title || 'Conflicts';
        conflictSummaryText.textContent = message || '';
        setWorkbenchMode(title || 'Conflicts');
    }
    function hideConflictWorkbench() {
        document.body.classList.remove('fp-reviewing');
        conflictWorkbench.className = 'fp-conflict-workbench';
        conflicts.innerHTML = '';
        conflictActions.innerHTML = '';
        conflictSummaryText.textContent = 'Select a conflicting revision to inspect entity-level details.';
        setWorkbenchMode();
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
    function setSelectValue(select, value) {
        value = String(value || '');
        if (!select || !value) return;
        Array.prototype.slice.call(select.options || []).forEach(function (option) {
            option.selected = option.value === value;
        });
    }
    function selectedContextRun() {
        return selectedRunId ? runById(selectedRunId) : null;
    }
    function selectedContextBranch() {
        var run = selectedContextRun();
        if (run) return String(run.target_branch || run.source_branch || state.currentBranch || 'main');
        return String(state.currentBranch || 'main');
    }
    function configureForkAction() {
        setSelectValue(createFrom, selectedContextBranch());
        createName.focus();
    }
    function configureMergeAction() {
        var run = selectedContextRun();
        var firstNonMain = state.branches.find(function (item) { return item.name !== 'main'; });
        var source = selectedContextBranch();
        var target = 'main';
        if (run && isMergeRun(run)) {
            source = String(run.source_branch || source);
            target = String(run.target_branch || target);
        } else if (source === 'main' && firstNonMain) {
            source = firstNonMain.name;
        }
        setSelectValue(mergeSource, source);
        setSelectValue(mergeTarget, target);
        mergeSource.focus();
    }
    function closeActionDetails(except) {
        [forkActions, mergeActions].forEach(function (node) {
            if (node && node !== except) node.open = false;
        });
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
        return { total: total, resolved: resolved, unresolved: unresolved, hasResolutionCounts: !!(summary && summary.hasResolutionCounts) };
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
    function humanStatus(value) {
        value = String(value || '');
        if (value === 'completed_with_conflicts') return 'merged with conflicts';
        if (value === 'revalidate') return 'check again';
        if (value === 'validated') return 'checked';
        return value.replace(/_/g, ' ');
    }
    function humanConflictType(value) {
        value = String(value || '');
        if (value === 'plugin-validator-conflict') return 'plugin check conflict';
        if (value === 'plugin-theme-template-conflict') return 'theme template conflict';
        return humanStatus(value);
    }
    function conflictPendingChoiceCount(summary) {
        return Number(summary && summary.unresolved || 0);
    }
    function conflictAppliedChoiceCount(summary) {
        return Number(summary && summary.resolved || 0);
    }
    function conflictResolutionSummaryText(summary) {
        if (!summary || !Number(summary.total || 0)) return 'none';
        var applied = conflictAppliedChoiceCount(summary);
        var pending = conflictPendingChoiceCount(summary);
        var parts = [String(summary.total || 0) + ' conflicts'];
        if (summary.hasResolutionCounts) {
            if (applied > 0) parts.push(String(applied) + ' choices applied');
            if (pending > 0) parts.push(String(pending) + ' without explicit choice');
        }
        return parts.join(' / ');
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
    function branchLabelText(name) {
        name = String(name || '');
        if (name.length <= 8) return name;
        var parts = name.split(/[-_]/).filter(Boolean);
        if (parts.length > 1) {
            var first = parts[0].slice(0, Math.min(8, parts[0].length));
            if (first.length >= 4) return first;
            return (first + '-' + parts[1].slice(0, 3)).slice(0, 8);
        }
        return name.slice(0, 7) + '...';
    }
    function isMergeRun(run) {
        var source = String(run && run.source_branch || '');
        var target = String(run && run.target_branch || source || '');
        return source !== '' && target !== '' && source !== target;
    }
    function runDetailTitle(run) {
        var id = '#' + String(run && run.id || '');
        if (isBranchForkRun(run)) return 'Branch birth ' + id;
        if (isMergeRun(run)) return 'Merge ' + id;
        return 'Revision ' + id;
    }
    function runLabel(run) {
        var source = String(run.source_branch || '?');
        var target = String(run.target_branch || source || '?');
        var prefix = '#' + String(run.id || '');
        if (source === target) {
            return prefix + ' revision on ' + source;
        }
        return prefix + ' merge into ' + target + ' (from ' + source + ')';
    }
    function runMeta(run) {
        var status = String(run.status || '');
        var source = String(run && run.source_branch || '');
        var target = String(run && run.target_branch || source || '');
        var parts = [];
        if (source && target && source !== target) {
            parts.push('merge event');
            parts.push('source ' + source + ' -> target ' + target);
        } else if (source) {
            parts.push('branch ' + source);
        }
        if (status) parts.push(humanStatus(status));
        var conflict = conflictSummary(run);
        if (conflict.total > 0) {
            parts.push('merge result exists');
            parts.push(String(conflict.total) + ' conflicts');
        } else if (Number(run.decision_count || 0) > 0) {
            parts.push(String(run.decision_count) + ' decisions');
        }
        var when = run.finished_at || run.started_at || '';
        if (when) parts.push(when);
        return parts.filter(Boolean).join('  ·  ');
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
        var graphLeft = 44;
        var laneGap = 30;
        var graphWidth = Math.max(1, lanes.length) * laneGap;
        var textX = graphLeft + graphWidth + 30;
        var top = 38;
        var rowGap = 46;
        var rowHeight = 40;
        var availableWidth = graph.parentNode && graph.parentNode.clientWidth ? graph.parentNode.clientWidth - 20 : 0;
        var width = Math.max(900, availableWidth, textX + 460);
        var height = Math.max(320, top + Math.max(entries.length, 1) * rowGap + 24);
        graph.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
        graph.setAttribute('width', width);
        graph.setAttribute('height', height);
        graph.innerHTML = '';
        var defs = svg('defs', {});
        var marker = svg('marker', {
            id: 'fp-merge-arrow',
            markerHeight: 5,
            markerWidth: 6,
            orient: 'auto',
            refX: 5.5,
            refY: 2.5,
            viewBox: '0 0 6 5'
        });
        marker.appendChild(svg('path', { d: 'M 0 0 L 6 2.5 L 0 5 z', fill: '#50575e' }));
        defs.appendChild(marker);
        graph.appendChild(defs);
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
        lanes.forEach(function (name, index) {
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
            var cap = svg('line', { x1: x[name] - 5, x2: x[name] + 5, y1: startY, y2: startY, class: 'fp-lane-boundary', stroke: color });
            cap.appendChild(svg('title', {})).textContent = name + ' latest visible revision';
            nodeLayer.appendChild(cap);
            var end = svg('line', { x1: x[name] - 5, x2: x[name] + 5, y1: endY, y2: endY, class: 'fp-lane-boundary', stroke: color });
            end.appendChild(svg('title', {})).textContent = name + ' oldest visible revision';
            nodeLayer.appendChild(end);
            if (span.first < 6) {
                var labelText = branchLabelText(name);
                var labelY = Math.max(18, startY + 8 + (index % 4) * 12);
                var labelX = x[name] + 7;
                var labelBg = svg('rect', {
                    x: labelX - 4,
                    y: labelY - 10,
                    width: Math.max(22, labelText.length * 5.9 + 8),
                    height: 13,
                    rx: 4,
                    class: 'fp-lane-label-bg'
                });
                labelBg.appendChild(svg('title', {})).textContent = name;
                textLayer.appendChild(labelBg);
                var label = svg('text', { x: labelX, y: labelY, class: 'fp-lane-label' });
                label.textContent = labelText;
                label.appendChild(svg('title', {})).textContent = name;
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
            var isMerge = isMergeRun(run);
            var isFork = isBranchForkRun(run);
            var isSelected = String(run && run.id || '') === String(selectedRunId || '');
            var forkParent = forkParents[source] || 'main';
            var px = x[forkParent] !== undefined ? x[forkParent] : graphLeft;
            if (isSelected) {
                rowLayer.appendChild(svg('rect', {
                    x: 0,
                    y: y - rowHeight / 2,
                    width: width - 20,
                    height: rowHeight,
                    rx: 7,
                    class: 'fp-row-selected'
                }));
            }
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
            }
            if (isMerge) {
                var bend = Math.max(32, Math.abs(tx - sx) / 2);
                var arrowEndX = tx + (sx < tx ? -13 : 13);
                var arrowControlX = arrowEndX + (sx < tx ? -bend : bend);
                var mergePath = svg('path', {
                    d: 'M ' + sx + ' ' + y + ' C ' + sx + ' ' + (y - 24) + ', ' + arrowControlX + ' ' + (y - 24) + ', ' + arrowEndX + ' ' + y,
                    class: 'fp-timeline-merge' + (visual.indexOf('is-conflict') !== -1 ? ' is-conflict' : '') + (visual.indexOf('is-resolved') !== -1 ? ' is-resolved' : ''),
                    stroke: visual.indexOf('is-conflict') !== -1 ? '#b35c00' : (visual.indexOf('is-resolved') !== -1 ? '#008a20' : sourceColor),
                    'marker-end': 'url(#fp-merge-arrow)',
                    'data-kind': 'run',
                    'data-index': entry.index
                });
                mergePath.appendChild(svg('title', {})).textContent = 'Merge #' + String(run.id || '') + ': ' + source + ' into ' + target;
                edgeLayer.appendChild(mergePath);
                if (isSelected) {
                    var flowLabel = branchLabelText(source) + ' -> ' + branchLabelText(target);
                    var flowX = Math.max(sx, tx) + 16;
                    var flowY = y - 28;
                    var flowWidth = Math.max(42, flowLabel.length * 5.4 + 8);
                    var flowBg = svg('rect', {
                        x: flowX - 4,
                        y: flowY - 10,
                        width: flowWidth,
                        height: 13,
                        rx: 4,
                        class: 'fp-merge-flow-bg'
                    });
                    flowBg.appendChild(svg('title', {})).textContent = 'Merge #' + String(run.id || '') + ': ' + source + ' into ' + target;
                    textLayer.appendChild(flowBg);
                    var flow = svg('text', { x: flowX, y: flowY, class: 'fp-merge-flow' });
                    flow.textContent = flowLabel;
                    flow.appendChild(svg('title', {})).textContent = 'Merge #' + String(run.id || '') + ': ' + source + ' into ' + target;
                    textLayer.appendChild(flow);
                }
            }
            var nodeFill = visual.indexOf('is-conflict') !== -1 ? '#b35c00' : (visual.indexOf('is-resolved') !== -1 ? '#008a20' : targetColor);
            var node = isMerge ? svg('path', {
                d: 'M ' + tx + ' ' + (y - 11) + ' L ' + (tx + 11) + ' ' + y + ' L ' + tx + ' ' + (y + 11) + ' L ' + (tx - 11) + ' ' + y + ' Z',
                fill: nodeFill,
                class: 'fp-node fp-node-merge' + visual + (isSelected ? ' is-selected' : ''),
                'data-kind': 'run',
                'data-index': entry.index
            }) : svg('circle', {
                cx: tx,
                cy: y,
                r: visual.indexOf('is-setup') !== -1 ? 8 : 11,
                fill: nodeFill,
                class: 'fp-node' + visual + (isSelected ? ' is-selected' : ''),
                'data-kind': 'run',
                'data-index': entry.index
            });
            node.appendChild(svg('title', {})).textContent = isFork ? ('#' + String(run.id || '') + ' ' + source + ' branched from ' + forkParent) : (isMerge ? ('Merge event #' + String(run.id || '') + ': source ' + source + ' into target ' + target) : ('Revision #' + String(run.id || '') + ' on ' + target + ' / ' + String(run.status || '')));
            nodeLayer.appendChild(node);
            var title = svg('text', { x: textX, y: y - 6, class: 'fp-row-title' });
            title.textContent = isFork ? ('#' + String(run.id || '') + ' ' + source + ' branched from ' + forkParent) : runLabel(run);
            textLayer.appendChild(title);
            var meta = svg('text', { x: textX, y: y + 12, class: 'fp-row-meta' });
            meta.textContent = runMeta(run);
            textLayer.appendChild(meta);
            if (conflict.total > 0) {
                var allApplied = !!conflict.hasResolutionCounts && conflictPendingChoiceCount(conflict) === 0 && hasConflictSummary(run);
                var badgeText = String(conflict.total);
                var badgeWidth = Math.max(28, 14 + badgeText.length * 7);
                var badgeX = tx + 10;
                var badgeY = y - 27;
                var badgeTitle = badgeText + ' conflict' + (badgeText === '1' ? '' : 's') + (allApplied ? ' with applied choices' : '');
                var badgeRect = svg('rect', { x: badgeX, y: badgeY, width: badgeWidth, height: 16, rx: 5, class: 'fp-conflict-badge' + (allApplied ? ' is-resolved' : '') });
                badgeRect.appendChild(svg('title', {})).textContent = badgeTitle;
                nodeLayer.appendChild(badgeRect);
                var badge = svg('text', { x: badgeX + badgeWidth / 2, y: badgeY + 11, class: 'fp-badge-text', 'text-anchor': 'middle' });
                badge.textContent = badgeText;
                badge.appendChild(svg('title', {})).textContent = badgeTitle;
                nodeLayer.appendChild(badge);
            }
            hitLayer.appendChild(svg('rect', {
                x: 0,
                y: y - rowHeight / 2,
                width: width,
                height: rowHeight,
                class: 'fp-row-hit',
                'data-kind': 'run',
                'data-index': entry.index,
                role: 'button',
                tabindex: '0',
                'aria-label': runLabel(run)
            }));
        });
        summary.textContent = lanes.length + ' lanes / ' + entries.length + ' events / newest first';
    }
    function scrollSelectedRunIntoView() {
        if (!selectedRunId || !graph.parentNode) return;
        var entries = sortedRunEntries();
        var index = entries.findIndex(function (entry) {
            return String(entry.run && entry.run.id || '') === String(selectedRunId);
        });
        if (index < 0) return;
        var rowY = 38 + index * 46;
        var wrap = graph.parentNode;
        var currentTop = wrap.scrollTop || 0;
        var currentBottom = currentTop + (wrap.clientHeight || 0);
        if (rowY > currentTop + 60 && rowY < currentBottom - 80) return;
        wrap.scrollTop = Math.max(0, rowY - Math.max(120, (wrap.clientHeight || 0) / 2));
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
            if (cells.length < 2 || cells[0].textContent !== 'resolution state') return false;
            cells[1].textContent = conflictResolutionSummaryText(summary);
            return true;
        });
        return true;
    }
    function link(label, href) {
        var a = document.createElement('a');
        a.className = 'fp-button';
        a.href = href;
        a.textContent = label;
        a.title = label;
        return a;
    }
    function copyTextButton(label, value, doneMessage) {
        return button(label, function () {
            var text = String(value || '');
            if (!text) {
                setStatus('warn', 'No URL is available for this branch.');
                return;
            }
            if (!navigator.clipboard || !navigator.clipboard.writeText) {
                setStatus('warn', text);
                return;
            }
            return navigator.clipboard.writeText(text).then(function () {
                setStatus('ok', doneMessage || 'URL copied.');
            }).catch(function () {
                setStatus('warn', text);
            });
        });
    }
    function branchManagerHref(name) {
        var url = new URL(state.rootUrl || '/_forkpress/branches', window.location.href);
        url.searchParams.set('branch', String(name || ''));
        return url.href;
    }
    function branchManagerLink(label, name) {
        var a = link(label, branchManagerHref(name));
        a.addEventListener('click', function (event) {
            event.preventDefault();
            selectBranch(name);
        });
        return a;
    }
    function appendBranchPreviewLinks(container, source, target) {
        if (source) container.appendChild(branchManagerLink('Show source: ' + source, source));
        if (target) container.appendChild(branchManagerLink('Show target: ' + target, target));
    }
    function appendReviewLinkAction(container) {
        container.appendChild(button('Copy conflict link', function () {
            syncReviewUrl();
            if (!navigator.clipboard || !navigator.clipboard.writeText) {
                setStatus('warn', 'Copy is not available in this browser. Use the current address bar URL.');
                return;
            }
            navigator.clipboard.writeText(window.location.href).then(function () {
                setStatus('ok', 'Conflict link copied.');
            }).catch(function () {
                setStatus('warn', 'Could not copy automatically. Use the current address bar URL.');
            });
        }));
    }
    function appendReviewMoreActions(container, source, target) {
        var details = document.createElement('details');
        details.className = 'fp-toolbar-more';
        details.appendChild(textNode('summary', '', 'More'));
        var body = document.createElement('div');
        body.className = 'fp-toolbar-more-body';
        appendBranchPreviewLinks(body, source, target);
        appendReviewLinkAction(body);
        details.appendChild(body);
        container.appendChild(details);
    }
    function button(label, fn, extraClass) {
        var b = document.createElement('button');
        b.type = 'button';
        b.textContent = label;
        b.title = label;
        if (extraClass) b.className = extraClass;
        b.addEventListener('click', function () {
            return runButtonAction(b, label, function () { return fn(b); });
        });
        return b;
    }
    function setButtonBusy(buttonNode, busy) {
        if (!buttonNode) return;
        buttonNode.disabled = !!busy;
        buttonNode.classList.toggle('is-busy', !!busy);
        buttonNode.setAttribute('aria-busy', busy ? 'true' : 'false');
    }
    function runButtonAction(buttonNode, label, work) {
        if (buttonNode && buttonNode.disabled) return Promise.resolve();
        setButtonBusy(buttonNode, true);
        var result;
        try {
            result = work();
        } catch (error) {
            setButtonBusy(buttonNode, false);
            setStatus('error', error && error.message ? error.message : ('Could not run ' + String(label || 'action') + '.'));
            return Promise.reject(error);
        }
        return Promise.resolve(result).then(function (value) {
            setButtonBusy(buttonNode, false);
            return value;
        }, function (error) {
            setButtonBusy(buttonNode, false);
            throw error;
        });
    }
    function textNode(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        node.textContent = text === undefined || text === null ? '' : String(text);
        return node;
    }
    function setConflictSummaryChips(flow, summary) {
        conflictSummaryText.innerHTML = '';
        var wrap = document.createElement('div');
        wrap.className = 'fp-summary-chips';
        if (flow) wrap.appendChild(textNode('span', 'fp-chip strong', flow));
        if (summary && summary.total !== undefined) {
            var total = Number(summary.total || 0);
            var pending = conflictPendingChoiceCount(summary);
            var applied = conflictAppliedChoiceCount(summary);
            wrap.appendChild(textNode('span', 'fp-chip', String(total) + ' conflicts'));
            if (summary.hasResolutionCounts) {
                if (applied > 0) wrap.appendChild(textNode('span', 'fp-chip ok', String(applied) + ' choices applied'));
                if (pending > 0) wrap.appendChild(textNode('span', 'fp-chip warn', String(pending) + ' without explicit choice'));
            }
        }
        conflictSummaryText.appendChild(wrap);
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
    function compactValuePreview(value) {
        value = value === undefined || value === null ? '' : String(value);
        value = value.replace(/\s+/g, ' ').trim();
        if (value === '' || value === 'null') return 'empty';
        if (value.length > 220) return 'too long to display';
        if (value.length > 72) return value.slice(0, 69) + '...';
        return value;
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
            field: humanConflictType(field),
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
            record.plugin_validator ? 'plugin check: ' + String(record.plugin_validator) : ''
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
    function pluginDriverFor(record) {
        var drivers = Array.isArray(state.pluginDrivers) ? state.pluginDrivers : [];
        var plugin = String(record && record.plugin || '');
        if (!plugin) return null;
        return drivers.find(function (driver) { return String(driver.plugin || '') === plugin; }) || null;
    }
    function pluginDriverMissingMessage(record) {
        var object = String(record && record.plugin_object || record && record.plugin || 'this plugin conflict');
        var plugin = String(record && record.plugin || 'this plugin');
        var subject = conflictScope(record) === 'theme' ? 'theme' : 'plugin';
        var driverTarget = subject === 'theme' ? 'this theme' : plugin;
        return 'No approved automation for this ' + subject + ': ForkPress has no trusted merge driver approved for ' + object + ', so it will not run arbitrary plugin repair code. Inspect the conflict details or add a trusted driver for ' + driverTarget + ' with FORKPRESS_PLUGIN_MERGE_DRIVERS or a forkpress-merge-driver.php file.';
    }
    function conflictScope(record) {
        if (!record) return 'unknown';
        var table = String(record.table_name || '');
        var type = String(record.conflict_type || '');
        var scope = String(record.semantic_scope || '');
        var object = String(record.plugin_object || '');
        if (table === '__files__') return 'file';
        if (table === '__plugins__' || record.plugin) {
            if (type.indexOf('theme') !== -1 || scope.indexOf('theme') !== -1 || object.indexOf('theme:') === 0) return 'theme';
            return 'plugin';
        }
        if (table) return 'database';
        return 'unknown';
    }
    function conflictHasAppliedResolution(record) {
        if (!record) return false;
        if (String(record.lifecycle_state || '') === 'resolved') return true;
        if (Number(record.latest_resolution_applied || 0) === 1) return true;
        return false;
    }
    function normalizeConflictFilter(filter) {
        if (filter === 'open' || filter === 'needsReview' || filter === 'unreviewed' || filter === 'pending') return 'all';
        if (filter === 'resolved' || filter === 'accepted') return 'applied';
        return filter || 'all';
    }
    function conflictMatchesFilter(record, filter) {
        filter = normalizeConflictFilter(filter);
        if (filter === 'all') return true;
        if (filter === 'applied') return conflictHasAppliedResolution(record);
        return conflictScope(record) === filter;
    }
    function conflictFilterCounts(list) {
        var counts = { all: list.length, applied: 0, plugin: 0, theme: 0, database: 0, file: 0 };
        list.forEach(function (record) {
            if (conflictHasAppliedResolution(record)) counts.applied++;
            var scope = conflictScope(record);
            if (Object.prototype.hasOwnProperty.call(counts, scope)) counts[scope]++;
        });
        return counts;
    }
    function conflictRecordSummary(list, fallback) {
        var counts = conflictFilterCounts(Array.isArray(list) ? list : []);
        var total = counts.all || Number(fallback && fallback.total || 0);
        return {
            total: total,
            resolved: counts.applied,
            unresolved: Math.max(0, total - counts.applied),
            hasResolutionCounts: true
        };
    }
    function conflictFilterLabel(filter) {
        return {
            all: 'all',
            applied: 'conflicts with applied choices',
            plugin: 'plugin',
            theme: 'theme',
            database: 'database',
            file: 'file'
        }[filter] || 'selected';
    }
    function renderConflictFilters(payload, list) {
        var counts = conflictFilterCounts(list);
        selectedConflictFilter = normalizeConflictFilter(selectedConflictFilter);
        if (!Object.prototype.hasOwnProperty.call(counts, selectedConflictFilter)) {
            selectedConflictFilter = 'all';
        }
        var bar = document.createElement('div');
        bar.className = 'fp-filterbar';
        bar.setAttribute('aria-label', 'Filter conflicts');
        [
            ['all', 'All'],
            ['applied', 'Applied choices'],
            ['plugin', 'Plugins'],
            ['theme', 'Themes'],
            ['database', 'Database'],
            ['file', 'Files']
        ].forEach(function (item) {
            var value = item[0];
            var label = item[1];
            var buttonNode = button(label, function () {
                selectedConflictFilter = value;
                renderConflicts(payload);
            });
            if (value === selectedConflictFilter) buttonNode.className = 'is-active';
            buttonNode.setAttribute('aria-pressed', value === selectedConflictFilter ? 'true' : 'false');
            buttonNode.appendChild(textNode('span', 'fp-filter-count', String(counts[value] || 0)));
            bar.appendChild(buttonNode);
        });
        return bar;
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
        if (Array.isArray(value)) {
            value = value.slice(0, 3).join(', ') + (value.length > 3 ? ' +' + String(value.length - 3) : '');
        }
        value = String(value);
        if (value.length > 160) value = value.slice(0, 157) + '...';
        var row = document.createElement('div');
        row.appendChild(textNode('span', '', label));
        row.appendChild(textNode('span', '', value));
        parent.appendChild(row);
    }
    function conflictPluginPanel(record) {
        if (!conflictIsPluginRecord(record)) return null;
        var panel = document.createElement('div');
        panel.className = 'fp-conflict-plugin';
        appendConflictInfo(panel, 'plugin', record.plugin);
        appendConflictInfo(panel, 'object', record.plugin_object);
        appendConflictInfo(panel, 'check', record.plugin_validator);
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
        if (!record || !record.latest_resolution_id) return 'Current applied resolution can be changed by selecting source, target, or a custom value.';
        return 'Current applied resolution #' + String(record.latest_resolution_id) + ' used ' + conflictChoiceLabel(record.latest_resolution_choice || '') + '.';
    }
    function appendConflictPill(parent, text, tone) {
        if (text === undefined || text === null || text === '') return;
        parent.appendChild(textNode('span', 'fp-chip' + (tone ? ' ' + tone : ''), text));
    }
    function conflictHeaderTitle(record, context) {
        var label = context.entityLabel || 'Conflict';
        var id = context.identifier || conflictObjectLabel(record);
        if (id) return label + ': ' + id;
        return 'Conflict #' + String(record && record.id || '');
    }
    function conflictHeaderSubtitle(record, context) {
        var parts = [];
        if (context.table && context.column) parts.push(context.table + '.' + context.column);
        else if (context.table) parts.push(context.table);
        if (context.field && parts.indexOf(context.field) === -1) parts.push(context.field);
        if (context.context) parts.push(context.context);
        return parts.filter(Boolean).join(' / ');
    }
    function conflictChoiceLabel(value) {
        value = String(value || '');
        if (value === 'source') return 'Source branch value';
        if (value === 'target') return 'Target branch value';
        if (value === 'reviewed') return 'saved choice';
        if (!value) return 'Target branch value';
        return humanStatus(value);
    }
    function conflictResolutionValue(record, choice) {
        choice = String(choice || '');
        if (choice === 'source') return conflictValue(record, 'source_payload', 'source_preview');
        if (choice === 'target') return conflictValue(record, 'target_payload', 'target_preview');
        return conflictValue(record, 'chosen_payload', 'chosen_preview')
            || conflictValue(record, 'current_target_payload', 'current_target_preview')
            || conflictValue(record, 'target_payload', 'target_preview');
    }
    function conflictResolutionChoiceLabel(record, choice) {
        return conflictChoiceLabel(choice) + ' (' + compactValuePreview(conflictResolutionValue(record, choice)) + ')';
    }
    function conflictResolutionLabel(record) {
        if (Number(record && record.latest_resolution_applied || 0) === 1) {
            return 'Applied: ' + conflictResolutionChoiceLabel(record, record.latest_resolution_choice || '');
        }
        if (record && record.latest_resolution_choice) {
            return 'Saved: ' + conflictResolutionChoiceLabel(record, record.latest_resolution_choice);
        }
        return conflictResolutionChoiceLabel(record, '');
    }
    function conflictGuidancePanel(record) {
        var rows = [];
        if (record.plugin_suggested_action) rows.push(['Suggested action', record.plugin_suggested_action]);
        if (record.plugin_manual_review_reason) rows.push(['Why review', record.plugin_manual_review_reason]);
        if (record.plugin_reason && rows.length < 2) rows.push(['Reason', record.plugin_reason]);
        if (record.plugin_resolution_policy && rows.length < 2) rows.push(['Policy', record.plugin_resolution_policy]);
        if (!rows.length) return null;
        var panel = document.createElement('div');
        panel.className = 'fp-conflict-guidance';
        rows.slice(0, 2).forEach(function (row) {
            panel.appendChild(textNode('strong', '', row[0]));
            panel.appendChild(textNode('div', '', row[1]));
        });
        return panel;
    }
    function disclosure(title, content, open) {
        var details = document.createElement('details');
        details.className = 'fp-disclosure';
        if (open) details.open = true;
        details.appendChild(textNode('summary', '', title));
        var body = document.createElement('div');
        body.className = 'fp-disclosure-body';
        if (Array.isArray(content)) {
            content.forEach(function (child) { if (child) body.appendChild(child); });
        } else if (content) {
            body.appendChild(content);
        }
        details.appendChild(body);
        return details;
    }
    function appendTableCell(row, label, parts) {
        var cell = document.createElement('td');
        var tooltip = [];
        cell.setAttribute('data-label', label || '');
        (parts || []).forEach(function (part) {
            if (!part || part.text === undefined || part.text === null || part.text === '') return;
            tooltip.push(String(part.text));
            cell.appendChild(textNode('div', part.className || '', part.text));
        });
        if (tooltip.length) cell.title = tooltip.join('\\n');
        row.appendChild(cell);
        return cell;
    }
    function conflictResolutionDetail(record) {
        return [
            Number(record && record.latest_resolution_applied || 0) === 1 ? 'written to target' : '',
            record && record.latest_resolution_status ? humanStatus(record.latest_resolution_status) : '',
            record && record.stale_status ? 'changed since choice: ' + humanStatus(record.stale_status) : ''
        ].filter(Boolean).join(' / ');
    }
    function setSelectedConflict(payload, record) {
        selectedConflictId = String(record && record.id || '');
        syncReviewUrl();
        if (record && record.id) {
            var run = runById(payload && payload.run) || {};
            setWorkbenchMode((isMergeRun(run) ? 'Merge #' : 'Revision #') + String(payload && payload.run || '') + ', conflict #' + String(record.id) + '.');
        }
        renderConflicts(payload);
    }
    function renderConflictTable(payload, list) {
        var wrap = document.createElement('div');
        wrap.className = 'fp-conflict-table-wrap';
        var table = document.createElement('table');
        table.className = 'fp-conflict-table';
        var thead = document.createElement('thead');
        var header = document.createElement('tr');
        ['Conflict', 'DB table', 'ID', 'Resolution', 'Summary'].forEach(function (label) {
            header.appendChild(textNode('th', '', label));
        });
        thead.appendChild(header);
        table.appendChild(thead);
        var tbody = document.createElement('tbody');
        list.forEach(function (record) {
            var context = conflictEntityContext(record);
            var row = document.createElement('tr');
            row.setAttribute('data-conflict-id', String(record.id || ''));
            row.setAttribute('role', 'button');
            row.setAttribute('tabindex', '0');
            row.setAttribute('aria-label', 'Inspect conflict #' + String(record.id || '') + ' ' + String(context.identifier || context.entityLabel || ''));
            if (String(record.id || '') === String(selectedConflictId || '')) row.className = 'is-selected';
            var details = detailsText(context.details);
            appendTableCell(row, 'Conflict', [
                { className: 'fp-table-primary', text: '#' + String(record.id || '') },
                { className: 'fp-table-muted', text: humanConflictType(record.conflict_type) }
            ]);
            appendTableCell(row, 'DB table', [
                { className: 'fp-table-primary', text: context.table || context.entityType || '(none)' },
                { className: 'fp-table-muted', text: context.column || context.field || '' }
            ]);
            appendTableCell(row, 'ID', [
                { className: 'fp-table-primary', text: context.identifier || '(unknown)' },
                { className: 'fp-table-muted', text: context.entityLabel }
            ]);
            appendTableCell(row, 'Resolution', [
                { className: 'fp-table-primary', text: conflictResolutionLabel(record) },
                { className: 'fp-table-muted', text: conflictResolutionDetail(record) }
            ]);
            appendTableCell(row, 'Summary', [
                { className: 'fp-table-primary', text: context.context || details || '(no context)' },
                { className: 'fp-table-muted', text: [humanConflictType(record.conflict_type), conflictPluginGuidance(record)].filter(Boolean).join(' / ') }
            ]);
            tbody.appendChild(row);
            row.addEventListener('click', function (event) {
                if (event.target && event.target.tagName === 'BUTTON') return;
                setSelectedConflict(payload, record);
            });
            row.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                event.preventDefault();
                setSelectedConflict(payload, record);
            });
        });
        table.appendChild(tbody);
        wrap.appendChild(table);
        return wrap;
    }
    function renderConflictInspector(payload, record) {
        if (!record) {
            return textNode('div', 'fp-conflict-inspector-empty', 'Choose a conflict row to inspect values and resolution controls.');
        }
        var context = conflictEntityContext(record);
        var node = document.createElement('div');
        node.className = 'fp-conflict fp-conflict-inspector';
        node.id = 'fp-conflict-card-' + String(record.id || '');

        var header = document.createElement('div');
        header.className = 'fp-conflict-card-head';
        var titleRow = document.createElement('div');
        titleRow.className = 'fp-conflict-title-row';
        titleRow.appendChild(textNode('div', 'fp-conflict-title', conflictHeaderTitle(record, context)));
        titleRow.appendChild(textNode('span', 'fp-conflict-meta', '#' + String(record.id || '')));
        var chips = document.createElement('div');
        chips.className = 'fp-summary-chips';
        appendConflictPill(chips, conflictResolutionLabel(record), conflictHasAppliedResolution(record) ? 'ok' : 'warn');
        appendConflictPill(chips, conflictScope(record));
        appendConflictPill(chips, humanConflictType(record.conflict_type));
        if (record.plugin_severity) appendConflictPill(chips, String(record.plugin_severity));
        header.appendChild(titleRow);
        header.appendChild(chips);
        header.appendChild(textNode('div', 'fp-conflict-contextline', conflictHeaderSubtitle(record, context) || conflictPluginMeta(record) || conflictObjectLabel(record)));

        var pluginPanel = conflictPluginPanel(record);
        var values = document.createElement('div');
        values.className = 'fp-conflict-grid';
        values.appendChild(conflictValueField('Source', conflictValue(record, 'source_payload', 'source_preview')));
        values.appendChild(conflictValueField('Target', conflictValue(record, 'target_payload', 'target_preview')));
        values.appendChild(conflictValueField('Base', conflictValue(record, 'base_payload', 'base_preview')));
        values.appendChild(conflictValueField('Chosen / current target', conflictValue(record, 'chosen_payload', 'chosen_preview') || conflictValue(record, 'current_target_payload', 'current_target_preview')));
        var noteWrap = document.createElement('div');
        noteWrap.className = 'fp-conflict-note';
        var noteLabel = document.createElement('label');
        noteLabel.textContent = 'Resolution note';
        var note = document.createElement('textarea');
        note.value = record.review_note || '';
        note.placeholder = 'Record why this resolution is correct or what changed after refreshing conflicts.';
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
            option.textContent = value === 'source' ? 'Use source value' : (value === 'target' ? 'Keep target value' : (value === 'custom' ? 'Enter custom value' : value));
            option.selected = record.latest_resolution_choice === value;
            choice.appendChild(option);
        });
        choiceWrap.appendChild(choiceLabel);
        choiceWrap.appendChild(choice);
        var customWrap = document.createElement('div');
        customWrap.className = 'fp-conflict-note';
        var customLabel = document.createElement('label');
        customLabel.textContent = 'Custom value';
        var customValue = document.createElement('textarea');
        customValue.placeholder = 'Use this when neither branch value is correct.';
        customWrap.appendChild(customLabel);
        customWrap.appendChild(customValue);
        function updateCustomValueVisibility() {
            customWrap.style.display = choice.value === 'custom' ? '' : 'none';
        }
        choice.addEventListener('change', updateCustomValueVisibility);
        updateCustomValueVisibility();
        var row = document.createElement('div');
        row.className = 'fp-buttons fp-conflict-action-row';
        var controls = document.createElement('div');
        controls.className = 'fp-conflict-card-controls';
        var scrollBody = document.createElement('div');
        scrollBody.className = 'fp-conflict-scroll';
        var prioritizeResolutionChange = conflictResolutionChangeAvailable(record);
        var isPluginConflict = conflictIsPluginRecord(record);
        var pluginDriver = pluginDriverFor(record);
        if (isPluginConflict && record.id && record.lifecycle_state !== 'resolved') {
            if (pluginDriver) {
                row.appendChild(button('Run plugin driver', function () { runPluginDriver(record.id, payload.run, pluginDriver.key); }, 'primary'));
            }
            if (!pluginDriver) row.appendChild(textNode('span', 'fp-conflict-meta', pluginDriverMissingMessage(record)));
        } else if (record.id && record.lifecycle_state !== 'resolved') {
            row.appendChild(button('Apply selected choice', function () { resolveConflict(record.id, choice.value, payload.run, note.value, false, false, customValue.value); }, 'primary'));
        } else if (prioritizeResolutionChange) {
            row.appendChild(button('Change applied resolution', function () { resolveConflict(record.id, choice.value, payload.run, note.value, false, true, customValue.value); }, 'primary'));
            row.appendChild(textNode('span', 'fp-conflict-meta', conflictResolutionAppliedLabel(record)));
        } else if (record.id && Number(record.latest_resolution_applied || 0) === 1) {
            row.appendChild(textNode('span', 'fp-conflict-meta', 'Resolved; changing this conflict type requires a fresh merge audit.'));
        } else {
            row.appendChild(textNode('span', 'fp-conflict-meta', 'No direct change action is available for this conflict type.'));
        }
        node.appendChild(header);
        controls.appendChild(row);
        if (isPluginConflict) {
            var extensionLabel = conflictScope(record) === 'theme' ? 'Theme' : 'Plugin';
            scrollBody.appendChild(disclosure('Resolution note', noteWrap, false));
            var guidance = conflictGuidancePanel(record);
            if (guidance) scrollBody.appendChild(guidance);
            if (pluginPanel) scrollBody.appendChild(disclosure(extensionLabel + ' details', pluginPanel, false));
            var pluginPayload = conflictValue(record, 'chosen_payload', 'chosen_preview');
            if (pluginPayload) {
                var pluginValues = document.createElement('div');
                pluginValues.className = 'fp-conflict-grid';
                pluginValues.appendChild(conflictValueField('Conflict check payload', pluginPayload));
                scrollBody.appendChild(disclosure('Conflict check payload', pluginValues, false));
            }
        } else if (prioritizeResolutionChange) {
            controls.appendChild(choiceWrap);
            controls.appendChild(customWrap);
            scrollBody.appendChild(disclosure('Resolution note', noteWrap, false));
            scrollBody.appendChild(disclosure('Compare values', values, true));
        } else {
            controls.appendChild(choiceWrap);
            controls.appendChild(customWrap);
            scrollBody.appendChild(disclosure('Resolution note', noteWrap, false));
            scrollBody.appendChild(disclosure('Compare values', values, true));
        }
        node.appendChild(controls);
        node.appendChild(scrollBody);
        return node;
    }
    function renderCrashRecovery(payload, source, target) {
        var run = String(payload.run || selectedRunId || '');
        setStatus('warn', payload.message || 'This merge has pending crash recovery.');
        conflictActions.innerHTML = '';
        conflictActions.appendChild(button('Restore crash recovery', function () { return restoreCrashRecovery(run); }, 'danger'));
        appendBranchPreviewLinks(conflictActions, source, target);
        appendReviewLinkAction(conflictActions);
        var panel = document.createElement('div');
        panel.className = 'fp-crash-recovery';
        panel.appendChild(textNode('strong', '', 'Pending crash recovery blocks conflict review'));
        panel.appendChild(textNode('div', '', payload.message || ('Merge run #' + run + ' needs target database/files restored before more conflict actions can run.')));
        if (payload.recoveryCommand) {
            panel.appendChild(textNode('code', '', payload.recoveryCommand));
        }
        conflicts.appendChild(panel);
    }
    function selectBranch(name) {
        var item = branch(name);
        selectedBranchName = String(name || '');
        selectedRunId = null;
        selectedConflictId = null;
        clearStatus();
        setDetail('Branch ' + name, { branch: name, current: name === state.currentBranch ? 'yes' : 'no' }, [
            branchManagerLink('Focus in manager', name),
            copyTextButton('Copy site URL', item.siteUrl || item.url, 'Site URL copied.'),
            copyTextButton('Copy admin URL', item.adminUrl || item.url, 'Admin URL copied.')
        ], item);
        setWorkbenchMode('Viewing branch ' + name + '.');
        syncReviewUrl();
    }
    function selectRun(run, options) {
        options = options || {};
        var nextRunId = String(run && run.id || '');
        var preserveInitialConflict = initialRunId && nextRunId === String(initialRunId) && selectedConflictId;
        selectedRunId = nextRunId;
        selectedBranchName = '';
        if (!preserveInitialConflict) selectedConflictId = null;
        clearStatus();
        var isMerge = isMergeRun(run);
        var actions = [
            branchManagerLink(isMerge ? 'Show source: ' + String(run.source_branch || '') : 'Show branch', String(run.source_branch || '')),
            branchManagerLink(isMerge ? 'Show target: ' + String(run.target_branch || '') : 'Show target', String(run.target_branch || ''))
        ];
        setDetail(runDetailTitle(run), {
            kind: isMerge ? 'merge' : (isBranchForkRun(run) ? 'branch birth' : 'revision'),
            flow: String(run.source_branch || '') + (isMerge ? ' -> ' : ' @ ') + String(run.target_branch || ''),
            state: humanStatus(run.status || ''),
            'resolution state': (function () {
                var summary = conflictSummary(run);
                return conflictResolutionSummaryText(summary);
            }())
        }, actions, run);
        setWorkbenchMode(isMerge ? ('Merge #' + String(run.id || '') + ' into ' + String(run.target_branch || '') + '.') : ('Viewing revision #' + String(run.id || '') + '.'));
        renderGraph();
        scrollSelectedRunIntoView();
        if (!options.skipConflictLoad && Number(run.conflict_count || 0) > 0 && run.id) {
            loadConflicts(run.id);
        } else {
            syncReviewUrl();
        }
    }
    function renderConflicts(payload) {
        var list = Array.isArray(payload.records) ? payload.records : [];
        var summary = conflictRecordSummary(list, payload.conflictSummary || payload.conflict_summary || {});
        if (refreshSelectedRunConflictState(payload.run, summary)) {
            renderGraph();
            scrollSelectedRunIntoView();
        }
        setStatus(list.length ? 'warn' : 'ok', list.length ? 'Loaded conflicts. The merge result already exists; inspect the current resolution or change it.' : (payload.message || 'No conflicts found.'));
        var run = runById(payload.run) || {};
        var source = String(run.source_branch || '');
        var target = String(run.target_branch || '');
        var flow = source && target && source !== target ? source + ' -> ' + target : (source || target);
        showConflictWorkbench((isMergeRun(run) ? 'Merge ' : 'Revision ') + '#' + String(payload.run || '') + ' conflicts', '');
        setConflictSummaryChips(flow, summary.total !== undefined ? summary : { total: list.length, resolved: 0, unresolved: list.length });
        conflictActions.innerHTML = '';
        conflicts.innerHTML = '';
        raw.textContent = '';
        raw.style.display = 'none';
        if (Number(payload.crashRecoveryCount || 0) > 0) {
            renderCrashRecovery(payload, source, target);
            return;
        }
        if (!list.length) {
            conflictActions.appendChild(button('Refresh conflicts', function () { return revalidateConflicts(payload.run); }));
            appendReviewMoreActions(conflictActions, source, target);
            conflicts.appendChild(textNode('div', 'fp-conflict-loading', 'No conflicts found for this revision.'));
            return;
        }
        conflicts.appendChild(renderConflictFilters(payload, list));
        var filteredList = list.filter(function (record) { return conflictMatchesFilter(record, selectedConflictFilter); });
        if (!filteredList.length) {
            selectedConflictId = null;
            syncReviewUrl();
            conflictActions.appendChild(button('Refresh conflicts', function () { return revalidateConflicts(payload.run); }));
            appendReviewMoreActions(conflictActions, source, target);
            conflicts.appendChild(textNode('div', 'fp-conflict-loading', 'No ' + conflictFilterLabel(selectedConflictFilter) + ' match this filter.'));
            return;
        }
        if (!selectedConflictId || !filteredList.some(function (record) { return String(record.id || '') === String(selectedConflictId); })) {
            selectedConflictId = String(filteredList[0] && filteredList[0].id || '');
        }
        var selected = filteredList.find(function (record) { return String(record.id || '') === String(selectedConflictId); }) || filteredList[0];
        syncReviewUrl();
        var selectedIndex = filteredList.indexOf(selected);
        var previous = button('Prev', function () {
            var prior = filteredList[Math.max(0, selectedIndex - 1)];
            if (prior) setSelectedConflict(payload, prior);
        });
        previous.disabled = selectedIndex <= 0;
        var next = button('Next', function () {
            var following = filteredList[Math.min(filteredList.length - 1, selectedIndex + 1)];
            if (following) setSelectedConflict(payload, following);
        });
        next.disabled = selectedIndex >= filteredList.length - 1;
        conflictActions.appendChild(previous);
        conflictActions.appendChild(textNode('span', 'fp-review-progress', String(selectedIndex + 1) + ' of ' + String(filteredList.length)));
        conflictActions.appendChild(next);
        conflictActions.appendChild(button('Refresh conflicts', function () { return revalidateConflicts(payload.run); }));
        appendReviewMoreActions(conflictActions, source, target);
        var reviewGrid = document.createElement('div');
        reviewGrid.className = 'fp-conflict-review-grid';
        reviewGrid.appendChild(renderConflictTable(payload, filteredList));
        var inspectorSlot = document.createElement('div');
        inspectorSlot.id = 'fp-conflict-inspector-slot';
        inspectorSlot.appendChild(renderConflictInspector(payload, selected));
        reviewGrid.appendChild(inspectorSlot);
        conflicts.appendChild(reviewGrid);
    }
    function loadTree(options) {
        options = options || {};
        setStatus('warn', 'Loading branch graph...');
        return post('forkpress_branch_tree', { limit: '50' }).then(function (payload) {
            if (Array.isArray(payload.branches)) {
                state.branches = payload.branches;
                refreshForms();
            }
            records = Array.isArray(payload.records) ? payload.records : [];
            seedConflictSummaries();
            renderGraph();
            scrollSelectedRunIntoView();
            clearStatus();
            var initialRun = options.preserveSelection && selectedRunId ? runById(selectedRunId) : null;
            if (!initialRun && initialRunId && !didRestoreInitialSelection) {
                initialRun = runById(initialRunId);
                didRestoreInitialSelection = true;
            }
            if (!initialRun && initialBranchName && !didRestoreInitialSelection) {
                didRestoreInitialSelection = true;
                selectBranch(initialBranchName);
                return;
            }
            initialRun = initialRun || firstConflictRun() || records[0] || null;
            if (initialRun) {
                selectRun(initialRun, { skipConflictLoad: !!options.skipConflictReload });
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
            scrollSelectedRunIntoView();
            setStatus('ok', payload.message || 'Loaded history.');
        }).catch(function (error) { setStatus('error', error.message || 'Could not load history.'); });
    }
    function renderConflictLoading(run) {
        var item = runById(run) || {};
        showConflictWorkbench((isMergeRun(item) ? 'Merge ' : 'Revision ') + '#' + String(run || '') + ' conflicts', 'Loading entity-level conflict context...');
        conflicts.innerHTML = '';
        raw.textContent = '';
        raw.style.display = 'none';
        conflicts.appendChild(textNode('div', 'fp-conflict-loading', 'Loading conflict list for run #' + String(run) + '...'));
    }
    function cacheConflictPayload(run, payload) {
        var item = runById(run);
        if (!item) return;
        item._conflictRecords = Array.isArray(payload.records) ? payload.records : [];
        item._conflictSummary = conflictRecordSummary(item._conflictRecords, payload.conflictSummary || payload.conflict_summary || item._conflictSummary || null);
    }
    function forgetConflictCache(run) {
        var item = runById(run);
        if (!item) return;
        delete item._conflictSummary;
        delete item._conflictRecords;
    }
    function refreshAfterConflictAction(run) {
        forgetConflictCache(run);
        return loadTree({ preserveSelection: true, skipConflictReload: true }).then(function () {
            return loadConflicts(run, { refresh: true });
        });
    }
    function loadConflicts(run, options) {
        var requestedRun = String(run || '');
        var item = runById(run);
        if (item && Array.isArray(item._conflictRecords)) {
            if (String(selectedRunId || '') === requestedRun) {
                renderConflicts({
                    run: Number(run),
                    records: item._conflictRecords,
                    conflictSummary: item._conflictSummary || null,
                    message: 'Loaded cached conflict details.'
                });
            }
            if (!options || !options.refresh) return Promise.resolve();
        } else if (String(selectedRunId || '') === requestedRun) {
            renderConflictLoading(run);
        }
        setStatus('warn', 'Loading conflicts...');
        return post('forkpress_branch_conflicts', { run: String(run) }).then(function (payload) {
            cacheConflictPayload(run, payload);
            if (String(selectedRunId || '') !== requestedRun) return;
            renderConflicts(payload);
            renderGraph();
            scrollSelectedRunIntoView();
            clearStatus();
        }).catch(function (error) {
            setStatus('error', error.message || 'Could not load conflicts.');
        });
    }
    function resolveConflict(id, choice, run, note, applyReviewed, replaceApplied, customValue) {
        if (replaceApplied && !window.confirm('Change the already-applied resolution for conflict #' + String(id || '') + '?')) return;
        return post('forkpress_branch_resolve_conflict', { conflict: String(id), choice: choice || '', customValue: customValue || '', run: String(run || ''), note: note || '', applyReviewed: applyReviewed ? '1' : '', replaceApplied: replaceApplied ? '1' : '' }).then(function (payload) {
            setStatus('ok', payload.message || 'Conflict resolution applied.');
            return refreshAfterConflictAction(run);
        }).catch(function (error) { setStatus('error', error.message); });
    }
    function revalidateConflicts(run) {
        return post('forkpress_branch_revalidate_conflicts', { run: String(run || '') }).then(function (payload) {
            setStatus(statusKindForPayload(payload, 'warn'), payload.message || 'Refreshed merge conflicts.');
            return refreshAfterConflictAction(run);
        }).catch(function (error) { setStatus('error', error.message || 'Could not check conflicts for changes.'); });
    }
    function restoreCrashRecovery(run) {
        if (!window.confirm('Restore crash recovery artifacts for merge run #' + String(run || '') + '?')) return;
        return post('forkpress_branch_restore_crash', { run: String(run || '') }).then(function (payload) {
            setStatus(statusKindForPayload(payload, 'ok'), payload.message || 'Crash recovery restored.');
            return refreshAfterConflictAction(run);
        }).catch(function (error) { setStatus('error', error.message || 'Could not restore crash recovery.'); });
    }
    function runPluginDriver(conflict, run, driverKey) {
        if (!window.confirm('Run the approved plugin driver for conflict #' + String(conflict || '') + '?')) return;
        return post('forkpress_branch_run_plugin_driver', { conflict: String(conflict || ''), run: String(run || ''), driverKey: String(driverKey || '') }).then(function (payload) {
            setStatus(statusKindForPayload(payload, 'ok'), payload.message || 'Plugin driver finished.');
            return refreshAfterConflictAction(run);
        }).catch(function (error) { setStatus('error', error.message || 'Could not run plugin driver.'); });
    }
    graph.addEventListener('click', function (event) {
        var target = event.target.closest ? event.target.closest('[data-kind]') : null;
        if (!target) return;
        if (target.getAttribute('data-kind') === 'branch') selectBranch(target.getAttribute('data-name'));
        if (target.getAttribute('data-kind') === 'run') selectRun(records[Number(target.getAttribute('data-index'))]);
    });
    graph.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        var target = event.target.closest ? event.target.closest('[data-kind]') : null;
        if (!target) return;
        event.preventDefault();
        if (target.getAttribute('data-kind') === 'branch') selectBranch(target.getAttribute('data-name'));
        if (target.getAttribute('data-kind') === 'run') selectRun(records[Number(target.getAttribute('data-index'))]);
    });
    createForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var submit = createForm.querySelector('button[type=submit]');
        setButtonBusy(submit, true);
        post('forkpress_branch_create', { branch: createName.value, from: createFrom.value }).then(function (payload) {
            if (Array.isArray(payload.branches)) state.branches = payload.branches;
            state.currentBranch = createName.value;
            current.textContent = 'Current: ' + state.currentBranch;
            adminLink.href = branchManagerHref(state.currentBranch);
            refreshForms();
            renderGraph();
            selectBranch(state.currentBranch);
            setStatus('ok', payload.message || 'Branch created.');
            createForm.reset();
        }).catch(function (error) { setStatus('error', error.message || 'Branch create failed.'); }).then(function () { setButtonBusy(submit, false); });
    });
    mergeForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var submit = mergeForm.querySelector('button[type=submit]');
        setButtonBusy(submit, true);
        post('forkpress_branch_merge', { source: mergeSource.value, target: mergeTarget.value }).then(function (payload) {
            setStatus(payload.type === 'warning' ? 'warn' : 'ok', payload.message || 'Merge completed.');
            if (payload.run) selectedRunId = String(payload.run);
            return loadTree({ preserveSelection: !!payload.run, skipConflictReload: true }).then(function () {
                if (payload.run && Number(payload.conflicts || 0) > 0) return loadConflicts(payload.run, { refresh: true });
            });
        }).catch(function (error) { setStatus('error', error.message || 'Merge failed.'); }).then(function () { setButtonBusy(submit, false); });
    });
    forkActions.addEventListener('toggle', function () {
        if (!forkActions.open) return;
        closeActionDetails(forkActions);
        configureForkAction();
    });
    mergeActions.addEventListener('toggle', function () {
        if (!mergeActions.open) return;
        closeActionDetails(mergeActions);
        configureMergeAction();
    });
    document.getElementById('fp-refresh').addEventListener('click', loadTree);
    document.getElementById('fp-focus-selected').addEventListener('click', scrollSelectedRunIntoView);
    document.getElementById('fp-load-history').addEventListener('click', loadHistory);
    current.textContent = 'Current: ' + state.currentBranch;
    adminLink.href = branchManagerHref(state.currentBranch);
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

function forkpress_cow_handle_branch_manager(string $path, string $current_branch, string $branches_dir): bool {
    if ($path !== '/_forkpress/branches') {
        return false;
    }
    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo str_replace('__STATE__', forkpress_cow_json_encode([
        'currentBranch' => $current_branch,
        'branches' => forkpress_cow_branch_switcher_data($current_branch, '/wp-admin/', forkpress_cow_metadata_branch_names($branches_dir)),
        'pluginDrivers' => array_values(forkpress_cow_branch_plugin_driver_map($branches_dir, $current_branch)),
        'actionUrl' => '/_forkpress/action',
        'rootUrl' => '/_forkpress/branches',
    ]), forkpress_cow_branch_manager_html($current_branch));
    return true;
}

if (forkpress_cow_handle_branch_manager($path, $branch, $branches_dir)) {
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

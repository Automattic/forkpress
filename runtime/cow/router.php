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
    if (!is_string($action) || !in_array($action, ['forkpress_branch_create', 'forkpress_branch_merge'], true)) {
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

function forkpress_cow_branch_url(string $branch, string $uri = '/wp-admin/'): string {
    $root_host = getenv('FORKPRESS_ROOT_HOST') ?: 'wp.localhost';
    $current_host = $_SERVER['HTTP_HOST'] ?? '';
    $port = preg_match('/:(\d+)$/', $current_host, $m) ? ':' . $m[1] : '';
    $host = $branch === 'main' ? $root_host : $branch . '.' . $root_host;
    return 'http://' . $host . $port . $uri;
}

function forkpress_cow_branch_names(string $current_branch): array {
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

function forkpress_cow_branch_switcher_data(string $current_branch): array {
    return array_map(static function (string $branch) use ($current_branch): array {
        return [
            'name' => $branch,
            'url' => forkpress_cow_branch_url($branch),
            'current' => $branch === $current_branch,
        ];
    }, forkpress_cow_branch_names($current_branch));
}

function forkpress_cow_branch_finish_json(int $status, string $url, bool $success, string $message, array $data = []): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array_merge([
        'success' => $success,
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

function forkpress_cow_handle_admin_branch_action(string $path, string $current_branch): bool {
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
            ['branches' => forkpress_cow_branch_switcher_data($current_branch)]
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
        forkpress_cow_branch_finish_json(
            200,
            forkpress_cow_branch_url($target, '/wp-admin/'),
            true,
            'Merged ' . $source . ' into ' . $target . '.',
            ['branches' => forkpress_cow_branch_switcher_data($current_branch)]
        );
        return true;
    }

    return false;
}

if (forkpress_cow_handle_admin_branch_action($path, $branch)) {
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

if ($path === '/plugins.php'
    && !file_exists($branch_root . '/plugins.php')
    && file_exists($branch_root . '/wp-admin/plugins.php')) {
    header('Location: /wp-admin/plugins.php' . ($query !== '' ? '?' . $query : ''), true, 302);
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

function forkpress_cow_prepare_php_request(string $path, string $branch_root): void {
    chdir($branch_root);
    $_SERVER['DOCUMENT_ROOT'] = $branch_root;
    $_SERVER['SCRIPT_FILENAME'] = $path;
    $_SERVER['SCRIPT_NAME'] = substr($path, strlen($branch_root));
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
}

if (file_exists($full_path) && !is_dir($full_path)) {
    if (!forkpress_cow_path_is_inside_branch($branch_root, $full_path)) {
        http_response_code(404);
        echo "Not found\n";
        return true;
    }
    if (strtolower(pathinfo($full_path, PATHINFO_EXTENSION)) === 'php') {
        forkpress_cow_prepare_php_request($full_path, $branch_root);
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
        forkpress_cow_prepare_php_request($index, $branch_root);
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
forkpress_cow_prepare_php_request($branch_root . '/index.php', $branch_root);
require $branch_root . '/index.php';
return true;

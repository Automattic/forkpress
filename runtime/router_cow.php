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

$branch_root = rtrim($branches_dir, '/') . '/' . $branch;
if (!is_dir($branch_root)) {
    http_response_code(404);
    echo "Branch not found: " . htmlspecialchars($branch, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
    return true;
}

$_SERVER['FORKPRESS_BRANCH'] = $branch;
putenv('FORKPRESS_BRANCH=' . $branch);
header('X-ForkPress-Branch: ' . $branch);

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$query = parse_url($uri, PHP_URL_QUERY) ?: '';

if (preg_match('|^/([a-zA-Z0-9_\-]+)\.git(/.*)?$|', $path, $git_match)) {
    $git_path = $git_match[2] ?? '/';
    $cow_dir = getenv('FORKPRESS_COW_DIR') ?: dirname(rtrim($branches_dir, '/'));
    $git_repo_dir = getenv('FORKPRESS_COW_GIT_DIR') ?: rtrim($cow_dir, '/') . '/git';
    $storage_branches_dir = getenv('FORKPRESS_COW_STORAGE_BRANCHES_DIR') ?: $branches_dir;
    $branch_list = getenv('FORKPRESS_BRANCH_LIST') ?: rtrim($cow_dir, '/') . '/branches.txt';
    $file_view = getenv('FORKPRESS_COW_FILE_VIEW') ?: 'file-copy';
    $debug_log = getenv('FORKPRESS_DEBUG_LOG') ?: '';
    require_once __DIR__ . '/../scripts/git_server/cow_server.php';
    cow_git_server_handle($branches_dir, $git_repo_dir, $git_path, $query, $storage_branches_dir, $branch_list, $file_view, $debug_log);
    return true;
}

if ($path === '/plugins.php'
    && !file_exists($branch_root . '/plugins.php')
    && file_exists($branch_root . '/wp-admin/plugins.php')) {
    header('Location: /wp-admin/plugins.php' . ($query !== '' ? '?' . $query : ''), true, 302);
    return true;
}

$full_path = $branch_root . $path;
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
        forkpress_cow_prepare_php_request($index, $branch_root);
        require $index;
        return true;
    }
}

forkpress_cow_prepare_php_request($branch_root . '/index.php', $branch_root);
require $branch_root . '/index.php';
return true;

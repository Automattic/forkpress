<?php
/**
 * Doltlite-backed router for ForkPress.
 *
 * Both WordPress files and WordPress tables live in one Doltlite database.
 * BranchFS and the SQLite integration checkout the request branch on their
 * own SQLite connections via Doltlite's `dolt_checkout(?)` function.
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);

$db_path = getenv('FORKPRESS_DOLTLITE_DB');
$wp_root = getenv('FORKPRESS_DOLTLITE_WP_ROOT');
$root_host = getenv('FORKPRESS_ROOT_HOST') ?: 'wp.localhost';

if (!$db_path || !$wp_root) {
    http_response_code(500);
    echo "FORKPRESS_DOLTLITE_DB and FORKPRESS_DOLTLITE_WP_ROOT are required\n";
    return true;
}
if (!extension_loaded('branchfs')) {
    http_response_code(500);
    echo "branchfs extension not loaded\n";
    return true;
}

$host = $_SERVER['HTTP_HOST'] ?? '';
$host_noport = strtolower(preg_replace('/:\d+$/', '', $host));
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

if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,62}$/', $branch)) {
    http_response_code(400);
    echo "Invalid branch\n";
    return true;
}

branchfs_set_db($db_path);
branchfs_set_root($wp_root);
branchfs_set_branch($branch);
if (!branchfs_activate()) {
    http_response_code(404);
    echo "Branch not found\n";
    return true;
}

$_SERVER['FORKPRESS_BRANCH'] = $branch;
putenv('FORKPRESS_BRANCH=' . $branch);
putenv('FORKPRESS_DOLTLITE_DB=' . $db_path);
putenv('FORKPRESS_DOLTLITE_DEBUG_LOG=' . (getenv('FORKPRESS_DOLTLITE_DEBUG_LOG') ?: '/tmp/forkpress-doltlite-wp-debug.log'));
header('X-ForkPress-Branch: ' . $branch);

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$query = parse_url($uri, PHP_URL_QUERY) ?: '';

if (preg_match('|^/([a-zA-Z0-9_\-]+)\.git(/.*)?$|', $path, $git_match)) {
    $git_path = $git_match[2] ?? '/';
    require_once __DIR__ . '/../scripts/git_server/server.php';
    git_server_handle($db_path, $wp_root, $git_path, $query, ['storage' => 'doltlite']);
    return true;
}

if ($path === '/plugins.php'
    && !file_exists("branchfs://$branch/plugins.php")
    && file_exists("branchfs://$branch/wp-admin/plugins.php")) {
    header('Location: /wp-admin/plugins.php' . ($query !== '' ? '?' . $query : ''), true, 302);
    return true;
}

$target = $path === '/' ? 'index.php' : ltrim($path, '/');
if (str_ends_with($path, '/') && $target !== 'index.php') {
    $target .= 'index.php';
}

$file = "branchfs://$branch/$target";

function forkpress_doltlite_send_file(string $path): void {
    $ext = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION));
    $mimes = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'txt' => 'text/plain',
        'html' => 'text/html',
        'map' => 'application/json',
    ];

    header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
    $content = file_get_contents($path);
    if ($content === false) {
        http_response_code(404);
        return;
    }
    header('Content-Length: ' . strlen($content));
    echo $content;
}

function forkpress_doltlite_require_php(string $path, string $branch, string $wp_root): void {
    chdir($wp_root);
    $_SERVER['DOCUMENT_ROOT'] = $wp_root;
    $_SERVER['SCRIPT_FILENAME'] = $wp_root . '/' . $path;
    $_SERVER['SCRIPT_NAME'] = '/' . $path;
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
    require "branchfs://$branch/$path";
}

if (file_exists($file) && !is_dir($file)) {
    if (strtolower(pathinfo($target, PATHINFO_EXTENSION)) === 'php') {
        forkpress_doltlite_require_php($target, $branch, $wp_root);
        return true;
    }
    forkpress_doltlite_send_file($file);
    return true;
}

forkpress_doltlite_require_php('index.php', $branch, $wp_root);
return true;

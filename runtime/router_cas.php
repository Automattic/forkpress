<?php
/**
 * Lazy Redb-backed router for the ForkPress CAS strategy.
 *
 * WordPress files are served through branchfs:// URLs. The branchfs extension
 * intercepts normal filesystem calls under FORKPRESS_CAS_WP_ROOT and resolves
 * them from .forkpress/cas/store.redb. The SQLite database file remains a
 * branch-local ordinary file under .forkpress/cas/branches/<branch>/.ht.sqlite.
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
set_time_limit(300);

$store = getenv('FORKPRESS_CAS_STORE');
$wp_root = getenv('FORKPRESS_CAS_WP_ROOT');
$db_base = getenv('FORKPRESS_CAS_DB_BASE');
$root_host = getenv('FORKPRESS_ROOT_HOST') ?: 'wp.localhost';

if (!extension_loaded('branchfs')) {
    http_response_code(500);
    echo "branchfs extension not loaded\n";
    return true;
}
if (!$store || !$wp_root || !$db_base) {
    http_response_code(500);
    echo "FORKPRESS_CAS_STORE, FORKPRESS_CAS_WP_ROOT, and FORKPRESS_CAS_DB_BASE are required\n";
    return true;
}

$host = $_SERVER['HTTP_HOST'] ?? $root_host;
$host = preg_replace('/:\d+$/', '', $host);

if ($host === $root_host) {
    $branch = 'main';
} elseif (str_ends_with($host, '.' . $root_host)) {
    $branch = substr($host, 0, -strlen('.' . $root_host));
} else {
    $branch = 'main';
}

if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,62}$/', $branch)) {
    http_response_code(400);
    echo "Invalid branch\n";
    return true;
}

branchfs_set_cas_store($store);
branchfs_set_root($wp_root);
branchfs_set_branch($branch);
if (!branchfs_activate()) {
    http_response_code(404);
    echo "Branch not found\n";
    return true;
}

$_SERVER['FORKPRESS_BRANCH'] = $branch;
putenv('FORKPRESS_BRANCH=' . $branch);
putenv('FORKPRESS_CAS_DB_BASE=' . $db_base);
header('X-ForkPress-Branch: ' . $branch);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (preg_match('|^/([a-zA-Z0-9_\-]+)\.git(/.*)?$|', $path)) {
    http_response_code(501);
    echo "Git smart HTTP is not wired for the cas strategy yet\n";
    return true;
}

if ($path === '/plugins.php' && !is_file("branchfs://$branch/plugins.php") && is_file("branchfs://$branch/wp-admin/plugins.php")) {
    $query = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?: '';
    header('Location: /wp-admin/plugins.php' . ($query !== '' ? '?' . $query : ''), true, 302);
    return true;
}

$target = $path === '/' ? 'index.php' : ltrim($path, '/');
if (str_ends_with($path, '/') && $target !== 'index.php') {
    $target .= 'index.php';
}

$branch_root = "branchfs://$branch";
$file = $branch_root . '/' . $target;

function forkpress_cas_send_file(string $path): void {
    $ext = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION));
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

function forkpress_cas_prepare_php_request(string $path, string $wp_root): void {
    chdir($wp_root);
    $_SERVER['DOCUMENT_ROOT'] = $wp_root;
    $_SERVER['SCRIPT_FILENAME'] = $wp_root . '/' . $path;
    $_SERVER['SCRIPT_NAME'] = '/' . $path;
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
}

if (is_file($file)) {
    if (strtolower(pathinfo($target, PATHINFO_EXTENSION)) === 'php') {
        forkpress_cas_prepare_php_request($target, $wp_root);
        require "branchfs://$branch/$target";
        return true;
    }
    forkpress_cas_send_file($file);
    return true;
}

forkpress_cas_prepare_php_request('index.php', $wp_root);
require "branchfs://$branch/index.php";
return true;

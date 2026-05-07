<?php
/**
 * BranchFS router for PHP built-in server.
 *
 * Branch resolution:
 *   - <root-domain>      -> main
 *   - <sub>.<root-domain> -> branch "<sub>"
 *
 * The root domain is BRANCHFS_ROOT_HOST (default: wp.localhost).
 * No cookies, no signed tokens: the subdomain IS the branch.
 *
 * To try it on a Mac add a wildcard entry to /etc/hosts, e.g.
 *     127.0.0.1   wp.localhost feature.wp.localhost marketing.wp.localhost
 * (or use dnsmasq for true wildcarding) and visit
 *     http://wp.localhost:18080/            -> main
 *     http://feature.wp.localhost:18080/    -> branch "feature"
 *
 * OPcache note: WordPress is loaded via `branchfs://<branch>/` URLs so
 * that OPcache keys compiled bytecode per branch. Using a single
 * absolute wp_root path across branches would let branch B serve branch
 * A's cached bytecode — see finding #1 in the review.
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);

$db_path   = getenv('BRANCHFS_DB');
$wp_root   = getenv('BRANCHFS_WP_ROOT');
$root_host = getenv('BRANCHFS_ROOT_HOST') ?: 'wp.localhost';

if (!$db_path || !$wp_root) {
    http_response_code(500);
    echo "BRANCHFS_DB and BRANCHFS_WP_ROOT env vars required\n";
    return true;
}
if (!extension_loaded('branchfs')) {
    http_response_code(500);
    echo "branchfs extension not loaded\n";
    return true;
}

// --- Branch resolution from HTTP Host header ---

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

// --- Init branchfs ---
branchfs_set_db($db_path);
branchfs_set_root($wp_root);
branchfs_set_branch($branch);
branchfs_activate();

// Set per-branch WordPress table prefix so wp-config.php uses b{id}_wp_
// instead of the static b1_wp_ fallback for every branch.
$_fp_sqlite = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
$_fp_branch_id = (int)($_fp_sqlite->querySingle(
    "SELECT id FROM branches WHERE name='" . SQLite3::escapeString($branch) . "'"
) ?: 1);
$_fp_sqlite->close();
unset($_fp_sqlite);
$GLOBALS['_branchfs_table_prefix'] = "b{$_fp_branch_id}_wp_";

// Drain pending OPcache invalidations from out-of-process writers
// (branchctl merge/reset/rollback). Each request pops any queued URLs
// and calls opcache_invalidate() so bytecode compiled before a merge
// is discarded before the next require. See experiments/branchfs/scripts/opcache.php.
require_once dirname(__DIR__) . '/scripts/opcache.php';
try {
    $_fp_opcache_db = new SQLite3($db_path, SQLITE3_OPEN_READWRITE);
    $_fp_opcache_db->busyTimeout(2000);
    opcache_process_pending($_fp_opcache_db);
    $_fp_opcache_db->close();
    unset($_fp_opcache_db);
} catch (\Throwable $_fp_opcache_err) {
    // OPcache invalidation is best-effort — never fail a request if the
    // queue drain hits an unexpected error (e.g. DB locked longer than
    // the busy timeout). The next request will retry.
}

// Configure sqlite-database-integration to use the same .fp file
if (!defined('FQDB')) {
    define('FQDB',    $db_path);
    define('DB_DIR',  dirname($db_path));
    define('DB_FILE', basename($db_path));
}

// chdir keeps relative-path-based libraries happy; PHP requires go through
// branchfs:// URLs below so OPcache keys per-branch.
chdir($wp_root);

$_SERVER['BRANCHFS_BRANCH'] = $branch;
header('X-BranchFS-Branch: ' . $branch);

// --- Git smart-HTTP endpoints ---
$uri  = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$query = parse_url($uri, PHP_URL_QUERY) ?: '';

if (preg_match('|^/([a-zA-Z0-9_\-]+)\.git(/.*)?$|', $path, $git_match)) {
    $git_site = $git_match[1];
    $git_path = $git_match[2] ?? '/';
    require_once dirname(__DIR__) . '/git/server.php';
    git_server_handle($db_path, $wp_root, $git_path, $query);
    return true;
}

// --- Route request via branchfs:// URL so OPcache keys per branch ---
$branch_root = "branchfs://$branch";

// Common admin-screen shortcut: WordPress keeps this page under wp-admin.
// If a site has its own root plugins.php file, serve that file instead.
if ($path === '/plugins.php'
    && !file_exists($branch_root . '/plugins.php')
    && file_exists($branch_root . '/wp-admin/plugins.php')) {
    header('Location: /wp-admin/plugins.php' . ($query !== '' ? '?' . $query : ''), true, 302);
    return true;
}

$file_url    = $branch_root . $path;

if (substr($path, -1) === '/') {
    $file_url .= 'index.php';
}

if (file_exists($file_url) && !is_dir($file_url)) {
    $ext = strtolower(pathinfo($file_url, PATHINFO_EXTENSION));

    if ($ext === 'php') {
        // Intentionally DO NOT pre-define ABSPATH here: wp-cron.php and
        // friends guard `if (!defined('ABSPATH')) require wp-load.php` and
        // must run that require. wp-config.php sets ABSPATH from __DIR__,
        // which is `branchfs://$branch` for this require — so OPcache keys
        // stay per-branch without us forcing the constant.
        $_SERVER['DOCUMENT_ROOT']   = $wp_root;
        $_SERVER['SCRIPT_FILENAME'] = $file_url;
        require $file_url;
        return true;
    }

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
    $content = file_get_contents($file_url);
    if ($content !== false) {
        header('Content-Length: ' . strlen($content));
        echo $content;
    } else {
        http_response_code(404);
    }
    return true;
}

if (is_dir($file_url)) {
    $index_url = rtrim($file_url, '/') . '/index.php';
    if (file_exists($index_url)) {
        $_SERVER['DOCUMENT_ROOT']   = $wp_root;
        $_SERVER['SCRIPT_FILENAME'] = $index_url;
        require $index_url;
        return true;
    }
}

// Fallback: WP pretty permalinks — run WordPress's front controller so it
// defines WP_USE_THEMES before loading wp-blog-header.php.
$_SERVER['DOCUMENT_ROOT']   = $wp_root;
$_SERVER['SCRIPT_FILENAME'] = "$branch_root/index.php";
require "$branch_root/index.php";
return true;

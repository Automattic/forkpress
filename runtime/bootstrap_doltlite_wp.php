<?php
/**
 * Bootstrap a Doltlite-backed WordPress branch.
 *
 * Usage:
 *   php bootstrap_doltlite_wp.php <site.doltlite> <wp-root> <branch> <site-title> <debug-log> [admin-password]
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

$db_path = $argv[1] ?? '';
$wp_root = rtrim($argv[2] ?? '', '/');
$branch = $argv[3] ?? 'main';
$site_title = $argv[4] ?? 'ForkPress';
$debug_log = $argv[5] ?? '/tmp/wp-debug.log';
$admin_password = $argv[6] ?? 'admin';

if (!extension_loaded('branchfs')) {
    die("bootstrap_doltlite_wp: branchfs extension not loaded\n");
}
if ($db_path === '' || $wp_root === '') {
    die("bootstrap_doltlite_wp: database and wp-root are required\n");
}
if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,62}$/', $branch)) {
    die("bootstrap_doltlite_wp: invalid branch $branch\n");
}

branchfs_set_db($db_path);
branchfs_set_root($wp_root);
branchfs_set_branch($branch);
if (!branchfs_activate()) {
    die("bootstrap_doltlite_wp: could not activate branch $branch\n");
}

$_SERVER = array_merge($_SERVER ?? [], [
    'HTTP_HOST'        => '127.0.0.1',
    'REQUEST_URI'      => '/',
    'REQUEST_METHOD'   => 'GET',
    'SERVER_NAME'      => '127.0.0.1',
    'SERVER_PORT'      => '80',
    'SERVER_PROTOCOL'  => 'HTTP/1.1',
    'DOCUMENT_ROOT'    => $wp_root,
    'SCRIPT_FILENAME'  => $wp_root . '/index.php',
    'FORKPRESS_BRANCH' => $branch,
]);
putenv('FORKPRESS_BRANCH=' . $branch);
putenv('FORKPRESS_DOLTLITE_DB=' . $db_path);
putenv('FORKPRESS_DOLTLITE_DEBUG_LOG=' . $debug_log);

$sqlite = new SQLite3($db_path, SQLITE3_OPEN_READWRITE);
$sqlite->busyTimeout(5000);
$checkout = @$sqlite->querySingle("SELECT dolt_checkout('" . SQLite3::escapeString($branch) . "')");
if ($checkout === false || $checkout === null) {
    die("bootstrap_doltlite_wp: could not checkout Doltlite branch $branch: " . $sqlite->lastErrorMsg() . "\n");
}
$has_options = (int)($sqlite->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='wp_options'") ?: 0);
$sqlite->close();

if ($has_options === 0) {
    define('WP_INSTALLING', true);
    ob_start();
    require_once "branchfs://$branch/wp-load.php";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $result = wp_install($site_title, 'admin', 'test@test.com', false, '', $admin_password);
    ob_end_clean();

    if (empty($result['user_id'])) {
        die("bootstrap_doltlite_wp: WordPress install failed\n");
    }
    echo "  WordPress installed (admin user_id={$result['user_id']})\n";
}

echo "  doltlite branch bootstrapped\n";

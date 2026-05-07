<?php
/**
 * Bootstrap a lazy Redb-backed CAS WordPress branch.
 *
 * Usage:
 *   php bootstrap_wp.php <store.redb> <wp-root> <db-base> <branch> <site-title> <debug-log> [admin-password]
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

$store = $argv[1] ?? '';
$wp_root = rtrim($argv[2] ?? '', '/');
$db_base = rtrim($argv[3] ?? '', '/');
$branch = $argv[4] ?? 'main';
$site_title = $argv[5] ?? 'ForkPress';
$debug_log = $argv[6] ?? '/tmp/wp-debug.log';
$admin_password = $argv[7] ?? 'admin';

if (!extension_loaded('branchfs')) {
    die("bootstrap_cas_wp: branchfs extension not loaded\n");
}
if ($store === '' || $wp_root === '' || $db_base === '') {
    die("bootstrap_cas_wp: store, wp-root, and db-base are required\n");
}

$db_dir = $db_base . '/' . $branch;
if (!is_dir($db_dir) && !mkdir($db_dir, 0755, true) && !is_dir($db_dir)) {
    die("bootstrap_cas_wp: could not create $db_dir\n");
}

branchfs_set_cas_store($store);
branchfs_set_root($wp_root);
branchfs_set_branch($branch);
if (!branchfs_activate()) {
    die("bootstrap_cas_wp: could not activate CAS branch $branch\n");
}

$_SERVER = array_merge($_SERVER ?? [], [
    'HTTP_HOST'       => '127.0.0.1',
    'REQUEST_URI'     => '/',
    'REQUEST_METHOD'  => 'GET',
    'SERVER_NAME'     => '127.0.0.1',
    'SERVER_PORT'     => '80',
    'SERVER_PROTOCOL' => 'HTTP/1.1',
    'DOCUMENT_ROOT'   => $wp_root,
    'SCRIPT_FILENAME' => $wp_root . '/index.php',
    'FORKPRESS_BRANCH'=> $branch,
]);
putenv('FORKPRESS_BRANCH=' . $branch);
putenv('FORKPRESS_CAS_DB_BASE=' . $db_base);
putenv('FORKPRESS_CAS_DEBUG_LOG=' . $debug_log);

if (!file_exists($db_dir . '/.ht.sqlite') || filesize($db_dir . '/.ht.sqlite') === 0) {
    define('WP_INSTALLING', true);
    ob_start();
    require_once "branchfs://$branch/wp-load.php";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $result = wp_install($site_title, 'admin', 'test@test.com', false, '', $admin_password);
    ob_end_clean();

    if (empty($result['user_id'])) {
        die("bootstrap_cas_wp: WordPress install failed\n");
    }
    echo "  WordPress installed (admin user_id={$result['user_id']})\n";
}

echo "  cas lazy branch bootstrapped\n";

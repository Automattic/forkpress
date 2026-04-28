<?php
/**
 * Refresh ForkPress-managed WordPress files in an existing BranchFS store.
 *
 * Usage: php refresh_wp_files.php <db-path> <wp-root> <site-title> <mu-plugin-path> <debug-log>
 * Env:   WP_TABLE_PREFIX — table prefix for WordPress tables (default: b1_wp_)
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

$db_path    = $argv[1] ?? die("Usage: php refresh_wp_files.php <db-path> <wp-root> <site-title> <mu-plugin> <debug-log>\n");
$wp_root    = rtrim($argv[2] ?? '', '/');
$site_title = $argv[3] ?? 'ForkPress';
$mu_plugin  = $argv[4] ?? null;
$debug_log  = $argv[5] ?? '/tmp/wp-debug.log';

$table_prefix = getenv('WP_TABLE_PREFIX') ?: 'b1_wp_';

require_once __DIR__ . '/managed_wp_files.php';

branchfs_set_db($db_path);
branchfs_set_root($wp_root);

forkpress_write_managed_wp_files(
    $db_path,
    $wp_root,
    $site_title,
    $mu_plugin,
    $debug_log,
    $table_prefix
);

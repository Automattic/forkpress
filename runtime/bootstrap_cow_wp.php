<?php
/**
 * Bootstrap a plain-filesystem WordPress branch for materialized ForkPress strategies.
 *
 * Usage:
 *   php bootstrap_cow_wp.php <branch-root> <site-title> <sqlite-plugin-source> <mu-plugin> <debug-log> [admin-password]
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

$branch_root = rtrim($argv[1] ?? '', '/');
$site_title = $argv[2] ?? 'ForkPress';
$sqlite_plugin_source = rtrim($argv[3] ?? '', '/');
$mu_plugin = $argv[4] ?? '';
$debug_log = $argv[5] ?? '/tmp/wp-debug.log';
$admin_password = $argv[6] ?? 'admin';

if ($branch_root === '' || !is_dir($branch_root)) {
    die("bootstrap_cow_wp: branch root missing or not a directory\n");
}
if (!file_exists($branch_root . '/wp-load.php')) {
    die("bootstrap_cow_wp: WordPress source missing in $branch_root\n");
}
if ($sqlite_plugin_source === '' || !is_dir($sqlite_plugin_source)) {
    die("bootstrap_cow_wp: sqlite plugin source missing\n");
}

function forkpress_cow_mkdir_p(string $path): void {
    if (is_dir($path)) {
        return;
    }
    if (!@mkdir($path, 0755, true) && !is_dir($path)) {
        die("bootstrap_cow_wp: could not create $path\n");
    }
}

function forkpress_cow_copy_tree(string $source_dir, string $dest_dir): int {
    if (!is_dir($source_dir)) {
        die("bootstrap_cow_wp: missing source directory $source_dir\n");
    }

    $copied = 0;
    forkpress_cow_mkdir_p($dest_dir);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        $source_path = $file->getPathname();
        $rel_path = substr($source_path, strlen($source_dir) + 1);
        $rel_path = str_replace(DIRECTORY_SEPARATOR, '/', $rel_path);
        $dest_path = rtrim($dest_dir, '/') . '/' . $rel_path;

        if ($file->isDir()) {
            forkpress_cow_mkdir_p($dest_path);
            continue;
        }

        forkpress_cow_mkdir_p(dirname($dest_path));
        if (!copy($source_path, $dest_path)) {
            die("bootstrap_cow_wp: could not copy $source_path to $dest_path\n");
        }
        $copied++;
    }

    return $copied;
}

$wp_content = $branch_root . '/wp-content';
$db_dir = $wp_content . '/database';
$db_file = '.ht.sqlite';
$db_path = $db_dir . '/' . $db_file;

forkpress_cow_mkdir_p($wp_content);
forkpress_cow_mkdir_p($db_dir);
forkpress_cow_mkdir_p($wp_content . '/plugins');
forkpress_cow_mkdir_p($wp_content . '/mu-plugins');

$plugin_dest = $wp_content . '/plugins/sqlite-database-integration';
$copied = 0;
if (!file_exists($plugin_dest . '/load.php')) {
    $copied = forkpress_cow_copy_tree($sqlite_plugin_source, $plugin_dest);
}

$dropin = <<<'PHP'
<?php
/**
 * ForkPress SQLite database drop-in for plain filesystem branches.
 */

define( 'SQLITE_DB_DROPIN_VERSION', '1.8.0' );

$sqlite_plugin_implementation_folder_path = __DIR__ . '/plugins/sqlite-database-integration';

if ( ! file_exists( $sqlite_plugin_implementation_folder_path . '/wp-includes/sqlite/db.php' ) ) {
	return;
}

if ( ! defined( 'DATABASE_TYPE' ) ) {
	define( 'DATABASE_TYPE', 'sqlite' );
}
if ( ! defined( 'DB_ENGINE' ) ) {
	define( 'DB_ENGINE', 'sqlite' );
}

require_once $sqlite_plugin_implementation_folder_path . '/wp-includes/sqlite/db.php';
PHP;
file_put_contents($wp_content . '/db.php', $dropin);

if ($mu_plugin !== '' && file_exists($mu_plugin)) {
    copy($mu_plugin, $wp_content . '/mu-plugins/branchfs-wp.php');
}

$config = <<<'PHP'
<?php
if (!defined('FQDB')) {
    define('FQDB',    '__FQDB__');
    define('DB_DIR',  '__DB_DIR__');
    define('DB_FILE', '__DB_FILE__');
}
define('DB_NAME', 'forkpress');
define('DB_USER', 'forkpress');
define('DB_PASSWORD', 'forkpress');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wp_';

define('AUTH_KEY',         'forkpress-cow-k1-xxxxxxxxxxxxxxxx');
define('SECURE_AUTH_KEY',  'forkpress-cow-k2-xxxxxxxxxxxxxxxx');
define('LOGGED_IN_KEY',    'forkpress-cow-k3-xxxxxxxxxxxxxxxx');
define('NONCE_KEY',        'forkpress-cow-k4-xxxxxxxxxxxxxxxx');
define('AUTH_SALT',        'forkpress-cow-s1-xxxxxxxxxxxxxxxx');
define('SECURE_AUTH_SALT', 'forkpress-cow-s2-xxxxxxxxxxxxxxxx');
define('LOGGED_IN_SALT',   'forkpress-cow-s3-xxxxxxxxxxxxxxxx');
define('NONCE_SALT',       'forkpress-cow-s4-xxxxxxxxxxxxxxxx');

define('WP_DEBUG', true);
define('WP_DEBUG_LOG', '__DEBUG_LOG__');
define('WP_DEBUG_DISPLAY', false);
define('DISALLOW_FILE_MODS', true);
define('WP_AUTO_UPDATE_CORE', false);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
if (!defined('DISABLE_WP_CRON')) {
    define('DISABLE_WP_CRON', true);
}

if (isset($_SERVER['HTTP_HOST'])) {
    define('WP_HOME',    'http://' . $_SERVER['HTTP_HOST']);
    define('WP_SITEURL', 'http://' . $_SERVER['HTTP_HOST']);
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once ABSPATH . 'wp-settings.php';
PHP;

$config = str_replace('__FQDB__', $db_path, $config);
$config = str_replace('__DB_DIR__', $db_dir, $config);
$config = str_replace('__DB_FILE__', $db_file, $config);
$config = str_replace('__DEBUG_LOG__', $debug_log, $config);
file_put_contents($branch_root . '/wp-config.php', $config);

if (!file_exists($db_path) || filesize($db_path) === 0) {
    chdir($branch_root);
    $_SERVER = array_merge($_SERVER ?? [], [
        'HTTP_HOST'       => '127.0.0.1',
        'REQUEST_URI'     => '/',
        'REQUEST_METHOD'  => 'GET',
        'SERVER_NAME'     => '127.0.0.1',
        'SERVER_PORT'     => '80',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'DOCUMENT_ROOT'   => $branch_root,
        'SCRIPT_FILENAME' => $branch_root . '/index.php',
        'FORKPRESS_BRANCH'=> 'main',
    ]);
    putenv('FORKPRESS_BRANCH=main');

    define('WP_INSTALLING', true);
    ob_start();
    require_once $branch_root . '/wp-load.php';
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $result = wp_install($site_title, 'admin', 'test@test.com', false, '', $admin_password);
    ob_end_clean();

    if (empty($result['user_id'])) {
        die("bootstrap_cow_wp: WordPress install failed\n");
    }
    echo "  WordPress installed (admin user_id={$result['user_id']})\n";
}

echo "  materialized branch root bootstrapped ($copied sqlite plugin files)\n";

<?php
/**
 * Bootstrap WordPress in BranchFS store (SQLite backend).
 *
 * Writes wp-config.php and mu-plugin into the store,
 * then installs WordPress via wp_install().
 *
 * Usage: php bootstrap_wp.php <db-path> <wp-root> <site-title> <mu-plugin-path> <debug-log>
 * Env:   WP_TABLE_PREFIX  — table prefix for WordPress tables (default: b1_wp_)
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

$db_path    = $argv[1] ?? die("Usage: php bootstrap_wp.php <db-path> <wp-root> <site-title> <mu-plugin> <debug-log>\n");
$wp_root    = rtrim($argv[2], '/');
$site_title = $argv[3] ?? 'ForkPress';
$mu_plugin  = $argv[4] ?? null;
$debug_log  = $argv[5] ?? '/tmp/wp-debug.log';

$table_prefix = getenv('WP_TABLE_PREFIX') ?: 'b1_wp_';

function branchfs_mkdir_p(string $path): void {
    if (is_dir($path)) {
        return;
    }
    if (!@mkdir($path, 0755, true) && !is_dir($path)) {
        die("ERROR: Could not create $path\n");
    }
}

function branchfs_copy_tree(string $source_dir, string $dest_dir): int {
    if (!is_dir($source_dir)) {
        die("ERROR: Missing source directory $source_dir\n");
    }

    $copied = 0;
    branchfs_mkdir_p($dest_dir);

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
            branchfs_mkdir_p($dest_path);
            continue;
        }

        branchfs_mkdir_p(dirname($dest_path));
        $bytes = file_put_contents($dest_path, file_get_contents($source_path));
        if ($bytes === false) {
            die("ERROR: Could not copy $source_path to $dest_path\n");
        }
        $copied++;
    }

    return $copied;
}

function install_sqlite_integration(string $vendor_dir): void {
    $plugin_source = rtrim($vendor_dir, '/') . '/sqlite-database-integration';
    $plugin_dest = 'branchfs://main/wp-content/plugins/sqlite-database-integration';

    $copied = branchfs_copy_tree($plugin_source, $plugin_dest);

    branchfs_mkdir_p('branchfs://main/wp-content');
    $dropin = <<<'PHP'
<?php
/**
 * ForkPress SQLite database drop-in.
 *
 * Loaded by WordPress before the mysqli requirement check. Keep paths relative
 * to this file so cloned branches load their own branchfs:// plugin copy.
 */

define( 'SQLITE_DB_DROPIN_VERSION', '1.8.0' );

$document_root = getenv( 'BRANCHFS_WP_ROOT' ) ?: ( $_SERVER['DOCUMENT_ROOT'] ?? '' );
$sqlite_plugin_implementation_folder_path = $document_root
	? rtrim( $document_root, '/' ) . '/wp-content/plugins/sqlite-database-integration'
	: '';

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

add_action(
	'admin_footer',
	function() {
		if ( defined( 'SQLITE_MAIN_FILE' ) ) {
			return;
		}
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin = 'sqlite-database-integration/load.php';
		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin;

		if ( file_exists( $plugin_path ) && is_plugin_inactive( $plugin ) ) {
			activate_plugin( $plugin, '', false, true );
		}
	}
);
PHP;

    $written = file_put_contents('branchfs://main/wp-content/db.php', $dropin);
    if ($written === false) {
        die("ERROR: Could not write wp-content/db.php\n");
    }
    echo "  sqlite drop-in installed ($copied plugin files, $written bytes)\n";
}

// --- Init branchfs (protocol only, no interception yet) ---
branchfs_set_db($db_path);
branchfs_set_root($wp_root);

// --- Write wp-config.php ---
// Uses FQDB/DB_DIR/DB_FILE for sqlite-database-integration plugin.
// table_prefix is branch-specific: b{branch_id}_wp_
$config = <<<CFG
<?php
// SQLite database integration constants
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

\$table_prefix = isset(\$GLOBALS['_branchfs_table_prefix'])
    ? \$GLOBALS['_branchfs_table_prefix']
    : '__TABLE_PREFIX__';

define('AUTH_KEY',         'forkpress-k1-xxxxxxxxxxxxxxxxxxxxx');
define('SECURE_AUTH_KEY',  'forkpress-k2-xxxxxxxxxxxxxxxxxxxxx');
define('LOGGED_IN_KEY',    'forkpress-k3-xxxxxxxxxxxxxxxxxxxxx');
define('NONCE_KEY',        'forkpress-k4-xxxxxxxxxxxxxxxxxxxxx');
define('AUTH_SALT',        'forkpress-s1-xxxxxxxxxxxxxxxxxxxxx');
define('SECURE_AUTH_SALT', 'forkpress-s2-xxxxxxxxxxxxxxxxxxxxx');
define('LOGGED_IN_SALT',   'forkpress-s3-xxxxxxxxxxxxxxxxxxxxx');
define('NONCE_SALT',       'forkpress-s4-xxxxxxxxxxxxxxxxxxxxx');

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

if (isset(\$_SERVER['HTTP_HOST'])) {
    define('WP_HOME',    'http://' . \$_SERVER['HTTP_HOST']);
    define('WP_SITEURL', 'http://' . \$_SERVER['HTTP_HOST']);
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once ABSPATH . 'wp-settings.php';
CFG;

$config = str_replace('__FQDB__',         $db_path,              $config);
$config = str_replace('__DB_DIR__',       dirname($db_path),     $config);
$config = str_replace('__DB_FILE__',      basename($db_path),    $config);
$config = str_replace('__TABLE_PREFIX__', $table_prefix,         $config);
$config = str_replace('__DEBUG_LOG__',    $debug_log,            $config);

$written = file_put_contents("branchfs://main/wp-config.php", $config);
if ($written === false) die("ERROR: Could not write wp-config.php\n");
echo "  wp-config.php written ($written bytes, table_prefix=$table_prefix)\n";

// --- Write mu-plugin ---
if ($mu_plugin && file_exists($mu_plugin)) {
    @mkdir("branchfs://main/wp-content/mu-plugins", 0755, true);
    file_put_contents(
        "branchfs://main/wp-content/mu-plugins/forkpress-wp.php",
        file_get_contents($mu_plugin)
    );
    echo "  mu-plugin installed\n";
}

install_sqlite_integration(dirname(__DIR__) . '/vendor');

// --- Install WordPress ---
branchfs_set_branch('main');
branchfs_activate();
chdir($wp_root);

$_SERVER = array_merge($_SERVER ?? [], [
    'HTTP_HOST'       => '127.0.0.1',
    'REQUEST_URI'     => '/',
    'REQUEST_METHOD'  => 'GET',
    'SERVER_NAME'     => '127.0.0.1',
    'SERVER_PORT'     => '80',
    'SERVER_PROTOCOL' => 'HTTP/1.1',
    'DOCUMENT_ROOT'   => $wp_root,
    'SCRIPT_FILENAME' => $wp_root . '/index.php',
]);

define('WP_INSTALLING', true);
define('FQDB',    $db_path);
define('DB_DIR',  dirname($db_path));
define('DB_FILE', basename($db_path));

$GLOBALS['_branchfs_table_prefix'] = $table_prefix;

ob_start();
require_once $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

$result = wp_install($site_title, 'admin', 'test@test.com', false, '', 'admin');
ob_end_clean();

if (!empty($result['user_id']) && $result['user_id'] > 0) {
    echo "  WordPress installed (admin user_id={$result['user_id']})\n";
} else {
    echo "  WARNING: wp_install result: " . print_r($result, true) . "\n";
}

// --- Record initial fs_commit so reset/rollback has a landing point ---
$sd = new SQLite3($db_path, SQLITE3_OPEN_READWRITE);
$bid = (int)$sd->querySingle("SELECT id FROM branches WHERE name = 'main'");
if ($bid) {
    $existing = (int)$sd->querySingle("SELECT COUNT(*) FROM fs_commits WHERE branch_id = $bid");
    if (!$existing) {
        $sd->exec("INSERT INTO fs_commits (branch_id, message) VALUES ($bid, 'Initial WordPress install')");
        $cid = $sd->lastInsertRowID();
        $sd->exec('BEGIN');
        $sd->exec("INSERT OR IGNORE INTO fs_commit_files (commit_id, path, blob_hash, mode, mtime, is_dir) "
            . "SELECT $cid, path, blob_hash, mode, mtime, is_dir FROM files WHERE branch_id = $bid");
        $sd->exec('COMMIT');
        $n = (int)$sd->querySingle("SELECT COUNT(*) FROM fs_commit_files WHERE commit_id = $cid");
        echo "  fs_commit #$cid recorded ($n files)\n";
    }
}
$sd->close();

<?php
/**
 * Write ForkPress-managed WordPress files into the BranchFS store.
 */

function forkpress_branchfs_mkdir_p(string $path): void {
    if (is_dir($path)) {
        return;
    }
    if (!@mkdir($path, 0755, true) && !is_dir($path)) {
        die("ERROR: Could not create $path\n");
    }
}

function forkpress_branchfs_copy_tree(string $source_dir, string $dest_dir): int {
    if (!is_dir($source_dir)) {
        die("ERROR: Missing source directory $source_dir\n");
    }

    $copied = 0;
    forkpress_branchfs_mkdir_p($dest_dir);

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
            forkpress_branchfs_mkdir_p($dest_path);
            continue;
        }

        forkpress_branchfs_mkdir_p(dirname($dest_path));
        $bytes = file_put_contents($dest_path, file_get_contents($source_path));
        if ($bytes === false) {
            die("ERROR: Could not copy $source_path to $dest_path\n");
        }
        $copied++;
    }

    return $copied;
}

function forkpress_install_sqlite_integration(string $vendor_dir): void {
    $plugin_source = rtrim($vendor_dir, '/') . '/sqlite-database-integration';
    $plugin_dest = 'branchfs://main/wp-content/plugins/sqlite-database-integration';

    $copied = forkpress_branchfs_copy_tree($plugin_source, $plugin_dest);

    forkpress_branchfs_mkdir_p('branchfs://main/wp-content');
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

function forkpress_write_wp_config(
    string $db_path,
    string $debug_log,
    string $table_prefix
): void {
    $config = <<<'CFG'
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

$table_prefix = isset($GLOBALS['_branchfs_table_prefix'])
    ? $GLOBALS['_branchfs_table_prefix']
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
define('FS_METHOD', 'direct');
define('DISALLOW_FILE_MODS', false);
define('DISALLOW_FILE_EDIT', true);
define('WP_AUTO_UPDATE_CORE', false);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_HTTP_BLOCK_EXTERNAL', false);
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
CFG;

    $config = str_replace('__FQDB__',         $db_path,              $config);
    $config = str_replace('__DB_DIR__',       dirname($db_path),     $config);
    $config = str_replace('__DB_FILE__',      basename($db_path),    $config);
    $config = str_replace('__TABLE_PREFIX__', $table_prefix,         $config);
    $config = str_replace('__DEBUG_LOG__',    $debug_log,            $config);

    $written = file_put_contents('branchfs://main/wp-config.php', $config);
    if ($written === false) {
        die("ERROR: Could not write wp-config.php\n");
    }
    echo "  wp-config.php written ($written bytes, table_prefix=$table_prefix)\n";
}

function forkpress_write_mu_plugins(?string $mu_plugin, ?string $experiment_mu_plugin): void {
    $mu_plugins = [
        'forkpress-wp.php' => $mu_plugin,
        'forkpress-experiments-wp.php' => $experiment_mu_plugin,
    ];
    foreach ($mu_plugins as $filename => $source) {
        if (!$source || !file_exists($source)) {
            continue;
        }

        forkpress_branchfs_mkdir_p('branchfs://main/wp-content/mu-plugins');
        file_put_contents(
            'branchfs://main/wp-content/mu-plugins/' . $filename,
            file_get_contents($source)
        );
        echo "  mu-plugin $filename installed\n";
    }
}

function forkpress_write_managed_wp_files(
    string $db_path,
    string $wp_root,
    string $site_title,
    ?string $mu_plugin,
    string $debug_log,
    string $table_prefix,
    ?string $experiment_mu_plugin = null
): void {
    forkpress_write_wp_config($db_path, $debug_log, $table_prefix);
    forkpress_write_mu_plugins($mu_plugin, $experiment_mu_plugin);
    forkpress_install_sqlite_integration(dirname(__DIR__, 3) . '/vendor');
}

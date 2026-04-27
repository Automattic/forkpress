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

// --- Init branchfs (protocol only, no interception yet) ---
branchfs_set_db($db_path);
branchfs_set_root($wp_root);

// --- Write wp-config.php ---
// Uses FQDB/DB_DIR/DB_FILE for sqlite-database-integration plugin.
// table_prefix is branch-specific: b{branch_id}_wp_
$config = <<<CFG
<?php
// SQLite database integration constants
define('FQDB',    '__FQDB__');
define('DB_DIR',  '__DB_DIR__');
define('DB_FILE', '__DB_FILE__');

\$table_prefix = isset(\$GLOBALS['_branchfs_table_prefix'])
    ? \$GLOBALS['_branchfs_table_prefix']
    : '__TABLE_PREFIX__';

define('AUTH_KEY',         'e2e-k1-xxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('SECURE_AUTH_KEY',  'e2e-k2-xxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('LOGGED_IN_KEY',    'e2e-k3-xxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('NONCE_KEY',        'e2e-k4-xxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('AUTH_SALT',        'e2e-s1-xxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('SECURE_AUTH_SALT', 'e2e-s2-xxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('LOGGED_IN_SALT',   'e2e-s3-xxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('NONCE_SALT',       'e2e-s4-xxxxxxxxxxxxxxxxxxxxxxxxxxx');

define('WP_DEBUG', true);
define('WP_DEBUG_LOG', '__DEBUG_LOG__');
define('WP_DEBUG_DISPLAY', false);
define('DISALLOW_FILE_MODS', true);
define('WP_AUTO_UPDATE_CORE', false);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);

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
        "branchfs://main/wp-content/mu-plugins/branchfs-wp.php",
        file_get_contents($mu_plugin)
    );
    echo "  mu-plugin installed\n";
}

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

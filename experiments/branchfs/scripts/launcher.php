<?php
/* : <<'__END__' */
/**
 * BranchFS Launcher - The single real file on disk.
 *
 * Validates branch context from signed cookie/header, configures the
 * branchfs extension, and boots WordPress from the SQLite store.
 *
 * Usage: This file replaces /var/www/html/index.php on the server.
 * All WordPress files live in the SQLite database, not on disk.
 */

// Configuration - adjust for your environment
define('BRANCHFS_DB',      getenv('BRANCHFS_DB')      ?: __DIR__ . '/../branchfs.db');
define('BRANCHFS_WP_ROOT', getenv('BRANCHFS_WP_ROOT') ?: dirname(__DIR__) . '/wproot');
define('BRANCHFS_SECRET',  getenv('BRANCHFS_SECRET')  ?: 'dev-secret-change-in-production');
define('BRANCHFS_DEFAULT', 'main');

/**
 * Determine branch from request context.
 * Priority: X-Branch header > wp_branch cookie > default
 */
function branchfs_resolve_branch(): string {
    // 1. Check header (for API/CI usage)
    $header_branch = $_SERVER['HTTP_X_BRANCH'] ?? null;
    if ($header_branch && branchfs_validate_branch_token($header_branch)) {
        return $header_branch;
    }

    // 2. Check signed cookie
    $cookie = $_COOKIE['wp_branch'] ?? null;
    if ($cookie) {
        $parts = explode(':', $cookie, 2);
        if (count($parts) === 2) {
            [$branch, $sig] = $parts;
            $expected = hash_hmac('sha256', $branch, BRANCHFS_SECRET);
            if (hash_equals($expected, $sig)) {
                return $branch;
            }
        }
    }

    // 3. Check query parameter (for preview links)
    $query_branch = $_GET['_branch'] ?? null;
    if ($query_branch && branchfs_validate_branch_token($query_branch)) {
        // Set cookie for subsequent requests
        $sig = hash_hmac('sha256', $query_branch, BRANCHFS_SECRET);
        setcookie('wp_branch', "$query_branch:$sig", [
            'path'     => '/',
            'httponly'  => true,
            'samesite'  => 'Lax',
            'secure'    => isset($_SERVER['HTTPS']),
        ]);
        return $query_branch;
    }

    return BRANCHFS_DEFAULT;
}

function branchfs_validate_branch_token(string $branch): bool {
    return (bool) preg_match('/^[a-zA-Z0-9_\-\/\.]{1,128}$/', $branch);
}

/**
 * Generate a signed preview URL for a given branch.
 */
function branchfs_preview_url(string $branch, string $path = '/'): string {
    $sig = hash_hmac('sha256', $branch, BRANCHFS_SECRET);
    $base = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $base . $path . '?_branch=' . urlencode($branch);
}

// ---- Boot sequence ----

if (!extension_loaded('branchfs')) {
    http_response_code(500);
    die('branchfs extension not loaded');
}

$branch = branchfs_resolve_branch();

// Resolve branch_id for table prefix
$_fp_db = new SQLite3(BRANCHFS_DB, SQLITE3_OPEN_READONLY);
$_branch_id = $_fp_db->querySingle(
    "SELECT id FROM branches WHERE name = " . SQLite3::escapeString("'$branch'")
);
$_fp_db->close();
$_branch_id = $_branch_id ?: 1;

require_once __DIR__ . '/branched_pdo.php';

// WordPress table prefix: b{branch_id}_wp_
$GLOBALS['_branchfs_table_prefix'] = "b{$_branch_id}_wp_";

// Configure Automattic sqlite-database-integration to use site.fp
// so the WordPress database lives in the same file as the filesystem.
$wp_db_path = getenv('BRANCHFS_SQLITE_WP_DB') ?: BRANCHFS_DB;
define('FQDB', $wp_db_path);
define('DB_DIR', dirname($wp_db_path));
define('DB_FILE', basename($wp_db_path));

// Initialize the branchfs extension
branchfs_set_db(BRANCHFS_DB);
branchfs_set_root(BRANCHFS_WP_ROOT);
branchfs_set_branch($branch);
branchfs_activate();

// Change to WP root so relative paths resolve correctly
chdir(BRANCHFS_WP_ROOT);

// Boot WordPress
$wp_blog_header = BRANCHFS_WP_ROOT . '/wp-blog-header.php';
if (!file_exists($wp_blog_header)) {
    http_response_code(500);
    die("WordPress not found in store for branch: $branch");
}

// Set SERVER vars WordPress expects
$_SERVER['DOCUMENT_ROOT'] = BRANCHFS_WP_ROOT;
$_SERVER['SCRIPT_FILENAME'] = BRANCHFS_WP_ROOT . '/index.php';

require $wp_blog_header;
?>
__END__

<?php
/**
 * Test that realpath() and is_file() are intercepted for branchfs-backed
 * paths, so code like WP's wp-admin/includes/noop.php::get_file() works.
 */

$pass = 0; $fail = 0;
function assert_true($cond, $msg) {
    global $pass, $fail;
    if ($cond) { echo "  PASS: $msg\n"; $pass++; }
    else       { echo "  FAIL: $msg\n"; $fail++; }
}

$DB = '/tmp/branchfs_rp_' . getmypid() . '.db';
$ROOT = '/tmp/branchfs_rp_root_' . getmypid();

@unlink($DB);

// Initialize schema
$db = new SQLite3($DB);
$db->exec(file_get_contents(__DIR__ . '/../experiments/branchfs/schema.sql'));
$db->close();

branchfs_set_db($DB);
branchfs_set_root($ROOT);
branchfs_set_branch('main');
branchfs_create_branch('main', null);

// Write via branchfs:// before activation (same pattern as test_plugin_compat)
file_put_contents("branchfs://main/wp-admin/css/dashicons.min.css", "body { color: red; }\n");

branchfs_activate();

// Now exercise the override path that noop.php uses
$path = $ROOT . '/wp-admin/./css/../css/dashicons.min.css';   // has . and .. segments
$rp = realpath($path);
assert_true($rp !== false, 'realpath returns non-false for branchfs-backed file');
assert_true($rp === $ROOT . '/wp-admin/css/dashicons.min.css', "realpath canonicalizes (got: " . var_export($rp, true) . ")");

assert_true(is_file($rp), 'is_file returns true for branchfs-backed file');
assert_true(!is_dir($rp), 'is_dir returns false for branchfs-backed file');
assert_true(is_readable($rp), 'is_readable returns true for branchfs-backed file');

// Simulate noop::get_file()
function get_file($path) {
    $path = realpath($path);
    if (!$path || !@is_file($path)) return '';
    return @file_get_contents($path);
}
$content = get_file($path);
assert_true($content === "body { color: red; }\n", 'get_file() returns expected content (got ' . strlen($content) . ' bytes)');

// Non-existent file: realpath should return false (defers to original handler)
$miss = realpath($ROOT . '/nope/missing.css');
assert_true($miss === false, 'realpath returns false for missing file');

branchfs_deactivate();
@unlink($DB);
@unlink($DB . '-wal');
@unlink($DB . '-shm');

echo "\n=== realpath tests: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);

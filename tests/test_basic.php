<?php
/**
 * Basic tests for the branchfs extension.
 * Tests: stream wrapper, SQLite store, branch operations.
 */

$pass = 0;
$fail = 0;

function assert_true($cond, $msg) {
    global $pass, $fail;
    if ($cond) {
        echo "  PASS: $msg\n";
        $pass++;
    } else {
        echo "  FAIL: $msg\n";
        $fail++;
    }
}

function assert_eq($a, $b, $msg) {
    assert_true($a === $b, "$msg (got " . var_export($a, true) . ", expected " . var_export($b, true) . ")");
}

$DB = '/tmp/branchfs_test_' . getmypid() . '.db';

echo "=== BranchFS Basic Tests ===\n\n";

// --- Test 1: Extension loaded ---
echo "# Extension loading\n";
assert_true(extension_loaded('branchfs'), 'Extension loaded');
assert_true(function_exists('branchfs_set_db'), 'branchfs_set_db exists');
assert_true(function_exists('branchfs_set_root'), 'branchfs_set_root exists');
assert_true(function_exists('branchfs_set_branch'), 'branchfs_set_branch exists');
assert_true(function_exists('branchfs_activate'), 'branchfs_activate exists');

// --- Test 2: Initialize database ---
echo "\n# Database initialization\n";
// Create schema
$db = new SQLite3($DB);
$schema = file_get_contents(__DIR__ . '/../sql/schema.sql');
$db->exec($schema);
$db->close();

assert_true(branchfs_set_db($DB), 'Set database path');
assert_true(branchfs_set_root('/tmp/branchfs_wproot'), 'Set WP root');
assert_true(branchfs_set_branch('main'), 'Set branch to main');

// --- Test 3: Branch operations ---
echo "\n# Branch operations\n";
$main_id = branchfs_create_branch('main', null);
assert_true($main_id > 0, "Main branch exists (id=$main_id)");

$feat_id = branchfs_create_branch('feature-1', 'main');
assert_true($feat_id > 0, "Created feature-1 branch (id=$feat_id)");
assert_true($feat_id !== $main_id, "Feature branch has different ID");

assert_eq(branchfs_get_branch(), 'main', 'Current branch is main');

// --- Test 4: branchfs:// stream wrapper - write and read ---
echo "\n# Stream wrapper (branchfs://)\n";

$content = "<?php echo 'Hello from BranchFS!';\n";
$written = file_put_contents('branchfs://main/index.php', $content);
assert_true($written !== false, 'Write via branchfs://main/index.php');

$read = file_get_contents('branchfs://main/index.php');
assert_eq($read, $content, 'Read back matches written content');

// --- Test 5: File exists, stat ---
echo "\n# File existence and stat\n";
assert_true(file_exists('branchfs://main/index.php'), 'file_exists returns true');
assert_true(!file_exists('branchfs://main/nonexistent.php'), 'file_exists returns false for missing');

$stat = stat('branchfs://main/index.php');
assert_true($stat !== false, 'stat() works');
assert_eq($stat['size'], strlen($content), 'stat size matches');
assert_true(($stat['mode'] & 0170000) === 0100000, 'stat mode is regular file');

// --- Test 6: Directory operations ---
echo "\n# Directory operations\n";
assert_true(mkdir('branchfs://main/wp-content', 0755), 'mkdir works');
assert_true(mkdir('branchfs://main/wp-content/plugins', 0755), 'nested mkdir works');

file_put_contents('branchfs://main/wp-content/plugins/hello.php', '<?php // Hello');
assert_true(file_exists('branchfs://main/wp-content/plugins/hello.php'), 'file in subdir exists');

// --- Test 7: Directory listing ---
echo "\n# Directory listing\n";
file_put_contents('branchfs://main/wp-content/plugins/world.php', '<?php // World');
$entries = scandir('branchfs://main/wp-content/plugins');
assert_true(is_array($entries), 'scandir returns array');
assert_true(in_array('hello.php', $entries), 'scandir includes hello.php');
assert_true(in_array('world.php', $entries), 'scandir includes world.php');

// --- Test 8: Copy-on-write (branch isolation) ---
echo "\n# Copy-on-write branch isolation\n";

// Write to main
file_put_contents('branchfs://main/shared.txt', 'main version');

// Read from feature-1 (should inherit from main)
$inherited = file_get_contents('branchfs://feature-1/shared.txt');
assert_eq($inherited, 'main version', 'Feature branch inherits from main');

// Write to feature-1
file_put_contents('branchfs://feature-1/shared.txt', 'feature version');

// Verify isolation
$main_ver = file_get_contents('branchfs://main/shared.txt');
$feat_ver = file_get_contents('branchfs://feature-1/shared.txt');
assert_eq($main_ver, 'main version', 'Main branch unaffected by feature write');
assert_eq($feat_ver, 'feature version', 'Feature branch has its own version');

// --- Test 9: Feature-only file ---
echo "\n# Feature-only files\n";
file_put_contents('branchfs://feature-1/feature-only.txt', 'only on feature');
assert_true(file_exists('branchfs://feature-1/feature-only.txt'), 'Feature-only file exists on feature');
assert_true(!file_exists('branchfs://main/feature-only.txt'), 'Feature-only file absent on main');

// --- Test 10: Unlink ---
echo "\n# Unlink (tombstone)\n";
file_put_contents('branchfs://main/deleteme.txt', 'to be deleted');
assert_true(file_exists('branchfs://feature-1/deleteme.txt'), 'Inherited file exists before delete');
unlink('branchfs://feature-1/deleteme.txt');
assert_true(!file_exists('branchfs://feature-1/deleteme.txt'), 'File gone on feature after unlink');
assert_true(file_exists('branchfs://main/deleteme.txt'), 'File still exists on main after feature unlink');

// --- Test 11: Seek/tell ---
echo "\n# Stream seek and tell\n";
$fp = fopen('branchfs://main/index.php', 'r');
assert_true($fp !== false, 'fopen works');
$first5 = fread($fp, 5);
assert_eq($first5, '<?php', 'fread first 5 bytes');
assert_eq(ftell($fp), 5, 'ftell after reading 5 bytes');
fseek($fp, 0);
assert_eq(ftell($fp), 0, 'ftell after seeking to 0');
fclose($fp);

// --- Cleanup ---
@unlink($DB);
@unlink($DB . '-wal');
@unlink($DB . '-shm');

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);

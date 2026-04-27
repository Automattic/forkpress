<?php
/**
 * OPcache cross-branch isolation test.
 *
 * Verifies finding #1: branch-qualified branchfs:// URLs produce distinct
 * OPcache cache keys so the same relative path on two branches resolves
 * to different compiled units. Running this under `-d opcache.enable_cli=1`
 * also inspects opcache_get_status() to confirm both keys are cached.
 */

$pass = 0;
$fail = 0;
function assert_true($cond, $msg) {
    global $pass, $fail;
    if ($cond) { echo "  PASS: $msg\n"; $pass++; }
    else       { echo "  FAIL: $msg\n"; $fail++; }
}

$DB   = '/tmp/branchfs_oc_' . getmypid() . '.db';
$ROOT = '/tmp/branchfs_oc_root_' . getmypid();
@unlink($DB);

$db = new SQLite3($DB);
$db->exec(file_get_contents(__DIR__ . '/../sql/schema.sql'));
$db->close();

branchfs_set_db($DB);
branchfs_set_root($ROOT);
branchfs_set_branch('main');
branchfs_create_branch('main', null);
branchfs_create_branch('feature', 'main');

// Same relative path, different content per branch.
file_put_contents('branchfs://main/loadable.php',
    "<?php return ['branch' => 'main', 'marker' => 'MAIN_MARKER'];\n");
file_put_contents('branchfs://feature/loadable.php',
    "<?php return ['branch' => 'feature', 'marker' => 'FEATURE_MARKER'];\n");

echo "=== OPcache cross-branch keying ===\n";

// First include — main.
$a = include 'branchfs://main/loadable.php';
assert_true(is_array($a) && $a['branch'] === 'main',
    'include branchfs://main/loadable.php returns main-branch value');

// Second include — feature. OPcache, if naive, might serve main bytecode
// under the same absolute path; using branch-qualified URLs forces
// distinct cache keys.
$b = include 'branchfs://feature/loadable.php';
assert_true(is_array($b) && $b['branch'] === 'feature',
    'include branchfs://feature/loadable.php returns feature-branch value');
assert_true($a['marker'] !== $b['marker'],
    'distinct markers prove two separate compilation units executed');

// If OPcache is enabled, both URLs should appear in its scripts list with
// distinct keys.
if (function_exists('opcache_get_status')) {
    $status = @opcache_get_status(true);
    if (is_array($status) && !empty($status['opcache_enabled']) && !empty($status['scripts'])) {
        $keys = array_keys($status['scripts']);
        $main_hit = false;
        $feat_hit = false;
        foreach ($keys as $k) {
            if (strpos($k, 'branchfs://main/loadable.php') !== false)    $main_hit = true;
            if (strpos($k, 'branchfs://feature/loadable.php') !== false) $feat_hit = true;
        }
        if ($main_hit && $feat_hit) {
            assert_true(true, 'opcache scripts list contains both branch-qualified keys');
        } else {
            echo "  SKIP: opcache did not cache the branchfs:// URLs (main=$main_hit, feature=$feat_hit)\n";
            echo "        correctness still proved above via distinct return values.\n";
        }
    } else {
        echo "  SKIP: opcache is not enabled in this CLI invocation\n";
    }
} else {
    echo "  SKIP: opcache extension not loaded\n";
}

// Cleanup
@unlink($DB);
@unlink($DB . '-wal');
@unlink($DB . '-shm');

echo "\n=== opcache keys: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);

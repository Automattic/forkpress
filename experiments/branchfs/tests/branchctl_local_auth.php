<?php
/**
 * branchctl local-control auth test.
 *
 * Auth-enabled sites must reject unauthenticated direct writes, but the
 * bundled local CLI marks operator control commands with FORKPRESS_LOCAL_CTL=1.
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

$DB = '/tmp/branchfs_local_auth_' . getmypid() . '.db';
@unlink($DB);
@unlink($DB . '-wal');
@unlink($DB . '-shm');

$php = escapeshellcmd(PHP_BINARY);
$ext = escapeshellarg(realpath(__DIR__ . '/../php-ext/branchfs.so'));
$init = $php . ' -d extension=' . $ext
      . ' ' . escapeshellarg(__DIR__ . '/../scripts/init_db.php')
      . ' ' . escapeshellarg($DB)
      . ' --admin-password admin';
$branchctl = $php . ' -d extension=' . $ext
           . ' ' . escapeshellarg(__DIR__ . '/../scripts/branchctl.php');

echo "=== branchctl local auth tests ===\n\n";

$out = [];
$rc = 0;
exec($init . ' 2>&1', $out, $rc);
assert_true($rc === 0, "init_db exits 0 (got $rc)");

$db = new SQLite3($DB, SQLITE3_OPEN_READONLY);
$auth_enabled = (string)$db->querySingle("SELECT value FROM site_config WHERE key = 'auth_enabled'");
$db->close();
assert_true($auth_enabled === '1', 'test site has auth_enabled=1');

echo "\n# Direct branchctl write without credentials\n";
$out = [];
$rc = 0;
exec('BRANCHFS_DB=' . escapeshellarg($DB) . ' ' . $branchctl . ' create marketing 2>&1', $out, $rc);
$joined = implode("\n", $out);
assert_true($rc === 2, "direct create exits 2 without auth (got $rc)");
assert_true(strpos($joined, 'auth_enabled=1') !== false, 'direct create explains auth requirement');

$db = new SQLite3($DB, SQLITE3_OPEN_READONLY);
$count = (int)$db->querySingle("SELECT COUNT(*) FROM branches WHERE name = 'marketing'");
$db->close();
assert_true($count === 0, 'direct unauthenticated create did not create branch');

echo "\n# Bundled local control path\n";
$out = [];
$rc = 0;
exec('BRANCHFS_DB=' . escapeshellarg($DB)
   . ' FORKPRESS_LOCAL_CTL=1 '
   . $branchctl . ' create marketing 2>&1',
    $out,
    $rc
);
$joined = implode("\n", $out);
assert_true($rc === 0, "local-control create exits 0 (got $rc)");
assert_true(strpos($joined, "forked 'main' -> 'marketing'") !== false, 'local-control create reports branch fork');

$db = new SQLite3($DB, SQLITE3_OPEN_READONLY);
$count = (int)$db->querySingle("SELECT COUNT(*) FROM branches WHERE name = 'marketing'");
$actor = (string)$db->querySingle(
    "SELECT actor FROM audit_log WHERE action = 'create' ORDER BY id DESC LIMIT 1"
);
$db->close();
assert_true($count === 1, 'local-control create created branch');
assert_true($actor === 'local' || str_starts_with($actor, 'local:'), 'audit actor is local synthetic principal');

@unlink($DB);
@unlink($DB . '-wal');
@unlink($DB . '-shm');

echo "\n=== branchctl local auth tests: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);

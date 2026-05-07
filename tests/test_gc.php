<?php
/**
 * branchctl gc test (finding #8).
 *
 * Writes blobs on a branch, deletes the branch, runs gc, asserts blob
 * count drops by the expected number.
 */

$pass = 0; $fail = 0;
function assert_true($cond, $msg) {
    global $pass, $fail;
    if ($cond) { echo "  PASS: $msg\n"; $pass++; }
    else       { echo "  FAIL: $msg\n"; $fail++; }
}

$DB   = '/tmp/branchfs_gc_' . getmypid() . '.db';
$ROOT = '/tmp/branchfs_gc_root_' . getmypid();
@unlink($DB);

$db = new SQLite3($DB);
$db->exec(file_get_contents(__DIR__ . '/../experiments/branchfs/schema.sql'));
$db->close();

branchfs_set_db($DB);
branchfs_set_root($ROOT);

// Seed main with one blob.
file_put_contents('branchfs://main/shared.txt', "shared content\n");
$blobs_after_main = (int)(new SQLite3($DB))->querySingle("SELECT COUNT(*) FROM blobs");
branchfs_set_db($DB);

// Fork feature branch and write 3 unique blobs only on it.
branchfs_create_branch('feature', 'main');
file_put_contents('branchfs://feature/a.txt', "feature-a content\n");
file_put_contents('branchfs://feature/b.txt', "feature-b content\n");
file_put_contents('branchfs://feature/c.txt', "feature-c content\n");

$blobs_before_delete = (int)(new SQLite3($DB))->querySingle("SELECT COUNT(*) FROM blobs");
assert_true($blobs_before_delete === $blobs_after_main + 3,
    "4 total blobs before delete (got $blobs_before_delete, expected " . ($blobs_after_main + 3) . ")");

// Delete feature branch — keeps the blobs in the store (branchctl delete
// just drops `files` + `branches` rows).
$db = new SQLite3($DB);
$db->exec("DELETE FROM files WHERE branch_id = (SELECT id FROM branches WHERE name = 'feature')");
$db->exec("DELETE FROM branches WHERE name = 'feature'");
$db->close();

$blobs_after_delete = (int)(new SQLite3($DB))->querySingle("SELECT COUNT(*) FROM blobs");
assert_true($blobs_after_delete === $blobs_before_delete,
    "blobs unchanged after branch delete (gc hasn't run yet)");

// Dry-run first.
$branchctl = escapeshellcmd(PHP_BINARY)
           . ' -d extension=' . escapeshellarg(realpath(__DIR__ . '/../experiments/branchfs/php-ext/branchfs.so'))
           . ' ' . escapeshellarg(__DIR__ . '/../experiments/branchfs/scripts/branchctl.php');
$out = [];
$rc  = 0;
exec("BRANCHFS_DB=" . escapeshellarg($DB) . " $branchctl gc --dry-run 2>&1", $out, $rc);
$joined = implode("\n", $out);
assert_true($rc === 0,                            "gc --dry-run exits 0 (got $rc)");
assert_true(strpos($joined, 'dry-run') !== false, "dry-run output mentions dry-run");

$blobs_still_intact = (int)(new SQLite3($DB))->querySingle("SELECT COUNT(*) FROM blobs");
assert_true($blobs_still_intact === $blobs_after_delete,
    "dry-run left blob count unchanged");

// Real gc.
$out = [];
$rc  = 0;
exec("BRANCHFS_DB=" . escapeshellarg($DB) . " $branchctl gc 2>&1", $out, $rc);
$joined = implode("\n", $out);
assert_true($rc === 0, "gc exits 0 (got $rc)");
assert_true(strpos($joined, 'reclaimed') !== false || strpos($joined, 'deleted') !== false,
    "gc output mentions reclamation");

$blobs_after_gc = (int)(new SQLite3($DB))->querySingle("SELECT COUNT(*) FROM blobs");
assert_true($blobs_after_gc === $blobs_after_main,
    "gc reclaimed exactly 3 orphaned blobs (got $blobs_after_gc, expected $blobs_after_main)");

// Idempotent: running again is a no-op.
$out = [];
$rc  = 0;
exec("BRANCHFS_DB=" . escapeshellarg($DB) . " $branchctl gc 2>&1", $out, $rc);
$joined = implode("\n", $out);
assert_true($rc === 0, "second gc exits 0");
assert_true(strpos($joined, 'nothing to reclaim') !== false,
    "second gc reports nothing to reclaim");

@unlink($DB);
@unlink($DB . '-wal');
@unlink($DB . '-shm');

echo "\n=== gc tests: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);

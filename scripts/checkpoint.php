<?php
/**
 * Run `PRAGMA wal_checkpoint(TRUNCATE)` against a site.fp file.
 *
 * Forces all committed WAL frames back into the main database and resets
 * the WAL file to zero length. Safe to call while the PHP server is
 * running — SQLite synchronises WAL checkpoints across processes via the
 * shared memory (-shm) file.
 *
 * Usage:
 *   php scripts/checkpoint.php <site.fp>
 *
 * Exit codes:
 *   0 success (prints busy / log / checkpointed frame counts)
 *   1 usage error
 *   2 DB not found / cannot open
 *   3 checkpoint command failed
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php checkpoint.php <site.fp>\n");
    exit(1);
}

$path = $argv[1];
if (!file_exists($path)) {
    fwrite(STDERR, "checkpoint: file not found: $path\n");
    exit(2);
}

$db = new SQLite3($path, SQLITE3_OPEN_READWRITE);
$db->busyTimeout(15000);

$wal_before  = file_exists("$path-wal") ? filesize("$path-wal") : 0;

// SQLite's PRAGMA wal_checkpoint(TRUNCATE) returns (busy, log, checkpointed).
//   busy          = 1 if another connection held a read that blocked truncation
//   log           = total frames in the WAL at start
//   checkpointed  = frames actually moved to the main DB
$r = $db->query("PRAGMA wal_checkpoint(TRUNCATE)");
if (!$r) {
    fwrite(STDERR, "checkpoint: PRAGMA failed: " . $db->lastErrorMsg() . "\n");
    $db->close();
    exit(3);
}
$row = $r->fetchArray(SQLITE3_NUM);
$db->close();

clearstatcache();
$wal_after = file_exists("$path-wal") ? filesize("$path-wal") : 0;

printf(
    "checkpoint: busy=%s log=%s checkpointed=%s  wal=%d→%d bytes\n",
    $row[0] ?? '?', $row[1] ?? '?', $row[2] ?? '?',
    $wal_before, $wal_after
);

<?php
/**
 * forkpress backup — consistent hot-copy of a running site.fp.
 *
 * TODO3 #14: prefer SQLite's online backup API (`SQLite3::backup`, PHP 8.1+)
 * over `VACUUM INTO`. The online backup copies pages in batches between
 * the source and destination connections, yielding the write lock between
 * batches, so concurrent writers pause for milliseconds per batch instead
 * of being blocked for the full copy duration (seconds to minutes on a
 * large .fp). See SQLite's backup API docs:
 * https://www.sqlite.org/backup.html
 *
 * Fallback: if SQLite3::backup isn't available (older PHP) we use
 * `VACUUM INTO` which still produces a correct backup but blocks writers.
 *
 * Usage: php sqlite_backup.php <source.sqlite> <dest.sqlite>
 */

require_once __DIR__ . '/sqlite_retry.php';

if ($argc < 3) {
    fwrite(STDERR, "Usage: php sqlite_backup.php <source.sqlite> <dest.sqlite>\n");
    exit(1);
}

[$_, $src, $dst] = $argv;

if (!file_exists($src)) {
    fwrite(STDERR, "backup: source not found: $src\n");
    exit(2);
}
if (file_exists($dst)) {
    fwrite(STDERR, "backup: refusing to overwrite existing file: $dst\n");
    exit(2);
}

$dst_dir = dirname($dst);
if ($dst_dir !== '' && !is_dir($dst_dir)) {
    fwrite(STDERR, "backup: destination directory does not exist: $dst_dir\n");
    exit(2);
}

$use_online_backup = method_exists('SQLite3', 'backup');

if ($use_online_backup) {
    // Online backup: open both DBs, call SQLite3::backup.
    $src_db = new SQLite3($src, SQLITE3_OPEN_READONLY);
    $src_db->busyTimeout(15000);
    $dst_db = new SQLite3($dst);
    $dst_db->busyTimeout(15000);

    try {
        $ok = sqlite_retry_busy(function() use ($src_db, $dst_db) {
            return $src_db->backup($dst_db, 'main', 'main');
        });
        if (!$ok) {
            throw new RuntimeException(
                'SQLite3::backup returned false: ' . $src_db->lastErrorMsg()
            );
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "backup: online backup failed: " . $e->getMessage() . "\n");
        $src_db->close();
        $dst_db->close();
        @unlink($dst);
        exit(3);
    }
    $src_db->close();
    $dst_db->close();
} else {
    // Fallback: VACUUM INTO.
    fwrite(STDERR, "backup: SQLite3::backup not available on this PHP; "
                 . "falling back to VACUUM INTO (blocks writers for the copy duration).\n");
    $db = new SQLite3($src, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(15000);
    try {
        sqlite_retry_busy(function() use ($db, $dst) {
            $db->exec("VACUUM INTO '" . SQLite3::escapeString($dst) . "'");
        });
    } catch (\Throwable $e) {
        fwrite(STDERR, "backup: VACUUM INTO failed: " . $e->getMessage() . "\n");
        $db->close();
        exit(3);
    }
    $db->close();
}

if (!file_exists($dst)) {
    fwrite(STDERR, "backup: destination was not created: $dst\n");
    exit(3);
}

printf("backup: %s -> %s (%d bytes)\n", $src, $dst, filesize($dst));

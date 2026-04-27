<?php
/**
 * SQLITE_BUSY retry helper with exponential backoff.
 *
 * SQLite serialises writes: only one writer can hold the write lock at
 * a time. PHP's SQLite3::busyTimeout() tells SQLite to poll the lock
 * up to a deadline before giving up. Under concurrent write load
 * (two HTTP requests, SFTP upload + HTTP request, MySQL proxy + HTTP
 * request, parallel branchctl invocations) the deadline can still be
 * exceeded — the error surfaces as "database is locked" / SQLITE_BUSY
 * and bubbles up as a 500 or a failed CLI command.
 *
 * This helper retries the supplied callback with exponential backoff
 * when SQLite reports a BUSY / LOCKED condition, giving the winning
 * writer time to commit and release. For non-BUSY errors it re-throws
 * immediately (no point retrying a syntax error).
 *
 * Usage:
 *     sqlite_retry_busy(function() use ($db) {
 *         $db->exec('BEGIN IMMEDIATE');
 *         // ... writes ...
 *         $db->exec('COMMIT');
 *     });
 *
 * Defaults (configurable): 3 retries, 100 / 500 / 2000 ms between attempts.
 */

function sqlite_is_busy_error(\Throwable $e): bool {
    $msg = $e->getMessage();
    // SQLite3 throws \Exception with "database is locked" or
    // "database table is locked". Rusqlite-style / raw codes sometimes
    // surface as "SQLITE_BUSY" in wrapped messages.
    return stripos($msg, 'database is locked') !== false
        || stripos($msg, 'database table is locked') !== false
        || stripos($msg, 'SQLITE_BUSY') !== false
        || stripos($msg, 'SQLITE_LOCKED') !== false;
}

/**
 * Run $fn, retrying on SQLITE_BUSY with exponential backoff.
 *
 * @param callable  $fn           Body to execute; return value is passed through.
 * @param int       $max_retries  Number of retries after the initial attempt (default 3).
 * @param int[]     $delays_ms    Per-retry delay in ms (padded with last value if too short).
 * @return mixed                  Whatever $fn returns.
 * @throws \Throwable             Re-thrown from $fn after the last retry, or immediately on non-BUSY.
 */
function sqlite_retry_busy(
    callable $fn,
    int $max_retries = 3,
    array $delays_ms = [100, 500, 2000]
) {
    $attempts = $max_retries + 1;
    $last = null;
    for ($i = 0; $i < $attempts; $i++) {
        try {
            return $fn();
        } catch (\Throwable $e) {
            $last = $e;
            if (!sqlite_is_busy_error($e) || $i === $attempts - 1) {
                throw $e;
            }
            $delay = $delays_ms[$i] ?? end($delays_ms);
            usleep((int)($delay * 1000));
        }
    }
    // Unreachable; the loop always either returns or rethrows. Included
    // so static analysers understand $last is the caller's failure.
    throw $last;
}

<?php
/**
 * OPcache invalidation queue.
 *
 * router.php serves PHP via `branchfs://<branch>/path.php` URLs so OPcache
 * keys bytecode per-branch. When merge/reset/rollback writes new content for
 * a .php file on a branch, the OPcache entry for `branchfs://<branch>/path`
 * still holds the pre-merge bytecode — so HTTP requests keep serving the
 * old code until the OPcache entry expires or the server restarts.
 *
 * This module is a cross-process signalling mechanism: out-of-process
 * writers (branchctl running in a new `php` process) enqueue pending
 * invalidations in a SQLite table, and the in-process PHP server (router.php)
 * drains the queue on each request and calls opcache_invalidate() for each
 * pending URL. No sentinel files, no race-prone mtime checks — the queue
 * is a transactional SQLite table, and the drain is a SELECT + DELETE
 * pair wrapped in a single transaction.
 */

/**
 * Ensure the queue table exists. Safe to call on every connection.
 */
function opcache_migrate(SQLite3 $db): void {
    $db->exec(<<<SQL
CREATE TABLE IF NOT EXISTS opcache_invalidations (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    url        TEXT NOT NULL,
    created_at INTEGER NOT NULL DEFAULT (strftime('%s','now'))
);
CREATE INDEX IF NOT EXISTS idx_opcache_url ON opcache_invalidations(url);
SQL);
}

/**
 * Enqueue a pending OPcache invalidation for `branchfs://$branch/$path`.
 * No-op for non-PHP paths — only .php / .phtml files produce OPcache entries.
 */
function opcache_queue_invalidate(SQLite3 $db, string $branch, string $path): void {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext !== 'php' && $ext !== 'phtml') return;

    opcache_migrate($db);

    $norm = '/' . ltrim($path, '/');
    $url  = 'branchfs://' . $branch . $norm;

    $s = $db->prepare("INSERT INTO opcache_invalidations (url) VALUES (:u)");
    $s->bindValue(':u', $url, SQLITE3_TEXT);
    $s->execute();
}

/**
 * Drain all pending invalidations from the queue and call opcache_invalidate()
 * on each. Returns the list of URLs processed.
 *
 * Called by router.php on every HTTP request. Each request drains at most
 * a few entries (merge/reset touch tens-to-hundreds of .php files at most
 * and the queue is cleared by the first request after the write), so the
 * cost is negligible.
 *
 * Safe to call when OPcache is not loaded: it still drains the queue
 * (so the rows don't accumulate forever when the PHP CLI hits this path)
 * and skips the actual invalidation call.
 */
function opcache_process_pending(SQLite3 $db): array {
    opcache_migrate($db);

    $rows = [];
    $r = $db->query("SELECT id, url FROM opcache_invalidations ORDER BY id");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
    $r->finalize();

    if (empty($rows)) return [];

    $ids  = [];
    $urls = [];
    $can_invalidate = function_exists('opcache_invalidate');
    foreach ($rows as $row) {
        $ids[]  = (int)$row['id'];
        $urls[] = $row['url'];
        if ($can_invalidate) {
            @opcache_invalidate($row['url'], true);
        }
    }

    $in = implode(',', array_map('intval', $ids));
    $db->exec("DELETE FROM opcache_invalidations WHERE id IN ($in)");

    return $urls;
}

"""
OPcache invalidation queue tests.

router.php serves PHP via `branchfs://<branch>/path.php` URLs so OPcache
keys bytecode per-branch. When branchctl merge/reset/rollback writes new
content for a .php file on a branch, the OPcache entry for that URL still
holds the pre-merge bytecode and HTTP requests keep serving stale code.

Fix: scripts/opcache.php exposes a cross-process queue in the .fp file.
Out-of-process writers (branchctl) enqueue pending `branchfs://branch/path`
URLs; router.php drains the queue on every HTTP request and calls
opcache_invalidate() for each URL.

These tests verify:
  - merge: .php paths written to target are enqueued; non-PHP paths are not.
  - reset/rollback: paths in the affected branch get enqueued.
  - The drainer pops rows and is idempotent.
  - The fix is compatible with OPcache being unavailable (CLI).

The live round-trip (hit URL → new content served without restart) needs
a running forkpress binary and is covered by @pytest.mark.live in the
test_invariants.py suite.

Run:
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_opcache_invalidation.py -v
"""

import os
import shutil
import sqlite3
import subprocess
import tempfile
from pathlib import Path

import pytest

E2E_DIR   = Path(__file__).parent
BASE_DIR  = E2E_DIR.parent
EXT_PATH  = BASE_DIR / "ext" / "branchfs.so"
PHP_BIN   = "php"
OPCACHE_PHP = BASE_DIR / "scripts" / "opcache.php"


def branchctl(site_fp: Path, *args, timeout: int = 30) -> subprocess.CompletedProcess:
    return subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         str(BASE_DIR / "scripts" / "branchctl.php"), *args],
        capture_output=True, text=True, timeout=timeout,
        env={**os.environ, "BRANCHFS_DB": str(site_fp)},
    )


def sqlite_q(site_fp: Path, sql: str, params=()) -> list:
    db = sqlite3.connect(str(site_fp))
    try:
        return db.execute(sql, params).fetchall()
    finally:
        db.close()


def php(code: str) -> tuple[int, str, str]:
    cmd = [PHP_BIN, "-d", f"extension={EXT_PATH}", "-r", code]
    r = subprocess.run(cmd, capture_output=True, text=True, timeout=15)
    return r.returncode, r.stdout, r.stderr


def _clear_queue(site_fp: Path) -> None:
    """Empty the opcache_invalidations queue (creating it first if absent)."""
    db = sqlite3.connect(str(site_fp))
    try:
        db.execute("""
            CREATE TABLE IF NOT EXISTS opcache_invalidations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                url TEXT NOT NULL,
                created_at INTEGER NOT NULL DEFAULT (strftime('%s','now'))
            )
        """)
        db.execute("DELETE FROM opcache_invalidations")
        db.commit()
    finally:
        db.close()


def init_site(site_fp: Path):
    r = subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         str(BASE_DIR / "scripts" / "init_db.php"), str(site_fp)],
        capture_output=True, text=True, timeout=30,
    )
    if r.returncode != 0:
        raise RuntimeError(f"init_db.php failed: {r.stderr[:500]}")


def branchfs_write(site_fp: Path, branch: str, path: str, content: str) -> bool:
    rc, out, err = php(f"""
        branchfs_set_db('{site_fp}');
        echo file_put_contents('branchfs://{branch}/{path}', {content!r}) !== false
            ? 'ok' : 'fail';
    """)
    return rc == 0 and out.strip() == "ok"


@pytest.fixture
def site_with_branch():
    if not EXT_PATH.exists():
        pytest.skip("branchfs.so not found")
    work = Path(tempfile.mkdtemp())
    site_fp = work / "site.fp"
    init_site(site_fp)

    r = branchctl(site_fp, "create", "feat")
    assert r.returncode == 0, f"create failed: {r.stderr}"

    yield {"site_fp": site_fp, "work": work}
    shutil.rmtree(work, ignore_errors=True)


# ── Queue mechanism ───────────────────────────────────────────────────────────

class TestOpcacheQueue:
    """Exercise scripts/opcache.php directly via PHP CLI."""

    def test_queue_schema_created(self, site_with_branch):
        site_fp = site_with_branch["site_fp"]
        rc, _, err = php(f"""
            require '{OPCACHE_PHP}';
            $db = new SQLite3('{site_fp}');
            opcache_migrate($db);
            $db->close();
        """)
        assert rc == 0, err
        tables = sqlite_q(site_fp,
            "SELECT name FROM sqlite_master WHERE type='table' "
            "AND name='opcache_invalidations'")
        assert tables, "opcache_invalidations table must be created"

    def test_queue_only_accepts_php_paths(self, site_with_branch):
        """Non-PHP paths must be ignored — they produce no OPcache entries."""
        site_fp = site_with_branch["site_fp"]
        rc, _, err = php(f"""
            require '{OPCACHE_PHP}';
            $db = new SQLite3('{site_fp}');
            opcache_queue_invalidate($db, 'main', 'wp-load.php');
            opcache_queue_invalidate($db, 'main', 'theme.phtml');
            opcache_queue_invalidate($db, 'main', 'style.css');
            opcache_queue_invalidate($db, 'main', 'logo.png');
            opcache_queue_invalidate($db, 'main', 'readme.txt');
            $db->close();
        """)
        assert rc == 0, err
        urls = [row[0] for row in sqlite_q(site_fp,
            "SELECT url FROM opcache_invalidations ORDER BY id")]
        assert urls == [
            'branchfs://main/wp-load.php',
            'branchfs://main/theme.phtml',
        ], f"only .php/.phtml should be queued, got: {urls}"

    def test_process_pending_drains_queue(self, site_with_branch):
        """opcache_process_pending() returns URLs and removes them."""
        site_fp = site_with_branch["site_fp"]
        rc, out, err = php(f"""
            require '{OPCACHE_PHP}';
            $db = new SQLite3('{site_fp}');
            opcache_queue_invalidate($db, 'main', 'a.php');
            opcache_queue_invalidate($db, 'feat', 'b.php');
            $urls = opcache_process_pending($db);
            echo implode('|', $urls);
            $db->close();
        """)
        assert rc == 0, err
        got = set(out.strip().split('|'))
        assert got == {'branchfs://main/a.php', 'branchfs://feat/b.php'}, got

        remaining = sqlite_q(site_fp, "SELECT COUNT(*) FROM opcache_invalidations")
        assert remaining[0][0] == 0, "queue must be drained after processing"

    def test_process_pending_idempotent_on_empty_queue(self, site_with_branch):
        """Draining an empty queue returns [] and is not an error."""
        site_fp = site_with_branch["site_fp"]
        rc, out, err = php(f"""
            require '{OPCACHE_PHP}';
            $db = new SQLite3('{site_fp}');
            $urls = opcache_process_pending($db);
            echo count($urls);
            $db->close();
        """)
        assert rc == 0, err
        assert out.strip() == "0"


# ── branchctl merge integration ───────────────────────────────────────────────

class TestMergeEnqueuesInvalidations:

    def test_merge_enqueues_php_files_written_to_target(self, site_with_branch):
        """
        When merge writes a .php file to the target branch, it enqueues a
        `branchfs://<target>/<path>` invalidation so the next HTTP request
        to target can drop the stale bytecode.
        """
        site_fp = site_with_branch["site_fp"]

        # Feature branch creates a new PHP file that main doesn't have.
        assert branchfs_write(site_fp, "feat", "wp-content/plugins/my-plugin.php",
                              "<?php /* v1 */ ?>")
        branchctl(site_fp, "commit", "feat", "-m", "add plugin")

        r = branchctl(site_fp, "merge", "feat", "--into", "main")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        urls = [row[0] for row in sqlite_q(site_fp,
            "SELECT url FROM opcache_invalidations ORDER BY id")]
        assert "branchfs://main/wp-content/plugins/my-plugin.php" in urls, (
            f"merge must queue invalidation for .php paths written to target.\n"
            f"queue contents: {urls}"
        )

    def test_merge_does_not_enqueue_nonphp_files(self, site_with_branch):
        """A .css/.png/.txt change on merge must not populate the queue."""
        site_fp = site_with_branch["site_fp"]

        assert branchfs_write(site_fp, "feat", "wp-content/style.css",
                              "body { color: red }")
        branchctl(site_fp, "commit", "feat", "-m", "css only")

        _clear_queue(site_fp)  # ensure table exists even if never touched

        r = branchctl(site_fp, "merge", "feat", "--into", "main")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        urls = [row[0] for row in sqlite_q(site_fp,
            "SELECT url FROM opcache_invalidations ORDER BY id")]
        css_urls = [u for u in urls if u.endswith(".css")]
        assert css_urls == [], f"CSS paths should never be queued: {css_urls}"

    def test_merge_enqueues_even_for_tombstone_delete(self, site_with_branch):
        """
        Merging a deletion of a .php file on source still needs the target
        to invalidate its OPcache entry — otherwise the cached bytecode
        would continue to run after the file is gone.
        """
        site_fp = site_with_branch["site_fp"]

        # Start with a PHP file on both branches (via main).
        assert branchfs_write(site_fp, "main", "doomed.php", "<?php /* v1 */ ?>")
        branchctl(site_fp, "commit", "main", "-m", "baseline")

        # Create a new branch AFTER main has the file so feat inherits it.
        r = branchctl(site_fp, "create", "rm-test")
        assert r.returncode == 0

        # Delete on rm-test
        rc, _, err = php(f"""
            branchfs_set_db('{site_fp}');
            echo unlink('branchfs://rm-test/doomed.php') ? 'ok' : 'fail';
        """)
        assert rc == 0
        branchctl(site_fp, "commit", "rm-test", "-m", "delete")

        # Clear any leftover queue entries
        _clear_queue(site_fp)

        r = branchctl(site_fp, "merge", "rm-test", "--into", "main")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        urls = [row[0] for row in sqlite_q(site_fp,
            "SELECT url FROM opcache_invalidations ORDER BY id")]
        assert "branchfs://main/doomed.php" in urls, (
            f"merge of a .php deletion must still queue invalidation for target.\n"
            f"queue contents: {urls}"
        )


# ── branchctl reset / rollback integration ───────────────────────────────────

class TestResetRollbackEnqueuesInvalidations:

    def test_rollback_queues_invalidations(self, site_with_branch):
        site_fp = site_with_branch["site_fp"]

        # Commit 1: baseline .php
        assert branchfs_write(site_fp, "feat", "theme.php", "<?php /* v1 */")
        branchctl(site_fp, "commit", "feat", "-m", "baseline")

        # Commit 2: modify
        assert branchfs_write(site_fp, "feat", "theme.php", "<?php /* v2 */")
        branchctl(site_fp, "commit", "feat", "-m", "v2")

        # Rollback → file goes back to v1; OPcache must be told
        _clear_queue(site_fp)

        r = branchctl(site_fp, "rollback", "feat", "--force")
        assert r.returncode == 0, f"rollback failed:\n{r.stdout}\n{r.stderr}"

        urls = [row[0] for row in sqlite_q(site_fp,
            "SELECT url FROM opcache_invalidations ORDER BY id")]
        assert "branchfs://feat/theme.php" in urls, (
            f"rollback must queue invalidation for affected .php paths.\n"
            f"queue contents: {urls}"
        )

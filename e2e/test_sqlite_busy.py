"""
SQLITE_BUSY retry tests.

Under concurrent writer load, SQLite serializes writes: only one can hold
the write lock at a time. Without retry support, a losing writer sees
SQLITE_BUSY which PHP raises as an Exception and the router turns into a
500. TODO #6 covers:
  - Bumping busyTimeout from 5s to 15s in PHP writers.
  - Wrapping the critical DB transactions (merge.php) with exponential-
    backoff retries.
  - Providing a shared helper in scripts/sqlite_retry.php.
  - Matching behaviour on the Rust side (fileserver/src/store.rs:
    with_busy_retry, busy_timeout(15s)).

Tests in this file exercise the retry helper and verify concurrent
branchctl invocations all succeed without SQLITE_BUSY leaks.

Run:
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_sqlite_busy.py -v
"""

import concurrent.futures
import os
import shutil
import sqlite3
import subprocess
import tempfile
from pathlib import Path

import pytest

E2E_DIR  = Path(__file__).parent
BASE_DIR = E2E_DIR.parent
EXT_PATH = BASE_DIR / "ext" / "branchfs.so"
PHP_BIN  = "php"
SQLITE_RETRY_PHP = BASE_DIR / "scripts" / "sqlite_retry.php"


def branchctl(site_fp: Path, *args, timeout: int = 30) -> subprocess.CompletedProcess:
    return subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         str(BASE_DIR / "scripts" / "branchctl.php"), *args],
        capture_output=True, text=True, timeout=timeout,
        env={**os.environ, "BRANCHFS_DB": str(site_fp)},
    )


def init_site(site_fp: Path):
    r = subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         str(BASE_DIR / "scripts" / "init_db.php"), str(site_fp)],
        capture_output=True, text=True, timeout=30,
    )
    if r.returncode != 0:
        raise RuntimeError(f"init_db.php failed: {r.stderr[:500]}")


@pytest.fixture
def fresh_site():
    if not EXT_PATH.exists():
        pytest.skip("branchfs.so not found")
    work = Path(tempfile.mkdtemp())
    site_fp = work / "site.fp"
    init_site(site_fp)
    yield site_fp
    shutil.rmtree(work, ignore_errors=True)


# ── sqlite_retry.php helper unit tests ────────────────────────────────────────

class TestSqliteRetryHelper:

    def test_helper_exists(self):
        assert SQLITE_RETRY_PHP.exists()

    def test_retry_returns_immediately_on_success(self):
        """No busy → no retry → callback runs once."""
        code = f"""
            require '{SQLITE_RETRY_PHP}';
            $n = 0;
            $r = sqlite_retry_busy(function() use (&$n) {{ $n++; return 42; }});
            echo $r . '|' . $n;
        """
        r = subprocess.run([PHP_BIN, "-r", code],
                           capture_output=True, text=True, timeout=10)
        assert r.returncode == 0, r.stderr
        assert r.stdout.strip() == "42|1"

    def test_retry_raises_non_busy_immediately(self):
        """Non-BUSY errors must propagate without retry."""
        code = f"""
            require '{SQLITE_RETRY_PHP}';
            $n = 0;
            try {{
                sqlite_retry_busy(function() use (&$n) {{
                    $n++;
                    throw new \\Exception('syntax error at line 1');
                }});
            }} catch (\\Exception $e) {{
                echo $e->getMessage() . '|' . $n;
            }}
        """
        r = subprocess.run([PHP_BIN, "-r", code],
                           capture_output=True, text=True, timeout=10)
        assert r.returncode == 0, r.stderr
        assert r.stdout.strip() == "syntax error at line 1|1"

    def test_retry_retries_busy_then_succeeds(self):
        """A transient BUSY followed by success must return cleanly."""
        code = f"""
            require '{SQLITE_RETRY_PHP}';
            $n = 0;
            $r = sqlite_retry_busy(function() use (&$n) {{
                $n++;
                if ($n < 3) {{
                    throw new \\Exception('database is locked');
                }}
                return 'ok';
            }}, 3, [1, 1, 1]);
            echo $r . '|' . $n;
        """
        r = subprocess.run([PHP_BIN, "-r", code],
                           capture_output=True, text=True, timeout=10)
        assert r.returncode == 0, r.stderr
        assert r.stdout.strip() == "ok|3"

    def test_retry_exhausts_retries(self):
        """Persistent BUSY must eventually give up and re-throw."""
        code = f"""
            require '{SQLITE_RETRY_PHP}';
            $n = 0;
            try {{
                sqlite_retry_busy(function() use (&$n) {{
                    $n++;
                    throw new \\Exception('database is locked');
                }}, 2, [1, 1]);
                echo 'unreachable';
            }} catch (\\Exception $e) {{
                echo 'caught|' . $n;
            }}
        """
        r = subprocess.run([PHP_BIN, "-r", code],
                           capture_output=True, text=True, timeout=10)
        assert r.returncode == 0, r.stderr
        # 2 retries + initial = 3 attempts
        assert r.stdout.strip() == "caught|3"


# ── Writer config ─────────────────────────────────────────────────────────────

class TestWriterConfig:

    def test_branchctl_busy_timeout_raised(self):
        """branchctl.php must set a 15s busy timeout, not the old 5s."""
        src = (BASE_DIR / "scripts" / "branchctl.php").read_text()
        assert "busyTimeout(15000)" in src, (
            "branchctl.php's sqlite_open() must set busyTimeout(15000). "
            "A shorter window under contention makes spurious SQLITE_BUSY "
            "leak into CLI output as failed commands."
        )

    def test_merge_busy_timeout_raised(self):
        src = (BASE_DIR / "scripts" / "merge.php").read_text()
        assert "busyTimeout(15000)" in src, (
            "scripts/merge.php must open SQLite with busyTimeout(15000); "
            "merge transactions are long and deserve the extra window."
        )

    def test_merge_wraps_transactions_in_retry(self):
        src = (BASE_DIR / "scripts" / "merge.php").read_text()
        assert "sqlite_retry_busy" in src, (
            "merge.php must use sqlite_retry_busy() around its write "
            "transactions so a transient BUSY doesn't fail the merge."
        )

    def test_rust_store_has_busy_retry(self):
        src = (BASE_DIR / "fileserver" / "src" / "store.rs").read_text()
        assert "with_busy_retry" in src, (
            "Rust Store must expose with_busy_retry so the MySQL proxy "
            "and SFTP write paths get the same retry behaviour as PHP."
        )
        assert "busy_timeout" in src, (
            "Rust Store::open must call busy_timeout; rusqlite's default "
            "returns SQLITE_BUSY immediately on lock contention."
        )


# ── Concurrent branchctl invocations ──────────────────────────────────────────

class TestConcurrentWriters:

    def test_parallel_branchctl_creates_all_succeed(self, fresh_site):
        """
        Fire K parallel `branchctl create` invocations. Each spawns a
        new php process that opens the same site.fp and performs many
        writes (CREATE TABLE ... AS SELECT, INSERT into db_snapshots,
        fs_commits insertion). They all contend for the write lock.
        Before the fix, 3+ parallel writers reliably produced at least
        one SQLITE_BUSY. With the 15s busy timeout and retry helper,
        all succeed.
        """
        K = 6

        def one(i: int) -> subprocess.CompletedProcess:
            return branchctl(fresh_site, "create", f"par-{i}", timeout=60)

        with concurrent.futures.ThreadPoolExecutor(max_workers=K) as pool:
            results = list(pool.map(one, range(K)))

        failures = [
            (i, r) for i, r in enumerate(results)
            if r.returncode != 0
        ]
        # Attach last 300 chars of stderr so failures are debuggable
        detail = "\n".join(
            f"par-{i}: rc={r.returncode} stderr={r.stderr[-300:]}"
            for i, r in failures
        )
        assert not failures, (
            f"{len(failures)}/{K} parallel creates failed:\n{detail}"
        )

        # Double-check every branch actually landed
        db = sqlite3.connect(str(fresh_site))
        try:
            names = {row[0] for row in db.execute(
                "SELECT name FROM branches WHERE name LIKE 'par-%'"
            )}
        finally:
            db.close()
        assert names == {f"par-{i}" for i in range(K)}, (
            f"expected {K} branches, got: {sorted(names)}"
        )

    def test_parallel_external_writer_does_not_block_branchctl(self, fresh_site):
        """
        A parallel writer (simulating an HTTP or SFTP write) should not
        cause branchctl create to fail — the busyTimeout + retry combo
        must cover contention long enough for both to win.
        """
        # Use a thread-pool with two roles:
        # 1. a burst of sqlite3 writes that holds the write lock briefly.
        # 2. a branchctl create that needs to get through.
        import threading
        import time as _time

        stop_flag = threading.Event()

        def hammer():
            db = sqlite3.connect(str(fresh_site))
            db.execute("CREATE TABLE IF NOT EXISTS hammer (id INTEGER, v TEXT)")
            db.commit()
            while not stop_flag.is_set():
                db.execute("INSERT INTO hammer (v) VALUES (?)", ("x" * 100,))
                db.commit()
                _time.sleep(0.005)
            db.close()

        t = threading.Thread(target=hammer)
        t.start()
        try:
            r = branchctl(fresh_site, "create", "under-load", timeout=60)
        finally:
            stop_flag.set()
            t.join(timeout=10)

        assert r.returncode == 0, (
            f"branchctl create failed under concurrent writer load.\n"
            f"stdout:\n{r.stdout}\nstderr:\n{r.stderr}"
        )

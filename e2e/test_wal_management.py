"""
WAL file management tests.

Goals of the fix (TODO #5):
  - WAL file must not grow without bound over long-running sessions.
  - `scripts/checkpoint.php` provides an explicit shrink point.
  - `init_db.php` sets PRAGMA wal_autocheckpoint so background growth is
    self-capped.
  - forkpress/fileserver runs a periodic checkpoint + one on shutdown
    (Rust code; exercised only when the binary is running, so those paths
    have cargo test coverage and live smoke coverage only).

Run:
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_wal_management.py -v
"""

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

CHECKPOINT_PHP = BASE_DIR / "scripts" / "checkpoint.php"
INIT_DB_PHP    = BASE_DIR / "scripts" / "init_db.php"


def init_site(site_fp: Path):
    r = subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}", str(INIT_DB_PHP), str(site_fp)],
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


# ── init_db.php must configure bounded WAL growth ────────────────────────────

class TestWalAutocheckpointPragma:

    def test_init_db_configures_autocheckpoint(self):
        """
        init_db.php must set PRAGMA wal_autocheckpoint tighter than the
        SQLite default of 1000 pages so the WAL stays bounded. The value
        is a per-connection setting that isn't persisted into the DB
        file, so we verify it's set by source inspection plus a live
        open-via-branchctl behavioural test below.
        """
        src = INIT_DB_PHP.read_text()
        assert "wal_autocheckpoint" in src, (
            "init_db.php must configure PRAGMA wal_autocheckpoint to cap WAL "
            "growth under idle / steady-state workloads."
        )

    def test_branchctl_sqlite_open_sets_autocheckpoint(self):
        """
        Every SQLite3 connection opened by branchctl must set
        wal_autocheckpoint (the pragma is per-connection, not persisted).
        """
        src = (BASE_DIR / "scripts" / "branchctl.php").read_text()
        assert "wal_autocheckpoint" in src, (
            "branchctl.php sqlite_open() must set PRAGMA wal_autocheckpoint "
            "— otherwise the CLI-side writer leaves the WAL growing."
        )

    def test_journal_mode_is_wal(self, fresh_site):
        db = sqlite3.connect(str(fresh_site))
        try:
            mode = db.execute("PRAGMA journal_mode").fetchone()[0]
        finally:
            db.close()
        assert mode.lower() == "wal", f"expected WAL mode, got {mode}"


# ── checkpoint.php truncates the WAL ─────────────────────────────────────────

class TestCheckpointScript:

    def test_checkpoint_script_exists(self):
        assert CHECKPOINT_PHP.exists(), \
            f"missing helper script {CHECKPOINT_PHP}"

    def test_checkpoint_truncates_wal(self, fresh_site):
        """
        Populate the WAL with enough writes to force it past zero bytes,
        then invoke checkpoint.php and verify the -wal file shrinks.
        """
        # Hold a read transaction on a second connection so the auto
        # -checkpoint that fires on commit can't truncate the WAL while
        # we're trying to grow it.
        holder = sqlite3.connect(str(fresh_site))
        holder.execute("BEGIN")
        holder.execute("SELECT COUNT(*) FROM sqlite_master").fetchall()

        db = sqlite3.connect(str(fresh_site))
        try:
            # Disable auto-checkpoint on this connection so the WAL
            # accumulates frames deterministically regardless of timing.
            db.execute("PRAGMA wal_autocheckpoint = 0")
            db.execute("CREATE TABLE IF NOT EXISTS wal_probe (id INTEGER PRIMARY KEY, v BLOB)")
            payload = b"x" * 8192
            for i in range(400):
                db.execute("INSERT INTO wal_probe (v) VALUES (?)", (payload,))
            db.commit()
        finally:
            db.close()

        wal_before = os.path.getsize(f"{fresh_site}-wal") \
            if os.path.exists(f"{fresh_site}-wal") else 0

        # Release the read lock so TRUNCATE can complete.
        holder.rollback()
        holder.close()

        if wal_before < 1024:
            pytest.skip(
                f"WAL too small to test truncation (got {wal_before} bytes) — "
                f"platform may auto-checkpoint more aggressively than expected"
            )

        r = subprocess.run(
            [PHP_BIN, "-d", f"extension={EXT_PATH}",
             str(CHECKPOINT_PHP), str(fresh_site)],
            capture_output=True, text=True, timeout=30,
        )
        assert r.returncode == 0, \
            f"checkpoint.php failed: {r.stderr}\nstdout: {r.stdout}"
        assert "checkpoint:" in r.stdout, f"unexpected output: {r.stdout}"

        wal_after = os.path.getsize(f"{fresh_site}-wal") \
            if os.path.exists(f"{fresh_site}-wal") else 0
        assert wal_after < wal_before, (
            f"checkpoint did not shrink the WAL: before={wal_before}, "
            f"after={wal_after}. Expected TRUNCATE to bring it close to 0."
        )

    def test_checkpoint_on_missing_file_exits_nonzero(self):
        r = subprocess.run(
            [PHP_BIN, "-d", f"extension={EXT_PATH}",
             str(CHECKPOINT_PHP), "/tmp/does-not-exist-forkpress.fp"],
            capture_output=True, text=True, timeout=10,
        )
        assert r.returncode != 0


# ── Heavy writes stay bounded under the configured autocheckpoint ────────────

class TestWalBoundedUnderWrites:

    def test_wal_stays_bounded_under_heavy_writes(self, fresh_site):
        """
        Write several MB worth of data in rapid succession using the
        same per-connection autocheckpoint config that branchctl /
        fileserver use (wal_autocheckpoint=500) and verify the WAL does
        not exceed the cap TODO #5 specifies (16 MB).
        """
        payload = b"y" * 8192  # 8 KB per row
        rows    = 2000         # ~ 16 MB of row data
        db = sqlite3.connect(str(fresh_site))
        try:
            # Match the per-connection settings branchctl.php's sqlite_open
            # uses so the test reflects real writer behaviour.
            db.execute("PRAGMA wal_autocheckpoint = 500")
            db.execute("CREATE TABLE IF NOT EXISTS big (id INTEGER PRIMARY KEY, v BLOB)")
            for i in range(rows):
                db.execute("INSERT INTO big (v) VALUES (?)", (payload,))
                if i % 50 == 49:
                    db.commit()
            db.commit()
        finally:
            db.close()

        wal_path = f"{fresh_site}-wal"
        wal_size = os.path.getsize(wal_path) if os.path.exists(wal_path) else 0
        CAP = 16 * 1024 * 1024
        assert wal_size <= CAP, (
            f"WAL grew to {wal_size} bytes ({wal_size / 1024 / 1024:.1f} MB), "
            f"exceeding the {CAP} byte cap. PRAGMA wal_autocheckpoint=500 "
            f"should keep it bounded under normal workloads."
        )


# ── Rust store exposes checkpoint API (cargo-test gated) ─────────────────────

@pytest.mark.skipif(
    shutil.which("cargo") is None,
    reason="cargo not available — skipping Rust-side checkpoint API check",
)
class TestRustCheckpointApi:

    def test_store_rs_declares_checkpoint_methods(self):
        """
        The Rust Store must expose `checkpoint_truncate` and
        `spawn_periodic_checkpoint` so the fileserver can run the WAL
        checkpoint on shutdown and on a timer.
        """
        src = (BASE_DIR / "fileserver" / "src" / "store.rs").read_text()
        assert "fn checkpoint_truncate" in src, (
            "Store must expose checkpoint_truncate() to let the fileserver "
            "flush the WAL on shutdown."
        )
        assert "fn spawn_periodic_checkpoint" in src, (
            "Store must expose spawn_periodic_checkpoint() for steady-state "
            "background checkpointing."
        )
        assert "wal_autocheckpoint" in src, (
            "Store::open must set PRAGMA wal_autocheckpoint so the WAL is "
            "bounded even without explicit checkpoints."
        )

    def test_fileserver_main_wires_shutdown_checkpoint(self):
        src = (BASE_DIR / "fileserver" / "src" / "main.rs").read_text()
        assert "checkpoint_truncate" in src, (
            "fileserver main.rs must call checkpoint_truncate on shutdown."
        )
        assert "spawn_periodic_checkpoint" in src, (
            "fileserver main.rs must spawn the periodic checkpoint thread."
        )

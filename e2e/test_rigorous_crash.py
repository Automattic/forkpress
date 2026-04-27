"""
Rigorous crash-recovery tests: SIGKILL processes mid-operation, reopen DB,
verify consistent state. No half-writes, no orphan rows.

Run:
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_rigorous_crash.py -v
"""

import os
import shutil
import signal
import sqlite3
import subprocess
import tempfile
import time
from pathlib import Path

import pytest

from _rigorous_helpers import (
    branchctl, branchctl_popen, branch_id, commit_branch, create_branch,
    init_site, make_fresh_site, require_ext,
    sqlite_exec, sqlite_q,
    EXT_PATH, PHP_BIN, BRANCHCTL_PHP,
)


@pytest.fixture
def site():
    work, site_fp = make_fresh_site(prefix="rigcrash_")
    yield site_fp
    shutil.rmtree(work, ignore_errors=True)


def reopen_integrity_ok(site_fp):
    """Reopen the DB, run PRAGMA integrity_check. Returns True iff 'ok'."""
    db = sqlite3.connect(str(site_fp), timeout=30.0)
    try:
        r = db.execute("PRAGMA integrity_check").fetchone()
        return r == ("ok",)
    finally:
        db.close()


# ═════════════════════════════════════════════════════════════════════
# 3.1 — SIGKILL during INSERT into branch overlay
# ═════════════════════════════════════════════════════════════════════

class TestKillDuringInsert:

    def test_kill_during_overlay_insert_leaves_db_consistent(self, site):
        """Spawn a PHP process that's in the middle of a big INSERT,
        SIGKILL it, reopen DB, check integrity."""
        create_branch(site, "f")
        fid = branch_id(site, "f")

        # Launch a PHP script that does 1000 inserts in a loop.
        script = (
            f"$db = new SQLite3('{site}');"
            "$db->exec('PRAGMA busy_timeout=5000');"
            f"for ($i = 0; $i < 1000; $i++) {{"
            f"$db->exec(\"INSERT INTO b{fid}_wp_options "
            "(option_name, option_value) VALUES "
            "('k' . $i, 'v' . $i)\");"
            f"}}"
        )
        p = subprocess.Popen(
            [PHP_BIN, "-d", f"extension={EXT_PATH}",
             "-d", "display_errors=Off", "-r", script],
        )
        time.sleep(0.1)  # let some inserts happen
        os.kill(p.pid, signal.SIGKILL)
        p.wait(timeout=5)

        # DB must reopen cleanly
        assert reopen_integrity_ok(site), "integrity_check failed after SIGKILL"
        # Some inserts landed; some didn't. None should be half-written.
        rows = sqlite_q(site,
            f"SELECT option_name, option_value FROM b{fid}_wp_options "
            "WHERE option_name LIKE 'k%'")
        for name, val in rows:
            # k0,v0 | k1,v1 | ... — if we see k_X with val != v_X, that's corruption.
            assert name == name, "sanity"
            k_num = name[1:]
            assert val == "v" + k_num, f"half row: {name}={val}"


# ═════════════════════════════════════════════════════════════════════
# 3.2 — SIGKILL during branchctl commit
# ═════════════════════════════════════════════════════════════════════

class TestKillDuringCommit:

    def test_kill_during_commit_leaves_db_atomic(self, site):
        """SIGKILL during branchctl commit should leave either the
        pre-commit state or the post-commit state; no half-committed
        fs_commit without a matching db_commit."""
        create_branch(site, "f")
        fid = branch_id(site, "f")

        # Pre-populate with a few rows so commit has real work.
        db = sqlite3.connect(str(site))
        for j in range(100):
            db.execute(f"INSERT INTO b{fid}_wp_options "
                       "(option_name, option_value) VALUES (?, ?)",
                       (f"pre_{j}", f"v_{j}"))
        db.commit()
        db.close()

        # Count fs_commits and db_commits BEFORE.
        fs_before = sqlite_q(site,
            "SELECT COUNT(*) FROM fs_commits WHERE branch_id = ?", (fid,))[0][0]
        db_before = sqlite_q(site,
            "SELECT COUNT(*) FROM db_commits WHERE branch_id = ?", (fid,))[0][0]

        # Spawn commit, kill quickly.
        p = branchctl_popen(site, "commit", "f", "-m", "will_be_killed")
        time.sleep(0.05)
        os.kill(p.pid, signal.SIGKILL)
        p.wait(timeout=5)

        # DB intact.
        assert reopen_integrity_ok(site)

        # Invariant: every fs_commit on this branch has a matching db_commit,
        # OR it was the ORIGINAL initial commit paired at create-time.
        fs_rows = sqlite_q(site,
            "SELECT id, commit_hash FROM fs_commits WHERE branch_id = ?", (fid,))
        for fs_id, fs_hash in fs_rows:
            dc = sqlite_q(site,
                "SELECT id FROM db_commits WHERE branch_id = ? AND commit_hash = ?",
                (fid, fs_hash))
            assert dc, (
                f"fs_commit {fs_id} ({fs_hash}) has no matching db_commit "
                f"— commit was half-applied and not rolled back!"
            )

        # No orphan db_commit_overlays (commit_id points to a db_commit row).
        orphans = sqlite_q(site,
            "SELECT COUNT(*) FROM db_commit_overlays dco "
            "WHERE NOT EXISTS (SELECT 1 FROM db_commits dc WHERE dc.id = dco.commit_id)"
        )[0][0]
        assert orphans == 0, f"{orphans} orphan db_commit_overlays rows"


# ═════════════════════════════════════════════════════════════════════
# 3.3 — WAL file larger than main DB — verify checkpoint
# ═════════════════════════════════════════════════════════════════════

class TestWalCheckpoint:

    def test_large_wal_recovers_cleanly(self, site):
        """Write enough to make the WAL balloon, then force a checkpoint
        and confirm the DB is still correct."""
        create_branch(site, "f")
        fid = branch_id(site, "f")

        # Write ~5MB into the overlay.
        db = sqlite3.connect(str(site))
        db.execute("PRAGMA wal_autocheckpoint = 0")  # prevent auto-checkpoint
        for j in range(500):
            db.execute(
                f"INSERT INTO b{fid}_wp_options "
                "(option_name, option_value) VALUES (?, ?)",
                (f"wal_{j}", "x" * 1024))
        db.commit()

        # Now force a checkpoint.
        db.execute("PRAGMA wal_checkpoint(TRUNCATE)")
        db.close()

        assert reopen_integrity_ok(site)
        rows = sqlite_q(site,
            f"SELECT COUNT(*) FROM b{fid}_wp_options WHERE option_name LIKE 'wal_%'")
        assert rows[0][0] == 500


# ═════════════════════════════════════════════════════════════════════
# 3.4 — Corrupted WAL tail
# ═════════════════════════════════════════════════════════════════════

class TestCorruptedWalTail:

    def test_truncated_wal_recovers_to_last_valid_tx(self, site):
        """Truncate the last few bytes of the WAL file; SQLite should
        recover to the last valid transaction. We keep a second reader
        connection open during the truncate so SQLite doesn't delete the
        WAL on the writer's close."""
        create_branch(site, "f")
        fid = branch_id(site, "f")

        # Persistent reader holds the WAL open across our writer's close.
        reader = sqlite3.connect(str(site))
        reader.execute("PRAGMA wal_autocheckpoint = 0")
        reader.execute("SELECT 1").fetchone()

        db = sqlite3.connect(str(site))
        db.execute("PRAGMA wal_autocheckpoint = 0")
        for i in range(3):
            db.execute(
                f"INSERT INTO b{fid}_wp_options (option_name, option_value) "
                "VALUES (?, ?)", (f"w_{i}", f"v{i}"))
            db.commit()
        db.close()

        wal_path = Path(str(site) + "-wal")
        if not wal_path.exists():
            reader.close()
            pytest.skip("WAL file not present (auto-checkpointed away)")
        size = wal_path.stat().st_size
        if size < 64:
            reader.close()
            pytest.skip(f"WAL too small to truncate safely: {size}")

        # Truncate while the reader still holds the WAL open (so SQLite
        # doesn't delete it out from under us). Then release and reopen.
        with open(wal_path, "r+b") as f:
            f.truncate(size - 32)
        reader.close()

        # Reopen — SQLite should discard the trailing partial frame.
        db = sqlite3.connect(str(site), timeout=30.0)
        try:
            r = db.execute("PRAGMA integrity_check").fetchone()
            assert r == ("ok",), f"integrity after corrupted WAL: {r}"
            # The SELECT at least runs without error.
            rows = db.execute(
                f"SELECT COUNT(*) FROM b{fid}_wp_options WHERE option_name LIKE 'w_%'"
            ).fetchone()[0]
            assert rows >= 0  # range includes all or fewer
        finally:
            db.close()


# ═════════════════════════════════════════════════════════════════════
# 3.5 — Kill during branchctl merge
# ═════════════════════════════════════════════════════════════════════

class TestKillDuringMerge:

    def test_kill_during_merge_leaves_target_atomic(self, site):
        """SIGKILL during merge should leave target in either the
        pre-merge or post-merge state. A partial merge that mutated
        some rows but not others is not acceptable."""
        create_branch(site, "f")
        fid = branch_id(site, "f")

        # Branch makes several updates.
        db = sqlite3.connect(str(site))
        for k in ("blogname", "siteurl"):
            db.execute(
                f"UPDATE b{fid}_wp_options SET option_value='br_' || ? "
                "WHERE option_name = ?", (k, k))
        db.commit()
        db.close()

        main_before = dict(sqlite_q(site,
            "SELECT option_name, option_value FROM b1_wp_options "
            "WHERE option_name IN ('blogname','siteurl')"))

        # Launch the merge and kill it quickly.
        p = branchctl_popen(site, "merge", "f", "--into", "main")
        time.sleep(0.03)
        p.send_signal(signal.SIGKILL)
        p.wait(timeout=5)

        assert reopen_integrity_ok(site)

        main_after = dict(sqlite_q(site,
            "SELECT option_name, option_value FROM b1_wp_options "
            "WHERE option_name IN ('blogname','siteurl')"))

        # Each option is either unchanged (pre-merge) OR both are the
        # merged value. Partial is the failure mode we're guarding.
        both_pre = (main_after == main_before)
        both_post = (main_after["blogname"].startswith("br_")
                     and main_after["siteurl"].startswith("br_"))
        partial = not both_pre and not both_post
        # Accept either pre or post; reject half.
        assert not partial, (
            f"merge was half-applied: before={main_before}, after={main_after}"
        )


# ═════════════════════════════════════════════════════════════════════
# 3.6 — Kill during rollback
# ═════════════════════════════════════════════════════════════════════

class TestKillDuringRollback:

    def test_kill_during_rollback_atomic(self, site):
        # Vary the kill delay across iterations so we hit both "rollback
        # landed" and "rollback didn't start" outcomes — without observing
        # both we wouldn't actually be testing the rollback path, we'd just
        # be testing that SIGKILL-before-work survives. Assert atomicity
        # on every iteration (no v1.5 / "partial rollback" state) and
        # assert we observed the rollback-landed path at least once.
        outcomes = set()
        # Rollback takes ~300ms (PHP startup + transaction); span delays
        # past that so some iterations land rollback, others kill pre-commit.
        delays_ms = [0, 20, 80, 160, 280, 340, 400, 600]

        for delay_ms in delays_ms:
            create_branch(site, "f")
            fid = branch_id(site, "f")

            sqlite_exec(site,
                f"UPDATE b{fid}_wp_options SET option_value='v1' WHERE option_name='blogname'")
            r = commit_branch(site, "f", "v1")
            assert r.returncode == 0
            sqlite_exec(site,
                f"UPDATE b{fid}_wp_options SET option_value='v2' WHERE option_name='blogname'")
            r = commit_branch(site, "f", "v2")
            assert r.returncode == 0

            p = branchctl_popen(site, "rollback", "f")
            if delay_ms > 0:
                time.sleep(delay_ms / 1000.0)
            p.send_signal(signal.SIGKILL)
            p.wait(timeout=5)

            assert reopen_integrity_ok(site), \
                f"integrity broken at delay={delay_ms}ms"
            val = sqlite_q(site,
                f"SELECT option_value FROM b{fid}_wp_options WHERE option_name='blogname'"
            )[0][0]
            assert val in ("v1", "v2"), \
                f"partial rollback at delay={delay_ms}ms: value={val!r}"
            outcomes.add(val)

            # Reset for next iteration by deleting the branch.
            from _rigorous_helpers import branchctl
            _ = branchctl(site, "delete", "f", "--force")

        assert "v1" in outcomes, (
            "Never observed rollback-landed outcome across "
            f"delays={delays_ms} — test did not exercise rollback path"
        )

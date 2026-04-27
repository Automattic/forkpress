"""
Rigorous backup/restore tests: hot backups, branch preservation, merges
on restored sites.

Run:
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_rigorous_backup.py -v
"""

import hashlib
import os
import shutil
import sqlite3
import subprocess
import tempfile
import threading
import time
from pathlib import Path

import pytest

from _rigorous_helpers import (
    branchctl, branch_id, commit_branch, create_branch,
    init_site, make_fresh_site, require_ext,
    sqlite_exec, sqlite_q,
    BASE_DIR, EXT_PATH, PHP_BIN,
)


@pytest.fixture
def site():
    work, site_fp = make_fresh_site(prefix="rigback_")
    yield work, site_fp
    shutil.rmtree(work, ignore_errors=True)


def backup_php(src: Path, dst: Path) -> subprocess.CompletedProcess:
    return subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         str(BASE_DIR / "scripts" / "backup.php"),
         str(src), str(dst)],
        capture_output=True, text=True, timeout=60,
    )


# ═════════════════════════════════════════════════════════════════════
# 9.1 — Hot backup during writes
# ═════════════════════════════════════════════════════════════════════

class TestHotBackup:

    def test_backup_while_writes_in_flight_is_consistent(self, site):
        work, site_fp = site
        create_branch(site_fp, "f")
        fid = branch_id(site_fp, "f")

        # Pre-seed with a meaningful payload so the backup has real work to
        # copy (not an empty DB that's done in microseconds). ~5k rows with
        # a non-trivial blob payload pushes the DB over a few megabytes so
        # the backup actually spans enough wall time to overlap the writer.
        seed_db = sqlite3.connect(str(site_fp), timeout=30.0)
        seed_db.execute("PRAGMA busy_timeout=20000")
        blob = "x" * 2048
        seed_db.execute("BEGIN")
        for i in range(5000):
            seed_db.execute(
                f"INSERT INTO b{fid}_wp_options "
                "(option_name, option_value) VALUES (?, ?)",
                (f"seed_{i}", blob),
            )
        seed_db.commit()
        seed_db.close()

        size_before = site_fp.stat().st_size

        # Writer thread inserts during backup.
        stop = threading.Event()
        insert_count = [0]
        writer_err = []

        def writer():
            db = sqlite3.connect(str(site_fp), timeout=30.0)
            db.execute("PRAGMA busy_timeout=20000")
            try:
                i = 0
                while not stop.is_set() and i < 2000:
                    try:
                        db.execute(
                            f"INSERT INTO b{fid}_wp_options "
                            "(option_name, option_value) VALUES (?, ?)",
                            (f"w_{i}", f"v_{i}"))
                        db.commit()
                        insert_count[0] += 1
                        i += 1
                    except sqlite3.OperationalError as e:
                        if "busy" in str(e).lower() or "locked" in str(e).lower():
                            time.sleep(0.005)
                            continue
                        writer_err.append(e)
                        return
            finally:
                db.close()

        wt = threading.Thread(target=writer)
        wt.start()
        time.sleep(0.05)

        dst = work / "backup.fp"
        t0 = time.time()
        r = backup_php(site_fp, dst)
        backup_wall_ms = (time.time() - t0) * 1000.0
        assert r.returncode == 0, f"backup failed: {r.stderr}"

        stop.set()
        wt.join(timeout=30)
        assert not writer_err
        # The writer must have landed inserts during the backup window —
        # otherwise the concurrency assertion below is vacuous.
        assert insert_count[0] > 0, (
            f"no concurrent writes landed in {backup_wall_ms:.0f}ms backup "
            "window; test isn't exercising the hot-backup path"
        )

        # Backup is a valid sqlite file with expected tables.
        assert dst.exists()
        # Backup size should be at least ~the DB size at start (hot-backup
        # copies a snapshot, not diffs).
        assert dst.stat().st_size >= size_before * 0.9, (
            f"backup size {dst.stat().st_size} << source {size_before} — "
            "hot backup may have shortcut on an empty/small DB"
        )
        db = sqlite3.connect(str(dst))
        try:
            # integrity_check
            ok = db.execute("PRAGMA integrity_check").fetchone()
            assert ok == ("ok",)
            # Branches table intact
            rows = db.execute("SELECT name FROM branches").fetchall()
            names = {r[0] for r in rows}
            assert "main" in names
            assert "f" in names
            # Seed rows must all be in the backup (committed before backup
            # started, so a consistent snapshot must include them).
            seed_rows = db.execute(
                f"SELECT COUNT(*) FROM b{fid}_wp_options "
                "WHERE option_name LIKE 'seed_%'"
            ).fetchone()[0]
            assert seed_rows == 5000, (
                f"backup dropped pre-backup committed rows: "
                f"expected 5000, got {seed_rows}"
            )
        finally:
            db.close()


# ═════════════════════════════════════════════════════════════════════
# 9.2 — Backup → restore → branchctl operations work
# ═════════════════════════════════════════════════════════════════════

class TestRestoreAndOperate:

    def test_restore_supports_commit_and_rollback(self, site):
        work, site_fp = site
        create_branch(site_fp, "f")
        fid = branch_id(site_fp, "f")

        # Make some changes + commit.
        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) VALUES (?, ?)",
            ("pre_bk", "a"))
        r = commit_branch(site_fp, "f", "pre-backup")
        assert r.returncode == 0

        dst = work / "restored.fp"
        r = backup_php(site_fp, dst)
        assert r.returncode == 0

        # On the restored site, make further changes and commit again.
        rid = branch_id(dst, "f")
        assert rid == fid
        sqlite_exec(dst,
            f"INSERT INTO b{rid}_wp_options (option_name, option_value) VALUES (?, ?)",
            ("post_bk", "b"))
        r = branchctl(dst, "commit", "f", "-m", "post_bk")
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"

        # Rollback should revert to pre_bk state.
        r = branchctl(dst, "rollback", "f")
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"

        names = {r[0] for r in sqlite_q(dst,
            f"SELECT option_name FROM b{rid}_wp_options WHERE option_name LIKE 'p%_bk'")}
        assert "pre_bk" in names
        assert "post_bk" not in names


# ═════════════════════════════════════════════════════════════════════
# 9.3 — Backup preserves content for digest
# ═════════════════════════════════════════════════════════════════════

class TestBackupContentIdentical:

    def test_backup_digest_matches_source_except_ephemeral(self, site):
        """Backup must preserve all application data. Ephemeral fields
        (rowids, wal, etc.) are exempt."""
        work, site_fp = site
        create_branch(site_fp, "f")
        create_branch(site_fp, "g")
        fid = branch_id(site_fp, "f")

        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) VALUES (?, ?)",
            ("bk_row", "v"))
        r = commit_branch(site_fp, "f", "pre")
        assert r.returncode == 0

        dst = work / "bk.fp"
        assert backup_php(site_fp, dst).returncode == 0

        # Compare branches table
        src_branches = sorted(sqlite_q(site_fp,
            "SELECT name, parent_branch FROM branches"))
        dst_branches = sorted(sqlite_q(dst,
            "SELECT name, parent_branch FROM branches"))
        assert src_branches == dst_branches

        # Compare row content in overlays
        src_rows = sorted(sqlite_q(site_fp,
            f"SELECT option_name, option_value FROM b{fid}_wp_options__overlay"))
        dst_rows = sorted(sqlite_q(dst,
            f"SELECT option_name, option_value FROM b{fid}_wp_options__overlay"))
        assert src_rows == dst_rows

        # fs_commits count matches
        src_n = sqlite_q(site_fp, "SELECT COUNT(*) FROM fs_commits")[0][0]
        dst_n = sqlite_q(dst, "SELECT COUNT(*) FROM fs_commits")[0][0]
        assert src_n == dst_n


# ═════════════════════════════════════════════════════════════════════
# 9.4 — Backup refuses to overwrite
# ═════════════════════════════════════════════════════════════════════

class TestBackupSafety:

    def test_backup_refuses_to_overwrite_existing(self, site):
        work, site_fp = site
        dst = work / "exists.fp"
        dst.write_text("")  # make it exist
        r = backup_php(site_fp, dst)
        assert r.returncode != 0, "backup overwrote existing file"
        # content unchanged
        assert dst.read_text() == ""

    def test_backup_fails_on_missing_source(self, site):
        work, _ = site
        r = backup_php(Path("/tmp/nonexistent_source.fp"), work / "out.fp")
        assert r.returncode != 0

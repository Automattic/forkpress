"""
Rigorous format-compatibility tests: old (pre-COW, pre-versioning) .fp
files should still work via lazy migration.

Run:
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_rigorous_compat.py -v
"""

import json
import os
import re
import shutil
import sqlite3
import subprocess
import tempfile
from pathlib import Path

import pytest

from _rigorous_helpers import (
    branchctl, branch_id, commit_branch, create_branch,
    init_site, make_fresh_site, require_ext,
    sqlite_exec, sqlite_q,
)


@pytest.fixture
def site():
    work, site_fp = make_fresh_site(prefix="rigcompat_")
    yield work, site_fp
    shutil.rmtree(work, ignore_errors=True)


def make_legacy_branch(site_fp: Path, branch_name: str, parent: str = "main"):
    """Create a branch the OLD way: real table copy + db_snapshots. This
    mimics pre-COW .fp files."""
    # Force fs_migrate to create any missing tables.
    branchctl(site_fp, "list")
    db = sqlite3.connect(str(site_fp))
    try:
        cur = db.execute(
            "INSERT INTO branches (name, parent_branch) VALUES (?, ?)",
            (branch_name, parent))
        bid = cur.lastrowid
        prow = db.execute(
            "SELECT id FROM branches WHERE name = ?", (parent,)).fetchone()
        pid = prow[0]
        prefix_old = f"b{pid}_wp_"
        prefix_new = f"b{bid}_wp_"

        tables = [
            r[0] for r in db.execute(
                "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE ?",
                (prefix_old + "%",)
            ).fetchall()
            if not r[0].endswith("__overlay") and not r[0].endswith("__tombstones")
        ]
        for old in tables:
            new = prefix_new + old[len(prefix_old):]
            ddl = db.execute(
                "SELECT sql FROM sqlite_master WHERE type='table' AND name=?",
                (old,)).fetchone()[0]
            new_ddl = re.sub(
                r'^(CREATE\s+TABLE\s+)(?:IF\s+NOT\s+EXISTS\s+)?"?'
                + re.escape(old) + r'"?',
                r'\1IF NOT EXISTS "' + new + r'"',
                ddl, count=1, flags=re.IGNORECASE,
            )
            db.execute(new_ddl)
            db.execute(f'INSERT INTO "{new}" SELECT * FROM "{old}"')
            # Snapshot rows to db_snapshots (legacy ancestor model)
            pi = db.execute(f'PRAGMA table_info("{new}")').fetchall()
            col_names = [r[1] for r in pi]
            pk_cols = [r[1] for r in pi if r[5] > 0]
            for row in db.execute(f'SELECT * FROM "{new}"'):
                row_dict = dict(zip(col_names, row))
                pk_map = {c: row_dict[c] for c in (pk_cols or col_names)}
                db.execute(
                    "INSERT OR REPLACE INTO db_snapshots (branch_id, table_name, row_pk, row_json) "
                    "VALUES (?, ?, ?, ?)",
                    (bid, new, json.dumps(pk_map), json.dumps(row_dict)))
        db.commit()
        return bid
    finally:
        db.close()


# ═════════════════════════════════════════════════════════════════════
# 12.1 — Legacy branch readable
# ═════════════════════════════════════════════════════════════════════

class TestLegacyReadability:

    def test_legacy_branch_select_works(self, site):
        work, site_fp = site
        bid = make_legacy_branch(site_fp, "legacy_read")
        # Table is a real table (legacy format)
        t = sqlite_q(site_fp,
            "SELECT type FROM sqlite_master WHERE name = ?",
            (f"b{bid}_wp_options",))
        assert t == [("table",)]
        # Reads work
        rows = sqlite_q(site_fp,
            f"SELECT option_name FROM b{bid}_wp_options ORDER BY option_name")
        names = [r[0] for r in rows]
        assert "blogname" in names


# ═════════════════════════════════════════════════════════════════════
# 12.2 — Legacy branch migrates on merge
# ═════════════════════════════════════════════════════════════════════

class TestLegacyLazyMigration:

    def test_merge_migrates_legacy_to_cow(self, site):
        work, site_fp = site
        bid = make_legacy_branch(site_fp, "legacy_merge")
        # Add a small change
        sqlite_exec(site_fp,
            f"INSERT INTO b{bid}_wp_options (option_name, option_value) "
            "VALUES (?, ?)", ("legacy_add", "v"))

        r = branchctl(site_fp, "merge", "legacy_merge", "--into", "main")
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"

        # After migration, b{bid}_wp_options is a view (COW format).
        t = sqlite_q(site_fp,
            "SELECT type FROM sqlite_master WHERE name = ?",
            (f"b{bid}_wp_options",))
        assert t == [("view",)], f"expected view after migration, got {t}"

        # Main received the addition.
        rows = sqlite_q(site_fp,
            "SELECT option_value FROM b1_wp_options WHERE option_name = 'legacy_add'")
        assert rows == [("v",)]

    def test_migrate_legacy_branch_with_schema_change(self, site):
        work, site_fp = site
        bid = make_legacy_branch(site_fp, "legacy_schema")

        # Add a column directly via ALTER on the legacy table (simulating
        # an old-format branch that evolved its schema).
        sqlite_exec(site_fp,
            f'ALTER TABLE "b{bid}_wp_options" ADD COLUMN legacy_extra TEXT')
        sqlite_exec(site_fp,
            f"INSERT INTO b{bid}_wp_options (option_name, option_value, legacy_extra) "
            "VALUES (?, ?, ?)", ("legacy_ext_row", "v", "extra"))

        # Merge should migrate and carry the row (schema merge on main).
        r = branchctl(site_fp, "merge", "legacy_schema", "--into", "main")
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"

        # Main got the column and the row
        cols = sqlite_q(site_fp, "PRAGMA table_info('b1_wp_options')")
        col_names = [c[1] for c in cols]
        assert "legacy_extra" in col_names

        rows = sqlite_q(site_fp,
            "SELECT option_value, legacy_extra FROM b1_wp_options "
            "WHERE option_name = 'legacy_ext_row'")
        assert rows == [("v", "extra")]


# ═════════════════════════════════════════════════════════════════════
# 12.3 — Old site → backup → restore → operate
# ═════════════════════════════════════════════════════════════════════

class TestLegacyBackupRestore:

    def test_backup_of_site_with_legacy_branch_works(self, site):
        work, site_fp = site
        bid = make_legacy_branch(site_fp, "leg_b")

        dst = work / "legacy_bk.fp"
        # Use backup.php directly
        r = subprocess.run(
            ["php",
             "-d", f"extension={Path(__file__).parent.parent / 'ext' / 'branchfs.so'}",
             str(Path(__file__).parent.parent / "scripts" / "backup.php"),
             str(site_fp), str(dst)],
            capture_output=True, text=True, timeout=60,
        )
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"

        # On the restored site: legacy branch readable
        rows = sqlite_q(dst,
            f"SELECT option_name FROM b{bid}_wp_options ORDER BY option_name")
        assert any(r[0] == "blogname" for r in rows)

        # And we can merge (triggers lazy migration) on the restored copy.
        sqlite_exec(dst,
            f"INSERT INTO b{bid}_wp_options (option_name, option_value) "
            "VALUES (?, ?)", ("post_restore", "x"))
        r = branchctl(dst, "merge", "leg_b", "--into", "main")
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"


# ═════════════════════════════════════════════════════════════════════
# 12.4 — branchctl on a site without db_commits table (pre-versioning)
# ═════════════════════════════════════════════════════════════════════

class TestPreVersioningSchema:

    def test_fs_migrate_adds_db_commits_tables_lazy(self, site):
        work, site_fp = site

        # Simulate pre-versioning: drop db_commits tables.
        db = sqlite3.connect(str(site_fp))
        for t in ("db_commits", "db_commit_overlays",
                  "db_commit_tombstones", "db_commit_schema"):
            db.execute(f"DROP TABLE IF EXISTS {t}")
        db.commit()
        db.close()

        # Now run branchctl list — fs_migrate should recreate them.
        r = branchctl(site_fp, "list")
        assert r.returncode == 0

        names = {r[0] for r in sqlite_q(site_fp,
            "SELECT name FROM sqlite_master WHERE type='table'")}
        for t in ("db_commits", "db_commit_overlays",
                  "db_commit_tombstones", "db_commit_schema"):
            assert t in names, f"{t} not recreated by fs_migrate"

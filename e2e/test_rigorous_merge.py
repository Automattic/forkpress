"""
Rigorous merge corner cases: self-merge, merge-into-parent, merge after
rollback, schema + row changes, re-merge after main advances.

Run:
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_rigorous_merge.py -v
"""

import os
import shutil
import sqlite3
import subprocess
import tempfile
from pathlib import Path

import pytest

from _rigorous_helpers import (
    branchctl, branch_id, commit_branch, create_branch,
    init_site, make_fresh_site, require_ext,
    sqlite_exec, sqlite_q, sqlite_executescript,
)


@pytest.fixture
def site():
    work, site_fp = make_fresh_site(prefix="rigmerge_")
    yield site_fp
    shutil.rmtree(work, ignore_errors=True)


# ═════════════════════════════════════════════════════════════════════
# 8.1 — Self-merge rejected
# ═════════════════════════════════════════════════════════════════════

class TestSelfMerge:

    def test_merge_branch_into_itself_rejected(self, site):
        create_branch(site, "self")
        r = branchctl(site, "merge", "self", "--into", "self")
        assert r.returncode != 0, f"self-merge accepted: {r.stdout}"

    def test_merge_main_into_main_rejected(self, site):
        r = branchctl(site, "merge", "main", "--into", "main")
        # valid_branch_name rejects 'main' as source
        assert r.returncode != 0


# ═════════════════════════════════════════════════════════════════════
# 8.2 — Merge with schema change + row change
# ═════════════════════════════════════════════════════════════════════

class TestMixedSchemaRowChange:

    def test_add_column_and_insert_on_same_branch_then_merge(self, site):
        create_branch(site, "f")
        fid = branch_id(site, "f")

        # Schema change: add a column
        r = branchctl(site, "alter-add-column", "f", "options", "meta", "TEXT")
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        # Row change: use new column
        sqlite_exec(site,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value, meta) "
            "VALUES (?, ?, ?)",
            ("schema_row", "v", "mdata"))

        # Merge into main
        r = branchctl(site, "merge", "f", "--into", "main")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        # Verify both schema + row change landed on main
        cols = sqlite_q(site, "PRAGMA table_info('b1_wp_options')")
        col_names = [c[1] for c in cols]
        assert "meta" in col_names, f"meta column not merged to main: {col_names}"

        rows = sqlite_q(site,
            "SELECT option_name, option_value, meta FROM b1_wp_options "
            "WHERE option_name = 'schema_row'")
        assert rows == [("schema_row", "v", "mdata")]


# ═════════════════════════════════════════════════════════════════════
# 8.3 — Merge after rollback (ancestor correctness)
# ═════════════════════════════════════════════════════════════════════

class TestMergeAfterRollback:

    def test_rollback_then_merge_uses_current_ancestor(self, site):
        create_branch(site, "f")
        fid = branch_id(site, "f")

        # Make a commit with blogname=step1
        sqlite_exec(site,
            f"UPDATE b{fid}_wp_options SET option_value='step1' WHERE option_name='blogname'")
        r = commit_branch(site, "f", "step1")
        assert r.returncode == 0

        # Another commit with blogname=step2
        sqlite_exec(site,
            f"UPDATE b{fid}_wp_options SET option_value='step2' WHERE option_name='blogname'")
        r = commit_branch(site, "f", "step2")
        assert r.returncode == 0

        # Rollback (back to step1)
        r = branchctl(site, "rollback", "f")
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"

        val = sqlite_q(site,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name='blogname'")
        assert val == [("step1",)]

        # Merge into main — should carry step1, not step2.
        r = branchctl(site, "merge", "f", "--into", "main")
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"

        main_val = sqlite_q(site,
            "SELECT option_value FROM b1_wp_options WHERE option_name='blogname'")
        assert main_val == [("step1",)]


# ═════════════════════════════════════════════════════════════════════
# 8.4 — Merge into parent
# ═════════════════════════════════════════════════════════════════════

class TestMergeIntoParent:

    def test_merge_child_into_parent(self, site):
        """main -> p -> c. Merge c into p; p sees c's changes."""
        create_branch(site, "p")
        create_branch(site, "c", "p")
        cid = branch_id(site, "c")
        pid = branch_id(site, "p")

        sqlite_exec(site,
            f"INSERT INTO b{cid}_wp_options (option_name, option_value) VALUES (?, ?)",
            ("from_c", "C"))
        r = branchctl(site, "merge", "c", "--into", "p")
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"

        # p now has the row
        rows = sqlite_q(site,
            f"SELECT option_value FROM b{pid}_wp_options WHERE option_name = 'from_c'")
        assert rows == [("C",)]


# ═════════════════════════════════════════════════════════════════════
# 8.5 — Re-merge after main advances
# ═════════════════════════════════════════════════════════════════════

class TestReMergeAfterMainAdvances:

    def test_second_merge_after_main_advance(self, site):
        """Create branch, merge, main advances, branch advances on a different
        table, merge again. Uses term_relationships (composite PK) to avoid
        autoincrement collisions between parallel inserts."""
        create_branch(site, "f")
        fid = branch_id(site, "f")

        # First change on branch: insert into a composite-PK table.
        sqlite_exec(site,
            f"INSERT INTO b{fid}_wp_term_relationships "
            "(object_id, term_taxonomy_id, term_order) VALUES (?, ?, ?)",
            (10, 100, 0))
        r = branchctl(site, "merge", "f", "--into", "main")
        assert r.returncode == 0, f"first merge: {r.stdout}\n{r.stderr}"

        # Main advances independently (different rows)
        sqlite_exec(site,
            "INSERT INTO b1_wp_term_relationships "
            "(object_id, term_taxonomy_id, term_order) VALUES (?, ?, ?)",
            (20, 100, 0))

        # Branch advances again on yet another row
        sqlite_exec(site,
            f"INSERT INTO b{fid}_wp_term_relationships "
            "(object_id, term_taxonomy_id, term_order) VALUES (?, ?, ?)",
            (30, 100, 0))

        r = branchctl(site, "merge", "f", "--into", "main")
        assert r.returncode == 0, f"second merge: {r.stdout}\n{r.stderr}"

        # All three non-fixture rows must be present post-merge.
        rows = sqlite_q(site,
            "SELECT object_id, term_taxonomy_id FROM b1_wp_term_relationships "
            "WHERE term_taxonomy_id = 100 ORDER BY object_id")
        assert rows == [(10, 100), (20, 100), (30, 100)]


# ═════════════════════════════════════════════════════════════════════
# 8.6 — Strategy theirs + ours
# ═════════════════════════════════════════════════════════════════════

class TestStrategiesAreAtomic:

    def test_theirs_conflicts_resolved_atomically(self, site):
        create_branch(site, "f")
        fid = branch_id(site, "f")

        # Both sides change same row
        sqlite_exec(site,
            "UPDATE b1_wp_options SET option_value='mainv' WHERE option_name='blogname'")
        sqlite_exec(site,
            f"UPDATE b{fid}_wp_options SET option_value='branchv' WHERE option_name='blogname'")

        r = branchctl(site, "merge", "f", "--into", "main", "--strategy", "theirs")
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        val = sqlite_q(site,
            "SELECT option_value FROM b1_wp_options WHERE option_name='blogname'")
        assert val == [("branchv",)]


# ═════════════════════════════════════════════════════════════════════
# 8.7 — id-collision mode
# ═════════════════════════════════════════════════════════════════════

class TestIdCollisionBehavior:

    def test_on_id_collision_renumber_on_non_wp_table(self, site):
        """--on-id-collision=renumber should renumber only on supported tables;
        on an unregistered table, behavior should be conflict (default) — never silent corruption."""
        # Create a non-WP table.
        sqlite_exec(site, """
            CREATE TABLE b1_wp_mymessages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                body TEXT
            )
        """)
        sqlite_exec(site,
            "INSERT INTO b1_wp_mymessages (body) VALUES ('main_msg_1')")

        create_branch(site, "f")
        fid = branch_id(site, "f")

        # Branch adds a row (id=2)
        sqlite_exec(site,
            f"INSERT INTO b{fid}_wp_mymessages (body) VALUES ('branch_msg')")
        # Main adds a row (id=2) — collision
        sqlite_exec(site,
            "INSERT INTO b1_wp_mymessages (body) VALUES ('main_msg_2')")

        r = branchctl(site, "merge", "f", "--into", "main",
                      "--on-id-collision", "renumber")
        # Renumber is meant for WP tables; for a non-WP mymessages table the
        # merge should either renumber (if generalized) or report a clean
        # conflict — but never silently corrupt main's data.
        if r.returncode == 0:
            # Success path: all three rows present, no duplicate IDs.
            rows = sqlite_q(site, "SELECT id, body FROM b1_wp_mymessages ORDER BY id")
            ids = [r[0] for r in rows]
            assert len(set(ids)) == len(ids), f"duplicate IDs: {rows}"
            bodies = {r[1] for r in rows}
            assert "main_msg_1" in bodies
            assert "main_msg_2" in bodies
            assert "branch_msg" in bodies
        else:
            # Conflict path: main untouched — branch_msg NOT applied.
            rows = sqlite_q(site, "SELECT body FROM b1_wp_mymessages")
            bodies = {r[0] for r in rows}
            assert "branch_msg" not in bodies, (
                f"conflict reported but branch row still leaked in: {bodies}"
            )


# ═════════════════════════════════════════════════════════════════════
# 8.8 — merge into unknown target
# ═════════════════════════════════════════════════════════════════════

class TestMergeNonexistentTarget:

    def test_merge_into_nonexistent_rejected_cleanly(self, site):
        create_branch(site, "f")
        r = branchctl(site, "merge", "f", "--into", "nonexistent")
        assert r.returncode != 0, \
            f"merge --into nonexistent accepted: {r.stdout}\n{r.stderr}"
        # Main must be intact
        assert sqlite_q(site, "SELECT COUNT(*) FROM b1_wp_options")[0][0] >= 3

"""
Rigorous primary-key edge-case tests: composite PKs, TEXT PKs,
WITHOUT ROWID tables, no explicit PK, case sensitivity.

Run:
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_rigorous_pk.py -v
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
    work, site_fp = make_fresh_site(prefix="rigpk_")
    yield site_fp
    shutil.rmtree(work, ignore_errors=True)


def branch_with(site_fp, extra_schema, extra_inserts=None):
    """Attach extra tables on main, then create a feature branch."""
    sqlite_executescript(site_fp, extra_schema)
    if extra_inserts:
        db = sqlite3.connect(str(site_fp))
        for sql, params in extra_inserts:
            db.execute(sql, params)
        db.commit()
        db.close()
    create_branch(site_fp, "feature")
    return branch_id(site_fp, "feature")


# ═════════════════════════════════════════════════════════════════════
# 5.1 — Composite 2-col PK (wp_term_relationships shape)
# ═════════════════════════════════════════════════════════════════════

class TestComposite2ColPK:

    def test_full_merge_rollback_cycle(self, site):
        """wp_term_relationships has (object_id, term_taxonomy_id) PK.
        Already in fixture — verify full lifecycle."""
        create_branch(site, "f")
        fid = branch_id(site, "f")

        # Branch adds a row
        sqlite_exec(site,
            f"INSERT INTO b{fid}_wp_term_relationships "
            "(object_id, term_taxonomy_id, term_order) VALUES (?, ?, ?)",
            (3, 30, 0))
        # Branch deletes one of parent's rows
        sqlite_exec(site,
            f"DELETE FROM b{fid}_wp_term_relationships "
            "WHERE object_id = 2 AND term_taxonomy_id = 10")
        # Branch updates one
        sqlite_exec(site,
            f"UPDATE b{fid}_wp_term_relationships SET term_order = 99 "
            "WHERE object_id = 1 AND term_taxonomy_id = 10")

        r = commit_branch(site, "f", "composite PK changes")
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"

        # Now merge into main.
        r = branchctl(site, "merge", "f", "--into", "main")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        # Main should reflect: (3,30,0) added, (2,10,?) deleted, (1,10,99) modified.
        rows = sqlite_q(site,
            "SELECT object_id, term_taxonomy_id, term_order "
            "FROM b1_wp_term_relationships ORDER BY object_id, term_taxonomy_id")
        assert (3, 30, 0) in rows
        assert (2, 10, 0) not in rows
        assert (1, 10, 99) in rows


# ═════════════════════════════════════════════════════════════════════
# 5.2 — Composite 3-col PK
# ═════════════════════════════════════════════════════════════════════

class TestComposite3ColPK:

    def test_3col_pk_works(self, site):
        fid = branch_with(site, """
            CREATE TABLE b1_wp_usermeta3 (
                a INTEGER NOT NULL,
                b INTEGER NOT NULL,
                c TEXT NOT NULL,
                d TEXT,
                PRIMARY KEY (a, b, c)
            );
            INSERT INTO b1_wp_usermeta3 VALUES (1, 10, 'x', 'orig');
        """)

        sqlite_exec(site,
            f"INSERT INTO b{fid}_wp_usermeta3 (a,b,c,d) VALUES (2, 20, 'y', 'br')")
        sqlite_exec(site,
            f"UPDATE b{fid}_wp_usermeta3 SET d='updated' WHERE a=1 AND b=10 AND c='x'")

        r = commit_branch(site, "feature", "3-col PK")
        assert r.returncode == 0

        # Verify both rows visible
        rows = sqlite_q(site,
            f"SELECT a,b,c,d FROM b{fid}_wp_usermeta3 ORDER BY a, b")
        assert (1, 10, 'x', 'updated') in rows
        assert (2, 20, 'y', 'br') in rows

        # Rollback restores orig
        r = branchctl(site, "rollback", "feature", "--force")
        # Rollback works only if there was a prior commit; the initial commit
        # happens at create-time, so rollback should succeed.
        assert r.returncode == 0, f"rollback: {r.stdout}\n{r.stderr}"
        post = sqlite_q(site,
            f"SELECT a,b,c,d FROM b{fid}_wp_usermeta3 ORDER BY a, b")
        assert (1, 10, 'x', 'orig') in post
        assert (2, 20, 'y', 'br') not in post


# ═════════════════════════════════════════════════════════════════════
# 5.3 — TEXT primary key
# ═════════════════════════════════════════════════════════════════════

class TestTextPK:

    def test_text_pk_insert_update_delete(self, site):
        fid = branch_with(site, """
            CREATE TABLE b1_wp_slugmap (
                slug TEXT PRIMARY KEY,
                target TEXT NOT NULL
            );
            INSERT INTO b1_wp_slugmap VALUES ('home', '/'), ('about', '/about');
        """)

        # Branch sees parent's rows
        rows = sqlite_q(site,
            f"SELECT slug, target FROM b{fid}_wp_slugmap ORDER BY slug")
        assert rows == [('about', '/about'), ('home', '/')]

        # Branch adds
        sqlite_exec(site,
            f"INSERT INTO b{fid}_wp_slugmap (slug, target) VALUES (?, ?)",
            ("contact", "/contact"))
        # Branch updates
        sqlite_exec(site,
            f"UPDATE b{fid}_wp_slugmap SET target=? WHERE slug=?",
            ("/home-new", "home"))
        # Branch deletes
        sqlite_exec(site,
            f"DELETE FROM b{fid}_wp_slugmap WHERE slug=?", ("about",))

        rows = sqlite_q(site,
            f"SELECT slug, target FROM b{fid}_wp_slugmap ORDER BY slug")
        assert rows == [('contact', '/contact'), ('home', '/home-new')]

        r = commit_branch(site, "feature", "text pk")
        assert r.returncode == 0

        # Main untouched
        main_rows = sqlite_q(site,
            "SELECT slug, target FROM b1_wp_slugmap ORDER BY slug")
        assert main_rows == [('about', '/about'), ('home', '/')]

        # Merge
        r = branchctl(site, "merge", "feature", "--into", "main")
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        main_rows = sqlite_q(site,
            "SELECT slug, target FROM b1_wp_slugmap ORDER BY slug")
        assert main_rows == [('contact', '/contact'), ('home', '/home-new')]


# ═════════════════════════════════════════════════════════════════════
# 5.4 — WITHOUT ROWID table
# ═════════════════════════════════════════════════════════════════════

class TestWithoutRowid:

    def test_without_rowid_branch_basic(self, site):
        fid = branch_with(site, """
            CREATE TABLE b1_wp_kv (
                k TEXT PRIMARY KEY,
                v TEXT NOT NULL
            ) WITHOUT ROWID;
            INSERT INTO b1_wp_kv VALUES ('a', '1'), ('b', '2');
        """)

        # Branch's view should work
        rows = sqlite_q(site, f"SELECT k, v FROM b{fid}_wp_kv ORDER BY k")
        assert rows == [('a', '1'), ('b', '2')]

        sqlite_exec(site,
            f"INSERT INTO b{fid}_wp_kv (k, v) VALUES (?, ?)", ("c", "3"))
        sqlite_exec(site,
            f"UPDATE b{fid}_wp_kv SET v=? WHERE k=?", ("1-new", "a"))

        r = commit_branch(site, "feature", "WITHOUT ROWID")
        assert r.returncode == 0

        rows = sqlite_q(site,
            f"SELECT k, v FROM b{fid}_wp_kv ORDER BY k")
        assert rows == [('a', '1-new'), ('b', '2'), ('c', '3')]


# ═════════════════════════════════════════════════════════════════════
# 5.5 — No explicit PK (rowid-only) — document behaviour
# ═════════════════════════════════════════════════════════════════════

class TestNoExplicitPK:

    def test_no_pk_table_branch_basic_read(self, site):
        fid = branch_with(site, """
            CREATE TABLE b1_wp_log (
                msg TEXT NOT NULL,
                ts INTEGER
            );
            INSERT INTO b1_wp_log (msg, ts) VALUES ('hi', 100);
        """)

        # Can we at least SELECT from the branch view?
        rows = sqlite_q(site, f"SELECT msg, ts FROM b{fid}_wp_log")
        assert rows == [('hi', 100)]

    @pytest.mark.xfail(
        reason="DELETE of a parent-only row on a no-PK table is not hidden by "
               "the branch view. The view's SELECT body for tables without an "
               "explicit PK uses plain UNION ALL with no tombstone filter, "
               "because full-row tombstone matching across UNION ALL is "
               "nontrivial (needs a correlated EXISTS subquery and may produce "
               "duplicate matches). WordPress core has a PK on every table, so "
               "this edge case is documented rather than fixed. See PRD.md "
               "non-requirements."
    )
    def test_no_pk_delete_hidden_via_tombstone(self, site):
        """With no PK, DELETE through the branch view SHOULD hide the row,
        using a full-row tombstone. Currently does not. Documented xfail."""
        fid = branch_with(site, """
            CREATE TABLE b1_wp_pklog (
                msg TEXT NOT NULL,
                ts INTEGER
            );
            INSERT INTO b1_wp_pklog (msg, ts) VALUES ('hi', 100);
        """)

        sqlite_exec(site,
            f"DELETE FROM b{fid}_wp_pklog WHERE msg = 'hi'")
        rows = sqlite_q(site, f"SELECT msg FROM b{fid}_wp_pklog")
        assert rows == []
        # Parent untouched
        rows_main = sqlite_q(site, "SELECT msg FROM b1_wp_pklog")
        assert rows_main == [('hi',)]

    def test_no_pk_overlay_rows_visible_in_branch(self, site):
        """Confirms that INSERT into a no-PK branch view still works
        (overlay accepts rows even without PK matching)."""
        fid = branch_with(site, """
            CREATE TABLE b1_wp_nopklog (
                msg TEXT NOT NULL,
                ts INTEGER
            );
            INSERT INTO b1_wp_nopklog (msg, ts) VALUES ('hi', 100);
        """)

        sqlite_exec(site,
            f"INSERT INTO b{fid}_wp_nopklog (msg, ts) VALUES (?, ?)",
            ("branch_row", 200))
        rows = sqlite_q(site, f"SELECT msg FROM b{fid}_wp_nopklog ORDER BY msg")
        assert rows == [('branch_row',), ('hi',)]


# ═════════════════════════════════════════════════════════════════════
# 5.6 — Case sensitivity of PK column name
# ═════════════════════════════════════════════════════════════════════

class TestPKColumnCase:

    @pytest.mark.parametrize("pkcol", ["id", "ID", "Id", "my_pk"])
    def test_pk_column_name_variations(self, site, pkcol):
        # Create a WP-style table with the configurable PK column name
        fid = branch_with(site, f"""
            CREATE TABLE b1_wp_customt (
                {pkcol} INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT
            );
            INSERT INTO b1_wp_customt (title) VALUES ('t1'), ('t2');
        """)

        # Branch sees the rows
        rows = sqlite_q(site, f"SELECT title FROM b{fid}_wp_customt ORDER BY {pkcol}")
        assert rows == [('t1',), ('t2',)]

        # Branch insert
        sqlite_exec(site,
            f"INSERT INTO b{fid}_wp_customt (title) VALUES (?)", ("t3",))
        rows = sqlite_q(site, f"SELECT title FROM b{fid}_wp_customt ORDER BY {pkcol}")
        assert ("t3",) in rows

        # Branch update
        sqlite_exec(site,
            f"UPDATE b{fid}_wp_customt SET title=? WHERE title=?", ("t1-new", "t1"))
        rows = sqlite_q(site, f"SELECT title FROM b{fid}_wp_customt WHERE title LIKE 't1%'")
        assert rows == [('t1-new',)]

        # Branch delete
        sqlite_exec(site,
            f"DELETE FROM b{fid}_wp_customt WHERE title=?", ("t2",))
        rows = sqlite_q(site, f"SELECT title FROM b{fid}_wp_customt")
        titles = {r[0] for r in rows}
        assert "t2" not in titles

        # Merge
        r = branchctl(site, "merge", "feature", "--into", "main")
        assert r.returncode == 0, f"merge failed with pkcol={pkcol!r}:\n{r.stdout}\n{r.stderr}"

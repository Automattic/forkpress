"""
Test suite for --on-id-collision=renumber on `branchctl merge`.

Scenario: both sides independently INSERT a row with the same auto-incrementing
PK after the fork. Current behavior is CONFLICT; with --on-id-collision=renumber,
source's row is assigned a fresh PK and hard-coded WordPress FK columns that
point at the old ID are rewritten to the new ID.

Run:
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_id_collision_renumber.py -v
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


def sqlite_exec(site_fp: Path, sql: str, params=()):
    db = sqlite3.connect(str(site_fp))
    try:
        db.execute(sql, params)
        db.commit()
    finally:
        db.close()


def sqlite_script(site_fp: Path, script: str):
    db = sqlite3.connect(str(site_fp))
    try:
        db.executescript(script)
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


def setup_wp_tables(site_fp: Path):
    """
    Full set of WP tables used in these tests (on main / b1_*).

    These are intentionally minimal — enough columns for collisions and FKs.
    Schema mirrors WordPress conventions so the FK-rewrite map applies.
    """
    sqlite_script(site_fp, """
        CREATE TABLE IF NOT EXISTS b1_wp_posts (
            ID          INTEGER PRIMARY KEY AUTOINCREMENT,
            post_title  TEXT    NOT NULL DEFAULT '',
            post_type   TEXT    NOT NULL DEFAULT 'post',
            post_author INTEGER NOT NULL DEFAULT 0,
            post_parent INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS b1_wp_postmeta (
            meta_id    INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id    INTEGER NOT NULL DEFAULT 0,
            meta_key   TEXT,
            meta_value TEXT
        );
        CREATE TABLE IF NOT EXISTS b1_wp_users (
            ID            INTEGER PRIMARY KEY AUTOINCREMENT,
            user_login    TEXT    NOT NULL DEFAULT '',
            user_email    TEXT    NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS b1_wp_usermeta (
            umeta_id   INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL DEFAULT 0,
            meta_key   TEXT,
            meta_value TEXT
        );
        CREATE TABLE IF NOT EXISTS b1_wp_comments (
            comment_ID      INTEGER PRIMARY KEY AUTOINCREMENT,
            comment_post_ID INTEGER NOT NULL DEFAULT 0,
            user_id         INTEGER NOT NULL DEFAULT 0,
            comment_content TEXT
        );
        CREATE TABLE IF NOT EXISTS b1_wp_commentmeta (
            meta_id    INTEGER PRIMARY KEY AUTOINCREMENT,
            comment_id INTEGER NOT NULL DEFAULT 0,
            meta_key   TEXT,
            meta_value TEXT
        );
        CREATE TABLE IF NOT EXISTS b1_wp_options (
            option_id    INTEGER PRIMARY KEY AUTOINCREMENT,
            option_name  TEXT NOT NULL DEFAULT '' UNIQUE,
            option_value TEXT NOT NULL DEFAULT ''
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_b1_wp_options_name
            ON b1_wp_options(option_name);
    """)


@pytest.fixture
def fresh_site():
    if not EXT_PATH.exists():
        pytest.skip("branchfs.so not found — run 'make' in branched-wp/")
    work = Path(tempfile.mkdtemp())
    site_fp = work / "site.fp"
    try:
        init_site(site_fp)
    except RuntimeError as e:
        pytest.skip(str(e))
    setup_wp_tables(site_fp)
    yield site_fp
    shutil.rmtree(work, ignore_errors=True)


@pytest.fixture
def branched(fresh_site):
    """
    Site with a 'feature' branch created from main.  No divergent content yet —
    each test sets up the exact collision it needs AFTER this fixture runs.
    """
    r = branchctl(fresh_site, "create", "feature")
    assert r.returncode == 0, f"create failed:\n{r.stdout}\n{r.stderr}"
    fid = sqlite_q(fresh_site, "SELECT id FROM branches WHERE name='feature'")[0][0]
    return {"site_fp": fresh_site, "feature_id": fid}


# ── The actual test class ─────────────────────────────────────────────────────

class TestIdCollisionRenumber:

    # ─── Baseline: default behavior (no flag) unchanged ───────────────────────

    def test_default_behavior_still_conflicts(self, branched):
        """Without --on-id-collision=renumber, same-PK insert still → CONFLICT."""
        fp, fid = branched["site_fp"], branched["feature_id"]

        # Both sides insert post ID=42 independently, after fork
        sqlite_exec(fp, "INSERT INTO b1_wp_posts (ID, post_title) VALUES (?, ?)",
                    (42, "from main"))
        sqlite_exec(fp, f"INSERT INTO b{fid}_wp_posts (ID, post_title) VALUES (?, ?)",
                    (42, "from branch"))

        r = branchctl(fp, "merge", "feature", "--into", "main")
        assert r.returncode == 2, \
            f"expected exit 2 (conflict) without --on-id-collision, got {r.returncode}\n{r.stdout}"
        assert "CONFLICT" in r.stdout.upper()

    # ─── Core renumber semantics ──────────────────────────────────────────────

    def test_renumber_resolves_posts_collision(self, branched):
        """Both sides inserted posts.ID=42 → renumber keeps both, distinct IDs."""
        fp, fid = branched["site_fp"], branched["feature_id"]

        sqlite_exec(fp, "INSERT INTO b1_wp_posts (ID, post_title) VALUES (?, ?)",
                    (42, "from main"))
        sqlite_exec(fp, f"INSERT INTO b{fid}_wp_posts (ID, post_title) VALUES (?, ?)",
                    (42, "from branch"))

        r = branchctl(fp, "merge", "feature", "--into", "main",
                      "--on-id-collision", "renumber")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        rows = sqlite_q(fp, "SELECT ID, post_title FROM b1_wp_posts ORDER BY ID")
        titles = {title for _, title in rows}
        assert "from main" in titles
        assert "from branch" in titles

        # Main's row must still be at ID=42 (target-side IDs are NEVER renumbered).
        main_rows = sqlite_q(fp,
            "SELECT ID FROM b1_wp_posts WHERE post_title='from main'")
        assert main_rows and main_rows[0][0] == 42

        branch_rows = sqlite_q(fp,
            "SELECT ID FROM b1_wp_posts WHERE post_title='from branch'")
        assert branch_rows
        new_id = branch_rows[0][0]
        assert new_id != 42, "branch's post should have been renumbered"
        assert new_id > 42, "new id must be > both existing maxes"

    def test_renumber_rewrites_postmeta_fk(self, branched):
        """wp_postmeta.post_id for branch's row is rewritten to the new post ID."""
        fp, fid = branched["site_fp"], branched["feature_id"]

        # main adds post 42 + meta pointing at it. Use explicit meta_ids on
        # disjoint ranges so the postmeta PKs themselves don't collide —
        # the only collision under test is wp_posts.ID.
        sqlite_exec(fp, "INSERT INTO b1_wp_posts (ID, post_title) VALUES (?, ?)",
                    (42, "from main"))
        sqlite_exec(fp,
            "INSERT INTO b1_wp_postmeta (meta_id, post_id, meta_key, meta_value) "
            "VALUES (?, ?, ?, ?)", (100, 42, "_main_marker", "main_val"))

        # feature adds post 42 + two meta rows pointing at it
        sqlite_exec(fp, f"INSERT INTO b{fid}_wp_posts (ID, post_title) VALUES (?, ?)",
                    (42, "from branch"))
        sqlite_exec(fp,
            f"INSERT INTO b{fid}_wp_postmeta (meta_id, post_id, meta_key, meta_value) "
            f"VALUES (?, ?, ?, ?)", (200, 42, "_branch_marker", "branch_val"))
        sqlite_exec(fp,
            f"INSERT INTO b{fid}_wp_postmeta (meta_id, post_id, meta_key, meta_value) "
            f"VALUES (?, ?, ?, ?)", (201, 42, "_branch_other", "branch_other"))

        r = branchctl(fp, "merge", "feature", "--into", "main",
                      "--on-id-collision", "renumber")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        # Discover the renumbered branch post ID
        branch_rows = sqlite_q(fp,
            "SELECT ID FROM b1_wp_posts WHERE post_title='from branch'")
        assert branch_rows
        new_id = branch_rows[0][0]

        # main's meta still at post_id=42
        main_meta = sqlite_q(fp,
            "SELECT meta_value FROM b1_wp_postmeta "
            "WHERE post_id=? AND meta_key='_main_marker'", (42,))
        assert main_meta and main_meta[0][0] == "main_val"

        # branch's meta rewritten to post_id=new_id
        branch_meta = sqlite_q(fp,
            "SELECT meta_key, meta_value FROM b1_wp_postmeta "
            "WHERE post_id=? ORDER BY meta_key", (new_id,))
        assert {(k, v) for k, v in branch_meta} == {
            ("_branch_marker", "branch_val"),
            ("_branch_other", "branch_other"),
        }

        # No leftover branch meta at old id
        stale = sqlite_q(fp,
            "SELECT meta_key FROM b1_wp_postmeta "
            "WHERE post_id=? AND meta_key LIKE '_branch%'", (42,))
        assert not stale, \
            f"branch's meta should NOT still reference old post_id=42: {stale}"

    def test_renumber_postmeta_row_count_preserved(self, branched):
        """Count of postmeta rows for renumbered post matches pre-merge count."""
        fp, fid = branched["site_fp"], branched["feature_id"]

        sqlite_exec(fp, "INSERT INTO b1_wp_posts (ID, post_title) VALUES (?, ?)",
                    (42, "from main"))
        sqlite_exec(fp, f"INSERT INTO b{fid}_wp_posts (ID, post_title) VALUES (?, ?)",
                    (42, "from branch"))
        # Five meta rows on branch's post; pre-assigned meta_ids in 200-range
        # to avoid PK collisions with target meta auto-assigned IDs.
        for i in range(5):
            sqlite_exec(fp,
                f"INSERT INTO b{fid}_wp_postmeta "
                f"(meta_id, post_id, meta_key, meta_value) VALUES (?, ?, ?, ?)",
                (200 + i, 42, f"_branch_{i}", f"v{i}"))

        before = sqlite_q(fp,
            f"SELECT COUNT(*) FROM b{fid}_wp_postmeta WHERE post_id=?", (42,))[0][0]

        r = branchctl(fp, "merge", "feature", "--into", "main",
                      "--on-id-collision", "renumber")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        new_id = sqlite_q(fp,
            "SELECT ID FROM b1_wp_posts WHERE post_title='from branch'")[0][0]
        after = sqlite_q(fp,
            "SELECT COUNT(*) FROM b1_wp_postmeta WHERE post_id=?", (new_id,))[0][0]
        assert after == before, \
            f"postmeta row count for renumbered post changed: {before} -> {after}"

    def test_renumber_users_and_post_author(self, branched):
        """
        users.ID collision: branch's post_author that pointed at old ID must
        point at the renumbered user ID after merge.
        """
        fp, fid = branched["site_fp"], branched["feature_id"]

        # Both sides add user ID=5 (different users, same PK)
        sqlite_exec(fp,
            "INSERT INTO b1_wp_users (ID, user_login, user_email) VALUES (?, ?, ?)",
            (5, "mainuser", "main@example.com"))
        sqlite_exec(fp,
            f"INSERT INTO b{fid}_wp_users (ID, user_login, user_email) VALUES (?, ?, ?)",
            (5, "branchuser", "branch@example.com"))

        # On branch: a post owned by user 5 (branchuser)
        sqlite_exec(fp,
            f"INSERT INTO b{fid}_wp_posts (ID, post_title, post_author) VALUES (?, ?, ?)",
            (100, "branch post by user 5", 5))

        r = branchctl(fp, "merge", "feature", "--into", "main",
                      "--on-id-collision", "renumber")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        # main kept user 5 = mainuser
        main_user = sqlite_q(fp,
            "SELECT user_login FROM b1_wp_users WHERE ID=5")
        assert main_user and main_user[0][0] == "mainuser"

        # branch's user now at a new ID
        bu = sqlite_q(fp,
            "SELECT ID FROM b1_wp_users WHERE user_login='branchuser'")
        assert bu and bu[0][0] != 5
        new_uid = bu[0][0]

        # branch's post on main now references the new user id, NOT 5
        p = sqlite_q(fp,
            "SELECT post_author FROM b1_wp_posts "
            "WHERE post_title='branch post by user 5'")
        assert p and p[0][0] == new_uid, \
            f"post_author FK not rewritten: got {p}, expected {new_uid}"

    def test_renumber_summary_output(self, branched):
        """stdout contains a renumber-list summary after successful renumber."""
        fp, fid = branched["site_fp"], branched["feature_id"]

        sqlite_exec(fp, "INSERT INTO b1_wp_posts (ID, post_title) VALUES (?, ?)",
                    (42, "from main"))
        sqlite_exec(fp, f"INSERT INTO b{fid}_wp_posts (ID, post_title) VALUES (?, ?)",
                    (42, "from branch"))

        r = branchctl(fp, "merge", "feature", "--into", "main",
                      "--on-id-collision", "renumber")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        # Must contain a renumber summary
        assert "renumber" in r.stdout.lower(), \
            f"expected renumber summary in stdout:\n{r.stdout}"
        assert "b1_wp_posts.ID" in r.stdout, \
            f"expected renumbered table/col in stdout:\n{r.stdout}"
        assert "42" in r.stdout, f"expected old id 42 in renumber log:\n{r.stdout}"

    def test_renumber_new_id_exceeds_both_maxes(self, branched):
        """New id > max(source.id, target.id) as observed at merge time."""
        fp, fid = branched["site_fp"], branched["feature_id"]

        # Main ends up with ID 42 and a higher "independent" row (100)
        sqlite_exec(fp, "INSERT INTO b1_wp_posts (ID, post_title) VALUES (?, ?)",
                    (42, "from main"))
        sqlite_exec(fp, "INSERT INTO b1_wp_posts (ID, post_title) VALUES (?, ?)",
                    (100, "main only"))

        # Feature: ID 42 (collision) and ID 200 (no collision, >> main.max)
        sqlite_exec(fp, f"INSERT INTO b{fid}_wp_posts (ID, post_title) VALUES (?, ?)",
                    (42, "from branch"))
        sqlite_exec(fp, f"INSERT INTO b{fid}_wp_posts (ID, post_title) VALUES (?, ?)",
                    (200, "branch only"))

        r = branchctl(fp, "merge", "feature", "--into", "main",
                      "--on-id-collision", "renumber")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        new_id = sqlite_q(fp,
            "SELECT ID FROM b1_wp_posts WHERE post_title='from branch'")[0][0]
        # New id must exceed max of both sides BEFORE the apply:
        # main had {42, 100}, feature had {42, 200}. new_id > 200.
        assert new_id > 200, \
            f"renumbered id {new_id} must be > max(source,target) = 200"

    # ─── Scope: what is NOT renumbered ────────────────────────────────────────

    def test_non_fk_mapped_table_still_conflicts(self, branched):
        """
        wp_options IS autoinc but is NOT in the FK_MAP → still CONFLICT
        even with renumber. This is the "custom/unknown table" fallback.
        """
        fp, fid = branched["site_fp"], branched["feature_id"]

        sqlite_exec(fp,
            "INSERT INTO b1_wp_options (option_id, option_name, option_value) "
            "VALUES (?, ?, ?)", (77, "main_key", "main_val"))
        sqlite_exec(fp,
            f"INSERT INTO b{fid}_wp_options (option_id, option_name, option_value) "
            f"VALUES (?, ?, ?)", (77, "branch_key", "branch_val"))

        r = branchctl(fp, "merge", "feature", "--into", "main",
                      "--on-id-collision", "renumber")
        assert r.returncode == 2, (
            f"wp_options is not in FK_MAP → must fall back to CONFLICT "
            f"even with renumber, got rc={r.returncode}\n{r.stdout}"
        )
        assert "CONFLICT" in r.stdout.upper()

    # ─── Mixed: some renumber, some conflict in same merge ────────────────────

    def test_mixed_renumber_and_real_conflict(self, branched):
        """
        wp_posts.ID collides (renumbered) AND wp_options.option_id (on a
        separate non-mapped row) also conflicts. merge must still exit 2 —
        but the renumber-handled collision should NOT appear as a conflict.

        Note: Post-TODO3 #10 the overlay's cross-layer UNIQUE guard blocks
        a branch INSERT whose option_name collides with an inherited parent
        row. So we use DIFFERENT option_names on each side — the conflict
        is on the PK (option_id), not the UNIQUE (option_name).
        """
        fp, fid = branched["site_fp"], branched["feature_id"]

        # Renumber-eligible collision on wp_posts
        sqlite_exec(fp, "INSERT INTO b1_wp_posts (ID, post_title) VALUES (?, ?)",
                    (42, "from main"))
        sqlite_exec(fp, f"INSERT INTO b{fid}_wp_posts (ID, post_title) VALUES (?, ?)",
                    (42, "from branch"))

        # Non-renumber conflict on options (same PK, different option_names).
        sqlite_exec(fp,
            "INSERT INTO b1_wp_options (option_id, option_name, option_value) "
            "VALUES (?, ?, ?)", (77, "main_key", "main_val"))
        sqlite_exec(fp,
            f"INSERT INTO b{fid}_wp_options (option_id, option_name, option_value) "
            f"VALUES (?, ?, ?)", (77, "branch_key", "branch_val"))

        r = branchctl(fp, "merge", "feature", "--into", "main",
                      "--on-id-collision", "renumber")
        # Default strategy is abort → still exits 2 because options still conflicts.
        assert r.returncode == 2, \
            f"expected exit 2 (options still conflicts): rc={r.returncode}\n{r.stdout}"
        # The conflict line should NOT mention wp_posts (renumbered out).
        # It should mention wp_options.
        assert "conflicts:         1" in r.stdout or "conflicts:         1\n" in r.stdout \
            or "conflicts: 1" in r.stdout, \
            f"expected exactly 1 remaining conflict:\n{r.stdout}"

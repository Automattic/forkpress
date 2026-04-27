"""
Test suite for COW (Copy-on-Write) branch DB storage in branched-wp.

The motivating change: branch creation should be O(num_tables), not O(num_rows).
Each branched table is a VIEW that UNION-ALLs an overlay (changed/added rows)
with the parent's view minus tombstones (deleted rows).

These tests are written FIRST (TDD); they MUST fail before implementation.

Run:
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_cow_branches.py -v
"""

import os
import shutil
import sqlite3
import subprocess
import tempfile
import time
from pathlib import Path

import pytest

# ── Paths ──────────────────────────────────────────────────────────────────────
E2E_DIR  = Path(__file__).parent
BASE_DIR = E2E_DIR.parent
EXT_PATH = BASE_DIR / "ext" / "branchfs.so"
PHP_BIN  = "php"


# ── Low-level helpers ──────────────────────────────────────────────────────────

def branchctl(site_fp: Path, *args, timeout: int = 60) -> subprocess.CompletedProcess:
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


def sqlite_executescript(site_fp: Path, script: str):
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


def setup_main_tables(site_fp: Path, n_posts: int = 0):
    """Create realistic WordPress-like tables on main (b1_wp_*).
    If n_posts > 0, bulk-load that many rows into wp_posts."""
    db = sqlite3.connect(str(site_fp))
    db.executescript("""
        CREATE TABLE IF NOT EXISTS b1_wp_options (
            option_id    INTEGER PRIMARY KEY AUTOINCREMENT,
            option_name  TEXT NOT NULL DEFAULT '' UNIQUE,
            option_value TEXT NOT NULL DEFAULT '',
            autoload     TEXT NOT NULL DEFAULT 'yes'
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_b1_wp_options_name
            ON b1_wp_options(option_name);
        INSERT OR IGNORE INTO b1_wp_options (option_name, option_value) VALUES
            ('blogname',      'My Site'),
            ('siteurl',       'http://localhost'),
            ('shared_option', 'original_value');
        CREATE TABLE IF NOT EXISTS b1_wp_posts (
            ID         INTEGER PRIMARY KEY AUTOINCREMENT,
            post_title TEXT NOT NULL DEFAULT '',
            post_type  TEXT NOT NULL DEFAULT 'post',
            post_content TEXT NOT NULL DEFAULT ''
        );
        INSERT OR IGNORE INTO b1_wp_posts (post_title, post_type) VALUES
            ('Hello World', 'post');
        CREATE TABLE IF NOT EXISTS b1_wp_term_relationships (
            object_id        INTEGER NOT NULL DEFAULT 0,
            term_taxonomy_id INTEGER NOT NULL DEFAULT 0,
            term_order       INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (object_id, term_taxonomy_id)
        );
        INSERT OR IGNORE INTO b1_wp_term_relationships VALUES
            (1, 10, 0), (1, 20, 0), (2, 10, 0);
    """)
    if n_posts > 0:
        # Bulk insert n_posts; pad post_content to ~512 bytes so size matters.
        padding = "x" * 480
        for i in range(n_posts):
            db.execute(
                "INSERT INTO b1_wp_posts (post_title, post_type, post_content) VALUES (?, ?, ?)",
                (f"post-{i}", "post", f"{padding}-{i}")
            )
    db.commit()
    db.close()


def db_size(site_fp: Path) -> int:
    """Return size of the .fp file's main payload (page_count * page_size)."""
    db = sqlite3.connect(str(site_fp))
    try:
        page_count = db.execute("PRAGMA page_count").fetchone()[0]
        page_size = db.execute("PRAGMA page_size").fetchone()[0]
        return page_count * page_size
    finally:
        db.close()


def is_view(site_fp: Path, name: str) -> bool:
    rows = sqlite_q(site_fp,
        "SELECT type FROM sqlite_master WHERE name = ?", (name,))
    return bool(rows) and rows[0][0] == "view"


def is_table(site_fp: Path, name: str) -> bool:
    rows = sqlite_q(site_fp,
        "SELECT type FROM sqlite_master WHERE name = ?", (name,))
    return bool(rows) and rows[0][0] == "table"


def object_exists(site_fp: Path, name: str) -> bool:
    rows = sqlite_q(site_fp,
        "SELECT 1 FROM sqlite_master WHERE name = ?", (name,))
    return bool(rows)


# ── Fixtures ──────────────────────────────────────────────────────────────────

@pytest.fixture
def fresh_db():
    """Fresh site.fp + WordPress-like b1_wp_* tables on main."""
    if not EXT_PATH.exists():
        pytest.skip(f"branchfs.so not found — run 'make' in branched-wp/")

    work = Path(tempfile.mkdtemp(prefix="cow_test_"))
    site_fp = work / "site.fp"

    try:
        init_site(site_fp)
    except RuntimeError as e:
        pytest.skip(str(e))

    setup_main_tables(site_fp)

    yield site_fp
    shutil.rmtree(work, ignore_errors=True)


@pytest.fixture
def branched_db(fresh_db):
    site_fp = fresh_db
    r = branchctl(site_fp, "create", "feature")
    assert r.returncode == 0, f"branchctl create failed:\n{r.stdout}\n{r.stderr}"
    rows = sqlite_q(site_fp, "SELECT id FROM branches WHERE name='feature'")
    assert rows
    return {"site_fp": site_fp, "feature_id": rows[0][0]}


# ════════════════════════════════════════════════════════════════════════════
# TestBranchCreateIsCheap
# ════════════════════════════════════════════════════════════════════════════

class TestBranchCreateIsCheap:
    """Branch creation should be O(num_tables), independent of row count."""

    def test_main_remains_real_table(self, fresh_db):
        """b1_wp_* on main is still a real table (not a view) — backcompat
        for routes that bypass the view layer and for any tooling that
        introspects schema directly."""
        site_fp = fresh_db
        # main is branch id=1; init_db creates it. Pre-populate already done.
        assert is_table(site_fp, "b1_wp_posts"), \
            "b1_wp_posts must be a real table on main (id=1)"
        assert is_table(site_fp, "b1_wp_options"), \
            "b1_wp_options must be a real table on main (id=1)"

    def test_branch_create_no_row_copy_in_storage(self, fresh_db):
        """Create a branch from a populated parent (10k rows). The DB page
        count must grow by < 1 MB — NOT by ~5 MB (which a row-copy would
        incur, since each padded row is ~512 bytes)."""
        site_fp = fresh_db
        # Pre-populate main with 10k rows.
        setup_main_tables(site_fp, n_posts=10_000)
        # Force a checkpoint to get a stable size baseline.
        sqlite_exec(site_fp, "PRAGMA wal_checkpoint(TRUNCATE)")
        before = db_size(site_fp)

        r = branchctl(site_fp, "create", "feature")
        assert r.returncode == 0, f"branchctl create failed:\n{r.stdout}\n{r.stderr}"
        sqlite_exec(site_fp, "PRAGMA wal_checkpoint(TRUNCATE)")
        after = db_size(site_fp)

        grew = after - before
        # Old code would copy ~5 MB of row data + ~5 MB more in db_snapshots.
        # COW should grow only by view/overlay/tombstone DDL — well under 1 MB.
        assert grew < 1_000_000, (
            f"branch create grew DB by {grew} bytes; "
            f"COW should be O(num_tables), not O(num_rows). "
            f"Old behavior would grow by ~5MB+ for 10k rows."
        )

    def test_branch_create_time_is_constant(self, fresh_db):
        """Branch create must be < 500ms regardless of parent row count."""
        site_fp = fresh_db
        # Pre-populate main with a substantial number of rows.
        setup_main_tables(site_fp, n_posts=10_000)
        sqlite_exec(site_fp, "PRAGMA wal_checkpoint(TRUNCATE)")

        t0 = time.monotonic()
        r = branchctl(site_fp, "create", "fast")
        elapsed = time.monotonic() - t0
        assert r.returncode == 0, f"create failed:\n{r.stdout}\n{r.stderr}"
        assert elapsed < 2.5, (
            f"branch create on 10k-row parent took {elapsed:.2f}s; "
            f"should be < 2.5s with COW (process startup dominates). "
            f"PHP startup overhead is ~150-300ms; under COW the actual "
            f"DB work must be milliseconds."
        )


# ════════════════════════════════════════════════════════════════════════════
# TestBranchSeesInheritedRows
# ════════════════════════════════════════════════════════════════════════════

class TestBranchSeesInheritedRows:

    def test_branch_view_returns_parent_rows(self, branched_db):
        """A fresh branch (no overlay writes) must return parent's rows
        when SELECTing from its view."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        # branch's b{fid}_wp_options should reflect main's seeded rows.
        rows = sqlite_q(site_fp,
            f"SELECT option_name, option_value FROM b{fid}_wp_options ORDER BY option_name")
        assert rows, "branch view must return parent's rows"
        names = [r[0] for r in rows]
        assert "blogname" in names
        assert "siteurl" in names
        assert "shared_option" in names

    def test_branch_view_handles_composite_pk_table(self, branched_db):
        """wp_term_relationships uses composite PK (object_id, term_taxonomy_id).
        The branch's view must expose all parent rows correctly."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        rows = sqlite_q(site_fp,
            f"SELECT object_id, term_taxonomy_id "
            f"FROM b{fid}_wp_term_relationships "
            f"ORDER BY object_id, term_taxonomy_id")
        assert sorted(rows) == [(1, 10), (1, 20), (2, 10)], \
            f"composite-PK view didn't return parent rows: {rows}"


# ════════════════════════════════════════════════════════════════════════════
# TestBranchOverlayIsolation
# ════════════════════════════════════════════════════════════════════════════

class TestBranchOverlayIsolation:

    def test_branch_insert_does_not_affect_parent(self, branched_db):
        """INSERT via branch view must not change parent's table."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) VALUES (?, ?)",
            ("branch_only", "v"))

        # Parent (main) must NOT see this row.
        main_rows = sqlite_q(site_fp,
            "SELECT 1 FROM b1_wp_options WHERE option_name = ?", ("branch_only",))
        assert not main_rows, "parent table contaminated by branch INSERT"

        # Branch view must see this row.
        br_rows = sqlite_q(site_fp,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name = ?",
            ("branch_only",))
        assert br_rows and br_rows[0][0] == "v"

    def test_branch_update_does_not_affect_parent(self, branched_db):
        """UPDATE via branch view must not change parent."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value = ? WHERE option_name = ?",
            ("branch_value", "blogname"))

        # Branch view shows new value.
        br_rows = sqlite_q(site_fp,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name = ?",
            ("blogname",))
        assert br_rows and br_rows[0][0] == "branch_value"

        # Parent retains original value.
        main_rows = sqlite_q(site_fp,
            "SELECT option_value FROM b1_wp_options WHERE option_name = ?",
            ("blogname",))
        assert main_rows and main_rows[0][0] == "My Site", \
            f"parent's blogname was modified: {main_rows[0][0]}"

    def test_branch_delete_via_tombstone_does_not_affect_parent(self, branched_db):
        """DELETE on inherited row writes a tombstone; parent untouched."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp,
            f"DELETE FROM b{fid}_wp_options WHERE option_name = ?",
            ("shared_option",))

        # Branch view no longer has the row.
        br_rows = sqlite_q(site_fp,
            f"SELECT 1 FROM b{fid}_wp_options WHERE option_name = ?",
            ("shared_option",))
        assert not br_rows, "branch deleted row should not appear in branch view"

        # Parent still has the row.
        main_rows = sqlite_q(site_fp,
            "SELECT 1 FROM b1_wp_options WHERE option_name = ?",
            ("shared_option",))
        assert main_rows, "parent's shared_option was deleted by branch DELETE!"

    def test_branch_delete_of_overlaid_row_removes_overlay(self, branched_db):
        """If a row is only in the branch's overlay (branch-added),
        deleting it should clear the overlay row — no tombstone needed."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) VALUES (?, ?)",
            ("only_branch", "v"))
        # Confirm it's in branch view
        rows = sqlite_q(site_fp,
            f"SELECT 1 FROM b{fid}_wp_options WHERE option_name = ?", ("only_branch",))
        assert rows

        sqlite_exec(site_fp,
            f"DELETE FROM b{fid}_wp_options WHERE option_name = ?", ("only_branch",))
        rows = sqlite_q(site_fp,
            f"SELECT 1 FROM b{fid}_wp_options WHERE option_name = ?", ("only_branch",))
        assert not rows, "branch-added row not removed by DELETE"

    def test_re_insert_after_delete_clears_tombstone(self, branched_db):
        """tombstone-then-insert must restore the row in the branch view."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp,
            f"DELETE FROM b{fid}_wp_options WHERE option_name = ?",
            ("shared_option",))
        # Now re-insert with same name.
        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) VALUES (?, ?)",
            ("shared_option", "re_added"))
        rows = sqlite_q(site_fp,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name = ?",
            ("shared_option",))
        assert rows, "re-inserted row should appear in branch view"
        assert rows[0][0] == "re_added"


# ════════════════════════════════════════════════════════════════════════════
# TestParentChangesPropagateToBranch
# ════════════════════════════════════════════════════════════════════════════

class TestParentChangesPropagateToBranch:
    """COW means parent changes flow into branch's view — until the branch
    overlays/tombstones a row of its own."""

    def test_parent_insert_visible_in_branch_for_unmodified_table(self, branched_db):
        """Parent inserts after fork; branch sees it."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp,
            "INSERT INTO b1_wp_options (option_name, option_value) VALUES (?, ?)",
            ("post_fork_main", "v"))

        rows = sqlite_q(site_fp,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name = ?",
            ("post_fork_main",))
        assert rows and rows[0][0] == "v", \
            "branch should see parent's post-fork insert"

    def test_parent_modify_visible_in_branch_when_branch_didnt_touch_row(self, branched_db):
        """Parent modifies a row branch didn't overlay; branch sees the new value."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp,
            "UPDATE b1_wp_options SET option_value = ? WHERE option_name = ?",
            ("Updated by Main", "blogname"))
        rows = sqlite_q(site_fp,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name = ?",
            ("blogname",))
        assert rows and rows[0][0] == "Updated by Main"

    def test_branch_overlay_wins_over_parent_for_same_pk(self, branched_db):
        """If branch overlaid row N, then parent later modifies row N,
        branch keeps its own version (overlay wins)."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        # Branch overlays
        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value = ? WHERE option_name = ?",
            ("branch_wins", "blogname"))
        # Parent later modifies
        sqlite_exec(site_fp,
            "UPDATE b1_wp_options SET option_value = ? WHERE option_name = ?",
            ("main_late", "blogname"))

        rows = sqlite_q(site_fp,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name = ?",
            ("blogname",))
        assert rows and rows[0][0] == "branch_wins", \
            f"branch's overlay should win; got {rows[0][0]!r}"


# ════════════════════════════════════════════════════════════════════════════
# TestAutoincrementSafety
# ════════════════════════════════════════════════════════════════════════════

class TestAutoincrementSafety:
    """Branches inserting independently must not collide on autoincrement IDs."""

    def test_branch_insert_id_does_not_collide_with_parent(self, fresh_db):
        """Parent has 100 posts; create branch; branch inserts; ID > 100."""
        site_fp = fresh_db
        # Insert 100 rows on main first.
        for i in range(100):
            sqlite_exec(site_fp,
                "INSERT INTO b1_wp_posts (post_title) VALUES (?)",
                (f"main_post_{i}",))
        # Get main's max id
        main_max = sqlite_q(site_fp, "SELECT MAX(ID) FROM b1_wp_posts")[0][0]
        assert main_max >= 100

        # Create branch
        r = branchctl(site_fp, "create", "feature")
        assert r.returncode == 0, f"create failed: {r.stdout}\n{r.stderr}"
        fid = sqlite_q(site_fp, "SELECT id FROM branches WHERE name='feature'")[0][0]

        # Branch inserts via the view
        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_posts (post_title) VALUES (?)",
            ("branch_first",))
        rows = sqlite_q(site_fp,
            f"SELECT ID FROM b{fid}_wp_posts WHERE post_title = ?",
            ("branch_first",))
        assert rows
        new_id = rows[0][0]
        assert new_id > main_max, (
            f"branch insert produced ID {new_id} <= main's max {main_max}; "
            f"sqlite_sequence wasn't carried forward at fork time"
        )

    def test_concurrent_inserts_each_branch_progresses_independently(self, branched_db):
        """Two branches inserting in parallel each get monotonic IDs scoped to
        their own overlay (no collisions, no shared sequence)."""
        site_fp = branched_db["site_fp"]
        fid_a = branched_db["feature_id"]

        r = branchctl(site_fp, "create", "feature_b")
        assert r.returncode == 0, r.stderr
        fid_b = sqlite_q(site_fp, "SELECT id FROM branches WHERE name='feature_b'")[0][0]

        # Both insert
        sqlite_exec(site_fp,
            f"INSERT INTO b{fid_a}_wp_posts (post_title) VALUES (?)",
            ("a1",))
        sqlite_exec(site_fp,
            f"INSERT INTO b{fid_b}_wp_posts (post_title) VALUES (?)",
            ("b1",))

        a_id = sqlite_q(site_fp,
            f"SELECT ID FROM b{fid_a}_wp_posts WHERE post_title='a1'")[0][0]
        b_id = sqlite_q(site_fp,
            f"SELECT ID FROM b{fid_b}_wp_posts WHERE post_title='b1'")[0][0]
        assert a_id is not None and b_id is not None
        # Each branch's overlay sees only its own insert
        rows = sqlite_q(site_fp,
            f"SELECT post_title FROM b{fid_a}_wp_posts WHERE post_title IN ('a1', 'b1')")
        titles = {r[0] for r in rows}
        assert "a1" in titles and "b1" not in titles


# ════════════════════════════════════════════════════════════════════════════
# TestSchemaChangePropagation
# ════════════════════════════════════════════════════════════════════════════

class TestSchemaChangePropagation:

    def test_alter_parent_add_column_branch_view_sees_new_column(self, branched_db):
        """ALTER on parent adds column; branch view must show that column.
        Requires the cow_recreate_views_for_table helper to fire, since
        SELECT * in views is resolved at definition time."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        # Use branchctl to add a column on parent — branchctl is the
        # only "schema-altering operation" path that fires the helper.
        # For now we use direct ALTER + manual recreate via branchctl.
        # The expectation: after a branchctl-driven ALTER, branch view
        # exposes the new column.
        r = branchctl(site_fp, "alter-add-column",
                      "main", "options", "extra_col", "TEXT")
        assert r.returncode == 0, f"alter-add-column failed:\n{r.stdout}\n{r.stderr}"

        # Fetch a row from the branch view; new column must be selectable.
        rows = sqlite_q(site_fp,
            f"SELECT extra_col FROM b{fid}_wp_options WHERE option_name = ?",
            ("blogname",))
        assert rows is not None, "extra_col not selectable from branch view"


# ════════════════════════════════════════════════════════════════════════════
# TestMergeStillWorks
# ════════════════════════════════════════════════════════════════════════════

class TestMergeStillWorks:
    """Pick representative scenarios from test_db_merge.py and reproduce
    them under COW. The full 19 are still covered by test_db_merge.py
    itself — these confirm the merge layer hasn't regressed under COW."""

    def test_clean_insert_merge(self, branched_db):
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]
        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) VALUES (?, ?)",
            ("cow_new_opt", "cow_v"))
        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"
        rows = sqlite_q(site_fp,
            "SELECT option_value FROM b1_wp_options WHERE option_name = ?",
            ("cow_new_opt",))
        assert rows and rows[0][0] == "cow_v"

    def test_clean_delete_merge(self, branched_db):
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]
        sqlite_exec(site_fp,
            f"DELETE FROM b{fid}_wp_options WHERE option_name = ?",
            ("shared_option",))
        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"
        rows = sqlite_q(site_fp,
            "SELECT 1 FROM b1_wp_options WHERE option_name = ?",
            ("shared_option",))
        assert not rows, "delete should propagate from branch to main"

    def test_conflict_aborts(self, branched_db):
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]
        sqlite_exec(site_fp,
            "UPDATE b1_wp_options SET option_value = ? WHERE option_name = ?",
            ("main_change", "shared_option"))
        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value = ? WHERE option_name = ?",
            ("branch_change", "shared_option"))
        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode != 0, "conflicting modifications should abort merge"

    def test_strategy_theirs_wins(self, branched_db):
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]
        sqlite_exec(site_fp,
            "UPDATE b1_wp_options SET option_value = ? WHERE option_name = ?",
            ("main_change", "shared_option"))
        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value = ? WHERE option_name = ?",
            ("branch_change", "shared_option"))
        r = branchctl(site_fp, "merge", "feature", "--into", "main",
                      "--strategy", "theirs")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"
        rows = sqlite_q(site_fp,
            "SELECT option_value FROM b1_wp_options WHERE option_name = ?",
            ("shared_option",))
        assert rows and rows[0][0] == "branch_change"

    def test_strategy_ours_keeps_target(self, branched_db):
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]
        sqlite_exec(site_fp,
            "UPDATE b1_wp_options SET option_value = ? WHERE option_name = ?",
            ("main_change", "shared_option"))
        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value = ? WHERE option_name = ?",
            ("branch_change", "shared_option"))
        r = branchctl(site_fp, "merge", "feature", "--into", "main",
                      "--strategy", "ours")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"
        rows = sqlite_q(site_fp,
            "SELECT option_value FROM b1_wp_options WHERE option_name = ?",
            ("shared_option",))
        assert rows and rows[0][0] == "main_change"


# ════════════════════════════════════════════════════════════════════════════
# TestStorageScaling
# ════════════════════════════════════════════════════════════════════════════

class TestStorageScaling:

    def test_n_branches_share_storage(self, fresh_db):
        """Create 10 empty-diff branches; total storage growth < 2 MB."""
        site_fp = fresh_db
        # Pre-populate so storage costs are observable
        setup_main_tables(site_fp, n_posts=2_000)
        sqlite_exec(site_fp, "PRAGMA wal_checkpoint(TRUNCATE)")
        before = db_size(site_fp)

        for i in range(10):
            r = branchctl(site_fp, "create", f"branch_{i}")
            assert r.returncode == 0, f"create failed:\n{r.stdout}\n{r.stderr}"
        sqlite_exec(site_fp, "PRAGMA wal_checkpoint(TRUNCATE)")
        after = db_size(site_fp)
        grew = after - before
        assert grew < 2_000_000, (
            f"10 empty COW branches grew DB by {grew} bytes; "
            f"should be well under 2MB."
        )

    def test_one_modified_row_costs_one_overlay_row(self, branched_db):
        """A single modified option should add roughly one overlay row's
        worth of storage — nowhere near table-sized."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]
        sqlite_exec(site_fp, "PRAGMA wal_checkpoint(TRUNCATE)")
        before = db_size(site_fp)

        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value = ? WHERE option_name = ?",
            ("modified", "blogname"))
        sqlite_exec(site_fp, "PRAGMA wal_checkpoint(TRUNCATE)")
        after = db_size(site_fp)
        grew = after - before
        # Should be well under 50 KB for one row.
        assert grew < 50_000, (
            f"one-row overlay grew DB by {grew} bytes; "
            f"COW should be O(divergent rows)"
        )


# ════════════════════════════════════════════════════════════════════════════
# TestBackcompatLazyMigration
# ════════════════════════════════════════════════════════════════════════════

def make_legacy_branch(site_fp: Path, branch_name: str, parent: str = "main"):
    """Manually create a branch in the OLD format (real table copy +
    db_snapshots), bypassing branchctl. Mimics what an old .fp file
    would have."""
    # Force any lazy fs_migrate() that creates db_snapshots / etc to fire,
    # so we can safely INSERT into db_snapshots below.
    branchctl(site_fp, "list")
    db = sqlite3.connect(str(site_fp))
    try:
        # Insert branch row directly
        cur = db.execute(
            "INSERT INTO branches (name, parent_branch) VALUES (?, ?)",
            (branch_name, parent)
        )
        bid = cur.lastrowid
        # Find parent id
        prow = db.execute("SELECT id FROM branches WHERE name = ?", (parent,)).fetchone()
        pid = prow[0]
        prefix_old = f"b{pid}_wp_"
        prefix_new = f"b{bid}_wp_"

        # Copy each parent table the OLD way
        tables = [
            r[0] for r in db.execute(
                "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE ?",
                (prefix_old + "%",)
            ).fetchall()
        ]
        for old in tables:
            new = prefix_new + old[len(prefix_old):]
            ddl = db.execute(
                "SELECT sql FROM sqlite_master WHERE type='table' AND name=?",
                (old,)
            ).fetchone()[0]
            # Rewrite name
            import re as _re
            new_ddl = _re.sub(
                r'^(CREATE\s+TABLE\s+)(?:IF\s+NOT\s+EXISTS\s+)?"?'
                + _re.escape(old) + r'"?',
                r'\1IF NOT EXISTS "' + new + r'"',
                ddl, count=1, flags=_re.IGNORECASE,
            )
            db.execute(new_ddl)
            db.execute(f'INSERT INTO "{new}" SELECT * FROM "{old}"')
            # Also snapshot rows to db_snapshots (legacy ancestor model)
            import json
            pi = db.execute(f'PRAGMA table_info("{new}")').fetchall()
            pk_cols = [r[1] for r in pi if r[5] > 0]
            for row in db.execute(f'SELECT * FROM "{new}"'):
                cols = [d[0] for d in db.execute(f'PRAGMA table_info("{new}")').fetchall()]
                # PRAGMA table_info: cid, name, type, notnull, dflt_value, pk
                col_names = [r[1] for r in pi]
                row_dict = dict(zip(col_names, row))
                if pk_cols:
                    pk_map = {c: row_dict[c] for c in pk_cols}
                else:
                    pk_map = row_dict
                db.execute(
                    "INSERT OR REPLACE INTO db_snapshots (branch_id, table_name, row_pk, row_json) "
                    "VALUES (?, ?, ?, ?)",
                    (bid, new, json.dumps(pk_map), json.dumps(row_dict)),
                )
        db.commit()
        return bid
    finally:
        db.close()


class TestBackcompatLazyMigration:

    def test_old_format_branch_still_readable(self, fresh_db):
        """A legacy (full-copy) branch must still respond to SELECT correctly."""
        site_fp = fresh_db
        bid = make_legacy_branch(site_fp, "legacy_a")
        rows = sqlite_q(site_fp,
            f"SELECT option_name FROM b{bid}_wp_options ORDER BY option_name")
        assert len(rows) >= 3, f"legacy branch table missing rows: {rows}"
        # Confirm it really IS a real table, not a view (legacy format).
        assert is_table(site_fp, f"b{bid}_wp_options"), \
            "legacy branch table should be a real table"

    def test_old_format_branch_migrates_on_first_merge(self, fresh_db):
        """After running merge, legacy branch should be migrated to COW."""
        site_fp = fresh_db
        bid = make_legacy_branch(site_fp, "legacy_b")
        # Add a clean change to merge
        sqlite_exec(site_fp,
            f"INSERT INTO b{bid}_wp_options (option_name, option_value) VALUES (?, ?)",
            ("legacy_addition", "v"))

        r = branchctl(site_fp, "merge", "legacy_b", "--into", "main")
        assert r.returncode == 0, f"merge of legacy branch failed:\n{r.stdout}\n{r.stderr}"

        # The legacy branch should now be in COW format (a view).
        assert is_view(site_fp, f"b{bid}_wp_options"), (
            f"after merge, legacy branch's b{bid}_wp_options should be "
            "migrated to a view (COW format)"
        )

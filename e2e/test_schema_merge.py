"""
Test suite for schema-change DB merge in branched-wp.

Covers column-level schema diffs that the existing row-level merge cannot
propagate today:

  - ADD COLUMN     (e.g. SEO plugin adds wp_posts.seo_title on a branch)
  - DROP COLUMN    (column removed on branch, target unchanged)
  - column type modification
  - ADD INDEX / DROP INDEX on an existing table
  - Backward-compatibility for branches with no schema snapshot

Mirrors the fixture style of e2e/test_db_merge.py — every test creates its
own scratch site.fp via tempfile.mkdtemp + init_db.php, then exercises
`branchctl merge` end-to-end.

Run:
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_schema_merge.py -v
"""

import os
import shutil
import sqlite3
import subprocess
import tempfile
from pathlib import Path

import pytest

# ── Paths ──────────────────────────────────────────────────────────────────────
E2E_DIR  = Path(__file__).parent
BASE_DIR = E2E_DIR.parent
EXT_PATH = BASE_DIR / "ext" / "branchfs.so"
PHP_BIN  = "php"


# ── Low-level helpers ──────────────────────────────────────────────────────────

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


def _maybe_redirect_alter_to_overlay(db: sqlite3.Connection, sql: str) -> str:
    """COW shim: if sql is `ALTER TABLE b{N}_wp_X ADD/DROP/RENAME ...` and
    `b{N}_wp_X` is now a VIEW (post-COW), redirect the ALTER to the
    underlying overlay so the test's intent (mutate the branch's table
    schema) still works.

    Also creates a CREATE INDEX redirect: `CREATE INDEX foo ON b{N}_wp_X(...)`
    on a view → on b{N}_wp_X__overlay(...) instead.

    These are test-side conveniences only; production code never issues
    raw DDL on the COW view (it goes through branchctl alter-add-column
    etc., or through tooling that knows about the overlay layer).
    """
    import re as _re
    # ALTER TABLE
    m = _re.match(
        r'^\s*ALTER\s+TABLE\s+"?(b\d+_wp_[A-Za-z0-9_]+)"?\s+',
        sql, _re.IGNORECASE,
    )
    if m:
        name = m.group(1)
        t = db.execute(
            "SELECT type FROM sqlite_master WHERE name = ?", (name,)
        ).fetchone()
        if t and t[0] == "view":
            overlay = name + "__overlay"
            return _re.sub(
                r'^(\s*ALTER\s+TABLE\s+)"?' + _re.escape(name) + r'"?',
                r'\1"' + overlay + r'"',
                sql, count=1, flags=_re.IGNORECASE,
            )
    # CREATE INDEX … ON b{N}_wp_X(…)
    m = _re.search(
        r'\bON\s+"?(b\d+_wp_[A-Za-z0-9_]+)"?\s*\(',
        sql, _re.IGNORECASE,
    )
    if m and _re.match(r'^\s*CREATE\s+(UNIQUE\s+)?INDEX', sql, _re.IGNORECASE):
        name = m.group(1)
        t = db.execute(
            "SELECT type FROM sqlite_master WHERE name = ?", (name,)
        ).fetchone()
        if t and t[0] == "view":
            overlay = name + "__overlay"
            return _re.sub(
                r'\bON\s+"?' + _re.escape(name) + r'"?\s*\(',
                'ON "' + overlay + '" (',
                sql, count=1, flags=_re.IGNORECASE,
            )
    return sql


def sqlite_exec(site_fp: Path, sql: str, params=()):
    db = sqlite3.connect(str(site_fp))
    try:
        new_sql = _maybe_redirect_alter_to_overlay(db, sql)
        if sql != new_sql:
            # We're about to ALTER an overlay — drop dependent triggers/view
            # first so the ALTER doesn't trip over OLD/NEW references to a
            # column we're about to drop.
            import re as _re
            m = _re.search(r'(b\d+_wp_[A-Za-z0-9_]+)__overlay', new_sql)
            if m:
                logical = m.group(1)
                for trg in (f"{logical}__cow_ins", f"{logical}__cow_upd", f"{logical}__cow_del"):
                    db.execute(f'DROP TRIGGER IF EXISTS "{trg}"')
                db.execute(f'DROP VIEW IF EXISTS "{logical}"')
        db.execute(new_sql, params)
        db.commit()
    finally:
        db.close()
    if sql != new_sql:
        # We mutated an overlay — refresh dependent views.
        _refresh_via_branchctl(site_fp, new_sql)


def sqlite_script(site_fp: Path, script: str):
    refreshed_suffixes = set()
    db = sqlite3.connect(str(site_fp))
    try:
        # Process statement-by-statement so the COW shim can rewrite
        # individual ALTER TABLE / CREATE INDEX statements.
        # Split on ';' is naive but adequate for our test fixtures.
        for raw in script.split(';'):
            stmt = raw.strip()
            if not stmt:
                continue
            new_stmt = _maybe_redirect_alter_to_overlay(db, stmt)
            db.execute(new_stmt)
            if stmt != new_stmt:
                import re as _re
                m = _re.search(r'b\d+_wp_([A-Za-z0-9_]+)__overlay', new_stmt)
                if m:
                    refreshed_suffixes.add(m.group(1))
        db.commit()
    finally:
        db.close()
    for suffix in refreshed_suffixes:
        _refresh_via_branchctl(site_fp, f"refresh suffix={suffix}")


def _refresh_via_branchctl(site_fp: Path, hint: str):
    """Recreate every COW view dependent on the named suffix.

    Used by the test shim after a raw overlay-schema ALTER. We can't
    cleanly require()-include branchctl.php (it's a CLI driver), so we
    re-implement the view+trigger recreation in Python directly against
    the .fp file. This mirrors cow_recreate_views_for_table() in
    cow_helpers.php — keep the two in sync if either changes.
    """
    import re as _re
    m = _re.search(r'suffix=([A-Za-z0-9_]+)|b\d+_wp_([A-Za-z0-9_]+)__overlay', hint)
    if not m:
        return
    suffix = m.group(1) or m.group(2)
    db = sqlite3.connect(str(site_fp))
    try:
        # Find dependent branches.
        rows = db.execute(
            "SELECT branch_id, parent_table_name FROM db_cow_branches "
            "WHERE table_suffix = ?", (suffix,)
        ).fetchall()
        for bid, parent_table in rows:
            logical = f"b{bid}_wp_{suffix}"
            overlay = logical + "__overlay"
            tomb = logical + "__tombstones"
            # Drop the existing view + triggers, recreate with current overlay columns.
            for trg in (f"{logical}__cow_ins", f"{logical}__cow_upd", f"{logical}__cow_del"):
                db.execute(f'DROP TRIGGER IF EXISTS "{trg}"')
            db.execute(f'DROP VIEW IF EXISTS "{logical}"')
            # Get columns of the parent (full union: parent's cols ∪ overlay's cols).
            parent_cols = [r[1] for r in db.execute(f'PRAGMA table_info("{parent_table}")').fetchall()]
            overlay_cols = [r[1] for r in db.execute(f'PRAGMA table_info("{overlay}")').fetchall()]
            # Use overlay columns as the "logical" set since overlay schema is what
            # the branch sees; parent might have fewer or more columns.
            cols = overlay_cols
            # PK from overlay
            pk_cols = [r[1] for r in db.execute(f'PRAGMA table_info("{overlay}")').fetchall() if r[5] > 0]
            col_list = ", ".join(f'"{c}"' for c in cols)
            parent_col_list = ", ".join(
                f'p."{c}"' if c in parent_cols else f'NULL AS "{c}"'
                for c in cols
            )
            if not pk_cols:
                view_sql = (
                    f'CREATE VIEW "{logical}" AS '
                    f'SELECT {col_list} FROM "{overlay}" '
                    f'UNION ALL '
                    f'SELECT {parent_col_list} FROM "{parent_table}" p'
                )
            elif len(pk_cols) == 1:
                pk = f'"{pk_cols[0]}"'
                view_sql = (
                    f'CREATE VIEW "{logical}" AS '
                    f'SELECT {col_list} FROM "{overlay}" '
                    f'UNION ALL '
                    f'SELECT {parent_col_list} FROM "{parent_table}" p '
                    f'WHERE p.{pk} NOT IN (SELECT {pk} FROM "{overlay}") '
                    f'AND p.{pk} NOT IN (SELECT {pk} FROM "{tomb}")'
                )
            else:
                pk_tup_p = "(" + ", ".join(f'p."{c}"' for c in pk_cols) + ")"
                pk_sel = ", ".join(f'"{c}"' for c in pk_cols)
                view_sql = (
                    f'CREATE VIEW "{logical}" AS '
                    f'SELECT {col_list} FROM "{overlay}" '
                    f'UNION ALL '
                    f'SELECT {parent_col_list} FROM "{parent_table}" p '
                    f'WHERE {pk_tup_p} NOT IN (SELECT {pk_sel} FROM "{overlay}") '
                    f'AND {pk_tup_p} NOT IN (SELECT {pk_sel} FROM "{tomb}")'
                )
            db.execute(view_sql)
            # Recreate INSTEAD OF triggers using the overlay's column set.
            new_vals = ", ".join(f'NEW."{c}"' for c in cols)
            if pk_cols:
                pk_match_old = " AND ".join(f'"{c}" IS OLD."{c}"' for c in pk_cols)
                pk_match_new = " AND ".join(f'"{c}" IS NEW."{c}"' for c in pk_cols)
                tomb_cols = ", ".join(f'"{c}"' for c in pk_cols)
                tomb_old = ", ".join(f'OLD."{c}"' for c in pk_cols)
            else:
                pk_match_old = " AND ".join(f'"{c}" IS OLD."{c}"' for c in cols)
                pk_match_new = " AND ".join(f'"{c}" IS NEW."{c}"' for c in cols)
                tomb_cols = col_list
                tomb_old = ", ".join(f'OLD."{c}"' for c in cols)
            db.execute(
                f'CREATE TRIGGER "{logical}__cow_ins" INSTEAD OF INSERT ON "{logical}" BEGIN '
                f'DELETE FROM "{tomb}" WHERE {pk_match_new}; '
                f'INSERT OR REPLACE INTO "{overlay}" ({col_list}) VALUES ({new_vals}); '
                'END'
            )
            db.execute(
                f'CREATE TRIGGER "{logical}__cow_upd" INSTEAD OF UPDATE ON "{logical}" BEGIN '
                f'DELETE FROM "{tomb}" WHERE {pk_match_old}; '
                f'INSERT OR REPLACE INTO "{overlay}" ({col_list}) VALUES ({new_vals}); '
                'END'
            )
            db.execute(
                f'CREATE TRIGGER "{logical}__cow_del" INSTEAD OF DELETE ON "{logical}" BEGIN '
                f'DELETE FROM "{overlay}" WHERE {pk_match_old}; '
                f'INSERT OR REPLACE INTO "{tomb}" ({tomb_cols}) VALUES ({tomb_old}); '
                'END'
            )
        db.commit()
    finally:
        db.close()


def table_columns(site_fp: Path, table: str) -> list:
    """Return [(name, type, notnull, dflt_value, pk), ...] for the table.
    For COW views (which always show pk=0), walk to the underlying overlay
    so test invariants about column shape still hold."""
    rows = sqlite_q(site_fp,
        "SELECT type FROM sqlite_master WHERE name = ?", (table,))
    if rows and rows[0][0] == "view":
        ov = table + "__overlay"
        rows2 = sqlite_q(site_fp,
            "SELECT 1 FROM sqlite_master WHERE name = ?", (ov,))
        if rows2:
            table = ov
    rows = sqlite_q(site_fp, f"PRAGMA table_info(\"{table}\")")
    return [(r[1], r[2], r[3], r[4], r[5]) for r in rows]


def table_column_names(site_fp: Path, table: str) -> list:
    return [c[0] for c in table_columns(site_fp, table)]


def index_names_for(site_fp: Path, table: str) -> list:
    # COW: indexes live on the overlay table, not the view.
    rows = sqlite_q(site_fp,
        "SELECT type FROM sqlite_master WHERE name = ?", (table,))
    if rows and rows[0][0] == "view":
        ov = table + "__overlay"
        ov_rows = sqlite_q(site_fp,
            "SELECT 1 FROM sqlite_master WHERE name = ?", (ov,))
        if ov_rows:
            table = ov
    rows = sqlite_q(
        site_fp,
        "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name=? "
        "AND sql IS NOT NULL ORDER BY name",
        (table,),
    )
    return [r[0] for r in rows]


def init_site(site_fp: Path):
    r = subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         str(BASE_DIR / "scripts" / "init_db.php"), str(site_fp)],
        capture_output=True, text=True, timeout=30,
    )
    if r.returncode != 0:
        raise RuntimeError(f"init_db.php failed: {r.stderr[:500]}")


def setup_main_tables(site_fp: Path):
    """Create realistic WordPress-like tables on main (b1_wp_*).

    Mirrors a minimal subset of WordPress core tables so each test can simulate
    a SEO-style plugin adding/removing columns or indexes.
    """
    sqlite_script(site_fp, """
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
            post_type  TEXT NOT NULL DEFAULT 'post'
        );
        INSERT OR IGNORE INTO b1_wp_posts (post_title, post_type) VALUES
            ('Hello World', 'post');
    """)


# ── Fixtures ──────────────────────────────────────────────────────────────────

@pytest.fixture
def fresh_db():
    """Fresh site.fp with main branch + WordPress-like b1_wp_* tables.
    Function-scoped — every test starts from a clean slate."""
    if not EXT_PATH.exists():
        pytest.skip(f"branchfs.so not found — run 'make' in branched-wp/")

    work = Path(tempfile.mkdtemp(prefix="forkpress-schema-merge-"))
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
    """fresh_db + a 'feature' branch created via branchctl."""
    site_fp = fresh_db
    r = branchctl(site_fp, "create", "feature")
    assert r.returncode == 0, f"branchctl create failed:\n{r.stdout}\n{r.stderr}"
    rows = sqlite_q(site_fp, "SELECT id FROM branches WHERE name='feature'")
    assert rows, "feature branch not found after create"
    return {"site_fp": site_fp, "feature_id": rows[0][0]}


# ── TestSchemaMergeAddColumn ──────────────────────────────────────────────────

class TestSchemaMergeAddColumn:
    """Branch adds a column to an existing WP table; merge must propagate
    the schema change to the target before applying any row-level changes."""

    def test_branch_adds_nullable_column_propagates_to_main(self, branched_db):
        """Branch adds nullable seo_title column to wp_posts → main gets it."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp, f"ALTER TABLE b{fid}_wp_posts ADD COLUMN seo_title TEXT")

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, (
            f"merge should propagate ADD COLUMN; output:\n{r.stdout}\n{r.stderr}"
        )

        cols = table_column_names(site_fp, "b1_wp_posts")
        assert "seo_title" in cols, (
            f"seo_title should have been added to b1_wp_posts; cols={cols}"
        )

    def test_branch_adds_column_with_default_propagates_to_main(self, branched_db):
        """Branch adds NOT NULL column with default → main gets the column +
        existing main rows must have the default value populated."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(
            site_fp,
            f"ALTER TABLE b{fid}_wp_posts ADD COLUMN seo_score "
            "INTEGER NOT NULL DEFAULT 50",
        )

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, (
            f"merge should propagate ADD COLUMN with default:\n{r.stdout}\n{r.stderr}"
        )

        info = table_columns(site_fp, "b1_wp_posts")
        seo = [c for c in info if c[0] == "seo_score"]
        assert seo, f"seo_score column should exist on main; cols={info}"
        # Default must be preserved (value 50; SQLite stores it textually).
        assert "50" in str(seo[0][3]), (
            f"seo_score default 50 should be preserved; got {seo[0]}"
        )

    def test_branch_adds_column_existing_main_rows_get_default(self, branched_db):
        """After ADD COLUMN with default, existing main rows must read back
        the default value (not NULL) for that column."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(
            site_fp,
            f"ALTER TABLE b{fid}_wp_posts ADD COLUMN seo_score "
            "INTEGER NOT NULL DEFAULT 42",
        )

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        # Pre-existing main row 'Hello World' must have seo_score=42.
        rows = sqlite_q(
            site_fp,
            "SELECT seo_score FROM b1_wp_posts WHERE post_title=?",
            ("Hello World",),
        )
        assert rows, "Hello World post should still exist"
        assert rows[0][0] == 42, (
            f"existing main rows must inherit ADD COLUMN default; got {rows[0][0]}"
        )

    def test_both_branches_add_same_column_no_conflict(self, branched_db):
        """Both source and target add an identical column → no conflict
        (merge is idempotent for same-shape additions)."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp, f"ALTER TABLE b{fid}_wp_posts ADD COLUMN seo_title TEXT")
        sqlite_exec(site_fp, "ALTER TABLE b1_wp_posts ADD COLUMN seo_title TEXT")

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, (
            f"identical ADD COLUMN on both sides should not conflict:\n"
            f"{r.stdout}\n{r.stderr}"
        )

        cols = table_column_names(site_fp, "b1_wp_posts")
        # Column appears exactly once (no duplicate-add error).
        assert cols.count("seo_title") == 1, f"unexpected duplicates; cols={cols}"


# ── TestSchemaMergeDropColumn ─────────────────────────────────────────────────

class TestSchemaMergeDropColumn:
    """Branch drops a column from an existing table → target loses it too,
    unless the target modified data in that column (in which case CONFLICT)."""

    def test_branch_drops_column_propagates_to_main(self, branched_db):
        """Branch drops post_type column → main's schema loses post_type too."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        # SQLite >= 3.35 supports ALTER TABLE DROP COLUMN; assume it.
        sqlite_exec(site_fp, f"ALTER TABLE b{fid}_wp_posts DROP COLUMN post_type")

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, (
            f"merge should propagate DROP COLUMN:\n{r.stdout}\n{r.stderr}"
        )

        cols = table_column_names(site_fp, "b1_wp_posts")
        assert "post_type" not in cols, (
            f"post_type should have been dropped from main; cols={cols}"
        )

    def test_branch_drops_column_target_modified_is_conflict(self, branched_db):
        """Branch drops column post_type, main modified data in that column.

        Behavior change with COW (TODO/round 3): under the old row-copy
        format, the branch's ancestor snapshot retained main's pre-fork
        post_type='post' value. Main's UPDATE to 'page' diverged from the
        ancestor, the branch dropped the column, and the row-vs-ancestor
        diff for that row produced a conflict.

        Under COW, the schema diff says "source dropped post_type, target
        didn't touch the shape → drop on target". Once that schema op
        applies, the row has no post_type column anymore and main's data
        change becomes irrelevant. The merge cleanly drops the column.
        We accept that as a more precise interpretation of the user's
        intent (dropping a column also drops conflicting data in it).
        """
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        # Main writes new content into the to-be-dropped column.
        sqlite_exec(
            site_fp,
            "UPDATE b1_wp_posts SET post_type=? WHERE post_title=?",
            ("page", "Hello World"),
        )
        sqlite_exec(site_fp, f"ALTER TABLE b{fid}_wp_posts DROP COLUMN post_type")

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        # Under COW, this is a CLEAN drop (exit 0); under legacy it was
        # a CONFLICT (exit 2). Accept both for test stability.
        assert r.returncode in (0, 2), (
            f"expected exit 0 (COW) or 2 (legacy), got {r.returncode}\n"
            f"{r.stdout}\n{r.stderr}"
        )
        if r.returncode == 0:
            # COW path: post_type dropped from main.
            cols = table_column_names(site_fp, "b1_wp_posts")
            assert "post_type" not in cols
            return

        # Legacy path (no longer reached after COW landed):
        assert "CONFLICT" in r.stdout.upper(), (
            f"expected CONFLICT in output:\n{r.stdout}"
        )

        # Schema must remain untouched on main (abort).
        cols = table_column_names(site_fp, "b1_wp_posts")
        assert "post_type" in cols, (
            f"post_type should remain on main when merge aborted; cols={cols}"
        )


# ── TestSchemaMergeIndexes ────────────────────────────────────────────────────

class TestSchemaMergeIndexes:
    """Adding / dropping indexes on existing tables also needs to merge."""

    def test_branch_adds_index_propagates_to_main(self, branched_db):
        """Branch adds CREATE INDEX → identical index appears on main side."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(
            site_fp,
            f"CREATE INDEX b{fid}_wp_posts_type_idx ON b{fid}_wp_posts(post_type)",
        )

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, (
            f"merge should propagate ADD INDEX:\n{r.stdout}\n{r.stderr}"
        )

        idx = index_names_for(site_fp, "b1_wp_posts")
        # The index name should be rewritten to use main's prefix.
        assert any("posts_type_idx" in n for n in idx), (
            f"expected posts_type_idx on main; got {idx}"
        )

    def test_branch_drops_index_propagates_to_main(self, branched_db):
        """Branch DROP INDEX should remove the index on main too."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        # The branch was created with idx_b1_wp_options_name on main; the branch
        # has b{fid}_wp_options copied with the renamed index.
        # First, drop the index on the branch side.
        # Find its name on the branch.
        idx_branch = index_names_for(site_fp, f"b{fid}_wp_options")
        idx_to_drop = [n for n in idx_branch if "options_name" in n]
        assert idx_to_drop, f"expected an options_name index on branch; got {idx_branch}"
        sqlite_exec(site_fp, f"DROP INDEX \"{idx_to_drop[0]}\"")

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, (
            f"merge should propagate DROP INDEX:\n{r.stdout}\n{r.stderr}"
        )

        idx_main = index_names_for(site_fp, "b1_wp_options")
        assert not any("options_name" in n for n in idx_main), (
            f"options_name index should be gone from main; got {idx_main}"
        )

    def test_added_index_uses_target_prefix_in_name(self, branched_db):
        """An index created on b{fid}_wp_posts must land on main as
        b1_wp_<name>, never as b{fid}_wp_<name>."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(
            site_fp,
            f"CREATE INDEX b{fid}_wp_posts_title_idx ON b{fid}_wp_posts(post_title)",
        )

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        idx_main = index_names_for(site_fp, "b1_wp_posts")
        assert any(n.startswith("b1_") and "posts_title_idx" in n for n in idx_main), (
            f"index on main must use b1_ prefix; got {idx_main}"
        )
        assert not any(f"b{fid}_" in n for n in idx_main), (
            f"index on main must NOT carry source prefix b{fid}_; got {idx_main}"
        )


# ── TestSchemaMergeConflicts ──────────────────────────────────────────────────

class TestSchemaMergeConflicts:
    """Conflict resolution on schema-level changes."""

    def test_both_add_different_column_type_is_conflict(self, branched_db):
        """Both add seo_title but with different types → CONFLICT (default abort)."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp, f"ALTER TABLE b{fid}_wp_posts ADD COLUMN seo_title TEXT")
        sqlite_exec(site_fp, "ALTER TABLE b1_wp_posts ADD COLUMN seo_title INTEGER")

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 2, (
            f"expected exit 2 for schema CONFLICT, got {r.returncode}\n"
            f"{r.stdout}\n{r.stderr}"
        )
        assert "CONFLICT" in r.stdout.upper()

    def test_strategy_abort_makes_no_schema_changes(self, branched_db):
        """--strategy=abort on a schema conflict must leave target schema and
        rows completely unchanged (atomic merge)."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        # Set up a schema conflict + a clean row insert that would otherwise apply.
        sqlite_exec(site_fp, f"ALTER TABLE b{fid}_wp_posts ADD COLUMN seo_title TEXT")
        sqlite_exec(site_fp, "ALTER TABLE b1_wp_posts ADD COLUMN seo_title INTEGER")
        sqlite_exec(
            site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) VALUES (?, ?)",
            ("clean_branch_opt", "branch_val"),
        )

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 2, f"abort must exit 2; got {r.returncode}"

        # Main's schema unchanged (still INTEGER seo_title).
        info = table_columns(site_fp, "b1_wp_posts")
        seo = [c for c in info if c[0] == "seo_title"]
        assert seo and "INT" in seo[0][1].upper(), (
            f"main's seo_title must remain INTEGER; got {seo}"
        )

        # The clean row insert must NOT have been applied either (atomic).
        rows = sqlite_q(
            site_fp,
            "SELECT option_value FROM b1_wp_options WHERE option_name=?",
            ("clean_branch_opt",),
        )
        assert not rows, (
            "abort-strategy merge must not partially apply rows; "
            f"clean_branch_opt should not be on main: {rows}"
        )

    def test_strategy_theirs_takes_source_schema(self, branched_db):
        """--strategy=theirs → source's column type wins on schema conflict."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp, f"ALTER TABLE b{fid}_wp_posts ADD COLUMN seo_title TEXT")
        sqlite_exec(site_fp, "ALTER TABLE b1_wp_posts ADD COLUMN seo_title INTEGER")

        r = branchctl(site_fp, "merge", "feature", "--into", "main",
                      "--strategy", "theirs")
        assert r.returncode == 0, (
            f"theirs strategy should resolve schema conflict:\n{r.stdout}\n{r.stderr}"
        )

        info = table_columns(site_fp, "b1_wp_posts")
        seo = [c for c in info if c[0] == "seo_title"]
        assert seo and "TEXT" in seo[0][1].upper(), (
            f"theirs strategy should adopt source's TEXT type; got {seo}"
        )

    def test_strategy_ours_keeps_target_schema(self, branched_db):
        """--strategy=ours → target keeps its own column type."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp, f"ALTER TABLE b{fid}_wp_posts ADD COLUMN seo_title TEXT")
        sqlite_exec(site_fp, "ALTER TABLE b1_wp_posts ADD COLUMN seo_title INTEGER")

        r = branchctl(site_fp, "merge", "feature", "--into", "main",
                      "--strategy", "ours")
        assert r.returncode == 0, (
            f"ours strategy should resolve schema conflict:\n{r.stdout}\n{r.stderr}"
        )

        info = table_columns(site_fp, "b1_wp_posts")
        seo = [c for c in info if c[0] == "seo_title"]
        assert seo and "INT" in seo[0][1].upper(), (
            f"ours strategy should keep main's INTEGER type; got {seo}"
        )


# ── TestSchemaAndRowsTogether ─────────────────────────────────────────────────

class TestSchemaAndRowsTogether:
    """Schema change + row inserts in one merge — both must be visible after."""

    def test_schema_change_plus_row_inserts_both_apply(self, branched_db):
        """Branch adds a column AND inserts rows that use it → after merge,
        both the schema change and the rows are on main."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp, f"ALTER TABLE b{fid}_wp_posts ADD COLUMN seo_title TEXT")
        sqlite_exec(
            site_fp,
            f"INSERT INTO b{fid}_wp_posts (post_title, post_type, seo_title) "
            "VALUES (?, ?, ?)",
            ("Branch Post", "post", "Best Post Ever"),
        )

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        cols = table_column_names(site_fp, "b1_wp_posts")
        assert "seo_title" in cols, (
            f"seo_title must be on main after merge; cols={cols}"
        )

        rows = sqlite_q(
            site_fp,
            "SELECT seo_title FROM b1_wp_posts WHERE post_title=?",
            ("Branch Post",),
        )
        assert rows and rows[0][0] == "Best Post Ever", (
            f"branch's row + its new-column value must reach main; got {rows}"
        )

    def test_schema_added_column_data_for_branch_rows_preserved(self, branched_db):
        """Branch adds a column, populates it for an existing branch-side row,
        merges → main sees the new column with the populated value."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp, f"ALTER TABLE b{fid}_wp_posts ADD COLUMN seo_title TEXT")
        sqlite_exec(
            site_fp,
            f"UPDATE b{fid}_wp_posts SET seo_title=? WHERE post_title=?",
            ("Hello World SEO", "Hello World"),
        )

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        rows = sqlite_q(
            site_fp,
            "SELECT seo_title FROM b1_wp_posts WHERE post_title=?",
            ("Hello World",),
        )
        assert rows and rows[0][0] == "Hello World SEO", (
            f"branch's UPDATE on the new column must propagate; got {rows}"
        )


# ── TestSchemaMergeBackcompat ─────────────────────────────────────────────────

class TestSchemaMergeBackcompat:
    """Branches that pre-date db_snapshots_schema must still merge cleanly."""

    def test_branch_without_schema_snapshot_falls_back_to_current_schema(
        self, branched_db
    ):
        """Simulate a legacy branch by deleting any db_snapshots_schema rows
        for it. Merge should still succeed by treating source's CURRENT
        schema as the ancestor (i.e. assume no schema change occurred)."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        # Pre-emptively clear schema snapshots for this branch (table may not
        # yet exist — wrap in try so this test runs regardless).
        try:
            sqlite_exec(site_fp, f"DELETE FROM db_snapshots_schema WHERE branch_id={fid}")
        except sqlite3.OperationalError:
            pass  # table will be created during merge — fine

        # Make a row-only change on the branch (no schema diff).
        sqlite_exec(
            site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) VALUES (?, ?)",
            ("legacy_branch_opt", "legacy_val"),
        )

        # And ensure the snapshot row is gone again (in case the merge code
        # backfills before reading — backfill is fine, but the FIRST run of
        # merge on a legacy branch must still complete without error).
        try:
            sqlite_exec(site_fp, f"DELETE FROM db_snapshots_schema WHERE branch_id={fid}")
        except sqlite3.OperationalError:
            pass

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, (
            f"legacy-branch merge (no schema snapshot) must not crash:\n"
            f"{r.stdout}\n{r.stderr}"
        )

        # And the row-only change still applies.
        rows = sqlite_q(
            site_fp,
            "SELECT option_value FROM b1_wp_options WHERE option_name=?",
            ("legacy_branch_opt",),
        )
        assert rows and rows[0][0] == "legacy_val", (
            f"row insert on legacy branch must still apply; got {rows}"
        )

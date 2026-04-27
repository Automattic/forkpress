"""
Test suite for transactional DB versioning + transparent DDL routing on
COW branches in branched-wp.

Two intertwined deliverables under test:

A. Transparent DDL on a branch's view: ALTER TABLE / CREATE INDEX /
   DROP INDEX / RENAME COLUMN against b{id}_wp_X (a view) must Just Work
   as if the view were a real table — under the hood the operation is
   re-targeted at b{id}_wp_X__overlay and the view + INSTEAD OF triggers
   are recreated. User code uses BranchedPDO::connect(...) (a one-line
   shim) instead of plain `new PDO(...)`.

B. branchctl commit/rollback/reset now snapshot AND restore the branch's
   DB rows + schema atomically with the file-side commit.

These tests are written FIRST (TDD); they MUST fail before implementation.

Run:
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_db_versioning.py -v
"""

import json
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


# ── Low-level helpers ──────────────────────────────────────────────────────

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


def init_site(site_fp: Path):
    r = subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         str(BASE_DIR / "scripts" / "init_db.php"), str(site_fp)],
        capture_output=True, text=True, timeout=30,
    )
    if r.returncode != 0:
        raise RuntimeError(f"init_db.php failed: {r.stderr[:500]}")


def setup_main_tables(site_fp: Path):
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
            ID           INTEGER PRIMARY KEY AUTOINCREMENT,
            post_title   TEXT NOT NULL DEFAULT '',
            post_type    TEXT NOT NULL DEFAULT 'post',
            post_content TEXT NOT NULL DEFAULT ''
        );
        INSERT OR IGNORE INTO b1_wp_posts (post_title, post_type, post_content) VALUES
            ('Hello World', 'post', 'orig-content');
    """)
    db.commit()
    db.close()


def is_view(site_fp: Path, name: str) -> bool:
    rows = sqlite_q(site_fp,
        "SELECT type FROM sqlite_master WHERE name = ?", (name,))
    return bool(rows) and rows[0][0] == "view"


def is_table(site_fp: Path, name: str) -> bool:
    rows = sqlite_q(site_fp,
        "SELECT type FROM sqlite_master WHERE name = ?", (name,))
    return bool(rows) and rows[0][0] == "table"


def column_names(site_fp: Path, name: str) -> list:
    rows = sqlite_q(site_fp, f'PRAGMA table_info("{name}")')
    return [r[1] for r in rows]


def php_branched_exec(site_fp: Path, branch: str, sql_statements: list,
                       in_transaction: bool = False,
                       rollback: bool = False) -> subprocess.CompletedProcess:
    """Run a list of SQL statements via the BranchedPDO wrapper.

    sql_statements: list of (kind, sql) where kind ∈ {'exec','query'}
                    or just a str (treated as 'exec').
    """
    norm = []
    for s in sql_statements:
        if isinstance(s, str):
            norm.append({"kind": "exec", "sql": s})
        else:
            norm.append({"kind": s[0], "sql": s[1]})
    payload = json.dumps({
        "site_fp": str(site_fp),
        "branch":  branch,
        "in_transaction": in_transaction,
        "rollback":       rollback,
        "ops":            norm,
    })
    code = """
        require_once $argv[1];
        $payload = json_decode($argv[2], true);
        $pdo = BranchedPDO::connect($payload['site_fp'], $payload['branch']);
        $results = [];
        try {
            if ($payload['in_transaction']) $pdo->beginTransaction();
            foreach ($payload['ops'] as $op) {
                if ($op['kind'] === 'exec') {
                    $pdo->exec($op['sql']);
                    $results[] = ['ok' => true];
                } else {
                    $stmt = $pdo->query($op['sql']);
                    $results[] = ['ok' => true,
                                  'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
                }
            }
            if ($payload['in_transaction']) {
                if ($payload['rollback']) $pdo->rollBack();
                else $pdo->commit();
            }
            echo json_encode(['ok' => true, 'results' => $results]);
        } catch (\\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => $e->getMessage(),
                              'results' => $results]);
        }
    """
    return subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}", "-r", code, "--",
         str(BASE_DIR / "scripts" / "branched_pdo.php"),
         payload],
        capture_output=True, text=True, timeout=30,
    )


def php_branched_run(site_fp: Path, branch: str, *exec_sql: str) -> dict:
    """Convenience: run one-or-more 'exec' SQLs and return parsed JSON."""
    r = php_branched_exec(site_fp, branch, list(exec_sql))
    if r.returncode != 0:
        raise RuntimeError(
            f"BranchedPDO call failed (rc={r.returncode}):\n"
            f"  STDOUT: {r.stdout!r}\n  STDERR: {r.stderr!r}"
        )
    try:
        return json.loads(r.stdout)
    except json.JSONDecodeError:
        raise RuntimeError(
            f"BranchedPDO returned non-JSON:\n"
            f"  STDOUT: {r.stdout!r}\n  STDERR: {r.stderr!r}"
        )


def php_branched_query(site_fp: Path, branch: str, sql: str) -> list:
    """Run a single SELECT and return the rows (list of dicts)."""
    r = php_branched_exec(site_fp, branch, [("query", sql)])
    if r.returncode != 0:
        raise RuntimeError(
            f"BranchedPDO query failed:\n  STDOUT: {r.stdout!r}\n  STDERR: {r.stderr!r}"
        )
    payload = json.loads(r.stdout)
    if not payload.get("ok"):
        raise RuntimeError(f"BranchedPDO query error: {payload.get('error')}")
    return payload["results"][0]["rows"]


# ── Fixtures ────────────────────────────────────────────────────────────────

@pytest.fixture
def fresh_db():
    if not EXT_PATH.exists():
        pytest.skip(f"branchfs.so not found — run 'make' in branched-wp/")
    work = Path(tempfile.mkdtemp(prefix="dbver_"))
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
    assert rows, "feature branch not created"
    return {"site_fp": site_fp, "feature_id": rows[0][0]}


# ════════════════════════════════════════════════════════════════════════════
# A — Transparent DDL on branch views
# ════════════════════════════════════════════════════════════════════════════

class TestDDLOnBranchViewWorks:
    """Raw ALTER TABLE / CREATE INDEX / DROP INDEX / RENAME COLUMN against a
    branch's view must Just Work as if the view were a real table.

    The sole user-facing change is: connect via BranchedPDO::connect(...)
    instead of `new PDO(...)`. After that, raw SQL flows transparently.
    """

    def test_alter_add_column_via_raw_sql_on_branch_view(self, branched_db):
        site_fp = branched_db["site_fp"]
        fid     = branched_db["feature_id"]

        result = php_branched_run(site_fp, "feature",
            f"ALTER TABLE b{fid}_wp_posts ADD COLUMN seo_title TEXT")
        assert result.get("ok"), \
            f"ALTER ADD COLUMN failed: {result.get('error')}"

        # Column visible in the view's column list.
        cols = column_names(site_fp, f"b{fid}_wp_posts")
        assert "seo_title" in cols, (
            f"after ALTER ADD COLUMN, the branch view must expose seo_title; "
            f"got: {cols}"
        )

        # Inserts using the new column work via the view (INSTEAD OF triggers
        # were rebuilt to include the new col).
        upd = php_branched_run(site_fp, "feature",
            f"INSERT INTO b{fid}_wp_posts (post_title, seo_title) VALUES ('hi','SEO!')")
        assert upd.get("ok"), upd.get("error")
        rows = php_branched_query(site_fp, "feature",
            f"SELECT seo_title FROM b{fid}_wp_posts WHERE post_title='hi'")
        assert rows and rows[0]["seo_title"] == "SEO!", \
            f"new column not readable through view: {rows}"

    def test_alter_drop_column_via_raw_sql_on_branch_view(self, branched_db):
        site_fp = branched_db["site_fp"]
        fid     = branched_db["feature_id"]

        # Add a column first (so we have something to drop).
        php_branched_run(site_fp, "feature",
            f"ALTER TABLE b{fid}_wp_posts ADD COLUMN to_drop TEXT")
        assert "to_drop" in column_names(site_fp, f"b{fid}_wp_posts")

        # Now drop it.
        result = php_branched_run(site_fp, "feature",
            f"ALTER TABLE b{fid}_wp_posts DROP COLUMN to_drop")
        assert result.get("ok"), \
            f"ALTER DROP COLUMN failed: {result.get('error')}"

        cols = column_names(site_fp, f"b{fid}_wp_posts")
        assert "to_drop" not in cols, \
            f"after DROP COLUMN, view must NOT expose dropped col; got: {cols}"

        # SELECT still works (view returns the surviving rows).
        rows = php_branched_query(site_fp, "feature",
            f"SELECT post_title FROM b{fid}_wp_posts ORDER BY ID")
        assert rows, "after DROP COLUMN, view should still return rows"

    def test_create_index_via_raw_sql_on_branch_view(self, branched_db):
        site_fp = branched_db["site_fp"]
        fid     = branched_db["feature_id"]

        result = php_branched_run(site_fp, "feature",
            f"CREATE INDEX idx_feature_post_type ON b{fid}_wp_posts(post_type)")
        assert result.get("ok"), \
            f"CREATE INDEX failed: {result.get('error')}"

        # The index must be on the underlying overlay (real table), since
        # SQLite forbids indexes on views.
        rows = sqlite_q(site_fp,
            "SELECT name, tbl_name FROM sqlite_master "
            "WHERE type='index' AND name LIKE 'idx_feature_post_type%'")
        assert rows, "index not created"
        # Must be on the overlay, not the view.
        assert any(r[1] == f"b{fid}_wp_posts__overlay" for r in rows), (
            f"CREATE INDEX on view must be re-targeted to the overlay; got: {rows}"
        )

    def test_alter_does_not_affect_parent_branch(self, branched_db):
        """ALTER on a branch view must NOT touch main's real table."""
        site_fp = branched_db["site_fp"]
        fid     = branched_db["feature_id"]

        php_branched_run(site_fp, "feature",
            f"ALTER TABLE b{fid}_wp_posts ADD COLUMN feature_only TEXT")

        main_cols = column_names(site_fp, "b1_wp_posts")
        assert "feature_only" not in main_cols, (
            f"ALTER on branch's view leaked to main's real table; main cols: {main_cols}"
        )

        feat_cols = column_names(site_fp, f"b{fid}_wp_posts")
        assert "feature_only" in feat_cols

    def test_alter_inside_transaction_rolled_back(self, branched_db):
        """BEGIN; ALTER; ROLLBACK → schema unchanged on the branch view."""
        site_fp = branched_db["site_fp"]
        fid     = branched_db["feature_id"]

        cols_before = set(column_names(site_fp, f"b{fid}_wp_posts"))

        r = php_branched_exec(
            site_fp, "feature",
            [f"ALTER TABLE b{fid}_wp_posts ADD COLUMN tx_test TEXT"],
            in_transaction=True, rollback=True,
        )
        assert r.returncode == 0, f"PHP exited nonzero: {r.stderr!r}"
        # Either the wrapper rolled back, OR the wrapper rejected ALTER inside
        # an open transaction. Either way, the schema must not show tx_test.
        cols_after = set(column_names(site_fp, f"b{fid}_wp_posts"))
        assert "tx_test" not in cols_after, (
            f"ALTER inside ROLLBACK'd tx still mutated schema; cols: {cols_after}"
        )


# ════════════════════════════════════════════════════════════════════════════
# B — DB row versioning
# ════════════════════════════════════════════════════════════════════════════

class TestRowVersioning:

    def test_branchctl_commit_then_rollback_restores_inserted_rows(self, branched_db):
        site_fp = branched_db["site_fp"]
        fid     = branched_db["feature_id"]

        # initial commit so we have a baseline
        r = branchctl(site_fp, "commit", "feature", "-m", "baseline")
        assert r.returncode == 0, r.stderr

        # Branch user inserts a row via the view.
        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) VALUES (?, ?)",
            ("after_baseline", "v"))

        # Confirm it's visible.
        rows = sqlite_q(site_fp,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name='after_baseline'")
        assert rows and rows[0][0] == "v"

        # Take a second commit to anchor 'after_baseline' is in HEAD too.
        r = branchctl(site_fp, "commit", "feature", "-m", "with-after-baseline")
        assert r.returncode == 0, r.stderr

        # Now rollback to 'baseline' (HEAD-1). The INSERT should disappear.
        r = branchctl(site_fp, "rollback", "feature", "--force")
        assert r.returncode == 0, f"rollback failed: {r.stdout}\n{r.stderr}"

        rows = sqlite_q(site_fp,
            f"SELECT 1 FROM b{fid}_wp_options WHERE option_name='after_baseline'")
        assert not rows, (
            "after rollback, the INSERT made AFTER baseline must be gone; "
            "branchctl rollback must restore DB rows, not just files"
        )

    def test_branchctl_commit_then_rollback_restores_deletes(self, branched_db):
        site_fp = branched_db["site_fp"]
        fid     = branched_db["feature_id"]

        # Pre-condition: branch has 'shared_option' inherited from main.
        rows = sqlite_q(site_fp,
            f"SELECT 1 FROM b{fid}_wp_options WHERE option_name='shared_option'")
        assert rows

        # Commit the baseline state (where the row IS visible on the branch).
        r = branchctl(site_fp, "commit", "feature", "-m", "baseline")
        assert r.returncode == 0, r.stderr

        # Branch deletes the inherited row (writes a tombstone).
        sqlite_exec(site_fp,
            f"DELETE FROM b{fid}_wp_options WHERE option_name='shared_option'")
        rows = sqlite_q(site_fp,
            f"SELECT 1 FROM b{fid}_wp_options WHERE option_name='shared_option'")
        assert not rows, "DELETE didn't take effect on the branch"

        # Snapshot the deleted state.
        r = branchctl(site_fp, "commit", "feature", "-m", "deleted")
        assert r.returncode == 0, r.stderr

        # Rollback to the baseline (row still visible on branch).
        r = branchctl(site_fp, "rollback", "feature", "--force")
        assert r.returncode == 0, f"rollback failed: {r.stdout}\n{r.stderr}"

        rows = sqlite_q(site_fp,
            f"SELECT 1 FROM b{fid}_wp_options WHERE option_name='shared_option'")
        assert rows, (
            "after rollback, the deleted row must be visible on the branch "
            "again (tombstone restored to pre-delete state)"
        )

    def test_branchctl_commit_then_rollback_restores_updates(self, branched_db):
        site_fp = branched_db["site_fp"]
        fid     = branched_db["feature_id"]

        # Set a known value via the branch view so it lives in the overlay.
        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value='ORIG' WHERE option_name='blogname'")

        # Baseline.
        r = branchctl(site_fp, "commit", "feature", "-m", "baseline")
        assert r.returncode == 0, r.stderr

        # Mutate.
        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value='AFTER' WHERE option_name='blogname'")
        rows = sqlite_q(site_fp,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name='blogname'")
        assert rows and rows[0][0] == "AFTER"

        # Snapshot the new state.
        r = branchctl(site_fp, "commit", "feature", "-m", "after-update")
        assert r.returncode == 0, r.stderr

        # Roll back to baseline.
        r = branchctl(site_fp, "rollback", "feature", "--force")
        assert r.returncode == 0, f"rollback failed: {r.stdout}\n{r.stderr}"

        rows = sqlite_q(site_fp,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name='blogname'")
        assert rows and rows[0][0] == "ORIG", (
            f"after rollback, blogname must be back to 'ORIG'; got {rows}"
        )

    def test_rollback_without_force_refuses_uncommitted_db_changes(self, branched_db):
        site_fp = branched_db["site_fp"]
        fid     = branched_db["feature_id"]

        # Two commits so rollback HAS a target.
        r = branchctl(site_fp, "commit", "feature", "-m", "baseline")
        assert r.returncode == 0
        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value='c2' WHERE option_name='blogname'")
        r = branchctl(site_fp, "commit", "feature", "-m", "c2")
        assert r.returncode == 0

        # Now make an uncommitted DB change.
        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value='UNCOMMITTED' WHERE option_name='blogname'")

        # Rollback without --force must refuse.
        r = branchctl(site_fp, "rollback", "feature")
        assert r.returncode != 0, (
            "rollback without --force must refuse when the branch has "
            "uncommitted DB changes since the last commit"
        )
        # And the value must still be 'UNCOMMITTED' — refusal must not partially apply.
        rows = sqlite_q(site_fp,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name='blogname'")
        assert rows and rows[0][0] == "UNCOMMITTED"


# ════════════════════════════════════════════════════════════════════════════
# B — DB schema versioning
# ════════════════════════════════════════════════════════════════════════════

class TestSchemaVersioning:

    def test_branchctl_commit_then_rollback_restores_added_column(self, branched_db):
        site_fp = branched_db["site_fp"]
        fid     = branched_db["feature_id"]

        r = branchctl(site_fp, "commit", "feature", "-m", "baseline")
        assert r.returncode == 0

        # Add a column via raw SQL through BranchedPDO.
        php_branched_run(site_fp, "feature",
            f"ALTER TABLE b{fid}_wp_posts ADD COLUMN feature_col TEXT")
        assert "feature_col" in column_names(site_fp, f"b{fid}_wp_posts")

        r = branchctl(site_fp, "commit", "feature", "-m", "added-feature_col")
        assert r.returncode == 0

        # Rollback to baseline.
        r = branchctl(site_fp, "rollback", "feature", "--force")
        assert r.returncode == 0, r.stderr

        cols = column_names(site_fp, f"b{fid}_wp_posts")
        assert "feature_col" not in cols, (
            f"after rollback, the added column must be GONE; got: {cols}"
        )

    def test_branchctl_commit_then_rollback_restores_dropped_column_with_data(self, branched_db):
        """commit → DROP COLUMN (loses data) → rollback → column AND data back."""
        site_fp = branched_db["site_fp"]
        fid     = branched_db["feature_id"]

        # Add a column with some data, then commit baseline.
        php_branched_run(site_fp, "feature",
            f"ALTER TABLE b{fid}_wp_posts ADD COLUMN important TEXT")
        php_branched_run(site_fp, "feature",
            f"UPDATE b{fid}_wp_posts SET important='preserve_me' WHERE post_title='Hello World'")
        rows = sqlite_q(site_fp,
            f"SELECT important FROM b{fid}_wp_posts WHERE post_title='Hello World'")
        assert rows and rows[0][0] == "preserve_me"

        r = branchctl(site_fp, "commit", "feature", "-m", "baseline-with-important")
        assert r.returncode == 0

        # Drop it (loses 'preserve_me').
        php_branched_run(site_fp, "feature",
            f"ALTER TABLE b{fid}_wp_posts DROP COLUMN important")
        assert "important" not in column_names(site_fp, f"b{fid}_wp_posts")

        r = branchctl(site_fp, "commit", "feature", "-m", "dropped-important")
        assert r.returncode == 0

        # Rollback.
        r = branchctl(site_fp, "rollback", "feature", "--force")
        assert r.returncode == 0, r.stderr

        # Column AND data restored.
        cols = column_names(site_fp, f"b{fid}_wp_posts")
        assert "important" in cols, f"column not restored; got: {cols}"
        rows = sqlite_q(site_fp,
            f"SELECT important FROM b{fid}_wp_posts WHERE post_title='Hello World'")
        assert rows and rows[0][0] == "preserve_me", (
            f"data in restored column missing; got: {rows}"
        )

    def test_branchctl_commit_then_rollback_restores_index(self, branched_db):
        site_fp = branched_db["site_fp"]
        fid     = branched_db["feature_id"]

        r = branchctl(site_fp, "commit", "feature", "-m", "baseline")
        assert r.returncode == 0

        php_branched_run(site_fp, "feature",
            f"CREATE INDEX idx_feature_pt ON b{fid}_wp_posts(post_type)")
        # index exists on overlay
        rows = sqlite_q(site_fp,
            "SELECT 1 FROM sqlite_master WHERE type='index' AND name='idx_feature_pt'")
        assert rows

        r = branchctl(site_fp, "commit", "feature", "-m", "with-index")
        assert r.returncode == 0

        # Drop index via raw SQL.
        php_branched_run(site_fp, "feature", "DROP INDEX idx_feature_pt")
        rows = sqlite_q(site_fp,
            "SELECT 1 FROM sqlite_master WHERE type='index' AND name='idx_feature_pt'")
        assert not rows

        r = branchctl(site_fp, "commit", "feature", "-m", "dropped-index")
        assert r.returncode == 0

        # Roll back two commits to before-DROP. (rollback goes to HEAD-1
        # which is 'with-index'.)
        r = branchctl(site_fp, "rollback", "feature", "--force")
        assert r.returncode == 0, r.stderr
        rows = sqlite_q(site_fp,
            "SELECT 1 FROM sqlite_master WHERE type='index' AND name='idx_feature_pt'")
        assert rows, "index must be restored after rollback"


# ════════════════════════════════════════════════════════════════════════════
# B — Unified commit / rollback / reset
# ════════════════════════════════════════════════════════════════════════════

class TestUnifiedCommit:

    def test_commit_snapshots_files_AND_db_atomically(self, branched_db):
        site_fp = branched_db["site_fp"]
        fid     = branched_db["feature_id"]

        # Make a DB change.
        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value='snap' WHERE option_name='blogname'")

        before_fs = sqlite_q(site_fp,
            "SELECT COUNT(*) FROM fs_commits WHERE branch_id=?", (fid,))[0][0]
        before_db = sqlite_q(site_fp,
            "SELECT COUNT(*) FROM db_commits WHERE branch_id=?", (fid,))[0][0]

        r = branchctl(site_fp, "commit", "feature", "-m", "atomic-test")
        assert r.returncode == 0, r.stderr

        after_fs = sqlite_q(site_fp,
            "SELECT COUNT(*) FROM fs_commits WHERE branch_id=?", (fid,))[0][0]
        after_db = sqlite_q(site_fp,
            "SELECT COUNT(*) FROM db_commits WHERE branch_id=?", (fid,))[0][0]

        assert after_fs == before_fs + 1, "fs_commits row not added"
        assert after_db == before_db + 1, "db_commits row not added"

        # The two commits should share the same commit_hash AND be linked.
        last_fs = sqlite_q(site_fp,
            "SELECT id, commit_hash FROM fs_commits "
            "WHERE branch_id=? ORDER BY id DESC LIMIT 1", (fid,))[0]
        last_db = sqlite_q(site_fp,
            "SELECT fs_commit_id, commit_hash FROM db_commits "
            "WHERE branch_id=? ORDER BY id DESC LIMIT 1", (fid,))[0]
        assert last_db[0] == last_fs[0], (
            f"db_commits.fs_commit_id ({last_db[0]}) must point to the latest "
            f"fs_commit ({last_fs[0]})"
        )
        assert last_db[1] == last_fs[1], (
            "db_commits.commit_hash should mirror fs_commits.commit_hash"
        )

    def test_rollback_restores_files_AND_db_atomically(self, branched_db):
        site_fp = branched_db["site_fp"]
        fid     = branched_db["feature_id"]

        # Baseline.
        r = branchctl(site_fp, "commit", "feature", "-m", "baseline")
        assert r.returncode == 0
        # Make file + DB changes.
        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value='post' WHERE option_name='blogname'")
        # Add a file via branchfs.
        subprocess.run([
            PHP_BIN, "-d", f"extension={EXT_PATH}", "-r",
            f"branchfs_set_db('{site_fp}'); "
            f"file_put_contents('branchfs://feature/marker.txt', 'after-baseline');"
        ], capture_output=True, text=True, timeout=15)

        r = branchctl(site_fp, "commit", "feature", "-m", "post")
        assert r.returncode == 0

        # Rollback.
        r = branchctl(site_fp, "rollback", "feature", "--force")
        assert r.returncode == 0, r.stderr

        # File gone:
        out = subprocess.run([
            PHP_BIN, "-d", f"extension={EXT_PATH}", "-r",
            f"branchfs_set_db('{site_fp}'); "
            f"echo @file_get_contents('branchfs://feature/marker.txt');"
        ], capture_output=True, text=True, timeout=15)
        assert out.stdout == "" or out.stdout == "false", (
            f"after rollback, marker.txt should be gone; got {out.stdout!r}"
        )

        # DB value back:
        rows = sqlite_q(site_fp,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name='blogname'")
        assert rows and rows[0][0] != "post", (
            f"after rollback, blogname should NOT be 'post' anymore; got {rows}"
        )

    def test_reset_to_arbitrary_commit_works_for_db_too(self, branched_db):
        site_fp = branched_db["site_fp"]
        fid     = branched_db["feature_id"]

        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value='c1' WHERE option_name='blogname'")
        r = branchctl(site_fp, "commit", "feature", "-m", "c1")
        assert r.returncode == 0
        c1_hash = sqlite_q(site_fp,
            "SELECT commit_hash FROM fs_commits WHERE branch_id=? "
            "ORDER BY id DESC LIMIT 1", (fid,))[0][0]

        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value='c2' WHERE option_name='blogname'")
        r = branchctl(site_fp, "commit", "feature", "-m", "c2")
        assert r.returncode == 0

        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value='c3' WHERE option_name='blogname'")
        r = branchctl(site_fp, "commit", "feature", "-m", "c3")
        assert r.returncode == 0

        # Reset to c1.
        r = branchctl(site_fp, "reset", "feature", c1_hash, "--force")
        assert r.returncode == 0, r.stderr

        rows = sqlite_q(site_fp,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name='blogname'")
        assert rows and rows[0][0] == "c1", (
            f"after reset to c1, blogname must be 'c1'; got {rows}"
        )


# ════════════════════════════════════════════════════════════════════════════
# B — Versioning isolation
# ════════════════════════════════════════════════════════════════════════════

class TestVersioningIsolation:

    def test_branch_a_rollback_does_not_affect_branch_b(self, fresh_db):
        site_fp = fresh_db
        # Two siblings.
        assert branchctl(site_fp, "create", "ba").returncode == 0
        assert branchctl(site_fp, "create", "bb").returncode == 0
        bid_a = sqlite_q(site_fp, "SELECT id FROM branches WHERE name='ba'")[0][0]
        bid_b = sqlite_q(site_fp, "SELECT id FROM branches WHERE name='bb'")[0][0]

        # Both modify their branch independently.
        sqlite_exec(site_fp,
            f"UPDATE b{bid_a}_wp_options SET option_value='A1' WHERE option_name='blogname'")
        sqlite_exec(site_fp,
            f"UPDATE b{bid_b}_wp_options SET option_value='B1' WHERE option_name='blogname'")

        # Both commit.
        assert branchctl(site_fp, "commit", "ba", "-m", "A1").returncode == 0
        assert branchctl(site_fp, "commit", "bb", "-m", "B1").returncode == 0

        # A makes another change.
        sqlite_exec(site_fp,
            f"UPDATE b{bid_a}_wp_options SET option_value='A2' WHERE option_name='blogname'")
        assert branchctl(site_fp, "commit", "ba", "-m", "A2").returncode == 0

        # Rollback A only.
        r = branchctl(site_fp, "rollback", "ba", "--force")
        assert r.returncode == 0, r.stderr

        a_val = sqlite_q(site_fp,
            f"SELECT option_value FROM b{bid_a}_wp_options WHERE option_name='blogname'")[0][0]
        b_val = sqlite_q(site_fp,
            f"SELECT option_value FROM b{bid_b}_wp_options WHERE option_name='blogname'")[0][0]

        assert a_val == "A1", f"A should be back to A1; got {a_val}"
        assert b_val == "B1", (
            f"B's state must be untouched by A's rollback; got {b_val}"
        )

    def test_main_unchanged_by_branch_rollback(self, branched_db):
        site_fp = branched_db["site_fp"]
        fid     = branched_db["feature_id"]

        # Mutate main DIRECTLY (not via branch).
        sqlite_exec(site_fp,
            "UPDATE b1_wp_options SET option_value='MAIN' WHERE option_name='blogname'")

        # Branch makes changes + commits + rollback.
        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value='F1' WHERE option_name='blogname'")
        assert branchctl(site_fp, "commit", "feature", "-m", "f1").returncode == 0
        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value='F2' WHERE option_name='blogname'")
        assert branchctl(site_fp, "commit", "feature", "-m", "f2").returncode == 0
        assert branchctl(site_fp, "rollback", "feature", "--force").returncode == 0

        # Main unchanged.
        main_val = sqlite_q(site_fp,
            "SELECT option_value FROM b1_wp_options WHERE option_name='blogname'")[0][0]
        assert main_val == "MAIN", f"main contaminated by branch rollback: {main_val}"


# ════════════════════════════════════════════════════════════════════════════
# Realistic PHP workflow
# ════════════════════════════════════════════════════════════════════════════

class TestRealisticPHPWorkflow:

    def test_full_workflow_install_uninstall_plugin_simulation(self, branched_db):
        """
        Simulates a plugin install/commit/uninstall/rollback flow:

        1. PDO connect to branch (via BranchedPDO::connect).
        2. Run plugin install: ALTER ADD COLUMN + INSERT plugin data.
        3. branchctl commit feature.
        4. PDO connect again.
        5. Run plugin uninstall: DROP COLUMN + DELETE plugin data.
        6. branchctl rollback feature.
        7. Verify the plugin's column AND data are back.
        """
        site_fp = branched_db["site_fp"]
        fid     = branched_db["feature_id"]

        # Anchor commit at "fresh fork" state.
        assert branchctl(site_fp, "commit", "feature", "-m", "pre-plugin").returncode == 0

        # 2. Plugin install.
        r = php_branched_exec(site_fp, "feature", [
            f"ALTER TABLE b{fid}_wp_posts ADD COLUMN myplugin_meta TEXT",
            f"UPDATE b{fid}_wp_posts SET myplugin_meta='installed' WHERE post_title='Hello World'",
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) VALUES ('myplugin_active', '1')",
        ])
        assert r.returncode == 0, r.stderr
        payload = json.loads(r.stdout)
        assert payload.get("ok"), payload.get("error")

        # Sanity: column + plugin row visible.
        assert "myplugin_meta" in column_names(site_fp, f"b{fid}_wp_posts")
        rows = php_branched_query(site_fp, "feature",
            f"SELECT myplugin_meta FROM b{fid}_wp_posts WHERE post_title='Hello World'")
        assert rows and rows[0]["myplugin_meta"] == "installed"
        rows = sqlite_q(site_fp,
            f"SELECT 1 FROM b{fid}_wp_options WHERE option_name='myplugin_active'")
        assert rows

        # 3. Snapshot the post-install state.
        assert branchctl(site_fp, "commit", "feature", "-m", "plugin-installed").returncode == 0

        # 5. Uninstall.
        r = php_branched_exec(site_fp, "feature", [
            f"DELETE FROM b{fid}_wp_options WHERE option_name='myplugin_active'",
            f"ALTER TABLE b{fid}_wp_posts DROP COLUMN myplugin_meta",
        ])
        assert r.returncode == 0, r.stderr
        payload = json.loads(r.stdout)
        assert payload.get("ok"), payload.get("error")
        assert "myplugin_meta" not in column_names(site_fp, f"b{fid}_wp_posts")
        assert branchctl(site_fp, "commit", "feature", "-m", "plugin-uninstalled").returncode == 0

        # 6. Rollback (HEAD-1 = plugin-installed).
        r = branchctl(site_fp, "rollback", "feature", "--force")
        assert r.returncode == 0, f"rollback failed: {r.stdout}\n{r.stderr}"

        # 7. Column + data + plugin row all back.
        cols = column_names(site_fp, f"b{fid}_wp_posts")
        assert "myplugin_meta" in cols, f"plugin column not restored: {cols}"
        rows = sqlite_q(site_fp,
            f"SELECT myplugin_meta FROM b{fid}_wp_posts WHERE post_title='Hello World'")
        assert rows and rows[0][0] == "installed", (
            f"plugin column data not restored; got {rows}"
        )
        rows = sqlite_q(site_fp,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name='myplugin_active'")
        assert rows and rows[0][0] == "1", (
            f"plugin option not restored; got {rows}"
        )

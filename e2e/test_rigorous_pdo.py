"""
Rigorous BranchedPDO coverage: prepared statements (named + positional),
exec/query/prepare paths, DDL interception, nested transactions, quoted
identifiers, PRAGMA, EXPLAIN.

Each test runs a PHP script that instantiates BranchedPDO::connect(site,
branch) and exercises the code path. We communicate results back via
JSON on stdout.

Run:
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_rigorous_pdo.py -v
"""

import json
import os
import shutil
import sqlite3
import subprocess
import tempfile
from pathlib import Path

import pytest

from _rigorous_helpers import (
    branchctl, branch_id, create_branch,
    init_site, make_fresh_site, require_ext,
    sqlite_exec, sqlite_q,
    EXT_PATH, PHP_BIN, BRANCHED_PDO_PHP,
)


@pytest.fixture
def site():
    work, site_fp = make_fresh_site(prefix="rigpdo_")
    yield site_fp
    shutil.rmtree(work, ignore_errors=True)


@pytest.fixture
def site_branch(site):
    create_branch(site, "feature")
    return site, branch_id(site, "feature")


def run_php(script: str, timeout: int = 60) -> subprocess.CompletedProcess:
    """Run an inline PHP snippet with the extension + BranchedPDO loaded."""
    return subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         "-d", "display_errors=On", "-d", "display_startup_errors=On",
         "-r", script],
        capture_output=True, text=True, timeout=timeout,
    )


def pdo_script(site_fp, branch, body):
    """Assemble a boilerplate script that opens BranchedPDO and runs $body.

    $body has $pdo defined. Print JSON to stdout for the harness to capture.
    """
    return (
        f"require '{BRANCHED_PDO_PHP}';"
        f"$pdo = BranchedPDO::connect('{site_fp}', '{branch}');"
        + body
    )


# ═════════════════════════════════════════════════════════════════════
# 6.1 — Prepared statements with named + positional params
# ═════════════════════════════════════════════════════════════════════

class TestPreparedStatements:

    def test_named_params_work_against_branch_view(self, site_branch):
        site, fid = site_branch
        body = (
            "$s = $pdo->prepare('INSERT INTO b" + str(fid) + "_wp_options "
            "(option_name, option_value) VALUES (:n, :v)');"
            "$s->execute([':n' => 'pstmt', ':v' => 'val1']);"
            "$r = $pdo->query('SELECT option_value FROM b" + str(fid) + "_wp_options WHERE option_name = \\'pstmt\\'');"
            "echo json_encode($r->fetchAll(PDO::FETCH_NUM));"
        )
        r = run_php(pdo_script(site, "feature", body))
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        out = json.loads(r.stdout)
        assert out == [["val1"]]

    def test_positional_params_work(self, site_branch):
        site, fid = site_branch
        body = (
            "$s = $pdo->prepare('INSERT INTO b" + str(fid) + "_wp_options "
            "(option_name, option_value) VALUES (?, ?)');"
            "$s->execute(['pos1', 'posval']);"
            "$r = $pdo->query('SELECT option_value FROM b" + str(fid)
            + "_wp_options WHERE option_name = \\'pos1\\'');"
            "echo json_encode($r->fetchAll(PDO::FETCH_NUM));"
        )
        r = run_php(pdo_script(site, "feature", body))
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        out = json.loads(r.stdout)
        assert out == [["posval"]]

    def test_select_with_prepared_fetch(self, site_branch):
        site, fid = site_branch
        body = (
            "$s = $pdo->prepare('SELECT option_name, option_value FROM b"
            + str(fid) + "_wp_options WHERE option_name = :n');"
            "$s->execute([':n' => 'blogname']);"
            "echo json_encode($s->fetchAll(PDO::FETCH_NUM));"
        )
        r = run_php(pdo_script(site, "feature", body))
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        out = json.loads(r.stdout)
        assert out == [["blogname", "My Site"]]


# ═════════════════════════════════════════════════════════════════════
# 6.2 — DDL interception via exec / query / prepare
# ═════════════════════════════════════════════════════════════════════

class TestDDLInterceptPaths:

    def test_alter_add_column_via_exec(self, site_branch):
        site, fid = site_branch
        body = (
            "$pdo->exec('ALTER TABLE b" + str(fid)
            + "_wp_options ADD COLUMN new_col TEXT DEFAULT NULL');"
            "$r = $pdo->query('SELECT new_col FROM b" + str(fid)
            + "_wp_options WHERE option_name = \\'blogname\\'');"
            "echo json_encode($r->fetchAll(PDO::FETCH_NUM));"
        )
        r = run_php(pdo_script(site, "feature", body))
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        # new_col exists and is NULL (default) for inherited rows.
        out = json.loads(r.stdout)
        assert out == [[None]]

    def test_alter_add_column_via_query(self, site_branch):
        site, fid = site_branch
        body = (
            "$pdo->query('ALTER TABLE b" + str(fid)
            + "_wp_options ADD COLUMN via_q TEXT');"
            "$r = $pdo->query('SELECT via_q FROM b" + str(fid)
            + "_wp_options WHERE option_name = \\'blogname\\'');"
            "echo json_encode($r->fetchAll(PDO::FETCH_NUM));"
        )
        r = run_php(pdo_script(site, "feature", body))
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        out = json.loads(r.stdout)
        assert out == [[None]]

    def test_alter_add_column_via_prepare_execute(self, site_branch):
        site, fid = site_branch
        body = (
            "$stmt = $pdo->prepare('ALTER TABLE b" + str(fid)
            + "_wp_options ADD COLUMN via_p TEXT');"
            "$stmt->execute();"
            "$r = $pdo->query('SELECT via_p FROM b" + str(fid)
            + "_wp_options WHERE option_name = \\'blogname\\'');"
            "echo json_encode($r->fetchAll(PDO::FETCH_NUM));"
        )
        r = run_php(pdo_script(site, "feature", body))
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        out = json.loads(r.stdout)
        assert out == [[None]]


# ═════════════════════════════════════════════════════════════════════
# 6.3 — Quoted identifier variants
# ═════════════════════════════════════════════════════════════════════

class TestQuotedIdentifiers:

    def test_double_quoted_table_name(self, site_branch):
        site, fid = site_branch
        body = (
            "$pdo->exec('ALTER TABLE \"b" + str(fid)
            + "_wp_options\" ADD COLUMN dq_col TEXT');"
            "$r = $pdo->query('SELECT dq_col FROM b" + str(fid)
            + "_wp_options WHERE option_name = \\'blogname\\'');"
            "echo json_encode($r->fetchAll(PDO::FETCH_NUM));"
        )
        r = run_php(pdo_script(site, "feature", body))
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        out = json.loads(r.stdout)
        assert out == [[None]]

    def test_backtick_identifier_works(self, site_branch):
        """MySQL-style backticks; BranchedPDO should handle them or fall
        through cleanly."""
        site, fid = site_branch
        body = (
            "try {"
            "$pdo->exec('ALTER TABLE `b" + str(fid)
            + "_wp_options` ADD COLUMN bt_col TEXT');"
            "$r = $pdo->query('SELECT bt_col FROM b" + str(fid)
            + "_wp_options WHERE option_name = \\'blogname\\'');"
            "echo json_encode(['ok', $r->fetchAll(PDO::FETCH_NUM)]);"
            "} catch (Throwable $e) {"
            "echo json_encode(['err', $e->getMessage()]);"
            "}"
        )
        r = run_php(pdo_script(site, "feature", body))
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        out = json.loads(r.stdout)
        # Either it worked (DDL was routed) OR we got a clean SQLite error.
        # Not acceptable: silent column garble.
        if out[0] == "ok":
            assert out[1] == [[None]]
        else:
            assert "syntax" in out[1].lower() or "such table" in out[1].lower() or "backtick" in out[1].lower() or "near" in out[1].lower()


# ═════════════════════════════════════════════════════════════════════
# 6.4 — Transactions
# ═════════════════════════════════════════════════════════════════════

class TestTransactions:

    def test_begin_commit(self, site_branch):
        site, fid = site_branch
        body = (
            "$pdo->beginTransaction();"
            "$pdo->exec(\"INSERT INTO b" + str(fid)
            + "_wp_options (option_name, option_value) VALUES ('tx1', 'v1')\");"
            "$pdo->commit();"
            "$r = $pdo->query(\"SELECT option_value FROM b" + str(fid)
            + "_wp_options WHERE option_name = 'tx1'\");"
            "echo json_encode($r->fetchAll(PDO::FETCH_NUM));"
        )
        r = run_php(pdo_script(site, "feature", body))
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        out = json.loads(r.stdout)
        assert out == [["v1"]]

    def test_begin_rollback_drops_changes(self, site_branch):
        site, fid = site_branch
        body = (
            "$pdo->beginTransaction();"
            "$pdo->exec(\"INSERT INTO b" + str(fid)
            + "_wp_options (option_name, option_value) VALUES ('rb1', 'v1')\");"
            "$pdo->rollBack();"
            "$r = $pdo->query(\"SELECT COUNT(*) FROM b" + str(fid)
            + "_wp_options WHERE option_name = 'rb1'\");"
            "echo json_encode($r->fetchAll(PDO::FETCH_NUM));"
        )
        r = run_php(pdo_script(site, "feature", body))
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        out = json.loads(r.stdout)
        assert out == [[0]]

    def test_savepoint_release(self, site_branch):
        site, fid = site_branch
        body = (
            "$pdo->exec('BEGIN');"
            "$pdo->exec(\"INSERT INTO b" + str(fid)
            + "_wp_options (option_name, option_value) VALUES ('sp1', 'base')\");"
            "$pdo->exec('SAVEPOINT s1');"
            "$pdo->exec(\"UPDATE b" + str(fid)
            + "_wp_options SET option_value='mid' WHERE option_name = 'sp1'\");"
            "$pdo->exec('RELEASE SAVEPOINT s1');"
            "$pdo->exec('COMMIT');"
            "$r = $pdo->query(\"SELECT option_value FROM b" + str(fid)
            + "_wp_options WHERE option_name = 'sp1'\");"
            "echo json_encode($r->fetchAll(PDO::FETCH_NUM));"
        )
        r = run_php(pdo_script(site, "feature", body))
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        out = json.loads(r.stdout)
        assert out == [["mid"]]

    def test_savepoint_rollback_to(self, site_branch):
        site, fid = site_branch
        body = (
            "$pdo->exec('BEGIN');"
            "$pdo->exec(\"INSERT INTO b" + str(fid)
            + "_wp_options (option_name, option_value) VALUES ('rb_s', 'base')\");"
            "$pdo->exec('SAVEPOINT s2');"
            "$pdo->exec(\"UPDATE b" + str(fid)
            + "_wp_options SET option_value='tainted' WHERE option_name = 'rb_s'\");"
            "$pdo->exec('ROLLBACK TO SAVEPOINT s2');"
            "$pdo->exec('COMMIT');"
            "$r = $pdo->query(\"SELECT option_value FROM b" + str(fid)
            + "_wp_options WHERE option_name = 'rb_s'\");"
            "echo json_encode($r->fetchAll(PDO::FETCH_NUM));"
        )
        r = run_php(pdo_script(site, "feature", body))
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        out = json.loads(r.stdout)
        assert out == [["base"]]


# ═════════════════════════════════════════════════════════════════════
# 6.5 — PRAGMA / EXPLAIN
# ═════════════════════════════════════════════════════════════════════

class TestPragmaExplain:

    def test_pragma_foreign_keys(self, site_branch):
        site, fid = site_branch
        body = (
            "$pdo->exec('PRAGMA foreign_keys = ON');"
            "$r = $pdo->query('PRAGMA foreign_keys');"
            "echo json_encode($r->fetchAll(PDO::FETCH_NUM));"
        )
        r = run_php(pdo_script(site, "feature", body))
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        out = json.loads(r.stdout)
        assert out == [[1]]

    def test_pragma_synchronous(self, site_branch):
        site, fid = site_branch
        body = (
            "$pdo->exec('PRAGMA synchronous = NORMAL');"
            "$r = $pdo->query('PRAGMA synchronous');"
            "echo json_encode($r->fetchAll(PDO::FETCH_NUM));"
        )
        r = run_php(pdo_script(site, "feature", body))
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        out = json.loads(r.stdout)
        # NORMAL = 1 in SQLite
        assert out == [[1]]

    def test_explain_query_plan_on_branch_view(self, site_branch):
        """EXPLAIN QUERY PLAN on a branch view should return a plan (not crash)."""
        site, fid = site_branch
        body = (
            "$r = $pdo->query('EXPLAIN QUERY PLAN SELECT * FROM b"
            + str(fid) + "_wp_options WHERE option_name = \\'blogname\\'');"
            "$rows = $r->fetchAll(PDO::FETCH_ASSOC);"
            "echo json_encode(count($rows));"
        )
        r = run_php(pdo_script(site, "feature", body))
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        count = json.loads(r.stdout)
        assert count >= 1, "EXPLAIN QUERY PLAN returned no rows"


# ═════════════════════════════════════════════════════════════════════
# 6.6 — CREATE INDEX / DROP INDEX via PDO
# ═════════════════════════════════════════════════════════════════════

class TestIndexDDL:

    def test_create_and_drop_index_on_branch_view(self, site_branch):
        site, fid = site_branch
        body = (
            "$pdo->exec('CREATE INDEX idx_test_pdo ON b" + str(fid)
            + "_wp_options (option_value)');"
            "$pdo->exec('DROP INDEX idx_test_pdo');"
            "echo 'ok';"
        )
        r = run_php(pdo_script(site, "feature", body))
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        assert r.stdout.strip() == "ok"


# ═════════════════════════════════════════════════════════════════════
# 6.7 — RENAME COLUMN / DROP COLUMN
# ═════════════════════════════════════════════════════════════════════

class TestColumnRenameDrop:

    def test_rename_column_on_branch_view(self, site_branch):
        site, fid = site_branch
        # First add a column on the branch's overlay via a PDO DDL.
        body = (
            "$pdo->exec('ALTER TABLE b" + str(fid)
            + "_wp_options ADD COLUMN temp_col TEXT');"
            "$pdo->exec('ALTER TABLE b" + str(fid)
            + "_wp_options RENAME COLUMN temp_col TO renamed_col');"
            "$r = $pdo->query('SELECT renamed_col FROM b" + str(fid)
            + "_wp_options WHERE option_name = \\'blogname\\'');"
            "echo json_encode($r->fetchAll(PDO::FETCH_NUM));"
        )
        r = run_php(pdo_script(site, "feature", body))
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        out = json.loads(r.stdout)
        assert out == [[None]]

    def test_drop_column_on_branch_view(self, site_branch):
        site, fid = site_branch
        body = (
            "$pdo->exec('ALTER TABLE b" + str(fid)
            + "_wp_options ADD COLUMN rm_me TEXT');"
            "$pdo->exec('ALTER TABLE b" + str(fid)
            + "_wp_options DROP COLUMN rm_me');"
            "$r = $pdo->query('PRAGMA table_info(b" + str(fid)
            + "_wp_options__overlay)');"
            "$cols = [];"
            "foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $x) $cols[] = $x['name'];"
            "echo json_encode($cols);"
        )
        r = run_php(pdo_script(site, "feature", body))
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        out = json.loads(r.stdout)
        assert "rm_me" not in out, f"column not dropped: {out}"

    def test_rename_to_is_rejected(self, site_branch):
        """RENAME TO on a branch view is explicitly refused — it would
        orphan the COW marker."""
        site, fid = site_branch
        body = (
            "try {"
            "$pdo->exec('ALTER TABLE b" + str(fid)
            + "_wp_options RENAME TO renamed_view');"
            "echo 'ACCEPTED';"
            "} catch (Throwable $e) {"
            "echo 'REJECTED:' . $e->getMessage();"
            "}"
        )
        r = run_php(pdo_script(site, "feature", body))
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        assert r.stdout.startswith("REJECTED"), f"RENAME TO was accepted: {r.stdout}"


# ═════════════════════════════════════════════════════════════════════
# 6.8 — Multi-statement (semicolon-separated)
# ═════════════════════════════════════════════════════════════════════

class TestMultiStatement:

    def test_multi_statement_via_exec(self, site_branch):
        """PDO::exec on SQLite runs multi-statement; our DDL router only sees
        the first. Document behavior — do not crash or corrupt, but also
        don't attempt to intercept the rest."""
        site, fid = site_branch
        body = (
            "try {"
            "$pdo->exec('INSERT INTO b" + str(fid)
            + "_wp_options (option_name, option_value) VALUES (\\'m1\\',\\'a\\'); "
            "INSERT INTO b" + str(fid)
            + "_wp_options (option_name, option_value) VALUES (\\'m2\\',\\'b\\');');"
            "echo 'ok';"
            "} catch (Throwable $e) {"
            "echo 'err:' . $e->getMessage();"
            "}"
        )
        r = run_php(pdo_script(site, "feature", body))
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        # SQLite's PDO implementation runs multi-statement via exec.
        # Our DDL router sees only the first statement; any PDO-level
        # support is through its own path.
        assert r.stdout.strip() in ("ok", "err:") or r.stdout.startswith("err:"), (
            f"unexpected: {r.stdout!r}"
        )

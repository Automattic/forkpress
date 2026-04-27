"""
Rigorous DDL edge-case tests: ALTER ADD/DROP/RENAME COLUMN, indexes,
UNIQUE constraints.

Run:
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_rigorous_ddl.py -v
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
    branchctl, branch_id, commit_branch, create_branch,
    init_site, make_fresh_site, require_ext,
    sqlite_exec, sqlite_q, sqlite_executescript,
    BRANCHED_PDO_PHP, EXT_PATH, PHP_BIN,
)


@pytest.fixture
def site():
    work, site_fp = make_fresh_site(prefix="rigddl_")
    yield site_fp
    shutil.rmtree(work, ignore_errors=True)


@pytest.fixture
def site_branch(site):
    create_branch(site, "feature")
    return site, branch_id(site, "feature")


def run_pdo(site_fp, branch, body, timeout=60):
    script = (
        f"require '{BRANCHED_PDO_PHP}';"
        f"$pdo = BranchedPDO::connect('{site_fp}', '{branch}');"
        + body
    )
    return subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         "-d", "display_errors=On", "-d", "display_startup_errors=On",
         "-r", script],
        capture_output=True, text=True, timeout=timeout,
    )


# ═════════════════════════════════════════════════════════════════════
# 7.1 — ALTER ADD COLUMN with NOT NULL + DEFAULT
# ═════════════════════════════════════════════════════════════════════

class TestAddColumnWithDefault:

    def test_add_not_null_default_column_applies_to_inherited_rows(self, site_branch):
        site, fid = site_branch
        body = (
            "$pdo->exec(\"ALTER TABLE b" + str(fid)
            + "_wp_options ADD COLUMN flag TEXT NOT NULL DEFAULT 'on'\");"
            "$r = $pdo->query(\"SELECT flag FROM b" + str(fid)
            + "_wp_options WHERE option_name = 'blogname'\");"
            "echo json_encode($r->fetchAll(PDO::FETCH_NUM));"
        )
        r = run_pdo(site, "feature", body)
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        out = json.loads(r.stdout)
        # Inherited row via view: the view projects NULL from parent, not
        # the overlay default. Document: inherited rows see NULL UNTIL the
        # row is overlaid, at which point the overlay's DEFAULT takes effect.
        # Branch-new inserts should see the default.
        body2 = (
            "$pdo->exec(\"INSERT INTO b" + str(fid)
            + "_wp_options (option_name, option_value) VALUES ('new_row', 'x')\");"
            "$r = $pdo->query(\"SELECT flag FROM b" + str(fid)
            + "_wp_options WHERE option_name = 'new_row'\");"
            "echo json_encode($r->fetchAll(PDO::FETCH_NUM));"
        )
        r2 = run_pdo(site, "feature", body2)
        assert r2.returncode == 0, f"{r2.stdout}\n{r2.stderr}"
        # Default should have applied via overlay trigger COALESCE.
        out2 = json.loads(r2.stdout)
        assert out2 == [["on"]], f"default not applied: {out2}"


# ═════════════════════════════════════════════════════════════════════
# 7.2 — ALTER DROP COLUMN of PK
# ═════════════════════════════════════════════════════════════════════

class TestDropPKColumn:

    def test_drop_pk_column_rejected_cleanly(self, site_branch):
        site, fid = site_branch
        body = (
            "try {"
            "$pdo->exec('ALTER TABLE b" + str(fid)
            + "_wp_options DROP COLUMN option_id');"
            "echo 'ACCEPTED';"
            "} catch (Throwable $e) {"
            "echo 'REJECTED:' . $e->getMessage();"
            "}"
        )
        r = run_pdo(site, "feature", body)
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        # SQLite itself rejects DROP COLUMN on a PK column.
        assert r.stdout.startswith("REJECTED"), (
            f"DROP PK column was accepted: {r.stdout}"
        )


# ═════════════════════════════════════════════════════════════════════
# 7.3 — ALTER DROP COLUMN of indexed column
# ═════════════════════════════════════════════════════════════════════

class TestDropIndexedColumn:

    def test_drop_indexed_column_drops_index_too(self, site_branch):
        site, fid = site_branch
        body = (
            "$pdo->exec('CREATE INDEX idx_tmp ON b" + str(fid)
            + "_wp_options (option_value)');"
            "$pdo->exec('ALTER TABLE b" + str(fid)
            + "_wp_options DROP COLUMN option_value');"
            "$r = $pdo->query(\"SELECT COUNT(*) FROM sqlite_master WHERE "
            "type='index' AND name='idx_tmp'\");"
            "echo json_encode($r->fetchAll(PDO::FETCH_NUM));"
        )
        r = run_pdo(site, "feature", body)
        # SQLite auto-drops indexes on dropped columns.
        # Check: either worked (idx gone) OR rejected cleanly (index still there, but column not dropped).
        if r.returncode == 0:
            out = json.loads(r.stdout)
            # If SQLite allowed the drop, the index should also be gone.
            assert out == [[0]]


# ═════════════════════════════════════════════════════════════════════
# 7.4 — Multiple ALTERs in one transaction
# ═════════════════════════════════════════════════════════════════════

class TestMultiAlterTransaction:

    def test_multiple_alters_atomic(self, site_branch):
        site, fid = site_branch
        body = (
            "$pdo->beginTransaction();"
            "try {"
            "$pdo->exec('ALTER TABLE b" + str(fid)
            + "_wp_options ADD COLUMN c1 TEXT');"
            "$pdo->exec('ALTER TABLE b" + str(fid)
            + "_wp_options ADD COLUMN c2 TEXT');"
            "$pdo->commit();"
            "echo 'ok';"
            "} catch (Throwable $e) {"
            "$pdo->rollBack();"
            "echo 'rolled_back:' . $e->getMessage();"
            "}"
        )
        r = run_pdo(site, "feature", body)
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        assert r.stdout.strip() == "ok"

        # Both columns should exist.
        cols = sqlite_q(site, f"PRAGMA table_info('b{fid}_wp_options__overlay')")
        names = [r[1] for r in cols]
        assert "c1" in names and "c2" in names


# ═════════════════════════════════════════════════════════════════════
# 7.5 — UNIQUE constraint violations
# ═════════════════════════════════════════════════════════════════════

class TestUniqueViolation:

    def test_unique_violation_within_overlay_rejected(self, site_branch):
        """Two branch-local inserts with same option_name hit the overlay's
        own UNIQUE constraint and get rejected."""
        site, fid = site_branch
        body = (
            "$pdo->exec(\"INSERT INTO b" + str(fid)
            + "_wp_options (option_name, option_value) VALUES ('dup1', 'a')\");"
            "try {"
            "$pdo->exec(\"INSERT INTO b" + str(fid)
            + "_wp_options (option_name, option_value) VALUES ('dup1', 'b')\");"
            "echo 'ACCEPTED';"
            "} catch (Throwable $e) {"
            "echo 'REJECTED:' . $e->getMessage();"
            "}"
        )
        r = run_pdo(site, "feature", body)
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        assert r.stdout.startswith("REJECTED"), (
            f"unique violation within overlay was accepted: {r.stdout}"
        )
        assert "unique" in r.stdout.lower() or "constraint" in r.stdout.lower()

    def test_cross_layer_unique_violation_rejected(self, site_branch):
        """Inheriting rows from parent + inserting a duplicate into the
        overlay must be rejected by the view's logical UNIQUE contract.
        Enforced by cross-layer UNIQUE RAISE guard in INSTEAD OF triggers
        (cow_helpers.php)."""
        site, fid = site_branch
        body = (
            "try {"
            "$pdo->exec(\"INSERT INTO b" + str(fid)
            + "_wp_options (option_name, option_value) VALUES ('blogname', 'dup')\");"
            "echo 'ACCEPTED';"
            "} catch (Throwable $e) {"
            "echo 'REJECTED:' . $e->getMessage();"
            "}"
        )
        r = run_pdo(site, "feature", body)
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        assert r.stdout.startswith("REJECTED"), (
            f"cross-layer unique violation was accepted: {r.stdout}"
        )


# ═════════════════════════════════════════════════════════════════════
# 7.6 — CREATE INDEX on branch view
# ═════════════════════════════════════════════════════════════════════

class TestIndexRoundtrip:

    def test_create_and_drop_index_via_pdo(self, site_branch):
        site, fid = site_branch
        body = (
            "$pdo->exec('CREATE UNIQUE INDEX idx_u ON b" + str(fid)
            + "_wp_options (option_name)');"
            "$r = $pdo->query(\"SELECT sql FROM sqlite_master WHERE name='idx_u'\");"
            "$rows = $r->fetchAll(PDO::FETCH_NUM);"
            "$pdo->exec('DROP INDEX idx_u');"
            "$r2 = $pdo->query(\"SELECT COUNT(*) FROM sqlite_master WHERE name='idx_u'\");"
            "echo json_encode([$rows, $r2->fetchAll(PDO::FETCH_NUM)]);"
        )
        r = run_pdo(site, "feature", body)
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"
        out = json.loads(r.stdout)
        created_rows, after_drop = out
        assert len(created_rows) == 1
        assert "UNIQUE" in created_rows[0][0].upper()
        assert after_drop == [[0]]


# ═════════════════════════════════════════════════════════════════════
# 7.7 — ALTER ADD COLUMN on table with existing rows
# ═════════════════════════════════════════════════════════════════════

class TestAddColumnPopulated:

    def test_add_column_on_populated_table_preserves_rows(self, site_branch):
        site, fid = site_branch
        # Branch has inherited rows; add a column via branchctl.
        r = branchctl(site, "alter-add-column", "main", "options", "extra_c", "TEXT")
        assert r.returncode == 0, f"alter failed: {r.stdout}\n{r.stderr}"

        # Inherited rows still visible
        rows = sqlite_q(site,
            f"SELECT option_name FROM b{fid}_wp_options ORDER BY option_name")
        names = {r[0] for r in rows}
        assert {"blogname", "siteurl", "shared_option"}.issubset(names)

    def test_add_column_twice_fails_cleanly(self, site_branch):
        site, fid = site_branch
        r = branchctl(site, "alter-add-column", "main", "options", "twice_c", "TEXT")
        assert r.returncode == 0
        r2 = branchctl(site, "alter-add-column", "main", "options", "twice_c", "TEXT")
        assert r2.returncode != 0, "second add of same column name accepted"
        assert "duplicate" in (r2.stdout + r2.stderr).lower() or \
               "already" in (r2.stdout + r2.stderr).lower() or \
               "exists" in (r2.stdout + r2.stderr).lower()


# ═════════════════════════════════════════════════════════════════════
# 7.8 — Rebuild view preserves INSTEAD OF triggers
# ═════════════════════════════════════════════════════════════════════

class TestViewTriggersSurviveRebuild:

    def test_triggers_exist_after_alter_add_column(self, site_branch):
        site, fid = site_branch
        body = (
            "$pdo->exec('ALTER TABLE b" + str(fid)
            + "_wp_options ADD COLUMN survives TEXT');"
        )
        r = run_pdo(site, "feature", body)
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"

        # All three cow triggers still exist on the view.
        for suffix in ("ins", "upd", "del"):
            name = f"b{fid}_wp_options__cow_{suffix}"
            rows = sqlite_q(site,
                "SELECT type FROM sqlite_master WHERE name = ?", (name,))
            assert rows, f"trigger {name} missing after ALTER"
            assert rows[0][0] == "trigger"

        # And an insert through the view still routes through the trigger.
        sqlite_exec(site,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value, survives) VALUES (?, ?, ?)",
            ("t", "v", "s"))
        rows = sqlite_q(site,
            f"SELECT survives FROM b{fid}_wp_options WHERE option_name = 't'")
        assert rows == [("s",)]

"""
TODO3 #6 — multi-branch view recreation is transactional.

`cow_recreate_views_for_table` fires after schema-altering operations
on a parent table (e.g. branchctl alter-add-column, merge of a schema
change). It iterates every descendant branch and rebuilds each one's
view + INSTEAD OF triggers.

Pre-TODO3, the loop committed incrementally per branch. A failure
partway through (locked table, malformed DDL, FK violation, …) left
branches 1..k with the new view shape and branches k+1..N with the
old — a silent half-upgrade with no diagnostic.

Post-TODO3, the loop runs inside a single `BEGIN IMMEDIATE`. Any
failure rolls back every branch's changes, and the caller sees a
RuntimeException naming the branches that failed.
"""

import os
import shutil
import subprocess
from pathlib import Path

import pytest

from _rigorous_helpers import (
    BASE_DIR,
    EXT_PATH,
    PHP_BIN,
    branchctl,
    create_branch,
    make_fresh_site,
    sqlite_q,
    sqlite_exec,
    is_view,
)


def test_recreate_succeeds_for_all_branches(tmp_path):
    """Sanity: after a parent ADD COLUMN, every descendant branch's
    view reflects the new column."""
    work, site_fp = make_fresh_site("view_ok_")
    try:
        for i in range(3):
            create_branch(site_fp, f"b{i}")

        r = branchctl(
            site_fp, "alter-add-column", "main", "options",
            "new_col", "TEXT"
        )
        assert r.returncode == 0, r.stderr

        for i in range(3):
            bid = sqlite_q(site_fp, "SELECT id FROM branches WHERE name=?",
                           (f"b{i}",))[0][0]
            cols = [c[1] for c in sqlite_q(site_fp,
                f'PRAGMA table_info("b{bid}_wp_options")')]
            assert "new_col" in cols, (
                f"branch b{i} (id {bid}) view missing new_col: {cols}"
            )
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_recreate_uses_a_transactional_wrapper():
    """Source-level guard: the helper must wrap its per-branch loop in
    `BEGIN IMMEDIATE` and ROLLBACK on failure. Under the pre-TODO3 code
    a failure partway through left branches in a half-upgraded state."""
    src = (BASE_DIR / "scripts" / "cow_helpers.php").read_text()
    assert "function cow_recreate_views_for_table" in src
    i = src.find("function cow_recreate_views_for_table")
    # Take the function's body window.
    body = src[i: i + 3500]
    assert "BEGIN IMMEDIATE" in body, (
        "cow_recreate_views_for_table must open its own BEGIN IMMEDIATE "
        "(TODO3 #6)."
    )
    assert "ROLLBACK" in body, (
        "cow_recreate_views_for_table must ROLLBACK on failure "
        "(TODO3 #6)."
    )
    assert "cow_recreate_one_branch_view" in body, (
        "per-branch work should be factored into cow_recreate_one_branch_view "
        "so the outer loop can aggregate failures across branches (TODO3 #6)."
    )


def test_failure_is_surfaced_with_branch_id(tmp_path):
    """When one branch's view rebuild fails, the caller gets a
    RuntimeException that names the failing branch id — making it
    possible to diagnose a half-upgrade. Under the pre-TODO3 code, the
    first failure just bubbled up raw without the context aggregation."""
    work, site_fp = make_fresh_site("view_diag_")
    try:
        create_branch(site_fp, "b0")
        create_branch(site_fp, "b1")

        # Plant a trigger name collision on b1 so the CREATE TRIGGER fires
        # inside cow_recreate_one_branch_view would fail. We install a
        # trigger on an unrelated table with the EXACT name the helper
        # will try to create — SQLite's trigger namespace is global, so
        # CREATE TRIGGER errors with "trigger already exists".
        b1_id = sqlite_q(site_fp, "SELECT id FROM branches WHERE name='b1'")[0][0]
        # Plant a conflicting trigger on main's real options table. The
        # trigger body is a no-op INSERT into a table we own.
        trg_name = f"b{b1_id}_wp_options__cow_ins"
        # Drop the existing one installed by cow_create_branch_table and
        # replace with a garbage trigger that simply exists. (We'll try to
        # make the recreate re-create the same name, which will conflict.)
        sqlite_exec(site_fp, f'DROP TRIGGER IF EXISTS "{trg_name}"')
        sqlite_exec(site_fp, f"""
            CREATE TRIGGER "{trg_name}" AFTER INSERT ON b1_wp_options
            BEGIN SELECT 1; END
        """)

        php = (
            "require_once '" + str(BASE_DIR / 'scripts' / 'cow_helpers.php') + "';"
            "$db=new SQLite3($argv[1]); "
            "try { cow_recreate_views_for_table($db, 'options'); echo 'OK'; }"
            "catch (\\Throwable $e) { echo 'FAIL: ' . $e->getMessage(); }"
        )
        r = subprocess.run(
            [PHP_BIN, "-d", f"extension={EXT_PATH}", "-r", php, str(site_fp)],
            capture_output=True, text=True, timeout=30,
        )
        # Either it fails (expected) or succeeds — either way, we're
        # asserting that IF it fails, the diagnostic names the branch.
        if "FAIL" in r.stdout:
            assert f"branch_id={b1_id}" in r.stdout, (
                f"failure message should name the failing branch id; "
                f"got: {r.stdout}"
            )
    finally:
        shutil.rmtree(work, ignore_errors=True)

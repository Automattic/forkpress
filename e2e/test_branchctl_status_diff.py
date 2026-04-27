"""
TODO3 #11 — `branchctl status <branch>` and `branchctl diff <a> <b> --rows`.

The pre-TODO3 CLI had `branchctl diff` for file-side comparisons and
no way to inspect DB state at all. This round adds:

  * `branchctl status <branch>` — per-table summary (overlay row count,
    tombstone count) + clean/divergent flag for the DB side.
  * `branchctl diff <a> <b> --rows` — row-level diff between two branches'
    tables (+added / -removed / ~modified counts per table).
"""

import os
import shutil
import subprocess
from pathlib import Path

import pytest

from _rigorous_helpers import (
    branchctl,
    create_branch,
    make_fresh_site,
    sqlite_exec,
)


# ---------- branchctl status ----------------------------------------


def test_status_reports_per_table_counts(tmp_path):
    work, site_fp = make_fresh_site("st_")
    try:
        fid = create_branch(site_fp, "feature")
        # Create divergence: add one overlay row + delete one (tombstone).
        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) "
            "VALUES ('added', 'x')")
        sqlite_exec(site_fp,
            f"DELETE FROM b{fid}_wp_options WHERE option_name='blogname'")

        r = branchctl(site_fp, "status", "feature")
        assert r.returncode == 0, r.stderr
        # Table summary line contains a positive overlay count and a
        # positive tombstone count for 'options'.
        assert "options" in r.stdout
        # Row for options should have overlay >= 1 and tombs >= 1.
        lines = [l for l in r.stdout.splitlines() if l.strip().startswith("options")]
        assert lines, f"expected an 'options' row; got:\n{r.stdout}"
        parts = lines[0].split()
        ov = int(parts[-2]); tb = int(parts[-1])
        assert ov >= 1 and tb >= 1, (
            f"overlay={ov}, tombs={tb} should both be >= 1 after "
            f"divergence; saw line: {lines[0]!r}"
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_status_unknown_branch_errors(tmp_path):
    work, site_fp = make_fresh_site("st_nope_")
    try:
        r = branchctl(site_fp, "status", "nope")
        assert r.returncode != 0
        assert "no branch" in (r.stderr + r.stdout)
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_status_shows_clean_vs_divergent_db_state(tmp_path):
    work, site_fp = make_fresh_site("st_clean_")
    try:
        fid = create_branch(site_fp, "f")
        # Fresh branch has no db_commit yet but also no uncommitted changes.
        r = branchctl(site_fp, "commit", "f", "-m", "anchor")
        assert r.returncode == 0

        r = branchctl(site_fp, "status", "f")
        assert "clean" in r.stdout.lower(), r.stdout

        # Divergence after the commit.
        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) "
            "VALUES ('new', 'v')")
        r = branchctl(site_fp, "status", "f")
        assert "divergent" in r.stdout.lower() or "uncommitted" in r.stdout.lower(), (
            f"expected 'divergent' flag after a post-commit change; got:\n{r.stdout}"
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


# ---------- branchctl diff --rows --------------------------------------


def test_diff_db_reports_row_level_changes(tmp_path):
    work, site_fp = make_fresh_site("df_")
    try:
        a = create_branch(site_fp, "a")
        b = create_branch(site_fp, "b")
        # a adds a row, b modifies a different row.
        sqlite_exec(site_fp,
            f"INSERT INTO b{a}_wp_options (option_name, option_value) "
            "VALUES ('only_a', 'xa')")
        sqlite_exec(site_fp,
            f"UPDATE b{b}_wp_options SET option_value='modified' "
            "WHERE option_name='blogname'")

        r = branchctl(site_fp, "diff", "a", "b", "--rows")
        assert r.returncode == 0, r.stderr
        assert "options" in r.stdout, r.stdout
        # The count format is: "+<added> / -<removed> / ~<modified>".
        # a has the row b doesn't → '+' on b's side OR '-' on a's side
        # depending on sort. Either way, one of these signs appears.
        assert ("+1" in r.stdout) or ("-1" in r.stdout) or ("~1" in r.stdout), (
            f"expected per-table counts in diff --rows output; got:\n{r.stdout}"
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_diff_db_no_change_reports_clean(tmp_path):
    work, site_fp = make_fresh_site("df_clean_")
    try:
        create_branch(site_fp, "a")
        create_branch(site_fp, "b")
        r = branchctl(site_fp, "diff", "a", "b", "--rows")
        assert r.returncode == 0, r.stderr
        assert "no row differences" in r.stdout.lower(), (
            f"expected 'no row differences' when branches are identical; got:\n{r.stdout}"
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_diff_file_side_still_works(tmp_path):
    """Regression: `branchctl diff a b` (no --db) must keep the old
    file-side behaviour — this round only added the --db path."""
    work, site_fp = make_fresh_site("df_file_")
    try:
        create_branch(site_fp, "a")
        create_branch(site_fp, "b")
        r = branchctl(site_fp, "diff", "a", "b")
        assert r.returncode == 0, r.stderr
        assert "overlay diff" in r.stdout.lower(), (
            f"file-side diff should still announce itself; got:\n{r.stdout}"
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)

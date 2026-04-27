"""
TODO3 #4 — `branchctl migrate` on-demand legacy-branch migration.

Pre-TODO3, legacy (pre-COW) branches migrated to the overlay+view+
tombstone trio only on their first `branchctl merge`. A branch used
but never merged stayed in the O(rows × branches) full-copy format
forever.

Post-TODO3:

  * `branchctl migrate <branch>` migrates a single branch in place.
  * `branchctl migrate --all` scans every branch and migrates any
    leftover legacy ones.
  * `branchctl commit <branch>` auto-migrates before taking the
    snapshot — commits already touch every overlay so folding it in
    is free and guarantees eventual consistency for branches that
    commit but never merge.

All three paths reuse `cow_migrate_legacy_branch`, which is the same
function merge already called, so correctness is inherited.
"""

import os
import shutil
import sqlite3
import subprocess
from pathlib import Path

import pytest

from _rigorous_helpers import (
    branchctl,
    make_fresh_site,
    sqlite_q,
    sqlite_exec,
    is_view,
    is_table,
)
from test_cow_branches import make_legacy_branch


# ---------- branchctl migrate <name> ---------------------------------


def test_migrate_single_branch_converts_to_cow(tmp_path):
    work, site_fp = make_fresh_site("mig_single_")
    try:
        bid = make_legacy_branch(site_fp, "legacy_a")
        # Legacy: real tables under b{id}_wp_* prefix.
        assert is_table(site_fp, f"b{bid}_wp_options"), (
            "setup fixture should leave the branch as legacy (real tables)"
        )
        assert not is_view(site_fp, f"b{bid}_wp_options"), (
            "setup fixture should NOT have migrated the branch yet"
        )

        r = branchctl(site_fp, "migrate", "legacy_a")
        assert r.returncode == 0, r.stderr

        # After migrate: b{id}_wp_options is a view; overlay + tombstones exist.
        assert is_view(site_fp, f"b{bid}_wp_options"), (
            "after branchctl migrate, the logical name should be a view"
        )
        assert is_table(site_fp, f"b{bid}_wp_options__overlay"), (
            "after branchctl migrate, the overlay table should exist"
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_migrate_reports_noop_for_already_cow(tmp_path):
    """A COW-format branch is already migrated — the command must no-op."""
    work, site_fp = make_fresh_site("mig_noop_")
    try:
        # create is already COW.
        r = branchctl(site_fp, "create", "fresh")
        assert r.returncode == 0, r.stderr
        r = branchctl(site_fp, "migrate", "fresh")
        assert r.returncode == 0, r.stderr
        assert "already in COW format" in r.stdout
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_migrate_rejects_unknown_branch(tmp_path):
    work, site_fp = make_fresh_site("mig_unknown_")
    try:
        r = branchctl(site_fp, "migrate", "nope")
        assert r.returncode != 0
        assert "no branch named" in ((r.stderr or "") + (r.stdout or ""))
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_migrate_main_is_noop(tmp_path):
    work, site_fp = make_fresh_site("mig_main_")
    try:
        r = branchctl(site_fp, "migrate", "main")
        assert r.returncode == 0, r.stderr
        assert "does not need" in (r.stdout + r.stderr)
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_migrate_without_args_fails(tmp_path):
    work, site_fp = make_fresh_site("mig_args_")
    try:
        r = branchctl(site_fp, "migrate")
        assert r.returncode != 0
        assert "needs a branch name" in ((r.stderr or "") + (r.stdout or ""))
    finally:
        shutil.rmtree(work, ignore_errors=True)


# ---------- branchctl migrate --all ----------------------------------


def test_migrate_all_touches_every_legacy_branch(tmp_path):
    work, site_fp = make_fresh_site("mig_all_")
    try:
        a = make_legacy_branch(site_fp, "legacy_a")
        b = make_legacy_branch(site_fp, "legacy_b")
        # Add one already-COW branch too.
        r = branchctl(site_fp, "create", "cow_c")
        assert r.returncode == 0, r.stderr

        r = branchctl(site_fp, "migrate", "--all")
        assert r.returncode == 0, r.stderr

        for bid in [a, b]:
            assert is_view(site_fp, f"b{bid}_wp_options"), (
                f"branch id {bid} should have been migrated by --all"
            )
    finally:
        shutil.rmtree(work, ignore_errors=True)


# ---------- branchctl commit auto-migrates ---------------------------


def test_commit_auto_migrates_legacy_branch(tmp_path):
    """`branchctl commit` on a legacy branch must migrate it in-place
    before recording the snapshot. Branches that commit but never merge
    should eventually end up COW-formatted without a separate user step."""
    work, site_fp = make_fresh_site("mig_commit_")
    try:
        bid = make_legacy_branch(site_fp, "legacy_c")
        # Make a change so commit has work to do.
        sqlite_exec(site_fp,
            f"INSERT INTO b{bid}_wp_options (option_name, option_value) "
            "VALUES ('new', 'v')")

        r = branchctl(site_fp, "commit", "legacy_c", "-m", "auto-migrate on commit")
        assert r.returncode == 0, f"{r.stderr}\n{r.stdout}"
        assert "auto-migrated" in r.stdout, (
            "expected commit to announce the auto-migration; got:\n" + r.stdout
        )
        assert is_view(site_fp, f"b{bid}_wp_options"), (
            "after auto-migration the branch's logical table should be a view"
        )
        # db_commit should exist for the branch (commit went through after migrate).
        db_rows = sqlite_q(site_fp,
            "SELECT COUNT(*) FROM db_commits WHERE branch_id=?", (bid,))
        assert db_rows[0][0] >= 1, "commit should have recorded a db_commit row"
    finally:
        shutil.rmtree(work, ignore_errors=True)

"""
TODO3 #15 — `branchctl delete` cleans up every per-branch tracking table.

The existing `test_invariants` suite already covers db_snapshots /
db_cow_branches / db_ancestor_overlay / db_post_fork_inserts /
db_snapshots_schema. This round tightens the net:

  * opcache_invalidations (keyed by `branchfs://<branch>/<path>` URL)
    is purged on delete — important for high-churn CI preview sites.
  * Shared parent-tracking tables (db_parent_ancestor /
    db_parent_post_fork_inserts) survive the delete (they're keyed by
    parent_table_name, not branch_id — other siblings still need them).
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
    sqlite_q,
    sqlite_exec,
)


def test_opcache_invalidations_purged_on_delete(tmp_path):
    """opcache_invalidations rows for the deleted branch must be gone."""
    work, site_fp = make_fresh_site("gc_op_")
    try:
        fid = create_branch(site_fp, "churn")
        # Plant an opcache_invalidations entry as if a .php write had
        # fired through opcache_queue_invalidate.
        sqlite_exec(site_fp,
            "CREATE TABLE IF NOT EXISTS opcache_invalidations ("
            "id INTEGER PRIMARY KEY AUTOINCREMENT, url TEXT NOT NULL, "
            "created_at INTEGER NOT NULL DEFAULT 0)")
        sqlite_exec(site_fp,
            "INSERT INTO opcache_invalidations (url) VALUES ('branchfs://churn/foo.php')")
        sqlite_exec(site_fp,
            "INSERT INTO opcache_invalidations (url) VALUES ('branchfs://other/bar.php')")

        r = branchctl(site_fp, "delete", "churn")
        assert r.returncode == 0, r.stderr

        rows = sqlite_q(site_fp,
            "SELECT url FROM opcache_invalidations ORDER BY url")
        urls = [r[0] for r in rows]
        # Only the 'other' branch's entry should remain.
        assert urls == ["branchfs://other/bar.php"], (
            f"expected only 'other' branch's opcache URL to survive; got {urls}"
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_shared_parent_tables_survive_branch_delete(tmp_path):
    """db_parent_ancestor is shared across all descendants of a parent
    table. Deleting one branch must NOT drop these rows — siblings
    still need them."""
    work, site_fp = make_fresh_site("gc_shared_")
    try:
        create_branch(site_fp, "sib_a")
        create_branch(site_fp, "sib_b")
        # Parent write to populate db_parent_ancestor.
        sqlite_exec(site_fp,
            "UPDATE b1_wp_options SET option_value='x' WHERE option_name='blogname'")
        before = sqlite_q(site_fp,
            "SELECT COUNT(*) FROM db_parent_ancestor "
            "WHERE parent_table_name='b1_wp_options'")[0][0]
        assert before >= 1

        # Delete one branch — the shared row should stay.
        r = branchctl(site_fp, "delete", "sib_a")
        assert r.returncode == 0, r.stderr

        after = sqlite_q(site_fp,
            "SELECT COUNT(*) FROM db_parent_ancestor "
            "WHERE parent_table_name='b1_wp_options'")[0][0]
        assert after == before, (
            f"shared db_parent_ancestor rows should survive branch delete "
            f"(other siblings need them); before={before} after={after}"
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_per_branch_tables_are_all_purged_on_delete(tmp_path):
    """Sanity check: every per-branch tracking table covered by TODO3 #15
    is empty for the deleted branch's id after delete."""
    work, site_fp = make_fresh_site("gc_all_")
    try:
        fid = create_branch(site_fp, "to_delete")
        # Make the branch write something so ancestor / post_fork_inserts
        # have plausible rows to start with.
        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) "
            "VALUES ('k','v')")
        r = branchctl(site_fp, "commit", "to_delete", "-m", "c1")
        assert r.returncode == 0

        r = branchctl(site_fp, "delete", "to_delete")
        assert r.returncode == 0, r.stderr

        # No row left in any of these per-branch tables.
        for table in (
            "db_cow_branches", "db_ancestor_overlay", "db_post_fork_inserts",
            "db_snapshots_schema", "db_snapshots", "db_commits",
            "fs_commits", "files", "branches",
        ):
            try:
                if table == "branches":
                    n = sqlite_q(site_fp,
                        f"SELECT COUNT(*) FROM {table} WHERE id=?", (fid,))[0][0]
                else:
                    n = sqlite_q(site_fp,
                        f"SELECT COUNT(*) FROM {table} WHERE branch_id=?", (fid,))[0][0]
            except Exception:
                continue
            assert n == 0, (
                f"branch {fid} still has {n} rows in {table} after delete "
                f"(TODO3 #15)"
            )
    finally:
        shutil.rmtree(work, ignore_errors=True)

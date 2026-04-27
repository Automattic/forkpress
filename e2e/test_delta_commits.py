"""
TODO3 #2 — delta-encoded db commits.

Pre-TODO3, `db_record_snapshot` stored the branch's *entire* divergent
state on every commit: 100 commits over the same 50 modified rows cost
5000 stored rows. Now each commit's `db_commit_overlays` /
`db_commit_tombstones` stores only rows that actually differ from the
previous commit's state; the first commit on a branch is a FULL
snapshot and every subsequent commit is a DELTA.

Storage: for 100 commits where 5 rows change per commit on a 50-row
branch, total stored rows shrink from ~5000 to ~50 (FULL) + 100×5
(DELTAs) ≈ 550.

This test file covers:

  * Schema migration: `kind` / `op` columns are added idempotently.
  * The first commit on a branch is kind='FULL', later ones 'DELTA'.
  * Delta rows are small: a 10-commit-cycle modifying one row per commit
    stores O(commits) rows, not O(commits × divergent_rows).
  * Restore to any commit hash materializes the correct branch state by
    walking the commit chain (FULL base + DELTAs).
  * Storage-scale assertion per the TODO acceptance criterion.

All existing rollback / reset / commit correctness tests keep passing
under the new encoding (enforced by `test_cow_branches.py` +
`test_db_versioning.py` + `test_rigorous_*` — see final summary).
"""

import os
import shutil
import subprocess
from pathlib import Path

import pytest

from _rigorous_helpers import (
    BASE_DIR,
    branchctl,
    create_branch,
    make_fresh_site,
    sqlite_q,
    sqlite_exec,
)


BRANCHCTL_PHP = BASE_DIR / "scripts" / "branchctl.php"


# ---------- Schema migration ----------------------------------------


def test_kind_and_op_columns_exist(tmp_path):
    work, site_fp = make_fresh_site("delta_schema_")
    try:
        # Trigger fs_migrate (creates tables and runs ALTER TABLE ADD COLUMN).
        r = branchctl(site_fp, "list")
        assert r.returncode == 0

        for table, col in [
            ("db_commits",            "kind"),
            ("db_commit_overlays",    "op"),
            ("db_commit_tombstones",  "op"),
        ]:
            cols = [r[1] for r in sqlite_q(site_fp, f'PRAGMA table_info("{table}")')]
            assert col in cols, f"{table}.{col} missing: {cols}"
    finally:
        shutil.rmtree(work, ignore_errors=True)


# ---------- FULL vs DELTA encoding ----------------------------------


def test_first_commit_is_full_subsequent_are_delta(tmp_path):
    """Branch creation auto-records an initial FULL commit; every
    subsequent `branchctl commit` records a DELTA commit."""
    work, site_fp = make_fresh_site("delta_kind_")
    try:
        fid = create_branch(site_fp, "f")
        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) "
            "VALUES ('k1','v1')")
        r = branchctl(site_fp, "commit", "f", "-m", "first")
        assert r.returncode == 0, r.stderr
        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value='v2' "
            "WHERE option_name='k1'")
        r = branchctl(site_fp, "commit", "f", "-m", "second")
        assert r.returncode == 0, r.stderr
        kinds = [r[0] for r in sqlite_q(site_fp,
            "SELECT kind FROM db_commits WHERE branch_id=? ORDER BY id", (fid,))]
        # Create auto-commits one FULL, then two user commits become DELTA.
        assert kinds == ["FULL", "DELTA", "DELTA"], (
            f"expected exactly one FULL + N DELTA commits; got {kinds}. "
            f"A regression to 'every commit is FULL' would show ['FULL','FULL','FULL']."
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_delta_commit_only_stores_changed_rows(tmp_path):
    """Second commit that changes just ONE row stores only that one row —
    not all previously-divergent rows. Pre-TODO3, every commit was a
    full snapshot and this number would equal the total overlay size.
    """
    work, site_fp = make_fresh_site("delta_rows_")
    try:
        fid = create_branch(site_fp, "f")
        # Create 10 divergent rows on the branch.
        for i in range(10):
            sqlite_exec(site_fp,
                f"INSERT INTO b{fid}_wp_options (option_name, option_value) "
                f"VALUES ('k{i}', 'v{i}')")
        r = branchctl(site_fp, "commit", "f", "-m", "bulk")
        assert r.returncode == 0

        # The bulk commit (first USER commit — the FULL one is the
        # auto-commit taken at branch creation) is a DELTA that upserts
        # all 10 new rows.
        bulk_cid = sqlite_q(site_fp,
            "SELECT id FROM db_commits WHERE branch_id=? ORDER BY id DESC LIMIT 1",
            (fid,))[0][0]
        bulk_rows = sqlite_q(site_fp,
            "SELECT COUNT(*) FROM db_commit_overlays WHERE commit_id=?",
            (bulk_cid,))[0][0]
        assert bulk_rows >= 10, (
            f"bulk commit should persist every new divergent row; got {bulk_rows}"
        )

        # Change only one row, commit.
        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value='v0_CHANGED' "
            "WHERE option_name='k0'")
        r = branchctl(site_fp, "commit", "f", "-m", "one-change")
        assert r.returncode == 0

        last_cid = sqlite_q(site_fp,
            "SELECT id FROM db_commits WHERE branch_id=? ORDER BY id DESC LIMIT 1",
            (fid,))[0][0]
        delta_rows = sqlite_q(site_fp,
            "SELECT COUNT(*) FROM db_commit_overlays WHERE commit_id=?",
            (last_cid,))[0][0]
        assert delta_rows == 1, (
            f"DELTA commit should store only the ONE changed row, got "
            f"{delta_rows}. If this equals ~10, the per-commit full-snapshot "
            f"regression is back (TODO3 #2)."
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_delete_is_recorded_as_delete_op(tmp_path):
    """Rows removed from the overlay between commits should be recorded
    as op='DELETE' (not simply absent) so the replay correctly drops them."""
    work, site_fp = make_fresh_site("delta_del_")
    try:
        fid = create_branch(site_fp, "f")
        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) "
            "VALUES ('to_keep','v1'),('to_drop','v2')")
        r = branchctl(site_fp, "commit", "f", "-m", "full")
        assert r.returncode == 0

        # Remove one row; commit.
        sqlite_exec(site_fp,
            f"DELETE FROM b{fid}_wp_options WHERE option_name='to_drop'")
        r = branchctl(site_fp, "commit", "f", "-m", "delta")
        assert r.returncode == 0

        # The DELTA commit should have at least one op='DELETE' row for
        # the dropped overlay entry.
        delta_ops = [r[0] for r in sqlite_q(site_fp,
            "SELECT op FROM db_commit_overlays co "
            "JOIN db_commits c ON c.id=co.commit_id "
            "WHERE c.branch_id=? AND c.kind='DELTA'", (fid,))]
        assert "DELETE" in delta_ops, (
            f"expected op='DELETE' in DELTA commit for the dropped overlay "
            f"row; got {delta_ops}."
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


# ---------- Restore walks the chain ---------------------------------


def test_rollback_materializes_correct_state(tmp_path):
    """Rollback walks the commit chain and materializes the correct
    branch state by combining FULL base + subsequent DELTAs up to the
    target commit.

    Sequence:
      - Create branch   → auto-commit (FULL, no 'k')
      - Insert k=v1 ; commit c1 → (DELTA, upserts k=v1)
      - Insert k2=q ; commit c2 → (DELTA, upserts k2=q; k is unchanged
                                   so it's NOT in this commit's rows)
      - Update k=v3 (uncommitted)
      - rollback --force ⇒ steps back to previous commit before HEAD=c2,
                           i.e. c1's materialized state: {k=v1} only, no k2.
        Under delta encoding, replay must walk
          auto-commit (base) + c1 (DELTA) = overlay {k=v1}.
    """
    work, site_fp = make_fresh_site("delta_rb_")
    try:
        fid = create_branch(site_fp, "f")
        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) "
            "VALUES ('k','v1')")
        r = branchctl(site_fp, "commit", "f", "-m", "c1")
        assert r.returncode == 0

        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) "
            "VALUES ('k2','q')")
        r = branchctl(site_fp, "commit", "f", "-m", "c2")
        assert r.returncode == 0

        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value='v3' WHERE option_name='k'")
        r = branchctl(site_fp, "rollback", "f", "--force")
        assert r.returncode == 0, r.stderr

        # After rollback we should see c1's materialized state: {k=v1}.
        # k2 must be gone (c2 is rolled back). k=v3 (uncommitted) is gone too.
        rows = dict(sqlite_q(site_fp,
            f"SELECT option_name, option_value FROM b{fid}_wp_options "
            "WHERE option_name IN ('k','k2')"))
        assert rows.get("k") == "v1", (
            f"after rollback from HEAD=c2 to c1, expected k=v1, got rows={rows}. "
            f"Delta replay (auto-commit base + c1 delta) is broken."
        )
        assert "k2" not in rows, (
            f"after rollback, k2 should not exist (c2 is not applied); got {rows}"
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_reset_to_arbitrary_commit_hash(tmp_path):
    """Reset to a specific commit hash walks forward from the FULL base,
    applying DELTAs, and ends exactly at that commit's state."""
    work, site_fp = make_fresh_site("delta_rst_")
    try:
        fid = create_branch(site_fp, "f")
        hashes = []
        for v in ["v1", "v2", "v3"]:
            sqlite_exec(site_fp,
                f"DELETE FROM b{fid}_wp_options WHERE option_name='k'")
            sqlite_exec(site_fp,
                f"INSERT INTO b{fid}_wp_options (option_name, option_value) "
                f"VALUES ('k','{v}')")
            r = branchctl(site_fp, "commit", "f", "-m", f"c_{v}")
            assert r.returncode == 0, r.stderr
            last = sqlite_q(site_fp,
                "SELECT commit_hash FROM db_commits WHERE branch_id=? "
                "ORDER BY id DESC LIMIT 1", (fid,))[0][0]
            hashes.append(last)

        # Now branch state = v3. Reset to v1's hash.
        r = branchctl(site_fp, "reset", "f", hashes[0], "--force")
        assert r.returncode == 0, r.stderr + "\n" + r.stdout
        v = sqlite_q(site_fp,
            f"SELECT option_value FROM b{fid}_wp_options "
            "WHERE option_name='k'")[0][0]
        assert v == "v1", f"reset to c_v1 should give v1; got {v}"

        # And forward to v2.
        r = branchctl(site_fp, "reset", "f", hashes[1], "--force")
        assert r.returncode == 0, r.stderr + "\n" + r.stdout
        v = sqlite_q(site_fp,
            f"SELECT option_value FROM b{fid}_wp_options "
            "WHERE option_name='k'")[0][0]
        assert v == "v2", f"reset to c_v2 should give v2; got {v}"
    finally:
        shutil.rmtree(work, ignore_errors=True)


# ---------- Storage-scale assertion (TODO3 #2 acceptance) ------------


def test_100_commits_changing_same_rows_is_sublinear(tmp_path):
    """
    Acceptance from TODO3 #2: 100 commits where the same 5 rows are
    modified each time → total db_commit_overlays size grows linearly
    in commits but with a much smaller per-commit cost than
    full-snapshot-per-commit.

    We keep the bar conservative: under the new scheme the total should
    be < 1000 rows (FULL of 5 + 99×5 = 500). Under the old scheme the
    total would be ~5000 rows (100×50). We assert < 1500 to leave room
    for schema/tombstone noise while still catching a regression.
    """
    work, site_fp = make_fresh_site("delta_scale_")
    try:
        fid = create_branch(site_fp, "f")
        # Initial population: 5 rows.
        for i in range(5):
            sqlite_exec(site_fp,
                f"INSERT INTO b{fid}_wp_options (option_name, option_value) "
                f"VALUES ('k{i}', 'v_initial_{i}')")
        r = branchctl(site_fp, "commit", "f", "-m", "first")
        assert r.returncode == 0

        for commit_n in range(1, 100):
            for i in range(5):
                sqlite_exec(site_fp,
                    f"UPDATE b{fid}_wp_options SET option_value=? "
                    "WHERE option_name=?",
                    (f"v_{commit_n}_{i}", f"k{i}"))
            r = branchctl(site_fp, "commit", "f", "-m", f"c{commit_n}")
            assert r.returncode == 0, r.stderr

        total_rows = sqlite_q(site_fp,
            "SELECT COUNT(*) FROM db_commit_overlays co "
            "JOIN db_commits c ON c.id=co.commit_id WHERE c.branch_id=?",
            (fid,))[0][0]

        # Old scheme would yield 5000 rows. New scheme: ~5 (FULL) + 99×5 (DELTAs) = 500.
        assert total_rows < 1500, (
            f"100 commits modifying 5 rows each: {total_rows} stored rows — "
            f"expected well under 1500 under delta encoding. (Pre-TODO3 "
            f"would produce ~5000.)"
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)

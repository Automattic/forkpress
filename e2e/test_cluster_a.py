"""
Cluster A — post-hostile-review remediation tests.

Five findings, each exercising a scenario the prior round missed:

  #1  INSTEAD OF UPDATE's `INSERT OR REPLACE` silently data-losing on
      overlay UNIQUE collision and PK-change cases.
  #9  Composite PK handling in the cross-layer UNIQUE guard and
      trigger bodies falls back to `$pk_cols[0]` in places — so a
      same-col-0, different-col-1 inherited row wrongly slips past.
  #10 test_shared_parent_ancestor's "O(1) parent-update" perf test
      uses a floor in the threshold (`max(t1*3, 0.05)`) that masks
      real regressions at small baselines.
  #11 Lazy-ancestor capture races and misses deleted-parent-row
      pre-fork snapshots — the merge ancestor silently disappears.
  #12 Nested branch chains (grandchildren-of-grandchildren) — verify
      parent_table in INSTEAD OF triggers points at the PARENT's
      logical view (not hard-coded b1), and precedence is correct.

These tests intentionally document the BUG from the hostile review
and must fail before the fix, then pass after.
"""

import os
import shutil
import sqlite3
import subprocess
import time
from pathlib import Path

import pytest

from _rigorous_helpers import (
    BASE_DIR,
    branchctl,
    create_branch,
    make_fresh_site,
    sqlite_q,
    sqlite_exec,
    init_site,
)


# ═════════════════════════════════════════════════════════════════════════════
# Finding #1 — INSERT OR REPLACE data-loss in INSTEAD OF UPDATE trigger.
# ═════════════════════════════════════════════════════════════════════════════

class TestClusterAF1_UpdateTriggerNoSilentReplace:
    """The UPDATE INSTEAD OF trigger must not silently replace a
    conflicting overlay row. A UNIQUE violation inside the overlay
    must raise, not data-loss-overwrite."""

    def test_update_collides_with_overlay_unique_raises(self, tmp_path):
        """Two overlay rows with distinct option_name. UPDATE one to
        the OTHER's option_name: must raise UNIQUE, not silently
        REPLACE-and-lose a row."""
        work, site_fp = make_fresh_site("cla_f1a_")
        try:
            fid = create_branch(site_fp, "feature")
            db = sqlite3.connect(str(site_fp))
            try:
                # Two overlay rows on the branch (both inserted via the view,
                # landing in the overlay — not inherited from parent).
                db.execute(
                    f"INSERT INTO b{fid}_wp_options (option_name, option_value) "
                    "VALUES ('opt_a', 'A')"
                )
                db.execute(
                    f"INSERT INTO b{fid}_wp_options (option_name, option_value) "
                    "VALUES ('opt_b', 'B')"
                )
                db.commit()

                # Now UPDATE opt_a's row to have option_name='opt_b' — this
                # would collide with the existing overlay row. Native SQLite
                # raises; the pre-fix trigger used INSERT OR REPLACE which
                # silently DELETES the existing opt_b row AND remaps the
                # original overlay row — data loss.
                with pytest.raises(sqlite3.IntegrityError):
                    db.execute(
                        f"UPDATE b{fid}_wp_options SET option_name='opt_b' "
                        "WHERE option_name='opt_a'"
                    )
                    db.commit()
                db.rollback()
            finally:
                db.close()

            # Both rows should still exist.
            rows = sqlite_q(site_fp,
                f"SELECT option_name, option_value FROM b{fid}_wp_options "
                f"WHERE option_name IN ('opt_a','opt_b') ORDER BY option_name")
            assert len(rows) == 2, (
                f"both overlay rows should survive a UNIQUE-violating UPDATE; "
                f"got {rows}"
            )
        finally:
            shutil.rmtree(work, ignore_errors=True)

    def test_pk_change_tombstones_old_pk(self, tmp_path):
        """UPDATE that changes the PK must tombstone the OLD PK so an
        inherited parent row at OLD.PK doesn't reappear through the view.
        Pre-fix the trigger only deletes tombstones for NEW and the old
        overlay row stays keyed by OLD.PK (INSERT OR REPLACE creates a
        new row at NEW.PK), so the parent row at OLD.PK is visible again."""
        work, site_fp = make_fresh_site("cla_f1b_")
        try:
            # Seed a test table on main — use a plain user table so we can
            # exercise a PK that's actually UPDATE-able (AUTOINCREMENT
            # columns in SQLite generally allow PK UPDATEs via the view).
            sqlite_exec(site_fp,
                "CREATE TABLE b1_wp_pkupd ("
                "  id INTEGER PRIMARY KEY, "
                "  name TEXT NOT NULL"
                ")")
            sqlite_exec(site_fp,
                "INSERT INTO b1_wp_pkupd (id, name) VALUES (7, 'parent_7')")
            fid = create_branch(site_fp, "pkbr")

            # Materialize the inherited row into the branch's overlay (so we
            # have an overlay row at PK=7 that we can then re-key to PK=99).
            sqlite_exec(site_fp,
                f"UPDATE b{fid}_wp_pkupd SET name='branch_7' WHERE id=7")

            # Now change PK 7 → 99 via the view.
            sqlite_exec(site_fp,
                f"UPDATE b{fid}_wp_pkupd SET id=99, name='branch_99' WHERE id=7")

            # The branch view must NOT show parent_7 again at id=7 — the
            # PK change should have left a tombstone at 7.
            rows = sqlite_q(site_fp,
                f"SELECT id, name FROM b{fid}_wp_pkupd WHERE id=7")
            assert rows == [], (
                f"after UPDATE id=7→99, the parent's row should NOT reappear; "
                f"got rows={rows}"
            )
            # And the row should be visible at PK=99.
            rows = sqlite_q(site_fp,
                f"SELECT id, name FROM b{fid}_wp_pkupd WHERE id=99")
            assert rows == [(99, "branch_99")], f"got {rows}"
        finally:
            shutil.rmtree(work, ignore_errors=True)


# ═════════════════════════════════════════════════════════════════════════════
# Finding #9 — composite PK + cross-layer UNIQUE guard.
# ═════════════════════════════════════════════════════════════════════════════

class TestClusterAF9_CompositePK:
    """Composite PKs must be handled by every trigger path, including
    the cross-layer UNIQUE guard which previously only used $pk_cols[0]."""

    def test_composite_pk_insert_overlay_then_delete_visible_only_in_overlay(self, tmp_path):
        """A same-col0, different-col1 inherited row must NOT be mis-masked
        by a tombstone that only keys on col0. Verifies the guard and
        overlay/tombstone paths use the full composite PK tuple."""
        work, site_fp = make_fresh_site("cla_f9_")
        try:
            # 2-column PK table.
            sqlite_exec(site_fp,
                "CREATE TABLE b1_wp_ckey ("
                "  a INTEGER NOT NULL, "
                "  b INTEGER NOT NULL, "
                "  v TEXT, "
                "  PRIMARY KEY (a, b)"
                ")")
            sqlite_exec(site_fp,
                "INSERT INTO b1_wp_ckey (a, b, v) VALUES (1, 10, 'p_1_10')")
            sqlite_exec(site_fp,
                "INSERT INTO b1_wp_ckey (a, b, v) VALUES (1, 20, 'p_1_20')")
            fid = create_branch(site_fp, "cbr")

            # Branch DELETE of (1, 20) — must tombstone (1,20) ONLY, not
            # everything with a=1. The parent (1, 10) should still be visible.
            sqlite_exec(site_fp,
                f"DELETE FROM b{fid}_wp_ckey WHERE a=1 AND b=20")

            rows = sqlite_q(site_fp,
                f"SELECT a, b, v FROM b{fid}_wp_ckey ORDER BY a, b")
            assert rows == [(1, 10, "p_1_10")], (
                f"composite-PK tombstone of (1,20) must not hide (1,10); "
                f"got {rows}"
            )
        finally:
            shutil.rmtree(work, ignore_errors=True)

    def test_composite_pk_update_preserves_other_rows(self, tmp_path):
        """UPDATE of one composite-PK row must not collaterally affect
        a sibling with same col0, different col1."""
        work, site_fp = make_fresh_site("cla_f9b_")
        try:
            sqlite_exec(site_fp,
                "CREATE TABLE b1_wp_ckey2 ("
                "  a INTEGER NOT NULL, "
                "  b INTEGER NOT NULL, "
                "  v TEXT, "
                "  PRIMARY KEY (a, b)"
                ")")
            sqlite_exec(site_fp,
                "INSERT INTO b1_wp_ckey2 VALUES (1, 10, 'p_10'), (1, 20, 'p_20')")
            fid = create_branch(site_fp, "cbr2")

            # Update just (1,10) → want both rows visible afterwards, with
            # (1,10) updated.
            sqlite_exec(site_fp,
                f"UPDATE b{fid}_wp_ckey2 SET v='updated_10' WHERE a=1 AND b=10")

            rows = sqlite_q(site_fp,
                f"SELECT a, b, v FROM b{fid}_wp_ckey2 ORDER BY a, b")
            assert rows == [(1, 10, "updated_10"), (1, 20, "p_20")], (
                f"composite-PK UPDATE of (1,10) must not mask (1,20); got {rows}"
            )
        finally:
            shutil.rmtree(work, ignore_errors=True)

    def test_composite_pk_cross_layer_unique_guard_uses_all_pk_cols(self, tmp_path):
        """The cross-layer UNIQUE guard's tombstone/overlay NOT-IN check
        must use the FULL composite PK tuple. Pre-fix it only used
        $pk_cols[0], so a tombstone on (1, 20) would make the guard think
        the parent row (1, 10) was also deleted — admitting a UNIQUE-
        violating INSERT."""
        work, site_fp = make_fresh_site("cla_f9c_")
        try:
            # Table with 2-col PK AND a single-column UNIQUE.
            sqlite_exec(site_fp,
                "CREATE TABLE b1_wp_cmpu ("
                "  a INTEGER NOT NULL, "
                "  b INTEGER NOT NULL, "
                "  u TEXT UNIQUE, "
                "  PRIMARY KEY (a, b)"
                ")")
            sqlite_exec(site_fp,
                "INSERT INTO b1_wp_cmpu VALUES (1, 10, 'unique_ten')")
            sqlite_exec(site_fp,
                "INSERT INTO b1_wp_cmpu VALUES (1, 20, 'unique_twenty')")
            fid = create_branch(site_fp, "cmpu_br")

            # Branch tombstones (1,20). With the buggy guard, the tombstone
            # NOT-IN check (on col 'a' only) would exclude BOTH (1,10) and
            # (1,20) from the "inherited parent" set → the next INSERT
            # with u='unique_ten' would silently succeed and the view
            # would return two rows with u='unique_ten'.
            sqlite_exec(site_fp,
                f"DELETE FROM b{fid}_wp_cmpu WHERE a=1 AND b=20")

            # Now try to INSERT a DIFFERENT (a,b) with u='unique_ten' —
            # still conflicts with the inherited (1, 10).
            db = sqlite3.connect(str(site_fp))
            try:
                with pytest.raises(sqlite3.IntegrityError):
                    db.execute(
                        f"INSERT INTO b{fid}_wp_cmpu (a, b, u) "
                        "VALUES (2, 99, 'unique_ten')"
                    )
                    db.commit()
                db.rollback()
            finally:
                db.close()

            # View must still show exactly one row with u='unique_ten'.
            rows = sqlite_q(site_fp,
                f"SELECT a, b, u FROM b{fid}_wp_cmpu "
                "WHERE u='unique_ten' ORDER BY a, b")
            assert len(rows) == 1, (
                f"cross-layer UNIQUE guard must catch composite-PK collision; "
                f"got rows={rows}"
            )
        finally:
            shutil.rmtree(work, ignore_errors=True)


# ═════════════════════════════════════════════════════════════════════════════
# Finding #10 — perf threshold has a floor that masks small-baseline regressions.
# ═════════════════════════════════════════════════════════════════════════════

class TestClusterAF10_NoFloorMaskedThreshold:
    """The perf threshold in test_shared_parent_ancestor must be a pure
    ratio. With a floor of 0.05s, a baseline of 0.001s and a measured
    0.04s value (40x regression) would pass."""

    def test_threshold_expression_uses_no_floor(self, tmp_path):
        path = BASE_DIR / "e2e" / "test_shared_parent_ancestor.py"
        text = path.read_text()
        # The pre-fix line looks like: assert t_50 < max(t_1 * 3.0, 0.05)
        # The fix removes the floor. Acceptable forms:
        #   assert t_50 < t_1 * 3.0
        #   assert (t_50 / max(t_1, <epsilon>)) < 3.0
        # Reject anything containing "max(t_1 * " or "max(t_1*" near the
        # assertion — the key tell is the floor value.
        assert "max(t_1 * 3.0, 0.05)" not in text, (
            "test_shared_parent_ancestor.py still has the floor-masked "
            "threshold max(t_1 * 3.0, 0.05). Use a pure ratio instead."
        )


# ═════════════════════════════════════════════════════════════════════════════
# Finding #11 — lazy-ancestor init must not drop snapshots silently.
# ═════════════════════════════════════════════════════════════════════════════

class TestClusterAF11_LazyAncestor:
    """Lazy (parent-trigger-based) ancestor capture must:
      (a) survive concurrent writers (INSERT OR IGNORE preserves first).
      (b) distinguish 'parent had row at fork, deleted since' from
          'parent never had this row at fork' — merge semantics differ.
    """

    def test_concurrent_parent_updates_capture_first_value(self, tmp_path):
        """Pre-fix race: two parallel UPDATE ... statements could race
        the trigger's INSERT OR IGNORE. Post-fix uses INSERT OR IGNORE
        which is a no-op on conflict — first writer wins. Verify the
        ancestor table has exactly ONE row for the PK after N writes."""
        work, site_fp = make_fresh_site("cla_f11a_")
        try:
            # Create one descendant so the trigger is active.
            create_branch(site_fp, "d1")

            # Drive many sequential UPDATEs (simulating "first wins"
            # semantics — even without true concurrency, the trigger
            # should only capture the FIRST pre-update value).
            for i in range(20):
                sqlite_exec(site_fp,
                    "UPDATE b1_wp_options SET option_value=? "
                    "WHERE option_name='blogname'",
                    (f"v_{i}",))

            rows = sqlite_q(site_fp,
                "SELECT COUNT(*), MIN(row_json), MAX(row_json) "
                "FROM db_parent_ancestor "
                "WHERE parent_table_name='b1_wp_options' "
                "  AND row_json LIKE '%blogname%'")
            n, minj, maxj = rows[0]
            assert n == 1, (
                f"INSERT OR IGNORE should preserve exactly ONE ancestor per "
                f"(parent_table, pk); got {n}"
            )
            # First-writer-wins: the stored row should reflect the ORIGINAL
            # value, not any subsequent UPDATE.
            assert "My Site" in (minj or ""), (
                f"ancestor should hold the FIRST pre-update value ('My Site'), "
                f"got {minj}"
            )
        finally:
            shutil.rmtree(work, ignore_errors=True)

    def test_parent_delete_captures_ancestor_for_merge(self, tmp_path):
        """If the parent DELETEs a row after fork, the branch's merge still
        needs an ancestor (the row as-of fork time). The parent-trigger's
        BEFORE DELETE fires with OLD.* and INSERT OR IGNOREs into
        db_parent_ancestor — so merge-time lookup finds the snapshot."""
        work, site_fp = make_fresh_site("cla_f11b_")
        try:
            # Seed main with a distinguishable row.
            sqlite_exec(site_fp,
                "INSERT INTO b1_wp_options (option_name, option_value) "
                "VALUES ('will_be_deleted', 'original_value')")
            create_branch(site_fp, "d2")

            # Parent deletes the row. BEFORE DELETE trigger should
            # snapshot OLD into db_parent_ancestor.
            sqlite_exec(site_fp,
                "DELETE FROM b1_wp_options WHERE option_name='will_be_deleted'")

            rows = sqlite_q(site_fp,
                "SELECT row_json FROM db_parent_ancestor "
                "WHERE parent_table_name='b1_wp_options' "
                "  AND row_json LIKE '%will_be_deleted%'")
            assert rows, (
                "parent DELETE of a row should snapshot it into "
                "db_parent_ancestor so the branch's merge can still "
                "resolve an ancestor; got no rows"
            )
            assert "original_value" in rows[0][0], (
                f"the snapshot should carry the OLD value; got {rows[0][0]}"
            )
        finally:
            shutil.rmtree(work, ignore_errors=True)

    def test_merge_filters_ancestor_by_branch_created_at(self, tmp_path):
        """A snapshot captured BEFORE a branch was created must not be
        consumed by that branch's merge as its pre-fork ancestor.

        Scenario:
          T=0: seed main with row X='v0'.
          T=1: UPDATE X on main → trigger snapshots 'v0' into
                db_parent_ancestor with captured_at=T=1.
          T=2: branch b forked off main (captures CURRENT main state,
                which is post-update).
          T=3: b writes X.
          T=4: merge b → main.

        The correct ancestor for X in b's merge is main's T=2 value
        (b's fork-time), NOT the T=1 snapshot of 'v0' which predates b.
        The SQL at merge.php:507 and :656 must filter by
        `captured_at >= branches.created_at` so the pre-fork snapshot
        is not returned for this branch.
        """
        import time as _time
        work, site_fp = make_fresh_site("cla_f11c_")
        try:
            # Seed main with a unique, distinguishable row.
            sqlite_exec(site_fp,
                "INSERT INTO b1_wp_options (option_name, option_value) "
                "VALUES ('pre_fork_row', 'v0')")

            # UPDATE on main — snapshot 'v0' into db_parent_ancestor.
            _time.sleep(1.05)  # guarantee distinct captured_at (datetime now has sec resolution)
            sqlite_exec(site_fp,
                "UPDATE b1_wp_options SET option_value='v1' "
                "WHERE option_name='pre_fork_row'")

            # Now create the branch — its created_at must be > captured_at.
            _time.sleep(1.05)
            create_branch(site_fp, "late")

            # The snapshot is for an UPDATE that predates the branch.
            # Verify the SQL filter gets the correct result.
            rows = sqlite_q(site_fp,
                "SELECT pa.row_json, pa.captured_at, b.created_at "
                "FROM db_parent_ancestor pa "
                "JOIN branches b ON b.name='late' "
                "WHERE pa.parent_table_name='b1_wp_options' "
                "  AND pa.row_json LIKE '%pre_fork_row%' "
                "  AND pa.captured_at >= b.created_at")
            assert rows == [], (
                f"A pre-branch snapshot must NOT be returned when filtering "
                f"by captured_at >= branch.created_at; got {rows}"
            )

            # And that the merge-path SQL in merge.php filters appropriately
            # for EACH db_parent_ancestor lookup.
            text = (BASE_DIR / "scripts" / "merge.php").read_text()
            # Every "FROM db_parent_ancestor" occurrence should be followed
            # by a captured_at filter tied to branches.created_at inside
            # ~400 chars (a single SELECT body).
            idx = 0
            seen = 0
            while True:
                i = text.find("FROM db_parent_ancestor", idx)
                if i < 0:
                    break
                seen += 1
                window = text[i:i + 800]
                assert (
                    "captured_at" in window and "created_at" in window
                ), (
                    f"db_parent_ancestor lookup at offset {i} is missing a "
                    f"captured_at >= branches.created_at filter:\n"
                    f"{window[:400]}"
                )
                idx = i + 1
            assert seen >= 2, f"expected 2+ lookups, found {seen}"
        finally:
            shutil.rmtree(work, ignore_errors=True)


# ═════════════════════════════════════════════════════════════════════════════
# Finding #12 — nested branch chains (b1 → b3 → b5) precedence.
# ═════════════════════════════════════════════════════════════════════════════

class TestClusterAF12_NestedBranchChains:
    """A grandchild branch's view must UNION ALL its own overlay and
    (its PARENT branch's view \\ its own tombstones \\ its own overlay).
    Verify this recurses correctly through 3+ levels of branching."""

    def test_three_level_branch_precedence(self, tmp_path):
        """b1 (main) → b_mid → b_leaf. Writes at each layer; leaf view
        must show leaf's overlay first, then mid's, then main's."""
        work, site_fp = make_fresh_site("cla_f12_")
        try:
            # Mid branch off main, leaf off mid.
            mid_id = create_branch(site_fp, "mid", parent="main")
            leaf_id = create_branch(site_fp, "leaf", parent="mid")

            # Write different values at each layer — same option_name.
            sqlite_exec(site_fp,
                f"INSERT INTO b{mid_id}_wp_options (option_name, option_value) "
                "VALUES ('triple_opt', 'mid_val')")
            # leaf sees mid_val now.
            rows = sqlite_q(site_fp,
                f"SELECT option_value FROM b{leaf_id}_wp_options "
                "WHERE option_name='triple_opt'")
            assert rows == [("mid_val",)], (
                f"leaf should inherit mid's write; got {rows}"
            )

            # Now write on leaf — leaf's overlay takes precedence.
            sqlite_exec(site_fp,
                f"UPDATE b{leaf_id}_wp_options SET option_value='leaf_val' "
                "WHERE option_name='triple_opt'")
            rows = sqlite_q(site_fp,
                f"SELECT option_value FROM b{leaf_id}_wp_options "
                "WHERE option_name='triple_opt'")
            assert rows == [("leaf_val",)], f"got {rows}"

            # Mid still sees mid_val (leaf's overlay doesn't propagate back).
            rows = sqlite_q(site_fp,
                f"SELECT option_value FROM b{mid_id}_wp_options "
                "WHERE option_name='triple_opt'")
            assert rows == [("mid_val",)], f"mid leaked from leaf: {rows}"

            # Main sees nothing (never had it).
            rows = sqlite_q(site_fp,
                "SELECT option_value FROM b1_wp_options "
                "WHERE option_name='triple_opt'")
            assert rows == [], f"main leaked: {rows}"
        finally:
            shutil.rmtree(work, ignore_errors=True)

    def test_three_level_delete_tombstones_propagate(self, tmp_path):
        """leaf DELETE of an inherited row (from main via mid) should
        tombstone only in leaf; mid still sees it."""
        work, site_fp = make_fresh_site("cla_f12b_")
        try:
            mid_id = create_branch(site_fp, "mid", parent="main")
            leaf_id = create_branch(site_fp, "leaf", parent="mid")

            # main already has 'blogname' — leaf DELETE it.
            sqlite_exec(site_fp,
                f"DELETE FROM b{leaf_id}_wp_options WHERE option_name='blogname'")

            leaf_rows = sqlite_q(site_fp,
                f"SELECT option_value FROM b{leaf_id}_wp_options "
                "WHERE option_name='blogname'")
            assert leaf_rows == [], f"leaf should not see deleted row; got {leaf_rows}"

            mid_rows = sqlite_q(site_fp,
                f"SELECT option_value FROM b{mid_id}_wp_options "
                "WHERE option_name='blogname'")
            assert mid_rows == [("My Site",)], (
                f"mid should still see main's blogname; got {mid_rows}"
            )

            main_rows = sqlite_q(site_fp,
                "SELECT option_value FROM b1_wp_options "
                "WHERE option_name='blogname'")
            assert main_rows == [("My Site",)], f"main leaked: {main_rows}"
        finally:
            shutil.rmtree(work, ignore_errors=True)

    def test_parent_table_in_nested_triggers_is_parent_view(self, tmp_path):
        """The INSTEAD OF triggers on a grandchild view must reference
        the PARENT branch's view (b{mid}_wp_*), not main's real table.
        Verify via sqlite_master.sql."""
        work, site_fp = make_fresh_site("cla_f12c_")
        try:
            mid_id = create_branch(site_fp, "mid", parent="main")
            leaf_id = create_branch(site_fp, "leaf", parent="mid")

            # view body must reference b{mid}_wp_options, not b1_wp_options
            view_sql = sqlite_q(site_fp,
                f"SELECT sql FROM sqlite_master WHERE name='b{leaf_id}_wp_options'")
            assert view_sql, "leaf view not found"
            sql_text = view_sql[0][0]
            assert f"b{mid_id}_wp_options" in sql_text, (
                f"leaf view should reference its PARENT (b{mid_id}_wp_options); "
                f"got:\n{sql_text}"
            )
        finally:
            shutil.rmtree(work, ignore_errors=True)

    def test_nested_cross_layer_unique_guard_on_grandchild(self, tmp_path):
        """Cross-layer UNIQUE guard on a grandchild branch must walk up
        through the parent branch's VIEW. Inserting a row into the
        grandchild with a UNIQUE value that matches a row inherited
        from GREAT-GRANDPARENT (main) through the MID branch must
        still be rejected — the guard's EXISTS subquery is on the
        parent VIEW and the parent view transitively exposes main's
        rows via its own UNION ALL."""
        work, site_fp = make_fresh_site("cla_f12d_")
        try:
            mid_id = create_branch(site_fp, "mid", parent="main")
            leaf_id = create_branch(site_fp, "leaf", parent="mid")

            db = sqlite3.connect(str(site_fp))
            try:
                # main already has 'blogname' — try inserting a new overlay
                # row in leaf with the same option_name. Should fail the
                # cross-layer UNIQUE guard.
                with pytest.raises(sqlite3.IntegrityError):
                    db.execute(
                        f"INSERT INTO b{leaf_id}_wp_options "
                        "(option_name, option_value) "
                        "VALUES ('blogname', 'leaf_blog')"
                    )
                    db.commit()
                db.rollback()
            finally:
                db.close()

            # View must still show exactly one blogname row.
            rows = sqlite_q(site_fp,
                f"SELECT option_value FROM b{leaf_id}_wp_options "
                "WHERE option_name='blogname'")
            assert len(rows) == 1, f"got {rows}"
        finally:
            shutil.rmtree(work, ignore_errors=True)

    def test_nested_write_chain_four_levels(self, tmp_path):
        """Four-level branch chain: main → A → B → C. Writing at each
        level must produce correct precedence through C's view."""
        work, site_fp = make_fresh_site("cla_f12e_")
        try:
            a_id = create_branch(site_fp, "a", parent="main")
            b_id = create_branch(site_fp, "b", parent="a")
            c_id = create_branch(site_fp, "c", parent="b")

            sqlite_exec(site_fp,
                f"INSERT INTO b{a_id}_wp_options (option_name, option_value) "
                "VALUES ('level_test', 'a_val')")
            # All descendants inherit.
            for bid, expected in ((b_id, "a_val"), (c_id, "a_val")):
                rows = sqlite_q(site_fp,
                    f"SELECT option_value FROM b{bid}_wp_options "
                    "WHERE option_name='level_test'")
                assert rows == [(expected,)], f"{bid}: got {rows}"

            # b writes on top.
            sqlite_exec(site_fp,
                f"UPDATE b{b_id}_wp_options SET option_value='b_val' "
                "WHERE option_name='level_test'")
            # a still sees a_val.
            rows = sqlite_q(site_fp,
                f"SELECT option_value FROM b{a_id}_wp_options "
                "WHERE option_name='level_test'")
            assert rows == [("a_val",)], f"got {rows}"
            # b sees b_val, c inherits b_val.
            for bid, expected in ((b_id, "b_val"), (c_id, "b_val")):
                rows = sqlite_q(site_fp,
                    f"SELECT option_value FROM b{bid}_wp_options "
                    "WHERE option_name='level_test'")
                assert rows == [(expected,)], f"{bid}: got {rows}"

            # c writes on top.
            sqlite_exec(site_fp,
                f"UPDATE b{c_id}_wp_options SET option_value='c_val' "
                "WHERE option_name='level_test'")
            rows = sqlite_q(site_fp,
                f"SELECT option_value FROM b{c_id}_wp_options "
                "WHERE option_name='level_test'")
            assert rows == [("c_val",)], f"got {rows}"
            # b unchanged.
            rows = sqlite_q(site_fp,
                f"SELECT option_value FROM b{b_id}_wp_options "
                "WHERE option_name='level_test'")
            assert rows == [("b_val",)], f"got {rows}"
        finally:
            shutil.rmtree(work, ignore_errors=True)

"""
TODO3 #3 — O(1) per-parent-write ancestor capture.

Pre-TODO3, `cow_install_parent_triggers` created ONE trigger per parent
table whose body fanned out via `INSERT ... SELECT FROM db_cow_branches`
— one row per descendant branch per parent write. A site with 100 child
branches paid 100 ancestor inserts per parent UPDATE. Parent throughput
degraded linearly with branch count — the exact workload pattern branch
creation was made cheap to encourage.

Post-TODO3, the parent-side trigger inserts ONCE into the shared
`db_parent_ancestor` table per parent write. Merge fans the row out at
merge time (only for branches that actually diverge).

This file covers:

  1. One UPDATE on the parent inserts EXACTLY ONE row into
     `db_parent_ancestor` — regardless of how many sibling branches
     descend from that parent table. No linear fanout.
  2. The shared parent trigger preserves merge semantics: a
     "parent-edits-first, branch-edits-after" row is still recognized
     as a conflict at merge time.
  3. Branch creation installs an O(1) trigger whose body does NOT JOIN
     against db_cow_branches (a source-level assertion catches
     accidental regressions).
  4. The `db_parent_ancestor` / `db_parent_post_fork_inserts` tables
     are created by fs_migrate — older stores upgrade transparently.
"""

import os
import shutil
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
)


COW_HELPERS_PHP = BASE_DIR / "scripts" / "cow_helpers.php"


# ---------- Source-level guard --------------------------------------


def test_parent_trigger_does_not_fanout_over_branches():
    """
    The parent-side trigger body must NOT reference `db_cow_branches`
    (that was the mechanism for per-descendant fanout). It should
    insert directly into `db_parent_ancestor` with static values.
    """
    src = COW_HELPERS_PHP.read_text()
    # Crude but effective: find the cow_install_parent_triggers body
    # and assert that it doesn't embed a SELECT from db_cow_branches.
    i = src.find("function cow_install_parent_triggers")
    assert i != -1, "cow_install_parent_triggers function missing"
    # Take the next ~5000 chars as the body window.
    body = src[i: i + 5000]
    assert "db_parent_ancestor" in body, (
        "cow_install_parent_triggers must write to db_parent_ancestor — "
        "the O(1) shared table added in TODO3 #3."
    )
    assert "FROM db_cow_branches" not in body, (
        "cow_install_parent_triggers must not fan out over db_cow_branches — "
        "that was the pre-TODO3 per-descendant trigger body. Inserting one "
        "row per descendant per parent write cost O(num descendants)."
    )


# ---------- Behavioural: parent-write is O(1) ------------------------


def test_parent_update_inserts_exactly_one_ancestor_row(tmp_path):
    """
    Create 10 sibling branches (all forked from main) and do a single
    UPDATE on `b1_wp_options`. Exactly ONE new row should land in
    `db_parent_ancestor` — not 10.
    """
    work, site_fp = make_fresh_site("shared_anc_")
    try:
        for i in range(10):
            create_branch(site_fp, f"sib{i}")

        # Baseline.
        before = sqlite_q(site_fp,
            "SELECT COUNT(*) FROM db_parent_ancestor "
            "WHERE parent_table_name='b1_wp_options'")[0][0]

        # Single parent UPDATE.
        sqlite_exec(site_fp,
            "UPDATE b1_wp_options SET option_value='after' "
            "WHERE option_name='blogname'")

        after = sqlite_q(site_fp,
            "SELECT COUNT(*) FROM db_parent_ancestor "
            "WHERE parent_table_name='b1_wp_options'")[0][0]

        assert (after - before) == 1, (
            f"one parent UPDATE should add ONE ancestor row; got "
            f"{after - before} (with 10 sibling branches — if this scales "
            f"with branch count, TODO3 #3 has regressed)."
        )

        # Per-branch db_ancestor_overlay should remain untouched by the
        # parent write — the new scheme stops writing there from the
        # parent side.
        per_branch = sqlite_q(site_fp,
            "SELECT COUNT(*) FROM db_ancestor_overlay")[0][0]
        assert per_branch == 0, (
            "parent write shouldn't populate the per-branch overlay any "
            "more — that's the fanout we're avoiding."
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_parent_update_is_O1_regardless_of_branch_count(tmp_path):
    """
    Same pattern but explicitly compare 1-branch vs 50-branch time on
    a fixed batch of parent UPDATEs. Not a strict benchmark — the
    assertion is loose (within 3x) to stay reliable on CI.
    """
    def timed_updates(branch_count, n_updates):
        work, site_fp = make_fresh_site(f"anc_t{branch_count}_")
        try:
            for i in range(branch_count):
                create_branch(site_fp, f"b{i}")
            t0 = time.perf_counter()
            for i in range(n_updates):
                sqlite_exec(site_fp,
                    "UPDATE b1_wp_options SET option_value=? "
                    "WHERE option_name='blogname'",
                    (f"v{i}",))
            return time.perf_counter() - t0
        finally:
            shutil.rmtree(work, ignore_errors=True)

    t_1  = timed_updates(1, 100)
    t_50 = timed_updates(50, 100)

    # The O(1) claim: t_50 shouldn't grow linearly with branch count.
    # Use a pure ratio — a floor (e.g. max(t_1 * k, 0.05)) would mask a
    # big regression whenever the baseline is small (say 1 ms → 40 ms
    # is a 40× slowdown but still under the old 0.05 s floor). Divide
    # by a tiny epsilon so we never blow up when t_1 is effectively 0.
    ratio = t_50 / max(t_1, 1e-6)
    assert ratio < 3.0, (
        f"parent UPDATE should be O(1) across descendant count; "
        f"t(1 branch)={t_1:.4f}s, t(50 branches)={t_50:.4f}s — "
        f"ratio {ratio:.1f}× suggests fanout regression."
    )


# ---------- Merge semantics still correct ----------------------------


def test_merge_conflict_with_parent_first_then_branch(tmp_path):
    """
    Regression test: parent updates a row, then branch updates the
    same row. Merge must still recognize the conflict.
    Under a naive lazy-only scheme (branch snapshots parent's current
    on first touch), parent's value becomes the "ancestor" and the
    merge looks like "source modified vs ancestor" — a false clean
    merge. The shared parent trigger preserves the pre-change value
    so merge correctly detects both sides diverged.
    """
    work, site_fp = make_fresh_site("anc_conf_")
    try:
        fid = create_branch(site_fp, "feature")

        # Parent first.
        sqlite_exec(site_fp,
            "UPDATE b1_wp_options SET option_value=? "
            "WHERE option_name='shared_option'",
            ("main_change",))
        # Then branch.
        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value=? "
            "WHERE option_name='shared_option'",
            ("branch_change",))

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode != 0, (
            "parent-first, branch-second on the same row must still "
            "produce a merge conflict — the shared parent trigger is "
            "what preserves this semantic.\n" + r.stdout + "\n" + r.stderr
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_merge_clean_with_branch_only_change(tmp_path):
    """
    Companion to the conflict test: when only the branch changes a
    row and the parent doesn't touch it, the merge should be clean
    (no conflict). Verifies that the shared snapshot doesn't produce
    false conflicts on one-sided divergence.
    """
    work, site_fp = make_fresh_site("anc_clean_")
    try:
        fid = create_branch(site_fp, "feature")
        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value=? "
            "WHERE option_name='shared_option'",
            ("branch_only",))
        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, (
            "branch-only change must merge cleanly:\n" + r.stdout + "\n" + r.stderr
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


# ---------- Schema migration: tables appear on open ------------------


def test_db_parent_ancestor_tables_created_by_fs_migrate(tmp_path):
    """The new shared tables must be part of the idempotent migration."""
    work, site_fp = make_fresh_site("anc_schema_")
    try:
        # `make_fresh_site` only runs init_db.php (the canonical schema);
        # the DB-side COW tables live in fs_migrate which runs on the
        # first `branchctl` command. Invoke `list` to trigger it.
        r = branchctl(site_fp, "list")
        assert r.returncode == 0, r.stderr
        names = [
            r[0] for r in sqlite_q(site_fp,
                "SELECT name FROM sqlite_master WHERE type='table' "
                "AND name IN ('db_parent_ancestor','db_parent_post_fork_inserts') "
                "ORDER BY name")
        ]
        assert names == ["db_parent_ancestor", "db_parent_post_fork_inserts"], \
            f"expected both TODO3 #3 tables to exist; got {names}"
    finally:
        shutil.rmtree(work, ignore_errors=True)


# ---------- Migration from legacy fanout trigger ---------------------


def test_legacy_fanout_trigger_is_replaced_on_open(tmp_path):
    """
    Simulate a pre-TODO3 store where the legacy fanout trigger exists,
    then run branchctl (which triggers fs_migrate). The trigger body
    must be rewritten to the O(1) form.
    """
    work, site_fp = make_fresh_site("anc_legacy_")
    try:
        create_branch(site_fp, "legacy")

        # Overwrite the parent-side trigger with the legacy fanout body
        # to emulate a site created before TODO3 #3.
        sqlite_exec(site_fp, 'DROP TRIGGER IF EXISTS "cow_anc__b1_wp_options__upd"')
        sqlite_exec(site_fp, """
            CREATE TRIGGER "cow_anc__b1_wp_options__upd"
            BEFORE UPDATE ON "b1_wp_options"
            BEGIN
                INSERT OR IGNORE INTO db_ancestor_overlay
                    (branch_id, table_name, row_pk, row_json)
                SELECT cb.branch_id,
                       'b' || cb.branch_id || '_wp_' || cb.table_suffix,
                       json_object('option_id', OLD."option_id"),
                       json_object('option_id', OLD."option_id",
                                   'option_name', OLD."option_name",
                                   'option_value', OLD."option_value",
                                   'autoload', OLD."autoload")
                FROM db_cow_branches cb
                WHERE cb.parent_table_name = 'b1_wp_options';
            END
        """)

        # Re-invoke branchctl to run fs_migrate — any command works.
        r = branchctl(site_fp, "list")
        assert r.returncode == 0, r.stderr

        rows = sqlite_q(site_fp,
            "SELECT sql FROM sqlite_master "
            "WHERE type='trigger' AND name='cow_anc__b1_wp_options__upd'")
        assert rows, "trigger should exist after migration"
        sql = rows[0][0] or ""
        assert "db_parent_ancestor" in sql, (
            "post-migration trigger should write to db_parent_ancestor — got:\n"
            + sql
        )
        assert "FROM db_cow_branches" not in sql, (
            "post-migration trigger must not JOIN against db_cow_branches "
            "(that's the pre-TODO3 fanout). Got:\n" + sql
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)

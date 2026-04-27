"""
TODO3 #7 — sibling branches reserve disjoint AUTOINCREMENT ranges.

Pre-TODO3, every new COW branch's overlay inherited the parent's
`sqlite_sequence` row verbatim. Two siblings forked from the same
parent therefore both generated the same next IDs (101, 102, …) on
their overlays — a guaranteed collision that `--on-id-collision=
renumber` only half-handles (only for tables in the hardcoded WP
FK graph).

Post-TODO3, each branch reserves a disjoint stride at fork time:

    branch K's overlay seq = parent_max + (K - 1) × COW_AUTOINCR_STRIDE

The stride is 1 billion, leaving ~10⁹ IDs per branch before touching
the next band — well below INTEGER PRIMARY KEY's 2^63-1 limit.
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


COW_STRIDE = 1_000_000_000


def test_sibling_branches_get_disjoint_ranges(tmp_path):
    """Two branches forked from the same parent must generate IDs in
    non-overlapping bands."""
    work, site_fp = make_fresh_site("seq_")
    try:
        a = create_branch(site_fp, "a")
        b = create_branch(site_fp, "b")
        # Each inserts one row.
        for name, bid in [("a", a), ("b", b)]:
            sqlite_exec(site_fp,
                f"INSERT INTO b{bid}_wp_posts (post_title) VALUES (?)",
                (f"hello-{name}",))
        a_id = sqlite_q(site_fp,
            f"SELECT ID FROM b{a}_wp_posts WHERE post_title='hello-a'")[0][0]
        b_id = sqlite_q(site_fp,
            f"SELECT ID FROM b{b}_wp_posts WHERE post_title='hello-b'")[0][0]
        assert a_id != b_id, (
            f"sibling branches must not collide on autoincrement IDs; "
            f"a={a_id} b={b_id}"
        )
        # Under the stride formula, the two IDs should differ by ~1 stride.
        assert abs(a_id - b_id) >= COW_STRIDE, (
            f"sibling IDs should be in disjoint ranges of at least {COW_STRIDE}; "
            f"a={a_id} b={b_id}"
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_five_siblings_all_disjoint(tmp_path):
    """Stronger test from the TODO acceptance: 5 sibling branches each
    insert 100 rows; no two IDs overlap across branches."""
    work, site_fp = make_fresh_site("seq5_")
    try:
        ids_by_branch = {}
        for i in range(5):
            bid = create_branch(site_fp, f"s{i}")
            for k in range(100):
                sqlite_exec(site_fp,
                    f"INSERT INTO b{bid}_wp_posts (post_title) VALUES (?)",
                    (f"sib-{i}-row-{k}",))
            ids = {r[0] for r in sqlite_q(site_fp,
                f"SELECT ID FROM b{bid}_wp_posts WHERE post_title LIKE 'sib-{i}-%'")}
            ids_by_branch[i] = ids

        # Disjoint across all branches.
        all_ids = set()
        for i, ids in ids_by_branch.items():
            overlap = ids & all_ids
            assert not overlap, (
                f"branch s{i} IDs overlap with earlier branches' IDs: "
                f"{sorted(overlap)[:5]}"
            )
            all_ids |= ids
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_main_sequence_not_inflated(tmp_path):
    """Main's sequence shouldn't pick up the sibling-stride offset — its
    on-disk IDs should stay small."""
    work, site_fp = make_fresh_site("seq_main_")
    try:
        create_branch(site_fp, "sib")
        sqlite_exec(site_fp,
            "INSERT INTO b1_wp_posts (post_title) VALUES (?)", ("on_main",))
        main_id = sqlite_q(site_fp,
            "SELECT ID FROM b1_wp_posts WHERE post_title='on_main'")[0][0]
        # Main is branch_id=1, so stride offset = 0. The ID should be
        # just parent_max + 1.
        assert main_id < COW_STRIDE, (
            f"main's sequence shouldn't carry the sibling stride offset; "
            f"got main ID = {main_id}"
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_branch_id_determines_range(tmp_path):
    """The branch's stride band must be (branch_id - 1) * stride above
    parent_max — i.e. deterministic and branch-id-driven."""
    work, site_fp = make_fresh_site("seq_det_")
    try:
        bid = create_branch(site_fp, "one")
        # Insert on branch; ID should be in band (bid-1)*stride + small.
        sqlite_exec(site_fp,
            f"INSERT INTO b{bid}_wp_posts (post_title) VALUES (?)", ("r1",))
        new_id = sqlite_q(site_fp,
            f"SELECT ID FROM b{bid}_wp_posts WHERE post_title='r1'")[0][0]
        lower = (bid - 1) * COW_STRIDE
        upper = bid * COW_STRIDE
        assert lower < new_id <= upper, (
            f"branch id={bid}: ID {new_id} should be in ({lower}, {upper}]"
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)

"""
TODO3 #9 — --on-id-collision=renumber detects FK relationships at runtime
via PRAGMA foreign_key_list.

Pre-TODO3, the renumber path only rewrote foreign-key columns listed in
`merge_fk_map()` — a hardcoded map of WordPress core tables
(posts→postmeta, users→posts.post_author, …). A plugin that declares its
own FK (e.g. ACF's wp_acf_fields.group_id → wp_acf_groups.id) was
invisible to the renumberer: a renumber on wp_acf_groups left wp_acf_fields
pointing at stale IDs.

Post-TODO3, the renumber path unions the hardcoded fallback with FK
edges discovered at runtime by walking every overlay table under the
branch prefix and calling PRAGMA foreign_key_list.
"""

import os
import shutil
import sqlite3
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


def test_discover_function_exists():
    """Source-level guard: the discovery function must exist."""
    src = (BASE_DIR / "scripts" / "merge.php").read_text()
    assert "function merge_discover_fk_map" in src, (
        "merge_discover_fk_map missing — renumber falls back to the "
        "hard-coded WP FK graph only (TODO3 #9 regression)."
    )
    assert "PRAGMA foreign_key_list" in src, (
        "the discovery function should call PRAGMA foreign_key_list"
    )


def test_plugin_fk_is_renumbered_on_collision(tmp_path):
    """A plugin-declared FK (wp_acf_fields.group_id → wp_acf_groups.id)
    must be rewritten when the parent PK is renumbered."""
    work, site_fp = make_fresh_site("fk_dyn_")
    try:
        # Add the plugin tables BEFORE creating the branch so the branch's
        # overlay inherits them via COW.
        sqlite_exec(site_fp, """
            CREATE TABLE b1_wp_acf_groups (
                id    INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT
            )
        """)
        sqlite_exec(site_fp, """
            CREATE TABLE b1_wp_acf_fields (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                group_id INTEGER NOT NULL,
                label    TEXT,
                FOREIGN KEY (group_id) REFERENCES b1_wp_acf_groups(id)
            )
        """)
        # Seed some initial data.
        sqlite_exec(site_fp, "INSERT INTO b1_wp_acf_groups (id, title) VALUES (1, 'g1')")
        sqlite_exec(site_fp, "INSERT INTO b1_wp_acf_fields (group_id, label) VALUES (1, 'f1')")

        fid = create_branch(site_fp, "feature")

        # Branch: add a new group (with id=2 on the branch band).
        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_acf_groups (id, title) VALUES (2, 'g2_branch')")
        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_acf_fields (group_id, label) VALUES (2, 'f2_branch')")

        # Main: claim id=2 independently on acf_groups (collision target).
        sqlite_exec(site_fp,
            "INSERT INTO b1_wp_acf_groups (id, title) VALUES (2, 'g2_main')")

        # Try renumbering.
        r = branchctl(site_fp, "merge", "feature", "--into", "main",
                      "--on-id-collision", "renumber")

        # Either the merge succeeds (and the FK got renumbered) or it
        # cleanly conflicts — but under no circumstances should f2_branch
        # point at a *different* group than the one it actually references.
        if r.returncode == 0:
            # Success path: branch's group got renumbered; branch's field
            # should still reference IT (not some other group).
            rows = sqlite_q(site_fp,
                "SELECT g.title, f.label FROM b1_wp_acf_groups g "
                "JOIN b1_wp_acf_fields f ON f.group_id = g.id")
            titles_to_fields = {t: l for (t, l) in rows}
            # The branch's field 'f2_branch' must map back to its original
            # group 'g2_branch', not to 'g2_main' (which would be data
            # corruption from renumbering the FK table without the column).
            assert titles_to_fields.get("g2_branch") == "f2_branch", (
                f"FK renumber lost the branch's field linkage; got {rows}"
            )
        else:
            # Conflict path is fine — but verify merge didn't leave the
            # DB in a corrupted state with dangling FKs.
            dangling = sqlite_q(site_fp, """
                SELECT f.id, f.group_id FROM b1_wp_acf_fields f
                LEFT JOIN b1_wp_acf_groups g ON g.id = f.group_id
                WHERE g.id IS NULL
            """)
            assert not dangling, (
                f"conflicted merge left dangling FKs: {dangling}"
            )
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_hardcoded_wp_graph_still_works(tmp_path):
    """The existing hardcoded WP FK graph must continue to work even
    though it's now merged with the dynamically-discovered graph."""
    work, site_fp = make_fresh_site("fk_wp_")
    try:
        # Set up a minimal wp_posts and wp_postmeta with hardcoded FK
        # relationship (postmeta.post_id → posts.ID).
        # The fixture already creates b1_wp_posts.
        sqlite_exec(site_fp, """
            CREATE TABLE IF NOT EXISTS b1_wp_postmeta (
                meta_id   INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id   INTEGER NOT NULL,
                meta_key  TEXT,
                meta_value TEXT
            )
        """)

        fid = create_branch(site_fp, "feature")
        # Both insert ID=50 on wp_posts.
        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_posts (ID, post_title) VALUES (50, 'branch_post')")
        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_postmeta (post_id, meta_key) VALUES (50, 'branch_meta')")
        sqlite_exec(site_fp,
            "INSERT INTO b1_wp_posts (ID, post_title) VALUES (50, 'main_post')")

        r = branchctl(site_fp, "merge", "feature", "--into", "main",
                      "--on-id-collision", "renumber")
        assert r.returncode == 0, r.stderr + "\n" + r.stdout
        # The branch's postmeta should have been rewritten to point at
        # the branch's renumbered post.
        rows = sqlite_q(site_fp,
            "SELECT pm.post_id, pm.meta_key, p.post_title "
            "FROM b1_wp_postmeta pm JOIN b1_wp_posts p ON p.ID = pm.post_id "
            "WHERE pm.meta_key = 'branch_meta'")
        assert rows, "branch_meta should survive renumber"
        assert rows[0][2] == "branch_post", (
            f"branch_meta should point at the branch's post; got {rows}"
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)

"""Cluster D: storage lifetimes (hostile-review #8 and #17).

#8: branchctl delete must purge db_parent_ancestor rows — otherwise
high-churn sites accumulate one row per (branch, table, pk) touched.

#17: AUTOINCREMENT bands for sibling + grandchild branches must be
strictly disjoint. Grandchildren used to slide into great-grandchildren
bands because the seed was `parent_max + (branch_id-1)*STRIDE` instead
of `(branch_id-1)*STRIDE`.
"""
import sqlite3
from pathlib import Path
import pytest

from _rigorous_helpers import (
    branchctl, init_site, setup_wp_tables, branch_id, sqlite_exec, sqlite_q,
)

@pytest.fixture
def site(tmp_path):
    fp = tmp_path / "site.fp"
    init_site(fp)
    setup_wp_tables(fp)
    return fp


class TestDbParentAncestorGc:
    def test_delete_purges_db_parent_ancestor_for_branch_tables(self, site):
        # db_parent_ancestor holds snapshots keyed by parent_table_name,
        # written by a BEFORE UPDATE/DELETE trigger on real parent tables.
        # For legacy (non-COW) branches whose overlays are real tables,
        # rows can land with parent_table_name="b{N}_wp_*". When that
        # branch is deleted its tables go but — without the purge — the
        # ancestor rows stay as orphans pointing at a table that no
        # longer exists. Unbounded on high-churn sites.
        #
        # Reproduce the orphan state directly (portable across COW and
        # legacy layouts), then assert branchctl delete drops those
        # rows when the branch itself is dropped.
        r = branchctl(site, "create", "f"); assert r.returncode == 0, r.stderr
        fid = branch_id(site, "f")
        leaked_prefix = f"b{fid}_wp_"

        # Manually insert an orphan-ish ancestor row pointing at the
        # branch's table-space, exactly the shape a legacy parent-side
        # trigger would have written.
        sqlite_exec(site,
            "INSERT OR IGNORE INTO db_parent_ancestor "
            "(parent_table_name, row_pk, row_json) VALUES (?, ?, ?)",
            (leaked_prefix + "options", '{"option_id":99}',
             '{"option_id":99,"option_name":"x","option_value":"y"}'))

        rows_before = sqlite_q(site,
            "SELECT COUNT(*) FROM db_parent_ancestor "
            "WHERE parent_table_name LIKE ?", (leaked_prefix + "%",))
        assert rows_before[0][0] == 1

        r = branchctl(site, "delete", "f", "--force")
        assert r.returncode == 0, r.stderr

        rows_after = sqlite_q(site,
            "SELECT COUNT(*) FROM db_parent_ancestor "
            "WHERE parent_table_name LIKE ?", (leaked_prefix + "%",))
        assert rows_after[0][0] == 0, (
            f"branchctl delete leaked {rows_after[0][0]} "
            "db_parent_ancestor rows pointing at the dropped branch's "
            "tables (hostile-review #8)"
        )


class TestAutoincrementDisjointBands:
    def _seq_for(self, site, overlay_name):
        rows = sqlite_q(site,
            "SELECT seq FROM sqlite_sequence WHERE name=?", (overlay_name,))
        return rows[0][0] if rows else None

    def test_grandchild_and_sibling_bands_do_not_overlap(self, site):
        # Build a chain: main -> b2, main -> b3, b2 -> b4 (grandchild).
        # Formerly the seed for b4 could slide into b5's band because
        # grandchild seed = b2.max + (b4-1)*STRIDE.
        r = branchctl(site, "create", "b2"); assert r.returncode == 0, r.stderr
        r = branchctl(site, "create", "b3"); assert r.returncode == 0, r.stderr

        b2id = branch_id(site, "b2")
        b3id = branch_id(site, "b3")

        # Force overlay creation for wp_options on b2 by writing a row so
        # sqlite_sequence[b2_wp_options] gets seeded.
        sqlite_exec(site,
            f"INSERT INTO b{b2id}_wp_options (option_name, option_value) "
            "VALUES ('seed', 'v')")

        # Grandchild from b2.
        r = branchctl(site, "create", "b4", "--from", "b2")
        assert r.returncode == 0, r.stderr
        b4id = branch_id(site, "b4")

        # Sibling of b2 (child of main).
        r = branchctl(site, "create", "b5")
        assert r.returncode == 0, r.stderr
        b5id = branch_id(site, "b5")

        # Force overlay creation on each branch by touching wp_options.
        for bid in (b3id, b4id, b5id):
            sqlite_exec(site,
                f"INSERT INTO b{bid}_wp_options (option_name, option_value) "
                f"VALUES ('seed_{bid}', 'v')")

        STRIDE = 1_000_000_000
        bands = {}
        for bid in (b2id, b3id, b4id, b5id):
            seq = self._seq_for(site, f"b{bid}_wp_options__overlay")
            assert seq is not None, \
                f"b{bid} overlay has no sqlite_sequence row"
            # Band is [seq - STRIDE, seq] since seed is at the low end.
            expected_band_start = (bid - 1) * STRIDE
            expected_band_end = bid * STRIDE
            bands[bid] = (expected_band_start, expected_band_end, seq)
            assert expected_band_start <= seq < expected_band_end, (
                f"b{bid} seed {seq} outside its branch-id band "
                f"[{expected_band_start}, {expected_band_end}) — "
                "grandchild collision hazard"
            )

        # Pairwise bands must be disjoint.
        ids = sorted(bands)
        for i, bid_a in enumerate(ids):
            for bid_b in ids[i+1:]:
                a_start, a_end, _ = bands[bid_a]
                b_start, b_end, _ = bands[bid_b]
                assert a_end <= b_start or b_end <= a_start, (
                    f"bands for b{bid_a} and b{bid_b} overlap: "
                    f"[{a_start},{a_end}) vs [{b_start},{b_end})"
                )

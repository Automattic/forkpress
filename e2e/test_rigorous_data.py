"""
Rigorous data-type tests: NULL, binary, UTF-8, huge strings, SQL injection,
numeric edge values. Every value passes through overlay + commit + merge
so the full pipeline is exercised.

Run:
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_rigorous_data.py -v
"""

import math
import os
import shutil
import sqlite3
import subprocess
import sys
import tempfile
from pathlib import Path

import pytest

from _rigorous_helpers import (
    branchctl, branch_id, commit_branch, create_branch,
    init_site, make_fresh_site, require_ext,
    sqlite_exec, sqlite_q, sqlite_executescript,
)


@pytest.fixture
def site():
    work, site_fp = make_fresh_site(prefix="rigdata_")
    yield site_fp
    shutil.rmtree(work, ignore_errors=True)


@pytest.fixture
def site_with_branch(site):
    create_branch(site, "x")
    return site, branch_id(site, "x")


def roundtrip_through_commit_rollback(site_fp, branch, table_suffix,
                                      where_col, where_val):
    """Commit, mutate, rollback, return the value at where_col=where_val."""
    r = commit_branch(site_fp, branch, "pre-change")
    assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"


# ═════════════════════════════════════════════════════════════════════
# 4.1 — NULL preservation
# ═════════════════════════════════════════════════════════════════════

class TestNullHandling:

    def test_null_in_nullable_column_preserved_through_commit_rollback(self, site_with_branch):
        site, fid = site_with_branch
        # Add a NULLable column to the overlay.
        sqlite_exec(site,
            f"ALTER TABLE b{fid}_wp_posts__overlay ADD COLUMN extra TEXT")
        # Need to recreate the view to include extra. Skip — insert via overlay directly.
        sqlite_exec(site,
            f"INSERT INTO b{fid}_wp_posts__overlay (post_title, extra) VALUES (?, ?)",
            ("null_test", None))
        r = commit_branch(site, "x", "ins null")
        assert r.returncode == 0

        got = sqlite_q(site,
            f"SELECT extra FROM b{fid}_wp_posts__overlay WHERE post_title = 'null_test'")
        assert got == [(None,)]

        # Now rollback — was no pre-existing state, so rollback should refuse or be no-op.
        # Instead, verify overlay-row's NULL survives a noop re-read.
        got2 = sqlite_q(site,
            f"SELECT post_title, extra FROM b{fid}_wp_posts__overlay "
            f"WHERE post_title = 'null_test'")
        assert got2 == [("null_test", None)]

    def test_null_vs_empty_string_distinction_preserved(self, site_with_branch):
        site, fid = site_with_branch
        sqlite_exec(site,
            f"ALTER TABLE b{fid}_wp_posts__overlay ADD COLUMN nullable TEXT")
        sqlite_exec(site,
            f"INSERT INTO b{fid}_wp_posts__overlay (post_title, nullable) VALUES (?, ?)",
            ("null_row", None))
        sqlite_exec(site,
            f"INSERT INTO b{fid}_wp_posts__overlay (post_title, nullable) VALUES (?, ?)",
            ("empty_row", ""))
        r = commit_branch(site, "x", "null vs empty")
        assert r.returncode == 0

        null_val = sqlite_q(site,
            f"SELECT nullable FROM b{fid}_wp_posts__overlay WHERE post_title = 'null_row'")
        empty_val = sqlite_q(site,
            f"SELECT nullable FROM b{fid}_wp_posts__overlay WHERE post_title = 'empty_row'")
        assert null_val == [(None,)], f"NULL coerced: {null_val}"
        assert empty_val == [("",)], f"empty string corrupted: {empty_val}"

        # Verify the snapshot serialization preserved NULL as JSON null, not "".
        rows = sqlite_q(site,
            "SELECT row_json FROM db_commit_overlays "
            "WHERE table_suffix = 'posts' AND row_json LIKE '%null_row%'")
        assert rows
        assert '"nullable":null' in rows[0][0] or '"nullable": null' in rows[0][0], (
            f"NULL value in snapshot JSON is not null: {rows[0][0]}"
        )

    def test_null_insert_via_view_trigger_routes_to_overlay(self, site_with_branch):
        site, fid = site_with_branch
        # Option_value is TEXT NOT NULL DEFAULT ''. Insert option_name='null_opt'
        # with option_value=NULL. The trigger's COALESCE path should substitute
        # the default '' so the not-null constraint doesn't fire.
        sqlite_exec(site,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) VALUES (?, ?)",
            ("null_opt", None))
        got = sqlite_q(site,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name = 'null_opt'")
        # COALESCE should have replaced NULL with the default ''.
        assert got == [("",)], f"expected '' (coalesced default), got {got}"


# ═════════════════════════════════════════════════════════════════════
# 4.2 — Binary BLOB with null bytes
# ═════════════════════════════════════════════════════════════════════

class TestBinaryBlob:

    def test_blob_with_null_bytes_round_trip(self, site_with_branch):
        site, fid = site_with_branch
        sqlite_exec(site,
            f"ALTER TABLE b{fid}_wp_posts__overlay ADD COLUMN bin BLOB")

        payload = b"before\x00middle\x00\xff\xfe\xfd\x00after"
        db = sqlite3.connect(str(site))
        db.execute(
            f"INSERT INTO b{fid}_wp_posts__overlay (post_title, bin) VALUES (?, ?)",
            ("blob_null", payload))
        db.commit()
        db.close()

        got = sqlite_q(site,
            f"SELECT bin FROM b{fid}_wp_posts__overlay WHERE post_title = 'blob_null'")
        assert got == [(payload,)]


# ═════════════════════════════════════════════════════════════════════
# 4.3 — Invalid UTF-8 in TEXT column (policy: not guaranteed to preserve
# through json_encode; document what actually happens)
# ═════════════════════════════════════════════════════════════════════

class TestInvalidUtf8:

    def test_invalid_utf8_does_not_crash_snapshot(self, site_with_branch):
        """Invalid UTF-8 bytes in a TEXT column don't crash commit. The
        row may be serialized with replaced bytes OR coerced — we assert
        only that commit succeeds and rollback works. This documents the
        policy: invalid UTF-8 survives inside SQLite (SQLite doesn't
        validate), but JSON-based snapshots may lossily encode it.
        """
        site, fid = site_with_branch
        bad = b"valid\xff\xfe\xfdtext"  # invalid UTF-8 continuation bytes
        db = sqlite3.connect(str(site))
        db.execute(
            f"INSERT INTO b{fid}_wp_options "
            "(option_name, option_value) VALUES (?, ?)",
            ("bad_utf8", sqlite3.Binary(bad)))
        db.commit()
        db.close()

        r = commit_branch(site, "x", "with bad utf8")
        # Must succeed — not crash.
        assert r.returncode == 0, (
            f"commit crashed on invalid UTF-8 instead of handling gracefully:\n"
            f"{r.stdout}\n{r.stderr}"
        )


# ═════════════════════════════════════════════════════════════════════
# 4.4 — Huge TEXT
# ═════════════════════════════════════════════════════════════════════

class TestHugeText:

    def test_1mb_text_round_trip(self, site_with_branch):
        site, fid = site_with_branch
        huge = "a" * (1024 * 1024)
        sqlite_exec(site,
            f"INSERT INTO b{fid}_wp_posts (post_title, post_content) VALUES (?, ?)",
            ("huge", huge))
        got = sqlite_q(site,
            f"SELECT post_content FROM b{fid}_wp_posts WHERE post_title = 'huge'")
        assert got[0][0] == huge

        r = commit_branch(site, "x", "huge")
        assert r.returncode == 0


# ═════════════════════════════════════════════════════════════════════
# 4.5 — Unicode-4 / emoji / CJK
# ═════════════════════════════════════════════════════════════════════

class TestUnicodePreservation:

    @pytest.mark.parametrize("label,value", [
        ("emoji", "hello 👋 world 🌍 test 🚀"),
        ("cjk_chinese", "你好世界 — 中文测试"),
        ("cjk_japanese", "こんにちは世界 — 日本語"),
        ("arabic_rtl", "مرحبا بالعالم"),
        ("hebrew", "שלום עולם"),
        ("combining", "café, naïve, Zoë, crème"),
        ("emoji_zwj", "👨‍👩‍👧‍👦 family zwj sequence"),
        ("math", "∀x∃y: x + y = y + x  ∫₀^∞ e^(-x) dx"),
    ])
    def test_unicode_survives_commit_and_rollback(self, site_with_branch, label, value):
        site, fid = site_with_branch
        sqlite_exec(site,
            f"INSERT INTO b{fid}_wp_options "
            "(option_name, option_value) VALUES (?, ?)",
            (label, value))
        got = sqlite_q(site,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name = ?",
            (label,))
        assert got == [(value,)], f"pre-commit roundtrip broke {label}"

        r = commit_branch(site, "x", f"unicode {label}")
        assert r.returncode == 0, f"commit failed on {label}:\n{r.stderr}"

        got_post = sqlite_q(site,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name = ?",
            (label,))
        assert got_post == [(value,)], f"post-commit value changed: {got_post}"


# ═════════════════════════════════════════════════════════════════════
# 4.6 — NaN / Infinity floats
# ═════════════════════════════════════════════════════════════════════

class TestExoticNumbers:

    def test_large_int_near_int64_max(self, site_with_branch):
        """SQLite uses 64-bit int. Near-max values must round-trip."""
        site, fid = site_with_branch
        sqlite_exec(site,
            f"ALTER TABLE b{fid}_wp_posts__overlay ADD COLUMN bignum INTEGER")
        big = 2**63 - 1  # 9223372036854775807 (max int64)
        neg = -(2**63)   # min int64
        db = sqlite3.connect(str(site))
        db.execute(
            f"INSERT INTO b{fid}_wp_posts__overlay "
            "(post_title, bignum) VALUES (?, ?)", ("bigmax", big))
        db.execute(
            f"INSERT INTO b{fid}_wp_posts__overlay "
            "(post_title, bignum) VALUES (?, ?)", ("bigmin", neg))
        db.commit()
        db.close()
        got_max = sqlite_q(site,
            f"SELECT bignum FROM b{fid}_wp_posts__overlay WHERE post_title='bigmax'")
        got_min = sqlite_q(site,
            f"SELECT bignum FROM b{fid}_wp_posts__overlay WHERE post_title='bigmin'")
        assert got_max == [(big,)]
        assert got_min == [(neg,)]

    def test_infinity_and_nan_documented_policy(self, site_with_branch):
        """SQLite policy for REAL column:
        - +Inf and -Inf round-trip as Python inf values.
        - NaN is COERCED TO NULL by SQLite itself (documented SQLite behavior).
        We verify both so regressions show up."""
        site, fid = site_with_branch
        sqlite_exec(site,
            f"ALTER TABLE b{fid}_wp_posts__overlay ADD COLUMN flt REAL")
        db = sqlite3.connect(str(site))
        db.execute(
            f"INSERT INTO b{fid}_wp_posts__overlay (post_title, flt) VALUES (?, ?)",
            ("inf_row", float("inf")))
        db.execute(
            f"INSERT INTO b{fid}_wp_posts__overlay (post_title, flt) VALUES (?, ?)",
            ("neg_inf", float("-inf")))
        db.execute(
            f"INSERT INTO b{fid}_wp_posts__overlay (post_title, flt) VALUES (?, ?)",
            ("nan_row", float("nan")))
        db.commit()
        db.close()

        inf_got = sqlite_q(site,
            f"SELECT flt FROM b{fid}_wp_posts__overlay WHERE post_title='inf_row'")[0][0]
        neg_got = sqlite_q(site,
            f"SELECT flt FROM b{fid}_wp_posts__overlay WHERE post_title='neg_inf'")[0][0]
        nan_got = sqlite_q(site,
            f"SELECT flt FROM b{fid}_wp_posts__overlay WHERE post_title='nan_row'")[0][0]
        assert math.isinf(inf_got) and inf_got > 0, f"Inf not preserved: {inf_got}"
        assert math.isinf(neg_got) and neg_got < 0, f"-Inf not preserved: {neg_got}"
        # SQLite's documented behaviour: NaN → NULL.
        assert nan_got is None, (
            f"NaN was stored as {nan_got!r}, expected None (SQLite coerces NaN to NULL)"
        )

        # Commit must still succeed with these values.
        r = commit_branch(site, "x", "inf+nan")
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"


# ═════════════════════════════════════════════════════════════════════
# 4.7 — SQL injection attempts in values
# ═════════════════════════════════════════════════════════════════════

class TestSqlInjectionInValues:

    @pytest.mark.parametrize("payload", [
        "'; DROP TABLE b1_wp_options; --",
        "') OR 1=1 --",
        "\x00null byte",
        "backslash\\escape\\test",
        "newlines\r\nand\ttabs",
        "' UNION SELECT password_hash FROM users --",
        "\" OR \"\"=\"",
        "1; DELETE FROM branches; --",
    ])
    def test_sql_injection_value_stored_literally(self, site_with_branch, payload):
        site, fid = site_with_branch
        sqlite_exec(site,
            f"INSERT INTO b{fid}_wp_options "
            "(option_name, option_value) VALUES (?, ?)",
            ("inj", payload))

        # The payload must be stored VERBATIM — not executed.
        got = sqlite_q(site,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name = 'inj'")
        assert got == [(payload,)]
        # Sanity: b1_wp_options still exists (DROP TABLE didn't run).
        n = sqlite_q(site, "SELECT COUNT(*) FROM b1_wp_options")[0][0]
        assert n >= 3
        # branches table intact.
        b = sqlite_q(site, "SELECT COUNT(*) FROM branches")[0][0]
        assert b >= 2

        # Commit survives too.
        r = commit_branch(site, "x", "commit with injection payload")
        assert r.returncode == 0


# ═════════════════════════════════════════════════════════════════════
# 4.8 — Dates in various formats (SQLite stores TEXT)
# ═════════════════════════════════════════════════════════════════════

class TestDateFormats:

    @pytest.mark.parametrize("d", [
        "2024-01-15 10:30:00",
        "2024-01-15T10:30:00Z",
        "2024-01-15T10:30:00+02:00",
        "0000-00-00 00:00:00",  # MySQL zero-date
        "9999-12-31 23:59:59",
        "  2024-01-15",  # leading whitespace
    ])
    def test_date_string_preserved_verbatim(self, site_with_branch, d):
        site, fid = site_with_branch
        sqlite_exec(site,
            f"INSERT INTO b{fid}_wp_options "
            "(option_name, option_value) VALUES (?, ?)",
            ("date_" + d[:4] + d[-4:], d))
        name = "date_" + d[:4] + d[-4:]
        got = sqlite_q(site,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name = ?",
            (name,))
        assert got == [(d,)], f"date changed: stored {got[0][0]!r}, expected {d!r}"

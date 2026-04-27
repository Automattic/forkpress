"""
Inline orphaned-blob GC on branch delete (TODO2 #4).

When a branch is deleted, its files/fs_commit_files rows are gone but the
underlying blobs — if they aren't referenced by any other branch or snapshot
— would otherwise stick around until a manual `branchctl gc`. These tests
verify that `branchctl delete` reclaims orphan blobs inline, preserves blobs
still referenced by other branches, and cleans both `blobs` and
`blob_chunks` rows in one shot.

They also cover the Rust CLI's `--gc-interval` flag via source inspection
(the flag is wired into the long-running `start_command` path which isn't
exercised headlessly here).

Run:
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_orphan_gc.py -v -m "not live"
"""

import hashlib
import os
import re
import shutil
import sqlite3
import subprocess
import tempfile
from pathlib import Path

import pytest

E2E_DIR = Path(__file__).parent
BASE_DIR = E2E_DIR.parent
EXT_PATH = BASE_DIR / "ext" / "branchfs.so"
PHP_BIN = "php"
CHUNK_SIZE = 1024 * 1024  # must match ext/branchfs.h BRANCHFS_CHUNK_SIZE


def _php(code: str, env: dict = None, timeout: int = 60) -> subprocess.CompletedProcess:
    cmd = [PHP_BIN, "-d", f"extension={EXT_PATH}", "-r", code]
    return subprocess.run(
        cmd, capture_output=True, text=True, timeout=timeout,
        env={**os.environ, **(env or {})},
    )


def branchctl(site_fp: Path, *args, timeout: int = 60) -> subprocess.CompletedProcess:
    return subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         str(BASE_DIR / "scripts" / "branchctl.php"), *args],
        capture_output=True, text=True, timeout=timeout,
        env={**os.environ, "BRANCHFS_DB": str(site_fp)},
    )


def sqlite_q(site_fp: Path, sql: str, params=()) -> list:
    db = sqlite3.connect(str(site_fp))
    try:
        return db.execute(sql, params).fetchall()
    finally:
        db.close()


def sqlite_exec(site_fp: Path, sql: str, params=()):
    db = sqlite3.connect(str(site_fp))
    try:
        db.execute(sql, params)
        db.commit()
    finally:
        db.close()


def _write(site_fp: Path, branch: str, path: str, data: bytes):
    """Write `data` to branchfs://<branch>/<path> via file_put_contents."""
    tmp = tempfile.NamedTemporaryFile(delete=False)
    try:
        tmp.write(data)
        tmp.close()
        code = (
            f"branchfs_set_db('{site_fp}');"
            f"$d = file_get_contents('{tmp.name}');"
            f"$r = file_put_contents('branchfs://{branch}/{path}', $d);"
            "echo $r === false ? 'FAIL' : $r;"
        )
        r = _php(code, timeout=120)
        assert r.returncode == 0, f"php write failed: {r.stderr[:400]}"
        assert r.stdout.strip() != "FAIL"
    finally:
        os.unlink(tmp.name)


def _blob_hash_for_size(site_fp: Path, size: int) -> str | None:
    rows = sqlite_q(site_fp, "SELECT hash FROM blobs WHERE size = ?", (size,))
    return rows[0][0] if rows else None


@pytest.fixture
def isolated_site(tmp_path):
    if not EXT_PATH.exists():
        pytest.skip(f"branchfs.so not found — run 'make' in {BASE_DIR}")

    site_fp = tmp_path / "site.fp"
    r = subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         str(BASE_DIR / "scripts" / "init_db.php"), str(site_fp),
         "--admin-password", "testpw"],
        capture_output=True, text=True, timeout=30,
    )
    assert r.returncode == 0, f"init failed: {r.stderr[:400]}"
    return site_fp


# ══════════════════════════════════════════════════════════════════════════════
# Core inline-GC semantics: unique blob goes, shared blob stays.
# ══════════════════════════════════════════════════════════════════════════════

class TestInlineGC:
    def test_delete_cleans_unique_blob(self, isolated_site):
        """A branch with a unique 10 KB file → delete branch → blob gone."""
        r = branchctl(isolated_site, "create", "gc-test")
        assert r.returncode == 0, f"create failed: {r.stderr[:400]}"

        payload = os.urandom(10 * 1024)  # unique per run
        _write(isolated_site, "gc-test", "cow-test-10k.bin", payload)

        h = _blob_hash_for_size(isolated_site, len(payload))
        assert h is not None, "blob row should exist after write"

        # Sanity: no other file / commit references this blob.
        other_files = sqlite_q(isolated_site,
            "SELECT COUNT(*) FROM files f "
            "JOIN branches b ON b.id = f.branch_id "
            "WHERE f.blob_hash = ? AND b.name != 'gc-test'", (h,))[0][0]
        assert other_files == 0

        r = branchctl(isolated_site, "delete", "gc-test")
        assert r.returncode == 0, f"delete failed: {r.stderr[:400]}\n{r.stdout}"

        left = sqlite_q(isolated_site,
            "SELECT COUNT(*) FROM blobs WHERE hash = ?", (h,))[0][0]
        assert left == 0, "orphan blob must be gone after delete"

    def test_delete_preserves_shared_blob(self, isolated_site):
        """Two branches share the same blob; deleting one keeps the blob."""
        for b in ("a", "b"):
            r = branchctl(isolated_site, "create", b)
            assert r.returncode == 0, f"create {b}: {r.stderr[:400]}"

        payload = b"shared-content-" + b"Z" * 500
        _write(isolated_site, "a", "same.bin", payload)
        _write(isolated_site, "b", "same.bin", payload)
        h = _blob_hash_for_size(isolated_site, len(payload))
        assert h is not None

        r = branchctl(isolated_site, "delete", "a")
        assert r.returncode == 0, f"delete a failed: {r.stderr[:400]}\n{r.stdout}"

        # b still references it → blob row stays.
        still = sqlite_q(isolated_site,
            "SELECT COUNT(*) FROM blobs WHERE hash = ?", (h,))[0][0]
        assert still == 1, "shared blob must be preserved while another branch still references it"

        # Inline-GC must not claim to have reclaimed anything.
        assert "reclaimed" not in r.stdout.lower(), \
            f"expected no reclamation line, got: {r.stdout!r}"

    def test_parent_blob_not_deleted(self, isolated_site):
        """A blob written on main (parent) stays when a child branch is deleted.

        The child's `files` rows don't reference the blob directly — the
        branch inherits the parent's file entries. The inline-GC candidate
        set is drawn only from the child's OWN rows, so the parent's blob is
        never a candidate in the first place.
        """
        payload = b"parent-content-" + os.urandom(200)
        _write(isolated_site, "main", "parent.bin", payload)
        h = _blob_hash_for_size(isolated_site, len(payload))
        assert h is not None

        r = branchctl(isolated_site, "create", "cow-child", "--from", "main")
        assert r.returncode == 0, f"create child: {r.stderr[:400]}"

        r = branchctl(isolated_site, "delete", "cow-child")
        assert r.returncode == 0, f"delete child: {r.stderr[:400]}\n{r.stdout}"

        still = sqlite_q(isolated_site,
            "SELECT COUNT(*) FROM blobs WHERE hash = ?", (h,))[0][0]
        assert still == 1, "parent branch's blob must survive child delete"

    def test_delete_output_reports_reclaimed_count(self, isolated_site):
        """stdout must contain a machine-grep-able reclaimed count."""
        r = branchctl(isolated_site, "create", "reportbranch")
        assert r.returncode == 0

        # Two unique blobs → expect reclaimed 2 on delete.
        _write(isolated_site, "reportbranch", "r1.bin", os.urandom(1024))
        _write(isolated_site, "reportbranch", "r2.bin", os.urandom(1024))

        r = branchctl(isolated_site, "delete", "reportbranch")
        assert r.returncode == 0, f"delete failed: {r.stderr[:400]}"
        assert "reclaimed" in r.stdout.lower(), \
            f"expected 'reclaimed' in stdout, got:\n{r.stdout}"
        # Extract the integer count.
        m = re.search(r"reclaimed\s+(\d+)", r.stdout, re.IGNORECASE)
        assert m is not None, f"no reclaimed count in: {r.stdout}"
        assert int(m.group(1)) == 2, f"expected reclaimed 2, got {m.group(1)} in:\n{r.stdout}"


# ══════════════════════════════════════════════════════════════════════════════
# Chunked-blob integration: inline GC must also remove blob_chunks rows.
# ══════════════════════════════════════════════════════════════════════════════

class TestChunkedGC:
    def test_gc_chunk_rows_also_cleaned(self, isolated_site):
        """Upload a 2 MB (chunked) file, delete branch, both blob + chunks go."""
        r = branchctl(isolated_site, "create", "chunkbr")
        assert r.returncode == 0

        size = 2 * CHUNK_SIZE + 100
        payload = bytes((i * 7 + 3) & 0xFF for i in range(size))
        _write(isolated_site, "chunkbr", "big.bin", payload)

        h = _blob_hash_for_size(isolated_site, size)
        assert h is not None

        pre_chunks = sqlite_q(isolated_site,
            "SELECT COUNT(*) FROM blob_chunks WHERE blob_hash = ?", (h,))[0][0]
        assert pre_chunks > 0, "precondition: large blob must be chunked"

        r = branchctl(isolated_site, "delete", "chunkbr")
        assert r.returncode == 0, f"delete failed: {r.stderr[:400]}\n{r.stdout}"

        blob_left = sqlite_q(isolated_site,
            "SELECT COUNT(*) FROM blobs WHERE hash = ?", (h,))[0][0]
        chunk_left = sqlite_q(isolated_site,
            "SELECT COUNT(*) FROM blob_chunks WHERE blob_hash = ?", (h,))[0][0]
        assert blob_left == 0, "blobs row for chunked blob must be gone"
        assert chunk_left == 0, "blob_chunks rows for chunked blob must be gone"


# ══════════════════════════════════════════════════════════════════════════════
# Source-level invariants: ensures the contract stays in code even if
# integration tests regress from environmental flakiness.
# ══════════════════════════════════════════════════════════════════════════════

class TestSourceInspection:
    def test_delete_uses_transaction(self):
        """The delete case runs inside a BEGIN IMMEDIATE so a mid-delete crash
        can't leak partial state. Enforced via source inspection."""
        src = (BASE_DIR / "scripts" / "branchctl.php").read_text()
        # Slice out the `case 'delete':` body conservatively.
        m = re.search(r"case 'delete':\s*\{(.+?)\n\}\s*\n", src, re.DOTALL)
        assert m is not None, "could not find case 'delete' block"
        body = m.group(1)
        assert "BEGIN IMMEDIATE" in body, \
            "branchctl delete must wrap its DB writes in BEGIN IMMEDIATE"
        assert "fs_gc(" in body, \
            "branchctl delete must invoke fs_gc for inline reclamation"
        assert "DELETE FROM fs_commits" in body, \
            "branchctl delete must drop fs_commits rows (was previously leaked)"
        assert "DELETE FROM fs_commit_files" in body, \
            "branchctl delete must drop fs_commit_files rows"

    def test_forkpress_has_gc_interval_flag(self):
        """The Rust CLI exposes --gc-interval and the parse_duration helper."""
        main_rs = (BASE_DIR / "forkpress" / "src" / "main.rs").read_text()
        assert "gc_interval" in main_rs, "StartArgs must have gc_interval field"
        assert "fn parse_duration" in main_rs, "parse_duration helper missing"
        assert "run_background_gc" in main_rs, \
            "background GC thread entry point missing"

    def test_fs_gc_helper_exists(self):
        """fs_gc() is the shared helper used by both `delete` and `gc`."""
        src = (BASE_DIR / "scripts" / "branchctl.php").read_text()
        assert re.search(r"function\s+fs_gc\s*\(", src), \
            "fs_gc() helper missing"
        assert "DELETE FROM blob_chunks" in src, \
            "fs_gc must also clean blob_chunks rows"


# ══════════════════════════════════════════════════════════════════════════════
# The existing manual `branchctl gc` still works after the refactor.
# ══════════════════════════════════════════════════════════════════════════════

class TestFullGCStillWorks:
    def test_full_gc_removes_orphans_created_out_of_band(self, isolated_site):
        """Simulates an externally-orphaned blob (e.g. from a pre-fix
        delete) and confirms the manual `branchctl gc` still reaps it."""
        # Write on main so a real blob exists, then orphan it.
        payload = os.urandom(2048)
        _write(isolated_site, "main", "willorphan.bin", payload)
        h = _blob_hash_for_size(isolated_site, len(payload))
        assert h is not None
        # Orphan it (equivalent to how the pre-fix delete would have left
        # things): drop every reference without touching the blob row.
        sqlite_exec(isolated_site, "DELETE FROM files WHERE blob_hash = ?", (h,))
        sqlite_exec(isolated_site,
            "DELETE FROM fs_commit_files WHERE blob_hash = ?", (h,))

        r = branchctl(isolated_site, "gc")
        assert r.returncode == 0, f"gc failed: {r.stderr[:400]}"

        left = sqlite_q(isolated_site,
            "SELECT COUNT(*) FROM blobs WHERE hash = ?", (h,))[0][0]
        assert left == 0, "manual gc must reap externally-orphaned blob"

    def test_gc_dry_run_does_not_delete(self, isolated_site):
        """--dry-run reports but doesn't mutate."""
        payload = os.urandom(1024)
        _write(isolated_site, "main", "dryorphan.bin", payload)
        h = _blob_hash_for_size(isolated_site, len(payload))
        sqlite_exec(isolated_site, "DELETE FROM files WHERE blob_hash = ?", (h,))
        sqlite_exec(isolated_site,
            "DELETE FROM fs_commit_files WHERE blob_hash = ?", (h,))

        r = branchctl(isolated_site, "gc", "--dry-run")
        assert r.returncode == 0, f"dry-run failed: {r.stderr[:400]}"
        assert "would delete" in r.stdout.lower()

        still = sqlite_q(isolated_site,
            "SELECT COUNT(*) FROM blobs WHERE hash = ?", (h,))[0][0]
        assert still == 1, "--dry-run must not delete anything"

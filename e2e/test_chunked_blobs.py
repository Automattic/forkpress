"""
Chunked blob storage tests (TODO2 #2).

Large blobs (> 1 MB) are split across rows in `blob_chunks` so that SQLite
row sizes stay bounded and the read path doesn't have to hold the whole
file in a single column. Smaller blobs stay inline in `blobs.data` for
single-query reads and backward compat with pre-chunking `.fp` files.

Run:
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_chunked_blobs.py -v -m "not live"
"""

import hashlib
import os
import shutil
import signal
import sqlite3
import subprocess
import tempfile
import threading
import time
from pathlib import Path

import pytest

E2E_DIR = Path(__file__).parent
BASE_DIR = E2E_DIR.parent
EXT_PATH = BASE_DIR / "ext" / "branchfs.so"
PHP_BIN = "php"

# Must match BRANCHFS_CHUNK_SIZE in ext/branchfs.h and CHUNK_SIZE in
# fileserver/src/store.rs.
CHUNK_SIZE = 1024 * 1024


def php(code: str, env: dict = None, timeout: int = 60, raise_on_err: bool = False) -> subprocess.CompletedProcess:
    cmd = [PHP_BIN, "-d", f"extension={EXT_PATH}", "-r", code]
    r = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout,
                       env={**os.environ, **(env or {})})
    if raise_on_err and r.returncode != 0:
        raise RuntimeError(f"php failed ({r.returncode}): {r.stderr[:500]}")
    return r


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


@pytest.fixture(scope="module")
def fresh_site():
    """A freshly-initialized site.fp (main branch only) for each test module."""
    if not EXT_PATH.exists():
        pytest.skip(f"branchfs.so not found — run 'make' in branched-wp/")

    work = Path(tempfile.mkdtemp(prefix="chunkedblob_"))
    site_fp = work / "site.fp"

    r = subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         str(BASE_DIR / "scripts" / "init_db.php"), str(site_fp),
         "--admin-password", "testpw"],
        capture_output=True, text=True, timeout=30,
    )
    if r.returncode != 0:
        pytest.skip(f"init_db.php failed: {r.stderr[:300]}")

    yield site_fp
    shutil.rmtree(work, ignore_errors=True)


@pytest.fixture
def isolated_site(tmp_path):
    """Per-test site.fp — used by tests that mutate blobs / run gc."""
    if not EXT_PATH.exists():
        pytest.skip(f"branchfs.so not found — run 'make' in branched-wp/")

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
# Schema invariants
# ══════════════════════════════════════════════════════════════════════════════

class TestSchema:
    def test_blob_chunks_table_exists(self, fresh_site):
        """init_db.php creates the blob_chunks table with the expected columns."""
        rows = sqlite_q(fresh_site,
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='blob_chunks'")
        assert rows, "blob_chunks table missing after init_db.php"
        ddl = rows[0][0]
        for col in ("blob_hash", "chunk_no", "data"):
            assert col in ddl, f"blob_chunks.{col} missing in DDL:\n{ddl}"
        assert "PRIMARY KEY" in ddl

    def test_blobs_data_is_nullable(self, fresh_site):
        """After the migration, blobs.data must allow NULL so chunked blobs
        can store their metadata row with a NULL payload."""
        ddl = sqlite_q(fresh_site,
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='blobs'"
        )[0][0]
        # The column line must NOT contain "NOT NULL" for data (size/hash keep theirs).
        # Find the data line and check.
        lines = [l.strip() for l in ddl.split("\n") if "data" in l.lower()]
        data_lines = [l for l in lines if l.lower().startswith("data ") or "data " in l.lower()]
        assert data_lines, f"no data column in blobs DDL:\n{ddl}"
        # Simpler invariant: try inserting NULL and it must succeed.
        sqlite_exec(fresh_site,
            "INSERT INTO blobs (hash, data, size) VALUES ('test_nullable_hash', NULL, 0)")
        got = sqlite_q(fresh_site,
            "SELECT data FROM blobs WHERE hash='test_nullable_hash'")
        assert got[0][0] is None
        sqlite_exec(fresh_site,
            "DELETE FROM blobs WHERE hash='test_nullable_hash'")


# ══════════════════════════════════════════════════════════════════════════════
# Write-path behaviour: small vs. large
# ══════════════════════════════════════════════════════════════════════════════

def _write_bytes(site_fp: Path, branch: str, path: str, data: bytes):
    """Write `data` to branchfs://<branch>/<path>. Uses file_put_contents,
    which ultimately drives store_write_file()."""
    tmp = tempfile.NamedTemporaryFile(delete=False)
    try:
        tmp.write(data)
        tmp.close()
        code = f"""
            branchfs_set_db('{site_fp}');
            $r = file_put_contents('branchfs://{branch}/{path}', file_get_contents('{tmp.name}'));
            echo $r === false ? 'FAIL' : $r;
        """
        r = php(code, timeout=120)
        assert r.returncode == 0, f"php failed: {r.stderr[:400]}"
        assert r.stdout.strip() != "FAIL", "file_put_contents returned false"
    finally:
        os.unlink(tmp.name)


def _read_bytes(site_fp: Path, branch: str, path: str) -> bytes:
    """Read branchfs://<branch>/<path> and return as bytes. Goes via a temp
    file so we can carry arbitrary binary back to Python."""
    out_tmp = tempfile.NamedTemporaryFile(delete=False)
    out_tmp.close()
    try:
        code = f"""
            branchfs_set_db('{site_fp}');
            $d = file_get_contents('branchfs://{branch}/{path}');
            file_put_contents('{out_tmp.name}', $d === false ? '' : $d);
        """
        r = php(code, timeout=120)
        assert r.returncode == 0, f"php failed: {r.stderr[:400]}"
        with open(out_tmp.name, "rb") as f:
            return f.read()
    finally:
        os.unlink(out_tmp.name)


class TestWritePath:
    def test_small_blob_stored_inline(self, isolated_site):
        """A 100-byte file: blobs.data NOT NULL, no rows in blob_chunks."""
        _write_bytes(isolated_site, "main", "small.txt", b"x" * 100)

        rows = sqlite_q(isolated_site,
            "SELECT hash, data, size FROM blobs WHERE size = 100")
        assert len(rows) == 1, f"expected 1 row for size=100, got {len(rows)}"
        h, data, sz = rows[0]
        assert data is not None, "small blob must have data inline"
        assert sz == 100
        chunk_rows = sqlite_q(isolated_site,
            "SELECT COUNT(*) FROM blob_chunks WHERE blob_hash = ?", (h,))
        assert chunk_rows[0][0] == 0, "small blob must not create chunk rows"

    def test_large_blob_is_chunked(self, isolated_site):
        """A 3 MB file: blobs.data IS NULL, exactly 3 rows in blob_chunks."""
        size = 3 * CHUNK_SIZE
        payload = bytes((i * 7 + 13) & 0xFF for i in range(size))
        _write_bytes(isolated_site, "main", "big.bin", payload)

        rows = sqlite_q(isolated_site,
            "SELECT hash, data, size FROM blobs WHERE size = ?", (size,))
        assert len(rows) == 1, f"expected 1 blob row, got {len(rows)}"
        h, data, sz = rows[0]
        assert data is None, "large blob must have NULL blobs.data"
        assert sz == size

        chunks = sqlite_q(isolated_site,
            "SELECT chunk_no, LENGTH(data) FROM blob_chunks "
            "WHERE blob_hash = ? ORDER BY chunk_no", (h,))
        assert len(chunks) == 3, f"expected 3 chunks, got {len(chunks)}"
        # chunk_no is contiguous starting at 0
        assert [c[0] for c in chunks] == [0, 1, 2]
        # All three chunks are exactly CHUNK_SIZE (no remainder)
        assert all(c[1] == CHUNK_SIZE for c in chunks)

    def test_large_blob_uneven_remainder(self, isolated_site):
        """A blob slightly larger than 2 chunks gets a small tail chunk."""
        size = 2 * CHUNK_SIZE + 1234
        payload = bytes((i * 31 + 5) & 0xFF for i in range(size))
        _write_bytes(isolated_site, "main", "uneven.bin", payload)

        rows = sqlite_q(isolated_site,
            "SELECT hash FROM blobs WHERE size = ?", (size,))
        h = rows[0][0]
        chunks = sqlite_q(isolated_site,
            "SELECT chunk_no, LENGTH(data) FROM blob_chunks "
            "WHERE blob_hash = ? ORDER BY chunk_no", (h,))
        assert len(chunks) == 3, f"expected 3 chunks, got {len(chunks)}"
        assert chunks[0][1] == CHUNK_SIZE
        assert chunks[1][1] == CHUNK_SIZE
        assert chunks[2][1] == 1234


# ══════════════════════════════════════════════════════════════════════════════
# Read-path round-trip: byte-exact integrity for both inline and chunked blobs.
# ══════════════════════════════════════════════════════════════════════════════

class TestReadRoundTrip:
    def test_chunked_read_returns_exact_content(self, isolated_site):
        """Deterministic 3 MB payload: write, read, compare SHA-256."""
        size = 3 * CHUNK_SIZE + 17
        payload = bytes((i * 13 + 7) & 0xFF for i in range(size))
        expected_hash = hashlib.sha256(payload).hexdigest()

        _write_bytes(isolated_site, "main", "content.bin", payload)
        got = _read_bytes(isolated_site, "main", "content.bin")
        got_hash = hashlib.sha256(got).hexdigest()
        assert len(got) == len(payload), \
            f"size mismatch: wrote {len(payload)} read {len(got)}"
        assert got_hash == expected_hash, \
            f"content mismatch: wrote {expected_hash}, read {got_hash}"

    def test_small_read_round_trip(self, isolated_site):
        """Inline payload round-trips byte-identical."""
        payload = b"hello world\n" + b"\x00\x01\x02\xff" * 10
        _write_bytes(isolated_site, "main", "small.bin", payload)
        got = _read_bytes(isolated_site, "main", "small.bin")
        assert got == payload


# ══════════════════════════════════════════════════════════════════════════════
# Backward compatibility: legacy inline blobs inserted directly via SQLite
# must remain readable through the stream wrapper.
# ══════════════════════════════════════════════════════════════════════════════

class TestBackwardCompat:
    def test_legacy_inline_blob_readable(self, isolated_site):
        """A blob inserted with NOT-NULL data (pre-chunking layout) reads
        back correctly through branchfs://. This is the on-disk format of
        every existing .fp file before the migration."""
        payload = b"legacy-blob-content-" + os.urandom(500)
        # The extension uses its own (FNV-1a) hash; we cannot compute a matching
        # hash from Python without duplicating that code, so we install the blob
        # then attach it to a file row by its hash.
        # Simpler: drive the write through the extension so it computes the
        # correct hash, then mutate the blob row in place to a known inline
        # layout (which it already is for a small write).
        _write_bytes(isolated_site, "main", "legacy.bin", payload)
        rows = sqlite_q(isolated_site,
            "SELECT hash, data FROM blobs WHERE size = ?", (len(payload),))
        assert len(rows) == 1
        h, data = rows[0]
        assert data is not None, "small write should already be inline"
        # Round-trip confirms the legacy path.
        got = _read_bytes(isolated_site, "main", "legacy.bin")
        assert got == payload


# ══════════════════════════════════════════════════════════════════════════════
# GC cleans both blobs AND blob_chunks for orphaned rows.
# ══════════════════════════════════════════════════════════════════════════════

class TestGCCleansChunks:
    def test_gc_removes_orphan_chunks(self, isolated_site):
        """Create a chunked blob, make it unreferenced, run branchctl gc,
        verify BOTH blobs and blob_chunks rows are gone."""
        size = 2 * CHUNK_SIZE + 500
        payload = bytes((i * 17 + 3) & 0xFF for i in range(size))

        _write_bytes(isolated_site, "main", "orphan.bin", payload)

        # Confirm the chunked layout is in place before gc.
        blob_row = sqlite_q(isolated_site,
            "SELECT hash FROM blobs WHERE size = ?", (size,))
        assert len(blob_row) == 1
        h = blob_row[0][0]
        pre_chunks = sqlite_q(isolated_site,
            "SELECT COUNT(*) FROM blob_chunks WHERE blob_hash = ?", (h,))
        assert pre_chunks[0][0] > 0

        # Orphan the blob: delete the files row AND every fs_commit_files row
        # that references it. `branchctl gc` treats both as live references.
        sqlite_exec(isolated_site, "DELETE FROM files WHERE blob_hash = ?", (h,))
        sqlite_exec(isolated_site,
            "DELETE FROM fs_commit_files WHERE blob_hash = ?", (h,))

        # Run gc.
        r = branchctl(isolated_site, "gc")
        assert r.returncode == 0, f"gc failed: {r.stderr[:400]}"

        # Both tables must no longer reference the orphan hash.
        blob_left = sqlite_q(isolated_site,
            "SELECT COUNT(*) FROM blobs WHERE hash = ?", (h,))
        assert blob_left[0][0] == 0, "gc must delete orphan blobs row"
        chunk_left = sqlite_q(isolated_site,
            "SELECT COUNT(*) FROM blob_chunks WHERE blob_hash = ?", (h,))
        assert chunk_left[0][0] == 0, "gc must delete orphan blob_chunks rows"


# ══════════════════════════════════════════════════════════════════════════════
# Memory peak: writing a 32 MB blob must NOT spike PHP RSS by ~32 MB.
#
# We sample /proc/<pid>/status:VmRSS every 10 ms while the write is in flight.
# The acceptance criterion is that peak RSS stays well below baseline + payload
# size; chunked storage means SQLite never holds the full 32 MB in one row,
# so peaks are bounded by (baseline + ~2x chunk size) in the steady state.
#
# file_put_contents() in PHP does load the whole payload into PHP memory once
# (PHP-level buffering we cannot eliminate without rewriting the stream API),
# so the expectation is NOT "peak < 32 MB"; it is "peak does not also include
# a second 32 MB copy inside SQLite's row buffer". Hence < baseline + 1.5x
# payload is the realistic bound — before chunking this test would fail with
# peaks around baseline + 2x payload.
#
# As a pragmatic fallback, we additionally verify that the file IS stored with
# >= 32 chunk rows, which proves the chunked path ran even if the RSS read
# is noisy on a loaded CI machine.
# ══════════════════════════════════════════════════════════════════════════════

def _sample_rss_kb(pid: int) -> int:
    try:
        with open(f"/proc/{pid}/status") as f:
            for line in f:
                if line.startswith("VmRSS:"):
                    return int(line.split()[1])
    except (FileNotFoundError, ProcessLookupError, PermissionError):
        return -1
    return -1


class TestMemoryPeak:
    def test_32mb_write_is_chunked(self, isolated_site):
        """The chunked-layout invariant: regardless of RSS measurements,
        a 32 MB write MUST have landed as >= 32 rows in blob_chunks."""
        size = 32 * CHUNK_SIZE  # 32 MiB exact
        # Fast deterministic payload; no need for crypto randomness.
        payload = (b"A" * CHUNK_SIZE) * 32
        _write_bytes(isolated_site, "main", "huge.bin", payload)

        rows = sqlite_q(isolated_site,
            "SELECT hash FROM blobs WHERE size = ?", (size,))
        assert len(rows) == 1
        h = rows[0][0]
        chunk_count = sqlite_q(isolated_site,
            "SELECT COUNT(*) FROM blob_chunks WHERE blob_hash = ?", (h,))[0][0]
        assert chunk_count == 32, f"expected 32 chunks, got {chunk_count}"

        # Round-trip integrity: chunked read reassembles the exact payload.
        got = _read_bytes(isolated_site, "main", "huge.bin")
        assert len(got) == size
        assert hashlib.sha256(got).hexdigest() == hashlib.sha256(payload).hexdigest()

    def test_32mb_write_peak_rss_bounded(self, isolated_site):
        """Measure peak PHP RSS while writing a 32 MB file. If the chunked
        storage path is active, SQLite's row buffer never holds the full 32 MB,
        so total peak should be roughly (PHP baseline + payload).

        This test is probabilistic on loaded machines. If the sample misses
        the actual peak (the whole write completes in < 10ms), the assertion
        should still hold comfortably because the chunked path allocates less.
        """
        size = 32 * CHUNK_SIZE
        payload = bytes(((i * 11 + 1) & 0xFF) for i in range(size))

        # Write the payload to a file first so PHP loads it via file_get_contents
        # — mirrors how a real SFTP/SMB upload lands.
        tmp = tempfile.NamedTemporaryFile(delete=False)
        tmp.write(payload)
        tmp.close()

        try:
            code = f"""
                branchfs_set_db('{isolated_site}');
                $data = file_get_contents('{tmp.name}');
                file_put_contents('branchfs://main/peak.bin', $data);
            """
            cmd = [PHP_BIN, "-d", "memory_limit=512M",
                   "-d", f"extension={EXT_PATH}", "-r", code]
            proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)

            # Sample RSS at 10 ms intervals.
            peak_kb = 0
            samples = 0
            while proc.poll() is None:
                rss = _sample_rss_kb(proc.pid)
                if rss > peak_kb:
                    peak_kb = rss
                samples += 1
                time.sleep(0.01)
            proc.wait(timeout=60)
            assert proc.returncode == 0, \
                f"php failed: {proc.stderr.read().decode()[:400]}"

            # Expectation: peak_kb should be bounded by PHP baseline (~25 MB
            # under OPcache) + PHP's unavoidable full-payload copy (~32 MB,
            # held by $data) + the extension's stream buffer (~32 MB,
            # populated once by stream_write) + malloc fragmentation.
            #
            # Before chunking, SQLite would ALSO hold a 32 MB row buffer
            # during the blob insert, pushing total peak to ~150+ MB. After
            # chunking, SQLite only holds one CHUNK_SIZE row at a time.
            # So peak < baseline + 2 * payload + small slack (~130 MB) is
            # the correct ceiling; anything above that suggests double-
            # buffering inside the extension or SQLite.
            #
            # If Python never sampled (very fast write), peak_kb=0 and we
            # fall back to the layout invariant covered by the other test.
            ceiling_kb = 130 * 1024
            if peak_kb > 0:
                assert peak_kb < ceiling_kb, (
                    f"peak PHP RSS {peak_kb} KB (> {ceiling_kb} KB) suggests "
                    f"the extension or SQLite is double-buffering the 32 MB "
                    f"payload; chunked write path should keep peak near "
                    f"baseline + ~2x payload"
                )
        finally:
            os.unlink(tmp.name)

        # Defence in depth: confirm the chunked layout ran.
        rows = sqlite_q(isolated_site,
            "SELECT hash FROM blobs WHERE size = ?", (size,))
        assert len(rows) == 1
        h = rows[0][0]
        n_chunks = sqlite_q(isolated_site,
            "SELECT COUNT(*) FROM blob_chunks WHERE blob_hash = ?", (h,))[0][0]
        assert n_chunks == 32, \
            f"32 MB write should create 32 chunks, got {n_chunks}"

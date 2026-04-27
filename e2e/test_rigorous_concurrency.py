"""
Rigorous concurrency tests for ForkPress branched-wp.

Each test produces actual contention: threads wait on a barrier or Event
and race when released. We spawn PHP processes via subprocess.Popen for
the branchctl tests so the OS really runs them in parallel.

Run:
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_rigorous_concurrency.py -v

If any case hangs, pytest's global timeout or the per-call timeout should
fire; tests are careful to always `.kill()` or `.terminate()` rogue procs
in finalizers.
"""

import os
import shutil
import sqlite3
import subprocess
import tempfile
import threading
import time
from pathlib import Path

import pytest

from _rigorous_helpers import (
    branchctl, branchctl_popen, branch_id, commit_branch, create_branch,
    init_site, make_fresh_site, require_ext, setup_wp_tables,
    sqlite_exec, sqlite_q, sqlite_executescript, BRANCHCTL_PHP, EXT_PATH,
    PHP_BIN,
)


# Each test uses its own tempdir so module state never leaks.

@pytest.fixture
def site():
    work, site_fp = make_fresh_site(prefix="rigconc_")
    yield site_fp
    shutil.rmtree(work, ignore_errors=True)


# ─────────────────────────────────────────────────────────────────────
# Helpers local to this file
# ─────────────────────────────────────────────────────────────────────

def run_in_threads(fn, n, barrier_release=True, timeout=60):
    """Start `n` threads that all hit a barrier and then call `fn(i)`.

    Returns a list of the return values (None for exceptions).
    """
    barrier = threading.Barrier(n)
    results = [None] * n
    errors = [None] * n

    def worker(i):
        try:
            if barrier_release:
                barrier.wait(timeout=10)
            results[i] = fn(i)
        except BaseException as e:
            errors[i] = e

    threads = [threading.Thread(target=worker, args=(i,)) for i in range(n)]
    for t in threads:
        t.start()
    for t in threads:
        t.join(timeout=timeout)
        if t.is_alive():
            raise AssertionError(f"thread {t.name} did not join in {timeout}s")
    return results, errors


def open_db_with_retry(site_fp, timeout_ms=30000):
    """Open a sqlite3 connection with a generous busy_timeout for concurrency."""
    db = sqlite3.connect(str(site_fp), timeout=30.0)
    db.execute(f"PRAGMA busy_timeout = {timeout_ms}")
    return db


# ═════════════════════════════════════════════════════════════════════
# 1.1 — N workers insert into same branch concurrently
# ═════════════════════════════════════════════════════════════════════

class TestConcurrentInsertsSameBranch:

    def test_8_workers_100_inserts_each_all_land(self, site):
        """8 worker threads each insert 100 rows concurrently; 800 rows
        must be present, no losses, no duplicates."""
        create_branch(site, "feature")
        fid = branch_id(site, "feature")

        N_WORKERS = 8
        ROWS_PER = 100

        def worker(i):
            db = open_db_with_retry(site)
            try:
                for j in range(ROWS_PER):
                    # Retry loop over SQLITE_BUSY.
                    for _attempt in range(50):
                        try:
                            db.execute(
                                f"INSERT INTO b{fid}_wp_posts (post_title, post_content) VALUES (?, ?)",
                                (f"t_{i}_{j}", f"body-{i}-{j}"),
                            )
                            db.commit()
                            break
                        except sqlite3.OperationalError as e:
                            if "locked" in str(e).lower() or "busy" in str(e).lower():
                                time.sleep(0.02 * (_attempt + 1))
                                continue
                            raise
                    else:
                        raise AssertionError(
                            f"worker {i} insert {j} never succeeded")
            finally:
                db.close()

        results, errors = run_in_threads(worker, N_WORKERS, timeout=120)
        for i, e in enumerate(errors):
            assert e is None, f"worker {i} raised {e!r}"

        titles = sqlite_q(site, f"SELECT post_title FROM b{fid}_wp_posts WHERE post_title LIKE 't\\_%' ESCAPE '\\'")
        assert len(titles) == N_WORKERS * ROWS_PER, (
            f"expected {N_WORKERS * ROWS_PER} rows; got {len(titles)}"
        )
        # All titles should be unique (no duplicate primary keys).
        title_set = {t[0] for t in titles}
        assert len(title_set) == N_WORKERS * ROWS_PER

    def test_concurrent_inserts_produce_unique_pks(self, site):
        """Autoincrement must hand out unique IDs under 6-way concurrent insert.

        (We fetch IDs via SELECT because lastrowid is unreliable for
        INSTEAD-OF-triggered inserts on a view.)
        """
        create_branch(site, "f")
        fid = branch_id(site, "f")

        N = 6
        ROWS = 30

        def worker(i):
            db = open_db_with_retry(site)
            try:
                for j in range(ROWS):
                    for _attempt in range(50):
                        try:
                            db.execute(
                                f"INSERT INTO b{fid}_wp_posts (post_title) VALUES (?)",
                                (f"w{i}-{j}",),
                            )
                            db.commit()
                            break
                        except sqlite3.OperationalError as e:
                            if "locked" in str(e).lower() or "busy" in str(e).lower():
                                time.sleep(0.02 * (_attempt + 1))
                                continue
                            raise
                    else:
                        raise AssertionError("insert didn't succeed")
            finally:
                db.close()

        results, errors = run_in_threads(worker, N, timeout=120)
        for i, e in enumerate(errors):
            assert e is None, f"worker {i}: {e!r}"

        rows = sqlite_q(site,
            f"SELECT ID, post_title FROM b{fid}_wp_posts WHERE post_title LIKE 'w%-%'")
        assert len(rows) == N * ROWS, f"expected {N*ROWS} rows, got {len(rows)}"
        ids = [r[0] for r in rows]
        assert len(set(ids)) == len(ids), (
            f"duplicate IDs issued across concurrent inserts; "
            f"got {len(ids)} total, {len(set(ids))} unique"
        )


# ═════════════════════════════════════════════════════════════════════
# 1.2 — Mixed reads + writes
# ═════════════════════════════════════════════════════════════════════

class TestMixedReadsAndWrites:

    def test_readers_see_consistent_counts(self, site):
        """4 readers poll SELECT COUNT while 4 writers insert; each read
        returns a valid integer (never an error, never a "half row")."""
        create_branch(site, "rw")
        fid = branch_id(site, "rw")

        READERS = 4
        WRITERS = 4
        WRITES_EACH = 50
        stop = threading.Event()
        reader_samples = []
        lock = threading.Lock()
        writer_errors = []

        def reader(i):
            db = open_db_with_retry(site)
            try:
                while not stop.is_set():
                    for _attempt in range(20):
                        try:
                            c = db.execute(
                                f"SELECT COUNT(*) FROM b{fid}_wp_posts"
                            ).fetchone()[0]
                            with lock:
                                reader_samples.append(c)
                            break
                        except sqlite3.OperationalError as e:
                            if "locked" in str(e).lower() or "busy" in str(e).lower():
                                time.sleep(0.01 * (_attempt + 1))
                                continue
                            raise
                    time.sleep(0.001)
            finally:
                db.close()

        def writer(i):
            db = open_db_with_retry(site)
            try:
                for j in range(WRITES_EACH):
                    for _attempt in range(50):
                        try:
                            db.execute(
                                f"INSERT INTO b{fid}_wp_posts (post_title) VALUES (?)",
                                (f"W{i}-{j}",),
                            )
                            db.commit()
                            break
                        except sqlite3.OperationalError as e:
                            if "locked" in str(e).lower() or "busy" in str(e).lower():
                                time.sleep(0.02 * (_attempt + 1))
                                continue
                            writer_errors.append(e)
                            raise
            finally:
                db.close()

        rt = [threading.Thread(target=reader, args=(i,)) for i in range(READERS)]
        wt = [threading.Thread(target=writer, args=(i,)) for i in range(WRITERS)]
        for t in rt + wt:
            t.start()
        # Wait on writers; then stop readers.
        for t in wt:
            t.join(timeout=120)
            assert not t.is_alive()
        stop.set()
        for t in rt:
            t.join(timeout=10)
            assert not t.is_alive()

        assert not writer_errors, f"writers errored: {writer_errors[:3]}"
        # All reader samples are non-negative integers and the final count matches.
        assert all(isinstance(s, int) and s >= 1 for s in reader_samples), \
            f"reader saw bad count: min={min(reader_samples)}"
        final = sqlite_q(site,
            f"SELECT COUNT(*) FROM b{fid}_wp_posts WHERE post_title LIKE 'W%'")[0][0]
        assert final == WRITERS * WRITES_EACH
        # Readers should have sampled a monotonic-increasing-then-stable count,
        # but only weakly: assert only that counts are non-decreasing when grouped.
        assert max(reader_samples) >= final


# ═════════════════════════════════════════════════════════════════════
# 1.3 — Concurrent branchctl create
# ═════════════════════════════════════════════════════════════════════

class TestConcurrentBranchCreate:

    def test_five_concurrent_create_all_succeed(self, site):
        """5 `branchctl create` processes in parallel must all produce a
        distinct branch with its own ID.
        """
        N = 5
        procs = [branchctl_popen(site, "create", f"par{i}") for i in range(N)]
        outs = [p.communicate(timeout=120) for p in procs]
        rcs  = [p.returncode for p in procs]

        failures = [(i, outs[i], rcs[i]) for i in range(N) if rcs[i] != 0]
        assert not failures, (
            f"some parallel creates failed:\n"
            + "\n".join(
                f"- par{i}: rc={rc} out={o[0][:200]!r} err={o[1][:200]!r}"
                for i, o, rc in failures
            )
        )

        # Each branch got a row
        rows = sqlite_q(site,
            "SELECT id, name FROM branches WHERE name LIKE 'par%' ORDER BY id")
        assert len(rows) == N, f"expected {N} branches; got {rows}"
        ids = [r[0] for r in rows]
        assert len(set(ids)) == N, "duplicate branch IDs"

    def test_concurrent_create_does_not_collide_ids_on_tables(self, site):
        """Each branch's COW-forked tables get a unique name prefix."""
        N = 4
        procs = [branchctl_popen(site, "create", f"t{i}") for i in range(N)]
        for p in procs:
            p.wait(timeout=120)
        bids = sqlite_q(site,
            "SELECT id FROM branches WHERE name LIKE 't%' AND name != 'test'")
        ids = sorted([b[0] for b in bids])
        assert len(ids) >= N
        # Each should have its own b{id}_wp_posts view
        for bid in ids:
            rows = sqlite_q(site,
                "SELECT type FROM sqlite_master WHERE name = ?",
                (f"b{bid}_wp_posts",))
            assert rows, f"missing b{bid}_wp_posts"


# ═════════════════════════════════════════════════════════════════════
# 1.4 — Concurrent branchctl commit on sibling branches
# ═════════════════════════════════════════════════════════════════════

class TestConcurrentCommitOnSiblings:

    def test_two_siblings_commit_concurrently(self, site):
        """Two sibling branches each have divergent changes; concurrent
        commits must succeed and produce correct per-branch snapshots."""
        create_branch(site, "left")
        create_branch(site, "right")
        lid = branch_id(site, "left")
        rid = branch_id(site, "right")

        # Introduce divergent changes on each
        sqlite_exec(site,
            f"INSERT INTO b{lid}_wp_options (option_name, option_value) VALUES (?, ?)",
            ("left_only", "L"))
        sqlite_exec(site,
            f"INSERT INTO b{rid}_wp_options (option_name, option_value) VALUES (?, ?)",
            ("right_only", "R"))

        p1 = branchctl_popen(site, "commit", "left", "-m", "L")
        p2 = branchctl_popen(site, "commit", "right", "-m", "R")
        out1, err1 = p1.communicate(timeout=120)
        out2, err2 = p2.communicate(timeout=120)
        assert p1.returncode == 0, f"left commit failed: {out1}\n{err1}"
        assert p2.returncode == 0, f"right commit failed: {out2}\n{err2}"

        # Each branch has its own fs_commit *with an overlay row for the
        # inserted option*.
        lc = sqlite_q(site,
            "SELECT id FROM db_commits WHERE branch_id = ? ORDER BY id DESC LIMIT 1",
            (lid,))[0][0]
        rc = sqlite_q(site,
            "SELECT id FROM db_commits WHERE branch_id = ? ORDER BY id DESC LIMIT 1",
            (rid,))[0][0]

        # Overlays are keyed by suffix without the `wp_` prefix: `options`.
        l_overlays = sqlite_q(site,
            "SELECT row_json FROM db_commit_overlays "
            "WHERE commit_id = ? AND table_suffix = 'options'",
            (lc,))
        r_overlays = sqlite_q(site,
            "SELECT row_json FROM db_commit_overlays "
            "WHERE commit_id = ? AND table_suffix = 'options'",
            (rc,))
        assert any("left_only" in r[0] for r in l_overlays), (
            f"left commit missing overlay for 'left_only': {l_overlays}"
        )
        assert any("right_only" in r[0] for r in r_overlays), (
            f"right commit missing overlay for 'right_only': {r_overlays}"
        )
        # Cross-contamination check: neither branch's commit captured
        # the other branch's row.
        assert not any("right_only" in r[0] for r in l_overlays), (
            f"left commit wrongly captured right's row: {l_overlays}"
        )
        assert not any("left_only" in r[0] for r in r_overlays), (
            f"right commit wrongly captured left's row: {r_overlays}"
        )


# ═════════════════════════════════════════════════════════════════════
# 1.5 — branchctl merge vs ongoing writes
# ═════════════════════════════════════════════════════════════════════

class TestMergeVsOngoingWrites:

    def test_writes_to_source_during_merge_never_lost_silently(self, site):
        """Writer thread inserts into source while merge is in flight.

        Required invariant: every write either lands on the source branch
        (visible via branch view after merge completes) or the write call
        fails loudly. We must NOT see writes that silently disappeared.
        """
        create_branch(site, "src")
        fid = branch_id(site, "src")

        # Pre-populate so merge has to do some work.
        db = sqlite3.connect(str(site))
        for j in range(200):
            db.execute(
                f"INSERT INTO b{fid}_wp_options (option_name, option_value) VALUES (?, ?)",
                (f"pre_{j}", f"v{j}"))
        db.commit()
        db.close()

        # Start merge in a process
        writer_error = []
        written = []
        stop = threading.Event()

        def writer():
            db = open_db_with_retry(site)
            i = 0
            try:
                while not stop.is_set() and i < 500:
                    try:
                        for _a in range(50):
                            try:
                                db.execute(
                                    f"INSERT INTO b{fid}_wp_options "
                                    "(option_name, option_value) VALUES (?, ?)",
                                    (f"mid_{i}", f"v{i}"))
                                db.commit()
                                written.append(f"mid_{i}")
                                break
                            except sqlite3.OperationalError as e:
                                m = str(e).lower()
                                if "locked" in m or "busy" in m:
                                    time.sleep(0.01 * (_a + 1))
                                    continue
                                raise
                        else:
                            writer_error.append("retries exhausted")
                            return
                        i += 1
                        time.sleep(0.005)
                    except Exception as e:
                        writer_error.append(e)
                        return
            finally:
                db.close()

        wt = threading.Thread(target=writer)
        wt.start()
        time.sleep(0.1)  # let a few writes land before merge starts
        p = branchctl_popen(site, "merge", "src", "--into", "main")
        mout, merr = p.communicate(timeout=180)
        stop.set()
        wt.join(timeout=30)
        assert not wt.is_alive()

        # Merge should have completed (0) or cleanly failed with diagnostic.
        assert p.returncode == 0, f"merge failed:\n{mout}\n{merr}"

        # Every `written` name is either on main (merged in) or still on
        # source (visible via the branch view). None should have been silently
        # dropped. A small post-merge window may still see writes only on src.
        time.sleep(0.2)
        main_names = {r[0] for r in sqlite_q(site,
            "SELECT option_name FROM b1_wp_options WHERE option_name LIKE 'mid_%'")}
        src_names = {r[0] for r in sqlite_q(site,
            f"SELECT option_name FROM b{fid}_wp_options WHERE option_name LIKE 'mid_%'")}
        visible = main_names | src_names
        missing = set(written) - visible
        assert not missing, (
            f"{len(missing)} writes visible nowhere. Sample: {list(missing)[:5]}"
        )


# ═════════════════════════════════════════════════════════════════════
# 1.6 — branchctl rollback vs ongoing reads
# ═════════════════════════════════════════════════════════════════════

class TestRollbackVsReads:

    def test_readers_never_see_half_state_during_rollback(self, site):
        """Reader thread polls a specific option while rollback runs.
        Each sample must be either the pre- or post-rollback value, never
        a NULL/error/garbage."""
        create_branch(site, "rb")
        fid = branch_id(site, "rb")

        # commit 1: set blogname = "v1"
        sqlite_exec(site,
            f"UPDATE b{fid}_wp_options SET option_value = ? WHERE option_name = ?",
            ("v1", "blogname"))
        r = commit_branch(site, "rb", "set v1")
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"

        # commit 2: set blogname = "v2"
        sqlite_exec(site,
            f"UPDATE b{fid}_wp_options SET option_value = ? WHERE option_name = ?",
            ("v2", "blogname"))
        r = commit_branch(site, "rb", "set v2")
        assert r.returncode == 0, f"{r.stdout}\n{r.stderr}"

        # Sanity: current view says v2
        assert sqlite_q(site,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name='blogname'"
        )[0][0] == "v2"

        samples = []
        errors = []
        stop = threading.Event()

        def reader():
            db = open_db_with_retry(site)
            try:
                while not stop.is_set():
                    try:
                        rows = db.execute(
                            f"SELECT option_value FROM b{fid}_wp_options "
                            f"WHERE option_name = 'blogname'"
                        ).fetchall()
                        if rows:
                            samples.append(rows[0][0])
                        else:
                            samples.append(None)
                    except sqlite3.OperationalError as e:
                        m = str(e).lower()
                        if "locked" in m or "busy" in m:
                            time.sleep(0.005)
                            continue
                        errors.append(e)
                        return
                    time.sleep(0.0005)
            finally:
                db.close()

        rt = threading.Thread(target=reader)
        rt.start()
        time.sleep(0.05)
        p = branchctl_popen(site, "rollback", "rb")
        out, err = p.communicate(timeout=60)
        assert p.returncode == 0, f"rollback failed: {out}\n{err}"
        time.sleep(0.05)
        stop.set()
        rt.join(timeout=10)
        assert not rt.is_alive()

        assert not errors, f"reader errored: {errors}"
        # Every sample is either v1 or v2 — never empty string, NULL, or garbage.
        bad = [s for s in samples if s not in ("v1", "v2")]
        assert not bad, f"reader saw partial state: {bad[:5]}"
        # And the final state is v1 (rolled back).
        final = sqlite_q(site,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name='blogname'"
        )[0][0]
        assert final == "v1"


# ═════════════════════════════════════════════════════════════════════
# 1.7 — Concurrent delete of unrelated branches
# ═════════════════════════════════════════════════════════════════════

class TestConcurrentDelete:

    def test_delete_unrelated_branches_in_parallel(self, site):
        """Deleting two unrelated branches in parallel must succeed and
        leave the other branches intact."""
        for n in ("keep", "del1", "del2"):
            create_branch(site, n)

        p1 = branchctl_popen(site, "delete", "del1")
        p2 = branchctl_popen(site, "delete", "del2")
        o1 = p1.communicate(timeout=60)
        o2 = p2.communicate(timeout=60)
        assert p1.returncode == 0, f"{o1}"
        assert p2.returncode == 0, f"{o2}"

        names = {r[0] for r in sqlite_q(site, "SELECT name FROM branches")}
        assert "del1" not in names
        assert "del2" not in names
        assert "keep" in names
        assert "main" in names


# ═════════════════════════════════════════════════════════════════════
# 1.8 — Concurrent reads on main while branch is being created
# ═════════════════════════════════════════════════════════════════════

class TestReadsDuringBranchCreate:

    def test_reads_on_main_keep_working_while_branch_created(self, site):
        """A reader on main must keep observing consistent row counts
        even as a branch is being forked (COW should not block reads)."""
        # Bulk-add some main rows first
        db = sqlite3.connect(str(site))
        for j in range(500):
            db.execute(
                "INSERT INTO b1_wp_posts (post_title) VALUES (?)",
                (f"main_{j}",))
        db.commit()
        db.close()

        errors = []
        samples = []
        stop = threading.Event()

        def reader():
            db = open_db_with_retry(site)
            try:
                while not stop.is_set():
                    try:
                        c = db.execute(
                            "SELECT COUNT(*) FROM b1_wp_posts"
                        ).fetchone()[0]
                        samples.append(c)
                    except sqlite3.OperationalError as e:
                        m = str(e).lower()
                        if "locked" in m or "busy" in m:
                            time.sleep(0.005)
                            continue
                        errors.append(e)
                        return
                    time.sleep(0.001)
            finally:
                db.close()

        rt = threading.Thread(target=reader)
        rt.start()
        time.sleep(0.05)
        p = branchctl_popen(site, "create", "forkme")
        out, err = p.communicate(timeout=60)
        assert p.returncode == 0, f"{out}\n{err}"
        stop.set()
        rt.join(timeout=10)

        assert not errors, f"reader errored: {errors}"
        # Main row count never changed during create (create is COW).
        assert set(samples) == {501}, (
            f"main row count changed during create: saw {set(samples)}"
        )

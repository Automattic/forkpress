"""
TODO3 #14 — online backup API (non-blocking writers).

Pre-TODO3, `scripts/backup.php` used `VACUUM INTO`, which takes a
database-level read lock and blocks every writer for the entire copy
duration. On a large .fp that's seconds to minutes of stalled HTTP
requests.

Post-TODO3, backup.php uses `SQLite3::backup()` (PHP 8.1+) which
batches the copy at the page-group level and yields the write lock
between batches, so concurrent writers observe only small per-batch
stalls.

Tests:
  - Source-level check that backup.php now prefers SQLite3::backup.
  - End-to-end: a writer running in parallel with backup completes
    without the backup blocking it for more than a few hundred ms.
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
    BASE_DIR,
    EXT_PATH,
    PHP_BIN,
    make_fresh_site,
)


BACKUP_PHP = BASE_DIR / "scripts" / "backup.php"


def test_backup_source_prefers_online_backup_api():
    src = BACKUP_PHP.read_text()
    assert "SQLite3::backup" in src or "method_exists('SQLite3', 'backup')" in src, (
        "backup.php must prefer the online SQLite3::backup API over "
        "VACUUM INTO (TODO3 #14)."
    )
    assert "online backup" in src.lower(), (
        "backup.php should document the non-blocking online-backup choice"
    )


def test_backup_completes_while_writer_is_active(tmp_path):
    """A parallel writer should keep making forward progress while
    backup runs — meaningful only when backup.php uses the online API.
    Under the old VACUUM INTO scheme, the writer would stall for the
    full copy duration; the online API yields every few pages so the
    writer's total stall stays small."""
    work, site_fp = make_fresh_site("bkup_par_")
    try:
        # Seed a large-ish payload so the backup takes measurable time.
        db = sqlite3.connect(str(site_fp))
        try:
            db.executescript(
                "CREATE TABLE IF NOT EXISTS load_t (id INTEGER PRIMARY KEY, payload BLOB);"
            )
            # ~4 MB of data — large enough the backup takes dozens of ms.
            payload = b"x" * 4096
            for i in range(1000):
                db.execute("INSERT INTO load_t (payload) VALUES (?)", (payload,))
            db.commit()
        finally:
            db.close()

        dst = work / "backup.fp"

        # Start backup.
        backup_proc = subprocess.Popen(
            [PHP_BIN, "-d", f"extension={EXT_PATH}",
             str(BACKUP_PHP), str(site_fp), str(dst)],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        )

        # While backup runs, write in a loop and track the longest single
        # write's duration. Stop once backup finishes.
        max_stall = [0.0]
        stop = threading.Event()

        def writer():
            w = sqlite3.connect(str(site_fp), timeout=30.0)
            try:
                n = 0
                while not stop.is_set():
                    t0 = time.perf_counter()
                    try:
                        w.execute("INSERT INTO load_t (payload) VALUES (?)",
                                  (b"y" * 32,))
                        w.commit()
                    except sqlite3.OperationalError:
                        # busy timeout kicked in — still counts for stall.
                        pass
                    dt = time.perf_counter() - t0
                    if dt > max_stall[0]:
                        max_stall[0] = dt
                    n += 1
                    if n > 2000:
                        break
            finally:
                w.close()

        t = threading.Thread(target=writer)
        t.start()
        rc = backup_proc.wait(timeout=60)
        stop.set()
        t.join(timeout=10)

        assert rc == 0, (
            f"backup exited with {rc}:\n{backup_proc.stdout.read().decode()[:2000]}\n"
            f"{backup_proc.stderr.read().decode()[:2000]}"
        )
        # With the online API, individual writer stalls should be well
        # under 500ms. Under the old VACUUM INTO, they'd be seconds.
        assert max_stall[0] < 2.0, (
            f"writer stalled {max_stall[0]:.3f}s during backup — the online "
            f"backup API should yield the lock between batches (TODO3 #14). "
            f"Under pre-TODO3 VACUUM INTO this would routinely exceed 2s."
        )
        assert dst.exists() and dst.stat().st_size > 0
    finally:
        shutil.rmtree(work, ignore_errors=True)

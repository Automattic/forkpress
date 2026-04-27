"""
TODO3 #8 — guard against raw PDO misuse.

BranchedPDO's DDL interception only runs when callers actually wrap
their connection with `BranchedPDO::connect(...)`. A developer who
writes `new PDO("sqlite:$site_fp")` directly sees SELECTs work but
silently loses ALTER TABLE / CREATE INDEX routing on branch views.

The new `BranchedPDO::assert_branched()` static helper gives callers a
one-line check to catch that at boot time:

  - pass a BranchedPDO → no-op (returns null)
  - pass a raw PDO → error_log() warning + returns the message
  - pass a raw PDO with FORKPRESS_STRICT_PDO=1 → throws
    RuntimeException

This test file covers all three modes.
"""

import os
import shutil
import subprocess
from pathlib import Path

import pytest

from _rigorous_helpers import (
    BASE_DIR,
    EXT_PATH,
    PHP_BIN,
    make_fresh_site,
)


BRANCHED_PDO = BASE_DIR / "scripts" / "branched_pdo.php"


def test_assert_branched_accepts_branched_pdo(tmp_path):
    work, site_fp = make_fresh_site("g_ok_")
    try:
        php = f"""
require_once {repr(str(BRANCHED_PDO))};
$pdo = BranchedPDO::connect({repr(str(site_fp))}, 'main');
$out = BranchedPDO::assert_branched($pdo, {repr(str(site_fp))});
echo $out === null ? 'OK' : 'WARN: ' . $out;
"""
        r = subprocess.run(
            [PHP_BIN, "-d", f"extension={EXT_PATH}", "-r", php],
            capture_output=True, text=True, timeout=30,
        )
        assert r.returncode == 0, r.stderr
        assert r.stdout.strip() == "OK", r.stdout
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_assert_branched_warns_on_raw_pdo(tmp_path):
    """A raw PDO should trigger a warning (and return the message)."""
    work, site_fp = make_fresh_site("g_raw_")
    try:
        php = f"""
require_once {repr(str(BRANCHED_PDO))};
$pdo = new PDO('sqlite:' . {repr(str(site_fp))});
$out = BranchedPDO::assert_branched($pdo, {repr(str(site_fp))});
echo $out === null ? 'OK' : 'WARN';
"""
        r = subprocess.run(
            [PHP_BIN, "-d", f"extension={EXT_PATH}", "-r", php],
            capture_output=True, text=True, timeout=30,
            env={**os.environ},
        )
        assert r.returncode == 0, r.stderr
        assert "WARN" in r.stdout, r.stdout
        # The raw-PDO warning should land in stderr via error_log.
        assert "raw PDO" in r.stderr or "raw PDO" in r.stdout, (
            f"expected raw-PDO diagnostic; stdout={r.stdout!r}\nstderr={r.stderr!r}"
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_assert_branched_throws_in_strict_mode(tmp_path):
    """With FORKPRESS_STRICT_PDO=1, a raw PDO must raise RuntimeException."""
    work, site_fp = make_fresh_site("g_strict_")
    try:
        php = f"""
require_once {repr(str(BRANCHED_PDO))};
$pdo = new PDO('sqlite:' . {repr(str(site_fp))});
try {{
    BranchedPDO::assert_branched($pdo, {repr(str(site_fp))});
    echo 'NO_THROW';
}} catch (\\RuntimeException $e) {{
    echo 'THROW: ' . $e->getMessage();
}}
"""
        r = subprocess.run(
            [PHP_BIN, "-d", f"extension={EXT_PATH}", "-r", php],
            capture_output=True, text=True, timeout=30,
            env={**os.environ, "FORKPRESS_STRICT_PDO": "1"},
        )
        assert r.returncode == 0, r.stderr
        assert r.stdout.startswith("THROW"), r.stdout
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_assert_branched_null_input_is_noop(tmp_path):
    """Passing null (e.g. an optional PDO arg) should not warn."""
    work, site_fp = make_fresh_site("g_null_")
    try:
        php = f"""
require_once {repr(str(BRANCHED_PDO))};
$out = BranchedPDO::assert_branched(null, {repr(str(site_fp))});
echo $out === null ? 'OK' : 'WARN: ' . $out;
"""
        r = subprocess.run(
            [PHP_BIN, "-d", f"extension={EXT_PATH}", "-r", php],
            capture_output=True, text=True, timeout=30,
        )
        assert r.returncode == 0, r.stderr
        assert r.stdout.strip() == "OK", r.stdout
    finally:
        shutil.rmtree(work, ignore_errors=True)

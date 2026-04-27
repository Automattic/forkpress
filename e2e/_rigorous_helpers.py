"""
Shared helpers for the rigorous adversarial test suites.

One source of truth for fixture setup / CLI invocation / DB inspection,
so each test file can focus on its category and not on boilerplate.
"""

import os
import sqlite3
import subprocess
import tempfile
from pathlib import Path

import pytest

E2E_DIR = Path(__file__).parent
BASE_DIR = E2E_DIR.parent
EXT_PATH = BASE_DIR / "ext" / "branchfs.so"
PHP_BIN = "php"

BRANCHCTL_PHP = BASE_DIR / "scripts" / "branchctl.php"
INIT_DB_PHP = BASE_DIR / "scripts" / "init_db.php"
USER_ADMIN_PHP = BASE_DIR / "scripts" / "user_admin.php"
BRANCHED_PDO_PHP = BASE_DIR / "scripts" / "branched_pdo.php"


def require_ext():
    if not EXT_PATH.exists():
        pytest.skip(f"branchfs.so not found at {EXT_PATH} — run 'make'")


def branchctl(site_fp: Path, *args, timeout: int = 120) -> subprocess.CompletedProcess:
    """Invoke scripts/branchctl.php with the site DB."""
    return subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         str(BRANCHCTL_PHP), *args],
        capture_output=True, text=True, timeout=timeout,
        env={**os.environ, "BRANCHFS_DB": str(site_fp)},
    )


def branchctl_popen(site_fp: Path, *args) -> subprocess.Popen:
    """Non-blocking branchctl for concurrency tests."""
    return subprocess.Popen(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         str(BRANCHCTL_PHP), *args],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
        env={**os.environ, "BRANCHFS_DB": str(site_fp)},
    )


def php_run(site_fp: Path, php_code: str, timeout: int = 120) -> subprocess.CompletedProcess:
    """Run inline PHP with the branchfs extension loaded.

    The code can assume $site_fp holds the site.fp path.
    """
    # Escape site_fp for PHP.
    fp = str(site_fp).replace("\\", "\\\\").replace("'", "\\'")
    full_code = f"<?php $site_fp = '{fp}'; ?>{php_code}"
    return subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         "-d", "display_errors=Off", "-d", "display_startup_errors=Off",
         "-r", full_code[5:]],  # strip leading <?php
        capture_output=True, text=True, timeout=timeout,
    )


def php_script(script: str, timeout: int = 120, env_extra: dict = None) -> subprocess.CompletedProcess:
    """Run a standalone PHP script string via -r."""
    env = {**os.environ}
    if env_extra:
        env.update(env_extra)
    return subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         "-d", "display_errors=Off", "-d", "display_startup_errors=Off",
         "-r", script],
        capture_output=True, text=True, timeout=timeout, env=env,
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


def sqlite_executescript(site_fp: Path, script: str):
    db = sqlite3.connect(str(site_fp))
    try:
        db.executescript(script)
        db.commit()
    finally:
        db.close()


def init_site(site_fp: Path):
    r = subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         str(INIT_DB_PHP), str(site_fp)],
        capture_output=True, text=True, timeout=30,
    )
    if r.returncode != 0:
        raise RuntimeError(f"init_db.php failed: {r.stderr[:500]}\n{r.stdout[:500]}")
    # init_db.php seeds auth_enabled=1 (production default). Flip off for
    # the default testing workflow so existing tests that don't care about
    # auth can invoke branchctl without credentials. Tests that exercise
    # the principal-auth path (test_principal_auth.py, test_auth.py) flip
    # this back via user_admin.php auth-enabled 1 on their own fixtures.
    subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         str(USER_ADMIN_PHP), "auth-enabled", "0"],
        capture_output=True, text=True, timeout=15,
        env={**os.environ, "BRANCHFS_DB": str(site_fp)},
    )


def setup_wp_tables(site_fp: Path):
    """Minimal WP-like tables on main (b1_wp_*) for generic tests."""
    db = sqlite3.connect(str(site_fp))
    db.executescript("""
        CREATE TABLE IF NOT EXISTS b1_wp_options (
            option_id    INTEGER PRIMARY KEY AUTOINCREMENT,
            option_name  TEXT NOT NULL DEFAULT '' UNIQUE,
            option_value TEXT NOT NULL DEFAULT '',
            autoload     TEXT NOT NULL DEFAULT 'yes'
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_b1_wp_options_name
            ON b1_wp_options(option_name);
        INSERT OR IGNORE INTO b1_wp_options (option_name, option_value) VALUES
            ('blogname',      'My Site'),
            ('siteurl',       'http://localhost'),
            ('shared_option', 'original_value');
        CREATE TABLE IF NOT EXISTS b1_wp_posts (
            ID           INTEGER PRIMARY KEY AUTOINCREMENT,
            post_title   TEXT NOT NULL DEFAULT '',
            post_type    TEXT NOT NULL DEFAULT 'post',
            post_content TEXT NOT NULL DEFAULT ''
        );
        INSERT OR IGNORE INTO b1_wp_posts (post_title, post_type, post_content) VALUES
            ('Hello World', 'post', 'orig-content');
        CREATE TABLE IF NOT EXISTS b1_wp_term_relationships (
            object_id        INTEGER NOT NULL DEFAULT 0,
            term_taxonomy_id INTEGER NOT NULL DEFAULT 0,
            term_order       INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (object_id, term_taxonomy_id)
        );
        INSERT OR IGNORE INTO b1_wp_term_relationships VALUES
            (1, 10, 0), (1, 20, 0), (2, 10, 0);
    """)
    db.commit()
    db.close()


def make_fresh_site(prefix="rigorous_"):
    """Create a fresh site.fp with WP tables. Returns (work_dir, site_fp).

    Caller is responsible for cleaning up work_dir with shutil.rmtree().

    Defaults to auth_enabled=0 (legacy mode) via init_site() so existing
    tests that don't care about auth can invoke `branchctl` without
    credentials. Tests exercising the principal-auth path flip it back
    explicitly.
    """
    require_ext()
    work = Path(tempfile.mkdtemp(prefix=prefix))
    site_fp = work / "site.fp"
    init_site(site_fp)
    setup_wp_tables(site_fp)
    return work, site_fp


def branch_id(site_fp: Path, name: str) -> int:
    rows = sqlite_q(site_fp, "SELECT id FROM branches WHERE name = ?", (name,))
    if not rows:
        raise ValueError(f"no branch {name!r}")
    return rows[0][0]


def create_branch(site_fp: Path, name: str, parent: str = "main") -> int:
    args = ["create", name]
    if parent != "main":
        args += ["--from", parent]
    r = branchctl(site_fp, *args)
    if r.returncode != 0:
        raise RuntimeError(f"create {name} failed: {r.stdout}\n{r.stderr}")
    return branch_id(site_fp, name)


def commit_branch(site_fp: Path, name: str, message: str = "test commit") -> subprocess.CompletedProcess:
    return branchctl(site_fp, "commit", name, "-m", message)


def is_view(site_fp: Path, name: str) -> bool:
    rows = sqlite_q(site_fp,
        "SELECT type FROM sqlite_master WHERE name = ?", (name,))
    return bool(rows) and rows[0][0] == "view"


def is_table(site_fp: Path, name: str) -> bool:
    rows = sqlite_q(site_fp,
        "SELECT type FROM sqlite_master WHERE name = ?", (name,))
    return bool(rows) and rows[0][0] == "table"

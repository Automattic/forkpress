"""
Scrupulous invariant tests for ForkPress.

Tests correctness properties that are easy to get wrong and hard to detect
without direct inspection. Each test documents the specific invariant it
checks and WHY it matters.

Two fixture tiers:
  - site_with_branch   no HTTP server needed; uses PHP + branchfs.so directly
  - live_stack         full forkpress binary running on non-default ports

Run just the fast tests (no binary needed):
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_invariants.py \
        -v -m "not live"

Run everything (requires built forkpress):
    FORKPRESS=./target/release/forkpress \
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_invariants.py -v
"""

import os
import re
import shutil
import signal
import sqlite3
import subprocess
import sys
import tempfile
import time
import urllib.request
import urllib.error
from pathlib import Path
from typing import Optional

import pytest

# ── Paths ──────────────────────────────────────────────────────────────────────
E2E_DIR  = Path(__file__).parent
BASE_DIR = E2E_DIR.parent
EXT_PATH = BASE_DIR / "ext" / "branchfs.so"
PHP_BIN  = "php"

HTTP_PORT  = 19680
SFTP_PORT  = 19722
SMB_PORT   = 19788
MYSQL_PORT = 19736
HOST       = "127.0.0.1"


# ── Low-level helpers ──────────────────────────────────────────────────────────

def find_forkpress() -> Optional[Path]:
    if fp := os.environ.get("FORKPRESS"):
        p = Path(fp)
        if p.is_file() and os.access(p, os.X_OK):
            return p
    for c in [
        BASE_DIR / "target" / "release" / "forkpress",
        BASE_DIR / "forkpress" / "target" / "release" / "forkpress",
    ]:
        if c.is_file() and os.access(c, os.X_OK):
            return c
    if fp := shutil.which("forkpress"):
        return Path(fp)
    return None


def php(code: str, env: dict = None, timeout: int = 15) -> str:
    cmd = [PHP_BIN, "-d", f"extension={EXT_PATH}", "-r", code]
    r = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout,
                       env={**os.environ, **(env or {})})
    return r.stdout.strip()


def branchctl(site_fp: Path, *args, timeout: int = 30) -> subprocess.CompletedProcess:
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


def branchfs_write(site_fp: Path, branch: str, path: str, content: str) -> bool:
    out = php(f"""
        branchfs_set_db('{site_fp}');
        echo file_put_contents('branchfs://{branch}/{path}', {repr(content)}) !== false
            ? 'ok' : 'fail';
    """)
    return out == "ok"


def branchfs_read(site_fp: Path, branch: str, path: str) -> str:
    # @ suppresses "failed to open stream" warning so it doesn't leak into stdout
    return php(f"""
        branchfs_set_db('{site_fp}');
        echo @file_get_contents('branchfs://{branch}/{path}') ?: '';
    """)


def http_get(url: str, host: str = None, timeout: int = 10) -> tuple[int, str]:
    req = urllib.request.Request(url)
    if host:
        req.add_header("Host", host)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return r.status, r.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", errors="replace")
    except Exception:
        return 0, ""


def mysql_php(host: str, port: int, branch: str, sql: str) -> str:
    code = f"""
    $c = @new mysqli('{host}', 'root', '', '{branch}', {port});
    if ($c->connect_error) {{ echo 'CONNECT_ERROR:' . $c->connect_error; exit(1); }}
    $r = $c->query({repr(sql)});
    if ($r === false) {{ echo 'SQL_ERROR:' . $c->error; exit(1); }}
    if ($r instanceof mysqli_result) {{
        while ($row = $r->fetch_row()) echo implode('\\t', $row) . "\\n";
        $r->free();
    }}
    while ($c->next_result()) {{ $r2=$c->store_result(); if($r2) $r2->free(); }}
    $c->close();
    """
    r = subprocess.run([PHP_BIN, "-r", code], capture_output=True, text=True, timeout=15)
    return r.stdout.strip()


def wait_for_http(url: str, timeout: int = 180) -> bool:
    deadline = time.time() + timeout
    while time.time() < deadline:
        code, _ = http_get(url, timeout=2)
        if code in (200, 301, 302):
            return True
        time.sleep(2)
    return False


# ── Fixtures ───────────────────────────────────────────────────────────────────

@pytest.fixture(scope="module")
def site_with_branch():
    """
    A site.fp with:
      - main branch (id=1) + realistic b1_wp_options table WITH proper constraints
      - child branch (id=2) created by branchctl, copying the tables

    No HTTP server needed — exercises PHP + branchfs.so + branchctl.php directly.
    Skips if branchfs.so is not built.
    """
    if not EXT_PATH.exists():
        pytest.skip(f"branchfs.so not found — run 'make' in branched-wp/")

    work = Path(tempfile.mkdtemp())
    site_fp = work / "site.fp"

    # Schema (creates branches table + seeds main)
    r = subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         str(BASE_DIR / "scripts" / "init_db.php"), str(site_fp)],
        capture_output=True, text=True, timeout=30,
    )
    if r.returncode != 0:
        pytest.skip(f"init_db.php failed: {r.stderr[:300]}")

    # Create realistic wp_options on main (branch_id=1) WITH UNIQUE constraint,
    # matching what the sqlite-database-integration plugin would produce.
    db = sqlite3.connect(str(site_fp))
    db.executescript("""
        CREATE TABLE IF NOT EXISTS b1_wp_options (
            option_id    INTEGER PRIMARY KEY AUTOINCREMENT,
            option_name  TEXT    NOT NULL DEFAULT '' UNIQUE,
            option_value LONGTEXT NOT NULL DEFAULT '',
            autoload     TEXT    NOT NULL DEFAULT 'yes'
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_b1_wp_options_name
            ON b1_wp_options(option_name);
        INSERT INTO b1_wp_options (option_name, option_value, autoload) VALUES
            ('blogname',  'Test Site',           'yes'),
            ('siteurl',   'http://localhost',    'yes'),
            ('blogdescription', 'Just a test',   'yes'),
            ('wp_user_roles', 'a:1:{s:13:"administrator";}', 'yes');
        CREATE TABLE IF NOT EXISTS b1_wp_users (
            ID           INTEGER PRIMARY KEY AUTOINCREMENT,
            user_login   TEXT    NOT NULL DEFAULT '' UNIQUE,
            user_email   TEXT    NOT NULL DEFAULT ''
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_b1_wp_users_login
            ON b1_wp_users(user_login);
        INSERT INTO b1_wp_users (user_login, user_email) VALUES
            ('admin', 'admin@example.com');
        CREATE TABLE IF NOT EXISTS b1_wp_usermeta (
            umeta_id   INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL DEFAULT 0,
            meta_key   TEXT    DEFAULT NULL,
            meta_value LONGTEXT
        );
        INSERT INTO b1_wp_usermeta (user_id, meta_key, meta_value) VALUES
            (1, 'wp_capabilities', 'a:1:{s:13:"administrator";b:1;}'),
            (1, 'wp_user_level',   '10');
    """)
    db.commit()
    db.close()

    # Create child branch via branchctl
    r = branchctl(site_fp, "create", "child")
    if r.returncode != 0:
        pytest.skip(f"branchctl create failed: {r.stderr[:300]}")

    yield {"site_fp": site_fp, "work": work}
    shutil.rmtree(work, ignore_errors=True)


@pytest.fixture(scope="module")
def live_stack():
    """Full forkpress instance on non-default ports."""
    fp = find_forkpress()
    if fp is None:
        pytest.skip("forkpress binary not found — build with: cargo build --release")

    work = Path(tempfile.mkdtemp())
    proc = None
    try:
        log = open(work / "fp.log", "w")
        proc = subprocess.Popen(
            [str(fp), "start",
             "--work-dir", str(work), "--host", HOST,
             "--port", str(HTTP_PORT), "--sftp-port", str(SFTP_PORT),
             "--smb-port", str(SMB_PORT), "--mysql-port", str(MYSQL_PORT),
             "--site-title", "InvariantTest"],
            stdout=log, stderr=log,
        )
        if not wait_for_http(f"http://{HOST}:{HTTP_PORT}/"):
            raise RuntimeError(
                "forkpress did not come up.\n" +
                (work / "fp.log").read_text()[-2000:]
            )
        yield {"fp": fp, "proc": proc, "work": work,
               "site_fp": work / "site.fp",
               "http_base": f"http://{HOST}:{HTTP_PORT}"}
    finally:
        if proc and proc.poll() is None:
            proc.send_signal(signal.SIGTERM)
            proc.wait(timeout=10)
        shutil.rmtree(work, ignore_errors=True)


def fp_branch(live: dict, *args) -> subprocess.CompletedProcess:
    return subprocess.run(
        [str(live["fp"]), "branch", "--work-dir", str(live["work"]), *args],
        capture_output=True, text=True, timeout=30,
    )


# ══════════════════════════════════════════════════════════════════════════════
# INVARIANT 1 — Branch DB tables must preserve UNIQUE/PK constraints
#
# branchctl uses  CREATE TABLE new AS SELECT * FROM old.
# In SQLite this produces a table with the same column names and data, but
# with ALL constraints stripped (PRIMARY KEY, UNIQUE, indexes, defaults).
#
# For WordPress this means:
#   - duplicate option_name rows can be inserted → get_option() returns garbage
#   - duplicate user_login rows → two admins with same login
# ══════════════════════════════════════════════════════════════════════════════

class TestBranchTableConstraints:

    def _child_id(self, site_fp):
        rows = sqlite_q(site_fp, "SELECT id FROM branches WHERE name='child'")
        assert rows, "child branch not in branches table"
        return rows[0][0]

    def test_branch_options_table_has_unique_index(self, site_with_branch):
        """
        The copied b{id}_wp_options must have a unique constraint on option_name.
        Without it, concurrent WP requests can create duplicate rows for the
        same option, and get_option() returns an unpredictable value.

        Under COW, b{id}_wp_options is a view backed by an overlay table —
        the UNIQUE index lives on b{id}_wp_options__overlay.
        """
        site_fp = site_with_branch["site_fp"]
        cid = self._child_id(site_fp)
        table = f"b{cid}_wp_options"

        # If COW: walk to overlay for index/DDL inspection.
        type_rows = sqlite_q(site_fp,
            "SELECT type FROM sqlite_master WHERE name=?", (table,))
        if type_rows and type_rows[0][0] == "view":
            table = f"b{cid}_wp_options__overlay"

        indexes = sqlite_q(site_fp,
            "SELECT name, sql FROM sqlite_master "
            "WHERE type='index' AND tbl_name=? AND sql LIKE '%option_name%'",
            (table,))

        table_ddl = sqlite_q(site_fp,
            "SELECT sql FROM sqlite_master WHERE type='table' AND name=?",
            (table,))
        ddl = table_ddl[0][0] if table_ddl else ""

        has_unique = len(indexes) > 0 or "UNIQUE" in ddl.upper()
        assert has_unique, (
            f"MISSING UNIQUE constraint on {table}.option_name\n\n"
            f"Root cause: CREATE TABLE {table} AS SELECT * FROM b1_wp_options\n"
            f"strips all SQLite constraints.  WordPress's update_option() does\n"
            f"INSERT OR REPLACE which requires a unique key to replace correctly;\n"
            f"without it, every update appends a new row instead of replacing.\n\n"
            f"Fix: after copying, run:\n"
            f"  CREATE UNIQUE INDEX {table}_name ON {table}(option_name);\n"
            f"Current DDL: {ddl[:200]}"
        )

    def test_duplicate_option_name_raises_integrity_error(self, site_with_branch):
        """
        SQLite must actually enforce the unique constraint — not just declare it.
        INSERT of a duplicate option_name must raise IntegrityError.
        """
        site_fp = site_with_branch["site_fp"]
        cid = self._child_id(site_fp)
        table = f"b{cid}_wp_options"

        db = sqlite3.connect(str(site_fp))
        try:
            db.execute(f"INSERT OR IGNORE INTO {table}(option_name,option_value,autoload) "
                       f"VALUES('_dup_test','v1','no')")
            db.commit()
            with pytest.raises(sqlite3.IntegrityError,
                               match="UNIQUE constraint failed"):
                db.execute(f"INSERT INTO {table}(option_name,option_value,autoload) "
                           f"VALUES('_dup_test','v2','no')")
                db.commit()
        finally:
            db.execute(f"DELETE FROM {table} WHERE option_name='_dup_test'")
            db.commit()
            db.close()

    def test_branch_table_data_matches_parent(self, site_with_branch):
        """Branch creation must copy all rows from the parent, not just the schema."""
        site_fp = site_with_branch["site_fp"]
        cid = self._child_id(site_fp)

        parent_rows = sqlite_q(site_fp,
            "SELECT option_name, option_value FROM b1_wp_options ORDER BY option_name")
        child_rows  = sqlite_q(site_fp,
            f"SELECT option_name, option_value FROM b{cid}_wp_options ORDER BY option_name")

        assert parent_rows == child_rows, (
            f"Branch tables have different data than parent immediately after create.\n"
            f"Parent rows: {parent_rows}\n"
            f"Child rows:  {child_rows}"
        )

    def test_branch_and_main_have_independent_data(self, site_with_branch):
        """
        After updating blogname on the branch, main's blogname must be unchanged.
        If the tables share rows (impossible with real copy, but verify anyway),
        this catches any aliasing bug.
        """
        site_fp = site_with_branch["site_fp"]
        cid = self._child_id(site_fp)

        db = sqlite3.connect(str(site_fp))
        try:
            db.execute(f"UPDATE b{cid}_wp_options SET option_value='BranchOnly' "
                       f"WHERE option_name='blogname'")
            db.commit()
        finally:
            db.close()

        main_val = sqlite_q(site_fp,
            "SELECT option_value FROM b1_wp_options WHERE option_name='blogname'")
        child_val = sqlite_q(site_fp,
            f"SELECT option_value FROM b{cid}_wp_options WHERE option_name='blogname'")

        assert main_val[0][0] == "Test Site", \
            f"Main's blogname changed after updating branch! Got '{main_val[0][0]}'"
        assert child_val[0][0] == "BranchOnly", \
            f"Branch blogname update didn't stick. Got '{child_val[0][0]}'"


# ══════════════════════════════════════════════════════════════════════════════
# INVARIANT 2 — router.php must set the branch table prefix for HTTP requests
#
# launcher.php correctly sets $GLOBALS['_branchfs_table_prefix'] = "b{id}_wp_"
# before WordPress loads. But router.php (used by forkpress) does NOT call
# launcher.php — it only calls branchfs_set_branch() for filesystem routing.
#
# Result: every HTTP request uses b1_wp_* tables regardless of branch.
# Database isolation (F6) is broken for HTTP.
# ══════════════════════════════════════════════════════════════════════════════

@pytest.mark.live
class TestBranchTablePrefixInHTTP:

    def test_branch_http_uses_branch_db_not_main(self, live_stack):
        """
        Update b{branch_id}_wp_options.blogname directly in SQLite.
        An HTTP request to the branch subdomain MUST show the updated title.

        If router.php doesn't set the table prefix global, WordPress falls back
        to b1_wp_options (main) for every branch → all branches look identical.
        """
        site_fp = live_stack["site_fp"]

        fp_branch(live_stack, "create", "prefix-http-test")

        rows = sqlite_q(site_fp, "SELECT id FROM branches WHERE name='prefix-http-test'")
        assert rows, "branch not in SQLite"
        bid = rows[0][0]

        unique_title = f"BRANCH_ONLY_{int(time.time())}"
        sqlite_exec(site_fp,
            f"UPDATE b{bid}_wp_options SET option_value=? WHERE option_name='blogname'",
            (unique_title,))

        time.sleep(0.5)  # ensure PHP server picks up the WAL commit

        code, body = http_get(
            f"{live_stack['http_base']}/",
            host="prefix-http-test.localhost",
        )
        assert code == 200, f"Branch HTTP returned {code}"
        assert unique_title in body, (
            f"CRITICAL: branch HTTP response does not show branch-specific title.\n"
            f"Expected title: {unique_title!r}\n"
            f"Response body excerpt:\n{body[:500]}\n\n"
            f"Root cause: router.php calls branchfs_set_branch() for file routing\n"
            f"but never sets $GLOBALS['_branchfs_table_prefix'], so wp-config.php\n"
            f"falls back to 'b1_wp_' for every branch.\n\n"
            f"Fix: in router.php, after resolving $branch, add:\n"
            f"  $db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);\n"
            f"  $bid = $db->querySingle(\"SELECT id FROM branches WHERE name='$branch'\");\n"
            f"  $db->close();\n"
            f"  $GLOBALS['_branchfs_table_prefix'] = 'b' . ($bid ?: 1) . '_wp_';"
        )

    def test_main_uncontaminated_by_branch_db_write(self, live_stack):
        """Writing to the branch table must not change main's data."""
        site_fp = live_stack["site_fp"]

        main_title_rows = sqlite_q(site_fp,
            "SELECT option_value FROM b1_wp_options WHERE option_name='blogname'")
        expected_main = main_title_rows[0][0] if main_title_rows else "InvariantTest"

        code, body = http_get(live_stack["http_base"] + "/")
        assert code == 200
        assert expected_main in body, \
            f"Main HTTP should show '{expected_main}', got:\n{body[:300]}"

    def test_x_branchfs_branch_header(self, live_stack):
        """router.php must emit X-BranchFS-Branch so branch routing is auditable."""
        req = urllib.request.Request(live_stack["http_base"] + "/")
        req.add_header("Host", "prefix-http-test.localhost")
        try:
            with urllib.request.urlopen(req, timeout=10) as resp:
                hdr = resp.headers.get("X-BranchFS-Branch", "")
        except urllib.error.HTTPError as e:
            hdr = e.headers.get("X-BranchFS-Branch", "")
        assert hdr == "prefix-http-test", \
            f"Expected X-BranchFS-Branch: prefix-http-test, got '{hdr}'"


# ══════════════════════════════════════════════════════════════════════════════
# INVARIANT 3 — MySQL proxy must not mangle string literal values
#
# mysql_proxy.rs rewrites  sql.replace("wp_", prefix)  on every query.
# This replaces "wp_" inside string literals too:
#
#   UPDATE wp_options SET option_value='wp_capabilities' ...
#   →  UPDATE b1_wp_options SET option_value='b1_wp_capabilities' ...
#
# WordPress stores role slugs ('wp_user_roles'), capability keys
# ('wp_capabilities'), and meta keys ('wp_user_level') as plain strings
# in the database. All of these get silently corrupted on write.
# ══════════════════════════════════════════════════════════════════════════════

@pytest.mark.live
class TestMysqlProxyRewriting:

    @pytest.fixture(autouse=True)
    def need_mysql_proxy(self, live_stack):
        result = mysql_php(HOST, MYSQL_PORT, "main", "SELECT 1")
        if not result or "CONNECT_ERROR" in result:
            pytest.skip(f"MySQL proxy not reachable on {HOST}:{MYSQL_PORT}")

    def test_string_literal_with_wp_prefix_not_rewritten(self, live_stack):
        """
        Writing option_value='wp_capabilities' must store exactly that string,
        not 'b1_wp_capabilities'. The naive str.replace corrupts this value,
        which breaks WordPress permission checks.
        """
        mysql_php(HOST, MYSQL_PORT, "main",
            "INSERT OR REPLACE INTO wp_options"
            "(option_name, option_value, autoload) "
            "VALUES('_proxy_rewrite_test', 'wp_capabilities_preserved', 'no')")

        result = mysql_php(HOST, MYSQL_PORT, "main",
            "SELECT option_value FROM wp_options "
            "WHERE option_name='_proxy_rewrite_test'")

        assert result == "wp_capabilities_preserved", (
            f"MySQL proxy CORRUPTED the string literal.\n"
            f"Expected: 'wp_capabilities_preserved'\n"
            f"Got:      '{result}'\n\n"
            f"Root cause: mysql_proxy.rs does sql.replace('wp_', prefix) on the\n"
            f"entire SQL string, including characters inside single-quoted literals.\n\n"
            f"Fix: use a regex that only replaces wp_ when it appears as an\n"
            f"identifier (not inside quotes):\n"
            f"  re.replace(r\"(?<!')\\bwp_\", prefix, sql)\n"
            f"Or ideally use a proper SQL tokeniser."
        )

    def test_usermeta_meta_key_values_not_rewritten_on_read(self, live_stack):
        """
        wp_usermeta.meta_key stores values like 'wp_capabilities'.
        These must come back from a SELECT as 'wp_capabilities', not
        'b1_wp_capabilities'. The proxy should only rewrite table names,
        not result column values — but the naive approach rewrites both.
        """
        result = mysql_php(HOST, MYSQL_PORT, "main",
            "SELECT meta_key FROM wp_usermeta WHERE meta_key LIKE 'wp_%' LIMIT 5")

        if not result:
            pytest.skip("wp_usermeta has no wp_* meta keys — WordPress not installed")

        for line in result.splitlines():
            key = line.strip()
            if not key:
                continue
            assert not re.match(r'^b\d+_wp_', key), (
                f"meta_key value '{key}' was rewritten to include branch prefix.\n"
                f"The proxy corrupts meta_key VALUES that start with 'wp_'.\n"
                f"This breaks wp_get_current_user() and all capability checks."
            )

    def test_where_clause_string_not_rewritten(self, live_stack):
        """
        SELECT ... WHERE meta_key='wp_capabilities' must not become
        WHERE meta_key='b1_wp_capabilities' — that query returns no rows,
        silently stripping every user of their permissions.
        """
        count_str = mysql_php(HOST, MYSQL_PORT, "main",
            "SELECT COUNT(*) FROM wp_usermeta WHERE meta_key='wp_capabilities'")

        count_rewritten = mysql_php(HOST, MYSQL_PORT, "main",
            "SELECT COUNT(*) FROM wp_usermeta WHERE meta_key='b1_wp_capabilities'")

        try:
            count_rewritten_int = int(count_rewritten.strip())
        except ValueError:
            count_rewritten_int = 0

        assert count_rewritten_int == 0, (
            f"Found {count_rewritten_int} rows with meta_key='b1_wp_capabilities'.\n"
            f"The proxy rewrote 'wp_capabilities' inside a string literal on write,\n"
            f"so the data is stored corrupted and queries for 'wp_capabilities' \n"
            f"return nothing, making every user appear to have no permissions."
        )


# ══════════════════════════════════════════════════════════════════════════════
# INVARIANT 4 — Filesystem COW isolation
# ══════════════════════════════════════════════════════════════════════════════

class TestFilesystemCOWIsolation:

    @pytest.fixture(autouse=True)
    def need_ext(self):
        if not EXT_PATH.exists():
            pytest.skip("branchfs.so not found")

    def test_file_written_to_branch_not_visible_on_main(self, site_with_branch):
        marker = f"cow-{int(time.time())}.txt"
        content = f"branch-only-{int(time.time())}"
        site_fp = site_with_branch["site_fp"]

        assert branchfs_write(site_fp, "child", marker, content)
        assert branchfs_read(site_fp, "main",  marker) == "", \
            "Branch file leaked to main — COW is broken"
        assert branchfs_read(site_fp, "child", marker) == content, \
            "Branch file not readable on the branch it was written to"

    def test_overwriting_shared_file_on_branch_does_not_affect_main(self, site_with_branch):
        """
        A file written to main is inherited (shared blob) by child.
        Overwriting it on child must not change main's copy — COW must
        create a new blob for the branch rather than mutating the shared one.
        """
        site_fp = site_with_branch["site_fp"]

        # Write a file to main so the child inherits it as a shared blob.
        shared_content = "<?php /* shared-cow-test-original */ ?>"
        assert branchfs_write(site_fp, "main", "cow-test-shared.php", shared_content), \
            "Could not write shared file to main"

        original = branchfs_read(site_fp, "main", "cow-test-shared.php")
        assert original == shared_content, "Shared file not readable on main"

        # Child inherits via COW — readable without an explicit write.
        inherited = branchfs_read(site_fp, "child", "cow-test-shared.php")
        assert inherited == shared_content, "Child did not inherit shared file from main"

        # Overwrite on child — must NOT affect main.
        override = "<?php /* COW TEST OVERRIDE — branch-only */ ?>"
        assert branchfs_write(site_fp, "child", "cow-test-shared.php", override)

        after_main = branchfs_read(site_fp, "main", "cow-test-shared.php")
        after_child = branchfs_read(site_fp, "child", "cow-test-shared.php")

        assert after_main == shared_content, (
            "Overwriting cow-test-shared.php on child changed main's copy.\n"
            "COW is mutating the shared blob instead of creating a new one."
        )
        assert after_child == override, "Child's override was not stored correctly"


# ══════════════════════════════════════════════════════════════════════════════
# INVARIANT 5 — Branch delete removes its tables
# ══════════════════════════════════════════════════════════════════════════════

class TestBranchDeleteCleansDB:

    def test_delete_drops_wp_tables(self, site_with_branch):
        """b{id}_wp_* tables must be gone after branch delete."""
        site_fp = site_with_branch["site_fp"]

        branchctl(site_fp, "create", "del-test")
        rows = sqlite_q(site_fp, "SELECT id FROM branches WHERE name='del-test'")
        assert rows, "del-test branch not created"
        bid = rows[0][0]

        prefix = f"b{bid}_wp_"
        before = sqlite_q(site_fp,
            "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE ?",
            (prefix + "%",))
        assert before, f"No {prefix}* tables created for branch"

        branchctl(site_fp, "delete", "del-test")

        after = sqlite_q(site_fp,
            "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE ?",
            (prefix + "%",))
        assert after == [], (
            f"After delete, {len(after)} {prefix}* tables remain:\n" +
            "\n".join(t[0] for t in after)
        )

    def test_delete_removes_branch_from_list(self, site_with_branch):
        site_fp = site_with_branch["site_fp"]
        branchctl(site_fp, "create", "vanish")
        branchctl(site_fp, "delete", "vanish")
        r = branchctl(site_fp, "list")
        assert "vanish" not in r.stdout

    def test_delete_removes_db_snapshots_rows(self, site_with_branch):
        """
        After `branchctl delete <branch>`, every per-branch row in
        ancestor-tracking tables (legacy db_snapshots; COW db_cow_branches /
        db_ancestor_overlay / db_post_fork_inserts) must be gone.
        Otherwise per-branch rows accumulate forever on sites with high
        branch churn (CI previews, per-PR branches).
        """
        site_fp = site_with_branch["site_fp"]

        branchctl(site_fp, "create", "snap-leak")
        rows = sqlite_q(site_fp, "SELECT id FROM branches WHERE name='snap-leak'")
        assert rows, "snap-leak branch not created"
        bid = rows[0][0]

        # Either (legacy) db_snapshots or (COW) db_cow_branches must hold
        # an ancestor reference per branch — pick whichever applies.
        legacy_n = sqlite_q(site_fp,
            "SELECT COUNT(*) FROM db_snapshots WHERE branch_id=?", (bid,))[0][0]
        cow_n = sqlite_q(site_fp,
            "SELECT COUNT(*) FROM db_cow_branches WHERE branch_id=?", (bid,))[0][0]
        assert (legacy_n > 0) or (cow_n > 0), (
            "branchctl create must record an ancestor reference for the new "
            "branch (db_snapshots in legacy format, db_cow_branches in COW)."
        )

        branchctl(site_fp, "delete", "snap-leak")

        # After delete, BOTH legacy and COW per-branch rows must be gone.
        for table in ("db_snapshots", "db_cow_branches", "db_ancestor_overlay",
                      "db_post_fork_inserts", "db_snapshots_schema"):
            try:
                n = sqlite_q(site_fp,
                    f"SELECT COUNT(*) FROM {table} WHERE branch_id=?", (bid,))[0][0]
            except Exception:
                continue # table may not exist on older DBs
            assert n == 0, (
                f"After delete, {n} {table} rows remain for branch id={bid}."
            )


# ══════════════════════════════════════════════════════════════════════════════
# INVARIANT 6 — Rollback correctness
# ══════════════════════════════════════════════════════════════════════════════

class TestRollbackCorrectness:

    @pytest.fixture(autouse=True)
    def need_ext(self):
        if not EXT_PATH.exists():
            pytest.skip("branchfs.so not found")

    def test_uncommitted_file_gone_after_rollback(self, site_with_branch):
        """
        Rollback goes to HEAD-1. Files written after the checkpoint commit
        (committed or not) must be gone; files in the checkpoint must remain.

        branchctl rollback refuses if there are uncommitted changes unless
        --force is passed — this test uses --force to test the core mechanic.
        """
        site_fp = site_with_branch["site_fp"]

        branchctl(site_fp, "create", "rb-test")

        # Commit 1: baseline — create a file that should survive rollback
        branchfs_write(site_fp, "rb-test", "baseline.txt", "baseline-content")
        branchctl(site_fp, "commit", "rb-test", "-m", "baseline")

        # Commit 2: checkpoint — the state we roll back TO
        branchfs_write(site_fp, "rb-test", "checkpoint.txt", "checkpoint-content")
        branchctl(site_fp, "commit", "rb-test", "-m", "checkpoint")

        # Write another file AFTER checkpoint (not committed)
        branchfs_write(site_fp, "rb-test", "after-checkpoint.txt", "lose-this")

        # rollback --force: go to HEAD-1 = "baseline" commit
        # (after-checkpoint.txt is gone; checkpoint.txt is also gone)
        r = branchctl(site_fp, "rollback", "rb-test", "--force")
        assert r.returncode == 0, f"rollback --force failed: {r.stderr}"

        assert branchfs_read(site_fp, "rb-test", "after-checkpoint.txt") == "", \
            "Post-checkpoint uncommitted file still present after rollback"

        assert branchfs_read(site_fp, "rb-test", "checkpoint.txt") == "", \
            "checkpoint.txt should be gone (rollback went to baseline, HEAD-1)"

        assert branchfs_read(site_fp, "rb-test", "baseline.txt") == "baseline-content", \
            "baseline.txt (from HEAD-1) missing after rollback"

        branchctl(site_fp, "delete", "rb-test")

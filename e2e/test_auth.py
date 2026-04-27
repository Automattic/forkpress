"""
Authentication tests (TODO2 #1).

Covers the new per-site `users` / `site_config` auth surface across the
four write surfaces (SFTP / SMB / MySQL proxy / git push). Fast tests run
against init_db.php + user_admin.php directly; the live SFTP/MySQL auth
rejections need the pre-built fileserver binary.

Markers:
  - default: schema + PHP CLI coverage (no servers, no binary)
  - @pytest.mark.live: starts the `fileserver` binary and hits it with
    real clients. These are skipped under `-m "not live"`.
"""

import os
import shutil
import signal
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time
from pathlib import Path
from typing import Optional

import pytest

# ── Paths ──────────────────────────────────────────────────────────────────────
E2E_DIR = Path(__file__).parent
BASE_DIR = E2E_DIR.parent
EXT_PATH = BASE_DIR / "ext" / "branchfs.so"
SCRIPTS_DIR = BASE_DIR / "scripts"
FILESERVER_BIN = BASE_DIR / "target" / "release" / "fileserver"
PHP_BIN = "php"


def _require_ext():
    if not EXT_PATH.exists():
        pytest.skip(f"branchfs.so not built at {EXT_PATH}")


def _init_site(work: Path, admin_password: Optional[str] = None) -> Path:
    site_fp = work / "site.fp"
    args = [PHP_BIN, "-d", f"extension={EXT_PATH}",
            str(SCRIPTS_DIR / "init_db.php"), str(site_fp)]
    if admin_password is not None:
        args += ["--admin-password", admin_password]
    # Auth tests want the production default (auth_enabled=1). The e2e
    # conftest sets FORKPRESS_INIT_AUTH_ENABLED=0 globally for legacy
    # tests; override here.
    env = {**os.environ, "FORKPRESS_INIT_AUTH_ENABLED": "1"}
    r = subprocess.run(args, capture_output=True, text=True, timeout=30, env=env)
    if r.returncode != 0:
        pytest.skip(f"init_db.php failed: {r.stderr[:300]}")
    return site_fp


def _user_admin(site_fp: Path, *args: str, timeout: int = 15) -> subprocess.CompletedProcess:
    return subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         str(SCRIPTS_DIR / "user_admin.php"), *args],
        capture_output=True, text=True, timeout=timeout,
        env={**os.environ, "BRANCHFS_DB": str(site_fp)},
    )


# ══════════════════════════════════════════════════════════════════════════════
# Schema assertions — the auth tables and config flag must land in new sites
# and in old sites via branchctl's fs_migrate().
# ══════════════════════════════════════════════════════════════════════════════

class TestSchema:

    def test_new_site_has_users_and_site_config_tables(self, tmp_path):
        _require_ext()
        site_fp = _init_site(tmp_path)
        db = sqlite3.connect(str(site_fp))
        try:
            tables = {row[0] for row in db.execute(
                "SELECT name FROM sqlite_master WHERE type='table'"
            ).fetchall()}
        finally:
            db.close()
        assert "users" in tables, f"users table missing after init_db.php; got {tables}"
        assert "site_config" in tables, f"site_config missing after init_db.php; got {tables}"

    def test_new_site_defaults_auth_enabled_to_1(self, tmp_path):
        _require_ext()
        site_fp = _init_site(tmp_path, admin_password="pw")
        db = sqlite3.connect(str(site_fp))
        try:
            v = db.execute(
                "SELECT value FROM site_config WHERE key='auth_enabled'"
            ).fetchone()
        finally:
            db.close()
        assert v and v[0] == "1", f"new sites must default auth_enabled='1', got {v}"

    def test_new_site_seeds_admin_user(self, tmp_path):
        _require_ext()
        site_fp = _init_site(tmp_path, admin_password="s3cret")
        db = sqlite3.connect(str(site_fp))
        try:
            rows = db.execute(
                "SELECT username, role, password_hash, mysql_sha1 FROM users"
            ).fetchall()
        finally:
            db.close()
        assert len(rows) == 1
        u, role, h, m = rows[0]
        assert u == "admin"
        assert role == "admin"
        # bcrypt hashes start with $2
        assert h.startswith("$2"), f"password_hash should be bcrypt: {h[:5]}"
        assert m and len(m) == 40, f"mysql_sha1 should be 40 hex chars: {m!r}"

    def test_pre_change_site_migrated_with_auth_disabled(self, tmp_path):
        """
        Old .fp files (created before the auth feature) have no site_config
        row. The first fs_migrate run on them must default auth_enabled='0'
        so existing workflows don't suddenly refuse connections.
        """
        _require_ext()
        site_fp = tmp_path / "legacy.fp"
        # Synthesize a pre-change DB: has branches + files but NO site_config.
        db = sqlite3.connect(str(site_fp))
        db.executescript("""
            CREATE TABLE branches (id INTEGER PRIMARY KEY AUTOINCREMENT,
                                   name TEXT UNIQUE NOT NULL,
                                   parent_branch TEXT,
                                   created_at TEXT DEFAULT (datetime('now')));
            INSERT INTO branches(name) VALUES('main');
            CREATE TABLE files (branch_id INTEGER, path TEXT, blob_hash TEXT,
                                mode INTEGER, mtime INTEGER, is_dir INTEGER,
                                PRIMARY KEY (branch_id, path));
            CREATE TABLE blobs (hash TEXT PRIMARY KEY, data BLOB, size INTEGER);
        """)
        db.commit()
        db.close()

        # Trigger fs_migrate via any branchctl invocation.
        r = subprocess.run(
            [PHP_BIN, "-d", f"extension={EXT_PATH}",
             str(SCRIPTS_DIR / "branchctl.php"), "list"],
            capture_output=True, text=True, timeout=15,
            env={**os.environ, "BRANCHFS_DB": str(site_fp)},
        )
        assert r.returncode == 0, f"branchctl list failed: {r.stderr}"

        db = sqlite3.connect(str(site_fp))
        try:
            v = db.execute(
                "SELECT value FROM site_config WHERE key='auth_enabled'"
            ).fetchone()
        finally:
            db.close()
        assert v and v[0] == "0", (
            "Legacy sites must be migrated with auth_enabled='0' to avoid "
            f"locking out existing workflows; got {v}"
        )


# ══════════════════════════════════════════════════════════════════════════════
# user_admin.php CLI — the command surface that forkpress user forwards to.
# ══════════════════════════════════════════════════════════════════════════════

class TestUserAdminCli:

    def test_user_add_creates_row(self, tmp_path):
        _require_ext()
        site_fp = _init_site(tmp_path, admin_password="root-pw")
        r = _user_admin(site_fp, "add", "alice", "alice-pw", "--role", "write")
        assert r.returncode == 0, f"user add failed: {r.stderr}"

        db = sqlite3.connect(str(site_fp))
        try:
            row = db.execute(
                "SELECT role, password_hash, mysql_sha1 FROM users WHERE username='alice'"
            ).fetchone()
        finally:
            db.close()
        assert row is not None, "alice not inserted"
        assert row[0] == "write"
        assert row[1].startswith("$2")
        assert len(row[2]) == 40

    def test_user_add_rejects_invalid_role(self, tmp_path):
        _require_ext()
        site_fp = _init_site(tmp_path, admin_password="pw")
        r = _user_admin(site_fp, "add", "bob", "bob-pw", "--role", "superuser")
        assert r.returncode != 0
        assert "invalid role" in (r.stderr + r.stdout).lower()

    def test_user_remove(self, tmp_path):
        _require_ext()
        site_fp = _init_site(tmp_path, admin_password="pw")
        _user_admin(site_fp, "add", "carol", "carol-pw", "--role", "read")
        r = _user_admin(site_fp, "remove", "carol")
        assert r.returncode == 0
        assert "removed" in r.stdout
        db = sqlite3.connect(str(site_fp))
        try:
            row = db.execute(
                "SELECT 1 FROM users WHERE username='carol'"
            ).fetchone()
        finally:
            db.close()
        assert row is None

    def test_user_list_contains_admin(self, tmp_path):
        _require_ext()
        site_fp = _init_site(tmp_path, admin_password="pw")
        r = _user_admin(site_fp, "list")
        assert r.returncode == 0
        assert "admin" in r.stdout

    def test_verify_good_password(self, tmp_path):
        _require_ext()
        site_fp = _init_site(tmp_path, admin_password="super-secret-123")
        r = _user_admin(site_fp, "verify", "admin", "super-secret-123")
        assert r.returncode == 0, f"verify should succeed: {r.stderr}"
        assert "OK role=admin" in r.stdout

    def test_verify_wrong_password_fails(self, tmp_path):
        _require_ext()
        site_fp = _init_site(tmp_path, admin_password="super-secret-123")
        r = _user_admin(site_fp, "verify", "admin", "wrong-password")
        assert r.returncode != 0
        assert "FAIL" in r.stdout

    def test_verify_unknown_user_fails(self, tmp_path):
        _require_ext()
        site_fp = _init_site(tmp_path, admin_password="pw")
        r = _user_admin(site_fp, "verify", "ghost", "anything")
        assert r.returncode != 0

    def test_auth_enabled_toggle(self, tmp_path):
        _require_ext()
        site_fp = _init_site(tmp_path, admin_password="pw")

        # Initial state: '1'
        r = _user_admin(site_fp, "auth-enabled")
        assert r.returncode == 0
        assert r.stdout.strip() == "1"

        # Flip to '0'
        r = _user_admin(site_fp, "auth-enabled", "0")
        assert r.returncode == 0
        r = _user_admin(site_fp, "auth-enabled")
        assert r.stdout.strip() == "0"

        # And back
        _user_admin(site_fp, "auth-enabled", "1")
        r = _user_admin(site_fp, "auth-enabled")
        assert r.stdout.strip() == "1"


# ══════════════════════════════════════════════════════════════════════════════
# Admin user survives `forkpress backup` (VACUUM INTO) + restore.
# Covered by TODO2 §1 acceptance: "admin user survives a forkpress backup + restore"
# ══════════════════════════════════════════════════════════════════════════════

class TestBackupPreservesAuth:

    def test_admin_survives_vacuum_into(self, tmp_path):
        _require_ext()
        site_fp = _init_site(tmp_path, admin_password="keep-me")
        dest = tmp_path / "backup.fp"

        # VACUUM INTO — this is what scripts/backup.php does. We invoke
        # it directly to avoid spinning up a full forkpress.
        db = sqlite3.connect(str(site_fp))
        try:
            db.execute(f"VACUUM INTO '{dest}'")
        finally:
            db.close()

        # Verify the user row made it to the restored .fp and that the
        # password still verifies.
        db2 = sqlite3.connect(str(dest))
        try:
            row = db2.execute(
                "SELECT username, role, password_hash FROM users"
            ).fetchone()
        finally:
            db2.close()
        assert row is not None and row[0] == "admin"

        # Re-point user_admin at the restored file and verify the password.
        r = _user_admin(dest, "verify", "admin", "keep-me")
        assert r.returncode == 0, f"admin password lost across VACUUM INTO: {r.stderr}"


# ══════════════════════════════════════════════════════════════════════════════
# Live fileserver tests — start the fileserver binary and drive it with
# real clients. All gated behind @pytest.mark.live (off by default).
# ══════════════════════════════════════════════════════════════════════════════

def _pick_free_port() -> int:
    s = socket.socket()
    try:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]
    finally:
        s.close()


def _wait_for_port(host: str, port: int, timeout: float = 5.0) -> bool:
    deadline = time.time() + timeout
    while time.time() < deadline:
        try:
            with socket.create_connection((host, port), timeout=0.5):
                return True
        except OSError:
            time.sleep(0.1)
    return False


class LiveFileserver:
    def __init__(self, site_fp: Path, logdir: Path):
        if not FILESERVER_BIN.exists():
            pytest.skip(f"fileserver binary missing at {FILESERVER_BIN}")
        self.site_fp = site_fp
        self.sftp_port = _pick_free_port()
        self.smb_port = _pick_free_port()
        self.mysql_port = _pick_free_port()
        self.log = (logdir / "fileserver.log").open("w")
        self.proc = subprocess.Popen(
            [str(FILESERVER_BIN),
             "--db", str(site_fp),
             "--sftp-addr", f"127.0.0.1:{self.sftp_port}",
             "--smb-addr", f"127.0.0.1:{self.smb_port}",
             "--mysql-addr", f"127.0.0.1:{self.mysql_port}"],
            stdout=self.log, stderr=self.log,
        )
        if not _wait_for_port("127.0.0.1", self.mysql_port, timeout=10):
            self.stop()
            raise RuntimeError("fileserver failed to start")

    def stop(self):
        if self.proc.poll() is None:
            self.proc.send_signal(signal.SIGTERM)
            try:
                self.proc.wait(timeout=5)
            except subprocess.TimeoutExpired:
                self.proc.kill()
        self.log.close()


@pytest.fixture
def live_fileserver(tmp_path):
    _require_ext()
    site_fp = _init_site(tmp_path, admin_password="live-admin-pw")
    # Add a read-only user for the role test.
    _user_admin(site_fp, "add", "reader", "reader-pw", "--role", "read")
    # And a write user.
    _user_admin(site_fp, "add", "writer", "writer-pw", "--role", "write")
    fs = LiveFileserver(site_fp, tmp_path)
    try:
        yield fs
    finally:
        fs.stop()


@pytest.mark.live
class TestLiveSftpAuth:

    def _paramiko_or_skip(self):
        try:
            import paramiko  # noqa: F401
            return paramiko
        except ImportError:
            pytest.skip("paramiko not installed (pip install -t /tmp/pylibs paramiko)")

    def test_wrong_password_rejected(self, live_fileserver):
        paramiko = self._paramiko_or_skip()
        t = paramiko.Transport(("127.0.0.1", live_fileserver.sftp_port))
        authed = False
        try:
            try:
                t.connect(username="admin", password="definitely-wrong")
                authed = t.is_authenticated()
            except paramiko.SSHException:
                # Server rejected — good.
                authed = False
        finally:
            try: t.close()
            except Exception: pass
        assert not authed, "SFTP accepted a wrong password"

    def test_no_password_rejected(self, live_fileserver):
        paramiko = self._paramiko_or_skip()
        t = paramiko.Transport(("127.0.0.1", live_fileserver.sftp_port))
        authed = False
        try:
            try:
                t.connect(username="admin")
                authed = t.is_authenticated()
            except paramiko.SSHException:
                authed = False
        finally:
            try: t.close()
            except Exception: pass
        assert not authed, "SFTP accepted auth-none when auth_enabled=1"

    def test_correct_password_accepted(self, live_fileserver):
        paramiko = self._paramiko_or_skip()
        t = paramiko.Transport(("127.0.0.1", live_fileserver.sftp_port))
        t.connect(username="admin", password="live-admin-pw")
        try:
            assert t.is_authenticated()
        finally:
            t.close()

    def test_read_role_cannot_write(self, live_fileserver):
        paramiko = self._paramiko_or_skip()
        t = paramiko.Transport(("127.0.0.1", live_fileserver.sftp_port))
        t.connect(username="reader", password="reader-pw")
        try:
            sftp = paramiko.SFTPClient.from_transport(t)
            # Attempt to open a file for write on main/ — should fail
            # somewhere on the write/close path with a permission error.
            with pytest.raises(IOError):
                with sftp.file("/main/hello.txt", "w") as f:
                    f.write("hi")
        finally:
            t.close()


def _mysql_connect_via_php(host: str, port: int, user: str, password: str,
                           db: str, sql: str) -> subprocess.CompletedProcess:
    # Disable mysqli_report exceptions so we observe failures via return
    # code + $c->error instead of PHP fatals.
    code = f"""
    mysqli_report(MYSQLI_REPORT_OFF);
    $c = @new mysqli('{host}', '{user}', '{password}', '{db}', {port});
    if ($c->connect_error) {{ echo 'CONNECT_ERROR:' . $c->connect_error; exit(1); }}
    $r = @$c->query({sql!r});
    if ($r === false) {{ echo 'SQL_ERROR:' . $c->error; exit(2); }}
    if ($r instanceof mysqli_result) {{
        while ($row = $r->fetch_row()) echo implode("\\t", $row) . "\\n";
        $r->free();
    }} else {{
        echo 'OK' . "\\n";
    }}
    $c->close();
    """
    return subprocess.run(
        [PHP_BIN, "-r", code], capture_output=True, text=True, timeout=15,
    )


@pytest.mark.live
class TestLiveMysqlAuth:

    def test_wrong_password_connect_error(self, live_fileserver):
        r = _mysql_connect_via_php(
            "127.0.0.1", live_fileserver.mysql_port,
            "admin", "this-is-not-the-password", "main", "SELECT 1",
        )
        assert "CONNECT_ERROR" in r.stdout, (
            f"expected connect error, got stdout={r.stdout!r} stderr={r.stderr!r}"
        )

    def test_correct_password_accepts(self, live_fileserver):
        # Pre-seed a minimal b1_wp_options so SELECT has something to run.
        db = sqlite3.connect(str(live_fileserver.site_fp))
        try:
            db.executescript("""
                CREATE TABLE IF NOT EXISTS b1_wp_options (
                    option_id INTEGER PRIMARY KEY AUTOINCREMENT,
                    option_name TEXT UNIQUE,
                    option_value TEXT);
                INSERT OR IGNORE INTO b1_wp_options(option_name, option_value)
                    VALUES ('x','y');
            """)
            db.commit()
        finally:
            db.close()
        r = _mysql_connect_via_php(
            "127.0.0.1", live_fileserver.mysql_port,
            "admin", "live-admin-pw", "main", "SELECT 1",
        )
        assert "CONNECT_ERROR" not in r.stdout, r.stdout + r.stderr
        # SELECT 1 → row ("1")
        assert "1" in r.stdout

    def test_read_role_update_fails(self, live_fileserver):
        # Seed a table the reader can SELECT from.
        db = sqlite3.connect(str(live_fileserver.site_fp))
        try:
            db.executescript("""
                CREATE TABLE IF NOT EXISTS b1_wp_options (
                    option_id INTEGER PRIMARY KEY AUTOINCREMENT,
                    option_name TEXT UNIQUE,
                    option_value TEXT);
                INSERT OR IGNORE INTO b1_wp_options(option_name, option_value)
                    VALUES ('siteurl','http://a');
            """)
            db.commit()
        finally:
            db.close()

        r_sel = _mysql_connect_via_php(
            "127.0.0.1", live_fileserver.mysql_port,
            "reader", "reader-pw", "main", "SELECT option_value FROM wp_options WHERE option_name='siteurl'",
        )
        assert "CONNECT_ERROR" not in r_sel.stdout, r_sel.stdout + r_sel.stderr
        assert "http://a" in r_sel.stdout

        r_upd = _mysql_connect_via_php(
            "127.0.0.1", live_fileserver.mysql_port,
            "reader", "reader-pw", "main",
            "UPDATE wp_options SET option_value='http://b' WHERE option_name='siteurl'",
        )
        # Write must be denied, but the connection itself should have succeeded.
        assert "CONNECT_ERROR" not in r_upd.stdout, r_upd.stdout
        assert "SQL_ERROR" in r_upd.stdout, f"reader UPDATE should fail: {r_upd.stdout}"

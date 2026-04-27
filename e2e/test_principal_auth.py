"""
Hostile-review Cluster B — identity & authorization.

Covers findings #2 (audit actor forgery + deletable log), #5 (assert_branched
dead code), #18 (_ddl subcommand accepts any SQL with no auth).

Redesign: a Principal is resolved once at CLI startup from
  a) --user / --password credentials, or
  b) an HMAC-signed FORKPRESS_TOKEN
and threaded through every audit-emitting operation. The audit_log
table is tamper-resistant via AFTER-INSERT triggers that RAISE(ABORT) on
UPDATE/DELETE. The hidden _ddl subcommand now requires auth AND validates
that the SQL on stdin is genuinely DDL.

All tests in this file MUST fail before implementation (RED), pass after
implementation (GREEN), and co-exist with the pre-existing suite.
"""

import os
import shutil
import sqlite3
import subprocess
import sys
from pathlib import Path

import pytest

from _rigorous_helpers import (
    BASE_DIR,
    EXT_PATH,
    PHP_BIN,
    BRANCHCTL_PHP,
    USER_ADMIN_PHP,
    BRANCHED_PDO_PHP,
    branchctl,
    create_branch,
    init_site,
    make_fresh_site,
    setup_wp_tables,
    sqlite_q,
    sqlite_exec,
)


# ──────────────────────────────────────────────────────────────────────────
# Helpers specific to principal-auth tests
# ──────────────────────────────────────────────────────────────────────────

def _enable_auth(site_fp: Path, admin_user: str = "admin",
                 admin_pw: str = "admin-pw") -> None:
    """Turn on auth and (re)set the admin password."""
    r = subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         str(USER_ADMIN_PHP), "auth-enabled", "1"],
        capture_output=True, text=True, timeout=15,
        env={**os.environ, "BRANCHFS_DB": str(site_fp)},
    )
    assert r.returncode == 0, r.stderr
    r = subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         str(USER_ADMIN_PHP), "add", admin_user, admin_pw, "--role", "admin"],
        capture_output=True, text=True, timeout=15,
        env={**os.environ, "BRANCHFS_DB": str(site_fp)},
    )
    assert r.returncode == 0, r.stderr


def _mint_token(site_fp: Path, username: str, ttl_seconds: int = 300) -> str:
    """Mint a signed FORKPRESS_TOKEN by calling the PHP token helper."""
    php = f"""
require_once {repr(str(BASE_DIR / 'scripts' / 'principal.php'))};
echo principal_mint_token({repr(str(site_fp))}, {repr(username)}, {int(ttl_seconds)});
"""
    r = subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}", "-r", php],
        capture_output=True, text=True, timeout=15,
    )
    assert r.returncode == 0, f"mint failed: {r.stderr}"
    return r.stdout.strip()


def _fresh_site_with_auth(prefix: str) -> tuple[Path, Path]:
    work, site_fp = make_fresh_site(prefix)
    _enable_auth(site_fp, "admin", "admin-pw")
    return work, site_fp


def _fresh_site_no_auth(prefix: str) -> tuple[Path, Path]:
    """auth_enabled=0 (legacy / optional-auth sites)."""
    work, site_fp = make_fresh_site(prefix)
    r = subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         str(USER_ADMIN_PHP), "auth-enabled", "0"],
        capture_output=True, text=True, timeout=15,
        env={**os.environ, "BRANCHFS_DB": str(site_fp)},
    )
    assert r.returncode == 0, r.stderr
    return work, site_fp


# ══════════════════════════════════════════════════════════════════════════
# Finding #2 — audit actor cannot be forged & audit log is tamper-resistant
# ══════════════════════════════════════════════════════════════════════════

def test_audit_actor_cannot_be_forged_via_env():
    """
    With auth_enabled=1 a user runs branchctl under genuine creds. If they
    also set FORKPRESS_ACTOR=victim, the audit_log row must carry the
    authenticated username, NOT the env-supplied value. The env var is
    ignored entirely by the principal resolver.
    """
    work, site_fp = _fresh_site_with_auth("prinauth_env_")
    try:
        # Legitimate user 'alice' writes a branch, attempts to frame 'victim'.
        subprocess.run(
            [PHP_BIN, "-d", f"extension={EXT_PATH}",
             str(USER_ADMIN_PHP), "add", "alice", "alice-pw", "--role", "admin"],
            capture_output=True, text=True, timeout=15,
            env={**os.environ, "BRANCHFS_DB": str(site_fp)},
        )
        r = subprocess.run(
            [PHP_BIN, "-d", f"extension={EXT_PATH}",
             str(BRANCHCTL_PHP), "create", "frame_try",
             "--user", "alice", "--password", "alice-pw"],
            capture_output=True, text=True, timeout=60,
            env={**os.environ,
                 "BRANCHFS_DB": str(site_fp),
                 "FORKPRESS_ACTOR": "victim@example.com"},
        )
        assert r.returncode == 0, r.stderr

        rows = sqlite_q(site_fp,
            "SELECT actor FROM audit_log WHERE action='create' "
            "AND target='frame_try'")
        assert rows, f"no audit row for frame_try; got {rows!r}"
        assert rows[0][0] == "alice", (
            f"audit actor was not the authenticated principal; "
            f"got {rows[0][0]!r} (FORKPRESS_ACTOR would have been 'victim@…')"
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_audit_log_cannot_be_deleted_by_user_writes():
    """
    Users with direct DB access (e.g. through the MySQL proxy, or an
    operator with a sqlite3 shell) must NOT be able to mutate or destroy
    audit_log rows. Triggers on UPDATE / DELETE abort the statement.
    """
    work, site_fp = make_fresh_site("prinauth_del_")
    try:
        # Seed at least one row via normal branchctl create.
        create_branch(site_fp, "seeded")
        n_before = sqlite_q(site_fp, "SELECT COUNT(*) FROM audit_log")[0][0]
        assert n_before >= 1

        db = sqlite3.connect(str(site_fp))
        try:
            with pytest.raises(sqlite3.DatabaseError):
                db.execute("DELETE FROM audit_log")
                db.commit()
        finally:
            db.close()

        db = sqlite3.connect(str(site_fp))
        try:
            with pytest.raises(sqlite3.DatabaseError):
                db.execute("UPDATE audit_log SET actor='spoofed'")
                db.commit()
        finally:
            db.close()

        rows = sqlite_q(site_fp, "SELECT COUNT(*), COUNT(DISTINCT actor) FROM audit_log")
        assert rows[0][0] == n_before, f"rows disappeared: {rows}"
        # No row should have been spoofed.
        r = sqlite_q(site_fp, "SELECT COUNT(*) FROM audit_log WHERE actor='spoofed'")
        assert r[0][0] == 0
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_audit_log_write_failure_surfaces():
    """
    If audit_log_write() fails for any reason, the failure must propagate
    (NOT silently swallowed). We force a failure by dropping the audit_log
    table mid-run, then invoking a branchctl subcommand that calls
    audit_log_write → the CLI exits non-zero and prints a diagnostic.
    """
    work, site_fp = make_fresh_site("prinauth_fail_")
    try:
        # Drop the audit_log table to simulate corruption. branchctl's
        # fs_migrate CREATE TABLE IF NOT EXISTS would recreate it on the
        # next run; to prevent that, replace it with a table that is missing
        # the 'details' column so INSERT binds fail.
        db = sqlite3.connect(str(site_fp))
        try:
            # Ensure audit_log exists first (invoke fs_migrate via a no-op cmd).
            pass
        finally:
            db.close()
        # Force fs_migrate to run.
        r = branchctl(site_fp, "list")
        assert r.returncode == 0

        # Replace audit_log with a broken schema. fs_migrate won't re-create
        # it because CREATE TABLE IF NOT EXISTS only fires when absent.
        db = sqlite3.connect(str(site_fp))
        try:
            db.executescript("""
                DROP TRIGGER IF EXISTS audit_log_no_update;
                DROP TRIGGER IF EXISTS audit_log_no_delete;
                DROP TABLE audit_log;
                CREATE TABLE audit_log (wrong_schema TEXT);
            """)
            db.commit()
        finally:
            db.close()

        r = branchctl(site_fp, "create", "will_fail")
        # Must not exit 0 — audit write failure was silently swallowed before.
        assert r.returncode != 0, (
            f"expected non-zero exit on audit failure; got stdout={r.stdout!r} "
            f"stderr={r.stderr!r}"
        )
        assert "audit" in (r.stderr + r.stdout).lower(), (
            f"expected audit-failure diagnostic; got {r.stderr!r}"
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


# ══════════════════════════════════════════════════════════════════════════
# Finding #2 / new design — credentials & tokens drive the principal
# ══════════════════════════════════════════════════════════════════════════

def test_branchctl_requires_credentials_when_auth_enabled():
    work, site_fp = _fresh_site_with_auth("prinauth_req_")
    try:
        # No --user/--password, no token: auth_enabled=1 means rejection.
        env = {k: v for k, v in os.environ.items() if k != "FORKPRESS_TOKEN"}
        env["BRANCHFS_DB"] = str(site_fp)
        r = subprocess.run(
            [PHP_BIN, "-d", f"extension={EXT_PATH}",
             str(BRANCHCTL_PHP), "create", "anon_try"],
            capture_output=True, text=True, timeout=30, env=env,
        )
        assert r.returncode != 0, (
            f"branchctl should refuse unauthenticated write when auth_enabled=1; "
            f"got stdout={r.stdout!r} stderr={r.stderr!r}"
        )
        assert "auth" in (r.stderr + r.stdout).lower()
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_branchctl_accepts_signed_token():
    work, site_fp = _fresh_site_with_auth("prinauth_tok_")
    try:
        token = _mint_token(site_fp, "admin")
        assert token, "token helper returned empty string"

        env = {**os.environ, "BRANCHFS_DB": str(site_fp),
               "FORKPRESS_TOKEN": token}
        r = subprocess.run(
            [PHP_BIN, "-d", f"extension={EXT_PATH}",
             str(BRANCHCTL_PHP), "create", "by_token"],
            capture_output=True, text=True, timeout=60, env=env,
        )
        assert r.returncode == 0, (
            f"signed token rejected: stdout={r.stdout!r} stderr={r.stderr!r}"
        )
        rows = sqlite_q(site_fp,
            "SELECT actor FROM audit_log WHERE target='by_token'")
        assert rows and rows[0][0] == "admin"

        # And a tampered token must fail.
        tampered = token[:-4] + ("aaaa" if not token.endswith("aaaa") else "bbbb")
        r = subprocess.run(
            [PHP_BIN, "-d", f"extension={EXT_PATH}",
             str(BRANCHCTL_PHP), "create", "by_bad_token"],
            capture_output=True, text=True, timeout=30,
            env={**os.environ, "BRANCHFS_DB": str(site_fp),
                 "FORKPRESS_TOKEN": tampered},
        )
        assert r.returncode != 0, (
            "tampered token accepted; stdout={}\n stderr={}".format(
                r.stdout, r.stderr)
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_branchctl_legacy_site_runs_as_system_principal():
    """auth_enabled=0 sites keep working without creds; audit shows 'system'."""
    work, site_fp = _fresh_site_no_auth("prinauth_legacy_")
    try:
        env = {k: v for k, v in os.environ.items()
               if k not in ("FORKPRESS_TOKEN", "FORKPRESS_ACTOR")}
        env["BRANCHFS_DB"] = str(site_fp)
        r = subprocess.run(
            [PHP_BIN, "-d", f"extension={EXT_PATH}",
             str(BRANCHCTL_PHP), "create", "legacy_feat"],
            capture_output=True, text=True, timeout=60, env=env,
        )
        assert r.returncode == 0, (
            f"legacy site rejected unauthenticated branchctl: "
            f"{r.stdout!r} / {r.stderr!r}"
        )
        rows = sqlite_q(site_fp,
            "SELECT actor FROM audit_log WHERE target='legacy_feat'")
        assert rows and rows[0][0] == "system", (
            f"expected 'system' synthetic principal; got {rows}"
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


# ══════════════════════════════════════════════════════════════════════════
# Finding #5 — assert_branched / BootstrapBranchedPDO::ensure wired
# ══════════════════════════════════════════════════════════════════════════

def test_assert_branched_fires_on_raw_pdo():
    """
    A PHP entry point that loads BootstrapBranchedPDO::ensure() AND opens
    the .fp with a raw PDO must be warned (error_log) or — in strict mode —
    throw. This verifies the bootstrap path is wired, not just the helper
    function defined.
    """
    work, site_fp = make_fresh_site("prinauth_raw_")
    try:
        php = f"""
require_once {repr(str(BRANCHED_PDO_PHP))};
// BootstrapBranchedPDO is a production chokepoint: scripts/ and the
// wp-config.php installer both call ::ensure() after opening their PDO.
$pdo = new PDO('sqlite:' . {repr(str(site_fp))});
$err = BootstrapBranchedPDO::ensure($pdo, {repr(str(site_fp))}, 'main');
echo $err === null ? 'OK' : 'WARN:' . $err;
"""
        r = subprocess.run(
            [PHP_BIN, "-d", f"extension={EXT_PATH}", "-r", php],
            capture_output=True, text=True, timeout=30,
        )
        assert r.returncode == 0, r.stderr
        # Helper name must be callable (not missing).
        assert "Class \"BootstrapBranchedPDO\" not found" not in r.stderr, (
            f"BootstrapBranchedPDO missing: {r.stderr}"
        )
        assert "WARN" in r.stdout, r.stdout + r.stderr
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_assert_branched_passes_via_BranchedPDO():
    work, site_fp = make_fresh_site("prinauth_br_")
    try:
        php = f"""
require_once {repr(str(BRANCHED_PDO_PHP))};
$pdo = BranchedPDO::connect({repr(str(site_fp))}, 'main');
$err = BootstrapBranchedPDO::ensure($pdo, {repr(str(site_fp))}, 'main');
echo $err === null ? 'OK' : 'WARN:' . $err;
"""
        r = subprocess.run(
            [PHP_BIN, "-d", f"extension={EXT_PATH}", "-r", php],
            capture_output=True, text=True, timeout=30,
        )
        assert r.returncode == 0, r.stderr
        assert r.stdout.strip() == "OK", r.stdout + r.stderr
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_branchctl_ddl_path_actually_invokes_bootstrap():
    """
    End-to-end behavioral test: the `_ddl` subcommand (production chokepoint
    where a raw PDO would silently miss COW DDL routing) MUST invoke
    BootstrapBranchedPDO::ensure() at runtime before executing user DDL.

    This test is explicitly designed to defeat grep-theater. Prior versions
    regex-matched the source of branchctl.php or substring-searched for
    "BootstrapBranchedPDO::ensure" — both satisfiable by a stray comment or
    dead code (hostile review round 2 / 3 findings).

    Design: ensure() contains an env-gated sentinel
    (`if (getenv('BRANCHFS_TRACE_ENSURE')) fwrite(STDERR, "BRANCHFS_ENSURE_CALLED\\n")`).
    We spawn `branchctl _ddl --branch main` in a real subprocess with the
    env var set, feed a valid CREATE INDEX on stdin, and assert both:
      (a) the subprocess exits 0 (DDL actually executed), and
      (b) the stderr contains BRANCHFS_ENSURE_CALLED (ensure() really ran).

    Deleting the ::ensure() call at branchctl.php while keeping the
    surrounding comment causes this test to fail — the sentinel never
    fires because ensure() is never invoked. A comment cannot satisfy
    this test; only a runtime call can.
    """
    work, site_fp = _fresh_site_with_auth("prinauth_ddl_trace_")
    try:
        token = _mint_token(site_fp, "admin")
        env = {**os.environ,
               "BRANCHFS_DB": str(site_fp),
               "FORKPRESS_TOKEN": token,
               "BRANCHFS_TRACE_ENSURE": "1"}
        # CREATE INDEX on main's b1_wp_options is a valid, allowlisted DDL
        # form that exercises the real BranchedPDO::connect +
        # BootstrapBranchedPDO::ensure path.
        sql = "CREATE INDEX test_idx_ensure_trace ON b1_wp_options(option_name)"
        p = subprocess.Popen(
            [PHP_BIN, "-d", f"extension={EXT_PATH}",
             str(BRANCHCTL_PHP), "_ddl", "--branch", "main"],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE,
            stderr=subprocess.PIPE, text=True, env=env,
        )
        out, err = p.communicate(input=sql, timeout=30)
        assert p.returncode == 0, (
            f"_ddl subprocess failed; stdout={out!r} stderr={err!r}"
        )
        assert "BRANCHFS_ENSURE_CALLED" in err, (
            "BootstrapBranchedPDO::ensure() was NOT invoked at runtime on the "
            "_ddl path — the bootstrap helper is defined but not wired into "
            "the production chokepoint (finding #5 regression). "
            f"stderr={err!r} stdout={out!r}"
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


# ══════════════════════════════════════════════════════════════════════════
# Finding #18 — _ddl requires auth and rejects non-DDL SQL
# ══════════════════════════════════════════════════════════════════════════

def test_ddl_subcommand_requires_auth():
    work, site_fp = _fresh_site_with_auth("prinauth_ddl_auth_")
    try:
        env = {k: v for k, v in os.environ.items() if k != "FORKPRESS_TOKEN"}
        env["BRANCHFS_DB"] = str(site_fp)
        p = subprocess.Popen(
            [PHP_BIN, "-d", f"extension={EXT_PATH}",
             str(BRANCHCTL_PHP), "_ddl", "--branch", "main"],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            text=True, env=env,
        )
        out, err = p.communicate(input="ALTER TABLE b1_wp_posts ADD COLUMN zzz TEXT",
                                 timeout=20)
        assert p.returncode != 0, (
            f"_ddl ran without auth on an auth-enabled site; "
            f"stdout={out!r} stderr={err!r}"
        )
        assert "auth" in (err + out).lower()
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_ddl_subcommand_rejects_non_ddl_sql():
    work, site_fp = _fresh_site_with_auth("prinauth_ddl_dml_")
    try:
        token = _mint_token(site_fp, "admin")
        env = {**os.environ, "BRANCHFS_DB": str(site_fp),
               "FORKPRESS_TOKEN": token}
        # DELETE should be flat-out rejected, not forwarded to BranchedPDO.
        p = subprocess.Popen(
            [PHP_BIN, "-d", f"extension={EXT_PATH}",
             str(BRANCHCTL_PHP), "_ddl", "--branch", "main"],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            text=True, env=env,
        )
        out, err = p.communicate(input="DELETE FROM audit_log", timeout=20)
        assert p.returncode != 0, (
            f"_ddl accepted DELETE; stdout={out!r} stderr={err!r}"
        )
        assert "ddl" in (err + out).lower() or "not allowed" in (err + out).lower()

        # UPDATE should likewise be rejected.
        p = subprocess.Popen(
            [PHP_BIN, "-d", f"extension={EXT_PATH}",
             str(BRANCHCTL_PHP), "_ddl", "--branch", "main"],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            text=True, env=env,
        )
        out, err = p.communicate(input="UPDATE users SET role='admin'", timeout=20)
        assert p.returncode != 0

        # DROP TABLE is destructive DDL but not in the allowlist — reject.
        p = subprocess.Popen(
            [PHP_BIN, "-d", f"extension={EXT_PATH}",
             str(BRANCHCTL_PHP), "_ddl", "--branch", "main"],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            text=True, env=env,
        )
        out, err = p.communicate(input="DROP TABLE b1_wp_posts", timeout=20)
        assert p.returncode != 0
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_ddl_subcommand_accepts_alter_table():
    work, site_fp = _fresh_site_with_auth("prinauth_ddl_ok_")
    try:
        # Create a non-main branch — this is the case the proxy intercepts.
        token = _mint_token(site_fp, "admin")
        env = {**os.environ, "BRANCHFS_DB": str(site_fp),
               "FORKPRESS_TOKEN": token}
        r = subprocess.run(
            [PHP_BIN, "-d", f"extension={EXT_PATH}",
             str(BRANCHCTL_PHP), "create", "feat_ddl"],
            capture_output=True, text=True, timeout=60, env=env,
        )
        assert r.returncode == 0, r.stderr
        bid = sqlite_q(site_fp, "SELECT id FROM branches WHERE name='feat_ddl'")[0][0]

        alter = f"ALTER TABLE b{bid}_wp_options ADD COLUMN ddl_test_col TEXT"
        p = subprocess.Popen(
            [PHP_BIN, "-d", f"extension={EXT_PATH}",
             str(BRANCHCTL_PHP), "_ddl", "--branch", "feat_ddl"],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            text=True, env=env,
        )
        out, err = p.communicate(input=alter, timeout=20)
        assert p.returncode == 0, (
            f"_ddl rejected a valid ALTER TABLE; stdout={out!r} stderr={err!r}"
        )

        # Column must exist on the overlay now.
        cols = [row[1] for row in sqlite_q(site_fp,
            f'PRAGMA table_info("b{bid}_wp_options__overlay")')]
        assert "ddl_test_col" in cols, (
            f"overlay missing new col; got {cols}"
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)

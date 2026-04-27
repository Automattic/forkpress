"""
TODO3 #12 — branchctl audit log.

Every state-mutating `branchctl` command writes a row into the new
`audit_log` table: create, commit, merge, rollback, reset, delete,
migrate. The actor comes from `FORKPRESS_ACTOR` (set by the caller's
shell), falling back to the system user, then 'anonymous'.

`branchctl audit [--since <ts>] [--actor <name>]` reads the log.
"""

import os
import shutil
import subprocess
from pathlib import Path

import pytest

from _rigorous_helpers import (
    branchctl,
    create_branch,
    make_fresh_site,
    sqlite_q,
    sqlite_exec,
    EXT_PATH,
    PHP_BIN,
    BRANCHCTL_PHP,
)


def test_audit_log_table_exists(tmp_path):
    work, site_fp = make_fresh_site("audit_tbl_")
    try:
        r = branchctl(site_fp, "list")
        assert r.returncode == 0
        cols = {r[1] for r in sqlite_q(site_fp, 'PRAGMA table_info("audit_log")')}
        for required in ["id", "ts", "actor", "action", "target", "details"]:
            assert required in cols, f"audit_log.{required} missing; got {sorted(cols)}"
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_create_writes_audit_row(tmp_path):
    work, site_fp = make_fresh_site("audit_cr_")
    try:
        r = subprocess.run(
            [PHP_BIN, "-d", f"extension={EXT_PATH}",
             str(BRANCHCTL_PHP), "create", "alice"],
            capture_output=True, text=True, timeout=60,
            env={**os.environ,
                 "BRANCHFS_DB": str(site_fp),
                 "FORKPRESS_ACTOR": "alice@example.com"},
        )
        assert r.returncode == 0, r.stderr

        rows = sqlite_q(site_fp,
            "SELECT actor, action, target FROM audit_log "
            "WHERE action='create'")
        assert rows and rows[0] == ("alice@example.com", "create", "alice"), (
            f"expected create audit row; got {rows}"
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_commit_rollback_delete_all_audited(tmp_path):
    work, site_fp = make_fresh_site("audit_all_")
    try:
        fid = create_branch(site_fp, "feat")
        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) "
            "VALUES ('k', 'v')")
        r = branchctl(site_fp, "commit", "feat", "-m", "c1")
        assert r.returncode == 0
        r = branchctl(site_fp, "rollback", "feat", "--force")
        assert r.returncode == 0

        actions = [
            r[0] for r in sqlite_q(site_fp,
                "SELECT action FROM audit_log ORDER BY id")
        ]
        assert "create" in actions
        assert "commit" in actions
        assert "rollback" in actions, f"rollback missing from audit: {actions}"

        r = branchctl(site_fp, "delete", "feat")
        assert r.returncode == 0
        actions = [
            r[0] for r in sqlite_q(site_fp,
                "SELECT action FROM audit_log ORDER BY id")
        ]
        assert "delete" in actions, f"delete missing from audit: {actions}"
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_audit_subcommand_reads_log(tmp_path):
    work, site_fp = make_fresh_site("audit_rd_")
    try:
        create_branch(site_fp, "b1")
        create_branch(site_fp, "b2")
        r = branchctl(site_fp, "audit")
        assert r.returncode == 0, r.stderr
        assert "create" in r.stdout, r.stdout
        assert "b1" in r.stdout and "b2" in r.stdout
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_audit_since_filter(tmp_path):
    work, site_fp = make_fresh_site("audit_since_")
    try:
        create_branch(site_fp, "b1")
        # Future timestamp — no rows should match.
        r = branchctl(site_fp, "audit", "--since", "2099-01-01")
        assert r.returncode == 0
        assert "no audit rows" in r.stdout.lower(), r.stdout
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_audit_actor_filter(tmp_path):
    work, site_fp = make_fresh_site("audit_actor_")
    try:
        # Two actions by two different actors.
        for actor in ["alice", "bob"]:
            r = subprocess.run(
                [PHP_BIN, "-d", f"extension={EXT_PATH}",
                 str(BRANCHCTL_PHP), "create", f"by_{actor}"],
                capture_output=True, text=True, timeout=60,
                env={**os.environ,
                     "BRANCHFS_DB": str(site_fp),
                     "FORKPRESS_ACTOR": actor},
            )
            assert r.returncode == 0, r.stderr

        r = branchctl(site_fp, "audit", "--actor", "alice")
        assert r.returncode == 0, r.stderr
        assert "by_alice" in r.stdout
        assert "by_bob" not in r.stdout
    finally:
        shutil.rmtree(work, ignore_errors=True)

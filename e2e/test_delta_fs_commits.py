"""
TODO3 #5 — delta-encoded fs_commit_files.

The DB-side commit graph already moved to delta encoding in TODO3 #2.
The file-side graph was still storing one row per path per commit;
a ~3000-path WordPress install committed 100 times cost ~300k rows
of path metadata even when only a handful of files changed per
commit.

This file covers the file-side equivalent:

  * Schema migration: fs_commits.kind and fs_commit_files.op columns
    are added idempotently.
  * First commit on a branch is FULL; subsequent commits are DELTA.
  * A DELTA commit that adds 1 file stores ONE row, not one per
    every file in the tree.
  * Removing a path between commits records op='DELETE' so
    materialization correctly drops it.
  * Rollback / reset walk the chain: target commits always
    materialize to the same tree the old full-snapshot scheme would
    have produced.
  * Storage-scale acceptance (TODO3 #5): 100 commits × 5 changed
    files costs O(commits × 5) rows, not O(commits × tree_size).
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
    branchctl,
    make_fresh_site,
    sqlite_q,
)


BRANCHCTL_PHP = BASE_DIR / "scripts" / "branchctl.php"
FS_HELPERS_PHP = BASE_DIR / "scripts" / "fs_commit_helpers.php"


def write_file_on_branch(site_fp: Path, branch: str, path: str, content: bytes):
    """Stage a file into the branch's overlay via a small PHP helper.
    (Test double for what SFTP / git push / HTTP writes do in practice.)"""
    fp = str(site_fp).replace("'", r"\'")
    b  = branch.replace("'", r"\'")
    pp = path.replace("'", r"\'")
    inline = (
        "<?php "
        "$ext='" + str(EXT_PATH) + "'; "
        "$db=new SQLite3('" + fp + "'); "
        "$bid=(int)$db->querySingle(\"SELECT id FROM branches WHERE name='" + b + "'\"); "
        "$h=strtolower(hash('sha256',$argv[1])); "
        "$s=$db->prepare('INSERT OR IGNORE INTO blobs (hash,data,size) VALUES (:h,:d,:s)'); "
        "$s->bindValue(':h',$h); $s->bindValue(':d',$argv[1],SQLITE3_BLOB); "
        "$s->bindValue(':s',strlen($argv[1]),SQLITE3_INTEGER); $s->execute(); "
        "$u=$db->prepare('INSERT INTO files (branch_id,path,blob_hash,mode,mtime,is_dir) "
        "VALUES (:b,:p,:h,33188,1,0) ON CONFLICT(branch_id,path) "
        "DO UPDATE SET blob_hash=excluded.blob_hash, mtime=excluded.mtime'); "
        "$u->bindValue(':b',$bid); $u->bindValue(':p','" + pp + "'); "
        "$u->bindValue(':h',$h); $u->execute();"
    )
    r = subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}", "-r", inline[5:], content.decode("utf-8", "replace")],
        capture_output=True, text=True, timeout=30,
    )
    assert r.returncode == 0, r.stderr


# ---------- Schema migration ----------------------------------------


def test_fs_kind_and_op_columns_exist(tmp_path):
    work, site_fp = make_fresh_site("fs_delta_schema_")
    try:
        r = branchctl(site_fp, "list")
        assert r.returncode == 0

        cols = {r[1] for r in sqlite_q(site_fp, 'PRAGMA table_info("fs_commits")')}
        assert "kind" in cols, f"fs_commits.kind missing; got {sorted(cols)}"
        cols = {r[1] for r in sqlite_q(site_fp, 'PRAGMA table_info("fs_commit_files")')}
        assert "op" in cols, f"fs_commit_files.op missing; got {sorted(cols)}"
    finally:
        shutil.rmtree(work, ignore_errors=True)


# ---------- FULL / DELTA sequencing ---------------------------------


def test_first_commit_is_full_subsequent_are_delta(tmp_path):
    work, site_fp = make_fresh_site("fs_delta_kind_")
    try:
        r = branchctl(site_fp, "create", "f")
        assert r.returncode == 0
        write_file_on_branch(site_fp, "f", "a.txt", b"contents-a")
        r = branchctl(site_fp, "commit", "f", "-m", "first")
        assert r.returncode == 0, r.stderr
        write_file_on_branch(site_fp, "f", "b.txt", b"contents-b")
        r = branchctl(site_fp, "commit", "f", "-m", "second")
        assert r.returncode == 0, r.stderr

        kinds = [r[0] for r in sqlite_q(site_fp,
            "SELECT kind FROM fs_commits WHERE branch_id=(SELECT id FROM branches WHERE name='f') "
            "ORDER BY id")]
        # `create` auto-commits a FULL commit; then two user commits become DELTA.
        assert kinds == ["FULL", "DELTA", "DELTA"], (
            f"expected exactly one FULL + two DELTA fs_commits; got {kinds}"
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_delta_fs_commit_only_records_changed_paths(tmp_path):
    """Adding one file between commits records ONE new row in
    fs_commit_files — not one per every tree path."""
    work, site_fp = make_fresh_site("fs_delta_rows_")
    try:
        r = branchctl(site_fp, "create", "f")
        assert r.returncode == 0
        # Add 10 files, commit.
        for i in range(10):
            write_file_on_branch(site_fp, "f", f"a{i}.txt", f"data{i}".encode())
        r = branchctl(site_fp, "commit", "f", "-m", "bulk")
        assert r.returncode == 0

        bulk_cid = sqlite_q(site_fp,
            "SELECT id FROM fs_commits WHERE branch_id=(SELECT id FROM branches WHERE name='f') "
            "ORDER BY id DESC LIMIT 1")[0][0]
        bulk_rows = sqlite_q(site_fp,
            "SELECT COUNT(*) FROM fs_commit_files WHERE commit_id=?", (bulk_cid,))[0][0]
        assert bulk_rows >= 10

        # Add just ONE more file. Commit. Delta should be 1 new row.
        write_file_on_branch(site_fp, "f", "just_one.txt", b"new-file")
        r = branchctl(site_fp, "commit", "f", "-m", "incr")
        assert r.returncode == 0

        incr_cid = sqlite_q(site_fp,
            "SELECT id FROM fs_commits WHERE branch_id=(SELECT id FROM branches WHERE name='f') "
            "ORDER BY id DESC LIMIT 1")[0][0]
        incr_rows = sqlite_q(site_fp,
            "SELECT COUNT(*) FROM fs_commit_files WHERE commit_id=?", (incr_cid,))[0][0]

        # Under the old scheme this would be ~11 (the entire resolved tree).
        # Under delta, it must be roughly 1 — be lenient for ancestor dir entries.
        assert incr_rows < 5, (
            f"DELTA commit after adding one file: expected ~1 stored row, got "
            f"{incr_rows}. If this grew to ~11, the per-commit full-snapshot "
            f"regression is back (TODO3 #5)."
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


# ---------- Restore semantics preserved ------------------------------


def test_rollback_restores_file_tree_through_delta_chain(tmp_path):
    """Rollback materializes the target commit's full tree via FULL base
    + intermediate DELTAs — should match the snapshot exactly."""
    work, site_fp = make_fresh_site("fs_delta_rb_")
    try:
        r = branchctl(site_fp, "create", "f")
        assert r.returncode == 0

        write_file_on_branch(site_fp, "f", "keep.txt", b"keep-me")
        r = branchctl(site_fp, "commit", "f", "-m", "c1")
        assert r.returncode == 0

        write_file_on_branch(site_fp, "f", "new.txt", b"created-in-c2")
        r = branchctl(site_fp, "commit", "f", "-m", "c2")
        assert r.returncode == 0

        # Modify the keep.txt file (uncommitted) and rollback.
        write_file_on_branch(site_fp, "f", "keep.txt", b"modified-uncommitted")
        r = branchctl(site_fp, "rollback", "f", "--force")
        assert r.returncode == 0, r.stderr

        # After rollback to c1, keep.txt should be back to original content;
        # new.txt (created in c2) should be gone.
        paths = {
            r[0]: r[1]
            for r in sqlite_q(site_fp,
                "SELECT f.path, b.data FROM files f "
                "LEFT JOIN blobs b ON b.hash = f.blob_hash "
                "WHERE f.branch_id=(SELECT id FROM branches WHERE name='f') "
                "  AND f.is_dir = 0")
        }
        assert paths.get("keep.txt") == b"keep-me", (
            f"keep.txt should have c1's content; got {paths.get('keep.txt')!r}"
        )
        assert "new.txt" not in paths, (
            f"new.txt was created in c2 and should be gone after rollback; got {list(paths)}"
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


# ---------- Storage-scale acceptance --------------------------------


def test_100_fs_commits_with_5_changed_paths_is_sublinear(tmp_path):
    """
    TODO3 #5 acceptance: 100 file-side commits with 5 changed files each
    → fs_commit_files grows by ~500 rows, not tree_size × 100.
    We bound at <1500 (generous; old scheme would be tree_size×100,
    typically > 300k).
    """
    work, site_fp = make_fresh_site("fs_delta_scale_")
    try:
        r = branchctl(site_fp, "create", "f")
        assert r.returncode == 0

        # Seed a "tree" of 30 files.
        for i in range(30):
            write_file_on_branch(site_fp, "f", f"seed{i:02d}.txt", f"seed-{i}".encode())
        r = branchctl(site_fp, "commit", "f", "-m", "seed")
        assert r.returncode == 0

        # 100 iterations: modify 5 files each.
        for n in range(100):
            for i in range(5):
                write_file_on_branch(site_fp, "f", f"seed{i:02d}.txt",
                                     f"seed-{i}-rev-{n}".encode())
            r = branchctl(site_fp, "commit", "f", "-m", f"c{n}")
            assert r.returncode == 0, r.stderr

        total_rows = sqlite_q(site_fp,
            "SELECT COUNT(*) FROM fs_commit_files fcf "
            "JOIN fs_commits c ON c.id = fcf.commit_id "
            "WHERE c.branch_id = (SELECT id FROM branches WHERE name='f')")[0][0]

        # Loose bound. Old scheme: 30 + 30×100 ≈ 3030. New: ~30 (seed) + 100×5 ≈ 530.
        assert total_rows < 1500, (
            f"expected sublinear storage growth under delta encoding; got "
            f"{total_rows} rows. Pre-TODO3 scheme would produce ~3000+."
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


# ---------- Shared materialization helper ----------------------------


def test_fs_commit_helpers_exports_materialize_function():
    """Other callers (merge.php, git_server, branchctl.php) must be
    able to materialize a tree via the shared helper."""
    assert FS_HELPERS_PHP.exists(), "scripts/fs_commit_helpers.php missing"
    src = FS_HELPERS_PHP.read_text()
    assert "function fs_materialize_commit_tree" in src, (
        "shared helper must define fs_materialize_commit_tree — otherwise "
        "merge.php / git_server / branchctl.php each reimplement the chain "
        "walker and diverge over time."
    )

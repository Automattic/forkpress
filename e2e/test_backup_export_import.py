"""
Backup / export / import tests (TODO #7).

Three separate capabilities:
  - backup: hot-copy of a running .fp via SQLite VACUUM INTO.
  - export: serialize a .fp to a portable directory tree (files + SQL +
    manifest.json) — survives format changes, git-diffable, archivable.
  - import: reconstruct a .fp from an export directory.

These are implemented as PHP scripts under scripts/ (invoked directly)
and wired into the Rust `forkpress` CLI as subcommands (rebuild of the
forkpress binary required to use the subcommands — not covered by
non-live tests).

Run:
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_backup_export_import.py -v
"""

import json
import os
import shutil
import sqlite3
import subprocess
import tempfile
from pathlib import Path

import pytest

E2E_DIR   = Path(__file__).parent
BASE_DIR  = E2E_DIR.parent
EXT_PATH  = BASE_DIR / "ext" / "branchfs.so"
PHP_BIN   = "php"

BACKUP_PHP = BASE_DIR / "scripts" / "backup.php"
EXPORT_PHP = BASE_DIR / "scripts" / "export.php"
IMPORT_PHP = BASE_DIR / "scripts" / "import.php"


def php_run(script: Path, *args, timeout: int = 60) -> subprocess.CompletedProcess:
    return subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}", str(script), *[str(a) for a in args]],
        capture_output=True, text=True, timeout=timeout,
    )


def branchctl(site_fp: Path, *args, timeout: int = 30) -> subprocess.CompletedProcess:
    return subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         str(BASE_DIR / "scripts" / "branchctl.php"), *args],
        capture_output=True, text=True, timeout=timeout,
        env={**os.environ, "BRANCHFS_DB": str(site_fp)},
    )


def init_site(site_fp: Path):
    r = php_run(BASE_DIR / "scripts" / "init_db.php", site_fp)
    if r.returncode != 0:
        raise RuntimeError(f"init_db.php failed: {r.stderr[:500]}")


def branchfs_write(site_fp: Path, branch: str, path: str, content: str) -> bool:
    r = subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}", "-r",
         f"""
         branchfs_set_db('{site_fp}');
         echo file_put_contents('branchfs://{branch}/{path}', {content!r}) !== false
             ? 'ok' : 'fail';
         """],
        capture_output=True, text=True, timeout=10,
    )
    return r.stdout.strip() == "ok"


def branchfs_read(site_fp: Path, branch: str, path: str) -> str:
    r = subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}", "-r",
         f"""
         branchfs_set_db('{site_fp}');
         echo @file_get_contents('branchfs://{branch}/{path}') ?: '';
         """],
        capture_output=True, text=True, timeout=10,
    )
    return r.stdout


def sqlite_q(site_fp: Path, sql: str, params=()) -> list:
    db = sqlite3.connect(str(site_fp))
    try:
        return db.execute(sql, params).fetchall()
    finally:
        db.close()


# ── Realistic fixture: 2 branches, each with unique files + WP data ──────────

@pytest.fixture
def populated_site():
    if not EXT_PATH.exists():
        pytest.skip("branchfs.so not found")
    work = Path(tempfile.mkdtemp())
    site_fp = work / "site.fp"
    init_site(site_fp)

    # Main gets a file + a b1_wp_options row
    branchfs_write(site_fp, "main", "index.php", "<?php echo 'main';")
    db = sqlite3.connect(str(site_fp))
    db.executescript("""
        CREATE TABLE IF NOT EXISTS b1_wp_options (
            option_id   INTEGER PRIMARY KEY AUTOINCREMENT,
            option_name TEXT NOT NULL DEFAULT '' UNIQUE,
            option_value TEXT NOT NULL DEFAULT ''
        );
        INSERT INTO b1_wp_options (option_name, option_value) VALUES
            ('siteurl',  'http://main.example'),
            ('blogname', 'Main Site');
    """)
    db.commit()
    db.close()

    # Feature branch with its own file + modified options
    r = branchctl(site_fp, "create", "feature")
    assert r.returncode == 0
    fid = sqlite_q(site_fp, "SELECT id FROM branches WHERE name='feature'")[0][0]
    branchfs_write(site_fp, "feature", "wp-content/custom.php", "<?php /* feature */ ?>")
    db = sqlite3.connect(str(site_fp))
    db.execute(
        f"UPDATE b{fid}_wp_options SET option_value='Feature Site' WHERE option_name='blogname'"
    )
    db.commit()
    db.close()

    yield {"site_fp": site_fp, "work": work, "feature_id": fid}
    shutil.rmtree(work, ignore_errors=True)


# ── Backup ────────────────────────────────────────────────────────────────────

class TestBackup:

    def test_backup_script_exists(self):
        assert BACKUP_PHP.exists()

    def test_backup_creates_independent_copy(self, populated_site):
        """
        backup must produce a .fp that contains all branches + data and
        is fully decoupled from the source (no -wal / -shm links, no
        shared pages — modifying the source must not change the backup).
        """
        src = populated_site["site_fp"]
        dst = populated_site["work"] / "backup.fp"

        r = php_run(BACKUP_PHP, src, dst)
        assert r.returncode == 0, f"backup failed: {r.stdout}\n{r.stderr}"
        assert dst.is_file(), "destination .fp was not created"
        assert dst.stat().st_size > 0

        # Every branch present
        src_branches = {row[0] for row in sqlite_q(src, "SELECT name FROM branches")}
        dst_branches = {row[0] for row in sqlite_q(dst, "SELECT name FROM branches")}
        assert src_branches == dst_branches

        # Data is present
        fid = populated_site["feature_id"]
        rows = sqlite_q(dst,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name='blogname'")
        assert rows and rows[0][0] == "Feature Site"

        # Independence: mutate source, verify backup is unchanged
        db = sqlite3.connect(str(src))
        db.execute("UPDATE b1_wp_options SET option_value='Mutated' WHERE option_name='blogname'")
        db.commit()
        db.close()

        rows = sqlite_q(dst,
            "SELECT option_value FROM b1_wp_options WHERE option_name='blogname'")
        assert rows and rows[0][0] == "Main Site", \
            "backup must not share state with source after VACUUM INTO"

    def test_backup_refuses_overwrite(self, populated_site):
        src = populated_site["site_fp"]
        dst = populated_site["work"] / "already.fp"
        dst.write_bytes(b"x")
        r = php_run(BACKUP_PHP, src, dst)
        assert r.returncode != 0, "backup should refuse to overwrite existing file"

    def test_backup_missing_source_errors(self, populated_site):
        r = php_run(BACKUP_PHP, "/tmp/nonexistent.fp",
                    populated_site["work"] / "x.fp")
        assert r.returncode != 0


# ── Export + Import round-trip ────────────────────────────────────────────────

class TestExport:

    def test_export_writes_manifest_and_branch_dirs(self, populated_site):
        src = populated_site["site_fp"]
        out_dir = populated_site["work"] / "exported"

        r = php_run(EXPORT_PHP, src, out_dir)
        assert r.returncode == 0, f"export failed: {r.stdout}\n{r.stderr}"

        assert (out_dir / "manifest.json").is_file()
        manifest = json.loads((out_dir / "manifest.json").read_text())

        assert manifest["format_version"] >= 1
        branch_names = [b["name"] for b in manifest["branches"]]
        assert "main" in branch_names
        assert "feature" in branch_names

        # Main's parent is null; feature's parent is main (topological order)
        by_name = {b["name"]: b for b in manifest["branches"]}
        assert by_name["main"]["parent_branch"] is None
        assert by_name["feature"]["parent_branch"] == "main"

        # Branch directories exist
        assert (out_dir / "branches" / "main" / "files").is_dir()
        assert (out_dir / "branches" / "feature" / "files").is_dir()
        assert (out_dir / "branches" / "main" / "db.sql").is_file()
        assert (out_dir / "branches" / "feature" / "db.sql").is_file()

    def test_export_preserves_file_content(self, populated_site):
        src = populated_site["site_fp"]
        out_dir = populated_site["work"] / "exported"
        r = php_run(EXPORT_PHP, src, out_dir)
        assert r.returncode == 0

        main_index = out_dir / "branches" / "main" / "files" / "index.php"
        assert main_index.is_file()
        assert main_index.read_text() == "<?php echo 'main';"

        feature_custom = (out_dir / "branches" / "feature" / "files"
                          / "wp-content" / "custom.php")
        assert feature_custom.is_file()
        assert feature_custom.read_text() == "<?php /* feature */ ?>"

    def test_export_refuses_nonempty_output(self, populated_site):
        src = populated_site["site_fp"]
        out_dir = populated_site["work"] / "nonempty"
        out_dir.mkdir()
        (out_dir / "x.txt").write_text("placeholder")
        r = php_run(EXPORT_PHP, src, out_dir)
        assert r.returncode != 0


class TestImport:

    def test_export_then_import_preserves_branches(self, populated_site):
        src = populated_site["site_fp"]
        out_dir = populated_site["work"] / "exported"
        rebuilt = populated_site["work"] / "rebuilt.fp"

        r = php_run(EXPORT_PHP, src, out_dir)
        assert r.returncode == 0, f"export: {r.stdout}\n{r.stderr}"

        r = php_run(IMPORT_PHP, out_dir, rebuilt)
        assert r.returncode == 0, f"import: {r.stdout}\n{r.stderr}"
        assert rebuilt.is_file()

        # Same branches, same topology
        src_rows = sqlite_q(src,
            "SELECT name, parent_branch FROM branches ORDER BY name")
        dst_rows = sqlite_q(rebuilt,
            "SELECT name, parent_branch FROM branches ORDER BY name")
        assert src_rows == dst_rows, (
            f"branch topology diverged:\n"
            f"  source:   {src_rows}\n"
            f"  rebuilt:  {dst_rows}"
        )

    def test_export_then_import_preserves_files(self, populated_site):
        src = populated_site["site_fp"]
        out_dir = populated_site["work"] / "exported"
        rebuilt = populated_site["work"] / "rebuilt.fp"

        assert php_run(EXPORT_PHP, src, out_dir).returncode == 0
        assert php_run(IMPORT_PHP, out_dir, rebuilt).returncode == 0

        # Main's file visible on rebuilt main
        assert branchfs_read(rebuilt, "main", "index.php") == "<?php echo 'main';"
        # Feature's file visible on rebuilt feature
        assert branchfs_read(rebuilt, "feature", "wp-content/custom.php") \
            == "<?php /* feature */ ?>"

    def test_export_then_import_preserves_db_tables(self, populated_site):
        src = populated_site["site_fp"]
        out_dir = populated_site["work"] / "exported"
        rebuilt = populated_site["work"] / "rebuilt.fp"

        assert php_run(EXPORT_PHP, src, out_dir).returncode == 0
        assert php_run(IMPORT_PHP, out_dir, rebuilt).returncode == 0

        # Option values preserved per branch
        rows = sqlite_q(rebuilt,
            "SELECT option_value FROM b1_wp_options WHERE option_name='blogname'")
        assert rows and rows[0][0] == "Main Site"

        # Feature's id in the rebuilt .fp may differ (AUTOINCREMENT picks
        # next free id). Look it up dynamically.
        fid = sqlite_q(rebuilt,
            "SELECT id FROM branches WHERE name='feature'")[0][0]
        rows = sqlite_q(rebuilt,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name='blogname'")
        assert rows and rows[0][0] == "Feature Site", \
            f"feature's blogname not preserved on import, got: {rows}"

    def test_import_refuses_missing_manifest(self, populated_site):
        empty = populated_site["work"] / "empty_export"
        empty.mkdir()
        rebuilt = populated_site["work"] / "should-not-exist.fp"
        r = php_run(IMPORT_PHP, empty, rebuilt)
        assert r.returncode != 0, "import must fail without manifest.json"
        assert not rebuilt.exists()


# ── Rust CLI wiring (source inspection; full e2e needs binary rebuild) ───────

class TestForkpressCliWiring:

    def test_rust_cli_defines_backup_subcommand(self):
        src = (BASE_DIR / "forkpress" / "src" / "main.rs").read_text()
        assert "Backup(BackupArgs)" in src
        assert "scripts/backup.php" in src

    def test_rust_cli_defines_export_subcommand(self):
        src = (BASE_DIR / "forkpress" / "src" / "main.rs").read_text()
        assert "Export(ExportArgs)" in src
        assert "scripts/export.php" in src

    def test_rust_cli_defines_import_subcommand(self):
        src = (BASE_DIR / "forkpress" / "src" / "main.rs").read_text()
        assert "Import(ImportArgs)" in src
        assert "scripts/import.php" in src

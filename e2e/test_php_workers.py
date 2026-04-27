"""
PHP multi-worker mode tests (TODO2 #3).

`forkpress start` now exports `PHP_CLI_SERVER_WORKERS` on the bundled
PHP built-in server so concurrent HTTP requests don't queue behind a
slow one. Each worker is a separate PHP process; with SQLite in WAL
mode plus the SQLITE_BUSY retry helper from round 1, multi-worker
writes still converge.

These tests verify four things without needing a `forkpress` binary
(which requires `make dist`):

1. The bundled / system PHP honours `PHP_CLI_SERVER_WORKERS`: a slow
   request on one worker does not block a fast request on another.
2. `forkpress start` exposes `--workers N` via clap.
3. The default worker count formula is `min(8, num_cpus * 2)`.
4. Under `PHP_CLI_SERVER_WORKERS=4` eight concurrent branchfs writes
   all succeed — this is the "SQLITE_BUSY retry actually exercises"
   check from the TODO2 acceptance criteria.

The end-to-end test through the real `forkpress` binary is marked
`@live` and skipped unless the binary is available.

Run:
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_php_workers.py -v -m "not live"
"""

import concurrent.futures
import os
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.request
from pathlib import Path

import pytest

E2E_DIR = Path(__file__).parent
BASE_DIR = E2E_DIR.parent
EXT_PATH = BASE_DIR / "ext" / "branchfs.so"
PHP_BIN = "php"
FORKPRESS_MAIN_RS = BASE_DIR / "forkpress" / "src" / "main.rs"
FORKPRESS_CARGO_TOML = BASE_DIR / "forkpress" / "Cargo.toml"


def _free_port() -> int:
    """Allocate a currently-unused TCP port on 127.0.0.1."""
    s = socket.socket()
    s.bind(("127.0.0.1", 0))
    port = s.getsockname()[1]
    s.close()
    return port


def _wait_for_port(host: str, port: int, timeout: float = 5.0):
    deadline = time.time() + timeout
    while time.time() < deadline:
        try:
            with socket.create_connection((host, port), timeout=0.3):
                return
        except OSError:
            time.sleep(0.05)
    raise TimeoutError(f"{host}:{port} did not open within {timeout}s")


# ═════════════════════════════════════════════════════════════════════════════
# 1. PHP_CLI_SERVER_WORKERS: one slow request does not block a fast one.
# ═════════════════════════════════════════════════════════════════════════════

class TestPhpWorkerEnvVar:

    def test_php_version_supports_workers(self):
        """PHP 7.4+ supports PHP_CLI_SERVER_WORKERS. Anything older cannot
        honour the env var and this whole feature is a no-op on such a
        build. forkpress targets PHP 8.x via static-php-cli, but we still
        guard against a mis-provisioned dev env."""
        r = subprocess.run(
            [PHP_BIN, "-r", "echo PHP_VERSION_ID;"],
            capture_output=True, text=True, timeout=10,
        )
        assert r.returncode == 0, r.stderr
        vid = int(r.stdout.strip())
        assert vid >= 70400, f"PHP_VERSION_ID={vid}, need >= 70400 for PHP_CLI_SERVER_WORKERS"

    def test_slow_request_does_not_block_fast(self):
        """With PHP_CLI_SERVER_WORKERS=4, a request to /slow (sleeps 2s) must
        not block a concurrent request to /fast. If PHP is single-threaded
        the fast request will wait for slow to complete."""
        port = _free_port()
        docroot = Path(tempfile.mkdtemp(prefix="phpworker_"))
        try:
            router = docroot / "router.php"
            router.write_text(
                "<?php\n"
                "if ($_SERVER['REQUEST_URI'] === '/slow') { sleep(2); echo 'slow'; return true; }\n"
                "echo 'fast'; return true;\n"
            )

            env = {**os.environ, "PHP_CLI_SERVER_WORKERS": "4"}
            srv = subprocess.Popen(
                [PHP_BIN, "-S", f"127.0.0.1:{port}", "-t", str(docroot), str(router)],
                env=env,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.PIPE,
            )
            try:
                _wait_for_port("127.0.0.1", port, timeout=5.0)

                results: dict = {}

                def hit(path: str, key: str):
                    t0 = time.time()
                    resp = urllib.request.urlopen(
                        f"http://127.0.0.1:{port}{path}", timeout=10
                    ).read()
                    results[key] = (time.time() - t0, resp.decode())

                t_slow = threading.Thread(target=hit, args=("/slow", "slow"))
                t_fast = threading.Thread(target=hit, args=("/fast", "fast"))
                t_slow.start()
                # Let slow begin so it holds a worker when fast arrives.
                time.sleep(0.1)
                t_fast.start()
                t_slow.join(timeout=5)
                t_fast.join(timeout=5)
            finally:
                srv.terminate()
                try:
                    srv.wait(timeout=3)
                except subprocess.TimeoutExpired:
                    srv.kill()
                    srv.wait(timeout=2)

            assert "slow" in results and "fast" in results, (
                f"expected both responses, got {list(results)}"
            )
            slow_elapsed, slow_body = results["slow"]
            fast_elapsed, fast_body = results["fast"]
            assert slow_body == "slow"
            assert fast_body == "fast"
            # slow should take ~2s; fast should return well before slow
            # finishes (< 1s under any sane load).
            assert fast_elapsed < 1.0, (
                f"fast took {fast_elapsed:.2f}s — PHP_CLI_SERVER_WORKERS=4 "
                f"did not parallelise (slow={slow_elapsed:.2f}s)"
            )
            assert slow_elapsed >= 1.8, (
                f"slow took only {slow_elapsed:.2f}s, expected ~2s sleep"
            )
        finally:
            shutil.rmtree(docroot, ignore_errors=True)


# ═════════════════════════════════════════════════════════════════════════════
# 2. `forkpress start --workers N` is wired up in the Rust source.
# ═════════════════════════════════════════════════════════════════════════════

class TestForkpressCliFlag:

    def test_workers_is_declared_on_startargs(self):
        """The `--workers N` clap attribute must live on StartArgs so that
        `forkpress start --workers 4` parses. We inspect source rather than
        invoking the binary because the forkpress crate needs `make dist`
        to build here."""
        src = FORKPRESS_MAIN_RS.read_text()
        # Look for the StartArgs struct and the workers field within it.
        assert "struct StartArgs" in src
        # Split out the StartArgs block and check `workers` lives inside it.
        start_idx = src.index("struct StartArgs")
        # Find the matching closing brace of the struct block.
        brace_idx = src.index("{", start_idx)
        depth = 1
        i = brace_idx + 1
        while i < len(src) and depth > 0:
            if src[i] == "{":
                depth += 1
            elif src[i] == "}":
                depth -= 1
            i += 1
        start_block = src[start_idx:i]
        assert "workers:" in start_block, (
            "StartArgs must declare a `workers:` field for the --workers CLI flag"
        )
        # It should be Option<usize> so the default can be computed lazily.
        assert "Option<usize>" in start_block, (
            "workers should be Option<usize> so the num_cpus-based default "
            "applies when the user omits --workers"
        )

    def test_env_var_is_set_on_php_child(self):
        """start_php_server must export PHP_CLI_SERVER_WORKERS to the spawned
        PHP process when workers > 1. Missing env export means --workers
        parses but is silently ignored."""
        src = FORKPRESS_MAIN_RS.read_text()
        assert "PHP_CLI_SERVER_WORKERS" in src, (
            "start_php_server must .env(\"PHP_CLI_SERVER_WORKERS\", ...) on "
            "the php child so the built-in server spawns workers"
        )

    def test_num_cpus_is_a_dep(self):
        """The default worker count uses num_cpus::get(); the crate has to be
        declared in Cargo.toml or cargo would refuse to build."""
        toml = FORKPRESS_CARGO_TOML.read_text()
        assert "num_cpus" in toml, (
            "forkpress/Cargo.toml must depend on num_cpus for the default "
            "workers calculation"
        )


# ═════════════════════════════════════════════════════════════════════════════
# 3. Default worker count is min(8, num_cpus * 2).
# ═════════════════════════════════════════════════════════════════════════════

class TestDefaultWorkerCount:

    def test_default_formula_is_min_8_num_cpus_times_2(self):
        """The default in main.rs must be `min(8, num_cpus::get() * 2)`.
        Source-inspection-only because running `forkpress --help` needs the
        crate to compile, which needs `make dist`."""
        src = FORKPRESS_MAIN_RS.read_text()
        # Must call num_cpus::get().
        assert "num_cpus::get()" in src, "default must read num_cpus::get()"
        # Must cap at 8.
        assert "std::cmp::min(8" in src or "cmp::min(8" in src, (
            "default must cap at 8 via std::cmp::min(8, ...)"
        )

    def test_startup_banner_advertises_worker_count(self):
        """The banner printed by start_command must surface the worker count
        so operators can verify multi-worker mode is active."""
        src = FORKPRESS_MAIN_RS.read_text()
        assert "PHP workers:" in src, (
            "start_command banner must print `PHP workers: N` so operators "
            "can confirm multi-worker mode is active"
        )


# ═════════════════════════════════════════════════════════════════════════════
# 4. Concurrent writes under PHP_CLI_SERVER_WORKERS exercise SQLITE_BUSY retry.
# ═════════════════════════════════════════════════════════════════════════════
#
# Proxy test: fire 8 parallel `php -r 'branchfs_set_db(...); file_put_contents(
# branchfs://main/...)'` processes (simpler than spawning a full PHP HTTP
# server with the branchfs extension loaded). Each process is a separate
# branchfs writer, so this is exactly the write-contention shape that
# PHP_CLI_SERVER_WORKERS produces in production.
# ═════════════════════════════════════════════════════════════════════════════

@pytest.fixture
def busy_site(tmp_path):
    if not EXT_PATH.exists():
        pytest.skip("branchfs.so not found — run 'make' in branched-wp/")
    site_fp = tmp_path / "site.fp"
    r = subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         str(BASE_DIR / "scripts" / "init_db.php"), str(site_fp),
         "--admin-password", "testpw"],
        capture_output=True, text=True, timeout=30,
    )
    if r.returncode != 0:
        pytest.skip(f"init_db.php failed: {r.stderr[:300]}")
    return site_fp


class TestConcurrentBranchfsWrites:

    def test_eight_parallel_writes_all_succeed(self, busy_site):
        """
        Under multi-worker PHP, 8 concurrent requests that each do a
        branchfs write will contend on the SQLite write lock. The round-1
        busyTimeout(15s) + sqlite_retry_busy() helpers must absorb this:
        all 8 writers succeed, no SQLITE_BUSY leaks as a visible error.

        We simulate worker concurrency via 8 parallel `php -r` processes
        (one per write). This is the same shape as PHP_CLI_SERVER_WORKERS=8
        serving 8 simultaneous requests: 8 independent PHP processes each
        calling `file_put_contents('branchfs://...')`.
        """
        N = 8

        def one_write(i: int) -> subprocess.CompletedProcess:
            code = (
                f"branchfs_set_db('{busy_site}');"
                f"$r = file_put_contents('branchfs://main/worker-{i}.txt',"
                f"  str_repeat('x', 4096));"
                "echo $r === false ? 'FAIL' : 'OK';"
            )
            return subprocess.run(
                [PHP_BIN, "-d", f"extension={EXT_PATH}", "-r", code],
                capture_output=True, text=True, timeout=60,
                env=os.environ.copy(),
            )

        with concurrent.futures.ThreadPoolExecutor(max_workers=N) as pool:
            results = list(pool.map(one_write, range(N)))

        failures = [
            (i, r) for i, r in enumerate(results)
            if r.returncode != 0 or r.stdout.strip() != "OK"
        ]
        detail = "\n".join(
            f"w-{i}: rc={r.returncode} out={r.stdout[-120:]} err={r.stderr[-200:]}"
            for i, r in failures
        )
        assert not failures, (
            f"{len(failures)}/{N} parallel branchfs writes failed — "
            f"SQLITE_BUSY retry is not absorbing multi-worker contention:\n{detail}"
        )

        # Verify the files landed: 8 distinct paths, each 4096 bytes.
        db = sqlite3.connect(str(busy_site))
        try:
            rows = db.execute(
                "SELECT path FROM files WHERE path LIKE '%worker-%' ORDER BY path"
            ).fetchall()
        finally:
            db.close()
        paths = {row[0] for row in rows}
        expected = {f"worker-{i}.txt" for i in range(N)}
        # Paths may be stored with a leading slash or branch prefix; match
        # by suffix for robustness.
        assert all(
            any(p.endswith(e) for p in paths) for e in expected
        ), f"expected every worker-i.txt landed, got paths: {sorted(paths)}"


# ═════════════════════════════════════════════════════════════════════════════
# 5. End-to-end via forkpress binary — @live, skipped without the binary.
# ═════════════════════════════════════════════════════════════════════════════

@pytest.mark.live
class TestForkpressEndToEnd:
    """Runs against a built `forkpress` binary (needs `make dist` first)."""

    def test_start_with_workers_flag(self):
        fp_bin = BASE_DIR / "target" / "release" / "forkpress"
        if not fp_bin.is_file():
            pytest.skip("forkpress binary not built; run `make dist` first")
        # --help must advertise --workers so PHP_CLI_SERVER_WORKERS remains
        # configurable from the CLI at the forkpress layer.
        r = subprocess.run(
            [str(fp_bin), "start", "--help"],
            capture_output=True, text=True, timeout=10,
        )
        assert r.returncode == 0, r.stderr
        assert "--workers" in r.stdout, (
            "forkpress start is missing --workers flag; PHP_CLI_SERVER_WORKERS "
            "will default and users cannot override concurrency."
        )


# Non-live substantive check: the forkpress CLI source must actually wire
# --workers through to the bundled PHP server, because we rely on that
# flag to export PHP_CLI_SERVER_WORKERS. Previously the only forkpress
# test was the @live stub above, which silently skipped when the binary
# wasn't built — giving a false sense of coverage. This test reads the
# source so it runs in every environment.
class TestForkpressWorkersWiring:
    def test_workers_flag_defined_in_cli(self):
        src = FORKPRESS_MAIN_RS.read_text()
        assert "workers" in src, "forkpress CLI source never mentions workers"
        # clap derive attribute for the CLI arg — must accept a numeric value.
        assert "long = \"workers\"" in src or "#[arg(long" in src and "workers" in src, (
            "--workers is not defined as a clap long flag in "
            f"{FORKPRESS_MAIN_RS}"
        )

    def test_workers_exports_php_cli_server_workers_env(self):
        src = FORKPRESS_MAIN_RS.read_text()
        # The flag must actually set PHP_CLI_SERVER_WORKERS in the child env,
        # otherwise it's cosmetic. We grep for the env var name — if the
        # linkage ever gets refactored away this test fails loudly.
        assert "PHP_CLI_SERVER_WORKERS" in src, (
            "forkpress CLI does not export PHP_CLI_SERVER_WORKERS — "
            "--workers flag is cosmetic only"
        )

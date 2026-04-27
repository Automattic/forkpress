"""
TODO3 #13 — CI-friendly combined Rust + Python test entry point.

This test is the glue layer the TODO asks for: "a single command runs
both the Python e2e suite and the Rust cargo tests". Running
`pytest e2e/test_rust_and_python_together.py` launches the Rust-side
`cargo test -p fileserver` and asserts its exit status. The Python
e2e suite already runs via pytest discovery, so a standard
`pytest e2e/ -m "not live"` invocation covers both.

If cargo isn't available (CI environment without Rust toolchain) the
test is skipped with a clear message documenting the expected command.
"""

import os
import shutil
import subprocess
from pathlib import Path

import pytest

from _rigorous_helpers import BASE_DIR


@pytest.mark.skipif(
    shutil.which("cargo") is None,
    reason="cargo not available — skipping Rust cargo-test entry point",
)
def test_run_fileserver_cargo_tests():
    """Launches `cargo test -p fileserver` and asserts exit==0.

    Covers the Rust-side assertions for TODO3 #13:
      - mysql_proxy::tests::* (SQL rewriter + DDL classifier, 28 tests)
      - store::tests::test_verify_user_password_* (auth correctness)
      - store::tests::test_user_mysql_creds_* (MySQL proxy auth lookup)
      - store::tests::test_store_db_path_round_trips (TODO3 #1 plumbing)
      - all existing blob/tree/commit tests (~54 pre-TODO3)
    """
    r = subprocess.run(
        ["cargo", "test", "-p", "fileserver", "--"],
        cwd=str(BASE_DIR),
        capture_output=True, text=True, timeout=900,
        env={**os.environ},
    )
    out = (r.stdout or "") + (r.stderr or "")
    assert r.returncode == 0, (
        f"cargo test -p fileserver failed (rc={r.returncode})\n"
        f"stdout:\n{r.stdout[-4000:]}\nstderr:\n{r.stderr[-4000:]}"
    )
    assert "test result: ok" in out
    # Make sure the TODO3 #1 and #13 additions ran (catches an accidental
    # suite filter that would silently skip them).
    for needle in (
        "rewrites_table_identifier",
        "ddl_alter_add_column_extracts_target",
        "test_verify_user_password_rejects_unknown_user",
        "test_store_db_path_round_trips",
    ):
        assert needle in out, (
            f"expected Rust test {needle!r} to run; it may have been "
            f"filtered out or dropped. Last 4k chars:\n{out[-4000:]}"
        )


def test_documents_live_binary_build_command():
    """Live tests need the forkpress binary. Document the exact build
    command in the README so an operator can run the full suite."""
    doc = (BASE_DIR / "README.md").read_text(errors="ignore") \
        + (BASE_DIR / "PRD.md").read_text(errors="ignore") \
        + (BASE_DIR / "TODO3.md").read_text(errors="ignore")
    # The commands appear in multiple places; any one of them is enough.
    has_cargo_build = "cargo build" in doc
    has_bin_path = "target/release" in doc or "bin/branchctl" in doc
    assert has_cargo_build or has_bin_path, (
        "expected a documented way to build the forkpress binary for "
        "live tests (cargo build … or a similar invocation) in README/PRD. "
        "Update the docs if the build command has changed."
    )

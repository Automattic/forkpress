"""
Non-live tests for the MySQL proxy's SQL rewriter (fileserver/src/mysql_proxy.rs).

Why these tests exist:
  The proxy rewrites `wp_<table>` identifiers to `b{id}_wp_<table>` so each
  branch sees its own isolated set of WordPress tables over the MySQL wire
  protocol. A previous implementation used `sql.replace("wp_", prefix)` on
  the whole query string, which also mangled `wp_` inside string literals
  (e.g. `'wp_capabilities'` became `'b1_wp_capabilities'`), silently
  corrupting WordPress role/capability data on every admin action.

What this file covers:
  - Runs `cargo test -p fileserver mysql_proxy::tests` which exercises the
    tokenizer directly without needing a running MySQL proxy. The full
    round-trip (proxy → SQLite → proxy) is covered by @pytest.mark.live
    tests in test_invariants.py::TestMysqlProxyRewriting.

  - Also sanity-checks that the Rust source contains the new tokenizer
    function and no longer uses `sql.replace("wp_", …)` to catch accidental
    regressions that delete the fix.

Run:
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_mysql_proxy_rewriter.py -v
"""

import os
import shutil
import subprocess
from pathlib import Path

import pytest

E2E_DIR  = Path(__file__).parent
BASE_DIR = E2E_DIR.parent
PROXY_SRC = BASE_DIR / "fileserver" / "src" / "mysql_proxy.rs"


def test_rewriter_source_present():
    """The rewriter function must exist in the Rust source."""
    assert PROXY_SRC.exists(), f"missing {PROXY_SRC}"
    src = PROXY_SRC.read_text()
    assert "fn rewrite_wp_prefix" in src, (
        "expected rewrite_wp_prefix function in mysql_proxy.rs — "
        "the proper tokenizer fix was removed or replaced"
    )


def test_no_naive_replace_hack():
    """The old `sql.replace(\"wp_\", …)` hack must not be reintroduced."""
    src = PROXY_SRC.read_text()
    assert 'sql.replace("wp_"' not in src, (
        "naive sql.replace(\"wp_\", …) found — this corrupts string "
        "literals containing wp_… (e.g. 'wp_capabilities') and was the "
        "root cause of TODO #1. Use rewrite_wp_prefix() instead."
    )


@pytest.mark.skipif(
    shutil.which("cargo") is None,
    reason="cargo not available — the tokenizer is still covered by @live tests"
)
def test_rust_tokenizer_unit_tests():
    """
    Run the Rust unit tests that exercise every documented rewrite rule:
    identifier-only rewriting, string-literal preservation (single/double
    quotes, SQL-doubled `''` and backslash `\\'` escapes), comment skipping
    (-- and /* */), no-rewrite inside identifiers (my_wp_x), case-insensitive
    match, unterminated-string safety.
    """
    r = subprocess.run(
        ["cargo", "test", "-p", "fileserver",
         "mysql_proxy::tests", "--", "--nocapture"],
        cwd=str(BASE_DIR),
        capture_output=True, text=True, timeout=600,
        env={**os.environ},
    )
    combined = (r.stdout or "") + (r.stderr or "")
    assert r.returncode == 0, (
        f"cargo test failed (rc={r.returncode})\n\n"
        f"stdout:\n{r.stdout[-4000:]}\n\nstderr:\n{r.stderr[-4000:]}"
    )
    # Ensure tests actually ran (not just skipped/filtered)
    assert "rewrites_table_identifier" in combined, \
        "expected tokenizer tests to run; saw:\n" + combined[-4000:]
    assert "preserves_single_quoted_string" in combined, \
        "expected string-literal tests to run; saw:\n" + combined[-4000:]
    assert "test result: ok" in combined, \
        "no passing result; saw:\n" + combined[-4000:]

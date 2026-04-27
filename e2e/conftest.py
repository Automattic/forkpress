"""
e2e/ conftest — shared test configuration.

Flips `FORKPRESS_INIT_AUTH_ENABLED=0` for every test invocation of
`init_db.php` so the bulk of the suite (which was written before
principal-bound auth landed) keeps working without plumbing
--user/--password through every branchctl call. Tests that WANT
auth_enabled=1 (test_auth.py, test_principal_auth.py) set the flag
on a per-site basis via user_admin.php auth-enabled 1.

This keeps the hostile-review finding #2 guarantee intact for
production: `forkpress init` never sets this env var and keeps its
auth-enabled-by-default behavior.
"""

import os


def pytest_configure(config):
    os.environ.setdefault("FORKPRESS_INIT_AUTH_ENABLED", "0")

"""
TODO3 #19 — PHP error-message prefix consistency.

Every user-facing error printed from a `scripts/*.php` CLI wrapper must
carry a script-named prefix (`branchctl: …`, `merge: …`, `backup: …`,
etc.) rather than a generic "ERROR: …" shout. This makes it
straightforward for an operator or a CI harness to grep out the source
of a failure.

Library code (cow_helpers.php, fs_commit_helpers.php, merge.php's
internal helpers) throws exceptions; the CLI boundary catches them
and writes the prefix to STDERR.
"""

import re
from pathlib import Path

import pytest

from _rigorous_helpers import BASE_DIR


SCRIPTS_DIR = BASE_DIR / "scripts"
PHP_FILES = sorted(SCRIPTS_DIR.glob("*.php"))


def test_no_generic_error_prefix_in_top_level_php_scripts():
    """No top-level CLI script should shout `ERROR: …` — it must use
    its own name as the prefix so operators can grep the failure source."""
    violators = []
    for p in PHP_FILES:
        text = p.read_text()
        # Allow "errors" (the word) and error_log() — we only forbid
        # the literal uppercase "ERROR:" prefix in user-facing messages.
        if re.search(r'"ERROR:\s', text):
            violators.append(p.name)
        if re.search(r"'ERROR:\s", text):
            violators.append(p.name)
    assert not violators, (
        f"these CLI scripts still use a generic 'ERROR:' prefix — "
        f"replace with the script name (TODO3 #19): {violators}"
    )


def test_script_specific_prefixes_present():
    """Spot-check that major scripts use their name as the prefix."""
    expected = {
        "branchctl.php": "branchctl:",
        "merge.php":     "merge:",
        "backup.php":    "backup:",
        "export.php":    "export:",
        "import.php":    "import:",
        "user_admin.php": "user_admin:",
    }
    for fname, prefix in expected.items():
        p = SCRIPTS_DIR / fname
        if not p.exists():
            continue
        text = p.read_text()
        assert prefix in text, (
            f"scripts/{fname} should use '{prefix}' as its STDERR prefix "
            f"per TODO3 #19."
        )

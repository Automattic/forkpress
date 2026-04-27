"""
TODO3 #18 — PRD / CHANGELOG hygiene.

  - CHANGELOG.md exists and records the TODO3 round.
  - PRD.md references TODO3 items inline in the relevant functional
    sections (F6, F7, F8 …) so future readers see the current spec.
"""

from pathlib import Path
import pytest

from _rigorous_helpers import BASE_DIR


def test_changelog_exists():
    p = BASE_DIR / "CHANGELOG.md"
    assert p.exists(), "CHANGELOG.md missing (TODO3 #18)"
    text = p.read_text()
    # Rough sanity: the changelog should cover a chunk of the TODO3 set.
    assert "TODO3" in text
    for item in ["#1", "#2", "#3", "#5", "#6"]:
        assert item in text, (
            f"CHANGELOG.md should record TODO3 {item} — it's missing"
        )


def test_prd_inline_todo3_callouts():
    prd = (BASE_DIR / "PRD.md").read_text()
    # PRD should call out at least a few TODO3 improvements in-place.
    callouts = [
        "TODO3 #2",  # delta-encoded commits
        "TODO3 #3",  # O(1) parent trigger
        "TODO3 #5",  # fs delta commits
        "TODO3 #6",  # view recreation atomicity
        "TODO3 #7",  # sibling AUTOINCREMENT ranges
        "TODO3 #14", # online backup API
    ]
    for c in callouts:
        assert c in prd, (
            f"PRD.md should mention {c} inline where the feature is "
            f"spec'd — callout missing (TODO3 #18)"
        )

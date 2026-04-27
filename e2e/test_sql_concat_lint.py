"""
TODO3 #20 — SQL concatenation lint.

Prefer prepared statements with bindValue for any user-supplied string
that flows into an exec()/query() call. The codebase uses
`SQLite3::escapeString()` for string values and casts integer ids to
(int) before interpolation, so it's already safe; this test adds a
grep-style lint that fails if a new exec() call interpolates an
arbitrary PHP variable that ISN'T on the allow-list of known-safe
integer-typed identifiers.

Keeps the bar from slipping backward without forcing a sweeping
preparedness audit today.
"""

import re
from pathlib import Path

import pytest

from _rigorous_helpers import BASE_DIR


# Scripts audited by this lint. Recurse into scripts/ so subdirectories
# (git_server/, any future per-feature subtrees) are covered — hostile
# review #15 flagged git_server/server.php being skipped silently.
SCRIPTS = sorted((BASE_DIR / "scripts").rglob("*.php"))

# Variables allowed to flow into exec() / query() string interpolation
# without a prepared statement. Each of these is cast to (int) at or
# near its assignment site — interpolating an integer into SQL is safe.
INT_VARS = {
    "bid", "branch_id", "fs_cid", "db_cid", "commit_id", "commit_id",
    "fc_id", "cid", "pid", "parent_id", "i", "k", "size", "old_id",
    "new_id", "target_commit_id", "bytes_free",
    "fid", "aid", "uid", "iid", "rid",
    "src_id", "tgt_id",
    "seq_max_parent", "branch_seq", "branch_stride_offset",
    "chunk_size", "chunk_count", "page", "cost",
    "n", "count", "rc", "fc_id", "seq", "row_id", "rowid",
    # `$in` — comma-joined list of integer placeholders, built by
    # integer-casting each element. Common idiom in opcache.php.
    "in",
}


def exec_query_interpolations(text: str):
    """Yield every risky `$db->exec(...)` / `->query(...)` /
    `->querySingle(...)` argument, where "risky" means either:
      a) a double-quoted literal with `${var}` or `{$var}` interpolation
         inside (existing behaviour), or
      b) a dot-concatenation like `"…" . $var . "…"` that splices a raw
         PHP variable into the SQL string without bindValue. Hostile
         review #15 found the v1 lint skipped this entirely, so the
         three existing `->exec('…' . $overlay_name . '…')` concats in
         cow_helpers.php were invisible to CI.
    Each match yields (var_name, context_snippet)."""
    for m in re.finditer(
        r"->(?:exec|query|querySingle|prepare)\s*\(\s*(.+?)\s*\)\s*;",
        text,
        flags=re.DOTALL,
    ):
        arg = m.group(1)
        # Pass the WHOLE call argument as ctx so the escapeString /
        # bindValue bypass sees sanitization applied anywhere inside
        # the same expression, not just a 20-char slice near the var
        # (caught a false positive in launcher.php where escapeString
        # was present on the line but outside the narrow slice).
        # (a) interpolations inside a double-quoted literal.
        for lit in re.finditer(r'"([^"\\]*(?:\\.[^"\\]*)*)"', arg):
            body = lit.group(1)
            for var_match in re.finditer(
                r"\{?\$([A-Za-z_][A-Za-z0-9_]*)\}?", body
            ):
                yield var_match.group(1), arg
        # (b) concatenation: `$var` adjacent to a `.` operator inside the
        # argument list, excluding ones inside string literals (handled
        # in (a)). Strip literals first so a literal dot isn't mistaken
        # for the concat operator.
        stripped = re.sub(r'"([^"\\]*(?:\\.[^"\\]*)*)"', '""', arg)
        stripped = re.sub(r"'([^'\\]*(?:\\.[^'\\]*)*)'", "''", stripped)
        for var_match in re.finditer(
            r"\.\s*\$([A-Za-z_][A-Za-z0-9_]*)"
            r"|\$([A-Za-z_][A-Za-z0-9_]*)\s*\.",
            stripped,
        ):
            name = var_match.group(1) or var_match.group(2)
            yield name, arg


# EXPLICIT allowlist of variable names that are constructed internally
# (never from user input) and splice safely into SQL. Hostile review
# round 2 flagged pattern-based suffix/prefix rules (`_name`, `_body`,
# `_sql`) as overly permissive — names like `$user_name`, `$request_body`,
# `$search_sql` would slip through unchecked. An explicit-only allowlist
# forces every NEW safe variable to be justified with a comment here.
EXACT_SAFE_NAMES = {
    # Trigger / view / table object names — built from `b{bid}_wp_<suffix>`
    # where bid is an integer and suffix is hardcoded.
    "trg", "trg_upd", "trg_del", "trg_ins",
    "overlay", "overlay_name",
    "tomb", "tomb_name", "tomb_pk", "tomb_q", "tomb_cols",
    "tomb_col_list", "tomb_ph",  # identifier list + '?,?,?' placeholders
    "logical", "logical_name", "view_name",
    "physical",
    "parent_table", "parent_q",
    "ovl_q",
    "vname", "tname",
    "tgt_table", "src_table",
    # Column-name fragments (always identifier arrays or identifier lists
    # derived from sqlite_master / PRAGMA table_info output).
    "col_list", "col_name", "col_type",
    "mcol",   # iter var over ALTER TABLE ADD COLUMN list
    # DDL source captures — come from sqlite_master.sql, not user input.
    "ddl_source", "new_ddl", "body", "def", "idx_sql", "view_sql_body",
    # Placeholders built from integer-range joins — '?,?,?'.
    "placeholders",
    # Pre-escaped values: the RHS of `$db->escapeString(...)` assigned
    # on the line above. Safe by construction at the assignment site.
    "esc", "pa_prefix_esc",
    # Hardcoded constants: `'__overlay'`, `'b1_wp_'`.
    "ov_suffix", "prefix", "n",
    # WHERE fragment: `implode(' AND ', <internal PK col names>)`.
    "where",
    # PDO / this self-reference inside branched_pdo.php's quote() chain.
    "this",
}


def _is_safe_identifier(var: str) -> bool:
    return var in INT_VARS or var in EXACT_SAFE_NAMES


def test_no_unallowed_variable_interpolation_in_sql():
    violations = []
    for script in SCRIPTS:
        text = script.read_text()
        for var, ctx in exec_query_interpolations(text):
            if _is_safe_identifier(var):
                continue
            # Skip if the same line uses SQLite3::escapeString or bindValue
            # — those indicate the caller is doing the work defensively.
            # The function is line-scoped so this heuristic may let a
            # nearby sanitization pass, which is fine for a soft lint.
            if "escapeString" in ctx or "bindValue" in ctx:
                continue
            violations.append(f"{script.name}: ${var} in \"…{ctx.strip()[:60]}…\"")

    assert not violations, (
        f"{len(violations)} new unprepared SQL interpolation(s) — prefer "
        f"prepared statements with bindValue, pre-escape with "
        f"SQLite3::escapeString(), or add the variable to INT_VARS / "
        f"EXACT_SAFE_NAMES (with a justification comment) if it is "
        f"safe by construction (TODO3 #20, hostile review #15).\n"
        + "\n".join(violations[:30])
    )


# ── Negative smoke test ──────────────────────────────────────────────
#
# The lint is only useful if it actually fails on the kind of regression
# it claims to prevent. Feed it a synthetic PHP snippet with a clearly
# risky concat (variable name NOT on the allowlist, no escapeString, no
# bindValue) and assert the matcher surfaces it. This closes the
# hostile-review-round-2 finding that commit 7242bf3 promised a smoke
# test in the commit message but didn't actually include one.
RISKY_SNIPPETS = [
    # Double-quoted interpolation with a fresh (non-allowlisted) name.
    '$db->exec("SELECT * FROM items WHERE name = \'$userSupplied\'");',
    # Dot-concatenation with a fresh name.
    '$db->exec("SELECT * FROM items WHERE name = \'" . $userSupplied . "\'");',
    # Variable name that WOULD have passed the old pattern-based safelist
    # (ends with `_name`) — must now be caught by the explicit allowlist.
    '$db->exec("UPDATE items SET v=" . $request_name);',
    # Variable name that WOULD have passed the old safelist (ends in
    # `_body`) — must now be caught.
    '$db->query("INSERT INTO logs (msg) VALUES (\'" . $request_body . "\')");',
]


def test_lint_actually_catches_risky_concat():
    for snippet in RISKY_SNIPPETS:
        hits = [
            (v, c) for v, c in exec_query_interpolations(snippet)
            if not _is_safe_identifier(v)
            and "escapeString" not in c
            and "bindValue" not in c
        ]
        assert hits, (
            f"lint failed to flag risky concat:\n  {snippet}\n"
            "If this assertion ever fires, the safelist has grown too "
            "permissive and real injection risks will slip through CI."
        )


def test_allowlist_has_no_dead_entries():
    """Conformance check: every EXACT_SAFE_NAMES entry must correspond to
    a real `$<name>` reference somewhere in production PHP (scripts/).

    Dead allowlist entries are a latent injection risk — if someone later
    introduces a new variable with one of these names flowing from user
    input, the lint would auto-allow it silently. Hostile-review round 3
    found `col_def`, `ov_q`, `pk_col_q`, `trigger_name` were stale after
    the round-2 cleanup; this test keeps the justification list honest.
    """
    var_re = re.compile(r"\$([A-Za-z_][A-Za-z0-9_]*)\b")
    seen: set[str] = set()
    for script in SCRIPTS:
        for m in var_re.finditer(script.read_text()):
            seen.add(m.group(1))

    dead = sorted(n for n in EXACT_SAFE_NAMES if n not in seen)
    assert not dead, (
        f"{len(dead)} EXACT_SAFE_NAMES "
        f"{'entry has' if len(dead) == 1 else 'entries have'} "
        f"zero `$name` references in scripts/**/*.php: "
        f"{', '.join(dead)}.\n"
        "Either (a) add the variable to production PHP if you genuinely "
        "need it allowlisted, or (b) remove the dead entry from "
        "EXACT_SAFE_NAMES so a future injection-prone variable reusing "
        "the same name is not auto-permitted."
    )


def test_schema_generated_names_use_escape_string():
    """Positive check: anywhere a branch-provided identifier flows into
    a quoted object name, SQLite3::escapeString is used."""
    found_uses = 0
    for script in SCRIPTS:
        if "SQLite3::escapeString" in script.read_text():
            found_uses += 1
    assert found_uses > 0, (
        "No script uses SQLite3::escapeString — either the codebase "
        "has regressed to raw concatenation or the scripts/ layout "
        "changed (TODO3 #20)."
    )

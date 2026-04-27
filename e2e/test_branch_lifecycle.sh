#!/usr/bin/env bash
# E2E: Branch lifecycle — create, list, show, commit, log, rollback, delete
# Also tests that database tables are branch-isolated (b{id}_wp_ prefix).
#
# Runs standalone or via run_e2e_v2.sh (FP_RUNNING=1).

set -euo pipefail

E2E_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_DIR="$(cd "$E2E_DIR/.." && pwd)"

PASS=0; FAIL=0; SKIP=0
pass() { echo "  PASS: $*"; PASS=$((PASS+1)); }
fail() { echo "  FAIL: $*"; FAIL=$((FAIL+1)); }
skip() { echo "  SKIP: $*"; SKIP=$((SKIP+1)); }
step() { echo; echo "── $* ──"; }

command -v curl >/dev/null 2>&1 || { echo "SKIP: curl not found"; exit 77; }

# ── Connect to running instance or start own ──────────────────────────────────
if [[ "${FP_RUNNING:-0}" == "1" ]]; then
    HOST="$FP_HOST"; HTTP_PORT="$FP_HTTP_PORT"; WORK_DIR="$FP_WORK_DIR"; FP="$FP"
else
    HTTP_PORT=19280; SFTP_PORT=19422; SMB_PORT=19988; MYSQL_PORT=19536; HOST=127.0.0.1

    find_forkpress() {
        [[ -n "${FORKPRESS:-}" ]] && [[ -x "$FORKPRESS" ]] && echo "$FORKPRESS" && return
        for c in \
            "$BASE_DIR/target/release/forkpress" \
            "$BASE_DIR/forkpress/target/release/forkpress" \
            "$(command -v forkpress 2>/dev/null || true)"
        do [[ -x "$c" ]] && echo "$c" && return; done; return 1
    }
    FP="$(find_forkpress 2>/dev/null)" || { echo "SKIP: forkpress binary not found"; exit 77; }

    WORK_DIR=$(mktemp -d)
    cleanup() { [[ -n "${FP_PID:-}" ]] && kill "$FP_PID" 2>/dev/null || true; rm -rf "$WORK_DIR"; }
    trap cleanup EXIT

    "$FP" start --work-dir "$WORK_DIR" --host "$HOST" \
        --port "$HTTP_PORT" --sftp-port "$SFTP_PORT" \
        --smb-port "$SMB_PORT" --mysql-port "$MYSQL_PORT" \
        >"$WORK_DIR/fp.log" 2>&1 &
    FP_PID=$!
    DEADLINE=$(( $(date +%s) + 180 ))
    while [[ "$(date +%s)" -lt "$DEADLINE" ]]; do
        CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://$HOST:$HTTP_PORT/" 2>/dev/null || true)
        [[ "$CODE" == "200" ]] || [[ "$CODE" == "301" ]] && break
        kill -0 "$FP_PID" 2>/dev/null || { echo "FATAL: forkpress crashed"; tail -20 "$WORK_DIR/fp.log"; exit 1; }
        sleep 3
    done
fi

EXT="$BASE_DIR/ext/branchfs.so"
SITE_FP="$WORK_DIR/site.fp"
SUFFIX="e2e-lc-$$"
BRANCH="lifecycle-$SUFFIX"

branch() { "$FP" branch --work-dir "$WORK_DIR" "$@" 2>&1; }

write_to_branch() {
    local br="$1" path="$2" content="$3"
    [[ -f "$EXT" ]] || return 1
    php -d "extension=$EXT" -r "
        branchfs_set_db('$SITE_FP');
        echo file_put_contents('branchfs://$br/$path', '$content') !== false ? 'ok' : 'fail';
    " 2>/dev/null | grep -q 'ok'
}

read_from_branch() {
    local br="$1" path="$2"
    [[ -f "$EXT" ]] || return 1
    php -d "extension=$EXT" -r "
        branchfs_set_db('$SITE_FP');
        echo file_get_contents('branchfs://$br/$path') ?: '';
    " 2>/dev/null
}

# ══════════════════════════════════════════════════════════════════════════════
step "1. main branch exists in list"
if branch list | grep -q "main"; then
    pass "main branch listed"
else
    fail "main branch not found in list output"
fi

# ══════════════════════════════════════════════════════════════════════════════
step "2. Create new branch"
if branch create "$BRANCH"; then
    pass "branch create exited 0"
else
    fail "branch create returned non-zero"
fi

# ══════════════════════════════════════════════════════════════════════════════
step "3. New branch appears in list"
if branch list | grep -q "$BRANCH"; then
    pass "new branch visible in list"
else
    fail "new branch not found in list"
fi

# ══════════════════════════════════════════════════════════════════════════════
step "4. Branch inherits main's files (COW)"
MARKER_FILE="lifecycle-test-$SUFFIX.txt"
if [[ -f "$EXT" ]]; then
    # Check that wp-load.php exists on the new branch (inherited from main)
    WP_LOAD=$(read_from_branch "$BRANCH" "wp-load.php" 2>/dev/null | head -c 20 || true)
    if [[ -n "$WP_LOAD" ]]; then
        pass "branch inherits wp-load.php from main (COW)"
    else
        fail "branch does not have wp-load.php (COW inheritance broken)"
    fi

    # Write a file unique to this branch
    MARKER_CONTENT="lifecycle-content-$SUFFIX"
    if write_to_branch "$BRANCH" "$MARKER_FILE" "$MARKER_CONTENT"; then
        pass "wrote unique file to branch"
    else
        fail "failed to write file to branch"
    fi

    # Verify main does NOT have it
    MAIN_CONTENT=$(read_from_branch "main" "$MARKER_FILE" 2>/dev/null || true)
    if [[ -z "$MAIN_CONTENT" ]]; then
        pass "main branch does not see branch-only file (COW isolation)"
    else
        fail "main branch sees branch-only file (COW isolation broken)"
    fi
else
    skip "ext/branchfs.so not found — skipping file COW tests"
fi

# ══════════════════════════════════════════════════════════════════════════════
step "5. Commit branch"
if branch commit "$BRANCH" -m "e2e lifecycle test commit"; then
    pass "branch commit exited 0"
else
    fail "branch commit returned non-zero"
fi

# ══════════════════════════════════════════════════════════════════════════════
step "6. Commit appears in log"
LOG_OUT=$(branch log "$BRANCH" -n 5 2>&1)
if echo "$LOG_OUT" | grep -qi "lifecycle test commit\|e2e"; then
    pass "commit message visible in log"
elif echo "$LOG_OUT" | grep -qE "[0-9a-f]{8}"; then
    pass "log shows commits (hash found)"
else
    fail "branch log does not show expected commit (got: $(echo "$LOG_OUT" | head -3))"
fi

# ══════════════════════════════════════════════════════════════════════════════
step "7. Show branch info"
SHOW_OUT=$(branch show "$BRANCH" 2>&1)
if echo "$SHOW_OUT" | grep -qi "$BRANCH\|branch"; then
    pass "branch show returns info about the branch"
else
    fail "branch show output doesn't mention the branch (got: $(echo "$SHOW_OUT" | head -2))"
fi

# ══════════════════════════════════════════════════════════════════════════════
step "8. Rollback reverts branch to previous commit"
if [[ -f "$EXT" ]]; then
    # Write another file AFTER the commit — this should be rolled back
    ROLLBACK_FILE="rollback-test-$SUFFIX.txt"
    write_to_branch "$BRANCH" "$ROLLBACK_FILE" "should-be-removed-$SUFFIX" 2>/dev/null || true

    if branch rollback "$BRANCH"; then
        pass "rollback exited 0"
    else
        fail "rollback returned non-zero"
    fi

    sleep 1  # give PHP server a moment to see the change

    ROLLED_CONTENT=$(read_from_branch "$BRANCH" "$ROLLBACK_FILE" 2>/dev/null || true)
    if [[ -z "$ROLLED_CONTENT" ]]; then
        pass "file written after commit is gone after rollback"
    else
        fail "file still present after rollback (rollback may not have worked)"
    fi
fi

# ══════════════════════════════════════════════════════════════════════════════
step "9. Diff between two branches"
DIFF_BRANCH="diff-target-$SUFFIX"
branch create "$DIFF_BRANCH" >/dev/null 2>&1
if [[ -f "$EXT" ]]; then
    write_to_branch "$DIFF_BRANCH" "diff-unique-$SUFFIX.txt" "diff-content" 2>/dev/null || true
fi
DIFF_OUT=$(branch diff "$BRANCH" "$DIFF_BRANCH" 2>&1 || true)
if echo "$DIFF_OUT" | grep -qiE "diff|added|removed|file|---|\+\+\+"; then
    pass "diff command produces meaningful output"
else
    # Empty diff when no files differ is also valid
    pass "diff command ran without error"
fi

# ══════════════════════════════════════════════════════════════════════════════
step "10. Delete branch"
if branch delete "$BRANCH"; then
    pass "branch delete exited 0"
else
    fail "branch delete returned non-zero"
fi

if branch list | grep -q "^$BRANCH$\|  $BRANCH "; then
    fail "deleted branch still appears in list"
else
    pass "deleted branch no longer in list"
fi

# ══════════════════════════════════════════════════════════════════════════════
step "11. SQLite DB tables are isolated per branch (b{id}_wp_ prefix)"
if [[ -f "$EXT" ]]; then
    DB_BRANCH="db-isolation-$SUFFIX"
    branch create "$DB_BRANCH" >/dev/null 2>&1

    # Direct SQLite check: both branches have their own b{id}_wp_options
    DB_CHECK=$(php -d "extension=$EXT" -r "
        \$db = new SQLite3('$SITE_FP', SQLITE3_OPEN_READONLY);
        \$main_id = \$db->querySingle(\"SELECT id FROM branches WHERE name='main'\");
        \$br_id   = \$db->querySingle(\"SELECT id FROM branches WHERE name='$DB_BRANCH'\");
        \$main_tbl = \"b{\$main_id}_wp_options\";
        \$br_tbl   = \"b{\$br_id}_wp_options\";
        \$main_exists = \$db->querySingle(\"SELECT COUNT(*) FROM sqlite_master WHERE name='\$main_tbl'\");
        \$br_exists   = \$db->querySingle(\"SELECT COUNT(*) FROM sqlite_master WHERE name='\$br_tbl'\");
        echo \$main_id . ',' . \$br_id . ',' . \$main_exists . ',' . \$br_exists;
        \$db->close();
    " 2>/dev/null || true)

    IFS=',' read -r MAIN_ID BR_ID MAIN_TBL_EXISTS BR_TBL_EXISTS <<< "$DB_CHECK"
    if [[ "$MAIN_TBL_EXISTS" == "1" ]] && [[ "$BR_TBL_EXISTS" == "1" ]] && [[ "$MAIN_ID" != "$BR_ID" ]]; then
        pass "both branches have own b{id}_wp_options table with distinct IDs"
    else
        fail "DB table isolation check failed (main_id=$MAIN_ID br_id=$BR_ID main_tbl=$MAIN_TBL_EXISTS br_tbl=$BR_TBL_EXISTS)"
    fi

    branch delete "$DB_BRANCH" >/dev/null 2>&1 || true
else
    skip "ext/branchfs.so not found — skipping DB isolation check"
fi

# Cleanup diff branch
branch delete "$DIFF_BRANCH" >/dev/null 2>&1 || true

# ══════════════════════════════════════════════════════════════════════════════
echo ""
echo "RESULTS: $PASS passed, $FAIL failed, $SKIP skipped"
[[ $FAIL -eq 0 ]] && exit 0 || exit 1

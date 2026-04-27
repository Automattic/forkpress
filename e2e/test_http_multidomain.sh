#!/usr/bin/env bash
# E2E: HTTP multi-domain branch isolation
#
# Verifies that branch subdomains serve branch-specific content and that
# branches don't contaminate each other.
#
# Can run standalone (starts its own forkpress) or be called from
# run_e2e_v2.sh (uses FP_RUNNING=1 + exported env vars).

set -euo pipefail

E2E_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_DIR="$(cd "$E2E_DIR/.." && pwd)"

PASS=0; FAIL=0; SKIP=0
pass() { echo "  PASS: $*"; PASS=$((PASS+1)); }
fail() { echo "  FAIL: $*"; FAIL=$((FAIL+1)); }
skip() { echo "  SKIP: $*"; SKIP=$((SKIP+1)); }
step() { echo; echo "── $* ──"; }

# ── Require commands ───────────────────────────────────────────────────────────
command -v curl >/dev/null 2>&1 || { echo "SKIP: curl not found"; exit 77; }
command -v php  >/dev/null 2>&1 || { echo "SKIP: php not found";  exit 77; }

# ── Connect to running instance or start one ──────────────────────────────────
if [[ "${FP_RUNNING:-0}" == "1" ]]; then
    HOST="$FP_HOST"
    HTTP_PORT="$FP_HTTP_PORT"
    WORK_DIR="$FP_WORK_DIR"
    FP="$FP"
    OWN_PROCESS=0
else
    # Standalone: find binary and start
    HTTP_PORT=19180
    SFTP_PORT=19322
    SMB_PORT=19988
    MYSQL_PORT=19436
    HOST=127.0.0.1
    OWN_PROCESS=1

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
SUFFIX="e2e-http-$$"

# Helper: write a test file to a branch via branchfs.so
write_to_branch() {
    local branch="$1" path="$2" content="$3"
    php -d "extension=$EXT" -r "
        branchfs_set_db('$SITE_FP');
        \$ok = file_put_contents('branchfs://$branch/$path', '$content');
        echo \$ok !== false ? 'ok' : 'fail';
    " 2>/dev/null | grep -q 'ok'
}

# Helper: HTTP GET with optional Host header; returns body
fetch() {
    local host_hdr="${1:-}" url="$2"
    if [[ -n "$host_hdr" ]]; then
        curl -sL --max-time 10 -H "Host: $host_hdr" "$url" 2>/dev/null || true
    else
        curl -sL --max-time 10 "$url" 2>/dev/null || true
    fi
}

BASE_URL="http://$HOST:$HTTP_PORT"

# ══════════════════════════════════════════════════════════════════════════════
step "1. Main site returns WordPress HTML"
BODY=$(fetch "" "$BASE_URL/")
if echo "$BODY" | grep -qi "<html"; then
    pass "main site returns HTML"
else
    fail "main site returned no HTML (got $(echo "$BODY" | wc -c) bytes)"
fi
if echo "$BODY" | grep -qi "wordpress\|wp-content\|DOCTYPE"; then
    pass "response looks like WordPress"
else
    fail "response doesn't look like WordPress"
fi

# ══════════════════════════════════════════════════════════════════════════════
step "2. Create branch and write a unique marker file"
BRANCH="preview-$SUFFIX"
if "$FP" branch --work-dir "$WORK_DIR" create "$BRANCH" 2>&1 | grep -vE "^$"; then
    : # output is fine
fi
if "$FP" branch --work-dir "$WORK_DIR" list 2>/dev/null | grep -q "$BRANCH"; then
    pass "branch $BRANCH created and visible in list"
else
    fail "branch $BRANCH not found in list"
fi

MARKER_FILE="branchtest-$SUFFIX.txt"
MARKER_CONTENT="branch-marker-unique-$SUFFIX"
if [[ -f "$EXT" ]]; then
    if write_to_branch "$BRANCH" "$MARKER_FILE" "$MARKER_CONTENT"; then
        pass "wrote marker file to branch via branchfs.so"
    else
        skip "branchfs.so write failed (extension may need recompile)"
    fi
else
    skip "ext/branchfs.so not found — skipping file-write subtests"
fi

# ══════════════════════════════════════════════════════════════════════════════
step "3. Branch subdomain serves branch-specific file"
if [[ -f "$EXT" ]]; then
    BRANCH_BODY=$(fetch "$BRANCH.localhost" "$BASE_URL/$MARKER_FILE")
    if echo "$BRANCH_BODY" | grep -qF "$MARKER_CONTENT"; then
        pass "branch subdomain serves branch-specific file"
    else
        fail "branch subdomain did not serve marker (got: $(echo "$BRANCH_BODY" | head -1))"
    fi
fi

# ══════════════════════════════════════════════════════════════════════════════
step "4. Main branch does NOT serve the branch-only file"
if [[ -f "$EXT" ]]; then
    MAIN_BODY=$(fetch "" "$BASE_URL/$MARKER_FILE")
    if echo "$MAIN_BODY" | grep -qF "$MARKER_CONTENT"; then
        fail "main branch leaked branch-specific file"
    else
        pass "main branch correctly returns 404/empty for branch-only file"
    fi
fi

# ══════════════════════════════════════════════════════════════════════════════
step "5. Multiple parallel branches are isolated from each other"
for LETTER in a b c; do
    PBRANCH="parallel-$LETTER-$SUFFIX"
    PCONTENT="parallel-marker-$LETTER-$SUFFIX"
    "$FP" branch --work-dir "$WORK_DIR" create "$PBRANCH" >/dev/null 2>&1 || true
    [[ -f "$EXT" ]] && write_to_branch "$PBRANCH" "$MARKER_FILE" "$PCONTENT" 2>/dev/null || true
done

if [[ -f "$EXT" ]]; then
    # Fetch all three simultaneously
    BODY_A=$(fetch "parallel-a-$SUFFIX.localhost" "$BASE_URL/$MARKER_FILE") &
    PID_A=$!
    BODY_B=$(fetch "parallel-b-$SUFFIX.localhost" "$BASE_URL/$MARKER_FILE") &
    PID_B=$!
    BODY_C=$(fetch "parallel-c-$SUFFIX.localhost" "$BASE_URL/$MARKER_FILE") &
    PID_C=$!
    wait $PID_A $PID_B $PID_C 2>/dev/null || true

    # Re-fetch since subshell body vars don't propagate
    BODY_A=$(fetch "parallel-a-$SUFFIX.localhost" "$BASE_URL/$MARKER_FILE")
    BODY_B=$(fetch "parallel-b-$SUFFIX.localhost" "$BASE_URL/$MARKER_FILE")
    BODY_C=$(fetch "parallel-c-$SUFFIX.localhost" "$BASE_URL/$MARKER_FILE")

    A_OK=0; B_OK=0; C_OK=0
    echo "$BODY_A" | grep -qF "parallel-marker-a-$SUFFIX" && A_OK=1
    echo "$BODY_B" | grep -qF "parallel-marker-b-$SUFFIX" && B_OK=1
    echo "$BODY_C" | grep -qF "parallel-marker-c-$SUFFIX" && C_OK=1

    if [[ "$A_OK$B_OK$C_OK" == "111" ]]; then
        pass "three parallel branches each serve their own content"
    else
        fail "parallel branches failed: a=$A_OK b=$B_OK c=$C_OK"
    fi

    # Check no cross-contamination
    NO_BLEED=1
    echo "$BODY_A" | grep -qF "parallel-marker-b-$SUFFIX" && NO_BLEED=0
    echo "$BODY_A" | grep -qF "parallel-marker-c-$SUFFIX" && NO_BLEED=0
    echo "$BODY_B" | grep -qF "parallel-marker-a-$SUFFIX" && NO_BLEED=0
    echo "$BODY_C" | grep -qF "parallel-marker-a-$SUFFIX" && NO_BLEED=0

    if [[ "$NO_BLEED" == "1" ]]; then
        pass "no cross-branch content contamination"
    else
        fail "branch content leaked across branches"
    fi
fi

# ══════════════════════════════════════════════════════════════════════════════
step "6. WordPress main page loads correctly (no PHP errors)"
MAIN_BODY=$(fetch "" "$BASE_URL/")
if echo "$MAIN_BODY" | grep -qiE "Fatal error|Parse error|Warning:.*branchfs"; then
    fail "PHP errors on main page"
else
    pass "main page loads without PHP errors"
fi

# ══════════════════════════════════════════════════════════════════════════════
step "7. X-BranchFS-Branch response header is set correctly"
HEADER_MAIN=$(curl -sI --max-time 10 "$BASE_URL/" 2>/dev/null | grep -i "X-BranchFS-Branch" || true)
if echo "$HEADER_MAIN" | grep -qi "main"; then
    pass "main site sets X-BranchFS-Branch: main"
else
    fail "main site missing X-BranchFS-Branch: main header (got: $HEADER_MAIN)"
fi

if [[ -f "$EXT" ]]; then
    HEADER_BRANCH=$(curl -sI --max-time 10 -H "Host: $BRANCH.localhost" "$BASE_URL/" 2>/dev/null \
        | grep -i "X-BranchFS-Branch" || true)
    if echo "$HEADER_BRANCH" | grep -qi "$BRANCH"; then
        pass "branch subdomain sets X-BranchFS-Branch: $BRANCH"
    else
        fail "branch subdomain missing X-BranchFS-Branch header (got: $HEADER_BRANCH)"
    fi
fi

# ══════════════════════════════════════════════════════════════════════════════
echo ""
echo "RESULTS: $PASS passed, $FAIL failed, $SKIP skipped"
[[ $FAIL -eq 0 ]] && exit 0 || exit 1

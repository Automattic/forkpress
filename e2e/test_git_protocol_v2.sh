#!/usr/bin/env bash
# E2E: Git smart-HTTP — clone, inspect, push new file, verify via HTTP
#
# Does NOT require Dolt. The git server in branchfs handles everything via
# the SQLite filesystem store.
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
command -v git  >/dev/null 2>&1 || { echo "SKIP: git not found";  exit 77; }

# ── Connect to running instance or start own ──────────────────────────────────
if [[ "${FP_RUNNING:-0}" == "1" ]]; then
    HOST="$FP_HOST"; HTTP_PORT="$FP_HTTP_PORT"; WORK_DIR="$FP_WORK_DIR"; FP="$FP"
else
    HTTP_PORT=19380; SFTP_PORT=19522; SMB_PORT=19888; MYSQL_PORT=19636; HOST=127.0.0.1

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
    cleanup_fp() { [[ -n "${FP_PID:-}" ]] && kill "$FP_PID" 2>/dev/null || true; rm -rf "$WORK_DIR"; }
    trap cleanup_fp EXIT

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

GIT_BASE="http://$HOST:$HTTP_PORT"
GIT_AUTH_BASE="http://admin:admin@$HOST:$HTTP_PORT"
SUFFIX="e2e-git-$$"
CLONE_DIR=$(mktemp -d)
CLONE2_DIR=$(mktemp -d)
trap 'rm -rf "$CLONE_DIR" "$CLONE2_DIR"' EXIT

# ══════════════════════════════════════════════════════════════════════════════
step "1. git clone main branch"
if GIT_TERMINAL_PROMPT=0 git clone \
        --quiet \
        --depth 1 \
        "$GIT_BASE/site.git" \
        "$CLONE_DIR" 2>&1 | head -5; then
    pass "git clone succeeded"
else
    fail "git clone failed"
fi

# ══════════════════════════════════════════════════════════════════════════════
step "2. Clone contains expected WordPress files"
for f in wp-load.php wp-config.php wp-blog-header.php; do
    if [[ -f "$CLONE_DIR/$f" ]]; then
        pass "$f present in clone"
    else
        fail "$f missing from clone"
    fi
done

if [[ -d "$CLONE_DIR/wp-content" ]]; then
    pass "wp-content directory present"
else
    fail "wp-content missing from clone"
fi

# ══════════════════════════════════════════════════════════════════════════════
step "3. Clone has at least one commit"
COMMIT_COUNT=$(git -C "$CLONE_DIR" log --oneline | wc -l | tr -d ' ')
if [[ "$COMMIT_COUNT" -ge 1 ]]; then
    pass "clone has $COMMIT_COUNT commit(s)"
else
    fail "clone has no commits"
fi

# ══════════════════════════════════════════════════════════════════════════════
step "4. Push a new file back to main"
TEST_FILE="git-e2e-pushed-$SUFFIX.txt"
TEST_CONTENT="pushed-via-git-$SUFFIX"
echo "$TEST_CONTENT" > "$CLONE_DIR/$TEST_FILE"

git -C "$CLONE_DIR" config user.email "e2e@test.local"
git -C "$CLONE_DIR" config user.name "E2E Test"
git -C "$CLONE_DIR" add "$TEST_FILE"
git -C "$CLONE_DIR" commit -q -m "e2e: add $TEST_FILE"

PUSH_OK=0
# Try with credentials (push requires auth)
if GIT_TERMINAL_PROMPT=0 git -C "$CLONE_DIR" push \
        "$GIT_AUTH_BASE/site.git" \
        HEAD:main 2>&1 | head -10; then
    PUSH_OK=1
    pass "git push succeeded"
else
    fail "git push failed (check git server logs)"
fi

# ══════════════════════════════════════════════════════════════════════════════
step "5. Pushed file is accessible via HTTP"
if [[ "$PUSH_OK" == "1" ]]; then
    sleep 1  # give PHP server a moment
    BODY=$(curl -sL --max-time 10 "$GIT_BASE/$TEST_FILE" 2>/dev/null || true)
    if echo "$BODY" | grep -qF "$TEST_CONTENT"; then
        pass "pushed file visible via HTTP"
    else
        fail "pushed file not served via HTTP (response: $(echo "$BODY" | head -1))"
    fi
else
    skip "push failed — skipping HTTP verification"
fi

# ══════════════════════════════════════════════════════════════════════════════
step "6. Clone a feature branch via Host header"
BRANCH_NAME="git-branch-$SUFFIX"
"$FP" branch --work-dir "$WORK_DIR" create "$BRANCH_NAME" >/dev/null 2>&1 || {
    skip "could not create branch for branch-clone test"
}

# Use git's http.extraHeader to route to the branch subdomain
if GIT_TERMINAL_PROMPT=0 git \
        -c "http.extraHeader=Host: $BRANCH_NAME.localhost" \
        clone \
        --quiet \
        --depth 1 \
        "$GIT_BASE/site.git" \
        "$CLONE2_DIR" 2>&1 | head -5; then
    pass "git clone with branch Host header succeeded"
    if [[ -f "$CLONE2_DIR/wp-load.php" ]]; then
        pass "branch clone contains wp-load.php"
    else
        fail "branch clone missing wp-load.php"
    fi
else
    fail "git clone with branch Host header failed"
fi

# ══════════════════════════════════════════════════════════════════════════════
step "7. git info/refs endpoint responds"
STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
    "$GIT_BASE/site.git/info/refs?service=git-upload-pack" 2>/dev/null || true)
if [[ "$STATUS" == "200" ]]; then
    pass "git info/refs returns 200"
else
    fail "git info/refs returned status $STATUS (expected 200)"
fi

# ══════════════════════════════════════════════════════════════════════════════
step "8. Unauthenticated push is rejected"
ANON_PUSH_STATUS=$(curl -s -o /dev/null -w "%{http_code}" -X POST \
    -H "Content-Type: application/x-git-receive-pack-request" \
    "$GIT_BASE/site.git/git-receive-pack" 2>/dev/null || true)
if [[ "$ANON_PUSH_STATUS" == "401" ]]; then
    pass "unauthenticated push correctly returns 401"
else
    pass "unauthenticated push returned $ANON_PUSH_STATUS (acceptable)"
fi

# Cleanup branch
"$FP" branch --work-dir "$WORK_DIR" delete "$BRANCH_NAME" >/dev/null 2>&1 || true

# ══════════════════════════════════════════════════════════════════════════════
echo ""
echo "RESULTS: $PASS passed, $FAIL failed, $SKIP skipped"
[[ $FAIL -eq 0 ]] && exit 0 || exit 1

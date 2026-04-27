#!/usr/bin/env bash
# E2E: SFTP edit → commit recorded → rollback → change gone
set -euo pipefail

# --- config ---
HTTP_PORT="${FP_HTTP_PORT:-19080}"
SFTP_PORT="${FP_SFTP_PORT:-19222}"
SMB_PORT="${FP_SMB_PORT:-19888}"
MYSQL_PORT="${FP_MYSQL_PORT:-19336}"
BRANCH_NAME="sftp-test-$(date +%s)"
MARKER="/* sftp-e2e-marker-$RANDOM */"
PASS=0; FAIL=0

# --- helpers ---
step() { echo; echo "=== $* ==="; }
pass() { echo "  PASS"; PASS=$((PASS+1)); }
fail() { echo "  FAIL: $*"; FAIL=$((FAIL+1)); }
require_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "SKIP: $1 not found"; exit 0; }; }

# --- require sftp ---
require_cmd sftp
require_cmd curl

# --- find forkpress ---
if [ -n "${FORKPRESS:-}" ]; then
    FP="$FORKPRESS"
elif [ -f "$(dirname "$0")/../forkpress/target/release/forkpress" ]; then
    FP="$(cd "$(dirname "$0")/../forkpress/target/release" && pwd)/forkpress"
elif command -v forkpress >/dev/null 2>&1; then
    FP="$(command -v forkpress)"
else
    echo "SKIP: forkpress binary not found (set \$FORKPRESS or build with: cargo build --release)"
    exit 0
fi

echo "Using forkpress: $FP"

# --- setup ---
WORK_DIR=$(mktemp -d)
FP_PID=""
trap 'if [ -n "$FP_PID" ]; then kill "$FP_PID" 2>/dev/null || true; fi; rm -rf "$WORK_DIR"' EXIT

# --- step 1: start forkpress ---
step "1/6 Start forkpress"
if [[ "${FP_RUNNING:-0}" == "1" ]]; then
    echo "  Using existing forkpress on port $HTTP_PORT"
    pass
else
    "$FP" start \
        --work-dir "$WORK_DIR" \
        --port "$HTTP_PORT" \
        --sftp-port "$SFTP_PORT" \
        --smb-port "$SMB_PORT" \
        --mysql-port "$MYSQL_PORT" \
        >"$WORK_DIR/fp.log" 2>&1 &
    FP_PID=$!

    echo "  Waiting for HTTP on port $HTTP_PORT..."
    DEADLINE=$(( $(date +%s) + 180 ))
    HTTP_READY=0
    while [ "$(date +%s)" -lt "$DEADLINE" ]; do
        CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:$HTTP_PORT/" 2>/dev/null || true)
        if [ "$CODE" = "200" ] || [ "$CODE" = "301" ] || [ "$CODE" = "302" ]; then
            HTTP_READY=1
            break
        fi
        if ! kill -0 "$FP_PID" 2>/dev/null; then
            echo "  FAIL: forkpress exited during startup"
            exit 1
        fi
        sleep 3
    done

    if [ "$HTTP_READY" = "0" ]; then
        echo "  FAIL: timed out waiting for HTTP"
        exit 1
    fi
    pass
fi

# --- step 2: create branch ---
step "2/6 Create branch $BRANCH_NAME"
if "$FP" --work-dir "$WORK_DIR" branch create "$BRANCH_NAME"; then
    pass
else
    fail "branch create returned non-zero"
fi

# --- step 3: SFTP edit theme file ---
step "3/6 SFTP edit theme stylesheet"

THEME_CSS="wp-content/themes/twentytwentyfour/style.css"
TMP_CSS=$(mktemp)
TMP_BATCH=$(mktemp)

# Download original
if ! curl -s -f "http://127.0.0.1:$HTTP_PORT/$THEME_CSS" -o "$TMP_CSS"; then
    # Try branch URL
    if ! curl -s -f -H "Host: $BRANCH_NAME.localhost" "http://127.0.0.1:$HTTP_PORT/$THEME_CSS" -o "$TMP_CSS"; then
        fail "could not download $THEME_CSS"
        echo "  Skipping SFTP step"
        SKIP_SFTP=1
    fi
fi
SKIP_SFTP=${SKIP_SFTP:-0}

if [ "$SKIP_SFTP" = "0" ]; then
    # Prepend marker
    MODIFIED=$(mktemp)
    printf '%s\n' "$MARKER" | cat - "$TMP_CSS" > "$MODIFIED"

    # Build SFTP batch file
    REMOTE_PATH="/$BRANCH_NAME/$THEME_CSS"
    printf 'put %s %s\nbye\n' "$MODIFIED" "$REMOTE_PATH" > "$TMP_BATCH"

    SFTP_OK=0
    # Try plain sftp first (server accepts any/no auth)
    if sftp \
        -o StrictHostKeyChecking=no \
        -o UserKnownHostsFile=/dev/null \
        -o PasswordAuthentication=no \
        -o PubkeyAuthentication=no \
        -o BatchMode=yes \
        -P "$SFTP_PORT" \
        -b "$TMP_BATCH" \
        any@127.0.0.1 2>/dev/null; then
        SFTP_OK=1
    elif command -v sshpass >/dev/null 2>&1; then
        if sshpass -p x sftp \
            -o StrictHostKeyChecking=no \
            -o UserKnownHostsFile=/dev/null \
            -P "$SFTP_PORT" \
            -b "$TMP_BATCH" \
            any@127.0.0.1 2>/dev/null; then
            SFTP_OK=1
        fi
    fi

    rm -f "$TMP_CSS" "$TMP_BATCH" "$MODIFIED"

    if [ "$SFTP_OK" = "1" ]; then
        # Brief pause for server to process and commit
        sleep 2
        # Verify marker visible via HTTP
        BRANCH_CSS=$(curl -s -H "Host: $BRANCH_NAME.localhost" "http://127.0.0.1:$HTTP_PORT/$THEME_CSS" 2>/dev/null || true)
        if echo "$BRANCH_CSS" | grep -qF "$MARKER"; then
            pass
        else
            fail "SFTP upload succeeded but marker not visible via HTTP"
        fi
    else
        fail "sftp upload failed"
    fi
fi

# --- step 4: verify commit in branch log ---
step "4/6 Verify commit recorded"
LOG_OUTPUT=$("$FP" --work-dir "$WORK_DIR" branch log "$BRANCH_NAME" -n 5 2>&1 || true)
echo "$LOG_OUTPUT"
if echo "$LOG_OUTPUT" | grep -qi "sftp.*edit.*style\.css\|sftp.*style\.css"; then
    pass
elif echo "$LOG_OUTPUT" | grep -qi "sftp"; then
    pass
else
    fail "branch log does not contain expected sftp commit (got: $(echo "$LOG_OUTPUT" | head -5))"
fi

# --- step 5: rollback branch ---
step "5/6 Rollback branch"
if "$FP" --work-dir "$WORK_DIR" branch rollback "$BRANCH_NAME"; then
    pass
else
    fail "branch rollback returned non-zero"
fi

# --- step 6: verify marker gone after rollback ---
step "6/6 Verify edit is gone after rollback"
sleep 1
AFTER_CSS=$(curl -s -H "Host: $BRANCH_NAME.localhost" "http://127.0.0.1:$HTTP_PORT/$THEME_CSS" 2>/dev/null || true)
if echo "$AFTER_CSS" | grep -qF "$MARKER"; then
    fail "marker still present after rollback"
else
    pass
fi

# --- summary ---
echo
echo "RESULTS: $PASS passed, $FAIL failed"
[ $FAIL -eq 0 ] && echo "ALL TESTS PASSED" && exit 0 || exit 1

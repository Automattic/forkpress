#!/usr/bin/env bash
# ForkPress E2E Test Suite v2 — exercises the full forkpress binary stack.
#
# Starts a fresh forkpress instance on non-default ports, runs all v2 test
# scripts against it, then tears down.
#
# Usage:
#   bash e2e/run_e2e_v2.sh            # run all tests
#   bash e2e/run_e2e_v2.sh --keep     # leave forkpress running on exit
#   FORKPRESS=/path/to/forkpress bash e2e/run_e2e_v2.sh
#
# Requirements:
#   - forkpress binary (build: cd branched-wp && cargo build --release)
#   - branchfs PHP extension compiled (build: cd branched-wp && make)
#   - php, curl, git on PATH

set -euo pipefail

E2E_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_DIR="$(cd "$E2E_DIR/.." && pwd)"

HTTP_PORT=19080
SFTP_PORT=19222
SMB_PORT=19888
MYSQL_PORT=19336
HOST=127.0.0.1
SITE_TITLE="ForkPress E2E"
KEEP=0

for arg in "$@"; do [[ "$arg" == "--keep" ]] && KEEP=1; done

# ── Find forkpress binary ──────────────────────────────────────────────────────
find_forkpress() {
    [[ -n "${FORKPRESS:-}" ]] && [[ -x "$FORKPRESS" ]] && echo "$FORKPRESS" && return
    local c
    for c in \
        "$BASE_DIR/target/release/forkpress" \
        "$BASE_DIR/forkpress/target/release/forkpress" \
        "$(command -v forkpress 2>/dev/null || true)"
    do
        [[ -x "$c" ]] && echo "$c" && return
    done
    return 1
}

FP="$(find_forkpress 2>/dev/null)" || {
    echo "SKIP: forkpress binary not found."
    echo "  Build it: cd branched-wp && cargo build --release"
    exit 0
}
echo "forkpress: $FP"

# ── Kill any leftover processes on our ports ──────────────────────────────────
for port in $HTTP_PORT $SFTP_PORT $SMB_PORT $MYSQL_PORT; do
    command -v fuser >/dev/null 2>&1 && fuser -k "$port/tcp" 2>/dev/null || true
done
pkill -f "forkpress.*$HTTP_PORT" 2>/dev/null || true
sleep 1

# ── Start forkpress ────────────────────────────────────────────────────────────
WORK_DIR=$(mktemp -d)
FP_PID=""

cleanup() {
    [[ -n "$FP_PID" ]] && kill "$FP_PID" 2>/dev/null; wait "$FP_PID" 2>/dev/null || true
    [[ "$KEEP" == "0" ]] && rm -rf "$WORK_DIR" || echo "Work dir preserved: $WORK_DIR"
}
trap cleanup EXIT

echo "Work dir: $WORK_DIR"
echo "Starting forkpress (HTTP=$HTTP_PORT SFTP=$SFTP_PORT SMB=$SMB_PORT MySQL=$MYSQL_PORT)..."

"$FP" start \
    --work-dir "$WORK_DIR" \
    --host "$HOST" \
    --port "$HTTP_PORT" \
    --sftp-port "$SFTP_PORT" \
    --smb-port "$SMB_PORT" \
    --mysql-port "$MYSQL_PORT" \
    --site-title "$SITE_TITLE" \
    >"$WORK_DIR/forkpress.log" 2>&1 &
FP_PID=$!

echo "Waiting for HTTP readiness (up to 180s — first run bootstraps WordPress)..."
DEADLINE=$(( $(date +%s) + 180 ))
while [[ "$(date +%s)" -lt "$DEADLINE" ]]; do
    CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://$HOST:$HTTP_PORT/" 2>/dev/null || true)
    if [[ "$CODE" == "200" ]] || [[ "$CODE" == "301" ]]; then
        echo "HTTP ready (status $CODE)"
        break
    fi
    if ! kill -0 "$FP_PID" 2>/dev/null; then
        echo "FATAL: forkpress exited during bootstrap"
        tail -30 "$WORK_DIR/forkpress.log"
        exit 1
    fi
    sleep 3
done
if [[ "$(date +%s)" -ge "$DEADLINE" ]]; then
    echo "FATAL: timed out waiting for HTTP"
    tail -30 "$WORK_DIR/forkpress.log"
    exit 1
fi

# ── Export context for sub-tests ──────────────────────────────────────────────
export FP FP_RUNNING=1
export FP_HOST=$HOST FP_HTTP_PORT=$HTTP_PORT FP_SFTP_PORT=$SFTP_PORT
export FP_SMB_PORT=$SMB_PORT FP_MYSQL_PORT=$MYSQL_PORT
export FP_WORK_DIR=$WORK_DIR FP_SITE_TITLE="$SITE_TITLE"

# ── Run test suites ────────────────────────────────────────────────────────────
SUITES=(
    test_http_multidomain.sh
    test_branch_lifecycle.sh
    test_git_protocol_v2.sh
    test_mysql_proxy.sh
    test_sftp_workflow.sh
)

TOTAL_PASS=0
TOTAL_FAIL=0
TOTAL_SKIP=0

echo ""
echo "════════════════════════════════════════"
echo " Running ${#SUITES[@]} test suites"
echo "════════════════════════════════════════"

for suite in "${SUITES[@]}"; do
    script="$E2E_DIR/$suite"
    echo ""
    echo "━━━ $suite ━━━"
    if [[ ! -f "$script" ]]; then
        echo "  SKIP: script not found"
        TOTAL_SKIP=$((TOTAL_SKIP+1))
        continue
    fi
    rc=0
    bash "$script" || rc=$?
    if [[ $rc -eq 0 ]]; then
        TOTAL_PASS=$((TOTAL_PASS+1))
    elif [[ $rc -eq 77 ]]; then
        echo "  (skipped)"
        TOTAL_SKIP=$((TOTAL_SKIP+1))
    else
        TOTAL_FAIL=$((TOTAL_FAIL+1))
    fi
done

echo ""
echo "════════════════════════════════════════"
echo " SUITE RESULTS: $TOTAL_PASS passed, $TOTAL_FAIL failed, $TOTAL_SKIP skipped"
echo "════════════════════════════════════════"
echo " Logs: $WORK_DIR/forkpress.log"
[[ $TOTAL_FAIL -eq 0 ]] && echo " ALL SUITES PASSED" && exit 0 || exit 1

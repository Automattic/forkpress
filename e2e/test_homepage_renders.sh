#!/bin/bash
# =============================================================================
# Homepage-renders-with-content regression test.
#
# Locks down the symptom of a class of bugs we've now fixed twice:
# blank or near-empty `<div class="wp-site-blocks"></div>` because
# WordPress couldn't discover theme patterns / core block CSS through
# the branchfs filesystem.
#
# This test must always pass — it's the canary for the "blank site" bug.
#
# Asserts (against http://127.0.0.1:18080/ on the dev stack):
#  1. HTTP 200
#  2. Body is at least 30KB (a fully-rendered TT4 home is ~70-90KB; a
#     bare empty-blocks shell is ~7KB)
#  3. <title> contains "Branched WP Dev"
#  4. <header> element is present (template part rendered)
#  5. <main> element is present (template part rendered)
#  6. <div class="wp-site-blocks"> contains > 5KB of inner content
#  7. wp-block-navigation-css link is emitted (block CSS discovered)
#  8. "Hello world" post is rendered (queries hit Dolt)
#  9. Same checks pass for a SECOND named branch via subdomain routing
# 10. wp-debug.log has zero "Could not register file" pattern errors
#     since the test started.
#
# Usage: bash e2e/test_homepage_renders.sh
# Exit code: 0 on full pass, non-zero on any failure.
# =============================================================================
set -uo pipefail

PHP_PORT="${PHP_PORT:-18080}"
DEBUG_LOG="${DEBUG_LOG:-/tmp/branchfs-dev/wp-debug.log}"
PASS=0
FAIL=0

pass() { echo "  ✓ PASS: $*"; PASS=$((PASS + 1)); }
fail() { echo "  ✗ FAIL: $*"; FAIL=$((FAIL + 1)); }

if ! curl -s -o /dev/null --max-time 5 "http://127.0.0.1:$PHP_PORT/"; then
    echo "FATAL: dev stack not reachable on :$PHP_PORT (start with: bash e2e/dev.sh)"
    exit 1
fi

# Mark a starting point in wp-debug.log for the pattern-error check.
DEBUG_BEFORE=$(wc -l < "$DEBUG_LOG" 2>/dev/null || echo 0)

check_branch() {
    local host="$1"
    local label="$2"
    local out="/tmp/test_homepage_$$_${label}.html"
    echo "=== $label (Host: $host) ==="
    local hdrs out_status
    hdrs=$(curl -s -m 60 -H "Host: $host" "http://127.0.0.1:$PHP_PORT/" -o "$out" -w "%{http_code} %{size_download}")
    out_status=$(echo "$hdrs" | awk '{print $1}')
    local size
    size=$(echo "$hdrs" | awk '{print $2}')

    [ "$out_status" = "200" ] && pass "[$label] HTTP 200" || fail "[$label] HTTP $out_status"

    if [ "$size" -ge 30000 ]; then
        pass "[$label] body size ${size} bytes (>=30KB)"
    else
        fail "[$label] body size ${size} bytes (expected >=30KB; bare-blocks shell would be <10KB)"
    fi

    grep -q "<title>Branched WP Dev" "$out" \
        && pass "[$label] <title> contains 'Branched WP Dev'" \
        || fail "[$label] <title> missing or wrong"

    grep -q "<header" "$out" && pass "[$label] <header> rendered" || fail "[$label] no <header>"
    grep -q "<main"   "$out" && pass "[$label] <main> rendered"   || fail "[$label] no <main>"

    # Measure inner content of wp-site-blocks.
    local inner
    inner=$(php -r '
        $h = file_get_contents($argv[1]);
        if (preg_match("~<div class=\"wp-site-blocks\">(.*?)<script id=~s", $h, $m)) {
            echo strlen(trim($m[1]));
        } else {
            echo "0";
        }
    ' "$out")
    if [ "$inner" -ge 5000 ]; then
        pass "[$label] <div class=\"wp-site-blocks\"> inner content ${inner} bytes (>=5000)"
    else
        fail "[$label] <div class=\"wp-site-blocks\"> inner only ${inner} bytes (likely empty)"
    fi

    grep -q "wp-block-navigation-css" "$out" \
        && pass "[$label] wp-block-navigation-css present (block assets discovered)" \
        || fail "[$label] wp-block-navigation-css missing — block discovery broken"

    grep -q "Hello world" "$out" \
        && pass "[$label] 'Hello world' post rendered" \
        || fail "[$label] 'Hello world' post missing"

    rm -f "$out"
}

check_branch "wp.localhost"  "main"

# Create a transient feature branch and check it on its subdomain too.
# Resolve bin/branchctl relative to this script's location so the test
# works regardless of where the repo is checked out (CI runners, Docker
# bind mounts, local sandboxes — all have different absolute paths).
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BRANCHCTL="$SCRIPT_DIR/../bin/branchctl"
if [ ! -x "$BRANCHCTL" ]; then
    echo "FATAL: $BRANCHCTL not found/executable"
    exit 1
fi

"$BRANCHCTL" delete homepage-test > /dev/null 2>&1 || true
if ! "$BRANCHCTL" create homepage-test --from main > /tmp/branchctl-create-$$.log 2>&1; then
    echo "FATAL: branchctl create homepage-test failed:"
    cat /tmp/branchctl-create-$$.log
    rm -f /tmp/branchctl-create-$$.log
    exit 1
fi
rm -f /tmp/branchctl-create-$$.log
check_branch "homepage-test.wp.localhost" "homepage-test"
"$BRANCHCTL" delete homepage-test > /dev/null 2>&1 || true

echo
echo "=== wp-debug.log: pattern-registration errors since start ==="
DEBUG_AFTER=$(wc -l < "$DEBUG_LOG" 2>/dev/null || echo 0)
NEW_LINES=$((DEBUG_AFTER - DEBUG_BEFORE))
PATTERN_ERRS=$(tail -n "$NEW_LINES" "$DEBUG_LOG" 2>/dev/null | grep -c "Could not register file" || true)
PATTERN_ERRS=${PATTERN_ERRS:-0}
if [ "$PATTERN_ERRS" -eq 0 ]; then
    pass "no 'Could not register file' pattern errors"
else
    fail "$PATTERN_ERRS 'Could not register file' pattern errors in wp-debug.log"
fi

echo
echo "============================================================"
echo "  RESULTS: $PASS passed, $FAIL failed"
echo "============================================================"
[ "$FAIL" -eq 0 ] && { echo "ALL TESTS PASSED"; exit 0; }
echo "FAILED — homepage is rendering empty / partial content."
exit 1

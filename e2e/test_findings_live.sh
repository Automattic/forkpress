#!/bin/bash
# =============================================================================
# Live-stack tests for findings that must be observed against a running
# dev stack (dev.sh must be up). Covers verifier acceptance items:
#
#  E. Push-rejection with invalid NDJSON (finding #3)
#  F. Two parallel clones (finding #2)
#  G. Push-auth custom creds (finding #4)
#  J. Backward reset -> edit -> commit creates a new fs_commit (finding #10)
#  K. branchctl create <reserved> rejected (finding #11)
#  L. git push to reserved name rejected (finding #11)
#  M. Re-create existing branch exits non-zero one-line (finding #12)
# =============================================================================
set -uo pipefail

E2E_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_DIR="$(cd "$E2E_DIR/.." && pwd)"
BRANCHCTL="$BASE_DIR/bin/branchctl"
PHP_PORT="${PHP_PORT:-18080}"
DOLT_PORT="${DOLT_PORT:-13306}"
PASS=0
FAIL=0

pass() { echo "  ✓ PASS: $*"; PASS=$((PASS + 1)); }
fail() { echo "  ✗ FAIL: $*"; FAIL=$((FAIL + 1)); }

# Make sure dev stack is responsive
if ! curl -s -o /dev/null "http://127.0.0.1:$PHP_PORT/"; then
    echo "FATAL: dev stack not reachable on :$PHP_PORT (start with bash e2e/dev.sh)"
    exit 1
fi

WORK=/tmp/branchfs-findings-$$
mkdir -p "$WORK"

cleanup() {
    rm -rf "$WORK"
    "$BRANCHCTL" delete e-invalid-ndjson > /dev/null 2>&1 || true
    "$BRANCHCTL" delete auth-test        > /dev/null 2>&1 || true
    "$BRANCHCTL" delete admin            > /dev/null 2>&1 || true
    "$BRANCHCTL" delete www              > /dev/null 2>&1 || true
    "$BRANCHCTL" delete reset-test       > /dev/null 2>&1 || true
    "$BRANCHCTL" delete dup-branch       > /dev/null 2>&1 || true
}
trap cleanup EXIT

# ==============================================================================
# F. Two parallel clones succeed (finding #2)
# ==============================================================================
echo ""
echo "=== F. Two parallel git clones must both succeed ==="
git clone "http://127.0.0.1:$PHP_PORT/site.git" "$WORK/cloneA" > "$WORK/cloneA.log" 2>&1 &
PID_A=$!
git clone "http://127.0.0.1:$PHP_PORT/site.git" "$WORK/cloneB" > "$WORK/cloneB.log" 2>&1 &
PID_B=$!
wait $PID_A; RC_A=$?
wait $PID_B; RC_B=$?
if [ $RC_A -eq 0 ] && [ $RC_B -eq 0 ]; then
    pass "two parallel clones both exit 0"
else
    fail "parallel clones failed (A=$RC_A, B=$RC_B)"
    tail -5 "$WORK/cloneA.log" "$WORK/cloneB.log"
fi

# Verify clones produced identical content at the wordpress/ path
if [ -d "$WORK/cloneA/wordpress" ] && [ -d "$WORK/cloneB/wordpress" ]; then
    DIFF_COUNT=$(diff -r "$WORK/cloneA/wordpress" "$WORK/cloneB/wordpress" 2>&1 | wc -l)
    if [ "$DIFF_COUNT" = "0" ]; then
        pass "parallel clones produce identical wordpress trees"
    else
        fail "parallel clones differ ($DIFF_COUNT diff lines)"
    fi
fi

# ==============================================================================
# E. Push with invalid NDJSON -> server rejects, ref unchanged, no new commit
# ==============================================================================
echo ""
echo "=== E. Push with invalid NDJSON is rejected; server state unchanged ==="

PRE_REFS=$(git ls-remote "http://127.0.0.1:$PHP_PORT/site.git" | sort)
PRE_LOG_TOP=$("$BRANCHCTL" log main -n 1 2>/dev/null | grep -A1 'COMMIT' | tail -1 | awk '{print $2}')

# Corrupt the pushed SQLite file and push — the importer must reject
# it and leave the server refs + Dolt log unchanged. (Migrated from the
# NDJSON layout's "corrupt wp_options.ndjson" test: the server now owns
# a single SQLite file, so the broken variant is truncated bytes.)
SQLITE_PATH="$WORK/cloneA/wordpress/wp-content/database/.ht.sqlite"
if [ -f "$SQLITE_PATH" ]; then
    echo "not a sqlite file" > "$SQLITE_PATH"
    git -C "$WORK/cloneA" add -A
    git -C "$WORK/cloneA" -c user.email=t@t.co -c user.name=t commit -m "intentionally invalid sqlite" > /dev/null 2>&1
    PUSH_OUT=$(git -C "$WORK/cloneA" push "http://admin:admin@127.0.0.1:$PHP_PORT/site.git" main 2>&1)
    PUSH_RC=$?
    if [ $PUSH_RC -ne 0 ]; then
        pass "git client sees rejection (push exited $PUSH_RC)"
    else
        fail "push with invalid sqlite succeeded: $PUSH_OUT"
    fi

    POST_REFS=$(git ls-remote "http://127.0.0.1:$PHP_PORT/site.git" | sort)
    if [ "$PRE_REFS" = "$POST_REFS" ]; then
        pass "git ls-remote shows refs unchanged after rejected push"
    else
        fail "refs changed after rejected push"
        diff <(echo "$PRE_REFS") <(echo "$POST_REFS") | head -5
    fi

    POST_LOG_TOP=$("$BRANCHCTL" log main -n 1 2>/dev/null | grep -A1 'COMMIT' | tail -1 | awk '{print $2}')
    if [ "$PRE_LOG_TOP" = "$POST_LOG_TOP" ]; then
        pass "branchctl log shows no new commit after rejected push"
    else
        fail "branchctl log top changed: $PRE_LOG_TOP -> $POST_LOG_TOP"
    fi
else
    fail "cloneA sqlite file not available for invalid-sqlite push test"
fi

# ==============================================================================
# G. Push-auth custom creds
# ==============================================================================
echo ""
echo "=== G. Push auth respects BRANCHFS_GIT_USER / BRANCHFS_GIT_PASSWORD_HASH ==="

# The dev stack we inherit started without these env vars set — we can only
# observe this against the LIVE server by testing the 401 path. For full
# coverage the unit test (tests/test_push_auth.php) exercises all branches
# of git_check_auth() in-process; here we just confirm the server's 401 UX.

HTTP_401=$(curl -s -o /dev/null -w "%{http_code}" -X POST \
    "http://wrong:wrong@127.0.0.1:$PHP_PORT/site.git/git-receive-pack" \
    -H "Content-Type: application/x-git-receive-pack-request" \
    --data-binary "0000" 2>/dev/null || true)

if [ "$HTTP_401" = "401" ]; then
    pass "wrong creds return 401 on /git-receive-pack"
else
    fail "wrong creds returned $HTTP_401 (expected 401)"
fi

# 200 on correct dev default creds
HTTP_OK=$(curl -s -o /dev/null -w "%{http_code}" -X POST \
    "http://admin:admin@127.0.0.1:$PHP_PORT/site.git/git-receive-pack" \
    -H "Content-Type: application/x-git-receive-pack-request" \
    --data-binary "0000" 2>/dev/null || true)

if [ "$HTTP_OK" = "200" ] || [ "$HTTP_OK" = "500" ]; then
    # 200 = accepted, 500 = accepted but empty body rejected. Both prove
    # auth passed (anything other than 401 is acceptable here).
    pass "admin/admin not rejected with 401 (got $HTTP_OK)"
else
    fail "admin/admin returned unexpected $HTTP_OK"
fi

# ==============================================================================
# J. Reset-then-modify-then-commit creates a new fs_commit (finding #10)
# ==============================================================================
echo ""
echo "=== J. Backward reset + edit + commit records a new fs_commit ==="

"$BRANCHCTL" create reset-test > /dev/null 2>&1 || true
# Make an initial change + commit so we have two fs_commits to jump between.
php -d extension="$BASE_DIR/ext/branchfs.so" -r '
    branchfs_set_db("/tmp/branchfs-dev/branchfs.db");
    file_put_contents("branchfs://reset-test/step1.txt", "first\n");
' 2>/dev/null
"$BRANCHCTL" commit reset-test -m "first edit" > /dev/null 2>&1

php -d extension="$BASE_DIR/ext/branchfs.so" -r '
    branchfs_set_db("/tmp/branchfs-dev/branchfs.db");
    file_put_contents("branchfs://reset-test/step2.txt", "second\n");
' 2>/dev/null
"$BRANCHCTL" commit reset-test -m "second edit" > /dev/null 2>&1

# Now reset back to first commit (HEAD~1).
"$BRANCHCTL" reset reset-test HEAD~1 --force > /dev/null 2>&1

# Count fs_commits on reset-test.
COUNT_BEFORE=$(php -r '
    $d = new SQLite3("/tmp/branchfs-dev/branchfs.db");
    $bid = (int)$d->querySingle("SELECT id FROM branches WHERE name = \"reset-test\"");
    echo (int)$d->querySingle("SELECT COUNT(*) FROM fs_commits WHERE branch_id = $bid");
')

# Modify file AFTER reset; commit.
php -d extension="$BASE_DIR/ext/branchfs.so" -r '
    branchfs_set_db("/tmp/branchfs-dev/branchfs.db");
    file_put_contents("branchfs://reset-test/after-reset.txt", "post-reset edit\n");
' 2>/dev/null
OUT=$("$BRANCHCTL" commit reset-test -m "post-reset commit" 2>&1)

COUNT_AFTER=$(php -r '
    $d = new SQLite3("/tmp/branchfs-dev/branchfs.db");
    $bid = (int)$d->querySingle("SELECT id FROM branches WHERE name = \"reset-test\"");
    echo (int)$d->querySingle("SELECT COUNT(*) FROM fs_commits WHERE branch_id = $bid");
')

if [ "$COUNT_AFTER" -gt "$COUNT_BEFORE" ]; then
    pass "post-reset commit recorded a new fs_commit ($COUNT_BEFORE -> $COUNT_AFTER)"
else
    fail "post-reset commit did NOT create a new fs_commit ($COUNT_BEFORE -> $COUNT_AFTER)"
    echo "$OUT"
fi

# ==============================================================================
# K. branchctl create <reserved> rejected
# ==============================================================================
echo ""
echo "=== K. branchctl create <reserved-name> rejected ==="

for name in www admin api mail localhost wp; do
    OUT=$("$BRANCHCTL" create "$name" 2>&1)
    RC=$?
    if [ $RC -ne 0 ] && echo "$OUT" | grep -qi 'reserved'; then
        pass "branchctl create $name rejected with reserved-name error"
    else
        fail "branchctl create $name rc=$RC out='$OUT'"
    fi
done

# ==============================================================================
# L. git push to reserved name rejected
# ==============================================================================
echo ""
echo "=== L. git push to reserved branch name rejected ==="

git -C "$WORK/cloneB" -c user.email=t@t.co -c user.name=t \
    commit --allow-empty -m "trigger reserved-name push" > /dev/null 2>&1
OUT=$(git -C "$WORK/cloneB" push "http://admin:admin@127.0.0.1:$PHP_PORT/site.git" main:admin 2>&1)
RC=$?
if [ $RC -ne 0 ]; then
    pass "git push main:admin rejected (rc=$RC)"
else
    fail "git push main:admin succeeded unexpectedly: $OUT"
fi

# Verify 'admin' doesn't exist as a branch
if ! "$BRANCHCTL" list 2>&1 | grep -qE '^admin '; then
    pass "no 'admin' branch exists after rejected push"
else
    fail "'admin' branch exists after rejected push"
fi

# ==============================================================================
# M. Re-creating an existing branch via branchctl create exits non-zero one-line
# ==============================================================================
echo ""
echo "=== M. branchctl create on existing branch -> one-line error, no stack trace ==="

"$BRANCHCTL" create dup-branch > /dev/null 2>&1
OUT=$("$BRANCHCTL" create dup-branch 2>&1)
RC=$?
if [ $RC -ne 0 ]; then
    LINES=$(echo "$OUT" | wc -l)
    if echo "$OUT" | grep -qi 'stack trace\|stacktrace'; then
        fail "output contains a stack trace (should be one-line message)"
        echo "$OUT"
    elif [ "$LINES" -le 3 ]; then
        pass "duplicate create exits $RC with ≤3 lines: $OUT"
    else
        fail "duplicate create output too long ($LINES lines)"
        echo "$OUT"
    fi
else
    fail "duplicate create exited 0 (should be non-zero)"
fi

# ==============================================================================
# Summary
# ==============================================================================
echo ""
echo "============================================================"
echo "  RESULTS: $PASS passed, $FAIL failed"
echo "============================================================"
if [ $FAIL -gt 0 ]; then
    exit 1
fi
exit 0

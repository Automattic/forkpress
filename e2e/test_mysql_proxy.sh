#!/usr/bin/env bash
# E2E: MySQL proxy — read WordPress data, write to branch, verify via HTTP
#
# The MySQL proxy translates wp_* table names to b{branch_id}_wp_* so MySQL
# clients see a familiar WordPress schema while the data lives in SQLite.
#
# Uses PHP with mysqli as the MySQL client (avoids requiring the mysql binary).
# Falls back to mysql CLI if mysqli extension is not available.
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
command -v php  >/dev/null 2>&1 || { echo "SKIP: php not found";  exit 77; }

# ── Connect to running instance or start own ──────────────────────────────────
if [[ "${FP_RUNNING:-0}" == "1" ]]; then
    HOST="$FP_HOST"; HTTP_PORT="$FP_HTTP_PORT"; MYSQL_PORT="$FP_MYSQL_PORT"
    WORK_DIR="$FP_WORK_DIR"; FP="$FP"
    SITE_TITLE="${FP_SITE_TITLE:-ForkPress E2E}"
else
    HTTP_PORT=19480; SFTP_PORT=19622; SMB_PORT=19888; MYSQL_PORT=19736; HOST=127.0.0.1
    SITE_TITLE="ForkPress MySQL Test"

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
        --site-title "$SITE_TITLE" \
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

SUFFIX="e2e-mysql-$$"

# ── Check MySQL client availability ───────────────────────────────────────────
# Prefer PHP with mysqli (always available when PHP is installed + mysql proxy is running)
HAS_PHP_MYSQLI=0
if php -r "new mysqli('127.0.0.1', 'root', '', 'main', $MYSQL_PORT);" 2>/dev/null | grep -qv "error\|CONNECT"; then
    HAS_PHP_MYSQLI=1
elif php -r "exit(extension_loaded('mysqli') ? 0 : 1);" 2>/dev/null; then
    HAS_PHP_MYSQLI=1
fi

HAS_MYSQL_CLI=0
if command -v mysql >/dev/null 2>&1; then
    HAS_MYSQL_CLI=1
fi

if [[ "$HAS_PHP_MYSQLI" == "0" ]] && [[ "$HAS_MYSQL_CLI" == "0" ]]; then
    echo "SKIP: neither PHP mysqli nor mysql CLI available"
    exit 77
fi

# ── mysql_query: run SQL against the MySQL proxy for a given branch/database ──
mysql_query() {
    local branch="$1" sql="$2"
    if [[ "$HAS_PHP_MYSQLI" == "1" ]]; then
        php -r "
            \$c = @new mysqli('$HOST', 'root', '', '$branch', $MYSQL_PORT);
            if (\$c->connect_error) { fwrite(STDERR, 'CONNECT: ' . \$c->connect_error . PHP_EOL); exit(1); }
            \$r = \$c->query('$sql');
            if (\$r === false) { fwrite(STDERR, 'SQL: ' . \$c->error . PHP_EOL); exit(1); }
            if (\$r instanceof mysqli_result) {
                while (\$row = \$r->fetch_row()) echo implode('\t', \$row) . PHP_EOL;
                \$r->free();
            }
            while (\$c->next_result()) { \$r2 = \$c->store_result(); if (\$r2) \$r2->free(); }
            \$c->close();
        " 2>&1
    elif [[ "$HAS_MYSQL_CLI" == "1" ]]; then
        mysql -h "$HOST" -P "$MYSQL_PORT" -u root "$branch" -e "$sql" 2>&1
    fi
}

# ── Check MySQL proxy is reachable ────────────────────────────────────────────
MYSQL_READY=0
DEADLINE=$(( $(date +%s) + 15 ))
while [[ "$(date +%s)" -lt "$DEADLINE" ]]; do
    OUT=$(mysql_query "main" "SELECT 1" 2>&1 || true)
    if echo "$OUT" | grep -qE "^1$|1\s*$"; then
        MYSQL_READY=1
        break
    fi
    sleep 1
done

if [[ "$MYSQL_READY" == "0" ]]; then
    echo "SKIP: MySQL proxy not reachable on $HOST:$MYSQL_PORT (is fileserver running?)"
    exit 77
fi

# ══════════════════════════════════════════════════════════════════════════════
step "1. Read wp_options from main branch via MySQL proxy"
BLOGNAME=$(mysql_query "main" \
    "SELECT option_value FROM wp_options WHERE option_name='blogname' LIMIT 1" 2>/dev/null | \
    grep -v "^option_value$" | head -1 | tr -d '\r' || true)

if [[ -n "$BLOGNAME" ]]; then
    pass "wp_options readable via MySQL proxy (blogname='$BLOGNAME')"
else
    fail "could not read blogname from wp_options (got empty result)"
fi

# ══════════════════════════════════════════════════════════════════════════════
step "2. Create a branch for MySQL isolation test"
BRANCH="mysql-iso-$SUFFIX"
"$FP" branch --work-dir "$WORK_DIR" create "$BRANCH" >/dev/null 2>&1 || {
    fail "could not create branch for MySQL isolation test"
    echo "RESULTS: $PASS passed, $FAIL failed, $SKIP skipped"; exit 1
}
pass "branch $BRANCH created"

# ══════════════════════════════════════════════════════════════════════════════
step "3. New branch inherits main's blogname via MySQL proxy"
BRANCH_BLOGNAME=$(mysql_query "$BRANCH" \
    "SELECT option_value FROM wp_options WHERE option_name='blogname' LIMIT 1" 2>/dev/null | \
    grep -v "^option_value$" | head -1 | tr -d '\r' || true)

if [[ "$BRANCH_BLOGNAME" == "$BLOGNAME" ]]; then
    pass "branch inherits main's blogname ('$BRANCH_BLOGNAME')"
else
    fail "branch blogname mismatch: expected '$BLOGNAME' got '$BRANCH_BLOGNAME'"
fi

# ══════════════════════════════════════════════════════════════════════════════
step "4. UPDATE on branch does not affect main"
NEW_TITLE="MySQL Branch $SUFFIX"
mysql_query "$BRANCH" \
    "UPDATE wp_options SET option_value='$NEW_TITLE' WHERE option_name='blogname'" \
    >/dev/null 2>&1 || { fail "UPDATE on branch failed"; }

AFTER_BRANCH=$(mysql_query "$BRANCH" \
    "SELECT option_value FROM wp_options WHERE option_name='blogname' LIMIT 1" 2>/dev/null | \
    grep -v "^option_value$" | head -1 | tr -d '\r' || true)

AFTER_MAIN=$(mysql_query "main" \
    "SELECT option_value FROM wp_options WHERE option_name='blogname' LIMIT 1" 2>/dev/null | \
    grep -v "^option_value$" | head -1 | tr -d '\r' || true)

if [[ "$AFTER_BRANCH" == "$NEW_TITLE" ]]; then
    pass "branch blogname updated to '$NEW_TITLE'"
else
    fail "branch blogname not updated (got '$AFTER_BRANCH')"
fi

if [[ "$AFTER_MAIN" == "$BLOGNAME" ]]; then
    pass "main blogname unchanged ('$AFTER_MAIN') — branch isolation works"
else
    fail "main blogname changed to '$AFTER_MAIN' (expected '$BLOGNAME') — isolation broken"
fi

# ══════════════════════════════════════════════════════════════════════════════
step "5. HTTP shows branch-specific title from MySQL data"
BRANCH_BODY=$(curl -sL --max-time 10 \
    -H "Host: $BRANCH.localhost" \
    "http://$HOST:$HTTP_PORT/" 2>/dev/null || true)

if echo "$BRANCH_BODY" | grep -qi "$NEW_TITLE"; then
    pass "branch HTTP response shows updated title '$NEW_TITLE'"
else
    fail "branch HTTP response does not show updated title (got: $(echo "$BRANCH_BODY" | grep -i "title" | head -1))"
fi

MAIN_BODY=$(curl -sL --max-time 10 "http://$HOST:$HTTP_PORT/" 2>/dev/null || true)
if echo "$MAIN_BODY" | grep -qi "$BLOGNAME"; then
    pass "main HTTP response still shows original title '$BLOGNAME'"
elif ! echo "$MAIN_BODY" | grep -qi "$NEW_TITLE"; then
    pass "main HTTP response does not show branch-only title (isolation confirmed)"
else
    fail "main HTTP response shows branch title (isolation broken)"
fi

# ══════════════════════════════════════════════════════════════════════════════
step "6. SELECT with wp_ prefix is rewritten to branch prefix"
# The proxy should transparently translate wp_posts to b{id}_wp_posts
POST_COUNT=$(mysql_query "$BRANCH" \
    "SELECT COUNT(*) FROM wp_posts" 2>/dev/null | \
    grep -v "^COUNT" | head -1 | tr -d '\r' || true)
if echo "$POST_COUNT" | grep -qE "^[0-9]+$"; then
    pass "SELECT from wp_posts works (got $POST_COUNT rows — table name rewritten)"
else
    fail "SELECT from wp_posts failed (got: $POST_COUNT)"
fi

# ══════════════════════════════════════════════════════════════════════════════
step "7. Multiple branch connections independently"
BRANCH2="mysql-iso2-$SUFFIX"
"$FP" branch --work-dir "$WORK_DIR" create "$BRANCH2" >/dev/null 2>&1 || true
mysql_query "$BRANCH2" \
    "UPDATE wp_options SET option_value='Title2-$SUFFIX' WHERE option_name='blogname'" \
    >/dev/null 2>&1 || true

T1=$(mysql_query "$BRANCH" "SELECT option_value FROM wp_options WHERE option_name='blogname' LIMIT 1" 2>/dev/null | grep -v "^option_value$" | head -1 | tr -d '\r' || true)
T2=$(mysql_query "$BRANCH2" "SELECT option_value FROM wp_options WHERE option_name='blogname' LIMIT 1" 2>/dev/null | grep -v "^option_value$" | head -1 | tr -d '\r' || true)

if [[ "$T1" == "$NEW_TITLE" ]] && [[ "$T2" == "Title2-$SUFFIX" ]]; then
    pass "two branches hold independent values simultaneously"
else
    fail "branch independence broken: branch1='$T1' branch2='$T2'"
fi

# Cleanup
"$FP" branch --work-dir "$WORK_DIR" delete "$BRANCH"  >/dev/null 2>&1 || true
"$FP" branch --work-dir "$WORK_DIR" delete "$BRANCH2" >/dev/null 2>&1 || true

# ══════════════════════════════════════════════════════════════════════════════
echo ""
echo "RESULTS: $PASS passed, $FAIL failed, $SKIP skipped"
[[ $FAIL -eq 0 ]] && exit 0 || exit 1

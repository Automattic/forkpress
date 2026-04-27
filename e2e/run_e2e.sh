#!/bin/bash
# ============================================================
# BranchFS End-to-End Test
#
# Exercises the full HTTP path: branchfs extension + Dolt database
# + real WordPress, with branch creation, preview, merge, revert,
# discard, and parallel-branch isolation.
#
# Usage: bash e2e/run_e2e.sh
# ============================================================

set -euo pipefail

# ---- Configuration ----
E2E_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_DIR="$(cd "$E2E_DIR/.." && pwd)"
WP_SRC="${WP_SRC:-$E2E_DIR/wp-src}"
WORK_DIR="${WORK_DIR:-/tmp/branchfs-e2e-$$}"
DOLT_PORT="${DOLT_PORT:-13306}"
PHP_PORT="${PHP_PORT:-18080}"
BRANCHFS_SECRET="${BRANCHFS_SECRET:-e2e-test-secret}"
SITE_TITLE="${SITE_TITLE:-Branched WP}"

PHP_BIN="${PHP_BIN:-php}"
PHP="$PHP_BIN -d extension=$BASE_DIR/ext/branchfs.so -d display_errors=Off -d display_startup_errors=Off"
DOLT="${DOLT:-$(command -v dolt || echo "$HOME/.local/bin/dolt")}"

DB_PATH="$WORK_DIR/branchfs.db"
WP_ROOT="$WORK_DIR/wproot"
DOLT_DATA="$WORK_DIR/dolt-data"
DOLT_LOG="$WORK_DIR/dolt-server.log"
PHP_LOG="$WORK_DIR/php-server.log"
DEBUG_LOG="$WORK_DIR/wp-debug.log"
ERR_LOG="$WORK_DIR/php-errors.log"

TOTAL_STEPS=8
DOLT_PID=""
PHP_PID=""

S1=0; S2=0; S3=0; S4=0; S5=0; S6=0; S7=0; S8=0

# ---- Helper functions ----

cleanup() {
    echo ""
    echo "[cleanup] stopping servers..."
    [ -n "$PHP_PID" ]  && kill "$PHP_PID"  2>/dev/null && wait "$PHP_PID"  2>/dev/null || true
    [ -n "$DOLT_PID" ] && kill "$DOLT_PID" 2>/dev/null && wait "$DOLT_PID" 2>/dev/null || true
}
trap cleanup EXIT

mint_cookie() {
    # Legacy shim kept for compatibility with earlier step numbering; the
    # router now routes by subdomain, so preview requests use a Host header
    # like `branch.wp.localhost` instead of a signed cookie.
    local branch="$1"
    echo "$branch.wp.localhost"
}

host_for() {
    echo "$1.wp.localhost"
}

# Run a single SQL statement against Dolt
dolt_sql() {
    $PHP_BIN -r '
        $c = new mysqli("127.0.0.1", "root", "", "wordpress", '"$DOLT_PORT"');
        if ($c->connect_error) { fwrite(STDERR, "CONNECT_ERROR: " . $c->connect_error . "\n"); exit(1); }
        $r = $c->query($argv[1]);
        if ($r === false) { fwrite(STDERR, "SQL_ERROR: " . $c->error . "\n"); exit(1); }
        if ($r instanceof mysqli_result) {
            while ($row = $r->fetch_assoc()) echo implode("\t", $row) . "\n";
            $r->free();
        }
        while ($c->next_result()) { $r2 = $c->store_result(); if ($r2 instanceof mysqli_result) $r2->free(); }
        $c->close();
    ' -- "$1"
}

# Run multiple SQL statements in a SINGLE Dolt session (preserves DOLT_CHECKOUT)
dolt_session() {
    $PHP_BIN -r '
        $c = new mysqli("127.0.0.1", "root", "", "wordpress", '"$DOLT_PORT"');
        if ($c->connect_error) { fwrite(STDERR, "CONNECT_ERROR: " . $c->connect_error . "\n"); exit(1); }
        foreach (array_slice($argv, 1) as $sql) {
            $r = $c->query($sql);
            if ($r === false) { fwrite(STDERR, "SQL_ERROR [$sql]: " . $c->error . "\n"); exit(1); }
            if ($r instanceof mysqli_result) {
                while ($row = $r->fetch_assoc()) echo implode("\t", $row) . "\n";
                $r->free();
            }
            while ($c->next_result()) { $r2 = $c->store_result(); if ($r2 instanceof mysqli_result) $r2->free(); }
        }
        $c->close();
    ' -- "$@"
}

bfs_php() {
    $PHP "$@"
}

fetch() {
    curl -sL --max-time 15 "$@" 2>/dev/null
}

# Fetch a URL into a scratch file under $WORK_DIR and echo the filename.
# Reading the body through a file avoids bash $VAR -> echo -> grep issues
# that bite when the response is >~16KB or contains awkward bytes.
fetch_to_file() {
    local name="$1"; shift
    local out="$WORK_DIR/fetch-$name.txt"
    curl -sL --max-time 15 -o "$out" "$@" 2>/dev/null
    echo "$out"
}

# ---- Kill old processes ----
echo "[setup] cleaning up old processes..."
# Kill any existing server on our ports regardless of bind address
# (dev.sh binds 0.0.0.0; earlier patterns matched only 127.0.0.1, which
# left dev.sh running and made run_e2e curl against the wrong stack).
pkill -f "dolt sql-server.*--port=$DOLT_PORT" 2>/dev/null || true
pkill -f "php.*:$PHP_PORT" 2>/dev/null || true
# Belt-and-suspenders: also kill anything holding the ports we need.
if command -v fuser >/dev/null 2>&1; then
    fuser -k -9 "$PHP_PORT/tcp" 2>/dev/null || true
    fuser -k -9 "$DOLT_PORT/tcp" 2>/dev/null || true
fi
# Wait for the ports to actually free.
for _i in $(seq 1 10); do
    if ! $PHP_BIN -r "\$s=@fsockopen('127.0.0.1',$PHP_PORT,\$e,\$m,0.2); if(\$s){fclose(\$s);exit(0);} exit(1);" 2>/dev/null; then
        break
    fi
    sleep 1
done

rm -rf "$WORK_DIR"
mkdir -p "$WORK_DIR" "$WP_ROOT" "$DOLT_DATA/wordpress"

# ============================================================
# BOOTSTRAP
# ============================================================

echo ""
echo "=== BOOTSTRAP ==="
echo ""

echo -n "[bootstrap] starting dolt sql-server on port $DOLT_PORT ... "
(cd "$DOLT_DATA/wordpress" && "$DOLT" init --name "e2e" --email "test@test.com" > /dev/null 2>&1)
(cd "$DOLT_DATA" && exec "$DOLT" sql-server --host=127.0.0.1 --port="$DOLT_PORT" \
    --data-dir="$DOLT_DATA" > "$DOLT_LOG" 2>&1) &
DOLT_PID=$!

for i in $(seq 1 30); do
    if $PHP_BIN -r "@\$c = new mysqli('127.0.0.1','root','','wordpress',$DOLT_PORT); if(\$c->connect_error) exit(1); \$c->close();" 2>/dev/null; then
        break
    fi
    [ "$i" -eq 30 ] && { echo "FAILED"; cat "$DOLT_LOG"; exit 1; }
    sleep 1
done
echo "ok (pid=$DOLT_PID)"

echo -n "[bootstrap] creating branchfs store ... "
bfs_php "$BASE_DIR/scripts/init_db.php" "$DB_PATH" > /dev/null 2>&1
echo "ok"

echo -n "[bootstrap] importing WP 6.5 to main ... "
IMPORT_OUT=$(bfs_php "$BASE_DIR/scripts/import_wp.php" "$WP_SRC" "$DB_PATH" main 2>/dev/null)
FILE_COUNT=$(echo "$IMPORT_OUT" | grep -oP '\d+ files' | head -1 || echo "? files")
echo "ok ($FILE_COUNT)"

echo "[bootstrap] installing WordPress ..."
bfs_php "$E2E_DIR/bootstrap_wp.php" \
    "$DB_PATH" "$WP_ROOT" "$DOLT_PORT" "$SITE_TITLE" \
    "$BASE_DIR/wp-plugin/branchfs-wp.php" "$DEBUG_LOG" 2>&1 \
    | grep -v 'Missing arginfo\|sendmail' | sed 's/^/  /'
echo "[bootstrap] WordPress installed (admin=admin site=$SITE_TITLE)"

echo -n "[bootstrap] starting php -S on port $PHP_PORT ... "
BRANCHFS_DB="$DB_PATH" \
BRANCHFS_WP_ROOT="$WP_ROOT" \
BRANCHFS_SECRET="$BRANCHFS_SECRET" \
$PHP \
    -d log_errors=On \
    -d "error_log=$ERR_LOG" \
    -S "127.0.0.1:$PHP_PORT" \
    -t "$WP_ROOT" \
    "$E2E_DIR/router.php" \
    > "$PHP_LOG" 2>&1 &
PHP_PID=$!

for i in $(seq 1 30); do
    if $PHP_BIN -r "\$s=@fsockopen('127.0.0.1',$PHP_PORT,\$e,\$m,0.5); if(\$s){fclose(\$s);exit(0);} exit(1);" 2>/dev/null; then
        break
    fi
    if ! kill -0 "$PHP_PID" 2>/dev/null; then
        echo "FAILED (php -S died)"; tail -20 "$PHP_LOG"; exit 1
    fi
    [ "$i" -eq 30 ] && { echo "FAILED (port never opened)"; tail -20 "$PHP_LOG"; exit 1; }
    sleep 1
done
echo "ok (pid=$PHP_PID)"

echo ""
echo "=== RUNNING TESTS ==="
echo ""

set +e

# ============================================================
# STEP 1: Main branch request
# ============================================================

F=$(fetch_to_file step1 "http://127.0.0.1:$PHP_PORT/")

if grep -qi "$SITE_TITLE" "$F" && grep -qi '<html' "$F"; then
    if grep -qi "Hello world" "$F"; then
        echo "STEP 1 PASS: main / returns 200 with '$SITE_TITLE' and 'Hello world!'"
    else
        echo "STEP 1 PASS: main / returns 200 with '$SITE_TITLE' (valid WordPress HTML)"
    fi
    S1=1
else
    echo "STEP 1 FAIL: main / did not return expected content"
    echo "  Body size: $(wc -c < "$F")"
    echo "  Title: $(grep -oiP '<title>[^<]+' "$F" | head -1)"
fi

# ============================================================
# STEP 2: Create preview branch (preview-a)
# ============================================================

STEP2_OK=true

# Use bin/branchctl create — atomically creates the branchfs overlay,
# the Dolt branch, AND the initial paired fs_commit. Raw
# branchfs_create_branch via a bfs_php oneliner races with the php -S
# process's SQLite snapshot in CI (works locally with warm state, 500s
# in a cold CI runner where the subsequent HTTP request doesn't see
# the just-inserted branch row yet).
BRANCHFS_DB="$DB_PATH" "$BASE_DIR/bin/branchctl" create preview-a --from main > /dev/null 2>&1 || STEP2_OK=false

dolt_session \
    "CALL DOLT_CHECKOUT('preview-a')" \
    "UPDATE wp_options SET option_value = 'Preview A Site' WHERE option_name = 'blogname'" \
    "CALL DOLT_COMMIT('-am', 'Change site title to Preview A Site')" \
    "CALL DOLT_CHECKOUT('main')" \
    > /dev/null 2>&1 || STEP2_OK=false

bfs_php -r "
    branchfs_set_db('$DB_PATH');
    \$css = file_get_contents('branchfs://main/wp-content/themes/twentytwentyfour/style.css');
    if (\$css === false) exit(1);
    file_put_contents('branchfs://preview-a/wp-content/themes/twentytwentyfour/style.css',
        \"/* preview-a marker */\n\" . \$css);
" 2>/dev/null || STEP2_OK=false

if $STEP2_OK; then
    echo "STEP 2 PASS: created branch preview-a in dolt and branchfs"
    S2=1
else
    echo "STEP 2 FAIL: failed to create or configure preview-a"
fi

# ============================================================
# STEP 3: Preview branch request
# ============================================================

HOST_A=$(host_for "preview-a")
S3_OK=true

F=$(fetch_to_file step3a -H "Host: $HOST_A" "http://127.0.0.1:$PHP_PORT/")
if grep -qi "Preview A Site" "$F"; then
    echo "STEP 3 PASS: preview-a / reflects modified title 'Preview A Site'"
else
    echo "STEP 3 FAIL: preview-a / did not show 'Preview A Site'"
    echo "  Title: $(grep -oiP '<title>[^<]+' "$F" | head -1)"
    S3_OK=false
fi

F=$(fetch_to_file step3css -H "Host: $HOST_A" "http://127.0.0.1:$PHP_PORT/wp-content/themes/twentytwentyfour/style.css")
if grep -q "preview-a marker" "$F"; then
    echo "STEP 3 PASS: preview-a stylesheet contains '/* preview-a marker */'"
else
    echo "STEP 3 FAIL: preview-a stylesheet missing marker"
    S3_OK=false
fi

F=$(fetch_to_file step3main "http://127.0.0.1:$PHP_PORT/")
if grep -qi "$SITE_TITLE" "$F" && ! grep -qi "Preview A Site" "$F"; then
    echo "STEP 3 PASS: main / unchanged (no cookie)"
else
    echo "STEP 3 FAIL: main / was contaminated or missing title"
    echo "  Title: $(grep -oiP '<title>[^<]+' "$F" | head -1)"
    S3_OK=false
fi

$S3_OK && S3=1

# ============================================================
# STEP 4: Merge preview-a into main
# ============================================================

PRE_MERGE_HASH=$(dolt_sql "SELECT HASHOF('HEAD') as h" 2>/dev/null | tr -d '[:space:]')

# WordPress may have modified wp_options (transients, cron) during prior requests.
# Dolt requires a clean working set before merge, so commit any pending changes.
bfs_php "$BASE_DIR/scripts/merge.php" preview-a main "$DB_PATH" > /dev/null 2>&1
dolt_session \
    "CALL DOLT_ADD('-A')" \
    "CALL DOLT_COMMIT('--allow-empty', '-am', 'Auto-commit before merge')" \
    "CALL DOLT_MERGE('preview-a')" > /dev/null 2>&1

FB=$(fetch_to_file step4body "http://127.0.0.1:$PHP_PORT/")
FC=$(fetch_to_file step4css "http://127.0.0.1:$PHP_PORT/wp-content/themes/twentytwentyfour/style.css")

# Finding #14: also verify the underlying Dolt row reflects the merged value.
# A correctly-merged title isn't just a rendering artifact — the wp_options
# row on main must now carry 'Preview A Site'.
MERGED_BLOGNAME=$($PHP_BIN -r '
    $c = new mysqli("127.0.0.1", "root", "", "wordpress/main", '"$DOLT_PORT"');
    if ($c->connect_error) { echo "CONNECT_ERROR"; exit; }
    $r = $c->query("SELECT option_value FROM wp_options WHERE option_name = \"blogname\" LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) { echo $row["option_value"]; }
    $c->close();
' 2>/dev/null)

if grep -qi "Preview A Site" "$FB" && grep -q "preview-a marker" "$FC" && [ "$MERGED_BLOGNAME" = "Preview A Site" ]; then
    echo "STEP 4 PASS: merge preview-a -> main completed (title rendered AND wp_options.blogname='Preview A Site')"
    S4=1
else
    echo "STEP 4 FAIL: merge did not propagate changes to main"
    echo "  Title: $(grep -oiP '<title>[^<]+' "$FB" | head -1)"
    echo "  CSS marker: $(grep 'preview-a marker' "$FC" | head -1)"
    echo "  wp_options.blogname in Dolt: '$MERGED_BLOGNAME' (expected 'Preview A Site')"
fi

# ============================================================
# STEP 5: Undo the merge (revert)
# ============================================================

if [ -n "$PRE_MERGE_HASH" ]; then
    dolt_sql "CALL DOLT_RESET('--hard', '$PRE_MERGE_HASH')" > /dev/null 2>&1
else
    dolt_session \
        "UPDATE wp_options SET option_value = '$SITE_TITLE' WHERE option_name = 'blogname'" \
        "CALL DOLT_COMMIT('-am', 'Revert')" \
        > /dev/null 2>&1
fi

bfs_php -r "
    branchfs_set_db('$DB_PATH');
    branchfs_import_file(
        '$WP_SRC/wp-content/themes/twentytwentyfour/style.css',
        'main',
        'wp-content/themes/twentytwentyfour/style.css'
    );
" 2>/dev/null

FB=$(fetch_to_file step5body "http://127.0.0.1:$PHP_PORT/")
FC=$(fetch_to_file step5css "http://127.0.0.1:$PHP_PORT/wp-content/themes/twentytwentyfour/style.css")

if grep -qi "$SITE_TITLE" "$FB" && \
   ! grep -qi "Preview A Site" "$FB" && \
   ! grep -q "preview-a marker" "$FC"; then
    echo "STEP 5 PASS: revert restored main to original state"
    S5=1
else
    echo "STEP 5 FAIL: revert did not restore original state"
    echo "  Title: $(grep -oiP '<title>[^<]+' "$FB" | head -1)"
    grep -qi "Preview A Site" "$FB" && echo "  (body still has 'Preview A Site')"
    grep -q "preview-a marker" "$FC" && echo "  (css still has 'preview-a marker')"
fi

# ============================================================
# STEP 6: Discard a branch (preview-b)
# ============================================================

BRANCHFS_DB="$DB_PATH" "$BASE_DIR/bin/branchctl" create preview-b --from main > /dev/null 2>&1

dolt_session \
    "CALL DOLT_CHECKOUT('preview-b')" \
    "UPDATE wp_options SET option_value = 'Preview B Site' WHERE option_name = 'blogname'" \
    "CALL DOLT_COMMIT('-am', 'Preview B changes')" \
    "CALL DOLT_CHECKOUT('main')" \
    > /dev/null 2>&1

bfs_php -r "
    branchfs_set_db('$DB_PATH');
    \$css = file_get_contents('branchfs://main/wp-content/themes/twentytwentyfour/style.css');
    file_put_contents('branchfs://preview-b/wp-content/themes/twentytwentyfour/style.css',
        \"/* preview-b marker */\n\" . \$css);
" 2>/dev/null

dolt_sql "CALL DOLT_BRANCH('-D', 'preview-b')" > /dev/null 2>&1

bfs_php -r "
    branchfs_set_db('$DB_PATH');
    \$db = new SQLite3('$DB_PATH');
    \$db->exec(\"DELETE FROM files WHERE branch_id = (SELECT id FROM branches WHERE name = 'preview-b')\");
    \$db->exec(\"DELETE FROM branches WHERE name = 'preview-b'\");
    \$db->close();
" 2>/dev/null

DOLT_CHECK=$(dolt_sql "SELECT COUNT(*) as c FROM dolt_branches WHERE name = 'preview-b'" 2>/dev/null | tr -d '[:space:]')
BFS_CHECK=$(bfs_php -r "
    branchfs_set_db('$DB_PATH');
    \$db = new SQLite3('$DB_PATH');
    echo \$db->querySingle(\"SELECT COUNT(*) FROM branches WHERE name = 'preview-b'\");
    \$db->close();
" 2>/dev/null | tr -d '[:space:]')

if [ "${DOLT_CHECK:-1}" = "0" ] && [ "${BFS_CHECK:-1}" = "0" ]; then
    echo "STEP 6 PASS: discarded preview-b, branch no longer resolvable"
    S6=1
else
    echo "STEP 6 FAIL: preview-b still exists (dolt=$DOLT_CHECK, branchfs=$BFS_CHECK)"
fi

# ============================================================
# STEP 7: Three parallel branches
# ============================================================

for BNAME in preview-c preview-d preview-e; do
    BRANCHFS_DB="$DB_PATH" "$BASE_DIR/bin/branchctl" create "$BNAME" --from main > /dev/null 2>&1

    BTITLE="Branch ${BNAME##preview-} Title"
    dolt_session \
        "CALL DOLT_CHECKOUT('$BNAME')" \
        "UPDATE wp_options SET option_value = '$BTITLE' WHERE option_name = 'blogname'" \
        "CALL DOLT_COMMIT('-am', 'Set title for $BNAME')" \
        "CALL DOLT_CHECKOUT('main')" \
        > /dev/null 2>&1

    bfs_php -r "
        branchfs_set_db('$DB_PATH');
        \$css = file_get_contents('branchfs://main/wp-content/themes/twentytwentyfour/style.css');
        file_put_contents('branchfs://$BNAME/wp-content/themes/twentytwentyfour/style.css',
            \"/* $BNAME marker */\n\" . \$css);
    " 2>/dev/null
done

HOST_C=$(host_for "preview-c")
HOST_D=$(host_for "preview-d")
HOST_E=$(host_for "preview-e")

fetch -H "Host: $HOST_C" "http://127.0.0.1:$PHP_PORT/" > "$WORK_DIR/body-c.txt" &
PID_C=$!
fetch -H "Host: $HOST_D" "http://127.0.0.1:$PHP_PORT/" > "$WORK_DIR/body-d.txt" &
PID_D=$!
fetch -H "Host: $HOST_E" "http://127.0.0.1:$PHP_PORT/" > "$WORK_DIR/body-e.txt" &
PID_E=$!

wait $PID_C $PID_D $PID_E 2>/dev/null || true

FILE_C="$WORK_DIR/body-c.txt"
FILE_D="$WORK_DIR/body-d.txt"
FILE_E="$WORK_DIR/body-e.txt"

S7_OK=true

grep -qi "Branch c Title" "$FILE_C" || { echo "  preview-c did not show 'Branch c Title'"; S7_OK=false; }
grep -qi "Branch d Title" "$FILE_D" || { echo "  preview-d did not show 'Branch d Title'"; S7_OK=false; }
grep -qi "Branch e Title" "$FILE_E" || { echo "  preview-e did not show 'Branch e Title'"; S7_OK=false; }

grep -qEi "Branch (d|e) Title" "$FILE_C" && { echo "  preview-c contaminated"; S7_OK=false; } || true
grep -qEi "Branch (c|e) Title" "$FILE_D" && { echo "  preview-d contaminated"; S7_OK=false; } || true
grep -qEi "Branch (c|d) Title" "$FILE_E" && { echo "  preview-e contaminated"; S7_OK=false; } || true

if $S7_OK; then
    echo "STEP 7 PASS: three parallel branches return independent content"
    S7=1
else
    echo "STEP 7 FAIL: parallel branch isolation failed"
    echo "  C title: $(grep -oiP '<title>[^<]+' "$FILE_C" | head -1)"
    echo "  D title: $(grep -oiP '<title>[^<]+' "$FILE_D" | head -1)"
    echo "  E title: $(grep -oiP '<title>[^<]+' "$FILE_E" | head -1)"
fi

# ============================================================
# STEP 8: No errors in server logs
# ============================================================

PHP_FATALS=$(grep -c -iE 'PHP Fatal|PHP Parse|Segmentation fault' "$ERR_LOG" 2>/dev/null || true)
SQLITE_ERRORS=$(grep -c -iE 'SQLite.*corrupt|database is locked' "$ERR_LOG" 2>/dev/null || true)
PHP_FATALS=$(echo "${PHP_FATALS:-0}" | tr -d '[:space:]')
SQLITE_ERRORS=$(echo "${SQLITE_ERRORS:-0}" | tr -d '[:space:]')
[ -z "$PHP_FATALS" ] && PHP_FATALS=0
[ -z "$SQLITE_ERRORS" ] && SQLITE_ERRORS=0

if [ "$PHP_FATALS" = "0" ] && [ "$SQLITE_ERRORS" = "0" ]; then
    echo "STEP 8 PASS: no PHP fatals or SQLite corruption in server log"
    S8=1
else
    echo "STEP 8 FAIL: server log contains errors (fatals=$PHP_FATALS, sqlite=$SQLITE_ERRORS)"
    grep -iE 'Fatal|Parse|Segmentation|corrupt|locked' "$ERR_LOG" 2>/dev/null | tail -5
fi

# ============================================================
# SUMMARY
# ============================================================

echo ""

TOTAL_PASS=$((S1 + S2 + S3 + S4 + S5 + S6 + S7 + S8))

echo "E2E RESULT: $TOTAL_PASS/$TOTAL_STEPS steps pass"
echo ""

if [ "$TOTAL_PASS" -eq "$TOTAL_STEPS" ]; then
    echo "All tests passed!"
    exit 0
else
    echo "Some tests failed. Check output above."
    echo "PHP server log: $PHP_LOG"
    echo "Dolt server log: $DOLT_LOG"
    echo "WP debug log: $DEBUG_LOG"
    echo "PHP error log: $ERR_LOG"
    exit 1
fi

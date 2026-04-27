#!/bin/bash
# ============================================================
# BranchFS Dev Mode
#
# Bootstraps the same stack as run_e2e.sh (dolt + branchfs +
# real WordPress + php -S router) and then STAYS RUNNING so you
# can poke at it manually.
#
# Usage: bash e2e/dev.sh
# Ctrl+C to stop. Servers are cleaned up on exit.
# ============================================================

set -euo pipefail

E2E_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_DIR="$(cd "$E2E_DIR/.." && pwd)"
WP_SRC="${WP_SRC:-$E2E_DIR/wp-src}"
WORK_DIR="${WORK_DIR:-/tmp/branchfs-dev}"
DOLT_PORT="${DOLT_PORT:-13306}"
PHP_PORT="${PHP_PORT:-18080}"
PHP_HOST="${PHP_HOST:-0.0.0.0}"   # bind to all interfaces so Docker/host can reach it
BRANCHFS_SECRET="${BRANCHFS_SECRET:-dev-secret}"
SITE_TITLE="${SITE_TITLE:-Branched WP Dev}"

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

DOLT_PID=""
PHP_PID=""

cleanup() {
    echo ""
    echo "[cleanup] stopping servers..."
    [ -n "$PHP_PID" ]  && kill "$PHP_PID"  2>/dev/null && wait "$PHP_PID"  2>/dev/null || true
    [ -n "$DOLT_PID" ] && kill "$DOLT_PID" 2>/dev/null && wait "$DOLT_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

mint_cookie() {
    local branch="$1"
    local sig
    sig=$($PHP_BIN -r "echo hash_hmac('sha256', '$branch', '$BRANCHFS_SECRET');")
    echo "wp_branch=${branch}:${sig}"
}

echo "[setup] cleaning up old processes..."
pkill -f "dolt sql-server.*--port=$DOLT_PORT" 2>/dev/null || true
pkill -f "php.*$PHP_HOST:$PHP_PORT" 2>/dev/null || true
sleep 1

rm -rf "$WORK_DIR"
mkdir -p "$WORK_DIR" "$WP_ROOT" "$DOLT_DATA/wordpress"

[ -x "$BASE_DIR/ext/branchfs.so" ] || { echo "ext/branchfs.so not built. Run: make"; exit 1; }
[ -d "$WP_SRC" ] || { echo "WordPress source missing at $WP_SRC. See README."; exit 1; }

echo ""
echo "=== BOOTSTRAP ==="
echo ""

echo -n "[bootstrap] starting dolt sql-server on :$DOLT_PORT ... "
(cd "$DOLT_DATA/wordpress" && "$DOLT" init --name "dev" --email "dev@local" > /dev/null 2>&1)
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
$PHP "$BASE_DIR/scripts/init_db.php" "$DB_PATH" > /dev/null 2>&1
echo "ok"

echo -n "[bootstrap] importing WordPress into branchfs (main) ... "
IMPORT_OUT=$($PHP "$BASE_DIR/scripts/import_wp.php" "$WP_SRC" "$DB_PATH" main 2>/dev/null)
FILE_COUNT=$(echo "$IMPORT_OUT" | grep -oP '\d+ files' | head -1 || echo "? files")
echo "ok ($FILE_COUNT)"

echo "[bootstrap] installing WordPress ..."
$PHP "$E2E_DIR/bootstrap_wp.php" \
    "$DB_PATH" "$WP_ROOT" "$DOLT_PORT" "$SITE_TITLE" \
    "$BASE_DIR/wp-plugin/branchfs-wp.php" "$DEBUG_LOG" 2>&1 \
    | grep -v 'Missing arginfo\|sendmail' | sed 's/^/  /'
echo "[bootstrap] WordPress installed (admin=admin / password=admin) site='$SITE_TITLE'"

echo -n "[bootstrap] starting php -S on $PHP_HOST:$PHP_PORT ... "
BRANCHFS_DB="$DB_PATH" \
BRANCHFS_WP_ROOT="$WP_ROOT" \
BRANCHFS_SECRET="$BRANCHFS_SECRET" \
$PHP \
    -d log_errors=On \
    -d "error_log=$ERR_LOG" \
    -d post_max_size=100M \
    -d upload_max_filesize=100M \
    -S "$PHP_HOST:$PHP_PORT" \
    -t "$WP_ROOT" \
    "$E2E_DIR/router.php" \
    > "$PHP_LOG" 2>&1 &
PHP_PID=$!

# Port-listening probe (don't send an HTTP request — cold WordPress through
# branchfs can take a while on the first hit, so a /-hitting probe races with
# the real first request).
for i in $(seq 1 30); do
    if $PHP_BIN -r "\$s=@fsockopen('127.0.0.1',$PHP_PORT,\$e,\$m,0.5); if(\$s){fclose(\$s);exit(0);} exit(1);" 2>/dev/null; then
        break
    fi
    if ! kill -0 "$PHP_PID" 2>/dev/null; then
        echo "FAILED (php -S died)"
        tail -20 "$PHP_LOG"
        exit 1
    fi
    [ "$i" -eq 30 ] && { echo "FAILED (port never opened)"; tail -20 "$PHP_LOG"; exit 1; }
    sleep 1
done
echo "ok (pid=$PHP_PID)"

# Pre-mint cookies for a couple of demo branches so the user can copy-paste.
MAIN_URL="http://127.0.0.1:$PHP_PORT"

cat <<EOF

============================================================
 BranchFS dev server is up.

 Browse the MAIN site:
   open $MAIN_URL/
   admin:  $MAIN_URL/wp-login.php   (admin / admin)

 Create a preview branch 'feature-x' (from another terminal):

   # 1. fork the Dolt branch
   $PHP_BIN -r '\$c = new mysqli("127.0.0.1","root","","wordpress",$DOLT_PORT); \$c->query("CALL DOLT_BRANCH(\"feature-x\", \"main\")"); echo "dolt: feature-x\n";'

   # 2. fork the branchfs overlay
   $PHP -r 'branchfs_set_db("$DB_PATH"); branchfs_create_branch("feature-x","main"); echo "branchfs: feature-x\n";'

   # 3. mint a signed preview cookie
   COOKIE=\$($PHP_BIN -r "echo 'wp_branch=feature-x:'.hash_hmac('sha256','feature-x','$BRANCHFS_SECRET');")

   # 4. request the preview
   curl -b "\$COOKIE" $MAIN_URL/
   # or in a browser, set cookie: \$COOKIE for 127.0.0.1

 Merge a preview back into main:
   $PHP $BASE_DIR/scripts/merge.php $DB_PATH feature-x main 127.0.0.1 $DOLT_PORT

 Logs are in $WORK_DIR (dolt-server.log, php-server.log, wp-debug.log, php-errors.log).
 Ctrl+C to stop.
============================================================

EOF

# Tail the PHP access log so the user sees requests.
tail -f "$PHP_LOG"

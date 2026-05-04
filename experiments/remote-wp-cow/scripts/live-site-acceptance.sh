#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

fail() {
  echo "live-acceptance: $*" >&2
  exit 1
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || fail "missing required command: $1"
}

require_env() {
  local name="$1"
  if [ -z "${!name:-}" ]; then
    fail "missing required environment variable: $name"
  fi
}

wait_for_tcp() {
  local host="$1"
  local port="$2"
  local timeout="${3:-20}"
  local start
  start="$(date +%s)"
  while true; do
    if (exec 3<>"/dev/tcp/$host/$port") >/dev/null 2>&1; then
      exec 3>&-
      exec 3<&-
      return 0
    fi
    if [ $(( $(date +%s) - start )) -ge "$timeout" ]; then
      return 1
    fi
    sleep 0.2
  done
}

wait_for_tcp_closed() {
  local host="$1"
  local port="$2"
  local timeout="${3:-20}"
  local start
  start="$(date +%s)"
  while true; do
    if ! (exec 3<>"/dev/tcp/$host/$port") >/dev/null 2>&1; then
      return 0
    fi
    exec 3>&-
    exec 3<&-
    if [ $(( $(date +%s) - start )) -ge "$timeout" ]; then
      return 1
    fi
    sleep 0.2
  done
}

kill_lingering_runtime_processes() {
  local pid args
  while read -r pid args; do
    [ -n "${pid:-}" ] || continue
    [ "$pid" = "$$" ] && continue
    case "$args" in
      *"$WORK_DIR"*)
        case "$args" in
          *mariadbd*|*mariadb-install-db*|*mysqladmin*|*mysql\ *) ;;
          *) kill "$pid" >/dev/null 2>&1 || true ;;
        esac
        ;;
    esac
  done < <(ps -eo pid=,args=)
}

unmount_acceptance_mountpoint() {
  [ -n "${MOUNTPOINT:-}" ] || return 0
  fusermount3 -u "$MOUNTPOINT" >/dev/null 2>&1 ||
    fusermount3 -uz "$MOUNTPOINT" >/dev/null 2>&1 ||
    fusermount -u "$MOUNTPOINT" >/dev/null 2>&1 ||
    fusermount -uz "$MOUNTPOINT" >/dev/null 2>&1 ||
    true
}

http_body() {
  local url="$1"
  local output="$2"
  local max_time="${3:-60}"
  local status
  status="$(curl -L -sS --max-time "$max_time" --connect-timeout 5 \
    -o "$output" -w '%{http_code}' "$url")"
  case "$status" in
    2*|3*) return 0 ;;
    *) echo "HTTP $status for $url" >&2; return 1 ;;
  esac
}

mysql_exec() {
  mysql --protocol=TCP -h127.0.0.1 -P33071 -uroot "$@"
}

mysql_scalar() {
  mysql_exec --batch --raw --skip-column-names --execute "$1"
}

remote_post_count() {
  local title="$1"
  local code
  code='
error_reporting(0);
if (!defined("WP_INSTALLING")) { define("WP_INSTALLING", true); }
require_once rtrim(getcwd(), "/") . "/wp-load.php";
global $wpdb;
$title = $argv[1];
$count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title = %s", $title));
echo $count, "\n";
'
  HOME="$SSH_HOME" ssh "$SSH_TARGET" "cd '$WPCOW_PATH' && php -r $(printf '%q' "$code") -- $(printf '%q' "$title")"
}

cleanup() {
  set +e
  if [ -n "${SERVE_PID:-}" ] && kill -0 "$SERVE_PID" >/dev/null 2>&1; then
    kill "$SERVE_PID" >/dev/null 2>&1 || true
    wait "$SERVE_PID" >/dev/null 2>&1 || true
  fi
  if [ -n "${WORK_DIR:-}" ]; then
    for pid in $(pgrep -f "$WORK_DIR" 2>/dev/null || true); do
      if [ "$pid" != "$$" ]; then
        kill "$pid" >/dev/null 2>&1 || true
      fi
    done
  fi
  unmount_acceptance_mountpoint
  if [ -n "${MYSQL_PID:-}" ] && kill -0 "$MYSQL_PID" >/dev/null 2>&1; then
    kill "$MYSQL_PID" >/dev/null 2>&1 || true
    wait "$MYSQL_PID" >/dev/null 2>&1 || true
  fi
  if [ "${WPCOW_KEEP_ACCEPTANCE_STATE:-0}" != "1" ] && [ -n "${WORK_DIR:-}" ]; then
    rm -rf "$WORK_DIR"
  elif [ -n "${WORK_DIR:-}" ]; then
    echo "live-acceptance: kept state at $WORK_DIR"
  fi
}
trap cleanup EXIT

require_env WPCOW_SSH
require_env WPCOW_PATH
require_env WPCOW_REMOTE_URL

need_cmd cargo
need_cmd curl
need_cmd mariadb-install-db
need_cmd mariadbd
need_cmd mysql
need_cmd mysqladmin
need_cmd php
need_cmd ssh
need_cmd fusermount3

cargo build --locked

WP_COW_BIN="${WP_COW_BIN:-$ROOT/target/debug/wp-cow}"
NAME="${WPCOW_NAME:-live-acceptance}"
HTTP_PORT="${WPCOW_HTTP_PORT:-9481}"
HTTP_ADDR="${WPCOW_HTTP:-127.0.0.1:${HTTP_PORT}}"
LOCAL_URL="${WPCOW_LOCAL_URL:-http://127.0.0.1:${HTTP_PORT}}"
ADMIN_PASSWORD="${WPCOW_LOCAL_ADMIN_PASSWORD:-8u239huiwdsj91das}"
EXPECT_TEXT="${WPCOW_EXPECT_TEXT:-}"
WORK_DIR="${WPCOW_ACCEPTANCE_WORK_DIR:-$(mktemp -d /tmp/wp-cow-live-acceptance.XXXXXX)}"
STATE_DIR="$WORK_DIR/state"
MOUNTPOINT="$WORK_DIR/mount"
UPPER_DIR="$STATE_DIR/clones/$NAME/upper"
MYSQL_DATA="$WORK_DIR/mysql-data"
MYSQL_SOCKET="$WORK_DIR/mysql.sock"
MYSQL_LOG="$WORK_DIR/mariadb.log"
MYSQL_INSTALL_LOG="$WORK_DIR/mariadb-install.log"
SERVE_LOG="$WORK_DIR/serve.log"
COOKIE_JAR="$WORK_DIR/cookies.txt"
TITLE="WP COW Local Only $(date +%s)-$$"

mkdir -p "$MOUNTPOINT" "$STATE_DIR"

SSH_TARGET="$WPCOW_SSH"
SSH_HOME="$HOME"
if [[ "$WPCOW_SSH" == *[[:space:]]* ]]; then
  # Accept the same pasted SSH command shape as the Docker helper by creating a
  # temporary OpenSSH host alias for this acceptance run.
  SSH_TARGET="wp-cow-live-acceptance"
  SSH_HOME="$WORK_DIR/ssh-home"
  SSH_CONFIG="$SSH_HOME/.ssh/config"
  mkdir -p "$SSH_HOME/.ssh"
  chmod 700 "$SSH_HOME/.ssh"
  eval "set -- $WPCOW_SSH"
  [ "${1:-}" = "ssh" ] && shift
  host=""
  user=""
  port=""
  identity=""
  while [ "$#" -gt 0 ]; do
    case "$1" in
      -p) port="${2:-}"; shift 2 ;;
      -p*) port="${1#-p}"; shift ;;
      -i) identity="${2:-}"; shift 2 ;;
      -i*) identity="${1#-i}"; shift ;;
      -l) user="${2:-}"; shift 2 ;;
      -l*) user="${1#-l}"; shift ;;
      -o) shift 2 ;;
      -o*) shift ;;
      ssh) shift ;;
      --) shift; break ;;
      -*) fail "unsupported SSH option in WPCOW_SSH for live acceptance: $1" ;;
      *) host="$1"; shift ;;
    esac
  done
  if [[ "$host" == *@* ]]; then
    [ -z "$user" ] && user="${host%@*}"
    host="${host#*@}"
  fi
  [ -n "$host" ] || fail "could not parse SSH host from WPCOW_SSH"
  if [[ "$identity" == "~/"* ]]; then
    identity="$HOME/${identity#~/}"
  fi
  {
    echo "Host $SSH_TARGET"
    echo "  HostName $host"
    [ -n "$user" ] && echo "  User $user"
    [ -n "$port" ] && echo "  Port $port"
    [ -n "$identity" ] && echo "  IdentityFile $identity"
    [ -n "$identity" ] && echo "  IdentitiesOnly yes"
    echo "  BatchMode yes"
    echo "  StrictHostKeyChecking accept-new"
  } > "$SSH_CONFIG"
  chmod 600 "$SSH_CONFIG"
fi

if mysqladmin --protocol=tcp --host=127.0.0.1 --port=33071 --user=root ping >/dev/null 2>&1; then
  fail "port 33071 already has a MySQL server; stop it or run inside the Docker lab"
fi

MARIADBD_PATH="$(readlink -f "$(command -v mariadbd)")"
BASE_DIR="$(cd "$(dirname "$MARIADBD_PATH")/.." && pwd)"
if ! mariadb-install-db \
  "--basedir=$BASE_DIR" \
  "--datadir=$MYSQL_DATA" \
  --innodb-log-file-size=16M \
  --innodb-buffer-pool-size=64M \
  --auth-root-authentication-method=normal \
  --skip-test-db \
  >"$MYSQL_INSTALL_LOG" 2>&1; then
  cat "$MYSQL_INSTALL_LOG" >&2
  fail "mariadb-install-db failed"
fi

mariadbd \
  --no-defaults \
  "--basedir=$BASE_DIR" \
  "--datadir=$MYSQL_DATA" \
  "--socket=$MYSQL_SOCKET" \
  --port=33071 \
  --bind-address=127.0.0.1 \
  "--pid-file=$WORK_DIR/mysql.pid" \
  --innodb-log-file-size=16M \
  --innodb-buffer-pool-size=64M \
  --aria-pagecache-buffer-size=8M \
  --key-buffer-size=8M \
  --skip-networking=0 \
  --skip-grant-tables \
  >"$MYSQL_LOG" 2>&1 &
MYSQL_PID="$!"

for _ in $(seq 1 100); do
  if mysqladmin --protocol=tcp --host=127.0.0.1 --port=33071 --user=root ping >/dev/null 2>&1; then
    break
  fi
  sleep 0.2
done
mysqladmin --protocol=tcp --host=127.0.0.1 --port=33071 --user=root ping >/dev/null 2>&1 ||
  fail "temporary MariaDB did not start; see $MYSQL_LOG"

before_remote="$(remote_post_count "$TITLE" | tr -d '[:space:]')"
[ "$before_remote" = "0" ] || fail "remote already has unexpected acceptance title"

WPCOW_WEB_SERVER="${WPCOW_WEB_SERVER:-php}" \
WPCOW_SPLASH="${WPCOW_SPLASH:-1}" \
WPCOW_PROXY_FRONTEND=0 \
WPCOW_REMOTE_DB_HELPER="${WPCOW_REMOTE_DB_HELPER:-1}" \
WPCOW_RUNTIME_CODE_PACK="${WPCOW_RUNTIME_CODE_PACK:-1}" \
WPCOW_RUNTIME_CODE_PACK_MAX_MB="${WPCOW_RUNTIME_CODE_PACK_MAX_MB:-256}" \
WPCOW_RUNTIME_CODE_PACK_MAX_FILE_MB="${WPCOW_RUNTIME_CODE_PACK_MAX_FILE_MB:-8}" \
WPCOW_RUNTIME_CODE_PACK_MAX_FILES="${WPCOW_RUNTIME_CODE_PACK_MAX_FILES:-20000}" \
WPCOW_RUNTIME_CODE_PACK_TIMEOUT_SECS="${WPCOW_RUNTIME_CODE_PACK_TIMEOUT_SECS:-180}" \
WPCOW_RUNTIME_CODE_PACK_INCLUDE_ADMIN="${WPCOW_RUNTIME_CODE_PACK_INCLUDE_ADMIN:-0}" \
WPCOW_MATERIALIZE_OPTIONS_TABLE="${WPCOW_MATERIALIZE_OPTIONS_TABLE:-1}" \
WPCOW_REMOTE_QUERY_CACHE=1 \
WPCOW_REMOTE_QUERY_CACHE_MAX_ROWS="${WPCOW_REMOTE_QUERY_CACHE_MAX_ROWS:-5000}" \
WPCOW_REMOTE_FILE_HELPER_TIMEOUT_SECS="${WPCOW_REMOTE_FILE_HELPER_TIMEOUT_SECS:-2}" \
WPCOW_REMOTE_STAT_PREFETCH_MAX_KB="${WPCOW_REMOTE_STAT_PREFETCH_MAX_KB:-0}" \
WPCOW_RUNTIME_SIBLING_PREFETCH_MAX_MB="${WPCOW_RUNTIME_SIBLING_PREFETCH_MAX_MB:-0}" \
WPCOW_PHP_WORKERS="${WPCOW_PHP_WORKERS:-1}" \
HOME="$SSH_HOME" \
"$WP_COW_BIN" serve \
  --state-dir "$STATE_DIR" \
  --name "$NAME" \
  --ssh "$SSH_TARGET" \
  --path "$WPCOW_PATH" \
  --remote-url "$WPCOW_REMOTE_URL" \
  --local-url "$LOCAL_URL" \
  --mountpoint "$MOUNTPOINT" \
  --http "$HTTP_ADDR" \
  >"$SERVE_LOG" 2>&1 &
SERVE_PID="$!"

host="${HTTP_ADDR%:*}"
port="${HTTP_ADDR##*:}"
wait_for_tcp "$host" "$port" 45 || {
  tail -n 200 "$SERVE_LOG" >&2 || true
  fail "wp-cow server did not open $HTTP_ADDR"
}

first_splash="$WORK_DIR/first-splash.html"
http_body "$LOCAL_URL/" "$first_splash" 10 || {
  tail -n 200 "$SERVE_LOG" >&2 || true
  fail "first splash/progress request failed"
}
if rg -qi 'WordPress.*Installation|wp-admin/install.php|wp-cow DB/runtime error|wp-cow did not load the remote site' "$first_splash"; then
  sed -n '1,80p' "$first_splash" >&2
  fail "first splash request returned installer or wp-cow runtime error"
fi
pack_wait="${WPCOW_RUNTIME_CODE_PACK_WAIT_SECS:-120}"
pack_started="$(date +%s)"
progress_path="$STATE_DIR/clones/$NAME/file-cache/progress.json"
while true; do
  if [ -f "$progress_path" ]; then
    progress_json="$(cat "$progress_path")"
  else
    progress_json="$(curl -sS --max-time 5 --connect-timeout 2 "$LOCAL_URL/__wp-cow/progress" || true)"
  fi
  phase="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo is_array($j) && isset($j["phase"]) ? $j["phase"] : "";' <<<"$progress_json")"
  if [ "${WPCOW_RUNTIME_CODE_PACK:-1}" = "0" ] && [ -z "$phase" ]; then
    break
  fi
  case "$phase" in
    runtime-code-pack-starting|runtime-code-pack|"")
      if [ $(( $(date +%s) - pack_started )) -ge "$pack_wait" ]; then
        echo "$progress_json" >&2
        fail "runtime code pack did not finish within ${pack_wait}s"
      fi
      sleep 0.5
      ;;
    *) break ;;
  esac
done

first_body="$WORK_DIR/first.html"
actual_timeout="${WPCOW_ACTUAL_TIMEOUT_SECS:-180}"
http_body "$LOCAL_URL/?__wp_cow_bypass_splash=1" "$first_body" "$actual_timeout" || {
  tail -n 200 "$SERVE_LOG" >&2 || true
  fail "first WordPress request failed"
}
if rg -qi 'WordPress.*Installation|wp-admin/install.php|wp-cow DB/runtime error|wp-cow did not load the remote site' "$first_body"; then
  sed -n '1,80p' "$first_body" >&2
  fail "first request returned installer or wp-cow runtime error"
fi
if [ -n "$EXPECT_TEXT" ]; then
  rg -q "$EXPECT_TEXT" "$first_body" || fail "first response did not contain WPCOW_EXPECT_TEXT=$EXPECT_TEXT"
fi

second_body="$WORK_DIR/second.html"
http_body "$LOCAL_URL/?__wp_cow_bypass_splash=1" "$second_body" "${WPCOW_SECOND_TIMEOUT_SECS:-60}" ||
  fail "second cached WordPress request failed"

php_create="$WORK_DIR/create-local-page.php"
cat > "$php_create" <<'PHP'
<?php
$_SERVER['HTTP_HOST'] = getenv('WPCOW_ACCEPTANCE_HTTP_HOST') ?: '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
define('WP_USE_THEMES', false);
require __DIR__ . '/wp-load.php';
$title = getenv('WPCOW_ACCEPTANCE_TITLE');
$post_id = wp_insert_post(array(
    'post_title' => $title,
    'post_content' => 'local-only acceptance content',
    'post_status' => 'publish',
    'post_type' => 'page',
), true);
if (is_wp_error($post_id)) {
    fwrite(STDERR, $post_id->get_error_message() . "\n");
    exit(1);
}
echo 'WPCOW_POST_ID=' . (int) $post_id . "\n";
PHP
mkdir -p "$UPPER_DIR"
cp "$php_create" "$UPPER_DIR/.wp-cow-create-local-page.php"
post_output="$(
  cd "$MOUNTPOINT" &&
  WPCOW_ACCEPTANCE_TITLE="$TITLE" \
  WPCOW_ACCEPTANCE_HTTP_HOST="${LOCAL_URL#http://}" \
  php .wp-cow-create-local-page.php
)"
printf '%s\n' "$post_output" > "$WORK_DIR/create-local-page.out"
post_id="$(sed -n 's/^WPCOW_POST_ID=//p' "$WORK_DIR/create-local-page.out" | tail -n 1 | tr -dc '0-9')"
[ -n "$post_id" ] || fail "local wp_insert_post did not return a post id"

local_body="$WORK_DIR/local-page.html"
http_body "$LOCAL_URL/?p=$post_id&__wp_cow_bypass_splash=1" "$local_body" 30 ||
  fail "local-only page did not render"
rg -q "$TITLE" "$local_body" || fail "local-only page response did not contain its title"

after_remote="$(remote_post_count "$TITLE" | tr -d '[:space:]')"
[ "$after_remote" = "0" ] || fail "local-only page title appeared in remote database"

sever_log="$WORK_DIR/sever.log"
HOME="$SSH_HOME" \
"$WP_COW_BIN" sever "$NAME" \
  --state-dir "$STATE_DIR" \
  --admin-password "$ADMIN_PASSWORD" \
  >"$sever_log" 2>&1 || {
    cat "$sever_log" >&2
    fail "wp-cow sever failed"
  }
admin_user="$(sed -n "s/.*set local administrator password for '\([^']*\)'.*/\1/p" "$sever_log" | tail -n 1)"
[ -n "$admin_user" ] || fail "could not determine local admin user from sever output"

kill "$SERVE_PID" >/dev/null 2>&1 || true
wait "$SERVE_PID" >/dev/null 2>&1 || true
SERVE_PID=""
kill_lingering_runtime_processes
wait_for_tcp_closed "$host" "$port" 30 ||
  fail "old web server did not release $HTTP_ADDR before offline restart"
wait_for_tcp_closed 127.0.0.1 39070 30 ||
  fail "old control server did not release 127.0.0.1:39070 before offline restart"
wait_for_tcp_closed 127.0.0.1 33070 30 ||
  fail "old MySQL proxy did not release 127.0.0.1:33070 before offline restart"
wait_for_tcp_closed 127.0.0.1 33072 30 || true
unmount_acceptance_mountpoint

manifest="$STATE_DIR/clones/$NAME/manifest.json"
php -r '$p=$argv[1]; $j=json_decode(file_get_contents($p), true); $j["ssh"]="wp-cow-offline-should-not-connect"; file_put_contents($p, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");' "$manifest"

WPCOW_WEB_SERVER="${WPCOW_WEB_SERVER:-php}" \
WPCOW_SPLASH="${WPCOW_SPLASH:-1}" \
WPCOW_REMOTE_DB_HELPER="${WPCOW_REMOTE_DB_HELPER:-1}" \
WPCOW_RUNTIME_CODE_PACK="${WPCOW_RUNTIME_CODE_PACK:-1}" \
WPCOW_RUNTIME_CODE_PACK_MAX_MB="${WPCOW_RUNTIME_CODE_PACK_MAX_MB:-256}" \
WPCOW_RUNTIME_CODE_PACK_MAX_FILE_MB="${WPCOW_RUNTIME_CODE_PACK_MAX_FILE_MB:-8}" \
WPCOW_RUNTIME_CODE_PACK_MAX_FILES="${WPCOW_RUNTIME_CODE_PACK_MAX_FILES:-20000}" \
WPCOW_RUNTIME_CODE_PACK_TIMEOUT_SECS="${WPCOW_RUNTIME_CODE_PACK_TIMEOUT_SECS:-180}" \
WPCOW_RUNTIME_CODE_PACK_INCLUDE_ADMIN="${WPCOW_RUNTIME_CODE_PACK_INCLUDE_ADMIN:-0}" \
WPCOW_MATERIALIZE_OPTIONS_TABLE="${WPCOW_MATERIALIZE_OPTIONS_TABLE:-1}" \
WPCOW_REMOTE_FILE_HELPER_TIMEOUT_SECS="${WPCOW_REMOTE_FILE_HELPER_TIMEOUT_SECS:-2}" \
WPCOW_REMOTE_STAT_PREFETCH_MAX_KB="${WPCOW_REMOTE_STAT_PREFETCH_MAX_KB:-0}" \
WPCOW_RUNTIME_SIBLING_PREFETCH_MAX_MB="${WPCOW_RUNTIME_SIBLING_PREFETCH_MAX_MB:-0}" \
WPCOW_PHP_WORKERS="${WPCOW_PHP_WORKERS:-1}" \
HOME="$SSH_HOME" \
"$WP_COW_BIN" run "$NAME" \
  --state-dir "$STATE_DIR" \
  --mountpoint "$MOUNTPOINT" \
  --http "$HTTP_ADDR" \
  >"$SERVE_LOG.offline" 2>&1 &
SERVE_PID="$!"
wait_for_tcp "$host" "$port" 30 || {
  tail -n 200 "$SERVE_LOG.offline" >&2 || true
  fail "offline wp-cow server did not open $HTTP_ADDR"
}

offline_body="$WORK_DIR/offline-local-page.html"
http_body "$LOCAL_URL/?p=$post_id&__wp_cow_bypass_splash=1" "$offline_body" 30 ||
  fail "offline local-only page did not render"
rg -q "$TITLE" "$offline_body" || fail "offline refresh did not use local materialized post"

login_body="$WORK_DIR/login.html"
login_status="$(curl -L -sS --max-time 30 --connect-timeout 5 \
  -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -d "log=$admin_user" \
  -d "pwd=$ADMIN_PASSWORD" \
  -d "wp-submit=Log In" \
  -d "redirect_to=$LOCAL_URL/wp-admin/" \
  -d "testcookie=1" \
  -o "$login_body" \
  -w '%{http_code}' \
  "$LOCAL_URL/wp-login.php")"
case "$login_status" in
  2*|3*) ;;
  *) fail "local admin login returned HTTP $login_status" ;;
esac
rg -q 'wordpress_logged_in' "$COOKIE_JAR" || fail "local admin login did not set wordpress_logged_in cookie"

admin_body="$WORK_DIR/admin.html"
http_status="$(curl -L -sS --max-time 30 --connect-timeout 5 \
  -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -o "$admin_body" \
  -w '%{http_code}' \
  "$LOCAL_URL/wp-admin/")"
case "$http_status" in
  2*|3*) ;;
  *) fail "wp-admin returned HTTP $http_status after login" ;;
esac
if rg -qi '<form[^>]+id="loginform"|name="loginform"' "$admin_body"; then
  fail "wp-admin still shows login form after local admin login"
fi

cache_files="$(find "$STATE_DIR/clones/$NAME/file-cache" -type f | wc -l | tr -d ' ')"
cache_bytes="$(du -sb "$STATE_DIR/clones/$NAME/file-cache" | awk '{print $1}')"
if [ -d "$STATE_DIR/clones/$NAME/file-cache/mirror/wp-content/uploads" ]; then
  fail "uploads directory was mirrored into the file cache"
fi

cat <<REPORT
live-acceptance: PASS
state_dir=$STATE_DIR
mountpoint=$MOUNTPOINT
local_url=$LOCAL_URL
local_post_id=$post_id
local_admin_user=$admin_user
file_cache_files=$cache_files
file_cache_bytes=$cache_bytes
REPORT

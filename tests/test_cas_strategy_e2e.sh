#!/usr/bin/env bash
set -euo pipefail

BIN="${1:-target/x86_64-unknown-linux-musl/release/forkpress}"
if [ ! -x "$BIN" ]; then
  echo "missing forkpress binary: $BIN" >&2
  exit 1
fi

TMP="$(mktemp -d /tmp/forkpress-cas-e2e.XXXXXX)"
STATE="$TMP/state"
WORK="$TMP/site"
PORT="${FORKPRESS_E2E_PORT:-18384}"
export FORKPRESS_STATE_DIR="$STATE"
mkdir -p "$STATE"

cleanup() {
  "$BIN" server stop --work-dir "$WORK" >/dev/null 2>&1 || true
}
trap cleanup EXIT

"$BIN" init --strategy cas --work-dir "$WORK" --admin-password admin
"$BIN" server start --work-dir "$WORK" --port "$PORT" --root-host wp.localhost --workers 1
"$BIN" server list | grep -F "$WORK" >/dev/null

"$BIN" branch --work-dir "$WORK" create feature-cas > "$TMP/branch-create.out"
grep -F "feature-cas.wp.localhost:$PORT" "$TMP/branch-create.out" >/dev/null
test -f "$WORK/cas/store.redb"
test -d "$WORK/cas/wproot"
test ! -e "$WORK/cas/wproot/wp-load.php"
test -f "$WORK/cas/branches/feature-cas/.ht.sqlite"
test ! -e "$WORK/cas/branches/feature-cas/wp-load.php"

curl -sS -H "Host: feature-cas.wp.localhost:$PORT" \
  "http://127.0.0.1:$PORT/wp-admin/post-new.php" \
  -o "$TMP/branch-post-new.html"
grep -F "Branch: feature-cas" "$TMP/branch-post-new.html" >/dev/null
grep -F "wp.apiFetch.createNonceMiddleware" "$TMP/branch-post-new.html" >/dev/null

REST_NONCE="$(node - <<'NODE' "$TMP/branch-post-new.html"
const fs = require('fs');
const html = fs.readFileSync(process.argv[2], 'utf8');
const match = html.match(/wp\.apiFetch\.createNonceMiddleware\(\s*"([^"]+)"\s*\)/)
  || html.match(/var wpApiSettings = .*?"nonce":"([^"]+)"/s);
if (!match) process.exit(2);
console.log(match[1]);
NODE
)"

TITLE="CAS backend saved $(date +%s)"
HTTP="$(
  curl -sS -o "$TMP/rest-save.json" -w '%{http_code}' \
    -H "Host: feature-cas.wp.localhost:$PORT" \
    -H "Content-Type: application/json" \
    -H "X-WP-Nonce: $REST_NONCE" \
    --data "{\"title\":\"$TITLE\",\"content\":\"Saved from ForkPress CAS e2e\",\"status\":\"publish\"}" \
    "http://127.0.0.1:$PORT/index.php?rest_route=/wp/v2/posts"
)"
if [ "$HTTP" != "201" ]; then
  echo "REST save returned $HTTP" >&2
  cat "$TMP/rest-save.json" >&2
  "$BIN" logs --work-dir "$WORK" --file all -n 160 >&2 || true
  exit 1
fi

curl -sS -H "Host: feature-cas.wp.localhost:$PORT" \
  "http://127.0.0.1:$PORT/wp-admin/edit.php" \
  -o "$TMP/edit.html"
grep -F "$TITLE" "$TMP/edit.html" >/dev/null

"$BIN" branch --work-dir "$WORK" list | grep -F "feature-cas" >/dev/null

echo "PASS cas strategy e2e"

#!/usr/bin/env bash
set -euo pipefail

BIN="${1:-target/x86_64-unknown-linux-musl/release/forkpress}"
if [ ! -x "$BIN" ]; then
  echo "missing forkpress binary: $BIN" >&2
  exit 1
fi

TMP="$(mktemp -d /tmp/forkpress-cow-e2e.XXXXXX)"
STATE="$TMP/state"
WORK="$TMP/site"
WORK_DIR="$WORK/.forkpress"
PORT="${FORKPRESS_E2E_PORT:-18383}"
export FORKPRESS_STATE_DIR="$STATE"
mkdir -p "$STATE"

cleanup() {
  "$BIN" stop --work-dir "$WORK_DIR" >/dev/null 2>&1 || true
}
trap cleanup EXIT

"$BIN" init --strategy cow --work-dir "$WORK_DIR" --admin-password admin
test -d "$WORK/.forkpress"
test -d "$WORK/main"
test -f "$WORK/main/wp-load.php"
test ! -e "$WORK/.forkpress/cow/branches/main"
grep -E 'file_view = "(reflink|file-copy|macos-apfs-sparsebundle)"' "$WORK_DIR/site.toml" >/dev/null
grep -F 'strategy = "cow"' "$WORK_DIR/site.toml" >/dev/null
"$BIN" doctor storage --work-dir "$WORK_DIR" > "$TMP/storage-doctor.out"
grep -F "ForkPress storage capability report" "$TMP/storage-doctor.out" >/dev/null
"$BIN" storage status --work-dir "$WORK_DIR" > "$TMP/storage-status.out"
grep -F "ForkPress storage status" "$TMP/storage-status.out" >/dev/null
"$BIN" serve --work-dir "$WORK_DIR" --port "$PORT" --root-host wp.localhost --workers 1
"$BIN" server list | grep -F "$WORK_DIR" >/dev/null

"$BIN" branch --work-dir "$WORK_DIR" create feature-cow > "$TMP/branch-create.out"
grep -F "feature-cow.wp.localhost:$PORT" "$TMP/branch-create.out" >/dev/null
test -d "$WORK/feature-cow"
echo "feature only" > "$WORK/feature-cow/wp-content/forkpress-branch.txt"
test ! -e "$WORK/main/wp-content/forkpress-branch.txt"

curl -sS -H "Host: feature-cow.wp.localhost:$PORT" \
  "http://127.0.0.1:$PORT/wp-admin/post-new.php" \
  -o "$TMP/branch-post-new.html"
grep -F "Branch: feature-cow" "$TMP/branch-post-new.html" >/dev/null
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

TITLE="COW backend saved $(date +%s)"
HTTP="$(
  curl -sS -o "$TMP/rest-save.json" -w '%{http_code}' \
    -H "Host: feature-cow.wp.localhost:$PORT" \
    -H "Content-Type: application/json" \
    -H "X-WP-Nonce: $REST_NONCE" \
    --data "{\"title\":\"$TITLE\",\"content\":\"Saved from ForkPress COW e2e\",\"status\":\"publish\"}" \
    "http://127.0.0.1:$PORT/index.php?rest_route=/wp/v2/posts"
)"
if [ "$HTTP" != "201" ]; then
  echo "REST save returned $HTTP" >&2
  cat "$TMP/rest-save.json" >&2
  "$BIN" logs --work-dir "$WORK_DIR" --file all -n 160 >&2 || true
  exit 1
fi

curl -sS -H "Host: feature-cow.wp.localhost:$PORT" \
  "http://127.0.0.1:$PORT/wp-admin/edit.php" \
  -o "$TMP/edit.html"
grep -F "$TITLE" "$TMP/edit.html" >/dev/null

"$BIN" branch --work-dir "$WORK_DIR" list | grep -F "feature-cow" >/dev/null

echo "PASS cow materialized strategy e2e"

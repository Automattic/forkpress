#!/usr/bin/env bash
set -euo pipefail

BIN="${1:-target/x86_64-unknown-linux-musl/release/forkpress}"
if [ ! -x "$BIN" ]; then
  echo "missing forkpress binary: $BIN" >&2
  exit 1
fi

TMP="$(mktemp -d /tmp/forkpress-zfs-e2e.XXXXXX)"
STATE="$TMP/state"
WORK="$TMP/site"
PORT="${FORKPRESS_E2E_PORT:-18383}"
export FORKPRESS_STATE_DIR="$STATE"
mkdir -p "$STATE"

cleanup() {
  "$BIN" server stop --work-dir "$WORK" >/dev/null 2>&1 || true
}
trap cleanup EXIT

"$BIN" init --strategy zfs --work-dir "$WORK" --admin-password admin
grep -E 'file_view = "(reflink|file-copy|macos-apfs-sparsebundle)"' "$WORK/site.toml" >/dev/null
"$BIN" doctor storage --work-dir "$WORK" > "$TMP/storage-doctor.out"
grep -F "ForkPress storage capability report" "$TMP/storage-doctor.out" >/dev/null
"$BIN" server start --work-dir "$WORK" --port "$PORT" --root-host wp.localhost --workers 1
"$BIN" server list | grep -F "$WORK" >/dev/null

"$BIN" branch --work-dir "$WORK" create feature-zfs > "$TMP/branch-create.out"
grep -F "feature-zfs.wp.localhost:$PORT" "$TMP/branch-create.out" >/dev/null
echo "feature only" > "$WORK/zfs/branches/feature-zfs/wp-content/forkpress-branch.txt"
test ! -e "$WORK/zfs/branches/main/wp-content/forkpress-branch.txt"

curl -sS -H "Host: feature-zfs.wp.localhost:$PORT" \
  "http://127.0.0.1:$PORT/wp-admin/post-new.php" \
  -o "$TMP/branch-post-new.html"
grep -F "Branch: feature-zfs" "$TMP/branch-post-new.html" >/dev/null
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

TITLE="ZFS backend saved $(date +%s)"
HTTP="$(
  curl -sS -o "$TMP/rest-save.json" -w '%{http_code}' \
    -H "Host: feature-zfs.wp.localhost:$PORT" \
    -H "Content-Type: application/json" \
    -H "X-WP-Nonce: $REST_NONCE" \
    --data "{\"title\":\"$TITLE\",\"content\":\"Saved from ForkPress ZFS e2e\",\"status\":\"publish\"}" \
    "http://127.0.0.1:$PORT/index.php?rest_route=/wp/v2/posts"
)"
if [ "$HTTP" != "201" ]; then
  echo "REST save returned $HTTP" >&2
  cat "$TMP/rest-save.json" >&2
  "$BIN" logs --work-dir "$WORK" --file all -n 160 >&2 || true
  exit 1
fi

curl -sS -H "Host: feature-zfs.wp.localhost:$PORT" \
  "http://127.0.0.1:$PORT/wp-admin/edit.php" \
  -o "$TMP/edit.html"
grep -F "$TITLE" "$TMP/edit.html" >/dev/null

"$BIN" branch --work-dir "$WORK" list | grep -F "feature-zfs" >/dev/null

echo "PASS zfs strategy e2e"

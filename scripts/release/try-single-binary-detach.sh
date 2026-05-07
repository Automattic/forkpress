#!/usr/bin/env bash
set -euo pipefail

TAG="${FORKPRESS_TEST_TAG:-v0.1.12-mac-cow.5}"
PORT="${FORKPRESS_TEST_PORT:-18780}"
ROOT="$(mktemp -d "${TMPDIR:-/tmp}/forkpress-single-binary.XXXXXX")"
WORK="$ROOT/site"
BIN="$ROOT/forkpress"

case "$(uname -m)" in
  arm64) TARGET="aarch64-apple-darwin" ;;
  x86_64) TARGET="x86_64-apple-darwin" ;;
  *)
    echo "unsupported Mac arch: $(uname -m)" >&2
    exit 1
    ;;
esac

ASSET="forkpress-${TARGET}.tar.gz"
URL="https://github.com/Automattic/forkpress/releases/download/${TAG}/${ASSET}"

cleanup() {
  "$BIN" stop --work-dir "$WORK/.forkpress" --force >/dev/null 2>&1 || true
}
trap cleanup EXIT

mkdir -p "$WORK"
cd "$ROOT"

curl -L -o "$ASSET" "$URL"
tar -xzf "$ASSET"
chmod +x "$BIN"

"$BIN" --version

cd "$WORK"

"$BIN" init
"$BIN" serve --port "$PORT" --root-host wp.localhost
"$BIN" branch create detach-test

curl -fsS -H "Host: detach-test.wp.localhost:$PORT" \
  "http://127.0.0.1:$PORT/" >/dev/null

"$BIN" stop

rm -rf .forkpress
test ! -e .forkpress

echo "PASS: single forkpress binary served, stopped, detached storage if needed, and .forkpress was removable."
echo "Demo dir: $ROOT"

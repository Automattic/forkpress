#!/usr/bin/env bash

set -euo pipefail

# Submit a signed macOS binary to Apple's notary service via
# `xcrun notarytool`, wait for the verdict, and pull the rejection log
# if it fails.
#
# Usage:
#   scripts/macos/notarize.sh <binary>
#
# Auth comes from the App Store Connect API key:
#   APP_STORE_CONNECT_API_KEY_KEY_ID    short alphanumeric key id
#   APP_STORE_CONNECT_API_KEY_ISSUER_ID UUID-shaped issuer id
#   APP_STORE_CONNECT_API_KEY_KEY       PEM body (literal `\n` between
#                                       lines accepted)
#
# notarytool only accepts `.zip`, `.pkg`, or `.dmg`, so the binary is
# ditto-zipped for submission. The notarization ticket is recorded
# against the binary's code-signature hash, so the raw binary passes
# Gatekeeper online lookups without stapling.

binary=""

usage() {
  printf "usage: %s <binary>\n" "${0##*/}"
}

while [ $# -gt 0 ]; do
  case "$1" in
    -h|--help) usage; exit 0 ;;
    -*) printf >&2 "unknown arg: %s\n" "$1"; usage >&2; exit 1 ;;
    *)
      if [ -n "$binary" ]; then
        printf >&2 "extra positional argument: %s\n" "$1"; usage >&2; exit 1
      fi
      binary="$1"; shift ;;
  esac
done

if [ -z "$binary" ]; then
  usage >&2; exit 1
fi
if [ ! -f "$binary" ]; then
  printf >&2 "binary not found: %s\n" "$binary"; exit 1
fi

key_id="${APP_STORE_CONNECT_API_KEY_KEY_ID-}"
issuer_id="${APP_STORE_CONNECT_API_KEY_ISSUER_ID-}"
key_pem="${APP_STORE_CONNECT_API_KEY_KEY-}"

if [ -z "$key_id" ] || [ -z "$issuer_id" ] || [ -z "$key_pem" ]; then
  printf >&2 "missing notarization auth: set APP_STORE_CONNECT_API_KEY_{KEY_ID,ISSUER_ID,KEY}\n"
  exit 1
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

p8="$work/AuthKey_${key_id}.p8"
# `%b` decodes literal `\n` escapes into real newlines. The trailing
# newline matters — notarytool rejects PEM without it as
# `invalidPEMDocument`.
printf '%b\n' "$key_pem" > "$p8"
chmod 600 "$p8"

zip_path="$work/$(basename "$binary").zip"
printf "==> ditto-zipping %s for submission\n" "$binary"
ditto -c -k "$binary" "$zip_path"

printf "==> submitting to notarytool (this can take a few minutes)\n"
submit_json="$work/submit.json"
xcrun notarytool submit "$zip_path" \
  --key "$p8" \
  --key-id "$key_id" \
  --issuer "$issuer_id" \
  --wait \
  --output-format json \
  > "$submit_json"

cat "$submit_json"
printf "\n"

status="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["status"])' "$submit_json")"
submission_id="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["id"])' "$submit_json")"

if [ "$status" != "Accepted" ]; then
  printf >&2 "==> notarization status: %s — fetching log\n" "$status"
  xcrun notarytool log "$submission_id" \
    --key "$p8" --key-id "$key_id" --issuer "$issuer_id"
  exit 1
fi

printf "==> notarization accepted (id=%s)\n" "$submission_id"

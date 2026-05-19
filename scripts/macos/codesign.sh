#!/usr/bin/env bash

set -euo pipefail

# Codesign a macOS binary with a Developer ID Application identity +
# hardened runtime + secure timestamp.
#
# Usage:
#   scripts/macos/codesign.sh <binary>
#                             [--team-id TEAM]
#                             [--entitlements FILE]
#                             [--identity 'Developer ID Application: ...']
#
# Identity is resolved from the codesigning keychain by team id.
# FORKPRESS_CODESIGN_IDENTITY overrides the lookup.

team_id="PZYM8XX95Q"
entitlements=""
identity="${FORKPRESS_CODESIGN_IDENTITY:-}"
binary=""

usage() {
  printf "usage: %s <binary> [--team-id TEAM] [--entitlements FILE] [--identity IDENTITY]\n" "${0##*/}"
}

while [ $# -gt 0 ]; do
  case "$1" in
    --team-id)
      [ $# -ge 2 ] || { printf >&2 "missing value for --team-id\n"; exit 1; }
      team_id="$2"; shift 2 ;;
    --entitlements)
      [ $# -ge 2 ] || { printf >&2 "missing value for --entitlements\n"; exit 1; }
      entitlements="$2"; shift 2 ;;
    --identity)
      [ $# -ge 2 ] || { printf >&2 "missing value for --identity\n"; exit 1; }
      identity="$2"; shift 2 ;;
    -h|--help)
      usage; exit 0 ;;
    -*)
      printf >&2 "unknown arg: %s\n" "$1"; usage >&2; exit 1 ;;
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

if [ -z "$identity" ]; then
  identity="$(security find-identity -v -p codesigning | awk -v team="(${team_id})" '
    /Developer ID Application:/ && index($0, team) {
      sub(/^[^"]*"/, "")
      sub(/"[^"]*$/, "")
      print
      exit
    }
  ')"
fi
if [ -z "$identity" ]; then
  printf >&2 "no Developer ID Application identity for team %s in keychain\n" "$team_id"
  printf >&2 "(set FORKPRESS_CODESIGN_IDENTITY=... or pass --identity to override)\n"
  exit 1
fi

printf "==> codesigning %s\n    identity: %s\n" "$binary" "$identity"

codesign_args=(--force --options runtime --timestamp --sign "$identity")
if [ -n "$entitlements" ]; then
  if [ ! -f "$entitlements" ]; then
    printf >&2 "entitlements file not found: %s\n" "$entitlements"; exit 1
  fi
  codesign_args+=(--entitlements "$entitlements")
fi

codesign "${codesign_args[@]}" "$binary"

printf "==> verifying signature\n"
codesign --verify --strict --verbose=2 "$binary"
codesign --display --verbose=2 "$binary" 2>&1 \
  | grep -E 'Authority|TeamIdentifier|Signature|flags|Identifier' || true

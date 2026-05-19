#!/usr/bin/env bash

set -euo pipefail

# Sign a macOS binary with Developer ID + hardened runtime, then
# submit it to Apple's notary service.
#
# Usage:
#   scripts/macos/sign-and-notarize.sh <binary>
#                                     [--team-id TEAM]
#                                     [--entitlements FILE]
#                                     [--identity 'Developer ID Application: ...']
#                                     [--skip-notarize]
#
# Entitlements default to `entitlements.plist` next to this script if
# it exists. `--skip-notarize` (or `FORKPRESS_SKIP_NOTARIZE=1`) signs
# but skips the notarytool round-trip.

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

team_id=""
identity=""
skip_notarize="${FORKPRESS_SKIP_NOTARIZE:-0}"
binary=""

# macOS ships bash 3.2 where empty arrays plus `set -u` blow up on
# `"${arr[@]}"`. Build a single args array — starting from the
# always-present binary — instead of stitching optional sub-arrays
# together at the call site.
entitlements="$here/entitlements.plist"

usage() {
  printf "usage: %s <binary> [--team-id TEAM] [--entitlements FILE] [--identity IDENTITY] [--skip-notarize]\n" "${0##*/}"
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
    --skip-notarize)
      skip_notarize=1; shift ;;
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

codesign_args=("$binary")
if [ -n "$team_id" ]; then
  codesign_args+=(--team-id "$team_id")
fi
if [ -n "$entitlements" ] && [ -f "$entitlements" ]; then
  codesign_args+=(--entitlements "$entitlements")
fi
if [ -n "$identity" ]; then
  codesign_args+=(--identity "$identity")
fi

"$here/codesign.sh" "${codesign_args[@]}"

case "$skip_notarize" in
  1|true|yes|on)
    printf "==> FORKPRESS_SKIP_NOTARIZE set — skipping notarytool submission\n"
    exit 0
    ;;
esac

"$here/notarize.sh" "$binary"

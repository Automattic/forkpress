#!/usr/bin/env bash

set -euo pipefail

TARGET=x86_64-apple-darwin

# shellcheck source=_lib/heartbeat.sh
source "$(dirname "$0")/_lib/heartbeat.sh"

require_release_grade_step() {
  local label="$1"
  shift

  if run_with_heartbeat "$label" "$@"; then
    return
  fi

  echo "ERROR: $label failed; refusing to build a non-release-grade $TARGET runtime." >&2
  exit 1
}

echo "--- :beer: Installing macOS runtime build tools ($TARGET)"
require_release_grade_step "install macOS runtime build tools ($TARGET)" bash scripts/dev/install-macos-runtime-tools.sh "$TARGET"

echo "--- :hammer: Pre-running spc doctor --auto-fix ($TARGET)"
# shellcheck source=_lib/spc-doctor-prerun.sh
source "$(dirname "$0")/_lib/spc-doctor-prerun.sh"
require_release_grade_step "spc doctor $TARGET" spc_doctor_prerun "$TARGET"

echo "--- :package: Building static PHP runtime bundle ($TARGET)"
run_with_heartbeat "build static PHP runtime bundle ($TARGET)" env FORKPRESS_TARGET="$TARGET" scripts/build-dist.sh

ls -lh "dist/$TARGET/bin/php"

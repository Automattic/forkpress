#!/usr/bin/env bash

set -euo pipefail

TARGET=x86_64-apple-darwin

# shellcheck source=_lib/heartbeat.sh
source "$(dirname "$0")/_lib/heartbeat.sh"

allow_pr_fallback() {
  [ "${BUILDKITE_PULL_REQUEST:-false}" != "false" ] && [ "${FORKPRESS_ALLOW_MAC_X86_SMOKE_FALLBACK:-0}" = "1" ]
}

run_empty_runtime_smoke_build() {
  echo "--- :warning: Falling back to empty-runtime x86_64 smoke build"
  echo "Release-grade x86_64 artifacts require Rosetta plus Intel Homebrew on the mac agent."
  echo "This fallback is allowed only for pull request validation; non-PR builds fail instead."

  echo "--- :crab: cargo build --release forkpress ($TARGET, empty runtime)"
  run_with_heartbeat "cargo build forkpress ($TARGET, empty runtime)" env FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo build --release --target "$TARGET" -p forkpress-cli --bin forkpress --locked

  ls -lh "target/$TARGET/release/forkpress"
  file "target/$TARGET/release/forkpress" || true

  # shellcheck source=_lib/setup-fastlane.sh
  source "$(dirname "$0")/_lib/setup-fastlane.sh"

  echo "--- :lock: Codesigning forkpress ($TARGET)"
  run_with_heartbeat "codesign forkpress ($TARGET)" bundle exec fastlane sign_binary binary:"target/$TARGET/release/forkpress"
}

require_release_grade_step() {
  local label="$1"
  shift

  if "$@"; then
    return
  fi

  if allow_pr_fallback; then
    echo "WARN: $label failed; using pull-request-only smoke fallback." >&2
    run_empty_runtime_smoke_build
    exit 0
  fi

  echo "ERROR: $label failed; refusing to upload a non-release-grade $TARGET artifact." >&2
  exit 1
}

echo "--- :crab: Installing Rust via rustup"
(
  while true; do
    sleep 60
    echo "--- :hourglass_flowing_sand: Still running: install Rust toolchain ($TARGET)"
  done
) &
rust_heartbeat_pid=$!
rust_status=0
{
  # shellcheck source=_lib/install-rust.sh
  source "$(dirname "$0")/_lib/install-rust.sh"
  rustup target add "$TARGET"
} || rust_status=$?
kill "$rust_heartbeat_pid" >/dev/null 2>&1 || true
wait "$rust_heartbeat_pid" >/dev/null 2>&1 || true
if [ "$rust_status" -ne 0 ]; then
  exit "$rust_status"
fi

echo "--- :package: Downloading static PHP runtime bundle ($TARGET)"
require_release_grade_step "Downloading static PHP runtime bundle" run_with_heartbeat "download static PHP runtime bundle ($TARGET)" buildkite-agent artifact download "dist/$TARGET/**" . --step mac-x86_64-runtime
ls -lh "dist/$TARGET/bin/php"

echo "--- :crab: cargo build --release forkpress ($TARGET)"
run_with_heartbeat "cargo build forkpress ($TARGET)" cargo build --release --target "$TARGET" -p forkpress-cli --bin forkpress --locked

ls -lh "target/$TARGET/release/forkpress"
file "target/$TARGET/release/forkpress" || true

# shellcheck source=_lib/setup-fastlane.sh
source "$(dirname "$0")/_lib/setup-fastlane.sh"

echo "--- :lock: Codesigning forkpress ($TARGET)"
run_with_heartbeat "codesign forkpress ($TARGET)" bundle exec fastlane sign_binary binary:"target/$TARGET/release/forkpress"

echo "--- :test_tube: forkpress smoke ($TARGET)"
run_with_heartbeat "forkpress smoke ($TARGET)" arch -x86_64 "target/$TARGET/release/forkpress" --version

echo "--- :cow: Skipping COW e2e; covered by GitHub Actions mac-cow-e2e ($TARGET)"

if [ "${FORKPRESS_SKIP_NOTARIZE:-0}" = "1" ]; then
  echo "--- :apple: Skipping notarization (FORKPRESS_SKIP_NOTARIZE=1)"
else
  echo "--- :apple: Notarizing forkpress ($TARGET)"
  run_with_heartbeat "notarize forkpress ($TARGET)" bundle exec fastlane notarize_binary binary:"target/$TARGET/release/forkpress"
fi

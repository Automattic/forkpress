#!/usr/bin/env bash

set -euo pipefail

TARGET=x86_64-apple-darwin

echo "--- :crab: Installing Rust via rustup"
# shellcheck source=_lib/install-rust.sh
source "$(dirname "$0")/_lib/install-rust.sh"
rustup target add "$TARGET"

echo "--- :beer: Installing macOS runtime build tools ($TARGET)"
bash scripts/dev/install-macos-runtime-tools.sh "$TARGET"

echo "--- :hammer: Pre-running spc doctor --auto-fix ($TARGET)"
# shellcheck source=_lib/spc-doctor-prerun.sh
source "$(dirname "$0")/_lib/spc-doctor-prerun.sh"
spc_doctor_prerun "$TARGET"

echo "--- :package: Building static PHP runtime bundle ($TARGET)"
FORKPRESS_TARGET="$TARGET" scripts/build-dist.sh

echo "--- :crab: cargo build --release forkpress ($TARGET)"
cargo build --release --target "$TARGET" -p forkpress-cli --bin forkpress --locked

ls -lh "target/$TARGET/release/forkpress"
file "target/$TARGET/release/forkpress" || true

# shellcheck source=_lib/setup-fastlane.sh
source "$(dirname "$0")/_lib/setup-fastlane.sh"

echo "--- :lock: Codesigning forkpress ($TARGET)"
bundle exec fastlane sign_binary binary:"target/$TARGET/release/forkpress"

echo "--- :test_tube: forkpress smoke ($TARGET)"
arch -x86_64 "target/$TARGET/release/forkpress" --version

echo "--- :cow: COW strategy e2e (APFS sparsebundle, $TARGET)"
FORKPRESS_FORCE_MACOS_APFS_SPARSEBUNDLE=1 tests/cow/e2e.sh "target/$TARGET/release/forkpress"

if [ "${FORKPRESS_SKIP_NOTARIZE:-0}" = "1" ]; then
  echo "--- :apple: Skipping notarization (FORKPRESS_SKIP_NOTARIZE=1)"
else
  echo "--- :apple: Notarizing forkpress ($TARGET)"
  bundle exec fastlane notarize_binary binary:"target/$TARGET/release/forkpress"
fi

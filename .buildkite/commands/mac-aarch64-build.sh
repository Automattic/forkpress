#!/usr/bin/env bash

set -euo pipefail

TARGET=aarch64-apple-darwin

echo "--- :crab: Installing Rust via rustup"
# shellcheck source=_lib/install-rust.sh
source "$(dirname "$0")/_lib/install-rust.sh"
rustup target add "$TARGET" || true

echo "--- :beer: Installing macOS runtime build tools"
# Re-run here because BK steps may land on a different mac agent than
# the one that ran `mac-aarch64-tests`; the runtime tooling is idempotent.
bash scripts/dev/install-macos-runtime-tools.sh
brew list node >/dev/null 2>&1 || brew install node

echo "--- :hammer: Pre-running spc doctor --auto-fix"
# shellcheck source=_lib/spc-doctor-prerun.sh
source "$(dirname "$0")/_lib/spc-doctor-prerun.sh"
spc_doctor_prerun "$TARGET"

echo "--- :package: Building static PHP runtime bundle ($TARGET)"
FORKPRESS_TARGET="$TARGET" scripts/build-dist.sh

echo "--- :crab: cargo build --release forkpress ($TARGET)"
cargo build --release --target "$TARGET" -p forkpress-cli --bin forkpress --locked

echo "--- :cow: COW strategy e2e (APFS sparsebundle)"
FORKPRESS_FORCE_MACOS_APFS_SPARSEBUNDLE=1 tests/cow/e2e.sh "target/$TARGET/release/forkpress"

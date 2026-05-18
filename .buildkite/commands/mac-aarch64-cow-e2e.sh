#!/usr/bin/env bash

set -euo pipefail

# Mirrors GHA `mac-cow-e2e` (aarch64-apple-darwin matrix entry) end-to-end:
#   - cargo unit tests (workspace excl. CLI; forkpress-core dev; CLI with
#     FORKPRESS_RUNTIME_BUNDLE=/dev/null; forkpress-dev with same)
#   - make test-release
#   - brew-install runtime build tools via scripts/dev/install-macos-runtime-tools.sh
#   - make test-cow-fast
#   - scripts/build-dist.sh (static PHP runtime bundle, ~5-10 min)
#   - cargo build --release forkpress for aarch64-apple-darwin
#   - tests/cow/e2e.sh through an APFS sparsebundle volume
#
# Run on the BK `mac` queue (Apple Silicon VM). No Docker — native execution
# inside the xcode-{IMAGE_ID} image. Caching is out of scope; static PHP
# rebuilds from scratch each run.

TARGET=aarch64-apple-darwin

echo "--- :information_source: Host"
uname -a
sw_vers || true

echo "--- :crab: Installing Rust via rustup"
if ! command -v cargo >/dev/null 2>&1; then
  curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --default-toolchain stable --profile minimal
fi
# shellcheck disable=SC1091
source "$HOME/.cargo/env"
rustup target add "$TARGET" || true
rustc --version
cargo --version

echo "--- :crab: cargo test (workspace, excluding forkpress-cli)"
cargo test --target "$TARGET" --workspace --exclude forkpress-cli --locked

echo "--- :crab: cargo test -p forkpress-core --features dev-experiments"
cargo test --target "$TARGET" -p forkpress-core --features dev-experiments --locked

echo "--- :crab: cargo test -p forkpress-cli --bin forkpress (external runtime)"
FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test --target "$TARGET" -p forkpress-cli --bin forkpress --locked

echo "--- :crab: cargo test -p forkpress-cli --features dev-experiments --bin forkpress-dev"
FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test --target "$TARGET" -p forkpress-cli --features dev-experiments --bin forkpress-dev --locked

echo "--- :package: make test-release"
make test-release

echo "--- :beer: Installing macOS runtime build tools"
bash scripts/dev/install-macos-runtime-tools.sh

echo "--- :cow: make test-cow-fast"
make test-cow-fast

echo "--- :package: Building static PHP runtime bundle ($TARGET)"
FORKPRESS_TARGET="$TARGET" scripts/build-dist.sh

echo "--- :crab: cargo build --release forkpress ($TARGET)"
cargo build --release --target "$TARGET" -p forkpress-cli --bin forkpress --locked

echo "--- :cow: COW strategy e2e (APFS sparsebundle)"
FORKPRESS_FORCE_MACOS_APFS_SPARSEBUNDLE=1 tests/cow/e2e.sh "target/$TARGET/release/forkpress"

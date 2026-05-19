#!/usr/bin/env bash

set -euo pipefail

# Mac aarch64 tests chunk of GHA `mac-cow-e2e` (aarch64-apple-darwin
# matrix entry):
#   - cargo unit tests (workspace excl. CLI; forkpress-core dev; CLI with
#     FORKPRESS_RUNTIME_BUNDLE=/dev/null; forkpress-dev with same)
#   - make test-release
#   - brew-install runtime build tools via scripts/dev/install-macos-runtime-tools.sh
#   - make test-cow-fast
#
# The heavier static-PHP build + cow-e2e lives in `mac-aarch64-build.sh`,
# gated on this step passing.
#
# Runs on the BK `mac` queue (Apple Silicon VM). No Docker — native
# execution inside the xcode-{IMAGE_ID} image. Caching is out of scope.

TARGET=aarch64-apple-darwin

echo "--- :information_source: Host"
uname -a
sw_vers || true

echo "--- :crab: Installing Rust via rustup"
# shellcheck source=_lib/install-rust.sh
source "$(dirname "$0")/_lib/install-rust.sh"
rustup target add "$TARGET" || true

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
# `tests/cow/e2e.sh` (in the build step) shells out to `node` to parse
# the WP-admin HTML and pull the nonce out of the inline literal. The
# GHA `macos-14` runner ships node by default; the BK mac VM doesn't.
brew list node >/dev/null 2>&1 || brew install node

echo "--- :cow: make test-cow-fast"
make test-cow-fast

#!/usr/bin/env bash

set -euo pipefail

TARGET=aarch64-apple-darwin

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

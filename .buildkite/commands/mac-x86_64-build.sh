#!/usr/bin/env bash

set -euo pipefail

# a8c BK has no Intel mac queue, so we cross-compile from Apple Silicon.
# The output isn't end-to-end testable here (no Rosetta) — it's a build-
# path smoke check, not a shippable binary; the release pipeline owns
# the real Intel binary with embedded runtime. FORKPRESS_RUNTIME_BUNDLE=
# /dev/null is needed because spc can't cleanly cross-build PHP from
# aarch64.

TARGET=x86_64-apple-darwin

echo "--- :crab: Installing Rust via rustup"
# shellcheck source=_lib/install-rust.sh
source "$(dirname "$0")/_lib/install-rust.sh"
rustup target add "$TARGET"

echo "--- :crab: cargo build --release forkpress ($TARGET, empty runtime)"
FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo build --release --target "$TARGET" -p forkpress-cli --bin forkpress --locked

ls -lh "target/$TARGET/release/forkpress"
file "target/$TARGET/release/forkpress" || true

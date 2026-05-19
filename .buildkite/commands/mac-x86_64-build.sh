#!/usr/bin/env bash

set -euo pipefail

# x86_64-apple-darwin cross-build smoke artifact.
#
# GHA runs the cow-e2e matrix on a real `macos-15-intel` runner. a8c BK
# has no Intel mac queue, so we cross-compile from an Apple Silicon
# runner instead. The resulting binary can't be exercised end-to-end on
# the same VM (Rosetta is not installed), so this step is build-only:
#
#   - `rustup target add x86_64-apple-darwin`
#   - `cargo build --release --target x86_64-apple-darwin` with
#     `FORKPRESS_RUNTIME_BUNDLE=/dev/null` — skips the static-PHP embed
#     so we don't have to cross-build PHP from aarch64 (spc doesn't
#     support that cleanly).
#
# The output binary is uploaded via `artifact_paths` in pipeline.yml.
# It's a CI smoke artifact, not a shippable binary; release-publish
# still emits the real Intel binary with embedded runtime.
#
# Gated on `mac-aarch64-tests` passing — the cargo workspace tests are
# arch-agnostic enough that a green aarch64 test run is a strong
# predictor of x86_64 compile health.

TARGET=x86_64-apple-darwin

echo "--- :information_source: Host"
uname -a
sw_vers || true

echo "--- :crab: Installing Rust via rustup"
# shellcheck source=_lib/install-rust.sh
source "$(dirname "$0")/_lib/install-rust.sh"
rustup target add "$TARGET"

echo "--- :crab: cargo build --release forkpress ($TARGET, empty runtime)"
# Empty runtime bundle: build.rs of forkpress-cli sees FORKPRESS_RUNTIME_BUNDLE
# pointing at an empty/missing file and skips the static-PHP embed,
# stamping the binary with an "external" runtime ID. The artifact is a
# build-path sanity check, not a runnable binary.
FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo build --release --target "$TARGET" -p forkpress-cli --bin forkpress --locked

ls -lh "target/$TARGET/release/forkpress"
file "target/$TARGET/release/forkpress" || true

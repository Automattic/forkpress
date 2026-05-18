#!/usr/bin/env bash

set -euo pipefail

# Mirrors the first cargo-test invocations from `linux-cow-e2e` in
# `.github/workflows/ci.yml`. `forkpress-cli` is excluded; its tests need
# `FORKPRESS_RUNTIME_BUNDLE=/dev/null` to skip the static PHP build.rs path
# and land in a follow-up step once we have a cargo cache.

echo "--- :information_source: Toolchain"
rustc --version
cargo --version

echo "--- :crab: cargo test (workspace, excluding forkpress-cli)"
cargo test --workspace --exclude forkpress-cli --locked

echo "--- :crab: cargo test -p forkpress-core --features dev-experiments"
cargo test -p forkpress-core --features dev-experiments --locked

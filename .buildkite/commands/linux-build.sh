#!/usr/bin/env bash

set -euo pipefail

# Walking skeleton for the Linux build step on Buildkite.
# Mirrors the first cargo invocation in `.github/workflows/ci.yml`'s
# `linux-cow-e2e` job:
#   cargo build --workspace --exclude forkpress-cli
#
# `forkpress-cli` is excluded because its build.rs needs the static PHP runtime
# bundle produced by `scripts/build-dist.sh` (3-5 minutes of compilation). That
# heavier path lands in a follow-up step, gated on a cache and the full musl
# toolchain. For now, we just want push -> Linux agent -> cargo to succeed
# end-to-end so we know the Docker plugin + Rust image scaffolding works.

echo "--- :information_source: Toolchain"
rustc --version
cargo --version

echo "--- :crab: cargo build (workspace, excluding forkpress-cli)"
cargo build --workspace --exclude forkpress-cli --locked

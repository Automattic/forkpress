#!/usr/bin/env bash

set -euo pipefail

# Mirrors the heavy chunk of GHA `linux-cow-e2e`:
#   - install full build toolchain (Rust target, musl, static-PHP deps)
#   - scripts/build-dist.sh   (static PHP runtime bundle, 3-5 min)
#   - cargo build --release   (forkpress for x86_64-unknown-linux-musl)
#   - tests/cow/e2e.sh        (COW strategy end-to-end against the binary)
# We're root inside the rust:1.95-bookworm container so apt works without
# sudo. Caching is intentionally absent for now; the static PHP compile pays
# the full 3-5 min cost on every build.

TARGET=x86_64-unknown-linux-musl

echo "--- :package: Installing build deps"
apt-get update -qq
# rust:1.95-bookworm (buildpack-deps base) already has build-essential, clang,
# curl, git, pkg-config, unzip. Add the rest that build-dist.sh and the musl
# linker want.
apt-get install -y --no-install-recommends \
  automake autopoint bison cmake composer flex musl-tools nodejs \
  php-cli php-sqlite3 re2c \
  >/dev/null
php --version | head -1

echo "--- :crab: rustup target add $TARGET"
rustup target add "$TARGET"

echo "--- :package: Building static PHP runtime bundle ($TARGET)"
FORKPRESS_TARGET="$TARGET" scripts/build-dist.sh

echo "--- :crab: cargo build --release ($TARGET)"
cargo build --release --target "$TARGET" -p forkpress-cli --bin forkpress --locked

echo "--- :cow: COW strategy e2e"
tests/cow/e2e.sh "target/$TARGET/release/forkpress"

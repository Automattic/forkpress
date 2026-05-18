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

# static-php-cli's `doctor` runs two musl checks that fail on a stock Debian
# host and would otherwise drop to an interactive "Do you want to fix it?"
# prompt the BK agent can't answer. `tests/release/build-dist-preflight.sh`
# forbids passing `--auto-fix` to `doctor`, so we install both upfront with
# the same commands `doctor` would run:
#
#   1. musl-wrapper: musl-1.2.5 built from source, installed to /usr/local/musl
#   2. musl-cross-make: prebuilt cross toolchain tarball from static-php.dev
#
# On GHA `ubuntu-24.04` these are restored from the `.build/` cache;
# we don't cache yet, so we pay the install cost each run.
echo "--- :hammer: Installing musl-wrapper (musl 1.2.5)"
if [ ! -f /usr/local/musl/lib/libc.a ]; then
  tmpdir="$(mktemp -d)"
  curl -sSLo "$tmpdir/musl-1.2.5.tar.gz" https://musl.libc.org/releases/musl-1.2.5.tar.gz
  tar -xzf "$tmpdir/musl-1.2.5.tar.gz" -C "$tmpdir"
  (
    cd "$tmpdir/musl-1.2.5"
    CC=gcc CXX=g++ AR=ar LD=ld ./configure --disable-gcc-wrapper >/dev/null
    CC=gcc CXX=g++ AR=ar LD=ld make -j"$(nproc)" >/dev/null
    CC=gcc CXX=g++ AR=ar LD=ld make install >/dev/null
  )
  rm -rf "$tmpdir"
else
  echo "/usr/local/musl/lib/libc.a already present"
fi

echo "--- :hammer: Installing musl-cross-make (prebuilt toolchain)"
if [ ! -f /usr/local/musl/bin/x86_64-linux-musl-gcc ]; then
  mkdir -p /usr/local/musl
  curl -sSLo /tmp/x86_64-musl-toolchain.tgz \
    https://dl.static-php.dev/static-php-cli/deps/musl-toolchain/x86_64-musl-toolchain.tgz
  tar -xzf /tmp/x86_64-musl-toolchain.tgz -C /usr/local/musl
  rm -f /tmp/x86_64-musl-toolchain.tgz
else
  echo "/usr/local/musl/bin/x86_64-linux-musl-gcc already present"
fi

echo "--- :package: Building static PHP runtime bundle ($TARGET)"
FORKPRESS_TARGET="$TARGET" scripts/build-dist.sh

echo "--- :crab: cargo build --release ($TARGET)"
cargo build --release --target "$TARGET" -p forkpress-cli --bin forkpress --locked

echo "--- :cow: COW strategy e2e"
tests/cow/e2e.sh "target/$TARGET/release/forkpress"

#!/usr/bin/env bash

set -euo pipefail

# shellcheck source=_lib/docker-chown-trap.sh
source "$(dirname "$0")/_lib/docker-chown-trap.sh"

# rust:1.95-trixie runs as root (so no sudo) but doesn't ship PHP; install
# it for `make test-cow-fast`'s sqlite-backed suite. ~15s per build.
echo "--- :package: Installing PHP"
apt-get update -qq
apt-get install -y --no-install-recommends php-cli php-sqlite3 >/dev/null
php --version | head -1

echo "--- :crab: cargo test (workspace, excluding forkpress-cli)"
cargo test --workspace --exclude forkpress-cli --locked

echo "--- :crab: cargo test -p forkpress-core --features dev-experiments"
cargo test -p forkpress-core --features dev-experiments --locked

# `FORKPRESS_RUNTIME_BUNDLE=/dev/null` tells the forkpress-cli build.rs to skip
# the static PHP dist bundle path (3-5 min static-PHP compile) and stamp the
# binary with an "external" runtime ID instead. Tests still exercise the CLI's
# Rust surface; they just don't embed a real runtime archive.
echo "--- :crab: cargo test -p forkpress-cli --bin forkpress (external runtime)"
FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli --bin forkpress --locked

echo "--- :crab: cargo test -p forkpress-cli --features dev-experiments --bin forkpress-dev"
FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli --features dev-experiments --bin forkpress-dev --locked

echo "--- :package: make test-release"
make test-release

echo "--- :cow: make test-cow-fast"
make test-cow-fast

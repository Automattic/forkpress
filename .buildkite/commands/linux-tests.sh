#!/usr/bin/env bash

set -euo pipefail

# We run as root inside the docker container; the BK agent on the host runs
# as the unprivileged `buildkite-agent` user and later tries to clean up our
# mounted workspace. Without this chown the agent's cleanup fails ("permission
# denied" on every cargo-emitted file) and the next checkout on the same
# agent loops forever. Chown the workspace back to whoever owns it on the
# host (the agent user) on exit so cleanup succeeds.
host_uid="$(stat -c %u .)"
host_gid="$(stat -c %g .)"
trap 'chown -R "$host_uid:$host_gid" . 2>/dev/null || true' EXIT

# Mirrors the cargo-test invocations from `linux-cow-e2e` in
# `.github/workflows/ci.yml` that don't require the static PHP runtime bundle.
# `make test-cow-fast` (PHP test suite) and the heavier production-build /
# COW-e2e chunks land in follow-up steps with their own image / cache setup.

# We're root inside the `rust:1.95-bookworm` container, so apt-get works
# without sudo. The image doesn't ship PHP; install it so `make test-cow-fast`
# can run its sqlite-backed PHP test suite. ~15s overhead per build.
echo "--- :package: Installing PHP"
apt-get update -qq
apt-get install -y --no-install-recommends php-cli php-sqlite3 >/dev/null
php --version | head -1

echo "--- :information_source: Toolchain"
rustc --version
cargo --version
make --version | head -1

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

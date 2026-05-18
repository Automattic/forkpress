#!/usr/bin/env bash

set -euo pipefail

# We run as root inside the docker container; the BK agent on the host runs
# as the unprivileged `buildkite-agent` user and later tries to clean up our
# mounted workspace. Without this chown the agent's cleanup fails ("permission
# denied" on every static-PHP-cli artifact) and the next checkout on the same
# agent loops forever. Chown back to the host owner on exit.
host_uid="$(stat -c %u .)"
host_gid="$(stat -c %g .)"
trap 'chown -R "$host_uid:$host_gid" . 2>/dev/null || true' EXIT

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

# `scripts/build-dist.sh` (correctly) refuses to pass `--auto-fix` to
# static-php-cli's `doctor` — `tests/release/build-dist-preflight.sh`
# enforces that policy so the operator stays in charge of the tooling
# baked into the release artifact. The "operator" on BK is this CI script,
# so we pre-clone the same spc revision into the same `BUILD_DIR` the
# release script will use and run `doctor --auto-fix` here. By the time
# `build-dist.sh` runs its own `doctor` invocation, every prereq it would
# ask about (musl-wrapper, musl-cross-make, pkg-config, ...) is already
# installed, so no interactive prompt is reached.
echo "--- :hammer: Pre-running spc doctor --auto-fix"
# Keep this in sync with `SPC_REF` in `scripts/build-dist.sh`. If it drifts,
# build-dist.sh will fetch+checkout the right ref afterward, but the doctor
# we run here might be from an older spc revision. Loose coupling, not strict.
SPC_REF="8d038f435da7845926ba425dfbae0278cd0e0746"
BUILD_DIR=".build/$TARGET"
SPC_DIR="$BUILD_DIR/static-php-cli"
mkdir -p "$BUILD_DIR"
if [ ! -d "$SPC_DIR/.git" ]; then
  git clone --no-checkout https://github.com/crazywhalecc/static-php-cli.git "$SPC_DIR"
fi
git -C "$SPC_DIR" fetch --depth 1 origin "$SPC_REF"
git -C "$SPC_DIR" checkout --detach FETCH_HEAD
(
  cd "$SPC_DIR"
  composer install --no-dev --no-interaction --quiet
  ./bin/spc doctor --auto-fix
)

echo "--- :package: Building static PHP runtime bundle ($TARGET)"
FORKPRESS_TARGET="$TARGET" scripts/build-dist.sh

echo "--- :crab: cargo build --release ($TARGET)"
cargo build --release --target "$TARGET" -p forkpress-cli --bin forkpress --locked

echo "--- :cow: COW strategy e2e"
tests/cow/e2e.sh "target/$TARGET/release/forkpress"

# Dev variant: builds a second runtime bundle (with experimental BranchFS/CAS
# support), `forkpress-dev` binary, then exercises the content-addressable
# storage e2e suite.
echo "--- :package: Building dev PHP runtime bundle ($TARGET)"
FORKPRESS_RUNTIME_PROFILE=dev FORKPRESS_TARGET="$TARGET" scripts/build-dist.sh

echo "--- :crab: cargo build --release forkpress-dev ($TARGET)"
cargo build --release --target "$TARGET" -p forkpress-cli --features dev-experiments --bin forkpress-dev --locked

echo "--- :package: CAS strategy e2e (dev)"
experiments/cas/tests/e2e.sh "target/$TARGET/release/forkpress-dev"

#!/usr/bin/env bash

set -euo pipefail

# shellcheck source=_lib/docker-chown-trap.sh
source "$(dirname "$0")/_lib/docker-chown-trap.sh"
# shellcheck source=_lib/heartbeat.sh
source "$(dirname "$0")/_lib/heartbeat.sh"

# Root inside the rust:1.95-trixie container so apt works without sudo.
# Caching is intentionally absent for now; static PHP rebuilds (3-5 min)
# each run.

TARGET=x86_64-unknown-linux-musl

echo "--- :package: Installing build deps"
# rust:1.95-trixie (buildpack-deps base) already has build-essential, clang,
# curl, git, pkg-config, unzip. Add the rest that build-dist.sh and the musl
# linker want.
run_with_heartbeat "apt install build deps" bash -c 'apt-get update -qq && apt-get install -y --no-install-recommends automake autopoint bison cmake composer flex musl-tools nodejs php-cli php-sqlite3 re2c >/dev/null'
php --version | head -1

echo "--- :crab: rustup target add $TARGET"
run_with_heartbeat "rustup target add $TARGET" rustup target add "$TARGET"

echo "--- :hammer: Pre-running spc doctor --auto-fix"
# Production and dev runtime builds use separate BUILD_DIRs each with
# their own spc checkout. spc's doctor only finds pkg-config inside the
# local `PKG_ROOT_PATH/bin/`, never on `$PATH`, so doctor has to run
# inside each before build-dist.sh's own doctor invocation can complete
# non-interactively.
# shellcheck source=_lib/spc-doctor-prerun.sh
source "$(dirname "$0")/_lib/spc-doctor-prerun.sh"
for dist_name in "$TARGET" "$TARGET-dev"; do
  run_with_heartbeat "spc doctor $dist_name" spc_doctor_prerun "$dist_name"
done

echo "--- :package: Building static PHP runtime bundle ($TARGET)"
run_with_heartbeat "build production PHP runtime" env FORKPRESS_TARGET="$TARGET" scripts/build-dist.sh

echo "--- :crab: cargo build --release ($TARGET)"
run_with_heartbeat "cargo build forkpress" cargo build --release --target "$TARGET" -p forkpress-cli --bin forkpress --locked

echo "--- :cow: COW strategy e2e"
run_with_heartbeat "COW strategy e2e" tests/cow/e2e.sh "target/$TARGET/release/forkpress"

# Dev variant: second runtime bundle with experimental BranchFS/CAS
# support, plus the forkpress-dev binary and CAS e2e suite.
echo "--- :package: Building dev PHP runtime bundle ($TARGET)"
run_with_heartbeat "build dev PHP runtime" env FORKPRESS_RUNTIME_PROFILE=dev FORKPRESS_TARGET="$TARGET" scripts/build-dist.sh

echo "--- :crab: cargo build --release forkpress-dev ($TARGET)"
run_with_heartbeat "cargo build forkpress-dev" cargo build --release --target "$TARGET" -p forkpress-cli --features dev-experiments --bin forkpress-dev --locked

echo "--- :package: CAS strategy e2e (dev)"
run_with_heartbeat "CAS strategy e2e" experiments/cas/tests/e2e.sh "target/$TARGET/release/forkpress-dev"

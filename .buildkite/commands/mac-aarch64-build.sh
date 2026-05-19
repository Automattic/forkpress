#!/usr/bin/env bash

set -euo pipefail

# Mac aarch64 build chunk of GHA `mac-cow-e2e` (aarch64-apple-darwin):
#   - scripts/build-dist.sh  (static PHP runtime bundle, ~5-10 min)
#   - cargo build --release forkpress for aarch64-apple-darwin
#   - tests/cow/e2e.sh through an APFS sparsebundle volume
#
# Gated on `mac-aarch64-tests` passing. The built binary is uploaded by
# `artifact_paths` in pipeline.yml (runs regardless of step status).
#
# Runs on the BK `mac` queue. Native execution; no Docker. Caching is out
# of scope; static PHP rebuilds from scratch each run.

TARGET=aarch64-apple-darwin

echo "--- :information_source: Host"
uname -a
sw_vers || true

echo "--- :crab: Installing Rust via rustup"
if ! command -v cargo >/dev/null 2>&1; then
  curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --default-toolchain stable --profile minimal
fi
# shellcheck disable=SC1091
source "$HOME/.cargo/env"
rustup target add "$TARGET" || true

echo "--- :beer: Installing macOS runtime build tools"
# Re-run here because BK steps may land on a different mac agent than
# the one that ran `mac-aarch64-tests`; the runtime tooling is idempotent.
bash scripts/dev/install-macos-runtime-tools.sh
brew list node >/dev/null 2>&1 || brew install node

echo "--- :hammer: Pre-running spc doctor --auto-fix"
# `tests/release/build-dist-preflight.sh` forbids `--auto-fix` inside
# `scripts/build-dist.sh`, so we run doctor here from a matching spc
# checkout to install spc's local prereqs (pkg-config in
# `PKG_ROOT_PATH/bin/`) before build-dist.sh's own doctor runs.
SPC_REF="8d038f435da7845926ba425dfbae0278cd0e0746"
build_dir=".build/$TARGET"
spc_dir="$build_dir/static-php-cli"
mkdir -p "$build_dir"
if [ ! -d "$spc_dir/.git" ]; then
  git clone --no-checkout https://github.com/crazywhalecc/static-php-cli.git "$spc_dir"
fi
git -C "$spc_dir" fetch --depth 1 origin "$SPC_REF"
git -C "$spc_dir" checkout --detach FETCH_HEAD
(
  cd "$spc_dir"
  composer install --no-dev --no-interaction --quiet
  ./bin/spc doctor --auto-fix
)

echo "--- :package: Building static PHP runtime bundle ($TARGET)"
FORKPRESS_TARGET="$TARGET" scripts/build-dist.sh

echo "--- :crab: cargo build --release forkpress ($TARGET)"
cargo build --release --target "$TARGET" -p forkpress-cli --bin forkpress --locked

echo "--- :cow: COW strategy e2e (APFS sparsebundle)"
FORKPRESS_FORCE_MACOS_APFS_SPARSEBUNDLE=1 tests/cow/e2e.sh "target/$TARGET/release/forkpress"

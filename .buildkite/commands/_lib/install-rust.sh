# Install a stable Rust toolchain via rustup on a macOS BK runner.
# The xcode image doesn't ship rustup; install on demand and source
# the env. Idempotent — skips install when `cargo` is already on PATH.
#
# Usage:
#   source .buildkite/commands/_lib/install-rust.sh
#   rustup target add aarch64-apple-darwin

if ! command -v cargo >/dev/null 2>&1; then
  curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs \
    | sh -s -- -y --default-toolchain stable --profile minimal
fi
# shellcheck disable=SC1091
source "$HOME/.cargo/env"
rustc --version
cargo --version

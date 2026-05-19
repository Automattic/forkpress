# Pre-run `spc doctor --auto-fix` from a matching static-php-cli
# checkout to install spc's local prereqs (pkg-config in
# `PKG_ROOT_PATH/bin/`) before `scripts/build-dist.sh`'s own doctor
# runs. `tests/release/build-dist-preflight.sh` forbids `--auto-fix`
# inside `build-dist.sh` so the operator stays in charge of the
# tooling baked into the release artifact; on CI the operator *is*
# this script.
#
# Usage:
#   spc_doctor_prerun aarch64-apple-darwin
#   spc_doctor_prerun x86_64-unknown-linux-musl
#   spc_doctor_prerun x86_64-unknown-linux-musl-dev   # for FORKPRESS_RUNTIME_PROFILE=dev
#
# Keep `SPC_REF` here in sync with `SPC_REF` in `scripts/build-dist.sh`.
# Drift is non-fatal: build-dist.sh fetches+checks-out the right ref
# afterward, but doctor would have run against the older revision.

SPC_REF="${SPC_REF:-8d038f435da7845926ba425dfbae0278cd0e0746}"

spc_doctor_prerun() {
  local dist_name="$1"
  local build_dir=".build/$dist_name"
  local spc_dir="$build_dir/static-php-cli"

  echo "  → $spc_dir"
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
}

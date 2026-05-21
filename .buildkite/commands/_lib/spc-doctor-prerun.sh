# Pre-run `spc doctor --auto-fix` from a matching static-php-cli
# checkout to install spc's local prereqs (pkg-config in
# `PKG_ROOT_PATH/bin/`) before `scripts/build-dist.sh`'s own doctor
# runs. `tests/release/build-dist-preflight.sh` forbids `--auto-fix`
# inside `build-dist.sh` so the operator stays in charge of the
# tooling baked into the release artifact; on CI the operator *is*
# this script.
#
# Usage:
#   source .buildkite/commands/_lib/spc-doctor-prerun.sh
#   spc_doctor_prerun aarch64-apple-darwin
#   spc_doctor_prerun x86_64-unknown-linux-musl
#   spc_doctor_prerun x86_64-unknown-linux-musl-dev   # FORKPRESS_RUNTIME_PROFILE=dev
#
# The ref pin, the clone/refresh, and the composer install all come
# from `scripts/shared/static-php-cli.sh`, which `scripts/build-dist.sh`
# uses too — so the pre-run can't drift on flags or revision.

# shellcheck source=../../../../scripts/shared/static-php-cli.sh
source "$(dirname "${BASH_SOURCE[0]}")/../../../scripts/shared/static-php-cli.sh"

spc_doctor_prerun() {
  local dist_name="$1"
  local spc_dir=".build/$dist_name/static-php-cli"

  echo "  → $spc_dir"
  ensure_static_php_cli_checkout "$spc_dir"
  install_static_php_cli_composer_deps "$spc_dir"
  (
    cd "$spc_dir"
    ./bin/spc doctor --auto-fix
  )
}

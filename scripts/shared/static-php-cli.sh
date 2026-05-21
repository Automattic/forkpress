# Shared helpers for working with static-php-cli (spc).
#
# Source from any bash script that needs to clone, refresh, or
# composer-install spc against the forkpress-pinned ref. Both
# `scripts/build-dist.sh` and `.buildkite/commands/_lib/spc-doctor-prerun.sh`
# use these so the two flows can't drift on the ref or the composer
# flags.
#
# Exposes:
#   $SPC_REPO_URL                              — upstream remote
#   $SPC_REF                                   — pinned commit (overridable)
#   ensure_static_php_cli_checkout <spc_dir>   — clone/refresh checkout
#   install_static_php_cli_composer_deps <spc_dir>
#                                              — composer install spc's deps

SPC_REPO_URL='https://github.com/crazywhalecc/static-php-cli.git'
SPC_REF="${FORKPRESS_STATIC_PHP_CLI_REF:-8d038f435da7845926ba425dfbae0278cd0e0746}"

ensure_static_php_cli_checkout() {
  local spc_dir="$1"
  mkdir -p "$(dirname "$spc_dir")"
  if [ ! -d "$spc_dir/.git" ]; then
    rm -rf "$spc_dir"
    git clone --no-checkout "$SPC_REPO_URL" "$spc_dir"
  fi
  git -C "$spc_dir" fetch --depth 1 origin "$SPC_REF"
  git -C "$spc_dir" checkout --detach FETCH_HEAD
  git -C "$spc_dir" reset --hard FETCH_HEAD
  printf '%s\n' "$SPC_REF" > "$spc_dir/.forkpress-static-php-cli-ref"
}

install_static_php_cli_composer_deps() {
  local spc_dir="$1"
  # --no-dev drops spc's own dev tooling we never invoke.
  # --prefer-dist uses GH dist tarballs instead of full git clones.
  # --ignore-platform-reqs is required because spc's composer.lock
  #   pins PHP version constraints that float up to PHP 8.4 as
  #   upstream deps update; spc itself runs fine on PHP 8.3, which
  #   is the baseline we can rely on (ubuntu-24.04, macos-14 via
  #   brew). Without this flag, composer aborts on a host with PHP
  #   < 8.4.
  # --no-interaction so we never hang waiting on a CI tty.
  (
    cd "$spc_dir"
    composer install --no-dev --prefer-dist --ignore-platform-reqs --no-interaction
  )
}

#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$repo_root"

bash -n scripts/build-dist.sh

make -n test-release PHP_CONFIG= PHP_DEV_DIR= PKG_CONFIG= >/dev/null

branchfs_header_log="$(mktemp "${TMPDIR:-/tmp}/forkpress-branchfs-header-preflight.XXXXXX.log")"
fake_bin="$(mktemp -d "${TMPDIR:-/tmp}/forkpress-build-dist-preflight-bin.XXXXXX")"
build_dir="$(mktemp -d "${TMPDIR:-/tmp}/forkpress-build-dist-preflight-build.XXXXXX")"
dist_dir="$(mktemp -d "${TMPDIR:-/tmp}/forkpress-build-dist-preflight-dist.XXXXXX")"
out_file="$(mktemp "${TMPDIR:-/tmp}/forkpress-build-dist-preflight.XXXXXX.log")"
invalid_retries_log="$(mktemp "${TMPDIR:-/tmp}/forkpress-build-dist-preflight-retries.XXXXXX.log")"
trap 'rm -rf "$fake_bin" "$build_dir" "$dist_dir" "$out_file" "$invalid_retries_log" "$branchfs_header_log"' EXIT
chmod 755 "$fake_bin" "$build_dir" "$dist_dir"

set +e
make -n test-branchfs PHP_CONFIG= PHP_DEV_DIR= PKG_CONFIG= > "$branchfs_header_log" 2>&1
branchfs_header_status=$?
set -e
if [ "$branchfs_header_status" -eq 0 ]; then
  echo "expected branchfs targets to fail when PHP headers are missing" >&2
  cat "$branchfs_header_log" >&2
  exit 1
fi
grep -q 'Could not determine PHP headers' "$branchfs_header_log"

for cmd in bash dirname uname mkdir; do
  ln -s "$(command -v "$cmd")" "$fake_bin/$cmd"
done

set +e
PATH="$fake_bin" \
FORKPRESS_BUILD_DIR="$build_dir" \
FORKPRESS_DIST_DIR="$dist_dir" \
FORKPRESS_STATIC_PHP_CLI_DOWNLOAD_RETRIES=0 \
  scripts/build-dist.sh > "$invalid_retries_log" 2>&1
invalid_retries_status=$?
set -e

if [ "$invalid_retries_status" -eq 0 ]; then
  echo "expected build-dist preflight to reject zero static-php-cli download retries" >&2
  cat "$invalid_retries_log" >&2
  exit 1
fi

grep -q 'FORKPRESS_STATIC_PHP_CLI_DOWNLOAD_RETRIES must be at least 1' "$invalid_retries_log"

for cmd in git composer php re2c automake bison; do
  printf '#!/usr/bin/env sh\nexit 0\n' > "$fake_bin/$cmd"
  chmod 755 "$fake_bin/$cmd"
done

set +e
PATH="$fake_bin" \
FORKPRESS_BUILD_DIR="$build_dir" \
FORKPRESS_DIST_DIR="$dist_dir" \
  scripts/build-dist.sh > "$out_file" 2>&1
status=$?
set -e

if [ "$status" -eq 0 ]; then
  echo "expected build-dist preflight to fail when pkg-config is missing" >&2
  cat "$out_file" >&2
  exit 1
fi

grep -q 'missing static PHP build tools: pkg-config' "$out_file"
grep -q 'Refusing to let static-php-cli auto-install prerequisites' "$out_file"
if grep -q 'doctor --auto-fix' scripts/build-dist.sh; then
  echo "build-dist must not let static-php-cli auto-install prerequisites during release builds" >&2
  exit 1
fi
grep -q 'if: github.event.pull_request.head.repo.full_name == github.repository' .github/workflows/release-verify.yml
grep -q 'steps.metadata_release.outputs.version || steps.metadata_defaults.outputs.version' .github/workflows/release-verify.yml
grep -q "if: startsWith(github.event.pull_request.head.ref, 'release/v')" .github/workflows/release-verify.yml

grep -q 'TRIPLE" = "aarch64-apple-darwin"' scripts/build-dist.sh
grep -q 'arch -arm64 /usr/bin/true' scripts/build-dist.sh
grep -q 'SPC_RUN_UNDER_ARM64=1' scripts/build-dist.sh
grep -q 'arch -arm64 ./bin/spc "$@"' scripts/build-dist.sh
grep -q 'FORKPRESS_STATIC_PHP_CLI_DOWNLOAD_RETRIES:-3' scripts/build-dist.sh
grep -q 'run_spc_phase_with_retries "$SPC_DOWNLOAD_RETRIES" "download PHP and extension sources"' scripts/build-dist.sh
awk '
  /run_spc_phase\(\) \{/ { in_fn = 1 }
  in_fn && /if run_spc "\$@"; then/ { saw_if = 1 }
  in_fn && saw_if && /else/ { saw_else = 1 }
  in_fn && saw_else && /status=\$\?/ { saw_status = 1 }
  in_fn && /^  \}/ { in_fn = 0 }
  END { exit(saw_if && saw_else && saw_status ? 0 : 1) }
' scripts/build-dist.sh || {
  echo "build-dist must preserve failed static-php-cli exit statuses for retry handling" >&2
  exit 1
}
if grep -q 'SPC_RUN\[@\]' scripts/build-dist.sh; then
  echo "build-dist must not expand an empty bash array under macOS bash with set -u" >&2
  exit 1
fi

echo "build-dist preflight checks passed"

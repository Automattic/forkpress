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
trap 'rm -rf "$fake_bin" "$build_dir" "$dist_dir" "$out_file" "$branchfs_header_log"' EXIT
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

grep -q 'TRIPLE" = "aarch64-apple-darwin"' scripts/build-dist.sh
grep -q 'arch -arm64 /usr/bin/true' scripts/build-dist.sh

echo "build-dist preflight checks passed"

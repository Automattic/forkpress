#!/usr/bin/env bash

set -euo pipefail

echo "--- :information_source: PHP availability"
# The a8c BK Linux agent runs as `buildkite-agent` without passwordless sudo,
# so `apt-get install` is not an option here. The preflight script only invokes
# PHP when the change set actually touches `.php` files or COW patterns; for
# changes that don't, it is a no-op. A proper PHP toolchain (via the Docker
# plugin) is a follow-up so the script can cover the full set of changes.
if command -v php >/dev/null 2>&1; then
  php --version
else
  echo "php not installed; the preflight will skip PHP-bound checks."
fi

if [ "${BUILDKITE_PULL_REQUEST:-false}" != "false" ] && [ -n "${BUILDKITE_PULL_REQUEST_BASE_BRANCH:-}" ]; then
  base_ref="$BUILDKITE_PULL_REQUEST_BASE_BRANCH"
  echo "--- :git: Fetching base branch $base_ref"
  git fetch --force origin "${base_ref}:refs/remotes/origin/${base_ref}"
  export FORKPRESS_TEST_BASE="origin/${base_ref}"
else
  export FORKPRESS_TEST_BASE="HEAD^"
fi

export FORKPRESS_CHANGED_TEST_PLAN_SCOPE=cow

echo "--- :wrench: Running changed-file COW preflight"
scripts/dev/cow-changed-test-plan.sh

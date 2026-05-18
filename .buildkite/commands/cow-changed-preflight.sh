#!/usr/bin/env bash

set -euo pipefail

# Buildkite mirror of the `cow-changed-preflight` job from .github/workflows/ci.yml.
# Runs the same script with the same env vars; the only difference is how we
# discover the PR base branch (BUILDKITE_PULL_REQUEST_BASE_BRANCH vs github.base_ref).

echo "--- :package: Installing PHP"
sudo apt-get update
sudo apt-get install -y --no-install-recommends php-cli php-sqlite3

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

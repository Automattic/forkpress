#!/usr/bin/env bash

set -euo pipefail

# Mirrors GHA `docs / build`:
#   - npm ci
#   - npm run validate  (astro check + node --test + astro build)
#
# Runs on the BK `default` queue inside the docker-plugin node image. The
# GHA workflow filters on docs/asset paths; BK has no native path filter,
# so we run this on every build. Cost: ~30s npm ci + ~30s validate; cheap
# enough to skip the filter for now.
#
# `npm run validate` writes the built site into `docs-dist/`. We don't
# publish here — the GHA `deploy` job is GitHub-Pages-specific and stays
# in GHA. The built site is uploaded via YAML `artifact_paths` in
# pipeline.yml so reviewers can preview before merge.

# shellcheck source=_lib/docker-chown-trap.sh
source "$(dirname "$0")/_lib/docker-chown-trap.sh"

echo "--- :information_source: Toolchain"
node --version
npm --version

echo "--- :npm: npm ci"
npm ci --no-audit --no-fund

echo "--- :books: npm run validate"
ASTRO_TELEMETRY_DISABLED=1 npm run validate

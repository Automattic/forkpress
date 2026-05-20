#!/usr/bin/env bash

set -euo pipefail

# Runs on every build (~60s). BK has no native path filter and the cost
# is low enough not to bother with one for now. Publishing the built
# site is handled outside this pipeline.

# shellcheck source=_lib/docker-chown-trap.sh
source "$(dirname "$0")/_lib/docker-chown-trap.sh"

echo "--- :npm: npm ci"
npm ci --no-audit --no-fund

echo "--- :books: npm run validate"
ASTRO_TELEMETRY_DISABLED=1 npm run validate

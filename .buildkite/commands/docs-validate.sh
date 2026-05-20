#!/usr/bin/env bash

set -euo pipefail

# GHA filters this job on docs/asset paths; BK has no native path filter
# so we run on every build (~60s, cheap enough to skip filtering for
# now). Deploy stays in GHA — it's GitHub-Pages-specific.

# shellcheck source=_lib/docker-chown-trap.sh
source "$(dirname "$0")/_lib/docker-chown-trap.sh"

echo "--- :npm: npm ci"
npm ci --no-audit --no-fund

echo "--- :books: npm run validate"
ASTRO_TELEMETRY_DISABLED=1 npm run validate

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

# We're root inside the docker container; the BK agent on the host runs
# as the unprivileged `buildkite-agent` user. Without this chown, npm's
# extraction into `node_modules/` (gitignored) would leave root-owned
# files that the agent can't clean up on the next checkout. Chown back
# on exit so the next build's git-clean works.
host_uid="$(stat -c %u .)"
host_gid="$(stat -c %g .)"
trap 'chown -R "$host_uid:$host_gid" . 2>/dev/null || true' EXIT

echo "--- :information_source: Toolchain"
node --version
npm --version

echo "--- :npm: npm ci"
npm ci --no-audit --no-fund

echo "--- :books: npm run validate"
ASTRO_TELEMETRY_DISABLED=1 npm run validate

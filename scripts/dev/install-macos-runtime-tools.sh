#!/usr/bin/env bash
set -euo pipefail

retry() {
  local attempt=1
  local max_attempts=4
  local delay=10

  until "$@"; do
    if [ "$attempt" -ge "$max_attempts" ]; then
      return 1
    fi
    echo "command failed; retrying in ${delay}s: $*" >&2
    sleep "$delay"
    attempt=$((attempt + 1))
    delay=$((delay * 2))
  done
}

retry brew update
retry brew install composer gpatch automake re2c bison pkg-config

# static-php-cli uses the Homebrew php-cli while building the runtime bundle.
# GitHub runners usually have it already; keep this best-effort so an unrelated
# php reinstall problem does not hide the actual runtime build result.
brew list php >/dev/null 2>&1 || retry brew install php || true

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

target="${1:-${FORKPRESS_TARGET:-}}"
brew_cmd=(brew)
if [ "$(uname -s)" = "Darwin" ] && [ "$(uname -m)" = "arm64" ] && [ "$target" = "x86_64-apple-darwin" ]; then
  if ! arch -x86_64 /usr/bin/true >/dev/null 2>&1; then
    echo "ERROR: x86_64-apple-darwin runtime builds on Apple Silicon require Rosetta." >&2
    exit 1
  fi
  if [ ! -x /usr/local/bin/brew ]; then
    echo "ERROR: x86_64-apple-darwin runtime builds on Apple Silicon require Intel Homebrew at /usr/local/bin/brew." >&2
    exit 1
  fi
  brew_cmd=(arch -x86_64 /usr/local/bin/brew)
fi

retry "${brew_cmd[@]}" update
retry "${brew_cmd[@]}" install composer gpatch automake re2c bison pkg-config

# static-php-cli uses the Homebrew php-cli while building the runtime bundle.
# GitHub runners usually have it already; keep this best-effort so an unrelated
# php reinstall problem does not hide the actual runtime build result.
"${brew_cmd[@]}" list php >/dev/null 2>&1 || retry "${brew_cmd[@]}" install php || true

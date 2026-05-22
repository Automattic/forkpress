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

ensure_intel_homebrew() {
	if [ -x /usr/local/bin/brew ]; then
		return
	fi

  echo "Intel Homebrew not found at /usr/local/bin/brew; installing under Rosetta."
  env NONINTERACTIVE=1 CI=1 arch -x86_64 /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

  if [ ! -x /usr/local/bin/brew ]; then
    echo "ERROR: Intel Homebrew installation completed but /usr/local/bin/brew is still missing." >&2
    exit 1
	fi
}

ensure_rosetta() {
	if arch -x86_64 /usr/bin/true >/dev/null 2>&1; then
		return
	fi

	echo "Rosetta is not available; installing Rosetta for x86_64 macOS runtime builds."
	if command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1; then
		sudo /usr/sbin/softwareupdate --install-rosetta --agree-to-license
	else
		/usr/sbin/softwareupdate --install-rosetta --agree-to-license
	fi

	if ! arch -x86_64 /usr/bin/true >/dev/null 2>&1; then
		echo "ERROR: Rosetta installation completed but x86_64 execution still fails." >&2
		exit 1
	fi
}

target="${1:-${FORKPRESS_TARGET:-}}"
brew_cmd=(brew)
if [ "$(uname -s)" = "Darwin" ] && [ "$(uname -m)" = "arm64" ] && [ "$target" = "x86_64-apple-darwin" ]; then
	ensure_rosetta
	ensure_intel_homebrew
	brew_cmd=(arch -x86_64 /usr/local/bin/brew)
fi

retry "${brew_cmd[@]}" update
retry "${brew_cmd[@]}" install composer gpatch automake re2c bison pkg-config

# static-php-cli uses the Homebrew php-cli while building the runtime bundle.
# GitHub runners usually have it already; keep this best-effort so an unrelated
# php reinstall problem does not hide the actual runtime build result.
"${brew_cmd[@]}" list php >/dev/null 2>&1 || retry "${brew_cmd[@]}" install php || true

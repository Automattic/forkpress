#!/bin/sh
set -eu

repo="${FORKPRESS_REPO:-Automattic/forkpress}"
version="${FORKPRESS_VERSION:-latest}"
install_dir="${FORKPRESS_INSTALL_DIR:-$HOME/.local/bin}"
target="${FORKPRESS_TARGET:-}"

need() {
	if ! command -v "$1" >/dev/null 2>&1; then
		echo "forkpress install: missing required command: $1" >&2
		exit 1
	fi
}

detect_target() {
	if [ -n "$target" ]; then
		printf '%s\n' "$target"
		return
	fi

	os="$(uname -s)"
	arch="$(uname -m)"
	case "$os-$arch" in
		Darwin-arm64) printf '%s\n' "aarch64-apple-darwin" ;;
		Darwin-x86_64) printf '%s\n' "x86_64-apple-darwin" ;;
		Linux-aarch64|Linux-arm64) printf '%s\n' "aarch64-unknown-linux-musl" ;;
		Linux-x86_64|Linux-amd64) printf '%s\n' "x86_64-unknown-linux-musl" ;;
		*)
			echo "forkpress install: unsupported platform: $os $arch" >&2
			exit 1
			;;
	esac
}

release_base_url() {
	if [ -n "${FORKPRESS_RELEASE_BASE_URL:-}" ]; then
		printf '%s\n' "${FORKPRESS_RELEASE_BASE_URL%/}"
		return
	fi

	case "$version" in
		latest|"") printf 'https://github.com/%s/releases/latest/download\n' "$repo" ;;
		v*) printf 'https://github.com/%s/releases/download/%s\n' "$repo" "$version" ;;
		*) printf 'https://github.com/%s/releases/download/v%s\n' "$repo" "$version" ;;
	esac
}

download() {
	url="$1"
	out="$2"
	curl -fsSL "$url" -o "$out"
}

sha256_file() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | awk '{ print $1 }'
	else
		shasum -a 256 "$1" | awk '{ print $1 }'
	fi
}

expected_sha256() {
	asset="$1"
	sums="$2"
	awk -v asset="$asset" '
		($2 == asset || $2 == "*" asset) { print tolower($1); found = 1; exit }
		END { if (!found) exit 1 }
	' "$sums"
}

verify_sha256() {
	asset="$1"
	file="$2"
	sums="$3"
	expected="$(expected_sha256 "$asset" "$sums")" || {
		echo "forkpress install: SHA256SUMS does not include $asset" >&2
		exit 1
	}
	actual="$(sha256_file "$file")"
	if [ "$actual" != "$expected" ]; then
		echo "forkpress install: checksum mismatch for $asset" >&2
		echo "expected: $expected" >&2
		echo "actual:   $actual" >&2
		exit 1
	fi
}

tmpdir="$(mktemp -d "${TMPDIR:-/tmp}/forkpress-install.XXXXXX")"
cleanup() {
	rm -rf "$tmpdir"
}
trap cleanup EXIT HUP INT TERM

need curl
need tar
need awk

target="$(detect_target)"
asset="forkpress-$target.tar.gz"
base_url="$(release_base_url)"
archive="$tmpdir/$asset"
sums="$tmpdir/SHA256SUMS"
extract="$tmpdir/extract"

mkdir -p "$extract" "$install_dir"

echo "forkpress install: downloading $asset"
download "$base_url/$asset" "$archive"
download "$base_url/SHA256SUMS" "$sums"
verify_sha256 "$asset" "$archive" "$sums"

tar -xzf "$archive" -C "$extract"
if [ ! -f "$extract/forkpress" ]; then
	echo "forkpress install: archive did not contain forkpress" >&2
	exit 1
fi

cp "$extract/forkpress" "$install_dir/forkpress"
chmod 0755 "$install_dir/forkpress"

"$install_dir/forkpress" --version

case ":$PATH:" in
	*":$install_dir:"*) ;;
	*)
		echo "forkpress install: $install_dir is not on PATH" >&2
		echo "Add it to PATH or run $install_dir/forkpress directly." >&2
		;;
esac

echo "forkpress install: installed $install_dir/forkpress"

#!/usr/bin/env bash
# Build the per-target runtime bundle (php + branchfs builtin) consumed by
# forkpress at build time. Produces dist/<triple>/ ready for
# `cargo build --release` to embed.
#
# Builds the bundled runtime for the host by default. CI passes
# FORKPRESS_TARGET so the bundle path matches the Rust target triple.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

# --- Target detection ------------------------------------------------------
UNAME_S=$(uname -s)
UNAME_M=$(uname -m)
if [ -n "${FORKPRESS_TARGET:-}" ]; then
  TRIPLE="$FORKPRESS_TARGET"
else
  case "$UNAME_S-$UNAME_M" in
    Darwin-arm64)  TRIPLE=aarch64-apple-darwin        ;;
    Darwin-x86_64) TRIPLE=x86_64-apple-darwin         ;;
    Linux-x86_64)  TRIPLE=x86_64-unknown-linux-musl   ;;
    Linux-aarch64) TRIPLE=aarch64-unknown-linux-musl  ;;
    *) echo "unsupported host: $UNAME_S $UNAME_M" >&2; exit 1 ;;
  esac
fi

# BUILD_DIR and DIST_DIR can be overridden to isolate per-target state
# (e.g. running cross-target builds back-to-back, or from inside a container
# that should not scribble on the host's .build/).
DIST_DIR="${FORKPRESS_DIST_DIR:-$REPO_ROOT/dist/$TRIPLE}"
BUILD_DIR="${FORKPRESS_BUILD_DIR:-$REPO_ROOT/.build/$TRIPLE}"
SPC_DIR="$BUILD_DIR/static-php-cli"

# WordPress-ready extension set.
# Exclusions:
#   - iconv: libiconv requires gettext headers static-php-cli doesn't bootstrap on mac;
#            mbstring covers the same ground for WordPress.
#   - opcache: PHP 8.3's JIT has a broken arm64-macos path (missing zend_jit_arm64.c);
#              opcache is a perf optimization, not functionally required.
EXTENSIONS="bcmath,ctype,curl,dom,exif,fileinfo,filter,mbstring,openssl,pcntl,pdo,pdo_sqlite,phar,posix,session,simplexml,sockets,sqlite3,tokenizer,xml,xmlreader,xmlwriter,zip,zlib"

mkdir -p "$DIST_DIR/bin"

# --- 1. Static PHP via static-php-cli --------------------------------------
# Build branchfs directly into the php binary as a builtin extension.
#
# On fully-static Linux PHP (musl), dlopen does not work, so there's no way
# to load an external branchfs.so at runtime. Rather than maintain two
# separate integration paths (dylib load on mac, builtin on Linux), we build
# branchfs into PHP itself on every platform via static-php-cli's patch hook
# (scripts/spc-patch-branchfs.php). The php binary thereby carries branchfs
# natively — no `-d extension=...` flag, no separate .so in the dist/ layout.

if [ ! -x "$SPC_DIR/buildroot/bin/php" ]; then
  echo "==> Building static PHP via static-php-cli (first-time: 3-5 minutes)"
  if [ ! -d "$SPC_DIR" ]; then
    mkdir -p "$BUILD_DIR"
    git clone --depth 1 https://github.com/crazywhalecc/static-php-cli.git "$SPC_DIR"
  fi
  cd "$SPC_DIR"
  # --ignore-platform-reqs skips strict checking of the PHP version constraint
  # in static-php-cli's composer.lock (which can float up to PHP >= 8.4 as
  # deps update). static-php-cli itself works fine on PHP 8.3, which is the
  # baseline we can rely on (ubuntu-24.04, macos-14 via brew).
  composer install --no-dev --prefer-dist --ignore-platform-reqs

  # macOS BSD patch fails on some static-php-cli patches ("out of memory").
  # Shim `patch` to gpatch when available.
  if [ "$UNAME_S" = "Darwin" ] && command -v gpatch >/dev/null 2>&1; then
    mkdir -p bin/bin-shim
    ln -sf "$(command -v gpatch)" bin/bin-shim/patch
    export PATH="$SPC_DIR/bin/bin-shim:$PATH"
  fi
  # Ensure Apple Silicon homebrew is preferred over any Intel brew symlinks.
  if [ -d /opt/homebrew/bin ]; then
    export PATH="/opt/homebrew/bin:$PATH"
  fi

  # On Apple Silicon, if the parent shell is running under Rosetta, native
  # clang defaults to x86_64 and some vendored library builds (libzip, etc)
  # use that default instead of --target=arm64-apple-darwin, producing mixed
  # arch objects that fail to link. Relaunch the spc subcommands in a native
  # arm64 shell so every vendored lib compiles for arm64 consistently.
  SPC_RUN=( )
  if [ "$UNAME_S-$UNAME_M" = "Darwin-arm64" ] && [ "$(uname -m)" != "arm64" ]; then
    SPC_RUN=( arch -arm64 )
  fi

  "${SPC_RUN[@]+"${SPC_RUN[@]}"}" ./bin/spc doctor --auto-fix
  "${SPC_RUN[@]+"${SPC_RUN[@]}"}" ./bin/spc download --for-extensions="$EXTENSIONS" --with-php=8.3

  # Register branchfs as a builtin extension in spc's ext.json so its
  # --enable-branchfs flag is passed to PHP's configure.
  php -r '
$p = "config/ext.json";
$c = json_decode(file_get_contents($p), true);
$c["branchfs"] = ["type" => "builtin"];
file_put_contents($p, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
'

  # The patch script injects branchfs source into php-src/ext/branchfs/ and
  # re-runs ./buildconf --force so the new extension is visible to configure.
  # Using the hook (rather than manual pre-extraction) is robust against spc
  # re-extracting php-src during the build phase.
  "${SPC_RUN[@]+"${SPC_RUN[@]}"}" ./bin/spc build \
    --with-added-patch="$REPO_ROOT/scripts/spc-patch-branchfs.php" \
    "$EXTENSIONS,branchfs" --build-cli
  cd "$REPO_ROOT"
fi

install -m 0755 "$SPC_DIR/buildroot/bin/php" "$DIST_DIR/bin/php"

# Sanity-check that branchfs is actually compiled into the php binary.
_php_modules=$("$DIST_DIR/bin/php" -m 2>&1 || true)
if ! printf '%s\n' "$_php_modules" | grep -qi '^branchfs$'; then
  echo "ERROR: branchfs is not a loaded extension in the built php binary." >&2
  echo "       php -m output:" >&2
  printf '%s\n' "$_php_modules" | sed 's/^/         /' >&2
  exit 1
fi

# --- 2. Ad-hoc codesign (Apple Silicon refuses unsigned ARM64 binaries) ----
if [ "$UNAME_S" = "Darwin" ]; then
  echo "==> Ad-hoc codesigning mac binaries"
  codesign --force --sign - "$DIST_DIR/bin/php"
fi

echo
echo "dist/$TRIPLE/ ready:"
ls -lh "$DIST_DIR/bin/php"
echo
echo "Next: cargo build --release"

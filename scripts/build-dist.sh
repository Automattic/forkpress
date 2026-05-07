#!/usr/bin/env bash
# Build the per-target runtime bundle consumed by forkpress at build time.
# Produces dist/<triple>/ or dist/<triple>-dev/ ready for
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
PROFILE="${FORKPRESS_RUNTIME_PROFILE:-production}"
case "$PROFILE" in
  production|dev) ;;
  *) echo "unsupported FORKPRESS_RUNTIME_PROFILE: $PROFILE" >&2; exit 1 ;;
esac

if [ "$PROFILE" = "dev" ]; then
  DIST_NAME="$TRIPLE-dev"
else
  DIST_NAME="$TRIPLE"
fi

DIST_DIR="${FORKPRESS_DIST_DIR:-$REPO_ROOT/dist/$DIST_NAME}"
BUILD_DIR="${FORKPRESS_BUILD_DIR:-$REPO_ROOT/.build/$DIST_NAME}"
SPC_DIR="$BUILD_DIR/static-php-cli"
CAS_TARGET_DIR="$BUILD_DIR/cas-ffi-target"
CAS_LIB_DIR="$CAS_TARGET_DIR/$TRIPLE/release"

# WordPress-ready extension set.
# Exclusions:
#   - iconv: libiconv requires gettext headers static-php-cli doesn't bootstrap on mac;
#            mbstring covers the same ground for WordPress.
#   - opcache: PHP 8.3's JIT has a broken arm64-macos path (missing zend_jit_arm64.c);
#              opcache is a perf optimization, not functionally required.
EXTENSIONS="bcmath,ctype,curl,dom,exif,fileinfo,filter,mbstring,openssl,pcntl,pdo,pdo_sqlite,phar,posix,session,simplexml,sockets,sqlite3,tokenizer,xml,xmlreader,xmlwriter,zip,zlib"

mkdir -p "$DIST_DIR/bin"

if [ "$PROFILE" = "dev" ]; then
  echo "==> Building Rust CAS static library for experimental PHP branchfs"
  cargo build --release --target "$TRIPLE" -p forkpress-cas-ffi --target-dir "$CAS_TARGET_DIR"
  CAS_STATIC_LIB="$CAS_LIB_DIR/libforkpress_cas_ffi.a"
  if [ ! -f "$CAS_STATIC_LIB" ]; then
    echo "ERROR: expected CAS static library was not built: $CAS_STATIC_LIB" >&2
    exit 1
  fi
  export FORKPRESS_CAS_LIB_DIR="$CAS_LIB_DIR"
  export SPC_EXTRA_LIBS="${SPC_EXTRA_LIBS:-} $CAS_STATIC_LIB"
fi

# --- 1. Static PHP via static-php-cli --------------------------------------
# Production builds a plain static PHP CLI for the materialized COW runtime.
# The dev profile additionally builds branchfs into PHP as a builtin extension
# because fully-static Linux PHP cannot dlopen an external branchfs.so.

NEED_PHP_BUILD=1
if [ -x "$SPC_DIR/buildroot/bin/php" ]; then
  if [ "$PROFILE" = "dev" ]; then
    PHP_READY_CHECK='exit(extension_loaded("branchfs") && function_exists("branchfs_set_cas_store") ? 0 : 1);'
  else
    PHP_READY_CHECK='exit(extension_loaded("branchfs") ? 1 : 0);'
  fi
  if "$SPC_DIR/buildroot/bin/php" -r "$PHP_READY_CHECK" >/dev/null 2>&1; then
    NEED_PHP_BUILD=0
    deps=( "$REPO_ROOT/scripts/build-dist.sh" )
    if [ "$PROFILE" = "dev" ]; then
      deps+=( "$CAS_STATIC_LIB" "$REPO_ROOT/experiments/branchfs/php-ext/branchfs.c" "$REPO_ROOT/experiments/branchfs/php-ext/branchfs.h" "$REPO_ROOT/experiments/branchfs/build/spc-patch.php" )
    fi
    for dep in "${deps[@]}"; do
      if [ "$dep" -nt "$SPC_DIR/buildroot/bin/php" ]; then
        NEED_PHP_BUILD=1
        break
      fi
    done
  else
    rm -f "$SPC_DIR/buildroot/bin/php"
  fi
fi

if [ "$NEED_PHP_BUILD" = "1" ]; then
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

  if [ "$PROFILE" = "dev" ]; then
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
      --with-added-patch="$REPO_ROOT/experiments/branchfs/build/spc-patch.php" \
      "$EXTENSIONS,branchfs" --build-cli
  else
    "${SPC_RUN[@]+"${SPC_RUN[@]}"}" ./bin/spc build "$EXTENSIONS" --build-cli
  fi
  cd "$REPO_ROOT"
fi

install -m 0755 "$SPC_DIR/buildroot/bin/php" "$DIST_DIR/bin/php"

# Sanity-check that the PHP runtime matches the selected profile.
_php_modules=$("$DIST_DIR/bin/php" -m 2>&1 || true)
if [ "$PROFILE" = "dev" ]; then
  if ! printf '%s\n' "$_php_modules" | grep -qi '^branchfs$'; then
    echo "ERROR: branchfs is not a loaded extension in the dev PHP binary." >&2
    echo "       php -m output:" >&2
    printf '%s\n' "$_php_modules" | sed 's/^/         /' >&2
    exit 1
  fi
else
  if printf '%s\n' "$_php_modules" | grep -qi '^branchfs$'; then
    echo "ERROR: production PHP binary unexpectedly includes experimental branchfs." >&2
    exit 1
  fi
fi

# --- 2. Ad-hoc codesign (Apple Silicon refuses unsigned ARM64 binaries) ----
if [ "$UNAME_S" = "Darwin" ]; then
  echo "==> Ad-hoc codesigning mac binaries"
  codesign --force --sign - "$DIST_DIR/bin/php"
fi

echo
echo "dist/$DIST_NAME/ ready:"
ls -lh "$DIST_DIR/bin/php"
echo
if [ "$PROFILE" = "dev" ]; then
  echo "Next: cargo build --release -p forkpress-cli --features dev-experiments --bin forkpress-dev"
else
  echo "Next: cargo build --release -p forkpress-cli --bin forkpress"
fi

#!/bin/bash
set -euo pipefail

# Build the branchfs extension if missing or if source is newer.
cd /app

if [ ! -f ext/branchfs.so ] \
   || [ ext/branchfs.c -nt ext/branchfs.so ] \
   || [ ext/branchfs.h -nt ext/branchfs.so ]; then
    echo "[entrypoint] building ext/branchfs.so ..."
    make >/dev/null
    echo "[entrypoint] built"
fi

exec "$@"

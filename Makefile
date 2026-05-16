# Portable by default: detect PHP + SQLite via php-config and pkg-config.
# If those tools are unavailable, fall back to Nix-style dev outputs when present.

PHP_CONFIG ?= $(shell command -v php-config 2>/dev/null || command -v php-config8.2 2>/dev/null)
PKG_CONFIG ?= $(shell command -v pkg-config 2>/dev/null)

PHP_DEV_DIR ?= $(firstword $(wildcard /nix/store/*-php-*-dev))
SQLITE_INC  ?= $(firstword $(wildcard /nix/store/*-sqlite-*-dev/include))
SQLITE_LIB  ?= $(firstword $(filter-out /nix/store/*-sqlite-*-dev/lib,$(wildcard /nix/store/*-sqlite-*/lib)))

BRANCHFS_EXT_DIR := experiments/branchfs/php-ext
BRANCHFS_EXT_SO := $(BRANCHFS_EXT_DIR)/branchfs.so
BRANCHFS_TEST_DIR := experiments/branchfs/tests
COW_TEST_DIR := tests/cow
RELEASE_TEST_DIR := tests/release
BRANCHFS_HEADER_GOALS := all init-db test test-compat test-branchfs test-all $(BRANCHFS_EXT_SO)
NEEDS_BRANCHFS_HEADERS := $(filter $(BRANCHFS_HEADER_GOALS),$(MAKECMDGOALS))
ifeq ($(strip $(MAKECMDGOALS)),)
NEEDS_BRANCHFS_HEADERS := all
endif

PHP_INCLUDE_DIR ?= $(if $(PHP_CONFIG),$(shell $(PHP_CONFIG) --include-dir 2>/dev/null))
PHP_EXTRA_INCS  :=

ifneq ($(strip $(PHP_INCLUDE_DIR)),)
PHP_EXTRA_INCS += -I$(PHP_INCLUDE_DIR) \
                  -I$(PHP_INCLUDE_DIR)/main \
                  -I$(PHP_INCLUDE_DIR)/TSRM \
                  -I$(PHP_INCLUDE_DIR)/Zend \
                  -I$(PHP_INCLUDE_DIR)/ext \
                  -I$(PHP_INCLUDE_DIR)/ext/date/lib
else ifneq ($(strip $(PHP_DEV_DIR)),)
PHP_EXTRA_INCS += -I$(PHP_DEV_DIR)/include/php \
                  -I$(PHP_DEV_DIR)/include/php/main \
                  -I$(PHP_DEV_DIR)/include/php/TSRM \
                  -I$(PHP_DEV_DIR)/include/php/Zend \
                  -I$(PHP_DEV_DIR)/include/php/ext \
                  -I$(PHP_DEV_DIR)/include/php/ext/date/lib
else ifneq ($(strip $(NEEDS_BRANCHFS_HEADERS)),)
$(error Could not determine PHP headers. Install php-config or set PHP_DEV_DIR)
endif

SQLITE_CFLAGS ?= $(if $(PKG_CONFIG),$(shell $(PKG_CONFIG) --cflags sqlite3 2>/dev/null))
SQLITE_LIBS   ?= $(if $(PKG_CONFIG),$(shell $(PKG_CONFIG) --libs sqlite3 2>/dev/null))

ifneq ($(strip $(SQLITE_INC)),)
SQLITE_CFLAGS := -I$(SQLITE_INC)
endif
ifneq ($(strip $(SQLITE_LIB)),)
SQLITE_LIBS := -L$(SQLITE_LIB) -lsqlite3 -Wl,-rpath,$(SQLITE_LIB)
endif

ifeq ($(strip $(SQLITE_LIBS)),)
SQLITE_LIBS := -lsqlite3
endif

CC      ?= gcc
CFLAGS  := -fPIC -O2 -Wall -DCOMPILE_DL_BRANCHFS -DHAVE_CONFIG_H=0 $(SQLITE_CFLAGS)
INCLUDES := $(PHP_EXTRA_INCS)
LDFLAGS := $(SQLITE_LIBS)
RUSTUP ?= $(shell command -v rustup 2>/dev/null)
UNAME_S := $(shell uname -s)
UNAME_M := $(shell uname -m)
PHP_EXT_LDFLAGS := -shared
ifeq ($(UNAME_S),Darwin)
PHP_EXT_LDFLAGS := -bundle -undefined dynamic_lookup
endif
ifeq ($(UNAME_S)-$(UNAME_M),Darwin-arm64)
FORKPRESS_TARGET ?= aarch64-apple-darwin
else ifeq ($(UNAME_S)-$(UNAME_M),Darwin-x86_64)
FORKPRESS_TARGET ?= x86_64-apple-darwin
else ifeq ($(UNAME_S)-$(UNAME_M),Linux-x86_64)
FORKPRESS_TARGET ?= x86_64-unknown-linux-musl
else ifeq ($(UNAME_S)-$(UNAME_M),Linux-aarch64)
FORKPRESS_TARGET ?= aarch64-unknown-linux-musl
endif

.PHONY: all clean test test-compat test-branchfs test-cow test-cow-branch-birth test-cow-explicit-ids test-cow-fast test-cow-filesystem test-cow-git-server test-cow-id-bands test-cow-media-validator test-cow-merge test-cow-merge-smoke test-cow-plugin-validator test-cow-schema-review test-cow-stale-audit test-cow-wp-semantic-validator test-release init-db test-all forkpress forkpress-dev dist dist-dev

all: $(BRANCHFS_EXT_SO)

$(BRANCHFS_EXT_SO): $(BRANCHFS_EXT_DIR)/branchfs.c $(BRANCHFS_EXT_DIR)/branchfs.h
	$(CC) $(CFLAGS) $(INCLUDES) $(PHP_EXT_LDFLAGS) -o $@ $(BRANCHFS_EXT_DIR)/branchfs.c $(LDFLAGS)

init-db: $(BRANCHFS_EXT_SO)
	php -d "extension=$(CURDIR)/$(BRANCHFS_EXT_SO)" experiments/branchfs/scripts/init_db.php

test: $(BRANCHFS_EXT_SO)
	php -d "extension=$(CURDIR)/$(BRANCHFS_EXT_SO)" $(BRANCHFS_TEST_DIR)/basic.php

test-compat: $(BRANCHFS_EXT_SO)
	php -d "extension=$(CURDIR)/$(BRANCHFS_EXT_SO)" $(BRANCHFS_TEST_DIR)/plugin_compat.php

test-branchfs: $(BRANCHFS_EXT_SO)
	php -d "extension=$(CURDIR)/$(BRANCHFS_EXT_SO)" $(BRANCHFS_TEST_DIR)/basic.php
	php -d "extension=$(CURDIR)/$(BRANCHFS_EXT_SO)" $(BRANCHFS_TEST_DIR)/plugin_compat.php
	php -d "extension=$(CURDIR)/$(BRANCHFS_EXT_SO)" $(BRANCHFS_TEST_DIR)/wp_boot.php
	php -d "extension=$(CURDIR)/$(BRANCHFS_EXT_SO)" $(BRANCHFS_TEST_DIR)/realpath.php
	php -d "extension=$(CURDIR)/$(BRANCHFS_EXT_SO)" $(BRANCHFS_TEST_DIR)/syscall_overrides.php
	php -d "extension=$(CURDIR)/$(BRANCHFS_EXT_SO)" $(BRANCHFS_TEST_DIR)/opcache_keys.php
	php -d "extension=$(CURDIR)/$(BRANCHFS_EXT_SO)" $(BRANCHFS_TEST_DIR)/merge.php
	php -d "extension=$(CURDIR)/$(BRANCHFS_EXT_SO)" $(BRANCHFS_TEST_DIR)/db_cow_backtick_ddl.php
	php -d "extension=$(CURDIR)/$(BRANCHFS_EXT_SO)" $(BRANCHFS_TEST_DIR)/gc.php
	php -d "extension=$(CURDIR)/$(BRANCHFS_EXT_SO)" $(BRANCHFS_TEST_DIR)/push_auth.php
	php -d "extension=$(CURDIR)/$(BRANCHFS_EXT_SO)" $(BRANCHFS_TEST_DIR)/branchctl_local_auth.php

test-cow-merge-smoke:
	php $(COW_TEST_DIR)/merge_smoke.php

test-cow-merge: test-cow-merge-smoke
	php $(COW_TEST_DIR)/merge.php

test-cow-git-server:
	php $(COW_TEST_DIR)/git_server.php

test-cow-filesystem:
	php $(COW_TEST_DIR)/filesystem.php

test-cow-branch-birth:
	php $(COW_TEST_DIR)/branch_birth.php

test-cow-explicit-ids:
	php $(COW_TEST_DIR)/explicit_ids.php

test-cow-id-bands:
	php $(COW_TEST_DIR)/id_bands.php

test-cow-media-validator:
	php $(COW_TEST_DIR)/media_validator.php

test-cow-plugin-validator:
	php $(COW_TEST_DIR)/plugin_validator.php

test-cow-stale-audit:
	php $(COW_TEST_DIR)/stale_audit.php

test-cow-wp-semantic-validator:
	php $(COW_TEST_DIR)/wp_semantic_validator.php

test-cow-schema-review:
	php $(COW_TEST_DIR)/schema_review.php

test-cow-fast: test-cow-git-server test-cow-merge-smoke
	php $(COW_TEST_DIR)/branch_birth.php
	php $(COW_TEST_DIR)/explicit_ids.php
	php $(COW_TEST_DIR)/filesystem.php
	php $(COW_TEST_DIR)/id_bands.php
	php $(COW_TEST_DIR)/media_validator.php
	php $(COW_TEST_DIR)/plugin_validator.php
	php $(COW_TEST_DIR)/schema_review.php
	php $(COW_TEST_DIR)/stale_audit.php
	php $(COW_TEST_DIR)/wp_semantic_validator.php
	php $(COW_TEST_DIR)/branch_ui.php
	php $(COW_TEST_DIR)/router_paths.php
	php $(COW_TEST_DIR)/router_lock.php

test-cow: test-cow-fast
	php $(COW_TEST_DIR)/merge.php

test-release:
	bash $(RELEASE_TEST_DIR)/build-dist-preflight.sh

test-all: test-branchfs test-cow test-release

clean:
	rm -f $(BRANCHFS_EXT_SO) /tmp/branchfs_test*.db /tmp/branchfs_wp*.db

# Build the per-target production runtime bundle consumed by forkpress.
# First-time build compiles static PHP from source and takes ~3-5 minutes on
# Apple Silicon; subsequent runs reuse the cached PHP.
dist:
	FORKPRESS_TARGET=$(FORKPRESS_TARGET) scripts/build-dist.sh

# Build the dev runtime bundle with experimental BranchFS/CAS support.
dist-dev:
	FORKPRESS_RUNTIME_PROFILE=dev FORKPRESS_TARGET=$(FORKPRESS_TARGET) scripts/build-dist.sh

# Build the shippable forkpress binary for FORKPRESS_TARGET. Requires `dist`
# to have run at least once for the same target.
forkpress:
	cargo build --release --target $(FORKPRESS_TARGET) -p forkpress-cli --bin forkpress

# Build the developer binary with experimental strategies. Requires `dist-dev`
# to have run at least once for the same target.
forkpress-dev:
	cargo build --release --target $(FORKPRESS_TARGET) -p forkpress-cli --features dev-experiments --bin forkpress-dev

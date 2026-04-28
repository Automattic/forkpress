# Portable by default: detect PHP + SQLite via php-config and pkg-config.
# If those tools are unavailable, fall back to Nix-style dev outputs when present.

PHP_CONFIG ?= $(shell command -v php-config 2>/dev/null || command -v php-config8.2 2>/dev/null)
PKG_CONFIG ?= $(shell command -v pkg-config 2>/dev/null)

PHP_DEV_DIR ?= $(firstword $(wildcard /nix/store/*-php-*-dev))
SQLITE_INC  ?= $(firstword $(wildcard /nix/store/*-sqlite-*-dev/include))
SQLITE_LIB  ?= $(firstword $(filter-out /nix/store/*-sqlite-*-dev/lib,$(wildcard /nix/store/*-sqlite-*/lib)))

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
else
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
CFLAGS  := -fPIC -shared -O2 -Wall -DCOMPILE_DL_BRANCHFS -DHAVE_CONFIG_H=0 $(SQLITE_CFLAGS)
INCLUDES := $(PHP_EXTRA_INCS)
LDFLAGS := $(SQLITE_LIBS)
RUSTUP ?= $(shell command -v rustup 2>/dev/null)
UNAME_S := $(shell uname -s)
UNAME_M := $(shell uname -m)
ifeq ($(UNAME_S)-$(UNAME_M),Darwin-arm64)
FORKPRESS_TARGET ?= aarch64-apple-darwin
else ifeq ($(UNAME_S)-$(UNAME_M),Darwin-x86_64)
FORKPRESS_TARGET ?= x86_64-apple-darwin
else ifeq ($(UNAME_S)-$(UNAME_M),Linux-x86_64)
FORKPRESS_TARGET ?= x86_64-unknown-linux-musl
else ifeq ($(UNAME_S)-$(UNAME_M),Linux-aarch64)
FORKPRESS_TARGET ?= aarch64-unknown-linux-musl
endif

.PHONY: all clean test test-compat init-db test-all forkpress dist

all: ext/branchfs.so

ext/branchfs.so: ext/branchfs.c ext/branchfs.h
	$(CC) $(CFLAGS) $(INCLUDES) -o $@ ext/branchfs.c $(LDFLAGS)

init-db: ext/branchfs.so
	php -d "extension=$(CURDIR)/ext/branchfs.so" scripts/init_db.php

test: ext/branchfs.so
	php -d "extension=$(CURDIR)/ext/branchfs.so" tests/test_basic.php

test-compat: ext/branchfs.so
	php -d "extension=$(CURDIR)/ext/branchfs.so" tests/test_plugin_compat.php

test-all: ext/branchfs.so
	php -d "extension=$(CURDIR)/ext/branchfs.so" tests/test_basic.php
	php -d "extension=$(CURDIR)/ext/branchfs.so" tests/test_plugin_compat.php
	php -d "extension=$(CURDIR)/ext/branchfs.so" tests/test_wp_boot.php
	php -d "extension=$(CURDIR)/ext/branchfs.so" tests/test_realpath.php
	php -d "extension=$(CURDIR)/ext/branchfs.so" tests/test_syscall_overrides.php
	php -d "extension=$(CURDIR)/ext/branchfs.so" tests/test_opcache_keys.php
	php -d "extension=$(CURDIR)/ext/branchfs.so" tests/test_merge.php
	php -d "extension=$(CURDIR)/ext/branchfs.so" tests/test_cow_backtick_ddl.php
	php -d "extension=$(CURDIR)/ext/branchfs.so" tests/test_gc.php
	php -d "extension=$(CURDIR)/ext/branchfs.so" tests/test_push_auth.php
	php -d "extension=$(CURDIR)/ext/branchfs.so" tests/test_branchctl_local_auth.php

clean:
	rm -f ext/branchfs.so /tmp/branchfs_test*.db /tmp/branchfs_wp*.db

# Build the per-target runtime bundle (php + branchfs builtin) consumed by
# forkpress. First-time build compiles static PHP from source and takes
# ~3-5 minutes on Apple Silicon; subsequent runs reuse the cached PHP.
dist:
	FORKPRESS_TARGET=$(FORKPRESS_TARGET) scripts/build-dist.sh

# Build the shippable forkpress binary for FORKPRESS_TARGET. Requires `dist`
# to have run at least once for the same target.
forkpress:
	cargo build --release --target $(FORKPRESS_TARGET) -p forkpress

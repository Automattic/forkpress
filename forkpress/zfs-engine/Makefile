ZFS        ?= $(EXPERIMENT)/upstream/zfs
BUILD      ?= build
EXPERIMENT ?= .
WRAPPER    ?= forkpress_zfs.c
ZLIB_DIR   ?=
CC         ?= cc
AR         ?= ar
MODE       ?= release
ENGINE_DIR := $(dir $(WRAPPER))

ifeq ($(ZFS_ENGINE_DARWIN),1)
LIBSPL_OS := freebsd
else
LIBSPL_OS := linux
endif

INCS := \
    -I $(ENGINE_DIR)/include \
    -I $(ZLIB_DIR) \
    -I $(EXPERIMENT)/src/config \
    -I $(EXPERIMENT)/src/shim \
    -I $(EXPERIMENT)/src/shim/os/wasm/zfs \
    -I $(ZFS)/include \
    -I $(ZFS)/lib/libzutil \
    -I $(ZFS)/lib/libspl/include \
    -I $(ZFS)/lib/libspl/include/os/$(LIBSPL_OS)

DEFS_COMMON := \
    -D_GNU_SOURCE \
    -DHAVE_ISSETUGID=1 \
    -DHAVE_PTHREAD=1 \
    -D__USE_LARGEFILE64=1 \
    -DLIB_ZPOOL_BUILD=1

ifeq ($(ZFS_ENGINE_MUSL),1)
DEFS_COMMON += \
    -Dopen64=open \
    -Dpread64=pread \
    -Dpwrite64=pwrite \
    -Dlseek64=lseek \
    -Dfopen64=fopen \
    -Dfreopen64=freopen \
    -Dfseeko64=fseeko \
    -Dftello64=ftello \
    -Dmmap64=mmap \
    -Dtruncate64=truncate \
    -Dftruncate64=ftruncate \
    -Dstat64=stat \
    -Dfstat64=fstat \
    -Dlstat64=lstat \
    -Ddirent64=dirent \
    -Dreaddir64=readdir
endif

ifeq ($(ZFS_ENGINE_DARWIN),1)
DEFS_COMMON += \
    -Dopen64=open \
    -Dpread64=pread \
    -Dpwrite64=pwrite \
    -Dlseek64=lseek \
    -Dfopen64=fopen \
    -Dfreopen64=freopen \
    -Dfseeko64=fseeko \
    -Dftello64=ftello \
    -Dmmap64=mmap \
    -Dtruncate64=truncate \
    -Dftruncate64=ftruncate \
    -Dloff_t=off_t \
    -DEREMOTEIO=EIO \
    -DO_LARGEFILE=0 \
    -DO_DSYNC=0 \
    -D__EMSCRIPTEN__=1 \
    -Dissetugid=forkpress_zfs_issetugid
endif

ifeq ($(MODE),debug)
DEFS := $(DEFS_COMMON) -DZFS_DEBUG=1 -DDEBUG=1
OPTFLAGS := -O1 -g
else
DEFS := $(DEFS_COMMON) -DNDEBUG=1
OPTFLAGS := -Os
endif

CFLAGS := $(OPTFLAGS) -ffunction-sections -fdata-sections -fPIC \
          -fvisibility=hidden -pthread $(DEFS) $(INCS) \
          -include $(EXPERIMENT)/src/config/zfs_config.h \
          -Wno-format \
          -Wno-unused-parameter \
          -Wno-unused-function \
          -Wno-unused-variable \
          -Wno-unused-but-set-variable \
          -Wno-type-limits \
          -Wno-implicit-function-declaration \
          -Wno-incompatible-pointer-types \
          -Wno-incompatible-function-pointer-types \
          -Wno-tautological-compare \
          -Wno-sign-compare

NVPAIR_SRCS := \
    $(ZFS)/lib/libnvpair/libnvpair.c \
    $(ZFS)/lib/libnvpair/libnvpair_json.c \
    $(ZFS)/lib/libnvpair/nvpair_alloc_system.c \
    $(ZFS)/module/nvpair/nvpair.c \
    $(ZFS)/module/nvpair/nvpair_alloc_fixed.c \
    $(ZFS)/module/nvpair/fnvpair.c

LIBZPOOL_SRCS := \
    $(ZFS)/lib/libzpool/kernel.c \
    $(ZFS)/lib/libzpool/taskq.c \
    $(ZFS)/lib/libzpool/util.c

ZCOMMON_SRCS := \
    $(ZFS)/module/zcommon/cityhash.c \
    $(ZFS)/module/zcommon/zfeature_common.c \
    $(ZFS)/module/zcommon/zfs_comutil.c \
    $(ZFS)/module/zcommon/zfs_namecheck.c \
    $(ZFS)/module/zcommon/zfs_prop.c \
    $(ZFS)/module/zcommon/zpool_prop.c \
    $(ZFS)/module/zcommon/zprop_common.c \
    $(ZFS)/module/zcommon/zfs_fletcher.c \
    $(ZFS)/module/zcommon/zfs_fletcher_superscalar.c \
    $(ZFS)/module/zcommon/zfs_fletcher_superscalar4.c

AVL_SRCS := $(ZFS)/module/avl/avl.c
UNICODE_SRCS := $(wildcard $(ZFS)/module/unicode/*.c)
ZUTIL_SRCS := $(wildcard $(ZFS)/lib/libzutil/*.c)

ZFS_SRCS := $(addprefix $(ZFS)/module/zfs/, \
    spa.c spa_misc.c spa_config.c spa_errlog.c spa_history.c \
    spa_stats.c spa_log_spacemap.c spa_checkpoint.c \
    dmu.c dmu_object.c dmu_objset.c dmu_tx.c dmu_zfetch.c \
    dmu_traverse.c \
    dbuf.c dbuf_stats.c dnode.c dnode_sync.c \
    dsl_dir.c dsl_dataset.c dsl_pool.c dsl_synctask.c \
    dsl_destroy.c dsl_prop.c dsl_scan.c dsl_deadlist.c \
    dsl_userhold.c dsl_deleg.c \
    zap.c zap_leaf.c zap_micro.c \
    zio.c zio_checksum.c zio_compress.c zio_inject.c \
    metaslab.c space_map.c space_reftree.c range_tree.c \
    vdev.c vdev_label.c vdev_mirror.c vdev_missing.c \
    vdev_queue.c vdev_indirect.c vdev_indirect_births.c \
    vdev_indirect_mapping.c vdev_initialize.c vdev_trim.c \
    vdev_removal.c vdev_rebuild.c vdev_root.c \
    arc.c aggsum.c btree.c multilist.c rrwlock.c zrlock.c \
    unique.c refcount.c bplist.c bpobj.c bptree.c bqueue.c \
    blkptr.c txg.c uberblock.c fm.c pathname.c \
    zfeature.c zthr.c mmp.c sa.c zil.c zle.c lzjb.c \
    lz4.c lz4_zfs.c \
    zfs_byteswap.c zfs_fm.c zfs_rlock.c zfs_ratelimit.c \
    zfs_sa.c objlist.c abd.c)

ZFS_OS_SRCS := $(addprefix $(ZFS)/module/os/linux/zfs/, \
    vdev_file.c abd_os.c arc_os.c zfs_debug.c zfs_racct.c)

SPL_SRCS := \
    $(ZFS)/lib/libspl/assert.c \
    $(ZFS)/lib/libspl/atomic.c \
    $(ZFS)/lib/libspl/list.c \
    $(ZFS)/lib/libspl/strlcat.c \
    $(ZFS)/lib/libspl/strlcpy.c \
    $(ZFS)/lib/libspl/mkdirp.c \
    $(ZFS)/lib/libspl/page.c \
    $(ZFS)/lib/libspl/timestamp.c \
    $(ZFS)/lib/libspl/getexecname.c

SHIM_SRCS := \
    $(EXPERIMENT)/src/shim/xdr_stub.c \
    $(EXPERIMENT)/src/shim/spl_host_stubs.c \
    $(EXPERIMENT)/src/shim/disabled_features.c

WRAPPER_OBJ  := $(BUILD)/forkpress_zfs.o
ZFS_OBJS     := $(patsubst $(ZFS)/%.c,$(BUILD)/zfs/%.o,$(ZFS_SRCS) $(ZFS_OS_SRCS))
AVL_OBJS     := $(patsubst $(ZFS)/%.c,$(BUILD)/zfs/%.o,$(AVL_SRCS))
UNICODE_OBJS := $(patsubst $(ZFS)/%.c,$(BUILD)/zfs/%.o,$(UNICODE_SRCS))
ZUTIL_OBJS   := $(patsubst $(ZFS)/%.c,$(BUILD)/zfs/%.o,$(ZUTIL_SRCS))
LIBZPOOL_OBJS := $(patsubst $(ZFS)/%.c,$(BUILD)/zfs/%.o,$(LIBZPOOL_SRCS))
ZCOMMON_OBJS := $(patsubst $(ZFS)/%.c,$(BUILD)/zfs/%.o,$(ZCOMMON_SRCS))
NVPAIR_OBJS  := $(patsubst $(ZFS)/%.c,$(BUILD)/zfs/%.o,$(NVPAIR_SRCS))
SPL_OBJS     := $(patsubst $(ZFS)/%.c,$(BUILD)/zfs/%.o,$(SPL_SRCS))
SHIM_OBJS    := $(patsubst $(EXPERIMENT)/src/%.c,$(BUILD)/experiment/%.o,$(SHIM_SRCS))
ZLIB_OBJS    := $(wildcard $(ZLIB_DIR)/*.o)

ALL_OBJS := \
    $(WRAPPER_OBJ) \
    $(ZFS_OBJS) $(AVL_OBJS) $(UNICODE_OBJS) $(ZUTIL_OBJS) \
    $(LIBZPOOL_OBJS) $(ZCOMMON_OBJS) $(NVPAIR_OBJS) $(SPL_OBJS) \
    $(SHIM_OBJS) $(ZLIB_OBJS)

.PHONY: libforkpress_zfs clean
libforkpress_zfs: $(BUILD)/libforkpress_zfs.a

$(BUILD)/.patches-applied: $(wildcard $(EXPERIMENT)/patches/*.patch) $(EXPERIMENT)/scripts/apply-patches.sh
	@mkdir -p $(BUILD)
	cd $(EXPERIMENT) && bash scripts/apply-patches.sh
	@touch $@

$(BUILD)/libforkpress_zfs.a: $(ALL_OBJS)
	$(AR) rcs $@ $^

$(WRAPPER_OBJ): $(WRAPPER) $(BUILD)/.patches-applied
	@mkdir -p $(dir $@)
	$(CC) $(CFLAGS) -c $< -o $@

$(BUILD)/zfs/%.o: $(ZFS)/%.c $(BUILD)/.patches-applied
	@mkdir -p $(dir $@)
	$(CC) $(CFLAGS) -c $< -o $@

$(BUILD)/experiment/%.o: $(EXPERIMENT)/src/%.c $(BUILD)/.patches-applied
	@mkdir -p $(dir $@)
	$(CC) $(CFLAGS) -c $< -o $@

clean:
	rm -rf $(BUILD)

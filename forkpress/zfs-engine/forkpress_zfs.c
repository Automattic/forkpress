/*
 * ForkPress embedded ZFS engine.
 *
 * This is a thin native C ABI over the OpenZFS libzpool/DMU/DSL subset
 * used by adamziel/experiments/zfs-wasm-real. It does not mount a ZPL
 * filesystem and it does not require kernel ZFS, FUSE, a daemon, or a
 * shared library at runtime. ForkPress links this code and the selected
 * OpenZFS objects into the forkpress binary as one static archive.
 *
 * Dataset layout:
 * - datasets are DMU_OST_OTHER object sets;
 * - object id 1 is a root ZAP;
 * - each logical file path maps to (DMU object id, byte length).
 */

#include <errno.h>
#include <fcntl.h>
#include <pthread.h>
#include <stdint.h>
#include <stddef.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <unistd.h>

#include <sys/zfs_context.h>
#include <sys/fs/zfs.h>
#include <sys/spa.h>
#include <sys/dmu.h>
#include <sys/dmu_objset.h>
#include <sys/dmu_tx.h>
#include <sys/dsl_dataset.h>
#include <sys/dsl_destroy.h>
#include <sys/dsl_dir.h>
#include <sys/dsl_pool.h>
#include <sys/zap.h>
#include <sys/txg.h>

#include <libzutil.h>
#include <zutil_import.h>
#include <zfs_fletcher.h>

#define FORKPRESS_ZFS_ROOT_ZAP_OBJ 1

#if defined(__aarch64__) && !defined(__FreeBSD__)
static boolean_t
forkpress_zfs_aarch64_neon_valid(void)
{
    return (B_FALSE);
}

const fletcher_4_ops_t fletcher_4_aarch64_neon_ops = {
    .valid = forkpress_zfs_aarch64_neon_valid,
    .uses_fpu = B_FALSE,
    .name = "aarch64_neon_disabled"
};
#endif

#if defined(__APPLE__)
#ifdef pthread_mutex_destroy
#undef pthread_mutex_destroy
#endif

int
forkpress_zfs_pthread_mutex_destroy(pthread_mutex_t *mutex)
{
    int err = pthread_mutex_destroy(mutex);
    if (err == EINVAL || err == EBUSY)
        return (0);
    return (err);
}

int
fstat64_blk(int fd, struct stat64 *st)
{
    return (fstat64(fd, st));
}
#endif

typedef struct tpool { int unused; } tpool_t;

tpool_t *
tpool_create(uint_t min_threads, uint_t max_threads, uint_t linger,
    pthread_attr_t *attr)
{
    (void) min_threads; (void) max_threads; (void) linger; (void) attr;
    static tpool_t singleton;
    return (&singleton);
}

int
tpool_dispatch(tpool_t *tpool, void (*func)(void *), void *arg)
{
    (void) tpool;
    func(arg);
    return (0);
}

void tpool_wait(tpool_t *tpool) { (void) tpool; }
void tpool_destroy(tpool_t *tpool) { (void) tpool; }

void update_vdev_config_dev_strs(nvlist_t *nv) { (void) nv; }
void update_vdevs_config_dev_sysfs_path(nvlist_t *config) { (void) config; }

const char * const *
zpool_default_search_paths(size_t *count)
{
    static const char *paths[] = { "/dev" };
    *count = 1;
    return (paths);
}

int
zpool_find_import_blkid(libpc_handle_t *hdl, pthread_mutex_t *lock,
    avl_tree_t **slice_cache)
{
    (void) hdl; (void) lock;
    *slice_cache = NULL;
    return (ENOENT);
}

void
zpool_open_func(void *arg)
{
    rdsk_node_t *rn = arg;
    struct stat statbuf;
    nvlist_t *config = NULL;
    uint64_t vdev_guid = 0;
    int num_labels = 0;
    int fd;

    if (stat(rn->rn_name, &statbuf) != 0 ||
        (!S_ISREG(statbuf.st_mode) && !S_ISBLK(statbuf.st_mode)) ||
        (S_ISREG(statbuf.st_mode) && statbuf.st_size < SPA_MINDEVSIZE))
        return;

    fd = open(rn->rn_name, O_RDONLY | O_CLOEXEC);
    if (fd < 0)
        return;

    if (zpool_read_label(fd, &config, &num_labels) != 0 ||
        num_labels == 0) {
        (void) close(fd);
        if (config != NULL)
            nvlist_free(config);
        return;
    }

    if (nvlist_lookup_uint64(config, ZPOOL_CONFIG_GUID, &vdev_guid) != 0 ||
        (rn->rn_vdev_guid != 0 && rn->rn_vdev_guid != vdev_guid)) {
        (void) close(fd);
        nvlist_free(config);
        return;
    }

    (void) close(fd);
    rn->rn_config = config;
    rn->rn_num_labels = num_labels;
}

int
forkpress_zfs_init(void)
{
    kernel_init(SPA_MODE_READ | SPA_MODE_WRITE);
    return (0);
}

int
forkpress_zfs_fini(void)
{
    kernel_fini();
    return (0);
}

int
forkpress_zfs_pool_create(const char *pool, const char *backing_file)
{
    nvlist_t *nvroot, *child;
    int err;

    child = fnvlist_alloc();
    fnvlist_add_string(child, ZPOOL_CONFIG_TYPE, VDEV_TYPE_FILE);
    fnvlist_add_string(child, ZPOOL_CONFIG_PATH, backing_file);
    fnvlist_add_uint64(child, ZPOOL_CONFIG_IS_LOG, 0);

    nvroot = fnvlist_alloc();
    fnvlist_add_string(nvroot, ZPOOL_CONFIG_TYPE, VDEV_TYPE_ROOT);
    fnvlist_add_nvlist_array(nvroot, ZPOOL_CONFIG_CHILDREN,
        (const nvlist_t **)&child, 1);

    err = spa_create(pool, nvroot, NULL, NULL, NULL);

    fnvlist_free(child);
    fnvlist_free(nvroot);
    return (err);
}

int
forkpress_zfs_pool_import(const char *pool, const char *backing_file)
{
    nvlist_t *import_config = NULL;
    libpc_handle_t hdl = { 0 };
    char *paths[1];
    importargs_t iarg = { 0 };
    int err;

    paths[0] = (char *)backing_file;
    iarg.path = paths;
    iarg.paths = 1;
    iarg.poolname = pool;
    iarg.scan = B_TRUE;
    iarg.can_be_active = B_TRUE;
    hdl.lpc_ops = &libzpool_config_ops;

    err = zpool_find_config(&hdl, pool, &import_config, &iarg);
    if (err != 0)
        return (err);

    err = spa_import((char *)pool, import_config, NULL,
        ZFS_IMPORT_NORMAL | ZFS_IMPORT_ANY_HOST | ZFS_IMPORT_MISSING_LOG);
    fnvlist_free(import_config);
    return (err);
}

int
forkpress_zfs_pool_export(const char *pool)
{
    return (spa_export(pool, NULL, B_TRUE, B_FALSE));
}

static void
forkpress_zfs_ds_create_cb(objset_t *os, void *arg, cred_t *cr, dmu_tx_t *tx)
{
    (void) arg; (void) cr;
    (void) zap_create_claim(os, FORKPRESS_ZFS_ROOT_ZAP_OBJ,
        DMU_OT_DIRECTORY_CONTENTS, DMU_OT_NONE, 0, tx);
}

int
forkpress_zfs_ds_create(const char *fullname)
{
    return (dmu_objset_create(fullname, DMU_OST_OTHER, 0, NULL,
        forkpress_zfs_ds_create_cb, NULL));
}

int
forkpress_zfs_ds_destroy(const char *fullname)
{
    return (dsl_destroy_head(fullname));
}

int
forkpress_zfs_snap(const char *ds, const char *snapname)
{
    char full[ZFS_MAX_DATASET_NAME_LEN];
    nvlist_t *snaps = fnvlist_alloc();
    nvlist_t *errors = NULL;
    int err;

    if (snprintf(full, sizeof (full), "%s@%s", ds, snapname)
        >= (int) sizeof (full)) {
        fnvlist_free(snaps);
        return (ENAMETOOLONG);
    }

    fnvlist_add_boolean(snaps, full);
    err = dsl_dataset_snapshot(snaps, NULL, &errors);
    fnvlist_free(snaps);
    if (errors != NULL)
        fnvlist_free(errors);
    return (err);
}

int
forkpress_zfs_snap_destroy(const char *full)
{
    return (dsl_destroy_snapshot(full, B_FALSE));
}

int
forkpress_zfs_clone(const char *snap, const char *newds)
{
    return (dmu_objset_clone(newds, snap));
}

int
forkpress_zfs_rollback(const char *ds, const char *snapname)
{
    char tosnap[ZFS_MAX_DATASET_NAME_LEN];
    nvlist_t *result = NULL;
    int err;

    if (snprintf(tosnap, sizeof (tosnap), "%s@%s", ds, snapname)
        >= (int) sizeof (tosnap))
        return (ENAMETOOLONG);

    err = dsl_dataset_rollback(ds, tosnap, NULL, result);
    if (result != NULL)
        nvlist_free(result);
    return (err);
}

int
forkpress_zfs_promote(const char *clone)
{
    char conflict[ZFS_MAX_DATASET_NAME_LEN] = "";
    return (dsl_dataset_promote(clone, conflict));
}

int
forkpress_zfs_file_write(const char *ds, const char *path,
    const void *buf, size_t len)
{
    objset_t *os;
    dmu_tx_t *tx;
    uint64_t entry[2] = {0, 0};
    uint64_t fileobj;
    int err;

    err = dmu_objset_own(ds, DMU_OST_OTHER, B_FALSE, B_FALSE, FTAG, &os);
    if (err != 0)
        return (err);

    err = zap_lookup(os, FORKPRESS_ZFS_ROOT_ZAP_OBJ, path, 8, 2, entry);
    if (err != 0 && err != ENOENT)
        goto out;

    tx = dmu_tx_create(os);
    dmu_tx_hold_zap(tx, FORKPRESS_ZFS_ROOT_ZAP_OBJ, B_TRUE, path);
    if (entry[0] == 0) {
        dmu_tx_hold_write(tx, DMU_NEW_OBJECT, 0, len);
    } else {
        dmu_tx_hold_write(tx, entry[0], 0, len);
        dmu_tx_hold_free(tx, entry[0], 0, DMU_OBJECT_END);
    }
    err = dmu_tx_assign(tx, TXG_WAIT);
    if (err != 0) {
        dmu_tx_abort(tx);
        goto out;
    }

    if (entry[0] == 0) {
        fileobj = dmu_object_alloc(os, DMU_OT_PLAIN_FILE_CONTENTS, 0,
            DMU_OT_NONE, 0, tx);
    } else {
        fileobj = entry[0];
        (void) dmu_free_range(os, fileobj, 0, DMU_OBJECT_END, tx);
    }
    if (len > 0)
        dmu_write(os, fileobj, 0, len, buf, tx);

    entry[0] = fileobj;
    entry[1] = (uint64_t)len;
    err = zap_update(os, FORKPRESS_ZFS_ROOT_ZAP_OBJ, path, 8, 2, entry, tx);
    dmu_tx_commit(tx);
    if (err != 0)
        goto out;

    txg_wait_synced(dmu_objset_pool(os), 0);
out:
    dmu_objset_disown(os, B_FALSE, FTAG);
    return (err);
}

int
forkpress_zfs_file_read(const char *ds, const char *path,
    void *buf, size_t cap, size_t *out_len)
{
    objset_t *os;
    uint64_t entry[2];
    size_t to_copy;
    int err;

    err = dmu_objset_hold(ds, FTAG, &os);
    if (err != 0)
        return (err);

    err = zap_lookup(os, FORKPRESS_ZFS_ROOT_ZAP_OBJ, path, 8, 2, entry);
    if (err != 0)
        goto out;

    to_copy = entry[1];
    if (to_copy > cap)
        to_copy = cap;
    if (to_copy > 0) {
        err = dmu_read(os, entry[0], 0, to_copy, buf, DMU_READ_PREFETCH);
        if (err != 0)
            goto out;
    }
    if (out_len != NULL)
        *out_len = to_copy;
out:
    dmu_objset_rele(os, FTAG);
    return (err);
}

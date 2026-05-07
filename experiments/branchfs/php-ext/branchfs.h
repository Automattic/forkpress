#ifndef PHP_BRANCHFS_H
#define PHP_BRANCHFS_H

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "ext/standard/php_fopen_wrappers.h"
#include "ext/standard/php_filestat.h"
#include "main/php_streams.h"
#include <sqlite3.h>
#include <string.h>
#include <stdlib.h>
#include <sys/stat.h>
#include <time.h>
#include <fnmatch.h>
#include "ext/standard/php_array.h"

#define PHP_BRANCHFS_VERSION "0.1.0"
#define BRANCHFS_PROTO "branchfs"
#define BRANCHFS_MAX_PATH 4096
#define BRANCHFS_HASH_LEN 17
/* Blobs strictly larger than BRANCHFS_CHUNK_SIZE bytes are split across
 * rows in `blob_chunks`; smaller blobs stay inline in `blobs.data`. Must
 * stay in sync with scripts that materialize branch files from the store. */
#define BRANCHFS_CHUNK_SIZE (1024 * 1024)

typedef enum {
    BRANCHFS_BACKEND_SQLITE = 0,
    BRANCHFS_BACKEND_CAS = 1
} branchfs_backend_t;

extern zend_module_entry branchfs_module_entry;
#define phpext_branchfs_ptr &branchfs_module_entry

/* ---- Module globals ---- */
ZEND_BEGIN_MODULE_GLOBALS(branchfs)
    char *db_path;
    char *cas_store_path;
    char *wp_root;
    char *current_branch;
    int   active;
    int   intercepting;
    branchfs_backend_t backend;
    sqlite3 *db;
    void *cas_handle;
    const php_stream_wrapper_ops *orig_plain_ops;
ZEND_END_MODULE_GLOBALS(branchfs)

ZEND_EXTERN_MODULE_GLOBALS(branchfs)
#define BRANCHFS_G(v) ZEND_MODULE_GLOBALS_ACCESSOR(branchfs, v)

/* ---- Stream data for open file handles ---- */
typedef struct {
    char   *data;
    size_t  size;
    size_t  alloc;
    size_t  position;
    char   *path;
    int     branch_id;
    int     writable;
    int     modified;
} branchfs_stream_data_t;

/* ---- Dir stream data ---- */
typedef struct {
    char  **entries;
    int     count;
    int     position;
} branchfs_dir_data_t;

/* ---- Store operations ---- */
int  store_open(const char *db_path);
void store_close(void);
int  store_get_branch_id(const char *branch_name);
int  store_create_branch(const char *name, const char *parent);
int  store_read_file(int branch_id, const char *path, char **data, size_t *size);
int  store_write_file(int branch_id, const char *path, const char *data, size_t size);
int  store_stat_file(int branch_id, const char *path, int *is_dir, size_t *size, int *mode, time_t *mtime);
int  store_file_exists(int branch_id, const char *path);
int  store_list_dir(int branch_id, const char *dir_path, char ***entries, int *count);
int  store_mkdir(int branch_id, const char *path, int mode);
int  store_unlink(int branch_id, const char *path);
int  store_rename(int branch_id, const char *from, const char *to);
int  store_rmdir(int branch_id, const char *path);
char *store_compute_hash(const char *data, size_t size);

#endif /* PHP_BRANCHFS_H */

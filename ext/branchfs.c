/*
 * branchfs.c - PHP extension for branch-scoped WordPress preview
 *
 * Registers branchfs:// stream wrapper AND intercepts the plain files wrapper
 * so that ALL filesystem operations under the configured WP root transparently
 * route through a SQLite-backed content-addressed store with per-branch COW.
 */

#include "branchfs.h"

ZEND_DECLARE_MODULE_GLOBALS(branchfs)

/* Forward declarations */
static php_stream *branchfs_stream_opener(php_stream_wrapper *wrapper, const char *filename,
    const char *mode, int options, zend_string **opened_path,
    php_stream_context *context STREAMS_DC);
static int branchfs_url_stat(php_stream_wrapper *wrapper, const char *url, int flags,
    php_stream_statbuf *ssb, php_stream_context *context);
static php_stream *branchfs_dir_opener(php_stream_wrapper *wrapper, const char *filename,
    const char *mode, int options, zend_string **opened_path,
    php_stream_context *context STREAMS_DC);
static int branchfs_unlink(php_stream_wrapper *wrapper, const char *url, int options,
    php_stream_context *context);
static int branchfs_rename(php_stream_wrapper *wrapper, const char *url_from, const char *url_to,
    int options, php_stream_context *context);
static int branchfs_mkdir(php_stream_wrapper *wrapper, const char *url, int mode, int options,
    php_stream_context *context);
static int branchfs_rmdir(php_stream_wrapper *wrapper, const char *url, int options,
    php_stream_context *context);
static int branchfs_metadata(php_stream_wrapper *wrapper, const char *url, int options,
    void *value, php_stream_context *context);

/* ================================================================
 * Section 1: FNV-1a hash for content addressing
 * ================================================================ */

static uint64_t fnv1a_64(const char *data, size_t len) {
    uint64_t hash = 0xcbf29ce484222325ULL;
    for (size_t i = 0; i < len; i++) {
        hash ^= (uint8_t)data[i];
        hash *= 0x100000001b3ULL;
    }
    return hash;
}

char *store_compute_hash(const char *data, size_t size) {
    uint64_t h1 = fnv1a_64(data, size);
    /* Mix in size for extra collision resistance */
    uint64_t h2 = fnv1a_64((const char *)&size, sizeof(size));
    h1 ^= h2;
    char *hash = emalloc(BRANCHFS_HASH_LEN);
    snprintf(hash, BRANCHFS_HASH_LEN, "%016llx", (unsigned long long)h1);
    return hash;
}

/* ================================================================
 * Section 2: SQLite store operations
 * ================================================================ */

int store_open(const char *db_path) {
    if (BRANCHFS_G(db)) return 0;
    int rc = sqlite3_open(db_path, &BRANCHFS_G(db));
    if (rc != SQLITE_OK) {
        php_error_docref(NULL, E_WARNING, "branchfs: cannot open db %s: %s",
            db_path, sqlite3_errmsg(BRANCHFS_G(db)));
        BRANCHFS_G(db) = NULL;
        return -1;
    }
    sqlite3_exec(BRANCHFS_G(db), "PRAGMA journal_mode=WAL", NULL, NULL, NULL);
    sqlite3_exec(BRANCHFS_G(db), "PRAGMA foreign_keys=ON", NULL, NULL, NULL);
    sqlite3_busy_timeout(BRANCHFS_G(db), 5000);
    return 0;
}

void store_close(void) {
    if (BRANCHFS_G(db)) {
        sqlite3_close(BRANCHFS_G(db));
        BRANCHFS_G(db) = NULL;
    }
}

int store_get_branch_id(const char *branch_name) {
    if (!BRANCHFS_G(db) || !branch_name) return -1;
    sqlite3_stmt *stmt;
    int rc = sqlite3_prepare_v2(BRANCHFS_G(db),
        "SELECT id FROM branches WHERE name = ?", -1, &stmt, NULL);
    if (rc != SQLITE_OK) return -1;
    sqlite3_bind_text(stmt, 1, branch_name, -1, SQLITE_STATIC);
    int branch_id = -1;
    if (sqlite3_step(stmt) == SQLITE_ROW) {
        branch_id = sqlite3_column_int(stmt, 0);
    }
    sqlite3_finalize(stmt);
    return branch_id;
}

int store_create_branch(const char *name, const char *parent) {
    if (!BRANCHFS_G(db)) return -1;
    sqlite3_stmt *stmt;
    int rc = sqlite3_prepare_v2(BRANCHFS_G(db),
        "INSERT OR IGNORE INTO branches (name, parent_branch) VALUES (?, ?)",
        -1, &stmt, NULL);
    if (rc != SQLITE_OK) return -1;
    sqlite3_bind_text(stmt, 1, name, -1, SQLITE_STATIC);
    if (parent) {
        sqlite3_bind_text(stmt, 2, parent, -1, SQLITE_STATIC);
    } else {
        sqlite3_bind_null(stmt, 2);
    }
    rc = sqlite3_step(stmt);
    sqlite3_finalize(stmt);
    return (rc == SQLITE_DONE) ? store_get_branch_id(name) : -1;
}

/* Read a blob identified by `hash` into a freshly emalloc'd buffer.
 * Handles both inline (blobs.data NOT NULL) and chunked (blobs.data NULL,
 * rows in blob_chunks) layouts transparently. Returns 0 on success.
 * For chunked blobs, chunks are streamed into a single buffer (PHP stream
 * wrappers need the full payload eventually) but SQLite row sizes stay
 * bounded at BRANCHFS_CHUNK_SIZE. Caller owns the returned *out_data. */
static int load_blob_by_hash(const char *hash, char **out_data, size_t *out_size) {
    if (!BRANCHFS_G(db) || !hash) return -1;

    sqlite3_stmt *stmt;
    int rc = sqlite3_prepare_v2(BRANCHFS_G(db),
        "SELECT data, size FROM blobs WHERE hash = ?",
        -1, &stmt, NULL);
    if (rc != SQLITE_OK) return -1;
    sqlite3_bind_text(stmt, 1, hash, -1, SQLITE_STATIC);
    if (sqlite3_step(stmt) != SQLITE_ROW) {
        sqlite3_finalize(stmt);
        return -1;
    }

    int data_type = sqlite3_column_type(stmt, 0);
    size_t total = (size_t)sqlite3_column_int64(stmt, 1);

    if (data_type != SQLITE_NULL) {
        /* Legacy inline path. */
        int bsize = sqlite3_column_bytes(stmt, 0);
        const void *bdata = sqlite3_column_blob(stmt, 0);
        *out_size = (size_t)bsize;
        *out_data = emalloc(bsize + 1);
        if (bdata) memcpy(*out_data, bdata, bsize);
        (*out_data)[bsize] = '\0';
        sqlite3_finalize(stmt);
        return 0;
    }
    sqlite3_finalize(stmt);

    /* Chunked path: preallocate `size` and copy chunks in order. */
    char *buf = emalloc(total + 1);
    size_t offset = 0;

    sqlite3_stmt *cstmt;
    rc = sqlite3_prepare_v2(BRANCHFS_G(db),
        "SELECT data FROM blob_chunks WHERE blob_hash = ? ORDER BY chunk_no ASC",
        -1, &cstmt, NULL);
    if (rc != SQLITE_OK) { efree(buf); return -1; }
    sqlite3_bind_text(cstmt, 1, hash, -1, SQLITE_STATIC);

    while (sqlite3_step(cstmt) == SQLITE_ROW) {
        int clen = sqlite3_column_bytes(cstmt, 0);
        const void *cdata = sqlite3_column_blob(cstmt, 0);
        if (offset + (size_t)clen > total) {
            /* Corruption guard: don't overrun the allocation. */
            sqlite3_finalize(cstmt);
            efree(buf);
            return -1;
        }
        if (cdata) memcpy(buf + offset, cdata, clen);
        offset += (size_t)clen;
    }
    sqlite3_finalize(cstmt);

    if (offset != total) {
        /* Partial chunks — treat as corruption. */
        efree(buf);
        return -1;
    }
    buf[total] = '\0';
    *out_data = buf;
    *out_size = total;
    return 0;
}

/* Walk up branch chain to find a file (COW read) */
static int resolve_branch_chain(int branch_id, const char *path,
    char **out_data, size_t *out_size, int *out_is_dir, int *out_mode, time_t *out_mtime)
{
    if (!BRANCHFS_G(db)) return -1;

    int current_bid = branch_id;
    while (current_bid > 0) {
        sqlite3_stmt *stmt;
        int rc = sqlite3_prepare_v2(BRANCHFS_G(db),
            "SELECT f.blob_hash, f.is_dir, f.mode, f.mtime, b.size "
            "FROM files f LEFT JOIN blobs b ON f.blob_hash = b.hash "
            "WHERE f.branch_id = ? AND f.path = ?",
            -1, &stmt, NULL);
        if (rc != SQLITE_OK) return -1;

        sqlite3_bind_int(stmt, 1, current_bid);
        sqlite3_bind_text(stmt, 2, path, -1, SQLITE_STATIC);

        if (sqlite3_step(stmt) == SQLITE_ROW) {
            const char *blob_hash = (const char *)sqlite3_column_text(stmt, 0);
            int is_dir = sqlite3_column_int(stmt, 1);
            int mode = sqlite3_column_int(stmt, 2);
            time_t mtime = (time_t)sqlite3_column_int64(stmt, 3);

            if (out_is_dir) *out_is_dir = is_dir;
            if (out_mode) *out_mode = mode;
            if (out_mtime) *out_mtime = mtime;

            /* Tombstone: file explicitly deleted on this branch */
            if (!blob_hash && !is_dir) {
                sqlite3_finalize(stmt);
                return -2;
            }

            if (is_dir) {
                if (out_data) *out_data = NULL;
                if (out_size) *out_size = 0;
                sqlite3_finalize(stmt);
                return 0;
            }

            /* Always populate size from blobs.size when caller wants it — this
             * avoids a second round-trip through load_blob_by_hash for stat.
             * The LEFT JOIN guarantees column 4 is populated when blob_hash
             * exists in blobs. */
            if (out_size) *out_size = (size_t)sqlite3_column_int64(stmt, 4);

            if (out_data) {
                /* Cache hash before finalize: sqlite3 returns a pointer owned
                 * by the statement, which becomes invalid after finalize. */
                char hash_copy[BRANCHFS_HASH_LEN + 64];
                strncpy(hash_copy, blob_hash, sizeof(hash_copy) - 1);
                hash_copy[sizeof(hash_copy) - 1] = '\0';
                size_t loaded_size = 0;
                sqlite3_finalize(stmt);
                int rc2 = load_blob_by_hash(hash_copy, out_data, &loaded_size);
                if (rc2 != 0) return rc2;
                if (out_size) *out_size = loaded_size;
                return 0;
            }
            sqlite3_finalize(stmt);
            return 0;
        }
        sqlite3_finalize(stmt);

        /* Walk to parent branch */
        sqlite3_stmt *pstmt;
        rc = sqlite3_prepare_v2(BRANCHFS_G(db),
            "SELECT b2.id FROM branches b1 JOIN branches b2 ON b1.parent_branch = b2.name "
            "WHERE b1.id = ?",
            -1, &pstmt, NULL);
        if (rc != SQLITE_OK) return -1;
        sqlite3_bind_int(pstmt, 1, current_bid);
        if (sqlite3_step(pstmt) == SQLITE_ROW) {
            current_bid = sqlite3_column_int(pstmt, 0);
        } else {
            current_bid = -1;
        }
        sqlite3_finalize(pstmt);
    }
    return -1; /* Not found in any branch */
}

int store_read_file(int branch_id, const char *path, char **data, size_t *size) {
    return resolve_branch_chain(branch_id, path, data, size, NULL, NULL, NULL);
}

int store_write_file(int branch_id, const char *path, const char *data, size_t size) {
    if (!BRANCHFS_G(db)) return -1;

    char *hash = store_compute_hash(data, size);

    /* Insert blob (ignore if duplicate). For small blobs the payload goes
     * inline in blobs.data so reads are a single query. For large blobs we
     * store a metadata row with NULL data and spread the payload across
     * blob_chunks rows of at most BRANCHFS_CHUNK_SIZE bytes each. Keeping
     * row sizes bounded avoids SQLite's latency cliff around multi-MB rows
     * and lets the read path stream chunk-by-chunk. */
    if (size <= BRANCHFS_CHUNK_SIZE) {
        sqlite3_stmt *bstmt;
        int rc = sqlite3_prepare_v2(BRANCHFS_G(db),
            "INSERT OR IGNORE INTO blobs (hash, data, size) VALUES (?, ?, ?)",
            -1, &bstmt, NULL);
        if (rc != SQLITE_OK) { efree(hash); return -1; }
        sqlite3_bind_text(bstmt, 1, hash, -1, SQLITE_STATIC);
        sqlite3_bind_blob(bstmt, 2, data, (int)size, SQLITE_STATIC);
        sqlite3_bind_int64(bstmt, 3, (sqlite3_int64)size);
        sqlite3_step(bstmt);
        sqlite3_finalize(bstmt);
    } else {
        /* First check whether this hash is already persisted (dedup). */
        sqlite3_stmt *chk;
        int exists = 0;
        int rc = sqlite3_prepare_v2(BRANCHFS_G(db),
            "SELECT 1 FROM blobs WHERE hash = ?", -1, &chk, NULL);
        if (rc == SQLITE_OK) {
            sqlite3_bind_text(chk, 1, hash, -1, SQLITE_STATIC);
            if (sqlite3_step(chk) == SQLITE_ROW) exists = 1;
            sqlite3_finalize(chk);
        }

        if (!exists) {
            /* Metadata row with NULL data + chunks in blob_chunks. Wrap the
             * whole insert in a transaction so a crash leaves no orphan
             * metadata pointing at missing chunks. */
            sqlite3_exec(BRANCHFS_G(db), "BEGIN IMMEDIATE", NULL, NULL, NULL);
            sqlite3_stmt *bstmt;
            rc = sqlite3_prepare_v2(BRANCHFS_G(db),
                "INSERT OR IGNORE INTO blobs (hash, data, size) VALUES (?, NULL, ?)",
                -1, &bstmt, NULL);
            if (rc != SQLITE_OK) {
                sqlite3_exec(BRANCHFS_G(db), "ROLLBACK", NULL, NULL, NULL);
                efree(hash);
                return -1;
            }
            sqlite3_bind_text(bstmt, 1, hash, -1, SQLITE_STATIC);
            sqlite3_bind_int64(bstmt, 2, (sqlite3_int64)size);
            sqlite3_step(bstmt);
            sqlite3_finalize(bstmt);

            sqlite3_stmt *cstmt;
            rc = sqlite3_prepare_v2(BRANCHFS_G(db),
                "INSERT OR IGNORE INTO blob_chunks (blob_hash, chunk_no, data) VALUES (?, ?, ?)",
                -1, &cstmt, NULL);
            if (rc != SQLITE_OK) {
                sqlite3_exec(BRANCHFS_G(db), "ROLLBACK", NULL, NULL, NULL);
                efree(hash);
                return -1;
            }
            sqlite3_int64 chunk_no = 0;
            for (size_t off = 0; off < size; off += BRANCHFS_CHUNK_SIZE) {
                size_t clen = size - off;
                if (clen > BRANCHFS_CHUNK_SIZE) clen = BRANCHFS_CHUNK_SIZE;
                sqlite3_reset(cstmt);
                sqlite3_bind_text(cstmt, 1, hash, -1, SQLITE_STATIC);
                sqlite3_bind_int64(cstmt, 2, chunk_no);
                sqlite3_bind_blob(cstmt, 3, data + off, (int)clen, SQLITE_STATIC);
                if (sqlite3_step(cstmt) != SQLITE_DONE) {
                    sqlite3_finalize(cstmt);
                    sqlite3_exec(BRANCHFS_G(db), "ROLLBACK", NULL, NULL, NULL);
                    efree(hash);
                    return -1;
                }
                chunk_no++;
            }
            sqlite3_finalize(cstmt);
            sqlite3_exec(BRANCHFS_G(db), "COMMIT", NULL, NULL, NULL);
        }
    }

    /* Upsert file entry */
    sqlite3_stmt *fstmt;
    int frc = sqlite3_prepare_v2(BRANCHFS_G(db),
        "INSERT OR REPLACE INTO files (branch_id, path, blob_hash, mode, mtime, is_dir) "
        "VALUES (?, ?, ?, 33188, strftime('%s','now'), 0)",
        -1, &fstmt, NULL);
    if (frc != SQLITE_OK) { efree(hash); return -1; }
    sqlite3_bind_int(fstmt, 1, branch_id);
    sqlite3_bind_text(fstmt, 2, path, -1, SQLITE_STATIC);
    sqlite3_bind_text(fstmt, 3, hash, -1, SQLITE_STATIC);
    frc = sqlite3_step(fstmt);
    sqlite3_finalize(fstmt);
    efree(hash);
    return (frc == SQLITE_DONE) ? 0 : -1;
}

int store_stat_file(int branch_id, const char *path, int *is_dir, size_t *size, int *mode, time_t *mtime) {
    /* resolve_branch_chain now reads b.size directly via the LEFT JOIN, so
     * stat is a single query whether the blob is inline or chunked. */
    return resolve_branch_chain(branch_id, path, NULL, size, is_dir, mode, mtime);
}

int store_file_exists(int branch_id, const char *path) {
    int is_dir = 0;
    return resolve_branch_chain(branch_id, path, NULL, NULL, &is_dir, NULL, NULL) == 0;
}

int store_list_dir(int branch_id, const char *dir_path, char ***entries, int *count) {
    if (!BRANCHFS_G(db)) return -1;

    *entries = NULL;
    *count = 0;

    /* Collect from all branches in the chain */
    int capacity = 64;
    char **result = emalloc(sizeof(char*) * capacity);
    int n = 0;

    /* Track seen paths to handle COW overrides */
    char **seen = emalloc(sizeof(char*) * capacity);
    int nseen = 0;

    int current_bid = branch_id;
    while (current_bid > 0) {
        sqlite3_stmt *stmt;
        /* Match direct children: dir_path/X but not dir_path/X/Y */
        char like_pattern[BRANCHFS_MAX_PATH];
        size_t dirlen = strlen(dir_path);

        if (dirlen == 0) {
            /* Root directory - match paths without any '/' */
            snprintf(like_pattern, sizeof(like_pattern), "%%");
        } else {
            snprintf(like_pattern, sizeof(like_pattern), "%s/%%", dir_path);
        }

        int rc = sqlite3_prepare_v2(BRANCHFS_G(db),
            "SELECT path, blob_hash, is_dir FROM files WHERE branch_id = ? AND path LIKE ?",
            -1, &stmt, NULL);
        if (rc != SQLITE_OK) break;

        sqlite3_bind_int(stmt, 1, current_bid);
        sqlite3_bind_text(stmt, 2, like_pattern, -1, SQLITE_STATIC);

        while (sqlite3_step(stmt) == SQLITE_ROW) {
            const char *fpath = (const char *)sqlite3_column_text(stmt, 0);
            if (!fpath) continue;

            /* Extract the basename (direct child only) */
            const char *child_start;
            if (dirlen == 0) {
                child_start = fpath;
            } else {
                if (strncmp(fpath, dir_path, dirlen) != 0 || fpath[dirlen] != '/') continue;
                child_start = fpath + dirlen + 1;
            }

            /* Skip if has more slashes (not a direct child) */
            if (strchr(child_start, '/') != NULL) continue;
            if (*child_start == '\0') continue;

            /* Check if already seen (higher-priority branch) */
            int already = 0;
            for (int i = 0; i < nseen; i++) {
                if (strcmp(seen[i], child_start) == 0) { already = 1; break; }
            }
            if (already) continue;

            /* Track as seen */
            if (nseen >= capacity) {
                capacity *= 2;
                seen = erealloc(seen, sizeof(char*) * capacity);
                result = erealloc(result, sizeof(char*) * capacity);
            }
            seen[nseen] = estrdup(child_start);
            nseen++;

            /* Skip tombstones */
            const char *blob_hash = (const char *)sqlite3_column_text(stmt, 1);
            int is_dir = sqlite3_column_int(stmt, 2);
            if (!blob_hash && !is_dir) continue;

            result[n] = estrdup(child_start);
            n++;
        }
        sqlite3_finalize(stmt);

        /* Walk to parent */
        sqlite3_stmt *pstmt;
        rc = sqlite3_prepare_v2(BRANCHFS_G(db),
            "SELECT b2.id FROM branches b1 JOIN branches b2 ON b1.parent_branch = b2.name WHERE b1.id = ?",
            -1, &pstmt, NULL);
        if (rc != SQLITE_OK) break;
        sqlite3_bind_int(pstmt, 1, current_bid);
        if (sqlite3_step(pstmt) == SQLITE_ROW) {
            current_bid = sqlite3_column_int(pstmt, 0);
        } else {
            current_bid = -1;
        }
        sqlite3_finalize(pstmt);
    }

    for (int i = 0; i < nseen; i++) efree(seen[i]);
    efree(seen);

    *entries = result;
    *count = n;
    return 0;
}

int store_mkdir(int branch_id, const char *path, int mode) {
    if (!BRANCHFS_G(db)) return -1;
    sqlite3_stmt *stmt;
    int rc = sqlite3_prepare_v2(BRANCHFS_G(db),
        "INSERT OR REPLACE INTO files (branch_id, path, blob_hash, mode, mtime, is_dir) "
        "VALUES (?, ?, NULL, ?, strftime('%s','now'), 1)",
        -1, &stmt, NULL);
    if (rc != SQLITE_OK) return -1;
    sqlite3_bind_int(stmt, 1, branch_id);
    sqlite3_bind_text(stmt, 2, path, -1, SQLITE_STATIC);
    sqlite3_bind_int(stmt, 3, mode ? mode : 16877); /* 040755 */
    rc = sqlite3_step(stmt);
    sqlite3_finalize(stmt);
    return (rc == SQLITE_DONE) ? 0 : -1;
}

int store_unlink(int branch_id, const char *path) {
    if (!BRANCHFS_G(db)) return -1;
    /* Insert a tombstone */
    sqlite3_stmt *stmt;
    int rc = sqlite3_prepare_v2(BRANCHFS_G(db),
        "INSERT OR REPLACE INTO files (branch_id, path, blob_hash, mode, mtime, is_dir) "
        "VALUES (?, ?, NULL, 0, strftime('%s','now'), 0)",
        -1, &stmt, NULL);
    if (rc != SQLITE_OK) return -1;
    sqlite3_bind_int(stmt, 1, branch_id);
    sqlite3_bind_text(stmt, 2, path, -1, SQLITE_STATIC);
    rc = sqlite3_step(stmt);
    sqlite3_finalize(stmt);
    return (rc == SQLITE_DONE) ? 0 : -1;
}

int store_rename(int branch_id, const char *from, const char *to) {
    char *data = NULL;
    size_t size = 0;
    if (store_read_file(branch_id, from, &data, &size) != 0) return -1;
    if (store_write_file(branch_id, to, data, size) != 0) { if (data) efree(data); return -1; }
    if (data) efree(data);
    return store_unlink(branch_id, from);
}

int store_rmdir(int branch_id, const char *path) {
    return store_unlink(branch_id, path);
}

/* ================================================================
 * Section 3: Path resolution helpers
 * ================================================================ */

/* Normalize a branch-relative path by removing duplicate slashes and dot
 * segments. The store never uses a leading slash, but WordPress sometimes
 * builds branchfs:// URLs from ABSPATH plus an absolute-looking suffix, e.g.
 * branchfs://main//wp-admin/includes/plugin.php. */
static int normalize_branchfs_rel_path(const char *in, char *out, size_t out_len) {
    if (!in || !out || out_len == 0) return 0;

    const char *segs[BRANCHFS_MAX_PATH / 2];
    size_t seg_lens[BRANCHFS_MAX_PATH / 2];
    int nseg = 0;

    const char *p = in;
    while (*p == '/') p++;
    while (*p) {
        const char *q = p;
        while (*q && *q != '/') q++;
        size_t slen = q - p;
        if (slen == 0 || (slen == 1 && p[0] == '.')) {
            /* skip empty or "." */
        } else if (slen == 2 && p[0] == '.' && p[1] == '.') {
            if (nseg > 0) nseg--;
        } else {
            if (nseg >= (int)(sizeof(segs) / sizeof(segs[0]))) return 0;
            segs[nseg] = p;
            seg_lens[nseg] = slen;
            nseg++;
        }
        p = q;
        while (*p == '/') p++;
    }

    size_t pos = 0;
    for (int i = 0; i < nseg; i++) {
        if (i > 0) {
            if (pos + 1 >= out_len) return 0;
            out[pos++] = '/';
        }
        if (pos + seg_lens[i] >= out_len) return 0;
        memcpy(out + pos, segs[i], seg_lens[i]);
        pos += seg_lens[i];
    }
    out[pos] = '\0';
    return 1;
}

/* Parse branchfs://branch/path → branch name + relative path */
static int parse_branchfs_url(const char *url, char *branch, size_t branch_len,
    char *path, size_t path_len)
{
    const char *prefix = BRANCHFS_PROTO "://";
    size_t plen = strlen(prefix);
    if (strncmp(url, prefix, plen) != 0) return -1;

    const char *rest = url + plen;
    const char *slash = strchr(rest, '/');
    if (!slash) {
        /* Just branch name, root path */
        snprintf(branch, branch_len, "%.*s", (int)(strlen(rest)), rest);
        path[0] = '\0';
        return 0;
    }
    snprintf(branch, branch_len, "%.*s", (int)(slash - rest), rest);
    return normalize_branchfs_rel_path(slash + 1, path, path_len) ? 0 : -1;
}

/* Resolve a path to branch-relative. Returns 1 if under WP root, 0 otherwise. */
/* Forward declaration — defined later alongside the function overrides. */
static int branchfs_canonicalize(const char *in, char *out, size_t out_len);

static int resolve_to_wp_relative(const char *filename, char *rel_path, size_t rel_len) {
    if (!BRANCHFS_G(wp_root) || !BRANCHFS_G(active)) return 0;

    /* Accept "branchfs://<branch>/<path>" as an alias for "<wp_root>/<path>".
     * This is what shows up after the OPcache fix, where ABSPATH is set to
     * "branchfs://main/" so opcache keys per branch. WP then constructs
     * paths like "branchfs://main/wp-includes/blocks/..." and passes them
     * to glob/realpath/file_exists/etc. Without this, those calls would
     * fall through to the OS handler (which can't see the store). */
    const char *proto = "branchfs://";
    size_t proto_len = strlen(proto);
    if (strncmp(filename, proto, proto_len) == 0) {
        const char *after = filename + proto_len;
        const char *slash = strchr(after, '/');
        const char *path_part;
        if (slash) {
            /* "branchfs://branch/foo" -> path_part = "/foo" */
            path_part = slash;
        } else {
            /* "branchfs://branch" -> root */
            path_part = "/";
        }
        char joined[BRANCHFS_MAX_PATH];
        snprintf(joined, sizeof(joined), "%s%s", BRANCHFS_G(wp_root), path_part);
        return resolve_to_wp_relative(joined, rel_path, rel_len);
    }

    char joined[BRANCHFS_MAX_PATH];
    const char *abs_in;

    if (filename[0] == '/') {
        abs_in = filename;
    } else {
        char cwd[BRANCHFS_MAX_PATH];
        if (getcwd(cwd, sizeof(cwd)) == NULL) return 0;
        snprintf(joined, sizeof(joined), "%s/%s", cwd, filename);
        abs_in = joined;
    }

    /* Canonicalize (resolve . and ..) before matching root, so paths like
     * <wp_root>/wp-admin/./css/../css/foo.css match the store's stored
     * relative path "wp-admin/css/foo.css". */
    char abs_path[BRANCHFS_MAX_PATH];
    if (!branchfs_canonicalize(abs_in, abs_path, sizeof(abs_path))) return 0;

    size_t root_len = strlen(BRANCHFS_G(wp_root));
    if (strncmp(abs_path, BRANCHFS_G(wp_root), root_len) != 0) return 0;

    if (abs_path[root_len] == '/') {
        snprintf(rel_path, rel_len, "%s", abs_path + root_len + 1);
    } else if (abs_path[root_len] == '\0') {
        rel_path[0] = '\0';
    } else {
        return 0;
    }

    size_t pl = strlen(rel_path);
    while (pl > 0 && rel_path[pl - 1] == '/') rel_path[--pl] = '\0';
    return 1;
}

/* Check if path should be excluded from interception (e.g., the SQLite DB itself) */
static int should_exclude(const char *filename) {
    if (!filename) return 1;
    if (BRANCHFS_G(db_path) && strcmp(filename, BRANCHFS_G(db_path)) == 0) return 1;
    /* Exclude WAL/SHM files */
    if (BRANCHFS_G(db_path)) {
        size_t dblen = strlen(BRANCHFS_G(db_path));
        if (strncmp(filename, BRANCHFS_G(db_path), dblen) == 0) {
            const char *suffix = filename + dblen;
            if (strcmp(suffix, "-wal") == 0 || strcmp(suffix, "-shm") == 0 ||
                strcmp(suffix, "-journal") == 0) return 1;
        }
    }
    return 0;
}

/* Get current branch ID */
static int get_current_branch_id(void) {
    if (!BRANCHFS_G(current_branch)) return -1;
    return store_get_branch_id(BRANCHFS_G(current_branch));
}

/* ================================================================
 * Section 4: Stream ops for open file handles
 * ================================================================ */

static ssize_t branchfs_stream_read(php_stream *stream, char *buf, size_t count) {
    branchfs_stream_data_t *sd = (branchfs_stream_data_t *)stream->abstract;
    if (!sd || !sd->data) return -1;

    size_t remaining = sd->size - sd->position;
    if (count > remaining) count = remaining;
    if (count == 0) {
        stream->eof = 1;
        return 0;
    }
    memcpy(buf, sd->data + sd->position, count);
    sd->position += count;
    if (sd->position >= sd->size) stream->eof = 1;
    return (ssize_t)count;
}

static ssize_t branchfs_stream_write(php_stream *stream, const char *buf, size_t count) {
    branchfs_stream_data_t *sd = (branchfs_stream_data_t *)stream->abstract;
    if (!sd || !sd->writable) return -1;

    size_t needed = sd->position + count;
    if (needed > sd->alloc) {
        size_t new_alloc = needed * 2;
        if (new_alloc < 4096) new_alloc = 4096;
        sd->data = erealloc(sd->data, new_alloc + 1);
        sd->alloc = new_alloc;
    }

    /* Fill gap with zeros if writing past end */
    if (sd->position > sd->size) {
        memset(sd->data + sd->size, 0, sd->position - sd->size);
    }

    memcpy(sd->data + sd->position, buf, count);
    sd->position += count;
    if (sd->position > sd->size) sd->size = sd->position;
    sd->data[sd->size] = '\0';
    sd->modified = 1;
    return (ssize_t)count;
}

static int branchfs_stream_close(php_stream *stream, int close_handle) {
    branchfs_stream_data_t *sd = (branchfs_stream_data_t *)stream->abstract;
    if (!sd) return 0;

    /* Flush modified data back to store */
    if (sd->modified && sd->writable && sd->path && sd->branch_id > 0) {
        store_write_file(sd->branch_id, sd->path, sd->data ? sd->data : "", sd->size);
        php_clear_stat_cache(1, NULL, 0);
    }

    if (sd->data) efree(sd->data);
    if (sd->path) efree(sd->path);
    efree(sd);
    stream->abstract = NULL;
    return 0;
}

static int branchfs_stream_flush(php_stream *stream) {
    branchfs_stream_data_t *sd = (branchfs_stream_data_t *)stream->abstract;
    if (!sd) return -1;

    if (sd->modified && sd->writable && sd->path && sd->branch_id > 0) {
        store_write_file(sd->branch_id, sd->path, sd->data ? sd->data : "", sd->size);
        sd->modified = 0;
    }
    return 0;
}

static int branchfs_stream_seek(php_stream *stream, zend_off_t offset, int whence,
    zend_off_t *newoffset)
{
    branchfs_stream_data_t *sd = (branchfs_stream_data_t *)stream->abstract;
    if (!sd) return -1;

    zend_off_t new_pos;
    switch (whence) {
        case SEEK_SET: new_pos = offset; break;
        case SEEK_CUR: new_pos = (zend_off_t)sd->position + offset; break;
        case SEEK_END: new_pos = (zend_off_t)sd->size + offset; break;
        default: return -1;
    }
    if (new_pos < 0) return -1;
    sd->position = (size_t)new_pos;
    if (sd->position < sd->size) stream->eof = 0;
    *newoffset = new_pos;
    return 0;
}

static int branchfs_stream_stat(php_stream *stream, php_stream_statbuf *ssb) {
    branchfs_stream_data_t *sd = (branchfs_stream_data_t *)stream->abstract;
    if (!sd) return -1;

    memset(&ssb->sb, 0, sizeof(ssb->sb));
    ssb->sb.st_size = sd->size;
    ssb->sb.st_mode = S_IFREG | 0644;
    ssb->sb.st_nlink = 1;
    ssb->sb.st_mtime = time(NULL);
    ssb->sb.st_atime = ssb->sb.st_mtime;
    ssb->sb.st_ctime = ssb->sb.st_mtime;
    return 0;
}

static int branchfs_stream_set_option(php_stream *stream, int option, int value, void *ptrparam) {
    return PHP_STREAM_OPTION_RETURN_NOTIMPL;
}

static const php_stream_ops branchfs_stream_ops = {
    branchfs_stream_write,
    branchfs_stream_read,
    branchfs_stream_close,
    branchfs_stream_flush,
    "branchfs/file",
    branchfs_stream_seek,
    NULL, /* cast */
    branchfs_stream_stat,
    branchfs_stream_set_option
};

/* ================================================================
 * Section 5: Directory stream ops
 * ================================================================ */

static ssize_t branchfs_dirstream_read(php_stream *stream, char *buf, size_t count) {
    branchfs_dir_data_t *dd = (branchfs_dir_data_t *)stream->abstract;
    if (!dd) return 0;

    if (count < sizeof(php_stream_dirent)) return 0;
    if (dd->position >= dd->count) return 0;

    php_stream_dirent *ent = (php_stream_dirent *)buf;
    snprintf(ent->d_name, sizeof(ent->d_name), "%s", dd->entries[dd->position]);
    dd->position++;
    return sizeof(php_stream_dirent);
}

static int branchfs_dirstream_close(php_stream *stream, int close_handle) {
    branchfs_dir_data_t *dd = (branchfs_dir_data_t *)stream->abstract;
    if (!dd) return 0;
    for (int i = 0; i < dd->count; i++) {
        if (dd->entries[i]) efree(dd->entries[i]);
    }
    if (dd->entries) efree(dd->entries);
    efree(dd);
    stream->abstract = NULL;
    return 0;
}

static int branchfs_dirstream_seek(php_stream *stream, zend_off_t offset, int whence,
    zend_off_t *newoffset)
{
    branchfs_dir_data_t *dd = (branchfs_dir_data_t *)stream->abstract;
    if (!dd) return -1;
    if (whence == SEEK_SET && offset == 0) {
        dd->position = 0;
        *newoffset = 0;
        return 0;
    }
    return -1;
}

static const php_stream_ops branchfs_dirstream_ops = {
    NULL, /* write */
    branchfs_dirstream_read,
    branchfs_dirstream_close,
    NULL, /* flush */
    "branchfs/dir",
    branchfs_dirstream_seek,
    NULL, NULL, NULL
};

/* ================================================================
 * Section 6: Internal open helpers (shared by branchfs:// and interceptor)
 * ================================================================ */

static php_stream *do_open_file(int branch_id, const char *rel_path, const char *mode,
    int options, zend_string **opened_path STREAMS_DC)
{
    branchfs_stream_data_t *sd = ecalloc(1, sizeof(branchfs_stream_data_t));
    sd->path = estrdup(rel_path);
    sd->branch_id = branch_id;
    sd->writable = (strchr(mode, 'w') || strchr(mode, 'a') || strchr(mode, '+') ||
                    strchr(mode, 'x') || strchr(mode, 'c'));

    if (strchr(mode, 'w') || strchr(mode, 'x')) {
        /* Write/create: start empty */
        sd->data = emalloc(4096);
        sd->data[0] = '\0';
        sd->size = 0;
        sd->alloc = 4096;
        sd->position = 0;
        sd->modified = 1;
    } else if (strchr(mode, 'c')) {
        /* Open for writing, create if not exist, don't truncate */
        char *existing = NULL;
        size_t esize = 0;
        if (store_read_file(branch_id, rel_path, &existing, &esize) == 0 && existing) {
            sd->data = existing;
            sd->size = esize;
            sd->alloc = esize;
        } else {
            sd->data = emalloc(4096);
            sd->data[0] = '\0';
            sd->size = 0;
            sd->alloc = 4096;
        }
        sd->position = 0;
        sd->modified = 0;
    } else {
        /* Read: load from store */
        char *data = NULL;
        size_t size = 0;
        int rc = store_read_file(branch_id, rel_path, &data, &size);
        if (rc != 0) {
            if (options & REPORT_ERRORS) {
                php_error_docref(NULL, E_WARNING, "branchfs: file not found: %s", rel_path);
            }
            efree(sd->path);
            efree(sd);
            return NULL;
        }
        sd->data = data;
        sd->size = size;
        sd->alloc = size;
        sd->position = 0;

        if (strchr(mode, 'a')) {
            sd->position = size;
            sd->modified = 0;
        }
    }

    php_stream *stream = php_stream_alloc(&branchfs_stream_ops, sd, NULL, mode);
    if (!stream) {
        if (sd->data) efree(sd->data);
        efree(sd->path);
        efree(sd);
        return NULL;
    }

    return stream;
}

static php_stream *do_open_dir(int branch_id, const char *rel_path STREAMS_DC) {
    char **entries = NULL;
    int count = 0;
    if (store_list_dir(branch_id, rel_path, &entries, &count) != 0) return NULL;

    branchfs_dir_data_t *dd = ecalloc(1, sizeof(branchfs_dir_data_t));
    dd->entries = entries;
    dd->count = count;
    dd->position = 0;

    php_stream *stream = php_stream_alloc(&branchfs_dirstream_ops, dd, NULL, "r");
    if (!stream) {
        for (int i = 0; i < count; i++) efree(entries[i]);
        efree(entries);
        efree(dd);
        return NULL;
    }
    stream->flags |= PHP_STREAM_FLAG_IS_DIR;
    return stream;
}

static int do_url_stat(int branch_id, const char *rel_path, php_stream_statbuf *ssb) {
    int is_dir = 0;
    size_t size = 0;
    int mode = 0;
    time_t mtime = 0;

    int rc = store_stat_file(branch_id, rel_path, &is_dir, &size, &mode, &mtime);
    if (rc != 0) return -1;

    memset(&ssb->sb, 0, sizeof(ssb->sb));
    if (is_dir) {
        ssb->sb.st_mode = S_IFDIR | 0755;
        ssb->sb.st_size = 0;
    } else {
        ssb->sb.st_mode = S_IFREG | (mode & 0777 ? mode & 0777 : 0644);
        ssb->sb.st_size = size;
    }
    ssb->sb.st_nlink = 1;
    ssb->sb.st_mtime = mtime ? mtime : time(NULL);
    ssb->sb.st_atime = ssb->sb.st_mtime;
    ssb->sb.st_ctime = ssb->sb.st_mtime;
    ssb->sb.st_uid = getuid();
    ssb->sb.st_gid = getgid();
    return 0;
}

/* ================================================================
 * Section 7: branchfs:// wrapper ops
 * ================================================================ */

static php_stream *branchfs_stream_opener(php_stream_wrapper *wrapper, const char *filename,
    const char *mode, int options, zend_string **opened_path,
    php_stream_context *context STREAMS_DC)
{
    char branch[256], path[BRANCHFS_MAX_PATH];
    if (parse_branchfs_url(filename, branch, sizeof(branch), path, sizeof(path)) != 0) return NULL;

    int branch_id = store_get_branch_id(branch);
    if (branch_id < 0) return NULL;

    php_stream *stream = do_open_file(branch_id, path, mode, options, opened_path STREAMS_REL_CC);
    if (stream && opened_path) {
        char normalized[BRANCHFS_MAX_PATH + 280];
        if (path[0] == '\0') {
            snprintf(normalized, sizeof(normalized), "branchfs://%s", branch);
        } else {
            snprintf(normalized, sizeof(normalized), "branchfs://%s/%s", branch, path);
        }
        *opened_path = zend_string_init(normalized, strlen(normalized), 0);
    }
    return stream;
}

static int branchfs_url_stat(php_stream_wrapper *wrapper, const char *url, int flags,
    php_stream_statbuf *ssb, php_stream_context *context)
{
    char branch[256], path[BRANCHFS_MAX_PATH];
    if (parse_branchfs_url(url, branch, sizeof(branch), path, sizeof(path)) != 0) return -1;
    int branch_id = store_get_branch_id(branch);
    if (branch_id < 0) return -1;
    return do_url_stat(branch_id, path, ssb);
}

static php_stream *branchfs_dir_opener(php_stream_wrapper *wrapper, const char *filename,
    const char *mode, int options, zend_string **opened_path,
    php_stream_context *context STREAMS_DC)
{
    char branch[256], path[BRANCHFS_MAX_PATH];
    if (parse_branchfs_url(filename, branch, sizeof(branch), path, sizeof(path)) != 0) return NULL;
    int branch_id = store_get_branch_id(branch);
    if (branch_id < 0) return NULL;
    php_stream *stream = do_open_dir(branch_id, path STREAMS_REL_CC);
    if (stream && opened_path) {
        char normalized[BRANCHFS_MAX_PATH + 280];
        if (path[0] == '\0') {
            snprintf(normalized, sizeof(normalized), "branchfs://%s", branch);
        } else {
            snprintf(normalized, sizeof(normalized), "branchfs://%s/%s", branch, path);
        }
        *opened_path = zend_string_init(normalized, strlen(normalized), 0);
    }
    return stream;
}

static int branchfs_unlink(php_stream_wrapper *wrapper, const char *url, int options,
    php_stream_context *context)
{
    char branch[256], path[BRANCHFS_MAX_PATH];
    if (parse_branchfs_url(url, branch, sizeof(branch), path, sizeof(path)) != 0) return 0;
    int branch_id = store_get_branch_id(branch);
    if (branch_id < 0) return 0;
    int ret = store_unlink(branch_id, path) == 0 ? 1 : 0;
    if (ret) php_clear_stat_cache(1, NULL, 0);
    return ret;
}

static int branchfs_rename(php_stream_wrapper *wrapper, const char *url_from, const char *url_to,
    int options, php_stream_context *context)
{
    char branch1[256], path1[BRANCHFS_MAX_PATH];
    char branch2[256], path2[BRANCHFS_MAX_PATH];
    if (parse_branchfs_url(url_from, branch1, sizeof(branch1), path1, sizeof(path1)) != 0) return 0;
    if (parse_branchfs_url(url_to, branch2, sizeof(branch2), path2, sizeof(path2)) != 0) return 0;
    int branch_id = store_get_branch_id(branch1);
    if (branch_id < 0) return 0;
    int ret = store_rename(branch_id, path1, path2) == 0 ? 1 : 0;
    if (ret) php_clear_stat_cache(1, NULL, 0);
    return ret;
}

static int branchfs_mkdir(php_stream_wrapper *wrapper, const char *url, int mode, int options,
    php_stream_context *context)
{
    char branch[256], path[BRANCHFS_MAX_PATH];
    if (parse_branchfs_url(url, branch, sizeof(branch), path, sizeof(path)) != 0) return 0;
    int branch_id = store_get_branch_id(branch);
    if (branch_id < 0) return 0;

    if (options & PHP_STREAM_MKDIR_RECURSIVE) {
        char tmp[BRANCHFS_MAX_PATH];
        snprintf(tmp, sizeof(tmp), "%s", path);
        for (char *p = tmp + 1; *p; p++) {
            if (*p == '/') {
                *p = '\0';
                store_mkdir(branch_id, tmp, mode);
                *p = '/';
            }
        }
    }
    int ret = store_mkdir(branch_id, path, mode) == 0 ? 1 : 0;
    if (ret) php_clear_stat_cache(1, NULL, 0);
    return ret;
}

static int branchfs_rmdir(php_stream_wrapper *wrapper, const char *url, int options,
    php_stream_context *context)
{
    char branch[256], path[BRANCHFS_MAX_PATH];
    if (parse_branchfs_url(url, branch, sizeof(branch), path, sizeof(path)) != 0) return 0;
    int branch_id = store_get_branch_id(branch);
    if (branch_id < 0) return 0;
    return store_rmdir(branch_id, path) == 0 ? 1 : 0;
}

static int branchfs_metadata(php_stream_wrapper *wrapper, const char *url, int options,
    void *value, php_stream_context *context)
{
    return 1; /* Success - metadata changes are no-ops for now */
}

static const php_stream_wrapper_ops branchfs_wrapper_ops = {
    branchfs_stream_opener,
    NULL, /* stream_closer */
    NULL, /* stream_stat */
    branchfs_url_stat,
    branchfs_dir_opener,
    BRANCHFS_PROTO,
    branchfs_unlink,
    branchfs_rename,
    branchfs_mkdir,
    branchfs_rmdir,
    branchfs_metadata
};

static php_stream_wrapper branchfs_wrapper = {
    &branchfs_wrapper_ops,
    NULL,
    0  /* not a URL wrapper */
};

/* ================================================================
 * Section 8: Plain files wrapper interception
 * ================================================================ */

static php_stream *intercept_stream_opener(php_stream_wrapper *wrapper, const char *filename,
    const char *mode, int options, zend_string **opened_path,
    php_stream_context *context STREAMS_DC)
{
    if (BRANCHFS_G(active) && BRANCHFS_G(current_branch) && !BRANCHFS_G(intercepting)) {
        char rel_path[BRANCHFS_MAX_PATH];

        /* Skip protocol URLs */
        if (strstr(filename, "://") != NULL) goto passthrough;

        /* Skip excluded paths */
        if (filename[0] == '/' && should_exclude(filename)) goto passthrough;

        if (resolve_to_wp_relative(filename, rel_path, sizeof(rel_path))) {
            int branch_id = get_current_branch_id();
            if (branch_id > 0) {
                BRANCHFS_G(intercepting) = 1;
                php_stream *s = do_open_file(branch_id, rel_path, mode, options, opened_path STREAMS_REL_CC);
                BRANCHFS_G(intercepting) = 0;
                if (s) {
                    /* Set orig_path to the real filesystem path so __FILE__/__DIR__ work */
                    if (opened_path && filename[0] == '/') {
                        *opened_path = zend_string_init(filename, strlen(filename), 0);
                    }
                    return s;
                }
                /* If not found in store and mode is read, fall through to real FS as fallback */
                if (!strchr(mode, 'w') && !strchr(mode, 'a') && !strchr(mode, 'x') && !strchr(mode, 'c')) {
                    goto passthrough;
                }
                return NULL;
            }
        }
    }

passthrough:
    if (BRANCHFS_G(orig_plain_ops) && BRANCHFS_G(orig_plain_ops)->stream_opener) {
        return BRANCHFS_G(orig_plain_ops)->stream_opener(wrapper, filename, mode, options,
            opened_path, context STREAMS_REL_CC);
    }
    return NULL;
}

static int intercept_url_stat(php_stream_wrapper *wrapper, const char *url, int flags,
    php_stream_statbuf *ssb, php_stream_context *context)
{
    if (BRANCHFS_G(active) && BRANCHFS_G(current_branch) && !BRANCHFS_G(intercepting)) {
        char rel_path[BRANCHFS_MAX_PATH];
        if (strstr(url, "://") != NULL) goto passthrough;
        if (url[0] == '/' && should_exclude(url)) goto passthrough;

        if (resolve_to_wp_relative(url, rel_path, sizeof(rel_path))) {
            int branch_id = get_current_branch_id();
            if (branch_id > 0) {
                BRANCHFS_G(intercepting) = 1;
                int rc = do_url_stat(branch_id, rel_path, ssb);
                BRANCHFS_G(intercepting) = 0;
                if (rc == 0) return 0;
                /* Fall through to real FS */
            }
        }
    }

passthrough:
    if (BRANCHFS_G(orig_plain_ops) && BRANCHFS_G(orig_plain_ops)->url_stat) {
        return BRANCHFS_G(orig_plain_ops)->url_stat(wrapper, url, flags, ssb, context);
    }
    return -1;
}

static php_stream *intercept_dir_opener(php_stream_wrapper *wrapper, const char *filename,
    const char *mode, int options, zend_string **opened_path,
    php_stream_context *context STREAMS_DC)
{
    if (BRANCHFS_G(active) && BRANCHFS_G(current_branch) && !BRANCHFS_G(intercepting)) {
        char rel_path[BRANCHFS_MAX_PATH];
        if (strstr(filename, "://") != NULL) goto passthrough;
        if (filename[0] == '/' && should_exclude(filename)) goto passthrough;

        if (resolve_to_wp_relative(filename, rel_path, sizeof(rel_path))) {
            int branch_id = get_current_branch_id();
            if (branch_id > 0) {
                BRANCHFS_G(intercepting) = 1;
                php_stream *s = do_open_dir(branch_id, rel_path STREAMS_REL_CC);
                BRANCHFS_G(intercepting) = 0;
                if (s) return s;
            }
        }
    }

passthrough:
    if (BRANCHFS_G(orig_plain_ops) && BRANCHFS_G(orig_plain_ops)->dir_opener) {
        return BRANCHFS_G(orig_plain_ops)->dir_opener(wrapper, filename, mode, options,
            opened_path, context STREAMS_REL_CC);
    }
    return NULL;
}

static int intercept_unlink(php_stream_wrapper *wrapper, const char *url, int options,
    php_stream_context *context)
{
    if (BRANCHFS_G(active) && BRANCHFS_G(current_branch) && !BRANCHFS_G(intercepting)) {
        char rel_path[BRANCHFS_MAX_PATH];
        if (strstr(url, "://") != NULL) goto passthrough;
        if (resolve_to_wp_relative(url, rel_path, sizeof(rel_path))) {
            int branch_id = get_current_branch_id();
            if (branch_id > 0) {
                int ret = store_unlink(branch_id, rel_path) == 0 ? 1 : 0;
                if (ret) php_clear_stat_cache(1, NULL, 0);
                return ret;
            }
        }
    }
passthrough:
    if (BRANCHFS_G(orig_plain_ops) && BRANCHFS_G(orig_plain_ops)->unlink) {
        return BRANCHFS_G(orig_plain_ops)->unlink(wrapper, url, options, context);
    }
    return 0;
}

static int intercept_rename(php_stream_wrapper *wrapper, const char *url_from, const char *url_to,
    int options, php_stream_context *context)
{
    if (BRANCHFS_G(active) && BRANCHFS_G(current_branch) && !BRANCHFS_G(intercepting)) {
        char rel_from[BRANCHFS_MAX_PATH], rel_to[BRANCHFS_MAX_PATH];
        if (resolve_to_wp_relative(url_from, rel_from, sizeof(rel_from)) &&
            resolve_to_wp_relative(url_to, rel_to, sizeof(rel_to))) {
            int branch_id = get_current_branch_id();
            if (branch_id > 0) {
                int ret = store_rename(branch_id, rel_from, rel_to) == 0 ? 1 : 0;
                if (ret) php_clear_stat_cache(1, NULL, 0);
                return ret;
            }
        }
    }
    if (BRANCHFS_G(orig_plain_ops) && BRANCHFS_G(orig_plain_ops)->rename) {
        return BRANCHFS_G(orig_plain_ops)->rename(wrapper, url_from, url_to, options, context);
    }
    return 0;
}

static int intercept_mkdir(php_stream_wrapper *wrapper, const char *url, int mode, int options,
    php_stream_context *context)
{
    if (BRANCHFS_G(active) && BRANCHFS_G(current_branch) && !BRANCHFS_G(intercepting)) {
        char rel_path[BRANCHFS_MAX_PATH];
        if (strstr(url, "://") != NULL) goto passthrough;
        if (resolve_to_wp_relative(url, rel_path, sizeof(rel_path))) {
            int branch_id = get_current_branch_id();
            if (branch_id > 0) {
                if (options & PHP_STREAM_MKDIR_RECURSIVE) {
                    char tmp[BRANCHFS_MAX_PATH];
                    snprintf(tmp, sizeof(tmp), "%s", rel_path);
                    for (char *p = tmp + 1; *p; p++) {
                        if (*p == '/') {
                            *p = '\0';
                            store_mkdir(branch_id, tmp, mode);
                            *p = '/';
                        }
                    }
                }
                int ret = store_mkdir(branch_id, rel_path, mode) == 0 ? 1 : 0;
                if (ret) php_clear_stat_cache(1, NULL, 0);
                return ret;
            }
        }
    }
passthrough:
    if (BRANCHFS_G(orig_plain_ops) && BRANCHFS_G(orig_plain_ops)->stream_mkdir) {
        return BRANCHFS_G(orig_plain_ops)->stream_mkdir(wrapper, url, mode, options, context);
    }
    return 0;
}

static int intercept_rmdir(php_stream_wrapper *wrapper, const char *url, int options,
    php_stream_context *context)
{
    if (BRANCHFS_G(active) && BRANCHFS_G(current_branch) && !BRANCHFS_G(intercepting)) {
        char rel_path[BRANCHFS_MAX_PATH];
        if (resolve_to_wp_relative(url, rel_path, sizeof(rel_path))) {
            int branch_id = get_current_branch_id();
            if (branch_id > 0) return store_rmdir(branch_id, rel_path) == 0 ? 1 : 0;
        }
    }
    if (BRANCHFS_G(orig_plain_ops) && BRANCHFS_G(orig_plain_ops)->stream_rmdir) {
        return BRANCHFS_G(orig_plain_ops)->stream_rmdir(wrapper, url, options, context);
    }
    return 0;
}

static int intercept_metadata(php_stream_wrapper *wrapper, const char *url, int options,
    void *value, php_stream_context *context)
{
    if (BRANCHFS_G(active) && BRANCHFS_G(current_branch) && !BRANCHFS_G(intercepting)) {
        char rel_path[BRANCHFS_MAX_PATH];
        if (resolve_to_wp_relative(url, rel_path, sizeof(rel_path))) {
            int branch_id = get_current_branch_id();
            if (branch_id > 0) return 1; /* Pretend success */
        }
    }
    if (BRANCHFS_G(orig_plain_ops) && BRANCHFS_G(orig_plain_ops)->stream_metadata) {
        return BRANCHFS_G(orig_plain_ops)->stream_metadata(wrapper, url, options, value, context);
    }
    return 0;
}

static const php_stream_wrapper_ops intercept_plain_ops = {
    intercept_stream_opener,
    NULL,
    NULL,
    intercept_url_stat,
    intercept_dir_opener,
    "plainfile/branchfs",
    intercept_unlink,
    intercept_rename,
    intercept_mkdir,
    intercept_rmdir,
    intercept_metadata
};

/* ================================================================
 * Section 9: PHP userland functions
 * ================================================================ */

PHP_FUNCTION(branchfs_set_db) {
    char *path;
    size_t path_len;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STRING(path, path_len)
    ZEND_PARSE_PARAMETERS_END();

    if (BRANCHFS_G(db_path)) efree(BRANCHFS_G(db_path));
    BRANCHFS_G(db_path) = estrndup(path, path_len);

    store_close();
    if (store_open(BRANCHFS_G(db_path)) == 0) {
        RETURN_TRUE;
    }
    RETURN_FALSE;
}

PHP_FUNCTION(branchfs_set_root) {
    char *path;
    size_t path_len;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STRING(path, path_len)
    ZEND_PARSE_PARAMETERS_END();

    /* Remove trailing slash */
    while (path_len > 1 && path[path_len - 1] == '/') path_len--;

    if (BRANCHFS_G(wp_root)) efree(BRANCHFS_G(wp_root));
    BRANCHFS_G(wp_root) = estrndup(path, path_len);
    RETURN_TRUE;
}

PHP_FUNCTION(branchfs_set_branch) {
    char *branch;
    size_t branch_len;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STRING(branch, branch_len)
    ZEND_PARSE_PARAMETERS_END();

    if (BRANCHFS_G(current_branch)) efree(BRANCHFS_G(current_branch));
    BRANCHFS_G(current_branch) = estrndup(branch, branch_len);
    RETURN_TRUE;
}

PHP_FUNCTION(branchfs_get_branch) {
    ZEND_PARSE_PARAMETERS_NONE();
    if (BRANCHFS_G(current_branch)) {
        RETURN_STRING(BRANCHFS_G(current_branch));
    }
    RETURN_NULL();
}

PHP_FUNCTION(branchfs_activate) {
    ZEND_PARSE_PARAMETERS_NONE();
    if (!BRANCHFS_G(db) || !BRANCHFS_G(wp_root) || !BRANCHFS_G(current_branch)) {
        php_error_docref(NULL, E_WARNING,
            "branchfs: must set db, root, and branch before activating");
        RETURN_FALSE;
    }
    BRANCHFS_G(active) = 1;
    RETURN_TRUE;
}

PHP_FUNCTION(branchfs_deactivate) {
    ZEND_PARSE_PARAMETERS_NONE();
    BRANCHFS_G(active) = 0;
    RETURN_TRUE;
}

PHP_FUNCTION(branchfs_create_branch) {
    char *name, *parent = NULL;
    size_t name_len, parent_len = 0;
    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STRING(name, name_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_STRING_OR_NULL(parent, parent_len)
    ZEND_PARSE_PARAMETERS_END();

    int id = store_create_branch(name, parent);
    if (id > 0) {
        RETURN_LONG(id);
    }
    RETURN_FALSE;
}

PHP_FUNCTION(branchfs_import_file) {
    char *real_path, *branch, *virtual_path;
    size_t rp_len, br_len, vp_len;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_STRING(real_path, rp_len)
        Z_PARAM_STRING(branch, br_len)
        Z_PARAM_STRING(virtual_path, vp_len)
    ZEND_PARSE_PARAMETERS_END();

    int branch_id = store_get_branch_id(branch);
    if (branch_id < 0) RETURN_FALSE;

    /* Read the real file (bypass our interceptor) */
    BRANCHFS_G(intercepting) = 1;
    php_stream *stream = php_stream_open_wrapper(real_path, "rb",
        REPORT_ERRORS, NULL);
    BRANCHFS_G(intercepting) = 0;

    if (!stream) RETURN_FALSE;

    zend_string *contents = php_stream_copy_to_mem(stream, PHP_STREAM_COPY_ALL, 0);
    php_stream_close(stream);

    if (!contents) RETURN_FALSE;

    int rc = store_write_file(branch_id, virtual_path,
        ZSTR_VAL(contents), ZSTR_LEN(contents));
    zend_string_release(contents);
    RETURN_BOOL(rc == 0);
}

PHP_FUNCTION(branchfs_import_dir) {
    char *branch, *virtual_path;
    size_t br_len, vp_len;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STRING(branch, br_len)
        Z_PARAM_STRING(virtual_path, vp_len)
    ZEND_PARSE_PARAMETERS_END();

    int branch_id = store_get_branch_id(branch);
    if (branch_id < 0) RETURN_FALSE;

    RETURN_BOOL(store_mkdir(branch_id, virtual_path, 16877) == 0);
}

PHP_FUNCTION(branchfs_is_active) {
    ZEND_PARSE_PARAMETERS_NONE();
    RETURN_BOOL(BRANCHFS_G(active));
}

/* ================================================================
 * Section 9b: Override file_exists / is_readable / is_writable
 *
 * PHP's file_exists() uses access(2) syscall for the plain files wrapper,
 * bypassing the stream wrapper's url_stat entirely. We must override these
 * PHP functions to intercept calls for paths under the WP root.
 * ================================================================ */

static zif_handler original_file_exists_handler = NULL;
static zif_handler original_realpath_handler    = NULL;
static zif_handler original_is_file_handler       = NULL;
static zif_handler original_is_dir_handler        = NULL;
static zif_handler original_is_readable_handler   = NULL;
static zif_handler original_is_writable_handler   = NULL;
static zif_handler original_is_executable_handler = NULL;
static zif_handler original_is_link_handler       = NULL;
static zif_handler original_chmod_handler         = NULL;
static zif_handler original_chown_handler         = NULL;
static zif_handler original_chgrp_handler         = NULL;
static zif_handler original_touch_handler         = NULL;
static zif_handler original_lstat_handler         = NULL;
static zif_handler original_link_handler          = NULL;
static zif_handler original_symlink_handler       = NULL;
static zif_handler original_readlink_handler      = NULL;
static zif_handler original_linkinfo_handler      = NULL;

ZEND_NAMED_FUNCTION(branchfs_override_file_exists) {
    zend_string *filename;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_PATH_STR(filename)
    ZEND_PARSE_PARAMETERS_END();

    if (BRANCHFS_G(active) && BRANCHFS_G(current_branch) && !BRANCHFS_G(intercepting)) {
        const char *path = ZSTR_VAL(filename);
        /* Skip protocol URLs - let original handler deal with them */
        if ((strstr(path, "branchfs://") == path || strstr(path, "://") == NULL)) {
            char rel_path[BRANCHFS_MAX_PATH];
            if (resolve_to_wp_relative(path, rel_path, sizeof(rel_path))) {
                int branch_id = get_current_branch_id();
                if (branch_id > 0) {
                    RETURN_BOOL(store_file_exists(branch_id, rel_path));
                }
            }
        }
    }
    original_file_exists_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

/* Canonicalize a path by collapsing ./ and ../ segments. Does not touch the
 * filesystem. Returns 0 on overflow, 1 on success. */
static int branchfs_canonicalize(const char *in, char *out, size_t out_len) {
    if (!in || in[0] != '/') return 0;
    const char *segs[BRANCHFS_MAX_PATH / 2];
    size_t seg_lens[BRANCHFS_MAX_PATH / 2];
    int nseg = 0;

    const char *p = in + 1;
    while (*p) {
        const char *q = p;
        while (*q && *q != '/') q++;
        size_t slen = q - p;
        if (slen == 0 || (slen == 1 && p[0] == '.')) {
            /* skip empty or "." */
        } else if (slen == 2 && p[0] == '.' && p[1] == '.') {
            if (nseg > 0) nseg--;
        } else {
            if (nseg >= (int)(sizeof(segs) / sizeof(segs[0]))) return 0;
            segs[nseg] = p;
            seg_lens[nseg] = slen;
            nseg++;
        }
        p = q;
        if (*p) p++;
    }

    size_t pos = 0;
    if (nseg == 0) {
        if (out_len < 2) return 0;
        out[pos++] = '/';
        out[pos] = '\0';
        return 1;
    }
    for (int i = 0; i < nseg; i++) {
        if (pos + 1 + seg_lens[i] >= out_len) return 0;
        out[pos++] = '/';
        memcpy(out + pos, segs[i], seg_lens[i]);
        pos += seg_lens[i];
    }
    out[pos] = '\0';
    return 1;
}

ZEND_NAMED_FUNCTION(branchfs_override_realpath) {
    zend_string *filename;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_PATH_STR(filename)
    ZEND_PARSE_PARAMETERS_END();

    if (BRANCHFS_G(active) && BRANCHFS_G(current_branch) && !BRANCHFS_G(intercepting)) {
        const char *path = ZSTR_VAL(filename);
        if ((strstr(path, "branchfs://") == path || strstr(path, "://") == NULL)) {
            char rel_path[BRANCHFS_MAX_PATH];
            if (resolve_to_wp_relative(path, rel_path, sizeof(rel_path))) {
                int branch_id = get_current_branch_id();
                if (branch_id > 0 && store_file_exists(branch_id, rel_path)) {
                    /* Mirror the input scheme: branchfs:// in -> branchfs:// out;
                     * plain path in -> wp_root absolute path. Mixing the two
                     * breaks string concat in callers like WP's
                     * get_block_patterns. */
                    char prefix[BRANCHFS_MAX_PATH];
                    if (strncmp(path, "branchfs://", 11) == 0) {
                        const char *after = path + 11;
                        const char *slash = strchr(after, '/');
                        size_t blen = slash ? (size_t)(slash - after) : strlen(after);
                        snprintf(prefix, sizeof(prefix), "branchfs://%.*s",
                            (int)blen, after);
                    } else {
                        snprintf(prefix, sizeof(prefix), "%s", BRANCHFS_G(wp_root));
                    }
                    char canon[BRANCHFS_MAX_PATH];
                    if (rel_path[0] == '\0') {
                        snprintf(canon, sizeof(canon), "%s", prefix);
                    } else {
                        snprintf(canon, sizeof(canon), "%s/%s", prefix, rel_path);
                    }
                    RETURN_STRING(canon);
                }
            }
        }
    }
    original_realpath_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

/* Shared body for is_file / is_dir / is_readable overrides. */
static void branchfs_stat_check(INTERNAL_FUNCTION_PARAMETERS, int want_dir, zif_handler fallback) {
    zend_string *filename;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_PATH_STR(filename)
    ZEND_PARSE_PARAMETERS_END();

    if (BRANCHFS_G(active) && BRANCHFS_G(current_branch) && !BRANCHFS_G(intercepting)) {
        const char *path = ZSTR_VAL(filename);
        if ((strstr(path, "branchfs://") == path || strstr(path, "://") == NULL)) {
            char rel_path[BRANCHFS_MAX_PATH];
            if (resolve_to_wp_relative(path, rel_path, sizeof(rel_path))) {
                int branch_id = get_current_branch_id();
                if (branch_id > 0) {
                    int is_dir = 0;
                    size_t sz = 0;
                    int mode = 0;
                    time_t mtime = 0;
                    int r = store_stat_file(branch_id, rel_path, &is_dir, &sz, &mode, &mtime);
                    if (r != 0) {
                        RETURN_FALSE;
                    }
                    if (want_dir == 0) {
                        RETURN_BOOL(!is_dir);       /* is_file */
                    } else if (want_dir == 1) {
                        RETURN_BOOL(is_dir);        /* is_dir */
                    } else {
                        RETURN_TRUE;                /* is_readable */
                    }
                }
            }
        }
    }
    fallback(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_NAMED_FUNCTION(branchfs_override_is_file) {
    branchfs_stat_check(INTERNAL_FUNCTION_PARAM_PASSTHRU, 0, original_is_file_handler);
}
ZEND_NAMED_FUNCTION(branchfs_override_is_dir) {
    branchfs_stat_check(INTERNAL_FUNCTION_PARAM_PASSTHRU, 1, original_is_dir_handler);
}
ZEND_NAMED_FUNCTION(branchfs_override_is_readable) {
    branchfs_stat_check(INTERNAL_FUNCTION_PARAM_PASSTHRU, 2, original_is_readable_handler);
}
/* is_writable: our store is always writable, so if the file (or its parent
 * dir for nonexistent files) is branchfs-backed we return true. */
ZEND_NAMED_FUNCTION(branchfs_override_is_writable) {
    zend_string *filename;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_PATH_STR(filename)
    ZEND_PARSE_PARAMETERS_END();

    if (BRANCHFS_G(active) && BRANCHFS_G(current_branch) && !BRANCHFS_G(intercepting)) {
        const char *path = ZSTR_VAL(filename);
        if ((strstr(path, "branchfs://") == path || strstr(path, "://") == NULL)) {
            char rel_path[BRANCHFS_MAX_PATH];
            if (resolve_to_wp_relative(path, rel_path, sizeof(rel_path))) {
                int branch_id = get_current_branch_id();
                if (branch_id > 0) {
                    /* Existing file or dir in the store -> writable. */
                    if (store_file_exists(branch_id, rel_path)) RETURN_TRUE;
                    /* Nonexistent path under wp_root: writable if a parent
                     * directory (or root) is resolvable. WP uses this to
                     * check if uploads/ is writable before trying to create
                     * subdirectories. */
                    RETURN_TRUE;
                }
            }
        }
    }
    original_is_writable_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}
/* is_executable: return true for directories (so WP considers plugin/theme
 * dirs traversable); false for files (branchfs doesn't execute). */
ZEND_NAMED_FUNCTION(branchfs_override_is_executable) {
    zend_string *filename;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_PATH_STR(filename)
    ZEND_PARSE_PARAMETERS_END();

    if (BRANCHFS_G(active) && BRANCHFS_G(current_branch) && !BRANCHFS_G(intercepting)) {
        const char *path = ZSTR_VAL(filename);
        if ((strstr(path, "branchfs://") == path || strstr(path, "://") == NULL)) {
            char rel_path[BRANCHFS_MAX_PATH];
            if (resolve_to_wp_relative(path, rel_path, sizeof(rel_path))) {
                int branch_id = get_current_branch_id();
                if (branch_id > 0) {
                    int is_dir = 0;
                    if (store_stat_file(branch_id, rel_path, &is_dir, NULL, NULL, NULL) != 0) {
                        RETURN_FALSE;
                    }
                    RETURN_BOOL(is_dir);
                }
            }
        }
    }
    original_is_executable_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}
/* is_link: branchfs doesn't model symlinks, always false for our paths. */
ZEND_NAMED_FUNCTION(branchfs_override_is_link) {
    zend_string *filename;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_PATH_STR(filename)
    ZEND_PARSE_PARAMETERS_END();

    if (BRANCHFS_G(active) && BRANCHFS_G(current_branch) && !BRANCHFS_G(intercepting)) {
        const char *path = ZSTR_VAL(filename);
        if ((strstr(path, "branchfs://") == path || strstr(path, "://") == NULL)) {
            char rel_path[BRANCHFS_MAX_PATH];
            if (resolve_to_wp_relative(path, rel_path, sizeof(rel_path))) {
                int branch_id = get_current_branch_id();
                if (branch_id > 0) {
                    RETURN_FALSE;
                }
            }
        }
    }
    original_is_link_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

/* chmod/chown/chgrp/touch: these bypass stream wrappers via VCWD_* macros.
 * For branchfs-backed paths we pretend success (branchfs doesn't model perms
 * or ownership at the OS level, so these are semantically no-ops).
 * `touch` with a nonexistent file creates it, which we honor. */
static void branchfs_noop_perm(INTERNAL_FUNCTION_PARAMETERS, zif_handler fallback) {
    zend_string *filename;
    zval *extra1 = NULL, *extra2 = NULL;
    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_PATH_STR(filename)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL(extra1)
        Z_PARAM_ZVAL(extra2)
    ZEND_PARSE_PARAMETERS_END();
    (void)extra1; (void)extra2;

    if (BRANCHFS_G(active) && BRANCHFS_G(current_branch) && !BRANCHFS_G(intercepting)) {
        const char *path = ZSTR_VAL(filename);
        if ((strstr(path, "branchfs://") == path || strstr(path, "://") == NULL)) {
            char rel_path[BRANCHFS_MAX_PATH];
            if (resolve_to_wp_relative(path, rel_path, sizeof(rel_path))) {
                int branch_id = get_current_branch_id();
                if (branch_id > 0) {
                    RETURN_TRUE;
                }
            }
        }
    }
    fallback(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_NAMED_FUNCTION(branchfs_override_chmod) {
    branchfs_noop_perm(INTERNAL_FUNCTION_PARAM_PASSTHRU, original_chmod_handler);
}
ZEND_NAMED_FUNCTION(branchfs_override_chown) {
    branchfs_noop_perm(INTERNAL_FUNCTION_PARAM_PASSTHRU, original_chown_handler);
}
ZEND_NAMED_FUNCTION(branchfs_override_chgrp) {
    branchfs_noop_perm(INTERNAL_FUNCTION_PARAM_PASSTHRU, original_chgrp_handler);
}

/* touch: if file exists in store, return true. If it doesn't, create an empty
 * file (matches touch(1) semantics). */
ZEND_NAMED_FUNCTION(branchfs_override_touch) {
    zend_string *filename;
    zend_long mtime = 0, atime = 0;
    bool mtime_is_null = 1, atime_is_null = 1;
    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_PATH_STR(filename)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG_OR_NULL(mtime, mtime_is_null)
        Z_PARAM_LONG_OR_NULL(atime, atime_is_null)
    ZEND_PARSE_PARAMETERS_END();
    (void)mtime; (void)atime; (void)mtime_is_null; (void)atime_is_null;

    if (BRANCHFS_G(active) && BRANCHFS_G(current_branch) && !BRANCHFS_G(intercepting)) {
        const char *path = ZSTR_VAL(filename);
        if ((strstr(path, "branchfs://") == path || strstr(path, "://") == NULL)) {
            char rel_path[BRANCHFS_MAX_PATH];
            if (resolve_to_wp_relative(path, rel_path, sizeof(rel_path))) {
                int branch_id = get_current_branch_id();
                if (branch_id > 0) {
                    if (store_file_exists(branch_id, rel_path)) RETURN_TRUE;
                    /* Create empty file. */
                    if (store_write_file(branch_id, rel_path, "", 0) == 0) RETURN_TRUE;
                    RETURN_FALSE;
                }
            }
        }
    }
    original_touch_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

/* lstat: branchfs paths have no symlinks, so return the same as stat.
 * php_stream_stat_path_ex honors our url_stat, but lstat() in core
 * uses VCWD_LSTAT directly, hence the override. */
ZEND_NAMED_FUNCTION(branchfs_override_lstat) {
    zend_string *filename;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_PATH_STR(filename)
    ZEND_PARSE_PARAMETERS_END();

    if (BRANCHFS_G(active) && BRANCHFS_G(current_branch) && !BRANCHFS_G(intercepting)) {
        const char *path = ZSTR_VAL(filename);
        if ((strstr(path, "branchfs://") == path || strstr(path, "://") == NULL)) {
            char rel_path[BRANCHFS_MAX_PATH];
            if (resolve_to_wp_relative(path, rel_path, sizeof(rel_path))) {
                int branch_id = get_current_branch_id();
                if (branch_id > 0) {
                    int is_dir = 0;
                    size_t sz = 0;
                    int mode = 0;
                    time_t mtime = 0;
                    if (store_stat_file(branch_id, rel_path, &is_dir, &sz, &mode, &mtime) != 0) {
                        RETURN_FALSE;
                    }
                    /* Return the PHP stat array (numeric + named keys). */
                    array_init(return_value);
                    zend_long m = is_dir ? (S_IFDIR | 0755) : (S_IFREG | (mode ? mode : 0644));
                    zend_long size = (zend_long) sz;
                    zend_long mtl = (zend_long) mtime;
                    const char *keys[] = {"dev","ino","mode","nlink","uid","gid",
                                          "rdev","size","atime","mtime","ctime","blksize","blocks"};
                    zend_long vals[] = {0, 0, m, 1, 0, 0, 0, size, mtl, mtl, mtl, 4096, 0};
                    for (int i = 0; i < 13; i++) {
                        add_index_long(return_value, i, vals[i]);
                    }
                    for (int i = 0; i < 13; i++) {
                        add_assoc_long(return_value, keys[i], vals[i]);
                    }
                    return;
                }
            }
        }
    }
    original_lstat_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

/* link/symlink/readlink/linkinfo: branchfs doesn't model links. For
 * paths that resolve under wp_root we short-circuit to the "no link"
 * reply (false/0); everything else falls through to the OS handler so
 * operations outside the overlay keep working. */
ZEND_NAMED_FUNCTION(branchfs_override_link) {
    zend_string *target;
    zend_string *linkname;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_PATH_STR(target)
        Z_PARAM_PATH_STR(linkname)
    ZEND_PARSE_PARAMETERS_END();

    if (BRANCHFS_G(active) && BRANCHFS_G(current_branch) && !BRANCHFS_G(intercepting)) {
        const char *t = ZSTR_VAL(target);
        const char *l = ZSTR_VAL(linkname);
        if ((strstr(t, "branchfs://") == t || strstr(t, "://") == NULL) && (strstr(l, "branchfs://") == l || strstr(l, "://") == NULL)) {
            char rel[BRANCHFS_MAX_PATH];
            if (resolve_to_wp_relative(t, rel, sizeof(rel)) ||
                resolve_to_wp_relative(l, rel, sizeof(rel))) {
                RETURN_FALSE;
            }
        }
    }
    original_link_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_NAMED_FUNCTION(branchfs_override_symlink) {
    zend_string *target;
    zend_string *linkname;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_PATH_STR(target)
        Z_PARAM_PATH_STR(linkname)
    ZEND_PARSE_PARAMETERS_END();

    if (BRANCHFS_G(active) && BRANCHFS_G(current_branch) && !BRANCHFS_G(intercepting)) {
        const char *t = ZSTR_VAL(target);
        const char *l = ZSTR_VAL(linkname);
        if ((strstr(t, "branchfs://") == t || strstr(t, "://") == NULL) && (strstr(l, "branchfs://") == l || strstr(l, "://") == NULL)) {
            char rel[BRANCHFS_MAX_PATH];
            if (resolve_to_wp_relative(t, rel, sizeof(rel)) ||
                resolve_to_wp_relative(l, rel, sizeof(rel))) {
                RETURN_FALSE;
            }
        }
    }
    original_symlink_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_NAMED_FUNCTION(branchfs_override_readlink) {
    zend_string *path;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_PATH_STR(path)
    ZEND_PARSE_PARAMETERS_END();

    if (BRANCHFS_G(active) && BRANCHFS_G(current_branch) && !BRANCHFS_G(intercepting)) {
        const char *p = ZSTR_VAL(path);
        if ((strstr(p, "branchfs://") == p || strstr(p, "://") == NULL)) {
            char rel[BRANCHFS_MAX_PATH];
            if (resolve_to_wp_relative(p, rel, sizeof(rel))) {
                RETURN_FALSE;
            }
        }
    }
    original_readlink_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

ZEND_NAMED_FUNCTION(branchfs_override_linkinfo) {
    zend_string *path;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_PATH_STR(path)
    ZEND_PARSE_PARAMETERS_END();

    if (BRANCHFS_G(active) && BRANCHFS_G(current_branch) && !BRANCHFS_G(intercepting)) {
        const char *p = ZSTR_VAL(path);
        if ((strstr(p, "branchfs://") == p || strstr(p, "://") == NULL)) {
            char rel[BRANCHFS_MAX_PATH];
            if (resolve_to_wp_relative(p, rel, sizeof(rel))) {
                RETURN_LONG(0);
            }
        }
    }
    original_linkinfo_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

/* glob() override: see doc in branchfs.h.
 *
 * Supports wildcards at ANY path depth, matching PHP glob semantics where a
 * double-star pattern behaves the same as a single-star (one path segment).
 * Pattern must begin with a literal prefix rooted under wp_root; wildcards
 * in the anchoring prefix are unsupported (glob(3) doesn't support that in
 * practice either).
 */

static zif_handler original_glob_handler = NULL;

/* Recursive helper: rel_dir is the current directory (relative to wp_root),
 * segs[seg_idx..seg_count-1] are the remaining pattern segments. Appends
 * matching canonical absolute paths to return_value. */
/* If the original glob pattern was "branchfs://<branch>/...", emit results
 * with the same branchfs:// prefix so callers that string-concat against
 * the pattern's own prefix (like WP's get_block_patterns + str_replace at
 * class-wp-theme.php:1954) work correctly. The result_prefix is set by the
 * caller. */
static void branchfs_glob_walk(zval *return_value, int branch_id,
    const char *rel_dir, char **segs, int seg_count, int seg_idx, int depth,
    const char *result_prefix)
{
    if (depth > 64) return; /* paranoia */
    if (seg_idx >= seg_count) return;

    const char *seg = segs[seg_idx];
    int is_last = (seg_idx == seg_count - 1);
    int has_wild = (strpbrk(seg, "*?[") != NULL);

    if (!has_wild) {
        /* Literal segment: descend without enumeration. */
        char next_rel[BRANCHFS_MAX_PATH];
        if (rel_dir[0] == '\0') {
            snprintf(next_rel, sizeof(next_rel), "%s", seg);
        } else {
            snprintf(next_rel, sizeof(next_rel), "%s/%s", rel_dir, seg);
        }
        int is_dir = 0;
        int r = store_stat_file(branch_id, next_rel, &is_dir, NULL, NULL, NULL);
        if (r != 0) return;
        if (is_last) {
            /* Final literal segment: if it exists, include it. */
            char abs[BRANCHFS_MAX_PATH];
            snprintf(abs, sizeof(abs), "%s/%s", result_prefix, next_rel);
            add_next_index_string(return_value, abs);
        } else {
            if (is_dir) {
                branchfs_glob_walk(return_value, branch_id, next_rel, segs, seg_count, seg_idx + 1, depth + 1, result_prefix);
            }
        }
        return;
    }

    /* Wildcard segment: enumerate current dir and fnmatch each entry. */
    char **entries = NULL;
    int count = 0;
    if (store_list_dir(branch_id, rel_dir, &entries, &count) != 0) return;
    for (int i = 0; i < count; i++) {
        const char *name = entries[i];
        /* glob(3) skips names starting with '.' unless the pattern explicitly starts with '.' */
        if (name[0] == '.' && seg[0] != '.') continue;
        if (fnmatch(seg, name, 0) != 0) continue;

        char child_rel[BRANCHFS_MAX_PATH];
        if (rel_dir[0] == '\0') {
            snprintf(child_rel, sizeof(child_rel), "%s", name);
        } else {
            snprintf(child_rel, sizeof(child_rel), "%s/%s", rel_dir, name);
        }
        int is_dir = 0;
        if (store_stat_file(branch_id, child_rel, &is_dir, NULL, NULL, NULL) != 0) continue;

        if (is_last) {
            char abs[BRANCHFS_MAX_PATH];
            snprintf(abs, sizeof(abs), "%s/%s", result_prefix, child_rel);
            add_next_index_string(return_value, abs);
        } else if (is_dir) {
            branchfs_glob_walk(return_value, branch_id, child_rel, segs, seg_count, seg_idx + 1, depth + 1, result_prefix);
        }
    }
    for (int i = 0; i < count; i++) efree(entries[i]);
    if (entries) efree(entries);
}

ZEND_NAMED_FUNCTION(branchfs_override_glob) {
    zend_string *pat_z;
    zend_long flags = 0;
    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_PATH_STR(pat_z)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(flags)
    ZEND_PARSE_PARAMETERS_END();
    (void)flags;

    if (!BRANCHFS_G(active) || !BRANCHFS_G(current_branch) || BRANCHFS_G(intercepting)) {
        original_glob_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    const char *pat = ZSTR_VAL(pat_z);
    /* Allow plain absolute/relative paths AND branchfs:// URLs through
     * (branchfs URLs show up after the OPcache fix put them in ABSPATH).
     * Other schemes (phar://, http://, etc.) defer to the OS handler. */
    if (strstr(pat, "://") != NULL && strncmp(pat, "branchfs://", 11) != 0) {
        original_glob_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    /* Find the literal prefix — the longest slash-prefix with no wildcards. */
    const char *first_wild = strpbrk(pat, "*?[");
    if (!first_wild) {
        original_glob_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }
    /* Back up to the last slash before the first wildcard. */
    const char *prefix_end = first_wild;
    while (prefix_end > pat && prefix_end[-1] != '/') prefix_end--;
    if (prefix_end == pat) {
        /* Pattern starts with a wildcard — unsupported, defer. */
        original_glob_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    size_t prefix_len = prefix_end - pat - 1; /* exclude trailing slash */
    char prefix[BRANCHFS_MAX_PATH];
    if (prefix_len >= sizeof(prefix)) {
        original_glob_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }
    memcpy(prefix, pat, prefix_len);
    prefix[prefix_len] = '\0';

    char rel_dir[BRANCHFS_MAX_PATH];
    if (!resolve_to_wp_relative(prefix, rel_dir, sizeof(rel_dir))) {
        original_glob_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    int branch_id = get_current_branch_id();
    if (branch_id <= 0) {
        original_glob_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    int prefix_is_dir = 0;
    if (store_stat_file(branch_id, rel_dir, &prefix_is_dir, NULL, NULL, NULL) != 0 || !prefix_is_dir) {
        original_glob_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    /* Split the remaining tail on '/'. */
    char tail[BRANCHFS_MAX_PATH];
    snprintf(tail, sizeof(tail), "%s", prefix_end);
    char *segs[64];
    int seg_count = 0;
    char *tok = tail;
    while (tok && *tok && seg_count < 64) {
        segs[seg_count++] = tok;
        char *slash = strchr(tok, '/');
        if (!slash) break;
        *slash = '\0';
        tok = slash + 1;
        /* Skip empty segs from double-slashes. */
        while (*tok == '/') tok++;
    }

    /* Pick the result prefix: if the caller used a branchfs:// pattern,
     * mirror it. Otherwise emit canonical wp_root absolute paths. This
     * matches what callers like WP's get_block_patterns expect: they
     * str_replace($dirpath, '', $file) to extract the basename, and the
     * replacement only works if $file starts with the same prefix as
     * $dirpath. */
    char result_prefix[BRANCHFS_MAX_PATH];
    if (strncmp(pat, "branchfs://", 11) == 0) {
        const char *after = pat + 11;
        const char *slash = strchr(after, '/');
        size_t branch_len = slash ? (size_t)(slash - after) : strlen(after);
        snprintf(result_prefix, sizeof(result_prefix), "branchfs://%.*s",
            (int)branch_len, after);
    } else {
        snprintf(result_prefix, sizeof(result_prefix), "%s", BRANCHFS_G(wp_root));
    }

    array_init(return_value);
    if (seg_count > 0) {
        branchfs_glob_walk(return_value, branch_id, rel_dir, segs, seg_count, 0, 0, result_prefix);
    }

    /* glob() sorts lexicographically by default (no GLOB_NOSORT). To avoid
     * depending on PHP's internal sort comparator, pull the values out,
     * qsort them, and rebuild the array. */
    HashTable *ht = Z_ARRVAL_P(return_value);
    int ncount = zend_hash_num_elements(ht);
    if (ncount > 1) {
        char **buf = emalloc(sizeof(char*) * ncount);
        int bi = 0;
        zval *zv;
        ZEND_HASH_FOREACH_VAL(ht, zv) {
            buf[bi++] = estrdup(Z_STRVAL_P(zv));
        } ZEND_HASH_FOREACH_END();

        /* qsort(3) with strcmp */
        for (int i = 1; i < ncount; i++) {
            for (int j = i; j > 0 && strcmp(buf[j-1], buf[j]) > 0; j--) {
                char *t = buf[j]; buf[j] = buf[j-1]; buf[j-1] = t;
            }
        }
        zend_hash_clean(ht);
        for (int i = 0; i < ncount; i++) {
            add_next_index_string(return_value, buf[i]);
            efree(buf[i]);
        }
        efree(buf);
    }
}

/* ================================================================
 * Section 10: Module lifecycle
 * ================================================================ */

ZEND_BEGIN_ARG_INFO_EX(arginfo_branchfs_set_db, 0, 0, 1)
    ZEND_ARG_INFO(0, path)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_branchfs_set_root, 0, 0, 1)
    ZEND_ARG_INFO(0, path)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_branchfs_set_branch, 0, 0, 1)
    ZEND_ARG_INFO(0, branch)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_branchfs_get_branch, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_branchfs_activate, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_branchfs_deactivate, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_branchfs_create_branch, 0, 0, 1)
    ZEND_ARG_INFO(0, name)
    ZEND_ARG_INFO(0, parent)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_branchfs_import_file, 0, 0, 3)
    ZEND_ARG_INFO(0, real_path)
    ZEND_ARG_INFO(0, branch)
    ZEND_ARG_INFO(0, virtual_path)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_branchfs_import_dir, 0, 0, 2)
    ZEND_ARG_INFO(0, branch)
    ZEND_ARG_INFO(0, virtual_path)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_branchfs_is_active, 0, 0, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry branchfs_functions[] = {
    PHP_FE(branchfs_set_db,        arginfo_branchfs_set_db)
    PHP_FE(branchfs_set_root,      arginfo_branchfs_set_root)
    PHP_FE(branchfs_set_branch,    arginfo_branchfs_set_branch)
    PHP_FE(branchfs_get_branch,    arginfo_branchfs_get_branch)
    PHP_FE(branchfs_activate,      arginfo_branchfs_activate)
    PHP_FE(branchfs_deactivate,    arginfo_branchfs_deactivate)
    PHP_FE(branchfs_create_branch, arginfo_branchfs_create_branch)
    PHP_FE(branchfs_import_file,   arginfo_branchfs_import_file)
    PHP_FE(branchfs_import_dir,    arginfo_branchfs_import_dir)
    PHP_FE(branchfs_is_active,     arginfo_branchfs_is_active)
    PHP_FE_END
};

static void php_branchfs_globals_ctor(zend_branchfs_globals *g) {
    memset(g, 0, sizeof(*g));
}

static void php_branchfs_globals_dtor(zend_branchfs_globals *g) {
    (void)g;
}

PHP_MINIT_FUNCTION(branchfs) {
    ZEND_INIT_MODULE_GLOBALS(branchfs, php_branchfs_globals_ctor, php_branchfs_globals_dtor);

    /* Register branchfs:// stream wrapper */
    if (php_register_url_stream_wrapper(BRANCHFS_PROTO, &branchfs_wrapper) == FAILURE) {
        return FAILURE;
    }

    /* Intercept plain files wrapper */
    BRANCHFS_G(orig_plain_ops) = php_plain_files_wrapper.wops;
    php_plain_files_wrapper.wops = &intercept_plain_ops;

    /* Override file_exists: PHP uses access(2) for plain wrapper, bypassing url_stat */
    zend_function *fe_func = zend_hash_str_find_ptr(CG(function_table),
        "file_exists", sizeof("file_exists") - 1);
    if (fe_func && fe_func->type == ZEND_INTERNAL_FUNCTION) {
        original_file_exists_handler = fe_func->internal_function.handler;
        fe_func->internal_function.handler = branchfs_override_file_exists;
    }

    /* Override realpath: goes straight to realpath(3), bypasses wrappers. */
    zend_function *rp_func = zend_hash_str_find_ptr(CG(function_table),
        "realpath", sizeof("realpath") - 1);
    if (rp_func && rp_func->type == ZEND_INTERNAL_FUNCTION) {
        original_realpath_handler = rp_func->internal_function.handler;
        rp_func->internal_function.handler = branchfs_override_realpath;
    }

    /* Override is_file / is_dir / is_readable — stat-family calls that can
     * bypass the url_stat path for plain filenames. */
    zend_function *if_func = zend_hash_str_find_ptr(CG(function_table),
        "is_file", sizeof("is_file") - 1);
    if (if_func && if_func->type == ZEND_INTERNAL_FUNCTION) {
        original_is_file_handler = if_func->internal_function.handler;
        if_func->internal_function.handler = branchfs_override_is_file;
    }
    zend_function *id_func = zend_hash_str_find_ptr(CG(function_table),
        "is_dir", sizeof("is_dir") - 1);
    if (id_func && id_func->type == ZEND_INTERNAL_FUNCTION) {
        original_is_dir_handler = id_func->internal_function.handler;
        id_func->internal_function.handler = branchfs_override_is_dir;
    }
    zend_function *ir_func = zend_hash_str_find_ptr(CG(function_table),
        "is_readable", sizeof("is_readable") - 1);
    if (ir_func && ir_func->type == ZEND_INTERNAL_FUNCTION) {
        original_is_readable_handler = ir_func->internal_function.handler;
        ir_func->internal_function.handler = branchfs_override_is_readable;
    }

    /* Override glob: uses glob(3) syscall, bypasses all stream wrappers.
     * Crucial for WordPress theme pattern registration. */
    zend_function *gl_func = zend_hash_str_find_ptr(CG(function_table),
        "glob", sizeof("glob") - 1);
    if (gl_func && gl_func->type == ZEND_INTERNAL_FUNCTION) {
        original_glob_handler = gl_func->internal_function.handler;
        gl_func->internal_function.handler = branchfs_override_glob;
    }

    /* Remaining syscall-direct functions. All of these hit VCWD_* macros
     * internally, which call the corresponding libc syscall and skip our
     * stream wrapper. We register overrides once at module startup so WP
     * never trips over them. */
    struct { const char *name; size_t name_len; zif_handler new_h; zif_handler *orig_slot; } overrides[] = {
        {"is_writable",   sizeof("is_writable") - 1,   branchfs_override_is_writable,   &original_is_writable_handler},
        {"is_writeable",  sizeof("is_writeable") - 1,  branchfs_override_is_writable,   &original_is_writable_handler},
        {"is_executable", sizeof("is_executable") - 1, branchfs_override_is_executable, &original_is_executable_handler},
        {"is_link",       sizeof("is_link") - 1,       branchfs_override_is_link,       &original_is_link_handler},
        {"chmod",         sizeof("chmod") - 1,         branchfs_override_chmod,         &original_chmod_handler},
        {"chown",         sizeof("chown") - 1,         branchfs_override_chown,         &original_chown_handler},
        {"chgrp",         sizeof("chgrp") - 1,         branchfs_override_chgrp,         &original_chgrp_handler},
        {"touch",         sizeof("touch") - 1,         branchfs_override_touch,         &original_touch_handler},
        {"lstat",         sizeof("lstat") - 1,         branchfs_override_lstat,         &original_lstat_handler},
        {"link",          sizeof("link") - 1,          branchfs_override_link,          &original_link_handler},
        {"symlink",       sizeof("symlink") - 1,       branchfs_override_symlink,       &original_symlink_handler},
        {"readlink",      sizeof("readlink") - 1,      branchfs_override_readlink,      &original_readlink_handler},
        {"linkinfo",      sizeof("linkinfo") - 1,      branchfs_override_linkinfo,      &original_linkinfo_handler},
    };
    for (size_t i = 0; i < sizeof(overrides) / sizeof(overrides[0]); i++) {
        zend_function *f = zend_hash_str_find_ptr(CG(function_table),
            overrides[i].name, overrides[i].name_len);
        if (f && f->type == ZEND_INTERNAL_FUNCTION) {
            *overrides[i].orig_slot = f->internal_function.handler;
            f->internal_function.handler = overrides[i].new_h;
        }
    }

    return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(branchfs) {
    /* Restore original plain files wrapper */
    if (BRANCHFS_G(orig_plain_ops)) {
        php_plain_files_wrapper.wops = BRANCHFS_G(orig_plain_ops);
        BRANCHFS_G(orig_plain_ops) = NULL;
    }

    php_unregister_url_stream_wrapper(BRANCHFS_PROTO);
    return SUCCESS;
}

PHP_RINIT_FUNCTION(branchfs) {
    BRANCHFS_G(active) = 0;
    BRANCHFS_G(intercepting) = 0;
    return SUCCESS;
}

PHP_RSHUTDOWN_FUNCTION(branchfs) {
    store_close();
    if (BRANCHFS_G(db_path)) { efree(BRANCHFS_G(db_path)); BRANCHFS_G(db_path) = NULL; }
    if (BRANCHFS_G(wp_root)) { efree(BRANCHFS_G(wp_root)); BRANCHFS_G(wp_root) = NULL; }
    if (BRANCHFS_G(current_branch)) { efree(BRANCHFS_G(current_branch)); BRANCHFS_G(current_branch) = NULL; }
    BRANCHFS_G(active) = 0;
    return SUCCESS;
}

PHP_MINFO_FUNCTION(branchfs) {
    php_info_print_table_start();
    php_info_print_table_header(2, "branchfs support", "enabled");
    php_info_print_table_row(2, "Version", PHP_BRANCHFS_VERSION);
    php_info_print_table_row(2, "Branch", BRANCHFS_G(current_branch) ? BRANCHFS_G(current_branch) : "(none)");
    php_info_print_table_row(2, "WP Root", BRANCHFS_G(wp_root) ? BRANCHFS_G(wp_root) : "(none)");
    php_info_print_table_row(2, "Active", BRANCHFS_G(active) ? "yes" : "no");
    php_info_print_table_end();
}

zend_module_entry branchfs_module_entry = {
    STANDARD_MODULE_HEADER,
    "branchfs",
    branchfs_functions,
    PHP_MINIT(branchfs),
    PHP_MSHUTDOWN(branchfs),
    PHP_RINIT(branchfs),
    PHP_RSHUTDOWN(branchfs),
    PHP_MINFO(branchfs),
    PHP_BRANCHFS_VERSION,
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_BRANCHFS
ZEND_GET_MODULE(branchfs)
#endif

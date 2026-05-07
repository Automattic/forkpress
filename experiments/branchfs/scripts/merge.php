<?php
/**
 * BranchFS Merge — file-side 3-way merge (SQLite overlay only).
 *
 * File-side does a true 3-way merge at the path level:
 *
 *   base (common ancestor) vs source vs target
 *
 *   base==target, source!=target   -> apply source (fast-forward on this path)
 *   base==source, target!=source   -> keep target (target already diverged)
 *   source==target                 -> no-op
 *   all three differ               -> conflict; honor --strategy
 *
 * --strategy=abort  (default) -- refuse merge if any conflict, exit 1
 * --strategy=ours             -- target wins on conflict
 * --strategy=theirs           -- source wins on conflict
 *
 * Phase 1 (file 3-way merge) and Phase 2 (DB 3-way merge) both run inside
 * this script.
 *
 * Usage: php merge.php {source-branch} {target-branch} [db-path] [--strategy=...]
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php merge.php {source-branch} {target-branch} [db-path] [--strategy=abort|ours|theirs] [--on-id-collision=conflict|renumber]\n");
    exit(1);
}

$source  = $argv[1];
$target  = $argv[2];
$db_path = $argv[3] ?? __DIR__ . '/../branchfs.db';
$strategy = 'abort';
$on_id_collision = 'conflict';

for ($i = 3; $i < $argc; $i++) {
    $a = $argv[$i];
    if (strpos($a, '--strategy=') === 0) {
        $strategy = substr($a, strlen('--strategy='));
    } elseif ($a === '--strategy' && isset($argv[$i + 1])) {
        $strategy = $argv[++$i];
    } elseif (strpos($a, '--on-id-collision=') === 0) {
        $on_id_collision = substr($a, strlen('--on-id-collision='));
    } elseif ($a === '--on-id-collision' && isset($argv[$i + 1])) {
        $on_id_collision = $argv[++$i];
    }
}

if (!in_array($strategy, ['abort', 'ours', 'theirs'], true)) {
    fwrite(STDERR, "merge: invalid --strategy '$strategy' (must be abort|ours|theirs)\n");
    exit(1);
}
if (!in_array($on_id_collision, ['conflict', 'renumber'], true)) {
    fwrite(STDERR, "merge: invalid --on-id-collision '$on_id_collision' (must be conflict|renumber)\n");
    exit(1);
}

if (!extension_loaded('branchfs')) {
    fwrite(STDERR, "merge: branchfs extension not loaded\n");
    exit(1);
}

require_once __DIR__ . '/opcache.php';
require_once dirname(__DIR__, 3) . '/scripts/shared/sqlite_retry.php';
// Shared with branchctl.php: provides cow_install_parent_triggers etc.,
// used to recreate parent-side ancestor-capture triggers around schema
// rebuilds in this script.
require_once __DIR__ . '/cow_helpers.php';
require_once __DIR__ . '/fs_commit_helpers.php';

branchfs_set_db($db_path);

echo "=== BranchFS Merge ===\n";
echo "Source:    $source\n";
echo "Target:    $target\n";
echo "Strategy:  $strategy\n";
echo "On-ID-collision: $on_id_collision\n\n";

$db = new SQLite3($db_path);
// 15s busy timeout + sqlite_retry_busy() wrappers around the two write
// transactions below handle SQLITE_BUSY under concurrent writer load
// (parallel HTTP, git push, and other branchctl invocations).
$db->busyTimeout(15000);
$db->exec('PRAGMA journal_mode = WAL');
$db->exec('PRAGMA wal_autocheckpoint = 500');

function merge_branch_id(SQLite3 $db, string $name): int {
    return (int)$db->querySingle("SELECT id FROM branches WHERE name = '" . $db->escapeString($name) . "'");
}

/**
 * Hard-coded WordPress foreign-key rewrite map used by --on-id-collision=renumber.
 *
 * Keys and values are the BARE suffix after the `b{id}_wp_` branch prefix.
 * For example, `b2_wp_postmeta` has suffix `postmeta`.
 *
 * Values are [fk_table_suffix, fk_column] pairs that reference the PK-owning
 * table's PK column.
 *
 * TODO3 #9: this hard-coded map is the FALLBACK. At merge time we union it
 * with any FK edges declared via `PRAGMA foreign_key_list` on the actual
 * overlay tables (see merge_discover_fk_map below), so plugin tables that
 * carry real FK declarations (e.g. ACF, WooCommerce extensions) get their
 * references renumbered automatically.
 */
function merge_fk_map(): array {
    return [
        'posts' => [
            ['postmeta',           'post_id'],
            ['comments',           'comment_post_ID'],
            ['term_relationships', 'object_id'],
            ['posts',              'post_parent'],
        ],
        'users' => [
            ['usermeta', 'user_id'],
            ['posts',    'post_author'],
            ['comments', 'user_id'],
        ],
        'comments' => [
            ['commentmeta', 'comment_id'],
        ],
        'terms' => [
            ['term_taxonomy', 'term_id'],
        ],
        'term_taxonomy' => [
            ['term_relationships', 'term_taxonomy_id'],
        ],
    ];
}

/**
 * TODO3 #9 — discover FK edges at runtime via PRAGMA foreign_key_list.
 *
 * Walks every overlay table under the given prefix and records each
 * declared foreign key. Result shape matches merge_fk_map(): a map
 * `suffix_of_PK_table => [[suffix_of_referencing_table, fk_column], …]`.
 *
 * For COW branches, the authoritative declarations live on the overlay
 * tables (views don't carry `foreign_key_list` metadata), so this helper
 * walks `b{id}_wp_<suffix>__overlay` entries.
 *
 * Caller is expected to merge this with `merge_fk_map()` so:
 *   - Plugin tables with declared FKs get renumbered automatically.
 *   - WP-core tables (which don't declare FKs) still work via the
 *     hard-coded fallback.
 */
function merge_discover_fk_map(SQLite3 $db, string $branch_prefix): array {
    $out = [];
    $ov_suffix = '__overlay';
    $esc = $db->escapeString($branch_prefix);
    $r = $db->query(
        "SELECT name FROM sqlite_master WHERE type='table' "
      . "  AND name LIKE '" . $esc . "%" . $ov_suffix . "'"
    );
    if (!$r) return $out;
    $tables = [];
    while ($row = $r->fetchArray(SQLITE3_NUM)) {
        $full = (string)$row[0];
        // strip 'b{id}_wp_' prefix and '__overlay' suffix → bare suffix
        $suffix = substr($full, strlen($branch_prefix));
        $suffix = substr($suffix, 0, -strlen($ov_suffix));
        $tables[$full] = $suffix;
    }
    $r->finalize();

    foreach ($tables as $full => $suffix) {
        $fk = $db->query(
            'PRAGMA foreign_key_list("' . SQLite3::escapeString($full) . '")'
        );
        if (!$fk) continue;
        while ($fkrow = $fk->fetchArray(SQLITE3_ASSOC)) {
            $target = (string)($fkrow['table'] ?? '');
            if ($target === '') continue;
            // Target table name may be bare (unprefixed) in plugin DDL;
            // try to resolve to a suffix we recognize.
            $target_suffix = null;
            if (str_starts_with($target, $branch_prefix)) {
                $target_suffix = substr($target, strlen($branch_prefix));
                if (str_ends_with($target_suffix, $ov_suffix)) {
                    $target_suffix = substr($target_suffix, 0, -strlen($ov_suffix));
                }
            } elseif (isset($tables[$branch_prefix . $target . $ov_suffix])) {
                $target_suffix = $target;
            }
            if ($target_suffix === null) continue;
            $fk_col = (string)($fkrow['from'] ?? '');
            if ($fk_col === '') continue;
            $out[$target_suffix][] = [$suffix, $fk_col];
        }
        $fk->finalize();
    }
    return $out;
}

/** Union two FK maps (same shape). Avoids duplicates by [suffix, column]. */
function merge_union_fk_maps(array $a, array $b): array {
    $out = $a;
    foreach ($b as $pk_suffix => $edges) {
        foreach ($edges as $edge) {
            $found = false;
            foreach ($out[$pk_suffix] ?? [] as $existing) {
                if ($existing[0] === $edge[0] && $existing[1] === $edge[1]) {
                    $found = true; break;
                }
            }
            if (!$found) $out[$pk_suffix][] = $edge;
        }
    }
    return $out;
}

/**
 * True iff $table has a single-column INTEGER PRIMARY KEY AUTOINCREMENT.
 *
 * Detection: prefer DDL inspection (sqlite_master.sql LIKE '%AUTOINCREMENT%'),
 * fall back to PRAGMA table_info for a single-INTEGER-PK column.
 */
function merge_table_is_autoinc_pk(SQLite3 $db, string $table): bool {
    // For COW views, walk to the underlying overlay — autoincrement
    // metadata lives on the real table, not the view.
    $type = (string)$db->querySingle(
        "SELECT type FROM sqlite_master WHERE name='" . SQLite3::escapeString($table) . "'"
    );
    if ($type === 'view') {
        $overlay = $table . '__overlay';
        if ((string)$db->querySingle(
            "SELECT type FROM sqlite_master WHERE name='" . SQLite3::escapeString($overlay) . "'"
        ) === 'table') {
            $table = $overlay;
        }
    }
    $ddl = $db->querySingle(
        "SELECT sql FROM sqlite_master WHERE type='table' AND name='"
        . SQLite3::escapeString($table) . "'"
    );
    if (is_string($ddl) && stripos($ddl, 'AUTOINCREMENT') !== false) {
        return true;
    }
    // Fallback: single PK column typed INTEGER.
    $pk_cols = [];
    $r = $db->query('PRAGMA table_info("' . SQLite3::escapeString($table) . '")');
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        if ((int)$row['pk'] > 0) {
            $pk_cols[] = $row;
        }
    }
    if (count($pk_cols) !== 1) return false;
    return strtoupper((string)$pk_cols[0]['type']) === 'INTEGER';
}

/**
 * Current MAX(pk_col) from a (prefixed) table, or 0 if empty/missing.
 */
function merge_table_max_pk(SQLite3 $db, string $table, string $pk_col): int {
    $v = $db->querySingle(
        'SELECT MAX("' . SQLite3::escapeString($pk_col) . '") FROM "'
        . SQLite3::escapeString($table) . '"'
    );
    return $v === null ? 0 : (int)$v;
}

/**
 * Rewrite all FK-column occurrences of $old_id → $new_id in $row_json, but
 * only for the columns declared in the supplied FK map entry list.
 *
 * $fk_cols_for_this_table is a list of column names (strings) in the CURRENT
 * row's table that point at a renumbered PK in some other table. The caller
 * supplies only relevant columns for the row's table.
 *
 * Leaves NULL and 0 (unset FK) columns unchanged.
 */
function merge_rewrite_row_fks(string $row_json, array $fk_rewrites): string {
    $row = json_decode($row_json, true);
    if (!is_array($row)) return $row_json;
    $changed = false;
    foreach ($fk_rewrites as $entry) {
        [$col, $map] = $entry; // $map : old_id => new_id
        if (!array_key_exists($col, $row)) continue;
        $v = $row[$col];
        if ($v === null) continue;
        if ((int)$v === 0) continue;
        $iv = (int)$v;
        if (isset($map[$iv])) {
            $row[$col] = $map[$iv];
            $changed = true;
        }
    }
    return $changed ? json_encode($row, JSON_UNESCAPED_UNICODE) : $row_json;
}

/**
 * Replace the PK column value in a row_json blob with $new_id.
 */
function merge_row_with_pk(string $row_json, string $pk_col, int $new_id): string {
    $row = json_decode($row_json, true);
    if (!is_array($row)) return $row_json;
    $row[$pk_col] = $new_id;
    return json_encode($row, JSON_UNESCAPED_UNICODE);
}

/** Resolve a branch's full tree by walking parent_branch (COW inheritance). */
function merge_resolve_tree(SQLite3 $db, int $branch_id): array {
    $tree = [];
    $tombstoned = [];
    $bid = $branch_id;
    while ($bid > 0) {
        $r = $db->query("SELECT path, blob_hash, mode, mtime, is_dir FROM files WHERE branch_id = $bid");
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $p = $row['path'];
            if (isset($tree[$p]) || isset($tombstoned[$p])) continue;
            if (!$row['blob_hash'] && !$row['is_dir']) {
                $tombstoned[$p] = true;
                continue;
            }
            $tree[$p] = $row;
        }
        $pq = $db->prepare("SELECT b2.id FROM branches b1 JOIN branches b2 ON b1.parent_branch = b2.name WHERE b1.id = :b");
        $pq->bindValue(':b', $bid, SQLITE3_INTEGER);
        $pr = $pq->execute();
        $prow = $pr->fetchArray(SQLITE3_NUM);
        $bid = $prow ? (int)$prow[0] : 0;
    }
    return $tree;
}

/**
 * Load the fork-time snapshot for a branch — the first fs_commit recorded
 * on the branch. This is the authoritative "base" for 3-way merge: it
 * captures the branch's starting state before any divergence on either
 * the source or the parent (since the parent may have moved on too).
 */
function merge_load_fork_base(SQLite3 $db, int $branch_id): ?array {
    $s = $db->prepare("SELECT id FROM fs_commits WHERE branch_id = :b ORDER BY id ASC LIMIT 1");
    $s->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_ASSOC);
    if (!$row) return null;
    // TODO3 #5: the first commit is always FULL, so the materialized
    // tree here equals the raw rows — but use the walker anyway so this
    // code keeps working if the "first commit" bookkeeping ever changes.
    return fs_materialize_commit_tree($db, (int)$row['id']);
}

/**
 * Return the SOURCE branch's fork-time parent branch name if we can find
 * a common ancestor via parent_branch chains. Used only for logging.
 */
function merge_find_ancestor(SQLite3 $db, string $src, string $tgt): string {
    $chain = function(string $b) use ($db): array {
        $seen = [$b];
        while (true) {
            $s = $db->prepare("SELECT parent_branch FROM branches WHERE name = :n");
            $s->bindValue(':n', end($seen), SQLITE3_TEXT);
            $r = $s->execute();
            $row = $r->fetchArray(SQLITE3_ASSOC);
            if (!$row || !$row['parent_branch']) break;
            $seen[] = $row['parent_branch'];
        }
        return $seen;
    };
    $sc = $chain($src);
    $tc = $chain($tgt);
    $tcset = array_flip($tc);
    foreach ($sc as $c) {
        if (isset($tcset[$c])) return $c;
    }
    return 'main';
}

// ── DB merge helpers ──────────────────────────────────────────────────────────

/** Get ordered PRIMARY KEY column names for a table (empty if no explicit PK).
 *  For COW views (b{N}_wp_X), walk to the underlying overlay table — PRAGMA
 *  on a view always reports pk=0. The overlay shares the parent's PK shape. */
function db_pk_cols(SQLite3 $db, string $table): array {
    // If $table is a COW view, walk to its overlay (which has the real PK).
    $type = (string)$db->querySingle(
        "SELECT type FROM sqlite_master WHERE name='" . SQLite3::escapeString($table) . "'"
    );
    if ($type === 'view') {
        $overlay = $table . '__overlay';
        $overlay_type = (string)$db->querySingle(
            "SELECT type FROM sqlite_master WHERE name='" . SQLite3::escapeString($overlay) . "'"
        );
        if ($overlay_type === 'table') {
            $table = $overlay;
        }
    }
    $pk = [];
    $r = $db->query('PRAGMA table_info("' . SQLite3::escapeString($table) . '")');
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        if ((int)$row['pk'] > 0) $pk[(int)$row['pk']] = $row['name'];
    }
    ksort($pk);
    return array_values($pk);
}

/** Read all rows from a table; returns [pk_json => row_json]. */
function db_table_rows(SQLite3 $db, string $table, array $pk_cols): array {
    $rows = [];
    $r = $db->query('SELECT * FROM "' . SQLite3::escapeString($table) . '"');
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        if (empty($pk_cols)) {
            $pk_map = $row; // use whole row as key when no explicit PK
        } else {
            $pk_map = [];
            foreach ($pk_cols as $col) $pk_map[$col] = $row[$col] ?? null;
        }
        $rows[json_encode($pk_map, JSON_UNESCAPED_UNICODE)] = json_encode($row, JSON_UNESCAPED_UNICODE);
    }
    return $rows;
}

/** Read ancestor rows from db_snapshots for a given (branch_id, table_name). */
function db_ancestor_rows(SQLite3 $db, int $branch_id, string $table_name): array {
    $rows = [];
    $s = $db->prepare('SELECT row_pk, row_json FROM db_snapshots WHERE branch_id=:b AND table_name=:t');
    $s->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $s->bindValue(':t', $table_name, SQLITE3_TEXT);
    $r = $s->execute();
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $rows[$row['row_pk']] = $row['row_json'];
    return $rows;
}

/**
 * COW ancestor row resolution.
 *
 * Under COW, a branch's "fork-time" ancestor view is reconstructed lazily:
 *   - Rows that the branch overlaid (in b{id}_wp_X__overlay) need an ancestor
 *     reference: that's the parent's row at the same PK at fork time.
 *   - Rows the branch tombstoned (in b{id}_wp_X__tombstones) need an ancestor:
 *     the parent's row at fork time.
 *   - All other rows are inherited unchanged from the parent's current view —
 *     so source's "current" value equals ancestor (no diff possible from
 *     source's side), and merge correctly takes a noop.
 *
 * Pragmatic approximation: ancestor = parent's CURRENT row at the same PK.
 * This is exact when the parent hasn't independently mutated that row since
 * the fork. When the parent HAS mutated it, the merge will either degenerate
 * to a clean update (if branch's overlay was the only divergence) or surface
 * a conflict — which is the correct outcome since both sides changed the row.
 *
 * Returns [pk_json => row_json] containing entries only for divergent PKs.
 * Pass-through to db_snapshots for legacy (non-COW) branches still works
 * via the caller.
 */
function db_ancestor_rows_cow(SQLite3 $db, int $branch_id, string $logical_name,
                              array $pk_cols, string $table_suffix): array {
    $rows = [];
    $overlay_name = $logical_name . '__overlay';
    $tomb_name    = $logical_name . '__tombstones';

    // Look up the parent table for this branch+suffix from db_cow_branches.
    $st = $db->prepare(
        "SELECT parent_table_name FROM db_cow_branches "
      . "WHERE branch_id = :b AND table_suffix = :s"
    );
    $st->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $st->bindValue(':s', $table_suffix, SQLITE3_TEXT);
    $rs = $st->execute();
    $row = $rs->fetchArray(SQLITE3_ASSOC);
    $parent_table = $row ? (string)$row['parent_table_name'] : null;
    $rs->finalize();
    $st->close();
    if ($parent_table === null) return $rows;

    // Gather divergent PKs from overlay + tombstone.
    $divergent_pks = [];
    $or = $db->query('SELECT * FROM "' . SQLite3::escapeString($overlay_name) . '"');
    while ($drow = $or->fetchArray(SQLITE3_ASSOC)) {
        if (empty($pk_cols)) {
            $pk_map = $drow;
        } else {
            $pk_map = [];
            foreach ($pk_cols as $c) $pk_map[$c] = $drow[$c] ?? null;
        }
        $divergent_pks[json_encode($pk_map, JSON_UNESCAPED_UNICODE)] = $pk_map;
    }
    $tr = $db->query('SELECT * FROM "' . SQLite3::escapeString($tomb_name) . '"');
    while ($drow = $tr->fetchArray(SQLITE3_ASSOC)) {
        $pk_map = [];
        foreach ($pk_cols as $c) $pk_map[$c] = $drow[$c] ?? null;
        $divergent_pks[json_encode($pk_map, JSON_UNESCAPED_UNICODE)] = $pk_map;
    }

    if (empty($divergent_pks)) return $rows;

    // First: look up captured fork-time ancestors in db_ancestor_overlay
    // (per-branch snapshot, populated by legacy parent-side triggers
    // pre-TODO3 or by explicit refresh after merge — see the refresh
    // path below). Takes priority so merge #N can overwrite snapshot
    // #N-1's ancestor with the current branch state.
    $anc_lookup = $db->prepare(
        "SELECT row_pk, row_json FROM db_ancestor_overlay "
      . "WHERE branch_id = :b AND table_name = :t"
    );
    $anc_lookup->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $anc_lookup->bindValue(':t', $logical_name, SQLITE3_TEXT);
    $rr = $anc_lookup->execute();
    while ($row = $rr->fetchArray(SQLITE3_ASSOC)) {
        if (isset($divergent_pks[$row['row_pk']])) {
            $rows[$row['row_pk']] = $row['row_json'];
        }
    }

    // TODO3 #3: also look up the SHARED parent ancestor snapshot
    // (populated by the O(1) parent-side trigger). Only applies for PKs
    // we didn't already resolve via the per-branch overlay.
    //
    // Cluster-A #11: filter by `captured_at >= branches.created_at` so a
    // snapshot captured BEFORE this branch existed (e.g. a parent UPDATE
    // on another descendant) is not mistakenly adopted as this branch's
    // fork-time ancestor. Without this filter, a branch forked AFTER a
    // parent-side DELETE of row X would see the DELETE's snapshot and
    // use it as if X existed at fork-time — which it didn't.
    $sh_lookup = $db->prepare(
        "SELECT pa.row_pk, pa.row_json FROM db_parent_ancestor pa "
      . "JOIN branches b ON b.id = :b "
      . "WHERE pa.parent_table_name = :p "
      . "  AND pa.captured_at >= b.created_at"
    );
    $sh_lookup->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $sh_lookup->bindValue(':p', $parent_table, SQLITE3_TEXT);
    $sr = $sh_lookup->execute();
    while ($row = $sr->fetchArray(SQLITE3_ASSOC)) {
        if (isset($divergent_pks[$row['row_pk']])
            && !isset($rows[$row['row_pk']])) {
            $rows[$row['row_pk']] = $row['row_json'];
        }
    }

    // Second: any PK that was INSERTED on the parent AFTER fork is "absent
    // in ancestor" — populated by the parent-side AFTER-INSERT trigger.
    // Excluding it from $rows leaves the merge to compute $a===null, so
    // it correctly recognizes "both sides inserted same PK independently"
    // as a conflict instead of "source modified target's row".
    $pf_lookup = $db->prepare(
        "SELECT row_pk FROM db_post_fork_inserts "
      . "WHERE branch_id = :b AND table_name = :t"
    );
    $pf_lookup->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $pf_lookup->bindValue(':t', $logical_name, SQLITE3_TEXT);
    $pfr = $pf_lookup->execute();
    $post_fork = [];
    while ($row = $pfr->fetchArray(SQLITE3_ASSOC)) {
        $post_fork[$row['row_pk']] = true;
    }
    // Also consult the shared-across-descendants post-fork inserts table
    // (TODO3 #3). A row here means the parent inserted this PK at some
    // point; only rows captured AFTER this branch was forked qualify as
    // "post-fork" for this particular branch — rows captured before the
    // branch existed are simply fork-time-inherited.
    $spf_lookup = $db->prepare(
        "SELECT pf.row_pk FROM db_parent_post_fork_inserts pf "
      . "JOIN branches b ON b.id = :b "
      . "WHERE pf.parent_table_name = :p "
      . "  AND pf.captured_at >= b.created_at"
    );
    $spf_lookup->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $spf_lookup->bindValue(':p', $parent_table, SQLITE3_TEXT);
    $spr = $spf_lookup->execute();
    while ($row = $spr->fetchArray(SQLITE3_ASSOC)) {
        $post_fork[$row['row_pk']] = true;
    }

    // Third: fall back to parent's CURRENT row for any divergent PK we
    // didn't find in the ancestor overlay AND that wasn't post-fork-inserted.
    // This is exact when the parent hasn't independently mutated the row
    // since the fork — i.e. the ancestor IS the parent's current state.
    $where = empty($pk_cols)
        ? '0'
        : implode(' AND ', array_map(fn($c) => '"' . $c . '" = :' . $c, $pk_cols));
    $sel = $db->prepare(
        'SELECT * FROM "' . SQLite3::escapeString($parent_table) . '" WHERE ' . $where
    );
    foreach ($divergent_pks as $pk_json => $pk_map) {
        if (isset($rows[$pk_json])) continue; // already from db_ancestor_overlay
        if (isset($post_fork[$pk_json])) continue; // ancestor absent (parent inserted post-fork)
        if (empty($pk_cols)) continue;
        $sel->reset();
        foreach ($pk_cols as $c) {
            $v = $pk_map[$c] ?? null;
            $type = match (true) {
                $v === null  => SQLITE3_NULL,
                is_int($v)   => SQLITE3_INTEGER,
                is_float($v) => SQLITE3_FLOAT,
                default      => SQLITE3_TEXT,
            };
            $sel->bindValue(':' . $c, $v, $type);
        }
        $rr = $sel->execute();
        $prow = $rr->fetchArray(SQLITE3_ASSOC);
        $rr->finalize();
        if ($prow !== false && $prow !== null) {
            $rows[$pk_json] = json_encode($prow, JSON_UNESCAPED_UNICODE);
        }
        // If parent doesn't have the row at all (and it wasn't captured
        // pre-deletion either), ancestor is "absent" → leave $pk_json out.
    }
    return $rows;
}

/** Lazy-fill the COW ancestor rows for any PK where src_rows[$pk] differs
 *  from tgt_rows[$pk] but $anc_rows[$pk] is missing. Such PKs are
 *  inherited rows that look "different" (e.g. because a schema change
 *  made src view return an extra column) — without an ancestor, the row
 *  diff would falsely classify them as "both inserted different rows".
 *
 *  We fill them by looking up the parent's CURRENT row at that PK, which
 *  is exact when the parent hasn't independently edited the row since
 *  fork — i.e. it's the same value the branch saw as ancestor through
 *  inheritance. */
function db_ancestor_fill_for_diff(
    SQLite3 $db, int $branch_id, string $table_suffix,
    array $pk_cols, array $src_rows, array $tgt_rows, array $anc_rows,
    string $logical_name = ''
): array {
    if (empty($pk_cols)) return $anc_rows;

    // Look up the parent table for this branch+suffix.
    $st = $db->prepare(
        "SELECT parent_table_name FROM db_cow_branches "
      . "WHERE branch_id = :b AND table_suffix = :s"
    );
    $st->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $st->bindValue(':s', $table_suffix, SQLITE3_TEXT);
    $rs = $st->execute();
    $row = $rs->fetchArray(SQLITE3_ASSOC);
    $parent_table = $row ? (string)$row['parent_table_name'] : null;
    $rs->finalize();
    if ($parent_table === null) return $anc_rows;

    // Pre-load post-fork-inserted PKs so we don't fill ancestor for those.
    // ("Both inserted same PK" must remain a conflict — ancestor must stay
    // null.)
    $post_fork = [];
    if ($logical_name !== '') {
        $pf = $db->prepare(
            "SELECT row_pk FROM db_post_fork_inserts "
          . "WHERE branch_id = :b AND table_name = :t"
        );
        $pf->bindValue(':b', $branch_id, SQLITE3_INTEGER);
        $pf->bindValue(':t', $logical_name, SQLITE3_TEXT);
        $pfr = $pf->execute();
        while ($row2 = $pfr->fetchArray(SQLITE3_ASSOC)) {
            $post_fork[$row2['row_pk']] = true;
        }
    }
    // TODO3 #3: shared parent-side post-fork inserts (captured after the
    // branch was forked).
    $spf = $db->prepare(
        "SELECT pf.row_pk FROM db_parent_post_fork_inserts pf "
      . "JOIN branches b ON b.id = :b "
      . "WHERE pf.parent_table_name = :p "
      . "  AND pf.captured_at >= b.created_at"
    );
    $spf->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $spf->bindValue(':p', $parent_table, SQLITE3_TEXT);
    $spr = $spf->execute();
    while ($row2 = $spr->fetchArray(SQLITE3_ASSOC)) {
        $post_fork[$row2['row_pk']] = true;
    }
    // Pre-load shared parent ancestor snapshots; used to short-circuit
    // the "parent's current row" fallback below with the captured
    // pre-divergence value.
    //
    // Cluster-A #11: filter by `captured_at >= branches.created_at` —
    // same rationale as the lookup above in the no-merge-base path.
    $shared_anc = [];
    $sa = $db->prepare(
        "SELECT pa.row_pk, pa.row_json FROM db_parent_ancestor pa "
      . "JOIN branches b ON b.id = :b "
      . "WHERE pa.parent_table_name = :p "
      . "  AND pa.captured_at >= b.created_at"
    );
    $sa->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $sa->bindValue(':p', $parent_table, SQLITE3_TEXT);
    $sar = $sa->execute();
    while ($row2 = $sar->fetchArray(SQLITE3_ASSOC)) {
        $shared_anc[$row2['row_pk']] = $row2['row_json'];
    }

    $where = implode(' AND ', array_map(fn($c) => '"' . $c . '" = :' . $c, $pk_cols));
    $sel = $db->prepare(
        'SELECT * FROM "' . SQLite3::escapeString($parent_table) . '" WHERE ' . $where
    );

    foreach ($src_rows as $pk => $src_json) {
        if (isset($anc_rows[$pk])) continue;
        if (isset($post_fork[$pk])) continue;
        $tgt_json = $tgt_rows[$pk] ?? null;
        // If src and tgt agree, no ancestor needed (this PK already noops).
        if ($src_json === $tgt_json) continue;

        // TODO3 #3: prefer the shared parent snapshot (captured value
        // from BEFORE parent's first post-fork modification) over the
        // current-row fallback below — that's the true ancestor.
        if (isset($shared_anc[$pk])) {
            $anc_rows[$pk] = $shared_anc[$pk];
            continue;
        }

        $pk_map = json_decode($pk, true);
        if (!is_array($pk_map)) continue;
        $sel->reset();
        foreach ($pk_cols as $c) {
            $v = $pk_map[$c] ?? null;
            $type = match (true) {
                $v === null  => SQLITE3_NULL,
                is_int($v)   => SQLITE3_INTEGER,
                is_float($v) => SQLITE3_FLOAT,
                default      => SQLITE3_TEXT,
            };
            $sel->bindValue(':' . $c, $v, $type);
        }
        $rr = $sel->execute();
        $prow = $rr->fetchArray(SQLITE3_ASSOC);
        $rr->finalize();
        if ($prow !== false && $prow !== null) {
            $anc_rows[$pk] = json_encode($prow, JSON_UNESCAPED_UNICODE);
        }
    }
    return $anc_rows;
}

/** True iff $branch_id has any COW marker rows in db_cow_branches. */
function branch_is_cow(SQLite3 $db, int $branch_id): bool {
    $n = (int)$db->querySingle(
        "SELECT COUNT(*) FROM db_cow_branches WHERE branch_id = $branch_id"
    );
    return $n > 0;
}

/** INSERT OR REPLACE a row into a table from its JSON representation. */
function db_upsert(SQLite3 $db, string $table, string $row_json): void {
    $row  = json_decode($row_json, true);
    $cols = array_keys($row);
    $quoted = array_map(fn($c) => '"' . $c . '"', $cols);
    $params  = array_map(fn($c) => ':' . $c, $cols);
    $sql  = 'INSERT OR REPLACE INTO "' . SQLite3::escapeString($table) . '" ('
          . implode(', ', $quoted) . ') VALUES (' . implode(', ', $params) . ')';
    $stmt = $db->prepare($sql);
    foreach ($row as $col => $val) {
        $type = match (true) {
            $val === null  => SQLITE3_NULL,
            is_int($val)   => SQLITE3_INTEGER,
            is_float($val) => SQLITE3_FLOAT,
            default        => SQLITE3_TEXT,
        };
        $stmt->bindValue(':' . $col, $val, $type);
    }
    $stmt->execute();
}

/** DELETE a row identified by its JSON-encoded PK. */
function db_delete_by_pk(SQLite3 $db, string $table, string $pk_json, array $pk_cols): void {
    $pk    = json_decode($pk_json, true);
    $conds = array_map(fn($c) => '"' . $c . '" = :' . $c, $pk_cols);
    $sql   = 'DELETE FROM "' . SQLite3::escapeString($table) . '" WHERE ' . implode(' AND ', $conds);
    $stmt  = $db->prepare($sql);
    foreach ($pk_cols as $col) {
        $val  = $pk[$col] ?? null;
        $type = match (true) {
            $val === null  => SQLITE3_NULL,
            is_int($val)   => SQLITE3_INTEGER,
            is_float($val) => SQLITE3_FLOAT,
            default        => SQLITE3_TEXT,
        };
        $stmt->bindValue(':' . $col, $val, $type);
    }
    $stmt->execute();
}

// ── Schema-merge helpers ─────────────────────────────────────────────────────
//
// Column-level 3-way schema diff for tables that exist on BOTH source and
// target. Inputs (per side):
//   - DDL string from sqlite_master (CREATE TABLE …)
//   - List of CREATE INDEX … statements from sqlite_master
// We normalize each to a comparable shape so:
//   - column adds/drops/type changes show up as per-column diffs
//   - index adds/drops show up as per-index diffs
// keyed in a way that survives the prefix-rename game (index names / table
// names embed the b{branch_id}_wp_ prefix and must be normalized away).

/** Read live columns of a table from PRAGMA table_info: ordered list of
 *  ['name' => …, 'type' => …, 'notnull' => 0|1, 'dflt_value' => …, 'pk' => 0|1+].
 *  For COW views, walks to the underlying overlay so notnull/default/pk
 *  attributes are accurate (PRAGMA on a view reports them as zero/null). */
function schema_columns_from_pragma(SQLite3 $db, string $table): array {
    $type = (string)$db->querySingle(
        "SELECT type FROM sqlite_master WHERE name='" . SQLite3::escapeString($table) . "'"
    );
    if ($type === 'view') {
        $overlay = $table . '__overlay';
        $overlay_type = (string)$db->querySingle(
            "SELECT type FROM sqlite_master WHERE name='" . SQLite3::escapeString($overlay) . "'"
        );
        if ($overlay_type === 'table') {
            $table = $overlay;
        }
    }
    $cols = [];
    $r = $db->query('PRAGMA table_info("' . SQLite3::escapeString($table) . '")');
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $cols[] = [
            'name'       => (string)$row['name'],
            'type'       => (string)$row['type'],
            'notnull'    => (int)$row['notnull'],
            'dflt_value' => $row['dflt_value'],
            'pk'         => (int)$row['pk'],
        ];
    }
    return $cols;
}

/** Read live indexes of a table from sqlite_master: list of
 *  ['name'=>…,'sql'=>…]. Auto-indexes (sql IS NULL) are skipped. */
function schema_indexes_from_master(SQLite3 $db, string $table): array {
    $idx = [];
    $st = $db->prepare(
        "SELECT name, sql FROM sqlite_master WHERE type='index' "
      . "AND tbl_name=:t AND sql IS NOT NULL ORDER BY name"
    );
    $st->bindValue(':t', $table, SQLITE3_TEXT);
    $r = $st->execute();
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $idx[] = ['name' => (string)$row['name'], 'sql' => (string)$row['sql']];
    }
    return $idx;
}

/** Parse a CREATE TABLE DDL string into a normalized columns list of the
 *  same shape that schema_columns_from_pragma() returns.
 *
 *  We attach the parsed schema to a temp DB, then use PRAGMA table_info on it.
 *  This sidesteps the surprisingly hairy job of writing a full SQLite DDL
 *  parser in PHP. The temp DB lives only as long as this function call.
 */
function schema_columns_from_ddl(string $ddl, string $original_name): array {
    if ($ddl === '') return [];
    $tmp = new SQLite3(':memory:');
    try {
        // Force the table name to a known stable string so we can PRAGMA it.
        $rewritten = preg_replace(
            '/^(CREATE\s+TABLE\s+)(?:IF\s+NOT\s+EXISTS\s+)?"?'
            . preg_quote($original_name, '/') . '"?(\s*\()/is',
            '$1IF NOT EXISTS "schema_probe"$2',
            $ddl, 1
        );
        if (!$rewritten) return [];
        @$tmp->exec($rewritten);
        $cols = [];
        $r = $tmp->query('PRAGMA table_info("schema_probe")');
        if (!$r) return [];
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $cols[] = [
                'name'       => (string)$row['name'],
                'type'       => (string)$row['type'],
                'notnull'    => (int)$row['notnull'],
                'dflt_value' => $row['dflt_value'],
                'pk'         => (int)$row['pk'],
            ];
        }
        return $cols;
    } finally {
        $tmp->close();
    }
}

/** Strip a prefix-specific table or index name out of an index DDL string so
 *  two indexes that differ ONLY in their b{N}_wp_ prefix compare equal.
 *  Also trims whitespace, drops double-quotes around identifiers, normalizes
 *  spacing around parens, and uppercases keywords for stable comparison. */
function schema_normalize_index_ddl(string $ddl, string $prefix): string {
    $norm = str_replace($prefix, 'BPFX_', $ddl);
    // Strip double-quotes around identifiers (sqlite_master may or may not
    // emit them depending on how the DDL was originally written).
    $norm = str_replace('"', '', $norm);
    // Collapse whitespace and normalize whitespace around parens.
    $norm = preg_replace('/\s+/', ' ', trim($norm));
    $norm = preg_replace('/\s*\(\s*/', '(', $norm);
    $norm = preg_replace('/\s*\)\s*/', ')', $norm);
    $norm = preg_replace('/\s*,\s*/', ',', $norm);
    return $norm;
}

/** Normalized key for an index — the DDL minus any branch-specific prefix.
 *  Two indexes from different branches that describe the same shape will
 *  produce the same key. */
function schema_index_key(array $index, string $prefix): string {
    return schema_normalize_index_ddl($index['sql'], $prefix);
}

/** Compare two column dicts (from schema_columns_from_*) for "same shape".
 *  Ignores column ordering — only the per-column attributes matter. */
function schema_columns_equal(array $a, array $b): bool {
    // Normalize: trim / uppercase types, compare default values loosely.
    $norm = function (array $c): array {
        return [
            'name'       => $c['name'],
            'type'       => strtoupper(trim((string)$c['type'])),
            'notnull'    => (int)$c['notnull'],
            'dflt_value' => is_null($c['dflt_value']) ? null : (string)$c['dflt_value'],
            'pk'         => (int)$c['pk'],
        ];
    };
    return $norm($a) === $norm($b);
}

/**
 * Build column-level + index-level 3-way diff for a single table that
 * exists on both source and target.
 *
 * Returns an array with:
 *   ['ops' => [ ... apply ops ... ],
 *    'conflicts' => [ ... conflict descriptors ... ]]
 *
 * Each op is one of:
 *   ['type'=>'add_column',  'table'=>$tgt, 'col_def'=>$ddl_fragment]
 *   ['type'=>'drop_column', 'table'=>$tgt, 'col_name'=>$name]
 *   ['type'=>'modify_column', 'table'=>$tgt, 'col_name'=>$name, 'new_col_def'=>$ddl_fragment, 'src_col'=>$col_dict]
 *   ['type'=>'add_index', 'src_ddl'=>$sql, 'src_prefix'=>…, 'tgt_prefix'=>…]
 *   ['type'=>'drop_index', 'name'=>$tgt_index_name]
 *
 * Each conflict carries 'desc' + 'theirs_op' + 'ours_op' (ours_op is null
 * because keeping target's schema means doing nothing).
 */
function schema_diff_table(
    array $anc_cols, array $anc_idx,
    array $src_cols, array $src_idx,
    array $tgt_cols, array $tgt_idx,
    string $src_prefix, string $tgt_prefix,
    string $src_ddl, string $tgt_table
): array {
    $ops = [];
    $conflicts = [];

    // Build name => col dicts for each side.
    $by_name = function (array $cols): array {
        $o = [];
        foreach ($cols as $c) $o[$c['name']] = $c;
        return $o;
    };
    $a = $by_name($anc_cols);
    $s = $by_name($src_cols);
    $t = $by_name($tgt_cols);

    $all_names = array_unique(array_merge(
        array_keys($a), array_keys($s), array_keys($t)
    ));

    foreach ($all_names as $name) {
        $av = $a[$name] ?? null;
        $sv = $s[$name] ?? null;
        $tv = $t[$name] ?? null;

        if ($sv !== null && $tv !== null && schema_columns_equal($sv, $tv)) {
            // Both sides agree on this column.
            continue;
        }

        if ($av === null) {
            // Column is brand new on at least one side.
            if ($sv !== null && $tv === null) {
                // Source added it, target doesn't have it yet → ADD on target.
                $def = schema_extract_column_def($src_ddl, $name);
                if ($def === null) {
                    $conflicts[] = [
                        'desc' => "$tgt_table column '$name' (cannot extract column DDL from source)",
                        'theirs_op' => null, 'ours_op' => null,
                    ];
                    continue;
                }
                $ops[] = [
                    'type'    => 'add_column',
                    'table'   => $tgt_table,
                    'col_def' => $def,
                ];
            } elseif ($sv === null && $tv !== null) {
                // Target added it independently — keep it.
                continue;
            } elseif ($sv !== null && $tv !== null && !schema_columns_equal($sv, $tv)) {
                // Both added the same column with DIFFERENT shapes → CONFLICT.
                $def = schema_extract_column_def($src_ddl, $name);
                $conflicts[] = [
                    'desc' => "$tgt_table column '$name' (both branches added different definitions)",
                    'theirs_op' => $def !== null ? [
                        'type'        => 'modify_column',
                        'table'       => $tgt_table,
                        'col_name'    => $name,
                        'new_col_def' => $def,
                        'src_col'     => $sv,
                    ] : null,
                    'ours_op' => null,
                ];
            }
            continue;
        }

        // Ancestor had this column.
        if ($sv === null && $tv !== null) {
            if (schema_columns_equal($av, $tv)) {
                // Source dropped it, target unchanged → DROP on target.
                $ops[] = [
                    'type'     => 'drop_column',
                    'table'    => $tgt_table,
                    'col_name' => $name,
                ];
            } else {
                // Source dropped, target modified → CONFLICT.
                $conflicts[] = [
                    'desc' => "$tgt_table column '$name' (source dropped, target modified)",
                    'theirs_op' => [
                        'type'     => 'drop_column',
                        'table'    => $tgt_table,
                        'col_name' => $name,
                    ],
                    'ours_op' => null,
                ];
            }
            continue;
        }
        if ($sv === null && $tv === null) {
            // Both dropped — already gone, no-op.
            continue;
        }
        if ($sv !== null && $tv !== null) {
            // Both present, $sv != $tv (we returned earlier when equal).
            if (schema_columns_equal($sv, $av)) {
                // Source unchanged, target modified → keep target.
                continue;
            } elseif (schema_columns_equal($tv, $av)) {
                // Source modified, target unchanged → adopt source.
                $def = schema_extract_column_def($src_ddl, $name);
                if ($def === null) {
                    $conflicts[] = [
                        'desc' => "$tgt_table column '$name' (cannot extract source DDL for type change)",
                        'theirs_op' => null, 'ours_op' => null,
                    ];
                    continue;
                }
                $ops[] = [
                    'type'        => 'modify_column',
                    'table'       => $tgt_table,
                    'col_name'    => $name,
                    'new_col_def' => $def,
                    'src_col'     => $sv,
                ];
            } else {
                // Both modified differently → CONFLICT.
                $def = schema_extract_column_def($src_ddl, $name);
                $conflicts[] = [
                    'desc' => "$tgt_table column '$name' (both branches modified differently)",
                    'theirs_op' => $def !== null ? [
                        'type'        => 'modify_column',
                        'table'       => $tgt_table,
                        'col_name'    => $name,
                        'new_col_def' => $def,
                        'src_col'     => $sv,
                    ] : null,
                    'ours_op' => null,
                ];
            }
        }
    }

    // ── Index-level diff ─────────────────────────────────────────────────
    //
    // Match indexes by their normalized DDL (prefix-independent). For each
    // unique normalized DDL key, decide based on presence in src/tgt/anc.

    // Build map: normalized_key => [side_idx]
    $a_idx = [];
    $s_idx = [];
    $t_idx = [];
    foreach ($anc_idx as $i) $a_idx[schema_index_key($i, $src_prefix)] = $i;
    foreach ($src_idx as $i) $s_idx[schema_index_key($i, $src_prefix)] = $i;
    foreach ($tgt_idx as $i) $t_idx[schema_index_key($i, $tgt_prefix)] = $i;

    $all_keys = array_unique(array_merge(
        array_keys($a_idx), array_keys($s_idx), array_keys($t_idx)
    ));

    foreach ($all_keys as $key) {
        $av = $a_idx[$key] ?? null;
        $sv = $s_idx[$key] ?? null;
        $tv = $t_idx[$key] ?? null;

        $in_a = $av !== null;
        $in_s = $sv !== null;
        $in_t = $tv !== null;

        if ($in_s === $in_t) continue; // both have / both lack — no-op

        if (!$in_a) {
            if ($in_s && !$in_t) {
                // Source added an index target doesn't have → CREATE on target.
                $ops[] = [
                    'type'       => 'add_index',
                    'src_ddl'    => $sv['sql'],
                    'src_index_name' => $sv['name'],
                    'src_prefix' => $src_prefix,
                    'tgt_prefix' => $tgt_prefix,
                ];
            }
            // !$in_s && $in_t: target added it; keep.
        } else {
            if ($in_t && !$in_s) {
                // Source dropped it, target still has it → DROP from target.
                $ops[] = [
                    'type' => 'drop_index',
                    'name' => $tv['name'],
                ];
            }
            // $in_s && !$in_t: source has it, target dropped it → keep target.
        }
    }

    return ['ops' => $ops, 'conflicts' => $conflicts];
}

/** Best-effort extraction of one column's DDL fragment ("seo_title TEXT NOT
 *  NULL DEFAULT '50'") from a CREATE TABLE statement. We attach the schema
 *  to a temp DB and re-emit the column definition by reading PRAGMA + the
 *  raw DDL slice. This is conservative — falls back to type+nullable+default
 *  if we can't slice the original token range. */
function schema_extract_column_def(string $ddl, string $col_name): ?string {
    if ($ddl === '') return null;

    // Try to grab the column's exact slice from the DDL inside the parens.
    if (preg_match('/^[^(]*\((.*)\)\s*[^)]*$/s', $ddl, $m)) {
        $body = $m[1];
        // Split body on commas not inside parens.
        $depth = 0;
        $parts = [];
        $cur = '';
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];
            if ($ch === '(') $depth++;
            elseif ($ch === ')') $depth--;
            if ($ch === ',' && $depth === 0) {
                $parts[] = trim($cur);
                $cur = '';
                continue;
            }
            $cur .= $ch;
        }
        if ($cur !== '') $parts[] = trim($cur);

        foreach ($parts as $part) {
            // Match column name (possibly quoted).
            if (preg_match('/^"?(' . preg_quote($col_name, '/') . ')"?\s+(.+)$/is', $part, $cm)) {
                // Strip a leading PRIMARY/UNIQUE/CHECK/FOREIGN keyword to skip
                // table-level constraints accidentally matching.
                $rest_first = strtoupper(substr(trim($cm[2]), 0, 7));
                if (in_array(substr($rest_first, 0, 7), ['PRIMARY', 'FOREIGN'])) continue;
                if (substr($rest_first, 0, 6) === 'UNIQUE') continue;
                if (substr($rest_first, 0, 5) === 'CHECK')  continue;
                return '"' . $col_name . '" ' . trim($cm[2]);
            }
        }
    }

    // Fallback: derive from a temp-DB PRAGMA.
    $tmp = new SQLite3(':memory:');
    try {
        $rewritten = preg_replace(
            '/^(CREATE\s+TABLE\s+)(?:IF\s+NOT\s+EXISTS\s+)?"?[^"\s(]+"?(\s*\()/is',
            '$1IF NOT EXISTS "schema_probe"$2',
            $ddl, 1
        );
        if (!$rewritten) return null;
        @$tmp->exec($rewritten);
        $r = $tmp->query('PRAGMA table_info("schema_probe")');
        if (!$r) return null;
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            if ((string)$row['name'] !== $col_name) continue;
            $def = '"' . $col_name . '"';
            if ($row['type']) $def .= ' ' . $row['type'];
            if ((int)$row['notnull']) $def .= ' NOT NULL';
            if ($row['dflt_value'] !== null) {
                $dv = (string)$row['dflt_value'];
                $def .= ' DEFAULT ' . $dv;
            }
            return $def;
        }
        return null;
    } finally {
        $tmp->close();
    }
}

/** Resolve a logical table name to its physical write target. For COW
 *  views, this is the overlay. For real tables, it's the table itself. */
function schema_physical_target(SQLite3 $db, string $name): string {
    $type = (string)$db->querySingle(
        "SELECT type FROM sqlite_master WHERE name='" . SQLite3::escapeString($name) . "'"
    );
    if ($type === 'view') {
        $overlay = $name . '__overlay';
        $ov_type = (string)$db->querySingle(
            "SELECT type FROM sqlite_master WHERE name='" . SQLite3::escapeString($overlay) . "'"
        );
        if ($ov_type === 'table') return $overlay;
    }
    return $name;
}

/** After a schema-altering op on a COW overlay, recreate the dependent
 *  view so SELECT * pulls the new column shape. */
function schema_recreate_view_if_cow(SQLite3 $db, string $logical_name): void {
    $type = (string)$db->querySingle(
        "SELECT type FROM sqlite_master WHERE name='" . SQLite3::escapeString($logical_name) . "'"
    );
    if ($type !== 'view') return;
    // Pull the suffix and let branchctl helpers do the work.
    if (!preg_match('/^b(\d+)_wp_(.+)$/', $logical_name, $m)) return;
    $suffix = $m[2];
    if (function_exists('cow_recreate_views_for_table')) {
        cow_recreate_views_for_table($db, $suffix);
    }
}

/** Apply a single schema op against the target table. May rebuild the table
 *  for DROP/MODIFY ops on older SQLite, or use ALTER TABLE on 3.35+.
 *
 *  For COW: the op references the logical (view) name; we ALTER the
 *  underlying overlay and recreate the view. */
function schema_apply_op(SQLite3 $db, array $op, string $src_prefix, string $tgt_prefix): void {
    // Translate ops that reference a logical (view) name to the physical
    // overlay name so the underlying ALTER actually mutates real schema.
    $logical_for_view_refresh = null;
    if (isset($op['table'])) {
        $physical = schema_physical_target($db, $op['table']);
        if ($physical !== $op['table']) {
            $logical_for_view_refresh = $op['table'];
            $op['table'] = $physical;
        }
    }

    switch ($op['type']) {
        case 'add_column':
            // ALTER TABLE works for ADD COLUMN even on old SQLite.
            $db->exec('ALTER TABLE "' . SQLite3::escapeString($op['table']) . '" '
                    . 'ADD COLUMN ' . $op['col_def']);
            if ($logical_for_view_refresh !== null) {
                schema_recreate_view_if_cow($db, $logical_for_view_refresh);
            }
            break;

        case 'drop_column':
            // ALTER TABLE … DROP COLUMN requires SQLite 3.35+; try and fall
            // back to table-rebuild on failure. We use schema_rebuild_table_drop_columns
            // directly here for consistency — a successful native ALTER would
            // skip the COW dependent-view drop/recreate dance, but with the
            // dependent view still referencing $tgt, the native ALTER is
            // blocked anyway (SQLite refuses DROP COLUMN on a column referenced
            // by a view's SELECT *). Going straight to rebuild handles both.
            $tgt = $op['table'];
            schema_rebuild_table_drop_columns($db, $tgt, [$op['col_name']]);
            if ($logical_for_view_refresh !== null) {
                schema_recreate_view_if_cow($db, $logical_for_view_refresh);
            }
            break;

        case 'modify_column':
            // No native ALTER COLUMN in SQLite — rebuild.
            schema_rebuild_table_modify_column($db, $op['table'], $op['col_name'], $op['new_col_def'], $op['src_col']);
            if ($logical_for_view_refresh !== null) {
                schema_recreate_view_if_cow($db, $logical_for_view_refresh);
            }
            break;

        case 'add_index':
            // Rewrite the source-side index DDL so it lives on target's
            // table with target's prefix in the index name.
            $sql = $op['src_ddl'];
            $old_idx = $op['src_index_name'];
            $new_idx = str_replace($src_prefix, $tgt_prefix, $old_idx);
            $sql = preg_replace(
                '/^(CREATE\s+(?:UNIQUE\s+)?INDEX\s+)(?:IF\s+NOT\s+EXISTS\s+)?"?'
                . preg_quote($old_idx, '/') . '"?/i',
                '$1IF NOT EXISTS "' . $new_idx . '"',
                $sql, 1
            );
            // Rewrite ON "src_table" → ON "tgt_table".
            $sql = preg_replace_callback(
                '/\bON\s+"?(' . preg_quote($src_prefix, '/') . '[A-Za-z0-9_]+)"?\s*\(/i',
                function ($m) use ($src_prefix, $tgt_prefix) {
                    $tgt = str_replace($src_prefix, $tgt_prefix, $m[1]);
                    return 'ON "' . $tgt . '" (';
                },
                $sql, 1
            );
            @$db->exec($sql);
            break;

        case 'drop_index':
            $db->exec('DROP INDEX IF EXISTS "' . SQLite3::escapeString($op['name']) . '"');
            break;
    }
}

/** Drop COW parent-side ancestor-capture triggers attached to $table, if
 *  any. Returns the trigger SQL strings so the caller can recreate them
 *  after a rebuild. */
function schema_drop_cow_anc_triggers(SQLite3 $db, string $table): array {
    $rows = [];
    $st = $db->prepare(
        "SELECT name, sql FROM sqlite_master WHERE type='trigger' AND tbl_name = :t "
      . "AND name LIKE 'cow\\_anc\\_\\_%' ESCAPE '\\'"
    );
    $st->bindValue(':t', $table, SQLITE3_TEXT);
    $r = $st->execute();
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
    foreach ($rows as $row) {
        $db->exec('DROP TRIGGER IF EXISTS "' . SQLite3::escapeString($row['name']) . '"');
    }
    return $rows;
}

/** Drop COW dependent views (and their INSTEAD OF triggers) that reference
 *  $parent_table. Returns the (suffix, branch_id, parent_table) tuples
 *  so cow_recreate_views_for_table can be called per-suffix to rebuild
 *  them after the parent rebuild completes. */
function schema_drop_dependent_cow_views(SQLite3 $db, string $parent_table): array {
    $deps = [];
    $st = $db->prepare(
        "SELECT branch_id, table_suffix FROM db_cow_branches WHERE parent_table_name = :p"
    );
    $st->bindValue(':p', $parent_table, SQLITE3_TEXT);
    $r = $st->execute();
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $deps[] = $row;
    foreach ($deps as $row) {
        $logical = "b{$row['branch_id']}_wp_{$row['table_suffix']}";
        foreach (["{$logical}__cow_ins", "{$logical}__cow_upd", "{$logical}__cow_del"] as $trg) {
            $db->exec("DROP TRIGGER IF EXISTS \"$trg\"");
        }
        $db->exec("DROP VIEW IF EXISTS \"$logical\"");
    }
    return $deps;
}

/** Recreate COW dependent views for the suffixes we previously dropped. */
function schema_recreate_dependent_cow_views(SQLite3 $db, array $deps): void {
    if (!function_exists('cow_recreate_views_for_table')) return;
    $seen = [];
    foreach ($deps as $row) {
        $sfx = (string)$row['table_suffix'];
        if (isset($seen[$sfx])) continue;
        $seen[$sfx] = true;
        cow_recreate_views_for_table($db, $sfx);
    }
}

/** Recreate previously-saved COW parent-side ancestor-capture triggers. */
function schema_recreate_cow_anc_triggers(SQLite3 $db, array $trigger_defs): void {
    foreach ($trigger_defs as $tg) {
        if (!empty($tg['sql'])) {
            @$db->exec($tg['sql']);
        }
    }
}

/** SQLite-3.34-and-older compatible "drop columns" by full table rebuild.
 *  Also re-creates indexes that don't reference the dropped columns.
 *  Drops/recreates COW parent-side ancestor triggers around the rebuild
 *  (otherwise the trigger keeps the table locked during DROP/RENAME).
 *  Also rebuilds the trigger SQL with the NEW column set so OLD/NEW
 *  references no longer point at dropped columns. */
function schema_rebuild_table_drop_columns(SQLite3 $db, string $table, array $drop_cols): void {
    $saved_triggers = schema_drop_cow_anc_triggers($db, $table);
    $dropped_views = schema_drop_dependent_cow_views($db, $table);
    $cols = schema_columns_from_pragma($db, $table);
    $keep = array_values(array_filter($cols, fn($c) => !in_array($c['name'], $drop_cols, true)));
    if (empty($keep)) {
        // Restore triggers (best-effort) before bailing.
        schema_recreate_cow_anc_triggers($db, $saved_triggers);
        schema_recreate_dependent_cow_views($db, $dropped_views);
        return;
    }

    $col_names = array_map(fn($c) => '"' . $c['name'] . '"', $keep);
    $col_list  = implode(', ', $col_names);

    // Reuse the original DDL minus the dropped columns. We rebuild via
    // CREATE TABLE … AS SELECT, then re-add an INTEGER PRIMARY KEY by
    // using the existing column types.
    $ddl_parts = [];
    foreach ($keep as $c) {
        $part = '"' . $c['name'] . '" ' . ($c['type'] !== '' ? $c['type'] : 'TEXT');
        if ($c['pk']) $part .= ' PRIMARY KEY';
        if ($c['notnull']) $part .= ' NOT NULL';
        if ($c['dflt_value'] !== null) $part .= ' DEFAULT ' . $c['dflt_value'];
        $ddl_parts[] = $part;
    }
    $tmp_table = $table . '__rebuild_tmp';
    $db->exec('CREATE TABLE "' . SQLite3::escapeString($tmp_table) . '" ('
            . implode(', ', $ddl_parts) . ')');
    $db->exec('INSERT INTO "' . SQLite3::escapeString($tmp_table) . '" '
            . '(' . $col_list . ') SELECT ' . $col_list
            . ' FROM "' . SQLite3::escapeString($table) . '"');
    $db->exec('DROP TABLE "' . SQLite3::escapeString($table) . '"');
    $db->exec('ALTER TABLE "' . SQLite3::escapeString($tmp_table)
            . '" RENAME TO "' . SQLite3::escapeString($table) . '"');

    // Re-install COW parent-side ancestor capture triggers AFTER the
    // rebuild — but with the new (post-drop) column set baked into the
    // OLD/NEW JSON projection. We can't just exec the saved trigger SQL
    // because it references the now-dropped column. cow_install_parent_triggers
    // re-derives the projection from the live PRAGMA.
    if (!empty($saved_triggers) && function_exists('cow_install_parent_triggers')) {
        cow_install_parent_triggers($db, $table);
    }
    schema_recreate_dependent_cow_views($db, $dropped_views);
}

/** Rebuild a table to apply a column-type change. Uses src_col's metadata
 *  to know the target type. Other columns retain their definitions. */
function schema_rebuild_table_modify_column(SQLite3 $db, string $table,
                                            string $col_name, string $new_col_def,
                                            array $src_col): void {
    $saved_triggers = schema_drop_cow_anc_triggers($db, $table);
    $dropped_views = schema_drop_dependent_cow_views($db, $table);
    $cols = schema_columns_from_pragma($db, $table);
    $ddl_parts = [];
    $col_names = [];
    foreach ($cols as $c) {
        $col_names[] = '"' . $c['name'] . '"';
        if ($c['name'] === $col_name) {
            // Replace this column's definition with src's.
            $part = $new_col_def;
            // strip out "COLUMN" keyword if present (ADD COLUMN ddl)
            $part = preg_replace('/^\s*COLUMN\s+/i', '', $part);
            $ddl_parts[] = $part;
        } else {
            $part = '"' . $c['name'] . '" ' . ($c['type'] !== '' ? $c['type'] : 'TEXT');
            if ($c['pk']) $part .= ' PRIMARY KEY';
            if ($c['notnull']) $part .= ' NOT NULL';
            if ($c['dflt_value'] !== null) $part .= ' DEFAULT ' . $c['dflt_value'];
            $ddl_parts[] = $part;
        }
    }
    $col_list = implode(', ', $col_names);
    $tmp_table = $table . '__rebuild_tmp';
    $db->exec('CREATE TABLE "' . SQLite3::escapeString($tmp_table) . '" ('
            . implode(', ', $ddl_parts) . ')');
    $db->exec('INSERT INTO "' . SQLite3::escapeString($tmp_table) . '" '
            . '(' . $col_list . ') SELECT ' . $col_list
            . ' FROM "' . SQLite3::escapeString($table) . '"');
    $db->exec('DROP TABLE "' . SQLite3::escapeString($table) . '"');
    $db->exec('ALTER TABLE "' . SQLite3::escapeString($tmp_table)
            . '" RENAME TO "' . SQLite3::escapeString($table) . '"');

    if (!empty($saved_triggers) && function_exists('cow_install_parent_triggers')) {
        cow_install_parent_triggers($db, $table);
    }
    schema_recreate_dependent_cow_views($db, $dropped_views);
}

/** Copy DDL + rows from $src_table to $tgt_table (renaming table/index names). */
function db_copy_table(SQLite3 $db, string $src_table, string $tgt_table,
                       string $src_prefix, string $tgt_prefix): void {
    $old_ddl = $db->querySingle(
        "SELECT sql FROM sqlite_master WHERE type='table' AND name='"
        . SQLite3::escapeString($src_table) . "'"
    );
    if ($old_ddl) {
        $new_ddl = preg_replace(
            '/^(CREATE\s+TABLE\s+)(?:IF\s+NOT\s+EXISTS\s+)?"?'
            . preg_quote($src_table, '/') . '"?(\s*\()/is',
            '$1IF NOT EXISTS "' . $tgt_table . '"$2',
            $old_ddl, 1
        );
        $db->exec($new_ddl ?: "CREATE TABLE IF NOT EXISTS \"$tgt_table\" AS SELECT * FROM \"$src_table\"");
    } else {
        $db->exec("CREATE TABLE IF NOT EXISTS \"$tgt_table\" AS SELECT * FROM \"$src_table\"");
    }
    $db->exec("INSERT INTO \"$tgt_table\" SELECT * FROM \"$src_table\"");

    // Recreate named indexes with renamed table/index references.
    $idx_stmt = $db->prepare("SELECT name, sql FROM sqlite_master WHERE type='index' AND tbl_name=:t AND sql IS NOT NULL");
    $idx_stmt->bindValue(':t', $src_table, SQLITE3_TEXT);
    $idx_res  = $idx_stmt->execute();
    $idx_rows = [];
    while ($irow = $idx_res->fetchArray(SQLITE3_ASSOC)) $idx_rows[] = $irow;
    foreach ($idx_rows as $irow) {
        $old_idx = $irow['name'];
        $new_idx = str_replace($src_prefix, $tgt_prefix, $old_idx);
        $idx_sql = preg_replace(
            '/^(CREATE\s+(?:UNIQUE\s+)?INDEX\s+)(?:IF\s+NOT\s+EXISTS\s+)?"?' . preg_quote($old_idx, '/') . '"?/i',
            '$1IF NOT EXISTS "' . $new_idx . '"',
            $irow['sql'], 1
        );
        $idx_sql = preg_replace(
            '/\bON\s+"?' . preg_quote($src_table, '/') . '"?\s*\(/i',
            'ON "' . $tgt_table . '" (',
            $idx_sql, 1
        );
        @$db->exec($idx_sql);
    }
}

$src_id = merge_branch_id($db, $source);
$tgt_id = merge_branch_id($db, $target);
if (!$src_id) { fwrite(STDERR, "merge: source branch '$source' not found\n"); exit(1); }
if (!$tgt_id) { fwrite(STDERR, "merge: target branch '$target' not found\n"); exit(1); }

$base = merge_find_ancestor($db, $source, $target);
echo "Phase 1: file 3-way merge (fork parent: $base) ...\n";

$src_tree = merge_resolve_tree($db, $src_id);
$tgt_tree = merge_resolve_tree($db, $tgt_id);

// Base = the fork-time snapshot of the SOURCE branch. Fallback to the
// parent branch's current state only if no snapshot exists (dev / test
// shortcut when branchctl create wasn't used). Using TARGET's current
// state as base would silently turn every target edit since fork into
// "pre-existing base", masking real conflicts.
$base_tree = merge_load_fork_base($db, $src_id);
$base_source = 'source fs_commit';
if ($base_tree === null) {
    $parent_id = (int)$db->querySingle(
        "SELECT b2.id FROM branches b1 JOIN branches b2 ON b1.parent_branch = b2.name "
      . "WHERE b1.id = $src_id"
    );
    $base_tree = $parent_id ? merge_resolve_tree($db, $parent_id) : [];
    $base_source = 'parent-branch current state (no fs_commit on source)';
}
echo "  base tree: $base_source (" . count($base_tree) . " paths)\n";

$to_apply  = [];
$keep      = 0;
$noop      = 0;
$conflicts = [];

$all_paths = array_unique(array_merge(
    array_keys($src_tree), array_keys($tgt_tree), array_keys($base_tree)
));

foreach ($all_paths as $p) {
    $b = $base_tree[$p] ?? null;
    $s = $src_tree[$p]  ?? null;
    $t = $tgt_tree[$p]  ?? null;

    $bh = $b ? ($b['blob_hash'] ?? '') : '';
    $sh = $s ? ($s['blob_hash'] ?? '') : '';
    $th = $t ? ($t['blob_hash'] ?? '') : '';

    if ($sh === $th) { $noop++; continue; }
    if ($bh === $th && $sh !== $th) { $to_apply[$p] = $s; continue; }   // target unchanged, source changed
    if ($bh === $sh && $th !== $sh) { $keep++; continue; }              // source unchanged, target changed
    $conflicts[] = $p;                                                   // both diverged
}

echo "  paths considered:  " . count($all_paths) . "\n";
echo "  no-op (same):      $noop\n";
echo "  apply from source: " . count($to_apply) . "\n";
echo "  keep target:       $keep\n";
echo "  conflicts:         " . count($conflicts) . "\n";

if ($conflicts) {
    if ($strategy === 'abort') {
        echo "\nCONFLICTS (merge aborted; pass --strategy=ours or --strategy=theirs to override):\n";
        foreach (array_slice($conflicts, 0, 20) as $cpath) {
            echo "  - $cpath\n";
        }
        if (count($conflicts) > 20) {
            echo "  ... and " . (count($conflicts) - 20) . " more\n";
        }
        exit(2);
    } elseif ($strategy === 'theirs') {
        foreach ($conflicts as $p) $to_apply[$p] = $src_tree[$p] ?? null;
        echo "  resolving conflicts with 'theirs' (source wins on " . count($conflicts) . " paths)\n";
    } elseif ($strategy === 'ours') {
        // Target wins — nothing to apply from source for conflicts.
        echo "  resolving conflicts with 'ours' (target kept on " . count($conflicts) . " paths)\n";
    }
}

// Retry the whole file-side transaction on SQLITE_BUSY so a transient
// write lock from a parallel writer doesn't bubble up as a failed merge.
try {
    $applied = sqlite_retry_busy(function() use ($db, $to_apply, $tgt_id, $target) {
        $db->exec('BEGIN IMMEDIATE');
        try {
            $n = 0;
            $opcache_paths = [];
            foreach ($to_apply as $path => $entry) {
                if ($entry === null) {
                    $ins = $db->prepare(
                        "INSERT OR REPLACE INTO files (branch_id, path, blob_hash, mode, mtime, is_dir) "
                      . "VALUES (:b, :p, NULL, 0, :t, 0)"
                    );
                    $ins->bindValue(':b', $tgt_id, SQLITE3_INTEGER);
                    $ins->bindValue(':p', $path, SQLITE3_TEXT);
                    $ins->bindValue(':t', time(), SQLITE3_INTEGER);
                    $ins->execute();
                    $opcache_paths[] = $path;
                    $n++;
                    continue;
                }
                $ins = $db->prepare(
                    "INSERT OR REPLACE INTO files (branch_id, path, blob_hash, mode, mtime, is_dir) "
                  . "VALUES (:b, :p, :h, :m, :mt, :d)"
                );
                $ins->bindValue(':b',  $tgt_id, SQLITE3_INTEGER);
                $ins->bindValue(':p',  $path, SQLITE3_TEXT);
                $ins->bindValue(':h',  $entry['blob_hash'] ?? null,
                    $entry['blob_hash'] ? SQLITE3_TEXT : SQLITE3_NULL);
                $ins->bindValue(':m',  (int)($entry['mode']   ?? 0),   SQLITE3_INTEGER);
                $ins->bindValue(':mt', (int)($entry['mtime']  ?? 0),   SQLITE3_INTEGER);
                $ins->bindValue(':d',  (int)($entry['is_dir'] ?? 0),   SQLITE3_INTEGER);
                $ins->execute();
                if (empty($entry['is_dir'])) $opcache_paths[] = $path;
                $n++;
            }
            foreach ($opcache_paths as $p) {
                opcache_queue_invalidate($db, $target, $p);
            }
            $db->exec('COMMIT');
            return $n;
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
    });
    echo "  applied: $applied rows\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "merge: file-side failed: " . $e->getMessage() . "\n");
    exit(4);
}
// ── Phase 2: DB 3-way merge ───────────────────────────────────────────────────
echo "\nPhase 2: DB 3-way merge ...\n";

// Ensure db_snapshots exists (idempotent; handles DBs created before this schema).
$db->exec("CREATE TABLE IF NOT EXISTS db_snapshots (
    branch_id  INTEGER NOT NULL,
    table_name TEXT NOT NULL,
    row_pk     TEXT NOT NULL,
    row_json   TEXT NOT NULL,
    PRIMARY KEY (branch_id, table_name, row_pk)
)");

// Ensure db_snapshots_schema exists. Fork-time schema is the ancestor for
// column-level 3-way diff. Branches that pre-date this table fall back to
// "source's CURRENT schema is the ancestor" (i.e. assume no schema changed
// on the source) — see merge_load_schema_ancestor() below.
$db->exec("CREATE TABLE IF NOT EXISTS db_snapshots_schema (
    branch_id    INTEGER NOT NULL,
    table_name   TEXT NOT NULL,
    ddl_sql      TEXT NOT NULL,
    indexes_json TEXT NOT NULL DEFAULT '[]',
    PRIMARY KEY (branch_id, table_name)
)");

$src_prefix = "b{$src_id}_wp_";
$tgt_prefix = "b{$tgt_id}_wp_";

/** Load (ddl, indexes_array) for the ancestor schema of $tname under
 *  $branch_id. Returns null if no snapshot row exists — caller decides
 *  fallback. */
$merge_load_schema_ancestor = function (SQLite3 $db, int $branch_id, string $tname): ?array {
    $st = $db->prepare("SELECT ddl_sql, indexes_json FROM db_snapshots_schema WHERE branch_id=:b AND table_name=:t");
    $st->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $st->bindValue(':t', $tname, SQLITE3_TEXT);
    $r = $st->execute();
    $row = $r->fetchArray(SQLITE3_ASSOC);
    if (!$row) return null;
    $idx = json_decode((string)$row['indexes_json'], true);
    if (!is_array($idx)) $idx = [];
    return ['ddl' => (string)$row['ddl_sql'], 'indexes' => $idx];
};

// Opportunistic backfill of db_snapshots_schema for any existing branch that
// has live tables but no schema snapshot rows yet. Best-effort: any errors
// here are non-fatal because the merge can still fall back to "current schema
// as ancestor" for branches without a snapshot.
{
    $branches = [];
    $br = $db->query("SELECT id FROM branches");
    while ($brow = $br->fetchArray(SQLITE3_NUM)) $branches[] = (int)$brow[0];
    foreach ($branches as $bid) {
        $has_any = (int)$db->querySingle(
            "SELECT COUNT(*) FROM db_snapshots_schema WHERE branch_id = $bid"
        );
        if ($has_any > 0) continue;
        $bprefix = "b{$bid}_wp_";
        $tnames = [];
        $tr = $db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '"
            . SQLite3::escapeString($bprefix) . "%' "
            . "AND name NOT LIKE '%\\_\\_overlay' ESCAPE '\\' "
            . "AND name NOT LIKE '%\\_\\_tombstones' ESCAPE '\\'"
        );
        while ($trow = $tr->fetchArray(SQLITE3_NUM)) $tnames[] = $trow[0];
        if (empty($tnames)) continue;
        try {
            $st = $db->prepare(
                "INSERT OR REPLACE INTO db_snapshots_schema "
              . "(branch_id, table_name, ddl_sql, indexes_json) "
              . "VALUES (:b, :t, :d, :i)"
            );
            foreach ($tnames as $tname) {
                $ddl = (string)$db->querySingle(
                    "SELECT sql FROM sqlite_master WHERE type='table' AND name='"
                    . SQLite3::escapeString($tname) . "'"
                );
                $idxs = [];
                $ir = $db->query(
                    "SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='"
                    . SQLite3::escapeString($tname) . "' AND sql IS NOT NULL"
                );
                while ($irow = $ir->fetchArray(SQLITE3_NUM)) $idxs[] = $irow[0];
                $st->bindValue(':b', $bid, SQLITE3_INTEGER);
                $st->bindValue(':t', $tname, SQLITE3_TEXT);
                $st->bindValue(':d', $ddl, SQLITE3_TEXT);
                $st->bindValue(':i', json_encode($idxs, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
                $st->execute();
                $st->reset();
            }
        } catch (\Throwable $e) {
            // Non-fatal — merge will still work via the current-schema fallback.
        }
    }
}

// Collect table suffixes present in source, target, and ancestor snapshot.
// Under COW, b{id}_wp_* on a non-main branch is a view, not a table — read
// from both. Skip overlay/tombstone artifact names so they don't appear
// as merge candidates.
$src_tables = [];
$r = $db->query("SELECT name FROM sqlite_master WHERE type IN ('table','view') AND name LIKE '"
    . SQLite3::escapeString($src_prefix) . "%' "
    . "AND name NOT LIKE '%\\_\\_overlay' ESCAPE '\\' "
    . "AND name NOT LIKE '%\\_\\_tombstones' ESCAPE '\\'");
while ($row = $r->fetchArray(SQLITE3_NUM)) {
    $src_tables[substr($row[0], strlen($src_prefix))] = $row[0];
}

$tgt_tables = [];
$r = $db->query("SELECT name FROM sqlite_master WHERE type IN ('table','view') AND name LIKE '"
    . SQLite3::escapeString($tgt_prefix) . "%' "
    . "AND name NOT LIKE '%\\_\\_overlay' ESCAPE '\\' "
    . "AND name NOT LIKE '%\\_\\_tombstones' ESCAPE '\\'");
while ($row = $r->fetchArray(SQLITE3_NUM)) {
    $tgt_tables[substr($row[0], strlen($tgt_prefix))] = $row[0];
}

$anc_tables = [];
$r = $db->query("SELECT DISTINCT table_name FROM db_snapshots WHERE branch_id = $src_id");
while ($row = $r->fetchArray(SQLITE3_NUM)) {
    $anc_tables[substr($row[0], strlen($src_prefix))] = $row[0];
}

// Detect whether source is a COW branch — ancestor lookup falls through to
// db_ancestor_rows_cow() (parent-current-state-based) instead of db_snapshots.
$src_is_cow = branch_is_cow($db, $src_id);

// Under COW, db_snapshots is empty; treat every INHERITED (i.e. tracked in
// db_cow_branches) suffix as "ancestor present" so the row-level walk runs
// against db_ancestor_rows_cow(). Suffixes that exist on the source but were
// NOT inherited from the parent (e.g. a brand-new plugin table created on
// the branch) stay out of $anc_tables — they are correctly treated as
// "new table on source".
if ($src_is_cow) {
    $cow_inherited_suffixes = [];
    $rs = $db->query("SELECT table_suffix FROM db_cow_branches WHERE branch_id = $src_id");
    while ($crow = $rs->fetchArray(SQLITE3_NUM)) {
        $cow_inherited_suffixes[(string)$crow[0]] = true;
    }
    foreach (array_keys($src_tables) as $sfx) {
        if (isset($cow_inherited_suffixes[$sfx]) && !isset($anc_tables[$sfx])) {
            $anc_tables[$sfx] = $src_prefix . $sfx;
        }
    }
}

$all_suffixes = array_unique(array_merge(
    array_keys($src_tables), array_keys($tgt_tables), array_keys($anc_tables)
));

$db_clean_ops    = [];
$db_conflict_ops = [];
$db_noop         = 0;
$db_inserted     = 0;
$db_updated      = 0;
$db_deleted      = 0;

// --on-id-collision=renumber state:
//   $renumber_map[$suffix] = [ $old_id => $new_id, ... ]
//   $renumber_log          = [ ['suffix', 'pk_col', $old, $new], ... ]
//   $renumber_next_id      = per-suffix next free ID, pre-allocated by inspecting
//                            MAX(src_pk), MAX(tgt_pk) at the start of each table walk.
$renumber_map     = [];
$renumber_log     = [];
$renumber_next_id = [];
// TODO3 #9: the hard-coded WP FK graph stays as the fallback, but we
// union it with FK edges discovered at runtime via PRAGMA foreign_key_list
// on the source and target overlay tables. That way plugin tables with
// real FK declarations (e.g. ACF's group_id → acf_groups.id) get their
// references renumbered automatically — without pre-registering them
// in merge_fk_map().
$FK_MAP           = merge_union_fk_maps(
    merge_fk_map(),
    merge_union_fk_maps(
        merge_discover_fk_map($db, $src_prefix),
        merge_discover_fk_map($db, $tgt_prefix)
    )
);

foreach ($all_suffixes as $suffix) {
    $src_tname = $src_tables[$suffix] ?? null;
    $tgt_tname = $tgt_tables[$suffix] ?? null;
    $anc_tname = $anc_tables[$suffix] ?? null;

    $in_src = $src_tname !== null;
    $in_tgt = $tgt_tname !== null;
    $in_anc = $anc_tname !== null;

    // Table only in target (target added it independently): no-op.
    if (!$in_src && !$in_anc) { $db_noop++; continue; }

    // New table on source, not in target and not in ancestor: copy to target.
    if ($in_src && !$in_tgt && !$in_anc) {
        $db_clean_ops[] = ['type' => 'new_table', 'src' => $src_tname,
                           'tgt' => $tgt_prefix . $suffix];
        continue;
    }

    // Source deleted the table (was in ancestor, no longer in source).
    if ($in_anc && !$in_src) {
        if (!$in_tgt) { $db_noop++; continue; } // both deleted → noop
        if ($src_is_cow) {
            // For COW branches, we don't track per-table "deletion" — a missing
            // suffix means it never existed, never that source dropped it.
            $db_noop++;
            continue;
        }
        $anc_rows = db_ancestor_rows($db, $src_id, $anc_tname);
        $tgt_pk   = db_pk_cols($db, $tgt_tname);
        $tgt_rows = db_table_rows($db, $tgt_tname, $tgt_pk);
        if ($anc_rows === $tgt_rows) {
            $db_clean_ops[] = ['type' => 'drop_table', 'tgt' => $tgt_tname];
        } else {
            $db_conflict_ops[] = [
                'desc'      => "table $suffix (source deleted, target modified)",
                'ours_op'   => null,
                'theirs_op' => ['type' => 'drop_table', 'tgt' => $tgt_tname],
            ];
        }
        continue;
    }

    // Source has it, target deleted it, was in ancestor.
    if ($in_src && !$in_tgt && $in_anc) {
        $src_pk   = db_pk_cols($db, $src_tname);
        $src_rows = db_table_rows($db, $src_tname, $src_pk);
        if ($src_is_cow) {
            $anc_rows = db_ancestor_rows_cow($db, $src_id, $src_tname, $src_pk, $suffix);
        } else {
            $anc_rows = db_ancestor_rows($db, $src_id, $anc_tname);
        }
        if ($src_rows === $anc_rows) {
            $db_noop++; // source unchanged; target's deletion wins
        } else {
            $db_conflict_ops[] = [
                'desc'      => "table $suffix (source modified, target deleted table)",
                'ours_op'   => null,
                'theirs_op' => ['type' => 'new_table', 'src' => $src_tname,
                                'tgt' => $tgt_prefix . $suffix],
            ];
        }
        continue;
    }

    // Row-level 3-way merge for tables present in both source and target.
    if ($in_src && $in_tgt) {
        // ── Schema-level 3-way diff (must run BEFORE row-level so any
        // ADD COLUMN happens before we INSERT a source row into target).
        // For COW source ($src_tname is a view), schema_columns_from_pragma
        // works on the view directly (PRAGMA returns the view's column
        // shape) but the source DDL must come from the underlying overlay
        // table (the view's `CREATE VIEW … SELECT …` text isn't a
        // CREATE TABLE statement).
        $src_cols = schema_columns_from_pragma($db, $src_tname);
        $tgt_cols = schema_columns_from_pragma($db, $tgt_tname);
        $src_idxs = schema_indexes_from_master($db, $src_tname);
        $tgt_idxs = schema_indexes_from_master($db, $tgt_tname);

        $src_ddl_source_tbl = $src_tname;
        $src_type = (string)$db->querySingle(
            "SELECT type FROM sqlite_master WHERE name='"
            . SQLite3::escapeString($src_tname) . "'"
        );
        if ($src_type === 'view') {
            $src_ddl_source_tbl = $src_tname . '__overlay';
            // Read indexes from the overlay too, then rewrite their
            // attached-table reference to the LOGICAL name so the merge's
            // generic prefix-rename machinery works downstream.
            $src_idxs = schema_indexes_from_master($db, $src_ddl_source_tbl);
            foreach ($src_idxs as &$_ix) {
                $_ix['sql'] = preg_replace(
                    '/\bON\s+"?' . preg_quote($src_ddl_source_tbl, '/') . '"?\s*\(/i',
                    'ON "' . $src_tname . '" (',
                    $_ix['sql'], 1
                );
            }
            unset($_ix);
        }
        $src_ddl_now_raw = (string)$db->querySingle(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='"
            . SQLite3::escapeString($src_ddl_source_tbl) . "'"
        );
        // Rewrite the overlay's DDL so its embedded CREATE TABLE name
        // matches $src_tname (the view's logical name) — schema_extract_column_def
        // and friends key off this name.
        $src_ddl_now = preg_replace(
            '/^(CREATE\s+TABLE\s+)(?:IF\s+NOT\s+EXISTS\s+)?"?'
            . preg_quote($src_ddl_source_tbl, '/') . '"?/is',
            '$1IF NOT EXISTS "' . $src_tname . '"',
            $src_ddl_now_raw, 1
        );
        if ($src_ddl_now === null || $src_ddl_now === '') $src_ddl_now = $src_ddl_now_raw;

        $anc_schema = $merge_load_schema_ancestor($db, $src_id, $src_tname);
        if ($anc_schema === null) {
            // Backward compat: no schema snapshot for this branch (legacy
            // branch created before db_snapshots_schema existed). Treat
            // source's CURRENT schema as the ancestor — i.e. assume no
            // schema change happened on source.
            $anc_cols = $src_cols;
            $anc_idxs = $src_idxs;
        } else {
            $anc_cols = schema_columns_from_ddl($anc_schema['ddl'], $src_tname);
            $anc_idxs = [];
            foreach ($anc_schema['indexes'] as $isql) {
                if (preg_match('/^\s*CREATE\s+(?:UNIQUE\s+)?INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?"?([^"\s(]+)"?/i', $isql, $im)) {
                    $anc_idxs[] = ['name' => $im[1], 'sql' => $isql];
                }
            }
        }

        $sd = schema_diff_table(
            $anc_cols, $anc_idxs,
            $src_cols, $src_idxs,
            $tgt_cols, $tgt_idxs,
            $src_prefix, $tgt_prefix,
            $src_ddl_now, $tgt_tname
        );
        foreach ($sd['ops'] as $sop) {
            $db_clean_ops[] = ['type' => 'schema', 'op' => $sop];
        }
        foreach ($sd['conflicts'] as $sc) {
            $db_conflict_ops[] = [
                'desc'      => $sc['desc'],
                'ours_op'   => $sc['ours_op'] !== null
                                ? ['type' => 'schema', 'op' => $sc['ours_op']]
                                : null,
                'theirs_op' => $sc['theirs_op'] !== null
                                ? ['type' => 'schema', 'op' => $sc['theirs_op']]
                                : null,
            ];
        }

        $src_pk   = db_pk_cols($db, $src_tname);
        $tgt_pk   = db_pk_cols($db, $tgt_tname);
        $src_rows = db_table_rows($db, $src_tname, $src_pk);
        $tgt_rows = db_table_rows($db, $tgt_tname, $tgt_pk);
        if ($src_is_cow) {
            // COW source: ancestor for divergent rows = captured fork-time
            // value (or parent's current as fallback). For non-divergent
            // rows that nevertheless differ src vs tgt (e.g. because the
            // branch ALTERed schema), populate ancestor as parent's current
            // row — that lets the row walk compute "src changed, target
            // unchanged → upsert source" instead of falsely flagging
            // "both inserted different rows".
            $anc_rows = db_ancestor_rows_cow($db, $src_id, $src_tname, $src_pk, $suffix);
            $anc_rows = db_ancestor_fill_for_diff(
                $db, $src_id, $suffix, $src_pk, $src_rows, $tgt_rows, $anc_rows,
                $src_tname
            );
        } else {
            $anc_rows = $in_anc ? db_ancestor_rows($db, $src_id, $anc_tname) : [];
        }

        $all_pks = array_unique(array_merge(
            array_keys($src_rows), array_keys($tgt_rows), array_keys($anc_rows)
        ));

        // Renumber eligibility for this table (suffix): only auto-inc single
        // INTEGER PK tables whose suffix is in $FK_MAP.
        $renumber_eligible =
            $on_id_collision === 'renumber'
            && count($src_pk) === 1
            && isset($FK_MAP[$suffix])
            && merge_table_is_autoinc_pk($db, $src_tname)
            && merge_table_is_autoinc_pk($db, $tgt_tname);

        if ($renumber_eligible && !isset($renumber_next_id[$suffix])) {
            $pk_col = $src_pk[0];
            $smax = merge_table_max_pk($db, $src_tname, $pk_col);
            $tmax = merge_table_max_pk($db, $tgt_tname, $pk_col);
            $renumber_next_id[$suffix] = max($smax, $tmax) + 1;
        }

        foreach ($all_pks as $pk) {
            $a = $anc_rows[$pk] ?? null;
            $s = $src_rows[$pk] ?? null;
            $t = $tgt_rows[$pk] ?? null;

            if ($s === $t) { $db_noop++; continue; }

            if ($a === null) {
                if ($s !== null && $t === null) {
                    $db_clean_ops[] = ['type' => 'upsert', 'table' => $tgt_tname, 'row_json' => $s,
                                       'suffix' => $suffix];
                    $db_inserted++;
                } elseif ($s !== null && $t !== null) {
                    // Collision: both sides independently inserted different
                    // rows with the same PK. If renumber is requested AND the
                    // table is autoinc + in the FK map, allocate a new PK for
                    // source's row and queue an upsert with the rewritten PK.
                    if ($renumber_eligible) {
                        $pk_col = $src_pk[0];
                        $pk_map = json_decode($pk, true);
                        $old_id = isset($pk_map[$pk_col]) ? (int)$pk_map[$pk_col] : 0;
                        if ($old_id > 0) {
                            $new_id = $renumber_next_id[$suffix]++;
                            $renumber_map[$suffix][$old_id] = $new_id;
                            $renumber_log[] = [$tgt_tname, $pk_col, $old_id, $new_id];
                            $renumbered_row = merge_row_with_pk($s, $pk_col, $new_id);
                            $db_clean_ops[] = [
                                'type'     => 'upsert',
                                'table'    => $tgt_tname,
                                'row_json' => $renumbered_row,
                                'suffix'   => $suffix,
                            ];
                            $db_inserted++;
                            continue;
                        }
                    }
                    $db_conflict_ops[] = [
                        'desc'      => "$tgt_tname pk=$pk (both inserted different rows)",
                        'ours_op'   => null,
                        'theirs_op' => ['type' => 'upsert', 'table' => $tgt_tname, 'row_json' => $s,
                                        'suffix' => $suffix],
                    ];
                }
                // $s===null && $t!==null: target added it, source doesn't have it → keep (noop)
                continue;
            }

            if ($s === null) {
                if ($t === $a) {
                    $db_clean_ops[] = ['type' => 'delete', 'table' => $tgt_tname,
                                       'pk' => $pk, 'pk_cols' => $tgt_pk];
                    $db_deleted++;
                } else {
                    $db_conflict_ops[] = [
                        'desc'      => "$tgt_tname pk=$pk (source deleted, target modified)",
                        'ours_op'   => null,
                        'theirs_op' => ['type' => 'delete', 'table' => $tgt_tname,
                                        'pk' => $pk, 'pk_cols' => $tgt_pk],
                    ];
                }
                continue;
            }

            // Both present (s != t), ancestor exists.
            if ($s === $a) {
                $db_noop++; // source unchanged, target changed → keep target
            } elseif ($t === $a) {
                $db_clean_ops[] = ['type' => 'upsert', 'table' => $tgt_tname, 'row_json' => $s,
                                   'suffix' => $suffix];
                $db_updated++;
            } else {
                $db_conflict_ops[] = [
                    'desc'      => "$tgt_tname pk=$pk (both modified)",
                    'ours_op'   => null,
                    'theirs_op' => ['type' => 'upsert', 'table' => $tgt_tname, 'row_json' => $s,
                                    'suffix' => $suffix],
                ];
            }
        }
    }
}

$schema_op_count = 0;
foreach ($db_clean_ops as $cop) {
    if (($cop['type'] ?? '') === 'schema') $schema_op_count++;
}

echo "  tables considered: " . count($all_suffixes) . "\n";
echo "  row no-op:         $db_noop\n";
echo "  row inserts:       $db_inserted\n";
echo "  row updates:       $db_updated\n";
echo "  row deletes:       $db_deleted\n";
echo "  schema ops:        $schema_op_count\n";
echo "  id renumbers:      " . count($renumber_log) . "\n";
echo "  conflicts:         " . count($db_conflict_ops) . "\n";

if (!empty($renumber_log)) {
    echo "\nID collision renumbers (" . count($renumber_log) . "):\n";
    foreach ($renumber_log as $entry) {
        [$tname, $pk_col, $old_id, $new_id] = $entry;
        echo "  " . str_pad($tname . '.' . $pk_col, 36) . " $old_id -> $new_id\n";
    }
    echo "\n";
}

if ($db_conflict_ops && $strategy === 'abort') {
    echo "\nDB CONFLICTS (merge aborted; pass --strategy=ours or --strategy=theirs to override):\n";
    foreach (array_slice($db_conflict_ops, 0, 20) as $c) {
        echo "  - " . $c['desc'] . "\n";
    }
    if (count($db_conflict_ops) > 20) {
        echo "  ... and " . (count($db_conflict_ops) - 20) . " more\n";
    }
    $db->close();
    exit(2);
}

// Resolve conflicts per strategy.
$final_db_ops = $db_clean_ops;
// For COW: track PKs whose conflicts were resolved via "ours" so we can
// stamp db_ancestor_overlay with source's current row. That way the next
// merge sees s == a → noop, preserving the user's "ours" decision across
// iterative merges (the legacy code achieved this via db_snapshots refresh).
$ours_resolved_pks = []; // [tgt_table => [pk_json => true]]
if ($strategy === 'theirs') {
    foreach ($db_conflict_ops as $c) {
        if ($c['theirs_op'] !== null) $final_db_ops[] = $c['theirs_op'];
    }
} elseif ($strategy === 'ours') {
    foreach ($db_conflict_ops as $c) {
        if ($c['ours_op'] !== null) $final_db_ops[] = $c['ours_op'];
        // Record PKs from "both modified" / "both inserted" / "delete vs modify"
        // conflicts so we can refresh COW ancestor for them post-merge.
        if (isset($c['theirs_op']) && is_array($c['theirs_op'])
            && in_array($c['theirs_op']['type'] ?? '', ['upsert', 'delete'], true)
            && isset($c['theirs_op']['table'])) {
            $tname = $c['theirs_op']['table'];
            if (isset($c['theirs_op']['pk'])) {
                $ours_resolved_pks[$tname][$c['theirs_op']['pk']] = true;
            } elseif (isset($c['theirs_op']['row_json'])) {
                // Reconstruct PK from row_json + table's PK cols.
                $pkc = db_pk_cols($db, $tname);
                $rj  = json_decode($c['theirs_op']['row_json'], true);
                if (is_array($rj) && !empty($pkc)) {
                    $pkmap = [];
                    foreach ($pkc as $col) $pkmap[$col] = $rj[$col] ?? null;
                    $ours_resolved_pks[$tname][json_encode($pkmap, JSON_UNESCAPED_UNICODE)] = true;
                }
            }
        }
    }
}

// Pass 2 of the two-pass renumber: rewrite FK columns in source-origin rows
// that are about to be upserted into FK-bearing tables. For a renumbered
// wp_posts.ID 42 → 157, source's wp_postmeta rows with post_id=42 now need
// post_id=157 before the upsert — otherwise they'd either overwrite target's
// unrelated meta for post 42 or float free.
//
// Critical scoping: only rewrite source-origin upserts. Target-origin rows
// never collide on the renumbered IDs (we just picked IDs beyond both sides'
// max), so rewriting their FK columns is a no-op in practice — but we only
// have source-origin rows in $final_db_ops for upsert anyway, since the
// row_level walk either keeps target unchanged (noop) or upserts source's
// row_json. So it's safe to walk every upsert here.
if (!empty($renumber_map)) {
    // Build: fk_table_suffix => [ [fk_col, map_ref], ... ]
    $fk_rewrites_by_suffix = [];
    foreach ($renumber_map as $pk_suffix => $map) {
        if (empty($map)) continue;
        if (!isset($FK_MAP[$pk_suffix])) continue;
        foreach ($FK_MAP[$pk_suffix] as $fk_entry) {
            [$fk_table_suffix, $fk_col] = $fk_entry;
            $fk_rewrites_by_suffix[$fk_table_suffix][] = [$fk_col, $map];
        }
    }
    foreach ($final_db_ops as &$op) {
        if ($op['type'] !== 'upsert') continue;
        $sfx = $op['suffix'] ?? null;
        if ($sfx === null) continue;
        if (!isset($fk_rewrites_by_suffix[$sfx])) continue;
        $op['row_json'] = merge_rewrite_row_fks($op['row_json'], $fk_rewrites_by_suffix[$sfx]);
    }
    unset($op);
}

// Apply DB ops AND refresh the source branch's ancestor snapshot in a
// single transaction. Refreshing the snapshot is how iterative merges
// stay clean: without it, rows that the last merge already propagated
// would look like "both sides changed vs (stale) ancestor" on the next
// merge, producing spurious conflicts on every previously-merged row.
//
// The refresh captures source's current rows as the new common ancestor.
// Because source branches are never modified by merge itself, this is
// equivalent to "the state target adopted for rows where it took source,
// and source's own state for rows where target's value prevailed" —
// which correctly drives future 3-way diffs for both --strategy=theirs
// (source adopted) and --strategy=ours (target kept its value;
// re-merge will see source unchanged vs ancestor, target changed vs
// ancestor → keep target, no re-conflict).
// Sort schema ops to the front so column ADD/DROP/MODIFY happens before any
// row INSERT into the (possibly new-shaped) target table.
usort($final_db_ops, function($a, $b) {
    $rank = function($op) {
        if ($op['type'] === 'schema') {
            // Among schema ops: drop_index → modify/drop column → add column → add_index
            $sub = $op['op']['type'] ?? '';
            return match ($sub) {
                'drop_index'    => 0,
                'drop_column'   => 1,
                'modify_column' => 2,
                'add_column'    => 3,
                'add_index'     => 4,
                default         => 5,
            };
        }
        if ($op['type'] === 'new_table') return 6;
        if ($op['type'] === 'drop_table') return 7;
        return 8; // upsert / delete (row-level) last
    };
    return $rank($a) <=> $rank($b);
});

try {
    [$db_applied, $snap_total, $schema_total] = sqlite_retry_busy(
        function() use ($db, $final_db_ops, $src_id, $src_prefix, $tgt_prefix,
                       $src_is_cow, $ours_resolved_pks) {
            $db->exec('BEGIN IMMEDIATE');
            try {
                $db_applied = 0;
                foreach ($final_db_ops as $op) {
                    switch ($op['type']) {
                        case 'upsert':
                            db_upsert($db, $op['table'], $op['row_json']);
                            break;
                        case 'delete':
                            db_delete_by_pk($db, $op['table'], $op['pk'], $op['pk_cols']);
                            break;
                        case 'new_table':
                            db_copy_table($db, $op['src'], $op['tgt'], $src_prefix, $tgt_prefix);
                            break;
                        case 'drop_table':
                            $db->exec('DROP TABLE IF EXISTS "'
                                . SQLite3::escapeString($op['tgt']) . '"');
                            break;
                        case 'schema':
                            schema_apply_op($db, $op['op'], $src_prefix, $tgt_prefix);
                            break;
                    }
                    $db_applied++;
                }

                // Refresh source branch's ancestor snapshot (rows + schema).
                $db->exec('DELETE FROM db_snapshots WHERE branch_id = ' . (int)$src_id);
                $db->exec('DELETE FROM db_snapshots_schema WHERE branch_id = ' . (int)$src_id);

                if ($src_is_cow) {
                    // COW source: refresh db_ancestor_overlay for PKs we
                    // just propagated from source to target (upserts). Without
                    // this, the next merge sees a stale ancestor (the value
                    // captured BEFORE the row was merged) and falsely
                    // conflicts on rows the user already merged. This is the
                    // COW analog of the legacy db_snapshots-refresh fix.
                    $merge_synced_pks = []; // [tgt_table => [pk_json => true]]
                    foreach ($final_db_ops as $op) {
                        if (($op['type'] ?? '') !== 'upsert') continue;
                        if (empty($op['table']) || empty($op['row_json'])) continue;
                        $pkc = db_pk_cols($db, $op['table']);
                        if (empty($pkc)) continue;
                        $rj = json_decode($op['row_json'], true);
                        if (!is_array($rj)) continue;
                        $pkmap = [];
                        foreach ($pkc as $col) $pkmap[$col] = $rj[$col] ?? null;
                        $merge_synced_pks[$op['table']][json_encode($pkmap, JSON_UNESCAPED_UNICODE)] = true;
                    }
                    if (!empty($merge_synced_pks)) {
                        $upsert_anc = $db->prepare(
                            "INSERT OR REPLACE INTO db_ancestor_overlay "
                          . "(branch_id, table_name, row_pk, row_json) "
                          . "VALUES (:b, :t, :pk, :rj)"
                        );
                        foreach ($merge_synced_pks as $tgt_table => $pks) {
                            // Map target table to source's logical name.
                            $suffix = '';
                            if (preg_match('/^' . preg_quote($tgt_prefix, '/') . '(.*)$/', $tgt_table, $m)) {
                                $suffix = $m[1];
                            }
                            if ($suffix === '') continue;
                            $src_table = $src_prefix . $suffix;
                            $pkc = db_pk_cols($db, $src_table);
                            if (empty($pkc)) continue;
                            $where = implode(' AND ',
                                array_map(fn($c) => '"' . $c . '" = :' . $c, $pkc));
                            $sel = $db->prepare(
                                'SELECT * FROM "' . SQLite3::escapeString($src_table)
                              . '" WHERE ' . $where
                            );
                            foreach (array_keys($pks) as $pk_json) {
                                $pk_map = json_decode($pk_json, true);
                                if (!is_array($pk_map)) continue;
                                $sel->reset();
                                foreach ($pkc as $col) {
                                    $v = $pk_map[$col] ?? null;
                                    $type = match (true) {
                                        $v === null  => SQLITE3_NULL,
                                        is_int($v)   => SQLITE3_INTEGER,
                                        is_float($v) => SQLITE3_FLOAT,
                                        default      => SQLITE3_TEXT,
                                    };
                                    $sel->bindValue(':' . $col, $v, $type);
                                }
                                $rr = $sel->execute();
                                $srow = $rr->fetchArray(SQLITE3_ASSOC);
                                $rr->finalize();
                                if ($srow === false || $srow === null) continue;
                                $upsert_anc->bindValue(':b',  $src_id,    SQLITE3_INTEGER);
                                $upsert_anc->bindValue(':t',  $src_table, SQLITE3_TEXT);
                                $upsert_anc->bindValue(':pk', $pk_json,   SQLITE3_TEXT);
                                $upsert_anc->bindValue(':rj',
                                    json_encode($srow, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
                                $upsert_anc->execute();
                                $upsert_anc->reset();
                            }
                        }
                    }

                    // Also: for rows whose conflict was resolved via
                    // --strategy=ours (target kept its value), record source's
                    // CURRENT row in db_ancestor_overlay so the next merge
                    // sees s == a → noop (preserves the user's "ours"
                    // decision across iterative merges).
                    if (!empty($ours_resolved_pks)) {
                        $upsert_anc = $db->prepare(
                            "INSERT OR REPLACE INTO db_ancestor_overlay "
                          . "(branch_id, table_name, row_pk, row_json) "
                          . "VALUES (:b, :t, :pk, :rj)"
                        );
                        foreach ($ours_resolved_pks as $tgt_table => $pks) {
                            // Map target table back to source's logical name.
                            // tgt_table is "b{tgt_id}_wp_X"; source's logical
                            // is "b{src_id}_wp_X".
                            $suffix = '';
                            if (preg_match('/^' . preg_quote($tgt_prefix, '/') . '(.*)$/', $tgt_table, $m)) {
                                $suffix = $m[1];
                            }
                            if ($suffix === '') continue;
                            $src_table = $src_prefix . $suffix;
                            $pkc = db_pk_cols($db, $src_table);
                            if (empty($pkc)) continue;
                            // Lookup source's CURRENT row at each PK
                            $where = implode(' AND ',
                                array_map(fn($c) => '"' . $c . '" = :' . $c, $pkc));
                            $sel = $db->prepare(
                                'SELECT * FROM "' . SQLite3::escapeString($src_table)
                              . '" WHERE ' . $where
                            );
                            foreach (array_keys($pks) as $pk_json) {
                                $pk_map = json_decode($pk_json, true);
                                if (!is_array($pk_map)) continue;
                                $sel->reset();
                                foreach ($pkc as $col) {
                                    $v = $pk_map[$col] ?? null;
                                    $type = match (true) {
                                        $v === null  => SQLITE3_NULL,
                                        is_int($v)   => SQLITE3_INTEGER,
                                        is_float($v) => SQLITE3_FLOAT,
                                        default      => SQLITE3_TEXT,
                                    };
                                    $sel->bindValue(':' . $col, $v, $type);
                                }
                                $rr = $sel->execute();
                                $srow = $rr->fetchArray(SQLITE3_ASSOC);
                                $rr->finalize();
                                if ($srow === false || $srow === null) continue;
                                $upsert_anc->bindValue(':b',  $src_id,    SQLITE3_INTEGER);
                                $upsert_anc->bindValue(':t',  $src_table, SQLITE3_TEXT);
                                $upsert_anc->bindValue(':pk', $pk_json,   SQLITE3_TEXT);
                                $upsert_anc->bindValue(':rj',
                                    json_encode($srow, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
                                $upsert_anc->execute();
                                $upsert_anc->reset();
                            }
                        }
                    }
                    $db->exec('COMMIT');
                    return [$db_applied, 0, 0];
                }

                $snap_ins = $db->prepare(
                    "INSERT OR REPLACE INTO db_snapshots (branch_id, table_name, row_pk, row_json) "
                  . "VALUES (:bid, :tname, :rpk, :rjson)"
                );
                $schema_ins = $db->prepare(
                    "INSERT OR REPLACE INTO db_snapshots_schema "
                  . "(branch_id, table_name, ddl_sql, indexes_json) "
                  . "VALUES (:bid, :tname, :ddl, :idx)"
                );

                $snap_tables = [];
                $tr = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '"
                    . SQLite3::escapeString($src_prefix) . "%'");
                while ($row = $tr->fetchArray(SQLITE3_NUM)) {
                    $snap_tables[] = $row[0];
                }

                $snap_total = 0;
                $schema_total = 0;
                foreach ($snap_tables as $tname) {
                    $pk_cols = db_pk_cols($db, $tname);
                    $rows = $db->query('SELECT * FROM "' . SQLite3::escapeString($tname) . '"');
                    while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
                        if (empty($pk_cols)) {
                            $pk_map = $row;
                        } else {
                            $pk_map = [];
                            foreach ($pk_cols as $col) $pk_map[$col] = $row[$col] ?? null;
                        }
                        $snap_ins->bindValue(':bid',   $src_id, SQLITE3_INTEGER);
                        $snap_ins->bindValue(':tname', $tname,  SQLITE3_TEXT);
                        $snap_ins->bindValue(':rpk',   json_encode($pk_map, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
                        $snap_ins->bindValue(':rjson', json_encode($row,    JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
                        $snap_ins->execute();
                        $snap_ins->reset();
                        $snap_total++;
                    }

                    // Snapshot the (possibly modified) source schema too.
                    $ddl = (string)$db->querySingle(
                        "SELECT sql FROM sqlite_master WHERE type='table' AND name='"
                        . SQLite3::escapeString($tname) . "'"
                    );
                    $idxs = [];
                    $ir = $db->query(
                        "SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='"
                        . SQLite3::escapeString($tname) . "' AND sql IS NOT NULL"
                    );
                    while ($irow = $ir->fetchArray(SQLITE3_NUM)) $idxs[] = $irow[0];
                    $schema_ins->bindValue(':bid',   $src_id, SQLITE3_INTEGER);
                    $schema_ins->bindValue(':tname', $tname,  SQLITE3_TEXT);
                    $schema_ins->bindValue(':ddl',   $ddl,    SQLITE3_TEXT);
                    $schema_ins->bindValue(':idx',   json_encode($idxs, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
                    $schema_ins->execute();
                    $schema_ins->reset();
                    $schema_total++;
                }

                $db->exec('COMMIT');
                return [$db_applied, $snap_total, $schema_total];
            } catch (\Throwable $e) {
                $db->exec('ROLLBACK');
                throw $e;
            }
        }
    );
    echo "  DB ops applied: $db_applied\n";
    echo "  refreshed ancestor snapshot for '$source': $snap_total rows, $schema_total tables\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "merge: DB phase failed: " . $e->getMessage() . "\n");
    $db->close();
    exit(4);
}

$db->close();
echo "\nMerge complete.\n";

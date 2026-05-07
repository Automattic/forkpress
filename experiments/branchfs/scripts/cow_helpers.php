<?php
/**
 * COW (Copy-on-Write) DB branch helpers — shared between branchctl.php
 * and merge.php.
 *
 * Don't include this file from CLI scripts directly — `require_once`
 * from branchctl.php and merge.php instead.
 */

// ============================================================
// COW (Copy-on-Write) DB branch helpers
// ============================================================
//
// Each "logical" WordPress table on a non-main branch is replaced with:
//
//   b{id}_wp_X                  →  VIEW (what WordPress queries)
//   b{id}_wp_X__overlay         →  TABLE (rows the branch added/modified)
//   b{id}_wp_X__tombstones      →  TABLE (PKs the branch deleted from inherited rows)
//
// The view returns:
//   SELECT * FROM overlay
//   UNION ALL
//   SELECT * FROM <parent's view> p
//   WHERE (p.pk_cols) NOT IN (SELECT pk_cols FROM overlay)
//     AND (p.pk_cols) NOT IN (SELECT pk_cols FROM tombstones)
//
// Branch creation is now O(num_tables × small constant) — no row data
// is touched. For ~22 typical WP tables, branch create is milliseconds
// regardless of row count.

/** Quote a SQLite identifier with double quotes. */
function cow_quote_identifier(string $name): string {
    return '"' . str_replace('"', '""', $name) . '"';
}

/** Regex fragment matching the common SQLite/MySQL identifier spellings. */
function cow_identifier_regex(string $name): string {
    $quoted = preg_quote($name, '/');
    return '(?:`' . $quoted . '`|"' . $quoted . '"|\[' . $quoted . '\]|' . $quoted . ')';
}

/** Rewrite a CREATE TABLE statement to target a new table name.
 *
 *  The WordPress SQLite integration emits backtick-quoted identifiers
 *  (`b1_wp_options`). Older COW creation only matched bare/double-quoted
 *  names, so overlay creation silently retried the parent table DDL and
 *  left branch views pointing at missing __overlay tables.
 */
function cow_rewrite_create_table_name(string $ddl, string $old_name, string $new_name): string {
    $pattern = '/^(CREATE\s+TABLE\s+)(?:IF\s+NOT\s+EXISTS\s+)?'
             . cow_identifier_regex($old_name)
             . '(\s*\()/is';
    $count = 0;
    $rewritten = preg_replace_callback(
        $pattern,
        fn($m) => $m[1] . 'IF NOT EXISTS ' . cow_quote_identifier($new_name) . $m[2],
        $ddl,
        1,
        $count
    );
    if ($rewritten === null || $count !== 1) {
        throw new RuntimeException("could not rewrite CREATE TABLE DDL for $old_name");
    }
    return $rewritten;
}

/** Rewrite a CREATE INDEX statement to target a new index name and table. */
function cow_rewrite_create_index(string $ddl, string $old_index, string $new_index,
                                  string $old_table, string $new_table): string {
    $index_pattern = '/^(CREATE\s+(?:UNIQUE\s+)?INDEX\s+)(?:IF\s+NOT\s+EXISTS\s+)?'
                   . cow_identifier_regex($old_index) . '/is';
    $count = 0;
    $rewritten = preg_replace_callback(
        $index_pattern,
        fn($m) => $m[1] . 'IF NOT EXISTS ' . cow_quote_identifier($new_index),
        $ddl,
        1,
        $count
    );
    if ($rewritten === null || $count !== 1) {
        throw new RuntimeException("could not rewrite CREATE INDEX DDL for $old_index");
    }

    $table_pattern = '/(\bON\s+)' . cow_identifier_regex($old_table) . '(\s*\()/is';
    $count = 0;
    $rewritten = preg_replace_callback(
        $table_pattern,
        fn($m) => $m[1] . cow_quote_identifier($new_table) . $m[2],
        $rewritten,
        1,
        $count
    );
    if ($rewritten === null || $count !== 1) {
        throw new RuntimeException("could not retarget CREATE INDEX DDL for $old_index");
    }
    return $rewritten;
}

function cow_sql_literal($value): string {
    if ($value === null) return 'NULL';
    return "'" . SQLite3::escapeString((string)$value) . "'";
}

function cow_has_column(SQLite3 $db, string $table, string $column): bool {
    $r = $db->query('PRAGMA table_info(' . cow_quote_identifier($table) . ')');
    if (!$r) return false;
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        if (strcasecmp((string)$row['name'], $column) === 0) return true;
    }
    return false;
}

/** Copy WordPress SQLite integration metadata from a parent table to a COW view.
 *
 *  The SQLite integration translates MySQL INSERT/UPDATE forms by consulting
 *  its own `_wp_sqlite_mysql_information_schema_*` tables. Our COW branch
 *  logical tables are SQLite views created outside that integration, so we
 *  mirror the parent's metadata under the branch logical table name.
 */
function cow_sync_information_schema_for_table(SQLite3 $db, int $branch_id, int $parent_id,
                                               string $table_suffix): void {
    $source_table = cow_logical_name($parent_id, $table_suffix);
    $dest_table   = cow_logical_name($branch_id, $table_suffix);
    $parent_prefix = "b{$parent_id}_wp_";
    $branch_prefix = "b{$branch_id}_wp_";

    $schema_tables = [
        'tables',
        'columns',
        'statistics',
        'table_constraints',
        'key_column_usage',
        'referential_constraints',
    ];

    foreach ($schema_tables as $schema_suffix) {
        $schema_table = '_wp_sqlite_mysql_information_schema_' . $schema_suffix;
        if (!cow_is_table($db, $schema_table) || !cow_has_column($db, $schema_table, 'TABLE_NAME')) {
            continue;
        }

        $columns = cow_table_columns($db, $schema_table);
        if (empty($columns)) continue;

        $quoted_columns = implode(', ', array_map('cow_quote_identifier', $columns));
        $select_exprs = [];
        foreach ($columns as $column) {
            $quoted = cow_quote_identifier($column);
            $upper = strtoupper($column);
            if ($upper === 'TABLE_NAME') {
                $select_exprs[] = cow_sql_literal($dest_table) . ' AS ' . $quoted;
            } elseif ($upper === 'TABLE_TYPE') {
                // These COW views are writable through INSTEAD OF triggers; make
                // MySQL-compat introspection treat them as normal WordPress tables.
                $select_exprs[] = "'BASE TABLE' AS " . $quoted;
            } elseif (in_array($upper, [
                'INDEX_NAME',
                'CONSTRAINT_NAME',
                'UNIQUE_CONSTRAINT_NAME',
                'REFERENCED_TABLE_NAME',
            ], true)) {
                $select_exprs[] = 'REPLACE(' . $quoted . ', '
                    . cow_sql_literal($parent_prefix) . ', '
                    . cow_sql_literal($branch_prefix) . ') AS ' . $quoted;
            } else {
                $select_exprs[] = $quoted;
            }
        }

        $where = cow_quote_identifier('TABLE_NAME') . ' = ' . cow_sql_literal($source_table);
        if (cow_has_column($db, $schema_table, 'TABLE_SCHEMA')) {
            $where .= ' AND ' . cow_quote_identifier('TABLE_SCHEMA') . " = 'sqlite_database'";
        }

        $delete_where = cow_quote_identifier('TABLE_NAME') . ' = ' . cow_sql_literal($dest_table);
        if (cow_has_column($db, $schema_table, 'TABLE_SCHEMA')) {
            $delete_where .= ' AND ' . cow_quote_identifier('TABLE_SCHEMA') . " = 'sqlite_database'";
        }

        $db->exec('DELETE FROM ' . cow_quote_identifier($schema_table) . ' WHERE ' . $delete_where);
        $ok = $db->exec(
            'INSERT OR REPLACE INTO ' . cow_quote_identifier($schema_table)
            . ' (' . $quoted_columns . ') SELECT '
            . implode(', ', $select_exprs)
            . ' FROM ' . cow_quote_identifier($schema_table)
            . ' WHERE ' . $where
        );
        if (!$ok) {
            throw new RuntimeException(
                "failed to sync SQLite information schema for $dest_table: " . $db->lastErrorMsg()
            );
        }
    }
}

function cow_sync_all_information_schema(SQLite3 $db): int {
    $synced = 0;
    $r = $db->query(
        "SELECT branch_id, parent_branch_id, table_suffix "
      . "FROM db_cow_branches ORDER BY branch_id, table_suffix"
    );
    if (!$r) return 0;
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $branch_id = (int)$row['branch_id'];
        $parent_id = (int)$row['parent_branch_id'];
        $suffix = (string)$row['table_suffix'];
        if ($branch_id <= 1 || $parent_id <= 0 || $suffix === '') continue;
        cow_sync_information_schema_for_table($db, $branch_id, $parent_id, $suffix);
        $synced++;
    }
    return $synced;
}

/** Seed branch-local WordPress role/capability rows that include table_prefix. */
function cow_seed_wordpress_prefix_rows(SQLite3 $db, int $branch_id, int $parent_id): void {
    if ($branch_id <= 1 || $parent_id <= 0) return;

    $parent_prefix = "b{$parent_id}_wp_";
    $branch_prefix = "b{$branch_id}_wp_";

    $parent_options = cow_logical_name($parent_id, 'options');
    $branch_options = cow_logical_name($branch_id, 'options');
    if ((cow_is_table($db, $parent_options) || cow_is_view($db, $parent_options))
        && (cow_is_table($db, $branch_options) || cow_is_view($db, $branch_options))) {
        $src = $parent_prefix . 'user_roles';
        $dst = $branch_prefix . 'user_roles';
        $ok = $db->exec(
            'INSERT INTO ' . cow_quote_identifier($branch_options)
            . ' ("option_name", "option_value", "autoload") '
            . 'SELECT ' . cow_sql_literal($dst) . ', src."option_value", src."autoload" '
            . 'FROM ' . cow_quote_identifier($parent_options) . ' src '
            . 'WHERE src."option_name" = ' . cow_sql_literal($src)
            . ' AND NOT EXISTS (SELECT 1 FROM ' . cow_quote_identifier($branch_options)
            . ' dst WHERE dst."option_name" = ' . cow_sql_literal($dst) . ')'
        );
        if (!$ok) {
            throw new RuntimeException(
                "failed to seed WordPress roles option for b$branch_id: " . $db->lastErrorMsg()
            );
        }
    }

    $parent_usermeta = cow_logical_name($parent_id, 'usermeta');
    $branch_usermeta = cow_logical_name($branch_id, 'usermeta');
    if ((cow_is_table($db, $parent_usermeta) || cow_is_view($db, $parent_usermeta))
        && (cow_is_table($db, $branch_usermeta) || cow_is_view($db, $branch_usermeta))) {
        foreach (['capabilities', 'user_level'] as $suffix) {
            $src = $parent_prefix . $suffix;
            $dst = $branch_prefix . $suffix;
            $ok = $db->exec(
                'INSERT INTO ' . cow_quote_identifier($branch_usermeta)
                . ' ("user_id", "meta_key", "meta_value") '
                . 'SELECT src."user_id", ' . cow_sql_literal($dst) . ', src."meta_value" '
                . 'FROM ' . cow_quote_identifier($parent_usermeta) . ' src '
                . 'WHERE src."meta_key" = ' . cow_sql_literal($src)
                . ' AND NOT EXISTS (SELECT 1 FROM ' . cow_quote_identifier($branch_usermeta)
                . ' dst WHERE dst."user_id" = src."user_id"'
                . ' AND dst."meta_key" = ' . cow_sql_literal($dst) . ')'
            );
            if (!$ok) {
                throw new RuntimeException(
                    "failed to seed WordPress usermeta $dst for b$branch_id: " . $db->lastErrorMsg()
                );
            }
        }
    }
}

function cow_seed_all_wordpress_prefix_rows(SQLite3 $db): int {
    $seeded = 0;
    $r = $db->query("SELECT id, parent_branch FROM branches WHERE id > 1 ORDER BY id");
    if (!$r) return 0;
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $branch_id = (int)$row['id'];
        $parent_name = (string)($row['parent_branch'] ?? '');
        if ($branch_id <= 1 || $parent_name === '') continue;
        $parent_id = (int)$db->querySingle(
            "SELECT id FROM branches WHERE name = '" . SQLite3::escapeString($parent_name) . "'"
        );
        if ($parent_id <= 0) continue;
        cow_seed_wordpress_prefix_rows($db, $branch_id, $parent_id);
        $seeded++;
    }
    return $seeded;
}

/** Return ordered PRIMARY KEY column names for a table or view.
 *
 *  `PRAGMA table_info()` on a VIEW returns rows with pk=0 for every column —
 *  SQLite doesn't propagate the underlying PK through the view metadata.
 *  When the target is a COW branch view we recurse into its __overlay (which
 *  carries the same shape and the original PK) to recover the true PK set.
 *  Fallback all the way up to the parent table if no __overlay exists yet.
 *
 *  Empty array if the table has no explicit PK. */
function cow_extract_pk_cols(SQLite3 $db, string $real_table_name): array {
    $target = $real_table_name;
    // If the target is a view, try to find an underlying __overlay table to
    // get PK metadata from. Walk up through the COW marker chain.
    $guard = 0;
    while (cow_is_view($db, $target) && $guard++ < 32) {
        $overlay = $target . '__overlay';
        if (cow_is_table($db, $overlay)) {
            $target = $overlay;
            break;
        }
        // Otherwise walk up to the parent's logical table via the COW marker.
        // e.g. target = "b3_wp_options": find branch id 3, its parent, then
        // parent's logical "b{parent_id}_wp_options".
        if (!preg_match('/^b(\d+)_wp_(.+)$/', $target, $m)) break;
        $bid = (int)$m[1];
        $suffix = $m[2];
        $pid = (int)$db->querySingle(
            "SELECT parent_branch_id FROM db_cow_branches "
          . "WHERE branch_id=$bid AND table_suffix='" . SQLite3::escapeString($suffix) . "'"
        );
        if ($pid <= 0) break;
        $target = "b{$pid}_wp_{$suffix}";
    }
    $pk = [];
    $r = $db->query('PRAGMA table_info("' . SQLite3::escapeString($target) . '")');
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        if ((int)$row['pk'] > 0) $pk[(int)$row['pk']] = $row['name'];
    }
    ksort($pk);
    return array_values($pk);
}

/** TODO3 #10 — return single-column UNIQUE constraints declared on $table.
 *
 *  Walks PRAGMA index_list; for each entry with origin='u' (CREATE TABLE
 *  UNIQUE) or origin='c' (CREATE UNIQUE INDEX), if the index covers exactly
 *  one column, include that column in the result. Multi-column UNIQUE and
 *  the PK's auto-created index (origin='pk') are excluded.
 *
 *  Used to emit cross-layer UNIQUE RAISE guards in INSTEAD OF triggers so
 *  a branch's overlay can't admit a row whose "unique" value already
 *  exists on an inherited parent row. */
function cow_single_col_unique_columns(SQLite3 $db, string $table): array {
    $result = [];
    $r = $db->query('PRAGMA index_list("' . SQLite3::escapeString($table) . '")');
    if (!$r) return [];
    $indexes = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $indexes[] = $row;
    $r->finalize();
    foreach ($indexes as $ix) {
        if ((int)($ix['unique'] ?? 0) !== 1) continue;
        $origin = (string)($ix['origin'] ?? '');
        if ($origin !== 'u' && $origin !== 'c') continue;
        $iname = (string)$ix['name'];
        $ir = $db->query('PRAGMA index_info("' . SQLite3::escapeString($iname) . '")');
        if (!$ir) continue;
        $cols = [];
        while ($row = $ir->fetchArray(SQLITE3_ASSOC)) {
            $cols[] = (string)$row['name'];
        }
        $ir->finalize();
        if (count($cols) === 1) $result[$cols[0]] = true;
    }
    return array_keys($result);
}

/** Return ordered list of all column names for a table. */
function cow_table_columns(SQLite3 $db, string $real_table_name): array {
    $cols = [];
    $r = $db->query('PRAGMA table_info("' . SQLite3::escapeString($real_table_name) . '")');
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $cols[] = (string)$row['name'];
    }
    return $cols;
}

/** Resolve a branch name to its parent branch's id (0 if it has no parent
 *  or parent doesn't exist). For 'main' returns 0. */
function cow_parent_branch_id(SQLite3 $db, int $branch_id): int {
    $s = $db->prepare(
        "SELECT b2.id FROM branches b1 JOIN branches b2 "
      . "ON b1.parent_branch = b2.name WHERE b1.id = :b"
    );
    $s->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_NUM);
    return $row ? (int)$row[0] : 0;
}

/** True iff the named object exists and is a view. */
function cow_is_view(SQLite3 $db, string $name): bool {
    $t = (string)$db->querySingle(
        "SELECT type FROM sqlite_master WHERE name='" . SQLite3::escapeString($name) . "'"
    );
    return $t === 'view';
}

/** True iff the named object exists and is a table. */
function cow_is_table(SQLite3 $db, string $name): bool {
    $t = (string)$db->querySingle(
        "SELECT type FROM sqlite_master WHERE name='" . SQLite3::escapeString($name) . "'"
    );
    return $t === 'table';
}

/** Build the SELECT body of the view (no CREATE VIEW prefix).
 *  $parent_view_name is the name of the parent's logical table — could be
 *  a real table (if parent is main) or a view (if parent is itself a branch). */
function cow_resolve_view_sql(string $overlay_name, string $tombstone_name,
                              string $parent_view_name, array $pk_cols,
                              array $columns): string {
    $col_list = implode(', ', array_map(fn($c) => '"' . $c . '"', $columns));
    if (empty($pk_cols)) {
        // No explicit PK: just UNION ALL (no dedup possible). Tombstones
        // can't really apply either; fall back to overlay-only when
        // tombstone is non-empty would be safest, but for WordPress
        // every table has a PK so this branch is essentially never hit.
        return "SELECT $col_list FROM \"$overlay_name\" "
             . "UNION ALL "
             . "SELECT $col_list FROM \"$parent_view_name\"";
    }
    if (count($pk_cols) === 1) {
        $pk = '"' . $pk_cols[0] . '"';
        return "SELECT $col_list FROM \"$overlay_name\" "
             . "UNION ALL "
             . "SELECT $col_list FROM \"$parent_view_name\" p "
             . "WHERE p.$pk NOT IN (SELECT $pk FROM \"$overlay_name\") "
             . "  AND p.$pk NOT IN (SELECT $pk FROM \"$tombstone_name\")";
    }
    // Composite PK: use row-value tuples.
    $pk_tuple_p   = '(' . implode(', ', array_map(fn($c) => 'p."' . $c . '"', $pk_cols)) . ')';
    $pk_tuple_sel = '(' . implode(', ', array_map(fn($c) => '"' . $c . '"', $pk_cols))   . ')';
    $pk_sel       = implode(', ', array_map(fn($c) => '"' . $c . '"', $pk_cols));
    return "SELECT $col_list FROM \"$overlay_name\" "
         . "UNION ALL "
         . "SELECT $col_list FROM \"$parent_view_name\" p "
         . "WHERE $pk_tuple_p NOT IN (SELECT $pk_sel FROM \"$overlay_name\") "
         . "  AND $pk_tuple_p NOT IN (SELECT $pk_sel FROM \"$tombstone_name\")";
}

/** Build a JSON-object expression `json_object('col1', SRC.col1, ...)` for
 *  a list of columns referenced via SRC alias (typically OLD or NEW). */
function cow_json_obj_expr(string $src_alias, array $columns): string {
    $pairs = [];
    foreach ($columns as $c) {
        $pairs[] = "'" . str_replace("'", "''", $c) . "', $src_alias.\"$c\"";
    }
    return 'json_object(' . implode(', ', $pairs) . ')';
}

/** Install (or reinstall) the parent-side ancestor-capture trigger for
 *  $parent_table.
 *
 *  Pre-TODO3 design: one trigger per parent whose body fanned out via
 *  `INSERT … SELECT FROM db_cow_branches WHERE parent_table_name = …`,
 *  producing ONE row per descendant branch per parent write. Cost was
 *  O(num descendants) — a site with 100 child branches paid 100
 *  trigger inserts per parent UPDATE. That degraded parent throughput
 *  linearly with descendant count, exactly the workload pattern cheap
 *  branching was meant to encourage.
 *
 *  Post-TODO3: ONE trigger per parent table whose body inserts ONCE
 *  into the shared `db_parent_ancestor` table keyed by (parent_table,
 *  row_pk). Cost is O(1) per parent write, independent of descendant
 *  count. Merge.php fans the row back out across descendants at merge
 *  time (only for branches that actually diverge — ancestors for rows
 *  a branch never touches stay untouched).
 *
 *  Only installs on REAL tables (BEFORE/AFTER triggers require tables);
 *  for nested-branch parents the parent is itself a view, and ancestor
 *  capture for its descendants flows through the (recursive) shared
 *  table of the ultimate parent table. */
function cow_install_parent_triggers(SQLite3 $db, string $parent_table): void {
    if (!cow_is_table($db, $parent_table)) return;

    $pk_cols = cow_extract_pk_cols($db, $parent_table);
    $columns = cow_table_columns($db, $parent_table);
    if (empty($columns)) return;
    $pk_for_obj = empty($pk_cols) ? $columns : $pk_cols;

    $pk_obj_old     = cow_json_obj_expr('OLD', $pk_for_obj);
    $row_obj_old    = cow_json_obj_expr('OLD', $columns);
    $pk_obj_new     = cow_json_obj_expr('NEW', $pk_for_obj);

    $base = preg_replace('/[^a-zA-Z0-9_]/', '_', $parent_table);
    $trg_upd = "cow_anc__{$base}__upd";
    $trg_del = "cow_anc__{$base}__del";
    $trg_ins = "cow_anc__{$base}__ins";
    $parent_q = str_replace("'", "''", $parent_table);

    // Drop any legacy (pre-TODO3 fanout) or previously-installed trigger
    // and reinstall the O(1) version. INSERT OR IGNORE preserves the
    // FIRST pre-divergence value as the true fork-time ancestor.
    $db->exec("DROP TRIGGER IF EXISTS \"$trg_upd\"");
    $db->exec("DROP TRIGGER IF EXISTS \"$trg_del\"");
    $db->exec("DROP TRIGGER IF EXISTS \"$trg_ins\"");

    $db->exec(
        "CREATE TRIGGER \"$trg_upd\" BEFORE UPDATE ON \"$parent_table\"\n"
      . "BEGIN\n"
      . "    INSERT OR IGNORE INTO db_parent_ancestor\n"
      . "        (parent_table_name, row_pk, row_json)\n"
      . "    VALUES ('$parent_q', $pk_obj_old, $row_obj_old);\n"
      . "END"
    );
    $db->exec(
        "CREATE TRIGGER \"$trg_del\" BEFORE DELETE ON \"$parent_table\"\n"
      . "BEGIN\n"
      . "    INSERT OR IGNORE INTO db_parent_ancestor\n"
      . "        (parent_table_name, row_pk, row_json)\n"
      . "    VALUES ('$parent_q', $pk_obj_old, $row_obj_old);\n"
      . "END"
    );
    // AFTER INSERT tracks PKs created on parent after any descendant's
    // fork — lets merge distinguish "ancestor was absent" (this case)
    // from "ancestor was the same as target's current row".
    $db->exec(
        "CREATE TRIGGER \"$trg_ins\" AFTER INSERT ON \"$parent_table\"\n"
      . "BEGIN\n"
      . "    INSERT OR IGNORE INTO db_parent_post_fork_inserts\n"
      . "        (parent_table_name, row_pk)\n"
      . "    VALUES ('$parent_q', $pk_obj_new);\n"
      . "END"
    );
}

/** Drop any legacy parent-side ancestor-capture triggers for $parent_table.
 *  Used by tests and by callers that explicitly want the O(1) trigger to
 *  go away. Safe no-op if triggers aren't present. */
function cow_drop_parent_triggers(SQLite3 $db, string $parent_table): int {
    $base = preg_replace('/[^a-zA-Z0-9_]/', '_', $parent_table);
    $names = ["cow_anc__{$base}__upd", "cow_anc__{$base}__del", "cow_anc__{$base}__ins"];
    $dropped = 0;
    foreach ($names as $n) {
        $exists = (int)$db->querySingle(
            "SELECT COUNT(*) FROM sqlite_master "
          . "WHERE type='trigger' AND name='" . SQLite3::escapeString($n) . "'"
        );
        if ($exists > 0) {
            $db->exec('DROP TRIGGER IF EXISTS "' . $n . '"');
            $dropped++;
        }
    }
    return $dropped;
}

/** Generate the INSTEAD OF triggers for the view that route writes to
 *  overlay/tombstones.
 *
 *  $columns is the ordered column list. $defaults (optional) is a map of
 *  col_name => default_value_expr — for columns with NOT NULL DEFAULT,
 *  the trigger uses COALESCE(NEW.col, default) so an INSERT that omits
 *  the column gets the same default the underlying table would. */
function cow_trigger_sql(string $view_name, string $overlay_name,
                        string $tombstone_name, array $pk_cols,
                        array $columns, array $defaults = [],
                        int $branch_id = 0, string $parent_table = '',
                        array $parent_columns = [],
                        array $unique_cols = []): array {
    // branch_id / parent_table / parent_columns were originally added for
    // lazy branch-side ancestor capture (TODO3 #3 pivoted to a shared
    // parent-trigger scheme instead); they remain in the signature to
    // keep the data flow available to cross-layer UNIQUE guards (TODO3 #10)
    // and forward-compat callers.

    $col_list   = implode(', ', array_map(fn($c) => '"' . $c . '"', $columns));
    $new_vals_with_default = function (string $c) use ($defaults): string {
        if (isset($defaults[$c]) && $defaults[$c] !== null && $defaults[$c] !== '') {
            return 'COALESCE(NEW."' . $c . '", ' . $defaults[$c] . ')';
        }
        return 'NEW."' . $c . '"';
    };
    $new_vals   = implode(', ', array_map($new_vals_with_default, $columns));
    $set_list   = implode(', ', array_map(fn($c) => '"' . $c . '" = NEW."' . $c . '"', $columns));

    // PK match condition for tombstone delete (after re-insert) and overlay
    // upsert/delete using OLD/NEW.
    if (empty($pk_cols)) {
        $pk_match_old = implode(' AND ', array_map(
            fn($c) => '"' . $c . '" IS OLD."' . $c . '"', $columns
        ));
        $pk_match_new = implode(' AND ', array_map(
            fn($c) => '"' . $c . '" IS NEW."' . $c . '"', $columns
        ));
        $pk_cols_for_tomb = $columns;
        $tomb_old_vals = implode(', ', array_map(fn($c) => 'OLD."' . $c . '"', $columns));
    } else {
        $pk_match_old = implode(' AND ', array_map(
            fn($c) => '"' . $c . '" IS OLD."' . $c . '"', $pk_cols
        ));
        $pk_match_new = implode(' AND ', array_map(
            fn($c) => '"' . $c . '" IS NEW."' . $c . '"', $pk_cols
        ));
        $pk_cols_for_tomb = $pk_cols;
        $tomb_old_vals = implode(', ', array_map(fn($c) => 'OLD."' . $c . '"', $pk_cols));
    }
    $tomb_cols = implode(', ', array_map(fn($c) => '"' . $c . '"', $pk_cols_for_tomb));

    // ── Cross-layer UNIQUE guard (TODO3 #10, Cluster-A #1/#9) ────────
    //
    // A naive overlay-only INSERT can admit a row whose UNIQUE-constrained
    // column matches an inherited (non-tombstoned) parent row: the view's
    // UNION ALL then returns two rows with the same "unique" value. Build
    // a RAISE(ABORT, …) preamble that mirrors SQLite's native
    // SQLITE_CONSTRAINT_UNIQUE for each single-column UNIQUE the overlay
    // carries.
    //
    // Cluster-A fix: the NOT-IN subquery must use the FULL composite PK
    // tuple — pre-fix this used only $pk_cols[0], so on a 2-col-PK table
    // a tombstone on (1, 20) would make the guard think (1, 10) was also
    // deleted, admitting a UNIQUE collision.
    $unique_ins_preamble = '';
    $unique_upd_preamble = '';
    if (!empty($unique_cols) && !empty($pk_cols) && $parent_table !== '') {
        $parent_q = str_replace('"', '""', $parent_table);
        $tomb_q   = str_replace('"', '""', $tombstone_name);
        $ovl_q    = str_replace('"', '""', $overlay_name);
        // Build the full composite-PK tuple expressions the guard needs.
        $pk_tuple_p   = '(' . implode(', ', array_map(fn($c) => 'p."' . $c . '"', $pk_cols)) . ')';
        $pk_tuple_new = '(' . implode(', ', array_map(fn($c) => 'NEW."' . $c . '"', $pk_cols)) . ')';
        $pk_sel       = implode(', ', array_map(fn($c) => '"' . $c . '"', $pk_cols));
        foreach ($unique_cols as $uc) {
            $uc_q = '"' . $uc . '"';
            $msg_ins = "UNIQUE constraint failed: "
                     . $view_name . '.' . $uc . " (cross-layer collision)";
            $msg_upd = $msg_ins;
            $unique_ins_preamble .=
                  "    SELECT RAISE(ABORT, '" . str_replace("'", "''", $msg_ins) . "')\n"
                . "    WHERE NEW.$uc_q IS NOT NULL AND EXISTS (\n"
                . "        SELECT 1 FROM \"$parent_q\" p\n"
                . "        WHERE p.$uc_q IS NEW.$uc_q\n"
                . "          AND $pk_tuple_p NOT IN (SELECT $pk_sel FROM \"$tomb_q\")\n"
                . "          AND $pk_tuple_p NOT IN (SELECT $pk_sel FROM \"$ovl_q\")\n"
                . "    );\n";
            // On UPDATE: if NEW's unique value matches an inherited
            // parent row whose PK tuple differs from NEW, that's a
            // cross-layer collision.
            $unique_upd_preamble .=
                  "    SELECT RAISE(ABORT, '" . str_replace("'", "''", $msg_upd) . "')\n"
                . "    WHERE NEW.$uc_q IS NOT NULL AND EXISTS (\n"
                . "        SELECT 1 FROM \"$parent_q\" p\n"
                . "        WHERE p.$uc_q IS NEW.$uc_q\n"
                . "          AND $pk_tuple_p IS NOT $pk_tuple_new\n"
                . "          AND $pk_tuple_p NOT IN (SELECT $pk_sel FROM \"$tomb_q\")\n"
                . "          AND $pk_tuple_p NOT IN (SELECT $pk_sel FROM \"$ovl_q\")\n"
                . "    );\n";
        }
    }

    $ins_trg = "CREATE TRIGGER \"{$view_name}__cow_ins\" INSTEAD OF INSERT ON \"$view_name\"\n"
             . "BEGIN\n"
             . $unique_ins_preamble
             . "    DELETE FROM \"$tombstone_name\" WHERE $pk_match_new;\n"
             . "    INSERT INTO \"$overlay_name\" ($col_list) VALUES ($new_vals);\n"
             . "END";

    // ── INSTEAD OF UPDATE (Cluster-A #1) ─────────────────────────────
    //
    // Pre-Cluster-A: `INSERT OR REPLACE INTO overlay VALUES (…)` silently
    // deleted any overlay row whose UNIQUE value conflicted with NEW —
    // SQL users expect UNIQUE-violation errors, not REPLACE semantics,
    // unless they wrote "OR REPLACE" themselves. And when NEW.PK ≠ OLD.PK
    // the old overlay row's tombstone coverage was lost (the parent row
    // at OLD.PK reappeared through the view).
    //
    // Post-Cluster-A:
    //   1. Cross-layer UNIQUE guard (above) — unchanged in spirit,
    //      composite-PK-safe now.
    //   2. If PK changed:
    //        a. tombstone OLD.PK (so parent's row at OLD doesn't show).
    //        b. delete the old overlay row at OLD.PK.
    //      Else skip both (same-PK update).
    //   3. Remove any tombstone at NEW.PK (we're placing a row there).
    //   4. UPSERT into overlay at NEW.PK — `ON CONFLICT(pk) DO UPDATE`.
    //      SQLite's native UNIQUE-on-non-PK enforcement then fires
    //      cleanly if NEW's unique value collides with another overlay
    //      row (a case INSERT OR REPLACE used to swallow).
    //
    // Tombstone values for OLD.PK must match the tombstone's column
    // list. For no-explicit-PK tables the tombstone covers all columns,
    // matching $pk_cols_for_tomb; otherwise it's $pk_cols.
    $pk_match_old_eq_new = empty($pk_cols)
        ? implode(' AND ', array_map(
            fn($c) => 'OLD."' . $c . '" IS NEW."' . $c . '"', $columns
          ))
        : implode(' AND ', array_map(
            fn($c) => 'OLD."' . $c . '" IS NEW."' . $c . '"', $pk_cols
          ));

    // Non-PK columns for the UPSERT's DO UPDATE SET. Can't set the PK
    // to itself; only non-PK columns need re-assigning. When all
    // columns are PK, DO UPDATE has nothing to set, so fall back to a
    // no-op (DO NOTHING).
    $nonpk_cols = empty($pk_cols)
        ? []
        : array_values(array_diff($columns, $pk_cols));
    if (!empty($nonpk_cols)) {
        $upsert_set = implode(', ', array_map(
            fn($c) => '"' . $c . '" = excluded."' . $c . '"', $nonpk_cols
        ));
        $upsert_tail = "ON CONFLICT($tomb_cols) DO UPDATE SET $upsert_set";
    } else {
        // Table is all-PK (rare; tombstone/overlay degenerate case).
        $upsert_tail = "ON CONFLICT($tomb_cols) DO NOTHING";
    }

    $upd_trg = "CREATE TRIGGER \"{$view_name}__cow_upd\" INSTEAD OF UPDATE ON \"$view_name\"\n"
             . "BEGIN\n"
             . $unique_upd_preamble
             . "    INSERT OR IGNORE INTO \"$tombstone_name\" ($tomb_cols)\n"
             . "        SELECT $tomb_old_vals WHERE NOT ($pk_match_old_eq_new);\n"
             . "    DELETE FROM \"$overlay_name\"\n"
             . "        WHERE $pk_match_old AND NOT ($pk_match_old_eq_new);\n"
             . "    DELETE FROM \"$tombstone_name\" WHERE $pk_match_new;\n"
             . "    INSERT INTO \"$overlay_name\" ($col_list) VALUES ($new_vals)\n"
             . "        $upsert_tail;\n"
             . "END";

    $del_trg = "CREATE TRIGGER \"{$view_name}__cow_del\" INSTEAD OF DELETE ON \"$view_name\"\n"
             . "BEGIN\n"
             . "    DELETE FROM \"$overlay_name\" WHERE $pk_match_old;\n"
             . "    INSERT OR REPLACE INTO \"$tombstone_name\" ($tomb_cols) VALUES ($tomb_old_vals);\n"
             . "END";

    return [$ins_trg, $upd_trg, $del_trg];
}

/** Resolve, for a branch_id, the name of the "logical" object (table or
 *  view) that exposes table_suffix on that branch. For main, this is the
 *  real table b1_wp_<suffix>; for any other branch this is the view
 *  b{id}_wp_<suffix>. */
function cow_logical_name(int $branch_id, string $table_suffix): string {
    return "b{$branch_id}_wp_{$table_suffix}";
}

/** Create the COW trio (overlay + tombstones + view + INSTEAD OF triggers)
 *  for one table on a new branch. Replicates indexes (notably UNIQUE) onto
 *  the overlay. Copies the parent's sqlite_sequence row so autoincrement
 *  IDs from the branch don't collide with the parent. */
function cow_create_branch_table(SQLite3 $db, int $branch_id, int $parent_id,
                                 string $table_suffix): void {
    $parent_table = "b{$parent_id}_wp_{$table_suffix}";
    $logical_name = "b{$branch_id}_wp_{$table_suffix}";
    $overlay_name = $logical_name . '__overlay';
    $tomb_name    = $logical_name . '__tombstones';

    // Discover the parent's underlying physical table for column/PK metadata.
    // PRAGMA table_info() is happy to describe a view as well as a table —
    // when the parent is itself a branch (so $parent_table is a view), this
    // still returns the correct columns and PK info as inherited.
    $pk_cols = cow_extract_pk_cols($db, $parent_table);
    $columns = cow_table_columns($db, $parent_table);

    if (empty($columns)) {
        // No table to mirror. Skip silently — caller will warn.
        return;
    }

    // Build overlay DDL by reusing parent's CREATE TABLE statement when
    // available (so PRIMARY KEY / UNIQUE / NOT NULL / DEFAULTs are preserved).
    // If the parent is a VIEW (i.e. parent is itself a branch), we walk up
    // to a real underlying overlay or table to get a DDL we can clone.
    $ddl_source = $parent_table;
    while (cow_is_view($db, $ddl_source)) {
        // Use the parent branch's overlay as DDL source — it has the same
        // shape as the logical view.
        $cow_pid = (int)$db->querySingle(
            "SELECT parent_branch_id FROM db_cow_branches "
          . "WHERE branch_id IN (SELECT id FROM branches WHERE name=("
          . "    SELECT parent_branch FROM branches WHERE id=$parent_id"
          . ")) LIMIT 1"
        );
        // Try the parent's overlay first; if it doesn't exist, fall back to
        // the parent of the parent's logical table.
        $cand_overlay = $ddl_source . '__overlay';
        if (cow_is_table($db, $cand_overlay)) {
            $ddl_source = $cand_overlay;
        } else {
            // Walk further up the parent chain.
            $bid = (int)$db->querySingle(
                "SELECT id FROM branches WHERE name = (SELECT parent_branch FROM branches WHERE id = $parent_id)"
            );
            if ($bid <= 0) break;
            $ddl_source = "b{$bid}_wp_{$table_suffix}";
        }
    }
    $parent_ddl = (string)$db->querySingle(
        "SELECT sql FROM sqlite_master WHERE type='table' AND name='"
        . SQLite3::escapeString($ddl_source) . "'"
    );

    // Build overlay table.
    if ($parent_ddl !== '') {
        $overlay_ddl = cow_rewrite_create_table_name($parent_ddl, $ddl_source, $overlay_name);
        if (!$db->exec($overlay_ddl)) {
            throw new RuntimeException(
                "failed to create overlay table $overlay_name: " . $db->lastErrorMsg()
            );
        }
    } else {
        // Last-ditch fallback: copy via SELECT (loses constraints).
        if (!$db->exec("CREATE TABLE IF NOT EXISTS " . cow_quote_identifier($overlay_name) . " AS "
                . "SELECT * FROM " . cow_quote_identifier($ddl_source) . " WHERE 0")) {
            throw new RuntimeException(
                "failed to create fallback overlay table $overlay_name: " . $db->lastErrorMsg()
            );
        }
    }
    if (!cow_is_table($db, $overlay_name)) {
        throw new RuntimeException("overlay table $overlay_name was not created");
    }

    // Tombstone table: just the PK columns. Preserve types where we can.
    if (!empty($pk_cols)) {
        // Discover types for the PK cols from PRAGMA on the parent.
        $pk_types = [];
        $pi = $db->query('PRAGMA table_info("' . SQLite3::escapeString($parent_table) . '")');
        while ($prow = $pi->fetchArray(SQLITE3_ASSOC)) {
            if (in_array($prow['name'], $pk_cols, true)) {
                $pk_types[$prow['name']] = $prow['type'] ?: 'TEXT';
            }
        }
        $tomb_cols_ddl = [];
        foreach ($pk_cols as $c) {
            $t = $pk_types[$c] ?? 'TEXT';
            $tomb_cols_ddl[] = '"' . $c . '" ' . $t;
        }
        $tomb_pk = implode(', ', array_map(fn($c) => '"' . $c . '"', $pk_cols));
        $db->exec(
            "CREATE TABLE IF NOT EXISTS \"$tomb_name\" ("
          . implode(', ', $tomb_cols_ddl)
          . ", PRIMARY KEY ($tomb_pk))"
        );
    } else {
        // No PK on parent: tombstone is by full-row identity. Mirror columns.
        $cols_ddl = [];
        foreach ($columns as $c) $cols_ddl[] = '"' . $c . '" BLOB';
        $db->exec("CREATE TABLE IF NOT EXISTS \"$tomb_name\" ("
                . implode(', ', $cols_ddl) . ")");
    }

    // Replicate non-PK UNIQUE indexes from the parent onto the overlay so
    // duplicate-insert semantics match. Skip auto-indexes (sql IS NULL).
    $idx_stmt = $db->prepare(
        "SELECT name, sql FROM sqlite_master WHERE type='index' AND tbl_name=:t "
      . "AND sql IS NOT NULL"
    );
    $idx_stmt->bindValue(':t', $ddl_source, SQLITE3_TEXT);
    $idx_res  = $idx_stmt->execute();
    $idx_rows = [];
    while ($irow = $idx_res->fetchArray(SQLITE3_ASSOC)) $idx_rows[] = $irow;
    $idx_res->finalize();
    $idx_stmt->close();
    // Determine parent's own prefix so we can do a clean str_replace from
    // the parent's index name to a branch-local name.
    $parent_prefix = "b{$parent_id}_wp_";
    $branch_prefix = "b{$branch_id}_wp_";
    foreach ($idx_rows as $irow) {
        $old_idx_name = $irow['name'];
        // Rename the index by str_replace'ing the parent's prefix for the
        // branch's. This both yields a unique-per-branch name AND keeps
        // the schema_merge normalization (which strips the prefix) producing
        // the SAME normalized key for parent-side and branch-side indexes
        // — so adding/dropping indexes on the branch shows up cleanly in
        // the schema diff.
        $new_idx_name = str_replace($parent_prefix, $branch_prefix, $old_idx_name);
        if ($new_idx_name === $old_idx_name) {
            // Index name didn't embed parent prefix — prefix it ourselves
            // to avoid collision with the parent's identically-named index.
            $new_idx_name = $branch_prefix . $old_idx_name;
        }
        $idx_sql = cow_rewrite_create_index(
            $irow['sql'],
            $old_idx_name,
            $new_idx_name,
            $ddl_source,
            $overlay_name
        );
        if (!$db->exec($idx_sql)) {
            throw new RuntimeException(
                "failed to create overlay index $new_idx_name: " . $db->lastErrorMsg()
            );
        }
    }

    // TODO3 #7 + hostile-review #17: Reserve a disjoint AUTOINCREMENT
    // range for this branch that cannot overlap with any sibling or
    // grandchild band regardless of fork depth.
    //
    // Formula: seq = (branch_id - 1) * COW_AUTOINCR_STRIDE — pure, stable,
    // does NOT depend on parent_max. That's critical for nested forks:
    //   • b2 forks from main: band [1e9, 2e9)
    //   • b3 forks from main: band [2e9, 3e9)
    //   • b4 forks from b2 (grandchild): band [3e9, 4e9)
    //   • b5 forks from main: band [4e9, 5e9)
    // Previously seq = parent_max + (branch_id-1)*STRIDE, so b4's seed
    // was b2's current max plus 3*STRIDE, which could slide into b5's
    // band and collide. Stripping parent_max keeps bands strictly
    // branch-id-indexed and collision-proof.
    //
    // Main (branch_id=1) keeps its natural sequence so on-disk IDs stay
    // small; siblings + grandchildren live in their own high-number
    // bands. Merge's `--on-id-collision=renumber` path already handles
    // remapping these back into main's gap on a per-FK-graph basis.
    if (!defined('COW_AUTOINCR_STRIDE')) {
        define('COW_AUTOINCR_STRIDE', 1_000_000_000);
    }
    if ($branch_id > 1) {
        $branch_seq = ($branch_id - 1) * COW_AUTOINCR_STRIDE;
        if (!$db->exec("INSERT OR REPLACE INTO sqlite_sequence (name, seq) "
                . "VALUES ('" . SQLite3::escapeString($overlay_name) . "', $branch_seq)"
        )) {
            throw new RuntimeException(
                "failed to seed sqlite_sequence for $overlay_name: " . $db->lastErrorMsg()
            );
        }
    }

    // Create view + triggers.
    $view_sql_body = cow_resolve_view_sql(
        $overlay_name, $tomb_name, $parent_table, $pk_cols, $columns
    );
    if (!$db->exec("CREATE VIEW IF NOT EXISTS " . cow_quote_identifier($logical_name) . " AS $view_sql_body")) {
        throw new RuntimeException(
            "failed to create COW view $logical_name: " . $db->lastErrorMsg()
        );
    }
    if (!cow_is_view($db, $logical_name)) {
        throw new RuntimeException("COW logical object $logical_name is not a view");
    }
    // Capture per-column defaults from the overlay so the trigger can
    // COALESCE(NEW.col, default) when the user omits a NOT NULL column.
    $defaults = [];
    $pi = $db->query('PRAGMA table_info("' . SQLite3::escapeString($overlay_name) . '")');
    while ($prow = $pi->fetchArray(SQLITE3_ASSOC)) {
        if ($prow['dflt_value'] !== null) {
            $defaults[$prow['name']] = (string)$prow['dflt_value'];
        }
    }
    // Parent columns for the lazy-ancestor row snapshot (TODO3 #3).
    $parent_cols = cow_table_columns($db, $parent_table);
    // Single-column UNIQUE constraints — used for cross-layer UNIQUE
    // RAISE guards in the INSTEAD OF triggers (TODO3 #10).
    $unique_cols = cow_single_col_unique_columns($db, $overlay_name);
    foreach (['ins', 'upd', 'del'] as $kind) {
        $db->exec('DROP TRIGGER IF EXISTS ' . cow_quote_identifier("{$logical_name}__cow_$kind"));
    }
    foreach (cow_trigger_sql($logical_name, $overlay_name, $tomb_name,
                             $pk_cols, $columns, $defaults,
                             $branch_id, $parent_table, $parent_cols,
                             $unique_cols) as $trg) {
        if (!$db->exec($trg)) {
            throw new RuntimeException(
                "failed to create COW trigger for $logical_name: " . $db->lastErrorMsg()
            );
        }
    }

    // Record the COW marker so merge / introspection can detect format.
    $tok = (string)$db->querySingle(
        "SELECT COALESCE(MAX(rowid), 0) FROM \"" . SQLite3::escapeString($parent_table) . "\""
    );
    $st = $db->prepare(
        "INSERT OR REPLACE INTO db_cow_branches "
      . "(branch_id, table_suffix, parent_branch_id, parent_table_name, fork_token) "
      . "VALUES (:b, :s, :p, :pt, :t)"
    );
    $st->bindValue(':b',  $branch_id,    SQLITE3_INTEGER);
    $st->bindValue(':s',  $table_suffix, SQLITE3_TEXT);
    $st->bindValue(':p',  $parent_id,    SQLITE3_INTEGER);
    $st->bindValue(':pt', $parent_table, SQLITE3_TEXT);
    $st->bindValue(':t',  (string)$tok,  SQLITE3_TEXT);
    $st->execute();

    // Install the O(1) parent-side ancestor-capture trigger (idempotent —
    // one per parent table, shared across all descendants). This replaces
    // the pre-TODO3 per-descendant fanout trigger whose cost scaled
    // linearly with branch count.
    cow_install_parent_triggers($db, $parent_table);

    // Snapshot the fork-time schema (DDL + indexes) for schema-merge.
    // Schema strings are small — this is O(num_tables × DDL size) and
    // doesn't compromise the row-storage savings.
    //
    // Use the LOGICAL name (the view's name) for both the DDL's
    // CREATE TABLE clause AND the (branch_id, table_name) key, so
    // merge.php's lookup-by-source-table-name finds it AND the DDL
    // parser can extract column shapes via PRAGMA on a probe table
    // re-attached from this DDL string.
    $raw_ddl = (string)$db->querySingle(
        "SELECT sql FROM sqlite_master WHERE type='table' AND name='"
        . SQLite3::escapeString($ddl_source) . "'"
    );
    $ancestor_ddl = $raw_ddl !== ''
        ? cow_rewrite_create_table_name($raw_ddl, $ddl_source, $logical_name)
        : '';
    $idxs = [];
    $ix = $db->query(
        "SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='"
        . SQLite3::escapeString($ddl_source) . "' AND sql IS NOT NULL"
    );
    $parent_prefix_for_idx = "b{$parent_id}_wp_";
    $branch_prefix_for_idx = "b{$branch_id}_wp_";
    while ($irow = $ix->fetchArray(SQLITE3_NUM)) {
        // Rewrite the parent-side index DDL to reference the branch's
        // logical names. This keeps schema_normalize_index_ddl producing
        // the SAME normalized key for "ancestor index" and "branch's
        // current index" — so dropping/adding indexes shows up cleanly
        // in the schema diff. Without this rewrite, the ancestor DDL
        // would still embed the parent's prefix, never normalizing to
        // match the branch's prefix-rewritten overlay index.
        $isql = $irow[0];
        // Just str_replace the prefix; the schema-merge normalization will
        // strip it again on both sides. We avoid regex quote/identifier
        // matching here because the DDL may use bare or quoted forms.
        $isql = str_replace($parent_prefix_for_idx, $branch_prefix_for_idx, $isql);
        $idxs[] = $isql;
    }
    $sch = $db->prepare(
        "INSERT OR REPLACE INTO db_snapshots_schema "
      . "(branch_id, table_name, ddl_sql, indexes_json) "
      . "VALUES (:b, :t, :d, :i)"
    );
    $sch->bindValue(':b', $branch_id,    SQLITE3_INTEGER);
    $sch->bindValue(':t', $logical_name, SQLITE3_TEXT);
    $sch->bindValue(':d', $ancestor_ddl ?: $raw_ddl, SQLITE3_TEXT);
    $sch->bindValue(':i', json_encode($idxs, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
    $sch->execute();

    cow_sync_information_schema_for_table($db, $branch_id, $parent_id, $table_suffix);
}

/** Repair COW branch views created without their overlay table.
 *
 *  This is intentionally narrow: it only repairs branches that already have
 *  a COW marker and a missing __overlay table. The repaired branch keeps its
 *  existing inherited view/tombstones and gets a correctly shaped overlay.
 */
function cow_repair_missing_overlays(SQLite3 $db): int {
    $repaired = 0;
    $r = $db->query(
        "SELECT branch_id, parent_branch_id, table_suffix "
      . "FROM db_cow_branches ORDER BY branch_id, table_suffix"
    );
    if (!$r) return 0;

    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $branch_id = (int)$row['branch_id'];
        $parent_id = (int)$row['parent_branch_id'];
        $suffix = (string)$row['table_suffix'];
        if ($branch_id <= 1 || $parent_id <= 0 || $suffix === '') continue;

        $logical = cow_logical_name($branch_id, $suffix);
        $overlay = $logical . '__overlay';
        if (cow_is_table($db, $overlay)) continue;

        cow_create_branch_table($db, $branch_id, $parent_id, $suffix);
        $repaired++;
    }

    return $repaired;
}

/** Recreate views (and triggers) for $table_suffix in every branch whose
 *  view depends on it. Used after a schema-altering operation on a parent
 *  table — `SELECT *` in views is resolved at definition time, so a new
 *  column would otherwise be invisible to descendants.
 *
 *  TODO3 #6: the multi-branch recreation is wrapped in a single
 *  `BEGIN IMMEDIATE … COMMIT` so if any individual branch's view
 *  rebuild fails (locked table, FK violation, malformed CREATE VIEW,
 *  …) the entire batch rolls back. Pre-TODO3 the loop committed
 *  incrementally, so branches 1..k had the new view while k+1..N
 *  kept the old one — a silent half-upgrade no one could detect.
 *
 *  If the caller is already inside a transaction (`in_transaction()`
 *  returns true), we let the caller's outer BEGIN drive the
 *  atomicity and rethrow on error without an extra commit. */
function cow_recreate_views_for_table(SQLite3 $db, string $table_suffix): void {
    // Find every branch whose db_cow_branches row mentions this suffix.
    $rows = [];
    $st = $db->prepare(
        "SELECT branch_id, parent_branch_id, parent_table_name "
      . "FROM db_cow_branches WHERE table_suffix = :s"
    );
    $st->bindValue(':s', $table_suffix, SQLITE3_TEXT);
    $r = $st->execute();
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
    if (empty($rows)) return;

    // A SELECT that returns 0 rows on an inactive tx, errors on an
    // active one — used as a lightweight probe for "are we already
    // inside an outer BEGIN?" so we don't double-nest.
    $own_tx = false;
    $probe = @$db->exec('BEGIN IMMEDIATE');
    if ($probe !== false) {
        $own_tx = true;
    }

    try {
        $failures = [];
        foreach ($rows as $row) {
            try {
                cow_recreate_one_branch_view(
                    $db, $table_suffix,
                    (int)$row['branch_id'], (string)$row['parent_table_name']
                );
            } catch (\Throwable $e) {
                $failures[] = "branch_id=" . (int)$row['branch_id'] . ": "
                            . $e->getMessage();
            }
        }
        if (!empty($failures)) {
            throw new RuntimeException(
                "cow_recreate_views_for_table('$table_suffix'): "
              . count($failures) . " branch(es) failed — "
              . implode('; ', $failures)
            );
        }
        if ($own_tx) $db->exec('COMMIT');
    } catch (\Throwable $e) {
        if ($own_tx) $db->exec('ROLLBACK');
        throw $e;
    }
}

/** Recreate a single branch's view + triggers for $table_suffix.
 *  Split out from cow_recreate_views_for_table so the transactional
 *  wrapper there can run per-branch and collect failures. Throws on
 *  failure — caller aggregates. */
function cow_recreate_one_branch_view(SQLite3 $db, string $table_suffix,
                                      int $bid, string $parent_table): void {
    $logical_name = "b{$bid}_wp_{$table_suffix}";
    $overlay_name = $logical_name . '__overlay';
    $tomb_name    = $logical_name . '__tombstones';

    // Skip branches whose objects don't exist (they may have been deleted).
    if (!cow_is_view($db, $logical_name)) return;

    // Re-derive PK + columns. The overlay is the source of truth for the
    // view's shape: it may have columns the parent doesn't yet, and may be
    // missing columns the parent grew post-fork.
    $pk_cols      = cow_extract_pk_cols($db, $parent_table);
    $parent_cols  = cow_table_columns($db, $parent_table);
    $overlay_cols = cow_table_columns($db, $overlay_name);

    $missing = array_diff($parent_cols, $overlay_cols);
    if (!empty($missing)) {
        $parent_ddl = (string)$db->querySingle(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='"
            . SQLite3::escapeString($parent_table) . "'"
        );
        foreach ($missing as $mcol) {
            $def = cow_extract_col_def($parent_ddl, $mcol);
            if ($def === null) continue;
            @$db->exec('ALTER TABLE "' . $overlay_name . '" ADD COLUMN ' . $def);
        }
        $overlay_cols = cow_table_columns($db, $overlay_name);
    }

    $columns = $overlay_cols;
    if (empty($columns)) return;

    $db->exec("DROP TRIGGER IF EXISTS \"{$logical_name}__cow_ins\"");
    $db->exec("DROP TRIGGER IF EXISTS \"{$logical_name}__cow_upd\"");
    $db->exec("DROP TRIGGER IF EXISTS \"{$logical_name}__cow_del\"");
    $db->exec("DROP VIEW IF EXISTS \"$logical_name\"");

    $view_sql_body = cow_resolve_view_sql_with_projection(
        $overlay_name, $tomb_name, $parent_table,
        $pk_cols, $columns, $parent_cols
    );
    $rc = $db->exec("CREATE VIEW \"$logical_name\" AS $view_sql_body");
    if ($rc === false) {
        throw new RuntimeException("CREATE VIEW failed: " . $db->lastErrorMsg());
    }
    $defaults = [];
    $pi = $db->query('PRAGMA table_info("' . SQLite3::escapeString($overlay_name) . '")');
    while ($prow = $pi->fetchArray(SQLITE3_ASSOC)) {
        if ($prow['dflt_value'] !== null) {
            $defaults[$prow['name']] = (string)$prow['dflt_value'];
        }
    }
    $unique_cols = cow_single_col_unique_columns($db, $overlay_name);
    foreach (cow_trigger_sql($logical_name, $overlay_name, $tomb_name,
                             $pk_cols, $columns, $defaults,
                             $bid, $parent_table, $parent_cols,
                             $unique_cols) as $trg) {
        $rc = $db->exec($trg);
        if ($rc === false) {
            throw new RuntimeException(
                "CREATE TRIGGER failed on $logical_name: " . $db->lastErrorMsg()
            );
        }
    }
}

/** Like cow_resolve_view_sql() but projects NULL for any column the
 *  overlay has that the parent doesn't. Used when the overlay has grown
 *  columns beyond what the parent carries (branch-side ALTER ADD COLUMN). */
function cow_resolve_view_sql_with_projection(
    string $overlay_name, string $tombstone_name, string $parent_view_name,
    array $pk_cols, array $overlay_cols, array $parent_cols
): string {
    $col_list = implode(', ', array_map(fn($c) => '"' . $c . '"', $overlay_cols));
    $pset = array_flip($parent_cols);
    $proj = [];
    foreach ($overlay_cols as $c) {
        if (isset($pset[$c])) $proj[] = 'p."' . $c . '"';
        else                  $proj[] = 'NULL AS "' . $c . '"';
    }
    $proj_list = implode(', ', $proj);

    if (empty($pk_cols)) {
        return "SELECT $col_list FROM \"$overlay_name\" "
             . "UNION ALL "
             . "SELECT $proj_list FROM \"$parent_view_name\" p";
    }
    if (count($pk_cols) === 1) {
        $pk = '"' . $pk_cols[0] . '"';
        return "SELECT $col_list FROM \"$overlay_name\" "
             . "UNION ALL "
             . "SELECT $proj_list FROM \"$parent_view_name\" p "
             . "WHERE p.$pk NOT IN (SELECT $pk FROM \"$overlay_name\") "
             . "  AND p.$pk NOT IN (SELECT $pk FROM \"$tombstone_name\")";
    }
    $pk_tuple_p  = '(' . implode(', ', array_map(fn($c) => 'p."' . $c . '"', $pk_cols)) . ')';
    $pk_sel      = implode(', ', array_map(fn($c) => '"' . $c . '"', $pk_cols));
    return "SELECT $col_list FROM \"$overlay_name\" "
         . "UNION ALL "
         . "SELECT $proj_list FROM \"$parent_view_name\" p "
         . "WHERE $pk_tuple_p NOT IN (SELECT $pk_sel FROM \"$overlay_name\") "
         . "  AND $pk_tuple_p NOT IN (SELECT $pk_sel FROM \"$tombstone_name\")";
}

/** Conservative column-DDL extraction from a CREATE TABLE statement.
 *  Returns the fragment we can hand to ALTER TABLE … ADD COLUMN. */
function cow_extract_col_def(string $ddl, string $col_name): ?string {
    if ($ddl === '') return null;
    if (!preg_match('/^[^(]*\((.*)\)\s*[^)]*$/s', $ddl, $m)) return null;
    $body = $m[1];
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
        if (preg_match('/^"?(' . preg_quote($col_name, '/') . ')"?\s+(.+)$/is', $part, $cm)) {
            $up7 = strtoupper(substr(trim($cm[2]), 0, 7));
            if (in_array(substr($up7, 0, 7), ['PRIMARY', 'FOREIGN'])) continue;
            if (substr($up7, 0, 6) === 'UNIQUE') continue;
            if (substr($up7, 0, 5) === 'CHECK')  continue;
            return '"' . $col_name . '" ' . trim($cm[2]);
        }
    }
    return null;
}

/** Drop the COW trio (view, overlay, tombstone) for one table on a branch. */
function cow_drop_branch_table(SQLite3 $db, int $branch_id, string $table_suffix): void {
    $logical_name = "b{$branch_id}_wp_{$table_suffix}";
    $overlay_name = $logical_name . '__overlay';
    $tomb_name    = $logical_name . '__tombstones';

    $db->exec("DROP TRIGGER IF EXISTS \"{$logical_name}__cow_ins\"");
    $db->exec("DROP TRIGGER IF EXISTS \"{$logical_name}__cow_upd\"");
    $db->exec("DROP TRIGGER IF EXISTS \"{$logical_name}__cow_del\"");
    $db->exec("DROP VIEW IF EXISTS \"$logical_name\"");
    $db->exec("DROP TABLE IF EXISTS \"$overlay_name\"");
    $db->exec("DROP TABLE IF EXISTS \"$tomb_name\"");
    // Remove sqlite_sequence rows for the dropped overlay so they don't
    // accumulate. Tolerate a missing sqlite_sequence table.
    @$db->exec("DELETE FROM sqlite_sequence WHERE name='"
             . SQLite3::escapeString($overlay_name) . "'");
}

/** Migrate an existing legacy (full-copy) branch to COW format in-place.
 *  For each b{bid}_wp_X real table on the branch, compute a per-row diff
 *  vs the parent's CURRENT state and:
 *    - Create the COW trio (overlay + tombstones + view).
 *    - Insert into overlay every row whose value differs from the parent's
 *      same-PK row (the branch's "modifications" + "additions").
 *    - Insert into tombstones every PK present in the parent but missing
 *      in the branch (the branch's "deletions").
 *    - Drop the old real table.
 *
 *  Limited to single-PK and composite-PK tables that we can match by PK.
 *  Returns the number of tables migrated. */
function cow_migrate_legacy_branch(SQLite3 $db, int $branch_id): int {
    $parent_id = cow_parent_branch_id($db, $branch_id);
    if ($parent_id <= 0) return 0; // no parent: nothing to COW against

    $prefix = "b{$branch_id}_wp_";
    $tables = [];
    $r = $db->query(
        "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '"
        . SQLite3::escapeString($prefix) . "%' "
        . "AND name NOT LIKE '%\\_\\_overlay' ESCAPE '\\' "
        . "AND name NOT LIKE '%\\_\\_tombstones' ESCAPE '\\'"
    );
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $name = (string)$row['name'];
        // Skip overlay / tombstone artifact names just in case.
        if (str_ends_with($name, '__overlay') || str_ends_with($name, '__tombstones')) continue;
        $tables[] = $name;
    }

    $migrated = 0;
    foreach ($tables as $branch_table) {
        $suffix = substr($branch_table, strlen($prefix));
        $parent_table = "b{$parent_id}_wp_{$suffix}";
        // If parent doesn't have the corresponding table/view, skip — this
        // is a branch-only table; just leave it alone.
        if (!cow_is_table($db, $parent_table) && !cow_is_view($db, $parent_table)) continue;

        $pk_cols = cow_extract_pk_cols($db, $branch_table);
        $columns = cow_table_columns($db, $branch_table);

        // Snapshot the branch's current rows BEFORE we drop the table.
        $branch_rows = [];
        $rs = $db->query('SELECT * FROM "' . SQLite3::escapeString($branch_table) . '"');
        while ($row = $rs->fetchArray(SQLITE3_ASSOC)) {
            if (empty($pk_cols)) {
                $key = json_encode($row, JSON_UNESCAPED_UNICODE);
            } else {
                $pk_map = [];
                foreach ($pk_cols as $c) $pk_map[$c] = $row[$c] ?? null;
                $key = json_encode($pk_map, JSON_UNESCAPED_UNICODE);
            }
            $branch_rows[$key] = $row;
        }

        // Snapshot parent's current rows for diffing.
        $parent_rows = [];
        $rp = $db->query('SELECT * FROM "' . SQLite3::escapeString($parent_table) . '"');
        while ($row = $rp->fetchArray(SQLITE3_ASSOC)) {
            if (empty($pk_cols)) {
                $key = json_encode($row, JSON_UNESCAPED_UNICODE);
            } else {
                $pk_map = [];
                foreach ($pk_cols as $c) $pk_map[$c] = $row[$c] ?? null;
                $key = json_encode($pk_map, JSON_UNESCAPED_UNICODE);
            }
            $parent_rows[$key] = $row;
        }

        // DROP the real table to make room for the view.
        $db->exec('DROP TABLE "' . SQLite3::escapeString($branch_table) . '"');

        // Create COW trio.
        cow_create_branch_table($db, $branch_id, $parent_id, $suffix);

        $logical_name = $branch_table; // same name; now a view
        $overlay_name = $logical_name . '__overlay';
        $tomb_name    = $logical_name . '__tombstones';

        // If the legacy branch had columns the parent lacked (branch-side
        // schema evolution), the freshly-created overlay (built from the
        // parent's DDL) will be missing those columns. Sync them now, and
        // rebuild the view/triggers to expose them.
        $overlay_cols = cow_table_columns($db, $overlay_name);
        $missing_on_overlay = array_diff($columns, $overlay_cols);
        if (!empty($missing_on_overlay)) {
            $branch_ddl = (string)$db->querySingle(
                "SELECT sql FROM sqlite_master WHERE type='table' AND name='"
              . SQLite3::escapeString($branch_table . '__stash_for_ddl') . "'"
            );
            // The branch_table was just dropped; re-derive column types from
            // the $branch_rows structure (all-TEXT fallback is fine for the
            // legacy case — rows still round-trip).
            foreach ($missing_on_overlay as $mcol) {
                @$db->exec('ALTER TABLE "' . $overlay_name . '" ADD COLUMN "'
                         . $mcol . '" TEXT');
            }
            // Rebuild view + triggers to pick up the new columns.
            cow_recreate_views_for_table($db, $suffix);
        }

        // Compute the diff. Anything in branch_rows that differs from
        // parent_rows[same key] (or has no parent counterpart) → overlay.
        $col_list = implode(', ', array_map(fn($c) => '"' . $c . '"', $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $insert_overlay = $db->prepare(
            "INSERT OR REPLACE INTO \"$overlay_name\" ($col_list) VALUES ($placeholders)"
        );
        if ($insert_overlay === false) {
            throw new RuntimeException(
                "cow_migrate_legacy_branch: could not prepare overlay INSERT for "
              . "$overlay_name (columns: " . implode(', ', $columns) . "): "
              . $db->lastErrorMsg()
            );
        }
        foreach ($branch_rows as $key => $row) {
            $parent_row = $parent_rows[$key] ?? null;
            if ($parent_row !== null && $parent_row == $row) continue; // identical → no overlay needed
            $i = 1;
            foreach ($columns as $c) {
                $v = $row[$c] ?? null;
                $type = match (true) {
                    $v === null  => SQLITE3_NULL,
                    is_int($v)   => SQLITE3_INTEGER,
                    is_float($v) => SQLITE3_FLOAT,
                    default      => SQLITE3_TEXT,
                };
                $insert_overlay->bindValue($i++, $v, $type);
            }
            $insert_overlay->execute();
            $insert_overlay->reset();
        }

        // Tombstones: parent rows with no branch counterpart.
        if (!empty($pk_cols)) {
            $tomb_col_list = implode(', ', array_map(fn($c) => '"' . $c . '"', $pk_cols));
            $tomb_ph = implode(', ', array_fill(0, count($pk_cols), '?'));
            $insert_tomb = $db->prepare(
                "INSERT OR REPLACE INTO \"$tomb_name\" ($tomb_col_list) VALUES ($tomb_ph)"
            );
            foreach ($parent_rows as $key => $prow) {
                if (isset($branch_rows[$key])) continue;
                $i = 1;
                foreach ($pk_cols as $c) {
                    $v = $prow[$c] ?? null;
                    $type = match (true) {
                        $v === null  => SQLITE3_NULL,
                        is_int($v)   => SQLITE3_INTEGER,
                        is_float($v) => SQLITE3_FLOAT,
                        default      => SQLITE3_TEXT,
                    };
                    $insert_tomb->bindValue($i++, $v, $type);
                }
                $insert_tomb->execute();
                $insert_tomb->reset();
            }
        }
        $migrated++;
    }
    return $migrated;
}

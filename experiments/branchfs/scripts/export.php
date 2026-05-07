<?php
/**
 * forkpress export — extract a site.fp into a portable directory tree.
 *
 * Output layout:
 *   <output-dir>/
 *     manifest.json                     — site metadata + branch topology
 *     branches/<name>/files/…           — resolved file tree for this branch
 *     branches/<name>/db.sql            — SQL dump of b{id}_wp_* tables
 *
 * The export is self-describing: `forkpress import <output-dir> <new.fp>`
 * reconstructs an equivalent `.fp` (same branches, same parent chain,
 * same file content, same DB tables).
 *
 * Usage: php export.php <site.fp> <output-dir>
 */

require_once dirname(__DIR__, 3) . '/scripts/shared/sqlite_retry.php';

if ($argc < 3) {
    fwrite(STDERR, "Usage: php export.php <site.fp> <output-dir>\n");
    exit(1);
}

[$_, $src, $out_dir] = $argv;

if (!file_exists($src)) {
    fwrite(STDERR, "export: source not found: $src\n");
    exit(2);
}
if (is_dir($out_dir) && (new \FilesystemIterator($out_dir))->valid()) {
    fwrite(STDERR, "export: refusing to write into non-empty directory: $out_dir\n");
    exit(2);
}
if (!is_dir($out_dir) && !@mkdir($out_dir, 0755, true)) {
    fwrite(STDERR, "export: cannot create $out_dir\n");
    exit(2);
}

$db = new SQLite3($src, SQLITE3_OPEN_READONLY);
$db->busyTimeout(15000);

// Collect branches in topological order (roots first, then children).
$branches = [];
$r = $db->query("SELECT id, name, parent_branch, created_at FROM branches");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    $branches[$row['name']] = $row;
}

function topo_sort(array $branches): array {
    $sorted = [];
    $placed = [];
    $remaining = $branches;
    while (!empty($remaining)) {
        $progress = false;
        foreach ($remaining as $name => $b) {
            $parent = $b['parent_branch'];
            if ($parent === null || isset($placed[$parent])) {
                $sorted[] = $b;
                $placed[$name] = true;
                unset($remaining[$name]);
                $progress = true;
            }
        }
        if (!$progress) {
            // Cycle or missing parent: append remaining best-effort.
            foreach ($remaining as $b) $sorted[] = $b;
            break;
        }
    }
    return $sorted;
}

$branches_sorted = topo_sort($branches);

/** Resolve the branch's full effective file tree (walk parent chain). */
function export_resolve_tree(SQLite3 $db, int $branch_id): array {
    $tree = [];
    $tomb = [];
    $bid = $branch_id;
    while ($bid > 0) {
        $r = $db->query("SELECT path, blob_hash, is_dir FROM files WHERE branch_id = $bid");
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $p = $row['path'];
            if (isset($tree[$p]) || isset($tomb[$p])) continue;
            if (!$row['blob_hash'] && !$row['is_dir']) {
                $tomb[$p] = true;
                continue;
            }
            $tree[$p] = $row;
        }
        $parent_id = $db->querySingle(
            "SELECT b2.id FROM branches b1 JOIN branches b2 "
          . "ON b1.parent_branch = b2.name WHERE b1.id = $bid"
        );
        $bid = (int)($parent_id ?: 0);
    }
    return $tree;
}

/** SQL dump of all b{bid}_wp_* tables.
 *
 *  COW awareness: a non-main branch's b{id}_wp_X is now a VIEW backed by
 *  an overlay table. To produce a self-contained dump we:
 *    - emit the LOGICAL CREATE TABLE statement (the overlay's DDL, with
 *      the table name rewritten to the logical name)
 *    - SELECT * from the VIEW (so inherited rows appear in the export)
 *    - dump non-PK indexes from the overlay (rewritten to point at the
 *      logical name)
 *  The result is byte-equivalent to a dump of the legacy row-copy format
 *  — `forkpress import` can replay it without knowing about COW. */
function export_dump_db(SQLite3 $db, int $branch_id): string {
    $prefix = "b{$branch_id}_wp_";
    // Real tables (legacy format, or main): logical = table itself.
    $tables = [];
    $r = $db->query("SELECT name, sql FROM sqlite_master "
        . "WHERE type='table' AND name LIKE '"
        . SQLite3::escapeString($prefix) . "%' "
        . "AND name NOT LIKE '%\\_\\_overlay' ESCAPE '\\' "
        . "AND name NOT LIKE '%\\_\\_tombstones' ESCAPE '\\'");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $tables[$row['name']] = ['name' => $row['name'], 'sql' => $row['sql'], 'physical' => $row['name']];
    }
    // COW views: logical name is the view; physical (for DDL/indexes) is the overlay.
    $r = $db->query("SELECT name FROM sqlite_master WHERE type='view' AND name LIKE '"
        . SQLite3::escapeString($prefix) . "%'");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $logical = $row['name'];
        $overlay = $logical . '__overlay';
        $overlay_sql = (string)$db->querySingle(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='"
            . SQLite3::escapeString($overlay) . "'"
        );
        $logical_sql = preg_replace(
            '/^(CREATE\s+TABLE\s+)(?:IF\s+NOT\s+EXISTS\s+)?"?'
            . preg_quote($overlay, '/') . '"?/is',
            '$1IF NOT EXISTS "' . $logical . '"',
            $overlay_sql, 1
        );
        $tables[$logical] = ['name' => $logical, 'sql' => $logical_sql ?: $overlay_sql, 'physical' => $overlay];
    }

    // Collect indexes from physical tables, rewriting their ON clause to the
    // logical name (so the export-then-import path produces a real table
    // with real indexes — not COW machinery).
    $indexes = [];
    foreach ($tables as $t) {
        $ir = $db->query(
            "SELECT name, sql FROM sqlite_master WHERE type='index' AND tbl_name='"
          . SQLite3::escapeString($t['physical']) . "' AND sql IS NOT NULL"
        );
        while ($row = $ir->fetchArray(SQLITE3_ASSOC)) {
            $isql = $row['sql'];
            if ($t['physical'] !== $t['name']) {
                $isql = preg_replace(
                    '/\bON\s+"?' . preg_quote($t['physical'], '/') . '"?\s*\(/i',
                    'ON "' . $t['name'] . '" (',
                    $isql, 1
                );
            }
            $indexes[] = ['name' => $row['name'], 'sql' => $isql];
        }
    }

    $out = "-- ForkPress DB export for branch_id=$branch_id (prefix=$prefix)\n";
    $out .= "-- Generated by experiments/branchfs/scripts/export.php\n\n";
    foreach ($tables as $t) {
        if (!$t['sql']) continue;
        $out .= $t['sql'] . ";\n";
        // SELECT from the LOGICAL name so inherited rows are included
        // (the view does the UNION ALL transparently).
        $rr = $db->query('SELECT * FROM "' . SQLite3::escapeString($t['name']) . '"');
        while ($row = $rr->fetchArray(SQLITE3_ASSOC)) {
            $cols = array_keys($row);
            $vals = array_map(function ($v) {
                if ($v === null) return 'NULL';
                if (is_int($v) || is_float($v)) return (string)$v;
                return "'" . SQLite3::escapeString((string)$v) . "'";
            }, array_values($row));
            $out .= 'INSERT INTO "' . $t['name'] . '" ('
                . implode(', ', array_map(fn($c) => '"' . $c . '"', $cols))
                . ') VALUES (' . implode(', ', $vals) . ");\n";
        }
    }
    foreach ($indexes as $i) $out .= $i['sql'] . ";\n";
    return $out;
}

$manifest = [
    'format_version' => 1,
    'generated_at'   => date('c'),
    'source_file'    => basename($src),
    'branches'       => [],
];

// site_config, if present
$has_site_config = (bool)$db->querySingle(
    "SELECT name FROM sqlite_master WHERE type='table' AND name='site_config'"
);
if ($has_site_config) {
    $cfg = [];
    $r = $db->query("SELECT key, value FROM site_config");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $cfg[$row['key']] = $row['value'];
    $manifest['site_config'] = $cfg;
}

foreach ($branches_sorted as $b) {
    $bname  = $b['name'];
    $branch_dir = "$out_dir/branches/$bname";
    @mkdir("$branch_dir/files", 0755, true);

    // Resolve files
    $tree = export_resolve_tree($db, (int)$b['id']);
    $file_count = 0;
    foreach ($tree as $path => $entry) {
        if ($entry['is_dir']) {
            @mkdir("$branch_dir/files/$path", 0755, true);
            continue;
        }
        $hash = $entry['blob_hash'];
        $content = $db->querySingle(
            "SELECT data FROM blobs WHERE hash = '" . SQLite3::escapeString($hash) . "'",
            false
        );
        $target = "$branch_dir/files/$path";
        $parent = dirname($target);
        if (!is_dir($parent)) @mkdir($parent, 0755, true);
        file_put_contents($target, $content);
        $file_count++;
    }

    // Dump DB tables
    $sql = export_dump_db($db, (int)$b['id']);
    file_put_contents("$branch_dir/db.sql", $sql);

    $manifest['branches'][] = [
        'name'          => $bname,
        'parent_branch' => $b['parent_branch'],
        'created_at'    => $b['created_at'],
        'file_count'    => $file_count,
    ];

    echo "  exported branch '$bname' ($file_count files)\n";
}

file_put_contents("$out_dir/manifest.json",
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$db->close();
echo "\nexport: " . count($manifest['branches']) . " branch(es) written to $out_dir\n";

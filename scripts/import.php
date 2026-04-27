<?php
/**
 * forkpress import — rebuild a .fp from the directory tree produced by
 * scripts/export.php.
 *
 * Steps:
 *   1. init_db.php creates a fresh .fp with schema + seeded 'main' branch.
 *   2. Read manifest.json; for each non-main branch, run branchfs_create_
 *      branch(...) in topological order (parents first).
 *   3. For each branch, write every file under branches/<name>/files/…
 *      via the branchfs:// stream wrapper.
 *   4. Apply branches/<name>/db.sql to restore b{id}_wp_* tables.
 *
 * Usage: php scripts/import.php <export-dir> <new.fp>
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php import.php <export-dir> <new.fp>\n");
    exit(1);
}

[$_, $src_dir, $dst] = $argv;

if (!is_dir($src_dir)) {
    fwrite(STDERR, "import: export directory not found: $src_dir\n");
    exit(2);
}
if (!file_exists("$src_dir/manifest.json")) {
    fwrite(STDERR, "import: manifest.json missing in $src_dir\n");
    exit(2);
}
if (file_exists($dst)) {
    fwrite(STDERR, "import: refusing to overwrite existing file: $dst\n");
    exit(2);
}
if (!extension_loaded('branchfs')) {
    fwrite(STDERR, "import: branchfs extension not loaded\n");
    exit(2);
}

$manifest = json_decode(file_get_contents("$src_dir/manifest.json"), true);
if (!is_array($manifest) || !isset($manifest['branches'])) {
    fwrite(STDERR, "import: manifest.json malformed\n");
    exit(2);
}

// Step 1: init a fresh .fp (creates schema + seeds 'main').
$init_cmd = sprintf(
    '%s %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg(__DIR__ . '/init_db.php'),
    escapeshellarg($dst)
);
$rc = 0; $out = [];
exec($init_cmd, $out, $rc);
if ($rc !== 0) {
    fwrite(STDERR, "import: init_db.php failed:\n" . implode("\n", $out) . "\n");
    exit(3);
}

branchfs_set_db($dst);
$db = new SQLite3($dst, SQLITE3_OPEN_READWRITE);
$db->busyTimeout(15000);

/** Reinsert restored rows into a branch's DB tables. */
function restore_db_sql(SQLite3 $db, string $sql, int $bid, string $old_prefix_guess = 'b\\d+_wp_'): void {
    // Translate any b{N}_wp_<suffix> reference in the SQL to this branch's
    // current prefix b{bid}_wp_<suffix>. The export wrote whatever prefix
    // the source used; import may assign a different id to the same branch.
    $new_prefix = "b{$bid}_wp_";
    $rewritten = preg_replace("/b\\d+_wp_/", $new_prefix, $sql);
    $db->exec($rewritten);
}

/** Write every file under $dir into the given branch via branchfs://. */
function import_files(string $branch, string $dir): int {
    if (!is_dir($dir)) return 0;
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
    );
    $count = 0;
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($dir) + 1);
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
        $url = "branchfs://$branch/$rel";
        if ($f->isDir()) {
            @mkdir($url);
            continue;
        }
        $bytes = @file_get_contents($f->getPathname());
        if ($bytes === false) continue;
        if (@file_put_contents($url, $bytes) === false) {
            fwrite(STDERR, "import: could not write $url\n");
            continue;
        }
        $count++;
    }
    return $count;
}

// Step 2+3: recreate branches in topological order (manifest is already sorted).
$created = 0;
foreach ($manifest['branches'] as $b) {
    $bname = $b['name'];
    if ($bname !== 'main') {
        $parent = $b['parent_branch'] ?? 'main';
        branchfs_create_branch($bname, $parent);
        $created++;
    }

    $files_dir = "$src_dir/branches/$bname/files";
    $n = import_files($bname, $files_dir);
    echo "  imported branch '$bname' ($n files)\n";

    $bid = (int)$db->querySingle(
        "SELECT id FROM branches WHERE name = '" . SQLite3::escapeString($bname) . "'"
    );
    $sql_file = "$src_dir/branches/$bname/db.sql";
    if (file_exists($sql_file) && $bid > 0) {
        $sql = file_get_contents($sql_file);
        restore_db_sql($db, $sql, $bid);
    }
}

// site_config
if (isset($manifest['site_config']) && is_array($manifest['site_config'])) {
    $db->exec("CREATE TABLE IF NOT EXISTS site_config (key TEXT PRIMARY KEY, value TEXT)");
    $s = $db->prepare("INSERT OR REPLACE INTO site_config (key, value) VALUES (:k, :v)");
    foreach ($manifest['site_config'] as $k => $v) {
        $s->bindValue(':k', $k, SQLITE3_TEXT);
        $s->bindValue(':v', (string)$v, SQLITE3_TEXT);
        $s->execute();
        $s->reset();
    }
}

$db->close();
echo "\nimport: rebuilt $dst (" . count($manifest['branches']) . " branches, $created newly created)\n";

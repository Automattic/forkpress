<?php
/**
 * Import a WordPress installation directory tree into BranchFS SQLite store.
 *
 * Usage: php import_wp.php <wp-root-dir> [db-path] [branch]
 */

if ($argc < 2) {
    die("Usage: php import_wp.php <wp-root-dir> [db-path] [branch]\n");
}

$wp_dir   = rtrim($argv[1], '/');
$db_path  = $argv[2] ?? __DIR__ . '/../branchfs.db';
$branch   = $argv[3] ?? 'main';

if (!is_dir($wp_dir)) {
    die("import_wp:$wp_dir is not a directory\n");
}

if (!extension_loaded('branchfs')) {
    die("import_wp:branchfs extension not loaded. Use: php -d extension=experiments/php-ext-branchfs/branchfs.so\n");
}

branchfs_set_db($db_path);

$branch_id = branchfs_create_branch($branch, $branch === 'main' ? null : 'main');
if ($branch_id === false) {
    die("import_wp:Could not create/find branch '$branch'\n");
}

echo "Importing $wp_dir into branch '$branch' (id=$branch_id)...\n";

$count = 0;
$errors = 0;
$dir_count = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($wp_dir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $file) {
    $real_path = $file->getPathname();
    $rel_path = substr($real_path, strlen($wp_dir) + 1);

    if ($file->isDir()) {
        branchfs_import_dir($branch, $rel_path);
        $dir_count++;
    } elseif ($file->isFile()) {
        if (branchfs_import_file($real_path, $branch, $rel_path)) {
            $count++;
        } else {
            echo "  WARN: failed to import $rel_path\n";
            $errors++;
        }
    }

    if (($count + $dir_count) % 500 === 0) {
        echo "  ... imported $count files, $dir_count dirs\n";
    }
}

echo "Done: $count files, $dir_count directories imported";
if ($errors) echo " ($errors errors)";
echo "\n";

<?php
/**
 * Git smart-HTTP server for ForkPress COW branches.
 *
 * COW branch directories are the runtime source of truth. This adapter keeps a
 * persistent Git object store under .forkpress/cow/git, snapshots each branch
 * directory into that Git store before clone/fetch/push, and applies pushed
 * wordpress/ file changes back to the materialized branch directory.
 *
 * database.sql is generated from the branch-local SQLite database for context
 * and is ignored on push.
 */

require_once __DIR__ . '/autoload.php';

use WordPress\Filesystem\LocalFilesystem;
use WordPress\Git\GitEndpoint;
use WordPress\Git\GitRepository;
use WordPress\Git\Model\Commit;
use WordPress\Git\Model\TreeEntry;
use WordPress\HttpServer\Response\StreamingResponseWriter;

function cow_git_server_handle(string $branches_dir, string $git_repo_dir, string $git_path, string $query_string): void {
    ini_set('memory_limit', '768M');
    set_time_limit(300);

    $branches_dir = rtrim($branches_dir, "/\\");
    if (!is_dir($branches_dir)) {
        http_response_code(500);
        echo "COW branch directory not found\n";
        return;
    }

    cow_git_mkdir(dirname($git_repo_dir));
    $lock_path = $git_repo_dir . '.lock';
    $lock = fopen($lock_path, 'c');
    if (!$lock) {
        http_response_code(500);
        echo "Cannot open COW git lock\n";
        return;
    }
    if (!flock($lock, LOCK_EX)) {
        http_response_code(500);
        echo "Cannot lock COW git store\n";
        return;
    }

    try {
        cow_git_mkdir($git_repo_dir);
        $fs = LocalFilesystem::create($git_repo_dir);
        $repo = new GitRepository($fs, ['default_branch' => 'main']);
        $repo->set_config_value(['user', 'name'], 'ForkPress COW');
        $repo->set_config_value(['user', 'email'], 'forkpress-cow@local');

        cow_git_sync_repository($repo, $branches_dir);

        $endpoint_path = $git_path;
        if ($query_string) {
            $endpoint_path .= '?' . $query_string;
        }

        $is_post_receive = ($git_path === '/git-receive-pack');
        if ($is_post_receive) {
            ob_start();
        }

        $request_bytes = file_get_contents('php://input');
        $response = new StreamingResponseWriter();
        try {
            $endpoint = new GitEndpoint($repo);
            $endpoint->handle_request($endpoint_path, $request_bytes, $response);
        } catch (\Throwable $e) {
            error_log("COW git server error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            throw $e;
        }

        if ($is_post_receive) {
            try {
                cow_git_apply_all_refs_to_branches($repo, $git_repo_dir, $branches_dir);
            } catch (\Throwable $e) {
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                error_log("COW push processing error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                http_response_code(500);
                header('Content-Type: text/plain');
                $short = substr(str_replace(["\n", "\r"], ' ', $e->getMessage()), 0, 500);
                echo "forkpress cow: push rejected: $short\n";
                return;
            }
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
        }
    } catch (\Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain');
        echo "forkpress cow git error: " . $e->getMessage() . "\n";
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function cow_git_sync_repository(GitRepository $repo, string $branches_dir): void {
    $branches = cow_git_branch_names($branches_dir);
    foreach ($branches as $branch) {
        $branch_root = $branches_dir . '/' . $branch;
        $ref = "refs/heads/$branch";
        if (!$repo->branch_exists($ref)) {
            $repo->set_branch_tip($ref, Commit::NULL_HASH);
        }
        $repo->checkout($ref);

        $updates = cow_git_collect_updates($branch_root);
        $updates['database.sql'] = cow_git_dump_branch_database($branch_root, $branch);

        $tip = $repo->get_branch_tip($ref);
        $deletes = [];
        $parents = [];
        if (!Commit::is_null_hash($tip) && $repo->has_object($tip)) {
            $parents = [$tip];
            foreach (cow_git_list_commit_paths($repo, $tip) as $path) {
                if (!isset($updates[$path])) {
                    $deletes[] = $path;
                }
            }
        }

        $timestamp = cow_git_branch_timestamp($branch_root);
        $date = $timestamp . ' +0000';
        $head = $repo->commit([
            'commit' => [
                'message' => "ForkPress COW snapshot for $branch",
                'author' => 'ForkPress COW <forkpress-cow@local>',
                'author_date' => $date,
                'committer' => 'ForkPress COW <forkpress-cow@local>',
                'committer_date' => $date,
                'parents' => $parents,
            ],
            'updates' => $updates,
            'deletes' => $deletes,
        ]);
        $repo->set_branch_tip($ref, $head);
    }
    $repo->set_branch_tip('HEAD', "ref: refs/heads/main\n");
}

function cow_git_apply_all_refs_to_branches(GitRepository $repo, string $git_repo_dir, string $branches_dir): void {
    $refs_dir = rtrim($git_repo_dir, "/\\") . '/refs/heads';
    if (!is_dir($refs_dir)) {
        return;
    }

    foreach (scandir($refs_dir) as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (!cow_git_valid_branch_name($name)) {
            throw new \RuntimeException("refusing to apply invalid branch name '$name'");
        }
        $tip = trim((string)file_get_contents($refs_dir . '/' . $name));
        if ($tip === '' || Commit::is_null_hash($tip)) {
            continue;
        }

        $branch_root = rtrim($branches_dir, "/\\") . '/' . $name;
        if (!is_dir($branch_root)) {
            throw new \RuntimeException(
                "branch '$name' does not exist; create it first with `forkpress branch create $name`"
            );
        }

        $all_files = [];
        cow_git_walk_tree($repo, $repo->read_object($tip)->as_commit()->tree, '', $all_files);
        $wp_files = [];
        foreach ($all_files as $path => $blob_hash) {
            if (strncmp($path, 'wordpress/', 10) !== 0) {
                continue;
            }
            $rel = substr($path, 10);
            if ($rel === '' || !cow_git_should_export_relative_path($rel)) {
                continue;
            }
            $wp_files[$rel] = $blob_hash;
        }
        cow_git_apply_wp_files($repo, $branch_root, $wp_files);
    }
}

function cow_git_collect_updates(string $branch_root): array {
    $updates = [];
    foreach (cow_git_collect_branch_files($branch_root) as $rel => $_path) {
        $updates['wordpress/' . $rel] = file_get_contents($branch_root . '/' . $rel);
    }
    ksort($updates);
    return $updates;
}

function cow_git_collect_branch_files(string $branch_root): array {
    $files = [];
    if (!is_dir($branch_root)) {
        return $files;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($branch_root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $entry) {
        if (!$entry->isFile()) {
            continue;
        }
        $path = $entry->getPathname();
        $rel = str_replace('\\', '/', substr($path, strlen(rtrim($branch_root, "/\\")) + 1));
        if (!cow_git_should_export_relative_path($rel)) {
            continue;
        }
        $files[$rel] = $path;
    }
    ksort($files);
    return $files;
}

function cow_git_should_export_relative_path(string $rel): bool {
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    if ($rel === '' || $rel === 'database.sql') {
        return false;
    }
    if (str_contains($rel, '/../') || str_starts_with($rel, '../')) {
        return false;
    }
    if (preg_match('#(^|/)\\.git(/|$)#', $rel)) {
        return false;
    }
    if (preg_match('#^wp-content/database/\\.ht\\.sqlite(?:-(?:wal|shm))?$#', $rel)) {
        return false;
    }
    return true;
}

function cow_git_apply_wp_files(GitRepository $repo, string $branch_root, array $wp_files): void {
    foreach ($wp_files as $rel => $blob_hash) {
        $target = $branch_root . '/' . $rel;
        $parent = dirname($target);
        cow_git_mkdir($parent);
        file_put_contents($target, $repo->read_object($blob_hash)->consume_all());
    }

    $current = cow_git_collect_branch_files($branch_root);
    foreach ($current as $rel => $path) {
        if (!isset($wp_files[$rel]) && is_file($path)) {
            unlink($path);
        }
    }
    cow_git_remove_empty_dirs($branch_root);
}

function cow_git_remove_empty_dirs(string $root): void {
    if (!is_dir($root)) {
        return;
    }
    $dirs = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        if ($entry->isDir()) {
            $dir = $entry->getPathname();
            $rel = str_replace('\\', '/', substr($dir, strlen(rtrim($root, "/\\")) + 1));
            if ($rel === 'wp-content/database' || str_starts_with($rel, 'wp-content/database/')) {
                continue;
            }
            $dirs[] = $dir;
        }
    }
    foreach ($dirs as $dir) {
        @rmdir($dir);
    }
}

function cow_git_list_commit_paths(GitRepository $repo, string $commit_hash): array {
    $paths = [];
    if (Commit::is_null_hash($commit_hash)) {
        return $paths;
    }
    $commit = $repo->read_object($commit_hash)->as_commit();
    cow_git_walk_tree($repo, $commit->tree, '', $paths);
    return array_keys($paths);
}

function cow_git_walk_tree(GitRepository $repo, string $tree_hash, string $prefix, array &$result): void {
    $tree = $repo->read_object($tree_hash)->as_tree();
    foreach ($tree->entries as $name => $entry) {
        $full_path = $prefix === '' ? $name : $prefix . '/' . $name;
        if ($entry->mode === '40000' || $entry->mode === TreeEntry::FILE_MODE_DIRECTORY) {
            cow_git_walk_tree($repo, $entry->hash, $full_path, $result);
        } else {
            $result[$full_path] = $entry->hash;
        }
    }
}

function cow_git_dump_branch_database(string $branch_root, string $branch): string {
    $db_path = $branch_root . '/wp-content/database/.ht.sqlite';
    $out = "-- ForkPress database snapshot for COW branch " . cow_git_sql_comment($branch) . "\n";
    $out .= "-- This file is read-only in git; push WordPress file changes from wordpress/.\n";
    $out .= "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n\n";

    if (!is_file($db_path)) {
        return $out . "COMMIT;\n";
    }

    $db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(5000);
    $tables = [];
    $result = $db->query(
        "SELECT name, sql FROM sqlite_master
         WHERE type='table'
           AND name LIKE 'wp\\_%' ESCAPE '\\'
           AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'
         ORDER BY name"
    );
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $tables[] = $row;
    }

    foreach ($tables as $table) {
        $name = (string)$table['name'];
        $sql = trim((string)$table['sql']);
        if ($sql === '') {
            continue;
        }
        $out .= 'DROP TABLE IF EXISTS ' . cow_git_sql_ident($name) . ";\n";
        $out .= $sql . ";\n";

        $columns = cow_git_table_columns($db, $name);
        if (!$columns) {
            $out .= "\n";
            continue;
        }
        $select = $db->query('SELECT * FROM ' . cow_git_sql_ident($name));
        while ($select && ($data = $select->fetchArray(SQLITE3_ASSOC))) {
            $names = [];
            $values = [];
            foreach ($columns as $column) {
                $col = (string)$column['name'];
                $names[] = cow_git_sql_ident($col);
                $values[] = cow_git_sql_literal($data[$col] ?? null);
            }
            $out .= 'INSERT INTO ' . cow_git_sql_ident($name)
                . ' (' . implode(', ', $names) . ') VALUES ('
                . implode(', ', $values) . ");\n";
        }
        $out .= "\n";
    }
    $db->close();

    $out .= "COMMIT;\n";
    return $out;
}

function cow_git_table_columns(SQLite3 $db, string $table): array {
    $columns = [];
    $result = $db->query('PRAGMA table_info(' . cow_git_sql_ident($table) . ')');
    while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
        $columns[] = $row;
    }
    return $columns;
}

function cow_git_sql_ident(string $identifier): string {
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function cow_git_sql_literal($value): string {
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }
    $value = (string)$value;
    if (str_contains($value, "\0")) {
        return "X'" . bin2hex($value) . "'";
    }
    return "'" . SQLite3::escapeString($value) . "'";
}

function cow_git_sql_comment(string $text): string {
    return str_replace(["\r", "\n"], ' ', $text);
}

function cow_git_branch_timestamp(string $branch_root): int {
    $latest = 0;
    foreach (cow_git_collect_branch_files($branch_root) as $_rel => $path) {
        $mtime = @filemtime($path);
        if ($mtime !== false && $mtime > $latest) {
            $latest = $mtime;
        }
    }
    $db_path = $branch_root . '/wp-content/database/.ht.sqlite';
    $db_mtime = @filemtime($db_path);
    if ($db_mtime !== false && $db_mtime > $latest) {
        $latest = $db_mtime;
    }
    return $latest > 0 ? $latest : 1;
}

function cow_git_branch_names(string $branches_dir): array {
    $names = [];
    foreach (scandir($branches_dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (!cow_git_valid_branch_name($entry)) {
            continue;
        }
        $root = rtrim($branches_dir, "/\\") . '/' . $entry;
        if (is_dir($root) && is_file($root . '/wp-load.php')) {
            $names[] = $entry;
        }
    }
    usort($names, function($a, $b) {
        if ($a === 'main') return -1;
        if ($b === 'main') return 1;
        return strcmp($a, $b);
    });
    return $names;
}

function cow_git_valid_branch_name(string $branch): bool {
    return (bool)preg_match('/^[A-Za-z0-9_-]{1,63}$/', $branch);
}

function cow_git_copy_tree(string $source, string $dest): void {
    if (!is_dir($source)) {
        throw new \RuntimeException("source directory not found: $source");
    }
    if (file_exists($dest)) {
        throw new \RuntimeException("destination already exists: $dest");
    }
    cow_git_mkdir($dest);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $entry) {
        $target = $dest . '/' . str_replace('\\', '/', substr($entry->getPathname(), strlen(rtrim($source, "/\\")) + 1));
        if ($entry->isDir()) {
            cow_git_mkdir($target);
        } elseif ($entry->isFile()) {
            cow_git_mkdir(dirname($target));
            copy($entry->getPathname(), $target);
        }
    }
}

function cow_git_mkdir(string $path): void {
    if ($path === '' || is_dir($path)) {
        return;
    }
    if (!mkdir($path, 0755, true) && !is_dir($path)) {
        throw new \RuntimeException("failed to create directory $path");
    }
}

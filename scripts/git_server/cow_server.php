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
use WordPress\Git\Protocol\GitProtocolEncoderPipe;
use WordPress\HttpServer\Response\StreamingResponseWriter;

function cow_git_server_handle(
    string $branches_dir,
    string $git_repo_dir,
    string $git_path,
    string $query_string,
    ?string $storage_branches_dir = null,
    ?string $branch_list_path = null,
    string $file_view = 'file-copy',
    string $debug_log = ''
): void {
    ini_set('memory_limit', '768M');
    set_time_limit(300);

    $branches_dir = rtrim($branches_dir, "/\\");
    if (!is_dir($branches_dir)) {
        http_response_code(500);
        echo "COW branch directory not found\n";
        return;
    }

    $storage_branches_dir = rtrim($storage_branches_dir ?: $branches_dir, "/\\");
    $branch_list_path = $branch_list_path ?: dirname($git_repo_dir) . '/branches.txt';

    cow_git_mkdir(dirname($git_repo_dir));
    $operation_lock = null;
    $operation_lock_path = dirname($branch_list_path) . '/operations.lock';
    $operation_lock = fopen($operation_lock_path, 'c');
    if (!$operation_lock) {
        http_response_code(500);
        echo "Cannot open COW branch operation lock\n";
        return;
    }
    if (!flock($operation_lock, LOCK_EX)) {
        http_response_code(500);
        echo "Cannot lock COW branch operations\n";
        fclose($operation_lock);
        return;
    }

    $lock_path = $git_repo_dir . '.lock';
    $lock = fopen($lock_path, 'c');
    if (!$lock) {
        http_response_code(500);
        echo "Cannot open COW git lock\n";
        flock($operation_lock, LOCK_UN);
        fclose($operation_lock);
        return;
    }
    if (!flock($lock, LOCK_EX)) {
        http_response_code(500);
        echo "Cannot lock COW git store\n";
        fclose($lock);
        flock($operation_lock, LOCK_UN);
        fclose($operation_lock);
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
        $pre_receive_refs = $is_post_receive ? cow_git_capture_all_head_refs($git_repo_dir) : [];
        if ($is_post_receive) {
            ob_start();
        }

        $request_bytes = file_get_contents('php://input');
        $push_commands = $is_post_receive ? cow_git_parse_push_commands($request_bytes) : [];
        if ($is_post_receive && cow_git_reject_push_commands_if_needed($push_commands)) {
            return;
        }

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
                cow_git_apply_push_to_branches(
                    $repo,
                    $git_repo_dir,
                    $branches_dir,
                    $storage_branches_dir,
                    $branch_list_path,
                    $file_view,
                    $debug_log,
                    $pre_receive_refs
                );
            } catch (\Throwable $e) {
                try {
                    cow_git_restore_head_refs($git_repo_dir, $pre_receive_refs, cow_git_capture_all_head_refs($git_repo_dir));
                } catch (\Throwable $restore_error) {
                    error_log("COW push ref restore failed after rejection: " . $restore_error->getMessage());
                }
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
        if ($operation_lock) {
            flock($operation_lock, LOCK_UN);
            fclose($operation_lock);
        }
    }
}

function cow_git_parse_push_commands(string $request_bytes): array {
    $commands = [];
    $offset = 0;
    $length = strlen($request_bytes);

    while ($offset + 4 <= $length) {
        $marker = substr($request_bytes, $offset, 4);
        if ($marker === 'PACK') {
            break;
        }
        if (!preg_match('/^[0-9a-f]{4}$/', $marker)) {
            break;
        }

        $offset += 4;
        if ($marker === '0000') {
            break;
        }
        if ($marker === '0001' || $marker === '0002') {
            continue;
        }

        $packet_length = hexdec($marker) - 4;
        if ($packet_length < 0 || $offset + $packet_length > $length) {
            break;
        }

        $payload = substr($request_bytes, $offset, $packet_length);
        $offset += $packet_length;

        foreach (explode("\n", rtrim($payload, "\n")) as $line) {
            if ($line === '') {
                continue;
            }

            $capabilities = [];
            $nul = strpos($line, "\0");
            if ($nul !== false) {
                $capabilities = preg_split('/\s+/', trim(substr($line, $nul + 1))) ?: [];
                $line = substr($line, 0, $nul);
            }

            if (!preg_match('/^([0-9a-f]{40}) ([0-9a-f]{40}) (.+)$/', $line, $matches)) {
                continue;
            }

            $commands[] = [
                'old_oid' => $matches[1],
                'new_oid' => $matches[2],
                'ref' => $matches[3],
                'capabilities' => $capabilities,
            ];
        }
    }

    return $commands;
}

function cow_git_reject_push_commands_if_needed(array $commands): bool {
    $rejections = cow_git_push_command_rejections($commands);
    if (!$rejections) {
        return false;
    }

    cow_git_close_receive_pack_buffer();
    cow_git_send_receive_pack_rejections($rejections);
    return true;
}

function cow_git_push_command_rejections(array $commands): array {
    if (count($commands) > 1) {
        $rejections = [];
        foreach ($commands as $command) {
            $rejections[$command['ref']] = 'ForkPress accepts one branch update per push';
        }
        return $rejections;
    }

    foreach ($commands as $command) {
        $ref = $command['ref'];
        if (!str_starts_with($ref, 'refs/heads/')) {
            return [$ref => 'ForkPress only accepts branch refs'];
        }

        $branch = substr($ref, strlen('refs/heads/'));
        if (!cow_git_valid_branch_name($branch)) {
            return [
                $ref => 'unsupported COW branch name; use 1-63 ASCII letters, numbers, underscores, or hyphens',
            ];
        }

        if ($branch === 'main' && Commit::is_null_hash($command['new_oid'])) {
            return [$ref => 'refusing to delete the main COW branch'];
        }
    }

    return [];
}

function cow_git_close_receive_pack_buffer(): void {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
}

function cow_git_send_receive_pack_rejections(array $ref_messages): void {
    http_response_code(200);
    header('Content-Type: application/x-git-receive-pack-result');
    header('Cache-Control: no-cache');

    $git_response = new GitProtocolEncoderPipe();
    $git_response->append_sideband_packet_line("unpack ok\n");
    foreach ($ref_messages as $ref => $message) {
        $git_response->append_sideband_packet_line("ng $ref $message\n");
    }
    $git_response->append_sideband_packet_line('0000');
    $git_response->append_packet_line('0000');
    $git_response->close_writing();

    while (true) {
        $available = $git_response->pull(65536);
        if ($available === 0 && $git_response->reached_end_of_data()) {
            break;
        }
        echo $git_response->consume($available);
    }
}

function cow_git_sync_repository(GitRepository $repo, string $branches_dir): void {
    $branches = cow_git_branch_names($branches_dir);
    cow_git_prune_missing_branch_refs($repo, $branches);
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
        if (cow_git_commit_matches_updates($repo, $tip, $updates)) {
            continue;
        }

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

function cow_git_prune_missing_branch_refs(GitRepository $repo, array $branches): void {
    $live = array_fill_keys($branches, true);
    foreach ($repo->list_refs(['refs/heads/']) as $ref => $_tip) {
        if (strncmp($ref, 'refs/heads/', 11) !== 0) {
            continue;
        }
        $branch = substr($ref, 11);
        if (!cow_git_valid_branch_name($branch) || isset($live[$branch])) {
            continue;
        }
        $repo->delete_branch($ref);
    }
}

function cow_git_commit_matches_updates(GitRepository $repo, string $tip, array $updates): bool {
    if (Commit::is_null_hash($tip) || !$repo->has_object($tip)) {
        return false;
    }

    $commit = $repo->read_object($tip)->as_commit();
    if (Commit::is_null_hash($commit->tree) || !$repo->has_object($commit->tree)) {
        return false;
    }

    $existing = [];
    cow_git_walk_tree($repo, $commit->tree, '', $existing);
    if (count($existing) !== count($updates)) {
        return false;
    }

    foreach ($updates as $path => $contents) {
        if (!isset($existing[$path]) || $existing[$path] !== cow_git_blob_hash((string)$contents)) {
            return false;
        }
    }

    return true;
}

function cow_git_apply_push_to_branches(
    GitRepository $repo,
    string $git_repo_dir,
    string $branches_dir,
    string $storage_branches_dir,
    ?string $branch_list_path,
    string $file_view,
    string $debug_log,
    array $pre_receive_refs = []
): void {
    cow_git_apply_all_refs_to_branches(
        $repo,
        $git_repo_dir,
        $branches_dir,
        $storage_branches_dir,
        $branch_list_path,
        $file_view,
        $debug_log,
        $pre_receive_refs
    );
    cow_git_sync_repository($repo, $branches_dir);
    try {
        cow_git_prune_unreachable_objects($repo, $git_repo_dir);
    } catch (\Throwable $e) {
        error_log("COW Git object prune skipped: " . $e->getMessage());
    }
}

function cow_git_apply_all_refs_to_branches(
    GitRepository $repo,
    string $git_repo_dir,
    string $branches_dir,
    string $storage_branches_dir,
    ?string $branch_list_path,
    string $file_view,
    string $debug_log,
    array $pre_receive_refs = []
): void {
    $refs_dir = rtrim($git_repo_dir, "/\\") . '/refs/heads';
    if (!is_dir($refs_dir)) {
        return;
    }

    cow_git_reject_unsupported_head_refs($git_repo_dir, $pre_receive_refs);
    $current_refs = cow_git_capture_head_refs($git_repo_dir);
    cow_git_delete_removed_branches(
        $git_repo_dir,
        $branches_dir,
        $storage_branches_dir,
        $branch_list_path,
        $pre_receive_refs,
        $current_refs
    );
    $existing_branches = cow_git_branch_names($branches_dir);
    foreach (scandir($refs_dir) as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (!cow_git_valid_branch_name($name)) {
            throw new \RuntimeException("refusing to apply invalid branch name '$name'");
        }
        $ref_path = $refs_dir . '/' . $name;
        if (!is_file($ref_path)) {
            throw new \RuntimeException("refusing to apply nested branch ref '$name'");
        }
        $tip = trim((string)file_get_contents($ref_path));
        if ($tip === '' || Commit::is_null_hash($tip)) {
            continue;
        }
        if (array_key_exists($name, $pre_receive_refs) && $pre_receive_refs[$name] === $tip) {
            continue;
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

        $branch_root = rtrim($branches_dir, "/\\") . '/' . $name;
        if (!is_dir($branch_root)) {
            $branch_root = cow_git_create_branch_for_ref(
                $repo,
                $branches_dir,
                $storage_branches_dir,
                $branch_list_path,
                $file_view,
                $debug_log,
                $name,
                $tip,
                $wp_files,
                $pre_receive_refs,
                $existing_branches
            );
            $existing_branches[] = $name;
            continue;
        }

        cow_git_apply_existing_branch_update(
            $repo,
            $branches_dir,
            $storage_branches_dir,
            $name,
            $wp_files,
            $file_view,
            $debug_log
        );
    }
}

function cow_git_delete_removed_branches(
    string $git_repo_dir,
    string $branches_dir,
    string $storage_branches_dir,
    ?string $branch_list_path,
    array $pre_receive_refs,
    array $current_refs
): void {
    foreach ($pre_receive_refs as $branch => $_old_tip) {
        if (!cow_git_valid_branch_name($branch) || array_key_exists($branch, $current_refs)) {
            continue;
        }
        if ($branch === 'main') {
            cow_git_restore_head_refs($git_repo_dir, $pre_receive_refs, cow_git_capture_all_head_refs($git_repo_dir));
            throw new \RuntimeException("refusing to delete the main COW branch");
        }
    }

    $staged = [];
    try {
        foreach ($pre_receive_refs as $branch => $_old_tip) {
            if (!cow_git_valid_branch_name($branch) || array_key_exists($branch, $current_refs)) {
                continue;
            }
            $staged[$branch] = cow_git_stage_delete_branch_tree($branches_dir, $storage_branches_dir, $branch);
        }

        if (!$staged) {
            return;
        }

        cow_git_write_branch_list($branches_dir, $branch_list_path);
    } catch (\Throwable $e) {
        cow_git_restore_staged_branch_deletes($staged);
        throw $e;
    }

    foreach ($staged as $branch => $entries) {
        cow_git_discard_staged_branch_delete($entries);
        error_log("ForkPress COW git deleted branch '$branch'");
    }
}

function cow_git_stage_delete_branch_tree(string $branches_dir, string $storage_branches_dir, string $branch): array {
    $public = rtrim($branches_dir, "/\\") . '/' . $branch;
    $storage = cow_git_branch_storage_root($storage_branches_dir, $branches_dir, $branch);
    $same_root = cow_git_normalize_configured_path($public) === cow_git_normalize_configured_path($storage);
    $entries = [];

    try {
        if (is_link($public)) {
            $entries[] = cow_git_stage_delete_path($public, $branch, 'public');
        } elseif (is_dir($public)) {
            if (!$same_root) {
                throw new \RuntimeException("refusing to remove unexpected public branch directory '$branch'");
            }
            $entries[] = cow_git_stage_delete_path($public, $branch, 'public');
        } elseif (file_exists($public)) {
            throw new \RuntimeException("refusing to remove non-directory branch path '$branch'");
        }

        if (!$same_root && (is_dir($storage) || is_link($storage))) {
            $entries[] = cow_git_stage_delete_path($storage, $branch, 'storage');
        }

        if ((file_exists($public) || is_link($public)) || (file_exists($storage) || is_link($storage))) {
            throw new \RuntimeException("failed to stage COW branch '$branch' for deletion");
        }
    } catch (\Throwable $e) {
        cow_git_restore_staged_branch_deletes([$branch => $entries]);
        throw $e;
    }

    return $entries;
}

function cow_git_stage_delete_path(string $path, string $branch, string $label): array {
    $backup = cow_git_delete_backup_path($path, $branch, $label);
    if (!@rename($path, $backup)) {
        throw new \RuntimeException("failed to stage COW branch '$branch' $label path for deletion");
    }
    return ['path' => $path, 'backup' => $backup];
}

function cow_git_delete_backup_path(string $path, string $branch, string $label): string {
    return cow_git_unique_branch_temp_path(dirname($path), 'delete-' . $label, $branch);
}

function cow_git_unique_branch_temp_path(string $parent, string $prefix, string $branch): string {
    $safe_branch = preg_replace('/[^A-Za-z0-9_-]/', '-', $branch) ?: 'branch';
    do {
        $path = rtrim($parent, "/\\") . '/.forkpress-' . $prefix . '-' . $safe_branch
            . '-' . getmypid() . '-' . bin2hex(random_bytes(4));
    } while (file_exists($path) || is_link($path));
    return $path;
}

function cow_git_restore_staged_branch_deletes(array $staged): void {
    foreach (array_reverse($staged, true) as $branch => $entries) {
        foreach (array_reverse($entries) as $entry) {
            if (!file_exists($entry['backup']) && !is_link($entry['backup'])) {
                continue;
            }
            if (file_exists($entry['path']) || is_link($entry['path']) || !@rename($entry['backup'], $entry['path'])) {
                error_log("ForkPress COW failed to restore staged delete for branch '$branch' at " . $entry['path']);
            }
        }
    }
}

function cow_git_discard_staged_branch_delete(array $entries): void {
    foreach ($entries as $entry) {
        cow_git_remove_tree($entry['backup']);
    }
}

function cow_git_reject_unsupported_head_refs(string $git_repo_dir, array $pre_receive_refs): void {
    $refs_dir = rtrim($git_repo_dir, "/\\") . '/refs/heads';
    if (!is_dir($refs_dir)) {
        return;
    }

    $unsupported = [];
    $current_refs = cow_git_capture_all_head_refs($git_repo_dir);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($refs_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        $path = $entry->getPathname();
        $rel = str_replace('\\', '/', substr($path, strlen(rtrim($refs_dir, "/\\")) + 1));
        if ($entry->isDir()) {
            continue;
        }
        if (str_contains($rel, '/') || !cow_git_valid_branch_name($rel)) {
            $unsupported[] = $rel;
            @unlink($path);
        }
    }

    if ($unsupported) {
        cow_git_restore_head_refs($git_repo_dir, $pre_receive_refs, $current_refs);
        sort($unsupported);
        throw new \RuntimeException(
            "unsupported COW branch name(s) pushed: " . implode(', ', $unsupported)
            . "; branch names must be 1-63 ASCII letters, numbers, underscores, or hyphens"
        );
    }

    cow_git_remove_empty_dirs($refs_dir);
}

function cow_git_restore_head_refs(string $git_repo_dir, array $pre_receive_refs, array $current_refs): void {
    $refs_dir = rtrim($git_repo_dir, "/\\") . '/refs/heads';
    foreach ($current_refs as $ref => $_tip) {
        if (!array_key_exists($ref, $pre_receive_refs)) {
            @unlink($refs_dir . '/' . str_replace('/', DIRECTORY_SEPARATOR, $ref));
        }
    }
    foreach ($pre_receive_refs as $ref => $tip) {
        $path = $refs_dir . '/' . str_replace('/', DIRECTORY_SEPARATOR, $ref);
        cow_git_mkdir(dirname($path));
        file_put_contents($path, $tip . "\n");
    }
    cow_git_remove_empty_dirs($refs_dir);
}

function cow_git_capture_head_refs(string $git_repo_dir): array {
    $refs = [];
    foreach (cow_git_capture_all_head_refs($git_repo_dir) as $name => $tip) {
        if (cow_git_valid_branch_name($name)) {
            $refs[$name] = $tip;
        }
    }
    return $refs;
}

function cow_git_capture_all_head_refs(string $git_repo_dir): array {
    $refs_dir = rtrim($git_repo_dir, "/\\") . '/refs/heads';
    $refs = [];
    if (!is_dir($refs_dir)) {
        return $refs;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($refs_dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($it as $entry) {
        if (!$entry->isFile()) {
            continue;
        }
        $path = $entry->getPathname();
        $name = str_replace('\\', '/', substr($path, strlen(rtrim($refs_dir, "/\\")) + 1));
        $tip = trim((string)file_get_contents($path));
        if ($tip !== '' && !Commit::is_null_hash($tip)) {
            $refs[$name] = $tip;
        }
    }
    return $refs;
}

function cow_git_create_branch_for_ref(
    GitRepository $repo,
    string $branches_dir,
    string $storage_branches_dir,
    ?string $branch_list_path,
    string $file_view,
    string $debug_log,
    string $branch,
    string $tip,
    array $wp_files,
    array $pre_receive_refs,
    array $existing_branches
): string {
    if ($branch === 'HEAD') {
        throw new \RuntimeException("refusing to create reserved branch '$branch'");
    }
    if (!in_array('main', $existing_branches, true)) {
        throw new \RuntimeException("cannot create branch '$branch' because main does not exist");
    }

    $source = cow_git_select_source_branch($repo, $tip, $existing_branches, $pre_receive_refs);
    if ($source === null) {
        throw new \RuntimeException(
            "cannot infer a source ForkPress branch for git-created branch '$branch'; fetch from /site.git and branch from an existing ref first"
        );
    }
    $source_root = cow_git_branch_storage_root($storage_branches_dir, $branches_dir, $source);
    if (!is_dir($source_root)) {
        throw new \RuntimeException("source branch '$source' does not exist for git-created branch '$branch'");
    }

    $dest_storage = cow_git_branch_storage_root($storage_branches_dir, $branches_dir, $branch);
    $dest_public = rtrim($branches_dir, "/\\") . '/' . $branch;
    if (file_exists($dest_storage) || is_link($dest_storage) || file_exists($dest_public) || is_link($dest_public)) {
        throw new \RuntimeException("branch '$branch' already exists");
    }

    $tmp = dirname($dest_storage) . '/.forkpress-new-' . $branch . '-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $published_storage = false;
    $linked_public = false;
    try {
        cow_git_clone_branch_tree($source_root, $tmp, $file_view);
        cow_git_apply_wp_files($repo, $tmp, $wp_files);

        if (!rename($tmp, $dest_storage)) {
            throw new \RuntimeException("failed to publish git-created branch '$branch'");
        }
        $published_storage = true;

        if (cow_git_normalize_path($dest_storage) !== cow_git_normalize_path($dest_public)) {
            if (!symlink($dest_storage, $dest_public)) {
                cow_git_remove_tree($dest_storage);
                $published_storage = false;
                throw new \RuntimeException("failed to link git-created branch '$branch' into public branch directory");
            }
            $linked_public = true;
        }

        cow_git_rewrite_wp_config($dest_public, $debug_log);
        cow_git_write_branch_list($branches_dir, $branch_list_path);
        error_log("ForkPress COW git created branch '$branch' from '$source'");
        return $dest_public;
    } catch (\Throwable $e) {
        cow_git_remove_tree($tmp);
        if ($linked_public && (file_exists($dest_public) || is_link($dest_public))) {
            cow_git_remove_tree($dest_public);
        }
        if ($published_storage && (file_exists($dest_storage) || is_link($dest_storage))) {
            cow_git_remove_tree($dest_storage);
        }
        throw $e;
    }
}

function cow_git_apply_existing_branch_update(
    GitRepository $repo,
    string $branches_dir,
    string $storage_branches_dir,
    string $branch,
    array $wp_files,
    string $file_view,
    string $debug_log
): void {
    $public_root = rtrim($branches_dir, "/\\") . '/' . $branch;
    $storage_root = cow_git_branch_storage_root($storage_branches_dir, $branches_dir, $branch);
    if (!is_dir($public_root) || !is_dir($storage_root)) {
        throw new \RuntimeException("target branch '$branch' does not exist");
    }

    $parent = dirname($storage_root);
    $stage = cow_git_unique_branch_temp_path($parent, 'update-stage', $branch);
    $backup = cow_git_unique_branch_temp_path($parent, 'update-backup', $branch);
    $failed = cow_git_unique_branch_temp_path($parent, 'update-failed', $branch);
    $moved_to_backup = false;
    $published_stage = false;

    try {
        cow_git_clone_branch_tree($storage_root, $stage, $file_view);
        cow_git_apply_wp_files($repo, $stage, $wp_files);
        cow_git_rewrite_wp_config_for_root($stage, $public_root, $debug_log);

        if (!@rename($storage_root, $backup)) {
            throw new \RuntimeException("failed to stage current branch '$branch' for update");
        }
        $moved_to_backup = true;

        if (!@rename($stage, $storage_root)) {
            throw new \RuntimeException("failed to publish staged update for branch '$branch'");
        }
        $published_stage = true;
    } catch (\Throwable $e) {
        if ($published_stage && (file_exists($storage_root) || is_link($storage_root))) {
            if (!@rename($storage_root, $failed)) {
                cow_git_remove_tree($storage_root);
            }
        }
        if (
            $moved_to_backup
            && (file_exists($backup) || is_link($backup))
            && !file_exists($storage_root)
            && !is_link($storage_root)
            && !@rename($backup, $storage_root)
        ) {
            error_log("ForkPress COW failed to restore branch '$branch' from update backup $backup");
        }
        cow_git_remove_tree($failed);
        cow_git_remove_tree($stage);
        throw $e;
    }

    cow_git_remove_tree($backup);
}

function cow_git_select_source_branch(
    GitRepository $repo,
    string $tip,
    array $existing_branches,
    array $pre_receive_refs = []
): ?string {
    $distances = cow_git_commit_distances($repo, $tip, 4096);
    $best = null;

    foreach ($existing_branches as $branch) {
        if (!cow_git_valid_branch_name($branch)) {
            continue;
        }
        $ref = "refs/heads/$branch";
        if (!$repo->branch_exists($ref)) {
            continue;
        }
        $branch_tip = $pre_receive_refs[$branch] ?? $repo->get_branch_tip($ref);
        if ($branch_tip === '' || Commit::is_null_hash($branch_tip)) {
            continue;
        }
        $branch_history = cow_git_commit_distances($repo, $branch_tip, 4096);
        $common_distance = null;
        foreach ($branch_history as $hash => $_branch_distance) {
            if (isset($distances[$hash])) {
                $common_distance = $distances[$hash];
                break;
            }
        }
        if ($common_distance === null) {
            continue;
        }

        $score = [$common_distance, $branch === 'main' ? 0 : 1, $branch];
        if ($best === null || cow_git_compare_source_score($score, $best['score']) < 0) {
            $best = ['branch' => $branch, 'score' => $score];
        }
    }

    return $best['branch'] ?? null;
}

function cow_git_compare_source_score(array $a, array $b): int {
    if ($a[0] !== $b[0]) {
        return $a[0] <=> $b[0];
    }
    if ($a[1] !== $b[1]) {
        return $a[1] <=> $b[1];
    }
    return strcmp($a[2], $b[2]);
}

function cow_git_commit_distances(GitRepository $repo, string $tip, int $limit): array {
    $distances = [];
    $queue = [[$tip, 0]];
    while ($queue && count($distances) < $limit) {
        [$hash, $distance] = array_shift($queue);
        if ($hash === '' || Commit::is_null_hash($hash) || isset($distances[$hash]) || !$repo->has_object($hash)) {
            continue;
        }
        $distances[$hash] = $distance;
        $commit = $repo->read_object($hash)->as_commit();
        foreach ($commit->parents as $parent) {
            $queue[] = [$parent, $distance + 1];
        }
    }
    return $distances;
}

function cow_git_branch_storage_root(string $storage_branches_dir, string $branches_dir, string $branch): string {
    $storage = rtrim($storage_branches_dir ?: $branches_dir, "/\\") . '/' . $branch;
    if (is_dir($storage) || is_link($storage) || cow_git_normalize_path($storage_branches_dir) !== cow_git_normalize_path($branches_dir)) {
        return $storage;
    }
    return rtrim($branches_dir, "/\\") . '/' . $branch;
}

function cow_git_clone_branch_tree(string $source, string $dest, string $file_view): void {
    $file_view = trim($file_view) !== '' ? trim($file_view) : 'file-copy';
    $requires_cow = $file_view !== 'file-copy' && $file_view !== 'copy';

    if ($requires_cow && cow_git_clone_tree_with_platform_tool($source, $dest)) {
        return;
    }
    if ($requires_cow) {
        throw new \RuntimeException("filesystem COW clone failed while creating branch at $dest");
    }
    cow_git_copy_tree($source, $dest);
}

function cow_git_clone_tree_with_platform_tool(string $source, string $dest): bool {
    if (!is_dir($source) || file_exists($dest) || is_link($dest)) {
        return false;
    }

    $family = PHP_OS_FAMILY;
    if ($family === 'Darwin') {
        $command = ['cp', '-cR', $source, $dest];
    } elseif ($family === 'Linux') {
        $command = ['cp', '-a', '--reflink=always', $source, $dest];
    } else {
        return false;
    }

    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($command, $descriptor, $pipes);
    if (!is_resource($process)) {
        return false;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        error_log("ForkPress COW platform clone failed: " . trim($stdout . "\n" . $stderr));
        cow_git_remove_tree($dest);
        return false;
    }
    return is_dir($dest);
}

function cow_git_rewrite_wp_config(string $branch_root, string $debug_log): void {
    cow_git_rewrite_wp_config_for_root($branch_root, $branch_root, $debug_log);
}

function cow_git_rewrite_wp_config_for_root(string $config_root, string $runtime_root, string $debug_log): void {
    $config_path = rtrim($config_root, "/\\") . '/wp-config.php';
    if (!is_file($config_path)) {
        return;
    }
    $config_db_dir = rtrim($config_root, "/\\") . '/wp-content/database';
    $db_dir = rtrim($runtime_root, "/\\") . '/wp-content/database';
    $db_file = '.ht.sqlite';
    $db_path = $db_dir . '/' . $db_file;
    cow_git_mkdir($config_db_dir);

    $config = file_get_contents($config_path);
    if ($config === false) {
        throw new \RuntimeException("failed to read $config_path");
    }
    $debug_log = $debug_log !== '' ? $debug_log : $db_dir . '/wp-debug.log';
    $replacements = [
        "/define\\(\\s*'FQDB'\\s*,\\s*'[^']*'\\s*\\);/" => "define('FQDB',    '" . cow_git_php_single_quoted($db_path) . "');",
        "/define\\(\\s*'DB_DIR'\\s*,\\s*'[^']*'\\s*\\);/" => "define('DB_DIR',  '" . cow_git_php_single_quoted($db_dir) . "');",
        "/define\\(\\s*'DB_FILE'\\s*,\\s*'[^']*'\\s*\\);/" => "define('DB_FILE', '" . cow_git_php_single_quoted($db_file) . "');",
        "/define\\(\\s*'WP_DEBUG_LOG'\\s*,\\s*'[^']*'\\s*\\);/" => "define('WP_DEBUG_LOG', '" . cow_git_php_single_quoted($debug_log) . "');",
    ];
    foreach ($replacements as $pattern => $replacement) {
        $config = preg_replace($pattern, $replacement, $config, 1);
    }
    if (file_put_contents($config_path, $config) === false) {
        throw new \RuntimeException("failed to write $config_path");
    }
}

function cow_git_php_single_quoted(string $value): string {
    return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
}

function cow_git_write_branch_list(string $branches_dir, ?string $branch_list_path): void {
    if (!$branch_list_path) {
        return;
    }
    $names = cow_git_branch_names($branches_dir);
    cow_git_mkdir(dirname($branch_list_path));
    $tmp = $branch_list_path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    try {
        if (file_put_contents($tmp, implode("\n", $names) . "\n") === false) {
            throw new \RuntimeException("failed to write temporary branch list");
        }
        if (!@rename($tmp, $branch_list_path)) {
            throw new \RuntimeException("failed to publish branch list");
        }
    } finally {
        if (file_exists($tmp)) {
            @unlink($tmp);
        }
    }
}

function cow_git_normalize_path(string $path): string {
    $real = realpath($path);
    if ($real !== false) {
        return rtrim(str_replace('\\', '/', $real), '/');
    }
    return rtrim(str_replace('\\', '/', $path), '/');
}

function cow_git_normalize_configured_path(string $path): string {
    $path = str_replace('\\', '/', $path);
    $prefix = str_starts_with($path, '/') ? '/' : '';
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }
    return rtrim($prefix . implode('/', $parts), '/') ?: $prefix;
}

function cow_git_remove_tree(string $path): void {
    if ($path === '' || (!file_exists($path) && !is_link($path))) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
    @rmdir($path);
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
    if ($rel === 'wp-content/database' || str_starts_with($rel, 'wp-content/database/')) {
        return false;
    }
    return true;
}

function cow_git_apply_wp_files(GitRepository $repo, string $branch_root, array $wp_files): void {
    foreach ($wp_files as $rel => $blob_hash) {
        $target = $branch_root . '/' . $rel;
        if (is_file($target) && !is_link($target) && cow_git_file_blob_hash($target) === $blob_hash) {
            continue;
        }
        $parent = dirname($target);
        cow_git_prepare_directory_path($branch_root, $parent);
        if ((is_dir($target) && !is_link($target)) || is_link($target)) {
            cow_git_remove_tree($target);
        }
        if (file_put_contents($target, $repo->read_object($blob_hash)->consume_all()) === false) {
            throw new \RuntimeException("failed to write $target");
        }
    }

    $current = cow_git_collect_branch_files($branch_root);
    foreach ($current as $rel => $path) {
        if (!isset($wp_files[$rel]) && is_file($path)) {
            if (!unlink($path)) {
                throw new \RuntimeException("failed to remove $path");
            }
        }
    }
    cow_git_remove_empty_dirs($branch_root);
}

function cow_git_prepare_directory_path(string $branch_root, string $path): void {
    $root = rtrim($branch_root, "/\\");
    $path = rtrim($path, "/\\");
    if ($path === '' || $path === $root || (is_dir($path) && !is_link($path))) {
        return;
    }

    $parent = dirname($path);
    if ($parent !== $path) {
        cow_git_prepare_directory_path($root, $parent);
    }
    if (file_exists($path) || is_link($path)) {
        cow_git_remove_tree($path);
    }
    cow_git_mkdir($path);
}

function cow_git_file_blob_hash(string $path): string {
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new \RuntimeException("failed to read $path");
    }
    return cow_git_blob_hash($contents);
}

function cow_git_blob_hash(string $contents): string {
    return sha1('blob ' . strlen($contents) . "\0" . $contents);
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

function cow_git_prune_unreachable_objects(GitRepository $repo, string $git_repo_dir): array {
    $reachable = cow_git_collect_reachable_objects($repo);
    $scanned = 0;
    $deleted = 0;
    $object_root = rtrim($git_repo_dir, "/\\") . '/objects';

    if (!is_dir($object_root)) {
        return ['scanned' => 0, 'deleted' => 0, 'kept' => 0];
    }

    foreach (scandir($object_root) ?: [] as $fanout) {
        if (!preg_match('/^[0-9a-f]{2}$/', $fanout)) {
            continue;
        }
        $dir = $object_root . '/' . $fanout;
        if (!is_dir($dir)) {
            continue;
        }
        foreach (scandir($dir) ?: [] as $leaf) {
            if (!preg_match('/^[0-9a-f]{38}$/', $leaf)) {
                continue;
            }
            $oid = $fanout . $leaf;
            ++$scanned;
            if (isset($reachable[$oid])) {
                continue;
            }
            $path = $dir . '/' . $leaf;
            if (!is_file($path)) {
                continue;
            }
            if (!@unlink($path) && file_exists($path)) {
                throw new \RuntimeException("failed to prune unreachable COW Git object $oid");
            }
            ++$deleted;
        }
        @rmdir($dir);
    }

    return ['scanned' => $scanned, 'deleted' => $deleted, 'kept' => $scanned - $deleted];
}

function cow_git_collect_reachable_objects(GitRepository $repo): array {
    $reachable = [];
    $tips = [];
    foreach ($repo->list_refs(['refs/heads/']) as $ref => $tip) {
        if (strncmp($ref, 'refs/heads/', 11) !== 0 || !preg_match('/^[0-9a-f]{40}$/', $tip)) {
            continue;
        }
        $tips[$tip] = true;
    }

    try {
        $head = $repo->get_branch_tip('HEAD');
        if (preg_match('/^[0-9a-f]{40}$/', $head)) {
            $tips[$head] = true;
        }
    } catch (\Throwable $_e) {
        // A missing HEAD should not keep a valid refs/heads/* graph from being pruned.
    }

    foreach (array_keys($tips) as $tip) {
        cow_git_mark_reachable_commit($repo, $tip, $reachable);
    }
    return $reachable;
}

function cow_git_mark_reachable_commit(GitRepository $repo, string $commit_hash, array &$reachable): void {
    if (Commit::is_null_hash($commit_hash) || isset($reachable[$commit_hash])) {
        return;
    }
    if (!$repo->has_object($commit_hash)) {
        throw new \RuntimeException("cannot prune COW Git store with missing reachable commit $commit_hash");
    }

    $reachable[$commit_hash] = true;
    $commit = $repo->read_object($commit_hash)->as_commit();
    if (!Commit::is_null_hash($commit->tree)) {
        cow_git_mark_reachable_tree($repo, $commit->tree, $reachable);
    }
    foreach ($commit->parents as $parent) {
        cow_git_mark_reachable_commit($repo, $parent, $reachable);
    }
}

function cow_git_mark_reachable_tree(GitRepository $repo, string $tree_hash, array &$reachable): void {
    if (Commit::is_null_hash($tree_hash) || isset($reachable[$tree_hash])) {
        return;
    }
    if (!$repo->has_object($tree_hash)) {
        throw new \RuntimeException("cannot prune COW Git store with missing reachable tree $tree_hash");
    }

    $reachable[$tree_hash] = true;
    $tree = $repo->read_object($tree_hash)->as_tree();
    foreach ($tree->entries as $entry) {
        $bucket = $entry->get_mode_bucket();
        if (isset($reachable[$entry->hash]) || $bucket === TreeEntry::FILE_MODE_COMMIT) {
            continue;
        }
        if ($bucket === TreeEntry::FILE_MODE_DIRECTORY) {
            cow_git_mark_reachable_tree($repo, $entry->hash, $reachable);
        } else {
            if (!$repo->has_object($entry->hash)) {
                throw new \RuntimeException("cannot prune COW Git store with missing reachable object {$entry->hash}");
            }
            $reachable[$entry->hash] = true;
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
    if (!@mkdir($path, 0755, true) && !is_dir($path)) {
        throw new \RuntimeException("failed to create directory $path");
    }
}

<?php
/**
 * Git smart-HTTP server for BranchFS.
 *
 * Bridges the branchfs file overlay with the Git protocol via the
 * WordPress php-toolkit's GitEndpoint. Database (WordPress) content
 * is NOT included in the git clone — only filesystem files are versioned.
 *
 * Expected globals from router.php: $db_path, $wp_root, $branch
 * Expected $_SERVER vars: REQUEST_METHOD, REQUEST_URI, HTTP_HOST
 */

require_once __DIR__ . '/autoload.php';

use WordPress\Filesystem\LocalFilesystem;
use WordPress\Git\GitEndpoint;
use WordPress\Git\GitRepository;
use WordPress\Git\Model\Commit;
use WordPress\Git\Model\Tree;
use WordPress\Git\Model\TreeEntry;
use WordPress\Git\Protocol\GitProtocolEncoderPipe;
use WordPress\HttpServer\Response\StreamingResponseWriter;

function git_server_handle(string $db_path, string $wp_root, string $git_path, string $query_string): void {
    ini_set('memory_limit', '512M');
    set_time_limit(300);

    $sqlite = new SQLite3($db_path, SQLITE3_OPEN_READWRITE);
    $sqlite->busyTimeout(5000);

    // Include branchctl helpers (git_fs_resolve_tree, git_fs_record_snapshot, etc.)
    require_once __DIR__ . '/helpers.php';

    // Determine the git endpoint path
    // git_path is like /git-upload-pack or /info/refs
    $endpoint_path = $git_path;
    if ($query_string) {
        $endpoint_path .= '?' . $query_string;
    }

    // Per-request cache dir — two concurrent clones must not trample each
    // other's working tree. Rebuild the repo from scratch each request.
    $cache_root = sys_get_temp_dir() . '/branchfs-git-' . bin2hex(random_bytes(8));
    mkdir($cache_root, 0700, true);
    register_shutdown_function(function() use ($cache_root) {
        if (is_dir($cache_root)) {
            git_rmrf($cache_root);
        }
    });
    $repo_dir = $cache_root . '/repo';

    // Determine request type
    $is_post_receive = ($git_path === '/git-receive-pack');

    // Auth only required on actual push (POST /git-receive-pack), and
    // only when the site has auth_enabled='1'. Older sites keep the
    // open-push behaviour until the admin flips the flag.
    $auth_user = null;
    if ($is_post_receive && git_site_auth_enabled($sqlite)) {
        $auth_user = git_check_auth($sqlite);
        if ($auth_user === null) {
            header('WWW-Authenticate: Basic realm="BranchFS Git"');
            http_response_code(401);
            echo "Authentication required for push\n";
            return;
        }
        // role 'read' cannot push.
        $role = git_user_role($sqlite, $auth_user);
        if ($role === 'read') {
            header('WWW-Authenticate: Basic realm="BranchFS Git"');
            http_response_code(403);
            echo "User '$auth_user' has role 'read' and cannot push\n";
            return;
        }
    } elseif ($is_post_receive) {
        // auth disabled: accept any BASIC user (or anonymous) as "admin"
        // so downstream commit-author attribution still has a value.
        $auth_user = $_SERVER['PHP_AUTH_USER'] ?? 'anonymous';
    }

    // Capture pre-push state for rollback on failure.
    $pre_state = null;
    if ($is_post_receive) {
        $pre_state = git_capture_pre_state($sqlite);
        ob_start();
    }

    // Build the repository from branchfs state
    git_build_repository($repo_dir, $sqlite);

    // Create the toolkit repository and endpoint
    $fs = LocalFilesystem::create($repo_dir);
    $repo = new GitRepository($fs, ['default_branch' => 'main']);
    $endpoint = new GitEndpoint($repo);

    // Read request body
    $request_bytes = file_get_contents('php://input');

    // Handle the request
    $response = new StreamingResponseWriter();
    try {
        $endpoint->handle_request($endpoint_path, $request_bytes, $response);
    } catch (\Throwable $e) {
        error_log("Git server error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    }

    // After push: apply changes back to branchfs, transactionally.
    if ($is_post_receive && $auth_user !== null) {
        $push_error = null;
        try {
            git_process_push($repo_dir, $fs, $repo, $sqlite, $auth_user, $pre_state);
        } catch (\Throwable $e) {
            $push_error = $e->getMessage();
            error_log("Push processing error: $push_error\n" . $e->getTraceAsString());
        }

        if ($push_error !== null) {
            // Roll back branchfs, replace the buffered response with HTTP 500.
            git_rollback_to_state($pre_state, $sqlite);
            if (ob_get_level() > 0) ob_end_clean();
            http_response_code(500);
            header('Content-Type: text/plain');
            $short = substr(str_replace(["\n", "\r"], ' ', $push_error), 0, 500);
            echo "branchfs: push rejected: $short\n";
            return;
        }

        // Success: flush the buffered git protocol response to the client.
        if (ob_get_level() > 0) ob_end_flush();
    }
}

/**
 * Capture per-branch pre-push state for transactional rollback.
 * Records current fs_commit id for every branchfs branch.
 */
function git_capture_pre_state(SQLite3 $sqlite): array {
    $state = ['branches' => []];
    $r = $sqlite->query("SELECT id, name FROM branches");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $name = $row['name'];
        $bid  = (int)$row['id'];
        $fs_commit = git_fs_last_commit($sqlite, $bid);
        $state['branches'][$name] = [
            'bid'       => $bid,
            'fs_commit' => $fs_commit ? (int)$fs_commit['id'] : null,
        ];
    }
    return $state;
}

/**
 * Roll branchfs back to the captured pre-push state.
 * For branches that existed: restore fs files from their pre-push fs_commit.
 * For branches created by the push but not in pre_state: delete them entirely.
 */
function git_rollback_to_state(?array $state, SQLite3 $sqlite): void {
    if (!$state) return;

    // 1) Delete NEW branches that weren't in pre_state.
    $r = $sqlite->query("SELECT id, name FROM branches");
    $current = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $current[] = $row;
    foreach ($current as $row) {
        if (!isset($state['branches'][$row['name']])) {
            $bid = (int)$row['id'];
            $sqlite->exec("DELETE FROM fs_commit_files WHERE commit_id IN (SELECT id FROM fs_commits WHERE branch_id=$bid)");
            $sqlite->exec("DELETE FROM fs_commits WHERE branch_id = $bid");
            $sqlite->exec("DELETE FROM files WHERE branch_id = $bid");
            $sqlite->exec("DELETE FROM branches WHERE id = $bid");
        }
    }

    // 2) Reset EXISTING branches to their pre-push fs_commit.
    foreach ($state['branches'] as $name => $b) {
        if ($b['fs_commit'] !== null) {
            git_fs_restore_from_commit($sqlite, (int)$b['bid'], (int)$b['fs_commit']);
        }
    }
}

/**
 * Restore branch overlay from a specific fs_commit id (rollback helper).
 */
function git_fs_restore_from_commit(SQLite3 $db, int $branch_id, int $commit_id): void {
    // TODO3 #5: under delta encoding, the target commit's raw rows only
    // hold changes since the previous commit. Use the chain walker so
    // restore sees the full materialized tree.
    require_once __DIR__ . '/../fs_commit_helpers.php';
    $tree = fs_materialize_commit_tree($db, $commit_id);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $db->exec("DELETE FROM files WHERE branch_id = $branch_id");
        $ins = $db->prepare(
            "INSERT INTO files (branch_id, path, blob_hash, mode, mtime, is_dir) "
          . "VALUES (:b, :p, :bh, :md, :mt, :d)"
        );
        foreach ($tree as $path => $row) {
            $ins->bindValue(':b',  $branch_id, SQLITE3_INTEGER);
            $ins->bindValue(':p',  $row['path'], SQLITE3_TEXT);
            $ins->bindValue(':bh', $row['blob_hash'] ?? null,
                $row['blob_hash'] ? SQLITE3_TEXT : SQLITE3_NULL);
            $ins->bindValue(':md', (int)($row['mode']  ?? 0), SQLITE3_INTEGER);
            $ins->bindValue(':mt', (int)($row['mtime'] ?? 0), SQLITE3_INTEGER);
            $ins->bindValue(':d',  (int)($row['is_dir']?? 0), SQLITE3_INTEGER);
            $ins->execute();
            $ins->reset();
        }
        $db->exec('COMMIT');
    } catch (\Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

/**
 * Reserved names that conflict with HTTP routing or well-known subdomains.
 * Pushes creating a branch with any of these names are rejected with 400.
 */
function git_reserved_branch_names(): array {
    return ['www', 'admin', 'api', 'mail', 'localhost', 'wp'];
}

/**
 * Return whether auth_enabled = '1' in site_config. Defaults to false so
 * sites that pre-date the auth feature (no site_config row) stay open.
 */
function git_site_auth_enabled(?SQLite3 $sqlite): bool {
    if ($sqlite === null) return false;
    // Ensure the table exists so the SELECT doesn't blow up on stores
    // still on the old schema.
    try {
        $sqlite->exec("CREATE TABLE IF NOT EXISTS site_config (key TEXT PRIMARY KEY, value TEXT)");
    } catch (\Throwable $e) {
        return false;
    }
    $v = (string)$sqlite->querySingle("SELECT value FROM site_config WHERE key='auth_enabled'");
    return $v === '1';
}

/**
 * Return the role of an authenticated user, or null if the user is absent.
 */
function git_user_role(?SQLite3 $sqlite, string $username): ?string {
    if ($sqlite === null) return null;
    $s = $sqlite->prepare("SELECT role FROM users WHERE username = :u");
    $s->bindValue(':u', $username, SQLITE3_TEXT);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_NUM);
    return $row ? (string)$row[0] : null;
}

/**
 * Validate push auth.
 *
 * Priority:
 *   1. If a SQLite3 handle is provided AND the users table has any rows,
 *      validate against it (bcrypt password_verify).
 *   2. Else fall back to env-var auth (BRANCHFS_GIT_USER /
 *      BRANCHFS_GIT_PASSWORD_HASH, defaulting to admin/admin in dev and
 *      rejecting admin/admin under BRANCHFS_PROD=1). This path is
 *      preserved purely for backward compatibility with existing setups
 *      and their unit tests; new deployments should use the users table.
 *
 * Returns the authenticated username on success, null on failure.
 */
function git_check_auth(?SQLite3 $sqlite = null): ?string {
    if (!isset($_SERVER['PHP_AUTH_USER'])) {
        return null;
    }

    $user = (string)($_SERVER['PHP_AUTH_USER'] ?? '');
    $pass = (string)($_SERVER['PHP_AUTH_PW']   ?? '');

    // Prefer DB-backed auth when a users table is populated.
    if ($sqlite !== null) {
        try {
            $sqlite->exec(
                "CREATE TABLE IF NOT EXISTS users ("
              . "  username      TEXT PRIMARY KEY, "
              . "  password_hash TEXT NOT NULL, "
              . "  mysql_sha1    TEXT, "
              . "  role          TEXT NOT NULL CHECK(role IN ('admin','write','read')), "
              . "  created_at    TEXT DEFAULT (datetime('now'))"
              . ")"
            );
        } catch (\Throwable $e) {
            // ignore — fall through to env fallback below
        }
        $user_count = (int)$sqlite->querySingle("SELECT COUNT(*) FROM users");
        if ($user_count > 0) {
            $s = $sqlite->prepare("SELECT password_hash FROM users WHERE username = :u");
            $s->bindValue(':u', $user, SQLITE3_TEXT);
            $r = $s->execute();
            $row = $r->fetchArray(SQLITE3_ASSOC);
            if ($row && password_verify($pass, (string)$row['password_hash'])) {
                return $user;
            }
            return null;
        }
    }

    // Env-var fallback for back-compat.
    $env_user = getenv('BRANCHFS_GIT_USER')          ?: 'admin';
    $env_hash = getenv('BRANCHFS_GIT_PASSWORD_HASH') ?: '';

    // In prod we REFUSE the built-in default creds. Better to explicitly
    // break than silently accept admin/admin.
    $prod = getenv('BRANCHFS_PROD') === '1';
    if ($prod) {
        if (!getenv('BRANCHFS_GIT_USER') || !getenv('BRANCHFS_GIT_PASSWORD_HASH')) {
            error_log('BRANCHFS_PROD=1 but BRANCHFS_GIT_USER/BRANCHFS_GIT_PASSWORD_HASH not both set — refusing push');
            return null;
        }
    }

    if ($env_hash !== '') {
        if ($user === $env_user && password_verify($pass, $env_hash)) {
            return $user;
        }
        return null;
    }

    // Dev default: admin / admin. Rejected when BRANCHFS_PROD=1 (checked above).
    if ($user === 'admin' && $pass === 'admin') {
        return $user;
    }
    return null;
}

/**
 * Build a Git repository on disk from branchfs state.
 * Each branch contains the WordPress file tree plus database.sql, a read-only
 * SQL snapshot of that branch's WordPress tables for local agent context.
 */
function git_build_repository(string $repo_dir, SQLite3 $sqlite): void {
    // Clean and recreate
    if (is_dir($repo_dir)) {
        git_rmrf($repo_dir);
    }
    mkdir($repo_dir, 0755, true);

    // Let GitRepository handle its own initialization
    $fs = LocalFilesystem::create($repo_dir);
    $repo = new GitRepository($fs, ['default_branch' => 'main']);
    $repo->set_config_value(['user', 'name'], 'BranchFS');
    $repo->set_config_value(['user', 'email'], 'branchfs@local');

    // Get all branchfs branches
    $branches_result = $sqlite->query("SELECT id, name FROM branches ORDER BY name");
    $branches = [];
    while ($row = $branches_result->fetchArray(SQLITE3_ASSOC)) {
        $branches[] = $row;
    }

    // Build commits for each branch
    foreach ($branches as $br) {
        $branch_name = $br['name'];
        $branch_id = (int)$br['id'];

        // Get fs_commits for this branch in chronological order
        $fs_commits = [];
        $fcr = $sqlite->query("SELECT id, commit_hash, message, created_at FROM fs_commits WHERE branch_id = $branch_id ORDER BY id ASC");
        while ($row = $fcr->fetchArray(SQLITE3_ASSOC)) {
            $fs_commits[] = $row;
        }

        // Auto-snapshot current state if there are uncommitted file changes
        git_auto_snapshot($sqlite, $branch_name, $branch_id);

        // Re-fetch after auto-snapshot
        $fs_commits = [];
        $fcr = $sqlite->query("SELECT id, commit_hash, message, created_at FROM fs_commits WHERE branch_id = $branch_id ORDER BY id ASC");
        while ($row = $fcr->fetchArray(SQLITE3_ASSOC)) {
            $fs_commits[] = $row;
        }

        if (empty($fs_commits)) continue;

        $parent_git_hash = null;
        $tip_hash = null;

        require_once __DIR__ . '/../fs_commit_helpers.php';
        foreach ($fs_commits as $fc) {
            $fc_id = (int)$fc['id'];

            // TODO3 #5: materialize the commit's tree via the chain
            // walker so delta-encoded commits present the same view to
            // git clients as full-snapshot commits did.
            $updates = [];
            $tree = fs_materialize_commit_tree($sqlite, $fc_id);
            foreach ($tree as $p => $fr) {
                if (!empty($fr['is_dir']) || empty($fr['blob_hash'])) continue;
                $blob_data = git_get_blob_data($sqlite, $fr['blob_hash']);
                if ($blob_data !== null) {
                    $updates['wordpress/' . $fr['path']] = $blob_data;
                }
            }
            $updates['database.sql'] = git_dump_branch_database($sqlite, $branch_id, $branch_name);

            if (empty($updates)) continue;

            $commit_date = $fc['created_at'] ?? gmdate('Y-m-d H:i:s');
            $timestamp = strtotime($commit_date);
            if ($timestamp === false) $timestamp = time();
            $date_str = $timestamp . ' +0000';

            $author = 'BranchFS <branchfs@local>';
            $message = trim($fc['message'] ?? 'BranchFS commit');
            // Include fs commit_hash reference
            $message .= "\n\nFS-Commit: " . $fc['commit_hash'];

            $commit_options = [
                'commit' => [
                    'message' => $message,
                    'author' => $author,
                    'author_date' => $date_str,
                    'committer' => $author,
                    'committer_date' => $date_str,
                    'parents' => $parent_git_hash !== null ? [$parent_git_hash] : [],
                ],
                'updates' => $updates,
            ];

            // Checkout this branch before committing. Initialize non-default
            // branches to NULL_HASH so GitRepository can track them.
            $ref_path = "refs/heads/$branch_name";
            if ($parent_git_hash !== null) {
                $repo->set_branch_tip($ref_path, $parent_git_hash);
            } elseif (!$fs->is_file($ref_path)) {
                $repo->set_branch_tip($ref_path, Commit::NULL_HASH);
            }
            $repo->checkout($ref_path);

            $git_hash = $repo->commit($commit_options);
            $parent_git_hash = $git_hash;
            $tip_hash = $git_hash;
        }

        if ($tip_hash !== null) {
            $repo->set_branch_tip("refs/heads/$branch_name", $tip_hash);
        }
    }

    // Set HEAD to main
    file_put_contents($repo_dir . '/HEAD', "ref: refs/heads/main\n");
}

/**
 * Auto-snapshot if the overlay has diverged from the last fs_commit.
 */
function git_auto_snapshot(SQLite3 $sqlite, string $branch_name, int $branch_id): void {
    $last = git_fs_last_commit($sqlite, $branch_id);
    $tree = git_fs_resolve_tree($sqlite, $branch_id);
    $current_digest = git_fs_tree_digest($tree);

    if ($last) {
        $last_digest = git_fs_digest_of_commit($sqlite, (int)$last['id']);
        if ($current_digest === $last_digest) {
            return; // No divergence
        }
    }

    git_fs_record_snapshot($sqlite, $branch_id, 'Auto-snapshot for git');
}

/**
 * Process push: apply received git changes back to branchfs.
 * Note: database.sql changes pushed via git are not applied. The SQL file is
 * a read-only branch snapshot; WordPress writes still go through the preview
 * server and branchfs SQLite store.
 * Throws \RuntimeException on unrecoverable errors; the caller rolls back.
 */
function git_process_push(string $repo_dir, $fs, GitRepository $repo, SQLite3 $sqlite, string $auth_user, ?array $pre_state = null): void {
    $reserved = git_reserved_branch_names();

    $refs_dir = $repo_dir . '/refs/heads';
    if (!is_dir($refs_dir)) return;

    foreach (scandir($refs_dir) as $ref_file) {
        if ($ref_file[0] === '.') continue;
        $branch_name = $ref_file;
        $tip_hash = trim(file_get_contents($refs_dir . '/' . $ref_file));

        if (Commit::is_null_hash($tip_hash)) continue;

        // Reject reserved names that would conflict with HTTP routing.
        if (in_array(strtolower($branch_name), $reserved, true)) {
            throw new \RuntimeException(
                "refusing to push to reserved branch name '$branch_name' (conflicts with routing)"
            );
        }

        // Ensure the branchfs branch exists
        $branch_id = git_fs_branch_id($sqlite, $branch_name);
        $branch_is_new = ($branch_id === 0);
        if ($branch_is_new) {
            if (extension_loaded('branchfs')) {
                $db_path_env = getenv('BRANCHFS_DB');
                if ($db_path_env) {
                    branchfs_set_db($db_path_env);
                    branchfs_create_branch($branch_name, 'main');
                }
            }

            $branch_id = git_fs_branch_id($sqlite, $branch_name);
            if ($branch_id === 0) {
                throw new \RuntimeException("cannot create branchfs branch '$branch_name'");
            }
        }

        // Read the pushed commit
        try {
            $commit_obj = $repo->read_object($tip_hash);
            $commit = $commit_obj->as_commit();
        } catch (\Throwable $e) {
            throw new \RuntimeException("cannot read pushed commit $tip_hash: " . $e->getMessage());
        }

        $message = $commit->message ?? 'Push via git';
        $message = preg_replace('/\n\nFS-Commit:.*$/s', '', $message);

        // Extract the tree
        $tree_hash = $commit->tree;
        $all_files = [];
        git_walk_tree($repo, $tree_hash, '', $all_files);

        // Only persist wordpress/ files; ignore anything else.
        $wp_files = [];
        foreach ($all_files as $path => $blob_hash) {
            if (strncmp($path, 'wordpress/', 10) !== 0) continue;
            $wp_files[substr($path, 10)] = $blob_hash;
        }

        // Apply file changes to branchfs overlay
        git_apply_file_changes($sqlite, $branch_id, $wp_files, $repo);

        // Record a paired fs_commit
        git_fs_record_snapshot($sqlite, $branch_id, trim($message));
    }
}

/**
 * Walk a git tree recursively, collecting all blob paths and hashes.
 */
function git_walk_tree(GitRepository $repo, string $tree_hash, string $prefix, array &$result): void {
    $tree_obj = $repo->read_object($tree_hash);
    $tree = $tree_obj->as_tree();
    foreach ($tree->entries as $name => $entry) {
        $full_path = $prefix === '' ? $name : $prefix . '/' . $name;
        if ($entry->mode === '40000' || $entry->mode === TreeEntry::FILE_MODE_DIRECTORY) {
            git_walk_tree($repo, $entry->hash, $full_path, $result);
        } else {
            $result[$full_path] = $entry->hash;
        }
    }
}

/**
 * Apply file changes to the branchfs overlay by diffing against current state.
 */
function git_apply_file_changes(SQLite3 $sqlite, int $branch_id, array $new_files, GitRepository $repo): void {
    $current_tree = git_fs_resolve_tree($sqlite, $branch_id);

    // Files in new_files but not in current, or changed
    foreach ($new_files as $path => $blob_hash) {
        $new_content = $repo->read_object($blob_hash)->consume_all();
        $new_hash = hash('fnv1a64', $new_content);

        $current = $current_tree[$path] ?? null;
        if ($current !== null && $current['blob_hash'] === $new_hash) {
            continue; // unchanged
        }

        // Insert or update the blob
        $stmt = $sqlite->prepare("INSERT OR REPLACE INTO blobs (hash, data, size) VALUES (:h, :d, :s)");
        $stmt->bindValue(':h', $new_hash, SQLITE3_TEXT);
        $stmt->bindValue(':d', $new_content, SQLITE3_BLOB);
        $stmt->bindValue(':s', strlen($new_content), SQLITE3_INTEGER);
        $stmt->execute();

        // Insert or update the file entry
        $stmt = $sqlite->prepare("INSERT OR REPLACE INTO files (branch_id, path, blob_hash, mode, mtime, is_dir) VALUES (:b, :p, :h, :m, :t, 0)");
        $stmt->bindValue(':b', $branch_id, SQLITE3_INTEGER);
        $stmt->bindValue(':p', $path, SQLITE3_TEXT);
        $stmt->bindValue(':h', $new_hash, SQLITE3_TEXT);
        $stmt->bindValue(':m', 33188, SQLITE3_INTEGER);
        $stmt->bindValue(':t', time(), SQLITE3_INTEGER);
        $stmt->execute();
    }

    // Files deleted (in current but not in new)
    foreach ($current_tree as $path => $entry) {
        if ($entry['is_dir']) continue;
        if (!isset($new_files[$path])) {
            // Tombstone
            $stmt = $sqlite->prepare("INSERT OR REPLACE INTO files (branch_id, path, blob_hash, mode, mtime, is_dir) VALUES (:b, :p, NULL, 0, :t, 0)");
            $stmt->bindValue(':b', $branch_id, SQLITE3_INTEGER);
            $stmt->bindValue(':p', $path, SQLITE3_TEXT);
            $stmt->bindValue(':t', time(), SQLITE3_INTEGER);
            $stmt->execute();
        }
    }
}

function git_dump_branch_database(SQLite3 $sqlite, int $branch_id, string $branch_name): string {
    $prefix = 'b' . $branch_id . '_wp_';
    $parent_prefix = git_parent_database_prefix($sqlite, $branch_id);
    $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%';
    $stmt = $sqlite->prepare(
        "SELECT name FROM sqlite_master
         WHERE type IN ('table', 'view') AND name LIKE :prefix ESCAPE '\\'
         ORDER BY name"
    );
    $stmt->bindValue(':prefix', $like, SQLITE3_TEXT);
    $result = $stmt->execute();

    $out = "-- ForkPress database snapshot for branch " . git_sql_comment($branch_name) . "\n";
    $out .= "-- This file is read-only in git; push WordPress file changes from wordpress/.\n";
    $out .= "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n\n";

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $store_table = (string)$row['name'];
        if (strncmp($store_table, $prefix, strlen($prefix)) !== 0) {
            continue;
        }
        if (str_ends_with($store_table, '__overlay') || str_ends_with($store_table, '__tombstones')) {
            continue;
        }
        $suffix = substr($store_table, strlen($prefix));
        $source_table = $store_table;
        $fallback_table = $parent_prefix ? $parent_prefix . $suffix : null;
        $logical_table = 'wp_' . substr($store_table, strlen($prefix));
        $columns = git_table_columns($sqlite, $store_table);
        if (!$columns && $fallback_table) {
            $fallback_columns = git_table_columns($sqlite, $fallback_table);
            if ($fallback_columns) {
                $source_table = $fallback_table;
                $columns = $fallback_columns;
            }
        }
        if (!$columns) {
            continue;
        }

        $out .= 'DROP TABLE IF EXISTS ' . git_sql_ident($logical_table) . ";\n";
        $defs = [];
        foreach ($columns as $column) {
            $type = trim((string)($column['type'] ?? ''));
            $defs[] = git_sql_ident((string)$column['name']) . ' ' . ($type !== '' ? $type : 'TEXT');
        }
        $out .= 'CREATE TABLE ' . git_sql_ident($logical_table) . ' (' . implode(', ', $defs) . ");\n";

        $select = $sqlite->query('SELECT * FROM ' . git_sql_ident($source_table));
        if ($select === false && $fallback_table && $source_table !== $fallback_table) {
            $source_table = $fallback_table;
            $select = $sqlite->query('SELECT * FROM ' . git_sql_ident($source_table));
        }
        if ($select === false) {
            continue;
        }
        while ($data = $select->fetchArray(SQLITE3_ASSOC)) {
            $names = [];
            $values = [];
            foreach ($columns as $column) {
                $name = (string)$column['name'];
                $names[] = git_sql_ident($name);
                $values[] = git_sql_literal($data[$name] ?? null);
            }
            $out .= 'INSERT INTO ' . git_sql_ident($logical_table)
                . ' (' . implode(', ', $names) . ') VALUES ('
                . implode(', ', $values) . ");\n";
        }
        $out .= "\n";
    }

    $out .= "COMMIT;\n";
    return $out;
}

function git_parent_database_prefix(SQLite3 $sqlite, int $branch_id): ?string {
    $stmt = $sqlite->prepare(
        "SELECT parent.id AS parent_id
         FROM branches child
         JOIN branches parent ON child.parent_branch = parent.name
         WHERE child.id = :branch_id
         LIMIT 1"
    );
    $stmt->bindValue(':branch_id', $branch_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    if (!$row || empty($row['parent_id'])) {
        return null;
    }
    return 'b' . (int)$row['parent_id'] . '_wp_';
}

function git_table_columns(SQLite3 $sqlite, string $table): array {
    $columns = [];
    $result = $sqlite->query('PRAGMA table_info(' . git_sql_ident($table) . ')');
    if ($result === false) {
        return [];
    }
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $columns[] = $row;
    }
    return $columns;
}

function git_sql_ident(string $identifier): string {
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function git_sql_literal($value): string {
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }
    return "'" . SQLite3::escapeString((string)$value) . "'";
}

function git_sql_comment(string $text): string {
    return str_replace(["\r", "\n"], ' ', $text);
}

/**
 * Get blob data from SQLite store.
 */
function git_get_blob_data(SQLite3 $sqlite, ?string $hash): ?string {
    if ($hash === null) return null;
    $stmt = $sqlite->prepare("SELECT data FROM blobs WHERE hash = :h");
    $stmt->bindValue(':h', $hash, SQLITE3_TEXT);
    $r = $stmt->execute();
    $row = $r->fetchArray(SQLITE3_NUM);
    return $row ? $row[0] : null;
}

function git_rmrf(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $f) {
        if ($f->isDir()) {
            @rmdir($f->getRealPath());
        } else {
            @unlink($f->getRealPath());
        }
    }
    @rmdir($dir);
}

<?php

$pass = 0;
$fail = 0;

function assert_true($cond, $msg) {
    global $pass, $fail;
    if ($cond) {
        echo "  PASS: $msg\n";
        $pass++;
    } else {
        echo "  FAIL: $msg\n";
        $fail++;
    }
}

function assert_same($actual, $expected, $msg) {
    assert_true(
        $actual === $expected,
        "$msg (got " . var_export($actual, true) . ", expected " . var_export($expected, true) . ")"
    );
}

function remove_tree(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

function open_db(string $path): SQLite3 {
    $db = new SQLite3($path);
    $db->busyTimeout(5000);
    return $db;
}

function scalar(string $db_path, string $sql): mixed {
    $db = open_db($db_path);
    $value = $db->querySingle($sql);
    $db->close();
    return $value;
}

function create_explicit_id_db(string $path): void {
    $db = open_db($path);
    $db->exec('CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY AUTOINCREMENT, post_title TEXT NOT NULL, post_content TEXT NOT NULL, post_status TEXT NOT NULL)');
    $db->exec('CREATE TABLE plugin_autoinc (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT NOT NULL)');
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status) VALUES (1, 'Base page', 'Base content', 'draft')");
    $db->exec("INSERT INTO plugin_autoinc (id, label) VALUES (1, 'base plugin row')");
    $db->close();
}

function create_explicit_user_graph_db(string $path): void {
    $db = open_db($path);
    $db->exec('CREATE TABLE wp_users (ID INTEGER PRIMARY KEY AUTOINCREMENT, user_login TEXT NOT NULL)');
    $db->exec('CREATE TABLE wp_usermeta (umeta_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)');
    $db->exec('CREATE TABLE wp_comments (comment_ID INTEGER PRIMARY KEY AUTOINCREMENT, comment_post_ID INTEGER NOT NULL, comment_content TEXT NOT NULL, user_id INTEGER NOT NULL DEFAULT 0)');
    $db->exec("INSERT INTO wp_users (ID, user_login) VALUES (1, 'base-user')");
    $db->exec("INSERT INTO wp_usermeta (umeta_id, user_id, meta_key, meta_value) VALUES (1, 1, 'base_key', 'base value')");
    $db->exec("INSERT INTO wp_comments (comment_ID, comment_post_ID, comment_content, user_id) VALUES (1, 1, 'base explicit user comment', 1)");
    $db->close();
}

function create_explicit_comment_graph_db(string $path): void {
    $db = open_db($path);
    $db->exec('CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY AUTOINCREMENT, post_title TEXT NOT NULL, post_content TEXT NOT NULL, post_status TEXT NOT NULL)');
    $db->exec('CREATE TABLE wp_comments (comment_ID INTEGER PRIMARY KEY AUTOINCREMENT, comment_post_ID INTEGER NOT NULL, comment_content TEXT NOT NULL, comment_parent INTEGER NOT NULL DEFAULT 0)');
    $db->exec('CREATE TABLE wp_commentmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, comment_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)');
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status) VALUES (1, 'Base comment page', 'Base content', 'publish')");
    $db->exec("INSERT INTO wp_comments (comment_ID, comment_post_ID, comment_content, comment_parent) VALUES (1, 1, 'base explicit comment', 0)");
    $db->exec("INSERT INTO wp_commentmeta (meta_id, comment_id, meta_key, meta_value) VALUES (1, 1, 'base_comment_key', 'base comment value')");
    $db->close();
}

function create_explicit_term_graph_db(string $path): void {
    $db = open_db($path);
    $db->exec('CREATE TABLE wp_terms (term_id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL)');
    $db->exec('CREATE TABLE wp_termmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, term_id INTEGER NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL)');
    $db->exec('CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY AUTOINCREMENT, term_id INTEGER NOT NULL, taxonomy TEXT NOT NULL, description TEXT NOT NULL DEFAULT "", parent INTEGER NOT NULL DEFAULT 0, count INTEGER NOT NULL DEFAULT 0)');
    $db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES (1, 'Base term', 'base-term')");
    $db->exec("INSERT INTO wp_termmeta (meta_id, term_id, meta_key, meta_value) VALUES (1, 1, 'base_term_key', 'base term value')");
    $db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES (1, 1, 'category', '', 0, 1)");
    $db->close();
}

define('FORKPRESS_COW_MERGE_TESTS', true);
require_once __DIR__ . '/../../scripts/cow/merge.php';

echo "=== COW explicit ID focused tests ===\n";

$tmp = sys_get_temp_dir() . '/forkpress-cow-explicit-ids-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);

try {
    $base = $tmp . '/base.sqlite';
    $source = $tmp . '/source.sqlite';
    $target = $tmp . '/target.sqlite';
    $metadata = $tmp . '/.forkpress/cow/merge/explicit-id-metadata.sqlite';

    create_explicit_id_db($base);
    copy($base, $source);
    copy($base, $target);

    $band_result = cow_merge_allocate_autoincrement_bands($source, $metadata, 'feature-explicit-import');
    assert_same($band_result['allocated'], 2, 'source branch allocates AUTOINCREMENT bands before imports');

    $source_db = open_db($source);
    $source_db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status) VALUES (2, 'Imported explicit post', 'explicit id import', 'publish')");
    $source_db->exec("INSERT INTO plugin_autoinc (id, label) VALUES (2, 'imported explicit plugin row')");
    $source_db->close();

    $result = cow_merge_databases($base, $source, $target, $metadata, 'feature-explicit-import', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'out-of-band explicit AUTOINCREMENT imports stay review-held');

    assert_same(
        (int)scalar($target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 2'),
        0,
        'out-of-band explicit WordPress post ID is not applied automatically'
    );
    assert_same(
        (int)scalar($target, 'SELECT COUNT(*) FROM plugin_autoinc WHERE id = 2'),
        0,
        'out-of-band explicit plugin AUTOINCREMENT ID is not applied automatically'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE r.source_branch = 'feature-explicit-import' AND c.table_name = 'wp_posts' AND c.conflict_type = 'row-target-constraint'"),
        1,
        'out-of-band explicit WordPress post records a review conflict'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE r.source_branch = 'feature-explicit-import' AND c.table_name = 'plugin_autoinc' AND c.conflict_type = 'row-target-constraint'"),
        1,
        'out-of-band explicit plugin row records a review conflict'
    );

    $wp_reason = (string)scalar($metadata, "SELECT reason FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE r.source_branch = 'feature-explicit-import' AND d.table_name = 'wp_posts' AND d.decision = 'target-wins' ORDER BY d.id DESC LIMIT 1");
    $plugin_reason = (string)scalar($metadata, "SELECT reason FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE r.source_branch = 'feature-explicit-import' AND d.table_name = 'plugin_autoinc' AND d.decision = 'target-wins' ORDER BY d.id DESC LIMIT 1");
    assert_true(str_contains($wp_reason, 'outside reserved branch band'), 'WordPress explicit-ID conflict explains the branch-band violation');
    assert_true(str_contains($plugin_reason, 'outside reserved branch band'), 'plugin explicit-ID conflict explains the branch-band violation');

    $audit = cow_merge_audit_report($metadata, null, 20, ['records' => 'conflicts']);
    $tables = array_map(fn($record) => $record['table_name'] ?? '', $audit['conflicts'] ?? []);
    assert_true(in_array('wp_posts', $tables, true), 'audit report exposes the WordPress explicit-ID conflict');
    assert_true(in_array('plugin_autoinc', $tables, true), 'audit report exposes the plugin explicit-ID conflict');

    $in_band_base = $tmp . '/in-band-base.sqlite';
    $in_band_source = $tmp . '/in-band-source.sqlite';
    $in_band_target = $tmp . '/in-band-target.sqlite';
    $in_band_metadata = $tmp . '/.forkpress/cow/merge/explicit-id-in-band-metadata.sqlite';
    create_explicit_id_db($in_band_base);
    copy($in_band_base, $in_band_source);
    copy($in_band_base, $in_band_target);
    cow_merge_allocate_autoincrement_bands($in_band_source, $in_band_metadata, 'feature-explicit-in-band');
    $wp_band_start = (int)scalar($in_band_metadata, "SELECT band_start FROM merge_autoincrement_bands WHERE branch_name = 'feature-explicit-in-band' AND table_name = 'wp_posts'");
    $plugin_band_end = (int)scalar($in_band_metadata, "SELECT band_end FROM merge_autoincrement_bands WHERE branch_name = 'feature-explicit-in-band' AND table_name = 'plugin_autoinc'");

    $in_band_source_db = open_db($in_band_source);
    $in_band_source_db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status) VALUES ($wp_band_start, 'In-band explicit post', 'explicit in-band import', 'publish')");
    $in_band_source_db->exec("INSERT INTO plugin_autoinc (id, label) VALUES ($plugin_band_end, 'in-band explicit plugin row')");
    $in_band_source_db->close();

    $in_band_result = cow_merge_databases($in_band_base, $in_band_source, $in_band_target, $in_band_metadata, 'feature-explicit-in-band', 'main');
    assert_same($in_band_result['status'], 'completed', 'in-band explicit AUTOINCREMENT imports merge automatically');
    assert_same(
        scalar($in_band_target, "SELECT post_title FROM wp_posts WHERE ID = $wp_band_start"),
        'In-band explicit post',
        'in-band explicit WordPress post ID is preserved without rewrite'
    );
    assert_same(
        scalar($in_band_target, "SELECT label FROM plugin_autoinc WHERE id = $plugin_band_end"),
        'in-band explicit plugin row',
        'in-band explicit plugin AUTOINCREMENT ID is preserved without rewrite'
    );
    assert_same(
        (int)scalar($in_band_metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE r.source_branch = 'feature-explicit-in-band' AND c.conflict_type = 'row-target-constraint'"),
        0,
        'in-band explicit AUTOINCREMENT imports do not create branch-band conflicts'
    );

    $rewrite_base = $tmp . '/rewrite-base.sqlite';
    $rewrite_source = $tmp . '/rewrite-source.sqlite';
    $rewrite_target = $tmp . '/rewrite-target.sqlite';
    $rewrite_metadata = $tmp . '/.forkpress/cow/merge/explicit-id-rewrite-metadata.sqlite';
    create_explicit_id_db($rewrite_base);
    copy($rewrite_base, $rewrite_source);
    copy($rewrite_base, $rewrite_target);
    cow_merge_allocate_autoincrement_bands($rewrite_source, $rewrite_metadata, 'feature-explicit-rewrite');

    $rewrite_source_db = open_db($rewrite_source);
    $rewrite_source_db->exec("UPDATE wp_posts SET ID = 2, post_title = 'Rewritten explicit post ID' WHERE ID = 1");
    $rewrite_source_db->exec("UPDATE plugin_autoinc SET id = 2, label = 'rewritten explicit plugin ID' WHERE id = 1");
    $rewrite_source_db->close();

    $rewrite_result = cow_merge_databases($rewrite_base, $rewrite_source, $rewrite_target, $rewrite_metadata, 'feature-explicit-rewrite', 'main');
    assert_same($rewrite_result['status'], 'completed_with_conflicts', 'out-of-band AUTOINCREMENT primary-key rewrites remain review-held');
    assert_same((int)scalar($rewrite_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 1'), 1, 'out-of-band WordPress primary-key rewrite keeps the original target row by default');
    assert_same((int)scalar($rewrite_target, 'SELECT COUNT(*) FROM wp_posts WHERE ID = 2'), 0, 'out-of-band WordPress primary-key rewrite does not insert the rewritten explicit ID by default');
    assert_same((int)scalar($rewrite_target, 'SELECT COUNT(*) FROM plugin_autoinc WHERE id = 1'), 1, 'out-of-band plugin primary-key rewrite keeps the original target row by default');
    assert_same((int)scalar($rewrite_target, 'SELECT COUNT(*) FROM plugin_autoinc WHERE id = 2'), 0, 'out-of-band plugin primary-key rewrite does not insert the rewritten explicit ID by default');
    assert_same(
        (int)scalar($rewrite_metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE r.source_branch = 'feature-explicit-rewrite' AND c.table_name = 'wp_posts' AND c.conflict_type = 'row-target-constraint'"),
        2,
        'out-of-band WordPress primary-key rewrite records reviewable insert and paired delete conflicts'
    );
    assert_same(
        (int)scalar($rewrite_metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE r.source_branch = 'feature-explicit-rewrite' AND c.table_name = 'plugin_autoinc' AND c.conflict_type = 'row-target-constraint'"),
        2,
        'out-of-band plugin primary-key rewrite records reviewable insert and paired delete conflicts'
    );
    assert_true(
        str_contains((string)scalar($rewrite_metadata, "SELECT reason FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE r.source_branch = 'feature-explicit-rewrite' AND d.table_name = 'wp_posts' AND d.row_identity = '" . SQLite3::escapeString(cow_merge_identity_json(['ID' => 1])) . "' ORDER BY d.id DESC LIMIT 1"), 'held explicit AUTOINCREMENT insert'),
        'out-of-band WordPress primary-key rewrite explains why the paired source delete is held'
    );
    assert_true(
        str_contains((string)scalar($rewrite_metadata, "SELECT reason FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE r.source_branch = 'feature-explicit-rewrite' AND d.table_name = 'plugin_autoinc' AND d.row_identity = '" . SQLite3::escapeString(cow_merge_identity_json(['id' => 1])) . "' ORDER BY d.id DESC LIMIT 1"), 'held explicit AUTOINCREMENT insert'),
        'out-of-band plugin primary-key rewrite explains why the paired source delete is held'
    );

    $user_graph_base = $tmp . '/user-graph-base.sqlite';
    $user_graph_source = $tmp . '/user-graph-source.sqlite';
    $user_graph_target = $tmp . '/user-graph-target.sqlite';
    $user_graph_metadata = $tmp . '/.forkpress/cow/merge/explicit-user-graph-metadata.sqlite';
    create_explicit_user_graph_db($user_graph_base);
    copy($user_graph_base, $user_graph_source);
    copy($user_graph_base, $user_graph_target);
    cow_merge_allocate_autoincrement_bands($user_graph_source, $user_graph_metadata, 'feature-explicit-user-graph');

    $user_graph_source_db = open_db($user_graph_source);
    $user_graph_source_db->exec("INSERT INTO wp_users (ID, user_login) VALUES (2, 'imported-explicit-user')");
    $user_graph_source_db->exec("INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (2, 'profile_json', '{\"user_id\":2}')");
    $user_graph_source_db->exec("INSERT INTO wp_comments (comment_post_ID, comment_content, user_id) VALUES (1, 'comment behind explicit user import', 2)");
    $user_graph_source_db->exec("UPDATE wp_comments SET user_id = 2 WHERE comment_ID = 1");
    $user_graph_source_db->close();

    $user_graph_result = cow_merge_databases($user_graph_base, $user_graph_source, $user_graph_target, $user_graph_metadata, 'feature-explicit-user-graph', 'main');
    assert_same($user_graph_result['status'], 'completed_with_conflicts', 'out-of-band explicit WordPress user import keeps its dependent graph review-held');
    assert_same(
        (int)scalar($user_graph_target, 'SELECT COUNT(*) FROM wp_users WHERE ID = 2'),
        0,
        'out-of-band explicit WordPress user ID is not applied automatically'
    );
    assert_same(
        (int)scalar($user_graph_target, "SELECT COUNT(*) FROM wp_usermeta WHERE user_id = 2 AND meta_key = 'profile_json'"),
        0,
        'usermeta behind a held explicit WordPress user ID is not applied automatically'
    );
    assert_same(
        (int)scalar($user_graph_target, "SELECT COUNT(*) FROM wp_comments WHERE user_id = 2"),
        0,
        'comments behind a held explicit WordPress user ID are not applied automatically'
    );
    assert_same(
        (int)scalar($user_graph_target, "SELECT user_id FROM wp_comments WHERE comment_content = 'base explicit user comment'"),
        1,
        'updated comments behind a held explicit WordPress user ID are not applied automatically'
    );
    assert_same(
        (int)scalar($user_graph_metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE r.source_branch = 'feature-explicit-user-graph' AND c.table_name = 'wp_users' AND c.conflict_type = 'row-target-constraint'"),
        1,
        'out-of-band explicit WordPress user records a review conflict'
    );
    assert_same(
        (int)scalar($user_graph_metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE r.source_branch = 'feature-explicit-user-graph' AND c.table_name = 'wp_usermeta' AND c.conflict_type = 'row-target-constraint'"),
        1,
        'usermeta behind a held explicit WordPress user records a review conflict'
    );
    assert_same(
        (int)scalar($user_graph_metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE r.source_branch = 'feature-explicit-user-graph' AND c.table_name = 'wp_comments' AND c.conflict_type = 'row-target-constraint'"),
        2,
        'comments behind a held explicit WordPress user record review conflicts'
    );
    $user_meta_reason = (string)scalar($user_graph_metadata, "SELECT reason FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE r.source_branch = 'feature-explicit-user-graph' AND d.table_name = 'wp_usermeta' AND d.decision = 'target-wins' ORDER BY d.id DESC LIMIT 1");
    assert_true(
        str_contains($user_meta_reason, 'outside the source branch ID band') && str_contains($user_meta_reason, 'wp_users'),
        'usermeta conflict explains that it is held behind the explicit WordPress user ID'
    );
    $user_comment_reason = (string)scalar($user_graph_metadata, "SELECT reason FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE r.source_branch = 'feature-explicit-user-graph' AND d.table_name = 'wp_comments' AND d.decision = 'target-wins' ORDER BY d.id DESC LIMIT 1");
    assert_true(
        str_contains($user_comment_reason, 'outside the source branch ID band') && str_contains($user_comment_reason, 'wp_users'),
        'comment conflict explains that it is held behind the explicit WordPress user ID'
    );

    $comment_graph_base = $tmp . '/comment-graph-base.sqlite';
    $comment_graph_source = $tmp . '/comment-graph-source.sqlite';
    $comment_graph_target = $tmp . '/comment-graph-target.sqlite';
    $comment_graph_metadata = $tmp . '/.forkpress/cow/merge/explicit-comment-graph-metadata.sqlite';
    create_explicit_comment_graph_db($comment_graph_base);
    copy($comment_graph_base, $comment_graph_source);
    copy($comment_graph_base, $comment_graph_target);
    cow_merge_allocate_autoincrement_bands($comment_graph_source, $comment_graph_metadata, 'feature-explicit-comment-graph');
    $comment_band_start = (int)scalar($comment_graph_metadata, "SELECT band_start FROM merge_autoincrement_bands WHERE branch_name = 'feature-explicit-comment-graph' AND table_name = 'wp_comments'");

    $comment_graph_source_db = open_db($comment_graph_source);
    $comment_graph_source_db->exec("INSERT INTO wp_comments (comment_ID, comment_post_ID, comment_content, comment_parent) VALUES (2, 1, 'imported explicit comment', 0)");
    $comment_graph_source_db->exec("INSERT INTO wp_commentmeta (comment_id, meta_key, meta_value) VALUES (2, 'comment_graph_json', '{\"comment_id\":2}')");
    $comment_graph_source_db->exec("UPDATE wp_commentmeta SET comment_id = 2 WHERE meta_id = 1");
    $comment_graph_source_db->exec("INSERT INTO wp_comments (comment_ID, comment_post_ID, comment_content, comment_parent) VALUES ($comment_band_start, 1, 'threaded comment behind explicit comment', 2)");
    $comment_graph_source_db->close();

    $comment_graph_result = cow_merge_databases($comment_graph_base, $comment_graph_source, $comment_graph_target, $comment_graph_metadata, 'feature-explicit-comment-graph', 'main');
    assert_same($comment_graph_result['status'], 'completed_with_conflicts', 'out-of-band explicit WordPress comment import keeps its dependent graph review-held');
    assert_same(
        (int)scalar($comment_graph_target, 'SELECT COUNT(*) FROM wp_comments WHERE comment_ID = 2'),
        0,
        'out-of-band explicit WordPress comment ID is not applied automatically'
    );
    assert_same(
        (int)scalar($comment_graph_target, 'SELECT COUNT(*) FROM wp_commentmeta WHERE comment_id = 2'),
        0,
        'commentmeta behind a held explicit WordPress comment ID is not applied automatically'
    );
    assert_same(
        (int)scalar($comment_graph_target, "SELECT comment_id FROM wp_commentmeta WHERE meta_key = 'base_comment_key'"),
        1,
        'updated commentmeta behind a held explicit WordPress comment ID is not applied automatically'
    );
    assert_same(
        (int)scalar($comment_graph_target, "SELECT COUNT(*) FROM wp_comments WHERE comment_parent = 2"),
        0,
        'threaded comments behind a held explicit WordPress comment ID are not applied automatically'
    );
    assert_same(
        (int)scalar($comment_graph_metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE r.source_branch = 'feature-explicit-comment-graph' AND c.table_name = 'wp_comments' AND c.conflict_type = 'row-target-constraint'"),
        2,
        'explicit comment imports and threaded comments behind them record review conflicts'
    );
    assert_same(
        (int)scalar($comment_graph_metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE r.source_branch = 'feature-explicit-comment-graph' AND c.table_name = 'wp_commentmeta' AND c.conflict_type = 'row-target-constraint'"),
        2,
        'commentmeta behind a held explicit WordPress comment records review conflicts'
    );
    $comment_meta_reason = (string)scalar($comment_graph_metadata, "SELECT reason FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE r.source_branch = 'feature-explicit-comment-graph' AND d.table_name = 'wp_commentmeta' AND d.decision = 'target-wins' ORDER BY d.id DESC LIMIT 1");
    assert_true(
        str_contains($comment_meta_reason, 'outside the source branch ID band') && str_contains($comment_meta_reason, 'wp_comments'),
        'commentmeta conflict explains that it is held behind the explicit WordPress comment ID'
    );
    assert_same(
        (int)scalar($comment_graph_metadata, "SELECT COUNT(*) FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE r.source_branch = 'feature-explicit-comment-graph' AND d.table_name = 'wp_comments' AND d.decision = 'target-wins' AND d.reason LIKE '%outside the source branch ID band%' AND d.reason LIKE '%wp_comments%'"),
        1,
        'threaded comment conflict explains that it is held behind the explicit WordPress comment ID'
    );

    $term_graph_base = $tmp . '/term-graph-base.sqlite';
    $term_graph_source = $tmp . '/term-graph-source.sqlite';
    $term_graph_target = $tmp . '/term-graph-target.sqlite';
    $term_graph_metadata = $tmp . '/.forkpress/cow/merge/explicit-term-graph-metadata.sqlite';
    create_explicit_term_graph_db($term_graph_base);
    copy($term_graph_base, $term_graph_source);
    copy($term_graph_base, $term_graph_target);
    cow_merge_allocate_autoincrement_bands($term_graph_source, $term_graph_metadata, 'feature-explicit-term-graph');

    $term_graph_source_db = open_db($term_graph_source);
    $term_graph_source_db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES (2, 'Imported explicit term', 'imported-explicit-term')");
    $term_graph_source_db->exec("INSERT INTO wp_termmeta (term_id, meta_key, meta_value) VALUES (2, 'term_graph_json', '{\"term_id\":2}')");
    $term_graph_source_db->exec("UPDATE wp_termmeta SET term_id = 2 WHERE meta_id = 1");
    $term_graph_source_db->exec("INSERT INTO wp_term_taxonomy (term_id, taxonomy, description, parent, count) VALUES (2, 'category', '', 0, 1)");
    $term_graph_source_db->exec("UPDATE wp_term_taxonomy SET term_id = 2 WHERE term_taxonomy_id = 1");
    $term_graph_source_db->close();

    $term_graph_result = cow_merge_databases($term_graph_base, $term_graph_source, $term_graph_target, $term_graph_metadata, 'feature-explicit-term-graph', 'main');
    assert_same($term_graph_result['status'], 'completed_with_conflicts', 'out-of-band explicit WordPress term import keeps its dependent graph review-held');
    assert_same(
        (int)scalar($term_graph_target, 'SELECT COUNT(*) FROM wp_terms WHERE term_id = 2'),
        0,
        'out-of-band explicit WordPress term ID is not applied automatically'
    );
    assert_same(
        (int)scalar($term_graph_target, 'SELECT COUNT(*) FROM wp_termmeta WHERE term_id = 2'),
        0,
        'termmeta behind a held explicit WordPress term ID is not applied automatically'
    );
    assert_same(
        (int)scalar($term_graph_target, "SELECT term_id FROM wp_termmeta WHERE meta_key = 'base_term_key'"),
        1,
        'updated termmeta behind a held explicit WordPress term ID is not applied automatically'
    );
    assert_same(
        (int)scalar($term_graph_target, 'SELECT COUNT(*) FROM wp_term_taxonomy WHERE term_id = 2'),
        0,
        'term taxonomy behind a held explicit WordPress term ID is not applied automatically'
    );
    assert_same(
        (int)scalar($term_graph_target, 'SELECT term_id FROM wp_term_taxonomy WHERE term_taxonomy_id = 1'),
        1,
        'updated term taxonomy behind a held explicit WordPress term ID is not applied automatically'
    );
    assert_same(
        (int)scalar($term_graph_metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE r.source_branch = 'feature-explicit-term-graph' AND c.table_name = 'wp_terms' AND c.conflict_type = 'row-target-constraint'"),
        1,
        'out-of-band explicit WordPress term records a review conflict'
    );
    assert_same(
        (int)scalar($term_graph_metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE r.source_branch = 'feature-explicit-term-graph' AND c.table_name = 'wp_termmeta' AND c.conflict_type = 'row-target-constraint'"),
        2,
        'termmeta behind a held explicit WordPress term records review conflicts'
    );
    assert_same(
        (int)scalar($term_graph_metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE r.source_branch = 'feature-explicit-term-graph' AND c.table_name = 'wp_term_taxonomy' AND c.conflict_type = 'row-target-constraint'"),
        2,
        'term taxonomy behind a held explicit WordPress term records review conflicts'
    );
    $term_meta_reason = (string)scalar($term_graph_metadata, "SELECT reason FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE r.source_branch = 'feature-explicit-term-graph' AND d.table_name = 'wp_termmeta' AND d.decision = 'target-wins' ORDER BY d.id DESC LIMIT 1");
    assert_true(
        str_contains($term_meta_reason, 'outside the source branch ID band') && str_contains($term_meta_reason, 'wp_terms'),
        'termmeta conflict explains that it is held behind the explicit WordPress term ID'
    );
    $term_taxonomy_reason = (string)scalar($term_graph_metadata, "SELECT reason FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE r.source_branch = 'feature-explicit-term-graph' AND d.table_name = 'wp_term_taxonomy' AND d.decision = 'target-wins' ORDER BY d.id DESC LIMIT 1");
    assert_true(
        str_contains($term_taxonomy_reason, 'outside the source branch ID band') && str_contains($term_taxonomy_reason, 'wp_terms'),
        'term taxonomy conflict explains that it is held behind the explicit WordPress term ID'
    );
} finally {
    remove_tree($tmp);
}

if ($fail) {
    echo "FAILURES: $fail\n";
    exit(1);
}
echo "COW explicit ID focused tests passed ($pass assertions).\n";

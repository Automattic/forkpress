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

function create_id_band_db(string $path): void {
    $db = open_db($path);
    $db->exec('CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY AUTOINCREMENT, post_title TEXT NOT NULL, post_content TEXT NOT NULL, post_status TEXT NOT NULL)');
    $db->exec('CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT UNIQUE, option_value TEXT NOT NULL, autoload TEXT NOT NULL)');
    $db->exec('CREATE TABLE plugin_plain_ipk (id INTEGER PRIMARY KEY, payload TEXT NOT NULL)');
    $db->exec('CREATE TABLE plugin_plain_ipk_implicit (id INTEGER PRIMARY KEY, payload TEXT NOT NULL)');
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status) VALUES (1, 'Base page', 'Base content', 'draft')");
    $db->exec("INSERT INTO wp_options (option_id, option_name, option_value, autoload) VALUES (1, 'base_graph', '{}', 'yes')");
    $db->close();
}

function create_plain_ipk_parent_graph_db(string $path): void {
    $db = open_db($path);
    $db->exec('CREATE TABLE plugin_plain_ipk_parent (id INTEGER PRIMARY KEY, label TEXT NOT NULL)');
    $db->exec('CREATE TABLE plugin_plain_ipk_child (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL REFERENCES plugin_plain_ipk_parent(id), label TEXT NOT NULL)');
    $db->close();
}

define('FORKPRESS_COW_MERGE_TESTS', true);
require_once __DIR__ . '/../../scripts/cow/merge.php';

echo "=== COW ID-band focused tests ===\n";

$tmp = sys_get_temp_dir() . '/forkpress-cow-id-bands-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);

try {
    $base = $tmp . '/base.sqlite';
    $source = $tmp . '/source.sqlite';
    $target = $tmp . '/target.sqlite';
    $metadata = $tmp . '/.forkpress/cow/merge/id-band-metadata.sqlite';

    create_id_band_db($base);
    copy($base, $source);
    copy($base, $target);

    $source_band = cow_merge_allocate_autoincrement_bands($source, $metadata, 'feature-source');
    $target_band = cow_merge_allocate_autoincrement_bands($target, $metadata, 'feature-target');
    assert_same($source_band['allocated'], 2, 'source branch allocates AUTOINCREMENT bands for posts and options');
    assert_same($target_band['allocated'], 2, 'target branch allocates independent AUTOINCREMENT bands for posts and options');
    $source_birth_validation = cow_merge_validate_branch_birth_metadata($source, $metadata, 'feature-source');
    assert_same($source_birth_validation['status'], 'validated', 'branch birth metadata validation accepts complete ID-band and plain-IPK skip metadata');
    assert_same($source_birth_validation['plain_integer_primary_key_tables'], 2, 'branch birth metadata validation counts non-bandable plain INTEGER PRIMARY KEY plugin tables');

    $missing_plain_skip = $tmp . '/missing-plain-skip.sqlite';
    $missing_plain_skip_metadata = $tmp . '/.forkpress/cow/merge/missing-plain-skip-metadata.sqlite';
    copy($base, $missing_plain_skip);
    cow_merge_allocate_autoincrement_bands($missing_plain_skip, $missing_plain_skip_metadata, 'feature-missing-plain-skip');
    $missing_plain_skip_meta = open_db($missing_plain_skip_metadata);
    $missing_plain_skip_meta->exec("DELETE FROM merge_decisions WHERE decision = 'id-band-skipped' AND table_name = 'plugin_plain_ipk_implicit'");
    $missing_plain_skip_meta->close();
    $missing_plain_skip_error = null;
    try {
        cow_merge_validate_branch_birth_metadata($missing_plain_skip, $missing_plain_skip_metadata, 'feature-missing-plain-skip');
    } catch (Throwable $e) {
        $missing_plain_skip_error = $e->getMessage();
    }
    assert_true(
        is_string($missing_plain_skip_error) &&
            str_contains($missing_plain_skip_error, 'plain INTEGER PRIMARY KEY skip decision for plugin_plain_ipk_implicit'),
        'branch birth metadata validation rejects missing plain INTEGER PRIMARY KEY skip audit decisions'
    );

    $source_db = open_db($source);
    $source_db->exec("INSERT INTO wp_posts (post_title, post_content, post_status) VALUES ('Source page', 'Source content', 'publish')");
    $source_post_id = (int)$source_db->lastInsertRowID();
    $source_graph = [
        'branch' => 'source',
        'post_id' => $source_post_id,
    ];
    $source_stmt = $source_db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('source_json_graph', :json, 'yes'), ('source_serialized_graph', :serialized, 'yes')");
    $source_stmt->bindValue(':json', json_encode($source_graph, JSON_UNESCAPED_SLASHES), SQLITE3_TEXT);
    $source_stmt->bindValue(':serialized', serialize($source_graph), SQLITE3_TEXT);
    $source_stmt->execute();
    $source_db->exec("INSERT INTO plugin_plain_ipk (id, payload) VALUES (7, 'source explicit plain integer key')");
    $source_db->exec("INSERT INTO plugin_plain_ipk (id, payload) VALUES (8, 'source non-colliding plain integer key')");
    $source_db->exec("INSERT INTO plugin_plain_ipk_implicit (payload) VALUES ('source implicit plain integer key')");
    $source_implicit_id = (int)$source_db->lastInsertRowID();
    $source_db->close();

    $target_db = open_db($target);
    $target_db->exec("INSERT INTO wp_posts (post_title, post_content, post_status) VALUES ('Target page', 'Target content', 'publish')");
    $target_post_id = (int)$target_db->lastInsertRowID();
    $target_graph = [
        'branch' => 'target',
        'post_id' => $target_post_id,
    ];
    $target_stmt = $target_db->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('target_json_graph', :json, 'yes'), ('target_serialized_graph', :serialized, 'yes')");
    $target_stmt->bindValue(':json', json_encode($target_graph, JSON_UNESCAPED_SLASHES), SQLITE3_TEXT);
    $target_stmt->bindValue(':serialized', serialize($target_graph), SQLITE3_TEXT);
    $target_stmt->execute();
    $target_db->exec("INSERT INTO plugin_plain_ipk (id, payload) VALUES (7, 'target explicit plain integer key')");
    $target_db->exec("INSERT INTO plugin_plain_ipk (id, payload) VALUES (9, 'target non-colliding plain integer key')");
    $target_db->exec("INSERT INTO plugin_plain_ipk_implicit (payload) VALUES ('target implicit plain integer key')");
    $target_implicit_id = (int)$target_db->lastInsertRowID();
    $target_db->close();

    assert_true($source_post_id !== $target_post_id, 'source and target branch inserts receive different post IDs');
    assert_true($source_post_id >= 1000000, 'source post ID lands inside an allocated branch band');
    assert_true($target_post_id >= 2000000, 'target post ID lands inside a later allocated branch band');
    assert_same($source_implicit_id, 1, 'source plain INTEGER PRIMARY KEY implicit insert starts at the unbanded rowid floor');
    assert_same($target_implicit_id, 1, 'target plain INTEGER PRIMARY KEY implicit insert independently reuses the unbanded rowid floor');

    $source_reuse_band = cow_merge_allocate_autoincrement_bands($source, $metadata, 'feature-source');
    assert_same($source_reuse_band['allocated'], 0, 'source branch still inside its reserved band does not allocate fresh AUTOINCREMENT bands');
    assert_same($source_reuse_band['reused'], 2, 'source branch still inside its reserved band reuses existing AUTOINCREMENT bands');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE decision = 'id-band-reused' AND table_name IN ('wp_posts', 'wp_options')"),
        2,
        'reused AUTOINCREMENT bands are auditable'
    );

    $result = cow_merge_databases($base, $source, $target, $metadata, 'feature-source', 'feature-target');
    assert_same($result['status'], 'completed_with_conflicts', 'plain INTEGER PRIMARY KEY collision remains reviewable while banded WordPress rows merge');

    assert_same(
        scalar($target, "SELECT post_title FROM wp_posts WHERE ID = $source_post_id"),
        'Source page',
        'source banded post merges into target without ID rewrite'
    );
    assert_same(
        scalar($target, "SELECT post_title FROM wp_posts WHERE ID = $target_post_id"),
        'Target page',
        'target banded post remains in target'
    );

    $merged_source_json = json_decode((string)scalar($target, "SELECT option_value FROM wp_options WHERE option_name = 'source_json_graph'"), true);
    $merged_target_json = json_decode((string)scalar($target, "SELECT option_value FROM wp_options WHERE option_name = 'target_json_graph'"), true);
    $merged_source_serialized = unserialize((string)scalar($target, "SELECT option_value FROM wp_options WHERE option_name = 'source_serialized_graph'"));
    $merged_target_serialized = unserialize((string)scalar($target, "SELECT option_value FROM wp_options WHERE option_name = 'target_serialized_graph'"));
    assert_same($merged_source_json['post_id'] ?? null, $source_post_id, 'source JSON graph keeps the source branch post ID');
    assert_same($merged_target_json['post_id'] ?? null, $target_post_id, 'target JSON graph keeps the target branch post ID');
    assert_same($merged_source_serialized['post_id'] ?? null, $source_post_id, 'source serialized graph keeps the source branch post ID');
    assert_same($merged_target_serialized['post_id'] ?? null, $target_post_id, 'target serialized graph keeps the target branch post ID');

    assert_same(
        (int)scalar(
            $metadata,
            "SELECT COUNT(DISTINCT r.source_branch)
             FROM merge_decisions d
             JOIN merge_runs r ON r.id = d.run_id
             WHERE d.decision = 'id-band-skipped'
               AND d.table_name = 'plugin_plain_ipk'"
        ),
        2,
        'plain INTEGER PRIMARY KEY plugin tables are explicitly marked non-bandable for each branch'
    );
    assert_same(
        (int)scalar(
            $metadata,
            "SELECT COUNT(DISTINCT r.source_branch)
             FROM merge_decisions d
             JOIN merge_runs r ON r.id = d.run_id
             WHERE d.decision = 'id-band-skipped'
               AND d.table_name = 'plugin_plain_ipk_implicit'"
        ),
        2,
        'plain INTEGER PRIMARY KEY plugin tables with implicit inserts are explicitly marked non-bandable for each branch'
    );
    $plain_ipk_skip_audit = cow_merge_audit_report($metadata, null, 20, [
        'id_band_skips' => '1',
    ]);
    assert_same($plain_ipk_skip_audit['filters']['records'], 'decisions', 'ID-band skip shortcut focuses audit records on decisions');
    assert_same($plain_ipk_skip_audit['filters']['decision'], 'id-band-skipped', 'ID-band skip shortcut filters skipped plain INTEGER PRIMARY KEY decisions');
    $skip_tables = array_values(array_unique(array_map(fn($row) => $row['table_name'] ?? '', $plain_ipk_skip_audit['decisions'])));
    sort($skip_tables);
    assert_same(
        $skip_tables,
        ['plugin_plain_ipk', 'plugin_plain_ipk_implicit'],
        'ID-band skip audit names each plain INTEGER PRIMARY KEY plugin table'
    );
    ob_start();
    cow_merge_print_audit_text($plain_ipk_skip_audit);
    $plain_ipk_skip_text = ob_get_clean();
    assert_true(
        str_contains($plain_ipk_skip_text, 'id-band-skips') &&
            str_contains($plain_ipk_skip_text, 'plugin_plain_ipk') &&
            str_contains($plain_ipk_skip_text, 'plain INTEGER PRIMARY KEY tables do not have a durable sqlite_sequence reservation point'),
        'ID-band skip text explains why plain plugin INTEGER PRIMARY KEY tables are not banded'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_plain_ipk'"),
        1,
        'plain INTEGER PRIMARY KEY plugin collision is recorded as a review conflict'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_plain_ipk_implicit'"),
        1,
        'implicit plain INTEGER PRIMARY KEY plugin collision is recorded as a review conflict'
    );
    assert_same(
        scalar($target, 'SELECT payload FROM plugin_plain_ipk WHERE id = 7'),
        'target explicit plain integer key',
        'plain INTEGER PRIMARY KEY plugin collision keeps the target row before review'
    );
    assert_same(
        scalar($target, 'SELECT payload FROM plugin_plain_ipk_implicit WHERE id = 1'),
        'target implicit plain integer key',
        'implicit plain INTEGER PRIMARY KEY plugin collision keeps the target row before review'
    );
    assert_same(
        scalar($target, 'SELECT payload FROM plugin_plain_ipk WHERE id = 8'),
        'source non-colliding plain integer key',
        'non-colliding plain INTEGER PRIMARY KEY plugin rows still merge'
    );
    assert_same(
        scalar($target, 'SELECT payload FROM plugin_plain_ipk WHERE id = 9'),
        'target non-colliding plain integer key',
        'target non-colliding plain INTEGER PRIMARY KEY plugin rows remain'
    );

    $graph_base = $tmp . '/plain-ipk-parent-graph-base.sqlite';
    $graph_source = $tmp . '/plain-ipk-parent-graph-source.sqlite';
    $graph_target = $tmp . '/plain-ipk-parent-graph-target.sqlite';
    $graph_metadata = $tmp . '/.forkpress/cow/merge/plain-ipk-parent-graph-metadata.sqlite';
    create_plain_ipk_parent_graph_db($graph_base);
    copy($graph_base, $graph_source);
    copy($graph_base, $graph_target);
    cow_merge_allocate_autoincrement_bands($graph_source, $graph_metadata, 'feature-plain-graph-source');
    cow_merge_allocate_autoincrement_bands($graph_target, $graph_metadata, 'feature-plain-graph-target');
    $graph_source_db = open_db($graph_source);
    $graph_source_db->exec("INSERT INTO plugin_plain_ipk_parent (id, label) VALUES (10, 'source parent')");
    $graph_source_db->exec("INSERT INTO plugin_plain_ipk_child (id, parent_id, label) VALUES (20, 10, 'source child')");
    $graph_source_db->close();
    $graph_target_db = open_db($graph_target);
    $graph_target_db->exec("INSERT INTO plugin_plain_ipk_parent (id, label) VALUES (10, 'target parent')");
    $graph_target_db->close();

    $graph_result = cow_merge_databases(
        $graph_base,
        $graph_source,
        $graph_target,
        $graph_metadata,
        'feature-plain-graph-source',
        'feature-plain-graph-target'
    );
    assert_same($graph_result['status'], 'completed_with_conflicts', 'plain INTEGER PRIMARY KEY parent collision keeps dependent source rows reviewable');
    assert_same(
        scalar($graph_target, 'SELECT label FROM plugin_plain_ipk_parent WHERE id = 10'),
        'target parent',
        'plain INTEGER PRIMARY KEY parent collision keeps the target parent before review'
    );
    assert_same(
        scalar($graph_target, 'SELECT label FROM plugin_plain_ipk_child WHERE id = 20'),
        null,
        'source child row is not applied to a different target parent with the same plain INTEGER PRIMARY KEY'
    );
    assert_same(
        (int)scalar($graph_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_plain_ipk_parent' AND conflict_type = 'row-insert-collision'"),
        1,
        'plain INTEGER PRIMARY KEY parent collision is recorded as a review conflict'
    );
    assert_same(
        (int)scalar($graph_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_plain_ipk_child' AND conflict_type = 'row-target-constraint'"),
        1,
        'dependent source child row is held behind the parent collision'
    );
    assert_true(
        str_contains(
            (string)scalar($graph_metadata, "SELECT reason FROM merge_decisions WHERE table_name = 'plugin_plain_ipk_child' AND decision = 'target-wins' ORDER BY id DESC LIMIT 1"),
            'collides with a different target parent row'
        ),
        'dependent source child row explains the parent collision guard'
    );

    $reset = $tmp . '/reset.sqlite';
    copy($base, $reset);
    $reset_band = cow_merge_allocate_autoincrement_bands($reset, $metadata, 'feature-source');
    assert_same($reset_band['allocated'], 2, 'reset branch DB below its old band gets fresh AUTOINCREMENT bands');
    assert_same($reset_band['reused'], 0, 'reset branch DB below its old band does not reuse possibly published bands');
    assert_true(
        (int)scalar($reset, "SELECT seq FROM sqlite_sequence WHERE name = 'wp_posts'") > $target_post_id,
        'fresh reset post band is above previously allocated branch post IDs'
    );
    assert_true(
        (int)scalar($reset, "SELECT seq FROM sqlite_sequence WHERE name = 'wp_options'") >= (int)scalar($metadata, "SELECT band_start - 1 FROM merge_autoincrement_bands WHERE branch_name = 'feature-source' AND table_name = 'wp_options'"),
        'fresh reset option band is recorded in the app DB sequence'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_runs WHERE source_branch = 'feature-source' AND policy = 'autoincrement-id-band-allocation' AND status = 'id_bands_allocated'"),
        3,
        'initial, reused, and reset-safe AUTOINCREMENT allocation runs are auditable'
    );

    $reset_target = $tmp . '/reset-target.sqlite';
    copy($base, $reset_target);
    $reset_db = open_db($reset);
    $reset_db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status) VALUES ($source_post_id, 'Retired band import', 'old band explicit import', 'publish')");
    $reset_db->close();

    $reset_import_result = cow_merge_databases($base, $reset, $reset_target, $metadata, 'feature-source', 'main-reset-target');
    assert_same(
        $reset_import_result['status'],
        'completed_with_conflicts',
        'explicit imports into a retired pre-reset AUTOINCREMENT band stay review-held'
    );
    assert_same(
        (int)scalar($reset_target, "SELECT COUNT(*) FROM wp_posts WHERE ID = $source_post_id"),
        0,
        'retired pre-reset AUTOINCREMENT band IDs are not applied automatically'
    );
    assert_true(
        str_contains(
            (string)scalar($metadata, "SELECT reason FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE r.source_branch = 'feature-source' AND d.table_name = 'wp_posts' AND d.decision = 'target-wins' ORDER BY d.id DESC LIMIT 1"),
            'outside reserved branch band'
        ),
        'retired pre-reset AUTOINCREMENT band conflict explains the active branch-band violation'
    );
} finally {
    remove_tree($tmp);
}

if ($fail) {
    echo "FAILURES: $fail\n";
    exit(1);
}
echo "COW ID-band focused tests passed ($pass assertions).\n";

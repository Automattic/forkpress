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

function assert_throws(callable $fn, string $contains, string $msg): void {
    try {
        $fn();
        assert_true(false, $msg . ' (no exception thrown)');
    } catch (Throwable $e) {
        assert_true(str_contains($e->getMessage(), $contains), $msg . ' (' . $e->getMessage() . ')');
    }
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

function copy_tree_for_test(string $source, string $dest): void {
    mkdir($dest, 0777, true);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $entry) {
        $target = $dest . '/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($source) + 1));
        if ($entry->isLink()) {
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }
            $link_target = readlink($entry->getPathname());
            if (!is_string($link_target) || !symlink($link_target, $target)) {
                throw new RuntimeException('failed to copy test symlink: ' . $entry->getPathname());
            }
            continue;
        }
        if ($entry->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0777, true);
            }
            continue;
        }
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        copy($entry->getPathname(), $target);
    }
}

function write_test_file(string $path, string $contents): void {
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);
}

function create_test_symlink(string $target, string $link): void {
    if (!is_dir(dirname($link))) {
        mkdir(dirname($link), 0777, true);
    }
    if ((file_exists($link) || is_link($link)) && !unlink($link)) {
        throw new RuntimeException("failed to replace test symlink: $link");
    }
    if (!symlink($target, $link)) {
        throw new RuntimeException("failed to create test symlink: $link");
    }
}

function open_db(string $path): SQLite3 {
    $db = new SQLite3($path);
    $db->busyTimeout(5000);
    return $db;
}

function create_base_db(string $path): void {
    $db = open_db($path);
    $db->exec('CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY AUTOINCREMENT, post_title TEXT, post_content TEXT, post_status TEXT)');
    $db->exec('CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT)');
    $db->exec('CREATE TABLE plugin_items (item_id TEXT PRIMARY KEY, label TEXT, value TEXT)');
    $db->exec('CREATE TABLE plugin_keyless (label TEXT, value TEXT)');
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status) VALUES (1, 'Base title', 'Base content', 'draft')");
    $db->exec("INSERT INTO wp_options (option_id, option_name, option_value, autoload) VALUES (1, 'theme_mods_test', 'a:1:{s:5:\"color\";s:4:\"blue\";}', 'yes')");
    $db->exec("INSERT INTO plugin_items (item_id, label, value) VALUES ('alpha', 'Alpha', 'base')");
    $db->exec("INSERT INTO plugin_keyless (label, value) VALUES ('Base keyless', 'base')");
    $db->close();
}

function scalar(string $db_path, string $sql): mixed {
    $db = open_db($db_path);
    $value = $db->querySingle($sql);
    $db->close();
    return $value;
}

function column_type(string $db_path, string $table, string $column): ?string {
    $db = open_db($db_path);
    $res = $db->query('PRAGMA table_info("' . str_replace('"', '""', $table) . '")');
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        if ((string)$row['name'] === $column) {
            $type = (string)$row['type'];
            $db->close();
            return $type;
        }
    }
    $db->close();
    return null;
}

require_once __DIR__ . '/../../scripts/cow/merge.php';

echo "=== COW generic SQLite merge ===\n";

$tmp = sys_get_temp_dir() . '/forkpress-cow-merge-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);
$metadata = $tmp . '/.forkpress/cow/merge/metadata.sqlite';

try {
    $base = $tmp . '/base.sqlite';
    $source = $tmp . '/source.sqlite';
    $target = $tmp . '/target.sqlite';
    create_base_db($base);
    copy($base, $source);
    copy($base, $target);

    $db = open_db($source);
    $db->exec("UPDATE wp_posts SET post_content = 'Source content' WHERE ID = 1");
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status) VALUES (2, 'Source-only post', 'Created on source', 'publish')");
    $db->exec("UPDATE plugin_items SET value = 'source plugin value' WHERE item_id = 'alpha'");
    $db->close();

    $db = open_db($target);
    $db->exec("UPDATE wp_posts SET post_status = 'publish' WHERE ID = 1");
    $db->close();

    $result = cow_merge_databases($base, $source, $target, $metadata, 'feature', 'main');
    assert_same($result['status'], 'completed', 'independent row and cell merge completes cleanly');
    assert_same(scalar($target, "SELECT post_content FROM wp_posts WHERE ID = 1"), 'Source content', 'source cell change is applied');
    assert_same(scalar($target, "SELECT post_status FROM wp_posts WHERE ID = 1"), 'publish', 'target cell change is preserved');
    assert_same(scalar($target, "SELECT post_title FROM wp_posts WHERE ID = 2"), 'Source-only post', 'source-only row is inserted');
    assert_same(scalar($target, "SELECT value FROM plugin_items WHERE item_id = 'alpha'"), 'source plugin value', 'plugin table with explicit PK merges generically');
    assert_true(file_exists($metadata), 'merge metadata database is created outside the WordPress DB');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND column_name = 'post_status' AND decision = 'target-kept'"),
        1,
        'independent target cell preservation is auditable'
    );

    $target_only_base = $tmp . '/target-only-base.sqlite';
    $target_only_source = $tmp . '/target-only-source.sqlite';
    $target_only_target = $tmp . '/target-only-target.sqlite';
    create_base_db($target_only_base);
    copy($target_only_base, $target_only_source);
    copy($target_only_base, $target_only_target);
    $db = open_db($target_only_target);
    $db->exec("UPDATE plugin_items SET value = 'target-only plugin value' WHERE item_id = 'alpha'");
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status) VALUES (9, 'Target-only post', 'Created on target', 'publish')");
    $db->exec("DELETE FROM wp_options WHERE option_name = 'theme_mods_test'");
    $db->close();

    $target_only_result = cow_merge_databases($target_only_base, $target_only_source, $target_only_target, $metadata, 'feature-target-only-data', 'main');
    assert_same($target_only_result['status'], 'completed', 'target-only data changes merge cleanly');
    assert_same(scalar($target_only_target, "SELECT value FROM plugin_items WHERE item_id = 'alpha'"), 'target-only plugin value', 'target-only cell change is preserved');
    assert_same(scalar($target_only_target, "SELECT post_title FROM wp_posts WHERE ID = 9"), 'Target-only post', 'target-only row insert is preserved');
    assert_same((int)scalar($target_only_target, "SELECT COUNT(*) FROM wp_options WHERE option_name = 'theme_mods_test'"), 0, 'target-only row delete is preserved');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_items' AND column_name = 'value' AND decision = 'target-kept'"),
        1,
        'target-only cell change is auditable'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_posts' AND column_name IS NULL AND decision = 'target-kept' AND reason = 'target inserted row and source did not have it'"),
        1,
        'target-only row insert is auditable'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'wp_options' AND column_name IS NULL AND decision = 'target-kept' AND target_payload IS NULL"),
        1,
        'target-only row delete is auditable'
    );

    $empty_table_base = $tmp . '/empty-table-base.sqlite';
    $empty_table_source = $tmp . '/empty-table-source.sqlite';
    $empty_table_target = $tmp . '/empty-table-target.sqlite';
    create_base_db($empty_table_base);
    copy($empty_table_base, $empty_table_source);
    copy($empty_table_base, $empty_table_target);
    $db = open_db($empty_table_source);
    $db->exec('CREATE TABLE plugin_empty_source_table (item_id TEXT PRIMARY KEY, label TEXT)');
    $db->close();
    $db = open_db($empty_table_target);
    $db->exec('CREATE TABLE plugin_target_only_table (item_id TEXT PRIMARY KEY, label TEXT)');
    $db->close();
    $empty_table_result = cow_merge_databases($empty_table_base, $empty_table_source, $empty_table_target, $metadata, 'feature-empty-table', 'main');
    assert_same($empty_table_result['status'], 'completed', 'source-added empty table merges cleanly');
    assert_same((int)scalar($empty_table_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_empty_source_table'"), 1, 'source-added empty table is created on target');
    assert_same((int)scalar($empty_table_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_target_only_table'"), 1, 'target-added table is preserved');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_empty_source_table' AND column_name IS NULL AND row_identity IS NULL AND decision = 'source-applied'"),
        1,
        'source-added empty table creation is auditable even without row decisions'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_target_only_table' AND column_name IS NULL AND row_identity IS NULL AND decision = 'target-kept'"),
        1,
        'target-added table preservation is auditable'
    );

    $conflict_base = $tmp . '/conflict-base.sqlite';
    $conflict_source = $tmp . '/conflict-source.sqlite';
    $conflict_target = $tmp . '/conflict-target.sqlite';
    create_base_db($conflict_base);
    copy($conflict_base, $conflict_source);
    copy($conflict_base, $conflict_target);

    $db = open_db($conflict_source);
    $db->exec("UPDATE wp_posts SET post_title = 'Source title' WHERE ID = 1");
    $db->exec("UPDATE wp_options SET option_value = 'a:1:{s:5:\"color\";s:5:\"green\";}' WHERE option_name = 'theme_mods_test'");
    $db->close();

    $db = open_db($conflict_target);
    $db->exec("UPDATE wp_posts SET post_title = 'Target title' WHERE ID = 1");
    $db->exec("UPDATE wp_options SET option_value = 'a:1:{s:5:\"color\";s:3:\"red\";}' WHERE option_name = 'theme_mods_test'");
    $db->close();

    $result = cow_merge_databases($conflict_base, $conflict_source, $conflict_target, $metadata, 'feature-conflict', 'main');
    $conflict_run_id = (int)$result['run_id'];
    assert_same($result['status'], 'completed_with_conflicts', 'same-cell conflicts complete with target-wins policy');
    assert_same(scalar($conflict_target, "SELECT post_title FROM wp_posts WHERE ID = 1"), 'Target title', 'target title wins conflicting post title');
    assert_same(scalar($conflict_target, "SELECT option_value FROM wp_options WHERE option_name = 'theme_mods_test'"), 'a:1:{s:5:"color";s:3:"red";}', 'serialized option is treated as one conflicting cell');

    cow_merge_databases($conflict_base, $conflict_source, $conflict_target, $metadata, 'feature-conflict', 'main');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_posts' AND column_name = 'post_title'"), 1, 'rerunning the same conflict does not duplicate conflict records');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_options' AND column_name = 'option_value'"), 1, 'serialized-cell conflict is auditable without repeated noise');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE decision = 'target-wins'"), 4, 'target-wins decisions are recorded for each conflicting run');
    $audit = cow_merge_audit_report($metadata, $conflict_run_id, 10);
    assert_same($audit['metadata_exists'], true, 'merge audit report reads existing metadata');
    assert_same(count($audit['runs']), 1, 'merge audit report can focus on one run');
    assert_same((int)$audit['runs'][0]['conflict_count'], 2, 'merge audit run summary includes conflict count');
    assert_same(count($audit['conflicts']), 2, 'merge audit report exports conflict records for a run');
    $title_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'wp_posts' AND column_name = 'post_title'");
    $dry_resolution = cow_merge_resolve_conflict(
        $metadata,
        $title_conflict_id,
        'source',
        false,
        'Preview source title resolution.',
        'cow-test'
    );
    assert_same($dry_resolution['status'], 'validated', 'dry-run source conflict resolution validates target preconditions');
    assert_same(scalar($conflict_target, "SELECT post_title FROM wp_posts WHERE ID = 1"), 'Target title', 'dry-run source resolution does not mutate target DB');
    assert_same((int)scalar($metadata, 'SELECT COUNT(*) FROM merge_resolutions'), 0, 'dry-run conflict resolution does not write resolution audit records');
    $source_resolution = cow_merge_resolve_conflict(
        $metadata,
        $title_conflict_id,
        'source',
        true,
        'Apply reviewed source title.',
        'cow-test'
    );
    assert_same($source_resolution['status'], 'applied', 'source conflict resolution records applied status');
    assert_same(scalar($conflict_target, "SELECT post_title FROM wp_posts WHERE ID = 1"), 'Source title', 'source conflict resolution applies audited source cell value');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $title_conflict_id AND choice = 'source' AND applied = 1"), 1, 'source conflict resolution is auditable');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_review_notes WHERE record_type = 'conflict' AND record_id = $title_conflict_id AND status = 'reviewed' AND note LIKE 'Resolved with source choice:%'"), 1, 'applied source conflict resolution appends a reviewed note');
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $title_conflict_id, 'source', true, 'Try stale source apply.', 'cow-test'),
        'target cell no longer matches',
        'stale conflict resolution is blocked when target has changed since audit'
    );
    $option_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'wp_options' AND column_name = 'option_value'");
    $target_resolution = cow_merge_resolve_conflict(
        $metadata,
        $option_conflict_id,
        'target',
        true,
        'Reviewed and kept target serialized option.',
        'cow-test'
    );
    assert_same($target_resolution['status'], 'validated', 'target conflict resolution records validated status');
    assert_same(scalar($conflict_target, "SELECT option_value FROM wp_options WHERE option_name = 'theme_mods_test'"), 'a:1:{s:5:"color";s:3:"red";}', 'target conflict resolution leaves target DB unchanged');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $option_conflict_id AND choice = 'target' AND applied = 1"), 1, 'target conflict resolution is auditable');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_review_notes WHERE record_type = 'conflict' AND record_id = $option_conflict_id AND status = 'reviewed' AND note LIKE 'Resolved with target choice:%'"), 1, 'applied target conflict resolution appends a reviewed note');
    $resolution_audit = cow_merge_audit_report($metadata, $conflict_run_id, 10);
    assert_same(count($resolution_audit['resolutions']), 2, 'merge audit report exports deterministic resolution records');
    $applied_resolution_audit = cow_merge_audit_report($metadata, $conflict_run_id, 10, ['resolution_status' => 'applied']);
    assert_same($applied_resolution_audit['filters']['records'], 'resolutions', 'resolution status filter defaults audit records to resolutions');
    assert_same($applied_resolution_audit['filters']['resolution_status'], 'applied', 'merge audit JSON report includes resolution status filter');
    assert_same(count($applied_resolution_audit['conflicts']), 0, 'resolution status filter omits conflict records');
    assert_same(count($applied_resolution_audit['decisions']), 0, 'resolution status filter omits decision records');
    assert_same(count($applied_resolution_audit['resolutions']), 1, 'resolution status filter returns applied resolution records');
    assert_same($applied_resolution_audit['resolutions'][0]['status'], 'applied', 'applied resolution status filter matches resolution rows');
    $validated_resolution_audit = cow_merge_audit_report($metadata, $conflict_run_id, 10, ['records' => 'resolutions', 'resolution_status' => 'validated']);
    assert_same(count($validated_resolution_audit['resolutions']), 1, 'validated resolution status filter returns validated resolution records');
    assert_same($validated_resolution_audit['resolutions'][0]['status'], 'validated', 'validated resolution status filter matches resolution rows');
    ob_start();
    cow_merge_print_audit_text($applied_resolution_audit);
    $resolution_status_text = ob_get_clean();
    assert_true(str_contains($resolution_status_text, 'records=resolutions resolution-status=applied'), 'resolution status filter is visible in text filters');
    $grouped_resolution_audit = cow_merge_audit_report($metadata, $conflict_run_id, 10, ['records' => 'resolutions', 'group_by' => 'status']);
    assert_same($grouped_resolution_audit['filters']['group_by'], 'status', 'merge audit JSON report includes resolution grouping filter');
    assert_same(count($grouped_resolution_audit['resolution_groups']), 2, 'resolution audit groups records by status');
    $group_counts = [];
    foreach ($grouped_resolution_audit['resolution_groups'] as $group) {
        $group_counts[$group['group_key']] = (int)$group['resolution_count'];
    }
    assert_same($group_counts['applied'] ?? 0, 1, 'resolution grouping counts applied records');
    assert_same($group_counts['validated'] ?? 0, 1, 'resolution grouping counts validated records');
    ob_start();
    cow_merge_print_audit_text($grouped_resolution_audit);
    $resolution_group_text = ob_get_clean();
    assert_true(str_contains($resolution_group_text, 'group-by=status') && str_contains($resolution_group_text, 'resolution-groups:'), 'resolution grouping is visible in text audit output');
    $grouped_conflict_audit = cow_merge_audit_report($metadata, $conflict_run_id, 10, ['records' => 'conflicts', 'group_by' => 'severity']);
    assert_same($grouped_conflict_audit['filters']['group_by'], 'severity', 'merge audit JSON report includes conflict grouping filter');
    assert_same(count($grouped_conflict_audit['resolution_groups']), 0, 'conflict grouping does not populate resolution groups');
    $conflict_group_counts = [];
    foreach ($grouped_conflict_audit['conflict_groups'] as $group) {
        $conflict_group_counts[$group['group_key']] = (int)$group['conflict_count'];
    }
    assert_same($conflict_group_counts['cell'] ?? 0, 2, 'conflict grouping counts cell conflicts by severity');
    ob_start();
    cow_merge_print_audit_text($grouped_conflict_audit);
    $conflict_group_text = ob_get_clean();
    assert_true(str_contains($conflict_group_text, 'group-by=severity') && str_contains($conflict_group_text, 'conflict-groups:'), 'conflict grouping is visible in text audit output');
    $grouped_decision_audit = cow_merge_audit_report($metadata, $conflict_run_id, 10, ['records' => 'decisions', 'group_by' => 'type']);
    assert_same($grouped_decision_audit['filters']['group_by'], 'type', 'merge audit JSON report includes decision grouping filter');
    assert_same(count($grouped_decision_audit['conflict_groups']), 0, 'decision grouping does not populate conflict groups');
    assert_same(count($grouped_decision_audit['resolution_groups']), 0, 'decision grouping does not populate resolution groups');
    $decision_group_counts = [];
    foreach ($grouped_decision_audit['decision_groups'] as $group) {
        $decision_group_counts[$group['group_key']] = (int)$group['decision_count'];
    }
    assert_same($decision_group_counts['target-wins'] ?? 0, 2, 'decision grouping counts target-wins decisions by type');
    ob_start();
    cow_merge_print_audit_text($grouped_decision_audit);
    $decision_group_text = ob_get_clean();
    assert_true(str_contains($decision_group_text, 'group-by=type') && str_contains($decision_group_text, 'decision-groups:'), 'decision grouping is visible in text audit output');

    $row_conflict_base = $tmp . '/row-conflict-base.sqlite';
    $row_conflict_source = $tmp . '/row-conflict-source.sqlite';
    $row_conflict_target = $tmp . '/row-conflict-target.sqlite';
    create_base_db($row_conflict_base);
    copy($row_conflict_base, $row_conflict_source);
    copy($row_conflict_base, $row_conflict_target);
    $db = open_db($row_conflict_source);
    $db->exec("INSERT INTO plugin_items (item_id, label, value) VALUES ('collision', 'Source label', 'source row')");
    $db->close();
    $db = open_db($row_conflict_target);
    $db->exec("INSERT INTO plugin_items (item_id, label, value) VALUES ('collision', 'Target label', 'target row')");
    $db->close();
    $row_conflict_result = cow_merge_databases($row_conflict_base, $row_conflict_source, $row_conflict_target, $metadata, 'feature-row-conflict', 'main');
    assert_same($row_conflict_result['status'], 'completed_with_conflicts', 'same-PK row insert collision is recorded as a conflict');
    assert_same(scalar($row_conflict_target, "SELECT value FROM plugin_items WHERE item_id = 'collision'"), 'target row', 'target row wins before explicit row conflict resolution');
    $row_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_items' AND conflict_type = 'row-insert-collision'");
    $row_dry_resolution = cow_merge_resolve_conflict(
        $metadata,
        $row_conflict_id,
        'source',
        false,
        'Preview source row resolution.',
        'cow-test'
    );
    assert_same($row_dry_resolution['status'], 'validated', 'dry-run row conflict resolution validates target row preconditions');
    assert_same(scalar($row_conflict_target, "SELECT label FROM plugin_items WHERE item_id = 'collision'"), 'Target label', 'dry-run row resolution does not mutate target row');
    $row_source_resolution = cow_merge_resolve_conflict(
        $metadata,
        $row_conflict_id,
        'source',
        true,
        'Apply audited source row.',
        'cow-test'
    );
    assert_same($row_source_resolution['status'], 'applied', 'source row conflict resolution records applied status');
    assert_same(scalar($row_conflict_target, "SELECT label FROM plugin_items WHERE item_id = 'collision'"), 'Source label', 'source row conflict resolution applies audited source row values');
    assert_same(scalar($row_conflict_target, "SELECT value FROM plugin_items WHERE item_id = 'collision'"), 'source row', 'source row conflict resolution updates the full audited source row');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $row_conflict_id AND choice = 'source' AND applied = 1 AND column_name = ''"), 1, 'row conflict resolution is auditable without a column name');
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $row_conflict_id, 'target', true, 'Try stale target keep.', 'cow-test'),
        'target row no longer matches',
        'stale row conflict resolution is blocked when target row has changed since audit'
    );

    $row_target_deleted_base = $tmp . '/row-target-deleted-base.sqlite';
    $row_target_deleted_source = $tmp . '/row-target-deleted-source.sqlite';
    $row_target_deleted_target = $tmp . '/row-target-deleted-target.sqlite';
    create_base_db($row_target_deleted_base);
    copy($row_target_deleted_base, $row_target_deleted_source);
    copy($row_target_deleted_base, $row_target_deleted_target);
    $db = open_db($row_target_deleted_source);
    $db->exec("UPDATE plugin_items SET label = 'Source kept row', value = 'source changed row' WHERE item_id = 'alpha'");
    $db->close();
    $db = open_db($row_target_deleted_target);
    $db->exec("DELETE FROM plugin_items WHERE item_id = 'alpha'");
    $db->close();
    cow_merge_databases($row_target_deleted_base, $row_target_deleted_source, $row_target_deleted_target, $metadata, 'feature-row-target-deleted', 'main');
    $row_target_deleted_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_items' AND conflict_type = 'row-target-deleted' ORDER BY id DESC LIMIT 1");
    assert_same((int)scalar($row_target_deleted_target, "SELECT COUNT(*) FROM plugin_items WHERE item_id = 'alpha'"), 0, 'target deletion wins before explicit source row restore');
    $row_restore_dry = cow_merge_resolve_conflict(
        $metadata,
        $row_target_deleted_id,
        'source',
        false,
        'Preview audited source row restore.',
        'cow-test'
    );
    assert_same($row_restore_dry['status'], 'validated', 'dry-run target-deleted row resolution validates missing target precondition');
    assert_same((int)scalar($row_target_deleted_target, "SELECT COUNT(*) FROM plugin_items WHERE item_id = 'alpha'"), 0, 'dry-run row restore does not mutate target DB');
    $row_restore_resolution = cow_merge_resolve_conflict(
        $metadata,
        $row_target_deleted_id,
        'source',
        true,
        'Restore audited source row.',
        'cow-test'
    );
    assert_same($row_restore_resolution['status'], 'applied', 'source row restore records applied status');
    assert_same(scalar($row_target_deleted_target, "SELECT value FROM plugin_items WHERE item_id = 'alpha'"), 'source changed row', 'source row restore inserts the audited source row');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $row_target_deleted_id AND choice = 'source' AND applied = 1 AND column_name = ''"), 1, 'row restore resolution is auditable');

    $row_source_deleted_base = $tmp . '/row-source-deleted-base.sqlite';
    $row_source_deleted_source = $tmp . '/row-source-deleted-source.sqlite';
    $row_source_deleted_target = $tmp . '/row-source-deleted-target.sqlite';
    create_base_db($row_source_deleted_base);
    copy($row_source_deleted_base, $row_source_deleted_source);
    copy($row_source_deleted_base, $row_source_deleted_target);
    $db = open_db($row_source_deleted_source);
    $db->exec("DELETE FROM plugin_items WHERE item_id = 'alpha'");
    $db->close();
    $db = open_db($row_source_deleted_target);
    $db->exec("UPDATE plugin_items SET value = 'target changed row' WHERE item_id = 'alpha'");
    $db->close();
    cow_merge_databases($row_source_deleted_base, $row_source_deleted_source, $row_source_deleted_target, $metadata, 'feature-row-source-deleted', 'main');
    $row_source_deleted_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_items' AND conflict_type = 'row-source-deleted' ORDER BY id DESC LIMIT 1");
    assert_same(scalar($row_source_deleted_target, "SELECT value FROM plugin_items WHERE item_id = 'alpha'"), 'target changed row', 'target row wins before explicit source deletion');
    $row_delete_resolution = cow_merge_resolve_conflict(
        $metadata,
        $row_source_deleted_id,
        'source',
        true,
        'Apply audited source deletion.',
        'cow-test'
    );
    assert_same($row_delete_resolution['status'], 'applied', 'source row deletion records applied status');
    assert_same((int)scalar($row_source_deleted_target, "SELECT COUNT(*) FROM plugin_items WHERE item_id = 'alpha'"), 0, 'source row deletion removes the current target row after validation');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $row_source_deleted_id AND choice = 'source' AND applied = 1 AND column_name = ''"), 1, 'row deletion resolution is auditable');
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $row_source_deleted_id, 'target', true, 'Try stale keep after deletion.', 'cow-test'),
        'target row no longer exists',
        'stale source-deleted row resolution is blocked after the target row was removed'
    );

    $reviewed_conflict_id = (int)$audit['conflicts'][0]['id'];
    $review_result = cow_merge_review_record(
        $metadata,
        'conflict',
        $reviewed_conflict_id,
        'reviewed',
        'Target value is intentional after manual review.',
        'cow-test'
    );
    assert_same($review_result['record_id'], $reviewed_conflict_id, 'review note records the selected conflict id');
    $reviewed_audit = cow_merge_audit_report($metadata, $conflict_run_id, 10);
    $reviewed_rows = array_values(array_filter(
        $reviewed_audit['conflicts'],
        fn($row) => (int)$row['id'] === $reviewed_conflict_id
    ));
    assert_same($reviewed_rows[0]['review_status'], 'reviewed', 'merge audit JSON exposes latest conflict review status');
    assert_same($reviewed_rows[0]['review_note'], 'Target value is intentional after manual review.', 'merge audit JSON exposes latest conflict review note');
    ob_start();
    cow_merge_print_audit_text($reviewed_audit);
    $audit_text = ob_get_clean();
    assert_true(str_contains($audit_text, 'target-wins'), 'merge audit text includes automatic target-wins decisions');
    assert_true(str_contains($audit_text, 'wp_posts'), 'merge audit text identifies affected tables');
    assert_true(str_contains($audit_text, 'review=reviewed') && str_contains($audit_text, 'Target value is intentional'), 'merge audit text includes review annotations');
    $review_audit = cow_merge_audit_report($metadata, null, 10, ['review' => '1']);
    assert_same($review_audit['filters']['review'], true, 'merge audit JSON report includes the review shortcut filter');
    assert_true(count($review_audit['conflicts']) >= 2, 'review audit includes revisitable merge conflicts');
    $reviewed_status_audit = cow_merge_audit_report($metadata, null, 10, ['review_status' => 'reviewed']);
    assert_same($reviewed_status_audit['filters']['review_status'], 'reviewed', 'merge audit JSON report includes review status filter');
    assert_true(count($reviewed_status_audit['conflicts']) >= 1, 'review status filter returns reviewed conflicts');
    $reviewed_status_ids = array_map(fn($row) => (int)$row['id'], $reviewed_status_audit['conflicts']);
    assert_true(in_array($reviewed_conflict_id, $reviewed_status_ids, true), 'review status filter returns the annotated conflict');
    assert_same(count($reviewed_status_audit['decisions']), 0, 'review status filter omits unannotated decisions');
    $needs_action_status_audit = cow_merge_audit_report($metadata, null, 10, ['review_status' => 'needs-action']);
    assert_same(count($needs_action_status_audit['conflicts']), 0, 'review status filter excludes other latest statuses');
    $missing_review_status_audit = cow_merge_audit_report($tmp . '/missing-review-status.sqlite', null, 5, ['review_status' => 'reviewed']);
    assert_same($missing_review_status_audit['metadata_exists'], false, 'review status filter handles missing metadata');
    ob_start();
    cow_merge_print_audit_text($review_audit);
    $review_text = ob_get_clean();
    assert_true(str_contains($review_text, 'review'), 'review audit shortcut is visible in text filters');
    ob_start();
    cow_merge_print_audit_text($reviewed_status_audit);
    $reviewed_status_text = ob_get_clean();
    assert_true(str_contains($reviewed_status_text, 'review-status=reviewed'), 'review status filter is visible in text filters');
    $resolution_review_id = (int)$applied_resolution_audit['resolutions'][0]['id'];
    $resolution_review = cow_merge_review_record(
        $metadata,
        'resolution',
        $resolution_review_id,
        'needs-action',
        'Follow up with content owner after source resolution.',
        'cow-test'
    );
    assert_same($resolution_review['record_type'], 'resolution', 'review note can target a deterministic resolution record');
    $reviewed_resolution_audit = cow_merge_audit_report($metadata, null, 10, ['records' => 'resolutions', 'review_status' => 'needs-action']);
    assert_same(count($reviewed_resolution_audit['conflicts']), 0, 'resolution review status filter omits conflicts when records=resolutions');
    assert_same(count($reviewed_resolution_audit['decisions']), 0, 'resolution review status filter omits decisions when records=resolutions');
    assert_same(count($reviewed_resolution_audit['resolutions']), 1, 'review status filter returns annotated resolution records');
    assert_same($reviewed_resolution_audit['resolutions'][0]['review_status'], 'needs-action', 'merge audit JSON exposes latest resolution review status');
    assert_same($reviewed_resolution_audit['resolutions'][0]['review_note'], 'Follow up with content owner after source resolution.', 'merge audit JSON exposes latest resolution review note');
    ob_start();
    cow_merge_print_audit_text($reviewed_resolution_audit);
    $reviewed_resolution_text = ob_get_clean();
    assert_true(str_contains($reviewed_resolution_text, 'review=needs-action') && str_contains($reviewed_resolution_text, 'Follow up with content owner'), 'merge audit text includes resolution review annotations');
    $missing_audit = cow_merge_audit_report($tmp . '/missing-metadata.sqlite', null, 5);
    assert_same($missing_audit['metadata_exists'], false, 'merge audit report handles missing metadata');
    $legacy_metadata = $tmp . '/legacy-metadata.sqlite';
    $legacy_db = open_db($legacy_metadata);
    $legacy_db->exec(<<<'SQL'
CREATE TABLE merge_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_branch TEXT NOT NULL,
    target_branch TEXT NOT NULL,
    base_ref TEXT,
    started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TEXT,
    status TEXT NOT NULL,
    policy TEXT NOT NULL,
    source_db TEXT NOT NULL,
    target_db TEXT NOT NULL,
    base_db TEXT NOT NULL
)
SQL);
    cow_merge_ensure_metadata($legacy_db);
    $legacy_db->close();
    assert_same(column_type($legacy_metadata, 'merge_runs', 'failure_reason'), 'TEXT', 'metadata migration adds failed-run reason storage to legacy merge databases');
    $legacy_review_metadata = $tmp . '/legacy-review-metadata.sqlite';
    $legacy_review_db = open_db($legacy_review_metadata);
    $legacy_review_db->exec(<<<'SQL'
CREATE TABLE merge_review_notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    record_type TEXT NOT NULL CHECK(record_type IN ('conflict', 'decision')),
    record_id INTEGER NOT NULL,
    status TEXT NOT NULL CHECK(status IN ('pending', 'needs-action', 'reviewed')),
    note TEXT NOT NULL,
    reviewer TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);
    $legacy_review_db->exec("INSERT INTO merge_review_notes (record_type, record_id, status, note, reviewer) VALUES ('conflict', 1, 'reviewed', 'legacy note', 'cow-test')");
    cow_merge_ensure_metadata($legacy_review_db);
    assert_true((bool)$legacy_review_db->exec("INSERT INTO merge_review_notes (record_type, record_id, status, note, reviewer) VALUES ('resolution', 1, 'reviewed', 'resolution note', 'cow-test')"), 'metadata migration allows resolution review notes in legacy merge databases');
    $legacy_review_db->close();

    $file_base_db = $tmp . '/file-base.sqlite';
    $file_source_db = $tmp . '/file-source.sqlite';
    $file_target_db = $tmp . '/file-target.sqlite';
    create_base_db($file_base_db);
    copy($file_base_db, $file_source_db);
    copy($file_base_db, $file_target_db);
    $file_base_root = $tmp . '/files-base';
    $file_source_root = $tmp . '/files-source';
    $file_target_root = $tmp . '/files-target';
    mkdir($file_base_root . '/wp-content/uploads', 0777, true);
    write_test_file($file_base_root . '/wp-content/uploads/shared.txt', 'base shared');
    write_test_file($file_base_root . '/wp-content/uploads/delete-me.txt', 'base delete');
    write_test_file($file_base_root . '/wp-content/uploads/target-delete.txt', 'base target delete');
    write_test_file($file_base_root . '/wp-content/uploads/target-change.txt', 'base target change');
    write_test_file($file_base_root . '/wp-content/uploads/conflict.txt', 'base conflict');
    create_test_symlink('shared.txt', $file_base_root . '/wp-content/uploads/shared-link.txt');
    mkdir($file_base_root . '/wp-content/uploads/delete-empty-dir', 0777, true);
    mkdir($file_base_root . '/wp-content/uploads/delete-dir-conflict', 0777, true);
    write_test_file($file_base_root . '/wp-config.php', 'managed base config');
    write_test_file($file_base_root . '/wp-content/database/.ht.sqlite', 'managed base db');
    copy_tree_for_test($file_base_root, $file_source_root);
    copy_tree_for_test($file_base_root, $file_target_root);
    $file_base_manifest = $tmp . '/.forkpress/cow/merge/file-bases/feature-files.json';
    $file_capture = cow_merge_capture_file_base($file_base_root, $file_base_manifest);
    assert_same($file_capture['files'], 6, 'filesystem merge base excludes ForkPress-managed files');

    write_test_file($file_source_root . '/wp-content/uploads/shared.txt', 'source shared');
    write_test_file($file_source_root . '/wp-content/uploads/new-source.txt', 'source new');
    mkdir($file_source_root . '/wp-content/uploads/source-empty-dir/nested', 0777, true);
    create_test_symlink('new-source.txt', $file_source_root . '/wp-content/uploads/shared-link.txt');
    create_test_symlink('../new-source.txt', $file_source_root . '/wp-content/uploads/links/source-link.txt');
    create_test_symlink('/etc/passwd', $file_source_root . '/wp-content/uploads/absolute-link.txt');
    create_test_symlink('../../../etc/passwd', $file_source_root . '/wp-content/uploads/outside-link.txt');
    unlink($file_source_root . '/wp-content/uploads/delete-me.txt');
    rmdir($file_source_root . '/wp-content/uploads/delete-empty-dir');
    rmdir($file_source_root . '/wp-content/uploads/delete-dir-conflict');
    write_test_file($file_source_root . '/wp-content/uploads/conflict.txt', 'source conflict');
    write_test_file($file_source_root . '/wp-config.php', 'source managed config');
    write_test_file($file_source_root . '/wp-content/database/.ht.sqlite', 'source managed db');

    write_test_file($file_target_root . '/wp-content/uploads/conflict.txt', 'target conflict');
    write_test_file($file_target_root . '/wp-content/uploads/target-only.txt', 'target only');
    unlink($file_target_root . '/wp-content/uploads/target-delete.txt');
    write_test_file($file_target_root . '/wp-content/uploads/target-change.txt', 'target changed');
    write_test_file($file_target_root . '/wp-content/uploads/delete-dir-conflict/target-child.txt', 'target child');
    write_test_file($file_target_root . '/wp-config.php', 'target managed config');
    write_test_file($file_target_root . '/wp-content/database/.ht.sqlite', 'target managed db');

    $result = cow_merge_branch_state(
        $file_base_db,
        $file_source_db,
        $file_target_db,
        $metadata,
        'feature-files',
        'main',
        $file_base_manifest,
        $file_source_root,
        $file_target_root
    );
    assert_same($result['status'], 'completed_with_conflicts', 'filesystem path conflicts complete with target-wins policy');
    assert_same(file_get_contents($file_target_root . '/wp-content/uploads/shared.txt'), 'source shared', 'source-only filesystem modification is applied');
    assert_same(file_get_contents($file_target_root . '/wp-content/uploads/new-source.txt'), 'source new', 'source-only filesystem addition is applied');
    assert_true(is_dir($file_target_root . '/wp-content/uploads/source-empty-dir/nested'), 'source-only empty filesystem directories are applied');
    assert_true(is_link($file_target_root . '/wp-content/uploads/shared-link.txt'), 'source-only filesystem symlink target change is applied');
    assert_same(readlink($file_target_root . '/wp-content/uploads/shared-link.txt'), 'new-source.txt', 'merged symlink keeps the source relative target');
    assert_true(is_link($file_target_root . '/wp-content/uploads/links/source-link.txt'), 'source-only safe filesystem symlink addition is applied');
    assert_same(readlink($file_target_root . '/wp-content/uploads/links/source-link.txt'), '../new-source.txt', 'safe symlink with in-root parent traversal is preserved');
    assert_true(!file_exists($file_target_root . '/wp-content/uploads/absolute-link.txt') && !is_link($file_target_root . '/wp-content/uploads/absolute-link.txt'), 'absolute source symlink is not auto-applied');
    assert_true(!file_exists($file_target_root . '/wp-content/uploads/outside-link.txt') && !is_link($file_target_root . '/wp-content/uploads/outside-link.txt'), 'path-traversing source symlink is not auto-applied');
    assert_true(!file_exists($file_target_root . '/wp-content/uploads/delete-me.txt'), 'source-only filesystem deletion is applied');
    assert_true(!file_exists($file_target_root . '/wp-content/uploads/delete-empty-dir'), 'source-only empty filesystem directory deletion is applied');
    assert_same(file_get_contents($file_target_root . '/wp-content/uploads/conflict.txt'), 'target conflict', 'target filesystem path wins conflicting edits');
    assert_same(file_get_contents($file_target_root . '/wp-content/uploads/target-only.txt'), 'target only', 'target-only filesystem addition is preserved');
    assert_true(!file_exists($file_target_root . '/wp-content/uploads/target-delete.txt'), 'target-only filesystem deletion is preserved');
    assert_same(file_get_contents($file_target_root . '/wp-content/uploads/target-change.txt'), 'target changed', 'target-only filesystem path change is preserved');
    assert_same(file_get_contents($file_target_root . '/wp-content/uploads/delete-dir-conflict/target-child.txt'), 'target child', 'target-side directory descendants block automatic source directory deletion');
    assert_same(file_get_contents($file_target_root . '/wp-config.php'), 'target managed config', 'managed wp-config.php is excluded from filesystem merge');
    assert_same(file_get_contents($file_target_root . '/wp-content/database/.ht.sqlite'), 'target managed db', 'managed SQLite database path is excluded from filesystem merge');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = '__files__' AND conflict_type = 'file-conflict'"), 1, 'filesystem conflict is auditable');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = '__files__' AND conflict_type = 'file-directory-delete-conflict'"), 1, 'unsafe filesystem directory deletion conflict is auditable');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = '__files__' AND conflict_type = 'file-unsafe-symlink'"), 2, 'unsafe filesystem symlink conflicts are auditable');
    assert_true((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = '__files__' AND decision = 'source-applied'") >= 5, 'filesystem automatic decisions are auditable');
    $target_only_file_identity = SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/target-only.txt'));
    $target_delete_file_identity = SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/target-delete.txt'));
    $target_change_file_identity = SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/target-change.txt'));
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = '__files__' AND decision = 'target-kept' AND row_identity = '$target_only_file_identity'"), 1, 'target-only filesystem addition preservation is auditable');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = '__files__' AND decision = 'target-kept' AND row_identity = '$target_delete_file_identity' AND target_payload IS NULL AND chosen_payload IS NULL"), 1, 'target-only filesystem deletion preservation is auditable');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = '__files__' AND decision = 'target-kept' AND row_identity = '$target_change_file_identity'"), 1, 'target-only filesystem path change preservation is auditable');
    $file_conflict_audit = cow_merge_audit_report($metadata, null, 10, ['scope' => 'files', 'records' => 'conflicts']);
    assert_same($file_conflict_audit['filters']['scope'], 'files', 'merge audit JSON report includes the file scope filter');
    assert_same($file_conflict_audit['filters']['records'], 'conflicts', 'merge audit JSON report includes the record-type filter');
    assert_same(count($file_conflict_audit['conflicts']), 4, 'merge audit can focus on filesystem conflicts');
    assert_same(count($file_conflict_audit['decisions']), 0, 'conflict-only audit filter omits decisions');
    assert_same(count($file_conflict_audit['autoincrement_bands']), 0, 'file conflict audit filter omits database-only band summaries');
    assert_same(count($file_conflict_audit['row_identity_summary']), 0, 'file conflict audit filter omits database-only row identity summaries');
    assert_same(count(array_filter($file_conflict_audit['conflicts'], fn($row) => $row['table_name'] === '__files__')), 4, 'filesystem audit filter exports only file records');
    $unsafe_symlink_audit = cow_merge_audit_report($metadata, null, 10, [
        'scope' => 'files',
        'records' => 'conflicts',
        'conflict_type' => 'file-unsafe-symlink',
    ]);
    assert_same(count($unsafe_symlink_audit['conflicts']), 2, 'merge audit can filter filesystem conflicts by type');
    ob_start();
    cow_merge_print_audit_text($unsafe_symlink_audit);
    $unsafe_symlink_text = ob_get_clean();
    assert_true(str_contains($unsafe_symlink_text, 'filters:   scope=files records=conflicts conflict-type=file-unsafe-symlink'), 'merge audit text prints active file conflict filters');
    assert_true(str_contains($unsafe_symlink_text, 'file-unsafe-symlink'), 'filtered file audit text includes matching file conflict type');
    assert_true(!str_contains($unsafe_symlink_text, 'decisions:'), 'filtered file conflict audit text omits decisions section');
    $exact_path_audit = cow_merge_audit_report($metadata, null, 10, [
        'records' => 'conflicts',
        'path' => 'wp-content/uploads/absolute-link.txt',
    ]);
    assert_same($exact_path_audit['filters']['path'], 'wp-content/uploads/absolute-link.txt', 'merge audit JSON report includes exact file path filter');
    assert_same(count($exact_path_audit['conflicts']), 1, 'merge audit can filter filesystem conflicts by exact path');
    assert_same($exact_path_audit['conflicts'][0]['row_identity'], cow_merge_file_identity_json('wp-content/uploads/absolute-link.txt'), 'exact path audit filter returns the requested file identity');
    $path_prefix_audit = cow_merge_audit_report($metadata, null, 10, [
        'scope' => 'files',
        'records' => 'decisions',
        'decision' => 'source-applied',
        'path_prefix' => 'wp-content/uploads/links',
    ]);
    assert_same($path_prefix_audit['filters']['path_prefix'], 'wp-content/uploads/links', 'merge audit JSON report includes file path prefix filter');
    assert_true(count($path_prefix_audit['decisions']) >= 1, 'merge audit can filter filesystem decisions by path prefix');
    assert_same(
        count(array_filter($path_prefix_audit['decisions'], fn($row) => $row['row_identity'] === cow_merge_file_identity_json('wp-content/uploads/links/source-link.txt'))),
        1,
        'path-prefix audit filter returns matching file identities'
    );
    ob_start();
    cow_merge_print_audit_text($path_prefix_audit);
    $path_prefix_text = ob_get_clean();
    assert_true(str_contains($path_prefix_text, 'path-prefix=wp-content/uploads/links'), 'merge audit text prints active file path prefix filters');

    $file_resolve_base_root = $tmp . '/files-resolve-base';
    $file_resolve_source_root = $tmp . '/files-resolve-source';
    $file_resolve_target_root = $tmp . '/files-resolve-target';
    mkdir($file_resolve_base_root . '/wp-content/uploads', 0777, true);
    write_test_file($file_resolve_base_root . '/wp-content/uploads/conflict.txt', 'base conflict');
    write_test_file($file_resolve_base_root . '/wp-content/uploads/delete-conflict.txt', 'base delete conflict');
    copy_tree_for_test($file_resolve_base_root, $file_resolve_source_root);
    copy_tree_for_test($file_resolve_base_root, $file_resolve_target_root);
    $file_resolve_base_db = $file_resolve_base_root . '/wp-content/database/.ht.sqlite';
    $file_resolve_source_db = $file_resolve_source_root . '/wp-content/database/.ht.sqlite';
    $file_resolve_target_db = $file_resolve_target_root . '/wp-content/database/.ht.sqlite';
    mkdir(dirname($file_resolve_base_db), 0777, true);
    mkdir(dirname($file_resolve_source_db), 0777, true);
    mkdir(dirname($file_resolve_target_db), 0777, true);
    create_base_db($file_resolve_base_db);
    create_base_db($file_resolve_source_db);
    create_base_db($file_resolve_target_db);
    $file_resolve_manifest = $tmp . '/.forkpress/cow/merge/file-bases/feature-file-resolve.json';
    cow_merge_capture_file_base($file_resolve_base_root, $file_resolve_manifest);
    write_test_file($file_resolve_source_root . '/wp-content/uploads/conflict.txt', 'source conflict resolution');
    create_test_symlink('/etc/passwd', $file_resolve_source_root . '/wp-content/uploads/unsafe-link.txt');
    unlink($file_resolve_source_root . '/wp-content/uploads/delete-conflict.txt');
    write_test_file($file_resolve_target_root . '/wp-content/uploads/conflict.txt', 'target conflict resolution');
    write_test_file($file_resolve_target_root . '/wp-content/uploads/delete-conflict.txt', 'target changed before source deletion');
    cow_merge_branch_state(
        $file_resolve_base_db,
        $file_resolve_source_db,
        $file_resolve_target_db,
        $metadata,
        'feature-file-resolve',
        'main',
        $file_resolve_manifest,
        $file_resolve_source_root,
        $file_resolve_target_root
    );
    $file_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = '__files__' AND row_identity = '" . SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/conflict.txt')) . "' ORDER BY id DESC LIMIT 1");
    $file_dry_resolution = cow_merge_resolve_conflict(
        $metadata,
        $file_conflict_id,
        'source',
        false,
        'Preview source file resolution.',
        'cow-test'
    );
    assert_same($file_dry_resolution['status'], 'validated', 'dry-run filesystem conflict resolution validates path preconditions');
    assert_same(file_get_contents($file_resolve_target_root . '/wp-content/uploads/conflict.txt'), 'target conflict resolution', 'dry-run filesystem resolution does not mutate target path');
    $file_source_resolution = cow_merge_resolve_conflict(
        $metadata,
        $file_conflict_id,
        'source',
        true,
        'Apply audited source file.',
        'cow-test'
    );
    assert_same($file_source_resolution['status'], 'applied', 'source filesystem conflict resolution records applied status');
    assert_same(file_get_contents($file_resolve_target_root . '/wp-content/uploads/conflict.txt'), 'source conflict resolution', 'source filesystem conflict resolution copies the audited source file');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $file_conflict_id AND table_name = '__files__' AND column_name = 'path' AND choice = 'source' AND applied = 1"), 1, 'filesystem conflict resolution is auditable');
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $file_conflict_id, 'target', true, 'Try stale file keep.', 'cow-test'),
        'target filesystem path no longer matches',
        'stale filesystem conflict resolution is blocked after the target path changes'
    );
    $file_delete_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = '__files__' AND row_identity = '" . SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/delete-conflict.txt')) . "' ORDER BY id DESC LIMIT 1");
    $file_delete_resolution = cow_merge_resolve_conflict(
        $metadata,
        $file_delete_conflict_id,
        'source',
        true,
        'Apply audited source file deletion.',
        'cow-test'
    );
    assert_same($file_delete_resolution['status'], 'applied', 'source filesystem deletion conflict resolution records applied status');
    assert_true(!file_exists($file_resolve_target_root . '/wp-content/uploads/delete-conflict.txt'), 'source filesystem deletion conflict resolution removes the target path after validation');
    $unsafe_symlink_id = (int)scalar($metadata, "SELECT c.id FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = '__files__' AND c.conflict_type = 'file-unsafe-symlink' AND r.source_branch = 'feature-file-resolve' ORDER BY c.id DESC LIMIT 1");
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $unsafe_symlink_id, 'source', true, 'Try unsafe source symlink.', 'cow-test'),
        'cannot apply source filesystem conflict',
        'unsafe source symlink conflicts cannot be applied by the deterministic resolver'
    );

    $rollback_base_root = $tmp . '/files-rollback-base';
    $rollback_source_root = $tmp . '/files-rollback-source';
    $rollback_target_root = $tmp . '/files-rollback-target';
    mkdir($rollback_base_root . '/wp-content/uploads', 0777, true);
    write_test_file($rollback_base_root . '/wp-content/uploads/a-first.txt', 'base first');
    write_test_file($rollback_base_root . '/wp-content/uploads/b-second.txt', 'base second');
    copy_tree_for_test($rollback_base_root, $rollback_source_root);
    copy_tree_for_test($rollback_base_root, $rollback_target_root);
    $rollback_manifest = $tmp . '/.forkpress/cow/merge/file-bases/feature-rollback.json';
    cow_merge_capture_file_base($rollback_base_root, $rollback_manifest);
    write_test_file($rollback_source_root . '/wp-content/uploads/a-first.txt', 'source first');
    write_test_file($rollback_source_root . '/wp-content/uploads/b-second.txt', 'source second');

    $rollback_metadata = $tmp . '/.forkpress/cow/merge/rollback-metadata.sqlite';
    $rollback_meta = open_db($rollback_metadata);
    cow_merge_ensure_metadata($rollback_meta);
    $rollback_run_id = cow_merge_start_run(
        $rollback_meta,
        'feature-rollback',
        'main',
        $file_base_db,
        $file_source_db,
        $file_target_db
    );
    $rollback_meta->exec(<<<'SQL'
CREATE TRIGGER fail_after_first_file_decision
BEFORE INSERT ON merge_decisions
WHEN NEW.table_name = '__files__'
  AND NEW.decision = 'source-applied'
  AND (
    SELECT COUNT(*)
    FROM merge_decisions
    WHERE table_name = '__files__'
      AND decision = 'source-applied'
  ) >= 1
BEGIN
    SELECT RAISE(ABORT, 'forced filesystem decision failure');
END
SQL);
    $rollback_meta->close();

    $rollback_failed = false;
    set_error_handler(static function (int $severity, string $message): bool {
        return str_contains($message, 'forced filesystem decision failure');
    });
    try {
        cow_merge_files($rollback_manifest, $rollback_source_root, $rollback_target_root, $rollback_metadata, $rollback_run_id);
    } catch (Throwable $e) {
        $rollback_failed = str_contains($e->getMessage(), 'forced filesystem decision failure');
    } finally {
        restore_error_handler();
    }
    assert_true($rollback_failed, 'filesystem merge failure is surfaced to the caller');
    assert_same(file_get_contents($rollback_target_root . '/wp-content/uploads/a-first.txt'), 'base first', 'filesystem rollback restores a file changed before the failure');
    assert_same(file_get_contents($rollback_target_root . '/wp-content/uploads/b-second.txt'), 'base second', 'filesystem rollback restores the file changed by the failing operation');
    assert_same((int)scalar($rollback_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = '__files__' AND decision = 'source-applied'"), 0, 'filesystem decision metadata rolls back with failed file operations');

    $whole_base_db = $tmp . '/whole-rollback-base.sqlite';
    $whole_source_db = $tmp . '/whole-rollback-source.sqlite';
    $whole_target_db = $tmp . '/whole-rollback-target.sqlite';
    create_base_db($whole_base_db);
    copy($whole_base_db, $whole_source_db);
    copy($whole_base_db, $whole_target_db);
    $db = open_db($whole_source_db);
    $db->exec("UPDATE wp_posts SET post_content = 'source db change before file failure' WHERE ID = 1");
    $db->exec("INSERT INTO wp_posts (ID, post_title, post_content, post_status) VALUES (2, 'Source rollback post', 'should not remain', 'draft')");
    $db->close();

    $whole_base_root = $tmp . '/whole-rollback-base';
    $whole_source_root = $tmp . '/whole-rollback-source';
    $whole_target_root = $tmp . '/whole-rollback-target';
    mkdir($whole_base_root . '/wp-content/uploads', 0777, true);
    write_test_file($whole_base_root . '/wp-content/uploads/whole.txt', 'whole base');
    copy_tree_for_test($whole_base_root, $whole_source_root);
    copy_tree_for_test($whole_base_root, $whole_target_root);
    write_test_file($whole_source_root . '/wp-content/uploads/whole.txt', 'whole source');
    $whole_manifest = $tmp . '/.forkpress/cow/merge/file-bases/feature-whole-rollback.json';
    cow_merge_capture_file_base($whole_base_root, $whole_manifest);

    $whole_metadata = $tmp . '/.forkpress/cow/merge/whole-rollback-metadata.sqlite';
    $whole_meta = open_db($whole_metadata);
    cow_merge_ensure_metadata($whole_meta);
    $whole_meta->exec(<<<'SQL'
CREATE TRIGGER fail_first_branch_file_decision
BEFORE INSERT ON merge_decisions
WHEN NEW.table_name = '__files__'
  AND NEW.decision = 'source-applied'
BEGIN
    SELECT RAISE(ABORT, 'forced whole-branch file failure');
END
SQL);
    $whole_meta->close();

    $whole_failed = false;
    set_error_handler(static function (int $severity, string $message): bool {
        return str_contains($message, 'forced whole-branch file failure');
    });
    try {
        cow_merge_branch_state(
            $whole_base_db,
            $whole_source_db,
            $whole_target_db,
            $whole_metadata,
            'feature-whole-rollback',
            'main',
            $whole_manifest,
            $whole_source_root,
            $whole_target_root
        );
    } catch (Throwable $e) {
        $whole_failed = str_contains($e->getMessage(), 'forced whole-branch file failure');
    } finally {
        restore_error_handler();
    }
    assert_true($whole_failed, 'whole-branch rollback surfaces the file-phase failure');
    assert_same(scalar($whole_target_db, "SELECT post_content FROM wp_posts WHERE ID = 1"), 'Base content', 'whole-branch rollback restores target DB cell changes from the DB phase');
    assert_same((int)scalar($whole_target_db, "SELECT COUNT(*) FROM wp_posts WHERE ID = 2"), 0, 'whole-branch rollback removes source rows inserted during the DB phase');
    assert_same(file_get_contents($whole_target_root . '/wp-content/uploads/whole.txt'), 'whole base', 'whole-branch rollback restores filesystem changes from the file phase');
    assert_same((int)scalar($whole_metadata, "SELECT COUNT(*) FROM merge_runs WHERE source_branch = 'feature-whole-rollback' AND target_branch = 'main' AND status = 'failed'"), 1, 'whole-branch rollback records a failed merge run after restoring metadata');
    $whole_failure_reason = (string)scalar($whole_metadata, "SELECT failure_reason FROM merge_runs WHERE source_branch = 'feature-whole-rollback' AND target_branch = 'main' AND status = 'failed'");
    assert_true(str_contains($whole_failure_reason, 'forced whole-branch file failure'), 'whole-branch rollback records the failed-run reason');
    $whole_audit = cow_merge_audit_report($whole_metadata, null, 5);
    assert_true(str_contains((string)$whole_audit['runs'][0]['failure_reason'], 'forced whole-branch file failure'), 'merge audit JSON report exposes failed-run reason');
    ob_start();
    cow_merge_print_audit_text($whole_audit);
    $whole_audit_text = ob_get_clean();
    assert_true(str_contains($whole_audit_text, 'failure=') && str_contains($whole_audit_text, 'forced whole-branch file failure'), 'merge audit text prints failed-run reason');
    assert_same((int)scalar($whole_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE decision = 'source-applied'"), 0, 'whole-branch rollback does not leave source-applied decisions for rolled-back changes');

    $rollback_artifact_metadata = $tmp . '/.forkpress/cow/merge/rollback-failure-artifact.sqlite';
    $artifact_path = cow_merge_record_rollback_failure_artifact(
        $rollback_artifact_metadata,
        123,
        'feature-rollback-artifact',
        'main',
        '/tmp/base.sqlite',
        '/tmp/source.sqlite',
        '/tmp/target.sqlite',
        'original merge failure',
        'restore snapshot failure'
    );
    assert_true(is_string($artifact_path) && is_file($artifact_path), 'rollback failure records a JSONL artifact outside the metadata database');
    $artifact_lines = file($artifact_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    assert_true(is_array($artifact_lines) && count($artifact_lines) === 1, 'rollback failure artifact contains one JSONL record');
    $artifact_record = json_decode($artifact_lines[0], true);
    assert_same($artifact_record['rollback_failure'], 'restore snapshot failure', 'rollback failure artifact preserves rollback failure reason');
    assert_same((int)scalar($rollback_artifact_metadata, "SELECT COUNT(*) FROM merge_rollback_failures WHERE source_branch = 'feature-rollback-artifact'"), 1, 'rollback failure is queryable in merge metadata when available');
    $rollback_failure_audit = cow_merge_audit_report($rollback_artifact_metadata, null, 5);
    assert_same(count($rollback_failure_audit['rollback_failures']), 1, 'merge audit JSON report exposes rollback failure artifacts');
    ob_start();
    cow_merge_print_audit_text($rollback_failure_audit);
    $rollback_failure_text = ob_get_clean();
    assert_true(str_contains($rollback_failure_text, 'rollback-failures:') && str_contains($rollback_failure_text, 'restore snapshot failure'), 'merge audit text prints rollback failure artifacts');

    $schema_base = $tmp . '/schema-base.sqlite';
    $schema_source = $tmp . '/schema-source.sqlite';
    $schema_target = $tmp . '/schema-target.sqlite';
    create_base_db($schema_base);
    copy($schema_base, $schema_source);
    copy($schema_base, $schema_target);

    $db = open_db($schema_source);
    $db->exec('ALTER TABLE plugin_items ADD COLUMN extra TEXT');
    $db->exec('CREATE INDEX plugin_items_label_idx ON plugin_items(label)');
    $db->exec("UPDATE plugin_items SET extra = 'source-only schema value' WHERE item_id = 'alpha'");
    $db->close();

    $db = open_db($schema_target);
    $db->exec('ALTER TABLE plugin_items ADD COLUMN target_note TEXT');
    $db->exec('CREATE INDEX plugin_items_target_note_idx ON plugin_items(target_note)');
    $db->exec("UPDATE plugin_items SET target_note = 'target-only schema value' WHERE item_id = 'alpha'");
    $db->close();

    $result = cow_merge_databases($schema_base, $schema_source, $schema_target, $metadata, 'feature-schema', 'main');
    assert_same($result['status'], 'completed', 'independent safe schema additions merge cleanly');
    assert_same(scalar($schema_target, "SELECT extra FROM plugin_items WHERE item_id = 'alpha'"), 'source-only schema value', 'source-added column is added to target and row value is merged');
    assert_same(scalar($schema_target, "SELECT target_note FROM plugin_items WHERE item_id = 'alpha'"), 'target-only schema value', 'target-added column value is preserved');
    assert_same((int)scalar($schema_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_items_label_idx'"), 1, 'source-added index is created on target');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_items' AND column_name = 'extra' AND row_identity IS NULL AND decision = 'source-applied'"), 1, 'source-added column decision is auditable');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_items' AND column_name = 'plugin_items_label_idx' AND decision = 'source-applied'"), 1, 'source-added index decision is auditable');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_items' AND column_name = 'target_note' AND row_identity IS NULL AND decision = 'target-kept'"), 1, 'target-added column preservation is auditable');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_items' AND column_name = 'plugin_items_target_note_idx' AND decision = 'target-kept'"), 1, 'target-added index preservation is auditable');

    $schema_conflict_base = $tmp . '/schema-conflict-base.sqlite';
    $schema_conflict_source = $tmp . '/schema-conflict-source.sqlite';
    $schema_conflict_target = $tmp . '/schema-conflict-target.sqlite';
    create_base_db($schema_conflict_base);
    copy($schema_conflict_base, $schema_conflict_source);
    copy($schema_conflict_base, $schema_conflict_target);

    $db = open_db($schema_conflict_source);
    $db->exec('ALTER TABLE plugin_items ADD COLUMN extra TEXT');
    $db->close();

    $db = open_db($schema_conflict_target);
    $db->exec('ALTER TABLE plugin_items ADD COLUMN extra INTEGER');
    $db->close();

    $result = cow_merge_databases($schema_conflict_base, $schema_conflict_source, $schema_conflict_target, $metadata, 'feature-schema-conflict', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'incompatible same-name schema additions remain conflicts');
    assert_same(column_type($schema_conflict_target, 'plugin_items', 'extra'), 'INTEGER', 'target schema wins incompatible same-name column addition');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_items' AND column_name = 'extra' AND conflict_type = 'schema-column-conflict'"), 1, 'incompatible schema addition conflict is auditable');
    $schema_target_resolution_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_items' AND column_name = 'extra' AND conflict_type = 'schema-column-conflict' ORDER BY id DESC LIMIT 1");
    $schema_target_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_target_resolution_id,
        'target',
        true,
        'Keep target schema type.',
        'test'
    );
    assert_same($schema_target_resolution['status'], 'validated', 'target schema conflict resolution validates current target schema');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_target_resolution_id AND table_name = 'plugin_items' AND column_name = 'extra' AND choice = 'target' AND applied = 1"), 1, 'target schema resolution is auditable');

    $schema_rebuild_base = $tmp . '/schema-rebuild-base.sqlite';
    $schema_rebuild_source = $tmp . '/schema-rebuild-source.sqlite';
    $schema_rebuild_target = $tmp . '/schema-rebuild-target.sqlite';
    create_base_db($schema_rebuild_base);
    copy($schema_rebuild_base, $schema_rebuild_source);
    copy($schema_rebuild_base, $schema_rebuild_target);

    $db = open_db($schema_rebuild_source);
    $db->exec('CREATE TABLE plugin_items_new (item_id TEXT PRIMARY KEY, label TEXT, value INTEGER)');
    $db->exec('INSERT INTO plugin_items_new (item_id, label, value) SELECT item_id, label, value FROM plugin_items');
    $db->exec('DROP TABLE plugin_items');
    $db->exec('ALTER TABLE plugin_items_new RENAME TO plugin_items');
    $db->close();

    $db = open_db($schema_rebuild_target);
    $db->exec("UPDATE plugin_items SET value = 'target preserved' WHERE item_id = 'alpha'");
    $db->exec('CREATE TABLE plugin_items_new (item_id TEXT PRIMARY KEY, label TEXT, value REAL)');
    $db->exec('INSERT INTO plugin_items_new (item_id, label, value) SELECT item_id, label, value FROM plugin_items');
    $db->exec('DROP TABLE plugin_items');
    $db->exec('ALTER TABLE plugin_items_new RENAME TO plugin_items');
    $db->close();

    $result = cow_merge_databases($schema_rebuild_base, $schema_rebuild_source, $schema_rebuild_target, $metadata, 'feature-schema-rebuild', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'incompatible table column rewrite remains a schema conflict');
    $schema_rebuild_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_items' AND column_name IS NULL AND conflict_type = 'schema-conflict' ORDER BY id DESC LIMIT 1");
    $schema_rebuild_dry = cow_merge_resolve_conflict(
        $metadata,
        $schema_rebuild_conflict_id,
        'source',
        false,
        'Preview table rebuild.',
        'test'
    );
    assert_same($schema_rebuild_dry['status'], 'validated', 'dry-run table rebuild schema resolution validates current schemas');
    assert_same(column_type($schema_rebuild_target, 'plugin_items', 'value'), 'REAL', 'dry-run table rebuild does not mutate target schema');
    $schema_rebuild_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_rebuild_conflict_id,
        'source',
        true,
        'Apply source table schema with preserved target rows.',
        'test'
    );
    assert_same($schema_rebuild_resolution['status'], 'applied', 'source table rebuild schema resolution records applied status');
    assert_same(column_type($schema_rebuild_target, 'plugin_items', 'value'), 'INTEGER', 'source table rebuild applies audited source column definition');
    assert_same(scalar($schema_rebuild_target, "SELECT value FROM plugin_items WHERE item_id = 'alpha'"), 'target preserved', 'source table rebuild preserves target row data');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_rebuild_conflict_id AND table_name = 'plugin_items' AND choice = 'source' AND applied = 1"), 1, 'source table rebuild schema resolution is auditable');

    $schema_rebuild_dep_base = $tmp . '/schema-rebuild-dep-base.sqlite';
    $schema_rebuild_dep_source = $tmp . '/schema-rebuild-dep-source.sqlite';
    $schema_rebuild_dep_target = $tmp . '/schema-rebuild-dep-target.sqlite';
    create_base_db($schema_rebuild_dep_base);
    copy($schema_rebuild_dep_base, $schema_rebuild_dep_source);
    copy($schema_rebuild_dep_base, $schema_rebuild_dep_target);

    $db = open_db($schema_rebuild_dep_source);
    $db->exec('CREATE TABLE plugin_items_new (item_id TEXT PRIMARY KEY, label TEXT, value INTEGER)');
    $db->exec('INSERT INTO plugin_items_new (item_id, label, value) SELECT item_id, label, value FROM plugin_items');
    $db->exec('DROP TABLE plugin_items');
    $db->exec('ALTER TABLE plugin_items_new RENAME TO plugin_items');
    $db->close();

    $db = open_db($schema_rebuild_dep_target);
    $db->exec('CREATE TABLE plugin_item_audit (item_id TEXT)');
    $db->exec('CREATE INDEX plugin_items_dep_label_idx ON plugin_items(label)');
    $db->exec('CREATE TRIGGER plugin_items_dep_insert AFTER INSERT ON plugin_items BEGIN INSERT INTO plugin_item_audit (item_id) VALUES (NEW.item_id); END');
    $db->exec('CREATE TABLE plugin_items_new (item_id TEXT PRIMARY KEY, label TEXT, value REAL)');
    $db->exec('INSERT INTO plugin_items_new (item_id, label, value) SELECT item_id, label, value FROM plugin_items');
    $db->exec('DROP TABLE plugin_items');
    $db->exec('ALTER TABLE plugin_items_new RENAME TO plugin_items');
    $db->exec('CREATE INDEX plugin_items_dep_label_idx ON plugin_items(label)');
    $db->exec('CREATE TRIGGER plugin_items_dep_insert AFTER INSERT ON plugin_items BEGIN INSERT INTO plugin_item_audit (item_id) VALUES (NEW.item_id); END');
    $db->close();

    $result = cow_merge_databases($schema_rebuild_dep_base, $schema_rebuild_dep_source, $schema_rebuild_dep_target, $metadata, 'feature-schema-rebuild-deps', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'table rewrite with target dependents remains a schema conflict before resolution');
    $schema_rebuild_dep_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_items' AND column_name IS NULL AND conflict_type = 'schema-conflict' ORDER BY id DESC LIMIT 1");
    $schema_rebuild_dep_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_rebuild_dep_conflict_id,
        'source',
        true,
        'Apply source table schema and preserve target dependents.',
        'test'
    );
    assert_same($schema_rebuild_dep_resolution['status'], 'applied', 'source table rebuild with target dependents records applied status');
    assert_same(column_type($schema_rebuild_dep_target, 'plugin_items', 'value'), 'INTEGER', 'source table rebuild with target dependents applies audited source schema');
    assert_same((int)scalar($schema_rebuild_dep_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_items_dep_label_idx'"), 1, 'source table rebuild recreates target explicit index');
    assert_same((int)scalar($schema_rebuild_dep_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_items_dep_insert'"), 1, 'source table rebuild recreates target trigger');
    $db = open_db($schema_rebuild_dep_target);
    $db->exec("INSERT INTO plugin_items (item_id, label, value) VALUES ('beta', 'Beta', 42)");
    $db->close();
    assert_same(scalar($schema_rebuild_dep_target, "SELECT item_id FROM plugin_item_audit WHERE item_id = 'beta'"), 'beta', 'recreated target trigger still fires after schema rebuild');

    $schema_rebuild_view_base = $tmp . '/schema-rebuild-view-base.sqlite';
    $schema_rebuild_view_source = $tmp . '/schema-rebuild-view-source.sqlite';
    $schema_rebuild_view_target = $tmp . '/schema-rebuild-view-target.sqlite';
    create_base_db($schema_rebuild_view_base);
    copy($schema_rebuild_view_base, $schema_rebuild_view_source);
    copy($schema_rebuild_view_base, $schema_rebuild_view_target);

    $db = open_db($schema_rebuild_view_source);
    $db->exec('CREATE TABLE plugin_items_new (item_id TEXT PRIMARY KEY, label TEXT, value INTEGER)');
    $db->exec('INSERT INTO plugin_items_new (item_id, label, value) SELECT item_id, label, value FROM plugin_items');
    $db->exec('DROP TABLE plugin_items');
    $db->exec('ALTER TABLE plugin_items_new RENAME TO plugin_items');
    $db->close();

    $db = open_db($schema_rebuild_view_target);
    $db->exec('CREATE VIEW plugin_items_view AS SELECT item_id, label FROM plugin_items');
    $db->exec('CREATE TABLE plugin_items_new (item_id TEXT PRIMARY KEY, label TEXT, value REAL)');
    $db->exec('INSERT INTO plugin_items_new (item_id, label, value) SELECT item_id, label, value FROM plugin_items');
    $db->exec('DROP VIEW plugin_items_view');
    $db->exec('DROP TABLE plugin_items');
    $db->exec('ALTER TABLE plugin_items_new RENAME TO plugin_items');
    $db->exec('CREATE VIEW plugin_items_view AS SELECT item_id, label FROM plugin_items');
    $db->close();

    $result = cow_merge_databases($schema_rebuild_view_base, $schema_rebuild_view_source, $schema_rebuild_view_target, $metadata, 'feature-schema-rebuild-view', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'table rewrite with dependent target view remains a schema conflict before resolution');
    $schema_rebuild_view_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_items' AND column_name IS NULL AND conflict_type = 'schema-conflict' ORDER BY id DESC LIMIT 1");
    $schema_rebuild_view_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_rebuild_view_conflict_id,
        'source',
        true,
        'Apply source table schema and preserve target view.',
        'test'
    );
    assert_same($schema_rebuild_view_resolution['status'], 'applied', 'source table rebuild with target view records applied status');
    assert_same(column_type($schema_rebuild_view_target, 'plugin_items', 'value'), 'INTEGER', 'source table rebuild with target view applies audited source schema');
    assert_same((int)scalar($schema_rebuild_view_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_items_view'"), 1, 'source table rebuild preserves target view');
    assert_same(scalar($schema_rebuild_view_target, "SELECT label FROM plugin_items_view WHERE item_id = 'alpha'"), 'Alpha', 'preserved target view remains queryable after schema rebuild');

    $schema_resolve_base = $tmp . '/schema-resolve-base.sqlite';
    $schema_resolve_source = $tmp . '/schema-resolve-source.sqlite';
    $schema_resolve_target = $tmp . '/schema-resolve-target.sqlite';
    create_base_db($schema_resolve_base);
    copy($schema_resolve_base, $schema_resolve_source);
    copy($schema_resolve_base, $schema_resolve_target);

    $db = open_db($schema_resolve_source);
    $db->exec('ALTER TABLE plugin_items ADD COLUMN review_note TEXT DEFAULT NULL');
    $db->exec('CREATE INDEX plugin_items_review_idx ON plugin_items(review_note)');
    $source_columns = cow_merge_columns_by_name(cow_merge_table_info($db, 'plugin_items'));
    $source_index_sql = cow_merge_index_sql($db, 'plugin_items_review_idx');
    $db->close();

    $db = open_db($schema_resolve_target);
    $target_table_sql = cow_merge_table_sql($db, 'plugin_items');
    $db->close();

    $manual_meta = cow_merge_open_db($metadata, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    cow_merge_ensure_metadata($manual_meta);
    $manual_run_id = cow_merge_start_run($manual_meta, 'feature-schema-resolution', 'main', $schema_resolve_base, $schema_resolve_source, $schema_resolve_target);
    cow_merge_record_schema_conflict(
        $manual_meta,
        $manual_run_id,
        'plugin_items',
        'review_note',
        'schema-source-changed',
        $target_table_sql,
        ['column' => $source_columns['review_note'], 'definition' => 'review_note TEXT DEFAULT NULL', 'error' => 'simulated earlier apply failure'],
        $target_table_sql,
        $target_table_sql,
        'simulated source-added column conflict'
    );
    cow_merge_record_schema_conflict(
        $manual_meta,
        $manual_run_id,
        'plugin_items',
        'plugin_items_review_idx',
        'schema-source-added-index',
        null,
        ['sql' => $source_index_sql, 'error' => 'simulated earlier apply failure'],
        null,
        null,
        'simulated source-added index conflict'
    );
    cow_merge_finish_run($manual_meta, $manual_run_id, 'completed_with_conflicts');
    $manual_meta->close();

    $schema_column_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_items' AND column_name = 'review_note' AND conflict_type = 'schema-source-changed' ORDER BY id DESC LIMIT 1");
    $schema_column_dry = cow_merge_resolve_conflict(
        $metadata,
        $schema_column_conflict_id,
        'source',
        false,
        'Preview safe source column.',
        'test'
    );
    assert_same($schema_column_dry['status'], 'validated', 'dry-run schema column source resolution validates target preconditions');
    assert_same(column_type($schema_resolve_target, 'plugin_items', 'review_note'), null, 'dry-run schema column resolution does not mutate target schema');
    $schema_column_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_column_conflict_id,
        'source',
        true,
        'Apply safe source column.',
        'test'
    );
    assert_same($schema_column_resolution['status'], 'applied', 'source schema column resolution records applied status');
    assert_same(column_type($schema_resolve_target, 'plugin_items', 'review_note'), 'TEXT', 'source schema column resolution applies audited safe column');

    $schema_index_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_items' AND column_name = 'plugin_items_review_idx' AND conflict_type = 'schema-source-added-index' ORDER BY id DESC LIMIT 1");
    $schema_index_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_index_conflict_id,
        'source',
        true,
        'Apply safe source index.',
        'test'
    );
    assert_same($schema_index_resolution['status'], 'applied', 'source schema index resolution records applied status');
    assert_same((int)scalar($schema_resolve_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_items_review_idx'"), 1, 'source schema index resolution applies audited source index');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id IN ($schema_column_conflict_id, $schema_index_conflict_id) AND choice = 'source' AND applied = 1"), 2, 'source schema resolutions are auditable');

    $schema_index_rewrite_base = $tmp . '/schema-index-rewrite-base.sqlite';
    $schema_index_rewrite_source = $tmp . '/schema-index-rewrite-source.sqlite';
    $schema_index_rewrite_target = $tmp . '/schema-index-rewrite-target.sqlite';
    create_base_db($schema_index_rewrite_base);
    $db = open_db($schema_index_rewrite_base);
    $db->exec('CREATE INDEX plugin_items_label_idx ON plugin_items(label)');
    $db->close();
    copy($schema_index_rewrite_base, $schema_index_rewrite_source);
    copy($schema_index_rewrite_base, $schema_index_rewrite_target);

    $db = open_db($schema_index_rewrite_source);
    $db->exec('DROP INDEX plugin_items_label_idx');
    $db->exec('CREATE INDEX plugin_items_label_idx ON plugin_items(value)');
    $db->close();

    $result = cow_merge_databases($schema_index_rewrite_base, $schema_index_rewrite_source, $schema_index_rewrite_target, $metadata, 'feature-index-rewrite', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'source-changed index remains a schema conflict');
    $schema_index_rewrite_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_items' AND column_name = 'plugin_items_label_idx' AND conflict_type = 'schema-source-changed-index' ORDER BY id DESC LIMIT 1");
    $schema_index_rewrite_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_index_rewrite_conflict_id,
        'source',
        true,
        'Apply source index rewrite.',
        'test'
    );
    assert_same($schema_index_rewrite_resolution['status'], 'applied', 'source index rewrite resolution records applied status');
    assert_true(str_contains((string)scalar($schema_index_rewrite_target, "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'plugin_items_label_idx'"), '(value)'), 'source index rewrite replaces target index definition');

    $schema_index_drop_base = $tmp . '/schema-index-drop-base.sqlite';
    $schema_index_drop_source = $tmp . '/schema-index-drop-source.sqlite';
    $schema_index_drop_target = $tmp . '/schema-index-drop-target.sqlite';
    create_base_db($schema_index_drop_base);
    $db = open_db($schema_index_drop_base);
    $db->exec('CREATE INDEX plugin_items_drop_idx ON plugin_items(label)');
    $db->close();
    copy($schema_index_drop_base, $schema_index_drop_source);
    copy($schema_index_drop_base, $schema_index_drop_target);

    $db = open_db($schema_index_drop_source);
    $db->exec('DROP INDEX plugin_items_drop_idx');
    $db->close();

    $result = cow_merge_databases($schema_index_drop_base, $schema_index_drop_source, $schema_index_drop_target, $metadata, 'feature-index-drop', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'source-dropped index remains a schema conflict');
    $schema_index_drop_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_items' AND column_name = 'plugin_items_drop_idx' AND conflict_type = 'schema-source-dropped-index' ORDER BY id DESC LIMIT 1");
    $schema_index_drop_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_index_drop_conflict_id,
        'source',
        true,
        'Apply source index drop.',
        'test'
    );
    assert_same($schema_index_drop_resolution['status'], 'applied', 'source index drop resolution records applied status');
    assert_same(scalar($schema_index_drop_target, "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'plugin_items_drop_idx'"), null, 'source index drop resolution removes target index');

    $schema_object_base = $tmp . '/schema-object-base.sqlite';
    $schema_object_source = $tmp . '/schema-object-source.sqlite';
    $schema_object_target = $tmp . '/schema-object-target.sqlite';
    create_base_db($schema_object_base);
    $db = open_db($schema_object_base);
    $db->exec('CREATE TABLE plugin_item_audit (item_id TEXT)');
    $db->close();
    copy($schema_object_base, $schema_object_source);
    copy($schema_object_base, $schema_object_target);

    $db = open_db($schema_object_source);
    $db->exec('CREATE VIEW plugin_items_source_view AS SELECT item_id, label FROM plugin_items');
    $db->exec('CREATE TRIGGER plugin_items_source_insert AFTER INSERT ON plugin_items BEGIN INSERT INTO plugin_item_audit (item_id) VALUES (NEW.item_id); END');
    $db->close();
    $db = open_db($schema_object_target);
    $db->exec('CREATE VIEW plugin_items_target_view AS SELECT item_id, value FROM plugin_items');
    $db->exec('CREATE TRIGGER plugin_items_target_insert AFTER INSERT ON plugin_items BEGIN INSERT INTO plugin_item_audit (item_id) VALUES (NEW.item_id || \':target\'); END');
    $db->close();

    $result = cow_merge_databases($schema_object_base, $schema_object_source, $schema_object_target, $metadata, 'feature-schema-object', 'main');
    assert_same($result['status'], 'completed', 'source-added views and triggers merge cleanly');
    assert_same((int)scalar($schema_object_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_items_source_view'"), 1, 'source-added view is created on target');
    assert_same((int)scalar($schema_object_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_items_source_insert'"), 1, 'source-added trigger is created on target');
    assert_same((int)scalar($schema_object_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_items_target_view'"), 1, 'target-added view is preserved');
    assert_same((int)scalar($schema_object_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_items_target_insert'"), 1, 'target-added trigger is preserved');
    $db = open_db($schema_object_target);
    $db->exec("INSERT INTO plugin_items (item_id, label, value) VALUES ('gamma', 'Gamma', 'view trigger')");
    $db->close();
    assert_same(scalar($schema_object_target, "SELECT label FROM plugin_items_source_view WHERE item_id = 'gamma'"), 'Gamma', 'source-added view remains queryable after merge');
    assert_same(scalar($schema_object_target, "SELECT item_id FROM plugin_item_audit WHERE item_id = 'gamma'"), 'gamma', 'source-added trigger fires after merge');
    assert_same(scalar($schema_object_target, "SELECT value FROM plugin_items_target_view WHERE item_id = 'gamma'"), 'view trigger', 'target-added view remains queryable after merge');
    assert_same(scalar($schema_object_target, "SELECT item_id FROM plugin_item_audit WHERE item_id = 'gamma:target'"), 'gamma:target', 'target-added trigger fires after merge');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE column_name IN ('plugin_items_source_view', 'plugin_items_source_insert') AND decision = 'source-applied'"), 2, 'source-added view and trigger decisions are auditable');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE column_name IN ('plugin_items_target_view', 'plugin_items_target_insert') AND decision = 'target-kept'"), 2, 'target-added view and trigger preservation decisions are auditable');

    $schema_view_rewrite_base = $tmp . '/schema-view-rewrite-base.sqlite';
    $schema_view_rewrite_source = $tmp . '/schema-view-rewrite-source.sqlite';
    $schema_view_rewrite_target = $tmp . '/schema-view-rewrite-target.sqlite';
    create_base_db($schema_view_rewrite_base);
    $db = open_db($schema_view_rewrite_base);
    $db->exec('CREATE VIEW plugin_items_review_view AS SELECT item_id, label FROM plugin_items');
    $db->close();
    copy($schema_view_rewrite_base, $schema_view_rewrite_source);
    copy($schema_view_rewrite_base, $schema_view_rewrite_target);

    $db = open_db($schema_view_rewrite_source);
    $db->exec('DROP VIEW plugin_items_review_view');
    $db->exec('CREATE VIEW plugin_items_review_view AS SELECT item_id, label, value FROM plugin_items');
    $db->close();

    $result = cow_merge_databases($schema_view_rewrite_base, $schema_view_rewrite_source, $schema_view_rewrite_target, $metadata, 'feature-view-rewrite', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'source-changed view remains a schema conflict');
    $schema_view_rewrite_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_items_review_view' AND conflict_type = 'schema-source-changed-view' ORDER BY id DESC LIMIT 1");
    $schema_view_rewrite_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_view_rewrite_conflict_id,
        'source',
        true,
        'Apply source view rewrite.',
        'test'
    );
    assert_same($schema_view_rewrite_resolution['status'], 'applied', 'source view rewrite resolution records applied status');
    assert_true(str_contains((string)scalar($schema_view_rewrite_target, "SELECT sql FROM sqlite_master WHERE type = 'view' AND name = 'plugin_items_review_view'"), 'value'), 'source view rewrite replaces target view definition');
    assert_same(scalar($schema_view_rewrite_target, "SELECT value FROM plugin_items_review_view WHERE item_id = 'alpha'"), 'base', 'rewritten source view remains queryable');

    $schema_view_dep_base = $tmp . '/schema-view-dep-base.sqlite';
    $schema_view_dep_source = $tmp . '/schema-view-dep-source.sqlite';
    $schema_view_dep_target = $tmp . '/schema-view-dep-target.sqlite';
    create_base_db($schema_view_dep_base);
    $db = open_db($schema_view_dep_base);
    $db->exec('CREATE VIEW plugin_items_dep_base AS SELECT item_id, label FROM plugin_items');
    $db->close();
    copy($schema_view_dep_base, $schema_view_dep_source);
    copy($schema_view_dep_base, $schema_view_dep_target);

    $db = open_db($schema_view_dep_source);
    $db->exec('DROP VIEW plugin_items_dep_base');
    $db->exec('CREATE VIEW plugin_items_dep_base AS SELECT item_id, label, value FROM plugin_items');
    $db->close();

    $db = open_db($schema_view_dep_target);
    $db->exec('CREATE TABLE plugin_items_dep_insert_audit (item_id TEXT, label TEXT)');
    $db->exec('CREATE TABLE plugin_items_dep_child_audit (label TEXT)');
    $db->exec('CREATE VIEW plugin_items_dep_child AS SELECT label FROM plugin_items_dep_base');
    $db->exec('CREATE VIEW plugin_items_dep_grandchild AS SELECT label FROM plugin_items_dep_child');
    $db->exec('CREATE TRIGGER plugin_items_dep_base_insert INSTEAD OF INSERT ON plugin_items_dep_base BEGIN INSERT INTO plugin_items_dep_insert_audit (item_id, label) VALUES (NEW.item_id, NEW.label); END');
    $db->exec('CREATE TRIGGER plugin_items_dep_child_insert INSTEAD OF INSERT ON plugin_items_dep_child BEGIN INSERT INTO plugin_items_dep_child_audit (label) VALUES (NEW.label); END');
    $db->close();

    $result = cow_merge_databases($schema_view_dep_base, $schema_view_dep_source, $schema_view_dep_target, $metadata, 'feature-view-dependency', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'source-changed view with target dependent view remains a schema conflict');
    $schema_view_dep_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_items_dep_base' AND conflict_type = 'schema-source-changed-view' ORDER BY id DESC LIMIT 1");
    $schema_view_dep_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_view_dep_conflict_id,
        'source',
        true,
        'Apply source view rewrite and preserve dependent target view.',
        'test'
    );
    assert_same($schema_view_dep_resolution['status'], 'applied', 'source view rewrite with dependent target view records applied status');
    assert_same((int)scalar($schema_view_dep_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_items_dep_child'"), 1, 'source view rewrite recreates dependent target view');
    assert_same((int)scalar($schema_view_dep_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_items_dep_grandchild'"), 1, 'source view rewrite recreates transitive dependent target view');
    assert_same((int)scalar($schema_view_dep_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_items_dep_base_insert'"), 1, 'source view rewrite recreates dependent target trigger');
    assert_same((int)scalar($schema_view_dep_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_items_dep_child_insert'"), 1, 'source view rewrite recreates trigger on transitive dependent target view');
    assert_same(scalar($schema_view_dep_target, "SELECT label FROM plugin_items_dep_child WHERE label = 'Alpha'"), 'Alpha', 'dependent target view remains queryable after source view rewrite');
    assert_same(scalar($schema_view_dep_target, "SELECT label FROM plugin_items_dep_grandchild WHERE label = 'Alpha'"), 'Alpha', 'transitive dependent target view remains queryable after source view rewrite');
    $db = open_db($schema_view_dep_target);
    $db->exec("INSERT INTO plugin_items_dep_base (item_id, label, value) VALUES ('from-view', 'From View', 'triggered')");
    $db->exec("INSERT INTO plugin_items_dep_child (label) VALUES ('From Child View')");
    $db->close();
    assert_same(scalar($schema_view_dep_target, "SELECT label FROM plugin_items_dep_insert_audit WHERE item_id = 'from-view'"), 'From View', 'dependent target trigger still fires after source view rewrite');
    assert_same(scalar($schema_view_dep_target, "SELECT label FROM plugin_items_dep_child_audit WHERE label = 'From Child View'"), 'From Child View', 'trigger on transitive dependent target view still fires after source view rewrite');

    $schema_view_drop_dep_base = $tmp . '/schema-view-drop-dep-base.sqlite';
    $schema_view_drop_dep_source = $tmp . '/schema-view-drop-dep-source.sqlite';
    $schema_view_drop_dep_target = $tmp . '/schema-view-drop-dep-target.sqlite';
    create_base_db($schema_view_drop_dep_base);
    $db = open_db($schema_view_drop_dep_base);
    $db->exec('CREATE VIEW plugin_items_drop_base AS SELECT item_id, label FROM plugin_items');
    $db->close();
    copy($schema_view_drop_dep_base, $schema_view_drop_dep_source);
    copy($schema_view_drop_dep_base, $schema_view_drop_dep_target);

    $db = open_db($schema_view_drop_dep_source);
    $db->exec('DROP VIEW plugin_items_drop_base');
    $db->close();

    $db = open_db($schema_view_drop_dep_target);
    $db->exec('CREATE VIEW plugin_items_drop_child AS SELECT label FROM plugin_items_drop_base');
    $db->close();

    $result = cow_merge_databases($schema_view_drop_dep_base, $schema_view_drop_dep_source, $schema_view_drop_dep_target, $metadata, 'feature-view-drop-dependency', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'source-dropped view with target dependent view remains a schema conflict');
    $schema_view_drop_dep_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_items_drop_base' AND conflict_type = 'schema-source-dropped-view' ORDER BY id DESC LIMIT 1");
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $schema_view_drop_dep_conflict_id, 'source', true, 'Try source view drop with dependent view.', 'test'),
        'dependent target views',
        'source view drop resolution refuses to leave dependent target views invalid'
    );
    assert_same((int)scalar($schema_view_drop_dep_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_items_drop_base'"), 1, 'blocked source view drop preserves target view');
    assert_same((int)scalar($schema_view_drop_dep_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_items_drop_child'"), 1, 'blocked source view drop preserves dependent target view');

    $schema_trigger_drop_base = $tmp . '/schema-trigger-drop-base.sqlite';
    $schema_trigger_drop_source = $tmp . '/schema-trigger-drop-source.sqlite';
    $schema_trigger_drop_target = $tmp . '/schema-trigger-drop-target.sqlite';
    create_base_db($schema_trigger_drop_base);
    $db = open_db($schema_trigger_drop_base);
    $db->exec('CREATE TABLE plugin_trigger_audit (item_id TEXT)');
    $db->exec('CREATE TRIGGER plugin_items_drop_trigger AFTER INSERT ON plugin_items BEGIN INSERT INTO plugin_trigger_audit (item_id) VALUES (NEW.item_id); END');
    $db->close();
    copy($schema_trigger_drop_base, $schema_trigger_drop_source);
    copy($schema_trigger_drop_base, $schema_trigger_drop_target);

    $db = open_db($schema_trigger_drop_source);
    $db->exec('DROP TRIGGER plugin_items_drop_trigger');
    $db->close();

    $result = cow_merge_databases($schema_trigger_drop_base, $schema_trigger_drop_source, $schema_trigger_drop_target, $metadata, 'feature-trigger-drop', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'source-dropped trigger remains a schema conflict');
    $schema_trigger_drop_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_items_drop_trigger' AND conflict_type = 'schema-source-dropped-trigger' ORDER BY id DESC LIMIT 1");
    $schema_trigger_drop_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_trigger_drop_conflict_id,
        'source',
        true,
        'Apply source trigger drop.',
        'test'
    );
    assert_same($schema_trigger_drop_resolution['status'], 'applied', 'source trigger drop resolution records applied status');
    assert_same(scalar($schema_trigger_drop_target, "SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_items_drop_trigger'"), null, 'source trigger drop resolution removes target trigger');
    $db = open_db($schema_trigger_drop_target);
    $db->exec("INSERT INTO plugin_items (item_id, label, value) VALUES ('delta', 'Delta', 'dropped trigger')");
    $db->close();
    assert_same((int)scalar($schema_trigger_drop_target, "SELECT COUNT(*) FROM plugin_trigger_audit WHERE item_id = 'delta'"), 0, 'dropped trigger no longer fires after source resolution');

    $schema_table_drop_base = $tmp . '/schema-table-drop-base.sqlite';
    $schema_table_drop_source = $tmp . '/schema-table-drop-source.sqlite';
    $schema_table_drop_target = $tmp . '/schema-table-drop-target.sqlite';
    create_base_db($schema_table_drop_base);
    $db = open_db($schema_table_drop_base);
    $db->exec('CREATE TABLE plugin_table_drop (item_id TEXT PRIMARY KEY, label TEXT)');
    $db->exec("INSERT INTO plugin_table_drop (item_id, label) VALUES ('alpha', 'Alpha')");
    $db->close();
    copy($schema_table_drop_base, $schema_table_drop_source);
    copy($schema_table_drop_base, $schema_table_drop_target);

    $db = open_db($schema_table_drop_source);
    $db->exec('DROP TABLE plugin_table_drop');
    $db->close();

    $result = cow_merge_databases($schema_table_drop_base, $schema_table_drop_source, $schema_table_drop_target, $metadata, 'feature-table-drop', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'source-dropped table is recorded as a schema conflict');
    assert_same((int)scalar($schema_table_drop_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_table_drop'"), 1, 'target table wins by default when source drops a table');
    $schema_table_drop_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_table_drop' AND column_name IS NULL AND conflict_type = 'schema-source-dropped-table' ORDER BY id DESC LIMIT 1");
    assert_true($schema_table_drop_conflict_id > 0, 'source-dropped table conflict is auditable');
    $schema_table_drop_dry = cow_merge_resolve_conflict(
        $metadata,
        $schema_table_drop_conflict_id,
        'source',
        false,
        'Preview source table drop.',
        'test'
    );
    assert_same($schema_table_drop_dry['status'], 'validated', 'dry-run source table drop resolution validates current schemas');
    assert_same((int)scalar($schema_table_drop_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_table_drop'"), 1, 'dry-run source table drop does not mutate target schema');
    $schema_table_drop_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_table_drop_conflict_id,
        'source',
        true,
        'Apply source table drop.',
        'test'
    );
    assert_same($schema_table_drop_resolution['status'], 'applied', 'source table drop schema resolution records applied status');
    assert_same((int)scalar($schema_table_drop_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_table_drop'"), 0, 'source table drop resolution removes target table after validation');

    $schema_table_both_drop_base = $tmp . '/schema-table-both-drop-base.sqlite';
    $schema_table_both_drop_source = $tmp . '/schema-table-both-drop-source.sqlite';
    $schema_table_both_drop_target = $tmp . '/schema-table-both-drop-target.sqlite';
    create_base_db($schema_table_both_drop_base);
    $db = open_db($schema_table_both_drop_base);
    $db->exec('CREATE TABLE plugin_table_both_drop (item_id TEXT PRIMARY KEY, label TEXT)');
    $db->close();
    copy($schema_table_both_drop_base, $schema_table_both_drop_source);
    copy($schema_table_both_drop_base, $schema_table_both_drop_target);
    $db = open_db($schema_table_both_drop_source);
    $db->exec('DROP TABLE plugin_table_both_drop');
    $db->close();
    $db = open_db($schema_table_both_drop_target);
    $db->exec('DROP TABLE plugin_table_both_drop');
    $db->close();
    $result = cow_merge_databases($schema_table_both_drop_base, $schema_table_both_drop_source, $schema_table_both_drop_target, $metadata, 'feature-table-both-drop', 'main');
    assert_same($result['status'], 'completed', 'matching source and target table drops do not create conflicts');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_table_both_drop'"), 0, 'matching table drops do not create audit noise');

    $schema_table_target_drop_base = $tmp . '/schema-table-target-drop-base.sqlite';
    $schema_table_target_drop_source = $tmp . '/schema-table-target-drop-source.sqlite';
    $schema_table_target_drop_target = $tmp . '/schema-table-target-drop-target.sqlite';
    create_base_db($schema_table_target_drop_base);
    $db = open_db($schema_table_target_drop_base);
    $db->exec('CREATE TABLE plugin_table_target_drop (item_id TEXT PRIMARY KEY, label TEXT)');
    $db->exec('CREATE INDEX plugin_table_target_drop_label_idx ON plugin_table_target_drop(label)');
    $db->exec('CREATE TABLE plugin_table_target_drop_audit (item_id TEXT, label TEXT)');
    $db->exec("CREATE TRIGGER plugin_table_target_drop_insert AFTER INSERT ON plugin_table_target_drop BEGIN INSERT INTO plugin_table_target_drop_audit (item_id, label) VALUES (NEW.item_id, NEW.label); END");
    $db->exec("INSERT INTO plugin_table_target_drop (item_id, label) VALUES ('alpha', 'Alpha')");
    $db->close();
    copy($schema_table_target_drop_base, $schema_table_target_drop_source);
    copy($schema_table_target_drop_base, $schema_table_target_drop_target);
    $db = open_db($schema_table_target_drop_source);
    $db->exec("INSERT INTO plugin_table_target_drop (item_id, label) VALUES ('beta', 'Beta')");
    $db->close();
    $db = open_db($schema_table_target_drop_target);
    $db->exec('DROP TABLE plugin_table_target_drop');
    $db->close();

    $result = cow_merge_databases($schema_table_target_drop_base, $schema_table_target_drop_source, $schema_table_target_drop_target, $metadata, 'feature-table-target-drop', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'target-dropped table is recorded as a schema conflict');
    assert_same((int)scalar($schema_table_target_drop_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_table_target_drop'"), 0, 'target table drop wins by default when source keeps a table');
    $schema_table_target_drop_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_table_target_drop' AND column_name IS NULL AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    assert_true($schema_table_target_drop_conflict_id > 0, 'target-dropped table conflict is auditable');
    $schema_table_target_drop_dry = cow_merge_resolve_conflict(
        $metadata,
        $schema_table_target_drop_conflict_id,
        'source',
        false,
        'Preview source table restore.',
        'test'
    );
    assert_same($schema_table_target_drop_dry['status'], 'validated', 'dry-run source table restore validates current schemas');
    assert_same((int)scalar($schema_table_target_drop_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_table_target_drop'"), 0, 'dry-run source table restore does not mutate target schema');
    $schema_table_target_drop_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_table_target_drop_conflict_id,
        'source',
        true,
        'Apply source table restore.',
        'test'
    );
    assert_same($schema_table_target_drop_resolution['status'], 'applied', 'source table restore schema resolution records applied status');
    assert_same((int)scalar($schema_table_target_drop_target, "SELECT COUNT(*) FROM plugin_table_target_drop"), 2, 'source table restore copies audited source rows into the target table');
    assert_same(scalar($schema_table_target_drop_target, "SELECT label FROM plugin_table_target_drop WHERE item_id = 'beta'"), 'Beta', 'source table restore includes source-only rows');
    assert_same((int)scalar($schema_table_target_drop_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_table_target_drop_label_idx'"), 1, 'source table restore recreates source table index removed by the target table drop');
    assert_same((int)scalar($schema_table_target_drop_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_table_target_drop_insert'"), 1, 'source table restore recreates source table trigger removed by the target table drop');
    $db = open_db($schema_table_target_drop_target);
    $db->exec("INSERT INTO plugin_table_target_drop (item_id, label) VALUES ('gamma', 'Gamma')");
    $db->close();
    assert_same(scalar($schema_table_target_drop_target, "SELECT label FROM plugin_table_target_drop_audit WHERE item_id = 'gamma'"), 'Gamma', 'recreated source table trigger fires after target-dropped table restore');
    $schema_table_target_drop_payload = cow_merge_decode_payload_json(
        (string)scalar($metadata, "SELECT resolved_payload FROM merge_resolutions WHERE conflict_id = $schema_table_target_drop_conflict_id ORDER BY id DESC LIMIT 1"),
        'target-dropped table resolution'
    );
    assert_same($schema_table_target_drop_payload['indexes'][0]['name'] ?? null, 'plugin_table_target_drop_label_idx', 'source table restore resolution records restored source index SQL');
    assert_same($schema_table_target_drop_payload['triggers'][0]['name'] ?? null, 'plugin_table_target_drop_insert', 'source table restore resolution records restored source trigger SQL');

    $schema_table_drop_view_base = $tmp . '/schema-table-drop-view-base.sqlite';
    $schema_table_drop_view_source = $tmp . '/schema-table-drop-view-source.sqlite';
    $schema_table_drop_view_target = $tmp . '/schema-table-drop-view-target.sqlite';
    create_base_db($schema_table_drop_view_base);
    $db = open_db($schema_table_drop_view_base);
    $db->exec('CREATE TABLE plugin_table_drop_view (item_id TEXT PRIMARY KEY, label TEXT)');
    $db->close();
    copy($schema_table_drop_view_base, $schema_table_drop_view_source);
    copy($schema_table_drop_view_base, $schema_table_drop_view_target);

    $db = open_db($schema_table_drop_view_source);
    $db->exec('DROP TABLE plugin_table_drop_view');
    $db->close();
    $db = open_db($schema_table_drop_view_target);
    $db->exec('CREATE VIEW plugin_table_drop_view_live AS SELECT item_id, label FROM plugin_table_drop_view');
    $db->close();

    $result = cow_merge_databases($schema_table_drop_view_base, $schema_table_drop_view_source, $schema_table_drop_view_target, $metadata, 'feature-table-drop-view', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'source table drop with a target dependent view remains reviewable');
    $schema_table_drop_view_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_table_drop_view' AND column_name IS NULL AND conflict_type = 'schema-source-dropped-table' ORDER BY id DESC LIMIT 1");
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $schema_table_drop_view_conflict_id, 'source', true, 'Try source table drop with dependent view.', 'test'),
        'dependent target views',
        'source table drop resolution refuses to leave target views invalid'
    );

    $schema_table_drop_deps_base = $tmp . '/schema-table-drop-deps-base.sqlite';
    $schema_table_drop_deps_source = $tmp . '/schema-table-drop-deps-source.sqlite';
    $schema_table_drop_deps_target = $tmp . '/schema-table-drop-deps-target.sqlite';
    create_base_db($schema_table_drop_deps_base);
    $db = open_db($schema_table_drop_deps_base);
    $db->exec('CREATE TABLE plugin_table_drop_deps (item_id TEXT PRIMARY KEY, label TEXT)');
    $db->exec('CREATE TABLE plugin_table_drop_deps_audit (item_id TEXT)');
    $db->exec('CREATE INDEX plugin_table_drop_deps_label_idx ON plugin_table_drop_deps(label)');
    $db->exec('CREATE TRIGGER plugin_table_drop_deps_insert AFTER INSERT ON plugin_table_drop_deps BEGIN INSERT INTO plugin_table_drop_deps_audit (item_id) VALUES (NEW.item_id); END');
    $db->close();
    copy($schema_table_drop_deps_base, $schema_table_drop_deps_source);
    copy($schema_table_drop_deps_base, $schema_table_drop_deps_target);

    $db = open_db($schema_table_drop_deps_source);
    $db->exec('DROP TABLE plugin_table_drop_deps');
    $db->close();

    $result = cow_merge_databases($schema_table_drop_deps_base, $schema_table_drop_deps_source, $schema_table_drop_deps_target, $metadata, 'feature-table-drop-deps', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'source table drop with dependent target index and trigger remains reviewable');
    $schema_table_drop_deps_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_table_drop_deps' AND column_name IS NULL AND conflict_type = 'schema-source-dropped-table' ORDER BY id DESC LIMIT 1");
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $schema_table_drop_deps_conflict_id, 'source', true, 'Try source table drop with dependent schema.', 'test'),
        'dependent target schema objects',
        'source table drop resolution refuses to implicitly remove target indexes or triggers'
    );
    $schema_table_drop_deps_index_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_table_drop_deps_label_idx' AND conflict_type = 'schema-source-dropped-index' ORDER BY id DESC LIMIT 1");
    cow_merge_resolve_conflict($metadata, $schema_table_drop_deps_index_conflict_id, 'source', true, 'Apply source dependent index drop.', 'test');
    $schema_table_drop_deps_trigger_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_table_drop_deps_insert' AND conflict_type = 'schema-source-dropped-trigger' ORDER BY id DESC LIMIT 1");
    cow_merge_resolve_conflict($metadata, $schema_table_drop_deps_trigger_conflict_id, 'source', true, 'Apply source dependent trigger drop.', 'test');
    $schema_table_drop_deps_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_table_drop_deps_conflict_id,
        'source',
        true,
        'Apply source table drop after dependent schema resolution.',
        'test'
    );
    assert_same($schema_table_drop_deps_resolution['status'], 'applied', 'source table drop resolution can apply after dependent schema objects are resolved');
    assert_same((int)scalar($schema_table_drop_deps_target, "SELECT COUNT(*) FROM sqlite_master WHERE name = 'plugin_table_drop_deps'"), 0, 'source table drop removes table after dependent index and trigger are resolved');
    assert_same((int)scalar($schema_table_drop_view_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_table_drop_view'"), 1, 'blocked source table drop preserves target table');
    assert_same((int)scalar($schema_table_drop_view_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_table_drop_view_live'"), 1, 'blocked source table drop preserves dependent target view');

    $keyless_base = $tmp . '/keyless-base.sqlite';
    $keyless_source = $tmp . '/keyless-source.sqlite';
    $keyless_target = $tmp . '/keyless-target.sqlite';
    create_base_db($keyless_base);
    copy($keyless_base, $keyless_source);
    copy($keyless_base, $keyless_target);

    $db = open_db($keyless_source);
    $db->exec("UPDATE plugin_keyless SET value = 'source base value' WHERE rowid = 1");
    $db->exec("INSERT INTO plugin_keyless (label, value) VALUES ('Source keyless', 'source insert')");
    $db->close();

    $db = open_db($keyless_target);
    $db->exec("UPDATE plugin_keyless SET label = 'Target base label' WHERE rowid = 1");
    $db->exec("INSERT INTO plugin_keyless (label, value) VALUES ('Target keyless', 'target insert')");
    $db->close();

    $result = cow_merge_databases($keyless_base, $keyless_source, $keyless_target, $metadata, 'feature-keyless', 'main');
    assert_same($result['status'], 'completed', 'keyless plugin table independent changes merge cleanly');
    assert_same(scalar($keyless_target, "SELECT label FROM plugin_keyless WHERE rowid = 1"), 'Target base label', 'target keyless base-row cell is preserved');
    assert_same(scalar($keyless_target, "SELECT value FROM plugin_keyless WHERE rowid = 1"), 'source base value', 'source keyless base-row cell is applied');
    assert_same((int)scalar($keyless_target, "SELECT COUNT(*) FROM plugin_keyless WHERE label IN ('Source keyless', 'Target keyless')"), 2, 'source and target keyless inserts are both present');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_keyless' AND conflict_type = 'row-insert-collision'"), 0, 'keyless insert rowid collisions are not recorded as same-row conflicts');
    assert_true((int)scalar($metadata, "SELECT COUNT(*) FROM merge_row_identities WHERE table_name = 'plugin_keyless'") >= 3, 'keyless sidecar row identities are recorded outside the plugin table');

    cow_merge_databases($keyless_base, $keyless_source, $keyless_target, $metadata, 'feature-keyless', 'main');
    assert_same((int)scalar($keyless_target, "SELECT COUNT(*) FROM plugin_keyless WHERE label = 'Source keyless'"), 1, 'rerunning keyless merge does not duplicate the source insert');

    $keyless_conflict_base = $tmp . '/keyless-conflict-base.sqlite';
    $keyless_conflict_source = $tmp . '/keyless-conflict-source.sqlite';
    $keyless_conflict_target = $tmp . '/keyless-conflict-target.sqlite';
    create_base_db($keyless_conflict_base);
    copy($keyless_conflict_base, $keyless_conflict_source);
    copy($keyless_conflict_base, $keyless_conflict_target);

    $db = open_db($keyless_conflict_source);
    $db->exec("UPDATE plugin_keyless SET value = 'source keyless conflict' WHERE rowid = 1");
    $db->close();

    $db = open_db($keyless_conflict_target);
    $db->exec("UPDATE plugin_keyless SET value = 'target keyless conflict' WHERE rowid = 1");
    $db->close();

    $result = cow_merge_databases($keyless_conflict_base, $keyless_conflict_source, $keyless_conflict_target, $metadata, 'feature-keyless-conflict', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'keyless same-cell conflict is recorded');
    $keyless_cell_conflict_id = (int)scalar($metadata, "SELECT c.id FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_keyless' AND c.column_name = 'value' AND r.source_branch = 'feature-keyless-conflict' ORDER BY c.id DESC LIMIT 1");
    $keyless_dry_resolution = cow_merge_resolve_conflict(
        $metadata,
        $keyless_cell_conflict_id,
        'source',
        false,
        'Preview keyless source cell.',
        'cow-test'
    );
    assert_same($keyless_dry_resolution['status'], 'validated', 'dry-run keyless cell resolution validates sidecar target identity');
    assert_same(scalar($keyless_conflict_target, "SELECT value FROM plugin_keyless WHERE rowid = 1"), 'target keyless conflict', 'dry-run keyless cell resolution does not mutate target');
    $keyless_source_resolution = cow_merge_resolve_conflict(
        $metadata,
        $keyless_cell_conflict_id,
        'source',
        true,
        'Apply keyless source cell.',
        'cow-test'
    );
    assert_same($keyless_source_resolution['status'], 'applied', 'source keyless cell resolution records applied status');
    assert_same(scalar($keyless_conflict_target, "SELECT value FROM plugin_keyless WHERE rowid = 1"), 'source keyless conflict', 'source keyless cell resolution updates target through sidecar identity');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $keyless_cell_conflict_id AND table_name = 'plugin_keyless' AND column_name = 'value' AND choice = 'source' AND applied = 1"), 1, 'keyless cell resolution is auditable');

    $capture_db = $tmp . '/capture.sqlite';
    $capture_feature = $tmp . '/capture-feature.sqlite';
    $capture_metadata = $tmp . '/.forkpress/cow/merge/capture-metadata.sqlite';
    create_base_db($capture_db);

    $result = cow_merge_capture_row_identities($capture_db, $capture_metadata, 'main');
    assert_same($result['status'], 'identity_captured', 'branch-time row identity capture reports a completed capture');
    assert_same($result['tables'], 1, 'branch-time capture scans no-PK plugin tables');
    assert_same($result['rows'], 1, 'branch-time capture records existing no-PK rows');
    assert_same((int)scalar($capture_metadata, "SELECT COUNT(*) FROM merge_runs WHERE source_branch = 'main' AND target_branch = 'main' AND policy = 'sidecar-row-identity-capture' AND status = 'identity_captured'"), 1, 'identity capture is auditable in merge metadata');
    assert_same((int)scalar($capture_metadata, "SELECT COUNT(*) FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_keyless'"), 1, 'identity capture records sidecar rows outside the app database');
    assert_same((int)scalar($capture_db, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'merge_row_identities'"), 0, 'identity capture does not create ForkPress tables inside the app database');

    copy($capture_db, $capture_feature);
    $main_identity = scalar($capture_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_keyless' AND rowid = 1");
    $result = cow_merge_capture_row_identities($capture_feature, $capture_metadata, 'feature-captured', 'main');
    assert_same($result['created'], 0, 'capturing a cloned branch can seed existing row identities from the source branch');
    assert_same(scalar($capture_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'feature-captured' AND table_name = 'plugin_keyless' AND rowid = 1"), $main_identity, 'seeded branch rows keep the source logical identity');

    $db = open_db($capture_feature);
    $db->exec("INSERT INTO plugin_keyless (label, value) VALUES ('Captured later', 'new row')");
    $db->close();
    $result = cow_merge_capture_row_identities($capture_feature, $capture_metadata, 'feature-captured', 'main');
    assert_same($result['created'], 1, 'recapturing a branch creates identities only for new no-PK rows');
    assert_same((int)scalar($capture_metadata, "SELECT COUNT(*) FROM merge_row_identities WHERE branch_name = 'feature-captured' AND table_name = 'plugin_keyless'"), 2, 'recapturing a branch preserves existing identities and adds new rows');

    $reuse_base = $tmp . '/reuse-base.sqlite';
    $reuse_source = $tmp . '/reuse-source.sqlite';
    $reuse_target = $tmp . '/reuse-target.sqlite';
    $reuse_metadata = $tmp . '/.forkpress/cow/merge/reuse-metadata.sqlite';
    create_base_db($reuse_base);
    copy($reuse_base, $reuse_source);
    copy($reuse_base, $reuse_target);
    cow_merge_capture_row_identities($reuse_base, $reuse_metadata, 'main');
    cow_merge_capture_row_identities($reuse_source, $reuse_metadata, 'feature-reuse', 'main');

    $old_reuse_identity = scalar($reuse_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'feature-reuse' AND table_name = 'plugin_keyless' AND rowid = 1");
    $db = open_db($reuse_source);
    $db->exec('DELETE FROM plugin_keyless WHERE rowid = 1');
    $db->exec("INSERT INTO plugin_keyless (label, value) VALUES ('Reused rowid source', 'new logical row')");
    $db->close();
    assert_same((int)scalar($reuse_source, 'SELECT rowid FROM plugin_keyless'), 1, 'SQLite reuses the keyless rowid after deleting the max rowid');

    $result = cow_merge_track_row_identity_events(
        $reuse_source,
        $reuse_metadata,
        'feature-reuse',
        [
            ['id' => 1, 'table_name' => 'plugin_keyless', 'op' => 'delete', 'rowid' => 1, 'row' => ['label' => 'Base keyless', 'value' => 'base']],
            ['id' => 2, 'table_name' => 'plugin_keyless', 'op' => 'insert', 'rowid' => 1, 'row' => ['label' => 'Reused rowid source', 'value' => 'new logical row']],
        ]
    );
    assert_same($result['status'], 'identity_tracked', 'runtime row identity events are auditable');
    $new_reuse_identity = scalar($reuse_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'feature-reuse' AND table_name = 'plugin_keyless' AND rowid = 1");
    assert_true(is_string($old_reuse_identity) && is_string($new_reuse_identity) && $old_reuse_identity !== $new_reuse_identity, 'rowid reuse receives a new logical identity');
    assert_same((int)scalar($reuse_metadata, "SELECT COUNT(*) FROM merge_row_identity_history WHERE branch_name = 'feature-reuse' AND table_name = 'plugin_keyless' AND rowid = 1"), 2, 'row identity history keeps both generations for a reused rowid');
    assert_same((int)scalar($reuse_metadata, "SELECT COUNT(*) FROM merge_row_identity_history WHERE branch_name = 'feature-reuse' AND table_name = 'plugin_keyless' AND rowid = 1 AND deleted_at IS NOT NULL"), 1, 'deleted no-PK row identity generation is tombstoned');
    assert_same(
        scalar($reuse_metadata, "SELECT row_hash FROM merge_row_identity_history WHERE branch_name = 'feature-reuse' AND table_name = 'plugin_keyless' AND rowid = 1 AND deleted_at IS NOT NULL"),
        cow_merge_row_hash(['label' => 'Base keyless', 'value' => 'base']),
        'runtime delete event preserves the deleted no-PK row snapshot instead of hashing the later rowid reuse'
    );

    $db = open_db($reuse_target);
    $db->exec("UPDATE plugin_keyless SET value = 'target kept old row' WHERE rowid = 1");
    $db->close();
    $result = cow_merge_databases($reuse_base, $reuse_source, $reuse_target, $reuse_metadata, 'feature-reuse', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'rowid reuse does not masquerade as an update to a target-changed no-PK row');
    assert_same((int)scalar($reuse_target, "SELECT COUNT(*) FROM plugin_keyless WHERE label = 'Base keyless' AND value = 'target kept old row'"), 1, 'target-changed old no-PK row is preserved');
    assert_same((int)scalar($reuse_target, "SELECT COUNT(*) FROM plugin_keyless WHERE label = 'Reused rowid source' AND value = 'new logical row'"), 1, 'source rowid reuse is inserted as a new logical row');
    assert_same((int)scalar($reuse_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_keyless' AND conflict_type = 'row-source-deleted'"), 1, 'old no-PK row deletion conflict is recorded separately from the new insert');
    assert_same((int)scalar($reuse_metadata, "SELECT COUNT(*) FROM merge_row_identity_history WHERE branch_name = 'feature-reuse' AND table_name = 'plugin_keyless' AND rowid = 1 AND deleted_at IS NOT NULL"), 1, 'merge-base identity lookup does not reactivate deleted no-PK generations');
    $keyless_delete_conflict_id = (int)scalar($reuse_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_keyless' AND conflict_type = 'row-source-deleted' ORDER BY id DESC LIMIT 1");
    $keyless_delete_resolution = cow_merge_resolve_conflict(
        $reuse_metadata,
        $keyless_delete_conflict_id,
        'source',
        true,
        'Apply source keyless deletion.',
        'cow-test'
    );
    assert_same($keyless_delete_resolution['status'], 'applied', 'source keyless delete resolution records applied status');
    assert_same((int)scalar($reuse_target, "SELECT COUNT(*) FROM plugin_keyless WHERE label = 'Base keyless'"), 0, 'source keyless delete resolution removes the target old row by logical identity');
    assert_same((int)scalar($reuse_target, "SELECT COUNT(*) FROM plugin_keyless WHERE label = 'Reused rowid source' AND value = 'new logical row'"), 1, 'source keyless delete resolution leaves the reused logical row intact');
    assert_same((int)scalar($reuse_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $keyless_delete_conflict_id AND table_name = 'plugin_keyless' AND choice = 'source' AND applied = 1"), 1, 'keyless delete resolution is auditable');

    if (!function_exists('add_action')) {
        function add_action($tag, $callback, $priority = 10, $accepted_args = 1) {
            return true;
        }
    }
    if (!function_exists('remove_action')) {
        function remove_action($tag, $callback, $priority = 10) {
            return true;
        }
    }
    if (!function_exists('add_filter')) {
        function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {
            $GLOBALS['forkpress_test_filters'][$tag][] = $callback;
            return true;
        }
    }
    if (!class_exists('ForkPressTestSqliteWpdbConnection')) {
        class ForkPressTestSqliteWpdbConnection {
            public function __construct(private PDO $pdo) {}
            public function get_pdo(): PDO {
                return $this->pdo;
            }
        }
    }
    if (!class_exists('ForkPressTestSqliteWpdbDbh')) {
        class ForkPressTestSqliteWpdbDbh {
            public function __construct(private ForkPressTestSqliteWpdbConnection $connection) {}
            public function get_connection(): ForkPressTestSqliteWpdbConnection {
                return $this->connection;
            }
        }
    }
    if (!class_exists('ForkPressTestSqliteWpdb')) {
        class ForkPressTestSqliteWpdb {
            public ForkPressTestSqliteWpdbDbh $dbh;
            public function __construct(public PDO $pdo) {
                $this->dbh = new ForkPressTestSqliteWpdbDbh(new ForkPressTestSqliteWpdbConnection($pdo));
            }
            public function query(string $sql): int|false {
                foreach (($GLOBALS['forkpress_test_filters']['query'] ?? []) as $callback) {
                    if (is_callable($callback)) {
                        $sql = (string) $callback($sql);
                    }
                }
                return $this->pdo->exec($sql);
            }
        }
    }

    $runtime_ddl_db = $tmp . '/runtime-ddl.sqlite';
    $runtime_ddl_metadata = $tmp . '/.forkpress/cow/merge/runtime-ddl-metadata.sqlite';
    $runtime_ddl_pdo = new PDO('sqlite:' . $runtime_ddl_db);
    $runtime_ddl_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $GLOBALS['wpdb'] = new ForkPressTestSqliteWpdb($runtime_ddl_pdo);
    $GLOBALS['forkpress_test_filters'] = [];
    putenv('FORKPRESS_BRANCH=runtime-ddl');
    putenv('FORKPRESS_COW_MERGE_METADATA_DB=' . $runtime_ddl_metadata);
    putenv('FORKPRESS_COW_MERGE_HELPER=' . realpath(__DIR__ . '/../../scripts/cow/merge.php'));
    if (!defined('ABSPATH')) {
        define('ABSPATH', $tmp . '/wp/');
    }
    if (!defined('FQDB')) {
        define('FQDB', $runtime_ddl_db);
    }

    require_once __DIR__ . '/../../wp-plugin/forkpress-wp.php';
    $GLOBALS['wpdb']->query('CREATE TABLE plugin_runtime_keyless (label TEXT, value TEXT)');
    $GLOBALS['wpdb']->query("INSERT INTO plugin_runtime_keyless (label, value) VALUES ('First runtime row', 'base')");
    $GLOBALS['wpdb']->query('DELETE FROM plugin_runtime_keyless WHERE rowid = 1');
    $GLOBALS['wpdb']->query("INSERT INTO plugin_runtime_keyless (label, value) VALUES ('Second runtime row', 'reused')");
    $runtime_event_count = (int) $runtime_ddl_pdo->query('SELECT COUNT(*) FROM temp.forkpress_row_identity_events')->fetchColumn();
    assert_same($runtime_event_count, 3, 'runtime row identity triggers refresh after CREATE TABLE in the same request');
    assert_same(
        (string) $runtime_ddl_pdo->query("SELECT row_payload FROM temp.forkpress_row_identity_events WHERE op = 'delete' ORDER BY id LIMIT 1")->fetchColumn(),
        '{"label":{"type":"text","value":"First runtime row"},"value":{"type":"text","value":"base"}}',
        'runtime delete trigger records the deleted no-PK row snapshot'
    );
    forkpress_cow_flush_row_identity_events();
    assert_same((int)scalar($runtime_ddl_metadata, "SELECT COUNT(*) FROM merge_runs WHERE source_branch = 'runtime-ddl' AND policy = 'runtime-row-identity-tracking' AND status = 'identity_tracked'"), 1, 'runtime DDL refresh flush is auditable');
    assert_same((int)scalar($runtime_ddl_metadata, "SELECT COUNT(*) FROM merge_row_identity_history WHERE branch_name = 'runtime-ddl' AND table_name = 'plugin_runtime_keyless' AND rowid = 1"), 2, 'runtime DDL rowid reuse keeps separate logical generations');
    assert_same((int)scalar($runtime_ddl_metadata, "SELECT COUNT(*) FROM merge_row_identity_history WHERE branch_name = 'runtime-ddl' AND table_name = 'plugin_runtime_keyless' AND rowid = 1 AND deleted_at IS NOT NULL"), 1, 'runtime DDL deleted generation is tombstoned');
    assert_same(
        scalar($runtime_ddl_metadata, "SELECT row_hash FROM merge_row_identity_history WHERE branch_name = 'runtime-ddl' AND table_name = 'plugin_runtime_keyless' AND rowid = 1 AND deleted_at IS NOT NULL"),
        cow_merge_row_hash(['label' => 'First runtime row', 'value' => 'base']),
        'runtime DDL delete+reuse preserves the deleted row content in identity history'
    );

    $band_base = $tmp . '/band-base.sqlite';
    $band_feature_a = $tmp . '/band-feature-a.sqlite';
    $band_feature_b = $tmp . '/band-feature-b.sqlite';
    $band_feature_a_reset = $tmp . '/band-feature-a-reset.sqlite';
    $band_metadata = $tmp . '/.forkpress/cow/merge/band-metadata.sqlite';
    create_base_db($band_base);
    $db = open_db($band_base);
    $db->exec('CREATE TABLE plugin_autoinc (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT)');
    $db->exec('CREATE TABLE plugin_plain_ipk (id INTEGER PRIMARY KEY, label TEXT)');
    $db->exec("INSERT INTO plugin_autoinc (label) VALUES ('base plugin auto')");
    $db->exec("INSERT INTO plugin_plain_ipk (label) VALUES ('base plugin plain ipk')");
    $db->close();
    copy($band_base, $band_feature_a);
    copy($band_base, $band_feature_b);

    $result = cow_merge_allocate_autoincrement_bands($band_feature_a, $band_metadata, 'feature-band-a');
    assert_same($result['status'], 'id_bands_allocated', 'branch-time AUTOINCREMENT band allocation completes');
    assert_same($result['tables'], 3, 'AUTOINCREMENT band allocation scans core and plugin AUTOINCREMENT tables');
    assert_same($result['allocated'], 3, 'first branch allocation reserves new bands');
    assert_same($result['advanced'], 3, 'first branch allocation advances sqlite_sequence for each AUTOINCREMENT table');
    assert_same($result['skipped_plain_integer_pk'], 1, 'plain INTEGER PRIMARY KEY tables are measured but not mutated');
    assert_true((int)scalar($band_feature_a, "SELECT seq FROM sqlite_sequence WHERE name = 'wp_posts'") >= COW_MERGE_AUTOINCREMENT_FIRST_BAND_START - 1, 'post sequence is moved into a branch band');
    assert_same(scalar($band_feature_a, "SELECT seq FROM sqlite_sequence WHERE name = 'plugin_plain_ipk'"), null, 'plain INTEGER PRIMARY KEY tables do not get sqlite_sequence rows');
    assert_true((int)scalar($band_metadata, "SELECT COUNT(*) FROM merge_autoincrement_bands WHERE branch_name = 'feature-band-a'") === 3, 'allocated bands are auditable in merge metadata');
    assert_same((int)scalar($band_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_plain_ipk' AND decision = 'id-band-skipped'"), 1, 'plain INTEGER PRIMARY KEY skip decision is auditable');
    assert_same((int)scalar($band_feature_a, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'merge_autoincrement_bands'"), 0, 'AUTOINCREMENT metadata is not written into the app database');

    $db = open_db($band_feature_a);
    $db->exec("INSERT INTO wp_posts (post_title, post_content, post_status) VALUES ('Band A post', 'branch a', 'publish')");
    $db->exec("INSERT INTO plugin_autoinc (label) VALUES ('branch a plugin auto')");
    $db->close();
    $post_id_a = (int)scalar($band_feature_a, "SELECT MAX(ID) FROM wp_posts");
    $plugin_id_a = (int)scalar($band_feature_a, "SELECT MAX(id) FROM plugin_autoinc");
    assert_true($post_id_a >= COW_MERGE_AUTOINCREMENT_FIRST_BAND_START, 'branch post insert uses the allocated ID band');
    assert_true($plugin_id_a >= COW_MERGE_AUTOINCREMENT_FIRST_BAND_START, 'arbitrary plugin AUTOINCREMENT table uses the allocated ID band');

    $result = cow_merge_allocate_autoincrement_bands($band_feature_a, $band_metadata, 'feature-band-a');
    assert_same($result['allocated'], 0, 'rerunning allocation on the same branch DB reuses existing bands');
    assert_same($result['reused'], 3, 'rerunning allocation records existing bands as reused');

    $result = cow_merge_allocate_autoincrement_bands($band_feature_b, $band_metadata, 'feature-band-b');
    assert_same($result['allocated'], 3, 'second branch receives its own bands');
    $db = open_db($band_feature_b);
    $db->exec("INSERT INTO wp_posts (post_title, post_content, post_status) VALUES ('Band B post', 'branch b', 'publish')");
    $db->close();
    $post_id_b = (int)scalar($band_feature_b, "SELECT MAX(ID) FROM wp_posts");
    assert_true($post_id_b > $post_id_a, 'independent branches do not allocate colliding post IDs');

    copy($band_base, $band_feature_a_reset);
    $result = cow_merge_allocate_autoincrement_bands($band_feature_a_reset, $band_metadata, 'feature-band-a');
    assert_same($result['allocated'], 3, 'reset branch DB below its old band gets fresh bands instead of reusing possibly published IDs');
    assert_true((int)scalar($band_feature_a_reset, "SELECT seq FROM sqlite_sequence WHERE name = 'wp_posts'") > $post_id_b, 'fresh reset band is above previously allocated branch bands');
    assert_same((int)scalar($band_metadata, "SELECT COUNT(*) FROM merge_runs WHERE source_branch = 'feature-band-a' AND policy = 'autoincrement-id-band-allocation' AND status = 'id_bands_allocated'"), 3, 'AUTOINCREMENT allocation runs are auditable');
    $band_audit = cow_merge_audit_report($band_metadata, null, 10);
    assert_true(count($band_audit['autoincrement_bands']) >= 3, 'merge audit report exposes AUTOINCREMENT band allocations');
    assert_true(count($band_audit['decisions']) >= 3, 'merge audit report exposes ID-band decisions');
    $skip_audit = cow_merge_audit_report($band_metadata, null, 10, ['id_band_skips' => '1']);
    assert_same($skip_audit['filters']['scope'], 'db', 'ID-band skip shortcut defaults audit scope to DB records');
    assert_same($skip_audit['filters']['records'], 'decisions', 'ID-band skip shortcut selects decision records');
    assert_same($skip_audit['filters']['decision'], 'id-band-skipped', 'ID-band skip shortcut selects skipped band decisions');
    assert_same(count($skip_audit['conflicts']), 0, 'ID-band skip shortcut omits conflict records');
    assert_same(count($skip_audit['autoincrement_bands']), 0, 'ID-band skip shortcut omits band summary rows');
    assert_true(count($skip_audit['decisions']) >= 1, 'ID-band skip shortcut returns skipped plain-IPK decisions');
    assert_same(count(array_filter($skip_audit['decisions'], fn($row) => $row['decision'] === 'id-band-skipped')), count($skip_audit['decisions']), 'ID-band skip shortcut returns only skipped decisions');
    assert_true(count(array_filter($skip_audit['decisions'], fn($row) => $row['table_name'] === 'plugin_plain_ipk')) >= 1, 'ID-band skip shortcut identifies skipped plain-IPK tables');
    $band_review_audit = cow_merge_audit_report($band_metadata, null, 10, ['review' => '1']);
    assert_true(count($band_review_audit['decisions']) >= 1, 'review audit includes skipped plain-IPK band decisions');
    assert_same(count(array_filter($band_review_audit['decisions'], fn($row) => $row['decision'] === 'id-band-skipped')), count($band_review_audit['decisions']), 'review audit only includes reviewable decision records');
    ob_start();
    cow_merge_print_audit_text($skip_audit);
    $skip_audit_text = ob_get_clean();
    assert_true(str_contains($skip_audit_text, 'id-band-skips'), 'ID-band skip shortcut is visible in text audit filters');

    $target_kept_audit = cow_merge_audit_report($metadata, null, 10, ['target_kept' => '1']);
    assert_same($target_kept_audit['filters']['records'], 'decisions', 'target-kept shortcut selects decision records');
    assert_same($target_kept_audit['filters']['decision'], 'target-kept', 'target-kept shortcut selects preserved target decisions');
    assert_same(count($target_kept_audit['conflicts']), 0, 'target-kept shortcut omits conflict records');
    assert_same(count($target_kept_audit['autoincrement_bands']), 0, 'target-kept shortcut omits band summary rows');
    assert_same(count($target_kept_audit['row_identity_summary']), 0, 'target-kept shortcut omits row identity summary rows');
    assert_true(count($target_kept_audit['decisions']) >= 1, 'target-kept shortcut returns preserved target decisions');
    assert_same(count(array_filter($target_kept_audit['decisions'], fn($row) => $row['decision'] === 'target-kept')), count($target_kept_audit['decisions']), 'target-kept shortcut returns only target-kept decisions');
    $target_kept_default_records_audit = cow_merge_audit_report($metadata, null, 10, [
        'target_kept' => '1',
        'records' => 'all',
        'scope' => 'files',
        'path_prefix' => 'wp-content/uploads/target-only.txt',
    ]);
    assert_same($target_kept_default_records_audit['filters']['records'], 'decisions', 'target-kept shortcut normalizes default CLI records to decisions');
    assert_same(count($target_kept_default_records_audit['decisions']), 1, 'target-kept shortcut works with file path filters through CLI-style defaults');
    assert_same($target_kept_default_records_audit['decisions'][0]['decision'], 'target-kept', 'target-kept path filter returns a preserved target decision');
    $target_kept_group_audit = cow_merge_audit_report($metadata, null, 10, ['target_kept' => '1', 'group_by' => 'type']);
    assert_true(count(array_filter($target_kept_group_audit['decision_groups'], fn($row) => $row['group_key'] === 'target-kept')) >= 1, 'target-kept shortcut can group preserved target decisions');
    $target_kept_default_group_audit = cow_merge_audit_report($metadata, null, 10, ['target_kept' => '1', 'records' => 'all', 'group_by' => 'type']);
    assert_true(count(array_filter($target_kept_default_group_audit['decision_groups'], fn($row) => $row['group_key'] === 'target-kept')) >= 1, 'target-kept shortcut grouping works through CLI-style default records');
    ob_start();
    cow_merge_print_audit_text($target_kept_audit);
    $target_kept_audit_text = ob_get_clean();
    assert_true(str_contains($target_kept_audit_text, 'target-kept'), 'target-kept shortcut is visible in text audit filters');
} finally {
    remove_tree($tmp);
}

if ($fail) {
    echo "FAILURES: $fail\n";
    exit(1);
}
echo "All COW merge tests passed ($pass assertions).\n";

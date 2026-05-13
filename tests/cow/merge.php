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

    $rollback_base = $tmp . '/rollback-base.sqlite';
    $rollback_source = $tmp . '/rollback-source.sqlite';
    $rollback_target = $tmp . '/rollback-target.sqlite';
    $rollback_metadata = $tmp . '/.forkpress/cow/merge/rollback-metadata.sqlite';
    foreach ([$rollback_base, $rollback_source, $rollback_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_z_rollback_rows (id INTEGER PRIMARY KEY, value TEXT)');
        $db->exec("INSERT INTO plugin_z_rollback_rows (id, value) VALUES (1, 'base')");
        $db->close();
    }
    $db = open_db($rollback_source);
    $db->exec('CREATE TABLE plugin_a_keyless_staged (label TEXT, value TEXT)');
    $db->exec("INSERT INTO plugin_a_keyless_staged (rowid, label, value) VALUES (17, 'staged', 'source')");
    $db->exec("UPDATE plugin_z_rollback_rows SET value = 'source' WHERE id = 1");
    $db->close();
    $db = open_db($rollback_target);
    $db->exec(
        "CREATE TRIGGER plugin_z_rollback_guard BEFORE UPDATE ON plugin_z_rollback_rows " .
        "BEGIN SELECT RAISE(ROLLBACK, 'rollback trigger'); END"
    );
    $db->close();
    assert_throws(
        fn() => cow_merge_databases($rollback_base, $rollback_source, $rollback_target, $rollback_metadata, 'feature-rollback', 'main'),
        'failed to commit target database transaction',
        'whole-merge target rollback is surfaced as a failed merge'
    );
    assert_same(
        (int)scalar($rollback_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_a_keyless_staged'"),
        0,
        'whole-merge target rollback removes earlier staged source-added tables'
    );
    assert_same(scalar($rollback_target, 'SELECT value FROM plugin_z_rollback_rows WHERE id = 1'), 'base', 'whole-merge target rollback preserves pre-merge row state');
    assert_same(
        (int)scalar($rollback_metadata, "SELECT COUNT(*) FROM merge_runs WHERE source_branch = 'feature-rollback' AND status = 'failed' AND failure_reason LIKE 'failed to commit target database transaction%'"),
        1,
        'failed whole-merge run remains auditable after metadata rollback'
    );
    assert_same((int)scalar($rollback_metadata, 'SELECT COUNT(*) FROM merge_decisions'), 0, 'failed whole-merge rollback discards staged decision metadata');
    assert_same((int)scalar($rollback_metadata, 'SELECT COUNT(*) FROM merge_conflicts'), 0, 'failed whole-merge rollback discards staged conflict metadata');
    assert_same((int)scalar($rollback_metadata, 'SELECT COUNT(*) FROM merge_row_identities'), 0, 'failed whole-merge rollback discards staged no-primary-key sidecars');

    $unique_base = $tmp . '/unique-base.sqlite';
    $unique_source = $tmp . '/unique-source.sqlite';
    $unique_target = $tmp . '/unique-target.sqlite';
    $unique_metadata = $tmp . '/.forkpress/cow/merge/unique-metadata.sqlite';
    create_base_db($unique_base);
    copy($unique_base, $unique_source);
    copy($unique_base, $unique_target);
    foreach ([$unique_base, $unique_source, $unique_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_unique_rows (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT UNIQUE, value TEXT)');
        $db->close();
    }
    $db = open_db($unique_source);
    $db->exec("INSERT INTO plugin_unique_rows (id, slug, value) VALUES (100, 'shared-slug', 'source row')");
    $db->close();
    $db = open_db($unique_target);
    $db->exec("INSERT INTO plugin_unique_rows (id, slug, value) VALUES (200, 'shared-slug', 'target row')");
    $db->close();
    $unique_result = cow_merge_databases($unique_base, $unique_source, $unique_target, $unique_metadata, 'feature-unique', 'main');
    assert_same($unique_result['status'], 'completed_with_conflicts', 'source insert colliding with target unique key is audited instead of aborting');
    assert_same((int)scalar($unique_target, "SELECT COUNT(*) FROM plugin_unique_rows WHERE slug = 'shared-slug'"), 1, 'target unique row remains singular after collision');
    assert_same(scalar($unique_target, "SELECT value FROM plugin_unique_rows WHERE slug = 'shared-slug'"), 'target row', 'target unique row wins by default');
    assert_same(
        (int)scalar($unique_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_unique_rows' AND conflict_type = 'row-unique-collision'"),
        1,
        'unique-key row collision is recorded as a conflict'
    );
    assert_same(
        (int)scalar($unique_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_unique_rows' AND decision = 'target-wins' AND reason LIKE 'source inserted row collides with target unique index%'"),
        1,
        'unique-key row collision records an auditable target-wins decision'
    );
    $unique_conflict_id = (int)scalar($unique_metadata, "SELECT c.id FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_unique_rows' AND c.conflict_type = 'row-unique-collision' AND r.source_branch = 'feature-unique' ORDER BY c.id DESC LIMIT 1");
    $unique_dry_resolution = cow_merge_resolve_conflict(
        $unique_metadata,
        $unique_conflict_id,
        'source',
        false,
        'Preview unique source row.',
        'cow-test'
    );
    assert_same($unique_dry_resolution['status'], 'validated', 'dry-run unique collision source resolution validates the audited target collision');
    assert_same(scalar($unique_target, "SELECT value FROM plugin_unique_rows WHERE slug = 'shared-slug'"), 'target row', 'dry-run unique collision resolution does not mutate target');
    $unique_source_resolution = cow_merge_resolve_conflict(
        $unique_metadata,
        $unique_conflict_id,
        'source',
        true,
        'Apply unique source row.',
        'cow-test'
    );
    assert_same($unique_source_resolution['status'], 'applied', 'source unique collision resolution records applied status');
    assert_same((int)scalar($unique_target, "SELECT COUNT(*) FROM plugin_unique_rows WHERE slug = 'shared-slug'"), 1, 'source unique collision resolution keeps the unique key singular');
    assert_same((int)scalar($unique_target, "SELECT id FROM plugin_unique_rows WHERE slug = 'shared-slug'"), 100, 'source unique collision resolution inserts the audited source row identity');
    assert_same(scalar($unique_target, "SELECT value FROM plugin_unique_rows WHERE slug = 'shared-slug'"), 'source row', 'source unique collision resolution replaces the target row payload');
    assert_same((int)scalar($unique_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $unique_conflict_id AND table_name = 'plugin_unique_rows' AND choice = 'source' AND applied = 1"), 1, 'source unique collision resolution is auditable');

    $partial_unique_base = $tmp . '/partial-unique-base.sqlite';
    $partial_unique_source = $tmp . '/partial-unique-source.sqlite';
    $partial_unique_target = $tmp . '/partial-unique-target.sqlite';
    $partial_unique_metadata = $tmp . '/.forkpress/cow/merge/partial-unique-metadata.sqlite';
    create_base_db($partial_unique_base);
    copy($partial_unique_base, $partial_unique_source);
    copy($partial_unique_base, $partial_unique_target);
    foreach ([$partial_unique_base, $partial_unique_source, $partial_unique_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_partial_unique_rows (id INTEGER PRIMARY KEY, slug TEXT, active INTEGER NOT NULL DEFAULT 1, value TEXT)');
        $db->exec('CREATE UNIQUE INDEX plugin_partial_unique_rows_slug_active_idx ON plugin_partial_unique_rows(slug) WHERE active = 1');
        $db->close();
    }
    $db = open_db($partial_unique_source);
    $db->exec("INSERT INTO plugin_partial_unique_rows (id, slug, active, value) VALUES (10, 'partial-shared', 1, 'source active row')");
    $db->exec("INSERT INTO plugin_partial_unique_rows (id, slug, active, value) VALUES (11, 'partial-inactive', 0, 'source inactive row')");
    $db->close();
    $db = open_db($partial_unique_target);
    $db->exec("INSERT INTO plugin_partial_unique_rows (id, slug, active, value) VALUES (20, 'partial-shared', 1, 'target active row')");
    $db->exec("INSERT INTO plugin_partial_unique_rows (id, slug, active, value) VALUES (21, 'partial-inactive', 1, 'target active same slug')");
    $db->close();
    $partial_unique_result = cow_merge_databases($partial_unique_base, $partial_unique_source, $partial_unique_target, $partial_unique_metadata, 'feature-partial-unique', 'main');
    assert_same($partial_unique_result['status'], 'completed_with_conflicts', 'source insert colliding with target partial unique key is audited instead of aborting');
    assert_same(
        (int)scalar($partial_unique_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_partial_unique_rows' AND conflict_type = 'row-unique-collision'"),
        1,
        'partial unique-key row collision is recorded as a conflict'
    );
    assert_same(scalar($partial_unique_target, "SELECT value FROM plugin_partial_unique_rows WHERE slug = 'partial-shared'"), 'target active row', 'partial unique target row wins by default');
    assert_same((int)scalar($partial_unique_target, "SELECT COUNT(*) FROM plugin_partial_unique_rows WHERE slug = 'partial-inactive'"), 2, 'source row outside partial unique predicate still inserts cleanly');
    assert_same(scalar($partial_unique_target, "SELECT value FROM plugin_partial_unique_rows WHERE id = 11"), 'source inactive row', 'inactive partial unique source row is applied');
    $partial_unique_conflict_id = (int)scalar($partial_unique_metadata, "SELECT c.id FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_partial_unique_rows' AND c.conflict_type = 'row-unique-collision' AND r.source_branch = 'feature-partial-unique' ORDER BY c.id DESC LIMIT 1");
    $partial_unique_source_resolution = cow_merge_resolve_conflict(
        $partial_unique_metadata,
        $partial_unique_conflict_id,
        'source',
        true,
        'Apply partial unique source row.',
        'cow-test'
    );
    assert_same($partial_unique_source_resolution['status'], 'applied', 'source partial unique collision resolution records applied status');
    assert_same((int)scalar($partial_unique_target, "SELECT id FROM plugin_partial_unique_rows WHERE slug = 'partial-shared'"), 10, 'source partial unique collision resolution inserts the audited source row identity');
    assert_same(scalar($partial_unique_target, "SELECT value FROM plugin_partial_unique_rows WHERE slug = 'partial-shared'"), 'source active row', 'source partial unique collision resolution replaces the target row payload');

    $expression_unique_base = $tmp . '/expression-unique-base.sqlite';
    $expression_unique_source = $tmp . '/expression-unique-source.sqlite';
    $expression_unique_target = $tmp . '/expression-unique-target.sqlite';
    $expression_unique_metadata = $tmp . '/.forkpress/cow/merge/expression-unique-metadata.sqlite';
    create_base_db($expression_unique_base);
    copy($expression_unique_base, $expression_unique_source);
    copy($expression_unique_base, $expression_unique_target);
    foreach ([$expression_unique_base, $expression_unique_source, $expression_unique_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_expression_unique_rows (id INTEGER PRIMARY KEY, slug TEXT, value TEXT)');
        $db->exec('CREATE UNIQUE INDEX plugin_expression_unique_rows_slug_idx ON plugin_expression_unique_rows(lower(slug))');
        $db->close();
    }
    $db = open_db($expression_unique_source);
    $db->exec("INSERT INTO plugin_expression_unique_rows (id, slug, value) VALUES (10, 'Shared-Expression', 'source expression row')");
    $db->close();
    $db = open_db($expression_unique_target);
    $db->exec("INSERT INTO plugin_expression_unique_rows (id, slug, value) VALUES (20, 'shared-expression', 'target expression row')");
    $db->close();
    $expression_unique_result = cow_merge_databases($expression_unique_base, $expression_unique_source, $expression_unique_target, $expression_unique_metadata, 'feature-expression-unique', 'main');
    assert_same($expression_unique_result['status'], 'completed_with_conflicts', 'source insert colliding with target expression unique key is audited instead of aborting');
    assert_same(
        (int)scalar($expression_unique_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_expression_unique_rows' AND conflict_type = 'row-unique-collision'"),
        1,
        'expression unique-key row collision is recorded as a conflict'
    );
    assert_same(scalar($expression_unique_target, "SELECT value FROM plugin_expression_unique_rows WHERE lower(slug) = 'shared-expression'"), 'target expression row', 'expression unique target row wins by default');
    $expression_unique_conflict_id = (int)scalar($expression_unique_metadata, "SELECT c.id FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_expression_unique_rows' AND c.conflict_type = 'row-unique-collision' AND r.source_branch = 'feature-expression-unique' ORDER BY c.id DESC LIMIT 1");
    $expression_unique_source_resolution = cow_merge_resolve_conflict(
        $expression_unique_metadata,
        $expression_unique_conflict_id,
        'source',
        true,
        'Apply expression unique source row.',
        'cow-test'
    );
    assert_same($expression_unique_source_resolution['status'], 'applied', 'source expression unique collision resolution records applied status');
    assert_same((int)scalar($expression_unique_target, "SELECT id FROM plugin_expression_unique_rows WHERE lower(slug) = 'shared-expression'"), 10, 'source expression unique collision resolution inserts the audited source row identity');
    assert_same(scalar($expression_unique_target, "SELECT value FROM plugin_expression_unique_rows WHERE lower(slug) = 'shared-expression'"), 'source expression row', 'source expression unique collision resolution replaces the target row payload');

    $composite_expression_unique_base = $tmp . '/composite-expression-unique-base.sqlite';
    $composite_expression_unique_source = $tmp . '/composite-expression-unique-source.sqlite';
    $composite_expression_unique_target = $tmp . '/composite-expression-unique-target.sqlite';
    $composite_expression_unique_metadata = $tmp . '/.forkpress/cow/merge/composite-expression-unique-metadata.sqlite';
    create_base_db($composite_expression_unique_base);
    copy($composite_expression_unique_base, $composite_expression_unique_source);
    copy($composite_expression_unique_base, $composite_expression_unique_target);
    foreach ([$composite_expression_unique_base, $composite_expression_unique_source, $composite_expression_unique_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_composite_expression_unique_rows (id INTEGER PRIMARY KEY, slug TEXT, locale TEXT, active INTEGER NOT NULL DEFAULT 1, value TEXT)');
        $db->exec('CREATE UNIQUE INDEX plugin_composite_expression_unique_rows_idx ON plugin_composite_expression_unique_rows(lower(slug) COLLATE NOCASE, locale COLLATE NOCASE) WHERE active = 1');
        $db->close();
    }
    $db = open_db($composite_expression_unique_source);
    $db->exec("INSERT INTO plugin_composite_expression_unique_rows (id, slug, locale, active, value) VALUES (10, 'Shared-Composite', 'EN', 1, 'source composite row')");
    $db->exec("INSERT INTO plugin_composite_expression_unique_rows (id, slug, locale, active, value) VALUES (11, 'Passive-Composite', 'EN', 0, 'source passive row')");
    $db->close();
    $db = open_db($composite_expression_unique_target);
    $db->exec("INSERT INTO plugin_composite_expression_unique_rows (id, slug, locale, active, value) VALUES (20, 'shared-composite', 'en', 1, 'target composite row')");
    $db->exec("INSERT INTO plugin_composite_expression_unique_rows (id, slug, locale, active, value) VALUES (21, 'passive-composite', 'en', 1, 'target active passive-slug row')");
    $db->close();
    $composite_expression_unique_result = cow_merge_databases($composite_expression_unique_base, $composite_expression_unique_source, $composite_expression_unique_target, $composite_expression_unique_metadata, 'feature-composite-expression-unique', 'main');
    assert_same($composite_expression_unique_result['status'], 'completed_with_conflicts', 'source insert colliding with composite partial expression unique key is audited instead of aborting');
    assert_same(
        (int)scalar($composite_expression_unique_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_composite_expression_unique_rows' AND conflict_type = 'row-unique-collision'"),
        1,
        'composite partial expression unique-key row collision is recorded as a conflict'
    );
    assert_same(scalar($composite_expression_unique_target, "SELECT value FROM plugin_composite_expression_unique_rows WHERE lower(slug) = 'shared-composite' AND locale COLLATE NOCASE = 'en'"), 'target composite row', 'composite partial expression unique target row wins by default');
    assert_same((int)scalar($composite_expression_unique_target, "SELECT COUNT(*) FROM plugin_composite_expression_unique_rows WHERE lower(slug) = 'passive-composite' AND locale COLLATE NOCASE = 'en'"), 2, 'source row outside composite partial expression predicate still inserts cleanly');
    $composite_expression_unique_conflict_id = (int)scalar($composite_expression_unique_metadata, "SELECT c.id FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_composite_expression_unique_rows' AND c.conflict_type = 'row-unique-collision' AND r.source_branch = 'feature-composite-expression-unique' ORDER BY c.id DESC LIMIT 1");
    $composite_expression_unique_source_resolution = cow_merge_resolve_conflict(
        $composite_expression_unique_metadata,
        $composite_expression_unique_conflict_id,
        'source',
        true,
        'Apply composite expression unique source row.',
        'cow-test'
    );
    assert_same($composite_expression_unique_source_resolution['status'], 'applied', 'source composite expression unique collision resolution records applied status');
    assert_same((int)scalar($composite_expression_unique_target, "SELECT id FROM plugin_composite_expression_unique_rows WHERE lower(slug) = 'shared-composite' AND locale COLLATE NOCASE = 'en'"), 10, 'source composite expression unique collision resolution inserts the audited source row identity');
    assert_same(scalar($composite_expression_unique_target, "SELECT value FROM plugin_composite_expression_unique_rows WHERE lower(slug) = 'shared-composite' AND locale COLLATE NOCASE = 'en'"), 'source composite row', 'source composite expression unique collision resolution replaces the target row payload');

    $generated_update_unique_base = $tmp . '/generated-update-unique-base.sqlite';
    $generated_update_unique_source = $tmp . '/generated-update-unique-source.sqlite';
    $generated_update_unique_target = $tmp . '/generated-update-unique-target.sqlite';
    $generated_update_unique_metadata = $tmp . '/.forkpress/cow/merge/generated-update-unique-metadata.sqlite';
    create_base_db($generated_update_unique_base);
    copy($generated_update_unique_base, $generated_update_unique_source);
    copy($generated_update_unique_base, $generated_update_unique_target);
    foreach ([$generated_update_unique_base, $generated_update_unique_source, $generated_update_unique_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_generated_unique_updates (id INTEGER PRIMARY KEY, slug TEXT, slug_norm TEXT GENERATED ALWAYS AS (lower(slug)) STORED UNIQUE, value TEXT)');
        $db->exec("INSERT INTO plugin_generated_unique_updates (id, slug, value) VALUES (1, 'base-generated-update', 'base generated row')");
        $db->close();
    }
    $db = open_db($generated_update_unique_source);
    $db->exec("UPDATE plugin_generated_unique_updates SET slug = 'Shared-Generated-Update', value = 'source generated update' WHERE id = 1");
    $db->close();
    $db = open_db($generated_update_unique_target);
    $db->exec("INSERT INTO plugin_generated_unique_updates (id, slug, value) VALUES (2, 'shared-generated-update', 'target generated collision')");
    $db->close();
    $generated_update_unique_result = cow_merge_databases($generated_update_unique_base, $generated_update_unique_source, $generated_update_unique_target, $generated_update_unique_metadata, 'feature-generated-update-unique', 'main');
    assert_same($generated_update_unique_result['status'], 'completed_with_conflicts', 'source update colliding with target generated-column unique key is audited instead of aborting');
    assert_same(scalar($generated_update_unique_target, "SELECT value FROM plugin_generated_unique_updates WHERE id = 1"), 'base generated row', 'generated unique source update is held for review by default');
    assert_same(scalar($generated_update_unique_target, "SELECT value FROM plugin_generated_unique_updates WHERE id = 2"), 'target generated collision', 'generated unique target collision wins by default');
    assert_same(
        (int)scalar($generated_update_unique_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_generated_unique_updates' AND conflict_type = 'row-unique-collision'"),
        1,
        'generated unique update collision is recorded as a conflict'
    );
    $generated_update_unique_conflict_id = (int)scalar($generated_update_unique_metadata, "SELECT c.id FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_generated_unique_updates' AND c.conflict_type = 'row-unique-collision' AND r.source_branch = 'feature-generated-update-unique' ORDER BY c.id DESC LIMIT 1");
    $generated_update_unique_source_resolution = cow_merge_resolve_conflict(
        $generated_update_unique_metadata,
        $generated_update_unique_conflict_id,
        'source',
        true,
        'Apply generated unique source update.',
        'cow-test'
    );
    assert_same($generated_update_unique_source_resolution['status'], 'applied', 'source generated unique update collision resolution records applied status');
    assert_same((int)scalar($generated_update_unique_target, "SELECT COUNT(*) FROM plugin_generated_unique_updates"), 1, 'source generated unique update resolution removes the target collision row');
    assert_same(scalar($generated_update_unique_target, "SELECT slug_norm FROM plugin_generated_unique_updates WHERE id = 1"), 'shared-generated-update', 'source generated unique update resolution recomputes the generated value');
    assert_same(scalar($generated_update_unique_target, "SELECT value FROM plugin_generated_unique_updates WHERE id = 1"), 'source generated update', 'source generated unique update resolution updates the original source identity row');

    $keyless_update_unique_base = $tmp . '/keyless-update-unique-base.sqlite';
    $keyless_update_unique_source = $tmp . '/keyless-update-unique-source.sqlite';
    $keyless_update_unique_target = $tmp . '/keyless-update-unique-target.sqlite';
    $keyless_update_unique_metadata = $tmp . '/.forkpress/cow/merge/keyless-update-unique-metadata.sqlite';
    create_base_db($keyless_update_unique_base);
    copy($keyless_update_unique_base, $keyless_update_unique_source);
    copy($keyless_update_unique_base, $keyless_update_unique_target);
    foreach ([$keyless_update_unique_base, $keyless_update_unique_source, $keyless_update_unique_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_keyless_unique_updates (slug TEXT UNIQUE, value TEXT)');
        $db->exec("INSERT INTO plugin_keyless_unique_updates (slug, value) VALUES ('base-keyless-update', 'base keyless row')");
        $db->close();
    }
    cow_merge_capture_row_identities($keyless_update_unique_base, $keyless_update_unique_metadata, 'main');
    cow_merge_capture_row_identities($keyless_update_unique_source, $keyless_update_unique_metadata, 'feature-keyless-update-unique', 'main');
    $db = open_db($keyless_update_unique_source);
    $db->exec("UPDATE plugin_keyless_unique_updates SET slug = 'shared-keyless-update', value = 'source keyless update' WHERE rowid = 1");
    $db->close();
    cow_merge_capture_row_identities($keyless_update_unique_source, $keyless_update_unique_metadata, 'feature-keyless-update-unique', 'main');
    $db = open_db($keyless_update_unique_target);
    $db->exec("INSERT INTO plugin_keyless_unique_updates (slug, value) VALUES ('shared-keyless-update', 'target keyless collision')");
    $db->close();
    cow_merge_capture_row_identities($keyless_update_unique_target, $keyless_update_unique_metadata, 'main');
    $keyless_update_unique_result = cow_merge_databases($keyless_update_unique_base, $keyless_update_unique_source, $keyless_update_unique_target, $keyless_update_unique_metadata, 'feature-keyless-update-unique', 'main');
    assert_same($keyless_update_unique_result['status'], 'completed_with_conflicts', 'keyless source update colliding with target unique key is audited instead of aborting');
    assert_same(scalar($keyless_update_unique_target, "SELECT value FROM plugin_keyless_unique_updates WHERE rowid = 1"), 'base keyless row', 'keyless source update is held for review by default');
    assert_same(scalar($keyless_update_unique_target, "SELECT value FROM plugin_keyless_unique_updates WHERE slug = 'shared-keyless-update'"), 'target keyless collision', 'keyless target collision wins by default');
    $keyless_update_unique_conflict_id = (int)scalar($keyless_update_unique_metadata, "SELECT c.id FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_keyless_unique_updates' AND c.conflict_type = 'row-unique-collision' AND r.source_branch = 'feature-keyless-update-unique' ORDER BY c.id DESC LIMIT 1");
    $keyless_update_unique_conflict_identity = scalar($keyless_update_unique_metadata, "SELECT row_identity FROM merge_conflicts WHERE id = $keyless_update_unique_conflict_id");
    $keyless_update_unique_source_resolution = cow_merge_resolve_conflict(
        $keyless_update_unique_metadata,
        $keyless_update_unique_conflict_id,
        'source',
        true,
        'Apply keyless unique source update.',
        'cow-test'
    );
    assert_same($keyless_update_unique_source_resolution['status'], 'applied', 'source keyless unique update collision resolution records applied status');
    assert_same((int)scalar($keyless_update_unique_target, "SELECT COUNT(*) FROM plugin_keyless_unique_updates"), 1, 'source keyless unique update resolution removes the target collision row');
    assert_same(scalar($keyless_update_unique_target, "SELECT value FROM plugin_keyless_unique_updates WHERE rowid = 1"), 'source keyless update', 'source keyless unique update resolution updates the original sidecar row');
    $keyless_update_unique_plain_identity = cow_merge_decode_payload_json($keyless_update_unique_conflict_identity, 'keyless update unique row identity');
    $keyless_update_unique_target_identity = cow_merge_decode_payload_json(
        (string)scalar($keyless_update_unique_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_keyless_unique_updates' AND rowid = 1"),
        'keyless update unique target row identity'
    );
    assert_true(cow_merge_values_equal($keyless_update_unique_target_identity, $keyless_update_unique_plain_identity), 'source keyless unique update resolution keeps the source sidecar identity on the updated target row');

    $constraint_insert_base = $tmp . '/constraint-insert-base.sqlite';
    $constraint_insert_source = $tmp . '/constraint-insert-source.sqlite';
    $constraint_insert_target = $tmp . '/constraint-insert-target.sqlite';
    $constraint_insert_metadata = $tmp . '/.forkpress/cow/merge/constraint-insert-metadata.sqlite';
    create_base_db($constraint_insert_base);
    copy($constraint_insert_base, $constraint_insert_source);
    copy($constraint_insert_base, $constraint_insert_target);
    foreach ([$constraint_insert_base, $constraint_insert_source, $constraint_insert_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_constraint_inserts (id INTEGER PRIMARY KEY, value TEXT)');
        $db->close();
    }
    $db = open_db($constraint_insert_source);
    $db->exec("INSERT INTO plugin_constraint_inserts (id, value) VALUES (10, 'blocked')");
    $db->close();
    $db = open_db($constraint_insert_target);
    $db->exec('DROP TABLE plugin_constraint_inserts');
    $db->exec("CREATE TABLE plugin_constraint_inserts (id INTEGER PRIMARY KEY, value TEXT CHECK(value != 'blocked'))");
    $db->close();
    $constraint_insert_result = cow_merge_databases($constraint_insert_base, $constraint_insert_source, $constraint_insert_target, $constraint_insert_metadata, 'feature-constraint-insert', 'main');
    assert_same($constraint_insert_result['status'], 'completed_with_conflicts', 'source insert violating target-side check constraint is audited instead of aborting');
    assert_same((int)scalar($constraint_insert_target, 'SELECT COUNT(*) FROM plugin_constraint_inserts'), 0, 'target-side check constraint keeps the source insert out by default');
    assert_same(
        (int)scalar($constraint_insert_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_constraint_inserts' AND conflict_type = 'row-target-constraint'"),
        1,
        'target-side constraint insert collision is recorded as a row conflict'
    );
    assert_same(
        (int)scalar($constraint_insert_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_constraint_inserts' AND decision = 'target-wins' AND reason LIKE 'source inserted row violates target constraints%'"),
        1,
        'target-side constraint insert collision records an auditable target-wins decision'
    );
    $constraint_insert_conflict_id = (int)scalar($constraint_insert_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_constraint_inserts' AND conflict_type = 'row-target-constraint' ORDER BY id DESC LIMIT 1");
    $constraint_insert_target_resolution = cow_merge_resolve_conflict(
        $constraint_insert_metadata,
        $constraint_insert_conflict_id,
        'target',
        true,
        'Accept target constraint for insert.',
        'cow-test'
    );
    assert_same($constraint_insert_target_resolution['status'], 'validated', 'target constraint insert resolution validates the audited target choice');
    cow_merge_databases($constraint_insert_base, $constraint_insert_source, $constraint_insert_target, $constraint_insert_metadata, 'feature-constraint-insert', 'main');
    assert_same(
        (int)scalar($constraint_insert_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_constraint_inserts' AND decision = 'target-accepted' AND reason LIKE 'reviewed target resolution already accepts source insert blocked by target constraints%'"),
        1,
        'rerunning after target constraint insert resolution records accepted target state'
    );

    $constraint_update_base = $tmp . '/constraint-update-base.sqlite';
    $constraint_update_source = $tmp . '/constraint-update-source.sqlite';
    $constraint_update_target = $tmp . '/constraint-update-target.sqlite';
    $constraint_update_metadata = $tmp . '/.forkpress/cow/merge/constraint-update-metadata.sqlite';
    create_base_db($constraint_update_base);
    copy($constraint_update_base, $constraint_update_source);
    copy($constraint_update_base, $constraint_update_target);
    foreach ([$constraint_update_base, $constraint_update_source, $constraint_update_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_constraint_updates (id INTEGER PRIMARY KEY, value TEXT)');
        $db->exec("INSERT INTO plugin_constraint_updates (id, value) VALUES (1, 'allowed')");
        $db->close();
    }
    $db = open_db($constraint_update_source);
    $db->exec("UPDATE plugin_constraint_updates SET value = 'blocked' WHERE id = 1");
    $db->close();
    $db = open_db($constraint_update_target);
    $db->exec('ALTER TABLE plugin_constraint_updates RENAME TO plugin_constraint_updates_old');
    $db->exec("CREATE TABLE plugin_constraint_updates (id INTEGER PRIMARY KEY, value TEXT CHECK(value != 'blocked'))");
    $db->exec('INSERT INTO plugin_constraint_updates (id, value) SELECT id, value FROM plugin_constraint_updates_old');
    $db->exec('DROP TABLE plugin_constraint_updates_old');
    $db->close();
    $constraint_update_result = cow_merge_databases($constraint_update_base, $constraint_update_source, $constraint_update_target, $constraint_update_metadata, 'feature-constraint-update', 'main');
    assert_same($constraint_update_result['status'], 'completed_with_conflicts', 'source update violating target-side check constraint is audited instead of aborting');
    assert_same(scalar($constraint_update_target, 'SELECT value FROM plugin_constraint_updates WHERE id = 1'), 'allowed', 'target-side check constraint keeps the original row by default');
    assert_same(
        (int)scalar($constraint_update_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_constraint_updates' AND conflict_type = 'row-target-constraint'"),
        1,
        'target-side constraint update collision is recorded as a row conflict'
    );
    assert_same(
        (int)scalar($constraint_update_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_constraint_updates' AND decision = 'target-wins' AND reason LIKE 'source changed row violates target constraints%'"),
        1,
        'target-side constraint update collision records an auditable target-wins decision'
    );

    $fk_order_base = $tmp . '/fk-order-base.sqlite';
    $fk_order_source = $tmp . '/fk-order-source.sqlite';
    $fk_order_target = $tmp . '/fk-order-target.sqlite';
    $fk_order_metadata = $tmp . '/.forkpress/cow/merge/fk-order-metadata.sqlite';
    create_base_db($fk_order_base);
    copy($fk_order_base, $fk_order_source);
    copy($fk_order_base, $fk_order_target);
    foreach ([$fk_order_base, $fk_order_source, $fk_order_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_fk_parents (id INTEGER PRIMARY KEY, label TEXT)');
        $db->exec('CREATE TABLE plugin_fk_children (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL REFERENCES plugin_fk_parents(id), label TEXT)');
        $db->close();
    }
    $db = open_db($fk_order_source);
    $db->exec("INSERT INTO plugin_fk_children (id, parent_id, label) VALUES (20, 10, 'source child')");
    $db->exec("INSERT INTO plugin_fk_parents (id, label) VALUES (10, 'source parent')");
    $db->close();
    $fk_order_result = cow_merge_databases($fk_order_base, $fk_order_source, $fk_order_target, $fk_order_metadata, 'feature-fk-order', 'main');
    assert_same($fk_order_result['status'], 'completed', 'foreign-key parent rows are merged before dependent child rows');
    assert_same(scalar($fk_order_target, 'SELECT label FROM plugin_fk_parents WHERE id = 10'), 'source parent', 'foreign-key source parent row is applied');
    assert_same(scalar($fk_order_target, 'SELECT label FROM plugin_fk_children WHERE id = 20'), 'source child', 'foreign-key source child row is applied after parent');

    $self_fk_insert_base = $tmp . '/self-fk-insert-base.sqlite';
    $self_fk_insert_source = $tmp . '/self-fk-insert-source.sqlite';
    $self_fk_insert_target = $tmp . '/self-fk-insert-target.sqlite';
    $self_fk_insert_metadata = $tmp . '/.forkpress/cow/merge/self-fk-insert-metadata.sqlite';
    create_base_db($self_fk_insert_base);
    copy($self_fk_insert_base, $self_fk_insert_source);
    copy($self_fk_insert_base, $self_fk_insert_target);
    foreach ([$self_fk_insert_base, $self_fk_insert_source, $self_fk_insert_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_self_fk_rows (id INTEGER PRIMARY KEY, parent_id INTEGER REFERENCES plugin_self_fk_rows(id), label TEXT)');
        $db->close();
    }
    $db = open_db($self_fk_insert_source);
    $db->exec("INSERT INTO plugin_self_fk_rows (id, parent_id, label) VALUES (1, 2, 'source child before parent')");
    $db->exec("INSERT INTO plugin_self_fk_rows (id, parent_id, label) VALUES (2, NULL, 'source parent')");
    $db->close();
    $self_fk_insert_result = cow_merge_databases($self_fk_insert_base, $self_fk_insert_source, $self_fk_insert_target, $self_fk_insert_metadata, 'feature-self-fk-insert', 'main');
    assert_same($self_fk_insert_result['status'], 'completed', 'same-table foreign-key source inserts are ordered parent-before-child');
    assert_same(scalar($self_fk_insert_target, 'SELECT label FROM plugin_self_fk_rows WHERE id = 2'), 'source parent', 'same-table foreign-key parent row is applied before its source child');
    assert_same(scalar($self_fk_insert_target, 'SELECT parent_id FROM plugin_self_fk_rows WHERE id = 1'), 2, 'same-table foreign-key child row validates after its source parent exists');
    assert_same(
        (int)scalar($self_fk_insert_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_self_fk_rows' AND conflict_type = 'row-target-constraint'"),
        0,
        'same-table foreign-key source inserts avoid false target constraint conflicts'
    );

    $self_fk_table_base = $tmp . '/self-fk-table-base.sqlite';
    $self_fk_table_source = $tmp . '/self-fk-table-source.sqlite';
    $self_fk_table_target = $tmp . '/self-fk-table-target.sqlite';
    $self_fk_table_metadata = $tmp . '/.forkpress/cow/merge/self-fk-table-metadata.sqlite';
    create_base_db($self_fk_table_base);
    copy($self_fk_table_base, $self_fk_table_source);
    copy($self_fk_table_base, $self_fk_table_target);
    $db = open_db($self_fk_table_source);
    $db->exec('CREATE TABLE plugin_self_fk_added_rows (id INTEGER PRIMARY KEY, parent_id INTEGER REFERENCES plugin_self_fk_added_rows(id), label TEXT)');
    $db->exec("INSERT INTO plugin_self_fk_added_rows (id, parent_id, label) VALUES (1, 2, 'source-added child before parent')");
    $db->exec("INSERT INTO plugin_self_fk_added_rows (id, parent_id, label) VALUES (2, NULL, 'source-added parent')");
    $db->close();
    $self_fk_table_result = cow_merge_databases($self_fk_table_base, $self_fk_table_source, $self_fk_table_target, $self_fk_table_metadata, 'feature-self-fk-table', 'main');
    assert_same($self_fk_table_result['status'], 'completed', 'source-added same-table foreign-key table materializes rows parent-before-child');
    assert_same(scalar($self_fk_table_target, 'SELECT label FROM plugin_self_fk_added_rows WHERE id = 2'), 'source-added parent', 'source-added same-table foreign-key parent row is materialized');
    assert_same(scalar($self_fk_table_target, 'SELECT parent_id FROM plugin_self_fk_added_rows WHERE id = 1'), 2, 'source-added same-table foreign-key child row validates after its parent');
    assert_same(
        (int)scalar($self_fk_table_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_self_fk_added_rows' AND decision = 'source-applied'"),
        3,
        'source-added same-table foreign-key table and rows remain auditable'
    );

    $fk_insert_base = $tmp . '/fk-insert-base.sqlite';
    $fk_insert_source = $tmp . '/fk-insert-source.sqlite';
    $fk_insert_target = $tmp . '/fk-insert-target.sqlite';
    $fk_insert_metadata = $tmp . '/.forkpress/cow/merge/fk-insert-metadata.sqlite';
    create_base_db($fk_insert_base);
    copy($fk_insert_base, $fk_insert_source);
    copy($fk_insert_base, $fk_insert_target);
    foreach ([$fk_insert_base, $fk_insert_source, $fk_insert_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_fk_insert_parents (id INTEGER PRIMARY KEY, label TEXT)');
        $db->exec('CREATE TABLE plugin_fk_insert_children (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL REFERENCES plugin_fk_insert_parents(id), label TEXT)');
        $db->close();
    }
    $db = open_db($fk_insert_source);
    $db->exec("INSERT INTO plugin_fk_insert_children (id, parent_id, label) VALUES (20, 999, 'orphan source child')");
    $db->close();
    $fk_insert_result = cow_merge_databases($fk_insert_base, $fk_insert_source, $fk_insert_target, $fk_insert_metadata, 'feature-fk-insert', 'main');
    assert_same($fk_insert_result['status'], 'completed_with_conflicts', 'source insert violating target foreign key is audited instead of applied');
    assert_same((int)scalar($fk_insert_target, 'SELECT COUNT(*) FROM plugin_fk_insert_children'), 0, 'foreign-key violating source insert is kept out by default');
    assert_same(
        (int)scalar($fk_insert_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_fk_insert_children' AND conflict_type = 'row-target-constraint'"),
        1,
        'foreign-key insert violation is recorded as a row target constraint conflict'
    );
    $fk_insert_conflict_id = (int)scalar($fk_insert_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_fk_insert_children' AND conflict_type = 'row-target-constraint' ORDER BY id DESC LIMIT 1");
    assert_throws(
        fn() => cow_merge_resolve_conflict($fk_insert_metadata, $fk_insert_conflict_id, 'source', true, 'Try orphan source child.', 'cow-test'),
        'FOREIGN KEY constraint failed',
        'source resolution for a foreign-key insert remains validation-gated by target constraints'
    );
    $db = open_db($fk_insert_target);
    $db->exec("INSERT INTO plugin_fk_insert_parents (id, label) VALUES (999, 'reviewed parent')");
    $db->close();
    $fk_insert_source_resolution = cow_merge_resolve_conflict(
        $fk_insert_metadata,
        $fk_insert_conflict_id,
        'source',
        true,
        'Apply orphan source child after parent review.',
        'cow-test'
    );
    assert_same($fk_insert_source_resolution['status'], 'applied', 'source foreign-key insert resolution applies after the parent row exists');
    assert_same(scalar($fk_insert_target, 'SELECT label FROM plugin_fk_insert_children WHERE id = 20'), 'orphan source child', 'foreign-key source insert resolution applies the audited child row');

    $fk_update_base = $tmp . '/fk-update-base.sqlite';
    $fk_update_source = $tmp . '/fk-update-source.sqlite';
    $fk_update_target = $tmp . '/fk-update-target.sqlite';
    $fk_update_metadata = $tmp . '/.forkpress/cow/merge/fk-update-metadata.sqlite';
    create_base_db($fk_update_base);
    copy($fk_update_base, $fk_update_source);
    copy($fk_update_base, $fk_update_target);
    foreach ([$fk_update_base, $fk_update_source, $fk_update_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_fk_update_parents (id INTEGER PRIMARY KEY, label TEXT)');
        $db->exec('CREATE TABLE plugin_fk_update_children (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL REFERENCES plugin_fk_update_parents(id), label TEXT)');
        $db->exec("INSERT INTO plugin_fk_update_parents (id, label) VALUES (1, 'base parent')");
        $db->exec("INSERT INTO plugin_fk_update_children (id, parent_id, label) VALUES (20, 1, 'base child')");
        $db->close();
    }
    $db = open_db($fk_update_source);
    $db->exec("UPDATE plugin_fk_update_children SET parent_id = 999, label = 'source child reparented' WHERE id = 20");
    $db->close();
    $fk_update_result = cow_merge_databases($fk_update_base, $fk_update_source, $fk_update_target, $fk_update_metadata, 'feature-fk-update', 'main');
    assert_same($fk_update_result['status'], 'completed_with_conflicts', 'source update violating target foreign key is audited instead of applied');
    assert_same((int)scalar($fk_update_target, 'SELECT parent_id FROM plugin_fk_update_children WHERE id = 20'), 1, 'foreign-key violating source update is held for review by default');
    assert_same(
        (int)scalar($fk_update_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_fk_update_children' AND conflict_type = 'row-target-constraint'"),
        1,
        'foreign-key update violation is recorded as a row target constraint conflict'
    );

    $fk_delete_base = $tmp . '/fk-delete-base.sqlite';
    $fk_delete_source = $tmp . '/fk-delete-source.sqlite';
    $fk_delete_target = $tmp . '/fk-delete-target.sqlite';
    $fk_delete_metadata = $tmp . '/.forkpress/cow/merge/fk-delete-metadata.sqlite';
    create_base_db($fk_delete_base);
    copy($fk_delete_base, $fk_delete_source);
    copy($fk_delete_base, $fk_delete_target);
    foreach ([$fk_delete_base, $fk_delete_source, $fk_delete_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_fk_delete_parents (id INTEGER PRIMARY KEY, label TEXT)');
        $db->exec('CREATE TABLE plugin_fk_delete_children (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL REFERENCES plugin_fk_delete_parents(id), label TEXT)');
        $db->exec("INSERT INTO plugin_fk_delete_parents (id, label) VALUES (1, 'base parent')");
        $db->close();
    }
    $db = open_db($fk_delete_source);
    $db->exec('DELETE FROM plugin_fk_delete_parents WHERE id = 1');
    $db->close();
    $db = open_db($fk_delete_target);
    $db->exec("INSERT INTO plugin_fk_delete_children (id, parent_id, label) VALUES (20, 1, 'target child')");
    $db->close();
    $fk_delete_result = cow_merge_databases($fk_delete_base, $fk_delete_source, $fk_delete_target, $fk_delete_metadata, 'feature-fk-delete', 'main');
    assert_same($fk_delete_result['status'], 'completed_with_conflicts', 'source delete violating a target foreign-key child is audited instead of orphaning the child');
    assert_same((int)scalar($fk_delete_target, 'SELECT COUNT(*) FROM plugin_fk_delete_parents WHERE id = 1'), 1, 'foreign-key protected parent is kept by default');
    assert_same((int)scalar($fk_delete_target, 'SELECT COUNT(*) FROM plugin_fk_delete_children WHERE id = 20'), 1, 'target foreign-key child is preserved by default');
    assert_same(
        (int)scalar($fk_delete_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_fk_delete_parents' AND conflict_type = 'row-target-constraint'"),
        1,
        'foreign-key delete violation is recorded as a row target constraint conflict'
    );
    assert_same(
        (int)scalar($fk_delete_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_fk_delete_parents' AND decision = 'target-wins' AND reason LIKE 'source deleted row violates target constraints%'"),
        1,
        'foreign-key delete violation records an auditable target-wins decision'
    );
    $fk_delete_conflict_id = (int)scalar($fk_delete_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_fk_delete_parents' AND conflict_type = 'row-target-constraint' ORDER BY id DESC LIMIT 1");
    assert_throws(
        fn() => cow_merge_resolve_conflict($fk_delete_metadata, $fk_delete_conflict_id, 'source', true, 'Try parent delete while child remains.', 'cow-test'),
        'FOREIGN KEY constraint failed',
        'source resolution for a foreign-key protected delete remains validation-gated by target children'
    );
    $db = open_db($fk_delete_target);
    $db->exec('DELETE FROM plugin_fk_delete_children WHERE id = 20');
    $db->close();
    $fk_delete_source_resolution = cow_merge_resolve_conflict(
        $fk_delete_metadata,
        $fk_delete_conflict_id,
        'source',
        true,
        'Apply parent delete after child review.',
        'cow-test'
    );
    assert_same($fk_delete_source_resolution['status'], 'applied', 'source foreign-key delete resolution applies after the child row is removed');
    assert_same((int)scalar($fk_delete_target, 'SELECT COUNT(*) FROM plugin_fk_delete_parents WHERE id = 1'), 0, 'foreign-key source delete resolution removes the audited parent row');

    $fk_delete_graph_base = $tmp . '/fk-delete-graph-base.sqlite';
    $fk_delete_graph_source = $tmp . '/fk-delete-graph-source.sqlite';
    $fk_delete_graph_target = $tmp . '/fk-delete-graph-target.sqlite';
    $fk_delete_graph_metadata = $tmp . '/.forkpress/cow/merge/fk-delete-graph-metadata.sqlite';
    create_base_db($fk_delete_graph_base);
    copy($fk_delete_graph_base, $fk_delete_graph_source);
    copy($fk_delete_graph_base, $fk_delete_graph_target);
    foreach ([$fk_delete_graph_base, $fk_delete_graph_source, $fk_delete_graph_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_fk_graph_parents (id INTEGER PRIMARY KEY, label TEXT)');
        $db->exec('CREATE TABLE plugin_fk_graph_children (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL REFERENCES plugin_fk_graph_parents(id), label TEXT)');
        $db->exec('CREATE TABLE plugin_fk_graph_grandchildren (id INTEGER PRIMARY KEY, child_id INTEGER NOT NULL REFERENCES plugin_fk_graph_children(id), label TEXT)');
        $db->exec("INSERT INTO plugin_fk_graph_parents (id, label) VALUES (1, 'base parent')");
        $db->exec("INSERT INTO plugin_fk_graph_children (id, parent_id, label) VALUES (20, 1, 'base child')");
        $db->exec("INSERT INTO plugin_fk_graph_grandchildren (id, child_id, label) VALUES (30, 20, 'base grandchild')");
        $db->close();
    }
    $db = open_db($fk_delete_graph_source);
    $db->exec('DELETE FROM plugin_fk_graph_grandchildren WHERE id = 30');
    $db->exec('DELETE FROM plugin_fk_graph_children WHERE id = 20');
    $db->exec('DELETE FROM plugin_fk_graph_parents WHERE id = 1');
    $db->close();
    $fk_delete_graph_result = cow_merge_databases($fk_delete_graph_base, $fk_delete_graph_source, $fk_delete_graph_target, $fk_delete_graph_metadata, 'feature-fk-delete-graph', 'main');
    assert_same($fk_delete_graph_result['status'], 'completed', 'source deleting a foreign-key parent and unchanged descendants applies without a false target constraint conflict');
    assert_same((int)scalar($fk_delete_graph_target, 'SELECT COUNT(*) FROM plugin_fk_graph_parents'), 0, 'foreign-key parent source delete is applied after source-deleted descendants are removed');
    assert_same((int)scalar($fk_delete_graph_target, 'SELECT COUNT(*) FROM plugin_fk_graph_children'), 0, 'foreign-key child source delete is applied before parent delete');
    assert_same((int)scalar($fk_delete_graph_target, 'SELECT COUNT(*) FROM plugin_fk_graph_grandchildren'), 0, 'foreign-key grandchild source delete is applied before child delete');
    assert_same(
        (int)scalar($fk_delete_graph_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE conflict_type = 'row-target-constraint' AND table_name LIKE 'plugin_fk_graph_%'"),
        0,
        'source-deleted foreign-key graph does not record target constraint conflicts when target descendants are unchanged'
    );
    assert_same(
        (int)scalar($fk_delete_graph_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name LIKE 'plugin_fk_graph_%' AND decision = 'source-applied' AND reason LIKE 'source%deleted row%'"),
        3,
        'source-deleted foreign-key graph records auditable source-applied delete decisions'
    );

    $fk_delete_update_base = $tmp . '/fk-delete-update-base.sqlite';
    $fk_delete_update_source = $tmp . '/fk-delete-update-source.sqlite';
    $fk_delete_update_target = $tmp . '/fk-delete-update-target.sqlite';
    $fk_delete_update_metadata = $tmp . '/.forkpress/cow/merge/fk-delete-update-metadata.sqlite';
    create_base_db($fk_delete_update_base);
    copy($fk_delete_update_base, $fk_delete_update_source);
    copy($fk_delete_update_base, $fk_delete_update_target);
    foreach ([$fk_delete_update_base, $fk_delete_update_source, $fk_delete_update_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_fk_update_delete_parents (id INTEGER PRIMARY KEY, label TEXT)');
        $db->exec('CREATE TABLE plugin_fk_update_delete_children (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL REFERENCES plugin_fk_update_delete_parents(id), label TEXT)');
        $db->exec("INSERT INTO plugin_fk_update_delete_parents (id, label) VALUES (1, 'old parent')");
        $db->exec("INSERT INTO plugin_fk_update_delete_parents (id, label) VALUES (2, 'kept parent')");
        $db->exec("INSERT INTO plugin_fk_update_delete_children (id, parent_id, label) VALUES (20, 1, 'base child')");
        $db->close();
    }
    $db = open_db($fk_delete_update_source);
    $db->exec("UPDATE plugin_fk_update_delete_children SET parent_id = 2, label = 'source child reparented' WHERE id = 20");
    $db->exec('DELETE FROM plugin_fk_update_delete_parents WHERE id = 1');
    $db->close();
    $fk_delete_update_result = cow_merge_databases($fk_delete_update_base, $fk_delete_update_source, $fk_delete_update_target, $fk_delete_update_metadata, 'feature-fk-delete-update', 'main');
    assert_same($fk_delete_update_result['status'], 'completed', 'source updating a foreign-key child away from a deleted parent applies without a false target constraint conflict');
    assert_same((int)scalar($fk_delete_update_target, 'SELECT COUNT(*) FROM plugin_fk_update_delete_parents WHERE id = 1'), 0, 'foreign-key parent source delete applies after source child update breaks the reference');
    assert_same((int)scalar($fk_delete_update_target, 'SELECT parent_id FROM plugin_fk_update_delete_children WHERE id = 20'), 2, 'foreign-key child source update is applied before parent delete');
    assert_same(scalar($fk_delete_update_target, 'SELECT label FROM plugin_fk_update_delete_children WHERE id = 20'), 'source child reparented', 'foreign-key child source payload is preserved');
    assert_same(
        (int)scalar($fk_delete_update_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE conflict_type = 'row-target-constraint' AND table_name LIKE 'plugin_fk_update_delete_%'"),
        0,
        'foreign-key child update plus parent delete does not record target constraint conflicts when target child is unchanged'
    );
    assert_same(
        (int)scalar($fk_delete_update_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_fk_update_delete_parents' AND decision = 'source-applied' AND reason = 'source deleted row and target did not change it'"),
        1,
        'foreign-key parent delete after child update remains auditable'
    );
    assert_same(
        (int)scalar($fk_delete_update_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_fk_update_delete_children' AND decision = 'source-applied' AND reason = 'source and target changed row to the same payload'"),
        1,
        'foreign-key child update after parent delete helper remains auditable'
    );

    $fk_key_rewrite_base = $tmp . '/fk-key-rewrite-base.sqlite';
    $fk_key_rewrite_source = $tmp . '/fk-key-rewrite-source.sqlite';
    $fk_key_rewrite_target = $tmp . '/fk-key-rewrite-target.sqlite';
    $fk_key_rewrite_metadata = $tmp . '/.forkpress/cow/merge/fk-key-rewrite-metadata.sqlite';
    create_base_db($fk_key_rewrite_base);
    copy($fk_key_rewrite_base, $fk_key_rewrite_source);
    copy($fk_key_rewrite_base, $fk_key_rewrite_target);
    foreach ([$fk_key_rewrite_base, $fk_key_rewrite_source, $fk_key_rewrite_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_fk_key_rewrite_parents (id INTEGER PRIMARY KEY, label TEXT)');
        $db->exec('CREATE TABLE plugin_fk_key_rewrite_children (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL REFERENCES plugin_fk_key_rewrite_parents(id), child_key TEXT NOT NULL UNIQUE, label TEXT)');
        $db->exec('CREATE TABLE plugin_fk_key_rewrite_grandchildren (id INTEGER PRIMARY KEY, child_key TEXT NOT NULL REFERENCES plugin_fk_key_rewrite_children(child_key), label TEXT)');
        $db->exec("INSERT INTO plugin_fk_key_rewrite_parents (id, label) VALUES (1, 'old parent'), (2, 'kept parent')");
        $db->exec("INSERT INTO plugin_fk_key_rewrite_children (id, parent_id, child_key, label) VALUES (20, 1, 'old-child-key', 'base child')");
        $db->exec("INSERT INTO plugin_fk_key_rewrite_grandchildren (id, child_key, label) VALUES (30, 'old-child-key', 'base grandchild')");
        $db->close();
    }
    $db = open_db($fk_key_rewrite_source);
    $db->exec("UPDATE plugin_fk_key_rewrite_children SET parent_id = 2, child_key = 'new-child-key', label = 'source child rewritten' WHERE id = 20");
    $db->exec("UPDATE plugin_fk_key_rewrite_grandchildren SET child_key = 'new-child-key', label = 'source grandchild rewritten' WHERE id = 30");
    $db->exec('DELETE FROM plugin_fk_key_rewrite_parents WHERE id = 1');
    $db->close();
    $fk_key_rewrite_result = cow_merge_databases($fk_key_rewrite_base, $fk_key_rewrite_source, $fk_key_rewrite_target, $fk_key_rewrite_metadata, 'feature-fk-key-rewrite', 'main');
    assert_same($fk_key_rewrite_result['status'], 'completed', 'source rewriting a referenced child key and dependent grandchild key applies before parent delete');
    assert_same((int)scalar($fk_key_rewrite_target, 'SELECT COUNT(*) FROM plugin_fk_key_rewrite_parents WHERE id = 1'), 0, 'foreign-key parent source delete applies after referenced child key rewrite');
    assert_same(scalar($fk_key_rewrite_target, 'SELECT child_key FROM plugin_fk_key_rewrite_children WHERE id = 20'), 'new-child-key', 'foreign-key child referenced key rewrite is applied');
    assert_same(scalar($fk_key_rewrite_target, 'SELECT child_key FROM plugin_fk_key_rewrite_grandchildren WHERE id = 30'), 'new-child-key', 'foreign-key grandchild update is applied before child referenced key rewrite');
    assert_same(
        (int)scalar($fk_key_rewrite_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE conflict_type = 'row-target-constraint' AND table_name LIKE 'plugin_fk_key_rewrite_%'"),
        0,
        'foreign-key referenced-key rewrite graph avoids target constraint conflicts when target rows are unchanged'
    );
    assert_same(
        (int)scalar($fk_key_rewrite_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_fk_key_rewrite_grandchildren' AND decision = 'source-applied' AND reason = 'source and target changed row to the same payload'"),
        1,
        'foreign-key grandchild rewrite remains auditable'
    );

    $fk_parent_rewrite_base = $tmp . '/fk-parent-rewrite-base.sqlite';
    $fk_parent_rewrite_source = $tmp . '/fk-parent-rewrite-source.sqlite';
    $fk_parent_rewrite_target = $tmp . '/fk-parent-rewrite-target.sqlite';
    $fk_parent_rewrite_metadata = $tmp . '/.forkpress/cow/merge/fk-parent-rewrite-metadata.sqlite';
    create_base_db($fk_parent_rewrite_base);
    copy($fk_parent_rewrite_base, $fk_parent_rewrite_source);
    copy($fk_parent_rewrite_base, $fk_parent_rewrite_target);
    foreach ([$fk_parent_rewrite_base, $fk_parent_rewrite_source, $fk_parent_rewrite_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_fk_parent_rewrite_parents (id INTEGER PRIMARY KEY, label TEXT)');
        $db->exec('CREATE TABLE plugin_fk_parent_rewrite_children (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL REFERENCES plugin_fk_parent_rewrite_parents(id), label TEXT)');
        $db->exec("INSERT INTO plugin_fk_parent_rewrite_parents (id, label) VALUES (1, 'old parent')");
        $db->exec("INSERT INTO plugin_fk_parent_rewrite_children (id, parent_id, label) VALUES (20, 1, 'base child')");
        $db->close();
    }
    $db = open_db($fk_parent_rewrite_source);
    $db->exec("INSERT INTO plugin_fk_parent_rewrite_parents (id, label) VALUES (2, 'source parent rewritten')");
    $db->exec("UPDATE plugin_fk_parent_rewrite_children SET parent_id = 2, label = 'source child follows rewritten parent' WHERE id = 20");
    $db->exec('DELETE FROM plugin_fk_parent_rewrite_parents WHERE id = 1');
    $db->close();
    $fk_parent_rewrite_result = cow_merge_databases($fk_parent_rewrite_base, $fk_parent_rewrite_source, $fk_parent_rewrite_target, $fk_parent_rewrite_metadata, 'feature-fk-parent-rewrite', 'main');
    assert_same($fk_parent_rewrite_result['status'], 'completed', 'source primary-key rewrite materializes the new parent before dependent child update');
    assert_same((int)scalar($fk_parent_rewrite_target, 'SELECT COUNT(*) FROM plugin_fk_parent_rewrite_parents WHERE id = 1'), 0, 'foreign-key old parent is deleted after dependent rewrite validates');
    assert_same(scalar($fk_parent_rewrite_target, 'SELECT label FROM plugin_fk_parent_rewrite_parents WHERE id = 2'), 'source parent rewritten', 'foreign-key rewritten source parent is materialized');
    assert_same((int)scalar($fk_parent_rewrite_target, 'SELECT parent_id FROM plugin_fk_parent_rewrite_children WHERE id = 20'), 2, 'foreign-key child points at the materialized source parent');
    assert_same(
        (int)scalar($fk_parent_rewrite_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE conflict_type = 'row-target-constraint' AND table_name LIKE 'plugin_fk_parent_rewrite_%'"),
        0,
        'source primary-key rewrite graph avoids target constraint conflicts when target rows are unchanged'
    );
    assert_same(
        (int)scalar($fk_parent_rewrite_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_fk_parent_rewrite_parents' AND row_identity = '" . SQLite3::escapeString(cow_merge_identity_json(['id' => 2])) . "' AND decision = 'source-applied' AND reason = 'source inserted row before dependent foreign-key rewrite'"),
        1,
        'materialized source parent insert is auditable'
    );

    $fk_keyless_parent_rewrite_base = $tmp . '/fk-keyless-parent-rewrite-base.sqlite';
    $fk_keyless_parent_rewrite_source = $tmp . '/fk-keyless-parent-rewrite-source.sqlite';
    $fk_keyless_parent_rewrite_target = $tmp . '/fk-keyless-parent-rewrite-target.sqlite';
    $fk_keyless_parent_rewrite_metadata = $tmp . '/.forkpress/cow/merge/fk-keyless-parent-rewrite-metadata.sqlite';
    create_base_db($fk_keyless_parent_rewrite_base);
    copy($fk_keyless_parent_rewrite_base, $fk_keyless_parent_rewrite_source);
    copy($fk_keyless_parent_rewrite_base, $fk_keyless_parent_rewrite_target);
    foreach ([$fk_keyless_parent_rewrite_base, $fk_keyless_parent_rewrite_source, $fk_keyless_parent_rewrite_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_fk_keyless_parent_rewrite_parents (code TEXT NOT NULL UNIQUE, label TEXT)');
        $db->exec('CREATE TABLE plugin_fk_keyless_parent_rewrite_children (id INTEGER PRIMARY KEY, parent_code TEXT NOT NULL REFERENCES plugin_fk_keyless_parent_rewrite_parents(code), label TEXT)');
        $db->exec("INSERT INTO plugin_fk_keyless_parent_rewrite_parents (rowid, code, label) VALUES (5, 'old-keyless-parent', 'old keyless parent')");
        $db->exec("INSERT INTO plugin_fk_keyless_parent_rewrite_children (id, parent_code, label) VALUES (20, 'old-keyless-parent', 'base child')");
        $db->close();
    }
    $db = open_db($fk_keyless_parent_rewrite_source);
    $db->exec("INSERT INTO plugin_fk_keyless_parent_rewrite_parents (rowid, code, label) VALUES (13, 'new-keyless-parent', 'source keyless parent')");
    $db->exec("UPDATE plugin_fk_keyless_parent_rewrite_children SET parent_code = 'new-keyless-parent', label = 'source child follows keyless parent' WHERE id = 20");
    $db->exec("DELETE FROM plugin_fk_keyless_parent_rewrite_parents WHERE code = 'old-keyless-parent'");
    $db->close();
    $fk_keyless_parent_rewrite_result = cow_merge_databases($fk_keyless_parent_rewrite_base, $fk_keyless_parent_rewrite_source, $fk_keyless_parent_rewrite_target, $fk_keyless_parent_rewrite_metadata, 'feature-fk-keyless-parent-rewrite', 'main');
    assert_same($fk_keyless_parent_rewrite_result['status'], 'completed', 'source no-primary-key parent rewrite materializes the source parent before dependent child update');
    assert_same((int)scalar($fk_keyless_parent_rewrite_target, "SELECT COUNT(*) FROM plugin_fk_keyless_parent_rewrite_parents WHERE code = 'old-keyless-parent'"), 0, 'old no-primary-key foreign-key parent is deleted after dependent rewrite validates');
    assert_same(scalar($fk_keyless_parent_rewrite_target, 'SELECT code FROM plugin_fk_keyless_parent_rewrite_parents WHERE rowid = 13'), 'new-keyless-parent', 'materialized no-primary-key parent preserves the sparse source rowid');
    assert_same(scalar($fk_keyless_parent_rewrite_target, 'SELECT parent_code FROM plugin_fk_keyless_parent_rewrite_children WHERE id = 20'), 'new-keyless-parent', 'foreign-key child points at the materialized no-primary-key source parent');
    $fk_keyless_parent_rewrite_source_identity = (string)scalar($fk_keyless_parent_rewrite_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'feature-fk-keyless-parent-rewrite' AND table_name = 'plugin_fk_keyless_parent_rewrite_parents' AND rowid = 13");
    assert_same(
        scalar($fk_keyless_parent_rewrite_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_fk_keyless_parent_rewrite_parents' AND rowid = 13"),
        $fk_keyless_parent_rewrite_source_identity,
        'materialized no-primary-key parent adopts the source sidecar identity at the preserved rowid'
    );
    assert_same(
        scalar($fk_keyless_parent_rewrite_metadata, "SELECT row_hash FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_fk_keyless_parent_rewrite_parents' AND rowid = 13"),
        cow_merge_row_hash(['code' => 'new-keyless-parent', 'label' => 'source keyless parent']),
        'materialized no-primary-key parent sidecar hash matches the target row'
    );
    assert_same(
        (int)scalar($fk_keyless_parent_rewrite_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE conflict_type = 'row-target-constraint' AND table_name LIKE 'plugin_fk_keyless_parent_rewrite_%'"),
        0,
        'no-primary-key parent materialization avoids target constraint conflicts when target rows are unchanged'
    );
    $fk_keyless_parent_rewrite_decision_identity = cow_merge_identity_json(cow_merge_decode_row_identity($fk_keyless_parent_rewrite_source_identity, 'keyless parent materialization'));
    assert_same(
        (int)scalar($fk_keyless_parent_rewrite_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_fk_keyless_parent_rewrite_parents' AND row_identity = '" . SQLite3::escapeString($fk_keyless_parent_rewrite_decision_identity) . "' AND decision = 'source-applied' AND reason = 'source inserted row before dependent foreign-key rewrite'"),
        1,
        'materialized no-primary-key parent insert is auditable'
    );

    $fk_keyless_parent_occupied_base = $tmp . '/fk-keyless-parent-occupied-base.sqlite';
    $fk_keyless_parent_occupied_source = $tmp . '/fk-keyless-parent-occupied-source.sqlite';
    $fk_keyless_parent_occupied_target = $tmp . '/fk-keyless-parent-occupied-target.sqlite';
    $fk_keyless_parent_occupied_metadata = $tmp . '/.forkpress/cow/merge/fk-keyless-parent-occupied-metadata.sqlite';
    create_base_db($fk_keyless_parent_occupied_base);
    copy($fk_keyless_parent_occupied_base, $fk_keyless_parent_occupied_source);
    copy($fk_keyless_parent_occupied_base, $fk_keyless_parent_occupied_target);
    foreach ([$fk_keyless_parent_occupied_base, $fk_keyless_parent_occupied_source, $fk_keyless_parent_occupied_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_fk_keyless_parent_occupied_parents (code TEXT NOT NULL UNIQUE, label TEXT)');
        $db->exec('CREATE TABLE plugin_fk_keyless_parent_occupied_children (id INTEGER PRIMARY KEY, parent_code TEXT NOT NULL REFERENCES plugin_fk_keyless_parent_occupied_parents(code), label TEXT)');
        $db->exec("INSERT INTO plugin_fk_keyless_parent_occupied_parents (rowid, code, label) VALUES (5, 'old-occupied-parent', 'old occupied parent')");
        $db->exec("INSERT INTO plugin_fk_keyless_parent_occupied_children (id, parent_code, label) VALUES (20, 'old-occupied-parent', 'base occupied child')");
        $db->close();
    }
    $db = open_db($fk_keyless_parent_occupied_source);
    $db->exec("INSERT INTO plugin_fk_keyless_parent_occupied_parents (rowid, code, label) VALUES (13, 'new-occupied-parent', 'source occupied parent')");
    $db->exec("UPDATE plugin_fk_keyless_parent_occupied_children SET parent_code = 'new-occupied-parent', label = 'source child follows occupied parent' WHERE id = 20");
    $db->exec("DELETE FROM plugin_fk_keyless_parent_occupied_parents WHERE code = 'old-occupied-parent'");
    $db->close();
    $db = open_db($fk_keyless_parent_occupied_target);
    $db->exec("INSERT INTO plugin_fk_keyless_parent_occupied_parents (rowid, code, label) VALUES (13, 'target-rowid-occupant', 'target rowid occupant')");
    $db->close();
    $fk_keyless_parent_occupied_result = cow_merge_databases($fk_keyless_parent_occupied_base, $fk_keyless_parent_occupied_source, $fk_keyless_parent_occupied_target, $fk_keyless_parent_occupied_metadata, 'feature-fk-keyless-parent-occupied', 'main');
    assert_same($fk_keyless_parent_occupied_result['status'], 'completed', 'source no-primary-key parent rewrite materializes at a fresh rowid when the source rowid is occupied');
    assert_same((int)scalar($fk_keyless_parent_occupied_target, "SELECT COUNT(*) FROM plugin_fk_keyless_parent_occupied_parents WHERE code = 'old-occupied-parent'"), 0, 'occupied-rowid rewrite still deletes the old parent after dependent rewrite validates');
    assert_same(scalar($fk_keyless_parent_occupied_target, 'SELECT code FROM plugin_fk_keyless_parent_occupied_parents WHERE rowid = 13'), 'target-rowid-occupant', 'occupied target rowid remains untouched during no-primary-key parent materialization');
    assert_same(scalar($fk_keyless_parent_occupied_target, 'SELECT code FROM plugin_fk_keyless_parent_occupied_parents WHERE rowid = 14'), 'new-occupied-parent', 'materialized no-primary-key parent falls back to a fresh target rowid');
    assert_same(scalar($fk_keyless_parent_occupied_target, 'SELECT parent_code FROM plugin_fk_keyless_parent_occupied_children WHERE id = 20'), 'new-occupied-parent', 'foreign-key child points at the freshly materialized no-primary-key source parent');
    $fk_keyless_parent_occupied_source_identity = (string)scalar($fk_keyless_parent_occupied_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'feature-fk-keyless-parent-occupied' AND table_name = 'plugin_fk_keyless_parent_occupied_parents' AND rowid = 13");
    assert_same(
        scalar($fk_keyless_parent_occupied_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_fk_keyless_parent_occupied_parents' AND rowid = 14"),
        $fk_keyless_parent_occupied_source_identity,
        'fresh-rowid no-primary-key parent materialization adopts the source sidecar identity'
    );
    assert_same(
        scalar($fk_keyless_parent_occupied_metadata, "SELECT row_hash FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_fk_keyless_parent_occupied_parents' AND rowid = 14"),
        cow_merge_row_hash(['code' => 'new-occupied-parent', 'label' => 'source occupied parent']),
        'fresh-rowid no-primary-key parent sidecar hash matches the materialized target row'
    );
    assert_same(
        (int)scalar($fk_keyless_parent_occupied_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE conflict_type = 'row-target-constraint' AND table_name LIKE 'plugin_fk_keyless_parent_occupied_%'"),
        0,
        'occupied source rowid fallback avoids target constraint conflicts when target rows are otherwise unchanged'
    );
    $fk_keyless_parent_occupied_decision_identity = cow_merge_identity_json(cow_merge_decode_row_identity($fk_keyless_parent_occupied_source_identity, 'occupied keyless parent materialization'));
    assert_same(
        (int)scalar($fk_keyless_parent_occupied_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_fk_keyless_parent_occupied_parents' AND row_identity = '" . SQLite3::escapeString($fk_keyless_parent_occupied_decision_identity) . "' AND decision = 'source-applied' AND reason = 'source inserted row before dependent foreign-key rewrite'"),
        1,
        'fresh-rowid no-primary-key parent materialization remains auditable under the source identity'
    );

    $fk_keyless_update_base = $tmp . '/fk-keyless-update-base.sqlite';
    $fk_keyless_update_source = $tmp . '/fk-keyless-update-source.sqlite';
    $fk_keyless_update_target = $tmp . '/fk-keyless-update-target.sqlite';
    $fk_keyless_update_metadata = $tmp . '/.forkpress/cow/merge/fk-keyless-update-metadata.sqlite';
    create_base_db($fk_keyless_update_base);
    copy($fk_keyless_update_base, $fk_keyless_update_source);
    copy($fk_keyless_update_base, $fk_keyless_update_target);
    foreach ([$fk_keyless_update_base, $fk_keyless_update_source, $fk_keyless_update_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_fk_keyless_update_parents (id INTEGER PRIMARY KEY, label TEXT)');
        $db->exec('CREATE TABLE plugin_fk_keyless_update_children (parent_id INTEGER NOT NULL REFERENCES plugin_fk_keyless_update_parents(id), label TEXT)');
        $db->exec("INSERT INTO plugin_fk_keyless_update_parents (id, label) VALUES (1, 'old keyless parent')");
        $db->exec("INSERT INTO plugin_fk_keyless_update_parents (id, label) VALUES (2, 'kept keyless parent')");
        $db->exec("INSERT INTO plugin_fk_keyless_update_children (rowid, parent_id, label) VALUES (7, 1, 'base keyless child')");
        $db->close();
    }
    $db = open_db($fk_keyless_update_source);
    $db->exec("UPDATE plugin_fk_keyless_update_children SET parent_id = 2, label = 'source keyless child reparented' WHERE rowid = 7");
    $db->exec('DELETE FROM plugin_fk_keyless_update_parents WHERE id = 1');
    $db->close();
    $fk_keyless_update_result = cow_merge_databases($fk_keyless_update_base, $fk_keyless_update_source, $fk_keyless_update_target, $fk_keyless_update_metadata, 'feature-fk-keyless-update', 'main');
    assert_same($fk_keyless_update_result['status'], 'completed', 'source updating a no-primary-key foreign-key child before parent delete applies cleanly');
    assert_same((int)scalar($fk_keyless_update_target, 'SELECT COUNT(*) FROM plugin_fk_keyless_update_parents WHERE id = 1'), 0, 'no-primary-key foreign-key parent source delete applies after child reparent');
    assert_same((int)scalar($fk_keyless_update_target, 'SELECT parent_id FROM plugin_fk_keyless_update_children WHERE rowid = 7'), 2, 'no-primary-key foreign-key child keeps its sparse rowid while reparenting');
    assert_same(scalar($fk_keyless_update_target, 'SELECT label FROM plugin_fk_keyless_update_children WHERE rowid = 7'), 'source keyless child reparented', 'no-primary-key foreign-key child source payload is preserved');
    assert_same(
        scalar($fk_keyless_update_metadata, "SELECT row_hash FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_fk_keyless_update_children' AND rowid = 7"),
        cow_merge_row_hash(['parent_id' => 2, 'label' => 'source keyless child reparented']),
        'no-primary-key foreign-key child update refreshes target sidecar row hash immediately'
    );
    assert_same(
        (int)scalar($fk_keyless_update_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE conflict_type = 'row-target-constraint' AND table_name LIKE 'plugin_fk_keyless_update_%'"),
        0,
        'no-primary-key foreign-key child update plus parent delete avoids target constraint conflicts'
    );

    $fk_mixed_rollback_base = $tmp . '/fk-mixed-rollback-base.sqlite';
    $fk_mixed_rollback_source = $tmp . '/fk-mixed-rollback-source.sqlite';
    $fk_mixed_rollback_target = $tmp . '/fk-mixed-rollback-target.sqlite';
    $fk_mixed_rollback_metadata = $tmp . '/.forkpress/cow/merge/fk-mixed-rollback-metadata.sqlite';
    create_base_db($fk_mixed_rollback_base);
    copy($fk_mixed_rollback_base, $fk_mixed_rollback_source);
    copy($fk_mixed_rollback_base, $fk_mixed_rollback_target);
    foreach ([$fk_mixed_rollback_base, $fk_mixed_rollback_source, $fk_mixed_rollback_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_fk_mixed_rollback_parents (id INTEGER PRIMARY KEY, label TEXT)');
        $db->exec("CREATE TABLE plugin_fk_mixed_rollback_children (parent_id INTEGER NOT NULL REFERENCES plugin_fk_mixed_rollback_parents(id), label TEXT CHECK(label != 'bad'))");
        $db->exec("INSERT INTO plugin_fk_mixed_rollback_parents (id, label) VALUES (1, 'old rollback parent'), (2, 'kept rollback parent')");
        $db->exec("INSERT INTO plugin_fk_mixed_rollback_children (rowid, parent_id, label) VALUES (7, 1, 'delete me'), (9, 1, 'base child')");
        $db->close();
    }
    $db = open_db($fk_mixed_rollback_source);
    $db->exec('PRAGMA ignore_check_constraints = ON');
    $db->exec('DELETE FROM plugin_fk_mixed_rollback_children WHERE rowid = 7');
    $db->exec("UPDATE plugin_fk_mixed_rollback_children SET parent_id = 2, label = 'bad' WHERE rowid = 9");
    $db->exec('DELETE FROM plugin_fk_mixed_rollback_parents WHERE id = 1');
    $db->close();
    $fk_mixed_rollback_meta_db = open_db($fk_mixed_rollback_metadata);
    cow_merge_ensure_metadata($fk_mixed_rollback_meta_db);
    $fk_mixed_rollback_run_id = cow_merge_start_run(
        $fk_mixed_rollback_meta_db,
        'feature-fk-mixed-rollback-direct',
        'main',
        $fk_mixed_rollback_base,
        $fk_mixed_rollback_source,
        $fk_mixed_rollback_target
    );
    $fk_mixed_rollback_base_db = open_db($fk_mixed_rollback_base);
    $fk_mixed_rollback_source_db = open_db($fk_mixed_rollback_source);
    $fk_mixed_rollback_target_db = open_db($fk_mixed_rollback_target);
    $fk_mixed_direct_result = cow_merge_try_delete_row_with_source_deleted_children(
        $fk_mixed_rollback_base_db,
        $fk_mixed_rollback_source_db,
        $fk_mixed_rollback_target_db,
        $fk_mixed_rollback_meta_db,
        $fk_mixed_rollback_run_id,
        'feature-fk-mixed-rollback-direct',
        'main',
        'plugin_fk_mixed_rollback_parents',
        ['id' => 1],
        ['id'],
        ['id' => 1, 'label' => 'old rollback parent']
    );
    assert_same($fk_mixed_direct_result['ok'] ?? null, false, 'mixed foreign-key dependent delete/update fails as one validation unit when a later update violates target constraints');
    assert_same((int)$fk_mixed_rollback_target_db->querySingle('SELECT COUNT(*) FROM plugin_fk_mixed_rollback_children WHERE rowid = 7'), 1, 'failed mixed foreign-key dependent update rolls back the earlier dependent delete');
    assert_same($fk_mixed_rollback_target_db->querySingle('SELECT label FROM plugin_fk_mixed_rollback_children WHERE rowid = 9'), 'base child', 'failed mixed foreign-key dependent update leaves the later child row unchanged');
    assert_same((int)$fk_mixed_rollback_target_db->querySingle('SELECT COUNT(*) FROM plugin_fk_mixed_rollback_parents WHERE id = 1'), 1, 'failed mixed foreign-key dependent update keeps the parent row');
    $fk_mixed_rollback_base_db->close();
    $fk_mixed_rollback_source_db->close();
    $fk_mixed_rollback_target_db->close();
    $fk_mixed_rollback_meta_db->close();

    copy($fk_mixed_rollback_base, $fk_mixed_rollback_target);
    $fk_mixed_rollback_result = cow_merge_databases($fk_mixed_rollback_base, $fk_mixed_rollback_source, $fk_mixed_rollback_target, $fk_mixed_rollback_metadata, 'feature-fk-mixed-rollback', 'main');
    assert_same($fk_mixed_rollback_result['status'], 'completed_with_conflicts', 'mixed no-primary-key foreign-key dependent rollback is audited as a target constraint conflict');
    assert_same((int)scalar($fk_mixed_rollback_target, 'SELECT COUNT(*) FROM plugin_fk_mixed_rollback_parents WHERE id = 1'), 1, 'mixed rollback keeps the foreign-key parent by default');
    assert_same(scalar($fk_mixed_rollback_target, 'SELECT label FROM plugin_fk_mixed_rollback_children WHERE rowid = 9'), 'base child', 'mixed rollback keeps the invalid source child update out by default');
    assert_same(
        (int)scalar($fk_mixed_rollback_metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_fk_mixed_rollback_parents' AND c.conflict_type = 'row-target-constraint' AND r.source_branch = 'feature-fk-mixed-rollback'"),
        1,
        'mixed no-primary-key foreign-key rollback records an auditable row-target-constraint conflict'
    );

    $fk_multi_sidecar_rollback_base = $tmp . '/fk-multi-sidecar-rollback-base.sqlite';
    $fk_multi_sidecar_rollback_source = $tmp . '/fk-multi-sidecar-rollback-source.sqlite';
    $fk_multi_sidecar_rollback_target = $tmp . '/fk-multi-sidecar-rollback-target.sqlite';
    $fk_multi_sidecar_rollback_metadata = $tmp . '/.forkpress/cow/merge/fk-multi-sidecar-rollback-metadata.sqlite';
    create_base_db($fk_multi_sidecar_rollback_base);
    copy($fk_multi_sidecar_rollback_base, $fk_multi_sidecar_rollback_source);
    copy($fk_multi_sidecar_rollback_base, $fk_multi_sidecar_rollback_target);
    foreach ([$fk_multi_sidecar_rollback_base, $fk_multi_sidecar_rollback_source, $fk_multi_sidecar_rollback_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_fk_multi_sidecar_old_parent (id INTEGER PRIMARY KEY, label TEXT)');
        $db->exec('CREATE TABLE plugin_fk_multi_sidecar_parent_a (code TEXT NOT NULL UNIQUE, label TEXT)');
        $db->exec('CREATE TABLE plugin_fk_multi_sidecar_parent_b (code TEXT NOT NULL UNIQUE, label TEXT)');
        $db->exec(
            "CREATE TABLE plugin_fk_multi_sidecar_child (" .
            "old_parent_id INTEGER REFERENCES plugin_fk_multi_sidecar_old_parent(id), " .
            "parent_a_code TEXT REFERENCES plugin_fk_multi_sidecar_parent_a(code), " .
            "parent_b_code TEXT REFERENCES plugin_fk_multi_sidecar_parent_b(code), " .
            "label TEXT CHECK(label != 'bad'))"
        );
        $db->exec("INSERT INTO plugin_fk_multi_sidecar_old_parent (id, label) VALUES (1, 'old multi parent')");
        $db->exec("INSERT INTO plugin_fk_multi_sidecar_child (rowid, old_parent_id, parent_a_code, parent_b_code, label) VALUES (5, 1, NULL, NULL, 'base multi child')");
        $db->close();
    }
    cow_merge_capture_row_identities($fk_multi_sidecar_rollback_base, $fk_multi_sidecar_rollback_metadata, 'main');
    cow_merge_capture_row_identities($fk_multi_sidecar_rollback_source, $fk_multi_sidecar_rollback_metadata, 'feature-fk-multi-sidecar-rollback', 'main');
    cow_merge_capture_row_identities($fk_multi_sidecar_rollback_target, $fk_multi_sidecar_rollback_metadata, 'main');
    $db = open_db($fk_multi_sidecar_rollback_source);
    $db->exec('PRAGMA ignore_check_constraints = ON');
    $db->exec("INSERT INTO plugin_fk_multi_sidecar_parent_a (rowid, code, label) VALUES (13, 'parent-a', 'source parent A')");
    $db->exec("INSERT INTO plugin_fk_multi_sidecar_parent_b (rowid, code, label) VALUES (17, 'parent-b', 'source parent B')");
    $db->exec("UPDATE plugin_fk_multi_sidecar_child SET old_parent_id = NULL, parent_a_code = 'parent-a', parent_b_code = 'parent-b', label = 'bad' WHERE rowid = 5");
    $db->exec('DELETE FROM plugin_fk_multi_sidecar_old_parent WHERE id = 1');
    $db->close();
    cow_merge_capture_row_identities($fk_multi_sidecar_rollback_source, $fk_multi_sidecar_rollback_metadata, 'feature-fk-multi-sidecar-rollback', 'main');
    $fk_multi_sidecar_meta_db = open_db($fk_multi_sidecar_rollback_metadata);
    cow_merge_ensure_metadata($fk_multi_sidecar_meta_db);
    $fk_multi_sidecar_run_id = cow_merge_start_run(
        $fk_multi_sidecar_meta_db,
        'feature-fk-multi-sidecar-rollback-direct',
        'main',
        $fk_multi_sidecar_rollback_base,
        $fk_multi_sidecar_rollback_source,
        $fk_multi_sidecar_rollback_target
    );
    $fk_multi_sidecar_base_db = open_db($fk_multi_sidecar_rollback_base);
    $fk_multi_sidecar_source_db = open_db($fk_multi_sidecar_rollback_source);
    $fk_multi_sidecar_target_db = open_db($fk_multi_sidecar_rollback_target);
    $fk_multi_sidecar_result = cow_merge_try_delete_row_with_source_deleted_children(
        $fk_multi_sidecar_base_db,
        $fk_multi_sidecar_source_db,
        $fk_multi_sidecar_target_db,
        $fk_multi_sidecar_meta_db,
        $fk_multi_sidecar_run_id,
        'feature-fk-multi-sidecar-rollback',
        'main',
        'plugin_fk_multi_sidecar_old_parent',
        ['id' => 1],
        ['id'],
        ['id' => 1, 'label' => 'old multi parent']
    );
    assert_same($fk_multi_sidecar_result['ok'] ?? null, false, 'multi-table no-primary-key foreign-key rewrite rolls back when a later child update fails validation');
    assert_same((int)$fk_multi_sidecar_target_db->querySingle('SELECT COUNT(*) FROM plugin_fk_multi_sidecar_parent_a'), 0, 'failed multi-table rollback removes the first materialized no-primary-key parent row');
    assert_same((int)$fk_multi_sidecar_target_db->querySingle('SELECT COUNT(*) FROM plugin_fk_multi_sidecar_parent_b'), 0, 'failed multi-table rollback removes the second materialized no-primary-key parent row');
    assert_same((int)$fk_multi_sidecar_target_db->querySingle('SELECT COUNT(*) FROM plugin_fk_multi_sidecar_old_parent WHERE id = 1'), 1, 'failed multi-table rollback keeps the deleted parent row');
    assert_same($fk_multi_sidecar_target_db->querySingle('SELECT label FROM plugin_fk_multi_sidecar_child WHERE rowid = 5'), 'base multi child', 'failed multi-table rollback keeps the no-primary-key child row unchanged');
    assert_same(
        (int)$fk_multi_sidecar_meta_db->querySingle("SELECT COUNT(*) FROM merge_row_identities WHERE branch_name = 'main' AND table_name IN ('plugin_fk_multi_sidecar_parent_a', 'plugin_fk_multi_sidecar_parent_b')"),
        0,
        'failed multi-table rollback does not adopt source sidecar identities for staged parent materializations'
    );
    assert_same(
        (int)$fk_multi_sidecar_meta_db->querySingle("SELECT COUNT(*) FROM merge_row_identity_history WHERE branch_name = 'main' AND table_name IN ('plugin_fk_multi_sidecar_parent_a', 'plugin_fk_multi_sidecar_parent_b')"),
        0,
        'failed multi-table rollback does not write source parent sidecar history'
    );
    $fk_multi_sidecar_base_db->close();
    $fk_multi_sidecar_source_db->close();
    $fk_multi_sidecar_target_db->close();
    $fk_multi_sidecar_meta_db->close();

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
    $reviewed_db_decision_id = (int)scalar($metadata, "SELECT id FROM merge_decisions WHERE table_name = 'plugin_items' AND column_name = 'value' AND decision = 'target-kept' ORDER BY id DESC LIMIT 1");
    $unreviewed_db_decision_id = (int)scalar($metadata, "SELECT id FROM merge_decisions WHERE table_name = 'wp_posts' AND column_name IS NULL AND decision = 'target-kept' ORDER BY id DESC LIMIT 1");
    $db_decision_review = cow_merge_review_record(
        $metadata,
        'decision',
        $reviewed_db_decision_id,
        'reviewed',
        'Target-only plugin value is intentional.',
        'cow-test'
    );
    assert_same($db_decision_review['record_type'], 'decision', 'review note can target a database decision record');
    $db_decision_queue_audit = cow_merge_audit_report($metadata, null, 10, [
        'review' => '1',
        'review_status' => 'unreviewed',
        'records' => 'decisions',
        'scope' => 'db',
    ]);
    assert_same($db_decision_queue_audit['filters']['review'], true, 'database decision review queue preserves the review shortcut filter');
    assert_same($db_decision_queue_audit['filters']['review_status'], 'unreviewed', 'database decision review queue preserves the unreviewed filter');
    assert_same($db_decision_queue_audit['filters']['records'], 'decisions', 'database decision review queue focuses on decision records');
    assert_same($db_decision_queue_audit['filters']['scope'], 'db', 'database decision review queue focuses on database records');
    assert_same(count($db_decision_queue_audit['conflicts']), 0, 'database decision review queue omits conflicts');
    assert_same(count($db_decision_queue_audit['resolutions']), 0, 'database decision review queue omits resolutions');
    $db_decision_queue_ids = array_map(fn($row) => (int)$row['id'], $db_decision_queue_audit['decisions']);
    assert_true(in_array($unreviewed_db_decision_id, $db_decision_queue_ids, true), 'database decision review queue returns unreviewed DB decisions');
    assert_true(!in_array($reviewed_db_decision_id, $db_decision_queue_ids, true), 'database decision review queue excludes reviewed DB decisions');
    foreach ($db_decision_queue_audit['decisions'] as $row) {
        assert_same($row['review_status'], null, 'database decision review queue returns only unreviewed records');
        assert_true($row['table_name'] !== '__files__', 'database decision review queue excludes file records');
    }
    $reviewed_db_decision_audit = cow_merge_audit_report($metadata, null, 10, [
        'records' => 'decisions',
        'review_status' => 'reviewed',
        'scope' => 'db',
    ]);
    assert_same(count($reviewed_db_decision_audit['decisions']), 1, 'review status filter returns annotated DB decisions');
    assert_same((int)$reviewed_db_decision_audit['decisions'][0]['id'], $reviewed_db_decision_id, 'reviewed DB decision filter returns the annotated decision');
    assert_same($reviewed_db_decision_audit['decisions'][0]['review_note'], 'Target-only plugin value is intentional.', 'merge audit JSON exposes latest DB decision review note');
    $reviewed_db_decision_closure_audit = cow_merge_audit_report($metadata, null, 10, [
        'review' => '1',
        'review_status' => 'reviewed',
        'records' => 'decisions',
        'scope' => 'db',
    ]);
    assert_same($reviewed_db_decision_closure_audit['filters']['review'], true, 'reviewed DB decision closure report preserves the review shortcut filter');
    assert_same($reviewed_db_decision_closure_audit['filters']['review_status'], 'reviewed', 'reviewed DB decision closure report preserves the reviewed filter');
    assert_same($reviewed_db_decision_closure_audit['filters']['records'], 'decisions', 'reviewed DB decision closure report focuses on decision records');
    assert_same($reviewed_db_decision_closure_audit['filters']['scope'], 'db', 'reviewed DB decision closure report focuses on database records');
    assert_same(count($reviewed_db_decision_closure_audit['conflicts']), 0, 'reviewed DB decision closure report omits conflicts');
    assert_same(count($reviewed_db_decision_closure_audit['resolutions']), 0, 'reviewed DB decision closure report omits resolutions');
    $reviewed_db_decision_closure_ids = array_map(fn($row) => (int)$row['id'], $reviewed_db_decision_closure_audit['decisions']);
    assert_true(in_array($reviewed_db_decision_id, $reviewed_db_decision_closure_ids, true), 'reviewed DB decision closure report returns reviewed DB decisions');
    assert_true(!in_array($unreviewed_db_decision_id, $reviewed_db_decision_closure_ids, true), 'reviewed DB decision closure report excludes unreviewed DB decisions');
    foreach ($reviewed_db_decision_closure_audit['decisions'] as $row) {
        assert_same($row['review_status'], 'reviewed', 'reviewed DB decision closure report returns only reviewed decisions');
        assert_true($row['table_name'] !== '__files__', 'reviewed DB decision closure report excludes file records');
    }

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

    $keyless_source_table_base = $tmp . '/keyless-source-table-base.sqlite';
    $keyless_source_table_source = $tmp . '/keyless-source-table-source.sqlite';
    $keyless_source_table_target = $tmp . '/keyless-source-table-target.sqlite';
    $keyless_source_table_metadata = $tmp . '/.forkpress/cow/merge/keyless-source-table-metadata.sqlite';
    create_base_db($keyless_source_table_base);
    copy($keyless_source_table_base, $keyless_source_table_source);
    copy($keyless_source_table_base, $keyless_source_table_target);
    $db = open_db($keyless_source_table_source);
    $db->exec('CREATE TABLE plugin_keyless_source_table (label TEXT, value TEXT)');
    $db->exec('CREATE UNIQUE INDEX plugin_keyless_source_table_label_idx ON plugin_keyless_source_table(label)');
    $db->exec(
        "CREATE TRIGGER plugin_keyless_source_table_insert AFTER INSERT ON plugin_keyless_source_table BEGIN " .
        "INSERT INTO plugin_items (item_id, label, value) VALUES ('keyless-source-' || NEW.rowid, NEW.label, NEW.value); " .
        'END'
    );
    $db->exec("INSERT INTO plugin_keyless_source_table (rowid, label, value) VALUES (3, 'Sparse source table alpha', 'alpha')");
    $db->exec("INSERT INTO plugin_keyless_source_table (rowid, label, value) VALUES (11, 'Sparse source table beta', 'beta')");
    $db->close();
    $keyless_source_table_result = cow_merge_databases(
        $keyless_source_table_base,
        $keyless_source_table_source,
        $keyless_source_table_target,
        $keyless_source_table_metadata,
        'feature-keyless-source-table',
        'main'
    );
    assert_same($keyless_source_table_result['status'], 'completed', 'source-added no-primary-key table merges cleanly');
    assert_same((int)scalar($keyless_source_table_target, "SELECT COUNT(*) FROM plugin_keyless_source_table WHERE rowid IN (3, 11)"), 2, 'source-added no-primary-key table preserves sparse source rowids');
    assert_same((int)scalar($keyless_source_table_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_keyless_source_table_label_idx'"), 1, 'source-added no-primary-key table restores its source index');
    assert_same((int)scalar($keyless_source_table_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_keyless_source_table_insert'"), 1, 'source-added no-primary-key table restores its source trigger');
    $db = open_db($keyless_source_table_target);
    $db->exec("INSERT INTO plugin_keyless_source_table (rowid, label, value) VALUES (19, 'Sparse source table gamma', 'gamma')");
    $db->close();
    assert_same(scalar($keyless_source_table_target, "SELECT value FROM plugin_items WHERE item_id = 'keyless-source-19'"), 'gamma', 'source-added no-primary-key table trigger remains functional on target');
    assert_same(
        scalar($keyless_source_table_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_keyless_source_table' AND rowid = 11"),
        scalar($keyless_source_table_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'feature-keyless-source-table' AND table_name = 'plugin_keyless_source_table' AND rowid = 11"),
        'source-added no-primary-key table adopts source sidecar identity at the preserved rowid'
    );
    assert_same((int)scalar($keyless_source_table_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_keyless_source_table' AND column_name = 'plugin_keyless_source_table_label_idx' AND decision = 'source-applied'"), 1, 'source-added no-primary-key table index restoration is auditable');
    assert_same((int)scalar($keyless_source_table_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_keyless_source_table' AND column_name = 'plugin_keyless_source_table_insert' AND decision = 'source-applied'"), 1, 'source-added no-primary-key table trigger restoration is auditable');

    $source_added_unique_fk_base = $tmp . '/source-added-unique-fk-base.sqlite';
    $source_added_unique_fk_source = $tmp . '/source-added-unique-fk-source.sqlite';
    $source_added_unique_fk_target = $tmp . '/source-added-unique-fk-target.sqlite';
    $source_added_unique_fk_metadata = $tmp . '/.forkpress/cow/merge/source-added-unique-fk-metadata.sqlite';
    create_base_db($source_added_unique_fk_base);
    copy($source_added_unique_fk_base, $source_added_unique_fk_source);
    copy($source_added_unique_fk_base, $source_added_unique_fk_target);
    $db = open_db($source_added_unique_fk_source);
    $db->exec('CREATE TABLE plugin_source_unique_parent (code TEXT NOT NULL, label TEXT)');
    $db->exec('CREATE UNIQUE INDEX plugin_source_unique_parent_code_idx ON plugin_source_unique_parent(code)');
    $db->exec("INSERT INTO plugin_source_unique_parent (code, label) VALUES ('source-parent', 'Source parent')");
    $db->exec('CREATE TABLE plugin_source_unique_child (parent_code TEXT NOT NULL REFERENCES plugin_source_unique_parent(code), label TEXT)');
    $db->exec("INSERT INTO plugin_source_unique_child (parent_code, label) VALUES ('source-parent', 'Source child')");
    $db->close();
    $source_added_unique_fk_result = cow_merge_databases(
        $source_added_unique_fk_base,
        $source_added_unique_fk_source,
        $source_added_unique_fk_target,
        $source_added_unique_fk_metadata,
        'feature-source-added-unique-fk',
        'main'
    );
    assert_same($source_added_unique_fk_result['status'], 'completed', 'source-added parent unique index materializes before dependent child rows');
    assert_same((int)scalar($source_added_unique_fk_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_source_unique_parent_code_idx'"), 1, 'source-added parent unique index exists on target');
    assert_same(scalar($source_added_unique_fk_target, "SELECT label FROM plugin_source_unique_child WHERE parent_code = 'source-parent'"), 'Source child', 'source-added child row validates against the source-added parent unique index');
    assert_same(
        (int)scalar($source_added_unique_fk_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE conflict_type = 'row-target-constraint' AND table_name IN ('plugin_source_unique_parent', 'plugin_source_unique_child')"),
        0,
        'source-added unique-index-backed FK tables avoid false target constraint conflicts'
    );
    assert_same(
        (int)scalar($source_added_unique_fk_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_source_unique_parent' AND column_name = 'plugin_source_unique_parent_code_idx' AND decision = 'source-applied'"),
        1,
        'source-added parent unique index materialization remains auditable'
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
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE decision = 'target-wins' AND table_name IN ('wp_posts', 'wp_options')"), 4, 'target-wins decisions are recorded for each conflicting run');
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
    $target_resolution_rerun = cow_merge_databases($conflict_base, $conflict_source, $conflict_target, $metadata, 'feature-conflict', 'main');
    assert_same($target_resolution_rerun['status'], 'completed', 'rerunning after target cell resolution treats the reviewed target choice as accepted');
    assert_same(scalar($conflict_target, "SELECT option_value FROM wp_options WHERE option_name = 'theme_mods_test'"), 'a:1:{s:5:"color";s:3:"red";}', 'rerunning after target cell resolution keeps the audited target value');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'wp_options' AND column_name = 'option_value'"),
        1,
        'rerunning after target cell resolution does not duplicate the unchanged conflict record'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE d.table_name = 'wp_options' AND d.column_name = 'option_value' AND d.decision = 'target-accepted' AND r.source_branch = 'feature-conflict'"),
        1,
        'rerunning after target cell resolution records the accepted target choice as an auditable decision'
    );
    $target_accepted_audit = cow_merge_audit_report($metadata, (int)$target_resolution_rerun['run_id'], 10);
    assert_same((int)$target_accepted_audit['runs'][0]['target_accepted_count'], 1, 'merge audit run summary counts accepted target decisions');
    ob_start();
    cow_merge_print_audit_text($target_accepted_audit);
    $target_accepted_text = ob_get_clean();
    assert_true(str_contains($target_accepted_text, 'target-accepted=1'), 'merge audit text includes accepted target decision counts');
    $target_accepted_grouped_audit = cow_merge_audit_report($metadata, (int)$target_resolution_rerun['run_id'], 10, ['records' => 'decisions', 'group_by' => 'type']);
    $target_accepted_group = null;
    foreach ($target_accepted_grouped_audit['decision_groups'] as $group) {
        if ($group['group_key'] === 'target-accepted') {
            $target_accepted_group = $group;
            break;
        }
    }
    assert_true($target_accepted_group !== null, 'decision grouping includes accepted target decisions by type');
    assert_same((int)$target_accepted_group['target_accepted_count'], 1, 'decision grouping counts accepted target decisions');
    $resolution_audit = cow_merge_audit_report($metadata, $conflict_run_id, 10);
    assert_same(count($resolution_audit['resolutions']), 2, 'merge audit report exports deterministic resolution records');
    $applied_resolution_audit = cow_merge_audit_report($metadata, $conflict_run_id, 10, ['resolution_status' => 'applied']);
    assert_same($applied_resolution_audit['filters']['records'], 'resolutions', 'resolution status filter defaults audit records to resolutions');
    assert_same($applied_resolution_audit['filters']['resolution_status'], 'applied', 'merge audit JSON report includes resolution status filter');
    assert_same(count($applied_resolution_audit['conflicts']), 0, 'resolution status filter omits conflict records');
    assert_same(count($applied_resolution_audit['decisions']), 0, 'resolution status filter omits decision records');
    assert_same(count($applied_resolution_audit['resolutions']), 1, 'resolution status filter returns applied resolution records');
    assert_same($applied_resolution_audit['resolutions'][0]['status'], 'applied', 'applied resolution status filter matches resolution rows');
    $applied_resolution_default_records_audit = cow_merge_audit_report($metadata, $conflict_run_id, 10, [
        'records' => 'all',
        'resolution_status' => 'applied',
        'group_by' => 'status',
    ]);
    assert_same($applied_resolution_default_records_audit['filters']['records'], 'resolutions', 'resolution status filter normalizes default CLI records to resolutions');
    assert_same(count($applied_resolution_default_records_audit['conflicts']), 0, 'resolution status filter with CLI-style defaults omits conflict records');
    assert_same(count($applied_resolution_default_records_audit['decisions']), 0, 'resolution status filter with CLI-style defaults omits decision records');
    assert_same(count($applied_resolution_default_records_audit['resolutions']), 1, 'resolution status filter with CLI-style defaults returns applied resolutions');
    assert_true(count(array_filter($applied_resolution_default_records_audit['resolution_groups'], fn($row) => $row['group_key'] === 'applied')) === 1, 'resolution status grouping works through CLI-style default records');
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

    $row_target_choice_base = $tmp . '/row-target-choice-base.sqlite';
    $row_target_choice_source = $tmp . '/row-target-choice-source.sqlite';
    $row_target_choice_target = $tmp . '/row-target-choice-target.sqlite';
    create_base_db($row_target_choice_base);
    copy($row_target_choice_base, $row_target_choice_source);
    copy($row_target_choice_base, $row_target_choice_target);
    $db = open_db($row_target_choice_source);
    $db->exec("INSERT INTO plugin_items (item_id, label, value) VALUES ('target-choice', 'Source target choice', 'source row')");
    $db->close();
    $db = open_db($row_target_choice_target);
    $db->exec("INSERT INTO plugin_items (item_id, label, value) VALUES ('target-choice', 'Target target choice', 'target row')");
    $db->close();
    $row_target_choice_result = cow_merge_databases($row_target_choice_base, $row_target_choice_source, $row_target_choice_target, $metadata, 'feature-row-target-choice', 'main');
    assert_same($row_target_choice_result['status'], 'completed_with_conflicts', 'row target-choice fixture starts with a reviewable conflict');
    $row_target_choice_id = (int)scalar($metadata, "SELECT c.id FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_items' AND c.conflict_type = 'row-insert-collision' AND r.source_branch = 'feature-row-target-choice' ORDER BY c.id DESC LIMIT 1");
    $row_target_choice_resolution = cow_merge_resolve_conflict(
        $metadata,
        $row_target_choice_id,
        'target',
        true,
        'Keep audited target row.',
        'cow-test'
    );
    assert_same($row_target_choice_resolution['status'], 'validated', 'target row resolution records validated status');
    $row_target_choice_rerun = cow_merge_databases($row_target_choice_base, $row_target_choice_source, $row_target_choice_target, $metadata, 'feature-row-target-choice', 'main');
    assert_same($row_target_choice_rerun['status'], 'completed', 'rerunning after target row resolution treats the reviewed target choice as accepted');
    assert_same(scalar($row_target_choice_target, "SELECT value FROM plugin_items WHERE item_id = 'target-choice'"), 'target row', 'rerunning after target row resolution keeps the audited target row');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_items' AND c.conflict_type = 'row-insert-collision' AND r.source_branch = 'feature-row-target-choice'"),
        1,
        'rerunning after target row resolution does not duplicate the unchanged row conflict record'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE d.table_name = 'plugin_items' AND d.decision = 'target-accepted' AND r.source_branch = 'feature-row-target-choice'"),
        1,
        'rerunning after target row resolution records the accepted target choice as an auditable decision'
    );

    $row_same_insert_base = $tmp . '/row-same-insert-base.sqlite';
    $row_same_insert_source = $tmp . '/row-same-insert-source.sqlite';
    $row_same_insert_target = $tmp . '/row-same-insert-target.sqlite';
    create_base_db($row_same_insert_base);
    copy($row_same_insert_base, $row_same_insert_source);
    copy($row_same_insert_base, $row_same_insert_target);
    $db = open_db($row_same_insert_source);
    $db->exec("INSERT INTO plugin_items (item_id, label, value) VALUES ('same-insert', 'Same label', 'same row')");
    $db->close();
    $db = open_db($row_same_insert_target);
    $db->exec("INSERT INTO plugin_items (item_id, label, value) VALUES ('same-insert', 'Same label', 'same row')");
    $db->close();
    $row_same_insert_result = cow_merge_databases($row_same_insert_base, $row_same_insert_source, $row_same_insert_target, $metadata, 'feature-row-same-insert', 'main');
    assert_same($row_same_insert_result['status'], 'completed', 'identical same-PK source and target inserts merge without a review conflict');
    assert_same((int)scalar($row_same_insert_target, "SELECT COUNT(*) FROM plugin_items WHERE item_id = 'same-insert'"), 1, 'identical same-PK insert is not duplicated');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_items' AND decision = 'source-applied' AND reason = 'source inserted row already exists in target with the same identity and payload'"), 1, 'identical same-PK insert collapse is auditable');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_items' AND row_identity LIKE '%same-insert%'"), 0, 'identical same-PK insert does not create a false row conflict');

    $row_same_update_base = $tmp . '/row-same-update-base.sqlite';
    $row_same_update_source = $tmp . '/row-same-update-source.sqlite';
    $row_same_update_target = $tmp . '/row-same-update-target.sqlite';
    create_base_db($row_same_update_base);
    copy($row_same_update_base, $row_same_update_source);
    copy($row_same_update_base, $row_same_update_target);
    foreach ([$row_same_update_source, $row_same_update_target] as $path) {
        $db = open_db($path);
        $db->exec("UPDATE plugin_items SET label = 'Same updated label', value = 'same updated row' WHERE item_id = 'alpha'");
        $db->close();
    }
    $row_same_update_result = cow_merge_databases($row_same_update_base, $row_same_update_source, $row_same_update_target, $metadata, 'feature-row-same-update', 'main');
    assert_same($row_same_update_result['status'], 'completed', 'identical same-PK source and target updates merge without a review conflict');
    assert_same(scalar($row_same_update_target, "SELECT value FROM plugin_items WHERE item_id = 'alpha'"), 'same updated row', 'identical same-PK update leaves the shared payload in target');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_items' AND decision = 'source-applied' AND reason = 'source and target changed row to the same payload'"), 1, 'identical same-PK update is auditable');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_items' AND r.source_branch = 'feature-row-same-update'"), 0, 'identical same-PK update does not create a false row conflict');

    $row_same_cell_base = $tmp . '/row-same-cell-base.sqlite';
    $row_same_cell_source = $tmp . '/row-same-cell-source.sqlite';
    $row_same_cell_target = $tmp . '/row-same-cell-target.sqlite';
    create_base_db($row_same_cell_base);
    copy($row_same_cell_base, $row_same_cell_source);
    copy($row_same_cell_base, $row_same_cell_target);
    $db = open_db($row_same_cell_source);
    $db->exec("UPDATE plugin_items SET label = 'Shared cell label' WHERE item_id = 'alpha'");
    $db->close();
    $db = open_db($row_same_cell_target);
    $db->exec("UPDATE plugin_items SET label = 'Shared cell label', value = 'target-only alongside shared cell' WHERE item_id = 'alpha'");
    $db->close();
    $row_same_cell_result = cow_merge_databases($row_same_cell_base, $row_same_cell_source, $row_same_cell_target, $metadata, 'feature-row-same-cell', 'main');
    assert_same($row_same_cell_result['status'], 'completed', 'identical same-PK cell changes inside a divergent row merge without a review conflict');
    assert_same(scalar($row_same_cell_target, "SELECT label FROM plugin_items WHERE item_id = 'alpha'"), 'Shared cell label', 'shared same-PK cell value remains in target');
    assert_same(scalar($row_same_cell_target, "SELECT value FROM plugin_items WHERE item_id = 'alpha'"), 'target-only alongside shared cell', 'target-only same-PK cell remains preserved');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE d.table_name = 'plugin_items' AND d.column_name = 'label' AND d.decision = 'source-applied' AND d.reason = 'source and target changed cell to the same value' AND r.source_branch = 'feature-row-same-cell'"), 1, 'identical same-PK cell change inside a divergent row is auditable');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE d.table_name = 'plugin_items' AND d.column_name = 'value' AND d.decision = 'target-kept' AND d.reason = 'target changed cell and source did not change it' AND r.source_branch = 'feature-row-same-cell'"), 1, 'target-only cell next to a shared cell remains auditable');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_items' AND r.source_branch = 'feature-row-same-cell'"), 0, 'identical same-PK cell change inside a divergent row does not create a false row conflict');

    $row_same_delete_base = $tmp . '/row-same-delete-base.sqlite';
    $row_same_delete_source = $tmp . '/row-same-delete-source.sqlite';
    $row_same_delete_target = $tmp . '/row-same-delete-target.sqlite';
    create_base_db($row_same_delete_base);
    copy($row_same_delete_base, $row_same_delete_source);
    copy($row_same_delete_base, $row_same_delete_target);
    foreach ([$row_same_delete_source, $row_same_delete_target] as $path) {
        $db = open_db($path);
        $db->exec("DELETE FROM plugin_items WHERE item_id = 'alpha'");
        $db->close();
    }
    $row_same_delete_result = cow_merge_databases($row_same_delete_base, $row_same_delete_source, $row_same_delete_target, $metadata, 'feature-row-same-delete', 'main');
    assert_same($row_same_delete_result['status'], 'completed', 'identical same-PK source and target deletes merge without a review conflict');
    assert_same((int)scalar($row_same_delete_target, "SELECT COUNT(*) FROM plugin_items WHERE item_id = 'alpha'"), 0, 'identical same-PK delete keeps the row deleted');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_items' AND decision = 'source-applied' AND reason = 'source and target deleted row with the same identity'"), 1, 'identical same-PK delete is auditable');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_items' AND r.source_branch = 'feature-row-same-delete'"), 0, 'identical same-PK delete does not create a false row conflict');

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

    $review_queue_base = $tmp . '/review-queue-base.sqlite';
    $review_queue_source = $tmp . '/review-queue-source.sqlite';
    $review_queue_target = $tmp . '/review-queue-target.sqlite';
    create_base_db($review_queue_base);
    copy($review_queue_base, $review_queue_source);
    copy($review_queue_base, $review_queue_target);
    $db = open_db($review_queue_source);
    $db->exec("UPDATE plugin_items SET value = 'source review queue conflict' WHERE item_id = 'alpha'");
    $db->close();
    $db = open_db($review_queue_target);
    $db->exec("UPDATE plugin_items SET value = 'target review queue conflict' WHERE item_id = 'alpha'");
    $db->close();
    cow_merge_databases($review_queue_base, $review_queue_source, $review_queue_target, $metadata, 'feature-review-queue', 'main');
    $review_queue_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_items' AND conflict_type = 'cell-conflict' ORDER BY id DESC LIMIT 1");

    $status_transition_base = $tmp . '/status-transition-base.sqlite';
    $status_transition_source = $tmp . '/status-transition-source.sqlite';
    $status_transition_target = $tmp . '/status-transition-target.sqlite';
    create_base_db($status_transition_base);
    copy($status_transition_base, $status_transition_source);
    copy($status_transition_base, $status_transition_target);
    $db = open_db($status_transition_source);
    $db->exec("UPDATE plugin_items SET value = 'source pending queue conflict' WHERE item_id = 'alpha'");
    $db->close();
    $db = open_db($status_transition_target);
    $db->exec("UPDATE plugin_items SET value = 'target pending queue conflict' WHERE item_id = 'alpha'");
    $db->close();
    cow_merge_databases($status_transition_base, $status_transition_source, $status_transition_target, $metadata, 'feature-status-transition', 'main');
    $status_transition_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_items' AND conflict_type = 'cell-conflict' ORDER BY id DESC LIMIT 1");
    cow_merge_review_record(
        $metadata,
        'conflict',
        $status_transition_conflict_id,
        'reviewed',
        'Initial status transition review.',
        'cow-test'
    );
    cow_merge_review_record(
        $metadata,
        'conflict',
        $status_transition_conflict_id,
        'pending',
        'Re-opened for a second reviewer.',
        'cow-test'
    );

    $needs_action_transition_base = $tmp . '/needs-action-transition-base.sqlite';
    $needs_action_transition_source = $tmp . '/needs-action-transition-source.sqlite';
    $needs_action_transition_target = $tmp . '/needs-action-transition-target.sqlite';
    create_base_db($needs_action_transition_base);
    copy($needs_action_transition_base, $needs_action_transition_source);
    copy($needs_action_transition_base, $needs_action_transition_target);
    $db = open_db($needs_action_transition_source);
    $db->exec("UPDATE plugin_items SET value = 'source needs-action queue conflict' WHERE item_id = 'alpha'");
    $db->close();
    $db = open_db($needs_action_transition_target);
    $db->exec("UPDATE plugin_items SET value = 'target needs-action queue conflict' WHERE item_id = 'alpha'");
    $db->close();
    cow_merge_databases($needs_action_transition_base, $needs_action_transition_source, $needs_action_transition_target, $metadata, 'feature-needs-action-transition', 'main');
    $needs_action_transition_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_items' AND conflict_type = 'cell-conflict' ORDER BY id DESC LIMIT 1");
    cow_merge_review_record(
        $metadata,
        'conflict',
        $needs_action_transition_conflict_id,
        'pending',
        'Initial needs-action transition review.',
        'cow-test'
    );
    cow_merge_review_record(
        $metadata,
        'conflict',
        $needs_action_transition_conflict_id,
        'needs-action',
        'Escalated for owner follow-up.',
        'cow-test'
    );

    ob_start();
    cow_merge_print_audit_text($reviewed_audit);
    $audit_text = ob_get_clean();
    assert_true(str_contains($audit_text, 'target-wins'), 'merge audit text includes automatic target-wins decisions');
    assert_true(str_contains($audit_text, 'wp_posts'), 'merge audit text identifies affected tables');
    assert_true(str_contains($audit_text, 'review=reviewed') && str_contains($audit_text, 'Target value is intentional'), 'merge audit text includes review annotations');
    $review_audit = cow_merge_audit_report($metadata, null, 10, ['review' => '1']);
    assert_same($review_audit['filters']['review'], true, 'merge audit JSON report includes the review shortcut filter');
    assert_true(count($review_audit['conflicts']) >= 2, 'review audit includes revisitable merge conflicts');
    $unreviewed_review_queue_audit = cow_merge_audit_report($metadata, null, 10, [
        'review' => '1',
        'review_status' => 'unreviewed',
        'records' => 'conflicts',
        'scope' => 'db',
    ]);
    assert_same($unreviewed_review_queue_audit['filters']['review'], true, 'review queue audit preserves the review shortcut filter');
    assert_same($unreviewed_review_queue_audit['filters']['review_status'], 'unreviewed', 'review queue audit preserves the unreviewed filter');
    assert_same($unreviewed_review_queue_audit['filters']['records'], 'conflicts', 'review queue audit can focus on conflict records');
    assert_same($unreviewed_review_queue_audit['filters']['scope'], 'db', 'review queue audit can focus on database records');
    assert_same(count($unreviewed_review_queue_audit['decisions']), 0, 'review queue conflict audit omits decisions');
    assert_same(count($unreviewed_review_queue_audit['resolutions']), 0, 'review queue conflict audit omits resolutions');
    $unreviewed_review_queue_ids = array_map(fn($row) => (int)$row['id'], $unreviewed_review_queue_audit['conflicts']);
    assert_true(in_array($review_queue_conflict_id, $unreviewed_review_queue_ids, true), 'review queue audit returns unreviewed DB conflicts');
    assert_true(!in_array($reviewed_conflict_id, $unreviewed_review_queue_ids, true), 'review queue audit excludes reviewed conflicts');
    assert_true(!in_array($status_transition_conflict_id, $unreviewed_review_queue_ids, true), 'review queue audit excludes pending conflicts from unreviewed queues');
    $pending_review_queue_audit = cow_merge_audit_report($metadata, null, 10, [
        'review' => '1',
        'review_status' => 'pending',
        'records' => 'conflicts',
        'scope' => 'db',
    ]);
    assert_same($pending_review_queue_audit['filters']['review'], true, 'pending review queue preserves the review shortcut filter');
    assert_same($pending_review_queue_audit['filters']['review_status'], 'pending', 'pending review queue preserves the pending filter');
    assert_same($pending_review_queue_audit['filters']['records'], 'conflicts', 'pending review queue can focus on conflict records');
    assert_same($pending_review_queue_audit['filters']['scope'], 'db', 'pending review queue can focus on database records');
    assert_same(count($pending_review_queue_audit['decisions']), 0, 'pending conflict queue omits decisions');
    assert_same(count($pending_review_queue_audit['resolutions']), 0, 'pending conflict queue omits resolutions');
    $pending_review_queue_ids = array_map(fn($row) => (int)$row['id'], $pending_review_queue_audit['conflicts']);
    assert_true(in_array($status_transition_conflict_id, $pending_review_queue_ids, true), 'pending review queue returns conflicts whose latest note is pending');
    assert_true(!in_array($reviewed_conflict_id, $pending_review_queue_ids, true), 'pending review queue excludes reviewed conflicts');
    assert_true(!in_array($needs_action_transition_conflict_id, $pending_review_queue_ids, true), 'pending review queue follows latest review status');
    $status_transition_rows = array_values(array_filter(
        $pending_review_queue_audit['conflicts'],
        fn($row) => (int)$row['id'] === $status_transition_conflict_id
    ));
    assert_same($status_transition_rows[0]['review_status'], 'pending', 'pending review queue exposes the latest review status');
    assert_same($status_transition_rows[0]['review_note'], 'Re-opened for a second reviewer.', 'pending review queue exposes the latest review note');
    $needs_action_review_queue_audit = cow_merge_audit_report($metadata, null, 10, [
        'review' => '1',
        'review_status' => 'needs-action',
        'records' => 'conflicts',
        'scope' => 'db',
    ]);
    assert_same($needs_action_review_queue_audit['filters']['review'], true, 'needs-action review queue preserves the review shortcut filter');
    assert_same($needs_action_review_queue_audit['filters']['review_status'], 'needs-action', 'needs-action review queue preserves the needs-action filter');
    assert_same($needs_action_review_queue_audit['filters']['records'], 'conflicts', 'needs-action review queue can focus on conflict records');
    assert_same($needs_action_review_queue_audit['filters']['scope'], 'db', 'needs-action review queue can focus on database records');
    assert_same(count($needs_action_review_queue_audit['decisions']), 0, 'needs-action conflict queue omits decisions');
    assert_same(count($needs_action_review_queue_audit['resolutions']), 0, 'needs-action conflict queue omits resolutions');
    $needs_action_review_queue_ids = array_map(fn($row) => (int)$row['id'], $needs_action_review_queue_audit['conflicts']);
    assert_true(in_array($needs_action_transition_conflict_id, $needs_action_review_queue_ids, true), 'needs-action review queue returns conflicts whose latest note is needs-action');
    assert_true(!in_array($status_transition_conflict_id, $needs_action_review_queue_ids, true), 'needs-action review queue excludes pending conflicts');
    $needs_action_transition_rows = array_values(array_filter(
        $needs_action_review_queue_audit['conflicts'],
        fn($row) => (int)$row['id'] === $needs_action_transition_conflict_id
    ));
    assert_same($needs_action_transition_rows[0]['review_status'], 'needs-action', 'needs-action review queue exposes the latest review status');
    assert_same($needs_action_transition_rows[0]['review_note'], 'Escalated for owner follow-up.', 'needs-action review queue exposes the latest review note');
    $reviewed_status_audit = cow_merge_audit_report($metadata, null, 10, ['review_status' => 'reviewed']);
    assert_same($reviewed_status_audit['filters']['review_status'], 'reviewed', 'merge audit JSON report includes review status filter');
    assert_true(count($reviewed_status_audit['conflicts']) >= 1, 'review status filter returns reviewed conflicts');
    $reviewed_status_ids = array_map(fn($row) => (int)$row['id'], $reviewed_status_audit['conflicts']);
    assert_true(in_array($reviewed_conflict_id, $reviewed_status_ids, true), 'review status filter returns the annotated conflict');
    assert_true(!in_array($status_transition_conflict_id, $reviewed_status_ids, true), 'reviewed status filter follows the latest review note');
    $reviewed_status_decision_ids = array_map(fn($row) => (int)$row['id'], $reviewed_status_audit['decisions']);
    assert_true(in_array($reviewed_db_decision_id, $reviewed_status_decision_ids, true), 'review status filter returns annotated DB decisions');
    foreach ($reviewed_status_audit['decisions'] as $row) {
        assert_same($row['review_status'], 'reviewed', 'review status filter omits unannotated decisions');
    }
    $reviewed_conflict_closure_audit = cow_merge_audit_report($metadata, null, 10, [
        'review' => '1',
        'review_status' => 'reviewed',
        'records' => 'conflicts',
        'scope' => 'db',
    ]);
    assert_same($reviewed_conflict_closure_audit['filters']['review'], true, 'reviewed conflict closure report preserves the review shortcut filter');
    assert_same($reviewed_conflict_closure_audit['filters']['review_status'], 'reviewed', 'reviewed conflict closure report preserves the reviewed filter');
    assert_same($reviewed_conflict_closure_audit['filters']['records'], 'conflicts', 'reviewed conflict closure report focuses on conflict records');
    assert_same($reviewed_conflict_closure_audit['filters']['scope'], 'db', 'reviewed conflict closure report focuses on database records');
    assert_same(count($reviewed_conflict_closure_audit['decisions']), 0, 'reviewed conflict closure report omits decisions');
    assert_same(count($reviewed_conflict_closure_audit['resolutions']), 0, 'reviewed conflict closure report omits resolutions');
    $reviewed_conflict_closure_ids = array_map(fn($row) => (int)$row['id'], $reviewed_conflict_closure_audit['conflicts']);
    assert_true(in_array($reviewed_conflict_id, $reviewed_conflict_closure_ids, true), 'reviewed conflict closure report returns reviewed DB conflicts');
    assert_true(!in_array($review_queue_conflict_id, $reviewed_conflict_closure_ids, true), 'reviewed conflict closure report excludes unreviewed DB conflicts');
    assert_true(!in_array($status_transition_conflict_id, $reviewed_conflict_closure_ids, true), 'reviewed conflict closure report follows latest review status');
    foreach ($reviewed_conflict_closure_audit['conflicts'] as $row) {
        assert_same($row['review_status'], 'reviewed', 'reviewed conflict closure report returns only reviewed conflicts');
        assert_true($row['table_name'] !== '__files__', 'reviewed conflict closure report excludes file records');
    }
    $unreviewed_status_audit = cow_merge_audit_report($metadata, null, 10, ['review_status' => 'unreviewed']);
    assert_same($unreviewed_status_audit['filters']['review_status'], 'unreviewed', 'merge audit JSON report includes unreviewed filter');
    assert_true(count($unreviewed_status_audit['decisions']) >= 1, 'unreviewed filter returns decisions with no review note');
    $unreviewed_conflict_ids = array_map(fn($row) => (int)$row['id'], $unreviewed_status_audit['conflicts']);
    assert_true(!in_array($reviewed_conflict_id, $unreviewed_conflict_ids, true), 'unreviewed filter excludes reviewed conflicts');
    $needs_action_status_audit = cow_merge_audit_report($metadata, null, 10, ['review_status' => 'needs-action']);
    $needs_action_status_ids = array_map(fn($row) => (int)$row['id'], $needs_action_status_audit['conflicts']);
    assert_true(in_array($needs_action_transition_conflict_id, $needs_action_status_ids, true), 'review status filter returns needs-action conflicts');
    assert_true(!in_array($status_transition_conflict_id, $needs_action_status_ids, true), 'review status filter excludes other latest statuses');
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
    ob_start();
    cow_merge_print_audit_text($unreviewed_status_audit);
    $unreviewed_status_text = ob_get_clean();
    assert_true(str_contains($unreviewed_status_text, 'review-status=unreviewed'), 'unreviewed filter is visible in text filters');
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
    $needs_action_resolution_queue_audit = cow_merge_audit_report($metadata, null, 10, [
        'review' => '1',
        'review_status' => 'needs-action',
        'records' => 'resolutions',
        'scope' => 'db',
    ]);
    assert_same($needs_action_resolution_queue_audit['filters']['review'], true, 'needs-action resolution queue preserves the review shortcut filter');
    assert_same($needs_action_resolution_queue_audit['filters']['review_status'], 'needs-action', 'needs-action resolution queue preserves the needs-action filter');
    assert_same($needs_action_resolution_queue_audit['filters']['records'], 'resolutions', 'needs-action resolution queue can focus on resolution records');
    assert_same($needs_action_resolution_queue_audit['filters']['scope'], 'db', 'needs-action resolution queue can focus on database records');
    assert_same(count($needs_action_resolution_queue_audit['conflicts']), 0, 'needs-action resolution queue omits conflicts');
    assert_same(count($needs_action_resolution_queue_audit['decisions']), 0, 'needs-action resolution queue omits decisions');
    assert_same(count($needs_action_resolution_queue_audit['resolutions']), 1, 'needs-action resolution queue returns annotated DB resolution records');
    assert_same((int)$needs_action_resolution_queue_audit['resolutions'][0]['id'], $resolution_review_id, 'needs-action resolution queue returns the annotated resolution id');
    $unreviewed_resolution_audit = cow_merge_audit_report($metadata, null, 10, ['records' => 'resolutions', 'review_status' => 'unreviewed']);
    assert_true(count($unreviewed_resolution_audit['resolutions']) >= 1, 'unreviewed filter returns resolution records with no review note');
    $unreviewed_resolution_ids = array_map(fn($row) => (int)$row['id'], $unreviewed_resolution_audit['resolutions']);
    assert_true(!in_array($resolution_review_id, $unreviewed_resolution_ids, true), 'unreviewed filter excludes reviewed resolution records');
    $unreviewed_resolution_queue_audit = cow_merge_audit_report($metadata, null, 10, [
        'review' => '1',
        'review_status' => 'unreviewed',
        'records' => 'resolutions',
        'scope' => 'db',
    ]);
    assert_same($unreviewed_resolution_queue_audit['filters']['review'], true, 'resolution review queue audit preserves the review shortcut filter');
    assert_same($unreviewed_resolution_queue_audit['filters']['review_status'], 'unreviewed', 'resolution review queue audit preserves the unreviewed filter');
    assert_same($unreviewed_resolution_queue_audit['filters']['records'], 'resolutions', 'resolution review queue audit can focus on resolution records');
    assert_same($unreviewed_resolution_queue_audit['filters']['scope'], 'db', 'resolution review queue audit can focus on database records');
    assert_same(count($unreviewed_resolution_queue_audit['conflicts']), 0, 'resolution review queue omits conflicts');
    assert_same(count($unreviewed_resolution_queue_audit['decisions']), 0, 'resolution review queue omits decisions');
    assert_true(count($unreviewed_resolution_queue_audit['resolutions']) >= 1, 'resolution review queue returns unreviewed DB resolutions');
    $unreviewed_resolution_queue_ids = array_map(fn($row) => (int)$row['id'], $unreviewed_resolution_queue_audit['resolutions']);
    assert_true(!in_array($resolution_review_id, $unreviewed_resolution_queue_ids, true), 'resolution review queue excludes reviewed resolutions');
    foreach ($unreviewed_resolution_queue_audit['resolutions'] as $row) {
        assert_same($row['review_status'], null, 'resolution review queue returns only unreviewed records');
        assert_true($row['table_name'] !== '__files__', 'database resolution review queue excludes file records');
    }
    ob_start();
    cow_merge_print_audit_text($reviewed_resolution_audit);
    $reviewed_resolution_text = ob_get_clean();
    assert_true(str_contains($reviewed_resolution_text, 'review=needs-action') && str_contains($reviewed_resolution_text, 'Follow up with content owner'), 'merge audit text includes resolution review annotations');
    cow_merge_review_record(
        $metadata,
        'resolution',
        $resolution_review_id,
        'reviewed',
        'Owner accepted source resolution.',
        'cow-test'
    );
    $reviewed_resolution_closure_audit = cow_merge_audit_report($metadata, null, 10, [
        'review' => '1',
        'review_status' => 'reviewed',
        'records' => 'resolutions',
        'scope' => 'db',
    ]);
    assert_same($reviewed_resolution_closure_audit['filters']['review'], true, 'reviewed resolution closure report preserves the review shortcut filter');
    assert_same($reviewed_resolution_closure_audit['filters']['review_status'], 'reviewed', 'reviewed resolution closure report preserves the reviewed filter');
    assert_same($reviewed_resolution_closure_audit['filters']['records'], 'resolutions', 'reviewed resolution closure report focuses on resolution records');
    assert_same($reviewed_resolution_closure_audit['filters']['scope'], 'db', 'reviewed resolution closure report focuses on database records');
    assert_same(count($reviewed_resolution_closure_audit['conflicts']), 0, 'reviewed resolution closure report omits conflicts');
    assert_same(count($reviewed_resolution_closure_audit['decisions']), 0, 'reviewed resolution closure report omits decisions');
    $reviewed_resolution_closure_ids = array_map(fn($row) => (int)$row['id'], $reviewed_resolution_closure_audit['resolutions']);
    assert_true(in_array($resolution_review_id, $reviewed_resolution_closure_ids, true), 'reviewed resolution closure report returns reviewed DB resolutions');
    foreach ($reviewed_resolution_closure_audit['resolutions'] as $row) {
        assert_same($row['review_status'], 'reviewed', 'reviewed resolution closure report returns only reviewed resolutions');
        assert_true($row['table_name'] !== '__files__', 'reviewed resolution closure report excludes file records');
    }
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
    write_test_file($file_base_root . '/wp-content/uploads/same-change.txt', 'base same change');
    write_test_file($file_base_root . '/wp-content/uploads/same-delete.txt', 'base same delete');
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
    assert_same($file_capture['files'], 8, 'filesystem merge base excludes ForkPress-managed files');

    write_test_file($file_source_root . '/wp-content/uploads/shared.txt', 'source shared');
    write_test_file($file_source_root . '/wp-content/uploads/new-source.txt', 'source new');
    write_test_file($file_source_root . '/wp-content/uploads/same-added.txt', 'same added');
    write_test_file($file_source_root . '/wp-content/uploads/same-change.txt', 'same changed');
    mkdir($file_source_root . '/wp-content/uploads/source-empty-dir/nested', 0777, true);
    create_test_symlink('new-source.txt', $file_source_root . '/wp-content/uploads/shared-link.txt');
    create_test_symlink('../new-source.txt', $file_source_root . '/wp-content/uploads/links/source-link.txt');
    create_test_symlink('/etc/passwd', $file_source_root . '/wp-content/uploads/absolute-link.txt');
    create_test_symlink('../../../etc/passwd', $file_source_root . '/wp-content/uploads/outside-link.txt');
    unlink($file_source_root . '/wp-content/uploads/delete-me.txt');
    unlink($file_source_root . '/wp-content/uploads/same-delete.txt');
    rmdir($file_source_root . '/wp-content/uploads/delete-empty-dir');
    rmdir($file_source_root . '/wp-content/uploads/delete-dir-conflict');
    write_test_file($file_source_root . '/wp-content/uploads/conflict.txt', 'source conflict');
    write_test_file($file_source_root . '/wp-config.php', 'source managed config');
    write_test_file($file_source_root . '/wp-content/database/.ht.sqlite', 'source managed db');

    write_test_file($file_target_root . '/wp-content/uploads/conflict.txt', 'target conflict');
    write_test_file($file_target_root . '/wp-content/uploads/target-only.txt', 'target only');
    write_test_file($file_target_root . '/wp-content/uploads/same-added.txt', 'same added');
    write_test_file($file_target_root . '/wp-content/uploads/same-change.txt', 'same changed');
    unlink($file_target_root . '/wp-content/uploads/target-delete.txt');
    unlink($file_target_root . '/wp-content/uploads/same-delete.txt');
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
    assert_same(file_get_contents($file_target_root . '/wp-content/uploads/same-added.txt'), 'same added', 'identical source/target filesystem addition remains present');
    assert_same(file_get_contents($file_target_root . '/wp-content/uploads/same-change.txt'), 'same changed', 'identical source/target filesystem change remains present');
    assert_true(!file_exists($file_target_root . '/wp-content/uploads/same-delete.txt'), 'identical source/target filesystem deletion remains deleted');
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
    $same_added_file_identity = SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/same-added.txt'));
    $same_change_file_identity = SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/same-change.txt'));
    $same_delete_file_identity = SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/same-delete.txt'));
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = '__files__' AND decision = 'source-applied' AND row_identity = '$same_added_file_identity' AND reason = 'source and target added the same filesystem path'"), 1, 'identical source/target filesystem addition is auditable');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = '__files__' AND decision = 'source-applied' AND row_identity = '$same_change_file_identity' AND reason = 'source and target changed filesystem path to the same state'"), 1, 'identical source/target filesystem change is auditable');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = '__files__' AND decision = 'source-applied' AND row_identity = '$same_delete_file_identity' AND reason = 'source and target deleted the same filesystem path' AND chosen_payload IS NULL"), 1, 'identical source/target filesystem deletion is auditable');
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
    $reviewed_file_conflict_id = (int)$exact_path_audit['conflicts'][0]['id'];
    $unreviewed_file_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = '__files__' AND row_identity = '" . SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/conflict.txt')) . "' ORDER BY id DESC LIMIT 1");
    $file_conflict_review = cow_merge_review_record(
        $metadata,
        'conflict',
        $reviewed_file_conflict_id,
        'reviewed',
        'Unsafe symlink is intentionally target-kept.',
        'cow-test'
    );
    assert_same($file_conflict_review['record_type'], 'conflict', 'review note can target a filesystem conflict record');
    $file_conflict_queue_audit = cow_merge_audit_report($metadata, null, 10, [
        'review' => '1',
        'review_status' => 'unreviewed',
        'records' => 'conflicts',
        'scope' => 'files',
        'path_prefix' => 'wp-content/uploads',
    ]);
    assert_same($file_conflict_queue_audit['filters']['review'], true, 'file conflict review queue preserves the review shortcut filter');
    assert_same($file_conflict_queue_audit['filters']['review_status'], 'unreviewed', 'file conflict review queue preserves the unreviewed filter');
    assert_same($file_conflict_queue_audit['filters']['records'], 'conflicts', 'file conflict review queue focuses on conflict records');
    assert_same($file_conflict_queue_audit['filters']['scope'], 'files', 'file conflict review queue focuses on filesystem records');
    assert_same(count($file_conflict_queue_audit['decisions']), 0, 'file conflict review queue omits decisions');
    assert_same(count($file_conflict_queue_audit['resolutions']), 0, 'file conflict review queue omits resolutions');
    assert_true(count($file_conflict_queue_audit['conflicts']) >= 1, 'file conflict review queue returns unreviewed filesystem conflicts');
    $file_conflict_queue_ids = array_map(fn($row) => (int)$row['id'], $file_conflict_queue_audit['conflicts']);
    assert_true(in_array($unreviewed_file_conflict_id, $file_conflict_queue_ids, true), 'file conflict review queue includes unreviewed filesystem conflicts');
    assert_true(!in_array($reviewed_file_conflict_id, $file_conflict_queue_ids, true), 'file conflict review queue excludes reviewed filesystem conflicts');
    foreach ($file_conflict_queue_audit['conflicts'] as $row) {
        assert_same($row['review_status'], null, 'file conflict review queue returns only unreviewed records');
        assert_same($row['table_name'], '__files__', 'file conflict review queue excludes database records');
    }
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
    $reviewed_file_decision_id = (int)scalar($metadata, "SELECT id FROM merge_decisions WHERE table_name = '__files__' AND row_identity = '$target_only_file_identity' AND decision = 'target-kept' ORDER BY id DESC LIMIT 1");
    $file_decision_review = cow_merge_review_record(
        $metadata,
        'decision',
        $reviewed_file_decision_id,
        'reviewed',
        'Target-only uploaded file is intentional.',
        'cow-test'
    );
    assert_same($file_decision_review['record_type'], 'decision', 'review note can target a filesystem decision record');
    $file_decision_queue_audit = cow_merge_audit_report($metadata, null, 10, [
        'review' => '1',
        'review_status' => 'unreviewed',
        'records' => 'decisions',
        'scope' => 'files',
        'path_prefix' => 'wp-content/uploads',
    ]);
    assert_same($file_decision_queue_audit['filters']['review'], true, 'file decision review queue preserves the review shortcut filter');
    assert_same($file_decision_queue_audit['filters']['review_status'], 'unreviewed', 'file decision review queue preserves the unreviewed filter');
    assert_same($file_decision_queue_audit['filters']['records'], 'decisions', 'file decision review queue focuses on decision records');
    assert_same($file_decision_queue_audit['filters']['scope'], 'files', 'file decision review queue focuses on filesystem records');
    assert_same(count($file_decision_queue_audit['conflicts']), 0, 'file decision review queue omits conflicts');
    assert_same(count($file_decision_queue_audit['resolutions']), 0, 'file decision review queue omits resolutions');
    assert_true(count($file_decision_queue_audit['decisions']) >= 1, 'file decision review queue returns unreviewed filesystem decisions');
    $file_decision_queue_ids = array_map(fn($row) => (int)$row['id'], $file_decision_queue_audit['decisions']);
    assert_true(!in_array($reviewed_file_decision_id, $file_decision_queue_ids, true), 'file decision review queue excludes reviewed decisions');
    foreach ($file_decision_queue_audit['decisions'] as $row) {
        assert_same($row['review_status'], null, 'file decision review queue returns only unreviewed records');
        assert_same($row['table_name'], '__files__', 'file decision review queue excludes database records');
    }
    $reviewed_file_decision_audit = cow_merge_audit_report($metadata, null, 10, [
        'records' => 'decisions',
        'review_status' => 'reviewed',
        'scope' => 'files',
        'path' => 'wp-content/uploads/target-only.txt',
    ]);
    assert_same(count($reviewed_file_decision_audit['decisions']), 1, 'review status filter returns annotated file decisions by path');
    assert_same($reviewed_file_decision_audit['decisions'][0]['review_status'], 'reviewed', 'merge audit JSON exposes latest file decision review status');
    assert_same($reviewed_file_decision_audit['decisions'][0]['review_note'], 'Target-only uploaded file is intentional.', 'merge audit JSON exposes latest file decision review note');

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
    $file_resolve_rerun = cow_merge_branch_state(
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
    assert_same($file_resolve_rerun['status'], 'completed_with_conflicts', 'rerunning after filesystem source resolutions only reports unresolved file conflicts');
    assert_same(file_get_contents($file_resolve_target_root . '/wp-content/uploads/conflict.txt'), 'source conflict resolution', 'rerunning after source filesystem replacement keeps the audited source file');
    assert_true(!file_exists($file_resolve_target_root . '/wp-content/uploads/delete-conflict.txt'), 'rerunning after source filesystem deletion keeps the target path deleted');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = '__files__' AND c.conflict_type = 'file-conflict' AND c.row_identity = '" . SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/conflict.txt')) . "' AND r.source_branch = 'feature-file-resolve'"),
        1,
        'rerunning after source filesystem replacement does not rediscover the resolved file conflict'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = '__files__' AND c.conflict_type = 'file-source-deleted' AND c.row_identity = '" . SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/delete-conflict.txt')) . "' AND r.source_branch = 'feature-file-resolve'"),
        1,
        'rerunning after source filesystem deletion does not rediscover the resolved delete conflict'
    );
    $reviewed_file_resolution_id = (int)$file_source_resolution['resolution_id'];
    $unreviewed_file_resolution_id = (int)$file_delete_resolution['resolution_id'];
    $file_resolution_review = cow_merge_review_record(
        $metadata,
        'resolution',
        $reviewed_file_resolution_id,
        'reviewed',
        'Source file replacement is accepted.',
        'cow-test'
    );
    assert_same($file_resolution_review['record_type'], 'resolution', 'review note can target a filesystem resolution record');
    $file_resolution_queue_audit = cow_merge_audit_report($metadata, null, 10, [
        'review' => '1',
        'review_status' => 'unreviewed',
        'records' => 'resolutions',
        'scope' => 'files',
        'path_prefix' => 'wp-content/uploads',
    ]);
    assert_same($file_resolution_queue_audit['filters']['review'], true, 'file resolution review queue preserves the review shortcut filter');
    assert_same($file_resolution_queue_audit['filters']['review_status'], 'unreviewed', 'file resolution review queue preserves the unreviewed filter');
    assert_same($file_resolution_queue_audit['filters']['records'], 'resolutions', 'file resolution review queue focuses on resolution records');
    assert_same($file_resolution_queue_audit['filters']['scope'], 'files', 'file resolution review queue focuses on filesystem records');
    assert_same(count($file_resolution_queue_audit['conflicts']), 0, 'file resolution review queue omits conflicts');
    assert_same(count($file_resolution_queue_audit['decisions']), 0, 'file resolution review queue omits decisions');
    assert_true(count($file_resolution_queue_audit['resolutions']) >= 1, 'file resolution review queue returns unreviewed filesystem resolutions');
    $file_resolution_queue_ids = array_map(fn($row) => (int)$row['id'], $file_resolution_queue_audit['resolutions']);
    assert_true(in_array($unreviewed_file_resolution_id, $file_resolution_queue_ids, true), 'file resolution review queue includes unreviewed filesystem resolutions');
    assert_true(!in_array($reviewed_file_resolution_id, $file_resolution_queue_ids, true), 'file resolution review queue excludes reviewed filesystem resolutions');
    foreach ($file_resolution_queue_audit['resolutions'] as $row) {
        assert_same($row['review_status'], null, 'file resolution review queue returns only unreviewed records');
        assert_same($row['table_name'], '__files__', 'file resolution review queue excludes database records');
    }
    $unsafe_symlink_id = (int)scalar($metadata, "SELECT c.id FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = '__files__' AND c.conflict_type = 'file-unsafe-symlink' AND r.source_branch = 'feature-file-resolve' ORDER BY c.id DESC LIMIT 1");
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $unsafe_symlink_id, 'source', true, 'Try unsafe source symlink.', 'cow-test'),
        'cannot apply source filesystem conflict',
        'unsafe source symlink conflicts cannot be applied by the deterministic resolver'
    );

    $file_target_keep_base_root = $tmp . '/files-target-keep-base';
    $file_target_keep_source_root = $tmp . '/files-target-keep-source';
    $file_target_keep_target_root = $tmp . '/files-target-keep-target';
    mkdir($file_target_keep_base_root . '/wp-content/uploads', 0777, true);
    write_test_file($file_target_keep_base_root . '/wp-content/uploads/keep-target.txt', 'base keep target');
    copy_tree_for_test($file_target_keep_base_root, $file_target_keep_source_root);
    copy_tree_for_test($file_target_keep_base_root, $file_target_keep_target_root);
    $file_target_keep_base_db = $file_target_keep_base_root . '/wp-content/database/.ht.sqlite';
    $file_target_keep_source_db = $file_target_keep_source_root . '/wp-content/database/.ht.sqlite';
    $file_target_keep_target_db = $file_target_keep_target_root . '/wp-content/database/.ht.sqlite';
    mkdir(dirname($file_target_keep_base_db), 0777, true);
    mkdir(dirname($file_target_keep_source_db), 0777, true);
    mkdir(dirname($file_target_keep_target_db), 0777, true);
    create_base_db($file_target_keep_base_db);
    create_base_db($file_target_keep_source_db);
    create_base_db($file_target_keep_target_db);
    $file_target_keep_manifest = $tmp . '/.forkpress/cow/merge/file-bases/feature-file-target-keep.json';
    cow_merge_capture_file_base($file_target_keep_base_root, $file_target_keep_manifest);
    write_test_file($file_target_keep_source_root . '/wp-content/uploads/keep-target.txt', 'source target-choice file');
    write_test_file($file_target_keep_target_root . '/wp-content/uploads/keep-target.txt', 'target target-choice file');
    $file_target_keep_result = cow_merge_branch_state(
        $file_target_keep_base_db,
        $file_target_keep_source_db,
        $file_target_keep_target_db,
        $metadata,
        'feature-file-target-keep',
        'main',
        $file_target_keep_manifest,
        $file_target_keep_source_root,
        $file_target_keep_target_root
    );
    assert_same($file_target_keep_result['status'], 'completed_with_conflicts', 'filesystem target-choice fixture starts with a reviewable conflict');
    $file_target_keep_conflict_id = (int)scalar($metadata, "SELECT c.id FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = '__files__' AND c.conflict_type = 'file-conflict' AND c.row_identity = '" . SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/keep-target.txt')) . "' AND r.source_branch = 'feature-file-target-keep' ORDER BY c.id DESC LIMIT 1");
    $file_target_keep_resolution = cow_merge_resolve_conflict(
        $metadata,
        $file_target_keep_conflict_id,
        'target',
        true,
        'Keep audited target file.',
        'cow-test'
    );
    assert_same($file_target_keep_resolution['status'], 'validated', 'target filesystem resolution records validated status');
    assert_same(file_get_contents($file_target_keep_target_root . '/wp-content/uploads/keep-target.txt'), 'target target-choice file', 'target filesystem resolution leaves target file unchanged');
    $file_target_keep_rerun = cow_merge_branch_state(
        $file_target_keep_base_db,
        $file_target_keep_source_db,
        $file_target_keep_target_db,
        $metadata,
        'feature-file-target-keep',
        'main',
        $file_target_keep_manifest,
        $file_target_keep_source_root,
        $file_target_keep_target_root
    );
    assert_same($file_target_keep_rerun['status'], 'completed', 'rerunning after target filesystem resolution treats the reviewed target choice as accepted');
    assert_same(file_get_contents($file_target_keep_target_root . '/wp-content/uploads/keep-target.txt'), 'target target-choice file', 'rerunning after target filesystem resolution keeps the audited target file');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = '__files__' AND c.conflict_type = 'file-conflict' AND c.row_identity = '" . SQLite3::escapeString(cow_merge_file_identity_json('wp-content/uploads/keep-target.txt')) . "' AND r.source_branch = 'feature-file-target-keep'"),
        1,
        'rerunning after target filesystem resolution does not duplicate the unchanged conflict record'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE d.table_name = '__files__' AND d.column_name = 'path' AND d.decision = 'target-accepted' AND r.source_branch = 'feature-file-target-keep'"),
        1,
        'rerunning after target filesystem resolution records the accepted target choice as an auditable decision'
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
    $db->exec('ALTER TABLE plugin_items ADD COLUMN shared_note TEXT');
    $db->exec('CREATE INDEX plugin_items_label_idx ON plugin_items(label)');
    $db->exec("UPDATE plugin_items SET extra = 'source-only schema value' WHERE item_id = 'alpha'");
    $db->close();

    $db = open_db($schema_target);
    $db->exec('ALTER TABLE plugin_items ADD COLUMN shared_note TEXT');
    $db->exec('ALTER TABLE plugin_items ADD COLUMN target_note TEXT');
    $db->exec('CREATE INDEX plugin_items_target_note_idx ON plugin_items(target_note)');
    $db->exec("UPDATE plugin_items SET target_note = 'target-only schema value' WHERE item_id = 'alpha'");
    $db->close();

    $result = cow_merge_databases($schema_base, $schema_source, $schema_target, $metadata, 'feature-schema', 'main');
    assert_same($result['status'], 'completed', 'independent safe schema additions merge cleanly');
    assert_same(scalar($schema_target, "SELECT extra FROM plugin_items WHERE item_id = 'alpha'"), 'source-only schema value', 'source-added column is added to target and row value is merged');
    assert_same(column_type($schema_target, 'plugin_items', 'shared_note'), 'TEXT', 'shared source/target-added column remains present');
    assert_same(scalar($schema_target, "SELECT target_note FROM plugin_items WHERE item_id = 'alpha'"), 'target-only schema value', 'target-added column value is preserved');
    assert_same((int)scalar($schema_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_items_label_idx'"), 1, 'source-added index is created on target');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_items' AND column_name = 'extra' AND row_identity IS NULL AND decision = 'source-applied'"), 1, 'source-added column decision is auditable');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_items' AND column_name = 'shared_note' AND row_identity IS NULL AND decision = 'source-applied' AND reason = 'source and target added the same table column'"), 1, 'shared source/target-added column decision is auditable inside divergent table schema');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_items' AND column_name = 'plugin_items_label_idx' AND decision = 'source-applied'"), 1, 'source-added index decision is auditable');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_items' AND column_name = 'target_note' AND row_identity IS NULL AND decision = 'target-kept'"), 1, 'target-added column preservation is auditable');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_items' AND column_name = 'plugin_items_target_note_idx' AND decision = 'target-kept'"), 1, 'target-added index preservation is auditable');

    $schema_index_validate_base = $tmp . '/schema-index-validate-base.sqlite';
    $schema_index_validate_source = $tmp . '/schema-index-validate-source.sqlite';
    $schema_index_validate_target = $tmp . '/schema-index-validate-target.sqlite';
    create_base_db($schema_index_validate_base);
    $db = open_db($schema_index_validate_base);
    $db->exec('CREATE TABLE plugin_index_validate (id INTEGER PRIMARY KEY, label TEXT)');
    $db->exec("INSERT INTO plugin_index_validate (id, label) VALUES (1, 'Alpha')");
    $db->close();
    copy($schema_index_validate_base, $schema_index_validate_source);
    copy($schema_index_validate_base, $schema_index_validate_target);

    $db = open_db($schema_index_validate_source);
    $db->exec('CREATE UNIQUE INDEX plugin_index_validate_lower_idx ON plugin_index_validate(lower(label))');
    $db->close();

    $db = open_db($schema_index_validate_target);
    $db->exec("INSERT INTO plugin_index_validate (id, label) VALUES (2, 'alpha')");
    $db->close();

    $schema_index_validate_result = cow_merge_databases($schema_index_validate_base, $schema_index_validate_source, $schema_index_validate_target, $metadata, 'feature-schema-index-validate', 'main');
    assert_same($schema_index_validate_result['status'], 'completed_with_conflicts', 'source-added expression unique index blocked by target rows is audited');
    assert_same((int)scalar($schema_index_validate_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_index_validate_lower_idx'"), 0, 'rejected source-added index is not installed on target');
    assert_same((int)scalar($schema_index_validate_target, "SELECT COUNT(*) FROM plugin_index_validate WHERE id = 2 AND label = 'alpha'"), 1, 'target row that blocks source-added index is preserved by default');
    $schema_index_validate_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_index_validate' AND column_name = 'plugin_index_validate_lower_idx' AND conflict_type = 'schema-source-added-index' ORDER BY id DESC LIMIT 1");
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $schema_index_validate_conflict_id, 'source', false, 'Preview blocked source index.', 'test'),
        'UNIQUE constraint failed',
        'dry-run source index resolution validates target rows before reporting success'
    );
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_index_validate_conflict_id"), 0, 'failed source index dry-run does not record resolution metadata');
    assert_same((int)scalar($schema_index_validate_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_index_validate_lower_idx'"), 0, 'failed source index dry-run leaves target schema unchanged');

    $db = open_db($schema_index_validate_target);
    $db->exec('DELETE FROM plugin_index_validate WHERE id = 2');
    $db->close();
    $schema_index_validate_dry = cow_merge_resolve_conflict(
        $metadata,
        $schema_index_validate_conflict_id,
        'source',
        false,
        'Preview source index after row review.',
        'test'
    );
    assert_same($schema_index_validate_dry['status'], 'validated', 'source index dry-run validates after target rows no longer violate the index');
    assert_same((int)scalar($schema_index_validate_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_index_validate_lower_idx'"), 0, 'successful source index dry-run still leaves target schema unchanged');
    $schema_index_validate_apply = cow_merge_resolve_conflict(
        $metadata,
        $schema_index_validate_conflict_id,
        'source',
        true,
        'Apply source index after row review.',
        'test'
    );
    assert_same($schema_index_validate_apply['status'], 'applied', 'source index resolution applies after validation passes');
    assert_same((int)scalar($schema_index_validate_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_index_validate_lower_idx'"), 1, 'source index resolution installs the audited expression index');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_index_validate_conflict_id AND choice = 'source' AND applied = 1"), 1, 'successful source index resolution is auditable');

    $schema_fk_index_drop_base = $tmp . '/schema-fk-index-drop-base.sqlite';
    $schema_fk_index_drop_source = $tmp . '/schema-fk-index-drop-source.sqlite';
    $schema_fk_index_drop_target = $tmp . '/schema-fk-index-drop-target.sqlite';
    create_base_db($schema_fk_index_drop_base);
    $db = open_db($schema_fk_index_drop_base);
    $db->exec('CREATE TABLE plugin_fk_index_drop_parent (code TEXT NOT NULL, label TEXT)');
    $db->exec('CREATE UNIQUE INDEX plugin_fk_index_drop_parent_code_idx ON plugin_fk_index_drop_parent(code)');
    $db->exec('CREATE TABLE plugin_fk_index_drop_child (parent_code TEXT NOT NULL REFERENCES plugin_fk_index_drop_parent(code), label TEXT)');
    $db->exec("INSERT INTO plugin_fk_index_drop_parent (code, label) VALUES ('drop-parent', 'Drop parent')");
    $db->exec("INSERT INTO plugin_fk_index_drop_child (parent_code, label) VALUES ('drop-parent', 'Drop child')");
    $db->close();
    copy($schema_fk_index_drop_base, $schema_fk_index_drop_source);
    copy($schema_fk_index_drop_base, $schema_fk_index_drop_target);

    $db = open_db($schema_fk_index_drop_source);
    $db->exec('DROP INDEX plugin_fk_index_drop_parent_code_idx');
    $db->close();

    $schema_fk_index_drop_result = cow_merge_databases($schema_fk_index_drop_base, $schema_fk_index_drop_source, $schema_fk_index_drop_target, $metadata, 'feature-schema-fk-index-drop', 'main');
    assert_same($schema_fk_index_drop_result['status'], 'completed_with_conflicts', 'source-dropped foreign-key parent index stays validation-gated');
    $schema_fk_index_drop_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_fk_index_drop_parent' AND column_name = 'plugin_fk_index_drop_parent_code_idx' AND conflict_type = 'schema-source-dropped-index' ORDER BY id DESC LIMIT 1");
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $schema_fk_index_drop_conflict_id, 'source', false, 'Preview FK parent index drop.', 'test'),
        'foreign-key validation error',
        'dry-run source index drop rejects latent foreign-key mismatch before reporting success'
    );
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_fk_index_drop_conflict_id"), 0, 'failed source index drop dry-run does not record resolution metadata');
    assert_same((int)scalar($schema_fk_index_drop_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_fk_index_drop_parent_code_idx'"), 1, 'failed source index drop dry-run rolls back the target index');
    $db = open_db($schema_fk_index_drop_target);
    $db->exec('DROP TABLE plugin_fk_index_drop_child');
    $db->close();
    $schema_fk_index_drop_apply = cow_merge_resolve_conflict(
        $metadata,
        $schema_fk_index_drop_conflict_id,
        'source',
        true,
        'Apply FK parent index drop after child schema review.',
        'test'
    );
    assert_same($schema_fk_index_drop_apply['status'], 'applied', 'source index drop applies after dependent child schema is handled');
    assert_same((int)scalar($schema_fk_index_drop_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_fk_index_drop_parent_code_idx'"), 0, 'validated source index drop removes the foreign-key parent index');

    $schema_fk_index_rewrite_base = $tmp . '/schema-fk-index-rewrite-base.sqlite';
    $schema_fk_index_rewrite_source = $tmp . '/schema-fk-index-rewrite-source.sqlite';
    $schema_fk_index_rewrite_target = $tmp . '/schema-fk-index-rewrite-target.sqlite';
    create_base_db($schema_fk_index_rewrite_base);
    $db = open_db($schema_fk_index_rewrite_base);
    $db->exec('CREATE TABLE plugin_fk_index_rewrite_parent (code TEXT NOT NULL, label TEXT)');
    $db->exec('CREATE UNIQUE INDEX plugin_fk_index_rewrite_parent_code_idx ON plugin_fk_index_rewrite_parent(code)');
    $db->exec('CREATE TABLE plugin_fk_index_rewrite_child (parent_code TEXT NOT NULL REFERENCES plugin_fk_index_rewrite_parent(code), label TEXT)');
    $db->exec("INSERT INTO plugin_fk_index_rewrite_parent (code, label) VALUES ('rewrite-parent', 'Rewrite parent')");
    $db->exec("INSERT INTO plugin_fk_index_rewrite_child (parent_code, label) VALUES ('rewrite-parent', 'Rewrite child')");
    $db->close();
    copy($schema_fk_index_rewrite_base, $schema_fk_index_rewrite_source);
    copy($schema_fk_index_rewrite_base, $schema_fk_index_rewrite_target);

    $db = open_db($schema_fk_index_rewrite_source);
    $db->exec('DROP INDEX plugin_fk_index_rewrite_parent_code_idx');
    $db->exec('CREATE UNIQUE INDEX plugin_fk_index_rewrite_parent_code_idx ON plugin_fk_index_rewrite_parent(lower(code))');
    $db->close();

    $schema_fk_index_rewrite_result = cow_merge_databases($schema_fk_index_rewrite_base, $schema_fk_index_rewrite_source, $schema_fk_index_rewrite_target, $metadata, 'feature-schema-fk-index-rewrite', 'main');
    assert_same($schema_fk_index_rewrite_result['status'], 'completed_with_conflicts', 'source-rewritten foreign-key parent index stays validation-gated');
    $schema_fk_index_rewrite_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_fk_index_rewrite_parent' AND column_name = 'plugin_fk_index_rewrite_parent_code_idx' AND conflict_type = 'schema-source-changed-index' ORDER BY id DESC LIMIT 1");
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $schema_fk_index_rewrite_conflict_id, 'source', false, 'Preview FK parent index rewrite.', 'test'),
        'foreign-key validation error',
        'dry-run source index rewrite rejects latent foreign-key mismatch before reporting success'
    );
    assert_same((int)scalar($schema_fk_index_rewrite_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_fk_index_rewrite_parent_code_idx' AND sql LIKE '%lower(code)%'"), 0, 'failed source index rewrite dry-run rolls back the target index SQL');
    $db = open_db($schema_fk_index_rewrite_target);
    $db->exec('DROP TABLE plugin_fk_index_rewrite_child');
    $db->close();
    $schema_fk_index_rewrite_apply = cow_merge_resolve_conflict(
        $metadata,
        $schema_fk_index_rewrite_conflict_id,
        'source',
        true,
        'Apply FK parent index rewrite after child schema review.',
        'test'
    );
    assert_same($schema_fk_index_rewrite_apply['status'], 'applied', 'source index rewrite applies after dependent child schema is handled');
    assert_same((int)scalar($schema_fk_index_rewrite_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_fk_index_rewrite_parent_code_idx' AND sql LIKE '%lower(code)%'"), 1, 'validated source index rewrite installs the audited source expression index');

    $schema_restore_fk_index_base = $tmp . '/schema-restore-fk-index-base.sqlite';
    $schema_restore_fk_index_source = $tmp . '/schema-restore-fk-index-source.sqlite';
    $schema_restore_fk_index_target = $tmp . '/schema-restore-fk-index-target.sqlite';
    create_base_db($schema_restore_fk_index_base);
    $db = open_db($schema_restore_fk_index_base);
    $db->exec('CREATE TABLE plugin_restore_fk_parent (code TEXT NOT NULL, label TEXT)');
    $db->exec('CREATE UNIQUE INDEX plugin_restore_fk_parent_code_idx ON plugin_restore_fk_parent(code)');
    $db->exec('CREATE TABLE plugin_restore_fk_child (parent_code TEXT NOT NULL REFERENCES plugin_restore_fk_parent(code), label TEXT)');
    $db->exec("INSERT INTO plugin_restore_fk_parent (code, label) VALUES ('restore-parent', 'Restore parent')");
    $db->exec("INSERT INTO plugin_restore_fk_child (parent_code, label) VALUES ('restore-parent', 'Restore child')");
    $db->close();
    copy($schema_restore_fk_index_base, $schema_restore_fk_index_source);
    copy($schema_restore_fk_index_base, $schema_restore_fk_index_target);

    $db = open_db($schema_restore_fk_index_source);
    $db->exec('DROP INDEX plugin_restore_fk_parent_code_idx');
    $db->close();
    $db = open_db($schema_restore_fk_index_target);
    $db->exec('DROP TABLE plugin_restore_fk_parent');
    $db->close();

    $schema_restore_fk_index_result = cow_merge_databases($schema_restore_fk_index_base, $schema_restore_fk_index_source, $schema_restore_fk_index_target, $metadata, 'feature-schema-restore-fk-index', 'main');
    assert_same($schema_restore_fk_index_result['status'], 'completed_with_conflicts', 'target-dropped FK parent table with source-dropped unique index remains reviewable');
    $schema_restore_fk_index_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_restore_fk_parent' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $schema_restore_fk_index_conflict_id, 'source', false, 'Preview parent restore without FK index.', 'test'),
        'foreign-key validation error',
        'dry-run source table restore rejects latent foreign-key mismatch before reporting success'
    );
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_restore_fk_index_conflict_id"), 0, 'failed source table restore dry-run does not record resolution metadata');
    assert_same((int)scalar($schema_restore_fk_index_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_restore_fk_parent'"), 0, 'failed source table restore dry-run rolls back the restored table');
    $db = open_db($schema_restore_fk_index_target);
    $db->exec('DROP TABLE plugin_restore_fk_child');
    $db->close();
    $schema_restore_fk_index_apply = cow_merge_resolve_conflict(
        $metadata,
        $schema_restore_fk_index_conflict_id,
        'source',
        true,
        'Apply parent restore after child schema review.',
        'test'
    );
    assert_same($schema_restore_fk_index_apply['status'], 'applied', 'source table restore applies after dependent child schema is handled');
    assert_same((int)scalar($schema_restore_fk_index_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_restore_fk_parent'"), 1, 'validated source table restore recreates the audited parent table');
    assert_same((int)scalar($schema_restore_fk_index_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_restore_fk_parent_code_idx'"), 0, 'validated source table restore keeps the audited source-dropped index state');

    $schema_restore_trigger_base = $tmp . '/schema-restore-trigger-base.sqlite';
    $schema_restore_trigger_source = $tmp . '/schema-restore-trigger-source.sqlite';
    $schema_restore_trigger_target = $tmp . '/schema-restore-trigger-target.sqlite';
    create_base_db($schema_restore_trigger_base);
    $db = open_db($schema_restore_trigger_base);
    $db->exec('CREATE TABLE plugin_restore_trigger_items (id INTEGER PRIMARY KEY, label TEXT)');
    $db->close();
    copy($schema_restore_trigger_base, $schema_restore_trigger_source);
    copy($schema_restore_trigger_base, $schema_restore_trigger_target);

    $db = open_db($schema_restore_trigger_source);
    $db->exec("INSERT INTO plugin_restore_trigger_items (id, label) VALUES (1, 'Trigger restore')");
    $db->exec('CREATE TRIGGER plugin_restore_trigger_bad_insert AFTER INSERT ON plugin_restore_trigger_items BEGIN SELECT OLD.label; END');
    $db->close();
    $db = open_db($schema_restore_trigger_target);
    $db->exec('DROP TABLE plugin_restore_trigger_items');
    $db->close();

    $schema_restore_trigger_result = cow_merge_databases($schema_restore_trigger_base, $schema_restore_trigger_source, $schema_restore_trigger_target, $metadata, 'feature-schema-restore-trigger-validation', 'main');
    assert_same($schema_restore_trigger_result['status'], 'completed_with_conflicts', 'target-dropped table restore with invalid source trigger remains reviewable');
    $schema_restore_trigger_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_restore_trigger_items' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    $schema_restore_trigger_object_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_restore_trigger_bad_insert' AND conflict_type = 'schema-source-added-trigger' ORDER BY id DESC LIMIT 1");
    assert_true($schema_restore_trigger_object_conflict_id > 0, 'invalid source-added table trigger remains a separate schema conflict');
    $schema_restore_trigger_preview = cow_merge_resolve_conflict($metadata, $schema_restore_trigger_conflict_id, 'source', false, 'Preview table restore while deferring invalid source trigger.', 'test');
    assert_same($schema_restore_trigger_preview['status'], 'validated', 'dry-run source table restore validates while deferring invalid source-added trigger');
    assert_same((int)scalar($schema_restore_trigger_target, "SELECT COUNT(*) FROM sqlite_master WHERE name = 'plugin_restore_trigger_items'"), 0, 'successful trigger-deferred restore dry-run leaves target table absent');
    $schema_restore_trigger_apply = cow_merge_resolve_conflict($metadata, $schema_restore_trigger_conflict_id, 'source', true, 'Apply table restore while deferring invalid source trigger.', 'test');
    assert_same($schema_restore_trigger_apply['status'], 'applied', 'source table restore applies while invalid source-added trigger stays separate');
    assert_same((int)scalar($schema_restore_trigger_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_restore_trigger_items'"), 1, 'trigger-deferred table restore recreates the table');
    assert_same((int)scalar($schema_restore_trigger_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_restore_trigger_bad_insert'"), 0, 'trigger-deferred table restore does not install the invalid source-added trigger');
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $schema_restore_trigger_object_conflict_id, 'source', false, 'Preview invalid source trigger after table restore.', 'test'),
        'failed target trigger validation',
        'invalid deferred source trigger remains validation-gated after table restore'
    );
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_restore_trigger_object_conflict_id"), 0, 'failed deferred trigger dry-run does not record resolution metadata');
    assert_same((int)scalar($schema_restore_trigger_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_restore_trigger_bad_insert'"), 0, 'failed deferred trigger dry-run rolls back the invalid trigger');

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
    $schema_target_resolution_rerun = cow_merge_databases($schema_conflict_base, $schema_conflict_source, $schema_conflict_target, $metadata, 'feature-schema-conflict', 'main');
    assert_same($schema_target_resolution_rerun['status'], 'completed', 'rerunning after target schema resolution treats the reviewed target choice as accepted');
    assert_same(column_type($schema_conflict_target, 'plugin_items', 'extra'), 'INTEGER', 'rerunning after target schema resolution keeps the audited target schema');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_items' AND c.column_name = 'extra' AND c.conflict_type = 'schema-column-conflict' AND r.source_branch = 'feature-schema-conflict'"),
        1,
        'rerunning after target schema resolution does not duplicate the unchanged conflict record'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE d.table_name = 'plugin_items' AND d.column_name = 'extra' AND d.decision = 'target-accepted' AND r.source_branch = 'feature-schema-conflict'"),
        1,
        'rerunning after target schema resolution records the accepted target choice as an auditable decision'
    );

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
    $schema_rebuild_rerun = cow_merge_databases($schema_rebuild_base, $schema_rebuild_source, $schema_rebuild_target, $metadata, 'feature-schema-rebuild', 'main');
    assert_same($schema_rebuild_rerun['status'], 'completed', 'rerunning after compatible source table rebuild resolution completes without a new conflict');
    assert_same(column_type($schema_rebuild_target, 'plugin_items', 'value'), 'INTEGER', 'rerunning after source table rebuild keeps the audited source column definition');
    assert_same(scalar($schema_rebuild_target, "SELECT value FROM plugin_items WHERE item_id = 'alpha'"), 'target preserved', 'rerunning after source table rebuild preserves target row data');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_items' AND c.column_name IS NULL AND c.conflict_type = 'schema-conflict' AND r.source_branch = 'feature-schema-rebuild'"),
        1,
        'rerunning after compatible source table rebuild resolution does not rediscover the resolved schema conflict'
    );

    $schema_rebuild_fk_base = $tmp . '/schema-rebuild-fk-base.sqlite';
    $schema_rebuild_fk_source = $tmp . '/schema-rebuild-fk-source.sqlite';
    $schema_rebuild_fk_target = $tmp . '/schema-rebuild-fk-target.sqlite';
    create_base_db($schema_rebuild_fk_base);
    $db = open_db($schema_rebuild_fk_base);
    $db->exec('CREATE TABLE plugin_rebuild_fk_parent (code TEXT NOT NULL UNIQUE, label TEXT)');
    $db->exec('CREATE TABLE plugin_rebuild_fk_child (parent_code TEXT NOT NULL REFERENCES plugin_rebuild_fk_parent(code), label TEXT)');
    $db->exec("INSERT INTO plugin_rebuild_fk_parent (code, label) VALUES ('rebuild-parent', 'Rebuild parent')");
    $db->exec("INSERT INTO plugin_rebuild_fk_child (parent_code, label) VALUES ('rebuild-parent', 'Rebuild child')");
    $db->close();
    copy($schema_rebuild_fk_base, $schema_rebuild_fk_source);
    copy($schema_rebuild_fk_base, $schema_rebuild_fk_target);

    $db = open_db($schema_rebuild_fk_source);
    $db->exec('CREATE TABLE plugin_rebuild_fk_parent_new (code TEXT NOT NULL, label INTEGER)');
    $db->exec('INSERT INTO plugin_rebuild_fk_parent_new (code, label) SELECT code, label FROM plugin_rebuild_fk_parent');
    $db->exec('DROP TABLE plugin_rebuild_fk_parent');
    $db->exec('ALTER TABLE plugin_rebuild_fk_parent_new RENAME TO plugin_rebuild_fk_parent');
    $db->close();
    $db = open_db($schema_rebuild_fk_target);
    $db->exec('CREATE TABLE plugin_rebuild_fk_parent_new (code TEXT NOT NULL UNIQUE, label REAL)');
    $db->exec('INSERT INTO plugin_rebuild_fk_parent_new (code, label) SELECT code, label FROM plugin_rebuild_fk_parent');
    $db->exec('DROP TABLE plugin_rebuild_fk_parent');
    $db->exec('ALTER TABLE plugin_rebuild_fk_parent_new RENAME TO plugin_rebuild_fk_parent');
    $db->close();

    $schema_rebuild_fk_result = cow_merge_databases($schema_rebuild_fk_base, $schema_rebuild_fk_source, $schema_rebuild_fk_target, $metadata, 'feature-schema-rebuild-fk', 'main');
    assert_same($schema_rebuild_fk_result['status'], 'completed_with_conflicts', 'compatible source table rebuild that would invalidate target FKs stays reviewable');
    $schema_rebuild_fk_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_rebuild_fk_parent' AND conflict_type = 'schema-conflict' ORDER BY id DESC LIMIT 1");
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $schema_rebuild_fk_conflict_id, 'source', false, 'Preview FK-sensitive table rebuild.', 'test'),
        'foreign-key validation error',
        'dry-run compatible table rebuild rejects latent foreign-key mismatch before reporting success'
    );
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_rebuild_fk_conflict_id"), 0, 'failed table rebuild dry-run does not record resolution metadata');
    assert_same(column_type($schema_rebuild_fk_target, 'plugin_rebuild_fk_parent', 'label'), 'REAL', 'failed table rebuild dry-run rolls back target table schema');
    $db = open_db($schema_rebuild_fk_target);
    $db->exec('DROP TABLE plugin_rebuild_fk_child');
    $db->close();
    $schema_rebuild_fk_apply = cow_merge_resolve_conflict(
        $metadata,
        $schema_rebuild_fk_conflict_id,
        'source',
        true,
        'Apply FK-sensitive table rebuild after child schema review.',
        'test'
    );
    assert_same($schema_rebuild_fk_apply['status'], 'applied', 'compatible table rebuild applies after dependent child schema is handled');
    assert_same(column_type($schema_rebuild_fk_target, 'plugin_rebuild_fk_parent', 'label'), 'INTEGER', 'validated table rebuild applies the audited source column type');
    assert_true(!str_contains((string)scalar($schema_rebuild_fk_target, "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'plugin_rebuild_fk_parent'"), 'UNIQUE'), 'validated table rebuild keeps the audited source parent key shape');

    $schema_rebuild_trigger_fk_base = $tmp . '/schema-rebuild-trigger-fk-base.sqlite';
    $schema_rebuild_trigger_fk_source = $tmp . '/schema-rebuild-trigger-fk-source.sqlite';
    $schema_rebuild_trigger_fk_target = $tmp . '/schema-rebuild-trigger-fk-target.sqlite';
    create_base_db($schema_rebuild_trigger_fk_base);
    $db = open_db($schema_rebuild_trigger_fk_base);
    $db->exec('CREATE TABLE plugin_rebuild_trigger_fk_parent (code TEXT NOT NULL PRIMARY KEY, label TEXT NOT NULL)');
    $db->exec('CREATE UNIQUE INDEX plugin_rebuild_trigger_fk_parent_label_idx ON plugin_rebuild_trigger_fk_parent(label)');
    $db->exec('CREATE TABLE plugin_rebuild_trigger_fk_child (parent_label TEXT NOT NULL REFERENCES plugin_rebuild_trigger_fk_parent(label), note TEXT)');
    $db->exec('CREATE TABLE plugin_rebuild_trigger_fk_audit (seen_rowid TEXT, code TEXT)');
    $db->exec("INSERT INTO plugin_rebuild_trigger_fk_parent (code, label) VALUES ('parent-code', 'parent-label')");
    $db->exec("INSERT INTO plugin_rebuild_trigger_fk_child (parent_label, note) VALUES ('parent-label', 'base child')");
    $db->close();
    copy($schema_rebuild_trigger_fk_base, $schema_rebuild_trigger_fk_source);
    copy($schema_rebuild_trigger_fk_base, $schema_rebuild_trigger_fk_target);

    $db = open_db($schema_rebuild_trigger_fk_source);
    $db->exec('DROP INDEX plugin_rebuild_trigger_fk_parent_label_idx');
    $db->exec('CREATE TABLE plugin_rebuild_trigger_fk_parent_new (code TEXT NOT NULL PRIMARY KEY, label TEXT NOT NULL) WITHOUT ROWID');
    $db->exec('INSERT INTO plugin_rebuild_trigger_fk_parent_new (code, label) SELECT code, label FROM plugin_rebuild_trigger_fk_parent');
    $db->exec('DROP TABLE plugin_rebuild_trigger_fk_parent');
    $db->exec('ALTER TABLE plugin_rebuild_trigger_fk_parent_new RENAME TO plugin_rebuild_trigger_fk_parent');
    $db->exec('CREATE UNIQUE INDEX plugin_rebuild_trigger_fk_parent_label_idx ON plugin_rebuild_trigger_fk_parent(label)');
    $db->close();
    $db = open_db($schema_rebuild_trigger_fk_target);
    $db->exec('DROP INDEX plugin_rebuild_trigger_fk_parent_label_idx');
    $db->exec('CREATE TABLE plugin_rebuild_trigger_fk_parent_new (code TEXT NOT NULL PRIMARY KEY, label NUMERIC NOT NULL)');
    $db->exec('INSERT INTO plugin_rebuild_trigger_fk_parent_new (code, label) SELECT code, label FROM plugin_rebuild_trigger_fk_parent');
    $db->exec('DROP TABLE plugin_rebuild_trigger_fk_parent');
    $db->exec('ALTER TABLE plugin_rebuild_trigger_fk_parent_new RENAME TO plugin_rebuild_trigger_fk_parent');
    $db->exec('CREATE UNIQUE INDEX plugin_rebuild_trigger_fk_parent_label_idx ON plugin_rebuild_trigger_fk_parent(label)');
    $db->exec('CREATE TRIGGER plugin_rebuild_trigger_fk_parent_insert AFTER INSERT ON plugin_rebuild_trigger_fk_parent BEGIN INSERT INTO plugin_rebuild_trigger_fk_audit (seen_rowid, code) VALUES (NEW.rowid, NEW.code); END');
    $db->close();

    $schema_rebuild_trigger_fk_result = cow_merge_databases(
        $schema_rebuild_trigger_fk_base,
        $schema_rebuild_trigger_fk_source,
        $schema_rebuild_trigger_fk_target,
        $metadata,
        'feature-schema-rebuild-trigger-fk',
        'main'
    );
    assert_same($schema_rebuild_trigger_fk_result['status'], 'completed_with_conflicts', 'compatible table rebuild with preserved trigger and index-backed FK remains reviewable');
    $schema_rebuild_trigger_fk_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_rebuild_trigger_fk_parent' AND conflict_type = 'schema-conflict' ORDER BY id DESC LIMIT 1");
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $schema_rebuild_trigger_fk_conflict_id, 'source', false, 'Preview rowid-sensitive table rebuild.', 'test'),
        'no such column: NEW.rowid',
        'dry-run table rebuild rejects a preserved trigger that would become invalid after source schema application'
    );
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_rebuild_trigger_fk_conflict_id"), 0, 'failed trigger-sensitive table rebuild dry-run does not record resolution metadata');
    assert_same(column_type($schema_rebuild_trigger_fk_target, 'plugin_rebuild_trigger_fk_parent', 'label'), 'NUMERIC', 'failed trigger-sensitive table rebuild dry-run rolls back target table schema');
    assert_same((int)scalar($schema_rebuild_trigger_fk_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_rebuild_trigger_fk_parent_label_idx'"), 1, 'failed trigger-sensitive table rebuild dry-run preserves target FK backing index');
    assert_same((int)scalar($schema_rebuild_trigger_fk_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_rebuild_trigger_fk_parent_insert'"), 1, 'failed trigger-sensitive table rebuild dry-run preserves target trigger');
    $db = open_db($schema_rebuild_trigger_fk_target);
    $db->exec("INSERT INTO plugin_rebuild_trigger_fk_parent (code, label) VALUES ('target-rowid-parent', 'target-rowid-label')");
    $db->exec("INSERT INTO plugin_rebuild_trigger_fk_child (parent_label, note) VALUES ('target-rowid-label', 'target child still validates')");
    $db->close();
    assert_same(scalar($schema_rebuild_trigger_fk_target, "SELECT code FROM plugin_rebuild_trigger_fk_audit WHERE code = 'target-rowid-parent'"), 'target-rowid-parent', 'preserved target trigger still fires after failed table rebuild dry-run');
    assert_same(scalar($schema_rebuild_trigger_fk_target, "SELECT note FROM plugin_rebuild_trigger_fk_child WHERE parent_label = 'target-rowid-label'"), 'target child still validates', 'preserved target FK backing index still supports child inserts after failed table rebuild dry-run');
    $db = open_db($schema_rebuild_trigger_fk_target);
    $db->exec('DROP TRIGGER plugin_rebuild_trigger_fk_parent_insert');
    $db->close();
    $schema_rebuild_trigger_fk_apply = cow_merge_resolve_conflict(
        $metadata,
        $schema_rebuild_trigger_fk_conflict_id,
        'source',
        true,
        'Apply rowid-sensitive table rebuild after trigger review.',
        'test'
    );
    assert_same($schema_rebuild_trigger_fk_apply['status'], 'applied', 'table rebuild applies after the invalid preserved trigger is handled');
    assert_true(str_contains((string)scalar($schema_rebuild_trigger_fk_target, "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'plugin_rebuild_trigger_fk_parent'"), 'WITHOUT ROWID'), 'validated trigger-sensitive table rebuild applies the audited source WITHOUT ROWID schema');
    assert_same((int)scalar($schema_rebuild_trigger_fk_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_rebuild_trigger_fk_parent_label_idx'"), 1, 'validated trigger-sensitive table rebuild preserves the FK backing index');
    assert_same(scalar($schema_rebuild_trigger_fk_target, "SELECT note FROM plugin_rebuild_trigger_fk_child WHERE parent_label = 'parent-label'"), 'base child', 'validated trigger-sensitive table rebuild keeps existing index-backed child rows valid');

    $schema_rebuild_view_rollback_base = $tmp . '/schema-rebuild-view-rollback-base.sqlite';
    $schema_rebuild_view_rollback_source = $tmp . '/schema-rebuild-view-rollback-source.sqlite';
    $schema_rebuild_view_rollback_target = $tmp . '/schema-rebuild-view-rollback-target.sqlite';
    create_base_db($schema_rebuild_view_rollback_base);
    $db = open_db($schema_rebuild_view_rollback_base);
    $db->exec('CREATE TABLE plugin_rebuild_view_parent (code TEXT NOT NULL PRIMARY KEY, label TEXT NOT NULL)');
    $db->exec("INSERT INTO plugin_rebuild_view_parent (code, label) VALUES ('view-parent', 'view label')");
    $db->close();
    copy($schema_rebuild_view_rollback_base, $schema_rebuild_view_rollback_source);
    copy($schema_rebuild_view_rollback_base, $schema_rebuild_view_rollback_target);

    $db = open_db($schema_rebuild_view_rollback_source);
    $db->exec('CREATE TABLE plugin_rebuild_view_parent_new (code TEXT NOT NULL PRIMARY KEY, label TEXT NOT NULL) WITHOUT ROWID');
    $db->exec('INSERT INTO plugin_rebuild_view_parent_new (code, label) SELECT code, label FROM plugin_rebuild_view_parent');
    $db->exec('DROP TABLE plugin_rebuild_view_parent');
    $db->exec('ALTER TABLE plugin_rebuild_view_parent_new RENAME TO plugin_rebuild_view_parent');
    $db->close();
    $db = open_db($schema_rebuild_view_rollback_target);
    $db->exec('CREATE TABLE plugin_rebuild_view_parent_new (code TEXT NOT NULL PRIMARY KEY, label NUMERIC NOT NULL)');
    $db->exec('INSERT INTO plugin_rebuild_view_parent_new (code, label) SELECT code, label FROM plugin_rebuild_view_parent');
    $db->exec('DROP TABLE plugin_rebuild_view_parent');
    $db->exec('ALTER TABLE plugin_rebuild_view_parent_new RENAME TO plugin_rebuild_view_parent');
    $db->exec('CREATE VIEW plugin_rebuild_view_parent_rowids AS SELECT rowid, code FROM plugin_rebuild_view_parent');
    $db->close();

    $schema_rebuild_view_rollback_result = cow_merge_databases(
        $schema_rebuild_view_rollback_base,
        $schema_rebuild_view_rollback_source,
        $schema_rebuild_view_rollback_target,
        $metadata,
        'feature-schema-rebuild-view-rollback',
        'main'
    );
    assert_same($schema_rebuild_view_rollback_result['status'], 'completed_with_conflicts', 'compatible table rebuild with preserved rowid view remains reviewable');
    $schema_rebuild_view_rollback_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_rebuild_view_parent' AND conflict_type = 'schema-conflict' ORDER BY id DESC LIMIT 1");
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $schema_rebuild_view_rollback_conflict_id, 'source', false, 'Preview rowid-view table rebuild.', 'test'),
        'plugin_rebuild_view_parent_rowids',
        'dry-run table rebuild rejects a preserved target view that would become invalid after source schema application'
    );
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_rebuild_view_rollback_conflict_id"), 0, 'failed view-sensitive table rebuild dry-run does not record resolution metadata');
    assert_same(column_type($schema_rebuild_view_rollback_target, 'plugin_rebuild_view_parent', 'label'), 'NUMERIC', 'failed view-sensitive table rebuild dry-run rolls back target table schema');
    assert_same((int)scalar($schema_rebuild_view_rollback_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_rebuild_view_parent_rowids'"), 1, 'failed view-sensitive table rebuild dry-run preserves target view');
    assert_same(scalar($schema_rebuild_view_rollback_target, "SELECT code FROM plugin_rebuild_view_parent_rowids WHERE code = 'view-parent'"), 'view-parent', 'preserved target view remains queryable after failed table rebuild dry-run');
    $db = open_db($schema_rebuild_view_rollback_target);
    $db->exec('DROP VIEW plugin_rebuild_view_parent_rowids');
    $db->close();
    $schema_rebuild_view_rollback_apply = cow_merge_resolve_conflict(
        $metadata,
        $schema_rebuild_view_rollback_conflict_id,
        'source',
        true,
        'Apply rowid-view table rebuild after view review.',
        'test'
    );
    assert_same($schema_rebuild_view_rollback_apply['status'], 'applied', 'table rebuild applies after the invalid preserved view is handled');
    assert_true(str_contains((string)scalar($schema_rebuild_view_rollback_target, "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'plugin_rebuild_view_parent'"), 'WITHOUT ROWID'), 'validated view-sensitive table rebuild applies the audited source WITHOUT ROWID schema');
    assert_same((int)scalar($schema_rebuild_view_rollback_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_rebuild_view_parent_rowids'"), 0, 'validated view-sensitive table rebuild leaves the reviewed target view removed');

    $schema_keyless_rebuild_base = $tmp . '/schema-keyless-rebuild-base.sqlite';
    $schema_keyless_rebuild_source = $tmp . '/schema-keyless-rebuild-source.sqlite';
    $schema_keyless_rebuild_target = $tmp . '/schema-keyless-rebuild-target.sqlite';
    $schema_keyless_rebuild_metadata = $tmp . '/.forkpress/cow/merge/schema-keyless-rebuild-metadata.sqlite';
    create_base_db($schema_keyless_rebuild_base);
    $db = open_db($schema_keyless_rebuild_base);
    $db->exec('CREATE TABLE plugin_keyless_schema_rebuild (label TEXT, value TEXT)');
    $db->exec("INSERT INTO plugin_keyless_schema_rebuild (rowid, label, value) VALUES (1, 'Dense keyless', 'dense')");
    $db->exec("INSERT INTO plugin_keyless_schema_rebuild (rowid, label, value) VALUES (7, 'Sparse keyless', 'sparse')");
    $db->close();
    copy($schema_keyless_rebuild_base, $schema_keyless_rebuild_source);
    copy($schema_keyless_rebuild_base, $schema_keyless_rebuild_target);
    cow_merge_capture_row_identities($schema_keyless_rebuild_target, $schema_keyless_rebuild_metadata, 'main');

    $db = open_db($schema_keyless_rebuild_source);
    $db->exec('CREATE TABLE plugin_keyless_schema_rebuild_new (label TEXT, value NUMERIC)');
    $db->exec('INSERT INTO plugin_keyless_schema_rebuild_new (rowid, label, value) SELECT rowid, label, value FROM plugin_keyless_schema_rebuild');
    $db->exec('DROP TABLE plugin_keyless_schema_rebuild');
    $db->exec('ALTER TABLE plugin_keyless_schema_rebuild_new RENAME TO plugin_keyless_schema_rebuild');
    $db->close();

    $db = open_db($schema_keyless_rebuild_target);
    $db->exec("UPDATE plugin_keyless_schema_rebuild SET value = 'target sparse preserved' WHERE rowid = 7");
    $db->exec('CREATE TABLE plugin_keyless_schema_rebuild_new (label TEXT, value REAL)');
    $db->exec('INSERT INTO plugin_keyless_schema_rebuild_new (rowid, label, value) SELECT rowid, label, value FROM plugin_keyless_schema_rebuild');
    $db->exec('DROP TABLE plugin_keyless_schema_rebuild');
    $db->exec('ALTER TABLE plugin_keyless_schema_rebuild_new RENAME TO plugin_keyless_schema_rebuild');
    $db->close();

    $schema_keyless_rebuild_result = cow_merge_databases(
        $schema_keyless_rebuild_base,
        $schema_keyless_rebuild_source,
        $schema_keyless_rebuild_target,
        $schema_keyless_rebuild_metadata,
        'feature-keyless-schema-rebuild',
        'main'
    );
    assert_same($schema_keyless_rebuild_result['status'], 'completed_with_conflicts', 'no-primary-key compatible table rewrite remains validation-gated');
    $schema_keyless_rebuild_conflict_id = (int)scalar($schema_keyless_rebuild_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_keyless_schema_rebuild' AND column_name IS NULL AND conflict_type = 'schema-conflict' ORDER BY id DESC LIMIT 1");
    $schema_keyless_rebuild_resolution = cow_merge_resolve_conflict(
        $schema_keyless_rebuild_metadata,
        $schema_keyless_rebuild_conflict_id,
        'source',
        true,
        'Apply source no-primary-key table schema.',
        'test'
    );
    assert_same($schema_keyless_rebuild_resolution['status'], 'applied', 'source no-primary-key table rebuild schema resolution records applied status');
    assert_same(column_type($schema_keyless_rebuild_target, 'plugin_keyless_schema_rebuild', 'value'), 'NUMERIC', 'source no-primary-key table rebuild applies audited source column definition');
    assert_same((int)scalar($schema_keyless_rebuild_target, 'SELECT COUNT(*) FROM plugin_keyless_schema_rebuild WHERE rowid IN (1, 7)'), 2, 'source no-primary-key table rebuild preserves sparse target rowids');
    assert_same(scalar($schema_keyless_rebuild_target, 'SELECT value FROM plugin_keyless_schema_rebuild WHERE rowid = 7'), 'target sparse preserved', 'source no-primary-key table rebuild preserves target row data');
    assert_same(
        scalar($schema_keyless_rebuild_metadata, "SELECT row_hash FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_keyless_schema_rebuild' AND rowid = 7"),
        cow_merge_row_hash(['label' => 'Sparse keyless', 'value' => 'target sparse preserved']),
        'source no-primary-key table rebuild refreshes target sidecar row hash immediately'
    );
    $schema_keyless_rebuild_rerun = cow_merge_databases(
        $schema_keyless_rebuild_base,
        $schema_keyless_rebuild_source,
        $schema_keyless_rebuild_target,
        $schema_keyless_rebuild_metadata,
        'feature-keyless-schema-rebuild',
        'main'
    );
    assert_same($schema_keyless_rebuild_rerun['status'], 'completed', 'rerunning after no-primary-key table rebuild completes without a new conflict');
    assert_same(scalar($schema_keyless_rebuild_target, 'SELECT value FROM plugin_keyless_schema_rebuild WHERE rowid = 7'), 'target sparse preserved', 'rerunning after no-primary-key table rebuild keeps preserved target data');
    assert_same(
        (int)scalar($schema_keyless_rebuild_metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_keyless_schema_rebuild' AND c.conflict_type = 'schema-conflict' AND r.source_branch = 'feature-keyless-schema-rebuild'"),
        1,
        'rerunning after no-primary-key table rebuild does not rediscover the resolved schema conflict'
    );

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
    $schema_rebuild_dep_rerun = cow_merge_databases($schema_rebuild_dep_base, $schema_rebuild_dep_source, $schema_rebuild_dep_target, $metadata, 'feature-schema-rebuild-deps', 'main');
    assert_same($schema_rebuild_dep_rerun['status'], 'completed', 'rerunning after dependent source table rebuild completes without a new conflict');
    assert_same(column_type($schema_rebuild_dep_target, 'plugin_items', 'value'), 'INTEGER', 'rerunning after dependent source table rebuild keeps the audited source schema');
    assert_same((int)scalar($schema_rebuild_dep_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_items_dep_label_idx'"), 1, 'rerunning after dependent source table rebuild preserves target explicit index');
    assert_same((int)scalar($schema_rebuild_dep_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_items_dep_insert'"), 1, 'rerunning after dependent source table rebuild preserves target trigger');
    $db = open_db($schema_rebuild_dep_target);
    $db->exec("INSERT INTO plugin_items (item_id, label, value) VALUES ('gamma', 'Gamma', 43)");
    $db->close();
    assert_same(scalar($schema_rebuild_dep_target, "SELECT item_id FROM plugin_item_audit WHERE item_id = 'gamma'"), 'gamma', 'target trigger still fires after dependent source table rebuild rerun');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_items' AND c.column_name IS NULL AND c.conflict_type = 'schema-conflict' AND r.source_branch = 'feature-schema-rebuild-deps'"),
        1,
        'rerunning after dependent source table rebuild does not rediscover the resolved schema conflict'
    );

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
    $db->exec('CREATE TABLE plugin_items_new (item_id TEXT PRIMARY KEY, label TEXT, value REAL)');
    $db->exec('INSERT INTO plugin_items_new (item_id, label, value) SELECT item_id, label, value FROM plugin_items');
    $db->exec('DROP TABLE plugin_items');
    $db->exec('ALTER TABLE plugin_items_new RENAME TO plugin_items');
    $db->exec('CREATE TABLE plugin_items_view_audit (item_id TEXT, label TEXT)');
    $db->exec('CREATE VIEW plugin_items_view AS SELECT item_id, label FROM plugin_items');
    $db->exec('CREATE VIEW plugin_items_view_child AS SELECT label FROM plugin_items_view');
    $db->exec('CREATE TRIGGER plugin_items_view_insert INSTEAD OF INSERT ON plugin_items_view BEGIN INSERT INTO plugin_items_view_audit (item_id, label) VALUES (NEW.item_id, NEW.label); END');
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
    assert_same((int)scalar($schema_rebuild_view_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_items_view_child'"), 1, 'source table rebuild preserves transitive target view');
    assert_same((int)scalar($schema_rebuild_view_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_items_view_insert'"), 1, 'source table rebuild preserves target view trigger');
    assert_same(scalar($schema_rebuild_view_target, "SELECT label FROM plugin_items_view WHERE item_id = 'alpha'"), 'Alpha', 'preserved target view remains queryable after schema rebuild');
    assert_same(scalar($schema_rebuild_view_target, "SELECT label FROM plugin_items_view_child WHERE label = 'Alpha'"), 'Alpha', 'preserved transitive target view remains queryable after schema rebuild');
    $db = open_db($schema_rebuild_view_target);
    $db->exec("INSERT INTO plugin_items_view (item_id, label) VALUES ('from-view-rebuild', 'From View Rebuild')");
    $db->close();
    assert_same(scalar($schema_rebuild_view_target, "SELECT label FROM plugin_items_view_audit WHERE item_id = 'from-view-rebuild'"), 'From View Rebuild', 'preserved target view trigger still fires after schema rebuild');
    $schema_rebuild_view_rerun = cow_merge_databases($schema_rebuild_view_base, $schema_rebuild_view_source, $schema_rebuild_view_target, $metadata, 'feature-schema-rebuild-view', 'main');
    assert_same($schema_rebuild_view_rerun['status'], 'completed', 'rerunning after target-view source table rebuild completes without a new conflict');
    assert_same(column_type($schema_rebuild_view_target, 'plugin_items', 'value'), 'INTEGER', 'rerunning after target-view source table rebuild keeps the audited source schema');
    assert_same((int)scalar($schema_rebuild_view_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_items_view'"), 1, 'rerunning after target-view source table rebuild preserves target view');
    assert_same((int)scalar($schema_rebuild_view_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_items_view_child'"), 1, 'rerunning after target-view source table rebuild preserves transitive target view');
    assert_same((int)scalar($schema_rebuild_view_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_items_view_insert'"), 1, 'rerunning after target-view source table rebuild preserves target view trigger');
    assert_same(scalar($schema_rebuild_view_target, "SELECT label FROM plugin_items_view_child WHERE label = 'Alpha'"), 'Alpha', 'rerunning after target-view source table rebuild keeps transitive target view queryable');
    $db = open_db($schema_rebuild_view_target);
    $db->exec("INSERT INTO plugin_items_view (item_id, label) VALUES ('from-view-rerun', 'From View Rerun')");
    $db->close();
    assert_same(scalar($schema_rebuild_view_target, "SELECT label FROM plugin_items_view_audit WHERE item_id = 'from-view-rerun'"), 'From View Rerun', 'target view trigger still fires after source table rebuild rerun');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_items' AND c.column_name IS NULL AND c.conflict_type = 'schema-conflict' AND r.source_branch = 'feature-schema-rebuild-view'"),
        1,
        'rerunning after target-view source table rebuild does not rediscover the resolved schema conflict'
    );

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
    $schema_resolve_rerun = cow_merge_databases($schema_resolve_base, $schema_resolve_source, $schema_resolve_target, $metadata, 'feature-schema-resolution', 'main');
    assert_same($schema_resolve_rerun['status'], 'completed', 'rerunning after safe source column and index resolutions completes without a new conflict');
    assert_same(column_type($schema_resolve_target, 'plugin_items', 'review_note'), 'TEXT', 'rerunning after safe source column resolution keeps the audited source column');
    assert_same((int)scalar($schema_resolve_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_items_review_idx'"), 1, 'rerunning after safe source index resolution keeps the audited source index');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_items' AND c.column_name = 'review_note' AND c.conflict_type = 'schema-source-changed' AND r.source_branch = 'feature-schema-resolution'"),
        1,
        'rerunning after safe source column resolution does not rediscover the resolved schema conflict'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_items' AND c.column_name = 'plugin_items_review_idx' AND c.conflict_type = 'schema-source-added-index' AND r.source_branch = 'feature-schema-resolution'"),
        1,
        'rerunning after safe source index resolution does not rediscover the resolved schema conflict'
    );

    $schema_keyless_column_base = $tmp . '/schema-keyless-column-base.sqlite';
    $schema_keyless_column_source = $tmp . '/schema-keyless-column-source.sqlite';
    $schema_keyless_column_target = $tmp . '/schema-keyless-column-target.sqlite';
    $schema_keyless_column_metadata = $tmp . '/.forkpress/cow/merge/schema-keyless-column-metadata.sqlite';
    create_base_db($schema_keyless_column_base);
    $db = open_db($schema_keyless_column_base);
    $db->exec('CREATE TABLE plugin_keyless_schema_column (label TEXT, value TEXT)');
    $db->exec("INSERT INTO plugin_keyless_schema_column (rowid, label, value) VALUES (5, 'Keyless column', 'base')");
    $db->close();
    copy($schema_keyless_column_base, $schema_keyless_column_source);
    copy($schema_keyless_column_base, $schema_keyless_column_target);
    cow_merge_capture_row_identities($schema_keyless_column_base, $schema_keyless_column_metadata, 'main');
    cow_merge_capture_row_identities($schema_keyless_column_source, $schema_keyless_column_metadata, 'feature-keyless-schema-column', 'main');
    cow_merge_capture_row_identities($schema_keyless_column_target, $schema_keyless_column_metadata, 'main');

    $db = open_db($schema_keyless_column_source);
    $db->exec("ALTER TABLE plugin_keyless_schema_column ADD COLUMN review_note TEXT DEFAULT 'source default'");
    $schema_keyless_column_source_columns = cow_merge_columns_by_name(cow_merge_table_info($db, 'plugin_keyless_schema_column'));
    $db->close();

    $db = open_db($schema_keyless_column_target);
    $schema_keyless_column_target_sql = cow_merge_table_sql($db, 'plugin_keyless_schema_column');
    $db->close();

    $manual_meta = cow_merge_open_db($schema_keyless_column_metadata, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    cow_merge_ensure_metadata($manual_meta);
    $manual_run_id = cow_merge_start_run($manual_meta, 'feature-keyless-schema-column', 'main', $schema_keyless_column_base, $schema_keyless_column_source, $schema_keyless_column_target);
    cow_merge_record_schema_conflict(
        $manual_meta,
        $manual_run_id,
        'plugin_keyless_schema_column',
        'review_note',
        'schema-source-changed',
        $schema_keyless_column_target_sql,
        [
            'column' => $schema_keyless_column_source_columns['review_note'],
            'definition' => "review_note TEXT DEFAULT 'source default'",
            'error' => 'simulated earlier apply failure',
        ],
        $schema_keyless_column_target_sql,
        $schema_keyless_column_target_sql,
        'simulated source-added no-primary-key column conflict'
    );
    cow_merge_finish_run($manual_meta, $manual_run_id, 'completed_with_conflicts');
    $manual_meta->close();

    $schema_keyless_column_conflict_id = (int)scalar($schema_keyless_column_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_keyless_schema_column' AND column_name = 'review_note' AND conflict_type = 'schema-source-changed' ORDER BY id DESC LIMIT 1");
    $schema_keyless_column_resolution = cow_merge_resolve_conflict(
        $schema_keyless_column_metadata,
        $schema_keyless_column_conflict_id,
        'source',
        true,
        'Apply safe no-primary-key source column.',
        'test'
    );
    assert_same($schema_keyless_column_resolution['status'], 'applied', 'source no-primary-key schema column resolution records applied status');
    assert_same(column_type($schema_keyless_column_target, 'plugin_keyless_schema_column', 'review_note'), 'TEXT', 'source no-primary-key schema column resolution applies audited safe column');
    assert_same(scalar($schema_keyless_column_target, 'SELECT review_note FROM plugin_keyless_schema_column WHERE rowid = 5'), 'source default', 'source no-primary-key schema column resolution applies the SQLite default to existing rows');
    $db = open_db($schema_keyless_column_target);
    $schema_keyless_column_row = cow_merge_load_keyless_physical_row($db, 'plugin_keyless_schema_column', 5);
    $db->close();
    assert_same(
        scalar($schema_keyless_column_metadata, "SELECT row_hash FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_keyless_schema_column' AND rowid = 5"),
        cow_merge_row_hash($schema_keyless_column_row['row']),
        'source no-primary-key schema column resolution refreshes target sidecar row hash immediately'
    );
    $schema_keyless_column_rerun = cow_merge_databases($schema_keyless_column_base, $schema_keyless_column_source, $schema_keyless_column_target, $schema_keyless_column_metadata, 'feature-keyless-schema-column', 'main');
    assert_same($schema_keyless_column_rerun['status'], 'completed', 'rerunning after no-primary-key safe source column resolution completes without a new conflict');
    assert_same(
        (int)scalar($schema_keyless_column_metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_keyless_schema_column' AND c.column_name = 'review_note' AND c.conflict_type = 'schema-source-changed' AND r.source_branch = 'feature-keyless-schema-column'"),
        1,
        'rerunning after no-primary-key safe source column resolution does not rediscover the resolved schema conflict'
    );

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
    $schema_index_rewrite_rerun = cow_merge_databases($schema_index_rewrite_base, $schema_index_rewrite_source, $schema_index_rewrite_target, $metadata, 'feature-index-rewrite', 'main');
    assert_same($schema_index_rewrite_rerun['status'], 'completed', 'rerunning after source index rewrite resolution completes without a new conflict');
    assert_true(str_contains((string)scalar($schema_index_rewrite_target, "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'plugin_items_label_idx'"), '(value)'), 'rerunning after source index rewrite keeps the audited source index');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_items' AND c.column_name = 'plugin_items_label_idx' AND c.conflict_type = 'schema-source-changed-index' AND r.source_branch = 'feature-index-rewrite'"),
        1,
        'rerunning after source index rewrite resolution does not rediscover the resolved schema conflict'
    );

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
    $schema_index_drop_rerun = cow_merge_databases($schema_index_drop_base, $schema_index_drop_source, $schema_index_drop_target, $metadata, 'feature-index-drop', 'main');
    assert_same($schema_index_drop_rerun['status'], 'completed', 'rerunning after source index drop resolution completes without a new conflict');
    assert_same(scalar($schema_index_drop_target, "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'plugin_items_drop_idx'"), null, 'rerunning after source index drop keeps the target index removed');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_items' AND c.column_name = 'plugin_items_drop_idx' AND c.conflict_type = 'schema-source-dropped-index' AND r.source_branch = 'feature-index-drop'"),
        1,
        'rerunning after source index drop resolution does not rediscover the resolved schema conflict'
    );

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

    $schema_same_base = $tmp . '/schema-same-base.sqlite';
    $schema_same_source = $tmp . '/schema-same-source.sqlite';
    $schema_same_target = $tmp . '/schema-same-target.sqlite';
    create_base_db($schema_same_base);
    $db = open_db($schema_same_base);
    $db->exec('CREATE TABLE plugin_same_schema_drop (item_id TEXT PRIMARY KEY, label TEXT)');
    $db->exec('CREATE TABLE plugin_same_audit (item_id TEXT)');
    $db->exec('CREATE INDEX plugin_items_same_idx ON plugin_items(label)');
    $db->exec('CREATE VIEW plugin_items_same_view AS SELECT item_id, label FROM plugin_items');
    $db->exec('CREATE TRIGGER plugin_items_same_trigger AFTER INSERT ON plugin_items BEGIN INSERT INTO plugin_same_audit (item_id) VALUES (NEW.item_id); END');
    $db->close();
    copy($schema_same_base, $schema_same_source);
    copy($schema_same_base, $schema_same_target);
    foreach ([$schema_same_source, $schema_same_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_same_schema_add (item_id TEXT PRIMARY KEY, label TEXT)');
        $db->exec('DROP TABLE plugin_same_schema_drop');
        $db->exec('CREATE INDEX plugin_items_same_added_idx ON plugin_items(value)');
        $db->exec('DROP INDEX plugin_items_same_idx');
        $db->exec('CREATE INDEX plugin_items_same_idx ON plugin_items(value)');
        $db->exec('CREATE VIEW plugin_items_same_added_view AS SELECT item_id, value FROM plugin_items');
        $db->exec('DROP VIEW plugin_items_same_view');
        $db->exec('CREATE VIEW plugin_items_same_view AS SELECT item_id, label, value FROM plugin_items');
        $db->exec('CREATE TRIGGER plugin_items_same_added_trigger AFTER UPDATE ON plugin_items BEGIN INSERT INTO plugin_same_audit (item_id) VALUES (NEW.item_id || ":updated"); END');
        $db->exec('DROP TRIGGER plugin_items_same_trigger');
        $db->exec('CREATE TRIGGER plugin_items_same_trigger AFTER INSERT ON plugin_items BEGIN INSERT INTO plugin_same_audit (item_id) VALUES (NEW.item_id || ":same"); END');
        $db->close();
    }
    $schema_same_result = cow_merge_databases($schema_same_base, $schema_same_source, $schema_same_target, $metadata, 'feature-schema-same', 'main');
    $schema_same_run_id = (int)$schema_same_result['run_id'];
    assert_same($schema_same_result['status'], 'completed', 'identical source and target schema changes merge without conflicts');
    assert_same((int)scalar($schema_same_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_same_schema_add'"), 1, 'identical source and target table add remains present');
    assert_same((int)scalar($schema_same_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_same_schema_drop'"), 0, 'identical source and target table drop remains applied');
    assert_true(str_contains((string)scalar($schema_same_target, "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'plugin_items_same_idx'"), '(value)'), 'identical source and target index rewrite remains present');
    assert_true(str_contains((string)scalar($schema_same_target, "SELECT sql FROM sqlite_master WHERE type = 'view' AND name = 'plugin_items_same_view'"), 'value'), 'identical source and target view rewrite remains present');
    assert_true(str_contains((string)scalar($schema_same_target, "SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_items_same_trigger'"), ':same'), 'identical source and target trigger rewrite remains present');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE run_id = $schema_same_run_id"), 0, 'identical source and target schema changes do not create false conflicts');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE run_id = $schema_same_run_id AND decision = 'source-applied'"), 8, 'identical source and target schema changes are auditable as source-applied decisions');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE run_id = $schema_same_run_id AND table_name = 'plugin_same_schema_add' AND reason = 'source and target added the same table schema'"), 1, 'identical source and target table add is auditable');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE run_id = $schema_same_run_id AND table_name = 'plugin_same_schema_drop' AND reason = 'source and target dropped the same table schema'"), 1, 'identical source and target table drop is auditable');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE run_id = $schema_same_run_id AND column_name IN ('plugin_items_same_added_idx', 'plugin_items_same_added_view', 'plugin_items_same_added_trigger') AND reason LIKE 'source and target added the same % schema'"), 3, 'identical source and target schema object adds are auditable');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE run_id = $schema_same_run_id AND column_name IN ('plugin_items_same_idx', 'plugin_items_same_view', 'plugin_items_same_trigger') AND reason LIKE 'source and target changed % schema to the same definition'"), 3, 'identical source and target schema object rewrites are auditable');

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
    $schema_view_rewrite_rerun = cow_merge_databases($schema_view_rewrite_base, $schema_view_rewrite_source, $schema_view_rewrite_target, $metadata, 'feature-view-rewrite', 'main');
    assert_same($schema_view_rewrite_rerun['status'], 'completed', 'rerunning after source view rewrite resolution completes without a new conflict');
    assert_same(scalar($schema_view_rewrite_target, "SELECT value FROM plugin_items_review_view WHERE item_id = 'alpha'"), 'base', 'rerunning after source view rewrite keeps the audited source view queryable');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.column_name = 'plugin_items_review_view' AND c.conflict_type = 'schema-source-changed-view' AND r.source_branch = 'feature-view-rewrite'"),
        1,
        'rerunning after source view rewrite resolution does not rediscover the resolved schema conflict'
    );

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
    $schema_view_dep_rerun = cow_merge_databases($schema_view_dep_base, $schema_view_dep_source, $schema_view_dep_target, $metadata, 'feature-view-dependency', 'main');
    assert_same($schema_view_dep_rerun['status'], 'completed', 'rerunning after dependent source view rewrite completes without a new conflict');
    assert_same(scalar($schema_view_dep_target, "SELECT label FROM plugin_items_dep_child WHERE label = 'Alpha'"), 'Alpha', 'rerunning after dependent source view rewrite keeps dependent target view queryable');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.column_name = 'plugin_items_dep_base' AND c.conflict_type = 'schema-source-changed-view' AND r.source_branch = 'feature-view-dependency'"),
        1,
        'rerunning after dependent source view rewrite does not rediscover the resolved schema conflict'
    );

    $schema_view_cycle_rewrite_base = $tmp . '/schema-view-cycle-rewrite-base.sqlite';
    $schema_view_cycle_rewrite_source = $tmp . '/schema-view-cycle-rewrite-source.sqlite';
    $schema_view_cycle_rewrite_target = $tmp . '/schema-view-cycle-rewrite-target.sqlite';
    create_base_db($schema_view_cycle_rewrite_base);
    $db = open_db($schema_view_cycle_rewrite_base);
    $db->exec('CREATE VIEW plugin_items_cycle_parent AS SELECT item_id, label FROM plugin_items');
    $db->close();
    copy($schema_view_cycle_rewrite_base, $schema_view_cycle_rewrite_source);
    copy($schema_view_cycle_rewrite_base, $schema_view_cycle_rewrite_target);

    $db = open_db($schema_view_cycle_rewrite_source);
    $db->exec('DROP VIEW plugin_items_cycle_parent');
    $db->exec('CREATE VIEW plugin_items_cycle_parent AS SELECT item_id, label FROM plugin_items_cycle_child');
    $db->close();
    $db = open_db($schema_view_cycle_rewrite_target);
    $db->exec('CREATE VIEW plugin_items_cycle_child AS SELECT item_id, label FROM plugin_items_cycle_parent');
    $db->close();

    $schema_view_cycle_rewrite_result = cow_merge_databases(
        $schema_view_cycle_rewrite_base,
        $schema_view_cycle_rewrite_source,
        $schema_view_cycle_rewrite_target,
        $metadata,
        'feature-view-cycle-rewrite',
        'main'
    );
    assert_same($schema_view_cycle_rewrite_result['status'], 'completed_with_conflicts', 'source view rewrite that would cycle with a preserved target view remains reviewable');
    $schema_view_cycle_rewrite_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_items_cycle_parent' AND conflict_type = 'schema-source-changed-view' ORDER BY id DESC LIMIT 1");
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $metadata,
            $schema_view_cycle_rewrite_conflict_id,
            'source',
            false,
            'Preview cyclic source view rewrite.',
            'test'
        ),
        'unsupported cyclic view dependencies',
        'source view rewrite dry-run refuses to cycle with a preserved target view'
    );
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $metadata,
            $schema_view_cycle_rewrite_conflict_id,
            'source',
            true,
            'Apply cyclic source view rewrite.',
            'test'
        ),
        'unsupported cyclic view dependencies',
        'source view rewrite apply refuses to cycle with a preserved target view'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_view_cycle_rewrite_conflict_id"),
        0,
        'failed cyclic source view rewrite does not record resolution metadata'
    );
    assert_true(
        str_contains((string)scalar($schema_view_cycle_rewrite_target, "SELECT sql FROM sqlite_master WHERE type = 'view' AND name = 'plugin_items_cycle_parent'"), 'plugin_items'),
        'failed cyclic source view rewrite rolls back the target parent view'
    );
    assert_same(
        (int)scalar($schema_view_cycle_rewrite_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_items_cycle_child'"),
        1,
        'failed cyclic source view rewrite preserves the target child view'
    );
    $db = open_db($schema_view_cycle_rewrite_target);
    $db->exec('DROP VIEW plugin_items_cycle_child');
    $db->exec('CREATE VIEW plugin_items_cycle_child AS SELECT item_id, label FROM plugin_items');
    $db->close();
    $schema_view_cycle_rewrite_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_view_cycle_rewrite_conflict_id,
        'source',
        true,
        'Apply source view rewrite after target view cycle is removed.',
        'test'
    );
    assert_same($schema_view_cycle_rewrite_resolution['status'], 'applied', 'source view rewrite applies after the preserved target view no longer cycles');
    assert_same(scalar($schema_view_cycle_rewrite_target, "SELECT label FROM plugin_items_cycle_parent WHERE item_id = 'alpha'"), 'Alpha', 'resolved source view rewrite remains queryable through the preserved target view');
    $schema_view_cycle_rewrite_rerun = cow_merge_databases(
        $schema_view_cycle_rewrite_base,
        $schema_view_cycle_rewrite_source,
        $schema_view_cycle_rewrite_target,
        $metadata,
        'feature-view-cycle-rewrite',
        'main'
    );
    assert_same($schema_view_cycle_rewrite_rerun['status'], 'completed', 'rerunning after cyclic source view rewrite resolution completes without a new conflict');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.column_name = 'plugin_items_cycle_parent' AND c.conflict_type = 'schema-source-changed-view' AND r.source_branch = 'feature-view-cycle-rewrite'"),
        1,
        'rerunning after cyclic source view rewrite resolution does not rediscover the resolved schema conflict'
    );

    $schema_view_trigger_validation_base = $tmp . '/schema-view-trigger-validation-base.sqlite';
    $schema_view_trigger_validation_source = $tmp . '/schema-view-trigger-validation-source.sqlite';
    $schema_view_trigger_validation_target = $tmp . '/schema-view-trigger-validation-target.sqlite';
    create_base_db($schema_view_trigger_validation_base);
    $db = open_db($schema_view_trigger_validation_base);
    $db->exec('CREATE VIEW plugin_items_trigger_validation_view AS SELECT item_id, label, value FROM plugin_items');
    $db->close();
    copy($schema_view_trigger_validation_base, $schema_view_trigger_validation_source);
    copy($schema_view_trigger_validation_base, $schema_view_trigger_validation_target);

    $db = open_db($schema_view_trigger_validation_source);
    $db->exec('DROP VIEW plugin_items_trigger_validation_view');
    $db->exec('CREATE VIEW plugin_items_trigger_validation_view AS SELECT item_id, label FROM plugin_items');
    $db->close();
    $db = open_db($schema_view_trigger_validation_target);
    $db->exec('CREATE TABLE plugin_items_trigger_validation_audit (item_id TEXT, value TEXT)');
    $db->exec('CREATE TRIGGER plugin_items_trigger_validation_insert INSTEAD OF INSERT ON plugin_items_trigger_validation_view BEGIN INSERT INTO plugin_items_trigger_validation_audit (item_id, value) VALUES (NEW.item_id, NEW.value); END');
    $db->close();

    $schema_view_trigger_validation_result = cow_merge_databases(
        $schema_view_trigger_validation_base,
        $schema_view_trigger_validation_source,
        $schema_view_trigger_validation_target,
        $metadata,
        'feature-view-trigger-validation',
        'main'
    );
    assert_same($schema_view_trigger_validation_result['status'], 'completed_with_conflicts', 'source view rewrite with target trigger remains reviewable');
    $schema_view_trigger_validation_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_items_trigger_validation_view' AND conflict_type = 'schema-source-changed-view' ORDER BY id DESC LIMIT 1");
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $metadata,
            $schema_view_trigger_validation_conflict_id,
            'source',
            false,
            'Preview view rewrite with invalid preserved trigger.',
            'test'
        ),
        'failed target trigger validation',
        'dry-run source view rewrite rejects preserved target trigger programs that no longer compile'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_view_trigger_validation_conflict_id"),
        0,
        'failed view trigger validation dry-run does not record resolution metadata'
    );
    assert_true(
        str_contains((string)scalar($schema_view_trigger_validation_target, "SELECT sql FROM sqlite_master WHERE type = 'view' AND name = 'plugin_items_trigger_validation_view'"), 'value'),
        'failed view trigger validation dry-run rolls back the target view rewrite'
    );
    assert_same(
        (int)scalar($schema_view_trigger_validation_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_items_trigger_validation_insert'"),
        1,
        'failed view trigger validation dry-run preserves the target trigger'
    );
    $db = open_db($schema_view_trigger_validation_target);
    $db->exec('DROP TRIGGER plugin_items_trigger_validation_insert');
    $db->close();
    $schema_view_trigger_validation_apply = cow_merge_resolve_conflict(
        $metadata,
        $schema_view_trigger_validation_conflict_id,
        'source',
        true,
        'Apply source view rewrite after target trigger review.',
        'test'
    );
    assert_same($schema_view_trigger_validation_apply['status'], 'applied', 'source view rewrite applies after invalid target trigger is handled');
    assert_true(
        !str_contains((string)scalar($schema_view_trigger_validation_target, "SELECT sql FROM sqlite_master WHERE type = 'view' AND name = 'plugin_items_trigger_validation_view'"), 'value'),
        'validated view rewrite applies the audited source view definition'
    );

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
    $schema_trigger_drop_rerun = cow_merge_databases($schema_trigger_drop_base, $schema_trigger_drop_source, $schema_trigger_drop_target, $metadata, 'feature-trigger-drop', 'main');
    assert_same($schema_trigger_drop_rerun['status'], 'completed', 'rerunning after source trigger drop resolution completes without a new conflict');
    assert_same(scalar($schema_trigger_drop_target, "SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_items_drop_trigger'"), null, 'rerunning after source trigger drop keeps the target trigger removed');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.column_name = 'plugin_items_drop_trigger' AND c.conflict_type = 'schema-source-dropped-trigger' AND r.source_branch = 'feature-trigger-drop'"),
        1,
        'rerunning after source trigger drop resolution does not rediscover the resolved schema conflict'
    );

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
    $schema_table_drop_rerun = cow_merge_databases($schema_table_drop_base, $schema_table_drop_source, $schema_table_drop_target, $metadata, 'feature-table-drop', 'main');
    assert_same($schema_table_drop_rerun['status'], 'completed', 'rerunning after source table drop resolution completes without a new conflict');
    assert_same((int)scalar($schema_table_drop_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_table_drop'"), 0, 'rerunning after source table drop resolution keeps the table dropped');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_table_drop' AND c.conflict_type = 'schema-source-dropped-table' AND r.source_branch = 'feature-table-drop'"),
        1,
        'rerunning after source table drop resolution does not rediscover the resolved schema conflict'
    );

    $schema_keyless_table_drop_base = $tmp . '/schema-keyless-table-drop-base.sqlite';
    $schema_keyless_table_drop_source = $tmp . '/schema-keyless-table-drop-source.sqlite';
    $schema_keyless_table_drop_target = $tmp . '/schema-keyless-table-drop-target.sqlite';
    $schema_keyless_table_drop_metadata = $tmp . '/.forkpress/cow/merge/schema-keyless-table-drop-metadata.sqlite';
    create_base_db($schema_keyless_table_drop_base);
    $db = open_db($schema_keyless_table_drop_base);
    $db->exec('CREATE TABLE plugin_keyless_table_drop (label TEXT, value TEXT)');
    $db->exec("INSERT INTO plugin_keyless_table_drop (label, value) VALUES ('Drop keyless', 'base')");
    $db->close();
    copy($schema_keyless_table_drop_base, $schema_keyless_table_drop_source);
    copy($schema_keyless_table_drop_base, $schema_keyless_table_drop_target);
    cow_merge_capture_row_identities($schema_keyless_table_drop_base, $schema_keyless_table_drop_metadata, 'main');
    cow_merge_capture_row_identities($schema_keyless_table_drop_source, $schema_keyless_table_drop_metadata, 'feature-keyless-table-drop', 'main');
    cow_merge_capture_row_identities($schema_keyless_table_drop_target, $schema_keyless_table_drop_metadata, 'main');
    $db = open_db($schema_keyless_table_drop_source);
    $db->exec('DROP TABLE plugin_keyless_table_drop');
    $db->close();
    $result = cow_merge_databases($schema_keyless_table_drop_base, $schema_keyless_table_drop_source, $schema_keyless_table_drop_target, $schema_keyless_table_drop_metadata, 'feature-keyless-table-drop', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'source-dropped no-primary-key table is recorded as a schema conflict');
    $schema_keyless_table_drop_conflict_id = (int)scalar($schema_keyless_table_drop_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_keyless_table_drop' AND conflict_type = 'schema-source-dropped-table' ORDER BY id DESC LIMIT 1");
    $schema_keyless_table_drop_resolution = cow_merge_resolve_conflict(
        $schema_keyless_table_drop_metadata,
        $schema_keyless_table_drop_conflict_id,
        'source',
        true,
        'Apply source no-primary-key table drop.',
        'test'
    );
    assert_same($schema_keyless_table_drop_resolution['status'], 'applied', 'source no-primary-key table drop schema resolution records applied status');
    assert_same((int)scalar($schema_keyless_table_drop_metadata, "SELECT COUNT(*) FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_keyless_table_drop'"), 0, 'source no-primary-key table drop clears active target sidecar identities');
    assert_same((int)scalar($schema_keyless_table_drop_metadata, "SELECT COUNT(*) FROM merge_row_identity_history WHERE branch_name = 'main' AND table_name = 'plugin_keyless_table_drop' AND deleted_at IS NOT NULL"), 1, 'source no-primary-key table drop tombstones target sidecar history');
    $db = open_db($schema_keyless_table_drop_target);
    $db->exec('CREATE TABLE plugin_keyless_table_drop (label TEXT, value TEXT)');
    $db->exec("INSERT INTO plugin_keyless_table_drop (label, value) VALUES ('Drop replacement', 'runtime replacement')");
    $db->close();
    assert_same((int)scalar($schema_keyless_table_drop_target, 'SELECT rowid FROM plugin_keyless_table_drop'), 1, 'SQLite can reuse a no-primary-key rowid after source table drop resolution');
    cow_merge_track_row_identity_events(
        $schema_keyless_table_drop_target,
        $schema_keyless_table_drop_metadata,
        'main',
        [[
            'id' => 1,
            'table' => 'plugin_keyless_table_drop',
            'op' => 'insert',
            'rowid' => 1,
            'row' => ['label' => 'Drop replacement', 'value' => 'runtime replacement'],
        ]]
    );
    $schema_keyless_table_drop_replacement_identity = cow_merge_decode_payload_json(
        (string)scalar($schema_keyless_table_drop_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_keyless_table_drop' AND rowid = 1"),
        'replacement identity after source table drop'
    );
    assert_same($schema_keyless_table_drop_replacement_identity['origin'] ?? null, 'runtime-insert', 'rowid reuse after source no-primary-key table drop receives a fresh runtime sidecar identity');

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
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_table_both_drop'"), 0, 'matching table drops do not create conflict noise');

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
    $schema_table_target_drop_rerun = cow_merge_databases($schema_table_target_drop_base, $schema_table_target_drop_source, $schema_table_target_drop_target, $metadata, 'feature-table-target-drop', 'main');
    assert_same($schema_table_target_drop_rerun['status'], 'completed', 'rerunning after target-dropped table source restore completes without a new conflict');
    assert_same((int)scalar($schema_table_target_drop_target, "SELECT COUNT(*) FROM plugin_table_target_drop WHERE item_id IN ('alpha', 'beta', 'gamma')"), 3, 'rerunning after source table restore preserves restored and target-only rows');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_table_target_drop' AND c.conflict_type = 'schema-target-dropped-table' AND r.source_branch = 'feature-table-target-drop'"),
        1,
        'rerunning after target-dropped table source restore does not rediscover the resolved schema conflict'
    );

    $schema_keyless_table_restore_base = $tmp . '/schema-keyless-table-restore-base.sqlite';
    $schema_keyless_table_restore_source = $tmp . '/schema-keyless-table-restore-source.sqlite';
    $schema_keyless_table_restore_target = $tmp . '/schema-keyless-table-restore-target.sqlite';
    $schema_keyless_table_restore_metadata = $tmp . '/.forkpress/cow/merge/schema-keyless-table-restore-metadata.sqlite';
    create_base_db($schema_keyless_table_restore_base);
    $db = open_db($schema_keyless_table_restore_base);
    $db->exec('CREATE TABLE plugin_keyless_table_restore (label TEXT, value TEXT)');
    $db->exec('CREATE UNIQUE INDEX plugin_keyless_table_restore_label_idx ON plugin_keyless_table_restore(label)');
    $db->exec('CREATE TABLE plugin_keyless_table_restore_audit (label TEXT, value TEXT)');
    $db->exec(
        'CREATE TRIGGER plugin_keyless_table_restore_insert AFTER INSERT ON plugin_keyless_table_restore BEGIN ' .
        'INSERT INTO plugin_keyless_table_restore_audit (label, value) VALUES (NEW.label, NEW.value); ' .
        'END'
    );
    $db->exec("INSERT INTO plugin_keyless_table_restore (label, value) VALUES ('Restore keyless', 'base')");
    $db->close();
    copy($schema_keyless_table_restore_base, $schema_keyless_table_restore_source);
    copy($schema_keyless_table_restore_base, $schema_keyless_table_restore_target);
    cow_merge_capture_row_identities($schema_keyless_table_restore_base, $schema_keyless_table_restore_metadata, 'main');
    cow_merge_capture_row_identities($schema_keyless_table_restore_source, $schema_keyless_table_restore_metadata, 'feature-keyless-table-restore', 'main');
    cow_merge_capture_row_identities($schema_keyless_table_restore_target, $schema_keyless_table_restore_metadata, 'main');
    $db = open_db($schema_keyless_table_restore_source);
    $db->exec("UPDATE plugin_keyless_table_restore SET value = 'source restored base' WHERE rowid = 1");
    $db->exec("INSERT INTO plugin_keyless_table_restore (rowid, label, value) VALUES (9, 'Source restored extra', 'source extra')");
    $db->close();
    cow_merge_capture_row_identities($schema_keyless_table_restore_source, $schema_keyless_table_restore_metadata, 'feature-keyless-table-restore', 'main');
    $schema_keyless_table_restore_source_identity = scalar($schema_keyless_table_restore_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'feature-keyless-table-restore' AND table_name = 'plugin_keyless_table_restore' AND rowid = 1");
    $schema_keyless_table_restore_source_extra_identity = scalar($schema_keyless_table_restore_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'feature-keyless-table-restore' AND table_name = 'plugin_keyless_table_restore' AND rowid = 9");
    $db = open_db($schema_keyless_table_restore_target);
    $db->exec('DELETE FROM plugin_keyless_table_restore WHERE rowid = 1');
    $db->exec("INSERT INTO plugin_keyless_table_restore (label, value) VALUES ('Target stale rowid', 'target stale identity')");
    $db->close();
    cow_merge_track_row_identity_events(
        $schema_keyless_table_restore_target,
        $schema_keyless_table_restore_metadata,
        'main',
        [
            [
                'id' => 1,
                'table' => 'plugin_keyless_table_restore',
                'op' => 'delete',
                'rowid' => 1,
                'row' => ['label' => 'Restore keyless', 'value' => 'base'],
            ],
            [
                'id' => 2,
                'table' => 'plugin_keyless_table_restore',
                'op' => 'insert',
                'rowid' => 1,
                'row' => ['label' => 'Target stale rowid', 'value' => 'target stale identity'],
            ],
        ]
    );
    $schema_keyless_table_restore_stale_identity = scalar($schema_keyless_table_restore_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_keyless_table_restore' AND rowid = 1");
    $db = open_db($schema_keyless_table_restore_target);
    $db->exec('DROP TABLE plugin_keyless_table_restore');
    $db->close();
    $result = cow_merge_databases($schema_keyless_table_restore_base, $schema_keyless_table_restore_source, $schema_keyless_table_restore_target, $schema_keyless_table_restore_metadata, 'feature-keyless-table-restore', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'target-dropped no-primary-key table is recorded as a schema conflict');
    $schema_keyless_table_restore_conflict_id = (int)scalar($schema_keyless_table_restore_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_keyless_table_restore' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    $schema_keyless_table_restore_resolution = cow_merge_resolve_conflict(
        $schema_keyless_table_restore_metadata,
        $schema_keyless_table_restore_conflict_id,
        'source',
        true,
        'Apply source no-primary-key table restore.',
        'test'
    );
    assert_same($schema_keyless_table_restore_resolution['status'], 'applied', 'source no-primary-key table restore schema resolution records applied status');
    assert_same((int)scalar($schema_keyless_table_restore_target, "SELECT COUNT(*) FROM plugin_keyless_table_restore"), 2, 'source no-primary-key table restore copies audited source rows into the target table');
    assert_same(scalar($schema_keyless_table_restore_target, "SELECT value FROM plugin_keyless_table_restore WHERE rowid = 1"), 'source restored base', 'source no-primary-key table restore recreates the audited source row');
    assert_same(scalar($schema_keyless_table_restore_target, "SELECT value FROM plugin_keyless_table_restore WHERE rowid = 9"), 'source extra', 'source no-primary-key table restore preserves sparse source rowids');
    assert_same((int)scalar($schema_keyless_table_restore_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_keyless_table_restore_label_idx'"), 1, 'source no-primary-key table restore recreates source index');
    assert_same((int)scalar($schema_keyless_table_restore_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_keyless_table_restore_insert'"), 1, 'source no-primary-key table restore recreates source trigger');
    $db = open_db($schema_keyless_table_restore_target);
    $db->exec("INSERT INTO plugin_keyless_table_restore (rowid, label, value) VALUES (15, 'Restored trigger check', 'trigger works')");
    $db->close();
    assert_same(scalar($schema_keyless_table_restore_target, "SELECT value FROM plugin_keyless_table_restore_audit WHERE label = 'Restored trigger check'"), 'trigger works', 'source no-primary-key table restore trigger remains functional after restore');
    assert_same(
        scalar($schema_keyless_table_restore_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_keyless_table_restore' AND rowid = 1"),
        $schema_keyless_table_restore_source_identity,
        'source no-primary-key table restore adopts source sidecar identity after target table recreation'
    );
    assert_same(
        scalar($schema_keyless_table_restore_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_keyless_table_restore' AND rowid = 9"),
        $schema_keyless_table_restore_source_extra_identity,
        'source no-primary-key table restore adopts sparse source sidecar identity at the preserved rowid'
    );
    assert_same(
        (int)scalar($schema_keyless_table_restore_metadata, "SELECT COUNT(*) FROM merge_row_identity_history WHERE branch_name = 'main' AND table_name = 'plugin_keyless_table_restore' AND logical_identity = '" . SQLite3::escapeString((string)$schema_keyless_table_restore_stale_identity) . "' AND deleted_at IS NOT NULL"),
        1,
        'source no-primary-key table restore tombstones stale target sidecar identity before recreating rows'
    );
    $schema_keyless_table_restore_payload = cow_merge_decode_payload_json(
        (string)scalar($schema_keyless_table_restore_metadata, "SELECT resolved_payload FROM merge_resolutions WHERE conflict_id = $schema_keyless_table_restore_conflict_id ORDER BY id DESC LIMIT 1"),
        'target-dropped no-primary-key table resolution'
    );
    assert_same($schema_keyless_table_restore_payload['indexes'][0]['name'] ?? null, 'plugin_keyless_table_restore_label_idx', 'source no-primary-key table restore resolution records restored source index SQL');
    assert_same($schema_keyless_table_restore_payload['triggers'][0]['name'] ?? null, 'plugin_keyless_table_restore_insert', 'source no-primary-key table restore resolution records restored source trigger SQL');
    $schema_keyless_table_restore_rerun = cow_merge_databases($schema_keyless_table_restore_base, $schema_keyless_table_restore_source, $schema_keyless_table_restore_target, $schema_keyless_table_restore_metadata, 'feature-keyless-table-restore', 'main');
    assert_same($schema_keyless_table_restore_rerun['status'], 'completed', 'rerunning after target-dropped no-primary-key table source restore completes without a new conflict');
    assert_same((int)scalar($schema_keyless_table_restore_target, "SELECT COUNT(*) FROM plugin_keyless_table_restore WHERE rowid IN (1, 9, 15)"), 3, 'rerunning after source no-primary-key table restore preserves restored and target-only sparse rows');
    assert_same((int)scalar($schema_keyless_table_restore_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_keyless_table_restore_label_idx'"), 1, 'rerunning after source no-primary-key table restore preserves source index');
    assert_same((int)scalar($schema_keyless_table_restore_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_keyless_table_restore_insert'"), 1, 'rerunning after source no-primary-key table restore preserves source trigger');
    assert_same(
        (int)scalar($schema_keyless_table_restore_metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_keyless_table_restore' AND c.conflict_type = 'schema-target-dropped-table' AND r.source_branch = 'feature-keyless-table-restore'"),
        1,
        'rerunning after target-dropped no-primary-key table restore does not rediscover the resolved schema conflict'
    );

    $schema_self_fk_restore_base = $tmp . '/schema-self-fk-restore-base.sqlite';
    $schema_self_fk_restore_source = $tmp . '/schema-self-fk-restore-source.sqlite';
    $schema_self_fk_restore_target = $tmp . '/schema-self-fk-restore-target.sqlite';
    $schema_self_fk_restore_metadata = $tmp . '/.forkpress/cow/merge/schema-self-fk-restore-metadata.sqlite';
    create_base_db($schema_self_fk_restore_base);
    $db = open_db($schema_self_fk_restore_base);
    $db->exec('CREATE TABLE plugin_keyless_self_fk_restore (code TEXT NOT NULL UNIQUE, parent_code TEXT REFERENCES plugin_keyless_self_fk_restore(code), label TEXT)');
    $db->close();
    copy($schema_self_fk_restore_base, $schema_self_fk_restore_source);
    copy($schema_self_fk_restore_base, $schema_self_fk_restore_target);
    cow_merge_capture_row_identities($schema_self_fk_restore_base, $schema_self_fk_restore_metadata, 'main');
    cow_merge_capture_row_identities($schema_self_fk_restore_source, $schema_self_fk_restore_metadata, 'feature-self-fk-restore', 'main');
    cow_merge_capture_row_identities($schema_self_fk_restore_target, $schema_self_fk_restore_metadata, 'main');
    $db = open_db($schema_self_fk_restore_source);
    $db->exec("INSERT INTO plugin_keyless_self_fk_restore (rowid, code, parent_code, label) VALUES (3, 'child-before-parent', 'restored-parent', 'restored child')");
    $db->exec("INSERT INTO plugin_keyless_self_fk_restore (rowid, code, parent_code, label) VALUES (11, 'restored-parent', NULL, 'restored parent')");
    $db->close();
    cow_merge_capture_row_identities($schema_self_fk_restore_source, $schema_self_fk_restore_metadata, 'feature-self-fk-restore', 'main');
    $schema_self_fk_restore_child_identity = scalar($schema_self_fk_restore_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'feature-self-fk-restore' AND table_name = 'plugin_keyless_self_fk_restore' AND rowid = 3");
    $schema_self_fk_restore_parent_identity = scalar($schema_self_fk_restore_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'feature-self-fk-restore' AND table_name = 'plugin_keyless_self_fk_restore' AND rowid = 11");
    $db = open_db($schema_self_fk_restore_target);
    $db->exec('DROP TABLE plugin_keyless_self_fk_restore');
    $db->close();
    $schema_self_fk_restore_result = cow_merge_databases($schema_self_fk_restore_base, $schema_self_fk_restore_source, $schema_self_fk_restore_target, $schema_self_fk_restore_metadata, 'feature-self-fk-restore', 'main');
    assert_same($schema_self_fk_restore_result['status'], 'completed_with_conflicts', 'target-dropped no-primary-key self-FK table is recorded as a schema conflict');
    $schema_self_fk_restore_conflict_id = (int)scalar($schema_self_fk_restore_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_keyless_self_fk_restore' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    assert_true($schema_self_fk_restore_conflict_id > 0, 'target-dropped no-primary-key self-FK table conflict is auditable');
    $schema_self_fk_restore_resolution = cow_merge_resolve_conflict(
        $schema_self_fk_restore_metadata,
        $schema_self_fk_restore_conflict_id,
        'source',
        true,
        'Apply source no-primary-key self-FK table restore.',
        'test'
    );
    assert_same($schema_self_fk_restore_resolution['status'], 'applied', 'source no-primary-key self-FK table restore records applied status');
    assert_same(scalar($schema_self_fk_restore_target, "SELECT label FROM plugin_keyless_self_fk_restore WHERE code = 'restored-parent'"), 'restored parent', 'source no-primary-key self-FK table restore inserts the parent row');
    assert_same(scalar($schema_self_fk_restore_target, "SELECT parent_code FROM plugin_keyless_self_fk_restore WHERE code = 'child-before-parent'"), 'restored-parent', 'source no-primary-key self-FK child validates after restored parent row');
    assert_same(
        scalar($schema_self_fk_restore_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_keyless_self_fk_restore' AND rowid = 3"),
        $schema_self_fk_restore_child_identity,
        'source no-primary-key self-FK restore adopts child sidecar identity at the sparse rowid'
    );
    assert_same(
        scalar($schema_self_fk_restore_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_keyless_self_fk_restore' AND rowid = 11"),
        $schema_self_fk_restore_parent_identity,
        'source no-primary-key self-FK restore adopts parent sidecar identity at the sparse rowid'
    );
    $schema_self_fk_restore_rerun = cow_merge_databases($schema_self_fk_restore_base, $schema_self_fk_restore_source, $schema_self_fk_restore_target, $schema_self_fk_restore_metadata, 'feature-self-fk-restore', 'main');
    assert_same($schema_self_fk_restore_rerun['status'], 'completed', 'rerunning after no-primary-key self-FK table restore completes without a new conflict');
    assert_same(
        (int)scalar($schema_self_fk_restore_metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_keyless_self_fk_restore' AND c.conflict_type = 'schema-target-dropped-table' AND r.source_branch = 'feature-self-fk-restore'"),
        1,
        'rerunning after no-primary-key self-FK table restore does not rediscover the resolved schema conflict'
    );

    $schema_cross_fk_source_parent_base = $tmp . '/schema-cross-fk-source-parent-base.sqlite';
    $schema_cross_fk_source_parent_source = $tmp . '/schema-cross-fk-source-parent-source.sqlite';
    $schema_cross_fk_source_parent_target = $tmp . '/schema-cross-fk-source-parent-target.sqlite';
    $schema_cross_fk_source_parent_metadata = $tmp . '/.forkpress/cow/merge/schema-cross-fk-source-parent-metadata.sqlite';
    create_base_db($schema_cross_fk_source_parent_base);
    $db = open_db($schema_cross_fk_source_parent_base);
    $db->exec('CREATE TABLE plugin_cross_child_restore_source_parent (parent_code TEXT NOT NULL REFERENCES plugin_cross_parent_source_only(code), label TEXT)');
    $db->close();
    copy($schema_cross_fk_source_parent_base, $schema_cross_fk_source_parent_source);
    copy($schema_cross_fk_source_parent_base, $schema_cross_fk_source_parent_target);
    cow_merge_capture_row_identities($schema_cross_fk_source_parent_base, $schema_cross_fk_source_parent_metadata, 'main');
    cow_merge_capture_row_identities($schema_cross_fk_source_parent_source, $schema_cross_fk_source_parent_metadata, 'feature-cross-fk-source-parent', 'main');
    cow_merge_capture_row_identities($schema_cross_fk_source_parent_target, $schema_cross_fk_source_parent_metadata, 'main');
    $db = open_db($schema_cross_fk_source_parent_source);
    $db->exec('CREATE TABLE plugin_cross_parent_source_only (code TEXT PRIMARY KEY, label TEXT)');
    $db->exec("INSERT INTO plugin_cross_parent_source_only (code, label) VALUES ('source-parent', 'source-only parent')");
    $db->exec("INSERT INTO plugin_cross_child_restore_source_parent (rowid, parent_code, label) VALUES (7, 'source-parent', 'restored child')");
    $db->close();
    cow_merge_capture_row_identities($schema_cross_fk_source_parent_source, $schema_cross_fk_source_parent_metadata, 'feature-cross-fk-source-parent', 'main');
    $schema_cross_fk_source_parent_child_identity = scalar($schema_cross_fk_source_parent_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'feature-cross-fk-source-parent' AND table_name = 'plugin_cross_child_restore_source_parent' AND rowid = 7");
    $db = open_db($schema_cross_fk_source_parent_target);
    $db->exec('DROP TABLE plugin_cross_child_restore_source_parent');
    $db->close();
    $schema_cross_fk_source_parent_result = cow_merge_databases(
        $schema_cross_fk_source_parent_base,
        $schema_cross_fk_source_parent_source,
        $schema_cross_fk_source_parent_target,
        $schema_cross_fk_source_parent_metadata,
        'feature-cross-fk-source-parent',
        'main'
    );
    assert_same($schema_cross_fk_source_parent_result['status'], 'completed_with_conflicts', 'target-dropped child table records a schema conflict after source-only parent materializes');
    assert_same(scalar($schema_cross_fk_source_parent_target, "SELECT label FROM plugin_cross_parent_source_only WHERE code = 'source-parent'"), 'source-only parent', 'source-only parent table materializes before dependent child restore');
    $schema_cross_fk_source_parent_conflict_id = (int)scalar($schema_cross_fk_source_parent_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_cross_child_restore_source_parent' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    $schema_cross_fk_source_parent_resolution = cow_merge_resolve_conflict(
        $schema_cross_fk_source_parent_metadata,
        $schema_cross_fk_source_parent_conflict_id,
        'source',
        true,
        'Apply source child restore after source-only parent.',
        'test'
    );
    assert_same($schema_cross_fk_source_parent_resolution['status'], 'applied', 'source child table restore applies after source-only parent materialization');
    assert_same(scalar($schema_cross_fk_source_parent_target, "SELECT label FROM plugin_cross_child_restore_source_parent WHERE rowid = 7"), 'restored child', 'cross-table child restore preserves the source row');
    assert_same(
        scalar($schema_cross_fk_source_parent_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_cross_child_restore_source_parent' AND rowid = 7"),
        $schema_cross_fk_source_parent_child_identity,
        'cross-table child restore adopts the source no-primary-key sidecar identity'
    );
    assert_same(
        (int)scalar($schema_cross_fk_source_parent_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_cross_child_restore_source_parent' AND conflict_type = 'row-target-constraint'"),
        0,
        'cross-table child restore avoids false target constraint conflicts once source-only parent exists'
    );
    $schema_cross_fk_source_parent_rerun = cow_merge_databases(
        $schema_cross_fk_source_parent_base,
        $schema_cross_fk_source_parent_source,
        $schema_cross_fk_source_parent_target,
        $schema_cross_fk_source_parent_metadata,
        'feature-cross-fk-source-parent',
        'main'
    );
    assert_same($schema_cross_fk_source_parent_rerun['status'], 'completed', 'rerunning after source-only parent plus child restore completes without a new conflict');

    $source_added_fk_child_base = $tmp . '/source-added-fk-child-base.sqlite';
    $source_added_fk_child_source = $tmp . '/source-added-fk-child-source.sqlite';
    $source_added_fk_child_target = $tmp . '/source-added-fk-child-target.sqlite';
    $source_added_fk_child_metadata = $tmp . '/.forkpress/cow/merge/source-added-fk-child-metadata.sqlite';
    create_base_db($source_added_fk_child_base);
    $db = open_db($source_added_fk_child_base);
    $db->exec('CREATE TABLE plugin_source_added_fk_parent (id INTEGER PRIMARY KEY, label TEXT)');
    $db->close();
    copy($source_added_fk_child_base, $source_added_fk_child_source);
    copy($source_added_fk_child_base, $source_added_fk_child_target);
    cow_merge_capture_row_identities($source_added_fk_child_base, $source_added_fk_child_metadata, 'main');
    cow_merge_capture_row_identities($source_added_fk_child_source, $source_added_fk_child_metadata, 'feature-source-added-fk-child', 'main');
    cow_merge_capture_row_identities($source_added_fk_child_target, $source_added_fk_child_metadata, 'main');
    $db = open_db($source_added_fk_child_source);
    $db->exec("INSERT INTO plugin_source_added_fk_parent (id, label) VALUES (10, 'source restored parent')");
    $db->exec('CREATE TABLE plugin_source_added_fk_child (parent_id INTEGER NOT NULL REFERENCES plugin_source_added_fk_parent(id), label TEXT)');
    $db->exec("INSERT INTO plugin_source_added_fk_child (rowid, parent_id, label) VALUES (9, 10, 'source child awaiting parent')");
    $db->close();
    cow_merge_capture_row_identities($source_added_fk_child_source, $source_added_fk_child_metadata, 'feature-source-added-fk-child', 'main');
    $source_added_fk_child_identity = scalar($source_added_fk_child_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'feature-source-added-fk-child' AND table_name = 'plugin_source_added_fk_child' AND rowid = 9");
    $db = open_db($source_added_fk_child_target);
    $db->exec('DROP TABLE plugin_source_added_fk_parent');
    $db->close();
    $source_added_fk_child_result = cow_merge_databases(
        $source_added_fk_child_base,
        $source_added_fk_child_source,
        $source_added_fk_child_target,
        $source_added_fk_child_metadata,
        'feature-source-added-fk-child',
        'main'
    );
    assert_same($source_added_fk_child_result['status'], 'completed_with_conflicts', 'source-added child rows blocked by missing parent are audited instead of aborting merge');
    assert_same(
        (int)scalar($source_added_fk_child_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_source_added_fk_child'"),
        1,
        'source-added foreign-key child table schema still materializes for row review'
    );
    assert_same((int)scalar($source_added_fk_child_target, 'SELECT COUNT(*) FROM plugin_source_added_fk_child'), 0, 'blocked source-added child row is not inserted by default');
    $source_added_fk_parent_conflict_id = (int)scalar($source_added_fk_child_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_source_added_fk_parent' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    $source_added_fk_child_conflict_id = (int)scalar($source_added_fk_child_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_source_added_fk_child' AND conflict_type = 'row-target-constraint' ORDER BY id DESC LIMIT 1");
    assert_true($source_added_fk_parent_conflict_id > 0, 'missing parent table remains a reviewable schema conflict');
    assert_true($source_added_fk_child_conflict_id > 0, 'blocked source-added child row records a target constraint conflict');
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $source_added_fk_child_metadata,
            $source_added_fk_child_conflict_id,
            'source',
            true,
            'Try source-added child before parent restore.',
            'test'
        ),
        'parent table plugin_source_added_fk_parent could not be inspected',
        'source-added child row resolution remains gated until the parent table is restored'
    );
    $source_added_fk_parent_resolution = cow_merge_resolve_conflict(
        $source_added_fk_child_metadata,
        $source_added_fk_parent_conflict_id,
        'source',
        true,
        'Restore source parent table before child row.',
        'test'
    );
    assert_same($source_added_fk_parent_resolution['status'], 'applied', 'source parent table restore applies before source-added child row resolution');
    $source_added_fk_child_resolution = cow_merge_resolve_conflict(
        $source_added_fk_child_metadata,
        $source_added_fk_child_conflict_id,
        'source',
        true,
        'Apply source-added child row after parent restore.',
        'test'
    );
    assert_same($source_added_fk_child_resolution['status'], 'applied', 'source-added child row resolution applies after the parent exists');
    assert_same(scalar($source_added_fk_child_target, 'SELECT label FROM plugin_source_added_fk_child WHERE rowid = 9'), 'source child awaiting parent', 'source-added keyless child resolution preserves the source sparse rowid');
    $source_added_fk_child_target_identity = scalar($source_added_fk_child_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_source_added_fk_child' AND rowid = 9");
    assert_true(
        cow_merge_decode_payload_json($source_added_fk_child_target_identity, 'target child identity') == cow_merge_decode_payload_json($source_added_fk_child_identity, 'source child identity'),
        'source-added keyless child resolution adopts the source sidecar identity at the preserved rowid'
    );
    $source_added_fk_child_rerun = cow_merge_databases(
        $source_added_fk_child_base,
        $source_added_fk_child_source,
        $source_added_fk_child_target,
        $source_added_fk_child_metadata,
        'feature-source-added-fk-child',
        'main'
    );
    assert_same($source_added_fk_child_rerun['status'], 'completed', 'rerunning after source-added child row resolution completes without a new conflict');

    $source_added_trigger_missing_base = $tmp . '/source-added-trigger-missing-base.sqlite';
    $source_added_trigger_missing_source = $tmp . '/source-added-trigger-missing-source.sqlite';
    $source_added_trigger_missing_target = $tmp . '/source-added-trigger-missing-target.sqlite';
    $source_added_trigger_missing_metadata = $tmp . '/.forkpress/cow/merge/source-added-trigger-missing-metadata.sqlite';
    create_base_db($source_added_trigger_missing_base);
    $db = open_db($source_added_trigger_missing_base);
    $db->exec('CREATE TABLE plugin_trigger_audit (item_label TEXT)');
    $db->close();
    copy($source_added_trigger_missing_base, $source_added_trigger_missing_source);
    copy($source_added_trigger_missing_base, $source_added_trigger_missing_target);
    cow_merge_capture_row_identities($source_added_trigger_missing_base, $source_added_trigger_missing_metadata, 'main');
    cow_merge_capture_row_identities($source_added_trigger_missing_source, $source_added_trigger_missing_metadata, 'feature-source-trigger-missing', 'main');
    cow_merge_capture_row_identities($source_added_trigger_missing_target, $source_added_trigger_missing_metadata, 'main');
    $db = open_db($source_added_trigger_missing_source);
    $db->exec('CREATE TABLE plugin_trigger_items (label TEXT)');
    $db->exec("INSERT INTO plugin_trigger_items (rowid, label) VALUES (5, 'source item')");
    $db->exec('CREATE TRIGGER plugin_trigger_items_audit AFTER INSERT ON plugin_trigger_items BEGIN INSERT INTO plugin_trigger_audit (item_label) VALUES (NEW.label); END');
    $db->close();
    cow_merge_capture_row_identities($source_added_trigger_missing_source, $source_added_trigger_missing_metadata, 'feature-source-trigger-missing', 'main');
    $db = open_db($source_added_trigger_missing_target);
    $db->exec('DROP TABLE plugin_trigger_audit');
    $db->close();
    $source_added_trigger_missing_result = cow_merge_databases(
        $source_added_trigger_missing_base,
        $source_added_trigger_missing_source,
        $source_added_trigger_missing_target,
        $source_added_trigger_missing_metadata,
        'feature-source-trigger-missing',
        'main'
    );
    assert_same($source_added_trigger_missing_result['status'], 'completed_with_conflicts', 'source-added trigger with missing target dependency is audited instead of creating a latent invalid trigger');
    assert_same((int)scalar($source_added_trigger_missing_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_trigger_items'"), 1, 'source-added trigger table still materializes');
    assert_same(scalar($source_added_trigger_missing_target, 'SELECT label FROM plugin_trigger_items WHERE rowid = 5'), 'source item', 'source-added trigger table rows still materialize before trigger review');
    assert_same((int)scalar($source_added_trigger_missing_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_trigger_items_audit'"), 0, 'source-added trigger is held back while its target dependency is missing');
    $source_added_trigger_table_conflict_id = (int)scalar($source_added_trigger_missing_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_trigger_audit' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    $source_added_trigger_conflict_id = (int)scalar($source_added_trigger_missing_metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_trigger_items_audit' AND conflict_type = 'schema-source-added-trigger' ORDER BY id DESC LIMIT 1");
    assert_true($source_added_trigger_table_conflict_id > 0, 'missing trigger dependency remains a reviewable table restore conflict');
    assert_true($source_added_trigger_conflict_id > 0, 'missing trigger dependency records a source-added trigger schema conflict');
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $source_added_trigger_missing_metadata,
            $source_added_trigger_conflict_id,
            'source',
            true,
            'Try trigger before audit table restore.',
            'test'
        ),
        'references missing target schema objects',
        'source-added trigger resolution remains gated until its dependency is restored'
    );
    $source_added_trigger_table_resolution = cow_merge_resolve_conflict(
        $source_added_trigger_missing_metadata,
        $source_added_trigger_table_conflict_id,
        'source',
        true,
        'Restore trigger audit table before trigger.',
        'test'
    );
    assert_same($source_added_trigger_table_resolution['status'], 'applied', 'trigger dependency table restore applies before trigger resolution');
    $source_added_trigger_resolution = cow_merge_resolve_conflict(
        $source_added_trigger_missing_metadata,
        $source_added_trigger_conflict_id,
        'source',
        true,
        'Apply trigger after dependency restore.',
        'test'
    );
    assert_same($source_added_trigger_resolution['status'], 'applied', 'source-added trigger resolution applies after its dependency exists');
    $db = open_db($source_added_trigger_missing_target);
    $db->exec("INSERT INTO plugin_trigger_items (label) VALUES ('post-review item')");
    $db->close();
    assert_same(scalar($source_added_trigger_missing_target, "SELECT item_label FROM plugin_trigger_audit WHERE item_label = 'post-review item'"), 'post-review item', 'reviewed source trigger is functional after dependency restore');
    $source_added_trigger_missing_rerun = cow_merge_databases(
        $source_added_trigger_missing_base,
        $source_added_trigger_missing_source,
        $source_added_trigger_missing_target,
        $source_added_trigger_missing_metadata,
        'feature-source-trigger-missing',
        'main'
    );
    assert_same($source_added_trigger_missing_rerun['status'], 'completed', 'rerunning after source-added trigger resolution completes without a new conflict');

    $source_added_trigger_read_base = $tmp . '/source-added-trigger-read-base.sqlite';
    $source_added_trigger_read_source = $tmp . '/source-added-trigger-read-source.sqlite';
    $source_added_trigger_read_target = $tmp . '/source-added-trigger-read-target.sqlite';
    $source_added_trigger_read_metadata = $tmp . '/.forkpress/cow/merge/source-added-trigger-read-metadata.sqlite';
    create_base_db($source_added_trigger_read_base);
    $db = open_db($source_added_trigger_read_base);
    $db->exec('CREATE TABLE plugin_trigger_read_gate (enabled INTEGER NOT NULL)');
    $db->exec('CREATE TABLE plugin_trigger_read_audit (item_label TEXT)');
    $db->exec('INSERT INTO plugin_trigger_read_gate (enabled) VALUES (1)');
    $db->close();
    copy($source_added_trigger_read_base, $source_added_trigger_read_source);
    copy($source_added_trigger_read_base, $source_added_trigger_read_target);
    cow_merge_capture_row_identities($source_added_trigger_read_base, $source_added_trigger_read_metadata, 'main');
    cow_merge_capture_row_identities($source_added_trigger_read_source, $source_added_trigger_read_metadata, 'feature-source-trigger-read', 'main');
    cow_merge_capture_row_identities($source_added_trigger_read_target, $source_added_trigger_read_metadata, 'main');
    $db = open_db($source_added_trigger_read_source);
    $db->exec('CREATE TABLE plugin_trigger_read_items (label TEXT)');
    $db->exec("INSERT INTO plugin_trigger_read_items (rowid, label) VALUES (7, 'read dependency source item')");
    $db->exec('CREATE TRIGGER plugin_trigger_read_items_audit AFTER INSERT ON plugin_trigger_read_items BEGIN INSERT INTO plugin_trigger_read_audit (item_label) SELECT NEW.label FROM plugin_trigger_read_gate WHERE enabled = 1; END');
    $db->close();
    cow_merge_capture_row_identities($source_added_trigger_read_source, $source_added_trigger_read_metadata, 'feature-source-trigger-read', 'main');
    $db = open_db($source_added_trigger_read_target);
    $db->exec('DROP TABLE plugin_trigger_read_gate');
    $db->close();
    $source_added_trigger_read_result = cow_merge_databases(
        $source_added_trigger_read_base,
        $source_added_trigger_read_source,
        $source_added_trigger_read_target,
        $source_added_trigger_read_metadata,
        'feature-source-trigger-read',
        'main'
    );
    assert_same($source_added_trigger_read_result['status'], 'completed_with_conflicts', 'source-added trigger with missing read dependency is audited');
    assert_same((int)scalar($source_added_trigger_read_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_trigger_read_items'"), 1, 'source-added trigger read table still materializes');
    assert_same((int)scalar($source_added_trigger_read_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_trigger_read_items_audit'"), 0, 'source-added trigger with missing read dependency is held back');
    $source_added_trigger_read_table_conflict_id = (int)scalar($source_added_trigger_read_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_trigger_read_gate' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    $source_added_trigger_read_conflict_id = (int)scalar($source_added_trigger_read_metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_trigger_read_items_audit' AND conflict_type = 'schema-source-added-trigger' ORDER BY id DESC LIMIT 1");
    assert_true($source_added_trigger_read_table_conflict_id > 0, 'missing trigger read dependency remains a reviewable table restore conflict');
    assert_true($source_added_trigger_read_conflict_id > 0, 'missing trigger read dependency records a source-added trigger schema conflict');
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $source_added_trigger_read_metadata,
            $source_added_trigger_read_conflict_id,
            'source',
            true,
            'Try read trigger before gate restore.',
            'test'
        ),
        'references missing target schema objects',
        'source-added trigger read resolution remains gated until its dependency is restored'
    );
    $source_added_trigger_read_table_resolution = cow_merge_resolve_conflict(
        $source_added_trigger_read_metadata,
        $source_added_trigger_read_table_conflict_id,
        'source',
        true,
        'Restore trigger read gate before trigger.',
        'test'
    );
    assert_same($source_added_trigger_read_table_resolution['status'], 'applied', 'trigger read dependency table restore applies before trigger resolution');
    $source_added_trigger_read_resolution = cow_merge_resolve_conflict(
        $source_added_trigger_read_metadata,
        $source_added_trigger_read_conflict_id,
        'source',
        true,
        'Apply trigger after read dependency restore.',
        'test'
    );
    assert_same($source_added_trigger_read_resolution['status'], 'applied', 'source-added trigger with read dependency applies after its dependency exists');
    $db = open_db($source_added_trigger_read_target);
    $db->exec("INSERT INTO plugin_trigger_read_items (label) VALUES ('post-review read item')");
    $db->close();
    assert_same(scalar($source_added_trigger_read_target, "SELECT item_label FROM plugin_trigger_read_audit WHERE item_label = 'post-review read item'"), 'post-review read item', 'reviewed source trigger reads restored dependency when it fires');
    $source_added_trigger_read_rerun = cow_merge_databases(
        $source_added_trigger_read_base,
        $source_added_trigger_read_source,
        $source_added_trigger_read_target,
        $source_added_trigger_read_metadata,
        'feature-source-trigger-read',
        'main'
    );
    assert_same($source_added_trigger_read_rerun['status'], 'completed', 'rerunning after source-added trigger read resolution completes without a new conflict');

    $source_added_trigger_column_base = $tmp . '/source-added-trigger-column-base.sqlite';
    $source_added_trigger_column_source = $tmp . '/source-added-trigger-column-source.sqlite';
    $source_added_trigger_column_target = $tmp . '/source-added-trigger-column-target.sqlite';
    $source_added_trigger_column_metadata = $tmp . '/.forkpress/cow/merge/source-added-trigger-column-metadata.sqlite';
    create_base_db($source_added_trigger_column_base);
    $db = open_db($source_added_trigger_column_base);
    $db->exec('CREATE TABLE plugin_trigger_column_audit (item_label TEXT)');
    $db->close();
    copy($source_added_trigger_column_base, $source_added_trigger_column_source);
    copy($source_added_trigger_column_base, $source_added_trigger_column_target);
    cow_merge_capture_row_identities($source_added_trigger_column_base, $source_added_trigger_column_metadata, 'main');
    cow_merge_capture_row_identities($source_added_trigger_column_source, $source_added_trigger_column_metadata, 'feature-source-trigger-column', 'main');
    cow_merge_capture_row_identities($source_added_trigger_column_target, $source_added_trigger_column_metadata, 'main');
    $db = open_db($source_added_trigger_column_source);
    $db->exec('CREATE TABLE plugin_trigger_column_items (label TEXT)');
    $db->exec("INSERT INTO plugin_trigger_column_items (rowid, label) VALUES (9, 'column dependency source item')");
    $db->exec('CREATE TRIGGER plugin_trigger_column_items_audit AFTER INSERT ON plugin_trigger_column_items BEGIN INSERT INTO plugin_trigger_column_audit (missing_label) VALUES (NEW.label); END');
    $db->close();
    cow_merge_capture_row_identities($source_added_trigger_column_source, $source_added_trigger_column_metadata, 'feature-source-trigger-column', 'main');
    $source_added_trigger_column_result = cow_merge_databases(
        $source_added_trigger_column_base,
        $source_added_trigger_column_source,
        $source_added_trigger_column_target,
        $source_added_trigger_column_metadata,
        'feature-source-trigger-column',
        'main'
    );
    assert_same($source_added_trigger_column_result['status'], 'completed_with_conflicts', 'source-added trigger with an invalid target column is audited');
    assert_same((int)scalar($source_added_trigger_column_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_trigger_column_items'"), 1, 'source-added trigger column table still materializes');
    assert_same((int)scalar($source_added_trigger_column_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_trigger_column_items_audit'"), 0, 'source-added trigger with invalid column dependency is held back');
    $source_added_trigger_column_conflict_id = (int)scalar($source_added_trigger_column_metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_trigger_column_items_audit' AND conflict_type = 'schema-source-added-trigger' ORDER BY id DESC LIMIT 1");
    assert_true($source_added_trigger_column_conflict_id > 0, 'invalid trigger column dependency records a source-added trigger schema conflict');
    $source_added_trigger_column_payload = cow_merge_decode_payload_json(
        (string)scalar($source_added_trigger_column_metadata, "SELECT source_payload FROM merge_conflicts WHERE id = $source_added_trigger_column_conflict_id"),
        'source trigger column payload'
    );
    assert_true(
        is_array($source_added_trigger_column_payload)
            && str_contains((string)($source_added_trigger_column_payload['error'] ?? ''), 'missing_label'),
        'invalid trigger column conflict payload includes the rejected column'
    );
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $source_added_trigger_column_metadata,
            $source_added_trigger_column_conflict_id,
            'source',
            true,
            'Try trigger before audit column exists.',
            'test'
        ),
        'failed target trigger validation',
        'source-added trigger resolution remains gated while target columns are invalid'
    );
    assert_same((int)scalar($source_added_trigger_column_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_trigger_column_items_audit'"), 0, 'failed trigger column resolution rolls back the invalid trigger');

    $source_added_trigger_runtime_base = $tmp . '/source-added-trigger-runtime-base.sqlite';
    $source_added_trigger_runtime_source = $tmp . '/source-added-trigger-runtime-source.sqlite';
    $source_added_trigger_runtime_target = $tmp . '/source-added-trigger-runtime-target.sqlite';
    $source_added_trigger_runtime_metadata = $tmp . '/.forkpress/cow/merge/source-added-trigger-runtime-metadata.sqlite';
    create_base_db($source_added_trigger_runtime_base);
    $db = open_db($source_added_trigger_runtime_base);
    $db->exec('CREATE TABLE plugin_trigger_runtime_audit (item_label TEXT)');
    $db->close();
    copy($source_added_trigger_runtime_base, $source_added_trigger_runtime_source);
    copy($source_added_trigger_runtime_base, $source_added_trigger_runtime_target);
    cow_merge_capture_row_identities($source_added_trigger_runtime_base, $source_added_trigger_runtime_metadata, 'main');
    cow_merge_capture_row_identities($source_added_trigger_runtime_source, $source_added_trigger_runtime_metadata, 'feature-source-trigger-runtime', 'main');
    cow_merge_capture_row_identities($source_added_trigger_runtime_target, $source_added_trigger_runtime_metadata, 'main');
    $db = open_db($source_added_trigger_runtime_source);
    $db->exec('CREATE TABLE plugin_trigger_runtime_items (label TEXT)');
    $db->exec("INSERT INTO plugin_trigger_runtime_items (rowid, label) VALUES (11, 'runtime validation source item')");
    $db->exec('CREATE TRIGGER plugin_trigger_runtime_insert_old AFTER INSERT ON plugin_trigger_runtime_items BEGIN INSERT INTO plugin_trigger_runtime_audit (item_label) VALUES (OLD.label); END');
    $db->exec('CREATE TRIGGER plugin_trigger_runtime_delete_new AFTER DELETE ON plugin_trigger_runtime_items BEGIN INSERT INTO plugin_trigger_runtime_audit (item_label) VALUES (NEW.label); END');
    $db->exec('CREATE TRIGGER plugin_trigger_runtime_update_missing AFTER UPDATE OF missing_label ON plugin_trigger_runtime_items BEGIN INSERT INTO plugin_trigger_runtime_audit (item_label) VALUES (NEW.label); END');
    $db->close();
    cow_merge_capture_row_identities($source_added_trigger_runtime_source, $source_added_trigger_runtime_metadata, 'feature-source-trigger-runtime', 'main');
    $source_added_trigger_runtime_result = cow_merge_databases(
        $source_added_trigger_runtime_base,
        $source_added_trigger_runtime_source,
        $source_added_trigger_runtime_target,
        $source_added_trigger_runtime_metadata,
        'feature-source-trigger-runtime',
        'main'
    );
    assert_same($source_added_trigger_runtime_result['status'], 'completed_with_conflicts', 'source-added triggers with invalid runtime programs are audited');
    assert_same((int)scalar($source_added_trigger_runtime_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_trigger_runtime_items'"), 1, 'source-added trigger runtime table still materializes');
    assert_same((int)scalar($source_added_trigger_runtime_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'plugin_trigger_runtime_%'"), 0, 'source-added triggers with invalid runtime programs are held back');
    $runtime_trigger_errors = [];
    foreach ([
        'plugin_trigger_runtime_insert_old' => 'OLD is not available',
        'plugin_trigger_runtime_delete_new' => 'NEW is not available',
        'plugin_trigger_runtime_update_missing' => 'missing_label',
    ] as $trigger_name => $expected_error) {
        $conflict_id = (int)scalar($source_added_trigger_runtime_metadata, "SELECT id FROM merge_conflicts WHERE column_name = '$trigger_name' AND conflict_type = 'schema-source-added-trigger' ORDER BY id DESC LIMIT 1");
        assert_true($conflict_id > 0, "$trigger_name records a source-added trigger runtime conflict");
        $payload = cow_merge_decode_payload_json(
            (string)scalar($source_added_trigger_runtime_metadata, "SELECT source_payload FROM merge_conflicts WHERE id = $conflict_id"),
            "$trigger_name runtime payload"
        );
        $runtime_trigger_errors[$trigger_name] = (string)($payload['error'] ?? '');
        assert_true(
            str_contains($runtime_trigger_errors[$trigger_name], $expected_error),
            "$trigger_name conflict payload includes the rejected runtime reference"
        );
        assert_throws(
            fn() => cow_merge_resolve_conflict(
                $source_added_trigger_runtime_metadata,
                $conflict_id,
                'source',
                true,
                "Try invalid runtime trigger $trigger_name.",
                'test'
            ),
            'failed target trigger validation',
            "$trigger_name resolution remains gated by trigger program validation"
        );
    }
    assert_same(
        (int)scalar($source_added_trigger_runtime_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE conflict_type = 'schema-source-added-trigger' AND column_name LIKE 'plugin_trigger_runtime_%'"),
        3,
        'invalid OLD, invalid NEW, and invalid UPDATE OF triggers each remain separately reviewable'
    );

    $trigger_cte_refs = cow_merge_trigger_referenced_tables(
        'CREATE TRIGGER plugin_trigger_cte_items_audit AFTER INSERT ON plugin_trigger_cte_items BEGIN ' .
        'INSERT INTO plugin_trigger_cte_audit (item_label) ' .
        'WITH plugin_trigger_cte_rows(item_label) AS (SELECT NEW.label FROM plugin_trigger_cte_gate) ' .
        'SELECT item_label FROM plugin_trigger_cte_rows; END'
    );
    sort($trigger_cte_refs);
    assert_same(
        $trigger_cte_refs,
        ['plugin_trigger_cte_audit', 'plugin_trigger_cte_gate'],
        'trigger dependency parsing ignores CTE aliases while retaining real tables read inside the CTE'
    );
    $trigger_write_refs = cow_merge_trigger_written_schema_objects(
        'CREATE TRIGGER plugin_trigger_write_items_audit AFTER INSERT ON plugin_trigger_write_items BEGIN ' .
        'INSERT INTO plugin_trigger_write_audit (item_label) ' .
        'WITH plugin_trigger_write_rows(item_label) AS (SELECT NEW.label FROM plugin_trigger_write_gate) ' .
        'SELECT item_label FROM plugin_trigger_write_rows; ' .
        'UPDATE main.plugin_trigger_write_gate SET enabled = 1; ' .
        'DELETE FROM temp.plugin_trigger_write_temp; ' .
        'SELECT NEW.label FROM plugin_trigger_write_read_only; END'
    );
    usort($trigger_write_refs, fn(array $a, array $b): int => strcmp(($a['schema'] ?? '') . '.' . $a['name'], ($b['schema'] ?? '') . '.' . $b['name']));
    assert_same(
        $trigger_write_refs,
        [
            ['schema' => null, 'name' => 'plugin_trigger_write_audit'],
            ['schema' => 'main', 'name' => 'plugin_trigger_write_gate'],
            ['schema' => 'temp', 'name' => 'plugin_trigger_write_temp'],
        ],
        'trigger write dependency parsing keeps DML targets while ignoring CTE aliases and read-only references'
    );

    $trigger_temp_target = $tmp . '/trigger-temp-reference-target.sqlite';
    create_base_db($trigger_temp_target);
    $db = open_db($trigger_temp_target);
    $db->exec('CREATE TABLE plugin_trigger_temp_items (label TEXT)');
    $db->exec('CREATE TABLE plugin_trigger_temp_gate (enabled INTEGER)');
    $db->exec('CREATE TABLE plugin_trigger_temp_audit (item_label TEXT)');
    $db->close();
    $db = open_db($trigger_temp_target);
    $trigger_temp_missing = cow_merge_missing_trigger_references(
        $db,
        'CREATE TRIGGER plugin_trigger_temp_items_audit AFTER INSERT ON plugin_trigger_temp_items BEGIN ' .
        'INSERT INTO plugin_trigger_temp_audit (item_label) ' .
        'SELECT NEW.label FROM temp.plugin_trigger_temp_gate WHERE enabled = 1; END'
    );
    $db->close();
    assert_same(
        $trigger_temp_missing,
        ['temp.plugin_trigger_temp_gate'],
        'trigger dependency parsing treats temporary-schema references as non-persistent even when main has the same table name'
    );
    assert_throws(
        fn() => cow_merge_validate_trigger_references(
            open_db($trigger_temp_target),
            'plugin_trigger_temp_items_audit',
            'CREATE TRIGGER plugin_trigger_temp_items_audit AFTER INSERT ON plugin_trigger_temp_items BEGIN ' .
            'SELECT NEW.label FROM temp.plugin_trigger_temp_gate WHERE enabled = 1; END'
        ),
        'temp.plugin_trigger_temp_gate',
        'source-added trigger validation rejects temporary-schema dependencies explicitly'
    );

    $trigger_quoted_target = $tmp . '/trigger-quoted-reference-target.sqlite';
    create_base_db($trigger_quoted_target);
    $db = open_db($trigger_quoted_target);
    $db->exec('CREATE TABLE "plugin trigger quoted items" (label TEXT)');
    $db->exec('CREATE TABLE "plugin trigger quoted gate" (enabled INTEGER)');
    $db->exec('CREATE TABLE "plugin trigger quoted audit" (item_label TEXT)');
    $db->exec('CREATE TABLE plugin_trigger_literal_items (label TEXT)');
    $db->close();
    $db = open_db($trigger_quoted_target);
    $trigger_quoted_main_missing = cow_merge_missing_trigger_references(
        $db,
        'CREATE TRIGGER "plugin trigger quoted items audit" AFTER INSERT ON "plugin trigger quoted items" BEGIN ' .
        'INSERT INTO "main"."plugin trigger quoted audit" (item_label) ' .
        'SELECT NEW.label FROM "main"."plugin trigger quoted gate" WHERE enabled = 1; END'
    );
    $trigger_quoted_aux_missing = cow_merge_missing_trigger_references(
        $db,
        'CREATE TRIGGER "plugin trigger quoted items audit" AFTER INSERT ON "plugin trigger quoted items" BEGIN ' .
        'INSERT INTO "main"."plugin trigger quoted audit" (item_label) ' .
        'SELECT NEW.label FROM "aux"."plugin trigger quoted gate" WHERE enabled = 1; END'
    );
    $trigger_literal_missing = cow_merge_missing_trigger_references(
        $db,
        'CREATE TRIGGER plugin_trigger_literal_items_audit AFTER INSERT ON plugin_trigger_literal_items BEGIN ' .
        'INSERT INTO "plugin trigger quoted audit" (item_label) VALUES (\'FROM plugin_trigger_literal_missing JOIN temp.plugin_trigger_literal_temp\'); ' .
        '-- FROM plugin_trigger_comment_missing' . "\n" .
        'SELECT "JOIN plugin_trigger_double_quoted_literal"; END'
    );
    $db->close();
    assert_same($trigger_quoted_main_missing, [], 'quoted main-schema trigger references match persistent target schema objects');
    assert_same($trigger_quoted_aux_missing, ['aux.plugin trigger quoted gate'], 'quoted attached-schema trigger references remain validation-gated');
    assert_same($trigger_literal_missing, [], 'trigger dependency parsing ignores schema-looking text inside literals and comments');

    $view_literal_refs = cow_merge_sql_referenced_schema_objects(
        'CREATE VIEW "plugin view quoted child" AS ' .
        'SELECT \'FROM plugin_view_literal_missing\' AS literal_text, label FROM "main"."plugin view quoted source"'
    );
    assert_same(
        $view_literal_refs,
        [['schema' => 'main', 'name' => 'plugin view quoted source']],
        'view dependency parsing keeps quoted schema references while ignoring literal text'
    );
    $view_quoted_target = $tmp . '/view-quoted-reference-target.sqlite';
    create_base_db($view_quoted_target);
    $db = open_db($view_quoted_target);
    $db->exec('CREATE TABLE "plugin view quoted gate" (enabled INTEGER)');
    $view_quoted_main_missing = cow_merge_missing_view_references(
        $db,
        'CREATE VIEW "plugin view quoted child" AS SELECT enabled FROM "main"."plugin view quoted gate"'
    );
    $view_quoted_aux_missing = cow_merge_missing_view_references(
        $db,
        'CREATE VIEW "plugin view quoted child" AS SELECT enabled FROM "aux"."plugin view quoted gate"'
    );
    $view_literal_missing = cow_merge_missing_view_references(
        $db,
        'CREATE VIEW plugin_view_literal_child AS ' .
        'SELECT \'FROM plugin_view_literal_missing\' AS literal_text FROM "plugin view quoted gate" ' .
        '/* JOIN plugin_view_comment_missing */'
    );
    $db->close();
    assert_same($view_quoted_main_missing, [], 'quoted main-schema view references match persistent target schema objects');
    assert_same($view_quoted_aux_missing, ['aux.plugin view quoted gate'], 'quoted attached-schema view references remain validation-gated');
    assert_same($view_literal_missing, [], 'view dependency preflight ignores schema-looking text inside literals and comments');

    $source_added_trigger_cte_base = $tmp . '/source-added-trigger-cte-base.sqlite';
    $source_added_trigger_cte_source = $tmp . '/source-added-trigger-cte-source.sqlite';
    $source_added_trigger_cte_target = $tmp . '/source-added-trigger-cte-target.sqlite';
    $source_added_trigger_cte_metadata = $tmp . '/.forkpress/cow/merge/source-added-trigger-cte-metadata.sqlite';
    create_base_db($source_added_trigger_cte_base);
    $db = open_db($source_added_trigger_cte_base);
    $db->exec('CREATE TABLE plugin_trigger_cte_audit (item_label TEXT)');
    $db->close();
    copy($source_added_trigger_cte_base, $source_added_trigger_cte_source);
    copy($source_added_trigger_cte_base, $source_added_trigger_cte_target);
    cow_merge_capture_row_identities($source_added_trigger_cte_base, $source_added_trigger_cte_metadata, 'main');
    cow_merge_capture_row_identities($source_added_trigger_cte_source, $source_added_trigger_cte_metadata, 'feature-source-trigger-cte', 'main');
    cow_merge_capture_row_identities($source_added_trigger_cte_target, $source_added_trigger_cte_metadata, 'main');
    $db = open_db($source_added_trigger_cte_source);
    $db->exec('CREATE TABLE plugin_trigger_cte_items (label TEXT)');
    $db->exec("INSERT INTO plugin_trigger_cte_items (rowid, label) VALUES (3, 'cte source item')");
    $db->exec(
        'CREATE TRIGGER plugin_trigger_cte_items_audit AFTER INSERT ON plugin_trigger_cte_items BEGIN ' .
        'INSERT INTO plugin_trigger_cte_audit (item_label) ' .
        'WITH plugin_trigger_cte_rows(item_label) AS (SELECT NEW.label) ' .
        'SELECT item_label FROM plugin_trigger_cte_rows; END'
    );
    $db->close();
    cow_merge_capture_row_identities($source_added_trigger_cte_source, $source_added_trigger_cte_metadata, 'feature-source-trigger-cte', 'main');
    $source_added_trigger_cte_result = cow_merge_databases(
        $source_added_trigger_cte_base,
        $source_added_trigger_cte_source,
        $source_added_trigger_cte_target,
        $source_added_trigger_cte_metadata,
        'feature-source-trigger-cte',
        'main'
    );
    assert_same($source_added_trigger_cte_result['status'], 'completed', 'source-added trigger with CTE alias applies without a false missing dependency conflict');
    assert_same((int)scalar($source_added_trigger_cte_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_trigger_cte_items_audit'"), 1, 'source-added trigger with CTE alias is installed');
    assert_same((int)scalar($source_added_trigger_cte_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE column_name = 'plugin_trigger_cte_items_audit' AND conflict_type = 'schema-source-added-trigger'"), 0, 'CTE alias does not create a source-added trigger schema conflict');
    $db = open_db($source_added_trigger_cte_target);
    $db->exec("INSERT INTO plugin_trigger_cte_items (label) VALUES ('post-merge cte item')");
    $db->close();
    assert_same(scalar($source_added_trigger_cte_target, "SELECT item_label FROM plugin_trigger_cte_audit WHERE item_label = 'post-merge cte item'"), 'post-merge cte item', 'source-added trigger with CTE alias fires after merge');

    $source_added_view_order_base = $tmp . '/source-added-view-order-base.sqlite';
    $source_added_view_order_source = $tmp . '/source-added-view-order-source.sqlite';
    $source_added_view_order_target = $tmp . '/source-added-view-order-target.sqlite';
    $source_added_view_order_metadata = $tmp . '/.forkpress/cow/merge/source-added-view-order-metadata.sqlite';
    create_base_db($source_added_view_order_base);
    copy($source_added_view_order_base, $source_added_view_order_source);
    copy($source_added_view_order_base, $source_added_view_order_target);
    $db = open_db($source_added_view_order_source);
    $db->exec('CREATE VIEW plugin_child_source_view AS SELECT item_id, label FROM plugin_parent_source_view');
    $db->exec("CREATE VIEW plugin_parent_source_view AS SELECT item_id, label FROM plugin_items WHERE value = 'source view chain'");
    $db->close();
    $source_added_view_order_result = cow_merge_databases(
        $source_added_view_order_base,
        $source_added_view_order_source,
        $source_added_view_order_target,
        $source_added_view_order_metadata,
        'feature-source-view-order',
        'main'
    );
    assert_same($source_added_view_order_result['status'], 'completed', 'source-added views are ordered by source view dependencies');
    assert_same((int)scalar($source_added_view_order_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name IN ('plugin_child_source_view', 'plugin_parent_source_view')"), 2, 'dependent source-added views both materialize');
    $db = open_db($source_added_view_order_target);
    $db->exec("INSERT INTO plugin_items (item_id, label, value) VALUES ('view-order', 'View Order', 'source view chain')");
    $db->close();
    assert_same(scalar($source_added_view_order_target, "SELECT label FROM plugin_child_source_view WHERE item_id = 'view-order'"), 'View Order', 'dependent source-added view is queryable after ordered materialization');
    assert_same((int)scalar($source_added_view_order_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE conflict_type = 'schema-source-added-view'"), 0, 'ordered source-added views do not create false schema conflicts');
    assert_same((int)scalar($source_added_view_order_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE column_name IN ('plugin_child_source_view', 'plugin_parent_source_view') AND decision = 'source-applied'"), 2, 'ordered source-added view decisions are auditable');

    $source_added_view_cycle_base = $tmp . '/source-added-view-cycle-base.sqlite';
    $source_added_view_cycle_source = $tmp . '/source-added-view-cycle-source.sqlite';
    $source_added_view_cycle_target = $tmp . '/source-added-view-cycle-target.sqlite';
    $source_added_view_cycle_metadata = $tmp . '/.forkpress/cow/merge/source-added-view-cycle-metadata.sqlite';
    create_base_db($source_added_view_cycle_base);
    copy($source_added_view_cycle_base, $source_added_view_cycle_source);
    copy($source_added_view_cycle_base, $source_added_view_cycle_target);
    $db = open_db($source_added_view_cycle_source);
    $db->exec('CREATE VIEW plugin_cycle_alpha_view AS SELECT item_id, label FROM plugin_cycle_beta_view');
    $db->exec('CREATE VIEW plugin_cycle_beta_view AS SELECT item_id, label FROM plugin_cycle_alpha_view');
    $db->exec('CREATE VIEW plugin_cycle_self_view AS SELECT item_id, label FROM plugin_cycle_self_view');
    $db->close();
    $source_added_view_cycle_result = cow_merge_databases(
        $source_added_view_cycle_base,
        $source_added_view_cycle_source,
        $source_added_view_cycle_target,
        $source_added_view_cycle_metadata,
        'feature-source-view-cycle',
        'main'
    );
    assert_same($source_added_view_cycle_result['status'], 'completed_with_conflicts', 'source-added cyclic views are held as reviewable schema conflicts');
    assert_same((int)scalar($source_added_view_cycle_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name LIKE 'plugin_cycle_%'"), 0, 'source-added cyclic views are not installed on target');
    foreach ([
        'plugin_cycle_alpha_view' => 'plugin_cycle_alpha_view -> plugin_cycle_beta_view -> plugin_cycle_alpha_view',
        'plugin_cycle_beta_view' => 'plugin_cycle_alpha_view -> plugin_cycle_beta_view -> plugin_cycle_alpha_view',
        'plugin_cycle_self_view' => 'plugin_cycle_self_view -> plugin_cycle_self_view',
    ] as $view_name => $expected_cycle) {
        $conflict_id = (int)scalar($source_added_view_cycle_metadata, "SELECT id FROM merge_conflicts WHERE column_name = '$view_name' AND conflict_type = 'schema-source-added-view' ORDER BY id DESC LIMIT 1");
        assert_true($conflict_id > 0, "$view_name records a source-added cyclic view conflict");
        $payload = cow_merge_decode_payload_json(
            (string)scalar($source_added_view_cycle_metadata, "SELECT source_payload FROM merge_conflicts WHERE id = $conflict_id"),
            "$view_name cyclic view payload"
        );
        assert_true(
            str_contains((string)($payload['error'] ?? ''), 'unsupported cyclic source view dependencies') &&
                str_contains((string)($payload['error'] ?? ''), $expected_cycle),
            "$view_name cyclic view conflict payload records the unsupported dependency cycle"
        );
        assert_throws(
            fn() => cow_merge_resolve_conflict(
                $source_added_view_cycle_metadata,
                $conflict_id,
                'source',
                true,
                "Try cyclic view $view_name.",
                'test'
            ),
            'unsupported cyclic source view dependencies',
            "$view_name source resolution preserves the audited cycle reason"
        );
    }
    assert_same(
        (int)scalar($source_added_view_cycle_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id IN (SELECT id FROM merge_conflicts WHERE conflict_type = 'schema-source-added-view')"),
        0,
        'failed cyclic view resolution attempts do not record resolutions'
    );

    $source_added_view_target_cycle_base = $tmp . '/source-added-view-target-cycle-base.sqlite';
    $source_added_view_target_cycle_source = $tmp . '/source-added-view-target-cycle-source.sqlite';
    $source_added_view_target_cycle_target = $tmp . '/source-added-view-target-cycle-target.sqlite';
    $source_added_view_target_cycle_metadata = $tmp . '/.forkpress/cow/merge/source-added-view-target-cycle-metadata.sqlite';
    create_base_db($source_added_view_target_cycle_base);
    copy($source_added_view_target_cycle_base, $source_added_view_target_cycle_source);
    copy($source_added_view_target_cycle_base, $source_added_view_target_cycle_target);
    $db = open_db($source_added_view_target_cycle_source);
    $db->exec('CREATE VIEW plugin_target_cycle_source_view AS SELECT item_id, label FROM plugin_target_cycle_existing_view');
    $db->close();
    $db = open_db($source_added_view_target_cycle_target);
    $db->exec('CREATE VIEW plugin_target_cycle_existing_view AS SELECT item_id, label FROM plugin_target_cycle_source_view');
    $db->close();
    $source_added_view_target_cycle_result = cow_merge_databases(
        $source_added_view_target_cycle_base,
        $source_added_view_target_cycle_source,
        $source_added_view_target_cycle_target,
        $source_added_view_target_cycle_metadata,
        'feature-source-view-target-cycle',
        'main'
    );
    assert_same($source_added_view_target_cycle_result['status'], 'completed_with_conflicts', 'source-added view cycles with target-side views are held as reviewable schema conflicts');
    assert_same((int)scalar($source_added_view_target_cycle_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_target_cycle_source_view'"), 0, 'source-added view is not installed when it cycles with a target view');
    assert_same((int)scalar($source_added_view_target_cycle_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_target_cycle_existing_view'"), 1, 'blocked source-added view cycle preserves the target view');
    $source_added_view_target_cycle_conflict_id = (int)scalar($source_added_view_target_cycle_metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_target_cycle_source_view' AND conflict_type = 'schema-source-added-view' ORDER BY id DESC LIMIT 1");
    assert_true($source_added_view_target_cycle_conflict_id > 0, 'source-added target-view cycle records a schema conflict');
    $source_added_view_target_cycle_payload = cow_merge_decode_payload_json(
        (string)scalar($source_added_view_target_cycle_metadata, "SELECT source_payload FROM merge_conflicts WHERE id = $source_added_view_target_cycle_conflict_id"),
        'source-added target-view cycle payload'
    );
    assert_true(
        str_contains((string)($source_added_view_target_cycle_payload['error'] ?? ''), 'unsupported cyclic view dependencies') &&
            str_contains((string)($source_added_view_target_cycle_payload['error'] ?? ''), 'plugin_target_cycle_existing_view') &&
            str_contains((string)($source_added_view_target_cycle_payload['error'] ?? ''), 'plugin_target_cycle_source_view'),
        'source-added target-view cycle payload records both view names'
    );
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $source_added_view_target_cycle_metadata,
            $source_added_view_target_cycle_conflict_id,
            'source',
            true,
            'Try source-added view with target view cycle.',
            'test'
        ),
        'unsupported cyclic view dependencies',
        'source-added target-view cycle resolution remains validation-gated'
    );
    assert_same(
        (int)scalar($source_added_view_target_cycle_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $source_added_view_target_cycle_conflict_id"),
        0,
        'failed source-added target-view cycle resolution does not record a resolution'
    );

    $source_added_trigger_order_base = $tmp . '/source-added-trigger-order-base.sqlite';
    $source_added_trigger_order_source = $tmp . '/source-added-trigger-order-source.sqlite';
    $source_added_trigger_order_target = $tmp . '/source-added-trigger-order-target.sqlite';
    $source_added_trigger_order_metadata = $tmp . '/.forkpress/cow/merge/source-added-trigger-order-metadata.sqlite';
    create_base_db($source_added_trigger_order_base);
    $db = open_db($source_added_trigger_order_base);
    $db->exec('CREATE TABLE plugin_trigger_order_items (item_id TEXT DEFAULT "default-id", label TEXT DEFAULT "default-label")');
    $db->exec('CREATE TABLE plugin_trigger_order_audit (item_id TEXT, label TEXT)');
    $db->close();
    copy($source_added_trigger_order_base, $source_added_trigger_order_source);
    copy($source_added_trigger_order_base, $source_added_trigger_order_target);
    $db = open_db($source_added_trigger_order_source);
    $db->exec('CREATE VIEW plugin_trigger_order_view AS SELECT item_id, label FROM plugin_trigger_order_items');
    $db->exec('CREATE TRIGGER z_plugin_trigger_order_view_insert INSTEAD OF INSERT ON plugin_trigger_order_view BEGIN INSERT INTO plugin_trigger_order_audit (item_id, label) VALUES (NEW.item_id, NEW.label); END');
    $db->exec('CREATE TRIGGER a_plugin_trigger_order_items_after AFTER INSERT ON plugin_trigger_order_items BEGIN INSERT INTO plugin_trigger_order_view (item_id, label) VALUES (NEW.item_id, NEW.label); END');
    $db->close();
    $source_added_trigger_order_result = cow_merge_databases(
        $source_added_trigger_order_base,
        $source_added_trigger_order_source,
        $source_added_trigger_order_target,
        $source_added_trigger_order_metadata,
        'feature-source-trigger-order',
        'main'
    );
    assert_same($source_added_trigger_order_result['status'], 'completed', 'source-added trigger dependencies are installed before dependent trigger programs');
    assert_same((int)scalar($source_added_trigger_order_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name IN ('a_plugin_trigger_order_items_after', 'z_plugin_trigger_order_view_insert')"), 2, 'dependent source-added triggers both install');
    assert_same((int)scalar($source_added_trigger_order_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE conflict_type = 'schema-source-added-trigger' AND column_name LIKE '%plugin_trigger_order%'"), 0, 'ordered source-added triggers do not create false schema conflicts');
    assert_same((int)scalar($source_added_trigger_order_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE column_name IN ('a_plugin_trigger_order_items_after', 'z_plugin_trigger_order_view_insert') AND decision = 'source-applied'"), 2, 'ordered source-added trigger decisions are auditable');
    $db = open_db($source_added_trigger_order_target);
    $db->exec("INSERT INTO plugin_trigger_order_items (item_id, label) VALUES ('trigger-order', 'Trigger Order')");
    $db->close();
    assert_same(scalar($source_added_trigger_order_target, "SELECT label FROM plugin_trigger_order_audit WHERE item_id = 'trigger-order'"), 'Trigger Order', 'dependent source-added trigger chain fires after ordered materialization');

    $source_added_trigger_cycle_base = $tmp . '/source-added-trigger-cycle-base.sqlite';
    $source_added_trigger_cycle_source = $tmp . '/source-added-trigger-cycle-source.sqlite';
    $source_added_trigger_cycle_target = $tmp . '/source-added-trigger-cycle-target.sqlite';
    $source_added_trigger_cycle_metadata = $tmp . '/.forkpress/cow/merge/source-added-trigger-cycle-metadata.sqlite';
    create_base_db($source_added_trigger_cycle_base);
    $db = open_db($source_added_trigger_cycle_base);
    $db->exec('CREATE TABLE plugin_trigger_cycle_alpha (label TEXT)');
    $db->exec('CREATE TABLE plugin_trigger_cycle_beta (label TEXT)');
    $db->exec('CREATE TABLE plugin_trigger_cycle_self (label TEXT)');
    $db->close();
    copy($source_added_trigger_cycle_base, $source_added_trigger_cycle_source);
    copy($source_added_trigger_cycle_base, $source_added_trigger_cycle_target);
    $db = open_db($source_added_trigger_cycle_source);
    $db->exec('CREATE TRIGGER plugin_trigger_cycle_alpha_insert AFTER INSERT ON plugin_trigger_cycle_alpha BEGIN INSERT INTO plugin_trigger_cycle_beta (label) VALUES (NEW.label); END');
    $db->exec('CREATE TRIGGER plugin_trigger_cycle_beta_insert AFTER INSERT ON plugin_trigger_cycle_beta BEGIN INSERT INTO plugin_trigger_cycle_alpha (label) VALUES (NEW.label); END');
    $db->exec('CREATE TRIGGER plugin_trigger_cycle_self_insert AFTER INSERT ON plugin_trigger_cycle_self BEGIN UPDATE plugin_trigger_cycle_self SET label = NEW.label WHERE rowid = NEW.rowid; END');
    $db->close();
    $source_added_trigger_cycle_result = cow_merge_databases(
        $source_added_trigger_cycle_base,
        $source_added_trigger_cycle_source,
        $source_added_trigger_cycle_target,
        $source_added_trigger_cycle_metadata,
        'feature-source-trigger-cycle',
        'main'
    );
    assert_same($source_added_trigger_cycle_result['status'], 'completed_with_conflicts', 'source-added cyclic trigger programs are held as reviewable schema conflicts');
    assert_same((int)scalar($source_added_trigger_cycle_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'plugin_trigger_cycle_%'"), 0, 'source-added cyclic trigger programs are not installed on target');
    foreach ([
        'plugin_trigger_cycle_alpha_insert' => 'plugin_trigger_cycle_alpha_insert -> plugin_trigger_cycle_beta_insert -> plugin_trigger_cycle_alpha_insert',
        'plugin_trigger_cycle_beta_insert' => 'plugin_trigger_cycle_alpha_insert -> plugin_trigger_cycle_beta_insert -> plugin_trigger_cycle_alpha_insert',
        'plugin_trigger_cycle_self_insert' => 'plugin_trigger_cycle_self_insert -> plugin_trigger_cycle_self_insert',
    ] as $trigger_name => $expected_cycle) {
        $conflict_id = (int)scalar($source_added_trigger_cycle_metadata, "SELECT id FROM merge_conflicts WHERE column_name = '$trigger_name' AND conflict_type = 'schema-source-added-trigger' ORDER BY id DESC LIMIT 1");
        assert_true($conflict_id > 0, "$trigger_name records a source-added cyclic trigger conflict");
        $payload = cow_merge_decode_payload_json(
            (string)scalar($source_added_trigger_cycle_metadata, "SELECT source_payload FROM merge_conflicts WHERE id = $conflict_id"),
            "$trigger_name cyclic trigger payload"
        );
        assert_true(
            str_contains((string)($payload['error'] ?? ''), 'unsupported cyclic trigger dependencies') &&
                str_contains((string)($payload['error'] ?? ''), $expected_cycle),
            "$trigger_name cyclic trigger conflict payload records the unsupported dependency cycle"
        );
        assert_throws(
            fn() => cow_merge_resolve_conflict(
                $source_added_trigger_cycle_metadata,
                $conflict_id,
                'source',
                true,
                "Try cyclic trigger $trigger_name.",
                'test'
            ),
            'unsupported cyclic trigger dependencies',
            "$trigger_name source resolution remains validation-gated"
        );
    }
    assert_same(
        (int)scalar($source_added_trigger_cycle_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id IN (SELECT id FROM merge_conflicts WHERE conflict_type = 'schema-source-added-trigger')"),
        0,
        'failed cyclic trigger resolution attempts do not record resolutions'
    );

    $source_added_trigger_target_cycle_base = $tmp . '/source-added-trigger-target-cycle-base.sqlite';
    $source_added_trigger_target_cycle_source = $tmp . '/source-added-trigger-target-cycle-source.sqlite';
    $source_added_trigger_target_cycle_target = $tmp . '/source-added-trigger-target-cycle-target.sqlite';
    $source_added_trigger_target_cycle_metadata = $tmp . '/.forkpress/cow/merge/source-added-trigger-target-cycle-metadata.sqlite';
    create_base_db($source_added_trigger_target_cycle_base);
    $db = open_db($source_added_trigger_target_cycle_base);
    $db->exec('CREATE TABLE plugin_trigger_target_cycle_alpha (label TEXT DEFAULT "alpha")');
    $db->exec('CREATE TABLE plugin_trigger_target_cycle_beta (label TEXT DEFAULT "beta")');
    $db->close();
    copy($source_added_trigger_target_cycle_base, $source_added_trigger_target_cycle_source);
    copy($source_added_trigger_target_cycle_base, $source_added_trigger_target_cycle_target);
    $db = open_db($source_added_trigger_target_cycle_source);
    $db->exec('CREATE TRIGGER plugin_trigger_target_cycle_alpha_insert AFTER INSERT ON plugin_trigger_target_cycle_alpha BEGIN INSERT INTO plugin_trigger_target_cycle_beta (label) VALUES (NEW.label); END');
    $db->close();
    $db = open_db($source_added_trigger_target_cycle_target);
    $db->exec('CREATE TRIGGER plugin_trigger_target_cycle_beta_insert AFTER INSERT ON plugin_trigger_target_cycle_beta BEGIN INSERT INTO plugin_trigger_target_cycle_alpha (label) VALUES (NEW.label); END');
    $db->close();
    $source_added_trigger_target_cycle_result = cow_merge_databases(
        $source_added_trigger_target_cycle_base,
        $source_added_trigger_target_cycle_source,
        $source_added_trigger_target_cycle_target,
        $source_added_trigger_target_cycle_metadata,
        'feature-source-trigger-target-cycle',
        'main'
    );
    assert_same($source_added_trigger_target_cycle_result['status'], 'completed_with_conflicts', 'source-added trigger cycles with target-side trigger programs are reviewable');
    assert_same((int)scalar($source_added_trigger_target_cycle_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_trigger_target_cycle_alpha_insert'"), 0, 'source-added trigger is not installed when it cycles with a target trigger');
    assert_same((int)scalar($source_added_trigger_target_cycle_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_trigger_target_cycle_beta_insert'"), 1, 'target-side trigger remains preserved by default');
    $source_added_trigger_target_cycle_conflict_id = (int)scalar($source_added_trigger_target_cycle_metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_trigger_target_cycle_alpha_insert' AND conflict_type = 'schema-source-added-trigger' ORDER BY id DESC LIMIT 1");
    assert_true($source_added_trigger_target_cycle_conflict_id > 0, 'source-added target-trigger cycle records a schema conflict');
    $source_added_trigger_target_cycle_payload = cow_merge_decode_payload_json(
        (string)scalar($source_added_trigger_target_cycle_metadata, "SELECT source_payload FROM merge_conflicts WHERE id = $source_added_trigger_target_cycle_conflict_id"),
        'source-added target trigger cycle payload'
    );
    assert_true(
        str_contains((string)($source_added_trigger_target_cycle_payload['error'] ?? ''), 'unsupported cyclic trigger dependencies') &&
            str_contains((string)($source_added_trigger_target_cycle_payload['error'] ?? ''), 'plugin_trigger_target_cycle_alpha_insert') &&
            str_contains((string)($source_added_trigger_target_cycle_payload['error'] ?? ''), 'plugin_trigger_target_cycle_beta_insert'),
        'source-added target-trigger cycle payload records both trigger names'
    );
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $source_added_trigger_target_cycle_metadata,
            $source_added_trigger_target_cycle_conflict_id,
            'source',
            true,
            'Try source trigger that cycles with target trigger.',
            'test'
        ),
        'unsupported cyclic trigger dependencies',
        'source-added target-trigger cycle resolution remains validation-gated'
    );

    $schema_changed_trigger_target_cycle_base = $tmp . '/schema-changed-trigger-target-cycle-base.sqlite';
    $schema_changed_trigger_target_cycle_source = $tmp . '/schema-changed-trigger-target-cycle-source.sqlite';
    $schema_changed_trigger_target_cycle_target = $tmp . '/schema-changed-trigger-target-cycle-target.sqlite';
    $schema_changed_trigger_target_cycle_metadata = $tmp . '/.forkpress/cow/merge/schema-changed-trigger-target-cycle-metadata.sqlite';
    create_base_db($schema_changed_trigger_target_cycle_base);
    $db = open_db($schema_changed_trigger_target_cycle_base);
    $db->exec('CREATE TABLE plugin_trigger_changed_cycle_alpha (label TEXT DEFAULT "alpha")');
    $db->exec('CREATE TABLE plugin_trigger_changed_cycle_beta (label TEXT DEFAULT "beta")');
    $db->exec('CREATE TRIGGER plugin_trigger_changed_cycle_alpha_insert AFTER INSERT ON plugin_trigger_changed_cycle_alpha BEGIN SELECT NEW.label; END');
    $db->close();
    copy($schema_changed_trigger_target_cycle_base, $schema_changed_trigger_target_cycle_source);
    copy($schema_changed_trigger_target_cycle_base, $schema_changed_trigger_target_cycle_target);
    $db = open_db($schema_changed_trigger_target_cycle_source);
    $db->exec('DROP TRIGGER plugin_trigger_changed_cycle_alpha_insert');
    $db->exec('CREATE TRIGGER plugin_trigger_changed_cycle_alpha_insert AFTER INSERT ON plugin_trigger_changed_cycle_alpha BEGIN INSERT INTO plugin_trigger_changed_cycle_beta (label) VALUES (NEW.label); END');
    $db->close();
    $db = open_db($schema_changed_trigger_target_cycle_target);
    $db->exec('CREATE TRIGGER plugin_trigger_changed_cycle_beta_insert AFTER INSERT ON plugin_trigger_changed_cycle_beta BEGIN INSERT INTO plugin_trigger_changed_cycle_alpha (label) VALUES (NEW.label); END');
    $db->close();
    $schema_changed_trigger_target_cycle_result = cow_merge_databases(
        $schema_changed_trigger_target_cycle_base,
        $schema_changed_trigger_target_cycle_source,
        $schema_changed_trigger_target_cycle_target,
        $schema_changed_trigger_target_cycle_metadata,
        'feature-changed-trigger-target-cycle',
        'main'
    );
    assert_same($schema_changed_trigger_target_cycle_result['status'], 'completed_with_conflicts', 'source-changed trigger with a target-side trigger cycle remains reviewable');
    $schema_changed_trigger_target_cycle_conflict_id = (int)scalar($schema_changed_trigger_target_cycle_metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_trigger_changed_cycle_alpha_insert' AND conflict_type = 'schema-source-changed-trigger' ORDER BY id DESC LIMIT 1");
    assert_true($schema_changed_trigger_target_cycle_conflict_id > 0, 'source-changed target-trigger cycle records a schema conflict');
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $schema_changed_trigger_target_cycle_metadata,
            $schema_changed_trigger_target_cycle_conflict_id,
            'source',
            false,
            'Preview changed trigger that cycles with target trigger.',
            'test'
        ),
        'unsupported cyclic trigger dependencies',
        'source-changed trigger resolution dry-run remains gated by target trigger cycle validation'
    );
    assert_same(
        (int)scalar($schema_changed_trigger_target_cycle_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_changed_trigger_target_cycle_conflict_id"),
        0,
        'failed changed-trigger cycle dry-run does not record a resolution'
    );
    assert_true(
        str_contains((string)scalar($schema_changed_trigger_target_cycle_target, "SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_trigger_changed_cycle_alpha_insert'"), 'SELECT NEW.label'),
        'failed changed-trigger cycle dry-run rolls back the target trigger rewrite'
    );

    $schema_changed_trigger_dependency_cycle_base = $tmp . '/schema-changed-trigger-dependency-cycle-base.sqlite';
    $schema_changed_trigger_dependency_cycle_source = $tmp . '/schema-changed-trigger-dependency-cycle-source.sqlite';
    $schema_changed_trigger_dependency_cycle_target = $tmp . '/schema-changed-trigger-dependency-cycle-target.sqlite';
    $schema_changed_trigger_dependency_cycle_metadata = $tmp . '/.forkpress/cow/merge/schema-changed-trigger-dependency-cycle-metadata.sqlite';
    create_base_db($schema_changed_trigger_dependency_cycle_base);
    $db = open_db($schema_changed_trigger_dependency_cycle_base);
    $db->exec('CREATE TABLE plugin_trigger_dependency_cycle_alpha (label TEXT DEFAULT "alpha")');
    $db->exec('CREATE TABLE plugin_trigger_dependency_cycle_beta (label TEXT DEFAULT "beta")');
    $db->exec('CREATE TRIGGER plugin_trigger_dependency_cycle_alpha_insert AFTER INSERT ON plugin_trigger_dependency_cycle_alpha BEGIN SELECT NEW.label; END');
    $db->exec('CREATE TRIGGER plugin_trigger_dependency_cycle_beta_insert AFTER INSERT ON plugin_trigger_dependency_cycle_beta BEGIN INSERT INTO plugin_trigger_dependency_cycle_alpha (label) VALUES (NEW.label); END');
    $db->close();
    copy($schema_changed_trigger_dependency_cycle_base, $schema_changed_trigger_dependency_cycle_source);
    copy($schema_changed_trigger_dependency_cycle_base, $schema_changed_trigger_dependency_cycle_target);
    $db = open_db($schema_changed_trigger_dependency_cycle_source);
    $db->exec('DROP TRIGGER plugin_trigger_dependency_cycle_alpha_insert');
    $db->exec('CREATE TRIGGER plugin_trigger_dependency_cycle_alpha_insert AFTER INSERT ON plugin_trigger_dependency_cycle_alpha BEGIN INSERT INTO plugin_trigger_dependency_cycle_beta (label) VALUES (NEW.label); END');
    $db->exec('DROP TRIGGER plugin_trigger_dependency_cycle_beta_insert');
    $db->close();
    $schema_changed_trigger_dependency_cycle_result = cow_merge_databases(
        $schema_changed_trigger_dependency_cycle_base,
        $schema_changed_trigger_dependency_cycle_source,
        $schema_changed_trigger_dependency_cycle_target,
        $schema_changed_trigger_dependency_cycle_metadata,
        'feature-changed-trigger-dependency-cycle',
        'main'
    );
    assert_same($schema_changed_trigger_dependency_cycle_result['status'], 'completed_with_conflicts', 'source-changed trigger with a source-dropped target trigger dependency remains reviewable');
    $schema_changed_trigger_dependency_cycle_rewrite_conflict_id = (int)scalar($schema_changed_trigger_dependency_cycle_metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_trigger_dependency_cycle_alpha_insert' AND conflict_type = 'schema-source-changed-trigger' ORDER BY id DESC LIMIT 1");
    $schema_changed_trigger_dependency_cycle_drop_conflict_id = (int)scalar($schema_changed_trigger_dependency_cycle_metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_trigger_dependency_cycle_beta_insert' AND conflict_type = 'schema-source-dropped-trigger' ORDER BY id DESC LIMIT 1");
    assert_true($schema_changed_trigger_dependency_cycle_rewrite_conflict_id > 0, 'source-changed trigger dependency cycle records a rewrite conflict');
    assert_true($schema_changed_trigger_dependency_cycle_drop_conflict_id > 0, 'source-dropped target trigger dependency records a drop conflict');
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $schema_changed_trigger_dependency_cycle_metadata,
            $schema_changed_trigger_dependency_cycle_rewrite_conflict_id,
            'source',
            false,
            'Preview changed trigger before dropping cyclic dependency.',
            'test'
        ),
        'unsupported cyclic trigger dependencies',
        'source-changed trigger rewrite remains gated until the source-dropped trigger dependency is resolved'
    );
    assert_same(
        (int)scalar($schema_changed_trigger_dependency_cycle_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_changed_trigger_dependency_cycle_rewrite_conflict_id"),
        0,
        'failed changed-trigger dependency cycle preview does not record a resolution'
    );
    assert_true(
        str_contains((string)scalar($schema_changed_trigger_dependency_cycle_target, "SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_trigger_dependency_cycle_alpha_insert'"), 'SELECT NEW.label'),
        'failed changed-trigger dependency cycle preview rolls back the trigger rewrite'
    );
    $schema_changed_trigger_dependency_cycle_drop_resolution = cow_merge_resolve_conflict(
        $schema_changed_trigger_dependency_cycle_metadata,
        $schema_changed_trigger_dependency_cycle_drop_conflict_id,
        'source',
        true,
        'Drop source-dropped trigger before changed trigger rewrite.',
        'test'
    );
    assert_same($schema_changed_trigger_dependency_cycle_drop_resolution['status'], 'applied', 'source-dropped cyclic trigger dependency applies before changed trigger rewrite');
    $schema_changed_trigger_dependency_cycle_rewrite_resolution = cow_merge_resolve_conflict(
        $schema_changed_trigger_dependency_cycle_metadata,
        $schema_changed_trigger_dependency_cycle_rewrite_conflict_id,
        'source',
        true,
        'Apply changed trigger rewrite after dropping cyclic dependency.',
        'test'
    );
    assert_same($schema_changed_trigger_dependency_cycle_rewrite_resolution['status'], 'applied', 'changed trigger rewrite applies after cyclic trigger dependency is resolved');
    assert_true(
        str_contains((string)scalar($schema_changed_trigger_dependency_cycle_target, "SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_trigger_dependency_cycle_alpha_insert'"), 'plugin_trigger_dependency_cycle_beta'),
        'resolved changed trigger keeps the audited source rewrite'
    );
    assert_same(
        (int)scalar($schema_changed_trigger_dependency_cycle_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_trigger_dependency_cycle_beta_insert'"),
        0,
        'resolved source-dropped trigger dependency remains absent'
    );
    $schema_changed_trigger_dependency_cycle_rerun = cow_merge_databases(
        $schema_changed_trigger_dependency_cycle_base,
        $schema_changed_trigger_dependency_cycle_source,
        $schema_changed_trigger_dependency_cycle_target,
        $schema_changed_trigger_dependency_cycle_metadata,
        'feature-changed-trigger-dependency-cycle',
        'main'
    );
    assert_same($schema_changed_trigger_dependency_cycle_rerun['status'], 'completed', 'rerunning after changed trigger dependency cycle resolution completes without new conflicts');
    assert_same(
        (int)scalar($schema_changed_trigger_dependency_cycle_metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE r.source_branch = 'feature-changed-trigger-dependency-cycle'"),
        2,
        'rerunning after changed trigger dependency cycle resolution does not rediscover resolved conflicts'
    );

    $schema_restore_trigger_target_cycle_base = $tmp . '/schema-restore-trigger-target-cycle-base.sqlite';
    $schema_restore_trigger_target_cycle_source = $tmp . '/schema-restore-trigger-target-cycle-source.sqlite';
    $schema_restore_trigger_target_cycle_target = $tmp . '/schema-restore-trigger-target-cycle-target.sqlite';
    $schema_restore_trigger_target_cycle_metadata = $tmp . '/.forkpress/cow/merge/schema-restore-trigger-target-cycle-metadata.sqlite';
    create_base_db($schema_restore_trigger_target_cycle_base);
    $db = open_db($schema_restore_trigger_target_cycle_base);
    $db->exec('CREATE TABLE plugin_trigger_restore_cycle_alpha (label TEXT DEFAULT "alpha")');
    $db->exec('CREATE TABLE plugin_trigger_restore_cycle_beta (label TEXT DEFAULT "beta")');
    $db->exec('CREATE TRIGGER plugin_trigger_restore_cycle_alpha_insert AFTER INSERT ON plugin_trigger_restore_cycle_alpha BEGIN INSERT INTO plugin_trigger_restore_cycle_beta (label) VALUES (NEW.label); END');
    $db->exec('CREATE TRIGGER plugin_trigger_restore_cycle_beta_insert AFTER INSERT ON plugin_trigger_restore_cycle_beta BEGIN INSERT INTO plugin_trigger_restore_cycle_alpha (label) VALUES (NEW.label); END');
    $db->close();
    copy($schema_restore_trigger_target_cycle_base, $schema_restore_trigger_target_cycle_source);
    copy($schema_restore_trigger_target_cycle_base, $schema_restore_trigger_target_cycle_target);
    $db = open_db($schema_restore_trigger_target_cycle_source);
    $db->exec('DROP TRIGGER plugin_trigger_restore_cycle_beta_insert');
    $db->close();
    $db = open_db($schema_restore_trigger_target_cycle_target);
    $db->exec('DROP TABLE plugin_trigger_restore_cycle_alpha');
    $db->close();
    $schema_restore_trigger_target_cycle_result = cow_merge_databases(
        $schema_restore_trigger_target_cycle_base,
        $schema_restore_trigger_target_cycle_source,
        $schema_restore_trigger_target_cycle_target,
        $schema_restore_trigger_target_cycle_metadata,
        'feature-restore-trigger-target-cycle',
        'main'
    );
    assert_same($schema_restore_trigger_target_cycle_result['status'], 'completed_with_conflicts', 'table restore trigger cycle with target-side trigger remains reviewable');
    $schema_restore_trigger_target_cycle_table_conflict_id = (int)scalar($schema_restore_trigger_target_cycle_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_trigger_restore_cycle_alpha' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    $schema_restore_trigger_target_cycle_trigger_conflict_id = (int)scalar($schema_restore_trigger_target_cycle_metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_trigger_restore_cycle_beta_insert' AND conflict_type = 'schema-source-dropped-trigger' ORDER BY id DESC LIMIT 1");
    assert_true($schema_restore_trigger_target_cycle_table_conflict_id > 0, 'target-dropped table conflict is recorded before trigger-cycle restore');
    assert_true($schema_restore_trigger_target_cycle_trigger_conflict_id > 0, 'source-dropped target trigger conflict is recorded before trigger-cycle restore');
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $schema_restore_trigger_target_cycle_metadata,
            $schema_restore_trigger_target_cycle_table_conflict_id,
            'source',
            false,
            'Preview table restore before trigger drop.',
            'test'
        ),
        'unsupported cyclic trigger dependencies',
        'table restore dry-run remains gated while restored trigger cycles with target trigger'
    );
    assert_same(
        (int)scalar($schema_restore_trigger_target_cycle_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_restore_trigger_target_cycle_table_conflict_id"),
        0,
        'failed table restore trigger-cycle dry-run does not record a resolution'
    );
    assert_same(
        (int)scalar($schema_restore_trigger_target_cycle_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_trigger_restore_cycle_alpha'"),
        0,
        'failed table restore trigger-cycle dry-run rolls back the restored table'
    );
    $schema_restore_trigger_target_cycle_drop_resolution = cow_merge_resolve_conflict(
        $schema_restore_trigger_target_cycle_metadata,
        $schema_restore_trigger_target_cycle_trigger_conflict_id,
        'source',
        true,
        'Drop source-dropped trigger before table restore.',
        'test'
    );
    assert_same($schema_restore_trigger_target_cycle_drop_resolution['status'], 'applied', 'source-dropped target trigger applies before table restore');
    $schema_restore_trigger_target_cycle_restore_resolution = cow_merge_resolve_conflict(
        $schema_restore_trigger_target_cycle_metadata,
        $schema_restore_trigger_target_cycle_table_conflict_id,
        'source',
        true,
        'Restore table after trigger drop.',
        'test'
    );
    assert_same($schema_restore_trigger_target_cycle_restore_resolution['status'], 'applied', 'table restore applies after dependent target trigger is dropped');
    assert_same((int)scalar($schema_restore_trigger_target_cycle_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_trigger_restore_cycle_alpha_insert'"), 1, 'source table restore installs the restored trigger after cycle dependency is resolved');
    assert_same((int)scalar($schema_restore_trigger_target_cycle_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_trigger_restore_cycle_beta_insert'"), 0, 'source-dropped target trigger stays dropped after table restore');
    $schema_restore_trigger_target_cycle_rerun = cow_merge_databases(
        $schema_restore_trigger_target_cycle_base,
        $schema_restore_trigger_target_cycle_source,
        $schema_restore_trigger_target_cycle_target,
        $schema_restore_trigger_target_cycle_metadata,
        'feature-restore-trigger-target-cycle',
        'main'
    );
    assert_same($schema_restore_trigger_target_cycle_rerun['status'], 'completed', 'rerunning after trigger-cycle table restore completes without rediscovering conflicts');

    $source_added_view_missing_base = $tmp . '/source-added-view-missing-base.sqlite';
    $source_added_view_missing_source = $tmp . '/source-added-view-missing-source.sqlite';
    $source_added_view_missing_target = $tmp . '/source-added-view-missing-target.sqlite';
    $source_added_view_missing_metadata = $tmp . '/.forkpress/cow/merge/source-added-view-missing-metadata.sqlite';
    create_base_db($source_added_view_missing_base);
    $db = open_db($source_added_view_missing_base);
    $db->exec('CREATE TABLE plugin_view_dependency (item_id TEXT PRIMARY KEY, label TEXT)');
    $db->exec("INSERT INTO plugin_view_dependency (item_id, label) VALUES ('base-view-parent', 'Base View Parent')");
    $db->close();
    copy($source_added_view_missing_base, $source_added_view_missing_source);
    copy($source_added_view_missing_base, $source_added_view_missing_target);
    cow_merge_capture_row_identities($source_added_view_missing_base, $source_added_view_missing_metadata, 'main');
    cow_merge_capture_row_identities($source_added_view_missing_source, $source_added_view_missing_metadata, 'feature-source-view-missing', 'main');
    cow_merge_capture_row_identities($source_added_view_missing_target, $source_added_view_missing_metadata, 'main');
    $db = open_db($source_added_view_missing_source);
    $db->exec("CREATE VIEW plugin_view_dependency_source_view AS SELECT item_id, label FROM plugin_view_dependency WHERE label LIKE 'Base%'");
    $db->close();
    $db = open_db($source_added_view_missing_target);
    $db->exec('DROP TABLE plugin_view_dependency');
    $db->close();
    $source_added_view_missing_result = cow_merge_databases(
        $source_added_view_missing_base,
        $source_added_view_missing_source,
        $source_added_view_missing_target,
        $source_added_view_missing_metadata,
        'feature-source-view-missing',
        'main'
    );
    assert_same($source_added_view_missing_result['status'], 'completed_with_conflicts', 'source-added view with a missing restored-table dependency is audited');
    assert_same((int)scalar($source_added_view_missing_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_view_dependency_source_view'"), 0, 'source-added view is held back while its table dependency is missing');
    $source_added_view_table_conflict_id = (int)scalar($source_added_view_missing_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_view_dependency' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    $source_added_view_conflict_id = (int)scalar($source_added_view_missing_metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_view_dependency_source_view' AND conflict_type = 'schema-source-added-view' ORDER BY id DESC LIMIT 1");
    assert_true($source_added_view_table_conflict_id > 0, 'missing view dependency remains a reviewable table restore conflict');
    assert_true($source_added_view_conflict_id > 0, 'missing view dependency records a source-added view schema conflict');
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $source_added_view_missing_metadata,
            $source_added_view_conflict_id,
            'source',
            true,
            'Try view before dependency restore.',
            'test'
        ),
        'source view plugin_view_dependency_source_view references missing target schema objects',
        'source-added view resolution remains gated until its dependency is restored'
    );
    $source_added_view_table_resolution = cow_merge_resolve_conflict(
        $source_added_view_missing_metadata,
        $source_added_view_table_conflict_id,
        'source',
        true,
        'Restore view dependency table before view.',
        'test'
    );
    assert_same($source_added_view_table_resolution['status'], 'applied', 'view dependency table restore applies before view resolution');
    $source_added_view_resolution = cow_merge_resolve_conflict(
        $source_added_view_missing_metadata,
        $source_added_view_conflict_id,
        'source',
        true,
        'Apply view after dependency restore.',
        'test'
    );
    assert_same($source_added_view_resolution['status'], 'applied', 'source-added view resolution applies after its dependency exists');
    assert_same(scalar($source_added_view_missing_target, "SELECT label FROM plugin_view_dependency_source_view WHERE item_id = 'base-view-parent'"), 'Base View Parent', 'reviewed source view is queryable after dependency restore');
    $source_added_view_missing_rerun = cow_merge_databases(
        $source_added_view_missing_base,
        $source_added_view_missing_source,
        $source_added_view_missing_target,
        $source_added_view_missing_metadata,
        'feature-source-view-missing',
        'main'
    );
    assert_same($source_added_view_missing_rerun['status'], 'completed', 'rerunning after source-added view resolution completes without a new conflict');

    $source_added_view_chain_base = $tmp . '/source-added-view-chain-base.sqlite';
    $source_added_view_chain_source = $tmp . '/source-added-view-chain-source.sqlite';
    $source_added_view_chain_target = $tmp . '/source-added-view-chain-target.sqlite';
    $source_added_view_chain_metadata = $tmp . '/.forkpress/cow/merge/source-added-view-chain-metadata.sqlite';
    create_base_db($source_added_view_chain_base);
    $db = open_db($source_added_view_chain_base);
    $db->exec('CREATE TABLE plugin_view_chain_parent (item_id TEXT PRIMARY KEY, label TEXT)');
    $db->exec("INSERT INTO plugin_view_chain_parent (item_id, label) VALUES ('chain-parent', 'Chain Parent')");
    $db->close();
    copy($source_added_view_chain_base, $source_added_view_chain_source);
    copy($source_added_view_chain_base, $source_added_view_chain_target);
    cow_merge_capture_row_identities($source_added_view_chain_base, $source_added_view_chain_metadata, 'main');
    cow_merge_capture_row_identities($source_added_view_chain_source, $source_added_view_chain_metadata, 'feature-source-view-chain', 'main');
    cow_merge_capture_row_identities($source_added_view_chain_target, $source_added_view_chain_metadata, 'main');
    $db = open_db($source_added_view_chain_source);
    $db->exec('CREATE TABLE plugin_view_chain_extra (item_id TEXT PRIMARY KEY, suffix TEXT)');
    $db->exec("INSERT INTO plugin_view_chain_extra (item_id, suffix) VALUES ('chain-parent', ' + source extra')");
    $db->exec('CREATE VIEW plugin_view_chain_child_view AS SELECT item_id, label || suffix AS title FROM plugin_view_chain_parent_view');
    $db->exec('CREATE VIEW plugin_view_chain_parent_view AS SELECT p.item_id, p.label, e.suffix FROM plugin_view_chain_parent p JOIN plugin_view_chain_extra e ON e.item_id = p.item_id');
    $db->close();
    $db = open_db($source_added_view_chain_target);
    $db->exec('DROP TABLE plugin_view_chain_parent');
    $db->close();
    $source_added_view_chain_result = cow_merge_databases(
        $source_added_view_chain_base,
        $source_added_view_chain_source,
        $source_added_view_chain_target,
        $source_added_view_chain_metadata,
        'feature-source-view-chain',
        'main'
    );
    assert_same($source_added_view_chain_result['status'], 'completed_with_conflicts', 'source-added view chain with a restored-table dependency is audited');
    assert_same(scalar($source_added_view_chain_target, "SELECT suffix FROM plugin_view_chain_extra WHERE item_id = 'chain-parent'"), ' + source extra', 'source-only table in the view chain materializes before view resolution');
    assert_same((int)scalar($source_added_view_chain_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name IN ('plugin_view_chain_parent_view', 'plugin_view_chain_child_view')"), 0, 'source-added view chain is held back while restored dependencies are missing');
    $source_added_view_chain_table_conflict_id = (int)scalar($source_added_view_chain_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_view_chain_parent' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    $source_added_view_chain_parent_conflict_id = (int)scalar($source_added_view_chain_metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_view_chain_parent_view' AND conflict_type = 'schema-source-added-view' ORDER BY id DESC LIMIT 1");
    $source_added_view_chain_child_conflict_id = (int)scalar($source_added_view_chain_metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_view_chain_child_view' AND conflict_type = 'schema-source-added-view' ORDER BY id DESC LIMIT 1");
    assert_true($source_added_view_chain_table_conflict_id > 0, 'view chain missing table dependency remains reviewable');
    assert_true($source_added_view_chain_parent_conflict_id > 0, 'view chain parent view records a source-added view conflict');
    assert_true($source_added_view_chain_child_conflict_id > 0, 'view chain child view records a source-added view conflict');
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $source_added_view_chain_metadata,
            $source_added_view_chain_child_conflict_id,
            'source',
            true,
            'Try child view before table and parent view dependencies.',
            'test'
        ),
        'source view plugin_view_chain_child_view references missing target schema objects',
        'source-added child view resolution remains gated before dependencies exist'
    );
    $source_added_view_chain_table_resolution = cow_merge_resolve_conflict(
        $source_added_view_chain_metadata,
        $source_added_view_chain_table_conflict_id,
        'source',
        true,
        'Restore source table before view chain.',
        'test'
    );
    assert_same($source_added_view_chain_table_resolution['status'], 'applied', 'view chain table dependency restore applies first');
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $source_added_view_chain_metadata,
            $source_added_view_chain_child_conflict_id,
            'source',
            true,
            'Try child view before parent view dependency.',
            'test'
        ),
        'source view plugin_view_chain_child_view references missing target schema objects',
        'source-added child view resolution remains gated until parent view exists'
    );
    $source_added_view_chain_parent_resolution = cow_merge_resolve_conflict(
        $source_added_view_chain_metadata,
        $source_added_view_chain_parent_conflict_id,
        'source',
        true,
        'Apply parent view after table restore.',
        'test'
    );
    assert_same($source_added_view_chain_parent_resolution['status'], 'applied', 'source-added parent view applies after restored table and source-only table exist');
    $source_added_view_chain_child_resolution = cow_merge_resolve_conflict(
        $source_added_view_chain_metadata,
        $source_added_view_chain_child_conflict_id,
        'source',
        true,
        'Apply child view after parent view.',
        'test'
    );
    assert_same($source_added_view_chain_child_resolution['status'], 'applied', 'source-added child view applies after parent view exists');
    assert_same(scalar($source_added_view_chain_target, "SELECT title FROM plugin_view_chain_child_view WHERE item_id = 'chain-parent'"), 'Chain Parent + source extra', 'reviewed source-added view chain is queryable after dependencies resolve');
    $source_added_view_chain_rerun = cow_merge_databases(
        $source_added_view_chain_base,
        $source_added_view_chain_source,
        $source_added_view_chain_target,
        $source_added_view_chain_metadata,
        'feature-source-view-chain',
        'main'
    );
    assert_same($source_added_view_chain_rerun['status'], 'completed', 'rerunning after source-added view chain resolution completes without a new conflict');
    assert_same(
        (int)scalar($source_added_view_chain_metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE r.source_branch = 'feature-source-view-chain'"),
        3,
        'rerunning after source-added view chain resolution does not rediscover resolved schema conflicts'
    );

    $source_added_trigger_chain_base = $tmp . '/source-added-trigger-chain-base.sqlite';
    $source_added_trigger_chain_source = $tmp . '/source-added-trigger-chain-source.sqlite';
    $source_added_trigger_chain_target = $tmp . '/source-added-trigger-chain-target.sqlite';
    $source_added_trigger_chain_metadata = $tmp . '/.forkpress/cow/merge/source-added-trigger-chain-metadata.sqlite';
    create_base_db($source_added_trigger_chain_base);
    $db = open_db($source_added_trigger_chain_base);
    $db->exec('CREATE TABLE plugin_trigger_chain_parent (item_id TEXT PRIMARY KEY, label TEXT)');
    $db->exec('CREATE TABLE plugin_trigger_chain_audit (title TEXT)');
    $db->exec('CREATE TABLE plugin_trigger_chain_sink (title TEXT)');
    $db->exec('CREATE TRIGGER plugin_trigger_chain_audit_after AFTER INSERT ON plugin_trigger_chain_audit BEGIN INSERT INTO plugin_trigger_chain_sink (title) VALUES (NEW.title || " / chained"); END');
    $db->exec("INSERT INTO plugin_trigger_chain_parent (item_id, label) VALUES ('trigger-chain-parent', 'Trigger Chain Parent')");
    $db->close();
    copy($source_added_trigger_chain_base, $source_added_trigger_chain_source);
    copy($source_added_trigger_chain_base, $source_added_trigger_chain_target);
    cow_merge_capture_row_identities($source_added_trigger_chain_base, $source_added_trigger_chain_metadata, 'main');
    cow_merge_capture_row_identities($source_added_trigger_chain_source, $source_added_trigger_chain_metadata, 'feature-source-trigger-chain', 'main');
    cow_merge_capture_row_identities($source_added_trigger_chain_target, $source_added_trigger_chain_metadata, 'main');
    $db = open_db($source_added_trigger_chain_source);
    $db->exec('CREATE TABLE plugin_trigger_chain_extra (item_id TEXT PRIMARY KEY, suffix TEXT)');
    $db->exec("INSERT INTO plugin_trigger_chain_extra (item_id, suffix) VALUES ('trigger-chain-parent', ' + trigger extra')");
    $db->exec('CREATE VIEW plugin_trigger_chain_parent_view AS SELECT p.item_id, p.label, e.suffix FROM plugin_trigger_chain_parent p JOIN plugin_trigger_chain_extra e ON e.item_id = p.item_id');
    $db->exec('CREATE TRIGGER plugin_trigger_chain_view_insert INSTEAD OF INSERT ON plugin_trigger_chain_parent_view BEGIN INSERT INTO plugin_trigger_chain_audit (title) VALUES (NEW.label || NEW.suffix); END');
    $db->close();
    $db = open_db($source_added_trigger_chain_target);
    $db->exec('DROP TABLE plugin_trigger_chain_parent');
    $db->exec('DROP TABLE plugin_trigger_chain_audit');
    $db->close();
    $source_added_trigger_chain_result = cow_merge_databases(
        $source_added_trigger_chain_base,
        $source_added_trigger_chain_source,
        $source_added_trigger_chain_target,
        $source_added_trigger_chain_metadata,
        'feature-source-trigger-chain',
        'main'
    );
    assert_same($source_added_trigger_chain_result['status'], 'completed_with_conflicts', 'source-added trigger chain with restored dependencies is audited');
    assert_same(scalar($source_added_trigger_chain_target, "SELECT suffix FROM plugin_trigger_chain_extra WHERE item_id = 'trigger-chain-parent'"), ' + trigger extra', 'source-only table in the trigger chain materializes before trigger review');
    assert_same((int)scalar($source_added_trigger_chain_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_trigger_chain_sink'"), 1, 'trigger sink table remains present before trigger review');
    assert_same((int)scalar($source_added_trigger_chain_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_trigger_chain_parent_view'"), 0, 'source-added trigger view is held back while restored dependencies are missing');
    assert_same((int)scalar($source_added_trigger_chain_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name IN ('plugin_trigger_chain_audit_after', 'plugin_trigger_chain_view_insert')"), 0, 'source-added trigger chain is held back while dependencies are missing');
    $source_added_trigger_chain_parent_conflict_id = (int)scalar($source_added_trigger_chain_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_trigger_chain_parent' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    $source_added_trigger_chain_audit_conflict_id = (int)scalar($source_added_trigger_chain_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_trigger_chain_audit' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    $source_added_trigger_chain_view_conflict_id = (int)scalar($source_added_trigger_chain_metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_trigger_chain_parent_view' AND conflict_type = 'schema-source-added-view' ORDER BY id DESC LIMIT 1");
    $source_added_trigger_chain_view_trigger_conflict_id = (int)scalar($source_added_trigger_chain_metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_trigger_chain_view_insert' AND conflict_type = 'schema-source-added-trigger' ORDER BY id DESC LIMIT 1");
    assert_true($source_added_trigger_chain_parent_conflict_id > 0, 'trigger chain missing parent table remains reviewable');
    assert_true($source_added_trigger_chain_audit_conflict_id > 0, 'trigger chain missing audit table remains reviewable');
    assert_true($source_added_trigger_chain_view_conflict_id > 0, 'trigger chain parent view records a source-added view conflict');
    assert_true($source_added_trigger_chain_view_trigger_conflict_id > 0, 'trigger chain view trigger records a source-added trigger conflict');
    $source_added_trigger_chain_trigger_payload = cow_merge_decode_payload_json(
        (string)scalar($source_added_trigger_chain_metadata, "SELECT source_payload FROM merge_conflicts WHERE id = $source_added_trigger_chain_view_trigger_conflict_id"),
        'source trigger chain payload'
    );
    assert_true(
        is_array($source_added_trigger_chain_trigger_payload)
            && str_contains((string)($source_added_trigger_chain_trigger_payload['error'] ?? ''), 'plugin_trigger_chain_parent_view'),
        'source-added view trigger conflict payload records the missing trigger subject dependency'
    );
    assert_same((int)scalar($source_added_trigger_chain_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE column_name = 'plugin_trigger_chain_audit_after' AND conflict_type = 'schema-source-added-trigger'"), 0, 'restored table trigger is carried by the table restore instead of a separate source-added trigger conflict');
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $source_added_trigger_chain_metadata,
            $source_added_trigger_chain_view_trigger_conflict_id,
            'source',
            true,
            'Try view trigger before table and view dependencies.',
            'test'
        ),
        'source trigger plugin_trigger_chain_view_insert references missing target schema objects',
        'source-added view trigger resolution remains gated before restored audit table exists'
    );
    $source_added_trigger_chain_parent_resolution = cow_merge_resolve_conflict(
        $source_added_trigger_chain_metadata,
        $source_added_trigger_chain_parent_conflict_id,
        'source',
        true,
        'Restore trigger-chain parent table before view.',
        'test'
    );
    assert_same($source_added_trigger_chain_parent_resolution['status'], 'applied', 'trigger chain parent table restore applies first');
    $source_added_trigger_chain_audit_resolution = cow_merge_resolve_conflict(
        $source_added_trigger_chain_metadata,
        $source_added_trigger_chain_audit_conflict_id,
        'source',
        true,
        'Restore trigger-chain audit table before triggers.',
        'test'
    );
    assert_same($source_added_trigger_chain_audit_resolution['status'], 'applied', 'trigger chain audit table restore applies before triggers');
    assert_same((int)scalar($source_added_trigger_chain_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_trigger_chain_audit_after'"), 1, 'trigger chain audit table restore brings back its existing trigger');
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $source_added_trigger_chain_metadata,
            $source_added_trigger_chain_view_trigger_conflict_id,
            'source',
            true,
            'Try view trigger before its view dependency.',
            'test'
        ),
        'source trigger plugin_trigger_chain_view_insert references missing target schema objects: plugin_trigger_chain_parent_view',
        'source-added view trigger resolution remains gated until source-added view exists'
    );
    $source_added_trigger_chain_view_resolution = cow_merge_resolve_conflict(
        $source_added_trigger_chain_metadata,
        $source_added_trigger_chain_view_conflict_id,
        'source',
        true,
        'Apply trigger-chain view after table restore.',
        'test'
    );
    assert_same($source_added_trigger_chain_view_resolution['status'], 'applied', 'trigger chain source-added view applies after restored table and source-only table exist');
    $source_added_trigger_chain_view_trigger_resolution = cow_merge_resolve_conflict(
        $source_added_trigger_chain_metadata,
        $source_added_trigger_chain_view_trigger_conflict_id,
        'source',
        true,
        'Apply view trigger after source-added view exists.',
        'test'
    );
    assert_same($source_added_trigger_chain_view_trigger_resolution['status'], 'applied', 'source-added view trigger applies after source-added view exists');
    $db = open_db($source_added_trigger_chain_target);
    $db->exec("INSERT INTO plugin_trigger_chain_parent_view (item_id, label, suffix) VALUES ('trigger-chain-parent', 'Trigger Chain Parent', ' + trigger extra')");
    $db->close();
    assert_same(scalar($source_added_trigger_chain_target, "SELECT title FROM plugin_trigger_chain_audit WHERE title = 'Trigger Chain Parent + trigger extra'"), 'Trigger Chain Parent + trigger extra', 'reviewed source-added view trigger writes to restored audit table');
    assert_same(scalar($source_added_trigger_chain_target, "SELECT title FROM plugin_trigger_chain_sink WHERE title = 'Trigger Chain Parent + trigger extra / chained'"), 'Trigger Chain Parent + trigger extra / chained', 'reviewed source-added trigger chain reaches source-only sink table');
    $source_added_trigger_chain_rerun = cow_merge_databases(
        $source_added_trigger_chain_base,
        $source_added_trigger_chain_source,
        $source_added_trigger_chain_target,
        $source_added_trigger_chain_metadata,
        'feature-source-trigger-chain',
        'main'
    );
    assert_same($source_added_trigger_chain_rerun['status'], 'completed', 'rerunning after source-added trigger chain resolution completes without a new conflict');
    assert_same(
        (int)scalar($source_added_trigger_chain_metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE r.source_branch = 'feature-source-trigger-chain'"),
        4,
        'rerunning after source-added trigger chain resolution does not rediscover resolved schema conflicts'
    );

    $schema_restore_deferred_objects_base = $tmp . '/schema-restore-deferred-objects-base.sqlite';
    $schema_restore_deferred_objects_source = $tmp . '/schema-restore-deferred-objects-source.sqlite';
    $schema_restore_deferred_objects_target = $tmp . '/schema-restore-deferred-objects-target.sqlite';
    $schema_restore_deferred_objects_metadata = $tmp . '/.forkpress/cow/merge/schema-restore-deferred-objects-metadata.sqlite';
    create_base_db($schema_restore_deferred_objects_base);
    $db = open_db($schema_restore_deferred_objects_base);
    $db->exec('CREATE TABLE plugin_restore_deferred_objects (item_id TEXT PRIMARY KEY, label TEXT)');
    $db->exec('CREATE TABLE plugin_restore_deferred_audit (item_id TEXT, label TEXT)');
    $db->close();
    copy($schema_restore_deferred_objects_base, $schema_restore_deferred_objects_source);
    copy($schema_restore_deferred_objects_base, $schema_restore_deferred_objects_target);
    $db = open_db($schema_restore_deferred_objects_source);
    $db->exec("INSERT INTO plugin_restore_deferred_objects (item_id, label) VALUES ('deferred-alpha', 'Deferred Alpha')");
    $db->exec('CREATE UNIQUE INDEX plugin_restore_deferred_objects_label_idx ON plugin_restore_deferred_objects(label)');
    $db->exec('CREATE VIEW plugin_restore_deferred_objects_live AS SELECT item_id, label FROM plugin_restore_deferred_objects');
    $db->exec(<<<'SQL'
CREATE TRIGGER plugin_restore_deferred_objects_insert
AFTER INSERT ON plugin_restore_deferred_objects
BEGIN
    INSERT INTO plugin_restore_deferred_audit (item_id, label)
    SELECT item_id, label FROM plugin_restore_deferred_objects_live WHERE item_id = NEW.item_id;
END
SQL);
    $db->close();
    $db = open_db($schema_restore_deferred_objects_target);
    $db->exec('DROP TABLE plugin_restore_deferred_objects');
    $db->close();
    $schema_restore_deferred_objects_result = cow_merge_databases(
        $schema_restore_deferred_objects_base,
        $schema_restore_deferred_objects_source,
        $schema_restore_deferred_objects_target,
        $schema_restore_deferred_objects_metadata,
        'feature-restore-deferred-objects',
        'main'
    );
    assert_same($schema_restore_deferred_objects_result['status'], 'completed_with_conflicts', 'target-dropped table with source-added dependent objects remains reviewable');
    $schema_restore_deferred_table_conflict_id = (int)scalar($schema_restore_deferred_objects_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_restore_deferred_objects' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    $schema_restore_deferred_index_conflict_id = (int)scalar($schema_restore_deferred_objects_metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_restore_deferred_objects_label_idx' AND conflict_type = 'schema-source-added-index' ORDER BY id DESC LIMIT 1");
    $schema_restore_deferred_view_conflict_id = (int)scalar($schema_restore_deferred_objects_metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_restore_deferred_objects_live' AND conflict_type = 'schema-source-added-view' ORDER BY id DESC LIMIT 1");
    $schema_restore_deferred_trigger_conflict_id = (int)scalar($schema_restore_deferred_objects_metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_restore_deferred_objects_insert' AND conflict_type = 'schema-source-added-trigger' ORDER BY id DESC LIMIT 1");
    assert_true($schema_restore_deferred_table_conflict_id > 0, 'deferred restore table conflict is auditable');
    assert_true($schema_restore_deferred_index_conflict_id > 0, 'deferred restore source-added index conflict is auditable separately');
    assert_true($schema_restore_deferred_view_conflict_id > 0, 'deferred restore source-added view conflict is auditable separately');
    assert_true($schema_restore_deferred_trigger_conflict_id > 0, 'deferred restore source-added trigger conflict is auditable separately');
    $schema_restore_deferred_table_resolution = cow_merge_resolve_conflict(
        $schema_restore_deferred_objects_metadata,
        $schema_restore_deferred_table_conflict_id,
        'source',
        true,
        'Restore table while deferring standalone source-added objects.',
        'test'
    );
    assert_same($schema_restore_deferred_table_resolution['status'], 'applied', 'target-dropped table restore applies before standalone source-added objects');
    assert_same((int)scalar($schema_restore_deferred_objects_target, "SELECT COUNT(*) FROM plugin_restore_deferred_objects WHERE item_id = 'deferred-alpha'"), 1, 'deferred restore table rows materialize first');
    assert_same((int)scalar($schema_restore_deferred_objects_target, "SELECT COUNT(*) FROM sqlite_master WHERE name = 'plugin_restore_deferred_objects_label_idx'"), 0, 'deferred restore leaves standalone source-added index to its own conflict');
    assert_same((int)scalar($schema_restore_deferred_objects_target, "SELECT COUNT(*) FROM sqlite_master WHERE name = 'plugin_restore_deferred_objects_insert'"), 0, 'deferred restore leaves standalone source-added trigger to its own conflict');
    $schema_restore_deferred_table_payload = cow_merge_decode_payload_json(
        (string)scalar($schema_restore_deferred_objects_metadata, "SELECT resolved_payload FROM merge_resolutions WHERE conflict_id = $schema_restore_deferred_table_conflict_id ORDER BY id DESC LIMIT 1"),
        'target-dropped table deferred object resolution'
    );
    assert_same(count($schema_restore_deferred_table_payload['indexes']), 0, 'deferred table restore resolution payload omits standalone source-added indexes');
    assert_same(count($schema_restore_deferred_table_payload['triggers']), 0, 'deferred table restore resolution payload omits standalone source-added triggers');
    $schema_restore_deferred_index_resolution = cow_merge_resolve_conflict(
        $schema_restore_deferred_objects_metadata,
        $schema_restore_deferred_index_conflict_id,
        'source',
        true,
        'Apply deferred source-added index after table restore.',
        'test'
    );
    assert_same($schema_restore_deferred_index_resolution['status'], 'applied', 'deferred source-added index applies after table restore');
    $schema_restore_deferred_view_resolution = cow_merge_resolve_conflict(
        $schema_restore_deferred_objects_metadata,
        $schema_restore_deferred_view_conflict_id,
        'source',
        true,
        'Apply deferred source-added view after table restore.',
        'test'
    );
    assert_same($schema_restore_deferred_view_resolution['status'], 'applied', 'deferred source-added view applies after table restore');
    $schema_restore_deferred_trigger_resolution = cow_merge_resolve_conflict(
        $schema_restore_deferred_objects_metadata,
        $schema_restore_deferred_trigger_conflict_id,
        'source',
        true,
        'Apply deferred source-added trigger after view restore.',
        'test'
    );
    assert_same($schema_restore_deferred_trigger_resolution['status'], 'applied', 'deferred source-added trigger applies after its view dependency');
    $db = open_db($schema_restore_deferred_objects_target);
    $db->exec("INSERT INTO plugin_restore_deferred_objects (item_id, label) VALUES ('deferred-beta', 'Deferred Beta')");
    $db->close();
    assert_same(scalar($schema_restore_deferred_objects_target, "SELECT label FROM plugin_restore_deferred_audit WHERE item_id = 'deferred-beta'"), 'Deferred Beta', 'deferred restored trigger works after separate source resolution');
    $schema_restore_deferred_objects_rerun = cow_merge_databases(
        $schema_restore_deferred_objects_base,
        $schema_restore_deferred_objects_source,
        $schema_restore_deferred_objects_target,
        $schema_restore_deferred_objects_metadata,
        'feature-restore-deferred-objects',
        'main'
    );
    assert_same($schema_restore_deferred_objects_rerun['status'], 'completed', 'rerunning after deferred restore object resolutions completes without new conflicts');
    assert_same(
        (int)scalar($schema_restore_deferred_objects_metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE r.source_branch = 'feature-restore-deferred-objects'"),
        4,
        'rerunning after deferred restore object resolutions does not rediscover resolved conflicts'
    );

    $schema_restore_view_rollback_base = $tmp . '/schema-restore-view-rollback-base.sqlite';
    $schema_restore_view_rollback_source = $tmp . '/schema-restore-view-rollback-source.sqlite';
    $schema_restore_view_rollback_target = $tmp . '/schema-restore-view-rollback-target.sqlite';
    $schema_restore_view_rollback_metadata = $tmp . '/.forkpress/cow/merge/schema-restore-view-rollback-metadata.sqlite';
    create_base_db($schema_restore_view_rollback_base);
    $db = open_db($schema_restore_view_rollback_base);
    $db->exec('CREATE TABLE plugin_restore_view_parent (code TEXT NOT NULL PRIMARY KEY, label TEXT NOT NULL)');
    $db->exec('CREATE TABLE plugin_restore_view_audit (code TEXT, label TEXT)');
    $db->exec("INSERT INTO plugin_restore_view_parent (code, label) VALUES ('restore-view-parent', 'restore view label')");
    $db->exec('CREATE TRIGGER plugin_restore_view_parent_insert AFTER INSERT ON plugin_restore_view_parent BEGIN INSERT INTO plugin_restore_view_audit (code, label) VALUES (NEW.code, NEW.label); END');
    $db->close();
    copy($schema_restore_view_rollback_base, $schema_restore_view_rollback_source);
    copy($schema_restore_view_rollback_base, $schema_restore_view_rollback_target);

    $db = open_db($schema_restore_view_rollback_source);
    $db->exec('DROP TRIGGER plugin_restore_view_parent_insert');
    $db->exec('CREATE TABLE plugin_restore_view_parent_new (code TEXT NOT NULL PRIMARY KEY, label TEXT NOT NULL) WITHOUT ROWID');
    $db->exec('INSERT INTO plugin_restore_view_parent_new (code, label) SELECT code, label FROM plugin_restore_view_parent');
    $db->exec('DROP TABLE plugin_restore_view_parent');
    $db->exec('ALTER TABLE plugin_restore_view_parent_new RENAME TO plugin_restore_view_parent');
    $db->exec('CREATE TRIGGER plugin_restore_view_parent_insert AFTER INSERT ON plugin_restore_view_parent BEGIN INSERT INTO plugin_restore_view_audit (code, label) VALUES (NEW.code, NEW.label); END');
    $db->close();
    $db = open_db($schema_restore_view_rollback_target);
    $db->exec('DROP TABLE plugin_restore_view_parent');
    $db->exec('CREATE VIEW plugin_restore_view_parent_rowids AS SELECT rowid, code FROM plugin_restore_view_parent');
    $db->close();

    $schema_restore_view_rollback_result = cow_merge_databases(
        $schema_restore_view_rollback_base,
        $schema_restore_view_rollback_source,
        $schema_restore_view_rollback_target,
        $schema_restore_view_rollback_metadata,
        'feature-schema-restore-view-rollback',
        'main'
    );
    assert_same($schema_restore_view_rollback_result['status'], 'completed_with_conflicts', 'target-dropped table restore with a preserved rowid view remains reviewable');
    $schema_restore_view_rollback_conflict_id = (int)scalar($schema_restore_view_rollback_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_restore_view_parent' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    assert_true($schema_restore_view_rollback_conflict_id > 0, 'view-sensitive target-dropped table restore conflict is auditable');
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $schema_restore_view_rollback_metadata,
            $schema_restore_view_rollback_conflict_id,
            'source',
            false,
            'Preview table restore with invalid preserved rowid view.',
            'test'
        ),
        'plugin_restore_view_parent_rowids',
        'dry-run table restore rejects a preserved target view that would become invalid after source schema application'
    );
    assert_same(
        (int)scalar($schema_restore_view_rollback_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_restore_view_rollback_conflict_id"),
        0,
        'failed view-sensitive table restore dry-run does not record resolution metadata'
    );
    assert_same(
        (int)scalar($schema_restore_view_rollback_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_restore_view_parent'"),
        0,
        'failed view-sensitive table restore dry-run rolls back the restored table'
    );
    assert_same(
        (int)scalar($schema_restore_view_rollback_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_restore_view_parent_insert'"),
        0,
        'failed view-sensitive table restore dry-run rolls back the restored source trigger'
    );
    assert_same(
        (int)scalar($schema_restore_view_rollback_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_restore_view_parent_rowids'"),
        1,
        'failed view-sensitive table restore dry-run preserves the target view for review'
    );
    $db = open_db($schema_restore_view_rollback_target);
    $db->exec('DROP VIEW plugin_restore_view_parent_rowids');
    $db->close();
    $schema_restore_view_rollback_apply = cow_merge_resolve_conflict(
        $schema_restore_view_rollback_metadata,
        $schema_restore_view_rollback_conflict_id,
        'source',
        true,
        'Apply table restore after invalid target view is reviewed.',
        'test'
    );
    assert_same($schema_restore_view_rollback_apply['status'], 'applied', 'table restore applies after the invalid preserved view is handled');
    assert_true(str_contains((string)scalar($schema_restore_view_rollback_target, "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'plugin_restore_view_parent'"), 'WITHOUT ROWID'), 'validated view-sensitive table restore applies the audited source WITHOUT ROWID schema');
    assert_same((int)scalar($schema_restore_view_rollback_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_restore_view_parent_insert'"), 1, 'validated view-sensitive table restore installs the restored source trigger');
    $db = open_db($schema_restore_view_rollback_target);
    $db->exec("INSERT INTO plugin_restore_view_parent (code, label) VALUES ('restore-view-trigger-check', 'trigger ok')");
    $db->close();
    assert_same(scalar($schema_restore_view_rollback_target, "SELECT label FROM plugin_restore_view_audit WHERE code = 'restore-view-trigger-check'"), 'trigger ok', 'restored source trigger works after view-sensitive table restore');

    $schema_restore_trigger_body_rollback_base = $tmp . '/schema-restore-trigger-body-rollback-base.sqlite';
    $schema_restore_trigger_body_rollback_source = $tmp . '/schema-restore-trigger-body-rollback-source.sqlite';
    $schema_restore_trigger_body_rollback_target = $tmp . '/schema-restore-trigger-body-rollback-target.sqlite';
    $schema_restore_trigger_body_rollback_metadata = $tmp . '/.forkpress/cow/merge/schema-restore-trigger-body-rollback-metadata.sqlite';
    create_base_db($schema_restore_trigger_body_rollback_base);
    $db = open_db($schema_restore_trigger_body_rollback_base);
    $db->exec('CREATE TABLE plugin_restore_trigger_body_parent (code TEXT NOT NULL PRIMARY KEY, label TEXT NOT NULL)');
    $db->exec('CREATE TABLE plugin_restore_trigger_body_observer (code TEXT)');
    $db->exec('CREATE TABLE plugin_restore_trigger_body_audit (code TEXT, observed TEXT)');
    $db->exec("INSERT INTO plugin_restore_trigger_body_parent (code, label) VALUES ('restore-trigger-body-parent', 'restore trigger body label')");
    $db->close();
    copy($schema_restore_trigger_body_rollback_base, $schema_restore_trigger_body_rollback_source);
    copy($schema_restore_trigger_body_rollback_base, $schema_restore_trigger_body_rollback_target);

    $db = open_db($schema_restore_trigger_body_rollback_source);
    $db->exec('CREATE TABLE plugin_restore_trigger_body_parent_new (code TEXT NOT NULL PRIMARY KEY, label TEXT NOT NULL) WITHOUT ROWID');
    $db->exec('INSERT INTO plugin_restore_trigger_body_parent_new (code, label) SELECT code, label FROM plugin_restore_trigger_body_parent');
    $db->exec('DROP TABLE plugin_restore_trigger_body_parent');
    $db->exec('ALTER TABLE plugin_restore_trigger_body_parent_new RENAME TO plugin_restore_trigger_body_parent');
    $db->close();
    $db = open_db($schema_restore_trigger_body_rollback_target);
    $db->exec('DROP TABLE plugin_restore_trigger_body_parent');
    $db->exec(
        'CREATE TRIGGER plugin_restore_trigger_body_observer_insert AFTER INSERT ON plugin_restore_trigger_body_observer ' .
        'BEGIN INSERT INTO plugin_restore_trigger_body_audit (code, observed) ' .
        'SELECT NEW.code, CAST(rowid AS TEXT) FROM plugin_restore_trigger_body_parent WHERE code = NEW.code; END'
    );
    $db->close();

    $schema_restore_trigger_body_rollback_result = cow_merge_databases(
        $schema_restore_trigger_body_rollback_base,
        $schema_restore_trigger_body_rollback_source,
        $schema_restore_trigger_body_rollback_target,
        $schema_restore_trigger_body_rollback_metadata,
        'feature-schema-restore-trigger-body-rollback',
        'main'
    );
    assert_same($schema_restore_trigger_body_rollback_result['status'], 'completed_with_conflicts', 'target-dropped table restore with a preserved trigger body reference remains reviewable');
    $schema_restore_trigger_body_rollback_conflict_id = (int)scalar($schema_restore_trigger_body_rollback_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_restore_trigger_body_parent' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    assert_true($schema_restore_trigger_body_rollback_conflict_id > 0, 'trigger-body-sensitive target-dropped table restore conflict is auditable');
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $schema_restore_trigger_body_rollback_metadata,
            $schema_restore_trigger_body_rollback_conflict_id,
            'source',
            false,
            'Preview table restore with invalid preserved trigger body.',
            'test'
        ),
        'plugin_restore_trigger_body_observer_insert',
        'dry-run table restore rejects a preserved target trigger body that would become invalid after source schema application'
    );
    assert_same(
        (int)scalar($schema_restore_trigger_body_rollback_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_restore_trigger_body_rollback_conflict_id"),
        0,
        'failed trigger-body-sensitive table restore dry-run does not record resolution metadata'
    );
    assert_same(
        (int)scalar($schema_restore_trigger_body_rollback_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_restore_trigger_body_parent'"),
        0,
        'failed trigger-body-sensitive table restore dry-run rolls back the restored table'
    );
    assert_same(
        (int)scalar($schema_restore_trigger_body_rollback_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_restore_trigger_body_observer_insert'"),
        1,
        'failed trigger-body-sensitive table restore dry-run preserves the target trigger for review'
    );
    $db = open_db($schema_restore_trigger_body_rollback_target);
    $db->exec('DROP TRIGGER plugin_restore_trigger_body_observer_insert');
    $db->close();
    $schema_restore_trigger_body_rollback_apply = cow_merge_resolve_conflict(
        $schema_restore_trigger_body_rollback_metadata,
        $schema_restore_trigger_body_rollback_conflict_id,
        'source',
        true,
        'Apply table restore after invalid target trigger is reviewed.',
        'test'
    );
    assert_same($schema_restore_trigger_body_rollback_apply['status'], 'applied', 'table restore applies after the invalid preserved target trigger is handled');
    assert_true(str_contains((string)scalar($schema_restore_trigger_body_rollback_target, "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'plugin_restore_trigger_body_parent'"), 'WITHOUT ROWID'), 'validated trigger-body-sensitive table restore applies the audited source WITHOUT ROWID schema');

    $schema_restore_view_trigger_rollback_base = $tmp . '/schema-restore-view-trigger-rollback-base.sqlite';
    $schema_restore_view_trigger_rollback_source = $tmp . '/schema-restore-view-trigger-rollback-source.sqlite';
    $schema_restore_view_trigger_rollback_target = $tmp . '/schema-restore-view-trigger-rollback-target.sqlite';
    $schema_restore_view_trigger_rollback_metadata = $tmp . '/.forkpress/cow/merge/schema-restore-view-trigger-rollback-metadata.sqlite';
    create_base_db($schema_restore_view_trigger_rollback_base);
    $db = open_db($schema_restore_view_trigger_rollback_base);
    $db->exec('CREATE TABLE plugin_restore_view_trigger_parent (code TEXT NOT NULL PRIMARY KEY, label TEXT NOT NULL)');
    $db->exec('CREATE TABLE plugin_restore_view_trigger_audit (code TEXT, observed TEXT)');
    $db->exec("INSERT INTO plugin_restore_view_trigger_parent (code, label) VALUES ('restore-view-trigger-parent', 'restore view trigger label')");
    $db->close();
    copy($schema_restore_view_trigger_rollback_base, $schema_restore_view_trigger_rollback_source);
    copy($schema_restore_view_trigger_rollback_base, $schema_restore_view_trigger_rollback_target);

    $db = open_db($schema_restore_view_trigger_rollback_source);
    $db->exec('CREATE TABLE plugin_restore_view_trigger_parent_new (code TEXT NOT NULL PRIMARY KEY, label TEXT NOT NULL) WITHOUT ROWID');
    $db->exec('INSERT INTO plugin_restore_view_trigger_parent_new (code, label) SELECT code, label FROM plugin_restore_view_trigger_parent');
    $db->exec('DROP TABLE plugin_restore_view_trigger_parent');
    $db->exec('ALTER TABLE plugin_restore_view_trigger_parent_new RENAME TO plugin_restore_view_trigger_parent');
    $db->close();
    $db = open_db($schema_restore_view_trigger_rollback_target);
    $db->exec('DROP TABLE plugin_restore_view_trigger_parent');
    $db->exec('CREATE VIEW plugin_restore_view_trigger_live AS SELECT code, label FROM plugin_restore_view_trigger_parent');
    $db->exec(
        'CREATE TRIGGER plugin_restore_view_trigger_live_insert INSTEAD OF INSERT ON plugin_restore_view_trigger_live ' .
        'BEGIN INSERT INTO plugin_restore_view_trigger_audit (code, observed) ' .
        'SELECT NEW.code, CAST(rowid AS TEXT) FROM plugin_restore_view_trigger_parent WHERE code = NEW.code; END'
    );
    $db->close();

    $schema_restore_view_trigger_rollback_result = cow_merge_databases(
        $schema_restore_view_trigger_rollback_base,
        $schema_restore_view_trigger_rollback_source,
        $schema_restore_view_trigger_rollback_target,
        $schema_restore_view_trigger_rollback_metadata,
        'feature-schema-restore-view-trigger-rollback',
        'main'
    );
    assert_same($schema_restore_view_trigger_rollback_result['status'], 'completed_with_conflicts', 'target-dropped table restore with a preserved target view trigger remains reviewable');
    $schema_restore_view_trigger_rollback_conflict_id = (int)scalar($schema_restore_view_trigger_rollback_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_restore_view_trigger_parent' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    assert_true($schema_restore_view_trigger_rollback_conflict_id > 0, 'view-trigger-sensitive target-dropped table restore conflict is auditable');
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $schema_restore_view_trigger_rollback_metadata,
            $schema_restore_view_trigger_rollback_conflict_id,
            'source',
            false,
            'Preview table restore with invalid preserved view trigger.',
            'test'
        ),
        'plugin_restore_view_trigger_live_insert',
        'dry-run table restore rejects a preserved target view trigger that would become invalid after source schema application'
    );
    assert_same(
        (int)scalar($schema_restore_view_trigger_rollback_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_restore_view_trigger_rollback_conflict_id"),
        0,
        'failed view-trigger-sensitive table restore dry-run does not record resolution metadata'
    );
    assert_same(
        (int)scalar($schema_restore_view_trigger_rollback_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_restore_view_trigger_parent'"),
        0,
        'failed view-trigger-sensitive table restore dry-run rolls back the restored table'
    );
    assert_same(
        (int)scalar($schema_restore_view_trigger_rollback_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_restore_view_trigger_live'"),
        1,
        'failed view-trigger-sensitive table restore dry-run preserves the target view for review'
    );
    assert_same(
        (int)scalar($schema_restore_view_trigger_rollback_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_restore_view_trigger_live_insert'"),
        1,
        'failed view-trigger-sensitive table restore dry-run preserves the target view trigger for review'
    );
    $db = open_db($schema_restore_view_trigger_rollback_target);
    $db->exec('DROP TRIGGER plugin_restore_view_trigger_live_insert');
    $db->close();
    $schema_restore_view_trigger_rollback_apply = cow_merge_resolve_conflict(
        $schema_restore_view_trigger_rollback_metadata,
        $schema_restore_view_trigger_rollback_conflict_id,
        'source',
        true,
        'Apply table restore after invalid target view trigger is reviewed.',
        'test'
    );
    assert_same($schema_restore_view_trigger_rollback_apply['status'], 'applied', 'table restore applies after the invalid preserved target view trigger is handled');
    assert_same(scalar($schema_restore_view_trigger_rollback_target, "SELECT label FROM plugin_restore_view_trigger_live WHERE code = 'restore-view-trigger-parent'"), 'restore view trigger label', 'preserved target view remains queryable after view-trigger-sensitive table restore');

    $schema_restore_transitive_view_trigger_base = $tmp . '/schema-restore-transitive-view-trigger-base.sqlite';
    $schema_restore_transitive_view_trigger_source = $tmp . '/schema-restore-transitive-view-trigger-source.sqlite';
    $schema_restore_transitive_view_trigger_target = $tmp . '/schema-restore-transitive-view-trigger-target.sqlite';
    $schema_restore_transitive_view_trigger_metadata = $tmp . '/.forkpress/cow/merge/schema-restore-transitive-view-trigger-metadata.sqlite';
    create_base_db($schema_restore_transitive_view_trigger_base);
    $db = open_db($schema_restore_transitive_view_trigger_base);
    $db->exec('CREATE TABLE plugin_restore_transitive_parent (code TEXT NOT NULL PRIMARY KEY, label TEXT NOT NULL)');
    $db->exec('CREATE TABLE plugin_restore_transitive_audit (code TEXT, observed TEXT)');
    $db->exec("INSERT INTO plugin_restore_transitive_parent (code, label) VALUES ('restore-transitive-parent', 'restore transitive label')");
    $db->close();
    copy($schema_restore_transitive_view_trigger_base, $schema_restore_transitive_view_trigger_source);
    copy($schema_restore_transitive_view_trigger_base, $schema_restore_transitive_view_trigger_target);

    $db = open_db($schema_restore_transitive_view_trigger_source);
    $db->exec('CREATE TABLE plugin_restore_transitive_parent_new (code TEXT NOT NULL PRIMARY KEY, label TEXT NOT NULL) WITHOUT ROWID');
    $db->exec('INSERT INTO plugin_restore_transitive_parent_new (code, label) SELECT code, label FROM plugin_restore_transitive_parent');
    $db->exec('DROP TABLE plugin_restore_transitive_parent');
    $db->exec('ALTER TABLE plugin_restore_transitive_parent_new RENAME TO plugin_restore_transitive_parent');
    $db->close();
    $db = open_db($schema_restore_transitive_view_trigger_target);
    $db->exec('DROP TABLE plugin_restore_transitive_parent');
    $db->exec('CREATE VIEW plugin_restore_transitive_live AS SELECT code, label FROM plugin_restore_transitive_parent');
    $db->exec('CREATE VIEW plugin_restore_transitive_child AS SELECT code, label FROM plugin_restore_transitive_live');
    $db->exec(
        'CREATE TRIGGER plugin_restore_transitive_child_insert INSTEAD OF INSERT ON plugin_restore_transitive_child ' .
        'BEGIN INSERT INTO plugin_restore_transitive_audit (code, observed) ' .
        'SELECT NEW.code, CAST(rowid AS TEXT) FROM plugin_restore_transitive_parent WHERE code = NEW.code; END'
    );
    $db->close();

    $schema_restore_transitive_view_trigger_result = cow_merge_databases(
        $schema_restore_transitive_view_trigger_base,
        $schema_restore_transitive_view_trigger_source,
        $schema_restore_transitive_view_trigger_target,
        $schema_restore_transitive_view_trigger_metadata,
        'feature-schema-restore-transitive-view-trigger',
        'main'
    );
    assert_same($schema_restore_transitive_view_trigger_result['status'], 'completed_with_conflicts', 'target-dropped table restore with a preserved transitive view trigger remains reviewable');
    $schema_restore_transitive_view_trigger_conflict_id = (int)scalar($schema_restore_transitive_view_trigger_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_restore_transitive_parent' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    assert_true($schema_restore_transitive_view_trigger_conflict_id > 0, 'transitive view-trigger-sensitive target-dropped table restore conflict is auditable');
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $schema_restore_transitive_view_trigger_metadata,
            $schema_restore_transitive_view_trigger_conflict_id,
            'source',
            false,
            'Preview table restore with invalid preserved transitive view trigger.',
            'test'
        ),
        'plugin_restore_transitive_child_insert',
        'dry-run table restore rejects a preserved transitive target view trigger that would become invalid after source schema application'
    );
    assert_same(
        (int)scalar($schema_restore_transitive_view_trigger_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_restore_transitive_view_trigger_conflict_id"),
        0,
        'failed transitive view-trigger table restore dry-run does not record resolution metadata'
    );
    assert_same(
        (int)scalar($schema_restore_transitive_view_trigger_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_restore_transitive_parent'"),
        0,
        'failed transitive view-trigger table restore dry-run rolls back the restored table'
    );
    assert_same(
        (int)scalar($schema_restore_transitive_view_trigger_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_restore_transitive_live'"),
        1,
        'failed transitive view-trigger table restore dry-run preserves the direct target view'
    );
    assert_same(
        (int)scalar($schema_restore_transitive_view_trigger_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_restore_transitive_child'"),
        1,
        'failed transitive view-trigger table restore dry-run preserves the child target view'
    );
    assert_same(
        (int)scalar($schema_restore_transitive_view_trigger_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_restore_transitive_child_insert'"),
        1,
        'failed transitive view-trigger table restore dry-run preserves the child view trigger for review'
    );
    $db = open_db($schema_restore_transitive_view_trigger_target);
    $db->exec('DROP TRIGGER plugin_restore_transitive_child_insert');
    $db->close();
    $schema_restore_transitive_view_trigger_apply = cow_merge_resolve_conflict(
        $schema_restore_transitive_view_trigger_metadata,
        $schema_restore_transitive_view_trigger_conflict_id,
        'source',
        true,
        'Apply table restore after invalid transitive target view trigger is reviewed.',
        'test'
    );
    assert_same($schema_restore_transitive_view_trigger_apply['status'], 'applied', 'table restore applies after the invalid transitive target view trigger is handled');
    assert_same(scalar($schema_restore_transitive_view_trigger_target, "SELECT label FROM plugin_restore_transitive_child WHERE code = 'restore-transitive-parent'"), 'restore transitive label', 'preserved transitive target view remains queryable after table restore');

    $schema_restore_transitive_trigger_body_base = $tmp . '/schema-restore-transitive-trigger-body-base.sqlite';
    $schema_restore_transitive_trigger_body_source = $tmp . '/schema-restore-transitive-trigger-body-source.sqlite';
    $schema_restore_transitive_trigger_body_target = $tmp . '/schema-restore-transitive-trigger-body-target.sqlite';
    $schema_restore_transitive_trigger_body_metadata = $tmp . '/.forkpress/cow/merge/schema-restore-transitive-trigger-body-metadata.sqlite';
    create_base_db($schema_restore_transitive_trigger_body_base);
    $db = open_db($schema_restore_transitive_trigger_body_base);
    $db->exec('CREATE TABLE plugin_restore_transitive_trigger_parent (code TEXT NOT NULL PRIMARY KEY, label TEXT NOT NULL)');
    $db->exec('CREATE TABLE plugin_restore_transitive_trigger_observer (code TEXT)');
    $db->exec('CREATE TABLE plugin_restore_transitive_trigger_audit (code TEXT, observed TEXT)');
    $db->exec("INSERT INTO plugin_restore_transitive_trigger_parent (code, label) VALUES ('restore-transitive-trigger-parent', 'restore transitive trigger label')");
    $db->close();
    copy($schema_restore_transitive_trigger_body_base, $schema_restore_transitive_trigger_body_source);
    copy($schema_restore_transitive_trigger_body_base, $schema_restore_transitive_trigger_body_target);

    $db = open_db($schema_restore_transitive_trigger_body_source);
    $db->exec('CREATE TABLE plugin_restore_transitive_trigger_parent_new (code TEXT NOT NULL PRIMARY KEY, label TEXT NOT NULL) WITHOUT ROWID');
    $db->exec('INSERT INTO plugin_restore_transitive_trigger_parent_new (code, label) SELECT code, label FROM plugin_restore_transitive_trigger_parent');
    $db->exec('DROP TABLE plugin_restore_transitive_trigger_parent');
    $db->exec('ALTER TABLE plugin_restore_transitive_trigger_parent_new RENAME TO plugin_restore_transitive_trigger_parent');
    $db->close();
    $db = open_db($schema_restore_transitive_trigger_body_target);
    $db->exec('DROP TABLE plugin_restore_transitive_trigger_parent');
    $db->exec('CREATE VIEW plugin_restore_transitive_trigger_live AS SELECT code, label FROM plugin_restore_transitive_trigger_parent');
    $db->exec('CREATE VIEW plugin_restore_transitive_trigger_child AS SELECT code, label FROM plugin_restore_transitive_trigger_live');
    $db->exec(
        'CREATE TRIGGER plugin_restore_transitive_trigger_observer_insert AFTER INSERT ON plugin_restore_transitive_trigger_observer ' .
        'BEGIN INSERT INTO plugin_restore_transitive_trigger_audit (code, observed) ' .
        'SELECT NEW.code, CAST(rowid AS TEXT) FROM plugin_restore_transitive_trigger_child WHERE code = NEW.code; END'
    );
    $db->close();

    $schema_restore_transitive_trigger_body_result = cow_merge_databases(
        $schema_restore_transitive_trigger_body_base,
        $schema_restore_transitive_trigger_body_source,
        $schema_restore_transitive_trigger_body_target,
        $schema_restore_transitive_trigger_body_metadata,
        'feature-schema-restore-transitive-trigger-body',
        'main'
    );
    assert_same($schema_restore_transitive_trigger_body_result['status'], 'completed_with_conflicts', 'target-dropped table restore with a preserved transitive trigger body remains reviewable');
    $schema_restore_transitive_trigger_body_conflict_id = (int)scalar($schema_restore_transitive_trigger_body_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_restore_transitive_trigger_parent' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    assert_true($schema_restore_transitive_trigger_body_conflict_id > 0, 'transitive trigger-body-sensitive target-dropped table restore conflict is auditable');
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $schema_restore_transitive_trigger_body_metadata,
            $schema_restore_transitive_trigger_body_conflict_id,
            'source',
            false,
            'Preview table restore with invalid preserved transitive trigger body.',
            'test'
        ),
        'plugin_restore_transitive_trigger_observer_insert',
        'dry-run table restore rejects a preserved transitive target trigger body that would become invalid after source schema application'
    );
    assert_same(
        (int)scalar($schema_restore_transitive_trigger_body_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_restore_transitive_trigger_body_conflict_id"),
        0,
        'failed transitive trigger-body table restore dry-run does not record resolution metadata'
    );
    assert_same(
        (int)scalar($schema_restore_transitive_trigger_body_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_restore_transitive_trigger_parent'"),
        0,
        'failed transitive trigger-body table restore dry-run rolls back the restored table'
    );
    assert_same(
        (int)scalar($schema_restore_transitive_trigger_body_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_restore_transitive_trigger_live'"),
        1,
        'failed transitive trigger-body table restore dry-run preserves the direct target view'
    );
    assert_same(
        (int)scalar($schema_restore_transitive_trigger_body_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_restore_transitive_trigger_child'"),
        1,
        'failed transitive trigger-body table restore dry-run preserves the child target view'
    );
    assert_same(
        (int)scalar($schema_restore_transitive_trigger_body_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_restore_transitive_trigger_observer_insert'"),
        1,
        'failed transitive trigger-body table restore dry-run preserves the target observer trigger for review'
    );
    $db = open_db($schema_restore_transitive_trigger_body_target);
    $db->exec('DROP TRIGGER plugin_restore_transitive_trigger_observer_insert');
    $db->close();
    $schema_restore_transitive_trigger_body_apply = cow_merge_resolve_conflict(
        $schema_restore_transitive_trigger_body_metadata,
        $schema_restore_transitive_trigger_body_conflict_id,
        'source',
        true,
        'Apply table restore after invalid transitive target trigger body is reviewed.',
        'test'
    );
    assert_same($schema_restore_transitive_trigger_body_apply['status'], 'applied', 'table restore applies after the invalid transitive target trigger body is handled');
    assert_same(scalar($schema_restore_transitive_trigger_body_target, "SELECT label FROM plugin_restore_transitive_trigger_child WHERE code = 'restore-transitive-trigger-parent'"), 'restore transitive trigger label', 'preserved transitive target view remains queryable after trigger-body-sensitive table restore');

    $schema_restore_deep_trigger_body_base = $tmp . '/schema-restore-deep-trigger-body-base.sqlite';
    $schema_restore_deep_trigger_body_source = $tmp . '/schema-restore-deep-trigger-body-source.sqlite';
    $schema_restore_deep_trigger_body_target = $tmp . '/schema-restore-deep-trigger-body-target.sqlite';
    $schema_restore_deep_trigger_body_metadata = $tmp . '/.forkpress/cow/merge/schema-restore-deep-trigger-body-metadata.sqlite';
    create_base_db($schema_restore_deep_trigger_body_base);
    $db = open_db($schema_restore_deep_trigger_body_base);
    $db->exec('CREATE TABLE plugin_restore_deep_trigger_parent (code TEXT NOT NULL PRIMARY KEY, label TEXT NOT NULL)');
    $db->exec('CREATE TABLE plugin_restore_deep_trigger_observer (code TEXT)');
    $db->exec('CREATE TABLE plugin_restore_deep_trigger_audit (code TEXT, observed TEXT, note TEXT)');
    $db->exec("INSERT INTO plugin_restore_deep_trigger_parent (code, label) VALUES ('restore-deep-trigger-parent', 'restore deep trigger label')");
    $db->close();
    copy($schema_restore_deep_trigger_body_base, $schema_restore_deep_trigger_body_source);
    copy($schema_restore_deep_trigger_body_base, $schema_restore_deep_trigger_body_target);

    $db = open_db($schema_restore_deep_trigger_body_source);
    $db->exec('CREATE TABLE plugin_restore_deep_trigger_parent_new (code TEXT NOT NULL PRIMARY KEY, label TEXT NOT NULL) WITHOUT ROWID');
    $db->exec('INSERT INTO plugin_restore_deep_trigger_parent_new (code, label) SELECT code, label FROM plugin_restore_deep_trigger_parent');
    $db->exec('DROP TABLE plugin_restore_deep_trigger_parent');
    $db->exec('ALTER TABLE plugin_restore_deep_trigger_parent_new RENAME TO plugin_restore_deep_trigger_parent');
    $db->close();
    $db = open_db($schema_restore_deep_trigger_body_target);
    $db->exec('DROP TABLE plugin_restore_deep_trigger_parent');
    $db->exec('CREATE VIEW plugin_restore_deep_trigger_live AS SELECT code, label FROM plugin_restore_deep_trigger_parent');
    $db->exec('CREATE VIEW plugin_restore_deep_trigger_child AS SELECT code, label FROM plugin_restore_deep_trigger_live');
    $db->exec('CREATE VIEW plugin_restore_deep_trigger_grandchild AS SELECT code, label FROM plugin_restore_deep_trigger_child');
    $db->exec(
        'CREATE TRIGGER plugin_restore_deep_trigger_bad_insert AFTER INSERT ON plugin_restore_deep_trigger_observer ' .
        'BEGIN INSERT INTO plugin_restore_deep_trigger_audit (code, observed, note) ' .
        "SELECT NEW.code, CAST(rowid AS TEXT), 'bad' FROM plugin_restore_deep_trigger_grandchild WHERE code = NEW.code; END"
    );
    $db->exec(
        'CREATE TRIGGER plugin_restore_deep_trigger_ok_insert AFTER INSERT ON plugin_restore_deep_trigger_observer ' .
        'BEGIN INSERT INTO plugin_restore_deep_trigger_audit (code, observed, note) ' .
        "SELECT NEW.code, label, 'ok' FROM plugin_restore_deep_trigger_grandchild WHERE code = NEW.code; END"
    );
    $db->close();

    $schema_restore_deep_trigger_body_result = cow_merge_databases(
        $schema_restore_deep_trigger_body_base,
        $schema_restore_deep_trigger_body_source,
        $schema_restore_deep_trigger_body_target,
        $schema_restore_deep_trigger_body_metadata,
        'feature-schema-restore-deep-trigger-body',
        'main'
    );
    assert_same($schema_restore_deep_trigger_body_result['status'], 'completed_with_conflicts', 'target-dropped table restore with a preserved deep trigger body remains reviewable');
    $schema_restore_deep_trigger_body_conflict_id = (int)scalar($schema_restore_deep_trigger_body_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_restore_deep_trigger_parent' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    assert_true($schema_restore_deep_trigger_body_conflict_id > 0, 'deep trigger-body-sensitive target-dropped table restore conflict is auditable');
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $schema_restore_deep_trigger_body_metadata,
            $schema_restore_deep_trigger_body_conflict_id,
            'source',
            false,
            'Preview table restore with invalid preserved deep trigger body.',
            'test'
        ),
        'plugin_restore_deep_trigger_bad_insert',
        'dry-run table restore rejects a preserved target trigger body that references a deeper invalid view chain'
    );
    assert_same(
        (int)scalar($schema_restore_deep_trigger_body_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_restore_deep_trigger_body_conflict_id"),
        0,
        'failed deep trigger-body table restore dry-run does not record resolution metadata'
    );
    assert_same(
        (int)scalar($schema_restore_deep_trigger_body_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_restore_deep_trigger_parent'"),
        0,
        'failed deep trigger-body table restore dry-run rolls back the restored table'
    );
    assert_same(
        (int)scalar($schema_restore_deep_trigger_body_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name IN ('plugin_restore_deep_trigger_live', 'plugin_restore_deep_trigger_child', 'plugin_restore_deep_trigger_grandchild')"),
        3,
        'failed deep trigger-body table restore dry-run preserves the full target view chain'
    );
    assert_same(
        (int)scalar($schema_restore_deep_trigger_body_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name IN ('plugin_restore_deep_trigger_bad_insert', 'plugin_restore_deep_trigger_ok_insert')"),
        2,
        'failed deep trigger-body table restore dry-run preserves all target observer triggers for review'
    );
    $db = open_db($schema_restore_deep_trigger_body_target);
    $db->exec('DROP TRIGGER plugin_restore_deep_trigger_bad_insert');
    $db->close();
    $schema_restore_deep_trigger_body_apply = cow_merge_resolve_conflict(
        $schema_restore_deep_trigger_body_metadata,
        $schema_restore_deep_trigger_body_conflict_id,
        'source',
        true,
        'Apply table restore after invalid deep target trigger body is reviewed.',
        'test'
    );
    assert_same($schema_restore_deep_trigger_body_apply['status'], 'applied', 'table restore applies after the invalid deep target trigger body is handled');
    $db = open_db($schema_restore_deep_trigger_body_target);
    $db->exec("INSERT INTO plugin_restore_deep_trigger_observer (code) VALUES ('restore-deep-trigger-parent')");
    $db->close();
    assert_same(scalar($schema_restore_deep_trigger_body_target, "SELECT observed FROM plugin_restore_deep_trigger_audit WHERE note = 'ok' AND code = 'restore-deep-trigger-parent'"), 'restore deep trigger label', 'valid preserved target trigger still fires through the deeper view chain after table restore');

    $schema_restore_mixed_rollback_base = $tmp . '/schema-restore-mixed-rollback-base.sqlite';
    $schema_restore_mixed_rollback_source = $tmp . '/schema-restore-mixed-rollback-source.sqlite';
    $schema_restore_mixed_rollback_target = $tmp . '/schema-restore-mixed-rollback-target.sqlite';
    $schema_restore_mixed_rollback_metadata = $tmp . '/.forkpress/cow/merge/schema-restore-mixed-rollback-metadata.sqlite';
    create_base_db($schema_restore_mixed_rollback_base);
    $db = open_db($schema_restore_mixed_rollback_base);
    $db->exec('CREATE TABLE plugin_restore_mixed_parent (code TEXT NOT NULL PRIMARY KEY, lookup TEXT NOT NULL, label TEXT NOT NULL)');
    $db->exec('CREATE UNIQUE INDEX plugin_restore_mixed_parent_lookup_idx ON plugin_restore_mixed_parent(lookup)');
    $db->exec('CREATE TABLE plugin_restore_mixed_child (parent_lookup TEXT NOT NULL REFERENCES plugin_restore_mixed_parent(lookup), note TEXT NOT NULL)');
    $db->exec('CREATE TABLE plugin_restore_mixed_observer (code TEXT NOT NULL)');
    $db->exec('CREATE TABLE plugin_restore_mixed_audit (code TEXT, observed TEXT, note TEXT)');
    $db->exec(
        'CREATE TRIGGER plugin_restore_mixed_parent_insert AFTER INSERT ON plugin_restore_mixed_parent ' .
        'BEGIN INSERT INTO plugin_restore_mixed_audit (code, observed, note) VALUES (NEW.code, NEW.label, "source-trigger"); END'
    );
    $db->exec("INSERT INTO plugin_restore_mixed_parent (code, lookup, label) VALUES ('restore-mixed-parent', 'restore-mixed-lookup', 'restore mixed label')");
    $db->exec("INSERT INTO plugin_restore_mixed_child (parent_lookup, note) VALUES ('restore-mixed-lookup', 'child stays valid')");
    $db->close();
    copy($schema_restore_mixed_rollback_base, $schema_restore_mixed_rollback_source);
    copy($schema_restore_mixed_rollback_base, $schema_restore_mixed_rollback_target);

    $db = open_db($schema_restore_mixed_rollback_source);
    $db->exec('DROP TRIGGER plugin_restore_mixed_parent_insert');
    $db->exec('CREATE TABLE plugin_restore_mixed_parent_new (code TEXT NOT NULL PRIMARY KEY, lookup TEXT NOT NULL, label TEXT NOT NULL) WITHOUT ROWID');
    $db->exec('INSERT INTO plugin_restore_mixed_parent_new (code, lookup, label) SELECT code, lookup, label FROM plugin_restore_mixed_parent');
    $db->exec('DROP TABLE plugin_restore_mixed_parent');
    $db->exec('ALTER TABLE plugin_restore_mixed_parent_new RENAME TO plugin_restore_mixed_parent');
    $db->exec('CREATE UNIQUE INDEX plugin_restore_mixed_parent_lookup_idx ON plugin_restore_mixed_parent(lookup)');
    $db->exec(
        'CREATE TRIGGER plugin_restore_mixed_parent_insert AFTER INSERT ON plugin_restore_mixed_parent ' .
        'BEGIN INSERT INTO plugin_restore_mixed_audit (code, observed, note) VALUES (NEW.code, NEW.label, "source-trigger"); END'
    );
    $db->close();

    $db = open_db($schema_restore_mixed_rollback_target);
    $db->exec('DROP TABLE plugin_restore_mixed_parent');
    $db->exec(
        'CREATE TRIGGER plugin_restore_mixed_observer_insert AFTER INSERT ON plugin_restore_mixed_observer ' .
        'BEGIN INSERT INTO plugin_restore_mixed_audit (code, observed, note) ' .
        "SELECT NEW.code, CAST(rowid AS TEXT), 'target-trigger' FROM plugin_restore_mixed_parent WHERE code = NEW.code; END"
    );
    $db->close();

    $schema_restore_mixed_rollback_result = cow_merge_databases(
        $schema_restore_mixed_rollback_base,
        $schema_restore_mixed_rollback_source,
        $schema_restore_mixed_rollback_target,
        $schema_restore_mixed_rollback_metadata,
        'feature-schema-restore-mixed-rollback',
        'main'
    );
    assert_same($schema_restore_mixed_rollback_result['status'], 'completed_with_conflicts', 'target-dropped table restore with mixed FK/index/trigger dependencies remains reviewable');
    $schema_restore_mixed_rollback_conflict_id = (int)scalar($schema_restore_mixed_rollback_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_restore_mixed_parent' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    assert_true($schema_restore_mixed_rollback_conflict_id > 0, 'mixed dependency target-dropped table restore conflict is auditable');
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $schema_restore_mixed_rollback_metadata,
            $schema_restore_mixed_rollback_conflict_id,
            'source',
            false,
            'Preview table restore with preserved target trigger failure after source index and trigger restore.',
            'test'
        ),
        'plugin_restore_mixed_observer_insert',
        'dry-run table restore rejects the later invalid preserved target trigger after source index/trigger restoration'
    );
    assert_same(
        (int)scalar($schema_restore_mixed_rollback_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_restore_mixed_rollback_conflict_id"),
        0,
        'failed mixed dependency table restore dry-run does not record resolution metadata'
    );
    assert_same(
        (int)scalar($schema_restore_mixed_rollback_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_restore_mixed_parent'"),
        0,
        'failed mixed dependency table restore dry-run rolls back the restored table'
    );
    assert_same(
        (int)scalar($schema_restore_mixed_rollback_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_restore_mixed_parent_lookup_idx'"),
        0,
        'failed mixed dependency table restore dry-run rolls back the restored source index'
    );
    assert_same(
        (int)scalar($schema_restore_mixed_rollback_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_restore_mixed_parent_insert'"),
        0,
        'failed mixed dependency table restore dry-run rolls back the restored source trigger'
    );
    assert_same(
        (int)scalar($schema_restore_mixed_rollback_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_restore_mixed_observer_insert'"),
        1,
        'failed mixed dependency table restore dry-run preserves the invalid target trigger for review'
    );
    assert_same(
        (int)scalar($schema_restore_mixed_rollback_target, "SELECT COUNT(*) FROM plugin_restore_mixed_child WHERE parent_lookup = 'restore-mixed-lookup'"),
        1,
        'failed mixed dependency table restore dry-run preserves the target FK child row'
    );

    $db = open_db($schema_restore_mixed_rollback_target);
    $db->exec('DROP TRIGGER plugin_restore_mixed_observer_insert');
    $db->close();
    $schema_restore_mixed_rollback_apply = cow_merge_resolve_conflict(
        $schema_restore_mixed_rollback_metadata,
        $schema_restore_mixed_rollback_conflict_id,
        'source',
        true,
        'Apply table restore after invalid target trigger is reviewed.',
        'test'
    );
    assert_same($schema_restore_mixed_rollback_apply['status'], 'applied', 'mixed dependency table restore applies after the invalid target trigger is handled');
    assert_same(
        (int)scalar($schema_restore_mixed_rollback_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_restore_mixed_parent_lookup_idx'"),
        1,
        'validated mixed dependency table restore recreates the FK backing source index'
    );
    assert_same(
        (int)scalar($schema_restore_mixed_rollback_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_restore_mixed_parent_insert'"),
        1,
        'validated mixed dependency table restore recreates the source table trigger'
    );
    $db = open_db($schema_restore_mixed_rollback_target);
    $db->exec("INSERT INTO plugin_restore_mixed_parent (code, lookup, label) VALUES ('restore-mixed-new-parent', 'restore-mixed-new-lookup', 'restore mixed new label')");
    $db->close();
    assert_same(scalar($schema_restore_mixed_rollback_target, "SELECT observed FROM plugin_restore_mixed_audit WHERE code = 'restore-mixed-new-parent' AND note = 'source-trigger'"), 'restore mixed new label', 'restored source trigger fires after validated mixed dependency table restore');
    assert_same(scalar($schema_restore_mixed_rollback_target, "SELECT note FROM plugin_restore_mixed_child WHERE parent_lookup = 'restore-mixed-lookup'"), 'child stays valid', 'validated mixed dependency table restore keeps the index-backed FK child row valid');

    $schema_restore_keyless_mixed_rollback_base = $tmp . '/schema-restore-keyless-mixed-rollback-base.sqlite';
    $schema_restore_keyless_mixed_rollback_source = $tmp . '/schema-restore-keyless-mixed-rollback-source.sqlite';
    $schema_restore_keyless_mixed_rollback_target = $tmp . '/schema-restore-keyless-mixed-rollback-target.sqlite';
    $schema_restore_keyless_mixed_rollback_metadata = $tmp . '/.forkpress/cow/merge/schema-restore-keyless-mixed-rollback-metadata.sqlite';
    create_base_db($schema_restore_keyless_mixed_rollback_base);
    $db = open_db($schema_restore_keyless_mixed_rollback_base);
    $db->exec('CREATE TABLE plugin_restore_keyless_mixed_parent (lookup TEXT NOT NULL, label TEXT NOT NULL, legacy TEXT NOT NULL)');
    $db->exec('CREATE UNIQUE INDEX plugin_restore_keyless_mixed_parent_lookup_idx ON plugin_restore_keyless_mixed_parent(lookup)');
    $db->exec('CREATE TABLE plugin_restore_keyless_mixed_child (parent_lookup TEXT NOT NULL REFERENCES plugin_restore_keyless_mixed_parent(lookup), note TEXT NOT NULL)');
    $db->exec('CREATE TABLE plugin_restore_keyless_mixed_observer (code TEXT NOT NULL)');
    $db->exec('CREATE TABLE plugin_restore_keyless_mixed_audit (code TEXT, observed TEXT, note TEXT)');
    $db->exec(
        'CREATE TRIGGER plugin_restore_keyless_mixed_parent_insert AFTER INSERT ON plugin_restore_keyless_mixed_parent ' .
        'BEGIN INSERT INTO plugin_restore_keyless_mixed_audit (code, observed, note) VALUES (NEW.lookup, NEW.label, "source-trigger"); END'
    );
    $db->exec("INSERT INTO plugin_restore_keyless_mixed_parent (rowid, lookup, label, legacy) VALUES (7, 'keyless-restore-lookup', 'keyless restore label', 'legacy value')");
    $db->exec("INSERT INTO plugin_restore_keyless_mixed_child (parent_lookup, note) VALUES ('keyless-restore-lookup', 'keyless child stays valid')");
    $db->close();
    copy($schema_restore_keyless_mixed_rollback_base, $schema_restore_keyless_mixed_rollback_source);
    copy($schema_restore_keyless_mixed_rollback_base, $schema_restore_keyless_mixed_rollback_target);
    cow_merge_capture_row_identities($schema_restore_keyless_mixed_rollback_base, $schema_restore_keyless_mixed_rollback_metadata, 'main');
    cow_merge_capture_row_identities($schema_restore_keyless_mixed_rollback_source, $schema_restore_keyless_mixed_rollback_metadata, 'feature-schema-restore-keyless-mixed-rollback', 'main');
    cow_merge_capture_row_identities($schema_restore_keyless_mixed_rollback_target, $schema_restore_keyless_mixed_rollback_metadata, 'main');

    $db = open_db($schema_restore_keyless_mixed_rollback_source);
    $db->exec('DROP TRIGGER plugin_restore_keyless_mixed_parent_insert');
    $db->exec('CREATE TABLE plugin_restore_keyless_mixed_parent_new (lookup TEXT NOT NULL, label TEXT NOT NULL)');
    $db->exec('INSERT INTO plugin_restore_keyless_mixed_parent_new (rowid, lookup, label) SELECT rowid, lookup, label FROM plugin_restore_keyless_mixed_parent');
    $db->exec('DROP TABLE plugin_restore_keyless_mixed_parent');
    $db->exec('ALTER TABLE plugin_restore_keyless_mixed_parent_new RENAME TO plugin_restore_keyless_mixed_parent');
    $db->exec('CREATE UNIQUE INDEX plugin_restore_keyless_mixed_parent_lookup_idx ON plugin_restore_keyless_mixed_parent(lookup)');
    $db->exec(
        'CREATE TRIGGER plugin_restore_keyless_mixed_parent_insert AFTER INSERT ON plugin_restore_keyless_mixed_parent ' .
        'BEGIN INSERT INTO plugin_restore_keyless_mixed_audit (code, observed, note) VALUES (NEW.lookup, NEW.label, "source-trigger"); END'
    );
    $db->close();
    cow_merge_capture_row_identities($schema_restore_keyless_mixed_rollback_source, $schema_restore_keyless_mixed_rollback_metadata, 'feature-schema-restore-keyless-mixed-rollback', 'main');
    $schema_restore_keyless_mixed_source_identity = scalar(
        $schema_restore_keyless_mixed_rollback_metadata,
        "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'feature-schema-restore-keyless-mixed-rollback' AND table_name = 'plugin_restore_keyless_mixed_parent' AND rowid = 7"
    );

    $db = open_db($schema_restore_keyless_mixed_rollback_target);
    $db->exec('DELETE FROM plugin_restore_keyless_mixed_parent WHERE rowid = 7');
    $db->exec("INSERT INTO plugin_restore_keyless_mixed_parent (rowid, lookup, label, legacy) VALUES (7, 'keyless-restore-lookup', 'stale target label', 'stale legacy')");
    $db->close();
    cow_merge_track_row_identity_events(
        $schema_restore_keyless_mixed_rollback_target,
        $schema_restore_keyless_mixed_rollback_metadata,
        'main',
        [
            [
                'id' => 1,
                'table' => 'plugin_restore_keyless_mixed_parent',
                'op' => 'delete',
                'rowid' => 7,
                'row' => ['lookup' => 'keyless-restore-lookup', 'label' => 'keyless restore label', 'legacy' => 'legacy value'],
            ],
            [
                'id' => 2,
                'table' => 'plugin_restore_keyless_mixed_parent',
                'op' => 'insert',
                'rowid' => 7,
                'row' => ['lookup' => 'keyless-restore-lookup', 'label' => 'stale target label', 'legacy' => 'stale legacy'],
            ],
        ]
    );
    $schema_restore_keyless_mixed_stale_identity = scalar(
        $schema_restore_keyless_mixed_rollback_metadata,
        "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_restore_keyless_mixed_parent' AND rowid = 7"
    );
    $db = open_db($schema_restore_keyless_mixed_rollback_target);
    $db->exec('DROP TABLE plugin_restore_keyless_mixed_parent');
    $db->exec(
        'CREATE TRIGGER plugin_restore_keyless_mixed_observer_insert AFTER INSERT ON plugin_restore_keyless_mixed_observer ' .
        'BEGIN INSERT INTO plugin_restore_keyless_mixed_audit (code, observed, note) ' .
        "SELECT NEW.code, legacy, 'target-trigger' FROM plugin_restore_keyless_mixed_parent WHERE lookup = NEW.code; END"
    );
    $db->close();

    $schema_restore_keyless_mixed_rollback_result = cow_merge_databases(
        $schema_restore_keyless_mixed_rollback_base,
        $schema_restore_keyless_mixed_rollback_source,
        $schema_restore_keyless_mixed_rollback_target,
        $schema_restore_keyless_mixed_rollback_metadata,
        'feature-schema-restore-keyless-mixed-rollback',
        'main'
    );
    assert_same($schema_restore_keyless_mixed_rollback_result['status'], 'completed_with_conflicts', 'target-dropped keyless table restore with mixed dependencies remains reviewable');
    $schema_restore_keyless_mixed_rollback_conflict_id = (int)scalar($schema_restore_keyless_mixed_rollback_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_restore_keyless_mixed_parent' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    assert_true($schema_restore_keyless_mixed_rollback_conflict_id > 0, 'keyless mixed dependency target-dropped table restore conflict is auditable');
    $schema_restore_keyless_mixed_source_history_before_failed_preview = (int)scalar(
        $schema_restore_keyless_mixed_rollback_metadata,
        "SELECT COUNT(*) FROM merge_row_identity_history WHERE branch_name = 'main' AND table_name = 'plugin_restore_keyless_mixed_parent' AND logical_identity = '" . SQLite3::escapeString((string)$schema_restore_keyless_mixed_source_identity) . "'"
    );
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $schema_restore_keyless_mixed_rollback_metadata,
            $schema_restore_keyless_mixed_rollback_conflict_id,
            'source',
            false,
            'Preview keyless table restore with late preserved target trigger failure.',
            'test'
        ),
        'plugin_restore_keyless_mixed_observer_insert',
        'failed dry-run rolls back keyless table restore after source row/index/trigger staging'
    );
    assert_same(
        (int)scalar($schema_restore_keyless_mixed_rollback_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_restore_keyless_mixed_rollback_conflict_id"),
        0,
        'failed keyless mixed restore dry-run does not record resolution metadata'
    );
    assert_same(
        (int)scalar($schema_restore_keyless_mixed_rollback_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_restore_keyless_mixed_parent'"),
        0,
        'failed keyless mixed restore dry-run rolls back the restored table'
    );
    assert_same(
        scalar($schema_restore_keyless_mixed_rollback_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_restore_keyless_mixed_parent' AND rowid = 7"),
        $schema_restore_keyless_mixed_stale_identity,
        'failed keyless mixed restore dry-run rolls back source sidecar adoption'
    );
    assert_same(
        (int)scalar($schema_restore_keyless_mixed_rollback_metadata, "SELECT COUNT(*) FROM merge_row_identity_history WHERE branch_name = 'main' AND table_name = 'plugin_restore_keyless_mixed_parent' AND logical_identity = '" . SQLite3::escapeString((string)$schema_restore_keyless_mixed_source_identity) . "'"),
        $schema_restore_keyless_mixed_source_history_before_failed_preview,
        'failed keyless mixed restore dry-run rolls back new source sidecar history writes'
    );
    $schema_restore_keyless_mixed_source_history_before_failed_apply = (int)scalar(
        $schema_restore_keyless_mixed_rollback_metadata,
        "SELECT COUNT(*) FROM merge_row_identity_history WHERE branch_name = 'main' AND table_name = 'plugin_restore_keyless_mixed_parent' AND logical_identity = '" . SQLite3::escapeString((string)$schema_restore_keyless_mixed_source_identity) . "'"
    );
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $schema_restore_keyless_mixed_rollback_metadata,
            $schema_restore_keyless_mixed_rollback_conflict_id,
            'source',
            true,
            'Apply keyless table restore with late preserved target trigger failure.',
            'test'
        ),
        'plugin_restore_keyless_mixed_observer_insert',
        'failed apply rolls back keyless table restore after source row/index/trigger staging'
    );
    assert_same(
        (int)scalar($schema_restore_keyless_mixed_rollback_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_restore_keyless_mixed_rollback_conflict_id"),
        0,
        'failed keyless mixed restore apply does not record resolution metadata'
    );
    assert_same(
        (int)scalar($schema_restore_keyless_mixed_rollback_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_restore_keyless_mixed_parent'"),
        0,
        'failed keyless mixed restore apply rolls back the restored table'
    );
    assert_same(
        (int)scalar($schema_restore_keyless_mixed_rollback_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'plugin_restore_keyless_mixed_parent_lookup_idx'"),
        0,
        'failed keyless mixed restore apply rolls back the restored source index'
    );
    assert_same(
        (int)scalar($schema_restore_keyless_mixed_rollback_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_restore_keyless_mixed_parent_insert'"),
        0,
        'failed keyless mixed restore apply rolls back the restored source trigger'
    );
    assert_same(
        (int)scalar($schema_restore_keyless_mixed_rollback_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_restore_keyless_mixed_observer_insert'"),
        1,
        'failed keyless mixed restore apply preserves the invalid target trigger for review'
    );
    assert_same(
        scalar($schema_restore_keyless_mixed_rollback_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_restore_keyless_mixed_parent' AND rowid = 7"),
        $schema_restore_keyless_mixed_stale_identity,
        'failed keyless mixed restore apply rolls back source sidecar adoption'
    );
    assert_same(
        (int)scalar($schema_restore_keyless_mixed_rollback_metadata, "SELECT COUNT(*) FROM merge_row_identity_history WHERE branch_name = 'main' AND table_name = 'plugin_restore_keyless_mixed_parent' AND logical_identity = '" . SQLite3::escapeString((string)$schema_restore_keyless_mixed_source_identity) . "'"),
        $schema_restore_keyless_mixed_source_history_before_failed_apply,
        'failed keyless mixed restore apply rolls back new source sidecar history writes'
    );

    $db = open_db($schema_restore_keyless_mixed_rollback_target);
    $db->exec('DROP TRIGGER plugin_restore_keyless_mixed_observer_insert');
    $db->close();
    $schema_restore_keyless_mixed_rollback_apply = cow_merge_resolve_conflict(
        $schema_restore_keyless_mixed_rollback_metadata,
        $schema_restore_keyless_mixed_rollback_conflict_id,
        'source',
        true,
        'Apply keyless table restore after invalid target trigger is reviewed.',
        'test'
    );
    assert_same($schema_restore_keyless_mixed_rollback_apply['status'], 'applied', 'keyless mixed dependency table restore applies after the invalid target trigger is handled');
    assert_same(scalar($schema_restore_keyless_mixed_rollback_target, "SELECT label FROM plugin_restore_keyless_mixed_parent WHERE rowid = 7"), 'keyless restore label', 'validated keyless mixed restore preserves the audited source rowid and row payload');
    assert_same(
        scalar($schema_restore_keyless_mixed_rollback_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_restore_keyless_mixed_parent' AND rowid = 7"),
        $schema_restore_keyless_mixed_source_identity,
        'validated keyless mixed restore adopts source sidecar identity after rollback-sensitive apply succeeds'
    );
    assert_same(
        (int)scalar($schema_restore_keyless_mixed_rollback_metadata, "SELECT COUNT(*) FROM merge_row_identity_history WHERE branch_name = 'main' AND table_name = 'plugin_restore_keyless_mixed_parent' AND logical_identity = '" . SQLite3::escapeString((string)$schema_restore_keyless_mixed_stale_identity) . "' AND deleted_at IS NOT NULL"),
        1,
        'validated keyless mixed restore tombstones stale target sidecar identity after successful apply'
    );
    assert_same(scalar($schema_restore_keyless_mixed_rollback_target, "SELECT note FROM plugin_restore_keyless_mixed_child WHERE parent_lookup = 'keyless-restore-lookup'"), 'keyless child stays valid', 'validated keyless mixed restore keeps the index-backed FK child row valid');
    $db = open_db($schema_restore_keyless_mixed_rollback_target);
    $db->exec("INSERT INTO plugin_restore_keyless_mixed_parent (rowid, lookup, label) VALUES (17, 'keyless-restore-new-lookup', 'keyless restored trigger label')");
    $db->close();
    assert_same(scalar($schema_restore_keyless_mixed_rollback_target, "SELECT observed FROM plugin_restore_keyless_mixed_audit WHERE code = 'keyless-restore-new-lookup' AND note = 'source-trigger'"), 'keyless restored trigger label', 'restored keyless source trigger fires after validated mixed dependency table restore');

    $schema_restore_keyless_fk_rollback_base = $tmp . '/schema-restore-keyless-fk-rollback-base.sqlite';
    $schema_restore_keyless_fk_rollback_source = $tmp . '/schema-restore-keyless-fk-rollback-source.sqlite';
    $schema_restore_keyless_fk_rollback_target = $tmp . '/schema-restore-keyless-fk-rollback-target.sqlite';
    $schema_restore_keyless_fk_rollback_metadata = $tmp . '/.forkpress/cow/merge/schema-restore-keyless-fk-rollback-metadata.sqlite';
    create_base_db($schema_restore_keyless_fk_rollback_base);
    $db = open_db($schema_restore_keyless_fk_rollback_base);
    $db->exec('CREATE TABLE plugin_restore_keyless_fk_parent (lookup TEXT NOT NULL, label TEXT NOT NULL)');
    $db->exec('CREATE UNIQUE INDEX plugin_restore_keyless_fk_parent_lookup_idx ON plugin_restore_keyless_fk_parent(lookup)');
    $db->exec('CREATE TABLE plugin_restore_keyless_fk_child (parent_lookup TEXT NOT NULL REFERENCES plugin_restore_keyless_fk_parent(lookup), note TEXT NOT NULL)');
    $db->exec("INSERT INTO plugin_restore_keyless_fk_parent (rowid, lookup, label) VALUES (9, 'keyless-fk-lookup', 'base keyless FK label')");
    $db->exec("INSERT INTO plugin_restore_keyless_fk_child (parent_lookup, note) VALUES ('keyless-fk-lookup', 'target child needs unique parent index')");
    $db->close();
    copy($schema_restore_keyless_fk_rollback_base, $schema_restore_keyless_fk_rollback_source);
    copy($schema_restore_keyless_fk_rollback_base, $schema_restore_keyless_fk_rollback_target);
    cow_merge_capture_row_identities($schema_restore_keyless_fk_rollback_base, $schema_restore_keyless_fk_rollback_metadata, 'main');
    cow_merge_capture_row_identities($schema_restore_keyless_fk_rollback_source, $schema_restore_keyless_fk_rollback_metadata, 'feature-schema-restore-keyless-fk-rollback', 'main');
    cow_merge_capture_row_identities($schema_restore_keyless_fk_rollback_target, $schema_restore_keyless_fk_rollback_metadata, 'main');

    $db = open_db($schema_restore_keyless_fk_rollback_source);
    $db->exec('DROP INDEX plugin_restore_keyless_fk_parent_lookup_idx');
    $db->exec("DELETE FROM plugin_restore_keyless_fk_parent WHERE rowid = 9");
    $db->exec("INSERT INTO plugin_restore_keyless_fk_parent (rowid, lookup, label) VALUES (9, 'keyless-fk-lookup', 'source keyless FK label')");
    $db->close();
    cow_merge_track_row_identity_events(
        $schema_restore_keyless_fk_rollback_source,
        $schema_restore_keyless_fk_rollback_metadata,
        'feature-schema-restore-keyless-fk-rollback',
        [
            [
                'id' => 1,
                'table' => 'plugin_restore_keyless_fk_parent',
                'op' => 'delete',
                'rowid' => 9,
                'row' => ['lookup' => 'keyless-fk-lookup', 'label' => 'base keyless FK label'],
            ],
            [
                'id' => 2,
                'table' => 'plugin_restore_keyless_fk_parent',
                'op' => 'insert',
                'rowid' => 9,
                'row' => ['lookup' => 'keyless-fk-lookup', 'label' => 'source keyless FK label'],
            ],
        ]
    );
    $schema_restore_keyless_fk_source_identity = scalar(
        $schema_restore_keyless_fk_rollback_metadata,
        "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'feature-schema-restore-keyless-fk-rollback' AND table_name = 'plugin_restore_keyless_fk_parent' AND rowid = 9"
    );

    $db = open_db($schema_restore_keyless_fk_rollback_target);
    $db->exec("DELETE FROM plugin_restore_keyless_fk_parent WHERE rowid = 9");
    $db->exec("INSERT INTO plugin_restore_keyless_fk_parent (rowid, lookup, label) VALUES (9, 'keyless-fk-lookup', 'stale keyless FK target label')");
    $db->close();
    cow_merge_track_row_identity_events(
        $schema_restore_keyless_fk_rollback_target,
        $schema_restore_keyless_fk_rollback_metadata,
        'main',
        [
            [
                'id' => 1,
                'table' => 'plugin_restore_keyless_fk_parent',
                'op' => 'delete',
                'rowid' => 9,
                'row' => ['lookup' => 'keyless-fk-lookup', 'label' => 'base keyless FK label'],
            ],
            [
                'id' => 2,
                'table' => 'plugin_restore_keyless_fk_parent',
                'op' => 'insert',
                'rowid' => 9,
                'row' => ['lookup' => 'keyless-fk-lookup', 'label' => 'stale keyless FK target label'],
            ],
        ]
    );
    $schema_restore_keyless_fk_stale_identity = scalar(
        $schema_restore_keyless_fk_rollback_metadata,
        "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_restore_keyless_fk_parent' AND rowid = 9"
    );
    assert_true($schema_restore_keyless_fk_source_identity !== $schema_restore_keyless_fk_stale_identity, 'keyless FK restore fixture has distinct source and stale target sidecar identities');
    $db = open_db($schema_restore_keyless_fk_rollback_target);
    $db->exec('DROP TABLE plugin_restore_keyless_fk_parent');
    $db->close();

    $schema_restore_keyless_fk_rollback_result = cow_merge_databases(
        $schema_restore_keyless_fk_rollback_base,
        $schema_restore_keyless_fk_rollback_source,
        $schema_restore_keyless_fk_rollback_target,
        $schema_restore_keyless_fk_rollback_metadata,
        'feature-schema-restore-keyless-fk-rollback',
        'main'
    );
    assert_same($schema_restore_keyless_fk_rollback_result['status'], 'completed_with_conflicts', 'target-dropped keyless FK parent restore remains reviewable when the source drops the child FK backing index');
    $schema_restore_keyless_fk_rollback_conflict_id = (int)scalar($schema_restore_keyless_fk_rollback_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_restore_keyless_fk_parent' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    assert_true($schema_restore_keyless_fk_rollback_conflict_id > 0, 'keyless FK parent restore conflict is auditable');
    $schema_restore_keyless_fk_source_history_before_failed_preview = (int)scalar(
        $schema_restore_keyless_fk_rollback_metadata,
        "SELECT COUNT(*) FROM merge_row_identity_history WHERE branch_name = 'main' AND table_name = 'plugin_restore_keyless_fk_parent' AND logical_identity = '" . SQLite3::escapeString((string)$schema_restore_keyless_fk_source_identity) . "'"
    );
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $schema_restore_keyless_fk_rollback_metadata,
            $schema_restore_keyless_fk_rollback_conflict_id,
            'source',
            false,
            'Preview keyless FK parent restore with missing source unique index.',
            'test'
        ),
        'foreign-key validation error',
        'failed dry-run rolls back keyless FK parent restore after source sidecar staging'
    );
    assert_same(
        (int)scalar($schema_restore_keyless_fk_rollback_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_restore_keyless_fk_rollback_conflict_id"),
        0,
        'failed keyless FK restore dry-run does not record resolution metadata'
    );
    assert_same(
        (int)scalar($schema_restore_keyless_fk_rollback_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_restore_keyless_fk_parent'"),
        0,
        'failed keyless FK restore dry-run rolls back the restored table'
    );
    assert_same(
        scalar($schema_restore_keyless_fk_rollback_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_restore_keyless_fk_parent' AND rowid = 9"),
        $schema_restore_keyless_fk_stale_identity,
        'failed keyless FK restore dry-run rolls back source sidecar adoption'
    );
    assert_same(
        (int)scalar($schema_restore_keyless_fk_rollback_metadata, "SELECT COUNT(*) FROM merge_row_identity_history WHERE branch_name = 'main' AND table_name = 'plugin_restore_keyless_fk_parent' AND logical_identity = '" . SQLite3::escapeString((string)$schema_restore_keyless_fk_source_identity) . "'"),
        $schema_restore_keyless_fk_source_history_before_failed_preview,
        'failed keyless FK restore dry-run rolls back source sidecar history writes'
    );
    $schema_restore_keyless_fk_source_history_before_failed_apply = (int)scalar(
        $schema_restore_keyless_fk_rollback_metadata,
        "SELECT COUNT(*) FROM merge_row_identity_history WHERE branch_name = 'main' AND table_name = 'plugin_restore_keyless_fk_parent' AND logical_identity = '" . SQLite3::escapeString((string)$schema_restore_keyless_fk_source_identity) . "'"
    );
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $schema_restore_keyless_fk_rollback_metadata,
            $schema_restore_keyless_fk_rollback_conflict_id,
            'source',
            true,
            'Apply keyless FK parent restore with missing source unique index.',
            'test'
        ),
        'foreign-key validation error',
        'failed apply rolls back keyless FK parent restore after source sidecar staging'
    );
    assert_same(
        (int)scalar($schema_restore_keyless_fk_rollback_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_restore_keyless_fk_rollback_conflict_id"),
        0,
        'failed keyless FK restore apply does not record resolution metadata'
    );
    assert_same(
        scalar($schema_restore_keyless_fk_rollback_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_restore_keyless_fk_parent' AND rowid = 9"),
        $schema_restore_keyless_fk_stale_identity,
        'failed keyless FK restore apply rolls back source sidecar adoption'
    );
    assert_same(
        (int)scalar($schema_restore_keyless_fk_rollback_metadata, "SELECT COUNT(*) FROM merge_row_identity_history WHERE branch_name = 'main' AND table_name = 'plugin_restore_keyless_fk_parent' AND logical_identity = '" . SQLite3::escapeString((string)$schema_restore_keyless_fk_source_identity) . "'"),
        $schema_restore_keyless_fk_source_history_before_failed_apply,
        'failed keyless FK restore apply rolls back source sidecar history writes'
    );

    $db = open_db($schema_restore_keyless_fk_rollback_target);
    $db->exec('DROP TABLE plugin_restore_keyless_fk_child');
    $db->close();
    $schema_restore_keyless_fk_rollback_apply = cow_merge_resolve_conflict(
        $schema_restore_keyless_fk_rollback_metadata,
        $schema_restore_keyless_fk_rollback_conflict_id,
        'source',
        true,
        'Apply keyless FK parent restore after dependent child schema review.',
        'test'
    );
    assert_same($schema_restore_keyless_fk_rollback_apply['status'], 'applied', 'keyless FK parent restore applies after dependent child schema is handled');
    assert_same(scalar($schema_restore_keyless_fk_rollback_target, "SELECT label FROM plugin_restore_keyless_fk_parent WHERE rowid = 9"), 'source keyless FK label', 'validated keyless FK restore preserves the audited source rowid and payload');
    assert_same(
        scalar($schema_restore_keyless_fk_rollback_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_restore_keyless_fk_parent' AND rowid = 9"),
        $schema_restore_keyless_fk_source_identity,
        'validated keyless FK restore adopts source sidecar identity after rollback-sensitive apply succeeds'
    );
    assert_same(
        (int)scalar($schema_restore_keyless_fk_rollback_metadata, "SELECT COUNT(*) FROM merge_row_identity_history WHERE branch_name = 'main' AND table_name = 'plugin_restore_keyless_fk_parent' AND logical_identity = '" . SQLite3::escapeString((string)$schema_restore_keyless_fk_stale_identity) . "' AND deleted_at IS NOT NULL"),
        1,
        'validated keyless FK restore tombstones stale target sidecar identity after successful apply'
    );

    $schema_cross_fk_restored_parent_base = $tmp . '/schema-cross-fk-restored-parent-base.sqlite';
    $schema_cross_fk_restored_parent_source = $tmp . '/schema-cross-fk-restored-parent-source.sqlite';
    $schema_cross_fk_restored_parent_target = $tmp . '/schema-cross-fk-restored-parent-target.sqlite';
    $schema_cross_fk_restored_parent_metadata = $tmp . '/.forkpress/cow/merge/schema-cross-fk-restored-parent-metadata.sqlite';
    create_base_db($schema_cross_fk_restored_parent_base);
    $db = open_db($schema_cross_fk_restored_parent_base);
    $db->exec('CREATE TABLE plugin_cross_parent_restore (code TEXT PRIMARY KEY, label TEXT)');
    $db->exec('CREATE TABLE plugin_cross_child_restore_parent (parent_code TEXT NOT NULL REFERENCES plugin_cross_parent_restore(code), label TEXT)');
    $db->close();
    copy($schema_cross_fk_restored_parent_base, $schema_cross_fk_restored_parent_source);
    copy($schema_cross_fk_restored_parent_base, $schema_cross_fk_restored_parent_target);
    cow_merge_capture_row_identities($schema_cross_fk_restored_parent_base, $schema_cross_fk_restored_parent_metadata, 'main');
    cow_merge_capture_row_identities($schema_cross_fk_restored_parent_source, $schema_cross_fk_restored_parent_metadata, 'feature-cross-fk-restored-parent', 'main');
    cow_merge_capture_row_identities($schema_cross_fk_restored_parent_target, $schema_cross_fk_restored_parent_metadata, 'main');
    $db = open_db($schema_cross_fk_restored_parent_source);
    $db->exec("INSERT INTO plugin_cross_parent_restore (code, label) VALUES ('restored-parent', 'restored parent')");
    $db->exec("INSERT INTO plugin_cross_child_restore_parent (rowid, parent_code, label) VALUES (5, 'restored-parent', 'restored child')");
    $db->close();
    cow_merge_capture_row_identities($schema_cross_fk_restored_parent_source, $schema_cross_fk_restored_parent_metadata, 'feature-cross-fk-restored-parent', 'main');
    $schema_cross_fk_restored_parent_child_identity = scalar($schema_cross_fk_restored_parent_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'feature-cross-fk-restored-parent' AND table_name = 'plugin_cross_child_restore_parent' AND rowid = 5");
    $db = open_db($schema_cross_fk_restored_parent_target);
    $db->exec('DROP TABLE plugin_cross_child_restore_parent');
    $db->exec('DROP TABLE plugin_cross_parent_restore');
    $db->close();
    $schema_cross_fk_restored_parent_result = cow_merge_databases(
        $schema_cross_fk_restored_parent_base,
        $schema_cross_fk_restored_parent_source,
        $schema_cross_fk_restored_parent_target,
        $schema_cross_fk_restored_parent_metadata,
        'feature-cross-fk-restored-parent',
        'main'
    );
    assert_same($schema_cross_fk_restored_parent_result['status'], 'completed_with_conflicts', 'target-dropped cross-table FK tables record schema conflicts');
    $schema_cross_fk_restored_parent_parent_conflict_id = (int)scalar($schema_cross_fk_restored_parent_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_cross_parent_restore' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    $schema_cross_fk_restored_parent_child_conflict_id = (int)scalar($schema_cross_fk_restored_parent_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_cross_child_restore_parent' AND conflict_type = 'schema-target-dropped-table' ORDER BY id DESC LIMIT 1");
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $schema_cross_fk_restored_parent_metadata,
            $schema_cross_fk_restored_parent_child_conflict_id,
            'source',
            false,
            'Preview child restore before parent table.',
            'test'
        ),
        'requires parent table plugin_cross_parent_restore',
        'source child table restore reports the missing cross-table parent dependency before mutation'
    );
    assert_same(
        (int)scalar($schema_cross_fk_restored_parent_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_cross_fk_restored_parent_child_conflict_id"),
        0,
        'failed child-before-parent restore preview does not record a resolution'
    );
    assert_same(
        (int)scalar($schema_cross_fk_restored_parent_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_cross_child_restore_parent'"),
        0,
        'failed child-before-parent restore preview leaves the target child table absent'
    );
    $db = open_db($schema_cross_fk_restored_parent_target);
    $db->exec('CREATE TABLE plugin_cross_parent_restore (code TEXT PRIMARY KEY, label TEXT)');
    $db->close();
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $schema_cross_fk_restored_parent_metadata,
            $schema_cross_fk_restored_parent_child_conflict_id,
            'source',
            false,
            'Preview child restore before parent row.',
            'test'
        ),
        'requires parent row in plugin_cross_parent_restore',
        'source child table restore preview reports the missing cross-table parent row before mutation'
    );
    assert_throws(
        fn() => cow_merge_resolve_conflict(
            $schema_cross_fk_restored_parent_metadata,
            $schema_cross_fk_restored_parent_child_conflict_id,
            'source',
            true,
            'Apply child restore before parent row.',
            'test'
        ),
        'requires parent row in plugin_cross_parent_restore',
        'source child table restore apply reports the missing cross-table parent row before mutation'
    );
    assert_same(
        (int)scalar($schema_cross_fk_restored_parent_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_cross_fk_restored_parent_child_conflict_id"),
        0,
        'failed child-before-parent-row restore attempts do not record a resolution'
    );
    assert_same(
        (int)scalar($schema_cross_fk_restored_parent_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_cross_child_restore_parent'"),
        0,
        'failed child-before-parent-row restore attempts leave the target child table absent'
    );
    $db = open_db($schema_cross_fk_restored_parent_target);
    $db->exec('DROP TABLE plugin_cross_parent_restore');
    $db->close();
    $schema_cross_fk_restored_parent_parent_resolution = cow_merge_resolve_conflict(
        $schema_cross_fk_restored_parent_metadata,
        $schema_cross_fk_restored_parent_parent_conflict_id,
        'source',
        true,
        'Restore source parent table first.',
        'test'
    );
    assert_same($schema_cross_fk_restored_parent_parent_resolution['status'], 'applied', 'source parent table restore applies before dependent child restore');
    $schema_cross_fk_restored_parent_child_resolution = cow_merge_resolve_conflict(
        $schema_cross_fk_restored_parent_metadata,
        $schema_cross_fk_restored_parent_child_conflict_id,
        'source',
        true,
        'Restore source child table after parent table.',
        'test'
    );
    assert_same($schema_cross_fk_restored_parent_child_resolution['status'], 'applied', 'source child table restore applies after restored parent table');
    assert_same(scalar($schema_cross_fk_restored_parent_target, "SELECT label FROM plugin_cross_parent_restore WHERE code = 'restored-parent'"), 'restored parent', 'restored parent table keeps the audited source row');
    assert_same(scalar($schema_cross_fk_restored_parent_target, "SELECT label FROM plugin_cross_child_restore_parent WHERE rowid = 5"), 'restored child', 'restored child table validates against the restored parent table');
    assert_same(
        scalar($schema_cross_fk_restored_parent_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_cross_child_restore_parent' AND rowid = 5"),
        $schema_cross_fk_restored_parent_child_identity,
        'cross-table restored child adopts the source no-primary-key sidecar identity'
    );
    $schema_cross_fk_restored_parent_rerun = cow_merge_databases(
        $schema_cross_fk_restored_parent_base,
        $schema_cross_fk_restored_parent_source,
        $schema_cross_fk_restored_parent_target,
        $schema_cross_fk_restored_parent_metadata,
        'feature-cross-fk-restored-parent',
        'main'
    );
    assert_same($schema_cross_fk_restored_parent_rerun['status'], 'completed', 'rerunning after cross-table parent and child restores completes without new conflicts');
    assert_same(
        (int)scalar($schema_cross_fk_restored_parent_metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.conflict_type = 'schema-target-dropped-table' AND r.source_branch = 'feature-cross-fk-restored-parent'"),
        2,
        'rerunning after cross-table parent and child restores does not rediscover resolved schema conflicts'
    );

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

    $schema_table_drop_trigger_ref_base = $tmp . '/schema-table-drop-trigger-ref-base.sqlite';
    $schema_table_drop_trigger_ref_source = $tmp . '/schema-table-drop-trigger-ref-source.sqlite';
    $schema_table_drop_trigger_ref_target = $tmp . '/schema-table-drop-trigger-ref-target.sqlite';
    create_base_db($schema_table_drop_trigger_ref_base);
    $db = open_db($schema_table_drop_trigger_ref_base);
    $db->exec('CREATE TABLE plugin_table_drop_trigger_ref (item_id TEXT PRIMARY KEY, label TEXT)');
    $db->exec('CREATE TABLE plugin_table_drop_trigger_ref_observer (item_id TEXT PRIMARY KEY)');
    $db->exec('CREATE TABLE plugin_table_drop_trigger_ref_audit (item_id TEXT, label TEXT)');
    $db->exec(<<<'SQL'
CREATE TRIGGER plugin_table_drop_trigger_ref_observer_insert
AFTER INSERT ON plugin_table_drop_trigger_ref_observer
BEGIN
    INSERT INTO plugin_table_drop_trigger_ref_audit (item_id, label)
    SELECT item_id, label FROM plugin_table_drop_trigger_ref WHERE item_id = NEW.item_id;
END
SQL);
    $db->close();
    copy($schema_table_drop_trigger_ref_base, $schema_table_drop_trigger_ref_source);
    copy($schema_table_drop_trigger_ref_base, $schema_table_drop_trigger_ref_target);

    $db = open_db($schema_table_drop_trigger_ref_source);
    $db->exec('DROP TRIGGER plugin_table_drop_trigger_ref_observer_insert');
    $db->exec('DROP TABLE plugin_table_drop_trigger_ref');
    $db->close();

    $result = cow_merge_databases($schema_table_drop_trigger_ref_base, $schema_table_drop_trigger_ref_source, $schema_table_drop_trigger_ref_target, $metadata, 'feature-table-drop-trigger-ref', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'source table drop with dependent target trigger body remains reviewable');
    $schema_table_drop_trigger_ref_table_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_table_drop_trigger_ref' AND column_name IS NULL AND conflict_type = 'schema-source-dropped-table' ORDER BY id DESC LIMIT 1");
    $schema_table_drop_trigger_ref_trigger_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_table_drop_trigger_ref_observer_insert' AND conflict_type = 'schema-source-dropped-trigger' ORDER BY id DESC LIMIT 1");
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $schema_table_drop_trigger_ref_table_conflict_id, 'source', false, 'Preview table drop before trigger body dependency.', 'test'),
        'dependent target trigger programs',
        'source table drop preview refuses to leave target trigger body invalid'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_table_drop_trigger_ref_table_conflict_id"),
        0,
        'failed table drop trigger-body preview does not record a resolution'
    );
    assert_same((int)scalar($schema_table_drop_trigger_ref_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_table_drop_trigger_ref'"), 1, 'blocked trigger-body table drop preserves target table');
    assert_same((int)scalar($schema_table_drop_trigger_ref_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_table_drop_trigger_ref_observer_insert'"), 1, 'blocked trigger-body table drop preserves target trigger');
    cow_merge_resolve_conflict(
        $metadata,
        $schema_table_drop_trigger_ref_trigger_conflict_id,
        'source',
        true,
        'Apply source trigger body dependency drop.',
        'test'
    );
    $schema_table_drop_trigger_ref_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_table_drop_trigger_ref_table_conflict_id,
        'source',
        true,
        'Apply table drop after trigger body dependency.',
        'test'
    );
    assert_same($schema_table_drop_trigger_ref_resolution['status'], 'applied', 'source table drop applies after dependent trigger body is resolved');
    assert_same((int)scalar($schema_table_drop_trigger_ref_target, "SELECT COUNT(*) FROM sqlite_master WHERE name IN ('plugin_table_drop_trigger_ref', 'plugin_table_drop_trigger_ref_observer_insert')"), 0, 'source table drop removes table after dependent trigger body is resolved');

    $schema_table_drop_view_trigger_chain_base = $tmp . '/schema-table-drop-view-trigger-chain-base.sqlite';
    $schema_table_drop_view_trigger_chain_source = $tmp . '/schema-table-drop-view-trigger-chain-source.sqlite';
    $schema_table_drop_view_trigger_chain_target = $tmp . '/schema-table-drop-view-trigger-chain-target.sqlite';
    create_base_db($schema_table_drop_view_trigger_chain_base);
    $db = open_db($schema_table_drop_view_trigger_chain_base);
    $db->exec('CREATE TABLE plugin_table_drop_view_trigger_chain (item_id TEXT PRIMARY KEY, label TEXT)');
    $db->exec('CREATE VIEW plugin_table_drop_view_trigger_chain_live AS SELECT item_id, label FROM plugin_table_drop_view_trigger_chain');
    $db->exec('CREATE TABLE plugin_table_drop_view_trigger_chain_observer (item_id TEXT PRIMARY KEY)');
    $db->exec('CREATE TABLE plugin_table_drop_view_trigger_chain_audit (item_id TEXT, label TEXT)');
    $db->exec(<<<'SQL'
CREATE TRIGGER plugin_table_drop_view_trigger_chain_observer_insert
AFTER INSERT ON plugin_table_drop_view_trigger_chain_observer
BEGIN
    INSERT INTO plugin_table_drop_view_trigger_chain_audit (item_id, label)
    SELECT item_id, label FROM plugin_table_drop_view_trigger_chain_live WHERE item_id = NEW.item_id;
END
SQL);
    $db->close();
    copy($schema_table_drop_view_trigger_chain_base, $schema_table_drop_view_trigger_chain_source);
    copy($schema_table_drop_view_trigger_chain_base, $schema_table_drop_view_trigger_chain_target);

    $db = open_db($schema_table_drop_view_trigger_chain_source);
    $db->exec('DROP TRIGGER plugin_table_drop_view_trigger_chain_observer_insert');
    $db->exec('DROP VIEW plugin_table_drop_view_trigger_chain_live');
    $db->exec('DROP TABLE plugin_table_drop_view_trigger_chain');
    $db->close();

    $result = cow_merge_databases(
        $schema_table_drop_view_trigger_chain_base,
        $schema_table_drop_view_trigger_chain_source,
        $schema_table_drop_view_trigger_chain_target,
        $metadata,
        'feature-table-drop-view-trigger-chain',
        'main'
    );
    assert_same($result['status'], 'completed_with_conflicts', 'source table/view drops with dependent trigger body remain reviewable');
    $schema_table_drop_view_trigger_chain_table_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_table_drop_view_trigger_chain' AND column_name IS NULL AND conflict_type = 'schema-source-dropped-table' ORDER BY id DESC LIMIT 1");
    $schema_table_drop_view_trigger_chain_view_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_table_drop_view_trigger_chain_live' AND conflict_type = 'schema-source-dropped-view' ORDER BY id DESC LIMIT 1");
    $schema_table_drop_view_trigger_chain_trigger_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_table_drop_view_trigger_chain_observer_insert' AND conflict_type = 'schema-source-dropped-trigger' ORDER BY id DESC LIMIT 1");
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $schema_table_drop_view_trigger_chain_table_conflict_id, 'source', false, 'Preview table drop before dependent view.', 'test'),
        'dependent target views',
        'source table drop preview refuses to leave the dependent target view invalid'
    );
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $schema_table_drop_view_trigger_chain_view_conflict_id, 'source', false, 'Preview view drop before trigger body dependency.', 'test'),
        'dependent target trigger programs',
        'source view drop preview refuses to leave target trigger body invalid'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_table_drop_view_trigger_chain_view_conflict_id"),
        0,
        'failed view drop trigger-body preview does not record a resolution'
    );
    assert_same((int)scalar($schema_table_drop_view_trigger_chain_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name = 'plugin_table_drop_view_trigger_chain_live'"), 1, 'blocked trigger-body view drop preserves target view');
    assert_same((int)scalar($schema_table_drop_view_trigger_chain_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_table_drop_view_trigger_chain_observer_insert'"), 1, 'blocked trigger-body view drop preserves target trigger');
    cow_merge_resolve_conflict(
        $metadata,
        $schema_table_drop_view_trigger_chain_trigger_conflict_id,
        'source',
        true,
        'Apply source trigger body dependency drop.',
        'test'
    );
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $schema_table_drop_view_trigger_chain_table_conflict_id, 'source', false, 'Preview table drop before view drop.', 'test'),
        'dependent target views',
        'source table drop remains blocked until the dependent view is resolved'
    );
    $schema_table_drop_view_trigger_chain_view_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_table_drop_view_trigger_chain_view_conflict_id,
        'source',
        true,
        'Apply source view drop after trigger dependency.',
        'test'
    );
    assert_same($schema_table_drop_view_trigger_chain_view_resolution['status'], 'applied', 'source view drop applies after dependent trigger body is resolved');
    $schema_table_drop_view_trigger_chain_table_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_table_drop_view_trigger_chain_table_conflict_id,
        'source',
        true,
        'Apply table drop after view and trigger dependencies.',
        'test'
    );
    assert_same($schema_table_drop_view_trigger_chain_table_resolution['status'], 'applied', 'source table drop applies after dependent view and trigger chain is resolved');
    assert_same(
        (int)scalar($schema_table_drop_view_trigger_chain_target, "SELECT COUNT(*) FROM sqlite_master WHERE name IN ('plugin_table_drop_view_trigger_chain', 'plugin_table_drop_view_trigger_chain_live', 'plugin_table_drop_view_trigger_chain_observer_insert')"),
        0,
        'source table/view/trigger chain drops all resolved schema objects'
    );
    $schema_table_drop_view_trigger_chain_rerun = cow_merge_databases(
        $schema_table_drop_view_trigger_chain_base,
        $schema_table_drop_view_trigger_chain_source,
        $schema_table_drop_view_trigger_chain_target,
        $metadata,
        'feature-table-drop-view-trigger-chain',
        'main'
    );
    assert_same($schema_table_drop_view_trigger_chain_rerun['status'], 'completed', 'rerunning after table/view/trigger chain resolution completes without new conflicts');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE r.source_branch = 'feature-table-drop-view-trigger-chain'"),
        3,
        'rerunning after table/view/trigger chain resolution does not rediscover resolved schema conflicts'
    );

    $schema_view_drop_child_trigger_chain_base = $tmp . '/schema-view-drop-child-trigger-chain-base.sqlite';
    $schema_view_drop_child_trigger_chain_source = $tmp . '/schema-view-drop-child-trigger-chain-source.sqlite';
    $schema_view_drop_child_trigger_chain_target = $tmp . '/schema-view-drop-child-trigger-chain-target.sqlite';
    create_base_db($schema_view_drop_child_trigger_chain_base);
    $db = open_db($schema_view_drop_child_trigger_chain_base);
    $db->exec('CREATE TABLE plugin_view_drop_child_trigger_chain_items (item_id TEXT PRIMARY KEY, label TEXT)');
    $db->exec('CREATE TABLE plugin_view_drop_child_trigger_chain_audit (item_id TEXT, label TEXT)');
    $db->exec('CREATE VIEW plugin_view_drop_child_trigger_chain_parent AS SELECT item_id, label FROM plugin_view_drop_child_trigger_chain_items');
    $db->exec('CREATE VIEW plugin_view_drop_child_trigger_chain_child AS SELECT item_id, label FROM plugin_view_drop_child_trigger_chain_parent');
    $db->exec(<<<'SQL'
CREATE TRIGGER plugin_view_drop_child_trigger_chain_child_insert
INSTEAD OF INSERT ON plugin_view_drop_child_trigger_chain_child
BEGIN
    INSERT INTO plugin_view_drop_child_trigger_chain_audit (item_id, label) VALUES (NEW.item_id, NEW.label);
END
SQL);
    $db->close();
    copy($schema_view_drop_child_trigger_chain_base, $schema_view_drop_child_trigger_chain_source);
    copy($schema_view_drop_child_trigger_chain_base, $schema_view_drop_child_trigger_chain_target);

    $db = open_db($schema_view_drop_child_trigger_chain_source);
    $db->exec('DROP TRIGGER plugin_view_drop_child_trigger_chain_child_insert');
    $db->exec('DROP VIEW plugin_view_drop_child_trigger_chain_child');
    $db->exec('DROP VIEW plugin_view_drop_child_trigger_chain_parent');
    $db->close();

    $result = cow_merge_databases(
        $schema_view_drop_child_trigger_chain_base,
        $schema_view_drop_child_trigger_chain_source,
        $schema_view_drop_child_trigger_chain_target,
        $metadata,
        'feature-view-drop-child-trigger-chain',
        'main'
    );
    assert_same($result['status'], 'completed_with_conflicts', 'source view drops with dependent child view trigger chain remain reviewable');
    $schema_view_drop_child_trigger_chain_parent_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_view_drop_child_trigger_chain_parent' AND conflict_type = 'schema-source-dropped-view' ORDER BY id DESC LIMIT 1");
    $schema_view_drop_child_trigger_chain_child_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_view_drop_child_trigger_chain_child' AND conflict_type = 'schema-source-dropped-view' ORDER BY id DESC LIMIT 1");
    $schema_view_drop_child_trigger_chain_trigger_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_view_drop_child_trigger_chain_child_insert' AND conflict_type = 'schema-source-dropped-trigger' ORDER BY id DESC LIMIT 1");
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $schema_view_drop_child_trigger_chain_parent_conflict_id, 'source', false, 'Preview parent view drop before child view.', 'test'),
        'dependent target views',
        'source parent view drop preview refuses to leave dependent child view invalid'
    );
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $schema_view_drop_child_trigger_chain_child_conflict_id, 'source', false, 'Preview child view drop before attached trigger.', 'test'),
        'dependent target schema objects',
        'source child view drop preview refuses to implicitly remove its target trigger'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id IN ($schema_view_drop_child_trigger_chain_parent_conflict_id, $schema_view_drop_child_trigger_chain_child_conflict_id)"),
        0,
        'failed view-chain previews do not record resolutions'
    );
    assert_same((int)scalar($schema_view_drop_child_trigger_chain_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'view' AND name IN ('plugin_view_drop_child_trigger_chain_parent', 'plugin_view_drop_child_trigger_chain_child')"), 2, 'blocked view-chain drops preserve target views');
    assert_same((int)scalar($schema_view_drop_child_trigger_chain_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name = 'plugin_view_drop_child_trigger_chain_child_insert'"), 1, 'blocked child view drop preserves target trigger');
    cow_merge_resolve_conflict(
        $metadata,
        $schema_view_drop_child_trigger_chain_trigger_conflict_id,
        'source',
        true,
        'Apply source child view trigger drop.',
        'test'
    );
    $schema_view_drop_child_trigger_chain_child_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_view_drop_child_trigger_chain_child_conflict_id,
        'source',
        true,
        'Apply source child view drop after trigger.',
        'test'
    );
    assert_same($schema_view_drop_child_trigger_chain_child_resolution['status'], 'applied', 'source child view drop applies after attached trigger is resolved');
    $schema_view_drop_child_trigger_chain_parent_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_view_drop_child_trigger_chain_parent_conflict_id,
        'source',
        true,
        'Apply source parent view drop after child.',
        'test'
    );
    assert_same($schema_view_drop_child_trigger_chain_parent_resolution['status'], 'applied', 'source parent view drop applies after dependent child view is resolved');
    assert_same(
        (int)scalar($schema_view_drop_child_trigger_chain_target, "SELECT COUNT(*) FROM sqlite_master WHERE name IN ('plugin_view_drop_child_trigger_chain_parent', 'plugin_view_drop_child_trigger_chain_child', 'plugin_view_drop_child_trigger_chain_child_insert')"),
        0,
        'source parent view, child view, and child trigger chain drops all resolved schema objects'
    );
    $schema_view_drop_child_trigger_chain_rerun = cow_merge_databases(
        $schema_view_drop_child_trigger_chain_base,
        $schema_view_drop_child_trigger_chain_source,
        $schema_view_drop_child_trigger_chain_target,
        $metadata,
        'feature-view-drop-child-trigger-chain',
        'main'
    );
    assert_same($schema_view_drop_child_trigger_chain_rerun['status'], 'completed', 'rerunning after view/trigger chain resolution completes without new conflicts');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE r.source_branch = 'feature-view-drop-child-trigger-chain'"),
        3,
        'rerunning after view/trigger chain resolution does not rediscover resolved schema conflicts'
    );

    $schema_table_drop_fk_base = $tmp . '/schema-table-drop-fk-base.sqlite';
    $schema_table_drop_fk_source = $tmp . '/schema-table-drop-fk-source.sqlite';
    $schema_table_drop_fk_target = $tmp . '/schema-table-drop-fk-target.sqlite';
    create_base_db($schema_table_drop_fk_base);
    $db = open_db($schema_table_drop_fk_base);
    $db->exec('CREATE TABLE plugin_table_drop_fk_parent (item_id TEXT PRIMARY KEY, label TEXT)');
    $db->exec('CREATE TABLE plugin_table_drop_fk_child (parent_id TEXT REFERENCES plugin_table_drop_fk_parent(item_id), label TEXT)');
    $db->close();
    copy($schema_table_drop_fk_base, $schema_table_drop_fk_source);
    copy($schema_table_drop_fk_base, $schema_table_drop_fk_target);

    $db = open_db($schema_table_drop_fk_source);
    $db->exec('DROP TABLE plugin_table_drop_fk_child');
    $db->exec('DROP TABLE plugin_table_drop_fk_parent');
    $db->close();

    $result = cow_merge_databases($schema_table_drop_fk_base, $schema_table_drop_fk_source, $schema_table_drop_fk_target, $metadata, 'feature-table-drop-fk', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'source table drops with dependent FK child tables remain reviewable');
    $schema_table_drop_fk_parent_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_table_drop_fk_parent' AND column_name IS NULL AND conflict_type = 'schema-source-dropped-table' ORDER BY id DESC LIMIT 1");
    $schema_table_drop_fk_child_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_table_drop_fk_child' AND column_name IS NULL AND conflict_type = 'schema-source-dropped-table' ORDER BY id DESC LIMIT 1");
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $schema_table_drop_fk_parent_conflict_id, 'source', false, 'Preview parent drop before FK child drop.', 'test'),
        'dependent target foreign-key child tables',
        'source table drop preview refuses to leave dependent FK child schema invalid'
    );
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $schema_table_drop_fk_parent_conflict_id, 'source', true, 'Apply parent drop before FK child drop.', 'test'),
        'dependent target foreign-key child tables',
        'source table drop apply refuses to leave dependent FK child schema invalid'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $schema_table_drop_fk_parent_conflict_id"),
        0,
        'failed parent-before-child table drop attempts do not record a resolution'
    );
    assert_same((int)scalar($schema_table_drop_fk_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_table_drop_fk_parent'"), 1, 'failed parent-before-child table drop preserves parent table');
    assert_same((int)scalar($schema_table_drop_fk_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_table_drop_fk_child'"), 1, 'failed parent-before-child table drop preserves child table');
    $schema_table_drop_fk_child_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_table_drop_fk_child_conflict_id,
        'source',
        true,
        'Apply source child table drop before parent.',
        'test'
    );
    assert_same($schema_table_drop_fk_child_resolution['status'], 'applied', 'source child table drop applies before parent drop');
    $schema_table_drop_fk_parent_preview = cow_merge_resolve_conflict(
        $metadata,
        $schema_table_drop_fk_parent_conflict_id,
        'source',
        false,
        'Preview source parent table drop after child.',
        'test'
    );
    assert_same($schema_table_drop_fk_parent_preview['status'], 'validated', 'source parent table drop validates after dependent child drop');
    assert_same((int)scalar($schema_table_drop_fk_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_table_drop_fk_parent'"), 1, 'source parent table drop preview does not mutate target');
    $schema_table_drop_fk_parent_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_table_drop_fk_parent_conflict_id,
        'source',
        true,
        'Apply source parent table drop after child.',
        'test'
    );
    assert_same($schema_table_drop_fk_parent_resolution['status'], 'applied', 'source parent table drop applies after dependent child drop');
    assert_same((int)scalar($schema_table_drop_fk_target, "SELECT COUNT(*) FROM sqlite_master WHERE name IN ('plugin_table_drop_fk_parent', 'plugin_table_drop_fk_child')"), 0, 'source FK parent and child table drops both apply after dependency ordering');

    $schema_mixed_drop_base = $tmp . '/schema-mixed-drop-base.sqlite';
    $schema_mixed_drop_source = $tmp . '/schema-mixed-drop-source.sqlite';
    $schema_mixed_drop_target = $tmp . '/schema-mixed-drop-target.sqlite';
    create_base_db($schema_mixed_drop_base);
    $db = open_db($schema_mixed_drop_base);
    $db->exec('CREATE TABLE plugin_mixed_drop_parent (item_id TEXT PRIMARY KEY, label TEXT)');
    $db->exec('CREATE TABLE plugin_mixed_drop_child (child_id TEXT PRIMARY KEY, parent_id TEXT REFERENCES plugin_mixed_drop_parent(item_id), label TEXT)');
    $db->exec('CREATE VIEW plugin_mixed_drop_child_live AS SELECT child_id, parent_id, label FROM plugin_mixed_drop_child');
    $db->exec('CREATE TABLE plugin_mixed_drop_observer (child_id TEXT PRIMARY KEY)');
    $db->exec('CREATE TABLE plugin_mixed_drop_audit (child_id TEXT, label TEXT)');
    $db->exec(<<<'SQL'
CREATE TRIGGER plugin_mixed_drop_observer_insert
AFTER INSERT ON plugin_mixed_drop_observer
BEGIN
    INSERT INTO plugin_mixed_drop_audit (child_id, label)
    SELECT child_id, label FROM plugin_mixed_drop_child_live WHERE child_id = NEW.child_id;
END
SQL);
    $db->close();
    copy($schema_mixed_drop_base, $schema_mixed_drop_source);
    copy($schema_mixed_drop_base, $schema_mixed_drop_target);

    $db = open_db($schema_mixed_drop_source);
    $db->exec('DROP TRIGGER plugin_mixed_drop_observer_insert');
    $db->exec('DROP VIEW plugin_mixed_drop_child_live');
    $db->exec('DROP TABLE plugin_mixed_drop_child');
    $db->exec('DROP TABLE plugin_mixed_drop_parent');
    $db->close();

    $result = cow_merge_databases($schema_mixed_drop_base, $schema_mixed_drop_source, $schema_mixed_drop_target, $metadata, 'feature-mixed-schema-drop-chain', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'mixed source table/view/trigger drops remain reviewable');
    $schema_mixed_drop_parent_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_mixed_drop_parent' AND column_name IS NULL AND conflict_type = 'schema-source-dropped-table' ORDER BY id DESC LIMIT 1");
    $schema_mixed_drop_child_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_mixed_drop_child' AND column_name IS NULL AND conflict_type = 'schema-source-dropped-table' ORDER BY id DESC LIMIT 1");
    $schema_mixed_drop_view_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_mixed_drop_child_live' AND conflict_type = 'schema-source-dropped-view' ORDER BY id DESC LIMIT 1");
    $schema_mixed_drop_trigger_conflict_id = (int)scalar($metadata, "SELECT id FROM merge_conflicts WHERE column_name = 'plugin_mixed_drop_observer_insert' AND conflict_type = 'schema-source-dropped-trigger' ORDER BY id DESC LIMIT 1");
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $schema_mixed_drop_parent_conflict_id, 'source', false, 'Preview parent before FK child table.', 'test'),
        'dependent target foreign-key child tables',
        'mixed schema parent table drop remains blocked by the FK child table'
    );
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $schema_mixed_drop_child_conflict_id, 'source', false, 'Preview child before dependent view.', 'test'),
        'dependent target views',
        'mixed schema child table drop remains blocked by its dependent view'
    );
    assert_throws(
        fn() => cow_merge_resolve_conflict($metadata, $schema_mixed_drop_view_conflict_id, 'source', false, 'Preview child view before trigger body dependency.', 'test'),
        'dependent target trigger programs',
        'mixed schema child view drop remains blocked by external trigger body references'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id IN ($schema_mixed_drop_parent_conflict_id, $schema_mixed_drop_child_conflict_id, $schema_mixed_drop_view_conflict_id)"),
        0,
        'failed mixed schema dependency previews do not record resolutions'
    );
    assert_same((int)scalar($schema_mixed_drop_target, "SELECT COUNT(*) FROM sqlite_master WHERE name IN ('plugin_mixed_drop_parent', 'plugin_mixed_drop_child', 'plugin_mixed_drop_child_live', 'plugin_mixed_drop_observer_insert')"), 4, 'blocked mixed schema drops preserve the target dependency chain');
    cow_merge_resolve_conflict(
        $metadata,
        $schema_mixed_drop_trigger_conflict_id,
        'source',
        true,
        'Apply source external trigger drop first.',
        'test'
    );
    $schema_mixed_drop_view_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_mixed_drop_view_conflict_id,
        'source',
        true,
        'Apply source child view drop after trigger.',
        'test'
    );
    assert_same($schema_mixed_drop_view_resolution['status'], 'applied', 'mixed schema child view drop applies after trigger body dependency is resolved');
    $schema_mixed_drop_child_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_mixed_drop_child_conflict_id,
        'source',
        true,
        'Apply source child table drop after view.',
        'test'
    );
    assert_same($schema_mixed_drop_child_resolution['status'], 'applied', 'mixed schema FK child table drop applies after dependent view is resolved');
    $schema_mixed_drop_parent_preview = cow_merge_resolve_conflict(
        $metadata,
        $schema_mixed_drop_parent_conflict_id,
        'source',
        false,
        'Preview source parent table drop after child.',
        'test'
    );
    assert_same($schema_mixed_drop_parent_preview['status'], 'validated', 'mixed schema parent table drop validates after FK child table is resolved');
    assert_same((int)scalar($schema_mixed_drop_target, "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'plugin_mixed_drop_parent'"), 1, 'mixed schema parent drop preview does not mutate target');
    $schema_mixed_drop_parent_resolution = cow_merge_resolve_conflict(
        $metadata,
        $schema_mixed_drop_parent_conflict_id,
        'source',
        true,
        'Apply source parent table drop after child.',
        'test'
    );
    assert_same($schema_mixed_drop_parent_resolution['status'], 'applied', 'mixed schema parent table drop applies after dependency chain is resolved');
    assert_same((int)scalar($schema_mixed_drop_target, "SELECT COUNT(*) FROM sqlite_master WHERE name IN ('plugin_mixed_drop_parent', 'plugin_mixed_drop_child', 'plugin_mixed_drop_child_live', 'plugin_mixed_drop_observer_insert')"), 0, 'mixed table/view/trigger source drops all apply in dependency order');
    $schema_mixed_drop_rerun = cow_merge_databases($schema_mixed_drop_base, $schema_mixed_drop_source, $schema_mixed_drop_target, $metadata, 'feature-mixed-schema-drop-chain', 'main');
    assert_same($schema_mixed_drop_rerun['status'], 'completed', 'rerunning after mixed schema drop resolution completes without new conflicts');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE r.source_branch = 'feature-mixed-schema-drop-chain'"),
        4,
        'rerunning after mixed schema drop resolution does not rediscover resolved conflicts'
    );

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
    assert_same($result['status'], 'completed_with_conflicts', 'untracked keyless base-row source-only changes are bounded when target also changed');
    assert_same(scalar($keyless_target, "SELECT label FROM plugin_keyless WHERE rowid = 1"), 'Target base label', 'target keyless base-row cell is preserved');
    assert_same(scalar($keyless_target, "SELECT value FROM plugin_keyless WHERE rowid = 1"), 'base', 'source keyless base-row cell is not mixed into a target-changed row without runtime identity events');
    assert_same((int)scalar($keyless_target, "SELECT COUNT(*) FROM plugin_keyless WHERE label IN ('Source keyless', 'Target keyless')"), 2, 'source and target keyless inserts are both present');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_keyless' AND conflict_type = 'row-insert-collision'"), 0, 'keyless insert rowid collisions are not recorded as same-row conflicts');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_keyless' AND c.conflict_type = 'row-identity-ambiguous' AND r.source_branch = 'feature-keyless'"), 1, 'keyless sidecar ambiguity is auditable when source-only cells would otherwise be mixed into a target-changed row');
    assert_true((int)scalar($metadata, "SELECT COUNT(*) FROM merge_row_identities WHERE table_name = 'plugin_keyless'") >= 3, 'keyless sidecar row identities are recorded outside the plugin table');

    cow_merge_databases($keyless_base, $keyless_source, $keyless_target, $metadata, 'feature-keyless', 'main');
    assert_same((int)scalar($keyless_target, "SELECT COUNT(*) FROM plugin_keyless WHERE label = 'Source keyless'"), 1, 'rerunning keyless merge does not duplicate the source insert');

    $keyless_auto_update_base = $tmp . '/keyless-auto-update-base.sqlite';
    $keyless_auto_update_source = $tmp . '/keyless-auto-update-source.sqlite';
    $keyless_auto_update_target = $tmp . '/keyless-auto-update-target.sqlite';
    $keyless_auto_update_metadata = $tmp . '/.forkpress/cow/merge/keyless-auto-update-metadata.sqlite';
    create_base_db($keyless_auto_update_base);
    copy($keyless_auto_update_base, $keyless_auto_update_source);
    copy($keyless_auto_update_base, $keyless_auto_update_target);
    $db = open_db($keyless_auto_update_source);
    $db->exec("UPDATE plugin_keyless SET label = 'Auto source label', value = 'auto source value' WHERE rowid = 1");
    $db->close();
    $keyless_auto_update_result = cow_merge_databases($keyless_auto_update_base, $keyless_auto_update_source, $keyless_auto_update_target, $keyless_auto_update_metadata, 'feature-keyless-auto-update', 'main');
    assert_same($keyless_auto_update_result['status'], 'completed', 'source-only no-primary-key row update applies cleanly');
    assert_same(scalar($keyless_auto_update_target, "SELECT value FROM plugin_keyless WHERE rowid = 1"), 'auto source value', 'source-only no-primary-key update mutates target');
    assert_same(
        scalar($keyless_auto_update_metadata, "SELECT row_hash FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_keyless' AND rowid = 1"),
        cow_merge_row_hash(['label' => 'Auto source label', 'value' => 'auto source value']),
        'source-only no-primary-key update refreshes the target sidecar row hash immediately'
    );

    $keyless_auto_delete_base = $tmp . '/keyless-auto-delete-base.sqlite';
    $keyless_auto_delete_source = $tmp . '/keyless-auto-delete-source.sqlite';
    $keyless_auto_delete_target = $tmp . '/keyless-auto-delete-target.sqlite';
    $keyless_auto_delete_metadata = $tmp . '/.forkpress/cow/merge/keyless-auto-delete-metadata.sqlite';
    create_base_db($keyless_auto_delete_base);
    copy($keyless_auto_delete_base, $keyless_auto_delete_source);
    copy($keyless_auto_delete_base, $keyless_auto_delete_target);
    $db = open_db($keyless_auto_delete_source);
    $db->exec('DELETE FROM plugin_keyless WHERE rowid = 1');
    $db->close();
    $keyless_auto_delete_result = cow_merge_databases($keyless_auto_delete_base, $keyless_auto_delete_source, $keyless_auto_delete_target, $keyless_auto_delete_metadata, 'feature-keyless-auto-delete', 'main');
    assert_same($keyless_auto_delete_result['status'], 'completed', 'source-only no-primary-key row delete applies cleanly');
    assert_same((int)scalar($keyless_auto_delete_target, 'SELECT COUNT(*) FROM plugin_keyless WHERE rowid = 1'), 0, 'source-only no-primary-key delete removes target row');
    assert_same((int)scalar($keyless_auto_delete_metadata, "SELECT COUNT(*) FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_keyless' AND rowid = 1"), 0, 'source-only no-primary-key delete clears active target sidecar identity');
    assert_same((int)scalar($keyless_auto_delete_metadata, "SELECT COUNT(*) FROM merge_row_identity_history WHERE branch_name = 'main' AND table_name = 'plugin_keyless' AND rowid = 1 AND deleted_at IS NOT NULL"), 1, 'source-only no-primary-key delete tombstones target sidecar history');
    $db = open_db($keyless_auto_delete_target);
    $db->exec("INSERT INTO plugin_keyless (label, value) VALUES ('Target replacement after auto delete', 'runtime replacement')");
    $db->close();
    assert_same((int)scalar($keyless_auto_delete_target, 'SELECT rowid FROM plugin_keyless'), 1, 'SQLite can reuse the no-primary-key rowid after automatic merge delete');
    cow_merge_track_row_identity_events(
        $keyless_auto_delete_target,
        $keyless_auto_delete_metadata,
        'main',
        [[
            'id' => 1,
            'table' => 'plugin_keyless',
            'op' => 'insert',
            'rowid' => 1,
            'row' => ['label' => 'Target replacement after auto delete', 'value' => 'runtime replacement'],
        ]]
    );
    $replacement_identity = cow_merge_decode_payload_json(
        (string)scalar($keyless_auto_delete_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_keyless' AND rowid = 1"),
        'replacement no-primary-key identity'
    );
    assert_same($replacement_identity['origin'] ?? null, 'runtime-insert', 'rowid reuse after automatic delete receives a fresh runtime sidecar identity');

    $keyless_same_cell_base = $tmp . '/keyless-same-cell-base.sqlite';
    $keyless_same_cell_source = $tmp . '/keyless-same-cell-source.sqlite';
    $keyless_same_cell_target = $tmp . '/keyless-same-cell-target.sqlite';
    create_base_db($keyless_same_cell_base);
    copy($keyless_same_cell_base, $keyless_same_cell_source);
    copy($keyless_same_cell_base, $keyless_same_cell_target);
    $db = open_db($keyless_same_cell_source);
    $db->exec("UPDATE plugin_keyless SET label = 'Shared keyless label' WHERE rowid = 1");
    $db->close();
    $db = open_db($keyless_same_cell_target);
    $db->exec("UPDATE plugin_keyless SET label = 'Shared keyless label', value = 'target-only keyless value' WHERE rowid = 1");
    $db->close();
    $keyless_same_cell_result = cow_merge_databases($keyless_same_cell_base, $keyless_same_cell_source, $keyless_same_cell_target, $metadata, 'feature-keyless-same-cell', 'main');
    assert_same($keyless_same_cell_result['status'], 'completed', 'same-cell no-primary-key merge uses sidecar identity without a review conflict');
    assert_same(scalar($keyless_same_cell_target, "SELECT label FROM plugin_keyless WHERE rowid = 1"), 'Shared keyless label', 'shared keyless cell value remains in target');
    assert_same(scalar($keyless_same_cell_target, "SELECT value FROM plugin_keyless WHERE rowid = 1"), 'target-only keyless value', 'target-only keyless cell remains preserved');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE d.table_name = 'plugin_keyless' AND d.column_name = 'label' AND d.decision = 'source-applied' AND d.reason = 'source and target changed cell to the same value' AND r.source_branch = 'feature-keyless-same-cell'"), 1, 'same-cell no-primary-key source change is auditable when sidecar identity lines up');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE d.table_name = 'plugin_keyless' AND d.column_name = 'value' AND d.decision = 'target-kept' AND d.reason = 'target changed cell and source did not change it' AND r.source_branch = 'feature-keyless-same-cell'"), 1, 'target-only keyless cell next to a shared cell remains auditable');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_keyless' AND r.source_branch = 'feature-keyless-same-cell'"), 0, 'same-cell no-primary-key merge does not create a false identity ambiguity');

    $keyless_same_update_base = $tmp . '/keyless-same-update-base.sqlite';
    $keyless_same_update_source = $tmp . '/keyless-same-update-source.sqlite';
    $keyless_same_update_target = $tmp . '/keyless-same-update-target.sqlite';
    create_base_db($keyless_same_update_base);
    copy($keyless_same_update_base, $keyless_same_update_source);
    copy($keyless_same_update_base, $keyless_same_update_target);
    foreach ([$keyless_same_update_source, $keyless_same_update_target] as $path) {
        $db = open_db($path);
        $db->exec("UPDATE plugin_keyless SET label = 'Shared keyless row label', value = 'shared keyless row value' WHERE rowid = 1");
        $db->close();
    }
    $keyless_same_update_result = cow_merge_databases($keyless_same_update_base, $keyless_same_update_source, $keyless_same_update_target, $metadata, 'feature-keyless-same-update', 'main');
    assert_same($keyless_same_update_result['status'], 'completed', 'identical no-primary-key source and target updates merge without a review conflict');
    assert_same(scalar($keyless_same_update_target, "SELECT value FROM plugin_keyless WHERE rowid = 1"), 'shared keyless row value', 'identical no-primary-key update leaves the shared payload in target');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE d.table_name = 'plugin_keyless' AND d.decision = 'source-applied' AND d.reason = 'source and target changed row to the same payload' AND r.source_branch = 'feature-keyless-same-update'"), 1, 'identical no-primary-key update is auditable when sidecar identity lines up');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_keyless' AND r.source_branch = 'feature-keyless-same-update'"), 0, 'identical no-primary-key update does not create a false identity ambiguity');

    $keyless_same_delete_base = $tmp . '/keyless-same-delete-base.sqlite';
    $keyless_same_delete_source = $tmp . '/keyless-same-delete-source.sqlite';
    $keyless_same_delete_target = $tmp . '/keyless-same-delete-target.sqlite';
    create_base_db($keyless_same_delete_base);
    copy($keyless_same_delete_base, $keyless_same_delete_source);
    copy($keyless_same_delete_base, $keyless_same_delete_target);
    foreach ([$keyless_same_delete_source, $keyless_same_delete_target] as $path) {
        $db = open_db($path);
        $db->exec('DELETE FROM plugin_keyless WHERE rowid = 1');
        $db->close();
    }
    $keyless_same_delete_result = cow_merge_databases($keyless_same_delete_base, $keyless_same_delete_source, $keyless_same_delete_target, $metadata, 'feature-keyless-same-delete', 'main');
    assert_same($keyless_same_delete_result['status'], 'completed', 'identical no-primary-key source and target deletes merge without a review conflict');
    assert_same((int)scalar($keyless_same_delete_target, 'SELECT COUNT(*) FROM plugin_keyless WHERE rowid = 1'), 0, 'identical no-primary-key delete keeps the row deleted');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE d.table_name = 'plugin_keyless' AND d.decision = 'source-applied' AND d.reason = 'source and target deleted row with the same identity' AND r.source_branch = 'feature-keyless-same-delete'"), 1, 'identical no-primary-key delete is auditable when sidecar identity lines up');
    assert_same((int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_keyless' AND r.source_branch = 'feature-keyless-same-delete'"), 0, 'identical no-primary-key delete does not create a false identity ambiguity');

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
    cow_merge_databases($keyless_conflict_base, $keyless_conflict_source, $keyless_conflict_target, $metadata, 'feature-keyless-conflict', 'main');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_keyless' AND c.column_name = 'value' AND c.conflict_type = 'cell-conflict' AND r.source_branch = 'feature-keyless-conflict'"),
        1,
        'rerunning after source keyless cell resolution does not rediscover the resolved no-PK conflict'
    );
    assert_same(scalar($keyless_conflict_target, "SELECT value FROM plugin_keyless WHERE rowid = 1"), 'source keyless conflict', 'rerunning after source keyless cell resolution keeps the audited source value');

    $keyless_unique_base = $tmp . '/keyless-unique-base.sqlite';
    $keyless_unique_source = $tmp . '/keyless-unique-source.sqlite';
    $keyless_unique_target = $tmp . '/keyless-unique-target.sqlite';
    $keyless_unique_metadata = $tmp . '/.forkpress/cow/merge/keyless-unique-metadata.sqlite';
    create_base_db($keyless_unique_base);
    copy($keyless_unique_base, $keyless_unique_source);
    copy($keyless_unique_base, $keyless_unique_target);
    foreach ([$keyless_unique_base, $keyless_unique_source, $keyless_unique_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_keyless_unique (slug TEXT UNIQUE, value TEXT)');
        $db->close();
    }
    cow_merge_capture_row_identities($keyless_unique_base, $keyless_unique_metadata, 'main');
    cow_merge_capture_row_identities($keyless_unique_source, $keyless_unique_metadata, 'feature-keyless-unique', 'main');

    $db = open_db($keyless_unique_source);
    $db->exec("INSERT INTO plugin_keyless_unique (slug, value) VALUES ('shared-keyless-slug', 'source unique keyless')");
    $db->close();
    cow_merge_capture_row_identities($keyless_unique_source, $keyless_unique_metadata, 'feature-keyless-unique', 'main');

    $db = open_db($keyless_unique_target);
    $db->exec("INSERT INTO plugin_keyless_unique (slug, value) VALUES ('shared-keyless-slug', 'target unique keyless')");
    $db->close();
    cow_merge_capture_row_identities($keyless_unique_target, $keyless_unique_metadata, 'main');

    $result = cow_merge_databases($keyless_unique_base, $keyless_unique_source, $keyless_unique_target, $keyless_unique_metadata, 'feature-keyless-unique', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'keyless source insert colliding with target unique key is audited');
    assert_same(scalar($keyless_unique_target, "SELECT value FROM plugin_keyless_unique WHERE slug = 'shared-keyless-slug'"), 'target unique keyless', 'target keyless unique row wins by default');
    $keyless_unique_conflict_id = (int)scalar($keyless_unique_metadata, "SELECT c.id FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_keyless_unique' AND c.conflict_type = 'row-unique-collision' AND r.source_branch = 'feature-keyless-unique' ORDER BY c.id DESC LIMIT 1");
    $keyless_unique_conflict_identity = scalar($keyless_unique_metadata, "SELECT row_identity FROM merge_conflicts WHERE id = $keyless_unique_conflict_id");
    $keyless_unique_resolution = cow_merge_resolve_conflict(
        $keyless_unique_metadata,
        $keyless_unique_conflict_id,
        'source',
        true,
        'Apply source keyless unique row.',
        'cow-test'
    );
    assert_same($keyless_unique_resolution['status'], 'applied', 'source keyless unique collision resolution records applied status');
    assert_same((int)scalar($keyless_unique_target, "SELECT COUNT(*) FROM plugin_keyless_unique WHERE slug = 'shared-keyless-slug'"), 1, 'source keyless unique collision resolution keeps the unique key singular');
    assert_same(scalar($keyless_unique_target, "SELECT value FROM plugin_keyless_unique WHERE slug = 'shared-keyless-slug'"), 'source unique keyless', 'source keyless unique collision resolution replaces target row payload');
    $keyless_unique_rowid = (int)scalar($keyless_unique_target, "SELECT rowid FROM plugin_keyless_unique WHERE slug = 'shared-keyless-slug'");
    $keyless_unique_plain_identity = cow_merge_plain_json(cow_merge_decode_payload_json($keyless_unique_conflict_identity, 'keyless unique row identity'));
    assert_same(scalar($keyless_unique_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_keyless_unique' AND rowid = $keyless_unique_rowid"), $keyless_unique_plain_identity, 'source keyless unique collision resolution moves the source sidecar identity to target');
    cow_merge_databases($keyless_unique_base, $keyless_unique_source, $keyless_unique_target, $keyless_unique_metadata, 'feature-keyless-unique', 'main');
    assert_same(
        (int)scalar($keyless_unique_metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_keyless_unique' AND c.conflict_type = 'row-unique-collision' AND r.source_branch = 'feature-keyless-unique'"),
        1,
        'rerunning after source keyless unique resolution does not rediscover the resolved unique collision'
    );
    assert_same((int)scalar($keyless_unique_target, "SELECT COUNT(*) FROM plugin_keyless_unique WHERE slug = 'shared-keyless-slug'"), 1, 'rerunning after source keyless unique resolution keeps the unique key singular');
    assert_same(scalar($keyless_unique_target, "SELECT value FROM plugin_keyless_unique WHERE slug = 'shared-keyless-slug'"), 'source unique keyless', 'rerunning after source keyless unique resolution keeps the audited source row');

    $keyless_unique_same_base = $tmp . '/keyless-unique-same-base.sqlite';
    $keyless_unique_same_source = $tmp . '/keyless-unique-same-source.sqlite';
    $keyless_unique_same_target = $tmp . '/keyless-unique-same-target.sqlite';
    $keyless_unique_same_metadata = $tmp . '/.forkpress/cow/merge/keyless-unique-same-metadata.sqlite';
    create_base_db($keyless_unique_same_base);
    copy($keyless_unique_same_base, $keyless_unique_same_source);
    copy($keyless_unique_same_base, $keyless_unique_same_target);
    foreach ([$keyless_unique_same_base, $keyless_unique_same_source, $keyless_unique_same_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_keyless_unique_same (slug TEXT UNIQUE, value TEXT)');
        $db->close();
    }
    cow_merge_capture_row_identities($keyless_unique_same_base, $keyless_unique_same_metadata, 'main');
    cow_merge_capture_row_identities($keyless_unique_same_source, $keyless_unique_same_metadata, 'feature-keyless-unique-same', 'main');

    $db = open_db($keyless_unique_same_source);
    $db->exec("INSERT INTO plugin_keyless_unique_same (slug, value) VALUES ('same-keyless-slug', 'same payload')");
    $db->close();
    cow_merge_capture_row_identities($keyless_unique_same_source, $keyless_unique_same_metadata, 'feature-keyless-unique-same', 'main');

    $db = open_db($keyless_unique_same_target);
    $db->exec("INSERT INTO plugin_keyless_unique_same (slug, value) VALUES ('same-keyless-slug', 'same payload')");
    $db->close();
    cow_merge_capture_row_identities($keyless_unique_same_target, $keyless_unique_same_metadata, 'main');

    $result = cow_merge_databases($keyless_unique_same_base, $keyless_unique_same_source, $keyless_unique_same_target, $keyless_unique_same_metadata, 'feature-keyless-unique-same', 'main');
    assert_same($result['status'], 'completed', 'identical keyless unique inserts merge without a review conflict');
    assert_same((int)scalar($keyless_unique_same_target, "SELECT COUNT(*) FROM plugin_keyless_unique_same WHERE slug = 'same-keyless-slug' AND value = 'same payload'"), 1, 'identical keyless unique insert is not duplicated');
    assert_same((int)scalar($keyless_unique_same_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_keyless_unique_same' AND conflict_type = 'row-unique-collision'"), 0, 'identical keyless unique insert does not record a unique collision conflict');
    assert_same((int)scalar($keyless_unique_same_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_keyless_unique_same' AND decision = 'source-applied' AND reason LIKE 'source inserted no-primary-key row already exists in target by unique index%'"), 1, 'identical keyless unique insert is still auditable as source-applied');
    $keyless_unique_same_rowid = (int)scalar($keyless_unique_same_target, "SELECT rowid FROM plugin_keyless_unique_same WHERE slug = 'same-keyless-slug'");
    $keyless_unique_same_source_identity = scalar($keyless_unique_same_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'feature-keyless-unique-same' AND table_name = 'plugin_keyless_unique_same'");
    assert_same(scalar($keyless_unique_same_metadata, "SELECT logical_identity FROM merge_row_identities WHERE branch_name = 'main' AND table_name = 'plugin_keyless_unique_same' AND rowid = $keyless_unique_same_rowid"), $keyless_unique_same_source_identity, 'identical keyless unique insert adopts the source sidecar identity onto the target row');
    cow_merge_databases($keyless_unique_same_base, $keyless_unique_same_source, $keyless_unique_same_target, $keyless_unique_same_metadata, 'feature-keyless-unique-same', 'main');
    assert_same((int)scalar($keyless_unique_same_target, "SELECT COUNT(*) FROM plugin_keyless_unique_same WHERE slug = 'same-keyless-slug' AND value = 'same payload'"), 1, 'rerunning identical keyless unique merge keeps one target row');
    assert_same((int)scalar($keyless_unique_same_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_keyless_unique_same' AND decision = 'source-applied' AND reason LIKE 'source inserted no-primary-key row already exists in target by unique index%'"), 1, 'rerunning identical keyless unique merge does not repeat the source-applied collapse decision');
    assert_same((int)scalar($keyless_unique_same_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_keyless_unique_same' AND reason = 'source inserted row already exists in target with the same identity and payload'"), 0, 'rerunning identical keyless unique merge does not add explicit-primary-key audit noise');

    $keyless_duplicate_base = $tmp . '/keyless-duplicate-base.sqlite';
    $keyless_duplicate_source = $tmp . '/keyless-duplicate-source.sqlite';
    $keyless_duplicate_target = $tmp . '/keyless-duplicate-target.sqlite';
    $keyless_duplicate_metadata = $tmp . '/.forkpress/cow/merge/keyless-duplicate-metadata.sqlite';
    create_base_db($keyless_duplicate_base);
    copy($keyless_duplicate_base, $keyless_duplicate_source);
    copy($keyless_duplicate_base, $keyless_duplicate_target);
    foreach ([$keyless_duplicate_base, $keyless_duplicate_source, $keyless_duplicate_target] as $path) {
        $db = open_db($path);
        $db->exec('CREATE TABLE plugin_keyless_duplicate (label TEXT, value TEXT)');
        $db->close();
    }
    cow_merge_capture_row_identities($keyless_duplicate_base, $keyless_duplicate_metadata, 'main');
    cow_merge_capture_row_identities($keyless_duplicate_source, $keyless_duplicate_metadata, 'feature-keyless-duplicate', 'main');

    $db = open_db($keyless_duplicate_source);
    $db->exec("INSERT INTO plugin_keyless_duplicate (label, value) VALUES ('same duplicate label', 'same duplicate value')");
    $db->close();
    cow_merge_capture_row_identities($keyless_duplicate_source, $keyless_duplicate_metadata, 'feature-keyless-duplicate', 'main');

    $db = open_db($keyless_duplicate_target);
    $db->exec("INSERT INTO plugin_keyless_duplicate (label, value) VALUES ('same duplicate label', 'same duplicate value')");
    $db->close();
    cow_merge_capture_row_identities($keyless_duplicate_target, $keyless_duplicate_metadata, 'main');

    $result = cow_merge_databases($keyless_duplicate_base, $keyless_duplicate_source, $keyless_duplicate_target, $keyless_duplicate_metadata, 'feature-keyless-duplicate', 'main');
    assert_same($result['status'], 'completed', 'identical keyless inserts without declared uniqueness merge as distinct rows');
    assert_same((int)scalar($keyless_duplicate_target, "SELECT COUNT(*) FROM plugin_keyless_duplicate WHERE label = 'same duplicate label' AND value = 'same duplicate value'"), 2, 'identical keyless rows without unique evidence are not collapsed');
    assert_same((int)scalar($keyless_duplicate_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_keyless_duplicate' AND decision = 'source-applied' AND reason = 'source inserted row and target did not change it'"), 1, 'source duplicate keyless insert is audited separately');
    assert_same((int)scalar($keyless_duplicate_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_keyless_duplicate' AND decision = 'target-kept' AND reason = 'target inserted row and source did not have it'"), 1, 'target duplicate keyless insert is audited separately');
    assert_same((int)scalar($keyless_duplicate_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_keyless_duplicate'"), 0, 'identical keyless duplicate inserts without uniqueness do not create a false conflict');

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
    cow_merge_databases($reuse_base, $reuse_source, $reuse_target, $reuse_metadata, 'feature-reuse', 'main');
    assert_same(
        (int)scalar($reuse_metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_keyless' AND c.conflict_type = 'row-source-deleted' AND r.source_branch = 'feature-reuse'"),
        1,
        'rerunning after source keyless delete resolution does not rediscover the resolved delete conflict'
    );
    assert_same((int)scalar($reuse_target, "SELECT COUNT(*) FROM plugin_keyless WHERE label = 'Base keyless'"), 0, 'rerunning after source keyless delete resolution keeps the old row deleted');
    assert_same((int)scalar($reuse_target, "SELECT COUNT(*) FROM plugin_keyless WHERE label = 'Reused rowid source' AND value = 'new logical row'"), 1, 'rerunning after source keyless delete resolution preserves the replacement logical row');

    $offline_reuse_base = $tmp . '/offline-reuse-base.sqlite';
    $offline_reuse_source = $tmp . '/offline-reuse-source.sqlite';
    $offline_reuse_target = $tmp . '/offline-reuse-target.sqlite';
    $offline_reuse_metadata = $tmp . '/.forkpress/cow/merge/offline-reuse-metadata.sqlite';
    create_base_db($offline_reuse_base);
    copy($offline_reuse_base, $offline_reuse_source);
    copy($offline_reuse_base, $offline_reuse_target);
    cow_merge_capture_row_identities($offline_reuse_base, $offline_reuse_metadata, 'main');
    cow_merge_capture_row_identities($offline_reuse_source, $offline_reuse_metadata, 'feature-offline-reuse', 'main');

    $db = open_db($offline_reuse_source);
    $db->exec('DELETE FROM plugin_keyless WHERE rowid = 1');
    $db->exec("INSERT INTO plugin_keyless (label, value) VALUES ('Offline reused rowid', 'new offline row')");
    $db->close();
    assert_same((int)scalar($offline_reuse_source, 'SELECT rowid FROM plugin_keyless'), 1, 'offline SQLite edit can reuse a no-PK rowid without runtime events');

    $db = open_db($offline_reuse_target);
    $db->exec("UPDATE plugin_keyless SET value = 'target kept offline old row' WHERE rowid = 1");
    $db->close();

    $result = cow_merge_databases($offline_reuse_base, $offline_reuse_source, $offline_reuse_target, $offline_reuse_metadata, 'feature-offline-reuse', 'main');
    assert_same($result['status'], 'completed_with_conflicts', 'offline no-PK rowid reuse is bounded as a conflict when target also changed');
    assert_same(scalar($offline_reuse_target, "SELECT label FROM plugin_keyless WHERE rowid = 1"), 'Base keyless', 'offline rowid ambiguity does not partially apply source label to target old row');
    assert_same(scalar($offline_reuse_target, "SELECT value FROM plugin_keyless WHERE rowid = 1"), 'target kept offline old row', 'offline rowid ambiguity keeps target value by default');
    assert_same((int)scalar($offline_reuse_metadata, "SELECT COUNT(*) FROM merge_conflicts WHERE table_name = 'plugin_keyless' AND conflict_type = 'row-identity-ambiguous'"), 1, 'offline no-PK rowid reuse ambiguity is auditable');
    assert_same((int)scalar($offline_reuse_metadata, "SELECT COUNT(*) FROM merge_decisions WHERE table_name = 'plugin_keyless' AND decision = 'target-wins' AND reason LIKE 'no-primary-key source row changed cells that target did not change%'"), 1, 'offline no-PK rowid reuse default target choice is auditable');
    $offline_ambiguity_conflict_id = (int)scalar($offline_reuse_metadata, "SELECT id FROM merge_conflicts WHERE table_name = 'plugin_keyless' AND conflict_type = 'row-identity-ambiguous' ORDER BY id DESC LIMIT 1");
    $offline_ambiguity_resolution = cow_merge_resolve_conflict(
        $offline_reuse_metadata,
        $offline_ambiguity_conflict_id,
        'source',
        true,
        'Apply source row after reviewing offline no-PK ambiguity.',
        'cow-test'
    );
    assert_same($offline_ambiguity_resolution['status'], 'applied', 'source offline no-PK ambiguity resolution records applied status');
    assert_same(scalar($offline_reuse_target, "SELECT label FROM plugin_keyless WHERE rowid = 1"), 'Offline reused rowid', 'source offline no-PK ambiguity resolution applies audited source label');
    assert_same(scalar($offline_reuse_target, "SELECT value FROM plugin_keyless WHERE rowid = 1"), 'new offline row', 'source offline no-PK ambiguity resolution applies audited source value');
    assert_same((int)scalar($offline_reuse_metadata, "SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $offline_ambiguity_conflict_id AND table_name = 'plugin_keyless' AND choice = 'source' AND applied = 1"), 1, 'offline no-PK ambiguity resolution is auditable');
    cow_merge_databases($offline_reuse_base, $offline_reuse_source, $offline_reuse_target, $offline_reuse_metadata, 'feature-offline-reuse', 'main');
    assert_same(
        (int)scalar($offline_reuse_metadata, "SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = 'plugin_keyless' AND c.conflict_type = 'row-identity-ambiguous' AND r.source_branch = 'feature-offline-reuse'"),
        1,
        'rerunning after source offline no-PK ambiguity resolution does not rediscover the resolved ambiguity'
    );
    assert_same(scalar($offline_reuse_target, "SELECT label FROM plugin_keyless WHERE rowid = 1"), 'Offline reused rowid', 'rerunning after source offline ambiguity resolution keeps the audited source label');
    assert_same(scalar($offline_reuse_target, "SELECT value FROM plugin_keyless WHERE rowid = 1"), 'new offline row', 'rerunning after source offline ambiguity resolution keeps the audited source value');

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

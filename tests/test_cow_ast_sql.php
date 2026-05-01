<?php
/**
 * COW SQL rewriting through the SQLite integration AST.
 */

require_once __DIR__ . '/../scripts/branched_pdo.php';

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

function assert_eq($a, $b, $msg) {
    assert_true($a === $b, "$msg (got " . var_export($a, true) . ", expected " . var_export($b, true) . ")");
}

function column_exists_pdo(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->query('PRAGMA table_info("' . str_replace('"', '""', $table) . '")');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((string)$row['name'] === $column) {
            return true;
        }
    }
    return false;
}

$DB = '/tmp/branchfs_cow_ast_sql_' . getmypid() . '.db';
@unlink($DB);
@unlink($DB . '-wal');
@unlink($DB . '-shm');

$db = new SQLite3($DB);
$db->exec(<<<SQL
CREATE TABLE branches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE NOT NULL,
    parent_branch TEXT
);
INSERT INTO branches (id, name, parent_branch) VALUES (1, 'main', NULL);
INSERT INTO branches (id, name, parent_branch) VALUES (2, 'ast', 'main');

CREATE TABLE db_cow_branches (
    branch_id INTEGER NOT NULL,
    table_suffix TEXT NOT NULL,
    parent_branch_id INTEGER NOT NULL,
    parent_table_name TEXT NOT NULL,
    fork_token TEXT NOT NULL DEFAULT '',
    created_at TEXT DEFAULT (datetime('now')),
    PRIMARY KEY (branch_id, table_suffix)
);
CREATE TABLE db_parent_ancestor (
    parent_table_name TEXT NOT NULL,
    row_pk TEXT NOT NULL,
    row_json TEXT NOT NULL,
    captured_at TEXT DEFAULT (datetime('now')),
    PRIMARY KEY (parent_table_name, row_pk)
);
CREATE TABLE db_parent_post_fork_inserts (
    parent_table_name TEXT NOT NULL,
    row_pk TEXT NOT NULL,
    captured_at TEXT DEFAULT (datetime('now')),
    PRIMARY KEY (parent_table_name, row_pk)
);
CREATE TABLE db_snapshots_schema (
    branch_id INTEGER NOT NULL,
    table_name TEXT NOT NULL,
    ddl_sql TEXT NOT NULL,
    indexes_json TEXT NOT NULL DEFAULT '[]',
    PRIMARY KEY (branch_id, table_name)
);

CREATE TABLE `b1_wp_options` (
  `option_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  `option_name` TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  `option_value` TEXT COLLATE NOCASE NOT NULL,
  `autoload` TEXT COLLATE NOCASE NOT NULL DEFAULT 'yes'
);
CREATE UNIQUE INDEX `b1_wp_options__option_name` ON `b1_wp_options` (`option_name`);
CREATE INDEX `b1_wp_options__autoload` ON `b1_wp_options` (`autoload`);
INSERT INTO `b1_wp_options` (`option_name`, `option_value`, `autoload`) VALUES
  ('siteurl', 'http://wp.localhost:18080', 'yes'),
  ('blogname', 'ForkPress', 'yes');
SQL);

echo "=== COW AST SQL tests ===\n\n";

$rewritten_table = cow_rewrite_create_table_name(
    'CREATE TABLE `b1_wp_options` (`id` INTEGER PRIMARY KEY, `label` TEXT DEFAULT "ON")',
    'b1_wp_options',
    'b2_wp_options__overlay'
);
assert_true(
    str_starts_with($rewritten_table, 'CREATE TABLE IF NOT EXISTS "b2_wp_options__overlay"'),
    'CREATE TABLE target is rewritten through AST range'
);
assert_true(str_contains($rewritten_table, 'DEFAULT "ON"'), 'CREATE TABLE body is preserved');

$rewritten_bracket_table = cow_rewrite_create_table_name(
    'CREATE TABLE [b1_wp_options] ([id] INTEGER PRIMARY KEY)',
    'b1_wp_options',
    'b2_wp_options__overlay'
);
assert_true(
    str_starts_with($rewritten_bracket_table, 'CREATE TABLE IF NOT EXISTS "b2_wp_options__overlay"'),
    'square-bracket CREATE TABLE target is rewritten through parser ranges'
);

$rewritten_index = cow_rewrite_create_index(
    'CREATE UNIQUE INDEX `b1_wp_options__option_name` ON `b1_wp_options` (`option_name`(191) DESC)',
    'b1_wp_options__option_name',
    'b2_wp_options__option_name',
    'b1_wp_options',
    'b2_wp_options__overlay'
);
assert_true(
    str_starts_with(
        $rewritten_index,
        'CREATE UNIQUE INDEX IF NOT EXISTS "b2_wp_options__option_name" ON "b2_wp_options__overlay"'
    ),
    'CREATE INDEX target table and index are rewritten through AST ranges'
);
assert_true(str_contains($rewritten_index, '`option_name`(191) DESC'), 'CREATE INDEX key expression is preserved');

$rewritten_bracket_index = cow_rewrite_create_index(
    'CREATE INDEX [b1_wp_options__autoload] ON [b1_wp_options] ([autoload])',
    'b1_wp_options__autoload',
    'b2_wp_options__autoload',
    'b1_wp_options',
    'b2_wp_options__overlay'
);
assert_true(
    str_starts_with(
        $rewritten_bracket_index,
        'CREATE INDEX IF NOT EXISTS "b2_wp_options__autoload" ON "b2_wp_options__overlay"'
    ),
    'square-bracket CREATE INDEX identifiers are rewritten through parser ranges'
);

assert_true(
    cow_sql_is_allowed_ddl("/* comment */ CREATE INDEX IF NOT EXISTS `idx` ON `b2_wp_options` (`autoload`)"),
    'SQLite CREATE INDEX IF NOT EXISTS is accepted by token fallback'
);
assert_true(
    cow_sql_is_allowed_ddl(
        "ALTER TABLE `b2_wp_options` ADD COLUMN `checked` TEXT CHECK(length(`checked`) > 0)"
    ),
    'SQLite-flavoured ALTER TABLE ADD COLUMN is accepted for routing'
);
assert_true(
    !cow_sql_is_allowed_ddl("ALTER TABLE `b2_wp_options` ADD COLUMN `x` TEXT; DELETE FROM audit_log"),
    'multi-statement DDL payload is rejected'
);
assert_true(
    cow_sql_is_allowed_ddl('ALTER TABLE [b2_wp_options] ADD COLUMN [bracket_col] TEXT'),
    'square-bracket ALTER TABLE identifiers are accepted'
);
assert_true(!cow_sql_is_allowed_ddl('CREATE TABLE x (id INT)'), 'CREATE TABLE is not in the DDL routing allowlist');

cow_create_branch_table($db, 2, 1, 'options');
$snapshot_indexes = json_decode((string)$db->querySingle(
    "SELECT indexes_json FROM db_snapshots_schema WHERE table_name = 'b2_wp_options'"
), true);
assert_true(
    is_array($snapshot_indexes)
        && str_contains(implode("\n", $snapshot_indexes), 'ON "b2_wp_options"')
        && !str_contains(implode("\n", $snapshot_indexes), '__overlay'),
    'schema snapshot index DDL is rewritten to the branch logical table'
);
$db->close();

$pdo = BranchedPDO::connect($DB, 'ast');
$pdo->exec("ALTER TABLE `b2_wp_options` ADD COLUMN `seo_title` TEXT DEFAULT 'draft'");
assert_true(
    column_exists_pdo($pdo, 'b2_wp_options__overlay', 'seo_title'),
    'ALTER TABLE ADD COLUMN is routed to the overlay'
);
assert_true(
    column_exists_pdo($pdo, 'b2_wp_options', 'seo_title'),
    'branch view is rebuilt after ADD COLUMN'
);
assert_eq(
    $pdo->query("SELECT seo_title FROM b2_wp_options WHERE option_name = 'siteurl'")->fetchColumn(),
    null,
    'inherited parent rows project NULL for branch-only columns'
);

$pdo->exec(
    "INSERT INTO b2_wp_options (option_name, option_value, autoload) "
  . "VALUES ('branch_only', 'Branch', 'yes')"
);
assert_eq(
    $pdo->query("SELECT seo_title FROM b2_wp_options WHERE option_name = 'branch_only'")->fetchColumn(),
    'draft',
    'view INSERT trigger applies overlay column default'
);

$pdo->exec('CREATE INDEX `b2_wp_options__autoload_ast` ON `main`.`b2_wp_options` (`autoload`)');
assert_eq(
    $pdo->query("SELECT tbl_name FROM sqlite_master WHERE type = 'index' AND name = 'b2_wp_options__autoload_ast'")
        ->fetchColumn(),
    'b2_wp_options__overlay',
    'qualified CREATE INDEX table reference is routed to overlay'
);

$pdo->exec('DROP INDEX `b2_wp_options__autoload_ast` ON `b2_wp_options`');
assert_eq(
    (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'b2_wp_options__autoload_ast'")
        ->fetchColumn(),
    0,
    'MySQL DROP INDEX ... ON branch view drops the overlay index'
);

$pdo->exec('CREATE INDEX IF NOT EXISTS `b2_wp_options__seo_title` ON `b2_wp_options` (`seo_title`)');
assert_eq(
    $pdo->query("SELECT tbl_name FROM sqlite_master WHERE type = 'index' AND name = 'b2_wp_options__seo_title'")
        ->fetchColumn(),
    'b2_wp_options__overlay',
    'SQLite CREATE INDEX IF NOT EXISTS is routed to overlay'
);

$pdo->exec('DROP INDEX IF EXISTS `b2_wp_options__seo_title`');
assert_eq(
    (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'b2_wp_options__seo_title'")
        ->fetchColumn(),
    0,
    'SQLite DROP INDEX IF EXISTS removes the overlay index'
);

$pdo->exec('CREATE INDEX [b2_wp_options__seo_bracket] ON [b2_wp_options] ([seo_title])');
assert_eq(
    $pdo->query("SELECT tbl_name FROM sqlite_master WHERE type = 'index' AND name = 'b2_wp_options__seo_bracket'")
        ->fetchColumn(),
    'b2_wp_options__overlay',
    'square-bracket CREATE INDEX is routed to overlay'
);

$pdo->exec('DROP INDEX [b2_wp_options__seo_bracket]');
assert_eq(
    (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'b2_wp_options__seo_bracket'")
        ->fetchColumn(),
    0,
    'square-bracket DROP INDEX removes the overlay index'
);

$pdo->exec('ALTER TABLE `b2_wp_options` RENAME COLUMN `seo_title` TO `seo_label`');
assert_true(
    column_exists_pdo($pdo, 'b2_wp_options__overlay', 'seo_label')
        && !column_exists_pdo($pdo, 'b2_wp_options__overlay', 'seo_title'),
    'ALTER TABLE RENAME COLUMN is routed to overlay and reflected in schema'
);
assert_true(column_exists_pdo($pdo, 'b2_wp_options', 'seo_label'), 'branch view is rebuilt after RENAME COLUMN');

$rename_failed = false;
try {
    $pdo->exec('ALTER TABLE `b2_wp_options` RENAME TO `renamed_options`');
} catch (RuntimeException $e) {
    $rename_failed = str_contains($e->getMessage(), 'RENAME TO is not supported');
}
assert_true($rename_failed, 'ALTER TABLE RENAME TO still fails explicitly on branch views');

$pdo = null;
@unlink($DB);
@unlink($DB . '-wal');
@unlink($DB . '-shm');

echo "\n=== COW AST SQL tests: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);

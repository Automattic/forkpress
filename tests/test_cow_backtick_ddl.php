<?php
/**
 * COW DB branch creation against WordPress SQLite DDL.
 *
 * The vendored SQLite integration emits backtick-quoted CREATE TABLE/INDEX
 * statements. Branch overlays must be cloned from that DDL, otherwise the
 * logical branch views point at missing __overlay tables and WordPress falls
 * back to the installation wizard.
 */

require_once __DIR__ . '/../experiments/branchfs/scripts/cow_helpers.php';

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

$DB = '/tmp/branchfs_cow_backtick_' . getmypid() . '.db';
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
INSERT INTO branches (id, name, parent_branch) VALUES (2, 'marketing', 'main');
INSERT INTO branches (id, name, parent_branch) VALUES (3, 'repair-me', 'main');

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

CREATE TABLE _wp_sqlite_mysql_information_schema_tables (
    TABLE_SCHEMA TEXT NOT NULL,
    TABLE_NAME TEXT NOT NULL,
    TABLE_TYPE TEXT NOT NULL,
    ENGINE TEXT NOT NULL,
    ROW_FORMAT TEXT NOT NULL,
    TABLE_COLLATION TEXT NOT NULL,
    PRIMARY KEY (TABLE_SCHEMA, TABLE_NAME)
);
CREATE TABLE _wp_sqlite_mysql_information_schema_columns (
    TABLE_SCHEMA TEXT NOT NULL,
    TABLE_NAME TEXT NOT NULL,
    COLUMN_NAME TEXT NOT NULL,
    ORDINAL_POSITION INTEGER NOT NULL,
    COLUMN_DEFAULT TEXT,
    IS_NULLABLE TEXT NOT NULL,
    DATA_TYPE TEXT NOT NULL,
    COLUMN_TYPE TEXT NOT NULL,
    EXTRA TEXT NOT NULL DEFAULT '',
    PRIMARY KEY (TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME)
);
CREATE TABLE _wp_sqlite_mysql_information_schema_statistics (
    TABLE_SCHEMA TEXT NOT NULL,
    TABLE_NAME TEXT NOT NULL,
    INDEX_NAME TEXT NOT NULL,
    SEQ_IN_INDEX INTEGER NOT NULL,
    COLUMN_NAME TEXT,
    PRIMARY KEY (TABLE_SCHEMA, TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX)
);
CREATE TABLE _wp_sqlite_mysql_information_schema_table_constraints (
    TABLE_SCHEMA TEXT NOT NULL,
    TABLE_NAME TEXT NOT NULL,
    CONSTRAINT_NAME TEXT NOT NULL,
    CONSTRAINT_TYPE TEXT NOT NULL,
    PRIMARY KEY (TABLE_SCHEMA, TABLE_NAME, CONSTRAINT_TYPE, CONSTRAINT_NAME)
);
CREATE TABLE _wp_sqlite_mysql_information_schema_key_column_usage (
    TABLE_SCHEMA TEXT NOT NULL,
    TABLE_NAME TEXT NOT NULL,
    CONSTRAINT_NAME TEXT NOT NULL,
    COLUMN_NAME TEXT NOT NULL,
    ORDINAL_POSITION INTEGER NOT NULL
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
  ('home', 'http://wp.localhost:18080', 'yes'),
  ('blogname', 'ForkPress', 'yes'),
  ('b1_wp_user_roles', 'a:1:{s:13:"administrator";a:1:{s:4:"name";s:13:"Administrator";}}', 'yes');

CREATE TABLE `b1_wp_usermeta` (
  `umeta_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  `user_id` INTEGER NOT NULL DEFAULT '0',
  `meta_key` TEXT,
  `meta_value` TEXT
);
CREATE INDEX `b1_wp_usermeta__user_id` ON `b1_wp_usermeta` (`user_id`);
CREATE INDEX `b1_wp_usermeta__meta_key` ON `b1_wp_usermeta` (`meta_key`);
INSERT INTO `b1_wp_usermeta` (`user_id`, `meta_key`, `meta_value`) VALUES
  (1, 'nickname', 'admin'),
  (1, 'b1_wp_capabilities', 'a:1:{s:13:"administrator";b:1;}'),
  (1, 'b1_wp_user_level', '10');

INSERT INTO _wp_sqlite_mysql_information_schema_tables
  (TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE, ENGINE, ROW_FORMAT, TABLE_COLLATION)
VALUES
  ('sqlite_database', 'b1_wp_options', 'BASE TABLE', 'InnoDB', 'Dynamic', 'utf8mb4_unicode_520_ci'),
  ('sqlite_database', 'b1_wp_usermeta', 'BASE TABLE', 'InnoDB', 'Dynamic', 'utf8mb4_unicode_520_ci');
INSERT INTO _wp_sqlite_mysql_information_schema_columns
  (TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE, COLUMN_TYPE, EXTRA)
VALUES
  ('sqlite_database', 'b1_wp_options', 'option_id', 1, NULL, 'NO', 'bigint', 'bigint unsigned', 'auto_increment'),
  ('sqlite_database', 'b1_wp_options', 'option_name', 2, "''", 'NO', 'varchar', 'varchar(191)', ''),
  ('sqlite_database', 'b1_wp_options', 'option_value', 3, NULL, 'NO', 'longtext', 'longtext', ''),
  ('sqlite_database', 'b1_wp_options', 'autoload', 4, "'yes'", 'NO', 'varchar', 'varchar(20)', ''),
  ('sqlite_database', 'b1_wp_usermeta', 'umeta_id', 1, NULL, 'NO', 'bigint', 'bigint unsigned', 'auto_increment'),
  ('sqlite_database', 'b1_wp_usermeta', 'user_id', 2, "'0'", 'NO', 'bigint', 'bigint unsigned', ''),
  ('sqlite_database', 'b1_wp_usermeta', 'meta_key', 3, NULL, 'YES', 'varchar', 'varchar(255)', ''),
  ('sqlite_database', 'b1_wp_usermeta', 'meta_value', 4, NULL, 'YES', 'longtext', 'longtext', '');
INSERT INTO _wp_sqlite_mysql_information_schema_statistics
  (TABLE_SCHEMA, TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME)
VALUES
  ('sqlite_database', 'b1_wp_options', 'b1_wp_options__option_name', 1, 'option_name'),
  ('sqlite_database', 'b1_wp_usermeta', 'b1_wp_usermeta__user_id', 1, 'user_id');
SQL);

echo "=== COW backtick DDL tests ===\n\n";

cow_create_branch_table($db, 2, 1, 'options');
cow_create_branch_table($db, 2, 1, 'usermeta');
cow_seed_wordpress_prefix_rows($db, 2, 1);

$overlay_type = (string)$db->querySingle(
    "SELECT type FROM sqlite_master WHERE name = 'b2_wp_options__overlay'"
);
assert_eq($overlay_type, 'table', 'overlay table is created from backtick-quoted parent DDL');

$info_schema_columns = (int)$db->querySingle(
    "SELECT COUNT(*) FROM _wp_sqlite_mysql_information_schema_columns WHERE TABLE_NAME = 'b2_wp_options'"
);
assert_eq($info_schema_columns, 4, 'branch logical table is registered in SQLite information schema');

$index_sql = (string)$db->querySingle(
    "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'b2_wp_options__option_name'"
);
assert_true(strpos($index_sql, 'b2_wp_options__overlay') !== false, 'unique index is retargeted to overlay');

$branch_count = (int)$db->querySingle('SELECT COUNT(*) FROM b2_wp_options');
assert_eq($branch_count, 5, 'branch view inherits parent option rows plus branch-local roles');

$siteurl = (string)$db->querySingle(
    "SELECT option_value FROM b2_wp_options WHERE option_name = 'siteurl'"
);
assert_eq($siteurl, 'http://wp.localhost:18080', 'branch view can read inherited siteurl');

$db->exec("UPDATE b2_wp_options SET option_value = 'Marketing' WHERE option_name = 'blogname'");
$branch_blogname = (string)$db->querySingle(
    "SELECT option_value FROM b2_wp_options WHERE option_name = 'blogname'"
);
$main_blogname = (string)$db->querySingle(
    "SELECT option_value FROM b1_wp_options WHERE option_name = 'blogname'"
);
$overlay_rows = (int)$db->querySingle('SELECT COUNT(*) FROM b2_wp_options__overlay');
assert_eq($branch_blogname, 'Marketing', 'branch update is visible through the branch view');
assert_eq($main_blogname, 'ForkPress', 'branch update does not modify parent table');
assert_eq($overlay_rows, 2, 'branch update and branch-local roles are in overlay');

$roles = (string)$db->querySingle(
    "SELECT option_value FROM b2_wp_options WHERE option_name = 'b2_wp_user_roles'"
);
$capabilities = (string)$db->querySingle(
    "SELECT meta_value FROM b2_wp_usermeta WHERE meta_key = 'b2_wp_capabilities'"
);
$user_level = (string)$db->querySingle(
    "SELECT meta_value FROM b2_wp_usermeta WHERE meta_key = 'b2_wp_user_level'"
);
assert_true($roles !== '', 'branch gets branch-prefixed user_roles option');
assert_true(strpos($capabilities, 'administrator') !== false, 'branch gets branch-prefixed admin capabilities');
assert_eq($user_level, '10', 'branch gets branch-prefixed user level');

cow_create_branch_table($db, 3, 1, 'options');
$db->exec('DROP TABLE "b3_wp_options__overlay"');
$repaired = cow_repair_missing_overlays($db);
$repair_overlay_type = (string)$db->querySingle(
    "SELECT type FROM sqlite_master WHERE name = 'b3_wp_options__overlay'"
);
$repair_count = (int)$db->querySingle('SELECT COUNT(*) FROM b3_wp_options');
assert_eq($repaired, 1, 'repair detects one missing overlay');
assert_eq($repair_overlay_type, 'table', 'repair recreates missing overlay table');
assert_eq($repair_count, 4, 'repaired branch view inherits parent rows');

$db->close();
@unlink($DB);
@unlink($DB . '-wal');
@unlink($DB . '-shm');

echo "\n=== COW backtick DDL tests: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);

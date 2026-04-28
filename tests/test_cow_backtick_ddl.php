<?php
/**
 * COW DB branch creation against WordPress SQLite DDL.
 *
 * The vendored SQLite integration emits backtick-quoted CREATE TABLE/INDEX
 * statements. Branch overlays must be cloned from that DDL, otherwise the
 * logical branch views point at missing __overlay tables and WordPress falls
 * back to the installation wizard.
 */

require_once __DIR__ . '/../scripts/cow_helpers.php';

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
  ('blogname', 'ForkPress', 'yes');
SQL);

echo "=== COW backtick DDL tests ===\n\n";

cow_create_branch_table($db, 2, 1, 'options');

$overlay_type = (string)$db->querySingle(
    "SELECT type FROM sqlite_master WHERE name = 'b2_wp_options__overlay'"
);
assert_eq($overlay_type, 'table', 'overlay table is created from backtick-quoted parent DDL');

$index_sql = (string)$db->querySingle(
    "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'b2_wp_options__option_name'"
);
assert_true(strpos($index_sql, 'b2_wp_options__overlay') !== false, 'unique index is retargeted to overlay');

$branch_count = (int)$db->querySingle('SELECT COUNT(*) FROM b2_wp_options');
assert_eq($branch_count, 3, 'branch view inherits parent option rows');

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
assert_eq($overlay_rows, 1, 'branch update writes one row to overlay');

cow_create_branch_table($db, 3, 1, 'options');
$db->exec('DROP TABLE "b3_wp_options__overlay"');
$repaired = cow_repair_missing_overlays($db);
$repair_overlay_type = (string)$db->querySingle(
    "SELECT type FROM sqlite_master WHERE name = 'b3_wp_options__overlay'"
);
$repair_count = (int)$db->querySingle('SELECT COUNT(*) FROM b3_wp_options');
assert_eq($repaired, 1, 'repair detects one missing overlay');
assert_eq($repair_overlay_type, 'table', 'repair recreates missing overlay table');
assert_eq($repair_count, 3, 'repaired branch view inherits parent rows');

$db->close();
@unlink($DB);
@unlink($DB . '-wal');
@unlink($DB . '-shm');

echo "\n=== COW backtick DDL tests: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);

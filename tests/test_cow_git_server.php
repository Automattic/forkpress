<?php

$pass = 0; $fail = 0;
function assert_true($cond, $msg) {
    global $pass, $fail;
    if ($cond) { echo "  PASS: $msg\n"; $pass++; }
    else       { echo "  FAIL: $msg\n"; $fail++; }
}
function assert_same($actual, $expected, $msg) {
    assert_true($actual === $expected, "$msg (got " . var_export($actual, true) . ", expected " . var_export($expected, true) . ")");
}
function pkt_line(string $payload): string {
    return sprintf('%04x', strlen($payload) + 4) . $payload;
}

require_once __DIR__ . '/../scripts/git_server/cow_server.php';

echo "=== COW Git server receive-pack parsing ===\n";

$old = str_repeat('1', 40);
$new = str_repeat('2', 40);
$zero = str_repeat('0', 40);

$single_packet_multi = pkt_line(
    "$old $zero refs/heads/git-created\0report-status delete-refs\n"
    . "$old $zero refs/heads/feature-cow\n"
) . '0000';
$commands = cow_git_parse_push_commands($single_packet_multi);
assert_same(count($commands), 2, 'multiple commands inside one packet are parsed');
assert_same($commands[0]['ref'], 'refs/heads/git-created', 'first command ref parsed');
assert_same($commands[1]['ref'], 'refs/heads/feature-cow', 'second command ref parsed');
$rejections = cow_git_push_command_rejections($commands);
assert_same(count($rejections), 2, 'multi-ref push is rejected before mutation');

$multi_packet = pkt_line("$old $new refs/heads/feature-cow\0report-status\n")
    . pkt_line("$old $zero refs/heads/git-created\n")
    . '0000';
$commands = cow_git_parse_push_commands($multi_packet);
assert_same(count($commands), 2, 'commands split across packets are parsed');
assert_same($commands[0]['new_oid'], $new, 'update command new oid parsed');
assert_same($commands[1]['new_oid'], $zero, 'delete command new oid parsed');

$single_main_delete = pkt_line("$old $zero refs/heads/main\0report-status delete-refs\n") . '0000';
$commands = cow_git_parse_push_commands($single_main_delete);
assert_same(count($commands), 1, 'single command parsed');
$rejections = cow_git_push_command_rejections($commands);
assert_same($rejections['refs/heads/main'] ?? '', 'refusing to delete the main COW branch', 'main delete is rejected before mutation');

$tmp = sys_get_temp_dir() . '/forkpress-cow-git-server-' . getmypid() . '-' . bin2hex(random_bytes(4));
$branches = $tmp . '/public';
$storage = $tmp . '/storage';
mkdir($branches, 0777, true);
mkdir($storage . '/git-created', 0777, true);
file_put_contents($storage . '/git-created/wp-load.php', "<?php\n");
if (@symlink($storage . '/git-created', $branches . '/git-created')) {
    $staged = cow_git_stage_delete_branch_tree($branches, $storage, 'git-created');
    assert_true(!file_exists($branches . '/git-created') && !is_link($branches . '/git-created'), 'symlink public branch is staged for deletion');
    assert_true(!file_exists($storage . '/git-created') && !is_link($storage . '/git-created'), 'symlink storage branch is staged for deletion');
    cow_git_restore_staged_branch_deletes(['git-created' => $staged]);
    assert_true(is_link($branches . '/git-created'), 'staged symlink public branch can be restored');
    assert_true(is_dir($storage . '/git-created'), 'staged symlink storage branch can be restored');
    $staged = cow_git_stage_delete_branch_tree($branches, $storage, 'git-created');
    cow_git_discard_staged_branch_delete($staged);
    assert_true(!file_exists($branches . '/git-created') && !is_link($branches . '/git-created'), 'discard removes staged public symlink');
    assert_true(!file_exists($storage . '/git-created'), 'discard removes staged storage tree');
} else {
    echo "  SKIP: symlink-backed branch delete test\n";
}
cow_git_remove_tree($tmp);

$tmp = sys_get_temp_dir() . '/forkpress-cow-git-sync-' . getmypid() . '-' . bin2hex(random_bytes(4));
$branches = $tmp . '/branches';
$git = $tmp . '/git';
mkdir($branches . '/main/wp-content/database', 0777, true);
file_put_contents($branches . '/main/wp-load.php', "<?php\n");
file_put_contents($branches . '/main/wp-content/sync.txt', "one\n");
$db = new SQLite3($branches . '/main/wp-content/database/.ht.sqlite');
$db->exec('CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY, option_name TEXT, option_value TEXT)');
$db->exec("INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl', 'http://wp.localhost')");
$db->close();

$fs = WordPress\Filesystem\LocalFilesystem::create($git);
$repo = new WordPress\Git\GitRepository($fs, ['default_branch' => 'main']);
$repo->set_config_value(['user', 'name'], 'ForkPress COW');
$repo->set_config_value(['user', 'email'], 'forkpress-cow@local');
cow_git_sync_repository($repo, $branches);
$first_tip = $repo->get_branch_tip('refs/heads/main');
cow_git_sync_repository($repo, $branches);
$second_tip = $repo->get_branch_tip('refs/heads/main');
assert_same($second_tip, $first_tip, 'unchanged COW Git snapshot keeps branch ref stable');

file_put_contents($branches . '/main/wp-content/sync.txt', "two\n");
cow_git_sync_repository($repo, $branches);
$file_tip = $repo->get_branch_tip('refs/heads/main');
assert_true($file_tip !== $second_tip, 'file change advances COW Git snapshot ref');
cow_git_sync_repository($repo, $branches);
assert_same($repo->get_branch_tip('refs/heads/main'), $file_tip, 'unchanged file snapshot remains stable after advancing');

$db = new SQLite3($branches . '/main/wp-content/database/.ht.sqlite');
$db->exec("INSERT INTO wp_options (option_name, option_value) VALUES ('blogname', 'ForkPress')");
$db->close();
cow_git_sync_repository($repo, $branches);
$db_tip = $repo->get_branch_tip('refs/heads/main');
assert_true($db_tip !== $file_tip, 'database snapshot change advances COW Git snapshot ref');
cow_git_sync_repository($repo, $branches);
assert_same($repo->get_branch_tip('refs/heads/main'), $db_tip, 'unchanged database snapshot remains stable after advancing');
cow_git_remove_tree($tmp);

$tmp = sys_get_temp_dir() . '/forkpress-cow-git-push-resync-' . getmypid() . '-' . bin2hex(random_bytes(4));
$branches = $tmp . '/branches';
$git = $tmp . '/git';
mkdir($branches . '/main/wp-content/database', 0777, true);
file_put_contents($branches . '/main/wp-load.php', "<?php\n");
file_put_contents($branches . '/main/wp-content/pushed.txt', "old\n");
file_put_contents($branches . '/main/wp-content/database/wp-debug.log', "local log\n");
$db = new SQLite3($branches . '/main/wp-content/database/.ht.sqlite');
$db->exec('CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY, option_name TEXT, option_value TEXT)');
$db->exec("INSERT INTO wp_options (option_name, option_value) VALUES ('blogname', 'ForkPress')");
$db->close();

$fs = WordPress\Filesystem\LocalFilesystem::create($git);
$repo = new WordPress\Git\GitRepository($fs, ['default_branch' => 'main']);
$repo->set_config_value(['user', 'name'], 'ForkPress COW');
$repo->set_config_value(['user', 'email'], 'forkpress-cow@local');
cow_git_sync_repository($repo, $branches);
$base_tip = $repo->get_branch_tip('refs/heads/main');
$repo->checkout('refs/heads/main');
$pushed_tip = $repo->commit([
    'commit' => [
        'message' => 'push edited database snapshot',
        'author' => 'ForkPress Test <forkpress-test@local>',
        'committer' => 'ForkPress Test <forkpress-test@local>',
        'parents' => [$base_tip],
    ],
    'updates' => [
        'database.sql' => "-- user-edited database.sql should not persist\n",
        'wordpress/wp-content/pushed.txt' => "new\n",
        'wordpress/wp-content/database/pushed-private.txt' => "private\n",
    ],
]);
$repo->set_branch_tip('refs/heads/main', $pushed_tip);
cow_git_apply_push_to_branches($repo, $git, $branches, $branches, null, 'file-copy', '', ['main' => $base_tip]);
$resynced_tip = $repo->get_branch_tip('refs/heads/main');
assert_true($resynced_tip !== $pushed_tip, 'push apply immediately resyncs ref when database.sql was edited');
assert_same(file_get_contents($branches . '/main/wp-content/pushed.txt'), "new\n", 'push apply keeps wordpress file changes');
$resynced_database = $repo->read_object_by_path('database.sql', $resynced_tip)->consume_all();
assert_true(!str_contains($resynced_database, 'user-edited database.sql'), 'push resync discards edited database.sql from Git ref');
assert_true(str_contains($resynced_database, 'ForkPress'), 'push resync regenerates database.sql from branch SQLite');
assert_true(!in_array('wordpress/wp-content/database/wp-debug.log', cow_git_list_commit_paths($repo, $resynced_tip), true), 'push resync keeps database directory out of Git ref');
assert_same(file_get_contents($branches . '/main/wp-content/database/wp-debug.log'), "local log\n", 'push apply preserves local database directory files');
assert_true(!file_exists($branches . '/main/wp-content/database/pushed-private.txt'), 'push apply ignores pushed database directory files');
cow_git_remove_tree($tmp);

$tmp = sys_get_temp_dir() . '/forkpress-cow-git-config-rewrite-' . getmypid() . '-' . bin2hex(random_bytes(4));
$branches = $tmp . '/branches';
$git = $tmp . '/git';
mkdir($branches . '/main/wp-content/database', 0777, true);
mkdir($branches . '/feature/wp-content/database', 0777, true);
file_put_contents($branches . '/main/wp-load.php', "<?php\n");
file_put_contents($branches . '/feature/wp-load.php', "<?php\n");
$main_db = $branches . '/main/wp-content/database/.ht.sqlite';
$feature_db = $branches . '/feature/wp-content/database/.ht.sqlite';
$main_debug = $branches . '/main/wp-content/database/wp-debug.log';
$feature_debug = $branches . '/feature/wp-content/database/wp-debug.log';
$main_config = "<?php\n"
    . "define('FQDB',    '" . cow_git_php_single_quoted($main_db) . "');\n"
    . "define('DB_DIR',  '" . cow_git_php_single_quoted(dirname($main_db)) . "');\n"
    . "define('DB_FILE', '.ht.sqlite');\n"
    . "define('WP_DEBUG_LOG', '" . cow_git_php_single_quoted($main_debug) . "');\n";
$feature_config = "<?php\n"
    . "define('FQDB',    '" . cow_git_php_single_quoted($feature_db) . "');\n"
    . "define('DB_DIR',  '" . cow_git_php_single_quoted(dirname($feature_db)) . "');\n"
    . "define('DB_FILE', '.ht.sqlite');\n"
    . "define('WP_DEBUG_LOG', '" . cow_git_php_single_quoted($feature_debug) . "');\n";
file_put_contents($branches . '/main/wp-config.php', $main_config);
file_put_contents($branches . '/feature/wp-config.php', $feature_config);

$fs = WordPress\Filesystem\LocalFilesystem::create($git);
$repo = new WordPress\Git\GitRepository($fs, ['default_branch' => 'main']);
$repo->set_config_value(['user', 'name'], 'ForkPress COW');
$repo->set_config_value(['user', 'email'], 'forkpress-cow@local');
cow_git_sync_repository($repo, $branches);
$main_tip = $repo->get_branch_tip('refs/heads/main');
$repo->checkout('refs/heads/main');
$pushed_tip = $repo->commit([
    'commit' => [
        'message' => 'push config from another branch',
        'author' => 'ForkPress Test <forkpress-test@local>',
        'committer' => 'ForkPress Test <forkpress-test@local>',
        'parents' => [$main_tip],
    ],
    'updates' => [
        'wordpress/wp-config.php' => $feature_config,
    ],
]);
$repo->set_branch_tip('refs/heads/main', $pushed_tip);
cow_git_apply_push_to_branches($repo, $git, $branches, $branches, null, 'file-copy', $main_debug, ['main' => $main_tip]);
$rewritten_config = file_get_contents($branches . '/main/wp-config.php');
assert_true(str_contains($rewritten_config, $main_db), 'existing branch push rewrites wp-config.php to target database path');
assert_true(!str_contains($rewritten_config, $feature_db), 'existing branch push does not keep source branch database path');
assert_true(str_contains($rewritten_config, $main_debug), 'existing branch push rewrites wp-config.php to target debug log');
assert_true(!str_contains($rewritten_config, $feature_debug), 'existing branch push does not keep source branch debug log');
$rewritten_tip = $repo->get_branch_tip('refs/heads/main');
$git_config = $repo->read_object_by_path('wordpress/wp-config.php', $rewritten_tip)->consume_all();
assert_true(str_contains($git_config, $main_db), 'push resync exports rewritten target wp-config.php');
assert_true(!str_contains($git_config, $feature_db), 'push resync removes source wp-config.php database path from Git ref');
assert_true(str_contains($git_config, $main_debug), 'push resync exports rewritten target debug log');
assert_true(!str_contains($git_config, $feature_debug), 'push resync removes source debug log from Git ref');
cow_git_remove_tree($tmp);

$tmp = sys_get_temp_dir() . '/forkpress-cow-git-atomic-update-' . getmypid() . '-' . bin2hex(random_bytes(4));
$branches = $tmp . '/branches';
$git = $tmp . '/git';
mkdir($branches . '/main/wp-content', 0777, true);
file_put_contents($branches . '/main/wp-load.php', "<?php\n");
file_put_contents($branches . '/main/wp-content/keep.txt', "keep\n");
file_put_contents($branches . '/main/wp-content/conflict', "original\n");

$fs = WordPress\Filesystem\LocalFilesystem::create($git);
$repo = new WordPress\Git\GitRepository($fs, ['default_branch' => 'main']);
$repo->set_config_value(['user', 'name'], 'ForkPress COW');
$repo->set_config_value(['user', 'email'], 'forkpress-cow@local');
$nested_blob = $repo->add_object('blob', "nested\n");
$failed = false;
try {
    cow_git_apply_existing_branch_update(
        $repo,
        $branches,
        $branches,
        'main',
        ['wp-content/conflict/nested.txt' => $nested_blob],
        'file-copy',
        ''
    );
} catch (Throwable $e) {
    $failed = true;
}
assert_true($failed, 'existing branch staged update reports path conflict failure');
assert_same(file_get_contents($branches . '/main/wp-content/conflict'), "original\n", 'failed staged update keeps original conflicting file');
assert_same(file_get_contents($branches . '/main/wp-content/keep.txt'), "keep\n", 'failed staged update keeps unrelated file');
assert_true(!file_exists($branches . '/main/wp-content/conflict/nested.txt'), 'failed staged update does not publish nested conflict path');
assert_same(glob($branches . '/.forkpress-update-*') ?: [], [], 'failed staged update cleans temporary directories');
cow_git_remove_tree($tmp);

echo "\n=== COW Git server tests: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);

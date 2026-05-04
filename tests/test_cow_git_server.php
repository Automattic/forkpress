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

echo "\n=== COW Git server tests: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);

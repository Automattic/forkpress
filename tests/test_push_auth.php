<?php
/**
 * Push auth test (finding #4).
 *
 * Verifies git_check_auth() rejects the default admin/admin when custom
 * BRANCHFS_GIT_USER / BRANCHFS_GIT_PASSWORD_HASH env vars are set, and
 * accepts the configured credential pair.
 */

$pass = 0; $fail = 0;
function assert_true($cond, $msg) {
    global $pass, $fail;
    if ($cond) { echo "  PASS: $msg\n"; $pass++; }
    else       { echo "  FAIL: $msg\n"; $fail++; }
}

require_once __DIR__ . '/../scripts/git/autoload.php';
require_once __DIR__ . '/../experiments/branchfs/git/server.php';

// Save & clear env state between sub-tests
$restore_env = function() {
    putenv('BRANCHFS_GIT_USER');
    putenv('BRANCHFS_GIT_PASSWORD_HASH');
    putenv('BRANCHFS_PROD');
    unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
};

echo "=== default dev auth (admin/admin) ===\n";
$restore_env();
$_SERVER['PHP_AUTH_USER'] = 'admin';
$_SERVER['PHP_AUTH_PW']   = 'admin';
assert_true(git_check_auth() === 'admin', 'admin/admin accepted in dev (no env vars set)');

$_SERVER['PHP_AUTH_USER'] = 'admin';
$_SERVER['PHP_AUTH_PW']   = 'wrong';
assert_true(git_check_auth() === null,    'wrong password rejected in dev');

echo "\n=== custom creds via BRANCHFS_GIT_* env vars ===\n";
$restore_env();
$hash = password_hash('bar', PASSWORD_DEFAULT);
putenv("BRANCHFS_GIT_USER=foo");
putenv("BRANCHFS_GIT_PASSWORD_HASH=$hash");

$_SERVER['PHP_AUTH_USER'] = 'foo';
$_SERVER['PHP_AUTH_PW']   = 'bar';
assert_true(git_check_auth() === 'foo',  'custom user foo/bar accepted');

$_SERVER['PHP_AUTH_USER'] = 'admin';
$_SERVER['PHP_AUTH_PW']   = 'admin';
assert_true(git_check_auth() === null,   'admin/admin rejected once custom env is set');

$_SERVER['PHP_AUTH_USER'] = 'foo';
$_SERVER['PHP_AUTH_PW']   = 'wrong';
assert_true(git_check_auth() === null,   'wrong password for custom user rejected');

echo "\n=== BRANCHFS_PROD=1 forces non-default creds ===\n";
$restore_env();
putenv('BRANCHFS_PROD=1');
$_SERVER['PHP_AUTH_USER'] = 'admin';
$_SERVER['PHP_AUTH_PW']   = 'admin';
assert_true(git_check_auth() === null,   'BRANCHFS_PROD=1 rejects default admin/admin');

// Still rejected even with env vars partially set
putenv('BRANCHFS_PROD=1');
putenv("BRANCHFS_GIT_USER=foo"); // hash missing
assert_true(git_check_auth() === null,   'BRANCHFS_PROD=1 + only USER set -> rejected');

// With both env vars set, prod auth works
putenv("BRANCHFS_GIT_PASSWORD_HASH=" . password_hash('prodpass', PASSWORD_DEFAULT));
$_SERVER['PHP_AUTH_USER'] = 'foo';
$_SERVER['PHP_AUTH_PW']   = 'prodpass';
assert_true(git_check_auth() === 'foo', 'BRANCHFS_PROD=1 accepts correct custom creds');

$restore_env();

echo "\n=== reserved branch names list ===\n";
$reserved = git_reserved_branch_names();
foreach (['www', 'admin', 'api', 'mail', 'localhost', 'wp'] as $name) {
    assert_true(in_array($name, $reserved, true), "'$name' is in the reserved list");
}
assert_true(!in_array('feature', $reserved, true), "'feature' is NOT reserved");

echo "\n=== push-auth tests: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);

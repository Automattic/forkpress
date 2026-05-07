<?php
/**
 * Plugin compatibility tests for branchfs extension.
 *
 * CRITICAL: Tests that plugins using normal PHP filesystem functions with
 * relative/absolute paths work WITHOUT modification. This verifies the
 * plain files wrapper interception.
 */

$pass = 0;
$fail = 0;

function assert_true($cond, $msg) {
    global $pass, $fail;
    if ($cond) { echo "  PASS: $msg\n"; $pass++; }
    else       { echo "  FAIL: $msg\n"; $fail++; }
}

function assert_eq($a, $b, $msg) {
    assert_true($a === $b, "$msg (got " . var_export($a, true) . ", expected " . var_export($b, true) . ")");
}

$DB = '/tmp/branchfs_test_compat_' . getmypid() . '.db';
$WP_ROOT = '/tmp/branchfs_wproot_' . getmypid();

echo "=== BranchFS Plugin Compatibility Tests ===\n";
echo "WP Root: $WP_ROOT\n\n";

// Initialize database
$db = new SQLite3($DB);
$schema = file_get_contents(__DIR__ . '/../schema.sql');
$db->exec($schema);
$db->close();

// Set up branchfs
branchfs_set_db($DB);
branchfs_set_root($WP_ROOT);
branchfs_set_branch('main');

// Create branch structure
branchfs_create_branch('main', null);
branchfs_create_branch('preview-1', 'main');

// Populate main branch with simulated WordPress files via branchfs://
$files = [
    'wp-config.php' => "<?php\ndefine('DB_NAME', 'wordpress');\ndefine('ABSPATH', '$WP_ROOT/');\n",
    'wp-load.php' => "<?php\n// Simulated wp-load\nrequire __DIR__ . '/wp-config.php';\n",
    'wp-blog-header.php' => "<?php\nrequire __DIR__ . '/wp-load.php';\n",
    'wp-content/plugins/hello-dolly/hello.php' => implode("\n", [
        '<?php',
        '/*',
        ' * Plugin Name: Hello Dolly (BranchFS Test)',
        ' */',
        '',
        'function hello_dolly_get_lyric() {',
        '    $file = __DIR__ . "/lyrics.txt";',
        '    if (file_exists($file)) {',
        '        $lines = file($file);',
        '        return trim($lines[array_rand($lines)]);',
        '    }',
        '    return "Hello, Dolly!";',
        '}',
        '',
        'function hello_dolly_test_relative() {',
        '    // Test relative path from plugin dir',
        '    $old_dir = getcwd();',
        '    chdir(__DIR__);',
        '    $content = file_get_contents("lyrics.txt");',
        '    chdir($old_dir);',
        '    return $content;',
        '}',
        '',
        'function hello_dolly_test_absolute() {',
        '    // Test absolute path',
        '    return file_get_contents("' . $WP_ROOT . '/wp-content/plugins/hello-dolly/lyrics.txt");',
        '}',
        '',
        'function hello_dolly_test_scandir() {',
        '    return scandir(__DIR__);',
        '}',
        '',
        'function hello_dolly_test_is_dir() {',
        '    return is_dir(__DIR__);',
        '}',
        '',
        'function hello_dolly_test_file_put() {',
        '    $file = __DIR__ . "/cache.tmp";',
        '    file_put_contents($file, "cached data");',
        '    return file_get_contents($file);',
        '}',
    ]),
    'wp-content/plugins/hello-dolly/lyrics.txt' => "Hello, Dolly!\nWell, hello, Dolly!\nIt's so nice to have you back where you belong.\n",
    'wp-content/themes/flavor/style.css' => "/* Theme Name: Flavor */\nbody { color: #333; }\n",
    'wp-content/themes/flavor/functions.php' => implode("\n", [
        '<?php',
        'function flavor_get_template_path($name) {',
        '    return __DIR__ . "/templates/$name.php";',
        '}',
        '',
        'function flavor_template_exists($name) {',
        '    return file_exists(flavor_get_template_path($name));',
        '}',
        '',
        'function flavor_load_template($name) {',
        '    $path = flavor_get_template_path($name);',
        '    if (file_exists($path)) {',
        '        return file_get_contents($path);',
        '    }',
        '    return false;',
        '}',
    ]),
    'wp-content/themes/flavor/templates/header.php' => '<header>Flavor Theme</header>',
    'wp-includes/version.php' => "<?php\n\$wp_version = '6.5.0';\n",
];

echo "# Populating store with " . count($files) . " files\n";
foreach ($files as $path => $content) {
    // Ensure parent directories exist
    $parts = explode('/', $path);
    array_pop($parts); // remove filename
    $dir = '';
    foreach ($parts as $part) {
        $dir = $dir ? "$dir/$part" : $part;
        @mkdir("branchfs://main/$dir", 0755);
    }
    file_put_contents("branchfs://main/$path", $content);
}
echo "  Done.\n\n";

// Create the WP_ROOT directory skeleton on the real filesystem.
// In production, these directories exist (even if empty) so chdir() works.
// The actual file content comes from SQLite, not the real filesystem.
@mkdir($WP_ROOT . '/wp-content/plugins/hello-dolly', 0755, true);
@mkdir($WP_ROOT . '/wp-content/themes/flavor/templates', 0755, true);
@mkdir($WP_ROOT . '/wp-includes', 0755, true);

// --- NOW ACTIVATE INTERCEPTION ---
echo "# Activating filesystem interception\n";
branchfs_activate();
echo "  Active: " . (branchfs_is_active() ? 'yes' : 'no') . "\n\n";

// Change to WP root like the launcher would
$prev_cwd = getcwd();
chdir($WP_ROOT);
clearstatcache(true);

// ============================================================
// TEST GROUP 1: Absolute path access (simulates __DIR__ usage)
// ============================================================
echo "# Test Group 1: Absolute path access (__DIR__ pattern)\n";

$content = file_get_contents($WP_ROOT . '/wp-content/plugins/hello-dolly/lyrics.txt');
assert_true($content !== false, 'file_get_contents with absolute path works');
assert_true(strpos($content, 'Hello, Dolly!') !== false, 'Content is correct via absolute path');

$exists = file_exists($WP_ROOT . '/wp-content/plugins/hello-dolly/hello.php');
assert_true($exists, 'file_exists with absolute path works');

$not_exists = file_exists($WP_ROOT . '/wp-content/plugins/nonexistent.php');
assert_true(!$not_exists, 'file_exists returns false for missing file (absolute)');

// ============================================================
// TEST GROUP 2: Relative path access
// ============================================================
echo "\n# Test Group 2: Relative path access\n";

// CWD is WP_ROOT
$content = file_get_contents('wp-content/plugins/hello-dolly/lyrics.txt');
assert_true($content !== false, 'file_get_contents with relative path works');
assert_true(strpos($content, 'Hello, Dolly!') !== false, 'Content is correct via relative path');

$exists = file_exists('wp-content/themes/flavor/style.css');
assert_true($exists, 'file_exists with relative path works');

// ============================================================
// TEST GROUP 3: include/require (critical for plugins)
// ============================================================
echo "\n# Test Group 3: include/require\n";

// Include a PHP file from the store - this tests that Zend's file compiler
// uses our intercepted stream opener
$included = include($WP_ROOT . '/wp-includes/version.php');
assert_true($included !== false, 'include with absolute path works');
assert_true(isset($wp_version), '$wp_version variable set after include');
assert_eq($wp_version, '6.5.0', 'Included file executed correctly');

// ============================================================
// TEST GROUP 4: Plugin simulation - Hello Dolly pattern
// ============================================================
echo "\n# Test Group 4: Plugin simulation (Hello Dolly pattern)\n";

// Include the plugin file
include($WP_ROOT . '/wp-content/plugins/hello-dolly/hello.php');

// Test __DIR__ based file access
$lyric = hello_dolly_get_lyric();
assert_true(!empty($lyric), 'Plugin __DIR__ file read works: "' . $lyric . '"');

// Test absolute path access from plugin
$abs_content = hello_dolly_test_absolute();
assert_true($abs_content !== false, 'Plugin absolute path file_get_contents works');
assert_true(strpos($abs_content, 'Hello, Dolly!') !== false, 'Plugin absolute path content correct');

// Test scandir from plugin (__DIR__)
$entries = hello_dolly_test_scandir();
assert_true(is_array($entries), 'Plugin scandir(__DIR__) returns array');
assert_true(in_array('hello.php', $entries), 'Plugin scandir includes hello.php');
assert_true(in_array('lyrics.txt', $entries), 'Plugin scandir includes lyrics.txt');

// Test is_dir from plugin
assert_true(hello_dolly_test_is_dir(), 'Plugin is_dir(__DIR__) returns true');

// Test write from plugin (simulates cache file creation)
$cached = hello_dolly_test_file_put();
assert_eq($cached, 'cached data', 'Plugin file_put_contents + file_get_contents works');

// ============================================================
// TEST GROUP 5: Theme simulation
// ============================================================
echo "\n# Test Group 5: Theme simulation\n";

include($WP_ROOT . '/wp-content/themes/flavor/functions.php');

assert_true(flavor_template_exists('header'), 'Theme template_exists works');
assert_true(!flavor_template_exists('nonexistent'), 'Theme template_exists returns false for missing');

$tpl = flavor_load_template('header');
assert_eq($tpl, '<header>Flavor Theme</header>', 'Theme load_template reads correct content');

// ============================================================
// TEST GROUP 6: stat() and is_*() functions
// ============================================================
echo "\n# Test Group 6: stat/is_file/is_dir functions\n";

assert_true(is_file($WP_ROOT . '/wp-config.php'), 'is_file works for file');
assert_true(!is_file($WP_ROOT . '/wp-content'), 'is_file returns false for dir');
assert_true(is_dir($WP_ROOT . '/wp-content'), 'is_dir works for directory');
assert_true(!is_dir($WP_ROOT . '/wp-config.php'), 'is_dir returns false for file');

$stat = stat($WP_ROOT . '/wp-config.php');
assert_true($stat !== false, 'stat works on intercepted path');
assert_true($stat['size'] > 0, 'stat returns non-zero size');

// ============================================================
// TEST GROUP 7: Directory operations
// ============================================================
echo "\n# Test Group 7: mkdir/rmdir via absolute paths\n";

$new_dir = $WP_ROOT . '/wp-content/uploads/2024/01';
assert_true(mkdir($new_dir, 0755, true), 'mkdir recursive with absolute path');

$upload = $WP_ROOT . '/wp-content/uploads/2024/01/photo.jpg';
file_put_contents($upload, 'FAKE_JPEG_DATA');
assert_true(file_exists($upload), 'File created in new directory');
assert_eq(file_get_contents($upload), 'FAKE_JPEG_DATA', 'File content correct');

// ============================================================
// TEST GROUP 8: Branch isolation with intercepted paths
// ============================================================
echo "\n# Test Group 8: Branch isolation via intercepted paths\n";

// Switch to preview branch
branchfs_set_branch('preview-1');

// Should inherit main's files
$inherited = file_get_contents($WP_ROOT . '/wp-config.php');
assert_true($inherited !== false, 'Preview branch inherits main files via absolute path');
assert_true(strpos($inherited, 'DB_NAME') !== false, 'Inherited content correct');

// Write branch-specific file
file_put_contents($WP_ROOT . '/wp-content/plugins/hello-dolly/lyrics.txt',
    "Custom lyrics for preview!\n");

$preview_lyrics = file_get_contents($WP_ROOT . '/wp-content/plugins/hello-dolly/lyrics.txt');
assert_eq($preview_lyrics, "Custom lyrics for preview!\n", 'Preview branch has its own version');

// Switch back to main and verify isolation
branchfs_set_branch('main');
$main_lyrics = file_get_contents($WP_ROOT . '/wp-content/plugins/hello-dolly/lyrics.txt');
assert_true(strpos($main_lyrics, 'Hello, Dolly!') !== false,
    'Main branch unaffected by preview write');

// ============================================================
// TEST GROUP 9: Relative path with CWD change (plugin pattern)
// ============================================================
echo "\n# Test Group 9: Relative path with chdir (plugin pattern)\n";

// Create real subdirs for chdir (content still comes from SQLite)
@mkdir($WP_ROOT . '/wp-content/plugins/hello-dolly', 0755, true);
chdir($WP_ROOT . '/wp-content/plugins/hello-dolly');
clearstatcache(true);
$rel = file_get_contents('lyrics.txt');
assert_true($rel !== false, 'Relative file_get_contents after chdir works');
assert_true(strpos($rel, 'Hello, Dolly!') !== false, 'Relative path content correct after chdir');

$rel_exists = file_exists('hello.php');
assert_true($rel_exists, 'Relative file_exists after chdir works');

// ============================================================
// TEST GROUP 10: Rename via absolute path
// ============================================================
echo "\n# Test Group 10: Rename via absolute path\n";

file_put_contents($WP_ROOT . '/wp-content/rename-test.txt', 'rename me');
rename($WP_ROOT . '/wp-content/rename-test.txt', $WP_ROOT . '/wp-content/renamed.txt');
assert_true(!file_exists($WP_ROOT . '/wp-content/rename-test.txt'), 'Old name gone after rename');
assert_true(file_exists($WP_ROOT . '/wp-content/renamed.txt'), 'New name exists after rename');
assert_eq(file_get_contents($WP_ROOT . '/wp-content/renamed.txt'), 'rename me', 'Renamed file content correct');

// --- Cleanup ---
chdir($prev_cwd);
branchfs_deactivate();
@unlink($DB);
@unlink($DB . '-wal');
@unlink($DB . '-shm');
// Remove real WP_ROOT skeleton
exec("rm -rf " . escapeshellarg($WP_ROOT));

echo "\n=== Plugin Compatibility Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);

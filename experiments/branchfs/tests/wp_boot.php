<?php
/**
 * Demonstration: Boot a simulated WordPress from BranchFS store.
 * Shows the full flow: init DB → populate main → create branch → boot → serve.
 */

$pass = 0;
$fail = 0;

function assert_true($cond, $msg) {
    global $pass, $fail;
    if ($cond) { echo "  PASS: $msg\n"; $pass++; }
    else       { echo "  FAIL: $msg\n"; $fail++; }
}

$DB = '/tmp/branchfs_wp_boot_' . getmypid() . '.db';
$WP_ROOT = '/tmp/branchfs_wproot_boot_' . getmypid();

echo "=== BranchFS WordPress Boot Demo ===\n";
echo "DB:      $DB\n";
echo "WP Root: $WP_ROOT (virtual, not on disk)\n\n";

// Initialize database with schema
$sqlite = new SQLite3($DB);
$sqlite->exec(file_get_contents(__DIR__ . '/../schema.sql'));
$sqlite->close();

// Configure branchfs
branchfs_set_db($DB);
branchfs_set_root($WP_ROOT);
branchfs_set_branch('main');
branchfs_create_branch('main', null);

// Populate a minimal "WordPress" in the store
echo "# Populating simulated WordPress\n";

$wp_files = [
    'index.php' => '<?php require __DIR__ . "/wp-blog-header.php";',
    'wp-blog-header.php' => implode("\n", [
        '<?php',
        'require_once __DIR__ . "/wp-load.php";',
        'global $wp_did_boot;',
        '$wp_did_boot = true;',
    ]),
    'wp-load.php' => implode("\n", [
        '<?php',
        'require_once __DIR__ . "/wp-config.php";',
        'require_once __DIR__ . "/wp-includes/version.php";',
        'require_once __DIR__ . "/wp-includes/functions.php";',
    ]),
    'wp-config.php' => implode("\n", [
        '<?php',
        "define('DB_NAME', 'wordpress');",
        "define('ABSPATH', '$WP_ROOT/');",
        "define('WP_CONTENT_DIR', ABSPATH . 'wp-content');",
    ]),
    'wp-includes/version.php' => implode("\n", [
        '<?php',
        '$wp_version = "6.5.0-branchfs";',
        '$wp_db_version = 57155;',
    ]),
    'wp-includes/functions.php' => implode("\n", [
        '<?php',
        'function wp_branchfs_test_plugin_load() {',
        '    $plugin_dir = ABSPATH . "wp-content/plugins";',
        '    $plugins = scandir($plugin_dir);',
        '    $loaded = [];',
        '    foreach ($plugins as $p) {',
        '        if ($p === "." || $p === "..") continue;',
        '        $main_file = "$plugin_dir/$p/$p.php";',
        '        if (file_exists($main_file)) {',
        '            include_once $main_file;',
        '            $loaded[] = $p;',
        '        }',
        '    }',
        '    return $loaded;',
        '}',
    ]),
    'wp-content/plugins/sample/sample.php' => implode("\n", [
        '<?php',
        '/* Plugin Name: Sample Plugin */',
        '',
        'function sample_plugin_info() {',
        '    $readme = __DIR__ . "/readme.txt";',
        '    return [',
        '        "name" => "Sample Plugin",',
        '        "has_readme" => file_exists($readme),',
        '        "readme" => file_exists($readme) ? file_get_contents($readme) : null,',
        '        "dir" => __DIR__,',
        '    ];',
        '}',
    ]),
    'wp-content/plugins/sample/readme.txt' => 'Sample Plugin v1.0 - A demo plugin for BranchFS testing.',
    'wp-content/themes/flavor/style.css' => "/* Theme Name: Flavor */\n",
    'wp-content/themes/flavor/index.php' => '<?php echo "<h1>Flavor Theme</h1>";',
];

foreach ($wp_files as $path => $content) {
    $parts = explode('/', $path);
    array_pop($parts);
    $dir = '';
    foreach ($parts as $part) {
        $dir = $dir ? "$dir/$part" : $part;
        @mkdir("branchfs://main/$dir", 0755);
    }
    file_put_contents("branchfs://main/$path", $content);
}
echo "  Populated " . count($wp_files) . " files on main branch.\n\n";

// Create real WP_ROOT skeleton for chdir (content served from SQLite)
@mkdir($WP_ROOT . '/wp-content/plugins/sample', 0755, true);
@mkdir($WP_ROOT . '/wp-content/plugins/analytics', 0755, true);
@mkdir($WP_ROOT . '/wp-content/themes/flavor', 0755, true);
@mkdir($WP_ROOT . '/wp-includes', 0755, true);

// Activate interception
echo "# Activating interception and booting WordPress\n";
branchfs_activate();
chdir($WP_ROOT);

// Boot WordPress (simulated)
$wp_did_boot = false;
require $WP_ROOT . '/wp-blog-header.php';
assert_true($wp_did_boot, 'WordPress boot sequence completed');
assert_true(isset($wp_version), '$wp_version is set');
assert_true($wp_version === '6.5.0-branchfs', "Version: $wp_version");
assert_true(defined('ABSPATH'), 'ABSPATH is defined');

// Test plugin loading (simulates wp_loaded action)
echo "\n# Loading plugins\n";
$loaded_plugins = wp_branchfs_test_plugin_load();
assert_true(in_array('sample', $loaded_plugins), 'Sample plugin loaded');

$info = sample_plugin_info();
assert_true($info['name'] === 'Sample Plugin', 'Plugin name correct');
assert_true($info['has_readme'] === true, 'Plugin readme exists via __DIR__');
assert_true(strpos($info['readme'], 'Sample Plugin v1.0') !== false, 'Plugin readme content correct');
assert_true(strpos($info['dir'], $WP_ROOT) !== false, '__DIR__ returns real-looking path (not branchfs://)');

echo "\n# Testing branch preview\n";
// Create preview branch and modify plugin
branchfs_create_branch('preview-new-plugin', 'main');
branchfs_set_branch('preview-new-plugin');

// Modify the sample plugin on the preview branch
$new_readme = 'Sample Plugin v2.0 - Preview version with new features!';
file_put_contents($WP_ROOT . '/wp-content/plugins/sample/readme.txt', $new_readme);

// Add a new plugin on the preview branch
mkdir($WP_ROOT . '/wp-content/plugins/analytics', 0755);
file_put_contents($WP_ROOT . '/wp-content/plugins/analytics/analytics.php', implode("\n", [
    '<?php',
    '/* Plugin Name: Analytics */',
    'function analytics_version() { return "1.0-preview"; }',
]));

// Verify preview branch state
$preview_readme = file_get_contents($WP_ROOT . '/wp-content/plugins/sample/readme.txt');
assert_true($preview_readme === $new_readme, 'Preview branch has modified readme');

$preview_plugins = wp_branchfs_test_plugin_load();
assert_true(in_array('analytics', $preview_plugins), 'New plugin visible on preview branch');
assert_true(function_exists('analytics_version'), 'New plugin functions loaded');
assert_true(analytics_version() === '1.0-preview', 'New plugin returns correct version');

// Switch back to main - verify isolation
branchfs_set_branch('main');
$main_readme = file_get_contents($WP_ROOT . '/wp-content/plugins/sample/readme.txt');
assert_true(strpos($main_readme, 'v1.0') !== false, 'Main branch still has v1.0 readme');

$main_analytics = file_exists($WP_ROOT . '/wp-content/plugins/analytics/analytics.php');
assert_true(!$main_analytics, 'Analytics plugin NOT visible on main branch');

// Cleanup
branchfs_deactivate();
@unlink($DB);
@unlink($DB . '-wal');
@unlink($DB . '-shm');
exec("rm -rf " . escapeshellarg($WP_ROOT));

echo "\n=== WordPress Boot Demo Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);

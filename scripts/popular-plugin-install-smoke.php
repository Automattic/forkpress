<?php
/**
 * Download and unpack popular WordPress plugin packages into a temporary
 * wp-content/plugins tree. This is a package-shape smoke test for ForkPress
 * plugin installation support; it does not activate plugins or execute plugin
 * code.
 */

error_reporting(E_ALL);

function usage(): void {
    echo "Usage: php scripts/popular-plugin-install-smoke.php [options]\n";
    echo "\n";
    echo "Options:\n";
    echo "  --manifest <path>   Popular plugin manifest JSON.\n";
    echo "  --cache-dir <path>  Directory for downloaded plugin zips.\n";
    echo "  --slug <slug>       Smoke one plugin slug. Can be passed more than once.\n";
    echo "  --limit <n>         Smoke the first n manifest plugins.\n";
    echo "  --help              Show this help.\n";
}

function fail(string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function option_value(array $argv, int &$index, string $option): string {
    $value = $argv[$index + 1] ?? '';
    if ($value === '' || str_starts_with($value, '--')) {
        fail("$option requires a value");
    }
    $index++;
    return $value;
}

function parse_args(array $argv): array {
    $options = [
        'manifest' => __DIR__ . '/../runtime/cow/plugin-compat/popular-wordpress-plugins.json',
        'cache_dir' => sys_get_temp_dir() . '/forkpress-popular-plugin-zips',
        'slugs' => [],
        'limit' => null,
    ];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if ($arg === '--manifest') {
            $options['manifest'] = option_value($argv, $i, $arg);
        } elseif ($arg === '--cache-dir') {
            $options['cache_dir'] = option_value($argv, $i, $arg);
        } elseif ($arg === '--slug') {
            $options['slugs'][] = option_value($argv, $i, $arg);
        } elseif ($arg === '--limit') {
            $value = option_value($argv, $i, $arg);
            if (!preg_match('/^[1-9][0-9]*$/', $value)) {
                fail('--limit requires a positive integer');
            }
            $options['limit'] = (int)$value;
        } elseif ($arg === '--help') {
            usage();
            exit(0);
        } else {
            fail("Unsupported option: $arg");
        }
    }
    return $options;
}

function mkdir_p(string $path): void {
    if (is_dir($path)) {
        return;
    }
    if (!mkdir($path, 0755, true) && !is_dir($path)) {
        fail("Failed to create $path");
    }
}

function rm_tree(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
    @rmdir($path);
}

function read_manifest(string $path): array {
    $json = file_get_contents($path);
    if ($json === false) {
        fail("Failed to read manifest: $path");
    }
    $manifest = json_decode($json, true);
    if (!is_array($manifest) || !isset($manifest['plugins']) || !is_array($manifest['plugins'])) {
        fail("Manifest is not a popular plugin compatibility manifest: $path");
    }
    return $manifest['plugins'];
}

function selected_plugins(array $plugins, array $options): array {
    if ($options['slugs'] !== []) {
        $wanted = array_fill_keys($options['slugs'], true);
        $selected = array_values(array_filter($plugins, static function (array $plugin) use ($wanted): bool {
            return isset($wanted[(string)($plugin['slug'] ?? '')]);
        }));
        $found = array_fill_keys(array_map(static fn(array $plugin): string => (string)$plugin['slug'], $selected), true);
        foreach ($options['slugs'] as $slug) {
            if (!isset($found[$slug])) {
                fail("Manifest does not contain requested plugin slug: $slug");
            }
        }
        return $selected;
    }
    if ($options['limit'] !== null) {
        return array_slice($plugins, 0, $options['limit']);
    }
    return $plugins;
}

function zip_path_is_safe(string $name): bool {
    if ($name === '' || str_starts_with($name, '/') || str_contains($name, "\0") || str_contains($name, '\\')) {
        return false;
    }
    foreach (explode('/', $name) as $part) {
        if ($part === '..') {
            return false;
        }
    }
    return true;
}

function download_zip(array $plugin, string $cache_dir): string {
    $slug = (string)$plugin['slug'];
    $url = (string)($plugin['download_link'] ?? '');
    if ($url === '') {
        fail("$slug has no download_link");
    }
    mkdir_p($cache_dir);
    $zip_path = rtrim($cache_dir, '/\\') . '/' . $slug . '.zip';
    if (is_file($zip_path) && filesize($zip_path) > 0) {
        return $zip_path;
    }
    $context = stream_context_create([
        'http' => [
            'header' => "User-Agent: ForkPress popular plugin install smoke\r\n",
            'timeout' => 60,
        ],
    ]);
    $body = file_get_contents($url, false, $context);
    if ($body === false || $body === '') {
        fail("Failed to download $slug from $url");
    }
    if (file_put_contents($zip_path, $body) === false) {
        fail("Failed to write $zip_path");
    }
    return $zip_path;
}

function smoke_zip(array $plugin, string $zip_path): array {
    $slug = (string)$plugin['slug'];
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        fail("Failed to open zip for $slug: $zip_path");
    }

    $top_dirs = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (!is_string($name) || !zip_path_is_safe($name)) {
            $zip->close();
            fail("$slug zip contains unsafe path: " . var_export($name, true));
        }
        $parts = explode('/', trim($name, '/'));
        if (($parts[0] ?? '') !== '') {
            $top_dirs[$parts[0]] = true;
        }
    }
    if (count($top_dirs) !== 1) {
        $zip->close();
        fail("$slug zip must contain one top-level plugin directory");
    }
    $top_dir = array_key_first($top_dirs);
    if ($top_dir !== $slug) {
        $zip->close();
        fail("$slug zip top-level directory is $top_dir, expected $slug");
    }

    $tmp = sys_get_temp_dir() . '/forkpress-plugin-install-smoke-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $plugins_dir = $tmp . '/wp-content/plugins';
    mkdir_p($plugins_dir);
    if (!$zip->extractTo($plugins_dir)) {
        $zip->close();
        rm_tree($tmp);
        fail("Failed to extract $slug");
    }
    $zip->close();

    $plugin_root = $plugins_dir . '/' . $slug;
    $header_file = find_plugin_header($plugin_root);
    $file_count = count_files($plugin_root);
    rm_tree($tmp);

    if ($header_file === null) {
        fail("$slug did not expose a WordPress plugin header after extraction");
    }

    return [
        'slug' => $slug,
        'files' => $file_count,
        'header' => substr($header_file, strlen($plugin_root) + 1),
    ];
}

function find_plugin_header(string $plugin_root): ?string {
    foreach (glob(rtrim($plugin_root, '/\\') . '/*.php') ?: [] as $path) {
        if (file_has_plugin_header($path)) {
            return $path;
        }
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($plugin_root, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($it as $entry) {
        if (!$entry->isFile() || strtolower($entry->getExtension()) !== 'php') {
            continue;
        }
        $path = $entry->getPathname();
        if (file_has_plugin_header($path)) {
            return $path;
        }
    }
    return null;
}

function file_has_plugin_header(string $path): bool {
    $prefix = file_get_contents($path, false, null, 0, 8192);
    return is_string($prefix) && preg_match('/^[ \t\/*#@]*Plugin Name:/mi', $prefix) === 1;
}

function count_files(string $root): int {
    $count = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($it as $entry) {
        if ($entry->isFile()) {
            $count++;
        }
    }
    return $count;
}

if (!class_exists(ZipArchive::class)) {
    fail('The PHP zip extension is required for the popular plugin install smoke test.');
}

$options = parse_args($argv);
$plugins = selected_plugins(read_manifest((string)$options['manifest']), $options);
if ($plugins === []) {
    fail('No plugins selected for install smoke test.');
}

$passed = [];
foreach ($plugins as $plugin) {
    $zip_path = download_zip($plugin, (string)$options['cache_dir']);
    $result = smoke_zip($plugin, $zip_path);
    $passed[] = $result;
    echo "PASS {$result['slug']} files={$result['files']} header={$result['header']}\n";
}

echo "Popular plugin install smoke passed for " . count($passed) . " plugin" . (count($passed) === 1 ? '' : 's') . ".\n";

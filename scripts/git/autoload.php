<?php
/**
 * Autoloader for vendored WordPress php-toolkit components.
 *
 * Maps WordPress\* namespaces to vendor/wordpress-php-toolkit/components/.
 * Also provides polyfills for WordPress functions used by the toolkit.
 */

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('_doing_it_wrong')) {
    function _doing_it_wrong($function, $message, $version) {
        trigger_error("$function: $message (since $version)", E_USER_NOTICE);
    }
}

spl_autoload_register(function ($class) {
    $prefix = 'WordPress\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $parts = explode('\\', $relative);
    $component = array_shift($parts);
    $class_name = array_pop($parts);

    $base = __DIR__ . '/../../vendor/wordpress-php-toolkit/components/' . $component;

    $subdir = '';
    if (!empty($parts)) {
        $subdir = '/' . implode('/', $parts);
    }

    $file = $base . $subdir . '/class-' . strtolower(preg_replace('/([a-z])([A-Z])/', '$1$2', str_replace('_', '', $class_name)));

    // Try WP-style naming: class-lowercasename.php
    $wp_name = strtolower($class_name);
    $wp_name = preg_replace('/([a-z])([A-Z])/', '$1-$2', $class_name);
    $wp_name = strtolower(str_replace('_', '', $wp_name));

    $candidates = [
        $base . $subdir . '/class-' . $wp_name . '.php',
        $base . $subdir . '/interface-' . $wp_name . '.php',
        $base . $subdir . '/trait-' . $wp_name . '.php',
    ];

    foreach ($candidates as $candidate) {
        if (file_exists($candidate)) {
            require_once $candidate;
            return;
        }
    }

    // Fallback: try to match by scanning the directory
    $dir = $base . $subdir;
    if (is_dir($dir)) {
        $lower = strtolower($class_name);
        foreach (scandir($dir) as $f) {
            if ($f[0] === '.') continue;
            $base_f = pathinfo($f, PATHINFO_FILENAME);
            // Strip class-/interface-/trait- prefix and compare
            $stripped = preg_replace('/^(class|interface|trait)-/', '', $base_f);
            if (strtolower($stripped) === $lower) {
                require_once $dir . '/' . $f;
                return;
            }
        }
    }
});

// Pre-load function files that define namespace-level functions
$function_files = [
    __DIR__ . '/../../vendor/wordpress-php-toolkit/components/Filesystem/functions.php',
    __DIR__ . '/../../vendor/wordpress-php-toolkit/components/Git/functions.php',
];
foreach ($function_files as $f) {
    if (file_exists($f)) {
        require_once $f;
    }
}

<?php
/**
 * Plugin Name: ForkPress Experimental WordPress Integration
 * Description: Adds WordPress hooks needed by experimental ForkPress storage runtimes.
 * Version: 0.1.0
 */

if (!defined('ABSPATH')) exit;

function forkpress_experiment_current_branch(): ?string {
    if (function_exists('branchfs_get_branch')) {
        $branch = branchfs_get_branch();
        if (is_string($branch) && $branch !== '') {
            return $branch;
        }
    }

    $branch = $_SERVER['BRANCHFS_BRANCH'] ?? '';
    return is_string($branch) && $branch !== '' ? $branch : null;
}

function forkpress_experiment_root_host(): ?string {
    $root_host = getenv('BRANCHFS_ROOT_HOST');
    return is_string($root_host) && $root_host !== '' ? $root_host : null;
}

/**
 * Redirect uploaded files through BranchFS when the experimental BranchFS
 * runtime is active. Materialized COW branches fall through to WordPress'
 * ordinary filesystem handling.
 */
add_filter('pre_move_uploaded_file', function ($move_new_file, $file, $new_file, $type) {
    if (!function_exists('branchfs_is_active') || !branchfs_is_active()) {
        return $move_new_file;
    }

    // WP 6.5+ passes the full $_FILES entry as arg 2; older versions sometimes
    // pass the tmp_name string directly. Accept either.
    $src = is_array($file) ? ($file['tmp_name'] ?? '') : (string) $file;
    if ($src === '' || !is_uploaded_file($src) && !file_exists($src)) {
        return $move_new_file;
    }

    $content = file_get_contents($src);
    if ($content === false) {
        return $move_new_file;
    }

    $dir = dirname($new_file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $result = file_put_contents($new_file, $content);
    if ($result === false) {
        return $move_new_file;
    }

    @unlink($src);

    // Return true to signal we handled the move (non-null short-circuits
    // WP's default @move_uploaded_file / @copy+unlink block).
    return true;
}, 10, 4);

/**
 * Override the WordPress filesystem method to 'direct' when BranchFS is active.
 * This ensures WordPress uses PHP file functions rather than FTP/SSH methods.
 */
add_filter('filesystem_method', function ($method) {
    if (function_exists('branchfs_is_active') && branchfs_is_active()) {
        return 'direct';
    }
    return $method;
});

/**
 * Ensure WP_Filesystem uses direct file access through our interceptor.
 */
add_filter('request_filesystem_credentials', function ($credentials) {
    if (function_exists('branchfs_is_active') && branchfs_is_active()) {
        return true;
    }
    return $credentials;
});

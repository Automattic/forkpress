<?php
/**
 * Plugin Name: BranchFS WordPress Integration
 * Description: Integrates BranchFS branch-scoped preview with WordPress.
 *              Redirects uploads, adjusts filesystem operations, and provides
 *              branch-aware admin UI hints.
 * Version: 0.1.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Redirect uploaded files into branchfs:// store.
 * Uses the pre_move_uploaded_file hook (WP 5.7+) to intercept uploads
 * before they hit the real filesystem.
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
 * Override the WordPress filesystem method to 'direct' when branchfs is active.
 * This ensures WP uses PHP file functions (which we intercept) rather than
 * FTP/SSH methods.
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

/**
 * Add branch indicator to admin bar.
 */
add_action('admin_bar_menu', function ($wp_admin_bar) {
    if (!function_exists('branchfs_get_branch')) return;

    $branch = branchfs_get_branch();
    if (!$branch || $branch === 'main') return;

    $wp_admin_bar->add_node([
        'id'    => 'branchfs-indicator',
        'title' => '&#9733; Branch: ' . esc_html($branch),
        'meta'  => ['class' => 'branchfs-branch-indicator'],
    ]);
}, 100);

/**
 * Style the branch indicator.
 */
add_action('admin_head', function () {
    if (!function_exists('branchfs_get_branch')) return;
    $branch = branchfs_get_branch();
    if (!$branch || $branch === 'main') return;

    echo '<style>
        #wpadminbar .branchfs-branch-indicator .ab-item {
            background: #2271b1 !important;
            color: #fff !important;
        }
    </style>';
});


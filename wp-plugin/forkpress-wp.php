<?php
/**
 * Plugin Name: ForkPress WordPress Integration
 * Description: Integrates ForkPress branch previews with WordPress.
 *              Handles local preview auth and branch-aware admin UI hints.
 * Version: 0.1.0
 */

if (!defined('ABSPATH')) exit;

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

function forkpress_env_is_disabled(string $name): bool {
    $value = getenv($name);
    if ($value === false) {
        return false;
    }

    return in_array(strtolower(trim((string) $value)), ['0', 'false', 'no', 'off'], true);
}

function forkpress_auto_login_enabled(): bool {
    if (defined('FORKPRESS_AUTO_LOGIN')) {
        return (bool) FORKPRESS_AUTO_LOGIN;
    }

    return !forkpress_env_is_disabled('FORKPRESS_AUTO_LOGIN');
}

function forkpress_current_branch(): ?string {
    if (function_exists('branchfs_get_branch')) {
        $branch = branchfs_get_branch();
        if (is_string($branch) && $branch !== '') {
            return $branch;
        }
    }

    $branch = getenv('FORKPRESS_BRANCH');
    if (is_string($branch) && $branch !== '') {
        return $branch;
    }

    $branch = $_SERVER['FORKPRESS_BRANCH'] ?? '';
    if (is_string($branch) && $branch !== '') {
        return $branch;
    }

    $branch = $_SERVER['BRANCHFS_BRANCH'] ?? '';
    return is_string($branch) && $branch !== '' ? $branch : null;
}

if (!function_exists('auth_redirect')) {
    function auth_redirect() {
        if (forkpress_auto_login_enabled()) {
            $user = wp_get_current_user();
            if ($user && !empty($user->ID)) {
                do_action('auth_redirect', (int) $user->ID);
                return;
            }
        }

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $secure = apply_filters('secure_auth_redirect', is_ssl() || force_ssl_admin());
        if ($secure && !is_ssl() && strpos($request_uri, 'wp-admin') !== false) {
            if (strpos($request_uri, 'http') === 0) {
                wp_redirect(set_url_scheme($request_uri, 'https'));
            } else {
                wp_redirect('https://' . $_SERVER['HTTP_HOST'] . $request_uri);
            }
            exit;
        }

        $scheme = apply_filters('auth_redirect_scheme', '');
        $user_id = wp_validate_auth_cookie('', $scheme);
        if ($user_id) {
            do_action('auth_redirect', $user_id);
            if (!$secure && get_user_option('use_ssl', $user_id) && strpos($request_uri, 'wp-admin') !== false) {
                if (strpos($request_uri, 'http') === 0) {
                    wp_redirect(set_url_scheme($request_uri, 'https'));
                } else {
                    wp_redirect('https://' . $_SERVER['HTTP_HOST'] . $request_uri);
                }
                exit;
            }
            return;
        }

        nocache_headers();
        $redirect = (strpos($request_uri, '/options.php') !== false && wp_get_referer())
            ? wp_get_referer()
            : set_url_scheme('http://' . $_SERVER['HTTP_HOST'] . $request_uri);
        wp_redirect(wp_login_url($redirect, true));
        exit;
    }
}

/**
 * Local previews are disposable, so make admin available without a login form
 * unless FORKPRESS_AUTO_LOGIN=0 or FORKPRESS_AUTO_LOGIN is defined false.
 */
add_action('init', function () {
    if (!forkpress_auto_login_enabled() || is_user_logged_in()) {
        return;
    }
    if ((defined('WP_INSTALLING') && WP_INSTALLING) || (defined('DOING_CRON') && DOING_CRON)) {
        return;
    }
    if (($_REQUEST['action'] ?? '') === 'logout') {
        return;
    }

    $user = get_user_by('login', 'admin');
    if (!$user) {
        $admins = get_users([
            'role'   => 'administrator',
            'number' => 1,
            'fields' => 'all',
        ]);
        $user = $admins[0] ?? null;
    }
    if (!$user || empty($user->ID)) {
        return;
    }

    $user_id = (int) $user->ID;
    wp_set_current_user($user_id);

    if (forkpress_current_branch() !== 'main' || headers_sent()) {
        return;
    }

    $captured_auth = null;
    $captured_logged_in = null;
    $captured_scheme = null;

    $capture_auth = function ($auth_cookie, $expire, $expiration, $cookie_user_id, $scheme) use (&$captured_auth, &$captured_scheme, $user_id) {
        if ((int) $cookie_user_id === $user_id) {
            $captured_auth = $auth_cookie;
            $captured_scheme = $scheme;
        }
    };
    $capture_logged_in = function ($logged_in_cookie, $expire, $expiration, $cookie_user_id) use (&$captured_logged_in, $user_id) {
        if ((int) $cookie_user_id === $user_id) {
            $captured_logged_in = $logged_in_cookie;
        }
    };

    add_action('set_auth_cookie', $capture_auth, 10, 6);
    add_action('set_logged_in_cookie', $capture_logged_in, 10, 6);
    wp_set_auth_cookie($user_id, true, is_ssl());
    remove_action('set_auth_cookie', $capture_auth, 10);
    remove_action('set_logged_in_cookie', $capture_logged_in, 10);

    $auth_cookie_name = $captured_scheme === 'secure_auth' ? SECURE_AUTH_COOKIE : AUTH_COOKIE;
    if (is_string($captured_auth) && $captured_auth !== '') {
        $_COOKIE[$auth_cookie_name] = $captured_auth;
    }
    if (is_string($captured_logged_in) && $captured_logged_in !== '') {
        $_COOKIE[LOGGED_IN_COOKIE] = $captured_logged_in;
    }
}, 1);

function forkpress_db_path(): ?string {
    $path = getenv('BRANCHFS_DB');
    if (is_string($path) && $path !== '') {
        return $path;
    }
    if (defined('FQDB') && is_string(FQDB) && FQDB !== '') {
        return FQDB;
    }
    return null;
}

function forkpress_branch_url(string $branch): string {
    $root_host = getenv('FORKPRESS_ROOT_HOST');
    if (!is_string($root_host) || $root_host === '') {
        $root_host = getenv('BRANCHFS_ROOT_HOST');
    }
    if (!is_string($root_host) || $root_host === '') {
        $root_host = 'wp.localhost';
    }

    $current_host = $_SERVER['HTTP_HOST'] ?? '';
    $port = preg_match('/:(\d+)$/', $current_host, $m) ? ':' . $m[1] : '';
    $host = $branch === 'main' ? $root_host : $branch . '.' . $root_host;
    $scheme = is_ssl() ? 'https' : 'http';
    $uri = $_SERVER['REQUEST_URI'] ?? '/wp-admin/';
    if (!is_string($uri) || $uri === '') {
        $uri = '/wp-admin/';
    }

    return $scheme . '://' . $host . $port . $uri;
}

function forkpress_local_branches(string $current_branch): array {
    $branch_list = getenv('FORKPRESS_BRANCH_LIST');
    if (is_string($branch_list) && $branch_list !== '' && is_readable($branch_list)) {
        $branches = [];
        foreach (file($branch_list, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $name = trim((string) $line);
            if ($name !== '' && preg_match('/^[a-zA-Z0-9_\-]{1,63}$/', $name)) {
                $branches[] = $name;
            }
        }
        if ($branches) {
            usort($branches, function (string $a, string $b) use ($current_branch): int {
                if ($a === $current_branch) return -1;
                if ($b === $current_branch) return 1;
                if ($a === 'main') return -1;
                if ($b === 'main') return 1;
                return strnatcasecmp($a, $b);
            });
            return array_values(array_unique($branches));
        }
    }

    $db_path = forkpress_db_path();
    if (!$db_path || !class_exists('SQLite3') || !is_readable($db_path)) {
        return [$current_branch];
    }

    try {
        $db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
        $db->busyTimeout(200);
        $stmt = $db->prepare(
            "SELECT name FROM branches
             ORDER BY CASE WHEN name = :current THEN 0 WHEN name = 'main' THEN 1 ELSE 2 END,
                      datetime(created_at) DESC,
                      name ASC
             LIMIT 200"
        );
        $stmt->bindValue(':current', $current_branch, SQLITE3_TEXT);
        $result = $stmt->execute();
        $branches = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (!empty($row['name']) && is_string($row['name'])) {
                $branches[] = $row['name'];
            }
        }
        $db->close();
    } catch (Throwable $e) {
        return [$current_branch];
    }

    return $branches ?: [$current_branch];
}

add_action('admin_bar_menu', function ($wp_admin_bar) {
    $branch = forkpress_current_branch();
    if (!$branch) {
        return;
    }

    $wp_admin_bar->add_node([
        'id'    => 'forkpress-branch-indicator',
        'title' => 'Branch: ' . esc_html($branch),
        'href'  => forkpress_branch_url($branch),
        'meta'  => ['class' => 'forkpress-branch-indicator'],
    ]);
}, 100);

function forkpress_branch_switcher_assets(): void {
    $branch = forkpress_current_branch();
    if (!$branch || !is_admin_bar_showing()) {
        return;
    }

    echo '<style>
        #wpadminbar #wp-admin-bar-forkpress-branch-indicator > .ab-item {
            background: #2271b1 !important;
            color: #fff !important;
        }
        #wpadminbar #wp-admin-bar-forkpress-branch-indicator {
            position: relative;
        }
        #wpadminbar .forkpress-switcher-panel {
            background: #1d2327;
            border: 1px solid #3c434a;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.28);
            box-sizing: border-box;
            color: #f0f0f1;
            display: none;
            left: 0;
            padding: 10px;
            position: absolute;
            top: 32px;
            width: 280px;
            z-index: 99999;
        }
        #wpadminbar #wp-admin-bar-forkpress-branch-indicator:hover .forkpress-switcher-panel,
        #wpadminbar #wp-admin-bar-forkpress-branch-indicator.forkpress-switcher-open .forkpress-switcher-panel {
            display: block;
        }
        #wpadminbar .forkpress-switcher-filter {
            background: #fff;
            border: 1px solid #8c8f94;
            border-radius: 3px;
            box-sizing: border-box;
            color: #1d2327;
            font-size: 13px;
            height: 30px;
            line-height: 1.4;
            margin: 0 0 8px;
            padding: 4px 8px;
            width: 100%;
        }
        #wpadminbar .forkpress-switcher-list {
            max-height: 360px;
            overflow: auto;
        }
        #wpadminbar .forkpress-switcher-branch {
            border-radius: 3px;
            box-sizing: border-box;
            color: #f0f0f1 !important;
            display: block;
            font-size: 13px;
            line-height: 1.4;
            overflow: hidden;
            padding: 7px 8px;
            text-decoration: none;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        #wpadminbar .forkpress-switcher-branch:hover,
        #wpadminbar .forkpress-switcher-branch:focus {
            background: #2c3338;
            color: #fff !important;
            outline: none;
        }
        #wpadminbar .forkpress-switcher-branch.is-current {
            background: #2271b1;
            color: #fff !important;
        }
        #wpadminbar .forkpress-switcher-empty {
            color: #c3c4c7;
            font-size: 13px;
            line-height: 1.4;
            padding: 7px 8px;
        }
    </style>';
}
add_action('admin_head', 'forkpress_branch_switcher_assets');
add_action('wp_head', 'forkpress_branch_switcher_assets');

function forkpress_render_branch_switcher(): void {
    static $rendered = false;
    if ($rendered || !is_admin_bar_showing()) {
        return;
    }

    $current = forkpress_current_branch();
    if (!$current) {
        return;
    }

    $rendered = true;
    $branches = array_values(array_unique(forkpress_local_branches($current)));
    $data = array_map(function (string $branch) use ($current): array {
        return [
            'name'    => $branch,
            'url'     => forkpress_branch_url($branch),
            'current' => $branch === $current,
        ];
    }, $branches);
    $json = function_exists('wp_json_encode') ? wp_json_encode($data) : json_encode($data);
    if (!is_string($json) || $json === '') {
        return;
    }
    ?>
    <script>
    (function () {
        var item = document.getElementById('wp-admin-bar-forkpress-branch-indicator');
        if (!item || item.querySelector('.forkpress-switcher-panel')) {
            return;
        }

        var branches = <?php echo $json; ?>;
        var panel = document.createElement('div');
        panel.className = 'forkpress-switcher-panel';
        panel.innerHTML = '<input class="forkpress-switcher-filter" type="search" autocomplete="off" placeholder="Filter branches" aria-label="Filter branches"><div class="forkpress-switcher-list" role="menu"></div>';
        item.appendChild(panel);

        var input = panel.querySelector('.forkpress-switcher-filter');
        var list = panel.querySelector('.forkpress-switcher-list');

        function render() {
            var query = input.value.toLowerCase();
            var matches = branches.filter(function (branch) {
                return !query || branch.name.toLowerCase().indexOf(query) !== -1;
            }).slice(0, 20);

            list.innerHTML = '';
            if (!matches.length) {
                var empty = document.createElement('div');
                empty.className = 'forkpress-switcher-empty';
                empty.textContent = 'No branches';
                list.appendChild(empty);
                return;
            }

            matches.forEach(function (branch) {
                var link = document.createElement('a');
                link.className = 'forkpress-switcher-branch' + (branch.current ? ' is-current' : '');
                link.href = branch.url;
                link.role = 'menuitem';
                link.textContent = branch.name;
                list.appendChild(link);
            });
        }

        item.addEventListener('mouseenter', function () {
            item.classList.add('forkpress-switcher-open');
            window.setTimeout(function () { input.focus(); }, 0);
        });
        item.addEventListener('mouseleave', function () {
            item.classList.remove('forkpress-switcher-open');
        });
        panel.addEventListener('click', function (event) {
            event.stopPropagation();
        });
        input.addEventListener('input', render);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                input.value = '';
                render();
                input.blur();
            }
        });
        render();
    }());
    </script>
    <?php
}
add_action('admin_footer', 'forkpress_render_branch_switcher');
add_action('wp_footer', 'forkpress_render_branch_switcher');

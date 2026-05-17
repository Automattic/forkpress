<?php
/**
 * Plugin Name: ForkPress WordPress Integration
 * Description: Integrates ForkPress branch previews with WordPress.
 *              Handles local preview auth and branch-aware admin UI hints.
 * Version: 0.1.0
 */

if (!defined('ABSPATH')) exit;

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
    if (function_exists('forkpress_experiment_current_branch')) {
        $branch = forkpress_experiment_current_branch();
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

    return null;
}

function forkpress_auto_login_user_id(): ?int {
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
        return null;
    }

    return (int) $user->ID;
}

function forkpress_maybe_auto_login_current_user(): ?int {
    if (!forkpress_auto_login_enabled()) {
        return null;
    }

    $current = wp_get_current_user();
    if ($current && !empty($current->ID)) {
        return (int) $current->ID;
    }

    if ((defined('WP_INSTALLING') && WP_INSTALLING) || (defined('DOING_CRON') && DOING_CRON)) {
        return null;
    }
    if (($_REQUEST['action'] ?? '') === 'logout') {
        return null;
    }

    $user_id = forkpress_auto_login_user_id();
    if ($user_id === null) {
        return null;
    }

    wp_set_current_user($user_id);
    return $user_id;
}

if (!function_exists('auth_redirect')) {
    function auth_redirect() {
        if (forkpress_auto_login_enabled()) {
            $user_id = forkpress_maybe_auto_login_current_user();
            if ($user_id !== null) {
                do_action('auth_redirect', $user_id);
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

    $user_id = forkpress_maybe_auto_login_current_user();
    if ($user_id === null) {
        return;
    }

    if (headers_sent()) {
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
    if (defined('FQDB') && is_string(FQDB) && FQDB !== '') {
        return FQDB;
    }
    return null;
}

function forkpress_cow_sqlite_identifier(string $name): string {
    return '"' . str_replace('"', '""', $name) . '"';
}

function forkpress_cow_sqlite_pdo(): ?PDO {
    global $wpdb;

    try {
        if (!isset($wpdb) || !is_object($wpdb) || !isset($wpdb->dbh) || !is_object($wpdb->dbh)) {
            return null;
        }
        if (!method_exists($wpdb->dbh, 'get_connection')) {
            return null;
        }
        $connection = $wpdb->dbh->get_connection();
        if (!is_object($connection) || !method_exists($connection, 'get_pdo')) {
            return null;
        }
        $pdo = $connection->get_pdo();
        return $pdo instanceof PDO ? $pdo : null;
    } catch (Throwable $e) {
        return null;
    }
}

function forkpress_cow_keyless_tables(PDO $pdo): array {
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    $tables = [];
    foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [] as $table) {
        if (!is_string($table) || $table === '') {
            continue;
        }
        $info = $pdo->query('PRAGMA table_info(' . forkpress_cow_sqlite_identifier($table) . ')');
        if (!$info) {
            continue;
        }
        $has_pk = false;
        foreach ($info->fetchAll(PDO::FETCH_ASSOC) as $column) {
            if ((int)($column['pk'] ?? 0) > 0) {
                $has_pk = true;
                break;
            }
        }
        if (!$has_pk) {
            $tables[] = $table;
        }
    }
    return $tables;
}

function forkpress_cow_sqlite_json_available(PDO $pdo): bool {
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    try {
        $stmt = $pdo->query("SELECT json_object('ok', 1)");
        $available = $stmt !== false && $stmt->fetchColumn() === '{"ok":1}';
    } catch (Throwable $e) {
        $available = false;
    }
    return $available;
}

function forkpress_cow_row_identity_payload_expr(PDO $pdo, string $table, string $row_alias): string {
    if (!forkpress_cow_sqlite_json_available($pdo)) {
        return 'NULL';
    }

    $info = $pdo->query('PRAGMA table_info(' . forkpress_cow_sqlite_identifier($table) . ')');
    if (!$info) {
        return 'NULL';
    }

    $parts = [];
    foreach ($info->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $name = $column['name'] ?? null;
        if (!is_string($name) || $name === '') {
            continue;
        }
        $value = $row_alias . '.' . forkpress_cow_sqlite_identifier($name);
        $encoded_value = "CASE typeof($value) WHEN 'blob' THEN hex($value) ELSE $value END";
        $parts[] = $pdo->quote($name);
        $parts[] = "json_object('type', typeof($value), 'value', $encoded_value)";
    }

    if (!$parts) {
        return "json_object()";
    }
    return 'json_object(' . implode(', ', $parts) . ')';
}

function forkpress_cow_prepare_row_identity_tracking(PDO $pdo, bool $clear_events): void {
    $pdo->exec(
        'CREATE TEMP TABLE IF NOT EXISTS forkpress_row_identity_events (' .
        'id INTEGER PRIMARY KEY AUTOINCREMENT, table_name TEXT NOT NULL, op TEXT NOT NULL, rowid INTEGER NOT NULL, row_payload TEXT)'
    );
    $columns = $pdo->query('PRAGMA temp.table_info(forkpress_row_identity_events)');
    $has_payload = false;
    foreach ($columns ? $columns->fetchAll(PDO::FETCH_ASSOC) : [] as $column) {
        if (($column['name'] ?? null) === 'row_payload') {
            $has_payload = true;
            break;
        }
    }
    if (!$has_payload) {
        $pdo->exec('ALTER TABLE temp.forkpress_row_identity_events ADD COLUMN row_payload TEXT');
    }
    if ($clear_events) {
        $pdo->exec('DELETE FROM temp.forkpress_row_identity_events');
    }

    foreach (forkpress_cow_keyless_tables($pdo) as $table) {
        $hash = substr(hash('sha256', $table), 0, 24);
        $quoted_table = forkpress_cow_sqlite_identifier($table);
        $table_literal = $pdo->quote($table);
        $insert_payload = forkpress_cow_row_identity_payload_expr($pdo, $table, 'new');
        $delete_payload = forkpress_cow_row_identity_payload_expr($pdo, $table, 'old');
        $insert_trigger = forkpress_cow_sqlite_identifier('forkpress_rid_' . $hash . '_ai');
        $delete_trigger = forkpress_cow_sqlite_identifier('forkpress_rid_' . $hash . '_ad');
        $pdo->exec(
            "CREATE TEMP TRIGGER IF NOT EXISTS $insert_trigger AFTER INSERT ON $quoted_table " .
            "BEGIN INSERT INTO forkpress_row_identity_events(table_name, op, rowid, row_payload) VALUES ($table_literal, 'insert', new.rowid, $insert_payload); END"
        );
        $pdo->exec(
            "CREATE TEMP TRIGGER IF NOT EXISTS $delete_trigger AFTER DELETE ON $quoted_table " .
            "BEGIN INSERT INTO forkpress_row_identity_events(table_name, op, rowid, row_payload) VALUES ($table_literal, 'delete', old.rowid, $delete_payload); END"
        );
    }
}

function forkpress_cow_refresh_row_identity_tracking(): void {
    $pdo = forkpress_cow_sqlite_pdo();
    if (!$pdo) {
        return;
    }

    forkpress_cow_prepare_row_identity_tracking($pdo, false);
}

function forkpress_cow_query_is_table_ddl(string $query): bool {
    return (bool) preg_match('/^\s*(?:CREATE|DROP|ALTER)\s+(?:TEMP(?:ORARY)?\s+)?TABLE\b/i', $query);
}

function forkpress_cow_row_identity_query_filter($query) {
    if (!is_string($query)) {
        return $query;
    }

    try {
        if (
            !empty($GLOBALS['forkpress_cow_row_identity_refresh_pending']) &&
            !forkpress_cow_query_is_table_ddl($query)
        ) {
            forkpress_cow_refresh_row_identity_tracking();
            $GLOBALS['forkpress_cow_row_identity_refresh_pending'] = false;
        }

        if (forkpress_cow_query_is_table_ddl($query)) {
            $GLOBALS['forkpress_cow_row_identity_schema_changed'] = true;
            $GLOBALS['forkpress_cow_row_identity_refresh_pending'] = true;
        }
    } catch (Throwable $e) {
        error_log('ForkPress row identity tracking refresh failed: ' . $e->getMessage());
    }

    return $query;
}

function forkpress_cow_flush_row_identity_events(): void {
    $metadata_db = getenv('FORKPRESS_COW_MERGE_METADATA_DB');
    $helper = getenv('FORKPRESS_COW_MERGE_HELPER');
    $branch = forkpress_current_branch();
    $db_path = forkpress_db_path();
    if (!$metadata_db || !$helper || !$branch || !$db_path || !is_readable($helper)) {
        return;
    }

    $pdo = forkpress_cow_sqlite_pdo();
    if (!$pdo) {
        return;
    }

    try {
        $stmt = $pdo->query(
            "SELECT id, table_name, op, rowid, row_payload FROM temp.forkpress_row_identity_events ORDER BY id"
        );
        $events = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if (!$events && empty($GLOBALS['forkpress_cow_row_identity_schema_changed'])) {
            return;
        }
        require_once $helper;
        if (function_exists('cow_merge_track_row_identity_events')) {
            cow_merge_track_row_identity_events($db_path, $metadata_db, $branch, $events);
            $pdo->exec('DELETE FROM temp.forkpress_row_identity_events');
            $GLOBALS['forkpress_cow_row_identity_schema_changed'] = false;
            $GLOBALS['forkpress_cow_row_identity_refresh_pending'] = false;
        }
    } catch (Throwable $e) {
        error_log('ForkPress row identity tracking failed: ' . $e->getMessage());
    }
}

function forkpress_cow_install_row_identity_tracking(): void {
    static $installed = false;
    if ($installed || forkpress_env_is_disabled('FORKPRESS_COW_ROW_IDENTITY_TRACKING')) {
        return;
    }

    $metadata_db = getenv('FORKPRESS_COW_MERGE_METADATA_DB');
    $helper = getenv('FORKPRESS_COW_MERGE_HELPER');
    if (!$metadata_db || !$helper || !forkpress_current_branch() || !forkpress_db_path()) {
        return;
    }

    $pdo = forkpress_cow_sqlite_pdo();
    if (!$pdo) {
        return;
    }

    try {
        forkpress_cow_prepare_row_identity_tracking($pdo, true);
        $installed = true;
        if (function_exists('add_filter')) {
            add_filter('query', 'forkpress_cow_row_identity_query_filter', PHP_INT_MAX);
        }
        register_shutdown_function('forkpress_cow_flush_row_identity_events');
    } catch (Throwable $e) {
        error_log('ForkPress row identity tracking setup failed: ' . $e->getMessage());
    }
}

forkpress_cow_install_row_identity_tracking();

function forkpress_branch_url(string $branch, ?string $uri = null): string {
    $root_host = null;
    if (function_exists('forkpress_experiment_root_host')) {
        $root_host = forkpress_experiment_root_host();
    }
    if (!is_string($root_host) || $root_host === '') {
        $root_host = getenv('FORKPRESS_ROOT_HOST');
    }
    $root_host = is_string($root_host) && $root_host !== '' ? $root_host : 'wp.localhost';

    $current_host = $_SERVER['HTTP_HOST'] ?? '';
    $port = preg_match('/:(\d+)$/', $current_host, $m) ? ':' . $m[1] : '';
    $host = $branch === 'main' ? $root_host : $branch . '.' . $root_host;
    $scheme = is_ssl() ? 'https' : 'http';
    $uri = $uri ?? ($_SERVER['REQUEST_URI'] ?? '/wp-admin/');
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

function forkpress_branch_name_is_valid(string $branch): bool {
    if (!preg_match('/^[a-zA-Z0-9_\-]{1,63}$/', $branch)) {
        return false;
    }

    return !in_array(strtolower($branch), ['www', 'admin', 'api', 'mail', 'localhost', 'wp'], true);
}

function forkpress_branch_post_value(string $key): string {
    $value = $_POST[$key] ?? '';
    if (function_exists('wp_unslash')) {
        $value = wp_unslash($value);
    }
    if (is_array($value)) {
        return '';
    }
    $value = trim((string) $value);
    return function_exists('sanitize_text_field') ? sanitize_text_field($value) : (preg_replace('/[^a-zA-Z0-9_\-]/', '', $value) ?? '');
}

function forkpress_branch_post_int(string $key): ?int {
    $value = $_POST[$key] ?? '';
    if (function_exists('wp_unslash')) {
        $value = wp_unslash($value);
    }
    if (is_array($value)) {
        return null;
    }
    $value = trim((string) $value);
    if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
        return null;
    }
    $int = (int) $value;
    return $int > 0 ? $int : null;
}

function forkpress_branch_plugin_driver_entry(string $plugin, string $driver): ?array {
    $plugin = trim($plugin);
    $driver = trim($driver);
    if ($plugin === '' || $driver === '') {
        return null;
    }
    $real = realpath($driver);
    if (!is_string($real) || !is_file($real)) {
        return null;
    }
    if (strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'php' && !is_executable($real)) {
        return null;
    }
    $key = hash('sha256', $plugin . "\0" . $real);
    return [
        'key' => $key,
        'plugin' => $plugin,
        'driver' => $real,
        'label' => basename($real),
    ];
}

function forkpress_branch_plugin_driver_add(array &$drivers, string $plugin, string $driver): void {
    $entry = forkpress_branch_plugin_driver_entry($plugin, $driver);
    if ($entry === null) {
        return;
    }
    $drivers[$entry['key']] = $entry;
}

function forkpress_branch_normalize_relative_path(string $path): ?string {
    if ($path === '' || str_contains($path, "\0")) {
        return null;
    }
    $path = str_replace('\\', '/', $path);
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            return null;
        }
        $parts[] = $part;
    }
    return $parts ? implode('/', $parts) : null;
}

function forkpress_branch_active_plugin_paths(): array {
    $plugins = [];
    if (function_exists('get_option')) {
        $active = get_option('active_plugins', []);
        if (is_array($active)) {
            foreach ($active as $plugin) {
                if (!is_string($plugin)) {
                    continue;
                }
                $plugin = forkpress_branch_normalize_relative_path($plugin);
                if ($plugin !== null) {
                    $plugins[] = $plugin;
                }
            }
        }
    }

    if (function_exists('get_site_option')) {
        $sitewide = get_site_option('active_sitewide_plugins', []);
        if (is_array($sitewide)) {
            foreach ($sitewide as $plugin => $enabled) {
                $candidate = is_string($plugin) && $plugin !== '' ? $plugin : (is_string($enabled) ? $enabled : '');
                $candidate = forkpress_branch_normalize_relative_path($candidate);
                if ($candidate !== null) {
                    $plugins[] = $candidate;
                }
            }
        }
    }

    return array_values(array_unique($plugins));
}

function forkpress_branch_plugin_dir(): string {
    if (defined('WP_PLUGIN_DIR') && is_string(WP_PLUGIN_DIR) && WP_PLUGIN_DIR !== '') {
        return WP_PLUGIN_DIR;
    }
    return rtrim((string) ABSPATH, "/\\") . '/wp-content/plugins';
}

function forkpress_branch_mu_plugin_dir(): string {
    if (defined('WPMU_PLUGIN_DIR') && is_string(WPMU_PLUGIN_DIR) && WPMU_PLUGIN_DIR !== '') {
        return WPMU_PLUGIN_DIR;
    }
    return rtrim((string) ABSPATH, "/\\") . '/wp-content/mu-plugins';
}

function forkpress_branch_discovered_plugin_driver_map(): array {
    $drivers = [];
    $plugins_dir = rtrim(forkpress_branch_plugin_dir(), "/\\");
    foreach (forkpress_branch_active_plugin_paths() as $active_plugin) {
        $plugin_dir = dirname($active_plugin);
        if ($plugin_dir === '.' || $plugin_dir === '') {
            $slug = pathinfo($active_plugin, PATHINFO_FILENAME);
            $driver = $plugins_dir . '/' . $slug . '.forkpress-merge-driver.php';
        } else {
            $slug = basename($plugin_dir);
            $driver = $plugins_dir . '/' . $plugin_dir . '/forkpress-merge-driver.php';
        }
        forkpress_branch_plugin_driver_add($drivers, $slug, $driver);
        forkpress_branch_plugin_driver_add($drivers, $active_plugin, $driver);
    }

    $mu_dir = rtrim(forkpress_branch_mu_plugin_dir(), "/\\");
    if (is_dir($mu_dir)) {
        $direct = $mu_dir . '/forkpress-merge-driver.php';
        forkpress_branch_plugin_driver_add($drivers, 'mu-plugins', $direct);
        foreach ([$mu_dir . '/*.forkpress-merge-driver.php', $mu_dir . '/*/forkpress-merge-driver.php'] as $pattern) {
            $matches = glob($pattern);
            if (!is_array($matches)) {
                continue;
            }
            sort($matches, SORT_STRING);
            foreach ($matches as $match) {
                if (!is_file($match)) {
                    continue;
                }
                $plugin = basename(dirname($match));
                if ($plugin === 'mu-plugins') {
                    $plugin = basename($match, '.forkpress-merge-driver.php');
                }
                forkpress_branch_plugin_driver_add($drivers, $plugin, $match);
            }
        }
    }

    return $drivers;
}

function forkpress_branch_configured_plugin_driver_map(): array {
    $raw = getenv('FORKPRESS_PLUGIN_MERGE_DRIVERS');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $drivers = [];
    if (array_is_list($decoded)) {
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            forkpress_branch_plugin_driver_add($drivers, (string)($entry['plugin'] ?? ''), (string)($entry['driver'] ?? $entry['path'] ?? ''));
        }
    } else {
        foreach ($decoded as $plugin => $driver) {
            if (is_array($driver)) {
                forkpress_branch_plugin_driver_add($drivers, (string)$plugin, (string)($driver['driver'] ?? $driver['path'] ?? ''));
            } else {
                forkpress_branch_plugin_driver_add($drivers, (string)$plugin, (string)$driver);
            }
        }
    }

    return $drivers;
}

function forkpress_branch_plugin_driver_map(): array {
    return forkpress_branch_configured_plugin_driver_map() + forkpress_branch_discovered_plugin_driver_map();
}

function forkpress_branch_conflict_audit_filters(): array {
    $allowed = [
        'scope' => ['all', 'db', 'files', 'plugin'],
        'lifecycleState' => ['unreviewed', 'deferred', 'needs-action', 'reviewed', 'validated', 'resolved'],
        'nextAction' => ['review', 'run-plugin-validator', 'wait', 'revalidate', 'resolve', 'apply-reviewed-choice', 'manual-review', 'none'],
    ];
    $flags = [
        'scope' => '--scope',
        'lifecycleState' => '--lifecycle-state',
        'nextAction' => '--next-action',
    ];
    $labels = [
        'scope' => 'scope',
        'lifecycleState' => 'lifecycle state',
        'nextAction' => 'next action',
    ];
    $filters = [];
    foreach ($allowed as $key => $values) {
        $value = forkpress_branch_post_value($key);
        if ($value === '') {
            continue;
        }
        if (!in_array($value, $values, true)) {
            return [
                'error' => 'Choose a valid merge conflict ' . $labels[$key] . '.',
                'args' => [],
                'filters' => [],
            ];
        }
        $filters[$key] = $value;
    }

    $args = [];
    foreach ($filters as $key => $value) {
        $args[] = $flags[$key];
        $args[] = $value;
    }
    return ['error' => null, 'args' => $args, 'filters' => $filters];
}

function forkpress_branch_can_manage(): bool {
    if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
        return false;
    }

    $bin = getenv('FORKPRESS_BIN');
    $work_dir = getenv('FORKPRESS_WORK_DIR');
    return is_string($bin) && $bin !== '' && is_executable($bin)
        && is_string($work_dir) && $work_dir !== ''
        && function_exists('proc_open');
}

function forkpress_branch_action_url(): string {
    return function_exists('admin_url') ? admin_url('admin-post.php') : '/wp-admin/admin-post.php';
}

function forkpress_branch_switcher_data(string $current): array {
    $branches = array_values(array_unique(forkpress_local_branches($current)));
    return array_map(function (string $branch) use ($current): array {
        return [
            'name'    => $branch,
            'url'     => forkpress_branch_url($branch),
            'current' => $branch === $current,
        ];
    }, $branches);
}

function forkpress_branch_birth_recovery_command(string $branch): string {
    return 'forkpress branch reset ' . $branch . ' --from main';
}

function forkpress_branch_birth_status(?string $branch = null): ?array {
    $branch = $branch ?: forkpress_current_branch();
    if (!is_string($branch) || $branch === '' || $branch === 'main') {
        return null;
    }

    $cow_dir = getenv('FORKPRESS_COW_DIR');
    $db_path = forkpress_db_path();
    if (!is_string($cow_dir) || $cow_dir === '' || !is_string($db_path) || $db_path === '') {
        return null;
    }

    $merge_dir = rtrim($cow_dir, "/\\") . '/merge';
    $metadata_db = getenv('FORKPRESS_COW_MERGE_METADATA_DB');
    if (!is_string($metadata_db) || $metadata_db === '') {
        $metadata_db = $merge_dir . '/metadata.sqlite';
    }

    $missing = [];
    if (!is_file($db_path)) {
        $missing[] = 'branch database';
    }
    if (!is_file($merge_dir . '/bases/' . $branch . '.sqlite')) {
        $missing[] = 'database merge base';
    }
    if (!is_file($merge_dir . '/file-bases/' . $branch . '.json')) {
        $missing[] = 'filesystem merge base';
    }
    if (!is_file($metadata_db)) {
        $missing[] = 'merge metadata';
    }

    $error = null;
    if (!$missing) {
        $helper = getenv('FORKPRESS_COW_MERGE_HELPER');
        if (is_string($helper) && $helper !== '' && is_readable($helper)) {
            try {
                require_once $helper;
                if (function_exists('cow_merge_validate_branch_birth_metadata')) {
                    cow_merge_validate_branch_birth_metadata($db_path, $metadata_db, $branch);
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }

    if (!$missing && $error === null) {
        return ['ready' => true, 'branch' => $branch, 'missing' => [], 'error' => null];
    }

    return [
        'ready' => false,
        'branch' => $branch,
        'missing' => $missing,
        'error' => $error,
        'recovery' => forkpress_branch_birth_recovery_command($branch),
    ];
}

function forkpress_branch_run_cli(array $args): array {
    if (!function_exists('proc_open')) {
        return [1, 'ForkPress branch actions require proc_open().'];
    }

    $bin = getenv('FORKPRESS_BIN');
    $work_dir = getenv('FORKPRESS_WORK_DIR');
    if (!is_string($bin) || $bin === '' || !is_executable($bin) || !is_string($work_dir) || $work_dir === '') {
        return [1, 'ForkPress branch actions are not available for this server.'];
    }

    $command = array_merge([$bin, 'branch', '--work-dir', $work_dir], $args);
    $shell_command = implode(' ', array_map('escapeshellarg', $command));
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($shell_command, $descriptors, $pipes);
    if (!is_resource($process)) {
        return [1, 'Failed to start the ForkPress branch command.'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $output = trim((string) $stdout . "\n" . (string) $stderr);
    return [(int) $code, $output];
}

function forkpress_branch_wants_json(): bool {
    $action = $_REQUEST['action'] ?? '';
    if (is_string($action) && in_array($action, ['forkpress_branch_create', 'forkpress_branch_merge', 'forkpress_branch_conflicts', 'forkpress_branch_revalidate_conflicts', 'forkpress_branch_run_plugin_driver'], true)) {
        return true;
    }

    $async = $_SERVER['HTTP_X_FORKPRESS_ASYNC'] ?? '';
    if (is_string($async) && $async === '1') {
        return true;
    }

    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return is_string($accept) && str_contains($accept, 'application/json');
}

function forkpress_branch_finish_action(string $url, string $type, string $message, array $data = []): void {
    if (forkpress_branch_wants_json()) {
        $payload = array_merge([
            'success' => $type !== 'error',
            'type' => $type,
            'message' => $message,
            'url' => $url,
        ], $data);
        http_response_code($type === 'error' ? 400 : 200);
        header('Content-Type: application/json; charset=UTF-8');
        echo function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
        exit;
    }

    forkpress_branch_redirect_with_notice($url, $type, $message);
}

function forkpress_branch_redirect_with_notice(string $url, string $type, string $message): void {
    if ($type === 'error') {
        $param = 'forkpress_branch_error';
    } elseif ($type === 'warning') {
        $param = 'forkpress_branch_warning';
    } else {
        $param = 'forkpress_branch_notice';
    }
    if (function_exists('add_query_arg')) {
        $url = add_query_arg($param, $message, $url);
    } else {
        $url .= (strpos($url, '?') === false ? '?' : '&') . $param . '=' . rawurlencode($message);
    }

    if (function_exists('wp_redirect')) {
        wp_redirect($url);
    } else {
        header('Location: ' . $url);
    }
    exit;
}

function forkpress_branch_admin_notice(): void {
    $notice = $_GET['forkpress_branch_notice'] ?? '';
    $warning = $_GET['forkpress_branch_warning'] ?? '';
    $error = $_GET['forkpress_branch_error'] ?? '';
    if (function_exists('wp_unslash')) {
        $notice = wp_unslash($notice);
        $warning = wp_unslash($warning);
        $error = wp_unslash($error);
    }

    $message = is_string($error) && $error !== '' ? $error : (is_string($warning) && $warning !== '' ? $warning : $notice);
    if (!is_string($message) || $message === '') {
        return;
    }

    if (is_string($error) && $error !== '') {
        $class = 'notice notice-error';
    } elseif (is_string($warning) && $warning !== '') {
        $class = 'notice notice-warning';
    } else {
        $class = 'notice notice-success';
    }
    echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
}
add_action('admin_notices', 'forkpress_branch_admin_notice');

function forkpress_branch_merge_summary(string $output): array {
    $summary = [
        'run' => null,
        'status' => null,
        'conflicts' => null,
    ];

    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        if (preg_match('/^\s*(run|status|conflicts):\s*(.+?)\s*$/', (string) $line, $matches) !== 1) {
            continue;
        }
        if ($matches[1] === 'conflicts') {
            $summary['conflicts'] = max(0, (int) $matches[2]);
        } elseif ($matches[1] === 'run') {
            $summary['run'] = max(0, (int) $matches[2]);
        } else {
            $summary['status'] = (string) $matches[2];
        }
    }

    return $summary;
}

function forkpress_branch_merge_audit_command(?int $run, array $filters = []): string {
    $command = 'forkpress branch merge-audit --records conflicts';
    if ($run !== null && $run > 0) {
        $command .= ' --run ' . $run;
    }
    $filter_flags = [
        'scope' => '--scope',
        'lifecycleState' => '--lifecycle-state',
        'nextAction' => '--next-action',
    ];
    foreach ($filter_flags as $key => $flag) {
        if (isset($filters[$key]) && is_string($filters[$key]) && $filters[$key] !== '') {
            $command .= ' ' . $flag . ' ' . $filters[$key];
        }
    }
    return $command;
}

function forkpress_branch_crash_recovery_audit_command(?int $run): string {
    $command = 'forkpress branch merge-audit --records crash-recovery';
    if ($run !== null && $run > 0) {
        $command .= ' --run ' . $run;
    }
    return $command;
}

function forkpress_branch_crash_recovery_command(?int $run, array $artifacts = []): string {
    $command = 'forkpress branch recover-crash';
    if ($run !== null && $run > 0) {
        $command .= ' --run ' . $run;
    }
    $restore_db = false;
    $restore_files = false;
    foreach ($artifacts as $artifact) {
        if (!is_array($artifact)) {
            continue;
        }
        if (is_array($artifact['target_db_snapshot'] ?? null)) {
            $restore_db = true;
        }
        if (is_array($artifact['filesystem_transaction'] ?? null) || is_array($artifact['filesystem_snapshot'] ?? null)) {
            $restore_files = true;
        }
    }
    if ($restore_db) {
        $command .= ' --restore-target-db';
    }
    if ($restore_files) {
        $command .= ' --restore-files';
    }
    return $command;
}

function forkpress_branch_crash_recovery_summary(array $report, int $run): array {
    $records = is_array($report['crash_recovery'] ?? null) ? array_values($report['crash_recovery']) : [];
    return [
        'run' => $run,
        'crashRecovery' => $records,
        'crashRecoveryCount' => count($records),
        'audit' => $report,
        'auditCommand' => forkpress_branch_crash_recovery_audit_command($run) . ' --format json',
        'recoveryCommand' => forkpress_branch_crash_recovery_command($run, $records),
    ];
}

function forkpress_branch_conflict_audit_summary(array $report, int $run, array $filters = []): array {
    $records = is_array($report['conflicts'] ?? null) ? array_values($report['conflicts']) : [];
    $total = count($records);
    $runs = is_array($report['runs'] ?? null) ? $report['runs'] : [];
    foreach ($runs as $run_record) {
        if (!is_array($run_record) || (int)($run_record['id'] ?? 0) !== $run) {
            continue;
        }
        $total = max($total, (int)($run_record['conflict_count'] ?? 0));
        break;
    }

    return [
        'run' => $run,
        'records' => $records,
        'recordCount' => count($records),
        'totalConflicts' => $total,
        'filters' => $filters,
        'audit' => $report,
        'auditCommand' => forkpress_branch_merge_audit_command($run, $filters) . ' --format json',
    ];
}

function forkpress_branch_birth_admin_notice(): void {
    $status = forkpress_branch_birth_status();
    if (!is_array($status) || ($status['ready'] ?? true)) {
        return;
    }

    $branch = (string)($status['branch'] ?? '');
    $missing = is_array($status['missing'] ?? null) ? $status['missing'] : [];
    $detail = $missing ? 'Missing: ' . implode(', ', $missing) . '.' : (string)($status['error'] ?? '');
    $message = "ForkPress branch '$branch' is missing required merge metadata. WordPress edits are blocked until it is reset or recreated.";
    $recovery = (string)($status['recovery'] ?? forkpress_branch_birth_recovery_command($branch));

    echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p>';
    if ($detail !== '') {
        echo '<p>' . esc_html($detail) . '</p>';
    }
    echo '<p><code>' . esc_html($recovery) . '</code></p></div>';
}
add_action('admin_notices', 'forkpress_branch_birth_admin_notice');

function forkpress_handle_branch_create(): void {
    if (!forkpress_branch_can_manage()) {
        forkpress_branch_finish_action(forkpress_branch_url(forkpress_current_branch() ?: 'main', '/wp-admin/'), 'error', 'You cannot create ForkPress branches from this site.');
    }
    if (function_exists('check_admin_referer')) {
        check_admin_referer('forkpress_branch_create');
    }

    $branch = forkpress_branch_post_value('branch');
    $from = forkpress_branch_post_value('from') ?: 'main';
    $current = forkpress_current_branch() ?: 'main';
    $branches = forkpress_local_branches($current);
    if (!forkpress_branch_name_is_valid($branch)) {
        forkpress_branch_finish_action(forkpress_branch_url($current, '/wp-admin/'), 'error', 'Branch names can use letters, numbers, hyphens, and underscores.');
    }
    if (!in_array($from, $branches, true)) {
        forkpress_branch_finish_action(forkpress_branch_url($current, '/wp-admin/'), 'error', 'Choose an existing source branch.');
    }

    [$code, $output] = forkpress_branch_run_cli(['create', $branch, '--from', $from]);
    if ($code !== 0) {
        forkpress_branch_finish_action(forkpress_branch_url($current, '/wp-admin/'), 'error', $output ?: 'ForkPress could not create the branch.');
    }
    forkpress_branch_finish_action(
        forkpress_branch_url($branch, '/wp-admin/'),
        'notice',
        'Created branch ' . $branch . '.',
        ['branches' => forkpress_branch_switcher_data($current)]
    );
}
add_action('admin_post_forkpress_branch_create', 'forkpress_handle_branch_create');

function forkpress_handle_branch_merge(): void {
    if (!forkpress_branch_can_manage()) {
        forkpress_branch_finish_action(forkpress_branch_url(forkpress_current_branch() ?: 'main', '/wp-admin/'), 'error', 'You cannot merge ForkPress branches from this site.');
    }
    if (function_exists('check_admin_referer')) {
        check_admin_referer('forkpress_branch_merge');
    }

    $source = forkpress_branch_post_value('source');
    $target = forkpress_branch_post_value('target') ?: 'main';
    $current = forkpress_current_branch() ?: 'main';
    $branches = forkpress_local_branches($current);
    if (!in_array($source, $branches, true) || !in_array($target, $branches, true)) {
        forkpress_branch_finish_action(forkpress_branch_url($current, '/wp-admin/'), 'error', 'Choose existing source and target branches.');
    }
    if ($source === $target) {
        forkpress_branch_finish_action(forkpress_branch_url($current, '/wp-admin/'), 'error', 'Choose two different branches to merge.');
    }

    [$code, $output] = forkpress_branch_run_cli(['merge', $source, '--into', $target]);
    if ($code !== 0) {
        forkpress_branch_finish_action(forkpress_branch_url($current, '/wp-admin/'), 'error', $output ?: 'ForkPress could not merge the branch.');
    }

    $summary = forkpress_branch_merge_summary($output);
    $run = is_int($summary['run']) && $summary['run'] > 0 ? $summary['run'] : null;
    $conflicts = is_int($summary['conflicts']) ? $summary['conflicts'] : 0;
    if (($summary['status'] ?? null) === 'completed_with_conflicts' || $conflicts > 0) {
        $audit_command = forkpress_branch_merge_audit_command($run);
        $message = 'Merged ' . $source . ' into ' . $target . ' with ' . $conflicts . ' conflict' . ($conflicts === 1 ? '' : 's') . '. Review them with `' . $audit_command . '`.';
        forkpress_branch_finish_action(
            forkpress_branch_url($target, '/wp-admin/'),
            'warning',
            $message,
            [
                'branches' => forkpress_branch_switcher_data($current),
                'mergeStatus' => $summary['status'],
                'conflicts' => $conflicts,
                'run' => $run,
                'auditCommand' => $audit_command,
            ]
        );
    }

    forkpress_branch_finish_action(
        forkpress_branch_url($target, '/wp-admin/'),
        'notice',
        'Merged ' . $source . ' into ' . $target . '.',
        ['branches' => forkpress_branch_switcher_data($current)]
    );
}
add_action('admin_post_forkpress_branch_merge', 'forkpress_handle_branch_merge');

function forkpress_handle_branch_conflicts(): void {
    if (!forkpress_branch_can_manage()) {
        forkpress_branch_finish_action(forkpress_branch_url(forkpress_current_branch() ?: 'main', '/wp-admin/'), 'error', 'You cannot inspect ForkPress merge conflicts from this site.');
    }
    if (function_exists('check_admin_referer')) {
        check_admin_referer('forkpress_branch_conflicts');
    }

    $current = forkpress_current_branch() ?: 'main';
    $run = forkpress_branch_post_int('run');
    if ($run === null) {
        forkpress_branch_finish_action(forkpress_branch_url($current, '/wp-admin/'), 'error', 'Choose a merge run to inspect.');
    }

    $filters = forkpress_branch_conflict_audit_filters();
    if (($filters['error'] ?? null) !== null) {
        forkpress_branch_finish_action(forkpress_branch_url($current, '/wp-admin/'), 'error', (string) $filters['error']);
    }

    [$crash_code, $crash_output] = forkpress_branch_run_cli(['merge-audit', '--records', 'crash-recovery', '--run', (string) $run, '--format', 'json']);
    if ($crash_code !== 0) {
        forkpress_branch_finish_action(forkpress_branch_url($current, '/wp-admin/'), 'error', $crash_output ?: 'ForkPress could not inspect pending crash recovery.');
    }
    $crash_report = json_decode($crash_output, true);
    if (!is_array($crash_report)) {
        forkpress_branch_finish_action(forkpress_branch_url($current, '/wp-admin/'), 'error', 'ForkPress returned invalid crash recovery JSON.');
    }
    $crash_summary = forkpress_branch_crash_recovery_summary($crash_report, $run);
    if (($crash_summary['crashRecoveryCount'] ?? 0) > 0) {
        $message = 'Merge run ' . $run . ' has pending crash recovery. Restore it before reviewing conflicts.';
        forkpress_branch_finish_action(
            forkpress_branch_url($current, '/wp-admin/'),
            'warning',
            $message,
            $crash_summary
        );
    }

    $audit_args = array_merge(['merge-audit', '--records', 'conflicts', '--run', (string) $run, '--format', 'json'], $filters['args']);
    [$code, $output] = forkpress_branch_run_cli($audit_args);
    if ($code !== 0) {
        forkpress_branch_finish_action(forkpress_branch_url($current, '/wp-admin/'), 'error', $output ?: 'ForkPress could not inspect merge conflicts.');
    }

    $report = json_decode($output, true);
    if (!is_array($report)) {
        forkpress_branch_finish_action(forkpress_branch_url($current, '/wp-admin/'), 'error', 'ForkPress returned invalid merge audit JSON.');
    }

    $summary = forkpress_branch_conflict_audit_summary($report, $run, $filters['filters']);
    $message = 'Loaded ' . $summary['recordCount'] . ' of ' . $summary['totalConflicts'] . ' conflict record' . ($summary['totalConflicts'] === 1 ? '' : 's') . ' for merge run ' . $run . '.';
    forkpress_branch_finish_action(
        forkpress_branch_url($current, '/wp-admin/'),
        'notice',
        $message,
        $summary
    );
}
add_action('admin_post_forkpress_branch_conflicts', 'forkpress_handle_branch_conflicts');

function forkpress_handle_branch_revalidate_conflicts(): void {
    if (!forkpress_branch_can_manage()) {
        forkpress_branch_finish_action(forkpress_branch_url(forkpress_current_branch() ?: 'main', '/wp-admin/'), 'error', 'You cannot revalidate ForkPress merge conflicts from this site.');
    }
    if (function_exists('check_admin_referer')) {
        check_admin_referer('forkpress_branch_revalidate_conflicts');
    }

    $current = forkpress_current_branch() ?: 'main';
    $run = forkpress_branch_post_int('run');
    if ($run === null) {
        forkpress_branch_finish_action(forkpress_branch_url($current, '/wp-admin/'), 'error', 'Choose a merge run to revalidate.');
    }

    [$code, $output] = forkpress_branch_run_cli(['merge-audit', '--revalidate', '--run', (string) $run, '--reviewer', 'wordpress-ui', '--format', 'json']);
    if ($code !== 0) {
        forkpress_branch_finish_action(forkpress_branch_url($current, '/wp-admin/'), 'error', $output ?: 'ForkPress could not revalidate merge conflicts.');
    }

    $result = json_decode($output, true);
    if (!is_array($result)) {
        forkpress_branch_finish_action(forkpress_branch_url($current, '/wp-admin/'), 'error', 'ForkPress returned invalid revalidation JSON.');
    }

    $checked = max(0, (int)($result['checked'] ?? 0));
    $stale = max(0, (int)($result['stale'] ?? 0));
    $carried = max(0, (int)($result['carried'] ?? 0));
    $message = 'Revalidated merge run ' . $run . ': checked ' . $checked . ', stale ' . $stale . ', carried ' . $carried . '.';
    forkpress_branch_finish_action(
        forkpress_branch_url($current, '/wp-admin/'),
        'warning',
        $message,
        [
            'run' => $run,
            'checked' => $checked,
            'stale' => $stale,
            'carried' => $carried,
            'revalidation' => $result,
            'auditCommand' => 'forkpress branch merge-audit --revalidate --run ' . $run . ' --reviewer wordpress-ui --format json',
        ]
    );
}
add_action('admin_post_forkpress_branch_revalidate_conflicts', 'forkpress_handle_branch_revalidate_conflicts');

function forkpress_handle_branch_run_plugin_driver(): void {
    if (!forkpress_branch_can_manage()) {
        forkpress_branch_finish_action(forkpress_branch_url(forkpress_current_branch() ?: 'main', '/wp-admin/'), 'error', 'You cannot run ForkPress plugin merge drivers from this site.');
    }
    if (function_exists('check_admin_referer')) {
        check_admin_referer('forkpress_branch_run_plugin_driver');
    }

    $current = forkpress_current_branch() ?: 'main';
    $conflict = forkpress_branch_post_int('conflict');
    if ($conflict === null) {
        forkpress_branch_finish_action(forkpress_branch_url($current, '/wp-admin/'), 'error', 'Choose a plugin conflict to repair.');
    }

    $drivers = forkpress_branch_plugin_driver_map();
    if (!$drivers) {
        forkpress_branch_finish_action(forkpress_branch_url($current, '/wp-admin/'), 'error', 'No approved plugin merge drivers are configured.');
    }

    $driver_key = forkpress_branch_post_value('driverKey');
    if ($driver_key === '' || !isset($drivers[$driver_key])) {
        forkpress_branch_finish_action(forkpress_branch_url($current, '/wp-admin/'), 'error', 'Choose an approved plugin merge driver.');
    }

    $driver = $drivers[$driver_key];
    [$code, $output] = forkpress_branch_run_cli([
        'run-plugin-driver',
        'conflict',
        (string) $conflict,
        '--driver',
        (string) $driver['driver'],
        '--reviewer',
        'wordpress-ui',
        '--format',
        'json',
    ]);
    if ($code !== 0) {
        forkpress_branch_finish_action(forkpress_branch_url($current, '/wp-admin/'), 'error', $output ?: 'ForkPress could not run the plugin merge driver.');
    }

    $result = json_decode($output, true);
    if (!is_array($result)) {
        forkpress_branch_finish_action(forkpress_branch_url($current, '/wp-admin/'), 'error', 'ForkPress returned invalid plugin driver JSON.');
    }

    $driver_status = trim((string)($result['driver_status'] ?? $result['status'] ?? 'completed'));
    if ($driver_status === '') {
        $driver_status = 'completed';
    }
    $run = forkpress_branch_post_int('run');
    forkpress_branch_finish_action(
        forkpress_branch_url($current, '/wp-admin/'),
        'notice',
        'Ran plugin driver for conflict #' . $conflict . ': ' . $driver_status . '.',
        [
            'run' => $run,
            'conflict' => $conflict,
            'driverStatus' => $driver_status,
            'driverPlugin' => (string) $driver['plugin'],
            'result' => $result,
        ]
    );
}
add_action('admin_post_forkpress_branch_run_plugin_driver', 'forkpress_handle_branch_run_plugin_driver');

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
            border: 1px solid #50575e;
            border-radius: 6px;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.34);
            box-sizing: border-box;
            color: #f0f0f1;
            display: none;
            left: 0;
            padding: 12px;
            position: absolute;
            top: 32px;
            width: 360px;
            z-index: 99999;
        }
        #wpadminbar #wp-admin-bar-forkpress-branch-indicator:hover .forkpress-switcher-panel,
        #wpadminbar #wp-admin-bar-forkpress-branch-indicator.forkpress-switcher-open .forkpress-switcher-panel {
            display: block;
        }
        #wpadminbar .forkpress-switcher-filter {
            background: #fff;
            border: 1px solid #8c8f94;
            border-radius: 4px;
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
        #wpadminbar .forkpress-switcher-tools {
            border-top: 1px solid #3c434a;
            display: grid;
            gap: 10px;
            margin-top: 10px;
            padding-top: 10px;
        }
        #wpadminbar .forkpress-switcher-form {
            background: #2c3338;
            border: 1px solid #3c434a;
            border-radius: 6px;
            display: grid;
            gap: 8px;
            padding: 10px;
        }
        #wpadminbar .forkpress-switcher-form-title {
            color: #f0f0f1;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0;
            line-height: 1.3;
        }
        #wpadminbar .forkpress-switcher-field {
            display: grid;
            gap: 3px;
            min-width: 0;
        }
        #wpadminbar .forkpress-switcher-label {
            color: #c3c4c7;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0;
            line-height: 1.2;
        }
        #wpadminbar .forkpress-switcher-row {
            display: grid;
            gap: 6px;
            grid-template-columns: 1fr 1fr;
        }
        #wpadminbar .forkpress-switcher-row.is-merge {
            align-items: end;
            grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
        }
        #wpadminbar .forkpress-switcher-arrow {
            color: #c3c4c7;
            font-size: 16px;
            font-weight: 700;
            line-height: 30px;
            min-width: 18px;
            text-align: center;
        }
        #wpadminbar .forkpress-switcher-input,
        #wpadminbar .forkpress-switcher-select {
            background: #fff;
            border: 1px solid #8c8f94;
            border-radius: 4px;
            box-sizing: border-box;
            color: #1d2327;
            font-size: 13px;
            height: 30px;
            line-height: 1.4;
            margin: 0;
            min-width: 0;
            padding: 4px 8px;
            width: 100%;
        }
        #wpadminbar .forkpress-switcher-button {
            background: #2271b1;
            border: 1px solid #2271b1;
            border-radius: 4px;
            box-sizing: border-box;
            color: #fff;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 600;
            height: 30px;
            line-height: 1;
            margin: 0;
            padding: 0 10px;
            text-align: center;
            width: 100%;
        }
        #wpadminbar .forkpress-switcher-button:hover,
        #wpadminbar .forkpress-switcher-button:focus {
            background: #135e96;
            border-color: #135e96;
            color: #fff;
            outline: none;
        }
        #wpadminbar .forkpress-switcher-form.is-loading .forkpress-switcher-button,
        #wpadminbar .forkpress-switcher-button:disabled {
            cursor: progress;
            opacity: 0.82;
        }
        #wpadminbar .forkpress-switcher-spinner {
            animation: forkpress-switcher-spin 0.8s linear infinite;
            border: 2px solid rgba(255, 255, 255, 0.45);
            border-top-color: #fff;
            border-radius: 50%;
            box-sizing: border-box;
            display: none;
            height: 14px;
            width: 14px;
        }
        #wpadminbar .forkpress-switcher-form.is-loading .forkpress-switcher-spinner {
            display: inline-block;
        }
        #wpadminbar .forkpress-switcher-status {
            border-radius: 4px;
            display: none;
            font-size: 12px;
            line-height: 1.35;
            padding: 8px;
        }
        #wpadminbar .forkpress-switcher-status.is-visible {
            display: block;
        }
        #wpadminbar .forkpress-switcher-status.is-success {
            background: #0a4b78;
            color: #fff;
        }
        #wpadminbar .forkpress-switcher-status.is-warning {
            background: #7a4d00;
            color: #fff;
        }
        #wpadminbar .forkpress-switcher-status.is-error {
            background: #8a2424;
            color: #fff;
        }
        #wpadminbar .forkpress-conflict-list {
            background: #2c3338;
            border: 1px solid #3c434a;
            border-radius: 6px;
            display: none;
            gap: 7px;
            max-height: 220px;
            overflow: auto;
            padding: 8px;
        }
        #wpadminbar .forkpress-conflict-list.is-visible {
            display: grid;
        }
        #wpadminbar .forkpress-conflict-heading {
            color: #f0f0f1;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.25;
        }
        #wpadminbar .forkpress-conflict-row {
            border-top: 1px solid #3c434a;
            color: #f0f0f1;
            display: grid;
            gap: 2px;
            font-size: 12px;
            line-height: 1.3;
            min-width: 0;
            padding-top: 7px;
        }
        #wpadminbar .forkpress-conflict-title,
        #wpadminbar .forkpress-conflict-meta,
        #wpadminbar .forkpress-conflict-command {
            overflow-wrap: anywhere;
        }
        #wpadminbar .forkpress-conflict-meta,
        #wpadminbar .forkpress-conflict-command {
            color: #c3c4c7;
            font-size: 11px;
        }
        #wpadminbar .forkpress-conflict-actions {
            display: flex;
            gap: 6px;
            margin-top: 4px;
        }
        #wpadminbar .forkpress-conflict-actions .forkpress-switcher-button {
            height: 26px;
            width: auto;
        }
        @keyframes forkpress-switcher-spin {
            to {
                transform: rotate(360deg);
            }
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
    $data = forkpress_branch_switcher_data($current);
    $json = function_exists('wp_json_encode') ? wp_json_encode($data) : json_encode($data);
    $actions = null;
    if (forkpress_branch_can_manage()) {
        $actions = [
            'url'         => forkpress_branch_action_url(),
            'current'     => $current,
            'createNonce' => function_exists('wp_create_nonce') ? wp_create_nonce('forkpress_branch_create') : '',
            'mergeNonce'  => function_exists('wp_create_nonce') ? wp_create_nonce('forkpress_branch_merge') : '',
            'auditNonce'  => function_exists('wp_create_nonce') ? wp_create_nonce('forkpress_branch_conflicts') : '',
            'revalidateNonce' => function_exists('wp_create_nonce') ? wp_create_nonce('forkpress_branch_revalidate_conflicts') : '',
            'driverNonce' => function_exists('wp_create_nonce') ? wp_create_nonce('forkpress_branch_run_plugin_driver') : '',
            'pluginDrivers' => array_map(
                static fn(array $driver): array => [
                    'key' => (string) $driver['key'],
                    'plugin' => (string) $driver['plugin'],
                    'label' => (string) $driver['label'],
                ],
                array_values(forkpress_branch_plugin_driver_map())
            ),
        ];
    }
    $actions_json = function_exists('wp_json_encode') ? wp_json_encode($actions) : json_encode($actions);
    if (!is_string($json) || $json === '' || !is_string($actions_json) || $actions_json === '') {
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
        var actions = <?php echo $actions_json; ?>;
        var panel = document.createElement('div');
        panel.className = 'forkpress-switcher-panel';
        panel.innerHTML = '<input class="forkpress-switcher-filter" type="search" autocomplete="off" placeholder="Filter branches" aria-label="Filter branches"><div class="forkpress-switcher-list" role="menu"></div><div class="forkpress-switcher-status" role="status" aria-live="polite"></div><div class="forkpress-conflict-list" aria-live="polite"></div>';
        item.appendChild(panel);

        var input = panel.querySelector('.forkpress-switcher-filter');
        var list = panel.querySelector('.forkpress-switcher-list');
        var status = panel.querySelector('.forkpress-switcher-status');
        var conflictList = panel.querySelector('.forkpress-conflict-list');
        var actionSelects = [];

        function setBranches(nextBranches) {
            if (!Array.isArray(nextBranches)) {
                return;
            }
            branches = nextBranches.filter(function (branch) {
                return branch && typeof branch.name === 'string' && typeof branch.url === 'string';
            });
            render();
            refreshActionSelects();
        }

        function showStatus(kind, message) {
            status.className = 'forkpress-switcher-status is-visible is-' + kind;
            status.textContent = message;
        }

        function clearConflictAudit() {
            conflictList.className = 'forkpress-conflict-list';
            conflictList.innerHTML = '';
        }

        function appendConflictText(parent, className, text) {
            if (!text) {
                return;
            }
            var node = document.createElement('div');
            node.className = className;
            node.textContent = text;
            parent.appendChild(node);
        }

        function conflictTitle(record) {
            if (record && record.conflict_key) {
                return String(record.conflict_key);
            }
            if (record && record.path) {
                return String(record.path);
            }
            if (record && record.table_name) {
                return String(record.table_name) + (record.column_name ? '.' + String(record.column_name) : '');
            }
            return 'Conflict #' + String(record && record.id ? record.id : '');
        }

        function conflictPluginMeta(record) {
            if (!record || !record.plugin) {
                return '';
            }
            return [
                'plugin: ' + String(record.plugin),
                record.plugin_object ? 'object: ' + String(record.plugin_object) : '',
                record.plugin_severity ? 'severity: ' + String(record.plugin_severity) : '',
                record.plugin_validator ? 'validator: ' + String(record.plugin_validator) : ''
            ].filter(Boolean).join(' / ');
        }

        function conflictPluginGuidance(record) {
            if (!record || !record.plugin) {
                return '';
            }
            return [
                record.plugin_resolution_policy ? 'policy: ' + String(record.plugin_resolution_policy) : '',
                record.plugin_suggested_action ? 'action: ' + String(record.plugin_suggested_action) : '',
                record.plugin_manual_review_reason ? 'manual review: ' + String(record.plugin_manual_review_reason) : ''
            ].filter(Boolean).join(' / ');
        }

        function driverForConflict(record) {
            if (!actions || !Array.isArray(actions.pluginDrivers) || !record || !record.plugin) {
                return null;
            }
            for (var i = 0; i < actions.pluginDrivers.length; i++) {
                var driver = actions.pluginDrivers[i];
                if (driver && driver.plugin === record.plugin && driver.key) {
                    return driver;
                }
            }
            return null;
        }

        function renderConflictAudit(payload, fallbackMessage) {
            var records = Array.isArray(payload.records) ? payload.records : [];
            var recovery = Array.isArray(payload.crashRecovery) ? payload.crashRecovery : [];
            var filters = payload.filters || {};
            showStatus('warning', payload.message || fallbackMessage || 'Merge completed with conflicts.');
            clearConflictAudit();
            conflictList.className = 'forkpress-conflict-list is-visible';

            var heading = document.createElement('div');
            heading.className = 'forkpress-conflict-heading';
            if (recovery.length) {
                heading.textContent = 'Run ' + String(payload.run || '') + ': pending crash recovery';
                conflictList.appendChild(heading);
                recovery.slice(0, 5).forEach(function (artifact) {
                    var row = document.createElement('div');
                    row.className = 'forkpress-conflict-row';
                    appendConflictText(row, 'forkpress-conflict-title', 'checkpoint: ' + String(artifact.checkpoint || 'unknown'));
                    appendConflictText(row, 'forkpress-conflict-meta', [
                        artifact.target_db ? 'target DB: ' + String(artifact.target_db) : '',
                        artifact.target_root ? 'target root: ' + String(artifact.target_root) : ''
                    ].filter(Boolean).join(' / '));
                    appendConflictText(row, 'forkpress-conflict-command', artifact.artifact_path || '');
                    conflictList.appendChild(row);
                });
                appendConflictText(conflictList, 'forkpress-conflict-command', payload.recoveryCommand || '');
                appendConflictText(conflictList, 'forkpress-conflict-command', payload.auditCommand || '');
                return;
            }
            heading.textContent = 'Run ' + String(payload.run || '') + ': ' + String(payload.recordCount || records.length) + ' of ' + String(payload.totalConflicts || records.length) + ' conflicts';
            conflictList.appendChild(heading);
            appendConflictText(conflictList, 'forkpress-conflict-meta', [
                filters.scope && filters.scope !== 'all' ? 'scope: ' + filters.scope : '',
                filters.lifecycleState ? 'state: ' + filters.lifecycleState : '',
                filters.nextAction ? 'next: ' + filters.nextAction : ''
            ].filter(Boolean).join(' / '));

            records.slice(0, 5).forEach(function (record) {
                var row = document.createElement('div');
                row.className = 'forkpress-conflict-row';
                appendConflictText(row, 'forkpress-conflict-title', conflictTitle(record));
                appendConflictText(row, 'forkpress-conflict-meta', [
                    record.conflict_type || record.type || '',
                    record.lifecycle_state || record.latest_event_lifecycle_state || '',
                    record.next_action || ''
                ].filter(Boolean).join(' / '));
                appendConflictText(row, 'forkpress-conflict-meta', conflictPluginMeta(record));
                appendConflictText(row, 'forkpress-conflict-meta', conflictPluginGuidance(record));
                var driver = driverForConflict(record);
                if (driver && record.id && record.lifecycle_state !== 'resolved') {
                    var actionsRow = document.createElement('div');
                    actionsRow.className = 'forkpress-conflict-actions';
                    var driverButton = document.createElement('button');
                    driverButton.className = 'forkpress-switcher-button';
                    driverButton.type = 'button';
                    driverButton.textContent = 'Run plugin driver';
                    driverButton.addEventListener('click', function (record, driver) {
                        return function () {
                            fetchPluginDriver(record, driver, payload.run);
                        };
                    }(record, driver));
                    actionsRow.appendChild(driverButton);
                    row.appendChild(actionsRow);
                }
                conflictList.appendChild(row);
            });

            if (records.length > 5) {
                appendConflictText(conflictList, 'forkpress-conflict-meta', String(records.length - 5) + ' more conflicts in merge audit.');
            }
            appendConflictText(conflictList, 'forkpress-conflict-command', payload.auditCommand || '');
            if (payload.run && actions && actions.revalidateNonce) {
                var button = document.createElement('button');
                button.className = 'forkpress-switcher-button';
                button.type = 'button';
                button.textContent = 'Revalidate conflicts';
                button.addEventListener('click', function () {
                    fetchConflictRevalidation(payload.run);
                });
                conflictList.appendChild(button);
            }
        }

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

        function hidden(name, value) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            return input;
        }

        function select(name, selected) {
            var field = document.createElement('select');
            field.className = 'forkpress-switcher-select';
            field.name = name;
            populateSelect(field, selected);
            actionSelects.push(field);
            return field;
        }

        function populateSelect(field, selected) {
            var value = selected || field.value;
            field.innerHTML = '';
            branches.forEach(function (branch) {
                var option = document.createElement('option');
                option.value = branch.name;
                option.textContent = branch.name;
                option.selected = branch.name === value;
                field.appendChild(option);
            });
        }

        function refreshActionSelects() {
            actionSelects.forEach(function (field) {
                populateSelect(field);
            });
        }

        function labeledField(labelText, field) {
            var wrap = document.createElement('label');
            wrap.className = 'forkpress-switcher-field';
            var label = document.createElement('span');
            label.className = 'forkpress-switcher-label';
            label.textContent = labelText;
            wrap.appendChild(label);
            wrap.appendChild(field);
            return wrap;
        }

        function setFormLoading(form, loading) {
            form.classList.toggle('is-loading', loading);
            Array.prototype.forEach.call(form.elements, function (field) {
                field.disabled = loading;
            });
        }

        function bindAsyncAction(form, clearField) {
            if (!window.fetch || !window.FormData) {
                return;
            }

            form.addEventListener('submit', function (event) {
                event.preventDefault();
                var body = new FormData(form);
                setFormLoading(form, true);
                showStatus('success', 'Working...');
                clearConflictAudit();

                fetch(form.action, {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-ForkPress-Async': '1'
                    }
                }).then(function (response) {
                    return response.text().then(function (text) {
                        var payload = null;
                        try {
                            payload = text ? JSON.parse(text) : null;
                        } catch (error) {
                            payload = null;
                        }
                        if (!response.ok || !payload || payload.success === false) {
                            throw new Error(payload && payload.message ? payload.message : (text || 'ForkPress branch action failed.'));
                        }
                        return payload;
                    });
                }).then(function (payload) {
                    if (clearField) {
                        clearField.value = '';
                    }
                    if (payload.branches) {
                        setBranches(payload.branches);
                    }
                    if (payload.type === 'warning' && payload.run) {
                        showStatus('warning', payload.message || 'ForkPress branch action completed.');
                        fetchConflictAudit(payload.run, payload.message || '');
                    } else {
                        showStatus('success', payload.message || 'ForkPress branch action completed.');
                    }
                }).catch(function (error) {
                    showStatus('error', error && error.message ? error.message : 'ForkPress branch action failed.');
                }).then(function () {
                    setFormLoading(form, false);
                });
            });
        }

        function fetchConflictAudit(run, fallbackMessage, filters) {
            if (!actions || !actions.auditNonce || !window.fetch || !window.FormData) {
                return;
            }
            var body = new FormData();
            body.append('action', 'forkpress_branch_conflicts');
            body.append('_wpnonce', actions.auditNonce);
            body.append('run', String(run));
            filters = filters || {};
            if (filters.scope) {
                body.append('scope', filters.scope);
            }
            if (filters.lifecycleState) {
                body.append('lifecycleState', filters.lifecycleState);
            }
            if (filters.nextAction) {
                body.append('nextAction', filters.nextAction);
            }
            fetch(actions.url, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-ForkPress-Async': '1'
                }
            }).then(function (response) {
                return response.text().then(function (text) {
                    var payload = null;
                    try {
                        payload = text ? JSON.parse(text) : null;
                    } catch (error) {
                        payload = null;
                    }
                    if (!response.ok || !payload || payload.success === false) {
                        throw new Error(payload && payload.message ? payload.message : (text || 'ForkPress conflict audit failed.'));
                    }
                    return payload;
                });
            }).then(function (payload) {
                renderConflictAudit(payload, fallbackMessage);
            }).catch(function (error) {
                var message = fallbackMessage || 'Merge completed with conflicts.';
                if (error && error.message) {
                    message += ' Conflict details could not be loaded: ' + error.message;
                }
                showStatus('warning', message);
            });
        }

        function fetchConflictRevalidation(run) {
            if (!actions || !actions.revalidateNonce || !window.fetch || !window.FormData) {
                return;
            }
            var body = new FormData();
            body.append('action', 'forkpress_branch_revalidate_conflicts');
            body.append('_wpnonce', actions.revalidateNonce);
            body.append('run', String(run));
            showStatus('warning', 'Revalidating conflicts...');
            fetch(actions.url, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-ForkPress-Async': '1'
                }
            }).then(function (response) {
                return response.text().then(function (text) {
                    var payload = null;
                    try {
                        payload = text ? JSON.parse(text) : null;
                    } catch (error) {
                        payload = null;
                    }
                    if (!response.ok || !payload || payload.success === false) {
                        throw new Error(payload && payload.message ? payload.message : (text || 'ForkPress conflict revalidation failed.'));
                    }
                    return payload;
                });
            }).then(function (payload) {
                showStatus('warning', payload.message || 'Revalidated conflicts.');
                fetchConflictAudit(run, payload.message || '', { lifecycleState: 'needs-action' });
            }).catch(function (error) {
                showStatus('error', error && error.message ? error.message : 'ForkPress conflict revalidation failed.');
            });
        }

        function fetchPluginDriver(record, driver, run) {
            if (!actions || !actions.driverNonce || !window.fetch || !window.FormData || !record || !record.id || !driver || !driver.key) {
                return;
            }
            var body = new FormData();
            body.append('action', 'forkpress_branch_run_plugin_driver');
            body.append('_wpnonce', actions.driverNonce);
            body.append('conflict', String(record.id));
            body.append('driverKey', String(driver.key));
            if (run) {
                body.append('run', String(run));
            }
            showStatus('warning', 'Running plugin driver...');
            fetch(actions.url, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-ForkPress-Async': '1'
                }
            }).then(function (response) {
                return response.text().then(function (text) {
                    var payload = null;
                    try {
                        payload = text ? JSON.parse(text) : null;
                    } catch (error) {
                        payload = null;
                    }
                    if (!response.ok || !payload || payload.success === false) {
                        throw new Error(payload && payload.message ? payload.message : (text || 'ForkPress plugin driver failed.'));
                    }
                    return payload;
                });
            }).then(function (payload) {
                showStatus('success', payload.message || 'Ran plugin driver.');
                if (run) {
                    fetchConflictAudit(run, payload.message || '', { lifecycleState: 'needs-action' });
                }
            }).catch(function (error) {
                showStatus('error', error && error.message ? error.message : 'ForkPress plugin driver failed.');
            });
        }

        function addActions() {
            if (!actions || !branches.length) {
                return;
            }

            var tools = document.createElement('div');
            tools.className = 'forkpress-switcher-tools';

            var createForm = document.createElement('form');
            createForm.className = 'forkpress-switcher-form';
            createForm.method = 'post';
            createForm.action = actions.url;
            createForm.appendChild(hidden('action', 'forkpress_branch_create'));
            createForm.appendChild(hidden('_wpnonce', actions.createNonce));
            var createTitle = document.createElement('div');
            createTitle.className = 'forkpress-switcher-form-title';
            createTitle.textContent = 'Create branch';
            createForm.appendChild(createTitle);
            var createRow = document.createElement('div');
            createRow.className = 'forkpress-switcher-row';
            var branchName = document.createElement('input');
            branchName.className = 'forkpress-switcher-input';
            branchName.name = 'branch';
            branchName.type = 'text';
            branchName.autocomplete = 'off';
            branchName.placeholder = 'new-branch';
            branchName.pattern = '[a-zA-Z0-9_-]{1,63}';
            branchName.required = true;
            createRow.appendChild(labeledField('Name', branchName));
            createRow.appendChild(labeledField('From', select('from', actions.current)));
            createForm.appendChild(createRow);
            var createButton = document.createElement('button');
            createButton.className = 'forkpress-switcher-button';
            createButton.type = 'submit';
            createButton.innerHTML = '<span class="forkpress-switcher-spinner" aria-hidden="true"></span><span>Create branch</span>';
            createForm.appendChild(createButton);

            var mergeForm = document.createElement('form');
            mergeForm.className = 'forkpress-switcher-form';
            mergeForm.method = 'post';
            mergeForm.action = actions.url;
            mergeForm.appendChild(hidden('action', 'forkpress_branch_merge'));
            mergeForm.appendChild(hidden('_wpnonce', actions.mergeNonce));
            var mergeTitle = document.createElement('div');
            mergeTitle.className = 'forkpress-switcher-form-title';
            mergeTitle.textContent = 'Merge branches';
            mergeForm.appendChild(mergeTitle);
            var mergeRow = document.createElement('div');
            mergeRow.className = 'forkpress-switcher-row is-merge';
            var firstNonMain = branches.find(function (branch) {
                return branch.name !== 'main';
            });
            var defaultSource = actions.current === 'main' && firstNonMain ? firstNonMain.name : actions.current;
            mergeRow.appendChild(labeledField('Source', select('source', defaultSource)));
            var arrow = document.createElement('span');
            arrow.className = 'forkpress-switcher-arrow';
            arrow.setAttribute('aria-hidden', 'true');
            arrow.textContent = '→';
            mergeRow.appendChild(arrow);
            mergeRow.appendChild(labeledField('Target', select('target', 'main')));
            mergeForm.appendChild(mergeRow);
            var mergeButton = document.createElement('button');
            mergeButton.className = 'forkpress-switcher-button';
            mergeButton.type = 'submit';
            mergeButton.innerHTML = '<span class="forkpress-switcher-spinner" aria-hidden="true"></span><span>Merge source → target</span>';
            mergeForm.appendChild(mergeButton);

            tools.appendChild(createForm);
            tools.appendChild(mergeForm);
            panel.appendChild(tools);
            bindAsyncAction(createForm, branchName);
            bindAsyncAction(mergeForm, null);
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
        addActions();
    }());
    </script>
    <?php
}
add_action('admin_footer', 'forkpress_render_branch_switcher');
add_action('wp_footer', 'forkpress_render_branch_switcher');

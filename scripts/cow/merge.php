<?php
/**
 * Generic COW SQLite merge helper.
 *
 * This is intentionally schema-agnostic: it treats WordPress core tables and
 * plugin tables as ordinary SQLite tables, uses primary keys when available,
 * and records every automatic conflict decision in ForkPress-owned metadata.
 */

function cow_merge_usage(): void {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php merge.php [merge] --base-db <path> --source-db <path> --target-db <path> --metadata-db <path> --source <branch> --target <branch>\n");
    fwrite(STDERR, "    [--base-files <manifest> --source-root <path> --target-root <path>] [--plugin-validator <path>]\n");
    fwrite(STDERR, "  php merge.php capture-files --root <path> --file-base <path>\n");
    fwrite(STDERR, "  php merge.php capture-identities --db <path> --metadata-db <path> --branch <branch> [--seed-branch <branch>]\n");
    fwrite(STDERR, "  php merge.php track-identity-events --db <path> --metadata-db <path> --branch <branch> --events-json <json>\n");
    fwrite(STDERR, "  php merge.php allocate-id-bands --db <path> --metadata-db <path> --branch <branch>\n");
    fwrite(STDERR, "  php merge.php validate-branch-birth-metadata --db <path> --metadata-db <path> --branch <branch>\n");
    fwrite(STDERR, "  php merge.php record-plugin-validator-conflicts --metadata-db <path> --run ID (--findings-json <json>|--findings-file <path>) [--format text|json]\n");
    fwrite(STDERR, "  php merge.php run-plugin-validator --metadata-db <path> --run ID --validator <path> [--format text|json]\n");
    fwrite(STDERR, "  php merge.php recover-crash --metadata-db <path> [--run ID] [--restore-target-db] [--restore-files] [--format text|json]\n");
    fwrite(STDERR, "  php merge.php audit --metadata-db <path> [--format text|json] [--limit N] [--run ID]\n");
    fwrite(STDERR, "    [--scope all|db|files|plugin] [--records all|conflicts|decisions|resolutions|rollback-failures] [--path <path>] [--path-prefix <prefix>]\n");
    fwrite(STDERR, "    [--scope all|db|files|plugin] [--records all|conflicts|decisions|resolutions|rollback-failures] [--conflict-type TYPE] [--decision DECISION]\n");
    fwrite(STDERR, "    [--id-band-skips] [--target-kept] [--review] [--review-status unreviewed|pending|needs-action|reviewed]\n");
    fwrite(STDERR, "    [--resolution-status validated|applied] [--group-by none|table|status|path|type|severity]\n");
    fwrite(STDERR, "    --group-by supports resolutions by table/status/path, conflicts by table/type/path/severity, and decisions by table/type/path.\n");
    fwrite(STDERR, "  php merge.php revalidate-reviews --metadata-db <path> [--run ID] [--reviewer NAME] [--format text|json]\n");
    fwrite(STDERR, "  php merge.php review-record --metadata-db <path> --record conflict|decision|resolution --id ID --status pending|needs-action|reviewed --note TEXT [--reviewer NAME]\n");
    fwrite(STDERR, "  php merge.php resolve-conflict --metadata-db <path> --id ID --choice source|target [--apply] [--after-revalidate] [--note TEXT] [--reviewer NAME]\n");
}

const COW_MERGE_AUTOINCREMENT_BAND_SIZE = 1000000;
const COW_MERGE_AUTOINCREMENT_FIRST_BAND_START = 1000000;

class CowMergeRollbackFailureException extends RuntimeException {
    public string $originalFailure;
    public string $rollbackFailure;
    public array $rollbackArtifacts;

    public function __construct(
        string $message,
        string $originalFailure,
        string $rollbackFailure,
        array $rollbackArtifacts,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->originalFailure = $originalFailure;
        $this->rollbackFailure = $rollbackFailure;
        $this->rollbackArtifacts = $rollbackArtifacts;
    }
}

function cow_merge_mkdir_p(string $path): void {
    if ($path === '' || is_dir($path)) {
        return;
    }
    if (!@mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException("failed to create $path");
    }
}

function cow_merge_open_db(string $path, int $flags): SQLite3 {
    cow_merge_test_hook('before_sqlite_open', $path, $flags);
    try {
        $db = new SQLite3($path, $flags);
    } catch (Throwable $e) {
        throw new RuntimeException("failed to open SQLite database $path: " . $e->getMessage(), 0, $e);
    }
    try {
        cow_merge_test_hook('after_sqlite_open', $db, $path, $flags);
        if (!$db->busyTimeout(5000)) {
            throw new RuntimeException($db->lastErrorMsg());
        }
    } catch (Throwable $e) {
        $db->close();
        throw new RuntimeException("failed to initialize SQLite database $path: " . $e->getMessage(), 0, $e);
    }
    return $db;
}

function cow_merge_temp_sqlite_path(string $dir, string $prefix): string {
    cow_merge_mkdir_p($dir);
    $path = tempnam($dir, $prefix);
    if (!is_string($path)) {
        throw new RuntimeException("failed to create temporary SQLite path in $dir");
    }
    if (!@unlink($path) && file_exists($path)) {
        throw new RuntimeException("failed to prepare temporary SQLite path: $path");
    }
    return $path;
}

function cow_merge_backup_sqlite_db(string $source, string $backup): void {
    if (!is_file($source)) {
        throw new RuntimeException("SQLite database does not exist: $source");
    }
    if (file_exists($backup)) {
        throw new RuntimeException("refusing to overwrite SQLite backup: $backup");
    }

    if (method_exists('SQLite3', 'backup')) {
        $source_db = cow_merge_open_db($source, SQLITE3_OPEN_READONLY);
        $backup_db = cow_merge_open_db($backup, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        try {
            if (!$source_db->backup($backup_db, 'main', 'main')) {
                throw new RuntimeException('SQLite online backup failed: ' . $source_db->lastErrorMsg());
            }
        } finally {
            $source_db->close();
            $backup_db->close();
        }
    } else {
        $db = cow_merge_open_db($source, SQLITE3_OPEN_READONLY);
        try {
            $escaped = SQLite3::escapeString($backup);
            if (!$db->exec("VACUUM INTO '$escaped'")) {
                throw new RuntimeException('SQLite VACUUM INTO backup failed: ' . $db->lastErrorMsg());
            }
        } finally {
            $db->close();
        }
    }

    if (!is_file($backup)) {
        throw new RuntimeException("SQLite backup was not created: $backup");
    }
}

function cow_merge_sqlite_sidecar_paths(string $path): array {
    return [$path . '-wal', $path . '-shm', $path . '-journal'];
}

function cow_merge_remove_sqlite_files(string $path): void {
    foreach (array_merge([$path], cow_merge_sqlite_sidecar_paths($path)) as $file) {
        if ((file_exists($file) || is_link($file)) && !@unlink($file)) {
            throw new RuntimeException("failed to remove SQLite file: $file");
        }
    }
}

function cow_merge_snapshot_sqlite_db(string $path): array {
    $snapshot = [
        'path' => $path,
        'existed' => is_file($path),
        'backup' => null,
    ];
    if (!$snapshot['existed']) {
        cow_merge_mkdir_p(dirname($path));
        return $snapshot;
    }

    $backup = cow_merge_temp_sqlite_path(dirname($path), '.forkpress-merge-db-backup-');
    cow_merge_backup_sqlite_db($path, $backup);
    $snapshot['backup'] = $backup;
    return $snapshot;
}

function cow_merge_restore_sqlite_snapshot(array $snapshot): void {
    cow_merge_test_hook('before_sqlite_snapshot_restore', $snapshot);
    $path = (string)$snapshot['path'];
    cow_merge_remove_sqlite_files($path);
    if (empty($snapshot['existed'])) {
        return;
    }

    $backup = $snapshot['backup'] ?? null;
    if (!is_string($backup) || !is_file($backup)) {
        throw new RuntimeException("missing SQLite rollback backup for: $path");
    }

    $restore = cow_merge_temp_sqlite_path(dirname($path), '.forkpress-merge-db-restore-');
    if (!@copy($backup, $restore)) {
        throw new RuntimeException("failed to copy SQLite rollback backup for: $path");
    }
    if (!@rename($restore, $path)) {
        @unlink($restore);
        throw new RuntimeException("failed to restore SQLite database: $path");
    }
}

function cow_merge_cleanup_sqlite_snapshot(array $snapshot): void {
    $backup = $snapshot['backup'] ?? null;
    if (is_string($backup) && (file_exists($backup) || is_link($backup))) {
        @unlink($backup);
    }
}

function cow_merge_quote_ident(string $name): string {
    return '"' . str_replace('"', '""', $name) . '"';
}

function cow_merge_bind(SQLite3Stmt $stmt, string|int $param, mixed $value): void {
    if ($value === null) {
        $stmt->bindValue($param, null, SQLITE3_NULL);
    } elseif (is_int($value)) {
        $stmt->bindValue($param, $value, SQLITE3_INTEGER);
    } elseif (is_float($value)) {
        $stmt->bindValue($param, $value, SQLITE3_FLOAT);
    } else {
        $stmt->bindValue($param, (string)$value, SQLITE3_TEXT);
    }
}

function cow_merge_payload(mixed $value): mixed {
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string)$k] = cow_merge_payload($v);
        }
        ksort($out);
        return $out;
    }
    if (is_string($value)) {
        return ['type' => 'bytes', 'base64' => base64_encode($value)];
    }
    if ($value === null || is_int($value) || is_float($value) || is_bool($value)) {
        return ['type' => gettype($value), 'value' => $value];
    }
    return ['type' => gettype($value), 'value' => (string)$value];
}

function cow_merge_payload_json(mixed $value): string {
    $encoded = json_encode(cow_merge_payload($value), JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('failed to encode merge payload');
    }
    return $encoded;
}

function cow_merge_value_key(mixed $value): string {
    return hash('sha256', cow_merge_payload_json($value));
}

function cow_merge_values_equal(mixed $a, mixed $b): bool {
    return cow_merge_value_key($a) === cow_merge_value_key($b);
}

function cow_merge_path_to_unix(string $path): string {
    return str_replace(DIRECTORY_SEPARATOR, '/', $path);
}

function cow_merge_file_is_excluded(string $rel): bool {
    $rel = ltrim(cow_merge_path_to_unix($rel), '/');
    if ($rel === '' || $rel === 'database.sql' || $rel === 'wp-config.php') {
        return true;
    }
    foreach ([
        '.git',
        'wp-content/database',
        'wp-content/db.php',
        'wp-content/mu-plugins/forkpress-wp.php',
        'wp-content/plugins/sqlite-database-integration',
    ] as $excluded) {
        if ($rel === $excluded || str_starts_with($rel, $excluded . '/')) {
            return true;
        }
    }
    return false;
}

function cow_merge_file_manifest_for_root(string $root): array {
    if (!is_dir($root)) {
        throw new RuntimeException("filesystem merge root does not exist: $root");
    }
    $root = rtrim($root, "/\\");
    $entries = [];
    $stack = [$root];

    while ($stack) {
        $dir = array_pop($stack);
        $children = @scandir($dir);
        if ($children === false) {
            throw new RuntimeException("failed to read filesystem merge directory: $dir");
        }
        foreach ($children as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            $rel = cow_merge_path_to_unix(substr($path, strlen($root) + 1));
            if (cow_merge_file_is_excluded($rel)) {
                continue;
            }
            if (is_link($path)) {
                $target = readlink($path);
                $entries[$rel] = [
                    'type' => 'symlink',
                    'target' => is_string($target) ? $target : '',
                ];
                continue;
            }
            if (is_dir($path)) {
                $entries[$rel] = [
                    'type' => 'dir',
                    'mode' => ((int)fileperms($path)) & 0777,
                ];
                $stack[] = $path;
                continue;
            }
            if (!is_file($path)) {
                $entries[$rel] = ['type' => 'special'];
                continue;
            }
            $hash = hash_file('sha256', $path);
            if (!is_string($hash)) {
                throw new RuntimeException("failed to hash filesystem merge path: $path");
            }
            $entries[$rel] = [
                'type' => 'file',
                'sha256' => $hash,
                'size' => filesize($path),
                'mode' => ((int)fileperms($path)) & 0777,
            ];
        }
    }
    ksort($entries);
    return [
        'version' => 1,
        'captured_at' => gmdate('c'),
        'entries' => $entries,
    ];
}

function cow_merge_write_json_file(string $path, array $payload): void {
    cow_merge_mkdir_p(dirname($path));
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException("failed to encode JSON for $path");
    }
    if (file_put_contents($path, $json . "\n") === false) {
        throw new RuntimeException("failed to write $path");
    }
}

function cow_merge_capture_file_base(string $root, string $file_base): array {
    $manifest = cow_merge_file_manifest_for_root($root);
    cow_merge_write_json_file($file_base, $manifest);
    return [
        'status' => 'file_base_captured',
        'files' => count(array_filter(
            $manifest['entries'],
            fn(array $entry): bool => ($entry['type'] ?? null) !== 'dir'
        )),
        'file_base' => $file_base,
    ];
}

function cow_merge_test_hook(string $name, mixed ...$args): void {
    if (!defined('FORKPRESS_COW_MERGE_TESTS') || FORKPRESS_COW_MERGE_TESTS !== true) {
        return;
    }
    $hooks = $GLOBALS['cow_merge_test_hooks'][$name] ?? null;
    if (!is_array($hooks)) {
        return;
    }
    foreach ($hooks as $hook) {
        if (is_callable($hook)) {
            $hook(...$args);
        }
    }
}

function cow_merge_failpoint(string $name): void {
    $configured = getenv('FORKPRESS_COW_MERGE_TEST_FAILPOINT');
    if (!is_string($configured) || $configured === '') {
        return;
    }
    $failpoints = array_map('trim', explode(',', $configured));
    if (!in_array($name, $failpoints, true)) {
        return;
    }

    $action = getenv('FORKPRESS_COW_MERGE_TEST_FAILPOINT_ACTION');
    $action = is_string($action) && $action !== '' ? $action : 'throw';
    if ($action === 'kill') {
        if (function_exists('posix_kill') && defined('SIGKILL')) {
            posix_kill(getmypid(), SIGKILL);
        }
        exit(86);
    }
    if ($action === 'exit') {
        exit(86);
    }
    throw new RuntimeException("forced COW merge failpoint: $name");
}

function cow_merge_read_file_base(string $file_base): array {
    if (!is_file($file_base)) {
        throw new RuntimeException("filesystem merge base does not exist: $file_base");
    }
    $decoded = json_decode((string)file_get_contents($file_base), true);
    if (!is_array($decoded) || ($decoded['version'] ?? null) !== 1 || !is_array($decoded['entries'] ?? null)) {
        throw new RuntimeException("invalid filesystem merge base manifest: $file_base");
    }
    $entries = $decoded['entries'];
    ksort($entries);
    return $entries;
}

function cow_merge_file_entries_equal(?array $a, ?array $b): bool {
    if ($a === null || $b === null) {
        return $a === $b;
    }
    return cow_merge_values_equal($a, $b);
}

function cow_merge_symlink_target_to_unix(string $target): string {
    return str_replace('\\', '/', $target);
}

function cow_merge_relative_path_is_absolute(string $path): bool {
    return str_starts_with($path, '/')
        || str_starts_with($path, '\\')
        || preg_match('/^[A-Za-z]:/', $path) === 1;
}

function cow_merge_normalize_relative_path(string $path): ?string {
    $path = cow_merge_symlink_target_to_unix($path);
    if ($path === '' || str_contains($path, "\0") || cow_merge_relative_path_is_absolute($path)) {
        return null;
    }
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            if (!$segments) {
                return null;
            }
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }
    return implode('/', $segments);
}

function cow_merge_symlink_target_relative_path(string $path, string $target): ?string {
    $target = cow_merge_symlink_target_to_unix($target);
    if ($target === '' || str_contains($target, "\0") || cow_merge_relative_path_is_absolute($target)) {
        return null;
    }
    $parent = dirname($path);
    $parent = $parent === '.' ? '' : cow_merge_path_to_unix($parent);
    $combined = $parent === '' ? $target : $parent . '/' . $target;
    return cow_merge_normalize_relative_path($combined);
}

function cow_merge_symlink_safety_reason(string $path, array $entry): ?string {
    if (($entry['type'] ?? null) !== 'symlink') {
        return null;
    }
    $target = $entry['target'] ?? null;
    if (!is_string($target) || $target === '') {
        return 'symlink target is empty';
    }
    if (str_contains($target, "\0")) {
        return 'symlink target contains a NUL byte';
    }
    if (cow_merge_relative_path_is_absolute($target)) {
        return 'symlink target is absolute';
    }
    $resolved = cow_merge_symlink_target_relative_path($path, $target);
    if ($resolved === null || $resolved === '') {
        return 'symlink target resolves outside the filesystem merge root';
    }
    if ($resolved === $path) {
        return 'symlink target points at itself';
    }
    if (cow_merge_file_is_excluded($resolved)) {
        return 'symlink target points at a ForkPress-managed path';
    }
    return null;
}

function cow_merge_file_identity_json(string $path): string {
    return cow_merge_payload_json(['path' => $path]);
}

function cow_merge_plain_json(array $value): string {
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('failed to encode merge identity');
    }
    return $encoded;
}

function cow_merge_row_hash(array $row): string {
    return cow_merge_value_key($row);
}

function cow_merge_table_sql_map(SQLite3 $db): array {
    $tables = [];
    $res = cow_merge_query_checked(
        $db,
        "SELECT name, sql FROM sqlite_master " .
        "WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        'failed to read table schema map'
    );
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $tables[(string)$row['name']] = $row['sql'];
    }
    cow_merge_result_finalize_checked($res, 'failed to finalize table schema map');
    return $tables;
}

function cow_merge_index_sql_map(SQLite3 $db): array {
    $indexes = [];
    $res = cow_merge_query_checked(
        $db,
        "SELECT name, tbl_name, sql FROM sqlite_master " .
        "WHERE type = 'index' AND sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY name",
        'failed to read index schema map'
    );
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $indexes[(string)$row['name']] = [
            'table' => (string)$row['tbl_name'],
            'sql' => (string)$row['sql'],
        ];
    }
    cow_merge_result_finalize_checked($res, 'failed to finalize index schema map');
    return $indexes;
}

function cow_merge_schema_object_sql_map(SQLite3 $db, string $type): array {
    if (!in_array($type, ['view', 'trigger'], true)) {
        throw new InvalidArgumentException("unsupported schema object type: $type");
    }
    $objects = [];
    $stmt = cow_merge_prepare_checked(
        $db,
        "SELECT name, tbl_name, sql FROM sqlite_master " .
        "WHERE type = :type AND sql IS NOT NULL ORDER BY name",
        "failed to prepare $type schema lookup"
    );
    cow_merge_bind($stmt, ':type', $type);
    $res = cow_merge_execute_checked($stmt, $db, "failed to read $type schema");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $objects[(string)$row['name']] = [
            'table' => (string)$row['tbl_name'],
            'sql' => (string)$row['sql'],
        ];
    }
    cow_merge_result_finalize_checked($res, "failed to finalize $type schema lookup");
    return $objects;
}

function cow_merge_table_sql(SQLite3 $db, string $table): ?string {
    $stmt = cow_merge_prepare_checked(
        $db,
        "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :name",
        "failed to prepare table schema lookup for $table"
    );
    cow_merge_bind($stmt, ':name', $table);
    $res = cow_merge_execute_checked($stmt, $db, "failed to read table schema for $table");
    $row = $res->fetchArray(SQLITE3_ASSOC);
    cow_merge_result_finalize_checked($res, "failed to finalize table schema lookup for $table");
    return $row ? (string)$row['sql'] : null;
}

function cow_merge_index_sql(SQLite3 $db, string $index): ?string {
    $stmt = cow_merge_prepare_checked(
        $db,
        "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = :name AND sql IS NOT NULL",
        "failed to prepare index schema lookup for $index"
    );
    cow_merge_bind($stmt, ':name', $index);
    $res = cow_merge_execute_checked($stmt, $db, "failed to read index schema for $index");
    $row = $res->fetchArray(SQLITE3_ASSOC);
    cow_merge_result_finalize_checked($res, "failed to finalize index schema lookup for $index");
    return $row ? (string)$row['sql'] : null;
}

function cow_merge_schema_object_sql(SQLite3 $db, string $type, string $name): ?string {
    if (!in_array($type, ['view', 'trigger'], true)) {
        throw new InvalidArgumentException("unsupported schema object type: $type");
    }
    $stmt = cow_merge_prepare_checked(
        $db,
        "SELECT sql FROM sqlite_master WHERE type = :type AND name = :name AND sql IS NOT NULL",
        "failed to prepare $type schema lookup for $name"
    );
    cow_merge_bind($stmt, ':type', $type);
    cow_merge_bind($stmt, ':name', $name);
    $res = cow_merge_execute_checked($stmt, $db, "failed to read $type schema for $name");
    $row = $res->fetchArray(SQLITE3_ASSOC);
    cow_merge_result_finalize_checked($res, "failed to finalize $type schema lookup for $name");
    return $row ? (string)$row['sql'] : null;
}

function cow_merge_source_table_restore_payload(SQLite3 $source, string $table, string $table_sql): array {
    $indexes = [];
    foreach (cow_merge_index_sql_map($source) as $name => $entry) {
        if ((string)$entry['table'] === $table) {
            $indexes[] = [
                'name' => (string)$name,
                'sql' => (string)$entry['sql'],
            ];
        }
    }
    $triggers = [];
    foreach (cow_merge_schema_object_sql_map($source, 'trigger') as $name => $entry) {
        if ((string)$entry['table'] === $table) {
            $triggers[] = [
                'name' => (string)$name,
                'sql' => (string)$entry['sql'],
            ];
        }
    }
    return [
        'table_sql' => $table_sql,
        'indexes' => $indexes,
        'triggers' => $triggers,
    ];
}

function cow_merge_normalize_source_table_restore_payload(mixed $payload): array {
    if (is_string($payload)) {
        return [
            'table_sql' => $payload,
            'indexes' => [],
            'triggers' => [],
        ];
    }
    if (!is_array($payload) || !isset($payload['table_sql']) || !is_string($payload['table_sql'])) {
        throw new RuntimeException('schema conflict does not contain a source table restore payload');
    }
    $normalized = [
        'table_sql' => $payload['table_sql'],
        'indexes' => [],
        'triggers' => [],
    ];
    foreach (['indexes', 'triggers'] as $key) {
        foreach (($payload[$key] ?? []) as $entry) {
            if (!is_array($entry) || !isset($entry['name'], $entry['sql']) || !is_string($entry['name']) || !is_string($entry['sql'])) {
                throw new RuntimeException("schema conflict contains an invalid source table $key payload");
            }
            $normalized[$key][] = [
                'name' => $entry['name'],
                'sql' => $entry['sql'],
            ];
        }
    }
    return $normalized;
}

function cow_merge_has_schema_conflict_for_object(SQLite3 $meta, int $run_id, string $table, string $object, array $types): bool {
    $stmt = cow_merge_prepare_checked(
        $meta,
        "SELECT 1 FROM merge_conflicts WHERE run_id = :run_id AND table_name = :table_name " .
        "AND column_name = :column_name AND conflict_type = :conflict_type LIMIT 1",
        'failed to prepare restore payload conflict lookup'
    );
    foreach ($types as $type) {
        cow_merge_bind($stmt, ':run_id', $run_id);
        cow_merge_bind($stmt, ':table_name', $table);
        cow_merge_bind($stmt, ':column_name', $object);
        cow_merge_bind($stmt, ':conflict_type', (string)$type);
        $res = cow_merge_execute_checked($stmt, $meta, 'failed to inspect restore payload conflicts');
        if ($res->fetchArray(SQLITE3_NUM)) {
            cow_merge_result_finalize_checked($res, 'failed to finalize restore payload conflict lookup');
            return true;
        }
        cow_merge_result_finalize_checked($res, 'failed to finalize restore payload conflict lookup');
        $stmt->reset();
    }
    return false;
}

function cow_merge_filter_deferred_source_table_restore_payload(SQLite3 $meta, int $run_id, string $table, array $payload): array {
    $filtered = $payload;
    $filtered['indexes'] = [];
    foreach ($payload['indexes'] as $index) {
        $name = (string)$index['name'];
        if (cow_merge_has_schema_conflict_for_object($meta, $run_id, $table, $name, [
            'schema-source-added-index',
            'schema-source-changed-index',
            'schema-index-conflict',
        ])) {
            continue;
        }
        $filtered['indexes'][] = $index;
    }
    $filtered['triggers'] = [];
    foreach ($payload['triggers'] as $trigger) {
        $name = (string)$trigger['name'];
        if (cow_merge_has_schema_conflict_for_object($meta, $run_id, $table, $name, [
            'schema-source-added-trigger',
            'schema-source-changed-trigger',
            'schema-trigger-conflict',
        ])) {
            continue;
        }
        $filtered['triggers'][] = $trigger;
    }
    return $filtered;
}

function cow_merge_table_columns(SQLite3 $db, string $table): array {
    $columns = [];
    $res = cow_merge_query_checked(
        $db,
        'PRAGMA table_info(' . cow_merge_quote_ident($table) . ')',
        "failed to read table columns for $table"
    );
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $columns[] = (string)$row['name'];
    }
    cow_merge_result_finalize_checked($res, "failed to finalize table columns for $table");
    return $columns;
}

function cow_merge_table_info(SQLite3 $db, string $table): array {
    $columns = [];
    $res = cow_merge_query_checked(
        $db,
        'PRAGMA table_info(' . cow_merge_quote_ident($table) . ')',
        "failed to read table info for $table"
    );
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $columns[] = [
            'name' => (string)$row['name'],
            'type' => (string)$row['type'],
            'notnull' => (int)$row['notnull'],
            'dflt_value' => $row['dflt_value'],
            'pk' => (int)$row['pk'],
        ];
    }
    cow_merge_result_finalize_checked($res, "failed to finalize table info for $table");
    return $columns;
}

function cow_merge_hidden_rowid_selector(SQLite3 $db, string $table): string {
    $columns = array_fill_keys(array_map('strtolower', cow_merge_table_columns($db, $table)), true);
    foreach (['_rowid_', 'oid', 'rowid'] as $candidate) {
        if (!isset($columns[$candidate])) {
            return $candidate;
        }
    }
    throw new RuntimeException("cannot access hidden rowid for no-primary-key table $table because rowid, _rowid_, and oid are all table columns");
}

function cow_merge_keyless_select_parts(SQLite3 $db, string $table): array {
    $columns = cow_merge_table_columns($db, $table);
    $select = [cow_merge_hidden_rowid_selector($db, $table)];
    foreach ($columns as $column) {
        $select[] = cow_merge_quote_ident($column);
    }
    return [$select, $columns];
}

function cow_merge_keyless_entry_from_numeric_row(array $values, array $columns, string $context): ?array {
    $rowid = $values[0] ?? null;
    if ($rowid === null) {
        return null;
    }
    if (!is_numeric($rowid)) {
        throw new RuntimeException("failed to read numeric rowid for $context");
    }
    $row = [];
    foreach ($columns as $i => $column) {
        $row[$column] = $values[$i + 1] ?? null;
    }
    return [
        'rowid' => (int)$rowid,
        'row' => $row,
    ];
}

function cow_merge_column_signature(array $column): array {
    return [
        'name' => strtolower((string)$column['name']),
        'type' => strtoupper(trim((string)$column['type'])),
        'notnull' => (int)$column['notnull'],
        'dflt_value' => $column['dflt_value'],
        'pk' => (int)$column['pk'],
    ];
}

function cow_merge_column_signatures_equal(array $a, array $b): bool {
    return cow_merge_column_signature($a) === cow_merge_column_signature($b);
}

function cow_merge_columns_by_name(array $columns): array {
    $by_name = [];
    foreach ($columns as $column) {
        $by_name[strtolower((string)$column['name'])] = $column;
    }
    return $by_name;
}

function cow_merge_base_column_prefix_unchanged(array $base_columns, array $changed_columns): bool {
    if (count($changed_columns) < count($base_columns)) {
        return false;
    }
    foreach ($base_columns as $i => $base_column) {
        if (!isset($changed_columns[$i]) || !cow_merge_column_signatures_equal($base_column, $changed_columns[$i])) {
            return false;
        }
    }
    return true;
}

function cow_merge_split_sql_list(string $sql): array {
    $items = [];
    $start = 0;
    $depth = 0;
    $quote = null;
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($quote !== null) {
            if ($quote === '[' && $ch === ']') {
                $quote = null;
            } elseif ($ch === $quote) {
                if ($i + 1 < $len && $sql[$i + 1] === $quote && ($quote === '"' || $quote === "'" || $quote === '`')) {
                    $i++;
                } else {
                    $quote = null;
                }
            }
            continue;
        }
        if ($ch === '"' || $ch === "'" || $ch === '`' || $ch === '[') {
            $quote = $ch;
            continue;
        }
        if ($ch === '(') {
            $depth++;
            continue;
        }
        if ($ch === ')' && $depth > 0) {
            $depth--;
            continue;
        }
        if ($ch === ',' && $depth === 0) {
            $items[] = trim(substr($sql, $start, $i - $start));
            $start = $i + 1;
        }
    }
    $tail = trim(substr($sql, $start));
    if ($tail !== '') {
        $items[] = $tail;
    }
    return $items;
}

function cow_merge_create_table_body(string $ddl): ?string {
    $start = strpos($ddl, '(');
    if ($start === false) {
        return null;
    }
    $depth = 0;
    $quote = null;
    $len = strlen($ddl);
    for ($i = $start; $i < $len; $i++) {
        $ch = $ddl[$i];
        if ($quote !== null) {
            if ($quote === '[' && $ch === ']') {
                $quote = null;
            } elseif ($ch === $quote) {
                if ($i + 1 < $len && $ddl[$i + 1] === $quote && ($quote === '"' || $quote === "'" || $quote === '`')) {
                    $i++;
                } else {
                    $quote = null;
                }
            }
            continue;
        }
        if ($ch === '"' || $ch === "'" || $ch === '`' || $ch === '[') {
            $quote = $ch;
            continue;
        }
        if ($ch === '(') {
            $depth++;
            continue;
        }
        if ($ch === ')') {
            $depth--;
            if ($depth === 0) {
                return substr($ddl, $start + 1, $i - $start - 1);
            }
        }
    }
    return null;
}

function cow_merge_create_table_parts(string $ddl): ?array {
    $start = strpos($ddl, '(');
    if ($start === false) {
        return null;
    }
    $depth = 0;
    $quote = null;
    $len = strlen($ddl);
    for ($i = $start; $i < $len; $i++) {
        $ch = $ddl[$i];
        if ($quote !== null) {
            if ($quote === '[' && $ch === ']') {
                $quote = null;
            } elseif ($ch === $quote) {
                if ($i + 1 < $len && $ddl[$i + 1] === $quote && ($quote === '"' || $quote === "'" || $quote === '`')) {
                    $i++;
                } else {
                    $quote = null;
                }
            }
            continue;
        }
        if ($ch === '"' || $ch === "'" || $ch === '`' || $ch === '[') {
            $quote = $ch;
            continue;
        }
        if ($ch === '(') {
            $depth++;
            continue;
        }
        if ($ch === ')') {
            $depth--;
            if ($depth === 0) {
                return [
                    'body' => substr($ddl, $start + 1, $i - $start - 1),
                    'suffix' => substr($ddl, $i + 1),
                ];
            }
        }
    }
    return null;
}

function cow_merge_create_table_sql_for_name(string $ddl, string $table): ?string {
    $parts = cow_merge_create_table_parts($ddl);
    if ($parts === null) {
        return null;
    }
    return 'CREATE TABLE ' . cow_merge_quote_ident($table) . ' (' . $parts['body'] . ')' . $parts['suffix'];
}

function cow_merge_read_identifier(string $sql): ?array {
    $sql = ltrim($sql);
    if ($sql === '') {
        return null;
    }
    $first = $sql[0];
    if ($first === '"' || $first === "'" || $first === '`') {
        $value = '';
        $len = strlen($sql);
        for ($i = 1; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($ch === $first) {
                if ($i + 1 < $len && $sql[$i + 1] === $first) {
                    $value .= $first;
                    $i++;
                    continue;
                }
                return [$value, substr($sql, $i + 1)];
            }
            $value .= $ch;
        }
        return null;
    }
    if ($first === '[') {
        $end = strpos($sql, ']');
        if ($end === false) {
            return null;
        }
        return [substr($sql, 1, $end - 1), substr($sql, $end + 1)];
    }
    if (!preg_match('/^([^\\s(,]+)/', $sql, $matches)) {
        return null;
    }
    return [$matches[1], substr($sql, strlen($matches[1]))];
}

function cow_merge_column_definition_from_create_sql(string $ddl, string $column): ?string {
    $body = cow_merge_create_table_body($ddl);
    if ($body === null) {
        return null;
    }
    foreach (cow_merge_split_sql_list($body) as $item) {
        if (preg_match('/^(CONSTRAINT|PRIMARY|UNIQUE|CHECK|FOREIGN)\\b/i', ltrim($item))) {
            continue;
        }
        $identifier = cow_merge_read_identifier($item);
        if ($identifier === null) {
            continue;
        }
        if (strcasecmp($identifier[0], $column) === 0) {
            return $item;
        }
    }
    return null;
}

function cow_merge_column_definition_is_safe_to_add(string $definition, array $column): bool {
    if ((int)$column['pk'] !== 0) {
        return false;
    }
    if ((int)$column['notnull'] !== 0 && $column['dflt_value'] === null) {
        return false;
    }
    if (preg_match('/\\bPRIMARY\\s+KEY\\b|\\bUNIQUE\\b/i', $definition)) {
        return false;
    }
    if (preg_match('/\\bGENERATED\\b.*\\bSTORED\\b|\\bAS\\s*\\(.*\\)\\s*STORED\\b/i', $definition)) {
        return false;
    }
    return true;
}

function cow_merge_pk_cols(SQLite3 $db, string $table): array {
    $pk = [];
    $res = cow_merge_query_checked(
        $db,
        'PRAGMA table_info(' . cow_merge_quote_ident($table) . ')',
        "failed to read primary key columns for $table"
    );
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $ordinal = (int)$row['pk'];
        if ($ordinal > 0) {
            $pk[$ordinal] = (string)$row['name'];
        }
    }
    cow_merge_result_finalize_checked($res, "failed to finalize primary key columns for $table");
    ksort($pk);
    return array_values($pk);
}

function cow_merge_row_identity(array $row, array $pk_cols, mixed $rowid): array {
    if (!$pk_cols) {
        return ['rowid' => $rowid];
    }
    $identity = [];
    foreach ($pk_cols as $col) {
        $identity[$col] = $row[$col] ?? null;
    }
    return $identity;
}

function cow_merge_identity_json(array $identity): string {
    return cow_merge_payload_json($identity);
}

function cow_merge_load_rows(SQLite3 $db, string $table, array $pk_cols): array {
    $rows = [];
    if (cow_merge_table_sql($db, $table) === null) {
        return $rows;
    }
    if (!$pk_cols) {
        [$select, $columns] = cow_merge_keyless_select_parts($db, $table);
        $res = cow_merge_query_checked(
            $db,
            'SELECT ' . implode(', ', $select) . ' FROM ' . cow_merge_quote_ident($table),
            "failed to load rows for $table"
        );
        while ($values = $res->fetchArray(SQLITE3_NUM)) {
            $entry = cow_merge_keyless_entry_from_numeric_row($values, $columns, "$table row load");
            if ($entry === null) {
                continue;
            }
            $identity = cow_merge_row_identity($entry['row'], $pk_cols, $entry['rowid']);
            $rows[cow_merge_identity_json($identity)] = [
                'identity' => $identity,
                'rowid' => $entry['rowid'],
                'row' => $entry['row'],
            ];
        }
        cow_merge_result_finalize_checked($res, "failed to finalize loaded rows for $table");
        return $rows;
    }

    $res = cow_merge_query_checked($db, 'SELECT * FROM ' . cow_merge_quote_ident($table), "failed to load rows for $table");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $rowid = null;
        $identity = cow_merge_row_identity($row, $pk_cols, $rowid);
        $rows[cow_merge_identity_json($identity)] = [
            'identity' => $identity,
            'rowid' => $rowid,
            'row' => $row,
        ];
    }
    cow_merge_result_finalize_checked($res, "failed to finalize loaded rows for $table");
    return $rows;
}

function cow_merge_load_keyless_physical_rows(SQLite3 $db, string $table): array {
    $rows = [];
    if (cow_merge_table_sql($db, $table) === null) {
        return $rows;
    }
    [$select, $columns] = cow_merge_keyless_select_parts($db, $table);
    $res = cow_merge_query_checked(
        $db,
        'SELECT ' . implode(', ', $select) . ' FROM ' . cow_merge_quote_ident($table),
        "failed to load physical keyless rows for $table"
    );
    while ($values = $res->fetchArray(SQLITE3_NUM)) {
        $entry = cow_merge_keyless_entry_from_numeric_row($values, $columns, "$table physical row load");
        if ($entry === null) {
            continue;
        }
        $rows[(string)$entry['rowid']] = $entry;
    }
    cow_merge_result_finalize_checked($res, "failed to finalize physical keyless rows for $table");
    return $rows;
}

function cow_merge_keyless_base_identity(string $table, int $rowid, array $row): array {
    return [
        'sidecar' => 'keyless-row',
        'origin' => 'base',
        'table' => $table,
        'base_rowid' => $rowid,
        'initial_row_hash' => cow_merge_row_hash($row),
    ];
}

function cow_merge_keyless_branch_identity(string $branch, string $table, int $rowid, array $row): array {
    return [
        'sidecar' => 'keyless-row',
        'origin' => 'branch',
        'branch' => $branch,
        'table' => $table,
        'rowid' => $rowid,
        'initial_row_hash' => cow_merge_row_hash($row),
    ];
}

function cow_merge_keyless_runtime_identity(
    string $branch,
    string $table,
    int $rowid,
    array $row,
    int $run_id,
    int $event_id,
    string $origin = 'runtime-insert'
): array {
    return [
        'sidecar' => 'keyless-row',
        'origin' => $origin,
        'branch' => $branch,
        'table' => $table,
        'rowid' => $rowid,
        'tracking_run_id' => $run_id,
        'event_id' => $event_id,
        'initial_row_hash' => cow_merge_row_hash($row),
    ];
}

function cow_merge_lookup_row_identity(SQLite3 $meta, string $branch, string $table, int $rowid): ?array {
    $stmt = cow_merge_prepare_checked(
        $meta,
        'SELECT logical_identity FROM merge_row_identities ' .
        'WHERE branch_name = :branch_name AND table_name = :table_name AND rowid = :rowid',
        'failed to prepare row identity lookup'
    );
    cow_merge_bind($stmt, ':branch_name', $branch);
    cow_merge_bind($stmt, ':table_name', $table);
    cow_merge_bind($stmt, ':rowid', $rowid);
    $res = cow_merge_execute_checked($stmt, $meta, 'failed to look up row identity');
    $row = $res->fetchArray(SQLITE3_ASSOC);
    cow_merge_result_finalize_checked($res, 'failed to finalize row identity lookup');
    if (!$row) {
        return null;
    }
    $identity = json_decode((string)$row['logical_identity'], true);
    if (!is_array($identity)) {
        throw new RuntimeException("invalid row identity sidecar for $branch.$table rowid $rowid");
    }
    return $identity;
}

function cow_merge_decode_row_identity(string $json, string $context): array {
    $identity = json_decode($json, true);
    if (!is_array($identity)) {
        throw new RuntimeException("invalid row identity sidecar for $context");
    }
    return $identity;
}

function cow_merge_lookup_row_identity_by_hash(
    SQLite3 $meta,
    string $branch,
    string $table,
    int $rowid,
    string $row_hash
): ?array {
    $stmt = cow_merge_prepare_checked(
        $meta,
        'SELECT logical_identity FROM merge_row_identity_history ' .
        'WHERE branch_name = :branch_name AND table_name = :table_name AND rowid = :rowid AND row_hash = :row_hash ' .
        'ORDER BY CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END, updated_at DESC, id DESC LIMIT 1',
        'failed to prepare row identity history lookup'
    );
    cow_merge_bind($stmt, ':branch_name', $branch);
    cow_merge_bind($stmt, ':table_name', $table);
    cow_merge_bind($stmt, ':rowid', $rowid);
    cow_merge_bind($stmt, ':row_hash', $row_hash);
    $res = cow_merge_execute_checked($stmt, $meta, 'failed to look up row identity history');
    $row = $res->fetchArray(SQLITE3_ASSOC);
    cow_merge_result_finalize_checked($res, 'failed to finalize row identity history lookup');
    if (!$row) {
        return null;
    }
    return cow_merge_decode_row_identity((string)$row['logical_identity'], "$branch.$table rowid $rowid history");
}

function cow_merge_remember_row_identity_history(
    SQLite3 $meta,
    int $run_id,
    string $branch,
    string $table,
    int $rowid,
    array $identity,
    array $row,
    bool $active = true
): void {
    $on_conflict = $active
        ? 'row_hash = excluded.row_hash, last_seen_run_id = excluded.last_seen_run_id, updated_at = CURRENT_TIMESTAMP, deleted_at = NULL, deleted_run_id = NULL'
        : 'row_hash = excluded.row_hash, last_seen_run_id = excluded.last_seen_run_id, updated_at = CURRENT_TIMESTAMP';
    $history = cow_merge_prepare_checked(
        $meta,
        'INSERT INTO merge_row_identity_history ' .
        '(branch_name, table_name, rowid, logical_identity, row_hash, first_seen_run_id, last_seen_run_id) ' .
        'VALUES (:branch_name, :table_name, :rowid, :logical_identity, :row_hash, :first_seen_run_id, :last_seen_run_id) ' .
        'ON CONFLICT(branch_name, table_name, rowid, logical_identity) DO UPDATE SET ' . $on_conflict,
        'failed to prepare row identity history upsert'
    );
    cow_merge_bind($history, ':branch_name', $branch);
    cow_merge_bind($history, ':table_name', $table);
    cow_merge_bind($history, ':rowid', $rowid);
    cow_merge_bind($history, ':logical_identity', cow_merge_plain_json($identity));
    cow_merge_bind($history, ':row_hash', cow_merge_row_hash($row));
    cow_merge_bind($history, ':first_seen_run_id', $run_id);
    cow_merge_bind($history, ':last_seen_run_id', $run_id);
    cow_merge_execute_checked($history, $meta, 'failed to remember row identity history');
}

function cow_merge_remember_row_identity(
    SQLite3 $meta,
    int $run_id,
    string $branch,
    string $table,
    int $rowid,
    array $identity,
    array $row
): void {
    $stmt = cow_merge_prepare_checked(
        $meta,
        'INSERT INTO merge_row_identities ' .
        '(branch_name, table_name, rowid, logical_identity, row_hash, first_seen_run_id, last_seen_run_id) ' .
        'VALUES (:branch_name, :table_name, :rowid, :logical_identity, :row_hash, :first_seen_run_id, :last_seen_run_id) ' .
        'ON CONFLICT(branch_name, table_name, rowid) DO UPDATE SET ' .
        'row_hash = excluded.row_hash, last_seen_run_id = excluded.last_seen_run_id, updated_at = CURRENT_TIMESTAMP',
        'failed to prepare row identity upsert'
    );
    cow_merge_bind($stmt, ':branch_name', $branch);
    cow_merge_bind($stmt, ':table_name', $table);
    cow_merge_bind($stmt, ':rowid', $rowid);
    cow_merge_bind($stmt, ':logical_identity', cow_merge_plain_json($identity));
    cow_merge_bind($stmt, ':row_hash', cow_merge_row_hash($row));
    cow_merge_bind($stmt, ':first_seen_run_id', $run_id);
    cow_merge_bind($stmt, ':last_seen_run_id', $run_id);
    cow_merge_execute_checked($stmt, $meta, 'failed to remember row identity');

    cow_merge_remember_row_identity_history($meta, $run_id, $branch, $table, $rowid, $identity, $row);
}

function cow_merge_adopt_row_identity(
    SQLite3 $meta,
    int $run_id,
    string $branch,
    string $table,
    int $rowid,
    array $identity,
    array $row
): void {
    cow_merge_forget_row_identity($meta, $run_id, $branch, $table, $rowid);
    cow_merge_remember_row_identity($meta, $run_id, $branch, $table, $rowid, $identity, $row);
}

function cow_merge_forget_row_identity(
    SQLite3 $meta,
    int $run_id,
    string $branch,
    string $table,
    int $rowid
): ?array {
    $stmt = cow_merge_prepare_checked(
        $meta,
        'SELECT logical_identity FROM merge_row_identities ' .
        'WHERE branch_name = :branch_name AND table_name = :table_name AND rowid = :rowid',
        'failed to prepare row identity deletion lookup'
    );
    cow_merge_bind($stmt, ':branch_name', $branch);
    cow_merge_bind($stmt, ':table_name', $table);
    cow_merge_bind($stmt, ':rowid', $rowid);
    $res = cow_merge_execute_checked($stmt, $meta, 'failed to look up row identity for deletion');
    $row = $res->fetchArray(SQLITE3_ASSOC);
    cow_merge_result_finalize_checked($res, 'failed to finalize row identity deletion lookup');
    if (!$row) {
        return null;
    }

    $identity_json = (string)$row['logical_identity'];
    $identity = cow_merge_decode_row_identity($identity_json, "$branch.$table rowid $rowid deletion");

    $history = cow_merge_prepare_checked(
        $meta,
        'UPDATE merge_row_identity_history ' .
        'SET deleted_at = CURRENT_TIMESTAMP, deleted_run_id = :deleted_run_id, last_seen_run_id = :last_seen_run_id, updated_at = CURRENT_TIMESTAMP ' .
        'WHERE branch_name = :branch_name AND table_name = :table_name AND rowid = :rowid AND logical_identity = :logical_identity',
        'failed to prepare row identity history tombstone'
    );
    cow_merge_bind($history, ':deleted_run_id', $run_id);
    cow_merge_bind($history, ':last_seen_run_id', $run_id);
    cow_merge_bind($history, ':branch_name', $branch);
    cow_merge_bind($history, ':table_name', $table);
    cow_merge_bind($history, ':rowid', $rowid);
    cow_merge_bind($history, ':logical_identity', $identity_json);
    cow_merge_execute_checked($history, $meta, 'failed to mark row identity deleted');

    $delete = cow_merge_prepare_checked(
        $meta,
        'DELETE FROM merge_row_identities WHERE branch_name = :branch_name AND table_name = :table_name AND rowid = :rowid',
        'failed to prepare current row identity deletion'
    );
    cow_merge_bind($delete, ':branch_name', $branch);
    cow_merge_bind($delete, ':table_name', $table);
    cow_merge_bind($delete, ':rowid', $rowid);
    cow_merge_execute_checked($delete, $meta, 'failed to delete current row identity');

    return $identity;
}

function cow_merge_forget_table_row_identities(
    SQLite3 $meta,
    int $run_id,
    string $branch,
    string $table
): int {
    $stmt = cow_merge_prepare_checked(
        $meta,
        'SELECT rowid FROM merge_row_identities ' .
        'WHERE branch_name = :branch_name AND table_name = :table_name ORDER BY rowid',
        'failed to prepare table row identity listing'
    );
    cow_merge_bind($stmt, ':branch_name', $branch);
    cow_merge_bind($stmt, ':table_name', $table);
    $res = cow_merge_execute_checked($stmt, $meta, 'failed to list row identities for table deletion');

    $rowids = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $rowids[] = (int)$row['rowid'];
    }
    cow_merge_result_finalize_checked($res, 'failed to finalize table row identity listing');
    foreach ($rowids as $rowid) {
        cow_merge_forget_row_identity($meta, $run_id, $branch, $table, $rowid);
    }
    return count($rowids);
}

function cow_merge_refresh_table_row_identities(
    SQLite3 $db,
    SQLite3 $meta,
    int $run_id,
    string $branch,
    string $table
): int {
    if (cow_merge_table_sql($db, $table) === null || cow_merge_pk_cols($db, $table)) {
        return 0;
    }

    $refreshed = 0;
    foreach (cow_merge_load_keyless_physical_rows($db, $table) as $entry) {
        $rowid = (int)$entry['rowid'];
        $identity = cow_merge_lookup_row_identity($meta, $branch, $table, $rowid);
        if ($identity === null) {
            continue;
        }
        cow_merge_remember_row_identity($meta, $run_id, $branch, $table, $rowid, $identity, $entry['row']);
        $refreshed++;
    }
    return $refreshed;
}

function cow_merge_load_keyless_physical_row(SQLite3 $db, string $table, int $rowid): ?array {
    [$select, $columns] = cow_merge_keyless_select_parts($db, $table);
    $rowid_selector = cow_merge_hidden_rowid_selector($db, $table);
    $stmt = cow_merge_prepare_checked(
        $db,
        'SELECT ' . implode(', ', $select) . ' FROM ' . cow_merge_quote_ident($table) . ' WHERE ' . $rowid_selector . ' = :rowid',
        "failed to prepare keyless physical row lookup for $table"
    );
    cow_merge_bind($stmt, ':rowid', $rowid);
    $res = cow_merge_execute_checked($stmt, $db, "failed to load keyless row $table rowid $rowid");
    $values = $res->fetchArray(SQLITE3_NUM);
    if (!$values) {
        cow_merge_result_finalize_checked($res, "failed to finalize keyless physical row lookup for $table");
        return null;
    }
    $entry = cow_merge_keyless_entry_from_numeric_row($values, $columns, "$table physical row lookup");
    if ($entry === null) {
        cow_merge_result_finalize_checked($res, "failed to finalize keyless physical row lookup for $table");
        return null;
    }
    cow_merge_result_finalize_checked($res, "failed to finalize keyless physical row lookup for $table");
    return $entry;
}

function cow_merge_keyless_rows_for_branch(
    SQLite3 $db,
    SQLite3 $meta,
    int $run_id,
    string $branch,
    string $table,
    array $base_identities_by_rowid
): array {
    $rows = [];
    foreach (cow_merge_load_keyless_physical_rows($db, $table) as $rowid_key => $entry) {
        $rowid = (int)$entry['rowid'];
        $identity = cow_merge_lookup_row_identity($meta, $branch, $table, $rowid);
        if ($identity === null) {
            $identity = $base_identities_by_rowid[$rowid_key]
                ?? cow_merge_keyless_branch_identity($branch, $table, $rowid, $entry['row']);
        }
        cow_merge_remember_row_identity($meta, $run_id, $branch, $table, $rowid, $identity, $entry['row']);
        $rows[cow_merge_identity_json($identity)] = [
            'identity' => $identity,
            'rowid' => $rowid,
            'row' => $entry['row'],
        ];
    }
    return $rows;
}

function cow_merge_load_keyless_sidecar_rows(
    SQLite3 $base,
    SQLite3 $source,
    SQLite3 $target,
    SQLite3 $meta,
    int $run_id,
    string $source_branch,
    string $target_branch,
    string $table
): array {
    $base_rows = [];
    $base_identities_by_rowid = [];
    foreach (cow_merge_load_keyless_physical_rows($base, $table) as $rowid_key => $entry) {
        $rowid = (int)$entry['rowid'];
        $row_hash = cow_merge_row_hash($entry['row']);
        $identity = cow_merge_lookup_row_identity_by_hash($meta, $source_branch, $table, $rowid, $row_hash)
            ?? cow_merge_lookup_row_identity_by_hash($meta, $target_branch, $table, $rowid, $row_hash)
            ?? cow_merge_keyless_base_identity($table, $rowid, $entry['row']);
        cow_merge_remember_row_identity_history($meta, $run_id, $source_branch, $table, $rowid, $identity, $entry['row'], false);
        $base_identities_by_rowid[$rowid_key] = $identity;
        $base_rows[cow_merge_identity_json($identity)] = [
            'identity' => $identity,
            'rowid' => $rowid,
            'row' => $entry['row'],
        ];
    }

    return [
        $base_rows,
        cow_merge_keyless_rows_for_branch($source, $meta, $run_id, $source_branch, $table, $base_identities_by_rowid),
        cow_merge_keyless_rows_for_branch($target, $meta, $run_id, $target_branch, $table, $base_identities_by_rowid),
    ];
}

function cow_merge_all_columns(array ...$sets): array {
    $seen = [];
    $out = [];
    foreach ($sets as $set) {
        foreach ($set as $name) {
            if (!isset($seen[$name])) {
                $seen[$name] = true;
                $out[] = $name;
            }
        }
    }
    return $out;
}

function cow_merge_row_values_equal(?array $a, ?array $b, array $columns): bool {
    if ($a === null || $b === null) {
        return $a === $b;
    }
    foreach ($columns as $col) {
        if (!cow_merge_values_equal($a[$col] ?? null, $b[$col] ?? null)) {
            return false;
        }
    }
    return true;
}

function cow_merge_row_semantic_identity(string $table, ?array $row): ?array {
    if ($row === null) {
        return null;
    }
    $profiles = [
        'wp_posts' => ['post_type'],
        'wp_postmeta' => ['post_id', 'meta_key'],
        'wp_terms' => ['slug'],
        'wp_term_taxonomy' => ['term_id', 'taxonomy'],
        'wp_termmeta' => ['term_id', 'meta_key'],
        'wp_users' => ['user_login'],
        'wp_usermeta' => ['user_id', 'meta_key'],
        'wp_comments' => ['comment_post_ID', 'comment_type'],
        'wp_commentmeta' => ['comment_id', 'meta_key'],
        'wp_options' => ['option_name'],
    ];
    $columns = $profiles[$table] ?? null;
    if ($columns === null) {
        return null;
    }
    $identity = ['table' => $table];
    foreach ($columns as $column) {
        if (!array_key_exists($column, $row)) {
            return null;
        }
        $identity[$column] = $row[$column];
    }
    return $identity;
}

function cow_merge_keyless_row_identity_ambiguous(?array $base_row, ?array $source_row, ?array $target_row, array $columns): bool {
    if ($base_row === null || $source_row === null || $target_row === null || !$columns) {
        return false;
    }
    if (cow_merge_row_values_equal($source_row, $base_row, $columns) || cow_merge_row_values_equal($target_row, $base_row, $columns)) {
        return false;
    }
    if (cow_merge_row_values_equal($source_row, $target_row, $columns)) {
        return false;
    }

    $source_changed = false;
    $target_changed = false;
    $source_only_changed = false;
    foreach ($columns as $col) {
        $source_cell_changed = !cow_merge_values_equal($source_row[$col] ?? null, $base_row[$col] ?? null);
        $target_cell_changed = !cow_merge_values_equal($target_row[$col] ?? null, $base_row[$col] ?? null);
        $source_changed = $source_changed || $source_cell_changed;
        $target_changed = $target_changed || $target_cell_changed;
        if ($source_cell_changed && !$target_cell_changed) {
            $source_only_changed = true;
        }
    }
    return $source_changed && $target_changed && $source_only_changed;
}

function cow_merge_where_clause(SQLite3 $db, string $table, array $identity, array $pk_cols, array &$values): string {
    $clauses = [];
    if ($pk_cols) {
        foreach ($pk_cols as $col) {
            $clauses[] = cow_merge_quote_ident($col) . ' = ?';
            $values[] = $identity[$col] ?? null;
        }
    } else {
        $clauses[] = cow_merge_hidden_rowid_selector($db, $table) . ' = ?';
        $values[] = $identity['rowid'] ?? null;
    }
    return implode(' AND ', $clauses);
}

function cow_merge_entry_where_identity(?array $entry, array $pk_cols): ?array {
    if ($entry === null) {
        return null;
    }
    if ($pk_cols) {
        return $entry['identity'];
    }
    return ['rowid' => $entry['rowid'] ?? null];
}

function cow_merge_foreign_key_groups(SQLite3 $db, string $table): array {
    $res = cow_merge_query_checked(
        $db,
        'PRAGMA foreign_key_list(' . cow_merge_quote_ident($table) . ')',
        "failed to inspect foreign keys for $table"
    );
    $groups = [];
    try {
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $id = (string)($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $groups[$id][] = $row;
        }
    } finally {
        cow_merge_result_finalize_checked($res, "failed to finalize foreign key inspection for $table");
    }
    foreach ($groups as &$group) {
        usort($group, fn($a, $b) => (int)($a['seq'] ?? 0) <=> (int)($b['seq'] ?? 0));
    }
    unset($group);
    ksort($groups);
    return array_values($groups);
}

function cow_merge_foreign_key_parent_columns(SQLite3 $db, string $parent_table, array $group): ?array {
    $columns = [];
    $needs_parent_pk = false;
    foreach ($group as $part) {
        $to = (string)($part['to'] ?? '');
        if ($to === '') {
            $needs_parent_pk = true;
            break;
        }
        $columns[] = $to;
    }
    if (!$needs_parent_pk) {
        return $columns;
    }

    $pk_cols = cow_merge_pk_cols($db, $parent_table);
    return count($pk_cols) === count($group) ? $pk_cols : null;
}

function cow_merge_row_satisfies_own_foreign_key(array $row, array $from_columns, array $parent_columns, array $values): bool {
    foreach ($parent_columns as $i => $parent_column) {
        if (
            !array_key_exists($parent_column, $row) ||
            !array_key_exists($i, $values) ||
            !cow_merge_values_equal($row[$parent_column], $values[$i])
        ) {
            return false;
        }
    }
    return true;
}

function cow_merge_row_matches_values(array $row, array $columns, array $values): bool {
    foreach ($columns as $i => $column) {
        if (!array_key_exists($column, $row) || !array_key_exists($i, $values)) {
            return false;
        }
        if (!cow_merge_values_equal($row[$column], $values[$i])) {
            return false;
        }
    }
    return true;
}

function cow_merge_row_exists_by_values(SQLite3 $db, string $table, array $columns, array $values): bool {
    if (!$columns || count($columns) !== count($values) || cow_merge_table_sql($db, $table) === null) {
        return false;
    }

    $clauses = [];
    foreach ($columns as $column) {
        $clauses[] = cow_merge_quote_ident($column) . ' = ?';
    }
    $stmt = cow_merge_prepare_checked(
        $db,
        'SELECT 1 FROM ' . cow_merge_quote_ident($table) . ' WHERE ' . implode(' AND ', $clauses) . ' LIMIT 1',
        "failed to prepare row value lookup on $table"
    );
    foreach ($values as $i => $value) {
        cow_merge_bind($stmt, $i + 1, $value);
    }
    $res = cow_merge_execute_checked($stmt, $db, "failed to query row value lookup on $table");
    $exists = (bool)$res->fetchArray(SQLITE3_NUM);
    cow_merge_result_finalize_checked($res, "failed to finalize row value lookup on $table");
    return $exists;
}

function cow_merge_find_row_entry_by_values(array $rows, array $columns, array $values): ?array {
    foreach ($rows as $key => $entry) {
        $row = $entry['row'] ?? null;
        if (is_array($row) && cow_merge_row_matches_values($row, $columns, $values)) {
            return [$key, $entry];
        }
    }
    return null;
}

function cow_merge_sort_row_keys_by_self_foreign_keys(SQLite3 $db, string $table, array $keys, array $rows): array {
    $keys = array_values(array_unique($keys));
    sort($keys);
    if (count($keys) < 2) {
        return $keys;
    }

    $key_set = array_fill_keys($keys, true);
    $dependencies = [];
    foreach ($keys as $key) {
        $row = $rows[$key]['row'] ?? null;
        if (!is_array($row)) {
            continue;
        }
        foreach (cow_merge_foreign_key_groups($db, $table) as $group) {
            $parent_table = (string)($group[0]['table'] ?? '');
            if ($parent_table !== $table) {
                continue;
            }
            $parent_columns = cow_merge_foreign_key_parent_columns($db, $table, $group);
            if ($parent_columns === null) {
                continue;
            }

            $values = [];
            $skip = false;
            foreach ($group as $i => $part) {
                $from = (string)($part['from'] ?? '');
                if ($from === '' || !isset($parent_columns[$i]) || !array_key_exists($from, $row) || $row[$from] === null) {
                    $skip = true;
                    break;
                }
                $values[] = $row[$from];
            }
            if ($skip || !$values) {
                continue;
            }

            $parent_match = cow_merge_find_row_entry_by_values($rows, $parent_columns, $values);
            if ($parent_match === null) {
                continue;
            }
            [$parent_key] = $parent_match;
            $parent_key = (string)$parent_key;
            if ($parent_key === (string)$key || !isset($key_set[$parent_key])) {
                continue;
            }
            $dependencies[(string)$key][$parent_key] = true;
        }
    }
    if (!$dependencies) {
        return $keys;
    }

    $state = [];
    $ordered = [];
    $visit = function (string $key) use (&$visit, &$state, &$ordered, $dependencies): void {
        if (($state[$key] ?? null) === 'done') {
            return;
        }
        if (($state[$key] ?? null) === 'visiting') {
            return;
        }
        $state[$key] = 'visiting';
        $parents = array_keys($dependencies[$key] ?? []);
        sort($parents);
        foreach ($parents as $parent) {
            $visit($parent);
        }
        $state[$key] = 'done';
        $ordered[] = $key;
    };
    foreach ($keys as $key) {
        $visit((string)$key);
    }
    return $ordered;
}

function cow_merge_foreign_key_error(SQLite3 $target, string $table, array $row): ?string {
    foreach (cow_merge_foreign_key_groups($target, $table) as $group) {
        $parent_table = (string)($group[0]['table'] ?? '');
        if ($parent_table === '') {
            continue;
        }
        $parent_columns = cow_merge_foreign_key_parent_columns($target, $parent_table, $group);
        if ($parent_columns === null) {
            return "FOREIGN KEY constraint failed on $table: parent key for $parent_table could not be inspected";
        }

        $from_columns = [];
        $values = [];
        $skip = false;
        foreach ($group as $i => $part) {
            $from = (string)($part['from'] ?? '');
            if ($from === '' || !array_key_exists($from, $row) || $row[$from] === null) {
                $skip = true;
                break;
            }
            $from_columns[] = $from;
            $values[] = $row[$from];
            if (!isset($parent_columns[$i])) {
                return "FOREIGN KEY constraint failed on $table: parent key for $parent_table could not be inspected";
            }
        }
        if ($skip) {
            continue;
        }

        $clauses = [];
        foreach ($parent_columns as $parent_column) {
            $clauses[] = cow_merge_quote_ident($parent_column) . ' = ?';
        }
        $parent_lookup_sql = 'SELECT 1 FROM ' . cow_merge_quote_ident($parent_table) . ' WHERE ' . implode(' AND ', $clauses) . ' LIMIT 1';
        $stmt = cow_merge_prepare_foreign_key_lookup(
            $target,
            $parent_lookup_sql,
            "failed to prepare foreign key parent lookup on $parent_table",
            "FOREIGN KEY constraint failed on $table: parent table $parent_table could not be inspected"
        );
        if (is_string($stmt)) {
            return $stmt;
        }
        foreach ($values as $i => $value) {
            cow_merge_bind($stmt, $i + 1, $value);
        }
        $res = cow_merge_execute_checked($stmt, $target, "failed to inspect foreign key parent lookup on $parent_table");
        $parent_exists = (bool)$res->fetchArray(SQLITE3_NUM);
        cow_merge_result_finalize_checked($res, "failed to finalize foreign key parent lookup on $parent_table");
        if (!$parent_exists) {
            if ($parent_table === $table && cow_merge_row_satisfies_own_foreign_key($row, $from_columns, $parent_columns, $values)) {
                continue;
            }
            return 'FOREIGN KEY constraint failed on ' . $table . '(' . implode(', ', $from_columns) . ') referencing ' . $parent_table . '(' . implode(', ', $parent_columns) . ')';
        }
    }
    return null;
}

function cow_merge_collect_required_foreign_key_parent_materializations(
    SQLite3 $base,
    SQLite3 $source,
    SQLite3 $target,
    SQLite3 $meta,
    int $run_id,
    string $source_branch,
    string $target_branch,
    string $table,
    array $source_row,
    array &$materializations,
    array &$operations,
    array &$visiting
): bool {
    foreach (cow_merge_foreign_key_groups($target, $table) as $group) {
        $parent_table = (string)($group[0]['table'] ?? '');
        if ($parent_table === '') {
            continue;
        }
        $parent_columns = cow_merge_foreign_key_parent_columns($target, $parent_table, $group);
        if ($parent_columns === null) {
            return false;
        }

        $from_columns = [];
        $values = [];
        $skip = false;
        foreach ($group as $i => $part) {
            $from = (string)($part['from'] ?? '');
            if ($from === '' || !isset($parent_columns[$i])) {
                return false;
            }
            if (!array_key_exists($from, $source_row) || $source_row[$from] === null) {
                $skip = true;
                break;
            }
            $from_columns[] = $from;
            $values[] = $source_row[$from];
        }
        if ($skip || !$from_columns || in_array(null, $values, true)) {
            continue;
        }
        if (cow_merge_row_exists_by_values($target, $parent_table, $parent_columns, $values)) {
            continue;
        }

        [$parent_pk_cols, $parent_base_rows, $parent_source_rows, $parent_target_rows] = cow_merge_load_rows_for_fk_delete(
            $base,
            $source,
            $target,
            $meta,
            $run_id,
            $source_branch,
            $target_branch,
            $parent_table
        );
        $source_match = cow_merge_find_row_entry_by_values($parent_source_rows, $parent_columns, $values);
        if ($source_match === null) {
            return false;
        }
        [$parent_key, $parent_source_entry] = $source_match;
        if (($parent_base_rows[$parent_key]['row'] ?? null) !== null || ($parent_target_rows[$parent_key]['row'] ?? null) !== null) {
            continue;
        }

        $parent_source_row = $parent_source_entry['row'] ?? null;
        $parent_identity = $parent_source_entry['identity'] ?? null;
        if (!is_array($parent_source_row) || !is_array($parent_identity)) {
            return false;
        }

        $materialize_key = $parent_table . "\0" . $parent_key;
        if (isset($materializations[$materialize_key])) {
            continue;
        }
        $visit_key = "materialize\0" . $materialize_key;
        if (isset($visiting[$visit_key])) {
            continue;
        }
        $visiting[$visit_key] = true;
        try {
            if (!cow_merge_collect_required_foreign_key_parent_materializations(
                $base,
                $source,
                $target,
                $meta,
                $run_id,
                $source_branch,
                $target_branch,
                $parent_table,
                $parent_source_row,
                $materializations,
                $operations,
                $visiting
            )) {
                return false;
            }
        } finally {
            unset($visiting[$visit_key]);
        }

        $parent_columns_all = cow_merge_all_columns(
            cow_merge_table_columns($target, $parent_table),
            cow_merge_table_columns($source, $parent_table),
            cow_merge_table_columns($base, $parent_table),
            array_keys($parent_source_row)
        );
        $materializations[$materialize_key] = [
            'table' => $parent_table,
            'key' => $parent_key,
            'pk_cols' => $parent_pk_cols,
            'identity' => $parent_identity,
            'row' => $parent_source_row,
            'rowid' => $parent_source_entry['rowid'] ?? null,
            'columns' => $parent_columns_all,
        ];
        $operations[] = ['type' => 'materialize', 'key' => $materialize_key];
    }

    return true;
}

function cow_merge_load_rows_for_fk_delete(
    SQLite3 $base,
    SQLite3 $source,
    SQLite3 $target,
    SQLite3 $meta,
    int $run_id,
    string $source_branch,
    string $target_branch,
    string $table
): array {
    $pk_cols = cow_merge_pk_cols($target, $table);
    if (!$pk_cols) {
        $pk_cols = cow_merge_pk_cols($source, $table);
    }
    if (!$pk_cols) {
        $pk_cols = cow_merge_pk_cols($base, $table);
    }

    if ($pk_cols) {
        return [
            $pk_cols,
            cow_merge_load_rows($base, $table, $pk_cols),
            cow_merge_load_rows($source, $table, $pk_cols),
            cow_merge_load_rows($target, $table, $pk_cols),
        ];
    }

    return array_merge(
        [[]],
        cow_merge_load_keyless_sidecar_rows(
            $base,
            $source,
            $target,
            $meta,
            $run_id,
            $source_branch,
            $target_branch,
            $table
        )
    );
}

function cow_merge_collect_source_deleted_foreign_key_children(
    SQLite3 $base,
    SQLite3 $source,
    SQLite3 $target,
    SQLite3 $meta,
    int $run_id,
    string $source_branch,
    string $target_branch,
    string $table,
    array $identity,
    array $pk_cols,
    array $row,
    array &$deletes,
    array &$updates,
    array &$materializations,
    array &$operations,
    array &$visiting
): bool {
    $visit_key = $table . "\0" . cow_merge_identity_json($identity);
    if (isset($visiting[$visit_key])) {
        return false;
    }
    $visiting[$visit_key] = true;

    try {
        foreach (array_keys(cow_merge_table_sql_map($target)) as $child_table) {
            foreach (cow_merge_foreign_key_groups($target, $child_table) as $group) {
                $parent_table = (string)($group[0]['table'] ?? '');
                if ($parent_table !== $table) {
                    continue;
                }
                $parent_columns = cow_merge_foreign_key_parent_columns($target, $table, $group);
                if ($parent_columns === null) {
                    return false;
                }

                $from_columns = [];
                $values = [];
                foreach ($group as $i => $part) {
                    $from = (string)($part['from'] ?? '');
                    if ($from === '' || !isset($parent_columns[$i])) {
                        return false;
                    }
                    $parent_column = $parent_columns[$i];
                    if (!array_key_exists($parent_column, $row)) {
                        return false;
                    }
                    $from_columns[] = $from;
                    $values[] = $row[$parent_column];
                }
                if (!$from_columns) {
                    continue;
                }
                if (in_array(null, $values, true)) {
                    continue;
                }

                [$child_pk_cols, $base_rows, $source_rows, $target_rows] = cow_merge_load_rows_for_fk_delete(
                    $base,
                    $source,
                    $target,
                    $meta,
                    $run_id,
                    $source_branch,
                    $target_branch,
                    $child_table
                );
                $child_columns = cow_merge_all_columns(
                    cow_merge_table_columns($target, $child_table),
                    cow_merge_table_columns($source, $child_table),
                    cow_merge_table_columns($base, $child_table)
                );

                foreach ($target_rows as $child_key => $target_entry) {
                    if ($child_table === $table && $child_key === cow_merge_identity_json($identity)) {
                        continue;
                    }
                    $target_row = $target_entry['row'] ?? null;
                    if ($target_row === null || !cow_merge_row_matches_values($target_row, $from_columns, $values)) {
                        continue;
                    }

                    $base_entry = $base_rows[$child_key] ?? null;
                    $source_entry = $source_rows[$child_key] ?? null;
                    $base_row = $base_entry['row'] ?? null;
                    $source_row = $source_entry['row'] ?? null;
                    $row_columns = cow_merge_all_columns($child_columns, array_keys($base_row ?? []), array_keys($target_row));
                    if ($base_row === null || !cow_merge_row_values_equal($target_row, $base_row, $row_columns)) {
                        return false;
                    }

                    $child_identity = $target_entry['identity'] ?? null;
                    $child_where_identity = cow_merge_entry_where_identity($target_entry, $child_pk_cols);
                    if (!is_array($child_identity) || $child_where_identity === null) {
                        return false;
                    }

                    if ($source_row === null) {
                        if (!cow_merge_collect_source_deleted_foreign_key_children(
                            $base,
                            $source,
                            $target,
                            $meta,
                            $run_id,
                            $source_branch,
                            $target_branch,
                            $child_table,
                            $child_identity,
                            $child_pk_cols,
                            $target_row,
                            $deletes,
                            $updates,
                            $materializations,
                            $operations,
                            $visiting
                        )) {
                            return false;
                        }

                        $delete_key = $child_table . "\0" . $child_key;
                        if (!isset($deletes[$delete_key])) {
                            $deletes[$delete_key] = [
                                'table' => $child_table,
                                'where_identity' => $child_where_identity,
                                'pk_cols' => $child_pk_cols,
                                'rowid' => $target_entry['rowid'] ?? null,
                            ];
                            $operations[] = ['type' => 'delete', 'key' => $delete_key];
                        }
                        continue;
                    }

                    if (cow_merge_row_matches_values($source_row, $from_columns, $values)) {
                        return false;
                    }

                    $update_key = $child_table . "\0" . $child_key;
                    if (!isset($updates[$update_key])) {
                        if (!cow_merge_collect_required_foreign_key_parent_materializations(
                            $base,
                            $source,
                            $target,
                            $meta,
                            $run_id,
                            $source_branch,
                            $target_branch,
                            $child_table,
                            $source_row,
                            $materializations,
                            $operations,
                            $visiting
                        )) {
                            return false;
                        }
                        $source_identity = $source_entry['identity'] ?? $child_identity;
                        if (!is_array($source_identity)) {
                            return false;
                        }
                        $updates[$update_key] = [
                            'table' => $child_table,
                            'where_identity' => $child_where_identity,
                            'pk_cols' => $child_pk_cols,
                            'identity' => $source_identity,
                            'row' => $source_row,
                            'columns' => $row_columns,
                            'rowid' => $target_entry['rowid'] ?? null,
                        ];
                        $operations[] = ['type' => 'update', 'key' => $update_key];
                    }

                    if (!cow_merge_collect_source_updated_foreign_key_children(
                        $base,
                        $source,
                        $target,
                        $meta,
                        $run_id,
                        $source_branch,
                        $target_branch,
                        $child_table,
                        $child_identity,
                        $child_pk_cols,
                        $base_row,
                        $source_row,
                        $target_row,
                        $deletes,
                        $updates,
                        $materializations,
                        $operations,
                        $visiting
                    )) {
                        return false;
                    }
                }
            }
        }
    } finally {
        unset($visiting[$visit_key]);
    }

    return true;
}

function cow_merge_collect_source_updated_foreign_key_children(
    SQLite3 $base,
    SQLite3 $source,
    SQLite3 $target,
    SQLite3 $meta,
    int $run_id,
    string $source_branch,
    string $target_branch,
    string $table,
    array $identity,
    array $pk_cols,
    array $base_row,
    array $source_row,
    array $target_row,
    array &$deletes,
    array &$updates,
    array &$materializations,
    array &$operations,
    array &$visiting
): bool {
    $visit_key = "update\0" . $table . "\0" . cow_merge_identity_json($identity);
    if (isset($visiting[$visit_key])) {
        return false;
    }
    $visiting[$visit_key] = true;

    try {
        foreach (array_keys(cow_merge_table_sql_map($target)) as $child_table) {
            foreach (cow_merge_foreign_key_groups($target, $child_table) as $group) {
                $parent_table = (string)($group[0]['table'] ?? '');
                if ($parent_table !== $table) {
                    continue;
                }
                $parent_columns = cow_merge_foreign_key_parent_columns($target, $table, $group);
                if ($parent_columns === null) {
                    return false;
                }

                $from_columns = [];
                $old_values = [];
                foreach ($group as $i => $part) {
                    $from = (string)($part['from'] ?? '');
                    if ($from === '' || !isset($parent_columns[$i])) {
                        return false;
                    }
                    $parent_column = $parent_columns[$i];
                    if (!array_key_exists($parent_column, $target_row)) {
                        return false;
                    }
                    $from_columns[] = $from;
                    $old_values[] = $target_row[$parent_column];
                }
                if (!$from_columns || in_array(null, $old_values, true)) {
                    continue;
                }
                if (cow_merge_row_matches_values($source_row, $parent_columns, $old_values)) {
                    continue;
                }

                [$child_pk_cols, $base_rows, $source_rows, $target_rows] = cow_merge_load_rows_for_fk_delete(
                    $base,
                    $source,
                    $target,
                    $meta,
                    $run_id,
                    $source_branch,
                    $target_branch,
                    $child_table
                );
                $child_columns = cow_merge_all_columns(
                    cow_merge_table_columns($target, $child_table),
                    cow_merge_table_columns($source, $child_table),
                    cow_merge_table_columns($base, $child_table)
                );

                foreach ($target_rows as $child_key => $target_entry) {
                    if ($child_table === $table && $child_key === cow_merge_identity_json($identity)) {
                        continue;
                    }
                    $dependent_target_row = $target_entry['row'] ?? null;
                    if ($dependent_target_row === null || !cow_merge_row_matches_values($dependent_target_row, $from_columns, $old_values)) {
                        continue;
                    }

                    $base_entry = $base_rows[$child_key] ?? null;
                    $source_entry = $source_rows[$child_key] ?? null;
                    $dependent_base_row = $base_entry['row'] ?? null;
                    $dependent_source_row = $source_entry['row'] ?? null;
                    $row_columns = cow_merge_all_columns($child_columns, array_keys($dependent_base_row ?? []), array_keys($dependent_target_row));
                    if ($dependent_base_row === null || !cow_merge_row_values_equal($dependent_target_row, $dependent_base_row, $row_columns)) {
                        return false;
                    }

                    $child_identity = $target_entry['identity'] ?? null;
                    $child_where_identity = cow_merge_entry_where_identity($target_entry, $child_pk_cols);
                    if (!is_array($child_identity) || $child_where_identity === null) {
                        return false;
                    }

                    if ($dependent_source_row === null) {
                        if (!cow_merge_collect_source_deleted_foreign_key_children(
                            $base,
                            $source,
                            $target,
                            $meta,
                            $run_id,
                            $source_branch,
                            $target_branch,
                            $child_table,
                            $child_identity,
                            $child_pk_cols,
                            $dependent_target_row,
                            $deletes,
                            $updates,
                            $materializations,
                            $operations,
                            $visiting
                        )) {
                            return false;
                        }

                        $delete_key = $child_table . "\0" . $child_key;
                        if (!isset($deletes[$delete_key])) {
                            $deletes[$delete_key] = [
                                'table' => $child_table,
                                'where_identity' => $child_where_identity,
                                'pk_cols' => $child_pk_cols,
                                'rowid' => $target_entry['rowid'] ?? null,
                            ];
                            $operations[] = ['type' => 'delete', 'key' => $delete_key];
                        }
                        continue;
                    }

                    if (cow_merge_row_matches_values($dependent_source_row, $from_columns, $old_values)) {
                        return false;
                    }

                    $update_key = $child_table . "\0" . $child_key;
                    if (!isset($updates[$update_key])) {
                        if (!cow_merge_collect_required_foreign_key_parent_materializations(
                            $base,
                            $source,
                            $target,
                            $meta,
                            $run_id,
                            $source_branch,
                            $target_branch,
                            $child_table,
                            $dependent_source_row,
                            $materializations,
                            $operations,
                            $visiting
                        )) {
                            return false;
                        }
                        $source_identity = $source_entry['identity'] ?? $child_identity;
                        if (!is_array($source_identity)) {
                            return false;
                        }
                        $updates[$update_key] = [
                            'table' => $child_table,
                            'where_identity' => $child_where_identity,
                            'pk_cols' => $child_pk_cols,
                            'identity' => $source_identity,
                            'row' => $dependent_source_row,
                            'columns' => $row_columns,
                            'rowid' => $target_entry['rowid'] ?? null,
                        ];
                        $operations[] = ['type' => 'update', 'key' => $update_key];
                    }

                    if (!cow_merge_collect_source_updated_foreign_key_children(
                        $base,
                        $source,
                        $target,
                        $meta,
                        $run_id,
                        $source_branch,
                        $target_branch,
                        $child_table,
                        $child_identity,
                        $child_pk_cols,
                        $dependent_base_row,
                        $dependent_source_row,
                        $dependent_target_row,
                        $deletes,
                        $updates,
                        $materializations,
                        $operations,
                        $visiting
                    )) {
                        return false;
                    }
                }
            }
        }
    } finally {
        unset($visiting[$visit_key]);
    }

    return true;
}

function cow_merge_foreign_key_delete_error(SQLite3 $target, string $table, array $identity, array $pk_cols, array $row): ?string {
    foreach (array_keys(cow_merge_table_sql_map($target)) as $child_table) {
        foreach (cow_merge_foreign_key_groups($target, $child_table) as $group) {
            $parent_table = (string)($group[0]['table'] ?? '');
            if ($parent_table !== $table) {
                continue;
            }
            $parent_columns = cow_merge_foreign_key_parent_columns($target, $table, $group);
            if ($parent_columns === null) {
                return "FOREIGN KEY constraint failed on $table delete: parent key could not be inspected";
            }

            $from_columns = [];
            $values = [];
            foreach ($group as $i => $part) {
                $from = (string)($part['from'] ?? '');
                if ($from === '' || !isset($parent_columns[$i])) {
                    return "FOREIGN KEY constraint failed on $table delete: child key for $child_table could not be inspected";
                }
                $parent_column = $parent_columns[$i];
                if (!array_key_exists($parent_column, $row)) {
                    return "FOREIGN KEY constraint failed on $table delete: parent column $parent_column could not be inspected";
                }
                $from_columns[] = $from;
                $values[] = $row[$parent_column];
            }
            if (!$from_columns) {
                continue;
            }

            $clauses = [];
            foreach ($from_columns as $from_column) {
                $clauses[] = cow_merge_quote_ident($from_column) . ' = ?';
            }
            if ($child_table === $table) {
                $exclude_values = [];
                $exclude = cow_merge_where_clause($target, $table, $identity, $pk_cols, $exclude_values);
                $clauses[] = 'NOT (' . $exclude . ')';
                $values = array_merge($values, $exclude_values);
            }
            $child_lookup_sql = 'SELECT 1 FROM ' . cow_merge_quote_ident($child_table) . ' WHERE ' . implode(' AND ', $clauses) . ' LIMIT 1';
            $stmt = cow_merge_prepare_foreign_key_lookup(
                $target,
                $child_lookup_sql,
                "failed to prepare foreign key child lookup on $child_table",
                "FOREIGN KEY constraint failed on $table delete: child table $child_table could not be inspected"
            );
            if (is_string($stmt)) {
                return $stmt;
            }
            foreach ($values as $i => $value) {
                cow_merge_bind($stmt, $i + 1, $value);
            }
            $res = cow_merge_execute_checked($stmt, $target, "failed to inspect foreign key child lookup on $child_table");
            $child_exists = (bool)$res->fetchArray(SQLITE3_NUM);
            cow_merge_result_finalize_checked($res, "failed to finalize foreign key child lookup on $child_table");
            if ($child_exists) {
                return 'FOREIGN KEY constraint failed on ' . $table . ' delete: referenced by ' . $child_table . '(' . implode(', ', $from_columns) . ')';
            }
        }
    }
    return null;
}

function cow_merge_is_constraint_error(SQLite3 $db): bool {
    return (int)$db->lastErrorCode() === 19;
}

function cow_merge_constraint_error(SQLite3 $db): string {
    $message = trim($db->lastErrorMsg());
    return $message === '' ? 'SQLite constraint failed' : $message;
}

function cow_merge_source_apply_postcondition_error(string $table, ?array $actual, array $expected, array $columns): ?string {
    if ($actual === null) {
        return "target triggers removed the applied source row from $table";
    }
    $compare_columns = cow_merge_all_columns($columns, array_keys($expected), array_keys($actual));
    if (cow_merge_row_values_equal($actual, $expected, $compare_columns)) {
        return null;
    }
    return "target triggers changed the applied source row in $table";
}

function cow_merge_savepoint_missing_after_transaction_end(RuntimeException $e): bool {
    return str_contains($e->getMessage(), 'no such savepoint');
}

function cow_merge_try_insert_row_preserving_payload(
    SQLite3 $target,
    string $table,
    array $row,
    array $columns,
    array $identity,
    array $pk_cols
): array {
    static $savepoint_counter = 0;
    $savepoint = 'cow_merge_insert_payload_' . (++$savepoint_counter);
    cow_merge_exec_checked($target, 'SAVEPOINT ' . $savepoint, 'failed to start source insert payload savepoint');
    try {
        $result = cow_merge_try_insert_row($target, $table, $row, $columns);
        if (!($result['ok'] ?? false)) {
            $cleanup = cow_merge_rollback_release_savepoint_checked($target, $savepoint, 'source insert payload');
            if ($cleanup !== null) {
                if (cow_merge_savepoint_missing_after_transaction_end($cleanup)) {
                    return $result;
                }
                throw $cleanup;
            }
            return $result;
        }

        if ($pk_cols) {
            $actual = cow_merge_select_current_row($target, $table, $identity, $pk_cols);
        } else {
            $rowid = (int)($result['rowid'] ?? 0);
            $entry = $rowid > 0 ? cow_merge_load_keyless_physical_row($target, $table, $rowid) : null;
            $actual = $entry['row'] ?? null;
        }
        $postcondition_error = cow_merge_source_apply_postcondition_error($table, $actual, $row, $columns);
        if ($postcondition_error !== null) {
            $cleanup = cow_merge_rollback_release_savepoint_checked($target, $savepoint, 'source insert payload');
            if ($cleanup !== null) {
                throw $cleanup;
            }
            return ['ok' => false, 'rowid' => null, 'error' => $postcondition_error];
        }

        cow_merge_release_savepoint_checked($target, $savepoint, 'source insert payload');
        return $result;
    } catch (Throwable $e) {
        $cleanup = cow_merge_rollback_release_savepoint_checked($target, $savepoint, 'source insert payload', $e);
        if ($cleanup !== null && !cow_merge_savepoint_missing_after_transaction_end($cleanup)) {
            throw $cleanup;
        }
        throw $e;
    }
}

function cow_merge_try_insert_row_with_rowid_preserving_payload(
    SQLite3 $target,
    string $table,
    int $rowid,
    array $row,
    array $columns
): array {
    static $savepoint_counter = 0;
    $savepoint = 'cow_merge_insert_rowid_payload_' . (++$savepoint_counter);
    cow_merge_exec_checked($target, 'SAVEPOINT ' . $savepoint, 'failed to start source rowid insert payload savepoint');
    try {
        $result = cow_merge_try_insert_row_with_rowid($target, $table, $rowid, $row, $columns);
        if (!($result['ok'] ?? false)) {
            $cleanup = cow_merge_rollback_release_savepoint_checked($target, $savepoint, 'source rowid insert payload');
            if ($cleanup !== null) {
                if (cow_merge_savepoint_missing_after_transaction_end($cleanup)) {
                    return $result;
                }
                throw $cleanup;
            }
            return $result;
        }

        $entry = cow_merge_load_keyless_physical_row($target, $table, $rowid);
        $actual = $entry['row'] ?? null;
        $postcondition_error = cow_merge_source_apply_postcondition_error($table, $actual, $row, $columns);
        if ($postcondition_error !== null) {
            $cleanup = cow_merge_rollback_release_savepoint_checked($target, $savepoint, 'source rowid insert payload');
            if ($cleanup !== null) {
                throw $cleanup;
            }
            return ['ok' => false, 'rowid' => null, 'error' => $postcondition_error];
        }

        cow_merge_release_savepoint_checked($target, $savepoint, 'source rowid insert payload');
        return $result;
    } catch (Throwable $e) {
        $cleanup = cow_merge_rollback_release_savepoint_checked($target, $savepoint, 'source rowid insert payload', $e);
        if ($cleanup !== null && !cow_merge_savepoint_missing_after_transaction_end($cleanup)) {
            throw $cleanup;
        }
        throw $e;
    }
}

function cow_merge_prepare_foreign_key_lookup(
    SQLite3 $db,
    string $sql,
    string $message,
    string $missing_schema_message
) {
    cow_merge_test_hook('before_sqlite_prepare', $db, $sql, $message);
    $stmt = @$db->prepare($sql);
    if ($stmt) {
        return $stmt;
    }
    if (str_contains(strtolower($db->lastErrorMsg()), 'no such table')) {
        return $missing_schema_message;
    }
    throw new RuntimeException($message . ': ' . $db->lastErrorMsg());
}

function cow_merge_try_insert_row(SQLite3 $target, string $table, array $row, array $columns): array {
    $columns = array_values(array_filter($columns, fn($col) => array_key_exists($col, $row)));
    if (!$columns) {
        return ['ok' => true, 'rowid' => 0, 'error' => null];
    }
    $foreign_key_error = cow_merge_foreign_key_error($target, $table, $row);
    if ($foreign_key_error !== null) {
        return ['ok' => false, 'rowid' => null, 'error' => $foreign_key_error];
    }
    $sql = 'INSERT INTO ' . cow_merge_quote_ident($table) . ' (' .
        implode(', ', array_map('cow_merge_quote_ident', $columns)) . ') VALUES (' .
        implode(', ', array_fill(0, count($columns), '?')) . ')';
    $stmt = cow_merge_prepare_checked($target, $sql, "failed to prepare insert into $table");
    foreach ($columns as $i => $col) {
        cow_merge_bind($stmt, $i + 1, $row[$col] ?? null);
    }
    $execute_result = cow_merge_execute_constraint_mutation(
        $stmt,
        $target,
        "failed to insert into $table",
        "failed to finalize insert into $table"
    );
    if (!($execute_result['ok'] ?? false)) {
        return ['ok' => false, 'rowid' => null, 'error' => $execute_result['error'] ?? 'SQLite constraint failed'];
    }
    return ['ok' => true, 'rowid' => (int)$target->lastInsertRowID(), 'error' => null];
}

function cow_merge_insert_row(SQLite3 $target, string $table, array $row, array $columns): int {
    $result = cow_merge_try_insert_row($target, $table, $row, $columns);
    if (!($result['ok'] ?? false)) {
        throw new RuntimeException("failed to insert into $table: " . (string)($result['error'] ?? 'SQLite constraint failed'));
    }
    return (int)($result['rowid'] ?? 0);
}

function cow_merge_try_insert_row_with_rowid(SQLite3 $target, string $table, int $rowid, array $row, array $columns): array {
    $columns = array_values(array_filter($columns, fn($col) => array_key_exists($col, $row)));
    $foreign_key_error = cow_merge_foreign_key_error($target, $table, $row);
    if ($foreign_key_error !== null) {
        return ['ok' => false, 'rowid' => null, 'error' => $foreign_key_error];
    }
    $quoted_columns = array_merge([cow_merge_hidden_rowid_selector($target, $table)], array_map('cow_merge_quote_ident', $columns));
    $sql = 'INSERT INTO ' . cow_merge_quote_ident($table) . ' (' .
        implode(', ', $quoted_columns) . ') VALUES (' .
        implode(', ', array_fill(0, count($quoted_columns), '?')) . ')';
    $stmt = cow_merge_prepare_checked($target, $sql, "failed to prepare rowid insert into $table");
    cow_merge_bind($stmt, 1, $rowid);
    foreach ($columns as $i => $col) {
        cow_merge_bind($stmt, $i + 2, $row[$col] ?? null);
    }
    $execute_result = cow_merge_execute_constraint_mutation(
        $stmt,
        $target,
        "failed to insert rowid into $table",
        "failed to finalize rowid insert into $table"
    );
    if (!($execute_result['ok'] ?? false)) {
        return ['ok' => false, 'rowid' => null, 'error' => $execute_result['error'] ?? 'SQLite constraint failed'];
    }
    return ['ok' => true, 'rowid' => (int)$target->lastInsertRowID(), 'error' => null];
}

function cow_merge_insert_row_with_rowid(SQLite3 $target, string $table, int $rowid, array $row, array $columns): int {
    $result = cow_merge_try_insert_row_with_rowid($target, $table, $rowid, $row, $columns);
    if (!($result['ok'] ?? false)) {
        throw new RuntimeException("failed to insert rowid into $table: " . (string)($result['error'] ?? 'SQLite constraint failed'));
    }
    return (int)($result['rowid'] ?? 0);
}

function cow_merge_partial_index_where(string $sql): ?string {
    $len = strlen($sql);
    $quote = null;
    $depth = 0;
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($quote !== null) {
            if ($quote === '[' && $ch === ']') {
                $quote = null;
            } elseif ($ch === $quote) {
                if ($quote === "'" && $i + 1 < $len && $sql[$i + 1] === "'") {
                    $i++;
                    continue;
                }
                $quote = null;
            }
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`' || $ch === '[') {
            $quote = $ch;
            continue;
        }
        if ($ch === '(') {
            $depth++;
            continue;
        }
        if ($ch === ')' && $depth > 0) {
            $depth--;
            continue;
        }
        if ($depth !== 0 || strncasecmp(substr($sql, $i, 5), 'WHERE', 5) !== 0) {
            continue;
        }
        $before = $i === 0 ? ' ' : $sql[$i - 1];
        $after = $i + 5 >= $len ? ' ' : $sql[$i + 5];
        if (preg_match('/[A-Za-z0-9_]/', $before) || preg_match('/[A-Za-z0-9_]/', $after)) {
            continue;
        }
        $where = trim(substr($sql, $i + 5));
        $where = rtrim($where, " \t\r\n;");
        return $where === '' ? null : $where;
    }
    return null;
}

function cow_merge_index_sql_terms(string $sql): ?array {
    $len = strlen($sql);
    $quote = null;
    $seen_on = false;
    $start = null;
    $depth = 0;
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($quote !== null) {
            if ($quote === '[' && $ch === ']') {
                $quote = null;
            } elseif ($ch === $quote) {
                if (($quote === "'" || $quote === '"') && $i + 1 < $len && $sql[$i + 1] === $quote) {
                    $i++;
                    continue;
                }
                $quote = null;
            }
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`' || $ch === '[') {
            $quote = $ch;
            continue;
        }
        if (!$seen_on && strncasecmp(substr($sql, $i, 2), 'ON', 2) === 0) {
            $before = $i === 0 ? ' ' : $sql[$i - 1];
            $after = $i + 2 >= $len ? ' ' : $sql[$i + 2];
            if (!preg_match('/[A-Za-z0-9_]/', $before) && !preg_match('/[A-Za-z0-9_]/', $after)) {
                $seen_on = true;
                $i++;
                continue;
            }
        }
        if ($seen_on && $ch === '(') {
            $start = $i + 1;
            $depth = 1;
            break;
        }
    }
    if ($start === null) {
        return null;
    }

    $terms = [];
    $term_start = $start;
    $quote = null;
    for ($i = $start; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($quote !== null) {
            if ($quote === '[' && $ch === ']') {
                $quote = null;
            } elseif ($ch === $quote) {
                if (($quote === "'" || $quote === '"') && $i + 1 < $len && $sql[$i + 1] === $quote) {
                    $i++;
                    continue;
                }
                $quote = null;
            }
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`' || $ch === '[') {
            $quote = $ch;
            continue;
        }
        if ($ch === '(') {
            $depth++;
            continue;
        }
        if ($ch === ')') {
            $depth--;
            if ($depth === 0) {
                $term = cow_merge_normalize_index_term_sql(substr($sql, $term_start, $i - $term_start));
                if ($term === '') {
                    return null;
                }
                $terms[] = $term;
                return $terms;
            }
            continue;
        }
        if ($ch === ',' && $depth === 1) {
            $term = cow_merge_normalize_index_term_sql(substr($sql, $term_start, $i - $term_start));
            if ($term === '') {
                return null;
            }
            $terms[] = $term;
            $term_start = $i + 1;
        }
    }
    return null;
}

function cow_merge_normalize_index_term_sql(string $term): string {
    $term = trim($term);
    return preg_replace('/\s+(ASC|DESC)\s*$/i', '', $term) ?? $term;
}

function cow_merge_row_expression_value(SQLite3 $db, array $row, string $expression): array {
    $columns = array_keys($row);
    if (!$columns) {
        return ['ok' => false, 'value' => null];
    }
    $quoted_columns = implode(', ', array_map('cow_merge_quote_ident', $columns));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $sql = 'WITH __forkpress_merge_row (' . $quoted_columns . ') AS (SELECT ' . $placeholders . ') ' .
        'SELECT ' . $expression . ' AS __forkpress_merge_value FROM __forkpress_merge_row LIMIT 1';
    cow_merge_test_hook('before_sqlite_prepare', $db, $sql, "failed to prepare row expression value for $expression");
    $stmt = @$db->prepare($sql);
    if (!$stmt) {
        return ['ok' => false, 'value' => null];
    }
    foreach ($columns as $i => $column) {
        cow_merge_bind($stmt, $i + 1, $row[$column] ?? null);
    }
    cow_merge_test_hook('before_sqlite_statement_execute', $db, "failed to evaluate row expression value for $expression");
    $res = @$stmt->execute();
    if (!$res) {
        return ['ok' => false, 'value' => null];
    }
    try {
        $value = $res->fetchArray(SQLITE3_ASSOC);
        if (!is_array($value) || !array_key_exists('__forkpress_merge_value', $value)) {
            return ['ok' => false, 'value' => null];
        }
        return ['ok' => true, 'value' => $value['__forkpress_merge_value']];
    } finally {
        cow_merge_result_finalize_checked($res, "failed to finalize row expression value for $expression");
    }
}

function cow_merge_row_matches_partial_index_where(SQLite3 $db, array $row, string $where): bool {
    $columns = array_keys($row);
    if (!$columns) {
        return false;
    }
    $quoted_columns = implode(', ', array_map('cow_merge_quote_ident', $columns));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $sql = 'WITH __forkpress_merge_row (' . $quoted_columns . ') AS (SELECT ' . $placeholders . ') ' .
        'SELECT 1 FROM __forkpress_merge_row WHERE ' . $where . ' LIMIT 1';
    cow_merge_test_hook('before_sqlite_prepare', $db, $sql, "failed to prepare partial index predicate for $where");
    $stmt = @$db->prepare($sql);
    if (!$stmt) {
        return false;
    }
    foreach ($columns as $i => $column) {
        cow_merge_bind($stmt, $i + 1, $row[$column] ?? null);
    }
    cow_merge_test_hook('before_sqlite_statement_execute', $db, "failed to evaluate partial index predicate for $where");
    $res = @$stmt->execute();
    if (!$res) {
        return false;
    }
    try {
        return (bool)$res->fetchArray(SQLITE3_NUM);
    } finally {
        cow_merge_result_finalize_checked($res, "failed to finalize partial index predicate for $where");
    }
}

function cow_merge_unique_index_terms(SQLite3 $db, string $name): ?array {
    $sql = cow_merge_index_sql($db, $name);
    $sql_terms = is_string($sql) ? cow_merge_index_sql_terms($sql) : null;
    $info = cow_merge_query_checked(
        $db,
        "PRAGMA index_xinfo('" . SQLite3::escapeString($name) . "')",
        "failed to inspect unique index terms for $name"
    );
    $terms = [];
    try {
        while ($column = $info->fetchArray(SQLITE3_ASSOC)) {
            if ((int)($column['key'] ?? 1) !== 1) {
                continue;
            }
            $seqno = (int)($column['seqno'] ?? count($terms));
            $cid = (int)($column['cid'] ?? -1);
            $column_name = $column['name'] ?? null;
            $collation = is_string($column['coll'] ?? null) && (string)$column['coll'] !== ''
                ? (string)$column['coll']
                : 'BINARY';
            if ($cid >= 0 && is_string($column_name) && $column_name !== '') {
                $terms[$seqno] = ['type' => 'column', 'name' => $column_name, 'collation' => $collation];
                continue;
            }
            if ($cid === -2 && is_array($sql_terms) && isset($sql_terms[$seqno])) {
                $terms[$seqno] = ['type' => 'expression', 'sql' => $sql_terms[$seqno], 'collation' => $collation];
                continue;
            }
            return null;
        }
    } finally {
        cow_merge_result_finalize_checked($info, "failed to finalize unique index term inspection for $name");
    }
    if (!$terms) {
        return null;
    }
    ksort($terms);
    return array_values($terms);
}

function cow_merge_unique_indexes(SQLite3 $db, string $table): array {
    $indexes = [];
    $res = cow_merge_query_checked(
        $db,
        'PRAGMA index_list(' . cow_merge_quote_ident($table) . ')',
        "failed to inspect unique indexes for $table"
    );
    try {
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            if ((int)($row['unique'] ?? 0) !== 1) {
                continue;
            }
            $name = (string)($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $where = null;
            if ((int)($row['partial'] ?? 0) === 1) {
                $sql = cow_merge_index_sql($db, $name);
                $where = is_string($sql) ? cow_merge_partial_index_where($sql) : null;
                if ($where === null) {
                    continue;
                }
            }
            $terms = cow_merge_unique_index_terms($db, $name);
            if (is_array($terms)) {
                $indexes[] = ['name' => $name, 'terms' => $terms, 'where' => $where];
            }
        }
    } finally {
        cow_merge_result_finalize_checked($res, "failed to finalize unique index inspection for $table");
    }
    return $indexes;
}

function cow_merge_unique_collision_matches_identity(
    array $row,
    ?int $rowid,
    array $identity,
    array $pk_cols,
    ?int $identity_rowid
): bool {
    if ($pk_cols) {
        foreach ($pk_cols as $pk_col) {
            if (!array_key_exists($pk_col, $identity) || !array_key_exists($pk_col, $row)) {
                return false;
            }
            if (!cow_merge_values_equal($row[$pk_col], $identity[$pk_col])) {
                return false;
            }
        }
        return true;
    }
    return $identity_rowid !== null && $rowid !== null && $rowid === $identity_rowid;
}

function cow_merge_find_unique_collision(
    SQLite3 $target,
    string $table,
    array $source_row,
    bool $include_rowid = false,
    ?array $exclude_identity = null,
    array $exclude_pk_cols = [],
    ?int $exclude_rowid = null
): ?array {
    $needs_rowid = $include_rowid || ($exclude_identity !== null && !$exclude_pk_cols);
    $needs_hidden_rowid = $needs_rowid && !cow_merge_pk_cols($target, $table);
    foreach (cow_merge_unique_indexes($target, $table) as $index) {
        $partial_where = $index['where'] ?? null;
        if (is_string($partial_where) && !cow_merge_row_matches_partial_index_where($target, $source_row, $partial_where)) {
            continue;
        }
        $values = [];
        $clauses = [];
        foreach ($index['terms'] as $term) {
            if (($term['type'] ?? null) === 'column') {
                $column = (string)$term['name'];
                if (!array_key_exists($column, $source_row) || $source_row[$column] === null) {
                    $clauses = [];
                    break;
                }
                $collation = (string)($term['collation'] ?? 'BINARY');
                $collate_sql = strcasecmp($collation, 'BINARY') === 0
                    ? ''
                    : ' COLLATE ' . cow_merge_quote_ident($collation);
                $clauses[] = cow_merge_quote_ident($column) . $collate_sql . ' = ?';
                $values[] = $source_row[$column];
                continue;
            }
            if (($term['type'] ?? null) !== 'expression') {
                $clauses = [];
                break;
            }
            $evaluated = cow_merge_row_expression_value($target, $source_row, (string)$term['sql']);
            if (!($evaluated['ok'] ?? false) || $evaluated['value'] === null) {
                $clauses = [];
                break;
            }
            $clauses[] = '(' . (string)$term['sql'] . ') = ?';
            $values[] = $evaluated['value'];
        }
        if (!$clauses) {
            continue;
        }
        if (is_string($partial_where)) {
            $clauses[] = '(' . $partial_where . ')';
        }

        $keyless_columns = [];
        if ($needs_hidden_rowid) {
            [$select_parts, $keyless_columns] = cow_merge_keyless_select_parts($target, $table);
            $select = implode(', ', $select_parts);
        } else {
            $select = '*';
        }
        $stmt = cow_merge_prepare_checked(
            $target,
            'SELECT ' . $select . ' FROM ' . cow_merge_quote_ident($table) . ' WHERE ' . implode(' AND ', $clauses) . ' LIMIT 1',
            "failed to prepare unique collision lookup on $table"
        );
        foreach ($values as $i => $value) {
            cow_merge_bind($stmt, $i + 1, $value);
        }
        $res = cow_merge_execute_checked($stmt, $target, "failed to query unique collision lookup on $table");
        $rowid = null;
        if ($needs_hidden_rowid) {
            $values = $res->fetchArray(SQLITE3_NUM);
            $entry = $values ? cow_merge_keyless_entry_from_numeric_row($values, $keyless_columns, "$table unique collision lookup") : null;
            $row = $entry['row'] ?? false;
            $rowid = $entry['rowid'] ?? null;
        } else {
            $row = $res->fetchArray(SQLITE3_ASSOC);
        }
        cow_merge_result_finalize_checked($res, "failed to finalize unique collision lookup on $table");
        if ($row) {
            if (
                $exclude_identity !== null &&
                cow_merge_unique_collision_matches_identity(
                    $row,
                    $rowid === null ? null : (int)$rowid,
                    $exclude_identity,
                    $exclude_pk_cols,
                    $exclude_rowid
                )
            ) {
                continue;
            }
            $collision = ['index' => $index['name'], 'terms' => $index['terms'], 'row' => $row];
            if ($include_rowid && $rowid !== null) {
                $collision['rowid'] = (int)$rowid;
            }
            return $collision;
        }
    }
    return null;
}

function cow_merge_update_row(
    SQLite3 $target,
    string $table,
    array $identity,
    array $pk_cols,
    array $row,
    array $columns
): void {
    $result = cow_merge_try_update_row($target, $table, $identity, $pk_cols, $row, $columns);
    if (!($result['ok'] ?? false)) {
        throw new RuntimeException("failed to update $table: " . (string)($result['error'] ?? 'SQLite constraint failed'));
    }
}

function cow_merge_try_update_row(
    SQLite3 $target,
    string $table,
    array $identity,
    array $pk_cols,
    array $row,
    array $columns,
    ?string $execute_message = null,
    ?string $finalize_message = null
): array {
    $set_cols = [];
    foreach ($columns as $col) {
        if (!array_key_exists($col, $row) || in_array($col, $pk_cols, true)) {
            continue;
        }
        $set_cols[] = $col;
    }
    if (!$set_cols) {
        return ['ok' => true, 'error' => null];
    }
    $foreign_key_error = cow_merge_foreign_key_error($target, $table, $row);
    if ($foreign_key_error !== null) {
        return ['ok' => false, 'error' => $foreign_key_error];
    }

    $where_values = [];
    $where = cow_merge_where_clause($target, $table, $identity, $pk_cols, $where_values);
    $sql = 'UPDATE ' . cow_merge_quote_ident($table) . ' SET ' .
        implode(', ', array_map(fn($col) => cow_merge_quote_ident($col) . ' = ?', $set_cols)) .
        ' WHERE ' . $where;
    $stmt = cow_merge_prepare_checked($target, $sql, "failed to prepare update on $table");
    $index = 1;
    foreach ($set_cols as $col) {
        cow_merge_bind($stmt, $index++, $row[$col] ?? null);
    }
    foreach ($where_values as $value) {
        cow_merge_bind($stmt, $index++, $value);
    }
    $execute_result = cow_merge_execute_constraint_mutation(
        $stmt,
        $target,
        $execute_message ?? "failed to update $table",
        $finalize_message ?? "failed to finalize update on $table"
    );
    if (!($execute_result['ok'] ?? false)) {
        return ['ok' => false, 'error' => $execute_result['error'] ?? 'SQLite constraint failed'];
    }
    return ['ok' => true, 'error' => null];
}

function cow_merge_try_update_row_preserving_payload(
    SQLite3 $target,
    string $table,
    array $identity,
    array $pk_cols,
    array $row,
    array $columns,
    ?string $execute_message = null,
    ?string $finalize_message = null
): array {
    static $savepoint_counter = 0;
    $savepoint = 'cow_merge_update_payload_' . (++$savepoint_counter);
    cow_merge_exec_checked($target, 'SAVEPOINT ' . $savepoint, 'failed to start source update payload savepoint');
    try {
        $result = cow_merge_try_update_row($target, $table, $identity, $pk_cols, $row, $columns, $execute_message, $finalize_message);
        if (!($result['ok'] ?? false)) {
            $cleanup = cow_merge_rollback_release_savepoint_checked($target, $savepoint, 'source update payload');
            if ($cleanup !== null) {
                if (cow_merge_savepoint_missing_after_transaction_end($cleanup)) {
                    return $result;
                }
                throw $cleanup;
            }
            return $result;
        }

        $actual = cow_merge_select_current_row($target, $table, $identity, $pk_cols);
        $postcondition_error = cow_merge_source_apply_postcondition_error($table, $actual, $row, $columns);
        if ($postcondition_error !== null) {
            $cleanup = cow_merge_rollback_release_savepoint_checked($target, $savepoint, 'source update payload');
            if ($cleanup !== null) {
                throw $cleanup;
            }
            return ['ok' => false, 'error' => $postcondition_error];
        }

        cow_merge_release_savepoint_checked($target, $savepoint, 'source update payload');
        return $result;
    } catch (Throwable $e) {
        $cleanup = cow_merge_rollback_release_savepoint_checked($target, $savepoint, 'source update payload', $e);
        if ($cleanup !== null && !cow_merge_savepoint_missing_after_transaction_end($cleanup)) {
            throw $cleanup;
        }
        throw $e;
    }
}

function cow_merge_require_source_apply_result(array $result, string $message): void {
    if (!($result['ok'] ?? false)) {
        throw new RuntimeException($message . ': ' . (string)($result['error'] ?? 'SQLite constraint failed'));
    }
}

function cow_merge_try_delete_row(SQLite3 $target, string $table, array $identity, array $pk_cols): array {
    $current_row = cow_merge_select_current_row($target, $table, $identity, $pk_cols);
    if ($current_row === null) {
        return ['ok' => true, 'error' => null];
    }
    $foreign_key_error = cow_merge_foreign_key_delete_error($target, $table, $identity, $pk_cols, $current_row);
    if ($foreign_key_error !== null) {
        return ['ok' => false, 'error' => $foreign_key_error];
    }

    $values = [];
    $where = cow_merge_where_clause($target, $table, $identity, $pk_cols, $values);
    $stmt = cow_merge_prepare_checked(
        $target,
        'DELETE FROM ' . cow_merge_quote_ident($table) . ' WHERE ' . $where,
        "failed to prepare delete from $table"
    );
    foreach ($values as $i => $value) {
        cow_merge_bind($stmt, $i + 1, $value);
    }
    $execute_result = cow_merge_execute_constraint_mutation(
        $stmt,
        $target,
        "failed to delete from $table",
        "failed to finalize delete from $table"
    );
    if (!($execute_result['ok'] ?? false)) {
        return ['ok' => false, 'error' => $execute_result['error'] ?? 'SQLite constraint failed'];
    }
    return ['ok' => true, 'error' => null];
}

function cow_merge_try_delete_row_with_source_deleted_children(
    SQLite3 $base,
    SQLite3 $source,
    SQLite3 $target,
    SQLite3 $meta,
    int $run_id,
    string $source_branch,
    string $target_branch,
    string $table,
    array $identity,
    array $pk_cols,
    array $row
): array {
    $deletes = [];
    $updates = [];
    $materializations = [];
    $operations = [];
    $visiting = [];
    if (!cow_merge_collect_source_deleted_foreign_key_children(
        $base,
        $source,
        $target,
        $meta,
        $run_id,
        $source_branch,
        $target_branch,
        $table,
        $identity,
        $pk_cols,
        $row,
        $deletes,
        $updates,
        $materializations,
        $operations,
        $visiting
    )) {
        return cow_merge_try_delete_row($target, $table, $identity, $pk_cols);
    }

    if (!$deletes && !$updates && !$materializations) {
        return cow_merge_try_delete_row($target, $table, $identity, $pk_cols);
    }

    cow_merge_exec_checked(
        $target,
        'SAVEPOINT cow_merge_source_deleted_fk_children',
        "failed to create foreign-key delete savepoint for $table"
    );
    $keyless_deletes = [];
    $keyless_updates = [];
    $keyless_materializations = [];
    $materialized_rows = [];
    try {
        foreach ($operations as $operation) {
            if (($operation['type'] ?? null) === 'materialize') {
                $materialization = $materializations[(string)$operation['key']];
                if (
                    !$materialization['pk_cols'] &&
                    isset($materialization['rowid']) &&
                    cow_merge_load_keyless_physical_row($target, $materialization['table'], (int)$materialization['rowid']) === null
                ) {
                    $inserted_rowid = cow_merge_insert_row_with_rowid(
                        $target,
                        $materialization['table'],
                        (int)$materialization['rowid'],
                        $materialization['row'],
                        $materialization['columns']
                    );
                    $insert_result = ['ok' => true, 'rowid' => $inserted_rowid, 'error' => null];
                } else {
                    $insert_result = cow_merge_try_insert_row(
                        $target,
                        $materialization['table'],
                        $materialization['row'],
                        $materialization['columns']
                    );
                }
                if (!($insert_result['ok'] ?? false)) {
                    $cleanup_failure = cow_merge_rollback_release_savepoint_checked(
                        $target,
                        'cow_merge_source_deleted_fk_children',
                        'foreign-key dependent rewrite'
                    );
                    if ($cleanup_failure !== null) {
                        throw $cleanup_failure;
                    }
                    return ['ok' => false, 'error' => (string)($insert_result['error'] ?? 'SQLite constraint failed')];
                }
                if (!$materialization['pk_cols']) {
                    $keyless_materializations[] = [
                        $materialization['table'],
                        (int)($insert_result['rowid'] ?? 0),
                        $materialization['identity'],
                        $materialization['row'],
                    ];
                }
                $materialized_rows[] = $materialization;
                continue;
            }

            if (($operation['type'] ?? null) === 'delete') {
                $delete = $deletes[(string)$operation['key']];
                $delete_result = cow_merge_try_delete_row(
                    $target,
                    $delete['table'],
                    $delete['where_identity'],
                    $delete['pk_cols']
                );
                if (!($delete_result['ok'] ?? false)) {
                    $cleanup_failure = cow_merge_rollback_release_savepoint_checked(
                        $target,
                        'cow_merge_source_deleted_fk_children',
                        'foreign-key dependent rewrite'
                    );
                    if ($cleanup_failure !== null) {
                        throw $cleanup_failure;
                    }
                    return ['ok' => false, 'error' => (string)($delete_result['error'] ?? 'SQLite constraint failed')];
                }
                if (!$delete['pk_cols'] && isset($delete['rowid'])) {
                    $keyless_deletes[] = [$delete['table'], (int)$delete['rowid']];
                }
                continue;
            }

            $update = $updates[(string)$operation['key']];
            $update_result = cow_merge_try_update_row(
                $target,
                $update['table'],
                $update['where_identity'],
                $update['pk_cols'],
                $update['row'],
                $update['columns']
            );
            if (!($update_result['ok'] ?? false)) {
                $cleanup_failure = cow_merge_rollback_release_savepoint_checked(
                    $target,
                    'cow_merge_source_deleted_fk_children',
                    'foreign-key dependent rewrite'
                );
                if ($cleanup_failure !== null) {
                    throw $cleanup_failure;
                }
                return ['ok' => false, 'error' => (string)($update_result['error'] ?? 'SQLite constraint failed')];
            }
            if (!$update['pk_cols'] && isset($update['rowid'])) {
                $keyless_updates[] = [$update['table'], (int)$update['rowid'], $update['identity'], $update['row']];
            }
        }

        $result = cow_merge_try_delete_row($target, $table, $identity, $pk_cols);
        if (!($result['ok'] ?? false)) {
            $cleanup_failure = cow_merge_rollback_release_savepoint_checked(
                $target,
                'cow_merge_source_deleted_fk_children',
                'foreign-key dependent rewrite'
            );
            if ($cleanup_failure !== null) {
                throw $cleanup_failure;
            }
            return $result;
        }

        cow_merge_release_savepoint_checked($target, 'cow_merge_source_deleted_fk_children', 'foreign-key dependent rewrite');
    } catch (Throwable $e) {
        $cleanup_failure = cow_merge_rollback_release_savepoint_checked(
            $target,
            'cow_merge_source_deleted_fk_children',
            'foreign-key dependent rewrite',
            $e
        );
        if ($cleanup_failure !== null) {
            throw $cleanup_failure;
        }
        throw $e;
    }

    foreach ($keyless_deletes as [$child_table, $rowid]) {
        cow_merge_forget_row_identity($meta, $run_id, $target_branch, $child_table, $rowid);
    }
    foreach ($keyless_updates as [$child_table, $rowid, $child_identity, $child_row]) {
        cow_merge_remember_row_identity($meta, $run_id, $target_branch, $child_table, $rowid, $child_identity, $child_row);
    }
    foreach ($keyless_materializations as [$parent_table, $rowid, $parent_identity, $parent_row]) {
        cow_merge_remember_row_identity($meta, $run_id, $target_branch, $parent_table, $rowid, $parent_identity, $parent_row);
    }

    return [
        'ok' => true,
        'error' => null,
        'deleted_dependents' => count($deletes),
        'updated_dependents' => count($updates),
        'materialized_dependents' => count($materializations),
        'materialized_rows' => $materialized_rows,
    ];
}

function cow_merge_delete_row(SQLite3 $target, string $table, array $identity, array $pk_cols): void {
    $result = cow_merge_try_delete_row($target, $table, $identity, $pk_cols);
    if (!($result['ok'] ?? false)) {
        throw new RuntimeException("failed to delete from $table: " . (string)($result['error'] ?? 'SQLite constraint failed'));
    }
}

function cow_merge_ensure_metadata(SQLite3 $meta): void {
    cow_merge_exec_checked($meta, 'PRAGMA journal_mode = WAL', 'failed to configure metadata journal mode');
    $schema_savepoint = 'cow_merge_ensure_metadata_schema';
    cow_merge_exec_checked(
        $meta,
        'SAVEPOINT ' . $schema_savepoint,
        'failed to create metadata schema savepoint'
    );
    try {
    cow_merge_exec_checked($meta, <<<'SQL'
CREATE TABLE IF NOT EXISTS merge_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_branch TEXT NOT NULL,
    target_branch TEXT NOT NULL,
    base_ref TEXT,
    started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TEXT,
    status TEXT NOT NULL,
    policy TEXT NOT NULL,
    source_db TEXT NOT NULL,
    target_db TEXT NOT NULL,
    base_db TEXT NOT NULL,
    failure_reason TEXT
)
SQL, 'failed to create metadata table merge_runs');
    cow_merge_ensure_metadata_column($meta, 'merge_runs', 'failure_reason', 'TEXT');
    cow_merge_exec_checked($meta, <<<'SQL'
CREATE TABLE IF NOT EXISTS merge_decisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id INTEGER NOT NULL,
    table_name TEXT NOT NULL,
    row_identity TEXT,
    column_name TEXT,
    decision TEXT NOT NULL,
    reason TEXT NOT NULL,
    base_payload TEXT,
    source_payload TEXT,
    target_payload TEXT,
    chosen_payload TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(run_id) REFERENCES merge_runs(id)
)
SQL, 'failed to create metadata table merge_decisions');
    cow_merge_exec_checked($meta, <<<'SQL'
CREATE TABLE IF NOT EXISTS merge_conflicts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id INTEGER NOT NULL,
    table_name TEXT NOT NULL,
    row_identity TEXT,
    column_name TEXT,
    conflict_type TEXT NOT NULL,
    base_payload TEXT,
    source_payload TEXT,
    target_payload TEXT,
    chosen_payload TEXT,
    base_hash TEXT NOT NULL,
    source_hash TEXT NOT NULL,
    target_hash TEXT NOT NULL,
    chosen_hash TEXT NOT NULL,
    resolver TEXT NOT NULL,
    resolved_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(run_id) REFERENCES merge_runs(id),
    UNIQUE(table_name, row_identity, column_name, conflict_type, base_hash, source_hash, target_hash, chosen_hash)
)
SQL, 'failed to create metadata table merge_conflicts');
    cow_merge_exec_checked($meta, <<<'SQL'
CREATE TABLE IF NOT EXISTS merge_revalidations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    conflict_id INTEGER NOT NULL,
    review_note_id INTEGER NOT NULL,
    run_id INTEGER NOT NULL,
    replacement_conflict_id INTEGER,
    revalidation_class TEXT NOT NULL DEFAULT 'unclassified',
    source_payload TEXT NOT NULL,
    target_payload TEXT NOT NULL,
    source_hash TEXT NOT NULL,
    target_hash TEXT NOT NULL,
    stale_reason TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(conflict_id) REFERENCES merge_conflicts(id),
    FOREIGN KEY(replacement_conflict_id) REFERENCES merge_conflicts(id),
    FOREIGN KEY(review_note_id) REFERENCES merge_review_notes(id),
    FOREIGN KEY(run_id) REFERENCES merge_runs(id)
)
SQL, 'failed to create metadata table merge_revalidations');
    cow_merge_ensure_metadata_column($meta, 'merge_revalidations', 'replacement_conflict_id', 'INTEGER');
    cow_merge_ensure_metadata_column($meta, 'merge_revalidations', 'revalidation_class', "TEXT NOT NULL DEFAULT 'unclassified'");
    cow_merge_exec_checked($meta, <<<'SQL'
CREATE TABLE IF NOT EXISTS merge_row_identities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_name TEXT NOT NULL,
    table_name TEXT NOT NULL,
    rowid INTEGER NOT NULL,
    logical_identity TEXT NOT NULL,
    row_hash TEXT NOT NULL,
    first_seen_run_id INTEGER NOT NULL,
    last_seen_run_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(first_seen_run_id) REFERENCES merge_runs(id),
    FOREIGN KEY(last_seen_run_id) REFERENCES merge_runs(id),
    UNIQUE(branch_name, table_name, rowid)
)
SQL, 'failed to create metadata table merge_row_identities');
    cow_merge_exec_checked($meta, <<<'SQL'
CREATE TABLE IF NOT EXISTS merge_row_identity_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_name TEXT NOT NULL,
    table_name TEXT NOT NULL,
    rowid INTEGER NOT NULL,
    logical_identity TEXT NOT NULL,
    row_hash TEXT NOT NULL,
    first_seen_run_id INTEGER NOT NULL,
    last_seen_run_id INTEGER NOT NULL,
    deleted_run_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TEXT,
    FOREIGN KEY(first_seen_run_id) REFERENCES merge_runs(id),
    FOREIGN KEY(last_seen_run_id) REFERENCES merge_runs(id),
    FOREIGN KEY(deleted_run_id) REFERENCES merge_runs(id),
    UNIQUE(branch_name, table_name, rowid, logical_identity)
)
SQL, 'failed to create metadata table merge_row_identity_history');
    cow_merge_exec_checked($meta, <<<'SQL'
INSERT INTO merge_row_identity_history
    (branch_name, table_name, rowid, logical_identity, row_hash, first_seen_run_id, last_seen_run_id, created_at, updated_at)
SELECT branch_name, table_name, rowid, logical_identity, row_hash, first_seen_run_id, last_seen_run_id, created_at, updated_at
FROM merge_row_identities
WHERE 1
ON CONFLICT(branch_name, table_name, rowid, logical_identity) DO UPDATE SET
    row_hash = excluded.row_hash,
    last_seen_run_id = excluded.last_seen_run_id,
    updated_at = excluded.updated_at,
    deleted_at = NULL,
    deleted_run_id = NULL
SQL, 'failed to migrate metadata row identity history');
    cow_merge_exec_checked($meta, <<<'SQL'
CREATE TABLE IF NOT EXISTS merge_autoincrement_bands (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_name TEXT NOT NULL,
    table_name TEXT NOT NULL,
    band_start INTEGER NOT NULL,
    band_end INTEGER NOT NULL,
    band_size INTEGER NOT NULL,
    allocated_run_id INTEGER NOT NULL,
    last_seen_run_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(allocated_run_id) REFERENCES merge_runs(id),
    FOREIGN KEY(last_seen_run_id) REFERENCES merge_runs(id),
    UNIQUE(branch_name, table_name)
)
SQL, 'failed to create metadata table merge_autoincrement_bands');
    cow_merge_exec_checked($meta, <<<'SQL'
CREATE TABLE IF NOT EXISTS merge_rollback_failures (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id INTEGER,
    source_branch TEXT NOT NULL,
    target_branch TEXT NOT NULL,
    base_db TEXT NOT NULL,
    source_db TEXT NOT NULL,
    target_db TEXT NOT NULL,
    original_failure TEXT NOT NULL,
    rollback_failure TEXT NOT NULL,
    artifact_path TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(run_id) REFERENCES merge_runs(id)
)
SQL, 'failed to create metadata table merge_rollback_failures');
    cow_merge_exec_checked($meta, <<<'SQL'
CREATE TABLE IF NOT EXISTS merge_review_notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    record_type TEXT NOT NULL CHECK(record_type IN ('conflict', 'decision', 'resolution')),
    record_id INTEGER NOT NULL,
    status TEXT NOT NULL CHECK(status IN ('pending', 'needs-action', 'reviewed')),
    note TEXT NOT NULL,
    reviewer TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL, 'failed to create metadata table merge_review_notes');
    $review_notes_schema = cow_merge_query_checked(
        $meta,
        "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'merge_review_notes'",
        'failed to inspect review-note metadata schema'
    );
    $review_notes_row = $review_notes_schema->fetchArray(SQLITE3_ASSOC);
    if ($review_notes_row === false) {
        cow_merge_result_finalize_checked(
            $review_notes_schema,
            'failed to finalize review-note metadata schema inspection'
        );
        throw new RuntimeException('failed to inspect review-note metadata schema: missing merge_review_notes table');
    }
    $review_notes_sql = (string)$review_notes_row['sql'];
    cow_merge_result_finalize_checked(
        $review_notes_schema,
        'failed to finalize review-note metadata schema inspection'
    );
    unset($review_notes_schema);
    if (str_contains($review_notes_sql, "CHECK(record_type IN ('conflict', 'decision'))")) {
        $migration_savepoint = 'migrate_merge_review_notes_record_type';
        cow_merge_exec_checked(
            $meta,
            'SAVEPOINT ' . $migration_savepoint,
            'failed to create review-note metadata migration savepoint'
        );
        try {
            cow_merge_exec_checked($meta, 'ALTER TABLE merge_review_notes RENAME TO merge_review_notes_old', 'failed to rename legacy review-note metadata table');
            cow_merge_exec_checked($meta, <<<'SQL'
CREATE TABLE merge_review_notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    record_type TEXT NOT NULL CHECK(record_type IN ('conflict', 'decision', 'resolution')),
    record_id INTEGER NOT NULL,
    status TEXT NOT NULL CHECK(status IN ('pending', 'needs-action', 'reviewed')),
    note TEXT NOT NULL,
    reviewer TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL, 'failed to create migrated review-note metadata table');
            cow_merge_exec_checked(
                $meta,
                'INSERT INTO merge_review_notes (id, record_type, record_id, status, note, reviewer, created_at) ' .
                'SELECT id, record_type, record_id, status, note, reviewer, created_at FROM merge_review_notes_old',
                'failed to copy legacy review-note metadata'
            );
            cow_merge_exec_checked($meta, 'DROP TABLE merge_review_notes_old', 'failed to drop legacy review-note metadata table');
            cow_merge_release_savepoint_checked(
                $meta,
                $migration_savepoint,
                'review-note metadata migration'
            );
        } catch (Throwable $e) {
            $cleanup_error = cow_merge_rollback_release_savepoint_checked(
                $meta,
                $migration_savepoint,
                'review-note metadata migration',
                $e
            );
            if ($cleanup_error !== null) {
                throw $cleanup_error;
            }
            throw $e;
        }
    }
    cow_merge_exec_checked($meta, 'CREATE INDEX IF NOT EXISTS merge_review_notes_record_idx ON merge_review_notes(record_type, record_id, id)', 'failed to create metadata index merge_review_notes_record_idx');
    cow_merge_exec_checked($meta, <<<'SQL'
CREATE TABLE IF NOT EXISTS merge_resolutions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    conflict_id INTEGER NOT NULL,
    choice TEXT NOT NULL CHECK(choice IN ('source', 'target')),
    applied INTEGER NOT NULL CHECK(applied IN (0, 1)),
    status TEXT NOT NULL CHECK(status IN ('validated', 'applied')),
    note TEXT NOT NULL,
    reviewer TEXT NOT NULL,
    target_db TEXT NOT NULL,
    table_name TEXT NOT NULL,
    row_identity TEXT NOT NULL,
    column_name TEXT NOT NULL,
    previous_payload TEXT NOT NULL,
    resolved_payload TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(conflict_id) REFERENCES merge_conflicts(id)
)
SQL, 'failed to create metadata table merge_resolutions');
    cow_merge_exec_checked($meta, 'CREATE INDEX IF NOT EXISTS merge_resolutions_conflict_idx ON merge_resolutions(conflict_id, id)', 'failed to create metadata index merge_resolutions_conflict_idx');
        cow_merge_release_savepoint_checked($meta, $schema_savepoint, 'metadata schema');
    } catch (Throwable $e) {
        $cleanup_error = cow_merge_rollback_release_savepoint_checked(
            $meta,
            $schema_savepoint,
            'metadata schema',
            $e
        );
        if ($cleanup_error !== null) {
            throw $cleanup_error;
        }
        throw $e;
    }
}

function cow_merge_ensure_metadata_column(SQLite3 $meta, string $table, string $column, string $definition): void {
    $res = cow_merge_query_checked(
        $meta,
        'PRAGMA table_info(' . cow_merge_quote_ident($table) . ')',
        "failed to inspect metadata table $table"
    );
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        if ((string)$row['name'] === $column) {
            cow_merge_result_finalize_checked(
                $res,
                "failed to finalize metadata table $table inspection"
            );
            return;
        }
    }
    cow_merge_result_finalize_checked(
        $res,
        "failed to finalize metadata table $table inspection"
    );
    $sql = 'ALTER TABLE ' . cow_merge_quote_ident($table) . ' ADD COLUMN ' . cow_merge_quote_ident($column) . ' ' . $definition;
    cow_merge_exec_checked($meta, $sql, "failed to migrate metadata table $table");
}

function cow_merge_failure_reason(Throwable $e): string {
    $reason = trim($e->getMessage());
    if ($reason === '') {
        $reason = get_class($e);
    }
    return strlen($reason) > 4096 ? substr($reason, 0, 4093) . '...' : $reason;
}

function cow_merge_rollback_failure_artifact_path(string $metadata_db): string {
    return dirname($metadata_db) . '/rollback-failures.jsonl';
}

function cow_merge_record_rollback_failure_artifact(
    string $metadata_db,
    ?int $run_id,
    string $source_branch,
    string $target_branch,
    string $base_db,
    string $source_db,
    string $target_db,
    string $original_failure,
    string $rollback_failure,
    array $rollback_artifacts = []
): ?string {
    $artifact_path = cow_merge_rollback_failure_artifact_path($metadata_db);
    $record = [
        'created_at' => gmdate('c'),
        'run_id' => $run_id,
        'source_branch' => $source_branch,
        'target_branch' => $target_branch,
        'base_db' => $base_db,
        'source_db' => $source_db,
        'target_db' => $target_db,
        'original_failure' => $original_failure,
        'rollback_failure' => $rollback_failure,
    ];
    if ($rollback_artifacts !== []) {
        $record['artifacts'] = $rollback_artifacts;
    }
    $json = json_encode($record, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        $json = '{"created_at":"' . gmdate('c') . '","rollback_failure":"failed to encode rollback failure artifact"}';
    }
    $artifact_written = false;
    $dir = dirname($artifact_path);
    if ((is_dir($dir) || @mkdir($dir, 0777, true)) && @file_put_contents($artifact_path, $json . "\n", FILE_APPEND | LOCK_EX) !== false) {
        $artifact_written = true;
    }

    try {
        $meta = cow_merge_open_db($metadata_db, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        cow_merge_ensure_metadata($meta);
        $stmt = cow_merge_prepare_checked(
            $meta,
            'INSERT INTO merge_rollback_failures ' .
            '(run_id, source_branch, target_branch, base_db, source_db, target_db, original_failure, rollback_failure, artifact_path) ' .
            'VALUES (:run_id, :source_branch, :target_branch, :base_db, :source_db, :target_db, :original_failure, :rollback_failure, :artifact_path)',
            'failed to prepare rollback failure insert'
        );
        cow_merge_bind($stmt, ':run_id', $run_id);
        cow_merge_bind($stmt, ':source_branch', $source_branch);
        cow_merge_bind($stmt, ':target_branch', $target_branch);
        cow_merge_bind($stmt, ':base_db', $base_db);
        cow_merge_bind($stmt, ':source_db', $source_db);
        cow_merge_bind($stmt, ':target_db', $target_db);
        cow_merge_bind($stmt, ':original_failure', $original_failure);
        cow_merge_bind($stmt, ':rollback_failure', $rollback_failure);
        cow_merge_bind($stmt, ':artifact_path', $artifact_written ? $artifact_path : null);
        cow_merge_execute_checked($stmt, $meta, 'failed to record rollback failure');
        $meta->close();
    } catch (Throwable $metadata_error) {
        if (isset($meta) && $meta instanceof SQLite3) {
            $meta->close();
        }
    }

    return $artifact_written ? $artifact_path : null;
}

function cow_merge_sqlite_snapshot_artifact(?array $snapshot): ?array {
    if ($snapshot === null) {
        return null;
    }
    $backup = $snapshot['backup'] ?? null;
    return [
        'path' => (string)($snapshot['path'] ?? ''),
        'existed' => !empty($snapshot['existed']),
        'backup' => is_string($backup) ? $backup : null,
        'backup_exists' => is_string($backup) && is_file($backup),
    ];
}

function cow_merge_crash_recovery_dir(string $metadata_db): string {
    return dirname($metadata_db) . '/crash-recovery';
}

function cow_merge_crash_recovery_artifact_path(string $metadata_db, int $run_id, string $checkpoint): string {
    $safe_checkpoint = preg_replace('/[^A-Za-z0-9_.-]/', '-', $checkpoint);
    if (!is_string($safe_checkpoint) || $safe_checkpoint === '') {
        $safe_checkpoint = 'unknown';
    }
    return cow_merge_crash_recovery_dir($metadata_db) . '/run-' . $run_id . '-' . $safe_checkpoint . '.json';
}

function cow_merge_write_crash_recovery_artifact(
    string $metadata_db,
    int $run_id,
    string $checkpoint,
    array $run_context,
    ?array $target_snapshot,
    ?array $filesystem_transaction = null,
    ?string $filesystem_target_root = null,
    ?array $metadata_snapshot = null,
    ?array $filesystem_snapshot = null
): string {
    $path = cow_merge_crash_recovery_artifact_path($metadata_db, $run_id, $checkpoint);
    $artifacts = [];
    if ($target_snapshot !== null) {
        $artifacts['target_db_snapshot'] = cow_merge_sqlite_snapshot_artifact($target_snapshot);
    }
    if ($metadata_snapshot !== null) {
        $artifacts['metadata_db_snapshot'] = cow_merge_sqlite_snapshot_artifact($metadata_snapshot);
    }
    if ($filesystem_transaction !== null) {
        $artifacts['filesystem_transaction'] = $filesystem_transaction;
        $artifacts['filesystem_transaction_summary'] = cow_merge_file_transaction_artifact($filesystem_transaction, $filesystem_target_root);
    }
    if ($filesystem_snapshot !== null) {
        $artifacts['filesystem_snapshot'] = $filesystem_snapshot;
        $artifacts['filesystem_snapshot_summary'] = cow_merge_file_root_snapshot_artifact($filesystem_snapshot, $filesystem_target_root);
    }
    cow_merge_write_json_file($path, [
        'version' => 1,
        'created_at' => gmdate('c'),
        'checkpoint' => $checkpoint,
        'run_id' => $run_id,
        'source_branch' => (string)($run_context['source_branch'] ?? ''),
        'target_branch' => (string)($run_context['target_branch'] ?? ''),
        'base_db' => (string)($run_context['base_db'] ?? ''),
        'source_db' => (string)($run_context['source_db'] ?? ''),
        'target_db' => (string)($run_context['target_db'] ?? ''),
        'target_root' => $filesystem_target_root,
        'artifacts' => $artifacts,
    ]);
    return $path;
}

function cow_merge_remove_crash_recovery_artifact(?string $path): void {
    if ($path === null) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
    }
    $dir = dirname($path);
    if (is_dir($dir)) {
        $entries = scandir($dir);
        if (is_array($entries) && count(array_diff($entries, ['.', '..'])) === 0) {
            @rmdir($dir);
        }
    }
}

function cow_merge_crash_recovery_artifacts(string $metadata_db, ?int $run_id = null): array {
    $dir = cow_merge_crash_recovery_dir($metadata_db);
    if (!is_dir($dir)) {
        return [];
    }
    $files = glob($dir . '/*.json');
    if (!is_array($files)) {
        return [];
    }
    sort($files);
    $artifacts = [];
    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        $decoded = json_decode((string)file_get_contents($file), true);
        if (!is_array($decoded)) {
            throw new RuntimeException("invalid crash recovery artifact: $file");
        }
        $artifact_run_id = (int)($decoded['run_id'] ?? 0);
        if ($run_id !== null && $artifact_run_id !== $run_id) {
            continue;
        }
        $snapshot = $decoded['artifacts']['target_db_snapshot'] ?? null;
        $metadata_snapshot = $decoded['artifacts']['metadata_db_snapshot'] ?? null;
        $filesystem_transaction = $decoded['artifacts']['filesystem_transaction'] ?? null;
        $filesystem_summary = $decoded['artifacts']['filesystem_transaction_summary'] ?? null;
        $filesystem_snapshot = $decoded['artifacts']['filesystem_snapshot'] ?? null;
        $filesystem_snapshot_summary = $decoded['artifacts']['filesystem_snapshot_summary'] ?? null;
        $artifacts[] = [
            'artifact_path' => $file,
            'checkpoint' => (string)($decoded['checkpoint'] ?? 'unknown'),
            'run_id' => $artifact_run_id,
            'source_branch' => (string)($decoded['source_branch'] ?? ''),
            'target_branch' => (string)($decoded['target_branch'] ?? ''),
            'target_db' => (string)($decoded['target_db'] ?? ''),
            'target_root' => (string)($decoded['target_root'] ?? ''),
            'target_db_snapshot' => is_array($snapshot) ? $snapshot : null,
            'metadata_db_snapshot' => is_array($metadata_snapshot) ? $metadata_snapshot : null,
            'filesystem_transaction' => is_array($filesystem_transaction) ? $filesystem_transaction : null,
            'filesystem_transaction_summary' => is_array($filesystem_summary) ? $filesystem_summary : null,
            'filesystem_snapshot' => is_array($filesystem_snapshot) ? $filesystem_snapshot : null,
            'filesystem_snapshot_summary' => is_array($filesystem_snapshot_summary) ? $filesystem_snapshot_summary : null,
        ];
    }
    return $artifacts;
}

function cow_merge_recover_crash_artifacts(
    string $metadata_db,
    ?int $run_id = null,
    bool $restore_target_db = false,
    bool $restore_files = false
): array {
    $artifacts = cow_merge_crash_recovery_artifacts($metadata_db, $run_id);
    $restored = 0;
    if ($restore_target_db || $restore_files) {
        foreach ($artifacts as $artifact) {
            $restored_snapshot = null;
            $restored_metadata_snapshot = null;
            $restored_filesystem_transaction = null;
            $restored_filesystem_snapshot = null;
            $restored_any_artifact = false;
            if ($restore_target_db) {
                $snapshot = $artifact['target_db_snapshot'] ?? null;
                if (!is_array($snapshot) && !$restore_files) {
                    throw new RuntimeException("crash recovery artifact has no target DB snapshot: {$artifact['artifact_path']}");
                }
                if (is_array($snapshot)) {
                    cow_merge_restore_sqlite_snapshot($snapshot);
                    $restored_snapshot = $snapshot;
                    $restored_any_artifact = true;
                    $metadata_snapshot = $artifact['metadata_db_snapshot'] ?? null;
                    if (is_array($metadata_snapshot)) {
                        cow_merge_restore_sqlite_snapshot($metadata_snapshot);
                        $restored_metadata_snapshot = $metadata_snapshot;
                    }
                }
            }
            if ($restore_files) {
                $filesystem_transaction = $artifact['filesystem_transaction'] ?? null;
                $filesystem_snapshot = $artifact['filesystem_snapshot'] ?? null;
                $target_root = (string)($artifact['target_root'] ?? '');
                $can_restore_filesystem = (is_array($filesystem_transaction) || is_array($filesystem_snapshot)) && $target_root !== '';
                if (!$can_restore_filesystem && !$restored_any_artifact) {
                    throw new RuntimeException("crash recovery artifact has no filesystem transaction: {$artifact['artifact_path']}");
                }
                if ($can_restore_filesystem && is_array($filesystem_snapshot)) {
                    cow_merge_file_root_snapshot_restore($filesystem_snapshot, $target_root);
                    $restored_filesystem_snapshot = $filesystem_snapshot;
                    $restored_any_artifact = true;
                } elseif ($can_restore_filesystem && is_array($filesystem_transaction)) {
                    cow_merge_file_transaction_restore($filesystem_transaction, $target_root);
                    $restored_filesystem_transaction = $filesystem_transaction;
                    $restored_any_artifact = true;
                }
            }
            cow_merge_failpoint('after-crash-recovery-restore');
            cow_merge_remove_crash_recovery_artifact((string)$artifact['artifact_path']);
            if ($restored_snapshot !== null) {
                cow_merge_cleanup_sqlite_snapshot($restored_snapshot);
            }
            if ($restored_metadata_snapshot !== null) {
                cow_merge_cleanup_sqlite_snapshot($restored_metadata_snapshot);
            }
            if ($restored_filesystem_transaction !== null) {
                cow_merge_file_transaction_cleanup($restored_filesystem_transaction);
            }
            if ($restored_filesystem_snapshot !== null) {
                cow_merge_file_root_snapshot_cleanup($restored_filesystem_snapshot);
            }
            $restored++;
        }
        $artifacts = cow_merge_crash_recovery_artifacts($metadata_db, $run_id);
    }
    return [
        'metadata_db' => $metadata_db,
        'run_id' => $run_id,
        'restore_target_db' => $restore_target_db,
        'restore_files' => $restore_files,
        'pending' => count($artifacts),
        'restored' => $restored,
        'artifacts' => $artifacts,
    ];
}

function cow_merge_assert_no_pending_crash_recovery(string $metadata_db): void {
    $artifacts = cow_merge_crash_recovery_artifacts($metadata_db);
    if (count($artifacts) === 0) {
        return;
    }

    $first = $artifacts[0];
    $run = (int)($first['run_id'] ?? 0);
    $checkpoint = (string)($first['checkpoint'] ?? 'unknown');
    $command = PHP_BINARY . ' ' . __FILE__ . ' recover-crash --metadata-db ' . escapeshellarg($metadata_db) . ' --format json';
    throw new RuntimeException(
        'refusing to start merge while ' . count($artifacts) . ' pending COW merge crash recovery artifact(s) exist'
        . " for metadata DB $metadata_db; first pending artifact is run #$run at checkpoint $checkpoint. "
        . "Inspect pending recovery with `forkpress branch recover-crash` or `$command`, then restore with --restore-target-db and/or --restore-files before merging again."
    );
}

function cow_merge_file_root_snapshot_artifact(?array $snapshot, ?string $target_root): ?array {
    if ($snapshot === null) {
        return null;
    }
    $tx = $snapshot['transaction'] ?? [];
    $entries = $snapshot['entries'] ?? [];
    return array_merge(
        cow_merge_file_transaction_artifact(is_array($tx) ? $tx : [], $target_root),
        [
            'entries_count' => is_array($entries) ? count($entries) : 0,
        ]
    );
}

function cow_merge_start_run(
    SQLite3 $meta,
    string $source_branch,
    string $target_branch,
    string $base_db,
    string $source_db,
    string $target_db
): int {
    $stmt = cow_merge_prepare_checked(
        $meta,
        'INSERT INTO merge_runs (source_branch, target_branch, base_ref, status, policy, source_db, target_db, base_db) ' .
        'VALUES (:source_branch, :target_branch, :base_ref, :status, :policy, :source_db, :target_db, :base_db)',
        'failed to prepare merge run insert'
    );
    cow_merge_bind($stmt, ':source_branch', $source_branch);
    cow_merge_bind($stmt, ':target_branch', $target_branch);
    cow_merge_bind($stmt, ':base_ref', basename($base_db));
    cow_merge_bind($stmt, ':status', 'running');
    cow_merge_bind($stmt, ':policy', 'target-wins');
    cow_merge_bind($stmt, ':source_db', $source_db);
    cow_merge_bind($stmt, ':target_db', $target_db);
    cow_merge_bind($stmt, ':base_db', $base_db);
    cow_merge_execute_checked($stmt, $meta, 'failed to create merge run');
    return (int)$meta->lastInsertRowID();
}

function cow_merge_start_identity_capture_run(
    SQLite3 $meta,
    string $branch,
    string $db
): int {
    $stmt = cow_merge_prepare_checked(
        $meta,
        'INSERT INTO merge_runs (source_branch, target_branch, base_ref, status, policy, source_db, target_db, base_db) ' .
        'VALUES (:source_branch, :target_branch, :base_ref, :status, :policy, :source_db, :target_db, :base_db)',
        'failed to prepare row identity capture run insert'
    );
    cow_merge_bind($stmt, ':source_branch', $branch);
    cow_merge_bind($stmt, ':target_branch', $branch);
    cow_merge_bind($stmt, ':base_ref', 'identity-capture');
    cow_merge_bind($stmt, ':status', 'running');
    cow_merge_bind($stmt, ':policy', 'sidecar-row-identity-capture');
    cow_merge_bind($stmt, ':source_db', $db);
    cow_merge_bind($stmt, ':target_db', $db);
    cow_merge_bind($stmt, ':base_db', $db);
    cow_merge_execute_checked($stmt, $meta, 'failed to create row identity capture run');
    return (int)$meta->lastInsertRowID();
}

function cow_merge_run_context(SQLite3 $meta, int $run_id): array {
    $stmt = cow_merge_prepare_checked(
        $meta,
        'SELECT source_branch, target_branch, base_db, source_db, target_db FROM merge_runs WHERE id = :id',
        'failed to prepare merge run context lookup'
    );
    cow_merge_bind($stmt, ':id', $run_id);
    $result = cow_merge_execute_checked($stmt, $meta, 'failed to read merge run context');
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    cow_merge_result_finalize_checked($result, 'failed to finalize merge run context lookup');
    if (!$row) {
        return [
            'source_branch' => '',
            'target_branch' => '',
            'base_db' => '',
            'source_db' => '',
            'target_db' => '',
        ];
    }
    return [
        'source_branch' => (string)$row['source_branch'],
        'target_branch' => (string)$row['target_branch'],
        'base_db' => (string)$row['base_db'],
        'source_db' => (string)$row['source_db'],
        'target_db' => (string)$row['target_db'],
    ];
}

function cow_merge_start_id_band_run(
    SQLite3 $meta,
    string $branch,
    string $db
): int {
    $stmt = cow_merge_prepare_checked(
        $meta,
        'INSERT INTO merge_runs (source_branch, target_branch, base_ref, status, policy, source_db, target_db, base_db) ' .
        'VALUES (:source_branch, :target_branch, :base_ref, :status, :policy, :source_db, :target_db, :base_db)',
        'failed to prepare AUTOINCREMENT band allocation run insert'
    );
    cow_merge_bind($stmt, ':source_branch', $branch);
    cow_merge_bind($stmt, ':target_branch', $branch);
    cow_merge_bind($stmt, ':base_ref', 'autoincrement-id-band');
    cow_merge_bind($stmt, ':status', 'running');
    cow_merge_bind($stmt, ':policy', 'autoincrement-id-band-allocation');
    cow_merge_bind($stmt, ':source_db', $db);
    cow_merge_bind($stmt, ':target_db', $db);
    cow_merge_bind($stmt, ':base_db', $db);
    cow_merge_execute_checked($stmt, $meta, 'failed to create AUTOINCREMENT band allocation run');
    return (int)$meta->lastInsertRowID();
}

function cow_merge_start_runtime_identity_run(
    SQLite3 $meta,
    string $branch,
    string $db
): int {
    $stmt = cow_merge_prepare_checked(
        $meta,
        'INSERT INTO merge_runs (source_branch, target_branch, base_ref, status, policy, source_db, target_db, base_db) ' .
        'VALUES (:source_branch, :target_branch, :base_ref, :status, :policy, :source_db, :target_db, :base_db)',
        'failed to prepare runtime row identity tracking run insert'
    );
    cow_merge_bind($stmt, ':source_branch', $branch);
    cow_merge_bind($stmt, ':target_branch', $branch);
    cow_merge_bind($stmt, ':base_ref', 'runtime-row-identity-events');
    cow_merge_bind($stmt, ':status', 'running');
    cow_merge_bind($stmt, ':policy', 'runtime-row-identity-tracking');
    cow_merge_bind($stmt, ':source_db', $db);
    cow_merge_bind($stmt, ':target_db', $db);
    cow_merge_bind($stmt, ':base_db', $db);
    cow_merge_execute_checked($stmt, $meta, 'failed to create runtime row identity tracking run');
    return (int)$meta->lastInsertRowID();
}

function cow_merge_finish_run(SQLite3 $meta, int $run_id, string $status, ?string $failure_reason = null): void {
    if ($status !== 'failed') {
        $failure_reason = null;
    }
    $stmt = cow_merge_prepare_checked(
        $meta,
        'UPDATE merge_runs SET status = :status, finished_at = CURRENT_TIMESTAMP, failure_reason = :failure_reason WHERE id = :id',
        'failed to prepare merge run status update'
    );
    cow_merge_bind($stmt, ':status', $status);
    cow_merge_bind($stmt, ':failure_reason', $failure_reason);
    cow_merge_bind($stmt, ':id', $run_id);
    cow_merge_execute_checked($stmt, $meta, 'failed to finish merge run');
}

function cow_merge_keyless_tables(SQLite3 $db): array {
    $tables = [];
    foreach (array_keys(cow_merge_table_sql_map($db)) as $table) {
        if (!cow_merge_pk_cols($db, $table)) {
            $tables[] = $table;
        }
    }
    sort($tables);
    return $tables;
}

function cow_merge_capture_row_identities(
    string $db_path,
    string $metadata_db,
    string $branch,
    ?string $seed_branch = null
): array {
    if (!is_file($db_path)) {
        throw new RuntimeException("SQLite database does not exist: $db_path");
    }
    cow_merge_mkdir_p(dirname($metadata_db));

    $db = cow_merge_open_db($db_path, SQLITE3_OPEN_READONLY);
    $meta = cow_merge_open_db($metadata_db, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    cow_merge_ensure_metadata($meta);
    $run_id = cow_merge_start_identity_capture_run($meta, $branch, $db_path);
    cow_merge_finish_run($meta, $run_id, 'failed', 'row identity capture metadata transaction did not complete');

    $tables = 0;
    $rows = 0;
    $created = 0;
    $metadata_transaction_active = false;
    try {
        cow_merge_exec_checked($meta, 'BEGIN IMMEDIATE', 'failed to start row identity capture metadata transaction');
        $metadata_transaction_active = true;
        foreach (cow_merge_keyless_tables($db) as $table) {
            $tables++;
            $current_rowids = [];
            foreach (cow_merge_load_keyless_physical_rows($db, $table) as $entry) {
                $rowid = (int)$entry['rowid'];
                $current_rowids[$rowid] = true;
                $identity = cow_merge_lookup_row_identity($meta, $branch, $table, $rowid);
                if ($identity === null && $seed_branch !== null) {
                    $identity = cow_merge_lookup_row_identity($meta, $seed_branch, $table, $rowid);
                }
                if ($identity === null) {
                    $identity = cow_merge_keyless_branch_identity($branch, $table, $rowid, $entry['row']);
                    $created++;
                }
                cow_merge_remember_row_identity($meta, $run_id, $branch, $table, $rowid, $identity, $entry['row']);
                $rows++;
            }
            $active = cow_merge_prepare_checked(
                $meta,
                'SELECT rowid FROM merge_row_identities ' .
                'WHERE branch_name = :branch_name AND table_name = :table_name ORDER BY rowid',
                'failed to prepare active row identity listing for capture'
            );
            cow_merge_bind($active, ':branch_name', $branch);
            cow_merge_bind($active, ':table_name', $table);
            $active_result = cow_merge_execute_checked($active, $meta, 'failed to list active row identities for capture');
            $missing_rowids = [];
            while ($active_row = $active_result->fetchArray(SQLITE3_ASSOC)) {
                $active_rowid = (int)$active_row['rowid'];
                if (!isset($current_rowids[$active_rowid])) {
                    $missing_rowids[] = $active_rowid;
                }
            }
            cow_merge_result_finalize_checked($active_result, 'failed to finalize active row identity listing for capture');
            foreach ($missing_rowids as $missing_rowid) {
                cow_merge_forget_row_identity($meta, $run_id, $branch, $table, $missing_rowid);
            }
        }
        cow_merge_finish_run($meta, $run_id, 'identity_captured');
        cow_merge_exec_checked($meta, 'COMMIT', 'failed to commit row identity capture metadata transaction');
        $metadata_transaction_active = false;
        return [
            'run_id' => $run_id,
            'status' => 'identity_captured',
            'tables' => $tables,
            'rows' => $rows,
            'created' => $created,
            'metadata_db' => $metadata_db,
        ];
    } catch (Throwable $e) {
        if ($metadata_transaction_active) {
            @$meta->exec('ROLLBACK');
        }
        cow_merge_finish_run($meta, $run_id, 'failed', cow_merge_failure_reason($e));
        throw $e;
    } finally {
        $db->close();
        $meta->close();
    }
}

function cow_merge_normalize_identity_events(array $events): array {
    $out = [];
    foreach ($events as $index => $event) {
        if (!is_array($event)) {
            continue;
        }
        $table = $event['table_name'] ?? $event['table'] ?? null;
        $op = strtolower((string)($event['op'] ?? ''));
        $rowid = $event['rowid'] ?? null;
        if (!is_string($table) || $table === '' || !in_array($op, ['insert', 'delete'], true) || !is_numeric($rowid)) {
            continue;
        }
        $out[] = [
            'id' => isset($event['id']) && is_numeric($event['id']) ? (int)$event['id'] : $index + 1,
            'table_name' => $table,
            'op' => $op,
            'rowid' => (int)$rowid,
            'row' => cow_merge_decode_identity_event_row($event['row'] ?? $event['row_payload'] ?? null),
        ];
    }
    usort($out, fn($a, $b) => $a['id'] <=> $b['id']);
    return $out;
}

function cow_merge_decode_identity_event_row(mixed $payload): ?array {
    if (is_array($payload)) {
        return $payload;
    }
    if (!is_string($payload) || $payload === '') {
        return null;
    }
    $decoded = json_decode($payload, true);
    if (!is_array($decoded)) {
        return null;
    }

    $row = [];
    foreach ($decoded as $column => $envelope) {
        if (!is_string($column) || !is_array($envelope)) {
            continue;
        }
        $type = $envelope['type'] ?? null;
        $value = $envelope['value'] ?? null;
        $row[$column] = match ($type) {
            'null' => null,
            'integer' => is_numeric($value) ? (int)$value : $value,
            'real' => is_numeric($value) ? (float)$value : $value,
            'blob' => is_string($value) ? (hex2bin($value) ?: '') : '',
            default => is_scalar($value) || $value === null ? $value : (string)json_encode($value),
        };
    }
    return $row;
}

function cow_merge_track_row_identity_events(
    string $db_path,
    string $metadata_db,
    string $branch,
    array $events
): array {
    if (!is_file($db_path)) {
        throw new RuntimeException("SQLite database does not exist: $db_path");
    }
    cow_merge_mkdir_p(dirname($metadata_db));

    $events = cow_merge_normalize_identity_events($events);
    $db = cow_merge_open_db($db_path, SQLITE3_OPEN_READONLY);
    $meta = cow_merge_open_db($metadata_db, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    cow_merge_ensure_metadata($meta);
    $run_id = cow_merge_start_runtime_identity_run($meta, $branch, $db_path);
    cow_merge_finish_run($meta, $run_id, 'failed', 'runtime row identity metadata transaction did not complete');

    $tracked = 0;
    $created = 0;
    $deleted = 0;
    $metadata_transaction_active = false;
    try {
        $keyless_tables = array_fill_keys(cow_merge_keyless_tables($db), true);
        cow_merge_exec_checked($meta, 'BEGIN IMMEDIATE', 'failed to start runtime row identity metadata transaction');
        $metadata_transaction_active = true;
        foreach ($events as $event) {
            $table = (string)$event['table_name'];
            $op = (string)$event['op'];
            $rowid = (int)$event['rowid'];
            if (!isset($keyless_tables[$table])) {
                continue;
            }
            $tracked++;
            if ($op === 'delete') {
                $identity = cow_merge_forget_row_identity($meta, $run_id, $branch, $table, $rowid);
                if ($identity === null && is_array($event['row'])) {
                    $identity = cow_merge_keyless_runtime_identity($branch, $table, $rowid, $event['row'], $run_id, (int)$event['id'], 'runtime-delete');
                    cow_merge_remember_row_identity($meta, $run_id, $branch, $table, $rowid, $identity, $event['row']);
                    cow_merge_forget_row_identity($meta, $run_id, $branch, $table, $rowid);
                    $created++;
                }
                if ($identity !== null) {
                    cow_merge_record_decision(
                        $meta,
                        $run_id,
                        $table,
                        cow_merge_identity_json($identity),
                        null,
                        'identity-tracked',
                        'runtime observed deletion of a no-primary-key row',
                        null,
                        null,
                        is_array($event['row']) ? $event['row'] : ['rowid' => $rowid],
                        null
                    );
                }
                $deleted++;
                continue;
            }

            $event_row = is_array($event['row']) ? $event['row'] : null;
            $entry = $event_row === null ? cow_merge_load_keyless_physical_row($db, $table, $rowid) : [
                'rowid' => $rowid,
                'row' => $event_row,
            ];
            if ($entry === null) {
                continue;
            }
            $identity = cow_merge_lookup_row_identity($meta, $branch, $table, $rowid);
            if ($identity === null) {
                $identity = cow_merge_keyless_runtime_identity($branch, $table, $rowid, $entry['row'], $run_id, (int)$event['id']);
                $created++;
            }
            cow_merge_remember_row_identity($meta, $run_id, $branch, $table, $rowid, $identity, $entry['row']);
            cow_merge_record_decision(
                $meta,
                $run_id,
                $table,
                cow_merge_identity_json($identity),
                null,
                'identity-tracked',
                'runtime observed insertion of a no-primary-key row',
                null,
                $entry['row'],
                null,
                $entry['row']
            );
        }

        foreach (cow_merge_keyless_tables($db) as $table) {
            foreach (cow_merge_load_keyless_physical_rows($db, $table) as $entry) {
                $rowid = (int)$entry['rowid'];
                $identity = cow_merge_lookup_row_identity($meta, $branch, $table, $rowid);
                if ($identity === null) {
                    $identity = cow_merge_keyless_branch_identity($branch, $table, $rowid, $entry['row']);
                    $created++;
                }
                cow_merge_remember_row_identity($meta, $run_id, $branch, $table, $rowid, $identity, $entry['row']);
            }
        }

        cow_merge_finish_run($meta, $run_id, 'identity_tracked');
        cow_merge_exec_checked($meta, 'COMMIT', 'failed to commit runtime row identity metadata transaction');
        $metadata_transaction_active = false;
        return [
            'run_id' => $run_id,
            'status' => 'identity_tracked',
            'events' => count($events),
            'tracked' => $tracked,
            'created' => $created,
            'deleted' => $deleted,
            'metadata_db' => $metadata_db,
        ];
    } catch (Throwable $e) {
        if ($metadata_transaction_active) {
            @$meta->exec('ROLLBACK');
        }
        cow_merge_finish_run($meta, $run_id, 'failed', cow_merge_failure_reason($e));
        throw $e;
    } finally {
        $db->close();
        $meta->close();
    }
}

function cow_merge_autoincrement_tables(SQLite3 $db): array {
    $tables = [];
    foreach (cow_merge_table_sql_map($db) as $table => $sql) {
        if (preg_match('/\bAUTOINCREMENT\b/i', (string)$sql)) {
            $tables[] = $table;
        }
    }
    sort($tables);
    return $tables;
}

function cow_merge_plain_integer_primary_key_tables(SQLite3 $db): array {
    $tables = [];
    foreach (cow_merge_table_sql_map($db) as $table => $sql) {
        $sql = (string)$sql;
        if (preg_match('/\bAUTOINCREMENT\b/i', $sql) || preg_match('/\bWITHOUT\s+ROWID\b/i', $sql)) {
            continue;
        }
        $pk_columns = array_values(array_filter(
            cow_merge_table_info($db, $table),
            fn($column) => (int)$column['pk'] > 0
        ));
        if (count($pk_columns) !== 1) {
            continue;
        }
        $type = strtoupper(trim((string)$pk_columns[0]['type']));
        if ($type === 'INTEGER') {
            $tables[] = $table;
        }
    }
    sort($tables);
    return $tables;
}

function cow_merge_table_max_rowid(SQLite3 $db, string $table): int {
    $res = cow_merge_query_checked(
        $db,
        'SELECT COALESCE(MAX(rowid), 0) AS max_rowid FROM ' . cow_merge_quote_ident($table),
        'failed to read AUTOINCREMENT table max rowid'
    );
    $row = $res->fetchArray(SQLITE3_ASSOC);
    cow_merge_result_finalize_checked($res, 'failed to finalize AUTOINCREMENT table max rowid lookup');
    return $row ? (int)$row['max_rowid'] : 0;
}

function cow_merge_sqlite_sequence_value(SQLite3 $db, string $table): int {
    $stmt = cow_merge_prepare_checked(
        $db,
        'SELECT seq FROM sqlite_sequence WHERE name = :name',
        'failed to prepare sqlite_sequence lookup'
    );
    cow_merge_bind($stmt, ':name', $table);
    $res = cow_merge_execute_checked($stmt, $db, 'failed to read sqlite_sequence');
    $row = $res->fetchArray(SQLITE3_ASSOC);
    cow_merge_result_finalize_checked($res, 'failed to finalize sqlite_sequence lookup');
    return $row ? (int)$row['seq'] : 0;
}

function cow_merge_set_sqlite_sequence(SQLite3 $db, string $table, int $seq): void {
    $stmt = cow_merge_prepare_checked(
        $db,
        'UPDATE sqlite_sequence SET seq = :seq WHERE name = :name',
        'failed to prepare sqlite_sequence update'
    );
    cow_merge_bind($stmt, ':seq', $seq);
    cow_merge_bind($stmt, ':name', $table);
    cow_merge_execute_checked($stmt, $db, 'failed to update sqlite_sequence');
    if ($db->changes() > 0) {
        return;
    }

    $stmt = cow_merge_prepare_checked(
        $db,
        'INSERT INTO sqlite_sequence (name, seq) VALUES (:name, :seq)',
        'failed to prepare sqlite_sequence insert'
    );
    cow_merge_bind($stmt, ':name', $table);
    cow_merge_bind($stmt, ':seq', $seq);
    cow_merge_execute_checked($stmt, $db, 'failed to insert sqlite_sequence row');
}

function cow_merge_lookup_autoincrement_band(SQLite3 $meta, string $branch, string $table): ?array {
    $stmt = cow_merge_prepare_checked(
        $meta,
        'SELECT band_start, band_end, band_size FROM merge_autoincrement_bands ' .
        'WHERE branch_name = :branch_name AND table_name = :table_name',
        'failed to prepare AUTOINCREMENT band lookup'
    );
    cow_merge_bind($stmt, ':branch_name', $branch);
    cow_merge_bind($stmt, ':table_name', $table);
    $res = cow_merge_execute_checked($stmt, $meta, 'failed to look up AUTOINCREMENT band');
    $row = $res->fetchArray(SQLITE3_ASSOC);
    cow_merge_result_finalize_checked($res, 'failed to finalize AUTOINCREMENT band lookup');
    if (!$row) {
        return null;
    }
    return [
        'band_start' => (int)$row['band_start'],
        'band_end' => (int)$row['band_end'],
        'band_size' => (int)$row['band_size'],
    ];
}

function cow_merge_autoincrement_id_band_violation(
    SQLite3 $meta,
    string $source_branch,
    string $table,
    array $source_row,
    array $pk_cols
): ?string {
    if (count($pk_cols) !== 1) {
        return null;
    }
    $band = cow_merge_lookup_autoincrement_band($meta, $source_branch, $table);
    if ($band === null) {
        return null;
    }
    $pk_col = $pk_cols[0];
    if (!array_key_exists($pk_col, $source_row)) {
        return null;
    }
    $value = $source_row[$pk_col];
    if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value))) {
        return null;
    }
    $id = (int)$value;
    $band_start = (int)$band['band_start'];
    $band_end = (int)$band['band_end'];
    if ($id >= $band_start && $id <= $band_end) {
        return null;
    }
    return "source inserted explicit AUTOINCREMENT id $id outside reserved branch band $band_start-$band_end";
}

function cow_merge_wordpress_parent_reference_violation(
    SQLite3 $source,
    SQLite3 $target,
    SQLite3 $meta,
    string $source_branch,
    string $child_label,
    string $parent_table,
    string $parent_pk,
    mixed $parent_id,
    string $parent_label,
    string $source_action = 'inserted'
): ?string {
    if (!is_int($parent_id) && !(is_string($parent_id) && preg_match('/^-?\d+$/', (string)$parent_id))) {
        return null;
    }
    $parent_id = (int)$parent_id;
    if ($parent_id <= 0 || !cow_merge_schema_object_exists($target, $parent_table)) {
        return null;
    }
    $stmt = cow_merge_prepare_checked(
        $target,
        'SELECT 1 FROM ' . cow_merge_quote_ident($parent_table) . ' WHERE ' . cow_merge_quote_ident($parent_pk) . ' = :parent_id LIMIT 1',
        "failed to prepare WordPress $parent_label parent lookup"
    );
    cow_merge_bind($stmt, ':parent_id', $parent_id);
    $res = cow_merge_execute_checked($stmt, $target, "failed to inspect WordPress $parent_label parent row");
    try {
        if ($res->fetchArray(SQLITE3_NUM)) {
            return null;
        }
    } finally {
        cow_merge_result_finalize_checked($res, "failed to finalize WordPress $parent_label parent lookup");
    }
    if (!cow_merge_schema_object_exists($source, $parent_table)) {
        return "source $source_action $child_label references missing $parent_table.$parent_pk $parent_id; parent $parent_label must merge before child row";
    }
    $stmt = cow_merge_prepare_checked(
        $source,
        'SELECT * FROM ' . cow_merge_quote_ident($parent_table) . ' WHERE ' . cow_merge_quote_ident($parent_pk) . ' = :parent_id LIMIT 1',
        "failed to prepare WordPress $parent_label source parent lookup"
    );
    cow_merge_bind($stmt, ':parent_id', $parent_id);
    $res = cow_merge_execute_checked($stmt, $source, "failed to inspect WordPress $parent_label source parent row");
    try {
        $parent = $res->fetchArray(SQLITE3_ASSOC);
    } finally {
        cow_merge_result_finalize_checked($res, "failed to finalize WordPress $parent_label source parent lookup");
    }
    if (!$parent) {
        return "source $source_action $child_label references missing $parent_table.$parent_pk $parent_id; parent $parent_label must merge before child row";
    }
    $parent_band_violation = cow_merge_autoincrement_id_band_violation($meta, $source_branch, $parent_table, $parent, [$parent_pk]);
    if ($parent_band_violation !== null) {
        return "source $source_action $child_label references $parent_table.$parent_pk $parent_id that is outside the source branch ID band; parent $parent_label must merge before child row";
    }
    return null;
}

function cow_merge_wordpress_source_row(SQLite3 $source, string $table, string $pk, mixed $id): ?array {
    if (!is_int($id) && !(is_string($id) && preg_match('/^-?\d+$/', (string)$id))) {
        return null;
    }
    if (!cow_merge_schema_object_exists($source, $table)) {
        return null;
    }
    $stmt = cow_merge_prepare_checked(
        $source,
        'SELECT * FROM ' . cow_merge_quote_ident($table) . ' WHERE ' . cow_merge_quote_ident($pk) . ' = :id LIMIT 1',
        "failed to prepare WordPress source row lookup for $table"
    );
    cow_merge_bind($stmt, ':id', (int)$id);
    $res = cow_merge_execute_checked($stmt, $source, "failed to inspect WordPress source row for $table");
    try {
        $row = $res->fetchArray(SQLITE3_ASSOC);
        return $row ?: null;
    } finally {
        cow_merge_result_finalize_checked($res, "failed to finalize WordPress source row lookup for $table");
    }
}

function cow_merge_wordpress_source_postmeta_value(SQLite3 $source, mixed $post_id, string $meta_key): ?string {
    if (!is_int($post_id) && !(is_string($post_id) && preg_match('/^-?\d+$/', (string)$post_id))) {
        return null;
    }
    if (!cow_merge_schema_object_exists($source, 'wp_postmeta')) {
        return null;
    }
    $stmt = cow_merge_prepare_checked(
        $source,
        "SELECT meta_value FROM wp_postmeta WHERE post_id = :post_id AND meta_key = :meta_key ORDER BY meta_id DESC LIMIT 1",
        'failed to prepare WordPress source postmeta lookup'
    );
    cow_merge_bind($stmt, ':post_id', (int)$post_id);
    cow_merge_bind($stmt, ':meta_key', $meta_key);
    $res = cow_merge_execute_checked($stmt, $source, 'failed to inspect WordPress source postmeta row');
    try {
        $row = $res->fetchArray(SQLITE3_ASSOC);
        return $row ? (string)$row['meta_value'] : null;
    } finally {
        cow_merge_result_finalize_checked($res, 'failed to finalize WordPress source postmeta lookup');
    }
}

function cow_merge_wordpress_comment_reference_violation(
    SQLite3 $source,
    SQLite3 $target,
    SQLite3 $meta,
    string $source_branch,
    string $child_label,
    array $comment,
    string $source_action = 'inserted'
): ?string {
    $post_violation = cow_merge_wordpress_parent_reference_violation($source, $target, $meta, $source_branch, $child_label, 'wp_posts', 'ID', $comment['comment_post_ID'] ?? null, 'post', $source_action);
    if ($post_violation !== null) {
        return $post_violation;
    }
    $comment_parent = $comment['comment_parent'] ?? null;
    $comment_violation = cow_merge_wordpress_parent_reference_violation($source, $target, $meta, $source_branch, $child_label, 'wp_comments', 'comment_ID', $comment_parent, 'parent comment', $source_action);
    if ($comment_violation !== null) {
        return $comment_violation;
    }
    $parent_comment = cow_merge_wordpress_source_row($source, 'wp_comments', 'comment_ID', $comment_parent);
    if ($parent_comment !== null) {
        return cow_merge_wordpress_parent_reference_violation($source, $target, $meta, $source_branch, $child_label, 'wp_posts', 'ID', $parent_comment['comment_post_ID'] ?? null, 'parent comment post', $source_action);
    }
    return null;
}

function cow_merge_json_object_end(string $text, int $start): ?int {
    if (($text[$start] ?? '') !== '{') {
        return null;
    }
    $depth = 0;
    $in_string = false;
    $escaped = false;
    $length = strlen($text);
    for ($i = $start; $i < $length; $i++) {
        $char = $text[$i];
        if ($in_string) {
            if ($escaped) {
                $escaped = false;
            } elseif ($char === '\\') {
                $escaped = true;
            } elseif ($char === '"') {
                $in_string = false;
            }
            continue;
        }
        if ($char === '"') {
            $in_string = true;
            continue;
        }
        if ($char === '{') {
            $depth++;
            continue;
        }
        if ($char === '}') {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }
    return null;
}

function cow_merge_wordpress_post_content_blocks(string $content): array {
    if (!preg_match_all('/<!--\s*wp:([A-Za-z0-9_\/-]+)\s*/', $content, $matches, PREG_OFFSET_CAPTURE)) {
        return [];
    }

    $blocks = [];
    foreach ($matches[0] as $index => $match) {
        $cursor = (int)$match[1] + strlen((string)$match[0]);
        $length = strlen($content);
        while ($cursor < $length && ctype_space($content[$cursor])) {
            $cursor++;
        }
        if (($content[$cursor] ?? '') !== '{') {
            continue;
        }
        $end = cow_merge_json_object_end($content, $cursor);
        if ($end === null) {
            continue;
        }
        $blocks[] = [
            'name' => (string)$matches[1][$index][0],
            'attrs' => substr($content, $cursor, $end - $cursor + 1),
        ];
    }
    return $blocks;
}

function cow_merge_wordpress_post_content_reference_violation(
    SQLite3 $source,
    SQLite3 $target,
    SQLite3 $meta,
    string $source_branch,
    array $source_row,
    string $source_action = 'inserted'
): ?string {
    $content = (string)($source_row['post_content'] ?? '');
    if ($content === '') {
        return null;
    }

    $check_post = function (mixed $id, string $label) use ($source, $target, $meta, $source_branch, $source_action): ?string {
        return cow_merge_wordpress_parent_reference_violation(
            $source,
            $target,
            $meta,
            $source_branch,
            "wp_posts post_content $label",
            'wp_posts',
            'ID',
            $id,
            'post',
            $source_action
        );
    };
    $check_user = function (mixed $id, string $label) use ($source, $target, $meta, $source_branch, $source_action): ?string {
        return cow_merge_wordpress_parent_reference_violation(
            $source,
            $target,
            $meta,
            $source_branch,
            "wp_posts post_content $label",
            'wp_users',
            'ID',
            $id,
            'user',
            $source_action
        );
    };
    $check_term = function (mixed $id, string $label) use ($source, $target, $meta, $source_branch, $source_action): ?string {
        return cow_merge_wordpress_parent_reference_violation(
            $source,
            $target,
            $meta,
            $source_branch,
            "wp_posts post_content $label",
            'wp_terms',
            'term_id',
            $id,
            'term',
            $source_action
        );
    };

    $blocks = cow_merge_wordpress_post_content_blocks($content);
    if (!$blocks) {
        return null;
    }

    $media_id_blocks = ['audio', 'cover', 'file', 'image', 'video'];
    foreach ($blocks as $block) {
        $block_name = (string)$block['name'];
        $attrs = json_decode((string)$block['attrs'], true);
        if (!is_array($attrs)) {
            continue;
        }

        if ($block_name === 'block' && array_key_exists('ref', $attrs)) {
            $violation = $check_post($attrs['ref'], 'wp:block.ref');
            if ($violation !== null) {
                return $violation;
            }
        }

        if (in_array($block_name, $media_id_blocks, true) && array_key_exists('id', $attrs)) {
            $violation = $check_post($attrs['id'], 'wp:' . $block_name . '.id');
            if ($violation !== null) {
                return $violation;
            }
        }

        if ($block_name === 'media-text' && array_key_exists('mediaId', $attrs)) {
            $violation = $check_post($attrs['mediaId'], 'wp:media-text.mediaId');
            if ($violation !== null) {
                return $violation;
            }
        }

        if ($block_name === 'gallery' && isset($attrs['ids']) && is_array($attrs['ids'])) {
            foreach ($attrs['ids'] as $index => $id) {
                $violation = $check_post($id, 'wp:gallery.ids.' . (string)$index);
                if ($violation !== null) {
                    return $violation;
                }
            }
        }

        if ($block_name === 'avatar' && array_key_exists('userId', $attrs)) {
            $violation = $check_user($attrs['userId'], 'wp:avatar.userId');
            if ($violation !== null) {
                return $violation;
            }
        }

        if ($block_name === 'navigation-link' && array_key_exists('id', $attrs)) {
            if (($attrs['kind'] ?? null) === 'post-type') {
                $violation = $check_post($attrs['id'], 'wp:navigation-link.id');
                if ($violation !== null) {
                    return $violation;
                }
            }
            if (($attrs['kind'] ?? null) === 'taxonomy') {
                $violation = $check_term($attrs['id'], 'wp:navigation-link.id');
                if ($violation !== null) {
                    return $violation;
                }
            }
        }
    }

    return null;
}

function cow_merge_wordpress_option_reference_violation(
    SQLite3 $source,
    SQLite3 $target,
    SQLite3 $meta,
    string $source_branch,
    array $source_row,
    string $source_action = 'inserted'
): ?string {
    $option_name = (string)($source_row['option_name'] ?? '');
    $option_value = (string)($source_row['option_value'] ?? '');
    if ($option_name === '') {
        return null;
    }

    $check_post = function (mixed $id, string $label) use ($source, $target, $meta, $source_branch, $option_name, $source_action): ?string {
        return cow_merge_wordpress_parent_reference_violation(
            $source,
            $target,
            $meta,
            $source_branch,
            "wp_options row '$option_name' $label",
            'wp_posts',
            'ID',
            $id,
            'post',
            $source_action
        );
    };
    $check_term = function (mixed $id, string $label) use ($source, $target, $meta, $source_branch, $option_name, $source_action): ?string {
        return cow_merge_wordpress_parent_reference_violation(
            $source,
            $target,
            $meta,
            $source_branch,
            "wp_options row '$option_name' $label",
            'wp_terms',
            'term_id',
            $id,
            'term',
            $source_action
        );
    };

    if (in_array($option_name, ['page_on_front', 'page_for_posts', 'site_icon'], true)) {
        return $check_post($option_value, 'value');
    }

    $decoded = @unserialize($option_value, ['allowed_classes' => false]);
    if (!is_array($decoded)) {
        return null;
    }

    if ($option_name === 'sticky_posts') {
        foreach ($decoded as $index => $post_id) {
            $violation = $check_post($post_id, 'sticky_posts.' . (string)$index);
            if ($violation !== null) {
                return $violation;
            }
        }
        return null;
    }

    if (str_starts_with($option_name, 'theme_mods_')) {
        if (array_key_exists('custom_logo', $decoded)) {
            $violation = $check_post($decoded['custom_logo'], 'custom_logo');
            if ($violation !== null) {
                return $violation;
            }
        }
        $locations = $decoded['nav_menu_locations'] ?? null;
        if (is_array($locations)) {
            foreach ($locations as $location => $term_id) {
                $violation = $check_term($term_id, 'nav_menu_locations.' . (string)$location);
                if ($violation !== null) {
                    return $violation;
                }
            }
        }
        return null;
    }

    if ($option_name === 'widget_nav_menu') {
        foreach ($decoded as $widget_id => $widget) {
            if (!is_array($widget) || !array_key_exists('nav_menu', $widget)) {
                continue;
            }
            $violation = $check_term($widget['nav_menu'], 'widget.' . (string)$widget_id . '.nav_menu');
            if ($violation !== null) {
                return $violation;
            }
        }
        return null;
    }

    if ($option_name === 'widget_media_image') {
        foreach ($decoded as $widget_id => $widget) {
            if (!is_array($widget) || !array_key_exists('attachment_id', $widget)) {
                continue;
            }
            $violation = $check_post($widget['attachment_id'], 'widget.' . (string)$widget_id . '.attachment_id');
            if ($violation !== null) {
                return $violation;
            }
        }
    }

    return null;
}

function cow_merge_wordpress_insert_reference_violation(
    SQLite3 $source,
    SQLite3 $target,
    SQLite3 $meta,
    string $source_branch,
    string $table,
    array $source_row
): ?string {
    return cow_merge_wordpress_row_reference_violation($source, $target, $meta, $source_branch, $table, $source_row, 'inserted');
}

function cow_merge_wordpress_update_reference_violation(
    SQLite3 $source,
    SQLite3 $target,
    SQLite3 $meta,
    string $source_branch,
    string $table,
    array $source_row
): ?string {
    return cow_merge_wordpress_row_reference_violation($source, $target, $meta, $source_branch, $table, $source_row, 'changed');
}

function cow_merge_wordpress_row_reference_violation(
    SQLite3 $source,
    SQLite3 $target,
    SQLite3 $meta,
    string $source_branch,
    string $table,
    array $source_row,
    string $source_action
): ?string {
    if ($table === 'wp_posts') {
        $author_violation = cow_merge_wordpress_parent_reference_violation($source, $target, $meta, $source_branch, 'wp_posts row', 'wp_users', 'ID', $source_row['post_author'] ?? null, 'user', $source_action);
        if ($author_violation !== null) {
            return $author_violation;
        }
        $parent_violation = cow_merge_wordpress_parent_reference_violation($source, $target, $meta, $source_branch, 'wp_posts row', 'wp_posts', 'ID', $source_row['post_parent'] ?? null, 'post', $source_action);
        if ($parent_violation !== null) {
            return $parent_violation;
        }
        return cow_merge_wordpress_post_content_reference_violation($source, $target, $meta, $source_branch, $source_row, $source_action);
    }
    if ($table === 'wp_usermeta') {
        return cow_merge_wordpress_parent_reference_violation($source, $target, $meta, $source_branch, 'wp_usermeta row', 'wp_users', 'ID', $source_row['user_id'] ?? null, 'user', $source_action);
    }
    if ($table === 'wp_postmeta') {
        $post_violation = cow_merge_wordpress_parent_reference_violation($source, $target, $meta, $source_branch, 'wp_postmeta row', 'wp_posts', 'ID', $source_row['post_id'] ?? null, 'post', $source_action);
        if ($post_violation !== null) {
            return $post_violation;
        }
        $meta_key = (string)($source_row['meta_key'] ?? '');
        if (in_array($meta_key, ['_thumbnail_id', '_menu_item_menu_item_parent'], true)) {
            return cow_merge_wordpress_parent_reference_violation($source, $target, $meta, $source_branch, 'wp_postmeta row', 'wp_posts', 'ID', $source_row['meta_value'] ?? null, 'referenced post', $source_action);
        }
        if ($meta_key === '_menu_item_object_id') {
            $menu_item_type = cow_merge_wordpress_source_postmeta_value($source, $source_row['post_id'] ?? null, '_menu_item_type');
            if ($menu_item_type === 'taxonomy') {
                return cow_merge_wordpress_parent_reference_violation($source, $target, $meta, $source_branch, 'wp_postmeta row', 'wp_terms', 'term_id', $source_row['meta_value'] ?? null, 'referenced term', $source_action);
            }
            if ($menu_item_type === 'post_type') {
                return cow_merge_wordpress_parent_reference_violation($source, $target, $meta, $source_branch, 'wp_postmeta row', 'wp_posts', 'ID', $source_row['meta_value'] ?? null, 'referenced post', $source_action);
            }
        }
        return null;
    }
    if ($table === 'wp_comments') {
        $comment_violation = cow_merge_wordpress_comment_reference_violation($source, $target, $meta, $source_branch, 'wp_comments row', $source_row, $source_action);
        if ($comment_violation !== null) {
            return $comment_violation;
        }
        return cow_merge_wordpress_parent_reference_violation($source, $target, $meta, $source_branch, 'wp_comments row', 'wp_users', 'ID', $source_row['user_id'] ?? null, 'user', $source_action);
    }
    if ($table === 'wp_commentmeta') {
        $comment_violation = cow_merge_wordpress_parent_reference_violation($source, $target, $meta, $source_branch, 'wp_commentmeta row', 'wp_comments', 'comment_ID', $source_row['comment_id'] ?? null, 'comment', $source_action);
        if ($comment_violation !== null) {
            return $comment_violation;
        }
        $comment = cow_merge_wordpress_source_row($source, 'wp_comments', 'comment_ID', $source_row['comment_id'] ?? null);
        if ($comment !== null) {
            return cow_merge_wordpress_comment_reference_violation($source, $target, $meta, $source_branch, 'wp_commentmeta row', $comment, $source_action);
        }
    }
    if ($table === 'wp_options' || str_ends_with($table, '_options')) {
        return cow_merge_wordpress_option_reference_violation($source, $target, $meta, $source_branch, $source_row, $source_action);
    }
    if ($table === 'wp_termmeta') {
        return cow_merge_wordpress_parent_reference_violation($source, $target, $meta, $source_branch, 'wp_termmeta row', 'wp_terms', 'term_id', $source_row['term_id'] ?? null, 'term', $source_action);
    }
    if ($table === 'wp_term_taxonomy') {
        $term_violation = cow_merge_wordpress_parent_reference_violation($source, $target, $meta, $source_branch, 'wp_term_taxonomy row', 'wp_terms', 'term_id', $source_row['term_id'] ?? null, 'term', $source_action);
        if ($term_violation !== null) {
            return $term_violation;
        }
        return cow_merge_wordpress_parent_reference_violation($source, $target, $meta, $source_branch, 'wp_term_taxonomy row', 'wp_terms', 'term_id', $source_row['parent'] ?? null, 'parent term', $source_action);
    }
    if ($table === 'wp_term_relationships') {
        $post_violation = cow_merge_wordpress_parent_reference_violation($source, $target, $meta, $source_branch, 'wp_term_relationships row', 'wp_posts', 'ID', $source_row['object_id'] ?? null, 'post', $source_action);
        if ($post_violation !== null) {
            return $post_violation;
        }
        $taxonomy_violation = cow_merge_wordpress_parent_reference_violation($source, $target, $meta, $source_branch, 'wp_term_relationships row', 'wp_term_taxonomy', 'term_taxonomy_id', $source_row['term_taxonomy_id'] ?? null, 'term taxonomy', $source_action);
        if ($taxonomy_violation !== null) {
            return $taxonomy_violation;
        }
        $term_taxonomy = cow_merge_wordpress_source_row($source, 'wp_term_taxonomy', 'term_taxonomy_id', $source_row['term_taxonomy_id'] ?? null);
        if ($term_taxonomy !== null) {
            return cow_merge_wordpress_parent_reference_violation($source, $target, $meta, $source_branch, 'wp_term_relationships row', 'wp_terms', 'term_id', $term_taxonomy['term_id'] ?? null, 'term', $source_action);
        }
    }
    return null;
}

function cow_merge_wordpress_delete_reference_violation(
    SQLite3 $source,
    SQLite3 $target,
    SQLite3 $meta,
    string $source_branch,
    string $table,
    array $base_row
): ?string {
    if ($table !== 'wp_term_relationships' || !cow_merge_schema_object_exists($source, 'wp_term_relationships')) {
        return null;
    }
    $object_id = $base_row['object_id'] ?? null;
    $base_term_taxonomy_id = $base_row['term_taxonomy_id'] ?? null;
    if (!is_int($object_id) && !(is_string($object_id) && preg_match('/^-?\d+$/', (string)$object_id))) {
        return null;
    }
    if (!is_int($base_term_taxonomy_id) && !(is_string($base_term_taxonomy_id) && preg_match('/^-?\d+$/', (string)$base_term_taxonomy_id))) {
        return null;
    }

    $stmt = cow_merge_prepare_checked(
        $source,
        'SELECT * FROM wp_term_relationships WHERE object_id = :object_id OR term_taxonomy_id = :term_taxonomy_id',
        'failed to prepare WordPress source term relationship replacement lookup'
    );
    cow_merge_bind($stmt, ':object_id', (int)$object_id);
    cow_merge_bind($stmt, ':term_taxonomy_id', (int)$base_term_taxonomy_id);
    $res = cow_merge_execute_checked($stmt, $source, 'failed to inspect WordPress source term relationship replacements');
    try {
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            if (
                cow_merge_values_equal($row['object_id'] ?? null, $object_id)
                && cow_merge_values_equal($row['term_taxonomy_id'] ?? null, $base_term_taxonomy_id)
            ) {
                continue;
            }
            $violation = cow_merge_wordpress_row_reference_violation($source, $target, $meta, $source_branch, $table, $row, 'changed');
            if ($violation !== null) {
                return $violation;
            }
        }
    } finally {
        cow_merge_result_finalize_checked($res, 'failed to finalize WordPress source term relationship replacement lookup');
    }

    return null;
}

function cow_merge_round_up_to_band(int $value, int $band_size): int {
    $remainder = $value % $band_size;
    if ($remainder === 0) {
        return $value;
    }
    return $value + ($band_size - $remainder);
}

function cow_merge_next_autoincrement_band_start(SQLite3 $meta, string $table, int $min_start, int $band_size): int {
    $stmt = cow_merge_prepare_checked(
        $meta,
        'SELECT MAX(band_end) AS max_band_end FROM merge_autoincrement_bands WHERE table_name = :table_name',
        'failed to prepare AUTOINCREMENT band selection'
    );
    cow_merge_bind($stmt, ':table_name', $table);
    $res = cow_merge_execute_checked($stmt, $meta, 'failed to choose AUTOINCREMENT band');
    $row = $res->fetchArray(SQLITE3_ASSOC);
    cow_merge_result_finalize_checked($res, 'failed to finalize AUTOINCREMENT band selection');
    $after_existing_bands = $row && $row['max_band_end'] !== null ? ((int)$row['max_band_end']) + 1 : COW_MERGE_AUTOINCREMENT_FIRST_BAND_START;
    return cow_merge_round_up_to_band(max(COW_MERGE_AUTOINCREMENT_FIRST_BAND_START, $after_existing_bands, $min_start), $band_size);
}

function cow_merge_remember_autoincrement_band(
    SQLite3 $meta,
    int $run_id,
    string $branch,
    string $table,
    int $band_start,
    int $band_end,
    int $band_size,
    bool $new_allocation
): void {
    if ($new_allocation) {
        $stmt = cow_merge_prepare_checked(
            $meta,
            'INSERT INTO merge_autoincrement_bands ' .
            '(branch_name, table_name, band_start, band_end, band_size, allocated_run_id, last_seen_run_id) ' .
            'VALUES (:branch_name, :table_name, :band_start, :band_end, :band_size, :allocated_run_id, :last_seen_run_id) ' .
            'ON CONFLICT(branch_name, table_name) DO UPDATE SET ' .
            'band_start = excluded.band_start, band_end = excluded.band_end, band_size = excluded.band_size, ' .
            'allocated_run_id = excluded.allocated_run_id, last_seen_run_id = excluded.last_seen_run_id, updated_at = CURRENT_TIMESTAMP',
            'failed to prepare AUTOINCREMENT band upsert'
        );
        cow_merge_bind($stmt, ':allocated_run_id', $run_id);
    } else {
        $stmt = cow_merge_prepare_checked(
            $meta,
            'UPDATE merge_autoincrement_bands SET last_seen_run_id = :last_seen_run_id, updated_at = CURRENT_TIMESTAMP ' .
            'WHERE branch_name = :branch_name AND table_name = :table_name',
            'failed to prepare AUTOINCREMENT band refresh'
        );
    }
    cow_merge_bind($stmt, ':branch_name', $branch);
    cow_merge_bind($stmt, ':table_name', $table);
    if ($new_allocation) {
        cow_merge_bind($stmt, ':band_start', $band_start);
        cow_merge_bind($stmt, ':band_end', $band_end);
        cow_merge_bind($stmt, ':band_size', $band_size);
    }
    cow_merge_bind($stmt, ':last_seen_run_id', $run_id);
    cow_merge_execute_checked($stmt, $meta, 'failed to remember AUTOINCREMENT band');
}

function cow_merge_allocate_autoincrement_bands(
    string $db_path,
    string $metadata_db,
    string $branch,
    int $band_size = COW_MERGE_AUTOINCREMENT_BAND_SIZE
): array {
    if (!is_file($db_path)) {
        throw new RuntimeException("SQLite database does not exist: $db_path");
    }
    cow_merge_mkdir_p(dirname($metadata_db));

    $db = cow_merge_open_db($db_path, SQLITE3_OPEN_READWRITE);
    $meta = cow_merge_open_db($metadata_db, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    cow_merge_ensure_metadata($meta);
    $run_id = cow_merge_start_id_band_run($meta, $branch, $db_path);

    $tables = 0;
    $allocated = 0;
    $reused = 0;
    $advanced = 0;
    $skipped_plain_integer_pk = 0;
    $db_transaction_active = false;
    $metadata_transaction_active = false;
    $db_committed = false;
    $db_snapshot = cow_merge_snapshot_sqlite_db($db_path);
    $preserve_db_snapshot = false;
    try {
        cow_merge_exec_checked($db, 'BEGIN IMMEDIATE', 'failed to start AUTOINCREMENT target database transaction');
        $db_transaction_active = true;
        cow_merge_exec_checked($meta, 'BEGIN IMMEDIATE', 'failed to start AUTOINCREMENT metadata transaction');
        $metadata_transaction_active = true;
        foreach (cow_merge_autoincrement_tables($db) as $table) {
            $tables++;
            $max_rowid = cow_merge_table_max_rowid($db, $table);
            $old_seq = cow_merge_sqlite_sequence_value($db, $table);
            $current_floor = max($max_rowid, $old_seq);
            $existing = cow_merge_lookup_autoincrement_band($meta, $branch, $table);
            $new_allocation = false;

            if (
                $existing !== null
                && $current_floor >= ((int)$existing['band_start']) - 1
                && $current_floor <= (int)$existing['band_end']
            ) {
                $band_start = (int)$existing['band_start'];
                $band_end = (int)$existing['band_end'];
                $band_size_for_table = (int)$existing['band_size'];
                $reused++;
            } else {
                $band_size_for_table = $band_size;
                $band_start = cow_merge_next_autoincrement_band_start($meta, $table, $current_floor + 1, $band_size_for_table);
                $band_end = $band_start + $band_size_for_table - 1;
                $new_allocation = true;
                $allocated++;
            }

            $target_seq = max($old_seq, $max_rowid, $band_start - 1);
            if ($target_seq > $old_seq) {
                cow_merge_set_sqlite_sequence($db, $table, $target_seq);
                $advanced++;
            }
            cow_merge_remember_autoincrement_band($meta, $run_id, $branch, $table, $band_start, $band_end, $band_size_for_table, $new_allocation);
            cow_merge_record_decision(
                $meta,
                $run_id,
                $table,
                null,
                'sqlite_sequence',
                $new_allocation ? 'id-band-allocated' : 'id-band-reused',
                'branch AUTOINCREMENT sequence reserved to avoid merge-time ID collisions',
                ['seq' => $old_seq, 'max_rowid' => $max_rowid],
                ['band_start' => $band_start, 'band_end' => $band_end, 'band_size' => $band_size_for_table],
                ['seq' => $old_seq],
                ['seq' => $target_seq]
            );
        }
        foreach (cow_merge_plain_integer_primary_key_tables($db) as $table) {
            $skipped_plain_integer_pk++;
            $max_rowid = cow_merge_table_max_rowid($db, $table);
            cow_merge_record_decision(
                $meta,
                $run_id,
                $table,
                null,
                null,
                'id-band-skipped',
                'plain INTEGER PRIMARY KEY tables do not have a durable sqlite_sequence reservation point',
                ['max_rowid' => $max_rowid],
                ['strategy' => 'state-based-merge'],
                ['max_rowid' => $max_rowid],
                ['max_rowid' => $max_rowid]
            );
        }
        cow_merge_exec_checked($db, 'COMMIT', 'failed to commit AUTOINCREMENT target database transaction');
        $db_transaction_active = false;
        $db_committed = true;
        cow_merge_finish_run($meta, $run_id, 'id_bands_allocated');
        cow_merge_exec_checked($meta, 'COMMIT', 'failed to commit AUTOINCREMENT metadata transaction');
        $metadata_transaction_active = false;
        return [
            'run_id' => $run_id,
            'status' => 'id_bands_allocated',
            'tables' => $tables,
            'allocated' => $allocated,
            'reused' => $reused,
            'advanced' => $advanced,
            'skipped_plain_integer_pk' => $skipped_plain_integer_pk,
            'metadata_db' => $metadata_db,
        ];
    } catch (Throwable $e) {
        if ($db_committed) {
            if ($metadata_transaction_active) {
                @$meta->exec('ROLLBACK');
                $metadata_transaction_active = false;
            }
            try {
                $db->close();
                $db = null;
                cow_merge_restore_sqlite_snapshot($db_snapshot);
            } catch (Throwable $rollback_error) {
                $preserve_db_snapshot = true;
                $run_context = cow_merge_run_context($meta, $run_id);
                $original_failure = cow_merge_failure_reason($e);
                $rollback_failure = cow_merge_failure_reason($rollback_error);
                $rollback_artifacts = [
                    'target_db_snapshot' => cow_merge_sqlite_snapshot_artifact($db_snapshot),
                ];
                cow_merge_finish_run(
                    $meta,
                    $run_id,
                    'failed',
                    $e->getMessage() . '; target database rollback failed: ' . $rollback_error->getMessage()
                );
                cow_merge_record_rollback_failure_artifact(
                    $metadata_db,
                    $run_id,
                    $run_context['source_branch'],
                    $run_context['target_branch'],
                    $run_context['base_db'],
                    $run_context['source_db'],
                    $run_context['target_db'],
                    $original_failure,
                    $rollback_failure,
                    $rollback_artifacts
                );
                throw new CowMergeRollbackFailureException(
                    $e->getMessage() . '; target database rollback failed: ' . $rollback_error->getMessage(),
                    $original_failure,
                    $rollback_failure,
                    $rollback_artifacts,
                    $e
                );
            }
        } elseif ($db_transaction_active) {
            @$db->exec('ROLLBACK');
        }
        if ($metadata_transaction_active) {
            @$meta->exec('ROLLBACK');
        }
        cow_merge_finish_run($meta, $run_id, 'failed', cow_merge_failure_reason($e));
        throw $e;
    } finally {
        if (!$preserve_db_snapshot) {
            cow_merge_cleanup_sqlite_snapshot($db_snapshot);
        }
        if ($db instanceof SQLite3) {
            $db->close();
        }
        $meta->close();
    }
}

function cow_merge_validate_branch_birth_metadata(
    string $db_path,
    string $metadata_db,
    string $branch
): array {
    if (!is_file($db_path)) {
        throw new RuntimeException("SQLite database does not exist: $db_path");
    }
    if (!is_file($metadata_db)) {
        throw new RuntimeException("merge metadata database does not exist: $metadata_db");
    }

    $db = cow_merge_open_db($db_path, SQLITE3_OPEN_READONLY);
    $meta = cow_merge_open_db($metadata_db, SQLITE3_OPEN_READONLY);
    try {
        foreach (['merge_autoincrement_bands', 'merge_row_identities'] as $table) {
            if (!cow_merge_audit_has_table($meta, $table)) {
                throw new RuntimeException("merge metadata database is missing required table $table");
            }
        }

        $autoincrement_tables = cow_merge_autoincrement_tables($db);
        $keyless_tables = cow_merge_keyless_tables($db);
        $missing = [];
        foreach ($autoincrement_tables as $table) {
            if (cow_merge_lookup_autoincrement_band($meta, $branch, $table) === null) {
                $missing[] = "AUTOINCREMENT ID band for $table";
            }
        }

        $keyless_rows = 0;
        foreach ($keyless_tables as $table) {
            foreach (cow_merge_load_keyless_physical_rows($db, $table) as $entry) {
                $keyless_rows++;
                $rowid = (int)$entry['rowid'];
                if (cow_merge_lookup_row_identity($meta, $branch, $table, $rowid) === null) {
                    $missing[] = "row identity for $table rowid $rowid";
                }
            }
        }

        if ($missing) {
            $preview = implode(', ', array_slice($missing, 0, 8));
            if (count($missing) > 8) {
                $preview .= ', ...';
            }
            throw new RuntimeException("branch '$branch' is missing required merge metadata: $preview");
        }

        return [
            'status' => 'validated',
            'branch' => $branch,
            'autoincrement_tables' => count($autoincrement_tables),
            'keyless_tables' => count($keyless_tables),
            'keyless_rows' => $keyless_rows,
        ];
    } finally {
        $db->close();
        $meta->close();
    }
}

function cow_merge_record_decision(
    SQLite3 $meta,
    int $run_id,
    string $table,
    ?string $identity,
    ?string $column,
    string $decision,
    string $reason,
    mixed $base,
    mixed $source,
    mixed $target,
    mixed $chosen
): void {
    $stmt = cow_merge_prepare_checked(
        $meta,
        'INSERT INTO merge_decisions ' .
        '(run_id, table_name, row_identity, column_name, decision, reason, base_payload, source_payload, target_payload, chosen_payload) ' .
        'VALUES (:run_id, :table_name, :row_identity, :column_name, :decision, :reason, :base_payload, :source_payload, :target_payload, :chosen_payload)',
        'failed to prepare merge decision insert'
    );
    cow_merge_bind($stmt, ':run_id', $run_id);
    cow_merge_bind($stmt, ':table_name', $table);
    cow_merge_bind($stmt, ':row_identity', $identity);
    cow_merge_bind($stmt, ':column_name', $column);
    cow_merge_bind($stmt, ':decision', $decision);
    cow_merge_bind($stmt, ':reason', $reason);
    cow_merge_bind($stmt, ':base_payload', $base === null ? null : cow_merge_payload_json($base));
    cow_merge_bind($stmt, ':source_payload', $source === null ? null : cow_merge_payload_json($source));
    cow_merge_bind($stmt, ':target_payload', $target === null ? null : cow_merge_payload_json($target));
    cow_merge_bind($stmt, ':chosen_payload', $chosen === null ? null : cow_merge_payload_json($chosen));
    cow_merge_execute_checked($stmt, $meta, 'failed to record merge decision');
}

function cow_merge_latest_applied_resolution_choice(SQLite3 $meta, int $conflict_id): ?string {
    $stmt = cow_merge_prepare_checked(
        $meta,
        'SELECT choice FROM merge_resolutions WHERE conflict_id = :conflict_id AND applied = 1 ORDER BY id DESC LIMIT 1',
        'failed to prepare latest resolution lookup'
    );
    cow_merge_bind($stmt, ':conflict_id', $conflict_id);
    $res = cow_merge_execute_checked($stmt, $meta, 'failed to look up latest resolution');
    $row = $res->fetchArray(SQLITE3_ASSOC);
    cow_merge_result_finalize_checked($res, 'failed to finalize latest resolution lookup');
    if (!$row) {
        return null;
    }
    return (string)$row['choice'];
}

function cow_merge_record_conflict(
    SQLite3 $meta,
    int $run_id,
    string $table,
    ?string $identity,
    ?string $column,
    string $type,
    mixed $base,
    mixed $source,
    mixed $target,
    mixed $chosen
): bool {
    $base_payload = cow_merge_payload_json($base);
    $source_payload = cow_merge_payload_json($source);
    $target_payload = cow_merge_payload_json($target);
    $chosen_payload = cow_merge_payload_json($chosen);
    $base_hash = hash('sha256', $base_payload);
    $source_hash = hash('sha256', $source_payload);
    $target_hash = hash('sha256', $target_payload);
    $chosen_hash = hash('sha256', $chosen_payload);
    $existing = cow_merge_prepare_checked(
        $meta,
        'SELECT c.id FROM merge_conflicts c ' .
        'JOIN merge_runs existing_run ON existing_run.id = c.run_id ' .
        'JOIN merge_runs current_run ON current_run.id = :run_id ' .
        'WHERE existing_run.source_branch = current_run.source_branch ' .
        'AND existing_run.target_branch = current_run.target_branch ' .
        'AND c.table_name = :table_name ' .
        'AND ((c.row_identity = :row_identity) OR (c.row_identity IS NULL AND :row_identity IS NULL)) ' .
        'AND ((c.column_name = :column_name) OR (c.column_name IS NULL AND :column_name IS NULL)) ' .
        'AND c.conflict_type = :conflict_type ' .
        'AND c.base_hash = :base_hash AND c.source_hash = :source_hash AND c.target_hash = :target_hash AND c.chosen_hash = :chosen_hash ' .
        'LIMIT 1',
        'failed to prepare merge conflict lookup'
    );
    cow_merge_bind($existing, ':run_id', $run_id);
    cow_merge_bind($existing, ':table_name', $table);
    cow_merge_bind($existing, ':row_identity', $identity);
    cow_merge_bind($existing, ':column_name', $column);
    cow_merge_bind($existing, ':conflict_type', $type);
    cow_merge_bind($existing, ':base_hash', $base_hash);
    cow_merge_bind($existing, ':source_hash', $source_hash);
    cow_merge_bind($existing, ':target_hash', $target_hash);
    cow_merge_bind($existing, ':chosen_hash', $chosen_hash);
    $existing_result = cow_merge_execute_checked($existing, $meta, 'failed to look up existing merge conflict');
    $existing_row = $existing_result->fetchArray(SQLITE3_ASSOC);
    cow_merge_result_finalize_checked($existing_result, 'failed to finalize existing merge conflict lookup');
    if ($existing_row) {
        return cow_merge_latest_applied_resolution_choice($meta, (int)$existing_row['id']) !== 'target';
    }

    $stmt = cow_merge_prepare_checked(
        $meta,
        'INSERT OR IGNORE INTO merge_conflicts ' .
        '(run_id, table_name, row_identity, column_name, conflict_type, base_payload, source_payload, target_payload, chosen_payload, ' .
        'base_hash, source_hash, target_hash, chosen_hash, resolver, resolved_at) ' .
        'VALUES (:run_id, :table_name, :row_identity, :column_name, :conflict_type, :base_payload, :source_payload, :target_payload, :chosen_payload, ' .
        ':base_hash, :source_hash, :target_hash, :chosen_hash, :resolver, CURRENT_TIMESTAMP)',
        'failed to prepare merge conflict insert'
    );
    cow_merge_bind($stmt, ':run_id', $run_id);
    cow_merge_bind($stmt, ':table_name', $table);
    cow_merge_bind($stmt, ':row_identity', $identity);
    cow_merge_bind($stmt, ':column_name', $column);
    cow_merge_bind($stmt, ':conflict_type', $type);
    cow_merge_bind($stmt, ':base_payload', $base_payload);
    cow_merge_bind($stmt, ':source_payload', $source_payload);
    cow_merge_bind($stmt, ':target_payload', $target_payload);
    cow_merge_bind($stmt, ':chosen_payload', $chosen_payload);
    cow_merge_bind($stmt, ':base_hash', $base_hash);
    cow_merge_bind($stmt, ':source_hash', $source_hash);
    cow_merge_bind($stmt, ':target_hash', $target_hash);
    cow_merge_bind($stmt, ':chosen_hash', $chosen_hash);
    cow_merge_bind($stmt, ':resolver', 'target-wins');
    cow_merge_execute_checked($stmt, $meta, 'failed to record merge conflict');
    return true;
}

function cow_merge_file_source_path(string $source_root, string $path): string {
    return rtrim($source_root, "/\\") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
}

function cow_merge_file_target_path(string $target_root, string $path): string {
    return rtrim($target_root, "/\\") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
}

function cow_merge_file_path_payload(string $path, ?array $entry): ?array {
    if ($entry === null) {
        return null;
    }
    $payload = $entry;
    $payload['path'] = $path;
    return $payload;
}

function cow_merge_file_entry_auto_applicable(string $path, ?array $base, ?array $source, ?array $target): bool {
    if ($source === null) {
        return true;
    }
    $source_type = $source['type'] ?? null;
    if ($source_type === 'file') {
        if ($target !== null && ($target['type'] ?? null) !== 'file') {
            return false;
        }
        return $base === null || ($base['type'] ?? null) === 'file';
    }
    if ($source_type === 'dir') {
        if ($target !== null && ($target['type'] ?? null) !== 'dir') {
            return false;
        }
        return $base === null || ($base['type'] ?? null) === 'dir';
    }
    if ($source_type === 'symlink') {
        if (cow_merge_symlink_safety_reason($path, $source) !== null) {
            return false;
        }
        if ($target !== null && ($target['type'] ?? null) !== 'symlink') {
            return false;
        }
        return $base === null || ($base['type'] ?? null) === 'symlink';
    }
    return false;
}

function cow_merge_file_unsupported_source_conflict(
    string $path,
    ?array $source,
    ?array $base = null,
    ?array $target = null
): array {
    if (($source['type'] ?? null) === 'symlink') {
        $reason = cow_merge_symlink_safety_reason($path, $source);
        if ($reason !== null) {
            return [
                'file-unsafe-symlink',
                'source changed a filesystem symlink whose target cannot be safely applied automatically: ' . $reason,
            ];
        }
    }
    $source_type = $source['type'] ?? null;
    $base_type = $base['type'] ?? null;
    $target_type = $target['type'] ?? null;
    if (
        in_array($source_type, ['file', 'dir', 'symlink'], true)
        && (($base_type !== null && $base_type !== $source_type) || ($target_type !== null && $target_type !== $source_type))
    ) {
        return [
            'file-type-replacement-conflict',
            'source changed filesystem path type from ' . ($base_type ?? 'missing') .
            ' to ' . $source_type . '; review before replacing target ' . ($target_type ?? 'missing'),
        ];
    }
    return [
        'file-unsupported-source-change',
        'source changed a filesystem path whose type cannot be applied automatically',
    ];
}

function cow_merge_copy_file_entry(string $source_root, string $target_root, string $path, array $entry): void {
    if (($entry['type'] ?? null) !== 'file') {
        throw new RuntimeException("cannot auto-apply unsupported filesystem entry: $path");
    }
    $source = cow_merge_file_source_path($source_root, $path);
    $target = cow_merge_file_target_path($target_root, $path);
    if (!is_file($source)) {
        throw new RuntimeException("source filesystem path is not a regular file: $source");
    }
    if (is_dir($target) && !is_link($target)) {
        throw new RuntimeException("target filesystem path is a directory: $target");
    }
    cow_merge_mkdir_p(dirname($target));
    $tmp = $target . '.forkpress-merge-' . getmypid() . '-' . bin2hex(random_bytes(4));
    if (!copy($source, $tmp)) {
        throw new RuntimeException("failed to copy $source to $tmp");
    }
    @chmod($tmp, (int)($entry['mode'] ?? 0644));
    if (file_exists($target) || is_link($target)) {
        @unlink($target);
    }
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        throw new RuntimeException("failed to publish merged filesystem path: $target");
    }
}

function cow_merge_apply_symlink_entry(string $source_root, string $target_root, string $path, array $entry): void {
    if (($entry['type'] ?? null) !== 'symlink') {
        throw new RuntimeException("cannot auto-apply unsupported filesystem symlink entry: $path");
    }
    $reason = cow_merge_symlink_safety_reason($path, $entry);
    if ($reason !== null) {
        throw new RuntimeException("cannot auto-apply unsafe filesystem symlink $path: $reason");
    }
    $source = cow_merge_file_source_path($source_root, $path);
    $target = cow_merge_file_target_path($target_root, $path);
    if (!is_link($source)) {
        throw new RuntimeException("source filesystem path is not a symlink: $source");
    }
    $link_target = readlink($source);
    if (!is_string($link_target) || $link_target !== (string)$entry['target']) {
        throw new RuntimeException("source filesystem symlink changed while merging: $source");
    }
    if (is_dir($target) && !is_link($target)) {
        throw new RuntimeException("target filesystem path is a directory: $target");
    }
    cow_merge_mkdir_p(dirname($target));
    if (file_exists($target) || is_link($target)) {
        @unlink($target);
    }
    if (!@symlink((string)$entry['target'], $target)) {
        throw new RuntimeException("failed to create merged filesystem symlink: $target");
    }
}

function cow_merge_apply_dir_entry(string $source_root, string $target_root, string $path, array $entry): void {
    if (($entry['type'] ?? null) !== 'dir') {
        throw new RuntimeException("cannot auto-apply unsupported filesystem directory entry: $path");
    }
    $source = cow_merge_file_source_path($source_root, $path);
    $target = cow_merge_file_target_path($target_root, $path);
    if (!is_dir($source) || is_link($source)) {
        throw new RuntimeException("source filesystem path is not a directory: $source");
    }
    if ((file_exists($target) || is_link($target)) && (!is_dir($target) || is_link($target))) {
        throw new RuntimeException("target filesystem path is not a directory: $target");
    }
    if (!is_dir($target) && !mkdir($target, 0777, true)) {
        throw new RuntimeException("failed to create merged filesystem directory: $target");
    }
    @chmod($target, (int)($entry['mode'] ?? 0755));
}

function cow_merge_delete_file_entry(string $target_root, string $path): void {
    $target = cow_merge_file_target_path($target_root, $path);
    if (!file_exists($target) && !is_link($target)) {
        return;
    }
    if (is_dir($target) && !is_link($target)) {
        throw new RuntimeException("refusing to auto-delete filesystem directory: $target");
    }
    if (!@unlink($target)) {
        throw new RuntimeException("failed to delete filesystem path: $target");
    }
}

function cow_merge_delete_dir_entry(string $target_root, string $path): void {
    $target = cow_merge_file_target_path($target_root, $path);
    if (!file_exists($target) && !is_link($target)) {
        return;
    }
    if (!is_dir($target) || is_link($target)) {
        throw new RuntimeException("target filesystem path is not a directory: $target");
    }
    if (!@rmdir($target)) {
        throw new RuntimeException("failed to delete filesystem directory, expected it to be empty: $target");
    }
}

function cow_merge_branch_root_from_db_path(string $db): string {
    $database_dir = dirname($db);
    $wp_content_dir = dirname($database_dir);
    return dirname($wp_content_dir);
}

function cow_merge_remove_tree(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        if (!@unlink($path)) {
            throw new RuntimeException("failed to remove filesystem path: $path");
        }
        return;
    }
    if (!is_dir($path)) {
        throw new RuntimeException("refusing to remove unsupported filesystem path: $path");
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        $entry_path = $entry->getPathname();
        if ($entry->isDir() && !$entry->isLink()) {
            if (!@rmdir($entry_path)) {
                throw new RuntimeException("failed to remove filesystem directory: $entry_path");
            }
        } elseif (!@unlink($entry_path)) {
            throw new RuntimeException("failed to remove filesystem path: $entry_path");
        }
    }
    if (!@rmdir($path)) {
        throw new RuntimeException("failed to remove filesystem directory: $path");
    }
}

function cow_merge_file_transaction_begin(): array {
    $stage_root = sys_get_temp_dir()
        . DIRECTORY_SEPARATOR
        . 'forkpress-cow-file-merge-'
        . getmypid()
        . '-'
        . bin2hex(random_bytes(4));
    cow_merge_mkdir_p($stage_root);
    return [
        'stage_root' => $stage_root,
        'backups' => [],
        'seen' => [],
    ];
}

function cow_merge_file_transaction_snapshot_path(array &$tx, string $target_root, string $path): void {
    if (isset($tx['seen'][$path])) {
        return;
    }
    $target = cow_merge_file_target_path($target_root, $path);
    $backup = [
        'path' => $path,
        'type' => 'missing',
    ];

    if (is_link($target)) {
        $link_target = readlink($target);
        if (!is_string($link_target)) {
            throw new RuntimeException("failed to read target filesystem symlink: $target");
        }
        $backup['type'] = 'symlink';
        $backup['target'] = $link_target;
    } elseif (is_file($target)) {
        $backup_file = rtrim((string)$tx['stage_root'], "/\\")
            . DIRECTORY_SEPARATOR
            . count($tx['backups'])
            . '.backup';
        if (!copy($target, $backup_file)) {
            throw new RuntimeException("failed to stage target filesystem backup: $target");
        }
        $backup['type'] = 'file';
        $backup['backup_file'] = $backup_file;
        $backup['mode'] = ((int)fileperms($target)) & 0777;
    } elseif (is_dir($target)) {
        $backup['type'] = 'dir';
        $backup['mode'] = ((int)fileperms($target)) & 0777;
    } elseif (file_exists($target)) {
        throw new RuntimeException("refusing to stage unsupported target filesystem path: $target");
    }

    $tx['backups'][] = $backup;
    $tx['seen'][$path] = true;
}

function cow_merge_file_transaction_restore_path(string $target_root, array $backup): void {
    $target = cow_merge_file_target_path($target_root, (string)$backup['path']);
    $type = (string)$backup['type'];

    if ($type === 'dir') {
        if ((file_exists($target) || is_link($target)) && (!is_dir($target) || is_link($target))) {
            cow_merge_remove_tree($target);
        }
        if (!is_dir($target) && !mkdir($target, 0777, true)) {
            throw new RuntimeException("failed to restore filesystem directory: $target");
        }
        @chmod($target, (int)($backup['mode'] ?? 0755));
        return;
    }

    cow_merge_remove_tree($target);
    if ($type === 'missing') {
        return;
    }

    cow_merge_mkdir_p(dirname($target));
    if ($type === 'file') {
        $backup_file = (string)($backup['backup_file'] ?? '');
        if ($backup_file === '' || !is_file($backup_file)) {
            throw new RuntimeException("missing staged filesystem backup for: $target");
        }
        if (!copy($backup_file, $target)) {
            throw new RuntimeException("failed to restore filesystem path: $target");
        }
        @chmod($target, (int)($backup['mode'] ?? 0644));
        return;
    }
    if ($type === 'symlink') {
        if (!@symlink((string)$backup['target'], $target)) {
            throw new RuntimeException("failed to restore filesystem symlink: $target");
        }
        return;
    }

    throw new RuntimeException("unsupported filesystem backup type: $type");
}

function cow_merge_file_transaction_restore(array $tx, string $target_root): void {
    for ($i = count($tx['backups']) - 1; $i >= 0; $i--) {
        cow_merge_file_transaction_restore_path($target_root, $tx['backups'][$i]);
    }
}

function cow_merge_file_transaction_cleanup(array $tx): void {
    $stage_root = (string)($tx['stage_root'] ?? '');
    if ($stage_root !== '') {
        try {
            cow_merge_remove_tree($stage_root);
        } catch (Throwable) {
            // A stale temp backup is less harmful than masking the merge result
            // or the original rollback failure.
        }
    }
}

function cow_merge_file_transaction_artifact(array $tx, ?string $target_root = null): array {
    $backups = isset($tx['backups']) && is_array($tx['backups'])
        ? $tx['backups']
        : [];
    $artifact_backups = [];
    foreach ($backups as $backup) {
        if (!is_array($backup)) {
            continue;
        }
        $backup_file = $backup['backup_file'] ?? null;
        $artifact_backups[] = [
            'path' => (string)($backup['path'] ?? ''),
            'type' => (string)($backup['type'] ?? ''),
            'backup_file' => is_string($backup_file) ? $backup_file : null,
            'backup_exists' => is_string($backup_file) && is_file($backup_file),
        ];
    }

    $stage_root = isset($tx['stage_root']) && is_string($tx['stage_root'])
        ? $tx['stage_root']
        : null;
    return [
        'target_root' => $target_root,
        'stage_root' => $stage_root,
        'stage_root_exists' => is_string($stage_root) && is_dir($stage_root),
        'backup_count' => count($artifact_backups),
        'backups' => $artifact_backups,
    ];
}

function cow_merge_file_root_snapshot_begin(string $target_root): array {
    $manifest = cow_merge_file_manifest_for_root($target_root);
    $tx = cow_merge_file_transaction_begin();
    foreach (array_keys($manifest['entries']) as $path) {
        cow_merge_file_transaction_snapshot_path($tx, $target_root, $path);
    }
    return [
        'entries' => $manifest['entries'],
        'transaction' => $tx,
    ];
}

function cow_merge_file_root_snapshot_restore(array $snapshot, string $target_root): void {
    cow_merge_test_hook('before_file_root_snapshot_restore', $snapshot, $target_root);
    $original_entries = $snapshot['entries'] ?? [];
    if (!is_array($original_entries)) {
        throw new RuntimeException('invalid filesystem root snapshot');
    }

    $current_entries = cow_merge_file_manifest_for_root($target_root)['entries'];
    $added_paths = array_values(array_diff(array_keys($current_entries), array_keys($original_entries)));
    usort($added_paths, static function (string $a, string $b): int {
        return strlen($b) <=> strlen($a) ?: strcmp($b, $a);
    });
    foreach ($added_paths as $path) {
        cow_merge_remove_tree(cow_merge_file_target_path($target_root, $path));
    }

    $tx = $snapshot['transaction'] ?? null;
    if (!is_array($tx)) {
        throw new RuntimeException('invalid filesystem root snapshot transaction');
    }
    cow_merge_file_transaction_restore($tx, $target_root);
}

function cow_merge_file_root_snapshot_cleanup(?array $snapshot): void {
    if ($snapshot === null) {
        return;
    }
    $tx = $snapshot['transaction'] ?? null;
    if (is_array($tx)) {
        cow_merge_file_transaction_cleanup($tx);
    }
}

function cow_merge_file_entry_without_path(?array $entry): ?array {
    if ($entry === null) {
        return null;
    }
    unset($entry['path']);
    return $entry;
}

function cow_merge_validate_current_file_entry(string $root, string $path, ?array $expected, string $label): ?array {
    $entries = cow_merge_file_manifest_for_root($root)['entries'];
    $current = $entries[$path] ?? null;
    if (!cow_merge_file_entries_equal($current, $expected)) {
        throw new RuntimeException("$label filesystem path no longer matches the audited conflict payload; rerun merge-audit before resolving");
    }
    return $current;
}

function cow_merge_apply_file_resolution(
    string $source_root,
    string $target_root,
    string $path,
    ?array $source,
    ?array $target
): void {
    if ($source !== null) {
        $source_type = $source['type'] ?? null;
        if (!in_array($source_type, ['file', 'dir', 'symlink'], true)) {
            [$type, $reason] = cow_merge_file_unsupported_source_conflict($path, $source, null, $target);
            throw new RuntimeException("cannot apply source filesystem conflict $path ($type): $reason");
        }
        if ($source_type === 'symlink') {
            $reason = cow_merge_symlink_safety_reason($path, $source);
            if ($reason !== null) {
                throw new RuntimeException("cannot apply source filesystem conflict $path (file-unsafe-symlink): $reason");
            }
        }
    }

    if ($source === null) {
        if (($target['type'] ?? null) === 'dir') {
            cow_merge_delete_dir_entry($target_root, $path);
        } else {
            cow_merge_delete_file_entry($target_root, $path);
        }
        return;
    }

    $source_type = $source['type'] ?? null;
    $target_path = cow_merge_file_target_path($target_root, $path);
    if ($target !== null && ($target['type'] ?? null) !== $source_type && (file_exists($target_path) || is_link($target_path))) {
        cow_merge_remove_tree($target_path);
    }
    if ($source_type === 'dir') {
        cow_merge_apply_dir_entry($source_root, $target_root, $path, $source);
    } elseif ($source_type === 'symlink') {
        cow_merge_apply_symlink_entry($source_root, $target_root, $path, $source);
    } elseif ($source_type === 'file') {
        cow_merge_copy_file_entry($source_root, $target_root, $path, $source);
    } else {
        throw new RuntimeException("cannot apply unsupported source filesystem entry for $path");
    }
}

function cow_merge_file_has_prefix(string $path, string $prefix): bool {
    return $path !== $prefix && str_starts_with($path, rtrim($prefix, '/') . '/');
}

function cow_merge_file_deleted_dir_is_safe(
    string $path,
    array $base_entries,
    array $source_entries,
    array $target_entries
): bool {
    foreach ($target_entries as $target_path => $target_entry) {
        if (!cow_merge_file_has_prefix($target_path, $path)) {
            continue;
        }
        $base_entry = $base_entries[$target_path] ?? null;
        $source_entry = $source_entries[$target_path] ?? null;
        if ($source_entry !== null || !cow_merge_file_entries_equal($target_entry, $base_entry)) {
            return false;
        }
    }
    return true;
}

function cow_merge_record_file_conflict(
    SQLite3 $meta,
    int $run_id,
    string $path,
    string $type,
    ?array $base,
    ?array $source,
    ?array $target,
    ?array $chosen,
    string $reason
): bool {
    $identity = cow_merge_file_identity_json($path);
    $active = cow_merge_record_conflict(
        $meta,
        $run_id,
        '__files__',
        $identity,
        'path',
        $type,
        cow_merge_file_path_payload($path, $base),
        cow_merge_file_path_payload($path, $source),
        cow_merge_file_path_payload($path, $target),
        cow_merge_file_path_payload($path, $chosen)
    );
    cow_merge_record_decision(
        $meta,
        $run_id,
        '__files__',
        $identity,
        'path',
        $active ? 'target-wins' : 'target-accepted',
        $active ? $reason : 'reviewed target resolution already accepts this filesystem conflict: ' . $reason,
        cow_merge_file_path_payload($path, $base),
        cow_merge_file_path_payload($path, $source),
        cow_merge_file_path_payload($path, $target),
        cow_merge_file_path_payload($path, $chosen)
    );
    return $active;
}

function cow_merge_plugin_identity_json(string $plugin, string $object): string {
    return cow_merge_payload_json([
        'plugin' => $plugin,
        'object' => $object,
    ]);
}

function cow_merge_record_plugin_validator_conflicts(
    string $metadata_db,
    int $run_id,
    array $findings
): array {
    $meta = cow_merge_open_db($metadata_db, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $metadata_transaction_active = false;
    $conflicts = 0;
    try {
        cow_merge_ensure_metadata($meta);
        cow_merge_exec_checked($meta, 'BEGIN IMMEDIATE', 'failed to start plugin validator metadata transaction');
        $metadata_transaction_active = true;
        $stmt = cow_merge_prepare_checked(
            $meta,
            'SELECT 1 FROM merge_runs WHERE id = :run_id',
            'failed to prepare plugin validator merge run lookup'
        );
        cow_merge_bind($stmt, ':run_id', $run_id);
        $res = cow_merge_execute_checked($stmt, $meta, 'failed to execute plugin validator merge run lookup');
        $run_exists = (bool)$res->fetchArray(SQLITE3_NUM);
        cow_merge_result_finalize_checked($res, 'failed to finalize plugin validator merge run lookup');
        if (!$run_exists) {
            throw new InvalidArgumentException("merge run #$run_id does not exist in merge metadata");
        }
        if (!array_is_list($findings)) {
            throw new InvalidArgumentException('plugin validator findings must be a list');
        }
        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                throw new InvalidArgumentException('plugin validator findings must be arrays');
            }
            $plugin = trim((string)($finding['plugin'] ?? ''));
            $object = trim((string)($finding['object'] ?? ''));
            $reason = trim((string)($finding['reason'] ?? ''));
            if ($plugin === '' || $object === '' || $reason === '') {
                throw new InvalidArgumentException('plugin validator findings require plugin, object, and reason');
            }
            $type = trim((string)($finding['type'] ?? 'plugin-validator-conflict'));
            if ($type === '' || !str_starts_with($type, 'plugin-')) {
                throw new InvalidArgumentException('plugin validator conflict type must start with plugin-');
            }
            $payload = [
                'plugin' => $plugin,
                'object' => $object,
                'reason' => $reason,
                'tables' => array_values(array_map('strval', is_array($finding['tables'] ?? null) ? $finding['tables'] : [])),
                'files' => array_values(array_map('strval', is_array($finding['files'] ?? null) ? $finding['files'] : [])),
                'validator' => (string)($finding['validator'] ?? ''),
                'candidate' => $finding['candidate'] ?? null,
            ];
            if (cow_merge_record_conflict(
                $meta,
                $run_id,
                '__plugins__',
                cow_merge_plugin_identity_json($plugin, $object),
                null,
                $type,
                $finding['base'] ?? null,
                $finding['source'] ?? null,
                $finding['target'] ?? null,
                $payload
            )) {
                $conflicts++;
            }
        }
        if ($conflicts > 0) {
            cow_merge_finish_run($meta, $run_id, 'completed_with_conflicts');
        }
        cow_merge_exec_checked($meta, 'COMMIT', 'failed to commit plugin validator metadata transaction');
        $metadata_transaction_active = false;
    } catch (Throwable $e) {
        if ($metadata_transaction_active) {
            @$meta->exec('ROLLBACK');
        }
        throw $e;
    } finally {
        $meta->close();
    }

    return [
        'run_id' => $run_id,
        'status' => $conflicts > 0 ? 'completed_with_conflicts' : 'valid',
        'conflicts' => $conflicts,
        'metadata_db' => $metadata_db,
    ];
}

function cow_merge_decode_plugin_validator_stdout(string $stdout, string $validator): array {
    $decoded = json_decode($stdout, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("plugin validator $validator did not emit valid JSON: " . json_last_error_msg());
    }
    if (is_array($decoded) && array_is_list($decoded)) {
        return [
            'validator_status' => count($decoded) > 0 ? 'conflicts' : 'valid',
            'findings' => $decoded,
        ];
    }
    if (!is_array($decoded)) {
        throw new RuntimeException("plugin validator $validator must emit a JSON array or object");
    }
    $status = (string)($decoded['status'] ?? '');
    if (!in_array($status, ['valid', 'conflicts', 'failed'], true)) {
        throw new RuntimeException("plugin validator $validator emitted an invalid status");
    }
    if ($status === 'failed') {
        $reason = trim((string)($decoded['reason'] ?? ''));
        throw new RuntimeException("plugin validator $validator failed" . ($reason === '' ? '' : ": $reason"));
    }
    $findings = $decoded['findings'] ?? [];
    if (!is_array($findings) || !array_is_list($findings)) {
        throw new RuntimeException("plugin validator $validator findings must be a JSON array");
    }
    return [
        'validator_status' => $status,
        'findings' => $findings,
    ];
}

function cow_merge_plugin_validator_command(string $validator): array {
    $validator = trim($validator);
    if ($validator === '') {
        throw new InvalidArgumentException('--validator is required');
    }
    if (!is_file($validator)) {
        throw new InvalidArgumentException("--validator must point to a file: $validator");
    }
    if (strtolower(pathinfo($validator, PATHINFO_EXTENSION)) === 'php') {
        return [PHP_BINARY, $validator];
    }
    if (!is_executable($validator)) {
        throw new InvalidArgumentException("--validator must be executable or a PHP script: $validator");
    }
    return [$validator];
}

function cow_merge_run_plugin_validator(string $metadata_db, int $run_id, string $validator): array {
    $command = cow_merge_plugin_validator_command($validator);
    $meta = cow_merge_open_db($metadata_db, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    try {
        cow_merge_ensure_metadata($meta);
        $context = cow_merge_run_context($meta, $run_id);
    } finally {
        $meta->close();
    }
    if ($context['source_branch'] === '' && $context['target_branch'] === '') {
        throw new InvalidArgumentException("merge run #$run_id does not exist in merge metadata");
    }

    $env = array_merge($_ENV, [
        'FORKPRESS_MERGE_METADATA_DB' => $metadata_db,
        'FORKPRESS_MERGE_RUN' => (string)$run_id,
        'FORKPRESS_MERGE_SOURCE_BRANCH' => $context['source_branch'],
        'FORKPRESS_MERGE_TARGET_BRANCH' => $context['target_branch'],
        'FORKPRESS_MERGE_BASE_DB' => $context['base_db'],
        'FORKPRESS_MERGE_SOURCE_DB' => $context['source_db'],
        'FORKPRESS_MERGE_TARGET_DB' => $context['target_db'],
        'FORKPRESS_MERGE_BASE_ROOT' => $context['base_db'] === '' ? '' : cow_merge_branch_root_from_db_path($context['base_db']),
        'FORKPRESS_MERGE_SOURCE_ROOT' => $context['source_db'] === '' ? '' : cow_merge_branch_root_from_db_path($context['source_db']),
        'FORKPRESS_MERGE_TARGET_ROOT' => $context['target_db'] === '' ? '' : cow_merge_branch_root_from_db_path($context['target_db']),
    ]);
    $shell_command = implode(' ', array_map('escapeshellarg', $command));
    $pipes = [];
    $process = proc_open(
        $shell_command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        null,
        $env
    );
    if (!is_resource($process)) {
        throw new RuntimeException("failed to start plugin validator $validator");
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit_status = proc_close($process);
    if ($exit_status !== 0) {
        $stderr = trim(is_string($stderr) ? $stderr : '');
        throw new RuntimeException("plugin validator $validator exited with status $exit_status" . ($stderr === '' ? '' : ": $stderr"));
    }
    $decoded = cow_merge_decode_plugin_validator_stdout(is_string($stdout) ? $stdout : '', $validator);
    $result = cow_merge_record_plugin_validator_conflicts($metadata_db, $run_id, $decoded['findings']);
    $result['validator'] = $validator;
    $result['validator_status'] = $decoded['validator_status'];
    return $result;
}

function cow_merge_unique_plugin_validator_paths(array $validators): array {
    $seen = [];
    $out = [];
    foreach ($validators as $validator) {
        $validator = trim((string)$validator);
        if ($validator === '') {
            continue;
        }
        $key = realpath($validator);
        if (!is_string($key)) {
            $key = $validator;
        }
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $validator;
    }
    return $out;
}

function cow_merge_wordpress_option_tables(SQLite3 $db): array {
    $tables = [];
    $res = cow_merge_query_checked(
        $db,
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        'failed to list WordPress option tables for plugin validator discovery'
    );
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $name = (string)$row['name'];
        if ($name === 'wp_options' || str_ends_with($name, '_options')) {
            $tables[] = $name;
        }
    }
    cow_merge_result_finalize_checked($res, 'failed to finalize WordPress option table discovery');
    usort($tables, function (string $a, string $b): int {
        if ($a === 'wp_options') {
            return $b === 'wp_options' ? 0 : -1;
        }
        if ($b === 'wp_options') {
            return 1;
        }
        return strcmp($a, $b);
    });
    return $tables;
}

function cow_merge_active_wordpress_plugins(string $target_db): array {
    if (!is_file($target_db)) {
        return [];
    }
    $db = cow_merge_open_db($target_db, SQLITE3_OPEN_READONLY);
    try {
        foreach (cow_merge_wordpress_option_tables($db) as $table) {
            $stmt = cow_merge_prepare_checked(
                $db,
                'SELECT option_value FROM ' . cow_merge_quote_ident($table) . ' WHERE option_name = :name LIMIT 1',
                "failed to prepare active plugin lookup in $table"
            );
            cow_merge_bind($stmt, ':name', 'active_plugins');
            $res = cow_merge_execute_checked($stmt, $db, "failed to read active plugins from $table");
            $row = $res->fetchArray(SQLITE3_ASSOC);
            cow_merge_result_finalize_checked($res, "failed to finalize active plugin lookup in $table");
            if (!is_array($row)) {
                continue;
            }
            $decoded = @unserialize((string)($row['option_value'] ?? ''), ['allowed_classes' => false]);
            if (!is_array($decoded)) {
                continue;
            }
            $plugins = [];
            foreach ($decoded as $plugin) {
                if (!is_string($plugin)) {
                    continue;
                }
                $plugin = cow_merge_normalize_relative_path($plugin);
                if ($plugin === null || $plugin === '') {
                    continue;
                }
                $plugins[] = $plugin;
            }
            return array_values(array_unique($plugins));
        }
        return [];
    } finally {
        $db->close();
    }
}

function cow_merge_discover_mu_plugin_validators(string $target_root): array {
    $mu_dir = rtrim($target_root, DIRECTORY_SEPARATOR) . '/wp-content/mu-plugins';
    if (!is_dir($mu_dir)) {
        return [];
    }
    $validators = [];
    $direct = $mu_dir . '/forkpress-merge-validator.php';
    if (is_file($direct)) {
        $validators[] = $direct;
    }
    foreach ([$mu_dir . '/*.forkpress-merge-validator.php', $mu_dir . '/*/forkpress-merge-validator.php'] as $pattern) {
        $matches = glob($pattern);
        if (!is_array($matches)) {
            continue;
        }
        sort($matches, SORT_STRING);
        foreach ($matches as $match) {
            if (is_file($match)) {
                $validators[] = $match;
            }
        }
    }
    return $validators;
}

function cow_merge_active_plugin_validator_path(string $target_root, string $active_plugin): ?string {
    $active_plugin = cow_merge_normalize_relative_path($active_plugin);
    if ($active_plugin === null || $active_plugin === '') {
        return null;
    }
    $plugins_dir = rtrim($target_root, DIRECTORY_SEPARATOR) . '/wp-content/plugins';
    $plugin_dir = dirname($active_plugin);
    if ($plugin_dir === '.' || $plugin_dir === '') {
        $base = pathinfo($active_plugin, PATHINFO_FILENAME);
        $validator = $plugins_dir . '/' . $base . '.forkpress-merge-validator.php';
    } else {
        $validator = $plugins_dir . '/' . $plugin_dir . '/forkpress-merge-validator.php';
    }
    return is_file($validator) ? $validator : null;
}

function cow_merge_discover_plugin_validators(string $target_db, ?string $target_root): array {
    if ($target_root === null || $target_root === '' || !is_dir($target_root)) {
        return [];
    }
    $validators = cow_merge_discover_mu_plugin_validators($target_root);
    foreach (cow_merge_active_wordpress_plugins($target_db) as $active_plugin) {
        $validator = cow_merge_active_plugin_validator_path($target_root, $active_plugin);
        if ($validator !== null) {
            $validators[] = $validator;
        }
    }
    return cow_merge_unique_plugin_validator_paths($validators);
}

function cow_merge_record_matching_file_decision(
    SQLite3 $meta,
    int $run_id,
    string $path,
    ?array $base,
    ?array $source,
    ?array $target
): void {
    if ($source === null) {
        $reason = 'source and target deleted the same filesystem path';
    } elseif ($base === null) {
        $reason = 'source and target added the same filesystem path';
    } else {
        $reason = 'source and target changed filesystem path to the same state';
    }
    cow_merge_record_decision(
        $meta,
        $run_id,
        '__files__',
        cow_merge_file_identity_json($path),
        'path',
        'source-applied',
        $reason,
        cow_merge_file_path_payload($path, $base),
        cow_merge_file_path_payload($path, $source),
        cow_merge_file_path_payload($path, $target),
        cow_merge_file_path_payload($path, $target)
    );
}

function cow_merge_files(
    string $base_files,
    string $source_root,
    string $target_root,
    string $metadata_db,
    int $run_id
): array {
    if (!is_dir($source_root)) {
        throw new RuntimeException("source filesystem root does not exist: $source_root");
    }
    if (!is_dir($target_root)) {
        throw new RuntimeException("target filesystem root does not exist: $target_root");
    }
    $base_entries = cow_merge_read_file_base($base_files);
    $source_entries = cow_merge_file_manifest_for_root($source_root)['entries'];
    $target_entries = cow_merge_file_manifest_for_root($target_root)['entries'];
    $meta = cow_merge_open_db($metadata_db, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    cow_merge_ensure_metadata($meta);

    $paths = array_unique(array_merge(array_keys($base_entries), array_keys($source_entries), array_keys($target_entries)));
    sort($paths);
    $operations = [];
    $applied = 0;
    $conflicts = 0;
    $target_kept_subtree_conflict_prefixes = [];
    $metadata_transaction_active = false;

    try {
        cow_merge_exec_checked($meta, 'BEGIN IMMEDIATE', 'failed to start filesystem merge metadata transaction');
        $metadata_transaction_active = true;
        foreach ($paths as $path) {
            $base = $base_entries[$path] ?? null;
            $source = $source_entries[$path] ?? null;
            $target = $target_entries[$path] ?? null;

            foreach ($target_kept_subtree_conflict_prefixes as $prefix) {
                if (!cow_merge_file_has_prefix($path, $prefix)) {
                    continue;
                }
                if (!cow_merge_file_entries_equal($target, $base)) {
                    continue;
                }
                cow_merge_record_decision(
                    $meta,
                    $run_id,
                    '__files__',
                    cow_merge_file_identity_json($path),
                    'path',
                    'target-kept',
                    'target subtree kept because parent filesystem replacement requires review',
                    cow_merge_file_path_payload($path, $base),
                    cow_merge_file_path_payload($path, $source),
                    cow_merge_file_path_payload($path, $target),
                    cow_merge_file_path_payload($path, $target)
                );
                continue 2;
            }

            if (cow_merge_file_entries_equal($source, $base)) {
                if (!cow_merge_file_entries_equal($target, $base)) {
                    if ($base === null && $target !== null) {
                        $reason = 'target added filesystem path while source did not have it';
                    } elseif ($base !== null && $target === null) {
                        $reason = 'target deleted filesystem path while source did not change it';
                    } else {
                        $reason = 'target changed filesystem path while source did not change it';
                    }
                    cow_merge_record_decision(
                        $meta,
                        $run_id,
                        '__files__',
                        cow_merge_file_identity_json($path),
                        'path',
                        'target-kept',
                        $reason,
                        cow_merge_file_path_payload($path, $base),
                        cow_merge_file_path_payload($path, $source),
                        cow_merge_file_path_payload($path, $target),
                        cow_merge_file_path_payload($path, $target)
                    );
                }
                continue;
            }
            if (cow_merge_file_entries_equal($source, $target)) {
                cow_merge_record_matching_file_decision($meta, $run_id, $path, $base, $source, $target);
                $applied++;
                continue;
            }

            if (cow_merge_file_entries_equal($target, $base)) {
                if (!cow_merge_file_entry_auto_applicable($path, $base, $source, $target)) {
                    [$conflict_type, $conflict_reason] = cow_merge_file_unsupported_source_conflict($path, $source, $base, $target);
                    if (cow_merge_record_file_conflict(
                        $meta,
                        $run_id,
                        $path,
                        $conflict_type,
                        $base,
                        $source,
                        $target,
                        $target,
                        $conflict_reason
                    )) {
                        $conflicts++;
                    }
                    if (
                        $conflict_type === 'file-type-replacement-conflict'
                        && (($base['type'] ?? null) === 'dir' || ($target['type'] ?? null) === 'dir')
                        && ($source['type'] ?? null) !== 'dir'
                    ) {
                        $target_kept_subtree_conflict_prefixes[] = $path;
                    }
                    continue;
                }
                if (
                    $source === null
                    && ($base['type'] ?? null) === 'dir'
                    && !cow_merge_file_deleted_dir_is_safe($path, $base_entries, $source_entries, $target_entries)
                ) {
                    if (cow_merge_record_file_conflict(
                        $meta,
                        $run_id,
                        $path,
                        'file-directory-delete-conflict',
                        $base,
                        $source,
                        $target,
                        $target,
                        'source deleted a filesystem directory that has target-side descendants'
                    )) {
                        $conflicts++;
                    }
                    continue;
                }
                if ($source === null) {
                    $action = ($base['type'] ?? null) === 'dir' ? 'delete-dir' : 'delete-file';
                } else {
                    $source_type = $source['type'] ?? null;
                    $action = match ($source_type) {
                        'dir' => 'apply-dir',
                        'symlink' => 'apply-symlink',
                        default => 'copy-file',
                    };
                }
                $operations[] = [
                    'path' => $path,
                    'base' => $base,
                    'source' => $source,
                    'target' => $target,
                    'action' => $action,
                ];
                continue;
            }

            $type = 'file-conflict';
            $reason = 'source and target changed the same filesystem path differently';
            if ($base === null && $source !== null && $target !== null) {
                $type = 'file-add-collision';
                $reason = 'source and target independently added the same filesystem path differently';
            } elseif ($target === null) {
                $type = 'file-target-deleted';
                $reason = 'target deleted a filesystem path while source changed it';
            } elseif ($source === null) {
                $type = 'file-source-deleted';
                $reason = 'source deleted a filesystem path while target changed it';
            }
            if (cow_merge_record_file_conflict($meta, $run_id, $path, $type, $base, $source, $target, $target, $reason)) {
                $conflicts++;
            }
        }
    } catch (Throwable $e) {
        if ($metadata_transaction_active) {
            @$meta->exec('ROLLBACK');
            $metadata_transaction_active = false;
        }
        $meta->close();
        throw $e;
    }

    usort($operations, function (array $a, array $b): int {
        $rank = [
            'delete-file' => 0,
            'delete-dir' => 1,
            'apply-dir' => 2,
            'apply-symlink' => 3,
            'copy-file' => 4,
        ];
        $rank_a = $rank[$a['action']] ?? 99;
        $rank_b = $rank[$b['action']] ?? 99;
        if ($rank_a !== $rank_b) {
            return $rank_a <=> $rank_b;
        }
        if ($a['action'] === 'delete-dir') {
            return strlen($b['path']) <=> strlen($a['path']) ?: strcmp($b['path'], $a['path']);
        }
        if ($a['action'] === 'apply-dir') {
            return strlen($a['path']) <=> strlen($b['path']) ?: strcmp($a['path'], $b['path']);
        }
        return strcmp($a['path'], $b['path']);
    });

    $run_context = cow_merge_run_context($meta, $run_id);
    $file_tx = cow_merge_file_transaction_begin();
    $file_tx_committed = false;
    $preserve_file_tx = false;
    $crash_recovery_artifact = null;
    try {
        foreach ($operations as $op) {
            cow_merge_file_transaction_snapshot_path($file_tx, $target_root, $op['path']);
            if ($op['action'] === 'delete-file') {
                cow_merge_delete_file_entry($target_root, $op['path']);
                $reason = 'source deleted filesystem path and target did not change it';
            } elseif ($op['action'] === 'delete-dir') {
                cow_merge_delete_dir_entry($target_root, $op['path']);
                $reason = 'source deleted filesystem directory and target did not change it';
            } elseif ($op['action'] === 'apply-dir') {
                cow_merge_apply_dir_entry($source_root, $target_root, $op['path'], $op['source']);
                $reason = $op['base'] === null
                    ? 'source added filesystem directory and target did not have it'
                    : 'source changed filesystem directory metadata and target did not change it';
            } elseif ($op['action'] === 'apply-symlink') {
                cow_merge_apply_symlink_entry($source_root, $target_root, $op['path'], $op['source']);
                $reason = $op['base'] === null
                    ? 'source added filesystem symlink and target did not have it'
                    : 'source changed filesystem symlink target and target did not change it';
            } else {
                cow_merge_copy_file_entry($source_root, $target_root, $op['path'], $op['source']);
                $reason = $op['base'] === null
                    ? 'source added filesystem path and target did not have it'
                    : 'source changed filesystem path and target did not change it';
            }
            $crash_recovery_artifact = cow_merge_write_crash_recovery_artifact(
                $metadata_db,
                $run_id,
                'file-op',
                $run_context,
                null,
                $file_tx,
                $target_root
            );
            cow_merge_failpoint('after-file-op');
            cow_merge_record_decision(
                $meta,
                $run_id,
                '__files__',
                cow_merge_file_identity_json($op['path']),
                'path',
                'source-applied',
                $reason,
                cow_merge_file_path_payload($op['path'], $op['base']),
                cow_merge_file_path_payload($op['path'], $op['source']),
                cow_merge_file_path_payload($op['path'], $op['target']),
                cow_merge_file_path_payload($op['path'], $op['source'])
            );
            $applied++;
        }
        cow_merge_exec_checked($meta, 'COMMIT', 'failed to commit filesystem merge metadata transaction');
        $metadata_transaction_active = false;
        $file_tx_committed = true;
        cow_merge_remove_crash_recovery_artifact($crash_recovery_artifact);
        $crash_recovery_artifact = null;
    } catch (Throwable $e) {
        if ($metadata_transaction_active) {
            @$meta->exec('ROLLBACK');
            $metadata_transaction_active = false;
        }
        if (!$file_tx_committed) {
            try {
                cow_merge_file_transaction_restore($file_tx, $target_root);
                cow_merge_remove_crash_recovery_artifact($crash_recovery_artifact);
                $crash_recovery_artifact = null;
            } catch (Throwable $rollback_error) {
                $preserve_file_tx = true;
                $original_failure = cow_merge_failure_reason($e);
                $rollback_failure = cow_merge_failure_reason($rollback_error);
                $rollback_artifacts = [
                    'filesystem_transaction' => cow_merge_file_transaction_artifact($file_tx, $target_root),
                ];
                cow_merge_record_rollback_failure_artifact(
                    $metadata_db,
                    $run_id,
                    $run_context['source_branch'],
                    $run_context['target_branch'],
                    $run_context['base_db'],
                    $run_context['source_db'],
                    $run_context['target_db'],
                    $original_failure,
                    $rollback_failure,
                    $rollback_artifacts
                );
                throw new CowMergeRollbackFailureException(
                    $e->getMessage() . '; filesystem rollback failed: ' . $rollback_error->getMessage(),
                    $original_failure,
                    $rollback_failure,
                    $rollback_artifacts,
                    $e
                );
            }
        }
        throw $e;
    } finally {
        if (!$preserve_file_tx) {
            cow_merge_file_transaction_cleanup($file_tx);
        }
        $meta->close();
    }

    return ['applied' => $applied, 'conflicts' => $conflicts];
}

function cow_merge_audit_limit(?string $value): int {
    if ($value === null || $value === '') {
        return 20;
    }
    if (!ctype_digit($value)) {
        throw new InvalidArgumentException('--limit must be a positive integer');
    }
    $limit = (int)$value;
    if ($limit < 1 || $limit > 500) {
        throw new InvalidArgumentException('--limit must be between 1 and 500');
    }
    return $limit;
}

function cow_merge_audit_run_id(?string $value): ?int {
    if ($value === null || $value === '') {
        return null;
    }
    if (!ctype_digit($value) || (int)$value < 1) {
        throw new InvalidArgumentException('--run must be a positive integer');
    }
    return (int)$value;
}

function cow_merge_audit_format(?string $value): string {
    $format = $value ?? 'text';
    if ($format !== 'text' && $format !== 'json') {
        throw new InvalidArgumentException('--format must be text or json');
    }
    return $format;
}

function cow_merge_audit_scope(?string $value): string {
    $scope = $value ?? 'all';
    if (!in_array($scope, ['all', 'db', 'files', 'plugin'], true)) {
        throw new InvalidArgumentException('--scope must be all, db, files, or plugin');
    }
    return $scope;
}

function cow_merge_audit_records(?string $value): string {
    $records = $value ?? 'all';
    if (!in_array($records, ['all', 'conflicts', 'decisions', 'resolutions', 'rollback-failures'], true)) {
        throw new InvalidArgumentException('--records must be all, conflicts, decisions, resolutions, or rollback-failures');
    }
    return $records;
}

function cow_merge_audit_filter_text(?string $value, string $name): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    if (str_contains($value, "\0")) {
        throw new InvalidArgumentException("--$name must not contain NUL bytes");
    }
    return $value;
}

function cow_merge_audit_review_status_filter(?string $value): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    if (!in_array($value, ['unreviewed', 'pending', 'needs-action', 'reviewed'], true)) {
        throw new InvalidArgumentException('--review-status must be unreviewed, pending, needs-action, or reviewed');
    }
    return $value;
}

function cow_merge_audit_resolution_status_filter(?string $value): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    if (!in_array($value, ['validated', 'applied'], true)) {
        throw new InvalidArgumentException('--resolution-status must be validated or applied');
    }
    return $value;
}

function cow_merge_audit_group_by(?string $value): string {
    $group_by = $value ?? 'none';
    if (!in_array($group_by, ['none', 'table', 'status', 'path', 'type', 'severity'], true)) {
        throw new InvalidArgumentException('--group-by must be none, table, status, path, type, or severity');
    }
    return $group_by;
}

function cow_merge_audit_file_path_filter(?string $value, string $name): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    if (str_contains($value, "\0")) {
        throw new InvalidArgumentException("--$name must not contain NUL bytes");
    }
    $path = cow_merge_path_to_unix($value);
    $path = ltrim($path, '/');
    if ($path === '' || $path === '.' || str_contains($path, '/../') || str_starts_with($path, '../')) {
        throw new InvalidArgumentException("--$name must be a relative merge path");
    }
    return $path;
}

function cow_merge_review_record_type(?string $value): string {
    if ($value !== 'conflict' && $value !== 'decision' && $value !== 'resolution') {
        throw new InvalidArgumentException('--record must be conflict, decision, or resolution');
    }
    return $value;
}

function cow_merge_review_record_id(?string $value): int {
    if ($value === null || $value === '' || !ctype_digit($value) || (int)$value < 1) {
        throw new InvalidArgumentException('--id must be a positive integer');
    }
    return (int)$value;
}

function cow_merge_review_status(?string $value): string {
    if (!in_array($value, ['pending', 'needs-action', 'reviewed'], true)) {
        throw new InvalidArgumentException('--status must be pending, needs-action, or reviewed');
    }
    return (string)$value;
}

function cow_merge_review_text(?string $value, string $name): string {
    if ($value === null) {
        throw new InvalidArgumentException("--$name is required");
    }
    if (str_contains($value, "\0")) {
        throw new InvalidArgumentException("--$name must not contain NUL bytes");
    }
    $value = trim($value);
    if ($value === '') {
        throw new InvalidArgumentException("--$name must not be empty");
    }
    if (strlen($value) > 4096) {
        throw new InvalidArgumentException("--$name must be 4096 bytes or shorter");
    }
    return $value;
}

function cow_merge_review_record(
    string $metadata_db,
    string $record_type,
    int $record_id,
    string $status,
    string $note,
    string $reviewer
): array {
    cow_merge_mkdir_p(dirname($metadata_db));
    $meta = cow_merge_open_db($metadata_db, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $transaction_started = false;
    try {
        cow_merge_ensure_metadata($meta);
        cow_merge_exec_checked($meta, 'BEGIN IMMEDIATE', 'failed to start review note metadata transaction');
        $transaction_started = true;
        $table = match ($record_type) {
            'conflict' => 'merge_conflicts',
            'decision' => 'merge_decisions',
            'resolution' => 'merge_resolutions',
            default => throw new InvalidArgumentException('--record must be conflict, decision, or resolution'),
        };
        $stmt = cow_merge_prepare_checked($meta, "SELECT id FROM $table WHERE id = :id", "failed to prepare $record_type lookup");
        cow_merge_bind($stmt, ':id', $record_id);
        $res = cow_merge_execute_checked($stmt, $meta, "failed to execute $record_type lookup");
        if (!$res->fetchArray(SQLITE3_ASSOC)) {
            cow_merge_result_finalize_checked($res, "failed to finalize $record_type lookup");
            throw new InvalidArgumentException("$record_type #$record_id does not exist in merge metadata");
        }
        cow_merge_result_finalize_checked($res, "failed to finalize $record_type lookup");

        $review_note_id = cow_merge_insert_review_note($meta, $record_type, $record_id, $status, $note, $reviewer);
        cow_merge_exec_checked($meta, 'COMMIT', 'failed to commit review note metadata transaction');
        $transaction_started = false;
        return [
            'metadata_db' => $metadata_db,
            'record_type' => $record_type,
            'record_id' => $record_id,
            'status' => $status,
            'note' => $note,
            'reviewer' => $reviewer,
            'review_note_id' => $review_note_id,
        ];
    } catch (Throwable $e) {
        if ($transaction_started) {
            @$meta->exec('ROLLBACK');
        }
        throw $e;
    } finally {
        $meta->close();
    }
}

function cow_merge_latest_review_note(SQLite3 $meta, string $record_type, int $record_id): ?array {
    $stmt = cow_merge_prepare_checked(
        $meta,
        'SELECT id, status, note, reviewer, created_at FROM merge_review_notes ' .
        'WHERE record_type = :record_type AND record_id = :record_id ORDER BY id DESC LIMIT 1',
        'failed to prepare latest review note lookup'
    );
    cow_merge_bind($stmt, ':record_type', $record_type);
    cow_merge_bind($stmt, ':record_id', $record_id);
    $res = cow_merge_execute_checked($stmt, $meta, 'failed to look up latest review note');
    $row = $res->fetchArray(SQLITE3_ASSOC);
    cow_merge_result_finalize_checked($res, 'failed to finalize latest review note lookup');
    return $row ? $row : null;
}

function cow_merge_revalidation_note(array $review, array $staleness): string {
    $previous_status = (string)($review['status'] ?? 'unknown');
    $previous_reviewer = (string)($review['reviewer'] ?? 'unknown');
    $previous_at = (string)($review['created_at'] ?? 'unknown time');
    $previous_note = trim((string)($review['note'] ?? ''));
    $reason = (string)($staleness['stale_reason'] ?? 'target payload changed');
    $note = "Revalidation required after merge drift ($reason). Previous $previous_status review by $previous_reviewer at $previous_at";
    if ($previous_note !== '') {
        $note .= ": $previous_note";
    }
    return strlen($note) > 4096 ? substr($note, 0, 4093) . '...' : $note;
}

function cow_merge_revalidate_reviewed_conflicts(
    string $metadata_db,
    ?int $run_id = null,
    string $reviewer = 'forkpress'
): array {
    if (!is_file($metadata_db)) {
        throw new RuntimeException("merge metadata database does not exist: $metadata_db");
    }
    $meta = cow_merge_open_db($metadata_db, SQLITE3_OPEN_READWRITE);
    $transaction_started = false;
    try {
        cow_merge_ensure_metadata($meta);
        $params = [];
        $where = '';
        if ($run_id !== null) {
            $where = 'WHERE c.run_id = :run_id';
            $params[':run_id'] = $run_id;
        }
        $conflicts = cow_merge_fetch_rows(
            $meta,
            "SELECT c.id AS id, c.run_id, c.table_name, c.row_identity, c.column_name, c.conflict_type, c.resolver, c.resolved_at, c.created_at, " .
            "c.base_payload, c.source_payload, c.target_payload, c.chosen_payload, r.source_db, r.target_db, r.source_branch, r.target_branch " .
            "FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id $where ORDER BY c.id ASC",
            $params
        );

        cow_merge_exec_checked($meta, 'BEGIN IMMEDIATE', 'failed to start review revalidation transaction');
        $transaction_started = true;
        $checked = 0;
        $reviewed = 0;
        $fresh = 0;
        $stale = 0;
        $errors = 0;
        $carried = 0;
        $already_needs_action = 0;
        foreach ($conflicts as $conflict) {
            $checked++;
            $conflict_id = (int)$conflict['id'];
            $review = cow_merge_latest_review_note($meta, 'conflict', $conflict_id);
            if ($review === null) {
                continue;
            }
            $reviewed++;
            $staleness = cow_merge_audit_conflict_target_staleness($meta, $conflict);
            $status = (string)($staleness['stale_status'] ?? 'unknown');
            if ($status === 'fresh') {
                $fresh++;
                continue;
            }
            if ($status === 'unknown') {
                continue;
            }
            if ($status === 'error') {
                $errors++;
            } else {
                $stale++;
            }

            $latest_status = (string)($review['status'] ?? '');
            $latest_note = (string)($review['note'] ?? '');
            if ($latest_status === 'needs-action' && str_contains($latest_note, 'Revalidation required after')) {
                $latest_revalidation = cow_merge_latest_revalidation($meta, $conflict_id);
                $current_source_payload = $staleness['current_source_payload'] ?? (string)$conflict['source_payload'];
                $current_target_payload = $staleness['current_target_payload'] ?? null;
                if (
                    is_string($current_source_payload)
                    && is_string($current_target_payload)
                    && $latest_revalidation !== null
                    && hash_equals((string)$latest_revalidation['source_hash'], hash('sha256', $current_source_payload))
                    && hash_equals((string)$latest_revalidation['target_hash'], hash('sha256', $current_target_payload))
                ) {
                    $already_needs_action++;
                    continue;
                }
            }
            $review_note_id = cow_merge_insert_review_note(
                $meta,
                'conflict',
                $conflict_id,
                'needs-action',
                cow_merge_revalidation_note($review, $staleness),
                $reviewer
            );
            $current_source_payload = $staleness['current_source_payload'] ?? (string)$conflict['source_payload'];
            $current_target_payload = $staleness['current_target_payload'] ?? null;
            if (is_string($current_source_payload) && is_string($current_target_payload)) {
                cow_merge_record_revalidation(
                    $meta,
                    $conflict_id,
                    $review_note_id,
                    (int)$conflict['run_id'],
                    (string)($staleness['revalidation_class'] ?? 'unclassified'),
                    $current_source_payload,
                    $current_target_payload,
                    (string)($staleness['stale_reason'] ?? 'target payload changed'),
                    isset($staleness['replacement_conflict_id']) ? (int)$staleness['replacement_conflict_id'] : null
                );
            }
            $carried++;
        }
        cow_merge_exec_checked($meta, 'COMMIT', 'failed to commit review revalidation transaction');
        $transaction_started = false;
        return [
            'metadata_db' => $metadata_db,
            'run_id' => $run_id,
            'checked' => $checked,
            'reviewed' => $reviewed,
            'fresh' => $fresh,
            'stale' => $stale,
            'errors' => $errors,
            'carried' => $carried,
            'already_needs_action' => $already_needs_action,
        ];
    } catch (Throwable $e) {
        if ($transaction_started) {
            @$meta->exec('ROLLBACK');
        }
        throw $e;
    } finally {
        $meta->close();
    }
}

function cow_merge_insert_review_note(
    SQLite3 $meta,
    string $record_type,
    int $record_id,
    string $status,
    string $note,
    string $reviewer
): int {
    $stmt = cow_merge_prepare_checked(
        $meta,
        'INSERT INTO merge_review_notes (record_type, record_id, status, note, reviewer) ' .
        'VALUES (:record_type, :record_id, :status, :note, :reviewer)',
        'failed to prepare review note insert'
    );
    cow_merge_bind($stmt, ':record_type', $record_type);
    cow_merge_bind($stmt, ':record_id', $record_id);
    cow_merge_bind($stmt, ':status', $status);
    cow_merge_bind($stmt, ':note', $note);
    cow_merge_bind($stmt, ':reviewer', $reviewer);
    cow_merge_execute_checked($stmt, $meta, 'failed to record review note');
    return (int)$meta->lastInsertRowID();
}

function cow_merge_record_revalidation(
    SQLite3 $meta,
    int $conflict_id,
    int $review_note_id,
    int $run_id,
    string $revalidation_class,
    string $source_payload,
    string $target_payload,
    string $stale_reason,
    ?int $replacement_conflict_id = null
): int {
    $stmt = cow_merge_prepare_checked(
        $meta,
        'INSERT INTO merge_revalidations ' .
        '(conflict_id, review_note_id, run_id, replacement_conflict_id, revalidation_class, source_payload, target_payload, source_hash, target_hash, stale_reason) ' .
        'VALUES (:conflict_id, :review_note_id, :run_id, :replacement_conflict_id, :revalidation_class, :source_payload, :target_payload, :source_hash, :target_hash, :stale_reason)',
        'failed to prepare merge revalidation insert'
    );
    cow_merge_bind($stmt, ':conflict_id', $conflict_id);
    cow_merge_bind($stmt, ':review_note_id', $review_note_id);
    cow_merge_bind($stmt, ':run_id', $run_id);
    cow_merge_bind($stmt, ':replacement_conflict_id', $replacement_conflict_id);
    cow_merge_bind($stmt, ':revalidation_class', $revalidation_class);
    cow_merge_bind($stmt, ':source_payload', $source_payload);
    cow_merge_bind($stmt, ':target_payload', $target_payload);
    cow_merge_bind($stmt, ':source_hash', hash('sha256', $source_payload));
    cow_merge_bind($stmt, ':target_hash', hash('sha256', $target_payload));
    cow_merge_bind($stmt, ':stale_reason', $stale_reason);
    cow_merge_execute_checked($stmt, $meta, 'failed to record merge revalidation');
    return (int)$meta->lastInsertRowID();
}

function cow_merge_latest_revalidation(SQLite3 $meta, int $conflict_id): ?array {
    $stmt = cow_merge_prepare_checked(
        $meta,
        'SELECT id, conflict_id, review_note_id, run_id, replacement_conflict_id, revalidation_class, source_payload, target_payload, source_hash, target_hash, stale_reason, created_at ' .
        'FROM merge_revalidations WHERE conflict_id = :conflict_id ORDER BY id DESC LIMIT 1',
        'failed to prepare latest merge revalidation lookup'
    );
    cow_merge_bind($stmt, ':conflict_id', $conflict_id);
    $res = cow_merge_execute_checked($stmt, $meta, 'failed to look up latest merge revalidation');
    $row = $res->fetchArray(SQLITE3_ASSOC);
    cow_merge_result_finalize_checked($res, 'failed to finalize latest merge revalidation lookup');
    return $row ? $row : null;
}

function cow_merge_require_after_revalidate(
    SQLite3 $meta,
    int $conflict_id,
    string $source_payload,
    string $current_target_payload
): void {
    $review = cow_merge_latest_review_note($meta, 'conflict', $conflict_id);
    if ($review === null || (string)($review['status'] ?? '') !== 'needs-action') {
        throw new RuntimeException('--after-revalidate requires a latest needs-action review from merge-audit --revalidate');
    }
    $revalidation = cow_merge_latest_revalidation($meta, $conflict_id);
    if ($revalidation === null) {
        throw new RuntimeException('--after-revalidate requires merge-audit --revalidate to record the stale target payload before resolving');
    }
    if ((string)($revalidation['revalidation_class'] ?? '') === 'incompatible') {
        throw new RuntimeException('latest merge revalidation is incompatible; rerun merge-audit and review manually before resolving');
    }
    if (!hash_equals((string)$revalidation['source_hash'], hash('sha256', $source_payload))) {
        throw new RuntimeException('source payload changed after latest merge revalidation; rerun merge-audit --revalidate before resolving');
    }
    if (!hash_equals((string)$revalidation['target_hash'], hash('sha256', $current_target_payload))) {
        throw new RuntimeException('target payload changed after latest merge revalidation; rerun merge-audit --revalidate before resolving');
    }
}

function cow_merge_resolution_review_note(string $choice, string $note): string {
    return "Resolved with $choice choice: $note";
}

function cow_merge_resolution_choice(?string $value): string {
    if (!in_array($value, ['source', 'target'], true)) {
        throw new InvalidArgumentException('--choice must be source or target');
    }
    return (string)$value;
}

function cow_merge_bool_flag(mixed $value): bool {
    return (string)$value === '1' || $value === true;
}

function cow_merge_json_array_arg(array $args, string $json_key, string $file_key): array {
    $has_json = isset($args[$json_key]) && (string)$args[$json_key] !== '';
    $has_file = isset($args[$file_key]) && (string)$args[$file_key] !== '';
    $json_label = '--' . str_replace('_', '-', $json_key);
    $file_label = '--' . str_replace('_', '-', $file_key);
    if ($has_json && $has_file) {
        throw new InvalidArgumentException("$json_label and $file_label cannot be used together");
    }
    if (!$has_json && !$has_file) {
        throw new InvalidArgumentException("$json_label or $file_label is required");
    }
    if ($has_file) {
        $path = (string)$args[$file_key];
        if (!is_file($path)) {
            throw new InvalidArgumentException("$file_label must point to a readable file");
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("failed to read $file_label");
        }
    } else {
        $json = (string)$args[$json_key];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || !array_is_list($decoded)) {
        throw new InvalidArgumentException("$json_label must be a JSON array");
    }
    return $decoded;
}

function cow_merge_decode_payload_json(string $json, string $context): mixed {
    $decoded = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("invalid $context payload: " . json_last_error_msg());
    }
    return cow_merge_audit_decode_payload($decoded);
}

function cow_merge_select_current_cell(SQLite3 $db, string $table, array $identity, array $pk_cols, string $column): mixed {
    $where_values = [];
    $where = cow_merge_where_clause($db, $table, $identity, $pk_cols, $where_values);
    $stmt = cow_merge_prepare_checked(
        $db,
        'SELECT ' . cow_merge_quote_ident($column) . ' AS value FROM ' . cow_merge_quote_ident($table) . ' WHERE ' . $where,
        "failed to prepare current cell lookup for $table.$column"
    );
    foreach ($where_values as $i => $value) {
        cow_merge_bind($stmt, $i + 1, $value);
    }
    $res = cow_merge_execute_checked($stmt, $db, "failed to read current cell for $table.$column");
    $row = $res->fetchArray(SQLITE3_ASSOC);
    cow_merge_result_finalize_checked($res, "failed to finalize current cell lookup for $table.$column");
    if (!$row) {
        throw new RuntimeException("cannot resolve $table.$column conflict because the target row no longer exists");
    }
    return $row['value'] ?? null;
}

function cow_merge_select_current_row(SQLite3 $db, string $table, array $identity, array $pk_cols): ?array {
    $where_values = [];
    $where = cow_merge_where_clause($db, $table, $identity, $pk_cols, $where_values);
    $stmt = cow_merge_prepare_checked(
        $db,
        'SELECT * FROM ' . cow_merge_quote_ident($table) . ' WHERE ' . $where,
        "failed to prepare current row lookup for $table"
    );
    foreach ($where_values as $i => $value) {
        cow_merge_bind($stmt, $i + 1, $value);
    }
    $res = cow_merge_execute_checked($stmt, $db, "failed to read current row for $table");
    $row = $res->fetchArray(SQLITE3_ASSOC);
    cow_merge_result_finalize_checked($res, "failed to finalize current row lookup for $table");
    return $row ?: null;
}

function cow_merge_lookup_active_rowid_by_identity(SQLite3 $meta, string $branch, string $table, array $identity): ?int {
    $stmt = cow_merge_prepare_checked(
        $meta,
        'SELECT rowid FROM merge_row_identities ' .
        'WHERE branch_name = :branch_name AND table_name = :table_name AND logical_identity = :logical_identity',
        'failed to prepare active row identity lookup'
    );
    cow_merge_bind($stmt, ':branch_name', $branch);
    cow_merge_bind($stmt, ':table_name', $table);
    cow_merge_bind($stmt, ':logical_identity', cow_merge_plain_json($identity));
    $res = cow_merge_execute_checked($stmt, $meta, 'failed to look up active row identity');
    $row = $res->fetchArray(SQLITE3_ASSOC);
    cow_merge_result_finalize_checked($res, 'failed to finalize active row identity lookup');
    if ($row) {
        return (int)$row['rowid'];
    }

    $scan = cow_merge_prepare_checked(
        $meta,
        'SELECT rowid, logical_identity FROM merge_row_identities ' .
        'WHERE branch_name = :branch_name AND table_name = :table_name',
        'failed to prepare active row identity scan'
    );
    cow_merge_bind($scan, ':branch_name', $branch);
    cow_merge_bind($scan, ':table_name', $table);
    $res = cow_merge_execute_checked($scan, $meta, 'failed to scan active row identities');
    while ($candidate = $res->fetchArray(SQLITE3_ASSOC)) {
        $candidate_identity = json_decode((string)$candidate['logical_identity'], true);
        if (is_array($candidate_identity) && cow_merge_values_equal($candidate_identity, $identity)) {
            cow_merge_result_finalize_checked($res, 'failed to finalize active row identity scan');
            return (int)$candidate['rowid'];
        }
    }
    cow_merge_result_finalize_checked($res, 'failed to finalize active row identity scan');

    $history = cow_merge_prepare_checked(
        $meta,
        'SELECT rowid FROM merge_row_identity_history ' .
        'WHERE branch_name = :branch_name AND table_name = :table_name AND logical_identity = :logical_identity AND deleted_at IS NULL ' .
        'ORDER BY updated_at DESC, id DESC LIMIT 1',
        'failed to prepare active row identity history lookup'
    );
    cow_merge_bind($history, ':branch_name', $branch);
    cow_merge_bind($history, ':table_name', $table);
    cow_merge_bind($history, ':logical_identity', cow_merge_plain_json($identity));
    $res = cow_merge_execute_checked($history, $meta, 'failed to look up row identity history');
    $row = $res->fetchArray(SQLITE3_ASSOC);
    cow_merge_result_finalize_checked($res, 'failed to finalize active row identity history lookup');
    if ($row) {
        return (int)$row['rowid'];
    }

    $history_scan = cow_merge_prepare_checked(
        $meta,
        'SELECT rowid, logical_identity FROM merge_row_identity_history ' .
        'WHERE branch_name = :branch_name AND table_name = :table_name AND deleted_at IS NULL ' .
        'ORDER BY updated_at DESC, id DESC',
        'failed to prepare active row identity history scan'
    );
    cow_merge_bind($history_scan, ':branch_name', $branch);
    cow_merge_bind($history_scan, ':table_name', $table);
    $res = cow_merge_execute_checked($history_scan, $meta, 'failed to scan row identity history');
    while ($candidate = $res->fetchArray(SQLITE3_ASSOC)) {
        $candidate_identity = json_decode((string)$candidate['logical_identity'], true);
        if (is_array($candidate_identity) && cow_merge_values_equal($candidate_identity, $identity)) {
            cow_merge_result_finalize_checked($res, 'failed to finalize active row identity history scan');
            return (int)$candidate['rowid'];
        }
    }
    cow_merge_result_finalize_checked($res, 'failed to finalize active row identity history scan');
    return null;
}

function cow_merge_keyless_identity_rowid_hint(array $identity): ?int {
    foreach (['rowid', 'base_rowid'] as $key) {
        if (!array_key_exists($key, $identity)) {
            continue;
        }
        $value = $identity[$key];
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int)$value;
        }
    }
    return null;
}

function cow_merge_lookup_active_identity_by_rowid(SQLite3 $meta, string $branch, string $table, int $rowid): ?array {
    $stmt = cow_merge_prepare_checked(
        $meta,
        'SELECT logical_identity FROM merge_row_identities ' .
        'WHERE branch_name = :branch_name AND table_name = :table_name AND rowid = :rowid',
        'failed to prepare active rowid identity lookup'
    );
    cow_merge_bind($stmt, ':branch_name', $branch);
    cow_merge_bind($stmt, ':table_name', $table);
    cow_merge_bind($stmt, ':rowid', $rowid);
    $res = cow_merge_execute_checked($stmt, $meta, 'failed to look up active rowid identity');
    $row = $res->fetchArray(SQLITE3_ASSOC);
    cow_merge_result_finalize_checked($res, 'failed to finalize active rowid identity lookup');
    if (!$row) {
        return null;
    }
    $identity = json_decode((string)$row['logical_identity'], true);
    if (!is_array($identity)) {
        throw new RuntimeException("invalid active row identity sidecar for $branch.$table rowid $rowid");
    }
    return $identity;
}

function cow_merge_update_single_cell(SQLite3 $db, string $table, array $identity, array $pk_cols, string $column, mixed $value): void {
    $where_values = [];
    $where = cow_merge_where_clause($db, $table, $identity, $pk_cols, $where_values);
    $stmt = cow_merge_prepare_checked(
        $db,
        'UPDATE ' . cow_merge_quote_ident($table) . ' SET ' . cow_merge_quote_ident($column) . ' = ? WHERE ' . $where,
        "failed to prepare conflict resolution update for $table.$column"
    );
    cow_merge_bind($stmt, 1, $value);
    foreach ($where_values as $i => $where_value) {
        cow_merge_bind($stmt, $i + 2, $where_value);
    }
    cow_merge_execute_checked($stmt, $db, "failed to apply conflict resolution to $table.$column");
    if ($db->changes() !== 1) {
        throw new RuntimeException("conflict resolution for $table.$column affected {$db->changes()} rows, expected 1");
    }
}

function cow_merge_record_resolution(
    SQLite3 $meta,
    int $conflict_id,
    string $choice,
    bool $apply,
    string $note,
    string $reviewer,
    string $target_db,
    string $table,
    string $identity_json,
    string $column,
    mixed $previous,
    mixed $resolved
): int {
    $stmt = cow_merge_prepare_checked(
        $meta,
        'INSERT INTO merge_resolutions ' .
        '(conflict_id, choice, applied, status, note, reviewer, target_db, table_name, row_identity, column_name, previous_payload, resolved_payload) ' .
        'VALUES (:conflict_id, :choice, :applied, :status, :note, :reviewer, :target_db, :table_name, :row_identity, :column_name, :previous_payload, :resolved_payload)',
        'failed to prepare resolution record insert'
    );
    cow_merge_bind($stmt, ':conflict_id', $conflict_id);
    cow_merge_bind($stmt, ':choice', $choice);
    cow_merge_bind($stmt, ':applied', $apply ? 1 : 0);
    cow_merge_bind($stmt, ':status', $apply && $choice === 'source' ? 'applied' : 'validated');
    cow_merge_bind($stmt, ':note', $note);
    cow_merge_bind($stmt, ':reviewer', $reviewer);
    cow_merge_bind($stmt, ':target_db', $target_db);
    cow_merge_bind($stmt, ':table_name', $table);
    cow_merge_bind($stmt, ':row_identity', $identity_json);
    cow_merge_bind($stmt, ':column_name', $column);
    cow_merge_bind($stmt, ':previous_payload', cow_merge_payload_json($previous));
    cow_merge_bind($stmt, ':resolved_payload', cow_merge_payload_json($resolved));
    cow_merge_execute_checked($stmt, $meta, 'failed to record merge resolution');
    return (int)$meta->lastInsertRowID();
}

function cow_merge_exec_checked(SQLite3 $db, string $sql, string $message): void {
    cow_merge_test_hook('before_sqlite_exec', $db, $sql, $message);
    if (!@$db->exec($sql)) {
        throw new RuntimeException($message . ': ' . $db->lastErrorMsg());
    }
}

function cow_merge_prepare_checked(SQLite3 $db, string $sql, string $message): SQLite3Stmt {
    cow_merge_test_hook('before_sqlite_prepare', $db, $sql, $message);
    $stmt = @$db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($message . ': ' . $db->lastErrorMsg());
    }
    return $stmt;
}

function cow_merge_execute_checked(SQLite3Stmt $stmt, SQLite3 $db, string $message): SQLite3Result {
    cow_merge_test_hook('before_sqlite_statement_execute', $db, $message);
    $result = @$stmt->execute();
    if (!$result) {
        throw new RuntimeException($message . ': ' . $db->lastErrorMsg());
    }
    return $result;
}

function cow_merge_execute_constraint_mutation(
    SQLite3Stmt $stmt,
    SQLite3 $db,
    string $message,
    string $finalize_message
): array {
    cow_merge_test_hook('before_sqlite_statement_execute', $db, $message);
    $result = @$stmt->execute();
    if (!$result) {
        if (cow_merge_is_constraint_error($db)) {
            return ['ok' => false, 'error' => cow_merge_constraint_error($db)];
        }
        throw new RuntimeException($message . ': ' . $db->lastErrorMsg());
    }
    cow_merge_result_finalize_checked($result, $finalize_message);
    return ['ok' => true, 'error' => null];
}

function cow_merge_query_checked(SQLite3 $db, string $sql, string $message): SQLite3Result {
    cow_merge_test_hook('before_sqlite_query', $db, $sql, $message);
    $result = @$db->query($sql);
    if (!$result) {
        throw new RuntimeException($message . ': ' . $db->lastErrorMsg());
    }
    return $result;
}

function cow_merge_result_finalize_checked(SQLite3Result $result, string $message): void {
    cow_merge_test_hook('before_sqlite_result_finalize', $result, $message);
    if (!@$result->finalize()) {
        throw new RuntimeException($message);
    }
}

function cow_merge_release_savepoint_checked(SQLite3 $db, string $savepoint, string $context): void {
    cow_merge_exec_checked(
        $db,
        'RELEASE ' . $savepoint,
        'failed to release ' . $context . ' savepoint'
    );
}

function cow_merge_rollback_release_savepoint_checked(
    SQLite3 $db,
    string $savepoint,
    string $context,
    ?Throwable $cause = null
): ?RuntimeException {
    $errors = [];
    try {
        cow_merge_exec_checked(
            $db,
            'ROLLBACK TO ' . $savepoint,
            'failed to roll back ' . $context . ' savepoint'
        );
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
    try {
        cow_merge_release_savepoint_checked($db, $savepoint, $context);
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
    if (!$errors) {
        return null;
    }
    $message = 'failed to clean up ' . $context . ' savepoint: ' . implode('; ', $errors);
    if ($cause !== null) {
        $message = $cause->getMessage() . '; ' . $message;
    }
    return new RuntimeException($message, 0, $cause);
}

function cow_merge_schema_column_payload(mixed $payload): ?array {
    if (!is_array($payload)) {
        return null;
    }
    if (isset($payload['column']) && is_array($payload['column'])) {
        return $payload['column'];
    }
    if (isset($payload['name'])) {
        return $payload;
    }
    return null;
}

function cow_merge_schema_column_definition_payload(mixed $payload): ?string {
    if (is_array($payload) && isset($payload['definition']) && is_string($payload['definition'])) {
        return $payload['definition'];
    }
    return null;
}

function cow_merge_schema_index_sql_payload(mixed $payload): ?string {
    if (is_string($payload)) {
        return $payload;
    }
    if (is_array($payload) && isset($payload['sql']) && is_string($payload['sql'])) {
        return $payload['sql'];
    }
    return null;
}

function cow_merge_table_column_names(array $columns): array {
    return array_map(fn($column) => strtolower((string)$column['name']), $columns);
}

function cow_merge_table_rebuild_supported(array $source_columns, array $target_columns): bool {
    if (cow_merge_table_column_names($source_columns) !== cow_merge_table_column_names($target_columns)) {
        return false;
    }
    foreach ($source_columns as $i => $source_column) {
        $target_column = $target_columns[$i] ?? null;
        if (!is_array($target_column)) {
            return false;
        }
        $source_pk = (int)$source_column['pk'];
        $target_pk = (int)$target_column['pk'];
        if ($source_pk !== $target_pk) {
            return false;
        }
        if ($source_pk !== 0 && !cow_merge_column_signatures_equal($source_column, $target_column)) {
            return false;
        }
    }
    return true;
}

function cow_merge_table_rebuild_dependencies(SQLite3 $db, string $table): array {
    $dependents = [];
    $stmt = cow_merge_prepare_checked(
        $db,
        "SELECT type, name, sql FROM sqlite_master " .
            "WHERE tbl_name = :table AND type IN ('index', 'trigger') AND sql IS NOT NULL ORDER BY type, name",
        "failed to prepare table dependent lookup for $table"
    );
    cow_merge_bind($stmt, ':table', $table);
    $res = cow_merge_execute_checked($stmt, $db, "failed to read table dependents for $table");
    try {
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $dependents[] = [
                'type' => (string)$row['type'],
                'name' => (string)$row['name'],
                'sql' => (string)$row['sql'],
            ];
        }
    } finally {
        cow_merge_result_finalize_checked($res, "failed to finalize table dependents for $table");
    }
    return $dependents;
}

function cow_merge_sql_references_table(string $sql, string $table): bool {
    return in_array(strtolower($table), cow_merge_sql_referenced_tables($sql), true);
}

function cow_merge_identifier_pattern(string $prefix = ''): string {
    return '(?:"(?P<' . $prefix . 'dq>[^"]+)"|`(?P<' . $prefix . 'bq>[^`]+)`|\[(?P<' . $prefix . 'br>[^\]]+)\]|\'(?P<' . $prefix . 'sq>[^\']+)\'|(?P<' . $prefix . 'bare>[A-Za-z_][A-Za-z0-9_]*))';
}

function cow_merge_regex_named_match(array $match, string $name): ?string {
    return isset($match[$name]) && is_string($match[$name]) && $match[$name] !== '' ? $match[$name] : null;
}

function cow_merge_sql_reference_name(array $match, string $prefix = ''): ?string {
    foreach (['dq', 'bq', 'br', 'sq', 'bare'] as $name) {
        $value = cow_merge_regex_named_match($match, $prefix . $name);
        if ($value !== null) {
            return strtolower($value);
        }
    }
    return null;
}

function cow_merge_regex_flat_match(array $match): array {
    $flat = [];
    foreach ($match as $key => $value) {
        if (is_array($value)) {
            $flat[$key] = (string)($value[0] ?? '');
        } else {
            $flat[$key] = $value;
        }
    }
    return $flat;
}

function cow_merge_sql_ignored_ranges(string $sql): array {
    $ranges = [];
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $sql[$i + 1] ?? '';
        if ($char === '-' && $next === '-') {
            $start = $i;
            $i += 2;
            while ($i < $length && $sql[$i] !== "\n") {
                $i++;
            }
            $ranges[] = [$start, $i];
            continue;
        }
        if ($char === '/' && $next === '*') {
            $start = $i;
            $i += 2;
            while ($i < $length - 1 && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                $i++;
            }
            $ranges[] = [$start, min($length, $i + 2)];
            $i++;
            continue;
        }
        if ($char !== '\'' && $char !== '"' && $char !== '`' && $char !== '[') {
            continue;
        }
        $start = $i;
        $quote = $char === '[' ? ']' : $char;
        $i++;
        while ($i < $length) {
            if ($sql[$i] === $quote) {
                $after = $sql[$i + 1] ?? '';
                if (($quote === '\'' || $quote === '"') && $after === $quote) {
                    $i += 2;
                    continue;
                }
                $i++;
                break;
            }
            $i++;
        }
        $ranges[] = [$start, $i];
        $i--;
    }
    return $ranges;
}

function cow_merge_sql_offset_in_ranges(int $offset, array $ranges): bool {
    foreach ($ranges as $range) {
        if ($offset >= $range[0] && $offset < $range[1]) {
            return true;
        }
    }
    return false;
}

function cow_merge_sql_split_statements(string $sql): array {
    $statements = [];
    $start = 0;
    $quote = null;
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        if ($quote !== null) {
            if ($char === $quote) {
                $next = $sql[$i + 1] ?? '';
                if (($quote === '\'' || $quote === '"') && $next === $quote) {
                    $i++;
                    continue;
                }
                $quote = null;
            }
            continue;
        }
        if ($char === '\'' || $char === '"' || $char === '`') {
            $quote = $char;
            continue;
        }
        if ($char === '[') {
            $quote = ']';
            continue;
        }
        if ($char === ';') {
            $statement = trim(substr($sql, $start, $i - $start));
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $start = $i + 1;
        }
    }
    $statement = trim(substr($sql, $start));
    if ($statement !== '') {
        $statements[] = $statement;
    }
    return $statements ?: [trim($sql)];
}

function cow_merge_sql_skip_parenthesized(string $sql, int $open_pos): ?int {
    if (($sql[$open_pos] ?? null) !== '(') {
        return null;
    }
    $quote = null;
    $depth = 0;
    $length = strlen($sql);
    for ($i = $open_pos; $i < $length; $i++) {
        $char = $sql[$i];
        if ($quote !== null) {
            if ($char === $quote) {
                $next = $sql[$i + 1] ?? '';
                if (($quote === '\'' || $quote === '"') && $next === $quote) {
                    $i++;
                    continue;
                }
                $quote = null;
            }
            continue;
        }
        if ($char === '\'' || $char === '"' || $char === '`') {
            $quote = $char;
            continue;
        }
        if ($char === '[') {
            $quote = ']';
            continue;
        }
        if ($char === '(') {
            $depth++;
            continue;
        }
        if ($char === ')') {
            $depth--;
            if ($depth === 0) {
                return $i + 1;
            }
        }
    }
    return null;
}

function cow_merge_sql_cte_names(string $sql): array {
    $identifier = cow_merge_identifier_pattern();
    $names = [];
    $ignored_ranges = cow_merge_sql_ignored_ranges($sql);
    if (!preg_match_all('/\bWITH\s+(?:RECURSIVE\s+)?/i', $sql, $with_matches, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    foreach ($with_matches[0] as $with_match) {
        if (cow_merge_sql_offset_in_ranges((int)$with_match[1], $ignored_ranges)) {
            continue;
        }
        $offset = (int)$with_match[1] + strlen((string)$with_match[0]);
        while (true) {
            if (!preg_match('/\G\s*' . $identifier . '(?:\s*\([^)]*\))?\s+AS\s+(?:NOT\s+MATERIALIZED\s+|MATERIALIZED\s+)?\(/i', $sql, $cte_match, PREG_OFFSET_CAPTURE, $offset)) {
                break;
            }
            $name = cow_merge_sql_reference_name(cow_merge_regex_flat_match($cte_match));
            if ($name !== null) {
                $names[$name] = true;
            }
            $open_pos = (int)$cte_match[0][1] + strlen((string)$cte_match[0][0]) - 1;
            $after_cte = cow_merge_sql_skip_parenthesized($sql, $open_pos);
            if ($after_cte === null) {
                break;
            }
            $offset = $after_cte;
            if (!preg_match('/\G\s*,/i', $sql, $comma_match, 0, $offset)) {
                break;
            }
            $offset += strlen($comma_match[0]);
        }
    }
    return array_keys($names);
}

function cow_merge_sql_referenced_schema_objects(string $sql): array {
    $schema_identifier = cow_merge_identifier_pattern('schema_');
    $object_identifier = cow_merge_identifier_pattern('object_');
    $patterns = [
        '/\b(?:FROM|JOIN|UPDATE|INTO)\s+(?:' . $schema_identifier . '\s*\.\s*)?' . $object_identifier . '/i',
        '/\bTABLE\s+(?:' . $schema_identifier . '\s*\.\s*)?' . $object_identifier . '/i',
    ];
    $refs = [];
    foreach (cow_merge_sql_split_statements($sql) as $statement) {
        $cte_names = array_fill_keys(cow_merge_sql_cte_names($statement), true);
        $ignored_ranges = cow_merge_sql_ignored_ranges($statement);
        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $statement, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches as $match) {
                if (cow_merge_sql_offset_in_ranges((int)$match[0][1], $ignored_ranges)) {
                    continue;
                }
                $flat_match = cow_merge_regex_flat_match($match);
                $name = cow_merge_sql_reference_name($flat_match, 'object_');
                if ($name !== null && !isset($cte_names[$name])) {
                    $schema = cow_merge_sql_reference_name($flat_match, 'schema_');
                    $key = ($schema ?? '') . '.' . $name;
                    $refs[$key] = ['schema' => $schema, 'name' => $name];
                }
            }
        }
    }
    return array_values($refs);
}

function cow_merge_sql_referenced_tables(string $sql): array {
    $refs = [];
    foreach (cow_merge_sql_referenced_schema_objects($sql) as $reference) {
        $refs[(string)$reference['name']] = true;
    }
    return array_keys($refs);
}

function cow_merge_sql_written_schema_objects(string $sql): array {
    $schema_identifier = cow_merge_identifier_pattern('schema_');
    $object_identifier = cow_merge_identifier_pattern('object_');
    $patterns = [
        '/\b(?:INSERT(?:\s+OR\s+\w+)?|REPLACE)\s+INTO\s+(?:' . $schema_identifier . '\s*\.\s*)?' . $object_identifier . '/i',
        '/\bUPDATE(?:\s+OR\s+\w+)?\s+(?:' . $schema_identifier . '\s*\.\s*)?' . $object_identifier . '/i',
        '/\bDELETE\s+FROM\s+(?:' . $schema_identifier . '\s*\.\s*)?' . $object_identifier . '/i',
    ];
    $refs = [];
    foreach (cow_merge_sql_split_statements($sql) as $statement) {
        $cte_names = array_fill_keys(cow_merge_sql_cte_names($statement), true);
        $ignored_ranges = cow_merge_sql_ignored_ranges($statement);
        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $statement, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches as $match) {
                if (cow_merge_sql_offset_in_ranges((int)$match[0][1], $ignored_ranges)) {
                    continue;
                }
                $flat_match = cow_merge_regex_flat_match($match);
                $name = cow_merge_sql_reference_name($flat_match, 'object_');
                if ($name !== null && !isset($cte_names[$name])) {
                    $schema = cow_merge_sql_reference_name($flat_match, 'schema_');
                    $key = strtolower((string)($schema ?? '')) . '.' . strtolower($name);
                    $refs[$key] = ['schema' => $schema, 'name' => $name];
                }
            }
        }
    }
    return array_values($refs);
}

function cow_merge_trigger_written_schema_objects(string $sql): array {
    $body = $sql;
    if (preg_match('/\bBEGIN\b(.*)\bEND\b/is', $sql, $match)) {
        $body = (string)$match[1];
    }
    return cow_merge_sql_written_schema_objects($body);
}

function cow_merge_schema_reference_key(?string $schema, string $name): string {
    $normalized_schema = $schema === null || $schema === '' || strcasecmp($schema, 'main') === 0
        ? ''
        : strtolower($schema);
    return $normalized_schema . '.' . strtolower($name);
}

function cow_merge_view_schema_dependency_map(array $objects, array $source_objects): array {
    $object_names = [];
    foreach ($objects as $object) {
        $object_names[strtolower((string)$object)] = (string)$object;
    }

    $map = [];
    foreach ($objects as $object) {
        $name = (string)$object;
        $key = strtolower($name);
        $source_sql = (string)($source_objects[$name]['sql'] ?? '');
        $dependencies = [];
        foreach (cow_merge_sql_referenced_tables($source_sql) as $reference) {
            $reference_key = strtolower($reference);
            if (isset($object_names[$reference_key])) {
                $dependencies[$reference_key] = $object_names[$reference_key];
            }
        }
        asort($dependencies);
        $map[$key] = [
            'name' => $name,
            'dependencies' => $dependencies,
        ];
    }

    return $map;
}

function cow_merge_view_schema_dependency_cycles(array $objects, array $source_objects): array {
    $map = cow_merge_view_schema_dependency_map($objects, $source_objects);
    $cycles = [];
    $state = [];
    $stack = [];
    $positions = [];

    $visit = function (string $key) use (&$visit, &$cycles, &$state, &$stack, &$positions, $map): void {
        if (($state[$key] ?? null) === 'done') {
            return;
        }
        if (($state[$key] ?? null) === 'visiting') {
            return;
        }
        $state[$key] = 'visiting';
        $positions[$key] = count($stack);
        $stack[] = $key;

        foreach (($map[$key]['dependencies'] ?? []) as $dependency_key => $dependency_name) {
            if (!isset($map[$dependency_key])) {
                continue;
            }
            if (($state[$dependency_key] ?? null) === 'visiting') {
                $cycle_keys = array_slice($stack, $positions[$dependency_key]);
                $cycle_keys[] = $dependency_key;
                $cycle_names = array_map(
                    fn(string $cycle_key): string => (string)$map[$cycle_key]['name'],
                    $cycle_keys
                );
                $message = implode(' -> ', $cycle_names);
                foreach (array_unique(array_slice($cycle_keys, 0, -1)) as $cycle_key) {
                    $cycles[$cycle_key] = $message;
                }
                continue;
            }
            $visit($dependency_key);
        }

        array_pop($stack);
        unset($positions[$key]);
        $state[$key] = 'done';
    };

    foreach (array_keys($map) as $key) {
        $visit($key);
    }

    return $cycles;
}

function cow_merge_trigger_program_dependency_map(array $objects, array $source_objects): array {
    $triggers_by_subject = [];
    $map = [];
    foreach ($objects as $object) {
        $name = (string)$object;
        $source_sql = (string)($source_objects[$name]['sql'] ?? '');
        if ($source_sql === '') {
            continue;
        }
        $subject = cow_merge_trigger_subject($source_sql);
        if ($subject === null) {
            continue;
        }
        $key = strtolower($name);
        $subject_key = cow_merge_schema_reference_key($subject['schema'], (string)$subject['table']);
        $triggers_by_subject[$subject_key][$key] = $name;
        $map[$key] = [
            'name' => $name,
            'sql' => $source_sql,
            'dependencies' => [],
        ];
    }

    foreach ($map as $key => $entry) {
        $dependencies = [];
        foreach (cow_merge_trigger_written_schema_objects((string)$entry['sql']) as $reference) {
            $reference_key = cow_merge_schema_reference_key($reference['schema'] ?? null, (string)$reference['name']);
            foreach (($triggers_by_subject[$reference_key] ?? []) as $trigger_key => $trigger_name) {
                $dependencies[$trigger_key] = $trigger_name;
            }
        }
        asort($dependencies);
        $map[$key]['dependencies'] = $dependencies;
    }

    return $map;
}

function cow_merge_trigger_program_dependency_cycles(array $objects, array $source_objects): array {
    $map = cow_merge_trigger_program_dependency_map($objects, $source_objects);

    $cycles = [];
    $state = [];
    $stack = [];
    $positions = [];
    $visit = function (string $key) use (&$visit, &$cycles, &$state, &$stack, &$positions, $map): void {
        if (($state[$key] ?? null) === 'done') {
            return;
        }
        if (($state[$key] ?? null) === 'visiting') {
            return;
        }
        $state[$key] = 'visiting';
        $positions[$key] = count($stack);
        $stack[] = $key;

        foreach (($map[$key]['dependencies'] ?? []) as $dependency_key => $dependency_name) {
            if (!isset($map[$dependency_key])) {
                continue;
            }
            if (($state[$dependency_key] ?? null) === 'visiting') {
                $cycle_keys = array_slice($stack, $positions[$dependency_key]);
                $cycle_keys[] = $dependency_key;
                $cycle_names = array_map(
                    fn(string $cycle_key): string => (string)$map[$cycle_key]['name'],
                    $cycle_keys
                );
                $message = implode(' -> ', $cycle_names);
                foreach (array_unique(array_slice($cycle_keys, 0, -1)) as $cycle_key) {
                    $cycles[$cycle_key] = $message;
                }
                continue;
            }
            $visit($dependency_key);
        }

        array_pop($stack);
        unset($positions[$key]);
        $state[$key] = 'done';
    };

    foreach (array_keys($map) as $key) {
        $visit($key);
    }

    return $cycles;
}

function cow_merge_sort_trigger_schema_objects(array $objects, array $source_objects): array {
    $dependency_map = cow_merge_trigger_program_dependency_map($objects, $source_objects);
    $ordered = [];
    $state = [];
    $visit = function (string $object) use (&$visit, &$ordered, &$state, $dependency_map): void {
        $key = strtolower($object);
        if (($state[$key] ?? null) === 'done') {
            return;
        }
        if (($state[$key] ?? null) === 'visiting') {
            return;
        }
        $state[$key] = 'visiting';
        $dependencies = $dependency_map[$key]['dependencies'] ?? [];
        unset($dependencies[$key]);
        foreach ($dependencies as $dependency) {
            $visit($dependency);
        }
        $state[$key] = 'done';
        $ordered[] = $object;
    };

    foreach ($objects as $object) {
        $visit((string)$object);
    }

    return $ordered;
}

function cow_merge_trigger_program_cycle_against_target(SQLite3 $db, string $name, string $sql): ?string {
    $triggers = cow_merge_schema_object_sql_map($db, 'trigger');
    $name_key = strtolower($name);
    foreach (array_keys($triggers) as $existing_name) {
        if (strtolower((string)$existing_name) === $name_key) {
            unset($triggers[$existing_name]);
        }
    }
    $triggers[$name] = [
        'table' => (string)(cow_merge_trigger_subject($sql)['table'] ?? ''),
        'sql' => $sql,
    ];
    $cycles = cow_merge_trigger_program_dependency_cycles(array_keys($triggers), $triggers);
    return $cycles[$name_key] ?? null;
}

function cow_merge_view_schema_cycle_against_target(SQLite3 $db, string $name, string $sql): ?string {
    $views = cow_merge_schema_object_sql_map($db, 'view');
    $name_key = strtolower($name);
    foreach (array_keys($views) as $existing_name) {
        if (strtolower((string)$existing_name) === $name_key) {
            unset($views[$existing_name]);
        }
    }
    $views[$name] = [
        'table' => $name,
        'sql' => $sql,
    ];
    $cycles = cow_merge_view_schema_dependency_cycles(array_keys($views), $views);
    return $cycles[$name_key] ?? null;
}

function cow_merge_validate_view_schema_acyclic(SQLite3 $db, string $name, string $sql): void {
    $cycle = cow_merge_view_schema_cycle_against_target($db, $name, $sql);
    if ($cycle !== null) {
        throw new InvalidArgumentException(
            'source view ' . $name . ' has unsupported cyclic view dependencies: ' . $cycle
        );
    }
}

function cow_merge_validate_trigger_program_acyclic(SQLite3 $db, string $name, string $sql): void {
    $cycle = cow_merge_trigger_program_cycle_against_target($db, $name, $sql);
    if ($cycle !== null) {
        throw new InvalidArgumentException(
            'source trigger ' . $name . ' has unsupported cyclic trigger dependencies: ' . $cycle
        );
    }
}

function cow_merge_sort_view_schema_objects(array $objects, array $source_objects): array {
    $dependency_map = cow_merge_view_schema_dependency_map($objects, $source_objects);
    $ordered = [];
    $state = [];
    $visit = function (string $object) use (&$visit, &$ordered, &$state, $dependency_map): void {
        $key = strtolower($object);
        if (($state[$key] ?? null) === 'done') {
            return;
        }
        if (($state[$key] ?? null) === 'visiting') {
            return;
        }
        $state[$key] = 'visiting';
        $dependencies = $dependency_map[$key]['dependencies'] ?? [];
        unset($dependencies[$key]);
        foreach ($dependencies as $dependency) {
            $visit($dependency);
        }
        $state[$key] = 'done';
        $ordered[] = $object;
    };

    foreach ($objects as $object) {
        $visit((string)$object);
    }

    return $ordered;
}

function cow_merge_trigger_referenced_tables(string $sql): array {
    return array_map(
        fn(array $reference): string => (string)$reference['name'],
        cow_merge_trigger_referenced_schema_objects($sql)
    );
}

function cow_merge_trigger_referenced_schema_objects(string $sql): array {
    $body = $sql;
    if (preg_match('/\bBEGIN\b(.*)\bEND\b/is', $sql, $match)) {
        $body = (string)$match[1];
    }
    return cow_merge_sql_referenced_schema_objects($body);
}

function cow_merge_trigger_dependency_schema_objects(string $sql): array {
    $references = [];
    $subject = cow_merge_trigger_subject($sql);
    if ($subject !== null) {
        $references[] = [
            'schema' => $subject['schema'],
            'name' => $subject['table'],
        ];
    }
    foreach (cow_merge_trigger_referenced_schema_objects($sql) as $reference) {
        $references[] = $reference;
    }

    $deduped = [];
    foreach ($references as $reference) {
        $schema = $reference['schema'] ?? null;
        $name = (string)($reference['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $key = strtolower((string)($schema ?? '')) . '.' . strtolower($name);
        $deduped[$key] = [
            'schema' => $schema,
            'name' => $name,
        ];
    }
    return array_values($deduped);
}

function cow_merge_trigger_subject(string $sql): ?array {
    $schema_identifier = cow_merge_identifier_pattern('schema_');
    $table_identifier = cow_merge_identifier_pattern('table_');
    $pattern =
        '/\bCREATE\s+(?:TEMP(?:ORARY)?\s+)?TRIGGER(?:\s+IF\s+NOT\s+EXISTS)?\s+' .
        cow_merge_identifier_pattern('trigger_') .
        '\s+(?:BEFORE|AFTER|INSTEAD\s+OF)\s+' .
        '(?P<event>INSERT|DELETE|UPDATE)(?:\s+OF\s+(?P<columns>.*?))?\s+' .
        'ON\s+(?:' . $schema_identifier . '\s*\.\s*)?' . $table_identifier . '/is';
    if (!preg_match($pattern, $sql, $match)) {
        return null;
    }
    $flat_match = cow_merge_regex_flat_match($match);
    $table = cow_merge_sql_reference_name($flat_match, 'table_');
    if ($table === null) {
        return null;
    }
    $columns = [];
    $raw_columns = cow_merge_regex_named_match($flat_match, 'columns');
    if ($raw_columns !== null) {
        if (preg_match_all('/' . cow_merge_identifier_pattern('column_') . '/', $raw_columns, $column_matches, PREG_SET_ORDER)) {
            foreach ($column_matches as $column_match) {
                $column = cow_merge_sql_reference_name(cow_merge_regex_flat_match($column_match), 'column_');
                if ($column !== null) {
                    $columns[] = $column;
                }
            }
        }
    }
    return [
        'schema' => cow_merge_sql_reference_name($flat_match, 'schema_'),
        'table' => $table,
        'event' => strtolower((string)$flat_match['event']),
        'columns' => array_values(array_unique($columns)),
    ];
}

function cow_merge_qualified_schema_name(?string $schema, string $name): string {
    if ($schema === null || $schema === '' || $schema === 'main') {
        return cow_merge_quote_ident($name);
    }
    return cow_merge_quote_ident($schema) . '.' . cow_merge_quote_ident($name);
}

function cow_merge_trigger_validation_sql(SQLite3 $db, string $sql): ?string {
    $subject = cow_merge_trigger_subject($sql);
    if ($subject === null) {
        return null;
    }
    $target = cow_merge_qualified_schema_name($subject['schema'], $subject['table']);
    $event = (string)$subject['event'];
    if ($event === 'insert') {
        return 'EXPLAIN INSERT INTO ' . $target . ' DEFAULT VALUES';
    }
    if ($event === 'delete') {
        return 'EXPLAIN DELETE FROM ' . $target . ' WHERE 0';
    }
    if ($event === 'update') {
        $columns = $subject['columns'];
        if (!$columns) {
            $columns = cow_merge_table_columns($db, (string)$subject['table']);
        }
        if (!$columns) {
            return null;
        }
        $assignments = array_map(
            fn(string $column): string => cow_merge_quote_ident($column) . ' = ' . cow_merge_quote_ident($column),
            $columns
        );
        return 'EXPLAIN UPDATE ' . $target . ' SET ' . implode(', ', $assignments) . ' WHERE 0';
    }
    return null;
}

function cow_merge_trigger_pseudo_column_references(string $sql): array {
    $refs = [];
    foreach (cow_merge_sql_split_statements($sql) as $statement) {
        $ignored_ranges = cow_merge_sql_ignored_ranges($statement);
        $pattern = '/\b(?P<pseudo>NEW|OLD)\s*\.\s*' . cow_merge_identifier_pattern('column_') . '/i';
        if (!preg_match_all($pattern, $statement, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($matches as $match) {
            if (cow_merge_sql_offset_in_ranges((int)$match[0][1], $ignored_ranges)) {
                continue;
            }
            $flat_match = cow_merge_regex_flat_match($match);
            $column = cow_merge_sql_reference_name($flat_match, 'column_');
            if ($column === null) {
                continue;
            }
            $refs[] = [
                'pseudo' => strtolower((string)$flat_match['pseudo']),
                'column' => $column,
            ];
        }
    }
    return $refs;
}

function cow_merge_validate_trigger_pseudo_columns(SQLite3 $db, string $name, string $sql): void {
    $subject = cow_merge_trigger_subject($sql);
    if ($subject === null) {
        return;
    }
    $table = (string)$subject['table'];
    $event = (string)$subject['event'];
    $columns = array_fill_keys(array_map('strtolower', cow_merge_table_columns($db, $table)), true);
    $table_sql = cow_merge_table_sql($db, $table);
    if ($table_sql !== null && !preg_match('/\bWITHOUT\s+ROWID\b/i', $table_sql)) {
        $columns['rowid'] = true;
        $columns['oid'] = true;
        $columns['_rowid_'] = true;
    }
    foreach (($subject['columns'] ?? []) as $column) {
        if (!isset($columns[strtolower((string)$column)])) {
            throw new InvalidArgumentException(
                'source trigger ' . $name . ' failed target trigger validation: no such column: ' . $column
            );
        }
    }
    foreach (cow_merge_trigger_pseudo_column_references($sql) as $ref) {
        $pseudo = (string)$ref['pseudo'];
        $column = (string)$ref['column'];
        if ($pseudo === 'old' && $event === 'insert') {
            throw new InvalidArgumentException(
                'source trigger ' . $name . ' failed target trigger validation: OLD is not available for INSERT triggers'
            );
        }
        if ($pseudo === 'new' && $event === 'delete') {
            throw new InvalidArgumentException(
                'source trigger ' . $name . ' failed target trigger validation: NEW is not available for DELETE triggers'
            );
        }
        if (!isset($columns[$column])) {
            throw new InvalidArgumentException(
                'source trigger ' . $name . ' failed target trigger validation: no such column: ' . strtoupper($pseudo) . '.' . $column
            );
        }
    }
}

function cow_merge_validate_trigger_program(SQLite3 $db, string $name, string $sql): void {
    cow_merge_validate_trigger_pseudo_columns($db, $name, $sql);
    $validation_sql = cow_merge_trigger_validation_sql($db, $sql);
    if ($validation_sql === null) {
        return;
    }
    cow_merge_test_hook('before_sqlite_query', $db, $validation_sql, 'failed to run source trigger ' . $name . ' target trigger validation');
    $res = @$db->query($validation_sql);
    if (!$res) {
        throw new InvalidArgumentException(
            'source trigger ' . $name . ' failed target trigger validation: ' . $db->lastErrorMsg()
        );
    }
    cow_merge_result_finalize_checked($res, 'failed to finalize source trigger ' . $name . ' target trigger validation result');
}

function cow_merge_missing_schema_references(SQLite3 $db, array $references): array {
    $missing = [];
    foreach ($references as $reference) {
        $schema = $reference['schema'];
        $table = (string)$reference['name'];
        if ($schema !== null && $schema !== 'main') {
            $missing[] = $schema . '.' . $table;
            continue;
        }
        if (!cow_merge_schema_object_exists($db, $table)) {
            $missing[] = $table;
        }
    }
    return $missing;
}

function cow_merge_schema_object_exists(SQLite3 $db, string $name): bool {
    $stmt = cow_merge_prepare_checked(
        $db,
        "SELECT 1 FROM sqlite_master WHERE type IN ('table', 'view') AND lower(name) = lower(:name) LIMIT 1",
        "failed to prepare schema object existence lookup for $name"
    );
    cow_merge_bind($stmt, ':name', $name);
    $res = cow_merge_execute_checked($stmt, $db, "failed to inspect schema object existence for $name");
    try {
        return (bool)$res->fetchArray(SQLITE3_NUM);
    } finally {
        cow_merge_result_finalize_checked($res, "failed to finalize schema object existence lookup for $name");
    }
}

function cow_merge_missing_trigger_references(SQLite3 $db, string $sql): array {
    return cow_merge_missing_schema_references($db, cow_merge_trigger_dependency_schema_objects($sql));
}

function cow_merge_validate_trigger_references(SQLite3 $db, string $name, string $sql): void {
    $missing = cow_merge_missing_trigger_references($db, $sql);
    if ($missing) {
        throw new InvalidArgumentException(
            'source trigger ' . $name . ' references missing target schema objects: ' . implode(', ', $missing)
        );
    }
}

function cow_merge_missing_view_references(SQLite3 $db, string $sql): array {
    return cow_merge_missing_schema_references($db, cow_merge_sql_referenced_schema_objects($sql));
}

function cow_merge_validate_view_references(SQLite3 $db, string $name, string $sql): void {
    $missing = cow_merge_missing_view_references($db, $sql);
    if ($missing) {
        throw new InvalidArgumentException(
            'source view ' . $name . ' references missing target schema objects: ' . implode(', ', $missing)
        );
    }
}

function cow_merge_validate_view_schema(SQLite3 $db, string $name, string $context): void {
    $sql = 'SELECT * FROM ' . cow_merge_quote_ident($name) . ' LIMIT 0';
    cow_merge_test_hook('before_sqlite_query', $db, $sql, 'failed to run source view ' . $name . " $context validation");
    $res = @$db->query($sql);
    if (!$res) {
        throw new InvalidArgumentException(
            'source view ' . $name . " is invalid during $context validation: " . $db->lastErrorMsg()
        );
    }
    cow_merge_result_finalize_checked($res, 'failed to finalize source view ' . $name . " $context validation result");
}

function cow_merge_table_dependent_views(SQLite3 $db, string $table, ?string $exclude_view = null): array {
    $views = [];
    $all_views = [];
    $res = cow_merge_query_checked(
        $db,
        "SELECT name, sql FROM sqlite_master WHERE type = 'view' AND sql IS NOT NULL ORDER BY name",
        "failed to read view dependencies for $table"
    );
    try {
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $name = (string)$row['name'];
            $all_views[$name] = [
                'name' => $name,
                'sql' => (string)$row['sql'],
            ];
        }
    } finally {
        cow_merge_result_finalize_checked($res, "failed to finalize view dependencies for $table");
    }

    $seen = [];
    if ($exclude_view !== null) {
        $seen[strtolower($exclude_view)] = true;
    }
    $queue = [$table];
    while ($queue) {
        $current = array_shift($queue);
        foreach ($all_views as $name => $view) {
            $key = strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            if (cow_merge_sql_references_table((string)$view['sql'], $current)) {
                $seen[$key] = true;
                $views[] = $view;
                $queue[] = $name;
            }
        }
    }
    return $views;
}

function cow_merge_view_trigger_dependencies(SQLite3 $db, array $views): array {
    $dependencies = [];
    foreach ($views as $view) {
        $name = is_array($view) ? (string)$view['name'] : (string)$view;
        foreach (cow_merge_table_rebuild_dependencies($db, $name) as $dependency) {
            if ((string)$dependency['type'] === 'trigger') {
                $dependencies[] = $dependency;
            }
        }
    }
    return $dependencies;
}

function cow_merge_table_dependent_triggers(SQLite3 $db, string $table, array $exclude_trigger_names = []): array {
    $excluded = array_fill_keys(array_map('strtolower', $exclude_trigger_names), true);
    $triggers = [];
    $stmt = cow_merge_query_checked(
        $db,
        "SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND sql IS NOT NULL ORDER BY name",
        "failed to read trigger dependencies for $table"
    );
    try {
        while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
            $name = (string)$row['name'];
            if (isset($excluded[strtolower($name)])) {
                continue;
            }
            if (cow_merge_sql_references_table((string)$row['sql'], $table)) {
                $triggers[] = [
                    'type' => 'trigger',
                    'name' => $name,
                    'sql' => (string)$row['sql'],
                ];
            }
        }
    } finally {
        cow_merge_result_finalize_checked($stmt, "failed to finalize trigger dependencies for $table");
    }
    return $triggers;
}

function cow_merge_trigger_body_dependencies(SQLite3 $db, array $schema_objects, array $exclude_trigger_names = []): array {
    $objects = [];
    foreach ($schema_objects as $schema_object) {
        $name = is_array($schema_object) ? (string)($schema_object['name'] ?? '') : (string)$schema_object;
        if ($name !== '') {
            $objects[strtolower($name)] = $name;
        }
    }
    if (!$objects) {
        return [];
    }

    $excluded = array_fill_keys(array_map('strtolower', $exclude_trigger_names), true);
    $triggers = [];
    $stmt = cow_merge_query_checked(
        $db,
        "SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND sql IS NOT NULL ORDER BY name",
        'failed to read trigger body dependencies'
    );
    try {
        while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
            $name = (string)$row['name'];
            if (isset($excluded[strtolower($name)])) {
                continue;
            }
            foreach ($objects as $object) {
                if (cow_merge_sql_references_table((string)$row['sql'], $object)) {
                    $triggers[] = [
                        'type' => 'trigger',
                        'name' => $name,
                        'sql' => (string)$row['sql'],
                    ];
                    $excluded[strtolower($name)] = true;
                    break;
                }
            }
        }
    } finally {
        cow_merge_result_finalize_checked($stmt, 'failed to finalize trigger body dependencies');
    }
    return $triggers;
}

function cow_merge_validate_views(SQLite3 $db, array $views, string $context): void {
    foreach ($views as $view) {
        $name = is_array($view) ? (string)$view['name'] : (string)$view;
        $sql = 'SELECT * FROM ' . cow_merge_quote_ident($name) . ' LIMIT 0';
        cow_merge_test_hook('before_sqlite_query', $db, $sql, 'failed to run target view ' . $name . " $context validation");
        $res = @$db->query($sql);
        if (!$res) {
            throw new InvalidArgumentException(
                'source schema resolution cannot preserve target view ' . $name .
                " during $context validation: " . $db->lastErrorMsg()
            );
        }
        cow_merge_result_finalize_checked($res, 'failed to finalize target view ' . $name . " $context validation result");
    }
}

function cow_merge_apply_source_table_rebuild(SQLite3 $target, string $table, string $source_sql, array $source_columns, array $target_columns): void {
    if (!cow_merge_table_rebuild_supported($source_columns, $target_columns)) {
        throw new InvalidArgumentException('source schema resolution can only rebuild tables with the same column order and unchanged primary key columns');
    }
    $dependent_views = cow_merge_table_dependent_views($target, $table);
    $dependent_view_triggers = cow_merge_view_trigger_dependencies($target, $dependent_views);
    cow_merge_validate_views($target, $dependent_views, 'pre-rebuild');
    $dependencies = cow_merge_table_rebuild_dependencies($target, $table);
    $tmp_table = '__forkpress_merge_rebuild_' . bin2hex(random_bytes(8));
    $create_sql = cow_merge_create_table_sql_for_name($source_sql, $tmp_table);
    if ($create_sql === null) {
        throw new InvalidArgumentException('source schema resolution could not parse the audited source CREATE TABLE statement');
    }
    $columns = array_map(fn($column) => (string)$column['name'], $source_columns);
    if (!$columns) {
        throw new InvalidArgumentException('source schema resolution cannot rebuild a table with no columns');
    }
    $quoted_columns = implode(', ', array_map('cow_merge_quote_ident', $columns));
    $has_pk = count(array_filter($target_columns, fn($column) => (int)$column['pk'] !== 0)) > 0;
    $insert_columns = $quoted_columns;
    $select_columns = $quoted_columns;
    if (!$has_pk) {
        $select_columns = cow_merge_hidden_rowid_selector($target, $table) . ', ' . $quoted_columns;
    }
    $target_savepoint_started = false;
    try {
        cow_merge_exec_checked(
            $target,
            'SAVEPOINT forkpress_schema_rebuild',
            'failed to start source table rebuild schema resolution savepoint'
        );
        $target_savepoint_started = true;
        cow_merge_exec_checked($target, $create_sql, 'failed to create rebuilt table');
        if (!$has_pk) {
            $insert_columns = cow_merge_hidden_rowid_selector($target, $tmp_table) . ', ' . $quoted_columns;
        }
        $copy_sql = 'INSERT INTO ' . cow_merge_quote_ident($tmp_table) . ' (' . $insert_columns . ') ' .
            'SELECT ' . $select_columns . ' FROM ' . cow_merge_quote_ident($table);
        cow_merge_exec_checked($target, $copy_sql, 'failed to copy rows into rebuilt table');
        foreach (array_reverse($dependent_views) as $view) {
            cow_merge_exec_checked(
                $target,
                'DROP VIEW ' . cow_merge_quote_ident((string)$view['name']),
                'failed to drop target view ' . $view['name'] . ' during schema rebuild'
            );
        }
        cow_merge_exec_checked($target, 'DROP TABLE ' . cow_merge_quote_ident($table), 'failed to drop old table during schema rebuild');
        cow_merge_exec_checked(
            $target,
            'ALTER TABLE ' . cow_merge_quote_ident($tmp_table) . ' RENAME TO ' . cow_merge_quote_ident($table),
            'failed to rename rebuilt table'
        );
        foreach ($dependencies as $dependency) {
            cow_merge_exec_checked(
                $target,
                (string)$dependency['sql'],
                'failed to recreate target ' . $dependency['type'] . ' ' . $dependency['name'] . ' after schema rebuild'
            );
            cow_merge_validate_schema_dependency_program($target, $dependency, 'schema rebuild');
        }
        foreach ($dependent_views as $view) {
            cow_merge_exec_checked(
                $target,
                (string)$view['sql'],
                'failed to recreate target view ' . $view['name'] . ' after schema rebuild'
            );
        }
        foreach ($dependent_view_triggers as $dependency) {
            cow_merge_exec_checked(
                $target,
                (string)$dependency['sql'],
                'failed to recreate dependent target ' . $dependency['type'] . ' ' . $dependency['name'] . ' after schema rebuild'
            );
            cow_merge_validate_schema_dependency_program($target, $dependency, 'schema rebuild');
        }
        cow_merge_validate_views($target, $dependent_views, 'post-rebuild');
        cow_merge_validate_foreign_key_integrity($target, 'source table rebuild schema resolution');
        cow_merge_release_savepoint_checked($target, 'forkpress_schema_rebuild', 'source table rebuild schema resolution');
        $target_savepoint_started = false;
    } catch (Throwable $e) {
        if ($target_savepoint_started) {
            $cleanup_failure = cow_merge_rollback_release_savepoint_checked(
                $target,
                'forkpress_schema_rebuild',
                'source table rebuild schema resolution',
                $e
            );
            if ($cleanup_failure !== null) {
                throw $cleanup_failure;
            }
        }
        throw $e;
    }
}

function cow_merge_validate_source_table_rebuild(SQLite3 $target, string $table, string $source_sql, array $source_columns, array $target_columns): void {
    $target_savepoint_started = false;
    try {
        cow_merge_exec_checked(
            $target,
            'SAVEPOINT forkpress_schema_rebuild_validation',
            'failed to start source table rebuild validation target savepoint'
        );
        $target_savepoint_started = true;
        cow_merge_apply_source_table_rebuild($target, $table, $source_sql, $source_columns, $target_columns);
        $cleanup_failure = cow_merge_rollback_release_savepoint_checked(
            $target,
            'forkpress_schema_rebuild_validation',
            'source table rebuild validation'
        );
        if ($cleanup_failure !== null) {
            throw $cleanup_failure;
        }
        $target_savepoint_started = false;
    } catch (Throwable $e) {
        if ($target_savepoint_started) {
            $cleanup_failure = cow_merge_rollback_release_savepoint_checked(
                $target,
                'forkpress_schema_rebuild_validation',
                'source table rebuild validation',
                $e
            );
            if ($cleanup_failure !== null) {
                throw $cleanup_failure;
            }
        }
        throw $e;
    }
}

function cow_merge_apply_source_view_schema_resolution(SQLite3 $target, string $view, ?string $source_sql): void {
    $dependent_views = cow_merge_table_dependent_views($target, $view, $view);
    $view_dependencies = cow_merge_table_rebuild_dependencies($target, $view);
    $dependent_view_triggers = cow_merge_view_trigger_dependencies($target, $dependent_views);
    $dependencies = array_merge(
        $view_dependencies,
        $dependent_view_triggers
    );
    $dependent_trigger_names = array_map(
        fn(array $dependency): string => (string)$dependency['name'],
        array_filter($dependencies, fn(array $dependency): bool => (string)($dependency['type'] ?? '') === 'trigger')
    );
    $dependent_trigger_bodies = $source_sql === null
        ? cow_merge_table_dependent_triggers($target, $view, $dependent_trigger_names)
        : [];
    if ($source_sql === null && $dependent_views) {
        $names = implode(', ', array_map(fn($dependency) => (string)$dependency['name'], $dependent_views));
        throw new InvalidArgumentException("source view drop resolution cannot leave dependent target views invalid: $names");
    }
    if ($source_sql === null && $dependent_trigger_bodies) {
        $names = implode(', ', array_map(fn($trigger) => (string)$trigger['name'], $dependent_trigger_bodies));
        throw new InvalidArgumentException("source view drop resolution cannot leave dependent target trigger programs invalid: $names");
    }
    if ($source_sql === null && $dependencies) {
        $names = implode(', ', array_map(fn($dependency) => (string)$dependency['type'] . ' ' . (string)$dependency['name'], $dependencies));
        throw new InvalidArgumentException("source view drop resolution cannot implicitly remove dependent target schema objects; resolve or remove them first: $names");
    }
    if ($source_sql !== null) {
        cow_merge_validate_view_references($target, $view, $source_sql);
        cow_merge_validate_view_schema_acyclic($target, $view, $source_sql);
    }
    cow_merge_validate_views($target, $dependent_views, 'pre-view-resolution');
    $target_savepoint_started = false;
    try {
        cow_merge_exec_checked(
            $target,
            'SAVEPOINT forkpress_view_resolution',
            'failed to start source view schema resolution savepoint'
        );
        $target_savepoint_started = true;
        foreach (array_reverse($dependent_views) as $dependency) {
            cow_merge_exec_checked(
                $target,
                'DROP VIEW ' . cow_merge_quote_ident((string)$dependency['name']),
                'failed to drop dependent target view ' . $dependency['name'] . ' during view schema resolution'
            );
        }
        if (cow_merge_schema_object_sql($target, 'view', $view) !== null) {
            cow_merge_exec_checked(
                $target,
                'DROP VIEW ' . cow_merge_quote_ident($view),
                'failed to drop target view during schema resolution'
            );
        }
        if ($source_sql !== null) {
            cow_merge_exec_checked(
                $target,
                $source_sql,
                'failed to apply source view schema resolution'
            );
        }
        if ($source_sql !== null) {
            cow_merge_validate_view_schema($target, $view, 'source-view-resolution');
        }
        foreach ($dependent_views as $dependency) {
            cow_merge_exec_checked(
                $target,
                (string)$dependency['sql'],
                'failed to recreate dependent target view ' . $dependency['name'] . ' after view schema resolution'
            );
        }
        foreach ($dependencies as $dependency) {
            cow_merge_exec_checked(
                $target,
                (string)$dependency['sql'],
                'failed to recreate dependent target ' . $dependency['type'] . ' ' . $dependency['name'] .
                    ' after view schema resolution'
            );
            cow_merge_validate_schema_dependency_program($target, $dependency, 'view schema resolution');
        }
        if ($source_sql !== null) {
            cow_merge_validate_views($target, [['name' => $view, 'sql' => $source_sql]], 'post-view-resolution');
        }
        cow_merge_validate_views($target, $dependent_views, 'post-view-resolution');
        cow_merge_release_savepoint_checked($target, 'forkpress_view_resolution', 'source view schema resolution');
        $target_savepoint_started = false;
    } catch (Throwable $e) {
        if ($target_savepoint_started) {
            $cleanup_failure = cow_merge_rollback_release_savepoint_checked(
                $target,
                'forkpress_view_resolution',
                'source view schema resolution',
                $e
            );
            if ($cleanup_failure !== null) {
                throw $cleanup_failure;
            }
        }
        throw $e;
    }
}

function cow_merge_validate_foreign_key_integrity(SQLite3 $db, string $context): void {
    $result = cow_merge_query_checked($db, 'PRAGMA foreign_key_check', $context . ' foreign-key validation error');
    $violations = [];
    try {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $violations[] = (string)($row['table'] ?? '(unknown)') .
                ' rowid=' . (string)($row['rowid'] ?? 'NULL') .
                ' parent=' . (string)($row['parent'] ?? '(unknown)') .
                ' fkid=' . (string)($row['fkid'] ?? '(unknown)');
            if (count($violations) >= 3) {
                break;
            }
        }
    } finally {
        cow_merge_result_finalize_checked($result, $context . ' foreign-key validation result');
    }
    if ($violations) {
        throw new RuntimeException($context . ' would leave target foreign-key violations: ' . implode('; ', $violations));
    }
}

function cow_merge_foreign_key_child_tables(SQLite3 $db, string $parent_table): array {
    $children = [];
    foreach (array_keys(cow_merge_table_sql_map($db)) as $child_table) {
        if (strcasecmp($child_table, $parent_table) === 0) {
            continue;
        }
        foreach (cow_merge_foreign_key_groups($db, $child_table) as $group) {
            $parent = (string)($group[0]['table'] ?? '');
            if ($parent !== '' && strcasecmp($parent, $parent_table) === 0) {
                $children[] = $child_table;
                break;
            }
        }
    }
    $children = array_values(array_unique($children));
    sort($children);
    return $children;
}

function cow_merge_apply_source_table_drop(SQLite3 $target, string $table, bool $apply): void {
    $child_tables = cow_merge_foreign_key_child_tables($target, $table);
    if ($child_tables) {
        throw new InvalidArgumentException(
            'source table drop resolution cannot leave dependent target foreign-key child tables invalid: ' .
            implode(', ', $child_tables)
        );
    }
    $attached_triggers = array_values(array_filter(
        cow_merge_table_rebuild_dependencies($target, $table),
        fn(array $dependency): bool => (string)($dependency['type'] ?? '') === 'trigger'
    ));
    $dependent_triggers = cow_merge_table_dependent_triggers(
        $target,
        $table,
        array_map(fn(array $dependency): string => (string)$dependency['name'], $attached_triggers)
    );
    if ($dependent_triggers) {
        throw new InvalidArgumentException(
            'source table drop resolution cannot leave dependent target trigger programs invalid: ' .
            implode(', ', array_map(fn(array $trigger): string => (string)$trigger['name'], $dependent_triggers))
        );
    }

    $target_savepoint_started = false;
    try {
        cow_merge_exec_checked(
            $target,
            'SAVEPOINT forkpress_source_table_drop',
            'failed to start source table drop schema resolution savepoint'
        );
        $target_savepoint_started = true;
        if (cow_merge_table_sql($target, $table) !== null) {
            cow_merge_exec_checked(
                $target,
                'DROP TABLE ' . cow_merge_quote_ident($table),
                'failed to apply source table drop schema resolution'
            );
        }
        cow_merge_validate_foreign_key_integrity($target, 'source table drop schema resolution');
        if ($apply) {
            cow_merge_release_savepoint_checked($target, 'forkpress_source_table_drop', 'source table drop schema resolution');
        } else {
            $cleanup_failure = cow_merge_rollback_release_savepoint_checked(
                $target,
                'forkpress_source_table_drop',
                'source table drop schema resolution'
            );
            if ($cleanup_failure !== null) {
                throw $cleanup_failure;
            }
        }
        $target_savepoint_started = false;
    } catch (Throwable $e) {
        if ($target_savepoint_started) {
            $cleanup_failure = cow_merge_rollback_release_savepoint_checked(
                $target,
                'forkpress_source_table_drop',
                'source table drop schema resolution',
                $e
            );
            if ($cleanup_failure !== null) {
                throw $cleanup_failure;
            }
        }
        throw $e;
    }
}

function cow_merge_validate_schema_dependency_program(SQLite3 $db, array $dependency, string $context): void {
    if ((string)($dependency['type'] ?? '') !== 'trigger') {
        return;
    }
    cow_merge_validate_trigger_program($db, (string)$dependency['name'], (string)$dependency['sql']);
}

function cow_merge_apply_source_index_schema_resolution(SQLite3 $target, string $index, ?string $source_sql, bool $apply): void {
    $target_savepoint_started = false;
    try {
        cow_merge_exec_checked(
            $target,
            'SAVEPOINT forkpress_index_resolution',
            'failed to start source index schema resolution savepoint'
        );
        $target_savepoint_started = true;
        if (cow_merge_index_sql($target, $index) !== null) {
            cow_merge_exec_checked(
                $target,
                'DROP INDEX ' . cow_merge_quote_ident($index),
                'failed to drop target index during schema resolution'
            );
        }
        if ($source_sql !== null) {
            cow_merge_exec_checked(
                $target,
                $source_sql,
                'failed to apply source index schema resolution'
            );
        }
        cow_merge_validate_foreign_key_integrity($target, 'source index schema resolution');
        if ($apply) {
            cow_merge_release_savepoint_checked($target, 'forkpress_index_resolution', 'source index schema resolution');
        } else {
            $cleanup_failure = cow_merge_rollback_release_savepoint_checked(
                $target,
                'forkpress_index_resolution',
                'source index schema resolution'
            );
            if ($cleanup_failure !== null) {
                throw $cleanup_failure;
            }
        }
        $target_savepoint_started = false;
    } catch (Throwable $e) {
        if ($target_savepoint_started) {
            $cleanup_failure = cow_merge_rollback_release_savepoint_checked(
                $target,
                'forkpress_index_resolution',
                'source index schema resolution',
                $e
            );
            if ($cleanup_failure !== null) {
                throw $cleanup_failure;
            }
        }
        throw $e;
    }
}

function cow_merge_resolve_schema_conflict(
    SQLite3 $meta,
    array $conflict,
    string $metadata_db,
    int $conflict_id,
    string $choice,
    bool $apply,
    string $note,
    string $reviewer
): array {
    $table = (string)$conflict['table_name'];
    $object = (string)($conflict['column_name'] ?? '');
    $conflict_type = (string)$conflict['conflict_type'];
    $source_db = (string)$conflict['source_db'];
    $target_db = (string)$conflict['target_db'];
    if (!is_file($source_db)) {
        throw new RuntimeException("source database for conflict #$conflict_id does not exist: $source_db");
    }
    if (!is_file($target_db)) {
        throw new RuntimeException("target database for conflict #$conflict_id does not exist: $target_db");
    }

    $source_payload = cow_merge_decode_payload_json((string)$conflict['source_payload'], 'source');
    $target_payload = cow_merge_decode_payload_json((string)$conflict['target_payload'], 'target');
    $source = cow_merge_open_db($source_db, SQLITE3_OPEN_READONLY);
    $target = cow_merge_open_db($target_db, SQLITE3_OPEN_READWRITE);
    try {
        $previous = null;
        $resolved = $choice === 'source' ? $source_payload : $target_payload;
        $validate_source = null;
        $apply_source = null;

        if (in_array($conflict_type, ['schema-source-changed', 'schema-column-conflict'], true) && $object !== '') {
            $source_column = cow_merge_schema_column_payload($source_payload);
            if ($source_column === null) {
                throw new RuntimeException("schema conflict #$conflict_id does not contain a source column payload");
            }
            $source_columns = cow_merge_columns_by_name(cow_merge_table_info($source, $table));
            $current_source_column = $source_columns[strtolower($object)] ?? null;
            if ($current_source_column === null || !cow_merge_column_signatures_equal($current_source_column, $source_column)) {
                throw new RuntimeException('source column no longer matches the audited conflict source value; rerun merge before resolving');
            }

            $target_columns = cow_merge_columns_by_name(cow_merge_table_info($target, $table));
            $current_target_column = $target_columns[strtolower($object)] ?? null;
            $previous = $current_target_column;
            if ($target_payload !== null && is_array($target_payload) && isset($target_payload['name'])) {
                if ($current_target_column === null || !cow_merge_column_signatures_equal($current_target_column, $target_payload)) {
                    throw new RuntimeException('target column no longer matches the audited conflict target value; rerun merge-audit before resolving');
                }
            } elseif ($target_payload === null) {
                if ($current_target_column !== null) {
                    throw new RuntimeException('target column no longer matches the audited missing-column target value; rerun merge-audit before resolving');
                }
            } else {
                $current_target_sql = cow_merge_table_sql($target, $table);
                if (!cow_merge_values_equal($current_target_sql, $target_payload)) {
                    throw new RuntimeException('target table schema no longer matches the audited conflict target value; rerun merge-audit before resolving');
                }
            }

            if ($choice === 'source') {
                $source_table_sql = cow_merge_table_sql($source, $table);
                if ($source_table_sql === null) {
                    throw new RuntimeException("source table for conflict #$conflict_id no longer exists: $table");
                }
                if ($current_target_column === null) {
                    $definition = cow_merge_schema_column_definition_payload($source_payload)
                        ?? cow_merge_column_definition_from_create_sql($source_table_sql, $object);
                    if ($definition === null || !cow_merge_column_definition_is_safe_to_add($definition, $source_column)) {
                        throw new InvalidArgumentException('source schema resolution can only apply columns that are safe for ALTER TABLE ADD COLUMN');
                    }
                    $resolved = ['column' => $source_column, 'definition' => $definition];
                    $target_branch = (string)$conflict['target_branch'];
                    $apply_source = function () use ($target, $meta, $conflict, $target_branch, $table, $definition): void {
                        $sql = 'ALTER TABLE ' . cow_merge_quote_ident($table) . ' ADD COLUMN ' . $definition;
                        cow_merge_exec_checked($target, $sql, 'failed to apply source column schema resolution');
                        cow_merge_refresh_table_row_identities($target, $meta, (int)$conflict['run_id'], $target_branch, $table);
                    };
                } else {
                    $target_table_sql = cow_merge_table_sql($target, $table);
                    if ($target_table_sql === null) {
                        throw new RuntimeException("target table for conflict #$conflict_id no longer exists: $table");
                    }
                    $source_columns = cow_merge_table_info($source, $table);
                    $target_columns = cow_merge_table_info($target, $table);
                    if (!cow_merge_table_rebuild_supported($source_columns, $target_columns)) {
                        throw new InvalidArgumentException('source schema resolution can only rebuild tables with the same column order and unchanged primary key columns');
                    }
                    $resolved = ['table_sql' => $source_table_sql, 'column' => $source_column];
                    $previous = $target_table_sql;
                    $target_branch = (string)$conflict['target_branch'];
                    $validate_source = function () use ($target, $table, $source_table_sql, $source_columns, $target_columns): void {
                        cow_merge_validate_source_table_rebuild($target, $table, $source_table_sql, $source_columns, $target_columns);
                    };
                    $apply_source = function () use ($target, $meta, $conflict, $target_branch, $table, $source_table_sql, $source_columns, $target_columns): void {
                        cow_merge_apply_source_table_rebuild($target, $table, $source_table_sql, $source_columns, $target_columns);
                        cow_merge_refresh_table_row_identities($target, $meta, (int)$conflict['run_id'], $target_branch, $table);
                    };
                }
            }
        } elseif (in_array($conflict_type, ['schema-source-added-index', 'schema-source-changed-index', 'schema-index-conflict', 'schema-source-dropped-index'], true) && $object !== '') {
            $source_sql = cow_merge_schema_index_sql_payload($source_payload);
            if ($source_sql === null && $conflict_type !== 'schema-source-dropped-index') {
                throw new RuntimeException("schema conflict #$conflict_id does not contain a source index SQL payload");
            }
            $current_source_sql = cow_merge_index_sql($source, $object);
            if (!cow_merge_values_equal($current_source_sql, $source_sql)) {
                throw new RuntimeException('source index no longer matches the audited conflict source value; rerun merge before resolving');
            }
            $current_target_sql = cow_merge_index_sql($target, $object);
            $previous = $current_target_sql;
            if ($target_payload === null) {
                if ($current_target_sql !== null) {
                    throw new RuntimeException('target index no longer matches the audited missing-index target value; rerun merge-audit before resolving');
                }
            } elseif (!cow_merge_values_equal($current_target_sql, $target_payload)) {
                throw new RuntimeException('target index no longer matches the audited conflict target value; rerun merge-audit before resolving');
            }
            if ($choice === 'source') {
                $resolved = $source_sql;
                $validate_source = function () use ($target, $object, $source_sql): void {
                    cow_merge_apply_source_index_schema_resolution($target, $object, $source_sql, false);
                };
                $apply_source = function () use ($target, $object, $source_sql): void {
                    cow_merge_apply_source_index_schema_resolution($target, $object, $source_sql, true);
                };
            }
        } elseif (in_array($conflict_type, [
            'schema-source-added-view',
            'schema-source-changed-view',
            'schema-source-dropped-view',
            'schema-view-conflict',
            'schema-source-added-trigger',
            'schema-source-changed-trigger',
            'schema-source-dropped-trigger',
            'schema-trigger-conflict',
        ], true)) {
            if ($object === '') {
                throw new InvalidArgumentException('schema view/trigger resolution requires a schema object name');
            }
            $type = str_contains($conflict_type, 'trigger') ? 'trigger' : 'view';
            $source_sql = cow_merge_schema_index_sql_payload($source_payload);
            if ($source_sql === null && !str_contains($conflict_type, 'source-dropped')) {
                throw new RuntimeException("schema conflict #$conflict_id does not contain a source $type SQL payload");
            }
            $current_source_sql = cow_merge_schema_object_sql($source, $type, $object);
            if (!cow_merge_values_equal($current_source_sql, $source_sql)) {
                throw new RuntimeException("source $type no longer matches the audited conflict source value; rerun merge before resolving");
            }
            $current_target_sql = cow_merge_schema_object_sql($target, $type, $object);
            $previous = $current_target_sql;
            if ($target_payload === null) {
                if ($current_target_sql !== null) {
                    throw new RuntimeException("target $type no longer matches the audited missing-$type target value; rerun merge-audit before resolving");
                }
            } elseif (!cow_merge_values_equal($current_target_sql, $target_payload)) {
                throw new RuntimeException("target $type no longer matches the audited conflict target value; rerun merge-audit before resolving");
            }
            if ($choice === 'source') {
                $resolved = $source_sql;
                $source_error = is_array($source_payload) ? (string)($source_payload['error'] ?? '') : '';
                $mutate_source = function () use ($target, $type, $object, $source_sql, $source_error): void {
                    if ($type === 'view') {
                        if (str_contains($source_error, 'unsupported cyclic') && str_contains($source_error, 'view dependencies')) {
                            throw new InvalidArgumentException($source_error);
                        }
                        cow_merge_apply_source_view_schema_resolution($target, $object, $source_sql);
                    } else {
                        if (str_contains($source_error, 'unsupported cyclic trigger dependencies')) {
                            throw new InvalidArgumentException($source_error);
                        }
                        if (cow_merge_schema_object_sql($target, $type, $object) !== null) {
                            $drop_sql = 'DROP ' . strtoupper($type) . ' ' . cow_merge_quote_ident($object);
                            cow_merge_exec_checked(
                                $target,
                                $drop_sql,
                                "failed to drop target $type during schema resolution"
                            );
                        }
                        if ($source_sql !== null) {
                            cow_merge_validate_trigger_references($target, $object, $source_sql);
                            cow_merge_validate_trigger_program_acyclic($target, $object, $source_sql);
                        }
                        if ($source_sql !== null) {
                            cow_merge_exec_checked(
                                $target,
                                $source_sql,
                                "failed to apply source $type schema resolution"
                            );
                        }
                        if ($source_sql !== null) {
                            cow_merge_validate_trigger_program($target, $object, $source_sql);
                        }
                    }
                };
                $validate_source = function () use ($target, $mutate_source): void {
                    $target_savepoint_started = false;
                    try {
                        cow_merge_exec_checked(
                            $target,
                            'SAVEPOINT forkpress_schema_object_resolution_validation',
                            'failed to start schema object resolution validation target savepoint'
                        );
                        $target_savepoint_started = true;
                        $mutate_source();
                        $cleanup_failure = cow_merge_rollback_release_savepoint_checked(
                            $target,
                            'forkpress_schema_object_resolution_validation',
                            'schema object resolution validation'
                        );
                        if ($cleanup_failure !== null) {
                            throw $cleanup_failure;
                        }
                        $target_savepoint_started = false;
                    } catch (Throwable $e) {
                        if ($target_savepoint_started) {
                            $cleanup_failure = cow_merge_rollback_release_savepoint_checked(
                                $target,
                                'forkpress_schema_object_resolution_validation',
                                'schema object resolution validation',
                                $e
                            );
                            if ($cleanup_failure !== null) {
                                throw $cleanup_failure;
                            }
                        }
                        throw $e;
                    }
                };
                $apply_source = $mutate_source;
            }
        } elseif ($conflict_type === 'schema-target-dropped-table' && $object === '') {
            $restore_payload = cow_merge_normalize_source_table_restore_payload($source_payload);
            if ($target_payload !== null) {
                throw new RuntimeException("schema conflict #$conflict_id has an unexpected target table payload");
            }
            $current_source_sql = cow_merge_table_sql($source, $table);
            if (!cow_merge_values_equal($current_source_sql, $restore_payload['table_sql'])) {
                throw new RuntimeException('source table schema no longer matches the audited conflict source value; rerun merge before resolving');
            }
            foreach ($restore_payload['indexes'] as $index) {
                if (!cow_merge_values_equal(cow_merge_index_sql($source, (string)$index['name']), (string)$index['sql'])) {
                    throw new RuntimeException('source table index no longer matches the audited conflict source value; rerun merge before resolving');
                }
            }
            foreach ($restore_payload['triggers'] as $trigger) {
                if (!cow_merge_values_equal(cow_merge_schema_object_sql($source, 'trigger', (string)$trigger['name']), (string)$trigger['sql'])) {
                    throw new RuntimeException('source table trigger no longer matches the audited conflict source value; rerun merge before resolving');
                }
            }
            $current_target_sql = cow_merge_table_sql($target, $table);
            $previous = $current_target_sql;
            if ($current_target_sql !== null) {
                throw new RuntimeException('target table no longer matches the audited dropped-table target value; rerun merge-audit before resolving');
            }
            if ($choice === 'source') {
                $source_branch = (string)$conflict['source_branch'];
                $target_branch = (string)$conflict['target_branch'];
                cow_merge_validate_source_table_restore_dependencies($source, $target, $table);
                $restore_payload_to_apply = cow_merge_filter_deferred_source_table_restore_payload(
                    $meta,
                    (int)$conflict['run_id'],
                    $table,
                    $restore_payload
                );
                $resolved = $restore_payload_to_apply;
                $validate_source = function () use ($source, $target, $meta, $conflict, $source_branch, $target_branch, $table, $restore_payload_to_apply): void {
                    cow_merge_validate_source_table_restore(
                        $source,
                        $target,
                        $meta,
                        (int)$conflict['run_id'],
                        $source_branch,
                        $target_branch,
                        $table,
                        $restore_payload_to_apply
                    );
                };
                $apply_source = function () use ($source, $target, $meta, $conflict, $source_branch, $target_branch, $table, $restore_payload_to_apply): void {
                    cow_merge_restore_source_table(
                        $source,
                        $target,
                        $meta,
                        (int)$conflict['run_id'],
                        $source_branch,
                        $target_branch,
                        $table,
                        $restore_payload_to_apply
                    );
                };
            }
        } elseif ($conflict_type === 'schema-source-dropped-table' && $object === '') {
            if ($source_payload !== null) {
                throw new RuntimeException("schema conflict #$conflict_id has an unexpected source table payload");
            }
            $current_source_sql = cow_merge_table_sql($source, $table);
            if ($current_source_sql !== null) {
                throw new RuntimeException('source table no longer matches the audited dropped-table source value; rerun merge before resolving');
            }
            $current_target_sql = cow_merge_table_sql($target, $table);
            $previous = $current_target_sql;
            if (!cow_merge_values_equal($current_target_sql, $target_payload)) {
                throw new RuntimeException('target table schema no longer matches the audited conflict target value; rerun merge-audit before resolving');
            }
            if ($choice === 'source') {
                $dependent_views = cow_merge_table_dependent_views($target, $table);
                if ($dependent_views) {
                    $names = implode(', ', array_map(fn($view) => (string)$view['name'], $dependent_views));
                    throw new InvalidArgumentException("source table drop resolution cannot leave dependent target views invalid: $names");
                }
                $dependent_schema = cow_merge_table_rebuild_dependencies($target, $table);
                if ($dependent_schema) {
                    $names = implode(', ', array_map(fn($dependency) => (string)$dependency['type'] . ' ' . (string)$dependency['name'], $dependent_schema));
                    throw new InvalidArgumentException("source table drop resolution cannot implicitly remove dependent target schema objects; resolve or remove them first: $names");
                }
                $resolved = null;
                $target_branch = (string)$conflict['target_branch'];
                $validate_source = function () use ($target, $table): void {
                    cow_merge_apply_source_table_drop($target, $table, false);
                };
                $apply_source = function () use ($target, $meta, $conflict, $target_branch, $table): void {
                    cow_merge_apply_source_table_drop($target, $table, true);
                    cow_merge_forget_table_row_identities($meta, (int)$conflict['run_id'], $target_branch, $table);
                };
            }
        } else {
            if ($choice === 'source') {
                if ($conflict_type !== 'schema-conflict' || $object !== '') {
                    throw new InvalidArgumentException('source schema resolution currently supports source-added columns/indexes/views/triggers, index/view/trigger rewrites or drops, source/target table drops with validation, and compatible table rebuilds only');
                }
                if (!is_string($source_payload)) {
                    throw new RuntimeException("schema conflict #$conflict_id does not contain a source table SQL payload");
                }
                $current_source_sql = cow_merge_table_sql($source, $table);
                if (!cow_merge_values_equal($current_source_sql, $source_payload)) {
                    throw new RuntimeException('source table schema no longer matches the audited conflict source value; rerun merge before resolving');
                }
                $current_target_sql = cow_merge_table_sql($target, $table);
                $previous = $current_target_sql;
                if (!cow_merge_values_equal($current_target_sql, $target_payload)) {
                    throw new RuntimeException('target table schema no longer matches the audited conflict target value; rerun merge-audit before resolving');
                }
                $source_columns = cow_merge_table_info($source, $table);
                $target_columns = cow_merge_table_info($target, $table);
                if (!cow_merge_table_rebuild_supported($source_columns, $target_columns)) {
                    throw new InvalidArgumentException('source schema resolution can only rebuild tables with the same column order and unchanged primary key columns');
                }
                $resolved = $source_payload;
                $target_branch = (string)$conflict['target_branch'];
                $validate_source = function () use ($target, $table, $source_payload, $source_columns, $target_columns): void {
                    cow_merge_validate_source_table_rebuild($target, $table, $source_payload, $source_columns, $target_columns);
                };
                $apply_source = function () use ($target, $meta, $conflict, $target_branch, $table, $source_payload, $source_columns, $target_columns): void {
                    cow_merge_apply_source_table_rebuild($target, $table, $source_payload, $source_columns, $target_columns);
                    cow_merge_refresh_table_row_identities($target, $meta, (int)$conflict['run_id'], $target_branch, $table);
                };
            } else {
                $current_target_sql = $object === '' ? cow_merge_table_sql($target, $table) : cow_merge_index_sql($target, $object);
                $previous = $current_target_sql;
                if (!cow_merge_values_equal($current_target_sql, $target_payload)) {
                    throw new RuntimeException('target schema no longer matches the audited conflict target value; rerun merge-audit before resolving');
                }
            }
        }

        if (!$apply && $choice === 'source' && $validate_source !== null) {
            $validate_source();
        }

        if ($apply) {
            $target_snapshot = cow_merge_snapshot_sqlite_db($target_db);
            $target_committed = false;
            $preserve_target_snapshot = false;
            cow_merge_exec_checked($meta, 'BEGIN IMMEDIATE', 'failed to start schema resolution metadata transaction');
            cow_merge_exec_checked($target, 'BEGIN IMMEDIATE', 'failed to start schema resolution target transaction');
            try {
                if ($choice === 'source' && $apply_source !== null) {
                    $apply_source();
                }
                $resolution_id = cow_merge_record_resolution(
                    $meta,
                    $conflict_id,
                    $choice,
                    true,
                    $note,
                    $reviewer,
                    $target_db,
                    $table,
                    '',
                    $object,
                    $previous,
                    $resolved
                );
                cow_merge_insert_review_note(
                    $meta,
                    'conflict',
                    $conflict_id,
                    'reviewed',
                    cow_merge_resolution_review_note($choice, $note),
                    $reviewer
                );
                cow_merge_exec_checked($target, 'COMMIT', 'failed to commit schema resolution target transaction');
                $target_committed = true;
                cow_merge_exec_checked($meta, 'COMMIT', 'failed to commit schema resolution metadata transaction');
            } catch (Throwable $e) {
                if ($target_committed) {
                    @$meta->exec('ROLLBACK');
                    try {
                        $target->close();
                        $target = null;
                        cow_merge_restore_sqlite_snapshot($target_snapshot);
                    } catch (Throwable $rollback_error) {
                        $preserve_target_snapshot = true;
                        $run_context = cow_merge_run_context($meta, (int)$conflict['run_id']);
                        $original_failure = cow_merge_failure_reason($e);
                        $rollback_failure = cow_merge_failure_reason($rollback_error);
                        $rollback_artifacts = [
                            'target_db_snapshot' => cow_merge_sqlite_snapshot_artifact($target_snapshot),
                        ];
                        cow_merge_record_rollback_failure_artifact(
                            $metadata_db,
                            (int)$conflict['run_id'],
                            $run_context['source_branch'],
                            $run_context['target_branch'],
                            $run_context['base_db'],
                            $run_context['source_db'],
                            $run_context['target_db'],
                            $original_failure,
                            $rollback_failure,
                            $rollback_artifacts
                        );
                        throw new CowMergeRollbackFailureException(
                            $e->getMessage() . '; target database rollback failed: ' . $rollback_error->getMessage(),
                            $original_failure,
                            $rollback_failure,
                            $rollback_artifacts,
                            $e
                        );
                    }
                } else {
                    @$target->exec('ROLLBACK');
                }
                @$meta->exec('ROLLBACK');
                throw $e;
            } finally {
                if (!$preserve_target_snapshot) {
                    cow_merge_cleanup_sqlite_snapshot($target_snapshot);
                }
            }
        } else {
            $resolution_id = null;
        }

        return [
            'metadata_db' => $metadata_db,
            'conflict_id' => $conflict_id,
            'resolution_id' => $resolution_id,
            'choice' => $choice,
            'applied' => $apply,
            'status' => $apply && $choice === 'source' ? 'applied' : 'validated',
            'target_db' => $target_db,
            'table_name' => $table,
            'column_name' => $object,
        ];
    } finally {
        $source->close();
        if ($target instanceof SQLite3) {
            $target->close();
        }
    }
}

function cow_merge_resolve_conflict(
    string $metadata_db,
    int $conflict_id,
    string $choice,
    bool $apply,
    string $note,
    string $reviewer,
    bool $after_revalidate = false
): array {
    if (!is_file($metadata_db)) {
        throw new InvalidArgumentException("merge metadata database does not exist: $metadata_db");
    }
    $meta = cow_merge_open_db($metadata_db, SQLITE3_OPEN_READWRITE);
    $target = null;
    try {
        cow_merge_ensure_metadata($meta);
        $stmt = cow_merge_prepare_checked(
            $meta,
            'SELECT c.id, c.run_id, c.table_name, c.row_identity, c.column_name, c.conflict_type, ' .
            'c.base_payload, c.source_payload, c.target_payload, r.source_db, r.target_db, r.source_branch, r.target_branch ' .
            'FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.id = :id',
            'failed to prepare conflict lookup'
        );
        cow_merge_bind($stmt, ':id', $conflict_id);
        $res = cow_merge_execute_checked($stmt, $meta, 'failed to read merge conflict');
        $conflict = $res->fetchArray(SQLITE3_ASSOC);
        cow_merge_result_finalize_checked($res, 'failed to finalize merge conflict lookup');
        if (!$conflict) {
            throw new InvalidArgumentException("conflict #$conflict_id does not exist in merge metadata");
        }
        $table = (string)$conflict['table_name'];
        $column = (string)($conflict['column_name'] ?? '');
        $conflict_type = (string)$conflict['conflict_type'];
        if ($table === '__files__') {
            $file_conflict_types = [
                'file-conflict',
                'file-add-collision',
                'file-target-deleted',
                'file-source-deleted',
                'file-directory-delete-conflict',
                'file-unsafe-symlink',
                'file-type-replacement-conflict',
                'file-unsupported-source-change',
            ];
            if (!in_array($conflict_type, $file_conflict_types, true)) {
                throw new InvalidArgumentException('resolve-conflict does not support this filesystem conflict type yet');
            }
            $identity = cow_merge_decode_payload_json((string)$conflict['row_identity'], 'file identity');
            if (!is_array($identity) || !isset($identity['path']) || !is_string($identity['path'])) {
                throw new RuntimeException("invalid filesystem identity for conflict #$conflict_id");
            }
            $path = $identity['path'];
            if (cow_merge_file_is_excluded($path)) {
                throw new RuntimeException("refusing to resolve ForkPress-managed filesystem path: $path");
            }
            $source_payload = cow_merge_decode_payload_json((string)$conflict['source_payload'], 'source');
            $target_payload = cow_merge_decode_payload_json((string)$conflict['target_payload'], 'target');
            $source_value = cow_merge_file_entry_without_path(is_array($source_payload) ? $source_payload : null);
            $target_value = cow_merge_file_entry_without_path(is_array($target_payload) ? $target_payload : null);
            $source_db = (string)$conflict['source_db'];
            $target_db = (string)$conflict['target_db'];
            $source_root = cow_merge_branch_root_from_db_path($source_db);
            $target_root = cow_merge_branch_root_from_db_path($target_db);
            if (!is_dir($source_root)) {
                throw new RuntimeException("source root for conflict #$conflict_id does not exist: $source_root");
            }
            if (!is_dir($target_root)) {
                throw new RuntimeException("target root for conflict #$conflict_id does not exist: $target_root");
            }

            if ($after_revalidate) {
                $target_entries = cow_merge_file_manifest_for_root($target_root)['entries'];
                $current_value = $target_entries[$path] ?? null;
                cow_merge_require_after_revalidate(
                    $meta,
                    $conflict_id,
                    (string)$conflict['source_payload'],
                    cow_merge_payload_json(cow_merge_file_path_payload($path, $current_value))
                );
                $target_value = $current_value;
            } else {
                $current_value = cow_merge_validate_current_file_entry($target_root, $path, $target_value, 'target');
            }
            if ($choice === 'source' && $source_value !== null) {
                cow_merge_validate_current_file_entry($source_root, $path, $source_value, 'source');
            }
            $resolved_value = $choice === 'source' ? $source_value : $target_value;

            if ($apply) {
                cow_merge_exec_checked($meta, 'BEGIN IMMEDIATE', 'failed to start filesystem resolution metadata transaction');
                $file_tx = cow_merge_file_transaction_begin();
                $file_tx_committed = false;
                $preserve_file_tx = false;
                try {
                    if ($choice === 'source') {
                        cow_merge_file_transaction_snapshot_path($file_tx, $target_root, $path);
                        cow_merge_apply_file_resolution($source_root, $target_root, $path, $source_value, $target_value);
                    }
                    $resolution_id = cow_merge_record_resolution(
                        $meta,
                        $conflict_id,
                        $choice,
                        true,
                        $note,
                        $reviewer,
                        $target_db,
                        $table,
                        (string)$conflict['row_identity'],
                        'path',
                        cow_merge_file_path_payload($path, $current_value),
                        cow_merge_file_path_payload($path, $resolved_value)
                    );
                    cow_merge_insert_review_note(
                        $meta,
                        'conflict',
                        $conflict_id,
                        'reviewed',
                        cow_merge_resolution_review_note($choice, $note),
                        $reviewer
                    );
                    cow_merge_exec_checked($meta, 'COMMIT', 'failed to commit filesystem resolution metadata transaction');
                    $file_tx_committed = true;
                } catch (Throwable $e) {
                    @$meta->exec('ROLLBACK');
                    if (!$file_tx_committed) {
                        try {
                            cow_merge_file_transaction_restore($file_tx, $target_root);
                        } catch (Throwable $rollback_error) {
                            $preserve_file_tx = true;
                            $run_context = cow_merge_run_context($meta, (int)$conflict['run_id']);
                            $original_failure = cow_merge_failure_reason($e);
                            $rollback_failure = cow_merge_failure_reason($rollback_error);
                            $rollback_artifacts = [
                                'filesystem_transaction' => cow_merge_file_transaction_artifact($file_tx, $target_root),
                            ];
                            cow_merge_record_rollback_failure_artifact(
                                $metadata_db,
                                (int)$conflict['run_id'],
                                $run_context['source_branch'],
                                $run_context['target_branch'],
                                $run_context['base_db'],
                                $run_context['source_db'],
                                $run_context['target_db'],
                                $original_failure,
                                $rollback_failure,
                                $rollback_artifacts
                            );
                            throw new CowMergeRollbackFailureException(
                                $e->getMessage() . '; filesystem rollback failed: ' . $rollback_error->getMessage(),
                                $original_failure,
                                $rollback_failure,
                                $rollback_artifacts,
                                $e
                            );
                        }
                    }
                    throw $e;
                } finally {
                    if (!$preserve_file_tx) {
                        cow_merge_file_transaction_cleanup($file_tx);
                    }
                }
            } else {
                $resolution_id = null;
            }

            return [
                'metadata_db' => $metadata_db,
                'conflict_id' => $conflict_id,
                'resolution_id' => $resolution_id,
                'choice' => $choice,
                'applied' => $apply,
                'status' => $apply && $choice === 'source' ? 'applied' : 'validated',
                'target_db' => $target_db,
                'table_name' => $table,
                'column_name' => 'path',
            ];
        }
        if (str_starts_with($conflict_type, 'schema-')) {
            if ($after_revalidate) {
                throw new InvalidArgumentException('--after-revalidate currently supports database row/cell conflicts and filesystem conflicts only');
            }
            return cow_merge_resolve_schema_conflict(
                $meta,
                $conflict,
                $metadata_db,
                $conflict_id,
                $choice,
                $apply,
                $note,
                $reviewer
            );
        }
        if ($table === '__plugins__') {
            throw new InvalidArgumentException('plugin validator conflicts cannot be resolved by generic merge-resolve; rerun the plugin validator or record updated validator findings');
        }
        $row_conflict_types = ['row-insert-collision', 'row-unique-collision', 'row-target-constraint', 'row-identity-ambiguous', 'row-target-deleted', 'row-source-deleted'];
        if ($conflict_type !== 'cell-conflict' && !in_array($conflict_type, $row_conflict_types, true)) {
            throw new InvalidArgumentException('resolve-conflict currently supports DB cell-conflict, row-insert-collision, row-unique-collision, row-target-constraint, row-identity-ambiguous, row-target-deleted, and row-source-deleted records only');
        }
        if ($conflict_type === 'cell-conflict' && $column === '') {
            throw new InvalidArgumentException('cell conflict resolution requires a column name');
        }
        $target_db = (string)$conflict['target_db'];
        if (!is_file($target_db)) {
            throw new RuntimeException("target database for conflict #$conflict_id does not exist: $target_db");
        }
        $identity = cow_merge_decode_payload_json((string)$conflict['row_identity'], 'row identity');
        if (!is_array($identity)) {
            throw new RuntimeException("invalid row identity for conflict #$conflict_id");
        }
        $source_value = cow_merge_decode_payload_json((string)$conflict['source_payload'], 'source');
        $base_value = cow_merge_decode_payload_json((string)$conflict['base_payload'], 'base');
        $target_value = cow_merge_decode_payload_json((string)$conflict['target_payload'], 'target');
        $resolved_value = $choice === 'source' ? $source_value : $target_value;

        $target = cow_merge_open_db($target_db, SQLITE3_OPEN_READWRITE);
        $pk_cols = cow_merge_pk_cols($target, $table);
        $source_branch = (string)$conflict['source_branch'];
        $target_branch = (string)$conflict['target_branch'];
        $where_identity = $identity;
        if ($pk_cols) {
            foreach ($pk_cols as $pk_col) {
                if (!array_key_exists($pk_col, $identity)) {
                    throw new RuntimeException("conflict #$conflict_id row identity does not include primary key column $pk_col");
                }
            }
        } else {
            $target_rowid = cow_merge_lookup_active_rowid_by_identity($meta, $target_branch, $table, $identity);
            if ($target_rowid === null) {
                $where_identity = [];
            } else {
                $where_identity = ['rowid' => $target_rowid];
            }
        }
        if ($conflict_type === 'cell-conflict') {
            if (!$pk_cols && !array_key_exists('rowid', $where_identity)) {
                throw new RuntimeException("cannot resolve $table.$column conflict because the target row no longer exists");
            }
            $current_value = cow_merge_select_current_cell($target, $table, $where_identity, $pk_cols, $column);
            if ($after_revalidate) {
                cow_merge_require_after_revalidate(
                    $meta,
                    $conflict_id,
                    (string)$conflict['source_payload'],
                    cow_merge_payload_json($current_value)
                );
                $target_value = $current_value;
                $resolved_value = $choice === 'source' ? $source_value : $target_value;
            } elseif (!cow_merge_values_equal($current_value, $target_value)) {
                throw new RuntimeException('target cell no longer matches the audited conflict target value; rerun merge-audit before resolving');
            }
        } else {
            $unique_collision_where_identity = null;
            if (in_array($conflict_type, ['row-insert-collision', 'row-unique-collision', 'row-identity-ambiguous'], true) && (!is_array($source_value) || !is_array($target_value))) {
                throw new RuntimeException("row conflict #$conflict_id does not contain row payloads");
            }
            if ($conflict_type === 'row-target-constraint' && !is_array($source_value) && $source_value !== null) {
                throw new RuntimeException("row conflict #$conflict_id does not contain a source row payload");
            }
            if ($conflict_type === 'row-target-constraint' && $source_value === null && !is_array($target_value)) {
                throw new RuntimeException("row conflict #$conflict_id does not contain a target row payload");
            }
            if ($conflict_type === 'row-target-deleted' && !is_array($source_value)) {
                throw new RuntimeException("row conflict #$conflict_id does not contain a source row payload");
            }
            if ($conflict_type === 'row-source-deleted' && !is_array($target_value)) {
                throw new RuntimeException("row conflict #$conflict_id does not contain a target row payload");
            }
            if ($conflict_type === 'row-unique-collision') {
                $is_update_unique_collision = is_array($base_value);
                $unique_collision = cow_merge_find_unique_collision($target, $table, $source_value, true);
                if ($unique_collision === null) {
                    throw new RuntimeException('target row no longer matches the audited unique collision; rerun merge-audit before resolving');
                }
                $current_value = $unique_collision['row'];
                if ($pk_cols) {
                    $unique_collision_where_identity = [];
                    foreach ($pk_cols as $pk_col) {
                        if (!array_key_exists($pk_col, $current_value)) {
                            throw new RuntimeException("current $table unique collision row does not include primary key column $pk_col");
                        }
                        $unique_collision_where_identity[$pk_col] = $current_value[$pk_col];
                    }
                    $source_identity_current = cow_merge_select_current_row($target, $table, $identity, $pk_cols);
                    if ($is_update_unique_collision) {
                        if ($source_identity_current === null) {
                            throw new RuntimeException('source row identity no longer exists in target; rerun merge-audit before resolving unique collision');
                        }
                        if (!cow_merge_row_values_equal($source_identity_current, $base_value, cow_merge_all_columns(array_keys($source_identity_current), array_keys($base_value)))) {
                            throw new RuntimeException('source row identity no longer matches the audited conflict base value; rerun merge-audit before resolving unique collision');
                        }
                    } elseif ($source_identity_current !== null && !cow_merge_row_values_equal($source_identity_current, $current_value, cow_merge_all_columns(array_keys($source_identity_current), array_keys($current_value)))) {
                        throw new RuntimeException('source row identity already exists in target; rerun merge-audit before resolving unique collision');
                    }
                } else {
                    if (!isset($unique_collision['rowid'])) {
                        throw new RuntimeException("cannot resolve $table unique collision because the target rowid is unavailable");
                    }
                    $unique_collision_where_identity = ['rowid' => (int)$unique_collision['rowid']];
                    if ($is_update_unique_collision) {
                        if (!array_key_exists('rowid', $where_identity)) {
                            throw new RuntimeException('source row identity no longer exists in target; rerun merge-audit before resolving unique collision');
                        }
                        $source_identity_current = cow_merge_select_current_row($target, $table, $where_identity, $pk_cols);
                        if ($source_identity_current === null) {
                            throw new RuntimeException('source row identity no longer exists in target; rerun merge-audit before resolving unique collision');
                        }
                        if (!cow_merge_row_values_equal($source_identity_current, $base_value, cow_merge_all_columns(array_keys($source_identity_current), array_keys($base_value)))) {
                            throw new RuntimeException('source row identity no longer matches the audited conflict base value; rerun merge-audit before resolving unique collision');
                        }
                    }
                }
                if ($after_revalidate) {
                    cow_merge_require_after_revalidate(
                        $meta,
                        $conflict_id,
                        (string)$conflict['source_payload'],
                        cow_merge_payload_json($current_value)
                    );
                    $target_value = $current_value;
                } else {
                    $row_columns = cow_merge_all_columns(array_keys($target_value), array_keys($current_value));
                    if (!cow_merge_row_values_equal($current_value, $target_value, $row_columns)) {
                        throw new RuntimeException('target row no longer matches the audited conflict target value; rerun merge-audit before resolving');
                    }
                }
            } else {
                $current_value = $pk_cols || array_key_exists('rowid', $where_identity)
                    ? cow_merge_select_current_row($target, $table, $where_identity, $pk_cols)
                    : null;
            }
            if ($after_revalidate && $conflict_type !== 'row-unique-collision') {
                cow_merge_require_after_revalidate(
                    $meta,
                    $conflict_id,
                    (string)$conflict['source_payload'],
                    cow_merge_payload_json($current_value)
                );
                $target_value = $current_value;
            } elseif ($conflict_type === 'row-target-deleted' || ($conflict_type === 'row-target-constraint' && $target_value === null)) {
                if ($current_value !== null) {
                    throw new RuntimeException('target row no longer matches the audited conflict target value; rerun merge-audit before resolving');
                }
            } elseif ($current_value === null) {
                throw new RuntimeException("cannot resolve $table row conflict because the target row no longer exists");
            } else {
                $row_columns = cow_merge_all_columns(array_keys($target_value), array_keys($current_value));
                if (!cow_merge_row_values_equal($current_value, $target_value, $row_columns)) {
                    throw new RuntimeException('target row no longer matches the audited conflict target value; rerun merge-audit before resolving');
                }
            }
            $resolved_value = $choice === 'source' ? $source_value : $target_value;
        }

        if ($apply) {
            $target_snapshot = cow_merge_snapshot_sqlite_db($target_db);
            $target_committed = false;
            $preserve_target_snapshot = false;
            cow_merge_exec_checked($meta, 'BEGIN IMMEDIATE', 'failed to start row resolution metadata transaction');
            cow_merge_exec_checked($target, 'BEGIN IMMEDIATE', 'failed to start row resolution target transaction');
            try {
                if ($choice === 'source') {
                    if ($conflict_type === 'cell-conflict') {
                        $columns = cow_merge_table_columns($target, $table);
                        $expected_row = cow_merge_select_current_row($target, $table, $where_identity, $pk_cols);
                        if ($expected_row === null) {
                            throw new RuntimeException("cannot resolve $table.$column conflict because the target row no longer exists");
                        }
                        $expected_row[$column] = $source_value;
                        $update_result = cow_merge_try_update_row_preserving_payload(
                            $target,
                            $table,
                            $where_identity,
                            $pk_cols,
                            $expected_row,
                            $columns,
                            "failed to apply conflict resolution to $table.$column",
                            "failed to finalize conflict resolution update for $table.$column"
                        );
                        cow_merge_require_source_apply_result($update_result, "failed to resolve $table.$column with source value");
                        if (!$pk_cols) {
                            $updated_row = cow_merge_select_current_row($target, $table, $where_identity, $pk_cols);
                            if ($updated_row !== null) {
                                cow_merge_remember_row_identity($meta, (int)$conflict['run_id'], $target_branch, $table, (int)$where_identity['rowid'], $identity, $updated_row);
                            }
                        }
                    } elseif ($conflict_type === 'row-source-deleted') {
                        cow_merge_delete_row($target, $table, $where_identity, $pk_cols);
                        if (!$pk_cols) {
                            cow_merge_forget_row_identity($meta, (int)$conflict['run_id'], $target_branch, $table, (int)$where_identity['rowid']);
                        }
                    } elseif (
                        $after_revalidate
                        && $target_value === null
                        && in_array($conflict_type, ['row-insert-collision', 'row-identity-ambiguous'], true)
                    ) {
                        $columns = cow_merge_table_columns($target, $table);
                        $insert_result = cow_merge_try_insert_row_preserving_payload($target, $table, $source_value, $columns, $identity, $pk_cols);
                        cow_merge_require_source_apply_result($insert_result, "failed to restore $table source row after revalidation");
                        $new_rowid = (int)($insert_result['rowid'] ?? 0);
                        if (!$pk_cols) {
                            cow_merge_remember_row_identity($meta, (int)$conflict['run_id'], $target_branch, $table, $new_rowid, $identity, $source_value);
                        }
                    } elseif ($conflict_type === 'row-target-deleted') {
                        $columns = cow_merge_table_columns($target, $table);
                        $insert_result = cow_merge_try_insert_row_preserving_payload($target, $table, $source_value, $columns, $identity, $pk_cols);
                        cow_merge_require_source_apply_result($insert_result, "failed to restore $table source row");
                        $new_rowid = (int)($insert_result['rowid'] ?? 0);
                        if (!$pk_cols) {
                            cow_merge_remember_row_identity($meta, (int)$conflict['run_id'], $target_branch, $table, $new_rowid, $identity, $source_value);
                        }
                    } elseif ($conflict_type === 'row-target-constraint') {
                        $columns = cow_merge_table_columns($target, $table);
                        if ($source_value === null) {
                            cow_merge_delete_row($target, $table, $where_identity, $pk_cols);
                            if (!$pk_cols) {
                                cow_merge_forget_row_identity($meta, (int)$conflict['run_id'], $target_branch, $table, (int)$where_identity['rowid']);
                            }
                        } elseif ($target_value === null) {
                            if (!$pk_cols) {
                                $source_rowid = cow_merge_lookup_active_rowid_by_identity($meta, $source_branch, $table, $identity);
                                if ($source_rowid !== null && cow_merge_load_keyless_physical_row($target, $table, $source_rowid) === null) {
                                    $insert_result = cow_merge_try_insert_row_with_rowid_preserving_payload($target, $table, $source_rowid, $source_value, $columns);
                                } else {
                                    $insert_result = cow_merge_try_insert_row_preserving_payload($target, $table, $source_value, $columns, $identity, $pk_cols);
                                }
                                cow_merge_require_source_apply_result($insert_result, "failed to insert $table source row blocked by target state");
                                $new_rowid = (int)($insert_result['rowid'] ?? 0);
                                cow_merge_remember_row_identity($meta, (int)$conflict['run_id'], $target_branch, $table, $new_rowid, $identity, $source_value);
                            } else {
                                $insert_result = cow_merge_try_insert_row_preserving_payload($target, $table, $source_value, $columns, $identity, $pk_cols);
                                cow_merge_require_source_apply_result($insert_result, "failed to insert $table source row blocked by target state");
                            }
                        } else {
                            $update_result = cow_merge_try_update_row_preserving_payload($target, $table, $where_identity, $pk_cols, $source_value, $columns);
                            cow_merge_require_source_apply_result($update_result, "failed to update $table source row blocked by target state");
                            if (!$pk_cols) {
                                cow_merge_remember_row_identity($meta, (int)$conflict['run_id'], $target_branch, $table, (int)$where_identity['rowid'], $identity, $source_value);
                            }
                        }
                    } elseif ($conflict_type === 'row-unique-collision') {
                        $columns = cow_merge_table_columns($target, $table);
                        if (!is_array($unique_collision_where_identity)) {
                            throw new RuntimeException("cannot resolve $table unique collision because the target identity is unavailable");
                        }
                        cow_merge_delete_row($target, $table, $unique_collision_where_identity, $pk_cols);
                        if (!$pk_cols) {
                            cow_merge_forget_row_identity($meta, (int)$conflict['run_id'], $target_branch, $table, (int)$unique_collision_where_identity['rowid']);
                        }
                        if ($is_update_unique_collision) {
                            $update_result = cow_merge_try_update_row_preserving_payload($target, $table, $where_identity, $pk_cols, $source_value, $columns);
                            cow_merge_require_source_apply_result($update_result, "failed to update $table source row after unique collision removal");
                            if (!$pk_cols) {
                                cow_merge_remember_row_identity($meta, (int)$conflict['run_id'], $target_branch, $table, (int)$where_identity['rowid'], $identity, $source_value);
                            }
                        } else {
                            $insert_result = cow_merge_try_insert_row_preserving_payload($target, $table, $source_value, $columns, $identity, $pk_cols);
                            cow_merge_require_source_apply_result($insert_result, "failed to insert $table source row after unique collision removal");
                            $new_rowid = (int)($insert_result['rowid'] ?? 0);
                            if (!$pk_cols) {
                                cow_merge_remember_row_identity($meta, (int)$conflict['run_id'], $target_branch, $table, $new_rowid, $identity, $source_value);
                            }
                        }
                    } else {
                        $columns = cow_merge_table_columns($target, $table);
                        $update_result = cow_merge_try_update_row_preserving_payload($target, $table, $where_identity, $pk_cols, $source_value, $columns);
                        cow_merge_require_source_apply_result($update_result, "failed to update $table source row");
                        if (!$pk_cols) {
                            cow_merge_remember_row_identity($meta, (int)$conflict['run_id'], $target_branch, $table, (int)$where_identity['rowid'], $identity, $source_value);
                        }
                    }
                }
                $resolution_id = cow_merge_record_resolution(
                    $meta,
                    $conflict_id,
                    $choice,
                    true,
                    $note,
                    $reviewer,
                    $target_db,
                    $table,
                    (string)$conflict['row_identity'],
                    $conflict_type === 'cell-conflict' ? $column : '',
                    $current_value,
                    $resolved_value
                );
                cow_merge_insert_review_note(
                    $meta,
                    'conflict',
                    $conflict_id,
                    'reviewed',
                    cow_merge_resolution_review_note($choice, $note),
                    $reviewer
                );
                cow_merge_exec_checked($target, 'COMMIT', 'failed to commit row resolution target transaction');
                $target_committed = true;
                cow_merge_exec_checked($meta, 'COMMIT', 'failed to commit row resolution metadata transaction');
            } catch (Throwable $e) {
                if ($target_committed) {
                    @$meta->exec('ROLLBACK');
                    try {
                        $target->close();
                        $target = null;
                        cow_merge_restore_sqlite_snapshot($target_snapshot);
                    } catch (Throwable $rollback_error) {
                        $preserve_target_snapshot = true;
                        $run_context = cow_merge_run_context($meta, (int)$conflict['run_id']);
                        $original_failure = cow_merge_failure_reason($e);
                        $rollback_failure = cow_merge_failure_reason($rollback_error);
                        $rollback_artifacts = [
                            'target_db_snapshot' => cow_merge_sqlite_snapshot_artifact($target_snapshot),
                        ];
                        cow_merge_record_rollback_failure_artifact(
                            $metadata_db,
                            (int)$conflict['run_id'],
                            $run_context['source_branch'],
                            $run_context['target_branch'],
                            $run_context['base_db'],
                            $run_context['source_db'],
                            $run_context['target_db'],
                            $original_failure,
                            $rollback_failure,
                            $rollback_artifacts
                        );
                        throw new CowMergeRollbackFailureException(
                            $e->getMessage() . '; target database rollback failed: ' . $rollback_error->getMessage(),
                            $original_failure,
                            $rollback_failure,
                            $rollback_artifacts,
                            $e
                        );
                    }
                } else {
                    @$target->exec('ROLLBACK');
                }
                @$meta->exec('ROLLBACK');
                throw $e;
            } finally {
                if (!$preserve_target_snapshot) {
                    cow_merge_cleanup_sqlite_snapshot($target_snapshot);
                }
            }
        } else {
            $resolution_id = null;
        }

        return [
            'metadata_db' => $metadata_db,
            'conflict_id' => $conflict_id,
            'resolution_id' => $resolution_id,
            'choice' => $choice,
            'applied' => $apply,
            'status' => $apply && $choice === 'source' ? 'applied' : 'validated',
            'target_db' => $target_db,
            'table_name' => $table,
            'column_name' => $conflict_type === 'cell-conflict' ? $column : null,
        ];
    } finally {
        if ($target instanceof SQLite3) {
            $target->close();
        }
        $meta->close();
    }
}

function cow_merge_audit_apply_shortcuts(array $filters): array {
    $id_band_skips = (string)($filters['id_band_skips'] ?? '') === '1';
    $target_kept = (string)($filters['target_kept'] ?? '') === '1';
    $review = (string)($filters['review'] ?? '') === '1';
    $resolution_status = ($filters['resolution_status'] ?? null) !== null && (string)$filters['resolution_status'] !== '';
    $group_by = (string)($filters['group_by'] ?? 'none');
    if ($id_band_skips && $target_kept) {
        throw new InvalidArgumentException('--id-band-skips cannot be combined with --target-kept');
    }
    if ($id_band_skips && $review) {
        throw new InvalidArgumentException('--id-band-skips cannot be combined with --review');
    }
    if ($target_kept && $review) {
        throw new InvalidArgumentException('--target-kept cannot be combined with --review');
    }
    if ($id_band_skips && $resolution_status) {
        throw new InvalidArgumentException('--id-band-skips cannot be combined with --resolution-status');
    }
    if ($target_kept && $resolution_status) {
        throw new InvalidArgumentException('--target-kept cannot be combined with --resolution-status');
    }
    if ($review) {
        if (($filters['decision'] ?? null) !== null) {
            throw new InvalidArgumentException('--review cannot be combined with --decision');
        }
        if (($filters['conflict_type'] ?? null) !== null) {
            throw new InvalidArgumentException('--review cannot be combined with --conflict-type');
        }
    }
    if ($resolution_status) {
        if (cow_merge_audit_filter_is_default_all($filters, 'records')) {
            $filters['records'] = 'resolutions';
        } elseif (($filters['records'] ?? null) !== 'resolutions') {
            throw new InvalidArgumentException('--resolution-status can only be combined with --records resolutions');
        }
        if (($filters['decision'] ?? null) !== null) {
            throw new InvalidArgumentException('--resolution-status cannot be combined with --decision');
        }
        if (($filters['conflict_type'] ?? null) !== null) {
            throw new InvalidArgumentException('--resolution-status cannot be combined with --conflict-type');
        }
        if ($review) {
            throw new InvalidArgumentException('--resolution-status cannot be combined with --review');
        }
        if (($filters['review_status'] ?? null) !== null) {
            throw new InvalidArgumentException('--resolution-status cannot be combined with --review-status');
        }
    }
    if ($target_kept && cow_merge_audit_filter_is_default_all($filters, 'records')) {
        $filters['records'] = 'decisions';
    }
    if ($group_by !== '' && $group_by !== 'none') {
        if (cow_merge_audit_filter_is_default_all($filters, 'records')) {
            $filters['records'] = 'resolutions';
        } elseif (!in_array(($filters['records'] ?? null), ['conflicts', 'decisions', 'resolutions'], true)) {
            throw new InvalidArgumentException('--group-by can only be combined with --records conflicts, decisions, or resolutions');
        }
        $records = (string)($filters['records'] ?? 'resolutions');
        if ($records === 'resolutions' && !in_array($group_by, ['table', 'status', 'path'], true)) {
            throw new InvalidArgumentException('--records resolutions supports --group-by table, status, or path');
        }
        if ($records === 'conflicts' && !in_array($group_by, ['table', 'type', 'path', 'severity'], true)) {
            throw new InvalidArgumentException('--records conflicts supports --group-by table, type, path, or severity');
        }
        if ($records === 'decisions' && !in_array($group_by, ['table', 'type', 'path'], true)) {
            throw new InvalidArgumentException('--records decisions supports --group-by table, type, or path');
        }
        if (($filters['decision'] ?? null) !== null && $records !== 'decisions') {
            throw new InvalidArgumentException('--group-by cannot be combined with --decision unless --records decisions is used');
        }
        if (($filters['conflict_type'] ?? null) !== null) {
            throw new InvalidArgumentException('--group-by cannot be combined with --conflict-type');
        }
        if ($id_band_skips) {
            throw new InvalidArgumentException('--group-by cannot be combined with --id-band-skips');
        }
        if ($review) {
            throw new InvalidArgumentException('--group-by cannot be combined with --review');
        }
        if (($filters['review_status'] ?? null) !== null) {
            throw new InvalidArgumentException('--group-by cannot be combined with --review-status');
        }
    }
    if (!$id_band_skips) {
        if (!$target_kept) {
            return $filters;
        }
        if (cow_merge_audit_filter_is_default_all($filters, 'records')) {
            $filters['records'] = 'decisions';
        } elseif (($filters['records'] ?? null) !== 'decisions') {
            throw new InvalidArgumentException('--target-kept can only be combined with --records decisions');
        }
        if (($filters['decision'] ?? null) === null) {
            $filters['decision'] = 'target-kept';
        } elseif (($filters['decision'] ?? null) !== 'target-kept') {
            throw new InvalidArgumentException('--target-kept cannot be combined with another --decision value');
        }
        if (($filters['conflict_type'] ?? null) !== null) {
            throw new InvalidArgumentException('--target-kept cannot be combined with --conflict-type');
        }
        return $filters;
    }
    if (($filters['scope'] ?? null) === null) {
        $filters['scope'] = 'db';
    } elseif (($filters['scope'] ?? null) === 'files') {
        throw new InvalidArgumentException('--id-band-skips cannot be combined with --scope files');
    }
    if (cow_merge_audit_filter_is_default_all($filters, 'records')) {
        $filters['records'] = 'decisions';
    } elseif (($filters['records'] ?? null) === 'conflicts') {
        throw new InvalidArgumentException('--id-band-skips cannot be combined with --records conflicts');
    }
    if (($filters['decision'] ?? null) === null) {
        $filters['decision'] = 'id-band-skipped';
    } elseif (($filters['decision'] ?? null) !== 'id-band-skipped') {
        throw new InvalidArgumentException('--id-band-skips cannot be combined with another --decision value');
    }
    if (($filters['conflict_type'] ?? null) !== null) {
        throw new InvalidArgumentException('--id-band-skips cannot be combined with --conflict-type');
    }
    if (($filters['path'] ?? null) !== null || ($filters['path_prefix'] ?? null) !== null) {
        throw new InvalidArgumentException('--id-band-skips cannot be combined with file path filters');
    }
    return $filters;
}

function cow_merge_audit_filter_is_default_all(array $filters, string $key): bool {
    if (!array_key_exists($key, $filters)) {
        return true;
    }
    $value = $filters[$key];
    return $value === null || $value === '' || $value === 'all';
}

function cow_merge_audit_filters(array $filters = []): array {
    $filters = cow_merge_audit_apply_shortcuts($filters);
    $scope = cow_merge_audit_scope($filters['scope'] ?? null);
    $records = cow_merge_audit_records($filters['records'] ?? null);
    $path = cow_merge_audit_file_path_filter($filters['path'] ?? null, 'path');
    $path_prefix = cow_merge_audit_file_path_filter($filters['path_prefix'] ?? null, 'path-prefix');
    if ($path !== null && $path_prefix !== null) {
        throw new InvalidArgumentException('--path and --path-prefix cannot be used together');
    }
    if ($scope !== 'all' && $scope !== 'files' && ($path !== null || $path_prefix !== null)) {
        throw new InvalidArgumentException('--path and --path-prefix require file audit scope');
    }
    if ($records === 'rollback-failures') {
        if ($scope !== 'all') {
            throw new InvalidArgumentException('--records rollback-failures cannot be combined with --scope');
        }
        foreach (['conflict_type', 'decision', 'review_status', 'resolution_status'] as $key) {
            if (($filters[$key] ?? null) !== null && (string)$filters[$key] !== '') {
                throw new InvalidArgumentException('--records rollback-failures cannot be combined with --' . str_replace('_', '-', $key));
            }
        }
        if ($path !== null || $path_prefix !== null) {
            throw new InvalidArgumentException('--records rollback-failures cannot be combined with file path filters');
        }
        if (
            (string)($filters['id_band_skips'] ?? '') === '1' ||
            (string)($filters['target_kept'] ?? '') === '1' ||
            (string)($filters['review'] ?? '') === '1'
        ) {
            throw new InvalidArgumentException('--records rollback-failures cannot be combined with audit shortcuts');
        }
        if (cow_merge_audit_group_by($filters['group_by'] ?? null) !== 'none') {
            throw new InvalidArgumentException('--records rollback-failures cannot be combined with --group-by');
        }
    }
    return [
        'scope' => $scope,
        'records' => $records,
        'conflict_type' => cow_merge_audit_filter_text($filters['conflict_type'] ?? null, 'conflict-type'),
        'decision' => cow_merge_audit_filter_text($filters['decision'] ?? null, 'decision'),
        'path' => $path,
        'path_prefix' => $path_prefix,
        'id_band_skips' => (string)($filters['id_band_skips'] ?? '') === '1',
        'target_kept' => (string)($filters['target_kept'] ?? '') === '1',
        'review' => (string)($filters['review'] ?? '') === '1',
        'review_status' => cow_merge_audit_review_status_filter($filters['review_status'] ?? null),
        'resolution_status' => cow_merge_audit_resolution_status_filter($filters['resolution_status'] ?? null),
        'group_by' => cow_merge_audit_group_by($filters['group_by'] ?? null),
    ];
}

function cow_merge_file_path_from_identity(?string $identity_json): ?string {
    if ($identity_json === null || $identity_json === '') {
        return null;
    }
    $decoded = json_decode($identity_json, true);
    if (!is_array($decoded) || !array_key_exists('path', $decoded)) {
        return null;
    }
    $path = cow_merge_audit_decode_payload($decoded['path']);
    return is_string($path) ? $path : null;
}

function cow_merge_audit_file_path_has_prefix(?string $identity_json, ?string $path_prefix): int {
    if ($path_prefix === null || $path_prefix === '') {
        return 0;
    }
    $path = cow_merge_file_path_from_identity($identity_json);
    if ($path === null) {
        return 0;
    }
    return ($path === $path_prefix || str_starts_with($path, rtrim($path_prefix, '/') . '/')) ? 1 : 0;
}

function cow_merge_audit_file_path_group(?string $identity_json): ?string {
    $path = cow_merge_file_path_from_identity($identity_json);
    if ($path === null || $path === '') {
        return null;
    }
    $first = explode('/', $path, 2)[0];
    return $first === '' ? null : $first;
}

function cow_merge_audit_register_functions(SQLite3 $db): void {
    if (!$db->createFunction(
        'forkpress_file_path_has_prefix',
        fn($identity_json, $path_prefix) => cow_merge_audit_file_path_has_prefix(
            is_string($identity_json) ? $identity_json : null,
            is_string($path_prefix) ? $path_prefix : null
        ),
        2
    )) {
        throw new RuntimeException('failed to register audit path-prefix filter');
    }
    if (!$db->createFunction(
        'forkpress_file_path_group',
        fn($identity_json) => cow_merge_audit_file_path_group(is_string($identity_json) ? $identity_json : null),
        1
    )) {
        throw new RuntimeException('failed to register audit path group function');
    }
}

function cow_merge_audit_where_sql(
    ?int $run_id,
    array $filters,
    string $record_type,
    string $alias = '',
    bool $review_notes_exist = true
): array {
    $prefix = $alias === '' ? '' : $alias . '.';
    $clauses = [];
    $params = [];

    if ($run_id !== null) {
        $clauses[] = $prefix . 'run_id = :run_id';
        $params[':run_id'] = $run_id;
    }

    if ($filters['scope'] === 'files') {
        $clauses[] = $prefix . "table_name = '__files__'";
    } elseif ($filters['scope'] === 'db') {
        $clauses[] = $prefix . "table_name NOT IN ('__files__', '__plugins__')";
    } elseif ($filters['scope'] === 'plugin') {
        $clauses[] = $prefix . "table_name = '__plugins__'";
    }

    if ($filters['path'] !== null) {
        $clauses[] = $prefix . "table_name = '__files__'";
        $clauses[] = $prefix . 'row_identity = :file_path_identity';
        $params[':file_path_identity'] = cow_merge_file_identity_json($filters['path']);
    } elseif ($filters['path_prefix'] !== null) {
        $clauses[] = $prefix . "table_name = '__files__'";
        $clauses[] = 'forkpress_file_path_has_prefix(' . $prefix . 'row_identity, :file_path_prefix) = 1';
        $params[':file_path_prefix'] = $filters['path_prefix'];
    }

    if ($record_type === 'conflicts' && $filters['conflict_type'] !== null) {
        $clauses[] = $prefix . 'conflict_type = :conflict_type';
        $params[':conflict_type'] = $filters['conflict_type'];
    }

    if ($record_type === 'decisions' && $filters['decision'] !== null) {
        $clauses[] = $prefix . 'decision = :decision';
        $params[':decision'] = $filters['decision'];
    }

    if (($filters['review'] ?? false) === true && ($filters['records'] ?? 'all') !== 'decisions') {
        if ($record_type === 'decisions') {
            $clauses[] = $prefix . "decision = 'id-band-skipped'";
        }
    }

    if (($filters['review_status'] ?? null) !== null) {
        if (($filters['review_status'] ?? null) === 'unreviewed') {
            if ($review_notes_exist) {
                $note_type = $record_type === 'conflicts' ? 'conflict' : 'decision';
                $outer_table = $record_type === 'conflicts' ? 'merge_conflicts' : 'merge_decisions';
                $outer_id = $alias === '' ? $outer_table . '.id' : $prefix . 'id';
                $clauses[] = "NOT EXISTS (SELECT 1 FROM merge_review_notes rn WHERE rn.record_type = '$note_type' AND rn.record_id = " .
                    $outer_id . ')';
            }
        } elseif (!$review_notes_exist) {
            $clauses[] = '0 = 1';
        } else {
            $note_type = $record_type === 'conflicts' ? 'conflict' : 'decision';
            $outer_table = $record_type === 'conflicts' ? 'merge_conflicts' : 'merge_decisions';
            $outer_id = $alias === '' ? $outer_table . '.id' : $prefix . 'id';
            $clauses[] = "(SELECT rn.status FROM merge_review_notes rn WHERE rn.record_type = '$note_type' AND rn.record_id = " .
                $outer_id . ' ORDER BY rn.id DESC LIMIT 1) = :review_status';
            $params[':review_status'] = $filters['review_status'];
        }
    }

    return [
        $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '',
        $params,
        $clauses ? ' AND ' . implode(' AND ', $clauses) : '',
    ];
}

function cow_merge_audit_resolution_where_sql(
    ?int $run_id,
    array $filters,
    bool $review_notes_exist = true
): array {
    $clauses = [];
    $params = [];

    if ($run_id !== null) {
        $clauses[] = 'c.run_id = :run_id';
        $params[':run_id'] = $run_id;
    }

    if ($filters['scope'] === 'files') {
        $clauses[] = "mr.table_name = '__files__'";
    } elseif ($filters['scope'] === 'db') {
        $clauses[] = "mr.table_name NOT IN ('__files__', '__plugins__')";
    } elseif ($filters['scope'] === 'plugin') {
        $clauses[] = "mr.table_name = '__plugins__'";
    }

    if ($filters['path'] !== null) {
        $clauses[] = "mr.table_name = '__files__'";
        $clauses[] = 'mr.row_identity = :file_path_identity';
        $params[':file_path_identity'] = cow_merge_file_identity_json($filters['path']);
    } elseif ($filters['path_prefix'] !== null) {
        $clauses[] = "mr.table_name = '__files__'";
        $clauses[] = 'forkpress_file_path_has_prefix(mr.row_identity, :file_path_prefix) = 1';
        $params[':file_path_prefix'] = $filters['path_prefix'];
    }

    if (($filters['resolution_status'] ?? null) !== null) {
        $clauses[] = 'mr.status = :resolution_status';
        $params[':resolution_status'] = $filters['resolution_status'];
    }

    if (($filters['review_status'] ?? null) !== null) {
        if (($filters['review_status'] ?? null) === 'unreviewed') {
            if ($review_notes_exist) {
                $clauses[] = "NOT EXISTS (SELECT 1 FROM merge_review_notes rn WHERE rn.record_type = 'resolution' AND rn.record_id = mr.id)";
            }
        } elseif (!$review_notes_exist) {
            $clauses[] = '0 = 1';
        } else {
            $clauses[] = "(SELECT rn.status FROM merge_review_notes rn WHERE rn.record_type = 'resolution' AND rn.record_id = " .
                'mr.id ORDER BY rn.id DESC LIMIT 1) = :resolution_review_status';
            $params[':resolution_review_status'] = $filters['review_status'];
        }
    }

    return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
}

function cow_merge_audit_resolution_group_sql(string $group_by): string {
    if ($group_by === 'table') {
        return 'mr.table_name';
    }
    if ($group_by === 'status') {
        return 'mr.status';
    }
    if ($group_by === 'path') {
        return "CASE WHEN mr.table_name = '__files__' THEN COALESCE(forkpress_file_path_group(mr.row_identity), '(unknown)') ELSE mr.table_name END";
    }
    throw new InvalidArgumentException('unsupported resolution group');
}

function cow_merge_audit_conflict_group_sql(string $group_by): string {
    if ($group_by === 'table') {
        return 'c.table_name';
    }
    if ($group_by === 'type') {
        return 'c.conflict_type';
    }
    if ($group_by === 'path') {
        return "CASE WHEN c.table_name = '__files__' THEN COALESCE(forkpress_file_path_group(c.row_identity), '(unknown)') ELSE c.table_name END";
    }
    if ($group_by === 'severity') {
        return "CASE " .
            "WHEN c.conflict_type LIKE 'schema-%' THEN 'schema' " .
            "WHEN c.conflict_type IN ('row-insert-collision', 'row-unique-collision', 'row-target-constraint', 'row-identity-ambiguous', 'row-target-deleted', 'row-source-deleted') THEN 'row' " .
            "WHEN c.conflict_type = 'cell-conflict' THEN 'cell' " .
            "WHEN c.table_name = '__files__' THEN 'files' " .
            "WHEN c.table_name = '__plugins__' THEN 'plugin' " .
            "ELSE 'other' END";
    }
    throw new InvalidArgumentException('unsupported conflict group');
}

function cow_merge_audit_decision_group_sql(string $group_by): string {
    if ($group_by === 'table') {
        return 'd.table_name';
    }
    if ($group_by === 'type') {
        return 'd.decision';
    }
    if ($group_by === 'path') {
        return "CASE WHEN d.table_name = '__files__' THEN COALESCE(forkpress_file_path_group(d.row_identity), '(unknown)') ELSE d.table_name END";
    }
    throw new InvalidArgumentException('unsupported decision group');
}

function cow_merge_audit_count_sql(array $filters, string $record_type, string $alias, bool $review_notes_exist = true): string {
    $conditions = [];
    if ($filters['scope'] === 'files') {
        $conditions[] = $alias . ".table_name = '__files__'";
    } elseif ($filters['scope'] === 'db') {
        $conditions[] = $alias . ".table_name NOT IN ('__files__', '__plugins__')";
    } elseif ($filters['scope'] === 'plugin') {
        $conditions[] = $alias . ".table_name = '__plugins__'";
    }
    if ($record_type === 'conflicts' && $filters['conflict_type'] !== null) {
        $conditions[] = $alias . ".conflict_type = '" . SQLite3::escapeString($filters['conflict_type']) . "'";
    }
    if ($record_type === 'decisions' && $filters['decision'] !== null) {
        $conditions[] = $alias . ".decision = '" . SQLite3::escapeString($filters['decision']) . "'";
    }
    if (($filters['review'] ?? false) === true && ($filters['records'] ?? 'all') !== 'decisions') {
        if ($record_type === 'decisions') {
            $conditions[] = $alias . ".decision = 'id-band-skipped'";
        }
    }
    if (($filters['review_status'] ?? null) !== null) {
        if (($filters['review_status'] ?? null) === 'unreviewed') {
            if ($review_notes_exist) {
                $note_type = $record_type === 'conflicts' ? 'conflict' : 'decision';
                $conditions[] = "NOT EXISTS (SELECT 1 FROM merge_review_notes rn WHERE rn.record_type = '$note_type' AND rn.record_id = " .
                    $alias . '.id)';
            }
        } elseif (!$review_notes_exist) {
            $conditions[] = '0 = 1';
        } else {
            $note_type = $record_type === 'conflicts' ? 'conflict' : 'decision';
            $conditions[] = "(SELECT rn.status FROM merge_review_notes rn WHERE rn.record_type = '$note_type' AND rn.record_id = " .
                $alias . ".id ORDER BY rn.id DESC LIMIT 1) = '" . SQLite3::escapeString($filters['review_status']) . "'";
        }
    }
    if ($filters['path'] !== null) {
        $conditions[] = $alias . ".table_name = '__files__'";
        $conditions[] = $alias . ".row_identity = '" . SQLite3::escapeString(cow_merge_file_identity_json($filters['path'])) . "'";
    } elseif ($filters['path_prefix'] !== null) {
        $conditions[] = $alias . ".table_name = '__files__'";
        $conditions[] = "forkpress_file_path_has_prefix(" . $alias . ".row_identity, '" . SQLite3::escapeString($filters['path_prefix']) . "') = 1";
    }
    return $conditions ? ' AND ' . implode(' AND ', $conditions) : '';
}

function cow_merge_audit_named_decision_count_sql(array $filters, string $alias, string $decision, bool $review_notes_exist = true): string {
    if ($filters['decision'] !== null && $filters['decision'] !== $decision) {
        return ' AND 0';
    }
    $filters['decision'] = null;
    return cow_merge_audit_count_sql($filters, 'decisions', $alias, $review_notes_exist);
}

function cow_merge_fetch_rows(SQLite3 $db, string $sql, array $params = []): array {
    $stmt = cow_merge_prepare_checked($db, $sql, 'failed to prepare audit query');
    foreach ($params as $key => $value) {
        cow_merge_bind($stmt, $key, $value);
    }
    $res = cow_merge_execute_checked($stmt, $db, 'failed to execute audit query');
    $rows = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $clean = [];
        foreach ($row as $key => $value) {
            $clean[(string)$key] = $value;
        }
        $rows[] = $clean;
    }
    cow_merge_result_finalize_checked($res, 'failed to finalize audit query');
    return $rows;
}

function cow_merge_audit_has_table(SQLite3 $db, string $table): bool {
    $stmt = cow_merge_prepare_checked(
        $db,
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name",
        'failed to prepare audit table check'
    );
    cow_merge_bind($stmt, ':name', $table);
    $res = cow_merge_execute_checked($stmt, $db, 'failed to execute audit table check');
    $exists = (bool)$res->fetchArray(SQLITE3_NUM);
    cow_merge_result_finalize_checked($res, 'failed to finalize audit table check');
    return $exists;
}

function cow_merge_audit_has_column(SQLite3 $db, string $table, string $column): bool {
    if (!cow_merge_audit_has_table($db, $table)) {
        return false;
    }
    $res = cow_merge_query_checked(
        $db,
        'PRAGMA table_info(' . cow_merge_quote_ident($table) . ')',
        'failed to inspect audit table'
    );
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        if ((string)$row['name'] === $column) {
            cow_merge_result_finalize_checked($res, 'failed to finalize audit table inspection');
            return true;
        }
    }
    cow_merge_result_finalize_checked($res, 'failed to finalize audit table inspection');
    return false;
}

function cow_merge_audit_table_rows(
    SQLite3 $db,
    string $table,
    string $sql,
    array $params = []
): array {
    if (!cow_merge_audit_has_table($db, $table)) {
        return [];
    }
    return cow_merge_fetch_rows($db, $sql, $params);
}

function cow_merge_cleanup_branch_birth_metadata(string $metadata_db, string $branch): array {
    if ($branch === '') {
        throw new InvalidArgumentException('--branch is required');
    }
    $result = [
        'branch' => $branch,
        'metadata_db' => $metadata_db,
        'cleaned' => 0,
    ];
    if (!is_file($metadata_db)) {
        return $result;
    }

    $db = cow_merge_open_db($metadata_db, SQLITE3_OPEN_READWRITE);
    try {
        $db->busyTimeout(5000);
        cow_merge_exec_checked($db, 'BEGIN IMMEDIATE', 'failed to begin branch birth metadata cleanup');
        $run_filter = "source_branch = :branch AND target_branch = :branch AND base_ref IN ('autoincrement-id-band', 'identity-capture') AND policy IN ('autoincrement-id-band-allocation', 'sidecar-row-identity-capture')";
        $statements = [
            ['merge_autoincrement_bands', 'DELETE FROM merge_autoincrement_bands WHERE branch_name = :branch'],
            ['merge_row_identities', 'DELETE FROM merge_row_identities WHERE branch_name = :branch'],
            ['merge_row_identity_history', 'DELETE FROM merge_row_identity_history WHERE branch_name = :branch'],
            ['merge_decisions', "DELETE FROM merge_decisions WHERE run_id IN (SELECT id FROM merge_runs WHERE $run_filter)"],
            ['merge_runs', "DELETE FROM merge_runs WHERE $run_filter"],
        ];
        foreach ($statements as [$table, $sql]) {
            if (!cow_merge_audit_has_table($db, $table)) {
                continue;
            }
            $stmt = cow_merge_prepare_checked($db, $sql, 'failed to prepare branch birth metadata cleanup');
            cow_merge_bind($stmt, ':branch', $branch);
            $res = cow_merge_execute_checked($stmt, $db, 'failed to clean branch birth metadata');
            $res->finalize();
            $stmt->close();
            $result['cleaned'] += $db->changes();
        }
        cow_merge_exec_checked($db, 'COMMIT', 'failed to commit branch birth metadata cleanup');
        return $result;
    } catch (Throwable $e) {
        @$db->exec('ROLLBACK');
        throw $e;
    } finally {
        $db->close();
    }
}

function cow_merge_audit_decode_payload(mixed $value): mixed {
    if (!is_array($value)) {
        return $value;
    }
    if (isset($value['type']) && is_scalar($value['type'])) {
        $type = (string)$value['type'];
        if ($type === 'bytes') {
            $bytes = base64_decode((string)($value['base64'] ?? ''), true);
            if ($bytes === false) {
                return ['base64' => (string)($value['base64'] ?? '')];
            }
            if (!str_contains($bytes, "\0") && preg_match('//u', $bytes)) {
                return $bytes;
            }
            return ['base64' => base64_encode($bytes), 'bytes' => strlen($bytes)];
        }
        if (array_key_exists('value', $value)) {
            return $value['value'];
        }
    }
    $out = [];
    foreach ($value as $key => $item) {
        $out[$key] = cow_merge_audit_decode_payload($item);
    }
    return $out;
}

function cow_merge_audit_truncate(string $text, int $max = 160): string {
    if (strlen($text) <= $max) {
        return $text;
    }
    return substr($text, 0, max(0, $max - 3)) . '...';
}

function cow_merge_audit_payload_preview(?string $json): string {
    if ($json === null || $json === '') {
        return 'null';
    }
    $decoded = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return cow_merge_audit_truncate($json);
    }
    $plain = json_encode(cow_merge_audit_decode_payload($decoded), JSON_UNESCAPED_SLASHES);
    if (!is_string($plain)) {
        return cow_merge_audit_truncate($json);
    }
    return cow_merge_audit_truncate($plain);
}

function cow_merge_audit_json_preview(?string $json): string {
    if ($json === null || $json === '') {
        return 'null';
    }
    $decoded = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return cow_merge_audit_truncate($json);
    }
    $plain = json_encode($decoded, JSON_UNESCAPED_SLASHES);
    if (!is_string($plain)) {
        return cow_merge_audit_truncate($json);
    }
    return cow_merge_audit_truncate($plain);
}

function cow_merge_audit_add_payload_previews(array $rows): array {
    foreach ($rows as &$row) {
        foreach (['base', 'source', 'target', 'chosen', 'current_target'] as $name) {
            $payload_key = $name . '_payload';
            if (array_key_exists($payload_key, $row)) {
                $row[$name . '_preview'] = cow_merge_audit_payload_preview($row[$payload_key]);
            }
        }
        if (array_key_exists('row_identity', $row)) {
            $row['row_identity_preview'] = cow_merge_audit_json_preview($row['row_identity']);
        }
    }
    unset($row);
    return $rows;
}

function cow_merge_audit_conflict_target_staleness(SQLite3 $meta, array $conflict): array {
    $status = [
        'stale_status' => 'unknown',
        'stale_reason' => 'conflict type is not checked for target drift',
        'revalidation_class' => 'unclassified',
        'current_source_payload' => null,
        'current_target_payload' => null,
    ];

    $table = (string)($conflict['table_name'] ?? '');
    $conflict_type = (string)($conflict['conflict_type'] ?? '');
    try {
        if ($table === '__plugins__') {
            $replacement = cow_merge_fetch_rows(
                $meta,
                'SELECT id, source_payload, target_payload, chosen_payload, source_hash, target_hash, chosen_hash ' .
                'FROM merge_conflicts ' .
                'WHERE run_id = :run_id ' .
                'AND table_name = :table_name ' .
                'AND row_identity = :row_identity ' .
                'AND conflict_type = :conflict_type ' .
                'AND id > :id ' .
                'ORDER BY id DESC LIMIT 1',
                [
                    ':run_id' => (int)($conflict['run_id'] ?? 0),
                    ':table_name' => $table,
                    ':row_identity' => (string)($conflict['row_identity'] ?? ''),
                    ':conflict_type' => $conflict_type,
                    ':id' => (int)($conflict['id'] ?? 0),
                ]
            );
            if (!$replacement) {
                return $status;
            }
            $latest = $replacement[0];
            $same_validator_payload =
                hash_equals((string)($conflict['source_hash'] ?? ''), (string)($latest['source_hash'] ?? ''))
                && hash_equals((string)($conflict['target_hash'] ?? ''), (string)($latest['target_hash'] ?? ''))
                && hash_equals((string)($conflict['chosen_hash'] ?? ''), (string)($latest['chosen_hash'] ?? ''));
            return [
                'stale_status' => $same_validator_payload ? 'fresh' : 'stale',
                'stale_reason' => $same_validator_payload
                    ? 'plugin validator finding still matches audited conflict payload'
                    : 'plugin validator reported updated evidence for this plugin object; rerun plugin audit before resolving',
                'revalidation_class' => $same_validator_payload ? 'unchanged' : 'replacement-evidence',
                'current_source_payload' => (string)($latest['source_payload'] ?? ''),
                'current_target_payload' => (string)($latest['chosen_payload'] ?? ''),
                'replacement_conflict_id' => (int)$latest['id'],
            ];
        }

        if (str_starts_with($conflict_type, 'schema-')) {
            return $status;
        }

        if ($table === '__files__') {
            $target_db = (string)($conflict['target_db'] ?? '');
            if ($target_db === '') {
                return ['stale_status' => 'error', 'stale_reason' => 'conflict run does not include a target database path', 'revalidation_class' => 'unclassified', 'current_target_payload' => null];
            }
            $target_root = cow_merge_branch_root_from_db_path($target_db);
            if (!is_dir($target_root)) {
                return ['stale_status' => 'error', 'stale_reason' => "target root does not exist: $target_root", 'revalidation_class' => 'unclassified', 'current_target_payload' => null];
            }
            $path = cow_merge_file_path_from_identity($conflict['row_identity'] ?? null);
            if ($path === null) {
                return ['stale_status' => 'error', 'stale_reason' => 'filesystem conflict has an invalid path identity', 'revalidation_class' => 'unclassified', 'current_target_payload' => null];
            }
            $target_payload = cow_merge_decode_payload_json((string)($conflict['target_payload'] ?? ''), 'target');
            $source_payload = cow_merge_decode_payload_json((string)($conflict['source_payload'] ?? ''), 'source');
            $expected = cow_merge_file_entry_without_path(is_array($target_payload) ? $target_payload : null);
            $expected_source = cow_merge_file_entry_without_path(is_array($source_payload) ? $source_payload : null);
            $entries = cow_merge_file_manifest_for_root($target_root)['entries'];
            $current = $entries[$path] ?? null;
            $current_source_payload = (string)($conflict['source_payload'] ?? '');
            $source_fresh = true;
            $source_db = (string)($conflict['source_db'] ?? '');
            if ($source_db !== '') {
                $source_root = cow_merge_branch_root_from_db_path($source_db);
                if (is_dir($source_root)) {
                    $source_entries = cow_merge_file_manifest_for_root($source_root)['entries'];
                    $current_source = $source_entries[$path] ?? null;
                    $source_fresh = cow_merge_file_entries_equal($current_source, $expected_source);
                    $current_source_payload = cow_merge_payload_json(cow_merge_file_path_payload($path, $current_source));
                }
            }
            if (cow_merge_file_entries_equal($current, $expected) && !$source_fresh) {
                return [
                    'stale_status' => 'stale',
                    'stale_reason' => 'source filesystem path no longer matches audited source payload; rerun merge-audit before resolving',
                    'revalidation_class' => 'compatible-source-drift',
                    'current_source_payload' => $current_source_payload,
                    'current_target_payload' => cow_merge_payload_json(cow_merge_file_path_payload($path, $current)),
                ];
            }
            return [
                'stale_status' => cow_merge_file_entries_equal($current, $expected) ? 'fresh' : 'stale',
                'stale_reason' => cow_merge_file_entries_equal($current, $expected)
                    ? 'target filesystem path still matches audited target payload'
                    : 'target filesystem path no longer matches audited target payload; rerun merge-audit before resolving',
                'revalidation_class' => cow_merge_file_entries_equal($current, $expected)
                    ? 'unchanged'
                    : ($current === null ? 'missing' : 'compatible-target-drift'),
                'current_source_payload' => $current_source_payload,
                'current_target_payload' => cow_merge_payload_json(cow_merge_file_path_payload($path, $current)),
            ];
        }

        $db_conflict_types = [
            'cell-conflict',
            'row-insert-collision',
            'row-unique-collision',
            'row-target-constraint',
            'row-identity-ambiguous',
            'row-target-deleted',
            'row-source-deleted',
        ];
        if (!in_array($conflict_type, $db_conflict_types, true)) {
            return $status;
        }

        $target_db = (string)($conflict['target_db'] ?? '');
        if ($target_db === '' || !is_file($target_db)) {
            return ['stale_status' => 'error', 'stale_reason' => "target database does not exist: $target_db", 'revalidation_class' => 'unclassified', 'current_target_payload' => null];
        }
        $target = cow_merge_open_db($target_db, SQLITE3_OPEN_READONLY);
        try {
            $identity = cow_merge_decode_payload_json((string)($conflict['row_identity'] ?? ''), 'row identity');
            if (!is_array($identity)) {
                return ['stale_status' => 'error', 'stale_reason' => 'database conflict has an invalid row identity', 'revalidation_class' => 'unclassified', 'current_target_payload' => null];
            }
            $target_value = cow_merge_decode_payload_json((string)($conflict['target_payload'] ?? ''), 'target');
            $source_value = cow_merge_decode_payload_json((string)($conflict['source_payload'] ?? ''), 'source');
            $pk_cols = cow_merge_pk_cols($target, $table);
            $where_identity = $identity;
            $source_where_identity = $identity;
            $missing_revalidation_class = 'missing';
            $identity_replacement_class = null;
            if (!$pk_cols) {
                $target_branch = (string)($conflict['target_branch'] ?? '');
                $target_rowid = $target_branch === '' ? null : cow_merge_lookup_active_rowid_by_identity($meta, $target_branch, $table, $identity);
                $where_identity = $target_rowid === null ? [] : ['rowid' => $target_rowid];
                $source_branch = (string)($conflict['source_branch'] ?? '');
                $source_rowid = $source_branch === '' ? null : cow_merge_lookup_active_rowid_by_identity($meta, $source_branch, $table, $identity);
                $source_where_identity = $source_rowid === null ? [] : ['rowid' => $source_rowid];
                if ($target_branch !== '' && $target_rowid === null) {
                    $identity_rowid = cow_merge_keyless_identity_rowid_hint($identity);
                    if ($identity_rowid !== null) {
                        $active_identity = cow_merge_lookup_active_identity_by_rowid($meta, $target_branch, $table, $identity_rowid);
                        if (is_array($active_identity) && !cow_merge_values_equal($active_identity, $identity)) {
                            $missing_revalidation_class = 'incompatible';
                            $identity_replacement_class = 'incompatible';
                            $where_identity = ['rowid' => $identity_rowid];
                        }
                    }
                }
            }

            $source_fresh = true;
            $current_source_payload = (string)($conflict['source_payload'] ?? '');
            $source_db = (string)($conflict['source_db'] ?? '');
            $source_current_row = null;
            if ($source_db !== '' && is_file($source_db)) {
                $source = cow_merge_open_db($source_db, SQLITE3_OPEN_READONLY);
                try {
                    $source_pk_cols = cow_merge_pk_cols($source, $table);
                    if ($source_pk_cols || array_key_exists('rowid', $source_where_identity)) {
                        $source_current_row = cow_merge_select_current_row($source, $table, $source_where_identity, $source_pk_cols);
                    }
                    if ($conflict_type === 'cell-conflict') {
                        $source_column = (string)($conflict['column_name'] ?? '');
                        $current_source = $source_current_row === null ? null : ($source_current_row[$source_column] ?? null);
                        $source_fresh = cow_merge_values_equal($current_source, $source_value);
                        $current_source_payload = cow_merge_payload_json($current_source);
                    } elseif ($source_value === null) {
                        $source_fresh = $source_current_row === null;
                        $current_source_payload = cow_merge_payload_json($source_current_row);
                    } elseif ($source_current_row === null || !is_array($source_value)) {
                        $source_fresh = false;
                        $current_source_payload = cow_merge_payload_json($source_current_row);
                    } else {
                        $source_fresh = cow_merge_row_values_equal($source_current_row, $source_value, cow_merge_all_columns(array_keys($source_value), array_keys($source_current_row)));
                        $current_source_payload = cow_merge_payload_json($source_current_row);
                    }
                } catch (Throwable) {
                    $source_fresh = true;
                    $current_source_payload = (string)($conflict['source_payload'] ?? '');
                } finally {
                    $source->close();
                }
            }

            if ($conflict_type === 'cell-conflict') {
                $column = (string)($conflict['column_name'] ?? '');
                if ($column === '') {
                    return ['stale_status' => 'error', 'stale_reason' => 'cell conflict has no audited column name', 'revalidation_class' => 'unclassified', 'current_target_payload' => null];
                }
                $current_row = ($pk_cols || array_key_exists('rowid', $where_identity))
                    ? cow_merge_select_current_row($target, $table, $where_identity, $pk_cols)
                    : null;
                $current = $current_row === null ? null : ($current_row[$column] ?? null);
                if (cow_merge_values_equal($current, $target_value) && !$source_fresh) {
                    return [
                        'stale_status' => 'stale',
                        'stale_reason' => 'source cell no longer matches audited source payload; rerun merge-audit before resolving',
                        'revalidation_class' => 'compatible-source-drift',
                        'current_source_payload' => $current_source_payload,
                        'current_target_payload' => cow_merge_payload_json($current),
                    ];
                }
                return [
                    'stale_status' => cow_merge_values_equal($current, $target_value) ? 'fresh' : 'stale',
                    'stale_reason' => cow_merge_values_equal($current, $target_value)
                        ? 'target cell still matches audited target payload'
                        : 'target cell no longer matches audited target payload; rerun merge-audit before resolving',
                    'revalidation_class' => cow_merge_values_equal($current, $target_value)
                        ? 'unchanged'
                        : ($identity_replacement_class ?? ($current_row === null ? $missing_revalidation_class : 'compatible-target-drift')),
                    'current_source_payload' => $current_source_payload,
                    'current_target_payload' => cow_merge_payload_json($current),
                ];
            }

            if ($conflict_type === 'row-unique-collision' && is_array($source_value)) {
                $unique_collision = cow_merge_find_unique_collision($target, $table, $source_value, true);
                $current = is_array($unique_collision) ? ($unique_collision['row'] ?? null) : null;
            } else {
                $current = ($pk_cols || array_key_exists('rowid', $where_identity))
                    ? cow_merge_select_current_row($target, $table, $where_identity, $pk_cols)
                    : null;
            }

            if ($conflict_type === 'row-target-deleted' || ($conflict_type === 'row-target-constraint' && $target_value === null)) {
                $fresh = $current === null;
            } elseif ($current === null || !is_array($target_value)) {
                $fresh = false;
            } else {
                $fresh = cow_merge_row_values_equal($current, $target_value, cow_merge_all_columns(array_keys($target_value), array_keys($current)));
            }

            $semantic_revalidation_class = null;
            $semantic_stale_reason = null;
            if (!$fresh && is_array($current) && is_array($target_value)) {
                $audited_semantic_identity = cow_merge_row_semantic_identity($table, $target_value);
                $current_semantic_identity = cow_merge_row_semantic_identity($table, $current);
                if (
                    $audited_semantic_identity !== null
                    && $current_semantic_identity !== null
                    && !cow_merge_values_equal($audited_semantic_identity, $current_semantic_identity)
                ) {
                    $semantic_revalidation_class = 'incompatible';
                    $semantic_stale_reason = 'target row semantic identity no longer matches audited target payload; rerun merge-audit before resolving';
                }
            }

            $source_semantic_revalidation_class = null;
            $source_semantic_stale_reason = null;
            if (!$source_fresh && is_array($source_current_row) && is_array($source_value)) {
                $audited_source_semantic_identity = cow_merge_row_semantic_identity($table, $source_value);
                $current_source_semantic_identity = cow_merge_row_semantic_identity($table, $source_current_row);
                if (
                    $audited_source_semantic_identity !== null
                    && $current_source_semantic_identity !== null
                    && !cow_merge_values_equal($audited_source_semantic_identity, $current_source_semantic_identity)
                ) {
                    $source_semantic_revalidation_class = 'incompatible';
                    $source_semantic_stale_reason = 'source row semantic identity no longer matches audited source payload; rerun merge-audit before resolving';
                }
            }

            if ($fresh && !$source_fresh) {
                return [
                    'stale_status' => 'stale',
                    'stale_reason' => $source_semantic_stale_reason ?? 'source row no longer matches audited source payload; rerun merge-audit before resolving',
                    'revalidation_class' => $source_semantic_revalidation_class ?? 'compatible-source-drift',
                    'current_source_payload' => $current_source_payload,
                    'current_target_payload' => cow_merge_payload_json($current),
                ];
            }

            return [
                'stale_status' => $fresh ? 'fresh' : 'stale',
                'stale_reason' => $semantic_stale_reason ?? ($fresh
                    ? 'target row still matches audited target payload'
                    : 'target row no longer matches audited target payload; rerun merge-audit before resolving'),
                'revalidation_class' => $fresh
                    ? 'unchanged'
                    : ($semantic_revalidation_class ?? $identity_replacement_class ?? ($current === null ? $missing_revalidation_class : 'compatible-target-drift')),
                'current_source_payload' => $current_source_payload,
                'current_target_payload' => cow_merge_payload_json($current),
            ];
        } finally {
            $target->close();
        }
    } catch (Throwable $e) {
        return [
            'stale_status' => 'error',
            'stale_reason' => $e->getMessage(),
            'revalidation_class' => 'unclassified',
            'current_target_payload' => null,
        ];
    }
}

function cow_merge_audit_add_conflict_staleness(SQLite3 $meta, array $rows): array {
    foreach ($rows as &$row) {
        $staleness = cow_merge_audit_conflict_target_staleness($meta, $row);
        $row['stale_status'] = $staleness['stale_status'];
        $row['stale_reason'] = $staleness['stale_reason'];
        $row['revalidation_class'] = $staleness['revalidation_class'] ?? 'unclassified';
        if (isset($staleness['replacement_conflict_id'])) {
            $row['replacement_conflict_id'] = (int)$staleness['replacement_conflict_id'];
        }
        $latest_revalidation = (
            cow_merge_audit_has_table($meta, 'merge_revalidations')
            && cow_merge_audit_has_column($meta, 'merge_revalidations', 'replacement_conflict_id')
        )
            ? cow_merge_latest_revalidation($meta, (int)$row['id'])
            : null;
        if ($latest_revalidation !== null) {
            $row['latest_revalidation_id'] = (int)$latest_revalidation['id'];
            $row['latest_revalidation_class'] = (string)($latest_revalidation['revalidation_class'] ?? 'unclassified');
            if ($latest_revalidation['replacement_conflict_id'] !== null) {
                $row['latest_revalidation_replacement_conflict_id'] = (int)$latest_revalidation['replacement_conflict_id'];
            }
        }
        if ($staleness['current_target_payload'] !== null) {
            $row['current_target_payload'] = $staleness['current_target_payload'];
        }
    }
    unset($row);
    return $rows;
}

function cow_merge_audit_report(string $metadata_db, ?int $run_id = null, int $limit = 20, array $filters = []): array {
    $filters = cow_merge_audit_filters($filters);
    $report = [
        'metadata_db' => $metadata_db,
        'metadata_exists' => is_file($metadata_db),
        'run_id' => $run_id,
        'limit' => $limit,
        'filters' => $filters,
        'runs' => [],
        'conflicts' => [],
        'conflict_groups' => [],
        'decisions' => [],
        'decision_groups' => [],
        'resolutions' => [],
        'resolution_groups' => [],
        'autoincrement_bands' => [],
        'row_identity_summary' => [],
        'rollback_failures' => [],
    ];
    if (!is_file($metadata_db)) {
        return $report;
    }

    $db = cow_merge_open_db($metadata_db, SQLITE3_OPEN_READONLY);
    try {
        cow_merge_audit_register_functions($db);
        if (!cow_merge_audit_has_table($db, 'merge_runs')) {
            return $report;
        }
        $review_notes_exist = cow_merge_audit_has_table($db, 'merge_review_notes');
        $conflict_review_select = $review_notes_exist
            ? ", (SELECT rn.status FROM merge_review_notes rn WHERE rn.record_type = 'conflict' AND rn.record_id = merge_conflicts.id ORDER BY rn.id DESC LIMIT 1) AS review_status, " .
              "(SELECT rn.note FROM merge_review_notes rn WHERE rn.record_type = 'conflict' AND rn.record_id = merge_conflicts.id ORDER BY rn.id DESC LIMIT 1) AS review_note, " .
              "(SELECT rn.reviewer FROM merge_review_notes rn WHERE rn.record_type = 'conflict' AND rn.record_id = merge_conflicts.id ORDER BY rn.id DESC LIMIT 1) AS review_reviewer, " .
              "(SELECT rn.created_at FROM merge_review_notes rn WHERE rn.record_type = 'conflict' AND rn.record_id = merge_conflicts.id ORDER BY rn.id DESC LIMIT 1) AS reviewed_at"
            : ', NULL AS review_status, NULL AS review_note, NULL AS review_reviewer, NULL AS reviewed_at';
        $decision_review_select = $review_notes_exist
            ? ", (SELECT rn.status FROM merge_review_notes rn WHERE rn.record_type = 'decision' AND rn.record_id = merge_decisions.id ORDER BY rn.id DESC LIMIT 1) AS review_status, " .
              "(SELECT rn.note FROM merge_review_notes rn WHERE rn.record_type = 'decision' AND rn.record_id = merge_decisions.id ORDER BY rn.id DESC LIMIT 1) AS review_note, " .
              "(SELECT rn.reviewer FROM merge_review_notes rn WHERE rn.record_type = 'decision' AND rn.record_id = merge_decisions.id ORDER BY rn.id DESC LIMIT 1) AS review_reviewer, " .
              "(SELECT rn.created_at FROM merge_review_notes rn WHERE rn.record_type = 'decision' AND rn.record_id = merge_decisions.id ORDER BY rn.id DESC LIMIT 1) AS reviewed_at"
            : ', NULL AS review_status, NULL AS review_note, NULL AS review_reviewer, NULL AS reviewed_at';
        $resolution_review_select = $review_notes_exist
            ? ", (SELECT rn.status FROM merge_review_notes rn WHERE rn.record_type = 'resolution' AND rn.record_id = mr.id ORDER BY rn.id DESC LIMIT 1) AS review_status, " .
              "(SELECT rn.note FROM merge_review_notes rn WHERE rn.record_type = 'resolution' AND rn.record_id = mr.id ORDER BY rn.id DESC LIMIT 1) AS review_note, " .
              "(SELECT rn.reviewer FROM merge_review_notes rn WHERE rn.record_type = 'resolution' AND rn.record_id = mr.id ORDER BY rn.id DESC LIMIT 1) AS review_reviewer, " .
              "(SELECT rn.created_at FROM merge_review_notes rn WHERE rn.record_type = 'resolution' AND rn.record_id = mr.id ORDER BY rn.id DESC LIMIT 1) AS reviewed_at"
            : ', NULL AS review_status, NULL AS review_note, NULL AS review_reviewer, NULL AS reviewed_at';
        $failure_reason_select = cow_merge_audit_has_column($db, 'merge_runs', 'failure_reason')
            ? 'r.failure_reason'
            : 'NULL AS failure_reason';
        $decision_count_filter = cow_merge_audit_count_sql($filters, 'decisions', 'd', $review_notes_exist);
        $conflict_count_filter = cow_merge_audit_count_sql($filters, 'conflicts', 'c', $review_notes_exist);
        $target_wins_filter = cow_merge_audit_named_decision_count_sql($filters, 'd', 'target-wins', $review_notes_exist);
        $target_accepted_filter = cow_merge_audit_named_decision_count_sql($filters, 'd', 'target-accepted', $review_notes_exist);
        $source_applied_filter = cow_merge_audit_named_decision_count_sql($filters, 'd', 'source-applied', $review_notes_exist);
        $target_kept_filter = cow_merge_audit_named_decision_count_sql($filters, 'd', 'target-kept', $review_notes_exist);
        $id_band_filter = cow_merge_audit_count_sql($filters, 'decisions', 'd', $review_notes_exist);
        $run_where = $run_id === null ? '' : 'WHERE r.id = :run_id';
        $run_params = [':limit' => $limit];
        if ($run_id !== null) {
            $run_params[':run_id'] = $run_id;
        }
        $report['runs'] = cow_merge_fetch_rows(
            $db,
            "SELECT r.id, r.source_branch, r.target_branch, r.base_ref, r.started_at, r.finished_at, r.status, r.policy, $failure_reason_select, " .
            "(SELECT COUNT(*) FROM merge_decisions d WHERE d.run_id = r.id$decision_count_filter) AS decision_count, " .
            "(SELECT COUNT(*) FROM merge_conflicts c WHERE c.run_id = r.id$conflict_count_filter) AS conflict_count, " .
            "(SELECT COUNT(*) FROM merge_decisions d WHERE d.run_id = r.id AND d.decision = 'target-wins'$target_wins_filter) AS target_wins_count, " .
            "(SELECT COUNT(*) FROM merge_decisions d WHERE d.run_id = r.id AND d.decision = 'target-accepted'$target_accepted_filter) AS target_accepted_count, " .
            "(SELECT COUNT(*) FROM merge_decisions d WHERE d.run_id = r.id AND d.decision = 'source-applied'$source_applied_filter) AS source_applied_count, " .
            "(SELECT COUNT(*) FROM merge_decisions d WHERE d.run_id = r.id AND d.decision = 'target-kept'$target_kept_filter) AS target_kept_count, " .
            "(SELECT COUNT(*) FROM merge_decisions d WHERE d.run_id = r.id AND d.decision LIKE 'id-band-%'$id_band_filter) AS id_band_decision_count " .
            "FROM merge_runs r $run_where ORDER BY r.id DESC LIMIT :limit",
            $run_params
        );

        [$conflict_filter, $conflict_params] = cow_merge_audit_where_sql($run_id, $filters, 'conflicts', '', $review_notes_exist);
        $conflict_params[':limit'] = $limit;
        if ($filters['records'] === 'all' || $filters['records'] === 'conflicts') {
            $report['conflicts'] = cow_merge_audit_add_payload_previews(cow_merge_audit_add_conflict_staleness($db, cow_merge_audit_table_rows(
                $db,
                'merge_conflicts',
                "SELECT merge_conflicts.id AS id, run_id, table_name, row_identity, column_name, conflict_type, resolver, resolved_at, created_at, " .
                "base_payload, source_payload, target_payload, chosen_payload, r.source_db, r.target_db, r.source_branch, r.target_branch$conflict_review_select " .
                "FROM merge_conflicts JOIN merge_runs r ON r.id = merge_conflicts.run_id $conflict_filter ORDER BY merge_conflicts.id DESC LIMIT :limit",
                $conflict_params
            )));
            if ($filters['records'] === 'conflicts' && $filters['group_by'] !== 'none') {
                [$conflict_group_filter, $conflict_group_params] = cow_merge_audit_where_sql($run_id, $filters, 'conflicts', 'c', $review_notes_exist);
                $conflict_group_params[':limit'] = $limit;
                $group_expr = cow_merge_audit_conflict_group_sql($filters['group_by']);
                $report['conflict_groups'] = cow_merge_audit_table_rows(
                    $db,
                    'merge_conflicts',
                    "SELECT :group_by AS group_by, $group_expr AS group_key, COUNT(*) AS conflict_count, " .
                    "SUM(CASE WHEN c.resolver = 'target-wins' THEN 1 ELSE 0 END) AS target_wins_count, " .
                    "SUM(CASE WHEN c.resolved_at IS NOT NULL AND c.resolved_at <> '' THEN 1 ELSE 0 END) AS resolved_count, " .
                    "SUM(CASE WHEN c.table_name = '__files__' THEN 1 ELSE 0 END) AS file_count, " .
                    "SUM(CASE WHEN c.table_name = '__plugins__' THEN 1 ELSE 0 END) AS plugin_count, " .
                    "SUM(CASE WHEN c.table_name NOT IN ('__files__', '__plugins__') THEN 1 ELSE 0 END) AS db_count " .
                    "FROM merge_conflicts c $conflict_group_filter " .
                    "GROUP BY group_key ORDER BY conflict_count DESC, group_key LIMIT :limit",
                    $conflict_group_params + [':group_by' => $filters['group_by']]
                );
            }
        }

        [$decision_filter, $decision_params] = cow_merge_audit_where_sql($run_id, $filters, 'decisions', '', $review_notes_exist);
        $decision_params[':limit'] = $limit;
        if ($filters['records'] === 'all' || $filters['records'] === 'decisions') {
            $report['decisions'] = cow_merge_audit_add_payload_previews(cow_merge_audit_table_rows(
                $db,
                'merge_decisions',
                "SELECT id, run_id, table_name, row_identity, column_name, decision, reason, created_at, " .
                "base_payload, source_payload, target_payload, chosen_payload$decision_review_select " .
                "FROM merge_decisions $decision_filter ORDER BY id DESC LIMIT :limit",
                $decision_params
            ));
            if ($filters['records'] === 'decisions' && $filters['group_by'] !== 'none') {
                [$decision_group_filter, $decision_group_params] = cow_merge_audit_where_sql($run_id, $filters, 'decisions', 'd', $review_notes_exist);
                $decision_group_params[':limit'] = $limit;
                $group_expr = cow_merge_audit_decision_group_sql($filters['group_by']);
                $report['decision_groups'] = cow_merge_audit_table_rows(
                    $db,
                    'merge_decisions',
                    "SELECT :group_by AS group_by, $group_expr AS group_key, COUNT(*) AS decision_count, " .
                    "SUM(CASE WHEN d.decision = 'target-wins' THEN 1 ELSE 0 END) AS target_wins_count, " .
                    "SUM(CASE WHEN d.decision = 'target-accepted' THEN 1 ELSE 0 END) AS target_accepted_count, " .
                    "SUM(CASE WHEN d.decision = 'source-applied' THEN 1 ELSE 0 END) AS source_applied_count, " .
                    "SUM(CASE WHEN d.decision = 'target-kept' THEN 1 ELSE 0 END) AS target_kept_count, " .
                    "SUM(CASE WHEN d.decision = 'id-band-skipped' THEN 1 ELSE 0 END) AS id_band_skipped_count, " .
                    "SUM(CASE WHEN d.table_name = '__files__' THEN 1 ELSE 0 END) AS file_count, " .
                    "SUM(CASE WHEN d.table_name = '__plugins__' THEN 1 ELSE 0 END) AS plugin_count, " .
                    "SUM(CASE WHEN d.table_name NOT IN ('__files__', '__plugins__') THEN 1 ELSE 0 END) AS db_count " .
                    "FROM merge_decisions d $decision_group_filter " .
                    "GROUP BY group_key ORDER BY decision_count DESC, group_key LIMIT :limit",
                    $decision_group_params + [':group_by' => $filters['group_by']]
                );
            }
        }

        if ($filters['records'] === 'all' || $filters['records'] === 'resolutions') {
            [$resolution_filter, $resolution_params] = cow_merge_audit_resolution_where_sql($run_id, $filters, $review_notes_exist);
            $resolution_params[':limit'] = $limit;
            $report['resolutions'] = cow_merge_audit_add_payload_previews(cow_merge_audit_table_rows(
                $db,
                'merge_resolutions',
                "SELECT mr.id, mr.conflict_id, c.run_id, mr.choice, mr.applied, mr.status, mr.reviewer, mr.note, mr.target_db, " .
                "mr.table_name, mr.row_identity, mr.column_name, mr.previous_payload AS target_payload, mr.resolved_payload AS chosen_payload, mr.created_at$resolution_review_select " .
                "FROM merge_resolutions mr JOIN merge_conflicts c ON c.id = mr.conflict_id $resolution_filter ORDER BY mr.id DESC LIMIT :limit",
                $resolution_params
            ));
            if ($filters['group_by'] !== 'none') {
                $group_expr = cow_merge_audit_resolution_group_sql($filters['group_by']);
                $report['resolution_groups'] = cow_merge_audit_table_rows(
                    $db,
                    'merge_resolutions',
                    "SELECT :group_by AS group_by, $group_expr AS group_key, COUNT(*) AS resolution_count, " .
                    "SUM(CASE WHEN mr.status = 'applied' THEN 1 ELSE 0 END) AS applied_count, " .
                    "SUM(CASE WHEN mr.status = 'validated' THEN 1 ELSE 0 END) AS validated_count, " .
                    "SUM(CASE WHEN mr.choice = 'source' THEN 1 ELSE 0 END) AS source_choice_count, " .
                    "SUM(CASE WHEN mr.choice = 'target' THEN 1 ELSE 0 END) AS target_choice_count " .
                    "FROM merge_resolutions mr JOIN merge_conflicts c ON c.id = mr.conflict_id $resolution_filter " .
                    "GROUP BY group_key ORDER BY resolution_count DESC, group_key LIMIT :limit",
                    $resolution_params + [':group_by' => $filters['group_by']]
                );
            }
        }

        $filter_params = [':limit' => $limit];
        if ($run_id !== null) {
            $filter_params[':run_id'] = $run_id;
        }
        if ($filters['scope'] !== 'files' && $filters['scope'] !== 'plugin' && in_array($filters['records'], ['all', 'decisions'], true) && !$filters['id_band_skips'] && !$filters['target_kept']) {
            $band_filter = $run_id === null ? '' : 'WHERE allocated_run_id = :run_id OR last_seen_run_id = :run_id';
            $report['autoincrement_bands'] = cow_merge_audit_table_rows(
                $db,
                'merge_autoincrement_bands',
                "SELECT branch_name, table_name, band_start, band_end, band_size, allocated_run_id, last_seen_run_id, created_at, updated_at " .
                "FROM merge_autoincrement_bands $band_filter ORDER BY branch_name, table_name LIMIT :limit",
                $filter_params
            );

            $identity_filter = $run_id === null ? '' : 'WHERE first_seen_run_id = :run_id OR last_seen_run_id = :run_id';
            $report['row_identity_summary'] = cow_merge_audit_table_rows(
                $db,
                'merge_row_identities',
                "SELECT branch_name, table_name, COUNT(*) AS row_count, MIN(created_at) AS first_seen_at, MAX(updated_at) AS last_seen_at " .
                "FROM merge_row_identities $identity_filter GROUP BY branch_name, table_name ORDER BY branch_name, table_name LIMIT :limit",
                $filter_params
            );
        }

        if ($filters['records'] === 'all' || $filters['records'] === 'rollback-failures') {
            $rollback_filter = $run_id === null ? '' : 'WHERE run_id = :run_id';
            $rollback_params = [':limit' => $limit];
            if ($run_id !== null) {
                $rollback_params[':run_id'] = $run_id;
            }
            $report['rollback_failures'] = cow_merge_audit_table_rows(
                $db,
                'merge_rollback_failures',
                "SELECT id, run_id, source_branch, target_branch, original_failure, rollback_failure, artifact_path, created_at " .
                "FROM merge_rollback_failures $rollback_filter ORDER BY id DESC LIMIT :limit",
                $rollback_params
            );
        }
    } finally {
        $db->close();
    }
    return $report;
}

function cow_merge_audit_object_label(array $row): string {
    $label = (string)$row['table_name'];
    if (($row['row_identity'] ?? null) !== null && (string)$row['row_identity'] !== '') {
        $label .= '[' . cow_merge_audit_json_preview((string)$row['row_identity']) . ']';
    }
    if (($row['column_name'] ?? null) !== null && (string)$row['column_name'] !== '') {
        $label .= '.' . (string)$row['column_name'];
    }
    return $label;
}

function cow_merge_audit_filter_label(array $filters): string {
    $parts = [];
    foreach (['scope', 'records', 'conflict_type', 'decision', 'path', 'path_prefix', 'review_status', 'resolution_status', 'group_by'] as $key) {
        $value = $filters[$key] ?? null;
        if ($value === null || $value === '') {
            continue;
        }
        if (($key === 'scope' && $value === 'all') || ($key === 'records' && $value === 'all')) {
            continue;
        }
        if ($key === 'group_by' && $value === 'none') {
            continue;
        }
        $parts[] = str_replace('_', '-', $key) . '=' . $value;
    }
    if (($filters['id_band_skips'] ?? false) === true) {
        $parts[] = 'id-band-skips';
    }
    if (($filters['target_kept'] ?? false) === true) {
        $parts[] = 'target-kept';
    }
    if (($filters['review'] ?? false) === true) {
        $parts[] = 'review';
    }
    return implode(' ', $parts);
}

function cow_merge_audit_effective_review_status(array $row, array $filters): ?string {
    $status = $row['review_status'] ?? null;
    if ($status !== null && (string)$status !== '') {
        return (string)$status;
    }
    if (($filters['review_status'] ?? null) === 'unreviewed') {
        return 'unreviewed';
    }
    return null;
}

function cow_merge_print_audit_review_text(array $row, array $filters, string $note_label = 'note'): void {
    $status = cow_merge_audit_effective_review_status($row, $filters);
    if ($status === null) {
        return;
    }
    if ($status === 'unreviewed' && (($row['review_status'] ?? null) === null || (string)$row['review_status'] === '')) {
        echo "     review=unreviewed\n";
        return;
    }
    echo "     review=$status reviewer={$row['review_reviewer']} at={$row['reviewed_at']}\n";
    echo "     $note_label=" . cow_merge_audit_truncate((string)$row['review_note'], 240) . "\n";
}

function cow_merge_print_audit_text(array $report): void {
    echo "forkpress: COW merge audit\n";
    echo "  metadata:  {$report['metadata_db']}\n";
    $filters = $report['filters'] ?? [];
    $filter_label = cow_merge_audit_filter_label($report['filters'] ?? []);
    if ($filter_label !== '') {
        echo "  filters:   $filter_label\n";
    }
    if (!$report['metadata_exists']) {
        echo "  status:    no metadata database\n";
        return;
    }
    if (!$report['runs'] && !$report['rollback_failures']) {
        echo "  status:    no audit runs\n";
        return;
    }

    if ($report['runs']) {
        echo "runs:\n";
        foreach ($report['runs'] as $run) {
            $finished = $run['finished_at'] !== null && $run['finished_at'] !== '' ? (string)$run['finished_at'] : 'running';
            echo "  #{$run['id']} {$run['status']} {$run['source_branch']} -> {$run['target_branch']} policy={$run['policy']} started={$run['started_at']} finished=$finished\n";
            echo "     decisions={$run['decision_count']} target-wins={$run['target_wins_count']} target-accepted={$run['target_accepted_count']} source-applied={$run['source_applied_count']} target-kept={$run['target_kept_count']} id-bands={$run['id_band_decision_count']} conflicts={$run['conflict_count']}\n";
            if ((string)$run['status'] === 'failed' && isset($run['failure_reason']) && (string)$run['failure_reason'] !== '') {
                echo "     failure=" . cow_merge_audit_truncate((string)$run['failure_reason'], 240) . "\n";
            }
        }
    }

    if ($report['conflicts']) {
        echo "conflicts:\n";
        foreach ($report['conflicts'] as $conflict) {
            $object = cow_merge_audit_object_label($conflict);
            echo "  #{$conflict['id']} run={$conflict['run_id']} {$conflict['conflict_type']} $object resolver={$conflict['resolver']} resolved_at={$conflict['resolved_at']}\n";
            cow_merge_print_audit_review_text($conflict, $filters);
            if (isset($conflict['stale_status']) && $conflict['stale_status'] !== 'unknown') {
                echo "     stale={$conflict['stale_status']} reason=" . cow_merge_audit_truncate((string)$conflict['stale_reason'], 240) . "\n";
                if (array_key_exists('current_target_preview', $conflict)) {
                    echo "     current-target={$conflict['current_target_preview']}\n";
                }
            }
            echo "     source={$conflict['source_preview']}\n";
            echo "     target={$conflict['target_preview']}\n";
            echo "     chosen={$conflict['chosen_preview']}\n";
        }
    }

    if ($report['conflict_groups']) {
        echo "conflict-groups:\n";
        foreach ($report['conflict_groups'] as $group) {
            echo "  {$group['group_by']}={$group['group_key']} conflicts={$group['conflict_count']} target-wins={$group['target_wins_count']} resolved={$group['resolved_count']} files={$group['file_count']} plugin={$group['plugin_count']} db={$group['db_count']}\n";
        }
    }

    if ($report['decisions']) {
        echo "decisions:\n";
        foreach ($report['decisions'] as $decision) {
            $object = cow_merge_audit_object_label($decision);
            echo "  #{$decision['id']} run={$decision['run_id']} {$decision['decision']} $object\n";
            cow_merge_print_audit_review_text($decision, $filters);
            echo "     reason={$decision['reason']}\n";
            echo "     chosen={$decision['chosen_preview']}\n";
        }
    }

    if ($report['decision_groups']) {
        echo "decision-groups:\n";
        foreach ($report['decision_groups'] as $group) {
            echo "  {$group['group_by']}={$group['group_key']} decisions={$group['decision_count']} target-wins={$group['target_wins_count']} target-accepted={$group['target_accepted_count']} source-applied={$group['source_applied_count']} target-kept={$group['target_kept_count']} id-band-skipped={$group['id_band_skipped_count']} files={$group['file_count']} plugin={$group['plugin_count']} db={$group['db_count']}\n";
        }
    }

    if ($report['resolutions']) {
        echo "resolutions:\n";
        foreach ($report['resolutions'] as $resolution) {
            $object = cow_merge_audit_object_label($resolution);
            $applied = ((int)$resolution['applied']) === 1 ? 'yes' : 'no';
            echo "  #{$resolution['id']} conflict={$resolution['conflict_id']} run={$resolution['run_id']} {$resolution['choice']} $object status={$resolution['status']} applied=$applied reviewer={$resolution['reviewer']}\n";
            cow_merge_print_audit_review_text($resolution, $filters, 'review-note');
            echo "     note=" . cow_merge_audit_truncate((string)$resolution['note'], 240) . "\n";
            echo "     previous={$resolution['target_preview']}\n";
            echo "     resolved={$resolution['chosen_preview']}\n";
        }
    }

    if ($report['resolution_groups']) {
        echo "resolution-groups:\n";
        foreach ($report['resolution_groups'] as $group) {
            echo "  {$group['group_by']}={$group['group_key']} resolutions={$group['resolution_count']} applied={$group['applied_count']} validated={$group['validated_count']} source={$group['source_choice_count']} target={$group['target_choice_count']}\n";
        }
    }

    if ($report['rollback_failures']) {
        echo "rollback-failures:\n";
        foreach ($report['rollback_failures'] as $failure) {
            $run = $failure['run_id'] !== null && $failure['run_id'] !== '' ? "run={$failure['run_id']}" : 'run=unknown';
            echo "  #{$failure['id']} $run {$failure['source_branch']} -> {$failure['target_branch']} created={$failure['created_at']}\n";
            echo "     original=" . cow_merge_audit_truncate((string)$failure['original_failure'], 240) . "\n";
            echo "     rollback=" . cow_merge_audit_truncate((string)$failure['rollback_failure'], 240) . "\n";
            if (($failure['artifact_path'] ?? null) !== null && (string)$failure['artifact_path'] !== '') {
                echo "     artifact={$failure['artifact_path']}\n";
            }
        }
    }

    if ($report['autoincrement_bands']) {
        echo "autoincrement-bands:\n";
        foreach ($report['autoincrement_bands'] as $band) {
            echo "  {$band['branch_name']} {$band['table_name']} {$band['band_start']}..{$band['band_end']} last_run={$band['last_seen_run_id']}\n";
        }
    }

    if ($report['row_identity_summary']) {
        echo "row-identity-sidecars:\n";
        foreach ($report['row_identity_summary'] as $summary) {
            echo "  {$summary['branch_name']} {$summary['table_name']} rows={$summary['row_count']} last_seen={$summary['last_seen_at']}\n";
        }
    }
}

function cow_merge_apply_source_table(
    SQLite3 $source,
    SQLite3 $target,
    SQLite3 $meta,
    int $run_id,
    string $source_branch,
    string $target_branch,
    string $table,
    string $ddl,
    array $source_indexes
): array {
    cow_merge_exec_checked($target, $ddl, "failed to create target table $table");
    cow_merge_record_decision(
        $meta,
        $run_id,
        $table,
        null,
        null,
        'source-applied',
        'source added table and target had no table',
        null,
        $ddl,
        null,
        $ddl
    );
    $columns = cow_merge_table_columns($source, $table);
    $pk_cols = cow_merge_pk_cols($source, $table);
    $rows = $pk_cols
        ? cow_merge_load_rows($source, $table, $pk_cols)
        : cow_merge_keyless_rows_for_branch($source, $meta, $run_id, $source_branch, $table, []);
    $applied = 1;
    $conflicts = 0;
    $row_keys = cow_merge_sort_row_keys_by_self_foreign_keys($target, $table, array_keys($rows), $rows);
    foreach ($row_keys as $identity_json) {
        $entry = $rows[$identity_json];
        if (!$pk_cols) {
            $insert_result = cow_merge_try_insert_row_with_rowid($target, $table, (int)$entry['rowid'], $entry['row'], $columns);
        } else {
            $insert_result = cow_merge_try_insert_row($target, $table, $entry['row'], $columns);
        }
        if (!($insert_result['ok'] ?? false)) {
            if (cow_merge_record_row_target_constraint(
                $meta,
                $run_id,
                $table,
                $identity_json,
                null,
                $entry['row'],
                null,
                'insert',
                (string)($insert_result['error'] ?? 'SQLite constraint failed')
            )) {
                $conflicts++;
            }
            continue;
        }
        if (!$pk_cols) {
            $new_rowid = (int)($insert_result['rowid'] ?? 0);
            cow_merge_remember_row_identity($meta, $run_id, $target_branch, $table, $new_rowid, $entry['identity'], $entry['row']);
        }
        cow_merge_record_decision(
            $meta,
            $run_id,
            $table,
            $identity_json,
            null,
            'source-applied',
            'source added table row and target had no table',
            null,
            $entry['row'],
            null,
            $entry['row']
        );
        $applied++;
    }
    cow_merge_apply_source_table_indexes($target, $table, $source_indexes);
    return ['applied' => $applied, 'conflicts' => $conflicts];
}

function cow_merge_apply_source_table_indexes(SQLite3 $target, string $table, array $source_indexes): void {
    foreach ($source_indexes as $index => $entry) {
        if ((string)($entry['table'] ?? '') !== $table) {
            continue;
        }
        if (cow_merge_index_sql($target, (string)$index) !== null) {
            continue;
        }
        cow_merge_test_hook(
            'before_sqlite_exec',
            $target,
            (string)$entry['sql'],
            'failed to apply early source-added table index schema merge'
        );
        @$target->exec((string)$entry['sql']);
    }
}

function cow_merge_validate_source_table_restore_dependencies(SQLite3 $source, SQLite3 $target, string $table): void {
    foreach (cow_merge_foreign_key_groups($source, $table) as $group) {
        $parent_table = (string)($group[0]['table'] ?? '');
        if ($parent_table === '' || $parent_table === $table) {
            continue;
        }
        $parent_columns = cow_merge_foreign_key_parent_columns($source, $parent_table, $group);
        if ($parent_columns === null) {
            throw new InvalidArgumentException("source table restore for $table cannot inspect parent key for $parent_table");
        }
        if (cow_merge_table_sql($target, $parent_table) === null) {
            throw new InvalidArgumentException("source table restore for $table requires parent table $parent_table; restore or materialize that parent table before restoring the child table");
        }

        $pk_cols = cow_merge_pk_cols($source, $table);
        foreach (cow_merge_load_rows($source, $table, $pk_cols) as $entry) {
            $row = $entry['row'];
            $from_columns = [];
            $values = [];
            $skip = false;
            foreach ($group as $i => $part) {
                $from = (string)($part['from'] ?? '');
                if ($from === '' || !isset($parent_columns[$i])) {
                    throw new InvalidArgumentException("source table restore for $table cannot inspect parent key for $parent_table");
                }
                if (!array_key_exists($from, $row) || $row[$from] === null) {
                    $skip = true;
                    break;
                }
                $from_columns[] = $from;
                $values[] = $row[$from];
            }
            if ($skip || !$from_columns) {
                continue;
            }
            if (!cow_merge_row_exists_by_values($target, $parent_table, $parent_columns, $values)) {
                throw new InvalidArgumentException(
                    'source table restore for ' . $table .
                    ' requires parent row in ' . $parent_table .
                    '(' . implode(', ', $parent_columns) . ') before restoring child rows'
                );
            }
        }
    }
}

function cow_merge_restore_source_table(
    SQLite3 $source,
    SQLite3 $target,
    SQLite3 $meta,
    int $run_id,
    string $source_branch,
    string $target_branch,
    string $table,
    array $restore_payload
): int {
    if (cow_merge_table_sql($target, $table) !== null) {
        throw new RuntimeException("target table already exists during source table restore: $table");
    }
    $dependent_views = cow_merge_table_dependent_views($target, $table);
    $dependent_view_triggers = cow_merge_view_trigger_dependencies($target, $dependent_views);
    $dependent_trigger_names = array_map(
        fn(array $dependency): string => (string)$dependency['name'],
        $dependent_view_triggers
    );
    $dependent_schema_objects = array_merge([$table], $dependent_views);
    $dependent_triggers = array_merge(
        $dependent_view_triggers,
        cow_merge_trigger_body_dependencies($target, $dependent_schema_objects, $dependent_trigger_names)
    );
    cow_merge_validate_source_table_restore_dependencies($source, $target, $table);
    cow_merge_forget_table_row_identities($meta, $run_id, $target_branch, $table);
    $ddl = (string)$restore_payload['table_sql'];
    cow_merge_exec_checked($target, $ddl, "failed to restore target table $table");
    $columns = cow_merge_table_columns($source, $table);
    $pk_cols = cow_merge_pk_cols($source, $table);
    $rows = $pk_cols
        ? cow_merge_load_rows($source, $table, $pk_cols)
        : cow_merge_keyless_rows_for_branch($source, $meta, $run_id, $source_branch, $table, []);
    $restored = 0;
    $row_keys = cow_merge_sort_row_keys_by_self_foreign_keys($target, $table, array_keys($rows), $rows);
    foreach ($row_keys as $identity_json) {
        $entry = $rows[$identity_json];
        if (!$pk_cols) {
            $new_rowid = cow_merge_insert_row_with_rowid($target, $table, (int)$entry['rowid'], $entry['row'], $columns);
            cow_merge_remember_row_identity($meta, $run_id, $target_branch, $table, $new_rowid, $entry['identity'], $entry['row']);
        } else {
            cow_merge_insert_row($target, $table, $entry['row'], $columns);
        }
        $restored++;
    }
    foreach ($restore_payload['indexes'] as $index) {
        if (cow_merge_index_sql($target, (string)$index['name']) !== null) {
            throw new RuntimeException('target index already exists during source table restore: ' . $index['name']);
        }
        cow_merge_exec_checked(
            $target,
            (string)$index['sql'],
            'failed to restore source table index ' . $index['name']
        );
    }
    foreach ($restore_payload['triggers'] as $trigger) {
        if (cow_merge_schema_object_sql($target, 'trigger', (string)$trigger['name']) !== null) {
            throw new RuntimeException('target trigger already exists during source table restore: ' . $trigger['name']);
        }
        cow_merge_validate_trigger_references($target, (string)$trigger['name'], (string)$trigger['sql']);
        cow_merge_validate_trigger_program_acyclic($target, (string)$trigger['name'], (string)$trigger['sql']);
        cow_merge_exec_checked(
            $target,
            (string)$trigger['sql'],
            'failed to restore source table trigger ' . $trigger['name']
        );
        cow_merge_validate_trigger_program($target, (string)$trigger['name'], (string)$trigger['sql']);
    }
    cow_merge_validate_views($target, $dependent_views, 'source-table-restore');
    foreach ($dependent_triggers as $dependency) {
        cow_merge_validate_schema_dependency_program($target, $dependency, 'source table restore');
    }
    cow_merge_validate_foreign_key_integrity($target, 'source table restore');
    return $restored;
}

function cow_merge_validate_source_table_restore(
    SQLite3 $source,
    SQLite3 $target,
    SQLite3 $meta,
    int $run_id,
    string $source_branch,
    string $target_branch,
    string $table,
    array $restore_payload
): void {
    $target_savepoint_started = false;
    $meta_savepoint_started = false;
    try {
        cow_merge_exec_checked(
            $target,
            'SAVEPOINT forkpress_source_table_restore_validation',
            'failed to start source table restore validation target savepoint'
        );
        $target_savepoint_started = true;
        cow_merge_exec_checked(
            $meta,
            'SAVEPOINT forkpress_source_table_restore_validation_meta',
            'failed to start source table restore validation metadata savepoint'
        );
        $meta_savepoint_started = true;
        cow_merge_restore_source_table(
            $source,
            $target,
            $meta,
            $run_id,
            $source_branch,
            $target_branch,
            $table,
            $restore_payload
        );
        $target_cleanup_failure = cow_merge_rollback_release_savepoint_checked(
            $target,
            'forkpress_source_table_restore_validation',
            'source table restore validation target'
        );
        if ($target_cleanup_failure !== null) {
            throw $target_cleanup_failure;
        }
        $target_savepoint_started = false;
        $meta_cleanup_failure = cow_merge_rollback_release_savepoint_checked(
            $meta,
            'forkpress_source_table_restore_validation_meta',
            'source table restore validation metadata'
        );
        if ($meta_cleanup_failure !== null) {
            throw $meta_cleanup_failure;
        }
        $meta_savepoint_started = false;
    } catch (Throwable $e) {
        if ($target_savepoint_started) {
            $cleanup_failure = cow_merge_rollback_release_savepoint_checked(
                $target,
                'forkpress_source_table_restore_validation',
                'source table restore validation target',
                $e
            );
            if ($cleanup_failure !== null) {
                $e = $cleanup_failure;
            }
        }
        if ($meta_savepoint_started) {
            $cleanup_failure = cow_merge_rollback_release_savepoint_checked(
                $meta,
                'forkpress_source_table_restore_validation_meta',
                'source table restore validation metadata',
                $e
            );
            if ($cleanup_failure !== null) {
                $e = $cleanup_failure;
            }
        }
        throw $e;
    }
}

function cow_merge_record_schema_conflict(
    SQLite3 $meta,
    int $run_id,
    string $table,
    ?string $object,
    string $type,
    mixed $base,
    mixed $source,
    mixed $target_value,
    mixed $chosen,
    string $reason
): bool {
    $active = cow_merge_record_conflict($meta, $run_id, $table, null, $object, $type, $base, $source, $target_value, $chosen);
    cow_merge_record_decision(
        $meta,
        $run_id,
        $table,
        null,
        $object,
        $active ? 'target-wins' : 'target-accepted',
        $active ? $reason : 'reviewed target resolution already accepts this schema conflict: ' . $reason,
        $base,
        $source,
        $target_value,
        $chosen
    );
    return $active;
}

function cow_merge_record_matching_schema_decision(
    SQLite3 $meta,
    int $run_id,
    string $table,
    ?string $object,
    string $schema_type,
    mixed $base,
    mixed $source,
    mixed $target_value
): void {
    if ($source === null) {
        $reason = "source and target dropped the same $schema_type schema";
    } elseif ($base === null) {
        $reason = "source and target added the same $schema_type schema";
    } else {
        $reason = "source and target changed $schema_type schema to the same definition";
    }
    cow_merge_record_decision(
        $meta,
        $run_id,
        $table,
        null,
        $object,
        'source-applied',
        $reason,
        $base,
        $source,
        $target_value,
        $target_value
    );
}

function cow_merge_schema_conflict_result(bool $active): array {
    return ['merged' => false, 'applied' => 0, 'conflicts' => $active ? 1 : 0];
}

function cow_merge_apply_safe_table_schema_changes(
    SQLite3 $base,
    SQLite3 $source,
    SQLite3 $target,
    SQLite3 $meta,
    int $run_id,
    string $table,
    ?string $base_sql,
    string $source_sql,
    string $target_sql
): array {
    if ($base_sql === null) {
        $active = cow_merge_record_schema_conflict(
            $meta,
            $run_id,
            $table,
            null,
            'schema-conflict',
            null,
            $source_sql,
            $target_sql,
            $target_sql,
            'source and target independently added incompatible table schemas'
        );
        return cow_merge_schema_conflict_result($active);
    }

    $base_columns = cow_merge_table_info($base, $table);
    $source_columns = cow_merge_table_info($source, $table);
    $target_columns = cow_merge_table_info($target, $table);
    if (
        !cow_merge_base_column_prefix_unchanged($base_columns, $source_columns) ||
        !cow_merge_base_column_prefix_unchanged($base_columns, $target_columns)
    ) {
        $active = cow_merge_record_schema_conflict(
            $meta,
            $run_id,
            $table,
            null,
            'schema-conflict',
            $base_sql,
            $source_sql,
            $target_sql,
            $target_sql,
            'source or target changed existing table columns; only appended columns can be merged automatically'
        );
        return cow_merge_schema_conflict_result($active);
    }

    $base_by_name = cow_merge_columns_by_name($base_columns);
    $source_by_name = cow_merge_columns_by_name($source_columns);
    $target_by_name = cow_merge_columns_by_name($target_columns);
    $columns_to_add = [];
    $matching_columns = [];
    foreach ($source_columns as $source_column) {
        $name_key = strtolower((string)$source_column['name']);
        if (isset($base_by_name[$name_key])) {
            continue;
        }
        if (isset($target_by_name[$name_key])) {
            if (!cow_merge_column_signatures_equal($source_column, $target_by_name[$name_key])) {
                $active = cow_merge_record_schema_conflict(
                    $meta,
                    $run_id,
                    $table,
                    (string)$source_column['name'],
                    'schema-column-conflict',
                    null,
                    $source_column,
                    $target_by_name[$name_key],
                    $target_by_name[$name_key],
                    'source and target added the same column name with incompatible definitions'
                );
                return cow_merge_schema_conflict_result($active);
            }
            $matching_columns[] = [
                'source_column' => $source_column,
                'target_column' => $target_by_name[$name_key],
                'source_definition' => cow_merge_column_definition_from_create_sql($source_sql, (string)$source_column['name']),
                'target_definition' => cow_merge_column_definition_from_create_sql($target_sql, (string)$source_column['name']),
            ];
            continue;
        }

        $definition = cow_merge_column_definition_from_create_sql($source_sql, (string)$source_column['name']);
        if ($definition === null || !cow_merge_column_definition_is_safe_to_add($definition, $source_column)) {
            $active = cow_merge_record_schema_conflict(
                $meta,
                $run_id,
                $table,
                (string)$source_column['name'],
                'schema-source-changed',
                $base_sql,
                ['column' => $source_column, 'table_sql' => $source_sql],
                $target_sql,
                $target_sql,
                'source added a column whose definition is not safe to apply automatically'
            );
            return cow_merge_schema_conflict_result($active);
        }
        $columns_to_add[] = ['column' => $source_column, 'definition' => $definition];
    }

    if (!$columns_to_add && !$matching_columns) {
        return ['merged' => true, 'applied' => 0, 'conflicts' => 0];
    }

    if ($columns_to_add) {
        $target_savepoint_started = false;
        try {
            cow_merge_exec_checked(
                $target,
                'SAVEPOINT forkpress_schema_merge',
                'failed to start automatic schema merge savepoint'
            );
            $target_savepoint_started = true;
            foreach ($columns_to_add as $entry) {
                $column = $entry['column'];
                $definition = $entry['definition'];
                $sql = 'ALTER TABLE ' . cow_merge_quote_ident($table) . ' ADD COLUMN ' . $definition;
                cow_merge_test_hook('before_sqlite_exec', $target, $sql, 'failed to apply automatic source column schema merge');
                if (!$target->exec($sql)) {
                    $cleanup_failure = cow_merge_rollback_release_savepoint_checked(
                        $target,
                        'forkpress_schema_merge',
                        'automatic schema merge'
                    );
                    $target_savepoint_started = false;
                    if ($cleanup_failure !== null) {
                        throw $cleanup_failure;
                    }
                    $active = cow_merge_record_schema_conflict(
                        $meta,
                        $run_id,
                        $table,
                        (string)$column['name'],
                        'schema-source-changed',
                        $base_sql,
                        ['column' => $column, 'definition' => $definition, 'error' => $target->lastErrorMsg()],
                        $target_sql,
                        $target_sql,
                        'source added a column that SQLite rejected on the target'
                    );
                    return cow_merge_schema_conflict_result($active);
                }
            }
            cow_merge_release_savepoint_checked($target, 'forkpress_schema_merge', 'automatic schema merge');
            $target_savepoint_started = false;
        } catch (Throwable $e) {
            if ($target_savepoint_started) {
                $cleanup_failure = cow_merge_rollback_release_savepoint_checked(
                    $target,
                    'forkpress_schema_merge',
                    'automatic schema merge',
                    $e
                );
                if ($cleanup_failure !== null) {
                    throw $cleanup_failure;
                }
            }
            throw $e;
        }
    }

    foreach ($columns_to_add as $entry) {
        $column = $entry['column'];
        $definition = $entry['definition'];
        cow_merge_record_decision(
            $meta,
            $run_id,
            $table,
            null,
            (string)$column['name'],
            'source-applied',
            'source added a table column that target did not have',
            null,
            ['column' => $column, 'definition' => $definition],
            null,
            ['column' => $column, 'definition' => $definition]
        );
    }
    foreach ($target_columns as $target_column) {
        $name_key = strtolower((string)$target_column['name']);
        if (isset($base_by_name[$name_key]) || isset($source_by_name[$name_key])) {
            continue;
        }
        $definition = cow_merge_column_definition_from_create_sql($target_sql, (string)$target_column['name']);
        cow_merge_record_decision(
            $meta,
            $run_id,
            $table,
            null,
            (string)$target_column['name'],
            'target-kept',
            'target added a table column that source did not have',
            null,
            null,
            ['column' => $target_column, 'definition' => $definition],
            ['column' => $target_column, 'definition' => $definition]
        );
    }
    foreach ($matching_columns as $entry) {
        $column = $entry['source_column'];
        cow_merge_record_decision(
            $meta,
            $run_id,
            $table,
            null,
            (string)$column['name'],
            'source-applied',
            'source and target added the same table column',
            null,
            ['column' => $entry['source_column'], 'definition' => $entry['source_definition']],
            ['column' => $entry['target_column'], 'definition' => $entry['target_definition']],
            ['column' => $entry['target_column'], 'definition' => $entry['target_definition']]
        );
    }
    return ['merged' => true, 'applied' => count($columns_to_add) + count($matching_columns), 'conflicts' => 0];
}

function cow_merge_apply_index_schema_changes(
    SQLite3 $target,
    SQLite3 $meta,
    int $run_id,
    array $base_indexes,
    array $source_indexes,
    array $target_indexes
): array {
    $applied = 0;
    $conflicts = 0;
    $all_indexes = array_unique(array_merge(array_keys($base_indexes), array_keys($source_indexes), array_keys($target_indexes)));
    sort($all_indexes);

    foreach ($all_indexes as $index) {
        $base_entry = $base_indexes[$index] ?? null;
        $source_entry = $source_indexes[$index] ?? null;
        $target_entry = $target_indexes[$index] ?? null;
        $table = (string)($source_entry['table'] ?? $target_entry['table'] ?? $base_entry['table'] ?? '(unknown)');
        $base_sql = $base_entry['sql'] ?? null;
        $source_sql = $source_entry['sql'] ?? null;
        $target_sql = $target_entry['sql'] ?? null;

        if ($source_sql === $target_sql) {
            if ($source_sql !== $base_sql) {
                cow_merge_record_matching_schema_decision(
                    $meta,
                    $run_id,
                    $table,
                    $index,
                    'index',
                    $base_sql,
                    $source_sql,
                    $target_sql
                );
                $applied++;
            }
            continue;
        }
        if ($source_sql === null) {
            if ($base_sql !== null) {
                if (cow_merge_record_schema_conflict(
                    $meta,
                    $run_id,
                    $table,
                    $index,
                    'schema-source-dropped-index',
                    $base_sql,
                    null,
                    $target_sql,
                    $target_sql,
                    'source dropped an index; automatic index drops are not applied'
                )) {
                    $conflicts++;
                }
            } elseif ($target_sql !== null) {
                cow_merge_record_decision(
                    $meta,
                    $run_id,
                    $table,
                    null,
                    $index,
                    'target-kept',
                    'target added an index while source did not have it',
                    null,
                    null,
                    $target_sql,
                    $target_sql
                );
            }
            continue;
        }
        if ($base_sql === null && $target_sql === null) {
            cow_merge_test_hook('before_sqlite_exec', $target, $source_sql, 'failed to apply source-added index schema merge');
            if (!@$target->exec($source_sql)) {
                if (cow_merge_record_schema_conflict(
                    $meta,
                    $run_id,
                    $table,
                    $index,
                    'schema-source-added-index',
                    null,
                    ['sql' => $source_sql, 'error' => $target->lastErrorMsg()],
                    null,
                    null,
                    'source added an index that SQLite rejected on the target'
                )) {
                    $conflicts++;
                }
                continue;
            }
            cow_merge_record_decision(
                $meta,
                $run_id,
                $table,
                null,
                $index,
                'source-applied',
                'source added an index that target did not have',
                null,
                $source_sql,
                null,
                $source_sql
            );
            $applied++;
            continue;
        }
        if ($source_sql === $base_sql) {
            if ($target_sql !== $base_sql) {
                cow_merge_record_decision(
                    $meta,
                    $run_id,
                    $table,
                    null,
                    $index,
                    'target-kept',
                    'target changed an index while source did not change it',
                    $base_sql,
                    $source_sql,
                    $target_sql,
                    $target_sql
                );
            }
            continue;
        }
        if ($target_sql === $base_sql) {
            if (cow_merge_record_schema_conflict(
                $meta,
                $run_id,
                $table,
                $index,
                'schema-source-changed-index',
                $base_sql,
                $source_sql,
                $target_sql,
                $target_sql,
                'source changed an existing index; automatic index rewrites are not applied'
            )) {
                $conflicts++;
            }
            continue;
        }
        if (cow_merge_record_schema_conflict(
            $meta,
            $run_id,
            $table,
            $index,
            'schema-index-conflict',
            $base_sql,
            $source_sql,
            $target_sql,
            $target_sql,
            'source and target changed the same index differently'
        )) {
            $conflicts++;
        }
    }

    return ['applied' => $applied, 'conflicts' => $conflicts];
}

function cow_merge_apply_schema_object_changes(
    SQLite3 $target,
    SQLite3 $meta,
    int $run_id,
    string $type,
    array $base_objects,
    array $source_objects,
    array $target_objects
): array {
    if (!in_array($type, ['view', 'trigger'], true)) {
        throw new InvalidArgumentException("unsupported schema object type: $type");
    }
    $applied = 0;
    $conflicts = 0;
    $all_objects = array_unique(array_merge(array_keys($base_objects), array_keys($source_objects), array_keys($target_objects)));
    sort($all_objects);
    $view_cycles = [];
    $trigger_cycles = [];
    if ($type === 'view') {
        $view_cycles = cow_merge_view_schema_dependency_cycles($all_objects, $source_objects);
        $all_objects = cow_merge_sort_view_schema_objects($all_objects, $source_objects);
    } elseif ($type === 'trigger') {
        $trigger_cycles = cow_merge_trigger_program_dependency_cycles($all_objects, $source_objects);
        $all_objects = cow_merge_sort_trigger_schema_objects($all_objects, $source_objects);
    }

    foreach ($all_objects as $name) {
        $base_entry = $base_objects[$name] ?? null;
        $source_entry = $source_objects[$name] ?? null;
        $target_entry = $target_objects[$name] ?? null;
        $table = (string)($source_entry['table'] ?? $target_entry['table'] ?? $base_entry['table'] ?? $name);
        $base_sql = $base_entry['sql'] ?? null;
        $source_sql = $source_entry['sql'] ?? null;
        $target_sql = $target_entry['sql'] ?? null;

        if ($source_sql === $target_sql) {
            if ($source_sql !== $base_sql) {
                cow_merge_record_matching_schema_decision(
                    $meta,
                    $run_id,
                    $table,
                    $name,
                    $type,
                    $base_sql,
                    $source_sql,
                    $target_sql
                );
                $applied++;
            }
            continue;
        }
        if ($source_sql === null) {
            if ($base_sql !== null) {
                if (cow_merge_record_schema_conflict(
                    $meta,
                    $run_id,
                    $table,
                    $name,
                    "schema-source-dropped-$type",
                    $base_sql,
                    null,
                    $target_sql,
                    $target_sql,
                    "source dropped a $type; automatic $type drops are not applied"
                )) {
                    $conflicts++;
                }
            } elseif ($target_sql !== null) {
                cow_merge_record_decision(
                    $meta,
                    $run_id,
                    $table,
                    null,
                    $name,
                    'target-kept',
                    "target added a $type while source did not have it",
                    null,
                    null,
                    $target_sql,
                    $target_sql
                );
            }
            continue;
        }
        if ($base_sql === null && $target_sql === null) {
            $apply_error = null;
            $target_savepoint_started = false;
            $source_object_ddl_boundary = false;
            $source_object_created = false;
            $source_object_validated = false;
            try {
                cow_merge_exec_checked(
                    $target,
                    'SAVEPOINT forkpress_schema_object_apply',
                    "failed to start source-added $type schema apply savepoint"
                );
                $target_savepoint_started = true;
                if ($type === 'trigger') {
                    $cycle = $trigger_cycles[strtolower($name)] ?? null;
                    if ($cycle !== null) {
                        throw new InvalidArgumentException(
                            'source trigger ' . $name . ' has unsupported cyclic trigger dependencies: ' . $cycle
                        );
                    }
                    cow_merge_validate_trigger_references($target, $name, $source_sql);
                    cow_merge_validate_trigger_program_acyclic($target, $name, $source_sql);
                } elseif ($type === 'view') {
                    $cycle = $view_cycles[strtolower($name)] ?? null;
                    if ($cycle !== null) {
                        throw new InvalidArgumentException(
                            'source view ' . $name . ' has unsupported cyclic source view dependencies: ' . $cycle
                        );
                    }
                    cow_merge_validate_view_references($target, $name, $source_sql);
                    cow_merge_validate_view_schema_acyclic($target, $name, $source_sql);
                }
                $source_object_ddl_boundary = true;
                cow_merge_test_hook('before_sqlite_exec', $target, $source_sql, "failed to apply source-added $type schema merge");
                if (!@$target->exec($source_sql)) {
                    $apply_error = $target->lastErrorMsg();
                    $cleanup_failure = cow_merge_rollback_release_savepoint_checked(
                        $target,
                        'forkpress_schema_object_apply',
                        "source-added $type schema apply"
                    );
                    $target_savepoint_started = false;
                    if ($cleanup_failure !== null) {
                        throw $cleanup_failure;
                    }
                } else {
                    $source_object_created = true;
                    if ($type === 'trigger') {
                        cow_merge_validate_trigger_program($target, $name, $source_sql);
                    }
                    if ($type === 'view') {
                        cow_merge_validate_view_schema($target, $name, 'source-added');
                    }
                    $source_object_validated = true;
                    cow_merge_release_savepoint_checked($target, 'forkpress_schema_object_apply', "source-added $type schema apply");
                    $target_savepoint_started = false;
                }
            } catch (Throwable $e) {
                if (!$target_savepoint_started) {
                    throw $e;
                }
                $cleanup_failure = cow_merge_rollback_release_savepoint_checked(
                    $target,
                    'forkpress_schema_object_apply',
                    "source-added $type schema apply",
                    $e
                );
                if ($cleanup_failure !== null) {
                    throw $cleanup_failure;
                }
                if ($source_object_ddl_boundary && !$source_object_created) {
                    throw $e;
                }
                if ($source_object_validated) {
                    throw $e;
                }
                $apply_error = $e->getMessage();
            }
            if ($apply_error !== null) {
                if (cow_merge_record_schema_conflict(
                    $meta,
                    $run_id,
                    $table,
                    $name,
                    "schema-source-added-$type",
                    null,
                    ['sql' => $source_sql, 'error' => $apply_error],
                    null,
                    null,
                    "source added a $type that target validation rejected"
                )) {
                    $conflicts++;
                }
                continue;
            }
            cow_merge_record_decision(
                $meta,
                $run_id,
                $table,
                null,
                $name,
                'source-applied',
                "source added a $type that target did not have",
                null,
                $source_sql,
                null,
                $source_sql
            );
            $applied++;
            continue;
        }
        if ($source_sql === $base_sql) {
            if ($target_sql !== $base_sql) {
                cow_merge_record_decision(
                    $meta,
                    $run_id,
                    $table,
                    null,
                    $name,
                    'target-kept',
                    "target changed a $type while source did not change it",
                    $base_sql,
                    $source_sql,
                    $target_sql,
                    $target_sql
                );
            }
            continue;
        }
        if ($target_sql === $base_sql) {
            if (cow_merge_record_schema_conflict(
                $meta,
                $run_id,
                $table,
                $name,
                "schema-source-changed-$type",
                $base_sql,
                $source_sql,
                $target_sql,
                $target_sql,
                "source changed an existing $type; automatic $type rewrites are not applied"
            )) {
                $conflicts++;
            }
            continue;
        }
        if (cow_merge_record_schema_conflict(
            $meta,
            $run_id,
            $table,
            $name,
            "schema-$type-conflict",
            $base_sql,
            $source_sql,
            $target_sql,
            $target_sql,
            "source and target changed the same $type differently"
        )) {
            $conflicts++;
        }
    }

    return ['applied' => $applied, 'conflicts' => $conflicts];
}

function cow_merge_record_row_target_constraint(
    SQLite3 $meta,
    int $run_id,
    string $table,
    string $key,
    ?array $base_row,
    ?array $source_row,
    ?array $target_row,
    string $operation,
    string $error
): bool {
    $active = cow_merge_record_conflict(
        $meta,
        $run_id,
        $table,
        $key,
        null,
        'row-target-constraint',
        $base_row,
        $source_row,
        $target_row,
        $target_row
    );
    $target_trigger_mutation = str_starts_with($error, 'target triggers ');
    if ($operation === 'insert') {
        $reason_prefix = $target_trigger_mutation ? 'source inserted row was rewritten by target triggers' : 'source inserted row violates target constraints';
        $accepted_prefix = $target_trigger_mutation ? 'reviewed target resolution already accepts source insert rewritten by target triggers' : 'reviewed target resolution already accepts source insert blocked by target constraints';
    } elseif ($operation === 'delete') {
        $reason_prefix = 'source deleted row violates target constraints';
        $accepted_prefix = 'reviewed target resolution already accepts source row delete blocked by target constraints';
    } else {
        $reason_prefix = $target_trigger_mutation ? 'source changed row was rewritten by target triggers' : 'source changed row violates target constraints';
        $accepted_prefix = $target_trigger_mutation ? 'reviewed target resolution already accepts source row change rewritten by target triggers' : 'reviewed target resolution already accepts source row change blocked by target constraints';
    }
    cow_merge_record_decision(
        $meta,
        $run_id,
        $table,
        $key,
        null,
        $active ? 'target-wins' : 'target-accepted',
        ($active ? $reason_prefix : $accepted_prefix) . ': ' . $error,
        $base_row,
        $source_row,
        $target_row,
        $target_row
    );
    return $active;
}

function cow_merge_table_rows(
    SQLite3 $base,
    SQLite3 $source,
    SQLite3 $target,
    SQLite3 $meta,
    int $run_id,
    string $source_branch,
    string $target_branch,
    string $table
): array {
    $columns = cow_merge_table_columns($target, $table);
    if (!$columns) {
        $columns = cow_merge_table_columns($source, $table);
    }
    $pk_cols = cow_merge_pk_cols($target, $table);
    if (!$pk_cols) {
        $pk_cols = cow_merge_pk_cols($source, $table);
    }

    if ($pk_cols) {
        $base_rows = cow_merge_load_rows($base, $table, $pk_cols);
        $source_rows = cow_merge_load_rows($source, $table, $pk_cols);
        $target_rows = cow_merge_load_rows($target, $table, $pk_cols);
    } else {
        [$base_rows, $source_rows, $target_rows] = cow_merge_load_keyless_sidecar_rows(
            $base,
            $source,
            $target,
            $meta,
            $run_id,
            $source_branch,
            $target_branch,
            $table
        );
    }
    $all_keys = array_unique(array_merge(array_keys($base_rows), array_keys($source_rows), array_keys($target_rows)));
    $all_keys = cow_merge_sort_row_keys_by_self_foreign_keys($target, $table, $all_keys, $source_rows);

    $applied = 0;
    $conflicts = 0;
    $externally_applied = [];
    $held_explicit_autoincrement_insert_reason = null;
    if ($pk_cols) {
        foreach ($all_keys as $key) {
            $base_entry = $base_rows[$key] ?? null;
            $source_entry = $source_rows[$key] ?? null;
            $target_entry = $target_rows[$key] ?? null;
            $source_row = $source_entry['row'] ?? null;
            if (($base_entry['row'] ?? null) !== null || !is_array($source_row) || ($target_entry['row'] ?? null) !== null) {
                continue;
            }
            $held_explicit_autoincrement_insert_reason = cow_merge_autoincrement_id_band_violation($meta, $source_branch, $table, $source_row, $pk_cols);
            if ($held_explicit_autoincrement_insert_reason !== null) {
                break;
            }
        }
    }
    foreach ($all_keys as $key) {
        if (isset($externally_applied[$key])) {
            continue;
        }
        $base_entry = $base_rows[$key] ?? null;
        $source_entry = $source_rows[$key] ?? null;
        $target_entry = $target_rows[$key] ?? null;
        $base_row = $base_entry['row'] ?? null;
        $source_row = $source_entry['row'] ?? null;
        $target_row = $target_entry['row'] ?? null;
        $identity = ($source_entry['identity'] ?? $target_entry['identity'] ?? $base_entry['identity'] ?? null);
        if (!is_array($identity)) {
            continue;
        }
        $row_columns = cow_merge_all_columns($columns, array_keys($base_row ?? []), array_keys($source_row ?? []), array_keys($target_row ?? []));

        if (cow_merge_row_values_equal($source_row, $base_row, $row_columns)) {
            if (!cow_merge_row_values_equal($target_row, $base_row, $row_columns)) {
                if ($base_row === null && $target_row !== null) {
                    cow_merge_record_decision($meta, $run_id, $table, $key, null, 'target-kept', 'target inserted row and source did not have it', null, null, $target_row, $target_row);
                } elseif ($base_row !== null && $target_row === null) {
                    cow_merge_record_decision($meta, $run_id, $table, $key, null, 'target-kept', 'target deleted row and source did not change it', $base_row, $source_row, null, null);
                } elseif ($base_row !== null && $target_row !== null) {
                    foreach ($row_columns as $col) {
                        if (in_array($col, $pk_cols, true)) {
                            continue;
                        }
                        $b = $base_row[$col] ?? null;
                        $t = $target_row[$col] ?? null;
                        if (!cow_merge_values_equal($t, $b)) {
                            cow_merge_record_decision($meta, $run_id, $table, $key, $col, 'target-kept', 'target changed cell and source did not change it', $b, $source_row[$col] ?? null, $t, $t);
                        }
                    }
                }
            }
            continue;
        }
        if (cow_merge_row_values_equal($target_row, $source_row, $row_columns)) {
            if ($pk_cols && $base_row === null && $source_row !== null && $target_row !== null) {
                cow_merge_record_decision(
                    $meta,
                    $run_id,
                    $table,
                    $key,
                    null,
                    'source-applied',
                    'source inserted row already exists in target with the same identity and payload',
                    null,
                    $source_row,
                    $target_row,
                    $target_row
                );
                $applied++;
            } elseif ($base_row !== null && $source_row === null && $target_row === null) {
                cow_merge_record_decision(
                    $meta,
                    $run_id,
                    $table,
                    $key,
                    null,
                    'source-applied',
                    'source and target deleted row with the same identity',
                    $base_row,
                    null,
                    null,
                    null
                );
                $applied++;
            } elseif ($base_row !== null && $source_row !== null && $target_row !== null) {
                cow_merge_record_decision(
                    $meta,
                    $run_id,
                    $table,
                    $key,
                    null,
                    'source-applied',
                    'source and target changed row to the same payload',
                    $base_row,
                    $source_row,
                    $target_row,
                    $target_row
                );
                $applied++;
            }
            continue;
        }

        if ($base_row === null && $source_row !== null && $target_row === null) {
            $id_band_violation = $pk_cols
                ? cow_merge_autoincrement_id_band_violation($meta, $source_branch, $table, $source_row, $pk_cols)
                : null;
            if ($id_band_violation !== null) {
                if (cow_merge_record_row_target_constraint(
                    $meta,
                    $run_id,
                    $table,
                    $key,
                    null,
                    $source_row,
                    null,
                    'insert',
                    $id_band_violation
                )) {
                    $conflicts++;
                }
                continue;
            }
            $wp_reference_violation = cow_merge_wordpress_insert_reference_violation($source, $target, $meta, $source_branch, $table, $source_row);
            if ($wp_reference_violation !== null) {
                if (cow_merge_record_row_target_constraint(
                    $meta,
                    $run_id,
                    $table,
                    $key,
                    null,
                    $source_row,
                    null,
                    'insert',
                    $wp_reference_violation
                )) {
                    $conflicts++;
                }
                continue;
            }
            if ($pk_cols) {
                $current_row = cow_merge_select_current_row($target, $table, $identity, $pk_cols);
                if ($current_row !== null && cow_merge_row_values_equal($current_row, $source_row, $row_columns)) {
                    cow_merge_record_decision(
                        $meta,
                        $run_id,
                        $table,
                        $key,
                        null,
                        'source-applied',
                        'source inserted row already exists in target with the same identity and payload',
                        null,
                        $source_row,
                        $current_row,
                        $current_row
                    );
                    $applied++;
                    continue;
                }
            }
            $unique_collision = cow_merge_find_unique_collision($target, $table, $source_row, !$pk_cols);
            if ($unique_collision !== null) {
                $unique_columns = cow_merge_all_columns($columns, array_keys($source_row), array_keys($unique_collision['row']));
                if (!$pk_cols && cow_merge_row_values_equal($source_row, $unique_collision['row'], $unique_columns)) {
                    if (!isset($unique_collision['rowid'])) {
                        throw new RuntimeException("cannot adopt $table unique collision identity because the target rowid is unavailable");
                    }
                    cow_merge_adopt_row_identity($meta, $run_id, $target_branch, $table, (int)$unique_collision['rowid'], $identity, $unique_collision['row']);
                    cow_merge_record_decision(
                        $meta,
                        $run_id,
                        $table,
                        $key,
                        null,
                        'source-applied',
                        'source inserted no-primary-key row already exists in target by unique index ' . $unique_collision['index'],
                        null,
                        $source_row,
                        $unique_collision['row'],
                        $unique_collision['row']
                    );
                    $applied++;
                    continue;
                }
                $active = cow_merge_record_conflict(
                    $meta,
                    $run_id,
                    $table,
                    $key,
                    null,
                    'row-unique-collision',
                    null,
                    $source_row,
                    $unique_collision['row'],
                    $unique_collision['row']
                );
                cow_merge_record_decision(
                    $meta,
                    $run_id,
                    $table,
                    $key,
                    null,
                    $active ? 'target-wins' : 'target-accepted',
                    ($active ? 'source inserted row collides with target unique index ' : 'reviewed target resolution already accepts source row collision with target unique index ') . $unique_collision['index'],
                    null,
                    $source_row,
                    $unique_collision['row'],
                    $unique_collision['row']
                );
                if ($active) {
                    $conflicts++;
                }
                continue;
            }
            $insert_result = cow_merge_try_insert_row_preserving_payload(
                $target,
                $table,
                $source_row,
                $columns,
                $identity,
                $pk_cols
            );
            if (!($insert_result['ok'] ?? false)) {
                if (cow_merge_record_row_target_constraint(
                    $meta,
                    $run_id,
                    $table,
                    $key,
                    null,
                    $source_row,
                    null,
                    'insert',
                    (string)($insert_result['error'] ?? 'SQLite constraint failed')
                )) {
                    $conflicts++;
                }
                continue;
            }
            $new_rowid = (int)($insert_result['rowid'] ?? 0);
            if (!$pk_cols) {
                cow_merge_remember_row_identity($meta, $run_id, $target_branch, $table, $new_rowid, $identity, $source_row);
            }
            cow_merge_record_decision($meta, $run_id, $table, $key, null, 'source-applied', 'source inserted row and target did not change it', null, $source_row, null, $source_row);
            $applied++;
            continue;
        }

        if ($base_row !== null && $source_row === null && cow_merge_row_values_equal($target_row, $base_row, $row_columns)) {
            $wp_delete_reference_violation = cow_merge_wordpress_delete_reference_violation($source, $target, $meta, $source_branch, $table, $base_row);
            if ($wp_delete_reference_violation !== null) {
                if (cow_merge_record_row_target_constraint(
                    $meta,
                    $run_id,
                    $table,
                    $key,
                    $base_row,
                    null,
                    $target_row,
                    'update',
                    $wp_delete_reference_violation
                )) {
                    $conflicts++;
                }
                continue;
            }
            if ($held_explicit_autoincrement_insert_reason !== null) {
                if (cow_merge_record_row_target_constraint(
                    $meta,
                    $run_id,
                    $table,
                    $key,
                    $base_row,
                    null,
                    $target_row,
                    'delete',
                    'source deleted row while the same table has a held explicit AUTOINCREMENT insert: ' . $held_explicit_autoincrement_insert_reason
                )) {
                    $conflicts++;
                }
                continue;
            }
            $where_identity = cow_merge_entry_where_identity($target_entry, $pk_cols);
            if ($where_identity === null) {
                throw new RuntimeException("cannot delete $table row without a target identity");
            }
            $delete_result = cow_merge_try_delete_row_with_source_deleted_children(
                $base,
                $source,
                $target,
                $meta,
                $run_id,
                $source_branch,
                $target_branch,
                $table,
                $where_identity,
                $pk_cols,
                $target_row
            );
            if (!($delete_result['ok'] ?? false)) {
                if (cow_merge_record_row_target_constraint(
                    $meta,
                    $run_id,
                    $table,
                    $key,
                    $base_row,
                    null,
                    $target_row,
                    'delete',
                    (string)($delete_result['error'] ?? 'SQLite constraint failed')
                )) {
                    $conflicts++;
                }
                continue;
            }
            if (!$pk_cols) {
                cow_merge_forget_row_identity($meta, $run_id, $target_branch, $table, (int)$where_identity['rowid']);
            }
            foreach (($delete_result['materialized_rows'] ?? []) as $materialized_row) {
                if (!is_array($materialized_row)) {
                    continue;
                }
                $materialized_table = (string)($materialized_row['table'] ?? '');
                $materialized_key = (string)($materialized_row['key'] ?? '');
                $materialized_payload = $materialized_row['row'] ?? null;
                if ($materialized_table === '' || $materialized_key === '' || !is_array($materialized_payload)) {
                    continue;
                }
                cow_merge_record_decision(
                    $meta,
                    $run_id,
                    $materialized_table,
                    $materialized_key,
                    null,
                    'source-applied',
                    'source inserted row before dependent foreign-key rewrite',
                    null,
                    $materialized_payload,
                    null,
                    $materialized_payload
                );
                if ($materialized_table === $table) {
                    $externally_applied[$materialized_key] = true;
                }
                $applied++;
            }
            cow_merge_record_decision($meta, $run_id, $table, $key, null, 'source-applied', 'source deleted row and target did not change it', $base_row, null, $target_row, null);
            $applied++;
            continue;
        }

        if ($target_row === null && $source_row !== null) {
            $active = cow_merge_record_conflict($meta, $run_id, $table, $key, null, 'row-target-deleted', $base_row, $source_row, null, null);
            cow_merge_record_decision($meta, $run_id, $table, $key, null, $active ? 'target-wins' : 'target-accepted', $active ? 'target deleted row while source changed it' : 'reviewed target resolution already accepts target-deleted row conflict', $base_row, $source_row, null, null);
            if ($active) {
                $conflicts++;
            }
            continue;
        }

        if ($base_row === null && $source_row !== null && $target_row !== null) {
            $active = cow_merge_record_conflict($meta, $run_id, $table, $key, null, 'row-insert-collision', null, $source_row, $target_row, $target_row);
            cow_merge_record_decision($meta, $run_id, $table, $key, null, $active ? 'target-wins' : 'target-accepted', $active ? 'source and target inserted different rows with the same identity' : 'reviewed target resolution already accepts same-identity insert collision', null, $source_row, $target_row, $target_row);
            if ($active) {
                $conflicts++;
            }
            continue;
        }

        if ($source_row === null && $target_row !== null) {
            $active = cow_merge_record_conflict($meta, $run_id, $table, $key, null, 'row-source-deleted', $base_row, null, $target_row, $target_row);
            cow_merge_record_decision($meta, $run_id, $table, $key, null, $active ? 'target-wins' : 'target-accepted', $active ? 'source deleted row while target changed it' : 'reviewed target resolution already accepts source-deleted row conflict', $base_row, null, $target_row, $target_row);
            if ($active) {
                $conflicts++;
            }
            continue;
        }

        if ($base_row !== null && $source_row !== null && $target_row !== null && cow_merge_row_values_equal($target_row, $base_row, $row_columns)) {
            $where_identity = cow_merge_entry_where_identity($target_entry, $pk_cols);
            if ($where_identity === null) {
                throw new RuntimeException("cannot update $table row without a target identity");
            }
            $wp_reference_violation = cow_merge_wordpress_update_reference_violation($source, $target, $meta, $source_branch, $table, $source_row);
            if ($wp_reference_violation !== null) {
                if (cow_merge_record_row_target_constraint(
                    $meta,
                    $run_id,
                    $table,
                    $key,
                    $base_row,
                    $source_row,
                    $target_row,
                    'update',
                    $wp_reference_violation
                )) {
                    $conflicts++;
                }
                continue;
            }
            $unique_collision = cow_merge_find_unique_collision(
                $target,
                $table,
                $source_row,
                !$pk_cols,
                $identity,
                $pk_cols,
                !$pk_cols ? (int)$where_identity['rowid'] : null
            );
            if ($unique_collision !== null) {
                $active = cow_merge_record_conflict(
                    $meta,
                    $run_id,
                    $table,
                    $key,
                    null,
                    'row-unique-collision',
                    $base_row,
                    $source_row,
                    $unique_collision['row'],
                    $unique_collision['row']
                );
                cow_merge_record_decision(
                    $meta,
                    $run_id,
                    $table,
                    $key,
                    null,
                    $active ? 'target-wins' : 'target-accepted',
                    ($active ? 'source changed row collides with target unique index ' : 'reviewed target resolution already accepts source row update collision with target unique index ') . $unique_collision['index'],
                    $base_row,
                    $source_row,
                    $unique_collision['row'],
                    $unique_collision['row']
                );
                if ($active) {
                    $conflicts++;
                }
                continue;
            }
            $update_result = cow_merge_try_update_row_preserving_payload($target, $table, $where_identity, $pk_cols, $source_row, $columns);
            if (!($update_result['ok'] ?? false)) {
                if (cow_merge_record_row_target_constraint(
                    $meta,
                    $run_id,
                    $table,
                    $key,
                    $base_row,
                    $source_row,
                    $target_row,
                    'update',
                    (string)($update_result['error'] ?? 'SQLite constraint failed')
                )) {
                    $conflicts++;
                }
                continue;
            }
            if (!$pk_cols) {
                cow_merge_remember_row_identity($meta, $run_id, $target_branch, $table, (int)$where_identity['rowid'], $identity, $source_row);
            }
            cow_merge_record_decision($meta, $run_id, $table, $key, null, 'source-applied', 'source changed row and target did not change it', $base_row, $source_row, $target_row, $source_row);
            $applied++;
            continue;
        }

        if ($base_row === null || $source_row === null || $target_row === null) {
            continue;
        }

        $wp_reference_violation = cow_merge_wordpress_update_reference_violation($source, $target, $meta, $source_branch, $table, $source_row);
        if ($wp_reference_violation !== null) {
            if (cow_merge_record_row_target_constraint(
                $meta,
                $run_id,
                $table,
                $key,
                $base_row,
                $source_row,
                $target_row,
                'update',
                $wp_reference_violation
            )) {
                $conflicts++;
            }
            continue;
        }

        $where_identity = cow_merge_entry_where_identity($target_entry, $pk_cols);
        if ($where_identity === null) {
            throw new RuntimeException("cannot inspect $table row without a target identity");
        }
        $unique_collision = cow_merge_find_unique_collision(
            $target,
            $table,
            $source_row,
            !$pk_cols,
            $identity,
            $pk_cols,
            !$pk_cols ? (int)$where_identity['rowid'] : null
        );
        if ($unique_collision !== null) {
            $active = cow_merge_record_conflict(
                $meta,
                $run_id,
                $table,
                $key,
                null,
                'row-unique-collision',
                $base_row,
                $source_row,
                $unique_collision['row'],
                $unique_collision['row']
            );
            cow_merge_record_decision(
                $meta,
                $run_id,
                $table,
                $key,
                null,
                $active ? 'target-wins' : 'target-accepted',
                ($active ? 'source changed row collides with target unique index ' : 'reviewed target resolution already accepts source row update collision with target unique index ') . $unique_collision['index'],
                $base_row,
                $source_row,
                $unique_collision['row'],
                $unique_collision['row']
            );
            if ($active) {
                $conflicts++;
            }
            continue;
        }

        $merged = $target_row;
        if (!$pk_cols && cow_merge_keyless_row_identity_ambiguous($base_row, $source_row, $target_row, $row_columns)) {
            $active = cow_merge_record_conflict($meta, $run_id, $table, $key, null, 'row-identity-ambiguous', $base_row, $source_row, $target_row, $target_row);
            cow_merge_record_decision(
                $meta,
                $run_id,
                $table,
                $key,
                null,
                $active ? 'target-wins' : 'target-accepted',
                $active
                    ? 'no-primary-key source row changed cells that target did not change while target also changed; rowid reuse cannot be ruled out without runtime identity events'
                    : 'reviewed target resolution already accepts no-primary-key row identity ambiguity',
                $base_row,
                $source_row,
                $target_row,
                $target_row
            );
            if ($active) {
                $conflicts++;
            }
            continue;
        }

        $row_conflicts = 0;
        $row_applied = 0;
        $pending_source_cell_decisions = [];
        foreach ($row_columns as $col) {
            if (in_array($col, $pk_cols, true)) {
                continue;
            }
            $b = $base_row[$col] ?? null;
            $s = $source_row[$col] ?? null;
            $t = $target_row[$col] ?? null;
            $source_changed = !cow_merge_values_equal($s, $b);
            $target_changed = !cow_merge_values_equal($t, $b);
            if (!$source_changed) {
                if ($target_changed) {
                    cow_merge_record_decision($meta, $run_id, $table, $key, $col, 'target-kept', 'target changed cell and source did not change it', $b, $s, $t, $t);
                }
                continue;
            }
            if (!$target_changed || cow_merge_values_equal($s, $t)) {
                $merged[$col] = $s;
                if (!$target_changed) {
                    $pending_source_cell_decisions[] = [$col, 'source changed cell and target did not change it', $b, $s, $t, $s];
                    $row_applied++;
                } else {
                    $pending_source_cell_decisions[] = [$col, 'source and target changed cell to the same value', $b, $s, $t, $t];
                    $row_applied++;
                }
                continue;
            }
            $active = cow_merge_record_conflict($meta, $run_id, $table, $key, $col, 'cell-conflict', $b, $s, $t, $t);
            cow_merge_record_decision($meta, $run_id, $table, $key, $col, $active ? 'target-wins' : 'target-accepted', $active ? 'source and target changed the same cell differently' : 'reviewed target resolution already accepts same-cell conflict', $b, $s, $t, $t);
            if ($active) {
                $row_conflicts++;
            }
        }

        if ($row_applied > 0) {
            $update_result = cow_merge_try_update_row_preserving_payload($target, $table, $where_identity, $pk_cols, $merged, $columns);
            if (!($update_result['ok'] ?? false)) {
                if (cow_merge_record_row_target_constraint(
                    $meta,
                    $run_id,
                    $table,
                    $key,
                    $base_row,
                    $source_row,
                    $target_row,
                    'update',
                    (string)($update_result['error'] ?? 'SQLite constraint failed')
                )) {
                    $conflicts++;
                }
                $conflicts += $row_conflicts;
                continue;
            }
            foreach ($pending_source_cell_decisions as $decision) {
                [$col, $reason, $base_value, $source_value, $target_value, $chosen_value] = $decision;
                cow_merge_record_decision($meta, $run_id, $table, $key, $col, 'source-applied', $reason, $base_value, $source_value, $target_value, $chosen_value);
            }
            if (!$pk_cols) {
                cow_merge_remember_row_identity($meta, $run_id, $target_branch, $table, (int)$where_identity['rowid'], $identity, $merged);
            }
            $applied += $row_applied;
        }
        $conflicts += $row_conflicts;
    }

    return ['applied' => $applied, 'conflicts' => $conflicts];
}

function cow_merge_foreign_key_parent_tables(SQLite3 $db, string $table): array {
    $parents = [];
    foreach (cow_merge_foreign_key_groups($db, $table) as $group) {
        $parent = (string)($group[0]['table'] ?? '');
        if ($parent !== '' && $parent !== $table) {
            $parents[] = $parent;
        }
    }
    return array_values(array_unique($parents));
}

function cow_merge_sort_tables_by_foreign_keys(array $tables, SQLite3 ...$dbs): array {
    $tables = array_values(array_unique($tables));
    sort($tables);
    $table_set = array_fill_keys($tables, true);
    $state = [];
    $ordered = [];
    $visit = function (string $table) use (&$visit, &$state, &$ordered, $table_set, $dbs): void {
        if (($state[$table] ?? null) === 'done') {
            return;
        }
        if (($state[$table] ?? null) === 'visiting') {
            return;
        }
        $state[$table] = 'visiting';
        $parents = [];
        foreach ($dbs as $db) {
            foreach (cow_merge_foreign_key_parent_tables($db, $table) as $parent) {
                if (isset($table_set[$parent])) {
                    $parents[] = $parent;
                }
            }
        }
        $parents = array_values(array_unique($parents));
        sort($parents);
        foreach ($parents as $parent) {
            $visit($parent);
        }
        $state[$table] = 'done';
        $ordered[] = $table;
    };
    foreach ($tables as $table) {
        $visit($table);
    }
    return $ordered;
}

function cow_merge_databases(
    string $base_db,
    string $source_db,
    string $target_db,
    string $metadata_db,
    string $source_branch,
    string $target_branch
): array {
    foreach ([$base_db, $source_db, $target_db] as $path) {
        if (!is_file($path)) {
            throw new RuntimeException("SQLite database does not exist: $path");
        }
    }
    cow_merge_mkdir_p(dirname($metadata_db));
    cow_merge_assert_no_pending_crash_recovery($metadata_db);

    $base = cow_merge_open_db($base_db, SQLITE3_OPEN_READONLY);
    $source = cow_merge_open_db($source_db, SQLITE3_OPEN_READONLY);
    $target = cow_merge_open_db($target_db, SQLITE3_OPEN_READWRITE);
    $meta = cow_merge_open_db($metadata_db, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    cow_merge_ensure_metadata($meta);
    $run_id = cow_merge_start_run($meta, $source_branch, $target_branch, $base_db, $source_db, $target_db);

    $applied = 0;
    $conflicts = 0;
    $target_transaction_active = false;
    $metadata_transaction_active = false;
    $target_committed = false;
    $target_snapshot = cow_merge_snapshot_sqlite_db($target_db);
    $preserve_target_snapshot = false;
    $crash_recovery_artifact = null;
    try {
        cow_merge_exec_checked($target, 'BEGIN IMMEDIATE', 'failed to start target database transaction');
        $target_transaction_active = true;
        cow_merge_exec_checked($meta, 'BEGIN IMMEDIATE', 'failed to start merge metadata transaction');
        $metadata_transaction_active = true;
        $base_tables = cow_merge_table_sql_map($base);
        $source_tables = cow_merge_table_sql_map($source);
        $target_tables = cow_merge_table_sql_map($target);
        $base_indexes = cow_merge_index_sql_map($base);
        $source_indexes = cow_merge_index_sql_map($source);
        $base_views = cow_merge_schema_object_sql_map($base, 'view');
        $source_views = cow_merge_schema_object_sql_map($source, 'view');
        $target_views = cow_merge_schema_object_sql_map($target, 'view');
        $base_triggers = cow_merge_schema_object_sql_map($base, 'trigger');
        $source_triggers = cow_merge_schema_object_sql_map($source, 'trigger');
        $target_triggers = cow_merge_schema_object_sql_map($target, 'trigger');
        $all_tables = array_unique(array_merge(array_keys($base_tables), array_keys($source_tables), array_keys($target_tables)));
        $all_tables = cow_merge_sort_tables_by_foreign_keys($all_tables, $target, $source, $base);

        foreach ($all_tables as $table) {
            $base_sql = $base_tables[$table] ?? null;
            $source_sql = $source_tables[$table] ?? null;
            $target_sql = $target_tables[$table] ?? null;

            if ($source_sql === $target_sql && $source_sql !== $base_sql) {
                cow_merge_record_matching_schema_decision(
                    $meta,
                    $run_id,
                    $table,
                    null,
                    'table',
                    $base_sql,
                    $source_sql,
                    $target_sql
                );
                $applied++;
                if ($source_sql === null) {
                    continue;
                }
            }
            if ($source_sql === null) {
                if ($base_sql !== null && $target_sql !== null) {
                    if (cow_merge_record_schema_conflict(
                        $meta,
                        $run_id,
                        $table,
                        null,
                        'schema-source-dropped-table',
                        $base_sql,
                        null,
                        $target_sql,
                        $target_sql,
                        'source dropped a table; automatic table drops are not applied'
                    )) {
                        $conflicts++;
                    }
                } elseif ($base_sql === null && $target_sql !== null) {
                    cow_merge_record_decision(
                        $meta,
                        $run_id,
                        $table,
                        null,
                        null,
                        'target-kept',
                        'target added table while source did not have it',
                        null,
                        null,
                        $target_sql,
                        $target_sql
                    );
                }
                continue;
            }
            if ($target_sql === null && $base_sql === null) {
                $result = cow_merge_apply_source_table($source, $target, $meta, $run_id, $source_branch, $target_branch, $table, $source_sql, $source_indexes);
                $applied += $result['applied'];
                $conflicts += $result['conflicts'];
                continue;
            }
            if ($target_sql === null) {
                if (cow_merge_record_schema_conflict(
                    $meta,
                    $run_id,
                    $table,
                    null,
                    'schema-target-dropped-table',
                    $base_sql,
                    cow_merge_source_table_restore_payload($source, $table, $source_sql),
                    null,
                    null,
                    'target dropped table while source kept or changed it'
                )) {
                    $conflicts++;
                }
                continue;
            }
            if ($source_sql !== $target_sql && $source_sql !== $base_sql) {
                $schema_result = cow_merge_apply_safe_table_schema_changes(
                    $base,
                    $source,
                    $target,
                    $meta,
                    $run_id,
                    $table,
                    $base_sql,
                    $source_sql,
                    $target_sql
                );
                $applied += $schema_result['applied'];
                $conflicts += $schema_result['conflicts'];
                if (!$schema_result['merged']) {
                    continue;
                }
            } elseif ($base_sql !== null && $source_sql === $base_sql && $target_sql !== $base_sql) {
                cow_merge_record_decision(
                    $meta,
                    $run_id,
                    $table,
                    null,
                    null,
                    'target-kept',
                    'target changed table schema while source did not change it',
                    $base_sql,
                    $source_sql,
                    $target_sql,
                    $target_sql
                );
            }

            $result = cow_merge_table_rows($base, $source, $target, $meta, $run_id, $source_branch, $target_branch, $table);
            $applied += $result['applied'];
            $conflicts += $result['conflicts'];
        }

        $target_indexes = cow_merge_index_sql_map($target);
        $index_result = cow_merge_apply_index_schema_changes($target, $meta, $run_id, $base_indexes, $source_indexes, $target_indexes);
        $applied += $index_result['applied'];
        $conflicts += $index_result['conflicts'];
        $view_result = cow_merge_apply_schema_object_changes($target, $meta, $run_id, 'view', $base_views, $source_views, $target_views);
        $applied += $view_result['applied'];
        $conflicts += $view_result['conflicts'];
        $trigger_result = cow_merge_apply_schema_object_changes($target, $meta, $run_id, 'trigger', $base_triggers, $source_triggers, $target_triggers);
        $applied += $trigger_result['applied'];
        $conflicts += $trigger_result['conflicts'];

        $status = $conflicts > 0 ? 'completed_with_conflicts' : 'completed';
        $crash_recovery_artifact = cow_merge_write_crash_recovery_artifact(
            $metadata_db,
            $run_id,
            'target-db-commit',
            [
                'source_branch' => $source_branch,
                'target_branch' => $target_branch,
                'base_db' => $base_db,
                'source_db' => $source_db,
                'target_db' => $target_db,
            ],
            $target_snapshot
        );
        cow_merge_failpoint('before-target-db-commit');
        cow_merge_exec_checked($target, 'COMMIT', 'failed to commit target database transaction');
        $target_transaction_active = false;
        $target_committed = true;
        cow_merge_failpoint('after-target-db-commit');
        cow_merge_finish_run($meta, $run_id, $status);
        cow_merge_failpoint('before-metadata-commit');
        cow_merge_exec_checked($meta, 'COMMIT', 'failed to commit merge metadata transaction');
        $metadata_transaction_active = false;
        cow_merge_remove_crash_recovery_artifact($crash_recovery_artifact);
        $crash_recovery_artifact = null;
        return [
            'run_id' => $run_id,
            'status' => $status,
            'applied' => $applied,
            'conflicts' => $conflicts,
            'metadata_db' => $metadata_db,
        ];
    } catch (Throwable $e) {
        if ($target_committed) {
            if ($metadata_transaction_active) {
                @$meta->exec('ROLLBACK');
                $metadata_transaction_active = false;
            }
            try {
                $target->close();
                $target = null;
                cow_merge_restore_sqlite_snapshot($target_snapshot);
                cow_merge_remove_crash_recovery_artifact($crash_recovery_artifact);
                $crash_recovery_artifact = null;
            } catch (Throwable $rollback_error) {
                $preserve_target_snapshot = true;
                $run_context = cow_merge_run_context($meta, $run_id);
                $original_failure = cow_merge_failure_reason($e);
                $rollback_failure = cow_merge_failure_reason($rollback_error);
                $rollback_artifacts = [
                    'target_db_snapshot' => cow_merge_sqlite_snapshot_artifact($target_snapshot),
                ];
                cow_merge_finish_run($meta, $run_id, 'failed', $original_failure);
                cow_merge_record_rollback_failure_artifact(
                    $metadata_db,
                    $run_id,
                    $run_context['source_branch'],
                    $run_context['target_branch'],
                    $run_context['base_db'],
                    $run_context['source_db'],
                    $run_context['target_db'],
                    $original_failure,
                    $rollback_failure,
                    $rollback_artifacts
                );
                throw new CowMergeRollbackFailureException(
                    $e->getMessage() . '; target database rollback failed: ' . $rollback_error->getMessage(),
                    $original_failure,
                    $rollback_failure,
                    $rollback_artifacts,
                    $e
                );
            }
        } elseif ($target_transaction_active) {
            @$target->exec('ROLLBACK');
        }
        if ($metadata_transaction_active) {
            @$meta->exec('ROLLBACK');
        }
        cow_merge_finish_run($meta, $run_id, 'failed', cow_merge_failure_reason($e));
        cow_merge_remove_crash_recovery_artifact($crash_recovery_artifact);
        $crash_recovery_artifact = null;
        throw $e;
    } finally {
        if (!$preserve_target_snapshot) {
            cow_merge_cleanup_sqlite_snapshot($target_snapshot);
        }
        $base->close();
        $source->close();
        if ($target instanceof SQLite3) {
            $target->close();
        }
        $meta->close();
    }
}

function cow_merge_set_run_status(string $metadata_db, int $run_id, string $status): void {
    $meta = cow_merge_open_db($metadata_db, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $transaction_started = false;
    try {
        cow_merge_ensure_metadata($meta);
        cow_merge_exec_checked($meta, 'BEGIN IMMEDIATE', 'failed to start run status metadata transaction');
        $transaction_started = true;
        cow_merge_finish_run($meta, $run_id, $status);
        cow_merge_exec_checked($meta, 'COMMIT', 'failed to commit run status metadata transaction');
        $transaction_started = false;
    } catch (Throwable $e) {
        if ($transaction_started) {
            @$meta->exec('ROLLBACK');
        }
        throw $e;
    } finally {
        $meta->close();
    }
}

function cow_merge_record_failed_run(
    string $metadata_db,
    string $source_branch,
    string $target_branch,
    string $base_db,
    string $source_db,
    string $target_db,
    ?string $failure_reason = null
): int {
    $meta = cow_merge_open_db($metadata_db, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $transaction_started = false;
    try {
        cow_merge_ensure_metadata($meta);
        cow_merge_exec_checked($meta, 'BEGIN IMMEDIATE', 'failed to start failed-run metadata transaction');
        $transaction_started = true;
        $run_id = cow_merge_start_run($meta, $source_branch, $target_branch, $base_db, $source_db, $target_db);
        cow_merge_finish_run($meta, $run_id, 'failed', $failure_reason);
        cow_merge_exec_checked($meta, 'COMMIT', 'failed to commit failed-run metadata transaction');
        $transaction_started = false;
        return $run_id;
    } catch (Throwable $e) {
        if ($transaction_started) {
            @$meta->exec('ROLLBACK');
        }
        throw $e;
    } finally {
        $meta->close();
    }
}

function cow_merge_filesystem_rollback_failure_parts(Throwable $e): ?array {
    if ($e instanceof CowMergeRollbackFailureException) {
        return [
            'original_failure' => $e->originalFailure,
            'rollback_failure' => $e->rollbackFailure,
            'rollback_artifacts' => $e->rollbackArtifacts,
        ];
    }

    $reason = cow_merge_failure_reason($e);
    $needle = '; filesystem rollback failed: ';
    $pos = strpos($reason, $needle);
    if ($pos === false) {
        return null;
    }

    $original_failure = substr($reason, 0, $pos);
    $rollback_failure = substr($reason, $pos + strlen($needle));
    if ($original_failure === '' || $rollback_failure === '') {
        return null;
    }

    return [
        'original_failure' => $original_failure,
        'rollback_failure' => $rollback_failure,
        'rollback_artifacts' => [],
    ];
}

function cow_merge_record_failed_run_with_recovered_rollback_failure(
    string $metadata_db,
    string $source_branch,
    string $target_branch,
    string $base_db,
    string $source_db,
    string $target_db,
    Throwable $failure
): int {
    $failure_reason = cow_merge_failure_reason($failure);
    $run_id = cow_merge_record_failed_run(
        $metadata_db,
        $source_branch,
        $target_branch,
        $base_db,
        $source_db,
        $target_db,
        $failure_reason
    );

    $filesystem_rollback_failure = cow_merge_filesystem_rollback_failure_parts($failure);
    if ($filesystem_rollback_failure !== null) {
        cow_merge_record_rollback_failure_artifact(
            $metadata_db,
            $run_id,
            $source_branch,
            $target_branch,
            $base_db,
            $source_db,
            $target_db,
            $filesystem_rollback_failure['original_failure'],
            $filesystem_rollback_failure['rollback_failure'],
            $filesystem_rollback_failure['rollback_artifacts']
        );
    }

    return $run_id;
}

function cow_merge_branch_state(
    string $base_db,
    string $source_db,
    string $target_db,
    string $metadata_db,
    string $source_branch,
    string $target_branch,
    ?string $base_files = null,
    ?string $source_root = null,
    ?string $target_root = null,
    array $plugin_validators = []
): array {
    $file_args = [$base_files, $source_root, $target_root];
    $has_file_args = array_filter($file_args, fn($value) => $value !== null && $value !== '');
    if ($has_file_args) {
        if (count($has_file_args) !== 3) {
            throw new InvalidArgumentException('--base-files, --source-root, and --target-root must be provided together');
        }
    }
    $plugin_validators = cow_merge_unique_plugin_validator_paths($plugin_validators);
    cow_merge_assert_no_pending_crash_recovery($metadata_db);

    $target_snapshot = null;
    $metadata_snapshot = null;
    $filesystem_snapshot = null;
    if ($has_file_args || count($plugin_validators) > 0) {
        $target_snapshot = cow_merge_snapshot_sqlite_db($target_db);
        $metadata_snapshot = cow_merge_snapshot_sqlite_db($metadata_db);
    }
    if ($has_file_args) {
        $filesystem_snapshot = cow_merge_file_root_snapshot_begin((string)$target_root);
    }

    $attempted_run_id = null;
    $preserve_rollback_snapshots = false;
    $whole_branch_crash_recovery_artifact = null;
    try {
        $result = cow_merge_databases($base_db, $source_db, $target_db, $metadata_db, $source_branch, $target_branch);
        $attempted_run_id = (int)$result['run_id'];
        $result['db_applied'] = $result['applied'];
        $result['db_conflicts'] = $result['conflicts'];
        $result['file_applied'] = 0;
        $result['file_conflicts'] = 0;
        $result['plugin_validators'] = 0;
        $result['plugin_validator_conflicts'] = 0;
        $result['plugin_validators_discovered'] = 0;

        if ($has_file_args) {
            $whole_branch_crash_recovery_artifact = cow_merge_write_crash_recovery_artifact(
                $metadata_db,
                (int)$result['run_id'],
                'before-file-op',
                [
                    'source_branch' => $source_branch,
                    'target_branch' => $target_branch,
                    'base_db' => $base_db,
                    'source_db' => $source_db,
                    'target_db' => $target_db,
                ],
                $target_snapshot,
                null,
                (string)$target_root,
                $metadata_snapshot,
                $filesystem_snapshot
            );
            cow_merge_failpoint('before-file-op');
            $file_result = cow_merge_files($base_files, $source_root, $target_root, $metadata_db, (int)$result['run_id']);
            $result['file_applied'] = $file_result['applied'];
            $result['file_conflicts'] = $file_result['conflicts'];
            $result['applied'] += $file_result['applied'];
            $result['conflicts'] += $file_result['conflicts'];
            $result['status'] = $result['conflicts'] > 0 ? 'completed_with_conflicts' : 'completed';
            cow_merge_set_run_status($metadata_db, (int)$result['run_id'], $result['status']);
            $discovered_plugin_validators = cow_merge_discover_plugin_validators($target_db, $target_root);
            $result['plugin_validators_discovered'] = count($discovered_plugin_validators);
            $plugin_validators = cow_merge_unique_plugin_validator_paths(array_merge(
                $plugin_validators,
                $discovered_plugin_validators
            ));
        }

        foreach ($plugin_validators as $validator) {
            $validator_result = cow_merge_run_plugin_validator($metadata_db, (int)$result['run_id'], $validator);
            $result['plugin_validators']++;
            $validator_conflicts = (int)($validator_result['conflicts'] ?? 0);
            $result['plugin_validator_conflicts'] += $validator_conflicts;
            if ($validator_conflicts > 0) {
                $result['conflicts'] += $validator_conflicts;
                $result['status'] = 'completed_with_conflicts';
            }
        }

        cow_merge_remove_crash_recovery_artifact($whole_branch_crash_recovery_artifact);
        $whole_branch_crash_recovery_artifact = null;
        return $result;
    } catch (Throwable $e) {
        if ($target_snapshot !== null && $metadata_snapshot !== null) {
            try {
                cow_merge_restore_sqlite_snapshot($target_snapshot);
                cow_merge_restore_sqlite_snapshot($metadata_snapshot);
                if ($filesystem_snapshot !== null) {
                    cow_merge_file_root_snapshot_restore($filesystem_snapshot, (string)$target_root);
                }
                cow_merge_record_failed_run_with_recovered_rollback_failure(
                    $metadata_db,
                    $source_branch,
                    $target_branch,
                    $base_db,
                    $source_db,
                    $target_db,
                    $e
                );
                cow_merge_remove_crash_recovery_artifact($whole_branch_crash_recovery_artifact);
                $whole_branch_crash_recovery_artifact = null;
            } catch (Throwable $rollback_error) {
                $preserve_rollback_snapshots = true;
                $failure_reason = cow_merge_failure_reason($e);
                $rollback_failure_reason = cow_merge_failure_reason($rollback_error);
                $failed_run_id = $attempted_run_id;
                try {
                    $failed_run_id = cow_merge_record_failed_run(
                        $metadata_db,
                        $source_branch,
                        $target_branch,
                        $base_db,
                        $source_db,
                        $target_db,
                        $failure_reason . '; whole-branch rollback failed: ' . $rollback_failure_reason
                    );
                } catch (Throwable) {
                    // The metadata snapshot may itself be unavailable after a
                    // rollback failure. The JSONL artifact below is still the
                    // durable recovery path in that case.
                }
                cow_merge_record_rollback_failure_artifact(
                    $metadata_db,
                    $failed_run_id,
                    $source_branch,
                    $target_branch,
                    $base_db,
                    $source_db,
                    $target_db,
                    $failure_reason,
                    $rollback_failure_reason,
                    [
                        'target_db_snapshot' => cow_merge_sqlite_snapshot_artifact($target_snapshot),
                        'metadata_db_snapshot' => cow_merge_sqlite_snapshot_artifact($metadata_snapshot),
                        'filesystem_snapshot' => cow_merge_file_root_snapshot_artifact($filesystem_snapshot, $target_root),
                    ]
                );
                throw new RuntimeException(
                    $e->getMessage() . '; whole-branch rollback failed: ' . $rollback_error->getMessage(),
                    0,
                    $e
                );
            }
        }
        throw $e;
    } finally {
        if (!$preserve_rollback_snapshots && $target_snapshot !== null) {
            cow_merge_cleanup_sqlite_snapshot($target_snapshot);
        }
        if (!$preserve_rollback_snapshots && $metadata_snapshot !== null) {
            cow_merge_cleanup_sqlite_snapshot($metadata_snapshot);
        }
        if (!$preserve_rollback_snapshots) {
            cow_merge_file_root_snapshot_cleanup($filesystem_snapshot);
        }
    }
}

function cow_merge_parse_cli(array $argv, array $required, int $start_index = 1): array {
    $args = [];
    for ($i = $start_index; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if ($arg === '--help' || $arg === '-h') {
            cow_merge_usage();
            exit(0);
        }
        if (!str_starts_with($arg, '--')) {
            throw new InvalidArgumentException("unexpected argument: $arg");
        }
        $key = substr($arg, 2);
        if (in_array($key, ['id-band-skips', 'target-kept', 'review', 'apply', 'after-revalidate', 'restore-target-db', 'restore-files'], true) && (!isset($argv[$i + 1]) || str_starts_with($argv[$i + 1], '--'))) {
            $args[$key] = '1';
            continue;
        }
        if (!isset($argv[$i + 1])) {
            throw new InvalidArgumentException("--$key requires a value");
        }
        $args[$key] = $argv[++$i];
    }
    foreach ($required as $name) {
        if (!isset($args[$name]) || $args[$name] === '') {
            throw new InvalidArgumentException("--$name is required");
        }
    }
    return $args;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    try {
        $command = $argv[1] ?? 'merge';
        if ($command === 'capture-files') {
            $args = cow_merge_parse_cli($argv, ['root', 'file-base'], 2);
            $result = cow_merge_capture_file_base($args['root'], $args['file-base']);
            if (($args['quiet'] ?? '0') !== '1') {
                echo "forkpress: captured filesystem merge base\n";
                echo "  status:    {$result['status']}\n";
                echo "  files:     {$result['files']}\n";
                echo "  file-base: {$result['file_base']}\n";
            }
            exit(0);
        }
        if ($command === 'capture-identities') {
            $args = cow_merge_parse_cli($argv, ['db', 'metadata-db', 'branch'], 2);
            $result = cow_merge_capture_row_identities(
                $args['db'],
                $args['metadata-db'],
                $args['branch'],
                $args['seed-branch'] ?? null
            );
            if (($args['quiet'] ?? '0') !== '1') {
                echo "forkpress: captured row identities for {$args['branch']}\n";
                echo "  run:       {$result['run_id']}\n";
                echo "  status:    {$result['status']}\n";
                echo "  tables:    {$result['tables']}\n";
                echo "  rows:      {$result['rows']}\n";
                echo "  created:   {$result['created']}\n";
                echo "  metadata:  {$result['metadata_db']}\n";
            }
            exit(0);
        }
        if ($command === 'track-identity-events') {
            $args = cow_merge_parse_cli($argv, ['db', 'metadata-db', 'branch', 'events-json'], 2);
            $events = json_decode($args['events-json'], true);
            if (!is_array($events)) {
                throw new InvalidArgumentException('--events-json must be a JSON array');
            }
            $result = cow_merge_track_row_identity_events(
                $args['db'],
                $args['metadata-db'],
                $args['branch'],
                $events
            );
            if (($args['quiet'] ?? '0') !== '1') {
                echo "forkpress: tracked runtime row identity events for {$args['branch']}\n";
                echo "  run:       {$result['run_id']}\n";
                echo "  status:    {$result['status']}\n";
                echo "  events:    {$result['events']}\n";
                echo "  tracked:   {$result['tracked']}\n";
                echo "  created:   {$result['created']}\n";
                echo "  deleted:   {$result['deleted']}\n";
                echo "  metadata:  {$result['metadata_db']}\n";
            }
            exit(0);
        }
        if ($command === 'allocate-id-bands') {
            $args = cow_merge_parse_cli($argv, ['db', 'metadata-db', 'branch'], 2);
            $result = cow_merge_allocate_autoincrement_bands(
                $args['db'],
                $args['metadata-db'],
                $args['branch']
            );
            if (($args['quiet'] ?? '0') !== '1') {
                echo "forkpress: allocated AUTOINCREMENT ID bands for {$args['branch']}\n";
                echo "  run:       {$result['run_id']}\n";
                echo "  status:    {$result['status']}\n";
                echo "  tables:    {$result['tables']}\n";
                echo "  allocated: {$result['allocated']}\n";
                echo "  reused:    {$result['reused']}\n";
                echo "  advanced:  {$result['advanced']}\n";
                echo "  metadata:  {$result['metadata_db']}\n";
            }
            exit(0);
        }
        if ($command === 'validate-branch-birth-metadata') {
            $args = cow_merge_parse_cli($argv, ['db', 'metadata-db', 'branch'], 2);
            $result = cow_merge_validate_branch_birth_metadata(
                $args['db'],
                $args['metadata-db'],
                $args['branch']
            );
            if (($args['quiet'] ?? '0') !== '1') {
                echo "forkpress: validated branch birth metadata for {$args['branch']}\n";
                echo "  status:               {$result['status']}\n";
                echo "  autoincrement tables: {$result['autoincrement_tables']}\n";
                echo "  keyless tables:       {$result['keyless_tables']}\n";
                echo "  keyless rows:         {$result['keyless_rows']}\n";
            }
            exit(0);
        }
        if ($command === 'cleanup-branch-birth-metadata') {
            $args = cow_merge_parse_cli($argv, ['metadata-db', 'branch'], 2);
            $result = cow_merge_cleanup_branch_birth_metadata($args['metadata-db'], $args['branch']);
            if (($args['quiet'] ?? '0') !== '1') {
                echo "forkpress: cleaned branch birth metadata for {$args['branch']}\n";
                echo "  cleaned:  {$result['cleaned']}\n";
                echo "  metadata: {$result['metadata_db']}\n";
            }
            exit(0);
        }
        if ($command === 'record-plugin-validator-conflicts') {
            $args = cow_merge_parse_cli($argv, ['metadata-db', 'run'], 2);
            $findings = cow_merge_json_array_arg($args, 'findings-json', 'findings-file');
            $result = cow_merge_record_plugin_validator_conflicts(
                $args['metadata-db'],
                (int)cow_merge_audit_run_id($args['run'] ?? null),
                $findings
            );
            $format = cow_merge_audit_format($args['format'] ?? null);
            if ($format === 'json') {
                $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if (!is_string($encoded)) {
                    throw new RuntimeException('failed to encode plugin validator result');
                }
                echo $encoded . "\n";
            } elseif (($args['quiet'] ?? '0') !== '1') {
                echo "forkpress: recorded plugin validator conflicts\n";
                echo "  run:       {$result['run_id']}\n";
                echo "  status:    {$result['status']}\n";
                echo "  conflicts: {$result['conflicts']}\n";
                echo "  metadata:  {$result['metadata_db']}\n";
            }
            exit(0);
        }
        if ($command === 'run-plugin-validator') {
            $args = cow_merge_parse_cli($argv, ['metadata-db', 'run', 'validator'], 2);
            $result = cow_merge_run_plugin_validator(
                $args['metadata-db'],
                (int)cow_merge_audit_run_id($args['run'] ?? null),
                $args['validator']
            );
            $format = cow_merge_audit_format($args['format'] ?? null);
            if ($format === 'json') {
                $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if (!is_string($encoded)) {
                    throw new RuntimeException('failed to encode plugin validator run result');
                }
                echo $encoded . "\n";
            } elseif (($args['quiet'] ?? '0') !== '1') {
                echo "forkpress: ran plugin validator\n";
                echo "  run:       {$result['run_id']}\n";
                echo "  status:    {$result['status']}\n";
                echo "  validator: {$result['validator_status']}\n";
                echo "  conflicts: {$result['conflicts']}\n";
                echo "  metadata:  {$result['metadata_db']}\n";
            }
            exit(0);
        }
        if ($command === 'recover-crash') {
            $args = cow_merge_parse_cli($argv, ['metadata-db'], 2);
            $format = cow_merge_audit_format($args['format'] ?? null);
            $result = cow_merge_recover_crash_artifacts(
                $args['metadata-db'],
                cow_merge_audit_run_id($args['run'] ?? null),
                cow_merge_bool_flag($args['restore-target-db'] ?? '0'),
                cow_merge_bool_flag($args['restore-files'] ?? '0')
            );
            if ($format === 'json') {
                $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if (!is_string($encoded)) {
                    throw new RuntimeException('failed to encode crash recovery result');
                }
                echo $encoded . "\n";
            } elseif (($args['quiet'] ?? '0') !== '1') {
                echo "forkpress: inspected COW merge crash recovery artifacts\n";
                echo "  pending:           {$result['pending']}\n";
                echo "  restored:          {$result['restored']}\n";
                echo "  restore-target-db: " . ($result['restore_target_db'] ? 'yes' : 'no') . "\n";
                echo "  restore-files:     " . ($result['restore_files'] ? 'yes' : 'no') . "\n";
                echo "  metadata:          {$result['metadata_db']}\n";
                foreach ($result['artifacts'] as $artifact) {
                    echo "  artifact:          {$artifact['artifact_path']}\n";
                    echo "    run:             {$artifact['run_id']}\n";
                    echo "    checkpoint:      {$artifact['checkpoint']}\n";
                    echo "    target-db:       {$artifact['target_db']}\n";
                    if (($artifact['target_root'] ?? '') !== '') {
                        echo "    target-root:     {$artifact['target_root']}\n";
                    }
                }
            }
            exit(0);
        }
        if ($command === 'audit') {
            $args = cow_merge_parse_cli($argv, ['metadata-db'], 2);
            $format = cow_merge_audit_format($args['format'] ?? null);
            $report = cow_merge_audit_report(
                $args['metadata-db'],
                cow_merge_audit_run_id($args['run'] ?? null),
                cow_merge_audit_limit($args['limit'] ?? null),
                [
                    'scope' => $args['scope'] ?? null,
                    'records' => $args['records'] ?? null,
                    'conflict_type' => $args['conflict-type'] ?? null,
                    'decision' => $args['decision'] ?? null,
                    'path' => $args['path'] ?? null,
                    'path_prefix' => $args['path-prefix'] ?? null,
                    'id_band_skips' => $args['id-band-skips'] ?? null,
                    'target_kept' => $args['target-kept'] ?? null,
                    'review' => $args['review'] ?? null,
                    'review_status' => $args['review-status'] ?? null,
                    'resolution_status' => $args['resolution-status'] ?? null,
                    'group_by' => $args['group-by'] ?? null,
                ]
            );
            if ($format === 'json') {
                $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if (!is_string($encoded)) {
                    throw new RuntimeException('failed to encode audit report');
                }
                echo $encoded . "\n";
            } else {
                cow_merge_print_audit_text($report);
            }
            exit(0);
        }
        if ($command === 'revalidate-reviews') {
            $args = cow_merge_parse_cli($argv, ['metadata-db'], 2);
            $format = cow_merge_audit_format($args['format'] ?? null);
            $result = cow_merge_revalidate_reviewed_conflicts(
                $args['metadata-db'],
                cow_merge_audit_run_id($args['run'] ?? null),
                cow_merge_review_text($args['reviewer'] ?? 'forkpress', 'reviewer')
            );
            if ($format === 'json') {
                $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if (!is_string($encoded)) {
                    throw new RuntimeException('failed to encode review revalidation result');
                }
                echo $encoded . "\n";
            } elseif (($args['quiet'] ?? '0') !== '1') {
                echo "forkpress: revalidated reviewed COW merge conflicts\n";
                echo "  checked:              {$result['checked']}\n";
                echo "  reviewed:             {$result['reviewed']}\n";
                echo "  fresh:                {$result['fresh']}\n";
                echo "  stale:                {$result['stale']}\n";
                echo "  errors:               {$result['errors']}\n";
                echo "  carried:              {$result['carried']}\n";
                echo "  already-needs-action: {$result['already_needs_action']}\n";
                echo "  metadata:             {$result['metadata_db']}\n";
            }
            exit(0);
        }
        if ($command === 'review-record') {
            $args = cow_merge_parse_cli($argv, ['metadata-db', 'record', 'id', 'status', 'note'], 2);
            $result = cow_merge_review_record(
                $args['metadata-db'],
                cow_merge_review_record_type($args['record'] ?? null),
                cow_merge_review_record_id($args['id'] ?? null),
                cow_merge_review_status($args['status'] ?? null),
                cow_merge_review_text($args['note'] ?? null, 'note'),
                cow_merge_review_text($args['reviewer'] ?? 'user', 'reviewer')
            );
            if (($args['quiet'] ?? '0') !== '1') {
                echo "forkpress: recorded COW merge review note\n";
                echo "  note:      {$result['review_note_id']}\n";
                echo "  record:    {$result['record_type']} #{$result['record_id']}\n";
                echo "  status:    {$result['status']}\n";
                echo "  reviewer:  {$result['reviewer']}\n";
                echo "  metadata:  {$result['metadata_db']}\n";
            }
            exit(0);
        }
        if ($command === 'resolve-conflict') {
            $args = cow_merge_parse_cli($argv, ['metadata-db', 'id', 'choice'], 2);
            $apply = cow_merge_bool_flag($args['apply'] ?? '0');
            $result = cow_merge_resolve_conflict(
                $args['metadata-db'],
                cow_merge_review_record_id($args['id'] ?? null),
                cow_merge_resolution_choice($args['choice'] ?? null),
                $apply,
                cow_merge_review_text($args['note'] ?? 'deterministic conflict resolution', 'note'),
                cow_merge_review_text($args['reviewer'] ?? 'user', 'reviewer'),
                cow_merge_bool_flag($args['after-revalidate'] ?? '0')
            );
            if (($args['quiet'] ?? '0') !== '1') {
                echo "forkpress: validated COW merge conflict resolution\n";
                echo "  conflict:  #{$result['conflict_id']}\n";
                if ($result['resolution_id'] !== null) {
                    echo "  resolution:#{$result['resolution_id']}\n";
                }
                echo "  choice:    {$result['choice']}\n";
                echo "  applied:   " . ($result['applied'] ? 'yes' : 'no') . "\n";
                echo "  object:    {$result['table_name']}.{$result['column_name']}\n";
                echo "  target:    {$result['target_db']}\n";
                echo "  metadata:  {$result['metadata_db']}\n";
            }
            exit(0);
        }

        $start_index = $command === 'merge' ? 2 : 1;
        $args = cow_merge_parse_cli($argv, ['base-db', 'source-db', 'target-db', 'metadata-db', 'source', 'target'], $start_index);
        $result = cow_merge_branch_state(
            $args['base-db'],
            $args['source-db'],
            $args['target-db'],
            $args['metadata-db'],
            $args['source'],
            $args['target'],
            $args['base-files'] ?? null,
            $args['source-root'] ?? null,
            $args['target-root'] ?? null,
            isset($args['plugin-validator']) ? [$args['plugin-validator']] : []
        );
        echo "forkpress: merged {$args['source']} into {$args['target']}\n";
        echo "  run:       {$result['run_id']}\n";
        echo "  status:    {$result['status']}\n";
        echo "  applied:   {$result['applied']}\n";
        echo "  conflicts: {$result['conflicts']}\n";
        if (isset($result['file_applied']) && ($result['file_applied'] > 0 || $result['file_conflicts'] > 0)) {
            echo "  files:     applied={$result['file_applied']} conflicts={$result['file_conflicts']}\n";
        }
        if (isset($result['plugin_validators']) && $result['plugin_validators'] > 0) {
            echo "  plugins:   validators={$result['plugin_validators']} conflicts={$result['plugin_validator_conflicts']}\n";
        }
        echo "  metadata:  {$result['metadata_db']}\n";
        exit(0);
    } catch (Throwable $e) {
        cow_merge_usage();
        fwrite(STDERR, "merge: " . $e->getMessage() . "\n");
        exit(1);
    }
}

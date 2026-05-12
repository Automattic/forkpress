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
    fwrite(STDERR, "    [--base-files <manifest> --source-root <path> --target-root <path>]\n");
    fwrite(STDERR, "  php merge.php capture-files --root <path> --file-base <path>\n");
    fwrite(STDERR, "  php merge.php capture-identities --db <path> --metadata-db <path> --branch <branch> [--seed-branch <branch>]\n");
    fwrite(STDERR, "  php merge.php track-identity-events --db <path> --metadata-db <path> --branch <branch> --events-json <json>\n");
    fwrite(STDERR, "  php merge.php allocate-id-bands --db <path> --metadata-db <path> --branch <branch>\n");
    fwrite(STDERR, "  php merge.php audit --metadata-db <path> [--format text|json] [--limit N] [--run ID]\n");
    fwrite(STDERR, "    [--scope all|db|files] [--records all|conflicts|decisions|resolutions] [--path <path>] [--path-prefix <prefix>]\n");
    fwrite(STDERR, "    [--scope all|db|files] [--records all|conflicts|decisions|resolutions] [--conflict-type TYPE] [--decision DECISION]\n");
    fwrite(STDERR, "    [--id-band-skips] [--review] [--review-status pending|needs-action|reviewed]\n");
    fwrite(STDERR, "    [--resolution-status validated|applied] [--group-by none|table|status|path]\n");
    fwrite(STDERR, "  php merge.php review-record --metadata-db <path> --record conflict|decision --id ID --status pending|needs-action|reviewed --note TEXT [--reviewer NAME]\n");
    fwrite(STDERR, "  php merge.php resolve-conflict --metadata-db <path> --id ID --choice source|target [--apply] [--note TEXT] [--reviewer NAME]\n");
}

const COW_MERGE_AUTOINCREMENT_BAND_SIZE = 1000000;
const COW_MERGE_AUTOINCREMENT_FIRST_BAND_START = 1000000;

function cow_merge_mkdir_p(string $path): void {
    if ($path === '' || is_dir($path)) {
        return;
    }
    if (!@mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException("failed to create $path");
    }
}

function cow_merge_open_db(string $path, int $flags): SQLite3 {
    $db = new SQLite3($path, $flags);
    $db->busyTimeout(5000);
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
        $children = scandir($dir);
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
    $res = $db->query(
        "SELECT name, sql FROM sqlite_master " .
        "WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
    );
    if (!$res) {
        return $tables;
    }
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $tables[(string)$row['name']] = $row['sql'];
    }
    return $tables;
}

function cow_merge_index_sql_map(SQLite3 $db): array {
    $indexes = [];
    $res = $db->query(
        "SELECT name, tbl_name, sql FROM sqlite_master " .
        "WHERE type = 'index' AND sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY name"
    );
    if (!$res) {
        return $indexes;
    }
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $indexes[(string)$row['name']] = [
            'table' => (string)$row['tbl_name'],
            'sql' => (string)$row['sql'],
        ];
    }
    return $indexes;
}

function cow_merge_table_sql(SQLite3 $db, string $table): ?string {
    $stmt = $db->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :name");
    if (!$stmt) {
        throw new RuntimeException("failed to prepare table schema lookup for $table: " . $db->lastErrorMsg());
    }
    cow_merge_bind($stmt, ':name', $table);
    $res = $stmt->execute();
    if (!$res) {
        throw new RuntimeException("failed to read table schema for $table: " . $db->lastErrorMsg());
    }
    $row = $res->fetchArray(SQLITE3_ASSOC);
    return $row ? (string)$row['sql'] : null;
}

function cow_merge_index_sql(SQLite3 $db, string $index): ?string {
    $stmt = $db->prepare("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = :name AND sql IS NOT NULL");
    if (!$stmt) {
        throw new RuntimeException("failed to prepare index schema lookup for $index: " . $db->lastErrorMsg());
    }
    cow_merge_bind($stmt, ':name', $index);
    $res = $stmt->execute();
    if (!$res) {
        throw new RuntimeException("failed to read index schema for $index: " . $db->lastErrorMsg());
    }
    $row = $res->fetchArray(SQLITE3_ASSOC);
    return $row ? (string)$row['sql'] : null;
}

function cow_merge_table_columns(SQLite3 $db, string $table): array {
    $columns = [];
    $res = $db->query('PRAGMA table_info(' . cow_merge_quote_ident($table) . ')');
    if (!$res) {
        return $columns;
    }
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $columns[] = (string)$row['name'];
    }
    return $columns;
}

function cow_merge_table_info(SQLite3 $db, string $table): array {
    $columns = [];
    $res = $db->query('PRAGMA table_info(' . cow_merge_quote_ident($table) . ')');
    if (!$res) {
        return $columns;
    }
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $columns[] = [
            'name' => (string)$row['name'],
            'type' => (string)$row['type'],
            'notnull' => (int)$row['notnull'],
            'dflt_value' => $row['dflt_value'],
            'pk' => (int)$row['pk'],
        ];
    }
    return $columns;
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
    $res = $db->query('PRAGMA table_info(' . cow_merge_quote_ident($table) . ')');
    if (!$res) {
        return [];
    }
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $ordinal = (int)$row['pk'];
        if ($ordinal > 0) {
            $pk[$ordinal] = (string)$row['name'];
        }
    }
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
    $sql = $pk_cols
        ? 'SELECT * FROM ' . cow_merge_quote_ident($table)
        : 'SELECT rowid AS __forkpress_merge_rowid, * FROM ' . cow_merge_quote_ident($table);
    $res = $db->query($sql);
    if (!$res) {
        return $rows;
    }
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $rowid = $row['__forkpress_merge_rowid'] ?? null;
        unset($row['__forkpress_merge_rowid']);
        $identity = cow_merge_row_identity($row, $pk_cols, $rowid);
        $rows[cow_merge_identity_json($identity)] = [
            'identity' => $identity,
            'rowid' => $rowid,
            'row' => $row,
        ];
    }
    return $rows;
}

function cow_merge_load_keyless_physical_rows(SQLite3 $db, string $table): array {
    $rows = [];
    $res = $db->query('SELECT rowid AS __forkpress_merge_rowid, * FROM ' . cow_merge_quote_ident($table));
    if (!$res) {
        return $rows;
    }
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $rowid = $row['__forkpress_merge_rowid'] ?? null;
        unset($row['__forkpress_merge_rowid']);
        if ($rowid === null) {
            continue;
        }
        $rows[(string)$rowid] = [
            'rowid' => (int)$rowid,
            'row' => $row,
        ];
    }
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
    $stmt = $meta->prepare(
        'SELECT logical_identity FROM merge_row_identities ' .
        'WHERE branch_name = :branch_name AND table_name = :table_name AND rowid = :rowid'
    );
    cow_merge_bind($stmt, ':branch_name', $branch);
    cow_merge_bind($stmt, ':table_name', $table);
    cow_merge_bind($stmt, ':rowid', $rowid);
    $res = $stmt->execute();
    if (!$res) {
        throw new RuntimeException('failed to look up row identity: ' . $meta->lastErrorMsg());
    }
    $row = $res->fetchArray(SQLITE3_ASSOC);
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
    $stmt = $meta->prepare(
        'SELECT logical_identity FROM merge_row_identity_history ' .
        'WHERE branch_name = :branch_name AND table_name = :table_name AND rowid = :rowid AND row_hash = :row_hash ' .
        'ORDER BY CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END, updated_at DESC, id DESC LIMIT 1'
    );
    cow_merge_bind($stmt, ':branch_name', $branch);
    cow_merge_bind($stmt, ':table_name', $table);
    cow_merge_bind($stmt, ':rowid', $rowid);
    cow_merge_bind($stmt, ':row_hash', $row_hash);
    $res = $stmt->execute();
    if (!$res) {
        throw new RuntimeException('failed to look up row identity history: ' . $meta->lastErrorMsg());
    }
    $row = $res->fetchArray(SQLITE3_ASSOC);
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
    $history = $meta->prepare(
        'INSERT INTO merge_row_identity_history ' .
        '(branch_name, table_name, rowid, logical_identity, row_hash, first_seen_run_id, last_seen_run_id) ' .
        'VALUES (:branch_name, :table_name, :rowid, :logical_identity, :row_hash, :first_seen_run_id, :last_seen_run_id) ' .
        'ON CONFLICT(branch_name, table_name, rowid, logical_identity) DO UPDATE SET ' . $on_conflict
    );
    cow_merge_bind($history, ':branch_name', $branch);
    cow_merge_bind($history, ':table_name', $table);
    cow_merge_bind($history, ':rowid', $rowid);
    cow_merge_bind($history, ':logical_identity', cow_merge_plain_json($identity));
    cow_merge_bind($history, ':row_hash', cow_merge_row_hash($row));
    cow_merge_bind($history, ':first_seen_run_id', $run_id);
    cow_merge_bind($history, ':last_seen_run_id', $run_id);
    if (!$history->execute()) {
        throw new RuntimeException('failed to remember row identity history: ' . $meta->lastErrorMsg());
    }
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
    $stmt = $meta->prepare(
        'INSERT INTO merge_row_identities ' .
        '(branch_name, table_name, rowid, logical_identity, row_hash, first_seen_run_id, last_seen_run_id) ' .
        'VALUES (:branch_name, :table_name, :rowid, :logical_identity, :row_hash, :first_seen_run_id, :last_seen_run_id) ' .
        'ON CONFLICT(branch_name, table_name, rowid) DO UPDATE SET ' .
        'row_hash = excluded.row_hash, last_seen_run_id = excluded.last_seen_run_id, updated_at = CURRENT_TIMESTAMP'
    );
    cow_merge_bind($stmt, ':branch_name', $branch);
    cow_merge_bind($stmt, ':table_name', $table);
    cow_merge_bind($stmt, ':rowid', $rowid);
    cow_merge_bind($stmt, ':logical_identity', cow_merge_plain_json($identity));
    cow_merge_bind($stmt, ':row_hash', cow_merge_row_hash($row));
    cow_merge_bind($stmt, ':first_seen_run_id', $run_id);
    cow_merge_bind($stmt, ':last_seen_run_id', $run_id);
    if (!$stmt->execute()) {
        throw new RuntimeException('failed to remember row identity: ' . $meta->lastErrorMsg());
    }

    cow_merge_remember_row_identity_history($meta, $run_id, $branch, $table, $rowid, $identity, $row);
}

function cow_merge_forget_row_identity(
    SQLite3 $meta,
    int $run_id,
    string $branch,
    string $table,
    int $rowid
): ?array {
    $stmt = $meta->prepare(
        'SELECT logical_identity FROM merge_row_identities ' .
        'WHERE branch_name = :branch_name AND table_name = :table_name AND rowid = :rowid'
    );
    cow_merge_bind($stmt, ':branch_name', $branch);
    cow_merge_bind($stmt, ':table_name', $table);
    cow_merge_bind($stmt, ':rowid', $rowid);
    $res = $stmt->execute();
    if (!$res) {
        throw new RuntimeException('failed to look up row identity for deletion: ' . $meta->lastErrorMsg());
    }
    $row = $res->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        return null;
    }

    $identity_json = (string)$row['logical_identity'];
    $identity = cow_merge_decode_row_identity($identity_json, "$branch.$table rowid $rowid deletion");

    $history = $meta->prepare(
        'UPDATE merge_row_identity_history ' .
        'SET deleted_at = CURRENT_TIMESTAMP, deleted_run_id = :deleted_run_id, last_seen_run_id = :last_seen_run_id, updated_at = CURRENT_TIMESTAMP ' .
        'WHERE branch_name = :branch_name AND table_name = :table_name AND rowid = :rowid AND logical_identity = :logical_identity'
    );
    cow_merge_bind($history, ':deleted_run_id', $run_id);
    cow_merge_bind($history, ':last_seen_run_id', $run_id);
    cow_merge_bind($history, ':branch_name', $branch);
    cow_merge_bind($history, ':table_name', $table);
    cow_merge_bind($history, ':rowid', $rowid);
    cow_merge_bind($history, ':logical_identity', $identity_json);
    if (!$history->execute()) {
        throw new RuntimeException('failed to mark row identity deleted: ' . $meta->lastErrorMsg());
    }

    $delete = $meta->prepare(
        'DELETE FROM merge_row_identities WHERE branch_name = :branch_name AND table_name = :table_name AND rowid = :rowid'
    );
    cow_merge_bind($delete, ':branch_name', $branch);
    cow_merge_bind($delete, ':table_name', $table);
    cow_merge_bind($delete, ':rowid', $rowid);
    if (!$delete->execute()) {
        throw new RuntimeException('failed to delete current row identity: ' . $meta->lastErrorMsg());
    }

    return $identity;
}

function cow_merge_load_keyless_physical_row(SQLite3 $db, string $table, int $rowid): ?array {
    $stmt = $db->prepare(
        'SELECT rowid AS __forkpress_merge_rowid, * FROM ' . cow_merge_quote_ident($table) . ' WHERE rowid = :rowid'
    );
    cow_merge_bind($stmt, ':rowid', $rowid);
    $res = $stmt->execute();
    if (!$res) {
        throw new RuntimeException("failed to load keyless row $table rowid $rowid: " . $db->lastErrorMsg());
    }
    $row = $res->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        return null;
    }
    $loaded_rowid = $row['__forkpress_merge_rowid'] ?? null;
    unset($row['__forkpress_merge_rowid']);
    if ($loaded_rowid === null) {
        return null;
    }
    return [
        'rowid' => (int)$loaded_rowid,
        'row' => $row,
    ];
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

function cow_merge_where_clause(array $identity, array $pk_cols, array &$values): string {
    $clauses = [];
    if ($pk_cols) {
        foreach ($pk_cols as $col) {
            $clauses[] = cow_merge_quote_ident($col) . ' = ?';
            $values[] = $identity[$col] ?? null;
        }
    } else {
        $clauses[] = 'rowid = ?';
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

function cow_merge_insert_row(SQLite3 $target, string $table, array $row, array $columns): int {
    $columns = array_values(array_filter($columns, fn($col) => array_key_exists($col, $row)));
    if (!$columns) {
        return 0;
    }
    $sql = 'INSERT INTO ' . cow_merge_quote_ident($table) . ' (' .
        implode(', ', array_map('cow_merge_quote_ident', $columns)) . ') VALUES (' .
        implode(', ', array_fill(0, count($columns), '?')) . ')';
    $stmt = $target->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException("failed to prepare insert into $table: " . $target->lastErrorMsg());
    }
    foreach ($columns as $i => $col) {
        cow_merge_bind($stmt, $i + 1, $row[$col] ?? null);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException("failed to insert into $table: " . $target->lastErrorMsg());
    }
    return (int)$target->lastInsertRowID();
}

function cow_merge_update_row(
    SQLite3 $target,
    string $table,
    array $identity,
    array $pk_cols,
    array $row,
    array $columns
): void {
    $set_cols = [];
    foreach ($columns as $col) {
        if (!array_key_exists($col, $row) || in_array($col, $pk_cols, true)) {
            continue;
        }
        $set_cols[] = $col;
    }
    if (!$set_cols) {
        return;
    }

    $where_values = [];
    $where = cow_merge_where_clause($identity, $pk_cols, $where_values);
    $sql = 'UPDATE ' . cow_merge_quote_ident($table) . ' SET ' .
        implode(', ', array_map(fn($col) => cow_merge_quote_ident($col) . ' = ?', $set_cols)) .
        ' WHERE ' . $where;
    $stmt = $target->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException("failed to prepare update on $table: " . $target->lastErrorMsg());
    }
    $index = 1;
    foreach ($set_cols as $col) {
        cow_merge_bind($stmt, $index++, $row[$col] ?? null);
    }
    foreach ($where_values as $value) {
        cow_merge_bind($stmt, $index++, $value);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException("failed to update $table: " . $target->lastErrorMsg());
    }
}

function cow_merge_delete_row(SQLite3 $target, string $table, array $identity, array $pk_cols): void {
    $values = [];
    $where = cow_merge_where_clause($identity, $pk_cols, $values);
    $stmt = $target->prepare('DELETE FROM ' . cow_merge_quote_ident($table) . ' WHERE ' . $where);
    if (!$stmt) {
        throw new RuntimeException("failed to prepare delete from $table: " . $target->lastErrorMsg());
    }
    foreach ($values as $i => $value) {
        cow_merge_bind($stmt, $i + 1, $value);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException("failed to delete from $table: " . $target->lastErrorMsg());
    }
}

function cow_merge_ensure_metadata(SQLite3 $meta): void {
    $meta->exec('PRAGMA journal_mode = WAL');
    $meta->exec(<<<'SQL'
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
SQL);
    cow_merge_ensure_metadata_column($meta, 'merge_runs', 'failure_reason', 'TEXT');
    $meta->exec(<<<'SQL'
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
SQL);
    $meta->exec(<<<'SQL'
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
SQL);
    $meta->exec(<<<'SQL'
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
SQL);
    $meta->exec(<<<'SQL'
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
SQL);
    $meta->exec(<<<'SQL'
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
SQL);
    $meta->exec(<<<'SQL'
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
SQL);
    $meta->exec(<<<'SQL'
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
SQL);
    $meta->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS merge_review_notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    record_type TEXT NOT NULL CHECK(record_type IN ('conflict', 'decision')),
    record_id INTEGER NOT NULL,
    status TEXT NOT NULL CHECK(status IN ('pending', 'needs-action', 'reviewed')),
    note TEXT NOT NULL,
    reviewer TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);
    $meta->exec('CREATE INDEX IF NOT EXISTS merge_review_notes_record_idx ON merge_review_notes(record_type, record_id, id)');
    $meta->exec(<<<'SQL'
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
SQL);
    $meta->exec('CREATE INDEX IF NOT EXISTS merge_resolutions_conflict_idx ON merge_resolutions(conflict_id, id)');
}

function cow_merge_ensure_metadata_column(SQLite3 $meta, string $table, string $column, string $definition): void {
    $res = $meta->query('PRAGMA table_info(' . cow_merge_quote_ident($table) . ')');
    if (!$res) {
        throw new RuntimeException("failed to inspect metadata table $table: " . $meta->lastErrorMsg());
    }
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        if ((string)$row['name'] === $column) {
            return;
        }
    }
    $sql = 'ALTER TABLE ' . cow_merge_quote_ident($table) . ' ADD COLUMN ' . cow_merge_quote_ident($column) . ' ' . $definition;
    if (!$meta->exec($sql)) {
        throw new RuntimeException("failed to migrate metadata table $table: " . $meta->lastErrorMsg());
    }
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
    string $rollback_failure
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
        $stmt = $meta->prepare(
            'INSERT INTO merge_rollback_failures ' .
            '(run_id, source_branch, target_branch, base_db, source_db, target_db, original_failure, rollback_failure, artifact_path) ' .
            'VALUES (:run_id, :source_branch, :target_branch, :base_db, :source_db, :target_db, :original_failure, :rollback_failure, :artifact_path)'
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
        if (!$stmt->execute()) {
            throw new RuntimeException('failed to record rollback failure: ' . $meta->lastErrorMsg());
        }
        $meta->close();
    } catch (Throwable $metadata_error) {
        if (isset($meta) && $meta instanceof SQLite3) {
            $meta->close();
        }
    }

    return $artifact_written ? $artifact_path : null;
}

function cow_merge_start_run(
    SQLite3 $meta,
    string $source_branch,
    string $target_branch,
    string $base_db,
    string $source_db,
    string $target_db
): int {
    $stmt = $meta->prepare(
        'INSERT INTO merge_runs (source_branch, target_branch, base_ref, status, policy, source_db, target_db, base_db) ' .
        'VALUES (:source_branch, :target_branch, :base_ref, :status, :policy, :source_db, :target_db, :base_db)'
    );
    cow_merge_bind($stmt, ':source_branch', $source_branch);
    cow_merge_bind($stmt, ':target_branch', $target_branch);
    cow_merge_bind($stmt, ':base_ref', basename($base_db));
    cow_merge_bind($stmt, ':status', 'running');
    cow_merge_bind($stmt, ':policy', 'target-wins');
    cow_merge_bind($stmt, ':source_db', $source_db);
    cow_merge_bind($stmt, ':target_db', $target_db);
    cow_merge_bind($stmt, ':base_db', $base_db);
    if (!$stmt->execute()) {
        throw new RuntimeException('failed to create merge run: ' . $meta->lastErrorMsg());
    }
    return (int)$meta->lastInsertRowID();
}

function cow_merge_start_identity_capture_run(
    SQLite3 $meta,
    string $branch,
    string $db
): int {
    $stmt = $meta->prepare(
        'INSERT INTO merge_runs (source_branch, target_branch, base_ref, status, policy, source_db, target_db, base_db) ' .
        'VALUES (:source_branch, :target_branch, :base_ref, :status, :policy, :source_db, :target_db, :base_db)'
    );
    cow_merge_bind($stmt, ':source_branch', $branch);
    cow_merge_bind($stmt, ':target_branch', $branch);
    cow_merge_bind($stmt, ':base_ref', 'identity-capture');
    cow_merge_bind($stmt, ':status', 'running');
    cow_merge_bind($stmt, ':policy', 'sidecar-row-identity-capture');
    cow_merge_bind($stmt, ':source_db', $db);
    cow_merge_bind($stmt, ':target_db', $db);
    cow_merge_bind($stmt, ':base_db', $db);
    if (!$stmt->execute()) {
        throw new RuntimeException('failed to create row identity capture run: ' . $meta->lastErrorMsg());
    }
    return (int)$meta->lastInsertRowID();
}

function cow_merge_start_id_band_run(
    SQLite3 $meta,
    string $branch,
    string $db
): int {
    $stmt = $meta->prepare(
        'INSERT INTO merge_runs (source_branch, target_branch, base_ref, status, policy, source_db, target_db, base_db) ' .
        'VALUES (:source_branch, :target_branch, :base_ref, :status, :policy, :source_db, :target_db, :base_db)'
    );
    cow_merge_bind($stmt, ':source_branch', $branch);
    cow_merge_bind($stmt, ':target_branch', $branch);
    cow_merge_bind($stmt, ':base_ref', 'autoincrement-id-band');
    cow_merge_bind($stmt, ':status', 'running');
    cow_merge_bind($stmt, ':policy', 'autoincrement-id-band-allocation');
    cow_merge_bind($stmt, ':source_db', $db);
    cow_merge_bind($stmt, ':target_db', $db);
    cow_merge_bind($stmt, ':base_db', $db);
    if (!$stmt->execute()) {
        throw new RuntimeException('failed to create AUTOINCREMENT band allocation run: ' . $meta->lastErrorMsg());
    }
    return (int)$meta->lastInsertRowID();
}

function cow_merge_start_runtime_identity_run(
    SQLite3 $meta,
    string $branch,
    string $db
): int {
    $stmt = $meta->prepare(
        'INSERT INTO merge_runs (source_branch, target_branch, base_ref, status, policy, source_db, target_db, base_db) ' .
        'VALUES (:source_branch, :target_branch, :base_ref, :status, :policy, :source_db, :target_db, :base_db)'
    );
    cow_merge_bind($stmt, ':source_branch', $branch);
    cow_merge_bind($stmt, ':target_branch', $branch);
    cow_merge_bind($stmt, ':base_ref', 'runtime-row-identity-events');
    cow_merge_bind($stmt, ':status', 'running');
    cow_merge_bind($stmt, ':policy', 'runtime-row-identity-tracking');
    cow_merge_bind($stmt, ':source_db', $db);
    cow_merge_bind($stmt, ':target_db', $db);
    cow_merge_bind($stmt, ':base_db', $db);
    if (!$stmt->execute()) {
        throw new RuntimeException('failed to create runtime row identity tracking run: ' . $meta->lastErrorMsg());
    }
    return (int)$meta->lastInsertRowID();
}

function cow_merge_finish_run(SQLite3 $meta, int $run_id, string $status, ?string $failure_reason = null): void {
    if ($status !== 'failed') {
        $failure_reason = null;
    }
    $stmt = $meta->prepare(
        'UPDATE merge_runs SET status = :status, finished_at = CURRENT_TIMESTAMP, failure_reason = :failure_reason WHERE id = :id'
    );
    cow_merge_bind($stmt, ':status', $status);
    cow_merge_bind($stmt, ':failure_reason', $failure_reason);
    cow_merge_bind($stmt, ':id', $run_id);
    if (!$stmt->execute()) {
        throw new RuntimeException('failed to finish merge run: ' . $meta->lastErrorMsg());
    }
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

    $tables = 0;
    $rows = 0;
    $created = 0;
    try {
        $meta->exec('BEGIN IMMEDIATE');
        foreach (cow_merge_keyless_tables($db) as $table) {
            $tables++;
            foreach (cow_merge_load_keyless_physical_rows($db, $table) as $entry) {
                $rowid = (int)$entry['rowid'];
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
        }
        $meta->exec('COMMIT');
        cow_merge_finish_run($meta, $run_id, 'identity_captured');
        return [
            'run_id' => $run_id,
            'status' => 'identity_captured',
            'tables' => $tables,
            'rows' => $rows,
            'created' => $created,
            'metadata_db' => $metadata_db,
        ];
    } catch (Throwable $e) {
        $meta->exec('ROLLBACK');
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

    $tracked = 0;
    $created = 0;
    $deleted = 0;
    try {
        $keyless_tables = array_fill_keys(cow_merge_keyless_tables($db), true);
        $meta->exec('BEGIN IMMEDIATE');
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

        $meta->exec('COMMIT');
        cow_merge_finish_run($meta, $run_id, 'identity_tracked');
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
        $meta->exec('ROLLBACK');
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
    return (int)$db->querySingle('SELECT COALESCE(MAX(rowid), 0) FROM ' . cow_merge_quote_ident($table));
}

function cow_merge_sqlite_sequence_value(SQLite3 $db, string $table): int {
    $stmt = $db->prepare('SELECT seq FROM sqlite_sequence WHERE name = :name');
    cow_merge_bind($stmt, ':name', $table);
    $res = $stmt->execute();
    if (!$res) {
        throw new RuntimeException('failed to read sqlite_sequence: ' . $db->lastErrorMsg());
    }
    $row = $res->fetchArray(SQLITE3_ASSOC);
    return $row ? (int)$row['seq'] : 0;
}

function cow_merge_set_sqlite_sequence(SQLite3 $db, string $table, int $seq): void {
    $stmt = $db->prepare('UPDATE sqlite_sequence SET seq = :seq WHERE name = :name');
    cow_merge_bind($stmt, ':seq', $seq);
    cow_merge_bind($stmt, ':name', $table);
    if (!$stmt->execute()) {
        throw new RuntimeException('failed to update sqlite_sequence: ' . $db->lastErrorMsg());
    }
    if ($db->changes() > 0) {
        return;
    }

    $stmt = $db->prepare('INSERT INTO sqlite_sequence (name, seq) VALUES (:name, :seq)');
    cow_merge_bind($stmt, ':name', $table);
    cow_merge_bind($stmt, ':seq', $seq);
    if (!$stmt->execute()) {
        throw new RuntimeException('failed to insert sqlite_sequence row: ' . $db->lastErrorMsg());
    }
}

function cow_merge_lookup_autoincrement_band(SQLite3 $meta, string $branch, string $table): ?array {
    $stmt = $meta->prepare(
        'SELECT band_start, band_end, band_size FROM merge_autoincrement_bands ' .
        'WHERE branch_name = :branch_name AND table_name = :table_name'
    );
    cow_merge_bind($stmt, ':branch_name', $branch);
    cow_merge_bind($stmt, ':table_name', $table);
    $res = $stmt->execute();
    if (!$res) {
        throw new RuntimeException('failed to look up AUTOINCREMENT band: ' . $meta->lastErrorMsg());
    }
    $row = $res->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'band_start' => (int)$row['band_start'],
        'band_end' => (int)$row['band_end'],
        'band_size' => (int)$row['band_size'],
    ];
}

function cow_merge_round_up_to_band(int $value, int $band_size): int {
    $remainder = $value % $band_size;
    if ($remainder === 0) {
        return $value;
    }
    return $value + ($band_size - $remainder);
}

function cow_merge_next_autoincrement_band_start(SQLite3 $meta, string $table, int $min_start, int $band_size): int {
    $stmt = $meta->prepare('SELECT MAX(band_end) AS max_band_end FROM merge_autoincrement_bands WHERE table_name = :table_name');
    cow_merge_bind($stmt, ':table_name', $table);
    $res = $stmt->execute();
    if (!$res) {
        throw new RuntimeException('failed to choose AUTOINCREMENT band: ' . $meta->lastErrorMsg());
    }
    $row = $res->fetchArray(SQLITE3_ASSOC);
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
        $stmt = $meta->prepare(
            'INSERT INTO merge_autoincrement_bands ' .
            '(branch_name, table_name, band_start, band_end, band_size, allocated_run_id, last_seen_run_id) ' .
            'VALUES (:branch_name, :table_name, :band_start, :band_end, :band_size, :allocated_run_id, :last_seen_run_id) ' .
            'ON CONFLICT(branch_name, table_name) DO UPDATE SET ' .
            'band_start = excluded.band_start, band_end = excluded.band_end, band_size = excluded.band_size, ' .
            'allocated_run_id = excluded.allocated_run_id, last_seen_run_id = excluded.last_seen_run_id, updated_at = CURRENT_TIMESTAMP'
        );
        cow_merge_bind($stmt, ':allocated_run_id', $run_id);
    } else {
        $stmt = $meta->prepare(
            'UPDATE merge_autoincrement_bands SET last_seen_run_id = :last_seen_run_id, updated_at = CURRENT_TIMESTAMP ' .
            'WHERE branch_name = :branch_name AND table_name = :table_name'
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
    if (!$stmt->execute()) {
        throw new RuntimeException('failed to remember AUTOINCREMENT band: ' . $meta->lastErrorMsg());
    }
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
    try {
        $db->exec('BEGIN IMMEDIATE');
        $meta->exec('BEGIN IMMEDIATE');
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
        $meta->exec('COMMIT');
        $db->exec('COMMIT');
        cow_merge_finish_run($meta, $run_id, 'id_bands_allocated');
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
        $meta->exec('ROLLBACK');
        $db->exec('ROLLBACK');
        cow_merge_finish_run($meta, $run_id, 'failed', cow_merge_failure_reason($e));
        throw $e;
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
    $stmt = $meta->prepare(
        'INSERT INTO merge_decisions ' .
        '(run_id, table_name, row_identity, column_name, decision, reason, base_payload, source_payload, target_payload, chosen_payload) ' .
        'VALUES (:run_id, :table_name, :row_identity, :column_name, :decision, :reason, :base_payload, :source_payload, :target_payload, :chosen_payload)'
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
    if (!$stmt->execute()) {
        throw new RuntimeException('failed to record merge decision: ' . $meta->lastErrorMsg());
    }
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
): void {
    $base_payload = cow_merge_payload_json($base);
    $source_payload = cow_merge_payload_json($source);
    $target_payload = cow_merge_payload_json($target);
    $chosen_payload = cow_merge_payload_json($chosen);
    $stmt = $meta->prepare(
        'INSERT OR IGNORE INTO merge_conflicts ' .
        '(run_id, table_name, row_identity, column_name, conflict_type, base_payload, source_payload, target_payload, chosen_payload, ' .
        'base_hash, source_hash, target_hash, chosen_hash, resolver, resolved_at) ' .
        'VALUES (:run_id, :table_name, :row_identity, :column_name, :conflict_type, :base_payload, :source_payload, :target_payload, :chosen_payload, ' .
        ':base_hash, :source_hash, :target_hash, :chosen_hash, :resolver, CURRENT_TIMESTAMP)'
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
    cow_merge_bind($stmt, ':base_hash', hash('sha256', $base_payload));
    cow_merge_bind($stmt, ':source_hash', hash('sha256', $source_payload));
    cow_merge_bind($stmt, ':target_hash', hash('sha256', $target_payload));
    cow_merge_bind($stmt, ':chosen_hash', hash('sha256', $chosen_payload));
    cow_merge_bind($stmt, ':resolver', 'target-wins');
    if (!$stmt->execute()) {
        throw new RuntimeException('failed to record merge conflict: ' . $meta->lastErrorMsg());
    }
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

function cow_merge_file_unsupported_source_conflict(string $path, ?array $source): array {
    if (($source['type'] ?? null) === 'symlink') {
        $reason = cow_merge_symlink_safety_reason($path, $source);
        if ($reason !== null) {
            return [
                'file-unsafe-symlink',
                'source changed a filesystem symlink whose target cannot be safely applied automatically: ' . $reason,
            ];
        }
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
    if ($source !== null && !cow_merge_file_entry_auto_applicable($path, null, $source, $target)) {
        [$type, $reason] = cow_merge_file_unsupported_source_conflict($path, $source);
        throw new RuntimeException("cannot apply source filesystem conflict $path ($type): $reason");
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
): void {
    $identity = cow_merge_file_identity_json($path);
    cow_merge_record_conflict(
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
        'target-wins',
        $reason,
        cow_merge_file_path_payload($path, $base),
        cow_merge_file_path_payload($path, $source),
        cow_merge_file_path_payload($path, $target),
        cow_merge_file_path_payload($path, $chosen)
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
    $conflicts = 0;

    try {
        $meta->exec('BEGIN IMMEDIATE');
        foreach ($paths as $path) {
            $base = $base_entries[$path] ?? null;
            $source = $source_entries[$path] ?? null;
            $target = $target_entries[$path] ?? null;

            if (cow_merge_file_entries_equal($source, $base) || cow_merge_file_entries_equal($source, $target)) {
                continue;
            }

            if (cow_merge_file_entries_equal($target, $base)) {
                if (!cow_merge_file_entry_auto_applicable($path, $base, $source, $target)) {
                    [$conflict_type, $conflict_reason] = cow_merge_file_unsupported_source_conflict($path, $source);
                    cow_merge_record_file_conflict(
                        $meta,
                        $run_id,
                        $path,
                        $conflict_type,
                        $base,
                        $source,
                        $target,
                        $target,
                        $conflict_reason
                    );
                    $conflicts++;
                    continue;
                }
                if (
                    $source === null
                    && ($base['type'] ?? null) === 'dir'
                    && !cow_merge_file_deleted_dir_is_safe($path, $base_entries, $source_entries, $target_entries)
                ) {
                    cow_merge_record_file_conflict(
                        $meta,
                        $run_id,
                        $path,
                        'file-directory-delete-conflict',
                        $base,
                        $source,
                        $target,
                        $target,
                        'source deleted a filesystem directory that has target-side descendants'
                    );
                    $conflicts++;
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
            cow_merge_record_file_conflict($meta, $run_id, $path, $type, $base, $source, $target, $target, $reason);
            $conflicts++;
        }
        $meta->exec('COMMIT');
    } catch (Throwable $e) {
        $meta->exec('ROLLBACK');
        $meta->close();
        throw $e;
    }
    $meta->close();

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

    $applied = 0;
    $meta = cow_merge_open_db($metadata_db, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    cow_merge_ensure_metadata($meta);
    $file_tx = cow_merge_file_transaction_begin();
    $file_tx_committed = false;
    try {
        if (!$meta->exec('BEGIN IMMEDIATE')) {
            throw new RuntimeException('failed to start filesystem merge metadata transaction: ' . $meta->lastErrorMsg());
        }
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
        if (!$meta->exec('COMMIT')) {
            throw new RuntimeException('failed to commit filesystem merge metadata transaction: ' . $meta->lastErrorMsg());
        }
        $file_tx_committed = true;
    } catch (Throwable $e) {
        @$meta->exec('ROLLBACK');
        if (!$file_tx_committed) {
            try {
                cow_merge_file_transaction_restore($file_tx, $target_root);
            } catch (Throwable $rollback_error) {
                throw new RuntimeException(
                    $e->getMessage() . '; filesystem rollback failed: ' . $rollback_error->getMessage(),
                    0,
                    $e
                );
            }
        }
        throw $e;
    } finally {
        cow_merge_file_transaction_cleanup($file_tx);
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
    if (!in_array($scope, ['all', 'db', 'files'], true)) {
        throw new InvalidArgumentException('--scope must be all, db, or files');
    }
    return $scope;
}

function cow_merge_audit_records(?string $value): string {
    $records = $value ?? 'all';
    if (!in_array($records, ['all', 'conflicts', 'decisions', 'resolutions'], true)) {
        throw new InvalidArgumentException('--records must be all, conflicts, decisions, or resolutions');
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
    return cow_merge_review_status($value);
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
    if (!in_array($group_by, ['none', 'table', 'status', 'path'], true)) {
        throw new InvalidArgumentException('--group-by must be none, table, status, or path');
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
    if ($value !== 'conflict' && $value !== 'decision') {
        throw new InvalidArgumentException('--record must be conflict or decision');
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
    try {
        cow_merge_ensure_metadata($meta);
        $table = $record_type === 'conflict' ? 'merge_conflicts' : 'merge_decisions';
        $stmt = $meta->prepare("SELECT id FROM $table WHERE id = :id");
        if (!$stmt) {
            throw new RuntimeException("failed to prepare $record_type lookup: " . $meta->lastErrorMsg());
        }
        cow_merge_bind($stmt, ':id', $record_id);
        $res = $stmt->execute();
        if (!$res || !$res->fetchArray(SQLITE3_ASSOC)) {
            throw new InvalidArgumentException("$record_type #$record_id does not exist in merge metadata");
        }

        $review_note_id = cow_merge_insert_review_note($meta, $record_type, $record_id, $status, $note, $reviewer);
        return [
            'metadata_db' => $metadata_db,
            'record_type' => $record_type,
            'record_id' => $record_id,
            'status' => $status,
            'note' => $note,
            'reviewer' => $reviewer,
            'review_note_id' => $review_note_id,
        ];
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
    $stmt = $meta->prepare(
        'INSERT INTO merge_review_notes (record_type, record_id, status, note, reviewer) ' .
        'VALUES (:record_type, :record_id, :status, :note, :reviewer)'
    );
    if (!$stmt) {
        throw new RuntimeException('failed to prepare review note insert: ' . $meta->lastErrorMsg());
    }
    cow_merge_bind($stmt, ':record_type', $record_type);
    cow_merge_bind($stmt, ':record_id', $record_id);
    cow_merge_bind($stmt, ':status', $status);
    cow_merge_bind($stmt, ':note', $note);
    cow_merge_bind($stmt, ':reviewer', $reviewer);
    if (!$stmt->execute()) {
        throw new RuntimeException('failed to record review note: ' . $meta->lastErrorMsg());
    }
    return (int)$meta->lastInsertRowID();
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

function cow_merge_decode_payload_json(string $json, string $context): mixed {
    $decoded = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("invalid $context payload: " . json_last_error_msg());
    }
    return cow_merge_audit_decode_payload($decoded);
}

function cow_merge_select_current_cell(SQLite3 $db, string $table, array $identity, array $pk_cols, string $column): mixed {
    $where_values = [];
    $where = cow_merge_where_clause($identity, $pk_cols, $where_values);
    $stmt = $db->prepare('SELECT ' . cow_merge_quote_ident($column) . ' AS value FROM ' . cow_merge_quote_ident($table) . ' WHERE ' . $where);
    if (!$stmt) {
        throw new RuntimeException("failed to prepare current cell lookup for $table.$column: " . $db->lastErrorMsg());
    }
    foreach ($where_values as $i => $value) {
        cow_merge_bind($stmt, $i + 1, $value);
    }
    $res = $stmt->execute();
    if (!$res) {
        throw new RuntimeException("failed to read current cell for $table.$column: " . $db->lastErrorMsg());
    }
    $row = $res->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        throw new RuntimeException("cannot resolve $table.$column conflict because the target row no longer exists");
    }
    return $row['value'] ?? null;
}

function cow_merge_select_current_row(SQLite3 $db, string $table, array $identity, array $pk_cols): ?array {
    $where_values = [];
    $where = cow_merge_where_clause($identity, $pk_cols, $where_values);
    $stmt = $db->prepare('SELECT * FROM ' . cow_merge_quote_ident($table) . ' WHERE ' . $where);
    if (!$stmt) {
        throw new RuntimeException("failed to prepare current row lookup for $table: " . $db->lastErrorMsg());
    }
    foreach ($where_values as $i => $value) {
        cow_merge_bind($stmt, $i + 1, $value);
    }
    $res = $stmt->execute();
    if (!$res) {
        throw new RuntimeException("failed to read current row for $table: " . $db->lastErrorMsg());
    }
    $row = $res->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

function cow_merge_lookup_active_rowid_by_identity(SQLite3 $meta, string $branch, string $table, array $identity): ?int {
    $stmt = $meta->prepare(
        'SELECT rowid FROM merge_row_identities ' .
        'WHERE branch_name = :branch_name AND table_name = :table_name AND logical_identity = :logical_identity'
    );
    if (!$stmt) {
        throw new RuntimeException('failed to prepare row identity lookup: ' . $meta->lastErrorMsg());
    }
    cow_merge_bind($stmt, ':branch_name', $branch);
    cow_merge_bind($stmt, ':table_name', $table);
    cow_merge_bind($stmt, ':logical_identity', cow_merge_plain_json($identity));
    $res = $stmt->execute();
    if (!$res) {
        throw new RuntimeException('failed to look up active row identity: ' . $meta->lastErrorMsg());
    }
    $row = $res->fetchArray(SQLITE3_ASSOC);
    if ($row) {
        return (int)$row['rowid'];
    }

    $scan = $meta->prepare(
        'SELECT rowid, logical_identity FROM merge_row_identities ' .
        'WHERE branch_name = :branch_name AND table_name = :table_name'
    );
    if (!$scan) {
        throw new RuntimeException('failed to prepare row identity scan: ' . $meta->lastErrorMsg());
    }
    cow_merge_bind($scan, ':branch_name', $branch);
    cow_merge_bind($scan, ':table_name', $table);
    $res = $scan->execute();
    if (!$res) {
        throw new RuntimeException('failed to scan active row identities: ' . $meta->lastErrorMsg());
    }
    while ($candidate = $res->fetchArray(SQLITE3_ASSOC)) {
        $candidate_identity = json_decode((string)$candidate['logical_identity'], true);
        if (is_array($candidate_identity) && cow_merge_values_equal($candidate_identity, $identity)) {
            return (int)$candidate['rowid'];
        }
    }

    $history = $meta->prepare(
        'SELECT rowid FROM merge_row_identity_history ' .
        'WHERE branch_name = :branch_name AND table_name = :table_name AND logical_identity = :logical_identity AND deleted_at IS NULL ' .
        'ORDER BY updated_at DESC, id DESC LIMIT 1'
    );
    if (!$history) {
        throw new RuntimeException('failed to prepare row identity history lookup: ' . $meta->lastErrorMsg());
    }
    cow_merge_bind($history, ':branch_name', $branch);
    cow_merge_bind($history, ':table_name', $table);
    cow_merge_bind($history, ':logical_identity', cow_merge_plain_json($identity));
    $res = $history->execute();
    if (!$res) {
        throw new RuntimeException('failed to look up row identity history: ' . $meta->lastErrorMsg());
    }
    $row = $res->fetchArray(SQLITE3_ASSOC);
    if ($row) {
        return (int)$row['rowid'];
    }

    $history_scan = $meta->prepare(
        'SELECT rowid, logical_identity FROM merge_row_identity_history ' .
        'WHERE branch_name = :branch_name AND table_name = :table_name AND deleted_at IS NULL ' .
        'ORDER BY updated_at DESC, id DESC'
    );
    if (!$history_scan) {
        throw new RuntimeException('failed to prepare row identity history scan: ' . $meta->lastErrorMsg());
    }
    cow_merge_bind($history_scan, ':branch_name', $branch);
    cow_merge_bind($history_scan, ':table_name', $table);
    $res = $history_scan->execute();
    if (!$res) {
        throw new RuntimeException('failed to scan row identity history: ' . $meta->lastErrorMsg());
    }
    while ($candidate = $res->fetchArray(SQLITE3_ASSOC)) {
        $candidate_identity = json_decode((string)$candidate['logical_identity'], true);
        if (is_array($candidate_identity) && cow_merge_values_equal($candidate_identity, $identity)) {
            return (int)$candidate['rowid'];
        }
    }
    return null;
}

function cow_merge_update_single_cell(SQLite3 $db, string $table, array $identity, array $pk_cols, string $column, mixed $value): void {
    $where_values = [];
    $where = cow_merge_where_clause($identity, $pk_cols, $where_values);
    $stmt = $db->prepare('UPDATE ' . cow_merge_quote_ident($table) . ' SET ' . cow_merge_quote_ident($column) . ' = ? WHERE ' . $where);
    if (!$stmt) {
        throw new RuntimeException("failed to prepare conflict resolution update for $table.$column: " . $db->lastErrorMsg());
    }
    cow_merge_bind($stmt, 1, $value);
    foreach ($where_values as $i => $where_value) {
        cow_merge_bind($stmt, $i + 2, $where_value);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException("failed to apply conflict resolution to $table.$column: " . $db->lastErrorMsg());
    }
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
    $stmt = $meta->prepare(
        'INSERT INTO merge_resolutions ' .
        '(conflict_id, choice, applied, status, note, reviewer, target_db, table_name, row_identity, column_name, previous_payload, resolved_payload) ' .
        'VALUES (:conflict_id, :choice, :applied, :status, :note, :reviewer, :target_db, :table_name, :row_identity, :column_name, :previous_payload, :resolved_payload)'
    );
    if (!$stmt) {
        throw new RuntimeException('failed to prepare resolution record insert: ' . $meta->lastErrorMsg());
    }
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
    if (!$stmt->execute()) {
        throw new RuntimeException('failed to record merge resolution: ' . $meta->lastErrorMsg());
    }
    return (int)$meta->lastInsertRowID();
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
                if ($current_target_column !== null) {
                    throw new InvalidArgumentException('source schema resolution can only apply source-added columns that are still missing from target');
                }
                $source_table_sql = cow_merge_table_sql($source, $table);
                if ($source_table_sql === null) {
                    throw new RuntimeException("source table for conflict #$conflict_id no longer exists: $table");
                }
                $definition = cow_merge_schema_column_definition_payload($source_payload)
                    ?? cow_merge_column_definition_from_create_sql($source_table_sql, $object);
                if ($definition === null || !cow_merge_column_definition_is_safe_to_add($definition, $source_column)) {
                    throw new InvalidArgumentException('source schema resolution can only apply columns that are safe for ALTER TABLE ADD COLUMN');
                }
                $resolved = ['column' => $source_column, 'definition' => $definition];
                $apply_source = function () use ($target, $table, $definition): void {
                    $sql = 'ALTER TABLE ' . cow_merge_quote_ident($table) . ' ADD COLUMN ' . $definition;
                    if (!$target->exec($sql)) {
                        throw new RuntimeException('failed to apply source column schema resolution: ' . $target->lastErrorMsg());
                    }
                };
            }
        } elseif ($conflict_type === 'schema-source-added-index' && $object !== '') {
            $source_sql = cow_merge_schema_index_sql_payload($source_payload);
            if ($source_sql === null) {
                throw new RuntimeException("schema conflict #$conflict_id does not contain a source index SQL payload");
            }
            $current_source_sql = cow_merge_index_sql($source, $object);
            if ($current_source_sql !== $source_sql) {
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
                $apply_source = function () use ($target, $source_sql): void {
                    if (!$target->exec($source_sql)) {
                        throw new RuntimeException('failed to apply source index schema resolution: ' . $target->lastErrorMsg());
                    }
                };
            }
        } else {
            if ($choice === 'source') {
                throw new InvalidArgumentException('source schema resolution currently supports source-added columns and source-added indexes only');
            }
            $current_target_sql = $object === '' ? cow_merge_table_sql($target, $table) : cow_merge_index_sql($target, $object);
            $previous = $current_target_sql;
            if (!cow_merge_values_equal($current_target_sql, $target_payload)) {
                throw new RuntimeException('target schema no longer matches the audited conflict target value; rerun merge-audit before resolving');
            }
        }

        if ($apply) {
            $meta->exec('BEGIN IMMEDIATE');
            $target->exec('BEGIN IMMEDIATE');
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
                $target->exec('COMMIT');
                $meta->exec('COMMIT');
            } catch (Throwable $e) {
                $target->exec('ROLLBACK');
                $meta->exec('ROLLBACK');
                throw $e;
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
        $target->close();
    }
}

function cow_merge_resolve_conflict(
    string $metadata_db,
    int $conflict_id,
    string $choice,
    bool $apply,
    string $note,
    string $reviewer
): array {
    if (!is_file($metadata_db)) {
        throw new InvalidArgumentException("merge metadata database does not exist: $metadata_db");
    }
    $meta = cow_merge_open_db($metadata_db, SQLITE3_OPEN_READWRITE);
    $target = null;
    try {
        cow_merge_ensure_metadata($meta);
        $stmt = $meta->prepare(
            'SELECT c.id, c.run_id, c.table_name, c.row_identity, c.column_name, c.conflict_type, ' .
            'c.source_payload, c.target_payload, r.source_db, r.target_db, r.target_branch ' .
            'FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.id = :id'
        );
        if (!$stmt) {
            throw new RuntimeException('failed to prepare conflict lookup: ' . $meta->lastErrorMsg());
        }
        cow_merge_bind($stmt, ':id', $conflict_id);
        $res = $stmt->execute();
        $conflict = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
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

            $current_value = cow_merge_validate_current_file_entry($target_root, $path, $target_value, 'target');
            if ($choice === 'source' && $source_value !== null) {
                cow_merge_validate_current_file_entry($source_root, $path, $source_value, 'source');
            }
            $resolved_value = $choice === 'source' ? $source_value : $target_value;

            if ($apply) {
                $meta->exec('BEGIN IMMEDIATE');
                $file_tx = cow_merge_file_transaction_begin();
                $file_tx_committed = false;
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
                    $meta->exec('COMMIT');
                    $file_tx_committed = true;
                } catch (Throwable $e) {
                    @$meta->exec('ROLLBACK');
                    if (!$file_tx_committed) {
                        try {
                            cow_merge_file_transaction_restore($file_tx, $target_root);
                        } catch (Throwable $rollback_error) {
                            throw new RuntimeException(
                                $e->getMessage() . '; filesystem rollback failed: ' . $rollback_error->getMessage(),
                                0,
                                $e
                            );
                        }
                    }
                    throw $e;
                } finally {
                    cow_merge_file_transaction_cleanup($file_tx);
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
        $row_conflict_types = ['row-insert-collision', 'row-target-deleted', 'row-source-deleted'];
        if ($conflict_type !== 'cell-conflict' && !in_array($conflict_type, $row_conflict_types, true)) {
            throw new InvalidArgumentException('resolve-conflict currently supports DB cell-conflict, row-insert-collision, row-target-deleted, and row-source-deleted records only');
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
        $target_value = cow_merge_decode_payload_json((string)$conflict['target_payload'], 'target');
        $resolved_value = $choice === 'source' ? $source_value : $target_value;

        $target = cow_merge_open_db($target_db, SQLITE3_OPEN_READWRITE);
        $pk_cols = cow_merge_pk_cols($target, $table);
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
            if (!cow_merge_values_equal($current_value, $target_value)) {
                throw new RuntimeException('target cell no longer matches the audited conflict target value; rerun merge-audit before resolving');
            }
        } else {
            if ($conflict_type === 'row-insert-collision' && (!is_array($source_value) || !is_array($target_value))) {
                throw new RuntimeException("row conflict #$conflict_id does not contain row payloads");
            }
            if ($conflict_type === 'row-target-deleted' && !is_array($source_value)) {
                throw new RuntimeException("row conflict #$conflict_id does not contain a source row payload");
            }
            if ($conflict_type === 'row-source-deleted' && !is_array($target_value)) {
                throw new RuntimeException("row conflict #$conflict_id does not contain a target row payload");
            }
            $current_value = $pk_cols || array_key_exists('rowid', $where_identity)
                ? cow_merge_select_current_row($target, $table, $where_identity, $pk_cols)
                : null;
            if ($conflict_type === 'row-target-deleted') {
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
        }

        if ($apply) {
            $meta->exec('BEGIN IMMEDIATE');
            $target->exec('BEGIN IMMEDIATE');
            try {
                if ($choice === 'source') {
                    if ($conflict_type === 'cell-conflict') {
                        cow_merge_update_single_cell($target, $table, $where_identity, $pk_cols, $column, $source_value);
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
                    } elseif ($conflict_type === 'row-target-deleted') {
                        $columns = cow_merge_table_columns($target, $table);
                        $new_rowid = cow_merge_insert_row($target, $table, $source_value, $columns);
                        if (!$pk_cols) {
                            cow_merge_remember_row_identity($meta, (int)$conflict['run_id'], $target_branch, $table, $new_rowid, $identity, $source_value);
                        }
                    } else {
                        $columns = cow_merge_table_columns($target, $table);
                        cow_merge_update_row($target, $table, $where_identity, $pk_cols, $source_value, $columns);
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
                $target->exec('COMMIT');
                $meta->exec('COMMIT');
            } catch (Throwable $e) {
                $target->exec('ROLLBACK');
                $meta->exec('ROLLBACK');
                throw $e;
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
    $review = (string)($filters['review'] ?? '') === '1';
    $resolution_status = ($filters['resolution_status'] ?? null) !== null && (string)$filters['resolution_status'] !== '';
    $group_by = (string)($filters['group_by'] ?? 'none');
    if ($id_band_skips && $review) {
        throw new InvalidArgumentException('--id-band-skips cannot be combined with --review');
    }
    if ($id_band_skips && $resolution_status) {
        throw new InvalidArgumentException('--id-band-skips cannot be combined with --resolution-status');
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
        if (($filters['records'] ?? null) === null) {
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
    if ($group_by !== '' && $group_by !== 'none') {
        if (($filters['records'] ?? null) === null) {
            $filters['records'] = 'resolutions';
        } elseif (($filters['records'] ?? null) !== 'resolutions') {
            throw new InvalidArgumentException('--group-by can only be combined with --records resolutions');
        }
        if (($filters['decision'] ?? null) !== null) {
            throw new InvalidArgumentException('--group-by cannot be combined with --decision');
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
        return $filters;
    }
    if (($filters['scope'] ?? null) === null) {
        $filters['scope'] = 'db';
    } elseif (($filters['scope'] ?? null) === 'files') {
        throw new InvalidArgumentException('--id-band-skips cannot be combined with --scope files');
    }
    if (($filters['records'] ?? null) === null) {
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

function cow_merge_audit_filters(array $filters = []): array {
    $filters = cow_merge_audit_apply_shortcuts($filters);
    $scope = cow_merge_audit_scope($filters['scope'] ?? null);
    $path = cow_merge_audit_file_path_filter($filters['path'] ?? null, 'path');
    $path_prefix = cow_merge_audit_file_path_filter($filters['path_prefix'] ?? null, 'path-prefix');
    if ($path !== null && $path_prefix !== null) {
        throw new InvalidArgumentException('--path and --path-prefix cannot be used together');
    }
    if ($scope === 'db' && ($path !== null || $path_prefix !== null)) {
        throw new InvalidArgumentException('--path and --path-prefix require file audit scope');
    }
    return [
        'scope' => $scope,
        'records' => cow_merge_audit_records($filters['records'] ?? null),
        'conflict_type' => cow_merge_audit_filter_text($filters['conflict_type'] ?? null, 'conflict-type'),
        'decision' => cow_merge_audit_filter_text($filters['decision'] ?? null, 'decision'),
        'path' => $path,
        'path_prefix' => $path_prefix,
        'id_band_skips' => (string)($filters['id_band_skips'] ?? '') === '1',
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
        $clauses[] = $prefix . "table_name <> '__files__'";
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

    if (($filters['review'] ?? false) === true) {
        if ($record_type === 'decisions') {
            $clauses[] = $prefix . "decision = 'id-band-skipped'";
        }
    }

    if (($filters['review_status'] ?? null) !== null) {
        if (!$review_notes_exist) {
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
    array $filters
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
        $clauses[] = "mr.table_name <> '__files__'";
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

function cow_merge_audit_count_sql(array $filters, string $record_type, string $alias, bool $review_notes_exist = true): string {
    $conditions = [];
    if ($filters['scope'] === 'files') {
        $conditions[] = $alias . ".table_name = '__files__'";
    } elseif ($filters['scope'] === 'db') {
        $conditions[] = $alias . ".table_name <> '__files__'";
    }
    if ($record_type === 'conflicts' && $filters['conflict_type'] !== null) {
        $conditions[] = $alias . ".conflict_type = '" . SQLite3::escapeString($filters['conflict_type']) . "'";
    }
    if ($record_type === 'decisions' && $filters['decision'] !== null) {
        $conditions[] = $alias . ".decision = '" . SQLite3::escapeString($filters['decision']) . "'";
    }
    if (($filters['review'] ?? false) === true) {
        if ($record_type === 'decisions') {
            $conditions[] = $alias . ".decision = 'id-band-skipped'";
        }
    }
    if (($filters['review_status'] ?? null) !== null) {
        if (!$review_notes_exist) {
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
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('failed to prepare audit query: ' . $db->lastErrorMsg());
    }
    foreach ($params as $key => $value) {
        cow_merge_bind($stmt, $key, $value);
    }
    $res = $stmt->execute();
    if (!$res) {
        throw new RuntimeException('failed to execute audit query: ' . $db->lastErrorMsg());
    }
    $rows = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $clean = [];
        foreach ($row as $key => $value) {
            $clean[(string)$key] = $value;
        }
        $rows[] = $clean;
    }
    return $rows;
}

function cow_merge_audit_has_table(SQLite3 $db, string $table): bool {
    $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name");
    if (!$stmt) {
        throw new RuntimeException('failed to prepare audit table check: ' . $db->lastErrorMsg());
    }
    cow_merge_bind($stmt, ':name', $table);
    $res = $stmt->execute();
    return (bool)($res && $res->fetchArray(SQLITE3_NUM));
}

function cow_merge_audit_has_column(SQLite3 $db, string $table, string $column): bool {
    if (!cow_merge_audit_has_table($db, $table)) {
        return false;
    }
    $res = $db->query('PRAGMA table_info(' . cow_merge_quote_ident($table) . ')');
    if (!$res) {
        throw new RuntimeException('failed to inspect audit table: ' . $db->lastErrorMsg());
    }
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        if ((string)$row['name'] === $column) {
            return true;
        }
    }
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
        foreach (['base', 'source', 'target', 'chosen'] as $name) {
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
        'decisions' => [],
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
        $failure_reason_select = cow_merge_audit_has_column($db, 'merge_runs', 'failure_reason')
            ? 'r.failure_reason'
            : 'NULL AS failure_reason';
        $decision_count_filter = cow_merge_audit_count_sql($filters, 'decisions', 'd', $review_notes_exist);
        $conflict_count_filter = cow_merge_audit_count_sql($filters, 'conflicts', 'c', $review_notes_exist);
        $target_wins_filter = cow_merge_audit_named_decision_count_sql($filters, 'd', 'target-wins', $review_notes_exist);
        $source_applied_filter = cow_merge_audit_named_decision_count_sql($filters, 'd', 'source-applied', $review_notes_exist);
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
            "(SELECT COUNT(*) FROM merge_decisions d WHERE d.run_id = r.id AND d.decision = 'source-applied'$source_applied_filter) AS source_applied_count, " .
            "(SELECT COUNT(*) FROM merge_decisions d WHERE d.run_id = r.id AND d.decision LIKE 'id-band-%'$id_band_filter) AS id_band_decision_count " .
            "FROM merge_runs r $run_where ORDER BY r.id DESC LIMIT :limit",
            $run_params
        );

        [$conflict_filter, $conflict_params] = cow_merge_audit_where_sql($run_id, $filters, 'conflicts', '', $review_notes_exist);
        $conflict_params[':limit'] = $limit;
        if ($filters['records'] !== 'decisions' && $filters['records'] !== 'resolutions') {
            $report['conflicts'] = cow_merge_audit_add_payload_previews(cow_merge_audit_table_rows(
                $db,
                'merge_conflicts',
                "SELECT id, run_id, table_name, row_identity, column_name, conflict_type, resolver, resolved_at, created_at, " .
                "base_payload, source_payload, target_payload, chosen_payload$conflict_review_select " .
                "FROM merge_conflicts $conflict_filter ORDER BY id DESC LIMIT :limit",
                $conflict_params
            ));
        }

        [$decision_filter, $decision_params] = cow_merge_audit_where_sql($run_id, $filters, 'decisions', '', $review_notes_exist);
        $decision_params[':limit'] = $limit;
        if ($filters['records'] !== 'conflicts' && $filters['records'] !== 'resolutions') {
            $report['decisions'] = cow_merge_audit_add_payload_previews(cow_merge_audit_table_rows(
                $db,
                'merge_decisions',
                "SELECT id, run_id, table_name, row_identity, column_name, decision, reason, created_at, " .
                "base_payload, source_payload, target_payload, chosen_payload$decision_review_select " .
                "FROM merge_decisions $decision_filter ORDER BY id DESC LIMIT :limit",
                $decision_params
            ));
        }

        if ($filters['records'] === 'all' || $filters['records'] === 'resolutions') {
            [$resolution_filter, $resolution_params] = cow_merge_audit_resolution_where_sql($run_id, $filters);
            $resolution_params[':limit'] = $limit;
            $report['resolutions'] = cow_merge_audit_add_payload_previews(cow_merge_audit_table_rows(
                $db,
                'merge_resolutions',
                "SELECT mr.id, mr.conflict_id, c.run_id, mr.choice, mr.applied, mr.status, mr.reviewer, mr.note, mr.target_db, " .
                "mr.table_name, mr.row_identity, mr.column_name, mr.previous_payload AS target_payload, mr.resolved_payload AS chosen_payload, mr.created_at " .
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
        if ($filters['scope'] !== 'files' && $filters['records'] !== 'conflicts' && !$filters['id_band_skips']) {
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

        if ($filters['records'] !== 'decisions') {
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
    if (($filters['review'] ?? false) === true) {
        $parts[] = 'review';
    }
    return implode(' ', $parts);
}

function cow_merge_print_audit_text(array $report): void {
    echo "forkpress: COW merge audit\n";
    echo "  metadata:  {$report['metadata_db']}\n";
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
            echo "     decisions={$run['decision_count']} target-wins={$run['target_wins_count']} source-applied={$run['source_applied_count']} id-bands={$run['id_band_decision_count']} conflicts={$run['conflict_count']}\n";
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
            if (($conflict['review_status'] ?? null) !== null && (string)$conflict['review_status'] !== '') {
                echo "     review={$conflict['review_status']} reviewer={$conflict['review_reviewer']} at={$conflict['reviewed_at']}\n";
                echo "     note=" . cow_merge_audit_truncate((string)$conflict['review_note'], 240) . "\n";
            }
            echo "     source={$conflict['source_preview']}\n";
            echo "     target={$conflict['target_preview']}\n";
            echo "     chosen={$conflict['chosen_preview']}\n";
        }
    }

    if ($report['decisions']) {
        echo "decisions:\n";
        foreach ($report['decisions'] as $decision) {
            $object = cow_merge_audit_object_label($decision);
            echo "  #{$decision['id']} run={$decision['run_id']} {$decision['decision']} $object\n";
            if (($decision['review_status'] ?? null) !== null && (string)$decision['review_status'] !== '') {
                echo "     review={$decision['review_status']} reviewer={$decision['review_reviewer']} at={$decision['reviewed_at']}\n";
                echo "     note=" . cow_merge_audit_truncate((string)$decision['review_note'], 240) . "\n";
            }
            echo "     reason={$decision['reason']}\n";
            echo "     chosen={$decision['chosen_preview']}\n";
        }
    }

    if ($report['resolutions']) {
        echo "resolutions:\n";
        foreach ($report['resolutions'] as $resolution) {
            $object = cow_merge_audit_object_label($resolution);
            $applied = ((int)$resolution['applied']) === 1 ? 'yes' : 'no';
            echo "  #{$resolution['id']} conflict={$resolution['conflict_id']} run={$resolution['run_id']} {$resolution['choice']} $object status={$resolution['status']} applied=$applied reviewer={$resolution['reviewer']}\n";
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
    string $ddl
): int {
    if (!$target->exec($ddl)) {
        throw new RuntimeException("failed to create target table $table: " . $target->lastErrorMsg());
    }
    $columns = cow_merge_table_columns($source, $table);
    $pk_cols = cow_merge_pk_cols($source, $table);
    $rows = $pk_cols
        ? cow_merge_load_rows($source, $table, $pk_cols)
        : cow_merge_keyless_rows_for_branch($source, $meta, $run_id, $source_branch, $table, []);
    $applied = 0;
    foreach ($rows as $identity_json => $entry) {
        $new_rowid = cow_merge_insert_row($target, $table, $entry['row'], $columns);
        if (!$pk_cols) {
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
    return $applied;
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
): void {
    cow_merge_record_conflict($meta, $run_id, $table, null, $object, $type, $base, $source, $target_value, $chosen);
    cow_merge_record_decision($meta, $run_id, $table, null, $object, 'target-wins', $reason, $base, $source, $target_value, $chosen);
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
        cow_merge_record_schema_conflict(
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
        return ['merged' => false, 'applied' => 0, 'conflicts' => 1];
    }

    $base_columns = cow_merge_table_info($base, $table);
    $source_columns = cow_merge_table_info($source, $table);
    $target_columns = cow_merge_table_info($target, $table);
    if (
        !cow_merge_base_column_prefix_unchanged($base_columns, $source_columns) ||
        !cow_merge_base_column_prefix_unchanged($base_columns, $target_columns)
    ) {
        cow_merge_record_schema_conflict(
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
        return ['merged' => false, 'applied' => 0, 'conflicts' => 1];
    }

    $base_by_name = cow_merge_columns_by_name($base_columns);
    $target_by_name = cow_merge_columns_by_name($target_columns);
    $columns_to_add = [];
    foreach ($source_columns as $source_column) {
        $name_key = strtolower((string)$source_column['name']);
        if (isset($base_by_name[$name_key])) {
            continue;
        }
        if (isset($target_by_name[$name_key])) {
            if (!cow_merge_column_signatures_equal($source_column, $target_by_name[$name_key])) {
                cow_merge_record_schema_conflict(
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
                return ['merged' => false, 'applied' => 0, 'conflicts' => 1];
            }
            continue;
        }

        $definition = cow_merge_column_definition_from_create_sql($source_sql, (string)$source_column['name']);
        if ($definition === null || !cow_merge_column_definition_is_safe_to_add($definition, $source_column)) {
            cow_merge_record_schema_conflict(
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
            return ['merged' => false, 'applied' => 0, 'conflicts' => 1];
        }
        $columns_to_add[] = ['column' => $source_column, 'definition' => $definition];
    }

    if (!$columns_to_add) {
        return ['merged' => true, 'applied' => 0, 'conflicts' => 0];
    }

    $target->exec('SAVEPOINT forkpress_schema_merge');
    foreach ($columns_to_add as $entry) {
        $column = $entry['column'];
        $definition = $entry['definition'];
        $sql = 'ALTER TABLE ' . cow_merge_quote_ident($table) . ' ADD COLUMN ' . $definition;
        if (!$target->exec($sql)) {
            $target->exec('ROLLBACK TO forkpress_schema_merge');
            $target->exec('RELEASE forkpress_schema_merge');
            cow_merge_record_schema_conflict(
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
            return ['merged' => false, 'applied' => 0, 'conflicts' => 1];
        }
    }
    $target->exec('RELEASE forkpress_schema_merge');

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
    return ['merged' => true, 'applied' => count($columns_to_add), 'conflicts' => 0];
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
            continue;
        }
        if ($source_sql === null) {
            if ($base_sql !== null) {
                cow_merge_record_schema_conflict(
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
                );
                $conflicts++;
            }
            continue;
        }
        if ($base_sql === null && $target_sql === null) {
            if (!$target->exec($source_sql)) {
                cow_merge_record_schema_conflict(
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
                );
                $conflicts++;
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
            continue;
        }
        if ($target_sql === $base_sql) {
            cow_merge_record_schema_conflict(
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
            );
            $conflicts++;
            continue;
        }
        cow_merge_record_schema_conflict(
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
        );
        $conflicts++;
    }

    return ['applied' => $applied, 'conflicts' => $conflicts];
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
    sort($all_keys);

    $applied = 0;
    $conflicts = 0;
    foreach ($all_keys as $key) {
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
            continue;
        }
        if (cow_merge_row_values_equal($target_row, $source_row, $row_columns)) {
            continue;
        }

        if ($base_row === null && $source_row !== null && $target_row === null) {
            $new_rowid = cow_merge_insert_row($target, $table, $source_row, $columns);
            if (!$pk_cols) {
                cow_merge_remember_row_identity($meta, $run_id, $target_branch, $table, $new_rowid, $identity, $source_row);
            }
            cow_merge_record_decision($meta, $run_id, $table, $key, null, 'source-applied', 'source inserted row and target did not change it', null, $source_row, null, $source_row);
            $applied++;
            continue;
        }

        if ($base_row !== null && $source_row === null && cow_merge_row_values_equal($target_row, $base_row, $row_columns)) {
            $where_identity = cow_merge_entry_where_identity($target_entry, $pk_cols);
            if ($where_identity === null) {
                throw new RuntimeException("cannot delete $table row without a target identity");
            }
            cow_merge_delete_row($target, $table, $where_identity, $pk_cols);
            cow_merge_record_decision($meta, $run_id, $table, $key, null, 'source-applied', 'source deleted row and target did not change it', $base_row, null, $target_row, null);
            $applied++;
            continue;
        }

        if ($target_row === null && $source_row !== null) {
            cow_merge_record_conflict($meta, $run_id, $table, $key, null, 'row-target-deleted', $base_row, $source_row, null, null);
            cow_merge_record_decision($meta, $run_id, $table, $key, null, 'target-wins', 'target deleted row while source changed it', $base_row, $source_row, null, null);
            $conflicts++;
            continue;
        }

        if ($base_row === null && $source_row !== null && $target_row !== null) {
            cow_merge_record_conflict($meta, $run_id, $table, $key, null, 'row-insert-collision', null, $source_row, $target_row, $target_row);
            cow_merge_record_decision($meta, $run_id, $table, $key, null, 'target-wins', 'source and target inserted different rows with the same identity', null, $source_row, $target_row, $target_row);
            $conflicts++;
            continue;
        }

        if ($source_row === null && $target_row !== null) {
            cow_merge_record_conflict($meta, $run_id, $table, $key, null, 'row-source-deleted', $base_row, null, $target_row, $target_row);
            cow_merge_record_decision($meta, $run_id, $table, $key, null, 'target-wins', 'source deleted row while target changed it', $base_row, null, $target_row, $target_row);
            $conflicts++;
            continue;
        }

        if ($base_row !== null && $source_row !== null && $target_row !== null && cow_merge_row_values_equal($target_row, $base_row, $row_columns)) {
            $where_identity = cow_merge_entry_where_identity($target_entry, $pk_cols);
            if ($where_identity === null) {
                throw new RuntimeException("cannot update $table row without a target identity");
            }
            cow_merge_update_row($target, $table, $where_identity, $pk_cols, $source_row, $columns);
            cow_merge_record_decision($meta, $run_id, $table, $key, null, 'source-applied', 'source changed row and target did not change it', $base_row, $source_row, $target_row, $source_row);
            $applied++;
            continue;
        }

        if ($base_row === null || $source_row === null || $target_row === null) {
            continue;
        }

        $merged = $target_row;
        $row_conflicts = 0;
        $row_applied = 0;
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
                continue;
            }
            if (!$target_changed || cow_merge_values_equal($s, $t)) {
                $merged[$col] = $s;
                if (!$target_changed) {
                    cow_merge_record_decision($meta, $run_id, $table, $key, $col, 'source-applied', 'source changed cell and target did not change it', $b, $s, $t, $s);
                    $row_applied++;
                }
                continue;
            }
            cow_merge_record_conflict($meta, $run_id, $table, $key, $col, 'cell-conflict', $b, $s, $t, $t);
            cow_merge_record_decision($meta, $run_id, $table, $key, $col, 'target-wins', 'source and target changed the same cell differently', $b, $s, $t, $t);
            $row_conflicts++;
        }

        if ($row_applied > 0) {
            $where_identity = cow_merge_entry_where_identity($target_entry, $pk_cols);
            if ($where_identity === null) {
                throw new RuntimeException("cannot update $table row without a target identity");
            }
            cow_merge_update_row($target, $table, $where_identity, $pk_cols, $merged, $columns);
            $applied += $row_applied;
        }
        $conflicts += $row_conflicts;
    }

    return ['applied' => $applied, 'conflicts' => $conflicts];
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

    $base = cow_merge_open_db($base_db, SQLITE3_OPEN_READONLY);
    $source = cow_merge_open_db($source_db, SQLITE3_OPEN_READONLY);
    $target = cow_merge_open_db($target_db, SQLITE3_OPEN_READWRITE);
    $meta = cow_merge_open_db($metadata_db, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    cow_merge_ensure_metadata($meta);
    $run_id = cow_merge_start_run($meta, $source_branch, $target_branch, $base_db, $source_db, $target_db);

    $applied = 0;
    $conflicts = 0;
    try {
        $target->exec('BEGIN IMMEDIATE');
        $base_tables = cow_merge_table_sql_map($base);
        $source_tables = cow_merge_table_sql_map($source);
        $target_tables = cow_merge_table_sql_map($target);
        $base_indexes = cow_merge_index_sql_map($base);
        $source_indexes = cow_merge_index_sql_map($source);
        $target_indexes = cow_merge_index_sql_map($target);
        $all_tables = array_unique(array_merge(array_keys($base_tables), array_keys($source_tables), array_keys($target_tables)));
        sort($all_tables);

        foreach ($all_tables as $table) {
            $base_sql = $base_tables[$table] ?? null;
            $source_sql = $source_tables[$table] ?? null;
            $target_sql = $target_tables[$table] ?? null;

            if ($source_sql === null) {
                continue;
            }
            if ($target_sql === null && $base_sql === null) {
                $applied += cow_merge_apply_source_table($source, $target, $meta, $run_id, $source_branch, $target_branch, $table, $source_sql);
                continue;
            }
            if ($target_sql === null) {
                cow_merge_record_schema_conflict($meta, $run_id, $table, null, 'schema-target-dropped-table', $base_sql, $source_sql, null, null, 'target dropped table while source kept or changed it');
                $conflicts++;
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
            }

            $result = cow_merge_table_rows($base, $source, $target, $meta, $run_id, $source_branch, $target_branch, $table);
            $applied += $result['applied'];
            $conflicts += $result['conflicts'];
        }

        $index_result = cow_merge_apply_index_schema_changes($target, $meta, $run_id, $base_indexes, $source_indexes, $target_indexes);
        $applied += $index_result['applied'];
        $conflicts += $index_result['conflicts'];

        $target->exec('COMMIT');
        $status = $conflicts > 0 ? 'completed_with_conflicts' : 'completed';
        cow_merge_finish_run($meta, $run_id, $status);
        return [
            'run_id' => $run_id,
            'status' => $status,
            'applied' => $applied,
            'conflicts' => $conflicts,
            'metadata_db' => $metadata_db,
        ];
    } catch (Throwable $e) {
        $target->exec('ROLLBACK');
        cow_merge_finish_run($meta, $run_id, 'failed', cow_merge_failure_reason($e));
        throw $e;
    } finally {
        $base->close();
        $source->close();
        $target->close();
        $meta->close();
    }
}

function cow_merge_set_run_status(string $metadata_db, int $run_id, string $status): void {
    $meta = cow_merge_open_db($metadata_db, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    cow_merge_ensure_metadata($meta);
    cow_merge_finish_run($meta, $run_id, $status);
    $meta->close();
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
    cow_merge_ensure_metadata($meta);
    $run_id = cow_merge_start_run($meta, $source_branch, $target_branch, $base_db, $source_db, $target_db);
    cow_merge_finish_run($meta, $run_id, 'failed', $failure_reason);
    $meta->close();
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
    ?string $target_root = null
): array {
    $file_args = [$base_files, $source_root, $target_root];
    $has_file_args = array_filter($file_args, fn($value) => $value !== null && $value !== '');
    if ($has_file_args) {
        if (count($has_file_args) !== 3) {
            throw new InvalidArgumentException('--base-files, --source-root, and --target-root must be provided together');
        }
    }

    $target_snapshot = null;
    $metadata_snapshot = null;
    if ($has_file_args) {
        $target_snapshot = cow_merge_snapshot_sqlite_db($target_db);
        $metadata_snapshot = cow_merge_snapshot_sqlite_db($metadata_db);
    }

    $attempted_run_id = null;
    try {
        $result = cow_merge_databases($base_db, $source_db, $target_db, $metadata_db, $source_branch, $target_branch);
        $attempted_run_id = (int)$result['run_id'];
        $result['db_applied'] = $result['applied'];
        $result['db_conflicts'] = $result['conflicts'];
        $result['file_applied'] = 0;
        $result['file_conflicts'] = 0;

        if ($has_file_args) {
            $file_result = cow_merge_files($base_files, $source_root, $target_root, $metadata_db, (int)$result['run_id']);
            $result['file_applied'] = $file_result['applied'];
            $result['file_conflicts'] = $file_result['conflicts'];
            $result['applied'] += $file_result['applied'];
            $result['conflicts'] += $file_result['conflicts'];
            $result['status'] = $result['conflicts'] > 0 ? 'completed_with_conflicts' : 'completed';
            cow_merge_set_run_status($metadata_db, (int)$result['run_id'], $result['status']);
        }

        return $result;
    } catch (Throwable $e) {
        if ($target_snapshot !== null && $metadata_snapshot !== null) {
            try {
                cow_merge_restore_sqlite_snapshot($target_snapshot);
                cow_merge_restore_sqlite_snapshot($metadata_snapshot);
                cow_merge_record_failed_run(
                    $metadata_db,
                    $source_branch,
                    $target_branch,
                    $base_db,
                    $source_db,
                    $target_db,
                    cow_merge_failure_reason($e)
                );
            } catch (Throwable $rollback_error) {
                cow_merge_record_rollback_failure_artifact(
                    $metadata_db,
                    $attempted_run_id,
                    $source_branch,
                    $target_branch,
                    $base_db,
                    $source_db,
                    $target_db,
                    cow_merge_failure_reason($e),
                    cow_merge_failure_reason($rollback_error)
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
        if ($target_snapshot !== null) {
            cow_merge_cleanup_sqlite_snapshot($target_snapshot);
        }
        if ($metadata_snapshot !== null) {
            cow_merge_cleanup_sqlite_snapshot($metadata_snapshot);
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
        if (($key === 'id-band-skips' || $key === 'review' || $key === 'apply') && (!isset($argv[$i + 1]) || str_starts_with($argv[$i + 1], '--'))) {
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
                cow_merge_review_text($args['reviewer'] ?? 'user', 'reviewer')
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
            $args['target-root'] ?? null
        );
        echo "forkpress: merged {$args['source']} into {$args['target']}\n";
        echo "  run:       {$result['run_id']}\n";
        echo "  status:    {$result['status']}\n";
        echo "  applied:   {$result['applied']}\n";
        echo "  conflicts: {$result['conflicts']}\n";
        if (isset($result['file_applied']) && ($result['file_applied'] > 0 || $result['file_conflicts'] > 0)) {
            echo "  files:     applied={$result['file_applied']} conflicts={$result['file_conflicts']}\n";
        }
        echo "  metadata:  {$result['metadata_db']}\n";
        exit(0);
    } catch (Throwable $e) {
        cow_merge_usage();
        fwrite(STDERR, "merge: " . $e->getMessage() . "\n");
        exit(1);
    }
}

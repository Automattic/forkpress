<?php
/**
 * Import a ForkPress MySQL JSONL export into a SQLite database.
 *
 * Usage:
 *   php mysql_import_sqlite.php <export-jsonl> <sqlite-db>
 */

declare(strict_types=1);

error_reporting(E_ALL);

$export_path = (string)($argv[1] ?? '');
$db_path = (string)($argv[2] ?? '');
if ($export_path === '' || !is_file($export_path)) {
    fwrite(STDERR, "mysql_import_sqlite: export file missing\n");
    exit(2);
}
if ($db_path === '') {
    fwrite(STDERR, "mysql_import_sqlite: SQLite database path missing\n");
    exit(2);
}
if (!class_exists('SQLite3')) {
    fwrite(STDERR, "mysql_import_sqlite: local PHP is missing SQLite3\n");
    exit(2);
}

function forkpress_mysql_import_ident(string $name): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException("unsupported MySQL identifier: $name");
    }
    return '"' . str_replace('"', '""', $name) . '"';
}

function forkpress_mysql_import_affinity(string $mysql_type): string {
    $type = strtolower($mysql_type);
    if (str_contains($type, 'blob') || str_contains($type, 'binary')) {
        return 'BLOB';
    }
    if (str_contains($type, 'int') || str_contains($type, 'bit') || str_contains($type, 'bool')) {
        return 'INTEGER';
    }
    if (str_contains($type, 'decimal') || str_contains($type, 'double') || str_contains($type, 'float') || str_contains($type, 'real')) {
        return 'REAL';
    }
    return 'TEXT';
}

function forkpress_mysql_import_decode_value(mixed $encoded): ?string {
    if ($encoded === null) {
        return null;
    }
    if (!is_string($encoded)) {
        throw new RuntimeException('row value must be null or a base64 string');
    }
    $decoded = base64_decode($encoded, true);
    if ($decoded === false) {
        throw new RuntimeException('row value is not valid base64');
    }
    return $decoded;
}

function forkpress_mysql_import_bind(SQLite3Stmt $stmt, string $param, ?string $value, string $mysql_type): void {
    if ($value === null) {
        $stmt->bindValue($param, null, SQLITE3_NULL);
        return;
    }
    $affinity = forkpress_mysql_import_affinity($mysql_type);
    if ($affinity === 'INTEGER' && preg_match('/^-?[0-9]+$/', $value)) {
        $stmt->bindValue($param, (int)$value, SQLITE3_INTEGER);
        return;
    }
    if ($affinity === 'REAL' && is_numeric($value)) {
        $stmt->bindValue($param, (float)$value, SQLITE3_FLOAT);
        return;
    }
    if ($affinity === 'BLOB') {
        $stmt->bindValue($param, $value, SQLITE3_BLOB);
        return;
    }
    $stmt->bindValue($param, $value, SQLITE3_TEXT);
}

function forkpress_mysql_import_create_table(SQLite3 $db, array $table): array {
    $name = (string)($table['name'] ?? '');
    $columns = $table['columns'] ?? null;
    if ($name === '' || !is_array($columns) || count($columns) === 0) {
        throw new RuntimeException('table record must include name and columns');
    }

    $primary = [];
    $auto_primary = null;
    $column_types = [];
    foreach ($columns as $column) {
        $column_name = (string)($column['name'] ?? '');
        $key = strtoupper((string)($column['key'] ?? ''));
        $extra = strtolower((string)($column['extra'] ?? ''));
        if ($column_name === '') {
            throw new RuntimeException("table $name has an unnamed column");
        }
        $column_types[$column_name] = (string)($column['type'] ?? 'text');
        if ($key === 'PRI') {
            $primary[] = $column_name;
            if (str_contains($extra, 'auto_increment')) {
                $auto_primary = $column_name;
            }
        }
    }

    $defs = [];
    foreach ($columns as $column) {
        $column_name = (string)$column['name'];
        $affinity = forkpress_mysql_import_affinity((string)($column['type'] ?? 'text'));
        if ($auto_primary === $column_name && count($primary) === 1 && $affinity === 'INTEGER') {
            $defs[] = forkpress_mysql_import_ident($column_name) . ' INTEGER PRIMARY KEY AUTOINCREMENT';
        } else {
            $defs[] = forkpress_mysql_import_ident($column_name) . ' ' . $affinity;
        }
    }
    if ($auto_primary === null && count($primary) > 0) {
        $defs[] = 'PRIMARY KEY (' . implode(', ', array_map('forkpress_mysql_import_ident', $primary)) . ')';
    }

    $db->exec('DROP TABLE IF EXISTS ' . forkpress_mysql_import_ident($name));
    $sql = 'CREATE TABLE ' . forkpress_mysql_import_ident($name) . ' (' . implode(', ', $defs) . ')';
    if (!$db->exec($sql)) {
        throw new RuntimeException("failed to create SQLite table $name: " . $db->lastErrorMsg());
    }

    $indexes = $table['indexes'] ?? [];
    if (is_array($indexes)) {
        $grouped = [];
        foreach ($indexes as $index) {
            $index_name = (string)($index['name'] ?? '');
            $column = $index['column'] ?? null;
            if ($index_name === '' || $index_name === 'PRIMARY' || $column === null) {
                continue;
            }
            $grouped[$index_name]['unique'] = (bool)($index['unique'] ?? false);
            $grouped[$index_name]['columns'][(int)($index['seq'] ?? 0)] = (string)$column;
        }
        foreach ($grouped as $index_name => $index) {
            ksort($index['columns'], SORT_NUMERIC);
            $columns_sql = implode(', ', array_map('forkpress_mysql_import_ident', $index['columns']));
            if ($columns_sql === '') {
                continue;
            }
            $sqlite_index = $name . '__' . $index_name;
            $sql = 'CREATE ' . ($index['unique'] ? 'UNIQUE ' : '') . 'INDEX '
                . forkpress_mysql_import_ident($sqlite_index)
                . ' ON ' . forkpress_mysql_import_ident($name)
                . ' (' . $columns_sql . ')';
            if (!$db->exec($sql)) {
                throw new RuntimeException("failed to create SQLite index $sqlite_index: " . $db->lastErrorMsg());
            }
        }
    }

    return $column_types;
}

$parent = dirname($db_path);
if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
    fwrite(STDERR, "mysql_import_sqlite: failed to create $parent\n");
    exit(2);
}
$tmp_path = $db_path . '.importing.' . getmypid();
@unlink($tmp_path);

$tables = [];
$prepared = [];
$table_count = 0;
$row_count = 0;

try {
    $db = new SQLite3($tmp_path);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA foreign_keys = OFF');
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('BEGIN IMMEDIATE');

    $handle = fopen($export_path, 'rb');
    if (!$handle) {
        throw new RuntimeException("failed to open $export_path");
    }
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $record = json_decode($line, true);
        if (!is_array($record)) {
            throw new RuntimeException('invalid JSON export record: ' . json_last_error_msg());
        }
        $type = (string)($record['type'] ?? '');
        if ($type === 'meta') {
            continue;
        }
        if ($type === 'table') {
            $table = (string)($record['name'] ?? '');
            $tables[$table] = forkpress_mysql_import_create_table($db, $record);
            $table_count++;
            continue;
        }
        if ($type !== 'row') {
            throw new RuntimeException("unsupported export record type: $type");
        }

        $table = (string)($record['table'] ?? '');
        if (!isset($tables[$table])) {
            throw new RuntimeException("row appeared before table metadata for $table");
        }
        $values = $record['values'] ?? null;
        if (!is_array($values)) {
            throw new RuntimeException("row for $table must include values");
        }
        $columns = array_keys($tables[$table]);
        if (!isset($prepared[$table])) {
            $placeholders = [];
            foreach ($columns as $i => $_column) {
                $placeholders[] = ':v' . $i;
            }
            $sql = 'INSERT INTO ' . forkpress_mysql_import_ident($table)
                . ' (' . implode(', ', array_map('forkpress_mysql_import_ident', $columns)) . ')'
                . ' VALUES (' . implode(', ', $placeholders) . ')';
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException("failed to prepare insert for $table: " . $db->lastErrorMsg());
            }
            $prepared[$table] = $stmt;
        }
        $stmt = $prepared[$table];
        foreach ($columns as $i => $column) {
            $decoded = forkpress_mysql_import_decode_value($values[$column] ?? null);
            forkpress_mysql_import_bind($stmt, ':v' . $i, $decoded, $tables[$table][$column]);
        }
        $result = $stmt->execute();
        if (!$result) {
            throw new RuntimeException("failed to insert row into $table: " . $db->lastErrorMsg());
        }
        $result->finalize();
        $stmt->reset();
        $row_count++;
    }
    fclose($handle);

    $db->exec('COMMIT');
    $db->close();
    if (is_file($db_path) && !unlink($db_path)) {
        throw new RuntimeException("failed to replace existing SQLite database at $db_path");
    }
    if (!rename($tmp_path, $db_path)) {
        throw new RuntimeException("failed to publish SQLite database at $db_path");
    }
    echo "  mysql:     imported $table_count tables, $row_count rows into wp-content/database/.ht.sqlite\n";
} catch (Throwable $e) {
    if (isset($db) && $db instanceof SQLite3) {
        @$db->exec('ROLLBACK');
        @$db->close();
    }
    @unlink($tmp_path);
    fwrite(STDERR, 'mysql_import_sqlite: ' . $e->getMessage() . "\n");
    exit(2);
}

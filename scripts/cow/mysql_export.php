<?php
/**
 * Stream a WordPress MySQL database as newline-delimited JSON.
 *
 * This script is sent over SSH and runs inside the remote WordPress root. It
 * intentionally avoids booting WordPress; production sites often have plugin
 * code or host-only assumptions that should not be executed during import.
 *
 * Usage:
 *   php mysql_export.php <wordpress-root>
 */

declare(strict_types=1);

error_reporting(E_ALL);

$root = rtrim((string)($argv[1] ?? ''), "/\\");
if ($root === '' || !is_dir($root)) {
    fwrite(STDERR, "mysql_export: WordPress root missing or not a directory\n");
    exit(2);
}

$config_path = $root . '/wp-config.php';
if (!is_file($config_path)) {
    fwrite(STDERR, "mysql_export: wp-config.php not found in $root\n");
    exit(2);
}

if (!extension_loaded('mysqli')) {
    fwrite(STDERR, "mysql_export: remote PHP is missing mysqli\n");
    exit(2);
}

$config = file_get_contents($config_path);
if ($config === false) {
    fwrite(STDERR, "mysql_export: failed to read $config_path\n");
    exit(2);
}

function forkpress_mysql_config_define(string $config, string $name): string {
    $pattern = '/define\s*\(\s*[\'"]' . preg_quote($name, '/') . '[\'"]\s*,\s*([\'"])(.*?)\1\s*\)/s';
    if (!preg_match($pattern, $config, $matches)) {
        throw new RuntimeException("wp-config.php must define $name as a string literal");
    }
    return stripcslashes($matches[2]);
}

function forkpress_mysql_table_prefix(string $config): string {
    if (!preg_match('/\$table_prefix\s*=\s*([\'"])(.*?)\1\s*;/s', $config, $matches)) {
        throw new RuntimeException('wp-config.php must assign $table_prefix as a string literal');
    }
    return stripcslashes($matches[2]);
}

function forkpress_mysql_quote_identifier(string $identifier): string {
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function forkpress_mysql_emit(array $record): void {
    $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        throw new RuntimeException('failed to encode export record: ' . json_last_error_msg());
    }
    echo $json, "\n";
}

try {
    $db_name = forkpress_mysql_config_define($config, 'DB_NAME');
    $db_user = forkpress_mysql_config_define($config, 'DB_USER');
    $db_password = forkpress_mysql_config_define($config, 'DB_PASSWORD');
    $db_host_raw = forkpress_mysql_config_define($config, 'DB_HOST');
    $table_prefix = forkpress_mysql_table_prefix($config);
} catch (Throwable $e) {
    fwrite(STDERR, 'mysql_export: ' . $e->getMessage() . "\n");
    exit(2);
}

$host = $db_host_raw === '' ? 'localhost' : $db_host_raw;
$port = 0;
$socket = null;
if (strpos($host, ':/') !== false) {
    [$host, $socket] = explode(':', $host, 2);
} elseif (preg_match('/^(.+):([0-9]+)$/', $host, $matches)) {
    $host = $matches[1];
    $port = (int)$matches[2];
}

$mysqli = mysqli_init();
if (!$mysqli) {
    fwrite(STDERR, "mysql_export: failed to initialize mysqli\n");
    exit(2);
}
if (!$mysqli->real_connect($host, $db_user, $db_password, $db_name, $port, $socket)) {
    fwrite(STDERR, 'mysql_export: MySQL connection failed: ' . mysqli_connect_error() . "\n");
    exit(2);
}
$mysqli->set_charset('utf8mb4');

forkpress_mysql_emit([
    'type' => 'meta',
    'database' => $db_name,
    'table_prefix' => $table_prefix,
]);

$tables = [];
$result = $mysqli->query('SHOW FULL TABLES');
if (!$result) {
    fwrite(STDERR, 'mysql_export: failed to list tables: ' . $mysqli->error . "\n");
    exit(2);
}
while ($row = $result->fetch_array(MYSQLI_NUM)) {
    $table = (string)($row[0] ?? '');
    $kind = strtoupper((string)($row[1] ?? 'BASE TABLE'));
    if ($kind !== 'BASE TABLE' || strncmp($table, $table_prefix, strlen($table_prefix)) !== 0) {
        continue;
    }
    $tables[] = $table;
}
$result->free();
sort($tables, SORT_STRING);

foreach ($tables as $table) {
    $quoted = forkpress_mysql_quote_identifier($table);
    $columns = [];
    $column_result = $mysqli->query("SHOW COLUMNS FROM $quoted");
    if (!$column_result) {
        throw new RuntimeException("failed to read columns for $table: " . $mysqli->error);
    }
    while ($column = $column_result->fetch_assoc()) {
        $columns[] = [
            'name' => (string)$column['Field'],
            'type' => (string)$column['Type'],
            'null' => (string)$column['Null'],
            'key' => (string)$column['Key'],
            'default' => $column['Default'],
            'extra' => (string)$column['Extra'],
        ];
    }
    $column_result->free();

    $indexes = [];
    $index_result = $mysqli->query("SHOW INDEX FROM $quoted");
    if (!$index_result) {
        throw new RuntimeException("failed to read indexes for $table: " . $mysqli->error);
    }
    while ($index = $index_result->fetch_assoc()) {
        $key = (string)$index['Key_name'];
        if ($key === 'PRIMARY') {
            continue;
        }
        $indexes[] = [
            'name' => $key,
            'unique' => ((int)$index['Non_unique']) === 0,
            'seq' => (int)$index['Seq_in_index'],
            'column' => $index['Column_name'] === null ? null : (string)$index['Column_name'],
            'sub_part' => $index['Sub_part'] === null ? null : (int)$index['Sub_part'],
        ];
    }
    $index_result->free();

    forkpress_mysql_emit([
        'type' => 'table',
        'name' => $table,
        'columns' => $columns,
        'indexes' => $indexes,
    ]);

    $row_result = $mysqli->query("SELECT * FROM $quoted", MYSQLI_USE_RESULT);
    if (!$row_result) {
        throw new RuntimeException("failed to read rows for $table: " . $mysqli->error);
    }
    while ($row = $row_result->fetch_assoc()) {
        $values = [];
        foreach ($row as $name => $value) {
            $values[$name] = $value === null ? null : base64_encode((string)$value);
        }
        forkpress_mysql_emit([
            'type' => 'row',
            'table' => $table,
            'values' => $values,
        ]);
    }
    $row_result->free();
}

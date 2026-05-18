<?php
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/forkpress_mysql_import_' . getmypid();
if (!mkdir($tmp, 0755, true) && !is_dir($tmp)) {
    fwrite(STDERR, "failed to create $tmp\n");
    exit(1);
}

function cleanup(string $path): void {
    if (!is_dir($path)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
}

function emit_record($handle, array $record): void {
    fwrite($handle, json_encode($record, JSON_UNESCAPED_SLASHES) . "\n");
}

$export = $tmp . '/mysql.jsonl';
$db_path = $tmp . '/wp-content/database/.ht.sqlite';
$fh = fopen($export, 'wb');
emit_record($fh, ['type' => 'meta', 'database' => 'wordpress', 'table_prefix' => 'wp_']);
emit_record($fh, [
    'type' => 'table',
    'name' => 'wp_posts',
    'columns' => [
        ['name' => 'ID', 'type' => 'bigint(20) unsigned', 'null' => 'NO', 'key' => 'PRI', 'default' => null, 'extra' => 'auto_increment'],
        ['name' => 'post_title', 'type' => 'text', 'null' => 'NO', 'key' => '', 'default' => null, 'extra' => ''],
        ['name' => 'post_status', 'type' => 'varchar(20)', 'null' => 'NO', 'key' => '', 'default' => 'publish', 'extra' => ''],
    ],
    'indexes' => [
        ['name' => 'post_status', 'unique' => false, 'seq' => 1, 'column' => 'post_status'],
    ],
]);
emit_record($fh, [
    'type' => 'row',
    'table' => 'wp_posts',
    'values' => [
        'ID' => base64_encode('7'),
        'post_title' => base64_encode('Imported MySQL page'),
        'post_status' => base64_encode('publish'),
    ],
]);
emit_record($fh, [
    'type' => 'table',
    'name' => 'wp_options',
    'columns' => [
        ['name' => 'option_id', 'type' => 'bigint(20) unsigned', 'null' => 'NO', 'key' => 'PRI', 'default' => null, 'extra' => 'auto_increment'],
        ['name' => 'option_name', 'type' => 'varchar(191)', 'null' => 'NO', 'key' => 'UNI', 'default' => '', 'extra' => ''],
        ['name' => 'option_value', 'type' => 'longtext', 'null' => 'NO', 'key' => '', 'default' => null, 'extra' => ''],
        ['name' => 'autoload', 'type' => 'varchar(20)', 'null' => 'NO', 'key' => '', 'default' => 'yes', 'extra' => ''],
    ],
    'indexes' => [
        ['name' => 'option_name', 'unique' => true, 'seq' => 1, 'column' => 'option_name'],
    ],
]);
emit_record($fh, [
    'type' => 'row',
    'table' => 'wp_options',
    'values' => [
        'option_id' => base64_encode('3'),
        'option_name' => base64_encode('siteurl'),
        'option_value' => base64_encode('https://example.test'),
        'autoload' => base64_encode('yes'),
    ],
]);
fclose($fh);

$cmd = escapeshellarg(PHP_BINARY) . ' '
    . escapeshellarg(__DIR__ . '/../../scripts/cow/mysql_import_sqlite.php') . ' '
    . escapeshellarg($export) . ' '
    . escapeshellarg($db_path);
passthru($cmd, $status);
if ($status !== 0) {
    cleanup($tmp);
    exit($status);
}

$db = new SQLite3($db_path, SQLITE3_OPEN_READWRITE);
$title = $db->querySingle("SELECT post_title FROM wp_posts WHERE ID = 7");
$sequence = (int)$db->querySingle("SELECT seq FROM sqlite_sequence WHERE name = 'wp_posts'");
$index = $db->querySingle("SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'wp_options__option_name'");
$duplicate_failed = false;
try {
    $duplicate_failed = !@$db->exec("INSERT INTO wp_options (option_id, option_name, option_value, autoload) VALUES (4, 'siteurl', 'duplicate', 'yes')");
} catch (Throwable $e) {
    $duplicate_failed = true;
}
$db->close();

$ok = $title === 'Imported MySQL page'
    && $sequence === 7
    && $index === 'wp_options__option_name'
    && $duplicate_failed;
cleanup($tmp);
if (!$ok) {
    fwrite(STDERR, "mysql import assertions failed\n");
    exit(1);
}
echo "mysql_import.php passed\n";

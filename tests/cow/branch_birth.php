<?php

$pass = 0;
$fail = 0;

function assert_true($cond, $msg) {
    global $pass, $fail;
    if ($cond) {
        echo "  PASS: $msg\n";
        $pass++;
    } else {
        echo "  FAIL: $msg\n";
        $fail++;
    }
}

function assert_same($actual, $expected, $msg) {
    assert_true(
        $actual === $expected,
        "$msg (got " . var_export($actual, true) . ", expected " . var_export($expected, true) . ")"
    );
}

function assert_throws_contains(callable $fn, string $needle, string $msg): void {
    try {
        $fn();
        assert_true(false, "$msg (no exception thrown)");
    } catch (Throwable $e) {
        assert_true(str_contains($e->getMessage(), $needle), "$msg (" . $e->getMessage() . ")");
    }
}

function remove_tree(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

function open_db(string $path): SQLite3 {
    $db = new SQLite3($path);
    $db->busyTimeout(5000);
    return $db;
}

function scalar(string $db_path, string $sql): mixed {
    $db = open_db($db_path);
    $value = $db->querySingle($sql);
    $db->close();
    return $value;
}

function write_test_file(string $path, string $contents): void {
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);
}

function create_branch_birth_db(string $path): void {
    $db = open_db($path);
    $db->exec('CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY AUTOINCREMENT, post_title TEXT NOT NULL)');
    $db->exec('CREATE TABLE plugin_autoinc (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT NOT NULL)');
    $db->exec('CREATE TABLE plugin_keyless (label TEXT NOT NULL)');
    $db->exec("INSERT INTO wp_posts (ID, post_title) VALUES (1, 'base post')");
    $db->exec("INSERT INTO plugin_autoinc (id, label) VALUES (1, 'base plugin row')");
    $db->exec("INSERT INTO plugin_keyless (label) VALUES ('base keyless row')");
    $db->close();
}

function run_merge_cli(array $args): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../scripts/cow/merge.php');
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg((string)$arg);
    }
    $output = [];
    $status = 0;
    exec($cmd . ' 2>&1', $output, $status);
    return [
        'status' => $status,
        'output' => implode("\n", $output),
    ];
}

define('FORKPRESS_COW_MERGE_TESTS', true);
require_once __DIR__ . '/../../scripts/cow/merge.php';

echo "=== COW branch birth focused tests ===\n";

$tmp = sys_get_temp_dir() . '/forkpress-cow-branch-birth-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);

try {
    $db_path = $tmp . '/branch.sqlite';
    $metadata = $tmp . '/.forkpress/cow/merge/metadata.sqlite';
    create_branch_birth_db($db_path);

    cow_merge_allocate_autoincrement_bands($db_path, $metadata, 'feature-birth');
    cow_merge_capture_row_identities($db_path, $metadata, 'feature-birth');

    $validation = cow_merge_validate_branch_birth_metadata($db_path, $metadata, 'feature-birth');
    assert_same($validation['status'], 'validated', 'complete branch birth metadata validates');
    assert_same($validation['autoincrement_tables'], 2, 'validation counts AUTOINCREMENT tables that need bands');
    assert_same($validation['keyless_rows'], 1, 'validation counts keyless rows that need identities');

    $cli_validation = run_merge_cli([
        'validate-branch-birth-metadata',
        '--db', $db_path,
        '--metadata-db', $metadata,
        '--branch', 'feature-birth',
    ]);
    assert_same($cli_validation['status'], 0, 'branch birth validation CLI accepts complete metadata');
    assert_true(str_contains($cli_validation['output'], 'validated branch birth metadata'), 'branch birth validation CLI reports success');

    $branch_root = $tmp . '/branch-root';
    $file_base = $tmp . '/.forkpress/cow/merge/file-bases/feature-birth.json';
    write_test_file($branch_root . '/wp-content/index.php', "<?php echo 'branch';\n");
    write_test_file($branch_root . '/wp-content/uploads/2026/05/photo.jpg', "image bytes\n");
    write_test_file($branch_root . '/wp-content/database/.ht.sqlite', "managed db bytes\n");
    write_test_file($branch_root . '/database.sql', "managed dump\n");
    write_test_file($branch_root . '/wp-config.php', "managed config\n");
    write_test_file($branch_root . '/.git/config', "managed git config\n");
    $file_capture = run_merge_cli([
        'capture-files',
        '--root', $branch_root,
        '--file-base', $file_base,
    ]);
    assert_same($file_capture['status'], 0, 'branch birth file-base capture CLI succeeds');
    assert_true(str_contains($file_capture['output'], 'captured filesystem merge base'), 'branch birth file-base capture CLI reports success');
    assert_true(is_file($file_base), 'branch birth file-base capture writes an artifact');
    $file_base_json = json_decode((string)file_get_contents($file_base), true);
    assert_true(is_array($file_base_json), 'branch birth file-base artifact is valid JSON');
    $entries = is_array($file_base_json['entries'] ?? null) ? $file_base_json['entries'] : [];
    assert_true(isset($entries['wp-content/index.php']), 'branch birth file-base captures WordPress content files');
    assert_true(isset($entries['wp-content/uploads/2026/05/photo.jpg']), 'branch birth file-base captures upload files');
    assert_true(!isset($entries['wp-content/database/.ht.sqlite']), 'branch birth file-base excludes managed branch SQLite files');
    assert_true(!isset($entries['database.sql']), 'branch birth file-base excludes generated database dumps');
    assert_true(!isset($entries['wp-config.php']), 'branch birth file-base excludes managed wp-config.php');
    assert_true(!isset($entries['.git/config']), 'branch birth file-base excludes Git internals');
    write_test_file($branch_root . '/wp-content/uploads/2026/05/after-capture.jpg', "after capture\n");
    write_test_file($branch_root . '/wp-content/uploads/2026/05/photo.jpg', "mutated image bytes\n");
    $captured_entries = json_decode((string)file_get_contents($file_base), true)['entries'] ?? [];
    assert_true(!isset($captured_entries['wp-content/uploads/2026/05/after-capture.jpg']), 'branch birth file-base remains a pre-write snapshot after later branch files are added');
    assert_same(
        $captured_entries['wp-content/uploads/2026/05/photo.jpg']['sha256'] ?? null,
        hash('sha256', "image bytes\n"),
        'branch birth file-base keeps the pre-write upload hash after later file mutation'
    );

    assert_true(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_autoincrement_bands WHERE branch_name = 'feature-birth'") > 0,
        'branch birth setup records ID-band metadata'
    );
    assert_true(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_row_identities WHERE branch_name = 'feature-birth'") > 0,
        'branch birth setup records active row identities'
    );
    $birth_run_ids = [];
    $birth_runs = new SQLite3($metadata, SQLITE3_OPEN_READWRITE);
    $birth_result = $birth_runs->query("SELECT id FROM merge_runs WHERE source_branch = 'feature-birth' AND target_branch = 'feature-birth' AND base_ref IN ('autoincrement-id-band', 'identity-capture') ORDER BY id");
    while ($birth_row = $birth_result->fetchArray(SQLITE3_ASSOC)) {
        $birth_run_ids[] = (int)$birth_row['id'];
    }
    $birth_result->finalize();
    foreach ($birth_run_ids as $birth_run_id) {
        $birth_runs->exec("INSERT INTO merge_decisions (run_id, table_name, decision, reason) VALUES ($birth_run_id, 'wp_posts', 'branch-birth-test', 'branch birth cleanup decision fixture')");
    }

    cow_merge_allocate_autoincrement_bands($db_path, $metadata, 'feature-birth-unrelated');
    cow_merge_capture_row_identities($db_path, $metadata, 'feature-birth-unrelated');
    assert_true(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_autoincrement_bands WHERE branch_name = 'feature-birth-unrelated'") > 0,
        'branch birth cleanup fixture records unrelated branch ID bands'
    );
    assert_true(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_row_identities WHERE branch_name = 'feature-birth-unrelated'") > 0,
        'branch birth cleanup fixture records unrelated active row identities'
    );
    $unrelated_run_id = (int)scalar($metadata, "SELECT id FROM merge_runs WHERE source_branch = 'feature-birth-unrelated' AND target_branch = 'feature-birth-unrelated' ORDER BY id LIMIT 1");
    $birth_runs->exec("INSERT INTO merge_decisions (run_id, table_name, decision, reason) VALUES ($unrelated_run_id, 'wp_posts', 'branch-birth-unrelated-test', 'unrelated branch birth cleanup decision fixture')");
    $birth_runs->close();

    $cleanup = cow_merge_cleanup_branch_birth_metadata($metadata, 'feature-birth');
    assert_true($cleanup['cleaned'] > 0, 'branch birth metadata cleanup reports removed rows');
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_autoincrement_bands WHERE branch_name = 'feature-birth'"),
        0,
        'branch birth metadata cleanup removes ID bands'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_row_identities WHERE branch_name = 'feature-birth'"),
        0,
        'branch birth metadata cleanup removes active row identities'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_row_identity_history WHERE branch_name = 'feature-birth'"),
        0,
        'branch birth metadata cleanup removes row identity history'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_runs WHERE source_branch = 'feature-birth'"),
        0,
        'branch birth metadata cleanup removes branch birth runs'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE decision = 'branch-birth-test' AND run_id IN (" . implode(',', $birth_run_ids) . ")"),
        0,
        'branch birth metadata cleanup removes branch birth decisions'
    );
    assert_true(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_autoincrement_bands WHERE branch_name = 'feature-birth-unrelated'") > 0,
        'branch birth metadata cleanup leaves unrelated branch ID bands intact'
    );
    assert_true(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_row_identities WHERE branch_name = 'feature-birth-unrelated'") > 0,
        'branch birth metadata cleanup leaves unrelated active row identities intact'
    );
    assert_true(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_runs WHERE source_branch = 'feature-birth-unrelated'") > 0,
        'branch birth metadata cleanup leaves unrelated branch birth runs intact'
    );
    assert_same(
        (int)scalar($metadata, "SELECT COUNT(*) FROM merge_decisions WHERE decision = 'branch-birth-unrelated-test' AND run_id = $unrelated_run_id"),
        1,
        'branch birth metadata cleanup leaves unrelated branch birth decisions intact'
    );

    $missing_band_db = $tmp . '/missing-band.sqlite';
    $missing_band_metadata = $tmp . '/.forkpress/cow/merge/missing-band-metadata.sqlite';
    create_branch_birth_db($missing_band_db);
    cow_merge_capture_row_identities($missing_band_db, $missing_band_metadata, 'feature-missing-band');
    $missing_band_cli = run_merge_cli([
        'validate-branch-birth-metadata',
        '--db', $missing_band_db,
        '--metadata-db', $missing_band_metadata,
        '--branch', 'feature-missing-band',
    ]);
    assert_true($missing_band_cli['status'] !== 0, 'branch birth validation CLI rejects missing ID bands');
    assert_true(str_contains($missing_band_cli['output'], 'AUTOINCREMENT ID band'), 'missing-band validation explains the missing branch ID bands');

    $missing_identity_db = $tmp . '/missing-identity.sqlite';
    $missing_identity_metadata = $tmp . '/.forkpress/cow/merge/missing-identity-metadata.sqlite';
    create_branch_birth_db($missing_identity_db);
    cow_merge_allocate_autoincrement_bands($missing_identity_db, $missing_identity_metadata, 'feature-missing-identity');
    assert_throws_contains(
        fn() => cow_merge_validate_branch_birth_metadata($missing_identity_db, $missing_identity_metadata, 'feature-missing-identity'),
        'row identity for plugin_keyless rowid',
        'branch birth validation rejects missing keyless row identities'
    );
} finally {
    remove_tree($tmp);
}

if ($fail) {
    echo "FAILURES: $fail\n";
    exit(1);
}
echo "COW branch birth focused tests passed ($pass assertions).\n";

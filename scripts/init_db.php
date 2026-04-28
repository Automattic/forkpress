<?php
/**
 * Initialize the BranchFS SQLite database with schema and seed data.
 *
 * Usage: php init_db.php <site.fp> [--admin-password PW]
 *
 * Admin password source (first match wins):
 *   1. --admin-password CLI arg
 *   2. FORKPRESS_ADMIN_PASSWORD env var
 *   3. random 24-char password, printed to stdout once
 */

$db_path = $argv[1] ?? __DIR__ . '/../branchfs.db';
$schema_path = __DIR__ . '/../sql/schema.sql';

// Parse optional --admin-password flag.
$admin_pw_arg = null;
for ($i = 2; $i < count($argv); $i++) {
    if ($argv[$i] === '--admin-password' && isset($argv[$i + 1])) {
        $admin_pw_arg = $argv[$i + 1];
        $i++;
    } elseif (strpos($argv[$i], '--admin-password=') === 0) {
        $admin_pw_arg = substr($argv[$i], strlen('--admin-password='));
    }
}

echo "Initializing BranchFS database at: $db_path\n";

$db = new SQLite3($db_path);
$db->exec('PRAGMA journal_mode = WAL');
$db->exec('PRAGMA foreign_keys = ON');

// Cap WAL growth under heavy write bursts (git push + multi-branch
// merges + concurrent HTTP). The default 1000-page threshold (~4 MB)
// lets the WAL balloon to many megabytes between writes; 500 keeps the
// file closer to 2 MB.
$db->exec('PRAGMA wal_autocheckpoint = 500');

$schema = file_get_contents($schema_path);
if (!$schema) {
    die("init_db:Cannot read schema from $schema_path\n");
}

$result = $db->exec($schema);
if (!$result) {
    die("init_db:" . $db->lastErrorMsg() . "\n");
}

echo "Database initialized successfully.\n";
echo "  - 'main' branch created\n";

// Test-only override: `FORKPRESS_INIT_AUTH_ENABLED=0` flips auth off at
// init time for compatibility tests. Production callers (forkpress init)
// never set this env var and keep the secure default of '1'.
$env_auth = getenv('FORKPRESS_INIT_AUTH_ENABLED');
if ($env_auth !== false && $env_auth !== '' && $env_auth !== '1') {
    $stmt = $db->prepare(
        "INSERT INTO site_config (key, value) VALUES ('auth_enabled', :v) "
      . "ON CONFLICT(key) DO UPDATE SET value = excluded.value"
    );
    $stmt->bindValue(':v', '0', SQLITE3_TEXT);
    $stmt->execute();
}

// Seed the default admin user (only if none exists yet — re-running init
// on the same file must not clobber the live admin password).
$existing_admin_count = (int)$db->querySingle("SELECT COUNT(*) FROM users");
if ($existing_admin_count === 0) {
    $admin_password = null;
    $printed_onetime = false;

    if ($admin_pw_arg !== null && $admin_pw_arg !== '') {
        $admin_password = $admin_pw_arg;
    } elseif (($env_pw = getenv('FORKPRESS_ADMIN_PASSWORD')) && $env_pw !== '') {
        $admin_password = $env_pw;
    } else {
        // Random password from /dev/urandom (printable alnum subset).
        $raw = random_bytes(18);
        $admin_password = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $printed_onetime = true;
    }

    $hash = password_hash($admin_password, PASSWORD_BCRYPT);
    $mysql_sha1 = strtolower(bin2hex(sha1(sha1($admin_password, true), true)));

    $stmt = $db->prepare(
        "INSERT INTO users (username, password_hash, mysql_sha1, role) "
      . "VALUES (:u, :h, :m, 'admin')"
    );
    $stmt->bindValue(':u', 'admin', SQLITE3_TEXT);
    $stmt->bindValue(':h', $hash, SQLITE3_TEXT);
    $stmt->bindValue(':m', $mysql_sha1, SQLITE3_TEXT);
    $stmt->execute();

    echo "  - admin user created (username: admin, role: admin)\n";
    if ($printed_onetime) {
        echo "\n";
        echo "  ==========================================================\n";
        echo "  One-time admin password (save this now, it won't reappear):\n";
        echo "  \n";
        echo "      $admin_password\n";
        echo "  \n";
        echo "  Rotate with: forkpress user remove admin && forkpress user add admin\n";
        echo "  ==========================================================\n";
    }
}

$db->close();

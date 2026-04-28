<?php
/**
 * user_admin.php — manage authentication users for a ForkPress site.
 *
 * Usage:
 *   user_admin.php add    <username> <password> [--role admin|write|read]
 *   user_admin.php list
 *   user_admin.php remove <username>
 *   user_admin.php verify <username> <password>        (exit 0 if OK, 1 otherwise)
 *   user_admin.php auth-enabled [0|1]                  (get/set the flag)
 *
 * Environment:
 *   BRANCHFS_DB   path to the .fp file (same default as branchctl.php)
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

function env_or(string $name, string $default): string {
    $v = getenv($name);
    return ($v === false || $v === '') ? $default : $v;
}

$DB_PATH = env_or('BRANCHFS_DB', '/tmp/branchfs-dev/branchfs.db');

function die_usage(?string $msg = null, int $code = 1): void {
    if ($msg !== null) fwrite(STDERR, "user_admin: $msg\n\n");
    fwrite(STDERR, <<<USAGE
Usage:
  user_admin add    <username> <password> [--role admin|write|read]
  user_admin list
  user_admin remove <username>
  user_admin verify <username> <password>
  user_admin auth-enabled [0|1]

Flags:
  --db <path>   override BRANCHFS_DB

USAGE);
    exit($code);
}

function parse_args(array $argv): array {
    $pos = [];
    $flags = [];
    for ($i = 1; $i < count($argv); $i++) {
        $a = $argv[$i];
        if (str_starts_with($a, '--')) {
            $name = substr($a, 2);
            $next = $argv[$i + 1] ?? null;
            if ($next !== null && !str_starts_with($next, '-')) {
                $flags[$name] = $next;
                $i++;
            } else {
                $flags[$name] = true;
            }
        } else {
            $pos[] = $a;
        }
    }
    return [$pos, $flags];
}

[$pos, $flags] = parse_args($argv);
$cmd = $pos[0] ?? null;
if ($cmd === null || $cmd === 'help' || $cmd === '-h' || $cmd === '--help') die_usage(null, 0);
if (isset($flags['db'])) $DB_PATH = (string)$flags['db'];

if (!file_exists($DB_PATH)) {
    fwrite(STDERR, "user_admin: .fp not found: $DB_PATH\n");
    exit(2);
}

function open_db(string $path): SQLite3 {
    $db = new SQLite3($path, SQLITE3_OPEN_READWRITE);
    $db->busyTimeout(15000);
    // Ensure users / site_config exist even on a pre-existing DB that
    // was created before the auth feature shipped.
    $db->exec(<<<SQL
CREATE TABLE IF NOT EXISTS users (
    username      TEXT PRIMARY KEY,
    password_hash TEXT NOT NULL,
    mysql_sha1    TEXT,
    role          TEXT NOT NULL CHECK(role IN ('admin','write','read')),
    created_at    TEXT DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS site_config (
    key   TEXT PRIMARY KEY,
    value TEXT
);
SQL);
    return $db;
}

function valid_username(string $u): bool {
    return (bool)preg_match('/^[A-Za-z0-9_\-\.]{1,63}$/', $u);
}

function valid_role(string $r): bool {
    return in_array($r, ['admin', 'write', 'read'], true);
}

function set_user(SQLite3 $db, string $username, string $password, string $role): void {
    $hash = password_hash($password, PASSWORD_BCRYPT);
    // Retained for older .fp files that still have mysql_sha1 in the users
    // table. Git push auth uses password_hash/password_verify.
    $mysql_sha1 = strtolower(bin2hex(sha1(sha1($password, true), true)));

    $s = $db->prepare(
        "INSERT INTO users (username, password_hash, mysql_sha1, role) "
      . "VALUES (:u, :h, :m, :r) "
      . "ON CONFLICT(username) DO UPDATE SET "
      . "  password_hash = excluded.password_hash, "
      . "  mysql_sha1    = excluded.mysql_sha1, "
      . "  role          = excluded.role"
    );
    $s->bindValue(':u', $username, SQLITE3_TEXT);
    $s->bindValue(':h', $hash, SQLITE3_TEXT);
    $s->bindValue(':m', $mysql_sha1, SQLITE3_TEXT);
    $s->bindValue(':r', $role, SQLITE3_TEXT);
    $s->execute();
}

switch ($cmd) {

case 'add': {
    $username = $pos[1] ?? die_usage("`add` needs <username>");
    $password = $pos[2] ?? die_usage("`add` needs <password>");
    $role     = (string)($flags['role'] ?? 'write');
    if (!valid_username($username)) die_usage("invalid username: $username");
    if (!valid_role($role))         die_usage("invalid role: $role (must be admin|write|read)");

    $db = open_db($DB_PATH);
    set_user($db, $username, $password, $role);
    echo "user_admin: user '$username' added/updated (role=$role)\n";
    break;
}

case 'list': {
    $db = open_db($DB_PATH);
    $r = $db->query("SELECT username, role, created_at FROM users ORDER BY username");
    printf("%-20s  %-6s  %s\n", "USERNAME", "ROLE", "CREATED");
    printf("%s\n", str_repeat('-', 60));
    $n = 0;
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        printf("%-20s  %-6s  %s\n", $row['username'], $row['role'], $row['created_at'] ?? '');
        $n++;
    }
    if ($n === 0) echo "  (no users)\n";
    break;
}

case 'remove': {
    $username = $pos[1] ?? die_usage("`remove` needs <username>");
    if (!valid_username($username)) die_usage("invalid username: $username");
    $db = open_db($DB_PATH);
    $s = $db->prepare("DELETE FROM users WHERE username = :u");
    $s->bindValue(':u', $username, SQLITE3_TEXT);
    $s->execute();
    $c = $db->changes();
    echo $c > 0 ? "user_admin: removed '$username'\n" : "user_admin: no user '$username'\n";
    break;
}

case 'verify': {
    $username = $pos[1] ?? die_usage("`verify` needs <username>");
    $password = $pos[2] ?? die_usage("`verify` needs <password>");
    $db = open_db($DB_PATH);
    $s = $db->prepare("SELECT password_hash, role FROM users WHERE username = :u");
    $s->bindValue(':u', $username, SQLITE3_TEXT);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        echo "FAIL: no such user\n";
        exit(1);
    }
    if (!password_verify($password, $row['password_hash'])) {
        echo "FAIL: wrong password\n";
        exit(1);
    }
    echo "OK role=" . $row['role'] . "\n";
    break;
}

case 'auth-enabled': {
    $db = open_db($DB_PATH);
    if (isset($pos[1])) {
        $v = $pos[1] === '1' ? '1' : '0';
        $s = $db->prepare("INSERT INTO site_config (key, value) VALUES ('auth_enabled', :v) "
                        . "ON CONFLICT(key) DO UPDATE SET value = excluded.value");
        $s->bindValue(':v', $v, SQLITE3_TEXT);
        $s->execute();
        echo "user_admin: auth_enabled = $v\n";
    } else {
        $v = (string)$db->querySingle("SELECT value FROM site_config WHERE key='auth_enabled'");
        echo ($v !== '' ? $v : '0') . "\n";
    }
    break;
}

default:
    die_usage("unknown command: $cmd");
}
exit(0);

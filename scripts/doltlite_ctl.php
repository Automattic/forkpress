<?php
/**
 * Local control plane for ForkPress Doltlite sites.
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

$db_path = getenv('FORKPRESS_DOLTLITE_DB') ?: '';
if ($db_path === '') {
    fwrite(STDERR, "doltlite_ctl: FORKPRESS_DOLTLITE_DB is required\n");
    exit(2);
}

require_once __DIR__ . '/git_server/helpers.php';

function dl_validate_branch(string $name): void {
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,62}$/', $name)) {
        fwrite(STDERR, "doltlite_ctl: invalid branch name: $name\n");
        exit(2);
    }
}

function dl_open(string $db_path, ?string $branch = null): SQLite3 {
    $db = new SQLite3($db_path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $db->busyTimeout(5000);
    @$db->exec('PRAGMA foreign_keys=ON');
    if ($branch !== null) {
        $result = @$db->querySingle('SELECT dolt_checkout(' . dl_sql_string($branch) . ')');
        if ($result === false || $result === null) {
            throw new RuntimeException($db->lastErrorMsg() ?: "failed to checkout branch $branch");
        }
    }
    return $db;
}

function dl_scalar(SQLite3 $db, string $sql): string {
    $value = $db->querySingle($sql);
    return $value === null ? '' : (string)$value;
}

function dl_sql_string(string $value): string {
    return "'" . SQLite3::escapeString($value) . "'";
}

function dl_has_doltlite(SQLite3 $db): bool {
    try {
        $engine = @$db->querySingle('SELECT doltlite_engine()');
        return is_string($engine) && $engine !== '';
    } catch (Throwable $e) {
        return false;
    }
}

function dl_require_doltlite(SQLite3 $db): void {
    if (!dl_has_doltlite($db)) {
        fwrite(STDERR, "doltlite_ctl: bundled PHP SQLite is not backed by Doltlite; rebuild the runtime with Doltlite support\n");
        exit(3);
    }
}

function dl_branch_exists(string $db_path, string $branch): bool {
    $db = dl_open($db_path);
    dl_require_doltlite($db);
    $s = $db->prepare('SELECT COUNT(*) FROM dolt_branches WHERE name = :n');
    $s->bindValue(':n', $branch, SQLITE3_TEXT);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_NUM);
    return ((int)($row[0] ?? 0)) > 0;
}

function dl_branch_id(SQLite3 $db, string $branch): int {
    $id = git_fs_branch_id($db, $branch);
    if ($id > 0) {
        return $id;
    }
    $s = $db->prepare('INSERT OR IGNORE INTO branches (name, parent_branch) VALUES (:n, NULL)');
    $s->bindValue(':n', $branch, SQLITE3_TEXT);
    $s->execute();
    return git_fs_branch_id($db, $branch);
}

function dl_commit(SQLite3 $db, string $message): string {
    $msg = dl_sql_string($message);
    $result = @$db->querySingle("SELECT dolt_commit('-A', '-m', $msg)");
    if ($result === false || $result === null) {
        $err = $db->lastErrorMsg();
        if (stripos($err, 'nothing to commit') !== false || stripos($err, 'no changes') !== false) {
            return 'no changes';
        }
        throw new RuntimeException($err ?: 'dolt_commit failed');
    }
    return (string)$result;
}

$cmd = $argv[1] ?? 'help';

try {
    switch ($cmd) {
        case 'exists': {
            $branch = $argv[2] ?? '';
            dl_validate_branch($branch);
            echo dl_branch_exists($db_path, $branch) ? "yes\n" : "no\n";
            exit(0);
        }

        case 'list': {
            $db = dl_open($db_path);
            dl_require_doltlite($db);
            $rows = $db->query("SELECT name FROM dolt_branches ORDER BY CASE WHEN name='main' THEN 0 ELSE 1 END, name");
            while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
                echo $row['name'] . "\n";
            }
            exit(0);
        }

        case 'create': {
            $branch = $argv[2] ?? '';
            dl_validate_branch($branch);
            $from = 'main';
            for ($i = 3; $i < count($argv); $i++) {
                if ($argv[$i] === '--from') {
                    $from = $argv[$i + 1] ?? '';
                    $i++;
                } else {
                    throw new RuntimeException("unsupported create argument: {$argv[$i]}");
                }
            }
            dl_validate_branch($from);
            if (dl_branch_exists($db_path, $branch)) {
                throw new RuntimeException("branch already exists: $branch");
            }
            if (!dl_branch_exists($db_path, $from)) {
                throw new RuntimeException("source branch does not exist: $from");
            }

            $source = dl_open($db_path, $from);
            dl_require_doltlite($source);
            $source->querySingle('SELECT dolt_branch(' . dl_sql_string($branch) . ')');

            $target = dl_open($db_path, $branch);
            $ins = $target->prepare('INSERT OR IGNORE INTO branches (name, parent_branch) VALUES (:n, :p)');
            $ins->bindValue(':n', $branch, SQLITE3_TEXT);
            $ins->bindValue(':p', $from, SQLITE3_TEXT);
            $ins->execute();

            $bid = dl_branch_id($target, $branch);
            if (!git_fs_last_commit($target, $bid)) {
                git_fs_record_snapshot($target, $bid, "Initial snapshot for $branch");
            }
            $hash = dl_commit($target, "Create ForkPress branch $branch from $from");
            echo "doltlite: branched '$from' -> '$branch'\n";
            echo "doltlite: commit $hash\n";
            exit(0);
        }

        case 'commit': {
            $branch = $argv[2] ?? 'main';
            dl_validate_branch($branch);
            $message = 'ForkPress Doltlite commit';
            for ($i = 3; $i < count($argv); $i++) {
                if ($argv[$i] === '--message' || $argv[$i] === '-m') {
                    $message = $argv[$i + 1] ?? $message;
                    $i++;
                } else {
                    throw new RuntimeException("unsupported commit argument: {$argv[$i]}");
                }
            }
            $db = dl_open($db_path, $branch);
            dl_require_doltlite($db);
            $bid = dl_branch_id($db, $branch);
            git_fs_record_snapshot($db, $bid, $message);
            $hash = dl_commit($db, $message);
            echo "doltlite: committed '$branch': $hash\n";
            exit(0);
        }

        case 'status': {
            $branch = $argv[2] ?? 'main';
            dl_validate_branch($branch);
            $db = dl_open($db_path, $branch);
            dl_require_doltlite($db);
            echo "doltlite: status of '$branch'\n";
            $rows = $db->query('SELECT table_name, staged, status FROM dolt_status ORDER BY table_name');
            $any = false;
            while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
                $any = true;
                echo "  {$row['table_name']}\t{$row['status']}\tstaged={$row['staged']}\n";
            }
            if (!$any) echo "  clean\n";
            exit(0);
        }

        case 'log': {
            $branch = $argv[2] ?? 'main';
            dl_validate_branch($branch);
            $db = dl_open($db_path, $branch);
            dl_require_doltlite($db);
            $rows = $db->query('SELECT commit_hash, date, message FROM dolt_log');
            while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
                echo substr($row['commit_hash'], 0, 12) . "\t{$row['date']}\t{$row['message']}\n";
            }
            exit(0);
        }

        case 'merge': {
            $from = $argv[2] ?? '';
            dl_validate_branch($from);
            $into = '';
            for ($i = 3; $i < count($argv); $i++) {
                if ($argv[$i] === '--into') {
                    $into = $argv[$i + 1] ?? '';
                    $i++;
                } else {
                    throw new RuntimeException("unsupported merge argument: {$argv[$i]}");
                }
            }
            dl_validate_branch($into);
            $db = dl_open($db_path, $into);
            dl_require_doltlite($db);
            $result = dl_scalar($db, 'SELECT dolt_merge(' . dl_sql_string($from) . ')');
            echo "doltlite: merged '$from' into '$into': $result\n";
            exit(0);
        }

        case 'diff': {
            $a = $argv[2] ?? 'main';
            $b = $argv[3] ?? 'HEAD';
            dl_validate_branch($a);
            if ($b !== 'HEAD') dl_validate_branch($b);
            $db = dl_open($db_path);
            dl_require_doltlite($db);
            $rows = $db->query('SELECT * FROM dolt_diff_summary(' . dl_sql_string($a) . ', ' . dl_sql_string($b) . ')');
            while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
                echo implode("\t", array_map('strval', $row)) . "\n";
            }
            exit(0);
        }

        case 'delete': {
            $branch = $argv[2] ?? '';
            dl_validate_branch($branch);
            if ($branch === 'main') {
                throw new RuntimeException("cannot delete main");
            }
            $db = dl_open($db_path);
            dl_require_doltlite($db);
            $result = dl_scalar($db, 'SELECT dolt_branch(' . dl_sql_string('-d') . ', ' . dl_sql_string($branch) . ')');
            echo "doltlite: deleted '$branch': $result\n";
            exit(0);
        }

        case 'gc': {
            $db = dl_open($db_path);
            dl_require_doltlite($db);
            echo "doltlite: " . dl_scalar($db, 'SELECT dolt_gc()') . "\n";
            exit(0);
        }

        default:
            fwrite(STDERR, "usage: doltlite_ctl.php list|exists|create|commit|status|log|merge|diff|delete|gc ...\n");
            exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "doltlite_ctl: " . $e->getMessage() . "\n");
    exit(1);
}

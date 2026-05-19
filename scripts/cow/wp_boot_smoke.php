<?php
declare(strict_types=1);

/**
 * Smoke-test a materialized branch root by serving its front page through
 * WordPress' normal index.php entrypoint.
 *
 * Usage:
 *   php wp_boot_smoke.php <branch-root> <host[:port]> <debug-log> [branch]
 */

$branch_root = rtrim((string)($argv[1] ?? ''), "/\\");
$host = (string)($argv[2] ?? 'wp.localhost:18080');
$debug_log = (string)($argv[3] ?? '');
$branch = (string)($argv[4] ?? '');

function forkpress_boot_smoke_write_log(string $debug_log, string $message): void {
    if ($debug_log === '') {
        return;
    }
    @file_put_contents($debug_log, $message . "\n", FILE_APPEND);
}

function forkpress_boot_smoke_fail(string $message, string $debug_log = '', int $status = 1): void {
    $line = 'forkpress: ' . $message;
    fwrite(STDERR, $line . "\n");
    forkpress_boot_smoke_write_log($debug_log, '[' . date('c') . '] ' . $line);
    exit($status);
}

if ($branch_root === '' || !is_dir($branch_root)) {
    forkpress_boot_smoke_fail("branch root missing or not a directory: $branch_root", $debug_log);
}
if (!is_file($branch_root . '/index.php') || !is_file($branch_root . '/wp-load.php')) {
    forkpress_boot_smoke_fail("branch root is not a WordPress tree: $branch_root", $debug_log);
}
if ($host === '') {
    forkpress_boot_smoke_fail('preview host must not be empty', $debug_log);
}

if ($debug_log !== '') {
    @ini_set('log_errors', '1');
    @ini_set('error_log', $debug_log);
}
@ini_set('display_errors', 'stderr');
@set_time_limit(20);
error_reporting(E_ALL);

$host_without_port = $host;
$port = '80';
if (preg_match('/^(.+):([0-9]+)$/', $host, $matches)) {
    $host_without_port = $matches[1];
    $port = $matches[2];
}

if ($branch !== '') {
    $_SERVER['FORKPRESS_BRANCH'] = $branch;
    putenv('FORKPRESS_BRANCH=' . $branch);
}

$_SERVER = array_merge($_SERVER ?? [], [
    'HTTP_HOST'       => $host,
    'REQUEST_URI'     => '/',
    'REQUEST_METHOD'  => 'GET',
    'SERVER_NAME'     => $host_without_port,
    'SERVER_PORT'     => $port,
    'SERVER_PROTOCOL' => 'HTTP/1.1',
    'DOCUMENT_ROOT'   => $branch_root,
    'SCRIPT_FILENAME' => $branch_root . '/index.php',
    'SCRIPT_NAME'     => '/index.php',
    'PHP_SELF'        => '/index.php',
    'REMOTE_ADDR'     => '127.0.0.1',
]);

register_shutdown_function(static function () use ($debug_log, $host): void {
    $error = error_get_last();
    if (!is_array($error)) {
        return;
    }
    $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array((int)($error['type'] ?? 0), $fatal_types, true)) {
        return;
    }
    $message = sprintf(
        "forkpress: branch preview PHP fatal while booting http://%s/: %s in %s:%s",
        $host,
        (string)($error['message'] ?? 'unknown fatal error'),
        (string)($error['file'] ?? 'unknown file'),
        (string)($error['line'] ?? 'unknown line')
    );
    fwrite(STDERR, $message . "\n");
    forkpress_boot_smoke_write_log($debug_log, '[' . date('c') . '] ' . $message);
});

$previous_cwd = getcwd();
if ($previous_cwd === false || !chdir($branch_root)) {
    forkpress_boot_smoke_fail("failed to enter branch root: $branch_root", $debug_log);
}

ob_start();
try {
    require $branch_root . '/index.php';
    $body = (string)ob_get_clean();
} catch (Throwable $throwable) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    forkpress_boot_smoke_fail(
        sprintf(
            'branch preview PHP exception while booting http://%s/: %s in %s:%s',
            $host,
            $throwable->getMessage(),
            $throwable->getFile(),
            $throwable->getLine()
        ),
        $debug_log
    );
} finally {
    if ($previous_cwd !== false) {
        @chdir($previous_cwd);
    }
}

if (stripos($body, 'There has been a critical error on this website') !== false) {
    $excerpt = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');
    $message = "branch preview returned WordPress' critical-error page for http://$host/.";
    if ($debug_log !== '') {
        $message .= " Check $debug_log for the PHP fatal.";
    }
    if ($excerpt !== '') {
        $message .= ' Page excerpt: ' . substr($excerpt, 0, 300);
    }
    forkpress_boot_smoke_fail($message, $debug_log);
}

echo "  preview:   booted http://$host/\n";

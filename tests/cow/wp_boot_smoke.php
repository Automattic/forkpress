<?php
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/forkpress_wp_boot_smoke_' . getmypid();
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

function make_root(string $base, string $name, string $index_php): string {
    $root = $base . '/' . $name;
    if (!mkdir($root, 0755, true) && !is_dir($root)) {
        throw new RuntimeException("failed to create $root");
    }
    file_put_contents($root . '/wp-load.php', "<?php\n");
    file_put_contents($root . '/index.php', $index_php);
    return $root;
}

function run_smoke(string $root, string $debug_log): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__DIR__ . '/../../scripts/cow/wp_boot_smoke.php') . ' '
        . escapeshellarg($root) . ' '
        . escapeshellarg('feature.wp.localhost:18080') . ' '
        . escapeshellarg($debug_log) . ' '
        . escapeshellarg('feature');
    $pipes = [];
    $proc = proc_open($cmd, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException('failed to start smoke process');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($proc);
    return [$status, (string)$stdout, (string)$stderr];
}

function assert_contains(string $haystack, string $needle, string $label): void {
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException("$label missing '$needle' in:\n$haystack");
    }
}

try {
    $debug_log = $tmp . '/debug.log';

    $ok_root = make_root($tmp, 'ok', "<?php echo '<html>ok</html>'; if (getenv('FORKPRESS_BRANCH') !== 'feature') { throw new RuntimeException('missing branch env'); }\n");
    [$status, $stdout, $stderr] = run_smoke($ok_root, $debug_log);
    if ($status !== 0) {
        throw new RuntimeException("ok smoke failed with $status\nSTDOUT:\n$stdout\nSTDERR:\n$stderr");
    }
    assert_contains($stdout, 'preview:   booted http://feature.wp.localhost:18080/', 'ok stdout');

    $critical_root = make_root($tmp, 'critical', "<?php echo '<html><body>There has been a critical error on this website.</body></html>';\n");
    [$status, $stdout, $stderr] = run_smoke($critical_root, $debug_log);
    if ($status === 0) {
        throw new RuntimeException("critical page smoke unexpectedly passed\nSTDOUT:\n$stdout\nSTDERR:\n$stderr");
    }
    assert_contains($stderr, "WordPress' critical-error page", 'critical stderr');

    $exception_root = make_root($tmp, 'exception', "<?php throw new RuntimeException('branch plugin failed');\n");
    [$status, $stdout, $stderr] = run_smoke($exception_root, $debug_log);
    if ($status === 0) {
        throw new RuntimeException("exception smoke unexpectedly passed\nSTDOUT:\n$stdout\nSTDERR:\n$stderr");
    }
    assert_contains($stderr, 'branch preview PHP exception while booting', 'exception stderr');
    assert_contains($stderr, 'branch plugin failed', 'exception stderr');

    $fatal_root = make_root($tmp, 'fatal', "<?php trigger_error('hard branch fatal', E_USER_ERROR);\n");
    [$status, $stdout, $stderr] = run_smoke($fatal_root, $debug_log);
    if ($status === 0) {
        throw new RuntimeException("fatal smoke unexpectedly passed\nSTDOUT:\n$stdout\nSTDERR:\n$stderr");
    }
    assert_contains($stderr, 'branch preview PHP fatal while booting', 'fatal stderr');

    $logged = file_get_contents($debug_log) ?: '';
    assert_contains($logged, 'branch preview returned WordPress', 'debug log critical');
    assert_contains($logged, 'branch preview PHP fatal while booting', 'debug log fatal');
} catch (Throwable $e) {
    cleanup($tmp);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

cleanup($tmp);
echo "wp_boot_smoke.php passed\n";

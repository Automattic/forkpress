<?php
/**
 * static-php-cli patch hook: inject branchfs as a builtin PHP extension.
 *
 * Runs at the `after-php-extract` patch point (see BuilderBase::emitPatchPoint).
 * Copies our ext/branchfs.{c,h} into php-src/ext/branchfs/, writes a config.m4,
 * and re-runs ./buildconf --force so PHP's configure script picks up the new
 * extension and honors --enable-branchfs.
 *
 * Invoked via: spc build --with-added-patch=scripts/spc-patch-branchfs.php
 */

// @phpstan-ignore-next-line -- runs inside spc's BuilderBase method via require
if ($this->getPatchPoint() !== 'after-php-extract') {
    return;
}

$repo_root = realpath(__DIR__ . '/..');
if ($repo_root === false) {
    throw new RuntimeException('forkpress patch: cannot resolve repo root');
}

$php_src = SOURCE_PATH . '/php-src';
$dest    = $php_src . '/ext/branchfs';

if (!is_dir($dest) && !mkdir($dest, 0755, true) && !is_dir($dest)) {
    throw new RuntimeException("forkpress patch: failed to create {$dest}");
}

foreach (['branchfs.c', 'branchfs.h'] as $file) {
    $src = "{$repo_root}/ext/{$file}";
    if (!is_file($src)) {
        throw new RuntimeException("forkpress patch: missing source {$src}");
    }
    if (!copy($src, "{$dest}/{$file}")) {
        throw new RuntimeException("forkpress patch: failed to copy {$src}");
    }
}

$config_m4 = <<<'M4'
dnl Injected by forkpress scripts/spc-patch-branchfs.php.
dnl Registers branchfs as a builtin PHP extension. sqlite3 symbols are
dnl already linked into the PHP binary (via --with-sqlite3), so branchfs
dnl just #includes <sqlite3.h> and reuses them.
PHP_ARG_ENABLE(branchfs, whether to enable branchfs support,
[  --enable-branchfs       Enable the branchfs extension])

if test "$PHP_BRANCHFS" != "no"; then
  PHP_NEW_EXTENSION(branchfs, branchfs.c, $ext_shared)
  PHP_SUBST(BRANCHFS_SHARED_LIBADD)
fi
M4;
file_put_contents("{$dest}/config.m4", $config_m4);

// Regenerate configure so --enable-branchfs is recognized.
$buildconf_cmd = sprintf('cd %s && ./buildconf --force 2>&1', escapeshellarg($php_src));
exec($buildconf_cmd, $output, $rc);
if ($rc !== 0) {
    throw new RuntimeException(
        "forkpress patch: buildconf failed (rc={$rc}):\n" . implode("\n", $output)
    );
}

logger()->info('forkpress patch: branchfs injected into php-src and configure regenerated');

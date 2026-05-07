<?php
/**
 * TODO3 #12 audit trail + hostile-review finding #2 (audit binding).
 *
 * Audit-log helpers. `audit_log_write` binds the row to a Principal that
 * was resolved at CLI startup (see principal.php) — NOT to the
 * freely-forgeable FORKPRESS_ACTOR env var as before.
 *
 * Failure semantics (finding #2): an audit-log write failure is NOT
 * silently swallowed. If the INSERT fails we emit a diagnostic to STDERR
 * and call exit(5) so the caller sees a non-zero exit code. The audit
 * trail is a security-relevant record; masking its corruption defeats
 * the feature.
 */

require_once __DIR__ . '/principal.php';

if (!function_exists('audit_log_write')) {

/**
 * Register the Principal resolved for this CLI invocation. Every
 * subsequent `audit_log_write` call reads from this registry — there is
 * no way to override the actor via env or arg at write time.
 */
function audit_log_set_principal(Principal $p): void
{
    $GLOBALS['__forkpress_principal'] = $p;
}

function audit_log_current_principal(): ?Principal
{
    return $GLOBALS['__forkpress_principal'] ?? null;
}

/**
 * Write one row to audit_log bound to the current Principal.
 *
 * Failures raise to STDERR and exit non-zero. The only soft-exit path
 * is "audit_log table doesn't exist yet" during a very early migration
 * — fs_migrate always creates the table, so in practice this branch is
 * dead for any reachable code path.
 */
function audit_log_write(SQLite3 $db, string $action, ?string $target = null,
                         ?array $details = null): void {
    $principal = audit_log_current_principal();
    if ($principal === null) {
        // Safety net: a caller forgot to resolve a principal. This should
        // never happen in production code; make it loud.
        fwrite(STDERR, "audit_log_write: no principal resolved — "
                     . "caller must run principal_resolve() first\n");
        exit(5);
    }

    // If audit_log hasn't been created yet we're inside a bootstrap path
    // that fires before fs_migrate() populated the schema. That's rare
    // and safe to skip — no trail can be written to a non-existent table.
    $tbl_exists = (int)$db->querySingle(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='audit_log'"
    );
    if ($tbl_exists === 0) return;

    $s = $db->prepare(
        "INSERT INTO audit_log (actor, action, target, details) "
      . "VALUES (:a, :act, :t, :d)"
    );
    if (!$s) {
        fwrite(STDERR, "audit_log_write: prepare failed: "
                     . $db->lastErrorMsg() . "\n");
        exit(5);
    }
    $s->bindValue(':a',   $principal->name, SQLITE3_TEXT);
    $s->bindValue(':act', $action,          SQLITE3_TEXT);
    if ($target === null) {
        $s->bindValue(':t', null, SQLITE3_NULL);
    } else {
        $s->bindValue(':t', $target, SQLITE3_TEXT);
    }
    $s->bindValue(':d',
        $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
        $details === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $ok = $s->execute();
    if ($ok === false) {
        fwrite(STDERR, "audit_log_write: execute failed: "
                     . $db->lastErrorMsg() . "\n");
        exit(5);
    }
}

}

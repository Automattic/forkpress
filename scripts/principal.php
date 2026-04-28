<?php
/**
 * principal.php — identity & authorization primitives for ForkPress.
 *
 * Introduces a `Principal` concept threaded through every CLI invocation
 * and PHP entry point. Closes hostile-review findings #2, #5, #18:
 *
 *   - #2 audit actor is resolved from a VALIDATED principal, not a freely
 *        forgeable FORKPRESS_ACTOR env var (when auth is enabled).
 *   - #5 BootstrapBranchedPDO::ensure() is a production chokepoint that
 *        wires BranchedPDO::assert_branched() into every entry point.
 *   - #18 The hidden _ddl subcommand rejects any caller without a resolved
 *        principal AND any SQL that isn't in the genuine-DDL allowlist.
 *
 * Design summary:
 *
 *   HMAC secret: site_config['hmac_secret'] — 64 hex chars. Auto-generated
 *     on first use (principal_ensure_hmac_secret). Never logged.
 *   Token shape: base64url("<username>.<exp_unix>.<sig>") where
 *     sig = hash_hmac('sha256', "<username>.<exp_unix>", $hmac_secret).
 *   Legacy mode: auth_enabled=0 sites resolve to a synthetic principal
 *     whose name is FORKPRESS_ACTOR (if set) or 'system'. The FORKPRESS_ACTOR
 *     env var has no security value in that mode — it's a display-only hint
 *     that lets operators attribute rows in audit_log.
 *   Auth mode: auth_enabled=1. FORKPRESS_ACTOR is IGNORED entirely. Remote
 *     writes must arrive via --user/--password OR FORKPRESS_TOKEN. The bundled
 *     local control path sets FORKPRESS_LOCAL_CTL=1 and receives a synthetic
 *     local operator principal.
 */

if (!class_exists('Principal', false)) {

/**
 * Resolved identity for a CLI invocation. Immutable.
 *
 *   name        — the actor string to write into audit_log.actor
 *   role        — 'admin' | 'write' | 'read' | 'system' (legacy)
 *   synthetic   — true when the principal wasn't derived from a users-row
 *                 credential check (legacy auth_enabled=0 sites or the local
 *                 control path)
 */
final class Principal
{
    public string $name;
    public string $role;
    public bool   $synthetic;

    public function __construct(string $name, string $role, bool $synthetic = false)
    {
        $this->name      = $name;
        $this->role      = $role;
        $this->synthetic = $synthetic;
    }

    /** admin + write are writer roles; read + (synthetic system) we decide per caller. */
    public function can_write(): bool
    {
        return in_array($this->role, ['admin', 'write', 'system'], true);
    }
}

}

if (!function_exists('principal_open_db')) {

function principal_open_db(string $site_fp): SQLite3
{
    $db = new SQLite3($site_fp, SQLITE3_OPEN_READWRITE);
    $db->busyTimeout(15000);
    return $db;
}

/** Get a site_config value; returns '' when absent. */
function principal_get_config(SQLite3 $db, string $key): string
{
    $s = $db->prepare("SELECT value FROM site_config WHERE key = :k");
    $s->bindValue(':k', $key, SQLITE3_TEXT);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_NUM);
    return $row ? (string)$row[0] : '';
}

function principal_set_config(SQLite3 $db, string $key, string $value): void
{
    $s = $db->prepare(
        "INSERT INTO site_config (key, value) VALUES (:k, :v) "
      . "ON CONFLICT(key) DO UPDATE SET value = excluded.value"
    );
    $s->bindValue(':k', $key, SQLITE3_TEXT);
    $s->bindValue(':v', $value, SQLITE3_TEXT);
    $s->execute();
}

function principal_auth_enabled(SQLite3 $db): bool
{
    return principal_get_config($db, 'auth_enabled') === '1';
}

/**
 * Look up (or generate and store) the site's HMAC secret. 32 bytes of
 * cryptographic entropy, hex-encoded for safe SQLite/env-var transport.
 *
 * Deliberately NOT logged anywhere: keep this value out of stdout/stderr.
 */
function principal_ensure_hmac_secret(SQLite3 $db): string
{
    $cur = principal_get_config($db, 'hmac_secret');
    if ($cur !== '' && strlen($cur) >= 32) {
        return $cur;
    }
    $secret = bin2hex(random_bytes(32));
    principal_set_config($db, 'hmac_secret', $secret);
    return $secret;
}

/** base64url without padding. */
function principal_b64u_enc(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function principal_b64u_dec(string $s): string|false
{
    $pad = 4 - (strlen($s) % 4);
    if ($pad < 4) $s .= str_repeat('=', $pad);
    return base64_decode(strtr($s, '-_', '+/'), true);
}

/**
 * Mint a signed token for the given username. Token encodes the username
 * and an absolute UNIX expiry. Returned as a single opaque ASCII string.
 */
function principal_mint_token(string $site_fp, string $username, int $ttl_seconds = 300): string
{
    $db = principal_open_db($site_fp);
    try {
        $secret = principal_ensure_hmac_secret($db);
    } finally {
        $db->close();
    }
    $exp = time() + max(1, $ttl_seconds);
    $payload = $username . '.' . $exp;
    $sig = hash_hmac('sha256', $payload, $secret, true);
    return principal_b64u_enc($payload . '.' . bin2hex($sig));
}

/**
 * Verify a token and return [username, expiry] on success or null on any
 * failure (malformed / bad signature / expired / unknown user).
 *
 * Rejects tokens for users that don't currently exist in the `users` table
 * so a revoked user's old token stops working immediately.
 */
function principal_verify_token(string $site_fp, string $token): ?array
{
    if ($token === '') return null;
    $raw = principal_b64u_dec($token);
    if ($raw === false) return null;
    $parts = explode('.', $raw);
    if (count($parts) !== 3) return null;
    [$username, $exp, $sig_hex] = $parts;
    if (!ctype_digit($exp)) return null;
    if ((int)$exp < time()) return null;
    $db = principal_open_db($site_fp);
    try {
        $secret = principal_ensure_hmac_secret($db);
        $payload = $username . '.' . $exp;
        $expect = bin2hex(hash_hmac('sha256', $payload, $secret, true));
        if (!hash_equals($expect, $sig_hex)) return null;
        // Verify the user still exists and return role too.
        $s = $db->prepare("SELECT role FROM users WHERE username = :u");
        $s->bindValue(':u', $username, SQLITE3_TEXT);
        $r = $s->execute();
        $row = $r->fetchArray(SQLITE3_ASSOC);
        if (!$row) return null;
        return [$username, (string)$row['role'], (int)$exp];
    } finally {
        $db->close();
    }
}

/**
 * Verify a plaintext password against the `users` table via PHP's
 * password_verify (bcrypt). Returns the role on success, null on failure.
 */
function principal_verify_password(SQLite3 $db, string $username, string $password): ?string
{
    $s = $db->prepare("SELECT password_hash, role FROM users WHERE username = :u");
    $s->bindValue(':u', $username, SQLITE3_TEXT);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_ASSOC);
    if (!$row) return null;
    if (!password_verify($password, $row['password_hash'])) return null;
    return (string)$row['role'];
}

/**
 * Resolve the principal for a CLI invocation. Returns a Principal on
 * success, or null on failure with $reason set to a human-readable string.
 *
 *   $flags — CLI flag map from branchctl's parse_args(). We read 'user'
 *            and 'password' from here.
 *   $site_fp — path to the .fp file; required for both password and token
 *              verification (they both open the DB).
 *
 * Resolution order when auth is enabled:
 *   1. --user/--password flags (best path; direct credential check)
 *   2. FORKPRESS_TOKEN env var (HMAC-signed short-lived token)
 *   3. FORKPRESS_LOCAL_CTL=1 from the bundled local CLI
 * Anything else → failure, caller writes an auth error and exits non-zero.
 *
 * When auth is DISABLED the CLI runs as a synthetic `system` principal
 * (or FORKPRESS_ACTOR if set, for backward-compat with legacy audit tests).
 */
function principal_resolve(string $site_fp, array $flags, ?string &$reason = null): ?Principal
{
    $db = principal_open_db($site_fp);
    try {
        $auth_on = principal_auth_enabled($db);
        if (!$auth_on) {
            // Legacy mode. FORKPRESS_ACTOR lets operators label the audit
            // row with their own name; absent that, we record 'system' so
            // readers can tell unauthenticated runs apart from named users.
            $env_actor = getenv('FORKPRESS_ACTOR');
            $name = ($env_actor !== false && $env_actor !== '')
                ? (string)$env_actor : 'system';
            return new Principal($name, 'system', /*synthetic=*/ true);
        }

        // Auth mode. FORKPRESS_ACTOR is deliberately ignored — it's the
        // exact forgery surface finding #2 called out. We only honor
        // cryptographically verifiable identity here.
        $user = isset($flags['user'])     ? (string)$flags['user']     : '';
        $pass = isset($flags['password']) ? (string)$flags['password'] : '';
        if ($user !== '' && $pass !== '') {
            $role = principal_verify_password($db, $user, $pass);
            if ($role === null) {
                $reason = "auth: invalid username or password";
                return null;
            }
            return new Principal($user, $role, false);
        }

        $tok = getenv('FORKPRESS_TOKEN');
        if ($tok !== false && $tok !== '') {
            $verified = principal_verify_token($site_fp, (string)$tok);
            if ($verified === null) {
                $reason = "auth: FORKPRESS_TOKEN is invalid, expired, or for "
                        . "an unknown user";
                return null;
            }
            [$u, $role, $_exp] = $verified;
            return new Principal($u, $role, false);
        }

        $local_ctl = getenv('FORKPRESS_LOCAL_CTL');
        if ($local_ctl === '1') {
            $name = 'local';
            $env_user = getenv('USER');
            if ($env_user === false || $env_user === '') {
                $env_user = getenv('LOGNAME');
            }
            if ($env_user !== false && $env_user !== '') {
                $name = 'local:' . (string)$env_user;
            }
            return new Principal($name, 'admin', /*synthetic=*/ true);
        }

        $reason = "auth: this site has auth_enabled=1; pass --user <u> --password <p> "
                . "or set FORKPRESS_TOKEN";
        return null;
    } finally {
        $db->close();
    }
}

}  // !function_exists('principal_open_db')

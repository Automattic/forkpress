#!/bin/bash
# ============================================================
# SQLite-clone git protocol end-to-end test (acceptance A..N)
#
# Validates that a git clone produces a self-booting WordPress
# install backed by a single SQLite file (via the WordPress
# SQLite Database Integration plugin), and that edits to that
# SQLite file — whether raw, or via wp-cli — round-trip back to
# the remote on git push.
#
# Expects the dev stack to be running (e2e/dev.sh).
# Exit non-zero on any failure.
# ============================================================
set -u
set -o pipefail

E2E_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_DIR="$(cd "$E2E_DIR/.." && pwd)"
PHP_PORT="${PHP_PORT:-18080}"
DOLT_PORT="${DOLT_PORT:-13306}"
CLONE_DIR="/tmp/wp-sqlite-clone-$$"
BOOT_PORT=9081
PASS=0
FAIL=0
SIDE_BRANCHES=("sqlite-marketing")
BRANCHCTL="$BASE_DIR/bin/branchctl"
PHP_BIN="${PHP_BIN:-php}"
BOOT_PID=""

# Prefer a PHP binary that has pdo_sqlite — without it, the
# SQLite integration drop-in refuses to serve the clone.
for cand in "$PHP_BIN" "php" "/usr/bin/php" "/usr/local/bin/php"; do
    if [ -x "$(command -v "$cand" 2>/dev/null)" ] && "$cand" -r 'exit(extension_loaded("pdo_sqlite") && extension_loaded("sqlite3") ? 0 : 1);' 2>/dev/null; then
        PHP_BIN="$cand"; break
    fi
done

stop_clone_boot() {
    if [ -n "$BOOT_PID" ]; then
        kill "$BOOT_PID" 2>/dev/null || true
        # wait can hang if the child already exited, so bound it.
        for _ in 1 2 3 4 5; do
            kill -0 "$BOOT_PID" 2>/dev/null || break
            sleep 0.5
        done
        kill -9 "$BOOT_PID" 2>/dev/null || true
        wait "$BOOT_PID" 2>/dev/null || true
        BOOT_PID=""
    fi
    # Belt-and-suspenders: anything still sitting on BOOT_PORT from a
    # previous incomplete run would block start_clone_boot otherwise.
    pkill -f "php -S 127.0.0.1:$BOOT_PORT" 2>/dev/null || true
    sleep 1
}

start_clone_boot() {
    local doc_root="$1"
    stop_clone_boot
    # auto_prepend a stub that disables WP cron — otherwise the cloned
    # WP's wp-cron fires during boot and does a *loopback HTTP request*
    # to the site_url stored in wp_options (which is the REMOTE dev
    # stack on :18080). That remote hit mutates Dolt mid-test, drifts
    # the server's main ref away from what we cloned, and a subsequent
    # push on that clone lands as a non-fast-forward.
    local prepend="/tmp/sqlite-clone-prepend-$$.php"
    cat > "$prepend" <<'EOF'
<?php
if (!defined('DISABLE_WP_CRON')) define('DISABLE_WP_CRON', true);
if (!defined('WP_HTTP_BLOCK_EXTERNAL')) define('WP_HTTP_BLOCK_EXTERNAL', true);
EOF
    "$PHP_BIN" -d "auto_prepend_file=$prepend" -S 127.0.0.1:"$BOOT_PORT" -t "$doc_root" > /tmp/sqlite-clone-boot.log 2>&1 &
    BOOT_PID=$!
    # Wait for the port to open.
    for i in $(seq 1 30); do
        if "$PHP_BIN" -r "\$s=@fsockopen('127.0.0.1',$BOOT_PORT,\$e,\$m,0.5); if(\$s){fclose(\$s);exit(0);} exit(1);" 2>/dev/null; then
            return 0
        fi
        if ! kill -0 "$BOOT_PID" 2>/dev/null; then
            echo "  php -S died early:"
            tail -10 /tmp/sqlite-clone-boot.log
            return 1
        fi
        sleep 1
    done
    echo "  php -S never opened port"
    return 1
}

# Restore blogname on main back to the baseline bootstrap value so reruns
# behave like fresh ones (matches test_git_protocol.sh's discipline).
revert_main_to_known_state() {
    "$PHP_BIN" -r '
        $c = new mysqli("127.0.0.1","root","","wordpress/main",'"$DOLT_PORT"');
        if ($c->connect_error) exit(0);
        $c->query("UPDATE wp_options SET option_value = \"Branched WP Dev\" WHERE option_name = \"blogname\"");
        while ($c->next_result()) { $r=$c->store_result(); if($r) $r->free(); }
        $c->query("UPDATE wp_posts SET post_title = \"Hello world!\" WHERE ID = 1");
        while ($c->next_result()) { $r=$c->store_result(); if($r) $r->free(); }
        $c->query("CALL DOLT_ADD(\"-A\")");
        while ($c->next_result()) { $r=$c->store_result(); if($r) $r->free(); }
        mysqli_report(MYSQLI_REPORT_OFF);
        @$c->query("CALL DOLT_COMMIT(\"-am\", \"test_sqlite_clone: revert to known state\")");
        while ($c->next_result()) { $r=$c->store_result(); if($r) $r->free(); }
        $c->close();
    ' 2>/dev/null
}

cleanup() {
    stop_clone_boot
    rm -rf "$CLONE_DIR" "$CLONE_DIR-2"
    for b in "${SIDE_BRANCHES[@]}"; do
        "$BRANCHCTL" delete "$b" > /dev/null 2>&1 || true
    done
    revert_main_to_known_state
}
trap cleanup EXIT

pre_clean() {
    rm -rf "$CLONE_DIR" "$CLONE_DIR-2"
    for b in "${SIDE_BRANCHES[@]}"; do
        "$BRANCHCTL" delete "$b" > /dev/null 2>&1 || true
    done
    revert_main_to_known_state
}

pre_clean

step() {
    local letter="$1"; shift
    echo ""
    echo "=== $letter: $* ==="
}

pass() { echo "  ✓ PASS"; PASS=$((PASS+1)); }
fail() { echo "  ✗ FAIL: $*"; FAIL=$((FAIL+1)); }

# Wait for dev server.
echo "Waiting for dev server on port $PHP_PORT..."
for i in $(seq 1 30); do
    if curl -s -o /dev/null "http://127.0.0.1:$PHP_PORT/" 2>/dev/null; then break; fi
    if [ "$i" -eq 30 ]; then echo "FATAL: dev server not responding"; exit 1; fi
    sleep 1
done

run_acceptance_suite() {
    local run_label="$1"

    # A. git clone
    step "A [$run_label]" "git clone http://wp.localhost:$PHP_PORT/site.git $CLONE_DIR"
    if git clone "http://127.0.0.1:$PHP_PORT/site.git" "$CLONE_DIR" 2>&1 | tail -3; then
        pass
    else
        fail "git clone exited non-zero"
        return
    fi

    # B. .ht.sqlite is a valid SQLite file with WP tables
    step "B [$run_label]" ".ht.sqlite exists and has wp_* tables"
    SQLITE_FILE="$CLONE_DIR/wordpress/wp-content/database/.ht.sqlite"
    if [ ! -f "$SQLITE_FILE" ]; then
        fail "$SQLITE_FILE missing"
    else
        TABLE_OK=$("$PHP_BIN" -r '
            $s = new SQLite3($argv[1], SQLITE3_OPEN_READONLY);
            $want = ["wp_options","wp_posts","wp_users","wp_postmeta"];
            $have = [];
            $r = $s->query("SELECT name FROM sqlite_master WHERE type=\"table\"");
            while ($row = $r->fetchArray(SQLITE3_ASSOC)) $have[] = $row["name"];
            foreach ($want as $t) {
                if (!in_array($t, $have)) { echo "missing $t"; exit(1); }
                $cnt = $s->querySingle("SELECT COUNT(*) FROM `$t`");
                echo "$t:$cnt\n";
            }
        ' "$SQLITE_FILE" 2>&1)
        if [ $? -eq 0 ] && echo "$TABLE_OK" | grep -q 'wp_options:'; then
            echo "$TABLE_OK" | sed 's/^/  /'
            pass
        else
            fail "sqlite tables missing or empty: $TABLE_OK"
        fi
    fi

    # C. wp-content/db.php is the sqlite drop-in
    step "C [$run_label]" "wp-content/db.php references sqlite plugin"
    if [ -f "$CLONE_DIR/wordpress/wp-content/db.php" ] \
       && grep -q "sqlite-database-integration" "$CLONE_DIR/wordpress/wp-content/db.php"; then
        pass
    else
        fail "db.php missing or doesn't reference sqlite plugin"
    fi

    # D. plugins/sqlite-database-integration/ exists
    step "D [$run_label]" "plugins/sqlite-database-integration/ is the real plugin"
    PLUGIN_DIR="$CLONE_DIR/wordpress/wp-content/plugins/sqlite-database-integration"
    if [ -d "$PLUGIN_DIR" ] && grep -q "Plugin Name: SQLite Database Integration" "$PLUGIN_DIR/load.php" 2>/dev/null; then
        pass
    else
        fail "plugin directory missing or load.php malformed"
    fi

    # E. db-meta.json is valid JSON
    step "E [$run_label]" "db-meta.json is valid and has expected keys"
    META_JSON="$CLONE_DIR/db-meta.json"
    if [ -f "$META_JSON" ]; then
        META_OK=$("$PHP_BIN" -r '
            $m = json_decode(file_get_contents($argv[1]), true);
            if (!is_array($m)) { echo "not json"; exit(1); }
            foreach (["dolt_commit_hash","branch","exported_at","schema_version"] as $k) {
                if (!isset($m[$k])) { echo "missing: $k"; exit(1); }
            }
            echo "ok v=" . $m["schema_version"] . " branch=" . $m["branch"];
        ' "$META_JSON" 2>&1)
        if [ $? -eq 0 ]; then echo "  $META_OK"; pass; else fail "$META_OK"; fi
    else
        fail "db-meta.json missing"
    fi

    # F. no legacy db/schema.sql / db/*.ndjson
    step "F [$run_label]" "no legacy db/schema.sql or db/*.ndjson"
    if [ -d "$CLONE_DIR/db" ] || ls "$CLONE_DIR"/db/*.ndjson 2>/dev/null | head -1 | grep -q .; then
        fail "legacy db/ layout leaked through"
    else
        pass
    fi

    # G. clone boots and serves HTTP 200 with <title>
    step "G [$run_label]" "php -S on clone serves HTTP 200 + <title>"
    if start_clone_boot "$CLONE_DIR/wordpress"; then
        # Single combined curl: header + body in one shot so status
        # and body always refer to the same request.
        CURL_OUT=$(curl -s --max-time 30 -o /tmp/curl-body.out \
                        -w "%{http_code}" "http://127.0.0.1:$BOOT_PORT/" \
                    || echo ERR)
        STATUS="$CURL_OUT"
        BODY=$(cat /tmp/curl-body.out 2>/dev/null || true)
        TITLE=$(echo "$BODY" | grep -oE '<title>[^<]+</title>' | head -1 || true)
        if [ "$STATUS" = "200" ] && [ -n "$TITLE" ]; then
            echo "  status=$STATUS title=$TITLE"
            pass
        else
            fail "status=$STATUS title='$TITLE' body-len=${#BODY}"
            echo "  --- first 400 bytes of body ---"
            echo "$BODY" | head -c 400 | sed 's/^/    /'
            echo ""
            echo "  --- boot log tail ---"
            tail -20 /tmp/sqlite-clone-boot.log | sed 's/^/    /'
        fi
    else
        fail "could not start php -S on clone"
    fi

    # H. UPDATE wp_options via raw SQLite3 inside the clone
    step "H [$run_label]" "UPDATE wp_options.blogname via raw SQLite3 in the clone"
    stop_clone_boot  # release the lock
    if "$PHP_BIN" -r '
        $s = new SQLite3($argv[1]);
        $s->exec("UPDATE wp_options SET option_value = \"SQLite-pushed\" WHERE option_name = \"blogname\"");
        $s->close();
    ' "$SQLITE_FILE"; then
        NEW_VALUE=$("$PHP_BIN" -r '
            $s = new SQLite3($argv[1], SQLITE3_OPEN_READONLY);
            echo $s->querySingle("SELECT option_value FROM wp_options WHERE option_name = \"blogname\"");
        ' "$SQLITE_FILE")
        if [ "$NEW_VALUE" = "SQLite-pushed" ]; then
            pass
        else
            fail "blogname didn't update, got: $NEW_VALUE"
        fi
    else
        fail "SQLite UPDATE failed"
    fi

    # I. Re-boot and confirm the edit surfaces via HTTP
    step "I [$run_label]" "edit round-trips to local boot"
    if start_clone_boot "$CLONE_DIR/wordpress"; then
        BODY=$(curl -s http://127.0.0.1:"$BOOT_PORT"/)
        TITLE=$(echo "$BODY" | grep -oE '<title>[^<]+</title>' | head -1)
        echo "  local title: $TITLE"
        if echo "$TITLE" | grep -q "SQLite-pushed"; then
            pass
        else
            fail "local clone still shows old blogname"
        fi
    else
        fail "could not reboot clone"
    fi
    stop_clone_boot

    # J. git commit + push
    # Intentionally start from a fresh clone — the server rebuilds
    # its ref chain on every request, and any WP activity the
    # previous steps may have triggered (OPcache, WP cron firing
    # during G/I boots) could have drifted its view of main. A
    # fresh clone takes the ref as of *now*, so we're pushing
    # against the server's current state.
    step "J [$run_label]" "commit + push to remote"
    stop_clone_boot
    rm -rf "$CLONE_DIR"
    if ! git clone "http://127.0.0.1:$PHP_PORT/site.git" "$CLONE_DIR" >/dev/null 2>&1; then
        fail "re-clone for J failed"
        return
    fi
    SQLITE_FILE="$CLONE_DIR/wordpress/wp-content/database/.ht.sqlite"
    "$PHP_BIN" -r '
        $s = new SQLite3($argv[1]);
        $s->exec("UPDATE wp_options SET option_value = \"SQLite-pushed\" WHERE option_name = \"blogname\"");
        $s->close();
    ' "$SQLITE_FILE"
    (cd "$CLONE_DIR" && git config user.email "sqlite-test@local" && git config user.name "sqlite-test") >/dev/null
    if (cd "$CLONE_DIR" && git commit -am "Edit via SQLite ($run_label)") >/dev/null 2>&1 \
       && (cd "$CLONE_DIR" && git push "http://admin:admin@127.0.0.1:$PHP_PORT/site.git" main 2>&1) | tail -3; then
        pass
    else
        fail "commit or push failed"
        tail -5 /tmp/branchfs-dev/php-errors.log 2>/dev/null | sed 's/^/    /'
    fi

    # K. remote serves the new title
    step "K [$run_label]" "remote shows SQLite-pushed title"
    sleep 1
    REMOTE_TITLE=$(curl -s -H "Host: wp.localhost" "http://127.0.0.1:$PHP_PORT/" | grep -oE '<title>[^<]+</title>' | head -1)
    echo "  remote title: $REMOTE_TITLE"
    if echo "$REMOTE_TITLE" | grep -q "SQLite-pushed"; then
        pass
    else
        fail "remote title doesn't reflect push"
    fi

    # L. branchctl log shows the paired fs_commit + git message
    step "L [$run_label]" "branchctl log shows the push"
    LOG_OUT=$("$BRANCHCTL" log main -n 3 2>&1 || true)
    echo "$LOG_OUT" | sed 's/^/    /'
    if echo "$LOG_OUT" | grep -q "Edit via SQLite"; then
        pass
    else
        fail "branchctl log missing paired commit"
    fi

    # M. Simulate an admin UI edit that goes through the SQLite plugin's
    # query translator (not a raw SQLite write). Re-clone first so the
    # local HEAD lines up with the server's post-J state — the server
    # rebuilds commit hashes on every push (the commit message embeds
    # `Dolt-Commit: <hash>`), so the clone we've been reusing has a
    # diverged HEAD and pull --rebase hits a conflict-shaped mirage.
    step "M [$run_label]" "wp-cli / SQLite-driver post update, push, verify on remote"
    stop_clone_boot
    rm -rf "$CLONE_DIR"
    if ! git clone "http://127.0.0.1:$PHP_PORT/site.git" "$CLONE_DIR" >/dev/null 2>&1; then
        fail "re-clone for M failed"
        return
    fi
    SQLITE_FILE="$CLONE_DIR/wordpress/wp-content/database/.ht.sqlite"

    WP_CLI=""
    for cand in wp /usr/local/bin/wp "$HOME/.local/bin/wp" ./wp-cli.phar; do
        if command -v "$cand" >/dev/null 2>&1; then WP_CLI="$cand"; break; fi
    done
    if [ -z "$WP_CLI" ] && [ -f /tmp/wp-cli.phar ]; then
        WP_CLI="$PHP_BIN /tmp/wp-cli.phar"
    fi
    if [ -z "$WP_CLI" ]; then
        if curl -sL --max-time 30 https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /tmp/wp-cli.phar 2>/dev/null; then
            chmod +x /tmp/wp-cli.phar
            WP_CLI="$PHP_BIN /tmp/wp-cli.phar"
        fi
    fi

    DRIVER_OK=""
    if [ -n "$WP_CLI" ]; then
        echo "    using wp-cli: $WP_CLI"
        (cd "$CLONE_DIR/wordpress" && $WP_CLI --allow-root post update 1 --post_title="Admin-Edited Post" 2>&1) | sed 's/^/    /' || true
        DRIVER_OK="wp-cli"
    else
        # No wp-cli: equivalent — go through the same plugin translator
        # (WP_SQLite_Driver) directly. This still exercises the exact
        # code path that an admin-UI edit hits when WP runs under
        # SQLite.
        echo "    no wp-cli on PATH, using WP_SQLite_Driver directly"
        "$PHP_BIN" -r '
            define("ABSPATH", $argv[1] . "/");
            define("WP_CONTENT_DIR", $argv[1] . "/wp-content");
            define("DB_ENGINE", "sqlite");
            define("DB_NAME", "wordpress");
            define("FQDBDIR", $argv[1] . "/wp-content/database/");
            define("FQDB", $argv[1] . "/wp-content/database/.ht.sqlite");
            define("WP_CLI", true);
            require_once $argv[2] . "/wp-includes/database/load.php";
            $pdo = new PDO("sqlite:" . FQDB);
            $conn = new WP_SQLite_Connection(["pdo" => $pdo]);
            $d = new WP_SQLite_Driver($conn, "wordpress");
            $d->query("UPDATE wp_posts SET post_title = \"Admin-Edited Post\" WHERE ID = 1");
        ' "$CLONE_DIR/wordpress" "$CLONE_DIR/wordpress/wp-content/plugins/sqlite-database-integration" 2>&1 | sed 's/^/    /' || true
        DRIVER_OK="driver"
    fi

    LOCAL_POST_TITLE=$("$PHP_BIN" -r '
        $s = new SQLite3($argv[1], SQLITE3_OPEN_READONLY);
        echo $s->querySingle("SELECT post_title FROM wp_posts WHERE ID = 1");
    ' "$SQLITE_FILE" 2>&1)
    echo "    local post_title ($DRIVER_OK): $LOCAL_POST_TITLE"
    if [ "$LOCAL_POST_TITLE" != "Admin-Edited Post" ]; then
        fail "$DRIVER_OK edit didn't persist locally"
    else
        (cd "$CLONE_DIR" && git config user.email "m@m" && git config user.name "m") >/dev/null
        (cd "$CLONE_DIR" && git commit -am "wp-cli/SQLite-driver post edit" --allow-empty) >/dev/null 2>&1
        if (cd "$CLONE_DIR" && git push "http://admin:admin@127.0.0.1:$PHP_PORT/site.git" main 2>&1 | tail -3) | sed 's/^/    /'; then
            sleep 1
            REMOTE_POST_TITLE=$("$PHP_BIN" -r '
                $c = new mysqli("127.0.0.1","root","","wordpress/main",'"$DOLT_PORT"');
                $r = $c->query("SELECT post_title FROM wp_posts WHERE ID = 1");
                echo $r->fetch_row()[0];
            ' 2>&1)
            echo "    remote post_title: $REMOTE_POST_TITLE"
            if [ "$REMOTE_POST_TITLE" = "Admin-Edited Post" ]; then
                pass
            else
                fail "remote post_title didn't reflect push"
            fi
        else
            fail "push failed for admin-UI-style edit"
        fi
    fi

    rm -rf "$CLONE_DIR"
}

# ---- First pass ----
run_acceptance_suite "run 1"

# N. Idempotency: rerun the whole thing and verify nothing leaks.
# Reset main so the second run isn't polluted by run 1's pushed state.
revert_main_to_known_state
for b in "${SIDE_BRANCHES[@]}"; do
    "$BRANCHCTL" delete "$b" > /dev/null 2>&1 || true
done

step "N" "re-run acceptance suite from clean slate (idempotency)"
echo "  (this exercises pre-clean + cleanup symmetry)"
run_acceptance_suite "run 2"

echo ""
echo "============================================================"
echo "  RESULTS: $PASS passed, $FAIL failed"
echo "============================================================"
if [ "$FAIL" -gt 0 ]; then
    exit 1
fi
echo "ALL TESTS PASSED"
exit 0

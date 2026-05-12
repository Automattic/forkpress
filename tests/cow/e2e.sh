#!/usr/bin/env bash
set -euo pipefail

BIN="${1:-target/x86_64-unknown-linux-musl/release/forkpress}"
if [ ! -x "$BIN" ]; then
  echo "missing forkpress binary: $BIN" >&2
  exit 1
fi

TMP="$(mktemp -d /tmp/forkpress-cow-e2e.XXXXXX)"
STATE="$TMP/state"
WORK="$TMP/site"
WORK_DIR="$WORK/.forkpress"
PORT="${FORKPRESS_E2E_PORT:-18383}"
export FORKPRESS_STATE_DIR="$STATE"
mkdir -p "$STATE"

cleanup() {
  "$BIN" stop --work-dir "$WORK_DIR" >/dev/null 2>&1 || true
}
trap cleanup EXIT

dump_if_exists() {
  local path="$1"
  if [ -f "$path" ]; then
    echo "--- $path ---" >&2
    tail -n 120 "$path" >&2 || true
  fi
}

on_error() {
  local status=$?
  echo "FAIL cow materialized strategy e2e at line ${BASH_LINENO[0]}: ${BASH_COMMAND}" >&2
  dump_if_exists "$TMP/git-created.html"
  dump_if_exists "$TMP/git-multi-delete.out"
  dump_if_exists "$TMP/git-delete.out"
  dump_if_exists "$TMP/git-delete-main.out"
  dump_if_exists "$TMP/keyless-init.json"
  dump_if_exists "$TMP/keyless-source-reuse.json"
  dump_if_exists "$TMP/keyless-target-edit.json"
  dump_if_exists "$TMP/keyless-main-after-merge.json"
  dump_if_exists "$TMP/keyless-merge.out"
  dump_if_exists "$TMP/keyless-conflicts.out"
  dump_if_exists "$TMP/keyless-resolve.out"
  dump_if_exists "$TMP/keyless-resolution-review.out"
  dump_if_exists "$TMP/keyless-resolution-audit.out"
  dump_if_exists "$TMP/keyless-resolution-status.json"
  dump_if_exists "$TMP/merge-audit.out"
  dump_if_exists "$TMP/merge-audit.json"
  dump_if_exists "$TMP/merge-target-kept-files.out"
  dump_if_exists "$TMP/merge-target-kept.json"
  dump_if_exists "$TMP/bad-slash.out"
  dump_if_exists "$TMP/storage-status-final.out"
  dump_if_exists "$TMP/storage-compact.out"
  dump_if_exists "$TMP/storage-status-detached.out"
  if [ -d "$WORK" ]; then
    echo "--- materialized branches ---" >&2
    find "$WORK" -maxdepth 1 -mindepth 1 -type d -print >&2 || true
  fi
  if [ -d "$WORK_DIR" ]; then
    echo "--- forkpress logs ---" >&2
    "$BIN" logs --work-dir "$WORK_DIR" --file all -n 180 >&2 || true
  fi
  exit "$status"
}
trap on_error ERR

log_step() {
  echo "==> $*"
}

branch_host() {
  if [ "$1" = "main" ]; then
    printf 'wp.localhost:%s' "$PORT"
  else
    printf '%s.wp.localhost:%s' "$1" "$PORT"
  fi
}

create_branch_post() {
  local branch="$1"
  local title="$2"
  local host
  host="$(branch_host "$branch")"
  local html="$TMP/${branch}-post-new.html"
  local json="$TMP/${branch}-rest-save.json"

  curl -sS -H "Host: $host" \
    "http://127.0.0.1:$PORT/wp-admin/post-new.php" \
    -o "$html"

  local nonce
  nonce="$(node - <<'NODE' "$html"
const fs = require('fs');
const html = fs.readFileSync(process.argv[2], 'utf8');
const match = html.match(/wp\.apiFetch\.createNonceMiddleware\(\s*"([^"]+)"\s*\)/)
  || html.match(/var wpApiSettings = .*?"nonce":"([^"]+)"/s);
if (!match) process.exit(2);
console.log(match[1]);
NODE
)"

  local http
  http="$(
    curl -sS -o "$json" -w '%{http_code}' \
      -H "Host: $host" \
      -H "Content-Type: application/json" \
      -H "X-WP-Nonce: $nonce" \
      --data "{\"title\":\"$title\",\"content\":\"Saved from ForkPress COW reset e2e\",\"status\":\"publish\"}" \
      "http://127.0.0.1:$PORT/index.php?rest_route=/wp/v2/posts"
  )"
  if [ "$http" != "201" ]; then
    echo "REST save on $branch returned $http" >&2
    cat "$json" >&2
    "$BIN" logs --work-dir "$WORK_DIR" --file all -n 160 >&2 || true
    exit 1
  fi
}

keyless_runtime_request() {
  local branch="$1"
  local action="$2"
  local out="$3"
  local host
  host="$(branch_host "$branch")"

  local http
  http="$(
    curl -sS -o "$out" -w '%{http_code}' \
      -H "Host: $host" \
      "http://127.0.0.1:$PORT/?forkpress_e2e_keyless=$action"
  )"
  if [ "$http" != "200" ]; then
    echo "keyless runtime action $action on $branch returned $http" >&2
    cat "$out" >&2
    "$BIN" logs --work-dir "$WORK_DIR" --file all -n 180 >&2 || true
    exit 1
  fi
}

log_step "init COW site"
"$BIN" init --work-dir "$WORK_DIR" --admin-password admin
test -d "$WORK/.forkpress"
test -d "$WORK/main"
test -f "$WORK/main/wp-load.php"
test ! -e "$WORK/.forkpress/cow/branches/main"
grep -E 'file_view = "(reflink|file-copy|macos-apfs-sparsebundle)"' "$WORK_DIR/site.toml" >/dev/null
grep -F 'strategy = "cow"' "$WORK_DIR/site.toml" >/dev/null
"$BIN" doctor storage --work-dir "$WORK_DIR" > "$TMP/storage-doctor.out"
grep -F "ForkPress storage capability report" "$TMP/storage-doctor.out" >/dev/null
"$BIN" storage status --work-dir "$WORK_DIR" > "$TMP/storage-status.out"
grep -F "ForkPress storage status" "$TMP/storage-status.out" >/dev/null
log_step "start server"
"$BIN" serve --work-dir "$WORK_DIR" --port "$PORT" --root-host wp.localhost --workers 1
"$BIN" server list | grep -F "$WORK_DIR" >/dev/null

log_step "create CLI branch"
"$BIN" branch --work-dir "$WORK_DIR" create feature-cow > "$TMP/branch-create.out"
grep -F "feature-cow.wp.localhost:$PORT" "$TMP/branch-create.out" >/dev/null
test -d "$WORK/feature-cow"
echo "feature only" > "$WORK/feature-cow/wp-content/forkpress-branch.txt"
test ! -e "$WORK/main/wp-content/forkpress-branch.txt"

curl -sS -H "Host: feature-cow.wp.localhost:$PORT" \
  "http://127.0.0.1:$PORT/wp-admin/post-new.php" \
  -o "$TMP/branch-post-new.html"
grep -F "Branch: feature-cow" "$TMP/branch-post-new.html" >/dev/null
grep -F "wp.apiFetch.createNonceMiddleware" "$TMP/branch-post-new.html" >/dev/null
grep -F 'id="menu-dashboard"' "$TMP/branch-post-new.html" >/dev/null
grep -F 'id="menu-posts"' "$TMP/branch-post-new.html" >/dev/null

REST_NONCE="$(node - <<'NODE' "$TMP/branch-post-new.html"
const fs = require('fs');
const html = fs.readFileSync(process.argv[2], 'utf8');
const match = html.match(/wp\.apiFetch\.createNonceMiddleware\(\s*"([^"]+)"\s*\)/)
  || html.match(/var wpApiSettings = .*?"nonce":"([^"]+)"/s);
if (!match) process.exit(2);
console.log(match[1]);
NODE
)"

TITLE="COW backend saved $(date +%s)"
HTTP="$(
  curl -sS -o "$TMP/rest-save.json" -w '%{http_code}' \
    -H "Host: feature-cow.wp.localhost:$PORT" \
    -H "Content-Type: application/json" \
    -H "X-WP-Nonce: $REST_NONCE" \
    --data "{\"title\":\"$TITLE\",\"content\":\"Saved from ForkPress COW e2e\",\"status\":\"publish\"}" \
    "http://127.0.0.1:$PORT/index.php?rest_route=/wp/v2/posts"
)"
if [ "$HTTP" != "201" ]; then
  echo "REST save returned $HTTP" >&2
  cat "$TMP/rest-save.json" >&2
  "$BIN" logs --work-dir "$WORK_DIR" --file all -n 160 >&2 || true
  exit 1
fi

curl -sS -H "Host: feature-cow.wp.localhost:$PORT" \
  "http://127.0.0.1:$PORT/wp-admin/edit.php" \
  -o "$TMP/edit.html"
grep -F "$TITLE" "$TMP/edit.html" >/dev/null

"$BIN" branch --work-dir "$WORK_DIR" list | grep -F "feature-cow" >/dev/null

log_step "clone Git view"
"$BIN" clone "http://127.0.0.1:$PORT/site.git" "$TMP/checkout"
test -f "$TMP/checkout/wordpress/wp-load.php"
test -f "$TMP/checkout/database.sql"
test ! -e "$TMP/checkout/wordpress/wp-content/database/.ht.sqlite"
test ! -e "$TMP/checkout/wordpress/wp-content/database/wp-debug.log"

git -C "$TMP/checkout" fetch origin '+refs/heads/*:refs/remotes/origin/*'
git -C "$TMP/checkout" checkout -B feature-cow origin/feature-cow
grep -F "$TITLE" "$TMP/checkout/database.sql" >/dev/null
echo "changed through git" > "$TMP/checkout/wordpress/wp-content/cow-git.txt"
mkdir -p "$TMP/checkout/wordpress/wp-content/database"
echo "private through git" > "$TMP/checkout/wordpress/wp-content/database/pushed-private.txt"
log_step "push Git update to existing branch"
"$BIN" commit "$TMP/checkout" --message "test cow git push"
test -f "$WORK/feature-cow/wp-content/cow-git.txt"
grep -F "changed through git" "$WORK/feature-cow/wp-content/cow-git.txt" >/dev/null
test ! -e "$WORK/feature-cow/wp-content/database/pushed-private.txt"
test ! -e "$WORK/main/wp-content/cow-git.txt"
test "$(git -C "$TMP/checkout" rev-parse HEAD)" = "$(git -C "$TMP/checkout" rev-parse refs/remotes/origin/feature-cow)"
STATUS="$(git -C "$TMP/checkout" status --porcelain)"
if [ -n "$STATUS" ]; then
  echo "forkpress commit left feature-cow checkout dirty after server normalization:" >&2
  echo "$STATUS" >&2
  exit 1
fi
test ! -e "$TMP/checkout/wordpress/wp-content/database/pushed-private.txt"

git -C "$TMP/checkout" checkout -B git-created origin/main
printf "created through git\n" > "$TMP/checkout/wordpress/wp-content/git-created.txt"
printf "\n-- ignored git-created database.sql edit\n" >> "$TMP/checkout/database.sql"
log_step "push Git-created branch"
"$BIN" commit "$TMP/checkout" --message "create cow branch through git"
test -f "$WORK/git-created/wp-load.php"
test -f "$WORK/git-created/wp-content/git-created.txt"
grep -F "created through git" "$WORK/git-created/wp-content/git-created.txt" >/dev/null
test ! -e "$WORK/main/wp-content/git-created.txt"
test ! -e "$WORK/git-created/database.sql"
test "$(git -C "$TMP/checkout" rev-parse HEAD)" = "$(git -C "$TMP/checkout" rev-parse refs/remotes/origin/git-created)"
test "$(git -C "$TMP/checkout" rev-parse --abbrev-ref --symbolic-full-name '@{upstream}')" = "origin/git-created"
STATUS="$(git -C "$TMP/checkout" status --porcelain)"
if [ -n "$STATUS" ]; then
  echo "forkpress commit left git-created checkout dirty after server normalization:" >&2
  echo "$STATUS" >&2
  exit 1
fi
if grep -F "ignored git-created database.sql edit" "$TMP/checkout/database.sql" >/dev/null; then
  echo "forkpress commit left local database.sql edit behind after server normalization" >&2
  exit 1
fi
"$BIN" branch --work-dir "$WORK_DIR" list | grep -F "git-created" >/dev/null
curl -sS -H "Host: git-created.wp.localhost:$PORT" \
  "http://127.0.0.1:$PORT/" \
  -o "$TMP/git-created.html"
grep -F "Branch: git-created" "$TMP/git-created.html" >/dev/null
grep -F "Branch not found" "$TMP/git-created.html" && exit 1

log_step "reject multi-branch Git delete without mutation"
if git -C "$TMP/checkout" push origin --delete git-created feature-cow > "$TMP/git-multi-delete.out" 2>&1; then
  echo "multi-branch delete unexpectedly succeeded" >&2
  cat "$TMP/git-multi-delete.out" >&2
  exit 1
fi
if ! grep -F "ForkPress accepts one branch update per push" "$TMP/git-multi-delete.out" >/dev/null; then
  grep -E "remote rejected|failed to push|atomic push failed" "$TMP/git-multi-delete.out" >/dev/null || {
    cat "$TMP/git-multi-delete.out" >&2
    exit 1
  }
fi
test -d "$WORK/git-created"
test -d "$WORK/feature-cow"

log_step "delete Git-created branch"
git -C "$TMP/checkout" push origin --delete git-created > "$TMP/git-delete.out" 2>&1
test ! -e "$WORK/git-created"
if "$BIN" branch --work-dir "$WORK_DIR" list | grep -F "git-created" >/dev/null; then
  echo "git-created branch still listed after remote delete" >&2
  exit 1
fi
curl -sS -H "Host: git-created.wp.localhost:$PORT" \
  "http://127.0.0.1:$PORT/" \
  -o "$TMP/git-created-deleted.html"
grep -F "Branch not found" "$TMP/git-created-deleted.html" >/dev/null
log_step "reject main branch Git delete"
if git -C "$TMP/checkout" push origin --delete main > "$TMP/git-delete-main.out" 2>&1; then
  echo "main branch delete unexpectedly succeeded" >&2
  cat "$TMP/git-delete-main.out" >&2
  exit 1
fi
grep -F "refusing to delete the main COW branch" "$TMP/git-delete-main.out" >/dev/null
test -d "$WORK/main"
git -C "$TMP/checkout" fetch origin main

log_step "reject invalid branch ref without partial mutation"
git -C "$TMP/checkout" checkout -B bad/slash origin/main
printf "bad slash branch\n" > "$TMP/checkout/wordpress/wp-content/bad-slash.txt"
git -C "$TMP/checkout" branch mixed-valid origin/main
if (
  cd "$TMP/checkout"
  git push origin HEAD:refs/heads/bad/slash mixed-valid:refs/heads/mixed-valid
) > "$TMP/bad-slash.out" 2>&1; then
  echo "slash branch push unexpectedly succeeded" >&2
  cat "$TMP/bad-slash.out" >&2
  exit 1
fi
grep -E "unsupported COW branch name|failed to push" "$TMP/bad-slash.out" >/dev/null
test ! -e "$WORK/bad"
test ! -e "$WORK/mixed-valid"
test ! -e "$WORK_DIR/cow/git/refs/heads/bad"
test ! -e "$WORK_DIR/cow/git/refs/heads/mixed-valid"

git -C "$TMP/checkout" checkout -B git-created-after-reject origin/main
git -C "$TMP/checkout" reset --hard origin/main
git -C "$TMP/checkout" clean -fd
printf "created after reject\n" > "$TMP/checkout/wordpress/wp-content/git-created-after-reject.txt"
log_step "create branch after rejected ref"
"$BIN" commit "$TMP/checkout" --message "create cow branch after rejected ref"
test -f "$WORK/git-created-after-reject/wp-content/git-created-after-reject.txt"
test ! -e "$WORK/git-created-after-reject/wp-content/bad-slash.txt"

log_step "reset branch from source"
"$BIN" branch --work-dir "$WORK_DIR" create reset-source
"$BIN" branch --work-dir "$WORK_DIR" create reset-target
echo "source reset file" > "$WORK/reset-source/wp-content/reset-source.txt"
echo "target reset file" > "$WORK/reset-target/wp-content/reset-target.txt"
RESET_SOURCE_TITLE="Reset source $(date +%s)"
RESET_TARGET_TITLE="Reset target $(date +%s)"
create_branch_post reset-source "$RESET_SOURCE_TITLE"
create_branch_post reset-target "$RESET_TARGET_TITLE"
"$BIN" branch --work-dir "$WORK_DIR" reset reset-target --from reset-source > "$TMP/reset.out"
grep -F "reset COW branch 'reset-target' from 'reset-source'" "$TMP/reset.out" >/dev/null
test -f "$WORK/reset-target/wp-content/reset-source.txt"
test ! -e "$WORK/reset-target/wp-content/reset-target.txt"
curl -sS -H "Host: reset-target.wp.localhost:$PORT" \
  "http://127.0.0.1:$PORT/wp-admin/edit.php" \
  -o "$TMP/reset-target-edit.html"
grep -F "$RESET_SOURCE_TITLE" "$TMP/reset-target-edit.html" >/dev/null
grep -F "$RESET_TARGET_TITLE" "$TMP/reset-target-edit.html" && exit 1
if "$BIN" branch --work-dir "$WORK_DIR" reset main --from reset-source > "$TMP/reset-main.out" 2>&1; then
  echo "reset main without --force unexpectedly succeeded" >&2
  cat "$TMP/reset-main.out" >&2
  exit 1
fi
grep -F "refusing to reset main without --force" "$TMP/reset-main.out" >/dev/null

log_step "merge branch into main"
php -r '$db = new SQLite3($argv[1]); $db->exec("CREATE TABLE IF NOT EXISTS forkpress_e2e_target_kept (id INTEGER PRIMARY KEY, label TEXT NOT NULL)");' "$WORK/main/wp-content/database/.ht.sqlite"
"$BIN" branch --work-dir "$WORK_DIR" create merge-source
MERGE_TITLE="Merge source $(date +%s)"
create_branch_post merge-source "$MERGE_TITLE"
php -r '$db = new SQLite3($argv[1]); $db->exec("INSERT INTO forkpress_e2e_target_kept (id, label) VALUES (1, '\''target-only row'\'')");' "$WORK/main/wp-content/database/.ht.sqlite"
echo "merged through branch merge" > "$WORK/merge-source/wp-content/merge-source-file.txt"
echo "kept on target through branch merge" > "$WORK/main/wp-content/main-target-file.txt"
"$BIN" branch --work-dir "$WORK_DIR" merge merge-source --into main > "$TMP/merge.out"
grep -F "forkpress: merged merge-source into main" "$TMP/merge.out" >/dev/null
grep -F "status:    completed" "$TMP/merge.out" >/dev/null
test -f "$WORK/main/wp-content/merge-source-file.txt"
grep -F "merged through branch merge" "$WORK/main/wp-content/merge-source-file.txt" >/dev/null
test -f "$WORK/main/wp-content/main-target-file.txt"
grep -F "kept on target through branch merge" "$WORK/main/wp-content/main-target-file.txt" >/dev/null
curl -sS -H "Host: wp.localhost:$PORT" \
  "http://127.0.0.1:$PORT/wp-admin/edit.php" \
  -o "$TMP/main-after-merge-edit.html"
grep -F "$MERGE_TITLE" "$TMP/main-after-merge-edit.html" >/dev/null
php -r '$db = new SQLite3($argv[1]); $label = $db->querySingle("SELECT label FROM forkpress_e2e_target_kept WHERE id = 1"); exit($label === "target-only row" ? 0 : 1);' "$WORK/main/wp-content/database/.ht.sqlite"
test -f "$WORK_DIR/cow/merge/metadata.sqlite"
php -r '$db = new SQLite3($argv[1]); $count = (int)$db->querySingle("SELECT COUNT(*) FROM merge_runs WHERE source_branch = '\''merge-source'\'' AND target_branch = '\''main'\'' AND status = '\''completed'\''"); exit($count > 0 ? 0 : 1);' "$WORK_DIR/cow/merge/metadata.sqlite"
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --limit 8 > "$TMP/merge-audit.out"
grep -F "forkpress: COW merge audit" "$TMP/merge-audit.out" >/dev/null
grep -F "merge-source -> main" "$TMP/merge-audit.out" >/dev/null
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --format json --limit 3 > "$TMP/merge-audit.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); exit(is_array($data) && !empty($data["runs"]) ? 0 : 1);' "$TMP/merge-audit.json"
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --target-kept --scope files --path-prefix wp-content/main-target-file.txt --limit 8 > "$TMP/merge-target-kept-files.out"
grep -F "target-kept" "$TMP/merge-target-kept-files.out" >/dev/null
grep -F "wp-content/main-target-file.txt" "$TMP/merge-target-kept-files.out" >/dev/null
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --format json --target-kept --group-by type --limit 12 > "$TMP/merge-target-kept.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $decisions = $data["decisions"] ?? []; $groups = $data["decision_groups"] ?? []; $ok = is_array($data) && (($data["filters"]["target_kept"] ?? false) === true) && (($data["filters"]["records"] ?? null) === "decisions") && (($data["filters"]["decision"] ?? null) === "target-kept") && count($decisions) > 0; $has_db = false; foreach ($decisions as $row) { if (($row["decision"] ?? null) !== "target-kept") $ok = false; if (($row["table_name"] ?? null) === "forkpress_e2e_target_kept") $has_db = true; } $has_group = false; foreach ($groups as $group) { if (($group["group_key"] ?? null) === "target-kept" && (int)($group["decision_count"] ?? 0) > 0) $has_group = true; } exit($ok && $has_db && $has_group ? 0 : 1);' "$TMP/merge-target-kept.json"

log_step "merge runtime-tracked no-PK rowid reuse"
mkdir -p "$WORK/main/wp-content/mu-plugins"
cat > "$WORK/main/wp-content/mu-plugins/forkpress-e2e-keyless.php" <<'PHP'
<?php
add_action('init', function () {
    if (!isset($_GET['forkpress_e2e_keyless'])) {
        return;
    }

    global $wpdb;
    $action = sanitize_key(wp_unslash($_GET['forkpress_e2e_keyless']));
    $table = $wpdb->prefix . 'forkpress_e2e_keyless';
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        wp_send_json_error(['error' => 'unsafe table name'], 500);
    }
    $quoted = '"' . str_replace('"', '""', $table) . '"';

    $query = static function (string $sql) use ($wpdb): void {
        $result = $wpdb->query($sql);
        if ($result === false) {
            wp_send_json_error(['error' => $wpdb->last_error ?: 'query failed'], 500);
        }
    };

    if ($action === 'init') {
        $query("DROP TABLE IF EXISTS $quoted");
        $query("CREATE TABLE $quoted (label TEXT, value TEXT)");
        $query($wpdb->prepare("INSERT INTO $quoted (label, value) VALUES (%s, %s)", 'Base keyless runtime', 'base'));
    } elseif ($action === 'source-reuse') {
        $query("DELETE FROM $quoted WHERE rowid = 1");
        $query($wpdb->prepare("INSERT INTO $quoted (label, value) VALUES (%s, %s)", 'Runtime reused source row', 'new logical row'));
    } elseif ($action === 'target-edit') {
        $query($wpdb->prepare("UPDATE $quoted SET value = %s WHERE rowid = 1", 'target kept old row'));
    } elseif ($action !== 'inspect') {
        wp_send_json_error(['error' => 'unknown action'], 400);
    }

    $rows = $wpdb->get_results("SELECT rowid, label, value FROM $quoted ORDER BY rowid", ARRAY_A);
    if (!is_array($rows)) {
        wp_send_json_error(['error' => $wpdb->last_error ?: 'select failed'], 500);
    }
    wp_send_json(['action' => $action, 'rows' => $rows]);
}, 20);
PHP

keyless_runtime_request main init "$TMP/keyless-init.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); exit(($data["rows"][0]["rowid"] ?? null) == 1 && ($data["rows"][0]["label"] ?? null) === "Base keyless runtime" ? 0 : 1);' "$TMP/keyless-init.json"
"$BIN" branch --work-dir "$WORK_DIR" create keyless-reuse > "$TMP/keyless-create.out"
grep -F "keyless-reuse.wp.localhost:$PORT" "$TMP/keyless-create.out" >/dev/null
keyless_runtime_request keyless-reuse source-reuse "$TMP/keyless-source-reuse.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); exit(count($data["rows"] ?? []) === 1 && ($data["rows"][0]["rowid"] ?? null) == 1 && ($data["rows"][0]["label"] ?? null) === "Runtime reused source row" ? 0 : 1);' "$TMP/keyless-source-reuse.json"
php -r '$db = new SQLite3($argv[1]); $runs = (int)$db->querySingle("SELECT COUNT(*) FROM merge_runs WHERE source_branch = '\''keyless-reuse'\'' AND policy = '\''runtime-row-identity-tracking'\'' AND status = '\''identity_tracked'\''"); $history = (int)$db->querySingle("SELECT COUNT(*) FROM merge_row_identity_history WHERE branch_name = '\''keyless-reuse'\'' AND table_name = '\''wp_forkpress_e2e_keyless'\'' AND rowid = 1"); exit($runs > 0 && $history >= 2 ? 0 : 1);' "$WORK_DIR/cow/merge/metadata.sqlite"
keyless_runtime_request main target-edit "$TMP/keyless-target-edit.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); exit(($data["rows"][0]["rowid"] ?? null) == 1 && ($data["rows"][0]["value"] ?? null) === "target kept old row" ? 0 : 1);' "$TMP/keyless-target-edit.json"
"$BIN" branch --work-dir "$WORK_DIR" merge keyless-reuse --into main > "$TMP/keyless-merge.out"
grep -F "forkpress: merged keyless-reuse into main" "$TMP/keyless-merge.out" >/dev/null
grep -F "status:    completed_with_conflicts" "$TMP/keyless-merge.out" >/dev/null
keyless_runtime_request main inspect "$TMP/keyless-main-after-merge.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $old = 0; $new = 0; foreach (($data["rows"] ?? []) as $row) { if (($row["label"] ?? null) === "Base keyless runtime" && ($row["value"] ?? null) === "target kept old row") $old++; if (($row["label"] ?? null) === "Runtime reused source row" && ($row["value"] ?? null) === "new logical row") $new++; } exit($old === 1 && $new === 1 ? 0 : 1);' "$TMP/keyless-main-after-merge.json"
php -r '$db = new SQLite3($argv[1]); $conflicts = (int)$db->querySingle("SELECT COUNT(*) FROM merge_conflicts WHERE table_name = '\''wp_forkpress_e2e_keyless'\'' AND conflict_type = '\''row-source-deleted'\''"); exit($conflicts > 0 ? 0 : 1);' "$WORK_DIR/cow/merge/metadata.sqlite"
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --scope db --records conflicts --conflict-type row-source-deleted --limit 8 > "$TMP/keyless-conflicts.out"
grep -F "wp_forkpress_e2e_keyless" "$TMP/keyless-conflicts.out" >/dev/null
KEYLESS_CONFLICT_ID="$(
  php -r '$db = new SQLite3($argv[1]); echo (int)$db->querySingle("SELECT id FROM merge_conflicts WHERE table_name = '\''wp_forkpress_e2e_keyless'\'' AND conflict_type = '\''row-source-deleted'\'' ORDER BY id DESC LIMIT 1");' \
    "$WORK_DIR/cow/merge/metadata.sqlite"
)"
if [ "$KEYLESS_CONFLICT_ID" = "0" ]; then
  echo "missing keyless row-source-deleted conflict id" >&2
  exit 1
fi
"$BIN" branch --work-dir "$WORK_DIR" merge-resolve conflict "$KEYLESS_CONFLICT_ID" --choice target --apply --note "Keep runtime target row for e2e" --reviewer cow-e2e > "$TMP/keyless-resolve.out"
grep -F "forkpress: validated COW merge conflict resolution" "$TMP/keyless-resolve.out" >/dev/null
grep -F "applied:   yes" "$TMP/keyless-resolve.out" >/dev/null
KEYLESS_RESOLUTION_ID="$(
  php -r '$db = new SQLite3($argv[1]); echo (int)$db->querySingle("SELECT id FROM merge_resolutions WHERE conflict_id = " . (int)$argv[2] . " ORDER BY id DESC LIMIT 1");' \
    "$WORK_DIR/cow/merge/metadata.sqlite" "$KEYLESS_CONFLICT_ID"
)"
if [ "$KEYLESS_RESOLUTION_ID" = "0" ]; then
  echo "missing keyless deterministic resolution id" >&2
  exit 1
fi
"$BIN" branch --work-dir "$WORK_DIR" merge-review resolution "$KEYLESS_RESOLUTION_ID" --status needs-action --note "E2E follow-up on runtime keyless resolution" --reviewer cow-e2e > "$TMP/keyless-resolution-review.out"
grep -F "forkpress: recorded COW merge review note" "$TMP/keyless-resolution-review.out" >/dev/null
grep -F "record:    resolution #$KEYLESS_RESOLUTION_ID" "$TMP/keyless-resolution-review.out" >/dev/null
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --records resolutions --review-status needs-action --limit 8 > "$TMP/keyless-resolution-audit.out"
grep -F "wp_forkpress_e2e_keyless" "$TMP/keyless-resolution-audit.out" >/dev/null
grep -F "review=needs-action" "$TMP/keyless-resolution-audit.out" >/dev/null
grep -F "E2E follow-up on runtime keyless resolution" "$TMP/keyless-resolution-audit.out" >/dev/null
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --format json --resolution-status validated --group-by status --limit 8 > "$TMP/keyless-resolution-status.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $ok = is_array($data) && (($data["filters"]["records"] ?? null) === "resolutions") && (($data["filters"]["resolution_status"] ?? null) === "validated") && (($data["filters"]["group_by"] ?? null) === "status"); $has_resolution = false; foreach (($data["resolutions"] ?? []) as $row) { if ((int)($row["id"] ?? 0) === (int)$argv[2] && ($row["status"] ?? null) === "validated") $has_resolution = true; } $has_group = false; foreach (($data["resolution_groups"] ?? []) as $group) { if (($group["group_key"] ?? null) === "validated" && (int)($group["resolution_count"] ?? 0) > 0) $has_group = true; } exit($ok && $has_resolution && $has_group ? 0 : 1);' "$TMP/keyless-resolution-status.json" "$KEYLESS_RESOLUTION_ID"

log_step "create agent worktrees"
"$BIN" agents \
  --work-dir "$WORK_DIR" \
  --remote "http://127.0.0.1:$PORT/site.git" \
  --count 2 \
  --prefix cowagent \
  "$TMP/agents"
test -f "$WORK/cowagent-1/wp-load.php"
test -f "$WORK/cowagent-2/wp-load.php"
test -d "$TMP/agents/cowagent-1/wordpress"
test -d "$TMP/agents/cowagent-2/wordpress"

log_step "storage lifecycle diagnostics"
"$BIN" storage status --work-dir "$WORK_DIR" > "$TMP/storage-status-final.out"
grep -F "  public:" "$TMP/storage-status-final.out" >/dev/null
grep -F "  storage:" "$TMP/storage-status-final.out" >/dev/null
grep -F "  lock:" "$TMP/storage-status-final.out" >/dev/null
grep -F "  leftovers:" "$TMP/storage-status-final.out" >/dev/null
"$BIN" storage compact --work-dir "$WORK_DIR" > "$TMP/storage-compact.out"
if grep -F 'file_view = "macos-apfs-sparsebundle"' "$WORK_DIR/site.toml" >/dev/null; then
  grep -F "forkpress: compacted COW sparsebundle" "$TMP/storage-compact.out" >/dev/null
  "$BIN" storage status --work-dir "$WORK_DIR" > "$TMP/storage-status-detached.out"
  grep -F "  attached:  no" "$TMP/storage-status-detached.out" >/dev/null
  grep -F "  branches:  unavailable while sparsebundle is detached" "$TMP/storage-status-detached.out" >/dev/null
  grep -F "  leftovers: unavailable while sparsebundle is detached" "$TMP/storage-status-detached.out" >/dev/null
else
  grep -F "forkpress: storage file view does not use a compactable sparsebundle" "$TMP/storage-compact.out" >/dev/null
fi

echo "PASS cow materialized strategy e2e"

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
  dump_if_exists "$TMP/git-created-merge.out"
  dump_if_exists "$TMP/git-created-http-crash.out"
  dump_if_exists "$TMP/git-created-http-crash-after-restart.html"
  dump_if_exists "$TMP/git-created-http-crash-merge.out"
  dump_if_exists "$TMP/autoinc-main-init.json"
  dump_if_exists "$TMP/ui-create-admin.html"
  dump_if_exists "$TMP/ui-create.json"
  dump_if_exists "$TMP/ui-merge-admin.html"
  dump_if_exists "$TMP/ui-merge.json"
  dump_if_exists "$TMP/ui-main-after-merge-edit.html"
  dump_if_exists "$TMP/public-create-crash.out"
  dump_if_exists "$TMP/public-create-crash-retry.out"
  dump_if_exists "$TMP/autoinc-feature-insert.json"
  dump_if_exists "$TMP/branch-post-edit.html"
  dump_if_exists "$TMP/branch-post-frontend.html"
  dump_if_exists "$TMP/band-merge-source-post-new.html"
  dump_if_exists "$TMP/band-merge-source-rest-save.json"
  dump_if_exists "$TMP/band-merge-target-post-new.html"
  dump_if_exists "$TMP/band-merge-target-rest-save.json"
  dump_if_exists "$TMP/merge-band-posts.out"
  dump_if_exists "$TMP/band-merge-target-edit.html"
  dump_if_exists "$TMP/band-merge-target-source-post.html"
  dump_if_exists "$TMP/semantic-seed.json"
  dump_if_exists "$TMP/semantic-source.json"
  dump_if_exists "$TMP/semantic-target.json"
  dump_if_exists "$TMP/semantic-merge.out"
  dump_if_exists "$TMP/semantic-after-merge.json"
  dump_if_exists "$TMP/band-merge-source-decision-queue.json"
  dump_if_exists "$TMP/git-multi-delete.out"
  dump_if_exists "$TMP/git-delete.out"
  dump_if_exists "$TMP/git-delete-main.out"
  dump_if_exists "$TMP/keyless-init.json"
  dump_if_exists "$TMP/public-reset-crash.out"
  dump_if_exists "$TMP/public-reset-crash-merge-blocked.out"
  dump_if_exists "$TMP/public-reset-crash-retry.out"
  dump_if_exists "$TMP/keyless-source-reuse.json"
  dump_if_exists "$TMP/keyless-target-edit.json"
  dump_if_exists "$TMP/keyless-main-after-merge.json"
  dump_if_exists "$TMP/keyless-merge.out"
  dump_if_exists "$TMP/keyless-conflicts.out"
  dump_if_exists "$TMP/keyless-review-queue.json"
  dump_if_exists "$TMP/keyless-resolve.out"
  dump_if_exists "$TMP/keyless-resolution-review.out"
  dump_if_exists "$TMP/keyless-resolution-audit.out"
  dump_if_exists "$TMP/keyless-resolution-needs-action-queue.json"
  dump_if_exists "$TMP/keyless-unreviewed-resolution-queue.json"
  dump_if_exists "$TMP/keyless-resolution-status.json"
  dump_if_exists "$TMP/offline-keyless-source.json"
  dump_if_exists "$TMP/offline-keyless-target.json"
  dump_if_exists "$TMP/offline-keyless-merge.out"
  dump_if_exists "$TMP/offline-keyless-after-merge.json"
  dump_if_exists "$TMP/offline-keyless-audit.json"
  dump_if_exists "$TMP/offline-keyless-resolve.out"
  dump_if_exists "$TMP/offline-keyless-after-resolution.json"
  dump_if_exists "$TMP/offline-keyless-partial-source.json"
  dump_if_exists "$TMP/offline-keyless-partial-target.json"
  dump_if_exists "$TMP/offline-keyless-partial-merge.out"
  dump_if_exists "$TMP/offline-keyless-partial-after-merge.json"
  dump_if_exists "$TMP/offline-keyless-partial-resolve.out"
  dump_if_exists "$TMP/offline-keyless-partial-after-resolution.json"
  dump_if_exists "$TMP/keyless-unique-same-merge.out"
  dump_if_exists "$TMP/keyless-unique-same-rerun.out"
  dump_if_exists "$TMP/keyless-unique-same-after-merge.json"
  dump_if_exists "$TMP/fk-keyless-update-merge.out"
  dump_if_exists "$TMP/fk-keyless-update-rerun.out"
  dump_if_exists "$TMP/fk-keyless-update-after-merge.json"
  dump_if_exists "$TMP/merge-audit.out"
  dump_if_exists "$TMP/merge-audit.json"
  dump_if_exists "$TMP/merge-pending-reset.out"
  dump_if_exists "$TMP/public-crash-merge.out"
  dump_if_exists "$TMP/public-crash-recover.json"
  dump_if_exists "$TMP/public-crash-blocked.out"
  dump_if_exists "$TMP/public-crash-restore.json"
  dump_if_exists "$TMP/public-crash-retry.out"
  dump_if_exists "$TMP/public-crash-main-edit.html"
  dump_if_exists "$TMP/public-metadata-crash-merge.out"
  dump_if_exists "$TMP/public-metadata-crash-recover.json"
  dump_if_exists "$TMP/public-metadata-crash-blocked.out"
  dump_if_exists "$TMP/public-metadata-crash-restore.json"
  dump_if_exists "$TMP/public-metadata-crash-retry.out"
  dump_if_exists "$TMP/public-metadata-crash-main-edit.html"
  dump_if_exists "$TMP/public-before-file-crash-merge.out"
  dump_if_exists "$TMP/public-before-file-crash-recover.json"
  dump_if_exists "$TMP/public-before-file-crash-blocked.out"
  dump_if_exists "$TMP/public-before-file-crash-restore.json"
  dump_if_exists "$TMP/public-before-file-crash-retry.out"
  dump_if_exists "$TMP/public-before-file-crash-main-edit.html"
  dump_if_exists "$TMP/public-recovery-cleanup-crash-merge.out"
  dump_if_exists "$TMP/public-recovery-cleanup-crash-recover.json"
  dump_if_exists "$TMP/public-recovery-cleanup-crash-restore.out"
  dump_if_exists "$TMP/public-recovery-cleanup-crash-retry-restore.json"
  dump_if_exists "$TMP/public-recovery-cleanup-crash-retry.out"
  dump_if_exists "$TMP/public-recovery-cleanup-crash-main-edit.html"
  dump_if_exists "$TMP/public-file-crash-merge.out"
  dump_if_exists "$TMP/public-file-crash-recover.json"
  dump_if_exists "$TMP/public-file-crash-blocked.out"
  dump_if_exists "$TMP/public-file-crash-restore.json"
  dump_if_exists "$TMP/public-file-crash-retry.out"
  dump_if_exists "$TMP/public-file-crash-main-edit.html"
  dump_if_exists "$TMP/merge-rollback-failures.json"
  dump_if_exists "$TMP/merge-rollback-failures.out"
  dump_if_exists "$TMP/file-conflict-pending.out"
  dump_if_exists "$TMP/file-conflict-pending-queue.json"
  dump_if_exists "$TMP/file-conflict-needs-action.out"
  dump_if_exists "$TMP/file-conflict-needs-action-queue.json"
  dump_if_exists "$TMP/merge-db-decision-review-queue.json"
  dump_if_exists "$TMP/merge-db-decision-reviewed.out"
  dump_if_exists "$TMP/merge-db-decision-reviewed.json"
  dump_if_exists "$TMP/merge-file-decision-review-queue.json"
  dump_if_exists "$TMP/merge-file-decision-reviewed.out"
  dump_if_exists "$TMP/merge-file-decision-reviewed.json"
  dump_if_exists "$TMP/merge-unreviewed.json"
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

autoinc_runtime_request() {
  local branch="$1"
  local action="$2"
  local out="$3"
  local host
  host="$(branch_host "$branch")"

  local http
  http="$(
    curl -sS -o "$out" -w '%{http_code}' \
      -H "Host: $host" \
      "http://127.0.0.1:$PORT/?forkpress_e2e_autoinc=$action"
  )"
  if [ "$http" != "200" ]; then
    echo "AUTOINCREMENT runtime action $action on $branch returned $http" >&2
    cat "$out" >&2
    "$BIN" logs --work-dir "$WORK_DIR" --file all -n 180 >&2 || true
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

unique_runtime_request() {
  local branch="$1"
  local action="$2"
  local out="$3"
  local host
  host="$(branch_host "$branch")"

  local http
  http="$(
    curl -sS -o "$out" -w '%{http_code}' \
      -H "Host: $host" \
      "http://127.0.0.1:$PORT/?forkpress_e2e_unique=$action"
  )"
  if [ "$http" != "200" ]; then
    echo "unique runtime action $action on $branch returned $http" >&2
    cat "$out" >&2
    "$BIN" logs --work-dir "$WORK_DIR" --file all -n 180 >&2 || true
    exit 1
  fi
}

semantic_runtime_request() {
  local branch="$1"
  local action="$2"
  local out="$3"
  local host
  host="$(branch_host "$branch")"

  local http
  http="$(
    curl -sS -o "$out" -w '%{http_code}' \
      -H "Host: $host" \
      "http://127.0.0.1:$PORT/?forkpress_e2e_semantic=$action"
  )"
  if [ "$http" != "200" ]; then
    echo "semantic runtime action $action on $branch returned $http" >&2
    cat "$out" >&2
    "$BIN" logs --work-dir "$WORK_DIR" --file all -n 180 >&2 || true
    exit 1
  fi
}

branch_ui_nonce() {
  local branch="$1"
  local field="$2"
  local out="$3"
  local cookie_jar="${4:-}"
  local host
  host="$(branch_host "$branch")"

  if [ -n "$cookie_jar" ]; then
    : > "$cookie_jar"
    if ! curl -sS -L -c "$cookie_jar" -b "$cookie_jar" \
      -H "Host: $host" \
      "http://127.0.0.1:$PORT/wp-login.php" \
      -o "$TMP/${branch}-${field}-login.html"; then
      echo "ForkPress branch UI login fetch failed for $field" >&2
      return 2
    fi
    if ! curl -sS -L -c "$cookie_jar" -b "$cookie_jar" \
      -H "Host: $host" \
      "http://127.0.0.1:$PORT/wp-admin/" \
      -o "$out"; then
      echo "ForkPress branch UI admin fetch failed for $field" >&2
      return 2
    fi
  else
    if ! curl -sS -L -H "Host: $host" \
      "http://127.0.0.1:$PORT/wp-admin/" \
      -o "$out"; then
      echo "ForkPress branch UI admin fetch failed for $field" >&2
      return 2
    fi
  fi

  node - <<'NODE' "$out" "$field"
const fs = require('fs');
const html = fs.readFileSync(process.argv[2], 'utf8');
const field = process.argv[3];
const match = html.match(/var actions = (\{.*?\}|null);/s);
if (match && match[1] !== 'null') {
  const actions = JSON.parse(match[1]);
  if (actions && typeof actions[field] === 'string' && actions[field]) {
    console.log(actions[field]);
    process.exit(0);
  }
}
const fieldPattern = new RegExp('"' + field.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '"\\s*:\\s*"([^"]+)"');
const fieldMatch = html.match(fieldPattern);
if (fieldMatch) {
  console.log(JSON.parse('"' + fieldMatch[1] + '"'));
  process.exit(0);
}
console.error('ForkPress branch UI nonce field not found: ' + field);
process.exit(2);
NODE
}

log_step "init COW site"
"$BIN" init --work-dir "$WORK_DIR" --admin-password admin
test -d "$WORK/.forkpress"
test -d "$WORK/main"
test -f "$WORK/main/wp-load.php"
test ! -e "$WORK/.forkpress/cow/branches/main"
grep -E 'file_view = "(reflink|file-copy|macos-apfs-sparsebundle|linux-xfs-loop)"' "$WORK_DIR/site.toml" >/dev/null
grep -F 'strategy = "cow"' "$WORK_DIR/site.toml" >/dev/null
"$BIN" doctor storage --work-dir "$WORK_DIR" > "$TMP/storage-doctor.out"
grep -F "ForkPress storage capability report" "$TMP/storage-doctor.out" >/dev/null
"$BIN" storage status --work-dir "$WORK_DIR" > "$TMP/storage-status.out"
grep -F "ForkPress storage status" "$TMP/storage-status.out" >/dev/null
log_step "start server"
"$BIN" serve --work-dir "$WORK_DIR" --port "$PORT" --root-host wp.localhost --workers 1
"$BIN" server list | grep -F "$WORK_DIR" >/dev/null

log_step "install runtime AUTOINCREMENT probe"
mkdir -p "$WORK/main/wp-content/mu-plugins"
cat > "$WORK/main/wp-content/mu-plugins/forkpress-e2e-autoinc.php" <<'PHP'
<?php
add_action('init', function () {
    if (!isset($_GET['forkpress_e2e_autoinc'])) {
        return;
    }

    global $wpdb;
    $action = sanitize_key(wp_unslash($_GET['forkpress_e2e_autoinc']));
    $table = $wpdb->prefix . 'forkpress_e2e_autoinc';
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        wp_send_json_error(['error' => 'unsafe table name'], 500);
    }
    $quoted = '`' . str_replace('`', '``', $table) . '`';

    $query = static function (string $sql) use ($wpdb): void {
        $result = $wpdb->query($sql);
        if ($result === false) {
            wp_send_json_error(['error' => $wpdb->last_error ?: 'query failed'], 500);
        }
    };

    if ($action === 'init') {
        $query("DROP TABLE IF EXISTS $quoted");
        $query("CREATE TABLE $quoted (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, label text NOT NULL, PRIMARY KEY (id))");
        $query($wpdb->prepare("INSERT INTO $quoted (label) VALUES (%s)", 'Base runtime plugin row'));
    } elseif ($action === 'insert') {
        $query($wpdb->prepare("INSERT INTO $quoted (label) VALUES (%s)", 'Branch runtime plugin row'));
    } elseif ($action !== 'inspect') {
        wp_send_json_error(['error' => 'unknown action'], 400);
    }

    $rows = $wpdb->get_results("SELECT id, label FROM $quoted ORDER BY id", ARRAY_A);
    if (!is_array($rows)) {
        wp_send_json_error(['error' => $wpdb->last_error ?: 'select failed'], 500);
    }
    $max_id = (int)$wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM $quoted");
    $seq = (int)$wpdb->get_var($wpdb->prepare("SELECT seq FROM sqlite_sequence WHERE name = %s", $table));
    wp_send_json(['action' => $action, 'rows' => $rows, 'max_id' => $max_id, 'seq' => $seq]);
}, 20);
PHP

cat > "$WORK/main/wp-content/mu-plugins/forkpress-e2e-semantic.php" <<'PHP'
<?php
add_action('init', function () {
    register_post_type('forkpress_note', [
        'public' => false,
        'show_in_rest' => true,
        'label' => 'ForkPress notes',
        'supports' => ['title', 'editor', 'custom-fields'],
    ]);
    register_taxonomy('forkpress_topic', ['page', 'forkpress_note'], [
        'public' => false,
        'hierarchical' => true,
        'show_in_rest' => true,
        'label' => 'ForkPress topics',
    ]);
    register_nav_menus([
        'forkpress_semantic_source' => 'ForkPress Semantic Source',
        'forkpress_semantic_target' => 'ForkPress Semantic Target',
    ]);
}, 0);

add_action('init', function () {
    if (!isset($_GET['forkpress_e2e_semantic'])) {
        return;
    }

    $action = sanitize_key(wp_unslash($_GET['forkpress_e2e_semantic']));
    $branch = null;
    if ($action === 'source') {
        $branch = 'source';
    } elseif ($action === 'target') {
        $branch = 'target';
    } elseif ($action !== 'seed' && $action !== 'inspect') {
        wp_send_json_error(['error' => 'unknown action'], 400);
    }

    global $wpdb;
    $plugin_parent_table = $wpdb->prefix . 'forkpress_semantic_plugin_parent';
    $plugin_child_table = $wpdb->prefix . 'forkpress_semantic_plugin_child';
    $quote_ident = static function (string $name): string {
        return '`' . str_replace('`', '``', $name) . '`';
    };
    $query = static function (string $sql) use ($wpdb): void {
        $result = $wpdb->query($sql);
        if ($result === false) {
            wp_send_json_error(['error' => $wpdb->last_error ?: 'query failed'], 500);
        }
    };
    $find_page = static function ($title) {
        $page = get_page_by_title($title, OBJECT, 'page');
        return $page instanceof WP_Post ? (int)$page->ID : 0;
    };
    $must_insert_post = static function ($args) {
        $id = wp_insert_post($args, true);
        if (is_wp_error($id)) {
            wp_send_json_error(['error' => $id->get_error_message()], 500);
        }
        return (int)$id;
    };
    $must_insert_user = static function (array $args): int {
        $id = wp_insert_user($args);
        if (is_wp_error($id)) {
            wp_send_json_error(['error' => $id->get_error_message()], 500);
        }
        return (int)$id;
    };
    $must_set_terms = static function ($post_id, $terms) {
        $result = wp_set_object_terms($post_id, $terms, 'forkpress_topic');
        if (is_wp_error($result)) {
            wp_send_json_error(['error' => $result->get_error_message()], 500);
        }
    };
    $must_term = static function ($name, $parent = 0) {
        $existing = term_exists($name, 'forkpress_topic', $parent);
        if (is_array($existing) && !empty($existing['term_id'])) {
            return (int)$existing['term_id'];
        }
        $created = wp_insert_term($name, 'forkpress_topic', ['parent' => (int)$parent]);
        if (is_wp_error($created)) {
            wp_send_json_error(['error' => $created->get_error_message()], 500);
        }
        return (int)$created['term_id'];
    };

    if ($action === 'seed') {
        $query('CREATE TABLE IF NOT EXISTS ' . $quote_ident($plugin_parent_table) . ' (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            branch text NOT NULL,
            label text NOT NULL,
            graph_json longtext NOT NULL,
            graph_serialized longtext NOT NULL,
            PRIMARY KEY (id)
        )');
        $query('CREATE TABLE IF NOT EXISTS ' . $quote_ident($plugin_child_table) . ' (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            parent_id bigint(20) unsigned NOT NULL,
            branch text NOT NULL,
            file_path text NOT NULL,
            payload longtext NOT NULL,
            PRIMARY KEY (id)
        )');
        foreach (['Source Edit', 'Target Edit', 'Source Delete', 'Target Delete'] as $case) {
            $title = "Semantic $case Page";
            if ($find_page($title) !== 0) {
                continue;
            }
            $id = $must_insert_post([
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_title' => $title,
                'post_content' => "<!-- wp:paragraph --><p>Base $case page body</p><!-- /wp:paragraph -->",
            ]);
            update_post_meta($id, '_forkpress_semantic_base', $case);
        }
    }

    if ($branch !== null) {
        $suffix = ucfirst($branch);
        $user_id = $must_insert_user([
            'user_login' => "forkpress_semantic_$branch",
            'user_pass' => wp_generate_password(32, true),
            'display_name' => "Semantic $suffix Author",
            'role' => 'author',
        ]);
        $user_graph = [
            'branch' => $branch,
            'user_id' => (int)$user_id,
        ];
        update_user_meta($user_id, '_forkpress_semantic_user_graph', $user_graph);
        update_user_meta($user_id, '_forkpress_semantic_user_serialized_graph', serialize($user_graph));

        $edit_id = $find_page("Semantic $suffix Edit Page");
        if ($edit_id === 0) {
            wp_send_json_error(['error' => "missing Semantic $suffix Edit Page"], 500);
        }
        $edit_result = wp_update_post([
            'ID' => $edit_id,
            'post_title' => "Semantic $suffix Edited Page",
            'post_content' => "<!-- wp:paragraph --><p>Edited on $branch branch</p><!-- /wp:paragraph -->",
            'post_author' => $user_id,
        ], true);
        if (is_wp_error($edit_result)) {
            wp_send_json_error(['error' => $edit_result->get_error_message()], 500);
        }

        $delete_id = $find_page("Semantic $suffix Delete Page");
        if ($delete_id === 0) {
            wp_send_json_error(['error' => "missing Semantic $suffix Delete Page"], 500);
        }
        if (wp_delete_post($delete_id, true) === false) {
            wp_send_json_error(['error' => "failed to delete Semantic $suffix Delete Page"], 500);
        }

        $page_id = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => "Semantic $suffix Page",
            'post_content' => "<!-- wp:paragraph --><p>Semantic $branch page body</p><!-- /wp:paragraph -->",
            'post_author' => $user_id,
        ], true);
        if (is_wp_error($page_id)) {
            wp_send_json_error(['error' => $page_id->get_error_message()], 500);
        }
        update_post_meta($page_id, '_forkpress_semantic_branch', $branch);
        $parent_term_id = $must_term("Semantic $suffix Parent Topic");
        $topic_term_id = $must_term("Semantic $suffix Topic", $parent_term_id);
        $must_set_terms($page_id, [$topic_term_id]);

        $note_id = wp_insert_post([
            'post_type' => 'forkpress_note',
            'post_status' => 'publish',
            'post_title' => "Semantic $suffix Note",
            'post_content' => "CPT content for $branch",
            'post_author' => $user_id,
        ], true);
        if (is_wp_error($note_id)) {
            wp_send_json_error(['error' => $note_id->get_error_message()], 500);
        }
        update_post_meta($note_id, '_forkpress_semantic_note', $branch);
        $must_set_terms($note_id, [$topic_term_id]);

        $block_id = wp_insert_post([
            'post_type' => 'wp_block',
            'post_status' => 'publish',
            'post_title' => "Semantic $suffix Block",
            'post_content' => "<!-- wp:paragraph --><p>Reusable block for $branch</p><!-- /wp:paragraph -->",
            'post_author' => $user_id,
        ], true);
        if (is_wp_error($block_id)) {
            wp_send_json_error(['error' => $block_id->get_error_message()], 500);
        }
        $page_update = wp_update_post([
            'ID' => (int)$page_id,
            'post_content' => "<!-- wp:paragraph --><p>Semantic $branch page body</p><!-- /wp:paragraph -->\n<!-- wp:block {\"ref\":$block_id} /-->",
        ], true);
        if (is_wp_error($page_update)) {
            wp_send_json_error(['error' => $page_update->get_error_message()], 500);
        }

        $menu_id = wp_create_nav_menu("Semantic $suffix Menu");
        if (is_wp_error($menu_id)) {
            wp_send_json_error(['error' => $menu_id->get_error_message()], 500);
        }
        $menu_item_id = wp_update_nav_menu_item($menu_id, 0, [
            'menu-item-title' => "Semantic $suffix Link",
            'menu-item-status' => 'publish',
            'menu-item-type' => 'post_type',
            'menu-item-object' => 'page',
            'menu-item-object-id' => (int)$page_id,
        ]);
        if (is_wp_error($menu_item_id)) {
            wp_send_json_error(['error' => $menu_item_id->get_error_message()], 500);
        }
        $locations = get_theme_mod('nav_menu_locations', []);
        if (!is_array($locations)) {
            $locations = [];
        }
        $locations["forkpress_semantic_{$branch}"] = (int)$menu_id;
        set_theme_mod('nav_menu_locations', $locations);

        $upload = wp_upload_dir();
        if (!empty($upload['error'])) {
            wp_send_json_error(['error' => $upload['error']], 500);
        }
        if (!wp_mkdir_p($upload['path'])) {
            wp_send_json_error(['error' => 'failed to create upload directory'], 500);
        }
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true);
        if ($png === false) {
            wp_send_json_error(['error' => 'failed to decode test image'], 500);
        }
        $filename = "forkpress-semantic-$branch.png";
        $path = trailingslashit($upload['path']) . $filename;
        if (file_put_contents($path, $png) === false) {
            wp_send_json_error(['error' => 'failed to write upload file'], 500);
        }
        $thumbnail_filename = "forkpress-semantic-$branch-150x150.png";
        $thumbnail_path = trailingslashit($upload['path']) . $thumbnail_filename;
        if (file_put_contents($thumbnail_path, $png) === false) {
            wp_send_json_error(['error' => 'failed to write generated upload size'], 500);
        }
        $attachment_id = wp_insert_attachment([
            'post_title' => "Semantic $suffix Media",
            'post_mime_type' => 'image/png',
            'post_status' => 'inherit',
            'post_author' => $user_id,
        ], $path, $page_id, true);
        if (is_wp_error($attachment_id)) {
            wp_send_json_error(['error' => $attachment_id->get_error_message()], 500);
        }
        wp_update_attachment_metadata($attachment_id, [
            'width' => 300,
            'height' => 300,
            'file' => _wp_relative_upload_path($path),
            'filesize' => filesize($path),
            'sizes' => [
                'thumbnail' => [
                    'file' => $thumbnail_filename,
                    'width' => 150,
                    'height' => 150,
                    'mime-type' => 'image/png',
                    'filesize' => filesize($thumbnail_path),
                ],
            ],
            'image_meta' => [],
        ]);
        update_post_meta($attachment_id, '_forkpress_semantic_media', $branch);

        $comment_id = wp_insert_comment([
            'comment_post_ID' => (int)$page_id,
            'comment_content' => "Semantic $suffix Comment",
            'comment_approved' => 1,
            'user_id' => (int)$user_id,
        ]);
        if ($comment_id === false || (int)$comment_id <= 0) {
            wp_send_json_error(['error' => 'failed to insert semantic comment'], 500);
        }
        $comment_id = (int)$comment_id;
        $reply_id = wp_insert_comment([
            'comment_post_ID' => (int)$page_id,
            'comment_content' => "Semantic $suffix Reply",
            'comment_parent' => $comment_id,
            'comment_approved' => 1,
            'user_id' => (int)$user_id,
        ]);
        if ($reply_id === false || (int)$reply_id <= 0) {
            wp_send_json_error(['error' => 'failed to insert semantic reply'], 500);
        }
        $reply_id = (int)$reply_id;
        $comment_graph = [
            'branch' => $branch,
            'page_id' => (int)$page_id,
            'comment_id' => $comment_id,
            'reply_id' => $reply_id,
            'user_id' => (int)$user_id,
        ];
        add_comment_meta($comment_id, '_forkpress_semantic_comment_graph', $comment_graph);
        add_comment_meta($reply_id, '_forkpress_semantic_comment_serialized_graph', serialize($comment_graph));

        $graph = [
            'branch' => $branch,
            'user_id' => (int)$user_id,
            'page_id' => (int)$page_id,
            'note_id' => (int)$note_id,
            'block_id' => (int)$block_id,
            'menu_id' => (int)$menu_id,
            'attachment_id' => (int)$attachment_id,
            'comment_id' => $comment_id,
            'reply_id' => $reply_id,
        ];
        update_option("forkpress_semantic_{$branch}_option", $graph, false);
        update_option("forkpress_semantic_{$branch}_json_option", wp_json_encode($graph), false);

        $plugin_file = trailingslashit($upload['path']) . "forkpress-plugin-graph-$branch.dat";
        if (file_put_contents($plugin_file, "plugin graph file for $branch\n") === false) {
            wp_send_json_error(['error' => 'failed to write plugin graph file'], 500);
        }
        $plugin_file_rel = _wp_relative_upload_path($plugin_file);
        $initial_graph = [
            'branch' => $branch,
            'page_id' => (int)$page_id,
            'note_id' => (int)$note_id,
            'attachment_id' => (int)$attachment_id,
            'file' => $plugin_file_rel,
        ];
        $inserted = $wpdb->insert($plugin_parent_table, [
            'branch' => $branch,
            'label' => "Semantic $suffix Plugin Parent",
            'graph_json' => wp_json_encode($initial_graph),
            'graph_serialized' => serialize($initial_graph),
        ]);
        if ($inserted === false) {
            wp_send_json_error(['error' => $wpdb->last_error ?: 'failed to insert plugin parent'], 500);
        }
        $plugin_parent_id = (int)$wpdb->insert_id;
        $inserted = $wpdb->insert($plugin_child_table, [
            'parent_id' => $plugin_parent_id,
            'branch' => $branch,
            'file_path' => $plugin_file_rel,
            'payload' => wp_json_encode([
                'branch' => $branch,
                'parent_id' => $plugin_parent_id,
                'page_id' => (int)$page_id,
                'note_id' => (int)$note_id,
            ]),
        ]);
        if ($inserted === false) {
            wp_send_json_error(['error' => $wpdb->last_error ?: 'failed to insert plugin child'], 500);
        }
        $plugin_child_id = (int)$wpdb->insert_id;
        $plugin_graph = $initial_graph + [
            'parent_id' => $plugin_parent_id,
            'child_id' => $plugin_child_id,
        ];
        $updated = $wpdb->update($plugin_parent_table, [
            'graph_json' => wp_json_encode($plugin_graph),
            'graph_serialized' => serialize($plugin_graph),
        ], ['id' => $plugin_parent_id]);
        if ($updated === false) {
            wp_send_json_error(['error' => $wpdb->last_error ?: 'failed to update plugin parent graph'], 500);
        }
        update_option("forkpress_semantic_plugin_{$branch}_option", $plugin_graph, false);
        update_post_meta($page_id, '_forkpress_semantic_plugin_graph', wp_json_encode($plugin_graph));
    }

    $posts = get_posts([
        'post_type' => ['page', 'forkpress_note', 'wp_block', 'attachment'],
        'post_status' => 'any',
        'numberposts' => -1,
        'orderby' => 'ID',
        'order' => 'ASC',
    ]);
    $rows = [];
    foreach ($posts as $post) {
        if (strpos($post->post_title, 'Semantic ') !== 0) {
            continue;
        }
        $file = $post->post_type === 'attachment' ? get_attached_file($post->ID) : '';
        $metadata = $post->post_type === 'attachment' ? wp_get_attachment_metadata($post->ID) : [];
        $metadata_sizes = [];
        $generated_files = [];
        if (is_array($metadata) && is_array($metadata['sizes'] ?? null) && $file !== '') {
            foreach ($metadata['sizes'] as $size_name => $size) {
                $metadata_sizes[] = (string)$size_name;
                $generated_files[(string)$size_name] = isset($size['file'])
                    ? file_exists(trailingslashit(dirname($file)) . $size['file'])
                    : false;
            }
            sort($metadata_sizes);
            ksort($generated_files);
        }
        $term_objects = wp_get_object_terms($post->ID, 'forkpress_topic');
        $terms = [];
        $term_parents = [];
        if (!is_wp_error($term_objects)) {
            foreach ($term_objects as $term) {
                $terms[] = $term->name;
                if ((int)$term->parent > 0) {
                    $parent = get_term((int)$term->parent, 'forkpress_topic');
                    if ($parent && !is_wp_error($parent)) {
                        $term_parents[$term->name] = $parent->name;
                    }
                }
            }
        }
        sort($terms);
        ksort($term_parents);
        $block_refs = [];
        if (preg_match_all('/<!--\s+wp:block\s+\{"ref":(\d+)\}\s+\/-->/', $post->post_content, $matches)) {
            $block_refs = array_map('intval', $matches[1]);
            sort($block_refs);
        }
        $rows[] = [
            'id' => (int)$post->ID,
            'type' => $post->post_type,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'block_refs' => $block_refs,
            'author' => (int)$post->post_author,
            'branch' => get_post_meta($post->ID, '_forkpress_semantic_branch', true)
                ?: get_post_meta($post->ID, '_forkpress_semantic_note', true)
                ?: get_post_meta($post->ID, '_forkpress_semantic_media', true),
            'terms' => $terms,
            'term_parents' => $term_parents,
            'file_exists' => $file === '' ? null : file_exists($file),
            'metadata_sizes' => $metadata_sizes,
            'generated_files' => $generated_files,
        ];
    }

    $users = [];
    foreach (get_users(['search' => 'forkpress_semantic_*', 'search_columns' => ['user_login']]) as $user) {
        $graph = get_user_meta($user->ID, '_forkpress_semantic_user_graph', true);
        $serialized_graph = maybe_unserialize((string)get_user_meta($user->ID, '_forkpress_semantic_user_serialized_graph', true));
        $users[$user->user_login] = [
            'id' => (int)$user->ID,
            'display_name' => $user->display_name,
            'graph_user_id' => is_array($graph) ? (int)($graph['user_id'] ?? 0) : 0,
            'serialized_graph_user_id' => is_array($serialized_graph) ? (int)($serialized_graph['user_id'] ?? 0) : 0,
        ];
    }
    ksort($users);

    $comments = [];
    $comment_rows = get_comments([
        'status' => 'all',
        'orderby' => 'comment_ID',
        'order' => 'ASC',
    ]);
    foreach ($comment_rows as $comment) {
        if (strpos((string)$comment->comment_content, 'Semantic ') !== 0) {
            continue;
        }
        $graph = get_comment_meta($comment->comment_ID, '_forkpress_semantic_comment_graph', true);
        $serialized_graph = maybe_unserialize((string)get_comment_meta($comment->comment_ID, '_forkpress_semantic_comment_serialized_graph', true));
        $comments[(string)$comment->comment_content] = [
            'id' => (int)$comment->comment_ID,
            'post_id' => (int)$comment->comment_post_ID,
            'parent' => (int)$comment->comment_parent,
            'user_id' => (int)$comment->user_id,
            'graph_comment_id' => is_array($graph) ? (int)($graph['comment_id'] ?? 0) : 0,
            'graph_reply_id' => is_array($graph) ? (int)($graph['reply_id'] ?? 0) : 0,
            'graph_user_id' => is_array($graph) ? (int)($graph['user_id'] ?? 0) : 0,
            'serialized_graph_comment_id' => is_array($serialized_graph) ? (int)($serialized_graph['comment_id'] ?? 0) : 0,
            'serialized_graph_reply_id' => is_array($serialized_graph) ? (int)($serialized_graph['reply_id'] ?? 0) : 0,
            'serialized_graph_user_id' => is_array($serialized_graph) ? (int)($serialized_graph['user_id'] ?? 0) : 0,
        ];
    }
    ksort($comments);

    $menus = [];
    $menu_items = [];
    foreach (wp_get_nav_menus(['hide_empty' => false]) as $menu) {
        if (strpos($menu->name, 'Semantic ') === 0) {
            $menus[] = $menu->name;
            $items = wp_get_nav_menu_items($menu->term_id);
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (strpos($item->title, 'Semantic ') !== 0) {
                        continue;
                    }
                    $menu_items[$item->title] = [
                        'menu' => $menu->name,
                        'type' => $item->type,
                        'object' => $item->object,
                        'object_id' => (int)$item->object_id,
                    ];
                }
            }
        }
    }
    sort($menus);
    ksort($menu_items);
    $locations = [];
    foreach (get_nav_menu_locations() as $location => $menu_id) {
        if (strpos((string)$location, 'forkpress_semantic_') !== 0 || (int)$menu_id <= 0) {
            continue;
        }
        $menu = wp_get_nav_menu_object((int)$menu_id);
        if ($menu && !is_wp_error($menu)) {
            $locations[$location] = $menu->name;
        }
    }
    ksort($locations);

    $plugin_graphs = [];
    $parent_table_exists = (string)$wpdb->get_var($wpdb->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = %s", $plugin_parent_table));
    $child_table_exists = (string)$wpdb->get_var($wpdb->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = %s", $plugin_child_table));
    if ($parent_table_exists === $plugin_parent_table && $child_table_exists === $plugin_child_table) {
        $upload_dir = wp_upload_dir();
        $parents = $wpdb->get_results('SELECT id, branch, label, graph_json, graph_serialized FROM ' . $quote_ident($plugin_parent_table) . ' ORDER BY id', ARRAY_A);
        if (!is_array($parents)) {
            wp_send_json_error(['error' => $wpdb->last_error ?: 'failed to select plugin parents'], 500);
        }
        foreach ($parents as $parent) {
            $parent_id = (int)$parent['id'];
            $child = $wpdb->get_row($wpdb->prepare('SELECT id, parent_id, branch, file_path, payload FROM ' . $quote_ident($plugin_child_table) . ' WHERE parent_id = %d', $parent_id), ARRAY_A);
            $graph_json = json_decode((string)$parent['graph_json'], true);
            $graph_serialized = maybe_unserialize((string)$parent['graph_serialized']);
            $child_payload = is_array($child) ? json_decode((string)($child['payload'] ?? ''), true) : [];
            $postmeta_json = [];
            $page_id = is_array($graph_json) ? (int)($graph_json['page_id'] ?? 0) : 0;
            if ($page_id > 0) {
                $postmeta_json = json_decode((string)get_post_meta($page_id, '_forkpress_semantic_plugin_graph', true), true);
            }
            $option_graph = get_option("forkpress_semantic_plugin_{$parent['branch']}_option");
            $file_path = is_array($child) ? (string)($child['file_path'] ?? '') : '';
            $plugin_graphs[(string)$parent['branch']] = [
                'parent_id' => $parent_id,
                'child_id' => is_array($child) ? (int)$child['id'] : 0,
                'child_parent_id' => is_array($child) ? (int)$child['parent_id'] : 0,
                'label' => (string)$parent['label'],
                'json_parent_id' => is_array($graph_json) ? (int)($graph_json['parent_id'] ?? 0) : 0,
                'json_child_id' => is_array($graph_json) ? (int)($graph_json['child_id'] ?? 0) : 0,
                'json_note_id' => is_array($graph_json) ? (int)($graph_json['note_id'] ?? 0) : 0,
                'serialized_parent_id' => is_array($graph_serialized) ? (int)($graph_serialized['parent_id'] ?? 0) : 0,
                'serialized_note_id' => is_array($graph_serialized) ? (int)($graph_serialized['note_id'] ?? 0) : 0,
                'option_parent_id' => is_array($option_graph) ? (int)($option_graph['parent_id'] ?? 0) : 0,
                'option_note_id' => is_array($option_graph) ? (int)($option_graph['note_id'] ?? 0) : 0,
                'postmeta_parent_id' => is_array($postmeta_json) ? (int)($postmeta_json['parent_id'] ?? 0) : 0,
                'postmeta_note_id' => is_array($postmeta_json) ? (int)($postmeta_json['note_id'] ?? 0) : 0,
                'child_payload_note_id' => is_array($child_payload) ? (int)($child_payload['note_id'] ?? 0) : 0,
                'file_exists' => $file_path !== '' && file_exists(trailingslashit($upload_dir['basedir']) . $file_path),
            ];
        }
        ksort($plugin_graphs);
    }

    wp_send_json([
        'action' => $action,
        'posts' => $rows,
        'users' => $users,
        'comments' => $comments,
        'menus' => $menus,
        'menu_items' => $menu_items,
        'menu_locations' => $locations,
        'plugin_graphs' => $plugin_graphs,
        'source_option' => get_option('forkpress_semantic_source_option'),
        'target_option' => get_option('forkpress_semantic_target_option'),
        'source_json_option' => json_decode((string)get_option('forkpress_semantic_source_json_option'), true),
        'target_json_option' => json_decode((string)get_option('forkpress_semantic_target_json_option'), true),
    ]);
}, 20);
PHP

autoinc_runtime_request main init "$TMP/autoinc-main-init.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); exit(($data["max_id"] ?? null) === 1 ? 0 : 1);' "$TMP/autoinc-main-init.json"

log_step "create and merge branch through WordPress admin UI"
UI_CREATE_COOKIES="$TMP/ui-create-cookies.txt"
if ! branch_ui_nonce main createNonce "$TMP/ui-create-admin.html" "$UI_CREATE_COOKIES" > "$TMP/ui-create-nonce.txt"; then
  echo "failed to read WP UI branch create nonce" >&2
  dump_if_exists "$TMP/main-createNonce-login.html"
  dump_if_exists "$TMP/ui-create-admin.html"
  "$BIN" logs --work-dir "$WORK_DIR" --file all -n 180 >&2 || true
  exit 1
fi
UI_CREATE_NONCE="$(cat "$TMP/ui-create-nonce.txt")"
if ! UI_CREATE_HTTP="$(
  curl -sS -o "$TMP/ui-create.json" -w '%{http_code}' \
    -b "$UI_CREATE_COOKIES" \
    -H "Host: wp.localhost:$PORT" \
    -H "Accept: application/json" \
    -H "X-ForkPress-Async: 1" \
    --data-urlencode "action=forkpress_branch_create" \
    --data-urlencode "_wpnonce=$UI_CREATE_NONCE" \
    --data-urlencode "branch=ui-created" \
    --data-urlencode "from=main" \
    "http://127.0.0.1:$PORT/wp-admin/admin-post.php"
)"; then
  echo "WP UI branch create request failed" >&2
  dump_if_exists "$TMP/ui-create.json"
  "$BIN" logs --work-dir "$WORK_DIR" --file all -n 180 >&2 || true
  exit 1
fi
if [ "$UI_CREATE_HTTP" != "200" ]; then
  echo "WP UI branch create returned $UI_CREATE_HTTP" >&2
  cat "$TMP/ui-create.json" >&2
  "$BIN" logs --work-dir "$WORK_DIR" --file all -n 180 >&2 || true
  exit 1
fi
if ! php -r '$data = json_decode(file_get_contents($argv[1]), true); $branches = array_map(fn($row) => $row["name"] ?? "", $data["branches"] ?? []); exit(($data["success"] ?? null) === true && ($data["message"] ?? null) === "Created branch ui-created." && in_array("ui-created", $branches, true) ? 0 : 1);' "$TMP/ui-create.json"; then
  echo "WP UI branch create response did not contain the expected success payload" >&2
  cat "$TMP/ui-create.json" >&2
  "$BIN" logs --work-dir "$WORK_DIR" --file all -n 180 >&2 || true
  exit 1
fi
test -d "$WORK/ui-created"
test -f "$WORK_DIR/cow/merge/bases/ui-created.sqlite"
test -f "$WORK_DIR/cow/merge/file-bases/ui-created.json"
php -r '$db = new SQLite3($argv[1]); exit((int)$db->querySingle("SELECT MAX(id) FROM wp_forkpress_e2e_autoinc") === 1 ? 0 : 1);' "$WORK_DIR/cow/merge/bases/ui-created.sqlite"
php -r '$base = json_decode((string)file_get_contents($argv[1]), true); $entries = $base["entries"] ?? []; exit(is_array($entries) && count($entries) > 0 && !isset($entries["wp-content/ui-created-file.txt"]) ? 0 : 1);' "$WORK_DIR/cow/merge/file-bases/ui-created.json"
php -r '$meta = new SQLite3($argv[1]); $branch = new SQLite3($argv[2]); $band = $meta->querySingle("SELECT band_start, band_end FROM merge_autoincrement_bands WHERE branch_name = '\''ui-created'\'' AND table_name = '\''wp_forkpress_e2e_autoinc'\''", true); $seq = (int)$branch->querySingle("SELECT seq FROM sqlite_sequence WHERE name = '\''wp_forkpress_e2e_autoinc'\''"); exit($band && (int)$band["band_start"] >= 1000000 && $seq === (int)$band["band_start"] - 1 ? 0 : 1);' "$WORK_DIR/cow/merge/metadata.sqlite" "$WORK/ui-created/wp-content/database/.ht.sqlite"

UI_MERGE_TITLE="UI branch merge $(date +%s)"
create_branch_post ui-created "$UI_MERGE_TITLE"
echo "merged through WP branch UI" > "$WORK/ui-created/wp-content/ui-created-file.txt"
UI_MERGE_COOKIES="$TMP/ui-merge-cookies.txt"
if ! branch_ui_nonce main mergeNonce "$TMP/ui-merge-admin.html" "$UI_MERGE_COOKIES" > "$TMP/ui-merge-nonce.txt"; then
  echo "failed to read WP UI branch merge nonce" >&2
  dump_if_exists "$TMP/main-mergeNonce-login.html"
  dump_if_exists "$TMP/ui-merge-admin.html"
  "$BIN" logs --work-dir "$WORK_DIR" --file all -n 180 >&2 || true
  exit 1
fi
UI_MERGE_NONCE="$(cat "$TMP/ui-merge-nonce.txt")"
if ! UI_MERGE_HTTP="$(
  curl -sS -o "$TMP/ui-merge.json" -w '%{http_code}' \
    -b "$UI_MERGE_COOKIES" \
    -H "Host: wp.localhost:$PORT" \
    -H "Accept: application/json" \
    -H "X-ForkPress-Async: 1" \
    --data-urlencode "action=forkpress_branch_merge" \
    --data-urlencode "_wpnonce=$UI_MERGE_NONCE" \
    --data-urlencode "source=ui-created" \
    --data-urlencode "target=main" \
    "http://127.0.0.1:$PORT/wp-admin/admin-post.php"
)"; then
  echo "WP UI branch merge request failed" >&2
  dump_if_exists "$TMP/ui-merge.json"
  "$BIN" logs --work-dir "$WORK_DIR" --file all -n 180 >&2 || true
  exit 1
fi
if [ "$UI_MERGE_HTTP" != "200" ]; then
  echo "WP UI branch merge returned $UI_MERGE_HTTP" >&2
  cat "$TMP/ui-merge.json" >&2
  "$BIN" logs --work-dir "$WORK_DIR" --file all -n 180 >&2 || true
  exit 1
fi
if ! php -r '$data = json_decode(file_get_contents($argv[1]), true); exit(($data["success"] ?? null) === true && ($data["message"] ?? null) === "Merged ui-created into main." ? 0 : 1);' "$TMP/ui-merge.json"; then
  echo "WP UI branch merge response did not contain the expected success payload" >&2
  cat "$TMP/ui-merge.json" >&2
  "$BIN" logs --work-dir "$WORK_DIR" --file all -n 180 >&2 || true
  exit 1
fi
test -f "$WORK/main/wp-content/ui-created-file.txt"
grep -F "merged through WP branch UI" "$WORK/main/wp-content/ui-created-file.txt" >/dev/null
curl -sS -H "Host: wp.localhost:$PORT" \
  "http://127.0.0.1:$PORT/wp-admin/edit.php" \
  -o "$TMP/ui-main-after-merge-edit.html"
grep -F "$UI_MERGE_TITLE" "$TMP/ui-main-after-merge-edit.html" >/dev/null
php -r '$db = new SQLite3($argv[1]); $count = (int)$db->querySingle("SELECT COUNT(*) FROM merge_runs WHERE source_branch = '\''ui-created'\'' AND target_branch = '\''main'\'' AND status = '\''completed'\''"); exit($count > 0 ? 0 : 1);' "$WORK_DIR/cow/merge/metadata.sqlite"

log_step "public branch create crash retry"
if FORKPRESS_COW_STORAGE_TEST_FAILPOINT=after-branch-create-birth-metadata FORKPRESS_COW_STORAGE_TEST_FAILPOINT_ACTION=exit \
  "$BIN" branch --work-dir "$WORK_DIR" create public-create-crash > "$TMP/public-create-crash.out" 2>&1; then
  echo "public branch create unexpectedly survived after-branch-create-birth-metadata failpoint" >&2
  exit 1
fi
test ! -e "$WORK/public-create-crash"
"$BIN" branch --work-dir "$WORK_DIR" create public-create-crash > "$TMP/public-create-crash-retry.out"
grep -F "public-create-crash.wp.localhost:$PORT" "$TMP/public-create-crash-retry.out" >/dev/null
test -d "$WORK/public-create-crash"
test -f "$WORK_DIR/cow/merge/bases/public-create-crash.sqlite"
test -f "$WORK_DIR/cow/merge/file-bases/public-create-crash.json"
php -r '$meta = new SQLite3($argv[1]); $count = (int)$meta->querySingle("SELECT COUNT(*) FROM merge_autoincrement_bands WHERE branch_name = '\''public-create-crash'\''"); exit($count > 0 ? 0 : 1);' "$WORK_DIR/cow/merge/metadata.sqlite"

log_step "create CLI branch"
"$BIN" branch --work-dir "$WORK_DIR" create feature-cow > "$TMP/branch-create.out"
grep -F "feature-cow.wp.localhost:$PORT" "$TMP/branch-create.out" >/dev/null
test -d "$WORK/feature-cow"
echo "feature only" > "$WORK/feature-cow/wp-content/forkpress-branch.txt"
test ! -e "$WORK/main/wp-content/forkpress-branch.txt"
test -f "$WORK_DIR/cow/merge/bases/feature-cow.sqlite"
test -f "$WORK_DIR/cow/merge/file-bases/feature-cow.json"
php -r '$db = new SQLite3($argv[1]); exit((int)$db->querySingle("SELECT MAX(id) FROM wp_forkpress_e2e_autoinc") === 1 ? 0 : 1);' "$WORK_DIR/cow/merge/bases/feature-cow.sqlite"
php -r '$base = json_decode((string)file_get_contents($argv[1]), true); $entries = $base["entries"] ?? []; exit(is_array($entries) && count($entries) > 0 && !isset($entries["wp-content/forkpress-branch.txt"]) ? 0 : 1);' "$WORK_DIR/cow/merge/file-bases/feature-cow.json"
php -r '$meta = new SQLite3($argv[1]); $branch = new SQLite3($argv[2]); $band = $meta->querySingle("SELECT band_start, band_end FROM merge_autoincrement_bands WHERE branch_name = '\''feature-cow'\'' AND table_name = '\''wp_forkpress_e2e_autoinc'\''", true); $seq = (int)$branch->querySingle("SELECT seq FROM sqlite_sequence WHERE name = '\''wp_forkpress_e2e_autoinc'\''"); exit($band && (int)$band["band_start"] >= 1000000 && $seq === (int)$band["band_start"] - 1 ? 0 : 1);' "$WORK_DIR/cow/merge/metadata.sqlite" "$WORK/feature-cow/wp-content/database/.ht.sqlite"
autoinc_runtime_request feature-cow insert "$TMP/autoinc-feature-insert.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $meta = new SQLite3($argv[2]); $branch = new SQLite3($argv[3]); $max = (int)($data["max_id"] ?? 0); $band = $meta->querySingle("SELECT band_start, band_end FROM merge_autoincrement_bands WHERE branch_name = '\''feature-cow'\'' AND table_name = '\''wp_forkpress_e2e_autoinc'\''", true); $seq = (int)$branch->querySingle("SELECT seq FROM sqlite_sequence WHERE name = '\''wp_forkpress_e2e_autoinc'\''"); exit($band && $max >= (int)$band["band_start"] && $max <= (int)$band["band_end"] && $seq === $max ? 0 : 1);' "$TMP/autoinc-feature-insert.json" "$WORK_DIR/cow/merge/metadata.sqlite" "$WORK/feature-cow/wp-content/database/.ht.sqlite"

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
POST_ID="$(php -r '$data = json_decode(file_get_contents($argv[1]), true); echo (int)($data["id"] ?? 0);' "$TMP/rest-save.json")"
if [ "$POST_ID" = "0" ]; then
  echo "REST save did not return a post ID" >&2
  cat "$TMP/rest-save.json" >&2
  exit 1
fi
php -r '$meta = new SQLite3($argv[1]); $branch = new SQLite3($argv[2]); $band = $meta->querySingle("SELECT band_start, band_end FROM merge_autoincrement_bands WHERE branch_name = '\''feature-cow'\'' AND table_name = '\''wp_posts'\''", true); $id = (int)$argv[3]; $seq = (int)$branch->querySingle("SELECT seq FROM sqlite_sequence WHERE name = '\''wp_posts'\''"); exit($band && $id >= (int)$band["band_start"] && $id <= (int)$band["band_end"] && $seq >= $id && $seq <= (int)$band["band_end"] ? 0 : 1);' "$WORK_DIR/cow/merge/metadata.sqlite" "$WORK/feature-cow/wp-content/database/.ht.sqlite" "$POST_ID"

curl -sS -H "Host: feature-cow.wp.localhost:$PORT" \
  "http://127.0.0.1:$PORT/wp-admin/post.php?post=$POST_ID&action=edit" \
  -o "$TMP/branch-post-edit.html"
grep -F "$TITLE" "$TMP/branch-post-edit.html" >/dev/null
grep -F 'id="menu-posts"' "$TMP/branch-post-edit.html" >/dev/null

curl -sSL -H "Host: feature-cow.wp.localhost:$PORT" \
  "http://127.0.0.1:$PORT/?p=$POST_ID" \
  -o "$TMP/branch-post-frontend.html"
grep -F "$TITLE" "$TMP/branch-post-frontend.html" >/dev/null

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
"$BIN" branch --work-dir "$WORK_DIR" delete cowagent-1 > "$TMP/agent-delete-1.out"
"$BIN" branch --work-dir "$WORK_DIR" delete cowagent-2 > "$TMP/agent-delete-2.out"
test ! -e "$WORK/cowagent-1"
test ! -e "$WORK/cowagent-2"

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
test -f "$WORK_DIR/cow/merge/bases/git-created.sqlite"
test -f "$WORK_DIR/cow/merge/file-bases/git-created.json"
"$BIN" branch --work-dir "$WORK_DIR" merge git-created --into main > "$TMP/git-created-merge.out"
grep -F "forkpress: merged git-created into main" "$TMP/git-created-merge.out" >/dev/null
grep -F "status:    completed" "$TMP/git-created-merge.out" >/dev/null
test -f "$WORK/main/wp-content/git-created.txt"
grep -F "created through git" "$WORK/main/wp-content/git-created.txt" >/dev/null

log_step "actual Git push created-branch crash recovery"
git -C "$TMP/checkout" fetch origin main:refs/remotes/origin/main
git -C "$TMP/checkout" checkout -B git-created-http-crash origin/main
git -C "$TMP/checkout" reset --hard origin/main
git -C "$TMP/checkout" clean -fd
printf "created through crashed git push\n" > "$TMP/checkout/wordpress/wp-content/git-created-http-crash.txt"
"$BIN" stop --work-dir "$WORK_DIR" >/dev/null 2>&1 || true
FORKPRESS_COW_GIT_TEST_FAILPOINT=after-created-branch-list FORKPRESS_COW_GIT_TEST_FAILPOINT_ACTION=exit \
  "$BIN" serve --work-dir "$WORK_DIR" --port "$PORT" --root-host wp.localhost --workers 1
if "$BIN" commit "$TMP/checkout" --message "create cow branch through crashed git push" > "$TMP/git-created-http-crash.out" 2>&1; then
  echo "Git push unexpectedly survived after-created-branch-list server exit failpoint" >&2
  exit 1
fi
for _ in $(seq 1 40); do
  if ! "$BIN" server list | grep -F "$WORK_DIR" >/dev/null; then
    break
  fi
  sleep 0.25
done
"$BIN" stop --work-dir "$WORK_DIR" >/dev/null 2>&1 || true
"$BIN" serve --work-dir "$WORK_DIR" --port "$PORT" --root-host wp.localhost --workers 1
"$BIN" branch --work-dir "$WORK_DIR" list | grep -F "git-created-http-crash" >/dev/null
curl -sS -H "Host: git-created-http-crash.wp.localhost:$PORT" \
  "http://127.0.0.1:$PORT/" \
  -o "$TMP/git-created-http-crash-after-restart.html"
grep -F "Branch: git-created-http-crash" "$TMP/git-created-http-crash-after-restart.html" >/dev/null
grep -F "Branch not found" "$TMP/git-created-http-crash-after-restart.html" && exit 1
test -f "$WORK/git-created-http-crash/wp-content/git-created-http-crash.txt"
grep -F "created through crashed git push" "$WORK/git-created-http-crash/wp-content/git-created-http-crash.txt" >/dev/null
test -f "$WORK_DIR/cow/merge/bases/git-created-http-crash.sqlite"
test -f "$WORK_DIR/cow/merge/file-bases/git-created-http-crash.json"
git -C "$TMP/checkout" fetch origin git-created-http-crash:refs/remotes/origin/git-created-http-crash
test "$(git -C "$TMP/checkout" rev-parse git-created-http-crash)" = "$(git -C "$TMP/checkout" rev-parse refs/remotes/origin/git-created-http-crash)"
"$BIN" branch --work-dir "$WORK_DIR" merge git-created-http-crash --into main > "$TMP/git-created-http-crash-merge.out"
grep -F "forkpress: merged git-created-http-crash into main" "$TMP/git-created-http-crash-merge.out" >/dev/null
grep -F "status:    completed" "$TMP/git-created-http-crash-merge.out" >/dev/null
test -f "$WORK/main/wp-content/git-created-http-crash.txt"
grep -F "created through crashed git push" "$WORK/main/wp-content/git-created-http-crash.txt" >/dev/null

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

log_step "public branch reset crash retry"
"$BIN" branch --work-dir "$WORK_DIR" create public-reset-crash-source
"$BIN" branch --work-dir "$WORK_DIR" create public-reset-crash-target
echo "public reset crash source" > "$WORK/public-reset-crash-source/wp-content/public-reset-crash-source.txt"
echo "public reset crash target" > "$WORK/public-reset-crash-target/wp-content/public-reset-crash-target.txt"
if FORKPRESS_COW_STORAGE_TEST_FAILPOINT=after-branch-reset-publish FORKPRESS_COW_STORAGE_TEST_FAILPOINT_ACTION=exit \
  "$BIN" branch --work-dir "$WORK_DIR" reset public-reset-crash-target --from public-reset-crash-source > "$TMP/public-reset-crash.out" 2>&1; then
  echo "public branch reset unexpectedly survived after-branch-reset-publish failpoint" >&2
  exit 1
fi
test -f "$WORK/public-reset-crash-target/wp-content/public-reset-crash-source.txt"
test -f "$WORK_DIR/cow/reset-pending/public-reset-crash-target.txt"
if "$BIN" branch --work-dir "$WORK_DIR" merge public-reset-crash-target --into main > "$TMP/public-reset-crash-merge-blocked.out" 2>&1; then
  echo "public branch merge unexpectedly accepted a reset-pending branch" >&2
  exit 1
fi
grep -F "unfinished reset" "$TMP/public-reset-crash-merge-blocked.out" >/dev/null
"$BIN" branch --work-dir "$WORK_DIR" reset public-reset-crash-target --from public-reset-crash-source > "$TMP/public-reset-crash-retry.out"
grep -F "reset COW branch 'public-reset-crash-target' from 'public-reset-crash-source'" "$TMP/public-reset-crash-retry.out" >/dev/null
test ! -e "$WORK_DIR/cow/reset-pending/public-reset-crash-target.txt"
test -f "$WORK/public-reset-crash-target/wp-content/public-reset-crash-source.txt"
test ! -e "$WORK/public-reset-crash-target/wp-content/public-reset-crash-target.txt"
test -f "$WORK_DIR/cow/merge/bases/public-reset-crash-target.sqlite"
test -f "$WORK_DIR/cow/merge/file-bases/public-reset-crash-target.json"
php -r '$meta = new SQLite3($argv[1]); $count = (int)$meta->querySingle("SELECT COUNT(*) FROM merge_autoincrement_bands WHERE branch_name = '\''public-reset-crash-target'\''"); exit($count > 0 ? 0 : 1);' "$WORK_DIR/cow/merge/metadata.sqlite"

log_step "merge independently banded WordPress posts"
"$BIN" branch --work-dir "$WORK_DIR" create band-merge-source > "$TMP/band-merge-source-create.out"
"$BIN" branch --work-dir "$WORK_DIR" create band-merge-target > "$TMP/band-merge-target-create.out"
grep -F "band-merge-source.wp.localhost:$PORT" "$TMP/band-merge-source-create.out" >/dev/null
grep -F "band-merge-target.wp.localhost:$PORT" "$TMP/band-merge-target-create.out" >/dev/null
BAND_SOURCE_TITLE="Band source $(date +%s)"
BAND_TARGET_TITLE="Band target $(date +%s)"
create_branch_post band-merge-source "$BAND_SOURCE_TITLE"
create_branch_post band-merge-target "$BAND_TARGET_TITLE"
BAND_SOURCE_POST_ID="$(php -r '$data = json_decode(file_get_contents($argv[1]), true); echo (int)($data["id"] ?? 0);' "$TMP/band-merge-source-rest-save.json")"
BAND_TARGET_POST_ID="$(php -r '$data = json_decode(file_get_contents($argv[1]), true); echo (int)($data["id"] ?? 0);' "$TMP/band-merge-target-rest-save.json")"
if [ "$BAND_SOURCE_POST_ID" = "0" ] || [ "$BAND_TARGET_POST_ID" = "0" ] || [ "$BAND_SOURCE_POST_ID" = "$BAND_TARGET_POST_ID" ]; then
  echo "banded source/target post IDs were not distinct: source=$BAND_SOURCE_POST_ID target=$BAND_TARGET_POST_ID" >&2
  exit 1
fi
php -r '$meta = new SQLite3($argv[1]); $source_id = (int)$argv[2]; $target_id = (int)$argv[3]; $source = $meta->querySingle("SELECT band_start, band_end FROM merge_autoincrement_bands WHERE branch_name = '\''band-merge-source'\'' AND table_name = '\''wp_posts'\''", true); $target = $meta->querySingle("SELECT band_start, band_end FROM merge_autoincrement_bands WHERE branch_name = '\''band-merge-target'\'' AND table_name = '\''wp_posts'\''", true); $ok = $source && $target && (int)$source["band_start"] !== (int)$target["band_start"] && $source_id >= (int)$source["band_start"] && $source_id <= (int)$source["band_end"] && $target_id >= (int)$target["band_start"] && $target_id <= (int)$target["band_end"]; exit($ok ? 0 : 1);' "$WORK_DIR/cow/merge/metadata.sqlite" "$BAND_SOURCE_POST_ID" "$BAND_TARGET_POST_ID"
php -r '$db = new SQLite3($argv[1]); $id = (int)$argv[2]; $json = json_encode(["linkedPostId" => $id, "branch" => "source"], JSON_UNESCAPED_SLASHES); $serialized = "a:2:{s:12:\"linkedPostId\";i:$id;s:6:\"branch\";s:6:\"source\";}"; $stmt = $db->prepare("UPDATE wp_posts SET post_content = :content WHERE ID = :id"); $stmt->bindValue(":content", $json, SQLITE3_TEXT); $stmt->bindValue(":id", $id, SQLITE3_INTEGER); $stmt->execute(); $stmt = $db->prepare("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (:id, '\''_forkpress_json_ref'\'', :json), (:id, '\''_forkpress_serialized_ref'\'', :serialized)"); $stmt->bindValue(":id", $id, SQLITE3_INTEGER); $stmt->bindValue(":json", $json, SQLITE3_TEXT); $stmt->bindValue(":serialized", $serialized, SQLITE3_TEXT); $stmt->execute();' "$WORK/band-merge-source/wp-content/database/.ht.sqlite" "$BAND_SOURCE_POST_ID"
php -r '$db = new SQLite3($argv[1]); $id = (int)$argv[2]; $json = json_encode(["linkedPostId" => $id, "branch" => "target"], JSON_UNESCAPED_SLASHES); $serialized = "a:2:{s:12:\"linkedPostId\";i:$id;s:6:\"branch\";s:6:\"target\";}"; $stmt = $db->prepare("UPDATE wp_posts SET post_content = :content WHERE ID = :id"); $stmt->bindValue(":content", $json, SQLITE3_TEXT); $stmt->bindValue(":id", $id, SQLITE3_INTEGER); $stmt->execute(); $stmt = $db->prepare("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (:id, '\''_forkpress_json_ref'\'', :json), (:id, '\''_forkpress_serialized_ref'\'', :serialized)"); $stmt->bindValue(":id", $id, SQLITE3_INTEGER); $stmt->bindValue(":json", $json, SQLITE3_TEXT); $stmt->bindValue(":serialized", $serialized, SQLITE3_TEXT); $stmt->execute();' "$WORK/band-merge-target/wp-content/database/.ht.sqlite" "$BAND_TARGET_POST_ID"
"$BIN" branch --work-dir "$WORK_DIR" merge band-merge-source --into band-merge-target > "$TMP/merge-band-posts.out"
grep -F "forkpress: merged band-merge-source into band-merge-target" "$TMP/merge-band-posts.out" >/dev/null
grep -F "status:    completed_with_conflicts" "$TMP/merge-band-posts.out" >/dev/null
curl -sS -H "Host: band-merge-target.wp.localhost:$PORT" \
  "http://127.0.0.1:$PORT/wp-admin/edit.php" \
  -o "$TMP/band-merge-target-edit.html"
grep -F "$BAND_SOURCE_TITLE" "$TMP/band-merge-target-edit.html" >/dev/null
grep -F "$BAND_TARGET_TITLE" "$TMP/band-merge-target-edit.html" >/dev/null
curl -sSL -H "Host: band-merge-target.wp.localhost:$PORT" \
  "http://127.0.0.1:$PORT/?p=$BAND_SOURCE_POST_ID" \
  -o "$TMP/band-merge-target-source-post.html"
grep -F "$BAND_SOURCE_TITLE" "$TMP/band-merge-target-source-post.html" >/dev/null
php -r '$db = new SQLite3($argv[1]); $source_id = (int)$argv[2]; $target_id = (int)$argv[3]; $source_title = $db->querySingle("SELECT post_title FROM wp_posts WHERE ID = $source_id"); $target_title = $db->querySingle("SELECT post_title FROM wp_posts WHERE ID = $target_id"); exit($source_title === $argv[4] && $target_title === $argv[5] ? 0 : 1);' "$WORK/band-merge-target/wp-content/database/.ht.sqlite" "$BAND_SOURCE_POST_ID" "$BAND_TARGET_POST_ID" "$BAND_SOURCE_TITLE" "$BAND_TARGET_TITLE"
php -r '$db = new SQLite3($argv[1]); $source_id = (int)$argv[2]; $target_id = (int)$argv[3]; $source_json = json_encode(["linkedPostId" => $source_id, "branch" => "source"], JSON_UNESCAPED_SLASHES); $target_json = json_encode(["linkedPostId" => $target_id, "branch" => "target"], JSON_UNESCAPED_SLASHES); $source_serialized = "a:2:{s:12:\"linkedPostId\";i:$source_id;s:6:\"branch\";s:6:\"source\";}"; $target_serialized = "a:2:{s:12:\"linkedPostId\";i:$target_id;s:6:\"branch\";s:6:\"target\";}"; $source_content = $db->querySingle("SELECT post_content FROM wp_posts WHERE ID = $source_id"); $target_content = $db->querySingle("SELECT post_content FROM wp_posts WHERE ID = $target_id"); $source_meta_json = $db->querySingle("SELECT meta_value FROM wp_postmeta WHERE post_id = $source_id AND meta_key = '\''_forkpress_json_ref'\''"); $target_meta_json = $db->querySingle("SELECT meta_value FROM wp_postmeta WHERE post_id = $target_id AND meta_key = '\''_forkpress_json_ref'\''"); $source_meta_serialized = $db->querySingle("SELECT meta_value FROM wp_postmeta WHERE post_id = $source_id AND meta_key = '\''_forkpress_serialized_ref'\''"); $target_meta_serialized = $db->querySingle("SELECT meta_value FROM wp_postmeta WHERE post_id = $target_id AND meta_key = '\''_forkpress_serialized_ref'\''"); $ok = $source_content === $source_json && $target_content === $target_json && $source_meta_json === $source_json && $target_meta_json === $target_json && $source_meta_serialized === $source_serialized && $target_meta_serialized === $target_serialized; exit($ok ? 0 : 1);' "$WORK/band-merge-target/wp-content/database/.ht.sqlite" "$BAND_SOURCE_POST_ID" "$BAND_TARGET_POST_ID"
BAND_SOURCE_POST_DECISION_ID="$(
  php -r 'require_once getcwd() . "/scripts/cow/merge.php"; $db = new SQLite3($argv[1]); $identity = cow_merge_identity_json(["ID" => (int)$argv[2]]); $stmt = $db->prepare("SELECT id FROM merge_decisions WHERE table_name = '\''wp_posts'\'' AND row_identity = :identity AND decision = '\''source-applied'\'' ORDER BY id DESC LIMIT 1"); $stmt->bindValue(":identity", $identity, SQLITE3_TEXT); echo (int)$stmt->execute()->fetchArray(SQLITE3_NUM)[0];' \
    "$WORK_DIR/cow/merge/metadata.sqlite" "$BAND_SOURCE_POST_ID"
)"
BAND_TARGET_POST_DECISION_ID="$(
  php -r 'require_once getcwd() . "/scripts/cow/merge.php"; $db = new SQLite3($argv[1]); $identity = cow_merge_identity_json(["ID" => (int)$argv[2]]); $stmt = $db->prepare("SELECT id FROM merge_decisions WHERE table_name = '\''wp_posts'\'' AND row_identity = :identity AND decision = '\''target-kept'\'' ORDER BY id DESC LIMIT 1"); $stmt->bindValue(":identity", $identity, SQLITE3_TEXT); echo (int)$stmt->execute()->fetchArray(SQLITE3_NUM)[0];' \
    "$WORK_DIR/cow/merge/metadata.sqlite" "$BAND_TARGET_POST_ID"
)"
if [ "$BAND_SOURCE_POST_DECISION_ID" = "0" ] || [ "$BAND_TARGET_POST_DECISION_ID" = "0" ]; then
  echo "missing banded post merge audit decisions: source=$BAND_SOURCE_POST_DECISION_ID target=$BAND_TARGET_POST_DECISION_ID" >&2
  exit 1
fi
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --format json --review --review-status unreviewed --records decisions --scope db --limit 80 > "$TMP/band-merge-source-decision-queue.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $ok = is_array($data) && (($data["filters"]["review"] ?? false) === true) && (($data["filters"]["review_status"] ?? null) === "unreviewed") && (($data["filters"]["records"] ?? null) === "decisions") && (($data["filters"]["scope"] ?? null) === "db"); $has_source = false; foreach (($data["decisions"] ?? []) as $row) { if (($row["review_status"] ?? null) !== null) $ok = false; if ((int)($row["id"] ?? 0) === (int)$argv[2] && ($row["table_name"] ?? null) === "wp_posts" && ($row["decision"] ?? null) === "source-applied") $has_source = true; } exit($ok && $has_source ? 0 : 1);' "$TMP/band-merge-source-decision-queue.json" "$BAND_SOURCE_POST_DECISION_ID"

log_step "merge WordPress semantic object graphs"
semantic_runtime_request main seed "$TMP/semantic-seed.json"
"$BIN" branch --work-dir "$WORK_DIR" create semantic-source > "$TMP/semantic-source-create.out"
"$BIN" branch --work-dir "$WORK_DIR" create semantic-target > "$TMP/semantic-target-create.out"
semantic_runtime_request semantic-source source "$TMP/semantic-source.json"
semantic_runtime_request semantic-target target "$TMP/semantic-target.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $posts = []; foreach (($data["posts"] ?? []) as $post) { $posts[$post["title"] ?? ""] = $post; } $menus = $data["menus"] ?? []; $locations = $data["menu_locations"] ?? []; $graph = $data["plugin_graphs"]["source"] ?? []; $note_id = $posts["Semantic Source Note"]["id"] ?? null; $graph_ok = (int)($graph["parent_id"] ?? 0) > 0 && (int)($graph["child_id"] ?? 0) > 0 && ($graph["child_parent_id"] ?? null) === ($graph["parent_id"] ?? null) && ($graph["json_parent_id"] ?? null) === ($graph["parent_id"] ?? null) && ($graph["serialized_parent_id"] ?? null) === ($graph["parent_id"] ?? null) && ($graph["option_parent_id"] ?? null) === ($graph["parent_id"] ?? null) && ($graph["postmeta_parent_id"] ?? null) === ($graph["parent_id"] ?? null) && ($graph["json_note_id"] ?? null) === $note_id && ($graph["serialized_note_id"] ?? null) === $note_id && ($graph["option_note_id"] ?? null) === $note_id && ($graph["postmeta_note_id"] ?? null) === $note_id && ($graph["child_payload_note_id"] ?? null) === $note_id && (($graph["file_exists"] ?? null) === true); $ok = isset($posts["Semantic Source Page"], $posts["Semantic Source Note"], $posts["Semantic Source Block"], $posts["Semantic Source Media"], $posts["Semantic Source Edited Page"]) && !isset($posts["Semantic Source Delete Page"]) && in_array("Semantic Source Menu", $menus, true) && (($locations["forkpress_semantic_source"] ?? null) === "Semantic Source Menu") && in_array("Semantic Source Topic", $posts["Semantic Source Page"]["terms"] ?? [], true) && (($posts["Semantic Source Page"]["term_parents"]["Semantic Source Topic"] ?? null) === "Semantic Source Parent Topic") && in_array("Semantic Source Topic", $posts["Semantic Source Note"]["terms"] ?? [], true) && (($posts["Semantic Source Media"]["file_exists"] ?? null) === true) && in_array("thumbnail", $posts["Semantic Source Media"]["metadata_sizes"] ?? [], true) && (($posts["Semantic Source Media"]["generated_files"]["thumbnail"] ?? null) === true) && (($data["source_option"]["branch"] ?? null) === "source") && $graph_ok; exit($ok ? 0 : 1);' "$TMP/semantic-source.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $posts = []; foreach (($data["posts"] ?? []) as $post) { $posts[$post["title"] ?? ""] = $post; } $menus = $data["menus"] ?? []; $locations = $data["menu_locations"] ?? []; $graph = $data["plugin_graphs"]["target"] ?? []; $note_id = $posts["Semantic Target Note"]["id"] ?? null; $graph_ok = (int)($graph["parent_id"] ?? 0) > 0 && (int)($graph["child_id"] ?? 0) > 0 && ($graph["child_parent_id"] ?? null) === ($graph["parent_id"] ?? null) && ($graph["json_parent_id"] ?? null) === ($graph["parent_id"] ?? null) && ($graph["serialized_parent_id"] ?? null) === ($graph["parent_id"] ?? null) && ($graph["option_parent_id"] ?? null) === ($graph["parent_id"] ?? null) && ($graph["postmeta_parent_id"] ?? null) === ($graph["parent_id"] ?? null) && ($graph["json_note_id"] ?? null) === $note_id && ($graph["serialized_note_id"] ?? null) === $note_id && ($graph["option_note_id"] ?? null) === $note_id && ($graph["postmeta_note_id"] ?? null) === $note_id && ($graph["child_payload_note_id"] ?? null) === $note_id && (($graph["file_exists"] ?? null) === true); $ok = isset($posts["Semantic Target Page"], $posts["Semantic Target Note"], $posts["Semantic Target Block"], $posts["Semantic Target Media"], $posts["Semantic Target Edited Page"]) && !isset($posts["Semantic Target Delete Page"]) && in_array("Semantic Target Menu", $menus, true) && (($locations["forkpress_semantic_target"] ?? null) === "Semantic Target Menu") && in_array("Semantic Target Topic", $posts["Semantic Target Page"]["terms"] ?? [], true) && (($posts["Semantic Target Page"]["term_parents"]["Semantic Target Topic"] ?? null) === "Semantic Target Parent Topic") && in_array("Semantic Target Topic", $posts["Semantic Target Note"]["terms"] ?? [], true) && (($posts["Semantic Target Media"]["file_exists"] ?? null) === true) && in_array("thumbnail", $posts["Semantic Target Media"]["metadata_sizes"] ?? [], true) && (($posts["Semantic Target Media"]["generated_files"]["thumbnail"] ?? null) === true) && (($data["target_option"]["branch"] ?? null) === "target") && $graph_ok; exit($ok ? 0 : 1);' "$TMP/semantic-target.json"
"$BIN" branch --work-dir "$WORK_DIR" merge semantic-source --into semantic-target > "$TMP/semantic-merge.out"
grep -F "forkpress: merged semantic-source into semantic-target" "$TMP/semantic-merge.out" >/dev/null
grep -E "status:    completed(_with_conflicts)?" "$TMP/semantic-merge.out" >/dev/null
semantic_runtime_request semantic-target inspect "$TMP/semantic-after-merge.json"
php -r '
$data = json_decode(file_get_contents($argv[1]), true);
$posts = [];
foreach (($data["posts"] ?? []) as $post) {
    $posts[$post["title"] ?? ""] = $post;
}
$menus = $data["menus"] ?? [];
$menu_items = $data["menu_items"] ?? [];
$locations = $data["menu_locations"] ?? [];
$users = $data["users"] ?? [];
$comments = $data["comments"] ?? [];
$required = [
    "Semantic Source Page" => "page",
    "Semantic Target Page" => "page",
    "Semantic Source Edited Page" => "page",
    "Semantic Target Edited Page" => "page",
    "Semantic Source Note" => "forkpress_note",
    "Semantic Target Note" => "forkpress_note",
    "Semantic Source Block" => "wp_block",
    "Semantic Target Block" => "wp_block",
    "Semantic Source Media" => "attachment",
    "Semantic Target Media" => "attachment",
];
$ok = true;
foreach ($required as $title => $type) {
    $ok = $ok && (($posts[$title]["type"] ?? null) === $type);
}
$optionRefsValid = static function (array $option, string $branch, string $suffix) use ($posts): bool {
    return (($option["branch"] ?? null) === $branch)
        && ((int)($option["user_id"] ?? 0) > 0)
        && ((int)($option["page_id"] ?? 0) === (int)($posts["Semantic $suffix Page"]["id"] ?? 0))
        && ((int)($option["note_id"] ?? 0) === (int)($posts["Semantic $suffix Note"]["id"] ?? 0))
        && ((int)($option["block_id"] ?? 0) === (int)($posts["Semantic $suffix Block"]["id"] ?? 0))
        && ((int)($option["attachment_id"] ?? 0) === (int)($posts["Semantic $suffix Media"]["id"] ?? 0))
        && ((int)($option["comment_id"] ?? 0) > 0)
        && ((int)($option["reply_id"] ?? 0) > 0);
};
$pluginGraphValid = static function (array $graphs, array $posts, string $branch, string $suffix): bool {
    $graph = $graphs[$branch] ?? [];
    $note_id = $posts["Semantic $suffix Note"]["id"] ?? null;
    return (int)($graph["parent_id"] ?? 0) > 0
        && (int)($graph["child_id"] ?? 0) > 0
        && (($graph["child_parent_id"] ?? null) === ($graph["parent_id"] ?? null))
        && (($graph["json_parent_id"] ?? null) === ($graph["parent_id"] ?? null))
        && (($graph["serialized_parent_id"] ?? null) === ($graph["parent_id"] ?? null))
        && (($graph["option_parent_id"] ?? null) === ($graph["parent_id"] ?? null))
        && (($graph["postmeta_parent_id"] ?? null) === ($graph["parent_id"] ?? null))
        && (($graph["json_note_id"] ?? null) === $note_id)
        && (($graph["serialized_note_id"] ?? null) === $note_id)
        && (($graph["option_note_id"] ?? null) === $note_id)
        && (($graph["postmeta_note_id"] ?? null) === $note_id)
        && (($graph["child_payload_note_id"] ?? null) === $note_id)
        && (($graph["file_exists"] ?? null) === true);
};
$menuItemValid = static function (array $items, array $posts, string $suffix): bool {
    $item = $items["Semantic $suffix Link"] ?? [];
    return (($item["menu"] ?? null) === "Semantic $suffix Menu")
        && (($item["type"] ?? null) === "post_type")
        && (($item["object"] ?? null) === "page")
        && ((int)($item["object_id"] ?? 0) === (int)($posts["Semantic $suffix Page"]["id"] ?? 0));
};
$userValid = static function (array $users, array $posts, array $comments, string $branch, string $suffix): bool {
    $user = $users["forkpress_semantic_$branch"] ?? [];
    $userId = (int)($user["id"] ?? 0);
    return $userId > 0
        && (($user["display_name"] ?? null) === "Semantic $suffix Author")
        && ((int)($user["graph_user_id"] ?? 0) === $userId)
        && ((int)($user["serialized_graph_user_id"] ?? 0) === $userId)
        && ((int)($posts["Semantic $suffix Page"]["author"] ?? 0) === $userId)
        && ((int)($posts["Semantic $suffix Note"]["author"] ?? 0) === $userId)
        && ((int)($posts["Semantic $suffix Media"]["author"] ?? 0) === $userId)
        && ((int)($comments["Semantic $suffix Comment"]["user_id"] ?? 0) === $userId)
        && ((int)($comments["Semantic $suffix Reply"]["user_id"] ?? 0) === $userId);
};
$commentValid = static function (array $comments, array $posts, string $suffix): bool {
    $comment = $comments["Semantic $suffix Comment"] ?? [];
    $reply = $comments["Semantic $suffix Reply"] ?? [];
    $pageId = (int)($posts["Semantic $suffix Page"]["id"] ?? 0);
    $commentId = (int)($comment["id"] ?? 0);
    $replyId = (int)($reply["id"] ?? 0);
    return $commentId > 0
        && $replyId > 0
        && ((int)($comment["post_id"] ?? 0) === $pageId)
        && ((int)($reply["post_id"] ?? 0) === $pageId)
        && ((int)($reply["parent"] ?? 0) === $commentId)
        && ((int)($comment["graph_comment_id"] ?? 0) === $commentId)
        && ((int)($comment["graph_reply_id"] ?? 0) === $replyId)
        && ((int)($reply["serialized_graph_comment_id"] ?? 0) === $commentId)
        && ((int)($reply["serialized_graph_reply_id"] ?? 0) === $replyId);
};
$reusableBlockValid = static function (array $posts, string $suffix): bool {
    $blockId = (int)($posts["Semantic $suffix Block"]["id"] ?? 0);
    $refs = array_map("intval", $posts["Semantic $suffix Page"]["block_refs"] ?? []);
    return $blockId > 0 && in_array($blockId, $refs, true);
};
$ok = $ok
    && (($posts["Semantic Source Media"]["file_exists"] ?? null) === true)
    && (($posts["Semantic Target Media"]["file_exists"] ?? null) === true)
    && in_array("thumbnail", $posts["Semantic Source Media"]["metadata_sizes"] ?? [], true)
    && in_array("thumbnail", $posts["Semantic Target Media"]["metadata_sizes"] ?? [], true)
    && (($posts["Semantic Source Media"]["generated_files"]["thumbnail"] ?? null) === true)
    && (($posts["Semantic Target Media"]["generated_files"]["thumbnail"] ?? null) === true)
    && !isset($posts["Semantic Source Delete Page"])
    && !isset($posts["Semantic Target Delete Page"])
    && in_array("Semantic Source Topic", $posts["Semantic Source Page"]["terms"] ?? [], true)
    && in_array("Semantic Target Topic", $posts["Semantic Target Page"]["terms"] ?? [], true)
    && (($posts["Semantic Source Page"]["term_parents"]["Semantic Source Topic"] ?? null) === "Semantic Source Parent Topic")
    && (($posts["Semantic Target Page"]["term_parents"]["Semantic Target Topic"] ?? null) === "Semantic Target Parent Topic")
    && in_array("Semantic Source Topic", $posts["Semantic Source Note"]["terms"] ?? [], true)
    && in_array("Semantic Target Topic", $posts["Semantic Target Note"]["terms"] ?? [], true)
    && in_array("Semantic Source Menu", $menus, true)
    && in_array("Semantic Target Menu", $menus, true)
    && (($locations["forkpress_semantic_source"] ?? null) === "Semantic Source Menu")
    && (($locations["forkpress_semantic_target"] ?? null) === "Semantic Target Menu")
    && $menuItemValid($menu_items, $posts, "Source")
    && $menuItemValid($menu_items, $posts, "Target")
    && $userValid($users, $posts, $comments, "source", "Source")
    && $userValid($users, $posts, $comments, "target", "Target")
    && $commentValid($comments, $posts, "Source")
    && $commentValid($comments, $posts, "Target")
    && $reusableBlockValid($posts, "Source")
    && $reusableBlockValid($posts, "Target")
    && $optionRefsValid($data["source_option"] ?? [], "source", "Source")
    && $optionRefsValid($data["target_option"] ?? [], "target", "Target")
    && $optionRefsValid($data["source_json_option"] ?? [], "source", "Source")
    && $optionRefsValid($data["target_json_option"] ?? [], "target", "Target")
    && $pluginGraphValid($data["plugin_graphs"] ?? [], $posts, "source", "Source")
    && $pluginGraphValid($data["plugin_graphs"] ?? [], $posts, "target", "Target");
exit($ok ? 0 : 1);
' "$TMP/semantic-after-merge.json"

log_step "merge branch into main"
php -r '$db = new SQLite3($argv[1]); $db->exec("CREATE TABLE IF NOT EXISTS forkpress_e2e_target_kept (id INTEGER PRIMARY KEY, label TEXT NOT NULL)");' "$WORK/main/wp-content/database/.ht.sqlite"
"$BIN" branch --work-dir "$WORK_DIR" create merge-source
MERGE_TITLE="Merge source $(date +%s)"
create_branch_post merge-source "$MERGE_TITLE"
php -r '$db = new SQLite3($argv[1]); $db->exec("INSERT INTO forkpress_e2e_target_kept (id, label) VALUES (1, '\''target-only row'\'')");' "$WORK/main/wp-content/database/.ht.sqlite"
echo "merged through branch merge" > "$WORK/merge-source/wp-content/merge-source-file.txt"
echo "kept on target through branch merge" > "$WORK/main/wp-content/main-target-file.txt"
mkdir -p "$WORK_DIR/cow/reset-pending"
printf 'branch=merge-source\nfrom=main\n' > "$WORK_DIR/cow/reset-pending/merge-source.txt"
if "$BIN" branch --work-dir "$WORK_DIR" merge merge-source --into main > "$TMP/merge-pending-reset.out" 2>&1; then
  echo "branch merge unexpectedly accepted a source branch with pending reset metadata" >&2
  exit 1
fi
grep -F "unfinished reset" "$TMP/merge-pending-reset.out" >/dev/null
rm -f "$WORK_DIR/cow/reset-pending/merge-source.txt"
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

log_step "public branch merge crash recovery"
"$BIN" branch --work-dir "$WORK_DIR" create public-crash-merge
PUBLIC_CRASH_TITLE="Public crash merge $(date +%s)"
create_branch_post public-crash-merge "$PUBLIC_CRASH_TITLE"
if FORKPRESS_COW_MERGE_TEST_FAILPOINT=before-target-db-commit FORKPRESS_COW_MERGE_TEST_FAILPOINT_ACTION=kill \
  "$BIN" branch --work-dir "$WORK_DIR" merge public-crash-merge --into main > "$TMP/public-crash-merge.out" 2>&1; then
  echo "public branch merge unexpectedly survived before-target-db-commit kill failpoint" >&2
  exit 1
fi
"$BIN" branch --work-dir "$WORK_DIR" recover-crash --format json > "$TMP/public-crash-recover.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); exit(is_array($data) && (int)($data["pending"] ?? 0) >= 1 ? 0 : 1);' "$TMP/public-crash-recover.json"
if "$BIN" branch --work-dir "$WORK_DIR" merge public-crash-merge --into main > "$TMP/public-crash-blocked.out" 2>&1; then
  echo "public branch merge unexpectedly ignored pending crash recovery artifact" >&2
  exit 1
fi
grep -F "pending COW merge crash recovery artifact" "$TMP/public-crash-blocked.out" >/dev/null
"$BIN" branch --work-dir "$WORK_DIR" recover-crash --restore-target-db --format json > "$TMP/public-crash-restore.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); exit(is_array($data) && (int)($data["pending"] ?? 0) === 0 && (int)($data["restored"] ?? 0) >= 1 ? 0 : 1);' "$TMP/public-crash-restore.json"
"$BIN" branch --work-dir "$WORK_DIR" merge public-crash-merge --into main > "$TMP/public-crash-retry.out"
grep -F "forkpress: merged public-crash-merge into main" "$TMP/public-crash-retry.out" >/dev/null
grep -F "status:    completed" "$TMP/public-crash-retry.out" >/dev/null
curl -sS -H "Host: wp.localhost:$PORT" \
  "http://127.0.0.1:$PORT/wp-admin/edit.php" \
  -o "$TMP/public-crash-main-edit.html"
grep -F "$PUBLIC_CRASH_TITLE" "$TMP/public-crash-main-edit.html" >/dev/null

log_step "public branch merge metadata crash recovery"
"$BIN" branch --work-dir "$WORK_DIR" create public-metadata-crash-merge
PUBLIC_METADATA_CRASH_TITLE="Public metadata crash merge $(date +%s)"
create_branch_post public-metadata-crash-merge "$PUBLIC_METADATA_CRASH_TITLE"
if FORKPRESS_COW_MERGE_TEST_FAILPOINT=before-metadata-commit FORKPRESS_COW_MERGE_TEST_FAILPOINT_ACTION=kill \
  "$BIN" branch --work-dir "$WORK_DIR" merge public-metadata-crash-merge --into main > "$TMP/public-metadata-crash-merge.out" 2>&1; then
  echo "public branch merge unexpectedly survived before-metadata-commit kill failpoint" >&2
  exit 1
fi
"$BIN" branch --work-dir "$WORK_DIR" recover-crash --format json > "$TMP/public-metadata-crash-recover.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); exit(is_array($data) && (int)($data["pending"] ?? 0) >= 1 ? 0 : 1);' "$TMP/public-metadata-crash-recover.json"
if "$BIN" branch --work-dir "$WORK_DIR" merge public-metadata-crash-merge --into main > "$TMP/public-metadata-crash-blocked.out" 2>&1; then
  echo "public branch merge unexpectedly ignored pending metadata crash recovery artifact" >&2
  exit 1
fi
grep -F "pending COW merge crash recovery artifact" "$TMP/public-metadata-crash-blocked.out" >/dev/null
"$BIN" branch --work-dir "$WORK_DIR" recover-crash --restore-target-db --format json > "$TMP/public-metadata-crash-restore.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); exit(is_array($data) && (int)($data["pending"] ?? 0) === 0 && (int)($data["restored"] ?? 0) >= 1 ? 0 : 1);' "$TMP/public-metadata-crash-restore.json"
"$BIN" branch --work-dir "$WORK_DIR" merge public-metadata-crash-merge --into main > "$TMP/public-metadata-crash-retry.out"
grep -F "forkpress: merged public-metadata-crash-merge into main" "$TMP/public-metadata-crash-retry.out" >/dev/null
grep -F "status:    completed" "$TMP/public-metadata-crash-retry.out" >/dev/null
curl -sS -H "Host: wp.localhost:$PORT" \
  "http://127.0.0.1:$PORT/wp-admin/edit.php" \
  -o "$TMP/public-metadata-crash-main-edit.html"
grep -F "$PUBLIC_METADATA_CRASH_TITLE" "$TMP/public-metadata-crash-main-edit.html" >/dev/null

log_step "public branch merge before-file crash recovery"
"$BIN" branch --work-dir "$WORK_DIR" create public-before-file-crash-merge
PUBLIC_BEFORE_FILE_CRASH_TITLE="Public before-file crash merge $(date +%s)"
create_branch_post public-before-file-crash-merge "$PUBLIC_BEFORE_FILE_CRASH_TITLE"
echo "public before-file crash merge" > "$WORK/public-before-file-crash-merge/wp-content/public-before-file-crash.txt"
if FORKPRESS_COW_MERGE_TEST_FAILPOINT=before-file-op FORKPRESS_COW_MERGE_TEST_FAILPOINT_ACTION=kill \
  "$BIN" branch --work-dir "$WORK_DIR" merge public-before-file-crash-merge --into main > "$TMP/public-before-file-crash-merge.out" 2>&1; then
  echo "public branch merge unexpectedly survived before-file-op kill failpoint" >&2
  exit 1
fi
"$BIN" branch --work-dir "$WORK_DIR" recover-crash --format json > "$TMP/public-before-file-crash-recover.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); exit(is_array($data) && (int)($data["pending"] ?? 0) >= 1 ? 0 : 1);' "$TMP/public-before-file-crash-recover.json"
if "$BIN" branch --work-dir "$WORK_DIR" merge public-before-file-crash-merge --into main > "$TMP/public-before-file-crash-blocked.out" 2>&1; then
  echo "public branch merge unexpectedly ignored pending before-file crash recovery artifact" >&2
  exit 1
fi
grep -F "pending COW merge crash recovery artifact" "$TMP/public-before-file-crash-blocked.out" >/dev/null
"$BIN" branch --work-dir "$WORK_DIR" recover-crash --restore-target-db --restore-files --format json > "$TMP/public-before-file-crash-restore.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); exit(is_array($data) && (int)($data["pending"] ?? 0) === 0 && (int)($data["restored"] ?? 0) >= 1 ? 0 : 1);' "$TMP/public-before-file-crash-restore.json"
"$BIN" branch --work-dir "$WORK_DIR" merge public-before-file-crash-merge --into main > "$TMP/public-before-file-crash-retry.out"
grep -F "forkpress: merged public-before-file-crash-merge into main" "$TMP/public-before-file-crash-retry.out" >/dev/null
grep -F "status:    completed" "$TMP/public-before-file-crash-retry.out" >/dev/null
test -f "$WORK/main/wp-content/public-before-file-crash.txt"
grep -F "public before-file crash merge" "$WORK/main/wp-content/public-before-file-crash.txt" >/dev/null
curl -sS -H "Host: wp.localhost:$PORT" \
  "http://127.0.0.1:$PORT/wp-admin/edit.php" \
  -o "$TMP/public-before-file-crash-main-edit.html"
grep -F "$PUBLIC_BEFORE_FILE_CRASH_TITLE" "$TMP/public-before-file-crash-main-edit.html" >/dev/null

log_step "public crash recovery cleanup interruption"
"$BIN" branch --work-dir "$WORK_DIR" create public-recovery-cleanup-crash
PUBLIC_RECOVERY_CLEANUP_CRASH_TITLE="Public recovery cleanup crash $(date +%s)"
create_branch_post public-recovery-cleanup-crash "$PUBLIC_RECOVERY_CLEANUP_CRASH_TITLE"
if FORKPRESS_COW_MERGE_TEST_FAILPOINT=before-target-db-commit FORKPRESS_COW_MERGE_TEST_FAILPOINT_ACTION=kill \
  "$BIN" branch --work-dir "$WORK_DIR" merge public-recovery-cleanup-crash --into main > "$TMP/public-recovery-cleanup-crash-merge.out" 2>&1; then
  echo "public branch merge unexpectedly survived recovery-cleanup fixture kill failpoint" >&2
  exit 1
fi
"$BIN" branch --work-dir "$WORK_DIR" recover-crash --format json > "$TMP/public-recovery-cleanup-crash-recover.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); exit(is_array($data) && (int)($data["pending"] ?? 0) >= 1 ? 0 : 1);' "$TMP/public-recovery-cleanup-crash-recover.json"
if FORKPRESS_COW_MERGE_TEST_FAILPOINT=after-crash-recovery-restore FORKPRESS_COW_MERGE_TEST_FAILPOINT_ACTION=exit \
  "$BIN" branch --work-dir "$WORK_DIR" recover-crash --restore-target-db --format json > "$TMP/public-recovery-cleanup-crash-restore.out" 2>&1; then
  echo "public crash recovery unexpectedly survived after-crash-recovery-restore failpoint" >&2
  exit 1
fi
"$BIN" branch --work-dir "$WORK_DIR" recover-crash --restore-target-db --format json > "$TMP/public-recovery-cleanup-crash-retry-restore.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); exit(is_array($data) && (int)($data["pending"] ?? 0) === 0 && (int)($data["restored"] ?? 0) >= 1 ? 0 : 1);' "$TMP/public-recovery-cleanup-crash-retry-restore.json"
"$BIN" branch --work-dir "$WORK_DIR" merge public-recovery-cleanup-crash --into main > "$TMP/public-recovery-cleanup-crash-retry.out"
grep -F "forkpress: merged public-recovery-cleanup-crash into main" "$TMP/public-recovery-cleanup-crash-retry.out" >/dev/null
grep -F "status:    completed" "$TMP/public-recovery-cleanup-crash-retry.out" >/dev/null
curl -sS -H "Host: wp.localhost:$PORT" \
  "http://127.0.0.1:$PORT/wp-admin/edit.php" \
  -o "$TMP/public-recovery-cleanup-crash-main-edit.html"
grep -F "$PUBLIC_RECOVERY_CLEANUP_CRASH_TITLE" "$TMP/public-recovery-cleanup-crash-main-edit.html" >/dev/null

log_step "public branch merge filesystem crash recovery"
"$BIN" branch --work-dir "$WORK_DIR" create public-file-crash-merge
PUBLIC_FILE_CRASH_TITLE="Public file crash merge $(date +%s)"
create_branch_post public-file-crash-merge "$PUBLIC_FILE_CRASH_TITLE"
echo "public file crash merge" > "$WORK/public-file-crash-merge/wp-content/public-file-crash.txt"
if FORKPRESS_COW_MERGE_TEST_FAILPOINT=after-file-op FORKPRESS_COW_MERGE_TEST_FAILPOINT_ACTION=kill \
  "$BIN" branch --work-dir "$WORK_DIR" merge public-file-crash-merge --into main > "$TMP/public-file-crash-merge.out" 2>&1; then
  echo "public branch merge unexpectedly survived after-file-op kill failpoint" >&2
  exit 1
fi
"$BIN" branch --work-dir "$WORK_DIR" recover-crash --format json > "$TMP/public-file-crash-recover.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); exit(is_array($data) && (int)($data["pending"] ?? 0) >= 1 ? 0 : 1);' "$TMP/public-file-crash-recover.json"
if "$BIN" branch --work-dir "$WORK_DIR" merge public-file-crash-merge --into main > "$TMP/public-file-crash-blocked.out" 2>&1; then
  echo "public branch merge unexpectedly ignored pending filesystem crash recovery artifact" >&2
  exit 1
fi
grep -F "pending COW merge crash recovery artifact" "$TMP/public-file-crash-blocked.out" >/dev/null
"$BIN" branch --work-dir "$WORK_DIR" recover-crash --restore-target-db --restore-files --format json > "$TMP/public-file-crash-restore.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); exit(is_array($data) && (int)($data["pending"] ?? 0) === 0 && (int)($data["restored"] ?? 0) >= 1 ? 0 : 1);' "$TMP/public-file-crash-restore.json"
"$BIN" branch --work-dir "$WORK_DIR" merge public-file-crash-merge --into main > "$TMP/public-file-crash-retry.out"
grep -F "forkpress: merged public-file-crash-merge into main" "$TMP/public-file-crash-retry.out" >/dev/null
grep -F "status:    completed" "$TMP/public-file-crash-retry.out" >/dev/null
test -f "$WORK/main/wp-content/public-file-crash.txt"
grep -F "public file crash merge" "$WORK/main/wp-content/public-file-crash.txt" >/dev/null
curl -sS -H "Host: wp.localhost:$PORT" \
  "http://127.0.0.1:$PORT/wp-admin/edit.php" \
  -o "$TMP/public-file-crash-main-edit.html"
grep -F "$PUBLIC_FILE_CRASH_TITLE" "$TMP/public-file-crash-main-edit.html" >/dev/null

ROLLBACK_FAILURE_ARTIFACT="$WORK_DIR/cow/merge/e2e-rollback-failures.jsonl"
printf '%s\n' '{"source_branch":"feature-e2e-rollback","rollback_failure":"forced runtime rollback failure"}' > "$ROLLBACK_FAILURE_ARTIFACT"
ROLLBACK_FAILURE_RUN_ID="$(
  php -r '$db = new SQLite3($argv[1]); $db->exec("INSERT INTO merge_runs (source_branch, target_branch, base_ref, status, policy, source_db, target_db, base_db, finished_at, failure_reason) VALUES (\"feature-e2e-rollback\", \"main\", NULL, \"failed\", \"generic-3way-v1\", \"/tmp/e2e-source.sqlite\", \"/tmp/e2e-target.sqlite\", \"/tmp/e2e-base.sqlite\", CURRENT_TIMESTAMP, \"forced runtime rollback failure\")"); $run = $db->lastInsertRowID(); $stmt = $db->prepare("INSERT INTO merge_rollback_failures (run_id, source_branch, target_branch, base_db, source_db, target_db, original_failure, rollback_failure, artifact_path) VALUES (:run_id, \"feature-e2e-rollback\", \"main\", \"/tmp/e2e-base.sqlite\", \"/tmp/e2e-source.sqlite\", \"/tmp/e2e-target.sqlite\", \"forced runtime file failure\", \"forced runtime rollback failure\", :artifact)"); $stmt->bindValue(":run_id", $run, SQLITE3_INTEGER); $stmt->bindValue(":artifact", $argv[2], SQLITE3_TEXT); $stmt->execute(); echo $run;' "$WORK_DIR/cow/merge/metadata.sqlite" "$ROLLBACK_FAILURE_ARTIFACT"
)"
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --format json --records rollback-failures --run "$ROLLBACK_FAILURE_RUN_ID" --limit 5 > "$TMP/merge-rollback-failures.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $rows = $data["rollback_failures"] ?? []; $runs = $data["runs"] ?? []; $ok = is_array($data) && (($data["filters"]["records"] ?? null) === "rollback-failures") && empty($data["conflicts"] ?? []) && empty($data["decisions"] ?? []) && empty($data["resolutions"] ?? []) && count($runs) === 1 && count($rows) === 1; $run = $runs[0] ?? []; $row = $rows[0] ?? []; exit($ok && (int)($run["id"] ?? 0) === (int)$argv[2] && ($run["status"] ?? null) === "failed" && (int)($row["run_id"] ?? 0) === (int)$argv[2] && ($row["source_branch"] ?? null) === "feature-e2e-rollback" && ($row["rollback_failure"] ?? null) === "forced runtime rollback failure" && ($row["artifact_path"] ?? null) === $argv[3] ? 0 : 1);' "$TMP/merge-rollback-failures.json" "$ROLLBACK_FAILURE_RUN_ID" "$ROLLBACK_FAILURE_ARTIFACT"
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --records rollback-failures --run "$ROLLBACK_FAILURE_RUN_ID" --limit 5 > "$TMP/merge-rollback-failures.out"
grep -F "filters:   records=rollback-failures" "$TMP/merge-rollback-failures.out" >/dev/null
grep -F "forced runtime rollback failure" "$TMP/merge-rollback-failures.out" >/dev/null
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --target-kept --scope files --path-prefix wp-content/main-target-file.txt --limit 8 > "$TMP/merge-target-kept-files.out"
grep -F "target-kept" "$TMP/merge-target-kept-files.out" >/dev/null
grep -F "wp-content/main-target-file.txt" "$TMP/merge-target-kept-files.out" >/dev/null
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --format json --target-kept --group-by type --limit 12 > "$TMP/merge-target-kept.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $decisions = $data["decisions"] ?? []; $groups = $data["decision_groups"] ?? []; $ok = is_array($data) && (($data["filters"]["target_kept"] ?? false) === true) && (($data["filters"]["records"] ?? null) === "decisions") && (($data["filters"]["decision"] ?? null) === "target-kept") && count($decisions) > 0; $has_db = false; foreach ($decisions as $row) { if (($row["decision"] ?? null) !== "target-kept") $ok = false; if (($row["table_name"] ?? null) === "forkpress_e2e_target_kept") $has_db = true; } $has_group = false; foreach ($groups as $group) { if (($group["group_key"] ?? null) === "target-kept" && (int)($group["decision_count"] ?? 0) > 0) $has_group = true; } exit($ok && $has_db && $has_group ? 0 : 1);' "$TMP/merge-target-kept.json"
REVIEWED_DB_DECISION_ID="$(
  php -r '$db = new SQLite3($argv[1]); $stmt = $db->prepare("SELECT id FROM merge_decisions WHERE table_name = '\''forkpress_e2e_target_kept'\'' AND decision = '\''target-kept'\'' ORDER BY id DESC LIMIT 1"); echo (int)$stmt->execute()->fetchArray(SQLITE3_NUM)[0];' \
    "$WORK_DIR/cow/merge/metadata.sqlite"
)"
UNREVIEWED_DB_DECISION_ID="$(
  php -r '$db = new SQLite3($argv[1]); $stmt = $db->prepare("SELECT id FROM merge_decisions WHERE table_name <> '\''__files__'\'' AND decision = '\''source-applied'\'' ORDER BY id DESC LIMIT 1"); echo (int)$stmt->execute()->fetchArray(SQLITE3_NUM)[0];' \
    "$WORK_DIR/cow/merge/metadata.sqlite"
)"
if [ "$REVIEWED_DB_DECISION_ID" = "0" ] || [ "$UNREVIEWED_DB_DECISION_ID" = "0" ]; then
  echo "missing DB decision ids for review queue coverage" >&2
  exit 1
fi
"$BIN" branch --work-dir "$WORK_DIR" merge-review decision "$REVIEWED_DB_DECISION_ID" --status reviewed --note "E2E reviewed target-kept DB decision" --reviewer cow-e2e > "$TMP/merge-db-decision-reviewed.out"
grep -F "forkpress: recorded COW merge review note" "$TMP/merge-db-decision-reviewed.out" >/dev/null
grep -F "record:    decision #$REVIEWED_DB_DECISION_ID" "$TMP/merge-db-decision-reviewed.out" >/dev/null
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --format json --review --review-status unreviewed --records decisions --scope db --limit 12 > "$TMP/merge-db-decision-review-queue.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $ok = is_array($data) && (($data["filters"]["review"] ?? false) === true) && (($data["filters"]["review_status"] ?? null) === "unreviewed") && (($data["filters"]["records"] ?? null) === "decisions") && (($data["filters"]["scope"] ?? null) === "db") && empty($data["conflicts"] ?? []) && empty($data["resolutions"] ?? []); $has_unreviewed = false; foreach (($data["decisions"] ?? []) as $row) { if (($row["review_status"] ?? null) !== null) $ok = false; if (($row["table_name"] ?? null) === "__files__") $ok = false; if ((int)($row["id"] ?? 0) === (int)$argv[2]) $ok = false; if ((int)($row["id"] ?? 0) === (int)$argv[3] && ($row["decision"] ?? null) === "source-applied") $has_unreviewed = true; } exit($ok && $has_unreviewed ? 0 : 1);' "$TMP/merge-db-decision-review-queue.json" "$REVIEWED_DB_DECISION_ID" "$UNREVIEWED_DB_DECISION_ID"
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --format json --records decisions --review-status reviewed --scope db --limit 8 > "$TMP/merge-db-decision-reviewed.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $decisions = $data["decisions"] ?? []; $ok = is_array($data) && (($data["filters"]["review_status"] ?? null) === "reviewed") && (($data["filters"]["records"] ?? null) === "decisions") && (($data["filters"]["scope"] ?? null) === "db") && count($decisions) === 1; $row = $decisions[0] ?? []; exit($ok && (int)($row["id"] ?? 0) === (int)$argv[2] && ($row["review_status"] ?? null) === "reviewed" && ($row["review_note"] ?? null) === "E2E reviewed target-kept DB decision" ? 0 : 1);' "$TMP/merge-db-decision-reviewed.json" "$REVIEWED_DB_DECISION_ID"
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --format json --review-status unreviewed --scope files --path-prefix wp-content/main-target-file.txt --limit 8 > "$TMP/merge-unreviewed.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $decisions = $data["decisions"] ?? []; $ok = is_array($data) && (($data["filters"]["records"] ?? null) === "all") && (($data["filters"]["review_status"] ?? null) === "unreviewed"); $has_file = false; foreach ($decisions as $row) { if (($row["review_status"] ?? null) !== null) $ok = false; if (($row["table_name"] ?? null) === "__files__" && ($row["decision"] ?? null) === "target-kept" && str_contains((string)($row["target_preview"] ?? ""), "wp-content/main-target-file.txt")) $has_file = true; } exit($ok && $has_file ? 0 : 1);' "$TMP/merge-unreviewed.json"
REVIEWED_FILE_DECISION_ID="$(
  php -r 'require_once getcwd() . "/scripts/cow/merge.php"; $db = new SQLite3($argv[1]); $identity = cow_merge_file_identity_json("wp-content/main-target-file.txt"); $stmt = $db->prepare("SELECT id FROM merge_decisions WHERE table_name = '\''__files__'\'' AND row_identity = :identity AND decision = '\''target-kept'\'' ORDER BY id DESC LIMIT 1"); $stmt->bindValue(":identity", $identity, SQLITE3_TEXT); echo (int)$stmt->execute()->fetchArray(SQLITE3_NUM)[0];' \
    "$WORK_DIR/cow/merge/metadata.sqlite"
)"
UNREVIEWED_FILE_DECISION_ID="$(
  php -r 'require_once getcwd() . "/scripts/cow/merge.php"; $db = new SQLite3($argv[1]); $identity = cow_merge_file_identity_json("wp-content/merge-source-file.txt"); $stmt = $db->prepare("SELECT id FROM merge_decisions WHERE table_name = '\''__files__'\'' AND row_identity = :identity AND decision = '\''source-applied'\'' ORDER BY id DESC LIMIT 1"); $stmt->bindValue(":identity", $identity, SQLITE3_TEXT); echo (int)$stmt->execute()->fetchArray(SQLITE3_NUM)[0];' \
    "$WORK_DIR/cow/merge/metadata.sqlite"
)"
if [ "$REVIEWED_FILE_DECISION_ID" = "0" ] || [ "$UNREVIEWED_FILE_DECISION_ID" = "0" ]; then
  echo "missing file decision ids for review queue coverage" >&2
  exit 1
fi
"$BIN" branch --work-dir "$WORK_DIR" merge-review decision "$REVIEWED_FILE_DECISION_ID" --status reviewed --note "E2E reviewed target-kept file decision" --reviewer cow-e2e > "$TMP/merge-file-decision-reviewed.out"
grep -F "forkpress: recorded COW merge review note" "$TMP/merge-file-decision-reviewed.out" >/dev/null
grep -F "record:    decision #$REVIEWED_FILE_DECISION_ID" "$TMP/merge-file-decision-reviewed.out" >/dev/null
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --format json --review --review-status unreviewed --records decisions --scope files --limit 12 > "$TMP/merge-file-decision-review-queue.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $ok = is_array($data) && (($data["filters"]["review"] ?? false) === true) && (($data["filters"]["review_status"] ?? null) === "unreviewed") && (($data["filters"]["records"] ?? null) === "decisions") && (($data["filters"]["scope"] ?? null) === "files") && empty($data["conflicts"] ?? []) && empty($data["resolutions"] ?? []); $has_unreviewed = false; foreach (($data["decisions"] ?? []) as $row) { if (($row["review_status"] ?? null) !== null) $ok = false; if (($row["table_name"] ?? null) !== "__files__") $ok = false; if ((int)($row["id"] ?? 0) === (int)$argv[2]) $ok = false; if ((int)($row["id"] ?? 0) === (int)$argv[3] && ($row["decision"] ?? null) === "source-applied") $has_unreviewed = true; } exit($ok && $has_unreviewed ? 0 : 1);' "$TMP/merge-file-decision-review-queue.json" "$REVIEWED_FILE_DECISION_ID" "$UNREVIEWED_FILE_DECISION_ID"
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --format json --records decisions --review-status reviewed --scope files --path wp-content/main-target-file.txt --limit 8 > "$TMP/merge-file-decision-reviewed.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $decisions = $data["decisions"] ?? []; $ok = is_array($data) && (($data["filters"]["review_status"] ?? null) === "reviewed") && (($data["filters"]["records"] ?? null) === "decisions") && (($data["filters"]["scope"] ?? null) === "files") && count($decisions) === 1; $row = $decisions[0] ?? []; exit($ok && (int)($row["id"] ?? 0) === (int)$argv[2] && ($row["review_status"] ?? null) === "reviewed" && ($row["review_note"] ?? null) === "E2E reviewed target-kept file decision" ? 0 : 1);' "$TMP/merge-file-decision-reviewed.json" "$REVIEWED_FILE_DECISION_ID"

log_step "merge file review queues"
echo "file review base one" > "$WORK/main/wp-content/file-review-one.txt"
echo "file review base two" > "$WORK/main/wp-content/file-review-two.txt"
"$BIN" branch --work-dir "$WORK_DIR" create file-review-source > "$TMP/file-review-create.out"
grep -F "file-review-source.wp.localhost:$PORT" "$TMP/file-review-create.out" >/dev/null
echo "file review source one" > "$WORK/file-review-source/wp-content/file-review-one.txt"
echo "file review source two" > "$WORK/file-review-source/wp-content/file-review-two.txt"
echo "file review target one" > "$WORK/main/wp-content/file-review-one.txt"
echo "file review target two" > "$WORK/main/wp-content/file-review-two.txt"
"$BIN" branch --work-dir "$WORK_DIR" merge file-review-source --into main > "$TMP/file-review-merge.out"
grep -F "forkpress: merged file-review-source into main" "$TMP/file-review-merge.out" >/dev/null
grep -F "status:    completed_with_conflicts" "$TMP/file-review-merge.out" >/dev/null
grep -F "file review target one" "$WORK/main/wp-content/file-review-one.txt" >/dev/null
grep -F "file review target two" "$WORK/main/wp-content/file-review-two.txt" >/dev/null
REVIEWED_FILE_CONFLICT_ID="$(
  php -r 'require_once getcwd() . "/scripts/cow/merge.php"; $db = new SQLite3($argv[1]); $identity = cow_merge_file_identity_json("wp-content/file-review-one.txt"); $stmt = $db->prepare("SELECT id FROM merge_conflicts WHERE table_name = '\''__files__'\'' AND row_identity = :identity AND conflict_type = '\''file-conflict'\'' ORDER BY id DESC LIMIT 1"); $stmt->bindValue(":identity", $identity, SQLITE3_TEXT); echo (int)$stmt->execute()->fetchArray(SQLITE3_NUM)[0];' \
    "$WORK_DIR/cow/merge/metadata.sqlite"
)"
UNREVIEWED_FILE_CONFLICT_ID="$(
  php -r 'require_once getcwd() . "/scripts/cow/merge.php"; $db = new SQLite3($argv[1]); $identity = cow_merge_file_identity_json("wp-content/file-review-two.txt"); $stmt = $db->prepare("SELECT id FROM merge_conflicts WHERE table_name = '\''__files__'\'' AND row_identity = :identity AND conflict_type = '\''file-conflict'\'' ORDER BY id DESC LIMIT 1"); $stmt->bindValue(":identity", $identity, SQLITE3_TEXT); echo (int)$stmt->execute()->fetchArray(SQLITE3_NUM)[0];' \
    "$WORK_DIR/cow/merge/metadata.sqlite"
)"
if [ "$REVIEWED_FILE_CONFLICT_ID" = "0" ] || [ "$UNREVIEWED_FILE_CONFLICT_ID" = "0" ]; then
  echo "missing file conflict ids for review queue coverage" >&2
  exit 1
fi
"$BIN" branch --work-dir "$WORK_DIR" merge-review conflict "$REVIEWED_FILE_CONFLICT_ID" --status reviewed --note "E2E reviewed file conflict" --reviewer cow-e2e > "$TMP/file-conflict-reviewed.out"
grep -F "forkpress: recorded COW merge review note" "$TMP/file-conflict-reviewed.out" >/dev/null
grep -F "record:    conflict #$REVIEWED_FILE_CONFLICT_ID" "$TMP/file-conflict-reviewed.out" >/dev/null
"$BIN" branch --work-dir "$WORK_DIR" merge-review conflict "$REVIEWED_FILE_CONFLICT_ID" --status pending --note "E2E pending file conflict follow-up" --reviewer cow-e2e > "$TMP/file-conflict-pending.out"
grep -F "forkpress: recorded COW merge review note" "$TMP/file-conflict-pending.out" >/dev/null
grep -F "status:    pending" "$TMP/file-conflict-pending.out" >/dev/null
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --format json --review --review-status pending --records conflicts --scope files --limit 12 > "$TMP/file-conflict-pending-queue.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $ok = is_array($data) && (($data["filters"]["review"] ?? false) === true) && (($data["filters"]["review_status"] ?? null) === "pending") && (($data["filters"]["records"] ?? null) === "conflicts") && (($data["filters"]["scope"] ?? null) === "files") && empty($data["decisions"] ?? []) && empty($data["resolutions"] ?? []); $has_pending = false; foreach (($data["conflicts"] ?? []) as $row) { if (($row["table_name"] ?? null) !== "__files__") $ok = false; if ((int)($row["id"] ?? 0) === (int)$argv[3]) $ok = false; if ((int)($row["id"] ?? 0) === (int)$argv[2] && ($row["review_status"] ?? null) === "pending" && ($row["review_note"] ?? null) === "E2E pending file conflict follow-up") $has_pending = true; } exit($ok && $has_pending ? 0 : 1);' "$TMP/file-conflict-pending-queue.json" "$REVIEWED_FILE_CONFLICT_ID" "$UNREVIEWED_FILE_CONFLICT_ID"
"$BIN" branch --work-dir "$WORK_DIR" merge-review conflict "$REVIEWED_FILE_CONFLICT_ID" --status needs-action --note "E2E needs-action file conflict follow-up" --reviewer cow-e2e > "$TMP/file-conflict-needs-action.out"
grep -F "forkpress: recorded COW merge review note" "$TMP/file-conflict-needs-action.out" >/dev/null
grep -F "status:    needs-action" "$TMP/file-conflict-needs-action.out" >/dev/null
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --format json --review --review-status needs-action --records conflicts --scope files --limit 12 > "$TMP/file-conflict-needs-action-queue.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $ok = is_array($data) && (($data["filters"]["review"] ?? false) === true) && (($data["filters"]["review_status"] ?? null) === "needs-action") && (($data["filters"]["records"] ?? null) === "conflicts") && (($data["filters"]["scope"] ?? null) === "files") && empty($data["decisions"] ?? []) && empty($data["resolutions"] ?? []); $has_needs_action = false; foreach (($data["conflicts"] ?? []) as $row) { if (($row["table_name"] ?? null) !== "__files__") $ok = false; if ((int)($row["id"] ?? 0) === (int)$argv[3]) $ok = false; if ((int)($row["id"] ?? 0) === (int)$argv[2] && ($row["review_status"] ?? null) === "needs-action" && ($row["review_note"] ?? null) === "E2E needs-action file conflict follow-up") $has_needs_action = true; } exit($ok && $has_needs_action ? 0 : 1);' "$TMP/file-conflict-needs-action-queue.json" "$REVIEWED_FILE_CONFLICT_ID" "$UNREVIEWED_FILE_CONFLICT_ID"
"$BIN" branch --work-dir "$WORK_DIR" merge-review conflict "$REVIEWED_FILE_CONFLICT_ID" --status reviewed --note "E2E closed file conflict review" --reviewer cow-e2e > "$TMP/file-conflict-closed.out"
grep -F "forkpress: recorded COW merge review note" "$TMP/file-conflict-closed.out" >/dev/null
grep -F "status:    reviewed" "$TMP/file-conflict-closed.out" >/dev/null
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --format json --review --review-status reviewed --records conflicts --scope files --limit 12 > "$TMP/file-conflict-reviewed-queue.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $ok = is_array($data) && (($data["filters"]["review"] ?? false) === true) && (($data["filters"]["review_status"] ?? null) === "reviewed") && (($data["filters"]["records"] ?? null) === "conflicts") && (($data["filters"]["scope"] ?? null) === "files") && empty($data["decisions"] ?? []) && empty($data["resolutions"] ?? []); $has_reviewed = false; foreach (($data["conflicts"] ?? []) as $row) { if (($row["table_name"] ?? null) !== "__files__") $ok = false; if ((int)($row["id"] ?? 0) === (int)$argv[3]) $ok = false; if ((int)($row["id"] ?? 0) === (int)$argv[2] && ($row["review_status"] ?? null) === "reviewed" && ($row["review_note"] ?? null) === "E2E closed file conflict review") $has_reviewed = true; } exit($ok && $has_reviewed ? 0 : 1);' "$TMP/file-conflict-reviewed-queue.json" "$REVIEWED_FILE_CONFLICT_ID" "$UNREVIEWED_FILE_CONFLICT_ID"
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --format json --review --review-status unreviewed --records conflicts --scope files --limit 12 > "$TMP/file-conflict-review-queue.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $ok = is_array($data) && (($data["filters"]["review"] ?? false) === true) && (($data["filters"]["review_status"] ?? null) === "unreviewed") && (($data["filters"]["records"] ?? null) === "conflicts") && (($data["filters"]["scope"] ?? null) === "files") && empty($data["decisions"] ?? []) && empty($data["resolutions"] ?? []); $has_unreviewed = false; foreach (($data["conflicts"] ?? []) as $row) { if (($row["review_status"] ?? null) !== null) $ok = false; if (($row["table_name"] ?? null) !== "__files__") $ok = false; if ((int)($row["id"] ?? 0) === (int)$argv[2]) $ok = false; if ((int)($row["id"] ?? 0) === (int)$argv[3] && ($row["conflict_type"] ?? null) === "file-conflict") $has_unreviewed = true; } exit($ok && $has_unreviewed ? 0 : 1);' "$TMP/file-conflict-review-queue.json" "$REVIEWED_FILE_CONFLICT_ID" "$UNREVIEWED_FILE_CONFLICT_ID"
"$BIN" branch --work-dir "$WORK_DIR" merge-resolve conflict "$REVIEWED_FILE_CONFLICT_ID" --choice source --apply --note "E2E apply source file one" --reviewer cow-e2e > "$TMP/file-resolve-one.out"
grep -F "forkpress: validated COW merge conflict resolution" "$TMP/file-resolve-one.out" >/dev/null
grep -F "applied:   yes" "$TMP/file-resolve-one.out" >/dev/null
"$BIN" branch --work-dir "$WORK_DIR" merge-resolve conflict "$UNREVIEWED_FILE_CONFLICT_ID" --choice source --apply --note "E2E apply source file two" --reviewer cow-e2e > "$TMP/file-resolve-two.out"
grep -F "forkpress: validated COW merge conflict resolution" "$TMP/file-resolve-two.out" >/dev/null
grep -F "applied:   yes" "$TMP/file-resolve-two.out" >/dev/null
grep -F "file review source one" "$WORK/main/wp-content/file-review-one.txt" >/dev/null
grep -F "file review source two" "$WORK/main/wp-content/file-review-two.txt" >/dev/null
REVIEWED_FILE_RESOLUTION_ID="$(
  php -r '$db = new SQLite3($argv[1]); echo (int)$db->querySingle("SELECT id FROM merge_resolutions WHERE conflict_id = " . (int)$argv[2] . " ORDER BY id DESC LIMIT 1");' \
    "$WORK_DIR/cow/merge/metadata.sqlite" "$REVIEWED_FILE_CONFLICT_ID"
)"
UNREVIEWED_FILE_RESOLUTION_ID="$(
  php -r '$db = new SQLite3($argv[1]); echo (int)$db->querySingle("SELECT id FROM merge_resolutions WHERE conflict_id = " . (int)$argv[2] . " ORDER BY id DESC LIMIT 1");' \
    "$WORK_DIR/cow/merge/metadata.sqlite" "$UNREVIEWED_FILE_CONFLICT_ID"
)"
if [ "$REVIEWED_FILE_RESOLUTION_ID" = "0" ] || [ "$UNREVIEWED_FILE_RESOLUTION_ID" = "0" ]; then
  echo "missing file resolution ids for review queue coverage" >&2
  exit 1
fi
"$BIN" branch --work-dir "$WORK_DIR" merge-review resolution "$REVIEWED_FILE_RESOLUTION_ID" --status reviewed --note "E2E reviewed file resolution" --reviewer cow-e2e > "$TMP/file-resolution-reviewed.out"
grep -F "forkpress: recorded COW merge review note" "$TMP/file-resolution-reviewed.out" >/dev/null
grep -F "record:    resolution #$REVIEWED_FILE_RESOLUTION_ID" "$TMP/file-resolution-reviewed.out" >/dev/null
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --format json --review --review-status reviewed --records resolutions --scope files --limit 12 > "$TMP/file-resolution-reviewed-queue.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $ok = is_array($data) && (($data["filters"]["review"] ?? false) === true) && (($data["filters"]["review_status"] ?? null) === "reviewed") && (($data["filters"]["records"] ?? null) === "resolutions") && (($data["filters"]["scope"] ?? null) === "files") && empty($data["conflicts"] ?? []) && empty($data["decisions"] ?? []); $has_reviewed = false; foreach (($data["resolutions"] ?? []) as $row) { if (($row["table_name"] ?? null) !== "__files__") $ok = false; if ((int)($row["id"] ?? 0) === (int)$argv[3]) $ok = false; if ((int)($row["id"] ?? 0) === (int)$argv[2] && ($row["review_status"] ?? null) === "reviewed" && ($row["review_note"] ?? null) === "E2E reviewed file resolution") $has_reviewed = true; } exit($ok && $has_reviewed ? 0 : 1);' "$TMP/file-resolution-reviewed-queue.json" "$REVIEWED_FILE_RESOLUTION_ID" "$UNREVIEWED_FILE_RESOLUTION_ID"
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --format json --review --review-status unreviewed --records resolutions --scope files --limit 12 > "$TMP/file-resolution-review-queue.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $ok = is_array($data) && (($data["filters"]["review"] ?? false) === true) && (($data["filters"]["review_status"] ?? null) === "unreviewed") && (($data["filters"]["records"] ?? null) === "resolutions") && (($data["filters"]["scope"] ?? null) === "files") && empty($data["conflicts"] ?? []) && empty($data["decisions"] ?? []); $has_unreviewed = false; foreach (($data["resolutions"] ?? []) as $row) { if (($row["review_status"] ?? null) !== null) $ok = false; if (($row["table_name"] ?? null) !== "__files__") $ok = false; if ((int)($row["id"] ?? 0) === (int)$argv[2]) $ok = false; if ((int)($row["id"] ?? 0) === (int)$argv[3]) $has_unreviewed = true; } exit($ok && $has_unreviewed ? 0 : 1);' "$TMP/file-resolution-review-queue.json" "$REVIEWED_FILE_RESOLUTION_ID" "$UNREVIEWED_FILE_RESOLUTION_ID"

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
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --format json --review --review-status unreviewed --records conflicts --scope db --limit 8 > "$TMP/keyless-review-queue.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $ok = is_array($data) && (($data["filters"]["review"] ?? false) === true) && (($data["filters"]["review_status"] ?? null) === "unreviewed") && (($data["filters"]["records"] ?? null) === "conflicts") && (($data["filters"]["scope"] ?? null) === "db") && empty($data["decisions"] ?? []) && empty($data["resolutions"] ?? []); $has_conflict = false; foreach (($data["conflicts"] ?? []) as $row) { if (($row["review_status"] ?? null) !== null) $ok = false; if ((int)($row["id"] ?? 0) === (int)$argv[2] && ($row["table_name"] ?? null) === "wp_forkpress_e2e_keyless" && ($row["conflict_type"] ?? null) === "row-source-deleted") $has_conflict = true; } exit($ok && $has_conflict ? 0 : 1);' "$TMP/keyless-review-queue.json" "$KEYLESS_CONFLICT_ID"
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
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --format json --review --review-status unreviewed --records resolutions --scope db --limit 8 > "$TMP/keyless-unreviewed-resolution-queue.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $ok = is_array($data) && (($data["filters"]["review"] ?? false) === true) && (($data["filters"]["review_status"] ?? null) === "unreviewed") && (($data["filters"]["records"] ?? null) === "resolutions") && (($data["filters"]["scope"] ?? null) === "db") && empty($data["conflicts"] ?? []) && empty($data["decisions"] ?? []); $has_resolution = false; foreach (($data["resolutions"] ?? []) as $row) { if (($row["review_status"] ?? null) !== null) $ok = false; if (($row["table_name"] ?? null) === "__files__") $ok = false; if ((int)($row["id"] ?? 0) === (int)$argv[2] && ($row["table_name"] ?? null) === "wp_forkpress_e2e_keyless") $has_resolution = true; } exit($ok && $has_resolution ? 0 : 1);' "$TMP/keyless-unreviewed-resolution-queue.json" "$KEYLESS_RESOLUTION_ID"
"$BIN" branch --work-dir "$WORK_DIR" merge-review resolution "$KEYLESS_RESOLUTION_ID" --status needs-action --note "E2E follow-up on runtime keyless resolution" --reviewer cow-e2e > "$TMP/keyless-resolution-review.out"
grep -F "forkpress: recorded COW merge review note" "$TMP/keyless-resolution-review.out" >/dev/null
grep -F "record:    resolution #$KEYLESS_RESOLUTION_ID" "$TMP/keyless-resolution-review.out" >/dev/null
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --records resolutions --review-status needs-action --limit 8 > "$TMP/keyless-resolution-audit.out"
grep -F "wp_forkpress_e2e_keyless" "$TMP/keyless-resolution-audit.out" >/dev/null
grep -F "review=needs-action" "$TMP/keyless-resolution-audit.out" >/dev/null
grep -F "E2E follow-up on runtime keyless resolution" "$TMP/keyless-resolution-audit.out" >/dev/null
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --format json --review --review-status needs-action --records resolutions --scope db --limit 8 > "$TMP/keyless-resolution-needs-action-queue.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $ok = is_array($data) && (($data["filters"]["review"] ?? false) === true) && (($data["filters"]["review_status"] ?? null) === "needs-action") && (($data["filters"]["records"] ?? null) === "resolutions") && (($data["filters"]["scope"] ?? null) === "db") && empty($data["conflicts"] ?? []) && empty($data["decisions"] ?? []); $has_resolution = false; foreach (($data["resolutions"] ?? []) as $row) { if (($row["table_name"] ?? null) === "__files__") $ok = false; if ((int)($row["id"] ?? 0) === (int)$argv[2] && ($row["review_status"] ?? null) === "needs-action" && ($row["review_note"] ?? null) === "E2E follow-up on runtime keyless resolution") $has_resolution = true; } exit($ok && $has_resolution ? 0 : 1);' "$TMP/keyless-resolution-needs-action-queue.json" "$KEYLESS_RESOLUTION_ID"
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --format json --resolution-status validated --group-by status --limit 8 > "$TMP/keyless-resolution-status.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $ok = is_array($data) && (($data["filters"]["records"] ?? null) === "resolutions") && (($data["filters"]["resolution_status"] ?? null) === "validated") && (($data["filters"]["group_by"] ?? null) === "status"); $has_resolution = false; foreach (($data["resolutions"] ?? []) as $row) { if ((int)($row["id"] ?? 0) === (int)$argv[2] && ($row["status"] ?? null) === "validated") $has_resolution = true; } $has_group = false; foreach (($data["resolution_groups"] ?? []) as $group) { if (($group["group_key"] ?? null) === "validated" && (int)($group["resolution_count"] ?? 0) > 0) $has_group = true; } exit($ok && $has_resolution && $has_group ? 0 : 1);' "$TMP/keyless-resolution-status.json" "$KEYLESS_RESOLUTION_ID"

log_step "bound offline no-PK rowid ambiguity"
php -r '$db = new SQLite3($argv[1]); $db->exec("DROP TABLE IF EXISTS wp_forkpress_e2e_offline_keyless"); $db->exec("CREATE TABLE wp_forkpress_e2e_offline_keyless (label TEXT, value TEXT)"); $db->exec("INSERT INTO wp_forkpress_e2e_offline_keyless (label, value) VALUES ('\''Offline base row'\'', '\''base'\'')");' "$WORK/main/wp-content/database/.ht.sqlite"
"$BIN" branch --work-dir "$WORK_DIR" create offline-keyless-reuse > "$TMP/offline-keyless-create.out"
grep -F "offline-keyless-reuse.wp.localhost:$PORT" "$TMP/offline-keyless-create.out" >/dev/null
php -r '$db = new SQLite3($argv[1]); $db->exec("DELETE FROM wp_forkpress_e2e_offline_keyless WHERE rowid = 1"); $db->exec("INSERT INTO wp_forkpress_e2e_offline_keyless (label, value) VALUES ('\''Offline reused source row'\'', '\''new offline row'\'')"); $row = $db->querySingle("SELECT rowid, label, value FROM wp_forkpress_e2e_offline_keyless", true); file_put_contents($argv[2], json_encode($row)); exit(((int)($row["rowid"] ?? 0) === 1 && ($row["label"] ?? null) === "Offline reused source row") ? 0 : 1);' "$WORK/offline-keyless-reuse/wp-content/database/.ht.sqlite" "$TMP/offline-keyless-source.json"
php -r '$db = new SQLite3($argv[1]); $db->exec("UPDATE wp_forkpress_e2e_offline_keyless SET value = '\''target kept offline old row'\'' WHERE rowid = 1"); $row = $db->querySingle("SELECT rowid, label, value FROM wp_forkpress_e2e_offline_keyless", true); file_put_contents($argv[2], json_encode($row)); exit(((int)($row["rowid"] ?? 0) === 1 && ($row["value"] ?? null) === "target kept offline old row") ? 0 : 1);' "$WORK/main/wp-content/database/.ht.sqlite" "$TMP/offline-keyless-target.json"
"$BIN" branch --work-dir "$WORK_DIR" merge offline-keyless-reuse --into main > "$TMP/offline-keyless-merge.out"
grep -F "forkpress: merged offline-keyless-reuse into main" "$TMP/offline-keyless-merge.out" >/dev/null
grep -F "status:    completed_with_conflicts" "$TMP/offline-keyless-merge.out" >/dev/null
php -r '$db = new SQLite3($argv[1]); $rows = []; $res = $db->query("SELECT rowid, label, value FROM wp_forkpress_e2e_offline_keyless ORDER BY rowid"); while ($row = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $row; file_put_contents($argv[2], json_encode(["rows" => $rows])); exit(count($rows) === 1 && ($rows[0]["label"] ?? null) === "Offline base row" && ($rows[0]["value"] ?? null) === "target kept offline old row" ? 0 : 1);' "$WORK/main/wp-content/database/.ht.sqlite" "$TMP/offline-keyless-after-merge.json"
OFFLINE_KEYLESS_CONFLICT_ID="$(
  php -r '$db = new SQLite3($argv[1]); echo (int)$db->querySingle("SELECT c.id FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = '\''wp_forkpress_e2e_offline_keyless'\'' AND c.conflict_type = '\''row-identity-ambiguous'\'' AND r.source_branch = '\''offline-keyless-reuse'\'' AND r.target_branch = '\''main'\'' ORDER BY c.id DESC LIMIT 1");' \
    "$WORK_DIR/cow/merge/metadata.sqlite"
)"
if [ "$OFFLINE_KEYLESS_CONFLICT_ID" = "0" ]; then
  echo "missing offline keyless row-identity-ambiguous conflict id" >&2
  exit 1
fi
php -r '$db = new SQLite3($argv[1]); $conflict_id = (int)$argv[2]; $target_wins = (int)$db->querySingle("SELECT COUNT(*) FROM merge_decisions WHERE table_name = '\''wp_forkpress_e2e_offline_keyless'\'' AND decision = '\''target-wins'\'' AND reason LIKE '\''no-primary-key source row changed cells that target did not change%'\''"); exit($conflict_id > 0 && $target_wins > 0 ? 0 : 1);' "$WORK_DIR/cow/merge/metadata.sqlite" "$OFFLINE_KEYLESS_CONFLICT_ID"
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --format json --scope db --records conflicts --conflict-type row-identity-ambiguous --limit 20 > "$TMP/offline-keyless-audit.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $ok = is_array($data) && (($data["filters"]["conflict_type"] ?? null) === "row-identity-ambiguous") && (($data["filters"]["records"] ?? null) === "conflicts") && (($data["filters"]["scope"] ?? null) === "db"); $has_conflict = false; foreach (($data["conflicts"] ?? []) as $row) { if ((int)($row["id"] ?? 0) === (int)$argv[2] && ($row["table_name"] ?? null) === "wp_forkpress_e2e_offline_keyless" && ($row["conflict_type"] ?? null) === "row-identity-ambiguous") $has_conflict = true; } exit($ok && $has_conflict ? 0 : 1);' "$TMP/offline-keyless-audit.json" "$OFFLINE_KEYLESS_CONFLICT_ID"
"$BIN" branch --work-dir "$WORK_DIR" merge-resolve conflict "$OFFLINE_KEYLESS_CONFLICT_ID" --choice source --apply --note "Apply reviewed offline no-PK row choice" --reviewer cow-e2e > "$TMP/offline-keyless-resolve.out"
grep -F "forkpress: validated COW merge conflict resolution" "$TMP/offline-keyless-resolve.out" >/dev/null
grep -F "applied:   yes" "$TMP/offline-keyless-resolve.out" >/dev/null
php -r '$db = new SQLite3($argv[1]); $rows = []; $res = $db->query("SELECT rowid, label, value FROM wp_forkpress_e2e_offline_keyless ORDER BY rowid"); while ($row = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $row; file_put_contents($argv[2], json_encode(["rows" => $rows])); exit(count($rows) === 1 && ($rows[0]["label"] ?? null) === "Offline reused source row" && ($rows[0]["value"] ?? null) === "new offline row" ? 0 : 1);' "$WORK/main/wp-content/database/.ht.sqlite" "$TMP/offline-keyless-after-resolution.json"
php -r '$db = new SQLite3($argv[1]); $conflict_id = (int)$argv[2]; $resolution = (int)$db->querySingle("SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $conflict_id AND table_name = '\''wp_forkpress_e2e_offline_keyless'\'' AND choice = '\''source'\'' AND applied = 1"); $reviewed = (int)$db->querySingle("SELECT COUNT(*) FROM merge_review_notes WHERE record_type = '\''conflict'\'' AND record_id = $conflict_id AND status = '\''reviewed'\''"); exit($resolution === 1 && $reviewed === 1 ? 0 : 1);' "$WORK_DIR/cow/merge/metadata.sqlite" "$OFFLINE_KEYLESS_CONFLICT_ID"

log_step "bound partial offline no-PK rowid ambiguity"
php -r '$db = new SQLite3($argv[1]); $db->exec("DROP TABLE IF EXISTS wp_forkpress_e2e_offline_keyless_partial"); $db->exec("CREATE TABLE wp_forkpress_e2e_offline_keyless_partial (label TEXT, value TEXT)"); $db->exec("INSERT INTO wp_forkpress_e2e_offline_keyless_partial (label, value) VALUES ('\''Partial base row'\'', '\''base'\'')");' "$WORK/main/wp-content/database/.ht.sqlite"
"$BIN" branch --work-dir "$WORK_DIR" create offline-keyless-partial > "$TMP/offline-keyless-partial-create.out"
grep -F "offline-keyless-partial.wp.localhost:$PORT" "$TMP/offline-keyless-partial-create.out" >/dev/null
php -r '$db = new SQLite3($argv[1]); $db->exec("DELETE FROM wp_forkpress_e2e_offline_keyless_partial WHERE rowid = 1"); $db->exec("INSERT INTO wp_forkpress_e2e_offline_keyless_partial (label, value) VALUES ('\''Partial reused source row'\'', '\''base'\'')"); $row = $db->querySingle("SELECT rowid, label, value FROM wp_forkpress_e2e_offline_keyless_partial", true); file_put_contents($argv[2], json_encode($row)); exit(((int)($row["rowid"] ?? 0) === 1 && ($row["label"] ?? null) === "Partial reused source row" && ($row["value"] ?? null) === "base") ? 0 : 1);' "$WORK/offline-keyless-partial/wp-content/database/.ht.sqlite" "$TMP/offline-keyless-partial-source.json"
php -r '$db = new SQLite3($argv[1]); $db->exec("UPDATE wp_forkpress_e2e_offline_keyless_partial SET value = '\''target kept partial old row'\'' WHERE rowid = 1"); $row = $db->querySingle("SELECT rowid, label, value FROM wp_forkpress_e2e_offline_keyless_partial", true); file_put_contents($argv[2], json_encode($row)); exit(((int)($row["rowid"] ?? 0) === 1 && ($row["label"] ?? null) === "Partial base row" && ($row["value"] ?? null) === "target kept partial old row") ? 0 : 1);' "$WORK/main/wp-content/database/.ht.sqlite" "$TMP/offline-keyless-partial-target.json"
"$BIN" branch --work-dir "$WORK_DIR" merge offline-keyless-partial --into main > "$TMP/offline-keyless-partial-merge.out"
grep -F "forkpress: merged offline-keyless-partial into main" "$TMP/offline-keyless-partial-merge.out" >/dev/null
grep -F "status:    completed_with_conflicts" "$TMP/offline-keyless-partial-merge.out" >/dev/null
php -r '$db = new SQLite3($argv[1]); $rows = []; $res = $db->query("SELECT rowid, label, value FROM wp_forkpress_e2e_offline_keyless_partial ORDER BY rowid"); while ($row = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $row; file_put_contents($argv[2], json_encode(["rows" => $rows])); exit(count($rows) === 1 && ($rows[0]["label"] ?? null) === "Partial base row" && ($rows[0]["value"] ?? null) === "target kept partial old row" ? 0 : 1);' "$WORK/main/wp-content/database/.ht.sqlite" "$TMP/offline-keyless-partial-after-merge.json"
OFFLINE_KEYLESS_PARTIAL_CONFLICT_ID="$(
  php -r '$db = new SQLite3($argv[1]); echo (int)$db->querySingle("SELECT c.id FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = '\''wp_forkpress_e2e_offline_keyless_partial'\'' AND c.conflict_type = '\''row-identity-ambiguous'\'' AND r.source_branch = '\''offline-keyless-partial'\'' AND r.target_branch = '\''main'\'' ORDER BY c.id DESC LIMIT 1");' \
    "$WORK_DIR/cow/merge/metadata.sqlite"
)"
if [ "$OFFLINE_KEYLESS_PARTIAL_CONFLICT_ID" = "0" ]; then
  echo "missing partial offline keyless row-identity-ambiguous conflict id" >&2
  exit 1
fi
php -r '$db = new SQLite3($argv[1]); $conflict_id = (int)$argv[2]; $target_wins = (int)$db->querySingle("SELECT COUNT(*) FROM merge_decisions WHERE table_name = '\''wp_forkpress_e2e_offline_keyless_partial'\'' AND decision = '\''target-wins'\'' AND reason LIKE '\''no-primary-key source row changed cells that target did not change%'\''"); exit($conflict_id > 0 && $target_wins > 0 ? 0 : 1);' "$WORK_DIR/cow/merge/metadata.sqlite" "$OFFLINE_KEYLESS_PARTIAL_CONFLICT_ID"
"$BIN" branch --work-dir "$WORK_DIR" merge-resolve conflict "$OFFLINE_KEYLESS_PARTIAL_CONFLICT_ID" --choice source --apply --note "Apply reviewed partial offline no-PK row choice" --reviewer cow-e2e > "$TMP/offline-keyless-partial-resolve.out"
grep -F "forkpress: validated COW merge conflict resolution" "$TMP/offline-keyless-partial-resolve.out" >/dev/null
grep -F "applied:   yes" "$TMP/offline-keyless-partial-resolve.out" >/dev/null
php -r '$db = new SQLite3($argv[1]); $rows = []; $res = $db->query("SELECT rowid, label, value FROM wp_forkpress_e2e_offline_keyless_partial ORDER BY rowid"); while ($row = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $row; file_put_contents($argv[2], json_encode(["rows" => $rows])); exit(count($rows) === 1 && ($rows[0]["label"] ?? null) === "Partial reused source row" && ($rows[0]["value"] ?? null) === "base" ? 0 : 1);' "$WORK/main/wp-content/database/.ht.sqlite" "$TMP/offline-keyless-partial-after-resolution.json"
php -r '$db = new SQLite3($argv[1]); $conflict_id = (int)$argv[2]; $resolution = (int)$db->querySingle("SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $conflict_id AND table_name = '\''wp_forkpress_e2e_offline_keyless_partial'\'' AND choice = '\''source'\'' AND applied = 1"); $reviewed = (int)$db->querySingle("SELECT COUNT(*) FROM merge_review_notes WHERE record_type = '\''conflict'\'' AND record_id = $conflict_id AND status = '\''reviewed'\''"); exit($resolution === 1 && $reviewed === 1 ? 0 : 1);' "$WORK_DIR/cow/merge/metadata.sqlite" "$OFFLINE_KEYLESS_PARTIAL_CONFLICT_ID"

log_step "merge identical keyless unique inserts"
php -r '$db = new SQLite3($argv[1]); $db->exec("DROP TABLE IF EXISTS wp_forkpress_e2e_keyless_unique_same"); $db->exec("CREATE TABLE wp_forkpress_e2e_keyless_unique_same (slug TEXT UNIQUE, value TEXT)");' "$WORK/main/wp-content/database/.ht.sqlite"
"$BIN" branch --work-dir "$WORK_DIR" create keyless-unique-same > "$TMP/keyless-unique-same-create.out"
grep -F "keyless-unique-same.wp.localhost:$PORT" "$TMP/keyless-unique-same-create.out" >/dev/null
php -r '$db = new SQLite3($argv[1]); $db->exec("INSERT INTO wp_forkpress_e2e_keyless_unique_same (slug, value) VALUES ('\''shared-keyless-unique-same'\'', '\''same payload'\'')");' "$WORK/keyless-unique-same/wp-content/database/.ht.sqlite"
php -r '$db = new SQLite3($argv[1]); $db->exec("INSERT INTO wp_forkpress_e2e_keyless_unique_same (slug, value) VALUES ('\''shared-keyless-unique-same'\'', '\''same payload'\'')");' "$WORK/main/wp-content/database/.ht.sqlite"
"$BIN" branch --work-dir "$WORK_DIR" merge keyless-unique-same --into main > "$TMP/keyless-unique-same-merge.out"
grep -F "forkpress: merged keyless-unique-same into main" "$TMP/keyless-unique-same-merge.out" >/dev/null
grep -F "status:    completed" "$TMP/keyless-unique-same-merge.out" >/dev/null
php -r '$db = new SQLite3($argv[1]); $rows = []; $res = $db->query("SELECT rowid, slug, value FROM wp_forkpress_e2e_keyless_unique_same ORDER BY rowid"); while ($row = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $row; file_put_contents($argv[2], json_encode(["rows" => $rows])); exit(count($rows) === 1 && ($rows[0]["slug"] ?? null) === "shared-keyless-unique-same" && ($rows[0]["value"] ?? null) === "same payload" ? 0 : 1);' "$WORK/main/wp-content/database/.ht.sqlite" "$TMP/keyless-unique-same-after-merge.json"
php -r '$db = new SQLite3($argv[1]); $conflicts = (int)$db->querySingle("SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = '\''wp_forkpress_e2e_keyless_unique_same'\'' AND c.conflict_type = '\''row-unique-collision'\'' AND r.source_branch = '\''keyless-unique-same'\'' AND r.target_branch = '\''main'\''"); $decisions = (int)$db->querySingle("SELECT COUNT(*) FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE d.table_name = '\''wp_forkpress_e2e_keyless_unique_same'\'' AND d.decision = '\''source-applied'\'' AND d.reason LIKE '\''source inserted no-primary-key row already exists in target by unique index%'\'' AND r.source_branch = '\''keyless-unique-same'\'' AND r.target_branch = '\''main'\''"); $rowid = (int)$db->querySingle("SELECT rowid FROM merge_row_identities WHERE branch_name = '\''main'\'' AND table_name = '\''wp_forkpress_e2e_keyless_unique_same'\''"); $source_identity = $db->querySingle("SELECT logical_identity FROM merge_row_identities WHERE branch_name = '\''keyless-unique-same'\'' AND table_name = '\''wp_forkpress_e2e_keyless_unique_same'\''"); $target_identity = $db->querySingle("SELECT logical_identity FROM merge_row_identities WHERE branch_name = '\''main'\'' AND table_name = '\''wp_forkpress_e2e_keyless_unique_same'\'' AND rowid = $rowid"); exit($conflicts === 0 && $decisions === 1 && is_string($source_identity) && $source_identity === $target_identity ? 0 : 1);' "$WORK_DIR/cow/merge/metadata.sqlite"
"$BIN" branch --work-dir "$WORK_DIR" merge keyless-unique-same --into main > "$TMP/keyless-unique-same-rerun.out"
grep -F "forkpress: merged keyless-unique-same into main" "$TMP/keyless-unique-same-rerun.out" >/dev/null
grep -F "status:    completed" "$TMP/keyless-unique-same-rerun.out" >/dev/null
php -r '$site = new SQLite3($argv[1]); $meta = new SQLite3($argv[2]); $rows = (int)$site->querySingle("SELECT COUNT(*) FROM wp_forkpress_e2e_keyless_unique_same WHERE slug = '\''shared-keyless-unique-same'\'' AND value = '\''same payload'\''"); $decisions = (int)$meta->querySingle("SELECT COUNT(*) FROM merge_decisions d JOIN merge_runs r ON r.id = d.run_id WHERE d.table_name = '\''wp_forkpress_e2e_keyless_unique_same'\'' AND d.decision = '\''source-applied'\'' AND d.reason LIKE '\''source inserted no-primary-key row already exists in target by unique index%'\'' AND r.source_branch = '\''keyless-unique-same'\'' AND r.target_branch = '\''main'\''"); exit($rows === 1 && $decisions === 1 ? 0 : 1);' "$WORK/main/wp-content/database/.ht.sqlite" "$WORK_DIR/cow/merge/metadata.sqlite"

log_step "merge no-PK foreign-key dependent update"
php -r '$db = new SQLite3($argv[1]); $db->exec("PRAGMA foreign_keys = ON"); $db->exec("DROP TABLE IF EXISTS wp_forkpress_e2e_fk_keyless_children"); $db->exec("DROP TABLE IF EXISTS wp_forkpress_e2e_fk_keyless_parents"); $db->exec("CREATE TABLE wp_forkpress_e2e_fk_keyless_parents (id INTEGER PRIMARY KEY, label TEXT)"); $db->exec("CREATE TABLE wp_forkpress_e2e_fk_keyless_children (parent_id INTEGER NOT NULL REFERENCES wp_forkpress_e2e_fk_keyless_parents(id), label TEXT)"); $db->exec("INSERT INTO wp_forkpress_e2e_fk_keyless_parents (id, label) VALUES (1, '\''old parent'\''), (2, '\''kept parent'\'')"); $db->exec("INSERT INTO wp_forkpress_e2e_fk_keyless_children (rowid, parent_id, label) VALUES (7, 1, '\''base keyless child'\'')");' "$WORK/main/wp-content/database/.ht.sqlite"
"$BIN" branch --work-dir "$WORK_DIR" create fk-keyless-update > "$TMP/fk-keyless-update-create.out"
grep -F "fk-keyless-update.wp.localhost:$PORT" "$TMP/fk-keyless-update-create.out" >/dev/null
php -r '$db = new SQLite3($argv[1]); $db->exec("PRAGMA foreign_keys = ON"); $db->exec("UPDATE wp_forkpress_e2e_fk_keyless_children SET parent_id = 2, label = '\''source keyless child reparented'\'' WHERE rowid = 7"); $db->exec("DELETE FROM wp_forkpress_e2e_fk_keyless_parents WHERE id = 1");' "$WORK/fk-keyless-update/wp-content/database/.ht.sqlite"
"$BIN" branch --work-dir "$WORK_DIR" merge fk-keyless-update --into main > "$TMP/fk-keyless-update-merge.out"
grep -F "forkpress: merged fk-keyless-update into main" "$TMP/fk-keyless-update-merge.out" >/dev/null
grep -F "status:    completed" "$TMP/fk-keyless-update-merge.out" >/dev/null
php -r 'require "scripts/cow/merge.php"; $site = new SQLite3($argv[1]); $meta = new SQLite3($argv[2]); $row = $site->querySingle("SELECT rowid, parent_id, label FROM wp_forkpress_e2e_fk_keyless_children WHERE rowid = 7", true); $parent = (int)$site->querySingle("SELECT COUNT(*) FROM wp_forkpress_e2e_fk_keyless_parents WHERE id = 1"); $hash = $meta->querySingle("SELECT row_hash FROM merge_row_identities WHERE branch_name = '\''main'\'' AND table_name = '\''wp_forkpress_e2e_fk_keyless_children'\'' AND rowid = 7"); $conflicts = (int)$meta->querySingle("SELECT COUNT(*) FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.conflict_type = '\''row-target-constraint'\'' AND c.table_name LIKE '\''wp_forkpress_e2e_fk_keyless_%'\'' AND r.source_branch = '\''fk-keyless-update'\''"); file_put_contents($argv[3], json_encode(["row" => $row, "old_parent_count" => $parent, "row_hash" => $hash, "conflicts" => $conflicts])); exit(is_array($row) && (int)$row["parent_id"] === 2 && ($row["label"] ?? null) === "source keyless child reparented" && $parent === 0 && $hash === cow_merge_row_hash(["parent_id" => 2, "label" => "source keyless child reparented"]) && $conflicts === 0 ? 0 : 1);' "$WORK/main/wp-content/database/.ht.sqlite" "$WORK_DIR/cow/merge/metadata.sqlite" "$TMP/fk-keyless-update-after-merge.json"
"$BIN" branch --work-dir "$WORK_DIR" merge fk-keyless-update --into main > "$TMP/fk-keyless-update-rerun.out"
grep -F "forkpress: merged fk-keyless-update into main" "$TMP/fk-keyless-update-rerun.out" >/dev/null
grep -F "status:    completed" "$TMP/fk-keyless-update-rerun.out" >/dev/null

log_step "resolve runtime plugin unique collision"
mkdir -p "$WORK/main/wp-content/mu-plugins"
cat > "$WORK/main/wp-content/mu-plugins/forkpress-e2e-unique.php" <<'PHP'
<?php
add_action('init', function () {
    if (!isset($_GET['forkpress_e2e_unique'])) {
        return;
    }

    global $wpdb;
    $action = sanitize_key(wp_unslash($_GET['forkpress_e2e_unique']));
    $table = $wpdb->prefix . 'forkpress_e2e_unique';
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
        $query("CREATE TABLE $quoted (id INTEGER PRIMARY KEY, slug TEXT NOT NULL UNIQUE, value TEXT NOT NULL)");
    } elseif ($action === 'source-insert') {
        $query($wpdb->prepare("INSERT INTO $quoted (id, slug, value) VALUES (%d, %s, %s)", 101, 'shared-runtime-slug', 'source runtime row'));
    } elseif ($action === 'target-insert') {
        $query($wpdb->prepare("INSERT INTO $quoted (id, slug, value) VALUES (%d, %s, %s)", 202, 'shared-runtime-slug', 'target runtime row'));
    } elseif ($action !== 'inspect') {
        wp_send_json_error(['error' => 'unknown action'], 400);
    }

    $rows = $wpdb->get_results("SELECT id, slug, value FROM $quoted ORDER BY id", ARRAY_A);
    if (!is_array($rows)) {
        wp_send_json_error(['error' => $wpdb->last_error ?: 'select failed'], 500);
    }
    wp_send_json(['action' => $action, 'rows' => $rows]);
}, 20);
PHP

unique_runtime_request main init "$TMP/unique-init.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); exit(is_array($data) && count($data["rows"] ?? []) === 0 ? 0 : 1);' "$TMP/unique-init.json"
"$BIN" branch --work-dir "$WORK_DIR" create unique-collision > "$TMP/unique-create.out"
grep -F "unique-collision.wp.localhost:$PORT" "$TMP/unique-create.out" >/dev/null
unique_runtime_request unique-collision source-insert "$TMP/unique-source-insert.json"
unique_runtime_request main target-insert "$TMP/unique-target-insert.json"
php -r '$source = json_decode(file_get_contents($argv[1]), true); $target = json_decode(file_get_contents($argv[2]), true); exit(($source["rows"][0]["id"] ?? null) == 101 && ($target["rows"][0]["id"] ?? null) == 202 ? 0 : 1);' "$TMP/unique-source-insert.json" "$TMP/unique-target-insert.json"
"$BIN" branch --work-dir "$WORK_DIR" merge unique-collision --into main > "$TMP/unique-merge.out"
grep -F "forkpress: merged unique-collision into main" "$TMP/unique-merge.out" >/dev/null
grep -F "status:    completed_with_conflicts" "$TMP/unique-merge.out" >/dev/null
unique_runtime_request main inspect "$TMP/unique-after-merge.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); exit(count($data["rows"] ?? []) === 1 && ($data["rows"][0]["id"] ?? null) == 202 && ($data["rows"][0]["value"] ?? null) === "target runtime row" ? 0 : 1);' "$TMP/unique-after-merge.json"
UNIQUE_CONFLICT_ID="$(
  php -r '$db = new SQLite3($argv[1]); echo (int)$db->querySingle("SELECT c.id FROM merge_conflicts c JOIN merge_runs r ON r.id = c.run_id WHERE c.table_name = '\''wp_forkpress_e2e_unique'\'' AND c.conflict_type = '\''row-unique-collision'\'' AND r.source_branch = '\''unique-collision'\'' AND r.target_branch = '\''main'\'' ORDER BY c.id DESC LIMIT 1");' \
    "$WORK_DIR/cow/merge/metadata.sqlite"
)"
if [ "$UNIQUE_CONFLICT_ID" = "0" ]; then
  echo "missing runtime plugin row-unique-collision conflict id" >&2
  exit 1
fi
"$BIN" branch --work-dir "$WORK_DIR" merge-audit --format json --review --review-status unreviewed --records conflicts --scope db --limit 20 > "$TMP/unique-review-queue.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); $ok = is_array($data) && (($data["filters"]["review"] ?? false) === true) && (($data["filters"]["review_status"] ?? null) === "unreviewed") && (($data["filters"]["records"] ?? null) === "conflicts") && (($data["filters"]["scope"] ?? null) === "db"); $has_conflict = false; foreach (($data["conflicts"] ?? []) as $row) { if (($row["review_status"] ?? null) !== null) $ok = false; if ((int)($row["id"] ?? 0) === (int)$argv[2] && ($row["table_name"] ?? null) === "wp_forkpress_e2e_unique" && ($row["conflict_type"] ?? null) === "row-unique-collision") $has_conflict = true; } exit($ok && $has_conflict ? 0 : 1);' "$TMP/unique-review-queue.json" "$UNIQUE_CONFLICT_ID"
"$BIN" branch --work-dir "$WORK_DIR" merge-resolve conflict "$UNIQUE_CONFLICT_ID" --choice source --apply --note "Apply runtime source unique row" --reviewer cow-e2e > "$TMP/unique-resolve.out"
grep -F "forkpress: validated COW merge conflict resolution" "$TMP/unique-resolve.out" >/dev/null
grep -F "applied:   yes" "$TMP/unique-resolve.out" >/dev/null
unique_runtime_request main inspect "$TMP/unique-after-resolution.json"
php -r '$data = json_decode(file_get_contents($argv[1]), true); exit(count($data["rows"] ?? []) === 1 && ($data["rows"][0]["id"] ?? null) == 101 && ($data["rows"][0]["value"] ?? null) === "source runtime row" ? 0 : 1);' "$TMP/unique-after-resolution.json"
php -r '$db = new SQLite3($argv[1]); $conflict_id = (int)$argv[2]; $resolution = (int)$db->querySingle("SELECT COUNT(*) FROM merge_resolutions WHERE conflict_id = $conflict_id AND table_name = '\''wp_forkpress_e2e_unique'\'' AND choice = '\''source'\'' AND applied = 1"); $reviewed = (int)$db->querySingle("SELECT COUNT(*) FROM merge_review_notes WHERE record_type = '\''conflict'\'' AND record_id = $conflict_id AND status = '\''reviewed'\''"); exit($resolution === 1 && $reviewed === 1 ? 0 : 1);' "$WORK_DIR/cow/merge/metadata.sqlite" "$UNIQUE_CONFLICT_ID"

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

#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

fail() {
  echo "strict-harness: $*" >&2
  exit 1
}

need_pattern() {
  local file="$1"
  local pattern="$2"
  local label="$3"
  rg -q "$pattern" "$file" || fail "$label missing in $file"
}

deny_pattern() {
  local file="$1"
  local pattern="$2"
  local label="$3"
  if rg -q "$pattern" "$file"; then
    fail "$label found in $file"
  fi
}

run_exact_test() {
  local test_name="$1"
  cargo test --locked "$test_name" -- --exact --nocapture
}

run_exact_ignored_test() {
  local test_name="$1"
  cargo test --locked "$test_name" -- --exact --ignored --nocapture
}

echo "== full Rust/PHP unit suite =="
cargo test --locked

echo "== targeted behavior proofs =="
run_exact_test overlay::tests::lazy_remote_file_is_cached_and_survives_remote_loss
run_exact_test cli::tests::offline_core_runtime_cache_is_bounded_to_wordpress_core
run_exact_test overlay::tests::cached_only_copy_up_uses_materialized_files_without_remote
run_exact_test fusefs::tests::offline_readdir_uses_cached_remote_metadata_without_remote
run_exact_test fusefs::tests::remote_stat_metadata_survives_severed_mode_without_remote
run_exact_test fusefs::tests::remote_missing_metadata_survives_daemon_restart
run_exact_test generate::tests::router_splash_and_progress_smoke_responds_quickly
run_exact_test db::tests::remote_query_cache_round_trips_safe_read_results
run_exact_test db::tests::dirty_row_overlay_tables_are_local_state
run_exact_test row_cow::tests::select_materializes_remote_rows_for_later_offline_reads
run_exact_test row_cow::tests::primary_key_single_row_selects_allow_safe_order_and_limit_clauses
run_exact_test row_cow::tests::local_insert_is_not_sent_to_remote_and_appears_in_merged_select
run_exact_test row_cow::tests::update_copy_up_fetches_only_affected_primary_keys
run_exact_test row_cow::tests::delete_tombstone_hides_remote_row_from_merged_selects
run_exact_test run::tests::frankenphp_routes_wp_admin_directory_to_index
run_exact_test run::tests::frankenphp_routes_installer_paths_through_runtime_guard
run_exact_test run::tests::web_runtime_disables_common_plugin_side_effect_primitives
run_exact_test run::tests::web_runtime_defaults_to_no_opcache_timestamp_revalidation
run_exact_test sql::tests::extract_tables_preserves_wordpress_table_case_for_proxy_cow
run_exact_ignored_test generate::tests::runtime_cow_harness_proves_admin_login_local_mutation_and_offline_refresh
run_exact_ignored_test generate::tests::production_run_harness_proves_fuse_rust_control_and_offline_refresh

echo "== implementation invariants =="
need_pattern src/cli.rs 'Command::Serve' "one-command serve subcommand"
need_pattern src/cli.rs 'Command::Sever' "sever/offline subcommand"
need_pattern src/cli.rs 'cache_offline_core_runtime' "offline login/admin core runtime cache"
need_pattern src/cli.rs 'wp-content/uploads' "offline core runtime cache excludes uploads"
need_pattern src/config.rs 'offline\.json' "offline marker"
need_pattern src/run.rs 'WPCOW_WEB_SERVER' "web-server selection"
need_pattern src/run.rs 'falling back to PHP' "FrankenPHP unavailable fallback"
need_pattern src/run.rs 'start_php_dev_server' "PHP dev-server fallback"
need_pattern src/run.rs '@wpCowInstaller path /wp-admin/install\.php /wp-admin/setup-config\.php' "FrankenPHP installer guard route"
need_pattern src/run.rs '__wp_cow_installer_guard=1' "FrankenPHP installer guard router flag"
need_pattern src/fusefs.rs 'clone is severed and file is not cached locally' "offline cached-file guard"
need_pattern src/fusefs.rs 'copy_up_cached_only' "offline write-open cached-only copy-up"
need_pattern src/fusefs.rs 'put_cached_entry\(rel, &entry\)' "FUSE stat metadata persistence"
need_pattern src/fusefs.rs 'put_cached_missing' "FUSE missing metadata persistence"
need_pattern src/overlay.rs 'clone is severed and writable lower file is not cached locally' "offline write-open remote guard"
need_pattern src/overlay.rs 'missing\.json' "persistent missing metadata cache"
need_pattern src/control.rs 'clone is severed from the remote database' "offline remote-DB guard"
need_pattern src/generate.rs 'will not fall back to the empty local schema' "installer/runtime failure guard"
need_pattern src/generate.rs 'wp_cow_looks_like_installer' "installer response detector"
need_pattern src/generate.rs '__wp_cow_installer_guard' "direct installer route guard"
need_pattern src/generate.rs "'1' !== getenv\\( 'WPCOW_PROXY_FRONTEND' \\)" "local-first frontend default"
need_pattern src/db.rs 'cached_remote_readonly_query' "Rust remote read cache for MySQL proxy/control"
need_pattern src/db.rs 'dirty_tables' "dirty row-overlay table routing state"
need_pattern src/generate.rs 'cow_cached_remote_read_is_safe_without_control' "PHP cached remote read fast path"
need_pattern src/generate.rs 'cow_safe_local_read_without_control' "PHP local read fast path for materialized runtime data"
need_pattern src/remote.rs 'WPCOW_REMOTE_DB_TUNNEL", false' "remote DB SSH tunnel is opt-in"
need_pattern src/run.rs 'WPCOW_ALLOW_UNSAFE_PLUGIN_SIDE_EFFECTS' "plugin side-effect escape hatch is explicit"
need_pattern src/run.rs 'disable_functions' "PHP side-effect functions are disabled by default"
need_pattern src/run.rs 'stream_socket_client' "raw plugin socket egress is disabled by default"
need_pattern src/run.rs 'WPCOW_OPCACHE_VALIDATE_TIMESTAMPS' "OPcache timestamp validation is configurable"
need_pattern src/generate.rs 'function cow_offline' "PHP DB offline mode"
need_pattern src/db.rs 'set_local_admin_password' "local-only admin password override"
need_pattern src/row_cow.rs 'LocalOnlyInsert' "local-only content mutation path"
need_pattern src/generate.rs 'production_run_harness_proves_fuse_rust_control_and_offline_refresh' "strict production FUSE/control harness"
need_pattern src/generate.rs 'run_site_with_shutdown' "strict harness production run entry"
need_pattern src/generate.rs 'install_fake_ssh' "strict harness fake SSH remote"
need_pattern src/generate.rs 'read_line_count\(&fake_ssh_log\)' "strict harness offline no-SSH assertion"
need_pattern compose.yaml '\$\{WPCOW_HTTP_PORT:-8080\}:8080' "Docker host HTTP port exposure"
need_pattern compose.yaml 'WPCOW_HTTP: 0\.0\.0\.0:8080' "Docker in-container HTTP listener"
need_pattern .dockerignore '^/target/$' "Docker build context target exclusion"
need_pattern .dockerignore '^/\.env$' "Docker build context local env exclusion"
need_pattern .dockerignore '^!/\.env\.example$' "Docker build context env example inclusion"
need_pattern docker/wp-cow-lab-serve 'wp-cow serve' "Docker one-command serve wrapper"
need_pattern docker/wp-cow-lab-sever 'WPCOW_LOCAL_ADMIN_PASSWORD' "Docker local admin override wiring"
need_pattern .env.example '^WPCOW_HTTP_PORT=9481$' "Docker lab example host HTTP port"
need_pattern .env.example '^WPCOW_WEB_SERVER=frankenphp$' "Docker lab example FrankenPHP preference"
need_pattern .env.example '^WPCOW_REMOTE_DB_TUNNEL=0$' "Docker lab example disables remote DB tunnel by default"
need_pattern .env.example '^WPCOW_SPLASH=1$' "Docker lab example splash default"
need_pattern .env.example '^WPCOW_ALLOW_UNSAFE_PLUGIN_SIDE_EFFECTS=0$' "Docker lab example keeps PHP side-effect guards enabled"
need_pattern .env.example '^WPCOW_OPCACHE_VALIDATE_TIMESTAMPS=0$' "Docker lab example keeps warm render OPcache fast path enabled"
need_pattern .env.example '^WPCOW_REMOTE_METADATA_CACHE_TTL_SECS=3600$' "Docker lab example keeps remote metadata warm long enough for rerenders"
need_pattern .env.example '^WPCOW_LOCAL_ADMIN_PASSWORD=$' "Docker lab example local admin override"

deny_pattern src 'rsync|scp[[:space:]]+-r' "eager source tree copy command"
deny_pattern src/cli.rs 'wordpress_offline_table_names' "full core-table sever materialization"
deny_pattern src/cli.rs 'prefetch_runtime_files' "sever-triggered runtime prefetch"
deny_pattern src/run.rs 'WPCOW_PREFETCH_RUNTIME|prefetch_runtime_files|wp-cow-runtime-prefetch' "background runtime prefetch"
deny_pattern src/run.rs 'tar[[:space:]]+-cf[[:space:]]+-' "recursive remote tar runtime prefetch"
deny_pattern src/generate.rs 'function cow_remote_query_cache_clear|cow_remote_query_cache_clear\(\);' "global remote query cache invalidation"
deny_pattern src/generate.rs "define\\( 'WPCOW_REMOTE_DB_(NAME|USER|PASSWORD|HOST)'|function cow_remote_mysqli|real_connect" "remote DB credentials in generated PHP"
deny_pattern docker/Dockerfile 'rsync|scp[[:space:]]+-r' "eager copy tooling"
deny_pattern docker/wp-cow-lab-serve 'rsync|scp[[:space:]]+-r' "eager lab serve copy command"
deny_pattern docker/wp-cow-lab-run 'rsync|scp[[:space:]]+-r' "eager lab run copy command"

echo "strict-harness: PASS"

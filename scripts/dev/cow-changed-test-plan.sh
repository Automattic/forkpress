#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

BASE="${FORKPRESS_TEST_BASE:-origin/trunk}"
SCOPE="${FORKPRESS_CHANGED_TEST_PLAN_SCOPE:-all}"
JOBS="${FORKPRESS_CHANGED_TEST_PLAN_JOBS:-1}"
LIST_ONLY=0

usage() {
  cat <<'EOF'
Usage: scripts/dev/cow-changed-test-plan.sh [--base <ref>] [--jobs <n>] [--list]

Runs a focused local preflight for the current COW merge change set. The plan is
chosen from files changed against the base ref plus staged/unstaged edits.

Options:
  --base <ref>  Compare committed changes against this ref. Defaults to origin/trunk.
  --jobs <n>    Run selected checks with up to n concurrent jobs. Defaults to 1.
  --list        Print the selected commands without running them.

Environment:
  FORKPRESS_CHANGED_TEST_PLAN_SCOPE=cow  Limit selection to COW/PHP checks.
  FORKPRESS_CHANGED_TEST_PLAN_JOBS=n     Same as --jobs.
EOF
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --base)
      if [ "$#" -lt 2 ]; then
        echo "changed-test-plan: --base requires a ref" >&2
        exit 2
      fi
      BASE="$2"
      shift 2
      ;;
    --jobs)
      if [ "$#" -lt 2 ]; then
        echo "changed-test-plan: --jobs requires a positive integer" >&2
        exit 2
      fi
      JOBS="$2"
      shift 2
      ;;
    --list)
      LIST_ONLY=1
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "changed-test-plan: unsupported argument: $1" >&2
      usage >&2
      exit 2
      ;;
    esac
done

if ! [[ "$JOBS" =~ ^[1-9][0-9]*$ ]]; then
  echo "changed-test-plan: --jobs must be a positive integer" >&2
  exit 2
fi

case "$SCOPE" in
  all|cow) ;;
  *)
    echo "changed-test-plan: unsupported FORKPRESS_CHANGED_TEST_PLAN_SCOPE: $SCOPE" >&2
    exit 2
    ;;
esac

if ! git rev-parse --verify "$BASE" >/dev/null 2>&1; then
  if git rev-parse --verify trunk >/dev/null 2>&1; then
    BASE="trunk"
  else
    echo "changed-test-plan: cannot resolve base ref '$BASE'" >&2
    exit 2
  fi
fi

declare -a files=()
while IFS= read -r path; do
  [ -n "$path" ] && files+=("$path")
done < <(
  {
    git diff --name-only --diff-filter=ACMRT "$BASE"...HEAD
    git diff --name-only --diff-filter=ACMRT
    git diff --cached --name-only --diff-filter=ACMRT
    git ls-files --others --exclude-standard
  } | sort -u
)

if [ "${#files[@]}" -eq 0 ]; then
  echo "changed-test-plan: no changed files relative to $BASE"
  exit 0
fi

declare -a commands=()

add_cmd() {
  local cmd="$1"
  local existing
  for existing in "${commands[@]}"; do
    if [ "$existing" = "$cmd" ]; then
      return
    fi
  done
  commands+=("$cmd")
}

has_file() {
  local pattern="$1"
  local file
  for file in "${files[@]}"; do
    case "$file" in
      $pattern) return 0 ;;
    esac
  done
  return 1
}

changed_merge_php_text() {
  {
    git diff "$BASE"...HEAD -- scripts/cow/merge.php 2>/dev/null || true
    git diff -- scripts/cow/merge.php 2>/dev/null || true
    git diff --cached -- scripts/cow/merge.php 2>/dev/null || true
  }
}

merge_diff_matches() {
  local pattern="$1"
  changed_merge_php_text | grep -Eiq "$pattern"
}

add_cmd "git diff --check '$BASE'...HEAD && git diff --check && git diff --cached --check"

for file in "${files[@]}"; do
  case "$file" in
    *.php)
      if [ -f "$file" ]; then
        add_cmd "php -l '$file'"
      fi
      ;;
  esac
done

if has_file "scripts/cow/merge.php"; then
  add_cmd "php tests/cow/merge_smoke.php"
fi

if has_file "tests/cow/branch_birth.php" || merge_diff_matches "branch[ _-]?birth|merge[ _-]?base"; then
  add_cmd "php tests/cow/branch_birth.php"
fi

if has_file "tests/cow/git_server.php" || has_file "scripts/cow/git_server.php" || merge_diff_matches "git[ _-]?created|git[ _-]?server|publication|branch-list"; then
  add_cmd "php tests/cow/git_server.php"
fi

if has_file "tests/cow/filesystem.php" || merge_diff_matches "filesystem|file_root|symlink|directory|binary"; then
  add_cmd "php tests/cow/filesystem.php"
fi

if has_file "tests/cow/id_bands.php" || merge_diff_matches "id[ _-]?band|autoincrement|sqlite_sequence|INTEGER PRIMARY KEY"; then
  add_cmd "php tests/cow/id_bands.php"
fi

if has_file "tests/cow/explicit_ids.php" || merge_diff_matches "explicit[ _-]?id|out-of-band|reserved band"; then
  add_cmd "php tests/cow/explicit_ids.php"
fi

if has_file "tests/cow/media_validator.php" || merge_diff_matches "media|attachment|upload|image_meta|generated"; then
  add_cmd "php tests/cow/media_validator.php"
fi

if has_file "tests/cow/plugin_validator.php" || has_file "docs/plugin-merge-validators.md" || merge_diff_matches "plugin[ _-]?validator|plugin[ _-]?driver|__plugins__|logical_identity"; then
  add_cmd "php tests/cow/plugin_validator.php"
fi

if has_file "tests/cow/schema_review.php" || merge_diff_matches "schema|trigger|view|index|table rebuild|foreign-key validation"; then
  add_cmd "php tests/cow/schema_review.php"
fi

if has_file "tests/cow/stale_audit.php" || merge_diff_matches "stale|revalidat|review note|needs-action|after-revalidate"; then
  add_cmd "php tests/cow/stale_audit.php"
fi

if has_file "tests/cow/wp_semantic_validator.php" || merge_diff_matches "wp_posts|wp_postmeta|wp_terms|wp_options|block|comment|termmeta|usermeta"; then
  add_cmd "php tests/cow/wp_semantic_validator.php"
fi

if has_file "tests/cow/merge.php"; then
  add_cmd "php tests/cow/merge.php"
fi

if has_file "tests/cow/merge_smoke.php"; then
  add_cmd "php tests/cow/merge_smoke.php"
fi

if has_file "tests/cow/branch_ui.php" || has_file "tests/cow/router_branch_actions.php" || has_file "tests/cow/router_branch_birth_guard.php" || has_file "tests/cow/router_paths.php" || has_file "tests/cow/router_lock.php" || has_file "wp-plugin/*"; then
  add_cmd "make test-cow-branch-ui"
fi

if [ "$SCOPE" = "all" ] && { has_file "crates/forkpress-cli/*" || has_file "crates/forkpress-git/*" || has_file "crates/forkpress-runtime/*" || has_file "crates/forkpress-server/*"; }; then
  add_cmd "FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli branch"
fi

if [ "$SCOPE" = "all" ] && { has_file "scripts/build-dist.sh" || has_file "scripts/release-*.mjs" || has_file "tests/release/*" || has_file "installer/windows/*" || has_file "scripts/windows/*"; }; then
  add_cmd "make test-release"
fi

if [ "${#commands[@]}" -le 2 ] && { has_file "scripts/cow/*" || has_file "tests/cow/*"; }; then
  add_cmd "make test-cow-fast"
fi

echo "changed-test-plan: base=$BASE"
echo "changed-test-plan: files=${#files[@]} commands=${#commands[@]} jobs=$JOBS"
for cmd in "${commands[@]}"; do
  echo "+ $cmd"
done

if [ "$LIST_ONLY" -eq 1 ]; then
  exit 0
fi

if [ "$JOBS" -eq 1 ] || [ "${#commands[@]}" -le 1 ]; then
  for cmd in "${commands[@]}"; do
    bash -lc "$cmd"
  done
  exit 0
fi

logs_dir="$(mktemp -d "${TMPDIR:-/tmp}/forkpress-cow-changed-test-plan.XXXXXX")"
cleanup_logs() {
  rm -rf "$logs_dir"
}
trap cleanup_logs EXIT

declare -a pids=()
declare -a running_indices=()
declare -a statuses=()
next_command=0
failed=0

start_command() {
  local index="$1"
  local log="$logs_dir/$index.log"
  (
    bash -lc "${commands[$index]}"
  ) >"$log" 2>&1 &
  pids[$index]=$!
  running_indices+=("$index")
}

wait_one_command() {
  local wait_index="${running_indices[0]}"
  local pid="${pids[$wait_index]}"
  local status=0
  if wait "$pid"; then
    status=0
  else
    status=$?
  fi
  statuses[$wait_index]=$status
  if [ "$status" -ne 0 ]; then
    failed=1
  fi
  running_indices=("${running_indices[@]:1}")
}

while [ "$next_command" -lt "${#commands[@]}" ] || [ "${#running_indices[@]}" -gt 0 ]; do
  while [ "$next_command" -lt "${#commands[@]}" ] && [ "${#running_indices[@]}" -lt "$JOBS" ]; do
    start_command "$next_command"
    next_command=$((next_command + 1))
  done
  if [ "${#running_indices[@]}" -gt 0 ]; then
    wait_one_command
  fi
done

for index in "${!commands[@]}"; do
  status="${statuses[$index]:-0}"
  if [ -s "$logs_dir/$index.log" ]; then
    cat "$logs_dir/$index.log"
  fi
  if [ "$status" -ne 0 ]; then
    echo "changed-test-plan: command failed with status $status: ${commands[$index]}" >&2
  fi
done

exit "$failed"

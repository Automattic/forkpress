#!/usr/bin/env bash
set -uo pipefail

usage() {
  cat <<'USAGE'
Usage:
  scripts/codex-until-pass.sh --task-file TASK.md [--work-dir DIR]
  scripts/codex-until-pass.sh --task "Implement ..." [--work-dir DIR]

Runs Codex in an implement/verify loop until an independent verifier ends with
exactly "VERDICT: PASS". By default MAX_ITERATIONS=0, which means no iteration
limit. Press Ctrl-C to stop.

Environment:
  CODEX_CMD                         Codex command. Default: codex
  MAX_ITERATIONS                    0 means unlimited. Default: 0
  SLEEP_SECONDS                     Delay after structural failures. Default: 5
  IMPLEMENTER_TAIL_BYTES            Bytes of implementer output sent to verifier. Default: 24000
  FEEDBACK_TAIL_BYTES               Bytes of verifier feedback kept for next iteration. Default: 32000
  CODEX_UNTIL_PASS_BYPASS_SANDBOX   1 passes --dangerously-bypass-approvals-and-sandbox. Default: 1
USAGE
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

TASK_TEXT=""
TASK_FILE=""
WORK_DIR=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --task)
      [[ $# -ge 2 ]] || { echo "missing value for --task" >&2; exit 64; }
      TASK_TEXT="$2"
      shift 2
      ;;
    --task-file)
      [[ $# -ge 2 ]] || { echo "missing value for --task-file" >&2; exit 64; }
      TASK_FILE="$2"
      shift 2
      ;;
    --work-dir)
      [[ $# -ge 2 ]] || { echo "missing value for --work-dir" >&2; exit 64; }
      WORK_DIR="$2"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "unknown argument: $1" >&2
      usage >&2
      exit 64
      ;;
  esac
done

if [[ -n "$TASK_TEXT" && -n "$TASK_FILE" ]]; then
  echo "pass either --task or --task-file, not both" >&2
  exit 64
fi
if [[ -z "$TASK_TEXT" && -z "$TASK_FILE" ]]; then
  echo "missing --task or --task-file" >&2
  usage >&2
  exit 64
fi

CODEX_CMD="${CODEX_CMD:-codex}"
MAX_ITERATIONS="${MAX_ITERATIONS:-0}"
SLEEP_SECONDS="${SLEEP_SECONDS:-5}"
IMPLEMENTER_TAIL_BYTES="${IMPLEMENTER_TAIL_BYTES:-24000}"
FEEDBACK_TAIL_BYTES="${FEEDBACK_TAIL_BYTES:-32000}"
CODEX_UNTIL_PASS_BYPASS_SANDBOX="${CODEX_UNTIL_PASS_BYPASS_SANDBOX:-1}"

if [[ -z "$WORK_DIR" ]]; then
  WORK_DIR=".codex-until-pass/$(date +%Y%m%d-%H%M%S)"
fi
mkdir -p "$WORK_DIR"

RAW_TASK="$WORK_DIR/task.raw.md"
TASK="$WORK_DIR/task.md"
FEEDBACK="$WORK_DIR/feedback.md"
LOG="$WORK_DIR/log.md"
PASS_FILE="$WORK_DIR/passed-on-iteration.txt"

if [[ -n "$TASK_FILE" ]]; then
  cp "$TASK_FILE" "$RAW_TASK"
else
  printf '%s\n' "$TASK_TEXT" > "$RAW_TASK"
fi

# Prevent nested Codex runs from interpreting task text as another request to
# launch this or any adversarial loop. The original text remains in task.raw.md.
sed \
  -e 's/\$adversarial-loop/[adversarial-loop trigger disabled inside codex-until-pass]/g' \
  -e 's#/adversarial-loop#[adversarial-loop trigger disabled inside codex-until-pass]#g' \
  "$RAW_TASK" > "$TASK"

: > "$FEEDBACK"
: > "$LOG"

codex_args=()
if [[ "$CODEX_UNTIL_PASS_BYPASS_SANDBOX" == "1" ]]; then
  codex_args+=(--dangerously-bypass-approvals-and-sandbox)
fi

iteration=0
while true; do
  iteration=$((iteration + 1))
  if [[ "$MAX_ITERATIONS" != "0" && "$iteration" -gt "$MAX_ITERATIONS" ]]; then
    echo "Did not converge after $MAX_ITERATIONS iterations" | tee -a "$LOG"
    exit 1
  fi

  echo "=== Iteration $iteration ===" | tee -a "$LOG"

  impl_prompt="$WORK_DIR/iter-$iteration-impl-prompt.md"
  impl_out="$WORK_DIR/iter-$iteration-impl-output.md"
  impl_status="$WORK_DIR/iter-$iteration-impl-status"
  verify_prompt="$WORK_DIR/iter-$iteration-verify-prompt.md"
  verify_out="$WORK_DIR/iter-$iteration-verify-output.md"
  verify_status="$WORK_DIR/iter-$iteration-verify-status"

  {
    echo "# Task"
    cat "$TASK"
    echo
    echo "# Harness Rules"
    echo "- You are already inside scripts/codex-until-pass.sh."
    echo "- Do not invoke adversarial-loop, codex-until-pass, or any recursive Codex restart loop."
    echo "- Make real edits in this repository. Do not stop at a proposal."
    echo "- If full completion is impossible, implement the next concrete blocker and explain the remaining blocker precisely."
    echo
    echo "# Prior Verifier Feedback"
    if [[ -s "$FEEDBACK" ]]; then
      cat "$FEEDBACK"
    else
      echo "(none)"
    fi
    echo
    echo "# Final Output Contract"
    echo "End with a compact summary of files changed and checks run."
  } > "$impl_prompt"

  set +e
  "$CODEX_CMD" exec "${codex_args[@]}" < "$impl_prompt" > "$impl_out" 2>&1
  code=$?
  set -e
  printf '%s\n' "$code" > "$impl_status"
  if [[ "$code" -ne 0 ]]; then
    echo "Implementer exited $code on iteration $iteration; restarting after ${SLEEP_SECONDS}s" | tee -a "$LOG"
    sleep "$SLEEP_SECONDS"
    continue
  fi

  git_status_file="$WORK_DIR/iter-$iteration-git-status.txt"
  git_diff_stat_file="$WORK_DIR/iter-$iteration-git-diff-stat.txt"
  git status --short > "$git_status_file" 2>&1 || true
  git diff --stat > "$git_diff_stat_file" 2>&1 || true

  {
    echo "# Task"
    cat "$TASK"
    echo
    echo "# Verifier Instructions"
    echo "Independently inspect the actual working tree. Do not trust the implementer output."
    echo "Run whatever checks are needed. Do not make edits."
    echo "PASS only when the task is fully complete by code, tests, and docs where relevant."
    echo "If not complete, include a concise '## Issues' section with concrete actionable blockers."
    echo
    echo "End with exactly one final line:"
    echo "VERDICT: PASS"
    echo "or"
    echo "VERDICT: FAIL"
    echo
    echo "# Current Git Status"
    cat "$git_status_file"
    echo
    echo "# Current Diff Stat"
    cat "$git_diff_stat_file"
    echo
    echo "# Implementer Output Tail"
    tail -c "$IMPLEMENTER_TAIL_BYTES" "$impl_out"
  } > "$verify_prompt"

  set +e
  "$CODEX_CMD" exec "${codex_args[@]}" < "$verify_prompt" > "$verify_out" 2>&1
  code=$?
  set -e
  printf '%s\n' "$code" > "$verify_status"
  if [[ "$code" -ne 0 ]]; then
    echo "Verifier exited $code on iteration $iteration; restarting verifier/implementer after ${SLEEP_SECONDS}s" | tee -a "$LOG"
    sleep "$SLEEP_SECONDS"
    continue
  fi

  if tail -n 20 "$verify_out" | grep -qx 'VERDICT: PASS'; then
    echo "PASS on iteration $iteration" | tee -a "$LOG"
    printf '%s\n' "$iteration" > "$PASS_FILE"
    exit 0
  fi

  echo "FAIL on iteration $iteration; feeding capped verifier output back to implementer" | tee -a "$LOG"
  {
    echo
    echo "## Iteration $iteration Verifier Feedback"
    tail -c "$FEEDBACK_TAIL_BYTES" "$verify_out"
  } >> "$FEEDBACK"
done

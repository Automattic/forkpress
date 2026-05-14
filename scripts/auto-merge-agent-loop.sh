#!/usr/bin/env bash
set -u -o pipefail

REPO="${FORKPRESS_LOOP_REPO:-Automattic/forkpress}"
ISSUE="${FORKPRESS_LOOP_ISSUE:-39}"
SLEEP_SECONDS="${FORKPRESS_LOOP_SLEEP_SECONDS:-15}"
CODEX_BIN="${CODEX_BIN:-codex}"
CODEX_REASONING_EFFORT="${CODEX_REASONING_EFFORT:-medium}"
CODEX_DECISION_REASONING_EFFORT="${CODEX_DECISION_REASONING_EFFORT:-xhigh}"
WORKDIR="${FORKPRESS_LOOP_WORKDIR:-}"
LOG_DIR="${FORKPRESS_LOOP_LOG_DIR:-}"
SANDBOX="${CODEX_SANDBOX:-danger-full-access}"
APPROVAL="${CODEX_APPROVAL:-never}"
ENABLE_SEARCH="${CODEX_ENABLE_SEARCH:-1}"

if [[ -z "$WORKDIR" ]]; then
  WORKDIR="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
fi

if [[ -z "$LOG_DIR" ]]; then
  LOG_DIR="$WORKDIR/.forkpress-agent-loop"
fi

mkdir -p "$LOG_DIR"

NEXT_REASONING_FILE="$LOG_DIR/next-reasoning-effort"

validate_reasoning_effort() {
  case "$1" in
    low|medium|high|xhigh) return 0 ;;
    *) return 1 ;;
  esac
}

next_reasoning_effort() {
  local requested
  if [[ -s "$NEXT_REASONING_FILE" ]]; then
    requested="$(sed -n '1{s/^[[:space:]]*//;s/[[:space:]]*$//;p;q;}' "$NEXT_REASONING_FILE")"
    rm -f "$NEXT_REASONING_FILE"
    if validate_reasoning_effort "$requested"; then
      printf '%s\n' "$requested"
      return
    fi
    printf 'forkpress loop: ignored invalid reasoning request %q in %s\n' "$requested" "$NEXT_REASONING_FILE" >&2
  fi
  printf '%s\n' "$CODEX_REASONING_EFFORT"
}

validate_reasoning_effort "$CODEX_REASONING_EFFORT" || {
  echo "CODEX_REASONING_EFFORT must be low, medium, high, or xhigh." >&2
  exit 2
}
validate_reasoning_effort "$CODEX_DECISION_REASONING_EFFORT" || {
  echo "CODEX_DECISION_REASONING_EFFORT must be low, medium, high, or xhigh." >&2
  exit 2
}

top_args=()
top_args+=(-c 'service_tier="fast"')
if [[ "$ENABLE_SEARCH" != "0" ]]; then
  top_args+=(--search)
fi

model_args=()
if [[ -n "${CODEX_MODEL:-}" ]]; then
  model_args+=(-m "$CODEX_MODEL")
fi

extra_args=()
if [[ -n "${CODEX_EXTRA_ARGS:-}" ]]; then
  # shellcheck disable=SC2206
  extra_args=(${CODEX_EXTRA_ARGS})
fi

echo "forkpress loop: repo=$REPO issue=$ISSUE workdir=$WORKDIR"
echo "forkpress loop: logs=$LOG_DIR"

while true; do
  run_id="$(date -u +%Y%m%dT%H%M%SZ)"
  context_file="$LOG_DIR/issue-${ISSUE}-${run_id}.md"
  log_file="$LOG_DIR/run-${run_id}.log"
  last_message_file="$LOG_DIR/last-message-${run_id}.md"
  reasoning_effort="$(next_reasoning_effort)"
  run_top_args=("${top_args[@]}" -c "model_reasoning_effort=\"$reasoning_effort\"")

  {
    echo "# Coordination issue"
    gh issue view "$ISSUE" --repo "$REPO" --comments || true
    echo
    echo "# Local git status"
    git -C "$WORKDIR" status --short --branch || true
  } > "$context_file" 2>&1

  echo "forkpress loop: starting run $run_id service_tier=fast reasoning=$reasoning_effort"

  {
    cat <<'PROMPT'
You are continuing the ForkPress automatic branch mergeback work.

Use the GitHub issue context below as durable memory and coordination. Start by
reading the issue body, comments, and latest `Next steps`. Continue from the
highest-value unfinished item. Do not restart from scratch unless the issue says
the previous direction was abandoned.

Hard constraints:
- The base merge scenario must work with WordPress core plus arbitrary plugins
  without plugin authors declaring schemas, merge keys, conflict policies, or
  custom handlers.
- Optional plugin declarations or hooks may improve precision later, but must
  not be required for the default path.
- Any automatic conflict decision must be auditable and revisitable.
- Respect the existing working tree. Do not revert changes you did not make.
- Commit coherent, verified local checkpoints often. Prefer small commits after
  tests pass, and do not leave a successful run with a large dirty worktree
  unless the remaining dirty state is called out as a blocker.
- Maintain a human-readable changelog of work since the last known-good tag;
  use `CHANGELOG.md` if it exists, otherwise create it.
- Create annotated `known-good/...` Git tags only for major verified stable
  states, never for every commit. Tag after relevant verification passes, and
  record the tag name plus covered changes in the changelog and issue comment.
- If the obvious work appears complete, do not switch to passive monitoring.
  Re-read the issue, original goal, and current implementation, look for gaps,
  uncovered nuances, original-prompt omissions, difficult unaddressed parts,
  shallow implementation, shallow tests, edge cases, and user-flow or
  operational rough spots. Address the highest-value gap and continue.
- Keep context lean. Treat the issue and local files as durable state, but use
  targeted `rg`, `sed`, `git`, and focused log reads instead of broad history
  dumps whenever possible.
- For unusually important architecture, data-loss, security, or irreversible
  product decisions, write the decision reasoning effort value to the next
  reasoning request file named in the run metadata below. The next iteration
  will use that effort once, then return to normal.

Before exiting for any reason, append a comment to the coordination issue with:
- Current state
- Decision log
- Next steps
- Artifacts

If you make code changes, list changed paths in the issue comment and run the
smallest relevant verification that fits the change. Commit the verified slice
locally before exiting. If blocked, record the blocker and the next concrete
action in the issue comment.

PROMPT
    echo "# Run metadata"
    echo "service_tier=fast"
    echo "reasoning_effort=$reasoning_effort"
    echo "normal_reasoning_effort=$CODEX_REASONING_EFFORT"
    echo "decision_reasoning_effort=$CODEX_DECISION_REASONING_EFFORT"
    echo "next_reasoning_request_file=$NEXT_REASONING_FILE"
    echo
    echo "# Issue and workspace context"
    cat "$context_file"
  } | "$CODEX_BIN" "${run_top_args[@]}" exec \
    -C "$WORKDIR" \
    --yolo \
    "${model_args[@]}" \
    "${extra_args[@]}" \
    -o "$last_message_file" \
    - 2>&1 | tee "$log_file"

  status=${PIPESTATUS[1]}
  echo "forkpress loop: run $run_id exited with status $status"
  echo "forkpress loop: restarting after ${SLEEP_SECONDS}s; press Ctrl-C to stop"
  sleep "$SLEEP_SECONDS"
done

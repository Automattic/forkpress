run_with_heartbeat() {
  local label="$1"
  shift

  (
    while true; do
      sleep 60
      echo "--- :hourglass_flowing_sand: Still running: $label"
    done
  ) &
  local heartbeat_pid=$!
  local status=0
  "$@" || status=$?
  kill "$heartbeat_pid" >/dev/null 2>&1 || true
  wait "$heartbeat_pid" >/dev/null 2>&1 || true
  return "$status"
}

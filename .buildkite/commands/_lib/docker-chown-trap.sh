# Source from any docker-based BK step that may write to the
# bind-mounted workspace. We run as root inside the container; the BK
# agent on the host runs as the unprivileged `buildkite-agent` user.
# Without this repair, files we create (cargo target/, npm node_modules/,
# spc .build/) can stay root-owned or non-writable on the host and the next
# checkout's clean/remove step trips on them.
#
# Usage:
#   source .buildkite/commands/_lib/docker-chown-trap.sh

host_uid="$(stat -c %u .)"
host_gid="$(stat -c %g .)"
workspace_repaired=0

repair_workspace_permissions() {
  if [ "$workspace_repaired" -eq 1 ]; then
    return
  fi
  workspace_repaired=1

  # Skip the checkout's own `.git/` directory; the container should not write
  # there and walking it dominates trap cost. Nested Git checkouts under
  # `.build/`, such as static-php-cli, stay in scope because Buildkite must be
  # able to delete them before the next checkout.
  find . -mindepth 1 -maxdepth 1 ! -name .git -exec chown -R "$host_uid:$host_gid" {} + 2>/dev/null || true
  find . -mindepth 1 -maxdepth 1 ! -name .git -type d -exec chmod -R u+rwX,go+rX {} + 2>/dev/null || true
}

trap repair_workspace_permissions EXIT
trap 'repair_workspace_permissions; exit 143' TERM
trap 'repair_workspace_permissions; exit 130' INT
trap 'repair_workspace_permissions; exit 129' HUP

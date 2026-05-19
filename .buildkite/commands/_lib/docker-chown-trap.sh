# Source from any docker-based BK step that may write to the
# bind-mounted workspace. We run as root inside the container; the BK
# agent on the host runs as the unprivileged `buildkite-agent` user.
# Without this chown, any files we create (cargo target/, npm
# node_modules/, spc .build/) stay root-owned on the host and the
# next checkout's `git clean` trips on them.
#
# Usage:
#   source .buildkite/commands/_lib/docker-chown-trap.sh

host_uid="$(stat -c %u .)"
host_gid="$(stat -c %g .)"
trap 'chown -R "$host_uid:$host_gid" . 2>/dev/null || true' EXIT

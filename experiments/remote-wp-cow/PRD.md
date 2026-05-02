# PRD: Fast Remote WordPress COW Serve

## Problem

`wp-cow-lab-serve` must make a remote WordPress site locally usable quickly.
The current prototype can still feel stuck because WordPress boot touches many
files and options before the first byte reaches the browser. A design that is
theoretically lazy but blocks the first page for minutes is not acceptable.

## Goal

Given SSH access, a remote WordPress path, and a local port, one command should
produce a responsive local WordPress server without copying media uploads or all
database rows.

Target command shape:

```bash
wp-cow-lab-serve
```

## Success Criteria

- First run reaches a local HTTP response in under 15 seconds for the
  SiteGround test site, excluding Docker image build time.
- Repeated runs reach a local HTTP response in under 5 seconds.
- Startup output shows timed phases so slow work is visible.
- The browser must not spin indefinitely. Slow or failing remote work must
  return a visible error with timing context.
- No full `wp-content/uploads` copy.
- No optimistic full database row dump.
- Local writes must not reach production.

## Non-Goals

- Perfect visual fidelity for every media asset on first page load.
- A transparent MySQL protocol proxy.
- Full production snapshot semantics.
- Supporting non-Linux runtime hosts.

## Product Shape

The default runtime is request-driven. It must not assume plugin/theme/runtime
directories are small: any directory may contain large generated artifacts,
vendor caches, backups, or media-like data. Files are fetched only when the
local request path opens them, then remembered in the persistent local cache.

Startup should do:

1. Probe WordPress and remote DB credentials.
2. Export schema only.
3. Initialize empty local DB schema.
4. Start a persistent SSH tunnel for safe remote DB reads when the remote DB is
   reachable over TCP from the SSH host.
5. Start local PHP immediately with generated local `wp-config.php`, DB drop-in,
   and safety MU plugin.
6. Serve files lazily and persistently cache only the files touched by requests.

## File Materialization Policy

Fetch locally on demand:

- Any remote file that WordPress, PHP, or the browser actually opens.
- Remote directory entries only when a request actually lists that directory.
- Remote metadata needed for opened/listed files.

Remember:

- Cached file bytes in `file-cache/`.
- Cached remote metadata in `file-cache/metadata.json`.
- Local mutations separately in `upper/`.

Do not:

- Batch copy runtime directories by default.
- Copy `wp-content/uploads` up front.
- Assume plugin/theme directories are small.
- Re-fetch cached file bytes or metadata on subsequent runs unless explicitly
  refreshed.

## DB Policy

Initial startup must not dump rows. Reads should use a persistent tunneled
remote DB connection when available, not per-query SSH/PHP subprocesses. If a
query touches a locally materialized table, the involved tables should be routed
locally. Writes must be local-only.

If remote DB reads are too slow for first page boot, the next fallback should
be a bounded bootstrap materialization of only essential option rows, not a full
table dump.

## Observability

The CLI should print phase names and durations:

- probe
- schema export
- local schema init
- file cache hits/misses where practical
- mount
- php start
- first request diagnostics where practical

## Test Site

Use the SiteGround WordPress site supplied by the user:

```text
SSH: u2199-yx4tznmyunag@calm-cottage-mindfulness.com:18765
Key: ~/.ssh/id_siteground
Path: /home/u2199-yx4tznmyunag/www/calm-cottage-mindfulness.com/public_html
Remote URL: https://calm-cottage-mindfulness.com
Local URL: http://localhost:9481
```

Do not print secrets. Do not modify production data.

## Acceptance Test

From a clean clone state:

```bash
WPCOW_NAME=calm-cottage \
WPCOW_SSH=wp-cow-siteground-calm-cottage \
WPCOW_PATH=/home/u2199-yx4tznmyunag/www/calm-cottage-mindfulness.com/public_html \
WPCOW_REMOTE_URL=https://calm-cottage-mindfulness.com \
WPCOW_LOCAL_URL=http://localhost:9481 \
wp-cow-lab-serve
```

Then:

```bash
curl -I --max-time 10 http://localhost:9481/
```

The response must complete within the timeout. A WordPress error page is
acceptable during development only if it returns quickly with diagnostic output.

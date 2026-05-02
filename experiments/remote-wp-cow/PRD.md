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
- If the first real WordPress response is still warming files, the browser
  should receive a local splash/progress page quickly instead of a blank loading
  tab.
- The clone must serve the actual remote-backed site. A WordPress installation
  wizard indicates an empty or unavailable DB lower layer and must be surfaced
  as a wp-cow runtime error, not success.
- No full `wp-content/uploads` copy.
- No optimistic full database row dump.
- Local writes must not reach production.
- A warmed clone can be explicitly severed from the remote lower layers, then
  refreshed and used in `wp-admin` without opening SSH or remote DB reads.
- A local admin password reset must affect only the local materialized DB.

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
7. For the first dynamic browser request, show a temporary splash page that
   polls real file-cache progress while a bypass request warms WordPress.

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

The MVP implements that fallback for `*_options`: on the first matching
autoload/core-option read, it copies only autoloaded rows and core
identity/theme/plugin option names into the local database and routes matching
reads locally. Arbitrary non-bootstrap option reads still go through the remote
read path unless the table has been fully materialized.

## Severed Mode

`wp-cow sever <name>` turns a clone from live-lower mode into local-only mode.
It is not the default startup path because it must copy database rows, but it is
the expected path when the user wants to disconnect from production and keep
working locally.

Severing should:

- Materialize the core WordPress tables needed for local frontend/admin/content
  edits: options, users, usermeta, posts, postmeta, terms, term_taxonomy,
  term_relationships, comments, commentmeta, and links.
- Cache WordPress admin/runtime program files needed for offline `wp-admin`
  access without copying uploads.
- Optionally set a local administrator password in the local DB only.
- Write an offline marker that makes future `wp-cow run` skip SSH control
  masters, remote DB tunnels, remote filesystem reads, and daemon remote
  `/query` calls.
- Continue serving local upper-layer file writes and local DB writes after the
  remote link is severed.

## Observability

The CLI should print phase names and durations:

- probe
- schema export
- local schema init
- file cache hits/misses where practical
- mount
- php start
- first request file-cache progress through `/__wp-cow/progress`

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

The response must complete within the timeout. A splash/progress page is
acceptable while the first real page warms. A WordPress error page is acceptable
during development only if it returns quickly with diagnostic output. The
WordPress installation wizard is not acceptable as a successful response.

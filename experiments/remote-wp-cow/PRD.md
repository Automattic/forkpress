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

The default runtime is not pure lazy FUSE for all files. WordPress core and
runtime code are small enough to pre-materialize and too latency-sensitive to
serve file-by-file over SSH. Large user data remains lazy.

Startup should do:

1. Probe WordPress and remote DB credentials.
2. Export schema only.
3. Initialize empty local DB schema.
4. Pre-materialize a bounded runtime code set.
5. Start a persistent SSH tunnel for safe remote DB reads when the remote DB is
   reachable over TCP from the SSH host.
6. Start local PHP immediately with generated local `wp-config.php`, DB drop-in,
   and safety MU plugin.
7. Serve media and other large user data lazily only when requested.

## Runtime Materialization Policy

Copy locally:

- Root WordPress PHP files and common root assets.
- `wp-admin`.
- `wp-includes`.
- `wp-content/plugins`.
- `wp-content/themes`.
- `wp-content/mu-plugins`.
- `wp-content/languages`.
- Top-level `wp-content` drop-in files.

Do not copy by default:

- `wp-content/uploads`.
- Cache directories.
- Backup directories.
- SQL dumps and archive files.
- Arbitrary large data directories under `wp-content`.

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
- runtime sync
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

# wp-cow

`wp-cow` is a Linux-only prototype for a lazy local WordPress clone runtime.
It creates a local clone description instead of copying a whole site, mounts a
copy-on-write FUSE filesystem over SSH/PHP, and keeps database writes local by
materializing remote tables into a local MySQL database before writes run.

## ForkPress exploration status

This directory is an isolated experiment. It is not wired into the ForkPress
workspace, release artifact, runtime, or CI path. The goal is to explore whether
ForkPress should have a remote-site onboarding mode where a very large
production WordPress tree can be made locally usable without first copying every
file and every database row.

This deliberately violates the current ForkPress product shape in a few ways:
it uses a long-running local helper, FUSE, local MySQL, and SSH/PHP calls to the
remote host. Those choices are useful for proving out the lazy-lower-layer
model, but they should not be read as a proposed final integration shape.

## Build

```bash
cargo build
```

## Strict harness

```bash
scripts/strict-harness.sh
```

The harness runs the full Rust/PHP test suite plus targeted checks for lazy
file caching, installer blocking, row-level DB write isolation, offline guards,
FrankenPHP routing, local admin override wiring, and Docker lab port exposure.

## Docker lab on macOS

Use this when you are on a Mac and want a Linux shell with FUSE, FrankenPHP,
SSH, and local MariaDB available. The container is intentionally privileged so
FUSE can mount inside Docker Desktop's Linux VM. The Docker image uses the
official FrankenPHP PHP 8.3 image and installs `mysqli`, `opcache`, and `pdo_mysql` for
WordPress.

From this directory:

```bash
cp .env.example .env
$EDITOR .env
docker compose build
docker compose up -d
docker compose exec wp-cow-lab bash
```

The Compose host port is created from `WPCOW_HTTP_PORT` when the container is
created. FrankenPHP still listens on port `8080` inside the container. If you
want to open port 9481 on the Mac, set it in `.env` or pass it when starting
the lab:

```bash
WPCOW_HTTP_PORT=9481 docker compose up -d
```

If you change the port after the container already exists, recreate it:

```bash
docker compose down
WPCOW_HTTP_PORT=9481 docker compose up -d --force-recreate
```

Inside the container, keep `WPCOW_HTTP=0.0.0.0:8080`. `wp-cow-lab-serve`
derives `WPCOW_LOCAL_URL` from `WPCOW_HTTP_PORT` when the URL is not explicitly
overridden.

Inside the container, check the lab:

```bash
wp-cow-lab-check
```

If DNS fails inside Docker Desktop with an error such as
`Temporary failure in name resolution`, check and temporarily repair the
container resolver:

```bash
wp-cow-lab-dns
wp-cow-lab-dns --fix
```

If `--fix` works, keep these values in `.env` and recreate the container:

```bash
WPCOW_DNS1=1.1.1.1
WPCOW_DNS2=8.8.8.8
```

If your SSH command has flags, put them in `~/.ssh/config` on the Mac before
starting the container. For example:

```sshconfig
Host mysite
  HostName example.com
  User user
  Port 2222
  IdentityFile ~/.ssh/id_ed25519
```

Docker Compose mounts your Mac `~/.ssh` read-only at `/host-ssh`, copies it
into the container, and removes Apple-only OpenSSH options such as
`UseKeychain`. It also forwards the Docker Desktop SSH agent socket at
`/run/host-services/ssh-auth.sock`.

Set the real site values in `.env`, or export them inside the container.
`WPCOW_SSH` can be either a host alias from `~/.ssh/config` or a simple SSH
command copied from a host dashboard:

```bash
export WPCOW_NAME=example
export WPCOW_SSH=mysite
export WPCOW_PATH=/home/user/public_html
export WPCOW_REMOTE_URL=https://example.com
export WPCOW_LOCAL_URL=http://localhost:9481
```

For example, this is accepted:

```bash
export WPCOW_SSH='ssh -p18765 -i ~/.ssh/id_siteground user@example.com'
```

For a full local WordPress runtime, use one command:

```bash
wp-cow-lab-serve
```

That is the normal path. It creates or reuses the lazy clone, exports schema
only if needed, initializes an empty local MariaDB database if needed, mounts
the lazy filesystem, starts the DB control layer, and starts FrankenPHP. It does
not download media, runtime directories, or table rows up front.

File reads are request-driven. When WordPress opens a remote file, `wp-cow`
fetches that file into the persistent `file-cache/` and records the remote
metadata beside it. Later reads and later runs use the local cached copy instead
of fetching the file or statting it remotely again. Runtime batch sync is not
part of `serve`; old `WPCOW_RUNTIME_SYNC` environment values are ignored so
plugin/theme/runtime trees stay lazy too.

The first browser hit can still spend time fetching the exact PHP files needed
to boot WordPress. With `WPCOW_SPLASH=1` (the Docker default), `wp-cow` returns a
temporary local splash page immediately and starts the real request in the
browser. The splash polls `/__wp-cow/progress`, which is backed by the local file
cache progress file, then swaps in the warmed WordPress response. FrankenPHP is
started with multiple PHP threads (`WPCOW_PHP_WORKERS`, default `4`) so progress
polling can continue while the warm request is running. Set
`WPCOW_WEB_SERVER=php` only when you explicitly want the old PHP built-in
development server fallback.

Remote database reads are mediated by the local daemon by default. Generated
PHP does not contain the production DB name, user, password, or host, so plugins
using the normal `DB_*` constants see only the local COW proxy. Write-class SQL
is blocked from the remote database and materialized locally first.
`WPCOW_REMOTE_DB_TUNNEL=1` is an opt-in debugging/performance mode for hosts
where you explicitly accept opening a local SSH tunnel to the remote DB.

`wp-cow run` also starts a local MySQL protocol proxy on the generated `DB_HOST`
port. Core WordPress still uses the generated `db.php` drop-in with a direct
local MariaDB connection to avoid recursion, but plugins that open their own
`mysqli` connection using `DB_HOST` hit the proxy instead of the empty local
schema. The proxy applies the same row-COW/read-routing/write-blocking rules as
the drop-in before forwarding anything to local MariaDB or the remote read-only
lower layer.

On first WordPress boot, `wp-cow` special-cases the options-table bootstrap
query. It materializes only autoloaded option rows plus core identity/theme/plugin
option names into the local database, then routes those matching reads locally.
That keeps the common `SELECT ... FROM *_options WHERE autoload IN (...)` query
off the slow remote `/query` fallback without dumping the whole database.

Remote read queries that still need the lower database are cached under
`~/.wp-cow/clones/<name>/db/query-cache` by default. This makes repeated page
loads reuse local query results instead of crossing SSH/remote MySQL again.
Set `WPCOW_REMOTE_QUERY_CACHE=0` to disable it or adjust
`WPCOW_REMOTE_QUERY_CACHE_MAX_ROWS` for large result sets. Local write-class SQL
does not globally clear this cache; cached remote reads are used only while the
referenced tables have no local overlay state.

The FUSE mount also keeps warmed path metadata live long enough for repeat
renders to reuse the program files WordPress just touched. The Docker lab
defaults `WPCOW_FUSE_TTL_SECS` to `60`; lower values make live remote changes
visible sooner, while higher values reduce repeated path walking.
FrankenPHP also enables OPcache for parsed PHP code in the local web runtime.
There is no recursive runtime warm-up: PHP files, themes, plugins, and uploads
are fetched only when a request touches them, then cached for repeated reads.
Remote plugin and language directories stay visible through the lazy lower
layer by default so the local site can render the same active code as the
remote site. Set `WPCOW_ENABLE_PLUGINS=0` only when you need to suppress active
plugins during testing; files still remain lazy and are not copied up front.

Because active plugins are production code, the launched PHP runtime also
disables common side-effect escape hatches by default: process spawning,
`mail()`, raw socket clients, and URL-based includes. That is in addition to the
mu-plugin guards for WordPress mail and HTTP APIs. The generated DB drop-in
still needs local HTTP for daemon control calls, so direct plugin cURL or URL
file-wrapper calls are not fully sandboxed yet. Set
`WPCOW_ALLOW_UNSAFE_PLUGIN_SIDE_EFFECTS=1` only when you intentionally want to
let plugin code spawn local processes or use raw sockets.

The lab uses bounded request timeouts so a bad remote DB query, unreachable SSH
host, or slow remote file read should fail visibly instead of leaving the
browser spinning forever. Adjust the defaults with
`WPCOW_CONTROL_REQUEST_TIMEOUT_SECS`, `WPCOW_REMOTE_COMMAND_TIMEOUT_SECS`,
`WPCOW_REMOTE_DB_QUERY_TIMEOUT_SECS`, `WPCOW_PHP_MAX_EXECUTION_SECS`, and
`WPCOW_PHP_SOCKET_TIMEOUT_SECS`.

If WordPress tries to show the installation wizard, the router treats that as a
wp-cow DB/runtime failure. The clone should either show the real remote-backed
site or a diagnostic error; the installer is not considered a successful local
copy.

To sever a warmed clone from the remote lower layers, run:

```bash
export WPCOW_LOCAL_ADMIN_PASSWORD='8u239huiwdsj91das'
wp-cow-lab-sever
wp-cow-lab-run
```

`wp-cow-lab-sever` materializes only the WordPress tables already touched by the
clone, plus the user tables needed for a requested local admin password
override. It does not walk or prefetch the remote WordPress tree; pages and
admin screens you want available offline must be loaded once before severing so
their PHP files and DB rows are already materialized. It then writes
`run/offline.json`. After that marker exists, `wp-cow run` does not open SSH,
does not start the remote DB tunnel, and routes DB reads locally.

Open this on the Mac:

```text
http://localhost:9481/
```

For a filesystem-only smoke test that does not touch the remote DB, skip schema
export and mount the remote tree lazily:

```bash
export WPCOW_SKIP_SCHEMA=1
wp-cow-lab-clone
wp-cow-lab-mount
```

If you already created a filesystem-only clone and then want to open WordPress
in a browser, initialize the local database schema without deleting the clone or
its file cache:

```bash
wp-cow-lab-db-init
wp-cow-lab-run
```

Then open another shell:

```bash
docker compose exec wp-cow-lab bash
ls -la /mnt/wp-cow/example
cat /mnt/wp-cow/example/wp-config.php
```

Remote file contents are cached separately from local mutations in
`~/.wp-cow/clones/<name>/file-cache`, which is persisted by the Docker
`wp-cow-state` volume. Files up to `WPCOW_CACHE_MAX_FILE_MB` are cached as whole
files on first read, and their remote metadata is recorded in
`file-cache/metadata.json` so later runs do not need to stat those files
remotely again. Larger files are streamed by range. The Docker lab defaults that
limit to 64 MB. Check or clear the cache with:

```bash
wp-cow-lab-cache status
wp-cow-lab-cache warm-core
wp-cow-lab-cache clear
```

Stop the lab:

```bash
docker compose down
```

Remove persisted clone state and local MariaDB data:

```bash
docker compose down -v
```

## Typical flow

```bash
wp-cow serve \
  --name example \
  --ssh user@example.com \
  --path /home/user/public_html \
  --remote-url https://example.com \
  --local-url http://example.test \
  --mountpoint /mnt/wp-cow/example \
  --http 127.0.0.1:8080
```

`wp-cow serve` is the one-command runtime. It prepares only the metadata needed
to boot WordPress locally and leaves file contents and database rows lazy until
WordPress actually asks for them.

The clone state is stored under `~/.wp-cow/clones/<name>/`:

```text
manifest.json
upper/
whiteouts.json
file-cache/
db/
  schema.sql
  state.json
generated/
run/
```

## What is implemented

- SSH session reuse through OpenSSH control sockets.
- Remote WordPress probe through an ephemeral PHP script.
- Lazy remote file operations through PHP over SSH.
- Local COW filesystem through FUSE:
  - upper layer shadows remote files,
  - remote reads are fetched lazily,
  - small remote files are cached separately from local mutations,
  - deletions are recorded as whiteouts.
- Generated local `wp-config.php`, `wp-content/db.php`, and safety MU plugin.
- Schema import and full-table DB materialization through remote `mysqldump`.
- A local control HTTP server used by the DB drop-in and MySQL proxy:
  - read queries can be served from the remote DB through daemon-mediated PHP,
  - write-class SQL is never sent to the remote DB,
  - writes materialize affected table groups before executing locally.
- A local MySQL protocol proxy for code paths that bypass WordPress's `$wpdb`
  object and connect with the generated `DB_HOST` constant.

## Requirements

Local machine:

- Linux with `/dev/fuse` access.
- `ssh`, `frankenphp`, `php`, `mysql`, and `mysqldump` on `PATH`.
- A local MySQL/MariaDB server reachable by the generated DB settings.

Remote host:

- SSH access.
- PHP CLI.
- `mysqldump`.
- WordPress files at the supplied `--path`.

## Notes

This is an MVP. The DB layer now has both a WordPress `db.php` drop-in and a
local MySQL protocol proxy, but it is still conservative: complex SQL promotes
tables instead of attempting unsafe partial merges, and it does not provide true
point-in-time snapshot support without cooperation from the remote host.

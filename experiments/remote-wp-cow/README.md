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

## Docker lab on macOS

Use this when you are on a Mac and want a Linux shell with FUSE, PHP, SSH, and
local MariaDB available. The container is intentionally privileged so FUSE can
mount inside Docker Desktop's Linux VM.

From this directory:

```bash
cp .env.example .env
$EDITOR .env
docker compose build
docker compose up -d
docker compose exec wp-cow-lab bash
```

The Compose host port is created from `WPCOW_HTTP_PORT` when the container is
created. The PHP server still listens on port `8080` inside the container. If
you want to open port 9481 on the Mac, set it in `.env` or pass it when
starting the lab:

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
only if needed, initializes an empty local MariaDB database if needed,
pre-materializes the WordPress runtime files, mounts the lazy filesystem, starts
the DB control layer, and starts PHP. Runtime file sync copies the root PHP
files, `wp-admin`, `wp-includes`, plugin/theme/mu-plugin/language code, and
top-level `wp-content` drop-ins. It does not copy `wp-content/uploads` or other
large content data directories, and it does not download table rows up front.

Runtime file sync is enabled by default because real WordPress boot performs too
many PHP file reads and stats for pure per-file SSH/FUSE reads to feel usable.
Set `WPCOW_RUNTIME_SYNC_FORCE=1` to refresh the local runtime copy or
`WPCOW_RUNTIME_SYNC=0` to return to fully lazy filesystem reads.

The lab uses bounded request timeouts so a bad remote DB query, unreachable SSH
host, or slow remote file read should fail visibly instead of leaving the
browser spinning forever. Adjust the defaults with
`WPCOW_CONTROL_REQUEST_TIMEOUT_SECS`, `WPCOW_REMOTE_COMMAND_TIMEOUT_SECS`,
`WPCOW_REMOTE_DB_QUERY_TIMEOUT_SECS`, and `WPCOW_PHP_MAX_EXECUTION_SECS`.

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
files on first read; larger files are streamed by range. The Docker lab defaults
that limit to 64 MB. Check or clear the cache with:

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
- A local control HTTP server used by the DB drop-in:
  - read queries can be served from the remote DB through daemon-mediated PHP,
  - write-class SQL is never sent to the remote DB,
  - writes materialize affected table groups before executing locally.

## Requirements

Local machine:

- Linux with `/dev/fuse` access.
- `ssh`, `php`, `mysql`, and `mysqldump` on `PATH`.
- A local MySQL/MariaDB server reachable by the generated DB settings.

Remote host:

- SSH access.
- PHP CLI.
- `mysqldump`.
- WordPress files at the supplied `--path`.

## Notes

This is an MVP. The DB layer uses a WordPress `db.php` drop-in plus daemon
control endpoints; it does not yet implement a transparent MySQL protocol
proxy, row-level overlays, or true point-in-time snapshot support.

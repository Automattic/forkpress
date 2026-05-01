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

Inside the container, check the lab:

```bash
wp-cow-lab-check
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
export WPCOW_LOCAL_URL=http://localhost:8080
```

For example, this is accepted:

```bash
export WPCOW_SSH='ssh -p18765 -i ~/.ssh/id_siteground user@example.com'
```

For a full local WordPress runtime, clone schema, initialize local MariaDB, and
run the local PHP server:

```bash
wp-cow-lab-clone
wp-cow-lab-run
```

Open this on the Mac:

```text
http://localhost:8080/
```

For a filesystem-only smoke test that does not touch the remote DB, skip schema
export and mount the remote tree lazily:

```bash
export WPCOW_SKIP_SCHEMA=1
wp-cow-lab-clone
wp-cow-lab-mount
```

Then open another shell:

```bash
docker compose exec wp-cow-lab bash
ls -la /mnt/wp-cow/example
cat /mnt/wp-cow/example/wp-config.php
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
wp-cow clone \
  --ssh user@example.com \
  --path /home/user/public_html \
  --remote-url https://example.com \
  --local-url http://example.test

wp-cow init-db example
wp-cow run example --http 127.0.0.1:8080
```

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

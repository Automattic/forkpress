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

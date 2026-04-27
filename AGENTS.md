# Agent / Contributor Guidelines

## Language stack

- **Rust** for all native binaries and services (no Go, no Python, no Node).
- **PHP** for WordPress integration scripts, using [static-php-cli](https://github.com/crazywhalecc/static-php-cli) (`branched-wp/.spc/`) to produce a self-contained PHP binary with the `branchfs` extension baked in as a builtin.

## Single-binary constraint

Every deliverable must ship as **one static binary per target** — no shared libraries, no runtime dependencies, no sidecars.

- Linux: fully static musl binary (`x86_64-unknown-linux-musl`, `aarch64-unknown-linux-musl`)
- macOS: links only against `libSystem` (`x86_64-apple-darwin`, `aarch64-apple-darwin`)
- See `Makefile` and `.github/workflows/release.yml` for the existing cross-compilation matrix.

This rules out:
- FUSE (requires OS-level kernel module, not portable)
- Samba / system SMB (external daemon)
- Go (separate toolchain, not integrated into the Rust workspace)
- Any C library that cannot be statically linked (e.g. `libsamba`, `libfuse`)

## Adding a new service (e.g. fileserver)

- Add it as a new crate in the Cargo workspace (`branched-wp/Cargo.toml` `[workspace.members]`).
- For network protocols use pure-Rust crates that support static linking:
  - SFTP: `russh` + `russh-sftp` (pure Rust SSH/SFTP server)
  - SMB2: implement minimal SMB2 server in pure Rust (no `libsmbclient` bindings)
  - SQLite: `rusqlite` with the `bundled` feature (compiles SQLite from source, no system lib needed)
- Every new crate **must** include unit tests (`#[cfg(test)]`) and, where meaningful, integration tests under `branched-wp/tests/` or a crate-local `tests/` directory.

## Branch-specific MySQL access

Dolt exposes a MySQL-compatible server (port 13306 by default). Every branch is
accessible as a separate database using Dolt's native `database/branch` syntax:

```
mysql -h 127.0.0.1 -P 13306 -u root wordpress/my-branch
```

Writes go to that branch's working set immediately (COW, isolated from other
branches). Use `CALL DOLT_COMMIT('-am', 'message')` to snapshot. Use
`CALL DOLT_MERGE('my-branch')` to merge back into main.

`forkpress start` prints the MySQL connection string at startup. Pass
`--dolt-bind 0.0.0.0` to allow connections from remote machines.

## Tests

The project has two test layers:
1. **PHP unit tests** — `branched-wp/tests/*.php`, run via `make test`
2. **E2E shell tests** — `branched-wp/e2e/test_*.sh`, run against a live Docker stack

New Rust code must have Rust tests. New fileserver code must include at minimum:
- Unit tests for the SQLite store layer (list files, read blob, write+commit)
- Integration test that starts the server and connects with a real SFTP client library

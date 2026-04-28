# Agent / Contributor Guidelines

## Stack

- Rust is the only native binary language.
- PHP is used only for WordPress/runtime integration scripts.
- `static-php-cli` builds PHP with the `branchfs` extension compiled in.

## Product Shape

ForkPress ships as one static `forkpress` binary per target. Do not add daemons,
shared libraries, FUSE mounts, Docker runtime dependencies, or service sidecars.

Linux release targets use musl:

- `x86_64-unknown-linux-musl`
- `aarch64-unknown-linux-musl`

macOS release targets link only against `libSystem`:

- `x86_64-apple-darwin`
- `aarch64-apple-darwin`

## Repository Layout

- `forkpress/` contains the Rust CLI.
- `ext/` contains the PHP `branchfs` extension.
- `scripts/` contains bundled PHP operations.
- `runtime/` contains the PHP built-in-server router, WordPress bootstrap, and
  the WordPress archive embedded into the binary.
- `tests/` contains PHP unit tests.

## Branch Workflow

The issue #2 workflow is Git/worktree based:

- `forkpress serve` starts local branch previews.
- `forkpress clone` clones `http://wp.localhost:18080/site.git`.
- `forkpress agents` creates 10 branches and matching worktrees.
- `forkpress commit` stages, commits, and pushes a worktree.
- Each checkout includes `database.sql` as a read-only branch DB snapshot.

## Tests

Run Rust tests with:

```bash
FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress
```

Run PHP unit tests with:

```bash
make test-all
```

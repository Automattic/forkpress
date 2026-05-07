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

- `crates/forkpress-cli/` contains the Rust CLI package.
- `crates/experiments/` contains experimental Rust crates.
- `runtime/` contains production COW runtime files and the WordPress archive
  embedded into the binary.
- `scripts/` contains production/shared build, SQLite, Git, and COW helpers.
- `tests/` contains production COW PHP tests.
- `experiments/branchfs/` contains the experimental BranchFS schema, PHP
  extension, runtime files, scripts, Git adapter, and tests.
- `experiments/cas/` contains CAS experiment runtime files and tests.

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
FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli
```

Run PHP unit tests with:

```bash
make test-all
```

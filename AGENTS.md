# Agent / Contributor Guidelines

## Stack

- Rust is the only native binary language.
- PHP is used only for WordPress/runtime integration scripts.
- `static-php-cli` builds production PHP without experimental extensions.
- `static-php-cli` builds dev PHP with the experimental `branchfs` extension
  compiled in for `forkpress-dev`.

## Product Shape

ForkPress production ships as one static `forkpress` binary per target. The
developer build is a separate `forkpress-dev` binary that includes experiments.
Do not add daemons, shared libraries, FUSE mounts, Docker runtime dependencies,
or service sidecars.

Linux release targets use musl:

- `x86_64-unknown-linux-musl`
- `aarch64-unknown-linux-musl`

macOS release targets link only against `libSystem`:

- `x86_64-apple-darwin`
- `aarch64-apple-darwin`

## Repository Layout

- `crates/forkpress-cli/` contains the Rust binaries and command routing.
- `crates/forkpress-core/` contains shared layout, manifest, path, and storage
  strategy types.
- `crates/forkpress-storage/` contains production COW branch storage:
  APFS clonefile, APFS sparsebundle, Linux `FICLONE`, Windows ReFS block clone,
  and file-copy fallback.
- `crates/forkpress-runtime/` contains embedded PHP/WordPress runtime
  preparation and PHP script execution.
- `crates/forkpress-server/` contains the server process registry, stop/list
  helpers, and TCP readiness helpers.
- `crates/forkpress-git/` contains Git command, ref, worktree, and push-sync
  helpers.
- `runtime/` contains production COW runtime files and the WordPress archive
  embedded into the binary.
- `scripts/` contains production/shared build, SQLite, Git, and COW helpers.
- `scripts/windows/` and `installer/windows/` contain the Windows runtime bundle
  and click-through setup packaging.
- `tests/` contains production COW PHP tests.
- `experiments/branchfs/` contains the experimental BranchFS schema, PHP
  extension, runtime files, scripts, Git adapter, and tests.
- `experiments/cas/` contains CAS experiment runtime files, Rust crates, and
  tests.

## Branch Workflow

The issue #2 workflow is Git/worktree based:

- `forkpress serve` starts local branch previews.
- `forkpress clone` clones `http://wp.localhost:18080/site.git`.
- `forkpress agents` creates 10 branches and matching worktrees.
- `forkpress commit` stages, commits, and pushes a worktree.
- Each checkout includes `database.sql` as a read-only branch DB snapshot.

## Codex `.git` Sandbox Repair

Known Codex-only failure mode: default sandbox sessions may start with a
restrictive `umask` and create local Git object directories without execute
permission. Symptoms look like repository corruption or branch workflow
breakage, for example `insufficient permission for adding an object to
repository database .git/objects` during `fetch`, `commit`, or `push`.

This has been confirmed before as a Codex sandbox permissions issue. Any
`.git` write, object-database, ref-lock, fetch, commit, or push permission
problem seen inside Codex should be handled as sandbox damage first, even if it
appears while working on ForkPress Git or merge features.

Treat these `.git` permission failures as Codex sandbox damage first. In Codex
sessions, assume `.git` write/permission problems are the sandbox issue we have
seen before until the same command fails from a repaired checkout or a
danger-full-access lane. They are not evidence of a ForkPress Git, merge,
release, or CI regression unless the same failure reproduces outside the
default Codex sandbox permissions state. Do the repair below before
investigating product code.

Before commands that write Git objects or create test temp trees, use:

```bash
umask 0022
```

If a checkout is already affected, repair only that checkout's local Git
metadata. Use both `git rev-parse --git-dir` and `git rev-parse
--git-common-dir` because linked worktrees keep per-worktree state in one
directory and shared objects/refs in another. In a materialized bare repository,
run the same commands from inside that bare repository:

```bash
git_dir="$(git rev-parse --git-dir)"
git_common_dir="$(git rev-parse --git-common-dir)"
for dir in "$git_dir" "$git_common_dir"; do
    find "$dir" -type d -exec chmod u+rwx,go+rx {} +
    find "$dir" -type f -exec chmod u+rw,go+r {} +
done
```

For a linked worktree, run the snippet from the worktree root. For a bare or
materialized repository, run it from the bare repository directory itself. If
`git rev-parse` cannot run because the permissions are too broken, identify the
checkout's `.git` file/directory and common gitdir manually, repair those
directories with the same `find ... chmod ...` commands, then rerun
`git rev-parse`.

Do not reset the repository, rewrite history, delete worktrees, or revert
unrelated work to fix this. Do not patch ForkPress Git/merge code for this
class of error. After the permissions are repaired, rerun the original Git
command with `umask 0022`.

If the failure happens in a default Codex sandbox, move the work to a
danger-full-access lane or repair the sandbox checkout permissions there before
retrying. Do not keep rerunning the same Git command in the damaged sandbox.
Record the sandbox diagnosis in notes or PR context so later agents do not
spend time debugging product code for an environment issue.

## Tests

## Verification Budget

Default to the smallest verification that proves the touched behavior. Run
focused unit tests, syntax checks, and changed-file lint while iterating.

Run full verification only at natural integration points: before creating a
known-good tag, before asking for PR review, after touching build/release/test
infrastructure, after changing shared runtime behavior, or after several
focused slices have accumulated. Do not run full CI-equivalent suites after
every small change.

Do not poll remote CI from an implementation session unless the active task is
explicitly to diagnose CI. Check once after pushing, record the result, and
continue with local work or a focused fix.

Run Rust tests with:

```bash
cargo test --workspace --exclude forkpress-cli
FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli
FORKPRESS_RUNTIME_BUNDLE=/dev/null cargo test -p forkpress-cli --features dev-experiments --bin forkpress-dev
```

Run PHP unit tests with:

```bash
make test-all
```

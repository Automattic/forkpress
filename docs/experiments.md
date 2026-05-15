# Experiments

This is the only docs chapter that covers non-production storage work.
Production documentation should describe the `forkpress` binary and the
materialized copy-on-write storage model.

Experimental code is compiled into a separate `forkpress-dev` binary, never
into the production `forkpress`.

## Current experiments

- **BranchFS + SQLite COW** — the older `.forkpress/site.fp` strategy and
  PHP stream-wrapper research.
- **CAS + Redb manifests** — lazy file-store research using Redb manifests
  and the PHP `branchfs` extension.
- **Lazy namespace storage** — NFS/FUSE/FSKit/ProjFS-style branch views.
- **Embedded ZFS smoke tooling** — parked under experiment paths; not
  connected to production branch operations.

## Build the dev binary

```bash
make dist-dev
make forkpress-dev
```

Rust-only checks for the dev feature set:

```bash
FORKPRESS_RUNTIME_BUNDLE=/dev/null \
  cargo test -p forkpress-cli --features dev-experiments --bin forkpress-dev
```

The production wrapper rejects `dev-experiments`; use `--bin forkpress-dev`
whenever that Cargo feature is enabled.

## Policy

Experiments can inform future storage work, but they must not appear in
product-facing workflow, branching, merging, Git, or storage pages until
they are part of the production `forkpress` binary.

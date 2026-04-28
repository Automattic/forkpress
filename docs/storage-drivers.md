# Storage Driver Notes

Status: 2026-04-29

This is a working design note for ForkPress storage drivers. It records what
ships today, what is experimental, and what we have learned while embedding the
OpenZFS engine.

ForkPress has a single-binary constraint: each release target ships as one
`forkpress` executable with no daemon, shared library, kernel module, Docker
service, FUSE mount, or system PHP dependency. A storage driver is only viable
if it can fit that constraint or if the product explicitly changes the
constraint.

## Driver Summary

| Driver | Current status | Upsides | Downsides |
| --- | --- | --- | --- |
| BranchFS + SQLite COW | Default production driver. `strategy = "branchfs"`. | Small and portable. One `.forkpress/site.fp` file stores files, branch metadata, users, Git snapshots, and WordPress tables. Git smart HTTP is wired. Works with the bundled PHP runtime and does not need a host database. | Complex SQL compatibility surface. WordPress writes MySQL-shaped SQL that is translated to SQLite, then routed through branch views, overlays, tombstones, and triggers. Some plugin/query patterns can hit SQLite-view edge cases. File and DB versioning are separate layers that must be kept in sync by ForkPress code. |
| Materialized ZFS strategy | Experimental runtime path. `strategy = "zfs"` creates ordinary branch directories under `.forkpress/zfs/branches`. | Simple for WordPress: each branch is just a normal WP tree plus its own `wp-content/database/.ht.sqlite`. No BranchFS stream wrapper and no SQL-level branch overlays. Browser/admin workflows already exercise normal file and SQLite writes. | Not yet backed by ZFS datasets for normal branch operations. Branch creation currently materializes/copies branch directories. Git smart HTTP is not wired for this strategy. It is easy to understand but not the final storage model. |
| Embedded OpenZFS engine | Built into Linux and macOS ForkPress binaries; exposed by `forkpress zfs smoke`. | Real OpenZFS primitives inside the single binary: pool image, dataset create, snapshot, clone, export/import, logical file read/write. This is the path toward branch = ZFS dataset, create branch = snapshot + clone, and no SQL overlays. | Native OpenZFS userland is C code with strong POSIX assumptions. We had to add Darwin shims for endian, `uio`, `types32`, `libintl`, `dirent64`, error codes, `O_DIRECT`, SIMD auxv, `fstat64_blk`, and mutex teardown. Current engine API is narrow and not yet connected to branch import/export or Git. License review is required because OpenZFS is CDDL. |
| System ZFS | Not a ForkPress driver. Useful only as background comparison. | Mature snapshots/clones when the host already has OpenZFS installed. Kernel/filesystem integration means normal programs can read datasets directly. | Violates the single-binary constraint. Requires host kernel modules or platform filesystem drivers, admin permissions, installation, unload/upgrade handling, and platform-specific support. Not acceptable for the default local-agent distribution. |
| Dolt | Future candidate, not shipped. | Native database branching, commits, diffs, and merges. MySQL-compatible protocol could map well to WordPress's MySQL assumptions. | Dolt is a separate Go stack/server in its normal deployment model. Shipping it under the one-static-binary Rust constraint would require major integration work or a sidecar exception. It handles database state, not WordPress files, so we still need a file branch driver. |
| Turso/libSQL | Future candidate, not shipped. | SQLite-family technology with embeddable and replicated modes. Potentially attractive for branch-local databases and remote sync. | It does not automatically solve WordPress's MySQL dialect, branch merge semantics, or file versioning. We would still need a file driver and a clear model for per-branch DB isolation. |
| Plain filesystem copy/reflink | Useful primitive, not enough as the final driver. | Very easy to reason about. Ordinary files are easy for PHP, editors, backup tools, and debuggers. Reflinks/clones can be cheap on filesystems that support them. | No portable version graph. Reflink support and semantics differ by platform/filesystem. Rollback, merge, Git export, and garbage collection all become ForkPress responsibilities. |

## What Git Means In These Drivers

Git is an interface, not the storage source of truth.

For `branchfs`, Git smart HTTP is synthesized from `.forkpress/site.fp`.
`scripts/git_server/server.php` builds a temporary Git repository from BranchFS
file snapshots; on push, ForkPress applies `wordpress/` changes back into the
BranchFS file overlay and records a new file snapshot. `database.sql` is a
read-only context artifact and is ignored on push.

For the target ZFS driver, Git should source data from the ZFS branch dataset:
export `wordpress/` and a database snapshot from the dataset, accept pushed file
changes, write those changes into the target dataset, then snapshot. It should
not go through BranchFS tables.

## Target ZFS Model

The intended ZFS-backed driver is:

```mermaid
flowchart LR
    pool[(.forkpress/zfs/pool.img)]
    engine[embedded OpenZFS engine]
    main[dataset: main]
    branch[dataset: feature]
    workdir[materialized branch dir]
    wp[WordPress + SQLite DB file]
    git[Git protocol adapter]

    pool <--> engine
    engine --> main
    main -- snapshot + clone --> branch
    branch <--> workdir
    workdir <--> wp
    git <--> engine
```

The key property is that files and the WordPress SQLite database file are
versioned together by the filesystem layer. A post save on a branch mutates that
branch's dataset state; it does not write SQL overlay rows against a parent
table. A branch checkpoint is a dataset snapshot.

Because the embedded engine is not a kernel filesystem mount, PHP cannot
`require` PHP files directly from an OpenZFS dataset. ForkPress still needs a
materialization boundary:

1. Export or hydrate the requested branch dataset into
   `.forkpress/zfs/branches/<branch>`.
2. Run WordPress against ordinary files in that directory.
3. Under a branch lock, import changed files and the SQLite database file back
   into the dataset.
4. Snapshot the dataset after explicit commit/checkpoint operations.

That boundary is less elegant than a kernel mount, but it preserves the
single-binary constraint.

## What We Learned Embedding ZFS

The embedded engine is based on the real-zfs/OpenZFS userland subset and is
linked as a static archive into the Rust binary. It currently builds and passes
CI smoke tests on:

- `x86_64-unknown-linux-musl`
- `aarch64-unknown-linux-musl`
- `x86_64-apple-darwin`
- `aarch64-apple-darwin`

The smoke test creates a file-backed pool image, creates a dataset, writes a
logical file, snapshots, clones, exports, imports, and reads from the clone.

Important implementation notes:

- The engine opens a sparse pool image through ordinary file APIs. It does not
  load kernel ZFS and does not mount a ZPL filesystem.
- The API is intentionally small: pool create/import/export, dataset create,
  snapshot, clone, logical file write, and logical file read.
- macOS required many compatibility shims because the upstream userland code is
  closer to Linux/FreeBSD assumptions than to Darwin.
- ARM64 builds should use the portable Fletcher checksum path for now. The
  OpenZFS ARM64 NEON path compiled, but keeping the embedded build on portable
  checksum code reduces platform-specific risk.
- Darwin `kernel_fini()` teardown asserted in userspace cleanup after
  successful operations. ForkPress now treats the Darwin ZFS engine as
  process-lifetime state and lets process exit reclaim it. That is acceptable
  for short-lived CLI smoke and command execution, but long-running server use
  should avoid repeated init/fini cycles.
- The browser Wasm demo proves the primitives are viable, but the demo artifact
  is not directly shippable for ForkPress because it expects an Emscripten
  JavaScript/Wasm runtime. ForkPress instead links native static code on Linux
  and macOS.

## Windows And ZFS

Windows is the hardest platform for a ZFS driver.

The official OpenZFS documentation focuses its getting-started pages on Unix
platforms such as Linux distributions and FreeBSD. The Windows work lives in a
separate OpenZFS-on-Windows fork. That fork's README describes the Windows port
as beta and recommends starting with test data before trusting it. Its Windows
development README expects Visual Studio, the Windows Driver Kit, host and
target Windows VMs, remote kernel debugging, Secure Boot changes, driver
deployment, and sometimes test-signing mode.

That matters for ForkPress because our constraint is not "can an expert install
ZFS on Windows"; it is "can a downloaded `forkpress.exe` contain everything it
needs without a sidecar or privileged install". A Windows kernel filesystem
driver cannot fit that model:

- It is not just a static library linked into `forkpress.exe`.
- It requires driver installation and usually administrator privileges.
- Driver signing, Secure Boot, Windows Update interactions, and crash recovery
  become product concerns.
- A kernel driver is a side effect on the host OS, not local project state under
  `.forkpress/`.
- The POSIX shims we added for Linux/macOS do not solve Windows APIs, path
  semantics, case sensitivity, drive letters, IO completion, security
  descriptors, file locking, or driver lifecycle.

The more plausible Windows options are:

- Keep `branchfs` as the default Windows-capable driver if/when the Rust/PHP
  release target exists.
- Use the materialized-directory strategy on Windows without embedded ZFS,
  accepting that branch copy/rollback is a ForkPress-level implementation.
- Explore a Wasm build of the ZFS engine embedded into the Rust binary with a
  Rust Wasm runtime. This may preserve the single-binary story, but it would be
  a separate engine path with different performance, threading, file IO, and
  debugging behavior.
- Explore non-ZFS database/file strategies, such as libSQL/Turso for DB state
  plus a separate file snapshot layer.

For now, embedded ZFS should be considered Linux/macOS only. Windows support
needs its own design pass rather than trying to extend the current Darwin/Linux
native shims.

## Decision Criteria For New Drivers

A candidate driver should be evaluated on:

- Single-binary fit: can it ship without sidecars, shared libraries, kernel
  modules, or system package installs?
- WordPress fit: can WordPress load PHP files normally and write plugin/theme
  files, uploads, options, posts, users, and schema changes?
- Branch isolation: can a write affect only the selected branch?
- Branch creation cost: can create branch be O(changed data), not a full copy?
- Merge/reset model: can ForkPress explain and implement merge, rollback, and
  garbage collection?
- Git protocol model: can clone/fetch/push be derived from the driver without a
  lossy intermediate format?
- Debuggability: can a user inspect files, DB state, and logs when WordPress
  fails?
- Portability: can it work on Linux and macOS now, and what is the honest path
  for Windows?
- License: can the dependency be distributed inside ForkPress under the planned
  release license?

## References

- OpenZFS documentation: https://openzfs.readthedocs.io/en/latest/
- OpenZFS platform/distribution notes: https://www.openzfs.org/wiki/Distributions
- OpenZFS on Windows fork: https://github.com/openzfsonwindows/openzfs
- OpenZFS on Windows development README:
  https://github.com/openzfsonwindows/openzfs/tree/windows/module/os/windows
- OpenZFS on OS X documentation: https://openzfsonosx.org/wiki/Documentation

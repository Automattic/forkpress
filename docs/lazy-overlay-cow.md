# Lazy Overlay COW Options

Status: 2026-05-04

This note records the current storage decision. ForkPress is staying with the
macOS APFS COW file view for now. Native lazy overlay filesystems are useful
research, but they are not part of the current product path.

The distribution constraint still matters: ForkPress should ship as one
`forkpress` binary per target, with no Docker runtime, installed daemon,
third-party FUSE dependency, or separate database service.

## Current Decision

Use the current **APFS materialized COW** strategy on macOS:

- `forkpress init` creates `.forkpress/` and `./main`;
- `forkpress branch create marketing` creates `./marketing`;
- file contents are APFS cloned with `clonefile` when possible;
- branch-local SQLite databases are normal files;
- the sparsebundle fallback is still allowed when the project directory is not
  on an APFS volume that supports file clones.

Do not build the current milestone on FSKit. FSKit is too much packaging,
signing, entitlement, and user approval surface for a CLI-first single-binary
tool.

## Vocabulary

### APFS `clonefile` COW

APFS `clonefile` creates a new file entry whose data blocks initially share the
same APFS extents as the source file.

```text
./main/wp-load.php       -> inode A -> extents 100, 101, 102
./marketing/wp-load.php  -> inode B -> extents 100, 101, 102
```

When `./marketing/wp-load.php` is edited, APFS copies only the changed blocks:

```text
./main/wp-load.php       -> inode A -> extents 100, 101, 102
./marketing/wp-load.php  -> inode B -> extents 100, 555, 102
```

This gives cheap unchanged file contents and independent paths. It does not
make branch creation namespace-lazy: ForkPress still walks the source branch
and creates directory entries and cloned file entries in the destination branch.

### Lazy overlay COW

A lazy overlay branch starts as metadata:

```toml
branch = "marketing"
parent = "main"
overlay = ".forkpress/overlays/marketing"
```

The mounted filesystem resolves paths dynamically:

```text
read marketing/wp-load.php
  if overlay has wp-load.php: read overlay copy
  else if whiteout exists: return not found
  else: read main/wp-load.php

write marketing/wp-load.php
  if overlay lacks wp-load.php: copy-up from main, preferably using clonefile
  write overlay copy

delete marketing/wp-load.php
  create whiteout so the parent file is hidden

list marketing/wp-admin/
  merge parent entries + overlay entries - whiteouts
```

This makes branch creation close to `O(1)` and stores only changed files plus
whiteouts. The cost moves into filesystem correctness: lookup, readdir,
rename, locking, caching, xattrs, permissions, and SQLite behavior all need to
be correct enough for PHP, Git, editors, and shell tools.

## Approach Matrix

| Approach | User flow | Requirements | Runtime footprint | Benefits | Downsides | Fit now |
| --- | --- | --- | --- | --- | --- | --- |
| APFS materialized COW | Download one binary, run `./forkpress init`, `./forkpress serve`. | macOS APFS `clonefile`, or ForkPress-created APFS sparsebundle fallback. | One ForkPress process while serving. Optional mounted sparsebundle managed by `serve`/`stop`. | Normal directories, normal files, no install prompts, good editor/PHP/Git compatibility, branch writes do not affect parents. | Branch creation walks every file. Every branch has a materialized namespace. `du` can over-count cloned files. | Product path. |
| Embedded loopback NFS lazy overlay | `forkpress serve` starts an in-process local NFS server and mounts branch views. | macOS built-in NFS client. Mount may require `sudo` or a privileged helper depending mount location and options. | ForkPress process must stay alive as filesystem server. Mounted volume lifecycle must be managed. | Keeps one-binary story better than FUSE/FSKit. Can expose lazy branch directories to normal tools. | NFSv4 server implementation is substantial. File locking, cache invalidation, xattrs, permissions, rename semantics, and SQLite safety are high risk. | Best future experiment if lazy mounts become necessary. |
| macFUSE lazy overlay | Install/approve macFUSE, then run ForkPress mount. | Third-party macFUSE install and system extension approval. | ForkPress or helper process implements the filesystem through FUSE. | Familiar userspace filesystem model. Faster to prototype than NFS or FSKit. | Breaks no-dependency product shape. User approval/install friction. Kernel/system extension issues vary by macOS version. | Prototype only, not product path. |
| Apple FSKit lazy overlay | Install a signed app/extension, enable filesystem extension, then mount. | macOS FSKit support, app extension bundle, `Info.plist`, entitlements such as FSKit module entitlement, code signing, likely notarization. | App extension plus container app/helper. Mount managed through macOS filesystem extension infrastructure. | Native Apple-supported userspace filesystem route. No macFUSE dependency. Integrates with system mount tooling. | Not a single Rust binary. Requires Apple packaging, signing, entitlements, and extension approval flow. Still must implement overlay semantics ourselves. | Too much for this milestone. Do not pursue now. |
| WebDAV or SMB lazy-ish view | `forkpress serve` exposes a network share and macOS mounts it. | macOS built-in network filesystem clients. | ForkPress process must serve the protocol. | Easy conceptual mount story. | Poor fit for POSIX expectations, Git, PHP tools, SQLite locking, permissions, symlinks, and editor behavior. | Avoid for WordPress working trees. |
| PHP `branchfs` virtual filesystem | WordPress/PHP reads branch paths through the PHP stream wrapper. | ForkPress PHP runtime with `branchfs` extension. | No OS mount. Only PHP sees the virtual tree. | Lazy access without native mounts. Works inside the bundled runtime. | Other software cannot see the files as normal paths. Editors, Git, WP-CLI from outside the runtime, and shell tools are limited. | Useful compatibility/research path, not enough for normal-tool workflows. |

## Current APFS COW Gaps

The current macOS COW strategy is usable, but it is not the final storage
architecture. Known gaps:

- Branch creation is `O(number of files)`, because each branch gets a full
  directory namespace with cloned file entries.
- There is no semantic database merge between branches. Each branch has a
  branch-local SQLite file, and Git exports `database.sql` as a read-only
  snapshot.
- Git-created COW branches now work for simple branch pushes, but branch
  creation is still materialized APFS COW rather than lazy namespace COW.
- Branch reset is now one operation for materialized COW branches: ForkPress
  stages a fresh COW clone of the source branch, hot-copies the source SQLite
  database with SQLite backup, then publishes it over the target branch.
- ForkPress-served WordPress requests now take a shared operation lock, and
  branch mutations take the same lock exclusively. Direct filesystem writes
  from editors, shells, and other tools still bypass that advisory lock.
- Sparsebundle detach can still be blocked by terminals, editors, or processes
  holding files open under the mounted path.
- Linux and Windows do not have macOS COW parity yet.
- There is no storage garbage collection beyond ordinary branch deletion and
  filesystem cleanup.
- `du`, Finder, and disk analyzers can over-count APFS clones unless they report
  unique allocated extents.

## References

- Apple FSKit overview: <https://developer.apple.com/documentation/fskit>
- Apple passthrough FSKit guide: <https://developer.apple.com/documentation/FSKit/building-a-passthrough-file-system>
- macFUSE home: <https://macfuse.github.io/>
- FUSE-T NFS bridge design: <https://github.com/macos-fuse-t/fuse-t>

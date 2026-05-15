# Storage engines

ForkPress storage is built from platform-native file cloning primitives. The
goal is ordinary paths that work with editors, PHP, Git, WP-CLI, backup tools,
and shell commands.

## Native file cloning

Native file cloning is the default storage engine whenever the project
directory supports it.

- **macOS:** APFS `clonefile`
- **Linux:** `FICLONE` reflinks
- **Windows:** ReFS `FSCTL_DUPLICATE_EXTENTS_TO_FILE`

Each cloned file starts by sharing physical storage with its source. When one
branch writes to the file, the filesystem allocates new blocks for the changed
data. Branch paths remain independent.

## APFS sparsebundle

The macOS sparsebundle fallback creates an APFS image under
`.forkpress/macos-cow` and stores physical branch trees inside the mounted
image. Public branch directories remain visible beside `.forkpress`.

This keeps ForkPress rootless on macOS when the project volume itself cannot
clone files.

## Linux XFS loop volume

The Linux XFS loop fallback provides a reflink-capable filesystem even when the
project filesystem cannot reflink files. ForkPress stores physical branch trees
inside one shared mounted XFS volume and exposes public branch directories from
the project.

This fallback requires loop-device and mount privileges.

## Windows ReFS Dev Drive

The Windows installer prepares a ReFS Dev Drive so ForkPress can use native
block cloning through ordinary Win32 paths. ForkPress does not ask users to
install WSL, Docker, FUSE, WinFsp, or developer-only storage tools.

## File-copy materialization

Full file-copy materialization is available only when explicitly requested. It
does not share physical blocks and is not part of the automatic copy-on-write
cascade.

## What ForkPress does not use in production

Production ForkPress does not use SQL overlay views, branch table prefixes,
Docker, a MySQL daemon, FUSE mounts, helper daemons, or lazy virtual filesystem
namespaces.

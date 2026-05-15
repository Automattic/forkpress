# Linux storage

ForkPress uses Linux reflinks when the project filesystem supports them. If the
project filesystem rejects reflinks, ForkPress can use a shared XFS loop volume
as the copy-on-write fallback.

## `FICLONE` reflinks

The default Linux path uses the `FICLONE` ioctl. New branch files share
unchanged blocks with their source files until one branch writes to them.

The branch directories live directly in the project directory:

```text
main/
marketing/
.forkpress/
```

## Shared XFS loop fallback

When the project filesystem cannot reflink files, ForkPress expands an embedded
reflink-capable XFS image under the user's ForkPress data directory, attaches it
to a loop device, and mounts one shared XFS volume for ForkPress sites.

Each site gets a stable storage directory inside that shared mount. Public
branch directories remain visible in the project directory.

The setup uses Linux ioctls and `mount(2)` directly. It still requires
permission to allocate loop devices and mount filesystems, usually root or
`CAP_SYS_ADMIN`.

## Mount lifecycle

Inspect storage:

```bash
forkpress storage status
forkpress doctor storage
```

Detach storage only after stopping other ForkPress sites that use the same
shared XFS file view:

```bash
forkpress storage detach
```

Before deleting a Linux XFS-loop site, remove the site work dir, public branch
symlinks, and hidden per-site directory inside the shared mount while storage is
attached. `forkpress storage detach` prints the exact `rm -rf` command before it
unmounts the shared volume.

`du` can over-count reflink sharing on XFS. Compare filesystem free space before
and after branch operations when you need to measure physical growth.

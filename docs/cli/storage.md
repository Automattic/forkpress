# `forkpress storage`

`forkpress storage` inspects, attaches, detaches, and compacts mount-backed ForkPress branch storage.

Use it when you need to understand the selected COW file view or release mount-backed storage before moving or deleting a site.

## Usage

```bash
forkpress storage <command> [options]
```

## Subcommands

| Subcommand | Purpose |
| --- | --- |
| `status` | Show whether this site's mount-backed storage is attached and summarize storage state. |
| `mount` | Attach this site's mount-backed storage. |
| `detach` | Detach mount-backed storage so the work directory can be moved or deleted. |
| `compact` | Compact detached macOS APFS sparsebundle storage. |

## `status`

```bash
forkpress storage status [--work-dir <path>]
```

| Option | Default | Description |
| --- | --- | --- |
| `--work-dir <path>` | `.forkpress` | Site state directory to inspect. |

`status` prints the initialized storage strategy, selected file view, branch count, branch roots, mount state, and leftover staging directories from interrupted branch operations.

## `mount`

```bash
forkpress storage mount [--work-dir <path>]
```

| Option | Default | Description |
| --- | --- | --- |
| `--work-dir <path>` | `.forkpress` | Site state directory whose mount-backed storage should be attached. |

For APFS sparsebundle and Linux XFS loop file views, `mount` attaches storage and prints the public branch root. For direct reflink and file-copy views, it reports that no detachable mount is used.

## `detach`

```bash
forkpress storage detach [--work-dir <path>] [--keep-server] [--timeout <seconds>] [--force]
```

| Option | Default | Description |
| --- | --- | --- |
| `--work-dir <path>` | `.forkpress` | Site state directory whose mount-backed storage should be detached. |
| `--keep-server` | Disabled | Do not stop this site's ForkPress server before detaching. If a matching server is running, the command fails instead of stopping it. |
| `--timeout <seconds>` | `10` | Seconds to wait when stopping the matching site server. |
| `--force` | Disabled | Force detach. Use only after normal detach reports that the mount is busy. |

`detach` stops the matching ForkPress server unless `--keep-server` is passed. On Linux XFS loop storage, explicit detach fails if another running ForkPress server is still using the shared volume.

## `compact`

```bash
forkpress storage compact [--work-dir <path>] [--keep-server] [--timeout <seconds>] [--force]
```

| Option | Default | Description |
| --- | --- | --- |
| `--work-dir <path>` | `.forkpress` | Site state directory whose sparsebundle should be compacted. |
| `--keep-server` | Disabled | Do not stop this site's ForkPress server before compacting. If a matching server is running, the command fails instead of stopping it. |
| `--timeout <seconds>` | `10` | Seconds to wait when stopping the matching site server. |
| `--force` | Disabled | Force detach before compacting. Use only after normal detach reports that the mount is busy. |

`compact` applies only to macOS APFS sparsebundle COW storage. It detaches the sparsebundle first, then compacts it. For other file views, ForkPress reports that there is no compactable sparsebundle.

## Examples

Inspect storage:

```bash
forkpress storage status
```

Attach mount-backed storage:

```bash
forkpress storage mount
```

Detach storage before moving a project:

```bash
forkpress storage detach
```

Compact a macOS sparsebundle:

```bash
forkpress storage compact
```

## Related Commands

[`forkpress stop`](./stop.md) stops a server and performs automatic storage cleanup for the affected site. [`forkpress doctor storage`](./doctor.md#storage) probes local storage capabilities before or after initialization.

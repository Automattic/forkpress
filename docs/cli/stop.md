# `forkpress stop`

`forkpress stop` stops ForkPress preview servers and detaches mount-backed storage for the affected site.

Use it after `serve`, or when you need to release APFS sparsebundle or Linux XFS loop storage before moving or deleting a project.

## Usage

```bash
forkpress stop [options]
```

## Options

| Option | Default | Description |
| --- | --- | --- |
| `--work-dir <path>` | `.forkpress` | Stop the server registered for this site state directory. |
| `--all` | Disabled | Stop every running ForkPress site server for the current user. Cannot be combined with `--pid`. |
| `--pid <pid>` | None | Stop a specific ForkPress server process from the registry. Cannot be combined with `--all`. |
| `--timeout <seconds>` | `10` | Seconds to wait for graceful shutdown before forcing the process down. |
| `--force` | Disabled | Force detach of mount-backed storage after stopping the server. Use only after normal detach reports that the mount is busy. |

## Target Selection

Without `--all` or `--pid`, ForkPress stops the server matching `--work-dir`. It first checks the site's PID file, then the live server registry.

With `--all`, ForkPress stops every live server record for the current user and attempts storage cleanup for each affected site.

With `--pid`, ForkPress stops only the matching registered process.

## Storage Cleanup

After stopping matched servers, `stop` tries to detach mount-backed storage for each affected site. On Linux XFS loop storage, ForkPress leaves the shared mount attached if another running ForkPress server is still using it.

Use `--force` only after normal detach has failed and you have confirmed no process should still be using the mount.

## Examples

Stop the server for the current project:

```bash
forkpress stop
```

Stop a site using a custom state directory:

```bash
forkpress stop --work-dir .fp
```

Stop all ForkPress servers owned by the current user:

```bash
forkpress stop --all
```

Stop one registered process:

```bash
forkpress stop --pid 12345
```

## Related Commands

[`forkpress server stop`](./server.md#stop) uses the same stop implementation. [`forkpress storage detach`](./storage.md#detach) detaches storage without using the server-management shortcut.

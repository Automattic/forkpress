# `forkpress doctor`

`forkpress doctor` inspects local ForkPress environment capabilities.

The production command currently includes storage diagnostics for copy-on-write branch materialization.

## Usage

```bash
forkpress doctor <command> [options]
```

## Subcommands

| Subcommand | Purpose |
| --- | --- |
| `storage` | Probe branch file storage options for this machine and site state directory. |

## `storage`

```bash
forkpress doctor storage [--work-dir <path>] [--php-bin <path>]
```

| Option | Default | Description |
| --- | --- | --- |
| `--work-dir <path>` | `.forkpress` | Site state directory used to resolve the project and branch root paths. |
| `--php-bin <path>` | Embedded PHP | Accepted as a shared runtime option. Storage probing does not normally need it. |

## What It Reports

`doctor storage` prints a capability report with:

- the site state directory;
- the project directory;
- the public branch root path;
- the initialized storage strategy and file view when a site manifest exists;
- whether reflinks are available in the branch root;
- the recommended file-view path for the current platform.

On macOS it reports APFS sparsebundle availability through `hdiutil` when direct clone support is not available in place.

On Linux it reports the shared XFS loop-volume path and recommends it when direct reflinks are unavailable and the process has the needed loop and mount privileges.

On Windows it points to ReFS Dev Drive setup through the Windows installer.

## Examples

Inspect the default site:

```bash
forkpress doctor storage
```

Inspect another state directory:

```bash
forkpress doctor storage --work-dir .fp
```

## Related Commands

Use [`forkpress storage status`](./storage.md#status) to inspect the selected storage state after initialization. See [Storage Overview](../storage/overview.md) for the storage model.

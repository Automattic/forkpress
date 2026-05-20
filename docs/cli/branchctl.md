# `forkpress branchctl`

`forkpress branchctl` is an alias for [`forkpress branch`](./branch.md).

Use it only when an existing workflow or script already uses the `branchctl` name. New documentation and examples should prefer `forkpress branch`.

## Usage

```bash
forkpress branchctl [--work-dir <path>] [--php-bin <path>] <command> [options]
```

## Options

`branchctl` accepts the same shared options and subcommand options as [`forkpress branch`](./branch.md):

| Option | Default | Description |
| --- | --- | --- |
| `--work-dir <path>` | `.forkpress` | Site state directory whose branches should be managed. |
| `--php-bin <path>` | Embedded PHP | PHP binary used for runtime helpers and merge operations. |
| `-h`, `--help` | None | Print branch help. |

## Examples

These commands are equivalent:

```bash
forkpress branch create marketing --from main
forkpress branchctl create marketing --from main
```

These are also equivalent:

```bash
forkpress branch merge marketing --into main
forkpress branchctl merge marketing --into main
```

## Reference

For the full subcommand and option reference, use [`forkpress branch`](./branch.md).

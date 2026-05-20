# `forkpress server`

`forkpress server` manages background ForkPress preview servers for the current user.

Use it to start a site in the background, list registered servers, or stop servers without relying on the shorthand top-level commands.

## Usage

```bash
forkpress server <command> [options]
```

## Subcommands

| Subcommand | Purpose |
| --- | --- |
| `start` | Start this site's server in the background. |
| `list` | List running ForkPress site servers started by the current user. |
| `stop` | Stop a server by site, PID, or all known live records. |

## `start`

`forkpress server start` starts the site in the background. It accepts the same startup options as [`forkpress start`](./start.md), but forces background mode.

```bash
forkpress server start [options]
```

| Option | Default | Description |
| --- | --- | --- |
| `--work-dir <path>` | `.forkpress` | Site state directory to serve. |
| `--php-bin <path>` | Embedded PHP | PHP binary used to run WordPress and ForkPress runtime scripts. |
| `--host <address>` | `127.0.0.1` | TCP address the PHP server binds to. |
| `--port <port>` | `18080` | TCP port for previews and the Git smart-HTTP remote. |
| `--root-host <host>` | `wp.localhost` | Root host for the main preview and branch subdomains. |
| `--site-title <title>` | `ForkPress` | Site title used when bootstrapping a site that needs runtime preparation. |
| `--workers <n>` | `min(8, CPUs * 2)` | Number of PHP CLI server workers on Linux and macOS. |
| `--background` | Enabled by `server start` | Start in the background. Passing it is allowed but redundant. |
| `--foreground` | Disabled | Accepted by the parser, but `server start` still forces background mode. |

Example:

```bash
forkpress server start --port 18081
```

## `list`

`forkpress server list` prints live registry records.

```bash
forkpress server list
```

Output columns:

| Column | Description |
| --- | --- |
| `PID` | ForkPress wrapper process ID. |
| `PHP PID` | Child PHP server process ID when known. |
| `PORT` | Preview server port. |
| `ROOT HOST` | Root host used for previews. |
| `WORK DIR` | Site state directory. |
| `LOG` | ForkPress wrapper log path. |

If no live records exist, ForkPress prints `forkpress: no running site servers found`.

## `stop`

`forkpress server stop` stops matching registered servers and detaches mount-backed storage. It accepts the same options as [`forkpress stop`](./stop.md).

```bash
forkpress server stop [options]
```

| Option | Default | Description |
| --- | --- | --- |
| `--work-dir <path>` | `.forkpress` | Stop the server registered for this site state directory. |
| `--all` | Disabled | Stop every running ForkPress site server for the current user. Cannot be combined with `--pid`. |
| `--pid <pid>` | None | Stop a specific ForkPress server process. Cannot be combined with `--all`. |
| `--timeout <seconds>` | `10` | Seconds to wait for graceful shutdown before forcing the process down. |
| `--force` | Disabled | Force detach of mount-backed storage after stopping. |

Example:

```bash
forkpress server stop --work-dir .forkpress
```

## Shorthand Commands

| Shorthand | Equivalent |
| --- | --- |
| [`forkpress serve`](./serve.md) | `forkpress server start` |
| [`forkpress stop`](./stop.md) | `forkpress server stop` |

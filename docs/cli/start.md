# `forkpress start`

`forkpress start` starts the local ForkPress preview server in the foreground by default.

Use it when you want server output and lifecycle tied to the current terminal, or when you need the lower-level command that `serve` uses internally.

## Usage

```bash
forkpress start [options]
```

## Options

| Option | Default | Description |
| --- | --- | --- |
| `--work-dir <path>` | `.forkpress` | Site state directory to serve. |
| `--php-bin <path>` | Embedded PHP | PHP binary used to run WordPress and ForkPress runtime scripts. |
| `--host <address>` | `127.0.0.1` | TCP address the PHP server binds to. |
| `--port <port>` | `18080` | TCP port for previews and the Git smart-HTTP remote. |
| `--root-host <host>` | `wp.localhost` | Root host for the main preview and branch subdomains. |
| `--site-title <title>` | `ForkPress` | Site title used when bootstrapping a site that needs runtime preparation. |
| `--workers <n>` | `min(8, CPUs * 2)` | Number of PHP CLI server workers on Linux and macOS. Pass `--workers 1` for single-request debugging mode. |
| `--background` | Disabled | Start in the background and return after the server is ready. |
| `--foreground` | Enabled by default | Keep the server in the current terminal. Conflicts with `--background`. |

## Foreground Behavior

In foreground mode, `start` registers the server, prints the preview URLs, and keeps running until you press `Ctrl+C`. On shutdown it stops the PHP server and detaches mount-backed COW storage when appropriate.

If the PHP server exits unexpectedly, `start` reports the exit status and points to the PHP server log.

## Background Behavior

With `--background`, `start` behaves like [`forkpress serve`](./serve.md). It launches a detached child process, writes wrapper logs under `.forkpress/logs/forkpress-server.log`, waits for readiness, then exits.

## Examples

Start in the foreground:

```bash
forkpress start
```

Start in the background:

```bash
forkpress start --background
```

Bind another host and port:

```bash
forkpress start --host 127.0.0.1 --port 18100
```

Use a custom root host:

```bash
forkpress start --root-host wp.test
```

## Related Commands

Use [`forkpress serve`](./serve.md) for the normal background startup command. Use [`forkpress stop`](./stop.md) or [`forkpress server stop`](./server.md#stop) to stop background servers.

# `forkpress serve`

`forkpress serve` starts the local ForkPress preview server in the background and returns after it is ready.

Use it for normal local work. It is the background-friendly wrapper around [`forkpress start`](./start.md).

## Usage

```bash
forkpress serve [options]
```

## Options

| Option | Default | Description |
| --- | --- | --- |
| `--work-dir <path>` | `.forkpress` | Site state directory to serve. |
| `--php-bin <path>` | Embedded PHP | PHP binary used to run WordPress and ForkPress runtime scripts. |
| `--host <address>` | `127.0.0.1` | TCP address the PHP server binds to. |
| `--port <port>` | `18080` | TCP port for local previews and the Git smart-HTTP remote. |
| `--root-host <host>` | `wp.localhost` | Root host for the main preview and branch subdomains. |
| `--site-title <title>` | `ForkPress` | Site title used when bootstrapping a site that needs runtime preparation. |
| `--workers <n>` | `min(8, CPUs * 2)` | Number of PHP CLI server workers on Linux and macOS. Pass `--workers 1` for single-request debugging mode. |
| `--background` | Enabled by `serve` | Start in the background. Passing it is allowed but redundant. |
| `--foreground` | Disabled | Keep `serve` in the foreground. Conflicts with `--background`. |

## What It Does

`serve` turns on background mode and then runs the same startup path as `start`:

- prepares the embedded runtime if needed;
- checks that the requested host and port are available;
- attaches mount-backed COW storage when the site uses it;
- starts the PHP server;
- registers the server in the per-user ForkPress server registry;
- waits until the TCP port and registry entry are ready.

When startup succeeds, ForkPress prints the main preview URL, branch preview URL pattern, log commands, and stop command.

## URLs

With defaults:

```text
http://wp.localhost:18080/
http://<branch>.wp.localhost:18080/
http://wp.localhost:18080/site.git
```

The plain root host serves `main`. Branch subdomains serve the matching local branch.

## Examples

Start the default site in the background:

```bash
forkpress serve
```

Use another port:

```bash
forkpress serve --port 18081
```

Keep `serve` attached to the terminal:

```bash
forkpress serve --foreground
```

Force single-request PHP mode while debugging:

```bash
forkpress serve --workers 1
```

## Logs And Shutdown

Read logs with [`forkpress logs`](./logs.md):

```bash
forkpress logs --file wp
forkpress logs --file forkpress --follow
```

Stop the server with [`forkpress stop`](./stop.md):

```bash
forkpress stop
```

Use [`forkpress server list`](./server.md#list) to see all running ForkPress servers for the current user.

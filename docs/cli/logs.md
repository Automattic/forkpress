# `forkpress logs`

`forkpress logs` reads or follows WordPress, PHP, server, and maintenance logs for a ForkPress site.

Use it when a preview request fails, a background server does not start, or branch maintenance needs inspection.

## Usage

```bash
forkpress logs [options]
```

## Options

| Option | Default | Description |
| --- | --- | --- |
| `--work-dir <path>` | `.forkpress` | Site state directory whose logs should be read. |
| `--file <name>` | `wp` | Log file to read. Values: `wp`, `php`, `php-errors`, `server`, `forkpress`, `gc`, `all`. |
| `-n <lines>`, `--lines <lines>` | `80` | Number of lines to print before exiting or following. |
| `-f`, `--follow` | Disabled | Keep printing new log output until interrupted. Requires one concrete log file, not `--file all`. |
| `--paths` | Disabled | Print known log paths instead of log contents. |

## Log Files

| Name | Path | Description |
| --- | --- | --- |
| `wp` | `.forkpress/logs/wp-debug.log` | WordPress debug log. Critical errors and PHP fatals usually land here. |
| `php`, `php-errors` | `.forkpress/logs/php-errors.log` | PHP `error_log` target for the bundled server. |
| `server` | `.forkpress/logs/php-server.log` | PHP built-in server access and output log. |
| `forkpress` | `.forkpress/logs/forkpress-server.log` | ForkPress background server wrapper log. |
| `gc` | `.forkpress/logs/gc.log` | Background branch garbage-collection log. |
| `all` | All known logs | Reads each known log in sequence. |

If a log has not been created yet, ForkPress prints a short message instead of failing.

## Examples

Show recent WordPress errors:

```bash
forkpress logs --file wp
```

Follow PHP errors:

```bash
forkpress logs --file php --follow
```

Print only the last 20 server lines:

```bash
forkpress logs --file server --lines 20
```

List log paths:

```bash
forkpress logs --paths --file all
```

## Notes

Branch previews disable WordPress' generic fatal-error recovery screen and add a ForkPress shutdown logger. For branch request fatals, `wp-debug.log` should include the branch name, request URI, PHP file, line, and fatal message.

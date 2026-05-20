# Logs

ForkPress writes separate logs for WordPress, PHP, the local server wrapper, and
background maintenance.

Branch previews disable WordPress' generic fatal-error recovery screen and add a
ForkPress shutdown logger. When a plugin or theme fatals on a branch URL,
`wp-debug.log` should include the branch name, request URI, PHP file, line, and
fatal message instead of only showing WordPress' "critical error" page.

For command options and log-file aliases, see [`forkpress logs`](./cli/logs.md).

## Read logs

Show WordPress critical errors and PHP fatals:

```bash
forkpress logs --file wp
```

Follow WordPress log output while reproducing a browser issue:

```bash
forkpress logs --file wp --follow
```

Print every known log path:

```bash
forkpress logs --file all --paths
```

## Log files

- `wp`: `.forkpress/logs/wp-debug.log`
- `php`: `.forkpress/logs/php-errors.log`
- `server`: `.forkpress/logs/php-server.log`
- `forkpress`: `.forkpress/logs/forkpress-server.log`
- `gc`: `.forkpress/logs/gc.log`

# Logs

ForkPress writes separate logs for WordPress, PHP, the local server wrapper,
and background maintenance.

## Read logs

WordPress critical errors and PHP fatals:

```bash
forkpress logs --file wp
```

Tail WordPress output while reproducing a browser issue:

```bash
forkpress logs --file wp --follow
```

Show every known log path:

```bash
forkpress logs --file all --paths
```

## Log files

| Selector | File |
| --- | --- |
| `wp` | `.forkpress/logs/wp-debug.log` |
| `php` | `.forkpress/logs/php-errors.log` |
| `server` | `.forkpress/logs/php-server.log` |
| `forkpress` | `.forkpress/logs/forkpress-server.log` |
| `gc` | `.forkpress/logs/gc.log` |

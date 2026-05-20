# `forkpress remote`

`forkpress remote` manages local caches of remote WordPress sites and creates ForkPress branches from those caches.

Use it when the source site already exists on another server and you want a local branch preview with ForkPress merge metadata.

## Usage

```bash
forkpress remote [--work-dir <path>] [--php-bin <path>] <command> [options]
```

## Shared Options

| Option | Default | Description |
| --- | --- | --- |
| `--work-dir <path>` | `.forkpress` | Site state directory where remote-site cache metadata is stored. |
| `--php-bin <path>` | Embedded PHP | PHP binary used for MySQL import and branch creation helpers. |

Remote-site caches live under `.forkpress/cow/remote-sites/` for production COW sites.

## Subcommands

| Subcommand | Purpose |
| --- | --- |
| `clone` | Thin-clone a remote WordPress root over SSH and optionally branch from it. |
| `add` | Register an existing local cache or wp-cow clone. |
| `list` | List registered remote-site caches. |
| `show` | Show cache details for one registered remote site. |
| `branch` | Create a normal ForkPress COW branch from a registered remote cache. |

## `clone`

`remote clone` syncs a remote WordPress root with `rsync` over SSH. If the remote does not already have ForkPress SQLite data, ForkPress exports MySQL over the same SSH connection and imports it into the local cache before branching.

```bash
forkpress remote clone <name> --ssh <user@host> --path <remote-wp-root> [options]
```

| Option | Required | Description |
| --- | --- | --- |
| `<name>` | Yes | Local name for this remote-site cache. The name is normalized to lowercase ASCII letters, numbers, and dashes. |
| `--ssh <user@host>` | Yes | SSH target for rsync and optional MySQL export. |
| `--path <remote-wp-root>` | Yes | Remote WordPress root path. It must contain `wp-load.php`. |
| `--ssh-key <path>` | No | SSH private key to use for rsync and MySQL export. Use a shell-expanded path instead of an unexpanded `~`. |
| `--ssh-port <port>` | No | SSH port. Defaults to the SSH client default when omitted. |
| `--branch <branch>` | No | Create or recreate a local ForkPress branch from the synced cache after cloning. Requires COW storage. |
| `--remote-url <url>` | No | Production site URL to record in the cache manifest. |
| `--url <url>` | No | Alias for `--remote-url`. |
| `--local-url <url>` | No | Local URL hint to record in the cache manifest. |
| `--include-uploads` | No | Include `wp-content/uploads/` in the initial boot sync. |
| `--full-sync` | No | Download the full WordPress tree instead of the boot-critical subset. |
| `--exclude <pattern>` | No | Additional rsync exclude pattern. May be passed more than once. |
| `--no-delete` | No | Do not delete stale local cache files that disappeared remotely. |
| `--force` | No | Replace an existing remote-site registration. With `--branch`, also recreate an existing local branch from the fresh cache. |

Default thin clones skip uploads, cache directories, backup directories, logs, upgrade temp files, and `.git/`.

Example:

```bash
forkpress remote clone production \
  --ssh deploy@example.com \
  --ssh-key ~/.ssh/id_ed25519 \
  --ssh-port 2222 \
  --path /srv/www/example \
  --url https://example.com \
  --branch production-main
```

## `add`

`remote add` registers an existing local WordPress cache without syncing it.

```bash
forkpress remote add <name> (--cache-root <dir>|--wp-cow-clone <name-or-dir>) [options]
```

| Option | Required | Description |
| --- | --- | --- |
| `<name>` | Yes | Local name for this remote-site cache. |
| `--cache-root <dir>` | One of cache root or wp-cow clone | Existing materialized WordPress cache root. The directory must contain `wp-load.php` before it can be branched. |
| `--wp-cow-clone <name-or-dir>` | One of cache root or wp-cow clone | Existing wp-cow clone name or clone directory. ForkPress uses its file-cache mirror without deleting or moving it. |
| `--wp-cow-state-dir <dir>` | No | wp-cow state directory. Defaults to `WPCOW_HOME` or `~/.wp-cow` when `--wp-cow-clone` is a name. |
| `--ssh <user@host>` | No | Remote SSH target to store as metadata only. ForkPress does not modify the remote. |
| `--path <remote-wp-root>` | No | Remote WordPress path to store as metadata. |
| `--remote-url <url>` | No | Production site URL to store as metadata. |
| `--local-url <url>` | No | Local URL hint to store as metadata. |
| `--force` | No | Replace an existing remote-site registration without touching the cache. |

Example:

```bash
forkpress remote add production --cache-root /tmp/production-cache --remote-url https://example.com
```

## `list`

```bash
forkpress remote list
```

Prints each registered cache as tab-separated fields:

| Field | Description |
| --- | --- |
| Name | Registered remote-site name. |
| Cache root | Local materialized WordPress cache path. |
| File count | Number of files under the cache root. |
| `wp-load` | Whether the cache currently contains `wp-load.php`. |

## `show`

```bash
forkpress remote show <name>
```

Shows cache path, file count, byte count, `wp-load.php` presence, remote path, remote URL, and wp-cow source when available.

## `branch`

`remote branch` creates a normal ForkPress branch from a registered remote cache.

```bash
forkpress remote branch <remote> <branch>
```

| Argument | Description |
| --- | --- |
| `<remote>` | Registered remote-site name. |
| `<branch>` | Local branch to create from the remote cache. |

The command requires production COW storage and refuses to replace an existing branch. Use `remote clone --branch <branch> --force` when you intentionally want to refresh a cache and recreate the branch in one step.

Example:

```bash
forkpress remote branch production production-main
```

## Troubleshooting

`remote clone` depends on local `rsync` and SSH access to the remote server. If rsync fails, ForkPress prints a focused diagnostic and an SSH check command for the target path.

If MySQL import is needed, the remote PHP must be able to load `mysqli` and connect with the constants from `wp-config.php`.

## Related Pages

See [Remote Sites](../remote-sites.md) for the full remote-site onboarding workflow, including thin syncs, uploads, plugin installation, and preview URLs.

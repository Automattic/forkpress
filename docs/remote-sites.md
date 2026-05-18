# Remote Sites

Use remote-site cloning when you already have a WordPress tree on another
server and want a local ForkPress branch from it.

This guide covers the boot path:

- sync a remote WordPress root over SSH;
- keep the first sync small by skipping uploads and cache directories;
- create a local COW branch with merge-base metadata;
- install plugins from wp-admin in that branch;
- understand what ForkPress means by top-plugin support.

## Start From A ForkPress Site

Create or enter a local ForkPress site, then start the preview server:

```bash
mkdir client-site
cd client-site
forkpress init
forkpress serve
```

ForkPress creates the local `main` branch and the `.forkpress/` metadata
directory. Remote-site caches are stored under
`.forkpress/cow/remote-sites/`.

## Thin-Clone A Remote WordPress Root

Run `remote clone` with the SSH target, remote path, production URL, and local
branch name:

```bash
forkpress remote clone production \
  --ssh deploy@example.com \
  --ssh-key ~/.ssh/id_ed25519 \
  --ssh-port 2222 \
  --path /srv/www/example \
  --url https://example.com \
  --branch production-main
```

The remote path must be a materialized WordPress root. It needs `wp-load.php`
and, for COW branch creation, the ForkPress-compatible SQLite database at
`wp-content/database/.ht.sqlite`.

ForkPress uses `rsync` over SSH. By default it creates a boot cache and skips
large or rebuildable directories:

- `wp-content/uploads/`
- `wp-content/cache/`
- `wp-content/upgrade/`
- common backup directories
- logs and `.git/`

That default is meant to boot quickly. It avoids downloading uploads before the
first local preview.

## Include More Files When Needed

Include uploads in the initial sync:

```bash
forkpress remote clone production \
  --ssh deploy@example.com \
  --path /srv/www/example \
  --url https://example.com \
  --branch production-main \
  --include-uploads
```

Download the full tree:

```bash
forkpress remote clone production \
  --ssh deploy@example.com \
  --path /srv/www/example \
  --url https://example.com \
  --branch production-main \
  --full-sync
```

Add project-specific excludes:

```bash
forkpress remote clone production \
  --ssh deploy@example.com \
  --path /srv/www/example \
  --url https://example.com \
  --branch production-main \
  --exclude wp-content/private-exports/
```

Use `--force` to update an existing remote-site cache registration.

## Branch Later From A Cache

If you want to sync now and branch later, omit `--branch`:

```bash
forkpress remote clone production \
  --ssh deploy@example.com \
  --path /srv/www/example \
  --url https://example.com
```

Inspect and branch from the cache:

```bash
forkpress remote list
forkpress remote show production
forkpress remote branch production production-main
```

Branches created from a remote cache get the same merge metadata as normal
ForkPress branches: database merge bases, file merge bases, row identity
capture, and per-branch ID bands.

## Preview The Branch

Open the branch preview:

```text
http://production-main.wp.localhost:18080/
http://production-main.wp.localhost:18080/wp-admin/
```

The admin opens logged in by default. The branch switcher in the admin bar lets
you move between local branches.

## Install Plugins

Install plugins from WordPress admin inside the branch:

```text
http://production-main.wp.localhost:18080/wp-admin/plugin-install.php
```

You can also use the normal WordPress path:

```text
Plugins → Add New
```

ForkPress branch previews keep WordPress file modifications enabled for plugin
installation. The COW router also keeps `plugin-install.php` and `update.php`
requests on the `wp-admin` path, so install and update requests do not fall
through to the public site front controller.

After installing a plugin, work normally in the branch. Plugin files live under
that branch's `wp-content/plugins/`, and plugin database changes go to that
branch's SQLite database.

Merge the branch when you are ready:

```bash
forkpress branch merge production-main --into main
```

## Top Plugin Support

ForkPress tracks the current WordPress.org top 100 popular plugins as an
explicit compatibility target. The package smoke test downloads all 100 plugin
zips, extracts them into a `wp-content/plugins` tree, and verifies their plugin
headers without executing plugin code.

For the support matrix and the difference between generic merge audit coverage
and plugin-specific semantic recipes, see
[Top Plugin Support](./top-plugin-support.md).

## Troubleshooting

If branch creation reports that the source branch database does not exist, the
remote cache is not a ForkPress-compatible SQLite WordPress root. Register or
sync a cache that includes `wp-content/database/.ht.sqlite`.

If `rsync` fails, verify the SSH target, key, port, and remote path outside
ForkPress first:

```bash
ssh -i ~/.ssh/id_ed25519 -p 2222 deploy@example.com 'test -f /srv/www/example/wp-load.php'
```

If uploads are missing in the local preview, that is the default thin-clone
behavior. Re-run the clone with `--include-uploads` or `--full-sync`.

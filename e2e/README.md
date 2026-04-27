# e2e

End-to-end integration test that exercises the whole stack against real
WordPress + real `dolt sql-server`.

## Prerequisites

- `dolt` binary on `PATH` (`~/.local/bin/dolt` works if you put it there)
- `php` 8.2 with `mysqli`, `pdo_mysql`, `sqlite3`
- A built extension at `../ext/branchfs.so` (run `make` in the parent
  directory first)
- WordPress 6.5 source at `./wp-src/` — fetch with:
  ```bash
  curl -sL https://wordpress.org/wordpress-6.5.tar.gz -o /tmp/wp.tgz
  tar -C . -xzf /tmp/wp.tgz
  mv wordpress wp-src
  ```

## Run

```bash
bash run_e2e.sh
```

The script:

- picks ports (13306 for Dolt, 18080 for PHP)
- kills any leftover servers from previous runs
- creates `dolt-data/` and imports WP into a fresh branchfs SQLite store
- installs WordPress (creates admin user, default homepage) via
  `wp_install()`
- starts `php -S` with `router.php` as front controller and `branchfs.so`
  loaded
- runs the 8 HTTP-level assertions described in `../README.md`
- cleans up servers on exit

Re-running from scratch is idempotent. Exit code is non-zero on any failure.

## Manual mode

`dev.sh` bootstraps the same stack as `run_e2e.sh` (Dolt + branchfs + real
WordPress + php -S router) and then stays running so you can hit it from a
browser. Binds to `0.0.0.0:18080` by default so it's reachable from your
host.

```bash
bash e2e/dev.sh
# open http://localhost:18080/
# admin: http://localhost:18080/wp-login.php  (admin / admin)
```

The bootstrap output prints recipes for creating a preview branch, minting a
signed cookie, and merging back. See the top-level README for a worked
example.

## Files

| File | Purpose |
| --- | --- |
| `run_e2e.sh` | Automated 8-step driver (exits non-zero on failure) |
| `dev.sh` | Manual mode — bootstrap + keep servers running |
| `bootstrap_wp.php` | Writes branch-aware `wp-config.php` into the store, installs WordPress via `wp_install()` |
| `router.php` | `php -S` front controller — resolves branch from cookie/header, activates branchfs, serves PHP or static assets |

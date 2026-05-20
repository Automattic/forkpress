# `forkpress init`

`forkpress init` creates a new ForkPress site and seeds the local `main` WordPress branch.

Run it once from the project directory before starting the preview server or creating branches.

## Usage

```bash
forkpress init [options]
```

## Options

| Option | Default | Description |
| --- | --- | --- |
| `--work-dir <path>` | `.forkpress` | Site state directory. ForkPress writes metadata, runtime files, logs, locks, storage state, and the site manifest here. |
| `--php-bin <path>` | Embedded PHP | PHP binary used while preparing and initializing the runtime. |
| `--site-title <title>` | `ForkPress` | Site title written to the local WordPress configuration. |
| `--root-host <host>` | `wp.localhost` | Root host used in generated output and local preview hints. |
| `--admin-password <password>` | Random | Password for the seeded local admin user. If omitted, ForkPress generates a random password and prints it once. |

## What It Creates

For production COW storage, `init` prepares the embedded runtime, selects the available file-view strategy, creates the `main` branch, writes `.forkpress/site.toml`, and records branch metadata under `.forkpress/cow/`.

The usual project shape after initialization is:

```text
.forkpress/        # ForkPress runtime, metadata, logs, locks, and storage state
main/              # materialized WordPress tree for the main branch
```

The `main` branch contains an ordinary WordPress tree. Its database lives at `main/wp-content/database/.ht.sqlite`.

## Examples

Create a site with generated admin credentials:

```bash
forkpress init
```

Create a site with a known local admin password:

```bash
forkpress init --admin-password admin
```

Use a custom state directory:

```bash
forkpress init --work-dir .fp
```

Use a custom root host for preview URLs:

```bash
forkpress init --root-host wp.test
```

## Notes

`init` refuses to run when the selected `--work-dir` already contains a ForkPress site manifest. To create another site, use a different project directory or a different `--work-dir`.

Production ForkPress always initializes the materialized COW strategy. The experimental `forkpress-dev` binary may expose additional storage strategies.

## Next Steps

Start previews with [`forkpress serve`](./serve.md), then create branches with [`forkpress branch create`](./branch.md#create).

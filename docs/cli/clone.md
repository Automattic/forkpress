# `forkpress clone`

`forkpress clone` clones the ForkPress Git remote into a local editing checkout.

Use it after the preview server is running and you want to edit branch files through Git.

## Usage

```bash
forkpress clone [remote] [dir] [--remote-name <name>]
```

## Arguments And Options

| Argument or option | Default | Description |
| --- | --- | --- |
| `remote` | `http://wp.localhost:18080/site.git` | Git remote URL exposed by the running ForkPress server. |
| `dir` | `site` | Directory to create for the checkout. |
| `--remote-name <name>` | `origin` | Name for the configured Git remote. |

## What It Does

`clone` is a thin wrapper around:

```bash
git clone --origin <remote-name> <remote> <dir>
```

The checkout contains the Git view of each ForkPress branch. In production COW sites, the editable WordPress tree is under `wordpress/`, and `database.sql` is a generated, read-only database snapshot for context.

## Examples

Clone the default local remote:

```bash
forkpress clone
cd site
```

Clone into a named directory:

```bash
forkpress clone http://wp.localhost:18080/site.git client-site
```

Use another remote name:

```bash
forkpress clone --remote-name forkpress http://wp.localhost:18080/site.git site
```

## Next Steps

Switch to a branch, edit files under `wordpress/`, then push with [`forkpress commit`](./commit.md):

```bash
git switch marketing
forkpress commit -m "Update marketing page"
```

See [Git Workflow](../git-workflow.md) for the full Git transport model.

# `forkpress branch`

`forkpress branch` manages materialized WordPress branches, merges, merge audit records, and conflict review.

Use it after [`forkpress init`](./init.md) and [`forkpress serve`](./serve.md) to create previews, merge work back to `main`, and review conflicts with auditable choices.

## Usage

```bash
forkpress branch [--work-dir <path>] [--php-bin <path>] <command> [options]
```

`forkpress branchctl` is an alias for this command. See [`forkpress branchctl`](./branchctl.md).

## Shared Options

| Option | Default | Description |
| --- | --- | --- |
| `--work-dir <path>` | `.forkpress` | Site state directory whose branches should be managed. |
| `--php-bin <path>` | Embedded PHP | PHP binary used for runtime helpers and merge operations. |
| `-h`, `--help` | None | Print branch help. Also works as `forkpress branch <command> --help`. |

## Branch Lifecycle

| Subcommand | Purpose |
| --- | --- |
| `list` | Print all materialized branches for this site. |
| `show [branch]` | Show storage details for one branch. Defaults to `main`. |
| `status [branch]` | Alias for `show`. |
| `create <branch>` | Create a branch from another branch. Defaults to `--from main`. |
| `reset <branch>` | Replace a branch with a fresh copy of another branch. |
| `rollback <branch>` | Alias for `reset`. |
| `delete <branch>` | Delete a branch other than `main`. |
| `rm <branch>` | Alias for `delete`. |

### `list`

```bash
forkpress branch list
```

Prints one branch name per line.

### `show`

```bash
forkpress branch show [branch]
```

| Argument | Default | Description |
| --- | --- | --- |
| `branch` | `main` | Branch to inspect. |

`show` prints the branch directory, database path, file count, and related storage metadata.

### `create`

```bash
forkpress branch create <new-branch> [--from <source>]
```

| Option | Default | Description |
| --- | --- | --- |
| `--from <source>` | `main` | Source branch to copy into the new branch. |

Example:

```bash
forkpress branch create marketing --from main
```

### `reset`

```bash
forkpress branch reset <branch> --from <source> [--force]
```

| Option | Required | Description |
| --- | --- | --- |
| `--from <source>` | Yes | Source branch to copy over the target branch. |
| `--force` | No | Allow resetting `main`. |

`reset` replaces the target branch directory and database with a fresh copy-on-write clone of the source branch.

### `delete`

```bash
forkpress branch delete <branch>
```

Deletes a materialized branch. `main` cannot be deleted.

## Merge Commands

| Subcommand | Purpose |
| --- | --- |
| `merge <source> --into <target>` | Merge one branch into another and record audit metadata. |
| `history` | Show merge runs as source-to-target edges. |
| `merge-history` | Alias for `history`. |
| `tree` | Show the same merge edge data for topology-oriented review. |
| `recover-crash` | Inspect or restore pending merge crash-recovery artifacts. |
| `merge-recover` | Alias for `recover-crash`. |

### `merge`

```bash
forkpress branch merge <source> --into <target> [--plugin-validator <path>]
```

| Option | Required | Description |
| --- | --- | --- |
| `--into <target>` | Yes | Target branch that receives source changes. |
| `--plugin-validator <path>` | No | Run one plugin validator before reporting merge completion. |

Example:

```bash
forkpress branch merge feature --into main
```

ForkPress records merge run metadata, decisions, conflicts, resolutions, and crash-recovery state in the COW merge audit database. If generic merge logic cannot safely choose a result, the merge can complete with reviewable conflicts rather than silently inventing a repair.

### `history` And `tree`

```bash
forkpress branch history [--run <id>] [--limit <n>] [--format text|json]
forkpress branch tree [--run <id>] [--limit <n>] [--format text|json]
```

| Option | Default | Description |
| --- | --- | --- |
| `--run <id>` | None | Show one merge run. |
| `--limit <n>` | `20` | Number of recent runs to print. |
| `--format text\|json` | `text` | Output format. |

### `recover-crash`

```bash
forkpress branch recover-crash [--run <id>] [--restore-target-db] [--restore-files] [--format text|json]
```

| Option | Default | Description |
| --- | --- | --- |
| `--run <id>` | None | Limit recovery inspection or restore to one merge run. |
| `--restore-target-db` | Disabled | Restore the pre-merge target database from crash-recovery artifacts. |
| `--restore-files` | Disabled | Restore pre-merge target files from crash-recovery artifacts. |
| `--format text\|json` | `text` | Output format. |

Run it without restore flags first to inspect pending artifacts:

```bash
forkpress branch recover-crash
```

## Audit And Conflict Review

| Subcommand | Purpose |
| --- | --- |
| `merge-audit` | Inspect merge runs, decisions, conflicts, events, resolutions, rollback failures, and crash recovery. |
| `audit` | Alias for `merge-audit`. |
| `conflicts` | Shortcut for `merge-audit --records conflicts`. |
| `merge-review` | Attach review metadata to audit records. |
| `merge-resolve` | Validate or apply a conflict choice. |
| `revalidate-reviews` | Recheck reviewed conflicts against current target state. |
| `merge-revalidate` | Alias for `revalidate-reviews`. |
| `merge-apply-reviewed` | Apply validated, unapplied generic conflict resolutions. |

### `merge-audit`

```bash
forkpress branch merge-audit [options]
```

| Option | Default | Description |
| --- | --- | --- |
| `--format text\|json` | `text` | Output format. |
| `--limit <n>` | `20` | Maximum rows per record type where a limit applies. |
| `--run <id>` | None | Filter to one merge run. |
| `--scope all\|db\|files\|plugin` | `all` | Limit audit output by merge domain. |
| `--records all\|runs\|conflicts\|conflict-events\|decisions\|resolutions\|rollback-failures\|crash-recovery` | `all` | Record family to print. |
| `--conflict-type <type>` | None | Filter conflicts by conflict type. |
| `--conflict-id <id>` | None | Filter to one conflict id. Cannot be combined with `--conflict-key` when revalidating. |
| `--conflict-key <key>` | None | Filter by logical conflict key. Cannot be combined with `--conflict-id` when revalidating. |
| `--event-type <type>` | None | Filter conflict events. Values include `recorded`, `review-pending`, `review-needs-action`, `review-reviewed`, `resolution-validated`, `resolution-applied`, `resolution-blocked`, and `revalidation-required`. |
| `--plugin <name>` | None | Filter plugin-scoped conflicts by plugin name. |
| `--plugin-object <object>` | None | Filter plugin-scoped conflicts by plugin object. |
| `--plugin-severity info\|warning\|error\|critical` | None | Filter plugin-scoped conflicts by severity. |
| `--plugin-logical-identity <json>` | None | Filter plugin-scoped conflicts by logical identity JSON. |
| `--decision <value>` | None | Filter merge decisions. |
| `--path <path>` | None | Filter by relative file path. |
| `--path-prefix <path>` | None | Filter by relative file path prefix. |
| `--id-band-skips` | Disabled | Show ID-band skip records. |
| `--target-kept` | Disabled | Show records where target state was kept. |
| `--review` | Disabled | Include review-focused audit output. |
| `--review-status <status>` | None | Filter by `unreviewed`, `pending`, `needs-action`, or `reviewed`. |
| `--resolution-status validated\|applied` | None | Filter resolution records. |
| `--lifecycle-state <state>` | None | Filter by `unreviewed`, `deferred`, `needs-action`, `reviewed`, `validated`, or `resolved`. |
| `--next-action <action>` | None | Filter by `review`, `run-plugin-validator`, `wait`, `revalidate`, `resolve`, `apply-reviewed-choice`, `manual-review`, or `none`. |
| `--revalidation-class <class>` | None | Filter by revalidation class, such as `unchanged`, `compatible-target-drift`, `missing`, `incompatible`, or `replacement-evidence`. |
| `--latest-revalidation-status <status>` | None | Filter by `none`, `current`, `source-drifted`, `target-drifted`, `source-and-target-drifted`, or `unknown`. |
| `--stale-status fresh\|stale\|error\|unknown` | None | Filter reviewed conflicts by stale status. |
| `--resolution-choice source\|target\|plugin-driver` | None | Filter conflicts or resolutions by available or selected choice. |
| `--blocked-resolution-choice source\|target` | None | Filter conflicts where a choice is visible but blocked. |
| `--resolution-strategy <strategy>` | None | Filter by `manual-review`, `plugin-validator`, `schema-choice`, `file-choice`, `row-choice`, or `cell-choice`. |
| `--generic-resolver yes\|no` | None | Filter conflicts by generic resolver support. |
| `--after-revalidate supported\|unsupported` | None | Filter by whether after-revalidate application is supported. |
| `--group-by <field>` | `none` | Group output. Supported fields include `table`, `status`, `path`, `type`, `severity`, `lifecycle`, `event-type`, `next-action`, `conflict-key`, `resolution-strategy`, `generic-resolver`, `after-revalidate`, `revalidation-class`, `latest-revalidation-status`, `stale-status`, `plugin`, `plugin-object`, `plugin-severity`, and `plugin-logical-identity`. |
| `--revalidate` | Disabled | Revalidate reviewed conflicts instead of printing audit output. Only accepts `--run`, `--conflict-id`, `--conflict-key`, `--reviewer`, `--format`, and `--quiet`. |
| `--reviewer <name>` | None | Reviewer name for `--revalidate`. |
| `--quiet` | Disabled | Suppress nonessential output for `--revalidate`. |

### `conflicts`

```bash
forkpress branch conflicts [options]
```

`conflicts` accepts the same options as `merge-audit`, but defaults `--records` to `conflicts`.

Examples:

```bash
forkpress branch conflicts --run 42
forkpress branch conflicts --run 42 --lifecycle-state needs-action
forkpress branch conflicts --run 42 --group-by next-action
```

### `merge-review`

```bash
forkpress branch merge-review <conflict|decision|resolution> <id> --status <pending|needs-action|reviewed> --note <text> [--reviewer <name>]
forkpress branch merge-review conflict-key <key> [--run <id>] --status <pending|needs-action|reviewed> --note <text> [--reviewer <name>]
```

| Option | Required | Description |
| --- | --- | --- |
| `--status pending\|needs-action\|reviewed` | Yes | Review status to attach. |
| `--note <text>` | Yes | Human review note. |
| `--reviewer <name>` | No | Reviewer name. |
| `--run <id>` | Only for `conflict-key` | Disambiguate a logical conflict key. |

### `merge-resolve`

```bash
forkpress branch merge-resolve conflict <id> (--choice <source|target> [--apply]|--apply-reviewed) [--after-revalidate] [--note <text>] [--reviewer <name>]
forkpress branch merge-resolve conflict-key <key> [--run <id>] (--choice <source|target> [--apply]|--apply-reviewed) [--after-revalidate] [--note <text>] [--reviewer <name>]
```

| Option | Required | Description |
| --- | --- | --- |
| `--choice source\|target` | Yes, unless `--apply-reviewed` is used | Validate the chosen side. |
| `--apply` | No | Apply the chosen side immediately after validation. |
| `--apply-reviewed` | Alternative to `--choice` | Apply the latest validated choice. Cannot be combined with `--choice` or `--apply`. |
| `--after-revalidate` | No | Allow application after compatible target drift has been revalidated. |
| `--note <text>` | No | Resolution note. |
| `--reviewer <name>` | No | Reviewer name. |
| `--run <id>` | Only for `conflict-key` | Disambiguate a logical conflict key. |

### `revalidate-reviews`

```bash
forkpress branch revalidate-reviews [--run <id>] [--conflict-id <id>|--conflict-key <key>] [--reviewer <name>] [--format text|json] [--quiet]
```

| Option | Default | Description |
| --- | --- | --- |
| `--run <id>` | None | Revalidate conflicts for one merge run. |
| `--conflict-id <id>` | None | Revalidate one conflict id. Cannot be combined with `--conflict-key`. |
| `--conflict-key <key>` | None | Revalidate one logical conflict key. Cannot be combined with `--conflict-id`. |
| `--reviewer <name>` | None | Reviewer name recorded for revalidation events. |
| `--format text\|json` | `text` | Output format. |
| `--quiet` | Disabled | Suppress nonessential output. |

### `merge-apply-reviewed`

```bash
forkpress branch merge-apply-reviewed [--run <id>] [--limit <n>] [--note <text>] [--reviewer <name>] [--format text|json]
```

| Option | Default | Description |
| --- | --- | --- |
| `--run <id>` | None | Apply validated choices for one merge run. |
| `--limit <n>` | None | Maximum number of resolutions to apply. |
| `--note <text>` | None | Resolution note. |
| `--reviewer <name>` | None | Reviewer name. |
| `--format text\|json` | `text` | Output format. |

Inspect candidates first:

```bash
forkpress branch merge-audit --next-action apply-reviewed-choice
```

## Plugin Validators And Drivers

| Subcommand | Purpose |
| --- | --- |
| `run-plugin-validator` | Run one plugin validator for a merge run and record emitted findings. |
| `record-plugin-validator-conflicts` | Record plugin-scoped validator findings from JSON. |
| `run-plugin-driver` | Run one plugin merge driver for a conflict and record its result. |
| `record-plugin-driver-resolution` | Record first-class audit evidence from a plugin-specific merge driver. |

### `run-plugin-validator`

```bash
forkpress branch run-plugin-validator --run <id> --validator <path> [--format text|json]
```

| Option | Required | Description |
| --- | --- | --- |
| `--run <id>` | Yes | Merge run id. |
| `--validator <path>` | Yes | Validator executable or script. |
| `--format text\|json` | No | Output format. Defaults to `text`. |

### `record-plugin-validator-conflicts`

```bash
forkpress branch record-plugin-validator-conflicts --run <id> (--findings-file <path>|--findings-json <json>) [--format text|json]
```

| Option | Required | Description |
| --- | --- | --- |
| `--run <id>` | Yes | Merge run id. |
| `--findings-file <path>` | One of findings file or JSON | File containing validator findings JSON. Preferred for real validators. |
| `--findings-json <json>` | One of findings file or JSON | Inline JSON findings. Best for small fixtures. |
| `--format text\|json` | No | Output format. Defaults to `text`. |

### `run-plugin-driver`

```bash
forkpress branch run-plugin-driver conflict <id> --driver <path> [--note <text>] [--reviewer <name>] [--format text|json]
forkpress branch run-plugin-driver conflict-key <key> [--run <id>] --driver <path> [--note <text>] [--reviewer <name>] [--format text|json]
```

| Option | Required | Description |
| --- | --- | --- |
| `--driver <path>` | Yes | Plugin merge driver executable or script. |
| `--run <id>` | Only for `conflict-key` | Disambiguate a logical conflict key. |
| `--note <text>` | No | Resolution note. |
| `--reviewer <name>` | No | Reviewer name. |
| `--format text\|json` | No | Output format. Defaults to `text`. |

The driver must emit JSON with status `validated` or `applied` and a `result` value.

### `record-plugin-driver-resolution`

```bash
forkpress branch record-plugin-driver-resolution conflict <id> --driver <name> (--result-file <path>|--result-json <json>) [--previous-file <path>|--previous-json <json>] [--applied] [--note <text>] [--reviewer <name>] [--format text|json]
forkpress branch record-plugin-driver-resolution conflict-key <key> [--run <id>] --driver <name> (--result-file <path>|--result-json <json>) [--previous-file <path>|--previous-json <json>] [--applied] [--note <text>] [--reviewer <name>] [--format text|json]
```

| Option | Required | Description |
| --- | --- | --- |
| `--driver <name>` | Yes | Driver name to record in audit metadata. |
| `--run <id>` | Only for `conflict-key` | Disambiguate a logical conflict key. |
| `--result-file <path>` | One of result file or JSON | File containing driver result JSON. |
| `--result-json <json>` | One of result file or JSON | Inline driver result JSON. |
| `--previous-file <path>` | No | File containing previous plugin state JSON. Cannot be combined with `--previous-json`. |
| `--previous-json <json>` | No | Inline previous plugin state JSON. Cannot be combined with `--previous-file`. |
| `--applied` | No | Mark the driver result as applied. |
| `--note <text>` | No | Resolution note. |
| `--reviewer <name>` | No | Reviewer name. |
| `--format text\|json` | No | Output format. Defaults to `text`. |

## Examples

Create and preview a feature branch:

```bash
forkpress branch create marketing --from main
```

Merge a branch into `main`:

```bash
forkpress branch merge marketing --into main
```

Review and apply one conflict:

```bash
forkpress branch conflicts --run 42
forkpress branch merge-review conflict 7 --status reviewed --note "Reviewed metadata"
forkpress branch merge-resolve conflict 7 --choice source --apply
```

Run a plugin validator for a conflicted merge:

```bash
forkpress branch run-plugin-validator --run 42 --validator ./validator.php --format json
```

## Related Pages

See [Branching](../branching.md) for the conceptual model, [Merging](../merging.md) for merge behavior, [Conflict Review](../conflict-review.md) for review queues, and [Plugin Validator Recipes](../plugin-validator-recipes.md) for plugin-specific conflict checks.

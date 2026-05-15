# Plugin Merge Validators

Status: partial implementation target

ForkPress can merge SQLite rows and files, but plugins often store one logical
object across custom tables, `postmeta`, options, JSON, serialized PHP values,
and uploaded/generated files. A generic row merge cannot safely infer those
semantics or rewrite embedded IDs.

Plugin validators are the intended boundary: plugins should be able to inspect
a merged candidate and either confirm that their object graph is coherent or
return reviewable conflicts.

## Contract

A validator should be deterministic and side-effect free. It receives:

- The source branch name and target branch name.
- Read-only handles or paths for base, source, target-before, and candidate
  target databases.
- Read-only paths for base, source, target-before, and candidate target file
  trees.
- The current merge run id and metadata database path for writing findings
  through ForkPress-provided helpers, not through plugin SQL.

A validator returns one of:

- `valid`: the merged candidate preserves this plugin's invariants.
- `conflicts`: the candidate is reviewable; each finding identifies the plugin,
  affected logical object, tables/files/options involved, and a human-readable
  reason.
- `failed`: the validator could not run; the merge should fail rather than
  silently accept an unchecked plugin graph.

Validators must not rewrite the candidate database or filesystem. A future
merge driver may do that, but validators are only a gate.

## Invariants To Check

The first validator API should support checks for:

- Custom-table rows whose IDs are embedded in JSON or serialized values.
- Cross-table parent/child rows where the database has no foreign keys.
- Options that point at posts, terms, users, media, or plugin custom rows.
- Plugin files referenced from custom tables, options, or postmeta.
- Generated files that can be safely regenerated versus files that must merge
  as user content.
- Tombstones or soft-delete markers that must agree with related rows/files.

## Merge Behavior

Validators run after the generic DB/files candidate has been staged and before
the merge is reported as completed.

- `valid` findings leave the normal merge status unchanged.
- `conflicts` change the merge status to `completed_with_conflicts` and record
  plugin-scoped conflict metadata.
- `failed` rolls back the staged candidate like any other merge failure.

This preserves the current safety model: ForkPress may apply exact safe changes,
preserve target state, or stop with an auditable conflict, but it should not
invent plugin-specific rewrites.

The current implementation has the metadata/audit foundation for validator
conflicts: ForkPress can record plugin-scoped findings against a merge run,
mark that run as `completed_with_conflicts`, filter `merge-audit` output with
`scope = plugin`, group plugin findings separately from DB/file findings, and
attach review notes. External validator runners can hand findings back through:

```bash
forkpress branch record-plugin-validator-conflicts \
  --run 123 \
  --findings-file /tmp/forkpress-plugin-findings.json
```

`--findings-json` is available for small fixtures, but real validators should
prefer `--findings-file` so large candidate payloads do not hit shell argument
limits.

ForkPress can also execute one explicit validator command and record its JSON
findings:

```bash
forkpress branch run-plugin-validator \
  --run 123 \
  --validator ./vendor/bin/my-plugin-merge-validator
```

The runner passes merge context through environment variables:
`FORKPRESS_MERGE_METADATA_DB`, `FORKPRESS_MERGE_RUN`,
`FORKPRESS_MERGE_SOURCE_BRANCH`, `FORKPRESS_MERGE_TARGET_BRANCH`,
`FORKPRESS_MERGE_BASE_DB`, `FORKPRESS_MERGE_SOURCE_DB`,
`FORKPRESS_MERGE_TARGET_DB`, `FORKPRESS_MERGE_BASE_ROOT`,
`FORKPRESS_MERGE_SOURCE_ROOT`, and `FORKPRESS_MERGE_TARGET_ROOT`. A validator
may emit either a raw findings array or an object with `status` and `findings`.

The lower-level PHP helper commands remain available for focused fixtures and
runtime integration:

```bash
php scripts/cow/merge.php record-plugin-validator-conflicts \
  --metadata-db .forkpress/cow/merge/metadata.sqlite \
  --run 123 \
  --findings-file /tmp/forkpress-plugin-findings.json

php scripts/cow/merge.php run-plugin-validator \
  --metadata-db .forkpress/cow/merge/metadata.sqlite \
  --run 123 \
  --validator ./vendor/bin/my-plugin-merge-validator
```

Normal branch merges automatically discover validators from the staged
candidate target:

- active plugins may ship `forkpress-merge-validator.php` next to the active
  plugin file's directory, such as
  `wp-content/plugins/my-plugin/forkpress-merge-validator.php`;
- single-file active plugins may ship
  `wp-content/plugins/my-plugin.forkpress-merge-validator.php`;
- mu-plugins may ship `wp-content/mu-plugins/forkpress-merge-validator.php`,
  `wp-content/mu-plugins/*.forkpress-merge-validator.php`, or
  `wp-content/mu-plugins/*/forkpress-merge-validator.php`.

Inactive plugin validators are not run. A normal branch merge can also run one
explicit validator before reporting the merge complete:

```bash
forkpress branch merge feature --into main \
  --plugin-validator ./vendor/bin/my-plugin-merge-validator
```

When this inline validator returns `conflicts`, the merge completes as
`completed_with_conflicts` and records plugin-scoped conflict rows before the
result is reported. When it returns `failed` or exits unsuccessfully, the merge
helper restores the pre-merge target database, metadata database, and target
file tree using the same rollback path as other late merge failures.

Validator status and findings must agree. `valid` must emit no findings, and
`conflicts` must emit at least one finding. Contradictory validator output is
treated as a validator failure so plugin state is not reported with ambiguous
review evidence.

## Review Metadata

Plugin conflicts should be exported by `forkpress branch merge-audit` with:

- `scope = plugin`
- plugin slug/name
- logical object identity
- involved database tables
- involved filesystem paths
- validator version
- base/source/target/candidate payload previews where safe

Review resolution should initially support only target acceptance and
re-audit-after-change. Source application should require a plugin merge driver,
not just a validator.

## Test Shape

Each plugin validator claim needs both:

- A PHP unit test in `tests/cow/merge.php` that builds custom plugin tables,
  JSON/serialized references, options, and files around a deterministic merge.
- A COW E2E test in `tests/cow/e2e.sh` when the invariant depends on real
  WordPress APIs, uploads, block serialization, or runtime plugin hooks.

The first fixture should model a plugin object with:

- one custom parent row
- one custom child row
- one option that embeds both custom IDs
- one `postmeta` JSON value that embeds the custom parent ID
- one uploaded or generated file referenced from the custom row

The expected result is a clean merge when branch ID bands keep both graphs
distinct, and a plugin-scoped review conflict when a graph reference points at
a missing or target-conflicting object.

The clean branch-ID-band case is covered by:

- `tests/cow/merge.php`: deterministic custom-table graph with JSON,
  serialized option/postmeta references, and a referenced file.
- `tests/cow/e2e.sh`: runtime WordPress fixture that creates the same shape
  through branch-local requests before merging.

The PHP unit suite also covers a simulated broken-reference validator finding
for that graph, plugin-scoped audit output, review metadata, automatic
validator discovery from active plugin and mu-plugin locations, inactive
plugin exclusion, automatic validator execution during a normal merge, a
discovered custom-table graph validator that aborts and rolls back a candidate
whose JSON points at a missing child row, and a discovered target-conflict
validator that completes the merge with plugin-scoped review conflicts when a
source graph references target-exclusive plugin state. It also covers a
WordPress media-shaped mu-plugin validator that inspects the candidate target
root and records plugin-scoped conflicts when attachment metadata references
missing original or generated upload files.

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
php scripts/cow/merge.php record-plugin-validator-conflicts \
  --metadata-db .forkpress/cow/merge/metadata.sqlite \
  --run 123 \
  --findings-file /tmp/forkpress-plugin-findings.json
```

`--findings-json` is available for small fixtures, but real validators should
prefer `--findings-file` so large candidate payloads do not hit shell argument
limits.

Runtime validator discovery and execution are still missing.

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
for that graph and verifies plugin-scoped audit output and review metadata.
Real broken-reference and target-conflicting cases still need runtime validator
discovery/execution before they can be checked during an actual merge.

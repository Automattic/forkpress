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
- Read-only paths for the base, source, pre-merge target, and candidate target
  databases.
- Read-only paths for the base, source, pre-merge target, and candidate target
  file trees when file merge context is available.
- The current merge run id and metadata database path for writing findings
  through ForkPress-provided helpers, not through plugin SQL.

A validator returns one of:

- `valid`: the merged candidate preserves this plugin's invariants.
- `conflicts`: the candidate is reviewable; each finding identifies the plugin,
  affected logical object, tables/files/options involved, and a human-readable
  reason. A finding may also include `severity` (`info`, `warning`, `error`,
  or `critical`), `resolution_policy`, `suggested_action`, and
  `manual_review_reason`. If present, `validator` and review guidance fields
  must be non-empty strings.
  ForkPress records these in the conflict payload so review tools can
  prioritize findings and distinguish review-only findings from future
  repairable findings.
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

Validator findings can carry first-class review guidance. For example, a media
validator should mark missing generated upload files as `review-only` instead
of implying that ForkPress may regenerate derivatives during the merge. A
future merge driver can introduce an automatic repair only when it can prove the
repair is deterministic and records the chosen repair in audit metadata.

The current implementation has the metadata/audit foundation for validator
conflicts: ForkPress can record plugin-scoped findings against a merge run,
mark that run as `completed_with_conflicts`, filter `merge-audit` output with
`scope = plugin`, group plugin findings separately from DB/file findings,
summarize plugin conflict queues with `merge-audit --scope plugin --group-by plugin`,
`--group-by plugin-object`, or `--group-by plugin-severity`, and
attach review notes. Plugin audit records expose validator metadata as
structured fields (`plugin`, `plugin_object`, `plugin_tables`,
`plugin_files`, `plugin_validator`, `plugin_severity`,
`plugin_logical_identity`, and review guidance fields) so UI/API consumers do
not need to scrape payload previews.
The same first-class fields are filterable with `merge-audit --plugin <name>`,
`--plugin-object <object>`, and `--plugin-severity <severity>`.
Text audit output prints the same plugin identity, owned table/file, logical
identity, and review-guidance evidence for CLI reviewers.
Validator findings may use either `files` or `paths`; both are normalized into
the audit `plugin_files` field. External validator runners can hand findings
back through:

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
`FORKPRESS_MERGE_TARGET_DB`, `FORKPRESS_MERGE_TARGET_BEFORE_DB`,
`FORKPRESS_MERGE_BASE_ROOT`, `FORKPRESS_MERGE_SOURCE_ROOT`,
`FORKPRESS_MERGE_TARGET_ROOT`, and `FORKPRESS_MERGE_TARGET_BEFORE_ROOT` when
that context exists for the run. A validator may emit either a raw findings
array or an object with `status` and `findings`.

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
- network-active plugins listed in `active_sitewide_plugins` may ship the same
  validator files as active plugins;
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
review evidence. Each finding must include non-empty `plugin`, `object`, and
`reason` fields, and its `type` must use the `plugin-*` namespace. `validator`,
when emitted, must be a non-empty string. Malformed object-shaped findings and
malformed raw finding arrays fail the validator run before any plugin audit
rows are recorded.

## Finding Shape

Findings should describe the semantic object, not just the row that happened
to expose the problem. For example, a WordPress-style comment reference
validator can report a target-edited comment left pointing at a deleted post:

```json
{
  "status": "conflicts",
  "findings": [
    {
      "plugin": "forkpress-wp-comment-refs",
      "object": "comment:122",
      "logical_identity": {
        "kind": "comment",
        "comment_id": 122
      },
      "reason": "comment references a missing post",
      "type": "plugin-wp-comment-missing-post",
      "tables": ["wp_comments", "wp_posts"],
      "validator": "forkpress-wp-comment-refs@1",
      "candidate": {
        "comment_id": 122,
        "field": "comment_post_ID",
        "missing_object_id": 120,
        "object_type": "post"
      }
    }
  ]
}
```

The same pattern is used for `wp_comments.user_id`,
`wp_comments.comment_parent`, and `wp_commentmeta.comment_id`: the candidate
payload names the stale field and the missing WordPress object so review can
decide whether to restore the deleted object, edit the reference, or accept the
deletion.

`object` is the validator's stable review key for replacement evidence across
reruns. `logical_identity` is optional first-class evidence for the plugin's
semantic object identity. When present, it must be non-null, non-empty, and
JSON encodable. Validators should set it when the plugin has a domain identity
that is not captured by SQLite primary keys or schema `UNIQUE` indexes, such
as a slug, UUID, remote object id, or compound plugin key. If a rerun reports
the same `plugin`, `object`, and conflict `type` but changes
`logical_identity`, stale-audit revalidation treats the reviewed finding as
replacement evidence and returns it to the review queue.

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

When a validator rerun changes evidence for a reviewed plugin conflict,
stale-audit revalidation records `replacement-evidence`, links to the newer
validator conflict row, and appends a `revalidation-required` conflict event
with `lifecycle_state = needs-action`. Plugin UI and API surfaces should use
that event stream to show the reviewed -> needs-action transition instead of
inferring state from review-note text.

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
missing original or generated upload files, plus WordPress comment-reference
validators for comments or commentmeta left pointing at deleted posts, users,
parent comments, or comments. Plugin validator reruns also cover changed
source evidence and changed first-class `logical_identity` evidence returning
reviewed findings to `needs-action` as replacement evidence.

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
`--group-by plugin-object`, `--group-by plugin-severity`, or
`--group-by plugin-logical-identity`, or `--group-by plugin-file`, and
attach review notes. Plugin audit records expose validator metadata as
structured fields (`plugin`, `plugin_object`, `plugin_tables`,
`plugin_files`, `plugin_validator`, `plugin_severity`,
`plugin_logical_identity`, `semantic_scope`, and review guidance fields) so
UI/API consumers do not need to scrape payload previews. ForkPress tags
built-in WordPress semantic validators whose plugin id starts with
`forkpress-wp-` as `semantic_scope = wordpress`; other plugin validator
findings default to `semantic_scope = plugin`.
The same first-class fields are filterable with `merge-audit --plugin <name>`,
`--plugin-object <object>`, `--plugin-severity <severity>`,
`--plugin-logical-identity <json>`, `--semantic-scope wordpress|plugin`, and
`--plugin-file <path>`.
Text audit output prints the same plugin identity, owned table/file, logical
identity, and review-guidance evidence for CLI reviewers.
Plugin conflict-event records inherit the same fields, so UI queues can render
review, revalidation, and blocked-resolution events without a second conflict
lookup.
Plugin resolution records also expose those fields from the linked conflict,
even though the resolution payload previews still show the validated/applied
value, and `--records resolutions --group-by plugin`,
`--group-by plugin-object`, `--group-by plugin-severity`, or
`--group-by plugin-logical-identity`, or `--group-by plugin-file` can
summarize resolution queues by that linked plugin evidence.
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

Merge output also reports active plugins that did not ship a discoverable
validator as `unchecked`. This is coverage metadata, not a hard conflict: it
keeps current plugin installs mergeable while making it explicit that ForkPress
only ran generic SQLite/files logic for those plugin graphs.

ForkPress records each unchecked active plugin as a durable
`plugin-validator-unchecked` decision in the merge audit metadata. Reviewers
can list those decisions with:

```bash
forkpress branch merge-audit \
  --scope plugin \
  --records decisions \
  --decision plugin-validator-unchecked
```

The decision payload includes the plugin basename, `coverage: unchecked`, and
review guidance explaining that generic merge rules were used because no
plugin-owned validator was discovered.

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

Plugin driver repairs now have a first-class audit boundary. ForkPress still
does not run generic source/target resolution for plugin validator conflicts;
instead, a plugin-specific driver must repair or validate the plugin-owned
object graph and then record that evidence. ForkPress can run one explicit
driver with merge context and a conflict-context JSON file:

```bash
forkpress branch run-plugin-driver conflict 34 \
  --driver ./vendor/bin/my-plugin-merge-driver \
  --format json
```

The runner sets the same merge context variables as plugin validators, plus
`FORKPRESS_MERGE_CONFLICT_ID`, `FORKPRESS_MERGE_CONFLICT_KEY`,
`FORKPRESS_MERGE_CONFLICT_TYPE`, `FORKPRESS_MERGE_CONFLICT_JSON`,
`FORKPRESS_MERGE_PLUGIN`, and `FORKPRESS_MERGE_PLUGIN_OBJECT`. A driver must
emit a JSON object with `status` (`validated`, `applied`, or `failed`) and a
`result` value. Optional `previous` and `note` fields are recorded as audit
evidence.

`validated` is an observation-only status. ForkPress snapshots the target DB
and target file tree before running the driver; if a driver reports
`validated` but changes either snapshot, the run fails, the mutations are
rolled back, and no `plugin-driver` resolution is recorded. A mutating repair
must report `applied`.

For `applied` repairs, ForkPress reruns discovered plugin validators before it
records the `plugin-driver` resolution. If any validator still reports the same
plugin, conflict type, and object or logical identity, the driver is treated as
not proven: target DB/files are rolled back and no resolution is recorded. This
prevents repairs from closing a semantic plugin conflict merely because a
volatile validator object label changed. Validators may still report different
findings for the same object; those remain reviewable plugin conflicts instead
of silently closing the original one.
If a postflight validator exits unsuccessfully or emits `failed`, the driver
result is also treated as unproven and ForkPress-owned driver execution rolls
back the target DB/files before returning the validator failure.
The ForkPress-owned runner captures the validator list before the driver runs,
so a mutating driver cannot bypass postflight by deleting or deactivating the
validator it was supposed to satisfy. It also fingerprints those discovered
validator files and rejects a mutating driver that rewrites one before
postflight, because postflight validation is only trustworthy when it runs the
same validator code that was active before the repair.

The runner also rejects stale plugin conflicts before executing the driver. If
a later validator rerun has already replaced the conflict evidence, ForkPress
points at the replacement conflict id and requires review of the current
finding instead of recording a driver result against old evidence.

If a driver fails or emits malformed/contradictory output after mutating the
candidate, ForkPress restores the target DB and files from snapshots. If that
rollback itself fails, ForkPress records a rollback-failure row and JSONL
artifact with the original driver failure, rollback failure, and preserved
snapshot locations for manual recovery.

External driver orchestration may also record a result directly:

```bash
forkpress branch record-plugin-driver-resolution conflict 34 \
  --driver ./vendor/bin/my-plugin-merge-driver \
  --result-file /tmp/forkpress-plugin-driver-result.json \
  --applied
```

The command records a `plugin-driver` resolution linked to the original
plugin-scoped conflict, including the driver identity, result payload, reviewer
note, and conflict lifecycle event. `--previous-file` or `--previous-json` may
be supplied when the driver wants to preserve a pre-repair snapshot; otherwise
ForkPress records the original validator finding as the previous payload. The
command is intentionally metadata-only: the driver is responsible for any
plugin-owned database or filesystem edits before it records an applied result.
When `--applied` is passed, ForkPress still reruns discovered plugin validators
before recording the resolution and refuses to close the conflict if the same
finding remains.
The explicit runner provides context and audit recording, but still expects
the plugin driver to own the correctness of any repair it performs.

The WordPress branch switcher can run approved plugin drivers for plugin
conflict rows. It does not accept arbitrary browser-posted driver paths. Active
plugins can expose a driver next to their plugin file:

- directory plugins: `wp-content/plugins/my-plugin/forkpress-merge-driver.php`
- single-file plugins:
  `wp-content/plugins/my-plugin.forkpress-merge-driver.php`
- mu-plugins: `wp-content/mu-plugins/forkpress-merge-driver.php`,
  `wp-content/mu-plugins/*.forkpress-merge-driver.php`, or
  `wp-content/mu-plugins/*/forkpress-merge-driver.php`

Operators may also configure an explicit allowlist with
`FORKPRESS_PLUGIN_MERGE_DRIVERS`:

```json
{
  "my-plugin": "/absolute/path/to/wp-content/plugins/my-plugin/forkpress-merge-driver.php"
}
```

The UI sends only an opaque driver key, the conflict id, and the optional merge
run id. The server maps that key back to the approved PHP script or executable
and invokes:

```bash
forkpress branch run-plugin-driver conflict <id> \
  --driver <approved-driver> \
  --reviewer wordpress-ui \
  --format json
```

After a successful driver run, the UI refreshes the needs-action conflict queue
for the merge run. This keeps plugin repairs behind the same stale-evidence
guard and rollback policy as CLI driver execution.

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
parent comments, or comments. A WooCommerce HPOS-shaped fixture covers a real
plugin graph: order addresses, order metadata, order items, itemmeta, cached
order options, and `_product_id` metadata that must point at
`wp_wc_product_meta_lookup`. Missing order and product lookup rows produce
plugin-scoped review conflicts grouped by WooCommerce logical identity. A
Gravity Forms-shaped fixture covers plugin schema stored in JSON: `wp_gf_entry`
metadata whose field key points at a field removed from
`wp_gf_form_meta.display_meta` stays reviewable and is filterable by form-field
logical identity. Plugin validator reruns also cover changed source evidence
and changed first-class `logical_identity` evidence returning reviewed findings
to `needs-action` as replacement evidence.

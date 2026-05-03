# Row-Level Database COW Design

## Goal

The remote WordPress clone must avoid dumping whole database tables before the
first ordinary edit. For simple primary-key operations, wp-cow treats the remote
database as the lower layer and the local database as the upper layer:

- remote rows are read-only lower data,
- copied or inserted local rows shadow remote rows with the same primary key,
- local tombstones hide remote rows without deleting them remotely,
- ambiguous SQL is not treated as row-level safe.

This is the hard database COW path that lets a large site stay lazy at row
granularity.

## Model Implemented In This Iteration

The Rust row-COW engine has a conservative SQL planner and a fakeable backend
trait. It supports single-table WordPress primary-key operations for these
columns:

- `ID`
- `option_id`
- `umeta_id`
- `meta_id`
- `term_id`
- `term_taxonomy_id`
- `object_id`
- `comment_ID`
- `link_id`

Supported row-level statements:

- `SELECT ... FROM table WHERE pk = value`
- `SELECT ... FROM table WHERE pk IN (...)`
- `UPDATE table SET ... WHERE pk = value` or `pk IN (...)`
- `DELETE FROM table WHERE pk = value` or `pk IN (...)`
- `INSERT INTO table ...` as local-only

`UPDATE` first copies up exactly the requested remote primary keys, excluding
locally tombstoned keys, then the caller runs the update locally. `DELETE`
records local tombstones and deletes any matching local upper rows; it never
sends a write to remote. Row-level `SELECT` merges remote rows, local rows, and
tombstones so deleted remote rows do not reappear and local rows shadow remote
rows.

The production control server exposes `/row-cow`. The generated `wp-content/db.php`
drop-in calls it before the older full-table materialization path. If row-COW
handles a statement, WordPress continues against the local database or receives
the merged result. If a write is not row-level safe, the existing table
promotion/materialization fallback remains the conservative path.

`wp-cow run` also exposes a local MySQL protocol proxy. The generated
`wp-config.php` points `DB_HOST` at this proxy so plugins that bypass `$wpdb`
still go through the COW routing layer. The drop-in itself uses
`WPCOW_LOCAL_DB_HOST` to connect directly to local MariaDB and avoid recursively
calling the proxy.

Promotion is overlay-preserving. Before importing a full remote table, wp-cow
dumps the current local upper rows for that table, imports the remote lower
table, restores the local upper rows, then reapplies tombstones. This keeps
later complex SQL correct after earlier row-level edits: local updates and
inserts survive promotion, and deleted remote rows do not reappear.

## Conservative Fallbacks

The planner returns `PromoteTable` or `Unsupported`, never row-level safe, for:

- joins and multi-table statements,
- non-primary-key writes,
- aggregate reads,
- `DISTINCT`, grouping, ordering, or limiting that cannot be merged safely,
- malformed or ambiguous SQL.

The strict unit harness uses an in-memory fake remote/local backend. It fails if
write-class SQL reaches the fake remote, if update/delete preparation fetches
more than the requested primary keys, if tombstoned remote rows reappear, if
local inserts are sent to remote, or if ambiguous SQL is planned as row-level
safe.

## Out Of Scope

This iteration intentionally does not solve every MySQL/WordPress query shape:

- joins, aggregates, range predicates, secondary-index predicates, subqueries,
  and complex expressions remain full-table-promotion or unsupported cases;
- safe merge support for `ORDER BY` and `LIMIT` is not implemented;
- auto-increment ID allocation for local inserts is still delegated to the
  local database and is not reconciled with the remote lower layer;
- no real remote SiteGround instance is required or touched by the test harness.

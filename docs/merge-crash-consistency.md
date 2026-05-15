# Merge Crash Consistency

Status: reliability map

ForkPress merge touches four durable surfaces:

- WordPress SQLite database
- branch filesystem tree
- ForkPress merge metadata database and rollback artifacts
- Git refs and branch publication metadata

The reliability target is not “nothing can fail”. The target is: after any
failure, ForkPress can identify whether the target branch is still at the old
state, fully at the new state, or in a manual-recovery state with preserved
artifacts. It should not silently report success after a partial merge.

## Current Covered Boundaries

The PHP merge suite covers these rollback classes:

- Target DB transaction failures before commit.
- Metadata transaction failures before commit.
- Metadata commit failures after target DB commit, with target DB restore.
- Target DB restore failures, with rollback-failure metadata and JSONL
  recovery artifacts.
- File transaction failures, with per-path backups and metadata rollback.
- File rollback failures, with preserved filesystem backup artifacts.
- Whole-branch DB plus file rollback after a file-phase failure.
- Late whole-branch rollback after files were applied but metadata finalization
  failed.
- Failed-run metadata write failures, including artifact-only fallback.
- ID-band allocation rollback across target DB, metadata DB, and recovery
  artifacts.
- Process death immediately after the target DB commit but before metadata
  commit, with a durable crash-recovery artifact that points at the pre-merge
  target DB snapshot, proves the run was not falsely marked completed, and can
  be inspected/restored through `recover-crash --restore-target-db`.
- Process death immediately after an individual filesystem operation but before
  filesystem metadata commit, with a durable crash-recovery artifact that points
  at the staged filesystem transaction and can be restored through
  `recover-crash --restore-files`.
- A subsequent merge against metadata with pending crash-recovery artifacts is
  rejected before DB or file mutation, forcing the operator to inspect and
  restore the pending recovery state first. The product-level entry point is
  `forkpress branch recover-crash`; the lower-level PHP helper remains available
  as `recover-crash` for focused test fixtures.

The Git server suite covers these publication classes:

- Git-created branch publication allocates ID bands, captures DB merge base,
  captures file merge base, and captures row identities.
- Git-created branch publication failure removes branch storage and file-base
  artifacts.
- Process exit before Git-created branch metadata capture does not publish a
  visible branch without birth metadata; the next Git apply can recreate the
  branch from the pushed ref and publish it with metadata.
- Process exit after Git-created branch metadata capture but before tree
  publication can leave unpublished birth metadata; a retry clears those stale
  branch-birth artifacts before publishing the branch from the pushed ref.
- Process exit after Git-created storage publication but before public branch
  linking can leave orphan storage; a retry removes the unpublished storage and
  stale birth artifacts, then recreates and links the branch from the pushed ref.
- Git-created branch metadata publication failure after ID-band and row-identity
  capture removes branch storage, merge-base artifacts, and merge metadata.
- Git-created branch-list publication failure after the list write removes
  branch storage, DB merge base artifacts, file-base artifacts, merge metadata,
  and restores the branch list.
- Process exit immediately after Git-created branch-list publication leaves the
  created branch visible with DB/file merge bases, ID-band metadata, and row
  identity metadata already finalized.
- Process exit after Git-created branch metadata capture but before branch-list
  publication can leave `branches.txt` stale; the next Git apply refreshes the
  branch list from the durable branch tree while preserving the finalized DB/file
  bases, ID-band metadata, and row identity metadata.
- Process exit after an existing Git branch update publishes its staged tree can
  leave an old update backup; the next successful Git apply keeps the published
  branch state and removes stale update artifacts for valid branch storage.
- Process exit after staging a Git branch deletion can leave stale delete
  backups and a stale branch-list entry; the next Git apply keeps the branch
  deleted, reconciles the branch list, and removes stale delete artifacts.
- Multi-branch Git-created ID-band metadata failure rolls back created branch
  metadata and merge-base artifacts.
- Stale-source Git-created branch publication is rejected.

## Missing Fault Injection

The remaining work is finer-grained failure injection around process death or
OS-level interruption, not just deliberate exceptions:

- Kill after target DB commit but before metadata commit is covered for the
  direct DB merge path; the remaining work is to extend the same process-death
  harness to full DB+file+Git branch publication.
- Kill after metadata commit but before file publish.
- Kill after one file publish but before later file publishes is covered for
  direct filesystem merge rollback; the remaining work is to extend the same
  recovery model to full branch publication after DB+file completion.
- Kill after file publish but before Git ref update.
- Kill after Git ref update but before branch list update.
- Kill during public branch symlink/tree publication after storage/link state is
  partially visible in ways not yet covered by the separate-storage retry test.
- Kill during sparsebundle detach or compact.
- Kill during cleanup/pruning of rollback artifacts.

These should be tested by an external harness that can terminate the process at
named checkpoints and then run a recovery/audit command in a new process.

## Checkpoint Model

Add named failpoints around durable boundaries:

- `before-target-db-commit`
- `after-target-db-commit`
- `before-metadata-commit`
- `after-metadata-commit`
- `before-file-op`
- `after-file-op`
- `before-git-ref-update`
- `after-git-ref-update`
- `before-branch-list-update`
- `after-branch-list-update`
- `before-cleanup`
- `after-cleanup`

Failpoints should be disabled in production unless an explicit test-only
environment variable is set. They should be deterministic: either exit the
process or throw before the operation, never sleep or race.

## Recovery Expectations

Each crash test should assert one of:

- Target branch equals the pre-merge snapshot.
- Target branch equals the fully merged state and metadata says completed.
- Target branch is marked failed with rollback artifacts sufficient for manual
  recovery.

Any state that has changed target content, no completed run, and no recovery
artifact is a release blocker.

If a crash-recovery artifact is present, new merges using the same metadata DB
must fail before mutation until `forkpress branch recover-crash` has inspected
and restored the pending DB snapshot and/or filesystem transaction.

## Test Shape

The first external crash harness should:

1. Create a branch with one DB change and one filesystem change.
2. Run merge with one failpoint enabled.
3. Start a new process and inspect target DB, target files, metadata, and
   rollback artifacts.
4. Repeat for every named checkpoint.

This is intentionally separate from `tests/cow/merge.php`, because a PHP unit
test cannot simulate process death after the interpreter or SQLite has flushed
only part of the state.

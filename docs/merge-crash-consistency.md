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
- Plugin-driver failure after a mutating driver returns but before the
  `plugin-driver` resolution metadata is recorded, with target DB/files restored
  from runner snapshots and no resolution row recorded.
- Plugin-driver process death after a mutating driver returns but before the
  `plugin-driver` resolution metadata is recorded, with a durable crash-recovery
  artifact that blocks retries, preserves target DB and filesystem snapshots,
  and restores both through `recover-crash --restore-target-db --restore-files`.
- Failed-run metadata write failures, including artifact-only fallback.
- ID-band allocation rollback across target DB, metadata DB, and recovery
  artifacts.
- Process death immediately before or after the target DB commit but before
  metadata commit, with a durable crash-recovery artifact that points at the
  pre-merge target DB snapshot, proves the run was not falsely marked
  completed, blocks retries while pending, and can be inspected/restored
  through `recover-crash --restore-target-db`.
- Process death immediately after an individual filesystem operation but before
  filesystem metadata commit, with durable crash-recovery artifacts that point
  at both the staged filesystem transaction and the whole-branch pre-merge DB,
  metadata, and filesystem snapshots. File-only recovery can use
  `recover-crash --restore-files`; whole-branch recovery uses
  `recover-crash --restore-target-db --restore-files`.
- Process death after the DB phase of a DB+file merge but before the first file
  operation, with a durable whole-branch crash-recovery artifact that points at
  the pre-merge target DB, metadata DB, and filesystem-root snapshots and can be
  restored through `recover-crash --restore-target-db --restore-files`.
- A subsequent merge against metadata with pending crash-recovery artifacts is
  rejected before DB or file mutation, forcing the operator to inspect and
  restore the pending recovery state first. The product-level entry point is
  `forkpress branch recover-crash`; the lower-level PHP helper remains available
  as `recover-crash` for focused test fixtures.
- Pending crash-recovery artifacts are also visible through
  `merge-audit --records crash-recovery`, so review and branch UI surfaces can
  detect a blocked recovery state without attempting another merge or driver
  command first.
- The WordPress branch UI and runtime router expose a restore action for pending
  crash recovery, using `forkpress branch recover-crash --restore-target-db
  --restore-files` instead of sending the request through WordPress.
- Process exit after crash recovery restores a target DB or filesystem
  transaction but before recovery artifact cleanup leaves the artifact and
  rollback material retryable; a second recovery removes the artifact and
  cleanup material after confirming the target is restored.
- The product E2E suite drives `before-target-db-commit` through the public
  `forkpress branch merge` command, verifies `forkpress branch recover-crash`
  reports the pending artifact, verifies a second public merge is blocked while
  recovery is pending, restores the target DB through the public recovery
  command, and reruns the public merge successfully.
- The product E2E suite drives `before-metadata-commit` through public
  `forkpress branch merge`, verifying that a DB-durable but metadata-incomplete
  public merge is reported, blocks retries, restores through public recovery,
  and can be retried.
- The product E2E suite drives `before-file-op` through public
  `forkpress branch merge`, verifying that a DB-complete but pre-filesystem
  public merge is reported, blocks retries, restores DB and files through
  public recovery, and can be retried.
- The product E2E suite drives `after-crash-recovery-restore` through public
  `forkpress branch recover-crash`, verifying that an interrupted recovery
  cleanup remains retryable and a second public recovery clears the pending
  artifact.
- The product E2E suite also drives `after-file-op` through public
  `forkpress branch merge`, verifies the pending filesystem crash recovery
  blocks retries, restores both DB and files through public recovery, and
  reruns the public merge successfully.

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
  linking can leave orphan storage; mount-backed file-view startup relinks the
  finalized storage branch into the public branch directory, refreshes
  `branches.txt`, and preserves finalized birth metadata.
- Process exit after separate-storage Git-created public branch linking but
  before branch-list publication can leave a visible public symlink and stale
  branch list; the next Git apply reconciles the branch list while preserving
  finalized DB/file bases, ID-band metadata, and row identity metadata.
- Git-created branch metadata publication failure after ID-band and row-identity
  capture removes branch storage, merge-base artifacts, and merge metadata.
- Git-created branch-list publication failure after the list write removes
  branch storage, DB merge base artifacts, file-base artifacts, merge metadata,
  and restores the branch list.
- Process exit immediately after Git-created branch-list publication leaves the
  created branch visible with DB/file merge bases, ID-band metadata, and row
  identity metadata already finalized; a retry reconciles the Git ref to the
  finalized branch DB snapshot, keeps the branch list and branch-birth metadata
  stable, and subsequent retries keep the recovered ref stable.
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
- Process exit during COW Git unreachable-object pruning may leave some
  unreachable objects behind, but reachable branch objects are preserved and the
  next prune removes the remaining unreachable objects.
- Multi-branch Git-created ID-band metadata failure rolls back created branch
  metadata, branch-birth decision rows, DB merge-base SQLite sidecars, and
  file-base artifacts.
- Stale-source Git-created branch publication is rejected.
- Normal branch creation removes stale unpublished merge-base files, temporary
  merge-base capture artifacts, and branch-birth metadata for the requested
  branch before allocating fresh birth metadata, so a retry after an
  interrupted create cannot inherit stale ID bands or merge bases. It also
  clears stale pending-reset markers for deleted/recreated branches.
- The product E2E suite drives a public `forkpress branch create` exit after
  branch-birth metadata is captured but before publication, then retries the
  same public branch creation and verifies fresh DB/file merge bases and ID-band
  metadata.
- Branch reset writes a pending-reset marker before publishing replacement
  branch contents and clears it only after merge-base, file-base, ID-band, row
  identity, and Git-ref metadata are finalized. Public branch reuse and merge
  refuse branches with an unfinished reset marker so a hard kill in the reset
  publication window cannot silently merge with stale branch-birth metadata.
- The product E2E suite drives a public `forkpress branch reset` exit after the
  replacement branch is published but before reset metadata finalization,
  verifies public merge is blocked by the pending-reset marker, then reruns the
  public reset and verifies fresh DB/file merge bases and ID-band metadata.
- The product E2E suite drives actual smart-HTTP Git pushes for Git-created
  branches with the server exiting before branch-birth metadata, after
  branch-birth metadata but before branch tree publication, after storage
  publication but before public branch linking, before branch-list publication,
  and immediately after branch-list publication. The pre-metadata crash restarts
  ForkPress in a fresh process, verifies the branch is not visible and has no
  ID-band or row-identity metadata, then retries the push, verifies stale temp
  paths are cleaned, and merges it into `main`. The post-metadata crash restarts
  ForkPress in a fresh process, verifies the branch is not visible before the
  branch tree is published, verifies DB/file merge bases plus finalized ID-band
  and row-identity metadata survived, retries the push, and merges it into
  `main`. The storage-publication crash restarts ForkPress in a fresh process,
  verifies mount-backed storage relinked the branch, verifies DB/file merge
  bases plus finalized ID-band and row-identity metadata, and merges it into
  `main`. The pre-list crash restarts ForkPress in a fresh process, verifies the
  branch tree and DB/file bases are visible, verifies `branches.txt` has been
  reconciled by restart, verifies finalized ID-band and row-identity metadata,
  and merges it into `main`. The post-list crash restarts ForkPress in a fresh
  process, then verifies the branch is visible, has DB/file merge-base
  artifacts, has a matching Git ref, and can merge into `main`.

## Missing Fault Injection

The remaining work is a broader product-level kill harness for entry points that
are not yet covered by the targeted public CLI failpoint tests. The public E2E
suite already kills `forkpress branch merge`, `forkpress branch create`,
`forkpress branch reset`, and `forkpress branch recover-crash` at representative
durable boundaries. The lower-level PHP/Git suites cover additional internals,
including merge subprocess death before/after target DB commit, before metadata
commit, before the file phase, after an individual file operation, and during
crash-recovery cleanup; plus Git-created branch publication before metadata
capture, after metadata capture, after storage publish, after public-link
creation, before/after branch-list publication, after existing-branch update
publish, after branch-delete staging, and after object pruning.

The remaining release-hardening work is:

- Broaden actual Git push failpoint coverage beyond the representative
  Git-created pre-metadata, post-metadata, storage-publication, pre-branch-list,
  and post-branch-list checkpoints, then restart in a new process and verify the
  public audit/recovery commands report the same state as the lower-level
  harnesses for remaining public-link, existing-update, delete, and object-prune
  checkpoints.
- Add platform-specific kill coverage around APFS sparsebundle detach/compact.
- Add kill coverage around cleanup of rollback artifacts outside the Git
  object-pruning and crash-recovery restore paths.
- Assert for each product-level checkpoint that the target branch is either the
  pre-merge snapshot, the fully completed merged state, or a blocked
  manual-recovery state with durable artifacts.

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

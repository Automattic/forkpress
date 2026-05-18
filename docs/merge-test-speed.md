# Merge Test Speed

ForkPress merge changes should get local proof before waiting on full e2e and
release jobs. The default fast path is:

```bash
make test-cow-changed
```

That command compares the current change set to `origin/trunk`, adds staged and
unstaged edits, and runs the smallest mapped COW gate it can infer from the
changed files and `scripts/cow/merge.php` diff. It always runs `git diff
--check` and PHP syntax checks for changed PHP files.

Use a different base when reviewing a stacked branch:

```bash
FORKPRESS_TEST_BASE=trunk make test-cow-changed
```

To see the plan without running it:

```bash
scripts/dev/cow-changed-test-plan.sh --list
```

For local iteration, run the independent checks in parallel:

```bash
scripts/dev/cow-changed-test-plan.sh --jobs 4
```

The same setting is available as `FORKPRESS_CHANGED_TEST_PLAN_JOBS=4`, which is
useful when calling `make test-cow-changed`. Keep `--jobs 1` when debugging
interleaved failures or when the machine is already CPU-bound.

CI uses the same planner as a COW/PHP preflight before the expensive Linux,
macOS, and Windows COW jobs. That CI scope intentionally avoids Rust and release
packaging checks so the preflight remains a fast fail gate, while local runs keep
the broader mappings by default.

The full release-verification matrix is reserved for binary, packaging, runtime
bundle, release-script, installer, dependency, and workflow changes. Pure
`scripts/cow/**` merge-logic PRs rely on the changed-file COW preflight plus the
platform COW jobs instead of also waiting for duplicated release bundle builds.

This is an iteration gate, not a release gate. Run broader checks before asking
for review when a change crosses subsystem boundaries:

```bash
make test-cow-fast
php tests/cow/merge.php
```

Full e2e and release verification still belong at integration points: before
merging user-facing runtime changes, before release tags, and after build or
packaging changes. Pull-request workflows cancel superseded runs for the same PR
so a follow-up push does not leave obsolete mac/Linux jobs consuming runners.

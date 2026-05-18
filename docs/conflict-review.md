# Conflict Review

ForkPress merge conflicts are audit records, not free-form notes. Each conflict
has a stable id, a lifecycle state, a next action, and a set of legal
resolution choices. Use those fields to decide whether to review, revalidate,
resolve, or leave a conflict for manual repair.

## Find History And Branch Edges

Use history when you want chronological merge runs:

```bash
forkpress branch history --limit 20
forkpress branch history --limit 20 --format json
```

Use tree when you want recent source-to-target branch topology:

```bash
forkpress branch tree --limit 20
forkpress branch tree --limit 20 --format json
```

In wp-admin, open the ForkPress branch manager page. **Show merge history**
loads recent runs, **Show branch tree** groups recent edges by target branch,
and conflicted history rows can load the conflict queue for that run.

## Inspect Conflict Queues

Start with the conflicts for one run:

```bash
forkpress branch conflicts --run 42
forkpress branch conflicts --run 42 --format json
```

Useful queue filters:

```bash
forkpress branch conflicts --run 42 --lifecycle-state needs-action
forkpress branch conflicts --run 42 --next-action revalidate
forkpress branch conflicts --run 42 --resolution-choice source
forkpress branch conflicts --run 42 --blocked-resolution-choice source
forkpress branch merge-audit --records conflict-events --run 42 --group-by event-type
```

`resolution_choices` says what can be applied. `blocked_resolution_choices`
says what is visible but not executable yet. `next_action` is the safest UI
hint: review, revalidate, resolve, apply a reviewed choice, run a plugin driver,
or leave the conflict for manual review.

## Review And Resolve

Mark a conflict as reviewed when a human has inspected it but should not apply a
new choice yet:

```bash
forkpress branch merge-review conflict 7 --status reviewed --note "Reviewed page metadata"
forkpress branch merge-review conflict-key wp_posts:page:about --run 42 --status reviewed --note "Reviewed page metadata"
```

Apply source or target only when the audit payload advertises that choice:

```bash
forkpress branch merge-resolve conflict 7 --choice source --apply
forkpress branch merge-resolve conflict 7 --choice target --apply
```

If a choice was already validated and is still current, apply the reviewed
choice:

```bash
forkpress branch merge-resolve conflict 7 --apply-reviewed
forkpress branch merge-apply-reviewed --run 42 --reviewer alice --format json
```

The wp-admin branch manager exposes the same review and resolution actions for
loaded conflict rows. It only shows source, target, and apply-reviewed buttons
when the conflict audit payload says those actions are legal.

## Handle Stale Reviews

If the target branch changed after review, a direct resolve can fail. Revalidate
the run and then inspect the queues again:

```bash
forkpress branch merge-audit --revalidate --run 42 --reviewer alice --format json
forkpress branch conflicts --run 42 --group-by latest-revalidation-status
```

Compatible stale database, filesystem, and supported schema conflicts can be
applied with an after-revalidate guard:

```bash
forkpress branch merge-resolve conflict 7 --choice source --apply --after-revalidate
```

Incompatible or unclassified drift stays in `needs-action` until a reviewer,
plugin validator, plugin driver, or schema planner can prove a safe repair.

## Plugin Conflicts

Plugin-owned conflicts are not resolved by generic source/target choice unless
the plugin contract allows it. Prefer plugin validators first, then plugin
drivers for repairs that can prove the resulting plugin state is coherent:

```bash
forkpress branch run-plugin-validator --run 42 --validator wp-content/plugins/acme/forkpress-merge-validator.php
forkpress branch run-plugin-driver conflict 7 --driver wp-content/plugins/acme/forkpress-merge-driver.php --format json
```

Plugin conflicts can also be filtered by plugin identity:

```bash
forkpress branch conflicts --run 42 --scope plugin
forkpress branch conflicts --run 42 --plugin acme-events
forkpress branch conflicts --run 42 --group-by plugin-object
```

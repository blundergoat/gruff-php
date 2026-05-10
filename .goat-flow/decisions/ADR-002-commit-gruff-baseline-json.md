# ADR-002: Commit gruff-baseline.json

**Status:** Accepted
**Date:** 2026-05-11
**Ticket/Context:** `.gitignore` review thread

## Context

`gruff-baseline.json` is the default static finding baseline for `gruff analyse` (see `.goat-flow/architecture.md`: "Static finding baselines default to `gruff-baseline.json` at the project root"). When `--generate-baseline` is run it writes the file; when `--baseline` is passed bare or omitted entirely, gruff auto-loads it; `--no-baseline` opts a single run out.

A gitignore review surfaced the question of whether this file should be tracked in version control. Two policies are coherent:

1. **Committed.** The team and CI share one baseline. Adding or removing an ignored finding becomes a reviewable diff. CI fails identically on every machine.
2. **Gitignored.** Each developer maintains their own local baseline. CI either ignores the concept or reads a baseline written by some other process.

Policy #2 produces split-brain failure modes: a developer's local run is green because their baseline silences a finding, CI is red because its baseline does not, and there is no shared file to diff to find the difference. Worse, deleting a rule's findings via baseline becomes invisible — there is no commit recording who decided that finding was acceptable.

Policy #1 makes the baseline a first-class team contract. A new finding the team agrees to defer is added to `gruff-baseline.json` in a PR with reviewers; a finding that is fixed disappears from the baseline in the same PR that fixes it.

## Decision

`gruff-baseline.json` is committed to the repository. It is **not** added to `.gitignore`. The file is the shared source of truth for which gruff findings the team and CI accept as currently ignored.

Rules for changes to the file:

- A finding is added to `gruff-baseline.json` only via a reviewable PR. The PR description must say why the finding is being deferred rather than fixed.
- A finding is removed from `gruff-baseline.json` in the same PR that resolves the underlying problem (or in the PR that proves the rule mis-fires and is being narrowed).
- The file is regenerated with `gruff analyse --generate-baseline` only when wholesale re-baselining is intentional and recorded in the PR description.
- CI runs `gruff analyse` with the committed baseline applied (the default). Local developer runs do the same unless the developer is debugging baseline behaviour, in which case `--no-baseline` is the explicit opt-out.

`auth.json` and `infection-report.json` remain gitignored because they are credential or generated-artifact files, not team contracts.

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| Commit `gruff-baseline.json` | Adds review burden when a finding is deferred; the diff is sometimes large after a sweeping rule change | Accepted because review burden is the correct cost of accepting a finding as known-ignored, and large diffs are themselves useful signals |
| Gitignore `gruff-baseline.json` | Local and CI baselines drift; "works on my machine" green/red splits become silent; no audit trail of which findings were accepted by whom | Rejected because shared CI gating without a shared baseline is incoherent |
| Commit but never enforce updates via PR review | The file accumulates stale entries that hide real regressions | Mitigated by the rule that baseline removal is part of the PR that fixes the underlying finding, and by periodic baseline regeneration as a deliberate act |
| Use a per-developer override file alongside a committed shared baseline | Two-baseline merge logic adds complexity to gruff itself for a use case the team has not asked for | Rejected for v0.1; revisit if a real workflow demands it |

## Reversibility

Two-way door. If the committed-baseline policy turns out to cause more friction than benefit (e.g. the file thrashes on every run because of nondeterministic line numbers, or merge conflicts dominate), the reversal path is:

1. Add `/gruff-baseline.json` to `.gitignore`.
2. Decide and document the replacement workflow (per-developer baseline, CI-generated baseline, or no baseline at all).
3. Supersede this ADR with a new one citing the evidence that forced the change.

Reversal cost is low because the file is small JSON, the tool already supports `--no-baseline`, and no other system depends on the committed copy.

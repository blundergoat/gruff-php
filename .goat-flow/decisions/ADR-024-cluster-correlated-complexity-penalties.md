# ADR-024: Cluster correlated size/complexity penalties

**Status:** Accepted
**Date:** 2026-05-31
**Author(s):** Matthew Hansen
**Builds on:** ADR-023 (retired the synthetic `design.god-method` composite).

## Context

The cross-port design principle P5 says: when several findings describe one root
cause — a method that is long *and* deeply nested *and* cyclomatically complex —
score it once, while still listing every finding in the report. Billing one
root cause four times distorts the grade and tells the agent the file is four
times worse than it is, pushing disproportionate rewrites for a single problem.

`ScoreCalculator` did not cluster. `pillarScores()` and `fileScores()` each
summed `penaltyFor()` over their findings independently, so a single over-large
method subtracted a separate penalty for every size and complexity finding it
produced — once in the Size pillar, again (twice or three times) in the
Complexity pillar, and the full stack again in its file score. ADR-023 retired
the `design.god-method` composite that used to *add* a third pillar's penalty on
top of that, but retiring the composite alone left the underlying size and
complexity findings still double- and triple-counting. Closing P5 requires
clustering those real findings, not just removing the synthetic one.

The sibling ports already converged on the same mechanism: gruff-ts (ADR-009)
and gruff-py (ADR-016) group findings that share a `file + symbol + line` and let
the group contribute a single penalty while keeping every finding visible.
gruff-py's `_finding_penalties` (penalty = `max(member) / len(group)`) is the
reference this port mirrors for cross-port parity.

## Decision

Cluster correlated complexity/size penalties in `ScoreCalculator`, keeping every
finding in the report.

- Group scored findings by `(file, symbol, line)` — the same key tuple the
  fingerprint uses — but only when the finding's rule is in the correlated set
  `{complexity.cognitive, complexity.cyclomatic, complexity.nesting-depth,
  size.method-length, size.parameter-count}`. A finding with no symbol or no line
  never clusters.
- A cluster of two or more contributes one shared weight per member:
  `max(member base penalty) / member count`. The largest symptom sets the bill;
  the weaker overlapping symptoms divide into it rather than stacking on top.
- Lone findings, and any rule outside the correlated set (naming, docs, security,
  …), keep their full base penalty even when they land on the same symbol.
- The shared weight follows each finding into both the pillar and the file
  penalty buckets, so the two views agree.
- Every finding stays in the detailed report and in its pillar's finding count;
  only the scoring weight is divided. The report's score explanation now states
  that correlated findings on one symbol share a single penalty.

The correlated set deliberately omits `design.god-method`: it was retired in
ADR-023 and emits nothing to cluster.

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| Keep summing every finding independently | One god-method is billed up to four times; the grade says the file is far worse than it is and the agent over-rewrites one root cause. | Rejected. This is the confirmed P5 gap. |
| Re-add a composite that carries a neutral score | Reintroduces the non-registry finding ADR-023 removed and its bespoke scoring branch, for no remediation value. | Rejected. The composite was the disguise; clustering is the mechanism. |
| Drop all but one finding per cluster from the report | Loses the per-symptom detail (which of length / nesting / branching is worst) the agent needs to fix the right thing. | Rejected. P5 requires keeping every finding visible. |
| Cluster by `file + symbol + line`, one max/count penalty, keep all findings | Two findings on different lines of one method do not cluster (acceptable: they are distinct sites). | Accepted. Mirrors gruff-py/gruff-ts; one root cause, one penalty, full detail. |

## Consequences

- Composite and pillar grades rise for files that previously double- or
  triple-counted an over-large method; a file with no co-located cluster scores
  exactly as before. Scores are still deterministic.
- Findings, fingerprints, and the `gruff.analysis.v2` / `gruff.baseline.v1`
  schemas are unchanged: clustering changes only penalty weighting, never the
  finding set or its identities, so baselines keep matching by fingerprint.
- The score `explanation` string changes to describe the clustering; reporters
  that surface it (text, JSON, HTML) show the new wording.
- The correlated set lives in `ScoreCalculator` as a literal, matching the file's
  existing convention of referencing rule ids by string. Adding a rule to the set
  is a one-line change with a test.

## Reversibility

Two-way door. The clustering is contained to `ScoreCalculator`; reverting to
independent summation restores the prior scores without touching findings,
schemas, or baselines. Changing the penalty formula (e.g. away from `max/count`)
would be a scoring change worth its own note for cross-port parity.

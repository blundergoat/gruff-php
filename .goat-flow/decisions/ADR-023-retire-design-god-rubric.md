# ADR-023: Retire `design.god-method`

**Status:** Accepted
**Date:** 2026-05-31
**Author(s):** Matthew Hansen
**Supersedes scope of:** ADR-018 only where it preserved the `design.god-method`
trigger after removing `complexity.npath`.

## Context

`design.god-method` was a synthetic finding emitted outside `RuleRegistry` by
`src/Scoring/CompositeFindingFactory.php` when size and complexity findings
overlapped on the same method/function symbol. It carried `Pillar::Design` and
`metadata.componentRules`, then `ScoreCalculator` had a registry-missing special
case so `excludeFromScore` could be inherited from the component rules.

ADR-018 narrowed the trigger to `{complexity.cognitive, complexity.cyclomatic,
complexity.nesting-depth}` after retiring `complexity.npath`. That kept the
synthetic rubric alive, but the surviving component findings already name the
actionable problems: too much size, too much cognitive/cyclomatic complexity, or
too much nesting. The synthetic design label adds a second finding and a scoring
branch without adding a remediation path that is not already implied by the
component findings.

## Decision

Retire `design.god-method` completely in 0.3.0.

- Delete the synthetic emission path instead of keeping an opt-in or warning-only
  version.
- Keep the underlying size and complexity findings visible and scored through their
  native pillars.
- Keep `design.single-implementor-interface`; this decision only removes the
  synthetic `design.god-*` rubric family.
- Remove `metadata.componentRules` scoring inheritance once no live synthetic
  component-rule finding remains.

This is a breaking rule-id retirement even though the rule was not registry-backed.
Users with stale `design.god-method` entries in `gruff-baseline.json` should remove
those entries or regenerate the baseline after reviewing the diff.

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| Keep `design.god-method` | One root cause can appear as size, complexity, and synthetic design findings; the synthetic finding needs custom scoring and docs despite no unique remediation. | Rejected. Duplicate abstraction is not worth the maintenance surface. |
| Make it scoring-only | Hides the visible rule id but keeps a hidden coupling between unrelated pillars and still requires special scoring behavior. | Rejected. If the signal is duplicate, remove it rather than making it implicit. |
| Demote or disable by default | Keeps a dormant non-registry rule id and the `componentRules` scoring branch for a finding most users will not need. | Rejected. Same maintenance problem with less visible value. |
| Delete the synthetic rubric | Users lose the roll-up label, but still see every actionable size and complexity component finding. | Accepted. Smallest surface and clearest report. |

## Consequences

- `src/Scoring/CompositeFindingFactory.php` and its `analyse`, `summary`, and
  branch-review call sites are removed.
- Reports no longer emit `design.god-method`; baselines containing it become stale
  debt records to remove.
- `ScoreCalculator` no longer needs registry-missing `metadata.componentRules`
  inheritance for `excludeFromScore`.
- The design pillar remains via `design.single-implementor-interface`.
- Registry counts do not change because `design.god-method` was never
  registry-backed.

## Reversibility

Two-way door before 1.0, but reversing requires a new ADR because it reintroduces a
non-registry finding path. A future design roll-up should be implemented as a normal
registry-backed rule or as a documented report aggregation, not by reviving the old
synthetic finding unchanged.

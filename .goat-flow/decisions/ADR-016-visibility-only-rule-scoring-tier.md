# ADR-016: Visibility-Only Rule Scoring Tier (excludeFromScore)

**Status:** Accepted
**Date:** 2026-05-27
**Ticket/Context:** Reviewer report section 13a (`.goat-flow/scratchpad/gruff-php-improvement-feedback.md`) flagged the gap between "rule is informational" and "rule should run at all". The only escape valve today is `enabled: false`, which loses visibility entirely. The composite score is dominated by a few high-volume rules even when the team has decided those rules' findings should not penalise the grade.

## Decision

Introduce a per-rule `excludeFromScore: bool` config key, default `false`. When set to `true`:

- The rule still runs.
- Its findings still appear in every report (text, JSON, HTML, Markdown, GitHub, hotspot, SARIF).
- Its findings do NOT contribute to the composite or pillar penalty bucket.

The key lives under `rules.<rule-id>.excludeFromScore` alongside the existing `enabled / threshold / severity / thresholds / options` keys. The validator hard-errors on non-boolean values. Field is exposed via `RuleSettings::isExcludedFromScore()` and consumed by `ScoreCalculator::calculate(... analysisConfig: $config)` which filters findings before pillar / file score accumulation.

`enabled: false` and `excludeFromScore: true` remain distinct:

| State                            | Rule runs? | Findings in reports? | Findings affect score? |
| -------------------------------- | ---------- | -------------------- | ---------------------- |
| `enabled: true` (default)        | yes        | yes                  | yes                    |
| `enabled: true, excludeFromScore: true` | yes  | yes                  | no                     |
| `enabled: false`                 | no         | no                   | no                     |

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| ------ | ---------- | ------------------------ |
| A. Rename `severity` to include a `disabled` case (`severity: disabled`) | Conflates two orthogonal axes: severity describes a finding's strength, scoring describes how it weighs the composite. Overloading the severity vocab muddies both for every consumer. | Rejected. |
| B. Add a CLI flag `--exclude-score-rule=<id>` for ephemeral exclusion | Per-rule config is the right surface; ephemeral overrides belong in `.gruff-php.yaml` so they ride alongside the rest of the analysis policy. Flag-based overrides also don't compose with baselines. | Rejected. |
| C. Per-finding scoring exclusion | That is what baselines are for. Granularity at the finding level adds a second escape hatch with overlapping semantics. | Rejected. |
| D. Differentiated penalty weights ("count this rule at 25%") | Adds a tuning dial with no concrete evidence anyone needs partial weighting. Two states (penalised / not) cover the requested workflow. | Rejected. |
| E. New top-level `scoring.excludeRules: [...]` list block | Splits per-rule state across two surfaces (`rules.<id>` for everything else, `scoring.excludeRules` for this one). Future "per-rule scoring metadata" would face the same fork. | Rejected. |
| F. **`rules.<id>.excludeFromScore: bool`** — new bool key alongside existing per-rule keys | One surface, additive (default false matches today's behaviour), no rename of any existing key, no flag plumbing. Two states cover the requested workflow. Rule still runs so findings are visible in every reporter without further changes. | **Accepted.** |

## Reversibility

**Two-way door.** Removing the field defaults every rule back to scored. No baseline file or SARIF consumer reads `excludeFromScore`; it lives purely in the project's `.gruff-php.yaml` and the per-rule resolved settings. Reverting the milestone means deleting the field on `RuleSettings`, the validator branch on `RuleConfigApplier`, and the filter call in `ScoreCalculator`; existing configs with the key set would fail to load with "Unknown config key" until users remove the line.

**Revisit trigger:** if differentiated penalty weighting (option D above) gains concrete evidence (a project that wants to "soften" a rule rather than fully exclude it), revisit; the current binary state can extend to a weight without changing the config-key name.

## Consequences

- `RuleSettings` gains a `excludeFromScore` bool field and `isExcludedFromScore()` accessor.
- `RuleConfigApplier::assertKnownRuleKeys()` accepts the new key; `excludeFromScore()` validates strict boolean.
- `ScoreCalculator::calculate()` accepts an optional `?AnalysisConfig $analysisConfig = null`. When set, findings from `excludeFromScore: true` rules are filtered before pillar and file score accumulation.
- All three current `ScoreCalculator::calculate()` call sites in `AnalyseCommand` and `SummaryCommand` pass the active `AnalysisConfig`. The branch-review base-tree score also passes the active config, so the comparator's `deltaScore` reflects the same exclusion shape across base and current.
- Reports continue to surface every finding the rule produced; the exclusion is exclusively a scoring contract.
- `.gruff-php.yaml` consumers who never set the field see no behaviour change.

## Out of Scope (Deferred)

- Per-finding scoring exclusion. Baselines handle this concern.
- A CLI `--exclude-score-rule` flag.
- Differentiated penalty weights ("count this rule at 25%").
- Auto-surfacing all `excludeFromScore: true` rules in a dedicated "informational" section of reports. The per-finding rule id is already visible; a dedicated section is polish that needs concrete evidence.
- Class-level inline suppression (the healthkit `BookingSession` problem). `excludeFromScore` is the wrong tool for that need — the user wants the warning visible AND acknowledged at the call site, not silenced from the score. A class-level attribute / annotation is a separate design with its own ADR.

## References

- Reviewer report: `.goat-flow/scratchpad/gruff-php-improvement-feedback.md` section 13a.
- Prior single-threshold severity decision (informs why the severity axis is not overloaded with scoring semantics): `.goat-flow/decisions/ADR-008-single-threshold-rubric-severity.md`.
- Task package: `.goat-flow/tasks/0.1.4/M06-disabled-severity-visibility-tier.md`.

# ADR-010: Complexity and documentation rubric default recalibration

**Status:** Accepted
**Date:** 2026-05-18

## Decision

The six `complexity.*` rule defaults and the one remaining tiered `docs.*` rubric (`docs.todo-density`) are recalibrated to single-threshold + severity form, replacing the legacy tiered `warning` / `error` pairs:

| Rule | Old default (tiered) | New default | Severity | Industry anchor |
| --- | --- | --- | --- | --- |
| `complexity.cyclomatic` | warning 10, error 20 | 20 | error | 2× Sonar S1541's 15-issue threshold |
| `complexity.cognitive` | warning 15, error 30 | 30 | error | 2× Sonar S3776 (PHP) 15-smell threshold |
| `complexity.npath` | warning 200, error 500 | 200 | error | PHPMD NPathComplexity violation threshold (1×) |
| `complexity.halstead-volume` | warning 1000, error 2000 | 8000 | error | PhpMetrics Halstead volume violation threshold |
| `complexity.maintainability-index` (lower-is-worse) | warning 55, error 35 | 35 | error | PhpMetrics maintainability "unmaintainable" lower bound |
| `complexity.nesting-depth` | warning 4, error 6 | 5 | error | Sonar S134 max-nesting +2 |
| `docs.todo-density` | warning 5, error 10 | 10 | error | gruff-specific "dense TODO file" rubric; no industry single-number anchor |

This ADR covers default values and severity for these seven rules only. The shape contract (single threshold + severity, no tiered ranges) is governed by [ADR-008](ADR-008-single-threshold-rubric-severity.md) and was implemented at the framework layer in the milestone that migrated the size pillar.

## Context

After the size pillar migrated to single-threshold defaults, six complexity rules and `docs.todo-density` were the last rubric rules still shipping tiered `warning` / `error` defaults. Three signals motivate finishing the migration with these specific values:

1. **Shape consistency across rubric pillars.** Mixing tiered and single-threshold defaults inside the same product surface forces every reader (rule author, config writer, downstream tool) to remember which rules use which shape. The project's `.gruff-php.yaml` already overrides every complexity rule with single-threshold form (e.g. `complexity.cognitive: threshold 30, severity error`), so the single shape is already the de-facto contract. Closing the gap at the rule-definition layer eliminates the inconsistency.

2. **Industry alignment is the dominant anchor.** Where the industry tool's published threshold is itself a violation cutoff (PHPMD NPath 200, PhpMetrics Halstead 8000, PhpMetrics MI 35), gruff matches it at 1×; setting `error` *above* a published violation threshold would make gruff strictly looser than the industry on a hard cliff and is rejected. Where the industry threshold is a "smell" or "report" line (Sonar S1541 CC 15, Sonar S3776 cognitive 15, Sonar S134 nesting 3), gruff's `error` sits at a small multiplier above — between 1.33× and 2× — because the smell line maps to gruff's `warning` or `advisory` tier, not `error`. The picks are: `complexity.cyclomatic` at 1.33× Sonar's smell-line, `complexity.cognitive` at 2×, `complexity.nesting-depth` at Sonar +2 (1.67×). No rule sits at more than 2× a smell-line or above a violation-line.

3. **Project overrides corroborate direction.** The project's `.gruff-php.yaml` already configured every complexity rule at exactly the values this ADR proposes for defaults, except for Halstead, where the project chose 2000 against a calibrated codebase. The 8000 default matches PhpMetrics' anchor and remains overridable; this codebase's stricter 2000 still applies via the existing override.

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| Leave the complexity and docs rules on tiered defaults indefinitely | The single-threshold contract becomes "size only" rather than the universal rubric shape; rule authors learn the wrong default each time they add a rubric. | Rejected. Once any pillar migrates, leaving the rest behind is a worse state than completing the migration. |
| Match the strictest industry thresholds (PHPMD's CC 10, Sonar's nesting 3, etc.) at `error` severity | These thresholds are "report" or "smell" lines in their source tools, not "violate" lines. Applying them at `error` severity in gruff would turn most maintained PHP code into a wall of failing errors out of the box. | Rejected. The industry's strictest line maps to gruff's `advisory` or `warning` severity, not `error`. |
| Use small multiples (1.33×, 1.67×, 2×) of industry "smell" lines as `error` defaults, and 1× match for published violation thresholds | The picks keep `error` reserved for genuinely hard-to-maintain code without ever sitting above a published industry violation threshold. NPath and Halstead and MI all match their anchors at 1×; cyclomatic, cognitive, and nesting sit above their smell-line anchors at 1.33×–2×. | Accepted. |
| Default each rule at a different severity (cyclomatic at `warning`, halstead at `error`, etc.) to express how strict each rubric should be | Severity choice ends up encoding rule authors' opinions about which complexity dimension matters most, rather than letting projects decide. | Rejected. All rubric rules default to `error`, matching the size pillar; projects downgrade by config when they want softer signal. |
| Use 4000 for Halstead as a compromise between this project's override (2000) and PhpMetrics (8000) | Picks a value with no external anchor and falls back to "feels about right." | Rejected. 8000 matches PhpMetrics' published violation threshold; the project's 2000 override stays in `.gruff-php.yaml` for this codebase specifically. |

## Consequences

- Each `src/Rule/Complexity/*Rule.php` and `src/Rule/Docs/TodoDensityRule.php` `RuleDefinition` must declare a `defaultSeverityThreshold` and remove the `defaultThresholds: ['warning' => X, 'error' => Y]` block. `defaultSeverity` becomes `Severity::Error` for each.
- After migration, no rule in the registry ships tiered defaults. `RuleConfigApplier`'s legacy-tiered-validation branch becomes unreachable for new rules; the code path remains in place so existing user configs that still use `thresholds: {warning, error}` overrides on rules that have migrated raise the documented single-threshold error rather than silently accepting the legacy shape.
- The `list-rules` and SARIF outputs gain `{threshold, severity}` shape for these seven rules and drop the legacy tiered map. Downstream consumers reading the rule list must accept either shape per the `defaultSeverityThreshold` field.
- The project's `.gruff-php.yaml` complexity overrides continue to win where they tighten (Halstead 2000 < 8000 default); where they match (cognitive 30 = 30 default), they become explicit-redundant overrides that can be pruned in a follow-up cleanup without behavioural change.
- Tests asserting tiered defaults for the migrated rules update to single-threshold expectations. The `RuleRegressionSnapshotTest` finding count and hash, and the `RuleRegistryTest` definitions snapshot, both refresh.
- Any rule that genuinely needs two cutoffs (a real-world case discovered post-migration) requires an ADR amendment naming the rule and showing why one severity-bound threshold cannot express the policy. Until that happens, the tiered shape is legacy compatibility for previously-shipped configs only.

## Reversibility

Two-way door per rule. Reversing a single threshold requires a new ADR-amend with evidence that the chosen cutoff is wrong on at least one external corpus (not just this project). Reverting the whole table back to tiered shape requires superseding this ADR plus ADR-009 plus ADR-008, since the shape contract is the precondition.

The rollback path is to restore each rule's previous `defaultThresholds: ['warning' => X, 'error' => Y]` block and the previous `Severity::Warning` default. Tests covering the old shape are preserved in git history.

## Project-specific overrides

The defaults in this ADR are framework-level. `.gruff-php.yaml` may override per-rule and historically does so in both directions. As of the audit-driven config honesty pass:

- `complexity.cyclomatic`, `complexity.cognitive`, `complexity.maintainability-index`, `complexity.nesting-depth` match the ADR defaults exactly.
- `complexity.halstead-volume` is stricter than the ADR default (project 2000, ADR 8000).
- **`complexity.npath` is intentionally looser** than the ADR default (project 500, ADR 200). The codebase currently has ~9 methods in the 200-435 npath range, several of which are statement-type dispatchers (`RedundantVariableRule::checkChildBlocks`, `UnreachableCodeRule::walkChildren`, complexity walkers) scheduled for consolidation under the shared-visitor refactor. Tightening `npath` to match the ADR is a follow-up after that refactor reduces the deduplicated walker count.

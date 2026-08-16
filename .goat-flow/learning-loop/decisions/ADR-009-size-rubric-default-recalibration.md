# ADR-009: Size rubric default recalibration

**Status:** Accepted
**Date:** 2026-05-18

## Decision

The seven `size.*` rule defaults are recalibrated to single-threshold + severity form, replacing the legacy tiered `warning` / `error` pairs. Values are anchored to the prevailing PHPMD / Sonar PHP defaults so the out-of-box behaviour aligns with what PHP teams already calibrate against in other tools:

| Rule | Old default (tiered) | New default | Severity | Industry anchor |
| --- | --- | --- | --- | --- |
| `size.file-length` | warning 400, error 800 | 1000 | error | PHPMD/Squiz/Sonar S104 consensus |
| `size.class-length` | warning 300, error 500 | 1000 | error | PHPMD ExcessiveClassLength |
| `size.method-length` | warning 30, error 60 | 100 | error | PHPMD ExcessiveMethodLength + Sonar S138 |
| `size.average-method-length` | warning 20, error 40 | 50 | error | half of method-length cap |
| `size.parameter-count` | warning 5, error 8 | 10 | error | PHPMD ExcessiveParameterList |
| `size.property-count` | warning 15, error 25 | 15 | error | PHPMD TooManyFields |
| `size.public-method-count` | warning 15, error 25 | 25 | error | PHPMD TooManyMethods |

Each rule's `RuleDefinition` declares a single default threshold and the `error` severity. Projects continue to override via the existing `threshold` + `severity` config keys.

This ADR covers default values only. The contract that every rubric uses single threshold + severity is governed by [ADR-008](ADR-008-single-threshold-rubric-severity.md) and is the precondition for these values.

## Context

The previous defaults shipped as tiered `warning` / `error` pairs in each size rule's `defaultThresholds`. Three signals motivate the recalibration:

> **Metric-unit caveat (added 2026-05-19, see [[ADR-012-size-rule-line-counting-metric]])**: the industry anchors below are stated in *raw* lines (PHPMD and Sonar both count raw source lines). gruff's `size.method-length` and `size.average-method-length` rules measure *logical* statement lines (distinct start lines of non-`Nop` statements), while `size.file-length` and `size.class-length` measure *substantive* lines (non-blank lines remaining after comment-only lines are masked out; amended 2026-08-05, superseding the raw-line metric these two rules used until 0.5.2). The anchor values inform the *direction* of the threshold; they do not provide byte-for-byte parity for either pair. On this codebase the empirical raw/logical ratio is median 2.39× and p25–p75 range 2.14×–2.88× (601 methods sampled at raw≥10, logical≥5; 2026-05-19). Adopters configuring a PHPMD-parallel `method-length` value should calibrate against their own codebase's logical-line distribution, not their PHPMD threshold.

1. **Industry alignment is the dominant anchor.** PHPMD and SonarQube PHP are the de-facto reference rubrics PHP teams already calibrate their codebases against. Shipping defaults that match those caps means a healthy codebase that passes PHPMD/Sonar also passes gruff at the size pillar, and gruff's findings carry the credibility of those tools' guidance. The previous tiered `warning` tier was substantially below every comparable tool's only threshold (400 < PHPMD/Sonar 1000 for files, 30 < PHPMD/Sonar 100 for methods, etc.), biasing the out-of-box signal toward noise.

2. **Own-config evidence corroborates direction.** The project's `.gruff-php.yaml` overrode every single tiered default upward and collapsed each to a single threshold + severity. When the tool author rejects the tool's own defaults on the tool's own source, downstream adopters will hit the same friction. The project's overrides are slightly stricter than PHPMD on some axes (class 800 < PHPMD 1000, method 70 < PHPMD 100) and looser on others, but never as strict as the legacy `warning` tier.

3. **Self-scan empirics on `src/`.** 224 PHP files, top five at 846 / 831 / 673 / 670 / 561 lines. Against the legacy tiered defaults, at least four files would fire at `error` and roughly a dozen at `warning` on file-length alone, despite the codebase being actively maintained. The current size-pillar self-scan returns exactly two `error` findings, both `size.parameter-count` on legitimately oversized constructor factories. The `parameter-count` rubric is doing its job — the others were misclassifying maintained code as failing.

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| Keep tiered defaults at the previous values | Out-of-box scan reports warnings on roughly a dozen files in a healthy codebase and the project's own dogfood config has to override every value. Calibration also conflicts with ADR-008. | Rejected. The defaults must match the contract and the empirical evidence. |
| Adopt the project's own override values verbatim (file 1000, class 800, method 70, etc.) | Aligns with current dogfood config but anchors gruff's defaults to one codebase's preferences rather than industry consensus. Downstream projects inherit a calibration optimised for this repository. | Rejected. Industry anchors are more durable than one project's snapshot. |
| Match PHPMD / Sonar exactly on every axis | Defaults align with the PHP ecosystem's prevailing rubrics, so a codebase that passes PHPMD/Sonar passes gruff at the size pillar out of the box. | Accepted. Where PHPMD and Sonar disagree (e.g. parameter count: PHPMD 10 vs Sonar 7), the looser PHPMD value is used because the rubric is severity `error` and the stricter value belongs in project-level overrides. |
| Pick stricter-than-PHPMD values to differentiate gruff | Gives the tool an opinionated identity but loses the "passes PHPMD = passes gruff" property that makes adoption frictionless. | Rejected. Differentiation lives in the rules gruff has that PHPMD does not (naming pillar, mutation integration, etc.), not in stricter size defaults. |
| Loosen severity to `warning` instead of `error` | Defers the consequence decision back to two thresholds in spirit and weakens the "this is a hard ceiling" signal. | Rejected. Severity is part of the rubric — if the rule fires, it should be an error by default; projects can downgrade by config. |

## Consequences

- Each `src/Rule/Size/*Rule.php` `RuleDefinition` must declare the new single-threshold default and an explicit default severity of `error`. The `defaultThresholds: ['warning' => X, 'error' => Y]` block is removed for size rules.
- `RuleDefinition` gains a `defaultSeverityThreshold` (or equivalent) so the single-threshold shape is expressible at the rule-definition layer, not just at the YAML override layer. `RuleSettings::severityThreshold` already consumes the runtime form.
- `RuleConfigApplier` continues to support both the legacy `thresholds: {warning, error}` shape (for backward compatibility on complexity / docs rules that have not migrated) and the `threshold` + `severity` shape (the new default for size).
- Tests asserting tiered defaults for size rules are updated to the single-threshold expectations. Tests asserting `RuleConfigApplier` tiered-override behavior remain valid for the rules that still carry tiered defaults.
- The project's `.gruff-php.yaml` keeps its overrides; they continue to win over the new defaults. The override file becomes a thinner overlay rather than a wholesale rewrite.
- Complexity (`src/Rule/Complexity/*Rule.php`) and documentation (`src/Rule/Docs/TodoDensityRule.php`) rules still ship tiered defaults. Those migrations are deferred to a follow-up ADR with their own empirical justification; this ADR is scoped to size only.

## Reversibility

Two-way door per rule. Reversing a single threshold requires a new ADR-amend with evidence that the chosen cutoff is wrong on at least one corpus other than this project. Reverting the whole table back to tiered shape requires superseding both this ADR and ADR-008, since the shape is the precondition.

The rollback path is to restore the previous `defaultThresholds` block per rule and the previous default severity of `Warning`. Tests covering the old defaults are preserved in git history.

# ADR-012: Size rule line-counting metric

**Status:** Accepted
**Date:** 2026-05-19

## Decision

The `size.*` rule family uses **two different line-counting metrics** by deliberate design, partitioned along the container/content axis:

| Rule | Metric | Implementation anchor |
| --- | --- | --- |
| `size.file-length` | **raw** lines | `src/Parser/AnalysisUnit.php` (search: `lineCount`) — `substr_count($source, "\n") + 1` |
| `size.class-length` | **raw** lines | `src/Rule/Size/ClassLengthRule.php` (search: `$length = $endLine`) — `endLine - startLine + 1` |
| `size.method-length` | **logical** lines | `src/Rule/Size/MethodLengthRule.php` (search: `logicalLineCount`) — distinct start lines of non-`Nop` statements |
| `size.average-method-length` | **logical** lines | `src/Rule/Size/AverageMethodLengthRule.php` (search: `logicalLineCount`) — same mechanic |

The `size.parameter-count`, `size.property-count`, and `size.public-method-count` rules count discrete entities and are unaffected by this metric question.

## Context

Containers (files and classes) are navigated visually. A reviewer's friction with a 1,000-line file is dominated by scroll distance and the surface area of unrelated state — concerns the raw line count captures accurately. Reformatting a class to compress vertical whitespace does not change how heavy it feels to read.

Method bodies are different. PHP's call-and-attribute idiom encourages multi-line constructor invocations and array literals, and modern formatters split arguments one-per-line. Under raw line counting, an orchestration method composed of a few delegated calls scores the same as a tight method full of inline logic. The signal a reviewer actually wants — *how much branching and state lives in this method?* — tracks statement density, not vertical extent. Empirically on this codebase (601 methods with raw≥10 and logical≥5), the raw/logical ratio has median 2.39× and p25–p75 range 2.14×–2.88× (computed 2026-05-19). A 169-raw / 59-logical orchestrator (`AnalyseCommand::execute`) reads as long but disciplined; a 50-raw / 35-logical method with deep nesting reads as cramped.

The mix is therefore: **containers as visual objects, methods as density measures.**

A worked example: `src/Command/AnalyseCommand.php::execute` is 169 raw lines (would exceed any conventional method-length cap) but 59 logical lines (passes `size.method-length: 70`). Inspection confirms it delegates every step to helpers, with no inline business logic. Under an all-raw metric this would fire as a god method despite being a textbook orchestration. Under an all-logical metric on classes, refactors that compress whitespace without changing behavior would silently relax the class-length gate.

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| All-raw across the four rules | Orchestration methods that delegate cleanly score the same as cramped methods with deep nesting; the rule fires on disciplined long-but-flat callers. | Rejected. Method density is what the rule should be measuring. |
| All-logical across the four rules | Class-length and file-length become dependent on statement density rather than visual size; whitespace-heavy classes pass that would fail an all-raw check, and cosmetic reformatting changes whether a file is "too long." | Rejected. Containers' burden is visual, not density-shaped. |
| Current mix (containers raw, contents logical) | Two metrics in one rule family that adopters must remember. Mitigation: this ADR plus the per-rule docblocks plus the per-rule comments in `.gruff-php.yaml` make the choice explicit. | Accepted. The metric choice matches what each rule is actually measuring. |
| Document the mix without changing anything else | Resolves the surprise but does not address PHPMD/Sonar anchor narratives elsewhere. | Insufficient on its own. ADR-009's anchor section is amended in the same milestone to acknowledge the unit transform for `size.method-length`. |

## Industry-anchor caveat

ADR-009 cites PHPMD's raw-line method-length cap as the basis for `size.method-length: 100` default. That anchor inspires the *direction* of the threshold; it does not deliver byte-for-byte parity, because gruff's rule measures logical lines while PHPMD measures raw. On this codebase the empirical multiplier is median 2.39× (logical 100 ≈ raw 200-300 for typical PHP). Adopters configuring a PHPMD-parallel `method-length` threshold should pick a value calibrated to their codebase's logical-line distribution, not their PHPMD value. ADR-009 is amended to state this multiplier range explicitly.

## Consequences

- Rule docblocks for `MethodLengthRule`, `AverageMethodLengthRule`, `ClassLengthRule`, and `FileLengthRule` each state which metric they use in one sentence.
- `.gruff-php.yaml` per-rule inline comments on those four rules mention "logical lines" or "raw lines" so config readers do not have to read the rule source to understand the metric.
- ADR-009's `## Context` carries a paragraph acknowledging the unit transform from PHPMD's raw anchor to gruff's logical measurement, with the empirical multiplier range stated.
- Future rubric authors adding a new size-shaped rule must declare which metric they use, with the container/content axis as the default decision boundary.

## Reversibility

Two-way door, but with downstream impact. Standardising on a single metric would silently re-calibrate every downstream config; the migration must be paired with a per-rule recalibration of the project YAML and any published default. Reverting to a single metric requires:

1. Choosing the unified metric (all-raw or all-logical) with empirical evidence on at least one corpus other than this project.
2. Picking new defaults that fire on the same problem classes as the current split-metric defaults.
3. Migrating shipped configs in a single deprecation cycle.

Until then, the split is the durable choice.

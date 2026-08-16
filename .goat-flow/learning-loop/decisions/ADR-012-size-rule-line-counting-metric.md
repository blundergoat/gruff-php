# ADR-012: Size rule line-counting metric

**Status:** Accepted
**Date:** 2026-05-19

## Decision

The `size.*` rule family uses **two different line-counting metrics** by deliberate design. Neither metric charges a unit for its own documentation; they differ in what they measure once blank and comment lines are discounted. The container/content partition below was the original rationale and is superseded by the 2026-08-05 amendment at the end of this record.

| Rule | Metric | Implementation anchor |
| --- | --- | --- |
| `size.file-length` | **substantive** lines | `src/Rules/Size/SubstantiveLineCounter.php` (search: `countAll`) — blank and comment-only lines are free |
| `size.class-length` | **substantive** lines | `src/Rules/Size/SubstantiveLineCounter.php` (search: `countRange`) — same counter over the declaration-to-closing-brace span |
| `size.method-length` | **logical** lines | `src/Rules/Shared/NodeIndex.php` (search: `logicalStatementLineCount`) — distinct start lines of non-`Nop` statements |
| `size.average-method-length` | **logical** lines | `src/Rules/Shared/NodeIndex.php` (search: `logicalStatementLineCount`) — same mechanic, averaged across the type's methods |

The `size.parameter-count`, `size.property-count`, and `size.public-method-count` rules count discrete entities and are unaffected by this metric question.

## Context

Containers (files and classes) are navigated visually. A reviewer's friction with a 1,000-line file is dominated by scroll distance and the surface area of unrelated state — concerns the raw line count captures accurately. Reformatting a class to compress vertical whitespace does not change how heavy it feels to read.

Method bodies are different. PHP's call-and-attribute idiom encourages multi-line constructor invocations and array literals, and modern formatters split arguments one-per-line. Under raw line counting, an orchestration method composed of a few delegated calls scores the same as a tight method full of inline logic. The signal a reviewer actually wants — *how much branching and state lives in this method?* — tracks statement density, not vertical extent. Empirically on this codebase (601 methods with raw≥10 and logical≥5), the raw/logical ratio has median 2.39× and p25–p75 range 2.14×–2.88× (computed 2026-05-19). A 169-raw / 59-logical orchestrator (`AnalyseCommand::execute`) reads as long but disciplined; a 50-raw / 35-logical method with deep nesting reads as cramped.

The original rationale therefore treated containers as visual objects and methods as density measures.

A worked example, measured at this ADR's date: `src/Cli/Command/AnalyseCommand.php::execute` was 169 raw lines (would exceed any conventional method-length cap) but 59 logical lines (passes `size.method-length: 70`). Inspection confirms it delegates every step to helpers, with no inline business logic. Under an all-raw metric this would fire as a god method despite being a textbook orchestration. Under an all-logical metric on classes, refactors that compress whitespace without changing behavior would silently relax the class-length gate.

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| All-raw across the four rules | Orchestration methods that delegate cleanly score the same as cramped methods with deep nesting; the rule fires on disciplined long-but-flat callers. | Rejected. Method density is what the rule should be measuring. |
| All-logical across the four rules | Class-length and file-length become dependent on statement density rather than visual size; whitespace-heavy classes pass that would fail an all-raw check, and cosmetic reformatting changes whether a file is "too long." | Rejected. Containers' burden is visual, not density-shaped. |
| Current split (containers substantive, method metrics logical) | Source-text line counts and AST statement-line counts share one family, so adopters must distinguish the units. Mitigation: this ADR, the rule docblocks, and the repository config comments name each metric. | Accepted. Each rule measures the unit its review signal requires. |
| Document the mix without changing anything else | Resolves the surprise but does not address PHPMD/Sonar anchor narratives elsewhere. | Insufficient on its own. ADR-009's anchor section is amended in the same milestone to acknowledge the unit transform for `size.method-length`. |

## Industry-anchor caveat

ADR-009 cites PHPMD's raw-line method-length cap as the basis for `size.method-length: 100` default. That anchor inspires the *direction* of the threshold; it does not deliver byte-for-byte parity, because gruff's rule measures logical lines while PHPMD measures raw. On this codebase the empirical multiplier is median 2.39× (logical 100 ≈ raw 200-300 for typical PHP). Adopters configuring a PHPMD-parallel `method-length` threshold should pick a value calibrated to their codebase's logical-line distribution, not their PHPMD value. ADR-009 is amended to state this multiplier range explicitly.

## Consequences

- Rule docblocks for `MethodLengthRule`, `AverageMethodLengthRule`, `ClassLengthRule`, and `FileLengthRule` each state which metric they use in one sentence.
- This repository's `.gruff-php.yaml` comments name file/class metrics as substantive lines and method metrics as logical lines.
- ADR-009's `## Context` carries a paragraph acknowledging the unit transform from PHPMD's raw anchor to gruff's logical measurement, with the empirical multiplier range stated.
- Future size rules must declare their metric. Physical-span metrics keep blank and comment-only lines free; method metrics default to logical statement lines.

## Reversibility

Two-way door, but with downstream impact. Standardising on a single metric would silently re-calibrate every downstream config; the migration must be paired with a per-rule recalibration of the project YAML and any published default. Reverting to a single metric requires:

1. Choosing the unified metric (all-raw or all-logical) with empirical evidence on at least one corpus other than this project.
2. Picking new defaults that fire on the same problem classes as the current split-metric defaults.
3. Migrating shipped configs in a single deprecation cycle.

Until then, the split is the durable choice.


## Amendment (2026-08-05, family ratification)

`size.class-length` now counts substantive lines through the shared
`GruffPhp\Rules\Size\SubstantiveLineCounter`, the same metric `size.file-length` adopted in the
family-wide ratification: blank lines and comment-only lines are free. The original
container-measure rationale (scroll distance) is superseded by the family principle that required
documentation must never push a unit over a size budget - the same argument that ended raw
counting at the file level applies to the class span under PSR-4's one-class-per-file reality.
`size.method-length` is unchanged: it still counts distinct statement start lines, the logical metric defined above.

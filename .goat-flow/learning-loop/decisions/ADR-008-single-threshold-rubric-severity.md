# ADR-008: Single threshold and severity for rubrics

**Status:** Accepted
**Date:** 2026-05-18

## Decision

Every thresholded rubric must be configured as one numeric value plus one severity.

Do not model rubric calibration as a warning/error range such as:

```yaml
thresholds:
    warning: 50
    error: 80
```

Use the single-value shape instead:

```yaml
threshold: 50
severity: warning
```

This applies to all rubric-style thresholds: size, complexity, maintainability, documentation density, test-quality numeric limits, sensitive-data thresholds where a single cutoff is the rubric, and future rule families that score one measured value against one cutoff.

Named option maps remain valid when they are not severity bands. For example, `allowedLiterals`, `typeSuffixes`, `cliMirrorAllowlist`, and multi-parameter detector knobs are rule options, not warning/error rubric ranges.

## Context

The current config surface already supports a concise `threshold` + `severity` form in `src/Config/RuleConfigApplier.php` and `.gruff-php.yaml` uses it heavily for project policy. `.goat-flow/architecture.md` currently documents that concise form as compiling both internal warning/error thresholds to the same value for rules whose defaults are warning/error pairs.

That compatibility layer is useful for existing rules, but warning/error bands make calibration harder to reason about:

- reviewers must decide two values when the project policy usually needs one boundary;
- downstream configs can disagree about which band matters, even when only one severity is operationally enforced;
- rule authors can encode escalation policy into the rule default instead of making the project choose an explicit severity;
- dogfooding discussions drift into range tuning instead of deciding the actual rubric cutoff.

The desired long-term contract is simpler: a rubric asks, "At what value does this become a finding, and at what severity?"

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| Warning/error ranges per rubric | Calibration has two cutoffs per rule, so project policy becomes ambiguous and future rubrics inherit unnecessary complexity. | Rejected. This is the legacy shape only, not the direction for new rubrics. |
| One threshold plus one severity | The project makes one explicit cutoff decision and one explicit consequence decision. | Accepted. This keeps rubrics auditable and aligns config with how maintainers actually reason about quality gates. |
| Severity hard-coded in the rule with no config severity | Projects cannot choose whether the same cutoff is advisory, warning, or error. | Rejected. Rubrics should expose both the value and the consequence. |
| Named options for non-rubric detector behavior | The config can still express real detector semantics without pretending every option is a severity threshold. | Accepted. Multi-field detector options are not warning/error bands. |

## Consequences

- New thresholded rules must prefer `threshold` + `severity` as their public calibration model.
- Future refactors should migrate warning/error threshold pairs toward a single threshold plus severity where the rule is measuring one rubric value.
- Documentation should avoid presenting warning/error ranges as the preferred shape.
- If a rule genuinely needs multiple independent numeric knobs, those knobs belong under named `options` or named non-severity thresholds with clear semantics, not `warning`/`error` bands.
- Compatibility code may continue to read old warning/error pairs until a deliberate migration removes that surface.

## Reversibility

This is reversible, but only with evidence that single-threshold rubrics cannot express a real project policy. Reversal should name the affected rubric family and show why two severity bands are materially better than one cutoff plus one severity. Until then, warning/error threshold ranges are legacy compatibility, not the design target.

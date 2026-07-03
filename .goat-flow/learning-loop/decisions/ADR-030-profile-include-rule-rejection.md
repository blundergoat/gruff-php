# ADR-030: Reject out-of-profile --include-rule ids

**Status:** Accepted
**Date:** 2026-07-03
**Author(s):** Matthew Hansen (decision), Claude (record)
**Ticket/Context:** 0.5.0 profile/include score coherence

## Decision

`analyse --profile security --include-rule <id>` is a usage error (exit code `2`) when `<id>`'s pillar is outside the profile's scored pillars (`security`, `sensitive-data`). The alternative - running the rule and expanding the score pillars to match - is rejected. `--exclude-rule` under a profile remains a plain narrowing operation and is never rejected. `--profile default` behaviour is unchanged.

## Context

Before this decision, `--profile security --include-rule docs.missing-public-phpdoc` ran the documentation rule and emitted its error finding while the composite score stayed a security-only 100: the CLI's `--include-rule` refinement replaced the profile's rule selection, but score pillars stayed profile-limited. The reported grade therefore did not correspond to the findings the command chose to emit - a violation of the mission's "a human can trust the verdict" bar.

Rejection is the safer default because a profile is a scoring contract, not just an execution filter. Expanding score pillars on demand would make the same `--profile security` invocation produce differently-composed composites depending on unrelated include flags, which is harder to reason about in CI than an explicit error.

## Consequences

- The usage error names the offending rule id, its pillar, and both remedies (drop the profile or include only security/sensitive-data ids).
- Validation runs before the missing-config prompt, honouring the prompt-ordering rule in `.goat-flow/learning-loop/patterns/commands.md`.
- Scripts that relied on the incoherent combination now exit `2` and must pick a side.
- A future score-expansion mode would supersede this ADR explicitly rather than silently changing composite composition.

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| Allow and keep security-only scoring (old behaviour) | A non-security error hides behind a clean security grade. | Rejected: the verdict lies about the emitted findings. |
| Allow and expand score pillars | The meaning of a `--profile security` composite silently varies per invocation. | Rejected: scoring contracts should not mutate via unrelated flags. |
| Reject with a usage error | Previously-working (incoherent) invocations now exit `2`. | Accepted: fails fast with an actionable message. |

# ADR-017: Project mission — govern AI-generated code for human verifiability

**Status:** Accepted
**Date:** 2026-05-30
**Author(s):** Matthew Hansen

## Context

gruff-php began as a general "opinionated PHP code-quality analyzer." In practice its highest-value use is as a gate in a coding agent's loop: the agent writes the code, and a human who did not write it must read, review, and trust it before it ships. Coding agents routinely produce code that superficially works while quietly misunderstanding the requirement, and they pad test suites with low-signal assertions that make a green run meaningless.

Without a stated mission, rule calibration drifts toward "match PHPMD / Sonar defaults" (industry parity) rather than "make this change safe for a human to sign off on." Those two targets disagree: [ADR-010](ADR-010-complexity-and-docs-rubric-default-recalibration.md) anchored the complexity defaults to industry violation/smell lines, but a verifiability lens weights the metrics that track human comprehension differently. This ADR fixes the mission so every downstream rule, default, severity, and documentation decision has a single optimisation target to serve.

## Decision

gruff-php's mission is to **govern AI-generated code so a human can verify, trust, and sign off on it.** Every rule and default is justified by one of three verifiability goals:

1. **Legible enough to verify.** Cap complexity and nesting, and require an intent-bearing doc comment on every method — public or private (see [ADR-004](ADR-004-public-phpdoc-template.md); `docs.missing-public-phpdoc` scans all `ClassMethod` nodes) — that states what the method is for, what it returns at the edges, and what the caller must satisfy. The comment is a plain-English contract the reviewer checks the code against; a doc comment that contradicts the implementation is itself a signal the change needs a deeper look.
2. **Secure where the eye fails.** The `security` and `sensitive-data` pillars catch the classes of mistake a human reviewer skims past.
3. **Tested for real, not padded.** The `test-quality` pillar rewards genuine assertions and flags low-signal ceremony, so a green suite means the behaviour is actually exercised rather than mocked into a tautology.

A calibration corollary follows: **a gate earns its place only if the cheapest way for the agent to satisfy it is the genuine improvement, not a cosmetic one.** Cognitive-complexity and nesting (cheapest fix = real simplification) and the test-quality anti-bloat rules (cheapest fix = a real assertion) satisfy this; raw "must have a doc comment" does not unless it demands substance, which is why `docs.missing-public-phpdoc` requires intent rather than presence.

This mission is the lens for calibration. Where industry parity and verifiability disagree, verifiability wins: complexity defaults should favour the metrics that track human comprehension (cognitive, nesting) over branch-counting proxies (cyclomatic, npath) that can misrank a readable guard-chain. ADR-010's specific thresholds remain in force; this ADR records the objective they serve, not new values.

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| Leave the mission implicit ("code-quality analyzer") | Rule and default decisions optimise for industry parity by default; severity/threshold debates have no shared tie-breaker; the doc-comment-on-everything policy reads as arbitrary strictness. | Rejected. The product already behaves as a verifiability gate; an unstated mission invites drift away from it. |
| State the mission as "find code smells" | Generic; does not explain why doc comments are mandatory on private one-liners or why test-bloat rules exist. | Rejected. Too weak to constrain calibration. |
| Govern AI-generated code for human verifiability (legible, secure, honestly tested) | Gives every rule a single justification and a calibration corollary (cheapest fix = genuine fix). | Accepted. |

## Consequences

- The mission is documented for humans in `README.md` (Mission section) and `docs/mission.md` (full rationale), for coding agents in `docs/gruff-cli-agent-instructions.md`, and as the project descriptor in `CLAUDE.md` / `AGENTS.md`. `.goat-flow/architecture.md` carries a one-line Mission lead-in.
- New rules and default changes must cite which verifiability goal they serve and confirm the cheapest passing fix is the genuine one. A rule whose cheapest fix is cosmetic is a candidate for lower severity, not a hard gate.
- ADR-010's complexity thresholds are unchanged by this ADR. Revisiting them through the verifiability lens (e.g. treating cognitive and nesting as the primary legibility gates and de-emphasising npath) is a follow-up that would carry its own evidence and an ADR amendment.

## Reversibility

Two-way door. The mission can be narrowed or restated by a superseding ADR; doing so must explain what optimisation target replaces it and how that changes calibration. Until then, "would a human sign this off?" is the tie-breaker for rule, default, and severity decisions.

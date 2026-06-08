# ADR-006: Control-Flow Comment Policy

**Status:** Partially reversed (2026-05-31), then return-comment shape narrowed by ADR-025 (2026-06-01) — `docs.return-comment` reworked from "comment above every return" to "value-returning functions need a described `@return`"; `docs.continue-comment` stays deleted. See "Update" below and ADR-025.
**Date:** 2026-05-13

## Update (2026-05-31): return-comment restored

**Superseded for return-comment by ADR-025 (2026-06-01):** the paragraph below is
retained as historical context only. `docs.return-comment` now means "value-returning
functions need a described `@return` tag," not "comment above every `return`."

`docs.return-comment` is reinstated as the original blanket rule: a one-line comment directly above every `return`. The earlier deletion treated it as low-signal ceremony, but that mis-read the rule's purpose. gruff governs AI-generated code so a human who didn't write it can verify it; a comment stating *why* each exit returns is a verification surface a reviewer diffs against the code — the same principle that makes doc comments mandatory. The rule stays advisory, and like other debt-heavy rules its existing-code findings are meant to be frozen via the baseline so it gates new and changed returns rather than forcing a backfill of gruff's own tree. `docs.continue-comment` remains deleted; only the return variant is restored, so the rest of this ADR's reasoning still applies to the continue rule.

## Context

M32 briefly calibrated `docs.return-comment` and `docs.continue-comment` down to advisory severity after the rules produced roughly 1.8k combined findings on gruff's own `src/` tree. M37 revisited the same evidence and found the rules still created pressure for comments that explain what a `return` or `continue` does, rather than why a surprising exit exists.

The v0.1 rule catalogue needs low-noise defaults. A rule that gruff itself cannot satisfy without adding ceremony is not a credible default for adopters.

## Decision

`docs.return-comment` and `docs.continue-comment` are deleted from the v0.1 rule catalogue.

Future work must not re-add blanket "comment every return" or "comment every continue" behavior under the same or a different ID. Reconsideration is allowed only through the 0.2 decision/spike path:

- `.goat-flow/tasks/0.2/M03-control-flow-comment-rule-reconsideration.md`
- `.goat-flow/tasks/0.2/M04-selective-control-flow-intent-rule-spike.md`

Any future replacement must target high-signal cases where a comment explains why non-obvious control flow exits, and it must estimate gruff self-scan volume before implementation.

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| Keep the blanket rules enabled | The self-scan stays dominated by low-value comment findings, and adopters inherit noisy defaults. | Rejected. Finding volume showed ceremony pressure, not quality signal. |
| Keep the rules but lower severity | The report becomes quieter for CI, but still teaches users that every control-flow statement deserves a comment. | Rejected. Severity tuning does not fix a weak rule shape. |
| Disable the rules in `.gruff-php.yaml` only for this repo | gruff would ship rules it cannot satisfy itself, hiding the default-noise problem from the project that owns the rules. | Rejected. Dogfooding should shape defaults, not hide failures locally. |
| Delete the v0.1 rules and defer reconsideration to a narrower 0.2 decision/spike | v0.1 loses a possible documentation rule. | Accepted. It protects default signal-to-noise while preserving a measured path for a future intent-focused rule. |

## Reversibility

This is a two-way door before a stable public release, but reversal requires new evidence. The rollback path is not restoring the deleted blanket rules; it is completing M03/M04 with a narrower rule proposal, fixture examples, non-goals, and a measured self-scan estimate in low double digits rather than hundreds or thousands.

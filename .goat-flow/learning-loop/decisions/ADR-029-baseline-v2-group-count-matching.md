# ADR-029: Baseline v2 group/count matching

**Status:** Accepted
**Date:** 2026-07-03
**Author(s):** Matthew Hansen (decision), Claude (record)
**Ticket/Context:** 0.5.0 baseline line-shift resilience

## Decision

Replace the per-finding fingerprint baseline schema (`gruff.baseline.v1`) with a PHPStan-style group/count schema, `gruff.baseline.v2`:

- The baseline match key is exactly `(file, ruleId, message)`. Line, endLine, column, symbol, and fingerprint never participate in baseline matching.
- Persisted rows are `{file, ruleId, message, count}` with `count >= 1`, one deterministic row per group, sorted by `(file, ruleId, message)`.
- Matching is count arithmetic per group: with `B` accepted and `C` live instances, `unchanged = min(B, C)`, `new = max(0, C - B)`, `absent/resolved = max(0, B - C)`. Within a group, live instances are ordered by `(line, column)` before the accepted count is spent.
- `gruff.baseline.v1` input fails closed with a `baseline-error` diagnostic instructing the user to regenerate via `--generate-baseline`. There is no silent migration, and ordinary `analyse` never rewrites a committed baseline.
- Message matching is exact. The baseline file is encoded with `JSON_INVALID_UTF8_SUBSTITUTE`, and the group key applies the same substitution to live values, so persisted and in-memory identities stay symmetric even for invalid UTF-8 bytes.
- `Finding::fingerprint()` and `Finding::stableIdentity()` are unchanged and remain byte-compatible in JSON findings and SARIF `partialFingerprints`; the baseline no longer consumes them.

## Context

v1 matched accepted debt to live findings purely by `Finding::fingerprint()`, which hashes `line`, `endLine`, and `column`. Inserting or deleting any line above a suppressed finding changed its fingerprint, so a still-present accepted finding re-appeared as one `new` finding plus one `absent` baseline entry (documented as an active footgun in `.goat-flow/learning-loop/footguns/commands.md`, observed four times during the 0.3.0 self-scan cleanup). Teams could not reformat or insert code above accepted debt without red CI.

The reproduction (2026-07-03, one line inserted above the second of two accepted findings): v1 reported `new=1, unchanged=1, absent=1`; v2 reports `new=0, unchanged=2, absent=0` for the same edit.

## Consequences

- Committed baselines survive unrelated line shifts; `--fail-on-new`'s "new" definition now derives from group-count overflow.
- Existing v1 baselines must be regenerated once (`gruff-php analyse --generate-baseline <path>`); until then runs exit `2` with the regenerate instruction.
- Rule-message rewording invalidates the affected baseline groups because the message is part of the match key. Releases that reword messages must carry a regenerate note in `CHANGELOG.md`.
- Absent/resolved reporting is group-shaped: rows carry `{file, ruleId, message, count}` where `count` is the number of resolved instances; per-row line numbers no longer exist.
- Diff-scoped runs keep `staleEvaluation: not-evaluated-diff-scope` and report zero absent entries, exactly as v1 did.

## Known blind spot: same-group swap

Fixing one accepted instance and simultaneously introducing a different instance with the same `(file, ruleId, message)` identity is invisible: the group count stays within budget, so the swap reports as `unchanged`. Within-file swap detection is intentionally out of scope for v2; the alternative (keeping line-sensitive identity) re-introduces the far more common line-shift false positive. Reviewers get the new instance surfaced whenever the group's live count exceeds its accepted count.

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| Keep v1 fingerprint matching | Any edit above accepted debt resurfaces it as new and fails `--fail-on advisory` CI. | Rejected: observed repeatedly in real use; defeats the purpose of accepting debt. |
| Group by `(file, ruleId)` only | Distinct problems under one rule in one file collapse into one budget; a new, different violation hides behind an old one. | Rejected: message keeps groups honest at negligible cost. |
| Group/count by `(file, ruleId, message)` | Same-group fix-one/add-one swaps go unnoticed; message rewording invalidates groups. | Accepted: rare failure shapes, both documented; line-shift resilience wins. |
| Silently parse v1 as v2 | v1 rows carry lines/fingerprints that v2 semantics ignore; counts would be fabricated from duplicate rows. | Rejected: fail closed with one clear regenerate step instead of silent mismatch. |

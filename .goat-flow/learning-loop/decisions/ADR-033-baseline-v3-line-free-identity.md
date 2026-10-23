# ADR-033: Baseline v3 line-free identity

**Status:** Implemented
**Date:** 2026-09-05
**Author(s):** Matthew Hansen (decision), Claude (record, 2026-10-03)
**Ticket/Context:** 0.6.0 family identity migration; supersedes ADR-029

## Decision

gruff-php writes and reads `gruff.baseline.v3`, the family baseline shape ratified in `FAMILY-CONTRACT.md` (search: `## 3. Identity-migration policy`):

- Each `occurrences` row is `{identity, count, ruleId, path, subject}`. The `identity` digest covers the tool language, rule ID, project-relative path, and subject. The subject is the symbol plus its declaration ordinal, or the message with measured values normalised when no symbol is named.
- Matching spends a row's accepted `count` against live findings with the same identity. Instances beyond the count report as new, missing instances report as absent, and an identity that covers two declarations reports as a collision that is never hidden (`src/Results/Baseline/BaselineFilter.php`, search: `an identity covering two declarations`).
- Sensitive-data findings are never baseline-eligible. A generated baseline counts them by rule under `sensitive.counts` and stores no row for them.
- A `gruff.baseline.v2` file fails closed with exit `2` and names `analyse --migrate-baseline <old> --generate-baseline <new>`, which writes a separate v3 file and leaves the original untouched. A `gruff.baseline.v1` file fails closed and must be regenerated (`src/Results/Baseline/BaselineStore.php`, search: `one line-free identity per reviewed finding`).
- `--generate-baseline` overwrites a current-schema file silently but refuses to destroy an older-schema file at the default path unless `--force` is passed.

## Context

ADR-029 keyed v2 baselines on `(file, ruleId, message)`. The message is part of that key, so rewording a rule's message expired every reviewed entry for it, and two declarations of one name in one file shared a single review. The family identity-migration policy moved every port to one line-free identity in the 0.6.0 break so baseline users re-baseline once. gruff-php introduced the v3 schema and `--migrate-baseline` in commit `9ae069d` (2026-09-05). `README.md` (search: `Baselines suppress reviewed findings by counted identity`) documents the user-facing behaviour.

## Consequences

- A symbol-bearing finding's subject is its symbol rather than its message, so message rewording no longer expires a reviewed entry.
- Existing v2 baselines need one `--migrate-baseline` run; v1 baselines lose their reviews and must be regenerated and re-reviewed.
- The count-per-identity blind spot remains: fixing one instance while adding another under the same identity stays within budget and reports as unchanged. `README.md` (search: `Known blind spot`) records that 102 of 2,303 rows generated over this repository's fixtures accept more than one instance.
- The identity is a family contract, so changing what feeds the digest is a family break owned by `FAMILY-CONTRACT.md`, not a gruff-php decision.

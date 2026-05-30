# ADR-022: Test-quality gate parity — promote fake-test rules to error

**Status:** Implemented
**Date:** 2026-05-30
**Author(s):** gruff maintainers
**Updated:** 2026-05-30 — amends ADR-010 (severity calibration); extends ADR-017 (mission corollary)

## Context

ADR-017 names three mission legs: legible, secure, and **tested for real**. The first
two gate hard, but the third barely participated: of 33 `test-quality` rules only two
defaulted to `error` (`empty-data-provider`, `extends-production-class`). The rules that
prove a test is *fake* — it asserts nothing, never calls the system under test, or asserts
a tautology — sat at `warning`/`advisory`. An agent gating at `--fail-on error` could
therefore ship a green suite that exercises nothing, which is exactly the failure ADR-017
exists to prevent (a green run that no longer means the behaviour is exercised).

ADR-017's calibration corollary is the test for which rules may gate: **a rule earns a
hard severity only when the cheapest way to satisfy it is a genuinely better artifact, not
a cosmetic edit.** For test-quality that means: the cheapest fix must be a real assertion
or a real call to the subject, not a rename or a reformat.

Evidence (dogfood + fixture corpus, `analyse --no-config` over `tests/`):

- The promotion candidates fire **only** on the deliberately-bad fixtures in
  `tests/Fixtures/TestQuality/`; they fire **zero** times on gruff's own 149-unit real
  test suite, which uses assertion helpers, data-provider matrices, `expectException`, and
  Pest `expect()`. That real suite is the negative corpus: the rules do not false-positive
  on legitimate test shapes.
- `trivial-assertion` fires 80 times even on fixtures and is broad; the mock smells have a
  cheapest-fix that can be cosmetic. These stay at `warning`.

## Decision

Promote three `test-quality` rules to `error` — changing both the rule's
`defaultSeverity` and the severity stamped on its findings:

- `test-quality.no-assertions` (`warning` → `error`) — a test with no observable assertion
  proves nothing; cheapest fix is to add a real assertion.
- `test-quality.sut-not-called` (`advisory` → `error`) — the named subject is never
  invoked; cheapest fix is to actually call it.
- `test-quality.tautological-type-assertion` (`warning` → `error`) — `assertInstanceOf(X,
  new X)` restates a static guarantee; cheapest fix is to assert real behaviour. (High
  confidence; fires only on locally-constructed instances.)

Keep at `warning`/`advisory` the rules whose cheapest fix can be cosmetic or that still
over-fire: `mock-only-test`, `mock-without-expectation`, `trivial-assertion`,
`trivial-snapshot`, and the style/design smells (`eager-test`, `mystery-guest`,
`excessive-mocking`, `setup-bloat`, `magic-number-assertion`, naming/readability). Forcing
those would manufacture ceremony — the opposite of the mission.

Severity is metadata, not schema: `gruff.analysis.v2` / `gruff.baseline.v1` are unchanged.
The two stability snapshots (rule-definition digest, fixture-finding digest) are refreshed
in the same change.

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| Leave all fake-test rules at warning/advisory | "Tested for real" never gates; an agent ships a green suite that asserts nothing | Rejected — defeats a core mission leg |
| Promote all seven Objective-2 candidates to error | `trivial-assertion` (80 fixture hits, broad) and the mock smells force cosmetic edits / risk FPs | Rejected — violates the cheapest-fix-is-genuine test and the kill criteria |
| Promote only the three FP-clean "proves-nothing" rules | — | **Accepted** — each is dogfood-proven FP-clean (realTests=0) and its cheapest fix is a stronger test |

## Reversibility

Two-way door. Severity is a rule-definition default plus a finding stamp; reverting is
flipping the enums back and regenerating the two snapshot digests
(`RuleRegistryTest`, `RuleRegressionSnapshotTest`). Revisit if a promoted rule is found to
false-positive on a legitimate test shape in the field — harden the shape or demote, per
the kill criteria. A consumer who disagrees can lower any rule's severity in config; the
bundled `gruff.starter` preset already narrows scope for first adoption.

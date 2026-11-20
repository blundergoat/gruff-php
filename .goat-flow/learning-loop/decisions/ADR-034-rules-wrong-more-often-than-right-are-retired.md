# ADR-034: Rules wrong more often than right are retired, not tuned

**Status:** Accepted
**Date:** 2026-10-04
**Updated:** 2026-10-14
**Author(s):** Claude, user direction
**Ticket/Context:** precision-floor M19 under family lock M82. The operator ruled on 2026-10-04 that a rule right less than
half the time is deleted, and accepted the gruff-php table with "Accept all (Recommended)".

## Context

The 0.6.0 and 0.7.0 precision measurements evaluated rule findings across real-world PHP repositories. Several rules produced more false positives than true positives (precision below 50%) because their underlying signals require framework-specific or project-wide semantic context that file-local AST inspection cannot reliably infer. Tuning heuristic exemptions on sub-50%-precision rules added complexity without lifting precision above the coin-flip floor.

## Decision

gruff-php retires nine rules the 0.6.0 precision measurement found right less than half the time:

- `security.dependency-composer-unpinned`, right on 1 of 22 judged findings;
- `security.github-actions-risky-workflow`, right on 8 of 24;
- `security.insecure-random`, right on 5 of 25;
- `security.path-traversal-file-access`, right on 2 of 10;
- `security.sensitive-data-logging`, right on 7 of 25;
- `security.weak-crypto`, right on 8 of 25;
- `sensitive-data.hardcoded-env-value`, right on 4 of 18;
- `sensitive-data.high-entropy-string`, right on 9 of 25, with the built-in lockfile skip that existed only for it
  (FAMILY-CONTRACT.md section 12 and section 13a now bind gruff-rs alone);
- `sensitive-data.url-credentials`, right on 0 of 11.

Each rule's code is removed, along with the helpers only it used, its tests and fixtures, its dogfood config block and
the two dogfood `sensitiveExclusions` entries for the entropy rule. A `rules:` block that names one is ignored with a
warning. A `sensitiveExclusions` entry, a `selection.excludeRules` entry or an `--exclude-rule` flag that names one exits 2.

It also turns three rules off by default, each right less than half the time on fewer than ten findings, too few to
delete on: `security.dependency-composer-path` (wrong on both of its 2 judged findings),
`security.dependency-composer-vcs` (neither of its 2 judged findings was worth acting on) and
`sensitive-data.database-url-password` (wrong on all 9). They stay in the catalogue and run when a config enables them.

The family specification records the retirements as a catalogue transition with no successor, and keeps each rule's
review record as `retired` (workspace ADR-010).

## M02 retirement decision, 2026-10-14

The operator accepted all five M02 tables with "i approve, continue", including the 36 gruff-php rows marked delete, and
approved family lock M94 for the php line. Under M94, retire these additional rules:

| Rule | Judged | Right | Not worth | Wrong | Unresolved | Score | Current right findings removed |
|---|---:|---:|---:|---:|---:|---:|---:|
| `dead-code.unused-private-property` | 23 | 1 | 0 | 22 | 23 | 0.043 | 1 |
| `docs.stale-param-tag` | 37 | 10 | 0 | 27 | 1 | 0.270 | 10 |
| `modernisation.constructor-promotion-candidate` | 35 | 0 | 33 | 2 | 12 | 0.000 | 0 |
| `modernisation.first-class-callable-candidate` | 18 | 2 | 10 | 6 | 3 | 0.111 | 2 |
| `modernisation.forbidden-global-access` | 23 | 8 | 15 | 0 | 0 | 0.348 | 8 |
| `modernisation.match-expression-candidate` | 28 | 0 | 28 | 0 | 22 | 0.000 | 0 |
| `modernisation.mixed-type-overuse` | 30 | 5 | 0 | 25 | 16 | 0.167 | 5 |
| `modernisation.named-argument-opportunity` | 29 | 1 | 2 | 26 | 21 | 0.034 | 1 |
| `modernisation.phpdoc-mixed-overuse` | 35 | 3 | 0 | 32 | 15 | 0.086 | 3 |
| `naming.boolean-prefix` | 24 | 3 | 10 | 11 | 0 | 0.125 | 3 |
| `naming.generic-method` | 24 | 8 | 5 | 11 | 0 | 0.333 | 8 |
| `naming.hungarian-notation` | 23 | 2 | 12 | 9 | 0 | 0.087 | 2 |
| `naming.negative-boolean` | 22 | 0 | 21 | 1 | 3 | 0.000 | 0 |
| `naming.suffix-hungarian` | 24 | 1 | 21 | 2 | 0 | 0.042 | 1 |
| `security.variable-include` | 24 | 4 | 0 | 20 | 12 | 0.167 | 4 |
| `sensitive-data.phi-pattern` | 9 | 4 | 0 | 5 | 11 | 0.444 | 4 |
| `size.average-method-length` | 40 | 17 | 23 | 0 | 10 | 0.425 | 17 |
| `test-quality.conditional-logic` | 25 | 5 | 20 | 0 | 0 | 0.200 | 0 |
| `test-quality.eager-test` | 25 | 0 | 25 | 0 | 0 | 0.000 | 0 |
| `test-quality.excessive-mocking` | 25 | 9 | 16 | 0 | 0 | 0.360 | 0 |
| `test-quality.extends-production-class` | 22 | 0 | 0 | 22 | 3 | 0.000 | 0 |
| `test-quality.magic-number-assertion` | 25 | 0 | 25 | 0 | 0 | 0.000 | 0 |
| `test-quality.mock-only-test` | 25 | 0 | 25 | 0 | 0 | 0.000 | 0 |
| `test-quality.mock-without-expectation` | 25 | 0 | 11 | 14 | 0 | 0.000 | 0 |
| `test-quality.multiple-aaa-cycles` | 25 | 0 | 13 | 12 | 0 | 0.000 | 0 |
| `test-quality.mystery-guest` | 23 | 6 | 2 | 15 | 2 | 0.261 | 6 |
| `test-quality.skipped-without-reason` | 25 | 3 | 0 | 22 | 0 | 0.120 | 3 |
| `test-quality.sleep-in-test` | 25 | 6 | 19 | 0 | 0 | 0.240 | 0 |
| `test-quality.tautological-type-assertion` | 25 | 12 | 0 | 13 | 0 | 0.480 | 12 |
| `test-quality.test-method-too-long` | 25 | 0 | 24 | 1 | 0 | 0.000 | 0 |
| `test-quality.trivial-assertion` | 25 | 10 | 15 | 0 | 0 | 0.400 | 10 |
| `test-quality.unused-mock` | 13 | 5 | 8 | 0 | 0 | 0.385 | 0 |
| `waste.empty-class` | 25 | 0 | 25 | 0 | 0 | 0.000 | 0 |
| `waste.one-line-method` | 23 | 0 | 16 | 7 | 0 | 0.000 | 0 |
| `waste.redundant-variable` | 25 | 3 | 17 | 5 | 0 | 0.120 | 3 |
| `waste.unused-parameter` | 23 | 6 | 0 | 17 | 0 | 0.261 | 6 |

Scores come from each row's latest measurement: the surviving original labels, or M14's and M15's re-measures where those
owned the rule. A rule already off by default shows no current findings. The operator saw the full prospective loss list
before approving: 109 currently emitting findings labelled right.

The rule code, the helpers only they used, their tests, the 27 fixtures that served only them, and their dogfood config
blocks are removed; shared helpers and fixtures stay for the surviving rules. As before, a `rules:` block that names one
is ignored with a warning, and a `sensitiveExclusions` entry, a `selection.excludeRules` entry or an `--exclude-rule` flag
that names one exits 2. The family specification records the retirements as a catalogue transition with no successor and
keeps each review record as `retired` (workspace ADR-010).

It also turns five more rules off by default, each too thinly judged right to establish its precision floor: `dead-code.unused-private-method` (4 of 38), `security.dangerous-function-call` (3 of 6), `security.disabled-ssl-verification` (1 of 1, with the rest unresolved), `security.sql-concatenation` (4 of 11), `security.unsafe-unserialize` (2 of 8). They stay in the catalogue and run when a config enables them; their review records move to `shadow`, score-neutral, as M19's three did. `complexity.halstead-volume` and `test-quality.sut-not-called`, the table's other two default-off rows, were already off.

Four rules the table kept for repair are off by default after their repairs: `security.debug-mode-enabled` (3 findings left), `security.header-injection` (9 left), `security.silent-catch` (right on 13 of 25 after its repair) and `sensitive-data.pii-test-fixture` (35 of 47). The first two fall under their accepted rows' fewer-than-ten clause; the operator turned the other two off when their re-measures still failed. Their review records move to `shadow`, score-neutral, like the five above.

Workspace evidence: .goat-flow/plans/0.6.0-precision-floor/evidence/m02/table-answers.json (search: acceptedRows),
tables.json (search: php-003), table-recall.json (search: currentRightIds) and activation-approval-m94.json.

## Reversibility

A retired rule can return as a new, measured rule; `.goat-flow/plans/0.7.0-roadmap/rules-to-rebuild.md` in the workspace
keeps its wrong shapes and what a rebuilt rule needs. A rule turned off comes back on by flipping `isEnabledByDefault`
once a measurement on more findings puts it at half or better.

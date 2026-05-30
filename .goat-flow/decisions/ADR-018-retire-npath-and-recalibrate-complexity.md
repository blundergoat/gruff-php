# ADR-018: Retire npath and recalibrate the complexity pillar

**Status:** Accepted
**Date:** 2026-05-30
**Author(s):** Matthew Hansen
**Updated:** amends ADR-010

## Context

ADR-010 anchored the complexity defaults to industry violation/smell lines. ADR-017 then fixed the project mission — gruff governs AI-generated code so a human can verify it — and named "de-emphasising npath" as a follow-up. `complexity.npath` measures the multiplicative count of independent execution paths, so it explodes on sequential-but-simple branching: its *unique* findings are false positives (genuinely hard-to-verify code is already caught by `complexity.cognitive` and `complexity.nesting-depth`; test-surface by `complexity.cyclomatic`), and its cheapest fix is cosmetic. `src/Scoring/CompositeFindingFactory.php` already excluded `halstead-volume` and `maintainability-index` from the `design.god-method` complexity trigger, treating cognitive/cyclomatic/nesting/npath as the "real" complexity signals; this decision completes that direction.

## Decision

Retire `complexity.npath` entirely (breaking; rule-id removal, precedent ADR-014) and recalibrate the remaining complexity rules to the mission:

| Rule | Before | After |
| --- | --- | --- |
| `complexity.npath` | error @ 200 | **removed** |
| `complexity.halstead-volume` | error @ 8000 | **advisory** @ 8000 |
| `complexity.maintainability-index` | error @ 35 | **advisory** @ 35 |
| `complexity.cognitive` | error @ 30 | error @ **20** |
| `complexity.nesting-depth` | error @ 6 | error @ **4** |
| `complexity.cyclomatic` | error @ 20 | **warning** @ 20 |

Registry: 119 → 118 rules; complexity pillar 5 → 4. The `halstead-volume` and `maintainability-index` *computations* are retained (MI still consumes Halstead); only their severity changes. `design.god-method`'s complexity trigger becomes `{cognitive, cyclomatic, nesting}`.

End state: `cognitive` (error, 20) + `nesting` (error, 4) are the legibility hard-gates; `cyclomatic` (warning, 20) is a secondary signal that misranks legibility; `halstead-volume` + `maintainability-index` (advisory) are informational.

## Failure Mode Comparison

| Option | What fails | Verdict |
| --- | --- | --- |
| Keep npath at error | Forces cosmetic refactors on readable sequential branching; its cheapest fix is not the genuine improvement (ADR-017 corollary). | Rejected. |
| Demote npath to advisory instead of deleting | Keeps an opaque metric the author judged redundant with the trio. | Rejected for npath (it actively misleads); chosen for halstead/MI (opaque but not misleading). |
| Delete halstead + MI too | Sharper, but a further breaking change with no extra mission benefit now. | Deferred (documented stretch goal). |

## Consequences

- Breaking: a config block referencing `complexity.npath` now fails closed (unknown rule id → `ConfigException`); the CHANGELOG instructs users to remove it and regenerate baselines.
- Rule-count stamps move 119 → 118 (and complexity 5 → 4) across `README.md`, `.goat-flow/architecture.md`, `.goat-flow/code-map.md`, `docs/rules.md`, `composer.json`.
- Tightening `cognitive`→20 surfaces previously-passing dense methods; these are resolved or baselined, never silently suppressed.
- The config validator now accepts `severity: advisory` (previously only `warning`/`error`), since `halstead-volume` and `maintainability-index` default to advisory and `init` scaffolds each rule's default severity — without this, `gruff-php init` would emit a config the loader rejects.

## Reversibility

Two-way door for the severity recalibrations (advisory/warning are config-overridable). npath's removal is a one-way-ish breaking change, reversible only by re-adding the rule id in a future release. Rollback path: restore each rule's prior `SeverityThreshold` and re-register `NpathComplexityRule`.

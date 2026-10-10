# ADR-034: Rules wrong more often than right are retired, not tuned

**Status:** Accepted
**Date:** 2026-10-04
**Author(s):** Claude, user direction
**Ticket/Context:** precision-floor M19 under family lock M82. The operator ruled on 2026-10-04 that a rule right less than
half the time is deleted, and accepted the gruff-php table with "Accept all (Recommended)".

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

## Reversibility

A retired rule can return as a new, measured rule; `.goat-flow/plans/0.7.0-roadmap/rules-to-rebuild.md` in the workspace
keeps its wrong shapes and what a rebuilt rule needs. A rule turned off comes back on by flipping `isEnabledByDefault`
once a measurement on more findings puts it at half or better.

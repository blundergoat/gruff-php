# ADR-015: Per-Command MinimumSeverity Config Dimension

**Status:** Accepted
**Date:** 2026-05-26
**Ticket/Context:** Cross-port `minimumSeverity:` harmonisation (gruff-go 0.1.2 lands the sibling shape under ADR-010). gruff-php's `analyse` command currently defaults `--fail-on` to `error`, which is too lax for the user philosophy ("show everything, fail on anything for gating commands; never fail for viewing commands"). Plus: `.gruff-php.yaml` has no top-level `schemaVersion:` field at all, despite the project carrying explicit `SCHEMA_VERSION` constants in `AnalysisReport` and `BaselineStore` — a divergence this work closes.

## Decision

Add two top-level keys to `.gruff-php.yaml`:

1. **`schemaVersion:`** (required, introduced for the first time). Only accepted value today is `gruff-php.config.v0.1`. `ConfigLoader` hard-errors on configs missing the field, with a migration hint pointing at `gruff-php init --force`.

2. **`minimumSeverity:`** (optional). Per-command exit-code threshold for the three gating commands (`analyse | report | dashboard`). The validator rejects every other key (including `summary | init | list-rules`) with a useful error naming the valid keys and explaining that the rejected command does not gate exit code.

```yaml
schemaVersion: gruff-php.config.v0.1
minimumSeverity:
  analyse: advisory
  report: none
  dashboard: none
```

Accepted threshold values: `advisory | warning | error | none`. The validator rejects every other value (`never`, `off`, `disabled`, `medium`, `low`, `high`, `critical`, `info`, `notice`, `warn`) with a clear error listing the four canonical values.

**Precedence:** CLI `--fail-on` flag > `minimumSeverity.<cmd>` YAML key > binary default. The CLI always wins when explicitly set.

**`analyse` binary default lowered.** From `error` to `advisory`. Matches the cross-port default and the user philosophy. `CHANGELOG.md` `[Unreleased]` records this as a breaking change.

**`FailThreshold::None / 'none'` is retained.** gruff-php has shipped this value since project inception; no rename. `FailThreshold::cases()` returns `[None, Advisory, Warning, Error]` and `FailThreshold::fromInput` accepts only those four strings. M10 (test-only) locked this parser contract before M11 wired it.

**Cross-port consistency notes:**

- **Value vocabulary is aligned.** Both gruff-php and gruff-go 0.1.2 settled on `none` as the off-switch value. The original wording-brainstorm picked `never`; both ports independently reversed to `none` per user direction.
- **Schema-version state is aligned.** Both ports currently use `gruff-{php,go}.config.v0.1` as the canonical value. gruff-php is introducing the field for the first time at v0.1 (this ADR); gruff-go already had it (per ADR-010). Neither port bumps as part of this work.
- **Gating-commands surface area is deliberately divergent.** gruff-php validates 3 keys (`analyse | report | dashboard`); gruff-go 0.1.2 validates 4 (adds `summary`) because Go's `summary` command gates exit code while PHP's does not. Intentional, not port drift.

## Context

Pre-work state:

- `.gruff-php.yaml` has no top-level `schemaVersion:` field. The `ConfigLoader` accepts whatever shape it parses, without explicit versioning. The codebase carries `AnalysisReport::SCHEMA_VERSION` and `BaselineStore::SCHEMA_VERSION` constants but not a config-shape version — a gap that any future config-shape break would have to invent ad-hoc.
- The `analyse` command's binary `--fail-on` default is `error` (the most lax option). A project running `php bin/gruff-php analyse` with no flags only fails on `error`-tier findings. Warnings and advisories pass silently. This is at odds with the user philosophy ("show everything, fail on anything for gating commands").
- The `report` and `dashboard` commands already default to `'none'` (don't gate exit code) — those are correct; the gap is `analyse`.
- Three commands (`summary`, `init`, `list-rules`) do not gate exit code at all. Silent acceptance of `minimumSeverity.summary: error` would be a CI footgun: the user would expect CI to fail on errors, but the command never gates. The validator must reject those keys explicitly.

Sibling port (gruff-go 0.1.2) is landing the same `minimumSeverity:` shape under ADR-010 in the same week. Cross-port shape consistency is the headline goal: a user running `gruff-*` tools across multiple languages should see the same YAML key in each port's config.

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| A. Lower the `analyse` default to `advisory` without adding `minimumSeverity:` | Closes the philosophy gap for the binary default but leaves no per-project override knob other than passing `--fail-on` on every invocation. | Rejected. Half the fix. |
| B. Add `minimumSeverity:` without introducing `schemaVersion:` | The config still has no version field. Future config-shape breaks have no migration anchor. The cross-port divergence stays (gruff-go has the field; gruff-php doesn't). | Rejected. Cheaper to introduce the field now while the schema is settling. |
| C. Add both, with soft-landing on missing `schemaVersion:` | Migration tooling complexity for an additive change. Users who hand-edit `.gruff-php.yaml` will not notice the field is missing until a future schema break, at which point the error message becomes more confusing. | Rejected. Hard-error keeps the contract sharp. |
| D. Add both with hard-error on missing `schemaVersion:` and reject all non-gating command keys with explicit errors | Breaks every existing `.gruff-php.yaml` at upgrade until users add the `schemaVersion:` line; that is intentional and consistent with the pre-public-adoption schema window. | **Accepted.** Sharpest contract; matches the gruff-go ADR-010 stance on cross-port harmonisation; pays the migration cost while the user surface is smallest. |
| E. Allow `summary | init | list-rules` keys silently (no-op when the command doesn't gate) | The CI footgun materialises immediately: a user writes `minimumSeverity.summary: error` expecting CI to fail; the command silently runs and exits 0; the user trusts the green build. | Rejected. Silent no-op is the worst UX shape. |

## Consequences

- **`schemaVersion:` becomes required.** Every existing `.gruff-php.yaml` fails to load until `schemaVersion: gruff-php.config.v0.1` is added at the top. The `ConfigLoader::applySchemaVersion` error message points users at `gruff-php init --force` to regenerate. `CHANGELOG.md` `[Unreleased]` documents this as a breaking change.
- **`minimumSeverity:` block is optional.** Configs without it parse cleanly; the binary defaults apply.
- **Validator rejects non-gating command keys.** `minimumSeverity.summary: advisory`, `minimumSeverity.init: error`, `minimumSeverity.list-rules: warning` all fail validation with an error message naming the three valid keys (`analyse | report | dashboard`) and explaining that the rejected command does not gate exit code.
- **Validator rejects unknown threshold values.** `medium`, `never`, `off`, `disabled`, `low`, `high`, `critical`, `info`, `notice`, `warn` all fail validation with an error listing the four canonical values.
- **`analyse` binary default changes.** From `error` to `advisory`. Projects relying on the previous default see exit code 1 from warning/advisory findings that previously exited 0. Pass `--fail-on error` on the CLI to restore the old behaviour, or set `minimumSeverity.analyse: error` in `.gruff-php.yaml`. `CHANGELOG.md` `[Unreleased]` records this as breaking.
- **`gruff-php init` emits both new keys.** `--force` regeneration preserves a user's hand-edited `minimumSeverity:` block. The scaffold places `schemaVersion:` first and `minimumSeverity:` after `minimumPhpVersion:` and before `paths:`.
- **Dashboard form default consults the config.** The form's `failOn` `<select>` reads from `AnalysisConfig::failThresholdFor('dashboard')` before falling back to the binary default `none`. The HTML option list itself is unchanged (the `none` option already exists).
- **Three docs files updated.** `docs/configuration.md`, `docs/ci-integration.md`, and `docs/dashboard.md` all reference `minimumSeverity:`, the `none` value, the rejection contract, and the precedence rule.
- **Lockstep contract extended.** `.goat-flow/footguns/schemas.md` and/or `.goat-flow/footguns/rules.md` gain `minimumSeverity:` and `schemaVersion:` as bump-sites that future config-schema changes must touch.

## Reversibility

**One-way door for the schemaVersion introduction.** Reverting would require:

1. `git revert` the M10-M13 commits (mechanical).
2. Every project that added `schemaVersion: gruff-php.config.v0.1` to their `.gruff-php.yaml` would need to remove it (manual; the parser would no longer recognise the key after revert).
3. Every project that lowered their `analyse` default by setting `minimumSeverity.analyse: error` to restore the old behaviour would need to remove that block too.

The first cost is bounded; the second and third have not yet materialised because the work has not shipped.

**Revisit trigger:** if cross-port value-vocabulary harmonisation reverses (e.g., gruff-rs / gruff-ts / gruff-py decide to migrate from `none` to `never`), the "Value vocabulary is aligned" note above becomes the canonical record of the prior state. Update this ADR in-place rather than writing a successor; the design dimension and naming are stable even if a single value name changes later.

## References

- Wording brainstorm: `/home/devgoat/projects/gruff-workspace/gruff-go/.goat-flow/logs/critiques/2026-05-26-config-wording-brainstorm-b5k2x.md`
- Sibling ADR: `/home/devgoat/projects/gruff-workspace/gruff-go/.goat-flow/decisions/ADR-010-per-command-minimum-severity.md`
- Task package: `.goat-flow/tasks/0.1.4/minimum-severity-overview.md`
- ADR-008 (single-threshold rubric severity): `.goat-flow/decisions/ADR-008-single-threshold-rubric-severity.md`

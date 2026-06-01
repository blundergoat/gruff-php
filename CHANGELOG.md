# Changelog

Notable user-facing changes to `gruff-php` are listed here.

This project is still pre-1.0, so minor releases may break behaviour. Breaking
changes are marked and include the action to take.

## 0.3.0 - 2026-05-31

0.3.0 focuses on agent-friendly CI: scan only changed code, respect ignored paths everywhere, and fail on newly introduced debt instead of old baseline debt. It also removes noisy complexity/design checks and tightens the rules that support human review of AI-written code.

- **Changed-code scanning** - `analyse` can now report only findings tied to edited ranges or symbols. JSON reports say how many older findings were suppressed.
- **Ignore handling is stricter** - `paths.ignore` now applies to explicit files, diff scans, and changed-region scans. `check-ignore` explains why a path is ignored.
- **Better baseline reporting** - Baseline output now separates findings into `new`, `unchanged`, and `resolved`, so teams can see debt movement instead of only muting old issues.
- **Count-based gates** - `failureConditions:` can fail a run by total finding count or by advisory/warning/error counts. Existing `--fail-on` behaviour still works.
- **New-findings gate** - `--fail-on-new` fails only on findings introduced by the current change. It requires a baseline, `--diff-vs`, or both.
- **Incremental cache** - Eligible runs reuse findings for unchanged files through `.gruff-cache/`. Runs that need whole-project analysis still bypass the cache.
- **Config presets** - `extends: gruff.recommended`, `gruff.starter`, or `gruff.strict` can replace most local config boilerplate.
- **BREAKING: Removed `complexity.npath`** - Delete any `rules.complexity.npath` config and regenerate baselines. The rule was noisy and overlapped with clearer complexity metrics.
- **BREAKING: Removed synthetic `design.god-method` findings** - Size and complexity findings still report normally, but gruff no longer adds a duplicate design finding. Remove stale baseline entries.
- **Fairer scoring** - Related size and complexity findings on the same method now count as one scoring penalty, while still appearing as separate findings.
- **Boolean-name allowlist** - `naming.boolean-prefix` can now accept intentional names like `valid()` without forcing a public API rename.
- **Complexity rules recalibrated** - Halstead volume and maintainability index are advisory; cognitive and nesting thresholds are tighter; cyclomatic complexity is warning-level.
- **Fake-test rules are stricter** - Tests with no real assertion, no subject call, or tautological type assertions now fail at error level.
- **Config supports `advisory`** - Rule severity overrides now accept `advisory`.
- **More dead-code checks** - gruff can now flag unused private constants and unused project-owned internal classes, functions, and constants.
- **More secret checks** - gruff now detects GCP service-account keys and credentials embedded in HTTP(S) URLs, with reporter tests to keep raw secrets redacted.
- **Mission documented** - README, docs, agent instructions, and ADR-017 now state the project goal: help humans verify AI-written code.
- **PHPDoc mixed rule relaxed** - Nullable JSON bags such as `array<string, mixed>|null` no longer trigger `phpdoc-mixed-overuse`.
- **Internal cleanup** - Large command and analysis classes were split up. CLI behaviour and output schemas are unchanged.
- **`docs.return-comment` changed meaning** - Same rule id, new behaviour: value-returning `@return` tags need a real description. Baselines may shift.
- **`docs.missing-param-tag` covers more methods** - Documented private/protected methods and functions now need `@param` tags. Baseline old gaps if needed.

## 0.2.0 - 2026-05-28

0.2.0 makes CI policy more explicit, adds rule triage help, and introduces several breaking config/schema changes.

- **BREAKING: `schemaVersion:` is required** - Add `schemaVersion: gruff-php.config.v0.1` to `.gruff-php.yaml`, or run `gruff-php init --force`.
- **BREAKING: `analyse --fail-on` default changed** - `analyse` now fails on advisory findings by default. Pass `--fail-on error` or set `minimumSeverity.analyse: error` to keep the old gate.
- **BREAKING: JSON schemas moved to v2** - `summary` and `analyse` now emit v2 schemas and singular severity count keys. Update JSON consumers.
- **BREAKING: Removed `naming.parameter-type-name`** - Delete any config for this rule. Findings disappear automatically.
- **BREAKING: `waste.one-line-method` defaults tightened** - Most projects see fewer findings. Pin the old options only if you need the old behaviour.
- **Per-command severity config** - `minimumSeverity:` lets config set fail thresholds for `analyse`, `report`, and `dashboard`.
- **Visibility-only rules** - `excludeFromScore: true` keeps a rule visible in reports without affecting scores.
- **Rule triage help** - `list-rules <ruleId>` now shows options, escape hatches, and false-positive notes. Large text reports point users toward `summary`.
- **Stable finding identity** - JSON adds a line-shift-resistant `stableIdentity` field for external diff tooling.
- **Fewer mixed-type false positives** - Precise `array{...}` PHPDoc shapes with useful sibling fields no longer trip `phpdoc-mixed-overuse`.
- **Cleaner first run** - `init` now seeds common abbreviations, reports are easier to scan, and rule messages point at config escape hatches.
- **Bug fixes** - Fixed report/dashboard fail-threshold loading, abbreviation defaults, and `@param-out` / `@param-immutable` handling.
- **Regression tests** - Added coverage for fail-threshold parsing, precedence, report hints, and new accessors.

## 0.1.3 - 2026-05-24

Patch release for Composer installs.

- **Installed binary fixed** - `vendor/bin/gruff-php` now finds Composer's generated autoload path in consuming projects.
- **Packaging regression test** - Tests now cover installing and running `vendor/bin/gruff-php init` from a throwaway project.

## 0.1.2 - 2026-05-24

Harness and documentation maintenance for goat-flow 1.7.0.

- **Agent instructions updated** - Codex and Claude docs now use the packaged goat-flow audit CLI and list the real project quality surface.
- **Security references cleaned up** - Old goat-security stubs now redirect to the current identity/data and supply-chain/CICD references.
- **Architecture docs refreshed** - Code map and architecture docs now cover the current CLI and local workflow.
- **Hook fixtures fixed** - Dangerous-command hook tests now use the real fixture repository name.
- **Symfony YAML range widened** - Runtime support now matches the other Symfony components: `^6.4 || ^7.0 || ^8.0`.

## 0.1.1 - 2026-05-24

Onboarding-focused follow-up to 0.1.0.

- **`init` command added** - `gruff-php init` creates `.gruff-php.yaml`, preserves ignore patterns with `--force`, and supports `--project-root`.
- **Missing-config prompt** - TTY runs can offer to create config before scanning.
- **More test-quality rules enabled** - New default advisory rules catch common weak-test patterns.
- **Baseline guidance added** - `summary` now points users to baseline generation and no-baseline audit modes.
- **Dependency audit added** - Composer audit now runs in `composer check` and CI.
- **Docs expanded** - README and docs now cover rules, CI, config, output formats, dashboard, naming, and release process.

## 0.1.0 - 2026-05-23

First public release.

- **120 rules** - Coverage spans size, complexity, maintainability, dead code, naming, docs, modernisation, security, sensitive data, test quality, and design.
- **Five commands** - `analyse`, `summary`, `report`, `dashboard`, and `list-rules`.
- **Seven output formats** - `text`, `json`, `html`, `markdown`, `github`, `hotspot`, and `sarif`.
- **Strict YAML config** - `.gruff-php.yaml` supports baselines, branch review, mutation analysis, and dashboard settings.
- **PHP 8.3 and MIT** - Minimum runtime is PHP 8.3.0; license is MIT.

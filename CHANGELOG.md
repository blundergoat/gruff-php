# Changelog

All notable user-visible changes to `gruff-php` are documented here. The shape
is themed-narrative: a `## X.Y.Z - YYYY-MM-DD` heading, a one-paragraph intro
naming the release theme, and themed bullets (`- **{Theme}** - {what changed}`)
where each `BREAKING:` theme carries an inline migration path.

While the project remains on 0.x, [semver][semver]'s post-1.0 stability
guarantees do not apply: minor bumps may break. The `BREAKING:` marker and
migration path are still required so callers know exactly what to update.
End-user release narrative for the latest version lives in
[`.goat-flow/scratchpad/release.md`](.goat-flow/scratchpad/release.md).
Development builds report a `-dev` suffix until `scripts/bump-version.sh`
stamps the tag.

[semver]: https://semver.org/

## 1.0.0 - 2026-05-30

First stable release. gruff-php sharpens around a single mission — governing AI-generated code so a human who didn't write it can read, verify, and trust it — and commits to a stable rule and schema surface. The headline breaking change retires the noisy `complexity.npath` rule and recalibrates the complexity pillar toward the metrics that track human comprehension; changed-region analysis lands so coding-agent hooks can gate only the lines they touched.

- **Changed-region analysis** - `analyse` now accepts `--changed-ranges`, `--since`, bare `--diff`, `--diff -`, and `--changed-scope=symbol|hunk` so hook consumers can request only findings attributable to the edited region. JSON reports include `suppressedCount` when this mode filters out pre-existing findings.
- **Ignore reasons and `check-ignore`** - the JSON report's new additive `ignoredPathDetails` field records why each path was excluded — its `source` (`config`, `default`, `generated`, or `gitignore`) and matching `pattern` — alongside the existing `ignoredPaths` list. A new `check-ignore [--format text|json] [--config <path>|--no-config] <path>...` command answers whether gruff would ignore a path, and why, without running an analysis (JSON `[{path, ignored, source, pattern}]`; exit codes mirror `git check-ignore`). `paths.ignore` stays authoritative in every mode — explicit file operands and all diff/changed-region scans, not just the directory walk — and `--include-ignored` never overrides it.
- **BREAKING: Retired the `complexity.npath` rule** - NPath produced false positives on sequential-but-simple branching and is redundant with the cognitive, cyclomatic, and nesting metrics. Remove any `complexity.npath` block from your config (it now fails closed as an unknown rule id) and regenerate baselines to drop stale npath findings. The registry now exposes 118 rules.
- **Complexity recalibration** - `complexity.halstead-volume` and `complexity.maintainability-index` are now `advisory` (informational, non-gating); `complexity.cognitive` tightened to a threshold of 20 and `complexity.nesting-depth` to 4; `complexity.cyclomatic` is now `warning`. The complexity pillar now gates on the metrics that track human comprehension rather than branch-counting proxies.
- **Config accepts `advisory` severity** - the `rules.<id>.severity` config key now accepts `advisory` alongside `warning` and `error`, so rules that default to advisory can be pinned or overridden in `.gruff-php.yaml`.
- **Mission documented** - a stated project mission (governing AI-generated code for human verifiability) now anchors `README.md`, `docs/mission.md`, and the agent instructions, recorded in ADR-017.

## 0.2.0 - 2026-05-28

gruff-php 0.2.0 tightens the CI gating philosophy, requires explicit config-schema versioning, and adds a per-rule triage surface so large scans stop being overwhelming. Five breaking changes (`schemaVersion:` required, `analyse` default lowered, JSON schemas v2, one rule retired, `waste.one-line-method` defaults tightened) motivate the minor bump from 0.1.x; each ships with a migration path below.

- **BREAKING: `schemaVersion:` required in `.gruff-php.yaml`** - Every config must declare `schemaVersion: gruff-php.config.v0.1` at the top, or `gruff-php analyse` and friends refuse to load with `Config key "schemaVersion" is required. Add 'schemaVersion: gruff-php.config.v0.1' to the top of .gruff-php.yaml, or regenerate with 'gruff-php init --force'.` Migration: add the line by hand, or run `gruff-php init --force` (preserves your existing `paths.ignore`, rule tunings, and `allowlists`). Rationale and pre-public-adoption window in [ADR-015](.goat-flow/decisions/ADR-015-per-command-minimum-severity.md).
- **BREAKING: `analyse --fail-on` default lowered from `error` to `advisory`** - CI jobs that previously exited 0 on advisory- or warning-tier findings now exit 1 by default. Matches the cross-port "show everything, fail on anything for gating commands" philosophy. Migration: pass `--fail-on error` on every CI invocation, or pin `minimumSeverity.analyse: error` in `.gruff-php.yaml` to restore the prior gate. To opt into the new default explicitly, pin `minimumSeverity.analyse: advisory`.
- **BREAKING: `summary` and `analyse` JSON schemas bumped to v2** - `summary` is now `gruff.summary.v2`, `analyse` is now `gruff.analysis.v2`. Per-severity counts in `findings`, `pillars`, and `topOffenders` payloads switch from plural (`advisories` / `warnings` / `errors`) to singular (`advisory` / `warning` / `error`). Migration: update consumer parsers for both the schemaVersion literal and the key renames. No backward-compatible aliases ship; v1 consumers must update.
- **BREAKING: `naming.parameter-type-name` retired** - Rule class, fixture, `RuleRegistry::NAMING_RULE_PRIORITY` slot, `docs/rules.md` row, and dogfood tuning block deleted. Adopters with a `rules.naming.parameter-type-name` block in their config hit `Unknown rule id "naming.parameter-type-name".` at load time. Migration: delete the block from `.gruff-php.yaml`; findings disappear from baselines and reports automatically. The gruff-py port retires the sibling in lockstep (their ADR-018). PHP naming rules drop 12 to 11. Rationale in [ADR-014](.goat-flow/decisions/ADR-014-retire-naming-parameter-type-name.md).
- **BREAKING: `waste.one-line-method` defaults tightened** - `minInFileCallers: 0 → 2`, `namedAlternativeFactoryExempt: false → true` (matches gruff-php's own self-tuning). Most projects see *fewer* findings: named factory pairs like `Money::fromCents()` / `Money::fromDollars()` and same-file wrappers stop reporting. Migration: no action required for adopters relying on defaults. To preserve the prior loose defaults explicitly, pin `options.minInFileCallers: 0` and `options.namedAlternativeFactoryExempt: false` under `rules.waste.one-line-method`.
- **Per-command exit-code thresholds** - New `minimumSeverity:` block in `.gruff-php.yaml` pins CI policy per command (`analyse | report | dashboard`) once instead of remembering `--fail-on` on every invocation. Accepted values: `advisory | warning | error | none`. Non-gating keys (`summary`, `init`, `list-rules`) and non-canonical values (including gruff-go's `never`) are rejected with explicit errors. Precedence: explicit CLI `--fail-on` > YAML > binary default. `AnalysisConfig::failThresholdFor()`, `ConfigLoader::SCHEMA_VERSION`, and `ConfigLoader::GATING_COMMANDS` are the new accessor / constants.
- **Visibility-only rule tier (`excludeFromScore`)** - `rules.<id>.excludeFromScore: true` keeps a rule running and surfaces its findings in every report, but those findings stop penalising the composite/pillar score. Distinct from `enabled: false` (which silences the rule entirely). Composite findings honour the flag transitively via `metadata.componentRules`. Useful when a team has decided a rule is informational but still wants to see what it catches. Design and failure-mode comparison in [ADR-016](.goat-flow/decisions/ADR-016-visibility-only-rule-scoring-tier.md).
- **Per-rule triage surface for large scans** - `list-rules <ruleId>` renders default options, escape-hatch config paths (`rules.<id>.options.*`, `enabled`, `excludeFromScore`), and catalogued false-positive shapes for the named rule; typos suggest near matches via Levenshtein and exit 2. `analyse --format=text` adds a footer hint pointing at `gruff-php summary` when findings ≥ 50. Branch-review reporters (`TextReporter` + `MarkdownReporter`) render "Top 5 improved / Top 5 regressed" rules before the composite score; `BranchReviewResult::perRuleDelta()` is the JSON-exposed source. `AnalysisReport::findingCountsByRule()` is the new accessor for triage views.
- **Line-shift-resilient finding identity** - `Finding::stableIdentity` is a 16-character SHA-256 sibling to `fingerprint`, keyed by `[ruleId, file, symbol]` (or `[ruleId, file, message]` when symbol is null). Line-insensitive so external diff tooling can track "the same finding" across unrelated edits. Baselines and SARIF stay keyed on `fingerprint`; the new field is additive metadata in JSON output.
- **Precise `array{...}` envelope exemption** - `modernisation.phpdoc-mixed-overuse` no longer fires on shapes like `array{entries: list<array<string, mixed>>, total: int|null, complete: bool}` when at least one named sibling field has a non-mixed type. Loose shapes still fire: `array<string|int, mixed>`, `Collection<mixed>`, `array{value: mixed}`.
- **Cleaner first-run experience** - `init` now scaffolds default accepted abbreviations for `naming.abbreviation-allowlist` (`id`, `url`, `db`, etc.) so fresh `.gruff-php.yaml` files no longer flood with universal tokens. HTML and Markdown reporters render per-pillar summaries as a table with per-severity columns instead of single-count rows. 11 rule remediations now name their escape-hatch config path ("If this is intentional, add it to `rules.<id>.options.<key>` in `.gruff-php.yaml`") so users can act on a finding without grepping for the right knob.
- **Config and CLI wiring fixes** - `ReportCommand::resolveFailOn()` now correctly detects whether `--fail-on` was passed explicitly (via `hasParameterOption`) instead of treating the option's `'none'` default as explicit; `minimumSeverity.report` actually takes effect now. `DashboardStateFactory::loadConfigFailThreshold()` reads `.gruff-php.yaml` from the resolved `--project-root` instead of `getcwd()`. `AnalysisConfig` no longer clobbers universal `acceptedAbbreviations` defaults when a user's `allowlists:` block declares only `secretPreviews`. `MissingParamTagRule` no longer treats `@param-out` or `@param-immutable` as `@param` matches (word-boundary check prevents PHPStan/Psalm out-only tags from suppressing missing-input-param findings).
- **Regression coverage for the new contracts** - `tests/Reporting/FailThresholdTest.php` locks the `FailThreshold::fromInput` parser contract; `tests/Console/AnalyseMinimumSeverityPrecedenceTest.php` covers the precedence chain end-to-end; `tests/Analysis/AnalysisReportTest.php`, `tests/Reporting/TextReporterTest.php`, and `tests/Review/BranchReviewResultTest.php` cover the new accessors and volume-hint behaviour.

## 0.1.3 - 2026-05-24

Patch release for the installed Composer binary bootstrap.

- **`vendor/bin/gruff-php` works in consuming projects** - The installed binary now prefers Composer's generated `_composer_autoload_path` before source-checkout fallbacks. Unblocks `composer require --dev blundergoat/gruff-php` followed by `vendor/bin/gruff-php init`, which previously failed at the autoload lookup.
- **Regression test for the packaged dependency layout** - New test installs `gruff-php` into a throwaway consumer project and runs `vendor/bin/gruff-php init`, so the packaged path is covered separately from `php bin/gruff-php` source-checkout runs.

## 0.1.2 - 2026-05-24

Harness and documentation maintenance for goat-flow 1.7.0.

- **Updated instruction files for the goat-flow 1.7.0 audit CLI** - Codex and Claude instructions now use the packaged `@blundergoat/goat-flow` audit CLI and list the real app/quality surface (`.gruff-php.yaml`, `phpstan.neon.dist`, scripts, `package-lock.json`, GitHub workflows).
- **Consolidated goat-security reference stubs** - Legacy Claude `goat-security` reference files now redirect to `identity-and-data.md` and `supply-chain-and-cicd.md`, preventing stale guidance from loading accidentally.
- **Refreshed architecture and code-map docs** - Coverage for the current CLI surface (the `init` command, dedicated Console test files, `skill-playbooks` routing, and resolved CLI footguns around config creation).
- **Hook self-tests use real fixtures** - `dangerous-command` hook self-tests now use the real `healthkit/healthkit` repository identifier instead of `example-org/example-repo` placeholders.
- **Broadened symfony/yaml constraint** - Runtime constraint now matches the other Symfony components: `^6.4 || ^7.0 || ^8.0`.

## 0.1.1 - 2026-05-24

Onboarding-focused follow-up to 0.1.0.

- **`init` scaffolds `.gruff-php.yaml` from registry defaults** - New `gruff-php init` command writes a config with registry defaults plus a curated `paths.ignore` list (agent harness dirs, generated reports, fixtures, vendored copies). `--force` regeneration preserves any existing `paths.ignore`; `init` refuses to silently shadow a legacy `.gruff.yaml` without `--force`. `--project-root <dir>` writes into a directory other than the current shell.
- **Interactive missing-config prompt** - When `analyse`, `summary`, `report`, or `dashboard` runs in a TTY against a project without `.gruff-php.yaml` or `.gruff.yaml`, the command offers to run `init`. The prompt fires only after option validation so malformed invocations no longer leave a stray config file behind, and prompt chatter routes to STDERR so JSON, SARIF, and HTML payloads on STDOUT stay parseable.
- **Test-quality rules enabled by default** - `test-quality.multiple-aaa-cycles` (minCycles 3), `test-quality.mocking-domain-object`, and `test-quality.testdox-readability` (minWords 2) now run unless explicitly disabled. Existing projects see new advisory findings on these rules after upgrade; configure or disable per project as needed.
- **Baseline guidance in `summary` output** - Text output now points users at `analyse --generate-baseline` to record current findings as known debt, and `--no-baseline` to audit without one.
- **Composer dependency audit in `composer check` and CI** - `composer audit:dependencies` runs inside `composer check` and the CI verify job, failing the build on known security advisories in the lockfile. `scripts/dependency-install.sh` and `dependency-update.sh` wrap the Composer commands used during installs and refreshes; the release preflight script is stricter.
- **README and `docs/` rewrite** - New documentation covers rule catalogue (`docs/rules.md`), CI integration (`docs/ci-integration.md`), configuration reference (`docs/configuration.md`), output formats (`docs/output-formats.md`), dashboard usage (`docs/dashboard.md`), naming conventions (`docs/naming-conventions.md`), and the release process (`docs/releasing.md`).

## 0.1.0 - 2026-05-23

First public release.

- **120 rules across 11 pillars** - Coverage spans size, complexity, maintainability, dead-code, naming, documentation, modernisation, security, sensitive-data, test-quality, and design. Run `php bin/gruff-php list-rules` to inspect the catalogue.
- **Five commands** - `analyse`, `summary`, `report`, `dashboard`, `list-rules`. Each carries `--help` for option discovery.
- **Seven output formats with stable schemas** - `text`, `json`, `html`, `markdown`, `github`, `hotspot`, `sarif`. Initial machine-readable schemas: `gruff.analysis.v1`, `gruff.summary.v1`, `gruff.baseline.v1`.
- **YAML config with strict unknown-key rejection** - `.gruff-php.yaml` supports baselines, branch-review (`--diff`, `--diff-vs`, `--changed-only`), opt-in Infection mutation analysis, and a local dashboard. Unknown top-level keys fail to load.
- **PHP `^8.3`, MIT licensed** - Minimum runtime is PHP 8.3.0; the project is MIT-licensed.

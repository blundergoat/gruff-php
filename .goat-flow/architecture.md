# Architecture - gruff-php

Last reviewed 2026-05-09. All claims map to a real file in `src/`, `tests/`, or top-level config; cross-check before broadening any of them.

## System Overview

`gruff-php` is a Composer-distributed PHP CLI for opinionated code-quality analysis. The package boundary is `composer.json`: it declares dependencies (`nikic/php-parser`, `symfony/console`, `symfony/finder`, `symfony/process`), the `bin/gruff` entrypoint, the `GruffPhp\` PSR-4 root, and the `check`, `phpstan`, and `test` Composer scripts. The runtime is a single `analyse` Symfony Console command that discovers source files, parses PHP through `nikic/php-parser`, runs a deterministic registry of rules, optionally ingests Infection mutation JSON, and emits a schema-versioned report (`gruff.analysis.v1`) in either grouped text or JSON.

The agent harness is intentionally separate from the app. `.goat-flow/` holds durable project knowledge and tool playbooks; `.claude/`, `.codex/`, and `.agents/skills/` hold the per-agent skill, hook, and settings surfaces. Harness changes do not touch the analyser binary or the Composer package.

## Layered Composition

| Layer | Purpose | Key files |
| --- | --- | --- |
| Entry | Boot autoloader and the Symfony Console app | `bin/gruff`, `src/Console/Application.php` |
| Command | Parse CLI flags, orchestrate the run, render | `src/Command/AnalyseCommand.php`, `src/Reporting/*` |
| Discovery | Resolve user paths to source files | `src/Source/SourceDiscovery.php`, `src/Source/SourceFile.php`, `src/Source/SourceDiscoveryResult.php` |
| Parsing | Produce AST + tokens or per-file diagnostics | `src/Parser/PhpFileParser.php`, `src/Parser/AnalysisUnit.php`, `src/Parser/ParseDiagnostic.php` |
| Configuration | Resolve per-rule enable/threshold settings | `src/Config/ConfigLoader.php`, `src/Config/AnalysisConfig.php`, `src/Config/RuleSettings.php` |
| Rules | Emit findings from `AnalysisUnit` + `RuleContext` | `src/Rule/RuleRegistry.php`, `src/Rule/{Size,Complexity,DeadCode,Waste,Naming,Docs,Modernisation,Security,Secrets,TestQuality}/*` |
| Mutation | Parse optional Infection output and emit mutation findings | `src/Mutation/*` |
| Findings & Report | Stable typed payload + summary aggregation | `src/Finding/*`, `src/Analysis/AnalysisReport.php`, `src/Analysis/RunDiagnostic.php` |
| Reporting | Render the report for humans or machines | `src/Reporting/TextReporter.php`, `src/Reporting/JsonReporter.php`, `src/Reporting/OutputFormat.php`, `src/Reporting/FailThreshold.php` |

## Request Flow

The current request flow is CLI-only.

1. `bin/gruff` runs `(new \GruffPhp\Console\Application())->run()` after loading `vendor/autoload.php`.
2. `Application` (Symfony Console subclass) registers the single `analyse` command with version constant `0.1.0-dev`.
3. `AnalyseCommand::execute()` reads the working directory, paths argument, and `--config`, `--format`, `--fail-on`, `--include-ignored`, `--infection-report`, `--infection-run`, `--infection-bin`, `--infection-config`, `--mutation-baseline`, and `--mutation-budget` options, validating `--format`, `--fail-on`, and mutation budget input up front.
4. `RuleRegistry::defaults()` constructs the v0.1 catalogue (sorted by id via `ksort`).
5. `ConfigLoader::load()` produces an `AnalysisConfig` from the registry defaults, then overlays `.gruff.json` (or the explicit `--config` path); unknown root keys, invalid `minimumPhpVersion`, rule ids, rule keys, threshold names, and non-numeric thresholds throw `ConfigException`, which becomes a `config-error` `RunDiagnostic`.
6. `SourceDiscovery::discover()` expands the input paths (defaulting to `.`), records missing inputs, applies the default ignored-directory list, and yields `SourceFile` values typed `php` or `text`.
7. For each discovered file, `PhpFileParser::parse()` reads the source. PHP files are parsed by `nikic/php-parser` and decorated with a `ParentConnectingVisitor`; non-PHP text/config files short-circuit to an `AnalysisUnit` with raw source but no AST or tokens. Parse failures produce one `ParseDiagnostic` per error and are surfaced as `parse-error` `RunDiagnostic` entries.
8. `RuleRegistry::analyse()` skips units with parse errors, then iterates the enabled rules. PHP-only rules run only against `SourceFile::isPhp()` units; rules implementing `SourceTextRuleInterface` also run against text/config units, so secret/PII scanners cover JSON, YAML, env, and similar files. Findings from all units are sorted by `(filePath, line, ruleId, message)` for deterministic output.
9. If `--infection-report` is supplied, `InfectionReportParser` ingests the full Infection JSON report, calculates per-file mutation summaries, and `MutationFindingFactory` appends `mutation.survived-mutant`, `mutation.budget-exceeded`, and `mutation.msi-regression` findings where applicable. `--infection-run` is explicit opt-in and only shells out through `InfectionRunner`; it requires a report path because Infection controls full JSON log output through its config.
10. `AnalyseCommand` builds an `AnalysisReport` with tool/run metadata, summary counts, ignored/missing paths, diagnostics, findings, and optional mutation data, then renders it via `TextReporter` or `JsonReporter`.
11. `resolveExitCode()` returns `Command::INVALID` (`2`) if any `RunDiagnostic` was recorded, `Command::FAILURE` (`1`) when at least one finding satisfies `--fail-on`, and `Command::SUCCESS` (`0`) otherwise.

Scoring, dashboard, general baselining, and source diff-mode flow remain owned by later v0.1 milestones. Mutation-specific baseline MSI comparison exists through `--mutation-baseline`.

## Rule Catalogue

The default static rule set covers ten pillars (`Size`, `Complexity`, `Maintainability`, `DeadCode`, `Naming`, `Documentation`, `Modernisation`, `Security`, `Secrets`, `TestQuality`). Infection ingestion can also emit `Mutation` pillar findings. All emitted rules are tier `v0.1`; `Coupling`, `Design`, and `Architecture` remain reserved.

| Pillar | Rule ids | Notes |
| --- | --- | --- |
| Size | `size.file-length`, `size.class-length`, `size.method-length`, `size.average-method-length`, `size.parameter-count`, `size.property-count`, `size.public-method-count` | Threshold-driven; warn/error pair where applicable |
| Complexity | `complexity.cognitive`, `complexity.cyclomatic`, `complexity.halstead-volume`, `complexity.maintainability-index`, `complexity.nesting-depth`, `complexity.npath` | `maintainability-index` reports on the `Maintainability` pillar; `halstead-volume` informs the maintainability-index calculation |
| DeadCode | `dead-code.unused-private-method`, `dead-code.unused-private-property` | Class-local; conservative to avoid framework/inheritance false positives |
| Waste | `waste.commented-out-code`, `waste.empty-class`, `waste.empty-method`, `waste.unreachable-code`, `waste.unused-import`, `waste.unused-parameter` | AST-driven |
| Naming | `naming.boolean-prefix`, `naming.class-file-mismatch`, `naming.confusing-name`, `naming.generic-method`, `naming.hungarian-notation`, `naming.short-variable`, `naming.test-naming-consistency` | Mix of identifier conventions and class/file alignment |
| Documentation | `docs.missing-public-phpdoc`, `docs.missing-param-tag`, `docs.missing-return-tag`, `docs.missing-throws-tag`, `docs.stale-param-tag`, `docs.useless-phpdoc`, `docs.todo-density`, `docs.missing-readme` | `docs.missing-readme` looks at `<projectRoot>/README.md` and is independent of the unit being analysed |
| Modernisation | `modernisation.constructor-promotion-candidate`, `modernisation.enum-candidate`, `modernisation.first-class-callable-candidate`, `modernisation.forbidden-global-access`, `modernisation.match-expression-candidate`, `modernisation.mixed-type-overuse`, `modernisation.named-argument-opportunity`, `modernisation.public-property`, `modernisation.readonly-property-candidate` | PHP-version-gated opportunity checks where syntax support matters; no autofix behavior; `ModernisationNodeHelper` is shared infrastructure |
| Security | `security.dangerous-function-call`, `security.disabled-ssl-verification`, `security.error-suppression`, `security.extract-compact-user-input`, `security.header-injection`, `security.insecure-random`, `security.silent-catch`, `security.sql-concatenation`, `security.unsafe-unserialize`, `security.variable-include`, `security.weak-crypto` | Heuristic AST checks; some carry secondary pillars (e.g. `Pillar::Complexity` or `Pillar::Modernisation`); `SecurityNodeHelper` is shared infrastructure |
| Secrets | `secrets.api-key-pattern`, `secrets.aws-access-key`, `secrets.database-url-password`, `secrets.hardcoded-env-value`, `secrets.high-entropy-string`, `secrets.jwt-token`, `secrets.phi-pattern`, `secrets.pii-test-fixture`, `secrets.private-key` | All implement `SourceTextRuleInterface`, so they also scan JSON/YAML/INI/.env-style files; `SecretScannerHelper` is shared infrastructure |
| TestQuality | `test-quality.no-assertions`, `test-quality.trivial-assertion`, `test-quality.conditional-logic`, `test-quality.loop-in-test`, `test-quality.test-longer-than-sut`, `test-quality.eager-test`, `test-quality.mystery-guest`, `test-quality.excessive-mocking`, `test-quality.mock-only-test`, `test-quality.sleep-in-test`, `test-quality.naming-consistency`, `test-quality.magic-number-assertion`, `test-quality.private-reflection`, `test-quality.data-provider-annotation`, `test-quality.trivial-snapshot`, `test-quality.sut-not-called`, `test-quality.setup-bloat`, `test-quality.skipped-without-reason` | PHPUnit/Pest AST heuristics scoped to detected test methods or closures; confidence labels identify noisier smells; `TestQualityNodeHelper` is shared infrastructure |
| Mutation | `mutation.survived-mutant`, `mutation.budget-exceeded`, `mutation.msi-regression` | Not registry-backed static rules; emitted only from optional Infection JSON ingestion |

`RuleDefinition` validates that ids match the slug pattern `^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$` and that threshold names are non-empty; the registry rejects duplicate ids on construction.

## Auth / Trust Boundaries

There is no runtime authentication or authorisation surface. The analyser only reads local files supplied by the user. Trust boundaries that exist:

- **Source discovery** treats any path provided on the CLI as user-trusted and applies the default ignored-directory list (overridable with `--include-ignored`).
- **Config loading** treats `.gruff.json` and `--config` as user-trusted but validates strictly: unknown root keys, invalid `minimumPhpVersion`, rule ids, rule sub-keys, and non-numeric thresholds all raise `ConfigException`.
- **Agent tooling** is gated independently by `.claude/hooks/deny-dangerous.sh` and `.codex/hooks/deny-dangerous.sh`, which reject dangerous shell commands before agent execution.

## Data Flow

- Source files: `SourceDiscovery` returns canonicalised absolute paths and project-relative display paths; output is sorted (`ksort` on files, `sort` on missing/ignored). Recognised types are `.php` (parsed) and the text/config extensions `conf`, `config`, `env`, `ini`, `json`, `neon`, `xml`, `yaml`, `yml`, plus dotfiles starting with `.env` (read but not parsed).
- AST: `nikic/php-parser` runs in newest-supported-version mode and the `ParentConnectingVisitor` annotates statements so rules can walk to enclosing classes/functions without re-traversing.
- Findings: `Finding` is a readonly value object exposing rule id, message, file/display path, optional line/end-line/column, severity, primary pillar, secondary pillars, tier, confidence, optional symbol/remediation, free-form metadata, and a stable 16-character `fingerprint` (sha256 of `ruleId+file+line+endLine+column+symbol`).
- Mutation: `InfectionReportParser` reads full Infection JSON and normalises absolute paths to project-relative display paths. `MutationAnalysisResult` adds an optional `mutation` object to JSON reports with raw stats, total MSI / covered MSI / mutation coverage, per-file summaries, survived mutants, optional baseline delta, and optional budget status.
- Aggregation: `AnalysisReport` exposes summary counts (advisory/warning/error/total) and a `parseErrorCount()` derived from diagnostics; `JsonReporter` returns `AnalysisReport::toArray()` matching the `gruff.analysis.v1` schema.
- Exit semantics: `--fail-on` accepts `none`, `advisory`, `warning`, or `error` (default `error`). `none` never fails the run; `advisory` fails on any finding; `warning` fails on warning or error; `error` fails only on error.
- Diagnostics surface (string `type` field): `usage-error` for unsupported flag values, `config-error` for `ConfigException`, `missing-path` for input paths that do not exist, `parse-error` for per-file parser errors, `mutation-tool-error` for missing Infection executables, `mutation-run-error` for execution failures before a report exists, and `mutation-report-error` for unreadable or malformed Infection JSON. Every diagnostic forces an exit code of `2`.

## Configuration

`ConfigLoader::load()` always seeds an `AnalysisConfig` from the registry's default thresholds (`AnalysisConfig::fromRegistry`). It then loads JSON from the resolved path (`--config` or `.gruff.json` in the project root). The supported shape is:

```json
{
  "minimumPhpVersion": 8.3,
  "rules": {
    "<rule.id>": {
      "enabled": true,
      "thresholds": { "<name>": <int|float> }
    }
  }
}
```

`minimumPhpVersion` is optional, defaults to `8.3`, and must be numeric and at least `7.4`; PHP syntax opportunity rules use it to suppress suggestions unsupported by the configured target. Unknown top-level keys, unknown rule ids, unknown rule sub-keys (anything other than `enabled`/`thresholds`), unknown threshold names, non-boolean `enabled`, and non-numeric threshold values all raise `ConfigException`. Threshold names must already exist in the rule's `RuleDefinition::$defaultThresholds`.

## Reporting

- Text output (`TextReporter`): header (`gruff <version>`, format, fail threshold), file counts, optional ignored/missing/diagnostics sections, optional mutation summary, findings section grouped by file/line, and a final summary line with severity counts and exit code.
- JSON output (`JsonReporter`): `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`, schema rooted at `gruff.analysis.v1` (`AnalysisReport::SCHEMA_VERSION`) with an optional `mutation` object when Infection data is supplied.

## Deployment / Operations

Composer is the package manager. Local verification is defined entirely by `composer.json` scripts:

- `composer check` runs `composer validate --strict`, `bash -n scripts/preflight-checks.sh`, an explicit `php -l` over every committed PHP source/test file, and PHPStan.
- `composer phpstan` runs PHPStan 2 at level 10 against `src/` and `tests/`.
- `composer test` runs PHPUnit 11.
- `scripts/preflight-checks.sh` runs `composer phpstan` then `composer test` with a coloured pass/fail summary.

There is no CI configuration, deployment pipeline, Packagist release flow, or runtime service operation today. Do not list any of these as implemented until the corresponding files (e.g. a `.github/workflows/` directory or release script) exist.

## File-Level Conventions

- `final readonly class` is the default for value objects (`AnalysisReport`, `RunDiagnostic`, `RuleDefinition`, `RuleContext`, `RuleSettings`, `Finding`, `SourceFile`, `SourceDiscoveryResult`, `AnalysisUnit`, `ParseDiagnostic`).
- Enums (`Severity`, `Pillar`, `RuleTier`, `Confidence`, `OutputFormat`, `FailThreshold`) are string-backed; CLI parsers use `fromInput()` static helpers that return `null` for unknown values so the command can emit a `usage-error` diagnostic.
- Rules expose a `public const ID` matching the slug returned by `definition()->id`, so test code and config can use the constant rather than re-typing the string.
- All test fixtures live under `tests/Fixtures/M0X/` matching the milestone that introduced them; new milestones add their own subtree rather than reusing earlier ones.

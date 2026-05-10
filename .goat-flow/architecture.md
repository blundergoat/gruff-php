# Architecture - gruff-php

Last reviewed 2026-05-09. All claims map to a real file in `src/`, `tests/`, or top-level config; cross-check before broadening any of them.

## System Overview

`gruff-php` is a Composer-distributed PHP CLI for opinionated code-quality analysis. The package boundary is `composer.json`: it declares dependencies (`nikic/php-parser`, `symfony/console`, `symfony/finder`, `symfony/process`), the `bin/gruff` entrypoint, the `GruffPhp\` PSR-4 root, and the `check`, `phpstan`, and `test` Composer scripts. The runtime exposes `analyse`, `report`, and `dashboard` Symfony Console commands. `analyse` discovers source files, parses PHP through `nikic/php-parser`, runs a deterministic registry of rules, optionally ingests Infection mutation JSON, scores the result, optionally filters to Git diff ranges, and emits a schema-versioned report (`gruff.analysis.v1`) as text, JSON, HTML, Markdown, GitHub annotations, or hotspot JSON. `report` is the static report convenience command: it delegates to `analyse` and can emit HTML or JSON to stdout or `--output`. `dashboard` is the local interactive server for refreshing scans and pointing gruff at other local project roots.

The agent harness is intentionally separate from the app. `.goat-flow/` holds durable project knowledge and tool playbooks; `.claude/`, `.codex/`, and `.agents/skills/` hold the per-agent skill, hook, and settings surfaces. Harness changes do not touch the analyser binary or the Composer package.

## Layered Composition

| Layer | Purpose | Key files |
| --- | --- | --- |
| Entry | Boot autoloader and the Symfony Console app | `bin/gruff`, `src/Console/Application.php` |
| Command | Parse CLI flags, orchestrate the run, render | `src/Command/AnalyseCommand.php`, `src/Command/ReportCommand.php`, `src/Command/DashboardCommand.php`, `src/Reporting/*` |
| Discovery | Resolve user paths to source files | `src/Source/SourceDiscovery.php`, `src/Source/SourceFile.php`, `src/Source/SourceDiscoveryResult.php` |
| Parsing | Produce AST + tokens or per-file diagnostics | `src/Parser/PhpFileParser.php`, `src/Parser/AnalysisUnit.php`, `src/Parser/ParseDiagnostic.php` |
| Configuration | Resolve thresholds, rule selection, path ignores, and allowlists | `src/Config/ConfigLoader.php`, `src/Config/AnalysisConfig.php`, `src/Config/RuleSelection.php`, `src/Config/RuleSettings.php` |
| Rules | Emit findings from `AnalysisUnit` + `RuleContext` | `src/Rule/RuleRegistry.php`, `src/Rule/{Size,Complexity,DeadCode,Waste,Naming,Docs,Modernisation,Security,SensitiveData,TestQuality}/*` |
| Project config | Discover project-level configuration (PHPUnit XML) once per analyse run; consumed by project-scoped rules | `src/Project/PhpUnitConfig.php`, `src/Project/PhpUnitConfigDiscovery.php` |
| Mutation | Parse optional Infection output and emit mutation findings | `src/Mutation/*` |
| Diff | Resolve Git changed files/line ranges and filter findings | `src/Diff/*` |
| Baseline | Generate/read fingerprint baselines and suppress matching findings | `src/Baseline/*` |
| Scoring | Compute A-F composite, pillar, file, and composite-design findings | `src/Scoring/*` |
| Trend | Append optional score-history JSON entries | `src/Trend/*` |
| Findings & Report | Stable typed payload + summary aggregation | `src/Finding/*`, `src/Analysis/AnalysisReport.php`, `src/Analysis/RunDiagnostic.php` |
| Reporting | Render the report for humans or machines | `src/Reporting/TextReporter.php`, `src/Reporting/JsonReporter.php`, `src/Reporting/HtmlReporter.php`, `src/Reporting/MarkdownReporter.php`, `src/Reporting/GithubAnnotationsReporter.php`, `src/Reporting/HotspotReporter.php`, `src/Reporting/OutputFormat.php`, `src/Reporting/FailThreshold.php` |

## Request Flow

The current request flow is CLI-first; `dashboard` additionally starts a local HTTP server for manual refreshes and cross-project scans.

1. `bin/gruff` runs `(new \GruffPhp\Console\Application())->run()` after loading `vendor/autoload.php`.
2. `Application` (Symfony Console subclass) registers the `analyse`, `report`, and `dashboard` commands with version constant `0.1.0-dev`.
3. `AnalyseCommand::execute()` reads the working directory, paths argument, and `--config`, `--no-config`, `--format`, `--fail-on`, `--include-ignored`, `--infection-report`, `--infection-run`, `--infection-bin`, `--infection-config`, `--mutation-baseline`, `--mutation-budget`, `--diff`, `--history-file`, `--baseline`, `--no-baseline`, and `--generate-baseline` options, validating `--format`, `--fail-on`, mutually exclusive baseline modes, mutually exclusive `--config`/`--no-config` modes, and mutation budget input up front. Both `--baseline` and `--generate-baseline` accept an optional path that defaults to `gruff-baseline.json` at the project root; bare `--baseline` resolves to that default file when present. With no explicit `--config`, `AnalyseCommand` auto-loads `.gruff.json` at the project root if present; `--no-config` opts a single run out.
4. `RuleRegistry::defaults()` constructs the v0.1 catalogue (sorted by id via `ksort`).
5. `ConfigLoader::load()` produces an `AnalysisConfig` from the registry defaults, then overlays `.gruff.json` (or the explicit `--config` path); unknown root keys, invalid `minimumPhpVersion`, path ignore patterns, allowlist values, selection values, rule ids, rule keys, threshold names, and non-numeric thresholds throw `ConfigException`, which becomes a `config-error` `RunDiagnostic`.
6. `SourceDiscovery::discover()` expands the input paths (defaulting to `.`), records missing inputs, applies configured path ignores plus the default ignored-directory list, and yields `SourceFile` values typed `php` or `text`.
7. For each discovered file, `PhpFileParser::parse()` reads the source. PHP files are parsed by `nikic/php-parser` and decorated with a `ParentConnectingVisitor`; non-PHP text/config files short-circuit to an `AnalysisUnit` with raw source but no AST or tokens. Parse failures produce one `ParseDiagnostic` per error and are surfaced as `parse-error` `RunDiagnostic` entries.
8. `RuleRegistry::analyse()` skips units with parse errors, then iterates rules allowed by `RuleSelection` and per-rule `enabled` settings. PHP-only rules run only against `SourceFile::isPhp()` units; rules implementing `SourceTextRuleInterface` also run against text/config units, so secret/PII scanners cover JSON, YAML, env, and similar files. Findings from all units are sorted by `(filePath, line, ruleId, message)` for deterministic output.
9. If `--infection-report` is supplied, `InfectionReportParser` ingests the full Infection JSON report, calculates per-file mutation summaries, and `MutationFindingFactory` appends `mutation.survived-mutant`, `mutation.budget-exceeded`, and `mutation.msi-regression` findings where applicable. `--infection-run` is explicit opt-in and only shells out through `InfectionRunner`; it requires a report path because Infection controls full JSON log output through its config.
10. `CompositeFindingFactory` appends `design.god-method` findings when size and complexity findings overlap on the same symbol.
11. If `--diff` is supplied, `GitDiffProvider` reads changed files and new-line ranges from Git (`working-tree`, `staged`, `unstaged`, or a base ref), and `DiffFindingFilter` keeps only findings touching changed lines or changed files.
12. Configured secret preview allowlists remove matching `SensitiveData` findings by redacted preview, after rules run and before scoring.
13. If `--generate-baseline` is supplied, `BaselineStore` writes the current scoped findings to a `gruff.baseline.v1` JSON file (defaulting to `gruff-baseline.json` at the project root, overwriting silently). If `--baseline` is supplied, `BaselineStore` reads that file and `BaselineFilter` suppresses matching findings by fingerprint, rule id, and file path. With no explicit baseline flag, `AnalyseCommand` auto-discovers `gruff-baseline.json` at the project root and applies it unless `--no-baseline` is set. The `BaselineReport` payload distinguishes `source: "explicit"` from `source: "default"` so reporters can communicate whether application was auto-discovered. Stale entries are evaluated only in full-project scope; diff scope reports that stale evaluation is skipped.
14. `ScoreCalculator` computes per-pillar scores, top-offender file scores, complexity distribution buckets, optional mutation scoring, and the composite A-F grade. If `--history-file` is supplied, `TrendRecorder` appends a bounded JSON history entry.
15. `AnalyseCommand` builds an `AnalysisReport` with tool/run metadata, summary counts, ignored/missing paths, diagnostics, findings, optional mutation data, score, diff metadata, optional trend data, and optional baseline metadata, then renders it via the selected reporter. Reporter output is written using `OutputInterface::OUTPUT_RAW` so Symfony Console does not scan rendered HTML/JSON/Markdown payloads as console formatting tags.
16. `resolveExitCode()` returns `Command::INVALID` (`2`) if any `RunDiagnostic` was recorded, `Command::FAILURE` (`1`) when at least one finding satisfies `--fail-on`, and `Command::SUCCESS` (`0`) otherwise.
17. `ReportCommand` builds a safe Symfony Process argument vector for `bin/gruff analyse <paths> --format <html|json> --fail-on <threshold>`, preserving supported analysis options including `--baseline` and `--no-baseline`, then writes the static report to stdout (also via `OUTPUT_RAW`) or to `--output`.
18. `DashboardCommand` binds a local socket (default `127.0.0.1:8765`), renders a control page at `GET /` (now including baseline path and skip-baseline form fields), re-runs analysis at `GET /scan` using query-supplied project root, paths, and baseline state, injects a no-store dashboard toolbar into the HTML report, and exposes `GET /health` for smoke tests. `GET /scan?mutation=run` adds `--infection-run --infection-report infection-report.json` to the analyse subprocess so the dashboard's mutation card "Run mutation analysis" button can trigger a foreground Infection scan; mutation-related `RunDiagnostic` types (`mutation-tool-error`, `mutation-run-error`, `mutation-report-error`) surface inline in the empty mutation cards so failures are visible. `scripts/start-dev.sh` starts this command with environment-overridable host, port, project root, and scan timeout.

Static finding baselines default to `gruff-baseline.json` at the project root: `--generate-baseline` writes it (overwriting silently), bare `--baseline` or no flag at all picks it up automatically, `--baseline=<path>` forces an explicit file, and `--no-baseline` opts a single run out. Mutation-specific baseline MSI comparison remains separate through `--mutation-baseline`.

## Rule Catalogue

The default registry-backed static rule set covers ten pillars (`Size`, `Complexity`, `Maintainability`, `DeadCode`, `Naming`, `Documentation`, `Modernisation`, `Security`, `SensitiveData`, `TestQuality`). Infection ingestion can also emit `Mutation` pillar findings, and `CompositeFindingFactory` can emit a `Design` pillar composite finding when size and complexity findings overlap on the same symbol. All emitted rules are tier `v0.1`; `Coupling` and `Architecture` remain reserved.

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
| SensitiveData | `sensitive-data.api-key-pattern`, `sensitive-data.aws-access-key`, `sensitive-data.database-url-password`, `sensitive-data.hardcoded-env-value`, `sensitive-data.high-entropy-string`, `sensitive-data.jwt-token`, `sensitive-data.phi-pattern`, `sensitive-data.pii-test-fixture`, `sensitive-data.private-key` | All implement `SourceTextRuleInterface`, so they also scan JSON/YAML/INI/.env-style files; `SecretScannerHelper` is shared infrastructure |
| TestQuality | Source-test rules: `test-quality.no-assertions`, `test-quality.trivial-assertion`, `test-quality.conditional-logic`, `test-quality.loop-in-test`, `test-quality.loop-assertion-without-message`, `test-quality.test-longer-than-sut`, `test-quality.test-method-too-long`, `test-quality.eager-test`, `test-quality.mystery-guest`, `test-quality.excessive-mocking`, `test-quality.mock-only-test`, `test-quality.mock-without-expectation`, `test-quality.unused-mock`, `test-quality.sleep-in-test`, `test-quality.naming-consistency`, `test-quality.magic-number-assertion`, `test-quality.private-reflection`, `test-quality.data-provider-annotation`, `test-quality.empty-data-provider`, `test-quality.trivial-snapshot`, `test-quality.sut-not-called`, `test-quality.setup-bloat`, `test-quality.skipped-without-reason`, `test-quality.extends-production-class`, `test-quality.tautological-type-assertion`, `test-quality.exception-type-only`, `test-quality.global-state-mutation`, `test-quality.repeated-structure-missing-data-provider`. Default-disabled heuristics (opt in via `.gruff.json`): `test-quality.multiple-aaa-cycles`, `test-quality.testdox-readability`, `test-quality.mocking-domain-object`. Project-config rules (one finding per analyse run, read from `phpunit.xml`/`phpunit.xml.dist`/`phpunit.dist.xml`): `test-quality.phpunit-strict-flags-missing`, `test-quality.phpunit-deprecations-not-fatal`, `test-quality.phpunit-coverage-source-missing`. PHPUnit/Pest AST heuristics scoped to detected test methods or closures; confidence labels identify noisier smells; `TestQualityNodeHelper` is shared infrastructure |
| Design | `design.god-method` | Not registry-backed; emitted when size and complexity findings overlap on a method/function symbol |
| Mutation | `mutation.survived-mutant`, `mutation.budget-exceeded`, `mutation.msi-regression` | Not registry-backed static rules; emitted only from optional Infection JSON ingestion |

`RuleDefinition` validates that ids match the slug pattern `^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$` and that threshold names are non-empty; the registry rejects duplicate ids on construction.

## Auth / Trust Boundaries

There is no runtime authentication or authorisation surface. The analyser only reads local files supplied by the user. Trust boundaries that exist:

- **Source discovery** treats any path provided on the CLI as user-trusted and applies configured path ignores plus the default ignored-directory list (default ignores are overridable with `--include-ignored`; configured ignores still apply).
- **Config loading** treats `.gruff.json` and `--config` as user-trusted but validates strictly: unknown root keys, invalid `minimumPhpVersion`, path ignore patterns, allowlist entries, rule selection entries, rule ids, rule sub-keys, and non-numeric thresholds all raise `ConfigException`.
- **Baselines** are explicit JSON files supplied by the user. They suppress only exact fingerprint/rule/file matches and report suppression counts plus stale-entry status; inline suppression comments are not supported in v0.1.
- **Agent tooling** is gated independently by `.claude/hooks/deny-dangerous.sh` and `.codex/hooks/deny-dangerous.sh`, which reject dangerous shell commands before agent execution.

## Data Flow

- Source files: `SourceDiscovery` returns canonicalised absolute paths and project-relative display paths; output is sorted (`ksort` on files, `sort` on missing/ignored). Recognised types are `.php` (parsed) and the text/config extensions `conf`, `config`, `env`, `ini`, `json`, `neon`, `xml`, `yaml`, `yml`, plus dotfiles starting with `.env` (read but not parsed).
- AST: `nikic/php-parser` runs in newest-supported-version mode and the `ParentConnectingVisitor` annotates statements so rules can walk to enclosing classes/functions without re-traversing.
- Findings: `Finding` is a readonly value object exposing rule id, message, file/display path, optional line/end-line/column, severity, primary pillar, secondary pillars, tier, confidence, optional symbol/remediation, free-form metadata, and a stable 16-character `fingerprint` (sha256 of `ruleId+file+line+endLine+column+symbol`).
- Mutation: `InfectionReportParser` reads full Infection JSON and normalises absolute paths to project-relative display paths. `MutationAnalysisResult` adds an optional `mutation` object to JSON reports with raw stats, total MSI / covered MSI / mutation coverage, per-file summaries, survived mutants, optional baseline delta, and optional budget status.
- Diff: `GitDiffProvider` parses zero-context `git diff` output into changed files and inclusive changed-line ranges. `DiffFindingFilter` keeps line-located findings that touch changed ranges and keeps line-less findings only when their file changed.
- Scoring: `ScoreCalculator` starts each applicable pillar at 100, subtracts severity/confidence-weighted penalties, uses Infection MSI for the optional mutation pillar, averages applicable pillars into a composite A-F grade, and records top-offender file scores plus cyclomatic distribution buckets.
- Trend history: `TrendRecorder` appends bounded JSON history entries only when `--history-file` is supplied; no history file is read or written by default.
- Aggregation: `AnalysisReport` exposes summary counts (advisory/warning/error/total), `parseErrorCount()` derived from diagnostics, optional mutation, score, diff, and trend payloads; `JsonReporter` returns `AnalysisReport::toArray()` matching the `gruff.analysis.v1` schema.
- Exit semantics: `--fail-on` accepts `none`, `advisory`, `warning`, or `error` (default `error`). `none` never fails the run; `advisory` fails on any finding; `warning` fails on warning or error; `error` fails only on error.
- Diagnostics surface (string `type` field): `usage-error` for unsupported flag values, `config-error` for `ConfigException`, `missing-path` for input paths that do not exist, `parse-error` for per-file parser errors, `mutation-tool-error` for missing Infection executables, `mutation-run-error` for execution failures before a report exists, `mutation-report-error` for unreadable or malformed Infection JSON, `diff-mode-error` for invalid or unavailable Git diff context, `baseline-error` for invalid/unreadable/unwritable gruff baselines, and `history-error` for score-history write failures. Every diagnostic forces an exit code of `2`.

## Configuration

`ConfigLoader::load()` always seeds an `AnalysisConfig` from the registry's default thresholds (`AnalysisConfig::fromRegistry`). It then loads JSON from the resolved path (`--config` or `.gruff.json` in the project root). The supported shape is:

```json
{
  "minimumPhpVersion": 8.3,
  "paths": {
    "ignore": ["legacy/**", "generated"]
  },
  "selection": {
    "tiers": ["v0.1"],
    "pillars": ["security", "sensitive-data"],
    "rules": ["size.file-length"],
    "excludePillars": ["documentation"],
    "excludeRules": ["security.weak-crypto"]
  },
  "allowlists": {
    "acceptedAbbreviations": ["id", "db"],
    "secretPreviews": ["AKIA...T3R2 (redacted, 20 chars)"]
  },
  "rules": {
    "<rule.id>": {
      "enabled": true,
      "thresholds": { "<name>": <int|float> }
    }
  }
}
```

`minimumPhpVersion` is optional, defaults to `8.3`, and must be numeric and at least `7.4`; PHP syntax opportunity rules use it to suppress suggestions unsupported by the configured target. `paths.ignore` contains project-relative exact or glob-like patterns (`*`, `?`, `**`) and cannot escape the project with absolute or parent paths. `selection` is explicit: if any of `tiers`, `pillars`, or `rules` is present, a rule must match at least one include; `excludePillars` and `excludeRules` subtract from that set. Per-rule `enabled: false` still disables a rule; `enabled: true` does not force a rule back into an excluded selection. `allowlists.acceptedAbbreviations` feeds naming rules, while `allowlists.secretPreviews` suppresses exact redacted secret previews already printed by gruff.

Unknown top-level keys, unknown path/allowlist/selection keys, unknown rule ids, unknown pillars, unknown tiers, unknown rule sub-keys (anything other than `enabled`/`thresholds`), unknown threshold names, non-boolean `enabled`, and non-numeric threshold values all raise `ConfigException`. Threshold names must already exist in the rule's `RuleDefinition::$defaultThresholds`.

## Reporting

- Text output (`TextReporter`): header (`gruff <version>`, format, fail threshold), file counts, optional ignored/missing/diagnostics sections, score summary, optional baseline summary, optional mutation summary, findings section grouped by file/line, and a final summary line with severity counts and exit code.
- JSON output (`JsonReporter`): `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`, schema rooted at `gruff.analysis.v1` (`AnalysisReport::SCHEMA_VERSION`) with optional `mutation`, `score`, `diff`, `trend`, and `baseline` objects.
- HTML output (`HtmlReporter`): a self-contained report with inline CSS, escaped run data, masthead, verdict, stats, pillar grades, top offenders, complexity distribution, mutation state, and findings list.
- Markdown output (`MarkdownReporter`): PR-comment style summary with score, counts, top offenders, and findings.
- GitHub annotations output (`GithubAnnotationsReporter`): GitHub Actions workflow commands for findings, with annotation properties escaped.
- Hotspot output (`HotspotReporter`): JSON hotspot map focused on file scores and known limitations; Git churn is not available yet.

## Deployment / Operations

Composer is the package manager. Local verification is defined by `composer.json` scripts:

- `composer check` runs `composer validate --strict`, `bash -n scripts/preflight-checks.sh`, an explicit `php -l` over every committed PHP source/test file, and PHPStan.
- `composer phpstan` runs PHPStan 2 at level 10 against `src/` and `tests/`.
- `composer test` runs PHPUnit 11.
- `scripts/preflight-checks.sh` runs `composer phpstan` then `composer test` with a coloured pass/fail summary.

CI is `.github/workflows/ci.yml`. It runs on push to `main` and pull requests, installs dependencies on PHP 8.3, then runs `composer check` and `bash scripts/preflight-checks.sh`.

There is no deployment pipeline, Packagist release flow, or persistent runtime service operation today. The `dashboard` command is a developer-local HTTP view only. Do not list deployment or release automation as implemented until the corresponding files (e.g. a release script or publishing workflow) exist.

## File-Level Conventions

- `final readonly class` is the default for value objects (`AnalysisReport`, `RunDiagnostic`, `RuleDefinition`, `RuleContext`, `RuleSettings`, `Finding`, `SourceFile`, `SourceDiscoveryResult`, `AnalysisUnit`, `ParseDiagnostic`).
- Enums (`Severity`, `Pillar`, `RuleTier`, `Confidence`, `OutputFormat`, `FailThreshold`) are string-backed; CLI parsers use `fromInput()` static helpers that return `null` for unknown values so the command can emit a `usage-error` diagnostic.
- Rules expose a `public const ID` matching the slug returned by `definition()->id`, so test code and config can use the constant rather than re-typing the string.
- All test fixtures live under `tests/Fixtures/M0X/` matching the milestone that introduced them; new milestones add their own subtree rather than reusing earlier ones.

# Glossary - gruff-php

Last reviewed 2026-05-14. Terms are grouped by domain. Each entry points at the file or files that own the concept; if you change behaviour, update the glossary entry too.

## Project shape

### gruff-php

The repository name and Composer package (`blundergoat/gruff-php`, `composer.json`). It ships a single PHP CLI binary (`bin/gruff`) and the `GruffPhp\` PSR-4 library under `src/`. v0.1 is unreleased; the README and `CHANGELOG.md` describe its current scope.

### Project-owned app surface

Everything that defines the analyser itself: `composer.json`, `composer.lock`, `bin/gruff`, `src/`, `tests/`, `phpunit.xml.dist`, `phpstan.neon.dist`, `scripts/preflight-checks.sh`, plus root docs (`README.md`, `CHANGELOG.md`). Changing these changes the product.

### goat-flow harness

The AI-agent workflow layer under `.goat-flow/` (durable knowledge: architecture, code-map, glossary, decisions, footguns, lessons, patterns, skill-reference) plus per-agent surfaces. Harness files do not ship with the package and do not affect runtime.

### Agent-owned surface

Files owned by one specific agent runtime. Claude Code uses `CLAUDE.md` and `.claude/**`. Codex uses `AGENTS.md`, `.codex/**`, and the shared `.agents/skills/**`. Cross-agent goat-flow rules in `CLAUDE.md` and `AGENTS.md` should stay consistent (see CLAUDE.md "Hard Rules").

### Gruff naming conventions

Shared naming policy for the gruff implementation family. See repo-root `docs/naming-conventions.md`. Public rule ids use `<namespace>.<rule-slug>` with lowercase kebab-case segments, documentation rule ids use `docs.*` while the emitted pillar remains `documentation`, and shared config keys prefer `.gruff-php.yaml`, `paths.ignore`, `allowlists.acceptedAbbreviations`, `allowlists.secretPreviews`, `selection`, and `rules`.

## CLI surface

### `bin/gruff`

Executable shim that requires `vendor/autoload.php` and runs `(new GruffPhp\Console\Application())->run()`.

### `Application`

`src/Console/Application.php`. Symfony Console subclass named `gruff` with `VERSION = '0.1.0-dev'`. Registers the `analyse`, `summary`, `report`, `dashboard`, and `list-rules` commands.

### `analyse` command

`src/Command/AnalyseCommand.php`. Primary analysis command. Accepts a variadic `paths` argument and the options `--config`, `--no-config`, `--format` (`text`|`json`|`html`|`markdown`|`github`|`hotspot`|`sarif`, default `text`), `--fail-on` (`none`|`advisory`|`warning`|`error`, default `error`), `--report-editor-link` (`none`|`vscode`|`phpstorm`, default `none`), `--report-interactive` (optional boolean for HTML findings filters), `--include-ignored`, `--infection-report`, `--infection-run`, `--infection-bin`, `--infection-config`, `--mutation-baseline`, `--mutation-budget`, `--diff`, `--diff-vs`, `--changed-only`, display filters, `--paths-relative-to`, `--history-file`, `--baseline`, `--no-baseline`, and `--generate-baseline`.

### `list-rules` command

`src/Command/ListRulesCommand.php`. Registry metadata command. Emits rule id, name, pillar, tier, default severity, confidence, default enabled state, thresholds, options, and description as a table or JSON (`--format=json`) so agents do not need to scrape source code.

### `summary` command

`src/Command/SummaryCommand.php` and `src/Command/SummaryReportData.php`. Compact analysis command that runs the same analyser pipeline as `analyse` and emits aggregate report data without the per-finding listing.

### `report` command

`src/Command/ReportCommand.php`. Static report convenience command that delegates to `analyse`, supports HTML or JSON output, can write to `--output`, and forwards supported analysis/report flags including baselines, config, `--report-editor-link`, and `--report-interactive`.

### `dashboard` command

`src/Command/DashboardCommand.php` plus `src/Command/DashboardPageRenderer.php` and `src/Command/DashboardScanCommandBuilder.php`. Developer-local HTTP dashboard for refreshable HTML scans. Its controls can set project root, paths, config, baseline behaviour, scan scope (`whole branch` or `diff only`), fail threshold, include-ignored, and the opt-in interactive findings flag; scan metadata includes a wrapping copyable command field.

### Exit codes

- `0` (`Command::SUCCESS`): clean run, or findings below the `--fail-on` threshold.
- `1` (`Command::FAILURE`): at least one finding meets `--fail-on`.
- `2` (`Command::INVALID`): any `RunDiagnostic` was recorded (usage, config, missing-path, parse, mutation, diff, baseline, or history).

## Source pipeline

### `SourceDiscovery`

`src/Source/SourceDiscovery.php`. Resolves user-supplied paths into `SourceFile` values. Defaults to `.` when no paths are passed, canonicalises with `realpath`, deterministically sorts results, and consults two constants: `IGNORED_DIRECTORIES` and `TEXT_EXTENSIONS`. Configured `paths.ignore` patterns are project-relative and always apply. The `--include-ignored` flag opts back into default ignored directories only.

### Default ignored directories

`SourceDiscovery::IGNORED_DIRECTORIES`: `.fleet`, `.git`, `.goat-flow/logs`, `.goat-flow/scratchpad`, `.goat-flow/tasks`, `.hg`, `.idea`, `.phpunit.cache`, `.svn`, `.vscode`, `build`, `cache`, `coverage`, `dist`, `generated`, `node_modules`, `tmp`, `var/cache`, `vendor`. Matched as path-segment sequences against the canonical display path.

### Display path

Project-relative path used in findings and reports. Computed in `SourceDiscovery::displayPath()` by stripping the canonical project root from a canonical absolute path; equal-to-root resolves to `.`.

### `SourceFile`

`src/Source/SourceFile.php`. Readonly trio of `absolutePath`, `displayPath`, and `type` (`SourceFile::TYPE_PHP` or `SourceFile::TYPE_TEXT`). `isPhp()` is the predicate used by the rule registry to gate PHP-only rules.

### Source file types

`php` covers files ending in `.php`. `text` covers `conf`, `config`, `env`, `ini`, `json`, `neon`, `xml`, `yaml`, `yml`, plus dotfiles whose basename is `.env` or starts with `.env.`. PHP files are parsed; text files are read but their AST/token lists are empty.

### `SourceDiscoveryResult`

`src/Source/SourceDiscoveryResult.php`. Wraps `files`, `missingPaths`, `ignoredPaths`. `hasInputErrors()` is true when any input path was missing.

## Parsing

### `PhpFileParser`

`src/Parser/PhpFileParser.php`. Wraps `nikic/php-parser`'s newest-supported-version parser. PHP files are parsed and traversed with `ParentConnectingVisitor` so rules can walk to enclosing context. Non-PHP files short-circuit to an `AnalysisUnit` with raw source and empty AST/tokens. Parser errors and unexpected throwables become `ParseDiagnostic` entries on the unit; the run continues for other files.

### `AnalysisUnit`

`src/Parser/AnalysisUnit.php`. Per-file parsed state: `file`, raw `source` text, `statements` (list of `PhpParser\Node\Stmt`), `tokens`, and `diagnostics`. `hasParseErrors()` returns true if any diagnostics were recorded; `lineCount()` returns 1-based line count of the source.

### `ParseDiagnostic`

`src/Parser/ParseDiagnostic.php`. Per-file parse error message + 1-based line.

## Configuration

### `.gruff-php.yaml`

Project-root config file consumed by `ConfigLoader`. Preferred default location is `<projectRoot>/.gruff-php.yaml`; legacy `<projectRoot>/.gruff.yaml` is still auto-loaded when the preferred file is absent. `--config <path>` overrides both defaults and `--no-config` opts out for a run. Recognised root keys are `minimumPhpVersion`, `paths`, `selection`, `allowlists`, and `rules`; everything else throws `ConfigException`. The repository's root `.gruff-php.yaml` lists the default config surface explicitly and does not contain an absolute project-root setting. Only `.yaml` and `.yml` extensions are accepted; passing `.json` raises a `ConfigException`.

### Minimum PHP Version

`minimumPhpVersion` in `.gruff-php.yaml`, defaulting to `AnalysisConfig::DEFAULT_MINIMUM_PHP_VERSION` (`8.3`). Must be numeric and at least `7.4`. Modernisation rules that suggest PHP 8.0/8.1 syntax use it to suppress findings unsupported by the configured target.

### `AnalysisConfig`

`src/Config/AnalysisConfig.php`. Resolved per-rule settings keyed by rule id plus the configured minimum PHP version, rule selection, path ignore patterns, accepted abbreviations, and allowed secret previews. Constructed from the registry defaults via `fromRegistry()` and then overlayed by the YAML config file.

### Path Ignores

`paths.ignore` in `.gruff-php.yaml`. A list of project-relative exact or glob-like patterns (`*`, `?`, `**`) applied by `SourceDiscovery`. Absolute paths and parent traversal are rejected so config cannot silently point outside the project.

### Rule Selection

`src/Config/RuleSelection.php` and `selection` in `.gruff-php.yaml`. Includes can target `tiers`, `pillars`, and explicit `rules`; exclusions can target `excludePillars` and `excludeRules`. If any include list is non-empty, a rule must match at least one include before exclusions apply. Per-rule `enabled: false` still disables a selected rule.

### Allowlists

`allowlists` in `.gruff-php.yaml`. `acceptedAbbreviations` feeds naming rules such as `naming.short-variable`; `secretPreviews` suppresses exact redacted secret previews already emitted by gruff findings. This avoids putting raw secret values in normal config for known synthetic fixtures.

### `RuleSettings`

`src/Config/RuleSettings.php`. Internal resolved rule state: `enabled` flag plus a `thresholds` map of `string => int|float`. Config can provide either named `thresholds` values or, for warning/error metric rules, a single `threshold` plus `severity` that `RuleConfigApplier` converts into this map. `numericThreshold($name)` throws if the named threshold is missing.

### `ConfigLoader`

`src/Config/ConfigLoader.php`. Loads and validates YAML config (`.yaml` or `.yml` extensions only); any other extension raises a `ConfigException`. Strict on unknown root keys, invalid `minimumPhpVersion`, path ignore values, allowlist values, selection tiers/pillars/rules, unknown rule ids, unknown rule sub-keys (anything other than `enabled`/`threshold`/`severity`/`thresholds`/`options`), invalid `threshold`/`severity` combinations, unknown threshold names, unknown option names, non-boolean `enabled`, and non-numeric thresholds. All failures throw `ConfigException`.

### `ConfigException`

`src/Config/ConfigException.php`. Subclass of `RuntimeException` used for any config validation failure.

## Rule engine

### `RuleInterface`

`src/Rule/RuleInterface.php`. Contract: `definition(): RuleDefinition` and `analyse(AnalysisUnit, RuleContext): list<Finding>`.

### `SourceTextRuleInterface`

`src/Rule/SourceTextRuleInterface.php`. Marker subinterface of `RuleInterface`. Rules implementing it also receive non-PHP text/config units; PHP-only rules are skipped on those units. The SensitiveData pillar uses this interface so JSON, YAML, INI, and `.env` files are scanned.

### `RuleDefinition`

`src/Rule/RuleDefinition.php`. Stable rule metadata: `id` (validated against `^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$`), `name`, `pillar`, `tier`, `defaultSeverity`, `confidence`, `defaultThresholds`, optional `secondaryPillars`, `defaultEnabled` (default-disabled heuristics opt in via config), and `defaultOptions` (non-numeric configuration like namespace globs, poor-name patterns, allowed literals). Constructing a rule with an invalid id or empty threshold/option name throws `InvalidArgumentException`.

### Rule ID

Stable public identifier for one rule, surfaced as `Finding.ruleId`, baseline `ruleId`, config `rules.<id>`, and `list-rules` metadata. gruff-php follows the gruff-family convention `<namespace>.<rule-slug>` with lowercase kebab-case segments. The rule-id namespace usually matches the quality area, but `docs.*` is intentionally used for documentation rules while findings still serialize the pillar as `documentation`.

### `RuleContext`

`src/Rule/RuleContext.php`. Passed to every rule run: project root and the resolved `AnalysisConfig`. `settingsFor($definition)` returns the matching `RuleSettings`.

### `RuleRegistry`

`src/Rule/RuleRegistry.php`. Indexes rules by id (rejects duplicates) and `ksort`s on construction so iteration is deterministic. `defaults()` instantiates the v0.1 catalogue. `analyse()` skips parse-errored units, gates PHP-only rules off non-PHP units, runs the remaining rules, and sorts findings by `(filePath, line, ruleId, message)`.

### Pillar

`src/Finding/Pillar.php`. String-backed enum tagging the quality dimension a finding belongs to. Currently emitted by static rules: `size`, `complexity`, `maintainability`, `dead-code`, `naming`, `documentation`, `modernisation`, `security`, `sensitive-data`, `test-quality`. Optional Infection ingestion emits `mutation`, and `CompositeFindingFactory` can emit `design`. Reserved (no rules yet): `coupling`, `architecture`.

### Infection Report

`src/Mutation/InfectionReportParser.php` and `src/Mutation/InfectionReport.php`. The full JSON output produced by Infection's JSON reporter. gruff-php validates top-level stats and mutant sections (`escaped`, `timeouted`, `killed`, `killedByStaticAnalysis`, `errored`, `syntaxErrors`, `uncovered`, `ignored`), normalises mutant paths, and exposes MSI, covered MSI, mutation coverage, survived mutants, and per-file summaries.

### Mutation Analysis Result

`src/Mutation/MutationAnalysisResult.php`. Optional aggregate attached to `AnalysisReport` when `--infection-report` is supplied. Contains the current Infection report, optional baseline report from `--mutation-baseline`, optional survived-mutant limit from `--mutation-budget`, and the serialised `mutation` JSON object.

### Mutation Findings

`src/Mutation/MutationFindingFactory.php`. External-result findings emitted from Infection data rather than `RuleRegistry`: `mutation.survived-mutant` for escaped or timed-out mutants, `mutation.budget-exceeded` when survived mutants exceed `--mutation-budget`, and `mutation.msi-regression` when current MSI is lower than the baseline.

### God Method Composite

`src/Scoring/CompositeFindingFactory.php`. External-result finding emitted as `design.god-method` when size findings (`size.method-length`, `size.parameter-count`) and complexity findings (`complexity.cognitive`, `complexity.cyclomatic`, `complexity.nesting-depth`, `complexity.npath`) overlap on the same method/function symbol.

### Grade

`src/Scoring/Grade.php`. A-F grade helper over a 0-100 score: A is 90+, B is 80+, C is 70+, D is 60+, and F is below 60.

### Score Report

`src/Scoring/ScoreReport.php` and `src/Scoring/ScoreCalculator.php`. Per-pillar scores start at 100 and subtract severity/confidence-weighted penalties. The composite score averages applicable pillar scores. Mutation uses Infection MSI when supplied and is omitted when no Infection report exists. The score payload also carries top-offender files, complexity distribution buckets, scope (`full-project` or `diff`), and a plain-language explanation.

### Diff Mode

`src/Diff/GitDiffProvider.php` and `src/Diff/DiffFindingFilter.php`. Enabled by `--diff` with an optional mode. Supported modes are `working-tree` (default when the flag has no value), `staged`, `unstaged`, or a base ref. Diff mode requires a Git worktree, parses zero-context `git diff`, keeps findings on changed line ranges, and falls back to changed-file filtering for line-less findings.

### Branch Review Mode

`src/Review/` and `--diff-vs=<base>`. Compares current findings against a Git archive snapshot of the base ref without mutating the working tree. Findings are matched by `file + ruleId + symbol`, falling back to `file + ruleId + message`, so line shifts do not make unchanged logical findings look introduced. `--changed-only` limits comparison to files changed from the base ref.

### Display Filters

`src/Reporting/FindingDisplayFilter.php` plus `--min-severity`, `--include-pillar`, `--exclude-pillar`, `--include-rule`, and `--exclude-rule`. Filters run after analysis/scoring/baseline/review and before rendering. They affect report contents and are recorded in `run.filters`; they do not change rule execution, scoring, or baseline generation.

### Trend History

`src/Trend/TrendRecorder.php`. Optional score-history writer enabled only by `--history-file <path>`. It appends a bounded JSON entry with timestamp, composite score, letter grade, and finding count, then reports current-vs-previous score delta.

### Static Baseline

`src/Baseline/BaselineStore.php`, `src/Baseline/BaselineFilter.php`, and the CLI options `--baseline` / `--generate-baseline`. Baselines are explicit `gruff.baseline.v1` JSON files containing stable finding fingerprints plus rule/file/line/symbol/message context. Applying a baseline suppresses only exact fingerprint + rule id + file path matches. Full-project runs report stale baseline entries; diff-mode runs skip stale evaluation because the scan is intentionally partial.

### Test Quality Scope

`src/Rule/TestQuality/TestQualityScope.php`. Value object representing a detected PHPUnit method or Pest `it()` / `test()` closure. Test-quality rules only inspect these scopes so production code is not flagged for test-only smells.

### Test Quality Node Helper

`src/Rule/TestQuality/TestQualityNodeHelper.php`. Shared AST helper for PHPUnit/Pest test detection, assertion detection, mock creation/verification detection, literal extraction, and SUT-call name normalization.

### Tier (`RuleTier`)

`src/Finding/RuleTier.php`. Release tier for a rule. Only `v0.1` exists today; later tiers will gate scoring or activation.

### Severity

`src/Finding/Severity.php`. `advisory` < `warning` < `error`. Determines whether `--fail-on` triggers a non-zero exit code.

### Confidence

`src/Finding/Confidence.php`. `low` / `medium` / `high`. Used by rules whose detections are heuristic so reporters can distinguish certain matches from probable ones.

### Finding

`src/Finding/Finding.php`. Readonly value object representing a single rule hit. Carries `ruleId`, `message`, `filePath`, optional location (`line`, `endLine`, `column`, `symbol`), `severity`, `pillar`, `secondaryPillars`, `tier`, `confidence`, optional `remediation`, and free-form `metadata`. Empty metadata serializes as `{}` in JSON. `fingerprint()` returns a stable 16-character sha256 prefix derived from `(ruleId, file, line, endLine, column, symbol)`.

### SARIF

`src/Reporting/SarifReporter.php` and `--format=sarif`. Emits SARIF 2.1.0 JSON with rule metadata, locations, severity mapping, and gruff partial fingerprints for code-scanning consumers.

### Identifier Quality

`src/Rule/Naming/IdentifierQualityRule.php`. Advisory naming rule (`naming.identifier-quality`) that catches high-signal placeholder, all-generic, and numbered identifiers from AST nodes. Uses `IdentifierTokenizer` plus rule-local options (`placeholderNames`, `genericTokens`, `ignoredNames`, `minScopeReferences`) and `allowlists.acceptedAbbreviations`.

### One-Line Method

`src/Rule/Waste/OneLineMethodRule.php`. Advisory waste/dead-code rule (`waste.one-line-method`) that flags trivial class methods whose whole body is a single-line return or expression wrapping a call. It skips magic, lifecycle, test, provider, and no-parameter accessor-like cases by default.

### Fingerprint

The 16-character hash returned by `Finding::fingerprint()`. Designed to be stable across runs of the same finding so ingest tools can deduplicate.

## Reporting

### `OutputFormat`

`src/Reporting/OutputFormat.php`. `text` (default), `json`, `html`, `markdown`, `github`, `hotspot`, or `sarif`. `fromInput()` returns `null` for unknown values so the command can emit a `usage-error`.

### `FailThreshold`

`src/Reporting/FailThreshold.php`. `none` / `advisory` / `warning` / `error`. `isTriggeredBy(Severity)` answers whether a finding meets the threshold: `none` is never; `advisory` triggers on anything; `warning` triggers on warning/error; `error` triggers only on error.

### `TextReporter`

`src/Reporting/TextReporter.php`. Grouped human report: header, file counts, optional ignored/missing/diagnostic sections, score summary, optional baseline summary, optional mutation summary, findings list, summary block ending with the exit code.

### `JsonReporter`

`src/Reporting/JsonReporter.php`. Wraps `AnalysisReport::toArray()` with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`.

### `HtmlReporter`

`src/Reporting/HtmlReporter.php`. Self-contained HTML renderer with inline CSS, escaped run data, masthead, verdict, canonical severity stats (`errors`, `warnings`, `advisories`), pillar grades (mutation pillar omitted), top offenders, cyclomatic-complexity summary plus histogram, and findings list. Locations render as selectable copy spans by default or `vscode` / `phpstorm` editor links when requested. When `--report-interactive` is set, the findings list gains dependency-free inline filtering, search, grouping, and URL-hash state; static output remains script-free.

### `MarkdownReporter`

`src/Reporting/MarkdownReporter.php`. PR-comment style Markdown renderer for score, counts, top offenders, and findings.

### `GithubAnnotationsReporter`

`src/Reporting/GithubAnnotationsReporter.php`. GitHub Actions annotation renderer. It escapes annotation properties so file paths, messages, and rule values cannot break annotation syntax.

### `HotspotReporter`

`src/Reporting/HotspotReporter.php`. JSON hotspot-map renderer based on file scores. It declares its current limitation that Git churn is not available yet.

### `AnalysisReport` / `gruff.analysis.v1`

`src/Analysis/AnalysisReport.php`. The schema-versioned payload (`SCHEMA_VERSION = 'gruff.analysis.v1'`). Includes tool metadata, run metadata (format, failOn, configPath, paths, filters), summary counts, ignored/missing paths, diagnostics, findings, optional mutation data, optional score data, optional diff metadata, optional branch-review metadata, optional trend data, and optional baseline metadata.

### `RunDiagnostic`

`src/Analysis/RunDiagnostic.php`. Run-level diagnostic with a string `type`. Known types today: `usage-error`, `config-error`, `missing-path`, `parse-error`, `mutation-tool-error`, `mutation-run-error`, `mutation-report-error`, `diff-mode-error`, `baseline-error`, and `history-error`. Any diagnostic in the report forces exit code `2`.

## Verification

### `composer check`

Composer script that runs `composer validate --strict`, `bash -n scripts/preflight-checks.sh`, an explicit `php -l` over every committed PHP file, and PHPStan. New PHP files must be added to the `check` script or it fails.

### `composer phpstan`

`phpstan analyse --configuration=phpstan.neon.dist`. Level 10 over `src/` and `tests/`, excluding intentionally invalid syntax fixtures.

### `composer test`

PHPUnit 11 against `phpunit.xml.dist`.

### `scripts/preflight-checks.sh`

Runs `composer phpstan`, `composer test`, then full-project `php bin/gruff-php analyse --fail-on advisory --format json` and prints a coloured summary. Used as the default local quality gate before commits.

## Milestones (M0X)

Decision records and internal task notes under `.goat-flow/tasks/` refer to milestone codes (`M01`, `M02`, ...) for delivery slices. Test fixtures used to live under tests/Fixtures/M0X/ but were renamed to descriptive pillar directories beneath `tests/Fixtures/` (Cli, Complexity, Config, DeadCode, Docs, Modernisation, Mutation, Naming, PhpUnitConfig, Reporting, Security, SensitiveData, Size, Source, TestQuality); milestone IDs no longer appear in fixture paths. Per the user's standing memory, milestone IDs do not appear in commit messages, file names, namespaces, or comments either — only in ADRs and internal task notes. See `.goat-flow/footguns/setup.md` and `.goat-flow/decisions/ADR-001-package-baseline-and-integrations.md` for the M01 baseline.

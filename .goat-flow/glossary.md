# Glossary - gruff-php

Last reviewed 2026-05-09. Terms are grouped by domain. Each entry points at the file or files that own the concept; if you change behaviour, update the glossary entry too.

## Project shape

### gruff-php

The repository name and Composer package (`devgoat/gruff-php`, `composer.json`). It ships a single PHP CLI binary (`bin/gruff`) and the `GruffPhp\` PSR-4 library under `src/`. v0.1 is unreleased; the README and `CHANGELOG.md` describe its current scope.

### Project-owned app surface

Everything that defines the analyser itself: `composer.json`, `composer.lock`, `bin/gruff`, `src/`, `tests/`, `phpunit.xml.dist`, `phpstan.neon.dist`, `scripts/preflight-checks.sh`, plus root docs (`README.md`, `CHANGELOG.md`). Changing these changes the product.

### goat-flow harness

The AI-agent workflow layer under `.goat-flow/` (durable knowledge: architecture, code-map, glossary, decisions, footguns, lessons, patterns, skill-reference) plus per-agent surfaces. Harness files do not ship with the package and do not affect runtime.

### Agent-owned surface

Files owned by one specific agent runtime. Claude Code uses `CLAUDE.md` and `.claude/**`. Codex uses `AGENTS.md`, `.codex/**`, and the shared `.agents/skills/**`. Cross-agent goat-flow rules in `CLAUDE.md` and `AGENTS.md` should stay consistent (see CLAUDE.md "Hard Rules").

## CLI surface

### `bin/gruff`

Executable shim that requires `vendor/autoload.php` and runs `(new GruffPhp\Console\Application())->run()`.

### `Application`

`src/Console/Application.php`. Symfony Console subclass named `gruff` with `VERSION = '0.1.0-dev'`. Registers the `analyse` command.

### `analyse` command

`src/Command/AnalyseCommand.php`. The single user-facing command. Accepts a variadic `paths` argument and the options `--config`, `--format` (`text`|`json`, default `text`), `--fail-on` (`none`|`advisory`|`warning`|`error`, default `error`), and `--include-ignored`.

### Exit codes

- `0` (`Command::SUCCESS`): clean run, or findings below the `--fail-on` threshold.
- `1` (`Command::FAILURE`): at least one finding meets `--fail-on`.
- `2` (`Command::INVALID`): any `RunDiagnostic` was recorded (usage, config, missing-path, parse).

## Source pipeline

### `SourceDiscovery`

`src/Source/SourceDiscovery.php`. Resolves user-supplied paths into `SourceFile` values. Defaults to `.` when no paths are passed, canonicalises with `realpath`, deterministically sorts results, and consults two constants: `IGNORED_DIRECTORIES` and `TEXT_EXTENSIONS`. The `--include-ignored` flag opts back into ignored paths.

### Default ignored directories

`SourceDiscovery::IGNORED_DIRECTORIES`: `.git`, `.hg`, `.svn`, `.phpunit.cache`, `build`, `cache`, `coverage`, `dist`, `generated`, `node_modules`, `var/cache`, `vendor`. Matched as path-segment sequences against the canonical display path.

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

### `.gruff.json`

Optional project-root config file consumed by `ConfigLoader`. Default location is `<projectRoot>/.gruff.json`; `--config <path>` overrides it. Only the `rules` root key is recognised; everything else throws `ConfigException`.

### `AnalysisConfig`

`src/Config/AnalysisConfig.php`. Resolved per-rule settings keyed by rule id. Constructed from the registry defaults via `fromRegistry()` and then overlayed by JSON config. Immutable; use `withRuleSettings()` to derive a new instance.

### `RuleSettings`

`src/Config/RuleSettings.php`. `enabled` flag plus a `thresholds` map of `string => int|float`. `numericThreshold($name)` throws if the named threshold is missing.

### `ConfigLoader`

`src/Config/ConfigLoader.php`. Loads and validates JSON config. Strict on unknown root keys, unknown rule ids, unknown rule sub-keys (anything other than `enabled`/`thresholds`), unknown threshold names, non-boolean `enabled`, and non-numeric thresholds. All failures throw `ConfigException`.

### `ConfigException`

`src/Config/ConfigException.php`. Subclass of `RuntimeException` used for any config validation failure.

## Rule engine

### `RuleInterface`

`src/Rule/RuleInterface.php`. Contract: `definition(): RuleDefinition` and `analyse(AnalysisUnit, RuleContext): list<Finding>`.

### `SourceTextRuleInterface`

`src/Rule/SourceTextRuleInterface.php`. Marker subinterface of `RuleInterface`. Rules implementing it also receive non-PHP text/config units; PHP-only rules are skipped on those units. The Secrets pillar uses this interface so JSON, YAML, INI, and `.env` files are scanned.

### `RuleDefinition`

`src/Rule/RuleDefinition.php`. Stable rule metadata: `id` (validated against `^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$`), `name`, `pillar`, `tier`, `defaultSeverity`, `confidence`, `defaultThresholds`, and optional `secondaryPillars`. Constructing a rule with an invalid id or empty threshold name throws `InvalidArgumentException`.

### `RuleContext`

`src/Rule/RuleContext.php`. Passed to every rule run: project root and the resolved `AnalysisConfig`. `settingsFor($definition)` returns the matching `RuleSettings`.

### `RuleRegistry`

`src/Rule/RuleRegistry.php`. Indexes rules by id (rejects duplicates) and `ksort`s on construction so iteration is deterministic. `defaults()` instantiates the v0.1 catalogue. `analyse()` skips parse-errored units, gates PHP-only rules off non-PHP units, runs the remaining rules, and sorts findings by `(filePath, line, ruleId, message)`.

### Pillar

`src/Finding/Pillar.php`. String-backed enum tagging the quality dimension a finding belongs to. Currently emitted: `size`, `complexity`, `maintainability`, `dead-code`, `naming`, `documentation`, `security`, `secrets`. Reserved (no rules yet): `coupling`, `design`, `modernisation`, `test-quality`, `architecture`, `mutation`.

### Tier (`RuleTier`)

`src/Finding/RuleTier.php`. Release tier for a rule. Only `v0.1` exists today; later tiers will gate scoring or activation.

### Severity

`src/Finding/Severity.php`. `advisory` < `warning` < `error`. Determines whether `--fail-on` triggers a non-zero exit code.

### Confidence

`src/Finding/Confidence.php`. `low` / `medium` / `high`. Used by rules whose detections are heuristic so reporters can distinguish certain matches from probable ones.

### Finding

`src/Finding/Finding.php`. Readonly value object representing a single rule hit. Carries `ruleId`, `message`, `filePath`, optional location (`line`, `endLine`, `column`, `symbol`), `severity`, `pillar`, `secondaryPillars`, `tier`, `confidence`, optional `remediation`, and free-form `metadata`. `fingerprint()` returns a stable 16-character sha256 prefix derived from `(ruleId, file, line, endLine, column, symbol)`.

### Fingerprint

The 16-character hash returned by `Finding::fingerprint()`. Designed to be stable across runs of the same finding so ingest tools can deduplicate.

## Reporting

### `OutputFormat`

`src/Reporting/OutputFormat.php`. `text` (default) or `json`. `fromInput()` returns `null` for unknown values so the command can emit a `usage-error`.

### `FailThreshold`

`src/Reporting/FailThreshold.php`. `none` / `advisory` / `warning` / `error`. `isTriggeredBy(Severity)` answers whether a finding meets the threshold: `none` is never; `advisory` triggers on anything; `warning` triggers on warning/error; `error` triggers only on error.

### `TextReporter`

`src/Reporting/TextReporter.php`. Grouped human report: header, file counts, optional ignored/missing/diagnostic sections, findings list, summary block ending with the exit code.

### `JsonReporter`

`src/Reporting/JsonReporter.php`. Wraps `AnalysisReport::toArray()` with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`.

### `AnalysisReport` / `gruff.analysis.v1`

`src/Analysis/AnalysisReport.php`. The schema-versioned payload (`SCHEMA_VERSION = 'gruff.analysis.v1'`). Includes tool metadata, run metadata (format, failOn, configPath, paths), summary counts, ignored/missing paths, diagnostics, and findings.

### `RunDiagnostic`

`src/Analysis/RunDiagnostic.php`. Run-level diagnostic with a string `type`. Known types today: `usage-error`, `config-error`, `missing-path`, `parse-error`. Any diagnostic in the report forces exit code `2`.

## Verification

### `composer check`

Composer script that runs `composer validate --strict`, `bash -n scripts/preflight-checks.sh`, an explicit `php -l` over every committed PHP file, and PHPStan. New PHP files must be added to the `check` script or it fails.

### `composer phpstan`

`phpstan analyse --configuration=phpstan.neon.dist`. Level 10 over `src/` and `tests/`, excluding intentionally invalid syntax fixtures.

### `composer test`

PHPUnit 11 against `phpunit.xml.dist`.

### `scripts/preflight-checks.sh`

Runs `composer phpstan` then `composer test` and prints a coloured summary. Used as the default local quality gate before commits.

## Milestones (M0X)

Test fixtures and decision records refer to milestone codes (`M01`, `M02`, ...). Each represents a delivery slice that introduced a specific surface; `tests/Fixtures/M0X/` holds the fixtures from that slice. Per the user's standing memory, milestone IDs do not appear in commit messages — only in fixtures, ADRs, and internal task notes. See `.goat-flow/footguns/setup.md` and `.goat-flow/decisions/ADR-001-package-baseline-and-integrations.md` for the M01 baseline.

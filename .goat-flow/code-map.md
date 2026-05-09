# Code Map - gruff-php

Last reviewed 2026-05-09. Captures the v0.1 surface as wired in `composer.json`, `bin/gruff`, `src/`, and `tests/`. Treat directory listings as authoritative for scope, but always re-grep before claiming behaviour.

## Top-level layout

```text
.
|-- README.md                 = project entry doc; also probed by docs.missing-readme rule
|-- CHANGELOG.md              = unreleased notes for the v0.1 surface
|-- CLAUDE.md                 = Claude Code root instruction file
|-- AGENTS.md                 = Codex peer instruction file
|-- composer.json             = Composer metadata, runtime deps, bin, autoload, `check`/`phpstan`/`test` scripts
|-- composer.lock             = resolved Composer dependency versions
|-- phpstan.neon.dist         = PHPStan 2 level 10 config for `src/` and `tests/`
|-- phpunit.xml.dist          = PHPUnit 11 test suite config
|-- package.json              = harness-only Node manifest (no app code consumes it)
|-- pnpm-lock.yaml            = pnpm lockfile for harness Node tooling
|-- node_modules/             = harness Node tooling install (gitignored)
|-- vendor/                   = Composer install (gitignored)
|-- bin/                      = PHP CLI entrypoint
|-- scripts/                  = local maintenance scripts
|-- src/                      = gruff-php application source (PSR-4 root `GruffPhp\`)
|-- tests/                    = PHPUnit suite + fixtures (PSR-4 root `GruffPhp\Tests\`)
|-- .editorconfig             = editor settings
|-- .gitattributes            = git export/diff rules
|-- .gitignore                = root ignore rules
|-- .github/                  = repository-facing guidance (commit instructions)
|-- .idea/                    = JetBrains IDE settings (developer-local)
|-- .goat-flow/               = goat-flow project memory and reference docs
|-- .claude/                  = Claude Code config, hooks, and installed skills
|-- .codex/                   = Codex hooks/config surface
`-- .agents/                  = shared peer-agent skill root (Codex/Gemini)
```

## Application surface

```text
bin/
`-- gruff                                     = `#!/usr/bin/env php` shim that loads autoload and runs Console\Application

scripts/
|-- preflight-checks.sh                       = local PHPStan + PHPUnit runner with coloured pass/fail summary
`-- maintenance/                              = ad-hoc maintenance scripts (developer-local)

src/
|-- Analysis/
|   |-- AnalysisReport.php                    = schema-versioned (`gruff.analysis.v1`) payload: tool, run, summary, paths, diagnostics, findings, optional mutation/score/diff/trend
|   `-- RunDiagnostic.php                     = run-level diagnostic value object (config-error, missing-path, parse-error, usage-error, mutation/diff/history errors)
|-- Command/
|   `-- AnalyseCommand.php                    = `analyse` command; loads config, discovers paths, parses files, runs rules/mutation/composites, filters diffs, scores, renders, and resolves exit code
|-- Config/
|   |-- AnalysisConfig.php                    = resolved per-rule settings; immutable, `withRuleSettings()` for per-rule edits
|   |-- ConfigException.php                   = invalid-config exception type (RuntimeException subclass)
|   |-- ConfigLoader.php                      = `.gruff.json` / `--config` JSON loader; strict unknown-key, type, and threshold validation
|   `-- RuleSettings.php                      = per-rule `enabled` flag and threshold map; `numericThreshold()` accessor
|-- Console/
|   `-- Application.php                       = Symfony Console application named `gruff`, version constant `0.1.0-dev`
|-- Diff/
|   |-- ChangedLineRange.php                  = inclusive changed-line range value object
|   |-- DiffException.php                     = diff-mode failure exception
|   |-- DiffFindingFilter.php                 = keeps findings touching changed ranges or changed files
|   |-- DiffResult.php                        = diff-mode metadata exposed in reports
|   `-- GitDiffProvider.php                   = zero-context `git diff` parser for working-tree, staged, unstaged, or base-ref modes
|-- Finding/
|   |-- Confidence.php                        = `low` / `medium` / `high` enum
|   |-- Finding.php                           = readonly finding value with stable `fingerprint()` (sha256 of identity fields, truncated)
|   |-- Pillar.php                            = quality pillar enum (size, complexity, coupling, dead-code, naming, documentation, security, secrets, design, modernisation, test-quality, architecture, maintainability, mutation)
|   |-- RuleTier.php                          = release-tier enum (currently only `v0.1`)
|   `-- Severity.php                          = `advisory` / `warning` / `error` enum
|-- Mutation/
|   |-- InfectionMutant.php                   = parsed Infection mutant row with status, file, line, mutator, diff, and process output
|   |-- InfectionReport.php                   = parsed Infection report plus MSI, covered MSI, survived-mutant, and per-file summary helpers
|   |-- InfectionReportParser.php             = full Infection JSON parser; validates stats/sections and normalises paths
|   |-- InfectionRunResult.php                = result value for optional Infection process execution
|   |-- InfectionRunner.php                   = explicit opt-in Infection `run` wrapper using Symfony Process and executable lookup
|   |-- MutationAnalysisResult.php            = report + optional baseline/budget aggregate exposed as optional JSON `mutation`
|   |-- MutationFileSummary.php               = per-file mutation totals and MSI values
|   |-- MutationFindingFactory.php             = emits `mutation.survived-mutant`, `mutation.budget-exceeded`, `mutation.msi-regression`
|   `-- MutationReportException.php           = invalid/malformed Infection report exception type
|-- Parser/
|   |-- AnalysisUnit.php                      = parsed unit: SourceFile, source text, AST statements, token list, diagnostics; `lineCount()` helper
|   |-- ParseDiagnostic.php                   = per-file parse error message + 1-based line
|   `-- PhpFileParser.php                     = nikic/php-parser wrapper; attaches `ParentConnectingVisitor`; non-PHP files return empty AST/tokens
|-- Reporting/
|   |-- FailThreshold.php                     = `none` / `advisory` / `warning` / `error` enum + `isTriggeredBy(Severity)` predicate
|   |-- GithubAnnotationsReporter.php         = GitHub Actions annotation renderer with escaped annotation properties
|   |-- HotspotReporter.php                   = hotspot-map JSON renderer based on file scores
|   |-- HtmlReporter.php                      = self-contained escaped dashboard renderer
|   |-- JsonReporter.php                      = pretty-printed JSON renderer of `AnalysisReport::toArray()`
|   |-- MarkdownReporter.php                  = PR-comment style Markdown renderer
|   |-- OutputFormat.php                      = `text` / `json` / `html` / `markdown` / `github` / `hotspot` enum
|   `-- TextReporter.php                      = grouped terminal renderer (header, files, paths, diagnostics, score, findings, summary)
|-- Rule/
|   |-- RuleContext.php                       = project root + AnalysisConfig; `settingsFor(RuleDefinition)` accessor
|   |-- RuleDefinition.php                    = stable rule metadata: id (slug-validated), name, pillar, tier, default severity, confidence, default thresholds, secondary pillars
|   |-- RuleInterface.php                     = `definition()` + `analyse(AnalysisUnit, RuleContext): list<Finding>` contract
|   |-- RuleRegistry.php                      = ksort-sorted registry; `defaults()` wires all v0.1 rules; `analyse()` skips parse-errored units, runs PHP rules on PHP files only, runs source-text rules on text files too, then sorts findings by file/line/ruleId/message
|   |-- SourceTextRuleInterface.php           = marker subinterface; rules implementing it also receive non-PHP text/config files
|   |-- Complexity/
|   |   |-- CognitiveComplexityRule.php       = `complexity.cognitive`
|   |   |-- CyclomaticComplexityRule.php      = `complexity.cyclomatic`
|   |   |-- HalsteadVolumeRule.php            = `complexity.halstead-volume`
|   |   |-- MaintainabilityIndexRule.php      = `complexity.maintainability-index` (Maintainability pillar)
|   |   |-- NestingDepthRule.php              = `complexity.nesting-depth`
|   |   `-- NpathComplexityRule.php           = `complexity.npath`
|   |-- DeadCode/
|   |   |-- UnusedPrivateMethodRule.php       = `dead-code.unused-private-method`
|   |   `-- UnusedPrivatePropertyRule.php     = `dead-code.unused-private-property`
|   |-- Docs/
|   |   |-- MissingParamTagRule.php           = `docs.missing-param-tag`
|   |   |-- MissingPublicPhpdocRule.php       = `docs.missing-public-phpdoc`
|   |   |-- MissingReadmeRule.php             = `docs.missing-readme` (project-root scoped; runs on every unit but emits at most once per run via short-circuit)
|   |   |-- MissingReturnTagRule.php          = `docs.missing-return-tag`
|   |   |-- MissingThrowsTagRule.php          = `docs.missing-throws-tag`
|   |   |-- StaleParamTagRule.php             = `docs.stale-param-tag`
|   |   |-- TodoDensityRule.php               = `docs.todo-density`
|   |   `-- UselessPhpdocRule.php             = `docs.useless-phpdoc`
|   |-- Naming/
|   |   |-- BooleanPrefixRule.php             = `naming.boolean-prefix`
|   |   |-- ClassFileMismatchRule.php         = `naming.class-file-mismatch`
|   |   |-- ConfusingNameRule.php             = `naming.confusing-name`
|   |   |-- GenericMethodNameRule.php         = `naming.generic-method`
|   |   |-- HungarianNotationRule.php         = `naming.hungarian-notation`
|   |   |-- ShortVariableRule.php             = `naming.short-variable`
|   |   `-- TestNamingConsistencyRule.php     = `naming.test-naming-consistency`
|   |-- Modernisation/                        = AST-driven PHP-modernisation opportunity rules; PHP syntax suggestions respect `minimumPhpVersion`
|   |   |-- ConstructorPromotionCandidateRule.php = `modernisation.constructor-promotion-candidate`
|   |   |-- EnumCandidateRule.php              = `modernisation.enum-candidate`
|   |   |-- FirstClassCallableCandidateRule.php = `modernisation.first-class-callable-candidate`
|   |   |-- ForbiddenGlobalAccessRule.php      = `modernisation.forbidden-global-access`
|   |   |-- MatchExpressionCandidateRule.php   = `modernisation.match-expression-candidate`
|   |   |-- MixedTypeOveruseRule.php           = `modernisation.mixed-type-overuse`
|   |   |-- ModernisationNodeHelper.php        = shared PHP-version/type/parent-node helpers
|   |   |-- NamedArgumentOpportunityRule.php   = `modernisation.named-argument-opportunity`
|   |   |-- PublicPropertyRule.php             = `modernisation.public-property`
|   |   `-- ReadonlyPropertyCandidateRule.php = `modernisation.readonly-property-candidate`
|   |-- Secrets/                              = SourceTextRuleInterface rules; scan PHP plus config/text/env files
|   |   |-- ApiKeyPatternRule.php             = `secrets.api-key-pattern`
|   |   |-- AwsAccessKeyRule.php              = `secrets.aws-access-key`
|   |   |-- DatabaseUrlPasswordRule.php       = `secrets.database-url-password`
|   |   |-- HardcodedEnvValueRule.php         = `secrets.hardcoded-env-value`
|   |   |-- HighEntropyStringRule.php         = `secrets.high-entropy-string`
|   |   |-- JwtTokenRule.php                  = `secrets.jwt-token`
|   |   |-- PhiPatternRule.php                = `secrets.phi-pattern`
|   |   |-- PiiTestFixtureRule.php            = `secrets.pii-test-fixture`
|   |   |-- PrivateKeyRule.php                = `secrets.private-key`
|   |   `-- SecretScannerHelper.php           = shared regex/entropy helpers for the secrets pack
|   |-- Security/                             = AST-driven heuristic rules
|   |   |-- DangerousFunctionCallRule.php     = `security.dangerous-function-call`
|   |   |-- DisabledSslVerificationRule.php   = `security.disabled-ssl-verification`
|   |   |-- ErrorSuppressionRule.php          = `security.error-suppression`
|   |   |-- ExtractCompactUserInputRule.php   = `security.extract-compact-user-input`
|   |   |-- HeaderInjectionRule.php           = `security.header-injection`
|   |   |-- InsecureRandomRule.php            = `security.insecure-random`
|   |   |-- SecurityNodeHelper.php            = shared AST traversal helpers for the security pack
|   |   |-- SilentCatchRule.php               = `security.silent-catch`
|   |   |-- SqlConcatenationRule.php          = `security.sql-concatenation`
|   |   |-- UnsafeUnserializeRule.php         = `security.unsafe-unserialize`
|   |   |-- VariableIncludeRule.php           = `security.variable-include`
|   |   `-- WeakCryptoRule.php                = `security.weak-crypto`
|   |-- TestQuality/                          = PHPUnit/Pest AST rules scoped by `TestQualityNodeHelper`
|   |   |-- ConditionalTestLogicRule.php      = `test-quality.conditional-logic`
|   |   |-- DataProviderAnnotationRule.php    = `test-quality.data-provider-annotation`
|   |   |-- EagerTestRule.php                 = `test-quality.eager-test`
|   |   |-- ExcessiveMockingRule.php          = `test-quality.excessive-mocking`
|   |   |-- LoopInTestRule.php                = `test-quality.loop-in-test`
|   |   |-- MagicNumberAssertionRule.php      = `test-quality.magic-number-assertion`
|   |   |-- MockOnlyTestRule.php              = `test-quality.mock-only-test`
|   |   |-- MysteryGuestRule.php              = `test-quality.mystery-guest`
|   |   |-- NoAssertionsRule.php              = `test-quality.no-assertions`
|   |   |-- PrivateReflectionRule.php         = `test-quality.private-reflection`
|   |   |-- SetupBloatRule.php                = `test-quality.setup-bloat`
|   |   |-- SkippedWithoutReasonRule.php      = `test-quality.skipped-without-reason`
|   |   |-- SleepInTestRule.php               = `test-quality.sleep-in-test`
|   |   |-- SutNotCalledRule.php              = `test-quality.sut-not-called`
|   |   |-- TestLongerThanSutRule.php         = `test-quality.test-longer-than-sut`
|   |   |-- TestNamingConsistencyRule.php     = `test-quality.naming-consistency`
|   |   |-- TestQualityNodeHelper.php         = shared test-scope/assertion/mock/SUT-call helpers
|   |   |-- TestQualityScope.php              = detected PHPUnit/Pest scope value
|   |   |-- TrivialAssertionRule.php          = `test-quality.trivial-assertion`
|   |   `-- TrivialSnapshotRule.php          = `test-quality.trivial-snapshot`
|   |-- Size/
|   |   |-- AverageMethodLengthRule.php       = `size.average-method-length`
|   |   |-- ClassLengthRule.php               = `size.class-length`
|   |   |-- FileLengthRule.php                = `size.file-length` (warn 400 / error 800 default thresholds)
|   |   |-- MethodLengthRule.php              = `size.method-length`
|   |   |-- ParameterCountRule.php            = `size.parameter-count`
|   |   |-- PropertyCountRule.php             = `size.property-count`
|   |   `-- PublicMethodCountRule.php         = `size.public-method-count`
|   `-- Waste/
|       |-- CommentedOutCodeRule.php          = `waste.commented-out-code`
|       |-- EmptyClassRule.php                = `waste.empty-class`
|       |-- EmptyMethodRule.php               = `waste.empty-method`
|       |-- UnreachableCodeRule.php           = `waste.unreachable-code`
|       |-- UnusedImportRule.php              = `waste.unused-import`
|       `-- UnusedParameterRule.php           = `waste.unused-parameter`
|-- Scoring/
|   |-- CompositeFindingFactory.php           = emits `design.god-method` from overlapping size + complexity findings
|   |-- FileScore.php                         = per-file top-offender score value
|   |-- Grade.php                             = A-F grade helper around 0-100 scores
|   |-- PillarScore.php                       = per-pillar score/count/penalty value
|   |-- ScoreCalculator.php                   = composite, pillar, file, complexity-distribution, and mutation scoring
|   `-- ScoreReport.php                       = serialisable score payload for reports
|-- Source/
|   |-- SourceDiscovery.php                   = recursive discovery; PHP plus text/config extensions (conf/config/env/ini/json/neon/xml/yaml/yml + `.env*`); deterministic ksort + path canonicalisation
|   |-- SourceDiscoveryResult.php             = files, missingPaths, ignoredPaths; `hasInputErrors()` on missing paths
|   `-- SourceFile.php                        = absolutePath, displayPath, type (`php` or `text`); `isPhp()` predicate
`-- Trend/
    |-- TrendRecorder.php                     = optional bounded JSON score-history writer for `--history-file`
    `-- TrendReport.php                       = current-vs-previous score delta payload
```

Default ignored directories (`SourceDiscovery::IGNORED_DIRECTORIES`): `.git`, `.hg`, `.svn`, `.phpunit.cache`, `build`, `cache`, `coverage`, `dist`, `generated`, `node_modules`, `var/cache`, `vendor`. The `--include-ignored` flag opts back in.

## Test surface

```text
tests/
|-- Config/
|   `-- ConfigLoaderTest.php                  = default config, JSON overrides, disable, unknown-key/threshold validation
|-- Console/
|   `-- GruffCliTest.php                      = end-to-end CLI smoke tests via `bin/gruff`: version, parser output, config, fail-on, JSON/schema score data, Infection ingestion, HTML/Markdown/GitHub/hotspot/history/diff paths
|-- Diff/
|   `-- GitDiffProviderTest.php               = changed-line filtering, unstaged git diff parsing, non-git diff errors
|-- Finding/
|   `-- FindingTest.php                       = `Finding::toArray()` shape and `fingerprint()` stability
|-- Mutation/
|   `-- InfectionReportParserTest.php         = full Infection JSON parser, path normalisation, MSI/per-file summaries, malformed report handling
|-- Parser/
|   `-- PhpFileParserTest.php                 = valid parse, syntax-error diagnostics, parent-connecting visitor
|-- Reporting/
|   `-- HtmlReporterTest.php                  = dashboard section rendering and malicious string escaping
|-- Source/
|   `-- SourceDiscoveryTest.php               = discovery, ignore semantics, missing-path reporting
|-- Rule/
|   |-- RuleRegistryTest.php                  = registry sorting, enable/disable, duplicate-id rejection, parse-error skipping
|   |-- Complexity/
|   |   |-- CognitiveComplexityRuleTest.php
|   |   |-- ComplexityIntegrationTest.php
|   |   |-- CyclomaticComplexityRuleTest.php
|   |   `-- NestingDepthRuleTest.php
|   |-- DeadCode/
|   |   `-- DeadCodeRulesTest.php
|   |-- Docs/
|   |   `-- DocsRulesTest.php
|   |-- Naming/
|   |   `-- NamingRulesTest.php
|   |-- Secrets/
|   |   `-- SecretsRulesTest.php
|   |-- Security/
|   |   `-- SecurityRulesTest.php
|   |-- Size/
|   |   |-- AverageMethodLengthRuleTest.php
|   |   |-- ClassLengthRuleTest.php
|   |   |-- MethodLengthRuleTest.php
|   |   |-- ParameterCountRuleTest.php
|   |   |-- PropertyCountRuleTest.php
|   |   |-- PublicMethodCountRuleTest.php
|   |   `-- SizeIntegrationTest.php
|   `-- Waste/
|       `-- WasteRulesTest.php
|-- Scoring/
|   `-- ScoreCalculatorTest.php               = grade boundaries, optional mutation behavior, security penalties, design composite findings
`-- Fixtures/
    |-- M02/                                  = parser/discovery: mixed/, empty/, syntax-error/
    |-- M03/Config/                           = JSON config: defaults, threshold override, disabled rule, unknown-rule
    |-- M04/                                  = CLI reporting: Config/ + Golden/ (text + json snapshots)
    |-- M05/                                  = size rules: Size/ source fixtures + Config/ low-threshold scenarios
    |-- M06/                                  = complexity rules: Complexity/ source fixtures + Config/
    |-- M07/                                  = dead-code & waste rules: DeadCode/ + Config/
    |-- M08/Naming/                           = naming rule fixtures
    |-- M09/Docs/                             = documentation rule fixtures
    |-- M10/                                  = security rules: Security/ source + Config/disable scenarios
    |-- M11/                                  = secrets rules: Secrets/ + Config/ disable/scope scenarios (php, json, env-style)
    |-- M12/                                  = modernisation rules: Modernisation/ + Config/ PHP-version gating scenarios
    |-- M13/                                  = static test-quality rules: TestQuality/ + Config/ selected-rule disable scenario
    `-- M14/                                  = Infection integration: Source/ target + Infection/ valid, clean, baseline, malformed reports
```

## goat-flow harness

```text
.goat-flow/
|-- architecture.md                           = system architecture notes (this file's sibling)
|-- code-map.md                               = this repository map
|-- glossary.md                               = project and harness terms
|-- config.yaml                               = goat-flow version + configured agents
|-- security-policy.md                        = baseline security expectations
|-- decisions/
|   |-- ADR-001-package-baseline-and-integrations.md
|   `-- README.md
|-- footguns/
|   |-- setup.md
|   `-- README.md
|-- lessons/
|   |-- setup.md
|   `-- README.md
|-- patterns/
|   `-- README.md
|-- skill-reference/
|   |-- README.md
|   |-- skill-conventions.md
|   |-- skill-preamble.md
|   |-- browser-use.md                        = browser-use CLI availability + usage playbook
|   |-- page-capture.md                       = page-capture CLI playbook
|   |-- skill-quality-testing.md              = quality-testing skill index
|   `-- skill-quality-testing/                = supporting docs for skill-quality-testing
|-- tasks/                                    = local milestone/task workspace (gitignored content under it)
|-- scratchpad/                               = local temporary notes (gitignored content under it)
`-- logs/                                     = local setup, quality, critique, and security logs (gitignored content under it)

.claude/
|-- settings.json                             = committed Claude Code settings
|-- settings.local.json                       = developer-local Claude Code settings (gitignored)
|-- hooks/
|   `-- deny-dangerous.sh                     = PreToolUse shell-safety hook
`-- skills/
    |-- goat/
    |-- goat-critique/
    |-- goat-debug/
    |-- goat-plan/
    |-- goat-qa/
    |-- goat-review/
    `-- goat-security/

.codex/
|-- config.toml                               = Codex hooks feature config
|-- hooks.json                                = Codex PreToolUse hook registration
`-- hooks/
    `-- deny-dangerous.sh                     = shell-safety hook (mirrors Claude's)

.agents/
`-- skills/                                   = peer-agent skills mirroring `.claude/skills/`
    |-- goat/
    |-- goat-critique/
    |-- goat-debug/
    |-- goat-plan/
    |-- goat-qa/
    |-- goat-review/
    `-- goat-security/
```

## Notes

- `vendor/` and `node_modules/` are generated and gitignored.
- No CI directory exists yet; verification is local via `composer check`, `composer phpstan`, `composer test`, or `scripts/preflight-checks.sh`.
- `composer.json`'s `check` script lists every committed PHP file for `php -l` linting; new files must be added there or the script fails.
- Pillars currently emitted by registered static rules: Size, Complexity, Maintainability, DeadCode, Naming, Documentation, Modernisation, Security, Secrets, TestQuality. Optional Infection ingestion emits Mutation findings, and scoring composites can emit Design findings. Other `Pillar::*` cases (Coupling, Architecture) are reserved for later tiers.

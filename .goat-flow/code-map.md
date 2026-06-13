# Code Map - gruff-php

Last reviewed 2026-06-13. Captures the v0.4.1 surface as wired in `composer.json`, `bin/gruff-php`, `src/`, and `tests/`. Treat directory listings as authoritative for scope, but always re-grep before claiming behaviour.

## Top-level layout

```text
.
|-- README.md                 = project entry doc; also probed by docs.missing-readme rule
|-- CHANGELOG.md              = unreleased notes for the v0.1 surface
|-- CLAUDE.md                 = Claude Code root instruction file
|-- AGENTS.md                 = Codex peer instruction file
|-- composer.json             = Composer metadata, runtime deps, bin, autoload, `check`/`phpstan`/`security:scan`/`test` scripts
|-- composer.lock             = resolved Composer dependency versions
|-- phpstan.neon.dist         = PHPStan 2 level 10 config for `src/` and `tests/`
|-- phpunit.xml.dist          = PHPUnit 12 test suite config
|-- package.json              = harness-only Node manifest (no app code consumes it)
|-- package-lock.json         = npm lockfile for harness Node tooling
|-- node_modules/             = harness Node tooling install (gitignored)
|-- vendor/                   = Composer install (gitignored)
|-- .gruff-cache/             = incremental result cache (ADR-020); gitignored + discovery-ignored
|-- bin/                      = PHP CLI entrypoint
|-- scripts/                  = local maintenance scripts
|-- src/                      = gruff-php application source (PSR-4 root `GruffPhp\`)
|-- tests/                    = PHPUnit suite + fixtures (PSR-4 root `GruffPhp\Tests\`)
|-- .editorconfig             = editor settings
|-- .gitattributes            = git export/diff rules
|-- .gitignore                = root ignore rules
|-- .github/                  = repository-facing guidance and CI workflow, including the gruff security-profile SARIF upload job
|-- .idea/                    = JetBrains IDE settings (developer-local)
|-- .goat-flow/               = goat-flow project memory and reference docs
|-- .claude/                  = Claude Code config, hooks, and installed skills
|-- .codex/                   = Codex hooks/config surface
`-- .agents/                  = shared peer-agent skill root (Codex/Gemini)
```

## Application surface

Application source map:

    bin/
    +-- gruff-php = PHP shim that loads Composer autoload and runs Cli\Application

    scripts/
    |-- preflight-checks.sh = local PHPStan + PHPUnit + full-project gruff analysis runner
    |-- start-dev.sh = starts the dashboard with environment-overridable host/port/project/scan timeout
    +-- maintenance/ = ad-hoc maintenance scripts

    src/
    |-- Cli/ = Symfony Console entrypoint and command-facing orchestration
    |   |-- Application.php = application named gruff-php; registers analyse, check-ignore, dashboard, hook, init, list-rules, report, and summary
    |   |-- Command/ = CLI command handlers and analyse/report orchestration helpers
    |   |   |-- AnalyseCommand.php = analyse command pipeline entry
    |   |   |-- HookCommand.php = hook contract endpoint used by coding-agent changed-line quality checks
    |   |   |-- ReportCommand.php = static HTML/JSON report command that delegates to analyse
    |   |   |-- SummaryCommand.php = compact summary command built on the analyser pipeline
    |   |   +-- Runtime/RuntimeTimingObserver.php = per-rule wall-clock observer for runtime reporting
    |   +-- Dashboard/ = local HTTP dashboard command, state, rendering, request handling, scan command building, and server loop
    |-- Engine/ = analysis engine inputs, configuration, parsing, discovery, cache, and package-level project facts
    |   |-- Analysis/ = AnalysisReport schema payload and RunDiagnostic value objects
    |   |-- Cache/ = incremental result cache keyed by analysis fingerprint and source bytes
    |   |-- Config/ = strict config loader, rule settings, selection, severity thresholds, preset loading, and config parsers
    |   |-- Parser/ = PHP parser wrapper, parsed AnalysisUnit, and parse diagnostics
    |   |-- Project/ = PHPUnit XML config discovery and parsed config values
    |   +-- Source/ = Git-aware source discovery, ignore resolution, source-file values, and ignored-path details
    |-- Output/ = presentation and coding-agent hook filtering output
    |   |-- Hook/ = hook finding filtering, scope attribution, identity, and presenter values
    |   +-- Reporter/ = text, JSON, HTML, Markdown, GitHub annotations, hotspot, SARIF, format, display-filter, and fail-threshold output
    |-- Results/ = serialisable analysis results and optional enrichment payloads
    |   |-- Baseline/ = baseline read/write/apply/report values for gruff.baseline.v1
    |   |-- Diff/ = Git diff ranges, diff-mode metadata, and changed-line finding filter
    |   |-- Finding/ = finding value, severity, confidence, pillar, and tier enums
    |   |-- Mutation/ = Infection report parsing, optional Infection run wrapper, mutation findings, budgets, and mutation payloads
    |   |-- Review/ = branch-review base snapshot and finding comparison values
    |   |-- Scoring/ = grade, pillar/file score values, score report, and score calculator
    |   +-- Trend/ = optional bounded score-history writer and trend report payload
    |-- Rules/ = registry, rule contracts, shared AST helpers, and concrete rule families
    |   |-- RuleRegistry.php = sorted default registry, enabled-rule filtering, per-unit/project dispatch, streaming support, and naming deduplication
    |   |-- Contracts/ = RuleInterface, ProjectRuleInterface, SourceTextRuleInterface, RuleContext, and RuleDefinition
    |   |-- Shared/ = NodeIndex, statement-child visitor values, streaming project-rule accumulators, and runtime observer contract
    |   |-- Complexity/ = complexity and maintainability rules plus shared complexity shape classifier
    |   |-- DeadCode/ = private member dead-code rules
    |   |-- Docs/ = PHPDoc, README, regex-comment, TODO-density, and tag-structure rules
    |   |-- Modernisation/ = PHP-version-gated syntax and type-surface opportunity rules
    |   |-- Naming/ = identifier, boolean, class/file, callback scope, and naming-consistency rules
    |   |-- Security/ = AST/source-text security heuristics and Composer/workflow dependency checks
    |   |-- SensitiveData/ = source-text secret, token, credential, PII, PHI, and entropy rules
    |   |-- Size/ = file/class/method/parameter/property/public-method size rules
    |   |-- TestQuality/ = PHPUnit/Pest test-quality rules and test-scope helpers
    |   +-- Waste/ = clutter, unreachable, unused import/parameter, redundant variable, and one-line method rules
    +-- Support/ = cross-package helpers such as project-relative path resolution

The Composer PSR-4 root remains GruffPhp\ => src/; the namespace consolidation is internal package structure only. The six direct application source packages are Cli, Engine, Rules, Results, Output, and Support.

Git worktree discovery uses git ls-files --cached --others --exclude-standard by default so tracked files and unignored untracked files define the broad scan boundary. Configured paths.ignore still applies after Git enumeration, and generated lockfile names stay skipped to avoid known generated-artifact noise. --include-ignored opts into filesystem traversal for deliberate ignored-file scans. Non-Git fallback traversal uses SourceDiscovery::IGNORED_DIRECTORIES, including dependency, cache, build, generated, IDE, VCS, goat-flow scratch/log, and temporary directories.

## Test surface

```text
tests/
|-- Config/
|   `-- ConfigLoaderTest.php                  = default config, YAML overrides, disable, path ignore, allowlist, selection, unknown-key/threshold validation
|-- Console/
|   |-- AnalyseCliTest.php                    = end-to-end `analyse` coverage: version/help, parser output, config/selection/allowlists, fail-on, JSON/schema score data, static HTML, SARIF, GitHub annotations, profiles, and filters
|   |-- AnalyseCliBaselineTest.php            = baseline generation, suppression, stale-entry reporting, and invalid baseline flag combinations
|   |-- AnalyseCliGitDiscoveryTest.php        = Git-aware default discovery and ignored-file handling
|   |-- AnalyseCliMutationTest.php            = Infection JSON ingestion, mutation summary rendering, mutation budget, and MSI regression findings
|   |-- AnalyseCliRuntimeTest.php             = `--print-runtime` summary/detail stderr JSON and default-output invariance
|   |-- DashboardCliTest.php                  = local dashboard server, scan endpoint, interactive controls, mutation UI suppression, and alternate project roots
|   |-- InitCliTest.php                       = default config creation, refusal to overwrite without force, forced regeneration, and path-ignore preservation
|   |-- ListRulesCliTest.php                  = version/list/help smoke tests and rule metadata output
|   `-- ReportCliTest.php                     = static/JSON report delegation, output writing, forwarded analysis flags, dash-prefixed paths, and no-write-on-invalid-analyse behaviour
|-- Diff/
|   `-- GitDiffProviderTest.php               = changed-line filtering, unstaged git diff parsing, non-git diff errors
|-- Finding/
|   `-- FindingTest.php                       = `Finding::toArray()` shape and `fingerprint()` stability
|-- Mutation/
|   `-- InfectionReportParserTest.php         = full Infection JSON parser, path normalisation, MSI/per-file summaries, malformed report handling
|-- Parser/
|   `-- PhpFileParserTest.php                 = valid parse, syntax-error diagnostics, parent-connecting visitor
|-- Reporting/
|   `-- HtmlReporterTest.php                  = HTML report section rendering and malicious string escaping
|-- Review/
|   `-- AgentWorkflowCliTest.php              = list-rules, display filters, SARIF, and branch-review CLI coverage
|-- Source/
|   `-- SourceDiscoveryTest.php               = discovery, default/configured ignore semantics, missing-path reporting
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
|   |-- SensitiveData/
|   |   |-- SensitiveDataExpansionRulesTest.php
|   |   `-- SensitiveDataRulesTest.php
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
|   `-- ScoreCalculatorTest.php               = grade boundaries, optional mutation behavior, security penalties, profile-scoped scoring
`-- Fixtures/                                 = pillar-organised fixture tree (no milestone prefixes; descriptive subdirs)
    |-- Cli/Golden/                           = CLI reporting: text + json golden snapshots
    |-- Complexity/                           = complexity-rule source fixtures
    |-- Config/                               = flat tree of explicit config fixtures (rule disable, threshold override, selection, allowlists, opt-in heuristic enables, etc.)
    |-- DeadCode/                             = dead-code + waste rule source fixtures
    |-- Docs/                                 = documentation-rule source fixtures
    |-- Modernisation/                        = modernisation-rule source fixtures (incl. nested Controller/ for routing-style cases)
    |-- Mutation/Infection/                   = Infection JSON reports: valid, clean, baseline, malformed
    |-- Naming/                               = naming-rule source fixtures
    |-- PhpUnitConfig/                        = PHPUnit XML configs for the project-config rules: strict/, lax/, legacy-whitelist/, no-config/
    |-- Security/                             = security-rule source fixtures
    |-- SensitiveData/                        = sensitive-data rule fixtures (php, json, env-style)
    |-- Size/                                 = size-rule source fixtures
    |-- Source/                               = parser/discovery: empty/, mixed/, syntax-error/, plus Code/ holding the mutation-target SUT
    `-- TestQuality/                          = static test-quality rule fixtures: per-rule positive/negative/edge files plus the cumulative-test-quality file that exercises every rule once
```

## goat-flow harness

```text
.goat-flow/
|-- architecture.md                           = system architecture notes (this file's sibling)
|-- code-map.md                               = this repository map
|-- glossary.md                               = project and harness terms
|-- config.yaml                               = goat-flow version + configured agents
|-- hooks/                                    = shared hook scripts, including deny-dangerous and gruff-code-quality
|-- learning-loop/
|   |-- decisions/                            = ADRs, including ADR-028 for source namespace consolidation
|   |-- footguns/                             = reproducible traps with evidence
|   |-- lessons/                              = reusable workflow and verification lessons
|   `-- patterns/                             = successful repeatable approaches
|-- skill-docs/
|   |-- skill-conventions.md                  = shared full-depth skill conventions
|   |-- skill-preamble.md                     = shared goat-* skill preamble
|   |-- playbooks/                            = tool and discipline playbooks, including gruff-code-quality and code-comments
|   `-- skill-quality-testing/                = supporting docs for skill-quality-testing
|-- plans/                                    = local milestone/task workspace (gitignored content under it)
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
- CI lives in `.github/workflows/ci.yml`: `verify` runs Composer checks and preflight on PHP 8.3/8.4, `security` gates on `composer security:scan` with read-only permissions, and `security-sarif` uploads gruff SARIF on non-PR events with `security-events: write`.
- `composer.json`'s `check` script lints every committed PHP source/test file with `php -l` via `find src tests -name '*.php'` (excluding the intentional `tests/Fixtures/Source/syntax-error` fixtures), so new files are linted automatically rather than from a hand-maintained list.
- Pillars currently emitted by registered static rules: Size, Complexity, Maintainability, DeadCode, Naming, Documentation, Modernisation, Security, SensitiveData, TestQuality. Optional Infection ingestion emits Mutation findings. Other `Pillar::*` cases (Coupling, Architecture, Design) are reserved; Design emptied when the project rules were retired (ADR-026).
- Static baselines are explicit `gruff.baseline.v1` JSON files. They suppress exact fingerprint/rule/file matches only; inline suppression comments are intentionally absent in v0.1.

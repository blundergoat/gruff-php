# gruff-php

An opinionated code-quality analyser for PHP. `gruff-php` reads your project, scores it across ten pillars &mdash; size, complexity, dead code, waste, naming, documentation, modernisation, security, sensitive data, and test quality &mdash; and writes a report you can drop into a CLI, a CI annotation, an HTML page, or a local dashboard. It is heuristic, not a type checker or linter; pair it with PHPStan and PHP_CodeSniffer, not in place of them.

## Status

Pre-release: the application reports its version as `0.1.0-dev` ([`src/Console/Application.php`](src/Console/Application.php)). The package is not on Packagist yet, schemas may shift, and the CLI surface is consumed from source. See [`CHANGELOG.md`](CHANGELOG.md) for the running list of foundation work.

## Requirements

- PHP `^8.3` ([`composer.json`](composer.json))
- Composer 2.x

Runtime dependencies: `nikic/php-parser`, `symfony/console`, `symfony/finder`, `symfony/process`. Infection is optional and only required if you opt into mutation analysis.

## Install

From source today:

```bash
git clone <repo-url> gruff-php
cd gruff-php
composer install
php bin/gruff --help
```

Once the package is published to Packagist, the convenient form will be:

```bash
composer require --dev devgoat/gruff-php
vendor/bin/gruff --help
```

## Quick start

```bash
# Analyse the current project
php bin/gruff analyse

# Analyse a directory or specific files
php bin/gruff analyse src/
php bin/gruff analyse src/Service/UserService.php src/Controller/

# JSON output for CI
php bin/gruff analyse src/ --format=json

# Fail the run on warnings as well as errors (default fails on errors only)
php bin/gruff analyse src/ --fail-on=warning

# Use a custom config (default: auto-load .gruff.yaml from the project root)
php bin/gruff analyse src/ --config=path/to/gruff.yaml

# Skip the auto-loaded .gruff.yaml for a single run
php bin/gruff analyse src/ --no-config
```

## Commands

| Command | Purpose |
|---------|---------|
| [`analyse`](src/Command/AnalyseCommand.php) | Run the rule registry over the supplied paths and emit a report in the chosen format. The main command. |
| [`summary`](src/Command/SummaryCommand.php) | Run a scan and print a compact digest only &mdash; composite score, per-pillar finding counts, top rules, top file offenders &mdash; with no per-finding spam. See [`docs/gruff-cli-summary.md`](docs/gruff-cli-summary.md). |
| [`report`](src/Command/ReportCommand.php) | Convenience wrapper around `analyse` for static HTML or JSON reports written to stdout or `--output <file>`. |
| [`dashboard`](src/Command/DashboardCommand.php) | Serve a local interactive dashboard (default `127.0.0.1:8765`) that re-runs scans against the current or another project root on demand. |
| [`list-rules`](src/Command/ListRulesCommand.php) | Print rule metadata (id, pillar, default severity, description) as a table or JSON. |

`php bin/gruff list` shows the full Symfony Console listing including `help` and `completion`.

```bash
# Quick digest of the project (text)
php bin/gruff summary src/

# JSON for tooling (schema: gruff.summary.v1)
php bin/gruff summary src/ --format=json --top=5
```

## Output formats

`--format` accepts ([`src/Reporting/OutputFormat.php`](src/Reporting/OutputFormat.php)):

| Value | Renderer | Use it for |
|-------|----------|------------|
| `text` (default) | [`TextReporter`](src/Reporting/TextReporter.php) | Local terminal output |
| `json` | [`JsonReporter`](src/Reporting/JsonReporter.php) | CI ingestion (schema `gruff.analysis.v1`) |
| `html` | [`HtmlReporter`](src/Reporting/HtmlReporter.php) | Self-contained shareable report; supports `--report-editor-link=vscode\|phpstorm\|none` and opt-in `--report-interactive` filters |
| `markdown` | [`MarkdownReporter`](src/Reporting/MarkdownReporter.php) | PR-comment style summary |
| `github` | [`GithubAnnotationsReporter`](src/Reporting/GithubAnnotationsReporter.php) | GitHub Actions inline annotations |
| `hotspot` | [`HotspotReporter`](src/Reporting/HotspotReporter.php) | JSON hotspot map keyed on file scores |
| `sarif` | [`SarifReporter`](src/Reporting/SarifReporter.php) | SARIF 2.1.0 for code-scanning ingestion (GitHub Code Scanning, Sonar, etc.) |

## Severity, fail thresholds, exit codes

Findings carry a severity from `Severity` ([`src/Finding/Severity.php`](src/Finding/Severity.php)): `advisory`, `warning`, or `error`. `--fail-on` chooses which severities flip a non-zero exit code ([`src/Reporting/FailThreshold.php`](src/Reporting/FailThreshold.php)):

| `--fail-on` | Effect |
|-------------|--------|
| `none` | Never fails on findings |
| `advisory` | Fails on any finding |
| `warning` | Fails on warning or error |
| `error` (default) | Fails only on error |

Exit codes:

| Code | Meaning |
|------|---------|
| `0` | Clean or below `--fail-on` threshold |
| `1` | At least one finding meets the threshold |
| `2` | A run diagnostic was recorded (config error, parse error, missing input path, mutation tool failure, diff-mode failure, baseline error, history-file error). Diagnostics always force `2`. |

## Rule pillars

The default registry seeds rules for ten active pillars ([`src/Rule/RuleRegistry.php`](src/Rule/RuleRegistry.php)). Each rule is configurable in `.gruff.yaml` by id; click a directory link to see every rule and its `RuleDefinition` defaults.

| Pillar | Source | Examples |
|--------|--------|----------|
| Size | [`src/Rule/Size/`](src/Rule/Size/) | `size.file-length`, `size.method-length`, `size.parameter-count` |
| Complexity | [`src/Rule/Complexity/`](src/Rule/Complexity/) | `complexity.cyclomatic`, `complexity.cognitive`, `complexity.maintainability-index` |
| DeadCode | [`src/Rule/DeadCode/`](src/Rule/DeadCode/) | `dead-code.unused-private-method`, `dead-code.unused-private-property` |
| Waste | [`src/Rule/Waste/`](src/Rule/Waste/) | `waste.unused-import`, `waste.unreachable-code`, `waste.commented-out-code` |
| Naming | [`src/Rule/Naming/`](src/Rule/Naming/) | `naming.short-variable`, `naming.boolean-prefix`, `naming.class-file-mismatch` |
| Documentation | [`src/Rule/Docs/`](src/Rule/Docs/) | `docs.missing-public-phpdoc`, `docs.stale-param-tag`, `docs.missing-readme` |
| Modernisation | [`src/Rule/Modernisation/`](src/Rule/Modernisation/) | `modernisation.constructor-promotion-candidate`, `modernisation.readonly-property-candidate`, `modernisation.match-expression-candidate` |
| Security | [`src/Rule/Security/`](src/Rule/Security/) | `security.sql-concatenation`, `security.unsafe-unserialize`, `security.weak-crypto` |
| SensitiveData | [`src/Rule/SensitiveData/`](src/Rule/SensitiveData/) | `sensitive-data.aws-access-key`, `sensitive-data.private-key`, `sensitive-data.high-entropy-string`. Also scans non-PHP text/config files via [`SourceTextRuleInterface`](src/Rule/SourceTextRuleInterface.php). |
| TestQuality | [`src/Rule/TestQuality/`](src/Rule/TestQuality/) | `test-quality.no-assertions`, `test-quality.excessive-mocking`, `test-quality.eager-test`. Three rules ship default-off: `test-quality.multiple-aaa-cycles`, `test-quality.testdox-readability`, `test-quality.mocking-domain-object`. |

Two emergent finding sources are not registry-backed:

- **Design composite** (`design.god-method`) — emitted by [`CompositeFindingFactory`](src/Scoring/CompositeFindingFactory.php) when size and complexity findings overlap on the same symbol.
- **Mutation findings** (`mutation.survived-mutant`, `mutation.budget-exceeded`, `mutation.msi-regression`) — only emitted when an Infection JSON report is ingested (see [Mutation analysis](#mutation-analysis-optional)).

A few representative thresholds (warn / error) for the most-tuned rules &mdash; see each rule file for full defaults:

| Rule | Default warn / error |
|------|----------------------|
| `size.file-length` | 400 / 800 |
| `size.class-length` | 300 / 500 |
| `size.method-length` | 30 / 60 |
| `size.parameter-count` | 5 / 8 |
| `complexity.cyclomatic` | 10 / 20 |
| `complexity.cognitive` | 15 / 30 |
| `complexity.nesting-depth` | 4 / 6 |
| `complexity.maintainability-index` | 55 / 35 (lower scores are worse) |

## Configuration

Place a `.gruff.yaml` at the project root and `analyse` will pick it up automatically; pass `--config=path` to point at another file or `--no-config` to skip auto-load for one run ([`src/Config/ConfigLoader.php`](src/Config/ConfigLoader.php)).

```yaml
minimumPhpVersion: 8.3

paths:
  ignore:
    - tests/Fixtures/**
    - generated

selection:
  tiers: [v0.1]
  pillars: [security, sensitive-data]
  rules: []
  excludePillars: [documentation]
  excludeRules: [security.weak-crypto]

allowlists:
  acceptedAbbreviations: [id, db]
  secretPreviews: []

rules:
  size.method-length:
    thresholds:
      warning: 40
      error: 80
  complexity.cyclomatic:
    enabled: false
  test-quality.excessive-mocking:
    thresholds:
      maxMocks: 5
  test-quality.magic-number-assertion:
    options:
      allowedLiterals: [200, 201, 404, 500]
```

Top-level keys, recognised by `ConfigLoader::assertKnownRootKeys()`:

| Key | Purpose |
|-----|---------|
| `minimumPhpVersion` | Numeric, ≥ `7.4`. PHP-version-gated modernisation rules respect it. Default `8.3`. |
| `paths.ignore` | Project-relative exact or glob patterns (`*`, `?`, `**`). Cannot escape the project root. |
| `selection.{tiers,pillars,rules,excludePillars,excludeRules}` | Explicit include/exclude over the rule set. If any include list is non-empty, a rule must match at least one include. |
| `allowlists.acceptedAbbreviations` | Identifier tokens the naming rules will accept (e.g. `id`, `db`). |
| `allowlists.secretPreviews` | Redacted previews to suppress for `SensitiveData` findings &mdash; copy them from a previous gruff report. |
| `rules.<id>` | Per-rule `enabled` / `thresholds` / `options`. Threshold and option names must already exist in the rule's `RuleDefinition`; unknown keys raise a `ConfigException`. |

## Baselines

Suppress known findings without disabling rules ([`src/Baseline/`](src/Baseline/)).

```bash
# Snapshot current findings into gruff-baseline.json (project root)
php bin/gruff analyse --generate-baseline

# Subsequent runs auto-discover gruff-baseline.json and apply it
php bin/gruff analyse

# Force an explicit file
php bin/gruff analyse --baseline=baselines/v0.1.json

# Skip the auto-loaded baseline for one run
php bin/gruff analyse --no-baseline
```

The baseline is a `gruff.baseline.v1` JSON file that suppresses by fingerprint, rule id, and file path. New findings still surface; stale entries (matches that no longer exist) are reported in full-project scope. Diff scope skips stale evaluation by design.

## Diff filtering

Limit findings to lines or files touched by a change ([`src/Diff/GitDiffProvider.php`](src/Diff/GitDiffProvider.php)):

```bash
# Compare against the working tree (default when --diff is bare)
php bin/gruff analyse --diff

# Other modes
php bin/gruff analyse --diff=staged
php bin/gruff analyse --diff=unstaged
php bin/gruff analyse --diff=origin/main
```

Findings without a line number are kept when their file changed; findings with a line number are kept only when they touch a changed line range.

### Compare against a base ref

`--diff-vs=<ref>` re-runs the analyser against a base ref and reports each finding as introduced, removed, or unchanged relative to the working tree. Pair with `--changed-only` to restrict the comparison to files that differ from the base ref.

```bash
php bin/gruff analyse --diff-vs=origin/main
php bin/gruff analyse --diff-vs=origin/main --changed-only
```

## Display filters

Reduce report noise without changing what fails the run. These flags shape the output only and never disable rules ([`src/Reporting/FindingDisplayFilter.php`](src/Reporting/FindingDisplayFilter.php)):

| Flag | Effect |
|------|--------|
| `--min-severity=advisory\|warning\|error` | Hide findings below the threshold |
| `--include-pillar=<csv>` (repeatable) | Show only the named pillars |
| `--exclude-pillar=<csv>` (repeatable) | Hide the named pillars |
| `--include-rule=<csv>` (repeatable) | Show only the named rule ids |
| `--exclude-rule=<csv>` (repeatable) | Hide the named rule ids |
| `--paths-relative-to=<dir>` | Rewrite absolute finding paths relative to `<dir>` for clean reports |

## Trend history

`--history-file=path.json` appends a bounded score-history entry per run ([`src/Trend/TrendRecorder.php`](src/Trend/TrendRecorder.php)). The file is created if it does not exist; each entry captures the composite score, grade, and finding count. Nothing is written by default.

## Mutation analysis (optional)

`gruff-php` does not run Infection by default. Two opt-in modes integrate with [Infection](https://infection.github.io/) ([`src/Mutation/`](src/Mutation/)):

```bash
# Ingest a pre-existing Infection JSON report
php bin/gruff analyse --infection-report=infection-report.json

# Run Infection inline, then ingest its JSON output
php bin/gruff analyse \
  --infection-run \
  --infection-report=infection-report.json \
  --infection-bin=infection \
  --infection-config=infection.json5 \
  --infection-test-framework-options="--filter=ServiceTest"

# Diff MSI against a baseline report and enforce a budget
php bin/gruff analyse \
  --infection-report=infection-report.json \
  --mutation-baseline=baseline-infection.json \
  --mutation-budget=3
```

The dashboard exposes a "Run mutation analysis" button when no report is present yet ([`src/Command/DashboardCommand.php`](src/Command/DashboardCommand.php)).

## Dashboard

```bash
# Defaults to http://127.0.0.1:8765
php bin/gruff dashboard
php bin/gruff dashboard --host=0.0.0.0 --port=9000
php bin/gruff dashboard --project=/path/to/another/project

# Start in diff-only scan mode
php bin/gruff dashboard --diff

# Cap each refresh scan (seconds; 0 disables the timeout, default 120)
php bin/gruff dashboard --scan-timeout=300
```

The dashboard renders the HTML report inside a control panel with project root, scope (whole project vs `--diff`), config path, baseline, fail threshold, include-ignored, and interactive-findings toggles. `GET /scan` re-runs the analyser; `GET /health` returns a smoke-test response. `scripts/start-dev.sh` is a convenience wrapper with environment-overridable host, port, project root, and scan timeout.

## Development

```bash
composer install
composer check    # composer validate, php -l on every committed PHP file, PHPStan level 10
composer test     # PHPUnit 11
bash scripts/preflight-checks.sh  # PHPStan + PHPUnit with a coloured pass/fail summary
```

The `composer check`/`preflight-checks.sh` pair is what CI runs (see [`.github/workflows/ci.yml`](.github/workflows/ci.yml)). Mutation testing has dedicated scripts: [`scripts/mutation-test-diff.sh`](scripts/mutation-test-diff.sh) for fast diff-scoped runs and [`scripts/mutation-test-full.sh`](scripts/mutation-test-full.sh) for full-project runs.

For deeper internals see [`.goat-flow/architecture.md`](.goat-flow/architecture.md), [`.goat-flow/code-map.md`](.goat-flow/code-map.md), and [`.goat-flow/glossary.md`](.goat-flow/glossary.md).

## License

Proprietary. See [`composer.json`](composer.json). A public license has not been chosen yet.

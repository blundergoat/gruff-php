# gruff-php

`gruff-php` is an opinionated PHP code-quality analyzer. It scans PHP projects,
scores findings across rule pillars, and emits reports for terminals, CI,
GitHub annotations, SARIF consumers, static HTML, and a local dashboard.

It is heuristic tooling. Use it alongside PHPStan, PHPUnit, PHP-CS-Fixer,
Psalm, PHPCS, or other project-specific gates; do not treat it as a replacement
for type checking or tests.

## Release Status

The current application version is `0.1.0-dev`. This repository is being
prepared for a public `0.1.0` release.

Current package facts:

- Package name: `devgoat/gruff-php`
- Binary: `bin/gruff-php`
- PHP requirement: `^8.3`
- Runtime dependencies: `nikic/php-parser`, Symfony Console/Finder/Process/Yaml
- Rule catalogue: 110 registry rules across 11 pillars
- Config format: YAML only (`.yaml` / `.yml`)
- License in `composer.json`: `proprietary`

If this project will be released as open source, choose a license and add a
`LICENSE` file before tagging `0.1.0`.

## Installation

From a checkout:

```bash
git clone <repo-url> gruff-php
cd gruff-php
composer install
php bin/gruff-php --help
```

After Packagist publication:

```bash
composer require --dev devgoat/gruff-php
vendor/bin/gruff-php --help
```

## Quick Start

```bash
# Analyze the current project
php bin/gruff-php analyse

# Analyze selected paths
php bin/gruff-php analyse src tests

# Keep the process green while inspecting findings
php bin/gruff-php analyse --fail-on none

# JSON for automation
php bin/gruff-php analyse --format json --fail-on none > gruff-report.json

# Markdown for PR comments
php bin/gruff-php analyse --format markdown --fail-on none > gruff-report.md

# SARIF for code-scanning ingestion
php bin/gruff-php analyse --format sarif --fail-on none > gruff.sarif

# Static HTML report
php bin/gruff-php report --format html --output gruff-report.html

# Local dashboard
php bin/gruff-php dashboard
```

The default fail threshold is `error`, so warning and advisory findings do not
fail the command unless you opt in:

```bash
php bin/gruff-php analyse --fail-on warning
php bin/gruff-php analyse --fail-on advisory
```

## Commands

| Command | Purpose |
| --- | --- |
| `analyse` | Run the analyzer and print findings in `text`, `json`, `html`, `markdown`, `github`, `hotspot`, or `sarif` format. |
| `summary` | Print a compact score and top-offender digest without the full finding list. |
| `report` | Render an HTML or JSON report to stdout or `--output`. |
| `dashboard` | Serve a local dashboard on `127.0.0.1:8765` by default. |
| `list-rules` | Print rule metadata as a table or JSON. |

Symfony Console also provides `list`, `help`, and shell completion support:

```bash
php bin/gruff-php list
php bin/gruff-php analyse --help
php bin/gruff-php list-rules --format json
```

## Output Formats

`analyse --format` accepts:

| Format | Intended use |
| --- | --- |
| `text` | Human CLI output. |
| `json` | Machine-readable `gruff.analysis.v1` reports. |
| `html` | Self-contained report output. |
| `markdown` | PR comment or issue summary text. |
| `github` | GitHub Actions workflow annotations. |
| `hotspot` | JSON hotspot map keyed around file scores. |
| `sarif` | SARIF 2.1.0 for code-scanning ingestion. |

`report --format` currently accepts `html` and `json`.

## Exit Codes

| Code | Meaning |
| --- | --- |
| `0` | The run completed and no finding met the `--fail-on` threshold. |
| `1` | At least one finding met the `--fail-on` threshold. |
| `2` | A run diagnostic occurred, such as config failure, missing path, parse error, baseline error, history-file error, diff failure, or mutation-tool failure. |

## Rule Pillars

`list-rules --format json` is the source of truth for rule metadata. The v0.1
catalogue includes these pillars:

- `size`
- `complexity`
- `maintainability`
- `dead-code`
- `naming`
- `documentation`
- `modernisation`
- `security`
- `sensitive-data`
- `test-quality`
- `design`

Representative rule IDs:

```text
size.method-length
complexity.cyclomatic
waste.unused-import
naming.identifier-quality
docs.missing-param-tag
modernisation.readonly-property-candidate
security.unsafe-unserialize
sensitive-data.high-entropy-string
test-quality.no-assertions
design.single-implementor-interface
```

Some dead-code pillar rules keep the `waste.*` rule-id prefix for historical
continuity. Use the `pillar` field from `list-rules --format json` when
filtering by pillar.

Use this command to inspect the full catalogue:

```bash
php bin/gruff-php list-rules
php bin/gruff-php list-rules --format json
```

## Configuration

Place `.gruff-php.yaml` in the project root. `analyse`, `report`, and `dashboard`
auto-load it unless `--no-config` is supplied. Legacy `.gruff.yaml` files are
still auto-loaded when `.gruff-php.yaml` is absent. You can also pass
`--config=path/to/file.yaml`.

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
  excludePillars: []
  excludeRules: []

allowlists:
  acceptedAbbreviations: [id, db]
  secretPreviews: []

rules:
  size.method-length:
    threshold: 80
    severity: error
  complexity.cyclomatic:
    enabled: false
  test-quality.magic-number-assertion:
    options:
      allowedLiterals: [200, 201, 404, 500]
```

Supported top-level keys:

| Key | Purpose |
| --- | --- |
| `minimumPhpVersion` | Minimum PHP version used by version-gated modernisation rules. |
| `paths.ignore` | Project-relative paths or glob patterns to skip. |
| `selection` | Include or exclude rules by tier, pillar, or rule id. |
| `allowlists.acceptedAbbreviations` | Naming tokens accepted by naming rules. |
| `allowlists.secretPreviews` | Redacted sensitive-data previews to suppress after review. |
| `rules.<id>` | Per-rule `enabled`, `threshold`, `severity`, `thresholds`, or `options`. |

Unknown keys are rejected. Threshold and option names must match the rule's
definition.

## Baselines

Baselines suppress known findings by fingerprint without disabling rules.

```bash
# Write gruff-baseline.json in the project root
php bin/gruff-php analyse --generate-baseline

# Auto-apply gruff-baseline.json when it exists
php bin/gruff-php analyse

# Use an explicit baseline file
php bin/gruff-php analyse --baseline=baselines/release.json

# Skip auto-baseline for one run
php bin/gruff-php analyse --no-baseline
```

Baseline files use schema `gruff.baseline.v1`. Full-project scans report stale
baseline entries when a stored finding no longer exists.

## Diff And Branch Review

Filter findings to changed files or changed lines:

```bash
php bin/gruff-php analyse --diff
php bin/gruff-php analyse --diff=staged
php bin/gruff-php analyse --diff=unstaged
php bin/gruff-php analyse --diff=origin/main
```

Compare current findings against a base ref:

```bash
php bin/gruff-php analyse --diff-vs=origin/main
php bin/gruff-php analyse --diff-vs=origin/main --changed-only
```

See [`docs/gruff-cli-branch-review.md`](docs/gruff-cli-branch-review.md) for
agent-oriented branch review usage.

## Display Filters

Display filters reduce report noise without changing what rules run or what
fails the command:

```bash
php bin/gruff-php analyse --min-severity warning
php bin/gruff-php analyse --include-pillar security --include-pillar sensitive-data
php bin/gruff-php analyse --exclude-rule test-quality.mystery-guest
php bin/gruff-php analyse --paths-relative-to "$PWD"
```

## Mutation Analysis

Mutation analysis is optional. `gruff-php` can ingest an Infection JSON report
or run Infection before ingesting the report path you provide.

```bash
php bin/gruff-php analyse --infection-report=infection-report.json

php bin/gruff-php analyse \
  --infection-run \
  --infection-report=infection-report.json \
  --infection-bin=infection \
  --infection-config=infection.json5

php bin/gruff-php analyse \
  --infection-report=infection-report.json \
  --mutation-baseline=baseline-infection.json \
  --mutation-budget=3
```

The dashboard does not run mutation analysis.

## Dashboard

```bash
php bin/gruff-php dashboard
php bin/gruff-php dashboard --host=0.0.0.0 --port=9000
php bin/gruff-php dashboard --project=/path/to/project
php bin/gruff-php dashboard --diff
php bin/gruff-php dashboard --scan-timeout=300
```

The dashboard serves a local control page and refresh endpoint. It is intended
for local development, not as a public network service.

## Development

```bash
composer install
composer check
composer test
bash scripts/preflight-checks.sh
```

Useful scripts:

| Command | Purpose |
| --- | --- |
| `composer check` | Composer validation, shell syntax checks, PHP syntax checks, PHPStan. |
| `composer test` | PHPUnit test suite. |
| `composer perf` | Wall, peak-memory, per-rule timing vs. a local baseline. See below. |
| `composer format` | Apply PHP-CS-Fixer formatting. |
| `composer format:check` | Check PHP-CS-Fixer formatting. |
| `scripts/mutation-test-diff.sh` | Diff-scoped Infection workflow. |
| `scripts/mutation-test-full.sh` | Full Infection workflow. |
| `scripts/start-dev.sh` | Start the local dashboard. |

### Performance harness

`composer perf` runs `php bin/gruff-php analyse` against three corpora (`src/Diff`,
`src/`, full self-scan), captures wall time and peak memory, and compares each
result to `.goat-flow/logs/perf/m50-baseline/baseline.json`. Use
`composer perf -- --baseline --yes` to overwrite the baseline after intentional
rule or threshold changes. Baselines are machine- and PHP-version-specific —
regenerate locally rather than committing a CI-host baseline. Tolerances default
to wall +20%, peak +25%; override with `GRUFF_PERF_WALL_TOLERANCE` /
`GRUFF_PERF_MEM_TOLERANCE`. Use `--quick` for a single-corpus, no-warmup run.

CI runs on PHP 8.3 and 8.4 via [`.github/workflows/ci.yml`](.github/workflows/ci.yml).

## Public Release Checklist

Before tagging `0.1.0`:

- Choose the public license and add `LICENSE` if this is not staying proprietary.
- Confirm Packagist metadata and repository URL.
- Run `composer validate --strict`, `composer check`, and `composer test`.
- Run `php bin/gruff-php analyse` and confirm the default self-scan exits 0.
- Review [`CHANGELOG.md`](CHANGELOG.md).
- Review [`SECURITY.md`](SECURITY.md) and replace any private-contact placeholder with the real reporting channel.
- Confirm generated artifacts such as `history.json`, `infection-report.json`, and local caches are not part of the release archive.

## More Documentation

- [`CHANGELOG.md`](CHANGELOG.md)
- [`CONTRIBUTING.md`](CONTRIBUTING.md)
- [`SECURITY.md`](SECURITY.md)
- [`SUPPORT.md`](SUPPORT.md)
- [`docs/gruff-cli-summary.md`](docs/gruff-cli-summary.md)
- [`docs/gruff-cli-agent-instructions.md`](docs/gruff-cli-agent-instructions.md)
- [`docs/gruff-cli-branch-review.md`](docs/gruff-cli-branch-review.md)
- [`docs/naming-conventions.md`](docs/naming-conventions.md)

## License

`composer.json` currently declares this package as `proprietary`. Add a
`LICENSE` file and update `composer.json` before an open-source release.

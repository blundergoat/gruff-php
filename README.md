# gruff-php

[![Latest Version on Packagist](https://img.shields.io/packagist/v/blundergoat/gruff-php.svg?style=flat-square)](https://packagist.org/packages/blundergoat/gruff-php)
[![Total Downloads](https://img.shields.io/packagist/dt/blundergoat/gruff-php.svg?style=flat-square)](https://packagist.org/packages/blundergoat/gruff-php)
[![PHP Version Require](https://img.shields.io/packagist/dependency-v/blundergoat/gruff-php/php?style=flat-square)](https://packagist.org/packages/blundergoat/gruff-php)
[![CI](https://img.shields.io/github/actions/workflow/status/blundergoat/gruff-php/ci.yml?branch=main&style=flat-square&label=CI)](https://github.com/blundergoat/gruff-php/actions/workflows/ci.yml)
[![License](https://img.shields.io/packagist/l/blundergoat/gruff-php.svg?style=flat-square)](LICENSE)

`gruff-php` is an opinionated PHP code-quality analyzer. It scans PHP projects, scores findings across quality pillars, and emits reports for terminals, CI annotations, SARIF consumers, static HTML, and a local dashboard. It is heuristic static analysis; run it beside PHPStan, Psalm, PHPUnit, PHP-CS-Fixer, PHPCS, security scanners, and code review, not instead of them.

## Mission

gruff-php exists to make AI-generated code safe for a human to sign off on. When a coding agent writes the code, someone who didn't write it still has to read, review, and trust it — and agents routinely produce code that superficially works while quietly misunderstanding the requirement. gruff governs that code so a reviewer can actually verify it does what was asked:

- **Legible enough to verify.** Complexity and nesting are capped, and every method — public or private — must carry an intent-bearing doc comment that states what it is for, what it returns at the edges, and what the caller must satisfy. The comment gives the reviewer a plain-English contract to check the code against; a doc comment that contradicts the implementation is a signal the change needs a deeper look.
- **Secure where the eye fails.** Security and sensitive-data rules catch the classes of mistake a human reviewer skims past.
- **Tested for real, not padded.** Test-quality rules reward genuine assertions and flag low-signal ceremony, so a green suite means the behaviour is actually exercised rather than mocked into a tautology.

Wired into a coding agent's loop — as a pre-commit hook, a CI gate (`--fail-on`), or the agent's own verification step — gruff pushes the agent to keep producing code a human can confidently approve, not just code that compiles and passes. See [`docs/mission.md`](docs/mission.md) for the full rationale.

## Status At A Glance

| Field | Value |
| --- | --- |
| Current source | `0.5.2` |
| Runtime | PHP `^8.3` |
| Package | `blundergoat/gruff-php` |
| Binary | `bin/gruff-php` from checkout; `vendor/bin/gruff-php` after install |
| Rule catalogue | 128 rules across 10 pillars |
| Primary config | `.gruff-php.yaml`; legacy `.gruff.yaml` is accepted when the primary file is absent |
| Analysis schema | `gruff.analysis.v2` |
| Baseline schema | `gruff.baseline.v2` |
| Severity gate | `--fail-on` with `none`, `advisory`, `warning`, `error` |
| Dashboard | `127.0.0.1:8765` by default |

## Requirements

- PHP `8.3+`.
- Composer for dependency installation.
- Git only for diff and branch-review modes.
- Infection only when mutation-analysis flags are used.

Runtime dependencies are `nikic/php-parser` plus Symfony Console, Finder, Process, and Yaml.

## Install

Install as a project dev dependency:

```bash
composer require --dev blundergoat/gruff-php
vendor/bin/gruff-php init
vendor/bin/gruff-php summary
```

From a source checkout:

```bash
git clone https://github.com/blundergoat/gruff-php.git
cd gruff-php
composer install
php bin/gruff-php --help
```

## Quick Start

```bash
# Create the project config.
vendor/bin/gruff-php init

# Review the current finding mix.
vendor/bin/gruff-php summary

# Explore without failing because of findings.
vendor/bin/gruff-php analyse --fail-on none

# Gate on warning and error findings.
vendor/bin/gruff-php analyse --fail-on warning

# Emit SARIF for code scanning.
vendor/bin/gruff-php analyse --format sarif --fail-on none > gruff.sarif

# Generate a fresh-start baseline.
vendor/bin/gruff-php analyse --generate-baseline --fail-on none

# Start the local dashboard.
vendor/bin/gruff-php dashboard
```

## Commands

| Command | Purpose |
| --- | --- |
| `analyse [paths...]` | Run the analyzer and print findings. |
| `summary [paths...]` | Print compact score, pillar, rule, and file summaries. |
| `report [paths...]` | Render an HTML or JSON report to stdout or `--output`. |
| `hook [paths...]` | Run gruff-php using the cross-analyzer agent-hook contract; emits `gruff.hook.v2` JSON. |
| `dashboard` | Serve the local browser dashboard. |
| `init` | Write a default `.gruff-php.yaml` populated with registry defaults. |
| `migrate-config` | Rewrite a 0.5 config for the current schema, writing the result to a different file. |
| `check-ignore <paths...>` | Report whether gruff would ignore each path, with the matching source and pattern. |
| `list-rules [rule-id]` | Print rule metadata as a table or JSON, or show one rule's detail view. |
| `list`, `help`, `completion` | Symfony Console command catalogue, help, and shell completion support. |

## Output Formats

`analyse --format <fmt>` accepts:

| Format | Use it for |
| --- | --- |
| `text` | Human terminal output. |
| `json` | Full `gruff.analysis.v2` report. |
| `html` | Self-contained inspection report. |
| `markdown` | Pull-request or issue comment summary. |
| `github` | GitHub Actions workflow annotations. |
| `hotspot` | File-level hotspot JSON. |
| `sarif` | SARIF 2.1.0 for code scanning. |

`report --format <fmt>` accepts `html` and `json`.

## Exit Codes

| Code | Meaning |
| --- | --- |
| `0` | Run completed and no finding met `--fail-on`. |
| `1` | At least one finding met `--fail-on`. |
| `2` | Fatal diagnostic such as config failure, missing path, parse error, baseline error, history-file error, diff failure, mutation-tool failure, or invalid input. |

`analyse` defaults to `--fail-on advisory`.

## CI Usage

Generic CI command:

```bash
vendor/bin/gruff-php analyse --format github --fail-on warning
```

GitHub Actions SARIF jobs can install dependencies, run the analyzer, then upload `gruff-php.sarif`:

```yaml
jobs:
  gruff:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.3"
      - run: composer install --no-interaction --prefer-dist
      - run: vendor/bin/gruff-php analyse --format sarif --fail-on none > gruff-php.sarif
```

Security-focused gates can bypass adoption baselines:

```bash
vendor/bin/gruff-php analyse --profile security --no-baseline --fail-on warning
```

`--profile security` is a scoring contract as well as an execution filter:
`--include-rule` ids must belong to the security or sensitive-data pillars, and
any other pillar's rule id is a usage error so a non-security finding can never
hide behind a security-only composite. `--exclude-rule` still narrows the
profile's rule set freely.

## Configuration

Place `.gruff-php.yaml` in the project root. `analyse`, `report`, and `dashboard` auto-load it unless `--config <path>` or `--no-config` is supplied. Legacy `.gruff.yaml` files are still auto-loaded when `.gruff-php.yaml` is absent. Unknown keys and unsupported rule options fail closed.

```yaml
schemaVersion: gruff-php.config.v0.1
minimumPhpVersion: 8.3

paths:
  ignore:
    - tests/Fixtures/**
    - generated

selection:
  tiers: [v0.1]
  pillars: [security, sensitive-data]
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
```

Use `vendor/bin/gruff-php list-rules --format json` to inspect supported thresholds and options.

## Rules And Pillars

The v0.1 catalogue contains 128 registry rules:

| Pillar | Rules |
| --- | ---: |
| `size` | 7 |
| `complexity` | 4 |
| `maintainability` | 2 |
| `dead-code` | 10 |
| `naming` | 11 |
| `documentation` | 15 |
| `modernisation` | 9 |
| `security` | 25 |
| `sensitive-data` | 11 |
| `test-quality` | 34 |

Some dead-code pillar rules keep a `waste.*` rule-id prefix for historical continuity. Filter by the `pillar` field from `list-rules --format json` when the pillar matters more than the rule-id prefix.

## Baselines And Changed-Code Scans

Baselines suppress reviewed findings by grouped counts: each `gruff.baseline.v2`
row accepts `count` instances of one `(file, ruleId, message)` identity, so
accepted debt keeps matching after unrelated edits shift line numbers. Instances
beyond a group's accepted count report as new; missing instances report as
resolved.

```bash
vendor/bin/gruff-php analyse --generate-baseline --fail-on none
vendor/bin/gruff-php analyse --baseline=gruff-baseline.json --fail-on warning
vendor/bin/gruff-php analyse --no-baseline --fail-on none
```

Because the finding message is part of the match key, releases that reword a
rule's message invalidate the affected baseline groups — regenerate the baseline
after upgrading across such a release (0.5.0 rewords several rule messages; see
`CHANGELOG.md`). Legacy `gruff.baseline.v1` files fail closed with a regenerate
instruction rather than parsing silently. Known blind spot: fixing one accepted
instance while adding a different one with the same file, rule, and message
keeps the group within budget and reports as unchanged.

Changed-code scans can filter to symbol-aware changed regions and report how many findings were suppressed as out of scope:

```bash
vendor/bin/gruff-php analyse --format json --changed-ranges "3-3,8-10" src/Example.php --fail-on none
vendor/bin/gruff-php analyse --format json --since HEAD src/Example.php --fail-on none
git diff | vendor/bin/gruff-php analyse --format json --diff - --fail-on none
```

Bare `--diff` compares the working tree to `HEAD`. `--changed-scope=symbol` is the default and keeps ordinary findings whose own location or enclosing declaration overlaps a changed hunk; file/class aggregate findings are kept only when the hunk touches their reported anchor. Use `--changed-scope=hunk` for strict line-span filtering, or `--changed-scope=file` when a changed-file review should keep file-level aggregates and class aggregate findings whose reported span overlaps the hunk. JSON output includes top-level `suppressedCount` when changed-region mode is active.

Branch review compares against a base ref:

```bash
vendor/bin/gruff-php analyse --diff-vs=origin/main --changed-only --fail-on none
```

Display filters such as `--min-severity`, `--include-pillar`, and `--exclude-pillar` reduce rendered output without changing which rules execute. The rule-id flags `--include-rule` and `--exclude-rule` instead select rule execution, matching the hook command: an excluded rule does not run, and the exit code reflects the narrowed run.

## Mutation Analysis

Mutation analysis is optional. `gruff-php` can ingest an Infection JSON report or run Infection before ingesting the report path you provide:

```bash
vendor/bin/gruff-php analyse --infection-report=infection-report.json
vendor/bin/gruff-php analyse --infection-run --infection-report=infection-report.json
```

The dashboard does not run mutation analysis.

## Dashboard

```bash
vendor/bin/gruff-php dashboard
vendor/bin/gruff-php dashboard --host=127.0.0.1 --port=8765 --project=/path/to/project
```

The dashboard serves a local control page and refresh endpoint. It has no authentication and is intended for local development; keep it on loopback unless the network is trusted.

In polyglot repositories, remember that `gruff-go`, `gruff-php`, and `gruff-py` all default to port `8765`; use `--port` when running multiple dashboards at the same time.

## Trust Boundary

Default scans are local source inspections. `gruff-php` parses PHP files and selected project metadata; it does not execute target application code, run tests, query vulnerability feeds, or contact package registries. Git is used only for explicit diff modes. External processes are used for explicitly requested features such as Infection runs. Sensitive-data previews are redacted before they reach terminal, JSON, SARIF, GitHub, Markdown, or HTML output.

## Stability Contract

The current pre-1.0 line treats rule IDs, finding fingerprints, baseline identity, `gruff.analysis.v2`, `gruff.baseline.v2`, SARIF rendering, and CLI exit semantics as compatibility-sensitive. Breaking changes should be tagged as a future minor release and recorded in [`CHANGELOG.md`](CHANGELOG.md).

## How It Compares

| Tool | Relationship |
| --- | --- |
| PHPStan / Psalm | Type-aware static analysis. `gruff-php` adds scoring, baselines, reports, dashboard, and heuristic project-quality rules. |
| PHPUnit | Runtime tests. `gruff-php` can flag test-quality smells but does not prove behavior. |
| PHP-CS-Fixer / PHPCS | Formatting and style policy. `gruff-php` does not format code. |
| Infection | Mutation testing. `gruff-php` can ingest or run Infection, but mutation analysis remains optional. |
| Composer audit | Advisory-backed dependency checks. `gruff-php` reports local static signals and does not replace advisory feeds. |

## Development

```bash
composer install
composer check
composer test
composer format:check
bash scripts/preflight-checks.sh
```

Performance checks are available with `composer perf`; mutation workflows live in `scripts/mutation-test-diff.sh` and `scripts/mutation-test-full.sh`. Release steps belong in the release checklist and `scripts/bump-version.sh`.

## Documentation

- [Mission](docs/mission.md)
- [Changelog](CHANGELOG.md)
- [Contributing](CONTRIBUTING.md)
- [Security](SECURITY.md)
- [Support](SUPPORT.md)
- [Summary command](docs/gruff-cli-summary.md)
- [Agent instructions](docs/gruff-cli-agent-instructions.md)
- [Branch review](docs/gruff-cli-branch-review.md)
- [Naming conventions](docs/naming-conventions.md)

## Author

Built by [Matthew Hansen](https://www.blundergoat.com/about).

## License

[MIT](LICENSE)

# Changelog

All notable changes to `gruff-php` are documented here.

This project follows the spirit of [Keep a Changelog](https://keepachangelog.com/)
and uses semantic versioning once public tags begin. Before tagging, development
binaries report `0.1.0-dev`; the release notes below are the public `0.1.0`
release-candidate notes and will be stamped onto the tagged release by
`scripts/bump-version.sh`.

## 0.1.0 - Unreleased

First public release of `gruff-php`.

### Added

#### Package and Distribution

- Composer package scaffold for `devgoat/gruff-php` with PSR-4 autoloading under
  `GruffPhp\` and the `bin/gruff-php` Symfony Console binary.
- MIT license (`LICENSE`) and matching `composer.json` declaration.
- PHP `^8.3` runtime requirement; CI verifies PHP 8.3 and PHP 8.4 via
  [`.github/workflows/ci.yml`](.github/workflows/ci.yml).
- Runtime dependencies pinned to `nikic/php-parser` and Symfony
  Console/Finder/Process/Yaml.
- `scripts/bump-version.sh` to update `Application::VERSION` and stamp the
  matching `CHANGELOG.md` entry.

#### CLI Commands

- `analyse` — runs the registry over discovered paths and renders findings.
- `analyse --file=PATH` for explicit single-file scans; the option is repeatable
  and uses the same discovery, ignore, config, baseline, and reporting pipeline
  as positional paths.
- `summary` — compact per-pillar, top-rule, and top-offender digest with text
  and `gruff.summary.v1` JSON output and a configurable `--top` cap.
- `report` — renders an HTML or JSON report to stdout or `--output`.
- `dashboard` — serves a local dashboard on `127.0.0.1:8765` by default with
  configurable host, port, project, scan timeout, diff mode, baseline, and
  config controls.
- `list-rules` — prints rule metadata as a table or JSON for catalogue
  inspection.
- Symfony Console `list`, `help`, and shell completion are inherited.

#### Configuration

- YAML project configuration loaded from `.gruff-php.yaml` at the project root,
  with legacy `.gruff.yaml` accepted as a fallback.
- `--config=PATH` for explicit config selection and `--no-config` to suppress
  auto-discovery for a single run.
- Top-level `minimumPhpVersion`, `paths.ignore`, `selection`, `allowlists`,
  and `rules` keys; unknown keys are rejected to keep config explicit.
- `selection` filters by tier, pillar, and rule id, with corresponding
  `excludePillars` and `excludeRules`.
- Per-rule overrides for `enabled`, single-threshold `threshold` + `severity`
  shorthand, named `thresholds` tuning values, and rule `options`.
- `size.parameter-count` exposes `constructorMaxParameters` for opt-in
  constructor-specific caps while keeping the default strict, plus
  `promotedConstructorMaxParameters` for promoted final readonly value-object
  constructors.
- `allowlists.acceptedAbbreviations` for naming-rule vocabulary and
  `allowlists.secretPreviews` for reviewed sensitive-data previews.

#### Output Formats

- `analyse --format` accepts `text`, `json`, `html`, `markdown`, `github`,
  `hotspot`, and `sarif`.
- `report --format` accepts `html` and `json`.
- `summary --format` accepts `text` and `json`.
- `list-rules --format` accepts `table` and `json`.
- Stable finding schema with severity, pillar, tier, confidence, remediation
  metadata, and deterministic fingerprints.
- Schema versions: `gruff.analysis.v1`, `gruff.summary.v1`, and
  `gruff.baseline.v1`.
- HTML reports support `--report-editor-link` (`vscode`, `phpstorm`, `none`)
  and opt-in interactive finding filters via `--report-interactive`.

#### Source Discovery and Parsing

- PHP and text/config-like file discovery with default ignored paths and
  Gitignore-aware traversal.
- `--include-ignored` to force filesystem traversal instead of Git/default
  ignores.
- PHP parser pipeline with AST, token, parent-node, and parse-diagnostic
  support.

#### Baselines

- Baseline generation with `--generate-baseline` (defaults to
  `gruff-baseline.json` at the project root).
- Automatic baseline application when `gruff-baseline.json` is present, or
  via `--baseline=PATH`.
- `--no-baseline` to skip auto-application for a single run.
- Stale-entry reporting for full-project scans.

#### Diff Filtering and Branch Review

- `--diff[=working-tree|staged|unstaged|<base-ref>]` filters findings to
  changed lines or files via Git.
- `--diff-vs=<base-ref>` compares current findings against a base snapshot and
  reports `introduced`, `removed`, and `unchanged` findings.
- `--changed-only` narrows `--diff-vs` to files changed against the base ref;
  with no explicit paths, gruff derives the changed file list from Git.

#### Display Filters

- `--min-severity`, `--include-pillar`, `--exclude-pillar`, `--include-rule`,
  `--exclude-rule`, and `--paths-relative-to` reshape report contents without
  altering rule execution, scoring, or baseline semantics.

#### Scoring and Trends

- Composite and per-pillar grade calculation with file-level penalties.
- Optional trend recording via `--history-file=PATH`.

#### Mutation Analysis

- Infection JSON report ingestion via `--infection-report`.
- Opt-in Infection execution with `--infection-run`, `--infection-bin`,
  `--infection-config`, and `--infection-test-framework-options`.
- Mutation baseline comparison via `--mutation-baseline` and budget
  enforcement via `--mutation-budget`.

#### Performance Instrumentation

- `--print-runtime` emits wall/peak-memory/phase telemetry on stderr as JSON.
- `--runtime-mode=summary|detailed` selects payload granularity; `detailed`
  adds per-rule totals.
- `composer perf` (`scripts/test-performance.sh`) compares wall time and peak
  memory against a local baseline across three corpora with configurable
  tolerances.

#### Exit Codes

- `0`: the run completed and no finding met the `--fail-on` threshold.
- `1`: at least one finding met the `--fail-on` threshold.
- `2`: a run diagnostic occurred (config failure, missing path, parse error,
  baseline error, history-file error, diff failure, or mutation-tool failure).

### Rule Catalogue

The v0.1 catalogue includes **114** registry rules across 11 pillars:

| Pillar | Rules |
| --- | ---: |
| `size` | 7 |
| `complexity` | 5 |
| `maintainability` | 2 |
| `dead-code` | 9 |
| `naming` | 12 |
| `documentation` | 14 |
| `modernisation` | 10 |
| `security` | 11 |
| `sensitive-data` | 9 |
| `test-quality` | 34 |
| `design` | 1 |

Representative rule families:

- **Size** — file, class, method, parameter, property, public-method, and
  average-method length.
- **Complexity** — cyclomatic, cognitive, nesting depth, NPath, and Halstead
  volume.
- **Maintainability** — maintainability index and one-line method wrappers.
- **Dead code** — unused private members, unused imports, unused parameters,
  unreachable code, empty classes/methods, commented-out code, and redundant
  variables.
- **Naming** — short variables, abbreviation allowlist, boolean prefixes,
  negative booleans, generic methods, prefix/suffix Hungarian notation,
  class/file mismatch, test naming consistency, identifier quality, confusing
  names, and parameter/type-name alignment.
- **Documentation** — missing PHPDoc for files/classes/methods/properties/
  constants, missing/stale `@param`/`@return`/`@throws` tags, useless PHPDoc,
  missing README files, TODO density, and `@var` assertion descriptions.
- **Modernisation** — constructor promotion, readonly properties, enum
  candidates, match expressions, first-class callables, named arguments,
  `mixed` overuse, PHPDoc `mixed` overuse, public mutable properties, and
  direct global access.
- **Security** — dangerous calls, unsafe `unserialize`, weak crypto, variable
  includes, SQL concatenation, header injection, error suppression, silent
  catches, request-data `extract`/`compact`, insecure randomness, and disabled
  SSL verification.
- **Sensitive data** — AWS access keys, private-key headers, API-key patterns,
  JWTs, database URLs with passwords, hardcoded env-style secrets,
  high-entropy strings, PHI patterns, and realistic PII in test fixtures.
- **Test quality** — assertions presence, trivial assertions, conditional and
  looped tests, long tests, eager tests, mystery guests, mocking smells,
  sleeps, snapshots, SUT calls, setup bloat, skipped tests, PHPUnit config
  flags, repeated test structures, and testdox readability.
- **Design** — single-implementor internal interfaces.

Some dead-code pillar rules keep the `waste.*` rule-id prefix for historical
continuity. Use the `pillar` field from `list-rules --format json` when
filtering by pillar.

### Changed

- License changed from `proprietary` to MIT; `composer.json` and the new
  `LICENSE` file reflect this.
- Default config filename is `.gruff-php.yaml`; `.gruff.yaml` remains accepted
  as a legacy fallback when the preferred name is absent.
- Sensitive-data taxonomy uses `sensitive-data.*` rule ids and the
  `SensitiveData` implementation namespace; the older `secrets.*` naming is
  not part of the v0.1 public surface.
- Config is YAML-only; JSON config loading was removed before public release.
- Control-flow comment rules were removed from the v0.1 catalogue after
  dogfooding showed poor signal-to-noise.
- Rubric defaults (size, complexity, maintainability, `docs.todo-density`)
  now ship as a single `threshold` plus `severity` rather than as
  `warning` / `error` tiered pairs. Defaults are anchored to PHPMD, Sonar PHP,
  and PhpMetrics published thresholds: size file/class/method/average-method/
  parameter/property/public-method at 1000/1000/100/50/10/15/25, complexity
  cyclomatic/cognitive/npath/halstead-volume/maintainability-index/
  nesting-depth at 20/30/200/8000/35/5, and `docs.todo-density` at 10.
  Projects that previously used `thresholds: {warning, error}` overrides on
  these rules must switch to `threshold` + `severity`.
- Naming rules now share isolated function/method/closure/arrow scope walking
  where parameter or local-variable checks need closure coverage.
- Overlapping naming findings on the same identifier keep the more specific
  rule according to the documented naming deferral order.
- The project dogfood baseline now runs with zero error and zero warning
  findings under the default `php bin/gruff-php analyse` command.

### Fixed

- Attribute-decorated PHPDoc declarations no longer trigger local `@var`
  assertion findings.
- Nullable union parameter types such as `Foo|null` and `null|Foo` are
  handled consistently by parameter/type-name checks.
- PHPDoc `mixed` detection no longer counts descriptive prose after a
  concrete tag type.
- Test discovery no longer treats prose such as `@test annotation` as an
  actual PHPUnit test marker.
- Lockfile scanning skips common dependency lockfiles by default to avoid
  high-entropy noise from integrity hashes.
- Project-level PHPUnit config rules require test-file scope before emitting.
- Dashboard and branch-review flows were hardened around argument handling,
  host handling, request size, baseline writes, and Git ref validation.
- HTML report rendering avoids Symfony Console tag parsing overhead and is
  much faster for large reports.

### Documentation

- `README.md` documents the full v0.1 CLI surface, output formats, exit
  codes, config schema, baselines, diff/branch review, display filters,
  mutation analysis, dashboard, and the public release checklist.
- `docs/gruff-cli-summary.md` documents the `summary` command and its
  `gruff.summary.v1` schema.
- `docs/gruff-cli-agent-instructions.md` documents the CLI surface for
  coding agents wrapping gruff.
- `docs/gruff-cli-branch-review.md` documents the `--diff-vs` / `--changed-only`
  branch-review workflow.
- `docs/naming-conventions.md` documents shared cross-implementation naming
  conventions.
- `CONTRIBUTING.md`, `SECURITY.md`, and `SUPPORT.md` describe contribution
  workflow, vulnerability reporting, and user-support scope.

### Known Limits

- Schemas (`gruff.analysis.v1`, `gruff.summary.v1`, `gruff.baseline.v1`) are
  versioned, but v0.1 should still be treated as an early public contract.
- The rules are heuristic. Review findings before making security or
  compliance claims.
- Inline suppression comments are not part of v0.1; use baselines, config
  selection, and display filters instead.
- The local dashboard is intended for development; do not expose it on an
  untrusted network.
- `--diff=<base>` is a changed-line/file filter, not a full base/current
  subtraction engine. Use `--diff-vs` for introduced/removed comparisons.
- Project-level rules (such as `design.single-implementor-interface`) need
  full-project context; a clean count under `--changed-only` is not proof of
  cleanliness for those rules.

## Pre-0.1 Development History

Detailed internal notes — dogfood calibration, rule-design decisions, and
release-prep evidence used to shape the public v0.1 surface — live under
`.goat-flow/`.

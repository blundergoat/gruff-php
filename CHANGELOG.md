# Changelog

All notable changes to `gruff-php` are documented here.

This project follows the spirit of [Keep a Changelog](https://keepachangelog.com/)
and uses semantic versioning once public tags begin. The current binary reports
`0.1.0-dev`; the release notes below are the public `0.1.0` preparation notes.

## 0.1.0 - Unreleased

### Added

- Composer package scaffold for `devgoat/gruff-php` with the `bin/gruff-php` CLI.
- Symfony Console command surface:
  - `analyse`
  - `summary`
  - `report`
  - `dashboard`
  - `list-rules`
- YAML project configuration via `.gruff.yaml`, `--config`, and `--no-config`.
- Rule selection by tier, pillar, and rule id.
- Per-rule enablement, threshold overrides, and rule options.
- Source discovery for PHP and text/config-like files with default ignored paths.
- PHP parser pipeline with AST, token, parent-node, and parse-diagnostic support.
- Stable finding schema with severity, pillar, tier, confidence, remediation,
  metadata, and fingerprint fields.
- Text, JSON, HTML, Markdown, GitHub annotation, hotspot JSON, and SARIF report
  outputs.
- Compact `summary` command with text and JSON output.
- Static `report` command for HTML and JSON reports.
- Local dashboard command with scan refresh, project-root selection, config,
  baseline, diff mode, and include-ignored controls.
- Baseline generation and application with `gruff.baseline.v1`.
- Git diff filtering and branch-review comparison via `--diff`,
  `--diff-vs`, and `--changed-only`.
- Display filters for minimum severity, included/excluded pillars, included/
  excluded rules, and path normalization.
- Optional Infection mutation report ingestion and opt-in Infection execution.
- Score calculation with composite and per-pillar grades.
- Trend-history recording with `--history-file`.
- Rule metadata discovery through `list-rules` table and JSON output.
- CI workflow for PHP 8.3 and PHP 8.4.

### Rule Catalogue

The v0.1 catalogue includes 113 registry rules across these pillars:

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

Representative rule families:

- Size metrics for file, class, method, parameter, property, public-method, and
  average-method length.
- Complexity metrics for cyclomatic, cognitive, nesting depth, NPath, Halstead
  volume, and maintainability index.
- Dead-code checks for unused private members, unused imports,
  unused parameters, unreachable code, empty declarations, commented-out code,
  and one-line method wrappers.
- Naming checks for short variables, declared abbreviation vocabulary, boolean
  prefixes, negative boolean flags, generic methods, prefix/suffix Hungarian
  notation, class/file mismatch, test naming consistency, identifier quality,
  closure/arrow scopes, and parameter/type-name alignment.
- Documentation checks for missing PHPDoc, missing/stale PHPDoc tags, useless
  PHPDoc, missing README files, TODO density, and `@var` assertion descriptions.
- Modernisation suggestions for constructor promotion, readonly properties,
  enum candidates, match expressions, first-class callables, named arguments,
  mixed overuse, public mutable properties, and direct global access.
- Security heuristics for dangerous calls, unsafe unserialize, weak crypto,
  variable includes, SQL concatenation, header injection, error suppression,
  silent catches, request-data extract/compact, insecure randomness, and
  disabled SSL verification.
- Sensitive-data scanning for AWS keys, private key headers, API keys, JWTs,
  database URLs with passwords, hardcoded env-style secrets, high-entropy
  strings, PHI patterns, and realistic PII in test fixtures.
- Test-quality checks for assertions, trivial assertions, conditional/looped
  tests, long tests, eager tests, mystery guests, mocking smells, sleeps,
  snapshots, SUT calls, setup bloat, skipped tests, PHPUnit config flags, and
  repeated test structures.
- Design check for single-implementor internal interfaces.

### Changed

- Sensitive-data taxonomy uses `sensitive-data.*` rule ids and the
  `SensitiveData` implementation namespace. Older `secrets.*` naming is not
  part of the v0.1 public surface.
- Config is YAML-only. JSON config loading was removed before public release.
- Control-flow comment rules were removed from the v0.1 catalogue after
  dogfooding showed poor signal-to-noise.
- The project dogfood baseline now runs with zero error and zero warning
  findings under the default `php bin/gruff-php analyse` command.
- Naming rules now share isolated function/method/closure/arrow scope walking
  where parameter or local-variable checks need closure coverage.
- Overlapping naming findings on the same identifier now keep the more specific
  rule according to the documented naming deferral order.

### Fixed

- Attribute-decorated PHPDoc declarations no longer trigger local `@var`
  assertion findings.
- Nullable union parameter types such as `Foo|null` and `null|Foo` are handled
  consistently by parameter/type-name checks.
- PHPDoc `mixed` detection no longer counts descriptive prose after a concrete
  tag type.
- Test discovery no longer treats prose such as `@test annotation` as an actual
  PHPUnit test marker.
- Lockfile scanning skips common dependency lockfiles by default to avoid
  high-entropy noise from integrity hashes.
- Project-level PHPUnit config rules require test-file scope before emitting.
- Dashboard and branch-review flows were hardened around argument handling,
  host handling, request size, baseline writes, and Git ref validation.
- HTML report rendering avoids Symfony Console tag parsing overhead and is much
  faster for large reports.

### Known Limits

- Public release metadata is not complete until a license decision is made.
- The binary still reports `0.1.0-dev` until the release version is finalized in
  source.
- Schemas are versioned, but v0.1 should still be treated as an early public
  contract.
- The rules are heuristic. Review findings before making security or compliance
  claims.
- Inline suppression comments are not part of v0.1; use baselines, config
  selection, and display filters instead.

## Pre-0.1 Development History

Detailed internal milestone notes live under `.goat-flow/`. They include the
dogfood calibration, rule-design decisions, and release-prep evidence used to
shape the public v0.1 surface.

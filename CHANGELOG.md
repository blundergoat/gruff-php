# Changelog

All notable changes to `gruff-php` are documented here. Follows
[Keep a Changelog](https://keepachangelog.com/) and semantic versioning.
Development builds report a `-dev` suffix until `scripts/bump-version.sh`
stamps the tag.

## 0.1.1 - 2026-05-24

First public release.

- 120 rules across 11 pillars (size, complexity, maintainability, dead-code,
  naming, documentation, modernisation, security, sensitive-data, test-quality,
  design). Run `php bin/gruff-php list-rules` to inspect.
- Commands: `analyse`, `summary`, `report`, `dashboard`, `list-rules`, `init`.
- `init` scaffolds a `.gruff-php.yaml` from registry defaults plus a curated
  `paths.ignore` list (agent harness dirs, generated reports, fixtures,
  vendored copies). `--force` regeneration preserves any existing
  `paths.ignore`; `init` refuses to silently shadow a legacy `.gruff.yaml`
  without `--force`. `--project-root <dir>` writes into a directory other
  than the current shell.
- Interactive missing-config prompt: when `analyse`, `summary`, `report`, or
  `dashboard` runs in a TTY against a project without `.gruff-php.yaml` or
  `.gruff.yaml`, the command offers to run `init`. The prompt fires only
  after option validation so malformed invocations no longer leave a stray
  config file behind, and prompt chatter routes to STDERR so JSON, SARIF,
  and HTML payloads on STDOUT stay parseable.
- Test-quality rules enabled by default: `test-quality.multiple-aaa-cycles`
  (minCycles 3), `test-quality.mocking-domain-object`, and
  `test-quality.testdox-readability` (minWords 2).
- Output formats: `text`, `json`, `html`, `markdown`, `github`, `hotspot`,
  `sarif`. Stable schemas: `gruff.analysis.v1`, `gruff.summary.v1`,
  `gruff.baseline.v1`.
- YAML config (`.gruff-php.yaml`), baselines, branch-review (`--diff`,
  `--diff-vs`, `--changed-only`), opt-in Infection mutation analysis, and a
  local dashboard.
- `composer audit:dependencies` runs inside `composer check` and the CI
  verify job, failing the build on known security advisories in the
  lockfile. `scripts/dependency-install.sh` and `dependency-update.sh`
  wrap the Composer commands used during installs and refreshes.
- Documentation added for CI integration, configuration reference, output
  formats, dashboard usage, naming conventions, and the release process
  (`docs/ci-integration.md`, `docs/configuration.md`, `docs/output-formats.md`,
  `docs/dashboard.md`, `docs/naming-conventions.md`, `docs/releasing.md`).
- PHP `^8.3`, MIT licensed.

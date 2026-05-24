# Changelog

All notable changes to `gruff-php` are documented here. Follows
[Keep a Changelog](https://keepachangelog.com/) and semantic versioning.
Development builds report a `-dev` suffix until `scripts/bump-version.sh`
stamps the tag.

## 0.1.1 - 2026-05-24

Onboarding-focused follow-up to 0.1.0.

- `init` command scaffolds a `.gruff-php.yaml` from registry defaults plus a
  curated `paths.ignore` list (agent harness dirs, generated reports,
  fixtures, vendored copies). `--force` regeneration preserves any existing
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
- `summary` text output now includes baseline guidance, pointing users at
  `analyse --generate-baseline` to record current findings as known debt
  and `--no-baseline` to audit without one.
- `composer audit:dependencies` runs inside `composer check` and the CI
  verify job, failing the build on known security advisories in the
  lockfile. `scripts/dependency-install.sh` and `dependency-update.sh`
  wrap the Composer commands used during installs and refreshes; the
  release preflight script is stricter.
- README rewritten. New documentation under `docs/`: rule catalogue
  (`docs/rules.md`), CI integration (`docs/ci-integration.md`),
  configuration reference (`docs/configuration.md`), output formats
  (`docs/output-formats.md`), dashboard usage (`docs/dashboard.md`),
  naming conventions (`docs/naming-conventions.md`), and the release
  process (`docs/releasing.md`).

## 0.1.0 - 2026-05-23

First public release.

- 120 rules across 11 pillars (size, complexity, maintainability, dead-code,
  naming, documentation, modernisation, security, sensitive-data,
  test-quality, design). Run `php bin/gruff-php list-rules` to inspect.
- Commands: `analyse`, `summary`, `report`, `dashboard`, `list-rules`.
- Output formats: `text`, `json`, `html`, `markdown`, `github`, `hotspot`,
  `sarif`. Stable schemas: `gruff.analysis.v1`, `gruff.summary.v1`,
  `gruff.baseline.v1`.
- YAML config (`.gruff-php.yaml`) with strict unknown-key rejection,
  baselines, branch-review (`--diff`, `--diff-vs`, `--changed-only`),
  opt-in Infection mutation analysis, and a local dashboard.
- PHP `^8.3`, MIT licensed.

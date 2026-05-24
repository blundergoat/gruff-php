# Changelog

All notable changes to `gruff-php` are documented here. Follows
[Keep a Changelog](https://keepachangelog.com/) and semantic versioning.
Development builds report a `-dev` suffix until `scripts/bump-version.sh`
stamps the tag.

## 0.1.3 - 2026-05-24

Patch release for the installed Composer binary bootstrap.

- Fixed `vendor/bin/gruff-php` in consuming projects by preferring Composer's
  generated `_composer_autoload_path` before source-checkout fallbacks. This
  unblocks `composer require --dev blundergoat/gruff-php` followed by
  `vendor/bin/gruff-php init`.
- Added a regression test that installs `gruff-php` into a throwaway consumer
  project and runs `vendor/bin/gruff-php init`, so the packaged dependency
  layout is covered separately from `php bin/gruff-php` source-checkout runs.

## 0.1.2 - 2026-05-24

Harness and documentation maintenance for goat-flow 1.7.0.

- Updated Codex and Claude instruction files to use the packaged
  `@blundergoat/goat-flow` audit CLI and to list the real app/quality
  surface, including `.gruff-php.yaml`, `phpstan.neon.dist`, scripts,
  `package-lock.json`, and GitHub workflows.
- Converted legacy Claude `goat-security` reference files into v1.7.0
  redirect stubs pointing at the consolidated `identity-and-data.md` and
  `supply-chain-and-cicd.md` references, preventing stale guidance from
  being loaded accidentally.
- Refreshed goat-flow architecture and code-map docs for the current CLI
  surface: the `init` command, dedicated Console test files, `skill-playbooks`
  routing, and resolved CLI footguns around config creation.
- Updated dangerous-command hook self-tests to use the real
  `healthkit/healthkit` repository identifier instead of placeholder
  `example-org/example-repo` values.
- Broadened the `symfony/yaml` runtime constraint to match the other Symfony
  components: `^6.4 || ^7.0 || ^8.0`.

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

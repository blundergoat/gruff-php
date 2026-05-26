# Changelog

All notable changes to `gruff-php` are documented here. Follows
[Keep a Changelog](https://keepachangelog.com/) and semantic versioning.
Development builds report a `-dev` suffix until `scripts/bump-version.sh`
stamps the tag.

## [Unreleased]

Introduce the per-command `minimumSeverity:` config dimension cross-port-aligned with gruff-go 0.1.2 (ADR-010), formalise the `schemaVersion:` field that pre-M11 configs lacked, and lower the `analyse` binary `--fail-on` default to match the cross-port philosophy. See [ADR-015](.goat-flow/decisions/ADR-015-per-command-minimum-severity.md).

- **Breaking:** `.gruff-php.yaml` now requires a top-level `schemaVersion: gruff-php.config.v0.1` field. Configs missing the key fail to load with a migration hint pointing at `gruff-php init --force`. Pre-public-adoption schema window per ADR-015; the hard-error path is the intentional UX.
- **Breaking:** the `analyse` command's binary `--fail-on` default lowered from `error` to `advisory`, matching the cross-port "show everything, fail on anything for gating commands" philosophy. Projects relying on the prior default see exit code 1 from warning- and advisory-tier findings that previously exited 0. Pass `--fail-on error` to restore the old behaviour, or set `minimumSeverity.analyse: error` in `.gruff-php.yaml`.
- **Added:** `minimumSeverity:` per-command exit-code threshold block in `.gruff-php.yaml`. Keys: `analyse | report | dashboard`. Values: `advisory | warning | error | none`. The validator rejects every non-gating command key (including `summary`, `init`, `list-rules`) with a useful error naming the valid keys, and rejects every non-canonical value (including the gruff-go alias `never`) with the four accepted values listed. Precedence: explicit CLI `--fail-on` flag > `minimumSeverity.<cmd>` YAML > binary default. Aligned with gruff-go 0.1.2's `minimumSeverity` shape; both ports converged on `none` as the off-switch value. Intentional cross-port divergence: gruff-php validates 3 gating commands; gruff-go validates 4 (PHP's `summary` does not gate exit code).
- **Added:** `AnalysisConfig::failThresholdFor(string $command): ?FailThreshold` exposes the per-command override to CLI consumers, returning null when the command has no entry. `AnalyseCommandSetupBuilder`, `ReportCommand`, and `DashboardStateFactory` consult this accessor before falling back to their respective binary defaults (`advisory`, `none`, `none`).
- **Added:** `ConfigLoader::SCHEMA_VERSION` and `ConfigLoader::GATING_COMMANDS` constants single-source the canonical schema-version literal and the valid `minimumSeverity:` keys for the validator, init scaffold, and docs.
- **Tests:** `tests/Reporting/FailThresholdTest.php` locks the `FailThreshold::fromInput` parser contract (acceptance of the four canonical values, rejection of every banned alias) plus the full `isTriggeredBy` matrix. `tests/Console/AnalyseMinimumSeverityPrecedenceTest.php` covers the precedence chain end-to-end (config-supplied error threshold suppresses warning-tier exit; CLI `--fail-on warning` overrides; binary default fails on advisory findings).

## 0.1.4 - 2026-05-25

Retire the `naming.parameter-type-name` rule, refresh reporter pillar
summaries, and bump the `summary` command schema to v2.

- **Breaking:** retired `naming.parameter-type-name`. The rule class,
  fixture, registry slot, priority-chain position in
  `RuleRegistry::NAMING_RULE_PRIORITY`, and `docs/rules.md` entry are
  deleted, and the project's own `.gruff-php.yaml` no longer ships a
  per-rule tuning block for it. Adopters relying on the rule for
  domain-DTO naming discipline will see those findings disappear after
  the next `composer update`. Rationale and reversibility plan recorded
  in `.goat-flow/decisions/ADR-014-retire-naming-parameter-type-name.md`;
  the cross-port sibling in `gruff-py` is being retired in lockstep
  (ADR-018 there). PHP naming-rule count drops from 12 to 11.
- **Breaking:** `summary` and `analyse` JSON output schemas bumped to
  `gruff.summary.v2` and `gruff.analysis.v2` respectively. Per-severity
  pillar and file counts now use singular property names
  (`advisory` / `warning` / `error`) instead of plural
  (`advisories` / `warnings` / `errors`). Consumers of v1 JSON output
  need to update their parsers.
- HTML and Markdown reporters render pillar summaries as a table with
  per-severity finding counts; `MarkdownReporterTest` covers the new
  output.
- `init` now scaffolds default accepted abbreviations for the
  `naming.abbreviation-allowlist` rule so new projects start with the
  registry-curated allowlist rather than an empty one.
- Documented the scaffold-then-manual-rules YAML emission pattern used
  by `init` in `.goat-flow/patterns/commands.md`, generalised so every
  rule's emitted block carries its registry description as a leading
  comment.
- Updated `.github/git-commit-instructions.md` example commit messages
  to reference `AbbreviationAllowlistRule` and `ShortVariableRule`
  instead of the retired rule.

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

---
category: schemas
last_reviewed: 2026-05-30
---

# Schema Versioning Footguns

## Footgun: Shared serializers fan out into multiple versioned schemas

**Status:** active | **Created:** 2026-05-25 | **Evidence:** OBSERVED

`src/Scoring/FileScore.php` (search: `public function toArray`) and `src/Scoring/PillarScore.php` (search: `public function toArray`) are embedded by **two** versioned payloads: `src/Analysis/AnalysisReport.php` (search: `$report['score'] = $this->score->toArray()`) under `AnalysisReport::SCHEMA_VERSION` (search: `'gruff.analysis.v`), and `src/Command/SummaryCommand.php` (search: `SCHEMA_VERSION = 'gruff.summary.v`) under its own constant. Renaming any key in either shared serializer breaks **both** schemas, but the version constants live in separate files and don't co-vary automatically. In PR #6 the user renamed `advisories/warnings/errors` → `advisory/warning/error` on those serializers and bumped `SummaryCommand::SCHEMA_VERSION` to `gruff.summary.v2`, but `AnalysisReport::SCHEMA_VERSION` stayed at `gruff.analysis.v1` — the analysis JSON now advertises a contract its payload no longer matches. Codex P1 and CodeRabbit Major both flagged it on the analysis golden fixture `tests/Fixtures/Cli/Golden/json-warning.json`, which advertised the stale `gruff.analysis.v1` literal until it was reconciled to `gruff.analysis.v2`.

**Prevention:** Before renaming any key in a class whose `toArray()` is embedded by report payloads, grep the codebase for `->toArray()` calls on the class and identify every consumer with its own `SCHEMA_VERSION` constant. Bump **every** consumer that embeds the renamed shape, not just the one whose payload prompted the rename. `src/Reporting/SarifReporter.php` (search: `gruffSchemaVersion`) already references `AnalysisReport::SCHEMA_VERSION` directly so SARIF auto-follows; the rule is "follow constant references", not "assume everything chains".

## Footgun: Schema-version strings are stamped in many human-written places

**Status:** active | **Created:** 2026-05-25 | **Evidence:** OBSERVED

A `SCHEMA_VERSION` constant in PHP is just one of N stamps of the version string. The rest live in prose, compatibility tables, JSON examples in Markdown, and code-map descriptions — none of which the compiler can update when the constant moves. PR #6's `gruff.summary.v1` → `v2` bump in `src/Command/SummaryCommand.php` (search: `SCHEMA_VERSION = 'gruff.summary.v`) left four stale references behind: `docs/gruff-cli-summary.md` (search: `gruff.summary.v1`, three occurrences including a literal `schemaVersion` line in a JSON example) and `.goat-flow/architecture.md`, whose `gruff.summary.v1 digest` mention has since been reconciled to `v2`. No reviewer flagged this; it surfaced only on a manual sweep. **Recurrence (2026-05-30):** the `.goat-flow/architecture.md` `gruff.summary.v1 digest` reference named above was still stale five days after being documented here — a "full re-audit" that bumped the doc's `Last reviewed` date to 2026-05-30 missed it, because it grep-checked the `gruff.analysis.v*` stamps but not the `gruff.summary.v*` one. It was caught only on a second manual schema-literal sweep and fixed to `v2` (the `docs/gruff-cli-summary.md` occurrences were resolved separately before then). The trap is sticky precisely because the doc reads fine in isolation.

**Prevention:** Whenever you bump a `SCHEMA_VERSION` constant, grep the repo for the OLD version literal before claiming the bump complete. Concrete current map of `gruff.analysis.v*` stamps that must move together:

```text
README.md                                       prose: "Analysis schema", "Full gruff.analysis.v...", "compatibility-sensitive"
.goat-flow/architecture.md                      three occurrences of gruff.analysis.v
.goat-flow/glossary.md                          "Native JSON uses..."
.goat-flow/code-map.md                          "schema-versioned"
docs/output-formats.md                          "JSON reports use..."
tests/Fixtures/Cli/Golden/json-warning.json     "schemaVersion" literal
tests/Reporting/SarifReporterTest.php           4 assertion hits
tests/Console/AnalyseCliTest.php                assertion hits
tests/Console/ReportCliTest.php                 assertion hits
tests/Trend/TrendRecorderTest.php               3 hits (two are intentional v1 fixture data and may stay)
```

Leave `CHANGELOG.md` historical entries and `history.json` alone — those are append-only record.

Re-audits count too: bumping a doc's `Last reviewed` date asserts you reconciled its claims, so before stamping it, enumerate **every** `gruff.*.v*` literal in the doc (analysis, summary, baseline, config) and check each against its source `SCHEMA_VERSION` constant — do not spot-check from memory, and read this footgun first since the stale stamps are listed here by file. The 2026-05-30 recurrence happened because the re-audit checked the schema family it remembered (`analysis`) and not the one it didn't (`summary`).

## Footgun: The `gruff-php.config.v0.1` literal lives in two source-of-truth places plus user-facing surfaces

**Status:** active | **Created:** 2026-05-27 | **Evidence:** OBSERVED

ADR-015 introduced `schemaVersion: gruff-php.config.v0.1` as a required top-level key in `.gruff-php.yaml`. The canonical literal lives in `src/Config/ConfigLoader.php` (search: `SCHEMA_VERSION = 'gruff-php.config.v0.1'`) and is referenced everywhere else: `src/Command/InitCommand.php` (search: `ConfigLoader::SCHEMA_VERSION` — used both in the scaffold and in `existingSchemaVersion`); the validator + migration-hint error message in `ConfigLoader::assertSchemaVersion`; the project's own `.gruff-php.yaml`; tests under `tests/Fixtures/Config/minimum-severity-*.yaml` plus inline strings in `tests/Command/DashboardScanRunnerTest.php` and `tests/Command/DashboardStateFactoryTest.php`; user-facing docs `docs/configuration.md`, `docs/ci-integration.md`, `docs/dashboard.md`; the migration hint repeated in `CHANGELOG.md`; and ADR-015 itself. A future bump from `v0.1` to `v0.2` that changes only the PHP constant leaves every other surface advertising the wrong literal.

`minimumSeverity:` adds a second lockstep surface: the binary defaults emitted by `InitCommand::DEFAULT_MINIMUM_SEVERITY` (search: `DEFAULT_MINIMUM_SEVERITY`) must match the `AnalyseCommand` binary default (`FailThreshold::Advisory`) and the precedence-fallback values in `DashboardStateFactory::resolveDashboardFailOn` and `ReportCommand::resolveFailOn`. The validator's gating-commands allowlist (`ConfigLoader::GATING_COMMANDS`) is the single source of truth that init's preservation helper, the loader, and the docs all reference.

**Prevention:** When bumping `ConfigLoader::SCHEMA_VERSION` or extending the `minimumSeverity:` shape, run these greps before declaring the change complete:

- `rg -n 'gruff-php\.config\.v' src/ tests/ docs/ .goat-flow/ CHANGELOG.md .gruff-php.yaml` — every hit must move in lockstep or be intentionally pinned (ADR history, this footgun).
- `rg -n 'minimumSeverity' src/ tests/ docs/ .goat-flow/ CHANGELOG.md .gruff-php.yaml` — every documentation surface listing the gating commands must agree.
- `rg -n 'GATING_COMMANDS|gatingCommand' src/ tests/` — every validator and helper using the allowlist must reference the constant, not a copy.

Adding a new gating command means adding it to `ConfigLoader::GATING_COMMANDS`, the docs lists, the `InitCommand::DEFAULT_MINIMUM_SEVERITY` map, and the precedence chain in the new command's wiring. Adding a new threshold value means extending `FailThreshold` first; the validator and init preservation iterate `FailThreshold::cases()` natively.

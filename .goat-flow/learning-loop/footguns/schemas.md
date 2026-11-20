---
category: schemas
last_reviewed: 2026-10-03
---

# Schema Versioning Footguns

## Footgun: Shared serializers fan out into multiple versioned schemas

**Status:** active | **Created:** 2026-05-25 | **Evidence:** OBSERVED

`src/Results/Scoring/FileScore.php` (search: `public function toArray`) and `src/Results/Scoring/PillarScore.php` (search: `public function toArray`) feed both analysis and summary output through `src/Engine/Analysis/AnalysisReport.php` (search: `private function machineScore`). Its `toSummaryArray` derives the summary from the analysis payload. Both schema constants now live in that class (`SCHEMA_VERSION` and `SUMMARY_SCHEMA_VERSION`); `src/Cli/Command/SummaryCommand.php` (search: `AnalysisReport::SUMMARY_SCHEMA_VERSION`) forwards the summary constant. A shared serializer change still needs review against both contracts.

In PR #6, when those constants lived in separate files, renaming `advisories/warnings/errors` to `advisory/warning/error` changed both payloads. The summary version moved to v2 while analysis still advertised v1. Codex P1 and CodeRabbit Major flagged the mismatch in `tests/Fixtures/Cli/Golden/json-warning.json`, and the analysis version was reconciled to v2.

**Prevention:** Before renaming any key in a class whose `toArray()` is embedded by report payloads, grep the codebase for `->toArray()` calls on the class and identify every consumer with its own `SCHEMA_VERSION` constant. Bump **every** consumer that embeds the renamed shape, not just the one whose payload prompted the rename. `src/Output/Reporter/SarifReporter.php` (search: `gruffSchemaVersion`) already references `AnalysisReport::SCHEMA_VERSION` directly so SARIF auto-follows; the rule is "follow constant references", not "assume everything chains".

## Footgun: Schema-version strings are stamped in many human-written places

**Status:** active | **Created:** 2026-05-25 | **Evidence:** OBSERVED

A `SCHEMA_VERSION` constant in PHP is just one of N stamps of the version string. The rest live in prose, compatibility tables, JSON examples in Markdown, and code-map descriptions — none of which the compiler can update when the constant moves. PR #6's `gruff.summary.v1` → `v2` bump in `src/Cli/Command/SummaryCommand.php` (search: `AnalysisReport::SUMMARY_SCHEMA_VERSION`) left four stale references behind: three in `docs/gruff-cli-summary.md`, including a literal `schemaVersion` line in a JSON example, and one `gruff.summary.v1 digest` mention in `.goat-flow/architecture.md`. Both files were later reconciled. No reviewer flagged this; it surfaced only on a manual sweep. **Recurrence (2026-05-30):** the `.goat-flow/architecture.md` `gruff.summary.v1 digest` reference named above was still stale five days after being documented here — a "full re-audit" that bumped the doc's `Last reviewed` date to 2026-05-30 missed it, because it grep-checked the `gruff.analysis.v*` stamps but not the `gruff.summary.v*` one. It was caught only on a second manual schema-literal sweep and fixed to `v2` (the `docs/gruff-cli-summary.md` occurrences were resolved separately before then). The trap is sticky precisely because the doc reads fine in isolation. **Recurrence (2026-10-03):** the 0.6.0 move to v3 - `src/Engine/Analysis/AnalysisReport.php` (search: `SUMMARY_SCHEMA_VERSION = 'gruff.summary.v3'`) and `src/Results/Baseline/BaselineStore.php` (search: `SCHEMA_VERSION = 'gruff.baseline.v3'`) - updated `README.md` and `docs/`, but `.goat-flow/architecture.md`, `.goat-flow/glossary.md`, and `.goat-flow/code-map.md` kept describing v2 reports and v2 grouped-count baselines for about a month after the v3 baseline landed in `9ae069d`. A goat-flow quality assessment caught it by counting stamps per file, and the three docs were reconciled the same day. Sweeps of the user-facing docs had skipped the agent-orientation docs.

**Prevention:** Whenever you bump a `SCHEMA_VERSION` constant, grep the repo for the OLD version literal before claiming the bump complete, and include the agent-orientation docs under `.goat-flow/`:

```bash
grep -rn --include='*.md' --include='*.php' --include='*.json' -E 'gruff\.(analysis|summary|baseline)\.v[0-9]' \
    README.md docs .goat-flow/architecture.md .goat-flow/glossary.md .goat-flow/code-map.md src tests
```

Two kinds of old literal are intentional: text describing fail-closed handling of older files, such as `gruff.baseline.v2` beside `--migrate-baseline`, and the seeded history entries in `tests/Trend/TrendRecorderTest.php` (search: `testRecordAppendsEntryAndCalculatesPreviousScoreDelta`), which stand for `history.json` lines written by earlier releases. Every other hit must name the current constant. Leave `CHANGELOG.md` historical entries and `history.json` alone — those are append-only record.

Re-audits count too: bumping a doc's `Last reviewed` date asserts you reconciled its claims, so before stamping it, enumerate **every** `gruff.*.v*` literal in the doc (analysis, summary, baseline, config) and check each against its source `SCHEMA_VERSION` constant — do not spot-check from memory, and run the sweep above first. The 2026-05-30 recurrence happened because the re-audit checked the schema family it remembered (`analysis`) and not the one it didn't (`summary`).

## Footgun: The `gruff-php.config.v0.1` literal lives in two source-of-truth places plus user-facing surfaces

**Status:** active | **Created:** 2026-05-27 | **Evidence:** OBSERVED

ADR-015 introduced `schemaVersion: gruff-php.config.v0.1` as a required top-level key in `.gruff-php.yaml`. The canonical literal lives in `src/Engine/Config/ConfigLoader.php` (search: `SCHEMA_VERSION = 'gruff-php.config.v0.1'`) and is referenced everywhere else: `src/Cli/Command/InitCommand.php` (search: `ConfigLoader::SCHEMA_VERSION` — used both in the scaffold and in `existingSchemaVersion`); the validator + migration-hint error message in `ConfigLoader::assertSchemaVersion`; the project's own `.gruff-php.yaml`; tests under `tests/Fixtures/Config/minimum-severity-*.yaml` plus inline strings in `tests/Command/DashboardScanRunnerTest.php` and `tests/Command/DashboardStateFactoryTest.php`; user-facing docs `docs/configuration.md`, `docs/ci-integration.md`, `docs/dashboard.md`; the migration hint repeated in `CHANGELOG.md`; and ADR-015 itself. A future bump from `v0.1` to `v0.2` that changes only the PHP constant leaves every other surface advertising the wrong literal.

`minimumSeverity:` adds a second lockstep surface: the binary defaults emitted by `InitCommand::DEFAULT_MINIMUM_SEVERITY` (search: `DEFAULT_MINIMUM_SEVERITY`) must match the `AnalyseCommand` binary default (`FailThreshold::Advisory`) and the precedence-fallback values in `DashboardStateFactory::resolveDashboardFailOn` and `ReportCommand::resolveFailOn`. The validator's gating-commands allowlist (`ConfigLoader::GATING_COMMANDS`) is the single source of truth that init's preservation helper, the loader, and the docs all reference.

**Prevention:** When bumping `ConfigLoader::SCHEMA_VERSION` or extending the `minimumSeverity:` shape, run these greps before declaring the change complete:

- `rg -n 'gruff-php\.config\.v' src/ tests/ docs/ .goat-flow/ CHANGELOG.md .gruff-php.yaml` — every hit must move in lockstep or be intentionally pinned (ADR history, this footgun).
- `rg -n 'minimumSeverity' src/ tests/ docs/ .goat-flow/ CHANGELOG.md .gruff-php.yaml` — every documentation surface listing the gating commands must agree.
- `rg -n 'GATING_COMMANDS|gatingCommand' src/ tests/` — every validator and helper using the allowlist must reference the constant, not a copy.

Adding a new gating command means adding it to `ConfigLoader::GATING_COMMANDS`, the docs lists, the `InitCommand::DEFAULT_MINIMUM_SEVERITY` map, and the precedence chain in the new command's wiring. Adding a new threshold value means extending `FailThreshold` first; the validator and init preservation iterate `FailThreshold::cases()` natively.

## Footgun: A finding-suppression filter has exactly one correct seam, and it does not cover `summary`

**Status:** active | **Created:** 2026-08-22 | **Evidence:** ACTUAL_MEASURED
**Decision changed:** Where to install any config-driven filter that matches on a finding's reported path, and whether one installation covers every command that reports findings.
**Trigger phase:** SCOPE

The 0.6.0 `sensitiveExclusions:` section matches a finding by its project-relative display path. Two properties of this codebase decide where that comparison can legally happen, and both are invisible from the config layer.

First, the display path is not stable for the whole run. `src/Cli/Command/AnalysisPipeline.php` (search: `private function runPipeline`) hands back findings carrying the discovery display path from `src/Engine/Source/SourceDiscovery.php` (search: `private function displayPath`), which is project-relative. `src/Cli/Command/AnalyseCommand.php` then rebases them through `src/Cli/Command/AnalysisFindingSupport.php` (search: `public function normalizeFindingPaths`) whenever the caller passed `--paths-relative-to`. A filter installed after that point compares configured paths against a caller-chosen base, so the same config would suppress different findings depending on how the command was invoked. The filter therefore has to run inside `runAnalysis`, between the pipeline and the rebase, and also before `ScoreCalculator` and the exit gate so a suppressed finding leaves scoring the way accepted baseline debt does.

Second, that seam is not the only place findings are produced. Three call sites run the rules: `AnalysisPipeline` (serving `analyse` and `hook`), `src/Cli/Command/BranchReviewBuilder.php` (search: `$baseRegistry->analyse`) for the `--diff-vs` base snapshot, and `src/Cli/Command/SummaryCommand.php` (search: `private function summaryData`), which calls the registry directly and never touches the pipeline. The base-snapshot site needs the same filter for a different reason: comparing suppressed current findings against unsuppressed base findings reports an accepted credential as removed by the branch. `summary` is not covered. Measured on a four-file synthetic corpus with one exclusion configured, `analyse --format json` reported `"total": 2` with a `suppressions` row of `"suppressed": 1`, while `summary --format json` over the same tree reported `"total": 3` and carried no `suppressions` key at all. The two commands disagreed by exactly the suppressed finding. The shipped feature commit `a0d8198` replicated the filter in `src/Cli/Command/SummaryCommand.php` (search: `(new SensitiveExclusionFilter())->apply`), and `tests/Console/SensitiveExclusionsCliTest.php` (search: `testSummaryTextReportsTheSameSuppressionTotalAndFindingCountAsAnalyse`) pins the parity; that test passed on 2026-10-03. The structural trap stands for the next filter, because findings still come from three producers.

**Prevention:** Before installing any config-driven finding filter, find every producer of findings, not just the one the feature was requested against. Grep case-insensitively for `->analyse(` under `src/Cli/Command/`: a case-sensitive `registry->analyse(` misses `$baseRegistry->analyse(` in `BranchReviewBuilder` and undercounts the producers by one. Then check whether the field the filter matches on is rewritten later in the run; display paths are, and a filter placed downstream of `normalizeFindingPaths` is invocation-dependent rather than wrong-looking. Finally, ask which comparisons pair filtered output with unfiltered output - a branch review pairs two independent analyses, so a filter applied to one side only turns suppression into a phantom fix.

**Portability question this raises for the family, unresolved:** `../FAMILY-CONTRACT.md` (search: `**Audit counts.**`) asks every port for the suppression total on "every finding-bearing surface", but does not say which run stage owns suppression. gruff-rs suppresses once, inside `../gruff-rs/src/analysis.rs` (search: `fn partition_excluded_findings`), because one report-building path serves everything. php has two finding-producing paths and one machine-report shape, so it replicated the filter into `summary` rather than converging on one suppression stage. The family has to decide whether the contract means one suppression stage each port must converge on, or per-command replication with a per-command count — and whether an `analyse`/`summary` disagreement of exactly the suppressed set is conformant in the meantime. Ports whose summary-equivalent shares the analyse pipeline will not notice this question exists.

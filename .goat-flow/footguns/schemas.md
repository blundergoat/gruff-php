---
category: schemas
last_reviewed: 2026-05-25
---

# Schema Versioning Footguns

## Footgun: Shared serializers fan out into multiple versioned schemas

**Status:** active | **Created:** 2026-05-25 | **Evidence:** OBSERVED

`src/Scoring/FileScore.php` (search: `public function toArray`) and `src/Scoring/PillarScore.php` (search: `public function toArray`) are embedded by **two** versioned payloads: `src/Analysis/AnalysisReport.php` (search: `$report['score'] = $this->score->toArray()`) under `AnalysisReport::SCHEMA_VERSION` (search: `'gruff.analysis.v`), and `src/Command/SummaryCommand.php` (search: `SCHEMA_VERSION = 'gruff.summary.v`) under its own constant. Renaming any key in either shared serializer breaks **both** schemas, but the version constants live in separate files and don't co-vary automatically. In PR #6 the user renamed `advisories/warnings/errors` → `advisory/warning/error` on those serializers and bumped `SummaryCommand::SCHEMA_VERSION` to `gruff.summary.v2`, but `AnalysisReport::SCHEMA_VERSION` stayed at `gruff.analysis.v1` — the analysis JSON now advertises a contract its payload no longer matches. Codex P1 and CodeRabbit Major both flagged it on `tests/Fixtures/Cli/Golden/json-warning.json` (search: `"gruff.analysis.v1"`).

**Prevention:** Before renaming any key in a class whose `toArray()` is embedded by report payloads, grep the codebase for `->toArray()` calls on the class and identify every consumer with its own `SCHEMA_VERSION` constant. Bump **every** consumer that embeds the renamed shape, not just the one whose payload prompted the rename. `src/Reporting/SarifReporter.php` (search: `gruffSchemaVersion`) already references `AnalysisReport::SCHEMA_VERSION` directly so SARIF auto-follows; the rule is "follow constant references", not "assume everything chains".

## Footgun: Schema-version strings are stamped in many human-written places

**Status:** active | **Created:** 2026-05-25 | **Evidence:** OBSERVED

A `SCHEMA_VERSION` constant in PHP is just one of N stamps of the version string. The rest live in prose, compatibility tables, JSON examples in Markdown, and code-map descriptions — none of which the compiler can update when the constant moves. PR #6's `gruff.summary.v1` → `v2` bump in `src/Command/SummaryCommand.php` (search: `SCHEMA_VERSION = 'gruff.summary.v`) left four stale references behind: `docs/gruff-cli-summary.md` (search: `gruff.summary.v1`, three occurrences including a literal `schemaVersion` line in a JSON example) and `.goat-flow/architecture.md` (search: `gruff.summary.v1 digest`). No reviewer flagged this; it surfaced only on a manual sweep.

**Prevention:** Whenever you bump a `SCHEMA_VERSION` constant, grep the repo for the OLD version literal before claiming the bump complete. Concrete current map of `gruff.analysis.v*` stamps that must move together: prose in `README.md` (search: `Analysis schema`, `Full \`gruff.analysis.v`, `compatibility-sensitive`), three occurrences in `.goat-flow/architecture.md` (search: `gruff.analysis.v`), `.goat-flow/glossary.md` (search: `Native JSON uses`), `.goat-flow/code-map.md` (search: `schema-versioned`), `docs/output-formats.md` (search: `JSON reports use`), the golden fixture `tests/Fixtures/Cli/Golden/json-warning.json` (search: `"schemaVersion"`), plus assertion sites in `tests/Reporting/SarifReporterTest.php` (4 hits), `tests/Console/AnalyseCliTest.php`, `tests/Console/ReportCliTest.php`, and `tests/Trend/TrendRecorderTest.php` (3 hits, two of which are intentional v1 fixture data for older-history coverage and may legitimately stay). Leave `CHANGELOG.md` historical entries and `history.json` alone — those are append-only record.

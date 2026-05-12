---
category: setup
last_reviewed: 2026-05-09
---

# Setup Footguns

## Resolved Entries

## Footgun: PHP-named scaffold has no PHP app surface yet

**Status:** resolved | **Created:** 2026-05-09 | **Resolved:** 2026-05-09 | **Evidence:** ACTUAL_MEASURED

`README.md` (search: `# gruff-php`) names the project, but the repository currently has no `composer.json`, `src/`, `tests/`, or PHP runtime configuration. The name makes it easy for agents to assume Composer, PHPUnit, or PHPStan commands exist. They do not exist until real app structure is added.

**Resolution:** M01 added `composer.json` (search: `"bin": [`), `bin/gruff` (search: `new Application()`), `src/Console/Application.php` (search: `final class Application`), `src/Command/AnalyseCommand.php` (search: `final class AnalyseCommand`), and `tests/Console/ListRulesCliTest.php` (search: `testVersionCommandRunsThroughBinary`).

**Prevention:** Before listing app commands or describing runtime architecture, check for the actual files that define them. If a future scaffold has no app surface, say "no application command configured yet" instead of inventing PHP defaults.

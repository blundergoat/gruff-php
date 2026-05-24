---
category: setup
last_reviewed: 2026-05-24
---

# Setup Footguns

## Footgun: Package bin bootstraps must use Composer's consumer autoloader

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED

`bin/gruff-php` (search: `$GLOBALS['_composer_autoload_path']`) must prefer Composer's generated bin-proxy autoload path when run from an installed project's `vendor/bin/gruff-php`. A package-local bootstrap such as `__DIR__ . '/../vendor/autoload.php'` works in this checkout, but fails after `composer require --dev blundergoat/gruff-php` because installed dependencies do not carry their own nested `vendor/autoload.php`.

**Evidence:** External install reproduction in `/home/devgoat/projects/strands-php-client`: `vendor/bin/gruff-php init` from package `v0.1.2` failed opening `vendor/blundergoat/gruff-php/bin/../vendor/autoload.php`. Composer's generated proxy at `/home/devgoat/projects/strands-php-client/vendor/bin/gruff-php` sets `$GLOBALS['_composer_autoload_path']` before including the package bin.

**Prevention:** CLI package bins need a regression test that installs the package into a consumer project and executes `vendor/bin/<tool>`, not only `php bin/<tool>` inside the source checkout. Keep the source-checkout fallback for direct development, but make the Composer proxy autoload path the first candidate.

## Resolved Entries

## Footgun: PHP-named scaffold has no PHP app surface yet

**Status:** resolved | **Created:** 2026-05-09 | **Resolved:** 2026-05-09 | **Evidence:** ACTUAL_MEASURED

`README.md` (search: `# gruff-php`) names the project, but the repository currently has no `composer.json`, `src/`, `tests/`, or PHP runtime configuration. The name makes it easy for agents to assume Composer, PHPUnit, or PHPStan commands exist. They do not exist until real app structure is added.

**Resolution:** M01 added `composer.json` (search: `"bin": [`), `bin/gruff-php` (search: `new Application()`), `src/Console/Application.php` (search: `final class Application`), `src/Command/AnalyseCommand.php` (search: `final class AnalyseCommand`), and `tests/Console/ListRulesCliTest.php` (search: `testVersionCommandRunsThroughBinary`).

**Prevention:** Before listing app commands or describing runtime architecture, check for the actual files that define them. If a future scaffold has no app surface, say "no application command configured yet" instead of inventing PHP defaults.

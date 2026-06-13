---
category: setup
last_reviewed: 2026-06-07
---

# Setup Footguns

## Footgun: Package bin bootstraps must use Composer's consumer autoloader

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED

`bin/gruff-php` (search: `$GLOBALS['_composer_autoload_path']`) must prefer Composer's generated bin-proxy autoload path when run from an installed project's `vendor/bin/gruff-php`. A package-local bootstrap such as `__DIR__ . '/../vendor/autoload.php'` works in this checkout, but fails after `composer require --dev blundergoat/gruff-php` because installed dependencies do not carry their own nested `vendor/autoload.php`.

**Evidence:** External install reproduction in `/home/devgoat/projects/strands-php-client`: `vendor/bin/gruff-php init` from package `v0.1.2` failed opening `vendor/blundergoat/gruff-php/bin/../vendor/autoload.php`. Composer's generated proxy at `/home/devgoat/projects/strands-php-client/vendor/bin/gruff-php` sets `$GLOBALS['_composer_autoload_path']` before including the package bin.

**Prevention:** CLI package bins need a regression test that installs the package into a consumer project and executes `vendor/bin/<tool>`, not only `php bin/<tool>` inside the source checkout. Keep the source-checkout fallback for direct development, but make the Composer proxy autoload path the first candidate.

## Footgun: Consumer install tests can resolve newer dependency majors than the source checkout

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED

`composer.json` (search: `"symfony/console": "^6.4 || ^7.0 || ^8.0"`) allows Symfony Console 8 on PHP 8.4, while the source checkout lockfile on PHP 8.3 currently installs Symfony Console 7. `src/Cli/Application.php` previously called the 7.4-deprecated `$this->add(...)` API, which passed all local source-checkout tests but failed in PR #5's PHP 8.4 consumer-install regression after Composer resolved `symfony/console v8.0.11`: `GruffPhp\Console\Application::add()` was undefined during `vendor/bin/gruff-php init`.

**Evidence:** `src/Cli/Application.php` (search: `addCommands`) now uses the cross-version registration API; `tests/Console/ListRulesCliTest.php` (search: `testInstalledVendorBinProxyUsesConsumerAutoloader`) exercises a consumer install path, but local PHP 8.3 resolution alone does not prove the Symfony 8 path. GitHub Actions run `26359298476` on PR #5 showed the PHP 8.4 failure while PHP 8.3 passed the same PHPUnit test.

**Prevention:** When a package constraint includes a newer framework major than the local lockfile currently installs, verify the public CLI against that major before release. For Symfony Console support, prefer APIs present across every advertised major (`addCommands` across 6.4/7.x/8.x here) and avoid deprecated 7.x APIs when `^8.0` is allowed. A consumer-install test should either run in the CI PHP version that can resolve the newest major or include an explicit platform/dependency smoke so the highest supported major is actually exercised.

## Resolved Entries

## Footgun: classmap-authoritative hid newly added src/ classes in dev

**Status:** resolved | **Created:** 2026-06-07 | **Resolved:** 2026-06-07 | **Evidence:** ACTUAL_MEASURED

`composer.json` (search: `"optimize-autoloader"`) previously also set `config.classmap-authoritative: true`, which disables the PSR-4 filesystem fallback in the generated autoloader. A newly created `src/` class (e.g. a new Rule) was then invisible to `bin/gruff-php` and `RuleRegistry::defaults()` (search: `RuleRegistry`) until `composer dump-autoload` regenerated the classmap — symptom: `Class "...Rule" not found`, or a new rule silently missing from `list-rules`. The flag only ever affected this repo's own dev install (a consumer's root config governs their autoloader optimisation), so it bought nothing here.

**Resolution:** Removed `classmap-authoritative` from `composer.json` (kept `optimize-autoloader: true`). Verified the regenerated autoloader reports `isClassMapAuthoritative()` false and that a class created after a dump — absent from `vendor/composer/autoload_classmap.php` — still resolves via `class_exists()`.

**Prevention:** Do not re-add `classmap-authoritative: true` to `composer.json`; it reinstates the invisible-new-class trap. `optimize-autoloader: true` is safe — it builds the fast classmap without disabling the PSR-4 fallback.

## Footgun: PHP-named scaffold has no PHP app surface yet

**Status:** resolved | **Created:** 2026-05-09 | **Resolved:** 2026-05-09 | **Evidence:** ACTUAL_MEASURED

`README.md` (search: `# gruff-php`) names the project, but the repository currently has no `composer.json`, `src/`, `tests/`, or PHP runtime configuration. The name makes it easy for agents to assume Composer, PHPUnit, or PHPStan commands exist. They do not exist until real app structure is added.

**Resolution:** M01 added `composer.json` (search: `"bin": [`), `bin/gruff-php` (search: `new Application()`), `src/Cli/Application.php` (search: `final class Application`), `src/Cli/Command/AnalyseCommand.php` (search: `final class AnalyseCommand`), and `tests/Console/ListRulesCliTest.php` (search: `testVersionCommandRunsThroughBinary`).

**Prevention:** Before listing app commands or describing runtime architecture, check for the actual files that define them. If a future scaffold has no app surface, say "no application command configured yet" instead of inventing PHP defaults.

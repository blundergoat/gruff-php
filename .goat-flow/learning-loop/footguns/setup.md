---
category: setup
last_reviewed: 2026-10-03
---

# Setup Footguns

## Footgun: Package bin bootstraps must use Composer's consumer autoloader

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED

`bin/gruff-php` (search: `$GLOBALS['_composer_autoload_path']`) must prefer Composer's generated bin-proxy autoload path when run from an installed project's `vendor/bin/gruff-php`. A package-local bootstrap such as `__DIR__ . '/../vendor/autoload.php'` works in this checkout, but fails after `composer require --dev blundergoat/gruff-php` because installed dependencies do not carry their own nested `vendor/autoload.php`.

**Evidence:** External install reproduction in `/home/devgoat/projects/strands-php-client`: `vendor/bin/gruff-php init` from package `v0.1.2` failed opening `vendor/blundergoat/gruff-php/bin/../vendor/autoload.php`. Composer's generated proxy at `/home/devgoat/projects/strands-php-client/vendor/bin/gruff-php` sets `$GLOBALS['_composer_autoload_path']` before including the package bin. The regression test the prevention asks for now exists: `tests/Console/ListRulesCliTest.php` (search: `testInstalledVendorBinProxyUsesConsumerAutoloader`) installs the package into a temporary consumer project and runs `vendor/bin/gruff-php init`. It passed on 2026-10-03.

**Prevention:** CLI package bins need a regression test that installs the package into a consumer project and executes `vendor/bin/<tool>`, not only `php bin/<tool>` inside the source checkout. Keep the source-checkout fallback for direct development, but make the Composer proxy autoload path the first candidate.

## Footgun: Consumer install tests can resolve newer dependency majors than the source checkout

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED

`composer.json` (search: `"symfony/console": "^6.4 || ^7.0 || ^8.0"`) allows Symfony Console 8 on PHP 8.4, while the source checkout lockfile on PHP 8.3 currently installs Symfony Console 7. `src/Cli/Application.php` previously called the 7.4-deprecated `$this->add(...)` API, which passed all local source-checkout tests but failed in PR #5's PHP 8.4 consumer-install regression after Composer resolved `symfony/console v8.0.11`: `GruffPhp\Console\Application::add()` was undefined during `vendor/bin/gruff-php init`.

**Evidence:** `src/Cli/Application.php` (search: `addCommands`) now uses the cross-version registration API; `tests/Console/ListRulesCliTest.php` (search: `testInstalledVendorBinProxyUsesConsumerAutoloader`) exercises a consumer install path, but local PHP 8.3 resolution alone does not prove the Symfony 8 path. GitHub Actions run `26359298476` on PR #5 showed the PHP 8.4 failure while PHP 8.3 passed the same PHPUnit test.

**Prevention:** When a package constraint includes a newer framework major than the local lockfile currently installs, verify the public CLI against that major before release. For Symfony Console support, prefer APIs present across every advertised major (`addCommands` across 6.4/7.x/8.x here) and avoid deprecated 7.x APIs when `^8.0` is allowed. A consumer-install test should either run in the CI PHP version that can resolve the newest major or include an explicit platform/dependency smoke so the highest supported major is actually exercised.

## Footgun: `composer update --prefer-lowest` cannot reach the advertised floor when a dev dependency requires higher

**Status:** active | **Created:** 2026-08-12 | **Evidence:** ACTUAL_MEASURED
**Decision changed:** How to prove that the version range in `composer.json` actually runs the code that depends on it.
**Trigger phase:** VERIFY

`composer.json` (search: `"nikic/php-parser"`) advertises `^5.6`. Running `composer update --prefer-lowest --prefer-stable` in this checkout resolves php-parser to **v5.7.0**, not v5.6.0, because two development dependencies hold the floor above the advertised minimum: `phpunit/php-code-coverage` requires `^5.7.0` and `infection/infection` requires `^5.6.2`. Forcing `nikic/php-parser:5.6.0` is refused outright by the resolver. A consumer installing this package has neither PHPUnit nor Infection, so a consumer can resolve v5.6.0 while this checkout never can. A `--prefer-lowest` run therefore reports green against a version no consumer floor uses, and the lowest advertised version stays untested.

**Evidence:** Measured 2026-08-12 while verifying that `src/Rules/Modernisation/PublicPropertyRule.php` (search: `hasOpenWriteSide`) runs at the advertised floor. `composer why nikic/php-parser` after a `--prefer-lowest` resolution printed `phpunit/php-code-coverage 12.5.2 requires nikic/php-parser (^5.7.0)`. The real floor was proven instead by a throwaway project requiring only `php: ^8.3` and `nikic/php-parser: 5.6.0`, where `method_exists` confirmed `isReadonly`, `isPrivateSet`, and `isProtectedSet` on both `PhpParser\Node\Stmt\Property` and `PhpParser\Node\Param`.

**Prevention:** Never cite a `--prefer-lowest` run inside this checkout as evidence that an advertised floor works. Prove a runtime floor in a consumer-shaped project that requires only the `require` block, never `require-dev`. Check `composer why <package>` first: when any dev dependency's constraint is tighter than the advertised one, the local resolution is measuring the dev constraint. This is the mirror image of the newer-major trap recorded above (search: `## Footgun: Consumer install tests can resolve newer dependency majors than the source checkout`); both come from the same root cause, that the source checkout's resolution is not a consumer's resolution.

## Footgun: `goat-flow hooks sync` reverts this repo's local hook fixes; the failing drift audit is not a repair prompt

**Status:** active | **Created:** 2026-08-14 | **Evidence:** ACTUAL_MEASURED
**Decision changed:** Whether to act on `goat-flow audit`'s printed repair command when the drift scope covers `.goat-flow/hooks/`.
**Trigger phase:** ACT

Two installed hooks carry fixes that the published goat-flow 1.17.0 templates lack, so `goat-flow hooks sync`, or `goat-flow install --force-path` on either file, would delete them. `.goat-flow/hooks/gruff-code-quality.sh` (search: `reported span intersects`) counts a finding when any part of its reported span overlaps the edit in the legacy fallback path (`changed_findings_report` and `suppressed_count`). The 1.17.0 template checks spans only on the contract path (search: `attributable_line_or_span`). `.goat-flow/hooks/post-turn-safety.sh` (search: `quoted path case failed`) unescapes `\"` in quoted Git paths before the Bash 3 compatibility scanner decodes them.

Both fixes are inert in this checkout today. `php bin/gruff-php hook --capabilities --format json` advertises `gruff.hook.v2`, so the hook uses the contract path. Bash 5.2 runs the native post-turn scanner. They still change what goat-flow reports: `hooks list` marks both hooks `installed-content-diverged`, and `hooks verify --trusted-target` returns `not-configured` with reason `hook-not-installed` for the post-turn and Gruff groups.

**Evidence:** Re-measured 2026-10-03 during the 1.16.0 to 1.17.0 upgrade. `install --dry-run` classified five hook files as `both-changed` against the 1.15.1 baseline. Two earlier local fixes are now upstream: the xargs optional-argument flags in `.goat-flow/hooks/deny-dangerous/guard-runtime.sh` (search: `--show-limits|-e|-i|-l|--eof|--replace|--max-lines`) and curl `headers=@` detection in `.goat-flow/hooks/deny-dangerous/patterns-paths.sh` (search: `only headers=@file reads`). Those files now match the template. The local shared credential classifier was dropped: the 1.17.0 native and fallback copies carry the same 40 exclusion and 32 inclusion patterns it did. Negative controls: adding the local tests to the unmodified 1.17.0 hooks fails with `fallback finding-span overlap failed` and `quoted path case failed on scanner 1`. After the merge, `deny-dangerous.sh --self-test` reports `executed=906, skipped=871` and `deny-git-mutations.sh --self-test` reports `executed=910, skipped=892`, and both pass. Five skill and playbook files that carried cosmetic-only local edits were restored to the 1.17.0 templates the same day, so `Skill Template Drift` in `goat-flow audit` lists only these two hooks.

**Prevention:** Treat `drift`, `installation-stale` or `both-changed` rows that cover `.goat-flow/hooks/` as a report, not an instruction. Before syncing or forcing a path, three-way merge each file against the previous release's template: install `@blundergoat/goat-flow@<old>` into the scratchpad as the merge base, then check whether upstream already contains each local fix. Re-apply only the fixes that a negative-control self-test shows are missing. Land remaining fixes in the goat-flow checkout so the next upgrade is a no-op, and re-run all four hook self-tests after any sync.

## Footgun: `goat-flow install` silently drops the `.env*` deny catch-alls from `.claude/settings.json`

**Status:** active | **Created:** 2026-10-03 | **Evidence:** ACTUAL_MEASURED
**Decision changed:** Whether a goat-flow upgrade needs a manual diff of `.claude/settings.json` before it is accepted.
**Trigger phase:** VERIFY

`goat-flow install` replaces the `Read(**/.env*)` and `Edit(**/.env*)` deny rules with eight named variants so `.env.example` stays readable. The rewrite lives in `node_modules/@blundergoat/goat-flow/dist/cli/install-command.js` (search: `rule === "Read(**/.env*)"`) and the variant list in `node_modules/@blundergoat/goat-flow/workflow/install-goat-flow.sh` (search: `ENV_DENY_EXPANSIONS`). After the rewrite, other variants such as `.env.backup` or `.env.prod` are open to Claude's Read and Edit tools, because the Bash deny hook covers only shell commands. This project added both catch-alls back by hand after earlier upgrades removed them: `Read` in `bafe1f7` and `Edit` in `cc89c13`.

**Evidence:** Measured 2026-10-03 during the 1.17.0 upgrade. The install output listed every retired rule it removed, including `Bash(*sudo *)` and `Read(**/secrets/**)`, but printed nothing about the two `.env*` rules. `git diff .claude/settings.json` showed both gone, and they were restored by hand after the install. `.codex/config.toml` never carried a catch-all, so the Codex profile was unaffected.

**Prevention:** After any `goat-flow install`, diff `.claude/settings.json` against `HEAD` and restore `Read(**/.env*)` and `Edit(**/.env*)` if they are missing. The install log is not a complete list of removals.

## Resolved Entries

## Footgun: goat-flow content audit requires a goat-flow-dashboard-views line in a consumer code map

**Status:** resolved | **Created:** 2026-07-20 | **Resolved:** 2026-10-03 | **Evidence:** OBSERVED

goat-flow 1.14.0's `audit --check-content` ran `code-map-dashboard-view-drift` on any project with `.goat-flow/code-map.md`. With no `src/dashboard/views/*.html` on disk, the check fell back to goat-flow's own manifest view list, so a consumer code map had to carry a line listing goat-flow's dashboard view names. This project kept that line on the `node_modules/` entry of its code map.

**Resolution:** goat-flow 1.17.0 treats the selected project's files as the only view authority (`node_modules/@blundergoat/goat-flow/dist/cli/audit/check-factual-semantic-drift.js`, search: `an explicit inventory must match target files exactly`). After the upgrade, the workaround line itself failed `audit --check-content` with `code-map-dashboard-view-drift`. The line was removed on 2026-10-03; with no claim and no view files, the check sees absence on both sides.

**Prevention:** Do not add a dashboard-views inventory back to `.goat-flow/code-map.md`; this project has no `src/dashboard/views/`, so any listed view fails the content audit. Before adding a workaround for an upstream check, re-read the checker source in the installed goat-flow version, and re-check the workaround after each upgrade.

## Footgun: classmap-authoritative hid newly added src/ classes in dev

**Status:** resolved | **Created:** 2026-06-07 | **Resolved:** 2026-06-07 | **Evidence:** ACTUAL_MEASURED

`composer.json` (search: `"optimize-autoloader"`) previously also set `config.classmap-authoritative: true`, which disables the PSR-4 filesystem fallback in the generated autoloader. A newly created `src/` class (e.g. a new Rule) was then invisible to `bin/gruff-php` and `RuleRegistry::defaults()` (search: `RuleRegistry`) until `composer dump-autoload` regenerated the classmap — symptom: `Class "...Rule" not found`, or a new rule silently missing from `list-rules`. The flag only ever affected this repo's own dev install (a consumer's root config governs their autoloader optimisation), so it bought nothing here.

**Resolution:** Removed `classmap-authoritative` from `composer.json` (kept `optimize-autoloader: true`). Verified the regenerated autoloader reports `isClassMapAuthoritative()` false and that a class created after a dump — absent from `vendor/composer/autoload_classmap.php` — still resolves via `class_exists()`.

**Prevention:** Do not re-add `classmap-authoritative: true` to `composer.json`; it reinstates the invisible-new-class trap. `optimize-autoloader: true` is safe — it builds the fast classmap without disabling the PSR-4 fallback.

## Footgun: PHP-named scaffold has no PHP app surface yet

**Status:** resolved | **Created:** 2026-05-09 | **Resolved:** 2026-05-09 | **Evidence:** ACTUAL_MEASURED

`README.md` (search: `# gruff-php`) names the project, but the repository currently has no `composer.json`, `src/`, `tests/`, or PHP runtime configuration. The name makes it easy for agents to assume Composer, PHPUnit, or PHPStan commands exist. They do not exist until real app structure is added.

**Resolution:** M01 added the application surfaces:

- `composer.json` (search: `"bin": [`) declares the package binary.
- `bin/gruff-php` (search: `(new Application())->run()`) boots the console application.
- `src/Cli/Application.php` (search: `final class Application`) registers the command surface.
- `src/Cli/Command/AnalyseCommand.php` (search: `final class AnalyseCommand`) implements the analyser command.
- `tests/Console/ListRulesCliTest.php` (search: `testVersionCommandRunsThroughBinary`) proves the binary runs.

**Prevention:** Before listing app commands or describing runtime architecture, check for the actual files that define them. If a future scaffold has no app surface, say "no application command configured yet" instead of inventing PHP defaults.

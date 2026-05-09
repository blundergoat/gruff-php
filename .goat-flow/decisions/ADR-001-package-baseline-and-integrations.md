# ADR-001: Package Baseline And Integrations

**Status:** Accepted
**Date:** 2026-05-09
**Ticket/Context:** `.goat-flow/tasks/0.1/M01-package-scaffold-and-quality-gates.md`

## Context

M01 needs real project commands before subsequent v0.1 milestones can rely on PHP tooling. The repository started as a scaffold with no `composer.json`, `src/`, `tests/`, or PHP runtime config, as recorded in `.goat-flow/footguns/setup.md` (search: `PHP-named scaffold has no PHP app surface yet`).

Dependency evidence from this session:

- Local runtime is PHP 8.3.30 and Composer 2.9.5.
- `infection/composer.json` requires PHP `^8.3`, `nikic/php-parser ^5.6.2`, PHPUnit `^11.5.27`, and Symfony Console/Finder/Process `^6.4 || ^7.0 || ^8.0`.
- `cognitive-complexity/composer.json` requires PHP `^8.3`, `nikic/php-parser ^5.3`, and PHPUnit `^11.5`.
- `grumphp/composer.json` supports PHP `~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0`, `nikic/php-parser ^5.7`, PHPUnit `^11.5.50`, and Symfony Console/Finder/Process `^6.4 || ^7.0 || ^8.0`.
- `composer update --no-interaction` resolved `nikic/php-parser 5.7.0`, Symfony Console/Finder/Process 7.4.x, and PHPUnit 11.5.55 for this project.

## Decision

`gruff-php` v0.1 is a Composer CLI package with:

- PHP runtime floor `^8.3`.
- CLI binary `bin/gruff`.
- Namespace `GruffPhp\`.
- Runtime dependencies on `nikic/php-parser`, Symfony Console, Symfony Finder, and Symfony Process.
- Dev dependency on PHPUnit 11.
- Composer scripts `check` and `test` as the first static/contract and automated verification commands.

Infection remains a v0.1 integration target, but it is not a mandatory runtime dependency in M01. The v0.1 integration path is report ingest first, with explicit optional execution mode added only when M14 proves tool availability and failure handling.

Git is optional at runtime. Diff mode must degrade clearly outside git worktrees instead of making normal analysis depend on git.

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| PHP `^8.2` floor | Wider install base, but Infection alignment and current local runtime evidence are weaker | Rejected for v0.1 because Infection and parser-heavy sibling projects point to PHP 8.3 as the safer integration floor |
| PHP `^8.3` floor | Excludes PHP 8.2 users | Accepted because it aligns with Infection, cognitive-complexity, phpinsights, local runtime, and PHPUnit 11 evidence |
| PHP `^8.4` floor | Narrows dogfooding and adoption too aggressively | Rejected because only `easy-quality` points that high |
| Make Infection mandatory in M01 | Base analyzer install can fail for mutation/test-framework reasons | Rejected; M14 owns optional execution and ingestion behavior |
| Report-ingest-first Infection path | Users need an external Infection report for mutation signal at first | Accepted because it keeps mutation in v0.1 without blocking base scoring |
| Require git for diff mode | Non-git directories and CI edge cases break normal use | Rejected; git is optional and diff mode must have clear non-git behavior |

## Reversibility

The PHP floor and dependency set are reversible before a public release by changing `composer.json` and re-running Composer resolution plus the M01 gates. After release, lowering the PHP floor would require compatibility tests across all parser, Symfony, PHPUnit, and Infection integration paths. Making Infection mandatory would require a new ADR because it changes the installation and failure model.

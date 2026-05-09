# Architecture - gruff-php

## System Overview

`gruff-php` is a Composer-based PHP CLI package scaffold for an opinionated PHP code quality analyzer. `composer.json` defines package metadata, dependencies, scripts, autoloading, and the `bin/gruff` executable; `src/Console/Application.php` wires the Symfony Console application; `src/Command/AnalyseCommand.php` provides the v0.1 analysis command surface for source discovery, parsing, config loading, and rule execution.

The installed harness remains separate from the app surface: `.goat-flow/` stores durable project knowledge and tool playbooks, `.claude/` stores Claude Code skills/hooks/settings, and `.codex/` plus `.agents/skills/` stores Codex hook/config surfaces and shared agent skills.

## Request Flow

The current request flow is CLI-only:

1. A user runs `bin/gruff` through PHP or Composer's bin proxy.
2. `bin/gruff` loads `vendor/autoload.php`.
3. `GruffPhp\Console\Application` registers available Symfony Console commands.
4. `GruffPhp\Command\AnalyseCommand` expands input paths through `GruffPhp\Source\SourceDiscovery`.
5. Discovered PHP files become `GruffPhp\Source\SourceFile` values.
6. `GruffPhp\Parser\PhpFileParser` parses each source file with `nikic/php-parser`.
7. Each parsed file becomes a `GruffPhp\Parser\AnalysisUnit` containing source text, AST statements, tokens, and parse diagnostics.
8. `GruffPhp\Config\ConfigLoader` loads default rule settings and optional project JSON config from `.gruff.json` or `--config`.
9. `GruffPhp\Rule\RuleRegistry` runs enabled rules over successfully parsed `AnalysisUnit` values and returns `GruffPhp\Finding\Finding` values.
10. The current command prints discovery, parse, and finding results. It returns exit code 0 for clean/parser-success runs, exit code 1 when paths are missing or parse errors are present, and exit code 2 for invalid config.

Formal JSON reporting, scoring, dashboard, baselines, and diff-mode flow are owned by subsequent v0.1 milestones.

## Auth / Trust Boundaries

No runtime authentication or authorization boundary exists. The app boundary is local CLI execution against user-provided paths. Current CLI code reads local PHP source files supplied by the user and applies default ignored-directory rules before parsing.

The agent tooling trust boundary remains separate: `.claude/hooks/deny-dangerous.sh` and `.codex/hooks/deny-dangerous.sh` are registered to block dangerous shell commands before agent execution.

## Data Flow

Composer metadata lives in `composer.json` and dependency resolution lives in `composer.lock`. Runtime code lives under `src/`; tests live under `tests/`; PHPUnit configuration lives in `phpunit.xml.dist`.
PHPStan configuration lives in `phpstan.neon.dist` and runs at level 10 over `src/` and `tests/`, excluding the intentionally invalid syntax fixture.

Source and analysis flow:

- `SourceDiscovery` accepts explicit paths or defaults to `.`.
- Default ignored directories include VCS, dependency, cache, build, generated, coverage, and frontend artifact directories.
- `PhpFileParser` catches parser errors per file and returns diagnostics instead of aborting the whole run.
- `Finding` preserves stable rule id, message, file path, location, severity, pillar, tier, confidence, remediation, fingerprint, and metadata.
- `RuleDefinition` owns stable rule metadata and default thresholds.
- `ConfigLoader` currently supports one JSON config shape with root `rules`; unknown root keys, rule ids, rule keys, and threshold keys fail loudly.
- `RuleRegistry::defaults()` currently registers `size.file-length` as the contract smoke rule for threshold and finding behavior.

Durable project documentation lives in `README.md` and committed `.goat-flow/` files. Local continuity and generated working notes stay under `.goat-flow/logs/`, `.goat-flow/tasks/`, and `.goat-flow/scratchpad/` according to their nested `.gitignore` files.

## Deployment / Operations

Composer is now the package manager. Local verification commands are defined by `composer.json` scripts:

- `composer check` runs package validation and PHP syntax checks.
- `composer phpstan` runs PHPStan 2 at level 10.
- `composer test` runs PHPUnit.

No CI, deployment, Packagist release, signed release, or runtime service operation flow is configured yet. Before adding those claims or commands, read the actual files that introduce them.

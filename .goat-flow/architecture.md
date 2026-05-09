# Architecture - gruff-php

## System Overview

`gruff-php` is a Composer-based PHP CLI package scaffold for an opinionated PHP code quality analyzer. The current app surface is intentionally minimal: `composer.json` defines package metadata, dependencies, scripts, autoloading, and the `bin/gruff` executable; `src/Console/Application.php` wires the Symfony Console application; `src/Command/AnalyseCommand.php` provides the no-op v0.1 command surface that subsequent analysis milestones extend.

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
8. The current command prints discovery and parse results. It returns exit code 0 for clean parser runs and exit code 1 when paths are missing or parse errors are present.

No real rule engine, formal reporting schema, scoring, dashboard, or diff-mode flow exists yet; those are owned by subsequent v0.1 milestones.

## Auth / Trust Boundaries

No runtime authentication or authorization boundary exists. The app boundary is local CLI execution against user-provided paths. Current CLI code reads local PHP source files supplied by the user and applies default ignored-directory rules before parsing.

The agent tooling trust boundary remains separate: `.claude/hooks/deny-dangerous.sh` and `.codex/hooks/deny-dangerous.sh` are registered to block dangerous shell commands before agent execution.

## Data Flow

Composer metadata lives in `composer.json` and dependency resolution lives in `composer.lock`. Runtime code lives under `src/`; tests live under `tests/`; PHPUnit configuration lives in `phpunit.xml.dist`.

M02 source flow:

- `SourceDiscovery` accepts explicit paths or defaults to `.`.
- Default ignored directories include VCS, dependency, cache, build, generated, coverage, and frontend artifact directories.
- `PhpFileParser` catches parser errors per file and returns diagnostics instead of aborting the whole run.

Durable project documentation lives in `README.md` and committed `.goat-flow/` files. Local continuity and generated working notes stay under `.goat-flow/logs/`, `.goat-flow/tasks/`, and `.goat-flow/scratchpad/` according to their nested `.gitignore` files.

## Deployment / Operations

Composer is now the package manager. Local verification commands are defined by `composer.json` scripts:

- `composer check` runs package validation and PHP syntax checks.
- `composer test` runs PHPUnit.

No CI, deployment, Packagist release, signed release, or runtime service operation flow is configured yet. Before adding those claims or commands, read the actual files that introduce them.

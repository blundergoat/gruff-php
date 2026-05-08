# Architecture - gruff-php

## System Overview

`gruff-php` is currently a scaffold repository, not a PHP application yet. The only project-owned app file is `README.md`; the rest of the current structure is goat-flow harness state for Claude and Codex.

The installed harness has three real components: `.goat-flow/` stores durable project knowledge and tool playbooks, `.claude/` stores Claude Code skills/hooks/settings, and `.codex/` plus `.agents/skills/` stores Codex hook/config surfaces and shared agent skills.

## Request Flow

No application request flow exists yet. There is no `composer.json`, route file, controller, entrypoint, or PHP source directory in this checkout.

## Auth / Trust Boundaries

No runtime authentication or authorization boundary exists yet. The current trust boundary is agent tooling: `.claude/hooks/deny-dangerous.sh` and `.codex/hooks/deny-dangerous.sh` are registered to block dangerous shell commands before agent execution.

## Data Flow

Durable project documentation lives in `README.md` and committed `.goat-flow/` files. Local continuity and generated working notes stay under `.goat-flow/logs/`, `.goat-flow/tasks/`, and `.goat-flow/scratchpad/` according to their nested `.gitignore` files.

## Deployment / Operations

No deployment, CI, package manager, or runtime operations flow is configured yet. Before adding deployment claims or commands, read the actual files that introduce them.


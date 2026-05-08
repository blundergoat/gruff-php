---
category: setup
last_reviewed: 2026-05-09
---

# Setup Footguns

## Footgun: PHP-named scaffold has no PHP app surface yet

**Status:** active | **Created:** 2026-05-09 | **Evidence:** ACTUAL_MEASURED

`README.md` (search: `# gruff-php`) names the project, but the repository currently has no `composer.json`, `src/`, `tests/`, or PHP runtime configuration. The name makes it easy for agents to assume Composer, PHPUnit, or PHPStan commands exist. They do not exist until real app structure is added.

**Prevention:** Before listing app commands or describing runtime architecture, check for the actual files that define them. In this scaffold, say "no application command configured yet" instead of inventing PHP defaults.

## Resolved Entries


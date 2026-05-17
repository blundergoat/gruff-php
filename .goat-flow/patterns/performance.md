---
category: performance
last_reviewed: 2026-05-17
---

# Performance Patterns

## Pattern: Path-limit snapshots before materialising them

**Created:** 2026-05-11

**Context:** Branch-review analysis can compare current findings against a Git base snapshot. On large repositories, materialising the full base tree before applying `--changed-only` turns a single-file review into full-repo archive and extraction work. The relevant implementation anchors are `src/Review/GitArchiveSnapshot.php` (search: `public function create(string $projectRoot, string $ref, array $paths = [])`) and `src/Command/AnalyseCommand.php` (search: `baseSnapshotPaths`, search: `currentAnalysisPaths`).

**Approach:** Compute the smallest repo-relative candidate path list before creating the snapshot, expand it against the base ref with `git ls-tree -r --name-only`, and archive only paths that exist in the base ref. For `--diff-vs=<base> --changed-only` with no explicit paths, derive current analysis paths from Gruff's own Git changed-file list instead of requiring callers to shell out to `git diff`. Do not move large archive payloads through PHP strings or `Process::setInput()`; use a temp archive file or a streamed process pipeline. Added files should produce an empty base snapshot path set instead of a pathspec failure, and deleted files still need base-side paths so removed findings can be reported.

**Generic branch-review smoke command:**

```bash
cd /path/to/target-project
php /path/to/gruff-php/bin/gruff-php analyse --diff-vs=origin/deploy --changed-only --no-config --no-baseline --format=json --fail-on=none
```

**Verification:** Keep lightweight tests that prove explicit changed files do not archive unrelated base files, added files do not fail base snapshot setup, deleted files can report removed findings, and failed snapshot creation removes temporary directories.

## Pattern: Cache repeated AST metrics within one analyse process

**Created:** 2026-05-17

**Context:** `scripts/test-performance.sh --full --corpus=all` identified repeated AST traversals as a real analyse hot path. Before the optimisation pass, the large corpus showed `design.single-implementor-interface` at about 688 ms and `complexity.maintainability-index` at about 522 ms in the per-rule top list. The relevant anchors are `src/Rule/Complexity/CyclomaticComplexityRule.php` (search: `static $cache = null`), `src/Rule/Complexity/HalsteadVolumeRule.php` (search: `validatedMetrics`), and `src/Rule/Design/SingleImplementorInterfaceRule.php` (search: `collectClassTypeReferences`).

**Approach:** When two rules or helper calculations need the same expensive AST metric for the same `PhpParser\Node`, cache it in a process-local `WeakMap` keyed by the node. This lets `complexity.maintainability-index` reuse cyclomatic and Halstead calculations that other complexity rules already requested, while avoiding retention of parsed ASTs after the analyse process releases them. For project rules that scan whole corpora, prefer one broad `NodeFinder::find()` pass that collects the needed class/interface nodes over multiple `findInstanceOf()` passes for each node type, then do targeted subtree scans only where the rule needs class-local references.

**Verification:** Use the performance script before and after the change, not ad hoc stopwatch timing. The 2026-05-17 optimisation pass kept focused rule tests green (`tests/Rule/Complexity/*`, `tests/Rule/Design/SingleImplementorInterfaceRuleTest.php`), `composer test` green, and `composer check` green. A follow-up `scripts/test-performance.sh --full --corpus=large` run reported `design.single-implementor-interface` at about 401 ms and `complexity.maintainability-index` at about 177 ms; rerun on the same machine/PHP build because wall time has normal local variance.

---
category: performance
last_reviewed: 2026-05-16
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

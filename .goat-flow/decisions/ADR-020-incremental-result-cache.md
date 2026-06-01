# ADR-020 - Incremental per-file result cache

- Status: Accepted
- Date: 2026-05-30
- Relates to: ADR-017 (mission: govern AI-generated code; fast hook feedback keeps the agent loop tight)

## Context

Every `gruff-php analyse` invocation is a cold start: it re-parses and re-runs all
per-unit rules from scratch. The in-process caches (`NodeIndex`, complexity
memoization) live and die with the process, so a hook that spawns a fresh process
per run, or CI that re-scans an unchanged tree, pays full price each time. We want a
warm, cross-run cache — **without ever trading correctness for speed** (a stale
cached finding misleads the reviewer, the cardinal sin).

A material design constraint surfaced during implementation: **per-file caching is
only byte-identical-correct when no project rule is enabled.** Project rules
(`ProjectRuleInterface`, including streaming `ProjectRuleAccumulator`s such as the
design / dead-code rules) observe *every* analysis unit; reusing one file's cached
findings while skipping its analysis would corrupt their cross-file output. Only
3 rules are project rules, so configs that exclude them (e.g. the `security`
profile, or a fast per-file hook config) are fully cacheable.

## Decision

1. **Content-addressed key.** Per-file key = `sha256(runDigest + displayPath +
   sha256(fileBytes))`, where `runDigest = sha256(gruff version + minimumPhpVersion
   + sorted allowlists + the enabled-rule set with each rule's resolved settings)`.
   Any change to what gruff checks, how, on which bytes, or at which path → a new
   key → a guaranteed miss. The display path is in the key because it is part of
   every finding's identity, so two identical files at different paths never share
   an entry. The digest is a conservative superset: it only ever invalidates more.

2. **Guarded to no-project-rule runs.** The cache engages only when
   `!hasEnabledProjectRules` (and `!--no-cache`). With any project rule active the
   cache is bypassed — correct, just uncached. Files with parse errors are never
   cached (so their diagnostics are always reproduced).

3. **Fail open, never stale.** A missing, unreadable, or corrupt entry, or any
   encode failure, is treated as a miss. With `--no-cache` or a cold cache, output
   is byte-identical to before — proven by a cold-vs-warm equivalence test over a
   real, metadata-bearing finding.

4. **Bounded and private.** Entries live under a gitignored, discovery-ignored
   `.gruff-cache/` directory, capped with oldest-first eviction. The store holds
   only the findings a run produced (sensitive-data findings are already redacted),
   never raw source.

5. **Snapshot cache deferred.** Caching the `--diff-vs` base-ref `GitArchiveSnapshot`
   by commit SHA is valuable but has a path-limiting subtlety (snapshots are
   archived per requested path set, not whole-tree), so it is left to a focused
   follow-up rather than bundled here.

## Consequences

- Cache-eligible runs (no project rules) re-use unchanged files' findings across
  runs; the headline win is repeated whole-set scans where most files are stable.
- `analyse` and the cache can never disagree: the key folds in every input to a
  per-unit rule, and the equivalence test is the standing proof.
- Correctness is preserved unconditionally — the guard plus the fail-open contract
  mean the cache can only ever make a correct run faster, never change its result.

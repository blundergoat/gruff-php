# ADR-019 - `paths.ignore` authoritative everywhere, with a shared ignore engine and `check-ignore`

**Status:** Accepted
**Date:** 2026-05-30
**Relates to:** ADR-017 (mission: govern AI-generated code so a human can sign off)

## Context

gruff runs as a coding-agent hook: after an agent edits files, the hook runs gruff
on the changed paths and gates on the result. A project's `paths.ignore` records
code the team has deliberately put out of scope. If the hook surfaced findings for
those paths, the agent would waste loops "fixing" code no human wants reviewed.

Empirically (verified on a throwaway project ignoring `legacy/**`, 2026-05-30),
gruff-php **already** applies `paths.ignore` in every invocation shape — explicit
file args, whole-tree, `--changed-ranges`, `--diff` working-tree, `--diff -` stdin,
and `--include-ignored` — because every mode routes file selection through
`SourceDiscovery`, which checks the configured patterns unconditionally. An ignored
path yields zero findings and appears in the report's `ignoredPaths`.

Two gaps remain, both relevant to the hook use case:

1. **No reason.** `ignoredPaths` is a bare list of strings. A hook (or a human)
   cannot tell *why* a path was skipped — a config glob, a built-in default, a
   generated lockfile, or `.gitignore` — nor which pattern matched.
2. **No way to ask without analysing.** There is no command to answer "would gruff
   ignore this path?" cheaply, and the ignore logic lives in private methods inside
   `SourceDiscovery`, so any new consumer would have to duplicate the glob/default
   matching — inviting drift from the behaviour `analyse` actually uses.

## Decision

1. **One ignore engine.** Extract the ignore decision into a single reusable
   resolver that owns the configured-glob match, the built-in default directories,
   the generated-file (lockfile) match, and the `.gitignore` lookup. `SourceDiscovery`
   delegates to it; the new command uses the same resolver. There is exactly one
   implementation of the ignore decision.

2. **Report the reason, additively.** Keep the existing `ignoredPaths` string list
   byte-identical for backward compatibility, and add a parallel
   `ignoredPathDetails` field whose entries carry `path`, `source`, and `pattern`.
   This is an additive change within the existing `gruff.analysis.v2` schema — no
   rename, per the cross-language compatibility policy — documented as a migration
   note in the schema/output docs.

3. **Source taxonomy.** `source` is one of `config` (a `paths.ignore` glob — `pattern`
   is that glob), `default` (a built-in ignored directory such as `vendor` — `pattern`
   is the directory token), `generated` (a built-in generated/lock filename such as
   `composer.lock` — `pattern` is the filename), or `gitignore` (excluded by git —
   `pattern` is the matching `.gitignore` rule when git reports it, else null).

4. **`--include-ignored` never overrides `paths.ignore`.** It opts back into
   git-ignored and default/generated paths only. Configured `paths.ignore` stays
   authoritative under it (already true; now locked by tests).

5. **`check-ignore` command.** Add `check-ignore [--format text|json]
   [--config <path>|--no-config] <path>...` that answers the ignore decision per
   path using the shared engine and resolution, performs no analysis (O(1) per
   path), and mirrors `git check-ignore` exit codes (0 = at least one ignored,
   1 = none, 2 = error). JSON `[{path, ignored, source, pattern}]` is the agent
   contract; verbose text prints `<path>\t<source>:<pattern>`.

## Consequences

- A hook can pre-flight `check-ignore` (or read `ignoredPathDetails`) to drop
  out-of-scope changed files before it even calls `analyse`, and can explain to the
  agent *why* a path is skipped.
- `analyse` and `check-ignore` can never disagree about what is ignored: they share
  one engine. Adding a built-in ignore or changing glob semantics changes both at
  once.
- Existing JSON/SARIF/text consumers keep working unchanged; `ignoredPathDetails`
  is purely additive and the schema string is unchanged.
- The cross-language `CONTRACT.md` gains a `check-ignore` command and an
  authoritative-`paths.ignore` clause so the guarantee is consistent across ports.

# ADR-021 - Config presets and `extends:` inheritance

- Status: Accepted
- Date: 2026-05-30
- Relates to: ADR-017 (mission: gruff must be easy to adopt as a coding-agent hook)

## Context

A repo that wants gruff must today hand-maintain a ~560-line `.gruff-php.yaml`
enumerating every default-enabled rule. For a stable 1.0.0 whose point is "drop me
in as a hook", that is the dominant adoption friction. We add bundled presets and an
`extends:` key so a config is expressed as a small delta against a known base.

This is **sugar over the existing config surface**: `extends:` is a parse-time merge
of YAML arrays that runs before the merged config is applied to `AnalysisConfig`.
Nothing in `RuleRegistry`, `RuleSelection`, `RuleSettings`, or the rule runner
changes.

## Decision

1. **Three bundled presets** under `resources/profiles/`: `gruff.recommended`,
   `gruff.starter`, `gruff.strict`. No more (kill criterion: avoid choice paralysis).

2. **`gruff.recommended` = the registry defaults**, expressed as a minimal preset
   (schema + intent header). It deliberately does *not* copy the repo's own
   `.gruff-php.yaml`, because that file adds extra accepted-abbreviations and
   repo-local `pathOverrides` *beyond* the defaults — which would break the anchor
   guarantee that `extends: gruff.recommended` with no overrides behaves identically
   to a no-config run. `starter` and `strict` `extends: gruff.recommended` and layer
   explicit deltas (starter narrows selection to the highest-signal pillars; strict
   enables default-disabled rules and tightens thresholds).

3. **`extends:` accepts one string** — a bundled name (`gruff.*`, resolved from the
   package `resources/profiles/`) or a path (relative to the loading file's
   directory, or absolute). No URLs, no list, no `imports:`.

4. **Merge by layering, not array-merge.** The chain resolves to an ordered list of
   raw configs (ancestor first, current file last); each is applied through the
   existing apply-chain in turn, so a child's settings layer over what it inherits.
   This reuses the validated config machinery (no separate merge code, no loosely
   typed merged array) and yields **child-replaces-per-section** semantics: a child
   block for a section (`paths.ignore`, `selection`, `minimumSeverity`,
   `failureConditions`, scalars) replaces the inherited block for that section;
   `rules.<id>` is per-rule (a child rule block replaces the parent's for that id,
   rules only in the parent are kept); registry-seeded allowlist defaults survive
   for sub-keys nobody sets. Predictable layering over clever merge. (Cross-source
   **union** for shared-base lists — e.g. appending a team base's `paths.ignore` — is
   a deferred refinement; for the common "extend a bundled preset" case the presets
   set none of the union-relevant sections, so layering is equivalent.)

5. **Cycle detection + depth cap 5.** Chains resolve depth-first with a visited set
   keyed by canonical path / preset name; a cycle or a 6th hop throws a
   `ConfigException` naming the chain. Unknown preset names throw, listing the three
   valid presets.

6. **Provenance.** The merged `AnalysisConfig` carries `extendsChain: list<string>`
   (most distant ancestor first, current file last) for an effective-config surface.

## Consequences

- A team maintains one shared base and each repo extends it with a few lines; a new
  user picks a preset in one line.
- A preset-integrity test proves every rule id referenced in every preset exists in
  the registry (no drift); a preset-identity test proves `extends: gruff.recommended`
  equals no-config behaviour (the no-behaviour-change anchor).
- No default behaviour changes: an absent `extends:` key means "no inheritance", and
  the repo's own `.gruff-php.yaml` is left untouched (its migration is a separate,
  reviewable change).

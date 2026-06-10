# ADR-026: Retire the project rules (`dead-code.unused-internal-*`, `design.single-implementor-interface`)

**Status:** Accepted
**Date:** 2026-06-10
**Relates to:** [ADR-003 project-rule seam](ADR-003-project-rule-seam.md) (the seam stays), [ADR-020 incremental result cache](ADR-020-incremental-result-cache.md) (cache guard now always passes — see the correction addendum there), [ADR-014 retire naming.parameter-type-name](ADR-014-retire-naming-parameter-type-name.md) (retirement precedent)

## Decision

The four `ProjectRuleInterface` rules — `dead-code.unused-internal-class`,
`dead-code.unused-internal-constant`, `dead-code.unused-internal-function`, and
`design.single-implementor-interface` — are removed from gruff-php. The rule classes, their private
support machinery (`AbstractUnusedInternalSymbolRule`, `DeadCodeProjectIndex`,
`DeadCodeProjectScope`, `DeadCodeNameResolver`, `DeadCodeSymbolDeclaration`,
`DeadCodeSymbolReference`), their registry entries, their `.gruff-php.yaml` blocks, their tests and
fixtures, and their documentation entries are deleted in the same change. The registry drops from
133 to 129 rule ids; the `dead-code` pillar drops from 13 to 10 rules; the `design` pillar empties
and is reserved alongside `Coupling` and `Architecture`.

The `ProjectRuleInterface` / `ProjectRuleAccumulator` / `ProjectSourceTextRuleAccumulator` seam and
all registry/pipeline seam code stay (ADR-003 stands); only the implementors go. A seam-level test
with a fake accumulator keeps the contract pinned.

Configs that still name the retired rule ids (init-generated `.gruff-php.yaml` files in the wild
carry all four blocks) do not hard-fail: unknown rule ids under `rules:` now produce a one-line
warning on stderr and the block is ignored. `selection:` entries stay strict because they change
which rules run.

## Context

These four rules were the only `ProjectRuleInterface` implementors, and the evidence from the
0.3.2 review (`.goat-flow/scratchpad/0.3.2-review-2026-06-10.md`, all numbers adversarially
verified) showed they were simultaneously the most expensive and the least trustworthy rules in the
catalogue:

- **Cost (measured, §2A of the review):** the four rules are the sole trigger of the narrow-path
  whole-project reparse. Single-file `analyse`/`hook` on real repos: 13.7 s user (jetpack), 13.0 s
  (mautic), 20.8 s (woocommerce), 31.0 s (shopware), peaking at 1.35–3.13 GB RSS — one small file
  cost 30–45 % of scanning the entire repo. With the four rules off: 0.03–0.07 s (228–416×).
- **Cache deadlock (ADR-020):** with any project rule enabled the per-file result cache is bypassed,
  so under default config the cache shipped in 0.3.0 never engaged. Warm whole-repo self-scan on
  this repo: 4.1 s before, 0.07–0.09 s after retirement (measured in this change: warm
  `analyse .` = 0.09 s wall).
- **Signal (sampled FP rates, review §7, every claimed FP re-audited by an adversarial skeptic):**
  `dead-code.unused-internal-class` 19/20 (95 %) FPs on shopware, 11/12 (92 %) on mautic — XML DI
  wiring, migration directory-scan discovery, and PHP route arrays are invisible to the reference
  index. `design.single-implementor-interface` 9/20 (45 %) on shopware+mautic and 19/19 (100 %) on
  jetpack+woocommerce — no extends propagation, no docblock types, no `::class` counting. The
  constant/function siblings share the same reference index and the same blindness. The mission is
  findings a human can trust; these were the least trustworthy rules in the catalogue.
- **Hook value:** hook output is pre-trimmed to the analysed files, so the cross-file findings these
  rules exist for can never surface in the per-edit hook anyway — the hook paid the whole-project
  cost for signal it could not show.
- **This repo:** the four rules emit zero findings and zero baseline entries here; whole-repo
  findings before/after retirement differ by exactly zero findings (verified byte-identical in this
  change).

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| A. Keep, fix the reference index (XML DI, `::class`, docblock types, extends propagation) | Months of index work to chase framework wiring conventions that grow without bound; the per-edit latency and memory cost remains until a summary cache also lands. | Rejected for now. The reference-index work is the right shape for a future reintroduction, but it must precede shipping the verdicts, not follow them. |
| B. Default-off (`isEnabledByDefault: false`) | Dormant classes, fixtures, options, and tests stay in the tree; the whole-project pipeline branch stays live for any user who flips them on, with the same FP rates. | Rejected. Maintenance burden without trustworthy output; the owner decision was remove-from-product. |
| C. Keep rules, gate to `analyse`-only (never hook) | Per-edit cost solved, but CI/full scans still emit 45–100 % FP verdicts on framework repos; trust damage continues where the rules do run. | Rejected. Slower delivery of the same untrustworthy signal. |
| D. Delete rules + support machinery, keep the seam | Adopters relying on cross-file dead-code/design hints lose them; configs naming the ids need compatibility handling (solved with warn-and-ignore). | **Accepted.** Smallest persistent surface; the seam keeps the door open for a trustworthy successor. |

## Consequences

- The per-file result cache (ADR-020) now engages by default: no registered rule implements
  `ProjectRuleInterface`, so `hasEnabledProjectRules()` is always false and `.gruff-cache/` is
  populated by ordinary runs. Single-file `analyse`/`hook` is no longer O(project) — measured on
  this repo: `analyse src/Command/AnalysisPipeline.php` 1.75 s → 0.08 s wall; warm `analyse .`
  ~4 s → 0.09 s wall.
- The cache guard's project-rule branch and the `projectContextUnits` pipeline path are inert until
  a new project rule lands. Removing the seam itself is deferred (see ADR-003) until a release
  proves nothing external registers project rules.
- Unknown rule ids under `rules:` warn-and-ignore instead of hard-failing, so existing
  init-generated configs keep working across the upgrade. The warning names the offending block.
- The `design` pillar has no emitter; docs and the architecture map mark it reserved.
- Adopters who valued the cross-file hints lose them; CHANGELOG and release notes call this out with
  the FP-rate rationale.

## Reversibility

**Two-way door.** The rule classes, support machinery, fixtures, and tests are restorable from git
history in one revert, and the ADR-003 seam they plugged into still compiles and is still tested
(fake-accumulator seam test in `tests/Rule/RuleRegistryTest.php`).

**Revisit trigger:** reintroduce cross-file dead-code/design analysis only behind a reference index
that can see DI/config wiring first — XML container files, PHP-array route configs,
`ContainerConfigurator::load` namespace globs, `::class` references, docblock types, and extends
propagation (the P1/P2 fix catalogue in review §7.2). Per the review's verdict, making the index
trustworthy is higher-value for the mission than making the old verdicts faster; do not revive the
deleted shape without it.

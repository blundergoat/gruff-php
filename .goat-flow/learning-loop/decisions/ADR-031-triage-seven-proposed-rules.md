# ADR-031: Triage seven proposed rules without expanding 0.5.2

**Status:** Accepted
**Date:** 2026-08-09
**Target release:** 0.5.2
**Relates to:** [ADR-003 project-rule seam](ADR-003-project-rule-seam.md), [ADR-017 mission](ADR-017-mission-govern-ai-generated-code.md), [ADR-026 project-rule retirement](ADR-026-retire-project-rules.md), [ADR-029 baseline matching](ADR-029-baseline-v2-group-count-matching.md)

## Context

Two consumer reports proposed seven rules after repeated review comments in one large PHP application. The requests mix three different products: mechanical formatting already owned by a fixer, generally useful analysis that gruff does not perform, and project-specific policy expressed through configuration. Treating all seven as one rule batch would hide those differences and would add rule, config, snapshot, documentation, and long-term calibration surface to the 0.5.2 release.

The release evidence is recorded in `.goat-flow/plans/0.5.2/M07-new-rule-proposals-triage-adr.md`. The durable constraints are:

- gruff's mission is human verifiability, and a gate's cheapest passing change should be a genuine improvement;
- `analyse` defaults to `--fail-on=advisory`, so an advisory rule still fails the default command;
- raw changed-region filtering can surface a newly substituted finding, but baseline, branch-review, `--fail-on-new`, and hook occurrence matching can hide it when another instance in the same identity group was removed;
- the parser already runs PHP-Parser's `NameResolver`, so fully qualified symbol recognition is available;
- current rule options support scalars, scalar lists, and scalar maps, not the proposed lists of structured per-symbol entries;
- `ProjectRuleInterface` is a cross-file execution seam, not a plugin loader. The CLI constructs `RuleRegistry::defaults()`, and config has no supported custom-rule or plugin key.

A stratified audit of 24 current rules found both genuine-improvement rules and cosmetic/readability rules. That precedent does not make cosmetic additions free: several existing comments incorrectly describe advisory as non-gating, while the default CLI threshold does gate on advisory.

## Decision

Add none of the seven rules to 0.5.2. Reject exact external-tool duplication, defer product gaps behind explicit reopening evidence, and fold the narrow frozen-prefix request into the broader banned-symbol question.

| Proposal | Verdict | Reason | Reopening or implementation trigger |
|---|---|---|---|
| `style.literal-first-comparison` | Reject | PHP CS Fixer 3.95.18's `yoda_style` exactly supports non-Yoda equality, identity, and ordering and applies the rewrite. PHPCS 4.0.1 also carries a disallow-Yoda sniff. Duplicating a formatter in gruff adds a second owner without a distinct verdict. | Reconsider only if gruff deliberately expands into formatter duplication and demonstrates an agent-facing diagnostic or changed-code capability the configured fixer cannot provide. |
| `style.negated-boolean-call` | Defer | No installed fixer converts `!$expr` to `$expr === false`, and the missed-negation rationale is relevant to legibility. The broad proposal is not behaviour-preserving for truthy/falsy non-booleans, however, and evidence comes from one consumer convention. | Require measured defect/review evidence from at least two unrelated projects, a statically proven Boolean subset, and an explicit default-gate decision. Any first version must be report-only, default-off, and exclude unresolved calls or operands. |
| `convention.banned-symbols` | Defer | FQN resolution makes per-file AST detection feasible, and the architecture use case can produce genuine replacements. The proposed nested entry schema is unsupported, there is no external-rule loader, and an empty default creates dormant built-in surface for one known consumer. | First decide a supported structured-option or extension schema, show at least two unrelated consumers, and fixture FQN aliases, constructors, functions, static/method calls, declarations, path exemptions, and per-entry messages without using `ProjectRuleInterface`. |
| `naming.vocabulary-map` | Defer | This is a naming-pillar, per-file policy gap, but case-shape rewriting and caller-visible identifiers create compatibility and false-positive risk. The proposed list-of-pairs shape also does not fit the current option contract. | Require a scalar-map-compatible or newly approved config schema, corpus precision from at least two projects, collision/case-shape tests, and `CONSIDER` treatment for caller-visible names. Start default-off and excluded from score. |
| `naming.frozen-class-prefix` | Reject as a standalone rule | It is one declaration-pattern instance of the deferred banned-symbol/policy facility. A separate built-in rule would duplicate its matching, configuration, baseline, and documentation surface. | Reconsider only as a `class-prefix` entry type after the broader policy facility satisfies its reopening trigger. |
| `style.trailing-comment` | Reject | PHP CS Fixer does not move post-statement comments, but PHPCS 4.0.1's `Squiz.Commenting.PostStatementComment` reports them and PHPCBF moves ordinary comments to their own line; annotation comments are deliberately non-fixable. This is established formatting/comment-tool ownership. | Reconsider only with measured semantic cases that the PHPCS sniff cannot express, such as a required pragma policy, and only if those cases need gruff's analysis rather than a custom PHPCS sniff. |
| `sensitive-data.fictitious-placeholder` | Defer and reframe | Existing PII/PHI rules prove jurisdiction-aware heuristics are not categorically out of scope, but “placeholder intent” is not statically knowable. The proposal's Australian reserved ranges are also stale: ACMA's current list includes geographic `5550` and `7010` ranges but enumerates particular mobile, 1300, and 1800 numbers rather than reserving all `0491 570/571 xxx` or `1800 160 xxx`. | Reopen as a realistic-PII-literal extension after defining an authoritative data source and update policy, redacted output, production/test scope, multi-jurisdiction configuration, and measured precision on multiple repositories. Use the current [ACMA fiction-number list](https://www.acma.gov.au/phone-numbers-use-tv-shows-films-and-creative-works), not copied ranges. |

A rejection here means “do not build this as a gruff rule,” not “the consumer convention is invalid.” A deferral preserves a product gap but refuses to turn one consumer's configuration into permanent core surface before its trust and maintenance model is known.

## Pillar and rule-seam decision

No `style` or `convention` pillar is added. Rule-id prefixes and scoring pillars are separate concepts, as the existing `waste.*` rules demonstrate.

| Proposal | Pillar if reopened | Execution seam | Current owner or gap |
|---|---|---|---|
| Literal-first comparison | Maintainability | Per-unit AST | PHP CS Fixer / PHPCS |
| Negated Boolean call | Maintainability | Per-unit AST plus proven local types | Explicit product gap |
| Banned symbols | Architecture | Per-unit AST with resolved names | Deferred policy/extension gap |
| Vocabulary map | Naming | Per-unit declarations and identifiers | Deferred project-policy gap |
| Frozen class prefix | Naming | Per-unit class-like declarations | Subset of banned-symbol policy |
| Trailing comment | Documentation | Source tokens | PHPCS / PHPCBF |
| Fictitious placeholder | Sensitive data | Per-unit source text | Deferred extension of existing PII heuristics |

`Architecture`, `Design`, and `Coupling` already exist as reserved enum cases. Scoring starts with the 10 built-in active pillars and dynamically adds another pillar when a finding uses it, so an empty reserved pillar is not an implementation blocker. A clean run would not display that non-built-in pillar until the scoring model is deliberately widened; any future architecture rule must decide that presentation explicitly.

None of these proposals needs whole-project context in its stated form. Using `ProjectRuleInterface` merely because a policy is project-specific would repeat the project-rule cost mistake ADR-026 retired. If gruff later supports third-party rules, that needs a separate loading and compatibility decision rather than overloading the cross-file seam.

## Baseline and new-code consequence

“Generate a baseline, then enforce only new instances” is not a sufficient adoption argument for a high-volume uniform-message rule. ADR-029's count budget hides a same-group substitution, and the current branch-review and hook occurrence ordinals have the same outcome when the group cardinality is unchanged. A future deferred rule must demonstrate its gate on the actual intended workflow and cannot cite baseline generation alone as proof of new-code isolation.

This does not reverse ADR-029: count matching still avoids noisy line-shift churn. It records that the trade-off is particularly material when deciding whether to introduce a noisy convention rule.

## Failure mode comparison

| Option | What fails | Verdict |
|---|---|---|
| Add all seven as advisory rules | External-tool duplication, unsupported config shapes, one-consumer defaults, and cosmetic findings all enter a default command that gates on advisory. | Rejected. |
| Add every project-specific proposal default-off | Dormant rule and fixture surface remains in core without proving a second consumer or a public extension contract; ADR-026 already showed default-off is not a substitute for trustworthy product ownership. | Rejected. |
| Reject all seven permanently | Loses a plausible missed-negation signal, reusable architecture policy, vocabulary policy, and realistic-PII gap before their evidence can mature. | Rejected. |
| Split exact-tool duplicates from evidence-bound product gaps | Consumers keep using the tools that already own formatting; gruff retains precise reopening paths without changing 0.5.2's catalogue. | Proposed. |

## Consequences

- 0.5.2 keeps 128 rules and its current pillar set, registry, config schema, snapshots, and dependency files.
- No implementation milestone is created from this ADR while it remains Proposed.
- Deferred items are routed to the 0.5.2 backlog with their evidence gates; the frozen-prefix case stays nested under banned symbols.
- Literal-first and trailing-comment conventions route to PHP CS Fixer and PHPCS respectively, not gruff.
- Separate backlog work records the measured same-group identity blind spot, `git archive`/ `export-ignore` snapshot limitation, and catalogue calibration mismatch. Those are findings from the triage, not reasons to broaden this ADR into application changes.
- Acceptance requires the maintainer to approve every row and trigger; until then this document remains Proposed.

## Reversibility

Two-way door. A rejected rule can return through a superseding ADR if its explicit external-tool or product-boundary trigger is met. A deferred rule can advance without superseding this ADR once its listed evidence is attached to a new implementation plan and the maintainer accepts the resulting scope. Changing the pillar model, config shape, or extension loading contract requires its own decision because each affects more than one proposed rule.

# ADR-014: Retire `naming.parameter-type-name`

**Status:** Accepted
**Date:** 2026-05-25
**Supersedes scope of:** [ADR-008 single-threshold rubric severity](ADR-008-single-threshold-rubric-severity.md) (only insofar as `naming.parameter-type-name` was one of the rules covered by that calibration)

## Decision

`naming.parameter-type-name` is removed from gruff-php. The rule class, its fixture, every test that targets the rule, the rule registry entry, the rule's priority slot in `RuleRegistry::NAMING_RULE_PRIORITY`, the rule's documentation entry in `docs/rules.md`, and the project's `.gruff-php.yaml` block for the rule are all deleted in the same change.

The cross-port sibling rule of the same name in gruff-py is retired in lockstep (see `gruff-py/.goat-flow/decisions/ADR-018-retire-naming-parameter-type-name.md`).

## Context

The rule enforced that class-typed parameters and direct `new Type()` locals use the lower-camel form of the short type name (e.g. `BookingSession $session` should be `$bookingSession`). In a strongly typed PHP 8.x codebase the parameter declaration already shows the type at the call site, so the rule's "expected name" was essentially restating the type — exactly the pattern the project's own comment policy warns against.

Concrete signal observed during the 2026-05-25 expansion of `ignoredTypes` / `ignoredParameterNames` defaults:

- A real PHP codebase produced **454 rule findings**.
- Roughly **44 (≈10%)** were universal false positives that the newly-added defaults now silence: 39 date/time semantic-role names (`$now`, `$createdAt`, `$expiresAt`, …) and 5 Throwable conventions (`$e`, `$exception`, `$previous`, `$cause`).
- The remaining **~410 findings** were domain-DTO naming complaints (`BookingSession $session`, `BookingIntent $intent`, `BookingRequestContext $requestContext`, …) which the rule wanted renamed to `$bookingSession` / `$bookingIntent` / `$bookingRequestContext`.

The domain-DTO findings are the rule "doing its job" — but they are also the most contested. Modern PHP teams routinely name domain parameters by role (`$session` is the session-under-consideration in this method's scope) rather than by type (which the IDE and the type-checker already show). Forcing `$bookingSession` adds type-noise without adding meaning.

Other measured pressure:

- The `ignoredTypes` default list grew to **30 entries** (date/time × 7, iterators × 5, collections × 2, IDs × 3, errors × 2, streams × 2, reflection × 4, file I/O × 1, money × 1, framework × 4) and a sibling list `ignoredParameterNames` grew to ~30 universal semantic role names. The exception arms race showed no sign of stopping; every new framework added to the PHP ecosystem creates more candidate exemptions.
- `RuleConfigApplier::options()` merges per-key with **replace** semantics. Users who set `options.ignoredParameterNames` (which the project's own `.gruff-php.yaml` does, with ~60 entries) silently lose the new universal defaults unless they re-list them — a discoverability footgun that compounds with every default change.
- Two earlier learning-loop entries (`patterns/commands.md` "Split scaffold-via-Yaml::dump and rules-via-manual-string", and the new defaults wiring) were entirely scaffolding to make the rule usable. None of that scaffolding earns its keep without the rule.

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| A. Keep adding exceptions (status quo) | Exception list grows monotonically with every framework added to PHP; per-key replace semantics make user customization brittle; the rule's signal stays mixed with domain-opinion-noise. | Rejected. Accumulating exceptions is a smell that the rule's premise is contested. |
| B. Flip to opt-in (`isEnabledByDefault: false`) | Existing adopters lose findings silently; the rule survives only in projects that explicitly opt in. Codebase still carries the rule, fixture, tests, and exception lists for a feature most users won't run. | Rejected. Maintenance burden of the dormant code outweighs the benefit for the few teams that want it. |
| C. Invert the matching contract (`enforcedTypes` / `enforcedNamespaces` allowlist) | New configuration shape required; the rule does nothing until configured; users who would have benefited from a default-on rule never discover the option; existing tests and fixtures need rewrite. | Rejected for now. Cleanest design, but worth re-deriving from scratch if/when a real demand re-emerges (see Reversibility). |
| D. Delete the rule and its scaffolding | Adopters who valued domain-DTO naming consistency lose enforcement; cross-rule references in the priority chain and a few docs need cleanup. | **Accepted.** Smallest persistent surface; revivable as a different design later without locking the project into the current shape. |

## Consequences

- The PHP rule catalogue drops from 12 naming rules to 11 (see `docs/rules.md` after this change).
- The naming-rule priority chain documented in `RuleRegistry::NAMING_RULE_PRIORITY` and `.goat-flow/architecture.md` drops the `parameter-type-name` slot. Adjacent rules (`negative-boolean`, `boolean-prefix`, `identifier-quality`, `hungarian-notation`, `suffix-hungarian`, `short-variable`, `abbreviation-allowlist`) re-number implicitly; the relative order between remaining rules is preserved.
- The project's own `.gruff-php.yaml` loses its `naming.parameter-type-name` block (currently ~70 lines of per-rule tuning). The committed list of project-specific parameter names (`node`, `stmt`, `expr`, AST-walker conventions) is no longer needed anywhere — the rule that consumed it is gone.
- Adopters relying on the rule for domain-DTO naming discipline will see findings disappear after the next `composer update`. Release notes and `CHANGELOG.md` must call this out explicitly.
- The "split scaffold-then-manual-rules" YAML emission pattern documented in `patterns/commands.md` stays — it was generalised to support per-rule descriptions on every rule, not just this one.

## Reversibility

**Two-way door, but with cleanup cost.**

- The rule class, its fixture (`tests/Fixtures/Naming/parameter-type-name.php`), and its test methods are removed from `tests/Rule/Naming/NamingAdvancedRulesTest.php` in this change. Reviving the rule by `git revert` is straightforward; reviving it without a revert requires re-implementing the rule from `git log` plus the prior `defaultOptions` shape captured here:
  - `typeSuffixesToTrim: ['Interface']`
  - `ignoredTypes`: 30 entries (date/time, iterators, collections, IDs, errors, streams, reflection, file I/O, money, framework — see commit prior to this change for the canonical list)
  - `ignoredParameterNames`: ~30 universal semantic-role names plus the project-specific AST-walker list from the deleted `.gruff-php.yaml` block

**Revisit trigger:** if cross-team naming-consistency tooling for domain DTOs becomes a recurring user ask, re-derive a new rule using **Option C** above (inverted matching contract: `enforcedNamespaces: ['App\\Domain\\**']`) rather than reviving the deleted shape. The exception-list maintenance pattern is what failed; the underlying problem (enforcing naming consistency for domain types) is still a legitimate ask.

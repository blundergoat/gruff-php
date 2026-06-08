# ADR-025: Rework `docs.return-comment` to a described-`@return` rule

**Status:** Accepted
**Date:** 2026-06-01
**Author(s):** Matthew Hansen
**Supersedes scope of:** the 2026-05-31 "return-comment restored" update to ADR-006,
which reinstated the blanket "one-line comment directly above every `return`" shape.
ADR-006's deletion of `docs.continue-comment` still stands.

## Context

The 2026-05-31 update to ADR-006 restored `docs.return-comment` as the original blanket
rule: a standalone `//` comment directly above every `return` statement
(`DirectLineComment::hasCommentAbove`). The intent was sound — a return's contract is a
verification surface a reviewer diffs against the code — but the shape contradicts the
project's own comment bar. `code-comments.md` rations inline comments to a non-obvious
WHY and names "restating the code" as an antipattern; a mandatory `//` above every return
is exactly the narration it omits by default. The rule therefore taught adopters to write
the comments the comment bar tells them to delete, and its findings landed inside function
bodies rather than on the contract surface PHPDoc consumers and IDEs already read.

Three documentation rules touch return tags, and the gap between them is the real target:

- `docs.missing-return-tag` (`MissingReturnTagRule`) owns **presence** — a documented
  function-like with no `@return` at all (exempts `__construct` / `__destruct`).
- `docs.bare-phpdoc-tags` (`BarePhpdocTagsRule`) owns **the tags-only docblock** — fires
  only when the whole docblock is tags with no prose summary.
- A value-returning function with a summary line **plus** a bare `@return Type` trips
  neither: presence is satisfied and the docblock is not tags-only. That gap is where the
  contract silently goes undescribed.

## Decision

Rework `docs.return-comment` (keeping the id) so it fires when a **value-returning**
function-like declaration has an `@return` tag that is **present but undescribed**.

Locked semantics:

- **Value-returning** = the declared return type is present and is not `void` or `never`;
  when the declared type is absent, fall back to "has at least one `return <expr>;`".
- **Exempt** `__construct` / `__destruct` exactly as `MissingReturnTagRule` does, plus any
  `void`/`never` return — there is no result to describe.
- **Fire only** when a docblock exists, carries an `@return`, and that `@return` has no
  description. Missing docblock and missing `@return` stay owned by
  `docs.missing-public-phpdoc` / `docs.missing-return-tag`; a wholly-bare docblock stays
  owned by `docs.bare-phpdoc-tags`. No double-reporting.
- **The rule checks for a description, not punctuation.** It reuses
  `BarePhpdocTagsRule`'s depth-aware `hasReturnTagDescription()` (tolerant of spaces inside
  `array<string, int>` generics), extracted into a shared `PhpdocTagText` helper both rules
  call. "Has any prose after the type" is the bar; the `-` separator is a house convention
  applied during conversion, not a rule-enforced character.
- The id stays `docs.return-comment`; severity stays `advisory`; confidence stays `high`;
  the registry slot and rule **count** are unchanged (this is a rework, not an add/remove).
  Only `name`, `description`, and behaviour change.

`DirectLineComment` (used only by this rule) is removed. No rule writes or requires a `//`
above a return after this change.

## Division of labour (must stay disjoint)

| Rule | Owns | Fires when |
| --- | --- | --- |
| `docs.missing-return-tag` | presence of `@return` | documented function-like, no `@return` at all (ctor/dtor exempt) |
| `docs.bare-phpdoc-tags` | the tags-only docblock | whole docblock is tags, no prose summary |
| `docs.return-comment` (reworked) | description of an existing `@return` | value-returning, `@return` present, no description |

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| Keep the blanket "`//` above every return" rule | Teaches the inline narration `code-comments.md` rations against; findings sit in bodies, not on the contract; gruff cannot satisfy it without ceremony. | Rejected. Wrong surface, contradicts the comment bar. |
| Rename the id to `docs.return-description` | Cleaner name, but breaks every config key, baseline entry, `docs/rules.md` row, and registry slot for adopters. | Rejected for now; deferred as a separate breaking decision. |
| Enforce the literal `-` separator in the rule | Brittle; punctuation is a house convention, and a description with a different separator is still a description. | Rejected. Rule checks for a description; the hyphen is convention-only. |
| Rework to "described `@return` for value-returning functions" | Adopters' bare-`@return` findings shift, and the conversion backfills descriptions instead of freezing them in a baseline. | Accepted. Puts the contract on the surface reviewers diff, fills the real gap between the sibling rules, and reuses tested detection. |

## Consequences

- `ReturnCommentRule` iterates `ClassMethod`/`Function_` nodes (like the sibling docs
  rules) instead of `Return_` nodes; `DirectLineComment` and its references are deleted.
- `RuleRegistryTest::testDefaultRuleDefinitionsStayStable` and
  `RuleRegressionSnapshotTest` digests/counts shift with the new `name`/`description` and
  the new finding set; recompute, do not hand-edit. The rule **count** stays 128.
- The codebase is converted repo-wide to the house format
  (`@param <Type> $name - <desc>`, `@return <Type> - <desc>`, blank ` *` line before
  `@return`); any `//`-above-return comments are removed. Comments/docblocks only — no
  executable code changes.
- Adopter baselines may shift as the `docs.return-comment` finding set changes; the id is
  unchanged so existing config keys keep working.

## Reversibility

Two-way door before 1.0. Reverting to the blanket shape requires a new ADR with fresh
evidence, because it reintroduces the inline-narration pressure this decision removes.
Renaming the id remains available as a separate, deliberately-decided breaking change.

---
category: rules
last_reviewed: 2026-05-11
---

# Rule Footguns

## Footgun: Heuristic rules overmatch nested syntax shapes

**Status:** active | **Created:** 2026-05-11 | **Evidence:** OBSERVED

Rule heuristics that search a whole docblock or AST subtree can attribute nested syntax to the wrong owner. `src/Rule/Docs/MissingParamTagRule.php` (search: `extractParamNames`) must parse the parameter variable from the whole `@param` line because generic PHPDoc types can contain spaces, and descriptive docblocks without tags still need to count as method-contract docs. `src/Rule/TestQuality/MockWithoutExpectationRule.php` (search: `isMockCreationExpression`) must decide whether the assigned expression itself creates a mock, not whether any nested constructor argument or helper call creates one.

**Prevention:** Add regression fixtures for the exact syntax shape that caused the false positive or false negative. For PHPDoc rules, include generics or array shapes with spaces before the parameter variable, plus one-line descriptive docblocks that should require full contract tags. For AST ownership rules, include a fake or value object whose constructor receives a mock/stub so nested mock creation does not get assigned to the outer variable.

## Footgun: Legacy rule IDs can outlive rule semantics

**Status:** active | **Created:** 2026-05-11 | **Evidence:** OBSERVED

`src/Rule/Docs/MissingPublicPhpdocRule.php` (search: `docs.missing-public-phpdoc`) keeps the historical rule ID for output compatibility, but the rule now requires local PHPDoc on every method declaration, including public, protected, private, abstract, accessor, magic, helper, reporter, and interface implementation methods. Agents must not infer that only public methods are checked from the ID or class name.

**Prevention:** When changing rule semantics, update `RuleDefinition` names, config comments, docs, tests, and golden fixtures together while preserving rule IDs only when output compatibility requires it. Regression tests should assert representative methods across all visibilities so the compatibility name cannot silently narrow behavior again.

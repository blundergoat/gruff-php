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

## Footgun: Project rules need full project context, not `--changed-only`

**Status:** active | **Created:** 2026-05-11 | **Evidence:** OBSERVED

`design.single-implementor-interface` is a `ProjectRuleInterface` (M31, see `.goat-flow/decisions/ADR-003-project-rule-seam.md`). It can only count implementations and external type-hint usages from the units it actually receives. Under `gruff analyse --diff-vs=<base> --changed-only`, the unit list is the diff's changed files, not the full project. A single-implementor interface whose implementor is in an unchanged file disappears from the project rule's view, so the rule emits zero findings on the diff even though a full-project scan would flag the interface. Observed during M31 dogfood: gruff scanned with `--diff-vs=deploy --changed-only` on healthkit's `feat/64272_voice-olb` branch reported `0` design.single-implementor-interface findings; a full `src` scan on the same branch reported 7.

**Prevention:** When the rule emits zero findings under `--changed-only`, do not treat that as "the diff is clean of this rule." Run a full-project scan (without `--changed-only`) to confirm. For agent review workflows that surface single-implementor-interface findings, the workflow should run the rule against the full project (or at least the project's `src/` tree) and intersect the findings with the diff's changed files afterwards, rather than relying on `--changed-only` to do the intersection. The same caveat applies to any future `ProjectRuleInterface`.

## Footgun: Vendored code under `src/Vendor/` evades the vendor filter

**Status:** active | **Created:** 2026-05-11 | **Evidence:** OBSERVED

`design.single-implementor-interface` (and any future project rule) excludes files whose displayPath starts with `vendor/` by default, matching the Composer convention. Some projects vendor third-party libraries by copying them into `src/Vendor/...` instead of relying on Composer (observed in the healthkit dogfood: `src/Vendor/LayerShifter/...`, `src/Vendor/phpdocx/...`). Those copies live under `src/` and the rule treats them as project code, flagging genuinely external interfaces as if they were internal. Three of seven full-project findings on healthkit were these vendored copies (43% false-positive rate before configuration).

**Prevention:** Document the rule's `additionalExcludedPaths` option (defined in `SingleImplementorInterfaceRule::definition()`'s `defaultOptions`). When dogfooding the rule in a project with vendored copies under `src/`, set `additionalExcludedPaths: ['src/Vendor/']` (or the project-specific convention) in `.gruff.yaml`. Do not extend the rule's hard-coded vendor list with project-specific paths; configuration is the right escape hatch. Mention the option in the rule's remediation text when the false-positive class surfaces in another project.

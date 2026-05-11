---
category: rules
last_reviewed: 2026-05-11
---

# Rule Engine Patterns

## Pattern: Project-level rule seam

**Created:** 2026-05-11 (M31)

**Context:** Most gruff rules implement `RuleInterface` and see one `AnalysisUnit` at a time, which is sufficient for AST-driven heuristics scoped to a single file. Some rules need to reason across the whole project (e.g. "this interface has exactly one concrete implementor"). The deciding factor is whether the answer the rule needs can be derived from one file's AST.

**Approach:** Add the rule to `RuleRegistry::defaults()` like any other rule, but have it implement `ProjectRuleInterface` (`definition()` + `analyseProject(list<AnalysisUnit>, RuleContext): list<Finding>`) instead of `RuleInterface`. `RuleRegistry::analyse` runs all per-unit `RuleInterface` rules first, then calls `analyseProject` once for each enabled `ProjectRuleInterface` rule with the full list of parse-clean PHP units. The two-pass dispatch is one branch inside `RuleRegistry::analyse`; see `src/Rule/ProjectRuleInterface.php` and the design rationale in `.goat-flow/decisions/ADR-003-project-rule-seam.md`.

**Inside the rule:**
- Run `NameResolver` once per unit to populate `resolvedName` attributes on `Name` nodes; PHP-Parser does not run this by default in the gruff parser pipeline (see `src/Parser/PhpFileParser.php`).
- Build a project index in pass 1 (interfaces / classes / type references) keyed by FQN.
- Apply heuristics in pass 2 with full visibility.
- Expose configuration via `RuleDefinition::$defaultOptions` so projects can tune exemptions without disabling the rule (e.g. `externalNamespacePrefixes`, `additionalExcludedPaths`).

**Verification:** Fixture set must cover every shape of true positive and true negative: the interface is external (PSR / Symfony / vendor), framework-tagged (attribute-decorated), single-implementor (true positive), multi-implementor (true negative), part of a hierarchy with another interface as parent (true negative), and the project-rule-specific edge case of mock-only test usage (decided by config). See `tests/Fixtures/Design/single-implementor-interface/` for the canonical layout.

**Caveat:** `--diff-vs=<base> --changed-only` reduces the unit list to changed files only. A project rule then sees an incomplete project and may emit zero findings even when a full-project scan would flag the same code. See the rules footgun "Project rules need full project context, not `--changed-only`" for the workflow consequence.

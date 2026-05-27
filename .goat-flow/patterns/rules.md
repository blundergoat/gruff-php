---
category: rules
last_reviewed: 2026-05-27
---

# Rule Engine Patterns

## Pattern: Remediation text shape — fix sentence plus config-hatch sentence

**Created:** 2026-05-27 (M07)

**Context:** Every rule has an escape hatch in `defaultOptions` for the cases where the rule's heuristic over-fires on intentional code. The default options live in the rule's `definition()` and the YAML config under `rules.<rule-id>.options.*` or, for cross-rule allowlists, `allowlists.*`. Users hitting a finding need both the in-source fix and the config-level override; without the config hint they often disable the rule entirely or accumulate baselines instead of telling gruff "this case is intentional".

**Approach:** When a rule has a discoverable escape-hatch knob, structure its `remediation` field as two sentences:

1. **Fix sentence.** What to do in the code (rename, inline, narrow the type, replace the magic literal, etc.). The text should make sense without the second sentence so users who don't want to touch config can act on it alone.
2. **Config-hatch sentence.** "If this `<name|identifier|pattern|literal>` is intentional, add it to ``rules.<id>.options.<key>`` in `.gruff-php.yaml`." Use backticks around the literal YAML path. For global allowlists (`naming.abbreviation-allowlist` → `allowlists.acceptedAbbreviations`) use the full global path instead of the per-rule path.

**Examples** (`src/Rule/Waste/OneLineMethodRule.php`):
- "Inline the expression at the call site or expand the method so it owns a meaningful contract. If this method is an intentional API contract, add its qualified symbol to `rules.waste.one-line-method.options.allowedSymbols` in `.gruff-php.yaml`."

**Constraints:**
- Two sentences maximum. The field is for human-readable guidance, not embedded fix commands.
- The named config key must already exist in the rule's `defaultOptions` (or be a global `allowlists.*` path). Don't surface a non-existent knob.
- Don't enumerate what to add (specific values are project-specific). Just name the path.
- If the rule has NO escape-hatch knob, skip the second sentence — better to be honest than to invent a config key.

**Why two sentences:** structured remediation metadata on `Finding` (`fixKind`, `fixCommand`, machine-readable suggestions) was considered and deferred — the text-only enrichment lands the value without changing the JSON schema. If codemod tooling needs structured data later, the second sentence becomes the seed for a `configKey` metadata field; until then it sits in the human-readable string and is consumed by every reporter unchanged.

**Verification:** `php bin/gruff-php analyse src --no-config --format=json --fail-on=none | jq '.findings[] | select(.remediation | contains("`.gruff-php.yaml`")) | .ruleId' | sort | uniq -c` should list every rule that has an escape hatch with at least one finding. Rules without knobs (size/complexity rules, security rules with no opt-out path) will not appear and that is correct.

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

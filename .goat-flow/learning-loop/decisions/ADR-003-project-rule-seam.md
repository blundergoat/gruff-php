# ADR-003: Project-level rule seam

**Status:** Accepted
**Date:** 2026-05-11
**Ticket/Context:** M31 phase 3 (`design.single-implementor-interface`)

## Context

Until M31 every gruff rule has implemented `RuleInterface::analyse(AnalysisUnit $unit, RuleContext $context): array` and seen exactly one `AnalysisUnit` at a time. `RuleRegistry::analyse(array $units, RuleContext $context)` invokes each enabled rule once per unit and concatenates the results (see `src/Rule/RuleRegistry.php` `analyse()`).

`design.single-implementor-interface` (M31 phase 3) cannot work that way. To decide whether an interface is worth keeping it must know how many concrete classes implement that interface **across the whole project**, and whether the interface's name is referenced as a type-hint anywhere outside its implementor's namespace. Both questions are cross-file.

Two seam shapes were considered:

### Option A - `ProjectRuleInterface` with `analyseProject(units, context)`

Introduce a second rule interface:

```php
interface ProjectRuleInterface
{
    public function definition(): RuleDefinition;

    /**
     * @param list<AnalysisUnit> $units
     * @return list<Finding>
     */
    public function analyseProject(array $units, RuleContext $context): array;
}
```

`RuleRegistry::analyse` is updated to dispatch each enabled rule based on its interface: per-unit rules continue to receive one unit at a time, project rules receive the full list after the per-unit loop completes.

Pros:
- Clean separation of concerns - a rule's `analyseProject` body cannot pretend it is per-unit.
- Existing `RuleInterface` rules are untouched.
- Simple to test - a project rule can be constructed and invoked directly with a list of units.

Cons:
- Adds a second rule shape that registry, config loader, and `list-rules` may have to special-case in places not yet enumerated.
- A rule that wants to use both per-unit fast-path detection and project-wide reasoning needs to implement both interfaces or pick one.

### Option B - Project index built into `RuleContext`

`RuleRegistry::analyse` performs a single project-wide pre-pass before the per-unit loop, collecting:

```
ProjectIndex {
  interfaces:        list<{ fqn, isAbstract, extends, file, line }>
  classes:           list<{ fqn, implements, extends, file, line }>
  typeReferences:    list<{ targetFqn, file, line }>  // from param/return/property type hints
}
```

The index is attached to `RuleContext` as a non-null public property. Per-unit rules ignore it; the new rule reads `$context->projectIndex->implementationsOf($interfaceFqn)`.

Pros:
- One rule shape. The new rule looks structurally identical to existing ones.
- Pre-pass cost is amortised across all rules that may need it.

Cons:
- `RuleContext` grows from "config + paths" to "config + paths + project state." Every test that constructs a `RuleContext` directly now has to provide a project index (or accept that an empty one is the default).
- The pre-pass walks every AST node, doubling parse-walk work for the project even when no project rule is enabled.

## Decision

**Option A: `ProjectRuleInterface` with `analyseProject(units, context)`.**

The deciding factor is the cons-list weight:

- Option B inflates `RuleContext` (the common case) for the benefit of an uncommon case (one rule today, possibly more later). Every test that constructs a context pays a cost.
- Option B's pre-pass cost is paid even when the project rule is disabled, unless the registry conditionally builds the index, which re-introduces the same dispatch logic Option A makes explicit.
- Option A keeps the per-unit rule contract identical, so the diff to existing rules and existing tests is zero. The dispatch change is one branch inside `RuleRegistry::analyse`.

Concretely, M31 adds:

1. `src/Rule/ProjectRuleInterface.php` declaring `analyseProject(array $units, RuleContext $context): array`.
2. `RuleRegistry::analyse()` runs the per-unit loop first, then iterates project rules (if any are enabled) once over the full unit list, appending their findings to the same array before deduplication.
3. The new rule implements only `ProjectRuleInterface`, not `RuleInterface`.

## Failure Mode Comparison

| Option | Failure mode | Verdict |
| --- | --- | --- |
| A - ProjectRuleInterface | Registry must special-case dispatch in a few places (mostly `analyse`); if forgotten, a project rule silently never runs | Mitigated by registry-level tests that assert both rule shapes execute |
| A - ProjectRuleInterface | A rule author who needs both per-unit and project reasoning implements both interfaces | Acceptable; the existing per-unit interface remains the default |
| B - RuleContext project index | Pre-pass runs even when no project rule is enabled | Rejected for the cost-when-unused argument |
| B - RuleContext project index | Test ergonomics regress: every test constructing a `RuleContext` must initialise the index (or accept a default empty one), but the default may hide bugs | Rejected for test-ergonomics drift |
| Both | A rule that wants project context but emits findings per-unit must still join the data manually | Out of scope for v0.1; revisit when a second project rule lands |

## Reversibility

Two-way door. The two seam shapes are interchangeable from outside the rule engine - both produce findings on the same identifiers. Switching from A to B is a registry refactor and a context-shape change; the rule code itself only changes how it acquires the index.

If a future project rule needs to share substantial work with per-unit rules and the duplication of walks becomes measurable, this ADR will be superseded by one that hoists the shared work into the context.

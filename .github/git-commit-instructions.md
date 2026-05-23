# Git commit message rules

Every commit message in this repository follows the same format. These
rules govern message content only - staging, checks, and process live
elsewhere.

## Required shape

```
<Imperative title>

<1-3 lines summarising the gist of the change>
```

- Subject line, blank separator line, then a body of 1-3 lines.
- Total length 3-5 lines. Never just a subject. Never more than 5.
- Plain text only. No footers, co-author tags, emoji, or AI signatures.

A body MAY be omitted when **all** of these hold: the change is genuinely
one observable thing, the subject already names what and where, and the
motivation is self-evident from the diff. In every other case a body is
required.

## Subject-line rules

- ≤ 70 characters, imperative mood, no trailing period.
- Pattern: `<Area> - <Action>` or `<Action> in <Area>`. Area is usually a
  rule name, command, or subsystem (`ParameterTypeNameRule`, `analyse CLI`,
  `dashboard scan`).
- **One observable change per subject.** If the subject contains "and",
  names two axes, or starts to read like a release-note paragraph - either
  split the commit or move the second axis into a bulleted body.

### Weak verbs (avoid)

These paraphrase the diff without naming the real change:

> *enhance, improve, streamline, clarify, update, tweak, polish, refactor
> (when used as a verb of last resort), handle, support, work with*

### Concrete verbs (prefer)

Name the actual change:

> *add, remove, replace, rename, fix, restore, deny, allow, gate, skip,
> wire, harden, cache, invalidate, log, retry, tighten, loosen, lock,
> migrate, split, merge*

## Body rules

- **What and why, not how.** A reader should understand the scope and
  motivation without opening the diff. Skip implementation detail unless
  it is surprising or load-bearing (a non-obvious dependency, a workaround
  for a known footgun, a regression source).
- **Name sources when locking policy or reversing a decision.** Cite the
  file (`.goat-flow/lessons/...`, an ADR), the prior commit hash, or the
  rule ID being adjusted.
- **Bullets are allowed in the body** when the change touches more than
  one axis - one bullet per axis. Bulleted bodies still count toward the
  3-5 line total; if you need more bullets, split the commit.

## Bad → Good rewrites

```
BAD:  Enhance VarAnnotationDescriptionRule to handle attributes
GOOD: Switch VarAnnotationDescriptionRule from token walker to AST
```

```
BAD:  Improve parameter-type-name rule and tweak fixture
GOOD: Add ignoredParameterNames option to naming.parameter-type-name
```

```
BAD:  Update tests for various rules
GOOD: Add tests for attribute-decorated properties and methods
```

```
BAD:  Refactor MissingReturnTagRule and update fixtures and docs
GOOD: Lock docs.missing-return-tag contract to void and never methods
```

## Examples

### Good

```
Switch VarAnnotationDescriptionRule from token walker to AST

The token walker mistook the next token after the docblock for a
non-declaration when an attribute sat between docblock and property,
producing a false positive on every attribute-decorated declaration.
The AST walker keys off Stmt\Property and ClassMethod directly.
```

```
Tighten RedundantVariableRule to exact assign+return pairs

Drops the looser 3-statement pattern that flagged variables used
once before the return. Behaviour now matches the test fixture's
intentional negatives and removes the dogfood false positives.
```

```
Add ignoredParameterNames option to naming.parameter-type-name

AST-walker parameters like $node, $context, $stmt trip the rule
even when the type makes the convention obvious. Default empty
list; gruff opts in to its own AST vocabulary in .gruff-php.yaml.
```

```
Lock docs.missing-return-tag contract to void and never methods

Adds fixture and assertion for the post-M31 behaviour. Cites
lessons/workflow.md "Respect explicit rule style" so a future
agent cannot re-narrow the rule.
```

### Bad

Subject only when the change is non-trivial - fails the 3-5 line rule:
```
Add tests for VarAnnotationDescriptionRule
```

Vague, no scope or motivation:
```
fix bug
```

Multi-axis "and" subject - either split or move the second axis to a body
bullet:
```
Update VarAnnotationDescriptionRule and rename ParameterTypeNameRule fixture
```

Run-on with implementation detail no reader needs:
```
Switch VarAnnotationDescriptionRule from token walker to AST

Drops isLocalVarAssertion, isTrivia, isDeclarationToken; walks
Stmt\Property and ClassMethod via NodeFinder; skips declaration
nodes whose docComment contains @var; preserves the local-assertion
path inside method bodies; adds fixture coverage for the attribute
decorated property and method shapes.
```

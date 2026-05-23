# ADR-004 - Public method PHPDoc templates

**Status:** accepted
**Date:** 2026-05-12
**Supersedes:** -
**Superseded by:** -

## Context

gruff's own `src/` carried 658 `docs.missing-public-phpdoc` findings entering M33 (severity error). The rule is the largest contributor to the run's 674 error-severity findings; the remaining 16 are 13 complexity + 3 size, addressed in M36. M33 must drive the rule to zero across `src/` without re-triggering adjacent docs rules.

The adjacent rules and their cascade behaviour against newly-added docblocks:

- **`docs.bare-phpdoc-tags`** - fires when a docblock contains ONLY bare `@param` / `@return` tags with no purpose line or tag descriptions. Suppressed by any descriptive (non-tag) line or by prose after a parameter/return tag.
- **`docs.missing-return-tag`** - fires when a documented method's docblock omits `@return`. Override-aware via `DocsInheritanceHelper`. Constructors and destructors are exempt by `isReturnlessMagicMethod`.
- **`docs.missing-param-tag`** - fires when a documented PUBLIC method has parameters but the docblock omits `@param` tags for them. Non-public methods are exempt. Requires `hasContractDoc` (prose OR any docs tag) to fire.
- **`docs.missing-throws-tag`** - fires when a documented public method's body contains `Throw_` AST nodes but the docblock lacks `@throws`. Override-aware.

This ADR captures the per-archetype template that satisfies `docs.missing-public-phpdoc` without re-firing the bare-PHPDoc rule, while keeping `@param` / `@throws` work scoped to M35.

## Decision

Every public method gets a docblock matching one of seven archetype templates. Templates always include a one-line purpose statement; non-void return types get a `@return` line. Parameter and throws tags are M35's scope and are NOT added in M33.

M34 extends the same principle to structural PHPDoc. In this codebase every `src/*.php` file currently has one declared type, so the default structural policy is: add a meaningful class/interface/enum docblock to the declared type instead of adding a separate file-level docblock. `docs.missing-file-phpdoc` treats a single documented class-like declaration as sufficient file documentation, so duplicating both file and class docblocks would add noise.

### Matcher contract (locked)

- `docs.missing-public-phpdoc` is satisfied by ANY non-null `getDocComment()`. Even a single-line `/** Build the X. */` suffices.
- `docs.bare-phpdoc-tags` is suppressed by ANY descriptive (non-`@`-starting) line or a tag description. A docblock with one prose line plus `@return Type Description.` is safe.
- `docs.missing-return-tag` is suppressed by ANY `@return` substring in the docblock text. Override-aware.
- `docs.missing-param-tag` checks documented public methods and functions with parameters. It requires an `@param` line whose final `$name` token matches each signature parameter.
- `docs.missing-throws-tag` checks documented public methods and functions whose body contains a `throw` expression. It is satisfied by any `@throws` line and skips inherited contract documentation.
- `docs.var-annotation-description` checks local `@var` assertions only. Declaration docblocks are skipped; a local assertion must either carry prose after the variable name or have a separate descriptive line in the same docblock.

### Templates

**1. Symfony command (`src/Command/*Command.php`)**

```
/**
 * Run the <noun-phrase>.
 *
 * @return int Command exit code.
 */
public function execute(InputInterface $input, OutputInterface $output): int
```

```
/**
 * Declare the <subcommand>'s arguments and options.
 */
protected function configure(): void
```

`configure()` is `void`, so no `@return` is needed.

**2. AST visitor / rule analyse (`src/Rule/<pillar>/*Rule.php`)**

```
/**
 * Detect <one-line description of what this rule flags>.
 *
 * @return list<Finding>
 */
public function analyse(AnalysisUnit $unit, RuleContext $context): array
```

```
/**
 * Describe the rule for the registry and reports.
 *
 * @return RuleDefinition
 */
public function definition(): RuleDefinition
```

**3. Reporter (`src/Reporting/*Reporter.php`)**

```
/**
 * Render <format> output for the given findings.
 *
 * @return string The rendered <format> document.
 */
public function render(...): string
```

`HtmlReporter::write*`/`open*` partial-render helpers get a one-line purpose statement only.

**4. Config loader / applier (`src/Config/*.php`)**

```
/**
 * Read the project's `.gruff-php.yaml` and produce an AnalysisConfig.
 *
 * @return AnalysisConfig
 */
public function load(?string $path): AnalysisConfig
```

**5. Value object / option DTO (`src/Command/*Options.php`, `src/Finding/Finding.php`)**

Constructors only: `/** Build the <noun>. */`. No `@param` / `@return`. Promoted-property docblocks belong to M34 (structural PHPDoc).

**6. Rule registry helper / utility class (`src/Rule/<pillar>/*Helper.php`)**

Each public method gets a one-line purpose statement. If the return type is non-void, add `@return Type Description.` (single line).

```
/**
 * Resolve the enclosing test class name for the given node.
 *
 * @return string|null The class name, or null when no test class encloses the node.
 */
public function enclosingTestClass(Node $node): ?string
```

**7. Plain helper class (everything else: `src/Source/`, `src/Analysis/`, `src/Parser/`, `src/Diff/`, `src/Mutation/`, `src/Trend/`, `src/Scoring/`, `src/Baseline/`, `src/Review/`)**

One-line purpose statement; `@return` if non-void. No `@param` / `@throws`.

```
/**
 * Walk the project tree and yield PHP source files.
 *
 * @return iterable<SourceFile>
 */
public function discover(string $root, AnalysisConfig $config): iterable
```

### M34 structural templates

**8. Declared type docblock (class / interface / enum / trait)**

Use one sentence that identifies the type's role in the package. This is the normal replacement for a file-level docblock in single-type files.

```
/**
 * Captures validated CLI options for an analyse run.
 */
final readonly class AnalyseCommandOptions
```

Counter-example: `/** AnalyseCommandOptions class. */`

**9. File-level docblock**

Use only when a file has multiple declared types or no declared type. Place it after `<?php` and before `declare(strict_types=1);`.

```
/**
 * Shared parser fixtures used by the source-loading tests.
 */
declare(strict_types=1);
```

Counter-example: `/** This file contains PHP code. */`

**10. Declared property docblock**

Use for non-promoted properties only. Describe the state the property carries or why it is cached. Do not add promoted-property `@param` tags in M34; M35 owns those.

```
/**
 * Parser instance reused across files in a parsing pass.
 */
private Parser $parser;
```

Counter-example: `/** @var Parser */`

**11. Class constant docblock**

Describe the role of the constant in configuration, matching, or reporting. Avoid restating the literal value.

```
/**
 * Default config file name discovered from project roots.
 */
public const DEFAULT_CONFIG_FILE = '.gruff-php.yaml';
```

Counter-example: `/** The default config file. */`

**12. Enum docblock and case docblocks**

Use an enum docblock for the enum's shared semantic axis. Add case docblocks when the case names are part of public report semantics or quality-gate behavior.

```
/**
 * Represents the level at which a finding should affect feedback and exits.
 */
enum Severity: string
{
    /**
     * Issue serious enough to fail warning-level quality gates.
     */
    case Warning = 'warning';
}
```

Counter-example: `/** Warning severity. */`

### M35 tag templates

**13. `@param` tag**

Use the most specific PHPStan-safe type already implied by the signature or existing PHPDoc. Add a short contract description after the parameter name.

```
/**
 * Load source files for an analyse run.
 *
 * @param list<string> $paths Project-relative paths requested by the CLI.
 * @return AnalysisSourceSet Parsed units and discovery diagnostics.
 */
public function load(array $paths): AnalysisSourceSet
```

Counter-example: `@param string $path string`

**14. `@return` tag**

Keep the native return type and describe the shape or meaning of the returned value. Constructors and destructors are exempt.

```
/**
 * Group findings by their source file.
 *
 * @return array<string, list<Finding>> Findings keyed by display path.
 */
private function findingsByFile(array $findings): array
```

Counter-example: `@return array`

**15. `@throws` tag**

Name the exception actually thrown by the method body, and describe the condition that triggers it. Do not document speculative exceptions from future refactors.

```
/**
 * Read and validate the configured YAML file.
 *
 * @throws ConfigException When the config file cannot be read or decoded.
 * @return AnalysisConfig Parsed analyser configuration.
 */
public function load(?string $path): AnalysisConfig
```

Counter-example: `@throws Exception`

**16. Local `@var` assertion**

Prefer deleting a redundant local assertion when PHPStan remains green without it. Keep the assertion only when it narrows parser, JSON, iterable, or dynamic-call output in a way native types cannot express, and explain why it is load-bearing.

```
/** @var list<ClassMethod|Function_> $nodes NodeFinder returns Node instances; this narrows the callback-filtered list. */
$nodes = $finder->find($unit->statements, static fn (Node $node): bool => $node instanceof ClassMethod || $node instanceof Function_);
```

Counter-example: `/** @var list<Node> $nodes */`

### Rules of thumb

- **Never restate the signature as the only documentation.** `/** Get the name. */` for `getName(): string` is too thin. Describe the *intent*: `/** Return the rule's stable identifier. */`.
- **Never use `{@inheritDoc}` alone** for non-void methods - it satisfies `docs.missing-public-phpdoc`, but `docs.missing-return-tag` fires because it doesn't contain `@return`. Either inherit from a documented interface (the override-aware path) or add a local `@return` line.
- **Magic methods** (`__construct`, `__destruct`, `__toString`, etc.) follow archetype 5 (value object). Constructors get `/** Build the <noun>. */`.
- **Static factory methods** (`fromArray`, `fromRegistry`) get `/** Build a <noun> from <source>. */ + @return Self.`.
- **Closure / arrow function bodies** are not method docblocks - the rule only fires on `ClassMethod` nodes.
- **Local `@var` assertions are guilty until proven load-bearing.** If PHPStan is green after deletion, delete the assertion; if not, keep it with a reason.

## Consequences

- M33 lands the seven templates across ~218 files. `docs.missing-public-phpdoc` drops from 658 to 0.
- M34 adds structural templates 8-12. In the 2026-05-12 pre-M34 scan, structural findings are: 217 missing file, 217 missing class, 5 declared-property, 201 missing constant, plus 208 promoted-property `@param` gaps deferred to M35.
- `docs.missing-param-tag` and `docs.missing-throws-tag` will rise because the new docblocks satisfy `hasContractDoc` but omit those tags. M35 owns the bulk tag sweep and closes the new advisories. Expected magnitudes: `+400` `missing-param-tag` (most methods have params); `+50-100` `missing-throws-tag` (only methods that throw); both stay in the advisory bucket so error-fail-on builds are unaffected.
- `docs.missing-return-tag` stays roughly stable because every non-void method gets an explicit `@return Type Description.` line.
- The bare-PHPDoc rule stays stable because every template includes at least one descriptive line.
- The M33 plan's "any non-target rule's finding count increases by more than 1% net" kill criterion is **explicitly waived** for `docs.missing-param-tag` and `docs.missing-throws-tag` - those rises are the unavoidable consequence of adding stub docblocks without M35's tag sweep. The criterion remains in force for the bare-PHPDoc rule, `modernisation.phpdoc-mixed-overuse`, and all non-docs rules.
- M34 (structural PHPDoc on classes, properties, constants) reuses archetypes 1, 4, 5, 7 verbatim. The "what does this class do?" one-liner mirrors the method "what does this method do?" one-liner.
- M35 (tag completeness) fills in `@param`, `@throws`, and `@return` (where missing) using descriptions consistent with the one-line purpose statements landed by M33 and M34. The refreshed post-M34 baseline is: 619 `docs.missing-param-tag`, 0 `docs.missing-return-tag`, 17 `docs.missing-throws-tag`, 54 `docs.var-annotation-description`, and 208 promoted-property constructor `@param` gaps.

## Alternatives considered

- **Full M33 + M35 combined (one-shot tag sweep alongside docblock addition).** Rejected - doubles M33's scope; risks running out of session budget; M35's tag completeness has its own quality bar that deserves dedicated review.
- **`/** {@inheritDoc} */` for every method.** Rejected - non-void methods still fire `docs.missing-return-tag` because the matcher checks for the `@return` substring, not the inherit-doc semantics. Override-aware rules use the `DocsInheritanceHelper` AST walk, which `{@inheritDoc}` doesn't trigger by itself.
- **Disable / downgrade `docs.missing-public-phpdoc` instead of writing docblocks.** Rejected - the rule's high error severity is intentional; the cleanup is the point.

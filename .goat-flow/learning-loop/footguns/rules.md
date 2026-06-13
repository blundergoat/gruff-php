---
category: rules
last_reviewed: 2026-06-14
---

# Rule Footguns

## Footgun: Statement-type additions must extend `StmtChildVisitor`, not individual rules

**Status:** active | **Created:** 2026-05-19 | **Evidence:** OBSERVED

Five rules (`NestingDepthRule`, `NpathComplexityRule`, `CognitiveComplexityRule`, `RedundantVariableRule`, `UnreachableCodeRule`) share `src/Rule/StmtChildVisitor.php` (search: `childBlocks`) for child-block enumeration. If PHP adds a new control-flow construct (a future statement-form of `match`, an `using`-style block, etc.) and the helper is not updated, all five rules silently miss the new shape — their per-kind logic just never runs on the unknown statement type. The pre-consolidation pattern was to copy a 4-block `instanceof` chain into each rule; the helper exists precisely so that mistake can't be made one rule at a time.

**Evidence:** `src/Rule/StmtChildVisitor.php` (search: `isControlFlowStmt`) — the supported statement-type set is fixed in one place. `tests/Rule/StmtChildVisitorTest.php` (search: `testControlFlowStatementIsRecognised`) asserts the set, so adding a new statement type without updating the helper fails the test.

**Prevention:** When a new control-flow statement type lands in this codebase, extend `StmtChildVisitor::isControlFlowStmt` and `StmtChildVisitor::childBlocks`, then add a kind constant on `StmtChildBlock` and the matching dispatch in any rule that needs per-kind math. Never re-introduce a per-rule `instanceof Stmt\X || Stmt\Y || ...` chain — the duplication that the helper exists to prevent.

## Footgun: Heuristic rules overmatch nested syntax shapes

**Status:** active | **Created:** 2026-05-11 | **Evidence:** OBSERVED

Rule heuristics that search a whole docblock or AST subtree can attribute nested syntax to the wrong owner. `src/Rule/Docs/MissingParamTagRule.php` (search: `extractParamNames`) must parse the parameter variable from the whole `@param` line because generic PHPDoc types can contain spaces, and descriptive docblocks without tags still need to count as method-contract docs. `src/Rule/TestQuality/MockWithoutExpectationRule.php` (search: `isMockCreationExpression`) must decide whether the assigned expression itself creates a mock, not whether any nested constructor argument or helper call creates one.

**Prevention:** Add regression fixtures for the exact syntax shape that caused the false positive or false negative. For PHPDoc rules, include generics or array shapes with spaces before the parameter variable, plus one-line descriptive docblocks that should require full contract tags. For AST ownership rules, include a fake or value object whose constructor receives a mock/stub so nested mock creation does not get assigned to the outer variable.

## Footgun: Legacy rule IDs can outlive rule semantics

**Status:** active | **Created:** 2026-05-11 | **Evidence:** OBSERVED

`src/Rule/Docs/MissingPublicPhpdocRule.php` (search: `docs.missing-public-phpdoc`) keeps the historical rule ID for output compatibility, but the rule now requires local PHPDoc on every method declaration, including public, protected, private, abstract, accessor, magic, helper, reporter, and interface implementation methods. Agents must not infer that only public methods are checked from the ID or class name.

**Prevention:** When changing rule semantics, update `RuleDefinition` names, config comments, docs, tests, and golden fixtures together while preserving rule IDs only when output compatibility requires it. Regression tests should assert representative methods across all visibilities so the compatibility name cannot silently narrow behavior again.

## Footgun: Conservative member scopes can hide concrete public waste

**Status:** active | **Created:** 2026-05-11 | **Evidence:** OBSERVED

`src/Rule/Waste/UnusedParameterRule.php` (search: `analysableNodes`) originally checked standalone functions and private methods only, so a concrete public method like an external healthcare target's `VoiceTraceLogger::trace()` could carry an ignored `$detailed` parameter without any `waste.unused-parameter` finding. The same rule also counted direct `unset($detailed)` as a parameter use even though it is only a placeholder/silencer, not a read of the argument.

**Prevention:** For waste/dead-code rules, make the conservative boundary explicit in tests and include at least one locally owned public method fixture plus one contract/override fixture. Treat direct `unset($param)` as non-use when the rule's question is "does the method actually consume this argument?" and use finding columns or metadata when multiple same-line findings can otherwise share a fingerprint.

## Footgun: Constructor-promoted properties bypass property-node scans

**Status:** active | **Created:** 2026-05-11 | **Evidence:** OBSERVED

Constructor-promoted properties are represented as `Node\Param` entries with visibility flags, not as `Stmt\Property` declarations. `src/Rule/DeadCode/UnusedPrivatePropertyRule.php` (search: `privateProperties`) originally collected only `Stmt\Property`, so promoted private readonly fields such as an external healthcare target's `VoiceTraceLogger::$appConfigHelper` and `VoiceTraceLogger::$twilioParams` were invisible to the "written but never read" check.

**Prevention:** Any rule that scans properties must include promoted constructor parameters in fixtures and treat private promoted params as property declarations with an initial write. Also include a used promoted private property and a public promoted property fixture so the rule proves both detection and visibility boundaries.

## Footgun: naming.boolean-prefix requires the prefix at the start of the identifier

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED

`src/Rule/Naming/BooleanPrefixRule.php` (search: `allowedPrefixes`) checks that bool-returning methods, bool parameters, and bool properties *begin* with one of the configured prefixes (default `is`, `has`, `can`, `should`, `will`). Names that merely contain a prefix later in the identifier still fail. `ConfigLoader::projectHasConfig()` was flagged on its first cut because `has` appeared in the middle of the name; renaming to `hasProjectConfig()` cleared the rule. The same trap fires for parameters: `$shouldForce` passes, `$force` and `$forceShould` both fail.

**Prevention:** When adding any bool-returning method, bool parameter, or bool property, put the prefix first. For names that read naturally with the subject before the verb (`projectHasConfig`, `userIsActive`), rephrase as prefix-first (`hasProjectConfig`, `isActiveUser`) or rename the subject out (`hasConfig` on a class already scoped to a project). The rule does not parse English — "name contains a prefix" is not enough; the leading token must be a configured prefix.

## Footgun: Vendored code under `src/Vendor/` evades the vendor filter

**Status:** active | **Created:** 2026-05-11 | **Evidence:** OBSERVED

`design.single-implementor-interface` (and any future project rule) excludes files whose displayPath starts with `vendor/` by default, matching the Composer convention. Some projects vendor third-party libraries by copying them into `src/Vendor/...` instead of relying on Composer (observed in external dogfood: `src/Vendor/LayerShifter/...`, `src/Vendor/phpdocx/...`). Those copies live under `src/` and the rule treats them as project code, flagging genuinely external interfaces as if they were internal. Three of seven full-project findings in that target were these vendored copies (43% false-positive rate before configuration).

**Prevention:** Document the rule's `additionalExcludedPaths` option (defined in `SingleImplementorInterfaceRule::definition()`'s `defaultOptions`). When dogfooding the rule in a project with vendored copies under `src/`, set `additionalExcludedPaths: ['src/Vendor/']` (or the project-specific convention) in `.gruff-php.yaml`. Do not extend the rule's hard-coded vendor list with project-specific paths; configuration is the right escape hatch. `src/Rule/Design/SingleImplementorInterfaceRule.php` (search: `additionalExcludedPaths when the interface comes from copied vendor/framework code`) now includes the option in remediation text so users see the intended mitigation at the finding site.

## Footgun: Retiring a rule leaves stale count references in five doc artefacts

**Status:** active | **Created:** 2026-05-25 | **Evidence:** OBSERVED

The rule registry's true count lives only in `src/Rule/RuleRegistry.php` (the `NAMING_RULE_PRIORITY` constant plus the public registration block), but human-readable counts of the same facts are stamped in five other artefacts that don't auto-update. The five stamp locations are:

```text
README.md                       — quality-table line ("Rule catalogue")
README.md                       — per-pillar tally row    | `naming` | N |
docs/rules.md                   — same pillar tally       | `naming` | N |
docs/rules.md                   — per-pillar section heading  ### `naming` (N)
.goat-flow/architecture.md      — prose count ("exposes N rule ids")
```

PR #6 retired `naming.parameter-type-name`, dropping the registry count from 120 → 119 and the naming-pillar count from 12 → 11. The PR updated the `docs/rules.md` pillar tally but missed the other four stamps. CodeRabbit's outside-diff sweep caught two of the four (the `docs/rules.md` section heading and the `architecture.md` prose); the two README stamps weren't in the PR's touched-file set so neither AI reviewer surfaced them.

**Prevention:** When retiring or adding a rule, after editing `src/Rule/RuleRegistry.php` run a sweep over the five stamp locations above. Greppable form:

```bash
grep -rn 'exposes [0-9]* rule\|Rule catalogue\|^|`naming`\|^### `naming` (' \
    README.md docs/rules.md .goat-flow/architecture.md
```

Update every hit before claiming retirement done; do not rely on a single PR review to surface all of them — outside-diff coverage is bounded by which files the PR touches.

## Footgun: PHPStan/Psalm array-shape exemptions need a "concrete sibling" gate, not "any nested mixed"

**Status:** active | **Created:** 2026-05-27 | **Evidence:** OBSERVED

`src/Rule/Modernisation/PhpDocMixedOveruseRule.php` (search: `isPreciseArrayShape`) exempts `array{...}` shapes that name at least one sibling field with a non-mixed type, on the basis that the nested `mixed` describes a heterogeneous leaf inside a typed envelope. The naive form of this rule — "any nested mixed inside any parametric type is fine" — silently exempts `array<string|int, mixed>` (mixed-keyed bag), `Collection<mixed>` (single-leaf generic), and `array{value: mixed}` (single-mixed-field shape), all of which are genuine type sloppiness the rule should keep flagging. The discriminator is "is there at least one CONCRETE sibling field?"; without it the exemption swallows real signal.

**Evidence:** External reviewer report section 7 (`.goat-flow/scratchpad/gruff-php-improvement-feedback.md`). The reviewer's original phrasing was "nested mixed inside any parametric type should be fine"; applied literally that exempts `Collection<mixed>` which is clearly not a precise envelope. The implemented rule reads the array-shape body, splits on top-level commas (depth-aware via `splitTopLevelComma`), finds the first top-level colon per pair (depth-aware via `topLevelColonIndex`), and returns true only when at least one pair's value type is NOT exactly `mixed` (case-insensitive). Fixtures at `tests/Fixtures/Modernisation/phpdoc-mixed-overuse.php` `preciseArrayShape*` cover both directions.

**Prevention:** When extending a type-shape exemption beyond a single canonical form, write the counter-fixture first. Every "loose" shape (mixed-keyed bag, single-mixed-field shape, mixed-only generic) gets a `*StillFires` fixture method that asserts the exemption did NOT swallow it. Only after the counter-fixtures are in place add the positive `*IsAllowed` cases. The shape-detector must use a depth-aware splitter (commas inside `<>{}()[]` belong to the inner shape, not the outer one); a naive `explode(',', ...)` would split `array{entries: list<array<string, mixed>>, total: int}` mid-list and corrupt the parse.

## Footgun: PHPStan rejects prose attached to multiline array-shape tags

**Status:** active | **Created:** 2026-06-01 | **Evidence:** OBSERVED

`docs.return-comment` only needs to know whether an `@return` tag has prose after the type, and
`src/Rule/Docs/PhpdocTagText.php` (search: `returnTagBody`) now reads multiline array-shape tags
through their closing line. That made comments such as `@return array{ ... } - description` clear
the gruff rule, but PHPStan treats the whole structural tag body as type syntax; prose after the
closing `}` produced `phpDoc.parseError` in `src/Command/ListRulesCommand.php` (search:
`ruleDetailPayload`) and `src/Source/SourceDiscovery.php` (search: `buildGitDiscoveryRequest`),
which then cascaded into `missingType.iterableValue` and `argument.type` errors.

**Prevention:** For multiline precise array shapes, keep the human-facing `@return` tag broad and
described (`@return array - ...` or `@return array|null - ...`), then put the precise shape in a
separate `@phpstan-return array{...}` tag with one complete `key: type` pair per line. Do not put a
description after the closing `}` of an `@return` or `@phpstan-return` array shape; PHPStan reads it
as malformed type syntax, not prose. When composing long PHPStan type aliases, avoid splitting the
alias name from its type body across physical lines; `tests/Mutation/InfectionReportParserTest.php`
(search: `InvalidReportNestedA`) uses smaller intermediate aliases instead.

## Footgun: Member-existence rules must honour PHP's split case rules — methods case-insensitive, properties case-sensitive

**Status:** active | **Created:** 2026-06-04 | **Evidence:** OBSERVED

PHP resolves method names case-insensitively but property names case-sensitively: `method_exists($c, 'RENDER')` is true for a declared `render()`, yet `property_exists($c, 'LABEL')` is false for a declared `$label`. A rule that indexes or looks up member names with a single `strtolower()` for both buckets mis-handles properties. `src/Rule/TestQuality/StaticAnalysisRedundantTestRule.php` (search: `memberCandidate`) originally lowercased both the declaration key and the asserted member, so `assertTrue(property_exists(Foo::class, 'LABEL'))` against a `$label` property — a test that actually fails at runtime — was reported as a static-analysis-redundant candidate, steering users to delete a test that was catching a real case typo.

**Evidence:** PR #8 review (Codex P2, "Preserve property-name case in redundant-test matching"). Reproduction: a fixture with `public string $label` plus `assertTrue(property_exists(Widget::class, 'LABEL'))` was flagged pre-fix and is not flagged post-fix, while `method_exists(Widget::class, 'RENDER')` stays flagged. The fix indexes properties by their declared name (search: `PHP property names are case-sensitive, so index by the declared name as-is`) and keeps methods lowercased (search: `PHP resolves method names case-insensitively`); `memberCandidate` chooses the lookup key by member kind.

**Prevention:** Any rule that matches class members by name must split case handling by kind. Case-insensitive: methods, functions, class/interface/trait/enum names (lowercase both sides). Case-sensitive: properties, class constants, enum cases, variables (compare verbatim). When a member-matching rule lands, add a wrong-case fixture for every case-sensitive member kind it inspects and assert it is NOT matched, so a future single-`strtolower()` shortcut fails the test.

## Footgun: `NodeIndex` enumerates declarations nested in functions and conditionals, not just top-level ones

**Status:** active | **Created:** 2026-06-04 | **Evidence:** OBSERVED

`NodeIndex::nodesOfAny`/`nodesOf` return matches from a full preorder walk of the whole unit — `src/Rule/NodeIndex.php` (search: `traverse($analysisUnit->statements)`) visits every descendant, so a query for `Stmt\Class_`/`Interface_`/`Trait_`/`Enum_` also returns class-likes declared inside functions, methods, `if` blocks, and other conditionals. PHP does not register those symbols until the enclosing path runs (`class_exists(Foo::class, false)` is false before a nested `class Foo {}` executes), so a rule that treats every indexed declaration as "statically guaranteed to exist" over-claims. `src/Rule/TestQuality/StaticAnalysisRedundantTestRule.php` (search: `topLevelClassLikes`) hit this: a `class` declared inside `if (!class_exists(...)) { ... }` (a common polyfill shape) was treated as proven, so an `assertTrue(class_exists(...))` that genuinely tests the runtime branch was flagged as redundant.

**Evidence:** PR #8 review (Codex P2, "Skip non-top-level declarations for redundant checks"). Reproduction: a conditionally-declared `Conditional` class plus `assertTrue(class_exists(Conditional::class))` was flagged pre-fix and is not flagged post-fix. The fix walks `$analysisUnit->statements` and only collects class-likes at file scope or directly inside a `Stmt\Namespace_` body (search: `topLevelClassLikes`), instead of using the full-AST `NodeIndex` enumeration.

**Prevention:** When a rule needs declarations that PHP registers unconditionally (top-level symbols, "this type definitely exists"), do not enumerate them via `NodeIndex` — it has no scope filter. Collect them from `$analysisUnit->statements` plus each `Stmt\Namespace_::$stmts` directly, which excludes function/method/conditional bodies. `NodeIndex` stays correct for "find every node of this shape anywhere" queries (most rules); the trap is specifically assuming its results are top-level.

## Footgun: Same-class callable-array detection must include `[__CLASS__, 'method']`, not just `$this`/`self::class`/`static::class`/`Class::class`

**Status:** active | **Created:** 2026-06-14 | **Evidence:** OBSERVED

`src/Rules/DeadCode/UnusedPrivateMethodRule.php` (search: `isCallableReference`) treats a private method as used when it appears in a callable array `[$this, 'm']`, `[self::class, 'm']`, `[static::class, 'm']`, or `[ClassName::class, 'm']`. The `__CLASS__` magic constant parses to `Node\Scalar\MagicConst\Class_` — NOT an `Expr\ClassConstFetch` — so a `ClassConstFetch`-only check silently misses `[__CLASS__, 'm']`, even though it is semantically identical to `[self::class, 'm']` (both name the defining class). `array(__CLASS__, 'method')` is a common WordPress/static-callback idiom: a final pre-ship scan of the dogfood corpora flagged 10 genuinely-used private methods as unused — 4 in woocommerce, 6 in jetpack, 0 in the Symfony-based shopware/mautic — e.g. `WC_Meta_Box_Product_Data::product_data_tabs_sort()`, used via `uasort( $tabs, array( __CLASS__, 'product_data_tabs_sort' ) )`.

**Evidence:** Fix accepts `$first instanceof Node\Scalar\MagicConst\Class_` (search: `names the defining class`). Regression case at `tests/Fixtures/DeadCode/unused-private-method.php` (search: `comparePromptRowsByType`) is referenced only via `usort($rows, [__CLASS__, 'comparePromptRowsByType'])`; `tests/Rule/DeadCode/DeadCodeRulesTest.php` (search: `comparePromptRowsByType`) asserts it is not flagged, and the file's `assertCount(2)` would become 3 without the fix. `get_class($this)` and `get_called_class()` callable forms produced 0 corpus false positives, so they are deliberately not handled.

**Prevention:** The canonical same-class callable set any callable-aware rule must accept is `[$this, …]`, `[self::class, …]`, `[static::class, …]`, `[ClassName::class, …]` (FQ-compared), and `[__CLASS__, …]`. When adding or reviewing a rule that treats array callables as references (dead-code, first-class-callable, hook detection), include a `[__CLASS__, 'method']` fixture — `MagicConst\Class_` is the easy form to miss because it shares no node type with `Foo::class`.

## Footgun: php-parser node-list properties type as `array<Stmt>` (int|string keys), so PHPStan L10 rejects an `array<int, Stmt>` parameter

**Status:** active | **Created:** 2026-06-14 | **Evidence:** OBSERVED

php-parser declares list properties such as `If_::$stmts`, `ClassMethod::$stmts`, and `Class_::$stmts` as `Stmt[]`, which PHPStan reads as `array<Stmt>` (key type `int|string`), not `array<int, Stmt>`. A rule helper that receives such a property and annotates the parameter `@param array<int, Stmt>` fails PHPStan L10 with `argument.type ... expects array<int, Stmt>, array<Stmt> given`. Observed in the guard-clause classifier change: `src/Rules/Complexity/ComplexityShapeClassifier.php` (search: `isEarlyExitBranch`) was first annotated `array<int, Stmt>` and rejected; `array<Stmt>` cleared it.

**Prevention:** When a rule helper takes a php-parser node-list property (`$node->stmts`, `$node->params`, `$class->items`, …), annotate the parameter `array<Stmt>` / `Stmt[]` — do not narrow the key to `int`. Reserve `array<int, X>` for lists the rule itself builds and re-keys. `composer phpstan` catches the mismatch quickly, but the instinct to write `array<int, …>` for "a list" is the trap.

## Resolved Entries

## Footgun: Project rules need full project context, not `--changed-only`

**Status:** resolved | **Created:** 2026-05-11 | **Resolved:** 2026-05-16 | **Evidence:** OBSERVED

`design.single-implementor-interface` is a `ProjectRuleInterface` (M31, see `.goat-flow/learning-loop/decisions/ADR-003-project-rule-seam.md`). It can only count implementations and external type-hint usages from the units it actually receives. Under the old `gruff-php analyse --diff-vs=<base> --changed-only` path, the unit list was the diff's changed files, not the full project. A single-implementor interface whose implementor was in an unchanged file disappeared from the project rule's view, so the rule emitted zero findings on the diff even though a full-project scan would flag the interface. Observed during M31 dogfood: gruff scanned with `--diff-vs=deploy --changed-only` on an external healthcare target branch reported `0` design.single-implementor-interface findings; a full `src` scan on the same branch reported 7.

**Resolution:** `src/Command/AnalyseCommand.php` (search: `projectContextUnits`) now loads full current/base project context for enabled project rules in changed-only branch review mode, while `src/Rule/RuleRegistry.php` (search: `$projectUnits ?? $units`) keeps file-scoped rules on the narrowed unit list. The reported findings are still filtered back to changed files. `tests/Review/AgentWorkflowCliTest.php` (search: `testBranchReviewChangedOnlyUsesFullProjectContextForProjectRules`) locks the bug shape where only the interface file changes and its implementor is unchanged.

**Prevention:** Keep any future `ProjectRuleInterface` tests paired with a `--diff-vs --changed-only` review fixture where the changed file depends on unchanged project context. Do not route project-level rules through a changed-file-only unit list unless the rule explicitly documents partial-context semantics.

## Footgun: Project-config rules fire on single-file scans of production code

**Status:** resolved | **Created:** 2026-05-11 | **Resolved:** 2026-05-11 | **Evidence:** OBSERVED

Rules that inspect project-level config files (`test-quality.phpunit-deprecations-not-fatal`, `test-quality.phpunit-strict-flags-missing`, `test-quality.phpunit-coverage-source-missing`) walk `$context->projectRoot` to discover `phpunit.xml.dist`, dedup per root, and emit a finding once per project. The unit being scanned was previously ignored - so `gruff-php analyse src/App/Foo.php` (a single production file with no test file in scope) still produced two warnings against `phpunit.xml.dist`. Targeting a production file should not surface test-config quality opinions.

**Prevention:** Project-config rules now gate their first emission on "at least one unit in this run looks like a PHPUnit test file" (via `TestQualityNodeHelper::looksLikePhpUnitTestFile()` - matches `/tests/`, `/Tests/`, basename ending `Test.php` or `TestCase.php`). When extending a `RuleInterface` rule to inspect project-level state, the rule must look at the unit being analysed (or aggregate per-unit data through a `ProjectRuleInterface` instead) before emitting at the project level - otherwise the rule emits regardless of scope and adds noise to narrow scans. Reuse `TestQualityNodeHelper::looksLikePhpUnitTestFile()` for any future PHPUnit-config rule; do not duplicate the path heuristic.

## Footgun: Lockfiles tripped sensitive-data rules with 100% false-positive rate

**Status:** resolved | **Created:** 2026-05-11 | **Resolved:** 2026-05-11 | **Evidence:** OBSERVED

Until `SourceDiscovery::IGNORED_FILENAMES` was added, well-known lockfiles with `.json` or `.yaml` extensions (`package-lock.json`, `npm-shrinkwrap.json`, `pnpm-lock.yaml`) were classified as text units and scanned by every rule implementing `SourceTextRuleInterface`. On an external healthcare target branch diff this produced 1441 `sensitive-data.high-entropy-string` findings on npm integrity hashes (`sha512-aBc...` base64 SHA-512 digests), with literally zero true positives. The rule had no way to distinguish machine-generated lockfile metadata from a real secret in a JSON config.

**Prevention:** Lockfile filenames are now hard-coded in `SourceDiscovery::IGNORED_FILENAMES` and treated like the `vendor/` directory: skipped during traversal, skipped when passed as an explicit path, and overridable only with `--include-ignored`. When adding a new `SourceTextRuleInterface` rule in the future, audit which file types it will match against the project state of a realistic open-source PHP project (Symfony, Laravel) and confirm that lockfiles, `.po`/`.mo` translation files, dist bundles, and similar machine-generated text artefacts either fall outside the rule's regex or are already filtered by source discovery. If a new false-positive class surfaces on a class of file, prefer extending `IGNORED_FILENAMES` over carving file-type exceptions into individual rules.

## Footgun: Readonly-property walker missed array-write mutation on `$this->prop[…]`

**Status:** resolved | **Created:** 2026-05-27 | **Resolved:** 2026-05-27 | **Evidence:** OBSERVED

`src/Rule/Modernisation/ReadonlyPropertyCandidateRule.php` (search: `lateAssignments`) called `ModernisationNodeHelper::propertyFetchName($assign->var)` directly. For `$this->messages[] = $x`, `$this->messages['k'] = $x`, and `unset($this->messages['k'])`, the AST shape is `Expr\ArrayDimFetch` wrapping the `$this` `PropertyFetch` (or `Stmt\Unset_` entirely outside the `Expr\Assign::class` set), so the helper returned null and the rule treated the property as never mutated late — emitting a readonly candidacy finding even though applying the suggested `readonly` modifier would crash at runtime on the very next array-write. Reviewer cited a real-world hit on a Symfony 6.4 `ChatAssistantSession::appendUserMessage()`-style class.

**Evidence:** External reviewer report at `.goat-flow/scratchpad/gruff-php-improvement-feedback.md` section 2. Reproduction: a final class with `private array $messages;`, constructor `$this->messages = []`, and `$this->messages[] = $x` in a later method produced a readonly candidate finding pre-fix and produces zero findings post-fix.

**Resolution:** `lateAssignments` now walks `Expr\ArrayDimFetch` chains down to the underlying expression via `recordPropertyMutation()` before consulting the helper, AND iterates `Stmt\Unset_` nodes separately so `unset($this->prop['k'])` is treated as the same kind of post-constructor mutation. The shared helper (`ModernisationNodeHelper::propertyFetchName`/`isThisPropertyFetch`) stays untouched because only one rule needs the walk today; expanding the helper to do it would change the behaviour of every consumer for no current benefit.

**Prevention:** When a modernisation rule reasons about "this property mutates after the constructor", it must check every AST shape that PHP allows to mutate the container without textually mentioning the property: plain `Expr\Assign` whose LHS is a `PropertyFetch`, `Expr\Assign` whose LHS is one-or-more `ArrayDimFetch` wrapping a `PropertyFetch`, and `Stmt\Unset_` whose arg list contains the same shape. `Expr\AssignOp::*` (compound assigns like `$this->count += 1`) is `Expr\AssignOp::class`, not `Expr\Assign::class`, and the same nodeFinder query would miss it — when a future rule needs compound-assign awareness, extend `recordPropertyMutation()` to be called from the `AssignOp` walker as well. The fixture lives at `tests/Fixtures/Modernisation/non-candidates.php` `MessageInboxFixture` covering all three sub-cases. Pass-by-reference detection (`func(&$this->prop)`) is deliberately deferred; see `.goat-flow/plans/_archive/0.2.0/M01-modernisation-waste-false-positive-fixes.md` "## Deferred".

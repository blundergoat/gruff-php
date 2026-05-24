---
category: rules
last_reviewed: 2026-05-23
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

## Resolved Entries

## Footgun: Project rules need full project context, not `--changed-only`

**Status:** resolved | **Created:** 2026-05-11 | **Resolved:** 2026-05-16 | **Evidence:** OBSERVED

`design.single-implementor-interface` is a `ProjectRuleInterface` (M31, see `.goat-flow/decisions/ADR-003-project-rule-seam.md`). It can only count implementations and external type-hint usages from the units it actually receives. Under the old `gruff-php analyse --diff-vs=<base> --changed-only` path, the unit list was the diff's changed files, not the full project. A single-implementor interface whose implementor was in an unchanged file disappeared from the project rule's view, so the rule emitted zero findings on the diff even though a full-project scan would flag the interface. Observed during M31 dogfood: gruff scanned with `--diff-vs=deploy --changed-only` on an external healthcare target branch reported `0` design.single-implementor-interface findings; a full `src` scan on the same branch reported 7.

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

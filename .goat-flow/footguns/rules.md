---
category: rules
last_reviewed: 2026-05-16
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

## Footgun: Conservative member scopes can hide concrete public waste

**Status:** active | **Created:** 2026-05-11 | **Evidence:** OBSERVED

`src/Rule/Waste/UnusedParameterRule.php` (search: `analysableNodes`) originally checked standalone functions and private methods only, so a concrete public method like healthkit's `VoiceTraceLogger::trace()` could carry an ignored `$detailed` parameter without any `waste.unused-parameter` finding. The same rule also counted direct `unset($detailed)` as a parameter use even though it is only a placeholder/silencer, not a read of the argument.

**Prevention:** For waste/dead-code rules, make the conservative boundary explicit in tests and include at least one locally owned public method fixture plus one contract/override fixture. Treat direct `unset($param)` as non-use when the rule's question is "does the method actually consume this argument?" and use finding columns or metadata when multiple same-line findings can otherwise share a fingerprint.

## Footgun: Constructor-promoted properties bypass property-node scans

**Status:** active | **Created:** 2026-05-11 | **Evidence:** OBSERVED

Constructor-promoted properties are represented as `Node\Param` entries with visibility flags, not as `Stmt\Property` declarations. `src/Rule/DeadCode/UnusedPrivatePropertyRule.php` (search: `privateProperties`) originally collected only `Stmt\Property`, so promoted private readonly fields such as healthkit's `VoiceTraceLogger::$appConfigHelper` and `VoiceTraceLogger::$twilioParams` were invisible to the "written but never read" check.

**Prevention:** Any rule that scans properties must include promoted constructor parameters in fixtures and treat private promoted params as property declarations with an initial write. Also include a used promoted private property and a public promoted property fixture so the rule proves both detection and visibility boundaries.

## Footgun: Project rules need full project context, not `--changed-only`

**Status:** active | **Created:** 2026-05-11 | **Evidence:** OBSERVED

`design.single-implementor-interface` is a `ProjectRuleInterface` (M31, see `.goat-flow/decisions/ADR-003-project-rule-seam.md`). It can only count implementations and external type-hint usages from the units it actually receives. Under `gruff-php analyse --diff-vs=<base> --changed-only`, the unit list is the diff's changed files, not the full project. A single-implementor interface whose implementor is in an unchanged file disappears from the project rule's view, so the rule emits zero findings on the diff even though a full-project scan would flag the interface. Observed during M31 dogfood: gruff scanned with `--diff-vs=deploy --changed-only` on healthkit's `feat/64272_voice-olb` branch reported `0` design.single-implementor-interface findings; a full `src` scan on the same branch reported 7.

**Prevention:** When the rule emits zero findings under `--changed-only`, do not treat that as "the diff is clean of this rule." Run a full-project scan (without `--changed-only`) to confirm. For agent review workflows that surface single-implementor-interface findings, the workflow should run the rule against the full project (or at least the project's `src/` tree) and intersect the findings with the diff's changed files afterwards, rather than relying on `--changed-only` to do the intersection. The same caveat applies to any future `ProjectRuleInterface`.

## Footgun: Vendored code under `src/Vendor/` evades the vendor filter

**Status:** active | **Created:** 2026-05-11 | **Evidence:** OBSERVED

`design.single-implementor-interface` (and any future project rule) excludes files whose displayPath starts with `vendor/` by default, matching the Composer convention. Some projects vendor third-party libraries by copying them into `src/Vendor/...` instead of relying on Composer (observed in the healthkit dogfood: `src/Vendor/LayerShifter/...`, `src/Vendor/phpdocx/...`). Those copies live under `src/` and the rule treats them as project code, flagging genuinely external interfaces as if they were internal. Three of seven full-project findings on healthkit were these vendored copies (43% false-positive rate before configuration).

**Prevention:** Document the rule's `additionalExcludedPaths` option (defined in `SingleImplementorInterfaceRule::definition()`'s `defaultOptions`). When dogfooding the rule in a project with vendored copies under `src/`, set `additionalExcludedPaths: ['src/Vendor/']` (or the project-specific convention) in `.gruff.yaml`. Do not extend the rule's hard-coded vendor list with project-specific paths; configuration is the right escape hatch. Mention the option in the rule's remediation text when the false-positive class surfaces in another project.

## Resolved Entries

## Footgun: Project-config rules fire on single-file scans of production code

**Status:** resolved | **Created:** 2026-05-11 | **Resolved:** 2026-05-11 | **Evidence:** OBSERVED

Rules that inspect project-level config files (`test-quality.phpunit-deprecations-not-fatal`, `test-quality.phpunit-strict-flags-missing`, `test-quality.phpunit-coverage-source-missing`) walk `$context->projectRoot` to discover `phpunit.xml.dist`, dedup per root, and emit a finding once per project. The unit being scanned was previously ignored - so `gruff-php analyse src/App/Foo.php` (a single production file with no test file in scope) still produced two warnings against `phpunit.xml.dist`. Targeting a production file should not surface test-config quality opinions.

**Prevention:** Project-config rules now gate their first emission on "at least one unit in this run looks like a PHPUnit test file" (via `TestQualityNodeHelper::looksLikePhpUnitTestFile()` - matches `/tests/`, `/Tests/`, basename ending `Test.php` or `TestCase.php`). When extending a `RuleInterface` rule to inspect project-level state, the rule must look at the unit being analysed (or aggregate per-unit data through a `ProjectRuleInterface` instead) before emitting at the project level - otherwise the rule emits regardless of scope and adds noise to narrow scans. Reuse `TestQualityNodeHelper::looksLikePhpUnitTestFile()` for any future PHPUnit-config rule; do not duplicate the path heuristic.

## Footgun: Lockfiles tripped sensitive-data rules with 100% false-positive rate

**Status:** resolved | **Created:** 2026-05-11 | **Resolved:** 2026-05-11 | **Evidence:** OBSERVED

Until `SourceDiscovery::IGNORED_FILENAMES` was added, well-known lockfiles with `.json` or `.yaml` extensions (`package-lock.json`, `npm-shrinkwrap.json`, `pnpm-lock.yaml`) were classified as text units and scanned by every rule implementing `SourceTextRuleInterface`. On healthkit's `feat/64272_voice-olb` branch diff this produced 1441 `sensitive-data.high-entropy-string` findings on npm integrity hashes (`sha512-aBc...` base64 SHA-512 digests), with literally zero true positives. The rule had no way to distinguish machine-generated lockfile metadata from a real secret in a JSON config.

**Prevention:** Lockfile filenames are now hard-coded in `SourceDiscovery::IGNORED_FILENAMES` and treated like the `vendor/` directory: skipped during traversal, skipped when passed as an explicit path, and overridable only with `--include-ignored`. When adding a new `SourceTextRuleInterface` rule in the future, audit which file types it will match against the project state of a realistic open-source PHP project (Symfony, Laravel) and confirm that lockfiles, `.po`/`.mo` translation files, dist bundles, and similar machine-generated text artefacts either fall outside the rule's regex or are already filtered by source discovery. If a new false-positive class surfaces on a class of file, prefer extending `IGNORED_FILENAMES` over carving file-type exceptions into individual rules.

---
category: tests
last_reviewed: 2026-09-27
---

# Test Footguns

## Footgun: Anonymous-class test fakes are scored by gruff's production rules

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED

The configured `php bin/gruff-php analyse` scan invoked by `scripts/preflight-checks.sh` (search: `gruff_php_check`) includes PHPUnit tests. Anonymous classes built inside tests as fakes, spies, or stubs are analysed like production code, so `docs.missing-phpdoc`, `naming.boolean-prefix`, `waste.empty-method`, and `waste.one-line-method` apply to them. The narrower security scan in `composer check` omits tests (`composer.json`, search: `security:scan`). It cannot replace this full self-scan.

In the original 2026-05-24 incident, the first cut of `tests/Command/MissingConfigPromptTest.php` (search: `private function fakeConsoleOutput`) triggered 5 errors and 7 advisories before the fake was reshaped to satisfy the rules. Preflight passes `--fail-on advisory`, so every finding blocked the build. The parameter-type naming rule active at that time was retired by `.goat-flow/learning-loop/decisions/ADR-014-retire-naming-parameter-type-name.md` (search: `Accepted`). It imposes no current naming requirement.

**Evidence:** `tests/Command/MissingConfigPromptTest.php` (search: `extends BufferedOutput implements ConsoleOutputInterface`) is the canonical post-fix example. The `tests/Fixtures/**` entry in `.gruff-php.yaml` (search: `tests/Fixtures/**`) ignores the fixture corpus that gruff scans as analysis input, but real PHPUnit test files under `tests/Command`, `tests/Console`, `tests/Rule`, etc. are in scope.

**Prevention:** Write anonymous classes in tests the same way you'd write a production class: PHPDoc on every method, meaningful parameter names, no empty method bodies, and no throw-only one-liners. For unavoidable interface-required no-ops, use `unset($parameter)` (parses as `Stmt\Unset_`, which `waste.one-line-method` skips because the rule only checks `Return_` and `Expression` statements). For interface methods that must throw because of a non-nullable return type, split the body into two statements (assign the message to a local, then `throw new ...($message);`) and add a `@throws` tag. The worked-out shape lives in `.goat-flow/learning-loop/patterns/tests.md` "Intersection-typed test fake for stream routing".

## Footgun: The obvious data-provider consolidation trips phpdoc-mixed-overuse and the public-method cap

**Status:** active | **Created:** 2026-05-31 | **Evidence:** OBSERVED

`test-quality.repeated-structure-missing-data-provider` (`src/Rules/TestQuality/RepeatedStructureMissingDataProviderRule.php`, search: `MIN_GROUP_SIZE`) pushes three-plus structurally-identical tests toward a `#[DataProvider]`, but the naive consolidation trips two other gates that score `tests/` like production code:

- A provider yielding heterogeneous config inputs wants `@return iterable<string, array{array<array-key, mixed>, string}>`, and `modernisation.phpdoc-mixed-overuse` fires on the nested `mixed` because the unstructured-bag exemption in `src/Rules/Modernisation/PhpDocMixedOveruseRule.php` (search: `isUnstructuredArrayBagType`) only applies when `array<…, mixed>` is the *top-level* tag type, not nested inside `iterable<…>`. PHPStan runs at level 10 (`phpstan.neon.dist`, search: `level: 10`), so a bare `array` value type is rejected too. Fix: yield each malformed input as a JSON *string*, then `json_decode` it inside the test behind a top-level `/** @var array<array-key, mixed> $config <reason> */` (a top-level bag *is* exempt). Worked example: `tests/Reporting/FailThresholdsTest.php` (search: `invalidFailureConditionsProvider`).
- The new public provider method plus the split test methods count toward `size.public-method-count` (cap 25 in `.gruff-php.yaml`, search: `size.public-method-count`). `tests/Config/ConfigLoaderTest.php` (search: `testExcludeFromScoreDefaultsToFalseAndHonoursOverrides`) was already at the cap, so a three-cycle test was kept as one method that batches all arrange/act then all asserts — a single act→assert transition satisfies `test-quality.multiple-aaa-cycles` (minCycles 3) without adding a method.

**Evidence:** Both findings surfaced mid-cleanup after consolidating the four `FailThresholds::fromConfig` rejection tests and splitting the ConfigLoader excludeFromScore test; the self-scan went back to zero only after the JSON-string provider and the batched-AAA rewrite.

**Prevention:** Before consolidating, check the test class's public-method count and whether the provider's `@return` will nest `mixed`. Prefer JSON-string provider rows for heterogeneous inputs; when the class is near the 25-method cap, satisfy `multiple-aaa-cycles` by batching arrange-act-then-assert in one method instead of splitting into new public methods. At least two classes already sit *at* the cap: `tests/Config/ConfigLoaderTest.php` and `tests/Rule/Naming/NamingRulesTest.php` (search: `testGenericMethodNamesDetected`). A genuinely new public test method for a naming rule belongs in `tests/Rule/Naming/NamingRuleConfigurationTest.php` (the rule-option/config home — search: `testBooleanPrefixAllowedPrefixesCanBeConfigured`) or a split-out class like `IdentifierTokenizerTest`, not in `NamingRulesTest` — adding one there takes it to 26 and `analyse` fails the gate with `size.public-method-count` (observed 2026-05-31 when the `acceptedBooleanNames` test was first placed in `NamingRulesTest`).

## Footgun: Two split-hex snapshot hashes lock the rule corpus and rule definitions; both break silently on fixture and defaultOptions changes

**Status:** active | **Created:** 2026-05-31 | **Evidence:** OBSERVED

Two regression tests pin a SHA-256 over a serialized snapshot, and each is sensitive to a change a different rule edit makes:

- `tests/Rule/RuleRegressionSnapshotTest.php` (search: `testDefaultRuleRegistryFindingsStayStableAcrossFixtures`) hashes the canonical finding payload produced by scanning all of `tests/Fixtures`. Adding a fixture can change the unit count, finding count and hash, including through incidental findings such as `docs.missing-file-phpdoc`.
- `tests/Rule/RuleRegistryTest.php` (search: `testDefaultRuleDefinitionsStayStable`) hashes every rule's serialized definition, **including `defaultOptions`**. Adding an option key changes this hash; the definition count changes only when a rule is added or removed.

The snapshot digest used to need a self-scan allowlist when written as one 64-character literal. On 2026-06-14 a regenerated digest no longer matched that preview allowlist; the narrow security scan omitted tests and missed the stale configuration. That workaround is obsolete in 0.6.0: `src/Engine/Config/ConfigLoader.php` (search: `LEGACY_SECRET_PREVIEWS_ERROR`) rejects `allowlists.secretPreviews`, and FAMILY-CONTRACT section 13a gives test paths a counted built-in sensitive-data suppression. Digest formatting no longer requires a preview entry.

**Evidence:** 2026-05-31, the P6 fixture `tests/Fixtures/Complexity/bodyless.php` took the corpus from 150→151 units / 2504→2507 findings / new hash, and adding `acceptedBooleanNames` to `BooleanPrefixRule::definition()` `defaultOptions` (search: `acceptedBooleanNames`) changed the definition hash while the 119 count held.

**Prevention:** When a fixture or rule definition changes, run the snapshot tests and inspect the finding or definition delta before updating their expected counts and digests. Reuse each test's serialization logic when computing its replacement digest. A larger-than-expected delta can mean an existing fingerprint moved. Do not restore the removed `secretPreviews` setting; verify the updated snapshots with `composer test` and the configured self-scan.

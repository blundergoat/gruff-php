---
category: tests
last_reviewed: 2026-05-31
---

# Test Footguns

## Footgun: Anonymous-class test fakes are scored by gruff's production rules

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED

The default `php bin/gruff-php analyse` scan invoked by `composer check` and `scripts/preflight-checks.sh` (search: `gruff_php_check`) does not exclude `tests/`. Anonymous classes built inside tests as fakes, spies, or stubs are analysed like production code, so `docs.missing-public-phpdoc`, `naming.parameter-type-name`, `naming.boolean-prefix`, `waste.empty-method`, and `waste.one-line-method` all fire on them. The first cut of `tests/Command/MissingConfigPromptTest.php` (search: `private function fakeConsoleOutput`) triggered 5 errors and 7 advisories before the fake was reshaped to satisfy the rules — preflight passes `--fail-on advisory`, so every finding blocked the build.

**Evidence:** `tests/Command/MissingConfigPromptTest.php` (search: `extends BufferedOutput implements ConsoleOutputInterface`) is the canonical post-fix example. The `tests/Fixtures/**` entry in `.gruff-php.yaml` (search: `tests/Fixtures/**`) ignores the fixture corpus that gruff scans as analysis input, but real PHPUnit test files under `tests/Command`, `tests/Console`, `tests/Rule`, etc. are in scope.

**Prevention:** Write anonymous classes in tests the same way you'd write a production class — PHPDoc on every public method, parameter names that match the type convention (`$bufferedOutput`, not `$stdoutBuffer`), no empty method bodies, no throw-only one-liners. For unavoidable interface-required no-ops, use `unset($parameter)` (parses as `Stmt\Unset_`, which `waste.one-line-method` skips because the rule only checks `Return_` and `Expression` statements). For interface methods that must throw because of a non-nullable return type, split the body into two statements (assign the message to a local, then `throw new ...($message);`) and add a `@throws` tag. The worked-out shape lives in `.goat-flow/patterns/tests.md` "Intersection-typed test fake for stream routing".

## Footgun: The obvious data-provider consolidation trips phpdoc-mixed-overuse and the public-method cap

**Status:** active | **Created:** 2026-05-31 | **Evidence:** OBSERVED

`test-quality.repeated-structure-missing-data-provider` (`src/Rule/TestQuality/RepeatedStructureMissingDataProviderRule.php`, search: `MIN_GROUP_SIZE`) pushes three-plus structurally-identical tests toward a `#[DataProvider]`, but the naive consolidation trips two other gates that score `tests/` like production code:

- A provider yielding heterogeneous config inputs wants `@return iterable<string, array{array<array-key, mixed>, string}>`, and `modernisation.phpdoc-mixed-overuse` fires on the nested `mixed` because the unstructured-bag exemption in `src/Rule/Modernisation/PhpDocMixedOveruseRule.php` (search: `isUnstructuredArrayBagType`) only applies when `array<…, mixed>` is the *top-level* tag type, not nested inside `iterable<…>`. PHPStan runs at level 10 (`phpstan.neon.dist`, search: `level: 10`), so a bare `array` value type is rejected too. Fix: yield each malformed input as a JSON *string*, then `json_decode` it inside the test behind a top-level `/** @var array<array-key, mixed> $config <reason> */` (a top-level bag *is* exempt). Worked example: `tests/Reporting/FailThresholdsTest.php` (search: `invalidFailureConditionsProvider`).
- The new public provider method plus the split test methods count toward `size.public-method-count` (cap 25 in `.gruff-php.yaml`, search: `size.public-method-count`). `tests/Config/ConfigLoaderTest.php` (search: `testExcludeFromScoreDefaultsToFalseAndHonoursOverrides`) was already at the cap, so a three-cycle test was kept as one method that batches all arrange/act then all asserts — a single act→assert transition satisfies `test-quality.multiple-aaa-cycles` (minCycles 3) without adding a method.

**Evidence:** Both findings surfaced mid-cleanup after consolidating the four `FailThresholds::fromConfig` rejection tests and splitting the ConfigLoader excludeFromScore test; the self-scan went back to zero only after the JSON-string provider and the batched-AAA rewrite.

**Prevention:** Before consolidating, check the test class's public-method count and whether the provider's `@return` will nest `mixed`. Prefer JSON-string provider rows for heterogeneous inputs; when the class is near the 25-method cap, satisfy `multiple-aaa-cycles` by batching arrange-act-then-assert in one method instead of splitting into new public methods.

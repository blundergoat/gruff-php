---
category: tests
last_reviewed: 2026-05-24
---

# Test Footguns

## Footgun: Anonymous-class test fakes are scored by gruff's production rules

**Status:** active | **Created:** 2026-05-24 | **Evidence:** OBSERVED

The default `php bin/gruff-php analyse` scan invoked by `composer check` and `scripts/preflight-checks.sh` (search: `gruff_php_check`) does not exclude `tests/`. Anonymous classes built inside tests as fakes, spies, or stubs are analysed like production code, so `docs.missing-public-phpdoc`, `naming.parameter-type-name`, `naming.boolean-prefix`, `waste.empty-method`, and `waste.one-line-method` all fire on them. The first cut of `tests/Command/MissingConfigPromptTest.php` (search: `private function fakeConsoleOutput`) triggered 5 errors and 7 advisories before the fake was reshaped to satisfy the rules — preflight passes `--fail-on advisory`, so every finding blocked the build.

**Evidence:** `tests/Command/MissingConfigPromptTest.php` (search: `extends BufferedOutput implements ConsoleOutputInterface`) is the canonical post-fix example. The `tests/Fixtures/**` entry in `.gruff-php.yaml` (search: `tests/Fixtures/**`) ignores the fixture corpus that gruff scans as analysis input, but real PHPUnit test files under `tests/Command`, `tests/Console`, `tests/Rule`, etc. are in scope.

**Prevention:** Write anonymous classes in tests the same way you'd write a production class — PHPDoc on every public method, parameter names that match the type convention (`$bufferedOutput`, not `$stdoutBuffer`), no empty method bodies, no throw-only one-liners. For unavoidable interface-required no-ops, use `unset($parameter)` (parses as `Stmt\Unset_`, which `waste.one-line-method` skips because the rule only checks `Return_` and `Expression` statements). For interface methods that must throw because of a non-nullable return type, split the body into two statements (assign the message to a local, then `throw new ...($message);`) and add a `@throws` tag. The worked-out shape lives in `.goat-flow/patterns/tests.md` "Intersection-typed test fake for stream routing".

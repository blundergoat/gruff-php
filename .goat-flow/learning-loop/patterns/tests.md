---
category: tests
last_reviewed: 2026-05-24
---

# Test Patterns

## Pattern: Intersection-typed test fake for stream routing

**Created:** 2026-05-24

**Context:** Some console code branches on `$output instanceof ConsoleOutputInterface` to route prompts or progress to STDERR via `getErrorOutput()` (see `src/Command/MissingConfigPrompt.php`, search: `promptOutput`). Verifying that routing in a test requires a double that (a) implements `ConsoleOutputInterface` so the runtime branch is taken, (b) captures any main-stream writes so the test can assert they did not happen, and (c) exposes a buffer for the error stream so the test can assert what *did* land there. `tests/Command/MissingConfigPromptTest.php` (search: `private function fakeConsoleOutput`) is the canonical implementation.

**Approach:** Build an anonymous class that extends `Symfony\Component\Console\Output\BufferedOutput` and implements `ConsoleOutputInterface`. The parent's own buffer captures any main-stream writes; the constructor takes a separate `BufferedOutput` exposed via `getErrorOutput()`. Declare the helper's return type as the intersection `BufferedOutput&ConsoleOutputInterface` so PHPStan permits parent methods like `fetch()` on the result without resorting to inline `@var` (which `phpstan.neon.dist` policy forbids). Two interface-required methods need care:

- `setErrorOutput(OutputInterface $output): void` must have a non-empty body that is not a call expression — otherwise `waste.empty-method` or `waste.one-line-method` will fire. Use `unset($output);`. It parses as `Stmt\Unset_`, which `src/Rule/Waste/OneLineMethodRule.php` (search: `Return_` and `Expression`) treats as out of scope.
- `section(): ConsoleSectionOutput` cannot return null and `ConsoleSectionOutput` is impractical to construct in a test. Throw a `LogicException` in a two-statement body so `waste.one-line-method` skips it (`count($classMethod->stmts) !== 1`), and add a `@throws LogicException` tag so `docs.missing-throws-tag` is satisfied.

The test then asserts `$consoleOutput->fetch()` returns `''` (no main-stream leakage) and that the supplied error buffer contains the expected prompt text plus any dispatched sub-command output.

**Verification:** `composer test` covers the routing assertion. `scripts/preflight-checks.sh` (search: `gruff_php_check`) is the second gate — it runs gruff with `--fail-on advisory`, so the fake's shape must satisfy `docs.missing-public-phpdoc`, `naming.parameter-type-name`, `waste.empty-method`, and `waste.one-line-method`. See `.goat-flow/learning-loop/footguns/tests.md` "Anonymous-class test fakes are scored by gruff's production rules" for why these rules apply to test code at all.

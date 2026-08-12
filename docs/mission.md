# gruff-php Mission

> gruff-php exists to make AI-generated code safe for a human to sign off on.

## The problem

When a coding agent writes the code, someone who didn't write it still has to read, review, and trust it before it ships. Two failure modes dominate AI-generated changes:

- The code **superficially works while misunderstanding the requirement** — it compiles, it passes a happy-path test, but it does the wrong thing in a way a quick skim won't catch.
- The tests are **padded with low-signal ceremony** — mocks with no expectations, assertions that restate a literal, snapshots of nothing — so a green run no longer means the behaviour is exercised.

A reviewer cannot catch either of these by reading faster. gruff governs the code so the reviewer has something concrete to check against.

## What gruff optimises for

Every rule and default earns its place by serving one of three verifiability goals:

1. **Legible enough to verify.** Complexity and nesting are capped so a method fits in a reviewer's head, and every method — public or private — must carry an intent-bearing doc comment stating what it is for, what it returns at the edges, and what the caller must satisfy. The comment is a plain-English contract the reviewer checks the implementation against. A doc comment that contradicts the code is a signal the change needs a deeper look — not noise.
2. **Secure where the eye fails.** The `security` and `sensitive-data` pillars catch the classes of mistake a human reviewer skims past: injection, unsafe deserialization, leaked secrets, weak crypto, and similar.
3. **Tested for real, not padded.** The `test-quality` pillar rewards genuine assertions and flags low-signal ceremony. Its strongest signals gate hard — a test that asserts nothing, never calls its subject, or asserts a tautology fails `--fail-on error` — so a green suite means the behaviour is actually exercised rather than mocked into a tautology.

## The calibration principle

A gate earns its place only if **the cheapest way for the agent to satisfy it is the genuine improvement, not a cosmetic one.**

- Cognitive complexity and nesting pass this test: the cheapest fix is real simplification (guard clauses, a named sub-step), which is exactly what makes the code more verifiable.
- The test-quality anti-bloat rules pass it: the cheapest fix is a real assertion.
- "Must have a doc comment" passes it **only if it demands substance** — which is why `docs.missing-public-phpdoc` asks for intent, not a restatement of the signature. A rule whose cheapest passing fix is cosmetic is a candidate for lower severity, not a hard gate.

This is also why gruff favours metrics that track human comprehension (cognitive complexity, nesting depth) over pure branch-counting proxies, which can flag a flat, readable guard-chain while waving through genuinely tangled control flow.

## Why doc comments are mandatory, even on a private one-liner

`docs.missing-public-phpdoc` requires a local doc comment on every method declaration — public, protected, private, abstract, accessor, magic, helper, or interface implementation (the historical rule ID predates this scope; do not infer "public only" from the name).

That is deliberate. Forcing the agent to state intent, usage, contract, and failure behaviour in prose gives the reviewer an independent description to check the implementation against. When the prose and the code disagree, the reviewer has found either a bug or a misunderstanding — which is the whole point. The rule wants content, not boilerplate: a comment that merely restates the signature adds no verifiability and is itself flagged.

## How gruff is used

gruff is a CLI that emits findings and an exit code (`--fail-on none|advisory|warning|error`). Wired into a coding agent's loop it becomes a gate:

- as a **pre-commit hook**, so the agent cannot stage code that fails the bar;
- as a **CI check** (`--format github` / `--format sarif`), so a reviewer sees findings inline; or
- as the **agent's own verification step**, so it iterates until the change is something a human can approve.

Because the cheapest way to clear the gate is the genuine fix, the agent is pushed toward producing verifiable code rather than learning to game the metric.

## What gruff is not

gruff is heuristic static analysis, not a proof. It does not format code, run your tests, or replace type-aware analysis. Run it **beside** PHPStan, Psalm, PHPUnit, PHP-CS-Fixer/PHPCS, security scanners, and human code review — not instead of them.

## See also

- [`ADR-017`](../.goat-flow/learning-loop/decisions/ADR-017-mission-govern-ai-generated-code.md) — the mission decision and its calibration corollary.
- [`ADR-010`](../.goat-flow/learning-loop/decisions/ADR-010-complexity-and-docs-rubric-default-recalibration.md) — complexity/docs defaults; read through the verifiability lens above.
- [`ADR-004`](../.goat-flow/learning-loop/decisions/ADR-004-public-phpdoc-template.md) — the public-PHPDoc template.
- [Agent instructions](gruff-cli-agent-instructions.md) — the command quick-start for coding agents.
- [README](../README.md) — install, commands, and configuration.

# ADR-005: Intent-Bearing One-Line Methods

**Status:** Accepted
**Date:** 2026-05-12
**Author(s):** Codex
**Ticket/Context:** M36 complexity, size, and dead-code refactors; M49 rubric recalibration

## Context

M36 reduced the live `src/` dead-code bucket from 46 findings to 17 after three refactor batches. The remaining findings are all `waste.one-line-method`; `waste.unused-import` and `waste.redundant-variable` are both zero.

The remaining methods are not accidental temporary wrappers. They are public construction helpers, public value-object/query helpers, stable rule computation APIs used by tests or other rules, shared HTML/shell escaping helpers, and cross-rule utility methods. Removing them would either change public/internal contracts or duplicate security-sensitive expressions across many callsites.

## Decision

Keep these one-line methods as intent-bearing exemptions unless a future refactor replaces the surrounding API shape:

| Method | Rationale |
| --- | --- |
| `AnalyseCommandSetupResult::ready()` | Named constructor for the successful setup variant. |
| `AnalyseCommandSetupResult::plainError()` | Named constructor for plain console setup failures. |
| `AnalyseCommandSetupResult::reportError()` | Named constructor for report-formatted setup failures. |
| `DashboardPageRenderer::escape()` | Central HTML escaping helper used across dashboard markup. |
| `DashboardStateFactory::initialProjectRoot()` | Public dashboard startup API that names project-root resolution. |
| `RuleSettings::hasOption()` | Public config query helper for option presence. |
| `DiffResult::hasFile()` | Public diff-result query helper used by filtering code. |
| `FindingDisplayFilter::apply()` | Public filtering API; the method name carries domain intent at callsites. |
| `HtmlReporter::escape()` | Central HTML escaping helper used across report markup. |
| `NestingDepthRule::compute()` | Public static algorithm entry point used by tests and rule code. |
| `NpathComplexityRule::compute()` | Public static algorithm entry point used by tests and rule code. |
| `ModernisationNodeHelper::supportsPhp()` | Shared version-gating helper across modernisation rules. |
| `RuleContext::settingsFor()` | Shared rule-settings lookup API used across rule implementations. |
| `SecretScannerHelper::lineNumberForOffset()` | Shared line-number conversion helper across sensitive-data rules. |
| `SecretScannerHelper::redactedKeyValue()` | Shared redaction helper for env-style secret findings. |
| `TestQualityNodeHelper::firstArgValue()` | Shared AST helper used by several test-quality rules. |
| `TestQualityNodeHelper::docComment()` | Shared AST helper that centralizes doc-comment access. |

Future cleanup may inline one of these only when the callsite remains clearer and no public helper contract is removed. The `waste.one-line-method` rule should remain enabled; these exemptions are a documented project decision, not a threshold relaxation.

M49 converted the recurring exemption shapes into narrow rule options used by this repository's `.gruff-php.yaml`:

- `minInFileCallers: 2` skips one-line helpers called at least twice in the same file, such as shared escaping or path-normalisation helpers.
- `namedAlternativeFactoryExempt: true` skips public static self-factory methods only when a class exposes at least two named alternatives.
- `waste.one-line-method` now reports on the `maintainability` pillar instead of `dead-code`, because the rule describes avoidable indirection rather than unreachable or unused code.

The options remain opt-in for this project configuration so downstream default rule behaviour does not silently loosen.

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| Inline every one-line method | Public/internal helper contracts disappear, shared escaping and redaction logic gets duplicated, and rule tests lose stable algorithm entry points. | Rejected. The dogfood metric improves, but maintainability and review clarity regress. |
| Disable or relax `waste.one-line-method` | The project stops catching accidental wrappers introduced by future refactors. | Rejected. M36 showed the rule found real cleanup opportunities. |
| Keep only documented intent-bearing one-liners | Remaining findings are explainable, and future agents have a checklist for what not to "fix" mechanically. | Accepted. This keeps the rule useful while avoiding contract churn. |

## Reversibility

Two-way door. A future API cleanup can remove an exempt method if it updates all callsites and tests in one batch, preserves emitted analyser output, and updates this ADR. Revisit this decision if the remaining exemption count grows above 25 or if a public helper becomes unused.

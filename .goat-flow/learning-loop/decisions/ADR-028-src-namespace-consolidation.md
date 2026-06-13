# ADR-028: Source Namespace Consolidation

**Status:** Implemented
**Date:** 2026-06-13

## Context

Before M05, `src/` exposed 19 direct application directories: `Analysis`, `Baseline`, `Cache`, `Command`, `Config`, `Console`, `Diff`, `Finding`, `Hook`, `Mutation`, `Parser`, `Project`, `Reporting`, `Review`, `Rule`, `Scoring`, `Source`, `Support`, and `Trend`.

The Composer package boundary did not require that flat layout. `composer.json` maps `GruffPhp\` to `src/`, so internal namespaces can move without changing the package root, binary name, CLI command names, rule IDs, schema versions, baseline shape, or report payload keys.

## Decision

Keep the Composer PSR-4 root as `GruffPhp\ => src/` and consolidate internal application source into six direct package areas:

- `src/Cli/` for the Symfony Console application, command handlers, dashboard server, and CLI orchestration.
- `src/Engine/` for analysis input, parsing, configuration, source discovery, project facts, cache, and analysis report aggregation.
- `src/Rules/` for `RuleRegistry`, rule contracts, shared rule helpers, and concrete rule families.
- `src/Results/` for finding, scoring, baseline, diff, review, trend, and mutation result payloads.
- `src/Output/` for report renderers and coding-agent hook output.
- `src/Support/` for cross-package helpers.

Rule contracts live under `src/Rules/Contracts/`, reusable rule helpers live under `src/Rules/Shared/`, and concrete rule family directories remain split under `src/Rules/<Family>/`.

Tests remain grouped by behaviour under the existing `tests/` directories. They import the moved production namespaces but do not mirror the production package tree one-to-one.

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| Keep the 19 direct `src/` folders | The source root remains harder to scan, and unrelated runtime concerns look equally top-level. | Rejected. The flat layout obscures real boundaries now that the analyser has CLI, engine, rule, result, and output subsystems. |
| Rename the Composer root from `GruffPhp\` | Every consumer import changes, and the package root churn is unrelated to the internal structure problem. | Rejected. `composer.json` already gives enough room for internal namespace movement. |
| Collapse rule families into fewer folders | Rule ownership and rule-rubric review paths become less legible. | Rejected. The top-level folder count was the problem, not the family split. |
| Group into `Cli`, `Engine`, `Rules`, `Results`, `Output`, and `Support` | Namespace imports move, but runtime boundaries become visible without changing public CLI or report contracts. | Accepted. Focused PHPUnit batches, `composer dump-autoload`, CLI smoke checks, and rule snapshot tests verified the moved source still loads and preserves rule metadata. |

## Reversibility

This is a reviewable structural change, not a public package rename. It can be reverted by moving files back to the previous directories and rewriting namespaces/imports, but doing so should require evidence that the grouped layout blocks maintenance or creates recurring navigation mistakes.

Do not use future rule-rubric or reporter behaviour changes as a reason to reopen this ADR. Behaviour changes need their own decision if they alter rule IDs, schemas, scoring, or emitted findings.

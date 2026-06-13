# ADR-027: Retire modernisation enum-candidate

**Status:** Accepted
**Date:** 2026-06-13
**Author(s):** Matthew Hansen (decision), Codex (record)
**Ticket/Context:** 0.4.1 rule-rubric precision hardening

## Decision

Delete `modernisation.enum-candidate` from gruff-php completely. Do not keep the rule disabled, do not keep backward-compatible config handling for the rule id, and do not replace it with a narrower enum-migration advisory in the default catalogue.

Future enum migrations belong in deliberate project work: consumer grep, serialisation checks, boundary audits, and tests. Gruff should not report constant-only scalar classes as default enum opportunities.

## Context

A Symfony/PHP 8.3 scan of `src/App/ExternalProvider/AI/PracticeAssistant` produced three modernisation advisories, all from `modernisation.enum-candidate`, on constant classes:

- `Constants/AnswerMode.php`
- `Constants/ChatQuestionSource.php`
- `Constants/PracticeAssistantTab.php`

The finding was technically plausible: the classes only contained scalar constants and could be represented as PHP 8.1 backed enums. The project-owner decision is that this advice is not valuable enough for a default rubric. These constants can be string payload values across PHP, Twig/TypeScript, telemetry, JSON, and Python/agent boundaries. Converting them to enums is not a low-risk cleanup unless every consumer boundary is audited.

This also fits gruff-php's mission: findings should represent code a human can verify and trust. A broad "could be enum" advisory asks for a migration whose correctness depends on external consumers that the single-file rule does not inspect.

## Consequences

- `modernisation.enum-candidate` is removed from `RuleRegistry`.
- `src/Rules/Modernisation/EnumCandidateRule.php` is deleted.
- `list-rules` no longer reports the rule.
- `.gruff-php.yaml` generated defaults no longer include the rule.
- No enum-specific compatibility shim is added. Existing config behaviour still applies: stale `rules.modernisation.enum-candidate` blocks warn and are ignored, while strict selection paths reject unknown rule ids.
- Documentation and rule-count stamps move from 129 to 128 total rules, and from 10 to 9 modernisation rules.
- Constant-only scalar classes no longer produce enum-modernisation findings.

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| Keep the advisory | It continues to produce low-value findings for payload constants whose safe migration requires cross-language and serialisation audits. | Rejected. The rule is not valuable enough to stay in the catalogue. |
| Disable by default | The rule id and docs still advertise a migration heuristic the project owner does not want gruff to own. | Rejected. This preserves catalogue clutter without useful signal. |
| Keep the rule but add boundary heuristics | The risky part is external consumers, not the class shape. Static shape heuristics cannot know whether a constant is a JSON/Twig/TS/telemetry boundary value. | Rejected. It invites false confidence. |
| Delete the rule completely | Removes the noisy advisory and forces enum migrations to be explicit project work with consumer audits. | Accepted. This keeps gruff focused on findings with stronger verification value. |

## Reversibility

Two-way door, but only with new evidence. Reintroduce an enum migration rule only if it can prove the target type is internal-only or ships with a configuration model that makes boundary ownership explicit. A future rule should use a new ADR and may reuse the old id only if backward compatibility requirements are intentionally restored.

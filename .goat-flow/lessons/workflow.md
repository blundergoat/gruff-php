---
category: workflow
last_reviewed: 2026-05-11
---

# Workflow Lessons

## Lesson: Never run `git commit` or `git push`

**Created:** 2026-05-10

**What happened:** After the user authorised "verify and commit as 2 focused commits" via an in-conversation question, the agent ran `git commit -F <file>`. The harness denied the call. The agent then asked clarifying questions and re-attempted the same call, which was denied again. The user committed the staged changes themselves and made it explicit that coding agents must never run `git commit` (and equally never `git push`), regardless of any in-conversation authorisation, AskUserQuestion answer, or prior approval in any other session.

**Root cause:** Treating conversational authorisation ("commit it as two focused commits") as authority to invoke `git commit` directly. Conversational authorisation lets the agent prepare a commit (stage files, draft message, run checks); it does not grant the agent permission to call the commit tool itself. Harness-level deny on `git commit` / `git push` is the durable rule, and the agent missed that signal twice in a row.

**Prevention:**
- Never invoke `git commit` or `git push` via Bash. Treat both as user-only operations forever, in this repo and in every other repo, for every session. There is no scenario in which the agent should run these.
- When changes are ready to commit: stage with `git add <files>`, run the agreed checks (`composer check` / `composer test` / etc.), and write the commit message to `.goat-flow/scratchpad/commit.md`. Then tell the user the message is staged and stop. Do not retry, do not propose a workaround, do not suggest the user use `! git commit ...` — they will commit themselves whichever way they prefer.
- If the harness denies `git commit` or `git push` once, treat it as a permanent signal for the rest of the session and never attempt either again. Do not interpret silence or further user instructions as a re-authorisation.
- If you want a permission-layer enforcement, suggest `Bash(git commit:*)` and `Bash(git push:*)` entries under `permissions.deny` in `.claude/settings.json` — but do not edit that file without explicit user instruction.

## Lesson: Tick the per-task checkboxes when flipping a plan to complete

**Created:** 2026-05-10

**What happened:** Three milestone plans (`.goat-flow/tasks/0.1/M19-performance-and-rule-quick-wins.md`, `M20-phpstan-style-baseline-workflow.md`, `M21-sensitive-data-rename.md`) were marked `Status: complete` with evidence blocks at the top, but every `- [ ]` checkbox inside their `## Assumptions`, `## Tasks`, and `## Testing Gate` sections was left unticked. The user had to catch the inconsistency. After the prompt, `sed -i 's|^- \[ \]|- [x]|g'` ticked 21 + 21 + 25 boxes across the three files.

**Root cause:** Treating the top-of-file `Status:` line as the single source of truth for milestone completion. The status line is a summary; the per-task checkboxes are the audit trail. Skipping them leaves a future reader unable to tell which individual tasks were done, deferred, or silently dropped — and it falsely signals that the plan was never executed even when the work shipped.

**Prevention:** Whenever a plan's `Status:` field flips to `complete` (or any task's status changes), tick every `- [ ]` line in `## Assumptions`, `## Tasks`, and `## Testing Gate` that the work actually covered before claiming the milestone done. If a checkbox cannot be ticked, leave it unchecked and add a one-line note explaining why — `Status: complete` with mixed checkboxes is still valid, but only when the unticked items are deliberate. Use `sed -i 's|^- \[ \]|- [x]|g' <plan>` for whole-plan completion or edit individual lines for partial progress. Verify with `grep -c '^- \[x\]' <plan>` before claiming done.

## Lesson: Adding a rule cascades through fixtures, goldens, and existing tests

**Created:** 2026-05-11 (M31)

**What happened:** M31 added six new rules: `modernisation.phpdoc-mixed-overuse` (phase 1) and four `docs.missing-*-phpdoc` rules plus `design.single-implementor-interface` (phases 2 + 3). After phase 2 implementation the test suite went red with seven failures even though the new rules' own unit tests passed: existing fixtures in `tests/Fixtures/Source/Code/OrderCalculator.php` and `tests/Fixtures/Source/mixed/alpha.php` had no class-level or file-level docblocks, so the new docs rules added findings the existing CLI/registry tests did not expect (baselined count `1` became `3`, the RuleRegistry test's expected `lines: 19` became `26` after the docblock added 7 lines, golden snapshots stopped matching). Each fixture had been deliberately authored to fire exactly one rule for the older tests. The fix was per-fixture: add docblocks to keep the originally-targeted rule the only one firing, regenerate the goldens (`text-warning.txt`, `json-warning.json`), and update inline line-number expectations in `tests/Console/GruffCliTest.php` and `tests/Rule/RuleRegistryTest.php`.

**Root cause:** Fixtures encoded "this file produces exactly N findings" as an implicit invariant of the test, not just of the fixture. Adding rules that legitimately fire on those fixtures broke that invariant. Goldens are even more brittle: they bake in line numbers, file lengths, and grade-pillar scores that shift the moment a new finding is recorded.

**Prevention:** When adding a new rule, plan for three side-effects beyond the rule's own test:
1. **Existing fixtures that should still produce N findings.** Audit every fixture under `tests/Fixtures/` for files that could newly fire the rule, and add the minimum decoration (docblock, type hint, parameter rename) needed to preserve the test's invariant. Touch the fixture, not the test, so the test's narrative ("baseline workflow with one finding") survives.
2. **Goldens under `tests/Fixtures/Cli/Golden/`.** They will need regeneration: `php bin/gruff analyse <fixture> --config <golden's config> --format text > tests/Fixtures/Cli/Golden/<name>.txt` (same for `--format json`). Verify the diff is exactly the new rule's contribution and no stray drift.
3. **Inline expected-value assertions in `tests/Console/` and `tests/Rule/`.** Search for the affected fixture's filename plus literal numbers (line counts, line numbers) before assuming the file is unaffected. The `RuleRegistry::analyse` finding count and the file-length metadata `lines` value drift even when no test directly mentions the new rule.

For the dogfood snapshots also expect new findings on the gruff source tree itself (new rule fires on the new rule files) and on test files that don't have docblocks. Those are signal, not regression, as long as every diff is contained to files added in the same PR.

## Lesson: Respect explicit rule style even when it restates native syntax

**Created:** 2026-05-11

**What happened:** During healthkit dogfooding, the user asked why private helper docblocks without `@return` were not being flagged. The agent initially pushed back that adding bare `@return string` / `@return bool` tags restated native signatures and created comment noise. The user clarified that gruff's project standard is stricter: `docs.missing-return-tag` must catch every documented method/function without `@return`, including private helpers.

**Root cause:** The agent applied a general PHPDoc minimalism preference instead of treating the user's explicit rule standard as the source of truth for this analyser. gruff is an opinionated scanner; some rules intentionally require documentation that another project might consider redundant.

**Prevention:** When the user specifies a rule standard, implement and verify that standard directly. Do not soften it based on generic style guidance unless the user asks for trade-offs. For PHPDoc rules in this repo, preserve the rule contract in tests using examples from both public methods and private helpers so future agents cannot narrow behavior back to "public contract only" or "only when the native signature is insufficient."

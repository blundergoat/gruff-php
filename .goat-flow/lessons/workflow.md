---
category: workflow
last_reviewed: 2026-05-10
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

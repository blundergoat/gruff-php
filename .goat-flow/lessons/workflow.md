---
category: workflow
last_reviewed: 2026-05-10
---

# Workflow Lessons

## Lesson: Tick the per-task checkboxes when flipping a plan to complete

**Created:** 2026-05-10

**What happened:** Three milestone plans (`.goat-flow/tasks/0.1/M19-performance-and-rule-quick-wins.md`, `M20-phpstan-style-baseline-workflow.md`, `M21-sensitive-data-rename.md`) were marked `Status: complete` with evidence blocks at the top, but every `- [ ]` checkbox inside their `## Assumptions`, `## Tasks`, and `## Testing Gate` sections was left unticked. The user had to catch the inconsistency. After the prompt, `sed -i 's|^- \[ \]|- [x]|g'` ticked 21 + 21 + 25 boxes across the three files.

**Root cause:** Treating the top-of-file `Status:` line as the single source of truth for milestone completion. The status line is a summary; the per-task checkboxes are the audit trail. Skipping them leaves a future reader unable to tell which individual tasks were done, deferred, or silently dropped — and it falsely signals that the plan was never executed even when the work shipped.

**Prevention:** Whenever a plan's `Status:` field flips to `complete` (or any task's status changes), tick every `- [ ]` line in `## Assumptions`, `## Tasks`, and `## Testing Gate` that the work actually covered before claiming the milestone done. If a checkbox cannot be ticked, leave it unchecked and add a one-line note explaining why — `Status: complete` with mixed checkboxes is still valid, but only when the unticked items are deliberate. Use `sed -i 's|^- \[ \]|- [x]|g' <plan>` for whole-plan completion or edit individual lines for partial progress. Verify with `grep -c '^- \[x\]' <plan>` before claiming done.

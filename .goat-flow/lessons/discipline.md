---
category: discipline
last_reviewed: 2026-05-31
---

# Discipline

Lessons about honest progress tracking and not cutting corners.

## Lesson: Tick task checkboxes the moment the item is done, never in a batch at the end

**Created:** 2026-05-31

**Trigger:** Working a `.goat-flow/tasks/` milestone (or any file with `- [ ]` items) across multiple steps.

**Do:** The instant a checklist item is genuinely done and verified, flip its `- [ ]` to `- [x]` in the *same* edit that finishes the work. A ticked box must be a fact that was true when you ticked it.

**Never:** Leave the boxes unticked and lean on a `Status: complete` + Close-out section instead, then retro-tick in bulk (`sed 's/- \[ \]/- [x]/g'`). A blanket tick cannot tell *done* from *deferred*, so it marks deferred work complete — a false-completion claim, the exact failure gruff-php exists to prevent.

**When you defer or skip an item:** leave it `- [ ]` and say why on the line or in a Deferred note. Unchecked is honest; a wrong check is a lie. When unsure whether an item is truly done, leave it unchecked.

**What this cost (2026-05-31):** Finished M06–M15 marking only Status + Close-out, every box left unticked. Asked to tick them, I blanket-`sed`-ticked — which falsely marked M09's deferred config-subcommand + `extendsChain` items and M13's deferred FP-guard fixtures as done, and I had to detect and hand-revert them. Ticking as I went would have made the bulk pass unnecessary and impossible to get wrong.

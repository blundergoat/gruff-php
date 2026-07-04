---
category: verification
last_reviewed: 2026-07-04
---

# Verification Patterns

## Pattern: Prove a comment-only edit with a whitespace-insensitive diff against the pre-edit baseline

**Created:** 2026-07-04

**Context:** When rewriting comments/docblocks across many `src/` files, each edit must be proven to change *only* comments, never code. The obvious check — a strict byte-level diff of the working file against its pre-edit baseline (`git show <baseline>:<path>`), filtered to non-comment lines — produces **false positives** in this repo. An external format-on-save / `php-cs-fixer` step (the user's editor or tooling — **not** the read-only `gruff-code-quality` PostToolUse hook, which only analyses and never writes) re-aligns `=`/`=>` assignment blocks in any edited file. That reformat diverges the working file from the committed baseline in whitespace only (e.g. `$scoreStart     =` → `$scoreStart =`), and the harness marks it intentional ("modified by a linter … don't revert"). A strict non-comment diff then reports phantom "code changes."

**Approach:** Gate on a whitespace-insensitive *semantic* diff, computing two numbers and passing when **either** is zero:
- `codeDelta` = strict non-comment diff vs baseline (byte-level). `codeDelta == 0` is the strongest possible guarantee: every non-comment line is byte-identical.
- `semDelta` = `diff -w` non-comment diff vs baseline (ignores alignment/whitespace). `semDelta == 0` means the only non-comment differences are cosmetic whitespace.

Pass when `codeDelta == 0 OR semDelta == 0`; only **both-nonzero** is a real semantic code change worth inspecting. Both are needed because `diff -w` can re-align hunks around freshly *inserted* comment lines and spuriously pair identical `if`/code lines as "changed" (observed: `ListRulesCommand.php` reported `semDelta=4` on untouched `if (is_array($value))` lines while `codeDelta=0` proved the code byte-identical). So `codeDelta == 0` must independently pass the gate, and the offending-line dump should print only when both are nonzero.

The canonical implementation is the session's `scratchpad/gate.sh`. Alongside the code-delta check it asserts: `php -l` clean; `@param`/`@return`/`@throws` counts unchanged vs baseline (catches a dropped/renamed tag); file `< 1000` lines (`size.file-length`/`size.class-length` are ERROR and count comment lines raw); `php bin/gruff-php analyse <file> --fail-on advisory` exit 0 (a comment can still trip `docs.regex-comment` or `waste.commented-out-code`); and no templated-junk residue. Run it yourself over every file — do not trust a fan-out author agent's self-reported pass (see `.goat-flow/learning-loop/lessons/workflow.md`).

**Reference:** the format-on-save divergence is why comment-only diffs need `diff -w`; leaving the alignment churn in place is acceptable (whitespace-only, house style) but means the final `git diff` shows minor `=`-alignment noise — offer to normalise it back to the baseline for a truly comments-only diff if the reviewer wants one.

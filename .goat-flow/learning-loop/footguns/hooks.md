---
category: hooks
last_reviewed: 2026-07-03
---

# Hook Footguns

## Footgun: Hook file-discovery and changed-range derivation must cover the same git states

**Status:** active | **Created:** 2026-06-04 | **Evidence:** OBSERVED

The gruff-code-quality hook discovers candidate files and then derives changed-line ranges for each through two independent git queries that must agree on which working states count as "changed". `git_changed_supported_paths` (search: `git_changed_supported_paths`) enumerates unstaged, staged (`git diff --cached --name-only`), and untracked paths, but range derivation in `git_diff_ranges` originally ran only `git diff --unified=0` (working-tree-vs-index, i.e. unstaged). A file with staged-only edits was therefore selected by discovery but produced empty ranges, so the hook skipped it with "no changed lines detected" — a silent false negative for exactly the files a pre-commit workflow stages.

**Evidence:** PR #8 review (Cursor Bugbot, "Staged paths without staged ranges"). Reproduction: in a repo with a staged-only change, the pre-fix `git_diff_ranges` returned empty; the fix diffs against `HEAD` (search: `Diff against HEAD so staged-only edits are scoped`), falling back to `git diff --cached` on an unborn branch with no HEAD, and now returns the staged ranges. The hook's own `--self-test=smoke` (search: `self-test=smoke`) and `bash -n` both pass after the change.

**Prevention:** Whenever you broaden which git states the hook *discovers* (staged, untracked, a specific ref), broaden range derivation to match in the same change, or files fall into a "selected but no ranges → skipped" gap. Diffing against `HEAD` covers staged+unstaged in one query and is the safe default; reserve `--cached`-only for the no-HEAD (unborn branch) case. Review the discovery query and the range query as a pair.

## Resolved Entries

## Footgun: `.claude` and `.codex` hook scripts are byte-identical duplicates that must move in lockstep

**Status:** resolved | **Created:** 2026-06-04 | **Resolved:** 2026-07-03 | **Evidence:** OBSERVED

`.claude/hooks/gruff-code-quality.sh` and `.codex/hooks/gruff-code-quality.sh` are maintained as byte-identical copies (verified with `diff` — no differences across the entire script), one per peer agent surface. They are intentionally NOT shared or symlinked — the project keeps `.claude/**` and `.codex/**` as distinct agent-owned surfaces — so any fix applied to one is invisible to the other unless mirrored. A change made to only one copy leaves the other agent running the old, buggy behaviour while tests and the self-test on the edited copy pass.

**Evidence:** PR #8 review surfaced this two ways: CodeRabbit flagged the duplication as a maintenance nitpick, and Copilot independently raised the same scope-header wording finding against BOTH copies. Every behavioural fix in PR #8 (staged ranges, timeout-message normalisation, multi-file range attribution, scope-header wording, two new self-test assertions) had to be applied to both files; the lockstep was confirmed with `diff .claude/hooks/gruff-code-quality.sh .codex/hooks/gruff-code-quality.sh` reporting identical.

**Resolution:** goat-flow 1.12.1 moved hook script ownership to the shared `.goat-flow/hooks/` store and agent config files now register those shared scripts (`.claude/settings.json`, `.codex/hooks.json`, and peer hook configs where supported). There are no tracked `.claude/hooks/` or `.codex/hooks/` scripts to mirror in this checkout.

**Prevention:** Maintain hook behavior in `.goat-flow/hooks/` and reconcile agent hook registrations through the goat-flow hook registry. Do not recreate per-agent hook script copies unless the manifest and installer explicitly reintroduce that storage model.

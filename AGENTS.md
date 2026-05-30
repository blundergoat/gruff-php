# AGENTS.md - project v1.5.1 / goat-flow 1.7.0 (2026-05-24)
gruff-php is an opinionated PHP code-quality analyzer; its mission is to govern AI-generated code so a human can verify, trust, and sign off on it (legible, secure, genuinely tested). Current invariant: keep app claims and commands grounded in real source/config files.

## Truth Order

1. User's explicit instruction in the current session
2. This instruction file
3. `.goat-flow/architecture.md`
4. `.goat-flow/code-map.md`
5. Skills and `.goat-flow/skill-playbooks/` on demand

## Autonomy Tiers

**Always:** Read files, inspect git status, run goat-flow audits, and edit `AGENTS.md`, `.codex/**`, `.agents/**`, and `.goat-flow/**` when asked to maintain Codex/goat-flow setup.

**Ask First:** Before changing `README.md`, deleting files, changing peer agent surfaces (`CLAUDE.md`, `.claude/**`), or adding application structure beyond the user's request, state the boundary, files read, learning-loop check, local instruction check, and rollback command.

**Never:** Invent PHP app commands, frameworks, services, incidents, footguns, or lessons. Do not commit, push, edit secrets, or run destructive git commands unless explicitly requested.

## Hard Rules

- If a file exists, modify it in place; do not create backup or `_new` variants.
- Keep app claims grounded in existing files. Current app/quality surface: `composer.json`, `composer.lock`, `bin/gruff-php`, `src/`, `tests/`, `phpunit.xml.dist`, `phpstan.neon.dist`, `.gruff-php.yaml`, `scripts/`, `package.json`, `package-lock.json`, and `.github/workflows/`.
- Route durable project knowledge to `.goat-flow/`; keep this hot-path file behavioral and concise.
- Use `apply_patch` for manual edits when available.
- Preserve cross-agent consistency between `AGENTS.md` and `CLAUDE.md` for shared goat-flow rules.
- Keep the controlling goat-flow workspace distinct from this selected target project when tools or prompts originate outside this checkout.

## Key Resources

- Learning loop: `.goat-flow/footguns/`, `.goat-flow/lessons/`, `.goat-flow/patterns/`, `.goat-flow/decisions/`
- Tool playbooks: `.goat-flow/skill-playbooks/browser-use.md`, `.goat-flow/skill-playbooks/page-capture.md`
- Orientation: `.goat-flow/architecture.md`, `.goat-flow/code-map.md`, `.goat-flow/glossary.md`

## Essential Commands

Application commands configured by `composer.json`:

```bash
git status --short --untracked-files=all
composer check
composer test
composer perf
php bin/gruff-php --help
php bin/gruff-php analyse
node node_modules/@blundergoat/goat-flow/dist/cli/cli.js audit . --agent codex
node node_modules/@blundergoat/goat-flow/dist/cli/cli.js audit . --agent codex --harness
```

## Execution Loop: READ -> SCOPE -> ACT -> VERIFY

When a goat-* skill is active, its Step 0 replaces READ and selects the skill mode/depth. Resume at ACT after Step 0 output.

### READ
Read relevant files before changes. For URL, local HTML, localhost, screenshot, rendered UI, or browser-visible behavior, check browser evidence first with `command -v browser-use || command -v browser-use-python`. Before declaring any tool or capability unavailable, read the matching playbook in `.goat-flow/skill-playbooks/` (e.g. `browser-use.md`, `page-capture.md`) and run that doc's "Availability Check" section verbatim - project-local CLI tools at `~/.local/bin/` are valid; do not conflate "no harness/MCP tool" with "no tool". Use grep-first retrieval across `.goat-flow/footguns/`, `.goat-flow/lessons/`, and `.goat-flow/patterns/`; include decisions for architecture, policy, or setup work.

### SCOPE
Declare files allowed to change, non-goals, and max blast radius before writes. Treat framework setup as limited to goat-flow artifacts and agent-owned config unless the user widens scope.

### ACT
State: `[MODE]` | Goal: `[one line]` | Exit: `[condition]`. Implement narrowly and prefer existing project patterns over new abstractions.

### VERIFY
Run relevant checks before claiming success. If no app commands exist, say that explicitly. For shell changes run `bash -n` or `shellcheck` when available. Do not claim checks passed without literal pass/fail output from this session.

**Hallucination red-flags:**
1. **Checks passed.** Do not claim tests pass or any check passed (composer check, shellcheck, audit) without showing the literal pass/fail line copied verbatim from this session's run. Paraphrase, cached output, or prior-session results do not count.
2. **Completion.** Do not claim completion without listing the specific files changed in this turn. If no files were changed, say so explicitly.
3. **Fix verification.** Do not claim a fix works without running the reproduction steps that originally demonstrated the bug. "Looks correct" is not verification.
4. **Hedged claims.** Do not use "should work", "probably fine", "looks good" as verification. These are guesses, not evidence.
5. **Rule paraphrase.** Do not weaken a rule by restating it with different words. Spirit over letter — paraphrases count as the same constraint.

Rationalisations to reject: see the Excuse / Reality table in `.goat-flow/skill-reference/skill-preamble.md`. If you catch yourself thinking the Excuse, run the proof or mark the claim **UNVERIFIED**.

## Definition of Done

- Changed files are listed.
- Relevant checks were run or explicitly skipped with reason.
- No broken router paths or stale references were introduced.
- Learning-loop updates were made only for real incidents or measured traps.
- No unapproved peer-agent or application-surface changes were made.

## Artifact Routing

Footguns go in `.goat-flow/footguns/<category>.md`; lessons in `.goat-flow/lessons/<category>.md`; decisions in `.goat-flow/decisions/ADR-NNN.md`; patterns in `.goat-flow/patterns/<category>.md`. Read the target directory README before adding artifacts.

## Router Table

| Resource | Path |
|----------|------|
| Codex instruction file | `AGENTS.md` |
| Claude peer instruction file | `CLAUDE.md` |
| Learning loop | `.goat-flow/footguns/`, `.goat-flow/lessons/`, `.goat-flow/patterns/`, `.goat-flow/decisions/` |
| Tool playbooks (CLI/MCP availability checks: browser-use, page-capture, skill-quality-testing) | `.goat-flow/skill-playbooks/` - read BEFORE declaring a tool unavailable |
| Orientation | `.goat-flow/architecture.md`, `.goat-flow/code-map.md`, `.goat-flow/glossary.md` |
| Codex skills/config | `.agents/skills/`, `.codex/config.toml`, `.codex/hooks.json`, `.codex/hooks/` |
| Claude skills/config | `.claude/skills/`, `.claude/settings.json`, `.claude/hooks/` |
| Local workspace notes | `.goat-flow/logs/sessions/`, `.goat-flow/tasks/`, `.goat-flow/scratchpad/` |
| Commit guidance | `.github/git-commit-instructions.md` |
| Project entry docs | `README.md` |
| Mission / philosophy | `docs/mission.md` (rationale); `ADR-017` (decision) |

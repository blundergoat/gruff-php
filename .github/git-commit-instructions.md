# Commit Instructions

- Do not commit unless the user explicitly asks.
- Keep commits focused on one logical change.
- Run relevant checks before committing; if no app checks exist, say so in the commit context instead of inventing one.
- Do not include secrets, local-only session logs, generated scratch files, or unrelated workspace changes.
- Use concise imperative subjects, for example `Install goat-flow for Claude and Codex`.
- Mention goat-flow setup changes explicitly when a commit changes `CLAUDE.md`, `AGENTS.md`, `.goat-flow/`, `.claude/`, `.codex/`, or `.agents/`.


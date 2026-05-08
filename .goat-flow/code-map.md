# Code Map - gruff-php

```text
.
|-- README.md = only project-owned app/documentation file currently present
|-- AGENTS.md = Codex root instruction file
|-- CLAUDE.md = Claude Code root instruction file
|-- .gitignore = local agent settings ignore rules
|-- .github/ = GitHub-facing repository guidance
|   `-- git-commit-instructions.md = commit message and commit safety rules
|-- .goat-flow/ = goat-flow harness, project memory, and reference docs
|   |-- config.yaml = goat-flow version and configured agents
|   |-- architecture.md = current system architecture notes
|   |-- code-map.md = this repository map
|   |-- glossary.md = project and harness terms
|   |-- footguns/ = durable architectural traps when real evidence exists
|   |-- lessons/ = durable behavioral lessons from real incidents
|   |-- patterns/ = reusable successful approaches
|   |-- decisions/ = ADRs when durable decisions exist
|   |-- skill-reference/ = shared goat-flow skill/tool playbooks
|   |-- tasks/ = local milestone/task workspace
|   |-- scratchpad/ = local temporary notes
|   `-- logs/ = local setup, quality, critique, and security logs
|-- .claude/ = Claude Code goat-flow setup
|   |-- settings.json = Claude hook/permission config
|   |-- hooks/deny-dangerous.sh = shell safety hook
|   `-- skills/ = seven installed goat-flow skills
|-- .codex/ = Codex goat-flow setup
|   |-- config.toml = Codex hooks feature config
|   |-- hooks.json = Codex PreToolUse hook registration
|   `-- hooks/deny-dangerous.sh = shell safety hook
`-- .agents/ = shared Codex/Gemini skill root
    `-- skills/ = seven installed goat-flow skills for Codex
```

No `src/`, `tests/`, `composer.json`, `vendor/`, or CI directory exists yet.

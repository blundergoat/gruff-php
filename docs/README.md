# gruff-php docs

Use these docs with the top-level README for the stable user-facing surface.

## Core Docs

- [Configuration](configuration.md) - config discovery, schema, allowlists, selection, and rule overrides.
- [Rules](rules.md) - rule IDs, severities, thresholds, and remediation guidance.
- [Output Formats](output-formats.md) - text, JSON, HTML, Markdown, GitHub annotations, hotspot, and SARIF.
- [CI Integration](ci-integration.md) - GitHub Actions, SARIF upload, baselines, and diff scans.
- [Dashboard](dashboard.md) - local dashboard flags and safety model.
- [Releasing](releasing.md) - release checks and packaging notes.

## Extra Docs

- [Naming Conventions](naming-conventions.md) - cross-project naming notes that complement the workspace contract.
- [CLI Agent Instructions](gruff-cli-agent-instructions.md) - agent-oriented PHP workflow.
- [CLI Summary](gruff-cli-summary.md) - PHP CLI summary notes.
- [Branch Review](gruff-cli-branch-review.md) - branch-review workflow notes.

## Shared Contract

Cross-language naming and CLI expectations are summarized in
[Naming Conventions](naming-conventions.md#shared-contract). PHP keeps
documented extensions for mutation and Infection workflows.

# gruff-php Documentation

Start with the [top-level README](../README.md) for installation and quick start, then use these guides for configuration, rules, and workflows.

## Core Guides

- [Mission](mission.md) - why `gruff-php` exists and what its rules optimise for.
- [Configuration](configuration.md) - config discovery, schema, presets, allowlists, selection, sensitive exclusions, and rule overrides.
- [Rules](rules.md) - built-in rule catalogue, severities, confidence evidence, thresholds, and false-positive guidance.
- [Output Formats](output-formats.md) - text, JSON, HTML, Markdown, GitHub annotations, hotspot, and SARIF.
- [CI Integration](ci-integration.md) - GitHub Actions, SARIF upload, baselines, diff scans, and `check-ignore`.
- [Dashboard](dashboard.md) - local browser dashboard flags and safety boundary.
- [Upgrading](../UPGRADING.md) - what each release line breaks, migration steps, and how to roll back.

## Workflows and Reference

- [CLI Summary](gruff-cli-summary.md) - compact terminal and JSON digests with `gruff-php summary`.
- [CLI Agent Instructions](gruff-cli-agent-instructions.md) - command quick-start for coding agents.
- [Branch Review](gruff-cli-branch-review.md) - diff-base comparison workflow for reviewing introduced findings.
- [Naming Conventions](naming-conventions.md) - cross-port rule ID and config naming conventions.

## Maintainer Docs

- [Releasing](releasing.md) - maintainer-only release checks, version bumps, and packaging notes.

## Shared Contract

Cross-language naming and CLI conventions are summarized in
[Naming Conventions](naming-conventions.md#shared-contract). PHP keeps
documented extensions for mutation and Infection workflows.

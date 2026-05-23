# Changelog

All notable changes to `gruff-php` are documented here. Follows
[Keep a Changelog](https://keepachangelog.com/) and semantic versioning.
Development builds report `0.1.0-dev` until `scripts/bump-version.sh` stamps
the tag.

## 0.1.0 - Unreleased

First public release.

- 120 rules across 11 pillars (size, complexity, maintainability, dead-code,
  naming, documentation, modernisation, security, sensitive-data, test-quality,
  design). Run `php bin/gruff-php list-rules` to inspect.
- Commands: `analyse`, `summary`, `report`, `dashboard`, `list-rules`.
- Output formats: `text`, `json`, `html`, `markdown`, `github`, `hotspot`,
  `sarif`. Stable schemas: `gruff.analysis.v1`, `gruff.summary.v1`,
  `gruff.baseline.v1`.
- YAML config (`.gruff-php.yaml`), baselines, branch-review (`--diff`,
  `--diff-vs`, `--changed-only`), opt-in Infection mutation analysis, and a
  local dashboard.
- PHP `^8.3`, MIT licensed.

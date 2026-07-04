# Glossary - gruff-php

Last reviewed 2026-05-24.

This glossary defines terms used by `gruff-php`, its public reports, and local project memory. Keep shared gruff-family terms aligned with the sibling implementations; keep PHP-specific differences explicit rather than making them look identical.

## Scope

`gruff-php` is the PHP implementation of the gruff quality-scanner family. The Composer package is `blundergoat/gruff-php`; the CLI binary is `bin/gruff-php` from a checkout or `vendor/bin/gruff-php` when installed; product code lives under `src/` with tests under `tests/`.

## Shared Gruff Terms

### Analysis Report

The complete result of one scan: schema version, tool metadata, run metadata, paths, summary counts, score data, diagnostics, findings, baseline state, and optional diff/review/mutation state. Native JSON uses `gruff.analysis.v2`.

### Baseline

A reviewed-finding suppression file. `gruff-php` writes and reads `gruff.baseline.v2`: grouped count rows keyed by `(file, ruleId, message)`, matched by count arithmetic so accepted findings stay suppressed across line shifts without disabling rules.

### Changed-Code Scan

A scan filtered to changed lines or files. `--diff` filters current findings through local Git diff output; `--diff-vs=<base>` compares current findings against a base ref.

### Confidence

The certainty tier attached to a finding: `low`, `medium`, or `high`. It helps scoring and reviewers distinguish high-signal findings from heuristic prompts.

### Dashboard

The local browser UI served by `gruff-php dashboard`. It binds to 127.0.0.1:8765 by default and has no authentication; use `--port` when another gruff dashboard is already using the port.

### Diagnostic

A run-level problem such as a usage error, config error, missing path, parse error, mutation error, diff error, baseline error, or history error. Fatal diagnostics force exit code `2`.

### Display Filter

A report-only filter such as `--min-severity`, include/exclude pillar, or include/exclude rule. Display filters change rendered output, not rule execution, scoring, or baseline generation.

### Exit Codes

`0` means the run completed and no finding met the failure threshold. `1` means at least one finding met the threshold. `2` means a fatal diagnostic or invalid input stopped the requested scan from being fully trustworthy.

### Finding

One rule-produced result with rule ID, message, severity, confidence, pillar, location, remediation, metadata, and fingerprint.

### Fingerprint

A stable 16-character hash derived from finding identity fields. Baselines and downstream tooling key on it together with rule ID and file path.

### Gruff Config

Project configuration that tunes discovery, allowlists, rule selection, and per-rule thresholds/severity/options. Shared keys are `paths.ignore`, `allowlists.acceptedAbbreviations`, `allowlists.secretPreviews`, `selection`, and `rules.<id>`.

### Hotspot Output

A compact JSON view of the worst file offenders for dashboards or trend tooling. `gruff-php` emits it with `--format hotspot`.

### Output Format

A renderer over the same analysis report. `analyse` supports `text`, `json`, `html`, `markdown`, `github`, `hotspot`, and `sarif`; `report` supports `html` and `json`.

### Pillar

The quality dimension a finding belongs to, such as `complexity`, `security`, `sensitive-data`, or `test-quality`. Pillars feed per-pillar scoring and display filters.

### Rule Catalogue

The set of built-in rules plus their public metadata. `list-rules --format json` is the source of truth for rule IDs, pillars, severity, confidence, thresholds, options, and default enablement.

### Rule ID

Stable public identifier for one rule, using dotted gruff-family names such as `size.method-length`, `docs.missing-param-tag`, and `sensitive-data.high-entropy-string`. Some dead-code pillar rules retain `waste.*` IDs for historical continuity.

### SARIF

Static Analysis Results Interchange Format. `gruff-php` emits SARIF 2.1.0 from the same report data used by the other renderers.

### Score And Grade

The numeric and letter quality summary derived from findings after baseline and filter layers have been applied according to the current command.

### Secret Preview

A redacted representation of sensitive-data matches. Raw secret values must not appear in terminal, JSON, SARIF, GitHub, Markdown, hotspot, or HTML output.

### Severity And Failure Threshold

`gruff-php` uses `advisory`, `warning`, and `error`. `--fail-on` controls exit code `1`; `none` reports findings without failing for severity.

### Source Discovery

The process that turns input paths into classifiable PHP or text files. `paths.ignore` always applies; `--include-ignored` opts into default-ignored paths for deliberate inspection.

### Trust Boundary

Default scans are local source inspections. `gruff-php` parses files and may call Git for explicit diff scans; it does not run target application code, run tests, query vulnerability feeds, or contact package registries. Explicit mutation options may run Infection.

## Implementation-Specific Terms

### Symfony Console Application

`src/Cli/Application.php` registers the CLI commands: `analyse`, `check-ignore`, `dashboard`, `hook`, `init`, `list-rules`, `report`, and `summary`, plus Symfony-provided help/list/completion behavior.

### PHP Parser

`src/Engine/Parser/PhpFileParser.php` wraps `nikic/php-parser`. PHP files get AST nodes and parser diagnostics; non-PHP text files are read for text-oriented rules and do not have AST/tokens.

### Analysis Unit

Per-file parsed state containing the source file, raw source text, AST statements, tokens, and parse diagnostics. Rules operate on analysis units.

### Minimum PHP Version

`minimumPhpVersion` in `.gruff-php.yaml`, defaulting to the implementation's configured minimum. Modernisation rules use it to avoid recommending syntax unsupported by the target project.

### Branch Review Mode

The `--diff-vs=<base>` workflow. It compares current findings with a Git archive snapshot of a base ref and can be narrowed to changed files with `--changed-only`.

### Infection Report

The JSON output from Infection's reporter. `gruff-php` can ingest it with `--infection-report` or run Infection explicitly with `--infection-run`.

### Mutation Findings

Findings derived from Infection data rather than the normal rule registry, such as survived mutants, mutation-budget breaches, and MSI regressions.

### Test Quality Scope

Detected PHPUnit methods or Pest `it()` / `test()` closures. Test-quality rules inspect these scopes so production code is not flagged for test-only smells.

### Trend History

Optional score-history output enabled by `--history-file`. It appends bounded JSON entries for score, grade, and finding-count trends.

## Agent Workflow Terms

### GOAT Flow

Local agent workflow framework installed from `@blundergoat/goat-flow`. It provides skills, audit commands, safety references, and `.goat-flow/` project-memory directories.

### Agent-Owned Surface

Files one agent setup owns without widening scope. Claude owns `CLAUDE.md` and `.claude/**`; Codex owns `AGENTS.md` and `.codex/**`; shared agent skills live under `.agents/skills/**`.

### Learning Loop

Durable shared project-memory directories under `.goat-flow/learning-loop/footguns/`, `.goat-flow/learning-loop/lessons/`, `.goat-flow/learning-loop/patterns/`, and `.goat-flow/learning-loop/decisions/`.

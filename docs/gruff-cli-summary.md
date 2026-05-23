# `gruff-php summary`

A compact digest of a scan. Runs the analyser once, aggregates by pillar and rule, and prints a single readable block - **no per-finding spam**.

Use it when:

- The `analyse` text output is too long to read at a glance.
- You're dogfooding new rules and want to see "where is the noise concentrated?" without scrolling.
- A CI step needs a one-glance score breakdown without rendering the full HTML or JSON report.
- You want a small JSON shape to feed into another tool (schema: `gruff.summary.v1`).

## Usage

```bash
php bin/gruff-php summary [paths...] [options]
```

`paths` defaults to whatever you pass; if empty, the analyser discovers from the project root just like `analyse`.

### Options

| Option | Default | Purpose |
|---|---|---|
| `--config=PATH` | auto-discover `.gruff-php.yaml` (legacy `.gruff.yaml`) | Use a specific config file. |
| `--no-config` | off | Skip the auto-discovered config for this run; built-in defaults only. Cannot combine with `--config`. |
| `--format=text\|json` | `text` | `text` is the human digest, `json` is `gruff.summary.v1` for tooling. |
| `--top=N` | `10` | Cap the "Top N rules" and "Top N file offenders" sections. |
| `--include-ignored` | off | Scan ignored files by using filesystem traversal instead of Git/default ignores. |

## Example - text format

The example output below was captured from a development checkout. A tagged
release prints `0.1.0` instead of `0.1.0-dev`.

```bash
php bin/gruff-php summary tests/Fixtures/Source/mixed --no-config --top=3
```

```
gruff-php 0.1.0-dev - summary

Paths     tests/Fixtures/Source/mixed
Config    (none)
Files     2 discovered, 2 parsed, 6 ignored, 0 missing, 0 parse errors

Composite A (95.50 / 100)
Scope     full-project
Score note Per-pillar scores start at 100 and subtract weighted finding penalties; the composite is the average of applicable pillar scores. Mutation is omitted when no Infection report is supplied.

Pillars
  naming          D  65.00 findings=3     advisory=1     warning=2     error=0
  documentation   A  90.00 findings=3     advisory=3     warning=0     error=0
  size            A 100.00 findings=0     advisory=0     warning=0     error=0
  ...

Top 3 rules by finding count
      2  naming.class-file-mismatch      naming  a=0 w=2 e=0
      1  docs.missing-class-phpdoc       documentation  a=1 w=0 e=0
      1  docs.missing-constant-phpdoc    documentation  a=1 w=0 e=0

Top 2 file offenders
  D   67.50  tests/Fixtures/Source/mixed/nested/beta.php  findings=4    a=3 w=1 e=0
  C   76.25  tests/Fixtures/Source/mixed/alpha.php        findings=2    a=1 w=1 e=0

Totals    6 findings (advisory=4, warning=2, error=0)
```

Pillars are ordered by finding count (loudest first). Pillars with zero findings still appear so it's obvious which are clean.

## Example - JSON format

```bash
php bin/gruff-php summary src --format=json --top=5
```

```json
{
  "schemaVersion": "gruff.summary.v1",
  "tool": { "name": "gruff-php", "version": "0.1.0-dev" },
  "scope": {
    "paths": ["src"],
    "configPath": "/home/devgoat/projects/gruff-workspace/gruff-php/.gruff-php.yaml",
    "filesDiscovered": 237,
    "filesParsed": 237,
    "ignoredPaths": 0,
    "missingPaths": 0,
    "parseErrors": 0,
    "scope": "full-project"
  },
  "composite": { "score": 89.7, "grade": "B" },
  "findings": { "advisory": 217, "warning": 0, "error": 0, "total": 217 },
  "pillars": [
    { "pillar": "documentation", "grade": "B", "score": 78.55, "findings": 216, "advisories": 216, "warnings": 0, "errors": 0, "penalty": 21.45, "applicable": true },
    ...
  ],
  "topRules": [
    { "ruleId": "docs.bare-phpdoc-tags", "count": 203, "advisory": 203, "warning": 0, "error": 0, "pillar": "documentation" },
    ...
  ],
  "topOffenders": [
    { "file": "src/Rule/Naming/IdentifierQualityRule.php", "score": 55, "grade": "F", "findings": 12, "advisories": 12, "warnings": 0, "errors": 0, "penalty": 45, "maxCyclomatic": null, "maxCognitive": null, "maxLines": null, "mutationScore": null },
    ...
  ]
}
```

The schema is versioned for the public package. New top-level keys may be
added in compatible releases; existing keys should not be renamed or change
shape without bumping the schema version.

## What this is *not*

- Not a `analyse` replacement - there's no per-finding list, no remediation hints, no diff/baseline interaction, no mutation analysis, no HTML rendering. Use `analyse` (with `--min-severity`, `--include-rule`, etc.) when you need full findings.
- Not faster scanning - it runs the full rule registry. The "speed" is in reading the output, not the scan.
- Not dashboard-server cached - every invocation rescans. Pipe it into a script if you want repeated lookups.

## Exit codes

| Code | Meaning |
|---|---|
| `0` | Summary printed successfully. |
| `1` | Reserved for future "failure on threshold" support if added. Today the command never returns `1`. |
| `2` | Usage error: bad `--format`, non-integer `--top`, `--config` combined with `--no-config`, or config load failure. |

`summary` deliberately does **not** honour `--fail-on`. It is a read-only digest; use `analyse --fail-on=warning` if you want CI to fail on the same data.

## See also

- [`gruff-cli-agent-instructions.md`](gruff-cli-agent-instructions.md) - for agents wrapping the CLI.
- [`gruff-cli-branch-review.md`](gruff-cli-branch-review.md) - diff-aware review workflow.
- [`README.md`](../README.md) - main project overview.
- [`src/Command/SummaryCommand.php`](../src/Command/SummaryCommand.php) - source of truth.

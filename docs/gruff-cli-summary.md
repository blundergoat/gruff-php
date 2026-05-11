# `gruff summary`

A compact digest of a scan. Runs the analyser once, aggregates by pillar and rule, and prints a single readable block — **no per-finding spam**.

Use it when:

- The `analyse` text output is too long to read at a glance.
- You're dogfooding new rules and want to see "where is the noise concentrated?" without scrolling.
- A CI step needs a one-glance score breakdown without rendering the full HTML or JSON report.
- You want a small JSON shape to feed into another tool (schema: `gruff.summary.v1`).

## Usage

```bash
php bin/gruff summary [paths...] [options]
```

`paths` defaults to whatever you pass; if empty, the analyser discovers from the project root just like `analyse`.

### Options

| Option | Default | Purpose |
|---|---|---|
| `--config=PATH` | auto-discover `.gruff.yaml` | Use a specific config file. |
| `--no-config` | off | Skip `.gruff.yaml` for this run; built-in defaults only. Cannot combine with `--config`. |
| `--format=text\|json` | `text` | `text` is the human digest, `json` is `gruff.summary.v1` for tooling. |
| `--top=N` | `10` | Cap the "Top N rules" and "Top N file offenders" sections. |
| `--include-ignored` | off | Scan files under default ignored directories (`vendor/`, `node_modules/`, etc.). |

## Example — text format

```bash
php bin/gruff summary tests/Fixtures/Source/mixed --no-config --top=3
```

```
gruff 0.1.0-dev — summary

Paths     tests/Fixtures/Source/mixed
Config    (none)
Files     2 discovered, 2 parsed, 4 ignored, 0 missing, 0 parse errors

Composite A (95.80 / 100)
Scope     full-project

Pillars
  documentation   A  90.00 findings=3     advisory=3     warning=0     error=0
  naming          D  68.00 findings=2     advisory=0     warning=2     error=0
  size            A 100.00 findings=0     advisory=0     warning=0     error=0
  ...

Top 3 rules by finding count
      2  naming.class-file-mismatch      naming         a=0 w=2 e=0
      1  docs.missing-class-phpdoc       documentation  a=1 w=0 e=0
      1  docs.missing-constant-phpdoc    documentation  a=1 w=0 e=0

Top 2 file offenders
  D   67.50  tests/Fixtures/Source/mixed/nested/beta.php   findings=4  a=3 w=1 e=0
  B   80.00  tests/Fixtures/Source/mixed/alpha.php         findings=1  a=0 w=1 e=0

Totals    5 findings (advisory=3, warning=2, error=0)
```

Pillars are ordered by finding count (loudest first). Pillars with zero findings still appear so it's obvious which are clean.

## Example — JSON format

```bash
php bin/gruff summary src --format=json --top=5
```

```json
{
  "schemaVersion": "gruff.summary.v1",
  "tool": { "name": "gruff", "version": "0.1.0-dev" },
  "scope": {
    "paths": ["src"],
    "configPath": ".gruff.yaml",
    "filesDiscovered": 234,
    "filesParsed": 234,
    "ignoredPaths": 0,
    "missingPaths": 0,
    "parseErrors": 0,
    "scope": "full-project"
  },
  "composite": { "score": 45.2, "grade": "F" },
  "findings": { "advisory": 1825, "warning": 1815, "error": 664, "total": 4304 },
  "pillars": [
    { "pillar": "documentation", "grade": "F", "score": 0, "findings": 3584, "advisories": 1118, "warnings": 1814, "errors": 652, "penalty": 100, "applicable": true },
    ...
  ],
  "topRules": [
    { "ruleId": "docs.return-comment", "count": 1426, "advisory": 0, "warning": 1426, "error": 0, "pillar": "documentation" },
    ...
  ],
  "topOffenders": [
    { "file": "src/Reporting/HtmlReporter.php", "score": 0, "grade": "F", "findings": 90, "advisories": 56, "warnings": 28, "errors": 6, "penalty": 100, "maxCyclomatic": null, "maxCognitive": null, "maxLines": null, "mutationScore": null },
    ...
  ]
}
```

The schema is stable for this minor version. New top-level keys may be added; existing keys won't be renamed or change shape without bumping the schema version.

## What this is *not*

- Not a `analyse` replacement — there's no per-finding list, no remediation hints, no diff/baseline interaction, no mutation analysis, no HTML rendering. Use `analyse` (with `--min-severity`, `--include-rule`, etc.) when you need full findings.
- Not faster scanning — it runs the full rule registry. The "speed" is in reading the output, not the scan.
- Not dashboard-server cached — every invocation rescans. Pipe it into a script if you want repeated lookups.

## Exit codes

| Code | Meaning |
|---|---|
| `0` | Summary printed successfully. |
| `2` | Usage error: bad `--format`, non-integer `--top`, `--config` combined with `--no-config`, or config load failure. |
| `1` | Reserved for future "failure on threshold" support if added. Today the command never returns `1`. |

`summary` deliberately does **not** honour `--fail-on`. It is a read-only digest; use `analyse --fail-on=warning` if you want CI to fail on the same data.

## See also

- [`gruff-cli-agent-instructions.md`](gruff-cli-agent-instructions.md) — for agents wrapping the CLI.
- [`gruff-cli-branch-review.md`](gruff-cli-branch-review.md) — diff-aware review workflow.
- [`README.md`](../README.md) — main project overview.
- [`src/Command/SummaryCommand.php`](../src/Command/SummaryCommand.php) — source of truth.

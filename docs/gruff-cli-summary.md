# `gruff-php summary`

A compact digest of a scan. Runs the analyser once, aggregates by pillar and rule, and prints a single readable block - **no per-finding spam**.

Use it when:

- The `analyse` text output is too long to read at a glance.
- You're dogfooding new rules and want to see "where is the noise concentrated?" without scrolling.
- A CI step needs a one-glance score breakdown without rendering the full HTML or JSON report.
- You want a small JSON shape to feed into another tool (schema: `gruff.summary.v3`).

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
| `--format=text\|json` | `text` | `text` is the human digest, `json` is `gruff.summary.v3` for tooling. |
| `--top=N` | `10` | Cap the "Top N rules" and "Top N file offenders" sections. |
| `--include-ignored` | off | Scan ignored files by using filesystem traversal instead of Git/default ignores. |

## Example - text format

The example output below is captured from the current checkout.

```bash
php bin/gruff-php summary tests/Fixtures/Source/mixed --no-config --top=3
```

```
gruff-php 0.6.0 summary
Composite: B (86.22 / 100)
Findings: 23 total · 0 error · 6 warning · 17 advisory

Paths     tests/Fixtures/Source/mixed
Config    (none)
Files     7 discovered, 7 parsed, 0 ignored, 0 missing, 0 parse errors
Scope     full-project
Score note Each pillar scores on the density of its weighted findings per evaluated file, on a curve from 50 to 100, so a larger project is not penalised for its size; correlated size and complexity findings on one symbol share a single weight; the composite is the average of applicable pillar scores. Mutation is omitted when no Infection report is supplied.

Pillars
  documentation   F  52.70 findings=12    advisory=12    warning=0     error=0
  naming          F  51.18 findings=7     advisory=1     warning=6     error=0
  dead-code       F  58.33 findings=4     advisory=4     warning=0     error=0
  ...

Top 3 rules by finding count
      6  naming.class-file-mismatch      naming  a=0 w=6 e=0
      5  docs.missing-class-phpdoc       documentation  a=5 w=0 e=0
      5  docs.missing-file-phpdoc        documentation  a=5 w=0 e=0

Top 3 file offenders
  F   50.76  tests/Fixtures/Source/mixed/build/ignored.php      findings=4    a=3 w=1 e=0
  F   50.76  tests/Fixtures/Source/mixed/cache/ignored.php      findings=4    a=3 w=1 e=0
  F   50.76  tests/Fixtures/Source/mixed/generated/ignored.php  findings=4    a=3 w=1 e=0

Baseline  After review, `gruff-php analyse --generate-baseline` records current findings as known debt.
          Use `gruff-php analyse --no-baseline` to audit without a baseline.
```

Pillars are ordered by finding count (loudest first). Pillars with zero findings still appear so it's obvious which are clean.

`summary` applies any configured [`sensitiveExclusions`](configuration.md#sensitive-exclusions), so its counts and grade match `analyse` over the same tree. When a run suppressed something, a final line states the total in the same words `analyse` uses:

```
Suppressed findings: 1 via sensitiveExclusions[0] sensitive-data.aws-access-key: 1 (Synthetic key used by the scanner fixtures; not a live credential.)
```

`gruff.summary.v3` publishes a top-level `suppressions` array - one `{index, rule, paths, symbol?, reason, suppressed}` row per configured `sensitiveExclusions` entry, including entries that matched nothing - so `summary --format json` reports the same total the text output prints.

## Example - JSON format

```bash
php bin/gruff-php summary tests/Fixtures/Source/mixed --no-config --format=json --top=5
```

```json
{
  "schemaVersion": "gruff.summary.v3",
  "tool": { "name": "gruff-php", "version": "0.6.0" },
  "run": {
    "failOn": "none",
    "format": "json",
    "inputs": ["tests/Fixtures/Source/mixed"],
    "projectRoot": "."
  },
  "summary": {
    "analysedFiles": 7,
    "discoveredFiles": 7,
    "parsedFiles": 7,
    "skippedFiles": 0,
    "ignoredPaths": 0,
    "missingPaths": 0,
    "parseErrors": 0,
    "diagnostics": 0,
    "exitCode": 0,
    "findings": { "advisory": 17, "warning": 6, "error": 0, "total": 23 },
    "findingsByPillar": { "dead-code": 4, "documentation": 12, "naming": 7 }
  },
  "score": {
    "composite": { "score": 86.22, "grade": "B" },
    "clusters": [],
    "ruleAttribution": [
      { "ruleId": "docs.missing-class-phpdoc", "findings": 5, "weight": 5 },
      ...
    ],
    "evaluatedFiles": 6,
    "scoredPillars": ["size", "complexity", "maintainability", "dead-code", "naming", "documentation", "modernisation", "security", "sensitive-data", "test-quality"],
    "pillars": [
      { "pillar": "size", "applicable": true, "score": 100, "grade": "A", "findings": 0, "advisory": 0, "warning": 0, "error": 0, "penalty": 0 },
      ...
    ],
    "topOffenders": [
      { "file": "tests/Fixtures/Source/mixed/build/ignored.php", "score": 50.76, "grade": "F", "findings": 4, "advisory": 3, "warning": 1, "error": 0, "penalty": 6.5 },
      ...
    ],
    "complexityDistribution": { "1-5": 0, "6-10": 0, "11-15": 0, "16-20": 0, "21+": 0 },
    "scope": "full-project",
    "explanation": "Each pillar scores on the density of its weighted findings per evaluated file, ..."
  },
  "diagnostics": [],
  "paths": { "analysedFiles": 7, "details": [], "ignoredPaths": [], "missingPaths": [] },
  "suppressions": []
}
```

`summary --format json` is the exact findings-free projection of the
corresponding `analyse` document: only the top-level `findings` array is
removed. [Output Formats](output-formats.md) owns the envelope's shape. The
schema is versioned for the public package: new top-level keys may be added in
a compatible release, and existing keys should not be renamed or change shape
without bumping the schema version.

## What this is *not*

- Not an `analyse` replacement - there's no per-finding list, no remediation hints, no diff/baseline interaction, no mutation analysis, no HTML rendering. Use `analyse` (with `--min-severity`, `--include-rule`, etc.) when you need full findings.
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
- [`src/Cli/Command/SummaryCommand.php`](../src/Cli/Command/SummaryCommand.php) - source of truth.

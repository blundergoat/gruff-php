# Configuration

gruff-php reads YAML configuration and applies it before rule analysis.

## Discovery

Default discovery checks the project root in this order:

1. `.gruff-php.yaml`
2. `.gruff.yaml`

Use `--config <path>` to load a specific YAML file, or `--no-config` to run
with built-in defaults.

## Root Keys

Supported top-level sections are:

- `schemaVersion`
- `extends`
- `minimumPhpVersion`
- `minimumSeverity`
- `failureConditions`
- `paths`
- `allowlists`
- `selection`
- `rules`

Unknown top-level keys are rejected so config mistakes fail early.

## Schema Version

`schemaVersion` is required at the top of every `.gruff-php.yaml`. The only
value accepted today is `gruff-php.config.v0.1`:

```yaml
schemaVersion: gruff-php.config.v0.1
```

Configs missing this key fail to load with a hint pointing at
`gruff-php init --force`. See
[`ADR-015`](../.goat-flow/decisions/ADR-015-per-command-minimum-severity.md)
for the rationale.

## Extends

`extends` can load a bundled preset or another YAML file before applying the
current file's overrides:

```yaml
schemaVersion: gruff-php.config.v0.1
extends: gruff.recommended
```

Bundled presets are `gruff.recommended`, `gruff.starter`, and `gruff.strict`.
Relative paths resolve from the file that declares `extends`. Inheritance chains
resolve ancestor-first; cycles, chains deeper than five hops, and unknown preset
names fail fast.

## Minimum Severity

`minimumSeverity` sets the exit-code threshold per gating command. Keys are
`analyse`, `report`, and `dashboard`; values are `advisory`, `warning`,
`error`, or `none`:

```yaml
minimumSeverity:
  analyse: advisory
  report: none
  dashboard: none
```

The validator rejects every other key, including `summary`, `init`, and
`list-rules`, because those commands do not gate exit code; silent
acceptance would hide a CI misconfiguration. It also rejects every other
value (including the gruff-go alias `never`) with an error naming the four
accepted values.

Precedence when resolving the effective threshold:

1. CLI `--fail-on` flag (when set explicitly)
2. `minimumSeverity.<command>` from `.gruff-php.yaml`
3. Binary default — `advisory` for `analyse`, `none` for `report` and
   `dashboard`

`analyse`'s binary default lowered from `error` to `advisory` in 0.2.0 so
that every finding visible in the report can fail CI by default. Pass
`--fail-on error` or set `minimumSeverity.analyse: error` to restore the
older behaviour.

## Failure Conditions

`failureConditions` sets count-based gates for `analyse`. Use it when the policy
is "allow N findings, fail above N" rather than a simple severity floor:

```yaml
failureConditions:
  total: 200
  severityThresholds:
    error: 0
    warning: 5
    advisory: 50
```

Any configured cap that is exceeded fails the run. An explicit CLI `--fail-on`
flag overrides `failureConditions`. To gate only change-introduced findings,
configure `newFindings` and provide a reference point with `--baseline` or
`--diff-vs`:

```yaml
failureConditions:
  newFindings:
    severityThresholds:
      error: 0
```

## Paths

Use `paths.ignore` for project-specific ignore patterns. The CLI also honours
Git/default ignores unless `--include-ignored` is passed.

```yaml
paths:
  ignore:
    - vendor/
    - var/cache/
```

`paths.ignore` is authoritative in every invocation mode: a matching path is
excluded from analysis and produces no findings however it was supplied — a
directory walk, an explicit file operand, or any diff/changed-region scan
(`--diff`, `--diff -`, `--changed-ranges`, `--since`, `--diff-vs`).
`--include-ignored` opts back into Git/default-ignored paths only; it never
overrides `paths.ignore`.

Each excluded path is reported in the JSON report's additive `ignoredPathDetails`
array (alongside the compatibility `ignoredPaths` string list) with the `source`
that excluded it (`config`, `default`, `generated`, or `gitignore`) and the
matching `pattern`:

```json
"ignoredPathDetails": [
  { "path": "legacy/Report.php", "source": "config", "pattern": "legacy/**" }
]
```

Use `gruff-php check-ignore <path>...` to ask whether gruff would ignore a path,
and why, without running an analysis (see [CI integration](ci-integration.md)).

## Allowlists

Use allowlists for deliberate naming or sensitive-data exceptions:

```yaml
allowlists:
  acceptedAbbreviations: [HTTP, API]
  secretPreviews: []
```

## Selection

Selection narrows the active rule set:

```yaml
selection:
  pillars: [security, complexity]
  excludeRules: [security.weak-crypto]
```

## Rules

Per-rule overrides live under `rules.<rule-id>`:

```yaml
rules:
  size.file-length:
    enabled: true
    threshold: 800
    severity: warning
```

Run `vendor/bin/gruff-php list-rules --format json` to inspect rule IDs and
available defaults.

### Visibility without scoring

`excludeFromScore: true` keeps a rule running and surfaces its findings in
every report, but the findings no longer contribute to the composite or
pillar penalty bucket. Use it when a rule is informational for the team
but you do not want its volume to dominate the grade. `enabled: false`
remains the way to silence a rule entirely.

```yaml
rules:
  docs.missing-public-phpdoc:
    enabled: true
    excludeFromScore: true
```

See [`ADR-016`](../.goat-flow/decisions/ADR-016-visibility-only-rule-scoring-tier.md)
for the rationale and the failure-mode comparison.

## Compatibility

The shared cross-language expectations are summarized in
[Naming Conventions](naming-conventions.md#shared-contract). PHP intentionally
keeps YAML-only config loading and the legacy `.gruff.yaml` fallback.

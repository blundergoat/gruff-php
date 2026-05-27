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
- `minimumPhpVersion`
- `minimumSeverity`
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

`analyse`'s binary default lowered from `error` to `advisory` in 0.1.5 so
that every finding visible in the report can fail CI by default. Pass
`--fail-on error` or set `minimumSeverity.analyse: error` to restore the
older behaviour.

## Paths

Use `paths.ignore` for project-specific ignore patterns. The CLI also honours
Git/default ignores unless `--include-ignored` is passed.

```yaml
paths:
  ignore:
    - vendor/
    - var/cache/
```

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
  excludeRules: [security.eval-call]
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

## Compatibility

The shared cross-language config expectations are documented in
[`../../CONTRACT.md`](../../CONTRACT.md). PHP intentionally keeps YAML-only
config loading and the legacy `.gruff.yaml` fallback.

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

- `paths`
- `allowlists`
- `selection`
- `rules`
- `minimumPhpVersion`

Unknown top-level keys are rejected so config mistakes fail early.

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

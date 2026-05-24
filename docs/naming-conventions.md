# Gruff Naming Conventions

These conventions keep the gruff implementations readable side by side. A
project may have language-specific rules and thresholds, but shared concepts
should use the same public names where the implementation supports them.

## Rule IDs

Rule IDs use the shape `<namespace>.<rule-slug>`.

- Use lowercase kebab-case for every namespace segment and rule slug.
- Use a dot between the rule namespace and the rule slug.
- Prefer stable names over exact implementation details. If behavior broadens,
  keep the old rule ID only when compatibility requires it.
- Use `docs.*` for documentation rules, even though the emitted pillar is
  `documentation`.
- Use `sensitive-data.*`, not `secrets.*`.
- Use `dead-code.*`, `test-quality.*`, and British-spelled
  `modernisation.*`.
- Use `design.*` for source-level design smells. Project-specific topology
  rules may use a narrower namespace such as `architecture.*` when the rule
  catalogue documents that namespace.
- The namespace does not have to equal the emitted pillar. For example,
  dependency or concurrency checks can still score against `security` or
  `waste` when that is the more accurate quality pillar.

## Config Files

Each implementation should prefer its tool-prefixed config filename. Legacy
fallbacks are compatibility features, not the primary convention.

| Project | Primary config | Default fallbacks |
| --- | --- | --- |
| `gruff-go` | `.gruff-go.yaml` | none |
| `gruff-php` | `.gruff-php.yaml` | `.gruff.yaml` |
| `gruff-py` | `.gruff-py.yaml` | `.gruff.yaml`, then `pyproject.toml` |
| `gruff-rs` | `.gruff-rs.yaml` | none |
| `gruff-ts` | `.gruff-ts.yaml` | `.gruff.json`, `.gruff.yaml`, `.gruff.yml` |

Checked-in dogfood config should prefer the implementation's primary filename.

Use these root keys when the implementation supports them:

```yaml
paths:
  ignore: []

allowlists:
  acceptedAbbreviations: []
  secretPreviews: []

selection:
  tiers: []
  pillars: []
  rules: []
  excludePillars: []
  excludeRules: []

rules:
  size.file-length:
    enabled: true
```

Do not add unsupported root keys just to make files visually identical. For
example, `gruff-rs` uses `rules.select` / `rules.ignore` instead of the PHP/Python
`selection` section, and `gruff-ts` does not implement `selection`.

## Rule Config Keys

Per-rule config keys should keep these names where supported:

- `enabled` for boolean rule activation.
- `threshold` for a single numeric override when the rule supports a shorthand.
- `severity` for the severity attached to a shorthand threshold.
- `thresholds` for named numeric rule knobs.
- `options` for non-threshold rule settings.

Threshold names are part of each implementation's rule catalogue. Do not
translate `warn` to `warning`, or the reverse, unless that implementation
explicitly supports the alias. Use `list-rules` or the local rule registry as
the source of truth.

## Current Cross-Project Notes

| Project | Naming status |
| --- | --- |
| `gruff-go` | Emits dotted rule IDs and accepts legacy hyphen-only plus `documentation.*` config aliases. Its `acceptedAbbreviations` loader currently requires uppercase initialisms. |
| `gruff-php` | Uses dotted rule IDs, `docs.*`, YAML-only config (`.gruff-php.yaml` preferred, legacy `.gruff.yaml` accepted), `selection`, and a single `threshold` + `severity` shorthand for rubric rules. |
| `gruff-py` | Uses dotted rule IDs, `docs.*`, `.gruff-py.yaml` before legacy `.gruff.yaml`, then `pyproject.toml`, and `warning` / `error` threshold names. |
| `gruff-rs` | Uses dotted rule IDs and `.gruff-rs.yaml`; `metrics.*` and `architecture.*` are documented Rust-specific namespaces, and several threshold names use `warn`. |
| `gruff-ts` | Uses dotted rule IDs, `docs.*`, `.gruff-ts.yaml` first, then `.gruff.json`, `.gruff.yaml`, and `.gruff.yml`; several threshold names use `warn`. |

## Remaining Consistency Candidates

- `gruff-go` still emits severity values `low`, `medium`, `high`, and
  `critical`; the newer implementations use `advisory`, `warning`, and
  `error`. Migrating that is an output-contract change.
- `gruff-rs` has `metrics.halstead-volume` and
  `metrics.maintainability-pressure`, while PHP and Python use the
  `complexity.*` namespace for related metrics. Keep this documented until a
  compatibility migration is chosen.
- Config discovery order differs by language and should stay documented rather
  than forced into exact parity without a user-facing reason.

## Shared Contract

The workspace-level contract that governs naming for rule IDs and config keys
lives in `CONTRACT.md` at the gruff workspace monorepo root, alongside the
sibling gruff-* package checkouts. It is the source of truth for CLI and docs
consistency decisions across packages; this repository does not vendor a copy.

# Output Formats

`vendor/bin/gruff-php analyse --format <format>` renders the same analysis data
for different consumers.

## Text

Use `text` for local terminal scans:

```sh
vendor/bin/gruff-php analyse src --format text --fail-on warning
```

Text keeps the established finding block in 0.5.1. Machine-readable
`remediationAction` and `configurationKey` metadata is not added to this
presentation.

## JSON

Use `json` for automation. Analysis reports use `gruff.analysis.v3`:

```sh
vendor/bin/gruff-php analyse src --format json --fail-on none > gruff-php.json
```

Version 3 is the coordinated family machine contract. Paths are
project-relative POSIX paths, `run.projectRoot` is `.`, and unavailable
optional fields are omitted rather than emitted as `null`. The shared
top-level sections are `schemaVersion`, `tool`, `run`, `summary`,
`score`, `diagnostics`, `findings`, `paths`, and `suppressions`.
`baseline`, `diff`, `displayFilter`, and `extensions` appear only when
their features are active.

### Migrating v2 consumers

Version 3 is a hard break with no v2 writer or compatibility flag:

| v2 | v3 |
|---|---|
| absolute or working-directory-dependent paths | project-relative POSIX paths and `run.projectRoot: "."` |
| `findings[].filePath` or duplicate path aliases | `findings[].file` |
| nullable `column`, `endLine`, or `symbol` | omit the unavailable key |
| `score.composite` plus `score.grade` | `score.composite.score` plus `score.composite.grade` |
| `score.topOffenders[].filePath` | `score.topOffenders[].file` |
| top-level `ignoredPaths`, `ignoredPathDetails`, and `missingPaths` | `paths.ignoredPaths`, `paths.details`, and `paths.missingPaths` |
| top-level `suppressedCount` and `diff.suppressedCount` | `summary.suppressedFindings` and `diff.filteredFindings` |
| top-level `trend`, `mutation`, or `review` | `extensions.php.topLevel.{trend,mutation,review}` |
| independent compact summary fields | the `gruff.summary.v3` analysis projection |

The adapter preserves native fingerprint and `stableIdentity` values, every
score and grade, baseline matching, action metadata, and exit-code decisions.

### Findings and identities

Every finding has one `file` path. `column`, `endLine`, and `symbol` are
present only when known. `metadata.locationPrecision` is
`scanner-pinpointed` when a column is known and `line-only` otherwise.

Each finding carries two identifiers:

- `fingerprint` — the existing 16-character, line-sensitive SHA-256 prefix
  mirrored into SARIF as `gruffFingerprint`.
- `stableIdentity` — the existing 16-character, line-insensitive identity for
  matching the same logical finding across unrelated line shifts.

Two findings for the same rule, symbol, and message can share a
`stableIdentity` while retaining distinct `fingerprint` values. PHP baseline
matching remains count arithmetic over its grouped v2 baseline rows; the
analysis-envelope upgrade does not change it.

Classified findings retain top-level `remediation` and action data inside
`metadata`:

```json
{
  "remediation": "Rename the identifier or add the abbreviation to allowlists.acceptedAbbreviations with a documented meaning.",
  "metadata": {
    "remediationAction": "CONSIDER",
    "configurationKey": "allowlists.acceptedAbbreviations",
    "locationPrecision": "line-only"
  }
}
```

`remediationAction` is `APPLY` for a direct source fix, `CONSIDER` for optional
or compatibility-sensitive advice, or `CONFIGURE` for a
configuration-only resolution. `configurationKey` is optional.

### Paths and suppressions

`paths.details` contains each excluded path with a canonical `reason`,
`source`, and `pattern` when a pattern caused the skip.
`paths.ignoredPaths` is the exact ordered path projection of those details.

Every analysis and summary document includes `suppressions`, with one
`{index, rule, paths, symbol?, reason, suppressed}` row per configured
`sensitiveExclusions` entry, including entries that matched nothing. The array
is empty when no exclusion is configured. No row carries a finding message,
preview, or matched value. See
[Configuration](configuration.md) for authoring rules and rejections.

### Baseline, trend, and changed-region data

When a PHP grouped baseline is generated or applied, the canonical `baseline`
object contains its entries, path, generation and staleness data, and
`suppressedFindings`. The native `new`, `unchanged`, and `absent` bucket
tallies live at `baseline.extensions.php.baseline.buckets`. Baseline files
remain `gruff.baseline.v2`; regenerate only when baseline behavior itself
requires it, not for the analysis-envelope upgrade.

Trend history remains scope-aware. Its machine representation moves to
`extensions.php.topLevel.trend`: full-project scores compare only with earlier
full-project scores, and changed-region scores compare only with earlier diff
scores.

Changed-region reports produced by `--diff`, `--since`, or
`--changed-ranges` publish the number removed by region filtering at both
`summary.suppressedFindings` and `diff.filteredFindings`; the values are
equal. Full scans omit both fields and omit `diff`.

`--changed-scope=symbol` keeps ordinary symbol-local findings when the changed
hunk touches their declaration. File and class aggregates are kept only when
the hunk touches their reported anchor. Use `--changed-scope=file` when a
changed-file workflow intentionally wants file-level aggregates and class
aggregates whose reported span overlaps the hunk.

## Summary

`summary --format json` emits the exact findings-free projection of the
corresponding analysis document. Only the top-level `findings` array is
removed and the schema changes to `gruff.summary.v3`:

```sh
vendor/bin/gruff-php summary src --format json
```

Counts, scores, diagnostics, paths, suppressions, baseline, diff, and extensions
therefore keep their analysis values. Text summary remains the compact human
view.

## Hook

`gruff-php hook --format json` carries the same `remediation` string and
`metadata.remediationAction` / `metadata.configurationKey` fields in each
`gruff.hook.v1` finding. The hook presenter passes non-threshold metadata
through unchanged; its existing threshold normalisation preserves the action
keys alongside measured values. Hook new-only fingerprints deliberately omit
both action keys, so a finding accepted before action metadata was introduced
remains suppressed when the underlying problem is otherwise unchanged.

## HTML

Use `html` for archived human review or dashboard scan output:

```sh
vendor/bin/gruff-php report src --format html --output gruff-php.html
```

`report` delegates to `analyse` and supports the analyse options that affect
analysis selection, gating, baselines, cache, mutation ingestion, or rendered
report content — including `--profile`, `--since`, `--changed-ranges`,
`--changed-scope`, `--fail-on-new`, `--no-cache`, `--baseline-include-absent`,
the `--infection-*` runtime options, and `--print-runtime`/`--runtime-mode`.
With `--fail-on-new`, the report artifact is still written and the exit code
reflects the gate, matching `analyse` semantics. Two analyse flags stay
analyse-only by design: `--generate-baseline` (report never writes baselines)
and `--file` (pass paths positionally instead).

## Markdown

Use `markdown` for pull request comments and release notes.

Markdown keeps its established finding rows in 0.5.1 and does not render the
new action metadata. Use JSON, hook, or SARIF when a consumer needs the
machine-readable action distinction.

## GitHub

Use `github` inside GitHub Actions to emit workflow annotations.

## Hotspot

Use `hotspot` for score and offender analysis. Hotspot output is a compact JSON
view intended for dashboards and trend tooling.

## SARIF

Use `sarif` for GitHub code scanning or other SARIF consumers:

```sh
vendor/bin/gruff-php analyse src --format sarif --fail-on none > gruff-php.sarif
```

Each SARIF result carries two `partialFingerprints` keys that map onto the
JSON finding identity fields:

- `gruffFingerprint` — the precise, line-sensitive `fingerprint`. Byte-compatible
  with earlier releases; changes whenever the finding's location changes.
- `gruffStableIdentity` — the line-insensitive `stableIdentity`. Survives
  unrelated edits that shift line numbers, so SARIF consumers (for example
  GitHub Code Scanning) can keep an alert open across line drift instead of
  closing and reopening it.

When a finding is classified, SARIF carries the human remediation at
`result.properties.remediation` and the action fields at
`result.properties.metadata.remediationAction` and, when available,
`result.properties.metadata.configurationKey`. The SARIF result message,
levels, locations, and partial fingerprints are unchanged.

## Exit Codes

`analyse` exits `1` when at least one finding meets `--fail-on`. Use
`--fail-on none` for report-only jobs.

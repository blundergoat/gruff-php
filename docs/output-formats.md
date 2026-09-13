# Output Formats

`vendor/bin/gruff-php analyse --format <format>` renders the same analysis data
for different consumers.

## Text

Use `text` for local terminal scans:

```sh
vendor/bin/gruff-php analyse src --format text --fail-on warning
```

Text keeps the established finding block in 0.5.2. Machine-readable
`remediationAction` and `configurationKey` metadata is not added to this
presentation.

## JSON

Use `json` for automation. Analysis reports use `gruff.analysis.v3`:

```sh
vendor/bin/gruff-php analyse src --format json --fail-on none > gruff-php.json
```

Version 3 is the coordinated family machine contract. Paths are
project-relative POSIX paths, `run.projectRoot` is `.`, and unavailable
optional fields are omitted rather than emitted as `null`. The one
exception is the composite: a run that evaluated nothing emits
`score.composite.score` and `score.composite.grade` as `null` rather
than omitting them. The shared top-level sections are `schemaVersion`,
`tool`, `run`, `summary`, `score`, `diagnostics`, `findings`, `paths`,
and `suppressions`. `baseline`, `diff`, `displayFilter`, and
`extensions` appear only when their features are active.

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
score and grade, baseline matching, action metadata, and exit-code decisions:
the envelope projects what the analyser computed and does not recompute it.
What each of those values *is* did change in this release - see
[`CHANGELOG.md`](../CHANGELOG.md) and [`UPGRADING.md`](../UPGRADING.md).

### Findings and identities

Every finding has one `file` path. `column`, `endLine`, and `symbol` are
present only when known. `metadata.locationPrecision` is
`scanner-pinpointed` when a column is known and `line-only` otherwise.

Each finding carries two identifiers:

- `fingerprint` — the 16-character, line-sensitive SHA-256 prefix. It is a
  JSON-only field: SARIF does not carry it.
- `stableIdentity` — the 16-character, line-insensitive identity for matching
  the same logical finding across unrelated line shifts.

Two findings for the same rule, symbol, and message can share a
`stableIdentity` while retaining distinct `fingerprint` values. PHP baseline
matching is count arithmetic over the line-free `identity` digest in each
`gruff.baseline.v3` `occurrences` row, which is neither of these two fields;
see [Baseline, trend, and changed-region data](#baseline-trend-and-changed-region-data).
SARIF publishes that same baseline identity under `gruffFingerprint`.

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

`remediationAction` is `APPLY` for a direct source fix or `CONSIDER` for
optional or compatibility-sensitive advice. `CONFIGURE`, for a
configuration-only resolution, is reserved and emitted by no current rule.
`configurationKey` is optional.

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

When a PHP baseline is generated or applied, the canonical `baseline`
object contains its entries, path, generation and staleness data, and
`suppressedFindings`. The native `new`, `unchanged`, `absent`, `collision`,
`notEligible`, and `sensitiveCounted` bucket tallies live at
`baseline.extensions.php.baseline.buckets`. Baseline files
are `gruff.baseline.v3`: a top-level `occurrences` array of
`{identity, count, ruleId, path, subject}` rows beside `toolLanguage`,
`generatedAt`, and a `sensitive` object recording that sensitive findings are
never eligible. A `gruff.baseline.v1` or `gruff.baseline.v2` file fails closed
with exit `2` and names `--migrate-baseline`.

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
`gruff.hook.v2` finding. The hook presenter passes non-threshold metadata
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

Markdown keeps its established finding rows in 0.5.2 and does not render the
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

A SARIF result for an ordinary finding carries one `partialFingerprints` key:

- `gruffFingerprint` — the ratified family identity, the same durable value
  baseline matching reads. It is line-free, so SARIF consumers (for example
  GitHub Code Scanning) keep an alert open across unrelated edits that shift
  line numbers instead of closing and reopening it.

A sensitive-data result carries no `partialFingerprints` object at all, so a
secret is never given a durable name in a system gruff does not control; its
alerts close at this break and later occurrences arrive ungrouped.

The php-only `gruffStableIdentity` key is gone; the JSON finding object still
publishes `stableIdentity`, documented under
[Findings and identities](#findings-and-identities).

When a finding is classified, SARIF carries the human remediation at
`result.properties.remediation` and the action fields at
`result.properties.metadata.remediationAction` and, when available,
`result.properties.metadata.configurationKey`. The SARIF result message,
levels, locations, and partial fingerprints are unchanged.

## Exit Codes

`analyse` exits `1` when at least one finding meets `--fail-on`. Use
`--fail-on none` for report-only jobs.

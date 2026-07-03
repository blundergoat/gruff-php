# Output Formats

`vendor/bin/gruff-php analyse --format <format>` renders the same analysis data
for different consumers.

## Text

Use `text` for local terminal scans:

```sh
vendor/bin/gruff-php analyse src --format text --fail-on warning
```

## JSON

Use `json` for automation. JSON reports use `gruff.analysis.v2`.

```sh
vendor/bin/gruff-php analyse src --format json --fail-on none > gruff-php.json
```

Each finding carries two identifier fields:

- `fingerprint` — 16-character SHA-256 prefix over `ruleId + file + line +
  endLine + column + symbol + message`. The precise, line-sensitive identity
  emitted per finding and mirrored into SARIF as `gruffFingerprint`.
- `stableIdentity` — 16-character SHA-256 prefix over `ruleId + file +
  symbol + message` (or `ruleId + file + message` when symbol is null). The
  line-insensitive identity intended for custom diff tooling that needs
  to track "the same finding" across unrelated edits that shift line
  numbers. Two findings of the same rule on the same symbol and message
  share a `stableIdentity` but have different `fingerprint` values.

Baselines do not match on either hash: `gruff.baseline.v2` files store grouped
count rows `{file, ruleId, message, count}` and matching is count arithmetic
per `(file, ruleId, message)` group, so line numbers never affect baseline
results. For line-shift-resilient diff tooling, use `stableIdentity`.

When a baseline is generated or applied, the `gruff.analysis.v2` payload
includes a `baseline` object: `path`, `generated`, `totalEntries` (group rows
loaded), `suppressedFindings`, `staleEvaluation` (`full-project`,
`not-evaluated-diff-scope`, or `generated`), `staleEntries` (absent group row
count), `source` (`explicit` or `default`), `stale` (absent group rows
`{file, ruleId, message, count}` where `count` is the resolved instance total
for that group), and `buckets` (`new` / `unchanged` / `absent` instance
tallies).

Trend history (`--history-file`) appends one entry per run and stamps the
run's score scope. The reported `trend.scope` names the series, and
`trend.delta` compares like-for-like scopes only: a full-project score is
compared with the latest full-project entry, a diff/changed-region score
(`--diff`, `--since`, `--changed-ranges`) with the latest diff entry, and
`previousScore`/`delta` are null when the history holds no earlier entry of
the same scope.

Changed-region reports (`--diff`, `--since`, or `--changed-ranges`) include a
top-level `suppressedCount`, mirrored as `diff.suppressedCount`, when diff
filtering is active. It counts findings anchored in the changed/requested files
that were produced by the analysis run and then removed because they were
outside the selected hunk or symbol. Project-wide rules still use whole-project
context before filtering, but project-rule findings anchored outside the
changed/requested files are outside the invocation scope and are not included in
the suppression total.

`--changed-scope=symbol` keeps ordinary symbol-local findings when the changed
hunk touches their enclosing declaration, but file and class aggregate findings
such as `size.file-length`, `size.class-length`, and `docs.todo-density` are kept
only when the hunk touches their reported anchor. Use `--changed-scope=file` for
changed-file review workflows that intentionally want file-level aggregates and
class aggregate findings whose reported span overlaps the changed hunk.

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

## Exit Codes

`analyse` exits `1` when at least one finding meets `--fail-on`. Use
`--fail-on none` for report-only jobs.

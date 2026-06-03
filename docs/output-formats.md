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
  endLine + column + symbol + message`. The line-sensitive identity used
  by baseline matching (so a moved violation can be re-baselined
  deliberately) and by SARIF emission.
- `stableIdentity` — 16-character SHA-256 prefix over `ruleId + file +
  symbol` (or `ruleId + file + message` when symbol is null). The
  line-insensitive identity intended for custom diff tooling that needs
  to track "the same finding" across unrelated edits that shift line
  numbers. Two findings of the same rule on the same symbol share a
  `stableIdentity` but have different `fingerprint` values.

For baseline matching, use `fingerprint`. For line-shift-resilient diff
tooling, use `stableIdentity`.

Changed-region reports (`--diff`, `--since`, or `--changed-ranges`) include a
top-level `suppressedCount`, mirrored as `diff.suppressedCount`, when diff
filtering is active. It counts findings anchored in the changed/requested files
that were produced by the analysis run and then removed because they were
outside the selected hunk or symbol. Project-wide rules still use whole-project
context before filtering, but project-rule findings anchored outside the
changed/requested files are outside the invocation scope and are not included in
the suppression total.

## HTML

Use `html` for archived human review or dashboard scan output:

```sh
vendor/bin/gruff-php report src --format html --output gruff-php.html
```

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

## Exit Codes

`analyse` exits `1` when at least one finding meets `--fail-on`. Use
`--fail-on none` for report-only jobs.

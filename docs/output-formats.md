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

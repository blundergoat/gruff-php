# Support

Support for the current `0.3.x` release line is best effort and focused on local
CLI, CI, reporting, and rule-calibration workflows.

## Getting Help

Use the public issue tracker at
`https://github.com/blundergoat/gruff-php/issues` for:

- Bug reports.
- Reproducible false positives.
- Documentation gaps.
- Feature requests.
- Questions about CLI usage.

Do not use public issues for vulnerability reports or real secrets. See
[`SECURITY.md`](SECURITY.md).

## Before Opening An Issue

Please include:

- PHP version: `php -v`
- gruff-php version: `php bin/gruff-php --version` from a checkout or
  `vendor/bin/gruff-php --version` from an installed package
- Install method: source checkout or Composer package
- Command run
- Whether `.gruff-php.yaml` (or legacy `.gruff.yaml`) or a baseline was loaded
- Minimal code sample or fixture when possible

Useful diagnostic commands:

```bash
php bin/gruff-php analyse --format json --fail-on none > gruff-report.json
php bin/gruff-php summary --format json > gruff-summary.json
php bin/gruff-php list-rules --format json > gruff-rules.json
```

Review generated JSON before attaching it to public issues.

## Supported Use Cases

Best-effort support for the current `0.3.x` release line:

- Local CLI scans.
- CI scans.
- Git diff filtering.
- Branch-review comparison.
- Static HTML reports.
- Local dashboard use.
- Baselines.
- Infection report ingestion.

Not supported as production services:

- Exposing the dashboard on an untrusted network.
- Treating findings as legal, compliance, or security certification.
- Scanning untrusted code with `--infection-run`.

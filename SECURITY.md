# Security Policy

`gruff-php` is a static-analysis tool. It scans source trees and may inspect
PHP, YAML, JSON, XML, lockfiles, environment-like text, and generated reports.
Treat analyzer output as sensitive when scanning private code.

## Supported Versions

| Version | Supported |
| --- | --- |
| `0.1.x` | Supported after the first public tag. |
| `<0.1.0` | Development snapshots only. |

## Reporting A Vulnerability

Do not open a public issue for a vulnerability that could expose secrets,
execute commands, bypass path boundaries, corrupt files, or leak private source
content.

Preferred reporting path for a public GitHub repository:

1. Use GitHub private vulnerability reporting or a private security advisory.
2. Include the affected version or commit.
3. Include a minimal reproduction.
4. State whether the issue affects normal local use, CI use, dashboard use, or
   report generation.

Before public release, the maintainer should replace this section with the real
security contact if GitHub private reporting is not enabled.

## Scope

Security-sensitive areas include:

- Dashboard HTTP request handling.
- Git diff and base-ref handling.
- Baseline and trend-history file writes.
- Infection execution via `--infection-run`.
- Shell/process argument construction.
- HTML, Markdown, SARIF, GitHub annotation, and JSON report escaping.
- Sensitive-data detection and redaction.
- Path discovery and ignored-path handling.

## Handling Sensitive Findings

`sensitive-data.*` findings are redacted, but reports may still reveal file
paths, line numbers, rule ids, and redacted previews. Store reports with the
same care as source-code review artifacts.

If gruff reports a real secret:

1. Rotate or revoke the secret first.
2. Remove it from source history if required by your incident process.
3. Add a baseline or allowlist only after confirming the value is not live.

Do not use `allowlists.secretPreviews` to hide active credentials.

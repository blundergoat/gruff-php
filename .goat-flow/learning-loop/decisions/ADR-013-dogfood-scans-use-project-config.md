# ADR-013: Dogfood scans use project config

**Status:** Accepted
**Date:** 2026-05-23
**Author(s):** Codex

## Decision

Dogfood scans and cross-project quality reports must exercise the intended project configuration by default.

For a target project with no local `.gruff-php.yaml`, but with a configuration intentionally supplied from this checkout, run from the target project root and pass that config explicitly:

```bash
php /path/to/gruff-php/bin/gruff-php analyse . --config /path/to/gruff-php/.gruff-php.yaml --format=json --fail-on=none
```

Do not use `--no-config` for user-facing dogfood reports, false-positive rankings, or "top findings" summaries unless the user explicitly asks for unconfigured default-rule calibration. If a calibration scan does use `--no-config`, the report must label it as config-disabled and must not present its output as the configured project signal.

`--no-config` remains valid for narrow rule-development probes where the purpose is to isolate registry defaults from local policy. Those probes are evidence about default-rule behavior, not evidence about a configured target project's scan quality.

## Context

During external dogfooding on 2026-05-23, a report was generated with:

```bash
php /path/to/gruff-php/bin/gruff-php analyse /path/to/external-healthcare-project --no-config --no-baseline --format=json --fail-on=none --paths-relative-to=/path/to/external-healthcare-project
```

That command deliberately bypassed `.gruff-php.yaml` immediately after `src/Vendor/**` had been added to `paths.ignore`. The report therefore ranked vendored-code findings as one of the worst false-positive clusters even though the intended config would have removed them.

Measured evidence from the same session:

- Config-disabled scan: `real 73.04`, `11370` files parsed, `101677` findings, `2490` findings under `src/Vendor/**`.
- Config-applied scan from the target root with `--config /path/to/gruff-php/.gruff-php.yaml`: `real 74.96`, `11000` files parsed, `97010` findings, `0` findings under `src/Vendor/**`.
- The corrected scan also removed the remaining configured-vendor `security.variable-include`, `security.disabled-ssl-verification`, `security.path-traversal-file-access`, and copied-vendor `design.single-implementor-interface` findings.

This matters because dogfood work is supposed to evaluate the scanner as users would run it after project policy is configured. Bypassing config is useful for rule calibration, but it answers a different question.

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| Use `--no-config` for dogfood reports | Configured ignores, thresholds, allowlists, and rule exclusions are bypassed; false-positive analysis blames the rule engine for policy that was intentionally disabled. | Rejected for user-facing dogfood reports. |
| Rely on auto-discovery from the gruff checkout while scanning another root by absolute path | The loaded config can be ambiguous because the current working directory, target path, and intended project policy may be different projects. | Rejected for cross-project dogfood scans. |
| Run from the target project root and pass the intended config explicitly | The scan boundary and relative ignore patterns match the target project, while the chosen config is visible in the command. | Accepted. |
| Keep `--no-config` for narrow default-rule probes only | Rule authors still need a way to isolate default behavior from project overrides. | Accepted, provided the output is labelled as config-disabled calibration. |

## Consequences

- Future external dogfood commands must not include `--no-config` unless explicitly evaluating default-rule behavior.
- Reports must state whether config was applied and name any explicit config path.
- False-positive rankings should be based on configured scans first; default-rule scans may be used as a secondary calibration appendix.
- `--no-baseline` is a separate choice. It may still be used when the task is to inspect all current findings, but it does not justify bypassing config.

## Reversibility

Two-way door. Revisit only if `analyse` gains a first-class `--project-root` or cross-project config-resolution mode that makes the target root and config provenance explicit without requiring the caller to `cd` into the target project.

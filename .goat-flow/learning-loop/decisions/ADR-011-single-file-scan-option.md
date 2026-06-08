# ADR-011: Single file scan option

**Status:** Implemented
**Date:** 2026-05-19
**Ticket/Context:** Explicit `analyse --file=PATH` public CLI surface

## Decision

`analyse` supports a repeatable `--file=PATH` option for explicit single-file scans.

`--file` values are appended to the same internal path list as positional `paths`; they do not create a separate scan mode. The existing `SourceDiscovery` pipeline remains authoritative for supported source types, missing-path diagnostics, Git/default/configured ignore behavior, `--include-ignored`, parsing, baselines, diff filtering, scoring, and report rendering.

Positional file and directory paths remain supported. `--file` is an ergonomic and automation-friendly alias for callers that want an unambiguous single-file command surface.

## Context

Before this decision, `analyse` already accepted explicit file paths as positional arguments because `AnalyseCommand` defined variadic `paths` as "Files or directories to analyse" and `SourceDiscovery::discover()` handled `is_file(...)` inputs directly.

The missing part was the public contract: users asking whether gruff can scan one file had to know that a positional "path" could be a file. That is easy for humans to miss and harder for wrappers to expose clearly. Adding `--file` makes the intended use visible in `analyse --help` without duplicating discovery behavior.

The implementation evidence is:

- `src/Command/AnalyseCommand.php` declares repeatable `--file`.
- `src/Command/AnalyseCommandOptions.php` validates repeated non-empty values and merges them into `paths`.
- `tests/Console/AnalyseCliTest.php` asserts `analyse --file tests/Fixtures/Source/mixed/alpha.php --format json` discovers and parses exactly one file.

## Failure Mode Comparison

| Option | What fails | Why rejected or accepted |
| --- | --- | --- |
| Keep positional file paths only | The capability exists but remains hidden behind generic "paths" wording; wrappers and users may assume only directories are accepted. | Rejected. The public CLI should make common single-file scans obvious. |
| Add a separate single-file execution path | Duplicate discovery, ignore, baseline, diff, and reporting logic can drift from normal analysis semantics. | Rejected. Single-file scans need the same rules and diagnostics as every other scan scope. |
| Add repeatable `--file` and merge it into positional paths | The help surface becomes explicit while all existing scan behavior stays centralized in `SourceDiscovery`. | Accepted. This is the smallest public-contract change with the least maintenance risk. |

## Consequences

- Future changes to source discovery, ignore handling, parsing, baseline application, diff filtering, scoring, and reporters must continue to treat `--file` inputs exactly like equivalent positional file paths after option parsing.
- `--file` should remain repeatable so automation can add files without constructing a positional tail.
- `--file` values are path values, not comma-separated lists. This avoids ambiguity for filenames that contain commas and matches Symfony Console's repeated option model.
- Tests should cover the public CLI behavior rather than adding a second discovery test for behavior `SourceDiscovery` already owns.

## Reversibility

Two-way door before the first public release, but removal after release is a public CLI break. If evidence shows the option confuses users or conflicts with a stronger scan-scope model, a later ADR can deprecate it and keep positional file paths as the compatibility path.

Rollback before release is to remove the `--file` option, remove the option-merge code, and keep positional file scans as the documented mechanism.

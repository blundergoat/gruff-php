# Releasing

This page captures the gruff-php release checks that protect the user-facing CLI
and report contracts.

## Version Bump

`scripts/bump-version.sh <X.Y.Z> [--release-date YYYY-MM-DD]` is the single
entry point for version stamps. It rewrites, in one run:

- `Application::VERSION` in `src/Cli/Application.php`;
- the `| Current source |` row in `README.md`;
- the header and JSON-example stamps in `docs/gruff-cli-summary.md`;
- the `## X.Y.Z - Unreleased` CHANGELOG heading (dated, non-prerelease bumps only).

CLI golden fixtures never need regeneration: `tests/Console/AnalyseCliTest.php`
normalises golden version stamps to `Application::VERSION` at compare time, and
the version-sensitive console assertions derive from the constant directly.

During development the repo carries a prerelease version (for example
`0.5.0-dev`) alongside an `Unreleased` CHANGELOG heading; the release bump to
`X.Y.Z` stamps the date. Rollback of a bump is a plain checkout of the four
touched files:

```sh
git checkout -- src/Cli/Application.php CHANGELOG.md README.md docs/gruff-cli-summary.md
```

## Preflight

Run the local check suite before tagging. The preflight's first step verifies
`Application::VERSION` against the CLI, the CHANGELOG heading, the README
stamp, and both summary-doc stamps, and names the exact stale file when any
drift:

```sh
composer check
bash scripts/preflight-checks.sh --release-version X.Y.Z
vendor/bin/gruff-php --help
vendor/bin/gruff-php list-rules --format json
```

## CLI Contract

Verify the common command surface:

```sh
vendor/bin/gruff-php --help
vendor/bin/gruff-php analyse --help
vendor/bin/gruff-php summary --help
vendor/bin/gruff-php dashboard --help
```

Aliases such as `list-rules --format text` and `dashboard --project-root` should
remain covered by smoke tests.

## Docs

Update docs when command output or schemas change:

- `docs/configuration.md`
- `docs/output-formats.md`
- `docs/ci-integration.md`
- `docs/dashboard.md`
- `docs/rules.md`

If the rule registry changes, verify `docs/rules.md` against
`vendor/bin/gruff-php list-rules --format json`.

## Changelog

Record compatibility-sensitive changes in `CHANGELOG.md`, especially:

- schema strings
- severity names
- default exit thresholds
- baseline behaviour
- dashboard defaults
- output format additions or removals

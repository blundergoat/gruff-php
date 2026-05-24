# Releasing

This page captures the gruff-php release checks that protect the user-facing CLI
and report contracts.

## Preflight

Run the local check suite before tagging:

```sh
composer check
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

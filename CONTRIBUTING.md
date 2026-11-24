# Contributing

Thanks for taking the time to improve `gruff-php`.

Keep contributions focused, grounded in the current CLI surface, and covered by
tests. Public rule ids, schemas, and command flags should be treated as
compatibility-sensitive throughout the pre-1.0 line.

## Requirements

- PHP `^8.3`
- Composer 2.x
- Git

Install dependencies:

```bash
composer install
```

## Development Workflow

1. Create a topic branch.
2. Make the smallest coherent change.
3. Add or update tests for behavior changes.
4. Run the relevant focused tests.
5. Run the full verification commands before opening a PR.

Common commands:

```bash
composer check
composer test
composer format:check
bash scripts/preflight-checks.sh
```

`composer check` runs Composer validation, dependency audit, the security scan,
a full self-scan (`php bin/gruff-php analyse . --fail-on=advisory`), shell and
PHP syntax checks, and PHPStan. It does not run PHPUnit; run `composer test`
separately, or run `bash scripts/preflight-checks.sh` to execute the full CI
gate (including version consistency and documentation-drift checks).

## Coding Standards

- Prefer existing local patterns over new abstractions.
- Keep rule IDs stable once public.
- Keep findings deterministic: stable order, stable fingerprints, and stable
  JSON shapes.
- Add rule fixtures for both positive and negative cases.
- Do not weaken rule defaults just to make this repository's dogfood score look
  better.
- Keep sensitive-data tests synthetic and make sure full secret values never
  leak into report messages or JSON metadata.
- Use `.gruff-php.yaml` (or the legacy `.gruff.yaml` fallback) for
  project-specific calibration, not hidden code paths.

## Adding Or Changing Rules

When adding or changing a rule:

- Add or update the rule class under `src/Rules/<Pillar>/`.
- Register it in `RuleRegistry`.
- Add fixture files under `tests/Fixtures/<Pillar>/`.
- Add focused PHPUnit coverage.
- Check `php bin/gruff-php list-rules --format json`.
- Run `php bin/gruff-php analyse --fail-on none` and inspect the dogfood impact.
- Update docs when the public rule surface changes.

Avoid rule names that describe an implementation detail rather than a stable
quality concern.

## Reporting Bugs

A useful bug report includes:

- `php -v`
- `composer show blundergoat/gruff-php` or the commit hash
- The command you ran
- Expected behavior
- Actual output, preferably with `--format json --fail-on none`
- A minimal fixture or code sample when possible

Do not include real credentials or private source code in public issues.

## Pull Request Checklist

- [ ] Tests added or updated for behavior changes.
- [ ] `composer check` passes.
- [ ] `composer test` passes.
- [ ] `composer format:check` passes, or formatter drift is explicitly scoped.
- [ ] `bash scripts/preflight-checks.sh` passes (covers self-scan, PHPStan, PHPUnit, and docs drift).
- [ ] README, changelog, or docs updated for public behavior changes.

## Commit Messages

This repository uses concise, imperative Conventional Commits. See
[`docs/coding-standards/git-commit-message.md`](docs/coding-standards/git-commit-message.md).

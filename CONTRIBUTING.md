# Contributing

Thanks for taking the time to improve `gruff-php`.

This project is preparing its first public release. Keep contributions focused,
grounded in the current CLI surface, and covered by tests.

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
php bin/gruff-php analyse --fail-on none
```

`composer check` runs Composer validation, shell syntax checks, PHP syntax
checks, and PHPStan. It does not run PHPUnit; run `composer test` separately.

## Coding Standards

- Prefer existing local patterns over new abstractions.
- Keep rule ids stable once public.
- Keep findings deterministic: stable order, stable fingerprints, stable JSON
  shapes.
- Add rule fixtures for both positive and negative cases.
- Do not weaken rule defaults just to make this repository's dogfood score look
  better.
- Keep sensitive-data tests synthetic and ensure full secret values do not leak
  into report messages or JSON metadata.
- Use `.gruff.yaml` for project-specific calibration, not hidden code paths.

## Adding Or Changing Rules

When adding or changing a rule:

- Add or update the rule class under `src/Rule/<Pillar>/`.
- Register it in `RuleRegistry`.
- Add fixture files under `tests/Fixtures/<Pillar>/`.
- Add focused PHPUnit coverage.
- Check `php bin/gruff-php list-rules --format json`.
- Run `php bin/gruff-php analyse --fail-on none` and inspect dogfood impact.
- Update docs when the public rule surface changes.

Avoid rule names that describe an implementation detail rather than a stable
quality concern.

## Reporting Bugs

A useful bug report includes:

- `php -v`
- `composer show devgoat/gruff-php` or the commit hash
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
- [ ] `php bin/gruff-php analyse` exits 0 for this repository.
- [ ] README, changelog, or docs updated for public behavior changes.

## Commit Messages

This repository keeps concise, imperative commit messages. See
[`.github/git-commit-instructions.md`](.github/git-commit-instructions.md).
